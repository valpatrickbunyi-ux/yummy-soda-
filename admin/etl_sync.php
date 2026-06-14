<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/helpers.php';
require_admin();

$pdo = db();
$currentPage = 'etl';

$runId = null;
$rowsInserted = 0;
$etlError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['run_etl'])) {
  try {
    $stmt = $pdo->prepare("INSERT INTO etl_runs(started_at,status) VALUES(NOW(),'RUNNING')");
    $stmt->execute();
    $runId = (int)$pdo->lastInsertId();

    // Bug fix 1 & 2: one transaction wraps ALL steps — dims + fact rebuild.
    // Previously the dims ran outside any transaction (partial failures would
    // silently commit), and a second beginTransaction() was called mid-flight
    // which in InnoDB implicitly commits the outer one.
    $pdo->beginTransaction();

    $pdo->exec("INSERT INTO olap_dim_product(product_id,sku,name,category,is_active)
                SELECT p.product_id,p.sku,p.name,p.category,p.is_active FROM products p
                ON DUPLICATE KEY UPDATE sku=VALUES(sku), name=VALUES(name), category=VALUES(category), is_active=VALUES(is_active)");

    $pdo->exec("INSERT INTO olap_dim_customer(user_id,full_name,email)
                SELECT u.user_id,u.full_name,u.email FROM users u
                ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), email=VALUES(email)");

    $pdo->exec("INSERT INTO olap_dim_payment_method(method) VALUES ('CASH'),('GCASH'),('CARD')
                ON DUPLICATE KEY UPDATE method=method");

    // Fetch distinct dates before the transaction touches anything large
    $dates = $pdo->query("SELECT DISTINCT DATE(o.ordered_at) d FROM orders o")->fetchAll();
    $insDate = $pdo->prepare("INSERT IGNORE INTO olap_dim_date(date_key,full_date,year,quarter,month,month_name,day) VALUES(?,?,?,?,?,?,?)");
    foreach ($dates as $r) {
      $dt = new DateTime($r['d']);
      $y = (int)$dt->format('Y'); $mo = (int)$dt->format('n'); $d = (int)$dt->format('j');
      $insDate->execute([(int)$dt->format('Ymd'), $r['d'], $y, (int)ceil($mo/3), $mo, $dt->format('F'), $d]);
    }

    $pdo->exec("DELETE FROM olap_fact_sales");

    // Bug fix 3: LEFT JOIN on payments so orders with no payment row are still
    // included in the fact table (INNER JOIN was silently dropping them).
    $rowsInserted = $pdo->exec("
      INSERT INTO olap_fact_sales
        (date_key, product_key, customer_key, payment_method_key, order_id, quantity, gross_amount, payment_status, order_status)
      SELECT
        CAST(DATE_FORMAT(o.ordered_at,'%Y%m%d') AS UNSIGNED),
        dp.product_key, dc.customer_key, dpm.payment_method_key, o.order_id,
        oi.quantity, oi.line_total,
        COALESCE(p.status, 'UNPAID'), o.status
      FROM orders o
      JOIN order_items oi ON oi.order_id = o.order_id
      LEFT JOIN payments p ON p.order_id = o.order_id
      LEFT JOIN olap_dim_payment_method dpm ON dpm.method = COALESCE(p.method, 'CASH')
      JOIN olap_dim_product dp ON dp.product_id = oi.product_id
      JOIN olap_dim_customer dc ON dc.user_id = o.user_id
    ");

    $pdo->commit();

    // Bug fix 4: log SUCCESS *before* the redirect — previously the UPDATE ran
    // after header()+exit, so every successful run stayed logged as 'RUNNING'.
    $pdo->prepare("UPDATE etl_runs SET finished_at=NOW(), status='SUCCESS', rows_inserted=? WHERE etl_run_id=?")
        ->execute([(int)$rowsInserted, $runId]);

    header('Location: /yummy-soda/admin/analytics.php');
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($runId) {
      $pdo->prepare("UPDATE etl_runs SET finished_at=NOW(), status='FAILED', error_message=? WHERE etl_run_id=?")
          ->execute([$e->getMessage(), $runId]);
    }
    $etlError = $e->getMessage();
  }
}

// Recent ETL runs
$recentRuns = [];
try {
  $recentRuns = $pdo->query("SELECT * FROM etl_runs ORDER BY started_at DESC LIMIT 5")->fetchAll();
} catch (Throwable $_ignored) {}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ETL Sync — Yummy Soda Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="/yummy-soda/admin/admin.css">
  <style>
    .etl-steps {
      display: flex;
      flex-direction: column;
      gap: 10px;
      margin: 24px 0;
    }
    .etl-step {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 14px 18px;
      background: #f8fafc;
      border-radius: var(--radius);
      border: 1px solid var(--border);
      font-size: 13.5px;
    }
    .etl-step-num {
      width: 28px; height: 28px;
      border-radius: 50%;
      background: var(--teal-pale);
      color: var(--teal-dark);
      display: flex; align-items: center; justify-content: center;
      font-weight: 800;
      font-family: var(--font-mono);
      font-size: 12px;
      flex-shrink: 0;
    }
  </style>
</head>
<body>

<?php require __DIR__ . '/_nav.php'; ?>

<div class="main">
  <header class="topbar">
    <div class="topbar-title">
      <div class="topbar-title-icon">⚡</div>
      ETL Sync
    </div>
  </header>

  <div class="page-content">

    <?php if ($etlError): ?>
    <div class="notice err">⚠️ ETL Failed: <?=e($etlError)?></div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;">

      <!-- Trigger Card -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">
            <span class="panel-title-dot"></span>
            Run ETL Pipeline
          </div>
        </div>
        <div class="panel-body" style="text-align:center;padding:36px 24px;">
          <div class="etl-icon" style="width:68px;height:68px;border-radius:18px;background:linear-gradient(135deg,var(--teal-pale),rgba(34,193,195,0.2));display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto 20px;">
            ⚡
          </div>
          <h3 style="margin-bottom:8px;font-size:17px;">Sync OLAP Data</h3>
          <p style="font-size:13.5px;color:var(--text-muted);line-height:1.6;margin-bottom:24px;">
            Refreshes all dimension tables and rebuilds the fact table from your transactional data. Run this after importing new orders.
          </p>

          <div class="etl-steps" style="text-align:left;">
            <div class="etl-step"><span class="etl-step-num">1</span> Update product, customer &amp; payment method dimensions</div>
            <div class="etl-step"><span class="etl-step-num">2</span> Build date dimension from order history</div>
            <div class="etl-step"><span class="etl-step-num">3</span> Clear and reload sales fact table (in transaction)</div>
            <div class="etl-step"><span class="etl-step-num">4</span> Log run result &amp; redirect to Analytics</div>
          </div>

          <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Running…'">
            <input type="hidden" name="run_etl" value="1">
            <button type="submit" class="btn btn-primary" style="width:100%;padding:14px;font-size:15px;">
              ⚡ Run ETL Now
            </button>
          </form>
        </div>
      </div>

      <!-- Recent Runs -->
      <?php if ($recentRuns): ?>
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">
            <span class="panel-title-dot" style="background:var(--amber);"></span>
            Recent ETL Runs
          </div>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Run ID</th>
                <th>Started</th>
                <th>Status</th>
                <th>Rows</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentRuns as $run): ?>
              <tr>
                <td style="font-family:var(--font-mono);font-size:12px;">#<?=e($run['etl_run_id'])?></td>
                <td style="font-size:12.5px;color:var(--text-muted);"><?=e($run['started_at'])?></td>
                <td>
                  <?php $rs = strtolower($run['status'] ?? ''); ?>
                  <span class="pill <?=match($rs){'success'=>'pill-paid','failed'=>'pill-cancelled',default=>'pill-pending'}?>">
                    <?=e($run['status'])?>
                  </span>
                </td>
                <td style="font-family:var(--font-mono);font-size:13px;"><?=e($run['rows_inserted'] ?? 0)?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </div>

  </div><!-- /page-content -->
</div><!-- /main -->
</body>
</html>
<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/helpers.php';
require_admin();

$pdo = db();
$currentPage = 'decisions';

// ── Schema bootstrap: migrate old table or create fresh ──────────────────────
// Step 1: create table if it doesn't exist at all (fresh install)
$pdo->exec("
  CREATE TABLE IF NOT EXISTS auto_approve_rules (
    rule_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    min_threshold   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_threshold   DECIMAL(10,2) NOT NULL,
    is_enabled      TINYINT(1)    NOT NULL DEFAULT 0,
    label           VARCHAR(60)   NOT NULL DEFAULT '',
    approved_count  INT UNSIGNED  NOT NULL DEFAULT 0,
    last_run_at     DATETIME      NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_max_threshold (max_threshold)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Step 2: migrate existing installs that have old column names (threshold → max_threshold + add min_threshold)
$cols = $pdo->query("SHOW COLUMNS FROM auto_approve_rules")->fetchAll(PDO::FETCH_COLUMN);
if (in_array('threshold', $cols) && !in_array('max_threshold', $cols)) {
  $pdo->exec("ALTER TABLE auto_approve_rules CHANGE COLUMN threshold max_threshold DECIMAL(10,2) NOT NULL");
}
if (!in_array('min_threshold', $cols)) {
  $pdo->exec("ALTER TABLE auto_approve_rules ADD COLUMN min_threshold DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER rule_id");
  // Patch existing rows: assign min based on sorted tier order
  $existing = $pdo->query("SELECT rule_id FROM auto_approve_rules ORDER BY max_threshold ASC")->fetchAll();
  $mins = [0.00, 500.00, 1000.00, 2000.00, 5000.00];
  foreach ($existing as $i => $row) {
    $min = $mins[$i] ?? 0.00;
    $pdo->prepare("UPDATE auto_approve_rules SET min_threshold=? WHERE rule_id=?")->execute([$min, $row['rule_id']]);
  }
}
if (!in_array('uq_max_threshold', array_column($pdo->query("SHOW INDEX FROM auto_approve_rules")->fetchAll(), 'Key_name'))) {
  try { $pdo->exec("ALTER TABLE auto_approve_rules ADD UNIQUE KEY uq_max_threshold (max_threshold)"); } catch (Throwable $_) {}
}

// Step 3: seed tiers that don't exist yet (safe with INSERT IGNORE on max_threshold unique key)
$tiers = [
  ['min' =>    0.00,   'max' =>     500.00, 'label' => '₱1 – ₱500'],
  ['min' =>  500.00,   'max' =>   1000.00,  'label' => '₱501 – ₱1,000'],
  ['min' => 1000.00,   'max' =>   2000.00,  'label' => '₱1,001 – ₱2,000'],
  ['min' => 2000.00,   'max' =>   5000.00,  'label' => '₱2,001 – ₱5,000'],
  ['min' => 5000.00,   'max' => 999999.99,  'label' => '₱5,001 & above'],
];
$seedStmt = $pdo->prepare("
  INSERT IGNORE INTO auto_approve_rules (min_threshold, max_threshold, label) VALUES (?, ?, ?)
");
foreach ($tiers as $t) {
  $seedStmt->execute([$t['min'], $t['max'], $t['label']]);
}

// ── Handle POST actions ───────────────────────────────────────────────────────
$flashMsg   = '';
$flashType  = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Toggle individual rule on/off
  if (isset($_POST['toggle_rule'], $_POST['set_enabled'])) {
    $ruleId  = (int)$_POST['toggle_rule'];
    $enabled = $_POST['set_enabled'] === '1' ? 1 : 0;
    $pdo->prepare("UPDATE auto_approve_rules SET is_enabled=? WHERE rule_id=?")
        ->execute([$enabled, $ruleId]);

    // When ENABLING a rule, immediately approve all existing PENDING orders in its range
    if ($enabled === 1) {
      $rule = $pdo->prepare("SELECT * FROM auto_approve_rules WHERE rule_id=?");
      $rule->execute([$ruleId]);
      $rule = $rule->fetch();

      if ($rule) {
        $isOpenEnded = ((float)$rule['max_threshold'] >= 999999.00);
        if ($isOpenEnded) {
          $stmt = $pdo->prepare("
            SELECT o.order_id FROM orders o
            LEFT JOIN payments p ON p.order_id = o.order_id
            WHERE o.status = 'PENDING'
              AND (p.status = 'PAID' OR p.status IS NULL)
              AND COALESCE(p.amount, 0) > ?
            ORDER BY o.order_id ASC
          ");
          $stmt->execute([$rule['min_threshold']]);
        } else {
          $stmt = $pdo->prepare("
            SELECT o.order_id FROM orders o
            LEFT JOIN payments p ON p.order_id = o.order_id
            WHERE o.status = 'PENDING'
              AND (p.status = 'PAID' OR p.status IS NULL)
              AND COALESCE(p.amount, 0) >  ?
              AND COALESCE(p.amount, 0) <= ?
            ORDER BY o.order_id ASC
          ");
          $stmt->execute([$rule['min_threshold'], $rule['max_threshold']]);
        }

        $pendingOrders = $stmt->fetchAll();
        $backfillCount = count($pendingOrders);

        if ($backfillCount > 0) {
          $approveStmt = $pdo->prepare("UPDATE orders SET status='PAID' WHERE order_id=?");
          foreach ($pendingOrders as $o) {
            $approveStmt->execute([$o['order_id']]);
          }
          $pdo->prepare("
            UPDATE auto_approve_rules
            SET approved_count = approved_count + ?, last_run_at = NOW()
            WHERE rule_id = ?
          ")->execute([$backfillCount, $ruleId]);
        }
      }
    }

    header('Location: /yummy-soda/admin/decisions.php'); exit;
  }

  // Reset lifetime count for a rule
  if (!empty($_POST['reset_count'])) {
    $pdo->prepare("UPDATE auto_approve_rules SET approved_count=0 WHERE rule_id=?")
        ->execute([(int)$_POST['reset_count']]);
    header('Location: /yummy-soda/admin/decisions.php'); exit;
  }
}

// ── Fetch fresh data ──────────────────────────────────────────────────────────
$rules = $pdo->query("SELECT * FROM auto_approve_rules ORDER BY max_threshold ASC")->fetchAll();

// Pending order counts per tier
// Uses COALESCE so orders with no payment row (legacy) default to amount=0 and fall into the lowest tier
$pendingCountByTier = [];
foreach ($rules as $rule) {
  $isOpenEnded = ((float)$rule['max_threshold'] >= 999999.00);
  if ($isOpenEnded) {
    $stmt = $pdo->prepare("
      SELECT COUNT(*) FROM orders o
      LEFT JOIN payments p ON p.order_id=o.order_id
      WHERE o.status='PENDING'
        AND (p.status='PAID' OR p.status IS NULL)
        AND COALESCE(p.amount, 0) > ?
    ");
    $stmt->execute([$rule['min_threshold']]);
  } else {
    $stmt = $pdo->prepare("
      SELECT COUNT(*) FROM orders o
      LEFT JOIN payments p ON p.order_id=o.order_id
      WHERE o.status='PENDING'
        AND (p.status='PAID' OR p.status IS NULL)
        AND COALESCE(p.amount, 0) >  ?
        AND COALESCE(p.amount, 0) <= ?
    ");
    $stmt->execute([$rule['min_threshold'], $rule['max_threshold']]);
  }
  $pendingCountByTier[$rule['rule_id']] = (int)$stmt->fetchColumn();
}

$enabledCount     = count(array_filter($rules, fn($r) => $r['is_enabled']));
$totalAutoApproved = array_sum(array_column($rules, 'approved_count'));

// ── Existing Decision Support data ───────────────────────────────────────────
$lowStock    = $pdo->query("SELECT * FROM products WHERE stock_qty < 20 ORDER BY stock_qty ASC LIMIT 10")->fetchAll();
$bestSellers = $pdo->query(
  "SELECT p.name, p.sku, SUM(oi.quantity) total_qty, SUM(oi.line_total) total_rev
   FROM order_items oi JOIN products p ON p.product_id=oi.product_id
   GROUP BY oi.product_id ORDER BY total_qty DESC LIMIT 5"
)->fetchAll();
$revTrend = $pdo->query(
  "SELECT DATE_FORMAT(o.ordered_at,'%Y-%m') as month, SUM(p.amount) as revenue
   FROM orders o JOIN payments p ON p.order_id=o.order_id
   WHERE p.status='PAID' GROUP BY month ORDER BY month DESC LIMIT 3"
)->fetchAll();

$recommendations = [];
foreach ($lowStock as $p) {
  $recommendations[] = [
    'type'   => 'warning',
    'icon'   => '📦',
    'msg'    => 'Restock ' . $p['name'] . ' (SKU: ' . $p['sku'] . ') — only ' . $p['stock_qty'] . ' units left.',
    'action' => 'Order 100 units',
  ];
}
if (!empty($bestSellers)) {
  $recommendations[] = [
    'type'   => 'success',
    'icon'   => '🏆',
    'msg'    => $bestSellers[0]['name'] . ' is your top seller. Feature it in promotions.',
    'action' => 'Create campaign',
  ];
}
if (count($revTrend) >= 2) {
  $latest = (float)($revTrend[0]['revenue'] ?? 0);
  $prev   = (float)($revTrend[1]['revenue'] ?? 0);
  if ($prev > 0) {
    $growth = (($latest - $prev) / $prev) * 100;
    if ($growth < -10) {
      $recommendations[] = ['type'=>'danger','icon'=>'📉','msg'=>'Revenue dropped by '.number_format(abs($growth),1).'% this month.','action'=>'Review pricing'];
    } elseif ($growth > 10) {
      $recommendations[] = ['type'=>'success','icon'=>'📈','msg'=>'Revenue grew by '.number_format($growth,1).'% this month! Keep the momentum.','action'=>'Expand marketing'];
    } else {
      $predicted = $latest * (1 + ($growth / 100));
      $recommendations[] = ['type'=>'info','icon'=>'📊','msg'=>'Revenue trend is stable ('.number_format($growth,1).'%). Projected next month: ₱'.number_format($predicted,2).'.','action'=>'Monitor closely'];
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Decision Support — Yummy Soda Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="/yummy-soda/admin/admin.css">
  <style>
    /* ── Auto-Approve Rule Cards ───────────────────────────────────────────── */
    .rule-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      gap: 16px;
      padding: 24px;
    }
    .rule-card {
      border: 2px solid var(--border);
      border-radius: var(--radius);
      padding: 20px;
      background: var(--surface);
      transition: all var(--transition);
      position: relative;
      overflow: hidden;
    }
    .rule-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
      background: var(--border);
      transition: background var(--transition);
    }
    .rule-card.enabled {
      border-color: rgba(34,193,195,0.35);
      background: linear-gradient(135deg, #f0fdfd 0%, #fff 100%);
      box-shadow: 0 2px 16px rgba(34,193,195,0.10);
    }
    .rule-card.enabled::before {
      background: linear-gradient(90deg, #22C1C3, #22c55e);
    }
    .rule-threshold {
      font-size: 26px;
      font-weight: 900;
      font-family: var(--font-mono);
      color: var(--text);
      letter-spacing: -1px;
      margin-bottom: 2px;
      line-height: 1;
    }
    .rule-card.enabled .rule-threshold {
      color: var(--teal-dark);
    }
    .rule-label {
      font-size: 11.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.07em;
      color: var(--text-muted);
      margin-bottom: 14px;
    }
    .rule-stats {
      display: flex;
      gap: 12px;
      margin-bottom: 16px;
    }
    .rule-stat {
      flex: 1;
      background: #f8fafc;
      border-radius: 8px;
      padding: 8px 10px;
      text-align: center;
    }
    .rule-card.enabled .rule-stat {
      background: rgba(34,193,195,0.08);
    }
    .rule-stat-val {
      font-size: 18px;
      font-weight: 800;
      font-family: var(--font-mono);
      color: var(--text);
      line-height: 1;
    }
    .rule-stat-label {
      font-size: 10px;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.06em;
      margin-top: 3px;
    }
    /* ── Toggle Switch ─────────────────────────────────────────────────────── */
    .toggle-wrap {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
    }
    .toggle-label {
      font-size: 13px;
      font-weight: 700;
      color: var(--text-muted);
    }
    .rule-card.enabled .toggle-label {
      color: var(--teal-dark);
    }
    .toggle {
      position: relative;
      width: 44px;
      height: 24px;
      flex-shrink: 0;
    }
    .toggle input {
      opacity: 0;
      width: 0; height: 0;
      position: absolute;
    }
    .toggle-slider {
      position: absolute;
      inset: 0;
      border-radius: 99px;
      background: #e2e8f0;
      cursor: pointer;
      transition: background 0.2s;
    }
    .toggle-slider::after {
      content: '';
      position: absolute;
      width: 18px; height: 18px;
      border-radius: 50%;
      background: #fff;
      top: 3px; left: 3px;
      transition: transform 0.2s;
      box-shadow: 0 1px 4px rgba(0,0,0,0.18);
    }
    .toggle input:checked + .toggle-slider {
      background: var(--teal);
    }
    .toggle input:checked + .toggle-slider::after {
      transform: translateX(20px);
    }
    /* ── Run Button ────────────────────────────────────────────────────────── */
    .run-btn-wrap {
      padding: 20px 24px;
      border-top: 1px solid var(--border);
      display: flex;
      align-items: center;
      gap: 14px;
      flex-wrap: wrap;
    }
    .run-result-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      border-radius: 99px;
      font-size: 12.5px;
      font-weight: 700;
      font-family: var(--font-mono);
    }
    .run-result-badge.approved  { background: #dcfce7; color: #15803d; }
    .run-result-badge.none      { background: #f1f5f9; color: var(--text-muted); }
    /* ── Run Results Banner ────────────────────────────────────────────────── */
    .run-results-panel {
      background: linear-gradient(135deg, #f0fdfd 0%, #ecfdf5 100%);
      border: 1.5px solid rgba(34,193,195,0.3);
      border-radius: var(--radius);
      padding: 20px 24px;
      margin-bottom: 24px;
      animation: slideIn 0.3s ease;
    }
    @keyframes slideIn {
      from { opacity: 0; transform: translateY(-8px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .run-results-title {
      font-size: 14px;
      font-weight: 800;
      color: var(--teal-dark);
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .run-results-row {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 0;
      border-bottom: 1px solid rgba(34,193,195,0.12);
      font-size: 13px;
    }
    .run-results-row:last-child { border-bottom: none; }
    .run-results-count {
      font-weight: 800;
      font-family: var(--font-mono);
      color: var(--teal-dark);
      min-width: 28px;
      text-align: right;
    }
    .pending-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 22px;
      height: 20px;
      padding: 0 6px;
      border-radius: 99px;
      font-size: 10.5px;
      font-weight: 800;
      font-family: var(--font-mono);
      background: var(--amber);
      color: #fff;
    }
    .pending-pill.zero {
      background: #e2e8f0;
      color: var(--text-muted);
    }
    /* ── notice info ───────────────────────────────────────────────────────── */
    .notice.info {
      background: #eff6ff;
      border-color: #bfdbfe;
      color: #1e40af;
    }
  </style>
</head>
<body>

<?php require __DIR__ . '/_nav.php'; ?>

<div class="main">
  <header class="topbar">
    <div class="topbar-title">
      <div class="topbar-title-icon">🎯</div>
      Decision Support System
    </div>
  </header>

  <div class="page-content">

    <?php if ($flashMsg): ?>
    <div class="notice <?=e($flashType)?>"> <?=e($flashMsg)?></div>
    <?php endif; ?>

    <!-- ── Summary KPIs ─────────────────────────────────────────────────────── -->
    <div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
      <div class="stat-card amber">
        <div class="stat-icon">⚠️</div>
        <div class="stat-value"><?=count($lowStock)?></div>
        <div class="stat-label">Low Stock Items</div>
      </div>
      <div class="stat-card green">
        <div class="stat-icon">🏆</div>
        <div class="stat-value"><?=count($bestSellers)?></div>
        <div class="stat-label">Top Performers</div>
      </div>
      <div class="stat-card teal">
        <div class="stat-icon">⚡</div>
        <div class="stat-value"><?=$enabledCount?></div>
        <div class="stat-label">Active Auto-Rules</div>
      </div>
      <div class="stat-card blue">
        <div class="stat-icon">✅</div>
        <div class="stat-value"><?=number_format($totalAutoApproved)?></div>
        <div class="stat-label">Total Auto-Approved</div>
      </div>
    </div>

    <!-- ── Auto-Approve Engine ─────────────────────────────────────────────── -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          <span class="panel-title-dot" style="background:var(--teal);"></span>
          Auto-Approve Engine
        </div>
        <div style="font-size:12px;color:var(--text-muted);">
          <?=$enabledCount?> of <?=count($rules)?> tiers active
          &nbsp;·&nbsp;
          <?php
            $totalPending = (int)$pdo->query("SELECT COUNT(*) FROM orders o LEFT JOIN payments p ON p.order_id=o.order_id WHERE o.status='PENDING' AND (p.status='PAID' OR p.status IS NULL)")->fetchColumn();
          ?>
          <strong><?=$totalPending?></strong> paid-but-pending order<?=$totalPending!=1?'s':''?> in queue
        </div>
      </div>

      <!-- Rule Cards -->
      <div class="rule-grid">
        <?php foreach ($rules as $rule):
          $isEnabled   = (bool)$rule['is_enabled'];
          $pendingCount = $pendingCountByTier[$rule['rule_id']] ?? 0;
          $isOpenEnded = ((float)$rule['max_threshold'] >= 999999.00);
          $thresholdDisplay = $isOpenEnded
            ? '₱' . number_format((float)$rule['min_threshold'], 0) . '+'
            : '₱' . number_format((float)$rule['max_threshold'], 0);
        ?>
        <div class="rule-card <?=$isEnabled ? 'enabled' : ''?>">
          <div class="rule-threshold"><?=$thresholdDisplay?></div>
          <div class="rule-label"><?=e($rule['label'])?></div>

          <div class="rule-stats">
            <div class="rule-stat">
              <div class="rule-stat-val"><?=$pendingCount?></div>
              <div class="rule-stat-label">Pending</div>
            </div>
            <div class="rule-stat">
              <div class="rule-stat-val"><?=number_format($rule['approved_count'])?></div>
              <div class="rule-stat-label">Approved</div>
            </div>
          </div>

          <?php if ($rule['last_run_at']): ?>
          <div style="font-size:11px;color:var(--text-muted);margin-bottom:12px;">
            Last run: <?=date('M j · g:i A', strtotime($rule['last_run_at']))?>
          </div>
          <?php else: ?>
          <div style="font-size:11px;color:var(--text-light);margin-bottom:12px;">Never run</div>
          <?php endif; ?>

          <div class="toggle-wrap">
            <span class="toggle-label"><?=$isEnabled ? 'Enabled' : 'Disabled'?></span>
            <form method="post" style="margin:0;">
              <input type="hidden" name="toggle_rule" value="<?=(int)$rule['rule_id']?>">
              <input type="hidden" name="set_enabled" value="<?=$isEnabled ? '0' : '1'?>">
              <button type="submit" class="btn btn-sm <?=$isEnabled ? 'btn-danger' : 'btn-primary'?>"
                style="min-width:82px;justify-content:center;">
                <?=$isEnabled ? '⏸ Disable' : '▶ Enable'?>
              </button>
            </form>
          </div>

          <?php if ($rule['approved_count'] > 0): ?>
          <form method="post" style="margin-top:10px;">
            <input type="hidden" name="reset_count" value="<?=(int)$rule['rule_id']?>">
            <button type="submit" class="btn btn-secondary btn-sm"
              style="width:100%;font-size:11px;"
              onclick="return confirm('Reset lifetime approved count for this tier?')">
              ↺ Reset Counter
            </button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Info note replacing the run button -->
      <div class="run-btn-wrap">
        <div style="font-size:13px;color:var(--text-muted);line-height:1.7;padding:4px 0;">
          ⚡ <strong>Auto-approval is instant.</strong>
          When a tier is <strong>enabled</strong>, any new order whose total falls within that range
          is confirmed automatically at checkout — no manual action needed.
        </div>
      </div>
    </div>

    <!-- ── Rule-Based Recommendations ─────────────────────────────────────── -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          <span class="panel-title-dot" style="background:var(--amber);"></span>
          Rule-Based Recommendations
        </div>
        <span style="font-size:12px;color:var(--text-muted);"><?=count($recommendations)?> alert<?=count($recommendations)!=1?'s':''?></span>
      </div>
      <div class="panel-body">
        <?php if (empty($recommendations)): ?>
        <div class="empty-state">
          <span class="empty-icon">✅</span>
          <p class="empty-text">All clear — no alerts at this time.</p>
        </div>
        <?php else: ?>
        <?php foreach ($recommendations as $i => $r): ?>
        <div class="rec-card <?=e($r['type'])?>" style="animation-delay:<?=$i*0.06?>s;">
          <div class="rec-icon"><?=$r['icon']?></div>
          <div class="rec-body">
            <div class="rec-type" style="color:<?=match($r['type']){'warning'=>'#b45309','danger'=>'#b91c1c','success'=>'#15803d',default=>'#0e7490'}?>;">
              <?=strtoupper(e($r['type']))?>
            </div>
            <div class="rec-msg"><?=e($r['msg'])?></div>
            <div class="rec-action">→ Suggested: <?=e($r['action'])?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Best Sellers ────────────────────────────────────────────────────── -->
    <?php if ($bestSellers): ?>
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          <span class="panel-title-dot" style="background:var(--green);"></span>
          Top Selling Products
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Rank</th>
              <th>Product</th>
              <th>SKU</th>
              <th>Units Sold</th>
              <th>Revenue</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bestSellers as $i => $bs): ?>
            <tr>
              <td>
                <span style="font-weight:800;font-size:18px;">
                  <?=$i===0?'🥇':($i===1?'🥈':($i===2?'🥉':($i+1)))?>
                </span>
              </td>
              <td style="font-weight:700;"><?=e($bs['name'])?></td>
              <td><span style="font-family:var(--font-mono);font-size:12px;background:#f1f5f9;padding:3px 8px;border-radius:5px;"><?=e($bs['sku'])?></span></td>
              <td><strong><?=number_format($bs['total_qty'])?></strong></td>
              <td><strong>₱<?=e(money($bs['total_rev']))?></strong></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /page-content -->
</div><!-- /main -->

</body>
</html>
<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/helpers.php';
require_admin();

$pdo = db();
$currentPage = 'orders';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['order_id'])) {
  $status = $_POST['status'] ?? '';
  if (in_array($status, ['PENDING','PAID','CANCELLED'], true)) {
    $orderId = (int)$_POST['order_id'];
    $pdo->prepare("UPDATE orders SET status=? WHERE order_id=?")
        ->execute([$status, $orderId]);
    $pdo->prepare("UPDATE payments SET status=? WHERE order_id=?")
        ->execute([$status, $orderId]);
    $msg = 'Order #' . $orderId . ' updated to ' . $status . '.';
  }
}

$orders = $pdo->query(
  "SELECT o.*, p.amount, p.method, p.status as pay_status, u.full_name
   FROM orders o
   LEFT JOIN payments p ON p.order_id = o.order_id
   LEFT JOIN users u ON u.user_id = o.user_id
   ORDER BY o.ordered_at DESC LIMIT 50"
)->fetchAll();

$counts = ['PENDING'=>0,'PAID'=>0,'CANCELLED'=>0];
foreach ($orders as $o) {
  if (isset($counts[$o['status']])) $counts[$o['status']]++;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Orders — Yummy Soda Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="/yummy-soda/admin/admin.css">
</head>
<body>

<?php require __DIR__ . '/_nav.php'; ?>

<div class="main">
  <header class="topbar">
    <div class="topbar-title">
      <div class="topbar-title-icon">📦</div>
      Order Management
    </div>
    <div class="topbar-actions">
      <a href="/yummy-soda/api/export_csv.php" class="topbar-btn">📄 Export CSV</a>
      <a href="/yummy-soda/api/export_excel.php" class="topbar-btn">📑 Export Excel</a>
    </div>
  </header>

  <div class="page-content">

    <?php if ($msg): ?>
    <div class="notice ok">✓ <?=e($msg)?></div>
    <?php endif; ?>

    <!-- Quick stats -->
    <div class="stats-grid" style="grid-template-columns:repeat(3,1fr);max-width:480px;margin-bottom:24px;">
      <div class="stat-card amber">
        <div class="stat-icon">⏳</div>
        <div class="stat-value"><?=$counts['PENDING']?></div>
        <div class="stat-label">Pending</div>
      </div>
      <div class="stat-card green">
        <div class="stat-icon">✅</div>
        <div class="stat-value"><?=$counts['PAID']?></div>
        <div class="stat-label">Paid</div>
      </div>
      <div class="stat-card red">
        <div class="stat-icon">❌</div>
        <div class="stat-value"><?=$counts['CANCELLED']?></div>
        <div class="stat-label">Cancelled</div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          <span class="panel-title-dot"></span>
          Recent Orders
          <span style="font-family:var(--font-mono);font-size:12px;color:var(--text-muted);font-weight:400;">(last 50)</span>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Order #</th>
              <th>Customer</th>
              <th>Date</th>
              <th>Amount</th>
              <th>Payment</th>
              <th>Status</th>
              <th>Update</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $o): ?>
            <tr>
              <td><span style="font-family:var(--font-mono);font-weight:700;color:var(--teal);">#<?=e($o['order_id'])?></span></td>
              <td style="font-weight:600;"><?=e($o['full_name'] ?? 'User #'.$o['user_id'])?></td>
              <td style="color:var(--text-muted);font-size:12.5px;white-space:nowrap;"><?=e($o['ordered_at'])?></td>
              <td><strong>₱<?=e(money($o['amount'] ?? 0))?></strong></td>
              <td>
                <span class="pill pill-default">
                  <?=e($o['method'] ?? '-')?>
                </span>
              </td>
              <td>
                <?php
                  $s = strtolower($o['status']);
                  $pillClass = match($s) {
                    'paid'      => 'pill-paid',
                    'pending'   => 'pill-pending',
                    'cancelled' => 'pill-cancelled',
                    default     => 'pill-default'
                  };
                ?>
                <span class="pill <?=$pillClass?>"><?=e($o['status'])?></span>
              </td>
              <td>
                <form method="post" style="display:flex;gap:6px;align-items:center;">
                  <input type="hidden" name="order_id" value="<?=e($o['order_id'])?>">
                  <select name="status" style="padding:7px 10px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:var(--font);font-size:12.5px;background:var(--surface);">
                    <option value="PENDING" <?=($o['status']==='PENDING'?'selected':'')?>>PENDING</option>
                    <option value="PAID"    <?=($o['status']==='PAID'   ?'selected':'')?>>PAID</option>
                    <option value="CANCELLED" <?=($o['status']==='CANCELLED'?'selected':'')?>>CANCELLED</option>
                  </select>
                  <button class="btn btn-primary btn-sm">Update</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /page-content -->
</div><!-- /main -->
</body>
</html>
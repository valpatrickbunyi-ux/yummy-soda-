<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/helpers.php';
require_admin();

$pdo = db();
$currentPage = 'dashboard';

$kpis = [
  'orders'    => (int)$pdo->query("SELECT COUNT(*) c FROM orders")->fetch()['c'],
  'revenue'   => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) s FROM payments WHERE status='PAID'")->fetch()['s'],
  'customers' => (int)$pdo->query("SELECT COUNT(*) c FROM users WHERE role='CUSTOMER'")->fetch()['c'],
  'products'  => (int)$pdo->query("SELECT COUNT(*) c FROM products")->fetch()['c'],
  'low_stock' => (int)$pdo->query("SELECT COUNT(*) c FROM products WHERE stock_qty < 20")->fetch()['c'],
];

$recent = $pdo->query(
  "SELECT o.order_id, o.status, o.ordered_at, p.amount, u.full_name
   FROM orders o
   LEFT JOIN payments p ON p.order_id = o.order_id
   LEFT JOIN users u ON u.user_id = o.user_id
   ORDER BY o.ordered_at DESC LIMIT 8"
)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard — Yummy Soda Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="/yummy-soda/admin/admin.css">
</head>
<body>

<?php require __DIR__ . '/_nav.php'; ?>

<div class="main">
  <header class="topbar">
    <div class="topbar-title">
      <div class="topbar-title-icon">🏠</div>
      Dashboard
    </div>

  </header>

  <div class="page-content">

    <div class="stats-grid">
      <div class="stat-card teal">
        <div class="stat-icon">📦</div>
        <div class="stat-value"><?=e($kpis['orders'])?></div>
        <div class="stat-label">Total Orders</div>
      </div>
      <div class="stat-card green">
        <div class="stat-icon">💰</div>
        <div class="stat-value">₱<?=e(money($kpis['revenue']))?></div>
        <div class="stat-label">Revenue</div>
      </div>
      <div class="stat-card blue">
        <div class="stat-icon">👥</div>
        <div class="stat-value"><?=e($kpis['customers'])?></div>
        <div class="stat-label">Customers</div>
      </div>
      <div class="stat-card amber">
        <div class="stat-icon">🧃</div>
        <div class="stat-value"><?=e($kpis['products'])?></div>
        <div class="stat-label">Products</div>
      </div>
      <div class="stat-card red">
        <div class="stat-icon">⚠️</div>
        <div class="stat-value"><?=e($kpis['low_stock'])?></div>
        <div class="stat-label">Low Stock Alert</div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          <span class="panel-title-dot"></span>
          Recent Orders
        </div>
        <a href="/yummy-soda/admin/orders.php" class="btn btn-secondary btn-sm">View All →</a>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Order #</th>
              <th>Customer</th>
              <th>Date</th>
              <th>Amount</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $r): ?>
            <tr>
              <td><span style="font-family:var(--font-mono);font-weight:700;color:var(--teal);">#<?=e($r['order_id'])?></span></td>
              <td><?=e($r['full_name'] ?? 'Unknown')?></td>
              <td style="color:var(--text-muted);font-size:12.5px;"><?=e($r['ordered_at'])?></td>
              <td><strong>₱<?=e(money($r['amount'] ?? 0))?></strong></td>
              <td>
                <?php
                  $s = strtolower($r['status']);
                  $pillClass = match($s) {
                    'paid'      => 'pill-paid',
                    'pending'   => 'pill-pending',
                    'cancelled' => 'pill-cancelled',
                    default     => 'pill-default'
                  };
                ?>
                <span class="pill <?=$pillClass?>"><?=e($r['status'])?></span>
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
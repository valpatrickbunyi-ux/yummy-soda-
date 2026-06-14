<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/helpers.php';
require_login();

$user = current_user();
$pdo = db();

$stmt = $pdo->prepare("SELECT o.order_id, o.ordered_at, o.status, p.amount, p.method, p.status as payment_status
                       FROM orders o
                       JOIN payments p ON p.order_id = o.order_id
                       WHERE o.user_id = ?
                       ORDER BY o.ordered_at DESC");
$stmt->execute([(int)$user['user_id']]);
$orders = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>My Orders</title>
  <link rel="stylesheet" href="/yummy-soda/public/assets/style.css">
</head>
<body>
  <nav><a href="/yummy-soda/public/index.php">Home</a> | <a href="/yummy-soda/customer/logout.php">Logout</a></nav>
  <div class="container">
    <div class="card">
      <div class="card-header"><h2>My Orders</h2></div>
      <div class="card-body">
        <?php if (!$orders): ?>
          <p>No orders yet.</p>
        <?php else: ?>
          <table class="cart-table">
            <tr><th>Order ID</th><th>Date</th><th>Amount</th><th>Payment</th><th>Status</th></tr>
            <?php foreach ($orders as $o): ?>
            <tr>
              <td>#<?=e($o['order_id'])?></td>
              <td><?=e($o['ordered_at'])?></td>
              <td>₱<?=e(money($o['amount']))?></td>
              <td><?=e($o['method'])?></td>
              <td><?=e($o['status'])?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
<?php
require __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

$customer_name = trim($_POST['customer_name'] ?? '');
$customer_phone = trim($_POST['customer_phone'] ?? '');
$payment_method = $_POST['payment_method'] ?? '';
$items = $_POST['items'] ?? [];

if ($customer_name === '' || $customer_phone === '') exit('Missing customer info');
if (!in_array($payment_method, ['CASH','GCASH','CARD'], true)) exit('Invalid payment method');
if (!is_array($items) || count($items) === 0) exit('No items');

$pdo = db();
$pdo->beginTransaction();

try {
  $stmt = $pdo->prepare("INSERT INTO orders(customer_name, customer_phone, status) VALUES(?,?, 'PENDING')");
  $stmt->execute([$customer_name, $customer_phone]);
  $order_id = (int)$pdo->lastInsertId();

  $total = 0.0;

  $getProduct = $pdo->prepare("SELECT product_id, price, stock_qty, is_active FROM products WHERE product_id = ? FOR UPDATE");
  $insertItem = $pdo->prepare("INSERT INTO order_items(order_id, product_id, quantity, unit_price, line_total) VALUES(?,?,?,?,?)");
  $updateStock = $pdo->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE product_id = ?");

  foreach ($items as $row) {
    $product_id = (int)($row['product_id'] ?? 0);
    $qty = (int)($row['qty'] ?? 0);
    if ($product_id <= 0 || $qty <= 0) continue;

    $getProduct->execute([$product_id]);
    $p = $getProduct->fetch();
    if (!$p || (int)$p['is_active'] !== 1) throw new Exception('Product not available');
    if ((int)$p['stock_qty'] < $qty) throw new Exception('Not enough stock');

    $unit = (float)$p['price'];
    $line = $unit * $qty;
    $total += $line;

    $insertItem->execute([$order_id, $product_id, $qty, $unit, $line]);
    $updateStock->execute([$qty, $product_id]);
  }

  if ($total <= 0) throw new Exception('No valid items');

  $stmt = $pdo->prepare("INSERT INTO payments(order_id, method, amount, status, paid_at) VALUES(?,?,?, 'PAID', NOW())");
  $stmt->execute([$order_id, $payment_method, $total]);

  $stmt = $pdo->prepare("UPDATE orders SET status='PAID' WHERE order_id=?");
  $stmt->execute([$order_id]);

  $pdo->commit();
  header('Location: /yummy-soda/public/index.php#order');
  exit;
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(400);
  echo "Order failed: " . htmlspecialchars($e->getMessage());
}
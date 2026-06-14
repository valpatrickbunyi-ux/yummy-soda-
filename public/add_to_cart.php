<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/cart.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$product_id = (int)($_POST['product_id'] ?? 0);
$qty = (int)($_POST['qty'] ?? 1);
if ($product_id <= 0) { http_response_code(400); exit; }
if ($qty < 1) $qty = 1;

cart_add($product_id, $qty);
http_response_code(200);
echo 'OK';
exit;
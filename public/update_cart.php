<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/cart.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$product_id = (int)($_POST['product_id'] ?? 0);
$qty        = (int)($_POST['qty'] ?? 0);

if ($product_id <= 0) { http_response_code(400); exit('Invalid product'); }

cart_update($product_id, $qty);   // qty <= 0 removes the item

header('Location: /yummy-soda/public/cart.php');
exit;
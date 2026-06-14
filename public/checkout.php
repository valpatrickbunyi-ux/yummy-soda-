<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/cart.php';
require __DIR__ . '/../includes/db.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

// ── Idempotency guard: reject replayed/double POST submissions ────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
$tokenPost    = $_POST['checkout_token'] ?? '';
$tokenSession = $_SESSION['checkout_token'] ?? '';
if ($tokenPost === '' || $tokenPost !== $tokenSession) {
    header('Location: /yummy-soda/public/cart.php?error=' . urlencode('Invalid or expired checkout session. Please try again.'));
    exit;
}
// Consume the token immediately so the same POST can't be replayed
unset($_SESSION['checkout_token']);

$method = $_POST['payment_method'] ?? '';
if (!in_array($method, ['CASH','GCASH','CARD'], true)) {
    header('Location: /yummy-soda/public/cart.php?error=' . urlencode('Invalid payment method.'));
    exit;
}

// Filter to only the items the user checked
$selected = isset($_POST['selected_items']) && is_array($_POST['selected_items'])
    ? array_map('intval', $_POST['selected_items'])
    : [];

$cart = cart_items();

// Apply selection filter if provided
if (!empty($selected) && $cart) {
    $cart = array_intersect_key($cart, array_flip($selected));
}

// Validate after filtering — redirect cleanly instead of showing a white error page
if (!$cart) {
    header('Location: /yummy-soda/public/cart.php?error=' . urlencode('Your cart is empty or no items were selected.'));
    exit;
}

$pdo = db();
$pdo->beginTransaction();

try {
    $user   = current_user();
    $stmt   = $pdo->prepare("INSERT INTO orders(user_id,customer_name,customer_phone,status) VALUES(?,?,?,'PENDING')");
    $stmt->execute([(int)$user['user_id'], $user['full_name'], $user['phone'] ?? '']);
    $order_id = (int)$pdo->lastInsertId();

    $getProduct  = $pdo->prepare("SELECT product_id,name,price,stock_qty,is_active FROM products WHERE product_id=? FOR UPDATE");
    $insertItem  = $pdo->prepare("INSERT INTO order_items(order_id,product_id,quantity,unit_price,line_total) VALUES(?,?,?,?,?)");
    $updateStock = $pdo->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE product_id = ?");

    $total = 0.0;

    foreach ($cart as $product_id => $qty) {
        $product_id = (int)$product_id;
        $qty        = (int)$qty;
        if ($product_id <= 0 || $qty <= 0) continue;

        $getProduct->execute([$product_id]);
        $p = $getProduct->fetch();

        // Skip inactive or out-of-stock items — don't block the whole order
        if (!$p || (int)$p['is_active'] !== 1) continue;
        if ((int)$p['stock_qty'] < $qty) {
            // Clamp to whatever stock is left; if zero, skip entirely
            $qty = (int)$p['stock_qty'];
            if ($qty <= 0) continue;
        }

        $unit  = (float)$p['price'];
        $line  = $unit * $qty;
        $total += $line;

        $insertItem->execute([$order_id, $product_id, $qty, $unit, $line]);
        $updateStock->execute([$qty, $product_id]);
    }

    if ($total <= 0) throw new Exception('No valid items');

    $stmt = $pdo->prepare("INSERT INTO payments(order_id,method,amount,status,paid_at) VALUES(?,?,?,'PAID',NOW())");
    $stmt->execute([$order_id, $method, $total]);

    // Check if an enabled auto-approve rule covers this order's total → instant approval
    $autoApproveStmt = $pdo->prepare("
        SELECT rule_id FROM auto_approve_rules
        WHERE is_enabled = 1
          AND min_threshold < ?
          AND max_threshold >= ?
        ORDER BY max_threshold ASC
        LIMIT 1
    ");
    $autoApproveStmt->execute([$total, $total]);
    $matchedRule = $autoApproveStmt->fetch();

    if ($matchedRule) {
        // Instantly approve the order
        $pdo->prepare("UPDATE orders SET status='PAID' WHERE order_id=?")->execute([$order_id]);
        // Increment the rule's approved_count
        $pdo->prepare("UPDATE auto_approve_rules SET approved_count = approved_count + 1, last_run_at = NOW() WHERE rule_id = ?")
            ->execute([$matchedRule['rule_id']]);
    }
    // Otherwise order stays PENDING — admin approves via Decision Support

    $pdo->commit();

    // Only remove the items that were actually checked out
    foreach (array_keys($cart) as $checked_pid) {
        cart_update((int)$checked_pid, 0);
    }
    // If nothing remains, also ensure the session is tidy
    if (empty(cart_items())) cart_clear();

    // Compute the per-user sequence number (matches account.php ROW_NUMBER logic)
    $cols    = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    $dateCol = in_array('created_at', $cols) ? 'created_at' :
              (in_array('order_date', $cols)  ? 'order_date'  :
              (in_array('ordered_at', $cols)  ? 'ordered_at'  : 'order_id'));

    $stmt = $pdo->prepare("
        SELECT user_order_num FROM (
            SELECT order_id,
                   ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY $dateCol ASC) AS user_order_num
            FROM orders
            WHERE user_id = ?
        ) ranked
        WHERE order_id = ?
    ");
    $stmt->execute([(int)$user['user_id'], $order_id]);
    $user_order_num = (int)($stmt->fetchColumn() ?: 1);

    // Format as YS-XXXXX to match account.php display
    $formatted = 'YS-' . str_pad($user_order_num, 5, '0', STR_PAD_LEFT);

    header('Location: /yummy-soda/public/cart.php?success=1&order_ref=' . urlencode($formatted));
    exit;

} catch (Throwable $e) {
    $pdo->rollBack();
    $msg = urlencode($e->getMessage());
    header('Location: /yummy-soda/public/cart.php?error=' . $msg);
    exit;
}
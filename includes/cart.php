<?php
/**
 * includes/cart.php  — DB-backed cart (persists per user account)
 *
 * Table auto-created on first use:
 *   CREATE TABLE IF NOT EXISTS cart_items (
 *     user_id    INT UNSIGNED NOT NULL,
 *     product_id INT UNSIGNED NOT NULL,
 *     qty        INT UNSIGNED NOT NULL DEFAULT 1,
 *     PRIMARY KEY (user_id, product_id)
 *   );
 *
 * Falls back to $_SESSION cart for guests (not logged in).
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// ── helpers ──────────────────────────────────────────────────────────────────

function _cart_user_id(): int {
    $u = current_user();
    return $u ? (int)$u['user_id'] : 0;
}

function _cart_ensure_table(): void {
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS cart_items (
        user_id    INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        qty        INT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (user_id, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

// ── guest (session) fallback ─────────────────────────────────────────────────

function _session_cart_init(): void {
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
}

// ── public API ───────────────────────────────────────────────────────────────

/**
 * Add $qty units of $product_id to the cart.
 */
function cart_add(int $product_id, int $qty): void {
    if ($qty <= 0 || $product_id <= 0) return;

    $uid = _cart_user_id();

    if ($uid) {
        _cart_ensure_table();
        // Insert or increment
        db()->prepare("
            INSERT INTO cart_items (user_id, product_id, qty)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
        ")->execute([$uid, $product_id, $qty]);
    } else {
        _session_cart_init();
        if (!isset($_SESSION['cart'][$product_id])) $_SESSION['cart'][$product_id] = 0;
        $_SESSION['cart'][$product_id] += $qty;
    }
}

/**
 * Set exact quantity for $product_id. qty <= 0 removes the item.
 */
function cart_update(int $product_id, int $qty): void {
    if ($product_id <= 0) return;

    $uid = _cart_user_id();

    if ($uid) {
        _cart_ensure_table();
        if ($qty <= 0) {
            db()->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id = ?")
                 ->execute([$uid, $product_id]);
        } else {
            db()->prepare("
                INSERT INTO cart_items (user_id, product_id, qty)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE qty = VALUES(qty)
            ")->execute([$uid, $product_id, $qty]);
        }
    } else {
        _session_cart_init();
        if ($qty <= 0) unset($_SESSION['cart'][$product_id]);
        else $_SESSION['cart'][$product_id] = $qty;
    }
}

/**
 * Empty the entire cart for the current user.
 */
function cart_clear(): void {
    $uid = _cart_user_id();

    if ($uid) {
        _cart_ensure_table();
        db()->prepare("DELETE FROM cart_items WHERE user_id = ?")
             ->execute([$uid]);
    } else {
        $_SESSION['cart'] = [];
    }
}

/**
 * Return the cart as [ product_id => qty, ... ]
 */
function cart_items(): array {
    $uid = _cart_user_id();

    if ($uid) {
        _cart_ensure_table();
        $stmt = db()->prepare("SELECT product_id, qty FROM cart_items WHERE user_id = ?");
        $stmt->execute([$uid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cart = [];
        foreach ($rows as $row) {
            $cart[(int)$row['product_id']] = (int)$row['qty'];
        }
        return $cart;
    }

    _session_cart_init();
    return $_SESSION['cart'];
}

/**
 * Call this right after login_user() to merge any guest session cart
 * into the newly-authenticated user's DB cart.
 */
function cart_merge_session_into_db(): void {
    _session_cart_init();
    $guest = $_SESSION['cart'];
    if (empty($guest)) return;

    $uid = _cart_user_id();
    if (!$uid) return;

    _cart_ensure_table();
    $stmt = db()->prepare("
        INSERT INTO cart_items (user_id, product_id, qty)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
    ");
    foreach ($guest as $pid => $qty) {
        if ((int)$pid > 0 && (int)$qty > 0) {
            $stmt->execute([$uid, (int)$pid, (int)$qty]);
        }
    }
    $_SESSION['cart'] = [];   // clear guest cart after merge
}
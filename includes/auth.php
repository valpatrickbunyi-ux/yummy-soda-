<?php
// Safe to include multiple times — session started only if not already active,
// and functions are declared only once.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('current_user')):

function current_user() {
    return $_SESSION['user'] ?? null;
}

function require_login() {
    if (!current_user()) {
        header('Location: /yummy-soda/customer/login.php');
        exit;
    }
}

function require_admin() {
    $u = current_user();
    if (!$u || ($u['role'] ?? '') !== 'ADMIN') {
        header('Location: /yummy-soda/admin/login.php');
        exit;
    }
}

function login_user(array $user) {
    $_SESSION['user'] = [
        'user_id'   => (int)$user['user_id'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'role'      => $user['role'],
    ];

    // Merge anything the guest added before logging in into the DB cart.
    // cart_merge_session_into_db() is defined in includes/cart.php;
    // only call it if that file has already been loaded.
    if (function_exists('cart_merge_session_into_db')) {
        cart_merge_session_into_db();
    }
}

function logout_user() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
    session_destroy();
}

endif;
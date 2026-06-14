<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email    = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';
  $stmt = db()->prepare("SELECT * FROM users WHERE email=? AND role='ADMIN' LIMIT 1");
  $stmt->execute([$email]);
  $user = $stmt->fetch();
  if ($user && password_verify($password, $user['password_hash'])) {
    login_user($user);
    header('Location: /yummy-soda/admin/dashboard.php');
    exit;
  }
  $error = 'Invalid email or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Login — Yummy Soda</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="/yummy-soda/admin/admin.css">
  <style>
    body { display: block; background: none; }
    .login-page { display: flex; }
  </style>
</head>
<body>

<div class="login-page">
  <div class="login-card">
    <div class="login-logo">🥤</div>
    <div class="login-title">Yummy Soda</div>
    <div class="login-sub">Admin Panel — Secure Access</div>

    <?php if ($error): ?>
    <div class="notice err" style="margin-bottom:20px;">⚠️ <?=e($error)?></div>
    <?php endif; ?>

    <form method="post">
      <div class="form-group" style="margin-bottom:16px;">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="admin@yummysoda.local" required autocomplete="email">
      </div>
      <div class="form-group" style="margin-bottom:24px;">
        <label>Password</label>
        <input type="password" name="password" placeholder="••••••••" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;padding:13px;font-size:15px;justify-content:center;border-radius:var(--radius-sm);">
        Sign In →
      </button>
    </form>

    <p style="text-align:center;margin-top:20px;font-size:12px;color:rgba(255,255,255,0.25);">
      Yummy Soda Admin v2 &nbsp;·&nbsp; <?=date('Y')?>
    </p>
  </div>
</div>

</body>
</html>
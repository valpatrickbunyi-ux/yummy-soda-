<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = db()->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash']) && $user['role'] === 'CUSTOMER') {
        login_user($user);
        header('Location: /yummy-soda/public/index.php#order');
        exit;
    }
    $error = 'Invalid email or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login – Yummy Soda</title>
  <link rel="stylesheet" href="/yummy-soda/public/assets/style.css">
</head>
<body class="auth-body">

  <div class="auth-card">
    <div class="auth-card-header">
      <h2>Welcome back 👋</h2>
      <p>Sign in to your Yummy Soda account</p>
    </div>

    <div class="auth-card-body">
      <?php if ($error): ?>
        <div class="auth-error"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" class="auth-form">
        <div class="auth-field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" placeholder="you@example.com" required
                 value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <div class="auth-field">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" placeholder="••••••••" required>
        </div>
        <button type="submit" class="auth-btn">Sign In →</button>
      </form>
    </div>

    <div class="auth-footer">
      Don't have an account?
      <a href="/yummy-soda/customer/register.php">Create one</a>
    </div>
  </div>

</body>
</html>
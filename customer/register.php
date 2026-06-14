<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email']     ?? '');
    $phone     = trim($_POST['phone']     ?? '');
    $password  = $_POST['password']       ?? '';

    if ($full_name === '' || $email === '' || strlen($password) < 6) {
        $error = 'Please fill all fields (password at least 6 characters).';
    } else {
        $pdo  = db();
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email=? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'That email is already registered.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users(full_name,email,phone,password_hash,role) VALUES(?,?,?,?,'CUSTOMER')");
            $stmt->execute([$full_name, $email, $phone, $hash]);
            $id = (int)$pdo->lastInsertId();
            login_user(['user_id' => $id, 'full_name' => $full_name, 'email' => $email, 'phone' => $phone, 'role' => 'CUSTOMER']);
            header('Location: /yummy-soda/public/index.php#order');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Account – Yummy Soda</title>
  <link rel="stylesheet" href="/yummy-soda/public/assets/style.css">
</head>
<body class="auth-body">

  <div class="auth-card">
    <div class="auth-card-header">
      <h2>Create account 🍹</h2>
      <p>Join Yummy Soda and start ordering</p>
    </div>

    <div class="auth-card-body">
      <?php if ($error): ?>
        <div class="auth-error"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" class="auth-form">
        <div class="auth-field">
          <label for="full_name">Full Name</label>
          <input id="full_name" name="full_name" type="text" placeholder="Juan dela Cruz" required
                 value="<?= e($_POST['full_name'] ?? '') ?>">
        </div>
        <div class="auth-field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" placeholder="you@example.com" required
                 value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <div class="auth-field">
          <label for="phone">Phone <span style="font-weight:600;opacity:.6;text-transform:none;">(optional)</span></label>
          <input id="phone" name="phone" type="tel" placeholder="09xx-xxx-xxxx"
                 value="<?= e($_POST['phone'] ?? '') ?>">
        </div>
        <div class="auth-field">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" placeholder="At least 6 characters" required>
        </div>
        <button type="submit" class="auth-btn">Create Account →</button>
      </form>
    </div>

    <div class="auth-footer">
      Already have an account?
      <a href="/yummy-soda/customer/login.php">Sign in</a>
    </div>
  </div>

</body>
</html>
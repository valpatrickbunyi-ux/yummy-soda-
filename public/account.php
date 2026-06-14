<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/helpers.php';
require_login();

$user = current_user();

// Handle logout
if (isset($_POST['logout'])) {
    logout_user();
    header('Location: /yummy-soda/public/index.php');
    exit;
}

$pdo = db();

// Handle password change
$pwSuccess = '';
$pwError   = '';
if (isset($_POST['change_password'])) {
    $current  = $_POST['current_password']  ?? '';
    $new      = $_POST['new_password']       ?? '';
    $confirm  = $_POST['confirm_password']   ?? '';

    if ($current === '' || $new === '' || $confirm === '') {
        $pwError = 'All password fields are required.';
    } elseif (strlen($new) < 6) {
        $pwError = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $pwError = 'New passwords do not match.';
    } else {
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->execute([$user['user_id']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($current, $row['password_hash'])) {
            $pwError = 'Current password is incorrect.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
                ->execute([$hash, $user['user_id']]);
            $pwSuccess = 'Password updated successfully!';
        }
    }
}

// Total orders & spending
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS total_orders, COALESCE(SUM(p.amount), 0) AS total_spent
    FROM orders o
    LEFT JOIN payments p ON p.order_id = o.order_id
    WHERE o.user_id = ?
");
$stmt->execute([$user['user_id']]);
$stats = $stmt->fetch();

// Detect timestamp column
$cols    = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
$dateCol = in_array('created_at', $cols) ? 'created_at' :
          (in_array('order_date', $cols)  ? 'order_date'  :
          (in_array('ordered_at', $cols)  ? 'ordered_at'  : 'order_id'));

// Order history with per-user sequence
$stmt = $pdo->prepare("
    SELECT o.order_id, o.status, o.$dateCol AS order_time,
           p.method, p.amount,
           ROW_NUMBER() OVER (PARTITION BY o.user_id ORDER BY o.$dateCol ASC) AS user_order_num
    FROM orders o
    LEFT JOIN payments p ON p.order_id = o.order_id
    WHERE o.user_id = ?
    ORDER BY o.$dateCol DESC
");
$stmt->execute([$user['user_id']]);
$orders = $stmt->fetchAll();

// Fetch items for each order
$orderItems = [];
if ($orders) {
    $orderIds = array_column($orders, 'order_id');
    $in   = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $pdo->prepare("
        SELECT oi.order_id, oi.quantity, oi.unit_price, oi.line_total, pr.name
        FROM order_items oi
        JOIN products pr ON pr.product_id = oi.product_id
        WHERE oi.order_id IN ($in)
    ");
    $stmt->execute($orderIds);
    foreach ($stmt->fetchAll() as $item) {
        $orderItems[$item['order_id']][] = $item;
    }
}

$statusColors = [
    'PENDING'   => ['bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fde68a'],
    'PAID'      => ['bg' => '#d1fae5', 'color' => '#065f46', 'border' => '#6ee7b7'],
    'SHIPPED'   => ['bg' => '#dbeafe', 'color' => '#1e40af', 'border' => '#93c5fd'],
    'DONE'      => ['bg' => '#f0fdf4', 'color' => '#15803d', 'border' => '#86efac'],
    'CANCELLED' => ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fca5a5'],
];

function fmt_order_num(int $n): string {
    return 'YS-' . str_pad($n, 5, '0', STR_PAD_LEFT);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Account – Yummy Soda</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    body { background: var(--grad-hero); }

    .account-wrap {
      max-width: 860px;
      margin: 0 auto;
      padding: 28px 16px 60px;
    }

    /* Profile card */
    .profile-card {
      background: var(--surface);
      border-radius: var(--radius-lg);
      padding: 28px 32px;
      box-shadow: var(--shadow-card);
      display: flex;
      align-items: center;
      gap: 24px;
      flex-wrap: wrap;
      margin-bottom: 20px;
    }

    .avatar {
      width: 76px; height: 76px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--teal), var(--amber));
      display: flex; align-items: center; justify-content: center;
      font-family: var(--font-display);
      font-size: 30px; font-weight: 900; color: #fff;
      flex-shrink: 0;
      box-shadow: 0 8px 24px rgba(34,193,195,0.38);
    }

    .profile-info { flex: 1; min-width: 0; }
    .profile-name { font-family: var(--font-display); font-size: 22px; font-weight: 900; color: var(--ink); margin-bottom: 2px; }
    .profile-email { font-size: 14px; color: var(--muted); font-weight: 600; }
    .profile-role {
      display: inline-block; margin-top: 6px;
      padding: 3px 12px; border-radius: var(--radius-pill);
      font-size: 12px; font-weight: 800;
      background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7;
      text-transform: uppercase; letter-spacing: 0.05em;
    }

    .profile-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

    .logout-btn {
      background: #fee2e2; color: #991b1b; border: 1.5px solid #fca5a5;
      padding: 10px 22px; border-radius: var(--radius-pill);
      font-size: 14px; font-weight: 800; cursor: pointer;
      transition: background 0.2s, transform 0.2s; white-space: nowrap;
      font-family: var(--font-body);
    }
    .logout-btn:hover { background: #fecaca; transform: translateY(-1px); }

    .change-pw-btn {
      background: #eff6ff; color: #1d4ed8; border: 1.5px solid #bfdbfe;
      padding: 10px 22px; border-radius: var(--radius-pill);
      font-size: 14px; font-weight: 800; cursor: pointer;
      transition: background 0.2s, transform 0.2s; white-space: nowrap;
      font-family: var(--font-body);
    }
    .change-pw-btn:hover { background: #dbeafe; transform: translateY(-1px); }

    /* Stats */
    .stats-row {
      display: grid; grid-template-columns: repeat(2, 1fr);
      gap: 14px; margin-bottom: 20px;
    }
    .stat-tile {
      background: var(--surface); border-radius: 22px; padding: 22px 26px;
      box-shadow: 0 8px 30px rgba(0,0,0,0.09);
      display: flex; flex-direction: column; gap: 6px;
      transition: transform 0.22s, box-shadow 0.22s;
    }
    .stat-tile:hover { transform: translateY(-2px); box-shadow: 0 12px 36px rgba(0,0,0,0.12); }
    .stat-icon { font-size: 26px; }
    .stat-num { font-family: var(--font-display); font-size: 34px; font-weight: 900; color: var(--ink); line-height: 1; }
    .stat-lbl { font-size: 13px; font-weight: 700; color: var(--muted); }

    /* Password modal */
    .pw-modal-backdrop {
      position: fixed; inset: 0; z-index: 500;
      background: rgba(15,23,42,0.55);
      backdrop-filter: blur(6px);
      display: flex; align-items: center; justify-content: center;
      padding: 20px;
      opacity: 0; pointer-events: none;
      transition: opacity 0.25s;
    }
    .pw-modal-backdrop.is-open { opacity: 1; pointer-events: all; }

    .pw-modal {
      background: #fff; border-radius: 28px;
      padding: 36px 36px 32px;
      width: 100%; max-width: 420px;
      box-shadow: 0 32px 80px rgba(0,0,0,0.22);
      transform: translateY(24px) scale(0.97);
      transition: transform 0.28s cubic-bezier(0.22,0.61,0.36,1);
      position: relative;
    }
    .pw-modal-backdrop.is-open .pw-modal { transform: translateY(0) scale(1); }

    .pw-modal-close {
      position: absolute; top: 16px; right: 18px;
      width: 34px; height: 34px; border-radius: 50%;
      border: none; background: #f3f4f6; cursor: pointer;
      font-size: 18px; display: flex; align-items: center; justify-content: center;
      transition: background 0.2s, transform 0.2s;
    }
    .pw-modal-close:hover { background: #e5e7eb; transform: scale(1.1) rotate(90deg); }

    .pw-modal-title {
      font-family: var(--font-display);
      font-size: 22px; font-weight: 900; color: var(--ink);
      margin-bottom: 6px;
    }
    .pw-modal-sub { font-size: 13px; color: var(--muted); font-weight: 600; margin-bottom: 24px; }

    .pw-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
    .pw-field label { font-size: 13px; font-weight: 800; color: #374151; }
    .pw-field input {
      width: 100%; padding: 11px 16px;
      border-radius: 14px; border: 1.5px solid #e5e7eb;
      font-size: 15px; font-family: var(--font-body);
      outline: none; transition: border-color 0.2s, box-shadow 0.2s;
    }
    .pw-field input:focus {
      border-color: var(--teal);
      box-shadow: 0 0 0 3px rgba(34,193,195,0.15);
    }

    .pw-submit {
      width: 100%; padding: 13px;
      background: linear-gradient(135deg, var(--teal), var(--amber));
      color: #fff; border: none; border-radius: var(--radius-pill);
      font-size: 15px; font-weight: 900; font-family: var(--font-body);
      cursor: pointer; margin-top: 6px;
      transition: opacity 0.2s, transform 0.2s;
    }
    .pw-submit:hover { opacity: 0.92; transform: translateY(-1px); }

    .pw-feedback {
      padding: 11px 16px; border-radius: 14px;
      font-size: 13px; font-weight: 700;
      margin-bottom: 16px;
    }
    .pw-feedback.success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
    .pw-feedback.error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

    /* Password strength bar */
    .pw-strength { margin-top: 6px; }
    .pw-strength-bar {
      height: 4px; border-radius: 4px; background: #e5e7eb;
      overflow: hidden; margin-bottom: 4px;
    }
    .pw-strength-fill {
      height: 100%; border-radius: 4px; width: 0%;
      transition: width 0.3s, background 0.3s;
    }
    .pw-strength-label { font-size: 11px; font-weight: 700; color: var(--muted); }

    /* Orders section */
    .section-title {
      font-family: var(--font-display); font-size: 20px; font-weight: 900;
      color: rgba(255,255,255,0.95); margin-bottom: 14px;
      display: flex; align-items: center; gap: 10px;
    }

    .order-card {
      background: var(--surface); border-radius: 22px; overflow: hidden;
      box-shadow: 0 8px 28px rgba(0,0,0,0.09); margin-bottom: 14px;
      transition: box-shadow 0.22s, transform 0.22s;
    }
    .order-card:hover { box-shadow: 0 16px 40px rgba(0,0,0,0.14); transform: translateY(-2px); }

    .order-card-header {
      display: flex; justify-content: space-between; align-items: center;
      padding: 18px 24px; border-bottom: 1px solid #f3f4f6;
      flex-wrap: wrap; gap: 10px;
    }
    .order-id { font-family: var(--font-display); font-size: 15px; font-weight: 900; color: var(--ink); }
    .order-date { font-size: 12px; color: #9ca3af; margin-top: 2px; font-weight: 600; }
    .order-status {
      padding: 4px 14px; border-radius: var(--radius-pill);
      font-size: 11px; font-weight: 800; border: 1px solid transparent;
      text-transform: uppercase; letter-spacing: 0.06em;
    }

    .order-items-list { padding: 14px 24px; }
    .order-item-row {
      display: flex; justify-content: space-between; align-items: center;
      padding: 8px 0; border-bottom: 1px solid #f9fafb;
      font-size: 14px; color: #374151; gap: 12px;
    }
    .order-item-row:last-child { border-bottom: none; }
    .item-name  { font-weight: 700; }
    .item-qty   { color: var(--muted); font-size: 13px; }
    .item-price { font-weight: 800; color: var(--ink); }

    .order-footer {
      display: flex; justify-content: space-between; align-items: center;
      padding: 12px 24px; background: #f9fafb; flex-wrap: wrap; gap: 8px;
    }
    .payment-method { font-size: 12px; color: var(--muted); font-weight: 700; }
    .order-total { font-family: var(--font-display); font-size: 16px; font-weight: 900; color: var(--ink); }

    .empty-orders {
      background: var(--surface); border-radius: 22px; padding: 56px 40px;
      text-align: center; box-shadow: 0 8px 30px rgba(0,0,0,0.10);
    }
    .empty-orders .emoji { font-size: 52px; margin-bottom: 14px; }
    .empty-orders p { color: var(--muted); font-size: 16px; font-weight: 600; }

    @media (max-width: 520px) {
      .profile-card { padding: 20px 18px; }
      .stats-row { grid-template-columns: 1fr 1fr; }
      .account-wrap { padding: 16px 12px 60px; }
      .pw-modal { padding: 28px 20px 24px; }
    }
  </style>
</head>
<body>

<nav>
  <a href="/yummy-soda/public/index.php#home">Home</a>
  <a href="/yummy-soda/public/account.php">Account</a>
  <a href="/yummy-soda/public/index.php#order">Order</a>
  <a href="/yummy-soda/public/cart.php">Cart</a>
  <a href="/yummy-soda/public/index.php#contact">Contact</a>
</nav>

<div class="pw-modal-backdrop" id="pwModalBackdrop">
  <div class="pw-modal" role="dialog" aria-modal="true" aria-labelledby="pwModalTitle">
    <button class="pw-modal-close" id="pwModalClose" type="button" aria-label="Close">×</button>
    <div class="pw-modal-title" id="pwModalTitle">🔒 Change Password</div>
    <div class="pw-modal-sub">Choose a strong password for your account.</div>

    <?php if ($pwSuccess !== ''): ?>
      <div class="pw-feedback success">✅ <?= e($pwSuccess) ?></div>
    <?php endif; ?>
    <?php if ($pwError !== ''): ?>
      <div class="pw-feedback error">❌ <?= e($pwError) ?></div>
    <?php endif; ?>

    <form method="post" id="pwForm">
      <div class="pw-field">
        <label for="current_password">Current Password</label>
        <input type="password" id="current_password" name="current_password"
               placeholder="Enter your current password" required>
      </div>
      <div class="pw-field">
        <label for="new_password">New Password</label>
        <input type="password" id="new_password" name="new_password"
               placeholder="At least 6 characters" required>
        <div class="pw-strength">
          <div class="pw-strength-bar"><div class="pw-strength-fill" id="pwStrengthFill"></div></div>
          <div class="pw-strength-label" id="pwStrengthLabel"></div>
        </div>
      </div>
      <div class="pw-field">
        <label for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password"
               placeholder="Repeat new password" required>
      </div>
      <button type="submit" name="change_password" class="pw-submit">Update Password</button>
    </form>
  </div>
</div>

<div class="account-wrap">

  <div class="profile-card">
    <div class="avatar"><?= strtoupper(mb_substr($user['full_name'], 0, 1)) ?></div>
    <div class="profile-info">
      <div class="profile-name"><?= e($user['full_name']) ?></div>
      <div class="profile-email"><?= e($user['email'] ?? '') ?></div>
      <span class="profile-role"><?= e($user['role'] ?? 'Customer') ?></span>
    </div>
    <div class="profile-actions">
      <button type="button" class="change-pw-btn" id="openPwModal">🔒 Change Password</button>
      <form method="post" style="margin:0">
        <button type="submit" name="logout" class="logout-btn">🚪 Logout</button>
      </form>
    </div>
  </div>

  <div class="stats-row">
    <div class="stat-tile">
      <div class="stat-icon">🧾</div>
      <div class="stat-num"><?= (int)$stats['total_orders'] ?></div>
      <div class="stat-lbl">Total Orders</div>
    </div>
    <div class="stat-tile">
      <div class="stat-icon">💸</div>
      <div class="stat-num">₱<?= money($stats['total_spent']) ?></div>
      <div class="stat-lbl">Total Spent</div>
    </div>
  </div>

  <div class="section-title">📦 Order History</div>

  <?php if (!$orders): ?>
    <div class="empty-orders">
      <div class="emoji">🛍️</div>
      <p>No orders yet. Go grab some soda!</p>
      <a href="/yummy-soda/public/index.php#order" class="btn-primary" style="display:inline-flex;margin-top:20px;">Shop Now</a>
    </div>
  <?php else: ?>
    <?php foreach ($orders as $order):
      $sc  = $statusColors[$order['status']] ?? $statusColors['PENDING'];
      $its = $orderItems[$order['order_id']] ?? [];
      $num = (int)$order['user_order_num'];
    ?>
    <div class="order-card">
      <div class="order-card-header">
        <div>
          <div class="order-id">Order <?= e(fmt_order_num($num)) ?></div>
          <div class="order-date"><?= $order['order_time'] ? date('M j, Y – g:i A', strtotime($order['order_time'])) : '—' ?></div>
        </div>
        <span class="order-status" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;border-color:<?= $sc['border'] ?>">
          <?= e($order['status']) ?>
        </span>
        <?php if ($order['status'] === 'PENDING'): ?>
          <span style="font-size:11px;color:#92400e;font-weight:600;display:block;margin-top:4px;">⏳ Awaiting admin approval</span>
        <?php endif; ?>
      </div>

      <?php if ($its): ?>
      <div class="order-items-list">
        <?php foreach ($its as $it): ?>
        <div class="order-item-row">
          <span class="item-name"><?= e($it['name']) ?></span>
          <span class="item-qty">× <?= (int)$it['quantity'] ?></span>
          <span class="item-price">₱<?= money($it['line_total']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="order-footer">
        <span class="payment-method">💳 <?= e($order['method'] ?? '—') ?></span>
        <span class="order-total">Total: ₱<?= money($order['amount'] ?? 0) ?></span>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

<script>
(function() {
  const backdrop   = document.getElementById('pwModalBackdrop');
  const openBtn    = document.getElementById('openPwModal');
  const closeBtn   = document.getElementById('pwModalClose');

  function openModal() {
    backdrop.classList.add('is-open');
    document.body.style.overflow = 'hidden';
  }
  function closeModal() {
    backdrop.classList.remove('is-open');
    document.body.style.overflow = '';
  }

  if (openBtn) openBtn.addEventListener('click', openModal);
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (backdrop) backdrop.addEventListener('click', function(e) { if (e.target === backdrop) closeModal(); });
  document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });

  <?php if ($pwError !== '' || $pwSuccess !== ''): ?>
  openModal();
  <?php endif; ?>

  const newPwInput   = document.getElementById('new_password');
  const strengthFill = document.getElementById('pwStrengthFill');
  const strengthLbl  = document.getElementById('pwStrengthLabel');

  if (newPwInput && strengthFill && strengthLbl) {
    newPwInput.addEventListener('input', function() {
      const val = this.value;
      let score = 0;
      if (val.length >= 6)  score++;
      if (val.length >= 10) score++;
      if (/[A-Z]/.test(val)) score++;
      if (/[0-9]/.test(val)) score++;
      if (/[^A-Za-z0-9]/.test(val)) score++;

      const levels = [
        { label: '',          color: '#e5e7eb', pct: '0%'   },
        { label: 'Weak',      color: '#ef4444', pct: '25%'  },
        { label: 'Fair',      color: '#f59e0b', pct: '50%'  },
        { label: 'Good',      color: '#3b82f6', pct: '75%'  },
        { label: 'Strong',    color: '#10b981', pct: '90%'  },
        { label: 'Very strong', color: '#059669', pct: '100%' },
      ];
      const lvl = levels[Math.min(score, 5)];
      strengthFill.style.width      = lvl.pct;
      strengthFill.style.background = lvl.color;
      strengthLbl.textContent       = lvl.label;
      strengthLbl.style.color       = lvl.color;
    });
  }
})();
</script>

<script src="assets/scroll-animations.js"></script>
</body>
</html>
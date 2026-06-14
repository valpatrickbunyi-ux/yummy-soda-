<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/helpers.php';
require_admin();

$pdo = db();
$currentPage = 'messages';

// Ensure table exists
$pdo->exec("
  CREATE TABLE IF NOT EXISTS messages (
    message_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    phone       VARCHAR(40)  NOT NULL,
    comment     TEXT         NOT NULL DEFAULT '',
    is_read     TINYINT(1)   NOT NULL DEFAULT 0,
    received_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!empty($_POST['mark_read'])) {
    $pdo->prepare("UPDATE messages SET is_read=1 WHERE message_id=?")->execute([(int)$_POST['mark_read']]);
    header('Location: /yummy-soda/admin/messages.php'); exit;
  }
  if (!empty($_POST['delete_id'])) {
    $pdo->prepare("DELETE FROM messages WHERE message_id=?")->execute([(int)$_POST['delete_id']]);
    header('Location: /yummy-soda/admin/messages.php'); exit;
  }
  if (isset($_POST['mark_all_read'])) {
    $pdo->exec("UPDATE messages SET is_read=1");
    header('Location: /yummy-soda/admin/messages.php'); exit;
  }
}

$messages    = $pdo->query("SELECT * FROM messages ORDER BY received_at DESC")->fetchAll();
$unreadCount = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE is_read=0")->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Messages — Yummy Soda Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="/yummy-soda/admin/admin.css">
</head>
<body>

<?php require __DIR__ . '/_nav.php'; ?>

<div class="main">
  <header class="topbar">
    <div class="topbar-title">
      <div class="topbar-title-icon">💬</div>
      Customer Messages
      <?php if ($unreadCount): ?>
        <span style="background:var(--amber);color:#fff;font-size:11px;font-weight:800;padding:3px 9px;border-radius:99px;font-family:var(--font-mono);"><?=$unreadCount?> unread</span>
      <?php endif; ?>
    </div>
    <?php if ($unreadCount > 0): ?>
    <form method="post" style="margin:0;">
      <button name="mark_all_read" value="1" class="topbar-btn">✓ Mark all read</button>
    </form>
    <?php endif; ?>
  </header>

  <div class="page-content">

    <?php if (!$messages): ?>
    <div class="panel">
      <div class="panel-body">
        <div class="empty-state">
          <span class="empty-icon">📭</span>
          <p class="empty-text">No messages yet. Customer inquiries will appear here.</p>
        </div>
      </div>
    </div>
    <?php else: ?>

    <?php foreach ($messages as $i => $m): ?>
    <div class="msg-card <?=$m['is_read'] ? '' : 'unread'?>"
         style="animation-delay: <?=$i * 0.04?>s;">
      <div class="msg-avatar"><?=strtoupper(mb_substr($m['name'], 0, 1))?></div>
      <?php if (!$m['is_read']): ?><div class="unread-dot"></div><?php endif; ?>
      <div class="msg-body">
        <div class="msg-meta">
          <span class="msg-name"><?=e($m['name'])?></span>
          <span class="msg-phone">📞 <?=e($m['phone'])?></span>
          <span class="msg-time"><?=date('M j, Y · g:i A', strtotime($m['received_at']))?></span>
        </div>
        <?php if ($m['comment']): ?>
        <div class="msg-comment"><?=e($m['comment'])?></div>
        <?php endif; ?>
        <div class="msg-actions">
          <?php if (!$m['is_read']): ?>
          <form method="post" style="margin:0;">
            <input type="hidden" name="mark_read" value="<?=(int)$m['message_id']?>">
            <button class="btn btn-secondary btn-sm">✓ Mark read</button>
          </form>
          <?php endif; ?>
          <form method="post" style="margin:0;" onsubmit="return confirm('Delete this message?')">
            <input type="hidden" name="delete_id" value="<?=(int)$m['message_id']?>">
            <button class="btn btn-danger btn-sm">🗑 Delete</button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>

  </div><!-- /page-content -->
</div><!-- /main -->
</body>
</html>
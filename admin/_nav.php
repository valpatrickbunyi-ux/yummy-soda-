<?php
/* ─────────────────────────────────────────
   _nav.php  — include in every admin page
   Usage: $currentPage = 'dashboard'; require __DIR__ . '/_nav.php';
   ───────────────────────────────────────── */

// Unread message count for badge
$_unread = 0;
try {
  $_unread = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE is_read=0")->fetchColumn();
} catch (Throwable $_ignored) {}

$_navItems = [
  ['href'=>'/yummy-soda/admin/dashboard.php',  'icon'=>'🏠', 'label'=>'Dashboard',  'key'=>'dashboard'],
  ['href'=>'/yummy-soda/admin/analytics.php',  'icon'=>'📊', 'label'=>'Analytics',  'key'=>'analytics'],
  ['href'=>'/yummy-soda/admin/products.php',   'icon'=>'🧃', 'label'=>'Products',   'key'=>'products'],
  ['href'=>'/yummy-soda/admin/orders.php',     'icon'=>'📦', 'label'=>'Orders',     'key'=>'orders'],
  ['href'=>'/yummy-soda/admin/messages.php',   'icon'=>'💬', 'label'=>'Messages',   'key'=>'messages', 'badge'=>$_unread],
  ['href'=>'/yummy-soda/admin/decisions.php',  'icon'=>'🎯', 'label'=>'Decisions',  'key'=>'decisions'],
];
$_utilItems = [
  ['href'=>'/yummy-soda/admin/etl_sync.php',       'icon'=>'⚡', 'label'=>'Run ETL',        'key'=>'etl'],
  ['href'=>'/yummy-soda/api/export_csv.php',        'icon'=>'📄', 'label'=>'Export CSV',     'key'=>''],
  ['href'=>'/yummy-soda/api/export_excel.php',      'icon'=>'📑', 'label'=>'Export Excel',   'key'=>''],
  ['href'=>'/yummy-soda/api/export_pdf.php',        'icon'=>'🖨️', 'label'=>'Export PDF',     'key'=>''],
];
?>
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="sidebar-brand-icon">🥤</div>
    <div class="sidebar-brand-text">
      <span class="sidebar-brand-name">Yummy Soda</span>
      <span class="sidebar-brand-sub">Admin Panel</span>
    </div>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-label">Main</div>
    <?php foreach($_navItems as $_item): ?>
    <a href="<?=htmlspecialchars($_item['href'])?>"
       class="nav-item <?=($currentPage===($_item['key']??''))?'active':''?>">
      <span class="nav-icon"><?=$_item['icon']?></span>
      <?=$_item['label']?>
      <?php if(!empty($_item['badge'])): ?>
        <span class="nav-badge"><?=(int)$_item['badge']?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>

    <div class="sidebar-divider"></div>
    <div class="sidebar-label">Tools</div>
    <?php foreach($_utilItems as $_item): ?>
    <a href="<?=htmlspecialchars($_item['href'])?>"
       class="nav-item <?=($currentPage===($_item['key']??''))?'active':''?>">
      <span class="nav-icon"><?=$_item['icon']?></span>
      <?=$_item['label']?>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="sidebar-footer">
    <a href="/yummy-soda/admin/logout.php" class="nav-item logout">
      <span class="nav-icon">🚪</span>Logout
    </a>
  </div>
</aside>
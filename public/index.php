<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Handle contact form POST right here — no separate file needed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'message') {
    header('Content-Type: application/json');
    ob_start();

    $name    = trim($_POST['name']    ?? '');
    $phone   = trim($_POST['phone']   ?? '');
    $comment = trim($_POST['comment'] ?? '');

    if ($name === '' || $phone === '') {
        ob_end_clean();
        echo json_encode(['ok' => false, 'error' => 'Name and phone are required.']);
        exit;
    }

    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
            message_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(120) NOT NULL,
            phone       VARCHAR(40)  NOT NULL,
            comment     TEXT         NOT NULL DEFAULT '',
            is_read     TINYINT(1)   NOT NULL DEFAULT 0,
            received_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->prepare("INSERT INTO messages (name, phone, comment) VALUES (?, ?, ?)")
            ->execute([$name, $phone, $comment]);

        ob_end_clean();
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Load active products from DB ─────────────────────────────────────────────
$products = [];
try {
    $stmt = db()->query("SELECT * FROM products WHERE is_active = 1 ORDER BY product_id ASC");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $_) {
    // Products table may not exist yet — gracefully degrade
}

// Fallback static products if DB is empty
$fallbackProducts = [
    ['product_id' => 0, 'name' => 'Lime Boost',       'price' => 0, 'description' => '', 'image_path' => '', 'static_img' => 'assets/images/LimeSoda.png',       'btn_class' => 'order-btn-1', 'emoji' => '🍋'],
    ['product_id' => 0, 'name' => 'Strawberry Boost', 'price' => 0, 'description' => '', 'image_path' => '', 'static_img' => 'assets/images/StrawberrySoda.png', 'btn_class' => 'order-btn-2', 'emoji' => '🍓'],
    ['product_id' => 0, 'name' => 'Orange Boost',     'price' => 0, 'description' => '', 'image_path' => '', 'static_img' => 'assets/images/OrangeSoda.png',     'btn_class' => 'order-btn-3', 'emoji' => '🍊'],
];
$btnClasses = ['order-btn-1', 'order-btn-2', 'order-btn-3'];
$emojis     = ['🍋', '🍓', '🍊', '🥤', '🫧', '🧃'];

// For DB products that have no uploaded image yet, guess the static PNG by name keyword.
function guess_static_img(string $name): string {
    $n = strtolower($name);
    if (str_contains($n, 'lime'))       return 'assets/images/LimeSoda.png';
    if (str_contains($n, 'strawberry')) return 'assets/images/StrawberrySoda.png';
    if (str_contains($n, 'orange'))     return 'assets/images/OrangeSoda.png';
    return '';
}

$displayProducts = [];
if (!empty($products)) {
    foreach ($products as $i => $p) {
        $displayProducts[] = [
            'product_id'  => $p['product_id'],
            'name'        => $p['name'],
            'price'       => $p['price'],
            'description' => $p['description'] ?? '',
            'image_path'  => $p['image_path'] ?? '',
            'static_img'  => guess_static_img($p['name']),
            'btn_class'   => $btnClasses[$i % count($btnClasses)],
            'emoji'       => $emojis[$i % count($emojis)],
            'stock_qty'   => (int)($p['stock_qty'] ?? 99),
        ];
    }
} else {
    $displayProducts = $fallbackProducts;
}

// Image URL resolver: prefer uploaded image, then keyword-guessed static PNG, then ''.
function resolve_img(string $imagePath, string $staticImg): string {
    if ($imagePath !== '') {
        return '/yummy-soda/public/assets/images/' . rawurlencode($imagePath);
    }
    return $staticImg;
}

$_loggedIn     = (bool)current_user();
$_user         = current_user();
$_accountUrl   = $_loggedIn ? '/yummy-soda/public/account.php' : '/yummy-soda/customer/login.php';
$_accountLabel = 'Account';
$_avatarInitial = $_loggedIn ? strtoupper(mb_substr($_user['full_name'], 0, 1)) : '';
$_displayName   = $_loggedIn ? htmlspecialchars($_user['full_name'], ENT_QUOTES, 'UTF-8') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Yummy Soda – Natural Juice Soda</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    .nav-profile-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 5px 14px 5px 6px;
      border-radius: 999px;
      background: rgba(255,255,255,0.15);
      border: 1.5px solid rgba(255,255,255,0.25);
      text-decoration: none;
      transition: background 0.2s, transform 0.2s, box-shadow 0.2s;
      backdrop-filter: blur(6px);
    }
    .nav-profile-chip:hover {
      background: rgba(255,255,255,0.28);
      transform: translateY(-1px);
      box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    }
    .nav-avatar {
      width: 30px; height: 30px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--teal, #22c1c3), var(--amber, #fdbb2d));
      display: inline-flex; align-items: center; justify-content: center;
      font-size: 14px; font-weight: 900; color: #fff;
      flex-shrink: 0;
      box-shadow: 0 2px 8px rgba(34,193,195,0.35);
      font-family: var(--font-display, sans-serif);
    }
    .nav-profile-name {
      font-size: 13px;
      font-weight: 800;
      color: #fff;
      white-space: nowrap;
      max-width: 140px;
      overflow: hidden;
      text-overflow: ellipsis;
      font-family: var(--font-body, sans-serif);
    }
    .oos-btn {
      opacity: 0.5 !important;
      cursor: not-allowed !important;
      filter: grayscale(0.6);
    }
    .oos-btn:hover { transform: none !important; box-shadow: none !important; }
  </style>
</head>
<body>

<nav>
  <a href="#home">Home</a>
  <a href="<?= $_accountUrl ?>"><?= $_accountLabel ?></a>
  <a href="#order">Order</a>
  <a href="/yummy-soda/public/cart.php">Cart</a>
  <a href="#contact">Contact</a>
  <?php if ($_loggedIn): ?>
  <a href="<?= $_accountUrl ?>" class="nav-profile-chip" title="My Account">
    <span class="nav-avatar"><?= $_avatarInitial ?></span>
    <span class="nav-profile-name"><?= $_displayName ?></span>
  </a>
  <?php endif; ?>
</nav>

<!-- HERO -->
<div class="container" id="home">
  <div class="content-left">
    <h1>Yummy<br>Soda</h1>
    <p class="tagline">Natural juices for those who refuse to compromise. Cold-pressed, no added sugar, no preservatives — just pure fruit energy in every sip.</p>
    <div class="buttons">
      <a href="#order" class="btn-primary">Order Now</a>
      <a href="#order" class="btn-secondary">Explore Flavors</a>
    </div>
  </div>
  <div class="content-right">
    <img src="assets/images/main.png" alt="Zest Citrus Can" class="can-image" style="height:500px;min-height:10px;">
  </div>
</div>

<!-- ABOUT / BENTO -->
<div>
  <div class="products-description">
    <div class="column">
      <h2>Our Juices</h2>
      <p class="description"><strong>Yummy Soda</strong> — three refreshing flavors made from the finest ingredients. Cold-pressed and bottled fresh to preserve every nutrient and note of taste.<br><br>No shortcuts, no compromises, no artificial additives. Just pure organic citrus expertly pressed to deliver sharp flavor and vital vitamins in every drop.</p>
    </div>

    <div class="bento-layout">
      <div class="bento-grid-wrapper">
        <div class="grid-card cloud-card">
          <div class="floating-pills">
            <span class="bubble bubble-outline rotate-left">Phosphorus</span>
            <span class="bubble bubble-green rotate-right">B2, B6</span>
            <span class="bubble bubble-outline-yellow rotate-left-slight">Vitamin C</span>
            <span class="bubble bubble-pink rotate-right-slight">Mg</span>
          </div>
        </div>

        <div class="grid-card card-tall card-light-green">
          <h3>Health Advocacy</h3>
          <p>Designed to support an active and vibrant lifestyle, every single day.</p>
        </div>

        <div class="grid-card card-orange">
          <h3>Vitamins &amp; Minerals</h3>
        </div>

        <div class="grid-card card-pink">
          <h3>Energy</h3>
          <p>Fresh vitality every single day.</p>
        </div>

        <div class="grid-card card-dark-green">
          <div class="badge-percentage">100%</div>
          <p>Natural Juice Extracts</p>
        </div>
      </div>
    </div>
  </div>

  <!-- ORDER SECTION -->
  <div class="order-section" id="order">
    <div class="order-wrapper">
      <h2>Choose Your Flavor</h2>
      <div class="bottles-wrapper">
        <?php foreach ($displayProducts as $i => $prod): ?>
        <?php
          $imgSrc = resolve_img($prod['image_path'], $prod['static_img']);
          $hasImg = (bool)$imgSrc;
        ?>
        <div class="bottle-card">
          <?php if ($hasImg): ?>
            <img src="<?=htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8')?>"
                 alt="<?=htmlspecialchars($prod['name'], ENT_QUOTES, 'UTF-8')?>">
          <?php else: ?>
            <div style="height:440px;display:flex;align-items:center;justify-content:center;font-size:80px;opacity:0.4;">
              <?=$prod['emoji']?>
            </div>
          <?php endif; ?>
          <?php $outOfStock = isset($prod['stock_qty']) && (int)$prod['stock_qty'] <= 0; ?>
          <button class="order-btn <?=htmlspecialchars($prod['btn_class'], ENT_QUOTES, 'UTF-8')?><?= $outOfStock ? ' oos-btn' : '' ?>"
                  type="button"
                  data-product-idx="<?=(int)$i?>"
                  <?= $outOfStock ? 'data-oos="1"' : '' ?>>
            <?=htmlspecialchars($prod['name'], ENT_QUOTES, 'UTF-8')?>
            <?= $outOfStock ? ' — <span style="font-size:11px;opacity:0.85;">Out of Stock</span>' : '' ?>
          </button>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- CONTACT SECTION -->
<div class="contact-section" id="contact">
  <div class="contact-hero">
    <div class="contact-bg-elements">
      <div class="berry b1">🍓</div>
      <div class="berry b2">🍒</div>
      <div class="berry b3">🫐</div>
      <div class="berry b4">🍓</div>
      <div class="berry b5">🍒</div>
    </div>

    <div class="contact-content">
      <div class="contact-left">
        <h2>Message<br>us</h2>
        <p>Get your daily dose of<br>energy and vitamins<br>every single day!</p>
      </div>

      <div class="contact-center">
        <img src="assets/images/StrawberrySoda.png" alt="Strawberry Soda" class="contact-can">
      </div>

      <div class="contact-right">
        <form class="contact-form" id="contactForm">
          <input type="text"  name="name"    placeholder="Your name"    required>
          <input type="tel"   name="phone"   placeholder="Phone number" required>
          <textarea           name="comment" placeholder="Your message" rows="3"></textarea>
          <button type="submit" class="contact-submit" id="contactSubmitBtn">Send Message</button>
          <div id="contactMsg" style="display:none;margin-top:4px;padding:11px 16px;border-radius:14px;font-size:14px;font-weight:700;word-break:break-word;"></div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- PRODUCT MODAL -->
<div class="modal-backdrop" id="productModalBackdrop" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <button class="modal-close" type="button" id="modalCloseBtn" aria-label="Close">×</button>
    <div class="modal-grid">
      <div class="modal-left">
        <img id="modalImg" src="" alt="" class="modal-img">
      </div>
      <div class="modal-right">
        <h2 id="modalTitle" class="modal-title"></h2>
        <p id="modalDesc" class="modal-desc"></p>
        <div class="modal-row">
          <div class="price" id="modalPrice"></div>
          <div class="qty">
            <button type="button" class="qty-btn" id="qtyMinus" aria-label="Decrease quantity">−</button>
            <input type="number" id="qtyInput" min="1" value="1" aria-label="Quantity">
            <button type="button" class="qty-btn" id="qtyPlus" aria-label="Increase quantity">+</button>
          </div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn-primary" id="addToCartBtn">🛒 Add to Cart</button>
          <button type="button" class="btn-secondary" id="buyNowBtn">Buy Now</button>
        </div>
        <div class="cart-note" id="cartNote" style="display:none;"></div>
      </div>
    </div>
  </div>
</div>

<?php
// Build the JS product array from the same $displayProducts already computed above.
// This is output BEFORE scroll-animations.js so the file can reference window.PRODUCTS.
$jsProducts = [];
foreach ($displayProducts as $p) {
    $imgSrc = resolve_img($p['image_path'], $p['static_img']);
    $jsProducts[] = [
        'product_id'  => (int)$p['product_id'],
        'name'        => $p['name'],
        'price'       => (float)$p['price'],
        'description' => $p['description'] ?: 'A refreshing Yummy Soda flavor. Cold-pressed, no added sugar.',
        'image'       => $imgSrc,
        'stock_qty'   => (int)($p['stock_qty'] ?? 99),
    ];
}
?>
<script>
/* Product catalogue — injected by PHP, consumed by modal logic below */
window.PRODUCTS = <?=json_encode($jsProducts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)?>;
</script>

<script src="assets/scroll-animations.js"></script>

<script>
// ── Modal + Cart — single source of truth ────────────────────────────────────
(function () {
  const backdrop    = document.getElementById('productModalBackdrop');
  const modalImg    = document.getElementById('modalImg');
  const modalTitle  = document.getElementById('modalTitle');
  const modalDesc   = document.getElementById('modalDesc');
  const modalPrice  = document.getElementById('modalPrice');
  const qtyInput    = document.getElementById('qtyInput');
  const cartNote    = document.getElementById('cartNote');

  let activeProduct = null;

  // Open modal with a product object from window.PRODUCTS
  function openModal(product) {
    activeProduct = product;

    if (product.image) {
      modalImg.src           = product.image;
      modalImg.alt           = product.name;
      modalImg.style.display = '';
    } else {
      modalImg.src           = '';
      modalImg.style.display = 'none';
    }

    modalTitle.textContent = product.name;
    modalDesc.textContent  = product.description;
    modalPrice.textContent = product.price > 0
      ? '₱' + product.price.toFixed(2)
      : '';

    const stock = product.stock_qty ?? 99;
    const addBtn = document.getElementById('addToCartBtn');

    qtyInput.value = '1';
    qtyInput.max   = stock > 0 ? String(stock) : '1';
    qtyInput.min   = '1';

    if (stock <= 0) {
      addBtn.disabled          = true;
      addBtn.textContent       = '🚫 Out of Stock';
      addBtn.style.opacity     = '0.5';
      addBtn.style.cursor      = 'not-allowed';
      cartNote.style.display   = 'block';
      cartNote.style.background = '#fee2e2';
      cartNote.style.color      = '#991b1b';
      cartNote.textContent      = 'This item is currently out of stock.';
    } else {
      addBtn.disabled        = false;
      addBtn.textContent     = '🛒 Add to Cart';
      addBtn.style.opacity   = '';
      addBtn.style.cursor    = '';
      cartNote.style.display = 'none';
      cartNote.textContent   = '';
    }

    backdrop.classList.add('is-open');
    backdrop.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    backdrop.classList.remove('is-open');
    backdrop.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    activeProduct = null;
  }

  // Wire every order button via data-product-idx (set by PHP loop)
  document.querySelectorAll('[data-product-idx]').forEach(btn => {
    btn.addEventListener('click', () => {
      const idx = parseInt(btn.getAttribute('data-product-idx'), 10);
      const p   = (window.PRODUCTS || [])[idx];
      if (p) openModal(p);
    });
  });

  document.getElementById('modalCloseBtn')?.addEventListener('click', closeModal);
  backdrop?.addEventListener('click', e => { if (e.target === backdrop) closeModal(); });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && backdrop.classList.contains('is-open')) closeModal();
  });

  // Qty controls
  document.getElementById('qtyMinus')?.addEventListener('click', () => {
    qtyInput.value = String(Math.max(1, parseInt(qtyInput.value || '1', 10) - 1));
  });
  document.getElementById('qtyPlus')?.addEventListener('click', () => {
    const max = parseInt(qtyInput.max || '99', 10);
    const cur = parseInt(qtyInput.value || '1', 10);
    qtyInput.value = String(Math.min(max, Math.max(1, cur + 1)));
  });

  // Add to Cart
  document.getElementById('addToCartBtn')?.addEventListener('click', async () => {
    if (!activeProduct) return;

    if (!activeProduct.product_id) {
      cartNote.style.display    = 'block';
      cartNote.style.background = '#fee2e2';
      cartNote.style.color      = '#991b1b';
      cartNote.textContent      = '⚠️ This product cannot be added to cart yet.';
      return;
    }

    const qty  = Math.max(1, parseInt(qtyInput.value || '1', 10));
    const body = new URLSearchParams({ product_id: String(activeProduct.product_id), qty: String(qty) });

    cartNote.style.display    = 'none';
    cartNote.style.background = '';
    cartNote.style.color      = '';

    try {
      const res = await fetch('/yummy-soda/public/add_to_cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'include'
      });

      cartNote.style.display = 'block';

      if (res.redirected) {
        cartNote.style.background = '#fee2e2';
        cartNote.style.color      = '#991b1b';
        cartNote.textContent      = '⚠️ Please login first to add to cart.';
        return;
      }
      if (!res.ok) {
        cartNote.style.background = '#fee2e2';
        cartNote.style.color      = '#991b1b';
        cartNote.textContent      = '❌ Failed to add. Try again.';
        return;
      }

      cartNote.style.background = '#d1fae5';
      cartNote.style.color      = '#065f46';
      cartNote.textContent      = `✅ Added ${qty}× ${activeProduct.name} to cart!`;
      setTimeout(() => { cartNote.style.display = 'none'; }, 3000);

    } catch (err) {
      cartNote.style.display    = 'block';
      cartNote.style.background = '#fee2e2';
      cartNote.style.color      = '#991b1b';
      cartNote.textContent      = 'Error: ' + err.message;
    }
  });

  // Buy Now — scroll to order section (same behaviour as original)
  document.getElementById('buyNowBtn')?.addEventListener('click', () => {
    closeModal();
    document.getElementById('order')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

})();

// ── Contact form ─────────────────────────────────────────────────────────────
document.getElementById('contactForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const form  = e.target;
  const btn   = document.getElementById('contactSubmitBtn');
  const msgEl = document.getElementById('contactMsg');

  btn.disabled    = true;
  btn.textContent = 'Sending…';
  msgEl.style.display = 'none';

  const showError = msg => {
    msgEl.style.background = 'rgba(254,226,226,0.9)';
    msgEl.style.color      = '#991b1b';
    msgEl.textContent      = '❌ ' + msg;
    msgEl.style.display    = 'block';
  };

  try {
    const res = await fetch('/yummy-soda/public/index.php?action=message', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(new FormData(form)).toString(),
      credentials: 'include'
    });
    const raw = await res.text();
    let json;
    try { json = JSON.parse(raw); }
    catch { showError('Server said: ' + raw.substring(0, 300)); btn.disabled = false; btn.textContent = 'Send Message'; return; }

    if (json.ok) {
      msgEl.style.background = 'rgba(209,250,229,0.9)';
      msgEl.style.color      = '#065f46';
      msgEl.textContent      = '✅ Message sent! We\'ll contact you soon.';
      msgEl.style.display    = 'block';
      form.reset();
    } else {
      showError(json.error || 'Something went wrong.');
    }
  } catch (err) {
    showError('Could not reach server: ' + err.message);
  }

  btn.disabled    = false;
  btn.textContent = 'Send Message';
});
</script>
</body>
</html>
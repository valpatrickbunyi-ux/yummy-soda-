<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/cart.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/helpers.php';
require_login();

// Generate a one-time checkout token to prevent double-submit.
// Only create a fresh token if there isn't one already — otherwise a
// qty-save redirect (or any other reload) would overwrite the token that
// was already embedded in the form the user is about to submit.
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['checkout_token'])) {
    $_SESSION['checkout_token'] = bin2hex(random_bytes(16));
}
$checkout_token = $_SESSION['checkout_token'];

$error     = isset($_GET['error'])     ? htmlspecialchars($_GET['error'])     : '';
$success   = isset($_GET['success'])   && $_GET['success'] === '1';
$order_ref = isset($_GET['order_ref']) ? htmlspecialchars($_GET['order_ref']) : '';

$cart  = cart_items();
$items = [];
$total = 0.0;

if ($cart) {
    $ids  = array_keys($cart);
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT product_id,name,price,stock_qty,is_active FROM products WHERE product_id IN ($in)");
    $stmt->execute($ids);
    $products = [];
    foreach ($stmt->fetchAll() as $p) $products[(int)$p['product_id']] = $p;

    foreach ($cart as $pid => $qty) {
        $p = $products[(int)$pid] ?? null;
        if (!$p || (int)$p['is_active'] !== 1) continue;
        $line   = (float)$p['price'] * (int)$qty;
        $total += $line;
        $items[] = ['product' => $p, 'qty' => (int)$qty, 'line' => $line];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cart – Yummy Soda</title>
  <link rel="stylesheet" href="/yummy-soda/public/assets/style.css">
</head>
<body class="cart-page-body">

<nav>
  <a href="/yummy-soda/public/index.php#home">Home</a>
  <a href="/yummy-soda/public/account.php">Account</a>
  <a href="/yummy-soda/public/index.php#order">Order</a>
  <a href="/yummy-soda/public/cart.php">Cart</a>
  <a href="/yummy-soda/public/index.php#contact">Contact</a>
</nav>

<div class="cart-container">
  <div class="cart-card">

    <!-- Header -->
    <div class="cart-header">
      <a href="/yummy-soda/public/index.php" class="btn btn-back">← Back to Shop</a>
      <h1>Your Cart 🛒</h1>
    </div>

    <!-- Success banner -->
    <?php if ($success): ?>
    <div class="stock-success" id="successBanner">
      <span class="stock-success-icon">🎉</span>
      <div class="stock-success-body">
        <div class="stock-success-title">Order placed successfully!</div>
        <div class="stock-success-msg">
          <?php if ($order_ref): ?>
            Your order <strong><?= $order_ref ?></strong> is confirmed and being processed.
          <?php else: ?>
            Your order is confirmed and being processed.
          <?php endif; ?>
          Thanks for shopping with Yummy Soda!
        </div>
      </div>
      <button class="stock-success-close" onclick="dismissSuccess()" title="Dismiss">×</button>
      <div class="stock-success-progress"></div>
    </div>
    <?php endif; ?>

    <!-- Error banner -->
    <?php if ($error): ?>
    <div class="stock-error" id="stockError">
      <span class="stock-error-icon">⚠️</span>
      <div class="stock-error-body">
        <div class="stock-error-title">Checkout failed</div>
        <div class="stock-error-msg"><?= $error ?></div>
      </div>
      <button class="stock-error-close" onclick="dismissError()" title="Dismiss">×</button>
      <div class="stock-error-progress"></div>
    </div>
    <?php endif; ?>

    <?php if (!$items): ?>
      <div style="padding:64px 40px;text-align:center;">
        <div style="font-size:60px;margin-bottom:16px;">🛒</div>
        <p style="font-size:18px;color:#6b7280;margin-bottom:24px;font-weight:600;">Your cart is empty.</p>
        <a href="/yummy-soda/public/index.php#order" class="btn-primary">Browse Flavors</a>
      </div>

    <?php else: ?>

      <form method="post" action="/yummy-soda/public/checkout.php" id="checkoutForm">
      <table class="cart-table">
        <thead>
          <tr>
            <th>
              <input type="checkbox" id="selectAll" title="Select all"
                     style="width:16px;height:16px;cursor:pointer;">
            </th>
            <th>Product</th>
            <th>Quantity</th>
            <th>Price</th>
            <th>Line Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it):
          $pid      = (int)$it['product']['product_id'];
          $stock    = (int)$it['product']['stock_qty'];
          $qty      = $it['qty'];
          $lowStock = $stock > 0 && $stock <= 3;
        ?>
          <tr class="cart-row<?= $lowStock ? ' low-stock-row' : '' ?>" data-pid="<?= $pid ?>" data-line="<?= $it['line'] ?>">

            <td>
              <input type="checkbox" name="selected_items[]" value="<?= $pid ?>"
                     class="item-checkbox" checked
                     style="width:16px;height:16px;cursor:pointer;">
            </td>

            <td class="cart-product-name">
              <?= e($it['product']['name']) ?>
              <?php if ($lowStock): ?>
                <span class="low-stock-badge">Only <?= $stock ?> left!</span>
              <?php endif; ?>
            </td>

            <td>
              <div class="qty-form">
                <input type="hidden" name="product_id_upd[]" value="<?= $pid ?>">
                <div class="cart-qty-control">
                  <button type="button" class="cart-qty-btn cart-qty-minus">−</button>
                  <input type="number" name="qty_upd[]" class="cart-qty-input"
                         value="<?= $qty ?>" min="1" max="<?= $stock ?>"
                         data-max="<?= $stock ?>" data-pid="<?= $pid ?>">
                  <button type="button" class="cart-qty-btn cart-qty-plus" data-max="<?= $stock ?>">+</button>
                  <button type="button" class="cart-qty-update cart-qty-save" data-pid="<?= $pid ?>"
                          data-href="/yummy-soda/public/update_cart.php" title="Update quantity">✓</button>
                </div>
              </div>
            </td>

            <td>₱<?= e(money($it['product']['price'])) ?></td>
            <td class="line-total"><strong>₱<?= e(money($it['line'])) ?></strong></td>

            <td>
              <button type="button" class="cart-remove-btn" title="Remove item"
                      data-remove-pid="<?= $pid ?>">🗑</button>
            </td>

          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="cart-total">
        Selected Total: <span id="selectedTotal">₱<?= e(money($total)) ?></span>
      </div>

      <div class="checkout-form">
          <label>Payment method</label>
          <select name="payment_method" required>
            <option value="CASH">Cash on Delivery</option>
            <option value="GCASH">GCash</option>
            <option value="CARD">Card</option>
          </select>
          <input type="hidden" name="checkout_token" value="<?= htmlspecialchars($checkout_token, ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit" class="btn-primary" id="checkoutBtn">Checkout →</button>
      </div>
      </form>

    <?php endif; ?>
  </div>
</div>

<div class="max-toast" id="maxToast"></div>

<script>
// ── Qty auto-sync (debounced) ─────────────────────────────────────────────────
var saveTimers = {};
var unitPrices = {};
document.querySelectorAll('.cart-row[data-pid]').forEach(function(row) {
  var pid = row.dataset.pid;
var priceCell = row.querySelector('td:nth-child(4)');
if (priceCell) {
  var txt = priceCell.textContent.replace(/[₱,]/g, '').trim();
  unitPrices[pid] = parseFloat(txt) || 0;
}
});

function saveQty(pid, qty, inputEl, rowEl) {
  var fd = new FormData();
  fd.append('product_id', pid);
  fd.append('qty', qty);
  // Visual: show saving state
  var saveBtn = rowEl ? rowEl.querySelector('.cart-qty-save') : null;
  if (saveBtn) { saveBtn.textContent = '…'; saveBtn.classList.add('flash'); }
  fetch('/yummy-soda/public/update_cart.php', { method: 'POST', body: fd })
    .then(function() {
      // Update line total in the DOM without a full reload
      var lineTd = rowEl ? rowEl.querySelector('.line-total strong') : null;
      var max = parseInt(inputEl.dataset.max, 10);
      var v   = Math.min(parseInt(qty, 10), max);
      if (lineTd && unitPrices[pid]) {
        var newLine = unitPrices[pid] * v;
        lineTd.textContent = '₱' + newLine.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        // Update prices map so recalcTotal stays accurate
        prices[pid] = newLine;
        recalcTotal();
      }
      if (saveBtn) { saveBtn.textContent = '✓'; saveBtn.classList.remove('flash'); }
    })
    .catch(function() {
      if (saveBtn) { saveBtn.textContent = '✓'; saveBtn.classList.remove('flash'); }
    });
}

document.querySelectorAll('.cart-qty-control').forEach(function(ctrl) {
  var input  = ctrl.querySelector('.cart-qty-input');
  var minus  = ctrl.querySelector('.cart-qty-minus');
  var plus   = ctrl.querySelector('.cart-qty-plus');
  var update = ctrl.querySelector('.cart-qty-update');
  var max    = parseInt(input.dataset.max, 10);
  var pid    = input.dataset.pid;
  var row    = ctrl.closest('.cart-row');

  function scheduleAutoSave() {
    clearTimeout(saveTimers[pid]);
    flashTick(update);
    saveTimers[pid] = setTimeout(function() {
      saveQty(pid, input.value, input, row);
    }, 800); // save 800ms after last change
  }

  minus.addEventListener('click', function() {
    var v = parseInt(input.value, 10);
    if (v > 1) { input.value = v - 1; scheduleAutoSave(); }
  });

  plus.addEventListener('click', function() {
    var v = parseInt(input.value, 10);
    if (v < max) { input.value = v + 1; scheduleAutoSave(); }
    else showToast('Max available: ' + max);
  });

  input.addEventListener('input', function() {
    var v = parseInt(input.value, 10);
    if (isNaN(v) || v < 1) v = 1;
    if (v > max) { v = max; showToast('Max available: ' + max); }
    input.value = v;
    scheduleAutoSave();
  });
});

// Manual save button still works as instant trigger
document.querySelectorAll('.cart-qty-save').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var ctrl  = btn.closest('.cart-qty-control');
    var input = ctrl.querySelector('.cart-qty-input');
    var pid   = btn.dataset.pid;
    var row   = ctrl.closest('.cart-row');
    clearTimeout(saveTimers[pid]); // cancel any pending auto-save
    saveQty(pid, input.value, input, row);
  });
});

function flashTick(btn) {
  btn.classList.add('flash');
  setTimeout(function() { btn.classList.remove('flash'); }, 500);
}

function showToast(msg) {
  var t = document.getElementById('maxToast');
  t.textContent = msg;
  t.classList.add('visible');
  clearTimeout(t._t);
  t._t = setTimeout(function() { t.classList.remove('visible'); }, 2500);
}

// ── Selective checkout ────────────────────────────────────────────────────────
var prices = {};
document.querySelectorAll('.cart-row[data-pid]').forEach(function(row) {
  prices[row.dataset.pid] = parseFloat(row.dataset.line);
});

function recalcTotal() {
  var sum = 0;
  document.querySelectorAll('.item-checkbox:checked').forEach(function(cb) {
    sum += prices[cb.value] || 0;
  });
  document.getElementById('selectedTotal').textContent =
    '₱' + sum.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  var btn = document.getElementById('checkoutBtn');
  var anyChecked = document.querySelectorAll('.item-checkbox:checked').length > 0;
  btn.disabled = !anyChecked;
  btn.style.opacity = anyChecked ? '1' : '0.45';
}

document.querySelectorAll('.item-checkbox').forEach(function(cb) {
  cb.addEventListener('change', recalcTotal);
});

var selectAll = document.getElementById('selectAll');
if (selectAll) {
  selectAll.addEventListener('change', function() {
    document.querySelectorAll('.item-checkbox').forEach(function(cb) {
      cb.checked = selectAll.checked;
    });
    recalcTotal();
  });
}

document.querySelectorAll('.item-checkbox').forEach(function(cb) {
  cb.addEventListener('change', function() {
    var all  = document.querySelectorAll('.item-checkbox').length;
    var chkd = document.querySelectorAll('.item-checkbox:checked').length;
    selectAll.checked = (chkd === all);
    selectAll.indeterminate = (chkd > 0 && chkd < all);
  });
});

// Prevent double-submit: disable checkout button immediately on submit
document.getElementById('checkoutForm').addEventListener('submit', function(e) {
  var checked = document.querySelectorAll('.item-checkbox:checked').length;
  if (checked === 0) {
    e.preventDefault();
    showToast('Select at least one item to checkout.');
    return;
  }
  // Disable button to prevent double-submit
  var btn = document.getElementById('checkoutBtn');
  btn.disabled = true;
  btn.textContent = 'Processing…';
  btn.style.opacity = '0.7';
});

recalcTotal(); // init

// ── Banners ───────────────────────────────────────────────────────────────────
function dismissError() {
  var el = document.getElementById('stockError');
  if (!el) return;
  el.style.transition = 'opacity 0.4s, transform 0.4s';
  el.style.opacity = '0';
  el.style.transform = 'translateY(-10px) scale(0.97)';
  setTimeout(function() { el.remove(); }, 420);
}

function dismissSuccess() {
  var el = document.getElementById('successBanner');
  if (!el) return;
  el.style.transition = 'opacity 0.4s, transform 0.4s';
  el.style.opacity = '0';
  el.style.transform = 'translateY(-10px) scale(0.97)';
  setTimeout(function() { el.remove(); }, 420);
}

var errEl = document.getElementById('stockError');
if (errEl) setTimeout(dismissError, 8000);

var sucEl = document.getElementById('successBanner');
if (sucEl) {
  setTimeout(dismissSuccess, 10000);
  // Clean the URL so a reload won't re-trigger the success banner
  if (window.history && window.history.replaceState) {
    window.history.replaceState({}, '', '/yummy-soda/public/cart.php');
  }
}

// ── Remove item buttons ───────────────────────────────────────────────────────
document.querySelectorAll('.cart-remove-btn[data-remove-pid]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var pid = btn.dataset.removePid;
    var fd  = new FormData();
    fd.append('product_id', pid);
    fd.append('qty', '0');
    btn.disabled = true;
    btn.textContent = '…';
    fetch('/yummy-soda/public/update_cart.php', { method: 'POST', body: fd })
      .then(function() {
        var row = document.querySelector('.cart-row[data-pid="' + pid + '"]');
        if (row) row.remove();
        delete prices[pid];
        recalcTotal();
        if (document.querySelectorAll('.cart-row').length === 0) {
          window.location.reload();
        }
      })
      .catch(function() {
        btn.disabled = false;
        btn.textContent = '🗑';
      });
  });
});
</script>

</body>
</html>
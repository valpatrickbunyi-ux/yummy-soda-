<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/helpers.php';
require_admin();

$pdo = db();
$currentPage = 'products';
$msg = '';
$msgType = 'ok';

// ── Ensure image_path column exists ──────────────────────────────────────────
try {
    $pdo->query("SELECT image_path FROM products LIMIT 1");
} catch (Throwable $_) {
    $pdo->exec("ALTER TABLE products ADD COLUMN image_path VARCHAR(255) NOT NULL DEFAULT '' AFTER is_active");
}

// ── Upload directory setup ────────────────────────────────────────────────────
$uploadDir = __DIR__ . '/../public/assets/images/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ── Helper: handle uploaded image ────────────────────────────────────────────
function handle_product_image(array $file, string $uploadDir, string $oldPath = ''): string {
    // No new file uploaded — keep old
    if (empty($file['tmp_name'])) return $oldPath;

    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) return $oldPath;

    $ext      = match($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    };
    $filename = 'product_' . uniqid() . '.' . $ext;
    $dest     = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        // Delete old file if it exists and is different
        if ($oldPath && $oldPath !== $filename) {
            $oldFull = $uploadDir . basename($oldPath);
            if (file_exists($oldFull)) @unlink($oldFull);
        }
        return $filename;
    }
    return $oldPath;
}

// ── POST Actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete' && !empty($_POST['product_id'])) {
        // Remove image file too
        $row = $pdo->prepare("SELECT image_path FROM products WHERE product_id=?");
        $row->execute([(int)$_POST['product_id']]);
        $imgPath = $row->fetchColumn();
        if ($imgPath && file_exists($uploadDir . basename($imgPath))) {
            @unlink($uploadDir . basename($imgPath));
        }
        $pdo->prepare("DELETE FROM products WHERE product_id=?")->execute([(int)$_POST['product_id']]);
        $msg = 'Product deleted.';

    } elseif (in_array($action, ['add', 'edit'])) {
        $sku      = trim($_POST['sku']      ?? '');
        $name     = trim($_POST['name']     ?? '');
        $category = trim($_POST['category'] ?? '');
        $price    = (float)($_POST['price']     ?? 0);
        $stock    = (int)($_POST['stock_qty']   ?? 0);
        $active   = isset($_POST['is_active']) ? 1 : 0;

        if ($action === 'add') {
            $imgFilename = handle_product_image($_FILES['product_image'] ?? [], $uploadDir);
            $pdo->prepare(
                "INSERT INTO products(sku,name,category,price,stock_qty,is_active,image_path)
                 VALUES(?,?,?,?,?,?,?)"
            )->execute([$sku, $name, $category, $price, $stock, $active, $imgFilename]);
            $msg = 'Product added successfully.';
        } else {
            $id     = (int)$_POST['product_id'];
            $oldImg = $pdo->prepare("SELECT image_path FROM products WHERE product_id=?");
            $oldImg->execute([$id]);
            $oldImgPath  = $oldImg->fetchColumn() ?: '';
            $imgFilename = handle_product_image($_FILES['product_image'] ?? [], $uploadDir, $oldImgPath);
            $pdo->prepare(
                "UPDATE products SET sku=?,name=?,category=?,price=?,stock_qty=?,is_active=?,image_path=?
                 WHERE product_id=?"
            )->execute([$sku, $name, $category, $price, $stock, $active, $imgFilename, $id]);
            $msg = 'Product updated successfully.';
        }
    }
}

$products = $pdo->query("SELECT * FROM products ORDER BY product_id DESC")->fetchAll();

// Build image URL helper
function product_img_url(string $filename): string {
    if (!$filename) return '';
    return '/yummy-soda/public/assets/images/' . rawurlencode($filename);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Products — Yummy Soda Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="/yummy-soda/admin/admin.css">
  <style>
    /* ── Image Upload Drop Zone ── */
    .img-upload-zone {
      border: 2px dashed var(--border);
      border-radius: var(--radius);
      padding: 20px;
      text-align: center;
      cursor: pointer;
      transition: all var(--transition);
      background: #f8fafc;
      position: relative;
    }
    .img-upload-zone:hover,
    .img-upload-zone.drag-over {
      border-color: var(--teal);
      background: var(--teal-pale);
    }
    .img-upload-zone input[type="file"] {
      position: absolute;
      inset: 0;
      opacity: 0;
      cursor: pointer;
      width: 100%;
      height: 100%;
    }
    .img-preview-wrap {
      position: relative;
      display: inline-block;
      margin-top: 10px;
    }
    .img-preview {
      width: 120px;
      height: 120px;
      object-fit: contain;
      border-radius: var(--radius-sm);
      background: #fff;
      border: 1.5px solid var(--border);
      padding: 4px;
    }
    .img-preview-remove {
      position: absolute;
      top: -8px; right: -8px;
      width: 22px; height: 22px;
      border-radius: 50%;
      background: var(--red);
      color: #fff;
      border: none;
      cursor: pointer;
      font-size: 13px;
      display: flex; align-items: center; justify-content: center;
      font-weight: 700;
      line-height: 1;
    }
    .img-upload-hint {
      font-size: 12.5px;
      color: var(--text-muted);
      margin-top: 4px;
    }
    /* ── Product table thumbnail ── */
    .product-thumb {
      width: 44px; height: 44px;
      object-fit: contain;
      border-radius: var(--radius-sm);
      background: #f8fafc;
      border: 1.5px solid var(--border);
      padding: 3px;
    }
    .product-thumb-placeholder {
      width: 44px; height: 44px;
      border-radius: var(--radius-sm);
      background: #f1f5f9;
      border: 1.5px dashed var(--border);
      display: flex; align-items: center; justify-content: center;
      font-size: 18px;
      color: var(--text-light);
    }
  </style>
</head>
<body>

<?php require __DIR__ . '/_nav.php'; ?>

<div class="main">
  <header class="topbar">
    <div class="topbar-title">
      <div class="topbar-title-icon">🧃</div>
      Product Management
    </div>
    <div class="topbar-actions">
      <button class="topbar-btn primary" onclick="document.getElementById('addForm').scrollIntoView({behavior:'smooth'})">
        + Add Product
      </button>
    </div>
  </header>

  <div class="page-content">

    <?php if ($msg): ?>
    <div class="notice ok">✓ <?=e($msg)?></div>
    <?php endif; ?>

    <!-- ── Add / Edit Form ── -->
    <div class="panel" id="addForm">
      <div class="panel-header">
        <div class="panel-title">
          <span class="panel-title-dot" style="background:var(--amber);"></span>
          <span id="formTitle">Add New Product</span>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="resetForm()">Reset Form</button>
      </div>
      <div class="panel-body">
        <form method="post" enctype="multipart/form-data" id="productForm">
          <input type="hidden" name="action"     id="p_action" value="add">
          <input type="hidden" name="product_id" id="p_id"     value="">
          <div class="form-grid">
            <div class="form-group">
              <label>SKU</label>
              <input name="sku" id="p_sku" placeholder="e.g. SKU-001" required>
            </div>
            <div class="form-group">
              <label>Product Name</label>
              <input name="name" id="p_name" placeholder="e.g. Yummy Cola 350ml" required>
            </div>
            <div class="form-group">
              <label>Category</label>
              <input name="category" id="p_cat" placeholder="e.g. Soda" required>
            </div>
            <div class="form-group">
              <label>Price (₱)</label>
              <input name="price" id="p_price" type="number" step="0.01" min="0" placeholder="0.00" required>
            </div>
            <div class="form-group">
              <label>Stock Quantity</label>
              <input name="stock_qty" id="p_stock" type="number" min="0" placeholder="0" required>
            </div>
            <div class="form-group" style="justify-content:flex-end;padding-bottom:4px;">
              <label>&nbsp;</label>
              <label style="display:flex;align-items:center;gap:10px;cursor:pointer;text-transform:none;letter-spacing:0;font-size:14px;font-weight:600;color:var(--text);">
                <input type="checkbox" name="is_active" id="p_active" checked
                  style="width:18px;height:18px;accent-color:var(--teal);">
                Active product
              </label>
            </div>
          </div>

          <!-- ── Image Upload ── -->
          <div style="margin-top:18px;">
            <label style="display:block;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--text-muted);margin-bottom:8px;">Product Image</label>
            <div class="img-upload-zone" id="uploadZone">
              <input type="file" name="product_image" id="p_image_input"
                     accept="image/jpeg,image/png,image/webp,image/gif"
                     onchange="previewImage(this)">
              <div id="uploadPrompt">
                <div style="font-size:28px;margin-bottom:6px;">🖼️</div>
                <div style="font-size:13.5px;font-weight:600;color:var(--text);">Click or drag to upload photo</div>
                <div class="img-upload-hint">JPG, PNG, WebP, GIF · Max 5 MB</div>
              </div>
              <div id="previewContainer" style="display:none;">
                <div class="img-preview-wrap">
                  <img id="imgPreview" src="" alt="Preview" class="img-preview" style="width:140px;height:140px;">
                  <button type="button" class="img-preview-remove" onclick="clearImage(event)" title="Remove image">×</button>
                </div>
                <div class="img-upload-hint" id="previewFilename" style="margin-top:6px;"></div>
              </div>
            </div>
            <!-- Hidden field to signal "keep existing" when editing -->
            <input type="hidden" name="keep_image" id="p_keep_image" value="">
          </div>

          <div style="margin-top:20px;display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">💾 Save Product</button>
            <button type="button" class="btn btn-secondary" onclick="resetForm()">Cancel</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ── Products Table ── -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          <span class="panel-title-dot"></span>
          All Products
          <span style="font-family:var(--font-mono);font-size:12px;color:var(--text-muted);font-weight:400;">(<?=count($products)?>)</span>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Image</th>
              <th>ID</th>
              <th>SKU</th>
              <th>Name</th>
              <th>Category</th>
              <th>Price</th>
              <th>Stock</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p): ?>
            <tr>
              <td>
                <?php if (!empty($p['image_path'])): ?>
                  <img src="<?=e(product_img_url($p['image_path']))?>"
                       alt="<?=e($p['name'])?>"
                       class="product-thumb">
                <?php else: ?>
                  <div class="product-thumb-placeholder">🧃</div>
                <?php endif; ?>
              </td>
              <td style="font-family:var(--font-mono);font-size:12px;color:var(--text-muted);"><?=e($p['product_id'])?></td>
              <td><span style="font-family:var(--font-mono);font-size:12.5px;background:#f1f5f9;padding:3px 8px;border-radius:5px;"><?=e($p['sku'])?></span></td>
              <td style="font-weight:600;"><?=e($p['name'])?></td>
              <td><span class="pill pill-default"><?=e($p['category'])?></span></td>
              <td><strong>₱<?=e(money($p['price']))?></strong></td>
              <td>
                <span class="pill <?=$p['stock_qty'] < 20 ? 'pill-danger' : 'pill-success'?>">
                  <?=e($p['stock_qty'])?>
                </span>
              </td>
              <td>
                <span class="pill <?=$p['is_active'] ? 'pill-paid' : 'pill-cancelled'?>">
                  <?=$p['is_active'] ? 'Active' : 'Inactive'?>
                </span>
              </td>
              <td>
                <div style="display:flex;gap:6px;">
                  <button class="btn btn-secondary btn-sm"
                    onclick='editProduct(<?=json_encode($p)?>)'>✏️ Edit</button>
                  <form method="post" style="margin:0;" onsubmit="return confirm('Delete this product?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="product_id" value="<?=e($p['product_id'])?>">
                    <button class="btn btn-danger btn-sm">🗑</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /page-content -->
</div><!-- /main -->

<script>
const BASE_IMG = '/yummy-soda/public/assets/images/';

function previewImage(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const reader = new FileReader();
  reader.onload = (e) => {
    document.getElementById('imgPreview').src = e.target.result;
    document.getElementById('previewFilename').textContent = file.name;
    document.getElementById('uploadPrompt').style.display = 'none';
    document.getElementById('previewContainer').style.display = 'block';
  };
  reader.readAsDataURL(file);
}

function clearImage(e) {
  e.stopPropagation();
  document.getElementById('p_image_input').value = '';
  document.getElementById('imgPreview').src = '';
  document.getElementById('uploadPrompt').style.display = 'block';
  document.getElementById('previewContainer').style.display = 'none';
  document.getElementById('p_keep_image').value = '';
}

// Drag-over styling
const zone = document.getElementById('uploadZone');
zone.addEventListener('dragover', () => zone.classList.add('drag-over'));
zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
zone.addEventListener('drop', () => zone.classList.remove('drag-over'));

function editProduct(p) {
  document.getElementById('p_action').value = 'edit';
  document.getElementById('p_id').value = p.product_id;
  document.getElementById('p_sku').value = p.sku;
  document.getElementById('p_name').value = p.name;
  document.getElementById('p_cat').value = p.category;
  document.getElementById('p_price').value = p.price;
  document.getElementById('p_stock').value = p.stock_qty;
  document.getElementById('p_active').checked = p.is_active == 1;
  document.getElementById('formTitle').textContent = 'Edit Product — ' + p.name;

  // Show existing image if any
  if (p.image_path) {
    document.getElementById('imgPreview').src = BASE_IMG + encodeURIComponent(p.image_path);
    document.getElementById('previewFilename').textContent = p.image_path + ' (current — upload new to replace)';
    document.getElementById('uploadPrompt').style.display = 'none';
    document.getElementById('previewContainer').style.display = 'block';
    document.getElementById('p_keep_image').value = p.image_path;
  } else {
    clearImageSilent();
  }

  document.getElementById('addForm').scrollIntoView({ behavior: 'smooth' });
}

function clearImageSilent() {
  document.getElementById('p_image_input').value = '';
  document.getElementById('imgPreview').src = '';
  document.getElementById('uploadPrompt').style.display = 'block';
  document.getElementById('previewContainer').style.display = 'none';
  document.getElementById('p_keep_image').value = '';
}

function resetForm() {
  document.getElementById('p_action').value = 'add';
  document.getElementById('p_id').value = '';
  document.getElementById('formTitle').textContent = 'Add New Product';
  document.getElementById('productForm').reset();
  clearImageSilent();
}
</script>
</body>
</html>
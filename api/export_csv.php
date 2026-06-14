<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/helpers.php';
require_admin();

$from     = $_GET['from']     ?? '';
$to       = $_GET['to']       ?? '';
$category = trim($_GET['category'] ?? '');
$method   = $_GET['method']   ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');
if ($method !== '' && !in_array($method, ['CASH','GCASH','CARD'], true)) $method = '';

$pdo  = db();
$rows = [];

// ── Try OLAP fact table first (populated after ETL sync) ──────────────────────
$olap_ok = false;
try {
    $pdo->query("SELECT 1 FROM olap_fact_sales LIMIT 1");
    $olap_ok = true;
} catch (Throwable $_) {}

if ($olap_ok) {
    $sql = "
        SELECT dd.full_date        AS date,
               dp.name             AS product,
               dp.category         AS category,
               pm.method           AS payment_method,
               SUM(f.quantity)     AS qty,
               SUM(f.gross_amount) AS revenue
        FROM   olap_fact_sales f
        JOIN   olap_dim_date           dd  ON dd.date_key           = f.date_key
        JOIN   olap_dim_product        dp  ON dp.product_key        = f.product_key
        JOIN   olap_dim_payment_method pm  ON pm.payment_method_key = f.payment_method_key
        WHERE  f.payment_status = 'PAID'
          AND  dd.full_date BETWEEN ? AND ?
    ";
    $params = [$from, $to];
    if ($category !== '') { $sql .= " AND dp.category = ?"; $params[] = $category; }
    if ($method   !== '') { $sql .= " AND pm.method   = ?"; $params[] = $method;   }
    $sql .= " GROUP BY dd.full_date, dp.name, dp.category, pm.method ORDER BY dd.full_date";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Fallback: query raw transactional tables directly ─────────────────────────
if (empty($rows)) {
    $sql = "
        SELECT DATE(o.ordered_at)  AS date,
               p2.name             AS product,
               p2.category         AS category,
               pay.method          AS payment_method,
               SUM(oi.quantity)    AS qty,
               SUM(oi.line_total)  AS revenue
        FROM   orders      o
        JOIN   order_items oi  ON oi.order_id  = o.order_id
        JOIN   products    p2  ON p2.product_id = oi.product_id
        JOIN   payments    pay ON pay.order_id  = o.order_id
        WHERE  pay.status = 'PAID'
          AND  DATE(o.ordered_at) BETWEEN ? AND ?
    ";
    $params = [$from, $to];
    if ($category !== '') { $sql .= " AND p2.category = ?"; $params[] = $category; }
    if ($method   !== '') { $sql .= " AND pay.method  = ?"; $params[] = $method;   }
    $sql .= " GROUP BY DATE(o.ordered_at), p2.name, p2.category, pay.method ORDER BY date";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Stream CSV ────────────────────────────────────────────────────────────────
$filename = 'yummy_soda_report_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// BOM for Excel UTF-8 compatibility
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Yummy Soda — Sales Report']);
fputcsv($out, ['Generated At', date('Y-m-d H:i:s')]);
fputcsv($out, ['Period', "$from to $to"]);
if ($category !== '') fputcsv($out, ['Category', $category]);
if ($method   !== '') fputcsv($out, ['Payment Method', $method]);
fputcsv($out, []);
fputcsv($out, ['Date', 'Product', 'Category', 'Payment Method', 'Qty', 'Revenue (₱)']);

$grand_qty = 0;
$grand_rev = 0.0;
foreach ($rows as $r) {
    fputcsv($out, [
        $r['date'],
        $r['product'],
        $r['category'],
        $r['payment_method'],
        (int)$r['qty'],
        number_format((float)$r['revenue'], 2, '.', ''),
    ]);
    $grand_qty += (int)$r['qty'];
    $grand_rev += (float)$r['revenue'];
}
fputcsv($out, []);
fputcsv($out, ['TOTAL', '', '', '', $grand_qty, number_format($grand_rev, 2, '.', '')]);

fclose($out);
exit;
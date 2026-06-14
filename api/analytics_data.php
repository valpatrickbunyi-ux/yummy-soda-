<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$op  = $_GET['op'] ?? '';

// ── Check whether OLAP tables exist and have data ────────────────────────────
function olap_available(PDO $pdo): bool {
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM olap_fact_sales")->fetchColumn() > 0;
    } catch (Throwable $_) {
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
if ($op === 'rollup_month') {

    if (olap_available($pdo)) {
        $stmt = $pdo->query("
            SELECT dd.year, dd.month, dd.month_name,
                   SUM(f.gross_amount) AS revenue,
                   SUM(f.quantity)     AS qty
            FROM   olap_fact_sales f
            JOIN   olap_dim_date dd ON dd.date_key = f.date_key
            WHERE  f.payment_status = 'PAID'
            GROUP  BY dd.year, dd.month, dd.month_name
            ORDER  BY dd.year, dd.month
        ");
    } else {
        // Fallback: build rollup directly from transactional tables
        $stmt = $pdo->query("
            SELECT YEAR(o.ordered_at)                    AS year,
                   MONTH(o.ordered_at)                   AS month,
                   DATE_FORMAT(o.ordered_at, '%M')       AS month_name,
                   SUM(pay.amount)                       AS revenue,
                   SUM(oi.quantity)                      AS qty
            FROM   orders      o
            JOIN   payments    pay ON pay.order_id = o.order_id AND pay.status = 'PAID'
            JOIN   order_items oi  ON oi.order_id  = o.order_id
            GROUP  BY YEAR(o.ordered_at), MONTH(o.ordered_at), DATE_FORMAT(o.ordered_at, '%M')
            ORDER  BY year, month
        ");
    }

    echo json_encode(array_values($stmt->fetchAll(PDO::FETCH_ASSOC)));
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
if ($op === 'drilldown_day') {

    $ym = $_GET['ym'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) { http_response_code(400); echo json_encode(['error'=>'Invalid ym']); exit; }

    if (olap_available($pdo)) {
        $stmt = $pdo->prepare("
            SELECT dd.full_date              AS full_date,
                   SUM(f.gross_amount)       AS revenue,
                   SUM(f.quantity)           AS qty
            FROM   olap_fact_sales f
            JOIN   olap_dim_date dd ON dd.date_key = f.date_key
            WHERE  f.payment_status = 'PAID'
              AND  DATE_FORMAT(dd.full_date, '%Y-%m') = ?
            GROUP  BY dd.full_date
            ORDER  BY dd.full_date
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT DATE(o.ordered_at)        AS full_date,
                   SUM(pay.amount)           AS revenue,
                   SUM(oi.quantity)          AS qty
            FROM   orders      o
            JOIN   payments    pay ON pay.order_id = o.order_id AND pay.status = 'PAID'
            JOIN   order_items oi  ON oi.order_id  = o.order_id
            WHERE  DATE_FORMAT(o.ordered_at, '%Y-%m') = ?
            GROUP  BY DATE(o.ordered_at)
            ORDER  BY full_date
        ");
    }

    $stmt->execute([$ym]);
    echo json_encode(array_values($stmt->fetchAll(PDO::FETCH_ASSOC)));
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
if ($op === 'slice_method') {

    $method = $_GET['method'] ?? '';
    if (!in_array($method, ['CASH','GCASH','CARD'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid method']);
        exit;
    }

    if (olap_available($pdo)) {
        $stmt = $pdo->prepare("
            SELECT dp.name              AS product,
                   SUM(f.gross_amount)  AS revenue,
                   SUM(f.quantity)      AS qty
            FROM   olap_fact_sales f
            JOIN   olap_dim_product        dp  ON dp.product_key        = f.product_key
            JOIN   olap_dim_payment_method pm  ON pm.payment_method_key = f.payment_method_key
            WHERE  f.payment_status = 'PAID'
              AND  pm.method = ?
            GROUP  BY dp.name
            ORDER  BY revenue DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT p2.name              AS product,
                   SUM(oi.line_total)   AS revenue,
                   SUM(oi.quantity)     AS qty
            FROM   orders      o
            JOIN   payments    pay ON pay.order_id  = o.order_id AND pay.status = 'PAID' AND pay.method = ?
            JOIN   order_items oi  ON oi.order_id  = o.order_id
            JOIN   products    p2  ON p2.product_id = oi.product_id
            GROUP  BY p2.name
            ORDER  BY revenue DESC
        ");
    }

    $stmt->execute([$method]);
    echo json_encode(array_values($stmt->fetchAll(PDO::FETCH_ASSOC)));
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
if ($op === 'dice') {

    $from     = $_GET['from']     ?? '';
    $to       = $_GET['to']       ?? '';
    $category = trim($_GET['category'] ?? '');
    $method   = $_GET['method']   ?? '';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { http_response_code(400); echo json_encode(['error'=>'Invalid from']); exit; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { http_response_code(400); echo json_encode(['error'=>'Invalid to']);   exit; }
    if ($method !== '' && !in_array($method, ['CASH','GCASH','CARD'], true)) { http_response_code(400); echo json_encode(['error'=>'Invalid method']); exit; }

    if (olap_available($pdo)) {
        $sql = "
            SELECT dd.full_date        AS full_date,
                   dp.name             AS product,
                   dp.category         AS category,
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
        $sql .= " GROUP BY dd.full_date, dp.name, dp.category ORDER BY dd.full_date, revenue DESC";
    } else {
        $sql = "
            SELECT DATE(o.ordered_at)  AS full_date,
                   p2.name             AS product,
                   p2.category         AS category,
                   SUM(oi.quantity)    AS qty,
                   SUM(oi.line_total)  AS revenue
            FROM   orders      o
            JOIN   payments    pay ON pay.order_id  = o.order_id AND pay.status = 'PAID'
            JOIN   order_items oi  ON oi.order_id  = o.order_id
            JOIN   products    p2  ON p2.product_id = oi.product_id
            WHERE  DATE(o.ordered_at) BETWEEN ? AND ?
        ";
        $params = [$from, $to];
        if ($category !== '') { $sql .= " AND p2.category = ?"; $params[] = $category; }
        if ($method   !== '') { $sql .= " AND pay.method  = ?"; $params[] = $method;   }
        $sql .= " GROUP BY DATE(o.ordered_at), p2.name, p2.category ORDER BY full_date, revenue DESC";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(array_values($stmt->fetchAll(PDO::FETCH_ASSOC)));
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid op']);
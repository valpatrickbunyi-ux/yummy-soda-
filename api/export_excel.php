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

// ── Try OLAP fact table first ─────────────────────────────────────────────────
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

// ── Fallback: raw transactional tables ───────────────────────────────────────
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

// ── Helpers ───────────────────────────────────────────────────────────────────
function xl(string $v): string {
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
function xl_cell(string $type, string $val, string $style = ''): string {
    $s = $style ? " ss:StyleID=\"$style\"" : '';
    return "<Cell$s><Data ss:Type=\"$type\">" . xl($val) . "</Data></Cell>";
}

// ── Build SpreadsheetML XML ───────────────────────────────────────────────────
// This is the Excel 2003 XML format — opens natively in Excel, LibreOffice,
// and Google Sheets with no warnings and requires zero PHP extensions.

$grand_qty = 0;
$grand_rev = 0.0;

ob_start();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:x="urn:schemas-microsoft-com:office:excel">
  <Styles>
    <Style ss:ID="title">
      <Font ss:Bold="1" ss:Size="14" ss:Color="#0F172A"/>
    </Style>
    <Style ss:ID="meta_key">
      <Font ss:Bold="1" ss:Color="#334155"/>
      <Interior ss:Color="#F1F5F9" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="header">
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <Interior ss:Color="#22C1C3" ss:Pattern="Solid"/>
      <Alignment ss:Horizontal="Center"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#0E7490"/>
      </Borders>
    </Style>
    <Style ss:ID="row_even">
      <Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="row_num">
      <Alignment ss:Horizontal="Right"/>
    </Style>
    <Style ss:ID="row_num_even">
      <Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>
      <Alignment ss:Horizontal="Right"/>
    </Style>
    <Style ss:ID="total_label">
      <Font ss:Bold="1" ss:Color="#0F172A"/>
      <Interior ss:Color="#FEF3C7" ss:Pattern="Solid"/>
      <Borders>
        <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#D97706"/>
      </Borders>
    </Style>
    <Style ss:ID="total_num">
      <Font ss:Bold="1" ss:Color="#0F172A"/>
      <Interior ss:Color="#FEF3C7" ss:Pattern="Solid"/>
      <Alignment ss:Horizontal="Right"/>
      <Borders>
        <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#D97706"/>
      </Borders>
    </Style>
  </Styles>
  <Worksheet ss:Name="Sales Report">
    <Table ss:DefaultColumnWidth="120">
      <Column ss:Width="100"/>
      <Column ss:Width="160"/>
      <Column ss:Width="110"/>
      <Column ss:Width="120"/>
      <Column ss:Width="70"/>
      <Column ss:Width="110"/>

      <!-- Report title -->
      <Row>
        <?= xl_cell('String', 'Yummy Soda — Sales Report', 'title') ?>
      </Row>
      <!-- Meta rows -->
      <Row>
        <?= xl_cell('String', 'Generated At', 'meta_key') ?>
        <?= xl_cell('String', date('Y-m-d H:i:s')) ?>
      </Row>
      <Row>
        <?= xl_cell('String', 'Period', 'meta_key') ?>
        <?= xl_cell('String', "$from  to  $to") ?>
      </Row>
      <?php if ($category !== ''): ?>
      <Row>
        <?= xl_cell('String', 'Category', 'meta_key') ?>
        <?= xl_cell('String', $category) ?>
      </Row>
      <?php endif; ?>
      <?php if ($method !== ''): ?>
      <Row>
        <?= xl_cell('String', 'Payment Method', 'meta_key') ?>
        <?= xl_cell('String', $method) ?>
      </Row>
      <?php endif; ?>

      <!-- Blank spacer -->
      <Row><Cell><Data ss:Type="String"></Data></Cell></Row>

      <!-- Column headers -->
      <Row>
        <?= xl_cell('String', 'Date',           'header') ?>
        <?= xl_cell('String', 'Product',        'header') ?>
        <?= xl_cell('String', 'Category',       'header') ?>
        <?= xl_cell('String', 'Payment Method', 'header') ?>
        <?= xl_cell('String', 'Qty',            'header') ?>
        <?= xl_cell('String', 'Revenue (PHP)',  'header') ?>
      </Row>

      <!-- Data rows -->
      <?php foreach ($rows as $i => $r):
        $grand_qty += (int)$r['qty'];
        $grand_rev += (float)$r['revenue'];
        $even = ($i % 2 === 1);
        $ts   = $even ? 'row_even'     : '';
        $ns   = $even ? 'row_num_even' : 'row_num';
      ?>
      <Row>
        <?= xl_cell('String', (string)$r['date'],           $ts) ?>
        <?= xl_cell('String', (string)$r['product'],        $ts) ?>
        <?= xl_cell('String', (string)$r['category'],       $ts) ?>
        <?= xl_cell('String', (string)$r['payment_method'], $ts) ?>
        <?= xl_cell('Number', (string)(int)$r['qty'],       $ns) ?>
        <?= xl_cell('Number', number_format((float)$r['revenue'], 2, '.', ''), $ns) ?>
      </Row>
      <?php endforeach; ?>

      <!-- Totals row -->
      <Row>
        <?= xl_cell('String', 'TOTAL', 'total_label') ?>
        <Cell ss:StyleID="total_label"><Data ss:Type="String"></Data></Cell>
        <Cell ss:StyleID="total_label"><Data ss:Type="String"></Data></Cell>
        <Cell ss:StyleID="total_label"><Data ss:Type="String"></Data></Cell>
        <?= xl_cell('Number', (string)$grand_qty,                               'total_num') ?>
        <?= xl_cell('Number', number_format($grand_rev, 2, '.', ''), 'total_num') ?>
      </Row>

    </Table>
  </Worksheet>
</Workbook>
<?php
$xml = ob_get_clean();

$filename = 'yummy_soda_report_' . date('Ymd_His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($xml));
header('Pragma: no-cache');
header('Expires: 0');

echo $xml;
exit;
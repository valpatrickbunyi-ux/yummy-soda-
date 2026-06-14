<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/helpers.php';
require_admin();

// ── Same filter params as export_csv / export_excel ──────────────────────────
$from     = $_GET['from']     ?? '';
$to       = $_GET['to']       ?? '';
$category = trim($_GET['category'] ?? '');
$method   = $_GET['method']   ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');
if ($method !== '' && !in_array($method, ['CASH','GCASH','CARD'], true)) $method = '';

$pdo  = db();
$rows = [];

// ── OLAP first, then transactional fallback (identical to CSV/Excel) ──────────
$olap_ok = false;
try { $pdo->query("SELECT 1 FROM olap_fact_sales LIMIT 1"); $olap_ok = true; } catch (Throwable $_) {}

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
          AND  dd.full_date BETWEEN ? AND ?";
    $params = [$from, $to];
    if ($category !== '') { $sql .= " AND dp.category = ?"; $params[] = $category; }
    if ($method   !== '') { $sql .= " AND pm.method   = ?"; $params[] = $method; }
    $sql .= " GROUP BY dd.full_date, dp.name, dp.category, pm.method ORDER BY dd.full_date";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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
          AND  DATE(o.ordered_at) BETWEEN ? AND ?";
    $params = [$from, $to];
    if ($category !== '') { $sql .= " AND p2.category = ?"; $params[] = $category; }
    if ($method   !== '') { $sql .= " AND pay.method  = ?"; $params[] = $method; }
    $sql .= " GROUP BY DATE(o.ordered_at), p2.name, p2.category, pay.method ORDER BY date";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Aggregate summaries (same totals as CSV/Excel) ───────────────────────────
$grand_qty = 0;
$grand_rev = 0.0;
$method_summary  = [];
$product_summary = [];
foreach ($rows as $r) {
    $grand_qty += (int)$r['qty'];
    $grand_rev += (float)$r['revenue'];
    $m = $r['payment_method'];
    $method_summary[$m]['qty']     = ($method_summary[$m]['qty']     ?? 0)   + (int)$r['qty'];
    $method_summary[$m]['revenue'] = ($method_summary[$m]['revenue'] ?? 0.0) + (float)$r['revenue'];
    $p = $r['product'];
    $product_summary[$p]['qty']     = ($product_summary[$p]['qty']     ?? 0)   + (int)$r['qty'];
    $product_summary[$p]['revenue'] = ($product_summary[$p]['revenue'] ?? 0.0) + (float)$r['revenue'];
}
uasort($product_summary, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
$top_products = array_slice($product_summary, 0, 5, true);

// =============================================================================
//  TinyPDF — self-contained, zero-dependency PDF 1.4 writer
//  - Uses real Helvetica glyph-width table for accurate text measurement
//  - Cell() clips text with a PDF clipping path so text NEVER bleeds over borders
//  - Separate fill / border / text operations each wrapped in q...Q save/restore
// =============================================================================
class TinyPDF
{
    const PW = 595.28;   // A4 width  in pt
    const PH = 841.89;   // A4 height in pt
    const ML = 36.0;     // left margin
    const MR = 36.0;     // right margin
    const MT = 28.0;     // top margin (content area starts here)
    const MB = 28.0;     // bottom margin

    // Helvetica character widths (1/1000 of point size), WinAnsi chars 32–126
    private static array $GW = [
        278,278,355,556,556,889,667,222,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
        1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
        222,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
        556,556,333,500,278,556,500,722,500,500,500,334,260,334,584
    ];

    // Helvetica-Bold character widths (1/1000 of point size), WinAnsi chars 32–126
    private static array $GWB = [
        278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,
        975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,
        278,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,
        611,611,389,556,333,611,556,778,556,556,500,389,280,389,584
    ];

    private array  $pages = [];
    private int    $pg    = -1;
    private float  $x     = self::ML;
    private float  $y     = self::MT;
    private float  $sz    = 10.0;
    private bool   $bold  = false;
    private array  $fc    = [255,255,255];
    private array  $tc    = [0,0,0];
    private array  $dc    = [180,180,180];
    private float  $lw    = 0.5;

    private function w(string $s): void { if ($this->pg >= 0) $this->pages[$this->pg] .= $s; }

    // ── Text measurement ──────────────────────────────────────────────────────
    public function textW(string $txt, float $sz, bool $bold = false): float
    {
        $txt = (string)iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $txt);
        $gw  = $bold ? self::$GWB : self::$GW;
        $sum = 0.0;
        for ($i = 0, $n = strlen($txt); $i < $n; $i++) {
            $c = ord($txt[$i]);
            $sum += ($c >= 32 && $c <= 126) ? $gw[$c - 32] : 556;
        }
        return $sum * $sz / 1000.0;
    }

    // Fit text to maxPt width; appends '...' if truncated
    private function fit(string $txt, float $maxPt): string
    {
        if ($this->textW($txt, $this->sz, $this->bold) <= $maxPt) return $txt;
        $ew = $this->textW('...', $this->sz, $this->bold);
        while (mb_strlen($txt) > 0 && $this->textW($txt, $this->sz, $this->bold) + $ew > $maxPt)
            $txt = mb_substr($txt, 0, mb_strlen($txt) - 1);
        return $txt . '...';
    }

    private function esc(string $s): string
    {
        $s = (string)iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
        return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $s);
    }

    // ── Page / cursor ─────────────────────────────────────────────────────────
    public function addPage(): void
    {
        $this->pages[] = '';
        $this->pg = count($this->pages) - 1;
        $this->x  = self::ML;
        $this->y  = self::MT;
        $this->lw = 0.5;
        $this->w(sprintf("%.2f w\n", $this->lw));
    }

    public function getX(): float { return $this->x; }
    public function getY(): float { return $this->y; }
    public function setX(float $x): void { $this->x = $x; }
    public function setY(float $y): void { $this->y = $y; }
    public function setXY(float $x, float $y): void { $this->x = $x; $this->y = $y; }
    public function ln(float $h): void { $this->x = self::ML; $this->y += $h; }
    public function contentW(): float  { return self::PW - self::ML - self::MR; }
    public function needNewPage(float $needed): bool { return ($this->y + $needed) > (self::PH - self::MB); }

    // ── Color / style setters ─────────────────────────────────────────────────
    public function setFont(float $size, bool $bold = false): void { $this->sz = $size; $this->bold = $bold; }
    public function setFillColor(int $r, int $g, int $b): void  { $this->fc = [$r,$g,$b]; }
    public function setTextColor(int $r, int $g, int $b): void  { $this->tc = [$r,$g,$b]; }
    public function setDrawColor(int $r, int $g, int $b): void  { $this->dc = [$r,$g,$b]; }
    public function setLineWidth(float $w): void
    {
        $this->lw = $w;
        $this->w(sprintf("%.2f w\n", $w));
    }

    // ── Low-level drawing ─────────────────────────────────────────────────────
    /** Fill a rectangle with the current fill color (no border). */
    public function fillRect(float $x, float $y, float $w, float $h): void
    {
        [$r,$g,$b] = $this->fc;
        $py = self::PH - $y - $h;
        $this->w(sprintf("q %.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f Q\n",
            $r/255,$g/255,$b/255, $x,$py,$w,$h));
    }

    /** Stroke a rectangle with the current draw color (no fill). */
    public function strokeRect(float $x, float $y, float $w, float $h): void
    {
        [$r,$g,$b] = $this->dc;
        $py = self::PH - $y - $h;
        $this->w(sprintf("q %.3f %.3f %.3f RG %.2f w %.2f %.2f %.2f %.2f re S Q\n",
            $r/255,$g/255,$b/255, $this->lw, $x,$py,$w,$h));
    }

    // ── Cell ─────────────────────────────────────────────────────────────────
    /**
     * Draw a cell at the current cursor position.
     * - Text is clipped to the cell interior and fitted with '...' if needed.
     * - Fill, border and text are independent; each uses a q/Q save-restore.
     *
     * @param float  $w      Cell width in pt (0 = fill to right margin)
     * @param float  $h      Cell height in pt
     * @param string $txt    Cell text
     * @param bool   $fill   Fill background with current fill color
     * @param bool   $border Draw 1-pt border with current draw color
     * @param string $align  'L', 'C', or 'R'
     * @param int    $ln     0 = advance right, 1 = newline
     * @param float  $pad    Horizontal padding inside cell (pt)
     */
    public function cell(
        float  $w,
        float  $h,
        string $txt    = '',
        bool   $fill   = false,
        bool   $border = true,
        string $align  = 'L',
        int    $ln     = 0,
        float  $pad    = 3.5
    ): void {
        $x = $this->x;
        $y = $this->y;
        if ($w <= 0) $w = self::PW - self::MR - $x;

        // 1. Fill background
        if ($fill) {
            [$r,$g,$b] = $this->fc;
            $py = self::PH - $y - $h;
            $this->w(sprintf("q %.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f Q\n",
                $r/255,$g/255,$b/255, $x,$py,$w,$h));
        }

        // 2. Stroke border
        if ($border) {
            [$r,$g,$b] = $this->dc;
            $py = self::PH - $y - $h;
            $this->w(sprintf("q %.3f %.3f %.3f RG %.2f w %.2f %.2f %.2f %.2f re S Q\n",
                $r/255,$g/255,$b/255, $this->lw, $x,$py,$w,$h));
        }

        // 3. Text — clipped + fitted
        if ($txt !== '') {
            $font     = $this->bold ? '/F2' : '/F1';
            $inner    = $w - $pad * 2;               // available text width
            $fitted   = $this->fit($txt, $inner);    // truncate if needed
            $tw       = $this->textW($fitted, $this->sz, $this->bold);

            $tx = match ($align) {
                'C'   => $x + ($w - $tw) / 2,
                'R'   => $x + $w - $tw - $pad,
                default => $x + $pad,
            };
            // Baseline: vertically centred, slight upward nudge (+1 pt) for visual fit
            $py       = self::PH - $y - $h;
            $baseline = $py + ($h - $this->sz) / 2 + 1.0;

            [$tr,$tg,$tb] = $this->tc;
            $esc = $this->esc($fitted);

            $this->w(sprintf(
                "q %.2f %.2f %.2f %.2f re W n " .
                "BT %s %.2f Tf %.3f %.3f %.3f rg %.4f %.4f Td (%s) Tj ET Q\n",
                $x, $py, $w, $h,            // clip rect
                $font, $this->sz,
                $tr/255,$tg/255,$tb/255,
                $tx, $baseline,
                $esc
            ));
        }

        // 4. Advance cursor
        if ($ln === 1) { $this->x = self::ML; $this->y = $y + $h; }
        else           { $this->x = $x + $w; }
    }

    // ── PDF binary output ─────────────────────────────────────────────────────
    public function output(): string
    {
        $buf  = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        $objs = [];

        // F1 = Helvetica, F2 = Helvetica-Bold (both built-in; no embedding)
        $objs[1] = "<<\n/Type /Font\n/Subtype /Type1\n/BaseFont /Helvetica\n/Encoding /WinAnsiEncoding\n>>";
        $objs[2] = "<<\n/Type /Font\n/Subtype /Type1\n/BaseFont /Helvetica-Bold\n/Encoding /WinAnsiEncoding\n>>";

        $fontRes    = "/Font <<\n    /F1 1 0 R\n    /F2 2 0 R\n  >>";
        $pageTreeId = 3;
        $n          = 4;

        $contentIds = [];
        $pageIds    = [];
        foreach ($this->pages as $i => $_) {
            $contentIds[$i] = $n++;
            $pageIds[$i]    = $n++;
        }
        $catalogId = $n;

        $kids = implode(' ', array_map(fn($pid) => "$pid 0 R", $pageIds));
        $objs[$pageTreeId] = "<<\n/Type /Pages\n/Kids [$kids]\n/Count " . count($this->pages) . "\n>>";

        foreach ($this->pages as $i => $stream) {
            $len = strlen($stream);
            $objs[$contentIds[$i]] = "<<\n/Length $len\n>>\nstream\n" . $stream . "\nendstream";
            $objs[$pageIds[$i]]    =
                "<<\n/Type /Page\n/Parent $pageTreeId 0 R\n" .
                "/MediaBox [0 0 " . self::PW . " " . self::PH . "]\n" .
                "/Resources <<\n  $fontRes\n>>\n" .
                "/Contents $contentIds[$i] 0 R\n>>";
        }
        $objs[$catalogId] = "<<\n/Type /Catalog\n/Pages $pageTreeId 0 R\n>>";

        $maxId   = max(array_keys($objs));
        $offsets = [];
        $pos     = strlen($buf);

        for ($oid = 1; $oid <= $maxId; $oid++) {
            if (!isset($objs[$oid])) continue;
            $offsets[$oid] = $pos;
            $chunk = "$oid 0 obj\n" . $objs[$oid] . "\nendobj\n";
            $buf  .= $chunk;
            $pos  += strlen($chunk);
        }

        $xrefPos = $pos;
        $buf .= "xref\n0 " . ($maxId + 1) . "\n";
        $buf .= "0000000000 65535 f \n";
        for ($oid = 1; $oid <= $maxId; $oid++) {
            $buf .= isset($offsets[$oid])
                ? sprintf("%010d 00000 n \n", $offsets[$oid])
                : "0000000000 65535 f \n";
        }
        $buf .= "trailer\n<<\n/Size " . ($maxId + 1) . "\n/Root $catalogId 0 R\n>>\n";
        $buf .= "startxref\n$xrefPos\n%%EOF\n";
        return $buf;
    }
}

// =============================================================================
//  Compose the PDF
// =============================================================================
$pdf = new TinyPDF();
$CW  = $pdf->contentW();    // 595.28 - 36 - 36 = 523.28 pt
$ML  = TinyPDF::ML;

// Palette
$TEAL        = [34,  193, 195];
$DARK        = [15,   23,  42];
$MUTED       = [107, 114, 128];
$WHITE       = [255, 255, 255];
$AMBER       = [253, 187,  45];
$GREEN       = [34,  197,  94];
$PURPLE      = [139,  92, 246];
$SLATE       = [241, 245, 249];
$SLATE_ROW   = [248, 250, 252];
$AMBER_LIGHT = [254, 243, 199];
$BORDER      = [209, 213, 219];
$HEADER_TXT  = [51,   65,  85];

// ── Reusable: page banner (teal strip) ───────────────────────────────────────
$drawBanner = function() use ($pdf, $CW, $ML, $TEAL, $WHITE, $DARK, $MUTED) {
    $pdf->setFillColor(...$TEAL);
    $pdf->fillRect(0, 0, TinyPDF::PW, 20);

    $pdf->setFont(11, true);
    $pdf->setTextColor(...$WHITE);
    $pdf->setXY($ML, 4);
    $pdf->cell(340, 12, 'Yummy Soda  |  Analytics Report', false, false, 'L', 0, 3);

    $pdf->setFont(8, false);
    $pdf->setXY(0, 5);
    $pdf->cell(TinyPDF::PW - $ML, 10, 'Generated: ' . date('Y-m-d H:i:s'), false, false, 'R', 0, 3);

    $pdf->setTextColor(...$DARK);
    $pdf->setXY($ML, 24);
};

// ── Reusable: coloured section heading bar ────────────────────────────────────
$secBar = function(string $title, array $color) use ($pdf, $CW, $ML, $WHITE, $DARK) {
    $pdf->setFillColor(...$color);
    $pdf->fillRect($ML, $pdf->getY(), $CW, 8);
    $pdf->setFont(8, true);
    $pdf->setTextColor(...$WHITE);
    $y = $pdf->getY();
    $pdf->setXY($ML, $y);
    $pdf->cell($CW, 8, '  ' . strtoupper($title), false, false, 'L', 1, 3);
    $pdf->setTextColor(...$DARK);
    $pdf->ln(3);
};

// ── Reusable: table header row ────────────────────────────────────────────────
// $cols = [ [label, align, width_pt], ... ]
$drawHead = function(array $cols, float $rowH = 8) use ($pdf, $ML, $TEAL, $WHITE, $DARK, $BORDER) {
    $pdf->setDrawColor(...$BORDER);
    $pdf->setLineWidth(0.4);
    $pdf->setFillColor(...$TEAL);
    $pdf->setTextColor(...$WHITE);
    $pdf->setFont(8, true);
    $hY = $pdf->getY();
    $cx = $ML;
    foreach ($cols as [$lbl, $align, $cw]) {
        $pdf->fillRect($cx, $hY, $cw, $rowH);
        $pdf->strokeRect($cx, $hY, $cw, $rowH);
        $pdf->setXY($cx, $hY);
        $pdf->cell($cw, $rowH, $lbl, false, false, $align, 0, 4);
        $cx += $cw;
    }
    $pdf->setXY($ML, $hY + $rowH);
    $pdf->setTextColor(...$DARK);
    $pdf->setFont(8, false);
};

// ── Reusable: single data row ─────────────────────────────────────────────────
$drawRow = function(array $cols, array $vals, float $rowH, bool $shade)
    use ($pdf, $ML, $SLATE_ROW, $BORDER, $DARK)
{
    $pdf->setDrawColor(...$BORDER);
    $pdf->setLineWidth(0.3);
    $pdf->setFont(8, false);
    $pdf->setTextColor(...$DARK);
    $rY = $pdf->getY();
    $cx = $ML;
    foreach ($cols as $i => [$lbl, $align, $cw]) {
        if ($shade) {
            $pdf->setFillColor(...$SLATE_ROW);
            $pdf->fillRect($cx, $rY, $cw, $rowH);
        }
        $pdf->strokeRect($cx, $rY, $cw, $rowH);
        $pdf->setXY($cx, $rY);
        $pdf->cell($cw, $rowH, $vals[$i] ?? '', false, false, $align, 0, 4);
        $cx += $cw;
    }
    $pdf->setXY($ML, $rY + $rowH);
};

// =============================================================================
//  PAGE 1 — Summary (same aggregate data as CSV/Excel header section)
// =============================================================================
$pdf->addPage();
$drawBanner();

// ── Filter meta line ─────────────────────────────────────────────────────────
$pdf->setFont(8, false);
$pdf->setTextColor(...$MUTED);
$metaParts = ["Period: $from  to  $to"];
if ($category !== '') $metaParts[] = "Category: $category";
if ($method   !== '') $metaParts[] = "Payment Method: $method";
$pdf->setXY($ML, $pdf->getY());
$pdf->cell($CW, 7, implode('     |     ', $metaParts), false, false, 'L', 1, 0);
$pdf->setTextColor(...$DARK);
$pdf->ln(4);

// ── Grand-total KPI boxes ────────────────────────────────────────────────────
$secBar('Grand Totals', $DARK);

$kpis = [
    ['Total Orders',  number_format(count($rows)),           $GREEN],
    ['Units Sold',    number_format($grand_qty),              $TEAL],
    ['Total Revenue', 'PHP ' . number_format($grand_rev, 2), $AMBER],
];
$boxW = ($CW - 8) / 3;
$boxH = 26;
$bY   = $pdf->getY();

foreach ($kpis as $i => [$lbl, $val, $col]) {
    $bX = $ML + $i * ($boxW + 4);
    $pdf->setFillColor(...$col);
    $pdf->fillRect($bX, $bY, $boxW, $boxH);

    $pdf->setFont(13, true);
    $pdf->setTextColor(...$WHITE);
    $pdf->setXY($bX, $bY + 4);
    $pdf->cell($boxW, 10, $val, false, false, 'C', 0, 4);

    $pdf->setFont(7, false);
    $pdf->setXY($bX, $bY + 15);
    $pdf->cell($boxW, 8, strtoupper($lbl), false, false, 'C', 0, 4);
}
$pdf->setTextColor(...$DARK);
$pdf->setXY($ML, $bY + $boxH + 8);

// ── Revenue by payment method ─────────────────────────────────────────────────
if (!empty($method_summary)) {
    $pdf->ln(2);
    $secBar('Revenue by Payment Method', $PURPLE);

    $mCols = [
        ['Payment Method', 'C', $CW * 0.40],
        ['Units Sold',     'C', $CW * 0.25],
        ['Revenue (PHP)',  'R', $CW * 0.35],
    ];
    $drawHead($mCols, 8);

    $ri = 0;
    foreach ($method_summary as $mName => $mData) {
        $drawRow($mCols, [
            $mName,
            number_format($mData['qty']),
            number_format($mData['revenue'], 2),
        ], 7, $ri % 2 === 1);
        $ri++;
    }
    $pdf->ln(5);
}

// ── Top 5 products ────────────────────────────────────────────────────────────
if (!empty($top_products)) {
    $pdf->ln(2);
    $secBar('Top 5 Products by Revenue', $GREEN);

    $pCols = [
        ['#',             'C',  14],
        ['Product',       'L', round($CW * 0.46)],
        ['Units Sold',    'C', round($CW * 0.22)],
        ['Revenue (PHP)', 'R', $CW - 14 - round($CW * 0.46) - round($CW * 0.22)],
    ];
    $drawHead($pCols, 8);

    $ri = 1;
    foreach ($top_products as $pName => $pData) {
        $drawRow($pCols, [
            (string)$ri,
            $pName,
            number_format($pData['qty']),
            number_format($pData['revenue'], 2),
        ], 7, $ri % 2 === 0);
        $ri++;
    }
    $pdf->ln(5);
}

// =============================================================================
//  PAGE 2+ — Full detail table, identical columns to CSV/Excel
//  Columns: Date | Product | Category | Payment Method | Qty | Revenue (PHP)
// =============================================================================
if (!empty($rows)) {
    $pdf->addPage();
    $drawBanner();
    $pdf->setXY($ML, $pdf->getY());
    $pdf->ln(2);

    // Column widths must sum to $CW exactly
    $c0 = 52;   // Date
    $c1 = 168;  // Product
    $c2 = 80;   // Category
    $c3 = 60;   // Method
    $c4 = 38;   // Qty
    $c5 = $CW - $c0 - $c1 - $c2 - $c3 - $c4;  // Revenue — remainder

    $dCols = [
        ['Date',          'C', $c0],
        ['Product',       'L', $c1],
        ['Category',      'C', $c2],
        ['Method',        'C', $c3],
        ['Qty',           'C', $c4],
        ['Revenue (PHP)', 'R', $c5],
    ];
    $rowH = 7;

    // Closure to redraw the header on a continuation page
    $redraw = function() use ($pdf, $ML, $drawBanner, $secBar, $drawHead, $dCols, $TEAL, $DARK) {
        $drawBanner();
        $pdf->setXY($ML, $pdf->getY());
        $pdf->ln(2);
        $secBar('Detailed Sales Data (continued)', $TEAL);
        $drawHead($dCols, 8);
    };

    $secBar('Detailed Sales Data', $TEAL);
    $drawHead($dCols, 8);

    $ri = 0;
    foreach ($rows as $r) {
        if ($pdf->needNewPage($rowH + 12)) {
            $pdf->addPage();
            $redraw();
        }
        $drawRow($dCols, [
            (string)$r['date'],
            (string)$r['product'],
            (string)$r['category'],
            (string)$r['payment_method'],
            number_format((int)$r['qty']),
            number_format((float)$r['revenue'], 2),
        ], $rowH, $ri % 2 === 1);
        $ri++;
    }

    // ── Totals row ────────────────────────────────────────────────────────────
    if ($pdf->needNewPage(12)) {
        $pdf->addPage();
        $redraw();
    }
    $tY    = $pdf->getY();
    $spanW = $c0 + $c1 + $c2 + $c3;   // span the first four columns

    $pdf->setFillColor(...$AMBER_LIGHT);
    $pdf->setDrawColor(...$BORDER);
    $pdf->setLineWidth(0.5);

    // Fill + border each cell of the totals row
    foreach ([[$ML, $spanW], [$ML + $spanW, $c4], [$ML + $spanW + $c4, $c5]] as [$cx, $cw]) {
        $pdf->fillRect($cx, $tY, $cw, 9);
        $pdf->strokeRect($cx, $tY, $cw, 9);
    }

    $pdf->setFont(8, true);
    $pdf->setTextColor(...$DARK);

    $pdf->setXY($ML, $tY);
    $pdf->cell($spanW, 9, 'TOTAL', false, false, 'R', 0, 4);

    $pdf->setXY($ML + $spanW, $tY);
    $pdf->cell($c4, 9, number_format($grand_qty), false, false, 'C', 0, 4);

    $pdf->setXY($ML + $spanW + $c4, $tY);
    $pdf->cell($c5, 9, number_format($grand_rev, 2), false, false, 'R', 0, 4);
}

// ── Stream PDF to browser ─────────────────────────────────────────────────────
$filename = 'yummy_soda_report_' . date('Ymd_His') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store');
header('Pragma: no-cache');
echo $pdf->output();
exit;
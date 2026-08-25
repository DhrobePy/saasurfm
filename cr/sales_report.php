<?php
require_once '../core/init.php';
require_once '../core/functions/xlsx_writer.php';
require_once '../core/functions/sales_report_pdf.php';
global $db;

// Bare restrict_access() auto-detects 'credit_sales' for every cr/ page (it's
// registered first among the modules sharing this folder) even though the
// Privileges UI files this page under 'production' (see getModuleRegistry()) —
// same class of bug already worked around in credit_dispatch.php. Passing the
// module explicitly makes a 'production' grant (e.g. production manager-demra)
// actually take effect instead of silently falling through to deny.
$allowed_roles = ['Superadmin', 'Accounts', 'admin', 'production manager-srg', 'production manager-demra'];
restrict_access($allowed_roles, 'production', 'sales_report');

$currentUser = getCurrentUser();
$user_id     = $currentUser['id'] ?? null;
$user_role   = $currentUser['role'] ?? '';
$pageTitle   = 'Sales Report';

$is_admin = in_array($user_role, ['Superadmin', 'admin']);

// ── User branch (non-admin sees their factory only) ──────────────────────────
$user_branch = null;
if (!$is_admin && $user_id) {
    $emp = $db->query("SELECT branch_id FROM employees WHERE user_id = ?", [$user_id])->first();
    if ($emp && $emp->branch_id) $user_branch = (int)$emp->branch_id;
}

// ── Date range ───────────────────────────────────────────────────────────────
$date_from = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to   = !empty($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');
$date_from = date('Y-m-d', strtotime($date_from));
$date_to   = date('Y-m-d', strtotime($date_to));
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];

// ── Product drill-down selection (click a Product Breakdown row to see the
//    underlying orders that make up its total for this period) ───────────────
$drill_product_id = isset($_GET['pdid']) ? (int)$_GET['pdid'] : 0;
$drill_variant_id = isset($_GET['pvid']) ? (int)$_GET['pvid'] : 0;

$filter_branch = $is_admin
    ? (isset($_GET['branch_id']) && (int)$_GET['branch_id'] > 0 ? (int)$_GET['branch_id'] : null)
    : $user_branch;

$branch_sql  = $filter_branch ? "AND co.assigned_branch_id = ?" : "";
$base_params = $filter_branch ? [$date_from, $date_to, $filter_branch] : [$date_from, $date_to];

$filter_qs = 'date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to)
    . ($filter_branch ? '&branch_id=' . $filter_branch : '');

// Was COALESCE(delivered_date, updated_at) — updated_at is bumped by ANY later
// edit to the order (a payment collected weeks after delivery, an admin fix,
// etc.), completely unrelated to when the sale actually happened. Confirmed
// live: this misattributed 82 July-ordered orders into August's report, 61 of
// them purely from updated_at drift (~৳26.8M / ~11,800 units of real inflation
// for Aug 2026 alone). shipped_date is a far more stable, workflow-meaningful
// fallback than updated_at, and order_date (immutable) is the final fallback
// for the handful of orders with no shipping record at all.
$sale_date_expr = "COALESCE(DATE(cos.delivered_date), DATE(cos.shipped_date), DATE(co.order_date))";

// ── CSV Export ───────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $db->query("
        SELECT
            $sale_date_expr AS sale_date,
            co.order_number, c.name AS customer_name,
            COALESCE(b.name,'—') AS branch_name,
            p.base_name AS product_name,
            COALESCE(pv.weight_variant,'—') AS weight_variant,
            COALESCE(pv.grade,'—') AS grade,
            coi.quantity, coi.unit_price, coi.line_total
        FROM credit_orders co
        JOIN customers c ON co.customer_id = c.id
        LEFT JOIN branches b ON co.assigned_branch_id = b.id
        JOIN credit_order_items coi ON coi.order_id = co.id
        JOIN products p ON coi.product_id = p.id
        LEFT JOIN product_variants pv ON coi.variant_id = pv.id
        LEFT JOIN credit_order_shipping cos ON co.id = cos.order_id
        WHERE co.status = 'delivered'
          AND $sale_date_expr BETWEEN ? AND ?
          $branch_sql
        ORDER BY sale_date DESC, co.id, coi.id
    ", $base_params)->results();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales_report_' . $date_from . '_to_' . $date_to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Sale Date','Order #','Customer','Factory/Branch','Product','Variant','Grade','Qty','Unit Price (৳)','Line Total (৳)']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r->sale_date, $r->order_number, $r->customer_name, $r->branch_name,
            $r->product_name, $r->weight_variant, $r->grade,
            $r->quantity,
            number_format((float)$r->unit_price, 2, '.', ''),
            number_format((float)$r->line_total,  2, '.', ''),
        ]);
    }
    fclose($out);
    exit();
}

// ── Branches (admin filter dropdown) ─────────────────────────────────────────
$branches = $is_admin
    ? $db->query("SELECT id, name FROM branches WHERE status='active' ORDER BY name")->results()
    : [];

// ── Product summary for period ────────────────────────────────────────────────
// Gross = qty*unit_price (before discount) — Revenue (net) already reflects
// line_total (post-discount) — shown side by side so discount is reconcilable
// per product, not just a hidden subtraction.
$product_summary = $db->query("
    SELECT
        p.id AS product_id, COALESCE(pv.id, 0) AS variant_id,
        p.base_name AS product_name,
        COALESCE(pv.weight_variant,'—') AS weight_variant,
        COALESCE(pv.grade,'') AS grade,
        SUM(coi.quantity) AS total_qty,
        SUM(coi.quantity * coi.unit_price) AS total_gross,
        SUM(coi.discount_amount) AS total_discount,
        SUM(coi.line_total) AS total_revenue
    FROM credit_order_items coi
    JOIN credit_orders co ON coi.order_id = co.id
    JOIN products p ON coi.product_id = p.id
    LEFT JOIN product_variants pv ON coi.variant_id = pv.id
    LEFT JOIN credit_order_shipping cos ON co.id = cos.order_id
    WHERE co.status = 'delivered'
      AND $sale_date_expr BETWEEN ? AND ?
      $branch_sql
    GROUP BY p.id, pv.id
    ORDER BY total_qty DESC
", $base_params)->results();

// ── Customer summary for period ───────────────────────────────────────────────
$customer_summary = $db->query("
    SELECT
        c.id AS customer_id, c.name AS customer_name,
        COUNT(DISTINCT co.id) AS order_count,
        SUM(coi.quantity) AS total_qty,
        SUM(coi.quantity * coi.unit_price) AS total_gross,
        SUM(coi.discount_amount) AS total_discount,
        SUM(coi.line_total) AS total_revenue
    FROM credit_order_items coi
    JOIN credit_orders co ON coi.order_id = co.id
    JOIN customers c ON co.customer_id = c.id
    LEFT JOIN credit_order_shipping cos ON co.id = cos.order_id
    WHERE co.status = 'delivered'
      AND $sale_date_expr BETWEEN ? AND ?
      $branch_sql
    GROUP BY c.id
    ORDER BY total_revenue DESC
", $base_params)->results();
$period_discount = array_sum(array_map(fn($p) => (float)$p->total_discount, $product_summary));

// ── Daily summary ─────────────────────────────────────────────────────────────
$daily_summary = $db->query("
    SELECT
        $sale_date_expr AS sale_date,
        COUNT(DISTINCT co.id) AS order_count,
        SUM(coi.quantity) AS total_qty,
        SUM(coi.line_total) AS total_revenue
    FROM credit_orders co
    JOIN credit_order_items coi ON coi.order_id = co.id
    LEFT JOIN credit_order_shipping cos ON co.id = cos.order_id
    WHERE co.status = 'delivered'
      AND $sale_date_expr BETWEEN ? AND ?
      $branch_sql
    GROUP BY sale_date
    ORDER BY sale_date DESC
", $base_params)->results();

// ── Order detail rows (all items in range) ────────────────────────────────────
$order_rows = $db->query("
    SELECT
        $sale_date_expr AS sale_date,
        co.id AS order_id, co.order_number, co.total_amount,
        c.name AS customer_name,
        COALESCE(b.name,'—') AS branch_name,
        p.id AS product_id, COALESCE(pv.id, 0) AS variant_id,
        p.base_name AS product_name,
        COALESCE(pv.weight_variant,'—') AS weight_variant,
        COALESCE(pv.grade,'') AS grade,
        coi.quantity, coi.unit_price, coi.discount_amount, coi.line_total
    FROM credit_orders co
    JOIN customers c ON co.customer_id = c.id
    LEFT JOIN branches b ON co.assigned_branch_id = b.id
    JOIN credit_order_items coi ON coi.order_id = co.id
    JOIN products p ON coi.product_id = p.id
    LEFT JOIN product_variants pv ON coi.variant_id = pv.id
    LEFT JOIN credit_order_shipping cos ON co.id = cos.order_id
    WHERE co.status = 'delivered'
      AND $sale_date_expr BETWEEN ? AND ?
      $branch_sql
    ORDER BY sale_date DESC, co.id, coi.id
", $base_params)->results();

// Group by date → order_id for Alpine.js rendering
$daily_orders = [];
foreach ($order_rows as $row) {
    $d   = $row->sale_date;
    $oid = $row->order_id;
    if (!isset($daily_orders[$d][$oid])) {
        $daily_orders[$d][$oid] = [
            'number'   => $row->order_number,
            'customer' => $row->customer_name,
            'branch'   => $row->branch_name,
            'total'    => (float)$row->total_amount,
            'items'    => [],
        ];
    }
    $daily_orders[$d][$oid]['items'][] = [
        'product' => $row->product_name,
        'variant' => $row->weight_variant,
        'grade'   => $row->grade,
        'qty'     => (float)$row->quantity,
        'amount'  => (float)$row->line_total,
    ];
}

// ── Product drill-down rows (filtered from the already-fetched order rows —
//    no extra query needed) ─────────────────────────────────────────────────
$drill_rows  = [];
$drill_label = null;
if ($drill_product_id) {
    foreach ($order_rows as $row) {
        if ((int)$row->product_id === $drill_product_id && (int)$row->variant_id === $drill_variant_id) {
            $drill_rows[] = $row;
            if ($drill_label === null) {
                $drill_label = $row->product_name . ($row->weight_variant !== '—' ? ' (' . $row->weight_variant . ')' : '');
            }
        }
    }
}
$drill_qty = array_sum(array_map(fn($r) => (float)$r->quantity, $drill_rows));
$drill_net = array_sum(array_map(fn($r) => (float)$r->line_total, $drill_rows));

// Period totals
$period_orders  = array_sum(array_column((array)$daily_summary, 'order_count'));
$period_qty     = array_sum(array_column((array)$daily_summary, 'total_qty'));
$period_revenue = array_sum(array_column((array)$daily_summary, 'total_revenue'));

$period_label = date('d M Y', strtotime($date_from)) . ' to ' . date('d M Y', strtotime($date_to));
$branch_label = 'All Factories';
if ($filter_branch) {
    foreach ($branches as $br) {
        if ((int)$br->id === (int)$filter_branch) { $branch_label = $br->name; break; }
    }
    if ($branch_label === 'All Factories' && $user_branch) {
        $ub = $db->query("SELECT name FROM branches WHERE id = ?", [$filter_branch])->first();
        if ($ub) $branch_label = $ub->name;
    }
}

// ── XLSX Export (real formatted workbook — bold shaded headers, number
//    formats, borders, frozen header row, bold totals) ────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    $xlsx = new XlsxWriter();

    $sProd = $xlsx->addSheet('Product Breakdown', [26, 12, 10, 12, 15, 13, 15]);
    $xlsx->titleRow($sProd, 'Ujjal Flour Mills — Sales Report (' . $period_label . ', ' . $branch_label . ')');
    $xlsx->blankRow($sProd);
    $xlsx->headerRow($sProd, ['Product', 'Variant', 'Grade', 'Qty (bags)', 'Gross (৳)', 'Discount (৳)', 'Net Revenue (৳)']);
    $pTotQty = $pTotGross = $pTotDisc = $pTotRev = 0;
    foreach ($product_summary as $ps) {
        $xlsx->row($sProd, [$ps->product_name, $ps->weight_variant, $ps->grade, (float)$ps->total_qty, (float)$ps->total_gross, (float)$ps->total_discount, (float)$ps->total_revenue],
            [ST_TEXT, ST_TEXT, ST_TEXT, ST_INT, ST_MONEY, ST_MONEY, ST_MONEY]);
        $pTotQty += (float)$ps->total_qty; $pTotGross += (float)$ps->total_gross;
        $pTotDisc += (float)$ps->total_discount; $pTotRev += (float)$ps->total_revenue;
    }
    $xlsx->totalRow($sProd, ['Total', '', '', $pTotQty, $pTotGross, $pTotDisc, $pTotRev],
        [ST_TEXT, ST_TEXT, ST_TEXT, ST_INT, ST_MONEY, ST_MONEY, ST_MONEY]);

    $sCust = $xlsx->addSheet('Customer Breakdown', [30, 10, 12, 15, 13, 15]);
    $xlsx->titleRow($sCust, 'Ujjal Flour Mills — Sales Report (' . $period_label . ', ' . $branch_label . ')');
    $xlsx->blankRow($sCust);
    $xlsx->headerRow($sCust, ['Customer', 'Orders', 'Qty (bags)', 'Gross (৳)', 'Discount (৳)', 'Net Revenue (৳)']);
    $cTotQty = $cTotGross = $cTotDisc = $cTotRev = 0; $cTotOrders = 0;
    foreach ($customer_summary as $cs) {
        $xlsx->row($sCust, [$cs->customer_name, (int)$cs->order_count, (float)$cs->total_qty, (float)$cs->total_gross, (float)$cs->total_discount, (float)$cs->total_revenue],
            [ST_TEXT, ST_INT, ST_INT, ST_MONEY, ST_MONEY, ST_MONEY]);
        $cTotOrders += (int)$cs->order_count; $cTotQty += (float)$cs->total_qty;
        $cTotGross += (float)$cs->total_gross; $cTotDisc += (float)$cs->total_discount; $cTotRev += (float)$cs->total_revenue;
    }
    $xlsx->totalRow($sCust, ['Total', $cTotOrders, $cTotQty, $cTotGross, $cTotDisc, $cTotRev],
        [ST_TEXT, ST_INT, ST_INT, ST_MONEY, ST_MONEY, ST_MONEY]);

    $sDaily = $xlsx->addSheet('Daily Summary', [16, 12, 14, 16]);
    $xlsx->titleRow($sDaily, 'Ujjal Flour Mills — Sales Report (' . $period_label . ', ' . $branch_label . ')');
    $xlsx->blankRow($sDaily);
    $xlsx->headerRow($sDaily, ['Date', 'Orders', 'Qty (bags)', 'Net Revenue (৳)']);
    foreach ($daily_summary as $ds) {
        $xlsx->row($sDaily, [$ds->sale_date, (int)$ds->order_count, (float)$ds->total_qty, (float)$ds->total_revenue],
            [ST_TEXT, ST_INT, ST_INT, ST_MONEY]);
    }
    $xlsx->totalRow($sDaily, ['Total', $period_orders, $period_qty, $period_revenue],
        [ST_TEXT, ST_INT, ST_INT, ST_MONEY]);

    $sDetail = $xlsx->addSheet('Order Detail', [12, 16, 24, 14, 20, 10, 8, 10, 15]);
    $xlsx->titleRow($sDetail, 'Ujjal Flour Mills — Sales Report (' . $period_label . ', ' . $branch_label . ')');
    $xlsx->blankRow($sDetail);
    $xlsx->headerRow($sDetail, ['Date', 'Order #', 'Customer', 'Factory', 'Product', 'Variant', 'Grade', 'Qty', 'Line Total (৳)']);
    foreach ($order_rows as $r) {
        $xlsx->row($sDetail, [$r->sale_date, $r->order_number, $r->customer_name, $r->branch_name, $r->product_name, $r->weight_variant, $r->grade, (float)$r->quantity, (float)$r->line_total],
            [ST_TEXT, ST_TEXT, ST_TEXT, ST_TEXT, ST_TEXT, ST_TEXT, ST_TEXT, ST_INT, ST_MONEY]);
    }

    $xlsx->output('sales_report_' . $date_from . '_to_' . $date_to . '.xlsx');
    exit();
}

// ── PDF Export (real formatted report — shaded header rows, borders,
//    bold totals, page numbers — not a browser print of the HTML page) ────────
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $pdf = new SalesReportPdf('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->periodLabel = $period_label;
    $pdf->branchLabel = $branch_label;
    $pdf->generatedBy = $currentUser['display_name'] ?? ($currentUser['name'] ?? '');
    $pdf->AddPage();

    $pdf->sectionTitle('Product Breakdown');
    $pRows = []; $pTotQty = $pTotGross = $pTotDisc = $pTotRev = 0;
    foreach ($product_summary as $ps) {
        $pRows[] = [$ps->product_name, $ps->weight_variant, $ps->grade ?: '-', number_format((float)$ps->total_qty), SalesReportPdf::money((float)$ps->total_gross), SalesReportPdf::money((float)$ps->total_discount), SalesReportPdf::money((float)$ps->total_revenue)];
        $pTotQty += (float)$ps->total_qty; $pTotGross += (float)$ps->total_gross;
        $pTotDisc += (float)$ps->total_discount; $pTotRev += (float)$ps->total_revenue;
    }
    $pdf->table(
        ['Product', 'Variant', 'Grade', 'Qty (bags)', 'Gross', 'Discount', 'Net Revenue'],
        [65, 32, 25, 30, 40, 35, 40],
        ['L', 'L', 'L', 'R', 'R', 'R', 'R'],
        $pRows,
        ['Total', '', '', number_format($pTotQty), SalesReportPdf::money($pTotGross), SalesReportPdf::money($pTotDisc), SalesReportPdf::money($pTotRev)]
    );

    $pdf->sectionTitle('Customer Breakdown');
    $cRows = []; $cTotOrders = $cTotQty = $cTotGross = $cTotDisc = $cTotRev = 0;
    foreach ($customer_summary as $cs) {
        $cRows[] = [$cs->customer_name, (string)(int)$cs->order_count, number_format((float)$cs->total_qty), SalesReportPdf::money((float)$cs->total_gross), SalesReportPdf::money((float)$cs->total_discount), SalesReportPdf::money((float)$cs->total_revenue)];
        $cTotOrders += (int)$cs->order_count; $cTotQty += (float)$cs->total_qty;
        $cTotGross += (float)$cs->total_gross; $cTotDisc += (float)$cs->total_discount; $cTotRev += (float)$cs->total_revenue;
    }
    $pdf->table(
        ['Customer', 'Orders', 'Qty (bags)', 'Gross', 'Discount', 'Net Revenue'],
        [80, 30, 35, 42, 40, 40],
        ['L', 'R', 'R', 'R', 'R', 'R'],
        $cRows,
        ['Total', (string)$cTotOrders, number_format($cTotQty), SalesReportPdf::money($cTotGross), SalesReportPdf::money($cTotDisc), SalesReportPdf::money($cTotRev)]
    );

    $pdf->sectionTitle('Daily Summary');
    $dRows = [];
    foreach ($daily_summary as $ds) {
        $dRows[] = [date('d M Y (D)', strtotime($ds->sale_date)), (string)(int)$ds->order_count, number_format((float)$ds->total_qty), SalesReportPdf::money((float)$ds->total_revenue)];
    }
    $pdf->table(
        ['Date', 'Orders', 'Qty (bags)', 'Net Revenue'],
        [50, 40, 45, 50],
        ['L', 'R', 'R', 'R'],
        $dRows,
        ['Total', (string)$period_orders, number_format($period_qty), SalesReportPdf::money($period_revenue)]
    );

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="sales_report_' . $date_from . '_to_' . $date_to . '.pdf"');
    echo $pdf->Output('S');
    exit();
}

require_once '../templates/header.php';
?>

<!-- ── Page Header ─────────────────────────────────────────────────────────── -->
<div class="flex flex-wrap items-start justify-between gap-3 mb-4">
    <div>
        <h1 class="text-lg font-bold text-gray-900 flex items-center gap-2">
            <i class="fas fa-chart-bar text-primary-500 text-base"></i> Sales Report
        </h1>
        <p class="text-xs text-gray-400 mt-0.5">Delivered goods — per day breakdown</p>
    </div>

    <!-- Filter form -->
    <form method="GET" class="flex flex-wrap items-center gap-2">
        <input type="date" name="date_from" value="<?php echo $date_from; ?>"
               class="px-2.5 py-1.5 text-xs border border-gray-200 rounded-lg focus:ring-2 focus:ring-primary-300 outline-none shadow-sm">
        <span class="text-gray-300 text-xs">→</span>
        <input type="date" name="date_to" value="<?php echo $date_to; ?>"
               class="px-2.5 py-1.5 text-xs border border-gray-200 rounded-lg focus:ring-2 focus:ring-primary-300 outline-none shadow-sm">
        <?php if ($is_admin && !empty($branches)): ?>
        <select name="branch_id" class="px-2.5 py-1.5 text-xs border border-gray-200 rounded-lg focus:ring-2 focus:ring-primary-300 outline-none shadow-sm">
            <option value="">All Factories</option>
            <?php foreach ($branches as $br): ?>
            <option value="<?php echo $br->id; ?>" <?php echo $filter_branch == $br->id ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($br->name); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="submit"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition-colors">
            <i class="fas fa-filter text-[10px]"></i> Apply
        </button>
        <a href="sales_report.php?export=csv&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?><?php echo $filter_branch ? '&branch_id=' . $filter_branch : ''; ?>"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors"
           title="Detailed line-item CSV (every order/product row in range)">
            <i class="fas fa-file-csv text-[10px]"></i> Detail CSV
        </a>
        <a href="sales_report.php?export=xlsx&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?><?php echo $filter_branch ? '&branch_id=' . $filter_branch : ''; ?>"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-green-700 text-white rounded-lg hover:bg-green-800 transition-colors"
           title="Formatted Excel workbook — Product / Customer / Daily / Order Detail sheets, with totals">
            <i class="fas fa-file-excel text-[10px]"></i> Excel Report
        </a>
        <a href="sales_report.php?export=pdf&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?><?php echo $filter_branch ? '&branch_id=' . $filter_branch : ''; ?>"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors"
           title="Formatted PDF report — Product / Customer / Daily breakdown tables">
            <i class="fas fa-file-pdf text-[10px]"></i> PDF Report
        </a>
        <!-- Quick ranges -->
        <?php
        $quick = [
            'Today'     => [date('Y-m-d'), date('Y-m-d')],
            'This Week' => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
            'This Month'=> [date('Y-m-01'), date('Y-m-d')],
            'Last Month'=> [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last month'))],
        ];
        foreach ($quick as $label => [$qf, $qt]): ?>
        <a href="sales_report.php?date_from=<?php echo $qf; ?>&date_to=<?php echo $qt; ?><?php echo $filter_branch ? '&branch_id='.$filter_branch : ''; ?>"
           class="px-2.5 py-1.5 text-[10px] font-semibold border border-gray-200 text-gray-500 rounded-lg hover:bg-gray-50 transition-colors whitespace-nowrap
                  <?php echo ($date_from === $qf && $date_to === $qt) ? 'bg-gray-100 border-gray-300 text-gray-700' : ''; ?>">
            <?php echo $label; ?>
        </a>
        <?php endforeach; ?>
    </form>
</div>

<!-- ── Stats Chips ─────────────────────────────────────────────────────────── -->
<div class="flex flex-wrap items-center gap-3 mb-5">
    <div class="flex items-center gap-2 px-3 py-2 bg-white border border-gray-100 rounded-xl shadow-sm">
        <i class="fas fa-calendar-check text-primary-400 text-sm"></i>
        <div>
            <div class="text-[10px] text-gray-400 font-semibold uppercase leading-none">Period</div>
            <div class="text-xs font-bold text-gray-800 mt-0.5">
                <?php echo date('d M', strtotime($date_from)); ?>
                <?php echo $date_from !== $date_to ? ' – ' . date('d M Y', strtotime($date_to)) : ', ' . date('Y'); ?>
            </div>
        </div>
    </div>
    <div class="flex items-center gap-2 px-3 py-2 bg-white border border-gray-100 rounded-xl shadow-sm">
        <i class="fas fa-boxes text-indigo-400 text-sm"></i>
        <div>
            <div class="text-[10px] text-gray-400 font-semibold uppercase leading-none">Orders Delivered</div>
            <div class="text-sm font-bold text-gray-800 mt-0.5"><?php echo number_format($period_orders); ?></div>
        </div>
    </div>
    <div class="flex items-center gap-2 px-3 py-2 bg-white border border-gray-100 rounded-xl shadow-sm">
        <i class="fas fa-weight-hanging text-orange-400 text-sm"></i>
        <div>
            <div class="text-[10px] text-gray-400 font-semibold uppercase leading-none">Total Bags / Units</div>
            <div class="text-sm font-bold text-gray-800 mt-0.5"><?php echo number_format($period_qty); ?></div>
        </div>
    </div>
    <div class="flex items-center gap-2 px-3 py-2 bg-white border border-gray-100 rounded-xl shadow-sm">
        <i class="fas fa-taka-sign text-green-400 text-sm"></i>
        <div>
            <div class="text-[10px] text-gray-400 font-semibold uppercase leading-none">Total Revenue</div>
            <div class="text-sm font-bold text-green-700 mt-0.5">৳<?php echo number_format($period_revenue, 0); ?></div>
        </div>
    </div>
    <?php if ($period_qty > 0): ?>
    <div class="flex items-center gap-2 px-3 py-2 bg-white border border-gray-100 rounded-xl shadow-sm">
        <i class="fas fa-coins text-amber-400 text-sm"></i>
        <div>
            <div class="text-[10px] text-gray-400 font-semibold uppercase leading-none">Avg per Bag</div>
            <div class="text-sm font-bold text-gray-800 mt-0.5">৳<?php echo number_format($period_revenue / $period_qty, 0); ?></div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($period_discount > 0): ?>
    <div class="flex items-center gap-2 px-3 py-2 bg-white border border-gray-100 rounded-xl shadow-sm">
        <i class="fas fa-tags text-rose-400 text-sm"></i>
        <div>
            <div class="text-[10px] text-gray-400 font-semibold uppercase leading-none">Total Discount</div>
            <div class="text-sm font-bold text-rose-600 mt-0.5">৳<?php echo number_format($period_discount, 0); ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Product Period Totals ──────────────────────────────────────────────── -->
<?php if (!empty($product_summary)): ?>
<div class="mb-5">
    <div class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-2">Product Breakdown — <?php echo date('d M', strtotime($date_from)); ?> to <?php echo date('d M Y', strtotime($date_to)); ?></div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <table class="w-full" id="productTable">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100">
                    <th class="px-4 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Product</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Variant</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Grade / Type</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Bags / Units</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Gross (৳)</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Discount (৳)</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Net Revenue (৳)</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Share</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($product_summary as $ps):
                $share = $period_revenue > 0 ? ($ps->total_revenue / $period_revenue * 100) : 0;
                $is_selected = $drill_product_id === (int)$ps->product_id && $drill_variant_id === (int)$ps->variant_id;
                $drill_href = 'sales_report.php?' . $filter_qs . '&pdid=' . (int)$ps->product_id . '&pvid=' . (int)$ps->variant_id . '#product-detail';
            ?>
            <tr class="border-b border-gray-50 last:border-0 hover:bg-primary-50/50 cursor-pointer<?php echo $is_selected ? ' bg-primary-50/70' : ''; ?>"
                onclick="window.location.href='<?php echo htmlspecialchars($drill_href); ?>'" title="View underlying orders">
                <td class="px-4 py-2 text-xs font-bold text-gray-800">
                    <?php echo htmlspecialchars($ps->product_name); ?>
                    <i class="fas fa-chevron-right text-[8px] text-gray-300 ml-1"></i>
                </td>
                <td class="px-3 py-2">
                    <span class="text-[10px] px-2 py-0.5 bg-gray-100 text-gray-600 rounded font-semibold">
                        <?php echo htmlspecialchars($ps->weight_variant); ?>
                    </span>
                </td>
                <td class="px-3 py-2 text-[10px] text-gray-500"><?php echo htmlspecialchars($ps->grade ?: '—'); ?></td>
                <td class="px-3 py-2 text-right">
                    <span class="text-sm font-extrabold text-indigo-700"><?php echo number_format($ps->total_qty, 0); ?></span>
                </td>
                <td class="px-3 py-2 text-right text-xs text-gray-500"><?php echo number_format($ps->total_gross, 0); ?></td>
                <td class="px-3 py-2 text-right text-xs <?php echo $ps->total_discount > 0 ? 'text-rose-600 font-semibold' : 'text-gray-300'; ?>">
                    <?php echo $ps->total_discount > 0 ? '−৳' . number_format($ps->total_discount, 0) : '—'; ?>
                </td>
                <td class="px-3 py-2 text-right">
                    <span class="text-sm font-extrabold text-green-700">৳<?php echo number_format($ps->total_revenue, 0); ?></span>
                </td>
                <td class="px-3 py-2 text-right">
                    <div class="flex items-center justify-end gap-1.5">
                        <div class="w-16 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full bg-primary-400 rounded-full" style="width:<?php echo min(100, $share); ?>%"></div>
                        </div>
                        <span class="text-[10px] text-gray-500 w-8 text-right"><?php echo number_format($share, 1); ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-gray-50 border-t-2 border-gray-200">
                <tr>
                    <td colspan="3" class="px-4 py-2 text-xs font-bold text-gray-700">Total</td>
                    <td class="px-3 py-2 text-right text-sm font-extrabold text-indigo-700"><?php echo number_format($period_qty, 0); ?></td>
                    <td class="px-3 py-2 text-right text-xs text-gray-500"><?php echo number_format(array_sum(array_column((array)$product_summary,'total_gross')), 0); ?></td>
                    <td class="px-3 py-2 text-right text-xs text-rose-600 font-semibold"><?php echo $period_discount > 0 ? '−৳' . number_format($period_discount, 0) : '—'; ?></td>
                    <td class="px-3 py-2 text-right text-sm font-extrabold text-green-700">৳<?php echo number_format($period_revenue, 0); ?></td>
                    <td class="px-3 py-2 text-right text-[10px] text-gray-400">100%</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Product Drill-down: underlying orders for a clicked product row ────── -->
<?php if ($drill_product_id && !empty($drill_rows)): ?>
<div id="product-detail" class="mb-5 border-2 border-primary-200 rounded-xl bg-primary-50/30 p-4">
    <div class="flex items-center justify-between mb-3">
        <div>
            <div class="text-[10px] font-semibold text-primary-500 uppercase tracking-wide">Order-Level Detail</div>
            <div class="text-sm font-bold text-gray-800"><?php echo htmlspecialchars($drill_label); ?> — <?php echo number_format($drill_qty, 0); ?> bags/units, ৳<?php echo number_format($drill_net, 0); ?> net, <?php echo count($drill_rows); ?> line<?php echo count($drill_rows) === 1 ? '' : 's'; ?></div>
        </div>
        <a href="sales_report.php?<?php echo $filter_qs; ?>" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
            <i class="fas fa-times"></i> Clear
        </a>
    </div>
    <div class="bg-white rounded-lg border border-gray-100 overflow-hidden overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100">
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase">Date</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase">Order #</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase">Customer</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase">Factory</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase">Qty</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase">Unit Price</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase">Discount</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase">Line Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($drill_rows as $dr): ?>
                <tr class="hover:bg-gray-50/60">
                    <td class="px-3 py-2 text-gray-600"><?php echo date('d M Y', strtotime($dr->sale_date)); ?></td>
                    <td class="px-3 py-2">
                        <a href="<?php echo url('cr/credit_invoice_print.php?id=' . (int)$dr->order_id); ?>" target="_blank" class="text-primary-600 hover:underline font-mono"><?php echo htmlspecialchars($dr->order_number); ?></a>
                    </td>
                    <td class="px-3 py-2 text-gray-700"><?php echo htmlspecialchars($dr->customer_name); ?></td>
                    <td class="px-3 py-2 text-gray-500"><?php echo htmlspecialchars($dr->branch_name); ?></td>
                    <td class="px-3 py-2 text-right font-semibold text-indigo-700"><?php echo number_format($dr->quantity, 0); ?></td>
                    <td class="px-3 py-2 text-right text-gray-500">৳<?php echo number_format($dr->unit_price, 2); ?></td>
                    <td class="px-3 py-2 text-right <?php echo $dr->discount_amount > 0 ? 'text-rose-600' : 'text-gray-300'; ?>">
                        <?php echo $dr->discount_amount > 0 ? '−৳' . number_format($dr->discount_amount, 2) : '—'; ?>
                    </td>
                    <td class="px-3 py-2 text-right font-bold text-green-700">৳<?php echo number_format($dr->line_total, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php elseif ($drill_product_id): ?>
<div id="product-detail" class="mb-5 border border-gray-200 rounded-xl bg-gray-50 p-4 text-center text-xs text-gray-400">
    No matching order lines found for this selection in the current period/filters.
    <a href="sales_report.php?<?php echo $filter_qs; ?>" class="text-primary-600 hover:underline ml-1">Clear</a>
</div>
<?php endif; ?>

<!-- ── Customer Period Totals ─────────────────────────────────────────────── -->
<?php if (!empty($customer_summary)): ?>
<div class="mb-5">
    <div class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-2">Customer Breakdown — <?php echo date('d M', strtotime($date_from)); ?> to <?php echo date('d M Y', strtotime($date_to)); ?></div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <table class="w-full" id="customerTable">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100">
                    <th class="px-4 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Customer</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Orders</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Bags / Units</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Gross (৳)</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Discount (৳)</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Net Revenue (৳)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($customer_summary as $cs): ?>
            <tr class="border-b border-gray-50 last:border-0 hover:bg-gray-50/40">
                <td class="px-4 py-2 text-xs font-bold text-gray-800"><?php echo htmlspecialchars($cs->customer_name); ?></td>
                <td class="px-3 py-2 text-right text-xs text-gray-500"><?php echo (int)$cs->order_count; ?></td>
                <td class="px-3 py-2 text-right"><span class="text-sm font-extrabold text-indigo-700"><?php echo number_format($cs->total_qty, 0); ?></span></td>
                <td class="px-3 py-2 text-right text-xs text-gray-500"><?php echo number_format($cs->total_gross, 0); ?></td>
                <td class="px-3 py-2 text-right text-xs <?php echo $cs->total_discount > 0 ? 'text-rose-600 font-semibold' : 'text-gray-300'; ?>">
                    <?php echo $cs->total_discount > 0 ? '−৳' . number_format($cs->total_discount, 0) : '—'; ?>
                </td>
                <td class="px-3 py-2 text-right"><span class="text-sm font-extrabold text-green-700">৳<?php echo number_format($cs->total_revenue, 0); ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-gray-50 border-t-2 border-gray-200">
                <tr>
                    <td class="px-4 py-2 text-xs font-bold text-gray-700">Total</td>
                    <td class="px-3 py-2 text-right text-xs font-bold text-gray-700"><?php echo (int)$period_orders; ?></td>
                    <td class="px-3 py-2 text-right text-sm font-extrabold text-indigo-700"><?php echo number_format($period_qty, 0); ?></td>
                    <td class="px-3 py-2 text-right text-xs text-gray-500"><?php echo number_format(array_sum(array_column((array)$customer_summary,'total_gross')), 0); ?></td>
                    <td class="px-3 py-2 text-right text-xs text-rose-600 font-semibold"><?php echo $period_discount > 0 ? '−৳' . number_format($period_discount, 0) : '—'; ?></td>
                    <td class="px-3 py-2 text-right text-sm font-extrabold text-green-700">৳<?php echo number_format($period_revenue, 0); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Daily Breakdown Table ──────────────────────────────────────────────── -->
<div class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-2">Day-by-Day Breakdown</div>

<?php if (empty($daily_summary)): ?>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-10 text-center">
    <i class="fas fa-chart-bar text-gray-200 text-4xl mb-3 block"></i>
    <p class="text-sm text-gray-500 mb-1">No delivered orders in this date range.</p>
    <p class="text-xs text-gray-400">Try widening the date range or changing the factory filter.</p>
</div>
<?php else: ?>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <table class="w-full" id="daily-table">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-100">
                <th class="px-4 py-2.5 text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide w-32">Date</th>
                <th class="px-3 py-2.5 text-center text-[10px] font-semibold text-gray-400 uppercase tracking-wide w-20">Orders</th>
                <th class="px-3 py-2.5 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Bags / Units</th>
                <th class="px-4 py-2.5 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Revenue (৳)</th>
                <th class="px-3 py-2.5 w-10"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($daily_summary as $di => $day):
            $day_orders = $daily_orders[$day->sale_date] ?? [];
            $has_detail = !empty($day_orders);
            $row_id = 'day-' . str_replace('-', '', $day->sale_date);
            $is_today = ($day->sale_date === date('Y-m-d'));
            $dow = date('D', strtotime($day->sale_date));
        ?>
        <!-- Day summary row -->
        <tr class="border-b border-gray-50 hover:bg-blue-50/20 transition-colors cursor-pointer
                   <?php echo $is_today ? 'bg-amber-50/40' : ($di % 2 === 0 ? '' : 'bg-gray-50/30'); ?>"
            onclick="toggleDay('<?php echo $row_id; ?>')" title="Click to expand orders">
            <td class="px-4 py-2.5 whitespace-nowrap">
                <div class="flex items-center gap-2">
                    <span class="text-[9px] font-bold text-gray-400 uppercase w-7"><?php echo $dow; ?></span>
                    <div>
                        <div class="text-xs font-bold text-gray-800"><?php echo date('d M Y', strtotime($day->sale_date)); ?></div>
                        <?php if ($is_today): ?>
                        <div class="text-[9px] text-amber-600 font-semibold">Today</div>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <td class="px-3 py-2.5 text-center">
                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-bold">
                    <?php echo $day->order_count; ?>
                </span>
            </td>
            <td class="px-3 py-2.5 text-right">
                <span class="text-sm font-extrabold text-orange-600"><?php echo number_format($day->total_qty, 0); ?></span>
                <span class="text-[9px] text-gray-400 ml-0.5">bags</span>
            </td>
            <td class="px-4 py-2.5 text-right">
                <span class="text-sm font-extrabold text-green-700">৳<?php echo number_format($day->total_revenue, 0); ?></span>
            </td>
            <td class="px-3 py-2.5 text-center">
                <?php if ($has_detail): ?>
                <i id="<?php echo $row_id; ?>-icon" class="fas fa-chevron-down text-gray-300 text-[10px] transition-transform duration-200"></i>
                <?php endif; ?>
            </td>
        </tr>

        <!-- Detail rows (hidden by default) -->
        <?php if ($has_detail): ?>
        <tr id="<?php echo $row_id; ?>" class="hidden">
            <td colspan="5" class="p-0">
                <div class="bg-gray-50 border-y border-gray-100">
                    <?php foreach ($day_orders as $oid => $ord): ?>
                    <div class="border-b border-gray-100 last:border-0">
                        <!-- Order header -->
                        <div class="flex items-center gap-2 px-6 py-1.5 bg-gray-100/60">
                            <i class="fas fa-file-alt text-gray-300 text-[9px]"></i>
                            <span class="text-[10px] font-bold text-gray-700"><?php echo htmlspecialchars($ord['number']); ?></span>
                            <span class="text-gray-300 text-[10px]">·</span>
                            <span class="text-[10px] text-gray-600"><?php echo htmlspecialchars($ord['customer']); ?></span>
                            <?php if ($is_admin && $ord['branch'] !== '—'): ?>
                            <span class="text-gray-300 text-[10px]">·</span>
                            <span class="text-[10px] text-gray-400"><?php echo htmlspecialchars($ord['branch']); ?></span>
                            <?php endif; ?>
                            <span class="ml-auto text-[10px] font-bold text-green-700">৳<?php echo number_format($ord['total'], 0); ?></span>
                            <a href="credit_order_view.php?id=<?php echo $oid; ?>" class="text-[9px] text-primary-500 hover:underline font-semibold" onclick="event.stopPropagation()">View</a>
                        </div>
                        <!-- Items -->
                        <div class="px-6 py-1">
                        <?php foreach ($ord['items'] as $item): ?>
                        <div class="flex items-center gap-2 py-0.5 text-[10px]">
                            <span class="text-gray-300 w-3 text-center">›</span>
                            <span class="font-medium text-gray-700"><?php echo htmlspecialchars($item['product']); ?></span>
                            <span class="px-1.5 py-0 bg-gray-200 text-gray-600 rounded text-[9px] font-semibold"><?php echo htmlspecialchars($item['variant']); ?></span>
                            <?php if ($item['grade']): ?>
                            <span class="text-gray-400"><?php echo htmlspecialchars($item['grade']); ?></span>
                            <?php endif; ?>
                            <span class="ml-auto font-bold text-orange-600"><?php echo number_format($item['qty'], 0); ?> bags</span>
                            <span class="text-gray-400 w-24 text-right">৳<?php echo number_format($item['amount'], 0); ?></span>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
        </tbody>

        <!-- Totals footer -->
        <tfoot class="bg-gray-50 border-t-2 border-gray-200">
            <tr>
                <td class="px-4 py-2.5 text-xs font-bold text-gray-700">
                    <?php echo count($daily_summary); ?> day<?php echo count($daily_summary) !== 1 ? 's' : ''; ?>
                </td>
                <td class="px-3 py-2.5 text-center">
                    <span class="text-xs font-bold text-indigo-700"><?php echo number_format($period_orders); ?></span>
                </td>
                <td class="px-3 py-2.5 text-right">
                    <span class="text-sm font-extrabold text-orange-600"><?php echo number_format($period_qty, 0); ?></span>
                    <span class="text-[9px] text-gray-400 ml-0.5">bags</span>
                </td>
                <td class="px-4 py-2.5 text-right">
                    <span class="text-sm font-extrabold text-green-700">৳<?php echo number_format($period_revenue, 0); ?></span>
                </td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>

<script>
function toggleDay(id) {
    const row  = document.getElementById(id);
    const icon = document.getElementById(id + '-icon');
    if (!row) return;
    const hidden = row.classList.toggle('hidden');
    if (icon) {
        icon.style.transform = hidden ? '' : 'rotate(180deg)';
        icon.classList.toggle('text-primary-500', !hidden);
        icon.classList.toggle('text-gray-300', hidden);
    }
}
</script>

<?php require_once '../templates/footer.php'; ?>
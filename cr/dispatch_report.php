<?php
require_once '../core/init.php';
global $db;

// See sales_report.php for why the module must be passed explicitly here —
// bare restrict_access() would auto-resolve to 'credit_sales' instead of
// 'production' for every cr/ page, silently ignoring a 'production' grant.
$allowed_roles = [
    'Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra',
    'dispatch-srg', 'dispatch-demra', 'dispatchpos-srg', 'dispatchpos-demra',
    'production manager-srg', 'production manager-demra',
];
restrict_access($allowed_roles, 'production', 'dispatch_report');

$currentUser = getCurrentUser();
$user_id     = $currentUser['id'] ?? null;
$user_role   = $currentUser['role'] ?? '';
$pageTitle   = 'Dispatch Report';

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
//    underlying dispatched orders for that product in this period) ───────────
$drill_product_id = isset($_GET['pdid']) ? (int)$_GET['pdid'] : 0;
$drill_variant_id = isset($_GET['pvid']) ? (int)$_GET['pvid'] : 0;

$filter_branch = $is_admin
    ? (isset($_GET['branch_id']) && (int)$_GET['branch_id'] > 0 ? (int)$_GET['branch_id'] : null)
    : $user_branch;

$branch_sql  = $filter_branch ? "AND co.assigned_branch_id = ?" : "";
$base_params = $filter_branch ? [$date_from, $date_to, $filter_branch] : [$date_from, $date_to];

$filter_qs = 'date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to)
    . ($filter_branch ? '&branch_id=' . $filter_branch : '');

// "Dispatched from factory" = actually shipped, dated by the real shipping
// record — not delivery date (that's what sales_report.php tracks) and not
// order_date (that's when it was ordered, not shipped).
$dispatch_date_expr = "DATE(cos.shipped_date)";
$dispatch_where = "co.status IN ('shipped','goods_on_board','delivered') AND cos.shipped_date IS NOT NULL
      AND $dispatch_date_expr BETWEEN ? AND ?";

// ── CSV Export ───────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $db->query("
        SELECT
            $dispatch_date_expr AS ship_date,
            co.order_number, c.name AS customer_name,
            COALESCE(b.name,'—') AS branch_name,
            cos.truck_number, cos.driver_name,
            p.base_name AS product_name,
            COALESCE(pv.weight_variant,'—') AS weight_variant,
            COALESCE(pv.grade,'—') AS grade,
            coi.quantity, coi.line_total
        FROM credit_orders co
        JOIN customers c ON co.customer_id = c.id
        LEFT JOIN branches b ON co.assigned_branch_id = b.id
        JOIN credit_order_items coi ON coi.order_id = co.id
        JOIN products p ON coi.product_id = p.id
        LEFT JOIN product_variants pv ON coi.variant_id = pv.id
        JOIN credit_order_shipping cos ON co.id = cos.order_id
        WHERE $dispatch_where
          $branch_sql
        ORDER BY ship_date DESC, co.id, coi.id
    ", $base_params)->results();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="dispatch_report_' . $date_from . '_to_' . $date_to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Ship Date','Order #','Customer','Factory','Truck #','Driver','Product','Variant','Grade','Qty','Line Total (৳)']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r->ship_date, $r->order_number, $r->customer_name, $r->branch_name,
            $r->truck_number ?? '', $r->driver_name ?? '',
            $r->product_name, $r->weight_variant, $r->grade,
            $r->quantity,
            number_format((float)$r->line_total, 2, '.', ''),
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
$product_summary = $db->query("
    SELECT
        p.id AS product_id, COALESCE(pv.id, 0) AS variant_id,
        p.base_name AS product_name,
        COALESCE(pv.weight_variant,'—') AS weight_variant,
        COALESCE(pv.grade,'') AS grade,
        SUM(coi.quantity) AS total_qty,
        SUM(coi.line_total) AS total_revenue,
        COUNT(DISTINCT co.id) AS order_count
    FROM credit_order_items coi
    JOIN credit_orders co ON coi.order_id = co.id
    JOIN products p ON coi.product_id = p.id
    LEFT JOIN product_variants pv ON coi.variant_id = pv.id
    JOIN credit_order_shipping cos ON co.id = cos.order_id
    WHERE $dispatch_where
      $branch_sql
    GROUP BY p.id, pv.id
    ORDER BY total_qty DESC
", $base_params)->results();

// ── Factory (branch) summary ──────────────────────────────────────────────────
$branch_summary = $db->query("
    SELECT
        COALESCE(b.name,'—') AS branch_name,
        COUNT(DISTINCT co.id) AS order_count,
        SUM(coi.quantity) AS total_qty
    FROM credit_order_items coi
    JOIN credit_orders co ON coi.order_id = co.id
    LEFT JOIN branches b ON co.assigned_branch_id = b.id
    JOIN credit_order_shipping cos ON co.id = cos.order_id
    WHERE $dispatch_where
      $branch_sql
    GROUP BY b.id
    ORDER BY total_qty DESC
", $base_params)->results();

// ── Order-level rows (all dispatched orders + their items in range) ──────────
$order_rows = $db->query("
    SELECT
        $dispatch_date_expr AS ship_date,
        co.id AS order_id, co.order_number, co.status, co.total_amount,
        c.name AS customer_name,
        COALESCE(b.name,'—') AS branch_name,
        cos.truck_number, cos.driver_name,
        p.id AS product_id, COALESCE(pv.id, 0) AS variant_id,
        p.base_name AS product_name,
        COALESCE(pv.weight_variant,'—') AS weight_variant,
        COALESCE(pv.grade,'') AS grade,
        coi.quantity, coi.line_total
    FROM credit_orders co
    JOIN customers c ON co.customer_id = c.id
    LEFT JOIN branches b ON co.assigned_branch_id = b.id
    JOIN credit_order_items coi ON coi.order_id = co.id
    JOIN products p ON coi.product_id = p.id
    LEFT JOIN product_variants pv ON coi.variant_id = pv.id
    JOIN credit_order_shipping cos ON co.id = cos.order_id
    WHERE $dispatch_where
      $branch_sql
    ORDER BY ship_date DESC, co.id, coi.id
", $base_params)->results();

// Group by order for the order-level table
$orders_grouped = [];
foreach ($order_rows as $row) {
    $oid = $row->order_id;
    if (!isset($orders_grouped[$oid])) {
        $orders_grouped[$oid] = [
            'order_number' => $row->order_number,
            'ship_date'    => $row->ship_date,
            'status'       => $row->status,
            'customer'     => $row->customer_name,
            'branch'       => $row->branch_name,
            'truck'        => $row->truck_number,
            'driver'       => $row->driver_name,
            'total_amount' => (float)$row->total_amount,
            'total_qty'    => 0,
            'items'        => [],
        ];
    }
    $orders_grouped[$oid]['total_qty'] += (float)$row->quantity;
    $orders_grouped[$oid]['items'][] = $row->product_name . ' (' . $row->weight_variant . ') ×' . number_format($row->quantity, 0);
}

// ── Product drill-down rows (filtered from the already-fetched order rows) ───
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

// Period totals
$period_orders = count($orders_grouped);
$period_qty    = array_sum(array_column((array)$product_summary, 'total_qty'));
$period_label  = date('d M Y', strtotime($date_from)) . ' to ' . date('d M Y', strtotime($date_to));
$branch_label  = 'All Factories';
if ($filter_branch) {
    foreach ($branches as $br) {
        if ((int)$br->id === (int)$filter_branch) { $branch_label = $br->name; break; }
    }
    if ($branch_label === 'All Factories' && $user_branch) {
        $ub = $db->query("SELECT name FROM branches WHERE id = ?", [$filter_branch])->first();
        if ($ub) $branch_label = $ub->name;
    }
}

require_once '../templates/header.php';
?>

<!-- ── Page Header ─────────────────────────────────────────────────────────── -->
<div class="flex flex-wrap items-start justify-between gap-3 mb-4">
    <div>
        <h1 class="text-lg font-bold text-gray-900 flex items-center gap-2">
            <i class="fas fa-truck-loading text-primary-500 text-base"></i> Dispatch Report
        </h1>
        <p class="text-xs text-gray-400 mt-0.5">Goods shipped from factory — per product / per order breakdown</p>
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
        <a href="dispatch_report.php?export=csv&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?><?php echo $filter_branch ? '&branch_id=' . $filter_branch : ''; ?>"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors"
           title="Detailed line-item CSV (every order/product row in range)">
            <i class="fas fa-file-csv text-[10px]"></i> Export CSV
        </a>
        <!-- Quick ranges -->
        <?php
        $quick = [
            'Today'      => [date('Y-m-d'), date('Y-m-d')],
            'Yesterday'  => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
            'This Week'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
            'This Month' => [date('Y-m-01'), date('Y-m-d')],
            'Last Month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last month'))],
        ];
        foreach ($quick as $label => [$qf, $qt]): ?>
        <a href="dispatch_report.php?date_from=<?php echo $qf; ?>&date_to=<?php echo $qt; ?><?php echo $filter_branch ? '&branch_id='.$filter_branch : ''; ?>"
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
        <i class="fas fa-industry text-amber-400 text-sm"></i>
        <div>
            <div class="text-[10px] text-gray-400 font-semibold uppercase leading-none">Factory</div>
            <div class="text-xs font-bold text-gray-800 mt-0.5"><?php echo htmlspecialchars($branch_label); ?></div>
        </div>
    </div>
    <div class="flex items-center gap-2 px-3 py-2 bg-white border border-gray-100 rounded-xl shadow-sm">
        <i class="fas fa-shipping-fast text-indigo-400 text-sm"></i>
        <div>
            <div class="text-[10px] text-gray-400 font-semibold uppercase leading-none">Orders Shipped</div>
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
</div>

<!-- ── Factory Breakdown (admin, multi-factory view only) ─────────────────── -->
<?php if ($is_admin && !$filter_branch && count($branch_summary) > 1): ?>
<div class="mb-5">
    <div class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-2">Factory Breakdown</div>
    <div class="flex flex-wrap gap-2">
        <?php foreach ($branch_summary as $bs): ?>
        <div class="flex items-center gap-2 px-3 py-2 bg-white border border-gray-100 rounded-xl shadow-sm">
            <i class="fas fa-industry text-amber-400 text-xs"></i>
            <div>
                <div class="text-xs font-bold text-gray-800"><?php echo htmlspecialchars($bs->branch_name); ?></div>
                <div class="text-[10px] text-gray-400"><?php echo number_format($bs->order_count); ?> orders · <?php echo number_format($bs->total_qty, 0); ?> units</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Product Breakdown ───────────────────────────────────────────────────── -->
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
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Orders</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Bags / Units Shipped</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Share</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($product_summary as $ps):
                $share = $period_qty > 0 ? ($ps->total_qty / $period_qty * 100) : 0;
                $is_selected = $drill_product_id === (int)$ps->product_id && $drill_variant_id === (int)$ps->variant_id;
                $drill_href = 'dispatch_report.php?' . $filter_qs . '&pdid=' . (int)$ps->product_id . '&pvid=' . (int)$ps->variant_id . '#product-detail';
            ?>
            <tr class="border-b border-gray-50 last:border-0 hover:bg-primary-50/50 cursor-pointer<?php echo $is_selected ? ' bg-primary-50/70' : ''; ?>"
                onclick="window.location.href='<?php echo htmlspecialchars($drill_href); ?>'" title="View underlying dispatched orders">
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
                <td class="px-3 py-2 text-right text-xs text-gray-500"><?php echo number_format($ps->order_count); ?></td>
                <td class="px-3 py-2 text-right">
                    <span class="text-sm font-extrabold text-indigo-700"><?php echo number_format($ps->total_qty, 0); ?></span>
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
                    <td colspan="4" class="px-4 py-2 text-xs font-bold text-gray-700">Total</td>
                    <td class="px-3 py-2 text-right text-sm font-extrabold text-indigo-700"><?php echo number_format($period_qty, 0); ?></td>
                    <td class="px-3 py-2 text-right text-[10px] text-gray-400">100%</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Product Drill-down: underlying dispatched orders for a clicked product ── -->
<?php if ($drill_product_id && !empty($drill_rows)): ?>
<div id="product-detail" class="mb-5 border-2 border-primary-200 rounded-xl bg-primary-50/30 p-4">
    <div class="flex items-center justify-between mb-3">
        <div>
            <div class="text-[10px] font-semibold text-primary-500 uppercase tracking-wide">Order-Level Detail</div>
            <div class="text-sm font-bold text-gray-800"><?php echo htmlspecialchars($drill_label); ?> — <?php echo number_format($drill_qty, 0); ?> bags/units, <?php echo count($drill_rows); ?> line<?php echo count($drill_rows) === 1 ? '' : 's'; ?></div>
        </div>
        <a href="dispatch_report.php?<?php echo $filter_qs; ?>" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
            <i class="fas fa-times"></i> Clear
        </a>
    </div>
    <div class="bg-white rounded-lg border border-gray-100 overflow-hidden overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100">
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase">Ship Date</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase">Order #</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase">Customer</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase">Factory</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase">Qty</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($drill_rows as $dr): ?>
                <tr class="hover:bg-gray-50/60">
                    <td class="px-3 py-2 text-gray-600"><?php echo date('d M Y', strtotime($dr->ship_date)); ?></td>
                    <td class="px-3 py-2">
                        <a href="<?php echo url('cr/credit_invoice_print.php?id=' . (int)$dr->order_id); ?>" target="_blank" class="text-primary-600 hover:underline font-mono"><?php echo htmlspecialchars($dr->order_number); ?></a>
                    </td>
                    <td class="px-3 py-2 text-gray-700"><?php echo htmlspecialchars($dr->customer_name); ?></td>
                    <td class="px-3 py-2 text-gray-500"><?php echo htmlspecialchars($dr->branch_name); ?></td>
                    <td class="px-3 py-2 text-right font-semibold text-indigo-700"><?php echo number_format($dr->quantity, 0); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php elseif ($drill_product_id): ?>
<div id="product-detail" class="mb-5 border border-gray-200 rounded-xl bg-gray-50 p-4 text-center text-xs text-gray-400">
    No matching dispatched lines found for this selection in the current period/filters.
    <a href="dispatch_report.php?<?php echo $filter_qs; ?>" class="text-primary-600 hover:underline ml-1">Clear</a>
</div>
<?php endif; ?>

<!-- ── Dispatched Orders ────────────────────────────────────────────────────── -->
<?php if (!empty($orders_grouped)): ?>
<div class="mb-5">
    <div class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-2">Dispatched Orders — <?php echo date('d M', strtotime($date_from)); ?> to <?php echo date('d M Y', strtotime($date_to)); ?></div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100">
                    <th class="px-4 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Ship Date</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Order #</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Customer</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Factory</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Truck / Driver</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Items</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Qty</th>
                    <th class="px-3 py-2 text-center text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
            <?php
            $status_labels = ['shipped' => 'Shipped', 'goods_on_board' => 'On Board', 'delivered' => 'Delivered'];
            $status_colors = ['shipped' => 'bg-teal-100 text-teal-700', 'goods_on_board' => 'bg-indigo-100 text-indigo-700', 'delivered' => 'bg-green-100 text-green-700'];
            foreach ($orders_grouped as $oid => $og): ?>
                <tr class="hover:bg-gray-50/60">
                    <td class="px-4 py-2 text-xs text-gray-600 whitespace-nowrap"><?php echo date('d M Y', strtotime($og['ship_date'])); ?></td>
                    <td class="px-3 py-2 text-xs">
                        <a href="<?php echo url('cr/credit_invoice_print.php?id=' . (int)$oid); ?>" target="_blank" class="text-primary-600 hover:underline font-mono font-semibold">
                            <?php echo htmlspecialchars($og['order_number']); ?>
                        </a>
                    </td>
                    <td class="px-3 py-2 text-xs text-gray-700"><?php echo htmlspecialchars($og['customer']); ?></td>
                    <td class="px-3 py-2 text-xs text-gray-500"><?php echo htmlspecialchars($og['branch']); ?></td>
                    <td class="px-3 py-2 text-[10px] text-gray-400">
                        <?php echo htmlspecialchars($og['truck'] ?: '—'); ?><?php echo $og['driver'] ? ' · ' . htmlspecialchars($og['driver']) : ''; ?>
                    </td>
                    <td class="px-3 py-2 text-[10px] text-gray-500 max-w-xs truncate" title="<?php echo htmlspecialchars(implode(', ', $og['items'])); ?>">
                        <?php echo htmlspecialchars(implode(', ', array_slice($og['items'], 0, 2))); ?><?php echo count($og['items']) > 2 ? ' +' . (count($og['items']) - 2) . ' more' : ''; ?>
                    </td>
                    <td class="px-3 py-2 text-right text-sm font-extrabold text-indigo-700"><?php echo number_format($og['total_qty'], 0); ?></td>
                    <td class="px-3 py-2 text-center">
                        <span class="text-[10px] px-2 py-0.5 rounded-full font-semibold <?php echo $status_colors[$og['status']] ?? 'bg-gray-100 text-gray-600'; ?>">
                            <?php echo $status_labels[$og['status']] ?? ucfirst($og['status']); ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-8 text-center text-gray-400 text-sm">
    <i class="fas fa-inbox text-2xl mb-2 block"></i>
    No dispatched orders found for this period/filter.
</div>
<?php endif; ?>

<?php require_once '../templates/footer.php'; ?>

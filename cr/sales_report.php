<?php
require_once '../core/init.php';
global $db;

restrict_access([]);

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

$filter_branch = $is_admin
    ? (isset($_GET['branch_id']) && (int)$_GET['branch_id'] > 0 ? (int)$_GET['branch_id'] : null)
    : $user_branch;

$branch_sql  = $filter_branch ? "AND co.assigned_branch_id = ?" : "";
$base_params = $filter_branch ? [$date_from, $date_to, $filter_branch] : [$date_from, $date_to];

$sale_date_expr = "COALESCE(DATE(cos.delivered_date), DATE(co.updated_at))";

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
$product_summary = $db->query("
    SELECT
        p.base_name AS product_name,
        COALESCE(pv.weight_variant,'—') AS weight_variant,
        COALESCE(pv.grade,'') AS grade,
        SUM(coi.quantity) AS total_qty,
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

// Period totals
$period_orders  = array_sum(array_column((array)$daily_summary, 'order_count'));
$period_qty     = array_sum(array_column((array)$daily_summary, 'total_qty'));
$period_revenue = array_sum(array_column((array)$daily_summary, 'total_revenue'));

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
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors">
            <i class="fas fa-file-csv text-[10px]"></i> CSV
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
</div>

<!-- ── Product Period Totals ──────────────────────────────────────────────── -->
<?php if (!empty($product_summary)): ?>
<div class="mb-5">
    <div class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-2">Product Breakdown — <?php echo date('d M', strtotime($date_from)); ?> to <?php echo date('d M Y', strtotime($date_to)); ?></div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100">
                    <th class="px-4 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Product</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Variant</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Grade / Type</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Bags / Units</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Revenue (৳)</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Share</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($product_summary as $ps):
                $share = $period_revenue > 0 ? ($ps->total_revenue / $period_revenue * 100) : 0;
            ?>
            <tr class="border-b border-gray-50 last:border-0 hover:bg-gray-50/40">
                <td class="px-4 py-2 text-xs font-bold text-gray-800"><?php echo htmlspecialchars($ps->product_name); ?></td>
                <td class="px-3 py-2">
                    <span class="text-[10px] px-2 py-0.5 bg-gray-100 text-gray-600 rounded font-semibold">
                        <?php echo htmlspecialchars($ps->weight_variant); ?>
                    </span>
                </td>
                <td class="px-3 py-2 text-[10px] text-gray-500"><?php echo htmlspecialchars($ps->grade ?: '—'); ?></td>
                <td class="px-3 py-2 text-right">
                    <span class="text-sm font-extrabold text-indigo-700"><?php echo number_format($ps->total_qty, 0); ?></span>
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
                    <td class="px-3 py-2 text-right text-sm font-extrabold text-green-700">৳<?php echo number_format($period_revenue, 0); ?></td>
                    <td class="px-3 py-2 text-right text-[10px] text-gray-400">100%</td>
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
<?php
require_once '../core/init.php';
require_once '../core/functions/xlsx_writer.php';
require_once '../core/functions/daily_activity_report_pdf.php';
global $db;

// Same reason as sales_report.php / dispatch_report.php — module must be
// passed explicitly or bare restrict_access() silently resolves to
// 'credit_sales' instead of 'production' for every cr/ page.
$allowed_roles = [
    'Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra',
    'dispatch-srg', 'dispatch-demra', 'dispatchpos-srg', 'dispatchpos-demra',
    'production manager-srg', 'production manager-demra',
];
restrict_access($allowed_roles, 'production', 'daily_activity_report');

$currentUser = getCurrentUser();
$user_role   = $currentUser['role'] ?? '';
$pageTitle   = 'Daily Activity Report';

$is_admin = in_array($user_role, ['Superadmin', 'admin']);

// Access-Manager-aware (was: hardcoded to the user's own employees.branch_id
// for every non-admin — a real, forced restriction, just never configurable
// via Access Manager). See resolveBranchFilterUI()/getUserBranchScope() in
// helpers.php.
$bf_scope = resolveBranchFilterUI('production', 'co.assigned_branch_id');

// ── Date range ───────────────────────────────────────────────────────────────
$date_from = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to   = !empty($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');
$date_from = date('Y-m-d', strtotime($date_from));
$date_to   = date('Y-m-d', strtotime($date_to));
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];

$filter_branch = $bf_scope['selected_branch_id'] ?: null;

$branch_sql  = $bf_scope['filter_sql'];
$base_params = array_merge([$date_from, $date_to], $bf_scope['filter_params']);

$filter_qs = 'date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to)
    . ($filter_branch ? '&branch_id=' . $filter_branch : '');

// The 7 operational milestones this report tracks, in funnel order. Keyed by
// a stable internal key (not the raw workflow action string, since 'deliver'
// maps to two different milestones depending on to_status). SQL fragment is
// the boolean expression counted per day; 'approved' matches both the normal
// action literal and the Other-Sales variant with LIKE.
// Excludes no-op duplicate transitions everywhere (from_status = to_status) —
// confirmed live 2 Sep 2026 that credit_production.php could produce these
// via a duplicate click before that was fixed; a report built on this table
// must not let old duplicate rows inflate counts.
$milestones = [
    'approved'  => ['label' => 'Approved',           'sql' => "(w.action = 'approved' OR w.action LIKE 'approved (%')"],
    'started'   => ['label' => 'Started Production', 'sql' => "w.action = 'start_production'"],
    'produced'  => ['label' => 'Produced',            'sql' => "w.action = 'complete_production'"],
    'ready'     => ['label' => 'Ready to Ship',       'sql' => "w.action = 'mark_ready_to_ship'"],
    'board'     => ['label' => 'Goods on Board',      'sql' => "w.action = 'goods_on_board'"],
    'partial'   => ['label' => 'Partially Delivered', 'sql' => "(w.action = 'deliver' AND w.to_status = 'shipped')"],
    'delivered' => ['label' => 'Fully Delivered',     'sql' => "(w.action = 'deliver' AND w.to_status = 'delivered')"],
];
$no_op_guard = "(w.from_status IS NULL OR w.from_status != w.to_status)";

// ── Branches (filter dropdown — admins, and any non-admin granted 2+ branches) ──
$branches = $bf_scope['picker_branches'];

// ── Order-level detail rows for the period (shared by page display + both
//    exports — one query, no duplication) ─────────────────────────────────────
$milestone_case = [];
foreach ($milestones as $key => $m) {
    $milestone_case[] = "WHEN {$m['sql']} THEN '{$key}'";
}
$milestone_case_sql = implode(' ', $milestone_case);

$detail_rows = $db->query(
    "SELECT w.performed_at, w.action, w.from_status, w.to_status,
            (CASE {$milestone_case_sql} ELSE NULL END) AS milestone_key,
            co.id AS order_id, co.order_number, co.total_amount, co.status AS current_status,
            c.name AS customer_name,
            COALESCE(b.name, '—') AS branch_name,
            u.display_name AS performed_by_name
     FROM credit_order_workflow w
     JOIN credit_orders co ON co.id = w.order_id
     JOIN customers c ON c.id = co.customer_id
     LEFT JOIN branches b ON b.id = co.assigned_branch_id
     LEFT JOIN users u ON u.id = w.performed_by_user_id
     WHERE DATE(w.performed_at) BETWEEN ? AND ?
       AND co.assigned_branch_id IS NOT NULL
       AND {$no_op_guard}
       $branch_sql
       AND (CASE {$milestone_case_sql} ELSE NULL END) IS NOT NULL
     ORDER BY w.performed_at DESC",
    $base_params
)->results();

// ── Daily summary — pivot detail rows in PHP (avoids a second near-identical
//    query; the detail set is already the exact universe of rows that matter) ──
$daily_summary = []; // date => [milestone_key => count]
foreach ($detail_rows as $r) {
    $day = date('Y-m-d', strtotime($r->performed_at));
    if (!isset($daily_summary[$day])) {
        $daily_summary[$day] = array_fill_keys(array_keys($milestones), 0);
    }
    $daily_summary[$day][$r->milestone_key]++;
}
krsort($daily_summary); // most recent day first

// Period totals (for stat chips + export total row)
$period_totals = array_fill_keys(array_keys($milestones), 0);
foreach ($detail_rows as $r) { $period_totals[$r->milestone_key]++; }

$period_label = date('d M Y', strtotime($date_from)) . ' to ' . date('d M Y', strtotime($date_to));
$branch_label = $filter_branch ? ($bf_scope['selected_branch_name'] ?: 'All Factories') : 'All Factories';

// ── CSV Export ───────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="daily_activity_report_' . $date_from . '_to_' . $date_to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM for Excel UTF-8
    fputcsv($out, ['Date/Time', 'Order #', 'Customer', 'Factory', 'Milestone', 'From Status', 'To Status', 'Current Status', 'Order Total (৳)', 'By']);
    foreach ($detail_rows as $r) {
        fputcsv($out, [
            date('Y-m-d H:i', strtotime($r->performed_at)),
            $r->order_number,
            $r->customer_name,
            $r->branch_name,
            $milestones[$r->milestone_key]['label'] ?? $r->milestone_key,
            $r->from_status ?? '',
            $r->to_status,
            $r->current_status,
            number_format((float)$r->total_amount, 2, '.', ''),
            $r->performed_by_name ?? 'System',
        ]);
    }
    fclose($out);
    exit();
}

// ── PDF Export ───────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $pdf = new DailyActivityReportPdf('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->periodLabel = $period_label;
    $pdf->branchLabel = $branch_label;
    $pdf->generatedBy = $currentUser['display_name'] ?? ($currentUser['name'] ?? '');
    $pdf->AddPage();

    $pdf->sectionTitle('Daily Summary');
    $headers = ['Date'];
    foreach ($milestones as $m) $headers[] = $m['label'];
    $widths  = [28];
    foreach ($milestones as $m) $widths[] = 36;
    $aligns  = ['L'];
    foreach ($milestones as $m) $aligns[] = 'C';

    $rows = [];
    foreach ($daily_summary as $day => $counts) {
        $row = [date('d M Y (D)', strtotime($day))];
        foreach ($milestones as $key => $m) $row[] = (string)$counts[$key];
        $rows[] = $row;
    }
    $totalRow = ['Total'];
    foreach ($milestones as $key => $m) $totalRow[] = (string)$period_totals[$key];
    $pdf->table($headers, $widths, $aligns, $rows, $totalRow);

    $pdf->sectionTitle('Order Detail (' . count($detail_rows) . ' events)');
    $dHeaders = ['Date/Time', 'Order #', 'Customer', 'Factory', 'Milestone', 'Current Status', 'Total (Tk)', 'By'];
    $dWidths  = [28, 32, 45, 25, 32, 25, 30, 40];
    $dAligns  = ['L', 'L', 'L', 'L', 'L', 'L', 'R', 'L'];
    $dRows = [];
    foreach ($detail_rows as $r) {
        $dRows[] = [
            date('d M, g:i A', strtotime($r->performed_at)),
            $r->order_number,
            $r->customer_name,
            $r->branch_name,
            $milestones[$r->milestone_key]['label'] ?? $r->milestone_key,
            ucwords(str_replace('_', ' ', $r->current_status)),
            number_format((float)$r->total_amount, 0),
            $r->performed_by_name ?? 'System',
        ];
    }
    $pdf->table($dHeaders, $dWidths, $dAligns, $dRows);

    $pdf->Output('D', 'daily_activity_report_' . $date_from . '_to_' . $date_to . '.pdf');
    exit();
}

require_once '../templates/header.php';

$milestone_colors = [
    'approved'  => 'blue',
    'started'   => 'amber',
    'produced'  => 'purple',
    'ready'     => 'orange',
    'board'     => 'cyan',
    'partial'   => 'indigo',
    'delivered' => 'green',
];
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Page Header -->
<div class="flex flex-wrap items-start justify-between gap-3 mb-4">
    <div>
        <h1 class="text-lg font-bold text-gray-900 flex items-center gap-2">
            <i class="fas fa-clipboard-list text-primary-500 text-base"></i> Daily Activity Report
        </h1>
        <p class="text-xs text-gray-400 mt-0.5">How many orders were approved, produced, shipped and delivered per day — sourced from the order workflow log, not a live status snapshot.</p>
    </div>

    <!-- Filter form -->
    <form method="GET" class="flex flex-wrap items-center gap-2">
        <input type="date" name="date_from" value="<?php echo $date_from; ?>"
               class="px-2.5 py-1.5 text-xs border border-gray-200 rounded-lg focus:ring-2 focus:ring-primary-300 outline-none shadow-sm">
        <span class="text-gray-300 text-xs">→</span>
        <input type="date" name="date_to" value="<?php echo $date_to; ?>"
               class="px-2.5 py-1.5 text-xs border border-gray-200 rounded-lg focus:ring-2 focus:ring-primary-300 outline-none shadow-sm">
        <?php if ($bf_scope['show_picker'] && !empty($branches)): ?>
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
        <a href="daily_activity_report.php?export=csv&<?php echo $filter_qs; ?>"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors">
            <i class="fas fa-file-csv text-[10px]"></i> CSV
        </a>
        <a href="daily_activity_report.php?export=pdf&<?php echo $filter_qs; ?>"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-rose-600 text-white rounded-lg hover:bg-rose-700 transition-colors">
            <i class="fas fa-file-pdf text-[10px]"></i> PDF
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
        <a href="daily_activity_report.php?date_from=<?php echo $qf; ?>&date_to=<?php echo $qt; ?><?php echo $filter_branch ? '&branch_id=' . $filter_branch : ''; ?>"
           class="px-2.5 py-1.5 text-[10px] font-semibold border border-gray-200 text-gray-500 rounded-lg hover:bg-gray-50 transition-colors whitespace-nowrap
                  <?php echo ($date_from === $qf && $date_to === $qt) ? 'bg-gray-100 border-gray-300 text-gray-700' : ''; ?>">
            <?php echo $label; ?>
        </a>
        <?php endforeach; ?>
    </form>
</div>

<!-- ── Stats Chips ─────────────────────────────────────────────────────────── -->
<div class="flex flex-wrap items-center gap-2 mb-5">
    <span class="text-xs text-gray-400 mr-1"><?php echo htmlspecialchars($period_label); ?> · <?php echo htmlspecialchars($branch_label); ?></span>
    <?php foreach ($milestones as $key => $m): $col = $milestone_colors[$key]; ?>
    <div class="flex items-center gap-2 px-3 py-2 bg-white border border-gray-100 rounded-xl shadow-sm">
        <span class="w-2 h-2 rounded-full bg-<?php echo $col; ?>-500"></span>
        <div>
            <div class="text-[10px] text-gray-400 font-semibold uppercase leading-none"><?php echo htmlspecialchars($m['label']); ?></div>
            <div class="text-sm font-bold text-gray-800 mt-0.5"><?php echo number_format($period_totals[$key]); ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Daily Summary Table ─────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden mb-6">
    <div class="px-4 py-3 border-b border-gray-100">
        <h2 class="text-sm font-bold text-gray-800">Daily Summary</h2>
    </div>
    <?php if (!empty($daily_summary)): ?>
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-200 text-left">
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Date</th>
                    <?php foreach ($milestones as $m): ?>
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap text-center"><?php echo htmlspecialchars($m['label']); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($daily_summary as $day => $counts): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 font-semibold text-gray-700 whitespace-nowrap"><?php echo date('d M Y (D)', strtotime($day)); ?></td>
                    <?php foreach ($milestones as $key => $m): ?>
                    <td class="px-3 py-2 text-center <?php echo $counts[$key] > 0 ? 'font-semibold text-gray-800' : 'text-gray-300'; ?>"><?php echo number_format($counts[$key]); ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="bg-gray-50 border-t-2 border-gray-200 font-bold">
                    <td class="px-3 py-2 text-gray-700">Total</td>
                    <?php foreach ($milestones as $key => $m): ?>
                    <td class="px-3 py-2 text-center text-gray-800"><?php echo number_format($period_totals[$key]); ?></td>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php else: ?>
    <div class="p-8 text-center text-gray-400 text-sm">No activity in this period/filter.</div>
    <?php endif; ?>
</div>

<!-- ── Order-Level Detail ──────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
        <h2 class="text-sm font-bold text-gray-800">Order Detail</h2>
        <span class="text-xs text-gray-400"><?php echo count($detail_rows); ?> event<?php echo count($detail_rows) === 1 ? '' : 's'; ?></span>
    </div>
    <?php if (!empty($detail_rows)): ?>
    <div class="overflow-x-auto max-h-[600px] overflow-y-auto">
        <table class="w-full text-xs">
            <thead class="sticky top-0 bg-gray-50 z-10">
                <tr class="border-b border-gray-200 text-left">
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Date/Time</th>
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Order</th>
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Customer · Factory</th>
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Milestone</th>
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Current Status</th>
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap text-right">Amount</th>
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">By</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($detail_rows as $r): $col = $milestone_colors[$r->milestone_key] ?? 'gray'; ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 text-gray-500 whitespace-nowrap"><?php echo date('d M, g:i A', strtotime($r->performed_at)); ?></td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        <a href="credit_order_view.php?id=<?php echo (int)($r->order_id ?? 0); ?>" class="font-mono font-bold text-blue-700 hover:underline">
                            <?php echo htmlspecialchars($r->order_number); ?>
                        </a>
                    </td>
                    <td class="px-3 py-2">
                        <div class="font-medium text-gray-800"><?php echo htmlspecialchars($r->customer_name); ?></div>
                        <div class="text-gray-400"><?php echo htmlspecialchars($r->branch_name); ?></div>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-<?php echo $col; ?>-100 text-<?php echo $col; ?>-700">
                            <?php echo htmlspecialchars($milestones[$r->milestone_key]['label'] ?? $r->milestone_key); ?>
                        </span>
                    </td>
                    <td class="px-3 py-2 text-gray-500 whitespace-nowrap"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $r->current_status))); ?></td>
                    <td class="px-3 py-2 text-right font-semibold text-gray-800 whitespace-nowrap">৳<?php echo number_format((float)$r->total_amount, 2); ?></td>
                    <td class="px-3 py-2 text-gray-500 whitespace-nowrap"><?php echo htmlspecialchars($r->performed_by_name ?? 'System'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="p-8 text-center text-gray-400 text-sm">No activity in this period/filter.</div>
    <?php endif; ?>
</div>

</div>

<?php require_once '../templates/footer.php'; ?>

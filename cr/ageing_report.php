<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$pageTitle   = 'Customer Credit Ageing Report';

$as_of_date  = $_GET['as_of'] ?? date('Y-m-d');
$branch_filter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$min_due       = (float)($_GET['min_due'] ?? 0);

/* ─── Branches for filter ──────────────────────────────── */
$branches = $db->query("SELECT id, name FROM branches WHERE status='active' ORDER BY name")->results();

/* ─── Core Ageing Query ─────────────────────────────────
   Age = days between order_date and as_of_date.
   Buckets: 0–30 | 31–60 | 61–90 | 91–180 | 181+
   Only delivered/shipped orders with outstanding balance.
─────────────────────────────────────────────────────── */
$branch_sql = $branch_filter ? "AND co.assigned_branch_id = {$branch_filter}" : '';

$ageing_rows = $db->query(
    "SELECT
        c.id                AS customer_id,
        c.name              AS customer_name,
        c.phone_number,
        c.business_name,
        c.credit_limit,
        c.initial_due,
        c.current_balance,

        -- Per-order age grouping
        SUM(CASE WHEN DATEDIFF(?, co.order_date) BETWEEN 0  AND 30  THEN co.balance_due ELSE 0 END) AS bucket_0_30,
        SUM(CASE WHEN DATEDIFF(?, co.order_date) BETWEEN 31 AND 60  THEN co.balance_due ELSE 0 END) AS bucket_31_60,
        SUM(CASE WHEN DATEDIFF(?, co.order_date) BETWEEN 61 AND 90  THEN co.balance_due ELSE 0 END) AS bucket_61_90,
        SUM(CASE WHEN DATEDIFF(?, co.order_date) BETWEEN 91 AND 180 THEN co.balance_due ELSE 0 END) AS bucket_91_180,
        SUM(CASE WHEN DATEDIFF(?, co.order_date) > 180               THEN co.balance_due ELSE 0 END) AS bucket_over_180,
        SUM(co.balance_due)  AS total_due,
        COUNT(co.id)         AS open_invoices,
        MIN(co.order_date)   AS oldest_invoice_date,
        MAX(co.order_date)   AS latest_invoice_date,
        MAX(DATEDIFF(?, co.order_date)) AS max_age_days
     FROM customers c
     JOIN credit_orders co ON co.customer_id = c.id
     WHERE co.balance_due > 0.01
       AND co.status NOT IN ('cancelled','rejected','draft')
       AND c.customer_type = 'Credit'
       AND c.status = 'active'
       {$branch_sql}
     GROUP BY c.id
     HAVING total_due >= ?
     ORDER BY total_due DESC",
    [$as_of_date, $as_of_date, $as_of_date, $as_of_date, $as_of_date, $as_of_date, $min_due]
)->results();

/* ─── Totals ────────────────────────────────────────────── */
$totals = [
    'customers'    => count($ageing_rows),
    'invoices'     => 0,
    'bucket_0_30'  => 0,
    'bucket_31_60' => 0,
    'bucket_61_90' => 0,
    'bucket_91_180'=> 0,
    'over_180'     => 0,
    'total_due'    => 0,
];
foreach ($ageing_rows as $r) {
    $totals['invoices']     += $r->open_invoices;
    $totals['bucket_0_30']  += $r->bucket_0_30;
    $totals['bucket_31_60'] += $r->bucket_31_60;
    $totals['bucket_61_90'] += $r->bucket_61_90;
    $totals['bucket_91_180']+= $r->bucket_91_180;
    $totals['over_180']     += $r->bucket_over_180;
    $totals['total_due']    += $r->total_due;
}

/* helper */
function ageing_color(float $days): string {
    if ($days <= 30)  return 'text-green-700 bg-green-50';
    if ($days <= 60)  return 'text-yellow-700 bg-yellow-50';
    if ($days <= 90)  return 'text-orange-700 bg-orange-50';
    if ($days <= 180) return 'text-red-600 bg-red-50';
    return 'text-red-900 bg-red-100 font-bold';
}

require_once '../templates/header.php';
?>

<style>
@media print {
    .no-print { display:none !important; }
    body { font-size: 11px; }
    .shadow-md,.shadow-lg { box-shadow:none !important; }
    table { border-collapse: collapse; }
    th,td { border: 1px solid #ddd !important; padding: 4px 6px !important; }
}
</style>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- ── Header ───────────────────────────────────────────── -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-4 no-print">
    <div>
        <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
        <p class="text-gray-500 mt-1">Accounts Receivable ageing as of <strong><?php echo date('d M Y', strtotime($as_of_date)); ?></strong></p>
    </div>
    <div class="flex gap-3">
        <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
            <i class="fas fa-arrow-left mr-2"></i>Dashboard
        </a>
        <button onclick="window.print()" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">
            <i class="fas fa-print mr-2"></i>Print / PDF
        </button>
    </div>
</div>

<!-- ── Filters ───────────────────────────────────────────── -->
<div class="bg-white rounded-lg shadow-md p-4 mb-6 no-print">
    <form method="GET" class="flex flex-wrap gap-4 items-end">
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">As of Date</label>
            <input type="date" name="as_of" value="<?php echo $as_of_date; ?>"
                   class="px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Branch</label>
            <select name="branch_id" class="px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                <option value="0">All Branches</option>
                <?php foreach ($branches as $b): ?>
                <option value="<?php echo $b->id; ?>" <?php echo $branch_filter == $b->id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($b->name); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Min Outstanding (৳)</label>
            <input type="number" name="min_due" value="<?php echo $min_due ?: ''; ?>" step="1000" min="0"
                   placeholder="0" class="px-3 py-2 border rounded-lg text-sm w-36 focus:ring-2 focus:ring-blue-500">
        </div>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700">
            <i class="fas fa-filter mr-1"></i> Apply
        </button>
        <a href="ageing_report.php" class="px-3 py-2 border text-sm rounded-lg text-gray-600 hover:bg-gray-100">Reset</a>
    </form>
</div>

<!-- ── Summary Cards ─────────────────────────────────────── -->
<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 mb-6">
    <?php
    $cards = [
        ['label'=>'Total Outstanding', 'val'=>'৳'.number_format($totals['total_due'],0), 'color'=>'red'],
        ['label'=>'Customers',         'val'=>number_format($totals['customers']),         'color'=>'gray'],
        ['label'=>'Open Invoices',     'val'=>number_format($totals['invoices']),           'color'=>'gray'],
        ['label'=>'0–30 Days',         'val'=>'৳'.number_format($totals['bucket_0_30'],0), 'color'=>'green'],
        ['label'=>'31–60 Days',        'val'=>'৳'.number_format($totals['bucket_31_60'],0),'color'=>'yellow'],
        ['label'=>'61–90 Days',        'val'=>'৳'.number_format($totals['bucket_61_90'],0),'color'=>'orange'],
        ['label'=>'Over 90 Days',      'val'=>'৳'.number_format($totals['bucket_91_180']+$totals['over_180'],0),'color'=>'red'],
    ];
    foreach ($cards as $c): ?>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-<?php echo $c['color']; ?>-500 text-center">
        <p class="text-xs text-gray-500 font-medium uppercase"><?php echo $c['label']; ?></p>
        <p class="text-lg font-bold text-gray-900 mt-1"><?php echo $c['val']; ?></p>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Ageing Bar ─────────────────────────────────────────── -->
<?php if ($totals['total_due'] > 0): ?>
<div class="bg-white rounded-lg shadow-md p-4 mb-6">
    <p class="text-sm font-semibold text-gray-700 mb-2">Portfolio Risk Distribution</p>
    <div class="flex rounded-full overflow-hidden h-5 text-xs text-white font-bold">
        <?php
        $segs = [
            ['val'=>$totals['bucket_0_30'],   'bg'=>'bg-green-500',  'label'=>'0–30d'],
            ['val'=>$totals['bucket_31_60'],  'bg'=>'bg-yellow-500', 'label'=>'31–60d'],
            ['val'=>$totals['bucket_61_90'],  'bg'=>'bg-orange-500', 'label'=>'61–90d'],
            ['val'=>$totals['bucket_91_180'], 'bg'=>'bg-red-500',    'label'=>'91–180d'],
            ['val'=>$totals['over_180'],      'bg'=>'bg-red-800',    'label'=>'>180d'],
        ];
        foreach ($segs as $seg):
            $pct = $totals['total_due'] > 0 ? ($seg['val'] / $totals['total_due']) * 100 : 0;
            if ($pct < 0.5) continue;
        ?>
        <div class="<?php echo $seg['bg']; ?> flex items-center justify-center overflow-hidden"
             style="width:<?php echo round($pct,2); ?>%"
             title="<?php echo $seg['label']; ?>: ৳<?php echo number_format($seg['val'],0); ?> (<?php echo round($pct,1); ?>%)">
            <?php if ($pct > 5): echo $seg['label']; endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="flex flex-wrap gap-4 mt-2 text-xs text-gray-600">
        <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-green-500 inline-block"></span>0–30d</span>
        <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-yellow-500 inline-block"></span>31–60d</span>
        <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-orange-500 inline-block"></span>61–90d</span>
        <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-red-500 inline-block"></span>91–180d</span>
        <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-red-800 inline-block"></span>&gt;180d</span>
    </div>
</div>
<?php endif; ?>

<!-- ── Main Table ────────────────────────────────────────── -->
<div class="bg-white rounded-lg shadow-lg overflow-hidden">
    <div class="p-4 border-b bg-gray-50 flex justify-between items-center no-print">
        <h2 class="text-lg font-bold text-gray-800">
            Customer Ageing Detail
            <span class="ml-2 text-sm font-normal text-gray-500">(<?php echo $totals['customers']; ?> customers with outstanding balances)</span>
        </h2>
        <div class="flex gap-2">
            <input type="text" id="tableSearch" placeholder="Search customer..."
                   class="px-3 py-1.5 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500"
                   oninput="filterTable(this.value)">
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm" id="ageingTable">
            <thead class="bg-gray-800 text-white">
                <tr>
                    <th class="px-4 py-3 text-left font-medium">#</th>
                    <th class="px-4 py-3 text-left font-medium">Customer</th>
                    <th class="px-4 py-3 text-left font-medium">Phone</th>
                    <th class="px-4 py-3 text-right font-medium">Credit Limit</th>
                    <th class="px-4 py-3 text-right font-medium text-green-300">0–30 Days</th>
                    <th class="px-4 py-3 text-right font-medium text-yellow-300">31–60 Days</th>
                    <th class="px-4 py-3 text-right font-medium text-orange-300">61–90 Days</th>
                    <th class="px-4 py-3 text-right font-medium text-red-300">91–180 Days</th>
                    <th class="px-4 py-3 text-right font-medium text-red-200">&gt;180 Days</th>
                    <th class="px-4 py-3 text-right font-medium text-white">Total Due</th>
                    <th class="px-4 py-3 text-center font-medium">Oldest</th>
                    <th class="px-4 py-3 text-center font-medium no-print">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php if (empty($ageing_rows)): ?>
                <tr>
                    <td colspan="12" class="px-4 py-10 text-center text-gray-500">
                        <i class="fas fa-check-circle text-green-500 text-3xl mb-2 block"></i>
                        No outstanding balances found — all accounts are settled!
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($ageing_rows as $i => $row): ?>
                <?php
                    $avail = $row->credit_limit - $row->current_balance;
                    $utilPct = $row->credit_limit > 0 ? min(100, ($row->total_due / $row->credit_limit) * 100) : 0;
                    $ageClass = ageing_color((float)$row->max_age_days);
                    $isCritical = $row->bucket_over_180 > 0 || ($row->bucket_91_180 > 0 && $row->credit_limit > 0 && $row->total_due > $row->credit_limit * 0.9);
                ?>
                <tr class="hover:bg-gray-50 transition-colors <?php echo $isCritical ? 'border-l-4 border-red-500' : ''; ?>" data-name="<?php echo strtolower($row->customer_name); ?>">
                    <td class="px-4 py-3 text-gray-500"><?php echo $i + 1; ?></td>
                    <td class="px-4 py-3">
                        <div class="font-semibold text-gray-900">
                            <a href="../customers/view.php?id=<?php echo $row->customer_id; ?>" class="hover:underline text-blue-700">
                                <?php echo htmlspecialchars($row->customer_name); ?>
                            </a>
                        </div>
                        <?php if ($row->business_name): ?>
                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($row->business_name); ?></div>
                        <?php endif; ?>
                        <?php if ($row->credit_limit > 0): ?>
                        <div class="mt-1 flex items-center gap-1">
                            <div class="flex-1 bg-gray-200 rounded-full h-1.5 max-w-[80px]">
                                <div class="<?php echo $utilPct > 90 ? 'bg-red-500' : ($utilPct > 70 ? 'bg-yellow-500' : 'bg-green-500'); ?> h-1.5 rounded-full"
                                     style="width:<?php echo min(100, $utilPct); ?>%"></div>
                            </div>
                            <span class="text-xs text-gray-500"><?php echo round($utilPct); ?>%</span>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars($row->phone_number ?? ''); ?></td>
                    <td class="px-4 py-3 text-right text-gray-700">
                        <?php echo $row->credit_limit > 0 ? '৳'.number_format($row->credit_limit, 0) : '<span class="text-gray-400 text-xs">No limit</span>'; ?>
                    </td>
                    <td class="px-4 py-3 text-right <?php echo $row->bucket_0_30 > 0 ? 'text-green-700 font-medium' : 'text-gray-400'; ?>">
                        <?php echo $row->bucket_0_30 > 0 ? '৳'.number_format($row->bucket_0_30, 0) : '—'; ?>
                    </td>
                    <td class="px-4 py-3 text-right <?php echo $row->bucket_31_60 > 0 ? 'text-yellow-700 font-medium' : 'text-gray-400'; ?>">
                        <?php echo $row->bucket_31_60 > 0 ? '৳'.number_format($row->bucket_31_60, 0) : '—'; ?>
                    </td>
                    <td class="px-4 py-3 text-right <?php echo $row->bucket_61_90 > 0 ? 'text-orange-700 font-semibold' : 'text-gray-400'; ?>">
                        <?php echo $row->bucket_61_90 > 0 ? '৳'.number_format($row->bucket_61_90, 0) : '—'; ?>
                    </td>
                    <td class="px-4 py-3 text-right <?php echo $row->bucket_91_180 > 0 ? 'text-red-600 font-semibold' : 'text-gray-400'; ?>">
                        <?php echo $row->bucket_91_180 > 0 ? '৳'.number_format($row->bucket_91_180, 0) : '—'; ?>
                    </td>
                    <td class="px-4 py-3 text-right <?php echo $row->bucket_over_180 > 0 ? 'text-red-900 font-bold' : 'text-gray-400'; ?>">
                        <?php echo $row->bucket_over_180 > 0 ? '৳'.number_format($row->bucket_over_180, 0) : '—'; ?>
                    </td>
                    <td class="px-4 py-3 text-right font-bold text-red-700 whitespace-nowrap">
                        ৳<?php echo number_format($row->total_due, 0); ?>
                        <div class="text-xs text-gray-500 font-normal"><?php echo $row->open_invoices; ?> inv</div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-0.5 rounded text-xs font-bold <?php echo $ageClass; ?>">
                            <?php echo (int)$row->max_age_days; ?>d
                        </span>
                        <div class="text-xs text-gray-400 mt-0.5"><?php echo date('d M', strtotime($row->oldest_invoice_date)); ?></div>
                    </td>
                    <td class="px-4 py-3 text-center no-print whitespace-nowrap">
                        <a href="customer_ledger.php?customer_id=<?php echo $row->customer_id; ?>"
                           class="text-blue-600 hover:text-blue-800 text-xs px-2 py-1 rounded bg-blue-50 hover:bg-blue-100 mr-1" title="View Ledger">
                            <i class="fas fa-book"></i>
                        </a>
                        <a href="customer_payment.php"
                           class="text-teal-600 hover:text-teal-800 text-xs px-2 py-1 rounded bg-teal-50 hover:bg-teal-100" title="Record Payment">
                            <i class="fas fa-money-bill-wave"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($ageing_rows)): ?>
            <tfoot class="bg-gray-800 text-white">
                <tr>
                    <td colspan="4" class="px-4 py-3 font-bold">
                        TOTAL (<?php echo $totals['customers']; ?> customers, <?php echo $totals['invoices']; ?> invoices)
                    </td>
                    <td class="px-4 py-3 text-right font-bold text-green-300">৳<?php echo number_format($totals['bucket_0_30'],0); ?></td>
                    <td class="px-4 py-3 text-right font-bold text-yellow-300">৳<?php echo number_format($totals['bucket_31_60'],0); ?></td>
                    <td class="px-4 py-3 text-right font-bold text-orange-300">৳<?php echo number_format($totals['bucket_61_90'],0); ?></td>
                    <td class="px-4 py-3 text-right font-bold text-red-300">৳<?php echo number_format($totals['bucket_91_180'],0); ?></td>
                    <td class="px-4 py-3 text-right font-bold text-red-200">৳<?php echo number_format($totals['over_180'],0); ?></td>
                    <td class="px-4 py-3 text-right font-bold text-white">৳<?php echo number_format($totals['total_due'],0); ?></td>
                    <td colspan="2" class="no-print"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Print footer -->
<div class="hidden print:block mt-6 text-center text-xs text-gray-500">
    Generated by Ujjal Flour Mills ERP — <?php echo date('d M Y H:i'); ?> | As of <?php echo date('d M Y', strtotime($as_of_date)); ?>
</div>

</div>

<script>
function filterTable(q) {
    const rows = document.querySelectorAll('#ageingTable tbody tr[data-name]');
    q = q.toLowerCase();
    rows.forEach(r => {
        r.style.display = r.dataset.name.includes(q) ? '' : 'none';
    });
}
</script>

<?php require_once '../templates/footer.php'; ?>

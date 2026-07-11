<?php
/**
 * Purchase (Adnan) — Supplier Balance Summary
 * Accurate per-supplier due/advance picture.
 * Formula: SUM(grn.expected_quantity × po.unit_price_per_kg) + po.total_adjustment_amount − SUM(payments.amount_paid)
 * Scope: all non-closed, non-cancelled POs (matches index "All Active" default).
 */

require_once '../core/init.php';
require_once '../core/config/config.php';
require_once '../core/classes/Database.php';
require_once '../core/functions/helpers.php';

restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle = "Supplier Balance Summary — Purchase (Adnan)";

$db = Database::getInstance()->getPdo();

// ── Filters ──────────────────────────────────────────────────────────────────
$search      = trim($_GET['search'] ?? '');
$sort        = $_GET['sort'] ?? 'due_desc';
$scope       = $_GET['scope'] ?? 'active'; // active | all
$show_zero   = isset($_GET['show_zero']);   // show suppliers with zero balance

// ── Main Query — one row per PO, then group in PHP ───────────────────────────
// Using the same pattern as listPurchaseOrders (JOIN-aggregated sub-queries)
// so expected_qty comes through PO link, not grn.supplier_id.

$scope_join = ($scope === 'active')
    ? "AND po.delivery_status != 'closed'"
    : ""; // 'all' = include closed too

$sql = "
    SELECT
        s.id            AS supplier_id,
        s.company_name,
        s.supplier_code,
        po.id           AS po_id,
        po.po_number,
        po.po_date,
        po.wheat_origin,
        po.unit_price_per_kg,
        po.quantity_kg,
        po.delivery_status,
        po.payment_status,
        po.total_adjustment_amount,
        COALESCE(grn_agg.total_expected_qty, 0)  AS expected_qty,
        COALESCE(grn_agg.total_received_qty, 0)  AS received_qty,
        COALESCE(pmt_agg.total_paid, 0)          AS paid_amount,
        (SELECT COUNT(*) FROM purchase_adjustment_notes pan
         WHERE pan.purchase_order_id = po.id AND pan.status = 'posted') AS adj_notes_count
    FROM suppliers s
    INNER JOIN purchase_orders_adnan po
        ON po.supplier_id = s.id
        AND po.po_status != 'cancelled'
        $scope_join
    LEFT JOIN (
        SELECT purchase_order_id,
               SUM(expected_quantity)      AS total_expected_qty,
               SUM(quantity_received_kg)  AS total_received_qty
        FROM goods_received_adnan
        WHERE grn_status != 'cancelled'
        GROUP BY purchase_order_id
    ) grn_agg ON grn_agg.purchase_order_id = po.id
    LEFT JOIN (
        SELECT purchase_order_id,
               SUM(amount_paid) AS total_paid
        FROM purchase_payments_adnan
        WHERE is_posted = 1
        GROUP BY purchase_order_id
    ) pmt_agg ON pmt_agg.purchase_order_id = po.id
    WHERE s.status = 'active'
";

$params = [];
if ($search !== '') {
    $sql .= " AND s.company_name LIKE ?";
    $params[] = "%$search%";
}

$sql .= " ORDER BY s.company_name, po.po_date DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_OBJ);

// ── Aggregate in PHP ──────────────────────────────────────────────────────────
$suppliers = []; // keyed by supplier_id

foreach ($rows as $r) {
    $sid = (int)$r->supplier_id;

    if (!isset($suppliers[$sid])) {
        $suppliers[$sid] = [
            'id'           => $sid,
            'name'         => $r->company_name,
            'code'         => $r->supplier_code ?? '—',
            'total_receivable' => 0,
            'total_paid'       => 0,
            'total_adj'        => 0,
            'total_due'        => 0,
            'total_advance'    => 0,
            'po_count'         => 0,
            'pos'              => [],
        ];
    }

    $payable = (float)$r->expected_qty * (float)$r->unit_price_per_kg
             + (float)($r->total_adjustment_amount ?? 0);
    $paid    = (float)$r->paid_amount;
    $balance = $payable - $paid;

    $suppliers[$sid]['total_receivable'] += $payable;
    $suppliers[$sid]['total_paid']       += $paid;
    $suppliers[$sid]['total_adj']        += (float)($r->total_adjustment_amount ?? 0);
    $suppliers[$sid]['po_count']         += 1;

    if ($balance > 0.005) {
        $suppliers[$sid]['total_due']     += $balance;
    } elseif ($balance < -0.005) {
        $suppliers[$sid]['total_advance'] += abs($balance);
    }

    $suppliers[$sid]['pos'][] = [
        'po_id'         => (int)$r->po_id,
        'po_number'     => $r->po_number,
        'po_date'       => $r->po_date,
        'origin'        => $r->wheat_origin,
        'rate'          => (float)$r->unit_price_per_kg,
        'ordered_kg'    => (float)$r->quantity_kg,
        'expected_qty'  => (float)$r->expected_qty,
        'received_qty'  => (float)$r->received_qty,
        'payable'       => $payable,
        'paid'          => $paid,
        'adj'           => (float)($r->total_adjustment_amount ?? 0),
        'balance'       => $balance,
        'delivery'      => $r->delivery_status,
        'payment'       => $r->payment_status,
        'adj_notes'     => (int)$r->adj_notes_count,
    ];
}

// ── Remove zero-balance suppliers if not showing them ────────────────────────
if (!$show_zero) {
    $suppliers = array_filter($suppliers, function($s) {
        return ($s['total_due'] + $s['total_advance']) > 0.005;
    });
}

// ── Sort ─────────────────────────────────────────────────────────────────────
usort($suppliers, function($a, $b) use ($sort) {
    return match($sort) {
        'due_desc'     => $b['total_due']     <=> $a['total_due'],
        'due_asc'      => $a['total_due']     <=> $b['total_due'],
        'advance_desc' => $b['total_advance'] <=> $a['total_advance'],
        'name_asc'     => strcmp($a['name'],     $b['name']),
        default        => $b['total_due']     <=> $a['total_due'],
    };
});

// ── Grand totals ─────────────────────────────────────────────────────────────
$grand = [
    'suppliers'   => count($suppliers),
    'pos'         => array_sum(array_column($suppliers, 'po_count')),
    'receivable'  => array_sum(array_column($suppliers, 'total_receivable')),
    'paid'        => array_sum(array_column($suppliers, 'total_paid')),
    'adj'         => array_sum(array_column($suppliers, 'total_adj')),
    'due'         => array_sum(array_column($suppliers, 'total_due')),
    'advance'     => array_sum(array_column($suppliers, 'total_advance')),
];
$grand['net']          = $grand['due'] - $grand['advance'];
$grand['payment_rate'] = $grand['receivable'] > 0
    ? min(100, $grand['paid'] / $grand['receivable'] * 100)
    : 0;

$suppliers_with_due = count(array_filter($suppliers, fn($s) => $s['total_due'] > 0.005));
$suppliers_with_adv = count(array_filter($suppliers, fn($s) => $s['total_advance'] > 0.005));

// Recent 30-day payments
$pay30 = $db->query(
    "SELECT COALESCE(SUM(amount_paid),0) as total FROM purchase_payments_adnan
     WHERE is_posted=1 AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
)->fetch(PDO::FETCH_OBJ);

// Encode PO detail for JS
$js_data = json_encode(array_values($suppliers), JSON_UNESCAPED_UNICODE);

include '../templates/header.php';
?>

<div class="w-full px-4 sm:px-6 lg:px-8 py-5">

    <!-- ── Page Header ──────────────────────────────────────────────────────── -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <div>
            <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-balance-scale text-indigo-500"></i> Supplier Balance Summary
            </h1>
            <p class="text-xs text-gray-400 mt-0.5">
                Expected-quantity basis · posted payments only ·
                <?php echo $scope === 'active' ? 'Active (non-closed) POs' : 'All POs'; ?>
            </p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <a href="purchase_adnan_index.php"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium border border-gray-200 text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-arrow-left"></i> Back to Orders
            </a>
            <a href="purchase_adnan_supplier_summary.php"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium border border-gray-200 text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-list"></i> Old Summary
            </a>
        </div>
    </div>

    <!-- ── Summary Cards ────────────────────────────────────────────────────── -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">

        <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-4 py-3">
            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Active Suppliers</p>
            <p class="text-2xl font-bold text-gray-800"><?php echo $grand['suppliers']; ?></p>
            <p class="text-[10px] text-gray-400 mt-0.5"><?php echo $grand['pos']; ?> open POs</p>
        </div>

        <div class="bg-white rounded-xl border border-red-100 shadow-sm px-4 py-3">
            <p class="text-[10px] font-semibold text-red-400 uppercase tracking-wide mb-1">We Owe (Due)</p>
            <p class="text-2xl font-bold text-red-600">৳<?php echo number_format($grand['due'] / 1e5, 2); ?>L</p>
            <p class="text-[10px] text-red-400 mt-0.5"><?php echo $suppliers_with_due; ?> supplier<?php echo $suppliers_with_due != 1 ? 's' : ''; ?></p>
        </div>

        <div class="bg-white rounded-xl border border-blue-100 shadow-sm px-4 py-3">
            <p class="text-[10px] font-semibold text-blue-400 uppercase tracking-wide mb-1">Advance Paid</p>
            <p class="text-2xl font-bold text-blue-600">৳<?php echo number_format($grand['advance'] / 1e5, 2); ?>L</p>
            <p class="text-[10px] text-blue-400 mt-0.5"><?php echo $suppliers_with_adv; ?> supplier<?php echo $suppliers_with_adv != 1 ? 's' : ''; ?></p>
        </div>

        <div class="bg-white rounded-xl border border-<?php echo $grand['net'] > 0 ? 'red' : 'green'; ?>-100 shadow-sm px-4 py-3">
            <p class="text-[10px] font-semibold text-<?php echo $grand['net'] > 0 ? 'red' : 'green'; ?>-400 uppercase tracking-wide mb-1">Net Position</p>
            <p class="text-2xl font-bold text-<?php echo $grand['net'] > 0 ? 'red-600' : 'green-600'; ?>">
                <?php echo $grand['net'] > 0 ? '৳' . number_format($grand['net'] / 1e5, 2) . 'L' : '৳' . number_format(abs($grand['net']) / 1e5, 2) . 'L'; ?>
            </p>
            <p class="text-[10px] text-gray-400 mt-0.5">
                <?php echo $grand['net'] > 0 ? 'we owe net' : 'advance net'; ?>
            </p>
        </div>

        <div class="bg-white rounded-xl border border-purple-100 shadow-sm px-4 py-3">
            <p class="text-[10px] font-semibold text-purple-400 uppercase tracking-wide mb-1">Total Receivable</p>
            <p class="text-2xl font-bold text-purple-600">৳<?php echo number_format($grand['receivable'] / 1e7, 2); ?>Cr</p>
            <p class="text-[10px] text-gray-400 mt-0.5">expected qty × rate</p>
        </div>

        <div class="bg-white rounded-xl border border-green-100 shadow-sm px-4 py-3">
            <p class="text-[10px] font-semibold text-green-400 uppercase tracking-wide mb-1">Total Paid</p>
            <p class="text-2xl font-bold text-green-600">৳<?php echo number_format($grand['paid'] / 1e7, 2); ?>Cr</p>
            <p class="text-[10px] text-gray-400 mt-0.5">
                <?php echo number_format($grand['payment_rate'], 1); ?>% of receivable
            </p>
        </div>

    </div>

    <!-- ── Secondary Info Bar ───────────────────────────────────────────────── -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
        <div class="bg-indigo-50 border border-indigo-100 rounded-xl px-4 py-2.5 flex items-center gap-3">
            <i class="fas fa-file-invoice text-indigo-400 text-lg"></i>
            <div>
                <p class="text-[10px] font-semibold text-indigo-400 uppercase">Total Receivable</p>
                <p class="text-sm font-bold text-indigo-700">৳<?php echo number_format($grand['receivable'], 2); ?></p>
            </div>
        </div>
        <div class="bg-green-50 border border-green-100 rounded-xl px-4 py-2.5 flex items-center gap-3">
            <i class="fas fa-check-circle text-green-400 text-lg"></i>
            <div>
                <p class="text-[10px] font-semibold text-green-500 uppercase">Total Paid</p>
                <p class="text-sm font-bold text-green-700">৳<?php echo number_format($grand['paid'], 2); ?></p>
            </div>
        </div>
        <div class="bg-amber-50 border border-amber-100 rounded-xl px-4 py-2.5 flex items-center gap-3">
            <i class="fas fa-exchange-alt text-amber-400 text-lg"></i>
            <div>
                <p class="text-[10px] font-semibold text-amber-500 uppercase">Net Adjustments</p>
                <p class="text-sm font-bold text-amber-700">৳<?php echo number_format($grand['adj'], 2); ?></p>
            </div>
        </div>
        <div class="bg-teal-50 border border-teal-100 rounded-xl px-4 py-2.5 flex items-center gap-3">
            <i class="fas fa-clock text-teal-400 text-lg"></i>
            <div>
                <p class="text-[10px] font-semibold text-teal-500 uppercase">Paid (Last 30 Days)</p>
                <p class="text-sm font-bold text-teal-700">৳<?php echo number_format((float)($pay30->total ?? 0), 2); ?></p>
            </div>
        </div>
    </div>

    <!-- ── Payment Rate Progress ─────────────────────────────────────────────── -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-4 py-3 mb-5">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold text-gray-600">Overall Payment Progress</span>
            <span class="text-xs font-bold text-<?php echo $grand['payment_rate'] >= 90 ? 'green' : ($grand['payment_rate'] >= 70 ? 'amber' : 'red'); ?>-600">
                <?php echo number_format($grand['payment_rate'], 1); ?>% paid
            </span>
        </div>
        <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden">
            <div class="h-2.5 rounded-full <?php echo $grand['payment_rate'] >= 90 ? 'bg-green-500' : ($grand['payment_rate'] >= 70 ? 'bg-amber-500' : 'bg-red-500'); ?>"
                 style="width: <?php echo min(100, $grand['payment_rate']); ?>%"></div>
        </div>
        <div class="flex justify-between text-[10px] text-gray-400 mt-1">
            <span>৳0</span>
            <span>Receivable: ৳<?php echo number_format($grand['receivable'], 0); ?></span>
        </div>
    </div>

    <!-- ── Filter Bar ────────────────────────────────────────────────────────── -->
    <form method="GET" class="bg-white border border-gray-100 rounded-xl shadow-sm mb-4">
        <div class="px-4 py-3 flex flex-wrap items-end gap-2">
            <div class="min-w-[200px] flex-1">
                <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Search Supplier</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="Type supplier name…"
                       class="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-xs focus:ring-2 focus:ring-indigo-400 outline-none">
            </div>
            <div class="min-w-[140px]">
                <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Sort By</label>
                <select name="sort" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-indigo-400 outline-none">
                    <option value="due_desc"     <?php echo $sort === 'due_desc'     ? 'selected' : ''; ?>>Highest Due First</option>
                    <option value="due_asc"      <?php echo $sort === 'due_asc'      ? 'selected' : ''; ?>>Lowest Due First</option>
                    <option value="advance_desc" <?php echo $sort === 'advance_desc' ? 'selected' : ''; ?>>Highest Advance First</option>
                    <option value="name_asc"     <?php echo $sort === 'name_asc'     ? 'selected' : ''; ?>>Name A–Z</option>
                </select>
            </div>
            <div class="min-w-[130px]">
                <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Scope</label>
                <select name="scope" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-indigo-400 outline-none">
                    <option value="active" <?php echo $scope === 'active' ? 'selected' : ''; ?>>Active (no closed)</option>
                    <option value="all"    <?php echo $scope === 'all'    ? 'selected' : ''; ?>>All POs</option>
                </select>
            </div>
            <div class="flex items-end gap-2 pb-0.5">
                <label class="flex items-center gap-1.5 text-xs text-gray-600 cursor-pointer">
                    <input type="checkbox" name="show_zero" <?php echo $show_zero ? 'checked' : ''; ?> class="rounded">
                    Show settled
                </label>
                <button type="submit" class="px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded-lg hover:bg-indigo-700 transition-colors">
                    <i class="fas fa-filter mr-1"></i> Apply
                </button>
                <a href="purchase_adnan_supllier_summary.php" class="px-3 py-1.5 border border-gray-200 text-gray-600 text-xs rounded-lg hover:bg-gray-50 transition-colors">
                    <i class="fas fa-times mr-1"></i> Clear
                </a>
            </div>
        </div>
    </form>

    <!-- ── Supplier Cards ────────────────────────────────────────────────────── -->
    <?php if (empty($suppliers)): ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-12 text-center">
        <i class="fas fa-balance-scale text-5xl text-gray-200 mb-3 block"></i>
        <p class="text-gray-400 text-sm">No suppliers with outstanding balance found.</p>
        <?php if ($search): ?>
        <a href="purchase_adnan_supplier_summary.php" class="text-indigo-500 text-xs hover:underline mt-2 inline-block">Clear search</a>
        <?php endif; ?>
    </div>
    <?php else: ?>

    <div class="space-y-3" x-data="balanceSummary()">
        <?php foreach ($suppliers as $idx => $sup): ?>
        <?php
            $has_due = $sup['total_due'] > 0.005;
            $has_adv = $sup['total_advance'] > 0.005;
            $net     = $sup['total_due'] - $sup['total_advance'];
            $pay_rate = $sup['total_receivable'] > 0
                ? min(100, $sup['total_paid'] / $sup['total_receivable'] * 100) : 0;
            $card_border = $has_due && !$has_adv ? 'border-red-200'
                : (!$has_due && $has_adv ? 'border-blue-200' : 'border-gray-200');
        ?>

        <div class="bg-white rounded-xl border <?php echo $card_border; ?> shadow-sm overflow-hidden">

            <!-- Card Header -->
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 cursor-pointer select-none hover:bg-gray-50 transition-colors"
                 onclick="toggleSupplier(<?php echo $idx; ?>)" id="hdr-<?php echo $idx; ?>">

                <!-- Avatar -->
                <div class="flex-shrink-0 w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold
                    <?php echo $has_due && !$has_adv ? 'bg-red-100 text-red-700'
                        : (!$has_due && $has_adv ? 'bg-blue-100 text-blue-700'
                        : 'bg-amber-100 text-amber-700'); ?>">
                    <?php echo mb_substr($sup['name'], 0, 1, 'UTF-8'); ?>
                </div>

                <!-- Name & PO count -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-semibold text-sm text-gray-800 truncate"><?php echo htmlspecialchars($sup['name']); ?></span>
                        <?php if ($sup['code'] !== '—'): ?>
                        <span class="text-[10px] text-gray-400 font-mono"><?php echo htmlspecialchars($sup['code']); ?></span>
                        <?php endif; ?>
                        <span class="px-1.5 py-0.5 text-[10px] bg-gray-100 text-gray-500 rounded-full font-medium">
                            <?php echo $sup['po_count']; ?> PO<?php echo $sup['po_count'] != 1 ? 's' : ''; ?>
                        </span>
                    </div>
                    <!-- Mini progress bar -->
                    <div class="mt-1 flex items-center gap-2">
                        <div class="flex-1 h-1 bg-gray-100 rounded-full max-w-[120px]">
                            <div class="h-1 rounded-full <?php echo $pay_rate >= 100 ? 'bg-green-400' : ($pay_rate >= 80 ? 'bg-amber-400' : 'bg-red-400'); ?>"
                                 style="width: <?php echo min(100, $pay_rate); ?>%"></div>
                        </div>
                        <span class="text-[10px] text-gray-400"><?php echo number_format($pay_rate, 0); ?>% paid</span>
                    </div>
                </div>

                <!-- Figures -->
                <div class="flex items-center gap-4 flex-wrap ml-auto">
                    <div class="text-right">
                        <p class="text-[10px] text-gray-400 font-medium">Receivable</p>
                        <p class="text-sm font-bold text-purple-600">৳<?php echo number_format($sup['total_receivable'], 2); ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] text-gray-400 font-medium">Paid</p>
                        <p class="text-sm font-bold text-green-600">৳<?php echo number_format($sup['total_paid'], 2); ?></p>
                    </div>
                    <?php if ($has_due): ?>
                    <div class="text-right min-w-[90px]">
                        <p class="text-[10px] text-red-400 font-semibold uppercase">We Owe</p>
                        <p class="text-base font-bold text-red-600">৳<?php echo number_format($sup['total_due'], 2); ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if ($has_adv): ?>
                    <div class="text-right min-w-[90px]">
                        <p class="text-[10px] text-blue-400 font-semibold uppercase">Advance</p>
                        <p class="text-base font-bold text-blue-600">৳<?php echo number_format($sup['total_advance'], 2); ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if ($has_due && $has_adv): ?>
                    <div class="text-right min-w-[90px]">
                        <p class="text-[10px] text-gray-400 font-semibold uppercase">Net</p>
                        <p class="text-base font-bold <?php echo $net > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                            <?php echo ($net > 0 ? 'DUE ' : 'ADV ') . '৳' . number_format(abs($net), 2); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    <?php if (!$has_due && !$has_adv): ?>
                    <span class="px-2 py-1 bg-green-50 text-green-600 text-xs font-semibold rounded-lg border border-green-100">
                        <i class="fas fa-check mr-1"></i>Settled
                    </span>
                    <?php endif; ?>

                    <i class="fas fa-chevron-down text-gray-400 text-xs transition-transform" id="chv-<?php echo $idx; ?>"></i>
                </div>

            </div>

            <!-- PO Detail Table (hidden by default) -->
            <div id="detail-<?php echo $idx; ?>" class="hidden border-t border-gray-100">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-gray-500 font-semibold uppercase tracking-wide whitespace-nowrap">PO #</th>
                                <th class="px-3 py-2 text-left text-gray-500 font-semibold uppercase tracking-wide whitespace-nowrap">Date</th>
                                <th class="px-3 py-2 text-left text-gray-500 font-semibold uppercase tracking-wide">Origin</th>
                                <th class="px-3 py-2 text-right text-gray-500 font-semibold uppercase tracking-wide whitespace-nowrap">Rate/KG</th>
                                <th class="px-3 py-2 text-right text-gray-500 font-semibold uppercase tracking-wide whitespace-nowrap">Exp. Qty (KG)</th>
                                <th class="px-3 py-2 text-right text-purple-400 font-semibold uppercase tracking-wide whitespace-nowrap">Payable (৳)</th>
                                <th class="px-3 py-2 text-right text-amber-500 font-semibold uppercase tracking-wide whitespace-nowrap">Adj (৳)</th>
                                <th class="px-3 py-2 text-right text-green-500 font-semibold uppercase tracking-wide whitespace-nowrap">Paid (৳)</th>
                                <th class="px-3 py-2 text-right text-gray-500 font-semibold uppercase tracking-wide whitespace-nowrap">Balance</th>
                                <th class="px-3 py-2 text-center text-gray-500 font-semibold uppercase tracking-wide whitespace-nowrap">Status</th>
                                <th class="px-3 py-2 text-center text-gray-500 font-semibold uppercase tracking-wide"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php foreach ($sup['pos'] as $po):
                                $bal = $po['balance'];
                                $is_due = $bal > 0.005;
                                $is_adv = $bal < -0.005;
                                $dl = $po['delivery'];
                                $pm = $po['payment'];
                                if ($dl === 'closed') {
                                    $badge = '<span class="px-1.5 py-0.5 rounded-full bg-purple-100 text-purple-700 font-semibold text-[10px] uppercase">Closed</span>';
                                } elseif ($dl === 'completed' && $pm === 'paid') {
                                    $badge = '<span class="px-1.5 py-0.5 rounded-full bg-emerald-100 text-emerald-700 font-semibold text-[10px] uppercase">Complete</span>';
                                } elseif ($dl === 'partial' || ($dl === 'completed' && $pm !== 'paid')) {
                                    $badge = '<span class="px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 font-semibold text-[10px] uppercase">In Progress</span>';
                                } elseif ($dl === 'over_received') {
                                    $badge = '<span class="px-1.5 py-0.5 rounded-full bg-blue-100 text-blue-700 font-semibold text-[10px] uppercase">Over Dlv.</span>';
                                } else {
                                    $badge = '<span class="px-1.5 py-0.5 rounded-full bg-gray-100 text-gray-600 font-semibold text-[10px] uppercase">Pending</span>';
                                }
                            ?>
                            <tr class="<?php echo $is_due ? 'hover:bg-red-50' : ($is_adv ? 'hover:bg-blue-50' : 'hover:bg-gray-50'); ?> transition-colors">
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <a href="purchase_adnan_view_po.php?id=<?php echo $po['po_id']; ?>"
                                       class="font-mono font-bold text-indigo-600 hover:text-indigo-800">
                                        #<?php echo htmlspecialchars($po['po_number']); ?>
                                    </a>
                                    <?php if ($po['adj_notes'] > 0): ?>
                                    <span class="ml-1 px-1 py-0.5 bg-amber-100 text-amber-700 text-[9px] rounded font-bold"><?php echo $po['adj_notes']; ?> adj</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2 text-gray-500 whitespace-nowrap">
                                    <?php echo date('d M Y', strtotime($po['po_date'])); ?>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <span class="px-1.5 py-0.5 rounded-full text-[10px] font-medium
                                        <?php echo $po['origin'] === 'কানাডা' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700'; ?>">
                                        <?php echo htmlspecialchars($po['origin']); ?>
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right text-gray-600 whitespace-nowrap font-mono">
                                    ৳<?php echo number_format($po['rate'], 4); ?>
                                </td>
                                <td class="px-3 py-2 text-right font-semibold text-gray-700 whitespace-nowrap font-mono">
                                    <?php echo number_format($po['expected_qty'], 3); ?>
                                </td>
                                <td class="px-3 py-2 text-right font-bold text-purple-600 whitespace-nowrap font-mono">
                                    ৳<?php echo number_format($po['payable'], 2); ?>
                                </td>
                                <td class="px-3 py-2 text-right whitespace-nowrap font-mono
                                    <?php echo $po['adj'] > 0 ? 'text-amber-600' : ($po['adj'] < 0 ? 'text-red-500' : 'text-gray-300'); ?>">
                                    <?php echo $po['adj'] != 0 ? (($po['adj'] > 0 ? '+' : '') . '৳' . number_format($po['adj'], 2)) : '—'; ?>
                                </td>
                                <td class="px-3 py-2 text-right font-semibold text-green-600 whitespace-nowrap font-mono">
                                    ৳<?php echo number_format($po['paid'], 2); ?>
                                </td>
                                <td class="px-3 py-2 text-right whitespace-nowrap font-mono">
                                    <?php if ($is_due): ?>
                                    <span class="font-bold text-red-600">DUE ৳<?php echo number_format($bal, 2); ?></span>
                                    <?php elseif ($is_adv): ?>
                                    <span class="font-bold text-blue-600">ADV ৳<?php echo number_format(abs($bal), 2); ?></span>
                                    <?php else: ?>
                                    <span class="text-emerald-500 font-semibold">✓ Settled</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2 text-center"><?php echo $badge; ?></td>
                                <td class="px-3 py-2 text-center">
                                    <a href="purchase_adnan_view_po.php?id=<?php echo $po['po_id']; ?>"
                                       class="text-gray-400 hover:text-indigo-600 transition-colors" title="View PO">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <!-- Supplier subtotal -->
                        <tfoot>
                            <tr class="bg-gray-50 border-t-2 border-gray-200 font-semibold text-xs">
                                <td colspan="4" class="px-3 py-2 text-right text-gray-600 uppercase tracking-wide">
                                    <i class="fas fa-calculator mr-1 text-gray-400"></i> Supplier Total
                                </td>
                                <td class="px-3 py-2 text-right text-gray-700 font-mono">
                                    <?php echo number_format(array_sum(array_column($sup['pos'], 'expected_qty')), 3); ?>
                                </td>
                                <td class="px-3 py-2 text-right text-purple-600 font-mono">
                                    ৳<?php echo number_format($sup['total_receivable'], 2); ?>
                                </td>
                                <td class="px-3 py-2 text-right <?php echo $sup['total_adj'] != 0 ? 'text-amber-600' : 'text-gray-300'; ?> font-mono">
                                    <?php echo $sup['total_adj'] != 0 ? (($sup['total_adj'] > 0 ? '+' : '') . '৳' . number_format($sup['total_adj'], 2)) : '—'; ?>
                                </td>
                                <td class="px-3 py-2 text-right text-green-600 font-mono">
                                    ৳<?php echo number_format($sup['total_paid'], 2); ?>
                                </td>
                                <td class="px-3 py-2 text-right font-mono">
                                    <?php if ($has_due && $has_adv): ?>
                                    <span class="text-<?php echo $net > 0 ? 'red' : 'green'; ?>-600">
                                        NET <?php echo ($net > 0 ? 'DUE' : 'ADV'); ?> ৳<?php echo number_format(abs($net), 2); ?>
                                    </span>
                                    <?php elseif ($has_due): ?>
                                    <span class="text-red-600">DUE ৳<?php echo number_format($sup['total_due'], 2); ?></span>
                                    <?php elseif ($has_adv): ?>
                                    <span class="text-blue-600">ADV ৳<?php echo number_format($sup['total_advance'], 2); ?></span>
                                    <?php else: ?>
                                    <span class="text-emerald-500">✓ Settled</span>
                                    <?php endif; ?>
                                </td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

        </div>
        <?php endforeach; ?>

        <!-- ── Grand Total Row ──────────────────────────────────────────────── -->
        <div class="bg-gray-900 text-white rounded-xl px-5 py-4 flex flex-wrap items-center gap-4 justify-between">
            <div>
                <p class="text-xs text-gray-400 font-semibold uppercase tracking-wide">Grand Total — <?php echo $grand['suppliers']; ?> Suppliers, <?php echo $grand['pos']; ?> POs</p>
            </div>
            <div class="flex flex-wrap gap-6">
                <div class="text-right">
                    <p class="text-[10px] text-gray-400 uppercase tracking-wide">Receivable</p>
                    <p class="text-sm font-bold text-purple-300">৳<?php echo number_format($grand['receivable'], 2); ?></p>
                </div>
                <div class="text-right">
                    <p class="text-[10px] text-gray-400 uppercase tracking-wide">Total Paid</p>
                    <p class="text-sm font-bold text-green-300">৳<?php echo number_format($grand['paid'], 2); ?></p>
                </div>
                <div class="text-right">
                    <p class="text-[10px] text-red-400 uppercase tracking-wide font-semibold">We Owe</p>
                    <p class="text-base font-bold text-red-400">৳<?php echo number_format($grand['due'], 2); ?></p>
                </div>
                <div class="text-right">
                    <p class="text-[10px] text-blue-400 uppercase tracking-wide font-semibold">Advance</p>
                    <p class="text-base font-bold text-blue-400">৳<?php echo number_format($grand['advance'], 2); ?></p>
                </div>
                <div class="text-right">
                    <p class="text-[10px] text-<?php echo $grand['net'] > 0 ? 'red' : 'green'; ?>-400 uppercase tracking-wide font-semibold">Net</p>
                    <p class="text-base font-bold text-<?php echo $grand['net'] > 0 ? 'red' : 'green'; ?>-400">
                        <?php echo ($grand['net'] > 0 ? 'DUE ' : 'ADV ') . '৳' . number_format(abs($grand['net']), 2); ?>
                    </p>
                </div>
            </div>
        </div>

    </div>
    <?php endif; ?>

</div>

<script>
function toggleSupplier(idx) {
    const detail = document.getElementById('detail-' + idx);
    const chevron = document.getElementById('chv-' + idx);
    if (detail.classList.contains('hidden')) {
        detail.classList.remove('hidden');
        chevron.style.transform = 'rotate(180deg)';
    } else {
        detail.classList.add('hidden');
        chevron.style.transform = 'rotate(0deg)';
    }
}

function balanceSummary() { return {}; }

// Auto-expand the first supplier with dues on load
document.addEventListener('DOMContentLoaded', function() {
    const firstDue = document.querySelector('[id^="hdr-"]');
    if (firstDue) {
        const idx = firstDue.id.replace('hdr-', '');
        const detail = document.getElementById('detail-' + idx);
        const chevron = document.getElementById('chv-' + idx);
        if (detail) {
            detail.classList.remove('hidden');
            if (chevron) chevron.style.transform = 'rotate(180deg)';
        }
    }
});
</script>

<?php include '../templates/footer.php'; ?>
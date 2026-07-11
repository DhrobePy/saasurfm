<?php
/**
 * Purchase (Adnan) Module - Dashboard
 * Main overview page for wheat procurement system
 * FULLY RECTIFIED VERSION - All calculations based on EXPECTED QUANTITY
 *
 * @package Ujjal Flour Mills
 * @subpackage Purchase (Adnan) Module
 */

require_once '../core/init.php';
require_once '../core/config/config.php';
require_once '../core/classes/Database.php';
require_once '../core/functions/helpers.php';
require_once '../core/classes/Purchaseadnanmanager.php';

restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle = "Purchase (Adnan) - Dashboard";

$purchaseManager = new PurchaseAdnanManager();
$current_user    = getCurrentUser();
$is_superadmin   = ($current_user['role'] === 'Superadmin');

// ── Filters ─────────────────────────────────────────────────
$filters = [
    'date_from'           => $_GET['date_from']           ?? '',
    'date_to'             => $_GET['date_to']             ?? '',
    'supplier_id'         => $_GET['supplier_id']         ?? '',
    'wheat_origin'        => $_GET['wheat_origin']        ?? '',
    'delivery_status'     => $_GET['delivery_status']     ?? '',
    'payment_status'      => $_GET['payment_status']      ?? '',
    'po_status'           => $_GET['po_status']           ?? '',
    'order_status_filter' => $_GET['order_status_filter'] ?? '',
    'search'              => $_GET['search']              ?? '',
    'limit'               => 50,
];

$filters = array_filter($filters, fn($v) => $v !== '' && $v !== null);

// ── Default to In Progress if no user filters ─────────────
$user_has_filters = !empty(array_filter($filters, function($key) {
    return !in_array($key, ['limit', 'order_status_filter']);
}, ARRAY_FILTER_USE_KEY));

if (!$user_has_filters && !isset($_GET['order_status_filter'])) {
    $filters['show_in_progress']    = true;
    $filters['order_status_filter'] = 'in_progress';
}

// ── Stats & Data ─────────────────────────────────────────────
$stats            = $purchaseManager->getDashboardStats();
$all_suppliers    = $purchaseManager->getAllSuppliers();
$supplier_summary = $purchaseManager->getSupplierSummary();
$stats_by_origin  = $purchaseManager->getStatsByOrigin();
$recent_orders    = $purchaseManager->listPurchaseOrders($filters);

// ── AI Context (built once in PHP, passed to JS) ──────────
// In-progress orders needing attention
$in_progress_orders = $purchaseManager->listPurchaseOrders([
    'order_status_filter' => 'in_progress',
    'limit' => 100,
]);

// Build per-supplier dues from in-progress orders
$supplier_dues = [];
foreach ($in_progress_orders as $o) {
    $exp_qty  = getExpectedQuantityForPO($o->id);
    $exp_pay  = $exp_qty * $o->unit_price_per_kg;
    $bal      = $exp_pay - ($o->total_paid ?? 0);
    $due      = $bal > 0 ? $bal : 0;
    $adv      = $bal < 0 ? abs($bal) : 0;
    $name     = $o->supplier_name;
    if (!isset($supplier_dues[$name])) {
        $supplier_dues[$name] = ['due' => 0, 'advance' => 0, 'orders' => 0];
    }
    $supplier_dues[$name]['due']     += $due;
    $supplier_dues[$name]['advance'] += $adv;
    $supplier_dues[$name]['orders']  += 1;
}
arsort($supplier_dues);

// Origin cost breakdown
$origin_context = [];
foreach ($stats_by_origin as $o) {
    $origin_context[] = [
        'origin'    => $o->wheat_origin,
        'orders'    => (int)$o->order_count,
        'qty_kg'    => (float)$o->total_quantity,
        'value'     => (float)$o->total_value,
        'avg_price' => round((float)$o->avg_price, 2),
    ];
}

// Recent payment activity (last 30 days)
$db_pdo = Database::getInstance()->getPdo();
$recent_pay_stmt = $db_pdo->prepare(
    "SELECT COALESCE(SUM(amount_paid),0) as paid_30d,
            COUNT(*) as payment_count
     FROM purchase_payments_adnan
     WHERE is_posted=1 AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
);
$recent_pay_stmt->execute();
$recent_pay = $recent_pay_stmt->fetch(PDO::FETCH_OBJ);

// Pending deliveries count
$pending_del_stmt = $db_pdo->query(
    "SELECT COUNT(*) as cnt FROM purchase_orders_adnan
     WHERE delivery_status IN ('pending','partial') AND po_status != 'cancelled'"
);
$pending_del = $pending_del_stmt->fetch(PDO::FETCH_OBJ);

// Orders with highest due (top 5)
$top_due = array_slice($supplier_dues, 0, 5, true);

$aiContext = [
    'total_orders'       => (int)($stats->total_orders ?? 0),
    'total_order_value'  => (float)($stats->total_order_value ?? 0),
    'expected_payable'   => (float)($stats->expected_payable ?? 0),
    'total_paid'         => (float)($stats->total_paid ?? 0),
    'balance_due'        => (float)($stats->actual_balance_due ?? 0),
    'total_advance'      => (float)($stats->total_advance ?? 0),
    'payment_rate_pct'   => $stats->expected_payable > 0
                              ? round($stats->total_paid / $stats->expected_payable * 100, 1) : 0,
    'in_progress_count'  => count($in_progress_orders),
    'pending_deliveries' => (int)($pending_del->cnt ?? 0),
    'paid_last_30d'      => (float)($recent_pay->paid_30d ?? 0),
    'payments_last_30d'  => (int)($recent_pay->payment_count ?? 0),
    'supplier_dues'      => $top_due,
    'origins'            => $origin_context,
];

// ── Helper ────────────────────────────────────────────────────
function getExpectedQuantityForPO($po_id) {
    static $cache = [];
    if (isset($cache[$po_id])) return $cache[$po_id];
    $db   = Database::getInstance()->getPdo();
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(expected_quantity),0) as total_expected
         FROM goods_received_adnan
         WHERE purchase_order_id=? AND grn_status != 'cancelled'"
    );
    $stmt->execute([$po_id]);
    $result = $stmt->fetch(PDO::FETCH_OBJ);
    return $cache[$po_id] = ($result->total_expected ?? 0);
}

// ── Totals for visible rows ───────────────────────────────────
$totals = ['ordered_quantity' => 0, 'total_value' => 0, 'goods_received' => 0,
           'expected_payable' => 0, 'paid' => 0, 'due' => 0, 'advance' => 0];

foreach ($recent_orders as $order) {
    $expected_qty     = getExpectedQuantityForPO($order->id);
    $expected_payable = $expected_qty * $order->unit_price_per_kg;
    $balance          = $expected_payable - ($order->total_paid ?? 0);
    $totals['ordered_quantity'] += $order->quantity_kg;
    $totals['total_value']      += $order->total_order_value;
    $totals['goods_received']   += ($order->total_received_qty ?? 0);
    $totals['expected_payable'] += $expected_payable;
    $totals['paid']             += ($order->total_paid ?? 0);
    if ($balance > 0) $totals['due']     += $balance;
    else              $totals['advance'] += abs($balance);
}

// Active filters (excluding meta keys)
$active_filters = array_filter($filters, fn($k) =>
    !in_array($k, ['limit', 'show_in_progress', 'order_status_filter']), ARRAY_FILTER_USE_KEY);

// Current status label
$status_labels = [
    'in_progress' => ['📋 In Progress',  'text-amber-600',  'bg-amber-50 border-amber-200'],
    'all_active'  => ['✅ All Active',    'text-green-700',  'bg-green-50  border-green-200'],
    'completed'   => ['✓ Completed',      'text-emerald-700','bg-emerald-50 border-emerald-200'],
    'closed'      => ['🔒 Closed',        'text-purple-700', 'bg-purple-50 border-purple-200'],
    'cancelled'   => ['❌ Cancelled',     'text-red-700',    'bg-red-50   border-red-200'],
    'all'         => ['📊 All Orders',    'text-gray-600',   'bg-gray-50  border-gray-200'],
];
$cur_status = $filters['order_status_filter'] ?? 'in_progress';
[$sl_label, $sl_text, $sl_bg] = $status_labels[$cur_status] ?? $status_labels['in_progress'];

$payment_rate = ($stats->expected_payable ?? 0) > 0
    ? min(100, ($stats->total_paid / $stats->expected_payable * 100))
    : 0;

include '../templates/header.php';
?>

<div x-data="purchaseAiApp()">
<div class="w-full px-4 sm:px-6 lg:px-8 py-5">

    <!-- ── Page Header ─────────────────────────────────────── -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <div>
            <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-wheat text-amber-500"></i> Wheat Purchase
                <span class="px-2 py-0.5 text-xs rounded-full border font-medium <?php echo $sl_bg . ' ' . $sl_text; ?>">
                    <?php echo $sl_label; ?>
                </span>
            </h1>
            <p class="text-xs text-gray-400 mt-0.5">Wheat Procurement Management · <?php echo count($recent_orders); ?> orders shown</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <a href="purchase_adnan_record_grn.php"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                <i class="fas fa-truck"></i> Record GRN
            </a>
            <a href="purchase_adnan_record_payment.php"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                <i class="fas fa-credit-card"></i> Record Payment
            </a>
            <a href="variance_report.php"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-orange-500 text-white rounded-lg hover:bg-orange-600 transition-colors">
                <i class="fas fa-chart-line"></i> Variance
            </a>
            <a href="purchase_adnan_supplier_summary.php"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors">
                <i class="fas fa-chart-bar"></i> Suppliers
            </a>
            <a href="purchase_adnan_create_po.php"
               class="inline-flex items-center gap-1.5 px-4 py-1.5 text-xs font-semibold bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition-colors shadow-sm">
                <i class="fas fa-plus"></i> New PO
            </a>
        </div>
    </div>

    <!-- ── KPI Strip ───────────────────────────────────────── -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">

        <div class="bg-white border border-gray-100 rounded-xl shadow-sm p-3">
            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Total Orders</p>
            <p class="text-xl font-bold text-gray-800 mt-0.5"><?php echo number_format($stats->total_orders ?? 0); ?></p>
            <p class="text-[10px] text-gray-400 mt-0.5">
                <?php echo $stats->completed_deliveries ?? 0; ?> done ·
                <?php echo $stats->closed_deals ?? 0; ?> closed
            </p>
        </div>

        <div class="bg-white border border-purple-100 rounded-xl shadow-sm p-3">
            <p class="text-[10px] font-semibold text-purple-400 uppercase tracking-wide">Expected Payable</p>
            <p class="text-xl font-bold text-purple-700 mt-0.5">
                ৳<?php echo number_format(($stats->expected_payable ?? 0) / 1000000, 2); ?>M
            </p>
            <p class="text-[10px] text-gray-400 mt-0.5">Based on GRN expected qty</p>
        </div>

        <div class="bg-white border border-green-100 rounded-xl shadow-sm p-3">
            <p class="text-[10px] font-semibold text-green-500 uppercase tracking-wide">Total Paid</p>
            <p class="text-xl font-bold text-green-700 mt-0.5">
                ৳<?php echo number_format(($stats->total_paid ?? 0) / 1000000, 2); ?>M
            </p>
            <div class="mt-1.5 w-full bg-gray-100 rounded-full h-1">
                <div class="bg-green-500 h-1 rounded-full" style="width:<?php echo min(100, $payment_rate); ?>%"></div>
            </div>
            <p class="text-[10px] text-gray-400 mt-0.5"><?php echo number_format($payment_rate, 1); ?>% of payable</p>
        </div>

        <div class="bg-white border border-red-100 rounded-xl shadow-sm p-3">
            <p class="text-[10px] font-semibold text-red-400 uppercase tracking-wide">Balance Due</p>
            <p class="text-xl font-bold text-red-600 mt-0.5">
                ৳<?php echo number_format(($stats->actual_balance_due ?? 0) / 1000000, 2); ?>M
            </p>
            <p class="text-[10px] text-gray-400 mt-0.5">Expected − Paid</p>
        </div>

        <div class="bg-white border border-blue-100 rounded-xl shadow-sm p-3">
            <p class="text-[10px] font-semibold text-blue-400 uppercase tracking-wide">Advance Paid</p>
            <p class="text-xl font-bold text-blue-700 mt-0.5">
                ৳<?php echo number_format(($stats->total_advance ?? 0) / 1000000, 2); ?>M
            </p>
            <p class="text-[10px] text-gray-400 mt-0.5">Pre-delivery payments</p>
        </div>

        <div class="bg-white border border-amber-100 rounded-xl shadow-sm p-3">
            <p class="text-[10px] font-semibold text-amber-500 uppercase tracking-wide">Order Value</p>
            <p class="text-xl font-bold text-amber-700 mt-0.5">
                ৳<?php echo number_format(($stats->total_order_value ?? 0) / 1000000, 2); ?>M
            </p>
            <p class="text-[10px] text-gray-400 mt-0.5">Total contracted value</p>
        </div>

    </div>

    <!-- ── Filter Bar ──────────────────────────────────────── -->
    <div class="bg-white border border-gray-100 rounded-xl shadow-sm mb-4">
        <form method="GET" action="" id="filterForm">
            <div class="px-4 py-3 flex flex-wrap items-end gap-2">

                <!-- Order Status — most prominent -->
                <div class="min-w-[160px]">
                    <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Order Status</label>
                    <select name="order_status_filter" onchange="this.form.submit()"
                            class="w-full border border-blue-300 bg-blue-50 text-blue-800 rounded-lg px-2 py-1.5 text-xs font-semibold focus:ring-2 focus:ring-blue-400 outline-none">
                        <option value="in_progress" <?php echo $cur_status === 'in_progress' ? 'selected' : ''; ?>>📋 In Progress (Default)</option>
                        <option value="all_active"  <?php echo $cur_status === 'all_active'  ? 'selected' : ''; ?>>✅ All Active</option>
                        <option value="completed"   <?php echo $cur_status === 'completed'   ? 'selected' : ''; ?>>✓ Completed</option>
                        <option value="closed"      <?php echo $cur_status === 'closed'      ? 'selected' : ''; ?>>🔒 Closed</option>
                        <option value="cancelled"   <?php echo $cur_status === 'cancelled'   ? 'selected' : ''; ?>>❌ Cancelled</option>
                        <option value="all"         <?php echo $cur_status === 'all'         ? 'selected' : ''; ?>>📊 All Orders</option>
                    </select>
                </div>

                <!-- Supplier -->
                <div class="min-w-[150px]">
                    <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Supplier</label>
                    <select name="supplier_id"
                            class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-primary-400 outline-none">
                        <option value="">All Suppliers</option>
                        <?php foreach ($all_suppliers as $s): ?>
                        <option value="<?php echo $s->id; ?>" <?php echo ($filters['supplier_id'] ?? '') == $s->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s->name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Wheat Origin -->
                <div class="min-w-[120px]">
                    <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Origin</label>
                    <select name="wheat_origin"
                            class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-primary-400 outline-none">
                        <option value="">All Origins</option>
                        <?php foreach (['কানাডা'=>'কানাডা (Canada)','রাশিয়া'=>'রাশিয়া (Russia)','Australia'=>'Australia','Ukraine'=>'Ukraine','India'=>'India','Argentina'=>'Argentina','Brazil'=>'Brazil','Other'=>'Other'] as $v=>$l): ?>
                        <option value="<?php echo $v; ?>" <?php echo ($filters['wheat_origin'] ?? '') == $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Delivery Status -->
                <div class="min-w-[115px]">
                    <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Delivery</label>
                    <select name="delivery_status"
                            class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-primary-400 outline-none">
                        <option value="">All</option>
                        <option value="pending"   <?php echo ($filters['delivery_status'] ?? '') === 'pending'   ? 'selected' : ''; ?>>Pending</option>
                        <option value="partial"   <?php echo ($filters['delivery_status'] ?? '') === 'partial'   ? 'selected' : ''; ?>>Partial</option>
                        <option value="completed" <?php echo ($filters['delivery_status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="closed"    <?php echo ($filters['delivery_status'] ?? '') === 'closed'    ? 'selected' : ''; ?>>Closed</option>
                    </select>
                </div>

                <!-- Payment Status -->
                <div class="min-w-[115px]">
                    <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Payment</label>
                    <select name="payment_status"
                            class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-primary-400 outline-none">
                        <option value="">All</option>
                        <option value="unpaid"  <?php echo ($filters['payment_status'] ?? '') === 'unpaid'  ? 'selected' : ''; ?>>Unpaid</option>
                        <option value="partial" <?php echo ($filters['payment_status'] ?? '') === 'partial' ? 'selected' : ''; ?>>Partial</option>
                        <option value="paid"    <?php echo ($filters['payment_status'] ?? '') === 'paid'    ? 'selected' : ''; ?>>Paid</option>
                    </select>
                </div>

                <!-- Date From -->
                <div class="min-w-[115px]">
                    <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Date From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>"
                           class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-primary-400 outline-none">
                </div>

                <!-- Date To -->
                <div class="min-w-[115px]">
                    <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Date To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to'] ?? ''); ?>"
                           class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-primary-400 outline-none">
                </div>

                <!-- Search -->
                <div class="min-w-[140px] flex-1">
                    <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Search</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search'] ?? ''); ?>"
                           placeholder="PO number, supplier..."
                           class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-primary-400 outline-none">
                </div>

                <!-- Buttons -->
                <div class="flex items-end gap-1.5 pb-0.5">
                    <button type="submit"
                            class="inline-flex items-center gap-1 px-3 py-1.5 bg-primary-600 text-white text-xs font-semibold rounded-lg hover:bg-primary-700 transition-colors">
                        <i class="fas fa-search text-[10px]"></i> Apply
                    </button>
                    <a href="purchase_adnan_index.php"
                       class="inline-flex items-center gap-1 px-3 py-1.5 border border-gray-200 text-gray-600 text-xs rounded-lg hover:bg-gray-50 transition-colors">
                        <i class="fas fa-times text-[10px]"></i> Clear
                    </a>
                </div>

            </div>

            <?php if (!empty($active_filters)): ?>
            <div class="px-4 pb-2 flex items-center gap-1.5 flex-wrap">
                <span class="text-[10px] text-gray-400 font-medium">Active:</span>
                <?php foreach ($active_filters as $k => $v):
                    $label = str_replace('_', ' ', ucfirst($k)); ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-primary-50 text-primary-700 text-[10px] rounded-full border border-primary-200">
                    <?php echo htmlspecialchars($label . ': ' . $v); ?>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- ── Purchase Orders Table ────────────────────────────── -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">

        <div class="px-4 py-2.5 border-b border-gray-100 flex items-center justify-between">
            <span class="text-xs font-semibold text-gray-700">
                <?php echo !empty($active_filters) ? 'Filtered' : 'Default'; ?> Purchase Orders
                <span class="ml-1 text-gray-400 font-normal">(<?php echo number_format(count($recent_orders)); ?> result<?php echo count($recent_orders) != 1 ? 's' : ''; ?>)</span>
            </span>
            <?php if (!empty($active_filters)): ?>
            <a href="purchase_adnan_index.php" class="text-[10px] text-gray-400 hover:text-red-500 transition-colors">
                <i class="fas fa-times mr-0.5"></i> Clear filters
            </a>
            <?php endif; ?>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="px-3 py-2.5 text-left font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">PO #</th>
                        <th class="px-3 py-2.5 text-left font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Date</th>
                        <th class="px-3 py-2.5 text-left font-semibold text-gray-500 uppercase tracking-wide">Supplier</th>
                        <th class="px-3 py-2.5 text-left font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Origin</th>
                        <th class="px-3 py-2.5 text-right font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Ordered (KG)</th>
                        <th class="px-3 py-2.5 text-right font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Rate/KG</th>
                        <th class="px-3 py-2.5 text-right font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Received (KG)</th>
                        <th class="px-3 py-2.5 text-right font-semibold text-purple-500 uppercase tracking-wide whitespace-nowrap">Payable (৳)</th>
                        <th class="px-3 py-2.5 text-right font-semibold text-green-600 uppercase tracking-wide whitespace-nowrap">Paid (৳)</th>
                        <th class="px-3 py-2.5 text-right font-semibold text-red-500 uppercase tracking-wide whitespace-nowrap">Due (৳)</th>
                        <th class="px-3 py-2.5 text-right font-semibold text-blue-500 uppercase tracking-wide whitespace-nowrap">Advance (৳)</th>
                        <th class="px-3 py-2.5 text-center font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Status</th>
                        <th class="px-3 py-2.5 text-center font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">

                    <?php if (empty($recent_orders)): ?>
                    <tr>
                        <td colspan="13" class="px-4 py-10 text-center text-gray-400">
                            <i class="fas fa-box-open text-3xl mb-2 block opacity-30"></i>
                            <?php if (!empty($active_filters)): ?>
                                No purchase orders match your filters.
                                <a href="purchase_adnan_index.php" class="text-primary-600 hover:underline ml-1">Clear filters</a>
                            <?php else: ?>
                                No in-progress orders.
                                <a href="purchase_adnan_create_po.php" class="text-primary-600 hover:underline ml-1">Create a PO</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>

                    <?php foreach ($recent_orders as $order):
                        $expected_qty     = getExpectedQuantityForPO($order->id);
                        $expected_payable = $expected_qty * $order->unit_price_per_kg;
                        $balance          = $expected_payable - ($order->total_paid ?? 0);
                        $actual_due       = $balance > 0 ? $balance : 0;
                        $advance_paid     = $balance < 0 ? abs($balance) : 0;
                        $per_unit         = $order->quantity_kg > 0 ? ($order->total_order_value / $order->quantity_kg) : 0;
                        $received         = $order->total_received_qty ?? 0;

                        // Status badge
                        $delivery = $order->delivery_status;
                        $payment  = $order->payment_status;
                        if ($delivery === 'closed') {
                            $badge_text = 'Closed'; $badge_cls = 'bg-purple-100 text-purple-700';
                        } elseif ($delivery === 'completed' && $payment === 'paid') {
                            $badge_text = 'Complete'; $badge_cls = 'bg-emerald-100 text-emerald-700';
                        } elseif ($delivery === 'over_received') {
                            $badge_text = 'Over Delv.'; $badge_cls = 'bg-blue-100 text-blue-700';
                        } elseif ($delivery === 'partial' || $payment === 'partial' || ($delivery === 'completed' && $payment !== 'paid')) {
                            $badge_text = 'In Progress'; $badge_cls = 'bg-amber-100 text-amber-700';
                        } else {
                            $badge_text = 'Pending'; $badge_cls = 'bg-slate-100 text-slate-600';
                        }

                        // Row highlight for due
                        $row_cls = $actual_due > 0 ? 'hover:bg-red-50' : 'hover:bg-gray-50';
                    ?>
                    <tr class="<?php echo $row_cls; ?> transition-colors">

                        <td class="px-3 py-2 whitespace-nowrap">
                            <a href="purchase_adnan_view_po.php?id=<?php echo $order->id; ?>"
                               class="font-mono font-semibold text-primary-600 hover:text-primary-800">
                                #<?php echo htmlspecialchars($order->po_number); ?>
                            </a>
                        </td>

                        <td class="px-3 py-2 whitespace-nowrap text-gray-500">
                            <?php echo date('d M Y', strtotime($order->po_date)); ?>
                        </td>

                        <td class="px-3 py-2 text-gray-800 font-medium max-w-[160px] truncate">
                            <?php echo htmlspecialchars($order->supplier_name); ?>
                        </td>

                        <td class="px-3 py-2 whitespace-nowrap">
                            <span class="px-1.5 py-0.5 rounded-full font-medium <?php echo $order->wheat_origin === 'কানাডা' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700'; ?>">
                                <?php echo htmlspecialchars($order->wheat_origin); ?>
                            </span>
                        </td>

                        <td class="px-3 py-2 text-right font-semibold text-gray-700 whitespace-nowrap">
                            <?php echo number_format($order->quantity_kg, 0); ?>
                        </td>

                        <td class="px-3 py-2 text-right text-gray-600 whitespace-nowrap">
                            ৳<?php echo number_format($per_unit, 2); ?>
                        </td>

                        <td class="px-3 py-2 text-right whitespace-nowrap font-semibold
                            <?php if ($received >= $order->quantity_kg) echo 'text-green-600';
                                  elseif ($received > 0)                 echo 'text-amber-600';
                                  else                                   echo 'text-gray-300'; ?>">
                            <?php echo number_format($received, 0); ?>
                        </td>

                        <td class="px-3 py-2 text-right whitespace-nowrap font-bold
                            <?php echo $expected_payable > 0 ? 'text-purple-600' : 'text-gray-300'; ?>">
                            <?php echo $expected_payable > 0 ? '৳' . number_format($expected_payable, 0) : '—'; ?>
                        </td>

                        <td class="px-3 py-2 text-right whitespace-nowrap font-semibold text-green-600">
                            ৳<?php echo number_format($order->total_paid ?? 0, 0); ?>
                        </td>

                        <td class="px-3 py-2 text-right whitespace-nowrap font-semibold">
                            <?php if ($actual_due > 0): ?>
                                <span class="text-red-600">৳<?php echo number_format($actual_due, 0); ?></span>
                            <?php else: ?>
                                <span class="text-gray-200">—</span>
                            <?php endif; ?>
                        </td>

                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <?php if ($advance_paid > 0): ?>
                                <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-blue-50 text-blue-700 font-bold">
                                    <i class="fas fa-arrow-up text-[8px]"></i>৳<?php echo number_format($advance_paid, 0); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-gray-200">—</span>
                            <?php endif; ?>
                        </td>

                        <td class="px-3 py-2 text-center whitespace-nowrap">
                            <span class="px-2 py-0.5 rounded-full font-semibold uppercase tracking-wider text-[10px] <?php echo $badge_cls; ?>">
                                <?php echo $badge_text; ?>
                            </span>
                        </td>

                        <td class="px-3 py-2 text-center whitespace-nowrap">
                            <div class="flex items-center justify-center gap-2">
                                <a href="purchase_adnan_view_po.php?id=<?php echo $order->id; ?>"
                                   class="text-gray-400 hover:text-primary-600 transition-colors" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($is_superadmin): ?>
                                <a href="purchase_adnan_edit_po.php?id=<?php echo $order->id; ?>"
                                   class="text-gray-400 hover:text-yellow-600 transition-colors" title="Edit">
                                    <i class="fas fa-pencil-alt"></i>
                                </a>
                                <?php if ($order->delivery_status !== 'closed' && $order->delivery_status !== 'completed'): ?>
                                <button onclick="closePO(<?php echo $order->id; ?>, '<?php echo htmlspecialchars($order->po_number, ENT_QUOTES); ?>')"
                                        class="text-gray-400 hover:text-orange-600 transition-colors" title="Close Deal">
                                    <i class="fas fa-lock"></i>
                                </button>
                                <?php endif; ?>
                                <button onclick="deletePO(<?php echo $order->id; ?>, '<?php echo htmlspecialchars($order->po_number, ENT_QUOTES); ?>')"
                                        class="text-gray-400 hover:text-red-600 transition-colors" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>

                    </tr>
                    <?php endforeach; ?>

                    <!-- ── Totals Row ── -->
                    <tr class="bg-gray-50 border-t-2 border-gray-200">
                        <td colspan="4" class="px-3 py-2.5 text-right text-xs font-bold text-gray-600 uppercase tracking-wide">
                            <i class="fas fa-calculator mr-1 text-gray-400"></i> Totals
                        </td>
                        <td class="px-3 py-2.5 text-right text-xs font-bold text-gray-700 whitespace-nowrap">
                            <?php echo number_format($totals['ordered_quantity'], 0); ?>
                        </td>
                        <td class="px-3 py-2.5 text-right text-xs font-semibold text-gray-600 whitespace-nowrap">
                            ৳<?php echo $totals['ordered_quantity'] > 0 ? number_format($totals['total_value'] / $totals['ordered_quantity'], 2) : '0.00'; ?>
                        </td>
                        <td class="px-3 py-2.5 text-right text-xs font-bold text-gray-700 whitespace-nowrap">
                            <?php echo number_format($totals['goods_received'], 0); ?>
                        </td>
                        <td class="px-3 py-2.5 text-right text-xs font-bold text-purple-600 whitespace-nowrap">
                            ৳<?php echo number_format($totals['expected_payable'], 0); ?>
                        </td>
                        <td class="px-3 py-2.5 text-right text-xs font-bold text-green-600 whitespace-nowrap">
                            ৳<?php echo number_format($totals['paid'], 0); ?>
                        </td>
                        <td class="px-3 py-2.5 text-right text-xs font-bold text-red-600 whitespace-nowrap">
                            <?php echo $totals['due'] > 0 ? '৳' . number_format($totals['due'], 0) : '<span class="text-gray-300">—</span>'; ?>
                        </td>
                        <td class="px-3 py-2.5 text-right text-xs font-bold text-blue-600 whitespace-nowrap">
                            <?php echo $totals['advance'] > 0 ? '৳' . number_format($totals['advance'], 0) : '<span class="text-gray-300">—</span>'; ?>
                        </td>
                        <td colspan="2" class="px-3 py-2.5"></td>
                    </tr>

                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /main content padding -->

<?php if (in_array($current_user['role'], ['Superadmin', 'admin'])): ?>
<?php /* x-data div stays open — modal+FAB are inside Alpine scope */ ?>

<!-- ══ AI SUITE MODAL ════════════════════════════════════════════════════════ -->

<!-- Backdrop -->
<div x-show="aiOpen"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @click="aiOpen=false"
     class="fixed inset-0 bg-black/40 z-[100]"
     style="display:none;"></div>

<!-- Modal panel -->
<div x-show="aiOpen"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-full"
     x-transition:enter-end="opacity-100 translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 translate-x-0"
     x-transition:leave-end="opacity-0 translate-x-full"
     @click.stop
     class="fixed top-0 right-0 h-full w-full sm:w-[480px] bg-white flex flex-col shadow-2xl z-[101]"
     style="display:none;">

    <!-- Header -->
    <div class="bg-gradient-to-r from-purple-700 to-indigo-700 px-4 py-3 flex items-center justify-between flex-shrink-0">
        <div class="flex items-center gap-2">
            <div class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center">
                <i class="fas fa-robot text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-bold text-sm">AI Procurement Suite</h2>
                <p class="text-purple-200 text-xs">Live ERP · Multi-provider AI fallback</p>
            </div>
        </div>
        <button @click="aiOpen=false" class="text-white/70 hover:text-white p-1"><i class="fas fa-times text-lg"></i></button>
    </div>

    <!-- Tab bar -->
    <div class="flex border-b border-gray-200 bg-gray-50 flex-shrink-0">
        <button @click="activeTab='insights'"
                :class="activeTab==='insights' ? 'border-b-2 border-purple-600 text-purple-700 bg-white font-bold' : 'text-gray-500 hover:text-gray-700'"
                class="flex-1 py-2.5 text-xs uppercase tracking-wide transition">
            <i class="fas fa-chart-pie mr-1"></i>Insights
        </button>
        <button @click="activeTab='query'"
                :class="activeTab==='query' ? 'border-b-2 border-blue-600 text-blue-700 bg-white font-bold' : 'text-gray-500 hover:text-gray-700'"
                class="flex-1 py-2.5 text-xs uppercase tracking-wide transition">
            <i class="fas fa-database mr-1"></i>Query DB
        </button>
        <button @click="activeTab='agent'"
                :class="activeTab==='agent' ? 'border-b-2 border-green-600 text-green-700 bg-white font-bold' : 'text-gray-500 hover:text-gray-700'"
                class="flex-1 py-2.5 text-xs uppercase tracking-wide transition">
            <i class="fas fa-magic mr-1"></i>Agent
            <span class="ml-1 bg-green-600 text-white text-[10px] px-1.5 py-0.5 rounded-full">WRITE</span>
        </button>
    </div>

    <!-- ── TAB 1: INSIGHTS ──────────────────────────────────────────────────── -->
    <div x-show="activeTab==='insights'" class="flex flex-col flex-1 overflow-hidden">
        <div class="px-3 py-2 border-b border-gray-100 bg-gray-50 flex-shrink-0">
            <div class="flex flex-wrap gap-1.5">
                <template x-for="chip in insightChips" :key="chip.action">
                    <button @click="askInsight(chip.action)"
                            :class="insightAction===chip.action ? 'bg-purple-600 text-white border-purple-600' : 'bg-white text-gray-700 border-gray-300 hover:border-purple-400'"
                            class="flex items-center gap-1 px-2.5 py-1 border rounded-full text-xs font-medium transition"
                            x-html="chip.label"></button>
                </template>
            </div>
        </div>
        <div class="flex-1 overflow-y-auto p-4 space-y-3" id="insightsScroll">
            <div x-show="!insightLoading && !insightResponse && !insightError" class="text-center py-10">
                <i class="fas fa-robot text-purple-300 text-4xl mb-3"></i>
                <p class="text-gray-500 text-sm font-medium">Live Procurement Insights</p>
                <p class="text-gray-400 text-xs mt-1">Click a chip above or ask a custom question</p>
            </div>
            <div x-show="insightLoading" class="flex flex-col items-center py-8 gap-3">
                <div class="flex gap-1"><span class="dot bg-purple-500"></span><span class="dot bg-purple-400" style="animation-delay:.15s"></span><span class="dot bg-purple-300" style="animation-delay:.3s"></span></div>
                <p class="text-xs text-gray-500" x-text="insightLoadingMsg"></p>
            </div>
            <div x-show="insightError && !insightLoading" class="bg-red-50 border border-red-200 rounded-xl p-3 text-sm text-red-700">
                <i class="fas fa-exclamation-circle mr-1"></i><span x-text="insightError"></span>
                <button @click="askInsight(insightAction)" class="ml-2 text-xs underline">Retry</button>
            </div>
            <div x-show="insightResponse && !insightLoading">
                <div class="text-xs font-bold text-purple-700 uppercase tracking-wide mb-2" x-text="insightLabel"></div>
                <div class="bg-gradient-to-br from-purple-50 to-white border border-purple-100 rounded-xl p-4 shadow-sm">
                    <div class="ai-md text-gray-700 text-sm leading-relaxed" x-html="insightResponse"></div>
                </div>
                <div class="flex gap-2 mt-2">
                    <button @click="copyTxt(insightResponse)" class="text-xs text-gray-500 hover:text-gray-700 border border-gray-200 rounded px-2 py-1"><i class="fas fa-copy mr-1"></i>Copy</button>
                    <button @click="askInsight(insightAction)" class="text-xs text-gray-500 hover:text-gray-700 border border-gray-200 rounded px-2 py-1"><i class="fas fa-sync-alt mr-1"></i>Refresh</button>
                    <span x-show="copied" class="text-xs text-green-600 font-medium mt-0.5"><i class="fas fa-check mr-1"></i>Copied</span>
                </div>
            </div>
        </div>
        <div class="border-t border-gray-200 p-3 flex-shrink-0">
            <div class="flex gap-2">
                <input x-model="insightQ" @keydown.enter.prevent="if(insightQ.trim()) askInsightCustom()"
                       type="text" placeholder="Ask about procurement data…"
                       class="flex-1 text-sm px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-400" :disabled="insightLoading">
                <button @click="askInsightCustom()" :disabled="insightLoading||!insightQ.trim()"
                        class="px-3 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition disabled:opacity-40">
                    <i class="fas fa-paper-plane text-sm"></i></button>
            </div>
        </div>
    </div>

    <!-- ── TAB 2: QUERY DB ──────────────────────────────────────────────────── -->
    <div x-show="activeTab==='query'" class="flex flex-col flex-1 overflow-hidden">
        <div class="px-3 py-2.5 border-b border-gray-100 bg-blue-50 flex-shrink-0">
            <p class="text-xs font-bold text-blue-700 mb-1.5 uppercase tracking-wide"><i class="fas fa-lightbulb mr-1 text-yellow-500"></i>Example queries</p>
            <div class="flex flex-wrap gap-1.5">
                <template x-for="ex in qExamples" :key="ex">
                    <button @click="dbQ=ex; runDbQuery()"
                            class="px-2 py-1 bg-white border border-blue-200 text-blue-700 text-xs rounded-full hover:bg-blue-50 transition" x-text="ex"></button>
                </template>
            </div>
        </div>
        <div class="px-3 pt-3 pb-2 border-b border-gray-100 flex-shrink-0">
            <div class="flex gap-2">
                <input x-model="dbQ" @keydown.enter.prevent="if(dbQ.trim()) runDbQuery()"
                       type="text" placeholder="e.g. Show all payments made this month…"
                       class="flex-1 text-sm px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400" :disabled="dbLoading">
                <button @click="runDbQuery()" :disabled="dbLoading||!dbQ.trim()"
                        class="px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition disabled:opacity-40">
                    <i :class="dbLoading?'fas fa-spinner fa-spin':'fas fa-search'" class="text-sm"></i></button>
            </div>
            <p class="text-xs text-gray-400 mt-1"><i class="fas fa-lock mr-1 text-blue-400"></i>Read-only · AI writes & runs SQL · Up to 200 rows</p>
        </div>
        <div class="flex-1 overflow-y-auto" id="queryScroll">
            <div x-show="!dbLoading&&!dbResponse&&!dbError" class="text-center py-10 px-4">
                <i class="fas fa-database text-blue-200 text-4xl mb-3"></i>
                <p class="text-gray-500 text-sm font-medium">Ask Anything About Procurement Data</p>
                <p class="text-gray-400 text-xs mt-1">Plain English → SQL → Executed → Summary</p>
            </div>
            <div x-show="dbLoading" class="flex flex-col items-center py-8 gap-3">
                <div class="flex gap-1"><span class="dot bg-blue-500"></span><span class="dot bg-blue-400" style="animation-delay:.15s"></span><span class="dot bg-blue-300" style="animation-delay:.3s"></span></div>
                <p class="text-xs text-gray-500" x-text="dbLoadingMsg"></p>
            </div>
            <div x-show="dbError&&!dbLoading" class="m-3 bg-red-50 border border-red-200 rounded-xl p-3 text-sm text-red-700">
                <i class="fas fa-exclamation-circle mr-1"></i><span x-text="dbError"></span>
                <button @click="runDbQuery()" class="ml-2 text-xs underline">Retry</button>
            </div>
            <div x-show="dbResponse&&!dbLoading" class="p-3 space-y-3">
                <div class="bg-blue-50 border border-blue-200 rounded-xl p-3">
                    <div class="flex items-center gap-2 mb-1.5">
                        <i class="fas fa-robot text-blue-600 text-xs"></i>
                        <span class="text-xs font-bold text-blue-700 uppercase tracking-wide">Summary</span>
                        <span class="ml-auto text-xs text-blue-400" x-text="dbRowCount + ' rows · ' + dbTime"></span>
                    </div>
                    <p class="text-sm text-gray-700" x-text="dbResponse"></p>
                </div>
                <div class="border border-gray-200 rounded-xl overflow-hidden">
                    <button @click="showSql=!showSql" class="w-full flex items-center justify-between px-3 py-2 bg-gray-50 text-xs font-semibold text-gray-600 hover:bg-gray-100">
                        <span><i class="fas fa-code mr-1.5"></i>Generated SQL</span>
                        <i :class="showSql?'fa-chevron-up':'fa-chevron-down'" class="fas text-gray-400"></i>
                    </button>
                    <div x-show="showSql" class="bg-gray-900 p-3 overflow-x-auto">
                        <pre class="text-green-400 text-xs whitespace-pre-wrap font-mono" x-text="dbSql"></pre>
                        <button @click="copyTxt(dbSql)" class="mt-2 text-xs text-gray-400 hover:text-white"><i class="fas fa-copy mr-1"></i>Copy SQL</button>
                    </div>
                </div>
                <div x-show="dbRows.length>0" class="border border-gray-200 rounded-xl overflow-hidden">
                    <div class="flex items-center justify-between px-3 py-2 bg-gray-50 border-b border-gray-200">
                        <span class="text-xs font-semibold text-gray-600"><i class="fas fa-table mr-1.5"></i>Results <span class="text-gray-400 font-normal" x-text="'('+dbRows.length+' rows)'"></span></span>
                        <button @click="exportCsv()" class="text-xs text-blue-600 hover:text-blue-800 font-semibold"><i class="fas fa-download mr-1"></i>CSV</button>
                    </div>
                    <div class="overflow-x-auto" style="max-height:280px;overflow-y:auto;">
                        <table class="w-full text-xs">
                            <thead class="bg-gray-100 sticky top-0">
                                <tr><template x-for="col in dbColumns" :key="col">
                                    <th class="px-3 py-2 text-left font-semibold text-gray-600 whitespace-nowrap border-r border-gray-200 last:border-r-0"
                                        x-text="col.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase())"></th>
                                </template></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <template x-for="(row,i) in dbRows" :key="i">
                                    <tr :class="i%2===0?'bg-white':'bg-gray-50'" class="hover:bg-blue-50 transition">
                                        <template x-for="col in dbColumns" :key="col">
                                            <td class="px-3 py-1.5 text-gray-700 whitespace-nowrap border-r border-gray-100 last:border-r-0 max-w-[140px] truncate"
                                                :title="String(row[col]??'')" x-text="row[col]!==null&&row[col]!==undefined?row[col]:'—'"></td>
                                        </template>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TAB 3: AGENT ──────────────────────────────────────────────────────── -->
    <div x-show="activeTab==='agent'" class="flex flex-col flex-1 overflow-hidden">
        <div class="px-3 py-2.5 border-b border-gray-100 bg-green-50 flex-shrink-0">
            <div class="flex items-center justify-between mb-1.5">
                <p class="text-xs font-bold text-green-700 uppercase tracking-wide"><i class="fas fa-magic mr-1"></i>I can do this for you</p>
                <button @click="resetAgent()" x-show="agentMessages.length > 0"
                        class="text-xs text-red-500 hover:text-red-700 transition"><i class="fas fa-trash-alt mr-1"></i>Clear</button>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <template x-for="cap in agentCapabilities" :key="cap.label">
                    <button @click="startAgentAction(cap.msg)"
                            class="flex items-center gap-1 px-2.5 py-1 bg-white border border-green-200 text-green-700 text-xs font-medium rounded-full hover:bg-green-100 hover:border-green-400 transition"
                            x-html="cap.label"></button>
                </template>
            </div>
        </div>
        <div class="flex-1 overflow-y-auto p-3 space-y-3" id="agentChat" x-ref="agentChat">
            <div x-show="agentMessages.length===0 && !agentLoading" class="text-center py-8 px-3">
                <div class="w-14 h-14 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                    <i class="fas fa-magic text-green-600 text-xl"></i>
                </div>
                <h3 class="font-bold text-gray-700 text-sm">Procurement Agent</h3>
                <p class="text-gray-500 text-xs mt-1 max-w-xs mx-auto">I can create POs, record payments, log GRNs, and update statuses — just ask.</p>
                <div class="mt-4 grid grid-cols-1 gap-2 text-left max-w-xs mx-auto">
                    <div class="flex items-start gap-2 bg-green-50 rounded-lg px-3 py-2">
                        <i class="fas fa-check-circle text-green-500 text-xs mt-0.5"></i>
                        <p class="text-xs text-gray-600">"Create a PO for 500MT wheat from Canada"</p>
                    </div>
                    <div class="flex items-start gap-2 bg-green-50 rounded-lg px-3 py-2">
                        <i class="fas fa-check-circle text-green-500 text-xs mt-0.5"></i>
                        <p class="text-xs text-gray-600">"Record a ৳15 lakh payment for PO-495"</p>
                    </div>
                    <div class="flex items-start gap-2 bg-green-50 rounded-lg px-3 py-2">
                        <i class="fas fa-check-circle text-green-500 text-xs mt-0.5"></i>
                        <p class="text-xs text-gray-600">"Log GRN for PO-497 — 155MT received"</p>
                    </div>
                </div>
            </div>
            <template x-for="(msg, idx) in agentMessages" :key="idx">
                <div :class="msg.role==='user' ? 'flex justify-end' : 'flex justify-start'">
                    <div x-show="msg.role==='assistant'" class="flex-shrink-0 w-7 h-7 bg-green-100 rounded-full flex items-center justify-center mr-2 mt-1 self-start">
                        <i :class="msg.executed ? 'fas fa-check text-green-600' : 'fas fa-robot text-green-600'" class="text-xs"></i>
                    </div>
                    <div :class="msg.role==='user'
                              ? 'bg-indigo-600 text-white rounded-2xl rounded-tr-sm px-4 py-2.5 max-w-[82%]'
                              : msg.executed
                                ? 'bg-green-50 border border-green-200 rounded-2xl rounded-tl-sm px-4 py-2.5 max-w-[85%]'
                                : 'bg-white border border-gray-200 rounded-2xl rounded-tl-sm px-4 py-2.5 max-w-[85%] shadow-sm'">
                        <div :class="msg.role==='user' ? 'text-white text-sm' : 'text-gray-800 text-sm ai-md leading-relaxed'"
                             x-html="msg.role==='user' ? escHtml(msg.content) : md(msg.content)"></div>
                        <div x-show="msg.executed" class="mt-1.5"><span class="text-xs text-green-600 font-semibold"><i class="fas fa-check-circle mr-1"></i>Action Executed</span></div>
                        <div class="text-xs mt-1" :class="msg.role==='user'?'text-indigo-200':'text-gray-400'" x-text="msg.time"></div>
                    </div>
                </div>
            </template>
            <div x-show="agentLoading" class="flex justify-start">
                <div class="w-7 h-7 bg-green-100 rounded-full flex items-center justify-center mr-2 flex-shrink-0">
                    <i class="fas fa-robot text-green-600 text-xs"></i>
                </div>
                <div class="bg-white border border-gray-200 rounded-2xl rounded-tl-sm px-4 py-3 shadow-sm">
                    <div class="flex gap-1.5 items-center">
                        <span class="dot bg-green-500"></span><span class="dot bg-green-400" style="animation-delay:.2s"></span><span class="dot bg-green-300" style="animation-delay:.4s"></span>
                        <span class="text-xs text-gray-400 ml-1" x-text="agentLoadingMsg"></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="border-t border-gray-200 p-3 flex-shrink-0 bg-white">
            <div class="flex gap-2">
                <input x-model="agentInput"
                       @keydown.enter.prevent="if(agentInput.trim()) sendAgentMessage()"
                       type="text" placeholder="Tell me what to do…"
                       class="flex-1 text-sm px-3 py-2.5 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400"
                       :disabled="agentLoading">
                <button @click="sendAgentMessage()" :disabled="agentLoading||!agentInput.trim()"
                        class="px-4 py-2.5 bg-green-600 text-white rounded-xl hover:bg-green-700 transition disabled:opacity-40">
                    <i :class="agentLoading?'fas fa-spinner fa-spin':'fas fa-paper-plane'" class="text-sm"></i>
                </button>
            </div>
            <div class="flex items-center justify-between mt-1.5">
                <p class="text-xs text-gray-400"><i class="fas fa-lock text-green-500 mr-1"></i>Safe pre-defined actions &bull; Always confirms before writing</p>
                <span class="text-xs text-gray-400" x-show="agentProvider">via <span class="font-medium text-orange-500" x-text="agentProvider"></span></span>
            </div>
        </div>
    </div>

</div><!-- /AI modal panel -->

<!-- ══ FAB BUTTON (fixed, always visible) ════════════════════════════════════ -->
<button @click="toggleAI()"
        class="fixed bottom-6 right-6 z-[99] flex items-center gap-2 px-4 py-3 bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-full shadow-2xl hover:scale-105 transition-all">
    <i class="fas fa-robot text-sm"></i>
    <span class="text-sm font-semibold" x-text="aiOpen ? 'Close AI' : 'AI Suite'"></span>
    <span x-show="unreadBadge && !aiOpen" class="w-2.5 h-2.5 bg-yellow-400 rounded-full animate-pulse"></span>
</button>

<?php endif; ?>
</div><!-- /x-data purchaseAiApp -->

<!-- ══ STYLES<!-- ══ STYLES ════════════════════════════════════════════════════════════════ -->
<style>
.ai-md h3 { font-size:.875rem; font-weight:700; color:#166534; margin:.75rem 0 .375rem; }
.ai-md h3:first-child { margin-top:0; }
.ai-md ul { list-style:disc; padding-left:1.25rem; margin:.4rem 0; }
.ai-md li { margin:.2rem 0; font-size:.8125rem; }
.ai-md strong { font-weight:700; color:#111827; }
.ai-md p { margin:.375rem 0; font-size:.8125rem; }
.ai-md hr { border-color:#d1fae5; margin:.6rem 0; }
.ai-md code { background:#f0fdf4; color:#15803d; padding:.1rem .3rem; border-radius:.25rem; font-size:.75rem; }
.dot { display:inline-block; width:8px; height:8px; border-radius:50%; animation:dotBounce .8s infinite; }
@keyframes dotBounce { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }
</style>


<!-- ══ SCRIPT ════════════════════════════════════════════════════════════════ -->
<script>
const csrfToken = "<?php echo htmlspecialchars($csrfToken ?? $_SESSION['csrf_token'] ?? '', ENT_QUOTES); ?>";

function closePO(poId, poNumber) {
    if (!confirm(`Close PO #${poNumber}?\n\nThis will:\n— Prevent further goods receipt\n— Mark delivery as "closed"\n— Keep all existing records\n\nCan be reversed by Superadmin.`)) return;
    fetch('purchase_adnan_close_po.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'po_id=' + poId
    })
    .then(r => r.json())
    .then(d => { alert(d.message); if (d.success) location.reload(); })
    .catch(e => alert('Error: ' + e));
}

function deletePO(poId, poNumber) {
    if (!confirm(`⚠️ Delete PO #${poNumber}?\n\nThis will mark it as cancelled.\nGRNs and payments are preserved.\n\nCan be reversed by Superadmin.`)) return;
    const confirmText = prompt('Type "DELETE" to confirm:');
    if (confirmText !== 'DELETE') return;
    fetch('purchase_adnan_delete_po.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'po_id=' + poId
    })
    .then(r => r.json())
    .then(d => { alert(d.message); if (d.success) location.reload(); })
    .catch(e => alert('Error: ' + e));
}

<?php if (in_array($current_user['role'], ['Superadmin', 'admin'])): ?>
function purchaseAiApp() {
    return {
        aiOpen: false,
        activeTab: 'insights',
        unreadBadge: false,
        copied: false,

        // Insights
        insightLoading: false,
        insightResponse: '',
        insightError: '',
        insightAction: '',
        insightLabel: '',
        insightQ: '',
        insightLoadingMsg: 'Analysing procurement data…',
        insightLoadingMsgs: ['Analysing procurement data…','Reading supplier dues…','Checking payment obligations…','Calculating insights…','Almost ready…'],

        insightChips: [
            { action:'procurement_brief', label:'<i class="fas fa-sun text-yellow-400 mr-1"></i>Daily Brief' },
            { action:'payment_urgency',   label:'<i class="fas fa-credit-card text-red-500 mr-1"></i>Payment Urgency' },
            { action:'supplier_risk',     label:'<i class="fas fa-handshake text-orange-500 mr-1"></i>Supplier Risk' },
            { action:'origin_analysis',   label:'<i class="fas fa-globe text-blue-500 mr-1"></i>Origin Analysis' },
            { action:'cash_planning',     label:'<i class="fas fa-wallet text-green-500 mr-1"></i>Cash Planning' },
        ],
        insightLabelMap: {
            procurement_brief:'📋 Daily Procurement Brief',
            payment_urgency:'💳 Payment Urgency',
            supplier_risk:'🤝 Supplier Risk',
            origin_analysis:'🌍 Origin Analysis',
            cash_planning:'📊 Cash Planning',
            custom:'💬 Custom Query',
        },

        // Query DB
        dbLoading: false,
        dbQ: '',
        dbResponse: '',
        dbError: '',
        dbRows: [], dbColumns: [], dbSql: '', dbRowCount: 0, dbTime: '',
        showSql: false,
        dbLoadingMsg: 'Translating to SQL…',
        dbLoadingMsgs: ['Translating to SQL…','Writing query…','Executing against database…','Summarising results…'],

        qExamples: [
            'Show all in-progress purchase orders',
            'List payments made this month',
            'Which suppliers have the highest outstanding balance?',
            'Show GRNs received this week',
            'Total wheat received by origin country',
            'List all advance payments not yet settled',
        ],

        // Agent
        agentLoading: false,
        agentInput: '',
        agentMessages: [],
        agentLoadingMsg: 'Thinking…',
        agentProvider: '',
        agentLoadingMsgs: ['Thinking…','Understanding your request…','Preparing to act…','Almost ready…'],

        agentCapabilities: [
            { label:'<i class="fas fa-file-invoice text-purple-500 mr-1"></i>New PO',          msg:'Create a new purchase order for wheat' },
            { label:'<i class="fas fa-money-bill-wave text-green-500 mr-1"></i>Record Payment', msg:'Record a payment for a purchase order' },
            { label:'<i class="fas fa-truck-loading text-blue-500 mr-1"></i>Record GRN',        msg:'Record goods received for a PO' },
            { label:'<i class="fas fa-exchange-alt text-orange-500 mr-1"></i>Update PO Status', msg:'Update the status of a purchase order' },
            { label:'<i class="fas fa-lock text-gray-500 mr-1"></i>Close PO',                  msg:'Close a completed purchase order' },
        ],

        init() {
            // Nothing on page load — AI loads when user opens the modal
        },

        toggleAI() {
            this.aiOpen = !this.aiOpen;
            this.unreadBadge = false;
            if (this.aiOpen && this.activeTab === 'insights' && !this.insightResponse && !this.insightLoading) {
                this.askInsight('procurement_brief');
            }
        },

        // ── INSIGHTS ────────────────────────────────────────────────────────
        async askInsight(action) {
            this.insightAction = action;
            this.insightLabel  = this.insightLabelMap[action] || 'AI Response';
            this.insightLoading = true;
            this.insightResponse = ''; this.insightError = '';
            let i=0, iv=setInterval(()=>{ this.insightLoadingMsg=this.insightLoadingMsgs[i++%this.insightLoadingMsgs.length]; },1200);
            try {
                const d = await this.callAdvisor(action, '');
                clearInterval(iv);
                if (d.success) this.insightResponse = this.md(d.response);
                else           this.insightError = d.error || 'Error';
            } catch(e) { clearInterval(iv); this.insightError = 'Network error: ' + e.message; }
            this.insightLoading = false;
        },

        async askInsightCustom() {
            const q = this.insightQ.trim(); if (!q) return;
            this.insightAction = 'custom'; this.insightLabel = this.insightLabelMap.custom;
            this.insightLoading = true; this.insightResponse = ''; this.insightError = '';
            try {
                const d = await this.callAdvisor('custom', q);
                if (d.success) { this.insightResponse = this.md(d.response); this.insightQ = ''; }
                else           { this.insightError = d.error; }
            } catch(e) { this.insightError = 'Network error: ' + e.message; }
            this.insightLoading = false;
        },

        async fetchBriefBanner() {
            try {
                const d = await this.callAdvisor('procurement_brief', '');
                if (d.success) { this.unreadBadge = true; }
            } catch {}
        },

        async callAdvisor(action, question) {
            const controller = new AbortController();
            const tid = setTimeout(() => controller.abort(), 40000);
            try {
                const r = await fetch('purchase_advisor.php', {
                    method:'POST',
                    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken},
                    body: JSON.stringify({ action, question }),
                    signal: controller.signal,
                });
                clearTimeout(tid);
                const raw = await r.text();
                try { return JSON.parse(raw); }
                catch(e) { return { success:false, error:'Server error: '+raw.substring(0,200) }; }
            } catch(e) {
                clearTimeout(tid);
                const msg = e.name==='AbortError' ? 'Request timed out (40s)' : e.message;
                return { success:false, error:msg };
            }
        },

        // ── QUERY DB ─────────────────────────────────────────────────────────
        async runDbQuery() {
            const q = this.dbQ.trim(); if (!q) return;
            this.dbLoading=true; this.dbResponse=''; this.dbError=''; this.dbRows=[]; this.dbColumns=[]; this.dbSql=''; this.showSql=false;
            let i=0, iv=setInterval(()=>{ this.dbLoadingMsg=this.dbLoadingMsgs[i++%this.dbLoadingMsgs.length]; },1400);
            const t0=Date.now();
            try {
                const controller = new AbortController();
                const tid = setTimeout(()=>controller.abort(), 45000);
                const r = await fetch('purchase_advisor.php', {
                    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken},
                    body: JSON.stringify({ action:'db_query', question:q }),
                    signal: controller.signal,
                });
                clearTimeout(tid);
                const raw = await r.text();
                const d = JSON.parse(raw);
                clearInterval(iv);
                if (d.success) {
                    this.dbResponse=d.response; this.dbRows=d.rows||[]; this.dbColumns=d.columns||[];
                    this.dbSql=d.sql||''; this.dbRowCount=d.row_count||0;
                    this.dbTime=((Date.now()-t0)/1000).toFixed(1)+'s';
                } else { this.dbError=d.error||'Error'; }
            } catch(e) {
                clearInterval(iv);
                this.dbError = e.name==='AbortError' ? 'Query timed out (45s)' : 'Network error: '+e.message;
            }
            this.dbLoading=false;
        },

        exportCsv() {
            if (!this.dbRows.length) return;
            const header = this.dbColumns.join(',');
            const rows = this.dbRows.map(r => this.dbColumns.map(c => {
                const v=r[c]??'';
                return (String(v).includes(',')||String(v).includes('"')||String(v).includes('\n'))
                    ? '"'+String(v).replace(/"/g,'""')+'"' : v;
            }).join(','));
            const blob=new Blob(['\uFEFF'+[header,...rows].join('\n')],{type:'text/csv;charset=utf-8;'});
            const a=document.createElement('a'); a.href=URL.createObjectURL(blob);
            a.download='purchase_query_'+new Date().toISOString().slice(0,10)+'.csv'; a.click();
        },

        // ── AGENT ────────────────────────────────────────────────────────────
        startAgentAction(msg) {
            this.activeTab = 'agent';
            this.agentInput = msg;
            this.sendAgentMessage();
        },

        async sendAgentMessage() {
            const msg = this.agentInput.trim(); if (!msg) return;
            this.agentInput = '';
            this.agentMessages.push({ role:'user', content:msg, time:this.timeNow() });
            this.agentLoading = true;
            this.scrollChat();
            let i=0, iv=setInterval(()=>{ this.agentLoadingMsg=this.agentLoadingMsgs[i++%this.agentLoadingMsgs.length]; },1500);
            try {
                const controller = new AbortController();
                const tid = setTimeout(()=>controller.abort(), 45000);
                const r = await fetch('purchase_agent.php', {
                    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken},
                    body: JSON.stringify({ sub_action:'message', message:msg }),
                    signal: controller.signal,
                });
                clearTimeout(tid);
                const raw = await r.text();
                const d = JSON.parse(raw);
                clearInterval(iv);
                if (d.success) {
                    this.agentProvider = d.provider || '';
                    this.agentMessages.push({ role:'assistant', content:d.message, executed:d.executed||false, time:this.timeNow() });
                } else {
                    this.agentMessages.push({ role:'assistant', content:'⚠️ Error: '+(d.error||'Unknown error'), time:this.timeNow() });
                }
            } catch(e) {
                clearInterval(iv);
                const msg2 = e.name==='AbortError' ? '⚠️ Request timed out. Please try again.' : '⚠️ Network error. Please try again.';
                this.agentMessages.push({ role:'assistant', content:msg2, time:this.timeNow() });
            }
            this.agentLoading = false;
            this.$nextTick(()=>this.scrollChat());
        },

        async resetAgent() {
            if (!confirm('Clear conversation history?')) return;
            await fetch('purchase_agent.php', {
                method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken},
                body: JSON.stringify({ sub_action:'reset' })
            });
            this.agentMessages = [];
            this.agentProvider = '';
        },

        scrollChat() {
            this.$nextTick(()=>{ const el=document.getElementById('agentChat'); if(el) el.scrollTop=el.scrollHeight; });
        },

        // ── UTILS ────────────────────────────────────────────────────────────
        timeNow() { return new Date().toLocaleTimeString('en-BD',{hour:'2-digit',minute:'2-digit'}); },

        copyTxt(text) {
            navigator.clipboard.writeText(text.replace(/<[^>]*>/g,''))
                .then(()=>{ this.copied=true; setTimeout(()=>this.copied=false,2000); });
        },

        escHtml(t) {
            return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        },

        md(t) {
            if (!t) return '';
            return String(t)
                .replace(/^### (.+)$/gm, '<h3>$1</h3>')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/`([^`]+)`/g, '<code>$1</code>')
                .replace(/^[*\-] (.+)$/gm, '<li>$1</li>')
                .replace(/^\d+\. (.+)$/gm, '<li>$1</li>')
                .replace(/(<li>[\s\S]+?<\/li>\n?)+/g, m => '<ul>' + m + '</ul>')
                .replace(/^---$/gm, '<hr>')
                .replace(/✅/g, '<span class="text-green-600">✅</span>')
                .replace(/⚠️/g, '<span class="text-yellow-500">⚠️</span>')
                .split('\n\n').join('</p><p>')
                .split('\n').join('<br>');
        },
    };
}
<?php else: ?>
function purchaseAiApp() { return {}; }
<?php endif; ?>
</script>

<?php include '../templates/footer.php'; ?>
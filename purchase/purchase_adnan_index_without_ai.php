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
$stats         = $purchaseManager->getDashboardStats();
$all_suppliers = $purchaseManager->getAllSuppliers();
$recent_orders = $purchaseManager->listPurchaseOrders($filters);

// ── Helper ────────────────────────────────────────────────────
function getExpectedQuantityForPO($po_id) {
    $db  = Database::getInstance()->getPdo();
    $sql = "SELECT COALESCE(SUM(expected_quantity), 0) as total_expected
            FROM goods_received_adnan
            WHERE purchase_order_id = ?
            AND grn_status != 'cancelled'";
    $stmt = $db->prepare($sql);
    $stmt->execute([$po_id]);
    $result = $stmt->fetch(PDO::FETCH_OBJ);
    return $result->total_expected ?? 0;
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

</div>

<script>
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
</script>

<?php include '../templates/footer.php'; ?>
<?php
/**
 * Purchase (Adnan) Module - Dashboard
 * Main overview page for wheat procurement system
 * 
 * @package Ujjal Flour Mills
 * @subpackage Purchase (Adnan) Module
 */

require_once '../core/init.php';
require_once '../core/config/config.php';
require_once '../core/classes/Database.php';
require_once '../core/functions/helpers.php';
require_once '../core/classes/Purchaseadnanmanager.php';

// Restrict access to authorized users
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle = "Purchase (Adnan) - Dashboard";

// Initialize manager
$purchaseManager = new PurchaseAdnanManager();

// Get current user
$current_user = getCurrentUser();
$is_superadmin = ($current_user['role'] === 'Superadmin');

// Get filter parameters
$filters = [
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'supplier_id' => $_GET['supplier_id'] ?? '',
    'wheat_origin' => $_GET['wheat_origin'] ?? '',
    'delivery_status' => $_GET['delivery_status'] ?? '',
    'payment_status' => $_GET['payment_status'] ?? '',
    'po_status' => $_GET['po_status'] ?? '',
    'search' => $_GET['search'] ?? '',
    'limit' => 50 // Increased limit when filtering
];

// Remove empty filters
$filters = array_filter($filters, function($value) {
    return $value !== '' && $value !== null;
});

// Get dashboard statistics
$stats = $purchaseManager->getDashboardStats();
$supplier_summary = $purchaseManager->getSupplierSummary();
$stats_by_origin = $purchaseManager->getStatsByOrigin();

// Get orders with filters (chronologically - newest first)
if (empty($filters) || (count($filters) === 1 && isset($filters['limit']))) {
    // No filters applied - show recent 10 orders
    $filters['limit'] = 10;
    $recent_orders = $purchaseManager->listPurchaseOrders($filters);
} else {
    // Filters applied - show filtered results
    $recent_orders = $purchaseManager->listPurchaseOrders($filters);
}

// Helper function to get total expected quantity for a PO
function getExpectedQuantityForPO($po_id) {
    $db = Database::getInstance()->getPdo();
    $sql = "SELECT COALESCE(SUM(expected_quantity), 0) as total_expected
            FROM goods_received_adnan
            WHERE purchase_order_id = ?
            AND grn_status != 'cancelled'";
    $stmt = $db->prepare($sql);
    $stmt->execute([$po_id]);
    $result = $stmt->fetch(PDO::FETCH_OBJ);
    return $result->total_expected ?? 0;
}

// Calculate totals for the filtered orders
$totals = [
    'ordered_quantity' => 0,
    'total_value' => 0,
    'goods_received' => 0,
    'paid' => 0,
    'due' => 0,        // Sum of only positive due amounts
    'advance' => 0     // Sum of only advance (negative balance) amounts
];

if (!empty($recent_orders)) {
    foreach ($recent_orders as $order) {
        // Get expected quantity for this order
        $expected_qty = getExpectedQuantityForPO($order->id);
        $expected_payable = $expected_qty * $order->unit_price_per_kg;
        $balance = $expected_payable - ($order->total_paid ?? 0);
        
        $totals['ordered_quantity'] += $order->quantity_kg;
        $totals['total_value'] += $order->total_order_value;
        $totals['goods_received'] += ($order->total_received_qty ?? 0);
        $totals['paid'] += ($order->total_paid ?? 0);

        // Split into due vs advance
        if ($balance > 0) {
            $totals['due'] += $balance;      // Actual due (positive)
        } else {
            $totals['advance'] += abs($balance); // Advance paid (overpaid)
        }
    }
}

// Get unique suppliers for filter dropdown
$all_suppliers = $purchaseManager->getAllSuppliers();

// Check if any filter is active
$active_filters = array_filter($filters, function($key) {
    return $key !== 'limit';
}, ARRAY_FILTER_USE_KEY);

include '../templates/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <!-- Page Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Purchase (Adnan)</h1>
            <p class="text-gray-600 mt-1">Wheat Procurement Management System</p>
        </div>
        <div class="flex gap-2">
            <a href="purchase_adnan_create_po.php" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 flex items-center gap-2">
                <i class="fas fa-plus"></i> New Purchase Order
            </a>
            <a href="purchase_adnan_supplier_summary.php" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 flex items-center gap-2">
                <i class="fas fa-chart-bar"></i> Supplier Summary
            </a>
        </div>
    </div>

    <!-- KPI Cards -->
    <!-- KPI Cards - Enhanced Dynamic Design -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    
    <!-- Total Orders Card -->
    <div class="relative overflow-hidden bg-gradient-to-br from-blue-500 to-blue-700 rounded-xl shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1">
        <div class="absolute top-0 right-0 -mt-4 -mr-4 h-24 w-24 rounded-full bg-white opacity-10"></div>
        <div class="relative p-6">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center justify-center h-12 w-12 rounded-lg bg-white bg-opacity-20 backdrop-blur-sm">
                    <i class="fas fa-shopping-cart text-white text-xl"></i>
                </div>
                <div class="text-right">
                    <p class="text-blue-100 text-xs font-semibold uppercase tracking-wider">Total Orders</p>
                    <p class="text-white text-3xl font-bold mt-1"><?php echo number_format($stats->total_orders ?? 0); ?></p>
                </div>
            </div>
            <div class="flex items-center justify-between text-xs">
                <span class="text-blue-100">
                    <i class="fas fa-check-circle mr-1"></i><?php echo $stats->completed_deliveries ?? 0; ?> Completed
                </span>
                <span class="text-blue-100">
                    <i class="fas fa-lock mr-1"></i><?php echo $stats->closed_deals ?? 0; ?> Closed
                </span>
            </div>
        </div>
    </div>

    <!-- Total Order Value Card -->
    <div class="relative overflow-hidden bg-gradient-to-br from-purple-500 to-purple-700 rounded-xl shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1">
        <div class="absolute top-0 right-0 -mt-4 -mr-4 h-24 w-24 rounded-full bg-white opacity-10"></div>
        <div class="relative p-6">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center justify-center h-12 w-12 rounded-lg bg-white bg-opacity-20 backdrop-blur-sm">
                    <i class="fas fa-money-bill-wave text-white text-xl"></i>
                </div>
                <div class="text-right">
                    <p class="text-purple-100 text-xs font-semibold uppercase tracking-wider">Order Value</p>
                    <p class="text-white text-3xl font-bold mt-1">
                        ৳<?php echo number_format(($stats->total_order_value ?? 0) / 1000000, 1); ?>M
                    </p>
                </div>
            </div>
            <div class="text-xs text-purple-100">
                <i class="fas fa-info-circle mr-1"></i>
                Total: ৳<?php echo number_format($stats->total_order_value ?? 0, 0); ?>
            </div>
        </div>
    </div>

    <!-- Total Paid Card -->
    <div class="relative overflow-hidden bg-gradient-to-br from-green-500 to-green-700 rounded-xl shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1">
        <div class="absolute top-0 right-0 -mt-4 -mr-4 h-24 w-24 rounded-full bg-white opacity-10"></div>
        <div class="relative p-6">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center justify-center h-12 w-12 rounded-lg bg-white bg-opacity-20 backdrop-blur-sm">
                    <i class="fas fa-check-circle text-white text-xl"></i>
                </div>
                <div class="text-right">
                    <p class="text-green-100 text-xs font-semibold uppercase tracking-wider">Total Paid</p>
                    <p class="text-white text-3xl font-bold mt-1">
                        ৳<?php echo number_format(($stats->total_paid ?? 0) / 1000000, 1); ?>M
                    </p>
                </div>
            </div>
            <div class="flex items-center justify-between text-xs">
                <span class="text-green-100">
                    <i class="fas fa-wallet mr-1"></i>Regular: ৳<?php echo number_format(($stats->regular_payments ?? 0) / 1000000, 1); ?>M
                </span>
                <span class="text-green-100">
                    <?php 
                    $payment_rate = $stats->expected_payable > 0 ? ($stats->total_paid / $stats->expected_payable * 100) : 0;
                    echo number_format($payment_rate, 1); ?>%
                </span>
            </div>
        </div>
    </div>

    <!-- Advance Payments Card - NEW -->
    <div class="relative overflow-hidden bg-gradient-to-br from-yellow-500 to-yellow-700 rounded-xl shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1">
        <div class="absolute top-0 right-0 -mt-4 -mr-4 h-24 w-24 rounded-full bg-white opacity-10"></div>
        <div class="relative p-6">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center justify-center h-12 w-12 rounded-lg bg-white bg-opacity-20 backdrop-blur-sm">
                    <i class="fas fa-hand-holding-usd text-white text-xl"></i>
                </div>
                <div class="text-right">
                    <p class="text-yellow-100 text-xs font-semibold uppercase tracking-wider">Advance Paid</p>
                    <p class="text-white text-3xl font-bold mt-1">
                        ৳<?php echo number_format(($stats->total_advance ?? 0) / 1000000, 1); ?>M
                    </p>
                </div>
            </div>
            <div class="text-xs text-yellow-100">
                <i class="fas fa-coins mr-1"></i>
                Total: ৳<?php echo number_format($stats->total_advance ?? 0, 0); ?>
            </div>
        </div>
    </div>

</div>

<!-- Secondary KPI Row -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    
    <!-- Expected Payable Card -->
    <div class="relative overflow-hidden bg-gradient-to-br from-indigo-500 to-indigo-700 rounded-xl shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1">
        <div class="absolute top-0 right-0 -mt-4 -mr-4 h-24 w-24 rounded-full bg-white opacity-10"></div>
        <div class="relative p-6">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center justify-center h-12 w-12 rounded-lg bg-white bg-opacity-20 backdrop-blur-sm">
                    <i class="fas fa-calculator text-white text-xl"></i>
                </div>
                <div class="text-right">
                    <p class="text-indigo-100 text-xs font-semibold uppercase tracking-wider">Expected Payable</p>
                    <p class="text-white text-3xl font-bold mt-1">
                        ৳<?php echo number_format(($stats->expected_payable ?? 0) / 1000000, 1); ?>M
                    </p>
                </div>
            </div>
            <div class="text-xs text-indigo-100">
                <i class="fas fa-box mr-1"></i>
                Based on GRN expected quantities
            </div>
        </div>
    </div>

    <!-- Balance Due Card -->
    <div class="relative overflow-hidden bg-gradient-to-br from-red-500 to-red-700 rounded-xl shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1">
        <div class="absolute top-0 right-0 -mt-4 -mr-4 h-24 w-24 rounded-full bg-white opacity-10"></div>
        <div class="relative p-6">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center justify-center h-12 w-12 rounded-lg bg-white bg-opacity-20 backdrop-blur-sm">
                    <i class="fas fa-exclamation-triangle text-white text-xl"></i>
                </div>
                <div class="text-right">
                    <p class="text-red-100 text-xs font-semibold uppercase tracking-wider">Balance Due</p>
                    <p class="text-white text-3xl font-bold mt-1">
                        ৳<?php echo number_format(($stats->actual_balance_due ?? 0) / 1000000, 1); ?>M
                    </p>
                </div>
            </div>
            <div class="text-xs text-red-100">
                <i class="fas fa-info-circle mr-1"></i>
                Expected Payable - Total Paid
            </div>
        </div>
    </div>

    <!-- Payment Progress Card -->
    <div class="relative overflow-hidden bg-gradient-to-br from-teal-500 to-teal-700 rounded-xl shadow-lg hover:shadow-2xl transition-all duration-300 transform hover:-translate-y-1">
        <div class="absolute top-0 right-0 -mt-4 -mr-4 h-24 w-24 rounded-full bg-white opacity-10"></div>
        <div class="relative p-6">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center justify-center h-12 w-12 rounded-lg bg-white bg-opacity-20 backdrop-blur-sm">
                    <i class="fas fa-chart-pie text-white text-xl"></i>
                </div>
                <div class="text-right">
                    <p class="text-teal-100 text-xs font-semibold uppercase tracking-wider">Payment Rate</p>
                    <p class="text-white text-3xl font-bold mt-1">
                        <?php 
                        $payment_rate = $stats->expected_payable > 0 ? ($stats->total_paid / $stats->expected_payable * 100) : 0;
                        echo number_format($payment_rate, 1); 
                        ?>%
                    </p>
                </div>
            </div>
            <!-- Progress Bar -->
            <div class="w-full bg-white bg-opacity-20 rounded-full h-2">
                <div class="bg-white h-2 rounded-full transition-all duration-500" 
                     style="width: <?php echo min(100, $payment_rate); ?>%"></div>
            </div>
        </div>
    </div>

</div>

<!-- Financial Summary Cards -->
<div class="bg-white rounded-lg shadow-lg p-6 mb-8">
    <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
        <i class="fas fa-chart-line text-blue-600"></i> Financial Summary
    </h3>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        
        <!-- Orders Value -->
        <div class="text-center p-4 bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg border-l-4 border-blue-500">
            <div class="text-sm text-gray-600 mb-1">Total Order Value</div>
            <div class="text-2xl font-bold text-blue-600">
                ৳<?php echo number_format($stats->total_order_value ?? 0, 0); ?>
            </div>
        </div>

        <!-- Expected Payable -->
        <div class="text-center p-4 bg-gradient-to-br from-indigo-50 to-indigo-100 rounded-lg border-l-4 border-indigo-500">
            <div class="text-sm text-gray-600 mb-1">Expected Payable</div>
            <div class="text-2xl font-bold text-indigo-600">
                ৳<?php echo number_format($stats->expected_payable ?? 0, 0); ?>
            </div>
            <div class="text-xs text-gray-500 mt-1">From GRNs</div>
        </div>

        <!-- Total Paid -->
        <div class="text-center p-4 bg-gradient-to-br from-green-50 to-green-100 rounded-lg border-l-4 border-green-500">
            <div class="text-sm text-gray-600 mb-1">Total Paid</div>
            <div class="text-2xl font-bold text-green-600">
                ৳<?php echo number_format($stats->total_paid ?? 0, 0); ?>
            </div>
            <div class="text-xs text-gray-500 mt-1">
                Advance: ৳<?php echo number_format($stats->total_advance ?? 0, 0); ?>
            </div>
        </div>

        <!-- Balance Due -->
        <div class="text-center p-4 bg-gradient-to-br from-red-50 to-red-100 rounded-lg border-l-4 border-red-500">
            <div class="text-sm text-gray-600 mb-1">Balance Due</div>
            <div class="text-2xl font-bold text-red-600">
                ৳<?php echo number_format($stats->actual_balance_due ?? 0, 0); ?>
            </div>
            <div class="text-xs text-gray-500 mt-1">
                <?php echo number_format($payment_rate, 1); ?>% Paid
            </div>
        </div>

    </div>
</div>
    
    
    
    
    <!-- Quick Actions -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <a href="purchase_adnan_record_grn.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow">
            <div class="flex items-center gap-4">
                <div class="bg-purple-100 rounded-full p-3">
                    <i class="fas fa-truck text-purple-600 text-2xl"></i>
                </div>
                <div>
                    <h4 class="font-semibold text-gray-900">Record Goods Receipt</h4>
                    <p class="text-sm text-gray-600">Log truck delivery</p>
                </div>
            </div>
        </a>

        <a href="purchase_adnan_record_payment.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow">
            <div class="flex items-center gap-4">
                <div class="bg-yellow-100 rounded-full p-3">
                    <i class="fas fa-credit-card text-yellow-600 text-2xl"></i>
                </div>
                <div>
                    <h4 class="font-semibold text-gray-900">Record Payment</h4>
                    <p class="text-sm text-gray-600">Process supplier payment</p>
                </div>
            </div>
        </a>

        <a href="variance_report.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow">
            <div class="flex items-center gap-4">
                <div class="bg-orange-100 rounded-full p-3">
                    <i class="fas fa-chart-line text-orange-600 text-2xl"></i>
                </div>
                <div>
                    <h4 class="font-semibold text-gray-900">Variance Reports</h4>
                    <p class="text-sm text-gray-600">Quality & weight variance</p>
                </div>
            </div>
        </a>
    </div>

    <!-- Filter Panel -->
    <div class="bg-white rounded-lg shadow-md mb-6">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-lg font-semibold text-gray-900">
                <i class="fas fa-filter mr-2"></i>
                Filters
                <?php if (!empty($active_filters)): ?>
                    <span class="ml-2 bg-primary-100 text-primary-800 px-2 py-1 rounded-full text-xs">
                        <?php echo count($active_filters); ?> active
                    </span>
                <?php endif; ?>
            </h3>
            <button onclick="toggleFilters()" class="text-primary-600 hover:text-primary-700 text-sm font-medium">
                <span id="filterToggleText">Toggle Filters</span>
            </button>
        </div>
        
        <div id="filterPanel" class="px-6 py-4" style="display:none;">
            <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Date From -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                </div>

                <!-- Date To -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to'] ?? ''); ?>" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                </div>

                <!-- Supplier -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
                    <select name="supplier_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                        <option value="">All Suppliers</option>
                        <?php foreach ($all_suppliers as $supplier): ?>
                            <option value="<?php echo $supplier->id; ?>" <?php echo ($filters['supplier_id'] ?? '') == $supplier->id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Wheat Origin -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Wheat Origin</label>
                    <select name="wheat_origin" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                        <option value="">All Origins</option>
                        <option value="কানাডা" <?php echo ($filters['wheat_origin'] ?? '') == 'কানাডা' ? 'selected' : ''; ?>>কানাডা (Canada)</option>
                        <option value="রাশিয়া" <?php echo ($filters['wheat_origin'] ?? '') == 'রাশিয়া' ? 'selected' : ''; ?>>রাশিয়া (Russia)</option>
                        <option value="Australia" <?php echo ($filters['wheat_origin'] ?? '') == 'Australia' ? 'selected' : ''; ?>>Australia</option>
                        <option value="Ukraine" <?php echo ($filters['wheat_origin'] ?? '') == 'Ukraine' ? 'selected' : ''; ?>>Ukraine</option>
                        <option value="India" <?php echo ($filters['wheat_origin'] ?? '') == 'India' ? 'selected' : ''; ?>>India</option>
                        <option value="Local" <?php echo ($filters['wheat_origin'] ?? '') == 'Local' ? 'selected' : ''; ?>>Local</option>
                    </select>
                </div>

                <!-- Delivery Status -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Delivery Status</label>
                    <select name="delivery_status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                        <option value="">All Status</option>
                        <option value="pending" <?php echo ($filters['delivery_status'] ?? '') == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="partial" <?php echo ($filters['delivery_status'] ?? '') == 'partial' ? 'selected' : ''; ?>>Partial</option>
                        <option value="completed" <?php echo ($filters['delivery_status'] ?? '') == 'completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>

                <!-- Payment Status -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Status</label>
                    <select name="payment_status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                        <option value="">All Status</option>
                        <option value="unpaid" <?php echo ($filters['payment_status'] ?? '') == 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                        <option value="partial" <?php echo ($filters['payment_status'] ?? '') == 'partial' ? 'selected' : ''; ?>>Partial</option>
                        <option value="paid" <?php echo ($filters['payment_status'] ?? '') == 'paid' ? 'selected' : ''; ?>>Paid</option>
                    </select>
                </div>

                <!-- Search -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search'] ?? ''); ?>" 
                           placeholder="PO Number, Supplier..." 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                </div>

                <!-- Action Buttons -->
                <div class="flex items-end gap-2">
                    <button type="submit" class="flex-1 bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700">
                        <i class="fas fa-search mr-1"></i> Apply
                    </button>
                    <a href="purchase_adnan_index.php" class="flex-1 bg-gray-200 text-gray-800 px-4 py-2 rounded-lg hover:bg-gray-300 text-center">
                        <i class="fas fa-times mr-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Purchase Orders Table -->
    <div class="bg-white rounded-lg shadow-md">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">
                <?php if (!empty($active_filters)): ?>
                    Filtered Purchase Orders 
                    <span class="text-sm font-normal text-gray-600">(<?php echo count($recent_orders); ?> results)</span>
                <?php else: ?>
                    Recent Purchase Orders
                <?php endif; ?>
            </h3>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order Number</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Supplier Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Origin</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ordered Quantity (KG)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Per Unit Cost</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Goods Received (KG)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Paid (৳)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-red-500 uppercase tracking-wider">Due (৳)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-blue-500 uppercase tracking-wider">Advance Paid (৳)</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($recent_orders)): ?>
                        <tr>
                            <td colspan="12" class="px-6 py-8 text-center text-gray-500">
                                <?php if (!empty($active_filters)): ?>
                                    <i class="fas fa-search text-4xl text-gray-300 mb-2"></i>
                                    <p>No purchase orders found matching your filters</p>
                                    <a href="purchase_adnan_index.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">Clear filters</a>
                                <?php else: ?>
                                    <p>No purchase orders found</p>
                                    <a href="purchase_adnan_create_po.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">Create your first PO</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_orders as $order): 
                            // Calculate expected payable based on GRN expected quantities
                            $expected_qty = getExpectedQuantityForPO($order->id);
                            $expected_payable = $expected_qty * $order->unit_price_per_kg;
                            $due_amount = $expected_payable - ($order->total_paid ?? 0);
                            
                            $per_unit = $order->quantity_kg > 0 ? ($order->total_order_value / $order->quantity_kg) : 0;
                        ?>
                            <tr class="hover:bg-gray-50">
                                <!-- Order Number -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="purchase_adnan_view_po.php?id=<?php echo $order->id; ?>" class="text-primary-600 hover:text-primary-800 font-medium">
                                        #<?php echo htmlspecialchars($order->po_number); ?>
                                    </a>
                                </td>
                                
                                <!-- Date -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo date('d M Y', strtotime($order->po_date)); ?>
                                </td>
                                
                                <!-- Supplier Name -->
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <?php echo htmlspecialchars($order->supplier_name); ?>
                                </td>
                                
                                <!-- Origin -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $order->wheat_origin === 'কানাডা' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'; ?>">
                                        <?php echo htmlspecialchars($order->wheat_origin); ?>
                                    </span>
                                </td>
                                
                                <!-- Ordered Quantity -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900 font-semibold">
                                    <?php echo number_format($order->quantity_kg, 0); ?>
                                </td>
                                
                                <!-- Per Unit Cost -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                                    ৳<?php echo number_format($per_unit, 2); ?>
                                </td>
                                
                                <!-- Goods Received -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold
                                    <?php 
                                    $received = $order->total_received_qty ?? 0;
                                    if ($received >= $order->quantity_kg) {
                                        echo 'text-green-600';
                                    } elseif ($received > 0) {
                                        echo 'text-yellow-600';
                                    } else {
                                        echo 'text-gray-400';
                                    }
                                    ?>">
                                    <?php echo number_format($order->total_received_qty ?? 0, 2); ?>
                                </td>
                                
                                <!-- Paid -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-green-600 font-semibold">
                                    ৳<?php echo number_format($order->total_paid ?? 0, 0); ?>
                                </td>
                                
                                <?php
                                // Calculate balance: expected payable - paid
                                $balance = $expected_payable - ($order->total_paid ?? 0);
                                $actual_due    = $balance > 0 ? $balance : 0;
                                $advance_paid  = $balance < 0 ? abs($balance) : 0;
                                ?>
                                
                                <!-- Due (only if balance is positive) -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold">
                                    <?php if ($actual_due > 0): ?>
                                        <span class="text-red-600">৳<?php echo number_format($actual_due, 0); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-300">—</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Advance Paid (only if overpaid / balance is negative) -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold">
                                    <?php if ($advance_paid > 0): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 text-xs font-bold">
                                            <i class="fas fa-arrow-up text-[9px]"></i>৳<?php echo number_format($advance_paid, 0); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-300">—</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Status -->
                                <td class="px-6 py-4 whitespace-nowrap text-center">
    <?php
    // 1. Determine base values
    $delivery = $order->delivery_status;
    $payment = $order->payment_status;
    
    // 2. Fajracct Style Mapping
    if ($delivery === 'closed') {
        $status_text = 'Closed';
        $status_class = 'bg-purple-100 text-purple-700 border border-purple-200';
    } elseif ($delivery === 'completed' && $payment === 'paid') {
        $status_text = 'Complete';
        $status_class = 'bg-emerald-100 text-emerald-700 border border-emerald-200';
    } elseif ($delivery === 'over_received') {
        $status_text = 'Over Delivered';
        $status_class = 'bg-blue-100 text-blue-700 border border-blue-200';
    } elseif ($delivery === 'partial' || $payment === 'partial' || ($delivery === 'completed' && $payment !== 'paid')) {
        $status_text = 'In Progress';
        $status_class = 'bg-amber-100 text-amber-700 border border-amber-200';
    } else {
        $status_text = 'Pending';
        $status_class = 'bg-slate-100 text-slate-600 border border-slate-200';
    }
    ?>
    <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-wider <?php echo $status_class; ?>">
        <?php echo $status_text; ?>
    </span>
</td>
                                
                                <!-- Actions -->
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <!-- View Button -->
                                        <a href="purchase_adnan_view_po.php?id=<?php echo $order->id; ?>" 
                                           class="text-primary-600 hover:text-primary-800" 
                                           title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <?php if ($is_superadmin): ?>
                                            <!-- Edit Button -->
                                            <a href="purchase_adnan_edit_po.php?id=<?php echo $order->id; ?>" 
                                               class="text-blue-600 hover:text-blue-800" 
                                               title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <!-- Close Deal Button (if not closed) -->
                                            <?php if ($order->delivery_status !== 'closed' && $order->delivery_status !== 'completed'): ?>
                                                <button onclick="closePO(<?php echo $order->id; ?>, '<?php echo htmlspecialchars($order->po_number); ?>')" 
                                                        class="text-orange-600 hover:text-orange-800" 
                                                        title="Close Deal">
                                                    <i class="fas fa-lock"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <!-- Delete Button -->
                                            <button onclick="deletePO(<?php echo $order->id; ?>, '<?php echo htmlspecialchars($order->po_number); ?>')" 
                                                    class="text-red-600 hover:text-red-800" 
                                                    title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <!-- Totals Row -->
                        <tr class="bg-gray-100 font-bold">
                            <td colspan="4" class="px-6 py-4 text-sm text-right text-gray-900">
                                <i class="fas fa-calculator mr-2"></i>TOTALS:
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                                <?php echo number_format($totals['ordered_quantity'], 0); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                                <!-- Average per unit -->
                                ৳<?php echo $totals['ordered_quantity'] > 0 ? number_format($totals['total_value'] / $totals['ordered_quantity'], 2) : '0.00'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                                <?php echo number_format($totals['goods_received'], 0); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-green-600">
                                ৳<?php echo number_format($totals['paid'], 0); ?>
                            </td>
                            <!-- Total Due -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-red-600">
                                <?php if ($totals['due'] > 0): ?>
                                    ৳<?php echo number_format($totals['due'], 0); ?>
                                <?php else: ?>
                                    <span class="text-gray-400">—</span>
                                <?php endif; ?>
                            </td>
                            <!-- Total Advance Paid -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-blue-600">
                                <?php if ($totals['advance'] > 0): ?>
                                    ৳<?php echo number_format($totals['advance'], 0); ?>
                                <?php else: ?>
                                    <span class="text-gray-400">—</span>
                                <?php endif; ?>
                            </td>
                            <td colspan="2" class="px-6 py-4"></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function toggleFilters() {
    const panel = document.getElementById('filterPanel');
    const toggleText = document.getElementById('filterToggleText');
    
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        toggleText.textContent = 'Hide Filters';
    } else {
        panel.style.display = 'none';
        toggleText.textContent = 'Show Filters';
    }
}

// Auto-show filters if any filter is active
<?php if (!empty($active_filters)): ?>
document.addEventListener('DOMContentLoaded', function() {
    toggleFilters();
});
<?php endif; ?>

function closePO(poId, poNumber) {
    if (confirm(`Are you sure you want to CLOSE PO #${poNumber}?\n\nThis will:\n- Prevent further goods receipt\n- Mark delivery as "closed"\n- Keep all existing records\n\nThis action can be reversed by Superadmin.`)) {
        fetch('purchase_adnan_close_po.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'po_id=' + poId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error closing PO: ' + error);
        });
    }
}

function deletePO(poId, poNumber) {
    if (confirm(`⚠️ WARNING: Are you sure you want to DELETE PO #${poNumber}?\n\nThis will:\n- Mark the PO as cancelled\n- NOT delete related GRNs and payments\n- Hide it from active lists\n\nThis action can be reversed by Superadmin.`)) {
        const confirmText = prompt('Type "DELETE" to confirm deletion:');
        if (confirmText === 'DELETE') {
            fetch('purchase_adnan_delete_po.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'po_id=' + poId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error deleting PO: ' + error);
            });
        }
    }
}
</script>

<?php include '../templates/footer.php'; ?>
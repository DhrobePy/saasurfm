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

// Get orders with filters
if (empty($filters) || (count($filters) === 1 && isset($filters['limit']))) {
    // No filters applied - show recent 10 orders
    $filters['limit'] = 10;
    $recent_orders = $purchaseManager->listPurchaseOrders($filters);
} else {
    // Filters applied - show filtered results
    $recent_orders = $purchaseManager->listPurchaseOrders($filters);
}

// Get unique suppliers for filter dropdown
$all_suppliers = $purchaseManager->getAllSuppliers();

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
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Total Orders -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm font-medium">Total Orders</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($stats->total_orders ?? 0); ?></p>
                </div>
                <div class="bg-primary-100 rounded-full p-3">
                    <i class="fas fa-shopping-cart text-primary-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Total Order Value -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm font-medium">Total Order Value</p>
                    <p class="text-2xl font-bold text-gray-900 mt-2">৳<?php echo number_format($stats->total_order_value ?? 0, 0); ?></p>
                </div>
                <div class="bg-blue-100 rounded-full p-3">
                    <i class="fas fa-money-bill-wave text-blue-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Total Paid -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm font-medium">Total Paid</p>
                    <p class="text-2xl font-bold text-green-600 mt-2">৳<?php echo number_format($stats->total_paid ?? 0, 0); ?></p>
                </div>
                <div class="bg-green-100 rounded-full p-3">
                    <i class="fas fa-check-circle text-green-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Balance Payable -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm font-medium">Balance Payable</p>
                    <p class="text-2xl font-bold text-red-600 mt-2">৳<?php echo number_format($stats->balance_payable ?? 0, 0); ?></p>
                </div>
                <div class="bg-red-100 rounded-full p-3">
                    <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <a href="create_grn.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow">
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

        <a href="create_payment.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow">
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
                    <i class="fas fa-balance-scale text-orange-600 text-2xl"></i>
                </div>
                <div>
                    <h4 class="font-semibold text-gray-900">Weight Variance</h4>
                    <p class="text-sm text-gray-600">View variance analysis</p>
                </div>
            </div>
        </a>
    </div>

    <!-- Recent Orders with Filters -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <!-- Header with Filter Toggle -->
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-xl font-semibold text-gray-900">
                <?php 
                $active_filters = array_filter($_GET ?? [], function($value) {
                    return $value !== '' && $value !== null;
                });
                if (!empty($active_filters)): 
                ?>
                    Filtered Purchase Orders 
                    <span class="text-sm text-gray-500 font-normal">(<?php echo count($recent_orders); ?> results)</span>
                <?php else: ?>
                    Recent Purchase Orders
                <?php endif; ?>
            </h2>
            <button onclick="toggleFilters()" class="text-primary-600 hover:text-primary-700 flex items-center gap-2">
                <i class="fas fa-filter"></i>
                <span id="filterToggleText">Show Filters</span>
            </button>
        </div>

        <!-- Filter Panel (Hidden by default) -->
        <div id="filterPanel" class="bg-gray-50 px-6 py-4 border-b border-gray-200" style="display: none;">
            <form method="GET" action="" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Date From -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                        <input type="date" 
                               name="date_from" 
                               value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500">
                    </div>

                    <!-- Date To -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                        <input type="date" 
                               name="date_to" 
                               value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500">
                    </div>

                    <!-- Supplier -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
                        <select name="supplier_id" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500">
                            <option value="">All Suppliers</option>
                            <?php if ($all_suppliers): ?>
                                <?php foreach ($all_suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier->id; ?>" 
                                            <?php echo (isset($_GET['supplier_id']) && $_GET['supplier_id'] == $supplier->id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($supplier->supplier_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- Wheat Origin -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Wheat Origin</label>
                        <select name="wheat_origin" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500">
                            <option value="">All Origins</option>
                            <option value="কানাডা" <?php echo (isset($_GET['wheat_origin']) && $_GET['wheat_origin'] === 'কানাডা') ? 'selected' : ''; ?>>কানাডা</option>
                            <option value="রাশিয়া" <?php echo (isset($_GET['wheat_origin']) && $_GET['wheat_origin'] === 'রাশিয়া') ? 'selected' : ''; ?>>রাশিয়া</option>
                            <option value="Australia" <?php echo (isset($_GET['wheat_origin']) && $_GET['wheat_origin'] === 'Australia') ? 'selected' : ''; ?>>Australia</option>
                            <option value="Ukraine" <?php echo (isset($_GET['wheat_origin']) && $_GET['wheat_origin'] === 'Ukraine') ? 'selected' : ''; ?>>Ukraine</option>
                            <option value="India" <?php echo (isset($_GET['wheat_origin']) && $_GET['wheat_origin'] === 'India') ? 'selected' : ''; ?>>India</option>
                            <option value="Argentina" <?php echo (isset($_GET['wheat_origin']) && $_GET['wheat_origin'] === 'Argentina') ? 'selected' : ''; ?>>Argentina</option>
                            <option value="USA" <?php echo (isset($_GET['wheat_origin']) && $_GET['wheat_origin'] === 'USA') ? 'selected' : ''; ?>>USA</option>
                            <option value="Local" <?php echo (isset($_GET['wheat_origin']) && $_GET['wheat_origin'] === 'Local') ? 'selected' : ''; ?>>Local</option>
                        </select>
                    </div>

                    <!-- Delivery Status -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Delivery Status</label>
                        <select name="delivery_status" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500">
                            <option value="">All Statuses</option>
                            <option value="pending" <?php echo (isset($_GET['delivery_status']) && $_GET['delivery_status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="partial" <?php echo (isset($_GET['delivery_status']) && $_GET['delivery_status'] === 'partial') ? 'selected' : ''; ?>>Partial</option>
                            <option value="completed" <?php echo (isset($_GET['delivery_status']) && $_GET['delivery_status'] === 'completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="over_received" <?php echo (isset($_GET['delivery_status']) && $_GET['delivery_status'] === 'over_received') ? 'selected' : ''; ?>>Over Received</option>
                            <option value="closed" <?php echo (isset($_GET['delivery_status']) && $_GET['delivery_status'] === 'closed') ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>

                    <!-- Payment Status -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Payment Status</label>
                        <select name="payment_status" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500">
                            <option value="">All Statuses</option>
                            <option value="unpaid" <?php echo (isset($_GET['payment_status']) && $_GET['payment_status'] === 'unpaid') ? 'selected' : ''; ?>>Unpaid</option>
                            <option value="partial" <?php echo (isset($_GET['payment_status']) && $_GET['payment_status'] === 'partial') ? 'selected' : ''; ?>>Partial</option>
                            <option value="paid" <?php echo (isset($_GET['payment_status']) && $_GET['payment_status'] === 'paid') ? 'selected' : ''; ?>>Paid</option>
                            <option value="overpaid" <?php echo (isset($_GET['payment_status']) && $_GET['payment_status'] === 'overpaid') ? 'selected' : ''; ?>>Overpaid</option>
                        </select>
                    </div>

                    <!-- PO Status -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">PO Status</label>
                        <select name="po_status" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500">
                            <option value="">All Statuses</option>
                            <option value="draft" <?php echo (isset($_GET['po_status']) && $_GET['po_status'] === 'draft') ? 'selected' : ''; ?>>Draft</option>
                            <option value="approved" <?php echo (isset($_GET['po_status']) && $_GET['po_status'] === 'approved') ? 'selected' : ''; ?>>Approved</option>
                            <option value="partial" <?php echo (isset($_GET['po_status']) && $_GET['po_status'] === 'partial') ? 'selected' : ''; ?>>Partial</option>
                            <option value="completed" <?php echo (isset($_GET['po_status']) && $_GET['po_status'] === 'completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo (isset($_GET['po_status']) && $_GET['po_status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>

                    <!-- Search PO Number -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Search PO Number</label>
                        <input type="text" 
                               name="search" 
                               value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                               placeholder="Enter PO number..."
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500">
                    </div>
                </div>

                <!-- Filter Actions -->
                <div class="flex gap-2 pt-2">
                    <button type="submit" class="bg-primary-600 text-white px-4 py-2 rounded-md hover:bg-primary-700 flex items-center gap-2">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <a href="purchase_adnan_index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300 flex items-center gap-2">
                        <i class="fas fa-times"></i> Clear Filters
                    </a>
                    <?php if (!empty($active_filters)): ?>
                    <div class="flex items-center gap-2 ml-4">
                        <span class="text-sm text-gray-600">
                            <i class="fas fa-filter text-primary-600"></i> 
                            <?php echo count($active_filters); ?> filter(s) active
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PO#</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Supplier</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Origin</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity (KG)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Value</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Delivery</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Payment</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($recent_orders)): ?>
                        <tr>
                            <td colspan="9" class="px-6 py-8 text-center text-gray-500">
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
                        <?php foreach ($recent_orders as $order): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="purchase_adnan_view_po.php?id=<?php echo $order->id; ?>" class="text-primary-600 hover:text-primary-800 font-medium">
                                        #<?php echo htmlspecialchars($order->po_number); ?>
                                    </a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo date('M d, Y', strtotime($order->po_date)); ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <?php echo htmlspecialchars($order->supplier_name); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $order->wheat_origin === 'কানাডা' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'; ?>">
                                        <?php echo htmlspecialchars($order->wheat_origin); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                                    <?php echo number_format($order->quantity_kg, 0); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                                    ৳<?php echo number_format($order->total_order_value, 0); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $delivery_badges = [
                                        'pending' => 'bg-gray-100 text-gray-800',
                                        'partial' => 'bg-yellow-100 text-yellow-800',
                                        'completed' => 'bg-green-100 text-green-800',
                                        'closed' => 'bg-red-100 text-red-800',
                                        'over_received' => 'bg-blue-100 text-blue-800'
                                    ];
                                    $badge_class = $delivery_badges[$order->delivery_status] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $badge_class; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $order->delivery_status)); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $payment_badges = [
                                        'unpaid' => 'bg-red-100 text-red-800',
                                        'partial' => 'bg-yellow-100 text-yellow-800',
                                        'paid' => 'bg-green-100 text-green-800',
                                        'overpaid' => 'bg-purple-100 text-purple-800'
                                    ];
                                    $badge_class = $payment_badges[$order->payment_status] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $badge_class; ?>">
                                        <?php echo ucfirst($order->payment_status); ?>
                                    </span>
                                </td>
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
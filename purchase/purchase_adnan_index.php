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

// Get dashboard statistics
$stats = $purchaseManager->getDashboardStats();
$supplier_summary = $purchaseManager->getSupplierSummary();
$stats_by_origin = $purchaseManager->getStatsByOrigin();
$recent_orders = $purchaseManager->listPurchaseOrders(['limit' => 10]);

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
            <a href="supplier_summary.php" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 flex items-center gap-2">
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
                    <i class="fas fa-exclamation-circle text-red-600 text-2xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Wheat Origin Distribution -->
        

        <!-- Delivery & Payment Status -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Order Status</h3>
            <div class="space-y-4">
                <div>
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-gray-600">Delivery Completed</span>
                        <span class="font-semibold"><?php echo $stats->completed_deliveries ?? 0; ?> / <?php echo $stats->total_orders ?? 0; ?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-green-600 h-2 rounded-full" style="width: <?php echo $stats->total_orders > 0 ? ($stats->completed_deliveries / $stats->total_orders * 100) : 0; ?>%"></div>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-gray-600">Payment Completed</span>
                        <span class="font-semibold"><?php echo $stats->completed_payments ?? 0; ?> / <?php echo $stats->total_orders ?? 0; ?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-primary-600 h-2 rounded-full" style="width: <?php echo $stats->total_orders > 0 ? ($stats->completed_payments / $stats->total_orders * 100) : 0; ?>%"></div>
                    </div>
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

    <!-- Recent Orders Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-lg font-semibold text-gray-900">Recent Purchase Orders</h3>
            <a href="purchase_orders.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">View All →</a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PO#</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Supplier</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Origin</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Qty (KG)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Value</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Delivery</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($recent_orders)): ?>
                        <tr>
                            <td colspan="9" class="px-6 py-8 text-center text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-2"></i>
                                <p>No purchase orders found</p>
                                <a href="create_po.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">Create your first PO</a>
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
                                    <a href="purchase_adnan_view_po.php?id=<?php echo $order->id; ?>" class="text-primary-600 hover:text-primary-800 mx-1" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
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
// Chart for wheat origin distribution
<?php if (!empty($stats_by_origin)): ?>
const ctx = document.getElementById('originChart').getContext('2d');
const originChart = new Chart(ctx, {
    type: 'doughnut',
    data: {
        labels: [<?php echo implode(',', array_map(function($s) { return '"' . $s->wheat_origin . '"'; }, $stats_by_origin)); ?>],
        datasets: [{
            data: [<?php echo implode(',', array_map(function($s) { return $s->order_count; }, $stats_by_origin)); ?>],
            backgroundColor: ['#3B82F6', '#10B981'],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
            }
        }
    }
});
<?php endif; ?>
</script>

<?php include '../templates/footer.php'; ?>
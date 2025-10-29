<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 
                  'accounts-demra', 'sales-srg', 'sales-demra', 'sales-other',
                  'production-srg', 'production-demra', 'production-rampura',
                  'dispatcher-srg', 'dispatcher-demra', 'dispatchpos-demra', 'dispatchpos-srg'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Credit Sales Dashboard';

// Determine user capabilities
$is_admin = in_array($user_role, ['Superadmin', 'admin']);
$is_accounts = in_array($user_role, ['Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra']);
$is_sales = in_array($user_role, ['sales-srg', 'sales-demra', 'sales-other']);
$is_production = in_array($user_role, ['production-srg', 'production-demra', 'production-rampura']);
$is_dispatcher = in_array($user_role, ['dispatcher-srg', 'dispatcher-demra', 'dispatchpos-demra', 'dispatchpos-srg']);

// Get user's branch if applicable
$user_branch = null;
if (!$is_admin && !$is_accounts) {
    $emp = $db->query("SELECT branch_id FROM employees WHERE user_id = ?", [$user_id])->first();
    $user_branch = $emp ? $emp->branch_id : null;
}

// Get statistics based on role
$stats = [];

// Orders pending approval (Accounts + Admin)
if ($is_accounts || $is_admin) {
    $pending = $db->query("SELECT COUNT(*) as count FROM credit_orders WHERE status = 'pending_approval'")->first();
    $escalated = $db->query("SELECT COUNT(*) as count FROM credit_orders WHERE status = 'escalated'")->first();
    $stats['pending_approval'] = $pending->count ?? 0;
    $stats['escalated'] = $escalated->count ?? 0;
}

// Orders for production (Production Managers)
if ($is_production) {
    $branch_filter = $user_branch ? "AND assigned_branch_id = $user_branch" : "";
    $in_prod = $db->query("SELECT COUNT(*) as count FROM credit_orders WHERE status IN ('approved', 'in_production') $branch_filter")->first();
    $stats['for_production'] = $in_prod->count ?? 0;
}

// Ready to ship (Dispatchers)
if ($is_dispatcher) {
    $branch_filter = $user_branch ? "AND assigned_branch_id = $user_branch" : "";
    $ready = $db->query("SELECT COUNT(*) as count FROM credit_orders WHERE status = 'ready_to_ship' $branch_filter")->first();
    $stats['ready_to_ship'] = $ready->count ?? 0;
}

// My orders (Sales Staff)
if ($is_sales) {
    $my_orders = $db->query("SELECT COUNT(*) as count FROM credit_orders WHERE created_by_user_id = ?", [$user_id])->first();
    $stats['my_orders'] = $my_orders->count ?? 0;
}

// Recent activity
$recent_orders = [];
if ($is_admin || $is_accounts) {
    $recent_orders = $db->query(
        "SELECT co.*, c.name as customer_name, u.display_name as created_by_name
         FROM credit_orders co
         JOIN customers c ON co.customer_id = c.id
         JOIN users u ON co.created_by_user_id = u.id
         ORDER BY co.created_at DESC
         LIMIT 10"
    )->results();
} elseif ($is_sales) {
    $recent_orders = $db->query(
        "SELECT co.*, c.name as customer_name
         FROM credit_orders co
         JOIN customers c ON co.customer_id = c.id
         WHERE co.created_by_user_id = ?
         ORDER BY co.created_at DESC
         LIMIT 10",
        [$user_id]
    )->results();
} elseif ($is_production || $is_dispatcher) {
    $branch_filter = $user_branch ? "AND co.assigned_branch_id = $user_branch" : "";
    $status_filter = $is_production ? "IN ('approved', 'in_production', 'produced')" : "IN ('ready_to_ship', 'shipped')";
    $recent_orders = $db->query(
        "SELECT co.*, c.name as customer_name
         FROM credit_orders co
         JOIN customers c ON co.customer_id = c.id
         WHERE co.status $status_filter $branch_filter
         ORDER BY co.created_at DESC
         LIMIT 10"
    )->results();
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Page Header -->
<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">Manage credit sales, orders, and customer accounts</p>
</div>

<!-- Quick Actions -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    
    <?php if ($is_sales): ?>
    <a href="credit_order_create.php" class="block p-6 bg-blue-600 hover:bg-blue-700 rounded-lg shadow-lg text-white transition-colors">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90">Create New</p>
                <p class="text-2xl font-bold mt-1">Credit Order</p>
            </div>
            <i class="fas fa-plus-circle text-4xl opacity-80"></i>
        </div>
    </a>
    <?php endif; ?>

    <?php if ($is_accounts || $is_admin): ?>
    <a href="credit_order_approval.php" class="block p-6 bg-green-600 hover:bg-green-700 rounded-lg shadow-lg text-white transition-colors">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90">Pending Approval</p>
                <p class="text-2xl font-bold mt-1"><?php echo $stats['pending_approval'] ?? 0; ?> Orders</p>
            </div>
            <i class="fas fa-check-circle text-4xl opacity-80"></i>
        </div>
    </a>
    <?php endif; ?>

    <?php if ($is_admin && isset($stats['escalated']) && $stats['escalated'] > 0): ?>
    <a href="credit_order_approval.php?view=escalated" class="block p-6 bg-red-600 hover:bg-red-700 rounded-lg shadow-lg text-white transition-colors">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90">Escalated</p>
                <p class="text-2xl font-bold mt-1"><?php echo $stats['escalated']; ?> Orders</p>
            </div>
            <i class="fas fa-exclamation-triangle text-4xl opacity-80"></i>
        </div>
    </a>
    <?php endif; ?>

    <?php if ($is_production): ?>
    <a href="credit_production.php" class="block p-6 bg-purple-600 hover:bg-purple-700 rounded-lg shadow-lg text-white transition-colors">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90">For Production</p>
                <p class="text-2xl font-bold mt-1"><?php echo $stats['for_production'] ?? 0; ?> Orders</p>
            </div>
            <i class="fas fa-industry text-4xl opacity-80"></i>
        </div>
    </a>
    <?php endif; ?>

    <?php if ($is_dispatcher): ?>
    <a href="credit_dispatch.php" class="block p-6 bg-orange-600 hover:bg-orange-700 rounded-lg shadow-lg text-white transition-colors">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90">Ready to Ship</p>
                <p class="text-2xl font-bold mt-1"><?php echo $stats['ready_to_ship'] ?? 0; ?> Orders</p>
            </div>
            <i class="fas fa-truck text-4xl opacity-80"></i>
        </div>
    </a>
    <?php endif; ?>

    <?php if ($is_accounts || $is_admin): ?>
    <a href="customer_payment.php" class="block p-6 bg-teal-600 hover:bg-teal-700 rounded-lg shadow-lg text-white transition-colors">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90">Record</p>
                <p class="text-2xl font-bold mt-1">Payment</p>
            </div>
            <i class="fas fa-money-bill-wave text-4xl opacity-80"></i>
        </div>
    </a>
    <?php endif; ?>

</div>

<!-- Main Content -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Recent Orders -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-lg shadow-md">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-xl font-bold text-gray-800">Recent Orders</h2>
            </div>
            <div class="overflow-x-auto">
                <?php if (!empty($recent_orders)): ?>
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($recent_orders as $order): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                                <?php echo htmlspecialchars($order->order_number); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($order->customer_name); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo date('M j, Y', strtotime($order->order_date)); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-900">
                                ৳<?php echo number_format($order->total_amount, 2); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $status_colors = [
                                    'pending_approval' => 'yellow',
                                    'approved' => 'green',
                                    'escalated' => 'red',
                                    'rejected' => 'gray',
                                    'in_production' => 'blue',
                                    'produced' => 'purple',
                                    'ready_to_ship' => 'indigo',
                                    'shipped' => 'teal',
                                    'delivered' => 'green'
                                ];
                                $color = $status_colors[$order->status] ?? 'gray';
                                ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-800">
                                    <?php echo ucwords(str_replace('_', ' ', $order->status)); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                <a href="credit_order_view.php?id=<?php echo $order->id; ?>" 
                                   class="text-blue-600 hover:text-blue-900">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="p-8 text-center text-gray-500">
                    <i class="fas fa-inbox text-4xl mb-3"></i>
                    <p>No orders found</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Links Sidebar -->
    <div class="space-y-6">
        
        <!-- Navigation -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Quick Links</h3>
            <div class="space-y-2">
                <?php if ($is_sales): ?>
                <a href="credit_order_create.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">
                    <i class="fas fa-plus-circle w-5 mr-2 text-blue-500"></i>Create Order
                </a>
                <?php endif; ?>
                
                <?php if ($is_accounts || $is_admin): ?>
                <a href="credit_order_approval.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">
                    <i class="fas fa-check-circle w-5 mr-2 text-green-500"></i>Approve Orders
                </a>
                <a href="customer_credit_management.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">
                    <i class="fas fa-credit-card w-5 mr-2 text-purple-500"></i>Manage Credit Limits
                </a>
                <a href="customer_payment.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">
                    <i class="fas fa-money-bill-wave w-5 mr-2 text-teal-500"></i>Record Payment
                </a>
                <a href="customer_ledger.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">
                    <i class="fas fa-book w-5 mr-2 text-indigo-500"></i>Customer Ledger
                </a>
                <?php endif; ?>
                
                <?php if ($is_production): ?>
                <a href="credit_production.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">
                    <i class="fas fa-industry w-5 mr-2 text-purple-500"></i>Production Queue
                </a>
                <?php endif; ?>
                
                <?php if ($is_dispatcher): ?>
                <a href="credit_dispatch.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">
                    <i class="fas fa-truck w-5 mr-2 text-orange-500"></i>Dispatch Orders
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Help -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-5">
            <h3 class="text-sm font-medium text-blue-800 mb-2">Your Role: <?php echo $user_role; ?></h3>
            <div class="text-sm text-blue-700 space-y-1">
                <?php if ($is_sales): ?>
                    <p>• Create credit orders for customers</p>
                    <p>• Track order status</p>
                    <p>• View customer credit limits</p>
                <?php elseif ($is_accounts || $is_admin): ?>
                    <p>• Approve orders within credit limit</p>
                    <p>• Escalate large orders to admin</p>
                    <p>• Manage customer credit limits</p>
                    <p>• Record customer payments</p>
                <?php elseif ($is_production): ?>
                    <p>• View assigned production orders</p>
                    <p>• Update production status</p>
                    <p>• Mark orders as ready to ship</p>
                <?php elseif ($is_dispatcher): ?>
                    <p>• View orders ready to ship</p>
                    <p>• Enter truck & driver details</p>
                    <p>• Print shipping invoices</p>
                    <p>• Mark orders as delivered</p>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div>

</div>

<?php require_once '../templates/footer.php'; ?>
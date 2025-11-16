<?php
require_once '../core/init.php';

/* -----------------------------
   SECURITY & ACCESS CONTROL
----------------------------- */
$allowed_roles = [
    'Superadmin', 'admin', 'Accounts',
    'accounts-rampura', 'accounts-srg', 'accounts-demra',
    'sales-srg', 'sales-demra', 'sales-other',
    'production manager-srg', 'production manager-demra', 'production manager-rampura',
    'dispatch-srg', 'dispatch-demra', 'dispatchpos-demra', 'dispatchpos-srg',
    'collector'
];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id     = $currentUser['id'] ?? null;
$user_role   = $currentUser['role'] ?? '';
$pageTitle   = 'Credit Sales Dashboard';

/* -----------------------------
   ROLE CLASSIFICATIONS
----------------------------- */
$is_admin       = in_array($user_role, ['Superadmin', 'admin']);
$is_accounts    = in_array($user_role, ['Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra']);
$is_sales       = in_array($user_role, ['sales-srg', 'sales-demra', 'sales-other']);
$is_production  = in_array($user_role, ['production manager-srg', 'production manager-demra', 'production manager-rampura']);
$is_dispatcher  = in_array($user_role, ['dispatch-srg', 'dispatch-demra', 'dispatchpos-demra', 'dispatchpos-srg']);

/* -----------------------------
   USER BRANCH DETECTION
----------------------------- */
$user_branch = null;
if (!$is_admin && !$is_accounts && $user_id) {
    $emp = $db->query("SELECT branch_id FROM employees WHERE user_id = ?", [$user_id])->first();
    $user_branch = $emp ? $emp->branch_id : null;
}

/* -----------------------------
   DASHBOARD STATISTICS
----------------------------- */
$stats = [];

// Pending approvals (Accounts/Admin)
if ($is_accounts || $is_admin) {
    $pending    = $db->query("SELECT COUNT(*) AS count FROM credit_orders WHERE status = 'pending_approval'")->first();
    $escalated  = $db->query("SELECT COUNT(*) AS count FROM credit_orders WHERE status = 'escalated'")->first();
    $stats['pending_approval'] = $pending->count ?? 0;
    $stats['escalated']        = $escalated->count ?? 0;
}

// Production queue (Production Managers)
if ($is_production) {
    $branch_filter = $user_branch
        ? "AND assigned_branch_id = {$db->getPdo()->quote($user_branch)}"
        : "";
    $in_prod = $db->query("
        SELECT COUNT(*) AS count
        FROM credit_orders
        WHERE status IN ('approved', 'in_production') $branch_filter
    ")->first();
    $stats['for_production'] = $in_prod->count ?? 0;
}

// Ready to ship (Dispatchers)
if ($is_dispatcher) {
    $branch_filter = $user_branch
        ? "AND assigned_branch_id = {$db->getPdo()->quote($user_branch)}"
        : "";
    $ready = $db->query("
        SELECT COUNT(*) AS count
        FROM credit_orders
        WHERE status = 'ready_to_ship' $branch_filter
    ")->first();
    $stats['ready_to_ship'] = $ready->count ?? 0;
}

// My orders (Sales)
if ($is_sales && $user_id) {
    $my_orders = $db->query("
        SELECT COUNT(*) AS count
        FROM credit_orders
        WHERE created_by_user_id = ?
    ", [$user_id])->first();
    $stats['my_orders'] = $my_orders->count ?? 0;
}

/* -----------------------------
   ORDER HISTORY & FILTERING
----------------------------- */
// Default filters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to'] ?? date('Y-m-d');
$status_filter = $_GET['status'] ?? '';

// Build Query based on Role and Filters
$sql_conditions = ["co.order_date BETWEEN ? AND ?"];
$sql_params = [$date_from, $date_to];

if (!empty($status_filter)) {
    $sql_conditions[] = "co.status = ?";
    $sql_params[] = $status_filter;
}

// Role-specific constraints
if ($is_sales && $user_id) {
    $sql_conditions[] = "co.created_by_user_id = ?";
    $sql_params[] = $user_id;
} elseif (($is_production || $is_dispatcher) && $user_branch !== null) {
    $sql_conditions[] = "co.assigned_branch_id = ?";
    $sql_params[] = $user_branch;
    
    // Filter specific statuses for production/dispatch views if needed, 
    // or let them see everything history-wise for their branch.
}

$where_clause = implode(' AND ', $sql_conditions);

$orders_query = "
    SELECT co.*, 
           c.name AS customer_name, 
           u.display_name AS created_by_name,
           (co.total_amount - co.amount_paid) as due_amount
    FROM credit_orders co
    JOIN customers c ON co.customer_id = c.id
    JOIN users u ON co.created_by_user_id = u.id
    WHERE $where_clause
    ORDER BY co.order_date DESC, co.id DESC
    LIMIT 100
";

$orders = $db->query($orders_query, $sql_params)->results();

require_once '../templates/header.php';
?>

<!-- ======================
        PAGE HEADER
======================= -->
<div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">
        Manage credit sales, orders, and customer accounts relevant to your role.
    </p>
</div>

<!-- ======================
       STATISTIC CARDS
======================= -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 gap-6 mb-8">

    <?php if ($is_sales || $is_admin): ?>
    <a href="create_order.php" class="block p-6 bg-primary-600 hover:bg-primary-700 rounded-lg shadow-lg text-white transition-colors group">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90 uppercase tracking-wider">Action</p>
                <p class="text-2xl font-bold mt-1">Create Order</p>
            </div>
            <i class="fas fa-plus-circle text-4xl opacity-70 group-hover:opacity-100 transform group-hover:scale-110 transition-transform"></i>
        </div>
    </a>
    <?php endif; ?>

    <?php if ($is_accounts || $is_admin): ?>
    <a href="credit_order_approval.php" class="block p-6 bg-green-600 hover:bg-green-700 rounded-lg shadow-lg text-white transition-colors group">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90 uppercase tracking-wider">For Approval</p>
                <p class="text-3xl font-bold mt-1"><?php echo $stats['pending_approval'] ?? 0; ?></p>
            </div>
            <i class="fas fa-check-circle text-4xl opacity-70 group-hover:opacity-100 transform group-hover:scale-110 transition-transform"></i>
        </div>
    </a>
    <?php endif; ?>

    <?php if ($is_admin && !empty($stats['escalated'])): ?>
    <a href="credit_order_approval.php?view=escalated" class="block p-6 bg-red-600 hover:bg-red-700 rounded-lg shadow-lg text-white transition-colors group">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90 uppercase tracking-wider">Escalated</p>
                <p class="text-3xl font-bold mt-1"><?php echo $stats['escalated']; ?></p>
            </div>
            <i class="fas fa-exclamation-triangle text-4xl opacity-70 group-hover:opacity-100 transform group-hover:scale-110 transition-transform"></i>
        </div>
    </a>
    <?php endif; ?>

    <?php if ($is_production): ?>
    <a href="credit_production.php" class="block p-6 bg-purple-600 hover:bg-purple-700 rounded-lg shadow-lg text-white transition-colors group">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90 uppercase tracking-wider">In Production Queue</p>
                <p class="text-3xl font-bold mt-1"><?php echo $stats['for_production'] ?? 0; ?></p>
            </div>
            <i class="fas fa-industry text-4xl opacity-70 group-hover:opacity-100 transform group-hover:scale-110 transition-transform"></i>
        </div>
    </a>
    <?php endif; ?>

    <?php if ($is_dispatcher): ?>
    <a href="credit_dispatch.php" class="block p-6 bg-orange-500 hover:bg-orange-600 rounded-lg shadow-lg text-white transition-colors group">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90 uppercase tracking-wider">Ready to Ship</p>
                <p class="text-3xl font-bold mt-1"><?php echo $stats['ready_to_ship'] ?? 0; ?></p>
            </div>
            <i class="fas fa-truck text-4xl opacity-70 group-hover:opacity-100 transform group-hover:scale-110 transition-transform"></i>
        </div>
    </a>
    <?php endif; ?>

    <?php if ($is_accounts || $is_admin): ?>
    <a href="customer_payment.php" class="block p-6 bg-teal-600 hover:bg-teal-700 rounded-lg shadow-lg text-white transition-colors group">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90 uppercase tracking-wider">Action</p>
                <p class="text-2xl font-bold mt-1">Record Payment</p>
            </div>
            <i class="fas fa-money-bill-wave text-4xl opacity-70 group-hover:opacity-100 transform group-hover:scale-110 transition-transform"></i>
        </div>
    </a>
    <?php endif; ?>

</div>

<!-- ======================
        QUICK ACTIONS
======================= -->
<div class="bg-white rounded-lg shadow-md mb-8 p-4 border border-gray-200">
    <div class="flex flex-wrap gap-4 justify-center md:justify-start">

        <?php if ($is_sales || $is_admin): ?>
        <a href="create_order.php" class="flex flex-col items-center p-3 text-gray-600 hover:bg-primary-50 hover:text-primary-700 rounded-lg transition-colors" title="Create Order">
            <i class="fas fa-plus-circle text-2xl mb-1"></i>
            <span class="text-xs font-medium">Create</span>
        </a>
        <?php endif; ?>

        <?php if ($is_accounts || $is_admin): ?>
        <a href="credit_order_approval.php" class="flex flex-col items-center p-3 text-gray-600 hover:bg-green-50 hover:text-green-700 rounded-lg transition-colors" title="Approve Orders">
            <i class="fas fa-check-circle text-2xl mb-1"></i>
            <span class="text-xs font-medium">Approve</span>
        </a>
        <a href="customer_credit_management.php" class="flex flex-col items-center p-3 text-gray-600 hover:bg-purple-50 hover:text-purple-700 rounded-lg transition-colors" title="Manage Credit Limits">
            <i class="fas fa-credit-card text-2xl mb-1"></i>
            <span class="text-xs font-medium">Limits</span>
        </a>
        <a href="customer_payment.php" class="flex flex-col items-center p-3 text-gray-600 hover:bg-teal-50 hover:text-teal-700 rounded-lg transition-colors" title="Record Payment">
            <i class="fas fa-money-bill-wave text-2xl mb-1"></i>
            <span class="text-xs font-medium">Payment</span>
        </a>
        <a href="customer_ledger.php" class="flex flex-col items-center p-3 text-gray-600 hover:bg-indigo-50 hover:text-indigo-700 rounded-lg transition-colors" title="Customer Ledger">
            <i class="fas fa-book text-2xl mb-1"></i>
            <span class="text-xs font-medium">Ledger</span>
        </a>
        <a href="cr_invoice.php" class="flex flex-col items-center p-3 text-gray-600 hover:bg-gray-100 hover:text-gray-800 rounded-lg transition-colors" title="Create Invoice">
            <i class="fas fa-file-invoice-dollar text-2xl mb-1"></i>
            <span class="text-xs font-medium">Invoice</span>
        </a>
        <?php endif; ?>

        <?php if ($is_production): ?>
        <a href="credit_production.php" class="flex flex-col items-center p-3 text-gray-600 hover:bg-purple-50 hover:text-purple-700 rounded-lg transition-colors" title="Production Queue">
            <i class="fas fa-industry text-2xl mb-1"></i>
            <span class="text-xs font-medium">Production</span>
        </a>
        <?php endif; ?>

        <?php if ($is_dispatcher): ?>
        <a href="credit_dispatch.php" class="flex flex-col items-center p-3 text-gray-600 hover:bg-orange-50 hover:text-orange-600 rounded-lg transition-colors" title="Dispatch Orders">
            <i class="fas fa-truck text-2xl mb-1"></i>
            <span class="text-xs font-medium">Dispatch</span>
        </a>
        <?php endif; ?>

        <!-- Common link -->
        <a href="order_status.php" class="flex flex-col items-center p-3 text-gray-600 hover:bg-gray-100 hover:text-gray-800 rounded-lg transition-colors" title="Track Order Status">
            <i class="fas fa-tasks text-2xl mb-1"></i>
            <span class="text-xs font-medium">Status</span>
        </a>
    </div>
</div>

<!-- ======================
    CREDIT ORDER HISTORY
======================= -->
<div class="bg-white rounded-lg shadow-lg overflow-hidden mb-8">
    <div class="p-5 border-b border-gray-200 bg-gray-50 flex flex-wrap justify-between items-center gap-4">
        <h2 class="text-xl font-bold text-gray-800 flex items-center">
            <i class="fas fa-history mr-2 text-blue-600"></i> Credit Order History
        </h2>
        
        <div class="flex flex-wrap gap-3 w-full md:w-auto">
            <!-- Search & Filter Form -->
            <form method="GET" class="flex flex-wrap gap-2 w-full md:w-auto">
                <input type="date" name="date_from" value="<?php echo $date_from; ?>" class="px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                <input type="date" name="date_to" value="<?php echo $date_to; ?>" class="px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                
                <select name="status" class="px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                    <option value="">All Status</option>
                    <option value="pending_approval" <?php echo $status_filter == 'pending_approval' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="shipped" <?php echo $status_filter == 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                    <option value="delivered" <?php echo $status_filter == 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                    <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
                
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition">
                    <i class="fas fa-filter mr-1"></i> Filter
                </button>
            </form>

            <!-- CSV Export Button -->
            <a href="credit_history_export.php?date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&status=<?php echo $status_filter; ?>" 
               class="px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700 transition flex items-center justify-center">
                <i class="fas fa-file-csv mr-2"></i> Export CSV
            </a>
        </div>
    </div>

    <div class="overflow-x-auto">
        <?php if (!empty($orders)): ?>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Order #</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Customer</th>
                        <?php if ($is_admin || $is_accounts): ?>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Created By</th>
                        <?php endif; ?>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Date</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-600 uppercase">Total (BDT)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-600 uppercase">Due (BDT)</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-600 uppercase">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-600 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($orders as $order): ?>
                    <tr class="hover:bg-primary-50 transition-colors">
                        <td class="px-6 py-4 text-sm font-medium text-primary-700">
                            <a href="credit_order_view.php?id=<?php echo $order->id; ?>" class="hover:underline">
                                <?php echo htmlspecialchars($order->order_number); ?>
                            </a>
                        </td>
                        <td class="px-6 py-4 text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($order->customer_name); ?>
                        </td>
                        <?php if ($is_admin || $is_accounts): ?>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            <?php echo htmlspecialchars($order->created_by_name ?? 'N/A'); ?>
                        </td>
                        <?php endif; ?>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            <?php echo date('d-M-Y', strtotime($order->order_date)); ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-right font-semibold text-gray-800">
                            <?php echo number_format($order->total_amount, 2); ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-right font-medium <?php echo $order->due_amount > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                            <?php echo number_format($order->due_amount, 2); ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <?php
                            $status_colors = [
                                'draft'            => ['bg-gray-100', 'text-gray-800'],
                                'pending_approval' => ['bg-yellow-100', 'text-yellow-800'],
                                'approved'         => ['bg-blue-100', 'text-blue-800'],
                                'escalated'        => ['bg-red-100', 'text-red-800'],
                                'rejected'         => ['bg-gray-200', 'text-gray-600'],
                                'in_production'    => ['bg-purple-100', 'text-purple-800'],
                                'produced'         => ['bg-indigo-100', 'text-indigo-800'],
                                'ready_to_ship'    => ['bg-orange-100', 'text-orange-800'],
                                'shipped'          => ['bg-teal-100', 'text-teal-800'],
                                'delivered'        => ['bg-green-100', 'text-green-800'],
                                'cancelled'        => ['bg-gray-200', 'text-gray-600'],
                            ];
                            $colors = $status_colors[$order->status] ?? ['bg-gray-100', 'text-gray-800'];
                            ?>
                            <span class="px-3 py-1 text-xs font-bold rounded-full <?php echo $colors[0] . ' ' . $colors[1]; ?>">
                                <?php echo ucwords(str_replace('_', ' ', $order->status)); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-center text-sm font-medium">
                            <a href="credit_order_view.php?id=<?php echo $order->id; ?>" class="text-primary-600 hover:text-primary-900 px-2" title="View Details">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if ($order->status !== 'cancelled' && ($is_admin || $is_accounts)): ?>
                            <a href="cr_invoice.php?order_id=<?php echo $order->id; ?>" class="text-green-600 hover:text-green-900 px-2" title="Invoice">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="p-12 text-center text-gray-500">
                <i class="fas fa-inbox text-5xl mb-4 text-gray-300"></i>
                <p class="text-lg font-medium">No orders found matching your criteria.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ======================
        ROLE GUIDE
======================= -->
<div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6 shadow-md">
    <div class="flex items-center mb-3">
        <i class="fas fa-info-circle text-blue-500 text-xl mr-3"></i>
        <h3 class="text-lg font-bold text-blue-800">
            Your Role:
            <span class="font-mono bg-blue-100 px-2 py-1 rounded">
                <?php echo htmlspecialchars($user_role); ?>
            </span>
        </h3>
    </div>

    <div class="text-sm text-blue-700 space-y-2 pl-8">
        <p class="font-semibold">Key responsibilities in this module:</p>
        <ul class="list-disc list-inside space-y-1">
            <?php if ($is_sales): ?>
                <li>Create new credit orders for customers.</li>
                <li>Track the status of orders you created.</li>
                <li>View customer credit limits and balances before ordering.</li>
            <?php elseif ($is_accounts || $is_admin): ?>
                <li>Review and approve orders within customer credit limits.</li>
                <?php if ($is_admin): ?>
                <li>Handle escalated or high-value orders for final approval.</li>
                <?php endif; ?>
                <li>Manage customer credit limits (increase/decrease).</li>
                <li>Record payments received from customers.</li>
                <li>View ledgers and Accounts Receivable summaries.</li>
            <?php elseif ($is_production): ?>
                <li>View approved orders assigned to your branch for production.</li>
                <li>Update orders to 'In Production' or 'Produced' as appropriate.</li>
                <li>Mark completed orders as 'Ready to Ship'.</li>
            <?php elseif ($is_dispatcher): ?>
                <li>View 'Ready to Ship' orders for your branch.</li>
                <li>Assign trucks and drivers for dispatch.</li>
                <li>Print delivery challans or gate passes.</li>
                <li>Mark orders as 'Shipped' or 'Delivered'.</li>
            <?php else: ?>
                <li>Your role has limited permissions within the Credit Sales module.</li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>
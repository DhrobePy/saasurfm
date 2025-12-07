<?php
/**
 * Order History Page for Sales Officers
 * Shows historical credit orders with filtering and export capabilities
 * 
 * Features:
 * - View own submissions (default for sales officers)
 * - View all submissions (for Superadmin/admin)
 * - Date range filtering
 * - Search by order number, customer name
 * - CSV export functionality
 * - Pagination for large datasets
 * - Order details with items breakdown
 * - Status tracking
 * 
 * @version 1.0.0
 * @date 2025-12-02
 */

require_once '../core/init.php';

// Allow multiple roles to access
$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg', 
                  'production manager-srg', 'production manager-demra',
                  'sales-srg', 'sales-demra', 'sales-other', 'dispatch-srg', 'dispatch-demra', 'collector'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Order History';

// Check if user has admin privileges (can see all orders)
$is_admin = in_array($user_role, ['Superadmin', 'admin']);
$is_accounts = in_array($user_role, ['Accounts', 'accounts-demra', 'accounts-srg']);

// Get filter parameters
$view_mode = $_GET['view_mode'] ?? 'all';
 // 'own' or 'all'
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Non-admin users can only see their own orders
//if (!$is_admin && $view_mode === 'all') {
    //$view_mode = 'own';
//}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Build query for export
    $where_conditions = ["co.order_date BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];
    
    // View mode filter
    if ($view_mode === 'own') {
    $where_conditions[] = "co.created_by_user_id = ?";
    $params[] = $user_id;
}
    
    // Search filter
    if (!empty($search)) {
        $where_conditions[] = "(co.order_number LIKE ? OR c.name LIKE ? OR c.phone_number LIKE ?)";
        $search_term = "%{$search}%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    // Status filter
    if (!empty($status_filter)) {
        $where_conditions[] = "co.status = ?";
        $params[] = $status_filter;
    }
    
    $where_sql = implode(' AND ', $where_conditions);
    
    $export_query = "
        SELECT 
            co.order_number,
            co.order_date,
            co.required_date,
            c.name as customer_name,
            c.phone_number,
            c.business_name,
            co.order_type,
            co.subtotal,
            co.discount_amount,
            co.total_amount,
            co.advance_paid,
            co.amount_paid,
            co.balance_due,
            co.status,
            co.total_weight_kg,
            b.name as assigned_branch,
            u.display_name as created_by,
            co.shipping_address,
            co.special_instructions,
            co.created_at
        FROM credit_orders co
        LEFT JOIN customers c ON co.customer_id = c.id
        LEFT JOIN branches b ON co.assigned_branch_id = b.id
        LEFT JOIN users u ON co.created_by_user_id = u.id
        WHERE {$where_sql}
        ORDER BY co.order_date DESC, co.created_at DESC
    ";
    
    $export_results = $db->query($export_query, $params)->results();
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=order_history_' . date('Y-m-d_His') . '.csv');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // CSV Headers
    fputcsv($output, [
        'Order Number',
        'Order Date',
        'Required Date',
        'Customer Name',
        'Phone Number',
        'Business Name',
        'Order Type',
        'Subtotal',
        'Discount',
        'Total Amount',
        'Advance Paid',
        'Amount Paid',
        'Balance Due',
        'Status',
        'Weight (kg)',
        'Branch',
        'Created By',
        'Shipping Address',
        'Special Instructions',
        'Created At'
    ]);
    
    // CSV Data
    foreach ($export_results as $row) {
        fputcsv($output, [
            $row->order_number,
            $row->order_date,
            $row->required_date,
            $row->customer_name,
            $row->phone_number,
            $row->business_name ?? '',
            ucwords(str_replace('_', ' ', $row->order_type)),
            number_format($row->subtotal, 2),
            number_format($row->discount_amount, 2),
            number_format($row->total_amount, 2),
            number_format($row->advance_paid, 2),
            number_format($row->amount_paid, 2),
            number_format($row->balance_due, 2),
            ucwords(str_replace('_', ' ', $row->status)),
            number_format($row->total_weight_kg, 2),
            $row->assigned_branch ?? '',
            $row->created_by,
            $row->shipping_address ?? '',
            $row->special_instructions ?? '',
            $row->created_at
        ]);
    }
    
    fclose($output);
    exit;
}

// Build query for viewing orders
$where_conditions = ["co.order_date BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

// View mode filter
if ($view_mode === 'own') {
    $where_conditions[] = "co.created_by_user_id = ?";
    $params[] = $user_id;
}

// Search filter
if (!empty($search)) {
    $where_conditions[] = "(co.order_number LIKE ? OR c.name LIKE ? OR c.phone_number LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

// Status filter
if (!empty($status_filter)) {
    $where_conditions[] = "co.status = ?";
    $params[] = $status_filter;
}

$where_sql = implode(' AND ', $where_conditions);

// Get total count for pagination
$count_query = "
    SELECT COUNT(*) as total
    FROM credit_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    WHERE {$where_sql}
";
$total_count = $db->query($count_query, $params)->first()->total ?? 0;
$total_pages = ceil($total_count / $per_page);

// Get orders with pagination

// CHANGE TO:
$orders_query = "
    SELECT 
        co.*,
        c.name as customer_name,
        c.phone_number,
        c.business_name,
        c.customer_type,
        b.name as branch_name,
        b.code as branch_code,
        u.display_name as created_by_name,
        approver.display_name as approved_by_name
    FROM credit_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN branches b ON co.assigned_branch_id = b.id
    LEFT JOIN users u ON co.created_by_user_id = u.id
    LEFT JOIN users approver ON co.approved_by_user_id = approver.id
    WHERE {$where_sql}
    ORDER BY co.order_date DESC, co.created_at DESC
    LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;

// DON'T add params for LIMIT/OFFSET
$orders = $db->query($orders_query, $params)->results();


$orders = $db->query($orders_query, $params)->results();

// Get summary statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_orders,
        SUM(co.total_amount) as total_value,
        SUM(co.amount_paid) as total_paid,
        SUM(co.balance_due) as total_outstanding,
        SUM(CASE WHEN co.status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
        SUM(CASE WHEN co.status IN ('draft', 'pending_approval') THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN co.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count
    FROM credit_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    WHERE {$where_sql}
";

$stats = $db->query($stats_query, $params)->first();


// Status badge colors
function getStatusBadge($status) {
    $badges = [
        'draft' => 'bg-gray-100 text-gray-800',
        'pending_approval' => 'bg-yellow-100 text-yellow-800',
        'approved' => 'bg-blue-100 text-blue-800',
        'escalated' => 'bg-orange-100 text-orange-800',
        'rejected' => 'bg-red-100 text-red-800',
        'in_production' => 'bg-purple-100 text-purple-800',
        'produced' => 'bg-indigo-100 text-indigo-800',
        'ready_to_ship' => 'bg-cyan-100 text-cyan-800',
        'shipped' => 'bg-teal-100 text-teal-800',
        'delivered' => 'bg-green-100 text-green-800',
        'cancelled' => 'bg-red-100 text-red-800'
    ];
    return $badges[$status] ?? 'bg-gray-100 text-gray-800';
}

require_once '../templates/header.php';
?>

<!-- Page Header -->
<div class="bg-white shadow-sm border-b border-gray-200 mb-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">
                    <i class="fas fa-history text-primary-600 mr-2"></i>
                    Order History
                </h1>
                <p class="mt-1 text-sm text-gray-500">
                    View and export historical credit orders
                    <?php if ($view_mode === 'own'): ?>
                        <span class="font-medium text-primary-600">- Your Submissions</span>
                    <?php else: ?>
                        <span class="font-medium text-primary-600">- All Submissions</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="window.print()" class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg transition">
                    <i class="fas fa-print mr-2"></i>
                    Print
                </button>
                <button onclick="exportCSV()" class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition">
                    <i class="fas fa-file-csv mr-2"></i>
                    Export CSV
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Statistics Dashboard -->
<div class="mb-6">
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Total Orders</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo number_format($stats->total_orders ?? 0); ?></p>
                </div>
                <div class="h-12 w-12 bg-blue-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-shopping-cart text-blue-600 text-xl"></i>
                </div>
            </div>
            <div class="mt-3 text-xs text-gray-500">
                <span class="text-green-600 font-medium"><?php echo number_format($stats->delivered_count ?? 0); ?></span> delivered
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Total Value</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">৳<?php echo number_format($stats->total_value ?? 0, 0); ?></p>
                </div>
                <div class="h-12 w-12 bg-green-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-dollar-sign text-green-600 text-xl"></i>
                </div>
            </div>
            <div class="mt-3 text-xs text-gray-500">
                Total order value
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Amount Paid</p>
                    <p class="text-2xl font-bold text-green-600 mt-1">৳<?php echo number_format($stats->total_paid ?? 0, 0); ?></p>
                </div>
                <div class="h-12 w-12 bg-green-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-check-circle text-green-600 text-xl"></i>
                </div>
            </div>
            <div class="mt-3 text-xs text-gray-500">
                Collected payments
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Outstanding</p>
                    <p class="text-2xl font-bold text-orange-600 mt-1">৳<?php echo number_format($stats->total_outstanding ?? 0, 0); ?></p>
                </div>
                <div class="h-12 w-12 bg-orange-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-exclamation-triangle text-orange-600 text-xl"></i>
                </div>
            </div>
            <div class="mt-3 text-xs text-gray-500">
                Balance due
            </div>
        </div>
    </div>
</div>

<!-- Filters Section -->
<div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 p-6">
    <form method="GET" action="" id="filterForm" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            
            <!-- View Mode -->
            <?php if ($is_admin): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">View Mode</label>
                <select name="view_mode" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    <option value="own" <?php echo $view_mode === 'own' ? 'selected' : ''; ?>>My Submissions</option>
                    <option value="all" <?php echo $view_mode === 'all' ? 'selected' : ''; ?>>All Submissions</option>
                </select>
            </div>
            <?php else: ?>
            <input type="hidden" name="view_mode" value="own">
            <?php endif; ?>
            
            <!-- Date From -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
            </div>
            
            <!-- Date To -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
            </div>
            
            <!-- Status Filter -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    <option value="">All Statuses</option>
                    <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="pending_approval" <?php echo $status_filter === 'pending_approval' ? 'selected' : ''; ?>>Pending Approval</option>
                    <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="in_production" <?php echo $status_filter === 'in_production' ? 'selected' : ''; ?>>In Production</option>
                    <option value="produced" <?php echo $status_filter === 'produced' ? 'selected' : ''; ?>>Produced</option>
                    <option value="ready_to_ship" <?php echo $status_filter === 'ready_to_ship' ? 'selected' : ''; ?>>Ready to Ship</option>
                    <option value="shipped" <?php echo $status_filter === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                    <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            
            <!-- Search -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Order #, Customer..." 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
            </div>
        </div>
        
        <div class="flex items-center gap-3">
            <button type="submit" class="inline-flex items-center px-6 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg transition">
                <i class="fas fa-filter mr-2"></i>
                Apply Filters
            </button>
            <button type="button" onclick="resetFilters()" class="inline-flex items-center px-6 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg transition">
                <i class="fas fa-redo mr-2"></i>
                Reset
            </button>
            <div class="ml-auto text-sm text-gray-600">
                Showing <span class="font-semibold"><?php echo min($offset + 1, $total_count); ?>-<?php echo min($offset + $per_page, $total_count); ?></span> 
                of <span class="font-semibold"><?php echo number_format($total_count); ?></span> orders
            </div>
        </div>
        
        <!-- Quick Date Filters -->
        <div class="flex flex-wrap gap-2 pt-2 border-t border-gray-200">
            <button type="button" onclick="setDateRange('today')" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded transition">
                Today
            </button>
            <button type="button" onclick="setDateRange('yesterday')" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded transition">
                Yesterday
            </button>
            <button type="button" onclick="setDateRange('this_week')" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded transition">
                This Week
            </button>
            <button type="button" onclick="setDateRange('this_month')" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded transition">
                This Month
            </button>
            <button type="button" onclick="setDateRange('last_month')" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded transition">
                Last Month
            </button>
            <button type="button" onclick="setDateRange('last_3_months')" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded transition">
                Last 3 Months
            </button>
        </div>
    </form>
</div>

<!-- Orders Table -->
<div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
    <?php if (empty($orders)): ?>
        <div class="text-center py-12">
            <i class="fas fa-inbox text-gray-400 text-5xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No Orders Found</h3>
            <p class="text-gray-500">Try adjusting your filters or date range</p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order Details</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dates</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Branch</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created By</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($orders as $order): ?>
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-6 py-4">
                                <div class="text-sm">
                                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($order->order_number); ?></div>
                                    <div class="text-gray-500">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                            <?php echo ucwords(str_replace('_', ' ', $order->order_type)); ?>
                                        </span>
                                        <?php if ($order->total_weight_kg > 0): ?>
                                            <span class="ml-2 text-xs text-gray-500">
                                                <i class="fas fa-weight text-gray-400"></i> <?php echo number_format($order->total_weight_kg, 0); ?> kg
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm">
                                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($order->customer_name); ?></div>
                                    <div class="text-gray-500">
                                        <i class="fas fa-phone text-gray-400 text-xs"></i> 
                                        <?php echo htmlspecialchars($order->phone_number); ?>
                                    </div>
                                    <?php if (!empty($order->business_name)): ?>
                                        <div class="text-xs text-gray-400 italic"><?php echo htmlspecialchars($order->business_name); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-900">
                                    <div>
                                        <span class="text-xs text-gray-500">Order:</span> 
                                        <span class="font-medium"><?php echo date('d M Y', strtotime($order->order_date)); ?></span>
                                    </div>
                                    <?php if (!empty($order->required_date)): ?>
                                        <div class="mt-1">
                                            <span class="text-xs text-gray-500">Required:</span> 
                                            <span class="font-medium"><?php echo date('d M Y', strtotime($order->required_date)); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="text-sm">
                                    <div class="font-bold text-gray-900">৳<?php echo number_format($order->total_amount, 2); ?></div>
                                    <?php if ($order->amount_paid > 0): ?>
                                        <div class="text-xs text-green-600 mt-1">
                                            Paid: ৳<?php echo number_format($order->amount_paid, 2); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($order->balance_due > 0): ?>
                                        <div class="text-xs text-orange-600">
                                            Due: ৳<?php echo number_format($order->balance_due, 2); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($order->discount_amount > 0): ?>
                                        <div class="text-xs text-gray-500">
                                            Discount: ৳<?php echo number_format($order->discount_amount, 2); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium <?php echo getStatusBadge($order->status); ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $order->status)); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-900">
                                    <?php if (!empty($order->branch_name)): ?>
                                        <div class="font-medium"><?php echo htmlspecialchars($order->branch_name); ?></div>
                                        <?php if (!empty($order->branch_code)): ?>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($order->branch_code); ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-gray-400">Not assigned</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-900">
                                    <?php echo htmlspecialchars($order->created_by_name); ?>
                                    <?php if ($order->created_by_user_id == $user_id): ?>
                                        <span class="text-xs text-primary-600 font-medium ml-1">(You)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-gray-500">
                                    <?php echo date('d M Y h:i A', strtotime($order->created_at)); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <button onclick="viewOrderDetails(<?php echo $order->id; ?>)" 
                                        class="inline-flex items-center px-3 py-1 bg-primary-50 hover:bg-primary-100 text-primary-700 text-xs font-medium rounded transition"
                                        title="View Details">
                                    <i class="fas fa-eye mr-1"></i>
                                    View
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="bg-gray-50 px-6 py-4 border-t border-gray-200">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-gray-700">
                        Page <span class="font-medium"><?php echo $page; ?></span> of <span class="font-medium"><?php echo $total_pages; ?></span>
                    </div>
                    <div class="flex gap-2">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                               class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition text-sm">
                                <i class="fas fa-chevron-left mr-1"></i> Previous
                            </a>
                        <?php endif; ?>
                        
                        <?php 
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        for ($i = $start_page; $i <= $end_page; $i++): 
                        ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="px-4 py-2 border rounded-lg text-sm transition <?php echo $i === $page ? 'bg-primary-600 text-white border-primary-600' : 'bg-white border-gray-300 hover:bg-gray-50'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                               class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition text-sm">
                                Next <i class="fas fa-chevron-right ml-1"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Order Details Modal -->
<div id="orderDetailsModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-hidden">
        <div class="flex items-center justify-between p-6 border-b border-gray-200">
            <h3 class="text-xl font-bold text-gray-900">
                <i class="fas fa-file-invoice text-primary-600 mr-2"></i>
                Order Details
            </h3>
            <button onclick="closeOrderDetails()" class="text-gray-400 hover:text-gray-600 transition">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div id="orderDetailsContent" class="p-6 overflow-y-auto" style="max-height: calc(90vh - 140px);">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-4xl text-primary-600"></i>
                <p class="mt-4 text-gray-600">Loading order details...</p>
            </div>
        </div>
    </div>
</div>

<script>
// Quick date range filters
function setDateRange(range) {
    const today = new Date();
    let fromDate, toDate;
    
    const formatDate = (date) => {
        return date.toISOString().split('T')[0];
    };
    
    switch(range) {
        case 'today':
            fromDate = toDate = formatDate(today);
            break;
        case 'yesterday':
            const yesterday = new Date(today);
            yesterday.setDate(today.getDate() - 1);
            fromDate = toDate = formatDate(yesterday);
            break;
        case 'this_week':
            const weekStart = new Date(today);
            weekStart.setDate(today.getDate() - today.getDay() + 1);
            fromDate = formatDate(weekStart);
            toDate = formatDate(today);
            break;
        case 'this_month':
            fromDate = formatDate(new Date(today.getFullYear(), today.getMonth(), 1));
            toDate = formatDate(today);
            break;
        case 'last_month':
            const lastMonthStart = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            const lastMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0);
            fromDate = formatDate(lastMonthStart);
            toDate = formatDate(lastMonthEnd);
            break;
        case 'last_3_months':
            const threeMonthsAgo = new Date(today);
            threeMonthsAgo.setMonth(today.getMonth() - 3);
            fromDate = formatDate(threeMonthsAgo);
            toDate = formatDate(today);
            break;
    }
    
    document.querySelector('[name="date_from"]').value = fromDate;
    document.querySelector('[name="date_to"]').value = toDate;
    document.getElementById('filterForm').submit();
}

// Reset filters
function resetFilters() {
    window.location.href = window.location.pathname;
}

// Export CSV
function exportCSV() {
    const form = document.getElementById('filterForm');
    const url = new URL(window.location.href);
    
    const formData = new FormData(form);
    formData.forEach((value, key) => {
        if (value) url.searchParams.set(key, value);
    });
    
    url.searchParams.set('export', 'csv');
    
    window.open(url.toString(), '_blank');
}

// View order details
function viewOrderDetails(orderId) {
    const modal = document.getElementById('orderDetailsModal');
    const content = document.getElementById('orderDetailsContent');
    
    modal.classList.remove('hidden');
    
    // Show loading state
    content.innerHTML = `
        <div class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-4xl text-primary-600"></i>
            <p class="mt-4 text-gray-600">Loading order details...</p>
        </div>
    `;
    
    // Fetch order details
    fetch(`../cr/get_order_details.php?id=${orderId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayOrderDetails(data.order, data.items);
            } else {
                content.innerHTML = `
                    <div class="text-center py-8">
                        <i class="fas fa-exclamation-circle text-4xl text-red-500"></i>
                        <p class="mt-4 text-gray-600">${data.message || 'Failed to load order details'}</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            content.innerHTML = `
                <div class="text-center py-8">
                    <i class="fas fa-exclamation-triangle text-4xl text-orange-500"></i>
                    <p class="mt-4 text-gray-600">An error occurred while loading order details</p>
                </div>
            `;
        });
}

// Display order details
function displayOrderDetails(order, items) {
    const content = document.getElementById('orderDetailsContent');
    
    const statusBadges = {
        'draft': 'bg-gray-100 text-gray-800',
        'pending_approval': 'bg-yellow-100 text-yellow-800',
        'approved': 'bg-blue-100 text-blue-800',
        'in_production': 'bg-purple-100 text-purple-800',
        'produced': 'bg-indigo-100 text-indigo-800',
        'ready_to_ship': 'bg-cyan-100 text-cyan-800',
        'shipped': 'bg-teal-100 text-teal-800',
        'delivered': 'bg-green-100 text-green-800',
        'cancelled': 'bg-red-100 text-red-800'
    };
    
    let itemsHtml = '';
    if (items && items.length > 0) {
        itemsHtml = items.map(item => `
            <tr class="border-b border-gray-200">
                <td class="py-3 px-4">
                    <div class="font-medium text-gray-900">${item.product_name || 'N/A'}</div>
                    <div class="text-sm text-gray-500">${item.variant_name || 'N/A'}</div>
                </td>
                <td class="py-3 px-4 text-center">${item.quantity || 0}</td>
                <td class="py-3 px-4 text-right">৳${parseFloat(item.unit_price || 0).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                <td class="py-3 px-4 text-right font-medium">৳${parseFloat(item.total_price || 0).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
            </tr>
        `).join('');
    } else {
        itemsHtml = '<tr><td colspan="4" class="py-4 text-center text-gray-500">No items found</td></tr>';
    }
    
    content.innerHTML = `
        <div class="space-y-6">
            <!-- Order Header -->
            <div class="flex items-start justify-between">
                <div>
                    <h4 class="text-2xl font-bold text-gray-900">${order.order_number || 'N/A'}</h4>
                    <p class="text-sm text-gray-500 mt-1">Order Date: ${order.order_date ? new Date(order.order_date).toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'}) : 'N/A'}</p>
                </div>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${statusBadges[order.status] || 'bg-gray-100 text-gray-800'}">
                    ${order.status ? order.status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) : 'N/A'}
                </span>
            </div>
            
            <!-- Customer & Order Info -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-gray-50 rounded-lg p-4">
                    <h5 class="font-semibold text-gray-900 mb-3 flex items-center">
                        <i class="fas fa-user text-primary-600 mr-2"></i>
                        Customer Information
                    </h5>
                    <div class="space-y-2 text-sm">
                        <div><span class="text-gray-600">Name:</span> <span class="font-medium">${order.customer_name || 'N/A'}</span></div>
                        <div><span class="text-gray-600">Phone:</span> <span class="font-medium">${order.phone_number || 'N/A'}</span></div>
                        ${order.business_name ? `<div><span class="text-gray-600">Business:</span> <span class="font-medium">${order.business_name}</span></div>` : ''}
                        ${order.shipping_address ? `<div><span class="text-gray-600">Address:</span> <span class="font-medium">${order.shipping_address}</span></div>` : ''}
                    </div>
                </div>
                
                <div class="bg-gray-50 rounded-lg p-4">
                    <h5 class="font-semibold text-gray-900 mb-3 flex items-center">
                        <i class="fas fa-info-circle text-primary-600 mr-2"></i>
                        Order Information
                    </h5>
                    <div class="space-y-2 text-sm">
                        <div><span class="text-gray-600">Type:</span> <span class="font-medium">${order.order_type ? order.order_type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) : 'N/A'}</span></div>
                        ${order.required_date ? `<div><span class="text-gray-600">Required:</span> <span class="font-medium">${new Date(order.required_date).toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'})}</span></div>` : ''}
                        ${order.branch_name ? `<div><span class="text-gray-600">Branch:</span> <span class="font-medium">${order.branch_name}</span></div>` : ''}
                        ${order.total_weight_kg > 0 ? `<div><span class="text-gray-600">Weight:</span> <span class="font-medium">${parseFloat(order.total_weight_kg).toLocaleString('en-BD')} kg</span></div>` : ''}
                    </div>
                </div>
            </div>
            
            <!-- Order Items -->
            <div>
                <h5 class="font-semibold text-gray-900 mb-3 flex items-center">
                    <i class="fas fa-box text-primary-600 mr-2"></i>
                    Order Items
                </h5>
                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                                <th class="py-3 px-4 text-center text-xs font-medium text-gray-500 uppercase">Quantity</th>
                                <th class="py-3 px-4 text-right text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                                <th class="py-3 px-4 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itemsHtml}
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Financial Summary -->
            <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-lg p-6 border border-gray-200">
                <h5 class="font-semibold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-calculator text-primary-600 mr-2"></i>
                    Financial Summary
                </h5>
                <div class="space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Subtotal:</span>
                        <span class="font-medium">৳${parseFloat(order.subtotal || 0).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                    </div>
                    ${order.discount_amount > 0 ? `
                    <div class="flex justify-between text-sm text-red-600">
                        <span>Discount:</span>
                        <span class="font-medium">- ৳${parseFloat(order.discount_amount).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                    </div>
                    ` : ''}
                    <div class="flex justify-between text-lg font-bold text-gray-900 pt-2 border-t border-gray-300">
                        <span>Total Amount:</span>
                        <span>৳${parseFloat(order.total_amount || 0).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                    </div>
                    ${order.advance_paid > 0 ? `
                    <div class="flex justify-between text-sm text-blue-600">
                        <span>Advance Paid:</span>
                        <span class="font-medium">৳${parseFloat(order.advance_paid).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                    </div>
                    ` : ''}
                    ${order.amount_paid > 0 ? `
                    <div class="flex justify-between text-sm text-green-600">
                        <span>Amount Paid:</span>
                        <span class="font-medium">৳${parseFloat(order.amount_paid).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                    </div>
                    ` : ''}
                    ${order.balance_due > 0 ? `
                    <div class="flex justify-between text-sm text-orange-600">
                        <span>Balance Due:</span>
                        <span class="font-medium">৳${parseFloat(order.balance_due).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                    </div>
                    ` : ''}
                </div>
            </div>
            
            ${order.special_instructions ? `
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <h5 class="font-semibold text-gray-900 mb-2 flex items-center">
                    <i class="fas fa-sticky-note text-yellow-600 mr-2"></i>
                    Special Instructions
                </h5>
                <p class="text-sm text-gray-700">${order.special_instructions}</p>
            </div>
            ` : ''}
        </div>
    `;
}

// Close order details modal
function closeOrderDetails() {
    document.getElementById('orderDetailsModal').classList.add('hidden');
}

// Close modal on outside click
document.getElementById('orderDetailsModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeOrderDetails();
    }
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Escape to close modal
    if (e.key === 'Escape') {
        closeOrderDetails();
    }
    
    // Ctrl/Cmd + E for CSV export
    if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
        e.preventDefault();
        exportCSV();
    }
    
    // Ctrl/Cmd + F for search
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        document.querySelector('[name="search"]').focus();
    }
});

// Print styles
const style = document.createElement('style');
style.textContent = `
    @media print {
        nav, button, .no-print {
            display: none !important;
        }
        body {
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
    }
`;
document.head.appendChild(style);
</script>

<?php require_once '../templates/footer.php'; ?>
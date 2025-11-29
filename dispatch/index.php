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
   ROLE-BASED STATISTICS
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
   FILTERING & LIST LOGIC
----------------------------- */

// 1. Get Filter Inputs (Default to TODAY)
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d');
$to_date   = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$status    = isset($_GET['status']) ? $_GET['status'] : 'default';

// 2. Build Query
$sql = "SELECT co.*, c.name AS customer_name, c.phone_number, u.display_name AS created_by_name
        FROM credit_orders co
        JOIN customers c ON co.customer_id = c.id
        LEFT JOIN users u ON co.created_by_user_id = u.id
        WHERE 1=1";

$params = [];

// 3. Apply Role Restrictions
if ($is_sales && $user_id) {
    $sql .= " AND co.created_by_user_id = ?";
    $params[] = $user_id;
} elseif (($is_production || $is_dispatcher) && $user_branch) {
    $sql .= " AND co.assigned_branch_id = ?";
    $params[] = $user_branch;
}

// 4. Apply Date Filter (USING updated_at to show recent changes)
// Convert dates to datetime range to capture full days
$sql .= " AND co.updated_at >= ? AND co.updated_at < DATE_ADD(?, INTERVAL 1 DAY)";
$params[] = $from_date . ' 00:00:00';
$params[] = $to_date;

// 5. Apply Status Filter
if ($status === 'default') {
    // Smart Defaults based on Role
    if ($is_dispatcher) {
        $sql .= " AND co.status IN ('ready_to_ship', 'shipped', 'delivered', 'dispatched')";
    } elseif ($is_production) {
        $sql .= " AND co.status IN ('approved', 'in_production', 'produced')";
    } elseif ($is_accounts || $is_admin) {
        // Accounts/Admin see active stuff by default
        $sql .= " AND co.status NOT IN ('draft', 'cancelled')";
    } else {
        // Sales etc
        $sql .= " AND co.status NOT IN ('cancelled')";
    }
} elseif ($status !== 'all') {
    $sql .= " AND co.status = ?";
    $params[] = $status;
}

// 6. Ordering (Sort by updated_at so recent changes appear first)
$sql .= " ORDER BY co.updated_at DESC";

// 7. Execute
try {
    $orders = $db->query($sql, $params)->results();
} catch (Exception $e) {
    $orders = [];
    $error = "Error loading orders: " . $e->getMessage();
}

/* -----------------------------
   CSV EXPORT HANDLER
----------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="credit_orders_export_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV Headers with all possible details
    fputcsv($output, [
        'Order Number',
        'Order Date',
        'Status',
        'Created At',
        'Updated At',
        'Customer Name',
        'Customer Phone',
        'Customer Address',
        'Customer Credit Limit',
        'Customer Current Balance',
        'Shipping Address',
        'Required Date',
        'Special Instructions',
        'Item 1 - Product',
        'Item 1 - Variant',
        'Item 1 - Quantity',
        'Item 1 - Unit',
        'Item 1 - Unit Price',
        'Item 1 - Total',
        'Item 2 - Product',
        'Item 2 - Variant',
        'Item 2 - Quantity',
        'Item 2 - Unit',
        'Item 2 - Unit Price',
        'Item 2 - Total',
        'Item 3 - Product',
        'Item 3 - Variant',
        'Item 3 - Quantity',
        'Item 3 - Unit',
        'Item 3 - Unit Price',
        'Item 3 - Total',
        'Subtotal',
        'Discount Type',
        'Discount Amount',
        'Total Amount',
        'Advance Paid',
        'Balance Due',
        'Payment Status',
        'Assigned Branch',
        'Production Started At',
        'Production Completed At',
        'Truck Number',
        'Driver Name',
        'Driver Contact',
        'Shipped Date',
        'Delivered Date',
        'Delivery Notes',
        'Trip ID',
        'Created By User',
        'Total Weight (kg)',
        'Workflow History'
    ]);
    
    // Export each order with full details
    foreach ($orders as $order) {
        // Get customer full details
        $customer = $db->query(
            "SELECT name, phone_number, business_address, credit_limit, current_balance 
             FROM customers WHERE id = ?", 
            [$order->customer_id]
        )->first();
        
        // Get order items (up to 3 items shown inline, can be expanded)
        $items = $db->query(
            "SELECT coi.*, p.base_name as product_name, 
                    pv.grade, pv.weight_variant, pv.unit_of_measure, pv.sku
             FROM credit_order_items coi
             JOIN products p ON coi.product_id = p.id
             LEFT JOIN product_variants pv ON coi.variant_id = pv.id
             WHERE coi.order_id = ?
             ORDER BY coi.id",
            [$order->id]
        )->results();
        
        // Get branch name
        $branch = null;
        if ($order->assigned_branch_id) {
            $branch_result = $db->query("SELECT name FROM branches WHERE id = ?", [$order->assigned_branch_id])->first();
            $branch = $branch_result ? $branch_result->name : null;
        }
        
        // Get production schedule details
        $production = $db->query(
            "SELECT production_started_at, production_completed_at 
             FROM production_schedule WHERE order_id = ?",
            [$order->id]
        )->first();
        
        // Get shipping details
        $shipping = $db->query(
            "SELECT truck_number, driver_name, driver_contact, shipped_date, delivered_date, delivery_notes, trip_id
             FROM credit_order_shipping WHERE order_id = ?",
            [$order->id]
        )->first();
        
        // Get workflow history
        $workflow = $db->query(
            "SELECT CONCAT(from_status, ' → ', to_status, ' (', action, ') at ', 
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:%i'), ' by user_id:', performed_by_user_id, 
                    IF(comments != '', CONCAT(' - ', comments), '')) as event
             FROM credit_order_workflow 
             WHERE order_id = ? 
             ORDER BY created_at",
            [$order->id]
        )->results();
        
        $workflow_history = [];
        foreach ($workflow as $w) {
            $workflow_history[] = $w->event;
        }
        $workflow_text = implode(' | ', $workflow_history);
        
        // Prepare item data (up to 3 items)
        $item_data = [];
        for ($i = 0; $i < 3; $i++) {
            if (isset($items[$i])) {
                $item = $items[$i];
                $variant_name = trim(($item->grade ?? '') . ' ' . ($item->weight_variant ?? ''));
                $item_data[] = $item->product_name;
                $item_data[] = $variant_name;
                $item_data[] = $item->quantity;
                $item_data[] = $item->unit_of_measure ?? 'pcs';
                $item_data[] = number_format($item->unit_price, 2);
                $item_data[] = number_format($item->line_total, 2);
            } else {
                $item_data[] = '';
                $item_data[] = '';
                $item_data[] = '';
                $item_data[] = '';
                $item_data[] = '';
                $item_data[] = '';
            }
        }
        
        // Build CSV row
        $row = [
            $order->order_number,
            $order->order_date,
            ucwords(str_replace('_', ' ', $order->status)),
            $order->created_at,
            $order->updated_at,
            $customer ? $customer->name : '',
            $customer ? $customer->phone_number : '',
            $customer ? $customer->business_address : '',
            $customer ? number_format($customer->credit_limit, 2) : '0.00',
            $customer ? number_format($customer->current_balance, 2) : '0.00',
            $order->shipping_address ?? '',
            $order->required_date ?? '',
            $order->special_instructions ?? '',
        ];
        
        // Add item data
        $row = array_merge($row, $item_data);
        
        // Add financial data
        $row[] = number_format($order->subtotal, 2);
        $row[] = $order->discount_type ?? '';
        $row[] = number_format($order->discount_amount, 2);
        $row[] = number_format($order->total_amount, 2);
        $row[] = number_format($order->advance_paid, 2);
        $row[] = number_format($order->balance_due, 2);
        $row[] = $order->payment_status ?? '';
        
        // Add operational data
        $row[] = $branch ?? '';
        $row[] = $production ? $production->production_started_at : '';
        $row[] = $production ? $production->production_completed_at : '';
        $row[] = $shipping ? $shipping->truck_number : '';
        $row[] = $shipping ? $shipping->driver_name : '';
        $row[] = $shipping ? $shipping->driver_contact : '';
        $row[] = $shipping ? $shipping->shipped_date : '';
        $row[] = $shipping ? $shipping->delivered_date : '';
        $row[] = $shipping ? $shipping->delivery_notes : '';
        $row[] = $shipping ? $shipping->trip_id : '';
        $row[] = $order->created_by_name ?? '';
        $row[] = $order->total_weight_kg ?? '';
        $row[] = $workflow_text;
        
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

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
    <?php if (isset($error)): ?>
        <div class="mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
            <strong class="font-bold">System Error!</strong>
            <span class="block sm:inline"><?php echo $error; ?></span>
        </div>
    <?php endif; ?>
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
        FILTER SECTION
======================= -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 border-b border-gray-200 bg-gray-50">
        <h3 class="text-md font-bold text-gray-700"><i class="fas fa-filter mr-2"></i>Filter Orders (By Activity Date)</h3>
    </div>
    <div class="p-4">
        <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <!-- From Date -->
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-1">From Date</label>
                <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" class="w-full border-gray-300 rounded-md shadow-sm focus:border-primary-500 focus:ring focus:ring-primary-200 focus:ring-opacity-50 text-sm">
            </div>

            <!-- To Date -->
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-1">To Date</label>
                <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" class="w-full border-gray-300 rounded-md shadow-sm focus:border-primary-500 focus:ring focus:ring-primary-200 focus:ring-opacity-50 text-sm">
            </div>

            <!-- Status -->
            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Status</label>
                <select name="status" class="w-full border-gray-300 rounded-md shadow-sm focus:border-primary-500 focus:ring focus:ring-primary-200 focus:ring-opacity-50 text-sm">
                    <option value="default" <?php echo ($status === 'default') ? 'selected' : ''; ?>>Default (Relevant Today)</option>
                    <option value="all" <?php echo ($status === 'all') ? 'selected' : ''; ?>>All Statuses</option>
                    <option disabled>──────────</option>
                    <option value="approved" <?php echo ($status === 'approved') ? 'selected' : ''; ?>>Approved</option>
                    <option value="ready_to_ship" <?php echo ($status === 'ready_to_ship') ? 'selected' : ''; ?>>Ready to Ship</option>
                    <option value="dispatched" <?php echo ($status === 'dispatched') ? 'selected' : ''; ?>>Dispatched</option>
                    <option value="shipped" <?php echo ($status === 'shipped') ? 'selected' : ''; ?>>Shipped</option>
                    <option value="delivered" <?php echo ($status === 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                </select>
            </div>

            <!-- Filter Button -->
            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-primary-600 text-white px-4 py-2 rounded-md hover:bg-primary-700 transition-colors shadow-sm text-sm font-medium">
                    <i class="fas fa-filter mr-1"></i>Filter
                </button>
                <a href="index.php" class="bg-gray-200 text-gray-700 px-3 py-2 rounded-md hover:bg-gray-300 transition-colors shadow-sm text-sm" title="Reset">
                    <i class="fas fa-undo"></i>
                </a>
            </div>
        </form>
        
        <!-- Export CSV Button -->
        <div class="mt-3 pt-3 border-t border-gray-200">
            <a href="?export=csv&from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&status=<?php echo urlencode($status); ?>" 
               class="block w-full bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition-colors shadow-sm text-sm font-medium text-center">
                <i class="fas fa-file-csv mr-2"></i>Export to CSV
            </a>
            <p class="text-xs text-gray-500 mt-1 text-center">Exports all <?php echo count($orders); ?> filtered orders with complete details</p>
        </div>
    </div>
</div>

<!-- ======================
        ORDER LIST
======================= -->
<div class="bg-white rounded-lg shadow-lg overflow-hidden mb-8">
    <div class="p-5 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
        <h2 class="text-xl font-bold text-gray-800">
            Order List 
            <span class="ml-2 text-sm font-normal text-gray-500 bg-gray-200 px-2 py-1 rounded-full">
                <?php echo count($orders); ?> items
            </span>
        </h2>
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Updated</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-600 uppercase">Amount (BDT)</th>
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
                            <div class="text-xs text-gray-500 mt-1"><i class="fas fa-phone-alt mr-1"></i><?php echo htmlspecialchars($order->phone_number); ?></div>
                        </td>
                        <?php if ($is_admin || $is_accounts): ?>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            <?php echo htmlspecialchars($order->created_by_name ?? 'N/A'); ?>
                        </td>
                        <?php endif; ?>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            <!-- Displaying Updated At as requested -->
                            <?php echo date('d-M-Y h:i A', strtotime($order->updated_at)); ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-right font-semibold text-gray-800">
                            <?php echo number_format($order->total_amount, 2); ?>
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
                                'dispatched'       => ['bg-teal-100', 'text-teal-800'],
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
                            <div class="flex justify-center space-x-2">
                                <!-- View Button -->
                                <a href="credit_order_view.php?id=<?php echo $order->id; ?>" class="text-white bg-info-500 hover:bg-info-600 px-2 py-1 rounded shadow-sm transition-colors" title="View Details">
                                    <i class="fas fa-eye fa-fw"></i>
                                </a>
                                <!-- Print Button -->
                                <a href="credit_invoice_print.php?id=<?php echo $order->id; ?>" target="_blank" class="text-white bg-gray-700 hover:bg-gray-800 px-2 py-1 rounded shadow-sm transition-colors" title="Print Receipt">
                                    <i class="fas fa-print fa-fw"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="p-12 text-center text-gray-500">
                <i class="fas fa-inbox text-5xl mb-4 text-gray-300"></i>
                <p class="text-lg font-medium">No orders found matching your criteria.</p>
                <p class="text-sm">Try adjusting the filters above.</p>
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
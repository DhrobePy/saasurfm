<?php
/**
 * Purchase Payments - Complete List with Pagination
 * Shows all payment records with filtering, printing, editing, and deleting
 * 
 * @package Ujjal Flour Mills
 * @subpackage Purchase (Adnan) Module
 */

require_once '../core/init.php';
require_once '../core/classes/Purchasepaymentadnanmanager.php';

// Restrict access
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$currentUser = getCurrentUser();
$user_role = $currentUser['role'] ?? '';
$is_superadmin = ($user_role === 'Superadmin');

$pageTitle = "Purchase Payments";

// Initialize manager
$payment_manager = new PurchasePaymentAdnanManager();
$db = Database::getInstance()->getPdo();

// ===============================================
// PAGINATION SETUP
// ===============================================
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$items_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
$offset = ($page - 1) * $items_per_page;

// Get filter parameters
$filters = [
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'supplier_id' => $_GET['supplier_id'] ?? '',
    'payment_method' => $_GET['payment_method'] ?? '',
    'payment_type' => $_GET['payment_type'] ?? '',
    'is_posted' => $_GET['is_posted'] ?? '',
    'po_number' => $_GET['po_number'] ?? '',
    'search' => $_GET['search'] ?? ''
];

// Build WHERE clause and params
$where_conditions = ["1=1"];
$params = [];

// Apply filters
if ($filters['date_from']) {
    $where_conditions[] = "pmt.payment_date >= ?";
    $params[] = $filters['date_from'];
}
if ($filters['date_to']) {
    $where_conditions[] = "pmt.payment_date <= ?";
    $params[] = $filters['date_to'];
}
if ($filters['supplier_id']) {
    $where_conditions[] = "po.supplier_id = ?";
    $params[] = $filters['supplier_id'];
}
if ($filters['payment_method']) {
    $where_conditions[] = "pmt.payment_method = ?";
    $params[] = $filters['payment_method'];
}
if ($filters['payment_type']) {
    $where_conditions[] = "pmt.payment_type = ?";
    $params[] = $filters['payment_type'];
}
if ($filters['is_posted'] !== '') {
    $where_conditions[] = "pmt.is_posted = ?";
    $params[] = (int)$filters['is_posted'];
}
if ($filters['po_number']) {
    $where_conditions[] = "pmt.po_number LIKE ?";
    $params[] = '%' . $filters['po_number'] . '%';
}
if ($filters['search']) {
    $where_conditions[] = "(pmt.payment_voucher_number LIKE ? OR pmt.po_number LIKE ? OR pmt.reference_number LIKE ?)";
    $search_term = '%' . $filters['search'] . '%';
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$where_clause = implode(' AND ', $where_conditions);

// ===============================================
// COUNT TOTAL RECORDS (for pagination)
// ===============================================
$count_sql = "SELECT COUNT(*) as total
              FROM purchase_payments_adnan pmt
              LEFT JOIN purchase_orders_adnan po ON pmt.purchase_order_id = po.id
              WHERE $where_clause";

$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch(PDO::FETCH_OBJ)->total;
$total_pages = ceil($total_records / $items_per_page);

// ===============================================
// GET PAGINATED RECORDS
// ===============================================
$sql = "SELECT 
            pmt.*,
            po.po_number,
            po.supplier_name,
            po.wheat_origin,
            u.display_name as created_by_name
        FROM purchase_payments_adnan pmt
        LEFT JOIN purchase_orders_adnan po ON pmt.purchase_order_id = po.id
        LEFT JOIN users u ON pmt.created_by_user_id = u.id
        WHERE $where_clause
        ORDER BY pmt.payment_date DESC, pmt.created_at DESC
        LIMIT " . (int)$items_per_page . " OFFSET " . (int)$offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_OBJ);

// ===============================================
// CALCULATE STATISTICS FOR ALL FILTERED RECORDS
// ===============================================
$stats_sql = "SELECT 
                COUNT(*) as total_payments,
                COALESCE(SUM(pmt.amount_paid), 0) as total_amount,
                SUM(CASE WHEN pmt.is_posted = 1 THEN 1 ELSE 0 END) as posted_count,
                SUM(CASE WHEN pmt.is_posted = 0 THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN pmt.payment_method = 'bank' THEN 1 ELSE 0 END) as bank_count,
                SUM(CASE WHEN pmt.payment_method = 'cash' THEN 1 ELSE 0 END) as cash_count,
                SUM(CASE WHEN pmt.payment_method = 'cheque' THEN 1 ELSE 0 END) as cheque_count,
                SUM(CASE WHEN pmt.payment_type = 'advance' THEN 1 ELSE 0 END) as advance_count,
                SUM(CASE WHEN pmt.payment_type = 'regular' THEN 1 ELSE 0 END) as regular_count
              FROM purchase_payments_adnan pmt
              LEFT JOIN purchase_orders_adnan po ON pmt.purchase_order_id = po.id
              WHERE $where_clause";

$stats_stmt = $db->prepare($stats_sql);
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch(PDO::FETCH_OBJ);

// Get unique suppliers for filter
$suppliers_sql = "SELECT DISTINCT s.id, s.company_name 
                  FROM suppliers s 
                  INNER JOIN purchase_orders_adnan po ON po.supplier_id = s.id
                  INNER JOIN purchase_payments_adnan pmt ON pmt.purchase_order_id = po.id
                  ORDER BY s.company_name";
$suppliers = $db->query($suppliers_sql)->fetchAll(PDO::FETCH_OBJ);

// Check if any filter is active
$active_filters = array_filter($filters, function($value) {
    return $value !== '' && $value !== null;
});

// Helper function to build pagination URL
function getPaginationUrl($page_num, $filters, $per_page) {
    $params = array_merge($filters, ['page' => $page_num, 'per_page' => $per_page]);
    $params = array_filter($params, function($value) {
        return $value !== '' && $value !== null;
    });
    return 'payments.php?' . http_build_query($params);
}

require_once '../templates/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    .print-full-width { width: 100% !important; max-width: none !important; }
}
</style>

<div class="container mx-auto px-4 py-6">
    
    <!-- Page Header -->
    <div class="flex justify-between items-center mb-6 no-print">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-money-check-alt text-green-600"></i> Purchase Payments
            </h1>
            <p class="text-gray-600 mt-1">
                Complete list of all payment records 
                <span class="text-sm">
                    (Showing <?php echo number_format($offset + 1); ?>-<?php echo number_format(min($offset + $items_per_page, $total_records)); ?> 
                    of <?php echo number_format($total_records); ?>)
                </span>
            </p>
        </div>
        <div class="flex gap-2">
            <a href="purchase_adnan_index.php" class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <a href="purchase_adnan_record_payment.php" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 flex items-center gap-2">
                <i class="fas fa-plus"></i> New Payment
            </a>
            <button onclick="window.print()" class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 flex items-center gap-2">
                <i class="fas fa-print"></i> Print
            </button>
            <button onclick="exportToExcel()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 flex items-center gap-2">
                <i class="fas fa-file-excel"></i> Export
            </button>
        </div>
    </div>

    <?php echo display_message(); ?>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Total Payments -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 font-medium">Total Payments</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1"><?php echo number_format($stats->total_payments); ?></p>
                </div>
                <div class="bg-blue-100 rounded-full p-3">
                    <i class="fas fa-receipt text-blue-600 text-2xl"></i>
                </div>
            </div>
            <div class="mt-2 text-xs text-gray-500">
                <span class="text-green-600 font-semibold"><?php echo $stats->posted_count; ?> Posted</span> • 
                <span class="text-yellow-600"><?php echo $stats->pending_count; ?> Pending</span>
            </div>
        </div>

        <!-- Total Amount -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 font-medium">Total Amount</p>
                    <p class="text-3xl font-bold text-green-600 mt-1">৳<?php echo number_format($stats->total_amount, 0); ?></p>
                </div>
                <div class="bg-green-100 rounded-full p-3">
                    <i class="fas fa-money-bill-wave text-green-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Payment Methods -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div class="w-full">
                    <p class="text-sm text-gray-600 font-medium mb-2">Payment Methods</p>
                    <div class="space-y-1 text-xs">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Bank:</span>
                            <span class="font-semibold"><?php echo $stats->bank_count; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Cash:</span>
                            <span class="font-semibold"><?php echo $stats->cash_count; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Cheque:</span>
                            <span class="font-semibold"><?php echo $stats->cheque_count; ?></span>
                        </div>
                    </div>
                </div>
                <div class="bg-purple-100 rounded-full p-3">
                    <i class="fas fa-wallet text-purple-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Payment Types -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div class="w-full">
                    <p class="text-sm text-gray-600 font-medium mb-2">Payment Types</p>
                    <div class="space-y-1 text-xs">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Regular:</span>
                            <span class="font-semibold"><?php echo $stats->regular_count; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Advance:</span>
                            <span class="font-semibold text-orange-600"><?php echo $stats->advance_count; ?></span>
                        </div>
                    </div>
                </div>
                <div class="bg-orange-100 rounded-full p-3">
                    <i class="fas fa-tags text-orange-600 text-2xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-6 mb-6 no-print">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900">
                <i class="fas fa-filter text-blue-600"></i> Filters
            </h3>
            <div class="flex items-center gap-4">
                <!-- Items per page -->
                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-600">Show:</label>
                    <select onchange="window.location.href='<?php echo getPaginationUrl(1, $filters, ''); ?>' + this.value" 
                            class="px-3 py-1 border border-gray-300 rounded-md text-sm">
                        <option value="25" <?php echo $items_per_page == 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?php echo $items_per_page == 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $items_per_page == 100 ? 'selected' : ''; ?>>100</option>
                        <option value="200" <?php echo $items_per_page == 200 ? 'selected' : ''; ?>>200</option>
                        <option value="500" <?php echo $items_per_page == 500 ? 'selected' : ''; ?>>500</option>
                    </select>
                    <span class="text-sm text-gray-600">per page</span>
                </div>
                
                <?php if (!empty($active_filters)): ?>
                <a href="payments.php?per_page=<?php echo $items_per_page; ?>" class="text-sm text-red-600 hover:text-red-800">
                    <i class="fas fa-times-circle"></i> Clear All Filters
                </a>
                <?php endif; ?>
            </div>
        </div>

        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
            
            <!-- Hidden field to preserve items per page -->
            <input type="hidden" name="per_page" value="<?php echo $items_per_page; ?>">
            
            <!-- Date From -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
            </div>

            <!-- Date To -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
            </div>

            <!-- Supplier -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
                <select name="supplier_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Suppliers</option>
                    <?php foreach ($suppliers as $supplier): ?>
                    <option value="<?php echo $supplier->id; ?>" <?php echo $filters['supplier_id'] == $supplier->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($supplier->company_name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Payment Method -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                <select name="payment_method" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Methods</option>
                    <option value="bank" <?php echo $filters['payment_method'] == 'bank' ? 'selected' : ''; ?>>Bank Transfer</option>
                    <option value="cash" <?php echo $filters['payment_method'] == 'cash' ? 'selected' : ''; ?>>Cash</option>
                    <option value="cheque" <?php echo $filters['payment_method'] == 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                </select>
            </div>

            <!-- Payment Type -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Type</label>
                <select name="payment_type" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Types</option>
                    <option value="regular" <?php echo $filters['payment_type'] == 'regular' ? 'selected' : ''; ?>>Regular</option>
                    <option value="advance" <?php echo $filters['payment_type'] == 'advance' ? 'selected' : ''; ?>>Advance</option>
                </select>
            </div>

            <!-- Status -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Posting Status</label>
                <select name="is_posted" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Statuses</option>
                    <option value="1" <?php echo $filters['is_posted'] === '1' ? 'selected' : ''; ?>>Posted</option>
                    <option value="0" <?php echo $filters['is_posted'] === '0' ? 'selected' : ''; ?>>Pending</option>
                </select>
            </div>

            <!-- PO Number -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">PO Number</label>
                <input type="text" name="po_number" value="<?php echo htmlspecialchars($filters['po_number']); ?>" 
                       placeholder="Search PO..." 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
            </div>

            <!-- Search -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>" 
                       placeholder="Voucher#, PO#, Ref#..." 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
            </div>

            <!-- Submit Button -->
            <div class="flex items-end md:col-span-3 lg:col-span-4 gap-2">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700">
                    <i class="fas fa-search mr-2"></i>Apply Filters
                </button>
                <a href="payments.php?per_page=<?php echo $items_per_page; ?>" class="bg-gray-200 text-gray-700 px-6 py-2 rounded-md hover:bg-gray-300">
                    <i class="fas fa-redo mr-2"></i>Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Payments Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200" id="paymentsTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Voucher #</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PO #</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Supplier</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider no-print">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="10" class="px-6 py-8 text-center text-gray-500">
                            <i class="fas fa-inbox text-4xl text-gray-300 mb-2"></i>
                            <p>No payment records found</p>
                            <?php if (!empty($active_filters)): ?>
                            <a href="payments.php" class="text-blue-600 hover:text-blue-800 text-sm">Clear filters</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $payment): ?>
                        <tr class="hover:bg-gray-50">
                            <!-- Voucher Number -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <a href="purchase_adnan_payment_receipt.php?id=<?php echo $payment->id; ?>" 
                                   class="text-blue-600 hover:text-blue-800 font-medium">
                                    <?php echo htmlspecialchars($payment->payment_voucher_number); ?>
                                </a>
                            </td>

                            <!-- Payment Date -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                <?php echo date('d M Y', strtotime($payment->payment_date)); ?>
                            </td>

                            <!-- PO Number -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <a href="purchase_adnan_view_po.php?id=<?php echo $payment->purchase_order_id; ?>" 
                                   class="text-purple-600 hover:text-purple-800 text-sm">
                                    <?php echo htmlspecialchars($payment->po_number); ?>
                                </a>
                            </td>

                            <!-- Supplier -->
                            <td class="px-4 py-3 text-sm text-gray-900">
                                <?php echo htmlspecialchars($payment->supplier_name); ?>
                            </td>

                            <!-- Amount -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-bold text-green-600">
                                ৳<?php echo number_format($payment->amount_paid, 2); ?>
                            </td>

                            <!-- Payment Method -->
                            <td class="px-4 py-3 whitespace-nowrap text-center">
                                <?php
                                $method_colors = [
                                    'bank' => 'bg-blue-100 text-blue-800',
                                    'cash' => 'bg-green-100 text-green-800',
                                    'cheque' => 'bg-purple-100 text-purple-800'
                                ];
                                $method_icons = [
                                    'bank' => 'fa-university',
                                    'cash' => 'fa-money-bill',
                                    'cheque' => 'fa-money-check'
                                ];
                                ?>
                                <span class="px-2 py-1 rounded text-xs font-semibold <?php echo $method_colors[$payment->payment_method] ?? 'bg-gray-100 text-gray-800'; ?>">
                                    <i class="fas <?php echo $method_icons[$payment->payment_method] ?? 'fa-question'; ?>"></i>
                                    <?php echo strtoupper($payment->payment_method); ?>
                                </span>
                            </td>

                            <!-- Payment Type -->
                            <td class="px-4 py-3 whitespace-nowrap text-center">
                                <?php if ($payment->payment_type === 'advance'): ?>
                                <span class="px-2 py-1 bg-orange-100 text-orange-800 rounded text-xs font-semibold">
                                    <i class="fas fa-exclamation-triangle"></i> ADVANCE
                                </span>
                                <?php else: ?>
                                <span class="px-2 py-1 bg-gray-100 text-gray-800 rounded text-xs">
                                    Regular
                                </span>
                                <?php endif; ?>
                            </td>

                            <!-- Reference -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
                                <?php 
                                if ($payment->payment_method === 'bank' && $payment->bank_name) {
                                    echo htmlspecialchars($payment->bank_name);
                                    if ($payment->reference_number) {
                                        echo '<br><span class="text-xs text-gray-500">' . htmlspecialchars($payment->reference_number) . '</span>';
                                    }
                                } elseif ($payment->reference_number) {
                                    echo htmlspecialchars($payment->reference_number);
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>

                            <!-- Status -->
                            <td class="px-4 py-3 whitespace-nowrap text-center">
                                <?php if ($payment->is_posted): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-800 rounded text-xs font-semibold">
                                    <i class="fas fa-check-circle"></i> POSTED
                                </span>
                                <?php else: ?>
                                <span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded text-xs font-semibold">
                                    <i class="fas fa-clock"></i> PENDING
                                </span>
                                <?php endif; ?>
                            </td>

                            <!-- Actions -->
                            <td class="px-4 py-3 whitespace-nowrap text-center no-print">
                                <div class="flex items-center justify-center gap-2">
                                    <!-- View -->
                                    <a href="purchase_adnan_payment_receipt.php?id=<?php echo $payment->id; ?>" 
                                       class="text-blue-600 hover:text-blue-800" 
                                       title="View Receipt">
                                        <i class="fas fa-eye"></i>
                                    </a>

                                    <!-- Print -->
                                    <a href="purchase_adnan_payment_receipt.php?id=<?php echo $payment->id; ?>&print=1" 
                                       target="_blank"
                                       class="text-gray-600 hover:text-gray-800" 
                                       title="Print">
                                        <i class="fas fa-print"></i>
                                    </a>

                                    <?php if ($is_superadmin): ?>
                                    <!-- Edit -->
                                    <a href="purchase_adnan_edit_payment.php?id=<?php echo $payment->id; ?>" 
                                       class="text-orange-600 hover:text-orange-800" 
                                       title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>

                                    <!-- Delete -->
                                    <button onclick="deletePayment(<?php echo $payment->id; ?>, '<?php echo htmlspecialchars($payment->payment_voucher_number); ?>')" 
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

                <?php if (!empty($payments)): ?>
                <!-- Totals Footer -->
                <tfoot class="bg-gray-100 font-semibold">
                    <tr>
                        <td colspan="4" class="px-4 py-3 text-sm text-gray-900">TOTALS (Current Page)</td>
                        <td class="px-4 py-3 text-sm text-right text-green-600">
                            ৳<?php 
                            $page_total = array_sum(array_column($payments, 'amount_paid'));
                            echo number_format($page_total, 2); 
                            ?>
                        </td>
                        <td colspan="5"></td>
                    </tr>
                    <tr class="bg-blue-50">
                        <td colspan="4" class="px-4 py-3 text-sm font-bold text-gray-900">GRAND TOTAL (All Filtered)</td>
                        <td class="px-4 py-3 text-sm text-right font-bold text-blue-600">
                            ৳<?php echo number_format($stats->total_amount, 2); ?>
                        </td>
                        <td colspan="5"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="mt-6 flex items-center justify-between no-print">
        <div class="text-sm text-gray-600">
            Showing <?php echo number_format($offset + 1); ?> to <?php echo number_format(min($offset + $items_per_page, $total_records)); ?> 
            of <?php echo number_format($total_records); ?> results
        </div>
        
        <div class="flex items-center gap-2">
            <!-- First Page -->
            <?php if ($page > 1): ?>
            <a href="<?php echo getPaginationUrl(1, $filters, $items_per_page); ?>" 
               class="px-3 py-2 border border-gray-300 rounded-md hover:bg-gray-50">
                <i class="fas fa-angle-double-left"></i>
            </a>
            <?php endif; ?>

            <!-- Previous Page -->
            <?php if ($page > 1): ?>
            <a href="<?php echo getPaginationUrl($page - 1, $filters, $items_per_page); ?>" 
               class="px-3 py-2 border border-gray-300 rounded-md hover:bg-gray-50">
                <i class="fas fa-angle-left"></i> Previous
            </a>
            <?php endif; ?>

            <!-- Page Numbers -->
            <?php
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            
            for ($i = $start_page; $i <= $end_page; $i++):
            ?>
            <a href="<?php echo getPaginationUrl($i, $filters, $items_per_page); ?>" 
               class="px-3 py-2 border rounded-md <?php echo $i == $page ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 hover:bg-gray-50'; ?>">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>

            <!-- Next Page -->
            <?php if ($page < $total_pages): ?>
            <a href="<?php echo getPaginationUrl($page + 1, $filters, $items_per_page); ?>" 
               class="px-3 py-2 border border-gray-300 rounded-md hover:bg-gray-50">
                Next <i class="fas fa-angle-right"></i>
            </a>
            <?php endif; ?>

            <!-- Last Page -->
            <?php if ($page < $total_pages): ?>
            <a href="<?php echo getPaginationUrl($total_pages, $filters, $items_per_page); ?>" 
               class="px-3 py-2 border border-gray-300 rounded-md hover:bg-gray-50">
                <i class="fas fa-angle-double-right"></i>
            </a>
            <?php endif; ?>
        </div>

        <!-- Jump to Page -->
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-600">Go to:</span>
            <input type="number" 
                   min="1" 
                   max="<?php echo $total_pages; ?>" 
                   value="<?php echo $page; ?>" 
                   onchange="window.location.href='<?php echo getPaginationUrl('', $filters, $items_per_page); ?>'.replace('page=', 'page=' + this.value)"
                   class="w-16 px-2 py-1 border border-gray-300 rounded-md text-sm">
            <span class="text-sm text-gray-600">of <?php echo number_format($total_pages); ?></span>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center no-print" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4">
        <h3 class="text-xl font-bold text-gray-900 mb-4">
            <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>Delete Payment
        </h3>
        
        <p class="text-gray-600 mb-4">
            Are you sure you want to delete <strong id="deletePaymentNumber"></strong>?
        </p>

        <p class="text-sm text-red-600 mb-4">
            This will:
            <ul class="list-disc ml-5 mt-2">
                <li>Unpost the payment</li>
                <li>Reverse the journal entry (if posted)</li>
                <li>Recalculate PO balance</li>
            </ul>
        </p>

        <form method="POST" action="purchase_adnan_delete_payment.php" id="deleteForm">
            <input type="hidden" name="id" id="deletePaymentId">
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Reason for Deletion <span class="text-red-500">*</span>
                </label>
                <textarea name="reason" required rows="3"
                          placeholder="Please provide a reason for deleting this payment..."
                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-red-500 focus:border-red-500"></textarea>
            </div>

            <div class="flex justify-end space-x-3">
                <button type="button" onclick="hideDeleteModal()"
                        class="px-6 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">
                    <i class="fas fa-trash mr-2"></i>Delete Payment
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Delete Payment Function
function deletePayment(paymentId, paymentNumber) {
    document.getElementById('deletePaymentId').value = paymentId;
    document.getElementById('deletePaymentNumber').textContent = paymentNumber;
    document.getElementById('deleteModal').classList.remove('hidden');
    document.getElementById('deleteModal').classList.add('flex');
}

function hideDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('flex');
    document.getElementById('deleteForm').reset();
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideDeleteModal();
    }
});

// Close modal on background click
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideDeleteModal();
    }
});

// Export to Excel Function
function exportToExcel() {
    var table = document.getElementById('paymentsTable');
    var clonedTable = table.cloneNode(true);
    
    // Remove "Actions" column
    var rows = clonedTable.getElementsByTagName('tr');
    for (var i = 0; i < rows.length; i++) {
        var cells = rows[i].getElementsByTagName('th').length > 0 
            ? rows[i].getElementsByTagName('th') 
            : rows[i].getElementsByTagName('td');
        if (cells.length > 0) {
            rows[i].removeChild(cells[cells.length - 1]);
        }
    }
    
    var html = clonedTable.outerHTML;
    var url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    var downloadLink = document.createElement("a");
    var filename = 'purchase_payments_' + new Date().toISOString().slice(0,10) + '.xls';
    downloadLink.href = url;
    downloadLink.download = filename;
    downloadLink.click();
}
</script>

<?php require_once '../templates/footer.php'; ?>
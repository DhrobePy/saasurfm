<?php
/**
 * Expense Voucher List
 * View all expense vouchers with filtering and actions
 */

require_once '../core/init.php';
require_once '../core/classes/ExpenseManager.php';

global $db;

// Check permission
if (!canAccessExpense()) {
    header('Location: ' . url('unauthorized.php'));
    exit();
}

// Get Database instance
$dbInstance = Database::getInstance();
$expenseManager = new ExpenseManager($dbInstance);

// =============================================
// HANDLE ACTIONS (Delete, etc.)
// =============================================
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    if ($_GET['action'] === 'delete' && canDeleteExpense()) {
        // Only allow deleting pending vouchers
        $voucher = $db->query("SELECT status FROM expense_vouchers WHERE id = ?", [$id])->first();
        
        if ($voucher && $voucher->status === 'pending') {
            $db->query("DELETE FROM expense_vouchers WHERE id = ?", [$id]);
            $_SESSION['success_flash'] = 'Expense voucher deleted successfully';
        } else {
            $_SESSION['error_flash'] = 'Only pending vouchers can be deleted';
        }
        
        header('Location: ' . url('expense/expense_voucher_list.php'));
        exit();
    }
}

// =============================================
// GET FILTER PARAMETERS
// =============================================
$filters = [
    'status' => $_GET['status'] ?? '',
    'branch_id' => $_GET['branch_id'] ?? '',
    'category_id' => $_GET['category_id'] ?? '',
    'date_from' => $_GET['date_from'] ?? date('Y-m-01'), // Default: This month
    'date_to' => $_GET['date_to'] ?? date('Y-m-d'),
    'search' => $_GET['search'] ?? ''
];

// =============================================
// BUILD QUERY
// =============================================
$sql = "SELECT ev.*, 
               ec.category_name,
               es.subcategory_name,
               b.name as branch_name,
               u.display_name as created_by_name,
               approver.display_name as approved_by_name
        FROM expense_vouchers ev
        LEFT JOIN expense_categories ec ON ev.category_id = ec.id
        LEFT JOIN expense_subcategories es ON ev.subcategory_id = es.id
        LEFT JOIN branches b ON ev.branch_id = b.id
        LEFT JOIN users u ON ev.created_by_user_id = u.id
        LEFT JOIN users approver ON ev.approved_by_user_id = approver.id
        WHERE 1=1";

$params = [];

// Date range filter
if ($filters['date_from']) {
    $sql .= " AND ev.expense_date >= ?";
    $params[] = $filters['date_from'];
}
if ($filters['date_to']) {
    $sql .= " AND ev.expense_date <= ?";
    $params[] = $filters['date_to'];
}

// Status filter
if ($filters['status']) {
    $sql .= " AND ev.status = ?";
    $params[] = $filters['status'];
}

// Branch filter
if ($filters['branch_id']) {
    $sql .= " AND ev.branch_id = ?";
    $params[] = $filters['branch_id'];
}

// Category filter
if ($filters['category_id']) {
    $sql .= " AND ev.category_id = ?";
    $params[] = $filters['category_id'];
}

// Search filter
if ($filters['search']) {
    $sql .= " AND (ev.voucher_number LIKE ? OR ev.remarks LIKE ? OR ec.category_name LIKE ?)";
    $searchTerm = '%' . $filters['search'] . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql .= " ORDER BY ev.created_at DESC LIMIT 100";

$vouchers = $db->query($sql, $params)->results();

// Get summary statistics
$stats = $db->query("
    SELECT 
        COUNT(*) as total_vouchers,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN status = 'approved' THEN total_amount ELSE 0 END) as approved_total
    FROM expense_vouchers
    WHERE expense_date BETWEEN ? AND ?
", [$filters['date_from'], $filters['date_to']])->first();

// Get dropdown data
$categories = $expenseManager->getAllCategories();
$branches = $expenseManager->getAllBranches();

$pageTitle = "Expense Vouchers";
require_once '../templates/header.php';
?>

<div class="container mx-auto px-4 py-6">
    
    <!-- Page Header -->
    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-receipt text-blue-600"></i> Expense Vouchers
            </h1>
            <p class="text-gray-600 mt-2">View and manage all expense vouchers</p>
        </div>
        <div>
            <a href="<?php echo url('expense/create_expense.php'); ?>" 
               class="px-6 py-3 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg hover:from-blue-600 hover:to-blue-700 transition shadow-md">
                <i class="fas fa-plus-circle mr-2"></i>Create New Expense
            </a>
        </div>
    </div>

    <?php echo display_message(); ?>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-blue-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 mb-1">Total Vouchers</p>
                    <h3 class="text-2xl font-bold text-gray-800"><?php echo number_format($stats->total_vouchers); ?></h3>
                </div>
                <div class="bg-blue-100 rounded-full p-3">
                    <i class="fas fa-file-invoice text-blue-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-yellow-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 mb-1">Pending</p>
                    <h3 class="text-2xl font-bold text-gray-800"><?php echo number_format($stats->pending_count); ?></h3>
                </div>
                <div class="bg-yellow-100 rounded-full p-3">
                    <i class="fas fa-clock text-yellow-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-green-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 mb-1">Approved</p>
                    <h3 class="text-2xl font-bold text-gray-800"><?php echo number_format($stats->approved_count); ?></h3>
                </div>
                <div class="bg-green-100 rounded-full p-3">
                    <i class="fas fa-check-circle text-green-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-purple-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 mb-1">Approved Amount</p>
                    <h3 class="text-2xl font-bold text-gray-800">৳ <?php echo number_format($stats->approved_total, 2); ?></h3>
                </div>
                <div class="bg-purple-100 rounded-full p-3">
                    <i class="fas fa-money-bill-wave text-purple-600 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <form method="GET" id="filterForm" class="grid grid-cols-1 md:grid-cols-6 gap-4">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Status</option>
                    <option value="pending" <?php echo $filters['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $filters['status'] === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="rejected" <?php echo $filters['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Branch</label>
                <select name="branch_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo $branch->id; ?>" <?php echo $filters['branch_id'] == $branch->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($branch->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                <select name="category_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category->id; ?>" <?php echo $filters['category_id'] == $category->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category->category_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>"
                       placeholder="Voucher #, remarks..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div class="md:col-span-6 flex gap-2">
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                    <i class="fas fa-filter mr-2"></i>Apply Filters
                </button>
                <a href="<?php echo url('expense/expense_voucher_list.php'); ?>" 
                   class="px-6 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                    <i class="fas fa-redo mr-2"></i>Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Vouchers Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Voucher #</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Branch</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created By</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($vouchers)): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-inbox text-6xl text-gray-300 mb-4"></i>
                                <p class="text-lg">No expense vouchers found</p>
                                <p class="text-sm mt-2">Try adjusting your filters or create a new expense voucher</p>
                                <a href="<?php echo url('expense/create_expense.php'); ?>" 
                                   class="mt-4 inline-block px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                                    <i class="fas fa-plus mr-2"></i>Create New Expense
                                </a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($vouchers as $voucher): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="text-sm font-medium text-blue-600">
                                        <?php echo htmlspecialchars($voucher->voucher_number); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?php echo date('M d, Y', strtotime($voucher->expense_date)); ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <div class="font-medium"><?php echo htmlspecialchars($voucher->category_name); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($voucher->subcategory_name); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?php echo htmlspecialchars($voucher->branch_name); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <span class="text-sm font-semibold text-gray-900">
                                        ৳ <?php echo number_format($voucher->total_amount, 2); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <?php
                                    $statusColors = [
                                        'pending' => 'bg-yellow-100 text-yellow-800',
                                        'approved' => 'bg-green-100 text-green-800',
                                        'rejected' => 'bg-red-100 text-red-800',
                                        'draft' => 'bg-gray-100 text-gray-800'
                                    ];
                                    $statusIcons = [
                                        'pending' => 'clock',
                                        'approved' => 'check-circle',
                                        'rejected' => 'times-circle',
                                        'draft' => 'file'
                                    ];
                                    $color = $statusColors[$voucher->status] ?? 'bg-gray-100 text-gray-800';
                                    $icon = $statusIcons[$voucher->status] ?? 'question-circle';
                                    ?>
                                    <span class="px-3 py-1 inline-flex items-center text-xs leading-5 font-semibold rounded-full <?php echo $color; ?>">
                                        <i class="fas fa-<?php echo $icon; ?> mr-1"></i>
                                        <?php echo ucfirst($voucher->status); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <div><?php echo htmlspecialchars($voucher->created_by_name); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo date('M d, h:i A', strtotime($voucher->created_at)); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                    <div class="flex justify-center space-x-2">
                                        <!-- View Button -->
                                        <a href="<?php echo url('expense/view_expense_voucher.php?id=' . $voucher->id); ?>" 
                                           class="text-blue-600 hover:text-blue-900" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <!-- Print Button (approved only) -->
                                        <?php if ($voucher->status === 'approved'): ?>
                                            <a href="<?php echo url('expense/print_expense_voucher.php?id=' . $voucher->id); ?>" 
                                               target="_blank"
                                               class="text-green-600 hover:text-green-900" title="Print">
                                                <i class="fas fa-print"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <!-- Delete Button (pending only, if has permission) -->
                                        <?php if ($voucher->status === 'pending' && canDeleteExpense()): ?>
                                            <a href="<?php echo url('expense/expense_voucher_list.php?action=delete&id=' . $voucher->id); ?>" 
                                               onclick="return confirm('Are you sure you want to delete this voucher?');"
                                               class="text-red-600 hover:text-red-900" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($vouchers)): ?>
        <div class="bg-gray-50 px-6 py-4 border-t">
            <p class="text-sm text-gray-600">
                Showing <?php echo count($vouchers); ?> voucher(s)
                <?php if (count($vouchers) >= 100): ?>
                    <span class="text-orange-600 font-medium">(Limited to 100 results - use filters to narrow down)</span>
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
$(document).ready(function() {
    // Auto-submit on filter change (optional)
    // Uncomment if you want instant filtering
    /*
    $('select[name], input[name="date_from"], input[name="date_to"]').on('change', function() {
        $('#filterForm').submit();
    });
    */
});
</script>

<?php require_once '../templates/footer.php'; ?>
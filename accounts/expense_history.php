<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Expense History';

// Get filter parameters with defaults to TODAY
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$expense_type = isset($_GET['expense_type']) ? $_GET['expense_type'] : 'all';
$branch_filter = isset($_GET['branch_filter']) ? $_GET['branch_filter'] : 'all';

// Build query
$sql = "SELECT dv.*, 
        ea.name as expense_account_name,
        ea.account_number as expense_account_number,
        pa.name as payment_account_name,
        pa.account_number as payment_account_number,
        u.display_name as created_by_name,
        b.name as branch_name,
        CONCAT(e.first_name, ' ', e.last_name) as employee_name
 FROM debit_vouchers dv
 LEFT JOIN chart_of_accounts ea ON dv.expense_account_id = ea.id
 LEFT JOIN chart_of_accounts pa ON dv.payment_account_id = pa.id
 LEFT JOIN users u ON dv.created_by_user_id = u.id
 LEFT JOIN branches b ON dv.branch_id = b.id
 LEFT JOIN employees e ON dv.employee_id = e.id
 WHERE 1=1";

$params = [];

// Date range filter
$sql .= " AND dv.voucher_date >= ? AND dv.voucher_date <= ?";
$params[] = $from_date;
$params[] = $to_date;

// Search filter (voucher number, paid to, description, reference)
if (!empty($search)) {
    $sql .= " AND (dv.voucher_number LIKE ? OR dv.paid_to LIKE ? OR dv.description LIKE ? OR dv.reference_number LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

// Expense type filter
if ($expense_type !== 'all') {
    $sql .= " AND ea.name LIKE ?";
    $params[] = "%{$expense_type}%";
}

// Branch filter
if ($branch_filter !== 'all') {
    $sql .= " AND dv.branch_id = ?";
    $params[] = (int)$branch_filter;
}

$sql .= " ORDER BY dv.voucher_date DESC, dv.created_at DESC";

// Execute query
$expenses = $db->query($sql, $params)->results();

// Get statistics
$total_amount = array_sum(array_map(function($exp) { return floatval($exp->amount); }, $expenses));
$total_count = count($expenses);

// Get branches for filter
$branches = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name")->results();

// Get expense types for filter
$expense_types = $db->query(
    "SELECT DISTINCT name FROM chart_of_accounts 
     WHERE account_type IN ('Expense', 'Cost of Goods Sold', 'Other Expense') 
     AND status = 'active' 
     ORDER BY name"
)->results();

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Page Header -->
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
        <p class="text-lg text-gray-600 mt-1">View and manage all expense vouchers with accounting details</p>
    </div>
    <a href="debit_voucher.php" class="px-6 py-3 bg-primary-600 text-white rounded-lg hover:bg-primary-700 shadow-md">
        <i class="fas fa-plus mr-2"></i>New Expense
    </a>
</div>

<!-- Statistics Cards -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Total Expenses</p>
        <p class="text-3xl font-bold mt-2"><?php echo $total_count; ?></p>
        <p class="text-xs opacity-75 mt-1">Vouchers in period</p>
    </div>
    <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Total Amount</p>
        <p class="text-3xl font-bold mt-2">৳<?php echo number_format($total_amount, 2); ?></p>
        <p class="text-xs opacity-75 mt-1">Sum of all expenses</p>
    </div>
    <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Average Expense</p>
        <p class="text-3xl font-bold mt-2">৳<?php echo $total_count > 0 ? number_format($total_amount / $total_count, 2) : '0.00'; ?></p>
        <p class="text-xs opacity-75 mt-1">Per voucher</p>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <h3 class="text-lg font-semibold text-gray-800 mb-4">
        <i class="fas fa-filter mr-2"></i>Filter Expenses
    </h3>
    <form method="GET" action="expense_history.php" class="grid grid-cols-1 md:grid-cols-6 gap-4">
        <!-- From Date -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
            <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" 
                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
        </div>
        
        <!-- To Date -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
            <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" 
                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
        </div>
        
        <!-- Search -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                   placeholder="Voucher, beneficiary, etc."
                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
        </div>
        
        <!-- Expense Type -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Expense Type</label>
            <select name="expense_type" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                <option value="all">All Types</option>
                <?php foreach ($expense_types as $type): ?>
                <option value="<?php echo htmlspecialchars($type->name); ?>" <?php echo $expense_type === $type->name ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($type->name); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- Branch -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Branch</label>
            <select name="branch_filter" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                <option value="all">All Branches</option>
                <?php foreach ($branches as $branch): ?>
                <option value="<?php echo $branch->id; ?>" <?php echo $branch_filter == $branch->id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($branch->name); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- Buttons -->
        <div class="flex items-end gap-2">
            <button type="submit" class="flex-1 bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition-colors shadow-sm font-medium">
                <i class="fas fa-search mr-1"></i>Filter
            </button>
            <a href="expense_history.php" class="bg-gray-200 text-gray-700 px-3 py-2 rounded-md hover:bg-gray-300 transition-colors shadow-sm" title="Reset">
                <i class="fas fa-undo"></i>
            </a>
        </div>
    </form>
    
    <div class="mt-3 pt-3 border-t border-gray-200 text-sm text-gray-600">
        <i class="fas fa-info-circle mr-1"></i>
        Showing <strong><?php echo $total_count; ?></strong> expense(s) 
        from <strong><?php echo date('M j, Y', strtotime($from_date)); ?></strong> 
        to <strong><?php echo date('M j, Y', strtotime($to_date)); ?></strong>
        <?php if (!empty($search)): ?>
            matching "<strong><?php echo htmlspecialchars($search); ?></strong>"
        <?php endif; ?>
    </div>
</div>

<!-- Expense List -->
<div class="bg-white rounded-lg shadow-lg overflow-hidden">
    <div class="p-5 border-b border-gray-200 bg-gray-50">
        <h2 class="text-xl font-bold text-gray-800">
            Expense Vouchers
        </h2>
    </div>
    
    <?php if (!empty($expenses)): ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Voucher #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Paid To</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Description</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase">Accounting</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-600 uppercase">Amount</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-600 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($expenses as $expense): ?>
                <tr class="hover:bg-blue-50 transition-colors">
                    <td class="px-6 py-4 text-sm font-medium text-primary-700">
                        <?php echo htmlspecialchars($expense->voucher_number); ?>
                        <?php if ($expense->reference_number): ?>
                        <div class="text-xs text-gray-500">Ref: <?php echo htmlspecialchars($expense->reference_number); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900">
                        <?php echo date('d-M-Y', strtotime($expense->voucher_date)); ?>
                        <div class="text-xs text-gray-500"><?php echo $expense->branch_name ?: 'Head Office'; ?></div>
                    </td>
                    <td class="px-6 py-4 text-sm font-medium text-gray-900">
                        <?php echo htmlspecialchars($expense->paid_to); ?>
                        <?php if ($expense->employee_name): ?>
                        <div class="text-xs text-gray-500"><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($expense->employee_name); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-700">
                        <?php echo htmlspecialchars($expense->description); ?>
                    </td>
                    <td class="px-6 py-4 text-sm">
                        <div class="space-y-1">
                            <div class="text-red-600 font-medium">
                                <i class="fas fa-arrow-up mr-1"></i>DR: <?php echo htmlspecialchars($expense->expense_account_name); ?>
                            </div>
                            <div class="text-green-600 font-medium">
                                <i class="fas fa-arrow-down mr-1"></i>CR: <?php echo htmlspecialchars($expense->payment_account_name); ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm text-right font-bold text-gray-900">
                        ৳<?php echo number_format($expense->amount, 2); ?>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <div class="flex justify-center gap-2">
                            <a href="debit_voucher_print.php?id=<?php echo $expense->id; ?>" 
                               target="_blank"
                               class="text-white bg-blue-600 hover:bg-blue-700 px-3 py-1 rounded shadow-sm transition-colors" 
                               title="Print Voucher">
                                <i class="fas fa-print"></i>
                            </a>
                            <a href="debit_voucher_view.php?id=<?php echo $expense->id; ?>" 
                               class="text-white bg-green-600 hover:bg-green-700 px-3 py-1 rounded shadow-sm transition-colors" 
                               title="View Details">
                                <i class="fas fa-eye"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-gray-50">
                <tr>
                    <td colspan="5" class="px-6 py-4 text-right font-bold text-gray-900">Total:</td>
                    <td class="px-6 py-4 text-right font-bold text-primary-700 text-lg">৳<?php echo number_format($total_amount, 2); ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php else: ?>
    <div class="p-12 text-center text-gray-500">
        <i class="fas fa-inbox text-5xl mb-4 text-gray-300"></i>
        <p class="text-lg font-medium">No expenses found</p>
        <p class="text-sm">Try adjusting the filters above or create a new expense voucher.</p>
    </div>
    <?php endif; ?>
</div>

</div>

<?php require_once '../templates/footer.php'; ?>
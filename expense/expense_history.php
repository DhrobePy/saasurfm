<?php
/**
 * Expense History - Smart Dashboard
 * FULLY CORRECTED VERSION
 * 
 * Fixes:
 * 1. NO redundant containers (uses header's max-w-7xl container)
 * 2. Correct SQL joins (expense_categories, expense_subcategories)
 * 3. Correct column names (voucher_number, total_amount)
 * 4. Custom primary color palette (not hardcoded blue)
 * 5. Matches Fajracct Tailwind style
 * 
 * Access: Superadmin, admin, Accounts, Expense Approver
 */

require_once '../core/init.php';
require_once '../core/functions/helpers.php'; 
require_once '../core/classes/ExpenseManager.php';

global $db;

// Check permission
if (!canAccessExpenseHistory()) {
    $_SESSION['error_flash'] = 'You do not have permission to access expense history.';
    header('Location: ' . url('index.php'));
    exit();
}

// Get Database instance
$dbInstance = Database::getInstance();
$expenseManager = new ExpenseManager($dbInstance);

// Check if user can see dashboard
$showDashboard = canSeeExpenseDashboard();
$isSuperadmin = ($_SESSION['user_role'] ?? '') === 'Superadmin';

// =============================================
// HANDLE DELETE ACTION (Superadmin only)
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!$isSuperadmin) {
        $_SESSION['error_flash'] = 'Only Superadmin can delete expenses.';
    } else {
        $voucher_id = (int)($_POST['voucher_id'] ?? 0);
        if ($voucher_id) {
            try {
                $result = $expenseManager->deleteExpenseVoucher($voucher_id);
                if ($result['success']) {
                    $_SESSION['success_flash'] = $result['message'];
                } else {
                    $_SESSION['error_flash'] = $result['message'];
                }
            } catch (Exception $e) {
                $_SESSION['error_flash'] = 'Error deleting expense: ' . $e->getMessage();
            }
        }
    }
    header('Location: ' . url('expense/expense_history.php'));
    exit();
}

// =============================================
// GET FILTERS
// =============================================
$filters = [
    'date_from' => $_GET['date_from'] ?? date('Y-m-01'),
    'date_to' => $_GET['date_to'] ?? date('Y-m-d'),
    'branch_id' => $_GET['branch_id'] ?? '',
    'category_id' => $_GET['category_id'] ?? '',
    'subcategory_id' => $_GET['subcategory_id'] ?? '',
    'payment_method' => $_GET['payment_method'] ?? '',
    'status' => $_GET['status'] ?? 'approved',
    'search' => $_GET['search'] ?? '',
    'min_amount' => $_GET['min_amount'] ?? '',
    'max_amount' => $_GET['max_amount'] ?? '',
    'employee_id' => $_GET['employee_id'] ?? ''
];

// =============================================
// GET DATA - CORRECT SQL JOINS
// =============================================

$sql = "SELECT ev.*, 
               ec.category_name,
               es.subcategory_name,
               b.name as branch_name,
               creator.display_name as created_by_name,
               approver.display_name as approved_by_name,
               emp.display_name as employee_name
        FROM expense_vouchers ev
        LEFT JOIN expense_categories ec ON ev.category_id = ec.id
        LEFT JOIN expense_subcategories es ON ev.subcategory_id = es.id
        LEFT JOIN branches b ON ev.branch_id = b.id
        LEFT JOIN users creator ON ev.created_by_user_id = creator.id
        LEFT JOIN users approver ON ev.approved_by_user_id = approver.id
        LEFT JOIN users emp ON ev.employee_id = emp.id
        WHERE 1=1";

$params = [];

// Status filter
if ($filters['status'] === 'approved') {
    $sql .= " AND ev.status = 'approved'";
} elseif ($filters['status'] === 'rejected') {
    $sql .= " AND ev.status = 'rejected'";
} elseif ($filters['status'] === 'pending') {
    $sql .= " AND ev.status = 'pending'";
} elseif ($filters['status'] === 'draft') {
    $sql .= " AND ev.status = 'draft'";
} elseif ($filters['status'] === 'cancelled') {
    $sql .= " AND ev.status = 'cancelled'";
}

// Date range
if ($filters['date_from']) {
    $sql .= " AND ev.expense_date >= ?";
    $params[] = $filters['date_from'];
}
if ($filters['date_to']) {
    $sql .= " AND ev.expense_date <= ?";
    $params[] = $filters['date_to'];
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

// Subcategory filter
if ($filters['subcategory_id']) {
    $sql .= " AND ev.subcategory_id = ?";
    $params[] = $filters['subcategory_id'];
}

// Payment method filter
if ($filters['payment_method']) {
    $sql .= " AND ev.payment_method = ?";
    $params[] = $filters['payment_method'];
}

// Employee filter
if ($filters['employee_id']) {
    $sql .= " AND ev.employee_id = ?";
    $params[] = $filters['employee_id'];
}

// Amount range filter
if ($filters['min_amount']) {
    $sql .= " AND ev.total_amount >= ?";
    $params[] = $filters['min_amount'];
}
if ($filters['max_amount']) {
    $sql .= " AND ev.total_amount <= ?";
    $params[] = $filters['max_amount'];
}

// Search filter
if ($filters['search']) {
    $sql .= " AND (ev.voucher_number LIKE ? OR ev.remarks LIKE ? OR ev.handled_by_person LIKE ? OR ev.payment_reference LIKE ?)";
    $searchTerm = '%' . $filters['search'] . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql .= " ORDER BY ev.expense_date DESC, ev.created_at DESC";

$expenses = $db->query($sql, $params)->results();

// Get dropdown data
$branches = $expenseManager->getAllBranches();
$categories = $expenseManager->getAllCategories();
$employees = $db->query("SELECT id, display_name FROM users WHERE status = 'active' ORDER BY display_name")->results();

// Get subcategories for selected category
$subcategories = [];
if ($filters['category_id']) {
    $subcategories = $expenseManager->getSubcategoriesByCategory($filters['category_id']);
}

// =============================================
// CALCULATE STATISTICS
// =============================================

$stats = [
    'total_amount' => 0,
    'total_count' => count($expenses),
    'by_category' => [],
    'by_branch' => [],
    'by_payment_method' => [],
    'by_status' => [
        'approved' => 0,
        'pending' => 0,
        'rejected' => 0,
        'draft' => 0,
        'cancelled' => 0
    ],
    'top_expense' => null,
    'average_amount' => 0,
    'daily_average' => 0
];

foreach ($expenses as $expense) {
    $stats['total_amount'] += $expense->total_amount;
    $stats['by_status'][$expense->status]++;
    
    if (!isset($stats['by_category'][$expense->category_name])) {
        $stats['by_category'][$expense->category_name] = 0;
    }
    $stats['by_category'][$expense->category_name] += $expense->total_amount;
    
    if ($expense->branch_name) {
        if (!isset($stats['by_branch'][$expense->branch_name])) {
            $stats['by_branch'][$expense->branch_name] = 0;
        }
        $stats['by_branch'][$expense->branch_name] += $expense->total_amount;
    }
    
    if (!isset($stats['by_payment_method'][$expense->payment_method])) {
        $stats['by_payment_method'][$expense->payment_method] = 0;
    }
    $stats['by_payment_method'][$expense->payment_method] += $expense->total_amount;
    
    if (!$stats['top_expense'] || $expense->total_amount > $stats['top_expense']->total_amount) {
        $stats['top_expense'] = $expense;
    }
}

arsort($stats['by_category']);
arsort($stats['by_branch']);

if ($stats['total_count'] > 0) {
    $stats['average_amount'] = $stats['total_amount'] / $stats['total_count'];
    
    $date_from = new DateTime($filters['date_from']);
    $date_to = new DateTime($filters['date_to']);
    $days = $date_to->diff($date_from)->days + 1;
    $stats['daily_average'] = $stats['total_amount'] / $days;
}

// =============================================
// AI INSIGHTS
// =============================================

$insights = [];

if ($stats['total_amount'] > 0) {
    $insights[] = [
        'type' => 'info',
        'icon' => 'chart-line',
        'title' => 'Spending Overview',
        'message' => "Total spending of ৳" . number_format($stats['total_amount'], 2) . " across {$stats['total_count']} vouchers with an average of ৳" . number_format($stats['average_amount'], 2) . " per transaction."
    ];
}

if (!empty($stats['by_category'])) {
    $topCategory = array_key_first($stats['by_category']);
    $topCategoryAmount = $stats['by_category'][$topCategory];
    $percentage = ($topCategoryAmount / $stats['total_amount']) * 100;
    
    if ($percentage > 50) {
        $insights[] = [
            'type' => 'warning',
            'icon' => 'exclamation-triangle',
            'title' => 'High Concentration Alert',
            'message' => "{$topCategory} accounts for " . number_format($percentage, 1) . "% of total expenses (৳" . number_format($topCategoryAmount, 2) . "). Consider diversifying or reviewing this category."
        ];
    } else {
        $insights[] = [
            'type' => 'success',
            'icon' => 'check-circle',
            'title' => 'Top Spending Category',
            'message' => "{$topCategory} is the largest expense category at ৳" . number_format($topCategoryAmount, 2) . " (" . number_format($percentage, 1) . "% of total)."
        ];
    }
}

if ($stats['by_status']['pending'] > 0) {
    $insights[] = [
        'type' => 'warning',
        'icon' => 'clock',
        'title' => 'Pending Approvals',
        'message' => "{$stats['by_status']['pending']} expense vouchers are pending approval. Review them to maintain workflow efficiency."
    ];
}

$pageTitle = "Expense History";
require_once '../templates/header.php';
?>

<!-- NO CONTAINER - Header already provides max-w-7xl container -->

<!-- Page Header -->
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900 flex items-center">
            <i class="fas fa-history text-primary-600 mr-3"></i>
            Expense History
            <?php if ($showDashboard): ?>
                <span class="ml-3 px-3 py-1 bg-primary-100 text-primary-800 text-sm font-semibold rounded-full">
                    Smart Analytics
                </span>
            <?php endif; ?>
        </h1>
        <p class="text-gray-600 mt-1">Complete expense tracking with AI-powered insights</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="<?php echo url('expense/create_expense.php'); ?>" 
           class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg flex items-center transition-colors">
            <i class="fas fa-plus-circle mr-2"></i>Create New
        </a>
        <?php if (canAccessApproveExpense()): ?>
            <a href="<?php echo url('expense/approve_expense.php'); ?>" 
               class="px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg flex items-center transition-colors">
                <i class="fas fa-check-circle mr-2"></i>
                Pending Approvals
                <?php if ($stats['by_status']['pending'] > 0): ?>
                    <span class="ml-2 px-2 py-0.5 bg-red-500 text-white text-xs rounded-full">
                        <?php echo $stats['by_status']['pending']; ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php echo display_message(); ?>

<!-- Quick Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
    
    <!-- Total Amount -->
    <div class="bg-gradient-to-br from-primary-500 to-primary-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-primary-100 text-sm font-medium">Total Expenses</p>
                <h3 class="text-3xl font-bold mt-1">৳<?php echo number_format($stats['total_amount'], 0); ?></h3>
                <p class="text-primary-100 text-xs mt-1"><?php echo $stats['total_count']; ?> vouchers</p>
            </div>
            <div class="bg-white bg-opacity-20 rounded-full p-3">
                <i class="fas fa-receipt text-2xl"></i>
            </div>
        </div>
    </div>

    <!-- Average Amount -->
    <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-green-100 text-sm font-medium">Average Amount</p>
                <h3 class="text-3xl font-bold mt-1">৳<?php echo number_format($stats['average_amount'], 0); ?></h3>
                <p class="text-green-100 text-xs mt-1">per voucher</p>
            </div>
            <div class="bg-white bg-opacity-20 rounded-full p-3">
                <i class="fas fa-calculator text-2xl"></i>
            </div>
        </div>
    </div>

    <!-- Daily Average -->
    <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-purple-100 text-sm font-medium">Daily Average</p>
                <h3 class="text-3xl font-bold mt-1">৳<?php echo number_format($stats['daily_average'], 0); ?></h3>
                <p class="text-purple-100 text-xs mt-1">per day</p>
            </div>
            <div class="bg-white bg-opacity-20 rounded-full p-3">
                <i class="fas fa-calendar-day text-2xl"></i>
            </div>
        </div>
    </div>

    <!-- Status Summary -->
    <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-orange-100 text-sm font-medium">Status Summary</p>
                <div class="mt-2 space-y-1">
                    <div class="flex items-center justify-between text-xs">
                        <span>Approved</span>
                        <span class="font-bold"><?php echo $stats['by_status']['approved']; ?></span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span>Pending</span>
                        <span class="font-bold"><?php echo $stats['by_status']['pending']; ?></span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span>Rejected</span>
                        <span class="font-bold"><?php echo $stats['by_status']['rejected']; ?></span>
                    </div>
                </div>
            </div>
            <div class="bg-white bg-opacity-20 rounded-full p-3">
                <i class="fas fa-tasks text-2xl"></i>
            </div>
        </div>
    </div>

</div>

<?php if ($showDashboard && !empty($insights)): ?>
<!-- AI Insights -->
<div class="bg-gradient-to-r from-yellow-50 to-orange-50 border-l-4 border-yellow-500 rounded-lg shadow-md p-6 mb-6">
    <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
        <i class="fas fa-brain text-yellow-600 mr-3"></i>
        AI-Powered Insights & Recommendations
    </h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php foreach ($insights as $insight): ?>
            <?php
            $borderColor = $insight['type'] === 'warning' ? 'yellow' : ($insight['type'] === 'success' ? 'green' : 'primary');
            $textColor = $insight['type'] === 'warning' ? 'yellow' : ($insight['type'] === 'success' ? 'green' : 'primary');
            ?>
            <div class="bg-white rounded-lg p-4 border-l-4 border-<?php echo $borderColor; ?>-500 shadow-sm">
                <h3 class="font-semibold text-gray-900 flex items-center mb-2">
                    <i class="fas fa-<?php echo $insight['icon']; ?> text-<?php echo $textColor; ?>-600 mr-2"></i>
                    <?php echo htmlspecialchars($insight['title']); ?>
                </h3>
                <p class="text-gray-700 text-sm">
                    <?php echo htmlspecialchars($insight['message']); ?>
                </p>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($showDashboard && $stats['total_count'] > 0): ?>
<!-- Analytics Charts -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    
    <!-- Category Breakdown -->
    <div class="bg-white rounded-xl shadow-md p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
            <i class="fas fa-chart-pie text-primary-600 mr-2"></i>
            Category Breakdown
        </h3>
        <div class="space-y-4">
            <?php 
            $colorClasses = ['primary', 'green', 'purple', 'orange', 'red', 'indigo', 'pink'];
            $index = 0;
            foreach (array_slice($stats['by_category'], 0, 7) as $category => $amount): 
                $percentage = ($amount / $stats['total_amount']) * 100;
                $colorClass = $colorClasses[$index % count($colorClasses)];
                $index++;
            ?>
                <div>
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($category); ?></span>
                        <span class="text-sm font-bold text-gray-900">৳<?php echo number_format($amount, 0); ?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-<?php echo $colorClass; ?>-600 h-2 rounded-full transition-all" 
                             style="width: <?php echo min($percentage, 100); ?>%"></div>
                    </div>
                    <div class="text-xs text-gray-500 mt-1">
                        <?php echo number_format($percentage, 1); ?>% of total
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Branch Distribution -->
    <div class="bg-white rounded-xl shadow-md p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
            <i class="fas fa-building text-green-600 mr-2"></i>
            Branch Distribution
        </h3>
        <div class="space-y-4">
            <?php 
            $colorClasses = ['green', 'primary', 'purple', 'orange'];
            $index = 0;
            foreach ($stats['by_branch'] as $branch => $amount): 
                $percentage = ($amount / $stats['total_amount']) * 100;
                $colorClass = $colorClasses[$index % count($colorClasses)];
                $index++;
            ?>
                <div>
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($branch); ?></span>
                        <span class="text-sm font-bold text-gray-900">৳<?php echo number_format($amount, 0); ?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-<?php echo $colorClass; ?>-600 h-2 rounded-full transition-all" 
                             style="width: <?php echo min($percentage, 100); ?>%"></div>
                    </div>
                    <div class="text-xs text-gray-500 mt-1">
                        <?php echo number_format($percentage, 1); ?>% of total
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>
<?php endif; ?>

<!-- Filters -->
<div class="bg-white rounded-xl shadow-md mb-6">
    <div class="p-4 border-b border-gray-200 flex justify-between items-center">
        <h3 class="text-lg font-semibold text-gray-900 flex items-center">
            <i class="fas fa-filter text-primary-600 mr-2"></i>
            Advanced Filters
        </h3>
        <button type="button" onclick="document.getElementById('filterForm').classList.toggle('hidden')" 
                class="text-sm text-primary-600 hover:text-primary-800 flex items-center">
            <i class="fas fa-chevron-down mr-1"></i>Toggle Filters
        </button>
    </div>
    
    <form method="GET" id="filterForm" class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
            
            <!-- Date From -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-calendar-alt mr-1 text-primary-500"></i>Date From
                </label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
            </div>

            <!-- Date To -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-calendar-alt mr-1 text-primary-500"></i>Date To
                </label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
            </div>

            <!-- Branch -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-building mr-1 text-primary-500"></i>Branch
                </label>
                <select name="branch_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo $branch->id; ?>" <?php echo $filters['branch_id'] == $branch->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($branch->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Category -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-tags mr-1 text-primary-500"></i>Category
                </label>
                <select name="category_id" id="category_select" onchange="loadSubcategories(this.value)"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category->id; ?>" <?php echo $filters['category_id'] == $category->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category->category_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Subcategory -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-tag mr-1 text-primary-500"></i>Subcategory
                </label>
                <select name="subcategory_id" id="subcategory_select"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                    <option value="">All Subcategories</option>
                    <?php foreach ($subcategories as $subcategory): ?>
                        <option value="<?php echo $subcategory->id; ?>" <?php echo $filters['subcategory_id'] == $subcategory->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($subcategory->subcategory_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Payment Method -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-credit-card mr-1 text-primary-500"></i>Payment Method
                </label>
                <select name="payment_method" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                    <option value="">All Methods</option>
                    <option value="cash" <?php echo $filters['payment_method'] === 'cash' ? 'selected' : ''; ?>>Cash</option>
                    <option value="bank" <?php echo $filters['payment_method'] === 'bank' ? 'selected' : ''; ?>>Bank</option>
                </select>
            </div>

            <!-- Status -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-check-circle mr-1 text-primary-500"></i>Status
                </label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                    <option value="approved" <?php echo $filters['status'] === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="pending" <?php echo $filters['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="rejected" <?php echo $filters['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="draft" <?php echo $filters['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="cancelled" <?php echo $filters['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="all" <?php echo $filters['status'] === 'all' ? 'selected' : ''; ?>>All Status</option>
                </select>
            </div>

            <!-- Search -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-search mr-1 text-primary-500"></i>Search
                </label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>"
                       placeholder="Voucher #, remarks..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
            </div>

            <!-- Quick Filters -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-bolt mr-1 text-primary-500"></i>Quick Filters
                </label>
                <select onchange="applyQuickFilter(this.value)" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                    <option value="">Select...</option>
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="this_week">This Week</option>
                    <option value="this_month">This Month</option>
                    <option value="last_month">Last Month</option>
                    <option value="this_year">This Year</option>
                </select>
            </div>

        </div>

        <!-- Buttons -->
        <div class="mt-6 flex flex-wrap gap-3">
            <button type="submit" class="px-6 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg flex items-center transition-colors">
                <i class="fas fa-search mr-2"></i>Apply Filters
            </button>
            <a href="<?php echo url('expense/expense_history.php'); ?>" 
               class="px-6 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg flex items-center transition-colors">
                <i class="fas fa-redo mr-2"></i>Reset
            </a>
            <button type="button" onclick="exportToExcel()" 
                    class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg flex items-center transition-colors">
                <i class="fas fa-file-excel mr-2"></i>Export Excel
            </button>
            <button type="button" onclick="window.print()" 
                    class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg flex items-center transition-colors">
                <i class="fas fa-print mr-2"></i>Print
            </button>
        </div>
    </form>
</div>

<!-- Expense Table -->
<div class="bg-white rounded-xl shadow-md overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200" id="expenseTable">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Voucher #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Branch</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Handled By</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($expenses)): ?>
                    <tr>
                        <td colspan="9" class="px-6 py-12 text-center text-gray-500">
                            <i class="fas fa-inbox text-6xl text-gray-300 mb-4 block"></i>
                            <p class="text-lg font-medium">No expenses found</p>
                            <p class="text-sm mt-2">Try adjusting your filter criteria</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($expenses as $expense): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="font-semibold text-primary-600">
                                    <?php echo htmlspecialchars($expense->voucher_number); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                <?php echo date('M d, Y', strtotime($expense->expense_date)); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                <?php echo htmlspecialchars($expense->branch_name ?? 'N/A'); ?>
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($expense->category_name); ?></div>
                                <?php if ($expense->subcategory_name): ?>
                                    <div class="text-gray-500 text-xs mt-1"><?php echo htmlspecialchars($expense->subcategory_name); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-700">
                                <?php echo htmlspecialchars($expense->handled_by_person ?? '-'); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <span class="font-bold text-gray-900">৳<?php echo number_format($expense->total_amount, 2); ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="px-2 py-1 bg-gray-100 text-gray-800 rounded text-xs">
                                    <i class="fas fa-<?php echo $expense->payment_method === 'bank' ? 'university' : 'money-bill-wave'; ?> mr-1"></i>
                                    <?php echo ucfirst($expense->payment_method); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php 
                                $statusColors = [
                                    'approved' => 'green',
                                    'pending' => 'yellow',
                                    'rejected' => 'red',
                                    'draft' => 'gray',
                                    'cancelled' => 'gray'
                                ];
                                $statusIcons = [
                                    'approved' => 'check-circle',
                                    'pending' => 'clock',
                                    'rejected' => 'times-circle',
                                    'draft' => 'file',
                                    'cancelled' => 'ban'
                                ];
                                $color = $statusColors[$expense->status] ?? 'gray';
                                $icon = $statusIcons[$expense->status] ?? 'circle';
                                ?>
                                <span class="px-3 py-1 bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-800 rounded-full text-xs font-semibold inline-flex items-center">
                                    <i class="fas fa-<?php echo $icon; ?> mr-1"></i>
                                    <?php echo ucfirst($expense->status); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <div class="flex justify-center gap-2">
                                    <a href="<?php echo url('expense/view_expense_voucher.php?id=' . $expense->id); ?>" 
                                       class="px-3 py-1 bg-primary-100 text-primary-700 rounded hover:bg-primary-200 text-xs transition-colors"
                                       title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <button onclick="printVoucher(<?php echo $expense->id; ?>)"
                                            class="px-3 py-1 bg-purple-100 text-purple-700 rounded hover:bg-purple-200 text-xs transition-colors"
                                            title="Print">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <?php if ($isSuperadmin): ?>
                                        <a href="<?php echo url('expense/edit_expense.php?id=' . $expense->id); ?>" 
                                           class="px-3 py-1 bg-yellow-100 text-yellow-700 rounded hover:bg-yellow-200 text-xs transition-colors"
                                           title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button onclick="confirmDelete(<?php echo $expense->id; ?>, '<?php echo htmlspecialchars($expense->voucher_number); ?>')"
                                                class="px-3 py-1 bg-red-100 text-red-700 rounded hover:bg-red-200 text-xs transition-colors"
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
            <tfoot class="bg-gray-50">
                <tr>
                    <td colspan="5" class="px-6 py-3 text-right font-bold text-gray-900">Total:</td>
                    <td class="px-6 py-3 text-right font-bold text-gray-900">
                        ৳<?php echo number_format($stats['total_amount'], 2); ?>
                    </td>
                    <td colspan="3" class="px-6 py-3 text-sm text-gray-600">
                        <?php echo $stats['total_count']; ?> vouchers
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
        <div class="p-6">
            <div class="flex items-center mb-4">
                <div class="flex-shrink-0 bg-red-100 rounded-full p-3 mr-3">
                    <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-gray-900">Confirm Delete</h3>
                    <p class="text-gray-600 text-sm">This action cannot be undone</p>
                </div>
            </div>
            
            <p class="text-gray-700 mb-4">
                Delete expense voucher <strong id="deleteVoucherNumber"></strong>?
            </p>
            
            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-3 mb-4">
                <p class="text-sm text-yellow-800">
                    <i class="fas fa-info-circle mr-1"></i>
                    This will delete all related journal entries and accounting records.
                </p>
            </div>

            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="voucher_id" id="deleteVoucherId">
                
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="hideDeleteModal()"
                            class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                        <i class="fas fa-trash mr-2"></i>Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDelete(voucherId, voucherNumber) {
    document.getElementById('deleteVoucherId').value = voucherId;
    document.getElementById('deleteVoucherNumber').textContent = voucherNumber;
    document.getElementById('deleteModal').classList.remove('hidden');
    document.getElementById('deleteModal').classList.add('flex');
}

function hideDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('flex');
}

function printVoucher(expenseId) {
    window.open('<?php echo url('expense/print_expense_voucher.php?id='); ?>' + expenseId, '_blank');
}

function loadSubcategories(categoryId) {
    const select = document.getElementById('subcategory_select');
    select.innerHTML = '<option value="">Loading...</option>';
    
    if (!categoryId) {
        select.innerHTML = '<option value="">All Subcategories</option>';
        return;
    }
    
    fetch(`<?php echo url('expense/ajax_get_subcategories.php?category_id='); ?>` + categoryId)
        .then(r => r.json())
        .then(data => {
            select.innerHTML = '<option value="">All Subcategories</option>';
            data.forEach(s => {
                select.innerHTML += `<option value="${s.id}">${s.subcategory_name}</option>`;
            });
        })
        .catch(() => {
            select.innerHTML = '<option value="">Error loading</option>';
        });
}

function applyQuickFilter(filter) {
    if (!filter) return;
    
    const form = document.getElementById('filterForm').querySelector('form') || document.getElementById('filterForm').parentElement;
    const dateFrom = form.querySelector('[name="date_from"]');
    const dateTo = form.querySelector('[name="date_to"]');
    
    const today = new Date();
    let from, to;
    
    switch(filter) {
        case 'today':
            from = to = today.toISOString().split('T')[0];
            break;
        case 'yesterday':
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            from = to = yesterday.toISOString().split('T')[0];
            break;
        case 'this_week':
            const startOfWeek = new Date(today);
            startOfWeek.setDate(today.getDate() - today.getDay());
            from = startOfWeek.toISOString().split('T')[0];
            to = today.toISOString().split('T')[0];
            break;
        case 'this_month':
            from = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
            to = today.toISOString().split('T')[0];
            break;
        case 'last_month':
            const lastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            from = lastMonth.toISOString().split('T')[0];
            to = new Date(today.getFullYear(), today.getMonth(), 0).toISOString().split('T')[0];
            break;
        case 'this_year':
            from = new Date(today.getFullYear(), 0, 1).toISOString().split('T')[0];
            to = today.toISOString().split('T')[0];
            break;
    }
    
    if (from && to) {
        dateFrom.value = from;
        dateTo.value = to;
        form.submit();
    }
}

function exportToExcel() {
    const table = document.getElementById('expenseTable');
    let csv = [];
    
    const headers = [];
    table.querySelectorAll('thead th').forEach(th => {
        if (th.textContent.trim() !== 'Actions') {
            headers.push(th.textContent.trim());
        }
    });
    csv.push(headers.join(','));
    
    table.querySelectorAll('tbody tr').forEach(tr => {
        if (tr.querySelector('td[colspan]')) return;
        
        const row = [];
        const cells = tr.querySelectorAll('td');
        for (let i = 0; i < cells.length - 1; i++) {
            let text = cells[i].textContent.trim().replace(/[\n\r]+/g, ' ').replace(/"/g, '""');
            row.push('"' + text + '"');
        }
        if (row.length > 0) {
            csv.push(row.join(','));
        }
    });
    
    const csvContent = "data:text/csv;charset=utf-8," + csv.join("\n");
    const link = document.createElement("a");
    link.setAttribute("href", encodeURI(csvContent));
    link.setAttribute("download", "expense_history_<?php echo date('Y-m-d'); ?>.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') hideDeleteModal();
});

document.getElementById('deleteModal').addEventListener('click', e => {
    if (e.target === e.currentTarget) hideDeleteModal();
});
</script>

<?php require_once '../templates/footer.php'; ?>
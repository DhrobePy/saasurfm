<?php
/**
 * Expense Module Dashboard
 * Landing page for Expense Initiator and Expense Approver roles
 */

require_once '../core/init.php';

// Check permission - only expense roles can access
if (!canAccessExpense()) {
    header('Location: ' . url('unauthorized.php'));
    exit();
}

// Get PDO connection
$pdo = Database::getInstance()->getPdo();

// Get current user info
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];
$userName = $_SESSION['user_display_name'] ?? 'User';

// Get statistics based on role
$stats = [];

// Total expenses this month
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total
    FROM expense_vouchers
    WHERE MONTH(expense_date) = MONTH(CURRENT_DATE())
    AND YEAR(expense_date) = YEAR(CURRENT_DATE())
    AND status = 'approved'
");
$stmt->execute();
$monthlyStats = $stmt->fetch(PDO::FETCH_OBJ);

// Pending approvals (if user can approve)
$pendingCount = 0;
$pendingAmount = 0;
if (canApproveExpense()) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total
        FROM expense_vouchers
        WHERE status = 'pending'
    ");
    $stmt->execute();
    $pending = $stmt->fetch(PDO::FETCH_OBJ);
    $pendingCount = $pending->count;
    $pendingAmount = $pending->total;
}

// User's own expenses
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total
    FROM expense_vouchers
    WHERE created_by_user_id = :user_id
    AND MONTH(created_at) = MONTH(CURRENT_DATE())
    AND YEAR(created_at) = YEAR(CURRENT_DATE())
");
$stmt->execute(['user_id' => $userId]);
$myStats = $stmt->fetch(PDO::FETCH_OBJ);

// Recent expenses (last 10)
$stmt = $pdo->prepare("
    SELECT 
        ev.*,
        ec.category_name,
        b.name as branch_name,
        u.display_name as created_by_name
    FROM expense_vouchers ev
    LEFT JOIN expense_categories ec ON ev.category_id = ec.id
    LEFT JOIN branches b ON ev.branch_id = b.id
    LEFT JOIN users u ON ev.created_by_user_id = u.id
    WHERE ev.created_by_user_id = :user_id
    ORDER BY ev.created_at DESC
    LIMIT 10
");
$stmt->execute(['user_id' => $userId]);
$recentExpenses = $stmt->fetchAll(PDO::FETCH_OBJ);

$pageTitle = 'Expense Management';
include  '../templates/header.php';
?>

<div class="container mx-auto px-4 py-6">
    
    <!-- Welcome Header -->
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-800">
            <i class="fas fa-receipt text-primary-600"></i> Expense Management
        </h1>
        <p class="text-gray-600 mt-2">
            Welcome back, <strong><?= htmlspecialchars($userName) ?></strong>
            <span class="text-sm text-gray-500">(<?= htmlspecialchars($userRole) ?>)</span>
        </p>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        
        <!-- Monthly Expenses Card -->
        <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-blue-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 mb-1">This Month (Approved)</p>
                    <h3 class="text-2xl font-bold text-gray-800">
                        ৳ <?= number_format($monthlyStats->total, 2) ?>
                    </h3>
                    <p class="text-xs text-gray-500 mt-1">
                        <?= $monthlyStats->count ?> vouchers
                    </p>
                </div>
                <div class="bg-blue-100 rounded-full p-4">
                    <i class="fas fa-calendar-check text-blue-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Pending Approvals Card (if authorized) -->
        <?php if (canApproveExpense()): ?>
        <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-yellow-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 mb-1">Pending Approval</p>
                    <h3 class="text-2xl font-bold text-gray-800">
                        ৳ <?= number_format($pendingAmount, 2) ?>
                    </h3>
                    <p class="text-xs text-gray-500 mt-1">
                        <?= $pendingCount ?> vouchers waiting
                    </p>
                </div>
                <div class="bg-yellow-100 rounded-full p-4">
                    <i class="fas fa-clock text-yellow-600 text-2xl"></i>
                </div>
            </div>
            <div class="mt-3">
                <a href="<?= url('expense/approve_expense.php') ?>" class="text-sm text-yellow-600 hover:text-yellow-700 font-medium">
                    Review Now <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- My Expenses Card -->
        <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-green-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 mb-1">My Expenses (This Month)</p>
                    <h3 class="text-2xl font-bold text-gray-800">
                        ৳ <?= number_format($myStats->total, 2) ?>
                    </h3>
                    <p class="text-xs text-gray-500 mt-1">
                        <?= $myStats->count ?> vouchers created
                    </p>
                </div>
                <div class="bg-green-100 rounded-full p-4">
                    <i class="fas fa-user text-green-600 text-2xl"></i>
                </div>
            </div>
        </div>

    </div>

    <!-- Quick Actions -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">
            <i class="fas fa-bolt text-yellow-500"></i> Quick Actions
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            
            <a href="<?= url('expense/create_expense.php') ?>" class="flex items-center p-4 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg hover:from-blue-600 hover:to-blue-700 transition shadow-md">
                <div class="bg-white bg-opacity-20 rounded-full p-3 mr-4">
                    <i class="fas fa-plus-circle text-2xl"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-lg">Create Expense</h3>
                    <p class="text-sm text-blue-100">New voucher</p>
                </div>
            </a>

            <?php if (canAccessApproveExpense()): ?>
            <a href="<?= url('expense/approve_expense.php') ?>" class="flex items-center p-4 bg-gradient-to-r from-yellow-500 to-yellow-600 text-white rounded-lg hover:from-yellow-600 hover:to-yellow-700 transition shadow-md">
                <div class="bg-white bg-opacity-20 rounded-full p-3 mr-4">
                    <i class="fas fa-check-circle text-2xl"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-lg">Approve Expenses</h3>
                    <p class="text-sm text-yellow-100">
                        <?= $pendingCount ?> pending
                    </p>
                </div>
            </a>
            <?php endif; ?>

            <?php if (canAccessExpenseHistory()): ?>
            <a href="<?= url('expense/expense_history.php') ?>" class="flex items-center p-4 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg hover:from-green-600 hover:to-green-700 transition shadow-md">
                <div class="bg-white bg-opacity-20 rounded-full p-3 mr-4">
                    <i class="fas fa-history text-2xl"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-lg">Expense History</h3>
                    <p class="text-sm text-green-100">View all expenses</p>
                </div>
            </a>
            <?php endif; ?>

        </div>
    </div>

    <!-- Recent Expenses -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b">
            <h2 class="text-xl font-semibold text-gray-800">
                <i class="fas fa-list"></i> My Recent Expenses
            </h2>
        </div>
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
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($recentExpenses)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                <i class="fas fa-inbox text-4xl text-gray-300 mb-2"></i>
                                <p>No expenses created yet</p>
                                <a href="<?= url('expense/create_expense.php') ?>" class="text-blue-600 hover:text-blue-700 text-sm mt-2 inline-block">
                                    Create your first expense <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentExpenses as $expense): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($expense->voucher_number) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?= date('M d, Y', strtotime($expense->expense_date)) ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <?= htmlspecialchars($expense->category_name) ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <?= htmlspecialchars($expense->branch_name) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <span class="text-sm font-semibold text-gray-900">
                                        ৳ <?= number_format($expense->total_amount, 2) ?>
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
                                    $color = $statusColors[$expense->status] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $color ?>">
                                        <?= ucfirst($expense->status) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty($recentExpenses)): ?>
        <div class="px-6 py-4 bg-gray-50 border-t text-center">
            <a href="<?= url('expense/expense_history.php') ?>" class="text-sm text-blue-600 hover:text-blue-700 font-medium">
                View All Expenses <i class="fas fa-arrow-right ml-1"></i>
            </a>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once '../templates/footer.php'; ?>
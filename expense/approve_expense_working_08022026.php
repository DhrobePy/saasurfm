<?php
/**
 * Approve/Reject Expense Vouchers
 * For Superadmin and Expense Approver only
 */

require_once '../core/init.php';
require_once '../core/classes/ExpenseManager.php';

global $db;

// Check permission - only Superadmin and Expense Approver
if (!canApproveExpense()) {
    header('Location: ' . url('unauthorized.php'));
    exit();
}

// Get Database instance
$dbInstance = Database::getInstance();
$expenseManager = new ExpenseManager($dbInstance);

// =============================================
// HANDLE APPROVAL/REJECTION
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $voucher_id = (int)($_POST['voucher_id'] ?? 0);
    
    if ($action === 'approve' && $voucher_id) {
        $approver_id = $_SESSION['user_id'] ?? null;
        if (!$approver_id) {
            $_SESSION['error_flash'] = 'Session expired. Please login again.';
        } else {
            $result = $expenseManager->approveExpenseVoucher($voucher_id, $approver_id);
            if ($result['success']) {
                $_SESSION['success_flash'] = $result['message'];
            } else {
                $_SESSION['error_flash'] = $result['message'];
            }
        }
    } elseif ($action === 'reject' && $voucher_id) {
        $rejection_reason = $_POST['rejection_reason'] ?? 'No reason provided';
        $approver_id = $_SESSION['user_id'] ?? null;
        if (!$approver_id) {
            $_SESSION['error_flash'] = 'Session expired. Please login again.';
        } else {
            $result = $expenseManager->rejectExpenseVoucher($voucher_id, $rejection_reason, $approver_id);
            if ($result['success']) {
                $_SESSION['success_flash'] = $result['message'];
            } else {
                $_SESSION['error_flash'] = $result['message'];
            }
        }
    }
    
    //header('Location: ' . url('expense/expense_voucher_list.php'));
    header('Location: ' . url('expense/approve_expense.php'));
    exit();
}

// =============================================
// GET PENDING VOUCHERS
// =============================================
$filters = [
    'branch_id' => $_GET['branch_id'] ?? '',
    'category_id' => $_GET['category_id'] ?? '',
    'date_from' => $_GET['date_from'] ?? date('Y-m-01'),
    'date_to' => $_GET['date_to'] ?? date('Y-m-d')
];

$sql = "SELECT ev.*, 
               ec.category_name,
               es.subcategory_name,
               b.name as branch_name,
               u.display_name as created_by_name
        FROM expense_vouchers ev
        LEFT JOIN expense_categories ec ON ev.category_id = ec.id
        LEFT JOIN expense_subcategories es ON ev.subcategory_id = es.id
        LEFT JOIN branches b ON ev.branch_id = b.id
        LEFT JOIN users u ON ev.created_by_user_id = u.id
        WHERE ev.status = 'pending'";

$params = [];

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

$sql .= " ORDER BY ev.created_at ASC";

$pending_vouchers = $db->query($sql, $params)->results();

// Get dropdown data
$categories = $expenseManager->getAllCategories();
$branches = $expenseManager->getAllBranches();

$pageTitle = "Approve Expenses";
require_once '../templates/header.php';
?>

<div class="container mx-auto px-4 py-6">
    
    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">
            <i class="fas fa-check-circle text-green-600"></i> Approve Expense Vouchers
        </h1>
        <p class="text-gray-600 mt-2">Review and approve pending expense vouchers</p>
    </div>

    <?php echo display_message(); ?>

    <!-- Pending Count Alert -->
    <?php if (count($pending_vouchers) > 0): ?>
    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
        <div class="flex items-center">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-triangle text-yellow-400 text-2xl"></i>
            </div>
            <div class="ml-3">
                <p class="text-sm text-yellow-700">
                    <strong><?php echo count($pending_vouchers); ?> voucher(s)</strong> awaiting your approval
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Branch</label>
                <select name="branch_id" class="w-full px-3 py-2 border border-gray-300 rounded-md">
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
                <select name="category_id" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category->id; ?>" <?php echo $filters['category_id'] == $category->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category->category_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="md:col-span-4">
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                    <i class="fas fa-filter mr-2"></i>Apply Filters
                </button>
                <a href="<?php echo url('expense/approve_expense.php'); ?>" 
                   class="ml-2 px-6 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                    <i class="fas fa-redo mr-2"></i>Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Pending Vouchers -->
    <?php if (empty($pending_vouchers)): ?>
        <div class="bg-white rounded-lg shadow-md p-12 text-center">
            <i class="fas fa-check-circle text-6xl text-green-300 mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-700 mb-2">All Caught Up!</h3>
            <p class="text-gray-500">No pending expense vouchers to approve</p>
        </div>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($pending_vouchers as $voucher): ?>
                <div class="bg-white rounded-lg shadow-md overflow-hidden border-l-4 border-yellow-400">
                    <div class="p-6">
                        <div class="flex justify-between items-start">
                            
                            <!-- Voucher Details -->
                            <div class="flex-1">
                                <div class="flex items-center mb-3">
                                    <h3 class="text-xl font-bold text-gray-900 mr-3">
                                        <?php echo htmlspecialchars($voucher->voucher_number); ?>
                                    </h3>
                                    <span class="px-3 py-1 bg-yellow-100 text-yellow-800 text-xs font-semibold rounded-full">
                                        PENDING
                                    </span>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                    <div>
                                        <p class="text-sm text-gray-500">Expense Date</p>
                                        <p class="font-semibold"><?php echo date('M d, Y', strtotime($voucher->expense_date)); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-500">Branch</p>
                                        <p class="font-semibold"><?php echo htmlspecialchars($voucher->branch_name); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-500">Created By</p>
                                        <p class="font-semibold"><?php echo htmlspecialchars($voucher->created_by_name); ?></p>
                                        <p class="text-xs text-gray-500"><?php echo date('M d, h:i A', strtotime($voucher->created_at)); ?></p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                    <div>
                                        <p class="text-sm text-gray-500">Category / Subcategory</p>
                                        <p class="font-semibold"><?php echo htmlspecialchars($voucher->category_name); ?> / <?php echo htmlspecialchars($voucher->subcategory_name); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-500">Amount</p>
                                        <p class="text-2xl font-bold text-green-600">৳ <?php echo number_format($voucher->total_amount, 2); ?></p>
                                    </div>
                                </div>

                                <?php if ($voucher->remarks): ?>
                                <div class="bg-gray-50 rounded p-3 mb-4">
                                    <p class="text-sm text-gray-500 mb-1">Remarks:</p>
                                    <p class="text-gray-700"><?php echo nl2br(htmlspecialchars($voucher->remarks)); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Action Buttons -->
                            <div class="ml-6 flex flex-col space-y-2">
                                <!-- View Details -->
                                <a href="<?php echo url('expense/view_expense_voucher.php?id=' . $voucher->id); ?>" 
                                   class="px-4 py-2 bg-blue-100 text-blue-700 rounded-md hover:bg-blue-200 text-center">
                                    <i class="fas fa-eye mr-2"></i>View Details
                                </a>

                                <!-- Approve Button -->
                                <form method="POST" onsubmit="return confirm('Approve this voucher?');" class="inline">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="voucher_id" value="<?php echo $voucher->id; ?>">
                                    <button type="submit" class="w-full px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                                        <i class="fas fa-check mr-2"></i>Approve
                                    </button>
                                </form>

                                <!-- Reject Button -->
                                <button onclick="showRejectModal(<?php echo $voucher->id; ?>, '<?php echo htmlspecialchars($voucher->voucher_number); ?>')" 
                                        class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">
                                    <i class="fas fa-times mr-2"></i>Reject
                                </button>
                            </div>

                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Reject Modal -->
<div id="rejectModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4">
        <h3 class="text-xl font-bold text-gray-900 mb-4">
            <i class="fas fa-times-circle text-red-600 mr-2"></i>Reject Expense Voucher
        </h3>
        
        <p class="text-gray-600 mb-4">
            Rejecting voucher: <strong id="rejectVoucherNumber"></strong>
        </p>

        <form method="POST" id="rejectForm">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="voucher_id" id="rejectVoucherId">
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Reason for Rejection <span class="text-red-500">*</span>
                </label>
                <textarea name="rejection_reason" required rows="4"
                          placeholder="Please provide a reason for rejecting this voucher..."
                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-red-500 focus:border-red-500"></textarea>
            </div>

            <div class="flex justify-end space-x-3">
                <button type="button" onclick="hideRejectModal()"
                        class="px-6 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">
                    <i class="fas fa-times mr-2"></i>Reject Voucher
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showRejectModal(voucherId, voucherNumber) {
    document.getElementById('rejectVoucherId').value = voucherId;
    document.getElementById('rejectVoucherNumber').textContent = voucherNumber;
    document.getElementById('rejectModal').classList.remove('hidden');
    document.getElementById('rejectModal').classList.add('flex');
}

function hideRejectModal() {
    document.getElementById('rejectModal').classList.add('hidden');
    document.getElementById('rejectModal').classList.remove('flex');
    document.getElementById('rejectForm').reset();
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideRejectModal();
    }
});

// Close modal on background click
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideRejectModal();
    }
});
</script>

<?php require_once '../templates/footer.php'; ?>
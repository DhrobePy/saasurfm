<?php
require_once '../core/init.php';

global $db;

restrict_access(['Superadmin', 'admin', 'Accounts']);

require_once '../core/classes/ExpenseManager.php';

$currentUser = getCurrentUser();
$expenseManager = new ExpenseManager($db, $currentUser['id']);

$voucher_id = $_GET['id'] ?? 0;
$voucher = $expenseManager->getExpenseVoucherById($voucher_id);

if (!$voucher) {
    echo '<div class="text-center text-red-500 py-8">Voucher not found</div>';
    exit;
}
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="text-center pb-4 border-b border-gray-200">
        <h2 class="text-2xl font-bold text-gray-900">EXPENSE VOUCHER</h2>
        <p class="text-lg text-primary-600 font-semibold mt-2"><?php echo htmlspecialchars($voucher->voucher_number); ?></p>
    </div>

    <!-- Basic Details -->
    <div class="grid grid-cols-2 gap-4">
        <div>
            <p class="text-sm text-gray-600">Expense Date</p>
            <p class="font-semibold text-gray-900"><?php echo date('d M Y', strtotime($voucher->expense_date)); ?></p>
        </div>
        <div>
            <p class="text-sm text-gray-600">Created By</p>
            <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($voucher->created_by_name); ?></p>
        </div>
        <?php if ($voucher->branch_name): ?>
        <div>
            <p class="text-sm text-gray-600">Branch</p>
            <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($voucher->branch_name); ?></p>
        </div>
        <?php endif; ?>
        <div>
            <p class="text-sm text-gray-600">Status</p>
            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full 
                <?php echo $voucher->status === 'approved' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                <?php echo ucfirst($voucher->status); ?>
            </span>
        </div>
    </div>

    <!-- Expense Details -->
    <div class="border-t pt-4">
        <h3 class="font-semibold text-gray-900 mb-3">Expense Details</h3>
        <div class="bg-gray-50 rounded-lg p-4 space-y-3">
            <div class="flex justify-between">
                <span class="text-gray-600">Category:</span>
                <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($voucher->category_name); ?></span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-600">Subcategory:</span>
                <span class="font-semibold text-gray-900">
                    <?php echo htmlspecialchars($voucher->subcategory_name); ?>
                    <?php if ($voucher->unit_of_measurement): ?>
                        <span class="text-xs text-gray-500">(<?php echo htmlspecialchars($voucher->unit_of_measurement); ?>)</span>
                    <?php endif; ?>
                </span>
            </div>
            
            <?php if ($voucher->unit_quantity && $voucher->per_unit_cost): ?>
            <div class="border-t pt-2">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-600">Quantity:</span>
                    <span class="text-gray-900"><?php echo number_format($voucher->unit_quantity, 4); ?> <?php echo htmlspecialchars($voucher->unit_of_measurement ?? ''); ?></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-600">Per Unit Cost:</span>
                    <span class="text-gray-900">৳<?php echo number_format($voucher->per_unit_cost, 2); ?></span>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="flex justify-between border-t pt-2">
                <span class="font-semibold text-gray-900">Total Amount:</span>
                <span class="text-2xl font-bold text-primary-600">৳<?php echo number_format($voucher->total_amount, 2); ?></span>
            </div>
        </div>
    </div>

    <!-- Payment Details -->
    <div class="border-t pt-4">
        <h3 class="font-semibold text-gray-900 mb-3">Payment Details</h3>
        <div class="bg-gray-50 rounded-lg p-4 space-y-3">
            <div class="flex justify-between">
                <span class="text-gray-600">Payment Method:</span>
                <span class="font-semibold text-gray-900">
                    <i class="fas fa-<?php echo $voucher->payment_method === 'bank' ? 'university' : 'money-bill-wave'; ?> mr-1"></i>
                    <?php echo ucfirst($voucher->payment_method); ?>
                </span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-600">Account:</span>
                <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($voucher->payment_account_name ?? '-'); ?></span>
            </div>
            <?php if ($voucher->payment_reference): ?>
            <div class="flex justify-between">
                <span class="text-gray-600">Reference:</span>
                <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($voucher->payment_reference); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Handler Details -->
    <?php if ($voucher->handled_by_person || $voucher->employee_name): ?>
    <div class="border-t pt-4">
        <h3 class="font-semibold text-gray-900 mb-3">Handler Information</h3>
        <div class="bg-gray-50 rounded-lg p-4 space-y-3">
            <?php if ($voucher->handled_by_person): ?>
            <div class="flex justify-between">
                <span class="text-gray-600">Handled By:</span>
                <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($voucher->handled_by_person); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($voucher->employee_name): ?>
            <div class="flex justify-between">
                <span class="text-gray-600">Employee:</span>
                <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($voucher->employee_name); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Remarks -->
    <?php if ($voucher->remarks): ?>
    <div class="border-t pt-4">
        <h3 class="font-semibold text-gray-900 mb-2">Remarks</h3>
        <div class="bg-gray-50 rounded-lg p-4">
            <p class="text-gray-700"><?php echo nl2br(htmlspecialchars($voucher->remarks)); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Journal Entry Info -->
    <?php if ($voucher->journal_entry_id): ?>
    <div class="border-t pt-4">
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 flex items-start">
            <i class="fas fa-book text-blue-600 mt-1 mr-2"></i>
            <div class="text-sm text-blue-800">
                <p class="font-medium">Accounting Entry Created</p>
                <p class="text-xs">Journal Entry ID: #<?php echo $voucher->journal_entry_id; ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Audit Trail -->
    <div class="border-t pt-4">
        <h3 class="font-semibold text-gray-900 mb-2">Audit Trail</h3>
        <div class="text-xs text-gray-600 space-y-1">
            <p><i class="fas fa-clock mr-1"></i> Created: <?php echo date('d M Y H:i', strtotime($voucher->created_at)); ?></p>
            <p><i class="fas fa-sync mr-1"></i> Updated: <?php echo date('d M Y H:i', strtotime($voucher->updated_at)); ?></p>
            <?php if ($voucher->approved_at): ?>
            <p><i class="fas fa-check mr-1"></i> Approved: <?php echo date('d M Y H:i', strtotime($voucher->approved_at)); ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
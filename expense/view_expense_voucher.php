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
    die('<div style="text-align:center; padding:50px; font-family:Arial;">
         <h2>Voucher Not Found</h2>
         <button onclick="window.close()" style="padding:10px 20px; background:#3b82f6; color:white; border:none; border-radius:5px; cursor:pointer;">Close</button>
         </div>');
}

$pageTitle = "Expense Voucher Details";
require_once '../templates/header.php';
?>

<div class="container mx-auto px-4 py-6 max-w-4xl">
    <!-- Page Header -->
    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Expense Voucher Details</h1>
            <p class="text-gray-600 mt-1"><?php echo htmlspecialchars($voucher->voucher_number); ?></p>
        </div>
        <div class="flex space-x-3">
            <a href="print_expense_voucher.php?id=<?php echo $voucher_id; ?>" target="_blank"
               class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-print mr-2"></i>
                Print Voucher
            </a>
            <button onclick="window.close()" 
                    class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg">
                <i class="fas fa-times mr-2"></i>
                Close
            </button>
        </div>
    </div>

    <!-- Voucher Details Card -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        
        <!-- Header Section -->
        <div class="bg-gradient-to-r from-primary-600 to-primary-700 px-6 py-4">
            <div class="grid grid-cols-2 gap-4 text-white">
                <div>
                    <p class="text-sm opacity-90">Voucher Number</p>
                    <p class="text-2xl font-bold"><?php echo htmlspecialchars($voucher->voucher_number); ?></p>
                </div>
                <div class="text-right">
                    <p class="text-sm opacity-90">Expense Date</p>
                    <p class="text-xl font-semibold"><?php echo date('d M Y', strtotime($voucher->expense_date)); ?></p>
                </div>
            </div>
        </div>

        <!-- Status and Branch -->
        <div class="px-6 py-4 bg-gray-50 border-b">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-gray-600">Status</p>
                    <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full mt-1
                        <?php echo $voucher->status === 'approved' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                        <?php echo ucfirst($voucher->status); ?>
                    </span>
                </div>
                <?php if ($voucher->branch_name): ?>
                <div>
                    <p class="text-sm text-gray-600">Branch</p>
                    <p class="font-semibold text-gray-900 mt-1"><?php echo htmlspecialchars($voucher->branch_name); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Amount Section -->
        <div class="px-6 py-6 bg-yellow-50 border-b">
            <div class="text-center">
                <p class="text-sm text-gray-600 mb-2">Total Expense Amount</p>
                <p class="text-5xl font-bold text-gray-900">৳<?php echo number_format($voucher->total_amount, 2); ?></p>
            </div>
        </div>

        <!-- Expense Details -->
        <div class="px-6 py-6 border-b">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Expense Details</h3>
            
            <div class="grid grid-cols-2 gap-6">
                <div>
                    <p class="text-sm text-gray-600">Category</p>
                    <p class="font-semibold text-gray-900 mt-1"><?php echo htmlspecialchars($voucher->category_name); ?></p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Subcategory</p>
                    <p class="font-semibold text-gray-900 mt-1">
                        <?php echo htmlspecialchars($voucher->subcategory_name); ?>
                        <?php if ($voucher->unit_of_measurement): ?>
                            <span class="text-xs text-gray-500">(<?php echo htmlspecialchars($voucher->unit_of_measurement); ?>)</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            
            <?php if ($voucher->unit_quantity && $voucher->per_unit_cost): ?>
            <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
                <h4 class="text-sm font-semibold text-blue-900 mb-3">Calculation Breakdown</h4>
                <div class="grid grid-cols-3 gap-4 text-center">
                    <div>
                        <p class="text-xs text-gray-600">Quantity</p>
                        <p class="text-lg font-bold text-gray-900"><?php echo number_format($voucher->unit_quantity, 2); ?> <?php echo htmlspecialchars($voucher->unit_of_measurement ?? ''); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-600">Per Unit Cost</p>
                        <p class="text-lg font-bold text-gray-900">৳<?php echo number_format($voucher->per_unit_cost, 2); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-600">Total</p>
                        <p class="text-lg font-bold text-primary-600">৳<?php echo number_format($voucher->total_amount, 2); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Payment Details -->
        <div class="px-6 py-6 border-b">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Payment Details</h3>
            
            <div class="grid grid-cols-2 gap-6">
                <div>
                    <p class="text-sm text-gray-600">Payment Method</p>
                    <p class="font-semibold text-gray-900 mt-1">
                        <i class="fas fa-<?php echo $voucher->payment_method === 'bank' ? 'university' : 'money-bill-wave'; ?> mr-2"></i>
                        <?php echo ucfirst($voucher->payment_method); ?>
                    </p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Payment Account</p>
                    <p class="font-semibold text-gray-900 mt-1"><?php echo htmlspecialchars($voucher->payment_account_name ?? '-'); ?></p>
                </div>
                
                <?php if ($voucher->payment_reference): ?>
                <div>
                    <p class="text-sm text-gray-600">Payment Reference</p>
                    <p class="font-semibold text-gray-900 mt-1"><?php echo htmlspecialchars($voucher->payment_reference); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Handler Details -->
        <?php if ($voucher->handled_by_person || $voucher->employee_name): ?>
        <div class="px-6 py-6 border-b">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Handler Information</h3>
            
            <div class="grid grid-cols-2 gap-6">
                <?php if ($voucher->handled_by_person): ?>
                <div>
                    <p class="text-sm text-gray-600">Handled By</p>
                    <p class="font-semibold text-gray-900 mt-1"><?php echo htmlspecialchars($voucher->handled_by_person); ?></p>
                </div>
                <?php endif; ?>
                
                <?php if ($voucher->employee_name): ?>
                <div>
                    <p class="text-sm text-gray-600">Employee</p>
                    <p class="font-semibold text-gray-900 mt-1"><?php echo htmlspecialchars($voucher->employee_name); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Remarks -->
        <?php if ($voucher->remarks): ?>
        <div class="px-6 py-6 border-b">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Remarks</h3>
            <div class="bg-gray-50 rounded-lg p-4">
                <p class="text-gray-700"><?php echo nl2br(htmlspecialchars($voucher->remarks)); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Accounting Info -->
        <?php if ($voucher->journal_entry_id): ?>
        <div class="px-6 py-4 bg-blue-50">
            <div class="flex items-center text-sm text-blue-800">
                <i class="fas fa-book mr-2"></i>
                <span>Accounting Entry Created - Journal Entry ID: <strong>#<?php echo $voucher->journal_entry_id; ?></strong></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Audit Trail -->
        <div class="px-6 py-4 bg-gray-50">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">Audit Trail</h3>
            <div class="text-xs text-gray-600 space-y-1">
                <p><i class="fas fa-user mr-2"></i> Created by: <?php echo htmlspecialchars($voucher->created_by_name ?? '-'); ?></p>
                <p><i class="fas fa-clock mr-2"></i> Created at: <?php echo date('d M Y H:i', strtotime($voucher->created_at)); ?></p>
                <p><i class="fas fa-sync mr-2"></i> Last updated: <?php echo date('d M Y H:i', strtotime($voucher->updated_at)); ?></p>
                <?php if ($voucher->approved_at): ?>
                <p><i class="fas fa-check mr-2"></i> Approved at: <?php echo date('d M Y H:i', strtotime($voucher->approved_at)); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Actions Footer -->
    <div class="mt-6 flex justify-between">
        <button onclick="window.close()" 
                class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-6 py-2 rounded-lg">
            Close Window
        </button>
        <a href="print_expense_voucher.php?id=<?php echo $voucher_id; ?>" target="_blank"
           class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-lg flex items-center">
            <i class="fas fa-print mr-2"></i>
            Print Voucher
        </a>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>
<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'View Debit Voucher';

// Get voucher ID
$voucher_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($voucher_id <= 0) {
    $_SESSION['error_flash'] = "Invalid voucher ID";
    header('Location: expense_history.php');
    exit();
}

// Get voucher details with all related information
$voucher = $db->query(
    "SELECT dv.*, 
            ea.name as expense_account_name,
            ea.account_number as expense_account_number,
            ea.account_type as expense_account_type,
            pa.name as payment_account_name,
            pa.account_number as payment_account_number,
            pa.account_type as payment_account_type,
            u.display_name as created_by_name,
            u.email as created_by_email,
            b.name as branch_name,
            b.code as branch_code,
            CONCAT(e.first_name, ' ', e.last_name) as employee_name,
            e.email as employee_email,
            e.phone as employee_phone
     FROM debit_vouchers dv
     LEFT JOIN chart_of_accounts ea ON dv.expense_account_id = ea.id
     LEFT JOIN chart_of_accounts pa ON dv.payment_account_id = pa.id
     LEFT JOIN users u ON dv.created_by_user_id = u.id
     LEFT JOIN branches b ON dv.branch_id = b.id
     LEFT JOIN employees e ON dv.employee_id = e.id
     WHERE dv.id = ?",
    [$voucher_id]
)->first();

if (!$voucher) {
    $_SESSION['error_flash'] = "Voucher not found";
    header('Location: expense_history.php');
    exit();
}

// Get journal entry and transaction lines
$journal_entry = null;
$transaction_lines = [];

if ($voucher->journal_entry_id) {
    $journal_entry = $db->query(
        "SELECT * FROM journal_entries WHERE id = ?",
        [$voucher->journal_entry_id]
    )->first();
    
    if ($journal_entry) {
        $transaction_lines = $db->query(
            "SELECT tl.*, 
                    coa.name as account_name,
                    coa.account_number,
                    coa.account_type
             FROM transaction_lines tl
             JOIN chart_of_accounts coa ON tl.account_id = coa.id
             WHERE tl.journal_entry_id = ?
             ORDER BY tl.debit_amount DESC",
            [$journal_entry->id]
        )->results();
    }
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Header -->
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
        <p class="text-lg text-gray-600 mt-1">Voucher #<?php echo htmlspecialchars($voucher->voucher_number); ?></p>
    </div>
    <div class="flex gap-3">
        <a href="expense_history.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
            <i class="fas fa-arrow-left mr-2"></i>Back to List
        </a>
        <a href="debit_voucher_print.php?id=<?php echo $voucher->id; ?>" target="_blank" 
           class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors shadow-md">
            <i class="fas fa-print mr-2"></i>Print Voucher
        </a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

<!-- Main Details -->
<div class="lg:col-span-2 space-y-6">

    <!-- Voucher Information -->
    <div class="bg-white rounded-lg shadow-lg overflow-hidden">
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-4">
            <h2 class="text-xl font-bold text-white">
                <i class="fas fa-file-invoice mr-2"></i>Voucher Information
            </h2>
        </div>
        
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Voucher Number</label>
                    <p class="text-lg font-bold text-primary-700"><?php echo htmlspecialchars($voucher->voucher_number); ?></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Status</label>
                    <span class="inline-block px-3 py-1 text-sm font-bold rounded-full 
                        <?php 
                        $status_colors = [
                            'draft' => 'bg-gray-100 text-gray-800',
                            'approved' => 'bg-green-100 text-green-800',
                            'rejected' => 'bg-red-100 text-red-800',
                            'cancelled' => 'bg-gray-200 text-gray-600'
                        ];
                        echo $status_colors[$voucher->status] ?? 'bg-gray-100 text-gray-800';
                        ?>">
                        <?php echo ucwords($voucher->status); ?>
                    </span>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Voucher Date</label>
                    <p class="text-lg font-semibold text-gray-900">
                        <?php echo date('d F Y', strtotime($voucher->voucher_date)); ?>
                    </p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Amount</label>
                    <p class="text-2xl font-bold text-red-600">
                        ৳<?php echo number_format($voucher->amount, 2); ?>
                    </p>
                </div>
            </div>
            
            <?php if ($voucher->reference_number): ?>
            <div class="pt-4 border-t">
                <label class="block text-sm font-medium text-gray-600 mb-1">Reference Number</label>
                <p class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars($voucher->reference_number); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Details -->
    <div class="bg-white rounded-lg shadow-lg overflow-hidden">
        <div class="bg-gradient-to-r from-green-600 to-green-700 px-6 py-4">
            <h2 class="text-xl font-bold text-white">
                <i class="fas fa-hand-holding-usd mr-2"></i>Payment Details
            </h2>
        </div>
        
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-600 mb-1">Paid To</label>
                <p class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($voucher->paid_to); ?></p>
            </div>
            
            <?php if ($voucher->employee_name): ?>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <label class="block text-sm font-medium text-blue-800 mb-2">
                    <i class="fas fa-user mr-1"></i>Employee Information
                </label>
                <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($voucher->employee_name); ?></p>
                <?php if ($voucher->employee_email): ?>
                <p class="text-sm text-gray-600 mt-1">
                    <i class="fas fa-envelope mr-1"></i><?php echo htmlspecialchars($voucher->employee_email); ?>
                </p>
                <?php endif; ?>
                <?php if ($voucher->employee_phone): ?>
                <p class="text-sm text-gray-600 mt-1">
                    <i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($voucher->employee_phone); ?>
                </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="pt-4 border-t">
                <label class="block text-sm font-medium text-gray-600 mb-1">Description</label>
                <p class="text-gray-900 whitespace-pre-wrap"><?php echo htmlspecialchars($voucher->description); ?></p>
            </div>
        </div>
    </div>

    <!-- Accounting Entry -->
    <div class="bg-white rounded-lg shadow-lg overflow-hidden">
        <div class="bg-gradient-to-r from-purple-600 to-purple-700 px-6 py-4">
            <h2 class="text-xl font-bold text-white">
                <i class="fas fa-calculator mr-2"></i>Double-Entry Accounting
            </h2>
        </div>
        
        <div class="p-6">
            <?php if (!empty($transaction_lines)): ?>
            <div class="space-y-4">
                <div class="bg-yellow-50 border-2 border-yellow-300 rounded-lg p-4">
                    <p class="text-sm text-yellow-800 font-semibold mb-3">
                        <i class="fas fa-info-circle mr-1"></i>Journal Entry #<?php echo $journal_entry->id; ?>
                    </p>
                    <p class="text-xs text-gray-600">Date: <?php echo date('d M Y, h:i A', strtotime($journal_entry->transaction_date)); ?></p>
                    <p class="text-xs text-gray-600 mt-1"><?php echo htmlspecialchars($journal_entry->description); ?></p>
                </div>
                
                <div class="overflow-hidden border border-gray-200 rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Account</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-600 uppercase">Debit</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-600 uppercase">Credit</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php 
                            $total_debit = 0;
                            $total_credit = 0;
                            foreach ($transaction_lines as $line): 
                                $total_debit += $line->debit_amount;
                                $total_credit += $line->credit_amount;
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($line->account_name); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($line->account_number); ?> - <?php echo htmlspecialchars($line->account_type); ?></div>
                                    <?php if ($line->description): ?>
                                    <div class="text-xs text-gray-600 mt-1 italic"><?php echo htmlspecialchars($line->description); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <?php if ($line->debit_amount > 0): ?>
                                    <span class="font-bold text-red-600">৳<?php echo number_format($line->debit_amount, 2); ?></span>
                                    <?php else: ?>
                                    <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <?php if ($line->credit_amount > 0): ?>
                                    <span class="font-bold text-green-600">৳<?php echo number_format($line->credit_amount, 2); ?></span>
                                    <?php else: ?>
                                    <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-gray-50 font-bold">
                            <tr>
                                <td class="px-4 py-3 text-right">Total:</td>
                                <td class="px-4 py-3 text-right text-red-600">৳<?php echo number_format($total_debit, 2); ?></td>
                                <td class="px-4 py-3 text-right text-green-600">৳<?php echo number_format($total_credit, 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <?php if (abs($total_debit - $total_credit) < 0.01): ?>
                <div class="bg-green-50 border border-green-200 rounded-lg p-3 text-center">
                    <i class="fas fa-check-circle text-green-600 mr-2"></i>
                    <span class="text-green-800 font-semibold">Accounting Entry Balanced</span>
                </div>
                <?php else: ?>
                <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-center">
                    <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>
                    <span class="text-red-800 font-semibold">Warning: Entry Not Balanced!</span>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-exclamation-circle text-3xl mb-3"></i>
                <p>No accounting entry found for this voucher</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Sidebar -->
<div class="space-y-6">

    <!-- Quick Actions -->
    <div class="bg-white rounded-lg shadow-lg overflow-hidden">
        <div class="bg-gray-800 px-6 py-4">
            <h3 class="text-lg font-bold text-white">
                <i class="fas fa-bolt mr-2"></i>Quick Actions
            </h3>
        </div>
        <div class="p-6 space-y-3">
            <a href="debit_voucher_print.php?id=<?php echo $voucher->id; ?>" target="_blank"
               class="block w-full px-4 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-center font-medium">
                <i class="fas fa-print mr-2"></i>Print Voucher
            </a>
            <a href="expense_history.php" 
               class="block w-full px-4 py-3 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors text-center font-medium">
                <i class="fas fa-list mr-2"></i>All Expenses
            </a>
            <a href="debit_voucher.php" 
               class="block w-full px-4 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-center font-medium">
                <i class="fas fa-plus mr-2"></i>New Expense
            </a>
        </div>
    </div>

    <!-- Branch & Location -->
    <div class="bg-white rounded-lg shadow-lg overflow-hidden">
        <div class="bg-orange-600 px-6 py-4">
            <h3 class="text-lg font-bold text-white">
                <i class="fas fa-map-marker-alt mr-2"></i>Branch & Location
            </h3>
        </div>
        <div class="p-6">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center mr-4">
                    <i class="fas fa-building text-orange-600 text-xl"></i>
                </div>
                <div>
                    <p class="font-bold text-gray-900"><?php echo htmlspecialchars($voucher->branch_name ?: 'Head Office'); ?></p>
                    <?php if ($voucher->branch_code): ?>
                    <p class="text-sm text-gray-500">Code: <?php echo htmlspecialchars($voucher->branch_code); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Accounts Summary -->
    <div class="bg-white rounded-lg shadow-lg overflow-hidden">
        <div class="bg-indigo-600 px-6 py-4">
            <h3 class="text-lg font-bold text-white">
                <i class="fas fa-clipboard-list mr-2"></i>Accounts Summary
            </h3>
        </div>
        <div class="p-6 space-y-4">
            <div class="border-l-4 border-red-500 pl-4">
                <p class="text-xs text-gray-600 uppercase mb-1">Expense Account (Debit)</p>
                <p class="font-bold text-gray-900"><?php echo htmlspecialchars($voucher->expense_account_name); ?></p>
                <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($voucher->expense_account_number); ?></p>
                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($voucher->expense_account_type); ?></p>
            </div>
            
            <div class="border-l-4 border-green-500 pl-4">
                <p class="text-xs text-gray-600 uppercase mb-1">Payment Account (Credit)</p>
                <p class="font-bold text-gray-900"><?php echo htmlspecialchars($voucher->payment_account_name); ?></p>
                <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($voucher->payment_account_number); ?></p>
                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($voucher->payment_account_type); ?></p>
            </div>
        </div>
    </div>

    <!-- Audit Trail -->
    <div class="bg-white rounded-lg shadow-lg overflow-hidden">
        <div class="bg-gray-700 px-6 py-4">
            <h3 class="text-lg font-bold text-white">
                <i class="fas fa-history mr-2"></i>Audit Trail
            </h3>
        </div>
        <div class="p-6 space-y-3 text-sm">
            <div class="flex items-start">
                <i class="fas fa-user-circle text-gray-400 mt-1 mr-3"></i>
                <div>
                    <p class="text-gray-600">Created By</p>
                    <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($voucher->created_by_name); ?></p>
                    <?php if ($voucher->created_by_email): ?>
                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($voucher->created_by_email); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="flex items-start">
                <i class="fas fa-clock text-gray-400 mt-1 mr-3"></i>
                <div>
                    <p class="text-gray-600">Created At</p>
                    <p class="font-semibold text-gray-900"><?php echo date('d M Y, h:i A', strtotime($voucher->created_at)); ?></p>
                </div>
            </div>
            
            <?php if ($voucher->updated_at && $voucher->updated_at != $voucher->created_at): ?>
            <div class="flex items-start">
                <i class="fas fa-edit text-gray-400 mt-1 mr-3"></i>
                <div>
                    <p class="text-gray-600">Last Updated</p>
                    <p class="font-semibold text-gray-900"><?php echo date('d M Y, h:i A', strtotime($voucher->updated_at)); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

</div>

</div>

<?php require_once '../templates/footer.php'; ?>
<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$pageTitle = 'Journal Entry Details';

// Get journal entry ID
$entry_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($entry_id <= 0) {
    $_SESSION['error_flash'] = "Invalid journal entry ID";
    header('Location: daily_log.php');
    exit();
}

// Get journal entry with user details
$entry = $db->query(
    "SELECT je.*, 
            u.display_name as created_by_name,
            u.email as created_by_email
     FROM journal_entries je
     LEFT JOIN users u ON je.created_by_user_id = u.id
     WHERE je.id = ?",
    [$entry_id]
)->first();

if (!$entry) {
    $_SESSION['error_flash'] = "Journal entry not found";
    header('Location: daily_log.php');
    exit();
}

// Get transaction lines
$transaction_lines = $db->query(
    "SELECT tl.*, 
            coa.name as account_name,
            coa.account_number,
            coa.account_type
     FROM transaction_lines tl
     JOIN chart_of_accounts coa ON tl.account_id = coa.id
     WHERE tl.journal_entry_id = ?
     ORDER BY tl.debit_amount DESC, tl.id",
    [$entry_id]
)->results();

// Calculate totals
$total_debit = array_sum(array_map(function($line) { return $line->debit_amount; }, $transaction_lines));
$total_credit = array_sum(array_map(function($line) { return $line->credit_amount; }, $transaction_lines));

require_once '../templates/header.php';
?>

<div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Header -->
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
        <p class="text-lg text-gray-600 mt-1">Entry #<?php echo htmlspecialchars($entry->id); ?></p>
    </div>
    <div class="flex gap-3">
        <a href="daily_log.php?date=<?php echo date('Y-m-d', strtotime($entry->transaction_date)); ?>" 
           class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
            <i class="fas fa-arrow-left mr-2"></i>Back to Daily Log
        </a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

<!-- Main Details -->
<div class="lg:col-span-2 space-y-6">

    <!-- Entry Information -->
    <div class="bg-white rounded-lg shadow-lg overflow-hidden">
        <div class="bg-gradient-to-r from-purple-600 to-purple-700 px-6 py-4">
            <h2 class="text-xl font-bold text-white">
                <i class="fas fa-book-open mr-2"></i>Journal Entry Information
            </h2>
        </div>
        
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Entry ID</label>
                    <p class="text-lg font-bold text-primary-700">#<?php echo htmlspecialchars($entry->id); ?></p>
                </div>
                <?php if ($entry->uuid): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">UUID</label>
                    <p class="text-xs font-mono text-gray-700"><?php echo htmlspecialchars($entry->uuid); ?></p>
                </div>
                <?php endif; ?>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Transaction Date</label>
                    <p class="text-lg font-semibold text-gray-900">
                        <?php echo date('d F Y', strtotime($entry->transaction_date)); ?>
                    </p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Document Type</label>
                    <p class="text-lg font-medium text-gray-900">
                        <?php echo htmlspecialchars($entry->related_document_type ?: 'General Entry'); ?>
                    </p>
                </div>
            </div>
            
            <div class="pt-4 border-t">
                <label class="block text-sm font-medium text-gray-600 mb-1">Description</label>
                <p class="text-gray-900 whitespace-pre-wrap"><?php echo htmlspecialchars($entry->description); ?></p>
            </div>
        </div>
    </div>

    <!-- Transaction Lines -->
    <div class="bg-white rounded-lg shadow-lg overflow-hidden">
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-4">
            <h2 class="text-xl font-bold text-white">
                <i class="fas fa-list-alt mr-2"></i>Transaction Lines
            </h2>
        </div>
        
        <div class="p-6">
            <?php if (!empty($transaction_lines)): ?>
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
                        <?php foreach ($transaction_lines as $line): ?>
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
            <div class="bg-green-50 border border-green-200 rounded-lg p-3 text-center mt-4">
                <i class="fas fa-check-circle text-green-600 mr-2"></i>
                <span class="text-green-800 font-semibold">Entry Balanced</span>
            </div>
            <?php else: ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-center mt-4">
                <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>
                <span class="text-red-800 font-semibold">Warning: Entry Not Balanced! (Difference: ৳<?php echo number_format(abs($total_debit - $total_credit), 2); ?>)</span>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-exclamation-circle text-3xl mb-3"></i>
                <p>No transaction lines found</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Sidebar -->
<div class="space-y-6">

    <!-- Summary Stats -->
    <div class="bg-white rounded-lg shadow-lg overflow-hidden">
        <div class="bg-gray-800 px-6 py-4">
            <h3 class="text-lg font-bold text-white">
                <i class="fas fa-chart-bar mr-2"></i>Summary
            </h3>
        </div>
        <div class="p-6 space-y-4">
            <div class="text-center">
                <p class="text-sm text-gray-600 mb-1">Total Debit</p>
                <p class="text-2xl font-bold text-red-600">৳<?php echo number_format($total_debit, 2); ?></p>
            </div>
            <div class="text-center border-t pt-4">
                <p class="text-sm text-gray-600 mb-1">Total Credit</p>
                <p class="text-2xl font-bold text-green-600">৳<?php echo number_format($total_credit, 2); ?></p>
            </div>
            <div class="text-center border-t pt-4">
                <p class="text-sm text-gray-600 mb-1">Line Items</p>
                <p class="text-2xl font-bold text-gray-900"><?php echo count($transaction_lines); ?></p>
            </div>
        </div>
    </div>

    <!-- Related Document -->
    <?php if ($entry->related_document_type && $entry->related_document_id): ?>
    <div class="bg-white rounded-lg shadow-lg overflow-hidden">
        <div class="bg-indigo-600 px-6 py-4">
            <h3 class="text-lg font-bold text-white">
                <i class="fas fa-link mr-2"></i>Related Document
            </h3>
        </div>
        <div class="p-6">
            <p class="text-sm text-gray-600 mb-2">Document Type</p>
            <p class="font-semibold text-gray-900 mb-4"><?php echo htmlspecialchars($entry->related_document_type); ?></p>
            <p class="text-sm text-gray-600 mb-2">Document ID</p>
            <p class="font-mono text-gray-700">#<?php echo htmlspecialchars($entry->related_document_id); ?></p>
        </div>
    </div>
    <?php endif; ?>

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
                    <p class="text-gray-600">Posted By</p>
                    <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($entry->created_by_name ?: 'System'); ?></p>
                    <?php if ($entry->created_by_email): ?>
                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($entry->created_by_email); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="flex items-start">
                <i class="fas fa-clock text-gray-400 mt-1 mr-3"></i>
                <div>
                    <p class="text-gray-600">Created At</p>
                    <p class="font-semibold text-gray-900"><?php echo date('d M Y, h:i A', strtotime($entry->created_at)); ?></p>
                </div>
            </div>
            
            <?php if ($entry->updated_at && $entry->updated_at != $entry->created_at): ?>
            <div class="flex items-start">
                <i class="fas fa-edit text-gray-400 mt-1 mr-3"></i>
                <div>
                    <p class="text-gray-600">Last Updated</p>
                    <p class="font-semibold text-gray-900"><?php echo date('d M Y, h:i A', strtotime($entry->updated_at)); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

</div>

</div>

<?php require_once '../templates/footer.php'; ?>
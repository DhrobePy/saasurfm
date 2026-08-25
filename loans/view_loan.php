<?php
/**
 * View Loan — read-only detail: full loan info, the disbursement journal
 * entry, repayment history, and (gated) Edit/Delete actions.
 */
require_once '../core/init.php';

restrict_access(['Superadmin', 'admin'], 'loans', 'loan');

global $db;
$currentUser = getCurrentUser();
$is_admin    = in_array($currentUser['role'] ?? '', ['Superadmin', 'admin'], true);
$can_delete  = $is_admin || userCanPageAction('loans', 'loan', 'can_delete');
$pageTitle   = 'View Loan';

ensureLoansTable();
ensureLoanRepaymentsTable();

$loan_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$loan = $db->query(
    "SELECT l.*, c.name AS customer_name, c.phone_number AS customer_phone, c.business_partner_id AS customer_bp_id,
            s.company_name AS supplier_name, s.phone AS supplier_phone, s.business_partner_id AS supplier_bp_id,
            maker.display_name AS created_by_name, approver.display_name AS approved_by_name
     FROM loans l
     LEFT JOIN customers c ON c.id = l.customer_id
     LEFT JOIN suppliers s ON s.id = l.supplier_id
     LEFT JOIN users maker ON maker.id = l.created_by_user_id
     LEFT JOIN users approver ON approver.id = l.approved_by_user_id
     WHERE l.id = ?",
    [$loan_id]
)->first();

if (!$loan) {
    require_once '../templates/header.php';
    echo '<div class="max-w-screen-md mx-auto px-4 py-10 text-center"><p class="text-gray-500">Loan not found.</p><a href="loan.php" class="text-amber-700 hover:underline">&larr; Back to Loans</a></div>';
    require_once '../templates/footer.php';
    exit();
}

$party_name    = $loan->customer_name ?? $loan->supplier_name ?? '—';
$party_phone   = $loan->customer_phone ?? $loan->supplier_phone ?? '';
$party_type    = $loan->customer_id ? 'Customer' : 'Supplier';
$is_partner    = !empty($loan->customer_bp_id) || !empty($loan->supplier_bp_id);
$locked        = (float)$loan->amount_repaid > 0.01;
$overdue       = $loan->expected_return_date && (float)$loan->balance_due > 0.01 && strtotime($loan->expected_return_date) < strtotime(date('Y-m-d'));

$journal_lines = [];
if (!empty($loan->journal_entry_id)) {
    $journal_lines = $db->query(
        "SELECT tl.*, coa.name AS account_name, coa.account_number
         FROM transaction_lines tl JOIN chart_of_accounts coa ON coa.id = tl.account_id
         WHERE tl.journal_entry_id = ? ORDER BY tl.id ASC",
        [$loan->journal_entry_id]
    )->results();
}

$repayments = $db->query(
    "SELECT lr.*, u.display_name AS collected_by
     FROM loan_repayments lr LEFT JOIN users u ON u.id = lr.created_by_user_id
     WHERE lr.loan_id = ? ORDER BY lr.created_at DESC",
    [$loan_id]
)->results();

$csrf = $_SESSION['csrf_token'] ?? '';
$flash = $_SESSION['success_flash'] ?? null; unset($_SESSION['success_flash']);

require_once '../templates/header.php';
?>
<div class="max-w-screen-lg mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
            <a href="loan.php" class="text-xs text-gray-500 hover:text-amber-700"><i class="fas fa-arrow-left mr-1"></i>Back to Loans</a>
            <h1 class="text-2xl font-bold text-gray-900 mt-1">
                <i class="fas fa-file-invoice text-amber-600 mr-2"></i><?php echo htmlspecialchars($loan->loan_number); ?>
                <?php if ($overdue): ?><span class="ml-2 align-middle text-[11px] font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-800"><i class="fas fa-triangle-exclamation mr-1"></i>Overdue</span><?php endif; ?>
            </h1>
        </div>
        <div class="flex gap-2">
            <?php if ((float)$loan->balance_due > 0.01): ?>
            <a href="repay_loan.php?loan_id=<?php echo (int)$loan->id; ?>" class="px-3 py-2 text-sm bg-amber-600 text-white rounded-lg hover:bg-amber-700"><i class="fas fa-hand-holding-dollar mr-1"></i>Collect Repayment</a>
            <?php endif; ?>
            <?php if ($can_delete): ?>
                <?php if (!$locked): ?>
                <button type="button" onclick="vlDeleteLoan()" class="px-3 py-2 text-sm border-2 border-red-400 text-red-600 rounded-lg hover:bg-red-50"><i class="fas fa-trash mr-1"></i>Delete</button>
                <?php else: ?>
                <span class="px-3 py-2 text-sm border border-gray-200 rounded-lg text-gray-300" title="Reverse the repayment(s) first to delete this loan."><i class="fas fa-lock mr-1"></i>Delete</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash): ?><div class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-2.5 text-sm text-green-800"><i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Loan info -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 p-5">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Loan Details</h2>
            <dl class="grid grid-cols-2 gap-y-3 text-sm">
                <div><dt class="text-gray-500 text-xs">Borrower</dt><dd class="font-medium"><?php echo htmlspecialchars($party_name); ?> <span class="text-[10px] px-1.5 py-0.5 <?php echo $party_type === 'Customer' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'; ?> rounded-full"><?php echo $party_type; ?></span><?php if ($is_partner): ?> <span class="text-[10px] px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded-full">Business Partner</span><?php endif; ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Phone</dt><dd class="font-medium"><?php echo htmlspecialchars($party_phone ?: '—'); ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Loan Date</dt><dd class="font-medium"><?php echo date('d M Y', strtotime($loan->loan_date)); ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Expected Return</dt><dd class="font-medium <?php echo $overdue ? 'text-red-600' : ''; ?>"><?php echo $loan->expected_return_date ? date('d M Y', strtotime($loan->expected_return_date)) : '—'; ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Status</dt><dd class="font-medium capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $loan->status)); ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Created At</dt><dd class="font-medium"><?php echo date('d M Y, g:i A', strtotime($loan->created_at)); ?></dd></div>
                <div class="col-span-2"><dt class="text-gray-500 text-xs">Purpose</dt><dd class="font-medium"><?php echo $loan->purpose ? htmlspecialchars($loan->purpose) : '<span class="text-gray-300">—</span>'; ?></dd></div>
                <div class="col-span-2"><dt class="text-gray-500 text-xs">Notes</dt><dd class="font-medium"><?php echo $loan->notes ? htmlspecialchars($loan->notes) : '<span class="text-gray-300">—</span>'; ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Created By</dt><dd class="font-medium"><?php echo htmlspecialchars($loan->created_by_name ?? '—'); ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Approved/Posted By</dt><dd class="font-medium"><?php echo htmlspecialchars($loan->approved_by_name ?? '—'); ?></dd></div>
            </dl>
        </div>

        <!-- Financials -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Financials</h2>
            <dl class="space-y-2.5 text-sm">
                <div class="flex justify-between"><dt class="text-gray-500">Principal</dt><dd class="font-bold text-blue-700">৳<?php echo number_format((float)$loan->principal_amount, 2); ?></dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Repaid</dt><dd class="font-semibold text-green-700">৳<?php echo number_format((float)$loan->amount_repaid, 2); ?></dd></div>
                <div class="flex justify-between border-t pt-2"><dt class="text-gray-500">Balance Due</dt><dd class="font-bold <?php echo (float)$loan->balance_due > 0.01 ? 'text-amber-700' : 'text-gray-400'; ?>">৳<?php echo number_format((float)$loan->balance_due, 2); ?></dd></div>
            </dl>
        </div>
    </div>

    <!-- Journal entry -->
    <div class="mt-6 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-700">Journal Entry Posted (Disbursement)</h2></div>
        <div class="overflow-x-auto">
        <?php if (!empty($journal_lines)): ?>
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b"><tr>
                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Account</th>
                <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">Debit</th>
                <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">Credit</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($journal_lines as $jl): ?>
                <tr>
                    <td class="px-4 py-2"><?php echo htmlspecialchars($jl->account_name); ?> <span class="text-gray-400 text-xs">(<?php echo htmlspecialchars($jl->account_number ?? ''); ?>)</span></td>
                    <td class="px-4 py-2 text-right"><?php echo (float)$jl->debit_amount > 0 ? '৳' . number_format((float)$jl->debit_amount, 2) : '—'; ?></td>
                    <td class="px-4 py-2 text-right"><?php echo (float)$jl->credit_amount > 0 ? '৳' . number_format((float)$jl->credit_amount, 2) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-6 text-center text-gray-500 text-sm">No journal entry found for this loan.</div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Repayment history -->
    <div class="mt-6 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-700">Repayment History</h2></div>
        <div class="overflow-x-auto">
        <?php if (!empty($repayments)): ?>
        <table class="min-w-full text-xs">
            <thead class="bg-gray-50 border-b"><tr>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Receipt #</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Date</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase text-gray-500">Amount</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Method</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Collected By</th>
                <?php if ($can_delete): ?><th class="px-3 py-2 text-center text-[10px] font-semibold uppercase text-gray-500">Action</th><?php endif; ?>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($repayments as $r): ?>
                <tr>
                    <td class="px-3 py-2 font-mono text-amber-700"><?php echo htmlspecialchars($r->repayment_number); ?></td>
                    <td class="px-3 py-2 text-gray-500"><?php echo date('d M Y', strtotime($r->repayment_date)); ?></td>
                    <td class="px-3 py-2 text-right font-semibold">৳<?php echo number_format((float)$r->amount, 2); ?></td>
                    <td class="px-3 py-2"><?php echo htmlspecialchars($r->payment_method); ?></td>
                    <td class="px-3 py-2 text-gray-500"><?php echo htmlspecialchars($r->collected_by ?? '—'); ?></td>
                    <?php if ($can_delete): ?>
                    <td class="px-3 py-2 text-center">
                        <button type="button" onclick="vlReverseRepayment(<?php echo (int)$r->id; ?>, <?php echo htmlspecialchars(json_encode($r->repayment_number), ENT_QUOTES); ?>)"
                                class="px-2 py-1 border-2 border-red-400 text-red-600 rounded-md text-[11px] font-bold hover:bg-red-50">
                            <i class="fas fa-rotate-left mr-1"></i>Reverse
                        </button>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-6 text-center text-gray-500 text-xs">No repayments recorded against this loan yet.</div>
        <?php endif; ?>
        </div>
    </div>

</div>
<script>
function vlDeleteLoan() {
    const reason = prompt('Delete/reverse loan <?php echo htmlspecialchars($loan->loan_number, ENT_QUOTES); ?> — this moves it to the Recycle Bin and reverses the journal entry. Reason (required):');
    if (reason === null) return;
    if (!reason.trim()) { alert('A reason is required.'); return; }
    const fd = new FormData();
    fd.append('loan_id', <?php echo (int)$loan->id; ?>);
    fd.append('reason', reason.trim());
    fd.append('csrf_token', <?php echo json_encode($csrf); ?>);
    fetch('delete_loan.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': <?php echo json_encode($csrf); ?> } })
        .then(r => r.json())
        .then(data => {
            if (data.success) { alert(data.message); window.location.href = 'loan.php'; }
            else { alert('Could not delete: ' + data.message); }
        })
        .catch(() => alert('Network error — please try again.'));
}
function vlReverseRepayment(repaymentId, repaymentNumber) {
    const reason = prompt('Reverse repayment ' + repaymentNumber + ' — this moves it to the Recycle Bin and puts the loan balance back. Reason (required):');
    if (reason === null) return;
    if (!reason.trim()) { alert('A reason is required.'); return; }
    const fd = new FormData();
    fd.append('repayment_id', repaymentId);
    fd.append('reason', reason.trim());
    fd.append('csrf_token', <?php echo json_encode($csrf); ?>);
    fetch('delete_loan_repayment.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': <?php echo json_encode($csrf); ?> } })
        .then(r => r.json())
        .then(data => {
            if (data.success) { alert(data.message); location.reload(); }
            else { alert('Could not reverse: ' + data.message); }
        })
        .catch(() => alert('Network error — please try again.'));
}
</script>
<?php require_once '../templates/footer.php'; ?>

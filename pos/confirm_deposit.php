<?php
/**
 * Confirm next-day bank deposit for EOD batches flagged bank_deposit_pending
 * (Jul 2026). Closes the loop between "cash collected at POS" and "cash
 * actually banked" — posts the Petty Cash -> Bank journal entry.
 */
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'accountspos-demra', 'accountspos-srg'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Confirm POS Bank Deposit';
ensureEodDepositColumns();

$currentUser = getCurrentUser();
$user_id = (int)($currentUser['id'] ?? 0);
$user_name = $currentUser['display_name'] ?? 'Staff';

$error = null; $success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    $eod_id = (int)($_POST['eod_id'] ?? 0);
    $bank_account_id = (int)($_POST['bank_account_id'] ?? 0);
    $reference = trim($_POST['reference'] ?? '');

    $eod = $db->query("SELECT * FROM eod_summary WHERE id = ? AND cash_disposition = 'bank_deposit_pending' AND deposited_at IS NULL", [$eod_id])->first();

    if (!$eod) { $error = 'EOD record not found or already confirmed.'; }
    elseif (!$bank_account_id) { $error = 'Select a bank account.'; }
    else {
        $db->beginTransaction();
        try {
            $petty_cash_account_id = getBranchPettyCashAccountId((int)$eod->branch_id);
            if (!$petty_cash_account_id) throw new Exception('No active petty cash account configured for this branch.');

            $amount = (float)$eod->actual_cash;

            $db->query(
                "INSERT INTO journal_entries (transaction_date, description, related_document_type, created_by_user_id) VALUES (CURDATE(), ?, 'PosDeposit', ?)",
                ["POS Cash Deposit - EOD #{$eod_id} - " . date('d M Y', strtotime($eod->eod_date)), $user_id]
            );
            if ($db->error()) throw new Exception('Failed to create journal entry: ' . ($db->errorInfo()[2] ?? ''));
            $journal_id = $db->getPdo()->lastInsertId();

            $db->query("INSERT INTO transaction_lines (journal_entry_id, account_id, debit_amount, credit_amount, description) VALUES (?, ?, ?, 0, ?)",
                [$journal_id, $bank_account_id, $amount, "Bank deposit - EOD #{$eod_id}"]);
            if ($db->error()) throw new Exception('Failed to post bank debit line: ' . ($db->errorInfo()[2] ?? ''));

            $db->query("INSERT INTO transaction_lines (journal_entry_id, account_id, debit_amount, credit_amount, description) VALUES (?, ?, 0, ?, ?)",
                [$journal_id, $petty_cash_account_id, $amount, "Cash out for deposit - EOD #{$eod_id}"]);
            if ($db->error()) throw new Exception('Failed to post petty cash credit line: ' . ($db->errorInfo()[2] ?? ''));

            postBranchPettyCashTransaction((int)$eod->branch_id, 'transfer_out', $amount, [
                'reference_type' => 'pos_eod_deposit', 'reference_id' => $eod_id,
                'description' => "Deposited to bank - EOD #{$eod_id}", 'payment_method' => 'Bank Transfer',
            ]);

            $db->query(
                "UPDATE eod_summary SET deposit_bank_account_id = ?, deposit_reference = ?, deposited_at = NOW(), deposited_by_user_id = ?, deposit_journal_entry_id = ?, cash_disposition = 'deposited' WHERE id = ?",
                [$bank_account_id, $reference ?: null, $user_id, $journal_id, $eod_id]
            );
            if ($db->error()) throw new Exception('Failed to update EOD record: ' . ($db->errorInfo()[2] ?? ''));

            $db->commit();

            if (function_exists('auditLog')) {
                auditLog('pos', 'deposit_confirmed', "POS cash deposit confirmed for EOD #{$eod_id} — ৳" . number_format($amount, 2),
                    ['record_type' => 'pos_eod', 'record_id' => $eod_id]);
            }

            $success = "Deposit of ৳" . number_format($amount, 2) . " confirmed.";
        } catch (Exception $e) {
            if ($db->getPdo()->inTransaction()) $db->rollback();
            $error = 'Could not confirm deposit: ' . $e->getMessage();
        }
    }
}

$pending = $db->query(
    "SELECT e.*, b.name AS branch_name
     FROM eod_summary e
     JOIN branches b ON b.id = e.branch_id
     WHERE e.cash_disposition = 'bank_deposit_pending' AND e.deposited_at IS NULL
     ORDER BY e.eod_date ASC"
)->results();

$bank_accounts = $db->query("SELECT id, name FROM chart_of_accounts WHERE account_type = 'Bank' AND is_active = 1 ORDER BY name")->results();

$recent_deposits = $db->query(
    "SELECT e.*, b.name AS branch_name, c.name AS bank_name
     FROM eod_summary e
     JOIN branches b ON b.id = e.branch_id
     LEFT JOIN chart_of_accounts c ON c.id = e.deposit_bank_account_id
     WHERE e.deposited_at IS NOT NULL
     ORDER BY e.deposited_at DESC LIMIT 20"
)->results();

require_once '../templates/header.php';
?>
<div class="max-w-4xl mx-auto px-4 py-6">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Confirm POS Bank Deposit</h1>

    <?php if ($success): ?>
    <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg text-green-800 text-sm"><i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-800 text-sm"><i class="fas fa-exclamation-triangle mr-1"></i><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <h2 class="text-lg font-bold text-gray-800 mb-3">Pending Deposits</h2>
    <?php if (empty($pending)): ?>
    <div class="bg-white rounded-xl shadow-sm p-8 text-center text-gray-400 mb-8">No EOD batches waiting for bank deposit.</div>
    <?php else: ?>
    <div class="space-y-3 mb-8">
        <?php foreach ($pending as $e): ?>
        <div class="bg-white rounded-xl shadow-sm p-5">
            <div class="flex justify-between items-start mb-3">
                <div>
                    <div class="font-bold text-gray-900"><?php echo htmlspecialchars($e->branch_name); ?></div>
                    <div class="text-sm text-gray-500"><?php echo date('d M Y', strtotime($e->eod_date)); ?></div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-gray-500">Cash to deposit</div>
                    <div class="text-xl font-bold text-green-600">৳<?php echo number_format($e->actual_cash, 2); ?></div>
                </div>
            </div>
            <form method="POST" class="flex gap-2 items-end flex-wrap">
                <input type="hidden" name="action" value="confirm">
                <input type="hidden" name="eod_id" value="<?php echo $e->id; ?>">
                <div class="flex-1 min-w-[180px]">
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Bank Account</label>
                    <select name="bank_account_id" required class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg text-sm">
                        <option value="">— Select —</option>
                        <?php foreach ($bank_accounts as $ba): ?>
                        <option value="<?php echo $ba->id; ?>"><?php echo htmlspecialchars($ba->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex-1 min-w-[160px]">
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Deposit Slip / Reference</label>
                    <input type="text" name="reference" class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg text-sm">
                </div>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-bold">
                    Confirm Deposited
                </button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2 class="text-lg font-bold text-gray-800 mb-3">Recent Deposits</h2>
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <table class="min-w-full text-sm divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase text-xs">EOD Date</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase text-xs">Branch</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase text-xs">Amount</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase text-xs">Bank</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase text-xs">Deposited</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($recent_deposits)): ?>
                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No deposits confirmed yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recent_deposits as $d): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3"><?php echo date('d M Y', strtotime($d->eod_date)); ?></td>
                    <td class="px-4 py-3"><?php echo htmlspecialchars($d->branch_name); ?></td>
                    <td class="px-4 py-3 text-right font-bold">৳<?php echo number_format($d->actual_cash, 2); ?></td>
                    <td class="px-4 py-3"><?php echo htmlspecialchars($d->bank_name ?? ''); ?></td>
                    <td class="px-4 py-3 text-gray-500"><?php echo date('d M Y, h:i A', strtotime($d->deposited_at)); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once '../templates/footer.php'; ?>

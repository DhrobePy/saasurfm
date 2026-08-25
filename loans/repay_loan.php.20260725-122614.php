<?php
/**
 * Collect Loan Repayment — payment collection against a loans balance.
 * Deliberately its OWN table (loan_repayments), not customer_payments — same
 * reasoning as commodity sale payments (see ensureLoanRepaymentsTable()'s
 * docblock). Reuses the SAME 'collect_payment' ৳ limit and global "require
 * approval for every payment" toggle as every other collection page —
 * collecting money is one conceptual action regardless of module.
 */
require_once '../core/init.php';

restrict_access(['Superadmin', 'admin'], 'loans', 'loan');

global $db;
$currentUser = getCurrentUser();
$user_id     = $currentUser['id'] ?? null;
$is_admin    = in_array($currentUser['role'] ?? '', ['Superadmin', 'admin'], true);
$can_delete  = $is_admin || userCanPageAction('loans', 'loan', 'can_delete');
$pageTitle   = 'Collect Loan Repayment';

ensureLoansTable();
ensureLoanRepaymentsTable();

$csrf = $_SESSION['csrf_token'] ?? '';

$ar_account = null;
$cash_account = $db->query(
    "SELECT id, name FROM chart_of_accounts
     WHERE account_type = 'Petty Cash' OR name = 'Undeposited Funds'
     ORDER BY CASE WHEN name = 'Undeposited Funds' THEN 0 ELSE 1 END LIMIT 1"
)->first();
$bank_accounts = $db->query(
    "SELECT ba.id, ba.chart_of_account_id, ba.bank_name, ba.account_name
     FROM bank_accounts ba JOIN chart_of_accounts coa ON ba.chart_of_account_id = coa.id
     WHERE ba.status = 'active' AND coa.account_type = 'Bank' ORDER BY ba.account_name"
)->results();

// ── Checker executing an approved request? ──────────────────────────────
$preq        = null;
$preq_error  = null;
$preq_id_get = isset($_GET['pending_req']) ? (int)$_GET['pending_req'] : 0;
if ($preq_id_get) {
    $preq = getPendingRequest($preq_id_get, 'loan_repayment');
    if (!$preq) {
        $preq_error = "Request #{$preq_id_get} is not open — it may already be approved, rejected, or cancelled.";
    } elseif (!$is_admin) {
        $my_cap = getUserActionLimit((int)$user_id, 'collect_payment');
        if ($my_cap !== null && (float)$preq->amount > $my_cap) {
            $preq_error = "Request #{$preq_id_get} exceeds your collection limit — a more senior officer must post it.";
            $preq = null;
        }
    }
}

$loan_id_get = isset($_GET['loan_id']) ? (int)$_GET['loan_id'] : ($preq ? (int)($preq->payload_arr['loan_id'] ?? 0) : 0);
$loan = null;
if ($loan_id_get) {
    $loan = $db->query(
        "SELECT l.*, c.name AS customer_name, s.company_name AS supplier_name
         FROM loans l LEFT JOIN customers c ON c.id = l.customer_id LEFT JOIN suppliers s ON s.id = l.supplier_id
         WHERE l.id = ?", [$loan_id_get]
    )->first();
}

// ── POST: collect ────────────────────────────────────────────────────────
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'collect_loan_repayment') {
    try {
        if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token — refresh the page and try again.');
        }
        $lid            = (int)($_POST['loan_id'] ?? 0);
        $amount         = (float)($_POST['amount'] ?? 0);
        $repayment_date = trim($_POST['repayment_date'] ?? '');
        $payment_method = trim($_POST['payment_method'] ?? '');
        $bank_account_id = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
        $reference      = trim($_POST['reference_number'] ?? '');
        $notes          = trim($_POST['notes'] ?? '');
        $exec_pending_req_id = (int)($_POST['pending_req_id'] ?? 0);

        if (!$lid) throw new Exception('No loan selected.');
        if ($amount <= 0) throw new Exception('Amount must be greater than zero.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $repayment_date) || strtotime($repayment_date) === false) throw new Exception('Invalid repayment date.');
        if ($payment_method === '') throw new Exception('Select a payment method.');
        if ($payment_method !== 'Cash' && !$bank_account_id) throw new Exception('Select the bank account for a non-cash payment.');
        if (!$cash_account) throw new Exception("Chart of Accounts is missing a Petty Cash/Undeposited Funds account.");

        $loan_row = $db->query(
            "SELECT l.*, c.name AS customer_name, s.company_name AS supplier_name
             FROM loans l LEFT JOIN customers c ON c.id = l.customer_id LEFT JOIN suppliers s ON s.id = l.supplier_id
             WHERE l.id = ?", [$lid]
        )->first();
        if (!$loan_row) throw new Exception('Loan not found.');
        $party_name = $loan_row->customer_name ?? $loan_row->supplier_name ?? 'party';
        if ($amount > (float)$loan_row->balance_due + 0.01) {
            throw new Exception('Amount (৳' . number_format($amount, 2) . ') exceeds the outstanding balance (৳' . number_format((float)$loan_row->balance_due, 2) . ').');
        }

        // ── Maker/checker: same collect_payment limit + global policy as everywhere else ──
        if (!$is_admin) {
            $my_limit = getUserActionLimit((int)$user_id, 'collect_payment');
            $over_limit = $my_limit !== null && $amount > $my_limit;

            if ($exec_pending_req_id) {
                if ($over_limit) {
                    throw new Exception('Your collection limit (৳' . number_format($my_limit, 0) . ') does not cover this ৳' . number_format($amount, 0) . ' repayment — a more senior officer must post it.');
                }
            } else {
                $no_limit_configured = $my_limit === null;
                $needs_approval = paymentApprovalRequiredForAll() || $over_limit || $no_limit_configured;
                if ($needs_approval) {
                    $req_id = submitPendingRequest('loan_repayment', $amount, [
                        'loan_id' => $lid, 'repayment_date' => $repayment_date, 'payment_method' => $payment_method,
                        'bank_account_id' => $bank_account_id, 'reference_number' => $reference, 'notes' => $notes,
                    ], [
                        'customer_id' => $loan_row->customer_id,
                        'summary'     => "Collect ৳" . number_format($amount, 0) . " repayment against loan {$loan_row->loan_number} from {$party_name}",
                        'maker_limit' => $my_limit,
                    ]);
                    if (!$req_id) throw new Exception('Could not queue this repayment for approval. Please try again.');
                    $reason = $over_limit ? 'over ৳' . number_format($my_limit, 0) . ' limit' : ($no_limit_configured ? 'no collection limit configured for this user' : 'payment approval policy');
                    auditLog('other', 'created', "Loan repayment of ৳" . number_format($amount, 2) . " ({$loan_row->loan_number}) queued for approval ({$reason}) by " . ($currentUser['display_name'] ?? 'user'));
                    $_SESSION['success_flash'] = "This repayment (৳" . number_format($amount, 0) . ") was sent for approval. It will post once a senior officer approves it.";
                    header('Location: loan.php');
                    exit();
                }
            }
        }

        // ── Post directly ──
        $pdo = $db->getPdo();
        $pdo->beginTransaction();

        $date_prefix = date('Ymd', strtotime($repayment_date));
        $last = $db->query("SELECT repayment_number FROM loan_repayments WHERE repayment_number LIKE ? ORDER BY id DESC LIMIT 1", ["LNR-{$date_prefix}-%"])->first();
        $seq  = $last ? ((int)substr($last->repayment_number, -4) + 1) : 1;
        $repayment_number = sprintf("LNR-%s-%04d", $date_prefix, $seq);

        $loan_account_id = ensureLoanAccounts();
        $deposit_account_id = null;
        if ($payment_method === 'Cash') {
            $deposit_account_id = $cash_account->id;
        } else {
            $selected_bank = $db->query("SELECT chart_of_account_id FROM bank_accounts WHERE id = ?", [$bank_account_id])->first();
            if (!$selected_bank) throw new Exception('Invalid bank account.');
            $deposit_account_id = $selected_bank->chart_of_account_id;
        }

        $journal_desc = "Loan repayment {$repayment_number} — {$loan_row->loan_number} from {$party_name}";
        $journal_id = $db->insert('journal_entries', [
            'transaction_date' => $repayment_date, 'description' => $journal_desc,
            'related_document_type' => 'loan_repayments', 'related_document_id' => null, 'created_by_user_id' => $user_id,
        ]);
        if (!$journal_id) throw new Exception('Failed to create the journal entry.');
        $db->insert('transaction_lines', ['journal_entry_id' => $journal_id, 'account_id' => $deposit_account_id, 'debit_amount' => $amount, 'credit_amount' => 0, 'description' => $journal_desc]);
        $db->insert('transaction_lines', ['journal_entry_id' => $journal_id, 'account_id' => $loan_account_id, 'debit_amount' => 0, 'credit_amount' => $amount, 'description' => $journal_desc]);

        // Self-healing: balance_due recomputed from principal_amount − amount_repaid.
        $db->query(
            "UPDATE loans SET amount_repaid = amount_repaid + ?, balance_due = principal_amount - amount_repaid WHERE id = ?",
            [$amount, $lid]
        );
        // Close the loan once fully repaid.
        $db->query("UPDATE loans SET status = 'closed' WHERE id = ? AND balance_due <= 0.01", [$lid]);

        $repay_creator_id = $user_id;
        if ($exec_pending_req_id && $preq && !empty($preq->maker_user_id)) $repay_creator_id = (int)$preq->maker_user_id;

        $repayment_id = $db->insert('loan_repayments', [
            'repayment_number' => $repayment_number, 'loan_id' => $lid,
            'customer_id' => $loan_row->customer_id, 'supplier_id' => $loan_row->supplier_id,
            'amount' => $amount, 'payment_method' => $payment_method, 'bank_account_id' => $bank_account_id,
            'reference_number' => $reference ?: null, 'notes' => $notes ?: null,
            'journal_entry_id' => $journal_id, 'created_by_user_id' => $repay_creator_id, 'repayment_date' => $repayment_date,
        ]);
        if (!$repayment_id) throw new Exception('Failed to record the repayment.');
        $db->query("UPDATE journal_entries SET related_document_type = 'loan_repayments', related_document_id = ? WHERE id = ?", [$repayment_id, $journal_id]);

        // Best-effort bank bridge (money IN — credit — never blocks the repayment).
        if ($payment_method !== 'Cash' && $bank_account_id) {
            try {
                require_once dirname(__DIR__) . '/bank/BankManager.php';
                $bm_bridge = $db->query(
                    "SELECT bta.id AS bta_id FROM bank_tx_accounts bta INNER JOIN bank_accounts ba ON ba.account_number = bta.account_number
                     WHERE ba.id = ? AND bta.status = 'active' LIMIT 1", [$bank_account_id]
                )->first();
                if ($bm_bridge) {
                    $bankMgr = new BankManager();
                    $bankMgr->createTransaction([
                        'transaction_date' => $repayment_date, 'entry_type' => 'credit',
                        'bank_tx_account_id' => (int)$bm_bridge->bta_id, 'amount' => $amount,
                        'reference_number' => $repayment_number, 'payee_payer_name' => $party_name,
                        'description' => "Loan repayment — {$party_name} — Receipt #{$repayment_number}",
                    ], $user_id, $currentUser['display_name'] ?? 'System');
                }
            } catch (\Throwable $bm_ex) { error_log('BankManager bridge (repay_loan): ' . $bm_ex->getMessage()); }
        }

        if ($exec_pending_req_id) {
            decidePendingRequest($exec_pending_req_id, 'approved', 'Posted by ' . ($currentUser['display_name'] ?? 'checker'), $repayment_number);
        }

        $pdo->commit();

        auditLog('other', 'created', "Loan repayment {$repayment_number}: ৳" . number_format($amount, 2) . " against {$loan_row->loan_number} from {$party_name} by " . ($currentUser['display_name'] ?? 'user'));

        if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED && defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID')) {
            try {
                require_once '../core/classes/TelegramNotifier.php';
                (new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID))->sendMessage(
                    "<b>💰 LOAN REPAYMENT RECEIVED</b>\n───────────────────────────────\n\n"
                    . "• Receipt: <code>{$repayment_number}</code>\n• Loan: {$loan_row->loan_number}\n"
                    . "• From: <b>" . htmlspecialchars($party_name) . "</b>\n• Amount: ৳" . number_format($amount, 2) . "\n"
                    . "• Method: {$payment_method}\n• Collected by: " . ($currentUser['display_name'] ?? 'user')
                );
            } catch (\Throwable $te) { error_log('repay_loan Telegram: ' . $te->getMessage()); }
        }

        $_SESSION['success_flash'] = "Repayment {$repayment_number} of ৳" . number_format($amount, 2) . " recorded against {$loan_row->loan_number}.";
        header('Location: view_loan.php?id=' . $lid);
        exit();

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Open loans for the picker (when not deep-linked to one specific loan).
$open_loans = $db->query(
    "SELECT l.id, l.loan_number, l.balance_due, c.name AS customer_name, s.company_name AS supplier_name
     FROM loans l LEFT JOIN customers c ON c.id = l.customer_id LEFT JOIN suppliers s ON s.id = l.supplier_id
     WHERE l.balance_due > 0.01 ORDER BY l.created_at DESC"
)->results();

$flash = $_SESSION['success_flash'] ?? null; unset($_SESSION['success_flash']);

require_once '../templates/header.php';
?>
<div class="max-w-screen-md mx-auto px-4 sm:px-6 py-6">

    <div class="mb-4">
        <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-hand-holding-dollar text-amber-600 mr-2"></i>Collect Loan Repayment</h1>
        <p class="text-gray-600 mt-1 text-sm">Record a repayment against an outstanding loan balance.</p>
    </div>

    <?php if ($flash): ?><div class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-2.5 text-sm text-green-800"><i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-2.5 text-sm text-red-800"><i class="fas fa-triangle-exclamation mr-1"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($preq_error): ?><div class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-2.5 text-sm text-red-800"><i class="fas fa-ban mr-1"></i><?php echo htmlspecialchars($preq_error); ?></div><?php endif; ?>
    <?php if ($preq): ?><div class="mb-4 rounded-lg border border-blue-300 bg-blue-50 px-4 py-3 text-sm text-blue-900"><i class="fas fa-user-check mr-1"></i>Reviewing pending payment request #<?php echo (int)$preq->id; ?> — prefilled below.</div><?php endif; ?>

    <form method="POST" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-4" id="rlForm">
        <input type="hidden" name="action" value="collect_loan_repayment">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <?php if ($preq): ?><input type="hidden" name="pending_req_id" value="<?php echo (int)$preq->id; ?>"><?php endif; ?>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Loan</label>
            <select name="loan_id" required class="w-full px-4 py-2 border rounded-lg" onchange="rlUpdate()" id="rl_loan">
                <option value="">Select a loan with a balance due</option>
                <?php foreach ($open_loans as $l): ?>
                <option value="<?php echo (int)$l->id; ?>" data-due="<?php echo (float)$l->balance_due; ?>"
                        <?php echo (int)$loan_id_get === (int)$l->id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($l->loan_number . ' — ' . ($l->customer_name ?? $l->supplier_name) . ' — Due ৳' . number_format((float)$l->balance_due, 2)); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <p id="rl_due_hint" class="mt-1 text-xs text-gray-500"></p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Amount (৳) <span class="text-red-500">*</span></label>
                <input type="number" name="amount" id="rl_amount" step="0.01" min="0.01" required class="w-full px-4 py-2 border rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Repayment Date <span class="text-red-500">*</span></label>
                <input type="date" name="repayment_date" required value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" class="w-full px-4 py-2 border rounded-lg">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Payment Method <span class="text-red-500">*</span></label>
                <select name="payment_method" id="rl_method" required class="w-full px-4 py-2 border rounded-lg" onchange="rlToggleBank()">
                    <option value="Cash">Cash</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Cheque">Cheque</option>
                </select>
            </div>
            <div id="rl_bank_box" class="hidden">
                <label class="block text-sm font-medium text-gray-700 mb-2">Bank Account <span class="text-red-500">*</span></label>
                <select name="bank_account_id" class="w-full px-4 py-2 border rounded-lg">
                    <option value="">Select bank account</option>
                    <?php foreach ($bank_accounts as $b): ?>
                    <option value="<?php echo (int)$b->id; ?>"><?php echo htmlspecialchars($b->bank_name . ' — ' . $b->account_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Reference Number</label>
                <input type="text" name="reference_number" class="w-full px-4 py-2 border rounded-lg" placeholder="Cheque no, TXN ID, etc.">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                <input type="text" name="notes" class="w-full px-4 py-2 border rounded-lg" placeholder="Optional">
            </div>
        </div>

        <div class="flex justify-end pt-2 border-t">
            <button type="submit" class="px-5 py-2 bg-amber-600 text-white font-semibold rounded-lg hover:bg-amber-700 text-sm"><i class="fas fa-check mr-1"></i>Record Repayment</button>
        </div>
    </form>
</div>
<script>
function rlUpdate() {
    const sel = document.getElementById('rl_loan');
    const opt = sel.selectedOptions[0];
    const hint = document.getElementById('rl_due_hint');
    if (opt && opt.value) {
        const due = parseFloat(opt.dataset.due) || 0;
        hint.textContent = 'Outstanding: ৳' + due.toFixed(2);
        document.getElementById('rl_amount').max = due;
        if (!document.getElementById('rl_amount').value) document.getElementById('rl_amount').value = due.toFixed(2);
    } else {
        hint.textContent = '';
    }
}
function rlToggleBank() {
    const method = document.getElementById('rl_method').value;
    document.getElementById('rl_bank_box').classList.toggle('hidden', method === 'Cash');
}
document.addEventListener('DOMContentLoaded', () => { rlUpdate(); rlToggleBank();
<?php if ($preq):
    $pp = $preq->payload_arr;
?>
    document.querySelector('input[name="amount"]').value = <?php echo json_encode((string)($preq->amount ?? '')); ?>;
    document.querySelector('input[name="repayment_date"]').value = <?php echo json_encode($pp['repayment_date'] ?? date('Y-m-d')); ?>;
    document.getElementById('rl_method').value = <?php echo json_encode($pp['payment_method'] ?? 'Cash'); ?>;
    document.querySelector('input[name="reference_number"]').value = <?php echo json_encode($pp['reference_number'] ?? ''); ?>;
    document.querySelector('input[name="notes"]').value = <?php echo json_encode($pp['notes'] ?? ''); ?>;
    rlToggleBank();
    <?php if (!empty($pp['bank_account_id'])): ?>
    document.querySelector('select[name="bank_account_id"]').value = <?php echo json_encode((string)$pp['bank_account_id']); ?>;
    <?php endif; ?>
<?php endif; ?>
});
</script>
<?php require_once '../templates/footer.php'; ?>

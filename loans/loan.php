<?php
/**
 * New Loan — disburse cash to a customer, supplier, or related party (e.g. a
 * sister concern funding tender participation). Deliberately its OWN flow,
 * NOT posted into customer_ledger/supplier_ledger — a loan is cash, not
 * goods, and none of those tables' transaction types honestly describe it.
 * See ensureLoansTable()'s docblock in helpers.php for the full reasoning.
 *
 * Approval: Superadmin/admin always post directly. A non-admin posts directly
 * ONLY if they have a delegated 'loan_disbursement' ৳ limit covering the
 * amount AND the global approval policy is off — otherwise it queues in the
 * shared Approval Requests inbox, same maker/checker engine as everywhere else.
 */
require_once '../core/init.php';

restrict_access(['Superadmin', 'admin'], 'loans', 'loan');

global $db;
$currentUser = getCurrentUser();
$user_id     = $currentUser['id'] ?? null;
$is_admin    = in_array($currentUser['role'] ?? '', ['Superadmin', 'admin'], true);
$can_delete  = $is_admin || userCanPageAction('loans', 'loan', 'can_delete');
$pageTitle   = 'New Loan';

ensureLoansTable();
ensureLoanRepaymentsTable();

$csrf = $_SESSION['csrf_token'] ?? '';

$ar_account = null; // not used — loans don't touch Accounts Receivable
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
    $preq = getPendingRequest($preq_id_get, 'loan_disbursement');
    if (!$preq) {
        $preq_error = "Request #{$preq_id_get} is not open — it may already be approved, rejected, or cancelled.";
    } elseif (!$is_admin) {
        $my_cap = getUserActionLimit((int)$user_id, 'loan_disbursement');
        if ($my_cap !== null && (float)$preq->amount > $my_cap) {
            $preq_error = "Request #{$preq_id_get} (৳" . number_format((float)$preq->amount, 0) . ") exceeds your delegated limit (৳" . number_format($my_cap, 0) . ") — a more senior officer must post it.";
            $preq = null;
        }
    }
}

// ── POST: disburse loan ────────────────────────────────────────────────
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_loan') {
    try {
        if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token — refresh the page and try again.');
        }

        $customer_id  = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $supplier_id  = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
        $principal    = (float)($_POST['principal_amount'] ?? 0);
        $purpose      = trim($_POST['purpose'] ?? '');
        $notes        = trim($_POST['notes'] ?? '');
        $payment_method = trim($_POST['payment_method'] ?? '');
        $bank_account_id = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
        $exec_pending_req_id = (int)($_POST['pending_req_id'] ?? 0);

        $today = date('Y-m-d');
        $loan_date = $today;
        if ($is_admin && !empty($_POST['loan_date'])) {
            $posted_date = trim($_POST['loan_date']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $posted_date) && strtotime($posted_date) !== false) {
                if ($posted_date <= $today) { $loan_date = $posted_date; }
                else { throw new Exception('Loan date cannot be in the future.'); }
            }
        }
        $expected_return_date = null;
        if (!empty($_POST['expected_return_date'])) {
            $erd = trim($_POST['expected_return_date']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $erd) && strtotime($erd) !== false) $expected_return_date = $erd;
        }

        if (!$customer_id && !$supplier_id) throw new Exception('Please select a borrower (customer or supplier).');
        if ($customer_id && $supplier_id)   throw new Exception('Select only one party — either a customer or a supplier.');
        if ($principal <= 0) throw new Exception('Loan amount must be greater than zero.');
        if ($payment_method === '') throw new Exception('Select how the loan is being paid out.');
        if ($payment_method !== 'Cash' && !$bank_account_id) throw new Exception('Select the bank account funding this loan.');
        if (!$cash_account) throw new Exception("Chart of Accounts is missing a Petty Cash/Undeposited Funds account.");

        $party = null;
        $party_name = '';
        if ($customer_id) {
            $party = $db->query("SELECT id, name FROM customers WHERE id = ?", [$customer_id])->first();
            if (!$party) throw new Exception('Customer not found.');
            $party_name = $party->name;
        } else {
            $party = $db->query("SELECT id, company_name AS name FROM suppliers WHERE id = ?", [$supplier_id])->first();
            if (!$party) throw new Exception('Supplier not found.');
            $party_name = $party->name;
        }

        // ── Maker/checker gate (mirrors commodity_sale.php exactly) ───────
        if (!$is_admin) {
            $my_limit  = getUserActionLimit((int)$user_id, 'loan_disbursement');
            $over_limit = $my_limit !== null && $principal > $my_limit;

            if ($exec_pending_req_id) {
                if ($over_limit) {
                    throw new Exception('Your loan-disbursement limit (৳' . number_format($my_limit, 0)
                        . ') does not cover this ৳' . number_format($principal, 0) . ' loan — a more senior officer must post it.');
                }
            } else {
                $no_limit_configured = $my_limit === null;
                $needs_approval = loanDisbursementApprovalRequiredForAll() || $over_limit || $no_limit_configured;
                if ($needs_approval) {
                    $req_id = submitPendingRequest('loan_disbursement', $principal, [
                        'customer_id' => $customer_id, 'supplier_id' => $supplier_id,
                        'principal_amount' => $principal, 'loan_date' => $loan_date,
                        'expected_return_date' => $expected_return_date, 'purpose' => $purpose, 'notes' => $notes,
                        'payment_method' => $payment_method, 'bank_account_id' => $bank_account_id,
                    ], [
                        'customer_id' => $customer_id,
                        'summary'     => "Loan ৳" . number_format($principal, 0) . " to {$party_name}" . ($purpose ? " — {$purpose}" : ''),
                        'maker_limit' => $my_limit,
                    ]);
                    if (!$req_id) throw new Exception('Could not queue this loan for approval. Please try again.');
                    $reason = $over_limit ? 'over ৳' . number_format($my_limit, 0) . ' limit' : ($no_limit_configured ? 'no loan-disbursement limit configured for this user' : 'loan disbursement approval policy');
                    auditLog('other', 'created', "Loan of ৳" . number_format($principal, 2) . " to {$party_name} queued for approval ({$reason}) by " . ($currentUser['display_name'] ?? 'user'));
                    $_SESSION['success_flash'] = "This loan (৳" . number_format($principal, 0) . ") was sent for approval. It will post once a senior officer approves it.";
                    header('Location: loan.php');
                    exit();
                }
            }
        }

        // ── Post directly ──
        $pdo = $db->getPdo();
        $pdo->beginTransaction();

        $date_prefix = date('Ymd', strtotime($loan_date));
        $last = $db->query("SELECT loan_number FROM loans WHERE loan_number LIKE ? ORDER BY id DESC LIMIT 1", ["LN-{$date_prefix}-%"])->first();
        $seq  = $last ? ((int)substr($last->loan_number, -4) + 1) : 1;
        $loan_number = sprintf("LN-%s-%04d", $date_prefix, $seq);

        $loan_creator_id = $user_id;
        if ($exec_pending_req_id && $preq && !empty($preq->maker_user_id)) {
            $loan_creator_id = (int)$preq->maker_user_id;
        }

        $loan_id = $db->insert('loans', [
            'loan_number' => $loan_number, 'customer_id' => $customer_id, 'supplier_id' => $supplier_id,
            'principal_amount' => $principal, 'amount_repaid' => 0, 'balance_due' => $principal,
            'loan_date' => $loan_date, 'expected_return_date' => $expected_return_date,
            'purpose' => $purpose ?: null, 'status' => 'active', 'notes' => $notes ?: null,
            'created_by_user_id' => $loan_creator_id, 'approved_by_user_id' => $user_id,
        ]);
        if (!$loan_id) throw new Exception('Failed to create the loan record.');

        $loan_account_id = ensureLoanAccounts();
        $deposit_account_id = null;
        if ($payment_method === 'Cash') {
            $deposit_account_id = $cash_account->id;
        } else {
            $selected_bank = $db->query("SELECT chart_of_account_id FROM bank_accounts WHERE id = ?", [$bank_account_id])->first();
            if (!$selected_bank) throw new Exception('Invalid bank account.');
            $deposit_account_id = $selected_bank->chart_of_account_id;
        }

        $journal_desc = "Loan {$loan_number} disbursed to {$party_name}" . ($purpose ? " — {$purpose}" : '');
        $journal_id = $db->insert('journal_entries', [
            'transaction_date' => $loan_date, 'description' => $journal_desc,
            'related_document_type' => 'loans', 'related_document_id' => $loan_id, 'created_by_user_id' => $user_id,
        ]);
        if (!$journal_id) throw new Exception('Failed to create the journal entry.');

        $dr_line_id = $db->insert('transaction_lines', ['journal_entry_id' => $journal_id, 'account_id' => $loan_account_id, 'debit_amount' => $principal, 'credit_amount' => 0, 'description' => $journal_desc]);
        if (!$dr_line_id) throw new Exception('Failed to post the debit line of the loan disbursement journal entry.');
        $cr_line_id = $db->insert('transaction_lines', ['journal_entry_id' => $journal_id, 'account_id' => $deposit_account_id, 'debit_amount' => 0, 'credit_amount' => $principal, 'description' => $journal_desc]);
        if (!$cr_line_id) throw new Exception('Failed to post the credit line of the loan disbursement journal entry — would leave the journal unbalanced.');
        $db->query("UPDATE loans SET journal_entry_id = ? WHERE id = ?", [$journal_id, $loan_id]);
        if ($db->error()) throw new Exception('Failed to link the journal entry to this loan.');

        // Best-effort bank bridge (money OUT — debit — never blocks the loan).
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
                        'transaction_date' => $loan_date, 'entry_type' => 'debit',
                        'bank_tx_account_id' => (int)$bm_bridge->bta_id, 'amount' => $principal,
                        'reference_number' => $loan_number, 'payee_payer_name' => $party_name,
                        'description' => "Loan disbursed — {$party_name} — {$loan_number}",
                    ], $user_id, $currentUser['display_name'] ?? 'System');
                }
            } catch (\Throwable $bm_ex) { error_log('BankManager bridge (loan.php): ' . $bm_ex->getMessage()); }
        }

        if ($exec_pending_req_id) {
            decidePendingRequest($exec_pending_req_id, 'approved', 'Posted by ' . ($currentUser['display_name'] ?? 'checker'), $loan_number);
        }

        $pdo->commit();

        auditLog('other', 'created',
            "Loan {$loan_number}: ৳" . number_format($principal, 2) . " disbursed to {$party_name}" . ($purpose ? " — {$purpose}" : '') . " by " . ($currentUser['display_name'] ?? 'user'));

        if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED
            && defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID')) {
            try {
                require_once '../core/classes/TelegramNotifier.php';
                (new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('payment')))->sendMessage(
                    "<b>💸 LOAN DISBURSED</b>\n───────────────────────────────\n\n"
                    . "• Loan: <code>{$loan_number}</code>\n• To: <b>" . htmlspecialchars($party_name) . "</b>\n"
                    . "• Amount: ৳" . number_format($principal, 2) . "\n"
                    . ($purpose ? "• Purpose: {$purpose}\n" : '')
                    . ($expected_return_date ? "• Expected return: " . date('d M Y', strtotime($expected_return_date)) . "\n" : '')
                    . "• Posted by: " . ($currentUser['display_name'] ?? 'user')
                );
            } catch (\Throwable $te) { error_log('loan.php Telegram: ' . $te->getMessage()); }
        }

        $_SESSION['success_flash'] = "Loan {$loan_number} posted — ৳" . number_format($principal, 2) . " disbursed to {$party_name}.";
        header('Location: view_loan.php?id=' . $loan_id);
        exit();

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// ── Data for render ──────────────────────────────────────────────────────
$customers = $db->query(
    "SELECT id, name, business_name, phone_number, business_partner_id FROM customers WHERE status = 'active' ORDER BY name ASC"
)->results();
$suppliers = $db->query(
    "SELECT id, company_name AS name, phone, mobile, business_partner_id FROM suppliers WHERE status = 'active' ORDER BY company_name ASC"
)->results();

$recent_loans = $db->query(
    "SELECT l.*, c.name AS customer_name, s.company_name AS supplier_name
     FROM loans l
     LEFT JOIN customers c ON c.id = l.customer_id
     LEFT JOIN suppliers s ON s.id = l.supplier_id
     ORDER BY l.created_at DESC LIMIT 20"
)->results();

$flash = $_SESSION['success_flash'] ?? null; unset($_SESSION['success_flash']);

require_once '../templates/header.php';
?>
<div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-hand-holding-dollar text-amber-600 mr-2"></i>New Loan</h1>
            <p class="text-gray-600 mt-1 text-sm">Cash advance to a customer, supplier, or related party — repayable, not tied to goods. Kept separate from their trading balance.</p>
        </div>
        <a href="dashboard.php" class="px-3 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"><i class="fas fa-gauge-high mr-1"></i>Loans Dashboard</a>
    </div>

    <?php if ($flash): ?><div class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-2.5 text-sm text-green-800"><i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-2.5 text-sm text-red-800"><i class="fas fa-triangle-exclamation mr-1"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($preq_error): ?><div class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-2.5 text-sm text-red-800"><i class="fas fa-ban mr-1"></i><?php echo htmlspecialchars($preq_error); ?></div><?php endif; ?>
    <?php if ($preq): ?><div class="mb-4 rounded-lg border border-blue-300 bg-blue-50 px-4 py-3 text-sm text-blue-900"><i class="fas fa-user-check mr-1"></i>Reviewing pending request #<?php echo (int)$preq->id; ?> — form prefilled below.</div><?php endif; ?>

    <form method="POST" id="loanForm" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-5">
        <input type="hidden" name="action" value="create_loan">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <?php if ($preq): ?><input type="hidden" name="pending_req_id" value="<?php echo (int)$preq->id; ?>"><?php endif; ?>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Borrower <span class="text-red-500">*</span></label>
            <div class="relative">
                <input type="text" id="ln_party_search" autocomplete="off" required
                       placeholder="Search customer or supplier by name, business, phone..."
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-amber-500"
                       oninput="lnSearchParties(this.value)" onfocus="lnSearchParties(this.value)">
                <div id="ln_party_dropdown" class="absolute z-50 left-0 right-0 mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-64 overflow-y-auto hidden"></div>
            </div>
            <input type="hidden" name="customer_id" id="ln_customer_id">
            <input type="hidden" name="supplier_id" id="ln_supplier_id">
            <p id="ln_partner_hint" class="mt-1 text-xs text-amber-700 hidden"></p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Loan Amount (৳) <span class="text-red-500">*</span></label>
                <input type="number" name="principal_amount" id="ln_principal" step="0.01" min="0.01" required class="w-full px-4 py-2 border rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Loan Date
                    <?php if ($is_admin): ?><span class="ml-1 text-[10px] font-semibold uppercase tracking-wide text-amber-600 bg-amber-50 border border-amber-200 rounded-full px-2 py-0.5 align-middle">Reconciliation</span><?php endif; ?>
                </label>
                <?php if ($is_admin): ?>
                <input type="date" name="loan_date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" class="w-full px-4 py-2 border rounded-lg">
                <?php else: ?>
                <input type="text" value="<?php echo date('d M Y'); ?> (today)" disabled class="w-full px-4 py-2 border rounded-lg bg-gray-50 text-gray-500">
                <?php endif; ?>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Expected Return Date <span class="text-gray-400 text-xs font-normal">(optional)</span></label>
                <input type="date" name="expected_return_date" min="<?php echo date('Y-m-d'); ?>" class="w-full px-4 py-2 border rounded-lg">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Purpose <span class="text-gray-400 text-xs font-normal">(e.g. "Tender participation — XYZ project")</span></label>
            <input type="text" name="purpose" maxlength="500" class="w-full px-4 py-2 border rounded-lg">
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Paid Out Via <span class="text-red-500">*</span></label>
                <select name="payment_method" id="ln_method" required class="w-full px-4 py-2 border rounded-lg" onchange="lnToggleBank()">
                    <option value="Cash">Cash</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Cheque">Cheque</option>
                </select>
            </div>
            <div id="ln_bank_box" class="hidden">
                <label class="block text-sm font-medium text-gray-700 mb-2">Bank Account <span class="text-red-500">*</span></label>
                <select name="bank_account_id" class="w-full px-4 py-2 border rounded-lg">
                    <option value="">Select bank account</option>
                    <?php foreach ($bank_accounts as $b): ?>
                    <option value="<?php echo (int)$b->id; ?>"><?php echo htmlspecialchars($b->bank_name . ' — ' . $b->account_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
            <input type="text" name="notes" class="w-full px-4 py-2 border rounded-lg" placeholder="Optional">
        </div>

        <div class="flex justify-end gap-3 pt-2 border-t">
            <button type="submit" class="px-5 py-2 bg-amber-600 text-white font-semibold rounded-lg hover:bg-amber-700 text-sm">
                <i class="fas fa-check mr-1"></i>Disburse Loan
            </button>
        </div>
    </form>

    <!-- Recent loans -->
    <div class="mt-6 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-700">Recent Loans</h2></div>
        <div class="overflow-x-auto">
        <?php if (!empty($recent_loans)): ?>
        <table class="min-w-full text-xs">
            <thead class="bg-gray-50 border-b"><tr>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Loan #</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Date</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Borrower</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase text-gray-500">Principal</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase text-gray-500">Balance Due</th>
                <th class="px-3 py-2 text-center text-[10px] font-semibold uppercase text-gray-500">Status</th>
                <th class="px-3 py-2 text-center text-[10px] font-semibold uppercase text-gray-500"></th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($recent_loans as $l): $due = (float)$l->balance_due; ?>
                <tr>
                    <td class="px-3 py-2 font-mono"><a href="view_loan.php?id=<?php echo (int)$l->id; ?>" class="text-amber-700 hover:underline"><?php echo htmlspecialchars($l->loan_number); ?></a></td>
                    <td class="px-3 py-2 text-gray-500"><?php echo date('d M Y', strtotime($l->loan_date)); ?></td>
                    <td class="px-3 py-2"><?php echo htmlspecialchars($l->customer_name ?? $l->supplier_name ?? '—'); ?><?php echo $l->supplier_id ? ' <span class="text-gray-400">(Supplier)</span>' : ''; ?></td>
                    <td class="px-3 py-2 text-right font-semibold">৳<?php echo number_format((float)$l->principal_amount, 2); ?></td>
                    <td class="px-3 py-2 text-right <?php echo $due > 0.01 ? 'text-amber-700 font-semibold' : 'text-gray-400'; ?>">৳<?php echo number_format($due, 2); ?></td>
                    <td class="px-3 py-2 text-center"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo $l->status === 'active' ? 'bg-blue-100 text-blue-700' : ($l->status === 'closed' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'); ?>"><?php echo strtoupper($l->status); ?></span></td>
                    <td class="px-3 py-2 text-center">
                        <?php if ($due > 0.01): ?><a href="repay_loan.php?loan_id=<?php echo (int)$l->id; ?>" class="px-2 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-md text-[11px] font-semibold hover:bg-amber-100">Collect</a><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-8 text-center text-gray-500 text-xs">No loans yet.</div>
        <?php endif; ?>
        </div>
    </div>

</div>
<script>
const lnParties = [
    <?php foreach ($customers as $c): ?>
    { type: 'customer', id: <?php echo (int)$c->id; ?>, name: <?php echo json_encode($c->name); ?>, business: <?php echo json_encode($c->business_name ?? ''); ?>, phone: <?php echo json_encode($c->phone_number ?? ''); ?>, isPartner: <?php echo !empty($c->business_partner_id) ? 'true' : 'false'; ?> },
    <?php endforeach; ?>
    <?php foreach ($suppliers as $s): ?>
    { type: 'supplier', id: <?php echo (int)$s->id; ?>, name: <?php echo json_encode($s->name); ?>, business: '', phone: <?php echo json_encode(trim(($s->phone ?? '') . ' ' . ($s->mobile ?? ''))); ?>, isPartner: <?php echo !empty($s->business_partner_id) ? 'true' : 'false'; ?> },
    <?php endforeach; ?>
];

function lnSearchParties(query) {
    const dd = document.getElementById('ln_party_dropdown');
    const q = query.toLowerCase().trim();
    const matches = q.length === 0 ? lnParties.slice(0, 20) : lnParties.filter(p =>
        p.name.toLowerCase().includes(q) || p.business.toLowerCase().includes(q) || p.phone.includes(q)
    ).slice(0, 20);
    dd.innerHTML = matches.length === 0 ? '<div class="px-4 py-3 text-sm text-gray-500">No customers or suppliers found</div>' :
        matches.map(p => `<div class="px-4 py-2 hover:bg-amber-50 cursor-pointer text-sm border-b border-gray-100" onclick="lnSelectParty('${p.type}', ${p.id})">
            <span class="font-medium text-gray-900">${p.name}</span>
            ${p.business ? `<span class="text-gray-400 text-xs ml-1">(${p.business})</span>` : ''}
            <span class="ml-1 text-[10px] px-1.5 py-0.5 ${p.type === 'customer' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'} rounded-full">${p.type === 'customer' ? 'Customer' : 'Supplier'}</span>
            ${p.isPartner ? '<span class="ml-1 text-[10px] px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded-full">Business Partner</span>' : ''}
            <span class="text-gray-400 text-xs ml-2">${p.phone}</span>
        </div>`).join('');
    dd.classList.remove('hidden');
}
function lnSelectParty(type, id) {
    const p = lnParties.find(x => x.type === type && x.id === id);
    if (!p) return;
    document.getElementById('ln_customer_id').value = type === 'customer' ? id : '';
    document.getElementById('ln_supplier_id').value = type === 'supplier' ? id : '';
    document.getElementById('ln_party_search').value = p.name + (type === 'supplier' ? ' (Supplier)' : '');
    document.getElementById('ln_party_dropdown').classList.add('hidden');
    const hint = document.getElementById('ln_partner_hint');
    if (p.isPartner) {
        hint.textContent = 'This party is a linked Business Partner — check Trading → Business Partners for their combined AR/AP position.';
        hint.classList.remove('hidden');
    } else {
        hint.classList.add('hidden');
    }
}
document.addEventListener('click', e => {
    if (!e.target.closest('#ln_party_search') && !e.target.closest('#ln_party_dropdown')) {
        document.getElementById('ln_party_dropdown').classList.add('hidden');
    }
});
function lnToggleBank() {
    const method = document.getElementById('ln_method').value;
    document.getElementById('ln_bank_box').classList.toggle('hidden', method === 'Cash');
}
document.getElementById('loanForm').addEventListener('submit', function(e) {
    if (!document.getElementById('ln_customer_id').value && !document.getElementById('ln_supplier_id').value) {
        e.preventDefault(); alert('Please select a borrower.');
    }
});
document.addEventListener('DOMContentLoaded', lnToggleBank);

<?php if ($preq):
    $pp = $preq->payload_arr;
    $preq_party_name = '';
    if (!empty($pp['customer_id'])) {
        $pc = $db->query("SELECT name FROM customers WHERE id = ?", [(int)$pp['customer_id']])->first();
        $preq_party_name = $pc->name ?? '';
    } elseif (!empty($pp['supplier_id'])) {
        $ps = $db->query("SELECT company_name AS name FROM suppliers WHERE id = ?", [(int)$pp['supplier_id']])->first();
        $preq_party_name = $ps->name ?? '';
    }
?>
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('ln_customer_id').value = <?php echo json_encode((string)($pp['customer_id'] ?? '')); ?>;
    document.getElementById('ln_supplier_id').value = <?php echo json_encode((string)($pp['supplier_id'] ?? '')); ?>;
    document.getElementById('ln_party_search').value = <?php echo json_encode($preq_party_name); ?>;
    document.getElementById('ln_principal').value = <?php echo json_encode((string)($pp['principal_amount'] ?? '')); ?>;
    const loanDateField = document.querySelector('input[name="loan_date"]');
    if (loanDateField) loanDateField.value = <?php echo json_encode($pp['loan_date'] ?? date('Y-m-d')); ?>;
    document.querySelector('input[name="expected_return_date"]').value = <?php echo json_encode($pp['expected_return_date'] ?? ''); ?>;
    document.querySelector('input[name="purpose"]').value = <?php echo json_encode($pp['purpose'] ?? ''); ?>;
    document.querySelector('input[name="notes"]').value = <?php echo json_encode($pp['notes'] ?? ''); ?>;
    document.getElementById('ln_method').value = <?php echo json_encode($pp['payment_method'] ?? 'Cash'); ?>;
    lnToggleBank();
    <?php if (!empty($pp['bank_account_id'])): ?>
    document.querySelector('select[name="bank_account_id"]').value = <?php echo json_encode((string)$pp['bank_account_id']); ?>;
    <?php endif; ?>
});
<?php endif; ?>
</script>
<?php require_once '../templates/footer.php'; ?>

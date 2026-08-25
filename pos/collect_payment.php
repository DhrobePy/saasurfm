<?php
/**
 * POS Credit Collection (Jul 2026) — collect a payment against a POS
 * customer's running pos_customer_ledger balance. Cash / Bank / bKash / Nagad / Card.
 */
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'accountspos-demra', 'accountspos-srg', 'dispatchpos-demra', 'dispatchpos-srg'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Collect POS Payment';
ensurePosLedgerTable();

$currentUser = getCurrentUser();
$user_id = (int)($currentUser['id'] ?? 0);
$user_name = $currentUser['display_name'] ?? 'Staff';
$user_role = $currentUser['role'] ?? '';
$is_admin = in_array($user_role, ['Superadmin', 'admin']);

// Resolve branch same way index.php does
$branch_id = null; $branch_name = 'Unknown Branch';
$employee_info = $db->query("SELECT e.branch_id, b.name as branch_name FROM employees e JOIN branches b ON e.branch_id = b.id WHERE e.user_id = ?", [$user_id])->first();
if ($employee_info && $employee_info->branch_id) {
    $branch_id = (int)$employee_info->branch_id;
    $branch_name = $employee_info->branch_name;
} elseif ($is_admin) {
    $default_branch = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY id LIMIT 1")->first();
    if ($default_branch) { $branch_id = (int)$default_branch->id; $branch_name = $default_branch->name; }
}

$error = null; $success = null;
$customer_id = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$customer = $customer_id ? $db->query("SELECT * FROM customers WHERE id = ? AND customer_type = 'POS'", [$customer_id])->first() : null;

function find_pos_account_id($db, $name_pattern, $account_types) {
    $account = $db->query(
        "SELECT id FROM chart_of_accounts WHERE name LIKE ? AND account_type IN (" . implode(',', array_fill(0, count($account_types), '?')) . ") AND is_active = 1 LIMIT 1",
        array_merge(["%{$name_pattern}%"], $account_types)
    )->first();
    return $account ? (int)$account->id : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'collect') {
    $amount = (float)($_POST['amount'] ?? 0);
    $method = $_POST['method'] ?? 'Cash';
    $reference = trim($_POST['reference'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');

    if (!$customer) { $error = 'Customer not found.'; }
    elseif ($amount <= 0) { $error = 'Enter a valid amount.'; }
    elseif (!$branch_id) { $error = 'Branch not identified.'; }
    elseif (in_array($method, ['Bank Deposit', 'Bank Transfer', 'bKash', 'Nagad']) && $reference === '') { $error = 'Reference / transaction ID is required.'; }
    else {
        $db->beginTransaction();
        try {
            // payment_number is never stored in its own column anywhere (it's
            // only embedded in free-text description fields below), so unlike
            // the other rand()-based generators found in this sweep it can't
            // throw a UNIQUE-constraint error — but random_int(1,9999) with no
            // collision check still risked two unrelated payments on the same
            // day carrying the identical-looking reference, confusing any
            // search/audit trail. Derive it from the journal entry's own real
            // auto-increment id instead — guaranteed unique, no lookup needed.

            // Determine debit account (where the money landed)
            $debit_account_id = null; $debit_account_name = '';
            if ($method === 'Cash') {
                $debit_account_id = getBranchPettyCashAccountId($branch_id);
                $debit_account_name = 'POS Cash';
                if (!$debit_account_id) throw new Exception('No active petty cash account configured for this branch.');
            } else {
                $debit_account_id = find_pos_account_id($db, 'Undeposited', ['Other Current Asset', 'Cash']);
                $debit_account_name = 'Undeposited Funds';
                if (!$debit_account_id) {
                    $debit_account_id = getBranchPettyCashAccountId($branch_id);
                    $debit_account_name = 'POS Cash';
                }
                if (!$debit_account_id) throw new Exception('No Undeposited Funds or petty cash account configured.');
            }
            $ar_account_id = find_pos_account_id($db, 'Accounts Receivable', ['Accounts Receivable']);
            if (!$ar_account_id) throw new Exception('No active Accounts Receivable account found.');

            $db->query(
                "INSERT INTO journal_entries (transaction_date, description, related_document_type, created_by_user_id) VALUES (CURDATE(), ?, 'PosPayment', ?)",
                ["POS Credit Payment from {$customer->name}", $user_id]
            );
            if ($db->error()) throw new Exception('Failed to create journal entry: ' . ($db->errorInfo()[2] ?? ''));
            $journal_id = $db->getPdo()->lastInsertId();
            $payment_number = 'PPAY-' . date('Ymd') . '-' . str_pad((string)$journal_id, 4, '0', STR_PAD_LEFT);
            $db->query("UPDATE journal_entries SET description = ? WHERE id = ?",
                ["POS Credit Payment - {$payment_number} - {$customer->name}", $journal_id]);

            $db->query("INSERT INTO transaction_lines (journal_entry_id, account_id, debit_amount, credit_amount, description) VALUES (?, ?, ?, 0, ?)",
                [$journal_id, $debit_account_id, $amount, "{$debit_account_name} - {$payment_number}"]);
            if ($db->error()) throw new Exception('Failed to post debit line: ' . ($db->errorInfo()[2] ?? ''));

            $db->query("INSERT INTO transaction_lines (journal_entry_id, account_id, debit_amount, credit_amount, description) VALUES (?, ?, 0, ?, ?)",
                [$journal_id, $ar_account_id, $amount, "POS AR reduced - {$payment_number} - {$customer->name}"]);
            if ($db->error()) throw new Exception('Failed to post credit line: ' . ($db->errorInfo()[2] ?? ''));

            postPosLedgerEntry($customer_id, 'payment', 0, $amount, [
                'branch_id' => $branch_id, 'reference_type' => 'pos_payment', 'reference_id' => null,
                'description' => "Payment {$payment_number} via {$method}" . ($reference ? " (Ref: {$reference})" : ''),
                'journal_entry_id' => $journal_id,
            ]);

            if ($method === 'Cash') {
                postBranchPettyCashTransaction($branch_id, 'cash_in', $amount, [
                    'reference_type' => 'pos_payment', 'reference_id' => null,
                    'description' => "POS payment {$payment_number} from {$customer->name}", 'payment_method' => $method,
                ]);
            }

            $db->commit();

            if (function_exists('auditLog')) {
                auditLog('pos', 'payment_collected', "POS payment {$payment_number} - ৳" . number_format($amount, 2) . " from {$customer->name}",
                    ['record_type' => 'pos_payment', 'reference_number' => $payment_number]);
            }

            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED && defined('TELEGRAM_BOT_TOKEN')) {
                try {
                    require_once '../core/classes/TelegramNotifier.php';
                    $msg = "<b>💰 POS PAYMENT COLLECTED</b>\n───────────────────────────────\n\n"
                         . "• {$payment_number}\n• Customer: <b>" . htmlspecialchars($customer->name) . "</b>\n"
                         . "• Amount: <b>৳" . number_format($amount, 2) . "</b> via {$method}\n• By: <b>{$user_name}</b>";
                    (new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('payment_received')))->sendMessage($msg);
                } catch (\Throwable $e) { error_log('POS payment Telegram: ' . $e->getMessage()); }
            }

            $success = "Payment {$payment_number} recorded — ৳" . number_format($amount, 2) . " collected from {$customer->name}.";
        } catch (Exception $e) {
            if ($db->getPdo()->inTransaction()) $db->rollback();
            $error = 'Could not record payment: ' . $e->getMessage();
        }
    }
}

$customers = $db->query("SELECT id, name, business_name, phone_number FROM customers WHERE customer_type = 'POS' AND status = 'active' ORDER BY name ASC")->results();
$outstanding = $customer ? getPosCustomerOutstanding($customer_id) : null;

require_once '../templates/header.php';
?>
<div class="max-w-lg mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Collect POS Payment</h1>
        <a href="customer_ledger.php" class="text-sm text-blue-600 hover:text-blue-800"><i class="fas fa-list mr-1"></i>Ledger</a>
    </div>

    <?php if ($success): ?>
    <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg text-green-800 text-sm"><i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-800 text-sm"><i class="fas fa-exclamation-triangle mr-1"></i><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-sm p-5">
        <form method="GET" class="mb-4" x-data>
            <label class="block text-sm font-bold text-gray-700 mb-2">Customer</label>
            <select name="customer_id" onchange="this.form.submit()" class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg">
                <option value="">— Select customer —</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?php echo $c->id; ?>" <?php echo $c->id == $customer_id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($c->name . ($c->phone_number ? ' - ' . $c->phone_number : '')); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($customer): ?>
        <div class="mb-4 p-3 bg-gray-50 rounded-lg flex justify-between items-center">
            <div>
                <div class="font-bold text-gray-900"><?php echo htmlspecialchars($customer->name); ?></div>
                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($customer->phone_number ?? ''); ?></div>
            </div>
            <div class="text-right">
                <div class="text-xs text-gray-500">Outstanding</div>
                <div class="text-xl font-bold text-red-600">৳<?php echo number_format($outstanding, 2); ?></div>
            </div>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="collect">
            <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">

            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">Amount *</label>
                <input type="number" name="amount" min="0.01" step="0.01" required value="<?php echo number_format($outstanding, 2, '.', ''); ?>"
                       class="w-full px-3 py-3 border-2 border-gray-300 rounded-lg text-lg font-bold">
            </div>

            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">Method *</label>
                <select name="method" id="method" onchange="document.getElementById('refBlock').style.display = ['Bank Deposit','Bank Transfer','bKash','Nagad'].includes(this.value) ? 'block' : 'none'; document.getElementById('bankBlock').style.display = ['Bank Deposit','Bank Transfer'].includes(this.value) ? 'block' : 'none';"
                        class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg">
                    <option value="Cash">💵 Cash</option>
                    <option value="Bank Deposit">🏦 Bank Deposit</option>
                    <option value="Bank Transfer">💳 Bank Transfer</option>
                    <option value="bKash">📱 bKash</option>
                    <option value="Nagad">📱 Nagad</option>
                    <option value="Card">💳 Card</option>
                </select>
            </div>

            <div id="bankBlock" style="display:none;">
                <label class="block text-sm font-bold text-gray-700 mb-2">Bank Name</label>
                <input type="text" name="bank_name" class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg">
            </div>

            <div id="refBlock" style="display:none;">
                <label class="block text-sm font-bold text-gray-700 mb-2">Reference / Transaction ID</label>
                <input type="text" name="reference" class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg">
            </div>

            <button type="submit" class="w-full py-3 bg-green-600 hover:bg-green-700 text-white font-bold rounded-lg text-lg">
                Record Payment
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php require_once '../templates/footer.php'; ?>

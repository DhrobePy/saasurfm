<?php
/**
 * bank/manage_accounts.php
 * Create and manage bank accounts — MODULE-OWN table (bank_tx_accounts)
 * Superadmin / Admin only
 */

require_once dirname(__DIR__) . '/core/init.php';
require_once dirname(__DIR__) . '/bank/BankManager.php';

restrict_access(['Superadmin', 'admin']);

$currentUser = getCurrentUser();
$userId      = $currentUser['id'];
$bankManager = new BankManager();
$db          = Database::getInstance();

$error    = '';
$editMode = false;
$editAcct = null;

if (!empty($_GET['edit'])) {
    $editAcct = $bankManager->getBankAccountById((int)$_GET['edit']);
    if ($editAcct) $editMode = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $bankName    = trim($_POST['bank_name']      ?? '');
        $branchName  = trim($_POST['branch_name']    ?? '');
        $accountName = trim($_POST['account_name']   ?? '');
        $accountNo   = trim($_POST['account_number'] ?? '');
        $accountType = $_POST['account_type']        ?? 'Checking';
        $address     = trim($_POST['address']        ?? '');
        $openBal     = (float)($_POST['opening_balance'] ?? 0);
        $status      = $_POST['status']              ?? 'active';
        $notes       = trim($_POST['notes']          ?? '');

        if (!$bankName || !$accountName || !$accountNo) {
            throw new Exception('Bank name, account name, and account number are required.');
        }

        if ($editMode && !empty($_POST['edit_id'])) {
            $db->query(
                "UPDATE bank_tx_accounts SET bank_name=?, branch_name=?, account_name=?,
                    account_number=?, account_type=?, address=?, status=?, notes=?, updated_at=NOW()
                 WHERE id=?",
                [$bankName, $branchName, $accountName, $accountNo,
                 $accountType, $address, $status, $notes, (int)$_POST['edit_id']]
            );
            $_SESSION['success_flash'] = 'Bank account updated.';
        } else {
            $db->query(
                "INSERT INTO bank_tx_accounts
                    (bank_name, branch_name, account_name, account_number,
                     account_type, address, opening_balance, status, notes, created_by_user_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?)",
                [$bankName, $branchName, $accountName, $accountNo,
                 $accountType, $address, $openBal, $status, $notes, (int)$userId]
            );
            $_SESSION['success_flash'] = 'Bank account created.';
        }
        header('Location: ' . url('bank/manage_accounts.php'));
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load all accounts with live balance from bank_transactions
$accounts = $db->query(
    "SELECT ba.*,
        COALESCE(
            SUM(CASE WHEN bt.entry_type='credit' AND bt.status='approved' THEN bt.amount ELSE 0 END) -
            SUM(CASE WHEN bt.entry_type='debit'  AND bt.status='approved' THEN bt.amount ELSE 0 END),
        0) AS module_balance,
        COUNT(bt.id) AS tx_count
     FROM bank_tx_accounts ba
     LEFT JOIN bank_transactions bt ON bt.bank_tx_account_id = ba.id
     GROUP BY ba.id
     ORDER BY ba.bank_name, ba.account_name"
)->results();

$pageTitle = 'Manage Bank Accounts';
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="w-full px-4 sm:px-6 lg:px-8 py-6">

    <?php echo display_message(); ?>
    <?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-5 rounded-r-lg">
        <p class="font-bold">Error</p><p><?php echo htmlspecialchars($error); ?></p>
    </div>
    <?php endif; ?>

    <div class="flex items-center gap-3 mb-6">
        <a href="<?php echo url('bank/index.php'); ?>" class="text-gray-400 hover:text-gray-600">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
            <i class="fas fa-piggy-bank text-primary-600"></i>
            <?php echo $editMode ? 'Edit Bank Account' : 'Manage Bank Accounts'; ?>
        </h1>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

        <!-- ── Form ── -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sticky top-4">
                <h2 class="font-semibold text-gray-800 mb-4 text-sm uppercase tracking-wide">
                    <?php echo $editMode ? 'Edit Account' : 'Add New Account'; ?>
                </h2>
                <form method="POST">
                    <?php if ($editMode): ?>
                    <input type="hidden" name="edit_id" value="<?php echo $editAcct->id; ?>">
                    <?php endif; ?>

                    <?php
                    $fields = [
                        ['bank_name',      'Bank Name',       true],
                        ['branch_name',    'Branch Name',     false],
                        ['account_name',   'Account Name',    true],
                        ['account_number', 'Account Number',  true],
                    ];
                    foreach ($fields as [$name, $label, $req]):
                        $val = $editMode ? htmlspecialchars($editAcct->{$name} ?? '') : '';
                    ?>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">
                            <?php echo $label; ?><?php if ($req): ?> <span class="text-red-500">*</span><?php endif; ?>
                        </label>
                        <input type="text" name="<?php echo $name; ?>" value="<?php echo $val; ?>"
                               <?php echo $req ? 'required' : ''; ?>
                               class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                    </div>
                    <?php endforeach; ?>

                    <div class="mb-3">
                        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Account Type</label>
                        <select name="account_type"
                                class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                            <?php foreach (['Checking','Savings','Loan','Credit','FDR','Other'] as $at): ?>
                            <option value="<?php echo $at; ?>"
                                    <?php echo ($editMode && $editAcct->account_type === $at) ? 'selected' : ''; ?>>
                                <?php echo $at; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (!$editMode): ?>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Opening Balance (৳)</label>
                        <input type="number" name="opening_balance" step="0.01" value="0"
                               class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Address</label>
                        <textarea name="address" rows="2"
                                  class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none resize-none"><?php echo $editMode ? htmlspecialchars($editAcct->address ?? '') : ''; ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Notes</label>
                        <textarea name="notes" rows="2"
                                  class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none resize-none"><?php echo $editMode ? htmlspecialchars($editAcct->notes ?? '') : ''; ?></textarea>
                    </div>

                    <?php if ($editMode): ?>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Status</label>
                        <select name="status"
                                class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                            <?php foreach (['active','inactive','closed'] as $st): ?>
                            <option value="<?php echo $st; ?>"
                                    <?php echo $editAcct->status === $st ? 'selected' : ''; ?>>
                                <?php echo ucfirst($st); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="flex gap-2 mt-4">
                        <button type="submit"
                                class="flex-1 bg-primary-600 text-white py-2 rounded-lg text-sm font-semibold hover:bg-primary-700 transition-colors">
                            <?php echo $editMode ? 'Save Changes' : 'Create Account'; ?>
                        </button>
                        <?php if ($editMode): ?>
                        <a href="<?php echo url('bank/manage_accounts.php'); ?>"
                           class="px-3 py-2 border border-gray-200 text-gray-600 rounded-lg text-sm hover:bg-gray-50">
                            Cancel
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- ── Account List ── -->
        <div class="lg:col-span-3">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100">
                    <p class="text-sm font-semibold text-gray-700"><?php echo count($accounts); ?> bank account(s)</p>
                </div>
                <div class="divide-y divide-gray-50">
                    <?php if (empty($accounts)): ?>
                    <p class="text-center text-gray-400 py-10 text-sm">No bank accounts yet. Add one using the form.</p>
                    <?php endif; ?>
                    <?php foreach ($accounts as $a):
                        $bal = (float)$a->opening_balance + (float)$a->module_balance;
                    ?>
                    <div class="p-4 hover:bg-gray-50 flex items-start gap-4">
                        <div class="w-10 h-10 rounded-xl bg-primary-100 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-university text-primary-600"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="font-semibold text-gray-800 text-sm"><?php echo htmlspecialchars($a->bank_name); ?></p>
                                <?php if ($a->branch_name): ?>
                                <span class="text-xs text-gray-400">— <?php echo htmlspecialchars($a->branch_name); ?></span>
                                <?php endif; ?>
                                <span class="px-1.5 py-0.5 text-xs rounded-full ml-auto
                                    <?php echo $a->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>">
                                    <?php echo ucfirst($a->status); ?>
                                </span>
                            </div>
                            <p class="text-xs text-gray-500 mt-0.5">
                                <?php echo htmlspecialchars($a->account_name); ?>
                                <span class="font-mono ml-1"><?php echo htmlspecialchars($a->account_number); ?></span>
                            </p>
                            <p class="text-xs text-gray-400">
                                <?php echo $a->account_type; ?>
                                &nbsp;·&nbsp; <?php echo $a->tx_count; ?> transaction(s)
                            </p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="font-bold text-sm <?php echo $bal >= 0 ? 'text-gray-900' : 'text-red-600'; ?>">
                                ৳<?php echo number_format($bal, 2); ?>
                            </p>
                            <p class="text-xs text-gray-400 mb-2">current balance</p>
                            <div class="flex gap-3">
                                <a href="<?php echo url('bank/statement.php'); ?>?account_id=<?php echo $a->id; ?>"
                                   class="text-xs text-green-600 hover:underline">
                                    <i class="fas fa-file-invoice-dollar"></i> Statement
                                </a>
                                <a href="?edit=<?php echo $a->id; ?>"
                                   class="text-xs text-primary-600 hover:underline">
                                    <i class="fas fa-pencil-alt"></i> Edit
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
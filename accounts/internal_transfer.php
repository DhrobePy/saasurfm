<?php
require_once '../core/init.php';

// --- SECURITY ---
$allowed_roles = [
    'Superadmin',
    'admin',
    'Accounts',
    'accounts-rampura',
    'accounts-srg',
    'accounts-demra',
    'accountspos-srg',
];
restrict_access($allowed_roles);

// Get the $db instance
global $db;
$pageTitle = 'Internal Fund Transfer';
$error = null;
$success = null;
$cash_accounts = [];
$bank_accounts = [];
$employees = [];

// --- RECTIFICATION: Get PDO instance early ---
$pdo = null;
try {
    $pdo = $db->getPdo();
} catch (Exception $e) {
    $error = "Database connection error: " . $e->getMessage();
}


// --- DATA: GET ACCOUNTS & EMPLOYEES ---
if (!$error) {
    try {
        // Fetch Cash/Petty Cash accounts with branch info
        $cash_accounts = $db->query(
            "SELECT coa.id, coa.name, coa.branch_id, b.name as branch_name
             FROM chart_of_accounts coa
             LEFT JOIN branches b ON coa.branch_id = b.id
             WHERE coa.account_type IN ('Petty Cash', 'Cash') AND coa.status = 'active'
             ORDER BY b.name ASC, coa.name ASC"
        )->results();

        // Fetch Bank accounts
        $bank_accounts = $db->query(
            "SELECT id, name, branch_id FROM chart_of_accounts
             WHERE account_type = 'Bank' AND status = 'active'
             ORDER BY name ASC"
        )->results();

        // Fetch active employees
        $employees = $db->query(
            "SELECT id, CONCAT(first_name, ' ', last_name) as full_name
             FROM employees WHERE status = 'active'
             ORDER BY first_name ASC, last_name ASC"
        )->results();

    } catch (Exception $e) {
        $error = "Error loading data: " . $e->getMessage();
    }
}


// --- LOGIC: HANDLE POST REQUEST (PROCESS TRANSFER) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && $pdo) {
    try {
        // Start transaction
        $pdo->beginTransaction();

        $amount = (float)$_POST['amount'];
        $from_account_id = (int)$_POST['from_account_id'];
        $to_account_id = (int)$_POST['to_account_id'];
        $responsible_employee_id = (int)$_POST['responsible_employee_id'];
        $transfer_date = !empty($_POST['transfer_date']) ? $_POST['transfer_date'] : date('Y-m-d');
        $transfer_datetime = $transfer_date . ' ' . date('H:i:s');
        $memo = trim($_POST['memo']);

        // Basic Validation
        if ($amount <= 0) {
            throw new Exception("Transfer amount must be positive.");
        }
        if ($from_account_id === $to_account_id) {
            throw new Exception("Source and Destination accounts cannot be the same.");
        }
        if (empty($from_account_id) || empty($to_account_id) || empty($responsible_employee_id)) {
            throw new Exception("Please select source account, destination account, and responsible employee.");
        }

        // Fetch account details
        $from_acc = $db->query(
            "SELECT id, name, account_type, branch_id FROM chart_of_accounts WHERE id = ?", 
            [$from_account_id]
        )->first();
        
        $to_acc = $db->query(
            "SELECT id, name, account_type, branch_id FROM chart_of_accounts WHERE id = ?", 
            [$to_account_id]
        )->first();
        
        if (!$from_acc || !$to_acc) {
            throw new Exception("Invalid source or destination account selected.");
        }

        // Check if this is a petty cash account
        $from_is_petty_cash = in_array($from_acc->account_type, ['Petty Cash', 'Cash']);
        $to_is_petty_cash = in_array($to_acc->account_type, ['Petty Cash', 'Cash']);

        // --- Create Journal Entry ---
        $journal_desc = 'Internal Transfer: ' . $from_acc->name . ' to ' . $to_acc->name;
        if (!empty($memo)) {
             $journal_desc .= ' - ' . $memo;
        }

        $journal_id = $db->insert('journal_entries', [
            'transaction_date' => $transfer_date,
            'description' => $journal_desc,
            'related_document_type' => 'InternalTransfer',
            'created_by_user_id' => $_SESSION['user_id'],
            'responsible_employee_id' => $responsible_employee_id
        ]);

        if (!$journal_id) {
            throw new Exception("Failed to create journal entry header.");
        }

        // --- Create Transaction Lines (Debit & Credit) ---
        // Debit the Destination Account (Increase)
        $db->insert('transaction_lines', [
            'journal_entry_id' => $journal_id,
            'account_id' => $to_account_id,
            'debit_amount' => $amount,
            'credit_amount' => 0.00,
            'description' => $memo ?: 'Internal transfer received'
        ]);

        // Credit the Source Account (Decrease)
        $db->insert('transaction_lines', [
            'journal_entry_id' => $journal_id,
            'account_id' => $from_account_id,
            'debit_amount' => 0.00,
            'credit_amount' => $amount,
            'description' => $memo ?: 'Internal transfer sent'
        ]);

        // --- INTEGRATION: Post to Petty Cash System ---
        
        // If FROM account is petty cash - record cash OUT
        if ($from_is_petty_cash && $from_acc->branch_id) {
            // Get petty cash account
            $petty_account = $db->query(
                "SELECT id, current_balance FROM branch_petty_cash_accounts 
                 WHERE branch_id = ? AND status = 'active'", 
                [$from_acc->branch_id]
            )->first();
            
            if ($petty_account) {
                $new_balance = floatval($petty_account->current_balance) - $amount;
                
                // Insert transaction
                $db->insert('branch_petty_cash_transactions', [
                    'branch_id' => $from_acc->branch_id,
                    'account_id' => $petty_account->id,
                    'transaction_date' => $transfer_datetime,
                    'transaction_type' => 'transfer_out',
                    'amount' => $amount,
                    'balance_after' => $new_balance,
                    'reference_type' => 'internal_transfer',
                    'reference_id' => $journal_id,
                    'description' => 'Transfer out: ' . $journal_desc,
                    'payment_method' => 'Cash',
                    'created_by_user_id' => $_SESSION['user_id']
                ]);
                
                // Update account balance
                $db->query(
                    "UPDATE branch_petty_cash_accounts 
                     SET current_balance = ?, updated_at = NOW() 
                     WHERE id = ?",
                    [$new_balance, $petty_account->id]
                );
            }
        }
        
        // If TO account is petty cash - record cash IN
        if ($to_is_petty_cash && $to_acc->branch_id) {
            // Get petty cash account
            $petty_account = $db->query(
                "SELECT id, current_balance FROM branch_petty_cash_accounts 
                 WHERE branch_id = ? AND status = 'active'", 
                [$to_acc->branch_id]
            )->first();
            
            if ($petty_account) {
                $new_balance = floatval($petty_account->current_balance) + $amount;
                
                // Insert transaction
                $db->insert('branch_petty_cash_transactions', [
                    'branch_id' => $to_acc->branch_id,
                    'account_id' => $petty_account->id,
                    'transaction_date' => $transfer_datetime,
                    'transaction_type' => 'transfer_in',
                    'amount' => $amount,
                    'balance_after' => $new_balance,
                    'reference_type' => 'internal_transfer',
                    'reference_id' => $journal_id,
                    'description' => 'Transfer in: ' . $journal_desc,
                    'payment_method' => 'Cash',
                    'created_by_user_id' => $_SESSION['user_id']
                ]);
                
                // Update account balance
                $db->query(
                    "UPDATE branch_petty_cash_accounts 
                     SET current_balance = ?, updated_at = NOW() 
                     WHERE id = ?",
                    [$new_balance, $petty_account->id]
                );
            }
        }

        // If all good, commit
        $pdo->commit();
        $_SESSION['success_flash'] = 'Internal transfer recorded successfully. Petty cash accounts updated.';
        header('Location: internal_transfer.php');
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Transfer Failed: " . $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$pdo) {
    $error = "Database connection error. Cannot process transfer.";
}


// --- Include Header ---
require_once '../templates/header.php';
?>

<!-- ======================================== -->
<!-- 1. PAGE HEADER -->
<!-- ======================================== -->
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Internal Fund Transfer</h1>
        <p class="text-lg text-gray-600 mt-1">
            Record movement of funds between your company's cash and bank accounts.
        </p>
    </div>
</div>

<!-- ======================================== -->
<!-- 2. ERROR / SUCCESS DISPLAY -->
<!-- ======================================== -->
<?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg" role="alert">
        <p class="font-bold">Error</p>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>
<?php endif; ?>


<!-- ======================================== -->
<!-- 3. TRANSFER FORM -->
<!-- ======================================== -->
<div class="bg-white rounded-lg shadow-md p-6">

    <form action="internal_transfer.php" method="POST" class="space-y-6">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Amount -->
            <div>
                <label for="amount" class="block text-sm font-medium text-gray-700 mb-1">Amount (BDT) <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" id="amount" name="amount" required min="0.01"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                       placeholder="e.g., 15000.00">
            </div>

             <!-- Transfer Date -->
            <div>
                <label for="transfer_date" class="block text-sm font-medium text-gray-700 mb-1">Transfer Date</label>
                <input type="date" id="transfer_date" name="transfer_date"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                       value="<?php echo date('Y-m-d'); ?>">
                 <p class="mt-1 text-xs text-gray-500">Defaults to today.</p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- From Account (Source) -->
            <div>
                <label for="from_account_id" class="block text-sm font-medium text-gray-700 mb-1">From Account (Source) <span class="text-red-500">*</span></label>
                <select id="from_account_id" name="from_account_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors">
                    <option value="" disabled selected>-- Select Source --</option>
                    <optgroup label="Cash & Petty Cash">
                        <?php foreach ($cash_accounts as $acc): ?>
                            <option value="<?php echo $acc->id; ?>">
                                <?php echo htmlspecialchars($acc->name); ?>
                                <?php if ($acc->branch_name): ?>
                                    (<?php echo htmlspecialchars($acc->branch_name); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Bank Accounts">
                         <?php foreach ($bank_accounts as $acc): ?>
                            <option value="<?php echo $acc->id; ?>"><?php echo htmlspecialchars($acc->name); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                <p class="mt-1 text-xs text-gray-500">Where the money is coming FROM.</p>
            </div>

            <!-- To Account (Destination) -->
             <div>
                <label for="to_account_id" class="block text-sm font-medium text-gray-700 mb-1">To Account (Destination) <span class="text-red-500">*</span></label>
                <select id="to_account_id" name="to_account_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors">
                    <option value="" disabled selected>-- Select Destination --</option>
                     <optgroup label="Bank Accounts">
                         <?php foreach ($bank_accounts as $acc): ?>
                            <option value="<?php echo $acc->id; ?>"><?php echo htmlspecialchars($acc->name); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Cash & Petty Cash">
                        <?php foreach ($cash_accounts as $acc): ?>
                            <option value="<?php echo $acc->id; ?>">
                                <?php echo htmlspecialchars($acc->name); ?>
                                <?php if ($acc->branch_name): ?>
                                    (<?php echo htmlspecialchars($acc->branch_name); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                 <p class="mt-1 text-xs text-gray-500">Where the money is going TO.</p>
            </div>
        </div>

        <!-- Person Responsible -->
        <div>
            <label for="responsible_employee_id" class="block text-sm font-medium text-gray-700 mb-1">Person Handling Transfer <span class="text-red-500">*</span></label>
            <select id="responsible_employee_id" name="responsible_employee_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors">
                <option value="" disabled selected>-- Select Employee --</option>
                <?php foreach ($employees as $employee): ?>
                    <option value="<?php echo $employee->id; ?>"><?php echo htmlspecialchars($employee->full_name); ?></option>
                <?php endforeach; ?>
            </select>
             <p class="mt-1 text-xs text-gray-500">Employee who physically carried out the transfer (e.g., deposited cash).</p>
        </div>

        <!-- Memo / Reference -->
        <div>
            <label for="memo" class="block text-sm font-medium text-gray-700 mb-1">Memo / Reference <span class="text-gray-400">(Optional)</span></label>
            <input type="text" id="memo" name="memo" maxlength="255"
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                   placeholder="e.g., EOD Cash Deposit Oct 23">
        </div>

        <!-- Submit Button -->
        <div class="pt-6 border-t border-gray-200 flex justify-end">
            <button type="submit"
                    class="px-6 py-2.5 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <i class="fas fa-exchange-alt mr-2"></i>Record Transfer
            </button>
        </div>
    </form>
</div>

<!-- ======================================== -->
<!-- 4. HELP SECTION -->
<!-- ======================================== -->
<div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-5">
    <div class="flex">
        <div class="flex-shrink-0">
             <i class="fas fa-info-circle text-blue-600"></i>
        </div>
        <div class="ml-3">
            <h3 class="text-sm font-medium text-blue-800">How Internal Transfers Work</h3>
            <div class="mt-2 text-sm text-blue-700 space-y-1">
                <p>• This form records the movement of funds between your own cash or bank accounts.</p>
                <p>• Example: Moving cash from a petty cash box to deposit into the bank.</p>
                <p>• The system automatically creates a balanced journal entry:</p>
                <p class="ml-4"> - <span class="font-semibold">Debit:</span> The Destination Account (balance increases)</p>
                <p class="ml-4"> - <span class="font-semibold">Credit:</span> The Source Account (balance decreases)</p>
                 <p>• <span class="font-semibold text-green-700">✓ NEW:</span> If transferring from/to petty cash, the system also updates the real-time petty cash tracking!</p>
                 <p>• Select the employee who physically handled the funds for audit trail purposes.</p>
            </div>
        </div>
    </div>
</div>


<?php
require_once '../templates/footer.php';
?>
<?php
require_once '../core/init.php';

// --- SECURITY & CONTEXT ---
$allowed_roles = [
    'Superadmin', 'admin',
    'Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra',
    'accountspos-demra', 'accountspos-srg',
];
restrict_access($allowed_roles);

// Get DB instance and current user info
global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = "Accountant Dashboard";
$error = null;

// --- DETERMINE BRANCH & SCOPE ---
$branch_id = null;
$branch_name = 'All Branches (Company-Wide)';
$branch_code_filter = null; // SQL LIKE filter (e.g., '%SRG%')

$is_admin_scope = in_array($user_role, ['Superadmin', 'admin', 'Accounts']);

if (!$is_admin_scope) {
    // Branch-specific user
    try {
        $employee_info = $db->query(
            "SELECT e.branch_id, b.name as branch_name, b.code as branch_code
             FROM employees e
             JOIN branches b ON e.branch_id = b.id
             WHERE e.user_id = ?",
            [$user_id]
        )->first();
        
        if ($employee_info && $employee_info->branch_id) {
            $branch_id = $employee_info->branch_id;
            $branch_name = $employee_info->branch_name;
            $branch_code_filter = "%" . $employee_info->branch_code . "%";
            $pageTitle .= ' - ' . htmlspecialchars($branch_name);
        } else {
            throw new Exception("Your user account is not linked to a specific branch.");
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// --- DATA FETCHING ---
$key_accounts = [];
$receivables_list = [];
$receivables_total = 0.00;

if (!$error) {
    try {
        // --- 1. Fetch Key Account Balances ---
        $account_sql = "
            SELECT 
                coa.id, coa.name, coa.account_type, coa.normal_balance,
                -- Calculate current balance from transaction_lines
                COALESCE(
                    (SELECT SUM(CASE 
                                WHEN coa.normal_balance = 'Debit' THEN (tl.debit_amount - tl.credit_amount)
                                ELSE (tl.credit_amount - tl.debit_amount)
                            END)
                     FROM transaction_lines tl
                     WHERE tl.account_id = coa.id
                    ), 0.00
                ) as current_balance
            FROM 
                chart_of_accounts coa
            WHERE 
                coa.status = 'active' AND coa.is_active = 1
        ";
        
        $params = [];
        if ($is_admin_scope) {
            // Admin: Show key asset accounts (Bank, Cash, Petty Cash)
            $account_sql .= " AND coa.account_type_group = 'Asset' AND coa.account_type IN ('Bank', 'Petty Cash', 'Cash')";
        } else {
            // Branch User: Show only accounts matching their branch code (e.g., "POS Cash - SRG")
            $account_sql .= " AND coa.name LIKE ?";
            $params[] = $branch_code_filter;
        }
        $account_sql .= " ORDER BY coa.account_type, coa.name";
        
        $key_accounts = $db->query($account_sql, $params)->results();
        
        // --- 2. Fetch Receivables List ---
        // This is always company-wide in our current schema, as customers are not tied to branches
        $ar_account = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Accounts Receivable' AND status = 'active' LIMIT 1")->first();
        if ($ar_account) {
            $balance_result = $db->query(
                "SELECT SUM(debit_amount - credit_amount) as balance 
                 FROM transaction_lines WHERE account_id = ?",
                [$ar_account->id]
            )->first();
            $receivables_total = $balance_result->balance ?? 0.00;
        }
        
        // Get the list of all customers who owe money
        $receivables_list = $db->query(
            "SELECT id, uuid, name, business_name, phone_number, email, current_balance
             FROM customers
             WHERE current_balance > 0 AND status = 'active'
             ORDER BY current_balance DESC, name ASC"
        )->results();
        
        // Note: $receivables_total from transactions and SUM(current_balance) from customers *should* match
        // if all logic is correct. We'll display the ledger-based total.

    } catch (Exception $e) {
        $error = "Error fetching account data: " . $e->getMessage();
        error_log("Account Dashboard Error: " . $e->getMessage());
    }
}

// Include Header
require_once '../templates/header.php';
?>

<!-- ======================================== -->
<!-- Page Header -->
<!-- ======================================== -->
<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">
        Key account balances and receivable reports for: <strong class="text-primary-600"><?php echo htmlspecialchars($branch_name); ?></strong>
    </p>
</div>

<!-- ======================================== -->
<!-- Error Display -->
<!-- ======================================== -->
<?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
        <p class="font-bold">Error</p>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>
<?php endif; ?>

<?php if (!$error): ?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Left Column: Key Account Balances -->
    <div class="lg:col-span-1 space-y-6">
    
        <!-- Key Balances Card -->
        <div class="bg-white rounded-lg shadow-md border border-gray-200">
            <div class="p-5 border-b border-gray-200">
                <h2 class="text-xl font-bold text-gray-800 flex items-center">
                    <i class="fas fa-wallet text-primary-600 mr-2"></i>
                    Key Account Balances
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    <?php echo $is_admin_scope ? 'Company-wide cash & bank totals.' : 'Balances for your branch accounts.'; ?>
                </p>
            </div>
            <div class="divide-y divide-gray-100 max-h-[400px] overflow-y-auto">
                <?php if (empty($key_accounts)): ?>
                    <p class="p-5 text-center text-gray-500">No specific accounts found for your branch.</p>
                <?php else: ?>
                    <?php foreach ($key_accounts as $account): ?>
                        <a href="account_statement.php?account_id=<?php echo $account->id; ?>" class="block p-4 hover:bg-gray-50 transition-colors">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($account->name); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($account->account_type); ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-lg font-bold text-gray-800 font-mono">
                                        ৳<?php echo number_format($account->current_balance, 2); ?>
                                    </p>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payables Placeholder -->
        <div class="bg-white rounded-lg shadow-md border border-gray-200">
             <div class="p-5 border-b border-gray-200">
                <h2 class="text-xl font-bold text-gray-800 flex items-center">
                    <i class="fas fa-file-invoice-dollar text-red-600 mr-2"></i>
                    Accounts Payable
                </h2>
             </div>
             <div class="p-5 text-center text-gray-500">
                 <i class="fas fa-truck-loading text-3xl text-gray-300 mb-3"></i>
                 <p class="font-medium">Supplier & Bills module not yet implemented.</p>
                 <p class="text-sm">This card will show outstanding payables.</p>
             </div>
        </div>
        
    </div>

    <!-- Right Column: Receivables -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-lg shadow-md border border-gray-200">
            <div class="p-5 border-b border-gray-200">
                 <div class="flex justify-between items-center">
                     <div>
                        <h2 class="text-xl font-bold text-gray-800 flex items-center">
                            <i class="fas fa-users text-green-600 mr-2"></i>
                            Accounts Receivable
                        </h2>
                        <p class="text-sm text-gray-500 mt-1">Showing all customers with an outstanding balance.</p>
                     </div>
                     <div class="text-right">
                         <p class="text-xs text-gray-500 uppercase font-medium">Total Owed</p>
                         <p class="text-2xl font-bold text-green-600 font-mono">৳<?php echo number_format($receivables_total, 2); ?></p>
                     </div>
                 </div>
            </div>
            <!-- Receivables List Table -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance (BDT)</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($receivables_list)): ?>
                            <tr>
                                <td colspan="4" class="px-6 py-10 text-center text-gray-500">
                                    <i class="fas fa-check-circle text-3xl text-green-400 mb-3"></i>
                                    <p>No outstanding receivables. Well done!</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($receivables_list as $customer): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($customer->name); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($customer->business_name ?? 'N/A'); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-600"><?php echo htmlspecialchars($customer->phone_number); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($customer->email ?? 'N/A'); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 text-right font-mono font-bold">
                                        <?php echo number_format($customer->current_balance, 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-3">
                                        <!-- Link to Customer Profile -->
                                        <a href="../customers/view.php?uuid=<?php echo $customer->uuid; ?>" class="text-blue-600 hover:text-blue-900" title="View Customer Profile">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <!-- Future: Link to "Receive Payment" -->
                                        <a href="#" class="text-green-600 hover:text-green-900" title="Receive Payment (Coming Soon)">
                                            <i class="fas fa-hand-holding-usd"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

</div> <!-- End Alpine.js scope -->

<?php
// --- Include Footer ---
require_once '../templates/footer.php';
?>

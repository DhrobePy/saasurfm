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
    'accountspos-demra',
    'accountspos-srg',
];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Accounting Dashboard';

// --- DATA: Get Date Ranges ---
$today = date('Y-m-d');
// ===== THIS LINE WAS MISSING. IT IS NOW FIXED. =====
$month_start = date('Y-m-01'); 
// =======================================================
$default_30_days_ago = date('Y-m-d', strtotime('-30 days'));

$date_from_pending = $_GET['date_from_pending'] ?? $default_30_days_ago;
$date_to_pending = $_GET['date_to_pending'] ?? $today;

$date_from_shipped = $_GET['date_from_shipped'] ?? $default_30_days_ago;
$date_to_shipped = $_GET['date_to_shipped'] ?? $today;

// === REQUIREMENT 1: Pending Approval Notification ===
$pending_approvals = $db->query(
    "SELECT COUNT(id) as count 
     FROM credit_orders 
     WHERE status = 'pending_approval' OR status = 'escalated'"
)->first()->count ?? 0;

// === REQUIREMENT 2: Credit Limit Risk ===
$high_utilization_customers = $db->query(
    "SELECT id, name, business_name, current_balance, credit_limit, 
           (current_balance / credit_limit * 100) AS utilization_percent 
     FROM customers 
     WHERE customer_type = 'Credit' 
       AND credit_limit > 0 
       AND (current_balance / credit_limit) > 0.8 
     ORDER BY utilization_percent DESC 
     LIMIT 5"
)->results();

// === REQUIREMENT 3: Pending Orders (Not Shipped/Delivered) ===
$pending_orders = $db->query(
    "SELECT co.id, co.order_number, co.order_date, co.total_amount, co.status, c.name as customer_name, b.name as branch_name 
     FROM credit_orders co
     JOIN customers c ON co.customer_id = c.id
     LEFT JOIN branches b ON co.assigned_branch_id = b.id
     WHERE co.status NOT IN ('shipped', 'delivered', 'cancelled', 'rejected') 
       AND co.order_date BETWEEN ? AND ?
     ORDER BY co.order_date DESC",
    [$date_from_pending, $date_to_pending]
)->results();

// === REQUIREMENT 4: Shipped & Delivered Orders ===
$shipped_orders = $db->query(
    "SELECT co.id, co.order_number, co.order_date, co.total_amount, co.status, c.name as customer_name, b.name as branch_name 
     FROM credit_orders co
     JOIN customers c ON co.customer_id = c.id
     LEFT JOIN branches b ON co.assigned_branch_id = b.id
     WHERE co.status IN ('shipped', 'delivered') 
       AND co.order_date BETWEEN ? AND ?
     ORDER BY co.order_date DESC",
    [$date_from_shipped, $date_to_shipped]
)->results();

// === REQUIREMENT 5: POS Summary (Today) ===
$pos_stats = $db->query(
    "SELECT COUNT(id) as total_sales, SUM(total_amount) as total_amount 
     FROM orders 
     WHERE order_type = 'POS' AND DATE(order_date) = CURDATE()"
)->first();
$pos_cash = $db->query(
    "SELECT SUM(amount) as total_cash_in 
     FROM branch_petty_cash_transactions 
     WHERE transaction_type = 'cash_in' AND DATE(transaction_date) = CURDATE()"
)->first();

// === REQUIREMENT 6: Today's Journal (Fund Movement) ===
$todays_journal = $db->query(
    "SELECT je.id, je.transaction_date, je.description, tl.debit_amount, tl.credit_amount, coa.name as account_name, b.name as branch_name 
     FROM transaction_lines tl
     JOIN journal_entries je ON tl.journal_entry_id = je.id
     JOIN chart_of_accounts coa ON tl.account_id = coa.id
     LEFT JOIN branches b ON je.branch_id = b.id
     WHERE DATE(je.transaction_date) = CURDATE()
     ORDER BY je.id ASC, tl.id ASC"
)->results();

// === REQUIREMENT 7 & 8 (RECTIFIED): Bank & Cash Balances from LEDGER ===
// 1. Get ALL Bank & Cash Ledger Balances
$all_asset_accounts = $db->query(
    "SELECT 
        coa.id, 
        coa.name as account_name, 
        coa.account_type,
        b.name as branch_name,
        ba.account_number, 
        ba.bank_name,
        bpca.current_balance as cash_box_memo_balance, -- This is the memo balance from the cash box
        (SELECT SUM(tl.debit_amount - tl.credit_amount) 
         FROM transaction_lines tl 
         WHERE tl.account_id = coa.id) as ledger_balance
     FROM chart_of_accounts coa
     LEFT JOIN bank_accounts ba ON coa.id = ba.chart_of_account_id
     LEFT JOIN branch_petty_cash_accounts bpca ON coa.id = bpca.chart_of_account_id
     LEFT JOIN branches b ON coa.branch_id = b.id
     WHERE coa.account_type IN ('Bank', 'Petty Cash', 'Cash')
     ORDER BY coa.account_type, b.name, coa.name"
)->results();

$total_bank_balance = 0;
$total_cash_balance = 0;
$bank_accounts_list = [];
$cash_accounts_list = [];
$bank_ledger_total = 0;

foreach ($all_asset_accounts as $account) {
    if ($account->account_type == 'Bank') {
        $total_bank_balance += (float)$account->ledger_balance;
        $bank_accounts_list[] = $account;
    } else { // 'Petty Cash' or 'Cash'
        $total_cash_balance += (float)$account->ledger_balance;
        $cash_accounts_list[] = $account;
    }
}
$bank_ledger_total = $total_bank_balance; 

// 2. Get Total Accounts Receivable (A/R)
$ar_balance = $db->query(
    "SELECT SUM(current_balance) as total FROM customers WHERE customer_type = 'Credit' AND current_balance > 0"
)->first()->total ?? 0;

// 3. Get Revenue & Expense MTD
$revenue_mtd = $db->query(
    "SELECT SUM(tl.credit_amount) as total 
     FROM transaction_lines tl
     JOIN chart_of_accounts coa ON tl.account_id = coa.id
     WHERE coa.account_type_group = 'Revenue' AND tl.transaction_date BETWEEN ? AND ?",
    [$month_start, $today] // This line (147) now works
)->first()->total ?? 0;

$expenses_mtd = $db->query(
    "SELECT SUM(tl.debit_amount) as total 
     FROM transaction_lines tl
     JOIN chart_of_accounts coa ON tl.account_id = coa.id
     WHERE coa.account_type_group = 'Expense' AND tl.transaction_date BETWEEN ? AND ?",
    [$month_start, $today] // This line (155) now works
)->first()->total ?? 0;

$net_income_mtd = $revenue_mtd - $expenses_mtd;

// --- DATA: Chart (Last 6 Months) ---
$chart_data = $db->query(
    "SELECT 
        DATE_FORMAT(tl.transaction_date, '%Y-%m') as month,
        SUM(CASE WHEN coa.account_type_group = 'Revenue' THEN tl.credit_amount ELSE 0 END) as revenue,
        SUM(CASE WHEN coa.account_type_group = 'Expense' THEN tl.debit_amount ELSE 0 END) as expenses
     FROM transaction_lines tl
     JOIN chart_of_accounts coa ON tl.account_id = coa.id
     WHERE tl.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
       AND coa.account_type_group IN ('Revenue', 'Expense')
     GROUP BY DATE_FORMAT(tl.transaction_date, '%Y-%m')
     ORDER BY month ASC"
)->results();

$chart_labels = json_encode(array_map(fn($d) => date("M 'y", strtotime($d->month . '-01')), $chart_data));
$chart_revenue = json_encode(array_column($chart_data, 'revenue'));
$chart_expenses = json_encode(array_column($chart_data, 'expenses'));


require_once '../templates/header.php';
?>

<div class="space-y-8">

<div class="mb-2">
    <h1 class="text-3xl font-bold text-gray-900">Accounting Dashboard</h1>
    <p class="text-lg text-gray-600 mt-1">Financial overview and quick actions for <?php echo date('F Y'); ?>.</p>
</div>

<div class="bg-white rounded-lg shadow-md p-4 border border-gray-200">
    <div class="flex flex-wrap gap-4 justify-center md:justify-start">
        
        <a href="<?php echo url('accounts/new_transaction.php'); ?>" class="flex flex-col items-center p-3 text-gray-600 hover:bg-primary-50 hover:text-primary-700 rounded-lg transition-colors" title="New Journal Entry">
            <i class="fas fa-book text-2xl mb-1"></i>
            <span class="text-xs font-medium">New Entry</span>
        </a>
        <a href="<?php echo url('cr/customer_payment.php'); ?>" class="flex flex-col items-center p-3 text-gray-600 hover:bg-green-50 hover:text-green-700 rounded-lg transition-colors" title="Record Payment">
            <i class="fas fa-hand-holding-usd text-2xl mb-1"></i>
            <span class="text-xs font-medium">Record Payment</span>
        </a>
         <a href="<?php echo url('accounts/internal_transfer.php'); ?>" class="flex flex-col items-center p-3 text-gray-600 hover:bg-blue-50 hover:text-blue-700 rounded-lg transition-colors" title="Bank/Cash Transfer">
            <i class="fas fa-exchange-alt text-2xl mb-1"></i>
            <span class="text-xs font-medium">Transfer</span>
        </a>

        <a href="<?php echo url('cr/credit_order_approval.php'); ?>" class="relative flex flex-col items-center p-3 text-gray-600 hover:bg-red-50 hover:text-red-700 rounded-lg transition-colors" title="Approve Orders">
            <?php if ($pending_approvals > 0): ?>
                <span class="absolute top-0 right-0 h-5 w-5 rounded-full bg-red-600 text-white text-xs flex items-center justify-center"><?php echo $pending_approvals; ?></span>
            <?php endif; ?>
            <i class="fas fa-check-double text-2xl mb-1"></i>
            <span class="text-xs font-medium">Approve Orders</span>
        </a>

        <a href="<?php echo url('accounts/chart_of_accounts.php'); ?>" class="flex flex-col items-center p-3 text-gray-600 hover:bg-gray-100 hover:text-gray-800 rounded-lg transition-colors" title="Chart of Accounts">
            <i class="fas fa-sitemap text-2xl mb-1"></i>
            <span class="text-xs font-medium">Chart of Accounts</span>
        </a>
        <a href="<?php echo url('accounts/bank_accounts.php'); ?>" class="flex flex-col items-center p-3 text-gray-600 hover:bg-gray-100 hover:text-gray-800 rounded-lg transition-colors" title="Bank Accounts">
            <i class="fas fa-university text-2xl mb-1"></i>
            <span class="text-xs font-medium">Bank Accounts</span>
        </a>
        <a href="<?php echo url('admin/balance_sheet.php'); ?>" class="flex flex-col items-center p-3 text-gray-600 hover:bg-gray-100 hover:text-gray-800 rounded-lg transition-colors" title="Balance Sheet">
            <i class="fas fa-balance-scale-right text-2xl mb-1"></i>
            <span class="text-xs font-medium">Balance Sheet</span>
        </a>
        <a href="<?php echo url('cr/customer_ledger.php'); ?>" class="flex flex-col items-center p-3 text-gray-600 hover:bg-gray-100 hover:text-gray-800 rounded-lg transition-colors" title="Customer Ledger">
            <i class="fas fa-book-reader text-2xl mb-1"></i>
            <span class="text-xs font-medium">Customer Ledger</span>
        </a>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    
    <div class="bg-white rounded-lg shadow-lg p-6 border-l-4 border-blue-500">
        <div class="flex justify-between items-center">
            <div>
                <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Total Bank Balances (Ledger)</p>
                <p class="text-3xl font-bold text-gray-900 mt-2">৳<?php echo number_format($total_bank_balance, 2); ?></p>
            </div>
            <i class="fas fa-university text-4xl text-blue-200"></i>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-lg p-6 border-l-4 border-red-500">
        <div class="flex justify-between items-center">
            <div>
                <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Accounts Receivable (A/R)</p>
                <p class="text-3xl font-bold text-gray-900 mt-2">৳<?php echo number_format($ar_balance, 2); ?></p>
            </div>
            <i class="fas fa-file-invoice-dollar text-4xl text-red-200"></i>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-lg p-6 border-l-4 border-green-500">
        <div class="flex justify-between items-center">
            <div>
                <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Revenue (MTD)</p>
                <p class="text-3xl font-bold text-gray-900 mt-2">৳<?php echo number_format($revenue_mtd, 2); ?></p>
            </div>
            <i class="fas fa-arrow-up text-4xl text-green-200"></i>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-lg p-6 border-l-4 <?php echo ($net_income_mtd >= 0) ? 'border-green-500' : 'border-red-500'; ?>">
        <div class="flex justify-between items-center">
            <div>
                <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Net Income (MTD)</p>
                <p class="text-3xl font-bold <?php echo ($net_income_mtd >= 0) ? 'text-green-700' : 'text-red-700'; ?> mt-2">
                    ৳<?php echo number_format($net_income_mtd, 2); ?>
                </p>
            </div>
            <i class="fas fa-balance-scale text-4xl <?php echo ($net_income_mtd >= 0) ? 'text-green-200' : 'text-red-200'; ?>"></i>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
    
    <div class="bg-white rounded-lg shadow-lg border border-gray-200">
        <h3 class="text-lg font-bold text-gray-800 p-4 border-b border-gray-200">Credit Limit Risk (>80%)</h3>
        <div class="divide-y divide-gray-200 max-h-80 overflow-y-auto">
            <?php if (empty($high_utilization_customers)): ?>
                <p class="p-4 text-sm text-gray-500 text-center">No customers are over 80% utilization.</p>
            <?php else: ?>
                <?php foreach ($high_utilization_customers as $cust): ?>
                <div class="p-4 hover:bg-red-50">
                    <div class="flex justify-between items-center">
                        <a href="<?php echo url('customers/view.php?id=' . $cust->id); ?>" class="font-medium text-primary-700 hover:underline"><?php echo htmlspecialchars($cust->name); ?></a>
                        <span class="px-2 py-1 text-xs font-bold rounded-full bg-red-100 text-red-800"><?php echo number_format($cust->utilization_percent, 1); ?>%</span>
                    </div>
                    <p class="text-xs text-gray-500">Due: <?php echo number_format($cust->current_balance, 2); ?> / <?php echo number_format($cust->credit_limit, 2); ?></p>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-lg border border-gray-200 p-6">
        <h3 class="text-lg font-bold text-gray-800 mb-4">Today's POS Summary</h3>
        <div class="space-y-4">
            <div>
                <p class="text-sm font-medium text-gray-500">Total POS Sales</p>
                <p class="text-3xl font-bold text-gray-900"><?php echo $pos_stats->total_sales ?? 0; ?></p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500">Total POS Revenue</p>
                <p class="text-3xl font-bold text-gray-900">৳<?php echo number_format($pos_stats->total_amount ?? 0, 2); ?></p>
            </div>
             <div>
                <p class="text-sm font-medium text-gray-500">Total Cash Received (All POS)</p>
                <p class="text-3xl font-bold text-green-600">৳<?php echo number_format($pos_cash->total_cash_in ?? 0, 2); ?></p>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-lg shadow-lg border border-gray-200">
        <h3 class="text-lg font-bold text-gray-800 p-4 border-b border-gray-200">Bank Balances (from Ledger)</h3>
        <div class="divide-y divide-gray-200 max-h-80 overflow-y-auto">
            <?php foreach ($bank_accounts_list as $account): ?>
            <div class="p-4">
                <p class="font-medium text-gray-900"><?php echo htmlspecialchars($account->bank_name); ?> (<?php echo htmlspecialchars($account->account_number); ?>)</p>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Ledger Balance:</span>
                    <span class="font-bold text-lg text-gray-800">৳<?php echo number_format($account->ledger_balance, 2); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="p-4 bg-gray-50 border-t-2 border-gray-200">
             <div class="flex justify-between text-sm">
                <span class="font-bold text-gray-900">Total Ledger Bank Balance:</span>
                <span class="font-bold text-lg text-blue-600">৳<?php echo number_format($bank_ledger_total, 2); ?></span>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-lg shadow-lg border border-gray-200">
        <h3 class="text-lg font-bold text-gray-800 p-4 border-b border-gray-200">Cash Reconciliation</h3>
        <div class="divide-y divide-gray-200 max-h-80 overflow-y-auto">
            <?php foreach ($cash_accounts_list as $cash): 
                // Compare memo balance (cash box) to ledger balance
                $diff = (float)($cash->cash_box_memo_balance ?? 0) - (float)$cash->ledger_balance;
            ?>
            <div class="p-4 <?php echo (abs($diff) > 0.01) ? 'bg-red-50' : ''; ?>">
                <p class="font-medium text-gray-900"><?php echo htmlspecialchars($cash->account_name); ?></p>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Cash Box (Memo):</span>
                    <span class="font-medium">৳<?php echo number_format($cash->cash_box_memo_balance ?? 0, 2); ?></span>
                </div>
                 <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Ledger Balance:</span>
                    <span class="font-medium">৳<?php echo number_format($cash->ledger_balance, 2); ?></span>
                </div>
                <?php if (abs($diff) > 0.01): ?>
                <div class="flex justify-between text-sm mt-1 border-t border-red-200 pt-1">
                    <span class="font-bold text-red-600">Difference:</span>
                    <span class="font-bold text-red-600">৳<?php echo number_format($diff, 2); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="p-4 bg-gray-50 border-t-2 border-gray-200">
             <div class="flex justify-between text-sm">
                <span class="font-bold text-gray-900">Total Ledger Cash Balance:</span>
                <span class="font-bold text-lg text-blue-600">৳<?php echo number_format($total_cash_balance, 2); ?></span>
            </div>
        </div>
    </div>

</div>

<div class="mt-8 bg-white rounded-lg shadow-lg border border-gray-200 p-6">
    <h3 class="text-xl font-bold text-gray-800 mb-4">6-Month Financial Summary</h3>
    <div class="h-80">
        <canvas id="financialChart"></canvas>
    </div>
</div>

<div class="mt-8 bg-white rounded-lg shadow-lg border border-gray-200 overflow-hidden">
    <h3 class="text-xl font-bold text-gray-800 p-4 border-b border-gray-200">Today's Fund Movements (Journal)</h3>
    <?php if (empty($todays_journal)): ?>
        <p class="p-6 text-center text-gray-500">No journal entries recorded today.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Journal ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Branch</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Debit</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php $current_je_id = null; $row_count = 0; ?>
                <?php foreach ($todays_journal as $line): ?>
                    <?php
                    $is_new_entry = $current_je_id !== $line->id;
                    if ($is_new_entry) {
                        $current_je_id = $line->id;
                        $row_count = $db->query("SELECT COUNT(id) as c FROM transaction_lines WHERE journal_entry_id = ?", [$line->id])->first()->c ?? 2;
                    }
                    $border_class = $is_new_entry ? 'border-t-2 border-gray-300' : '';
                    ?>
                <tr class="hover:bg-gray-50 <?php echo $border_class; ?>">
                    <?php if ($is_new_entry): ?>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-medium align-top" rowspan="<?php echo $row_count; ?>">
                        <?php echo $line->id; ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900 align-top" rowspan="<?php echo $row_count; ?>">
                        <?php echo htmlspecialchars($line->description); ?>
                    </td>
                    <?php endif; ?>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($line->account_name); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($line->branch_name ?? 'N/A'); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-800">
                        <?php echo ($line->debit_amount > 0) ? number_format($line->debit_amount, 2) : '-'; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-800">
                        <?php echo ($line->credit_amount > 0) ? number_format($line->credit_amount, 2) : '-'; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="mt-8 bg-white rounded-lg shadow-lg border border-gray-200 overflow-hidden">
    <div class="p-4 border-b border-gray-200">
        <form method="GET" class="flex flex-col md:flex-row md:items-end gap-4">
            <h3 class="text-xl font-bold text-gray-800">Pending Orders (Not Shipped)</h3>
            <div class="flex-grow"></div>
            <div>
                <label for="date_from_pending" class="block text-sm font-medium text-gray-700">From</label>
                <input type="date" name="date_from_pending" id="date_from_pending" value="<?php echo $date_from_pending; ?>" class="mt-1 p-2 border border-gray-300 rounded-md shadow-sm">
            </div>
            <div>
                <label for="date_to_pending" class="block text-sm font-medium text-gray-700">To</label>
                <input type="date" name="date_to_pending" id="date_to_pending" value="<?php echo $date_to_pending; ?>" class="mt-1 p-2 border border-gray-300 rounded-md shadow-sm">
            </div>
            <button type="submit" class="px-4 py-2 bg-primary-600 text-white rounded-md shadow-sm hover:bg-primary-700">
                <i class="fas fa-filter"></i> Filter
            </button>
        </form>
    </div>
    <?php if (empty($pending_orders)): ?>
        <p class="p-6 text-center text-gray-500">No pending orders found in this date range.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Branch</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($pending_orders as $order): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <a href="<?php echo url('cr/credit_order_view.php?id=' . $order->id); ?>" class="text-primary-600 hover:underline"><?php echo htmlspecialchars($order->order_number); ?></a>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?php echo date('d-M-Y', strtotime($order->order_date)); ?></td>
                    <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($order->customer_name); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($order->branch_name ?? 'N/A'); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-800">৳<?php echo number_format($order->total_amount, 2); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                         <span class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">
                            <?php echo ucwords(str_replace('_', ' ', $order->status)); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="mt-8 bg-white rounded-lg shadow-lg border border-gray-200 overflow-hidden">
    <div class="p-4 border-b border-gray-200">
        <form method="GET" class="flex flex-col md:flex-row md:items-end gap-4">
            <h3 class="text-xl font-bold text-gray-800">Shipped & Delivered Orders</h3>
            <div class="flex-grow"></div>
            <div>
                <label for="date_from_shipped" class="block text-sm font-medium text-gray-700">From</label>
                <input type="date" name="date_from_shipped" id="date_from_shipped" value="<?php echo $date_from_shipped; ?>" class="mt-1 p-2 border border-gray-300 rounded-md shadow-sm">
            </div>
            <div>
                <label for="date_to_shipped" class="block text-sm font-medium text-gray-700">To</label>
                <input type="date" name="date_to_shipped" id="date_to_shipped" value="<?php echo $date_to_shipped; ?>" class="mt-1 p-2 border border-gray-300 rounded-md shadow-sm">
            </div>
            <button type="submit" class="px-4 py-2 bg-primary-600 text-white rounded-md shadow-sm hover:bg-primary-700">
                <i class="fas fa-filter"></i> Filter
            </button>
        </form>
    </div>
    <?php if (empty($shipped_orders)): ?>
        <p class="p-6 text-center text-gray-500">No shipped orders found in this date range.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Branch</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($shipped_orders as $order): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <a href="<?php echo url('cr/credit_order_view.php?id=' . $order->id); ?>" class="text-primary-600 hover:underline"><?php echo htmlspecialchars($order->order_number); ?></a>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?php echo date('d-M-Y', strtotime($order->order_date)); ?></td>
                    <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($order->customer_name); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($order->branch_name ?? 'N/A'); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-800">৳<?php echo number_format($order->total_amount, 2); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $order->status === 'delivered' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>">
                            <?php echo ucwords(str_replace('_', ' ', $order->status)); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Check if the chart canvas exists
    if (document.getElementById('financialChart')) {
        const ctx = document.getElementById('financialChart').getContext('2d');
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo $chart_labels; ?>,
                datasets: [
                    {
                        label: 'Revenue',
                        data: <?php echo $chart_revenue; ?>,
                        backgroundColor: 'rgba(2, 132, 199, 0.6)', // bg-primary-600
                        borderColor: 'rgba(2, 132, 199, 1)',
                        borderWidth: 1,
                        borderRadius: 4
                    },
                    {
                        label: 'Expenses',
                        data: <?php echo $chart_expenses; ?>,
                        backgroundColor: 'rgba(239, 68, 68, 0.6)', // bg-red-500
                        borderColor: 'rgba(239, 68, 68, 1)',
                        borderWidth: 1,
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '৳' + (value / 1000) + 'k'; // Format as ৳10k, ৳20k
                            }
                        }
                    },
                    x: {
                        grid: { display: false }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += '৳' + new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2 }).format(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>

<?php require_once '../templates/footer.php'; ?>
<?php
/**
 * POS Summary Dashboard (Jul 2026) — KPI tiles: today/period sales, cash vs
 * credit split, outstanding POS ledger total, pending exit verifications,
 * pending EOD bank deposits, pending credit-sale approvals.
 */
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'accountspos-demra', 'accountspos-srg', 'dispatchpos-demra', 'dispatchpos-srg'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'POS Dashboard';
ensurePosLedgerTable();
ensureEodDepositColumns();

$today = date('Y-m-d');

$today_summary = $db->query(
    "SELECT COUNT(*) AS orders, COALESCE(SUM(total_amount),0) AS sales,
            COALESCE(SUM(cash_paid),0) AS cash, COALESCE(SUM(credit_amount),0) AS credit
     FROM orders WHERE order_type = 'POS' AND DATE(order_date) = ?",
    [$today]
)->first();

$month_summary = $db->query(
    "SELECT COALESCE(SUM(total_amount),0) AS sales FROM orders WHERE order_type = 'POS' AND order_date >= ?",
    [date('Y-m-01')]
)->first();

// Sum of each customer's LATEST balance_after (not a flat debit-credit sum,
// which would double-count customers with multiple ledger rows).
$outstanding_rows = $db->query(
    "SELECT pl.customer_id, pl.balance_after
     FROM pos_customer_ledger pl
     INNER JOIN (SELECT customer_id, MAX(id) AS max_id FROM pos_customer_ledger GROUP BY customer_id) latest
       ON latest.customer_id = pl.customer_id AND latest.max_id = pl.id"
)->results();
$true_outstanding = array_sum(array_column($outstanding_rows, 'balance_after'));

$pending_exit = $db->query(
    "SELECT COUNT(*) AS c FROM pos_exit_verifications WHERE verified_at IS NULL"
)->first();

$pending_deposit = $db->query(
    "SELECT COUNT(*) AS c, COALESCE(SUM(actual_cash),0) AS amt FROM eod_summary WHERE cash_disposition = 'bank_deposit_pending' AND deposited_at IS NULL"
)->first();

$pending_credit_approvals = $db->query(
    "SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS amt FROM cr_pending_requests WHERE request_type = 'pos_credit_sale' AND status = 'pending'"
)->first();

$branch_today = $db->query(
    "SELECT b.name, COUNT(o.id) AS cnt, COALESCE(SUM(o.total_amount),0) AS amt
     FROM branches b LEFT JOIN orders o ON o.branch_id = b.id AND o.order_type = 'POS' AND DATE(o.order_date) = ?
     WHERE b.status = 'active' GROUP BY b.id, b.name ORDER BY amt DESC",
    [$today]
)->results();

require_once '../templates/header.php';
?>
<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">POS Dashboard</h1>
        <div class="flex gap-3 text-sm">
            <a href="index.php" class="text-blue-600 hover:text-blue-800"><i class="fas fa-cash-register mr-1"></i>Terminal</a>
            <a href="reports.php" class="text-blue-600 hover:text-blue-800"><i class="fas fa-chart-bar mr-1"></i>Reports</a>
            <a href="customer_ledger.php" class="text-blue-600 hover:text-blue-800"><i class="fas fa-book mr-1"></i>Ledger</a>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm p-4">
            <div class="text-xs text-gray-500 uppercase">Today's Sales</div>
            <div class="text-2xl font-bold text-green-600">৳<?php echo number_format($today_summary->sales, 0); ?></div>
            <div class="text-xs text-gray-400"><?php echo (int)$today_summary->orders; ?> orders</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
            <div class="text-xs text-gray-500 uppercase">This Month</div>
            <div class="text-2xl font-bold text-gray-900">৳<?php echo number_format($month_summary->sales, 0); ?></div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
            <div class="text-xs text-gray-500 uppercase">Today Cash / Credit</div>
            <div class="text-lg font-bold"><span class="text-blue-600">৳<?php echo number_format($today_summary->cash, 0); ?></span> / <span class="text-purple-600">৳<?php echo number_format($today_summary->credit, 0); ?></span></div>
        </div>

        <a href="customer_ledger.php" class="bg-white rounded-xl shadow-sm p-4 hover:shadow-md transition-shadow">
            <div class="text-xs text-gray-500 uppercase">POS Credit Outstanding</div>
            <div class="text-2xl font-bold text-red-600">৳<?php echo number_format($true_outstanding, 0); ?></div>
        </a>
        <a href="verify_exit.php" class="bg-white rounded-xl shadow-sm p-4 hover:shadow-md transition-shadow">
            <div class="text-xs text-gray-500 uppercase">Pending Exit Verification</div>
            <div class="text-2xl font-bold <?php echo $pending_exit->c > 0 ? 'text-amber-600' : 'text-gray-400'; ?>"><?php echo (int)$pending_exit->c; ?></div>
        </a>
        <a href="confirm_deposit.php" class="bg-white rounded-xl shadow-sm p-4 hover:shadow-md transition-shadow">
            <div class="text-xs text-gray-500 uppercase">Pending Bank Deposit</div>
            <div class="text-2xl font-bold <?php echo $pending_deposit->c > 0 ? 'text-amber-600' : 'text-gray-400'; ?>">৳<?php echo number_format($pending_deposit->amt, 0); ?></div>
            <div class="text-xs text-gray-400"><?php echo (int)$pending_deposit->c; ?> batch(es)</div>
        </a>

        <a href="../cr/approval_requests.php" class="bg-white rounded-xl shadow-sm p-4 hover:shadow-md transition-shadow md:col-span-3">
            <div class="text-xs text-gray-500 uppercase">Pending Credit-Limit Approvals</div>
            <div class="text-2xl font-bold <?php echo $pending_credit_approvals->c > 0 ? 'text-red-600' : 'text-gray-400'; ?>">
                <?php echo (int)$pending_credit_approvals->c; ?> request(s) — ৳<?php echo number_format($pending_credit_approvals->amt, 0); ?>
            </div>
        </a>
    </div>

    <div class="bg-white rounded-xl shadow-sm p-4">
        <h3 class="text-sm font-bold text-gray-700 mb-3">Today by Branch</h3>
        <?php foreach ($branch_today as $b): ?>
        <div class="flex justify-between text-sm py-2 border-b border-gray-50">
            <span><?php echo htmlspecialchars($b->name); ?> <span class="text-gray-400">(<?php echo (int)$b->cnt; ?> orders)</span></span>
            <span class="font-bold">৳<?php echo number_format($b->amt, 2); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php require_once '../templates/footer.php'; ?>

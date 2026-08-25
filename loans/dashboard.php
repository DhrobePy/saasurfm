<?php
/**
 * Loans Dashboard — outstanding loans, overdue alerts, pending approvals,
 * and a filterable loan history (date range / party).
 */
require_once '../core/init.php';

restrict_access(['Superadmin', 'admin'], 'loans', 'dashboard');

global $db;
$pageTitle = 'Loans Dashboard';

ensureLoansTable();
ensureLoanRepaymentsTable();

// ── Filters (GET, shareable/bookmarkable) ──────────────────────────────────
$month_start = date('Y-m-01');
$today       = date('Y-m-d');
$date_from = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-01-01'); // default: whole year, loans are infrequent
$date_to   = !empty($_GET['date_to'])   ? $_GET['date_to']   : $today;
$date_from = date('Y-m-d', strtotime($date_from));
$date_to   = date('Y-m-d', strtotime($date_to));
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];

$f_party_type = in_array($_GET['party_type'] ?? '', ['customer', 'supplier'], true) ? $_GET['party_type'] : null;
$f_customer_id = isset($_GET['customer_id']) && (int)$_GET['customer_id'] > 0 ? (int)$_GET['customer_id'] : null;
$f_supplier_id = isset($_GET['supplier_id']) && (int)$_GET['supplier_id'] > 0 ? (int)$_GET['supplier_id'] : null;

$filter_sql = "l.loan_date BETWEEN ? AND ?";
$filter_params = [$date_from, $date_to];
if ($f_customer_id) { $filter_sql .= " AND l.customer_id = ?"; $filter_params[] = $f_customer_id; }
if ($f_supplier_id) { $filter_sql .= " AND l.supplier_id = ?"; $filter_params[] = $f_supplier_id; }

// ── KPIs (all-time, not period-scoped — loan exposure is a snapshot) ────
$outstanding_total = (float)($db->query("SELECT COALESCE(SUM(balance_due),0) AS t FROM loans WHERE status != 'rejected'")->first()->t ?? 0);
$active_count = (int)($db->query("SELECT COUNT(*) AS c FROM loans WHERE status = 'active' AND balance_due > 0.01")->first()->c ?? 0);
$overdue_rows = $db->query(
    "SELECT l.*, c.name AS customer_name, s.company_name AS supplier_name
     FROM loans l LEFT JOIN customers c ON c.id = l.customer_id LEFT JOIN suppliers s ON s.id = l.supplier_id
     WHERE l.balance_due > 0.01 AND l.expected_return_date IS NOT NULL AND l.expected_return_date < CURDATE()
     ORDER BY l.expected_return_date ASC"
)->results();

$disbursed_period = (float)($db->query(
    "SELECT COALESCE(SUM(l.principal_amount),0) AS t FROM loans l WHERE {$filter_sql}", $filter_params
)->first()->t ?? 0);
$repaid_period = (float)($db->query(
    "SELECT COALESCE(SUM(lr.amount),0) AS t FROM loan_repayments lr JOIN loans l ON l.id = lr.loan_id WHERE {$filter_sql}", $filter_params
)->first()->t ?? 0);

$pending_count = (int)($db->query(
    "SELECT COUNT(*) AS c FROM cr_pending_requests WHERE status = 'pending' AND request_type IN ('loan_disbursement','loan_repayment')"
)->first()->c ?? 0);

// ── Filtered loan history ────────────────────────────────────────────────
$loan_history = $db->query(
    "SELECT l.*, c.name AS customer_name, s.company_name AS supplier_name
     FROM loans l LEFT JOIN customers c ON c.id = l.customer_id LEFT JOIN suppliers s ON s.id = l.supplier_id
     WHERE {$filter_sql} ORDER BY l.loan_date DESC, l.id DESC LIMIT 200",
    $filter_params
)->results();
if ($f_party_type === 'customer') { $loan_history = array_values(array_filter($loan_history, fn($l) => !empty($l->customer_id))); }
if ($f_party_type === 'supplier') { $loan_history = array_values(array_filter($loan_history, fn($l) => !empty($l->supplier_id))); }

$filter_customers = $db->query("SELECT id, name, business_name, phone_number FROM customers WHERE status = 'active' ORDER BY name ASC")->results();
$filter_suppliers = $db->query("SELECT id, company_name AS name, phone, mobile FROM suppliers WHERE status = 'active' ORDER BY company_name ASC")->results();

require_once '../templates/header.php';
?>
<div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <div class="mb-6 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-hand-holding-dollar text-amber-600 mr-2"></i>Loans Dashboard</h1>
            <p class="text-gray-600 mt-1 text-sm">Cash advances to customers, suppliers, and related parties — outstanding exposure, overdue loans, and history.</p>
        </div>
        <a href="loan.php" class="px-3 py-2 text-sm bg-amber-600 text-white rounded-lg hover:bg-amber-700"><i class="fas fa-plus mr-1"></i>New Loan</a>
    </div>

    <?php if (!empty($overdue_rows)): ?>
    <div class="mb-6 rounded-lg border border-red-300 bg-red-50 px-4 py-3">
        <p class="text-sm font-semibold text-red-800"><i class="fas fa-triangle-exclamation mr-1"></i><?php echo count($overdue_rows); ?> loan(s) are past their expected return date:</p>
        <ul class="mt-2 text-xs text-red-700 list-disc list-inside">
            <?php foreach ($overdue_rows as $ov): ?>
            <li><a href="view_loan.php?id=<?php echo (int)$ov->id; ?>" class="underline"><?php echo htmlspecialchars($ov->loan_number); ?></a> — <?php echo htmlspecialchars($ov->customer_name ?? $ov->supplier_name); ?>: <strong>৳<?php echo number_format((float)$ov->balance_due, 2); ?></strong> due, expected <?php echo date('d M Y', strtotime($ov->expected_return_date)); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($pending_count > 0): ?>
    <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 flex items-center justify-between">
        <p class="text-sm text-amber-800"><i class="fas fa-hourglass-half mr-1"></i><strong><?php echo $pending_count; ?></strong> loan request(s) waiting for approval.</p>
        <a href="../cr/approval_requests.php" class="text-xs font-semibold text-amber-800 underline">Review queue →</a>
    </div>
    <?php endif; ?>

    <!-- KPI tiles -->
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Outstanding Loans</p>
            <p class="text-xl font-bold text-amber-700 mt-1">৳<?php echo number_format($outstanding_total, 0); ?></p>
            <p class="text-[11px] text-gray-400 mt-0.5"><?php echo $active_count; ?> active</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Overdue</p>
            <p class="text-xl font-bold <?php echo count($overdue_rows) > 0 ? 'text-red-700' : 'text-gray-400'; ?> mt-1"><?php echo count($overdue_rows); ?></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Disbursed (period)</p>
            <p class="text-xl font-bold text-blue-700 mt-1">৳<?php echo number_format($disbursed_period, 0); ?></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Repaid (period)</p>
            <p class="text-xl font-bold text-green-700 mt-1">৳<?php echo number_format($repaid_period, 0); ?></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Pending Approval</p>
            <p class="text-xl font-bold <?php echo $pending_count > 0 ? 'text-amber-700' : 'text-gray-400'; ?> mt-1"><?php echo $pending_count; ?></p>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6 flex flex-wrap items-end gap-4">
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="px-3 py-2 border rounded-lg text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="px-3 py-2 border rounded-lg text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Party Type</label>
            <select name="party_type" class="px-3 py-2 border rounded-lg text-sm">
                <option value="">All</option>
                <option value="customer" <?php echo $f_party_type === 'customer' ? 'selected' : ''; ?>>Customers</option>
                <option value="supplier" <?php echo $f_party_type === 'supplier' ? 'selected' : ''; ?>>Suppliers</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Customer</label>
            <select name="customer_id" class="px-3 py-2 border rounded-lg text-sm">
                <option value="">Any</option>
                <?php foreach ($filter_customers as $c): ?>
                <option value="<?php echo (int)$c->id; ?>" <?php echo $f_customer_id === (int)$c->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($c->name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Supplier</label>
            <select name="supplier_id" class="px-3 py-2 border rounded-lg text-sm">
                <option value="">Any</option>
                <?php foreach ($filter_suppliers as $s): ?>
                <option value="<?php echo (int)$s->id; ?>" <?php echo $f_supplier_id === (int)$s->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($s->name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="px-4 py-2 bg-amber-600 text-white rounded-lg text-sm font-semibold hover:bg-amber-700"><i class="fas fa-filter mr-1"></i>Apply</button>
        <a href="dashboard.php" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Clear</a>
    </form>

    <!-- Loan history -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-700">Loan History</h2>
            <span class="text-xs text-gray-400"><?php echo count($loan_history); ?> loan(s)</span>
        </div>
        <div class="overflow-x-auto">
        <?php if (!empty($loan_history)): ?>
        <table class="min-w-full text-xs">
            <thead class="bg-gray-50 border-b"><tr>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Loan #</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Date</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Party</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase text-gray-500">Principal</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase text-gray-500">Repaid</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase text-gray-500">Balance Due</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Expected Return</th>
                <th class="px-3 py-2 text-center text-[10px] font-semibold uppercase text-gray-500">Status</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($loan_history as $l):
                    $lh_overdue = $l->expected_return_date && (float)$l->balance_due > 0.01 && strtotime($l->expected_return_date) < strtotime($today);
                ?>
                <tr>
                    <td class="px-3 py-2 font-mono"><a href="view_loan.php?id=<?php echo (int)$l->id; ?>" class="text-amber-700 hover:underline"><?php echo htmlspecialchars($l->loan_number); ?></a></td>
                    <td class="px-3 py-2 text-gray-500"><?php echo date('d M Y', strtotime($l->loan_date)); ?></td>
                    <td class="px-3 py-2"><?php echo htmlspecialchars($l->customer_name ?? $l->supplier_name ?? '—'); ?><?php echo $l->supplier_id ? ' <span class="text-gray-400">(Supplier)</span>' : ''; ?></td>
                    <td class="px-3 py-2 text-right font-semibold">৳<?php echo number_format((float)$l->principal_amount, 2); ?></td>
                    <td class="px-3 py-2 text-right text-green-700">৳<?php echo number_format((float)$l->amount_repaid, 2); ?></td>
                    <td class="px-3 py-2 text-right <?php echo (float)$l->balance_due > 0.01 ? 'text-amber-700 font-semibold' : 'text-gray-400'; ?>">৳<?php echo number_format((float)$l->balance_due, 2); ?></td>
                    <td class="px-3 py-2 <?php echo $lh_overdue ? 'text-red-600 font-semibold' : 'text-gray-500'; ?>"><?php echo $l->expected_return_date ? date('d M Y', strtotime($l->expected_return_date)) : '—'; ?></td>
                    <td class="px-3 py-2 text-center"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo $l->status === 'active' ? 'bg-blue-100 text-blue-700' : ($l->status === 'closed' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'); ?>"><?php echo strtoupper($l->status); ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-8 text-center text-gray-500 text-xs">No loans match this filter.</div>
        <?php endif; ?>
        </div>
    </div>

</div>
<?php require_once '../templates/footer.php'; ?>

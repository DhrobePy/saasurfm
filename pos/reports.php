<?php
/**
 * POS Reports (Jul 2026) — daily/weekly/monthly presets + custom date range,
 * branch/factory-wise filtering, payment-method breakdown, top products.
 */
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'accountspos-demra', 'accountspos-srg', 'dispatchpos-demra', 'dispatchpos-srg'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'POS Reports';
$currentUser = getCurrentUser();
$user_id = (int)($currentUser['id'] ?? 0);
$user_role = $currentUser['role'] ?? '';
$is_admin = in_array($user_role, ['Superadmin', 'admin']);

// Branch scoping: admins see all + a filter; branch-tied roles are locked to their own.
$employee_info = $db->query("SELECT branch_id FROM employees WHERE user_id = ?", [$user_id])->first();
$own_branch_id = $employee_info->branch_id ?? null;

$preset = $_GET['preset'] ?? 'today';
$today = date('Y-m-d');
switch ($preset) {
    case 'week':   $date_from = date('Y-m-d', strtotime('monday this week')); $date_to = $today; break;
    case 'month':  $date_from = date('Y-m-01'); $date_to = $today; break;
    case 'custom': $date_from = $_GET['date_from'] ?? $today; $date_to = $_GET['date_to'] ?? $today; break;
    default:       $preset = 'today'; $date_from = $today; $date_to = $today;
}

$branch_filter = $_GET['branch_id'] ?? '';
if (!$is_admin) { $branch_filter = $own_branch_id; }

$where = "o.order_type = 'POS' AND DATE(o.order_date) BETWEEN ? AND ?";
$params = [$date_from, $date_to];
if ($branch_filter !== '' && $branch_filter !== null) {
    $where .= " AND o.branch_id = ?";
    $params[] = $branch_filter;
}

$summary = $db->query(
    "SELECT COUNT(*) AS total_orders, COALESCE(SUM(o.subtotal),0) AS gross_sales,
            COALESCE(SUM(o.discount_amount),0) AS total_discount, COALESCE(SUM(o.total_amount),0) AS net_sales,
            COALESCE(SUM(o.cash_paid),0) AS total_cash, COALESCE(SUM(o.credit_amount),0) AS total_credit
     FROM orders o WHERE {$where}",
    $params
)->first();

$by_payment = $db->query(
    "SELECT o.payment_method, COUNT(*) AS cnt, COALESCE(SUM(o.total_amount),0) AS amt
     FROM orders o WHERE {$where} GROUP BY o.payment_method ORDER BY amt DESC",
    $params
)->results();

$by_branch = $db->query(
    "SELECT b.name AS branch_name, COUNT(*) AS cnt, COALESCE(SUM(o.total_amount),0) AS amt
     FROM orders o JOIN branches b ON b.id = o.branch_id
     WHERE {$where} GROUP BY o.branch_id, b.name ORDER BY amt DESC",
    $params
)->results();

$top_products = $db->query(
    "SELECT p.base_name, SUM(oi.quantity) AS qty, SUM(oi.total_amount) AS revenue
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     JOIN product_variants pv ON pv.id = oi.variant_id JOIN products p ON p.id = pv.product_id
     WHERE {$where} GROUP BY p.id, p.base_name ORDER BY revenue DESC LIMIT 10",
    $params
)->results();

$exit_status = $db->query(
    "SELECT
        SUM(CASE WHEN pv.verified_at IS NULL THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN pv.verified_at IS NOT NULL THEN 1 ELSE 0 END) AS verified
     FROM orders o LEFT JOIN pos_exit_verifications pv ON pv.order_id = o.id
     WHERE {$where}",
    $params
)->first();

$branches = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name")->results();

require_once '../templates/header.php';
?>
<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">POS Reports</h1>
        <a href="dashboard.php" class="text-sm text-blue-600 hover:text-blue-800"><i class="fas fa-chart-line mr-1"></i>Dashboard</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Period</label>
                <select name="preset" onchange="this.form.submit()" class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    <option value="today" <?php echo $preset === 'today' ? 'selected' : ''; ?>>Today</option>
                    <option value="week" <?php echo $preset === 'week' ? 'selected' : ''; ?>>This Week</option>
                    <option value="month" <?php echo $preset === 'month' ? 'selected' : ''; ?>>This Month</option>
                    <option value="custom" <?php echo $preset === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                </select>
            </div>
            <?php if ($preset === 'custom'): ?>
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
            </div>
            <?php endif; ?>
            <?php if ($is_admin): ?>
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Branch</label>
                <select name="branch_id" class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $b): ?>
                    <option value="<?php echo $b->id; ?>" <?php echo (string)$branch_filter === (string)$b->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($b->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">Apply</button>
        </form>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm p-4">
            <div class="text-xs text-gray-500 uppercase">Orders</div>
            <div class="text-2xl font-bold text-gray-900"><?php echo number_format($summary->total_orders); ?></div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
            <div class="text-xs text-gray-500 uppercase">Net Sales</div>
            <div class="text-2xl font-bold text-green-600">৳<?php echo number_format($summary->net_sales, 0); ?></div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
            <div class="text-xs text-gray-500 uppercase">Cash Collected</div>
            <div class="text-2xl font-bold text-blue-600">৳<?php echo number_format($summary->total_cash, 0); ?></div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
            <div class="text-xs text-gray-500 uppercase">On Credit</div>
            <div class="text-2xl font-bold text-purple-600">৳<?php echo number_format($summary->total_credit, 0); ?></div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm p-4">
            <h3 class="text-sm font-bold text-gray-700 mb-3">By Payment Method</h3>
            <?php if (empty($by_payment)): ?><p class="text-sm text-gray-400">No data.</p><?php endif; ?>
            <?php foreach ($by_payment as $p): ?>
            <div class="flex justify-between text-sm py-1 border-b border-gray-50">
                <span><?php echo htmlspecialchars($p->payment_method); ?> <span class="text-gray-400">(<?php echo $p->cnt; ?>)</span></span>
                <span class="font-bold">৳<?php echo number_format($p->amt, 2); ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="bg-white rounded-xl shadow-sm p-4">
            <h3 class="text-sm font-bold text-gray-700 mb-3">Exit Verification</h3>
            <div class="flex justify-between text-sm py-1">
                <span>Verified</span><span class="font-bold text-green-600"><?php echo (int)($exit_status->verified ?? 0); ?></span>
            </div>
            <div class="flex justify-between text-sm py-1">
                <span>Pending</span><span class="font-bold text-amber-600"><?php echo (int)($exit_status->pending ?? 0); ?></span>
            </div>
        </div>
    </div>

    <?php if ($is_admin && !$branch_filter): ?>
    <div class="bg-white rounded-xl shadow-sm p-4 mb-6">
        <h3 class="text-sm font-bold text-gray-700 mb-3">By Branch</h3>
        <?php foreach ($by_branch as $b): ?>
        <div class="flex justify-between text-sm py-1 border-b border-gray-50">
            <span><?php echo htmlspecialchars($b->branch_name); ?> <span class="text-gray-400">(<?php echo $b->cnt; ?> orders)</span></span>
            <span class="font-bold">৳<?php echo number_format($b->amt, 2); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-sm p-4">
        <h3 class="text-sm font-bold text-gray-700 mb-3">Top Products</h3>
        <table class="min-w-full text-sm">
            <thead><tr class="text-xs text-gray-500 uppercase"><th class="text-left py-1">Product</th><th class="text-right py-1">Qty Sold</th><th class="text-right py-1">Revenue</th></tr></thead>
            <tbody>
                <?php if (empty($top_products)): ?><tr><td colspan="3" class="text-center text-gray-400 py-4">No sales in this period.</td></tr><?php endif; ?>
                <?php foreach ($top_products as $tp): ?>
                <tr class="border-t border-gray-50">
                    <td class="py-2"><?php echo htmlspecialchars($tp->base_name); ?></td>
                    <td class="text-right py-2"><?php echo number_format($tp->qty); ?></td>
                    <td class="text-right py-2 font-bold">৳<?php echo number_format($tp->revenue, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once '../templates/footer.php'; ?>

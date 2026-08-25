<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra',
                  'accountspos-srg', 'accountspos-demra', 'sales-srg', 'sales-demra', 'sales-other', 'collector'];
// New page under credit_sales — accept an explicit grant OR the All Sales grant so
// users whose whitelist predates this page aren't locked out.
if (!userHasPageGrant('credit_sales', 'outstanding_invoices') && !userHasPageGrant('credit_sales', 'all_sales')) {
    restrict_access($allowed_roles, 'credit_sales', 'outstanding_invoices');
}

global $db;
$currentUser = getCurrentUser();
$user_role   = $currentUser['role'] ?? '';
$is_admin    = in_array($user_role, ['Superadmin', 'admin']);
$is_accounts = $is_admin || in_array($user_role, ['Accounts','accounts-srg','accounts-demra','accountspos-srg','accountspos-demra','collector']);
$pageTitle   = 'Outstanding Invoices';

/* ─── Filters + sort ────────────────────────────────────────── */
$branch_filter = (int)($_GET['branch_id'] ?? 0);
$type_filter   = in_array($_GET['type'] ?? 'all', ['all','regular','opening']) ? ($_GET['type'] ?? 'all') : 'all';
$min_due       = (float)($_GET['min_due'] ?? 0);
$search        = trim($_GET['q'] ?? '');
// "Due type" = the customer's overall credit standing behind this invoice.
$due_type      = in_array($_GET['due_type'] ?? 'all', ['all','due','advance','no_limit']) ? ($_GET['due_type'] ?? 'all') : 'all';

// Customer true balance (initial_due + ledger debits − credits) via a per-customer aggregate.
$balance_join = "LEFT JOIN (SELECT customer_id, SUM(debit_amount) - SUM(credit_amount) AS net
                            FROM customer_ledger WHERE reference_type != 'initial_due'
                            GROUP BY customer_id) led ON led.customer_id = c.id";
$balance_expr = "(COALESCE(c.initial_due,0) + COALESCE(led.net,0))";

$sort_map = [
    'amount'   => '(co.total_amount - COALESCE(co.advance_paid,0) - COALESCE(co.amount_paid,0))',
    'date'     => 'co.order_date',
    'customer' => 'c.name',
    'branch'   => 'b.name',
];
$sort = array_key_exists($_GET['sort'] ?? '', $sort_map) ? $_GET['sort'] : 'amount';
$dir  = (strtolower($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
$order_by = $sort_map[$sort] . ' ' . $dir . ', co.id DESC';

$where  = ["(co.total_amount - COALESCE(co.advance_paid,0) - COALESCE(co.amount_paid,0)) > 0.01"];
$where[] = "co.status NOT IN ('rejected','cancelled')";
$params = [];
if ($branch_filter) { $where[] = "co.assigned_branch_id = ?"; $params[] = $branch_filter; }
if ($type_filter === 'opening') { $where[] = "co.order_number LIKE 'INV-INITIAL-%'"; }
elseif ($type_filter === 'regular') { $where[] = "co.order_number NOT LIKE 'INV-INITIAL-%'"; }
if ($min_due > 0) { $where[] = "(co.total_amount - COALESCE(co.advance_paid,0) - COALESCE(co.amount_paid,0)) >= ?"; $params[] = $min_due; }
if ($search !== '') { $where[] = "(c.name LIKE ? OR co.order_number LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
// Due-type filter (uses the customer balance expression)
if ($due_type === 'due')          { $where[] = "COALESCE(c.credit_limit,0) > 0 AND {$balance_expr} >= -0.01"; }
elseif ($due_type === 'advance')  { $where[] = "{$balance_expr} < -0.01"; }
elseif ($due_type === 'no_limit') { $where[] = "COALESCE(c.credit_limit,0) <= 0"; }
$where_sql = implode(' AND ', $where);

$rows = $db->query(
    "SELECT co.id, co.order_number, co.order_date, co.total_amount, co.advance_paid, co.amount_paid,
            (co.total_amount - COALESCE(co.advance_paid,0) - COALESCE(co.amount_paid,0)) AS due_amount,
            co.status, c.name AS customer_name, c.phone_number, b.name AS branch_name,
            COALESCE(c.credit_limit,0) AS credit_limit, {$balance_expr} AS cust_balance,
            CASE WHEN co.order_number LIKE 'INV-INITIAL-%' THEN 1 ELSE 0 END AS is_opening
     FROM credit_orders co
     JOIN customers c ON c.id = co.customer_id
     {$balance_join}
     LEFT JOIN branches b ON b.id = co.assigned_branch_id
     WHERE {$where_sql}
     ORDER BY {$order_by}",
    $params
)->results();

$sum = $db->query(
    "SELECT COUNT(*) AS n,
            COALESCE(SUM(co.total_amount - COALESCE(co.advance_paid,0) - COALESCE(co.amount_paid,0)),0) AS total_due,
            COALESCE(SUM(CASE WHEN co.order_number LIKE 'INV-INITIAL-%' THEN (co.total_amount - COALESCE(co.advance_paid,0) - COALESCE(co.amount_paid,0)) ELSE 0 END),0) AS opening_due
     FROM credit_orders co JOIN customers c ON c.id = co.customer_id
     {$balance_join}
     LEFT JOIN branches b ON b.id = co.assigned_branch_id
     WHERE {$where_sql}",
    $params
)->first();

$branches = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name")->results();

// Build a sortable-header link that preserves other filters
function oi_sort_link(string $col, string $label, string $cur_sort, string $cur_dir): string {
    $next_dir = ($cur_sort === $col && strtoupper($cur_dir) === 'ASC') ? 'desc' : 'asc';
    $q = array_merge($_GET, ['sort' => $col, 'dir' => $next_dir]);
    $arrow = $cur_sort === $col ? (strtoupper($cur_dir) === 'ASC' ? ' ▲' : ' ▼') : '';
    return '<a href="?' . htmlspecialchars(http_build_query($q)) . '" class="hover:underline">' . $label . $arrow . '</a>';
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 py-6">

<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-file-invoice-dollar text-red-500 mr-2"></i>Outstanding Invoices</h1>
        <p class="text-sm text-gray-500 mt-1">Every invoice with a balance due — regular credit orders and opening balances (INV-INITIAL), sortable.</p>
    </div>
    <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50"><i class="fas fa-arrow-left mr-2"></i>Dashboard</a>
</div>

<!-- Summary -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-xs text-gray-500 uppercase">Outstanding Invoices</p>
        <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo (int)$sum->n; ?></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-xs text-gray-500 uppercase">Total Outstanding</p>
        <p class="text-2xl font-bold text-red-600 mt-1">৳<?php echo number_format((float)$sum->total_due, 2); ?></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-xs text-gray-500 uppercase">Of which Opening Balances</p>
        <p class="text-2xl font-bold text-purple-600 mt-1">৳<?php echo number_format((float)$sum->opening_due, 2); ?></p>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4 flex flex-wrap items-end gap-3">
    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
    <input type="hidden" name="dir" value="<?php echo htmlspecialchars(strtolower($dir)); ?>">
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Search</label>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Customer or invoice #" class="px-3 py-2 border rounded-lg text-sm">
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Branch</label>
        <select name="branch_id" class="px-3 py-2 border rounded-lg text-sm">
            <option value="0">All branches</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?php echo (int)$b->id; ?>" <?php echo $branch_filter === (int)$b->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($b->name); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Type</label>
        <select name="type" class="px-3 py-2 border rounded-lg text-sm">
            <option value="all"     <?php echo $type_filter==='all'?'selected':''; ?>>All</option>
            <option value="regular" <?php echo $type_filter==='regular'?'selected':''; ?>>Credit orders</option>
            <option value="opening" <?php echo $type_filter==='opening'?'selected':''; ?>>Opening balances</option>
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Due Type</label>
        <select name="due_type" class="px-3 py-2 border rounded-lg text-sm">
            <option value="all"      <?php echo $due_type==='all'?'selected':''; ?>>All</option>
            <option value="due"      <?php echo $due_type==='due'?'selected':''; ?>>Due (owes)</option>
            <option value="advance"  <?php echo $due_type==='advance'?'selected':''; ?>>Advance (net credit)</option>
            <option value="no_limit" <?php echo $due_type==='no_limit'?'selected':''; ?>>No credit limit</option>
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Min Due (৳)</label>
        <input type="number" name="min_due" value="<?php echo $min_due ?: ''; ?>" min="0" class="w-28 px-3 py-2 border rounded-lg text-sm">
    </div>
    <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700">Apply</button>
    <a href="outstanding_invoices.php" class="px-3 py-2 text-sm text-gray-500 hover:underline">Reset</a>
</form>

<div class="bg-white rounded-xl shadow-md overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm divide-y divide-gray-200">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Invoice #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase"><?php echo oi_sort_link('date','Date',$sort,$dir); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase"><?php echo oi_sort_link('customer','Customer',$sort,$dir); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase"><?php echo oi_sort_link('branch','Branch',$sort,$dir); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-600 uppercase">Type</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-600 uppercase">Total</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-600 uppercase">Paid</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-600 uppercase"><?php echo oi_sort_link('amount','Due',$sort,$dir); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-600 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($rows)): ?>
                <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">No outstanding invoices for these filters. 🎉</td></tr>
                <?php else: foreach ($rows as $r):
                    $paid = (float)$r->advance_paid + (float)$r->amount_paid; ?>
                <tr class="hover:bg-gray-50 <?php echo $r->is_opening ? 'bg-purple-50/40' : ''; ?>" data-order-id="<?php echo (int)$r->id; ?>">
                    <td class="px-4 py-3 font-mono text-xs"><a href="credit_order_view.php?id=<?php echo (int)$r->id; ?>" class="text-blue-600 hover:underline"><?php echo htmlspecialchars($r->order_number); ?></a></td>
                    <td class="px-4 py-3 text-gray-600 whitespace-nowrap"><?php echo $r->order_date ? date('d M Y', strtotime($r->order_date)) : '—'; ?></td>
                    <td class="px-4 py-3 font-medium text-gray-800">
                        <?php echo htmlspecialchars($r->customer_name); ?>
                        <?php
                        if ((float)$r->credit_limit <= 0)          { $dt = ['No limit', 'bg-gray-200 text-gray-700']; }
                        elseif ((float)$r->cust_balance < -0.01)   { $dt = ['Advance', 'bg-emerald-100 text-emerald-700']; }
                        else                                       { $dt = ['Due', 'bg-red-100 text-red-700']; }
                        ?>
                        <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold <?php echo $dt[1]; ?>" title="Customer credit standing"><?php echo $dt[0]; ?></span>
                    </td>
                    <td class="px-4 py-3 text-gray-500"><?php echo htmlspecialchars($r->branch_name ?? '—'); ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($r->is_opening): ?>
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-purple-100 text-purple-700">Opening</span>
                        <?php else: ?>
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-100 text-blue-700">Credit</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">৳<?php echo number_format((float)$r->total_amount, 2); ?></td>
                    <td class="px-4 py-3 text-right whitespace-nowrap text-green-600">৳<?php echo number_format($paid, 2); ?></td>
                    <td class="px-4 py-3 text-right whitespace-nowrap font-bold text-red-600">৳<?php echo number_format((float)$r->due_amount, 2); ?></td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        <?php if ($is_accounts): ?>
                        <a href="customer_payment.php?order_id=<?php echo (int)$r->id; ?>" class="px-3 py-1.5 bg-green-600 text-white rounded-lg text-xs font-semibold hover:bg-green-700"><i class="fas fa-money-bill-wave mr-1"></i>Collect</a>
                        <?php else: ?>
                        <a href="credit_order_view.php?id=<?php echo (int)$r->id; ?>" class="text-xs text-blue-600 hover:underline">View</a>
                        <?php endif; ?>
                        <?php if ($is_admin): ?>
                        <button type="button"
                                onclick="oiDeleteOrder(<?php echo (int)$r->id; ?>, <?php echo htmlspecialchars(json_encode($r->order_number), ENT_QUOTES); ?>)"
                                class="ml-1 px-2 py-1.5 bg-red-50 text-red-600 border border-red-200 rounded-lg text-xs font-semibold hover:bg-red-100"
                                title="Permanently delete this order (Recycle Bin — reversible)">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<script>
// Permanently delete an order via the same Recycle Bin flow used on the main
// order list and the payment page — reversible, cascades payments/ledger/
// journal entries, and sends the standard "order deleted" Telegram notification
// to the Daily Order group (delete_order.php already does this — no page-specific
// notification code needed here).
function oiDeleteOrder(orderId, orderNumber) {
    if (!confirm(`Permanently delete order ${orderNumber}?\n\nThis moves it to the Recycle Bin (restorable from Admin → Recycle Bin) and reverses its payments/ledger entries.`)) {
        return;
    }
    fetch('delete_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `order_id=${orderId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            document.querySelector(`tr[data-order-id="${orderId}"]`)?.remove();
        } else {
            alert('Could not delete order: ' + data.message);
        }
    })
    .catch(() => alert('An error occurred while deleting the order.'));
}
</script>

<?php require_once '../templates/footer.php'; ?>

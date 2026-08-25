<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra',
                  'sales-srg', 'sales-demra', 'sales-other',
                  'production manager-srg', 'production manager-demra',
                  'dispatch-srg', 'dispatch-demra', 'dispatchpos-srg', 'dispatchpos-demra'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_role   = $currentUser['role'] ?? '';
$user_id     = $currentUser['id']   ?? null;
$pageTitle   = 'All Credit Sales';

$is_admin    = in_array($user_role, ['Superadmin', 'admin']);
// Privilege-based: replaces hardcoded role list — true only if explicitly granted via Privileges UI
$is_accounts = userCanPageAction('credit_sales', 'customer_payment', 'can_collect');
$is_sales    = in_array($user_role, ['sales-srg', 'sales-demra', 'sales-other']);

/* ─── Filters ──────────────────────────────────────────── */
$date_from     = $_GET['date_from']     ?? date('Y-m-d');   // default: today
$date_to       = $_GET['date_to']       ?? date('Y-m-d');
$status_filter = $_GET['status']        ?? '';
$customer_q    = trim($_GET['customer'] ?? '');
$branch_filter = (int)($_GET['branch_id'] ?? 0);   // Feature #14: branch filter
$per_page      = 50;
$page          = max(1, (int)($_GET['page'] ?? 1));
$offset        = ($page - 1) * $per_page;

/* ─── Query ─────────────────────────────────────────────── */
$conditions = ["co.order_date BETWEEN ? AND ?"];
$params     = [$date_from, $date_to];

if (!empty($status_filter)) {
    $conditions[] = "co.status = ?";
    $params[]      = $status_filter;
}
if (!empty($customer_q)) {
    $conditions[] = "(c.name LIKE ? OR c.phone_number LIKE ?)";
    $params[]      = "%{$customer_q}%";
    $params[]      = "%{$customer_q}%";
}
if ($branch_filter) {
    $conditions[] = "co.assigned_branch_id = ?";
    $params[]      = $branch_filter;
}
// Sales staff see only their own orders
if ($is_sales && !$is_admin && !$is_accounts) {
    $conditions[] = "co.created_by_user_id = ?";
    $params[]      = $user_id;
}

$where = implode(' AND ', $conditions);

$count_row = $db->query(
    "SELECT COUNT(*) AS total FROM credit_orders co
     JOIN customers c ON co.customer_id = c.id
     WHERE {$where}",
    $params
)->first();
$total_rows  = (int)($count_row->total ?? 0);
$total_pages = max(1, (int)ceil($total_rows / $per_page));

$stats_row = $db->query(
    "SELECT
        SUM(co.total_amount)   AS grand_total,
        SUM(co.amount_paid)    AS grand_paid,
        SUM(co.balance_due)    AS grand_due,
        COUNT(*)               AS order_count
     FROM credit_orders co
     JOIN customers c ON co.customer_id = c.id
     WHERE {$where}",
    $params
)->first();

$params_paged = array_merge($params, [$per_page, $offset]);
$orders = $db->query(
    "SELECT co.*,
            c.name            AS customer_name,
            c.phone_number    AS customer_phone,
            u.display_name    AS created_by_name,
            b.name            AS branch_name,
            (co.total_amount - co.amount_paid) AS due_amount
     FROM credit_orders co
     JOIN customers   c ON co.customer_id         = c.id
     LEFT JOIN users  u ON co.created_by_user_id  = u.id
     LEFT JOIN branches b ON co.assigned_branch_id = b.id
     WHERE {$where}
     ORDER BY co.order_date DESC, co.id DESC
     LIMIT ? OFFSET ?",
    $params_paged
)->results();

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
        <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
        <p class="text-gray-600 mt-1">Browse all credit sales orders with filtering and export.</p>
    </div>
    <div class="flex gap-3">
        <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-sm">
            <i class="fas fa-arrow-left mr-2"></i>Dashboard
        </a>
        <?php if ($is_admin || $is_accounts): ?>
        <a href="customer_payment.php" class="px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 text-sm">
            <i class="fas fa-money-bill-wave mr-2"></i>Record Payment
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- ── Filters ─────────────────────────────────────────────── -->
<div class="bg-white rounded-lg shadow-md p-5 mb-6">
    <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">From Date</label>
            <input type="date" name="date_from" value="<?php echo $date_from; ?>"
                   class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">To Date</label>
            <input type="date" name="date_to" value="<?php echo $date_to; ?>"
                   class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
            <select name="status" class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                <option value="">All Statuses</option>
                <?php
                $statuses = [
                    'pending_approval' => 'Pending Approval',
                    'escalated'        => 'Escalated',
                    'approved'         => 'Approved',
                    'in_production'    => 'In Production',
                    'produced'         => 'Produced',
                    'ready_to_ship'    => 'Ready to Ship',
                    'goods_on_board'   => 'Goods on Board',
                    'shipped'          => 'Shipped',
                    'delivered'        => 'Delivered',
                    'cancelled'        => 'Cancelled',
                ];
                foreach ($statuses as $val => $label): ?>
                <option value="<?php echo $val; ?>" <?php echo $status_filter === $val ? 'selected' : ''; ?>>
                    <?php echo $label; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Branch</label>
            <?php $sales_branches = $db->query("SELECT id, name FROM branches WHERE status='active' ORDER BY name")->results(); ?>
            <select name="branch_id" class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                <option value="0">All branches</option>
                <?php foreach ($sales_branches as $sb): ?>
                <option value="<?php echo (int)$sb->id; ?>" <?php echo $branch_filter === (int)$sb->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($sb->name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Customer / Phone</label>
            <input type="text" name="customer" value="<?php echo htmlspecialchars($customer_q); ?>"
                   placeholder="Search..." class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700">
                <i class="fas fa-filter mr-1"></i> Filter
            </button>
            <a href="all_sales.php" class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-600 hover:bg-gray-100" title="Reset">
                <i class="fas fa-times"></i>
            </a>
        </div>
    </form>
</div>

<!-- ── Summary cards ────────────────────────────────────────── -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
        <p class="text-xs text-gray-500 uppercase font-medium">Orders</p>
        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats_row->order_count ?? 0); ?></p>
    </div>
    <div class="bg-white rounded-lg shadow p-4 border-l-4 border-gray-400">
        <p class="text-xs text-gray-500 uppercase font-medium">Grand Total</p>
        <p class="text-2xl font-bold text-gray-900">৳<?php echo number_format($stats_row->grand_total ?? 0, 0); ?></p>
    </div>
    <div class="bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
        <p class="text-xs text-gray-500 uppercase font-medium">Collected</p>
        <p class="text-2xl font-bold text-green-700">৳<?php echo number_format($stats_row->grand_paid ?? 0, 0); ?></p>
    </div>
    <div class="bg-white rounded-lg shadow p-4 border-l-4 border-red-500">
        <p class="text-xs text-gray-500 uppercase font-medium">Outstanding</p>
        <p class="text-2xl font-bold text-red-700">৳<?php echo number_format($stats_row->grand_due ?? 0, 0); ?></p>
    </div>
</div>

<!-- ── Table ────────────────────────────────────────────────── -->
<div class="bg-white rounded-lg shadow-lg overflow-hidden mb-6">
    <div class="p-4 border-b bg-gray-50 flex justify-between items-center">
        <p class="text-sm text-gray-600">
            Showing <strong><?php echo number_format($total_rows); ?></strong> order<?php echo $total_rows !== 1 ? 's' : ''; ?>
            <?php if ($total_pages > 1): ?>
            — Page <?php echo $page; ?> of <?php echo $total_pages; ?>
            <?php endif; ?>
        </p>
        <a href="credit_history_export.php?date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&status=<?php echo urlencode($status_filter); ?>"
           class="px-3 py-1.5 bg-green-600 text-white text-xs font-medium rounded hover:bg-green-700">
            <i class="fas fa-file-csv mr-1"></i> Export CSV
        </a>
    </div>

    <?php if (!empty($orders)): ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Order #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Customer</th>
                    <?php if ($is_admin || $is_accounts): ?>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Created By</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Branch</th>
                    <?php endif; ?>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-600 uppercase">Total</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-600 uppercase">Paid</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-600 uppercase">Due</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-600 uppercase">Status</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-600 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php
                $status_colors = [
                    'draft'            => 'bg-gray-100 text-gray-800',
                    'pending_approval' => 'bg-yellow-100 text-yellow-800',
                    'approved'         => 'bg-blue-100 text-blue-800',
                    'escalated'        => 'bg-red-100 text-red-800',
                    'rejected'         => 'bg-gray-200 text-gray-600',
                    'in_production'    => 'bg-purple-100 text-purple-800',
                    'produced'         => 'bg-indigo-100 text-indigo-800',
                    'ready_to_ship'    => 'bg-orange-100 text-orange-800',
                    'goods_on_board'   => 'bg-indigo-100 text-indigo-800',
                    'shipped'          => 'bg-teal-100 text-teal-800',
                    'delivered'        => 'bg-green-100 text-green-800',
                    'cancelled'        => 'bg-gray-200 text-gray-600',
                ];
                foreach ($orders as $order):
                    $sc = $status_colors[$order->status] ?? 'bg-gray-100 text-gray-800';
                ?>
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3 text-sm font-medium text-blue-700">
                        <a href="credit_order_view.php?id=<?php echo $order->id; ?>" class="hover:underline">
                            <?php echo htmlspecialchars($order->order_number ?? ''); ?>
                        </a>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                        <?php echo date('d-M-Y', strtotime($order->order_date)); ?>
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($order->customer_name ?? '—'); ?></div>
                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($order->customer_phone ?? ''); ?></div>
                    </td>
                    <?php if ($is_admin || $is_accounts): ?>
                    <td class="px-4 py-3 text-sm text-gray-500"><?php echo htmlspecialchars($order->created_by_name ?? '—'); ?></td>
                    <td class="px-4 py-3 text-sm text-gray-500"><?php echo htmlspecialchars($order->branch_name ?? '—'); ?></td>
                    <?php endif; ?>
                    <td class="px-4 py-3 text-sm text-right font-semibold text-gray-800 whitespace-nowrap">
                        ৳<?php echo number_format($order->total_amount ?? 0, 2); ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-right text-green-700 whitespace-nowrap">
                        ৳<?php echo number_format($order->amount_paid ?? 0, 2); ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-right whitespace-nowrap <?php echo ($order->due_amount ?? 0) > 0 ? 'text-red-600 font-semibold' : 'text-green-600'; ?>">
                        ৳<?php echo number_format($order->due_amount ?? 0, 2); ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-1 text-xs font-bold rounded-full <?php echo $sc; ?>">
                            <?php echo ucwords(str_replace('_', ' ', $order->status ?? '')); ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        <a href="credit_order_view.php?id=<?php echo $order->id; ?>" class="text-blue-600 hover:text-blue-900 px-1" title="View">
                            <i class="fas fa-eye"></i>
                        </a>
                        <?php if (in_array($order->status, ['shipped','delivered']) && ($is_admin || $is_accounts)): ?>
                        <a href="credit_invoice_print.php?id=<?php echo $order->id; ?>" target="_blank" class="text-green-600 hover:text-green-900 px-1" title="Invoice">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (($is_admin || $is_accounts) && $order->due_amount > 0): ?>
                        <a href="customer_payment.php?order_id=<?php echo $order->id; ?>" class="text-teal-600 hover:text-teal-900 px-1" title="Collect Payment">
                            <i class="fas fa-money-bill-wave"></i>
                        </a>
                        <?php endif; ?>
                        <?php if ($is_admin): ?>
                        <a href="order_status.php?id=<?php echo $order->id; ?>" class="text-purple-500 hover:text-purple-800 px-1" title="Update Status">
                            <i class="fas fa-edit text-sm"></i>
                        </a>
                        <?php if ($user_role === 'Superadmin'): ?>
                        <button onclick="confirmDeleteOrder(this)"
                                data-id="<?php echo $order->id; ?>"
                                data-order-number="<?php echo htmlspecialchars($order->order_number ?? ''); ?>"
                                data-status="<?php echo htmlspecialchars($order->status); ?>"
                                class="text-red-500 hover:text-red-800 px-1 cursor-pointer bg-transparent border-0" title="Delete Order">
                            <i class="fas fa-trash text-sm"></i>
                        </button>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-gray-50 border-t-2 border-gray-300">
                <tr>
                    <td colspan="<?php echo ($is_admin || $is_accounts) ? 5 : 3; ?>"
                        class="px-4 py-3 text-sm font-bold text-gray-700">
                        Page Total (<?php echo count($orders); ?> rows)
                    </td>
                    <td class="px-4 py-3 text-right text-sm font-bold text-gray-800">
                        ৳<?php echo number_format(array_sum(array_column((array)$orders, 'total_amount')), 2); ?>
                    </td>
                    <td class="px-4 py-3 text-right text-sm font-bold text-green-700">
                        ৳<?php echo number_format(array_sum(array_column((array)$orders, 'amount_paid')), 2); ?>
                    </td>
                    <td class="px-4 py-3 text-right text-sm font-bold text-red-700">
                        ৳<?php echo number_format(array_sum(array_column((array)$orders, 'due_amount')), 2); ?>
                    </td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="p-4 border-t bg-gray-50 flex flex-wrap justify-center gap-1">
        <?php
        $base_params = http_build_query(array_filter([
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'status'    => $status_filter,
            'customer'  => $customer_q,
            'branch_id' => $branch_filter ?: null,
        ]));
        for ($p = 1; $p <= min($total_pages, 15); $p++):
        ?>
        <a href="?<?php echo $base_params; ?>&page=<?php echo $p; ?>"
           class="px-3 py-1 text-sm rounded <?php echo $p === $page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-100'; ?>">
            <?php echo $p; ?>
        </a>
        <?php endfor; ?>
        <?php if ($total_pages > 15): ?>
        <span class="px-3 py-1 text-sm text-gray-500">… <?php echo $total_pages; ?> pages total</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="p-12 text-center text-gray-500">
        <i class="fas fa-inbox text-5xl mb-4 text-gray-300"></i>
        <p class="text-lg font-medium">No orders found for the selected criteria.</p>
        <p class="text-sm mt-2">Try expanding the date range or clearing filters.</p>
    </div>
    <?php endif; ?>
</div>

</div>

<!-- ── Delete Order Modal ──────────────────────────────────────── -->
<div id="deleteOrderModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-black/50"
     style="display:none">
    <div class="bg-white rounded-2xl shadow-xl max-w-md w-full p-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                <i class="fas fa-exclamation-triangle text-red-600"></i>
            </div>
            <h2 class="text-lg font-bold text-gray-900">Delete Order</h2>
        </div>

        <p class="text-gray-700 mb-3">
            Permanently delete order <strong id="delOrderNum" class="text-red-700"></strong>?
        </p>

        <!-- Warning shown only for delivered orders -->
        <div id="delDeliveredWarning" class="hidden bg-red-50 border border-red-200 rounded-lg p-3 mb-4 text-sm text-red-800">
            <i class="fas fa-exclamation-circle mr-1"></i>
            <strong>This order is DELIVERED.</strong> All associated payments and ledger entries will also be permanently deleted. The customer's balance will be recalculated. This <em>cannot</em> be undone.
        </div>

        <p id="delStandardWarning" class="text-sm text-gray-500 mb-5">This action cannot be undone.</p>

        <div class="flex gap-3 justify-end">
            <button onclick="closeDeleteOrderModal()"
                    class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-sm">
                Cancel
            </button>
            <button id="delConfirmBtn" onclick="submitDeleteOrder()"
                    class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm font-medium">
                <i class="fas fa-trash mr-1"></i> Delete Permanently
            </button>
        </div>
    </div>
</div>

<script>
var _delOrderId  = null;
var _delOrderRow = null;

function confirmDeleteOrder(btn) {
    _delOrderId  = btn.dataset.id;
    _delOrderRow = btn.closest('tr');

    var num       = btn.dataset.orderNumber;
    var status    = btn.dataset.status;
    var delivered = (status === 'delivered');

    document.getElementById('delOrderNum').textContent = num;
    document.getElementById('delDeliveredWarning').style.display  = delivered ? '' : 'none';
    document.getElementById('delStandardWarning').style.display   = delivered ? 'none' : '';

    var modal = document.getElementById('deleteOrderModal');
    modal.style.display = 'flex';
}

function closeDeleteOrderModal() {
    document.getElementById('deleteOrderModal').style.display = 'none';
    _delOrderId  = null;
    _delOrderRow = null;
}

function submitDeleteOrder() {
    if (!_delOrderId) return;

    var btn = document.getElementById('delConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Deleting…';

    fetch('delete_order.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'order_id=' + encodeURIComponent(_delOrderId)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            closeDeleteOrderModal();
            if (_delOrderRow) _delOrderRow.remove();
            showDelToast('Order ' + data.order_number + ' deleted.', 'success');
        } else {
            showDelToast(data.message || 'Delete failed.', 'error');
        }
    })
    .catch(function() {
        showDelToast('Network error — please try again.', 'error');
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash mr-1"></i> Delete Permanently';
    });
}

function showDelToast(msg, type) {
    var div = document.createElement('div');
    div.className = 'fixed bottom-4 right-4 z-[60] px-4 py-3 rounded-lg text-sm font-medium shadow-lg text-white '
                  + (type === 'success' ? 'bg-green-600' : 'bg-red-600');
    div.textContent = msg;
    document.body.appendChild(div);
    setTimeout(function() { div.remove(); }, 4000);
}

// Close modal on backdrop click
document.getElementById('deleteOrderModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteOrderModal();
});
</script>

<?php require_once '../templates/footer.php'; ?>

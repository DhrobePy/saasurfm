<?php
/**
 * Customer Directory (Feature #4) — the Customers landing page.
 * Contact list: name, business, phone, address. Superadmin/Admin CRUD; other
 * users view if their privileges allow. The balance-focused view lives on
 * index.php ("Balances" sub-tab).
 */
require_once '../core/init.php';

$allowed_roles = [
    'Superadmin','admin','Accounts',
    'accounts-srg','accounts-demra','accountspos-demra','accountspos-srg',
    'sales-srg','sales-demra','sales-other','collector',
];
// New page_key — accept the existing "All Customers" (index) grant so privileged
// users aren't locked out.
if (!userHasPageGrant('customers', 'directory') && !userHasPageGrant('customers', 'index')) {
    restrict_access($allowed_roles, 'customers', 'directory');
}

global $db;
$pageTitle   = 'Customer Directory';
$csrfToken   = $_SESSION['csrf_token'] ?? '';
$userRole    = $_SESSION['user_role'] ?? '';
$currentUser = getCurrentUser();
$can_delete  = in_array($userRole, ['Superadmin', 'admin'], true);

/* ── Delete → Recycle Bin (same guard + cascade as the Balances page) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_customer'])) {
    try {
        if (!$can_delete) throw new Exception('Permission denied.');
        if (!$csrfToken || !hash_equals($csrfToken, $_POST['csrf_token'] ?? ''))
            throw new Exception('Invalid security token.');
        $del_id = (int)$_POST['delete_id'];
        $cust = $db->query("SELECT name FROM customers WHERE id = ?", [$del_id])->first();
        if (!$cust) throw new Exception('Customer not found.');

        $cust_bal = getCustomerOutstanding($del_id);
        if (abs($cust_bal) > 0.01) {
            throw new Exception('Cannot delete "' . ($cust->name ?? 'customer') . '" — they have '
                . ($cust_bal > 0 ? 'an outstanding due of ৳' : 'an advance balance of ৳')
                . number_format(abs($cust_bal), 2) . '. Settle the balance to zero first.');
        }

        ensureRecycleBinTables();
        $pdo = $db->getPdo();
        $pdo->beginTransaction();
        $batch = recycleBegin('customer', 'Customer — ' . ($cust->name ?? '') . " (#{$del_id})", $del_id);
        recycleArchiveCustomerCascade($batch, $del_id);
        recycleArchiveDelete($batch, 'customers', 'id', $del_id);
        recycleFinalize($batch);
        $pdo->commit();

        auditLog('customers', 'deleted',
            'Customer "' . ($cust->name ?? '') . "\" (#{$del_id}) moved to Recycle Bin (batch #{$batch}) with all related records, by "
            . ($currentUser['display_name'] ?? $userRole),
            ['customer_id' => $del_id, 'batch_id' => $batch, 'severity' => 'critical']);

        $_SESSION['success_flash'] = 'Customer and all related records moved to Recycle Bin (batch #' . $batch . ').';
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_flash'] = $e->getMessage();
    }
    header('Location: directory.php');
    exit();
}

/* ── Search + list ── */
$search = trim($_GET['q'] ?? '');
$type   = in_array($_GET['type'] ?? '', ['Credit', 'POS'], true) ? $_GET['type'] : '';
$where  = ["c.status != 'deleted'"];
$params = [];
if ($search !== '') {
    $where[] = "(c.name LIKE ? OR c.phone_number LIKE ? OR c.business_name LIKE ? OR c.business_address LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if ($type !== '') { $where[] = "c.customer_type = ?"; $params[] = $type; }
$where_sql = implode(' AND ', $where);

$customers = $db->query(
    "SELECT c.id, c.name, c.business_name, c.phone_number, c.email, c.business_address,
            c.customer_type, c.status
     FROM customers c
     WHERE {$where_sql}
     ORDER BY c.name ASC
     LIMIT 1000",
    $params
)->results();
$total = (int)($db->query("SELECT COUNT(*) AS c FROM customers WHERE status != 'deleted'")->first()->c ?? 0);

require_once '../templates/header.php';
?>
<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 py-6">

<!-- Sub-tabs -->
<div class="mb-5 flex items-center gap-2 border-b border-gray-200">
    <a href="directory.php" class="px-4 py-2 text-sm font-semibold text-green-600 border-b-2 border-green-500">
        <i class="fas fa-address-book mr-1"></i>Directory
    </a>
    <a href="index.php" class="px-4 py-2 text-sm font-semibold text-gray-500 hover:text-gray-800 border-b-2 border-transparent">
        <i class="fas fa-scale-balanced mr-1"></i>Balances
    </a>
</div>

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-address-book text-green-600 mr-2"></i>Customer Directory</h1>
        <p class="text-sm text-gray-500 mt-1"><?php echo $total; ?> customers · contact details</p>
    </div>
    <a href="manage.php" class="px-4 py-2 bg-green-600 text-white text-sm font-semibold rounded-lg hover:bg-green-700"><i class="fas fa-user-plus mr-1"></i>Add Customer</a>
</div>

<form method="GET" class="mb-4 flex flex-wrap items-end gap-3 bg-white rounded-xl border border-gray-100 shadow-sm p-4">
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Search</label>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, phone, business or address" class="w-72 px-3 py-2 border rounded-lg text-sm">
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Type</label>
        <select name="type" class="px-3 py-2 border rounded-lg text-sm">
            <option value="">All</option>
            <option value="Credit" <?php echo $type==='Credit'?'selected':''; ?>>Credit</option>
            <option value="POS"    <?php echo $type==='POS'?'selected':''; ?>>POS</option>
        </select>
    </div>
    <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700">Search</button>
    <a href="directory.php" class="px-3 py-2 text-sm text-gray-500 hover:underline">Reset</a>
</form>

<div class="bg-white rounded-xl shadow-md overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm divide-y divide-gray-200">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Business</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Phone</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Address</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-600 uppercase">Type</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-600 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($customers)): ?>
                <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">No customers found<?php echo $search !== '' ? ' for this search' : ''; ?>.</td></tr>
                <?php else: foreach ($customers as $c): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2.5">
                        <a href="view.php?id=<?php echo (int)$c->id; ?>" class="font-medium text-gray-800 hover:text-green-700"><?php echo htmlspecialchars($c->name); ?></a>
                        <?php if (!empty($c->email)): ?><span class="block text-[10px] text-gray-400"><?php echo htmlspecialchars($c->email); ?></span><?php endif; ?>
                    </td>
                    <td class="px-4 py-2.5 text-gray-600"><?php echo htmlspecialchars($c->business_name ?: '—'); ?></td>
                    <td class="px-4 py-2.5 text-gray-600 whitespace-nowrap"><?php echo htmlspecialchars($c->phone_number ?: '—'); ?></td>
                    <td class="px-4 py-2.5 text-gray-500 text-xs max-w-xs truncate" title="<?php echo htmlspecialchars($c->business_address ?? ''); ?>"><?php echo htmlspecialchars($c->business_address ?: '—'); ?></td>
                    <td class="px-4 py-2.5 text-center">
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold <?php echo $c->customer_type === 'POS' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'; ?>"><?php echo htmlspecialchars($c->customer_type ?: '—'); ?></span>
                    </td>
                    <td class="px-4 py-2.5 text-center whitespace-nowrap">
                        <div class="flex items-center justify-center gap-2">
                            <a href="view.php?id=<?php echo (int)$c->id; ?>" class="text-gray-400 hover:text-blue-600" title="View"><i class="fas fa-eye text-xs"></i></a>
                            <a href="manage.php?id=<?php echo (int)$c->id; ?>" class="text-gray-400 hover:text-green-600" title="Edit"><i class="fas fa-pencil-alt text-xs"></i></a>
                            <?php if ($can_delete): ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Delete <?php echo htmlspecialchars(addslashes($c->name)); ?>?\n\nThe customer AND all their records move to the Recycle Bin (restorable). Only possible if their balance is zero.');">
                                <input type="hidden" name="delete_customer" value="1">
                                <input type="hidden" name="delete_id" value="<?php echo (int)$c->id; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <button type="submit" class="text-gray-300 hover:text-red-500" title="Delete → Recycle Bin"><i class="fas fa-trash text-xs"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<?php require_once '../templates/footer.php'; ?>

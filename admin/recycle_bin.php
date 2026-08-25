<?php
require_once dirname(__DIR__) . '/core/init.php';

// Recycle Bin is a Superadmin-only recovery surface (Feature #3).
restrict_access(['Superadmin']);

global $db;
$currentUser = getCurrentUser();
$pageTitle   = 'Recycle Bin';

ensureRecycleBinTables();

/* ─── POST: restore / purge ─────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rb_action'])) {
    $batch = (int)($_POST['batch_id'] ?? 0);
    if ($_POST['rb_action'] === 'restore') {
        [$ok, $msg] = restoreRecycleBatch($batch);
        $_SESSION[$ok ? 'success_flash' : 'error_flash'] = $msg;
        if ($ok) {
            auditLog('cr_recycle_bin', 'restored', "Recycle batch #{$batch} restored by "
                . ($currentUser['display_name'] ?? 'Superadmin'), ['batch_id' => $batch]);
        }
    } elseif ($_POST['rb_action'] === 'purge') {
        if (purgeRecycleBatch($batch)) {
            auditLog('cr_recycle_bin', 'purged', "Recycle batch #{$batch} permanently purged by "
                . ($currentUser['display_name'] ?? 'Superadmin'), ['batch_id' => $batch]);
            $_SESSION['success_flash'] = "Batch #{$batch} permanently removed.";
        } else {
            $_SESSION['error_flash'] = "Could not purge batch #{$batch}.";
        }
    }
    header('Location: recycle_bin.php');
    exit();
}

/* ─── Load batches ──────────────────────────────────────────── */
$filter = in_array($_GET['status'] ?? 'deleted', ['deleted','restored','purged','all'], true)
        ? ($_GET['status'] ?? 'deleted') : 'deleted';
$where  = $filter === 'all' ? '' : "WHERE b.status = " . $db->getPdo()->quote($filter);

$batches = $db->query(
    "SELECT b.*, c.name AS customer_name
     FROM cr_recycle_bin b
     LEFT JOIN customers c ON c.id = b.customer_id
     {$where}
     ORDER BY b.deleted_at DESC
     LIMIT 200"
)->results();

$counts = [];
foreach ($db->query("SELECT status, COUNT(*) AS n FROM cr_recycle_bin GROUP BY status")->results() as $r) {
    $counts[$r->status] = (int)$r->n;
}

/* Selected batch detail (row list) */
$detail_id   = (int)($_GET['batch'] ?? 0);
$detail_rows = [];
if ($detail_id) {
    $detail_rows = $db->query(
        "SELECT op, source_table, source_pk FROM cr_recycle_bin_rows WHERE batch_id = ? ORDER BY id ASC",
        [$detail_id]
    )->results();
}

require_once dirname(__DIR__) . '/templates/header.php';

$badge = [
    'deleted'  => 'bg-amber-100 text-amber-800',
    'restored' => 'bg-green-100 text-green-700',
    'purged'   => 'bg-gray-100 text-gray-500',
];
?>

<div class="max-w-screen-xl mx-auto px-4 sm:px-6 py-6">

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-trash-restore text-red-600 mr-2"></i>Recycle Bin</h1>
        <p class="text-sm text-gray-500 mt-1">
            Deleted records are archived here, not erased. Restore an accidental deletion in full,
            or purge it permanently. Superadmin only.
        </p>
    </div>
    <div class="flex gap-1 text-xs">
        <?php foreach (['deleted' => 'Deleted', 'restored' => 'Restored', 'purged' => 'Purged', 'all' => 'All'] as $k => $lbl): ?>
        <a href="?status=<?php echo $k; ?>"
           class="px-3 py-1.5 rounded-lg font-medium <?php echo $filter === $k ? 'bg-red-600 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50'; ?>">
            <?php echo $lbl; ?><?php if ($k !== 'all' && isset($counts[$k])): ?> (<?php echo $counts[$k]; ?>)<?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!empty($_SESSION['error_flash'])): ?>
<div class="mb-4 p-4 bg-red-50 border border-red-300 rounded-lg text-red-800 text-sm">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['error_flash']); unset($_SESSION['error_flash']); ?>
</div>
<?php endif; ?>
<?php if (!empty($_SESSION['success_flash'])): ?>
<div class="mb-4 p-4 bg-green-50 border border-green-300 rounded-lg text-green-800 text-sm">
    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['success_flash']); unset($_SESSION['success_flash']); ?>
</div>
<?php endif; ?>

<div class="bg-white rounded-xl shadow-md overflow-hidden">
    <?php if (empty($batches)): ?>
    <div class="p-12 text-center text-gray-400">
        <i class="fas fa-trash-alt text-4xl mb-3 opacity-30"></i>
        <p class="text-sm">Nothing here.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">What was deleted</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Rows</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Deleted by</th>
                    <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($batches as $b): ?>
                <tr class="hover:bg-gray-50 <?php echo $detail_id === (int)$b->id ? 'bg-red-50/40' : ''; ?>">
                    <td class="px-4 py-2.5 text-gray-500"><?php echo (int)$b->id; ?></td>
                    <td class="px-4 py-2.5">
                        <a href="?status=<?php echo $filter; ?>&batch=<?php echo (int)$b->id; ?>" class="text-gray-800 hover:text-red-600">
                            <span class="font-medium"><?php echo htmlspecialchars($b->entity_type); ?></span>
                            — <?php echo htmlspecialchars($b->label ?? ''); ?>
                        </a>
                    </td>
                    <td class="px-4 py-2.5 text-gray-600"><?php echo htmlspecialchars($b->customer_name ?? '—'); ?></td>
                    <td class="px-4 py-2.5 text-center text-gray-500"><?php echo (int)$b->row_count; ?></td>
                    <td class="px-4 py-2.5 text-xs text-gray-500">
                        <?php echo htmlspecialchars($b->deleted_by_name ?? ''); ?><br>
                        <span class="text-gray-400"><?php echo date('d M Y, g:i A', strtotime($b->deleted_at)); ?></span>
                    </td>
                    <td class="px-4 py-2.5 text-center">
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-bold <?php echo $badge[$b->status] ?? ''; ?>"><?php echo strtoupper($b->status); ?></span>
                        <?php if ($b->status === 'restored' && $b->restored_at): ?>
                        <div class="text-[10px] text-gray-400 mt-0.5"><?php echo htmlspecialchars($b->restored_by_name ?? ''); ?>, <?php echo date('d M g:iA', strtotime($b->restored_at)); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-2.5 text-right whitespace-nowrap">
                        <?php if ($b->status === 'deleted'): ?>
                        <div class="inline-flex gap-2">
                            <form method="POST" onsubmit="return confirm('Restore all <?php echo (int)$b->row_count; ?> record(s) in this batch back to the live system?');">
                                <input type="hidden" name="rb_action" value="restore">
                                <input type="hidden" name="batch_id" value="<?php echo (int)$b->id; ?>">
                                <button class="px-3 py-1.5 bg-green-600 text-white rounded-lg text-xs font-bold hover:bg-green-700 cursor-pointer">
                                    <i class="fas fa-undo mr-1"></i>Restore
                                </button>
                            </form>
                            <form method="POST" onsubmit="return confirm('PERMANENTLY delete this batch? This cannot be undone.');">
                                <input type="hidden" name="rb_action" value="purge">
                                <input type="hidden" name="batch_id" value="<?php echo (int)$b->id; ?>">
                                <button class="px-3 py-1.5 border-2 border-red-400 text-red-600 rounded-lg text-xs font-bold hover:bg-red-50 cursor-pointer">
                                    <i class="fas fa-times mr-1"></i>Purge
                                </button>
                            </form>
                        </div>
                        <?php else: ?>
                        <span class="text-xs text-gray-300">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($detail_id === (int)$b->id && $detail_rows): ?>
                <tr class="bg-gray-50">
                    <td colspan="7" class="px-6 py-3">
                        <p class="text-xs font-semibold text-gray-500 mb-2">Archived rows (<?php echo count($detail_rows); ?>):</p>
                        <div class="flex flex-wrap gap-1.5">
                            <?php foreach ($detail_rows as $dr): ?>
                            <span class="px-2 py-0.5 rounded text-[11px] <?php echo $dr->op === 'update' ? 'bg-blue-100 text-blue-700' : 'bg-gray-200 text-gray-600'; ?>">
                                <?php echo $dr->op === 'update' ? '↺' : '×'; ?>
                                <?php echo htmlspecialchars($dr->source_table); ?>#<?php echo htmlspecialchars($dr->source_pk); ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-[10px] text-gray-400 mt-2">× = deleted row (re-inserted on restore) · ↺ = value change (reverted on restore)</p>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>

<?php
/**
 * Procurement Catalog (Phase 1) — admin/superadmin manage the goods that can be
 * procured: commodities, their per-commodity origins, and which supplier supplies
 * which goods. All deletes route to the Recycle Bin. This page does NOT change the
 * PO/GRN flow yet (Phase 2 wires the create-PO form to these).
 */
require_once __DIR__ . '/../core/init.php';
restrict_access(['Superadmin', 'admin'], 'purchase', 'purchase_procurement_catalog');

$pageTitle   = 'Procurement Catalog';
$currentUser = getCurrentUser();
$is_admin    = in_array($currentUser['role'] ?? '', ['Superadmin', 'admin'], true);

global $db;
ensureProcurementCatalogTables();

$UNITS = ['KG', 'MT', 'pcs', 'bag', 'litre', 'ton', 'box'];

// ── POST actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $active_tab = $_POST['active_tab'] ?? 'commodities';
    try {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token — refresh the page and try again.');
        }

        switch ($action) {
            case 'add_commodity': {
                $name = trim($_POST['name'] ?? '');
                $unit = in_array($_POST['unit'] ?? '', $UNITS, true) ? $_POST['unit'] : 'KG';
                $acct = (int)($_POST['inventory_account_id'] ?? 0) ?: null;
                if ($name === '') throw new Exception('Commodity name is required.');
                if ($db->query("SELECT id FROM purchase_commodities WHERE name = ?", [$name])->first()) {
                    throw new Exception("A commodity named \"{$name}\" already exists.");
                }
                $db->query("INSERT INTO purchase_commodities (name, unit, inventory_account_id) VALUES (?, ?, ?)", [$name, $unit, $acct]);
                auditLog('purchase_commodities', 'created', "Procurement commodity added: {$name} ({$unit})");
                $_SESSION['pc_flash'] = "Commodity \"{$name}\" added.";
                break;
            }
            case 'edit_commodity': {
                $cid  = (int)($_POST['commodity_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $unit = in_array($_POST['unit'] ?? '', $UNITS, true) ? $_POST['unit'] : 'KG';
                $acct = (int)($_POST['inventory_account_id'] ?? 0) ?: null;
                $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
                if (!$cid || $name === '') throw new Exception('Commodity name is required.');
                $dupe = $db->query("SELECT id FROM purchase_commodities WHERE name = ? AND id <> ?", [$name, $cid])->first();
                if ($dupe) throw new Exception("Another commodity named \"{$name}\" already exists.");
                $db->query("UPDATE purchase_commodities SET name = ?, unit = ?, inventory_account_id = ?, status = ? WHERE id = ?",
                    [$name, $unit, $acct, $status, $cid]);
                auditLog('purchase_commodities', 'updated', "Procurement commodity #{$cid} updated: {$name} ({$unit}, {$status})");
                $_SESSION['pc_flash'] = "Commodity \"{$name}\" updated.";
                break;
            }
            case 'delete_commodity': {
                $cid = (int)($_POST['commodity_id'] ?? 0);
                $c = $db->query("SELECT name FROM purchase_commodities WHERE id = ?", [$cid])->first();
                if (!$c) throw new Exception('Commodity not found.');
                $in_use = (int)($db->query("SELECT COUNT(*) c FROM purchase_orders_adnan WHERE commodity_id = ?", [$cid])->first()->c ?? 0);
                if ($in_use > 0) throw new Exception("\"{$c->name}\" is used by {$in_use} purchase order(s) and can't be deleted. Set it Inactive instead.");
                ensureRecycleBinTables();
                $pdo = $db->getPdo();
                $pdo->beginTransaction();
                $batch = recycleBegin('purchase_commodity', "Commodity: {$c->name}");
                recycleArchiveDelete($batch, 'purchase_commodity_origins', 'commodity_id', $cid);
                recycleArchiveDelete($batch, 'supplier_commodities',       'commodity_id', $cid);
                recycleArchiveDelete($batch, 'purchase_commodities',       'id',           $cid);
                recycleFinalize($batch);
                $pdo->commit();
                auditLog('purchase_commodities', 'soft_deleted', "Commodity {$c->name} moved to Recycle Bin (batch #{$batch})");
                $_SESSION['pc_flash'] = "Commodity \"{$c->name}\" moved to the Recycle Bin (restorable).";
                break;
            }
            case 'add_origin': {
                $cid    = (int)($_POST['commodity_id'] ?? 0);
                $origin = trim($_POST['origin_name'] ?? '');
                if (!$cid || $origin === '') throw new Exception('Origin name is required.');
                if (!$db->query("SELECT id FROM purchase_commodities WHERE id = ?", [$cid])->first()) throw new Exception('Commodity not found.');
                if ($db->query("SELECT id FROM purchase_commodity_origins WHERE commodity_id = ? AND origin_name = ?", [$cid, $origin])->first()) {
                    throw new Exception("Origin \"{$origin}\" already exists for this commodity.");
                }
                $db->query("INSERT INTO purchase_commodity_origins (commodity_id, origin_name) VALUES (?, ?)", [$cid, $origin]);
                auditLog('purchase_commodity_origins', 'created', "Origin \"{$origin}\" added to commodity #{$cid}");
                $_SESSION['pc_flash'] = "Origin \"{$origin}\" added.";
                break;
            }
            case 'delete_origin': {
                $oid = (int)($_POST['origin_id'] ?? 0);
                $o = $db->query("SELECT origin_name FROM purchase_commodity_origins WHERE id = ?", [$oid])->first();
                if (!$o) throw new Exception('Origin not found.');
                ensureRecycleBinTables();
                $batch = recycleBegin('purchase_commodity_origin', "Origin: {$o->origin_name}");
                recycleArchiveDelete($batch, 'purchase_commodity_origins', 'id', $oid);
                recycleFinalize($batch);
                auditLog('purchase_commodity_origins', 'soft_deleted', "Origin \"{$o->origin_name}\" moved to Recycle Bin (batch #{$batch})");
                $_SESSION['pc_flash'] = "Origin \"{$o->origin_name}\" removed (restorable).";
                break;
            }
            case 'save_supplier_commodities': {
                $sid = (int)($_POST['supplier_id'] ?? 0);
                $picked = array_map('intval', (array)($_POST['commodities'] ?? []));
                if (!$sid) throw new Exception('Supplier not found.');
                $db->query("DELETE FROM supplier_commodities WHERE supplier_id = ?", [$sid]);
                foreach (array_unique($picked) as $cid) {
                    if ($cid > 0) {
                        try { $db->query("INSERT INTO supplier_commodities (supplier_id, commodity_id) VALUES (?, ?)", [$sid, $cid]); } catch (Exception $e) {}
                    }
                }
                auditLog('supplier_commodities', 'updated', "Supplier #{$sid} goods list updated (" . count($picked) . " commodities)");
                $_SESSION['pc_flash'] = "Supplier goods updated.";
                break;
            }
            default:
                throw new Exception('Unknown action.');
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['pc_error'] = $e->getMessage();
    }
    header('Location: procurement_catalog.php?tab=' . urlencode($active_tab));
    exit;
}

// ── Data for render ─────────────────────────────────────────────────────────
$active_tab = in_array($_GET['tab'] ?? '', ['commodities', 'suppliers'], true) ? $_GET['tab'] : 'commodities';

$commodities = $db->query(
    "SELECT c.*, coa.name AS account_name, coa.account_number,
            (SELECT COUNT(*) FROM purchase_orders_adnan po WHERE po.commodity_id = c.id) AS po_count
     FROM purchase_commodities c
     LEFT JOIN chart_of_accounts coa ON coa.id = c.inventory_account_id
     ORDER BY c.status ASC, c.name ASC"
)->results();

$origins_by_commodity = [];
foreach ($db->query("SELECT id, commodity_id, origin_name, status FROM purchase_commodity_origins ORDER BY origin_name ASC")->results() as $o) {
    $origins_by_commodity[(int)$o->commodity_id][] = $o;
}

$inv_accounts = $db->query(
    "SELECT id, name, account_number FROM chart_of_accounts
     WHERE account_number LIKE '14%' OR account_type LIKE '%nventor%'
     ORDER BY account_number ASC, name ASC"
)->results();
if (empty($inv_accounts)) {
    $inv_accounts = $db->query("SELECT id, name, account_number FROM chart_of_accounts ORDER BY account_number ASC, name ASC")->results();
}

$suppliers = $db->query("SELECT id, company_name, supplier_code FROM suppliers ORDER BY company_name ASC")->results();
$supplier_commodities = [];
foreach ($db->query("SELECT supplier_id, commodity_id FROM supplier_commodities")->results() as $sc) {
    $supplier_commodities[(int)$sc->supplier_id][(int)$sc->commodity_id] = true;
}

$flash = $_SESSION['pc_flash']  ?? null; unset($_SESSION['pc_flash']);
$err   = $_SESSION['pc_error']  ?? null; unset($_SESSION['pc_error']);
$csrf  = $_SESSION['csrf_token'] ?? '';

require_once '../templates/header.php';
?>
<div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-boxes-stacked text-indigo-600 mr-2"></i>Procurement Catalog</h1>
            <p class="text-gray-600 mt-1 text-sm">Define the goods you procure, their origins, and which supplier supplies what. Deletes go to the Recycle Bin.</p>
        </div>
        <a href="purchase_adnan_index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-sm"><i class="fas fa-arrow-left mr-2"></i>Purchase Dashboard</a>
    </div>

    <?php if ($flash): ?><div class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-2.5 text-sm text-green-800"><i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-2.5 text-sm text-red-800"><i class="fas fa-triangle-exclamation mr-1"></i><?php echo htmlspecialchars($err); ?></div><?php endif; ?>

    <div class="flex flex-wrap gap-1 border-b border-gray-200 mb-6">
        <a href="?tab=commodities" class="px-5 py-2.5 text-sm font-semibold border-b-2 -mb-px transition <?php echo $active_tab === 'commodities' ? 'text-indigo-600 border-indigo-500' : 'text-gray-500 border-transparent hover:text-gray-800'; ?>"><i class="fas fa-wheat-awn mr-1.5"></i>Commodities &amp; Origins</a>
        <a href="?tab=suppliers" class="px-5 py-2.5 text-sm font-semibold border-b-2 -mb-px transition <?php echo $active_tab === 'suppliers' ? 'text-indigo-600 border-indigo-500' : 'text-gray-500 border-transparent hover:text-gray-800'; ?>"><i class="fas fa-people-carry-box mr-1.5"></i>Supplier &harr; Goods</a>
    </div>

<?php if ($active_tab === 'commodities'): ?>
    <!-- Add commodity -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-6">
        <h2 class="text-sm font-bold text-gray-800 mb-3"><i class="fas fa-plus-circle text-indigo-500 mr-1"></i>Add a commodity</h2>
        <form method="POST" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="add_commodity">
            <input type="hidden" name="active_tab" value="commodities">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Name</label>
                <input type="text" name="name" required placeholder="e.g. Maize, Bran, Packaging Bags" class="w-full px-3 py-2 border rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Unit</label>
                <select name="unit" class="w-full px-3 py-2 border rounded-lg text-sm">
                    <?php foreach ($UNITS as $u): ?><option value="<?php echo $u; ?>"><?php echo $u; ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Inventory account <span class="text-gray-400">(optional)</span></label>
                <select name="inventory_account_id" class="w-full px-3 py-2 border rounded-lg text-sm">
                    <option value="">— none —</option>
                    <?php foreach ($inv_accounts as $a): ?>
                    <option value="<?php echo (int)$a->id; ?>"><?php echo htmlspecialchars(($a->account_number ? $a->account_number . ' · ' : '') . $a->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700">Add commodity</button>
            </div>
        </form>
    </div>

    <!-- Commodity list -->
    <div class="space-y-4">
        <?php foreach ($commodities as $c):
            $origins = $origins_by_commodity[(int)$c->id] ?? []; ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <!-- Edit commodity -->
                <form method="POST" class="flex flex-wrap items-end gap-2">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="edit_commodity">
                    <input type="hidden" name="active_tab" value="commodities">
                    <input type="hidden" name="commodity_id" value="<?php echo (int)$c->id; ?>">
                    <div>
                        <label class="block text-[10px] uppercase tracking-wide text-gray-400 mb-0.5">Name</label>
                        <input type="text" name="name" value="<?php echo htmlspecialchars($c->name); ?>" class="px-2.5 py-1.5 border rounded-lg text-sm font-semibold w-44">
                    </div>
                    <div>
                        <label class="block text-[10px] uppercase tracking-wide text-gray-400 mb-0.5">Unit</label>
                        <select name="unit" class="px-2.5 py-1.5 border rounded-lg text-sm">
                            <?php foreach ($UNITS as $u): ?><option value="<?php echo $u; ?>" <?php echo $c->unit === $u ? 'selected' : ''; ?>><?php echo $u; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] uppercase tracking-wide text-gray-400 mb-0.5">Inventory account</label>
                        <select name="inventory_account_id" class="px-2.5 py-1.5 border rounded-lg text-sm w-56">
                            <option value="">— none —</option>
                            <?php foreach ($inv_accounts as $a): ?>
                            <option value="<?php echo (int)$a->id; ?>" <?php echo (int)$c->inventory_account_id === (int)$a->id ? 'selected' : ''; ?>><?php echo htmlspecialchars(($a->account_number ? $a->account_number . ' · ' : '') . $a->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] uppercase tracking-wide text-gray-400 mb-0.5">Status</label>
                        <select name="status" class="px-2.5 py-1.5 border rounded-lg text-sm">
                            <option value="active"   <?php echo $c->status === 'active'   ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $c->status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <button type="submit" class="px-3 py-1.5 bg-gray-800 text-white text-xs font-semibold rounded-lg hover:bg-black">Save</button>
                    <span class="text-xs text-gray-400 self-center"><?php echo (int)$c->po_count; ?> PO(s)</span>
                </form>
                <!-- Delete commodity -->
                <form method="POST" onsubmit="return confirm('Move commodity &quot;<?php echo htmlspecialchars($c->name, ENT_QUOTES); ?>&quot; to the Recycle Bin?');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="delete_commodity">
                    <input type="hidden" name="active_tab" value="commodities">
                    <input type="hidden" name="commodity_id" value="<?php echo (int)$c->id; ?>">
                    <button type="submit" class="px-2.5 py-1.5 text-xs font-semibold text-red-600 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100"><i class="fas fa-trash-can"></i></button>
                </form>
            </div>

            <!-- Origins -->
            <div class="mt-4 pt-4 border-t border-gray-100">
                <div class="text-xs font-semibold text-gray-500 mb-2">Origins</div>
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <?php if (empty($origins)): ?>
                        <span class="text-xs text-gray-400 italic">No origins yet.</span>
                    <?php else: foreach ($origins as $o): ?>
                        <span class="inline-flex items-center gap-1.5 pl-3 pr-1.5 py-1 bg-gray-100 rounded-full text-xs text-gray-700">
                            <?php echo htmlspecialchars($o->origin_name); ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Remove origin &quot;<?php echo htmlspecialchars($o->origin_name, ENT_QUOTES); ?>&quot;?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="action" value="delete_origin">
                                <input type="hidden" name="active_tab" value="commodities">
                                <input type="hidden" name="origin_id" value="<?php echo (int)$o->id; ?>">
                                <button type="submit" class="w-4 h-4 flex items-center justify-center rounded-full text-gray-400 hover:text-red-600 hover:bg-red-100">&times;</button>
                            </form>
                        </span>
                    <?php endforeach; endif; ?>
                </div>
                <form method="POST" class="flex items-center gap-2">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="add_origin">
                    <input type="hidden" name="active_tab" value="commodities">
                    <input type="hidden" name="commodity_id" value="<?php echo (int)$c->id; ?>">
                    <input type="text" name="origin_name" required placeholder="Add an origin (e.g. Local, Canada)" class="px-3 py-1.5 border rounded-lg text-sm w-64">
                    <button type="submit" class="px-3 py-1.5 bg-indigo-50 text-indigo-700 border border-indigo-200 text-xs font-semibold rounded-lg hover:bg-indigo-100"><i class="fas fa-plus mr-1"></i>Add origin</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

<?php else: /* suppliers tab */ ?>
    <div class="space-y-4">
        <?php if (empty($suppliers)): ?>
            <div class="bg-white rounded-xl border border-gray-100 p-6 text-center text-sm text-gray-500">No suppliers yet. Add one from the Purchase module first.</div>
        <?php endif; ?>
        <?php foreach ($suppliers as $s):
            $picked = $supplier_commodities[(int)$s->id] ?? []; ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="save_supplier_commodities">
                <input type="hidden" name="active_tab" value="suppliers">
                <input type="hidden" name="supplier_id" value="<?php echo (int)$s->id; ?>">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                    <div class="font-semibold text-gray-800"><?php echo htmlspecialchars($s->company_name); ?>
                        <?php if ($s->supplier_code): ?><span class="text-xs text-gray-400 font-normal">(<?php echo htmlspecialchars($s->supplier_code); ?>)</span><?php endif; ?>
                    </div>
                    <button type="submit" class="px-3 py-1.5 bg-gray-800 text-white text-xs font-semibold rounded-lg hover:bg-black">Save goods</button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($commodities as $c): if ($c->status !== 'active') continue; ?>
                    <label class="inline-flex items-center gap-1.5 px-3 py-1.5 border rounded-lg text-sm cursor-pointer <?php echo isset($picked[(int)$c->id]) ? 'bg-indigo-50 border-indigo-300 text-indigo-800' : 'border-gray-200 text-gray-600 hover:bg-gray-50'; ?>">
                        <input type="checkbox" name="commodities[]" value="<?php echo (int)$c->id; ?>" <?php echo isset($picked[(int)$c->id]) ? 'checked' : ''; ?>>
                        <?php echo htmlspecialchars($c->name); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

</div>
<?php require_once '../templates/footer.php'; ?>
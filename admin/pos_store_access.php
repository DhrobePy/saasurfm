<?php
require_once '../core/init.php';

/**
 * POS Zones & Stores — Phase 1 Foundation of the standalone POS subsystem.
 * Superadmin/admin only: create/manage zones + stores, and assign users to
 * one or more stores (pos_store_users) — the data getUserStoreScope() /
 * resolveStoreFilterUI() (core/functions/helpers.php) read from. See the
 * approved plan: "POS module: fork into a fully standalone, multi-store
 * retail subsystem" (2 Oct 2026).
 */
restrict_access(['Superadmin', 'admin'], 'admin', 'pos_store_access');

global $db;
$currentUser = getCurrentUser();
$user_id     = $currentUser['id'] ?? null;
$pageTitle   = 'POS Zones & Stores';

ensurePosFoundationTables();   // DDL before any transaction — same convention as every other ensure*() call site

$error   = null;
$success = null;

/* ─── POST: create/update zones, stores, and user↔store assignments ───────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_zone') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new Exception('Zone name is required.');
            $db->insert('pos_zones', ['name' => $name, 'description' => trim($_POST['description'] ?? '') ?: null]);
            $_SESSION['success_flash'] = "Zone \"{$name}\" created.";

        } elseif ($action === 'toggle_zone_status') {
            $zone_id = (int)($_POST['zone_id'] ?? 0);
            $zone = $db->query("SELECT status FROM pos_zones WHERE id = ?", [$zone_id])->first();
            if (!$zone) throw new Exception('Zone not found.');
            $new_status = $zone->status === 'active' ? 'inactive' : 'active';
            $db->query("UPDATE pos_zones SET status = ? WHERE id = ?", [$new_status, $zone_id]);
            $_SESSION['success_flash'] = "Zone marked {$new_status}.";

        } elseif ($action === 'create_store') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new Exception('Store name is required.');
            $zone_id = (int)($_POST['zone_id'] ?? 0) ?: null;
            $db->insert('pos_stores', [
                'zone_id'      => $zone_id,
                'name'         => $name,
                'code'         => trim($_POST['code'] ?? '') ?: null,
                'address'      => trim($_POST['address'] ?? '') ?: null,
                'phone_number' => trim($_POST['phone_number'] ?? '') ?: null,
            ]);
            $_SESSION['success_flash'] = "Store \"{$name}\" created.";

        } elseif ($action === 'toggle_store_status') {
            $store_id = (int)($_POST['store_id'] ?? 0);
            $store = $db->query("SELECT status FROM pos_stores WHERE id = ?", [$store_id])->first();
            if (!$store) throw new Exception('Store not found.');
            $new_status = $store->status === 'active' ? 'inactive' : 'active';
            $db->query("UPDATE pos_stores SET status = ? WHERE id = ?", [$new_status, $store_id]);
            $_SESSION['success_flash'] = "Store marked {$new_status}.";

        } elseif ($action === 'save_assignments') {
            // Full-replace of one user's store assignments from a checkbox grid —
            // simpler and less error-prone than diffing add/remove one at a time.
            $target_user_id = (int)($_POST['target_user_id'] ?? 0);
            if (!$target_user_id) throw new Exception('Select a user.');
            $store_ids = array_map('intval', $_POST['store_ids'] ?? []);
            $primary_store_id = (int)($_POST['primary_store_id'] ?? 0);

            $db->query("DELETE FROM pos_store_users WHERE user_id = ?", [$target_user_id]);
            foreach ($store_ids as $sid) {
                if ($sid <= 0) continue;
                $db->insert('pos_store_users', [
                    'user_id'    => $target_user_id,
                    'store_id'   => $sid,
                    'is_primary' => ($sid === $primary_store_id) ? 1 : 0,
                ]);
            }
            $target_user = $db->query("SELECT display_name FROM users WHERE id = ?", [$target_user_id])->first();
            $_SESSION['success_flash'] = "Store access updated for " . ($target_user->display_name ?? 'user') . ' (' . count($store_ids) . ' store(s)).';

        } else {
            throw new Exception('Unknown action.');
        }
    } catch (Exception $e) {
        $_SESSION['error_flash'] = $e->getMessage();
    }
    header('Location: pos_store_access.php');
    exit();
}

$success = $_SESSION['success_flash'] ?? null; unset($_SESSION['success_flash']);
$error   = $_SESSION['error_flash']   ?? null; unset($_SESSION['error_flash']);

/* ─── Load data ─────────────────────────────────────────────────────────── */
$zones  = $db->query("SELECT * FROM pos_zones ORDER BY name ASC")->results();
$stores = $db->query(
    "SELECT s.*, z.name AS zone_name
     FROM pos_stores s LEFT JOIN pos_zones z ON s.zone_id = z.id
     ORDER BY z.name ASC, s.name ASC"
)->results();
$active_zones  = array_values(array_filter($zones,  fn($z) => $z->status === 'active'));
$active_stores = array_values(array_filter($stores, fn($s) => $s->status === 'active'));

$users = $db->query(
    "SELECT id, display_name, role, status FROM users WHERE status = 'active' ORDER BY display_name ASC"
)->results();

// Current assignments, grouped by user_id, for the grid's pre-checked state.
$assignments_raw = $db->query(
    "SELECT su.user_id, su.store_id, su.is_primary, s.name AS store_name
     FROM pos_store_users su JOIN pos_stores s ON su.store_id = s.id
     ORDER BY su.user_id"
)->results();
$assignments_by_user = [];
foreach ($assignments_raw as $a) {
    $assignments_by_user[$a->user_id][] = $a;
}

$selected_user_id = (int)($_GET['user_id'] ?? 0);

require_once '../templates/header.php';
?>

<div class="container mx-auto px-4 py-6 max-w-6xl">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">POS Zones &amp; Stores</h1>
            <p class="text-sm text-gray-500 mt-1">Phase 1 of the standalone POS subsystem — zones, stores, and which users can see which store's data.</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-800 rounded-lg"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-800 rounded-lg"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Zones -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h2 class="text-lg font-bold text-gray-900 mb-3"><i class="fas fa-map-marked-alt text-rose-500 mr-1"></i> Zones</h2>
            <form method="POST" class="flex gap-2 mb-4">
                <input type="hidden" name="action" value="create_zone">
                <input type="text" name="name" placeholder="Zone name (e.g. Dhaka Zone)" required
                       class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <input type="text" name="description" placeholder="Description (optional)"
                       class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <button type="submit" class="bg-rose-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-rose-700 cursor-pointer">Add</button>
            </form>
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500 border-b"><th class="py-1">Name</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($zones as $z): ?>
                    <tr class="border-b border-gray-50">
                        <td class="py-2 font-medium"><?php echo htmlspecialchars($z->name); ?>
                            <?php if ($z->description): ?><div class="text-xs text-gray-400"><?php echo htmlspecialchars($z->description); ?></div><?php endif; ?>
                        </td>
                        <td><span class="px-2 py-0.5 rounded text-xs <?php echo $z->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>"><?php echo htmlspecialchars($z->status); ?></span></td>
                        <td class="text-right">
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="toggle_zone_status">
                                <input type="hidden" name="zone_id" value="<?php echo (int)$z->id; ?>">
                                <button type="submit" class="text-xs text-gray-500 hover:text-gray-800 cursor-pointer"><?php echo $z->status === 'active' ? 'Deactivate' : 'Activate'; ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($zones)): ?><tr><td colspan="3" class="py-3 text-gray-400 text-center">No zones yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Stores -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h2 class="text-lg font-bold text-gray-900 mb-3"><i class="fas fa-store text-rose-500 mr-1"></i> Stores</h2>
            <form method="POST" class="space-y-2 mb-4">
                <input type="hidden" name="action" value="create_store">
                <div class="flex gap-2">
                    <input type="text" name="name" placeholder="Store name" required class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <select name="zone_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="">No zone</option>
                        <?php foreach ($active_zones as $z): ?>
                            <option value="<?php echo (int)$z->id; ?>"><?php echo htmlspecialchars($z->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2">
                    <input type="text" name="code" placeholder="Store code (optional)" class="w-32 border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <input type="text" name="address" placeholder="Address (optional)" class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <input type="text" name="phone_number" placeholder="Phone (optional)" class="w-36 border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <button type="submit" class="bg-rose-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-rose-700 cursor-pointer">Add</button>
                </div>
            </form>
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500 border-b"><th class="py-1">Store</th><th>Zone</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($stores as $s): ?>
                    <tr class="border-b border-gray-50">
                        <td class="py-2 font-medium"><?php echo htmlspecialchars($s->name); ?><?php if ($s->code): ?> <span class="text-xs text-gray-400">(<?php echo htmlspecialchars($s->code); ?>)</span><?php endif; ?></td>
                        <td class="text-gray-500"><?php echo htmlspecialchars($s->zone_name ?: '—'); ?></td>
                        <td><span class="px-2 py-0.5 rounded text-xs <?php echo $s->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>"><?php echo htmlspecialchars($s->status); ?></span></td>
                        <td class="text-right">
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="toggle_store_status">
                                <input type="hidden" name="store_id" value="<?php echo (int)$s->id; ?>">
                                <button type="submit" class="text-xs text-gray-500 hover:text-gray-800 cursor-pointer"><?php echo $s->status === 'active' ? 'Deactivate' : 'Activate'; ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($stores)): ?><tr><td colspan="4" class="py-3 text-gray-400 text-center">No stores yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- User <-> Store access -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h2 class="text-lg font-bold text-gray-900 mb-1"><i class="fas fa-user-lock text-rose-500 mr-1"></i> Store Access</h2>
        <p class="text-xs text-gray-500 mb-4">Pick a user, then tick which store(s) they can see in POS. Superadmin/admin always see every store and need no assignment. Saving replaces that user's entire store list.</p>

        <form method="GET" class="mb-4">
            <select name="user_id" onchange="this.form.submit()" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">— Select a user —</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?php echo (int)$u->id; ?>" <?php echo $selected_user_id === (int)$u->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($u->display_name); ?> (<?php echo htmlspecialchars($u->role); ?>)
                        <?php if (!empty($assignments_by_user[$u->id])) echo ' — ' . count($assignments_by_user[$u->id]) . ' store(s)'; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($selected_user_id):
            $current = $assignments_by_user[$selected_user_id] ?? [];
            $current_store_ids = array_map(fn($a) => (int)$a->store_id, $current);
            $current_primary = 0;
            foreach ($current as $a) { if ($a->is_primary) $current_primary = (int)$a->store_id; }
        ?>
        <form method="POST">
            <input type="hidden" name="action" value="save_assignments">
            <input type="hidden" name="target_user_id" value="<?php echo $selected_user_id; ?>">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-2 mb-4">
                <?php foreach ($active_stores as $s): $checked = in_array((int)$s->id, $current_store_ids, true); ?>
                <label class="flex items-center gap-2 px-3 py-2 border border-gray-200 rounded-lg text-sm cursor-pointer hover:bg-gray-50">
                    <input type="checkbox" name="store_ids[]" value="<?php echo (int)$s->id; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                    <span><?php echo htmlspecialchars($s->name); ?><?php if ($s->zone_name): ?> <span class="text-gray-400">(<?php echo htmlspecialchars($s->zone_name); ?>)</span><?php endif; ?></span>
                </label>
                <?php endforeach; ?>
                <?php if (empty($active_stores)): ?><div class="text-gray-400 text-sm">No active stores yet — add one above first.</div><?php endif; ?>
            </div>
            <div class="flex items-center gap-2 mb-4">
                <label class="text-sm text-gray-600">Primary store:</label>
                <select name="primary_store_id" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
                    <option value="">None</option>
                    <?php foreach ($active_stores as $s): ?>
                        <option value="<?php echo (int)$s->id; ?>" <?php echo $current_primary === (int)$s->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($s->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="bg-rose-600 text-white px-5 py-2 rounded-lg text-sm font-semibold hover:bg-rose-700 cursor-pointer">Save Store Access</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>

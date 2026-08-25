<?php
require_once '../core/init.php';

// Read-only overview — Superadmin/Admin only.
restrict_access(['Superadmin', 'admin']);

global $db;
$currentUser = getCurrentUser();
$pageTitle   = 'Role Access Matrix';

$registry = getModuleRegistry();
// Modules to show as columns (skip the admin module itself)
$modules = [];
foreach ($registry as $key => $def) {
    if ($key === 'admin') continue;
    $modules[$key] = $def['label'] ?? ucfirst($key);
}

// Active users per role
$role_totals = [];
foreach ($db->query("SELECT role, COUNT(*) AS n FROM users WHERE status = 'active' GROUP BY role ORDER BY role")->results() as $r) {
    $role_totals[$r->role] = (int)$r->n;
}

// How many active users of each role have each module explicitly enabled
$enabled = []; // [role][module] = count
foreach ($db->query(
    "SELECT u.role, ucp.module, COUNT(DISTINCT ucp.user_id) AS c
     FROM user_custom_permissions ucp
     JOIN users u ON u.id = ucp.user_id AND u.status = 'active'
     WHERE ucp.is_module_enabled = 1
     GROUP BY u.role, ucp.module"
)->results() as $r) {
    $enabled[$r->role][$r->module] = (int)$r->c;
}

// Users with NO custom-perm rows at all — they fall back to per-page role lists
$fallback_counts = []; // [role] = count of users with zero custom perms
foreach ($db->query(
    "SELECT u.role, COUNT(*) AS n
     FROM users u
     WHERE u.status = 'active'
       AND NOT EXISTS (SELECT 1 FROM user_custom_permissions x WHERE x.user_id = u.id)
     GROUP BY u.role"
)->results() as $r) {
    $fallback_counts[$r->role] = (int)$r->n;
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 py-6">

<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-table-cells text-purple-600 mr-2"></i>Role Access Matrix</h1>
        <p class="text-sm text-gray-500 mt-1">
            Which modules each role's users can reach, aggregated from per-user privileges. Read-only overview —
            manage access in <a href="privileges.php" class="text-purple-600 hover:underline">User Privileges</a>.
        </p>
    </div>
    <a href="users.php" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50"><i class="fas fa-users mr-2"></i>Users</a>
</div>

<div class="mb-4 flex flex-wrap gap-4 text-xs text-gray-500">
    <span><span class="inline-block w-3 h-3 rounded bg-green-500 align-middle mr-1"></span>All users of the role</span>
    <span><span class="inline-block w-3 h-3 rounded bg-amber-400 align-middle mr-1"></span>Some users</span>
    <span><span class="inline-block w-3 h-3 rounded bg-gray-200 align-middle mr-1"></span>None (explicit)</span>
    <span><i class="fas fa-shield-halved text-blue-400"></i> = users on role-default access (no custom privileges set)</span>
</div>

<div class="bg-white rounded-xl shadow-md overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm border-collapse">
            <thead class="bg-gray-800 text-white">
                <tr>
                    <th class="px-4 py-3 text-left font-medium sticky left-0 bg-gray-800">Role</th>
                    <th class="px-3 py-3 text-center font-medium">Users</th>
                    <?php foreach ($modules as $label): ?>
                    <th class="px-3 py-3 text-center font-medium whitespace-nowrap"><?php echo htmlspecialchars($label); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($role_totals)): ?>
                <tr><td colspan="<?php echo count($modules) + 2; ?>" class="px-4 py-8 text-center text-gray-400">No active users.</td></tr>
                <?php else: foreach ($role_totals as $role => $total):
                    $fb = $fallback_counts[$role] ?? 0; ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2.5 font-semibold text-gray-800 sticky left-0 bg-white whitespace-nowrap">
                        <?php echo htmlspecialchars($role); ?>
                        <?php if ($fb > 0): ?><i class="fas fa-shield-halved text-blue-400 ml-1" title="<?php echo $fb; ?> user(s) on role-default access"></i><?php endif; ?>
                    </td>
                    <td class="px-3 py-2.5 text-center text-gray-500"><?php echo $total; ?></td>
                    <?php foreach ($modules as $mkey => $label):
                        $cnt = $enabled[$role][$mkey] ?? 0;
                        if ($cnt >= $total && $total > 0) { $cls = 'bg-green-500 text-white'; $txt = 'All'; }
                        elseif ($cnt > 0)                 { $cls = 'bg-amber-400 text-white'; $txt = $cnt . '/' . $total; }
                        else                              { $cls = 'bg-gray-100 text-gray-400'; $txt = '—'; }
                    ?>
                    <td class="px-3 py-2.5 text-center">
                        <span class="inline-block min-w-[2.5rem] px-2 py-0.5 rounded text-[11px] font-bold <?php echo $cls; ?>"><?php echo $txt; ?></span>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="text-xs text-gray-400 mt-3">
    Cells count active users of each role who have the module <strong>explicitly enabled</strong> in their privileges.
    Users with no custom privileges (🛡) fall back to each page's built-in role list, so they may still reach some pages
    not shown here. Set precise access per user in <a href="privileges.php" class="underline">User Privileges</a>.
</p>
</div>

<?php require_once '../templates/footer.php'; ?>

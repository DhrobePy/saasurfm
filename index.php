<?php
// new_ufmhrm/index.php
// Landing page for all logged-in users.

require_once __DIR__ . '/core/init.php';

// Must be logged in.
if (!is_admin_logged_in()) {
    header('Location: auth/login.php');
    exit();
}

$role     = $_SESSION['user_role'] ?? null;
$is_admin = in_array($role, ['Superadmin', 'admin']);

// Admins get their own dashboard.
if ($is_admin) {
    header('Location: admin/index.php');
    exit();
}

// ── Privilege-based users (all other roles) ───────────────────────────────
// With the pure-privilege model, routing by hardcoded role is unreliable:
// a user denied by restrict_access() is sent HERE, and sending them straight
// to a module page again creates an infinite redirect loop.
//
// Instead, show every non-admin user a generic home that lists their
// accessible modules. The nav handles all module navigation.
//
// Legacy role-specific redirects are intentionally removed. Users reach
// module pages via the header nav (which is privilege-filtered).

$pageTitle    = 'Home';
$currentUser  = getCurrentUser();
$custom_perms = getUserCustomPerms();

// Build a list of modules this user can access (for the quick-access tiles)
$registry       = getModuleRegistry();
$visible_modules = [];
foreach ($registry as $module_key => $module_def) {
    if ($module_key === 'admin') continue;
    if (!empty($custom_perms[$module_key]) && $custom_perms[$module_key]['enabled']) {
        // First nav page becomes the tile link
        $first_nav = $module_def['nav'][0] ?? null;
        if ($first_nav) {
            $visible_modules[] = [
                'label'  => $module_def['label'],
                'icon'   => $module_def['icon'],
                'color'  => $module_def['color'],
                'url'    => url($module_def['folder'] . '/' . $first_nav['file'] . '.php'),
            ];
        }
    }
}

require_once __DIR__ . '/templates/header.php';
?>

<div class="max-w-5xl mx-auto px-4 py-10">

    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">
            Welcome, <?php echo htmlspecialchars($currentUser['display_name'] ?? 'User'); ?>
        </h1>
        <p class="text-gray-500 mt-1 text-sm">Select a module from the navigation bar above, or use the shortcuts below.</p>
    </div>

    <?php if (empty($visible_modules)): ?>
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-yellow-800 text-sm flex gap-3">
        <i class="fas fa-exclamation-triangle mt-0.5 flex-shrink-0"></i>
        <div>
            <strong>No modules enabled.</strong> Your account has no module access configured yet.
            Please contact your administrator to set up your privileges.
        </div>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
        <?php foreach ($visible_modules as $mod): ?>
        <a href="<?php echo $mod['url']; ?>"
           class="flex flex-col items-center justify-center gap-3 p-5 bg-white rounded-xl shadow-sm border border-gray-100
                  hover:shadow-md hover:border-<?php echo $mod['color']; ?>-200 transition-all group">
            <div class="w-12 h-12 rounded-xl bg-<?php echo $mod['color']; ?>-100 flex items-center justify-center
                        group-hover:bg-<?php echo $mod['color']; ?>-200 transition-colors">
                <i class="fas <?php echo $mod['icon']; ?> text-<?php echo $mod['color']; ?>-600 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-700 text-center"><?php echo htmlspecialchars($mod['label']); ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>

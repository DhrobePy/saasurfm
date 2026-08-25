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

$pageTitle    = 'Home';
$currentUser  = getCurrentUser();
$custom_perms = getUserCustomPerms();

// ── Production-first landing ───────────────────────────────────────────────
// Users with production access land directly on the production board instead
// of this tile page. The redirect fires ONLY when the user would pass
// cr/credit_production.php's own access check — mirroring restrict_access()
// exactly — so a denied user can never be bounced back here in a loop.
// The error_flash guard is belt-and-braces: if we arrived via any denial,
// show the tiles instead of redirecting again.
if (empty($_SESSION['error_flash'])) {
    $prod_perms = $custom_perms['production'] ?? null;
    if ($prod_perms !== null) {
        // Privilege user: module must be enabled and the page allowed (or unrestricted)
        $can_prod = !empty($prod_perms['enabled'])
                 && (empty($prod_perms['allowed_pages'])
                     || in_array('credit_production', $prod_perms['allowed_pages']));
    } else {
        // No production perms row: role fallback, same roles the page itself allows
        $can_prod = in_array($role, ['production manager-srg', 'production manager-demra', 'Accounts']);
    }
    if ($can_prod) {
        header('Location: ' . url('cr/credit_production.php'));
        exit();
    }
}

// Build quick-link CARDS for every individual page this user can reach, grouped
// by module — not just one tile per module. Mirrors the exact visibility rules
// the top nav uses (navModule()/navPage() in templates/header.php), reimplemented
// inline here because those helpers aren't defined until header.php is included
// (which happens after this block runs). Since only non-admins reach this page
// (admins are redirected to admin/index.php above), the admin-branch of that
// logic is irrelevant here — this is the $is_admin=false path only.
$registry = getModuleRegistry();
// Same modules the top nav hides regardless of grants (folded into other modules).
$hidden_from_quick_links = ['sales', 'collector', 'dispatch', 'pos', 'admin'];
$module_groups = [];
foreach ($registry as $module_key => $module_def) {
    if (in_array($module_key, $hidden_from_quick_links, true)) continue;

    $mod_perms = $custom_perms[$module_key] ?? null;
    if ($mod_perms === null || empty($mod_perms['enabled'])) continue;

    $allowed_pages = $mod_perms['allowed_pages'] ?? [];
    $pages = [];
    foreach (($module_def['nav'] ?? []) as $item) {
        if (!empty($item['hidden']))     continue; // explicitly hidden from nav
        if (!empty($item['admin_only'])) continue; // this page only serves non-admins
        $page_key = $item['page_key'] ?? $item['file'];
        if (!empty($allowed_pages) && !in_array($page_key, $allowed_pages, true)) continue;
        $pages[] = [
            'label' => $item['label'],
            'icon'  => $item['icon'] ?? 'fa-circle',
            'url'   => url(($item['folder'] ?? $module_def['folder']) . '/' . $item['file'] . '.php'),
        ];
    }
    if (empty($pages)) continue;

    $module_groups[] = [
        'label' => $module_def['label'],
        'icon'  => $module_def['icon'],
        'color' => $module_def['color'] ?? 'blue',
        'pages' => $pages,
    ];
}

require_once __DIR__ . '/templates/header.php';
?>

<div class="max-w-5xl mx-auto px-4 py-10">

    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">
            Welcome, <?php echo htmlspecialchars($currentUser['display_name'] ?? 'User'); ?>
        </h1>
        <p class="text-gray-500 mt-1 text-sm">Quick links to everything you're allowed to use, grouped by module — or pick a module from the navigation bar above.</p>
    </div>

    <?php if (empty($module_groups)): ?>
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-yellow-800 text-sm flex gap-3">
        <i class="fas fa-exclamation-triangle mt-0.5 flex-shrink-0"></i>
        <div>
            <strong>No modules enabled.</strong> Your account has no module access configured yet.
            Please contact your administrator to set up your privileges.
        </div>
    </div>
    <?php else: ?>
    <div class="space-y-8">
        <?php foreach ($module_groups as $group): ?>
        <div>
            <div class="flex items-center gap-2 mb-3">
                <div class="w-7 h-7 rounded-lg bg-<?php echo $group['color']; ?>-100 flex items-center justify-center flex-shrink-0">
                    <i class="fas <?php echo $group['icon']; ?> text-<?php echo $group['color']; ?>-600 text-xs"></i>
                </div>
                <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wide"><?php echo htmlspecialchars($group['label']); ?></h2>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                <?php foreach ($group['pages'] as $page): ?>
                <a href="<?php echo $page['url']; ?>"
                   class="flex items-center gap-3 p-4 bg-white rounded-xl shadow-sm border border-gray-100
                          hover:shadow-md hover:border-<?php echo $group['color']; ?>-200 transition-all group">
                    <div class="w-9 h-9 rounded-lg bg-<?php echo $group['color']; ?>-50 flex items-center justify-center flex-shrink-0
                                group-hover:bg-<?php echo $group['color']; ?>-100 transition-colors">
                        <i class="fas <?php echo $page['icon']; ?> text-<?php echo $group['color']; ?>-600 text-sm"></i>
                    </div>
                    <span class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($page['label']); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
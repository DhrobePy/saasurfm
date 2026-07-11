<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? APP_NAME; ?></title>
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?php echo asset('css/style.css'); ?>">

    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="<?php echo asset('js/app.js'); ?>" defer></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: { 50:'#f0f9ff', 100:'#e0f2fe', 200:'#bae6fd', 300:'#7dd3fc', 400:'#38bdf8', 500:'#0ea5e9', 600:'#0284c7', 700:'#0369a1', 800:'#075985', 900:'#0c4a6e' }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans min-h-screen flex flex-col">

<?php if (isLoggedIn()): ?>
<?php
    $currentUser = getCurrentUser();
    $user_role   = $currentUser['role'] ?? '';

    $is_admin     = in_array($user_role, ['Superadmin', 'admin']);
    $custom_perms = $is_admin ? [] : getUserCustomPerms();
    $nav_registry = getModuleRegistry();

    // =========================================================
    // NAV HELPERS
    // =========================================================
    function navModule(string $module, array $cp, bool $ia): bool {
        if ($ia) return true;
        return isset($cp[$module]) && $cp[$module]['enabled'];
    }
    function navPage(string $module, string $page_key, array $cp, bool $ia): bool {
        if ($ia) return true;
        if (!isset($cp[$module]) || !$cp[$module]['enabled']) return false;
        $pages = $cp[$module]['allowed_pages'] ?? [];
        return empty($pages) || in_array($page_key, $pages);
    }
    function navPageAction(string $module, string $page_key, string $action, array $cp, bool $ia): bool {
        if ($ia) return true;
        if (!isset($cp[$module]) || !$cp[$module]['enabled']) return false;
        return !empty($cp[$module]['allowed_actions'][$page_key][$action]);
    }
    function navAction(string $module, string $action, array $cp, bool $ia): bool {
        if ($ia) return true;
        if (!isset($cp[$module]) || !$cp[$module]['enabled']) return false;
        $actions = $cp[$module]['allowed_actions'] ?? [];
        return !empty($actions[$action]);
    }

    // =========================================================
    // MODULES HIDDEN FROM NAV (temporarily disabled)
    // Un-comment a key below to re-enable it in the nav.
    // =========================================================
    $hidden_nav_modules = [
        'sales',       // Sales module — hidden for now
        'collector',   // Collector module — hidden for now
        'dispatch',    // Dispatch module — hidden for now
        'pos',         // Point of Sale — hidden for now
        'production',  // Production module — hidden for now
    ];

    // =========================================================
    // COLOR MAP  — module color → full Tailwind class strings
    // (complete class names so Tailwind CDN can detect them)
    // =========================================================
    $modColors = [
        'blue'    => ['nav_icon' => 'text-blue-500',    'item_icon' => 'text-blue-500',    'item_hover' => 'hover:bg-blue-50',    'header_bg' => 'bg-blue-50 border-b border-blue-100',    'label' => 'text-blue-700'],
        'green'   => ['nav_icon' => 'text-green-500',   'item_icon' => 'text-green-500',   'item_hover' => 'hover:bg-green-50',   'header_bg' => 'bg-green-50 border-b border-green-100',   'label' => 'text-green-700'],
        'purple'  => ['nav_icon' => 'text-purple-500',  'item_icon' => 'text-purple-500',  'item_hover' => 'hover:bg-purple-50',  'header_bg' => 'bg-purple-50 border-b border-purple-100',  'label' => 'text-purple-700'],
        'yellow'  => ['nav_icon' => 'text-yellow-500',  'item_icon' => 'text-yellow-500',  'item_hover' => 'hover:bg-yellow-50',  'header_bg' => 'bg-yellow-50 border-b border-yellow-100',  'label' => 'text-yellow-700'],
        'indigo'  => ['nav_icon' => 'text-indigo-500',  'item_icon' => 'text-indigo-500',  'item_hover' => 'hover:bg-indigo-50',  'header_bg' => 'bg-indigo-50 border-b border-indigo-100',  'label' => 'text-indigo-700'],
        'teal'    => ['nav_icon' => 'text-teal-500',    'item_icon' => 'text-teal-500',    'item_hover' => 'hover:bg-teal-50',    'header_bg' => 'bg-teal-50 border-b border-teal-100',    'label' => 'text-teal-700'],
        'orange'  => ['nav_icon' => 'text-orange-500',  'item_icon' => 'text-orange-500',  'item_hover' => 'hover:bg-orange-50',  'header_bg' => 'bg-orange-50 border-b border-orange-100',  'label' => 'text-orange-700'],
        'amber'   => ['nav_icon' => 'text-amber-500',   'item_icon' => 'text-amber-500',   'item_hover' => 'hover:bg-amber-50',   'header_bg' => 'bg-amber-50 border-b border-amber-100',   'label' => 'text-amber-700'],
        'red'     => ['nav_icon' => 'text-red-500',     'item_icon' => 'text-red-500',     'item_hover' => 'hover:bg-red-50',     'header_bg' => 'bg-red-50 border-b border-red-100',     'label' => 'text-red-700'],
        'emerald' => ['nav_icon' => 'text-emerald-500', 'item_icon' => 'text-emerald-500', 'item_hover' => 'hover:bg-emerald-50', 'header_bg' => 'bg-emerald-50 border-b border-emerald-100', 'label' => 'text-emerald-700'],
        'cyan'    => ['nav_icon' => 'text-cyan-500',    'item_icon' => 'text-cyan-500',    'item_hover' => 'hover:bg-cyan-50',    'header_bg' => 'bg-cyan-50 border-b border-cyan-100',    'label' => 'text-cyan-700'],
        'rose'    => ['nav_icon' => 'text-rose-500',    'item_icon' => 'text-rose-500',    'item_hover' => 'hover:bg-rose-50',    'header_bg' => 'bg-rose-50 border-b border-rose-100',    'label' => 'text-rose-700'],
        'violet'  => ['nav_icon' => 'text-violet-500',  'item_icon' => 'text-violet-500',  'item_hover' => 'hover:bg-violet-50',  'header_bg' => 'bg-violet-50 border-b border-violet-100',  'label' => 'text-violet-700'],
    ];

    // =========================================================
    // GROUP BREAKS — insert visual divider BEFORE these modules
    // Groups: [Operations] | [Finance] | [Admin]
    // =========================================================
    $groupBreakBefore = ['expenses', 'admin'];
?>

    <nav class="bg-white shadow-md border-b border-gray-200" x-data="{ mobileMenuOpen: false }">
        <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">

                <!-- ═══════════════════════════════════════════
                     LEFT: LOGO + DESKTOP NAV
                ════════════════════════════════════════════ -->
                <div class="flex min-w-0">

                    <!-- Logo -->
                    <div class="flex-shrink-0 flex items-center pr-4">
                        <a href="<?php echo url('index.php'); ?>" class="flex items-center gap-2">
                            <i class="fas fa-layer-group text-primary-600 text-2xl"></i>
                            <span class="font-bold text-xl text-gray-900 hidden lg:block"><?php echo APP_NAME; ?></span>
                        </a>
                    </div>

                    <!-- Desktop Nav -->
                    <div class="hidden md:flex md:items-center md:gap-0.5">

                        <!-- Home -->
                        <a href="<?php echo url('index.php'); ?>"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100 transition-colors">
                            <i class="fas fa-home text-gray-400 text-sm"></i>
                            <span>Home</span>
                        </a>

                        <?php foreach ($nav_registry as $module_key => $module_def):

                            // ── Skip hidden modules ─────────────────────────
                            if (in_array($module_key, $hidden_nav_modules)) continue;

                            // ── Privilege check ─────────────────────────────
                            if (!navModule($module_key, $custom_perms, $is_admin)) continue;
                            if ($module_key === 'admin' && !$is_admin) continue;

                            $nav_items = $module_def['nav'];
                            $folder    = $module_def['folder'];
                            $mod_label = $module_def['label'];
                            $mod_icon  = $module_def['icon'];
                            $mod_color = $module_def['color'] ?? 'blue';
                            $c         = $modColors[$mod_color] ?? $modColors['blue'];

                            // Filter pages the user can see
                            $visible_items = [];
                            foreach ($nav_items as $item) {
                                if (navPage($module_key, $item['page_key'], $custom_perms, $is_admin)) {
                                    $visible_items[] = $item;
                                }
                            }
                            if (empty($visible_items)) continue;
                        ?>

                        <?php if (in_array($module_key, $groupBreakBefore)): ?>
                            <!-- Group divider -->
                            <div class="w-px h-5 bg-gray-300 mx-1 flex-shrink-0 self-center"></div>
                        <?php endif; ?>

                        <?php if (count($visible_items) === 1): ?>

                            <!-- Single-page module: direct link -->
                            <a href="<?php echo url($folder . '/' . $visible_items[0]['file'] . '.php'); ?>"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium text-gray-600 hover:text-gray-900 <?php echo $c['item_hover']; ?> transition-colors">
                                <i class="fas <?php echo $mod_icon; ?> <?php echo $c['nav_icon']; ?> text-sm"></i>
                                <span><?php echo htmlspecialchars($mod_label); ?></span>
                            </a>

                        <?php else: ?>

                            <!-- Multi-page module: dropdown -->
                            <div class="relative flex items-center" x-data="{ open: false }">
                                <button @click="open = !open"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium text-gray-600 hover:text-gray-900 <?php echo $c['item_hover']; ?> transition-colors">
                                    <i class="fas <?php echo $mod_icon; ?> <?php echo $c['nav_icon']; ?> text-sm"></i>
                                    <span><?php echo htmlspecialchars($mod_label); ?></span>
                                    <i class="fas fa-chevron-down text-[9px] opacity-40 transition-transform duration-200"
                                       :class="open ? 'rotate-180' : ''"></i>
                                </button>

                                <div x-show="open" @click.away="open = false"
                                     x-transition:enter="transition ease-out duration-100"
                                     x-transition:enter-start="opacity-0 scale-95"
                                     x-transition:enter-end="opacity-100 scale-100"
                                     x-transition:leave="transition ease-in duration-75"
                                     x-transition:leave-start="opacity-100 scale-100"
                                     x-transition:leave-end="opacity-0 scale-95"
                                     class="absolute left-0 top-full mt-1 w-56 rounded-xl shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 overflow-hidden">

                                    <!-- Dropdown header -->
                                    <div class="px-3 py-2 <?php echo $c['header_bg']; ?>">
                                        <span class="flex items-center gap-1.5 text-xs font-bold <?php echo $c['label']; ?> uppercase tracking-wider">
                                            <i class="fas <?php echo $mod_icon; ?>"></i>
                                            <?php echo htmlspecialchars($mod_label); ?>
                                        </span>
                                    </div>

                                    <!-- Dropdown items -->
                                    <div class="py-1">
                                        <?php foreach ($visible_items as $item): ?>
                                        <a href="<?php echo url($folder . '/' . $item['file'] . '.php'); ?>"
                                           class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-700 <?php echo $c['item_hover']; ?> transition-colors">
                                            <i class="fas <?php echo $item['icon']; ?> <?php echo $c['item_icon']; ?> w-4 text-center text-sm flex-shrink-0"></i>
                                            <?php echo htmlspecialchars($item['label']); ?>
                                        </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                        <?php endif; ?>
                        <?php endforeach; ?>

                    </div>
                </div><!-- end left flex -->

                <!-- ═══════════════════════════════════════════
                     RIGHT: USER PROFILE DROPDOWN
                ════════════════════════════════════════════ -->
                <div class="hidden md:flex md:items-center">
                    <div class="ml-3 relative" x-data="{ open: false }">
                        <button @click="open = !open"
                                class="flex items-center gap-2 text-sm rounded-full focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <div class="h-8 w-8 rounded-full bg-primary-500 flex items-center justify-center shadow-sm">
                                <span class="text-white font-semibold text-sm">
                                    <?php echo strtoupper(substr($currentUser['display_name'] ?? 'U', 0, 1)); ?>
                                </span>
                            </div>
                        </button>
                        <div x-show="open" @click.away="open = false"
                             x-transition:enter="transition ease-out duration-100"
                             x-transition:enter-start="opacity-0 scale-95"
                             x-transition:enter-end="opacity-100 scale-100"
                             class="absolute right-0 mt-2 w-52 rounded-xl shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 overflow-hidden">
                            <div class="py-1">
                                <div class="px-4 py-3 bg-gray-50 border-b border-gray-100">
                                    <div class="font-semibold text-sm text-gray-900"><?php echo htmlspecialchars($currentUser['display_name'] ?? 'User'); ?></div>
                                    <div class="text-xs text-gray-500 mt-0.5"><?php echo htmlspecialchars($user_role); ?></div>
                                </div>
                                <?php if ($is_admin): ?>
                                <a href="<?php echo url('admin/settings.php'); ?>"
                                   class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                                    <i class="fas fa-cog text-gray-400 w-4 text-center"></i>Settings
                                </a>
                                <?php endif; ?>
                                <a href="<?php echo url('auth/logout.php'); ?>"
                                   class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors">
                                    <i class="fas fa-sign-out-alt text-red-400 w-4 text-center"></i>Sign out
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════
                     MOBILE: HAMBURGER BUTTON
                ════════════════════════════════════════════ -->
                <div class="flex items-center md:hidden">
                    <button @click="mobileMenuOpen = !mobileMenuOpen"
                            class="p-2 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                        <i class="fas fa-bars text-lg"  x-show="!mobileMenuOpen"></i>
                        <i class="fas fa-times text-lg" x-show="mobileMenuOpen" x-cloak></i>
                    </button>
                </div>

            </div><!-- end flex justify-between -->

            <!-- ═══════════════════════════════════════════════
                 MOBILE MENU
            ════════════════════════════════════════════════ -->
            <div x-show="mobileMenuOpen" x-cloak
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0 -translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 class="md:hidden border-t border-gray-200 pb-4">

                <!-- Home -->
                <div class="pt-2 px-2">
                    <a href="<?php echo url('index.php'); ?>"
                       class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        <i class="fas fa-home text-gray-400 w-5 text-center"></i>
                        Dashboard
                    </a>
                </div>

                <!-- Dynamic module sections -->
                <?php foreach ($nav_registry as $module_key => $module_def):

                    // ── Skip hidden modules ─────────────────────────────
                    if (in_array($module_key, $hidden_nav_modules)) continue;

                    // ── Privilege check ─────────────────────────────────
                    if (!navModule($module_key, $custom_perms, $is_admin)) continue;
                    if ($module_key === 'admin' && !$is_admin) continue;

                    $nav_items = $module_def['nav'];
                    $folder    = $module_def['folder'];
                    $mod_label = $module_def['label'];
                    $mod_icon  = $module_def['icon'];
                    $mod_color = $module_def['color'] ?? 'blue';
                    $c         = $modColors[$mod_color] ?? $modColors['blue'];

                    $visible_items = [];
                    foreach ($nav_items as $item) {
                        if (navPage($module_key, $item['page_key'], $custom_perms, $is_admin)) {
                            $visible_items[] = $item;
                        }
                    }
                    if (empty($visible_items)) continue;
                ?>

                <!-- Group divider (before Finance and Admin groups) -->
                <div class="mx-3 <?php echo in_array($module_key, $groupBreakBefore) ? 'border-t border-gray-200 mt-3 pt-1' : 'mt-1'; ?>">

                    <!-- Module label -->
                    <div class="flex items-center gap-2 px-3 py-1.5">
                        <i class="fas <?php echo $mod_icon; ?> <?php echo $c['label']; ?> text-xs w-4 text-center"></i>
                        <span class="text-xs font-bold <?php echo $c['label']; ?> uppercase tracking-wider">
                            <?php echo htmlspecialchars($mod_label); ?>
                        </span>
                    </div>

                    <!-- Module pages -->
                    <?php foreach ($visible_items as $item): ?>
                    <a href="<?php echo url($folder . '/' . $item['file'] . '.php'); ?>"
                       class="flex items-center gap-3 pl-8 pr-4 py-2 text-sm text-gray-600 <?php echo $c['item_hover']; ?> rounded-lg transition-colors">
                        <i class="fas <?php echo $item['icon']; ?> <?php echo $c['item_icon']; ?> w-4 text-center text-sm flex-shrink-0"></i>
                        <?php echo htmlspecialchars($item['label']); ?>
                    </a>
                    <?php endforeach; ?>

                </div>
                <?php endforeach; ?>

                <!-- ── Mobile user section ───────────────────────────── -->
                <div class="mx-3 mt-3 pt-3 border-t border-gray-200">
                    <div class="flex items-center gap-3 px-3 py-2">
                        <div class="h-9 w-9 rounded-full bg-primary-500 flex items-center justify-center shadow-sm flex-shrink-0">
                            <span class="text-white font-semibold text-sm">
                                <?php echo strtoupper(substr($currentUser['display_name'] ?? 'U', 0, 1)); ?>
                            </span>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($currentUser['display_name'] ?? 'User'); ?></div>
                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($user_role); ?></div>
                        </div>
                    </div>
                    <div class="mt-1 space-y-0.5">
                        <?php if ($is_admin): ?>
                        <a href="<?php echo url('admin/settings.php'); ?>"
                           class="flex items-center gap-3 px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">
                            <i class="fas fa-cog text-gray-400 w-4 text-center"></i>Settings
                        </a>
                        <?php endif; ?>
                        <a href="<?php echo url('auth/logout.php'); ?>"
                           class="flex items-center gap-3 px-3 py-2 text-sm text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                            <i class="fas fa-sign-out-alt text-red-400 w-4 text-center"></i>Sign out
                        </a>
                    </div>
                </div>

            </div><!-- end mobile menu -->

        </div><!-- end max-w container -->
    </nav>

<?php endif; ?>

<!-- Main Content -->
<main class="py-6 lg:py-8 flex-grow">
    <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8">

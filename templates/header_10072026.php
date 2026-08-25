<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . ' — ' . APP_NAME : APP_NAME; ?></title>
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">

    <!-- ── PWA ─────────────────────────────────────────────────────────── -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0284c7">
    <!-- iOS / Safari PWA support -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="UFM ERP">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
    <!-- MS Tiles -->
    <meta name="msapplication-TileImage" content="/assets/icons/icon-144.png">
    <meta name="msapplication-TileColor" content="#0284c7">

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?php echo asset('css/style.css'); ?>">

    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="<?php echo asset('js/app.js'); ?>" defer></script>

    <!-- ── Service Worker Registration ────────────────────────────────── -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js', { scope: '/' })
                    .then(reg => {
                        // Silent registration — no console noise in production
                        // Notify user of new version available
                        reg.addEventListener('updatefound', () => {
                            const newWorker = reg.installing;
                            newWorker.addEventListener('statechange', () => {
                                if (newWorker.state === 'installed' &&
                                    navigator.serviceWorker.controller) {
                                    // Show a subtle "Update available" toast
                                    const toast = document.createElement('div');
                                    toast.id = 'sw-update-toast';
                                    toast.innerHTML = `
                                        <div class="fixed bottom-4 left-1/2 -translate-x-1/2 z-[9999]
                                                    flex items-center gap-3 bg-gray-900 text-white
                                                    text-sm font-medium px-5 py-3 rounded-xl shadow-2xl">
                                            <i class="fas fa-rotate-right text-sky-400"></i>
                                            New version available
                                            <button onclick="window.location.reload()"
                                                    class="ml-1 bg-sky-500 hover:bg-sky-400 text-white
                                                           px-3 py-1 rounded-lg text-xs font-semibold
                                                           transition-colors">
                                                Update
                                            </button>
                                            <button onclick="this.closest('#sw-update-toast').remove()"
                                                    class="text-gray-400 hover:text-white ml-1">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>`;
                                    document.body.appendChild(toast);
                                }
                            });
                        });
                    })
                    .catch(() => {
                        // SW registration failed — app still works, just no offline support
                    });
            });
        }

        // ── PWA Install Prompt ──────────────────────────────────────────────
        let _deferredPrompt = null;
        window.addEventListener('beforeinstallprompt', e => {
            e.preventDefault();
            _deferredPrompt = e;
            // Show install button in nav
            const btn = document.getElementById('pwa-install-btn');
            if (btn) btn.classList.remove('hidden');
        });
        window.addEventListener('appinstalled', () => {
            _deferredPrompt = null;
            const btn = document.getElementById('pwa-install-btn');
            if (btn) btn.classList.add('hidden');
        });
        function triggerPWAInstall() {
            if (!_deferredPrompt) return;
            _deferredPrompt.prompt();
            _deferredPrompt.userChoice.then(() => { _deferredPrompt = null; });
        }
    </script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50:  '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-100 font-sans min-h-screen flex flex-col">

<?php if (isLoggedIn()): ?>
<?php
    $currentUser = getCurrentUser();
    $user_role   = $currentUser['role'] ?? '';

    $is_admin     = in_array($user_role, ['Superadmin', 'admin']);
    $custom_perms = $is_admin ? [] : getUserCustomPerms();
    $nav_registry = getModuleRegistry();

    // ─── Detect current page for active-state highlighting ─────────────────
    // e.g.  /product/pricing_engine.php  →  ['product', 'pricing_engine']
    $uri_path       = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $uri_segments   = array_values(array_filter(explode('/', $uri_path)));
    $current_folder = count($uri_segments) >= 2 ? $uri_segments[count($uri_segments) - 2] : '';
    $current_file   = count($uri_segments) >= 1
                        ? pathinfo($uri_segments[count($uri_segments) - 1], PATHINFO_FILENAME)
                        : '';

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

    // =========================================================
    // MODULES HIDDEN FROM NAV
    // Un-comment a key to re-enable it in the navigation.
    // =========================================================
    $hidden_nav_modules = [
        'sales',      // Sales module — hidden for now
        'collector',  // Collector module — hidden for now
        'dispatch',   // Dispatch module — hidden for now
        'pos',        // Point of Sale — hidden for now
    ];

    // =========================================================
    // COLOR MAP — full Tailwind class strings (CDN safe)
    // =========================================================
    $modColors = [
        'blue'    => ['nav_icon' => 'text-blue-500',    'item_icon' => 'text-blue-500',    'item_hover' => 'hover:bg-blue-50',    'header_bg' => 'bg-blue-50 border-b border-blue-100',    'label' => 'text-blue-700',    'active_bg' => 'bg-blue-50',    'active_text' => 'text-blue-700'],
        'green'   => ['nav_icon' => 'text-green-500',   'item_icon' => 'text-green-500',   'item_hover' => 'hover:bg-green-50',   'header_bg' => 'bg-green-50 border-b border-green-100',   'label' => 'text-green-700',   'active_bg' => 'bg-green-50',   'active_text' => 'text-green-700'],
        'purple'  => ['nav_icon' => 'text-purple-500',  'item_icon' => 'text-purple-500',  'item_hover' => 'hover:bg-purple-50',  'header_bg' => 'bg-purple-50 border-b border-purple-100',  'label' => 'text-purple-700',  'active_bg' => 'bg-purple-50',  'active_text' => 'text-purple-700'],
        'yellow'  => ['nav_icon' => 'text-yellow-500',  'item_icon' => 'text-yellow-500',  'item_hover' => 'hover:bg-yellow-50',  'header_bg' => 'bg-yellow-50 border-b border-yellow-100',  'label' => 'text-yellow-700',  'active_bg' => 'bg-yellow-50',  'active_text' => 'text-yellow-700'],
        'indigo'  => ['nav_icon' => 'text-indigo-500',  'item_icon' => 'text-indigo-500',  'item_hover' => 'hover:bg-indigo-50',  'header_bg' => 'bg-indigo-50 border-b border-indigo-100',  'label' => 'text-indigo-700',  'active_bg' => 'bg-indigo-50',  'active_text' => 'text-indigo-700'],
        'teal'    => ['nav_icon' => 'text-teal-500',    'item_icon' => 'text-teal-500',    'item_hover' => 'hover:bg-teal-50',    'header_bg' => 'bg-teal-50 border-b border-teal-100',    'label' => 'text-teal-700',    'active_bg' => 'bg-teal-50',    'active_text' => 'text-teal-700'],
        'orange'  => ['nav_icon' => 'text-orange-500',  'item_icon' => 'text-orange-500',  'item_hover' => 'hover:bg-orange-50',  'header_bg' => 'bg-orange-50 border-b border-orange-100',  'label' => 'text-orange-700',  'active_bg' => 'bg-orange-50',  'active_text' => 'text-orange-700'],
        'amber'   => ['nav_icon' => 'text-amber-500',   'item_icon' => 'text-amber-500',   'item_hover' => 'hover:bg-amber-50',   'header_bg' => 'bg-amber-50 border-b border-amber-100',   'label' => 'text-amber-700',   'active_bg' => 'bg-amber-50',   'active_text' => 'text-amber-700'],
        'red'     => ['nav_icon' => 'text-red-500',     'item_icon' => 'text-red-500',     'item_hover' => 'hover:bg-red-50',     'header_bg' => 'bg-red-50 border-b border-red-100',     'label' => 'text-red-700',     'active_bg' => 'bg-red-50',     'active_text' => 'text-red-700'],
        'emerald' => ['nav_icon' => 'text-emerald-500', 'item_icon' => 'text-emerald-500', 'item_hover' => 'hover:bg-emerald-50', 'header_bg' => 'bg-emerald-50 border-b border-emerald-100', 'label' => 'text-emerald-700', 'active_bg' => 'bg-emerald-50', 'active_text' => 'text-emerald-700'],
        'cyan'    => ['nav_icon' => 'text-cyan-500',    'item_icon' => 'text-cyan-500',    'item_hover' => 'hover:bg-cyan-50',    'header_bg' => 'bg-cyan-50 border-b border-cyan-100',    'label' => 'text-cyan-700',    'active_bg' => 'bg-cyan-50',    'active_text' => 'text-cyan-700'],
        'rose'    => ['nav_icon' => 'text-rose-500',    'item_icon' => 'text-rose-500',    'item_hover' => 'hover:bg-rose-50',    'header_bg' => 'bg-rose-50 border-b border-rose-100',    'label' => 'text-rose-700',    'active_bg' => 'bg-rose-50',    'active_text' => 'text-rose-700'],
        'violet'  => ['nav_icon' => 'text-violet-500',  'item_icon' => 'text-violet-500',  'item_hover' => 'hover:bg-violet-50',  'header_bg' => 'bg-violet-50 border-b border-violet-100',  'label' => 'text-violet-700',  'active_bg' => 'bg-violet-50',  'active_text' => 'text-violet-700'],
    ];

    // =========================================================
    // GROUP BREAKS — insert visual divider BEFORE these modules
    // Groups: [Operations] | [Finance] | [Admin]
    // =========================================================
    $groupBreakBefore = ['expenses', 'admin'];

    // ─── Flash messages from session ───────────────────────────────────────
    $header_success = $_SESSION['success_flash'] ?? null;
    $header_error   = $_SESSION['error_flash']   ?? null;
    unset($_SESSION['success_flash'], $_SESSION['error_flash']);
?>

    <!-- ═══════════════════════════════════════════════════════════
         TOP NAVIGATION BAR
    ════════════════════════════════════════════════════════════ -->
    <nav class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-50"
         x-data="{ mobileMenuOpen: false }">
        <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">

                <!-- ═══════════════════════════════════════
                     LEFT: LOGO + DESKTOP NAV LINKS
                ════════════════════════════════════════ -->
                <div class="flex min-w-0">

                    <!-- Logo / Brand -->
                    <div class="flex-shrink-0 flex items-center pr-5 border-r border-gray-100 mr-3">
                        <a href="<?php echo url('index.php'); ?>"
                           class="flex items-center gap-2.5 group">
                            <div class="w-8 h-8 rounded-lg bg-primary-600 flex items-center justify-center shadow-sm
                                        group-hover:bg-primary-700 transition-colors">
                                <i class="fas fa-layer-group text-white text-sm"></i>
                            </div>
                            <span class="font-bold text-lg text-gray-900 hidden lg:block tracking-tight">
                                <?php echo APP_NAME; ?>
                            </span>
                        </a>
                    </div>

                    <!-- Desktop Nav Links -->
                    <div class="hidden md:flex md:items-center md:gap-0.5">

                        <!-- Home -->
                        <?php $is_home = ($current_file === 'index' && $current_folder === ''); ?>
                        <a href="<?php echo url('index.php'); ?>"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium transition-colors
                                  <?php echo $is_home
                                      ? 'bg-gray-100 text-gray-900'
                                      : 'text-gray-500 hover:text-gray-800 hover:bg-gray-100'; ?>">
                            <i class="fas fa-home text-sm <?php echo $is_home ? 'text-gray-700' : 'text-gray-400'; ?>"></i>
                            <span>Home</span>
                        </a>

                        <?php foreach ($nav_registry as $module_key => $module_def):

                            // Skip hidden modules
                            if (in_array($module_key, $hidden_nav_modules)) continue;

                            // Privilege check — admin module is admin-only
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
                                if (!empty($item['hidden'])) continue;       // explicitly hidden
                                if (!empty($item['admin_only']) && !$is_admin) continue;
                                if (navPage($module_key, $item['page_key'], $custom_perms, $is_admin)) {
                                    $visible_items[] = $item;
                                }
                            }
                            if (empty($visible_items)) continue;

                            // Is any page in this module currently active?
                            // If active_files is set, only highlight when on one of those files.
                            $active_files   = $module_def['active_files'] ?? null;
                            $module_active  = ($current_folder === $folder)
                                && ($active_files === null || in_array($current_file, $active_files));
                        ?>

                        <?php if (in_array($module_key, $groupBreakBefore)): ?>
                            <div class="w-px h-5 bg-gray-200 mx-1.5 flex-shrink-0 self-center"></div>
                        <?php endif; ?>

                        <?php if (count($visible_items) === 1): ?>

                            <!-- Single-page module: direct link -->
                            <?php $pg_active = $module_active && $current_file === $visible_items[0]['file']; ?>
                            <a href="<?php echo url(($visible_items[0]['folder'] ?? $folder) . '/' . $visible_items[0]['file'] . '.php'); ?>"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium transition-colors
                                      <?php echo $pg_active
                                          ? $c['active_bg'] . ' ' . $c['active_text'] . ' font-semibold'
                                          : 'text-gray-600 hover:text-gray-900 ' . $c['item_hover']; ?>">
                                <i class="fas <?php echo $mod_icon; ?> text-sm
                                           <?php echo $pg_active ? $c['nav_icon'] : $c['nav_icon'] . ' opacity-70'; ?>"></i>
                                <span><?php echo htmlspecialchars($mod_label); ?></span>
                            </a>

                        <?php else: ?>

                            <!-- Multi-page module: dropdown -->
                            <div class="relative flex items-center flex-shrink-0"
                                 x-data="{ open: false }">
                                <button @click="open = !open"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium transition-colors
                                               <?php echo $module_active
                                                   ? $c['active_bg'] . ' ' . $c['active_text'] . ' font-semibold'
                                                   : 'text-gray-600 hover:text-gray-900 ' . $c['item_hover']; ?>">
                                    <i class="fas <?php echo $mod_icon; ?> text-sm <?php echo $c['nav_icon']; ?>
                                               <?php echo $module_active ? '' : 'opacity-70'; ?>"></i>
                                    <span><?php echo htmlspecialchars($mod_label); ?></span>
                                    <i class="fas fa-chevron-down text-[9px] opacity-40 transition-transform duration-200 ml-0.5"
                                       :class="open ? 'rotate-180' : ''"></i>
                                </button>

                                <div x-show="open"
                                     @click.away="open = false"
                                     x-transition:enter="transition ease-out duration-100"
                                     x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                                     x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                     x-transition:leave="transition ease-in duration-75"
                                     x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                                     x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
                                     class="absolute left-0 top-full mt-1 w-56 rounded-xl shadow-xl bg-white
                                            ring-1 ring-black ring-opacity-5 z-[60] overflow-hidden">

                                    <!-- Dropdown header -->
                                    <div class="px-3 py-2 <?php echo $c['header_bg']; ?>">
                                        <span class="flex items-center gap-1.5 text-xs font-bold <?php echo $c['label']; ?> uppercase tracking-wider">
                                            <i class="fas <?php echo $mod_icon; ?>"></i>
                                            <?php echo htmlspecialchars($mod_label); ?>
                                        </span>
                                    </div>

                                    <!-- Dropdown items -->
                                    <div class="py-1">
                                        <?php foreach ($visible_items as $item):
                                            $item_active = $module_active && $current_file === $item['file'];
                                        ?>
                                        <a href="<?php echo url(($item['folder'] ?? $folder) . '/' . $item['file'] . '.php'); ?>"
                                           class="flex items-center gap-2.5 px-4 py-2.5 text-sm transition-colors
                                                  <?php echo $item_active
                                                      ? $c['active_bg'] . ' ' . $c['active_text'] . ' font-semibold'
                                                      : 'text-gray-700 ' . $c['item_hover']; ?>">
                                            <i class="fas <?php echo $item['icon']; ?> <?php echo $c['item_icon']; ?>
                                                       w-4 text-center text-sm flex-shrink-0
                                                       <?php echo $item_active ? '' : 'opacity-70'; ?>"></i>
                                            <span class="flex-1"><?php echo htmlspecialchars($item['label']); ?></span>
                                            <?php if (!empty($item['admin_only'])): ?>
                                            <span class="ml-auto text-[10px] font-semibold bg-red-100 text-red-600
                                                         px-1.5 py-0.5 rounded-full leading-none">
                                                Admin
                                            </span>
                                            <?php endif; ?>
                                        </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                        <?php endif; ?>
                        <?php endforeach; ?>

                    </div>
                </div><!-- end left flex -->

                <!-- ═══════════════════════════════════════
                     RIGHT: USER BADGE + DROPDOWN
                ════════════════════════════════════════ -->
                <div class="hidden md:flex md:items-center gap-3">

                    <!-- PWA Install button (hidden until browser fires beforeinstallprompt) -->
                    <button id="pwa-install-btn"
                            onclick="triggerPWAInstall()"
                            title="Install UFM ERP as an app"
                            class="hidden items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold
                                   bg-sky-50 hover:bg-sky-100 text-sky-700 border border-sky-200
                                   transition-colors focus:outline-none focus:ring-2 focus:ring-sky-400">
                        <i class="fas fa-download text-[10px]"></i>
                        Install App
                    </button>

                    <!-- Admin badge (only for Superadmin / admin) -->
                    <?php if ($is_admin): ?>
                    <span class="hidden lg:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full
                                 text-xs font-semibold bg-primary-50 text-primary-700 border border-primary-200
                                 ring-1 ring-primary-100">
                        <i class="fas fa-shield-alt text-[10px]"></i>
                        <?php echo htmlspecialchars($user_role); ?>
                    </span>
                    <?php endif; ?>

                    <!-- User avatar dropdown -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open"
                                class="flex items-center gap-2 rounded-full focus:outline-none
                                       focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 group">
                            <div class="h-9 w-9 rounded-full bg-gradient-to-br from-primary-500 to-primary-700
                                        flex items-center justify-center shadow-sm
                                        group-hover:shadow-md transition-shadow">
                                <span class="text-white font-bold text-sm select-none">
                                    <?php echo strtoupper(substr($currentUser['display_name'] ?? 'U', 0, 1)); ?>
                                </span>
                            </div>
                            <i class="fas fa-chevron-down text-[9px] text-gray-400 hidden lg:block
                                      transition-transform duration-200"
                               :class="open ? 'rotate-180' : ''"></i>
                        </button>

                        <div x-show="open"
                             @click.away="open = false"
                             x-transition:enter="transition ease-out duration-100"
                             x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-75"
                             x-transition:leave-start="opacity-100 scale-100"
                             x-transition:leave-end="opacity-0 scale-95"
                             class="absolute right-0 mt-2 w-56 rounded-xl shadow-xl bg-white
                                    ring-1 ring-black ring-opacity-5 z-[60] overflow-hidden">
                            <div class="py-1">
                                <!-- Profile header -->
                                <div class="px-4 py-3 bg-gray-50 border-b border-gray-100">
                                    <div class="font-semibold text-sm text-gray-900 truncate">
                                        <?php echo htmlspecialchars($currentUser['display_name'] ?? 'User'); ?>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-0.5 flex items-center gap-1.5">
                                        <?php if ($is_admin): ?>
                                        <i class="fas fa-shield-alt text-primary-500 text-[10px]"></i>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($user_role); ?>
                                    </div>
                                </div>
                                <?php if ($is_admin): ?>
                                <a href="<?php echo url('admin/settings.php'); ?>"
                                   class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-700
                                          hover:bg-gray-50 transition-colors">
                                    <i class="fas fa-cog text-gray-400 w-4 text-center"></i>
                                    Settings
                                </a>
                                <?php endif; ?>
                                <a href="<?php echo url('auth/logout.php'); ?>"
                                   class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-red-600
                                          hover:bg-red-50 transition-colors">
                                    <i class="fas fa-sign-out-alt text-red-400 w-4 text-center"></i>
                                    Sign out
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════
                     MOBILE: HAMBURGER
                ════════════════════════════════════════ -->
                <div class="flex items-center md:hidden">
                    <button @click="mobileMenuOpen = !mobileMenuOpen"
                            class="p-2 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                        <i class="fas fa-bars text-lg"  x-show="!mobileMenuOpen"></i>
                        <i class="fas fa-times text-lg" x-show="mobileMenuOpen" x-cloak></i>
                    </button>
                </div>

            </div><!-- end flex justify-between h-16 -->

            <!-- ═══════════════════════════════════════════════
                 MOBILE SLIDE-DOWN MENU
            ════════════════════════════════════════════════ -->
            <div x-show="mobileMenuOpen" x-cloak
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0 -translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 -translate-y-2"
                 class="md:hidden border-t border-gray-200 pb-4 max-h-[80vh] overflow-y-auto">

                <!-- Home -->
                <div class="pt-2 px-2">
                    <a href="<?php echo url('index.php'); ?>"
                       class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-lg transition-colors
                              <?php echo $is_home
                                  ? 'bg-gray-100 text-gray-900'
                                  : 'text-gray-700 hover:bg-gray-100'; ?>">
                        <i class="fas fa-home text-gray-400 w-5 text-center"></i>
                        Dashboard
                    </a>
                </div>

                <!-- Dynamic module sections -->
                <?php foreach ($nav_registry as $module_key => $module_def):

                    if (in_array($module_key, $hidden_nav_modules)) continue;
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
                        if (!empty($item['hidden'])) continue;
                        if (!empty($item['admin_only']) && !$is_admin) continue;
                        if (navPage($module_key, $item['page_key'], $custom_perms, $is_admin)) {
                            $visible_items[] = $item;
                        }
                    }
                    if (empty($visible_items)) continue;

                    $active_files  = $module_def['active_files'] ?? null;
                    $module_active = ($current_folder === $folder)
                        && ($active_files === null || in_array($current_file, $active_files));
                ?>

                <div class="mx-3 <?php echo in_array($module_key, $groupBreakBefore)
                                    ? 'border-t border-gray-200 mt-3 pt-2'
                                    : 'mt-1'; ?>">

                    <!-- Module label -->
                    <div class="flex items-center gap-2 px-3 py-1.5 mt-1">
                        <i class="fas <?php echo $mod_icon; ?> <?php echo $c['label']; ?> text-xs w-4 text-center"></i>
                        <span class="text-xs font-bold <?php echo $c['label']; ?> uppercase tracking-wider">
                            <?php echo htmlspecialchars($mod_label); ?>
                        </span>
                    </div>

                    <!-- Module pages -->
                    <?php foreach ($visible_items as $item):
                        $item_active = $module_active && $current_file === $item['file'];
                    ?>
                    <a href="<?php echo url(($item['folder'] ?? $folder) . '/' . $item['file'] . '.php'); ?>"
                       class="flex items-center gap-3 pl-8 pr-4 py-2 text-sm rounded-lg transition-colors
                              <?php echo $item_active
                                  ? $c['active_bg'] . ' ' . $c['active_text'] . ' font-semibold'
                                  : 'text-gray-600 ' . $c['item_hover']; ?>">
                        <i class="fas <?php echo $item['icon']; ?> <?php echo $c['item_icon']; ?>
                                   w-4 text-center text-sm flex-shrink-0
                                   <?php echo $item_active ? '' : 'opacity-70'; ?>"></i>
                        <span class="flex-1"><?php echo htmlspecialchars($item['label']); ?></span>
                        <?php if (!empty($item['admin_only'])): ?>
                        <span class="text-[10px] font-semibold bg-red-100 text-red-600
                                     px-1.5 py-0.5 rounded-full leading-none">
                            Admin
                        </span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>

                </div>
                <?php endforeach; ?>

                <!-- Mobile user section -->
                <div class="mx-3 mt-3 pt-3 border-t border-gray-200">
                    <div class="flex items-center gap-3 px-3 py-2">
                        <div class="h-10 w-10 rounded-full bg-gradient-to-br from-primary-500 to-primary-700
                                    flex items-center justify-center shadow-sm flex-shrink-0">
                            <span class="text-white font-bold text-sm">
                                <?php echo strtoupper(substr($currentUser['display_name'] ?? 'U', 0, 1)); ?>
                            </span>
                        </div>
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-gray-800 truncate">
                                <?php echo htmlspecialchars($currentUser['display_name'] ?? 'User'); ?>
                            </div>
                            <div class="text-xs text-gray-500 flex items-center gap-1">
                                <?php if ($is_admin): ?>
                                <i class="fas fa-shield-alt text-primary-500 text-[10px]"></i>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($user_role); ?>
                            </div>
                        </div>
                    </div>
                    <div class="mt-1 space-y-0.5">
                        <?php if ($is_admin): ?>
                        <a href="<?php echo url('admin/settings.php'); ?>"
                           class="flex items-center gap-3 px-3 py-2 text-sm text-gray-600
                                  hover:bg-gray-100 rounded-lg transition-colors">
                            <i class="fas fa-cog text-gray-400 w-4 text-center"></i>Settings
                        </a>
                        <?php endif; ?>
                        <a href="<?php echo url('auth/logout.php'); ?>"
                           class="flex items-center gap-3 px-3 py-2 text-sm text-red-600
                                  hover:bg-red-50 rounded-lg transition-colors">
                            <i class="fas fa-sign-out-alt text-red-400 w-4 text-center"></i>Sign out
                        </a>
                    </div>
                </div>

            </div><!-- end mobile menu -->

        </div><!-- end max-w container -->
    </nav>

    <!-- ═══════════════════════════════════════════════════════════
         SESSION FLASH MESSAGES (success / error from redirects)
    ════════════════════════════════════════════════════════════ -->
    <?php if ($header_success || $header_error): ?>
    <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8 pt-4" id="headerFlash">
        <?php if ($header_success): ?>
        <div class="flex items-start gap-3 px-4 py-3 rounded-lg bg-green-50 border border-green-300
                    text-green-800 text-sm shadow-sm">
            <i class="fas fa-check-circle text-green-500 mt-0.5 flex-shrink-0"></i>
            <span class="flex-1"><?php echo htmlspecialchars($header_success); ?></span>
            <button onclick="this.closest('#headerFlash').remove()"
                    class="flex-shrink-0 text-green-400 hover:text-green-600 ml-2 leading-none">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>
        <?php if ($header_error): ?>
        <div class="flex items-start gap-3 px-4 py-3 rounded-lg bg-red-50 border border-red-300
                    text-red-800 text-sm shadow-sm <?php echo $header_success ? 'mt-2' : ''; ?>">
            <i class="fas fa-exclamation-circle text-red-500 mt-0.5 flex-shrink-0"></i>
            <span class="flex-1"><?php echo htmlspecialchars($header_error); ?></span>
            <button onclick="this.closest('#headerFlash').remove()"
                    class="flex-shrink-0 text-red-400 hover:text-red-600 ml-2 leading-none">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>
    </div>
    <script>
        // Auto-dismiss flash messages after 6 seconds
        setTimeout(function () {
            const el = document.getElementById('headerFlash');
            if (el) {
                el.style.transition = 'opacity 0.5s';
                el.style.opacity    = '0';
                setTimeout(() => el.remove(), 500);
            }
        }, 6000);
    </script>
    <?php endif; ?>

<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     MAIN CONTENT WRAPPER
════════════════════════════════════════════════════════════ -->
<main class="py-6 lg:py-8 flex-grow">
    <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8">
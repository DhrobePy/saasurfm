<?php
/**
 * ============================================================================
 * CORE HELPER FUNCTIONS
 * ============================================================================
 * Helper functions for Ujjal Flour Mills ERP System
 * 
 * @package Ujjal Flour Mills
 * @version 2.0
 */

// Prevent direct access
if (!defined('APP_URL')) {
    die('Direct access not permitted');
}

/**
 * ============================================================================
 * CORE LOGIN & SESSION HELPERS
 * ============================================================================
 */

/**
 * Check if a user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Legacy function alias for backward compatibility
 * @return bool
 */
function is_admin_logged_in() {
    return isLoggedIn();
}

/**
 * Get current user's essential data from session
 * @return array|null
 */
function getCurrentUser() {
    if (isLoggedIn()) {
        return [
            'id'           => $_SESSION['user_id'] ?? null,
            'display_name' => $_SESSION['user_display_name'] ?? 'User',
            'role'         => $_SESSION['user_role'] ?? null,
            'email'        => $_SESSION['user_email'] ?? null,
            'branch_id'    => $_SESSION['user_branch_id'] ?? null,
        ];
    }
    return null;
}

/**
 * ============================================================================
 * CUSTOM PERMISSION INFRASTRUCTURE
 * ============================================================================
 */

/**
 * Central module registry — single source of truth for every module:
 * its folder, label, icon, color, nav items, and per-page controllable actions.
 *
 * @return array
 */
function getModuleRegistry(): array {
    return [
        'credit_sales' => [
            'folder' => 'cr',
            'label'  => 'Credit Sales',
            'icon'   => 'fa-chart-line',
            'color'  => 'blue',
            'nav' => [
                ['file' => 'index',                      'page_key' => 'index',                      'label' => 'Credit Dashboard',    'icon' => 'fa-chart-line'],
                ['file' => 'all_sales',                  'page_key' => 'all_sales',                  'label' => 'All Sales',           'icon' => 'fa-list-alt'],
                ['file' => 'create_order',               'page_key' => 'create_order',               'label' => 'Create Order',        'icon' => 'fa-plus-circle'],
                ['file' => 'credit_order_approval',      'page_key' => 'credit_order_approval',      'label' => 'Approve Orders',      'icon' => 'fa-check-circle'],
                ['file' => 'credit_production',          'page_key' => 'credit_production',          'label' => 'Production',          'icon' => 'fa-industry'],
                ['file' => 'order_status',               'page_key' => 'order_status',               'label' => 'Track Orders',        'icon' => 'fa-truck'],
                ['file' => 'credit_dispatch',            'page_key' => 'credit_dispatch',            'label' => 'Dispatch',            'icon' => 'fa-shipping-fast'],
                ['file' => 'partial_delivery',           'page_key' => 'partial_delivery',           'label' => 'Partial Delivery',    'icon' => 'fa-dolly'],
                ['file' => 'returns',                    'page_key' => 'returns',                    'label' => 'Returns',             'icon' => 'fa-undo'],
                ['file' => 'customer_ledger',            'page_key' => 'customer_ledger',            'label' => 'Customer Ledger',     'icon' => 'fa-book'],
                ['file' => 'customer_payment',           'page_key' => 'customer_payment',           'label' => 'Collect Payment',     'icon' => 'fa-money-bill-wave'],
                ['file' => 'advance_payment_collection', 'page_key' => 'advance_payment_collection', 'label' => 'Advance Collection',  'icon' => 'fa-hand-holding-usd'],
                ['file' => 'customer_credit_management', 'page_key' => 'customer_credit_management', 'label' => 'Credit Limits',       'icon' => 'fa-credit-card'],
                ['file' => 'ageing_report',              'page_key' => 'ageing_report',              'label' => 'Ageing Report',       'icon' => 'fa-chart-bar'],
            ],
            'page_actions' => [
                'all_sales'             => ['can_export' => 'Export CSV', 'can_delete' => 'Delete Orders', 'can_edit' => 'Edit Orders'],
                'credit_order_approval' => ['can_approve' => 'Approve Orders', 'can_reject' => 'Reject Orders', 'can_escalate_override' => 'Override 80% Credit Escalation'],
                'customer_payment'      => ['can_collect' => 'Collect Payment', 'can_override' => 'Override Amount'],
                'create_order'          => ['can_create' => 'Create Orders'],
                'customer_ledger'       => ['can_export' => 'Export Ledger'],
                'returns'               => ['can_approve' => 'Auto-Approve Returns', 'can_reject' => 'Reject Returns'],
            ],
        ],
        'customers' => [
            'folder' => 'customers',
            'label'  => 'Customers',
            'icon'   => 'fa-users',
            'color'  => 'green',
            'nav' => [
                ['file' => 'index',  'page_key' => 'index',  'label' => 'All Customers', 'icon' => 'fa-users'],
                ['file' => 'manage', 'page_key' => 'manage', 'label' => 'Add Customer',  'icon' => 'fa-user-plus'],
            ],
            'page_actions' => [
                'index'  => ['can_export' => 'Export CSV'],
                'manage' => ['can_create' => 'Create Customer', 'can_edit' => 'Edit Customer', 'can_delete' => 'Delete Customer'],
            ],
        ],
        'products' => [
            'folder' => 'product',
            'label'  => 'Products',
            'icon'   => 'fa-box',
            'color'  => 'purple',
            'nav' => [
                ['file' => 'products',         'page_key' => 'products',         'label' => 'Overview',         'icon' => 'fa-box'],
                ['file' => 'base_products',    'page_key' => 'base_products',    'label' => 'Base Products',    'icon' => 'fa-cube'],
                ['file' => 'pricing',          'page_key' => 'pricing',          'label' => 'Pricing',          'icon' => 'fa-tags'],
                ['file' => 'pricing_engine',   'page_key' => 'pricing_engine',   'label' => 'Smart Pricing',    'icon' => 'fa-bolt',   'admin_only' => true],
                ['file' => 'inventory',        'page_key' => 'inventory',        'label' => 'Inventory',        'icon' => 'fa-warehouse'],
                ['file' => 'manage_variants',  'page_key' => 'manage_variants',  'label' => 'Manage Variants',  'icon' => 'fa-list'],
            ],
            'page_actions' => [
                'pricing'   => ['can_edit' => 'Edit Pricing'],
                'inventory' => ['can_adjust' => 'Adjust Stock'],
            ],
        ],
        'expenses' => [
            'folder' => 'expense',
            'label'  => 'Expenses',
            'icon'   => 'fa-receipt',
            'color'  => 'yellow',
            'nav' => [
                ['file' => 'index',               'page_key' => 'index',               'label' => 'Expense Dashboard', 'icon' => 'fa-tachometer-alt'],
                ['file' => 'expense_history',     'page_key' => 'expense_history',     'label' => 'Expense History',   'icon' => 'fa-history'],
                ['file' => 'create_expense',      'page_key' => 'create_expense',      'label' => 'Create Expense',    'icon' => 'fa-plus-circle'],
                ['file' => 'approve_expense',     'page_key' => 'approve_expense',     'label' => 'Approve Expenses',  'icon' => 'fa-check-circle'],
                ['file' => 'edit_expense',        'page_key' => 'edit_expense',        'label' => 'Edit Expense',      'icon' => 'fa-edit'],
                ['file' => 'expense_voucher_list','page_key' => 'expense_voucher_list','label' => 'Voucher List',      'icon' => 'fa-file-invoice'],
                ['file' => 'expense_categories',  'page_key' => 'expense_categories',  'label' => 'Categories',        'icon' => 'fa-tags'],
            ],
            'page_actions' => [
                'create_expense'  => ['can_create' => 'Create Expense'],
                'approve_expense' => ['can_approve' => 'Approve Expenses', 'can_reject' => 'Reject Expenses'],
                'edit_expense'    => ['can_edit' => 'Edit Expenses'],
                'expense_history' => ['can_export' => 'Export CSV', 'can_delete' => 'Delete Records'],
                'expense_categories' => ['can_manage' => 'Manage Categories'],
            ],
        ],
        'bank' => [
            'folder' => 'bank',
            'label'  => 'Bank',
            'icon'   => 'fa-university',
            'color'  => 'indigo',
            'nav' => [
                ['file' => 'index',              'page_key' => 'index',              'label' => 'Dashboard',             'icon' => 'fa-tachometer-alt'],
                ['file' => 'create_transaction', 'page_key' => 'create_transaction', 'label' => 'New Transaction',       'icon' => 'fa-plus'],
                ['file' => 'transfer',           'page_key' => 'transfer',           'label' => 'Bank to Bank Transfer', 'icon' => 'fa-exchange-alt'],
                ['file' => 'statement',          'page_key' => 'statement',          'label' => 'Account Statement',     'icon' => 'fa-file-alt'],
                ['file' => 'manage_accounts',    'page_key' => 'manage_accounts',    'label' => 'Manage Accounts',       'icon' => 'fa-piggy-bank'],
                ['file' => 'manage_types',       'page_key' => 'manage_types',       'label' => 'Transaction Types',     'icon' => 'fa-tags'],
                ['file' => 'bulk_manage',        'page_key' => 'bulk_manage',        'label' => 'Bulk Manage',           'icon' => 'fa-layer-group'],
            ],
            'page_actions' => [
                'create_transaction' => ['can_create' => 'Create Transaction', 'can_approve' => 'Approve Transaction'],
                'transfer'           => ['can_transfer' => 'Transfer Funds'],
                'statement'          => ['can_export' => 'Export Statement'],
            ],
        ],
        'accounts' => [
            'folder' => 'accounts',
            'label'  => 'Accounts',
            'icon'   => 'fa-book',
            'color'  => 'teal',
            'nav' => [
                ['file' => 'chart_of_accounts', 'page_key' => 'chart_of_accounts', 'label' => 'Chart of Accounts', 'icon' => 'fa-sitemap'],
                ['file' => 'new_transaction',   'page_key' => 'new_transaction',   'label' => 'New Transaction',   'icon' => 'fa-plus-circle'],
                ['file' => 'all_accounts',      'page_key' => 'all_accounts',      'label' => 'All Statements',    'icon' => 'fa-list'],
                ['file' => 'bank_accounts',     'page_key' => 'bank_accounts',     'label' => 'Bank Accounts',     'icon' => 'fa-university'],
                ['file' => 'internal_transfer', 'page_key' => 'internal_transfer', 'label' => 'Internal Transfer', 'icon' => 'fa-exchange-alt'],
                ['file' => 'debit_voucher',     'page_key' => 'debit_voucher',     'label' => 'Debit Voucher',     'icon' => 'fa-receipt'],
                ['file' => 'daily_log',         'page_key' => 'daily_log',         'label' => 'Daily Log',         'icon' => 'fa-calendar-day'],
                ['file' => 'account_statement', 'page_key' => 'account_statement', 'label' => 'Account Statement', 'icon' => 'fa-file-alt'],
                ['file' => 'reconcile',         'page_key' => 'reconcile',         'label' => 'Reconciliation',    'icon' => 'fa-balance-scale'],
            ],
            'page_actions' => [
                'new_transaction'   => ['can_create' => 'Create Transaction', 'can_delete' => 'Delete Transaction'],
                'debit_voucher'     => ['can_create' => 'Create Voucher', 'can_approve' => 'Approve Voucher'],
                'internal_transfer' => ['can_transfer' => 'Make Transfer'],
                'all_accounts'      => ['can_export' => 'Export Data'],
                'daily_log'         => ['can_export' => 'Export Log'],
                'reconcile'         => ['can_export' => 'Export Reconciliation'],
            ],
        ],
        'purchase' => [
            'folder' => 'purchase',
            'label'  => 'Purchase',
            'icon'   => 'fa-shopping-cart',
            'color'  => 'orange',
            'nav' => [
                ['file' => 'purchase_adnan_index',            'page_key' => 'purchase_adnan_index',            'label' => 'Dashboard',       'icon' => 'fa-tachometer-alt'],
                ['file' => 'purchase_adnan_supplier_summary', 'page_key' => 'purchase_adnan_supplier_summary', 'label' => 'All Suppliers',   'icon' => 'fa-users'],
                ['file' => 'purchase_adnan_supplier_ledger',  'page_key' => 'purchase_adnan_supplier_ledger',  'label' => 'Supplier Ledger', 'icon' => 'fa-book'],
                ['file' => 'all_po',                          'page_key' => 'all_po',                          'label' => 'All POs',         'icon' => 'fa-file-invoice'],
                ['file' => 'purchase_adnan_create_po',        'page_key' => 'purchase_adnan_create_po',        'label' => 'Create PO',       'icon' => 'fa-plus-circle'],
                ['file' => 'goods_received',                  'page_key' => 'goods_received',                  'label' => 'Goods Received',  'icon' => 'fa-clipboard-check'],
                ['file' => 'payments',                        'page_key' => 'payments',                        'label' => 'All Payments',    'icon' => 'fa-money-bill-wave'],
                ['file' => 'purchase_adnan_record_payment',   'page_key' => 'purchase_adnan_record_payment',   'label' => 'Record Payment',  'icon' => 'fa-plus'],
                ['file' => 'reports',                         'page_key' => 'reports',                         'label' => 'Reports',         'icon' => 'fa-chart-bar'],
            ],
            'page_actions' => [
                'purchase_adnan_create_po'        => ['can_create' => 'Create PO', 'can_approve' => 'Approve PO'],
                'purchase_adnan_record_payment'   => ['can_pay' => 'Record Payment'],
                'purchase_adnan_supplier_summary' => ['can_create' => 'Add Supplier'],
                'all_po'                          => ['can_export' => 'Export', 'can_delete' => 'Delete PO'],
                'goods_received'                  => ['can_receive' => 'Record GRN', 'can_delete' => 'Delete GRN'],
            ],
        ],
        'sales' => [
            'folder' => 'sales',
            'label'  => 'Sales',
            'icon'   => 'fa-shopping-bag',
            'color'  => 'emerald',
            'nav' => [
                ['file' => 'index',         'page_key' => 'index',         'label' => 'Sales Dashboard', 'icon' => 'fa-tachometer-alt'],
                ['file' => 'order_history', 'page_key' => 'order_history', 'label' => 'Order History',   'icon' => 'fa-history'],
            ],
            'page_actions' => [
                'order_history' => ['can_export' => 'Export CSV'],
            ],
        ],
        'production' => [
            'folder' => 'production',
            'label'  => 'Production',
            'icon'   => 'fa-industry',
            'color'  => 'amber',
            'nav' => [
                ['file' => 'index', 'page_key' => 'index', 'label' => 'Production Dashboard', 'icon' => 'fa-industry'],
            ],
            'page_actions' => [],
        ],
        'dispatch' => [
            'folder' => 'dispatch',
            'label'  => 'Dispatch',
            'icon'   => 'fa-truck',
            'color'  => 'cyan',
            'nav' => [
                ['file' => 'index', 'page_key' => 'index', 'label' => 'Dispatch Dashboard', 'icon' => 'fa-truck'],
            ],
            'page_actions' => [],
        ],
        'pos' => [
            'folder' => 'pos',
            'label'  => 'Point of Sale',
            'icon'   => 'fa-cash-register',
            'color'  => 'rose',
            'nav' => [
                ['file' => 'index',        'page_key' => 'index',        'label' => 'POS Terminal',  'icon' => 'fa-cash-register'],
                ['file' => 'todays_sales', 'page_key' => 'todays_sales', 'label' => "Today's Sales", 'icon' => 'fa-receipt'],
            ],
            'page_actions' => [
                'todays_sales' => ['can_export' => 'Export CSV'],
            ],
        ],
        'collector' => [
            'folder' => 'collector',
            'label'  => 'Collector',
            'icon'   => 'fa-hand-holding-usd',
            'color'  => 'violet',
            'nav' => [
                ['file' => 'index', 'page_key' => 'index', 'label' => 'Collector Dashboard', 'icon' => 'fa-hand-holding-usd'],
            ],
            'page_actions' => [],
        ],
        'admin' => [
            'folder' => 'admin',
            'label'  => 'Admin',
            'icon'   => 'fa-cogs',
            'color'  => 'red',
            'nav' => [
                ['file' => 'users',         'page_key' => 'users',         'label' => 'Users',           'icon' => 'fa-users'],
                ['file' => 'employees',     'page_key' => 'employees',     'label' => 'Employees',       'icon' => 'fa-id-badge'],
                ['file' => 'user_activity', 'page_key' => 'user_activity', 'label' => 'Audit Trail',     'icon' => 'fa-history'],
                ['file' => 'cashflow_dashboard', 'page_key' => 'cashflow_dashboard', 'label' => 'Cash Flow & Ops',    'icon' => 'fa-chart-line'],
                ['file' => 'settings',      'page_key' => 'settings',      'label' => 'Settings',        'icon' => 'fa-cog'],
                ['file' => 'privileges',    'page_key' => 'privileges',    'label' => 'User Privileges', 'icon' => 'fa-shield-alt'],
                ['file' => 'db_viewer',     'page_key' => 'db_viewer',     'label' => 'DB Viewer',       'icon' => 'fa-database',       'admin_only' => true],
            ],
            'page_actions' => [],
        ],
    ];
}

/**
 * Convert snake_case filename to human-readable Title Case label.
 *
 * @param string $key
 * @return string
 */
function humanizeKey(string $key): string {
    return ucwords(str_replace(['_', '-'], ' ', $key));
}

/**
 * Return true if this filename (without .php) should be hidden from the privileges UI.
 * Catches: class files, ajax handlers, test/debug files, old versions, telegram variants, etc.
 *
 * @param string $basename  filename without .php extension
 * @return bool
 */
function isAutoHiddenPage(string $basename): bool {
    // Class files start with uppercase letter
    if (preg_match('/^[A-Z]/', $basename)) return true;
    // Ajax handlers
    if (str_starts_with($basename, 'ajax')) return true;
    // Test / debug files (case-insensitive debug match)
    if (str_starts_with($basename, 'test')) return true;
    if (stripos($basename, 'debug') !== false) return true;
    // Old versioned copies
    if (str_contains($basename, '_old')) return true;
    // Date-stamped files (6-8 digit date at end, optionally preceded by _)
    if (preg_match('/_?\d{6,8}$/', $basename)) return true;
    // Numbered variant copies (_1, _2, _3 suffix)
    if (preg_match('/_\d{1,2}$/', $basename)) return true;
    // Telegram variants
    if (str_contains($basename, 'telegram')) return true;
    // Working / original copies
    if (str_contains($basename, '_working') || str_contains($basename, '_original')) return true;
    // Not-working copies
    if (str_contains($basename, '_not_working')) return true;
    // Proxy / agent / AI utility files
    if (str_ends_with($basename, '_proxy') || str_ends_with($basename, '_agent')) return true;
    if (str_contains($basename, 'ai_proxy') || str_contains($basename, 'purchase_agent') || str_contains($basename, 'purchase_advisor')) return true;
    // Without-X variants: _without_telegram, _without_ai, _without_cash, "without AI" (space), etc.
    if (str_contains($basename, '_without')) return true;
    // With-X variants that are clearly alternate copies (e.g. _with_received_vlaue)
    if (str_contains($basename, '_with_received')) return true;
    // Typo variants (withiut = without with typo, withiut_cash etc.)
    if (str_contains($basename, 'withiut')) return true;
    // Specific utility files that don't warrant access control
    if (in_array($basename, ['check_functions', 'get_voucher_details', 'payment_debug', 'adnan_index', 'receipt'])) return true;

    return false;
}

/**
 * Scan a module folder for accessible PHP files. Returns page_key => label array.
 * Results are cached in core/cache/ keyed by folder mtime.
 *
 * @param string $module_key  e.g. 'accounts'
 * @return array  [page_key => ['label' => string, 'actions' => [key => label]]]
 */
function scanModulePages(string $module_key): array {
    static $mem = [];
    if (isset($mem[$module_key])) return $mem[$module_key];

    $registry = getModuleRegistry();
    if (!isset($registry[$module_key])) return $mem[$module_key] = [];

    $appRoot  = str_replace('\\', '/', defined('APP_ROOT') ? APP_ROOT : dirname(dirname(__DIR__)));
    $folder   = $appRoot . '/' . $registry[$module_key]['folder'];
    if (!is_dir($folder)) return $mem[$module_key] = [];

    // — Cache layer —
    $cacheDir  = $appRoot . '/core/cache';
    $cacheFile = $cacheDir . '/modpages_' . $module_key . '.json';
    $folderMtime = @filemtime($folder) ?: 0;
    if (is_file($cacheFile) && @filemtime($cacheFile) > $folderMtime) {
        $cached = @json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached)) return $mem[$module_key] = $cached;
    }

    // — Scan —
    $pageActions = $registry[$module_key]['page_actions'] ?? [];
    $files = glob($folder . '/*.php') ?: [];
    $pages = [];
    foreach ($files as $filepath) {
        $basename = basename($filepath, '.php');
        if (isAutoHiddenPage($basename)) continue;
        // Check for explicit @hidden tag in first 10 lines
        $fh = @fopen($filepath, 'r');
        $hidden = false;
        if ($fh) {
            for ($i = 0; $i < 10 && !feof($fh); $i++) {
                if (str_contains(fgets($fh), '@hidden')) { $hidden = true; break; }
            }
            fclose($fh);
        }
        if ($hidden) continue;

        $pages[$basename] = [
            'label'   => humanizeKey($basename),
            'actions' => $pageActions[$basename] ?? [],
        ];
    }

    // Sort alphabetically
    ksort($pages);

    // — Write cache —
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    @file_put_contents($cacheFile, json_encode($pages));

    return $mem[$module_key] = $pages;
}

/**
 * Auto-detect [module, page_key] for the current executing page using
 * folder-based detection against the module registry.
 * Returns null if the page is not in any registered module folder.
 *
 * @return array|null  [module_key, page_key] or null
 */
function detect_page_module(): ?array {
    static $cached = false;
    if ($cached !== false) return $cached;

    $appRoot = str_replace('\\', '/', defined('APP_ROOT') ? APP_ROOT : dirname(dirname(__DIR__)));
    $script  = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');

    if (!str_starts_with($script, $appRoot . '/')) return $cached = null;

    $relative = substr($script, strlen($appRoot) + 1); // e.g. "accounts/daily_log.php"
    $parts    = explode('/', $relative);
    if (count($parts) < 2) return $cached = null;

    $scriptFolder = $parts[0];
    $pageKey      = basename(end($parts), '.php');

    foreach (getModuleRegistry() as $module_key => $module_def) {
        if ($module_def['folder'] === $scriptFolder) {
            return $cached = [$module_key, $pageKey];
        }
    }
    return $cached = null;
}

/**
 * Check whether the current user can perform a specific action on a specific page.
 * Actions are stored per-page in allowed_actions: {"page_key": {"action_key": true}}
 *
 * @param string $module    e.g. 'credit_sales'
 * @param string $page_key  e.g. 'all_sales'
 * @param string $action    e.g. 'can_export'
 * @return bool
 */
function userCanPageAction(string $module, string $page_key, string $action): bool {
    if (in_array($_SESSION['user_role'] ?? '', ['Superadmin', 'admin'])) return true;
    $perms = getUserCustomPerms();
    if (!isset($perms[$module]) || !$perms[$module]['enabled']) return false;
    $actions = $perms[$module]['allowed_actions'][$page_key] ?? [];
    return !empty($actions[$action]);
}

/**
 * Load the current user's custom permissions from DB, session-cached for 300 s.
 * Returns an empty array for Superadmin (no restrictions apply) and for users
 * who have no custom entries in user_custom_permissions.
 *
 * Shape:
 *   ['module_key' => [
 *       'enabled'         => bool,
 *       'allowed_pages'   => string[],
 *       'allowed_actions' => [action_key => bool],
 *       'data_scope'      => string,
 *   ]]
 *
 * @return array
 */
function getUserCustomPerms(): array {
    // Superadmin is never subject to custom restrictions
    if (($_SESSION['user_role'] ?? '') === 'Superadmin') {
        return [];
    }

    $userId = $_SESSION['user_id'] ?? 0;
    if (!$userId) return [];

    // Return 300-second session cache if still fresh
    if (isset($_SESSION['_ccp'], $_SESSION['_ccp_ts'])
        && (time() - $_SESSION['_ccp_ts']) < 300) {
        return $_SESSION['_ccp'];
    }

    try {
        global $db;
        $rows = $db->query(
            "SELECT module, is_module_enabled, allowed_pages, allowed_actions, data_scope
               FROM user_custom_permissions
              WHERE user_id = ?",
            [$userId]
        )->results();

        $perms = [];
        foreach ($rows as $row) {
            $perms[$row->module] = [
                'enabled'         => (bool)$row->is_module_enabled,
                'allowed_pages'   => !empty($row->allowed_pages)
                    ? (array)json_decode($row->allowed_pages, true) : [],
                'allowed_actions' => !empty($row->allowed_actions)
                    ? json_decode($row->allowed_actions, true) : [],
                'data_scope'      => $row->data_scope ?? 'all',
            ];
        }

        $_SESSION['_ccp']    = $perms;
        $_SESSION['_ccp_ts'] = time();
        return $perms;

    } catch (\Throwable $e) {
        error_log('getUserCustomPerms failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Force-clear the custom-perms session cache for the current user.
 * Call this after updating the current user's privileges.
 */
function invalidateCustomPermsCache(): void {
    unset($_SESSION['_ccp'], $_SESSION['_ccp_ts']);
}

/**
 * Check whether the current user can perform a named action in a module.
 * Superadmin and admin always return true.
 * All other users must have the action explicitly set in user_custom_permissions.
 *
 * @param string $module  e.g. 'credit_sales'
 * @param string $action  e.g. 'can_approve'
 * @return bool
 */
function userCanAction(string $module, string $action): bool {
    if (in_array($_SESSION['user_role'] ?? '', ['Superadmin', 'admin'])) return true;

    $perms = getUserCustomPerms();
    if (!isset($perms[$module])) return false;
    if (!$perms[$module]['enabled'])  return false;

    $actions = $perms[$module]['allowed_actions'] ?? [];
    return !empty($actions[$action]);
}

/**
 * Restrict access to a page — pure privilege model.
 *
 * Access rules:
 *   1. Not logged in                        → redirect to login.
 *   2. Role is Superadmin or admin          → always allowed, no further checks.
 *   3. Page is in the module map:
 *      a. No custom-perm entry for module   → deny (no implicit role fallback).
 *      b. Module is disabled                → deny.
 *      c. Module enabled + page whitelist   → allow only if page_key is listed.
 *      d. Module enabled + no page list     → allow entire module.
 *   4. Page is NOT in the module map        → allow any authenticated user
 *      (unmapped utility pages, e.g. index.php, profile.php).
 *      Pass a non-empty $allowed_roles to add a role gate on unmapped pages.
 *
 * The module and page_key are auto-detected from the current URL via
 * detect_page_module() / getModuleRegistry() when not supplied explicitly.
 *
 * @param array       $allowed_roles  Optional role gate for unmapped pages only
 * @param string|null $module         Module key override (optional; auto-detected)
 * @param string|null $page_key       Page key override (optional; auto-detected)
 */
function restrict_access(array $allowed_roles = [], ?string $module = null, ?string $page_key = null): void {
    // ── Must be logged in ─────────────────────────────────────────────────────
    if (!isLoggedIn()) {
        $_SESSION['error_flash'] = 'You must be logged in to access that page.';
        header('Location: ' . url('auth/login.php'));
        exit();
    }

    $user_role = $_SESSION['user_role'] ?? null;

    // ── Superadmin and admin pass unconditionally ─────────────────────────────
    if (in_array($user_role, ['Superadmin', 'admin'])) {
        return;
    }

    // ── Auto-detect module/page from URL ──────────────────────────────────────
    if ($module === null) {
        $detected = detect_page_module();
        if ($detected !== null) {
            [$module, $page_key] = $detected;
        }
    }

    // ── Module-mapped page: pure privilege check, no role fallback ────────────
    if ($module !== null) {
        $custom_perms = getUserCustomPerms();
        $mod = $custom_perms[$module] ?? null;

        // No privilege entry for this module → deny
        if ($mod === null) {
            $_SESSION['error_flash'] = 'You do not have permission to access that page.';
            header('Location: ' . url('index.php'));
            exit();
        }

        // Module explicitly disabled → deny
        if (!$mod['enabled']) {
            $_SESSION['error_flash'] = 'You do not have permission to access that page.';
            header('Location: ' . url('index.php'));
            exit();
        }

        // Module enabled + page whitelist → enforce it
        if ($page_key !== null && !empty($mod['allowed_pages'])) {
            if (in_array($page_key, $mod['allowed_pages'])) {
                return; // Page explicitly granted
            }
            $_SESSION['error_flash'] = 'You do not have permission to access that page.';
            header('Location: ' . url('index.php'));
            exit();
        }

        // Module enabled + no page restriction → grant access to full module
        return;
    }

    // ── Unmapped page (not in module map) ─────────────────────────────────────
    // Any authenticated user may access pages not tied to a module,
    // unless an explicit $allowed_roles gate is provided by the caller.
    if (!empty($allowed_roles) && !in_array($user_role, $allowed_roles)) {
        $_SESSION['error_flash'] = 'You do not have permission to access that page.';
        header('Location: ' . url('index.php'));
        exit();
    }
}

/**
 * ============================================================================
 * URL & ASSET HELPERS
 * ============================================================================
 */

/**
 * Create full URL to a path within the application
 * @param string $path
 * @return string
 */
function url($path = '') {
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

/**
 * Create full URL to an asset (CSS, JS, image)
 * @param string $path
 * @return string
 */
function asset($path) {
    return rtrim(APP_URL, '/') . '/assets/' . ltrim($path, '/');
}

/**
 * ============================================================================
 * MESSAGE DISPLAY HELPERS
 * ============================================================================
 */

/**
 * Display and clear flash messages
 * @return string
 */
function display_message() {
    $message = '';
    
    // Success message
    if (isset($_SESSION['success_flash'])) {
        $message = '<div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded-r-lg" role="alert">
                        <p class="font-bold">Success</p>
                        <p>' . htmlspecialchars($_SESSION['success_flash']) . '</p>
                    </div>';
        unset($_SESSION['success_flash']);
    }

    // Error message
    if (isset($_SESSION['error_flash'])) {
        $message = '<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded-r-lg" role="alert">
                        <p class="font-bold">Error</p>
                        <p>' . htmlspecialchars($_SESSION['error_flash']) . '</p>
                    </div>';
        unset($_SESSION['error_flash']);
    }
    
    return $message;
}

/**
 * Alias for display_message
 * @return string
 */
function displayFlashMessage() {
    return display_message();
}

/**
 * ============================================================================
 * UTILITY HELPERS
 * ============================================================================
 */

/**
 * Escape HTML entities
 * @param string $string
 * @return string
 */
if (!function_exists('e')) {
    function e($string) {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Redirect to a path with optional flash message
 * @param string $path
 * @param string $message
 * @param string $type
 */
function redirect($path, $message = '', $type = 'success') {
    if (!empty($message)) {
        if ($type === 'success') {
            $_SESSION['success_flash'] = $message;
        } else {
            $_SESSION['error_flash'] = $message;
        }
    }
    
    // If path doesn't start with http, treat as relative
    if (strpos($path, 'http') !== 0) {
        $path = url($path);
    }
    
    header('Location: ' . $path);
    exit();
}

/**
 * Sanitize input
 * @param string|array $input
 * @return string|array
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * ============================================================================
 * EXPENSE MODULE PERMISSION FUNCTIONS
 * ============================================================================
 */

/**
 * Check if user can access Expense module
 * @return bool
 */
function canAccessExpense() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $role = $_SESSION['user_role'];
    
    return in_array($role, [
        'Superadmin',
        'admin',
        'Accounts',
        'Expense Initiator',
        'Expense Approver',
        'accounts-demra',
        'accounts-srg'
    ]);
}

/**
 * Check if user can create expense vouchers
 * @return bool
 */
function canCreateExpense() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $role = $_SESSION['user_role'];
    
    return in_array($role, [
        'Superadmin',
        'admin',
        'Accounts',
        'Expense Initiator',
        'accounts-demra',
        'accounts-srg'
    ]);
}

/**
 * Alias for canCreateExpense
 * @return bool
 */
function canCreateExpenseVoucher() {
    return canCreateExpense();
}

/**
 * Check if user can approve expense vouchers
 * @return bool
 */
function canApproveExpense() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $role = $_SESSION['user_role'];
    
    return in_array($role, [
        'Superadmin',
        'admin',
        'Accounts',
        'Expense Approver'
    ]);
}

/**
 * Check if user can access approve expense page
 * @return bool
 */
function canAccessApproveExpense() {
    return canApproveExpense();
}

/**
 * Check if user can edit expense vouchers (Superadmin only)
 * @return bool
 */
function canEditExpense() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    return $_SESSION['user_role'] === 'Superadmin';
}

/**
 * Check if user can delete expense vouchers (Superadmin only)
 * @return bool
 */
function canDeleteExpense() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    return $_SESSION['user_role'] === 'Superadmin';
}

/**
 * Check if user can access expense history page
 * @return bool
 */
function canAccessExpenseHistory() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $role = $_SESSION['user_role'];
    
    return in_array($role, [
        'Superadmin',
        'admin',
        'Accounts',
        'Expense Approver',
        'Expense Initiator',
        'accounts-demra',
        'accounts-srg'
    ]);
}

/**
 * Check if user can see expense dashboard/statistics
 * @return bool
 */
function canSeeExpenseDashboard() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $role = $_SESSION['user_role'];
    
    return in_array($role, [
        'Superadmin',
        'admin',
        'Accounts'
    ]);
}

/**
 * Check if user can view expense vouchers
 * @return bool
 */
function canViewExpense() {
    return canAccessExpenseHistory();
}

/**
 * Check if user can manage expense categories
 * @return bool
 */
function canManageExpenseCategories() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    return $_SESSION['user_role'] === 'Superadmin';
}

/**
 * Check if user can print expense vouchers
 * @return bool
 */
function canPrintExpense() {
    return canAccessExpenseHistory();
}

/**
 * ============================================================================
 * AUDIT TRAIL HELPER FUNCTIONS
 * ============================================================================
 */

/**
 * Check if user can access audit trail
 * @return bool
 */
function canAccessAuditTrail() {
    return ($_SESSION['user_role'] ?? '') === 'Superadmin';
}

/**
 * Check if user can view audit logs for specific user
 * @param int $userId
 * @return bool
 */
function canViewUserAudit($userId) {
    $currentUserId = $_SESSION['user_id'] ?? 0;
    $userRole = $_SESSION['user_role'] ?? '';
    
    // Superadmin can view all
    if ($userRole === 'Superadmin') {
        return true;
    }
    
    // Others can only view their own
    return $currentUserId == $userId;
}

/**
 * Check if action should be logged
 * @param string $action
 * @return bool
 */
function shouldLogAction($action) {
    $skipActions = ['viewed', 'listed', 'searched', 'filtered'];
    return !in_array($action, $skipActions);
}

/**
 * Get current user ID for logging
 * @return int|null
 */
function getAuditUserId() {
    return $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
}

/**
 * Get current user name for logging
 * @return string
 */
function getAuditUserName() {
    return $_SESSION['user_display_name'] ?? $_SESSION['display_name'] ?? 'Unknown User';
}

/**
 * Quick audit log function
 * @param string $module
 * @param string $action
 * @param string $description
 * @param array $options
 * @return bool
 */
function auditLog($module, $action, $description, $options = []) {
    if (!shouldLogAction($action)) {
        return false;
    }
    
    try {
        require_once __DIR__ . '/../classes/AuditLogger.php';
        $options['description'] = $description;
        return AuditLogger::log($module, $action, $options);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Log expense action
 * @param string $action
 * @param int $expenseId
 * @param string $voucherNumber
 * @param string $description
 * @param mixed $data
 * @return bool
 */
function auditLogExpense($action, $expenseId, $voucherNumber, $description, $data = null) {
    try {
        require_once __DIR__ . '/../classes/AuditLogger.php';
        return AuditLogger::logExpense($action, $expenseId, $voucherNumber, [
            'description' => $description,
            'data' => $data
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Log credit order action
 * @param string $action
 * @param int $orderId
 * @param string $orderNumber
 * @param string $description
 * @param mixed $data
 * @return bool
 */
function auditLogOrder($action, $orderId, $orderNumber, $description, $data = null) {
    try {
        require_once __DIR__ . '/../classes/AuditLogger.php';
        return AuditLogger::logCreditOrder($action, $orderId, $orderNumber, [
            'description' => $description,
            'data' => $data
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Log payment action
 * @param string $action
 * @param int $paymentId
 * @param string $reference
 * @param string $description
 * @param mixed $data
 * @return bool
 */
function auditLogPayment($action, $paymentId, $reference, $description, $data = null) {
    try {
        require_once __DIR__ . '/../classes/AuditLogger.php';
        return AuditLogger::logPayment($action, $paymentId, $reference, [
            'description' => $description,
            'data' => $data
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Log authentication action
 * @param string $action
 * @param string|null $description
 * @return bool
 */
function auditLogAuth($action, $description = null) {
    $userId = getAuditUserId();
    if (!$userId) return false;
    
    try {
        require_once __DIR__ . '/../classes/AuditLogger.php';
        return AuditLogger::logAuth($action, $userId, [
            'description' => $description ?? ($action === 'logged_in' ? 'User logged in' : 'User logged out')
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
        return false;
    }
}

/**
 * ============================================================================
 * END OF HELPERS
 * ============================================================================
 */
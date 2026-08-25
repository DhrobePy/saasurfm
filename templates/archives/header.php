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

    // =========================================================
    // ROLE GROUPS
    // =========================================================

    $admin_roles   = ['Superadmin', 'admin'];

    $accounts_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg'];

    // All roles that can see the Credit Sales section
    $credit_sales_roles = [
        'Superadmin', 'admin',
        'Accounts', 'accounts-demra', 'accounts-srg',
        'dispatch-demra', 'dispatch-srg', 'dispatchpos-demra', 'dispatchpos-srg',
        'production manager-srg', 'production manager-demra',
        'sales-srg', 'sales-demra', 'sales-other',
        'collector',
    ];

    // POS roles (module currently hidden/commented out)
    $pos_roles = [
        'Superadmin', 'admin',
        'accountspos-demra', 'accountspos-srg',
        'dispatchpos-demra', 'dispatchpos-srg',
    ];

    // Logistics roles (module currently hidden/commented out)
    $logistics_roles = [
        'Superadmin', 'admin',
        'Transport Manager',
        'dispatch-demra', 'dispatch-srg',
        'dispatchpos-demra', 'dispatchpos-srg',
    ];

    // Purchase module — accounts + production managers
    $purchase_roles = [
        'Superadmin', 'admin',
        'Accounts', 'accounts-demra', 'accounts-srg',
        'production manager-srg', 'production manager-demra',
    ];

    // Expense module — accounts + dedicated expense roles
    $expense_roles = [
        'Superadmin', 'admin',
        'Accounts', 'accounts-demra', 'accounts-srg',
        'Expense Initiator', 'Expense Approver',
    ];
    $expense_category_roles = ['Superadmin', 'admin', 'Accounts'];
    $expense_approver_roles = ['Superadmin', 'admin', 'Accounts', 'Expense Approver'];
    $expense_create_roles   = [
        'Superadmin', 'admin',
        'Accounts', 'accounts-demra', 'accounts-srg',
        'Expense Initiator',
    ];

    // Bank module
    $bank_roles = [
        'Superadmin', 'admin',
        'bank Transaction initiator',
        'Bank Transaction Approver',
    ];
    // Only these roles can CREATE new transactions
    $bank_create_roles = ['Superadmin', 'admin', 'bank Transaction initiator'];
    // Only these roles can manage bank accounts / types / bulk manage
    $bank_admin_roles  = ['Superadmin', 'admin'];

    // =========================================================
    // CONVENIENCE FLAGS
    // =========================================================

    // Roles whose entire focus is expense — hide unrelated modules
    $is_expense_only = in_array($user_role, ['Expense Initiator', 'Expense Approver']);

    // Roles whose entire focus is bank transactions — hide unrelated modules
    $is_bank_only    = in_array($user_role, ['bank Transaction initiator', 'Bank Transaction Approver']);

    // =========================================================
    // CREDIT SALES — per-item permission matrix
    // =========================================================

    $credit_menu_permissions = [
        'dashboard' => [
            'Superadmin', 'admin',
            'Accounts', 'accounts-demra', 'accounts-srg',
            'sales-srg', 'sales-demra', 'sales-other',
            'production manager-srg', 'production manager-demra',
            'dispatch-demra', 'dispatch-srg', 'dispatchpos-demra', 'dispatchpos-srg',
            'collector',
        ],
        'create_order' => [
            'Superadmin', 'admin',
            'sales-srg', 'sales-demra', 'sales-other',
        ],
        'approve_orders' => [
            'Superadmin', 'admin',
            'Accounts', 'accounts-demra', 'accounts-srg',
        ],
        'production_queue' => [
            'Superadmin', 'admin',
            'production manager-srg', 'production manager-demra',
        ],
        'track_status' => [
            'Superadmin', 'admin',
            'Accounts', 'accounts-demra', 'accounts-srg',
            'sales-srg', 'sales-demra', 'sales-other',
            'production manager-srg', 'production manager-demra',
            'dispatch-demra', 'dispatch-srg', 'dispatchpos-demra', 'dispatchpos-srg',
        ],
        'dispatch' => [
            'Superadmin', 'admin',
            'dispatch-demra', 'dispatch-srg',
            'dispatchpos-demra', 'dispatchpos-srg',
        ],
        'customer_ledger' => [
            'Superadmin', 'admin',
            'Accounts', 'accounts-demra', 'accounts-srg',
            'collector',
        ],
        'collect_payment' => [
            'Superadmin', 'admin',
            'Accounts', 'accounts-demra', 'accounts-srg',
            'collector',
        ],
        'advance_collection' => [
            'Superadmin', 'admin',
            'Accounts', 'accounts-demra', 'accounts-srg',
            'collector',
        ],
        'credit_limits' => [
            'Superadmin', 'admin',
            'Accounts', 'accounts-demra', 'accounts-srg',
        ],
    ];

    function canAccessCreditMenu($item, $role, $perms) {
        return isset($perms[$item]) && in_array($role, $perms[$item]);
    }

    // Credit Sales — flat links (one row of nav items) for focused roles
    $flat_menu_roles = [
        'sales-srg', 'sales-demra', 'sales-other',
        'production manager-srg', 'production manager-demra',
        'dispatch-demra', 'dispatch-srg',
        'dispatchpos-demra', 'dispatchpos-srg',
        'collector',
    ];

    // Credit Sales — dropdown for admin/accounts
    $dropdown_menu_roles = [
        'Superadmin', 'admin',
        'Accounts', 'accounts-demra', 'accounts-srg',
    ];

    $show_flat_menu     = in_array($user_role, $flat_menu_roles);
    $show_dropdown_menu = in_array($user_role, $dropdown_menu_roles);

    // Credit Sales menu item definitions
    $credit_menu_items = [
        'dashboard'        => ['label' => 'Credit Dashboard', 'url' => 'cr/index.php',                    'icon' => 'fa-chart-line'],
        'create_order'     => ['label' => 'Create Order',     'url' => 'cr/create_order.php',              'icon' => 'fa-plus-circle'],
        'approve_orders'   => ['label' => 'Approve Orders',   'url' => 'cr/credit_order_approval.php',     'icon' => 'fa-check-circle'],
        'production_queue' => ['label' => 'Production',       'url' => 'cr/credit_production.php',         'icon' => 'fa-industry'],
        'track_status'     => ['label' => 'Track Orders',     'url' => 'cr/order_status.php',              'icon' => 'fa-truck'],
        'dispatch'         => ['label' => 'Dispatch',         'url' => 'cr/credit_dispatch.php',           'icon' => 'fa-shipping-fast'],
        'customer_ledger'  => ['label' => 'Ledger',           'url' => 'cr/customer_ledger.php',           'icon' => 'fa-book'],
        'collect_payment'  => ['label' => 'Collect Payment',  'url' => 'cr/credit_payment_collect.php',    'icon' => 'fa-money-bill-wave'],
        'advance_collection'=> ['label' => 'Advance',         'url' => 'cr/advance_payment_collection.php','icon' => 'fa-hand-holding-usd'],
        'credit_limits'    => ['label' => 'Credit Limits',    'url' => 'cr/customer_credit_management.php','icon' => 'fa-credit-card'],
    ];
?>

    <nav class="bg-white shadow-lg border-b border-gray-200" x-data="{ mobileMenuOpen: false }">
        <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">

                <!-- ═══════════════════════════════════════════
                     LEFT: LOGO + DESKTOP NAV
                ════════════════════════════════════════════ -->
                <div class="flex">

                    <!-- Logo -->
                    <div class="flex-shrink-0 flex items-center">
                        <a href="<?php echo url('index.php'); ?>" class="flex items-center">
                            <i class="fas fa-layer-group text-primary-600 text-2xl mr-2"></i>
                            <span class="font-bold text-xl text-gray-900"><?php echo APP_NAME; ?></span>
                        </a>
                    </div>

                    <!-- Desktop Menu -->
                    <div class="hidden md:ml-6 md:flex md:space-x-1 md:items-center">

                        <!-- Dashboard -->
                        <a href="<?php echo url('index.php'); ?>"
                           class="text-gray-600 hover:text-primary-600 inline-flex items-center px-2 pt-1 text-sm font-medium h-full transition-colors">
                            Dashboard
                        </a>

                        <!-- ── CREDIT SALES ── -->
                        <?php if (in_array($user_role, $credit_sales_roles) && !$is_expense_only && !$is_bank_only): ?>

                            <?php if ($show_flat_menu): ?>
                                <!-- Flat nav items for focused roles (Sales, Production, Dispatch, Collector) -->
                                <?php foreach ($credit_menu_items as $key => $item):
                                    if (canAccessCreditMenu($key, $user_role, $credit_menu_permissions)): ?>
                                    <a href="<?php echo url($item['url']); ?>"
                                       class="text-gray-600 hover:text-primary-600 inline-flex items-center px-2 pt-1 text-sm font-medium h-full transition-colors">
                                        <i class="fas <?php echo $item['icon']; ?> mr-1 text-xs"></i>
                                        <?php echo $item['label']; ?>
                                    </a>
                                    <?php endif;
                                endforeach; ?>

                            <?php elseif ($show_dropdown_menu): ?>
                                <!-- Dropdown for Admin / Accounts -->
                                <div class="relative h-full flex items-center" x-data="{ open: false }">
                                    <button @click="open = !open"
                                            class="text-gray-600 hover:text-primary-600 inline-flex items-center px-2 pt-1 text-sm font-medium h-full transition-colors">
                                        Credit Sales <i class="fas fa-chevron-down text-[10px] ml-1 opacity-50"></i>
                                    </button>
                                    <div x-show="open" @click.away="open = false" x-transition
                                         class="absolute left-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50 top-full">
                                        <div class="py-1">
                                            <?php foreach ($credit_menu_items as $key => $item):
                                                if (canAccessCreditMenu($key, $user_role, $credit_menu_permissions)): ?>
                                                <a href="<?php echo url($item['url']); ?>"
                                                   class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600 transition-colors">
                                                    <i class="fas <?php echo $item['icon']; ?> mr-2 text-gray-400 w-4"></i>
                                                    <?php echo $item['label']; ?>
                                                </a>
                                                <?php endif;
                                            endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                        <?php endif; ?>
                        <!-- END CREDIT SALES -->

                        <!-- ── CUSTOMERS ── (not for expense-only or bank-only roles) -->
                        <?php if (!$is_expense_only && !$is_bank_only): ?>
                        <a href="<?php echo url('customers/index.php'); ?>"
                           class="text-gray-600 hover:text-primary-600 inline-flex items-center px-2 pt-1 text-sm font-medium h-full transition-colors">
                            Customers
                        </a>
                        <?php endif; ?>

                        <!-- ── PRODUCTS ── (not for expense-only or bank-only roles) -->
                        <?php if (!$is_expense_only && !$is_bank_only): ?>
                        <div class="relative h-full flex items-center" x-data="{ open: false }">
                            <button @click="open = !open"
                                    class="text-gray-600 hover:text-primary-600 inline-flex items-center px-2 pt-1 text-sm font-medium h-full transition-colors">
                                Products <i class="fas fa-chevron-down text-[10px] ml-1 opacity-50"></i>
                            </button>
                            <div x-show="open" @click.away="open = false" x-transition
                                 class="absolute left-0 mt-2 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50 top-full">
                                <div class="py-1">
                                    <a href="<?php echo url('product/products.php'); ?>"      class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Overview</a>
                                    <a href="<?php echo url('product/base_products.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Base Products</a>
                                    <a href="<?php echo url('product/pricing.php'); ?>"       class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Pricing</a>
                                    <a href="<?php echo url('product/inventory.php'); ?>"     class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Inventory</a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- ── BANK ── -->
                        <?php if (in_array($user_role, $bank_roles)): ?>
                        <div class="relative h-full flex items-center" x-data="{ open: false }">
                            <button @click="open = !open"
                                    class="text-gray-600 hover:text-primary-600 inline-flex items-center px-2 pt-1 text-sm font-medium h-full transition-colors">
                                <i class="fas fa-university mr-1 text-xs text-primary-500"></i>
                                Bank <i class="fas fa-chevron-down text-[10px] ml-1 opacity-50"></i>
                            </button>
                            <div x-show="open" @click.away="open = false" x-transition
                                 class="absolute left-0 mt-2 w-52 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50 top-full">
                                <div class="py-1">

                                    <a href="<?php echo url('bank/index.php'); ?>"
                                       class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-primary-600 transition-colors">
                                        <i class="fas fa-tachometer-alt mr-2 text-primary-500 w-4"></i>Dashboard
                                    </a>

                                    <?php if (in_array($user_role, $bank_create_roles)): ?>
                                    <a href="<?php echo url('bank/create_transaction.php'); ?>"
                                       class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-green-600 transition-colors">
                                        <i class="fas fa-plus mr-2 text-green-500 w-4"></i>New Transaction
                                    </a>
                                    <?php endif; ?>

                                    <a href="<?php echo url('bank/transfer.php'); ?>"
                                       class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-blue-600 transition-colors">
                                        <i class="fas fa-exchange-alt mr-2 text-blue-500 w-4"></i>Bank to Bank Transfer
                                    </a>

                                    <a href="<?php echo url('bank/statement.php'); ?>"
                                       class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-indigo-600 transition-colors">
                                        <i class="fas fa-file-alt mr-2 text-indigo-400 w-4"></i>Account Statement
                                    </a>

                                    <?php if (in_array($user_role, $bank_admin_roles)): ?>
                                    <div class="border-t border-gray-100 my-1"></div>

                                    <a href="<?php echo url('bank/manage_accounts.php'); ?>"
                                       class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-blue-600 transition-colors">
                                        <i class="fas fa-piggy-bank mr-2 text-blue-500 w-4"></i>Bank Accounts
                                    </a>

                                    <a href="<?php echo url('bank/manage_types.php'); ?>"
                                       class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-purple-600 transition-colors">
                                        <i class="fas fa-tags mr-2 text-purple-500 w-4"></i>Transaction Types
                                    </a>

                                    <div class="border-t border-gray-100 my-1"></div>

                                    <a href="<?php echo url('bank/bulk_manage.php'); ?>"
                                       class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                                        <i class="fas fa-layer-group mr-2 text-red-500 w-4"></i>Bulk Manage
                                    </a>
                                    <?php endif; ?>

                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <!-- END BANK -->

                        <!-- ── ACCOUNTS ── -->
                        <?php if (in_array($user_role, $accounts_roles) && !$is_expense_only && !$is_bank_only): ?>
                        <div class="relative h-full flex items-center" x-data="{ open: false }">
                            <button @click="open = !open"
                                    class="text-gray-600 hover:text-primary-600 inline-flex items-center px-2 pt-1 text-sm font-medium h-full transition-colors">
                                Accounts <i class="fas fa-chevron-down text-[10px] ml-1 opacity-50"></i>
                            </button>
                            <div x-show="open" @click.away="open = false" x-transition
                                 class="absolute left-0 mt-2 w-52 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50 top-full">
                                <div class="py-1">
                                    <a href="<?php echo url('accounts/chart_of_accounts.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-sitemap mr-2 text-gray-400 w-4"></i>Chart of Accounts
                                    </a>
                                    <a href="<?php echo url('accounts/new_transaction.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-plus-circle mr-2 text-gray-400 w-4"></i>New Transaction
                                    </a>
                                    <a href="<?php echo url('accounts/internal_transfer.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-exchange-alt mr-2 text-gray-400 w-4"></i>Internal Transfer
                                    </a>
                                    <a href="<?php echo url('accounts/debit_voucher.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-receipt mr-2 text-gray-400 w-4"></i>Debit Voucher
                                    </a>
                                    <a href="<?php echo url('accounts/bank_accounts.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-university mr-2 text-gray-400 w-4"></i>Bank Accounts
                                    </a>
                                    <a href="<?php echo url('accounts/all_accounts.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-list mr-2 text-gray-400 w-4"></i>All Statements
                                    </a>
                                    <a href="<?php echo url('accounts/daily_log.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-calendar-day mr-2 text-gray-400 w-4"></i>Daily Log
                                    </a>
                                    <?php if (in_array($user_role, $admin_roles)): ?>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <a href="<?php echo url('admin/balance_sheet.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-balance-scale mr-2 text-gray-400 w-4"></i>Balance Sheet
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <!-- END ACCOUNTS -->

                        <!-- ── PURCHASE ── -->
                        <?php if (in_array($user_role, $purchase_roles) && !$is_expense_only && !$is_bank_only): ?>
                        <div class="relative h-full flex items-center" x-data="{ open: false }">
                            <button @click="open = !open"
                                    class="text-gray-600 hover:text-primary-600 inline-flex items-center px-2 pt-1 text-sm font-medium h-full transition-colors">
                                Purchase <i class="fas fa-chevron-down text-[10px] ml-1 opacity-50"></i>
                            </button>
                            <div x-show="open" @click.away="open = false" x-transition
                                 class="absolute left-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50 top-full">
                                <div class="py-1">
                                    <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-tachometer-alt mr-2 text-gray-400 w-4"></i>Dashboard
                                    </a>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <div class="px-4 py-1"><span class="text-xs font-semibold text-gray-400 uppercase">Suppliers</span></div>
                                    <a href="<?php echo url('purchase/purchase_adnan_supplier_summary.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-users mr-2 text-gray-400 w-4"></i>All Suppliers
                                    </a>
                                    <?php if (in_array($user_role, $accounts_roles)): ?>
                                    <a href="<?php echo url('purchase/supplier_form.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-user-plus mr-2 text-gray-400 w-4"></i>Add Supplier
                                    </a>
                                    <?php endif; ?>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <div class="px-4 py-1"><span class="text-xs font-semibold text-gray-400 uppercase">Purchase Orders</span></div>
                                    <a href="<?php echo url('purchase/all_po.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-file-invoice mr-2 text-gray-400 w-4"></i>All POs
                                    </a>
                                    <?php if (in_array($user_role, $accounts_roles)): ?>
                                    <a href="<?php echo url('purchase/purchase_adnan_create_po.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-plus-circle mr-2 text-blue-500 w-4"></i>Create PO
                                    </a>
                                    <?php endif; ?>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <div class="px-4 py-1"><span class="text-xs font-semibold text-gray-400 uppercase">Goods Received</span></div>
                                    <a href="<?php echo url('purchase/goods_received.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-clipboard-check mr-2 text-gray-400 w-4"></i>All GRNs
                                    </a>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <div class="px-4 py-1"><span class="text-xs font-semibold text-gray-400 uppercase">Payments</span></div>
                                    <a href="<?php echo url('purchase/payments.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-money-bill-wave mr-2 text-gray-400 w-4"></i>All Payments
                                    </a>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <a href="<?php echo url('purchase/reports.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-chart-bar mr-2 text-purple-400 w-4"></i>Reports
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <!-- END PURCHASE -->

                        <!-- ── EXPENSE ── -->
                        <?php if (in_array($user_role, $expense_roles)): ?>
                        <div class="relative h-full flex items-center" x-data="{ open: false }">
                            <button @click="open = !open"
                                    class="text-gray-600 hover:text-primary-600 inline-flex items-center px-2 pt-1 text-sm font-medium h-full transition-colors">
                                Expense <i class="fas fa-chevron-down text-[10px] ml-1 opacity-50"></i>
                            </button>
                            <div x-show="open" @click.away="open = false" x-transition
                                 class="absolute left-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50 top-full">
                                <div class="py-1">
                                    <?php if (in_array($user_role, $expense_category_roles)): ?>
                                    <a href="<?php echo url('expense/expense_categories.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-tags mr-2 text-gray-400 w-4"></i>Expense Categories
                                    </a>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <?php endif; ?>
                                    <?php if (in_array($user_role, $expense_create_roles)): ?>
                                    <a href="<?php echo url('expense/create_expense.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-plus-circle mr-2 text-gray-400 w-4"></i>Create Expense Voucher
                                    </a>
                                    <?php endif; ?>
                                    <?php if (in_array($user_role, $expense_approver_roles)): ?>
                                    <a href="<?php echo url('expense/approve_expense.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-check-circle mr-2 text-gray-400 w-4"></i>Approve Expense Voucher
                                    </a>
                                    <?php endif; ?>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <a href="<?php echo url('expense/expense_history.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-history mr-2 text-gray-400 w-4"></i>Expense History
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <!-- END EXPENSE -->

                        <!-- ── ADMIN ── -->
                        <?php if (in_array($user_role, $admin_roles)): ?>
                        <div class="relative h-full flex items-center" x-data="{ open: false }">
                            <button @click="open = !open"
                                    class="text-gray-600 hover:text-primary-600 inline-flex items-center px-2 pt-1 text-sm font-medium h-full transition-colors">
                                Admin <i class="fas fa-chevron-down text-[10px] ml-1 opacity-50"></i>
                            </button>
                            <div x-show="open" @click.away="open = false" x-transition
                                 class="absolute left-0 mt-2 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50 top-full">
                                <div class="py-1">
                                    <a href="<?php echo url('admin/users.php'); ?>"         class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-users mr-2 text-gray-400 w-4"></i>Users</a>
                                    <a href="<?php echo url('admin/employees.php'); ?>"     class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-id-badge mr-2 text-gray-400 w-4"></i>Employees</a>
                                    <a href="<?php echo url('admin/user_activity.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-history mr-2 text-gray-400 w-4"></i>Audit Trail</a>
                                    <a href="<?php echo url('admin/settings.php'); ?>"      class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-cog mr-2 text-gray-400 w-4"></i>Settings</a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <!-- END ADMIN -->

                    </div>
                </div><!-- end left flex -->

                <!-- ═══════════════════════════════════════════
                     RIGHT: USER PROFILE DROPDOWN
                ════════════════════════════════════════════ -->
                <div class="hidden md:flex md:items-center">
                    <div class="ml-3 relative" x-data="{ open: false }">
                        <button @click="open = !open"
                                class="flex text-sm rounded-full focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <div class="h-8 w-8 rounded-full bg-primary-500 flex items-center justify-center">
                                <span class="text-white font-medium text-sm">
                                    <?php echo strtoupper(substr($currentUser['display_name'] ?? 'U', 0, 1)); ?>
                                </span>
                            </div>
                        </button>
                        <div x-show="open" @click.away="open = false" x-transition
                             class="absolute right-0 mt-2 w-52 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50">
                            <div class="py-1">
                                <div class="px-4 py-2 text-sm text-gray-900 border-b">
                                    <div class="font-medium"><?php echo htmlspecialchars($currentUser['display_name'] ?? 'User'); ?></div>
                                    <div class="text-xs text-gray-500 mt-0.5"><?php echo htmlspecialchars($user_role); ?></div>
                                </div>
                                <?php if (in_array($user_role, $admin_roles)): ?>
                                <a href="<?php echo url('admin/settings.php'); ?>"
                                   class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-cog mr-2 text-gray-400"></i>Settings
                                </a>
                                <?php endif; ?>
                                <a href="<?php echo url('auth/logout.php'); ?>"
                                   class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-sign-out-alt mr-2 text-gray-400"></i>Sign out
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
                            class="p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100">
                        <i class="fas fa-bars text-lg"  x-show="!mobileMenuOpen"></i>
                        <i class="fas fa-times text-lg" x-show="mobileMenuOpen"  x-cloak></i>
                    </button>
                </div>

            </div><!-- end flex justify-between -->

            <!-- ═══════════════════════════════════════════════
                 MOBILE MENU
            ════════════════════════════════════════════════ -->
            <div x-show="mobileMenuOpen" x-cloak class="md:hidden border-t border-gray-200 pb-3">

                <!-- Dashboard -->
                <div class="pt-2">
                    <a href="<?php echo url('index.php'); ?>"
                       class="block pl-3 pr-4 py-2 text-base font-medium text-gray-700 hover:bg-gray-50">
                        <i class="fas fa-home mr-2 text-gray-400"></i>Dashboard
                    </a>
                </div>

                <!-- ── MOBILE: CREDIT SALES ── -->
                <?php if (in_array($user_role, $credit_sales_roles) && !$is_expense_only && !$is_bank_only): ?>
                <div class="pt-2 border-t border-gray-100 mt-1">
                    <div class="px-3 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Credit Sales</div>
                    <?php foreach ($credit_menu_items as $key => $item):
                        if (canAccessCreditMenu($key, $user_role, $credit_menu_permissions)): ?>
                    <a href="<?php echo url($item['url']); ?>"
                       class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                        <i class="fas <?php echo $item['icon']; ?> mr-2 text-gray-400 w-4"></i>
                        <?php echo $item['label']; ?>
                    </a>
                    <?php endif; endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- ── MOBILE: CUSTOMERS ── -->
                <?php if (!$is_expense_only && !$is_bank_only): ?>
                <div class="pt-2 border-t border-gray-100 mt-1">
                    <div class="px-3 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Customers</div>
                    <a href="<?php echo url('customers/index.php'); ?>"
                       class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                        <i class="fas fa-users mr-2 text-gray-400 w-4"></i>All Customers
                    </a>
                </div>
                <?php endif; ?>

                <!-- ── MOBILE: PRODUCTS ── -->
                <?php if (!$is_expense_only && !$is_bank_only): ?>
                <div class="pt-2 border-t border-gray-100 mt-1">
                    <div class="px-3 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Products</div>
                    <a href="<?php echo url('product/products.php'); ?>"      class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-box mr-2 text-gray-400 w-4"></i>Overview</a>
                    <a href="<?php echo url('product/base_products.php'); ?>" class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-cube mr-2 text-gray-400 w-4"></i>Base Products</a>
                    <a href="<?php echo url('product/pricing.php'); ?>"       class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-tags mr-2 text-gray-400 w-4"></i>Pricing</a>
                    <a href="<?php echo url('product/inventory.php'); ?>"     class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-warehouse mr-2 text-gray-400 w-4"></i>Inventory</a>
                </div>
                <?php endif; ?>

                <!-- ── MOBILE: BANK ── -->
                <?php if (in_array($user_role, $bank_roles)): ?>
                <div class="pt-2 border-t border-gray-100 mt-1">
                    <div class="px-3 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Bank</div>
                    <a href="<?php echo url('bank/index.php'); ?>"
                       class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                        <i class="fas fa-tachometer-alt mr-2 text-gray-400 w-4"></i>Dashboard
                    </a>
                    <?php if (in_array($user_role, $bank_create_roles)): ?>
                    <a href="<?php echo url('bank/create_transaction.php'); ?>"
                       class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                        <i class="fas fa-plus mr-2 text-gray-400 w-4"></i>New Transaction
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo url('bank/transfer.php'); ?>"
                       class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                        <i class="fas fa-exchange-alt mr-2 text-gray-400 w-4"></i>Bank to Bank Transfer
                    </a>
                    <a href="<?php echo url('bank/statement.php'); ?>"
                       class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                        <i class="fas fa-file-alt mr-2 text-gray-400 w-4"></i>Account Statement
                    </a>
                    <?php if (in_array($user_role, $bank_admin_roles)): ?>
                    <a href="<?php echo url('bank/manage_accounts.php'); ?>"
                       class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                        <i class="fas fa-piggy-bank mr-2 text-gray-400 w-4"></i>Bank Accounts
                    </a>
                    <a href="<?php echo url('bank/manage_types.php'); ?>"
                       class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                        <i class="fas fa-tags mr-2 text-gray-400 w-4"></i>Transaction Types
                    </a>
                    <a href="<?php echo url('bank/bulk_manage.php'); ?>"
                       class="flex items-center pl-6 pr-4 py-2 text-sm text-red-600 hover:bg-red-50">
                        <i class="fas fa-layer-group mr-2 text-red-400 w-4"></i>Bulk Manage
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- ── MOBILE: ACCOUNTS ── -->
                <?php if (in_array($user_role, $accounts_roles) && !$is_expense_only && !$is_bank_only): ?>
                <div class="pt-2 border-t border-gray-100 mt-1">
                    <div class="px-3 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Accounts</div>
                    <a href="<?php echo url('accounts/chart_of_accounts.php'); ?>" class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-sitemap mr-2 text-gray-400 w-4"></i>Chart of Accounts</a>
                    <a href="<?php echo url('accounts/new_transaction.php'); ?>"   class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-plus-circle mr-2 text-gray-400 w-4"></i>New Transaction</a>
                    <a href="<?php echo url('accounts/internal_transfer.php'); ?>" class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-exchange-alt mr-2 text-gray-400 w-4"></i>Internal Transfer</a>
                    <a href="<?php echo url('accounts/debit_voucher.php'); ?>"     class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-receipt mr-2 text-gray-400 w-4"></i>Debit Voucher</a>
                    <a href="<?php echo url('accounts/bank_accounts.php'); ?>"     class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-university mr-2 text-gray-400 w-4"></i>Bank Accounts</a>
                    <a href="<?php echo url('accounts/all_accounts.php'); ?>"      class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-list mr-2 text-gray-400 w-4"></i>All Statements</a>
                    <a href="<?php echo url('accounts/daily_log.php'); ?>"         class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-calendar-day mr-2 text-gray-400 w-4"></i>Daily Log</a>
                    <?php if (in_array($user_role, $admin_roles)): ?>
                    <a href="<?php echo url('admin/balance_sheet.php'); ?>"        class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-balance-scale mr-2 text-gray-400 w-4"></i>Balance Sheet</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- ── MOBILE: PURCHASE ── -->
                <?php if (in_array($user_role, $purchase_roles) && !$is_expense_only && !$is_bank_only): ?>
                <div class="pt-2 border-t border-gray-100 mt-1">
                    <div class="px-3 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Purchase</div>
                    <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>"          class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-tachometer-alt mr-2 text-gray-400 w-4"></i>Dashboard</a>
                    <a href="<?php echo url('purchase/purchase_adnan_supplier_summary.php'); ?>" class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-users mr-2 text-gray-400 w-4"></i>All Suppliers</a>
                    <a href="<?php echo url('purchase/all_po.php'); ?>"                        class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-file-invoice mr-2 text-gray-400 w-4"></i>All POs</a>
                    <?php if (in_array($user_role, $accounts_roles)): ?>
                    <a href="<?php echo url('purchase/purchase_adnan_create_po.php'); ?>"      class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-plus-circle mr-2 text-blue-400 w-4"></i>Create PO</a>
                    <?php endif; ?>
                    <a href="<?php echo url('purchase/goods_received.php'); ?>"                class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-clipboard-check mr-2 text-gray-400 w-4"></i>All GRNs</a>
                    <a href="<?php echo url('purchase/payments.php'); ?>"                      class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-money-bill-wave mr-2 text-gray-400 w-4"></i>All Payments</a>
                    <a href="<?php echo url('purchase/reports.php'); ?>"                       class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-chart-bar mr-2 text-purple-400 w-4"></i>Reports</a>
                </div>
                <?php endif; ?>

                <!-- ── MOBILE: EXPENSE ── -->
                <?php if (in_array($user_role, $expense_roles)): ?>
                <div class="pt-2 border-t border-gray-100 mt-1">
                    <div class="px-3 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Expense</div>
                    <?php if (in_array($user_role, $expense_category_roles)): ?>
                    <a href="<?php echo url('expense/expense_categories.php'); ?>" class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-tags mr-2 text-gray-400 w-4"></i>Expense Categories</a>
                    <?php endif; ?>
                    <?php if (in_array($user_role, $expense_create_roles)): ?>
                    <a href="<?php echo url('expense/create_expense.php'); ?>"     class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-plus-circle mr-2 text-gray-400 w-4"></i>Create Expense Voucher</a>
                    <?php endif; ?>
                    <?php if (in_array($user_role, $expense_approver_roles)): ?>
                    <a href="<?php echo url('expense/approve_expense.php'); ?>"    class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-check-circle mr-2 text-gray-400 w-4"></i>Approve Expense Voucher</a>
                    <?php endif; ?>
                    <a href="<?php echo url('expense/expense_history.php'); ?>"    class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-history mr-2 text-gray-400 w-4"></i>Expense History</a>
                </div>
                <?php endif; ?>

                <!-- ── MOBILE: ADMIN ── -->
                <?php if (in_array($user_role, $admin_roles)): ?>
                <div class="pt-2 border-t border-gray-100 mt-1">
                    <div class="px-3 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Admin</div>
                    <a href="<?php echo url('admin/users.php'); ?>"         class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-users mr-2 text-gray-400 w-4"></i>Users</a>
                    <a href="<?php echo url('admin/employees.php'); ?>"     class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-id-badge mr-2 text-gray-400 w-4"></i>Employees</a>
                    <a href="<?php echo url('admin/user_activity.php'); ?>" class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-history mr-2 text-gray-400 w-4"></i>Audit Trail</a>
                    <a href="<?php echo url('admin/settings.php'); ?>"      class="flex items-center pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-cog mr-2 text-gray-400 w-4"></i>Settings</a>
                </div>
                <?php endif; ?>

                <!-- ── MOBILE: USER SECTION ── -->
                <div class="pt-3 pb-1 border-t border-gray-200 mt-2">
                    <div class="flex items-center px-4">
                        <div class="flex-shrink-0 h-9 w-9 rounded-full bg-primary-500 flex items-center justify-center">
                            <span class="text-white font-medium text-sm">
                                <?php echo strtoupper(substr($currentUser['display_name'] ?? 'U', 0, 1)); ?>
                            </span>
                        </div>
                        <div class="ml-3">
                            <div class="text-sm font-medium text-gray-800"><?php echo htmlspecialchars($currentUser['display_name'] ?? 'User'); ?></div>
                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($user_role); ?></div>
                        </div>
                    </div>
                    <div class="mt-2 space-y-1">
                        <?php if (in_array($user_role, $admin_roles)): ?>
                        <a href="<?php echo url('admin/settings.php'); ?>"
                           class="block px-4 py-2 text-sm font-medium text-gray-500 hover:bg-gray-100">
                            <i class="fas fa-cog mr-2"></i>Settings
                        </a>
                        <?php endif; ?>
                        <a href="<?php echo url('auth/logout.php'); ?>"
                           class="block px-4 py-2 text-sm font-medium text-gray-500 hover:bg-gray-100">
                            <i class="fas fa-sign-out-alt mr-2"></i>Sign out
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

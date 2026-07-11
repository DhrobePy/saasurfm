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
    $user_role = $currentUser['role'] ?? '';
    
    // Define role groups
    $admin_roles = ['Superadmin', 'admin'];
    $accounts_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg'];
    $credit_sales_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg', 'dispatch-demra', 'dispatch-srg', 'production manager-srg', 'production manager-demra', 'sales-srg', 'sales-demra', 'collector', 'sales-other'];
    $pos_roles = ['Superadmin', 'admin', 'accountspos-demra', 'accountspos-srg', 'dispatchpos-demra', 'dispatchpos-srg'];
    $logistics_roles = ['Superadmin', 'admin', 'Accounts', 'Transport Manager', 'dispatch-demra', 'dispatch-srg', 'dispatchpos-demra', 'dispatchpos-srg'];
    $purchase_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg', 'production manager-srg', 'production manager-demra'];
    $expense_roles = ['Superadmin', 'admin', 'Accounts', 'Expense Initiator', 'Expense Approver'];
    $expense_category_roles = ['Superadmin', 'admin', 'Accounts']; 
    $expense_approver_roles = ['Superadmin', 'admin', 'Accounts', 'Expense Approver']; 
    $bank_roles = ['Superadmin', 'admin', 'Bank Transaction initiator', 'Bank Transaction Approver'];
    $is_expense_only = in_array($user_role, ['Expense Initiator', 'Expense Approver']);
    
    // Credit Sales Menu Permissions Matrix
$credit_menu_permissions = [
    'dashboard' => [
        'Superadmin', 'admin', 'Accounts', 
        'accounts-demra', 'accounts-srg', 
        'sales-srg', 'sales-demra', 'sales-other', 
        'production manager-srg', 'production manager-demra', 
        'dispatch-demra', 'dispatch-srg', 'collector'
    ],
    'create_order' => [
        'Superadmin', 'admin', 
        'sales-srg', 'sales-demra', 'sales-other'
    ],
    'approve_orders' => [
        'Superadmin', 'admin', 'Accounts', 
        'accounts-demra', 'accounts-srg'
    ],
    'production_queue' => [
        'Superadmin', 'admin', 
        'production manager-srg', 'production manager-demra'
    ],
    'track_status' => [
        'Superadmin', 'admin', 'Accounts', 
        'accounts-demra', 'accounts-srg', 
        'sales-srg', 'sales-demra', 'sales-other', 
        'production manager-srg', 'production manager-demra', 
        'dispatch-demra', 'dispatch-srg'
    ],
    'dispatch' => [
        'Superadmin', 'admin', 
        'dispatch-demra', 'dispatch-srg'
    ],
    'customer_ledger' => [
        'Superadmin', 'admin', 'Accounts', 
        'accounts-demra', 'accounts-srg', 'collector'
    ],
    'collect_payment' => [
        'Superadmin', 'admin', 'Accounts', 
        'accounts-demra', 'accounts-srg', 'collector'
    ],
    'advance_collection' => [
        'Superadmin', 'admin', 'Accounts', 
        'accounts-demra', 'accounts-srg', 'collector'
    ],
    'credit_limits' => [
        'Superadmin', 'admin', 'Accounts', 
        'accounts-demra', 'accounts-srg'
    ],
];

// Helper function
function canAccessCreditMenu($menu_item, $user_role, $permissions) {
    return isset($permissions[$menu_item]) && in_array($user_role, $permissions[$menu_item]);
}

// Define which roles should see FLAT menus (individual buttons)
$flat_menu_roles = [
    'sales-srg',
    'sales-demra', 
    'sales-other',
    'production manager-srg',
    'production manager-demra',
    'dispatch-demra',
    'dispatch-srg',
    'collector'
];

// Define which roles should see DROPDOWN menu (many items)
$dropdown_menu_roles = [
    'Superadmin',
    'admin',
    'Accounts',
    'accounts-demra',
    'accounts-srg'
];

// Check if current user should see flat or dropdown
$show_flat_menu = in_array($user_role, $flat_menu_roles);
$show_dropdown_menu = in_array($user_role, $dropdown_menu_roles);

// Define menu items with labels and icons
$credit_menu_items = [
    'dashboard' => [
        'label' => 'Credit Dashboard',
        'url' => 'cr/index.php',
        'icon' => 'fa-chart-line'
    ],
    'create_order' => [
        'label' => 'Create Order',
        'url' => 'cr/create_order.php',
        'icon' => 'fa-plus-circle'
    ],
    'approve_orders' => [
        'label' => 'Approve Orders',
        'url' => 'cr/credit_order_approval.php',
        'icon' => 'fa-check-circle'
    ],
    'production_queue' => [
        'label' => 'Production',
        'url' => 'cr/credit_production.php',
        'icon' => 'fa-industry'
    ],
    'track_status' => [
        'label' => 'Track Orders',
        'url' => 'cr/order_status.php',
        'icon' => 'fa-truck'
    ],
    'dispatch' => [
        'label' => 'Dispatch',
        'url' => 'cr/credit_dispatch.php',
        'icon' => 'fa-shipping-fast'
    ],
    'customer_ledger' => [
        'label' => 'Ledger',
        'url' => 'cr/customer_ledger.php',
        'icon' => 'fa-book'
    ],
    'collect_payment' => [
        'label' => 'Collect Payment',
        'url' => 'cr/credit_payment_collect.php',
        'icon' => 'fa-money-bill-wave'
    ],
    'advance_collection' => [
        'label' => 'Advance',
        'url' => 'cr/advance_payment_collection.php',
        'icon' => 'fa-hand-holding-usd'
    ],
    'credit_limits' => [
        'label' => 'Credit Limits',
        'url' => 'cr/customer_credit_management.php',
        'icon' => 'fa-credit-card'
    ],
];
    

    ?>

    <nav class="bg-white shadow-lg border-b border-gray-200" x-data="{ mobileMenuOpen: false }">
        <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">

                <!-- Left: Logo & Navigation -->
                <div class="flex">
                    <!-- Logo -->
                    <div class="flex-shrink-0 flex items-center">
                        <a href="<?php echo url('index.php'); ?>" class="flex items-center">
                            <i class="fas fa-layer-group text-primary-600 text-2xl mr-2"></i>
                            <span class="font-bold text-xl text-gray-900"><?php echo APP_NAME; ?></span>
                        </a>
                    </div>

                    <!-- Desktop Menu -->
                    <div class="hidden md:ml-6 md:flex md:space-x-4">

                        <!-- Dashboard -->
                        <a href="<?php echo url('index.php'); ?>" class="text-gray-600 hover:text-primary-600 inline-flex items-center px-1 pt-1 text-sm font-medium">
                            Dashboard
                        </a>

                        <!-- Credit Sales -->
                        
                        <!-- Credit Sales Menu - Flat or Dropdown based on role -->
                        <?php if (in_array($user_role, $credit_sales_roles) && !$is_expense_only): ?>
                        
                            <?php if ($show_flat_menu): ?>
                                <!-- FLAT MENU for Sales, Production, Dispatch, Collector -->
                                <?php foreach ($credit_menu_items as $key => $item):
                                    if (canAccessCreditMenu($key, $user_role, $credit_menu_permissions)): ?>
                                        <a href="<?php echo url($item['url']); ?>" 
                                           class="text-gray-600 hover:text-primary-600 inline-flex items-center px-1 pt-1 text-sm font-medium">
                                            <i class="fas <?php echo $item['icon']; ?> mr-1 text-xs"></i>
                                            <?php echo $item['label']; ?>
                                        </a>
                                    <?php endif;
                                endforeach; ?>
                                
                            <?php elseif ($show_dropdown_menu): ?>
                                <!-- DROPDOWN MENU for Admin, Accounts -->
                                <div class="relative" x-data="{ open: false }">
                                    <button @click="open = !open" 
                                            class="text-gray-600 hover:text-primary-600 inline-flex items-center px-1 pt-1 text-sm font-medium h-full">
                                        Credit Sales <i class="fas fa-chevron-down text-xs ml-1"></i>
                                    </button>
                                    <div x-show="open" @click.away="open = false" x-transition 
                                         class="absolute left-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50">
                                        <div class="py-1">
                                            <?php foreach ($credit_menu_items as $key => $item):
                                                if (canAccessCreditMenu($key, $user_role, $credit_menu_permissions)): ?>
                                                    <a href="<?php echo url($item['url']); ?>" 
                                                       class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                                        <i class="fas <?php echo $item['icon']; ?> mr-2 text-gray-400"></i>
                                                        <?php echo $item['label']; ?>
                                                    </a>
                                                <?php endif;
                                            endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                
                            <?php endif; ?>
                            
                        <?php endif; ?>
                        

                        <!-- POS -->
                        <?php if (in_array($user_role, $pos_roles) && !$is_expense_only): ?>
                        <!--<div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" class="text-gray-600 hover:text-primary-600 inline-flex items-center px-1 pt-1 text-sm font-medium h-full">
                                POS <i class="fas fa-chevron-down text-xs ml-1"></i>
                            </button>
                            <div x-show="open" @click.away="open = false" x-transition class="absolute left-0 mt-2 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50">
                                <div class="py-1">
                                    <a href="<?php echo url('pos/index.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">POS Terminal</a>
                                    <a href="<?php echo url('pos/todays_sales.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Today's Sales</a>
                                    <a href="<?php echo url('pos/cash_verification.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Cash in Hand</a>
                                    <a href="<?php echo url('pos/eod.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">End of Day</a>
                                </div>
                            </div>
                        </div>-->
                        <?php endif; ?>

                        <!-- Logistics -->
                        <?php if (in_array($user_role, $logistics_roles) && !$is_expense_only): ?>
                        <!--<div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" class="text-gray-600 hover:text-primary-600 inline-flex items-center px-1 pt-1 text-sm font-medium h-full">
                                Logistics <i class="fas fa-chevron-down text-xs ml-1"></i>
                            </button>
                            <div x-show="open" @click.away="open = false" x-transition class="absolute left-0 mt-2 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50">
                                <div class="py-1">
                                    <a href="<?php echo url('logistics/'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Dashboard</a>
                                    <a href="<?php echo url('logistics/vehicles/'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Vehicles</a>
                                    <a href="<?php echo url('logistics/drivers/'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Drivers</a>
                                    <a href="<?php echo url('logistics/trips/'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Trips</a>
                                    <a href="<?php echo url('logistics/fuel/'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Fuel Logs</a>
                                    <a href="<?php echo url('logistics/maintenance/'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Maintenance</a>
                                    <a href="<?php echo url('logistics/rentals/');?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Rent Out</a>
                                </div>
                            </div>
                        </div>-->
                        <?php endif; ?>

                        

                        <!-- Customers -->
                        <?php if (!$is_expense_only): ?>
                        <a href="<?php echo url('customers/index.php'); ?>" class="text-gray-600 hover:text-primary-600 inline-flex items-center px-1 pt-1 text-sm font-medium">
                            Customers
                        </a>
                        <?php endif; ?>
                        
                        <!-- Products -->
                        <?php if (!$is_expense_only): ?>
                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" class="text-gray-600 hover:text-primary-600 inline-flex items-center px-1 pt-1 text-sm font-medium h-full">
                                Products <i class="fas fa-chevron-down text-xs ml-1"></i>
                            </button>
                            <div x-show="open" @click.away="open = false" x-transition class="absolute left-0 mt-2 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50">
                                <div class="py-1">
                                    <a href="<?php echo url('product/products.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Overview</a>
                                    <a href="<?php echo url('product/base_products.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Base Products</a>
                                    <a href="<?php echo url('product/pricing.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Pricing</a>
                                    <a href="<?php echo url('product/inventory.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Inventory</a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Bank Module (add this in the desktop nav section) -->
                        <?php if (in_array($user_role, $bank_roles)): ?>
                        <div class="relative h-full flex items-center" x-data="{ open: false }">
                            <button @click="open = !open"
                                    class="text-gray-600 hover:text-primary-600 inline-flex items-center px-1 pt-1 text-sm font-medium h-full transition-colors duration-200">
                                <i class="fas fa-university mr-1 text-xs text-primary-500"></i> Bank <i class="fas fa-chevron-down text-[10px] ml-1 opacity-50"></i>
                            </button>
                            
                            <!-- Dropdown Menu -->
                            <div x-show="open" 
                                 @click.away="open = false" 
                                 x-transition:enter="transition ease-out duration-100"
                                 x-transition:enter-start="transform opacity-0 scale-95"
                                 x-transition:enter-end="transform opacity-100 scale-100"
                                 class="absolute left-0 mt-2 w-52 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50 top-full">
                                <div class="py-1">
                                    <a href="<?php echo url('bank/index.php'); ?>"
                                       class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-primary-600 transition-colors">
                                        <i class="fas fa-tachometer-alt mr-2 text-primary-500 w-4"></i>Dashboard
                                    </a>
                                    
                                    <a href="<?php echo url('bank/create_transaction.php'); ?>"
                                       class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-green-600 transition-colors">
                                        <i class="fas fa-plus mr-2 text-green-500 w-4"></i>New Transaction
                                    </a>
                                    
                                    <a href="<?php echo url('bank/transfer.php'); ?>"
                                       class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-green-600 transition-colors">
                                        <i class="fas fa-plus mr-2 text-green-500 w-4"></i>Bank to Bank Transfer
                                    </a>

                                    <?php if (in_array($user_role, ['Superadmin', 'admin'])): ?>
                                        <div class="border-t border-gray-100 my-1"></div>
                                        
                                        <a href="<?php echo url('bank/manage_accounts.php'); ?>"
                                           class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-blue-600 transition-colors">
                                            <i class="fas fa-piggy-bank mr-2 text-blue-500 w-4"></i>Bank Accounts
                                        </a>
                                        
                                        <a href="<?php echo url('bank/manage_types.php'); ?>"
                                           class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-purple-600 transition-colors">
                                            <i class="fas fa-tags mr-2 text-purple-500 w-4"></i>Transaction Types
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>


                        <!-- Accounts -->
                        <?php if (in_array($user_role, $accounts_roles) && !$is_expense_only): ?>
                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" class="text-gray-600 hover:text-primary-600 inline-flex items-center px-1 pt-1 text-sm font-medium h-full">
                                Accounts <i class="fas fa-chevron-down text-xs ml-1"></i>
                            </button>
                            <div x-show="open" @click.away="open = false" x-transition class="absolute left-0 mt-2 w-52 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50">
                                <div class="py-1">
                                    <a href="<?php echo url('accounts/chart_of_accounts.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Chart of Accounts</a>
                                    <a href="<?php echo url('accounts/new_transaction.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">New Transaction</a>
                                    <a href="<?php echo url('accounts/internal_transfer.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Internal Transfer</a>
                                    <a href="<?php echo url('accounts/debit_voucher.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Debit Voucher</a>
                                    <a href="<?php echo url('accounts/bank_accounts.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Bank Accounts</a>
                                    <a href="<?php echo url('accounts/all_accounts.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">All Statements</a>
                                    <a href="<?php echo url('admin/balance_sheet.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Balance Sheet</a>
                                    <a href="<?php echo url('accounts/daily_log.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Daily Log</a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                       <!---- Purchase Module----->
                        
                        <!-- Purchase Module -->
                    <?php if (in_array($user_role, $accounts_roles) && !$is_expense_only): ?>
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="text-gray-600 hover:text-primary-600 inline-flex items-center px-1 pt-1 text-sm font-medium h-full">
                            Purchase <i class="fas fa-chevron-down text-xs ml-1"></i>
                        </button>
                        <div x-show="open" @click.away="open = false" x-transition class="absolute left-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50">
                            <div class="py-1">
                                <!----<a href="<?php echo url('purchase/index.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-tachometer-alt w-5 text-gray-400"></i> Dashboard
                                </a>----->
                                <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-tachometer-alt w-5 text-gray-400"></i> Dashboard
                                </a>
                                <!----<a href="<?php echo url('modules/wheat_shipment_dashboard.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">
                                    <i class="fas fa-ship text-primary-600 mr-2"></i>
                                    <span class="font-medium">Shipment Info</span>
                                    <span class="text-xs text-gray-500 block ml-6">Bangladesh Wheat Imports</span>
                                </a>---->
                                <div class="border-t border-gray-100 my-1"></div>
                                <div class="px-4 py-1">
                                    <span class="text-xs font-semibold text-gray-400 uppercase">Suppliers</span>
                                </div>
                                <a href="<?php echo url('purchase/purchase_adnan_supplier_summary.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-users w-5 text-gray-400"></i> All Suppliers
                                </a>
                                <a href="<?php echo url('purchase/supplier_form.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-user-plus w-5 text-gray-400"></i> Add Supplier
                                </a>
                                <div class="border-t border-gray-100 my-1"></div>
                                <div class="px-4 py-1">
                                    <span class="text-xs font-semibold text-gray-400 uppercase">Purchase Orders</span>
                                </div>
                                <a href="<?php echo url('purchase/all_po.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-file-invoice w-5 text-gray-400"></i> All POs
                                </a>
                                <a href="<?php echo url('purchase/purchase_adnan_create_po.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-plus-circle w-5 text-blue-500"></i> Create PO
                                </a>
                                <!----<a href="<?php echo url('purchase/purchase_orders.php?status=pending_approval'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-clock w-5 text-orange-400"></i> Pending Approval
                                </a>---->
                                <div class="border-t border-gray-100 my-1"></div>
                                <div class="px-4 py-1">
                                    <span class="text-xs font-semibold text-gray-400 uppercase">Goods Received</span>
                                </div>
                                <a href="<?php echo url('purchase/goods_received.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-clipboard-check w-5 text-gray-400"></i> All GRNs
                                </a>
                               <!------ <a href="<?php echo url('purchase/create_grn.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-truck-loading w-5 text-green-500"></i> Receive Goods
                               
                                <div class="border-t border-gray-100 my-1"></div>
                                <div class="px-4 py-1">
                                    <span class="text-xs font-semibold text-gray-400 uppercase">Invoices</span>
                                </div>
                                <a href="<?php echo url('purchase/invoices.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-file-invoice-dollar w-5 text-gray-400"></i> All Invoices
                                </a>
                                <a href="<?php echo url('purchase/create_invoice.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-plus-circle w-5 text-blue-500"></i> Create Invoice
                                </a>
                                <a href="<?php echo url('purchase/invoices.php?payment_status=unpaid'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-exclamation-circle w-5 text-red-400"></i> Unpaid Invoices
                                </a>
                                
                                 </a>----->
                                <div class="border-t border-gray-100 my-1"></div>
                                <div class="px-4 py-1">
                                    <span class="text-xs font-semibold text-gray-400 uppercase">Payments</span>
                                </div>
                                <a href="<?php echo url('purchase/payments.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-money-bill-wave w-5 text-gray-400"></i> All Payments
                                </a>
                                <!----<a href="<?php echo url('purchase/create_payment.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-hand-holding-usd w-5 text-green-500"></i> Make Payment
                                </a>---->
                                <div class="border-t border-gray-100 my-1"></div>
                                <a href="<?php echo url('purchase/reports.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-chart-bar w-5 text-purple-400"></i> Reports
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                        
                        
                        
                                <!-- Shipment Info - Market Intelligence -->
                                
                                
                              
                                
                                

                        <!-- Expense -->
                        <?php if (in_array($user_role, $expense_roles)): ?>
                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" class="text-gray-600 hover:text-primary-600 inline-flex items-center px-1 pt-1 text-sm font-medium h-full">
                                Expense <i class="fas fa-chevron-down text-xs ml-1"></i>
                            </button>
                            <div x-show="open" @click.away="open = false" x-transition class="absolute left-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50">
                                <div class="py-1">
                                    <?php if (in_array($user_role, $expense_category_roles)): ?>
                                    <a href="<?php echo url('expense/expense_categories.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-tags mr-2 text-gray-500"></i>Expense Categories
                                    </a>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <?php endif; ?>
                                    <a href="<?php echo url('expense/create_expense.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-plus-circle mr-2 text-gray-500"></i>Create Expense Voucher
                                    </a>
                                    <?php if (in_array($user_role, $expense_approver_roles)): ?>
                                    <a href="<?php echo url('expense/approve_expense.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-check-circle mr-2 text-gray-500"></i>Approve Expense Voucher
                                    </a>
                                    <?php endif; ?>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <a href="<?php echo url('expense/expense_history.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i class="fas fa-history mr-2 text-gray-500"></i>Expense History
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Admin -->
                        <?php if (in_array($user_role, $admin_roles) && !$is_expense_only): ?>
                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" class="text-gray-600 hover:text-primary-600 inline-flex items-center px-1 pt-1 text-sm font-medium h-full">
                                Admin <i class="fas fa-chevron-down text-xs ml-1"></i>
                            </button>
                            <div x-show="open" @click.away="open = false" x-transition class="absolute left-0 mt-2 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50">
                                <div class="py-1">
                                    <a href="<?php echo url('admin/users.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Users</a>
                                    <a href="<?php echo url('admin/employees.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Employees</a>
                                    <a href="<?php echo url('admin/user_activity.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Audit Trail</a>
                                    <a href="<?php echo url('admin/settings.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Settings</a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>

                <!-- Right: User Profile -->
                <div class="hidden md:flex md:items-center">
                    <div class="ml-3 relative" x-data="{ open: false }">
                        <button @click="open = !open" class="flex text-sm rounded-full focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <div class="h-8 w-8 rounded-full bg-primary-500 flex items-center justify-center">
                                <span class="text-white font-medium text-sm"><?php echo strtoupper(substr($currentUser['display_name'] ?? 'U', 0, 1)); ?></span>
                            </div>
                        </button>
                        <div x-show="open" @click.away="open = false" x-transition class="absolute right-0 mt-2 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50">
                            <div class="py-1">
                                <div class="px-4 py-2 text-sm text-gray-900 border-b">
                                    <div class="font-medium"><?php echo htmlspecialchars($currentUser['display_name'] ?? 'User'); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($currentUser['role'] ?? ''); ?></div>
                                </div>
                                <a href="<?php echo url('admin/settings.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Settings</a>
                                <a href="<?php echo url('auth/logout.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-sign-out-alt mr-2"></i>Sign out
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Mobile menu button -->
                <div class="flex items-center md:hidden">
                    <button @click="mobileMenuOpen = !mobileMenuOpen" class="p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100">
                        <i class="fas fa-bars text-lg" x-show="!mobileMenuOpen"></i>
                        <i class="fas fa-times text-lg" x-show="mobileMenuOpen" x-cloak></i>
                    </button>
                </div>

            </div>

            <!-- Mobile Menu -->
            <div x-show="mobileMenuOpen" x-cloak class="md:hidden border-t border-gray-200">
                <div class="pt-2 pb-3 space-y-1">
                    <a href="<?php echo url('index.php'); ?>" class="block pl-3 pr-4 py-2 text-base font-medium text-gray-700 hover:bg-gray-50">Dashboard</a>
                    
                    <?php if (in_array($user_role, $credit_sales_roles) && !$is_expense_only): ?>
                    <div class="px-3 py-1 text-xs font-semibold text-gray-500 uppercase">Credit Sales</div>
                    <a href="<?php echo url('cr/create_order.php'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Create Order</a>
                    <a href="<?php echo url('cr/credit_dispatch.php'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Dispatch</a>
                    <?php endif; ?>
                    
                    <?php if (in_array($user_role, $pos_roles) && !$is_expense_only): ?>
                    <div class="px-3 py-1 text-xs font-semibold text-gray-500 uppercase">POS</div>
                    <a href="<?php echo url('pos/index.php'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">POS Terminal</a>
                    <?php endif; ?>
                    
                    <?php if (in_array($user_role, $logistics_roles) && !$is_expense_only): ?>
                    <div class="px-3 py-1 text-xs font-semibold text-gray-500 uppercase">Logistics</div>
                    <a href="<?php echo url('logistics/vehicles/'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Vehicles</a>
                    <a href="<?php echo url('logistics/drivers/'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Drivers</a>
                    <?php endif; ?>
                    
                    <?php if (in_array($user_role, $expense_roles)): ?>
                    <div class="px-3 py-1 text-xs font-semibold text-gray-500 uppercase">Expense</div>
                    <?php if (in_array($user_role, $expense_category_roles)): ?>
                    <a href="<?php echo url('expense/expense_categories.php'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                        <i class="fas fa-tags mr-2"></i>Expense Categories
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo url('expense/create_expense.php'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                        <i class="fas fa-plus-circle mr-2"></i>Create Expense Voucher
                    </a>
                    <?php if (in_array($user_role, $expense_approver_roles)): ?>
                    <a href="<?php echo url('expense/approve_expense.php'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                        <i class="fas fa-check-circle mr-2"></i>Approve Expense Voucher
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo url('expense/expense_history.php'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                        <i class="fas fa-history mr-2"></i>Expense History
                    </a>
                    <?php endif; ?>
                    
                    <?php if (!$is_expense_only): ?>
                    <div class="px-3 py-1 text-xs font-semibold text-gray-500 uppercase">Other</div>
                    <a href="<?php echo url('customers/index.php'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Customers</a>
                    <a href="<?php echo url('product/products.php'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Products</a>
                    <?php endif; ?>
                </div>
                
                <!-- Mobile User Section -->
                <div class="pt-4 pb-3 border-t border-gray-200">
                    <div class="flex items-center px-4">
                        <div class="flex-shrink-0 h-10 w-10 rounded-full bg-primary-500 flex items-center justify-center">
                            <span class="text-white font-medium"><?php echo strtoupper(substr($currentUser['display_name'] ?? 'U', 0, 1)); ?></span>
                        </div>
                        <div class="ml-3">
                            <div class="text-base font-medium text-gray-800"><?php echo htmlspecialchars($currentUser['display_name'] ?? 'User'); ?></div>
                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($currentUser['role'] ?? ''); ?></div>
                        </div>
                    </div>
                    <div class="mt-3 space-y-1">
                        <a href="<?php echo url('admin/settings.php'); ?>" class="block px-4 py-2 text-base font-medium text-gray-500 hover:bg-gray-100">Settings</a>
                        <a href="<?php echo url('auth/logout.php'); ?>" class="block px-4 py-2 text-base font-medium text-gray-500 hover:bg-gray-100">Sign out</a>
                    </div>
                </div>
            </div>

        </div>
    </nav>

<?php endif; ?>

<!-- Main Content -->
<main class="py-6 lg:py-8 flex-grow">
    <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8">
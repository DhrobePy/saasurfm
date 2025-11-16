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
    ?>

    <nav class="bg-white shadow-lg border-b border-gray-200" x-data="{ mobileMenuOpen: false }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
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
                        <?php if (in_array($user_role, $credit_sales_roles)): ?>
                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" class="text-gray-600 hover:text-primary-600 inline-flex items-center px-1 pt-1 text-sm font-medium h-full">
                                Credit Sales <i class="fas fa-chevron-down text-xs ml-1"></i>
                            </button>
                            <div x-show="open" @click.away="open = false" x-transition class="absolute left-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50">
                                <div class="py-1">
                                    <a href="<?php echo url('cr/index.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Dashboard</a>
                                    <a href="<?php echo url('cr/create_order.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Create Order</a>
                                    <a href="<?php echo url('cr/credit_order_approval.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Approve Orders</a>
                                    <a href="<?php echo url('cr/credit_production.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Production Queue</a>
                                    <a href="<?php echo url('cr/order_status.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Track Status</a>
                                    <a href="<?php echo url('cr/credit_dispatch.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Dispatch</a>
                                    <a href="<?php echo url('cr/customer_ledger.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Customer Ledger</a>
                                    <a href="<?php echo url('cr/credit_payment_collect.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Collect Payment</a>
                                    <a href="<?php echo url('cr/customer_credit_management.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Credit Limits</a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- POS -->
                        <?php if (in_array($user_role, $pos_roles)): ?>
                        <div class="relative" x-data="{ open: false }">
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
                        </div>
                        <?php endif; ?>

                        <!-- Logistics -->
                        <?php if (in_array($user_role, $logistics_roles)): ?>
                        <div class="relative" x-data="{ open: false }">
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
                        </div>
                        <?php endif; ?>

                        <!-- Products -->
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

                        <!-- Customers -->
                        <a href="<?php echo url('customers/index.php'); ?>" class="text-gray-600 hover:text-primary-600 inline-flex items-center px-1 pt-1 text-sm font-medium">
                            Customers
                        </a>

                        <!-- Accounts -->
                        <?php if (in_array($user_role, $accounts_roles)): ?>
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
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Admin -->
                        <?php if (in_array($user_role, $admin_roles)): ?>
                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" class="text-gray-600 hover:text-primary-600 inline-flex items-center px-1 pt-1 text-sm font-medium h-full">
                                Admin <i class="fas fa-chevron-down text-xs ml-1"></i>
                            </button>
                            <div x-show="open" @click.away="open = false" x-transition class="absolute left-0 mt-2 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50">
                                <div class="py-1">
                                    <a href="<?php echo url('admin/users.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Users</a>
                                    <a href="<?php echo url('admin/employees.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Employees</a>
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
                    
                    <?php if (in_array($user_role, $credit_sales_roles)): ?>
                    <div class="px-3 py-1 text-xs font-semibold text-gray-500 uppercase">Credit Sales</div>
                    <a href="<?php echo url('cr/create_order.php'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Create Order</a>
                    <a href="<?php echo url('cr/credit_dispatch.php'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Dispatch</a>
                    <?php endif; ?>
                    
                    <?php if (in_array($user_role, $pos_roles)): ?>
                    <div class="px-3 py-1 text-xs font-semibold text-gray-500 uppercase">POS</div>
                    <a href="<?php echo url('pos/index.php'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">POS Terminal</a>
                    <?php endif; ?>
                    
                    <?php if (in_array($user_role, $logistics_roles)): ?>
                    <div class="px-3 py-1 text-xs font-semibold text-gray-500 uppercase">Logistics</div>
                    <a href="<?php echo url('logistics/vehicles/'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Vehicles</a>
                    <a href="<?php echo url('logistics/drivers/'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Drivers</a>
                    <?php endif; ?>
                    
                    <div class="px-3 py-1 text-xs font-semibold text-gray-500 uppercase">Other</div>
                    <a href="<?php echo url('customers/index.php'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Customers</a>
                    <a href="<?php echo url('product/products.php'); ?>" class="block pl-6 pr-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Products</a>
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
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
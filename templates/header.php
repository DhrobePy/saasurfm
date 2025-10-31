<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? APP_NAME; ?></title>

    <script src="https://cdn.tailwindcss.com"></script> <!-- Keep as is for now -->

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Use the (now rectified) asset helper -->
    <link rel="stylesheet" href="<?php echo asset('css/style.css'); ?>">

    <!-- *** RECTIFIED: Added defer attribute *** -->
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<meta name="csrf-token" content="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $pageTitle ?? APP_NAME; ?></title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<link rel="stylesheet" href="<?php echo asset('css/style.css'); ?>">
<script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>


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
     <!-- Use the (now rectified) asset helper -->
     <!-- *** RECTIFIED: Added defer attribute *** -->
     <script src="<?php echo asset('js/app.js'); ?>"></script>

</head>
<body class="bg-gray-50 font-sans min-h-screen flex flex-col">

    <?php if (isLoggedIn()): ?>
        <?php $currentUser = getCurrentUser(); // Get user data once ?>

        <!-- Outer nav has the full-width background and border -->
        <nav class="bg-white shadow-lg border-b border-gray-200" x-data="{ mobileMenuOpen: false }">
            <!-- Inner container centers the content (max-w-7xl) -->
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Flex container handles layout within the centered div -->
                <div class="flex justify-between h-16">

                    <!-- Left Section: Logo & Links -->
                    <div class="flex">
                        <div class="flex-shrink-0 flex items-center">
                            <a href="<?php echo url('index.php'); // Link to main router ?>" class="flex items-center">
                                <i class="fas fa-layer-group text-primary-600 text-2xl mr-2"></i> <!-- Changed icon -->
                                <span class="font-bold text-xl text-gray-900"><?php echo defined('APP_NAME') ? APP_NAME : 'ERP'; // Use App Name Constant ?></span>
                            </a>
                        </div>

                        <!-- Desktop Navigation Menu -->
                        <div class="hidden md:ml-6 md:flex md:space-x-8">

                            <!-- 1. Dashboard -->
                            <a href="<?php echo url('index.php'); ?>" class="border-transparent text-gray-500 hover:text-primary-600 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">Dashboard</a>


                            <!-- Credit Sales Dropdown (Conditional) -->
                            <?php
                            $cr_allowed_roles = [
                                'Superadmin', 'admin', 'Accounts','accounts-demra', 'accounts-srg',
                                'dispatch-demra', 'dispatch-srg', 'production manager-srg', 'production manager-demra', 'sales-srg', 'sales-demra', 'collector', 'sales-other',
                            ];
                            if ($currentUser && in_array($currentUser['role'], $cr_allowed_roles)):
                            ?>
                                <div class="relative" x-data="{ open: false }">
                                    <button @click="open = !open" class="border-transparent text-gray-500 hover:text-primary-600 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium h-full">
                                        <span>Credit Sales</span>
                                        <i class="fas fa-cash-register text-xs ml-1"></i>
                                    </button>
                                    <div x-show="open" @click.away="open = false" x-transition class="origin-top-left absolute left-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-10">
                                        <div class="py-1">
                                            <a href="<?php echo url('cr/index.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600"> <i class="fas fa-cash-register w-4 mr-2 text-gray-400"></i>Order Dashboard </a>
                                            <a href="<?php echo url('cr/create_order.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600"> <i class="fas fa-receipt w-4 mr-2 text-gray-400"></i>Create Order</a>
                                            
                                            <a href="<?php echo url('cr/credit_order_approval.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600"> <i class="fas fa-receipt w-4 mr-2 text-gray-400"></i>Approve orders</a>
                                            
                                            <a href="<?php echo url('cr/customer_ledger.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600"> <i class="fas fa-receipt w-4 mr-2 text-gray-400"></i>Customer Ledger</a>
                                            
                                            <a href="<?php echo url('cr/customer_credit_management.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600"> <i class="fas fa-receipt w-4 mr-2 text-gray-400"></i>Credit Limits</a>
                                            
                                            <a href="<?php echo url('cr/credit_payment_collect.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600"> <i class="fas fa-receipt w-4 mr-2 text-gray-400"></i>Collect Payment</a>
                                            
                                            <a href="<?php echo url('cr/cr_invoice.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600"> <i class="fas fa-receipt w-4 mr-2 text-gray-400"></i>Create Invoice </a>
                                            
                                            <a href="<?php echo url('cr/credit_production.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600"> <i class="fas fa-calendar-day w-4 mr-2 text-gray-400"></i>Production Que </a>
                                            
                                            <a href="<?php echo url('cr/order_status.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600"> <i class="fas fa-calendar-day w-4 mr-2 text-gray-400"></i>Track Order Status </a>
                                            
                                            <a href="<?php echo url('cr/credit_dispatch.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600"> <i class="fas fa-calendar-day w-4 mr-2 text-gray-400"></i>Dispatch </a>
                                            
                                            <a href="<?php echo url('cr/order_status.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600"> <i class="fas fa-chart-bar w-4 mr-2 text-gray-400"></i>Credit Sales Reports </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>


                             <!-- User Management Dropdown (Conditional) -->
                            <?php
                            $admin_roles = ['Superadmin', 'admin']; // Define admin roles
                            if ($currentUser && in_array($currentUser['role'], $admin_roles)):
                            ?>
                                <div class="relative" x-data="{ open: false }">
                                    <button @click="open = !open" class="border-transparent text-gray-500 hover:text-primary-600 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium h-full">
                                        <span>User Management</span>
                                        <i class="fas fa-chevron-down text-xs ml-1"></i>
                                    </button>
                                    <div x-show="open" @click.away="open = false" x-transition class="origin-top-left absolute left-0 mt-2 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-10">
                                        <div class="py-1">
                                            <a href="<?php echo url('admin/users.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Manage Users</a>
                                            <a href="<?php echo url('admin/employees.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Manage Employees</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Product Dropdown -->
                            <div class="relative" x-data="{ open: false }">
                                <button @click="open = !open" class="border-transparent text-gray-500 hover:text-primary-600 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium h-full">
                                    <span>Product</span>
                                    <i class="fas fa-chevron-down text-xs ml-1"></i>
                                </button>
                                <div x-show="open" @click.away="open = false" x-transition class="origin-top-left absolute left-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-10">
                                    <div class="py-1">
                                        <a href="<?php echo url('product/products.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Products Overview</a>
                                        <a href="<?php echo url('product/base_products.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Manage Base Products</a>
                                         <a href="<?php echo url('product/pricing.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Manage Pricing</a>
                                         <a href="<?php echo url('product/inventory.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Manage Stock</a>
                                    </div>
                                </div>
                            </div>

                            <!-- Customers -->
                            <a href="<?php echo url('customers/index.php'); ?>" class="border-transparent text-gray-500 hover:text-primary-600 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">Customers</a>

                            <!-- POS Dropdown (Conditional) -->
                            <?php
                            $pos_allowed_roles = [
                                'Superadmin', 'admin', 'accountspos-demra', 'accountspos-srg',
                                'dispatchpos-demra', 'dispatchpos-srg'
                            ];
                            if ($currentUser && in_array($currentUser['role'], $pos_allowed_roles)):
                            ?>
                                <div class="relative" x-data="{ open: false }">
                                    <button @click="open = !open" class="border-transparent text-gray-500 hover:text-primary-600 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium h-full">
                                        <span>POS</span>
                                        <i class="fas fa-cash-register text-xs ml-1"></i>
                                    </button>
                                    <div x-show="open" @click.away="open = false" x-transition class="origin-top-left absolute left-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-10">
                                        <div class="py-1">
                                            <a href="<?php echo url('pos/index.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600"> <i class="fas fa-cash-register w-4 mr-2 text-gray-400"></i>POS Terminal </a>
                                            <a href="<?php echo url('pos/todays_sales.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600"> <i class="fas fa-receipt w-4 mr-2 text-gray-400"></i>Today's Sales (POS) </a>
                                            <a href="<?php echo url('pos/cash_verification.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600"> <i class="fas fa-receipt w-4 mr-2 text-gray-400"></i>Check Cash in Hand </a>
                                            
                                            <a href="<?php echo url('pos/eod.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600"> <i class="fas fa-calendar-day w-4 mr-2 text-gray-400"></i>End of Day Summary </a>
                                            <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600"> <i class="fas fa-chart-bar w-4 mr-2 text-gray-400"></i>POS Reports </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Orders Dropdown -->
                            <div class="relative" x-data="{ open: false }">
                                <button @click="open = !open" class="border-transparent text-gray-500 hover:text-primary-600 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium h-full">
                                    <span>Orders</span> <i class="fas fa-chevron-down text-xs ml-1"></i>
                                </button>
                                <div x-show="open" @click.away="open = false" x-transition class="origin-top-left absolute left-0 mt-2 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-10">
                                    <div class="py-1"> <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Pending Orders</a> <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Orders in Process</a> <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Dispatched</a> <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Delivered</a> <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Report</a> </div>
                                </div>
                            </div>

                            <!-- Balance Sheet Dropdown -->
                            <div class="relative" x-data="{ open: false }">
                                <button @click="open = !open" class="border-transparent text-gray-500 hover:text-primary-600 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium h-full">
                                    <span>Balance Sheet</span> <i class="fas fa-chevron-down text-xs ml-1"></i>
                                </button>
                                <div x-show="open" @click.away="open = false" x-transition class="origin-top-left absolute left-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-10">
                                    <div class="py-1"> 
                                    <a href="<?php echo url('admin/balance_sheet.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Balance Sheet</a> 
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Receivables List</a> 
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Payables List</a>
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Bank Reconciliation</a> 
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Reports</a> </div>
                                </div>
                            </div>

                             <!-- Accounts Dropdown -->
                            <div class="relative" x-data="{ open: false }">
                                <button @click="open = !open" class="border-transparent text-gray-500 hover:text-primary-600 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium h-full">
                                    <span>Accounts</span> <i class="fas fa-chevron-down text-xs ml-1"></i>
                                </button>
                                <div x-show="open" @click.away="open = false" x-transition class="origin-top-left absolute left-0 mt-2 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-10">
                                    <div class="py-1"> <a href="<?php echo url('accounts/chart_of_accounts.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Chart of Accounts</a> <a href="<?php echo url('accounts/internal_transfer.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Internal Transfer</a> <a href="<?php echo url('accounts/new_transaction.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">New Transaction</a> <a href="<?php echo url('accounts/bank_accounts.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-primary-600">Bank Accounts</a> </div>
                                </div>
                            </div>

                            <!-- Reports -->
                            <a href="#" class="border-transparent text-gray-500 hover:text-primary-600 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">Reports</a>

                        </div>
                    </div>

                    <!-- Right Section: Profile Dropdown & Mobile Button -->
                    <div class="hidden md:ml-6 md:flex md:items-center">
                        <!-- Profile dropdown -->
                        <div class="ml-3 relative" x-data="{ open: false }">
                            <div>
                                <button @click="open = !open" type="button" class="bg-white flex text-sm rounded-full focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500" id="user-menu-button" aria-expanded="false" aria-haspopup="true">
                                    <span class="sr-only">Open user menu</span>
                                    <div class="h-8 w-8 rounded-full bg-primary-500 flex items-center justify-center ring-1 ring-primary-600 ring-offset-1">
                                        <span class="text-white font-medium text-sm"><?php echo strtoupper(substr($currentUser['display_name'] ?? 'U', 0, 1)); ?></span>
                                    </div>
                                </button>
                            </div>
                            <div x-show="open" @click.away="open = false" x-transition:enter="transition ease-out duration-100" x-transition:enter-start="transform opacity-0 scale-95" x-transition:enter-end="transform opacity-100 scale-100" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="transform opacity-100 scale-100" x-transition:leave-end="transform opacity-0 scale-95"
                                 class="origin-top-right absolute right-0 mt-2 w-48 rounded-md shadow-lg py-1 bg-white ring-1 ring-black ring-opacity-5 focus:outline-none z-10" role="menu" aria-orientation="vertical" aria-labelledby="user-menu-button" tabindex="-1">
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem" tabindex="-1">Your Profile</a>
                                <a href="<?php echo url('admin/settings.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem" tabindex="-1">Settings</a>
                                <a href="<?php echo url('auth/logout.php'); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem" tabindex="-1">
                                     <i class="fas fa-sign-out-alt mr-2 text-gray-400"></i>Sign out
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Mobile menu button -->
                    <div class="-mr-2 flex items-center md:hidden">
                        <button @click="mobileMenuOpen = !mobileMenuOpen" type="button" class="bg-white inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary-500" aria-controls="mobile-menu" aria-expanded="false">
                            <span class="sr-only">Open main menu</span>
                            <i class="fas fa-bars text-lg" x-show="!mobileMenuOpen"></i>
                            <i class="fas fa-times text-lg" x-show="mobileMenuOpen" x-cloak></i>
                        </button>
                    </div>

                </div> <!-- End flex justify-between -->
            </div> <!-- End max-w-7xl -->

            <!-- Mobile menu, show/hide based on menu state. -->
            <div class="md:hidden" id="mobile-menu" x-show="mobileMenuOpen" x-cloak>
                 <div class="pt-2 pb-3 space-y-1 px-2">
                    <a href="<?php echo url('index.php'); ?>" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Dashboard</a>

                     <!-- User Management Section (Conditional) -->
                     <?php if ($currentUser && in_array($currentUser['role'], $admin_roles)): ?>
                        <span class="block px-3 pt-4 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">User Management</span>
                        <a href="<?php echo url('admin/users.php'); ?>" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Manage Users</a>
                        <a href="<?php echo url('admin/employees.php'); ?>" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Manage Employees</a>
                    <?php endif; ?>

                    <!-- Product Section -->
                    <span class="block px-3 pt-4 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Product</span>
                    <a href="<?php echo url('product/products.php'); ?>" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Products Overview</a>
                    <a href="<?php echo url('product/base_products.php'); ?>" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Manage Base Products</a>

                     <!-- Customers -->
                     <span class="block px-3 pt-4 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Sales</span>
                    <a href="<?php echo url('customers/index.php'); ?>" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Customers</a>

                     <!-- POS Section (Conditional) -->
                     <?php if ($currentUser && in_array($currentUser['role'], $pos_allowed_roles)): ?>
                        <span class="block px-3 pt-4 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Point of Sale</span>
                        <a href="<?php echo url('pos/index.php'); ?>" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">POS Terminal</a>
                        <a href="#" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Today's Sales (POS)</a>
                        <a href="#" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">End of Day Summary</a>
                        <a href="#" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">POS Reports</a>
                     <?php endif; ?>

                    <!-- Orders Section -->
                    <span class="block px-3 pt-4 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Orders</span>
                    <a href="#" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Pending Orders</a>
                    <a href="#" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Orders in Process</a>
                    <a href="#" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Dispatched</a>
                    <a href="#" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Delivered</a>
                    <a href="#" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Report</a>

                    <!-- Accounting Section -->
                     <span class="block px-3 pt-4 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Accounting</span>
                     <a href="<?php echo url('accounts/bank_accounts.php'); ?>" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Bank Accounts</a>
                     <a href="<?php echo url('accounts/new_transaction.php'); ?>" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">New Transaction</a>
                     <a href="<?php echo url('accounts/internal_transfer.php'); ?>" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Internal Transfer</a>
                     <a href="#" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Receivables List</a>
                     <a href="#" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Payables List</a>
                     <a href="#" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Bank Reconciliation</a>
                     <a href="<?php echo url('accounts/chart_of_accounts.php'); ?>" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Chart of Accounts</a>

                     <!-- Reports -->
                     <span class="block px-3 pt-4 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Reports</span>
                     <a href="#" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Overall Report</a>

                </div>
                <!-- Mobile User Section -->
                 <div class="pt-4 pb-3 border-t border-gray-200 px-2">
                    <div class="flex items-center px-3">
                        <div class="flex-shrink-0 h-10 w-10 rounded-full bg-primary-500 flex items-center justify-center ring-1 ring-primary-600 ring-offset-1">
                             <span class="text-white font-medium text-base"><?php echo strtoupper(substr($currentUser['display_name'] ?? 'U', 0, 1)); ?></span>
                        </div>
                        <div class="ml-3">
                            <div class="text-base font-medium text-gray-800"><?php echo htmlspecialchars($currentUser['display_name'] ?? 'User'); ?></div>
                            <div class="text-sm font-medium text-gray-500"><?php echo htmlspecialchars($currentUser['email'] ?? ''); ?></div>
                        </div>
                        <!-- Optional: Add notification bell here if needed -->
                    </div>
                    <div class="mt-3 space-y-1">
                        <a href="#" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Your Profile</a>
                        <a href="#" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">Settings</a>
                        <a href="<?php echo url('auth/logout.php'); ?>" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">
                             <i class="fas fa-sign-out-alt mr-2 text-gray-400"></i>Sign out
                        </a>
                    </div>
                </div>
            </div> <!-- End Mobile Menu -->

        </nav> <!-- End Outer Nav -->

    <?php endif; ?>

    <!-- ======================================== -->
    <!-- MAIN CONTENT AREA -->
    <!-- ======================================== -->
    <main class="py-6 lg:py-8 flex-grow <?php echo ($is_pos_interface ?? false) ? 'pos-main-content' : ''; ?>"> <!-- Add class for POS styling if needed -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <?php // Flash messages are now displayed by header/display_message() ?>

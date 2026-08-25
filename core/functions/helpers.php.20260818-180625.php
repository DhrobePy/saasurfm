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
 * TELEGRAM MULTI-GROUP ROUTING (Jul 2026)
 * ============================================================================
 * Notifications used to all go to one TELEGRAM_CHAT_ID. Add named per-category
 * constants to core/config/config.php (next to the existing TELEGRAM_BOT_TOKEN/
 * TELEGRAM_CHAT_ID lines) as each Telegram group is created — nothing here
 * requires all 9 to exist at once. Any category without its own constant
 * falls back to the original general TELEGRAM_CHAT_ID, so notifications never
 * silently stop firing while groups are still being set up.
 *
 * Add to config.php, one line per group as you create it:
 *   define('TELEGRAM_CHAT_ID_ORDERS',           '-100XXXXXXXXXX'); // Daily order group
 *   define('TELEGRAM_CHAT_ID_PRODUCTION',       '-100XXXXXXXXXX'); // Production group
 *   define('TELEGRAM_CHAT_ID_PAYMENT_RECEIVED', '-100XXXXXXXXXX'); // Payment received group (money IN)
 *   define('TELEGRAM_CHAT_ID_DISPATCH',         '-100XXXXXXXXXX'); // Dispatch/delivery group
 *   define('TELEGRAM_CHAT_ID_PURCHASE',         '-100XXXXXXXXXX'); // Purchase group
 *   define('TELEGRAM_CHAT_ID_PAYMENT',          '-100XXXXXXXXXX'); // Payment group (money OUT)
 *   define('TELEGRAM_CHAT_ID_GOODS_RECEIVED',   '-100XXXXXXXXXX'); // Goods received (GRN) group
 *   define('TELEGRAM_CHAT_ID_BANK_APPROVED',    '-100XXXXXXXXXX'); // Bank approved group
 *   define('TELEGRAM_CHAT_ID_EXPENSE',          '-100XXXXXXXXXX'); // Expense initiator group
 */
function getTelegramChatId(string $category): ?string {
    if (!defined('TELEGRAM_BOT_TOKEN')) return null;
    $map = [
        'orders'           => 'TELEGRAM_CHAT_ID_ORDERS',
        'production'       => 'TELEGRAM_CHAT_ID_PRODUCTION',
        'payment_received' => 'TELEGRAM_CHAT_ID_PAYMENT_RECEIVED',
        'dispatch'         => 'TELEGRAM_CHAT_ID_DISPATCH',
        'purchase'         => 'TELEGRAM_CHAT_ID_PURCHASE',
        'payment'          => 'TELEGRAM_CHAT_ID_PAYMENT',
        'goods_received'   => 'TELEGRAM_CHAT_ID_GOODS_RECEIVED',
        'bank_approved'    => 'TELEGRAM_CHAT_ID_BANK_APPROVED',
        'expense'          => 'TELEGRAM_CHAT_ID_EXPENSE',
        'ai_query'         => 'TELEGRAM_CHAT_ID_AI_QUERY',
    ];
    $const = $map[$category] ?? null;
    if ($const && defined($const) && (string)constant($const) !== '') {
        return (string)constant($const);
    }
    return defined('TELEGRAM_CHAT_ID') ? (string)TELEGRAM_CHAT_ID : null;
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
            'folder'       => 'cr',
            'label'        => 'Credit Sales',
            'icon'         => 'fa-chart-line',
            'color'        => 'blue',
            // active_files: pages that should highlight THIS module (not Production)
            'active_files' => [
                'sales_dashboard', 'index', 'all_sales', 'create_order',
                'credit_order_approval', 'customer_payment', 'payment_history', 'returns',
                'advance_payment_collection', 'bank_statement', 'order_status',
                'payment_watch', 'over_delivery', 'sales_hub', 'order_amendment',
                'approval_requests', 'stock_adjustment', 'outstanding_invoices', 'qr_scan_log',
            ],
            'nav' => [
                ['file' => 'sales_hub',             'page_key' => 'sales_hub',             'label' => 'Sales Hub',       'icon' => 'fa-route'],
                ['file' => 'sales_dashboard',       'page_key' => 'sales_dashboard',       'label' => 'Sales Dashboard', 'icon' => 'fa-tachometer-alt'],
                ['file' => 'index',                 'page_key' => 'index',                 'label' => 'Credit Dashboard','icon' => 'fa-chart-line', 'hidden' => true],
                ['file' => 'all_sales',             'page_key' => 'all_sales',             'label' => 'All Sales',       'icon' => 'fa-list-alt'],
                ['file' => 'create_order',          'page_key' => 'create_order',          'label' => 'Create Order',    'icon' => 'fa-plus-circle'],
                ['file' => 'backdated_order',       'page_key' => 'backdated_order',       'label' => 'Backdated Order (Reconciliation)', 'icon' => 'fa-clock-rotate-left', 'admin_only' => true],
                ['file' => 'credit_order_approval', 'page_key' => 'credit_order_approval', 'label' => 'Approve Orders',  'icon' => 'fa-check-circle'],
                ['file' => 'customer_payment',      'page_key' => 'customer_payment',      'label' => 'Collect Payment', 'icon' => 'fa-money-bill-wave'],
                ['file' => 'outstanding_invoices',  'page_key' => 'outstanding_invoices',  'label' => 'Outstanding Invoices', 'icon' => 'fa-file-invoice-dollar'],
                ['file' => 'payment_history',       'page_key' => 'payment_history',       'label' => 'Payment History', 'icon' => 'fa-history'],
                ['file' => 'advance_payment_collection', 'page_key' => 'advance_payment_collection', 'label' => 'Advance Collection', 'icon' => 'fa-money-bill-wave'],
                ['file' => 'returns_center',            'page_key' => 'returns',                   'label' => 'Returns, Adjustments & Over-Delivery', 'icon' => 'fa-undo'],
                ['file' => 'returns',                   'page_key' => 'returns',                   'label' => 'Returns & Adjustments', 'icon' => 'fa-undo', 'hidden' => true],
                ['file' => 'stock_adjustment',          'page_key' => 'stock_adjustment',          'label' => 'Stock Adjustments',   'icon' => 'fa-sliders-h', 'hidden' => true],
                ['file' => 'payment_watch',             'page_key' => 'payment_watch',             'label' => 'Payment Watch',       'icon' => 'fa-eye'],
                ['file' => 'over_delivery',             'page_key' => 'over_delivery',             'label' => 'Over-Delivery',       'icon' => 'fa-truck-loading', 'hidden' => true],
                ['file' => 'approval_requests',         'page_key' => 'approval_requests',         'label' => 'Approval Requests',   'icon' => 'fa-user-check'],
                ['file' => 'qr_scan_log',               'page_key' => 'qr_scan_log',               'label' => 'QR Scan Log',         'icon' => 'fa-qrcode', 'hidden' => true],
            ],
            'page_actions' => [
                'all_sales'                  => ['can_export' => 'Export CSV', 'can_delete' => 'Delete Orders', 'can_edit' => 'Edit Orders'],
                'credit_order_approval'      => ['can_approve' => 'Approve Orders', 'can_reject' => 'Reject Orders', 'can_escalate_override' => 'Override 80% Credit Escalation'],
                'customer_payment'           => ['can_collect' => 'Collect Payment', 'can_override' => 'Override Amount'],
                'create_order'               => ['can_create' => 'Create Orders'],
                'customer_ledger'            => ['can_export' => 'Export Ledger'],
                'payment_history'            => ['can_edit' => 'Edit Posted Payment', 'can_delete' => 'Delete / Reverse Payment', 'can_export' => 'Export Payment History'],
                'outstanding_invoices'       => ['can_export' => 'Export Outstanding Invoices'],
                'returns'                    => ['can_approve' => 'Approve Returns (not own)', 'can_reject' => 'Reject Returns'],
                'stock_adjustment'           => ['can_create' => 'Create Stock Adjustments', 'can_approve' => 'Approve Adjustments (not own)'],
                'advance_payment_collection' => ['can_collect' => 'Collect Advance Payment'],
                'credit_order_view'          => ['can_view' => 'View Order Detail'],
                'credit_payment_collect'     => ['can_collect' => 'Collect Payment'],
                'ageing_report'              => ['can_export' => 'Export Ageing Report'],
                'bank_statement'             => ['can_export' => 'Export Bank Statement'],
                'payment_watch'              => ['can_grant_clearance' => 'Grant Dispatch Clearance', 'can_revoke_clearance' => 'Revoke Clearance'],
                'approval_requests'          => ['can_decide' => 'Reject Requests (posting needs own ৳ limit)'],
                'over_delivery'              => ['can_record' => 'Record Over-Delivery', 'can_approve' => 'Approve Over-Delivery (not own)'],
                'customer_credit_management' => ['can_update' => 'Update Credit Limits'],
                'order_amendment'            => ['can_request' => 'Request Order Amendments', 'can_approve' => 'Approve Order Amendments'],
                'sales_hub' => [
                    // Stage 1 — Order Creation
                    'can_create_order'     => 'Hub 1: Create New Order',
                    'can_advance_collect'  => 'Hub 1: Collect Advance',
                    'can_credit_limits'    => 'Hub 1: Customer Credit Limits',
                    // Stage 2 — Approval
                    'can_approve_orders'   => 'Hub 2: Approve Orders',
                    'can_payment_watch'    => 'Hub 2: Payment Watch',
                    // Stage 3 — Production
                    'can_production_queue' => 'Hub 3: Production Queue',
                    'can_order_tracker'    => 'Hub 3: Track Orders',
                    // Stage 4 — Dispatch & Delivery
                    'can_dispatch_board'   => 'Hub 4: Dispatch Board',
                    'can_partial_delivery' => 'Hub 4: Partial Delivery',
                    // Stage 5 — Payments
                    'can_customer_payment' => 'Hub 5: Record Payment',
                    'can_field_collect'    => 'Hub 5: Collect (Field)',
                    'can_payment_history'  => 'Hub 5: Payment History',
                    'can_bank_statement'   => 'Hub 5: Bank Statement',
                    // Stage 6 — Reports & Adjustments
                    'can_all_sales'        => 'Hub 6: All Sales',
                    'can_sales_report'     => 'Hub 6: Sales Report',
                    'can_ageing_report'    => 'Hub 6: Ageing Report',
                    'can_customer_ledger'  => 'Hub 6: Customer Ledger',
                    'can_returns'          => 'Hub 6: Returns',
                    'can_over_delivery'    => 'Hub 6: Over-Delivery',
                ],
            ],
        ],
        'production' => [
            'folder'       => 'cr',
            'label'        => 'Production',
            'icon'         => 'fa-industry',
            'color'        => 'amber',
            'active_files' => ['credit_production', 'credit_dispatch', 'partial_delivery', 'order_status', 'sales_report', 'production_requirement'],
            'nav' => [
                ['file' => 'credit_production',      'page_key' => 'credit_production',      'label' => 'Production',                 'icon' => 'fa-industry'],
                ['file' => 'production_requirement', 'page_key' => 'production_requirement', 'label' => "Today's Requirement",        'icon' => 'fa-list-check'],
                ['file' => 'credit_dispatch',        'page_key' => 'credit_dispatch',        'label' => 'Dispatch',                   'icon' => 'fa-shipping-fast'],
                ['file' => 'partial_delivery',       'page_key' => 'partial_delivery',       'label' => 'Partial Delivery',           'icon' => 'fa-dolly'],
                ['file' => 'order_status',           'page_key' => 'order_status',           'label' => 'Track Order',                'icon' => 'fa-map-marker-alt'],
                ['file' => 'sales_report',           'page_key' => 'sales_report',           'label' => 'Sales Report',               'icon' => 'fa-chart-bar'],
            ],
            'page_actions' => [
                'credit_production' => [
                    'can_update_status'     => 'Update Production Status',
                    'can_view_orders'       => 'View Orders & Sales Report',
                    'can_collect_payment'   => 'Collect Payment',
                    'can_view_payments'     => 'Payment History',
                    'can_edit_orders'       => 'Edit / Delete / Update Orders',
                    'can_partial_delivery'  => 'Partial Delivery',
                    'can_return'            => 'Process Returns',
                    'can_production_report' => 'Production Totals Report',
                ],
                'production_requirement' => ['can_update' => 'Update On-Hand / Produced Quantities'],
                'credit_dispatch'   => ['can_dispatch' => 'Mark as Dispatched', 'can_export' => 'Export Dispatch Report'],
                'partial_delivery'  => ['can_update' => 'Record Partial Delivery'],
                'sales_report'      => ['can_export' => 'Export Sales Report'],
            ],
        ],
        'customers' => [
            'folder' => 'customers',
            'label'  => 'Customers',
            'icon'   => 'fa-users',
            'color'  => 'green',
            'nav' => [
                ['file' => 'directory',                 'page_key' => 'directory',                 'label' => 'Customers',       'icon' => 'fa-address-book'],
                ['file' => 'index',                     'page_key' => 'index',                     'label' => 'Balances',        'icon' => 'fa-scale-balanced'],
                ['file' => 'manage',                    'page_key' => 'manage',                    'label' => 'Add Customer',    'icon' => 'fa-user-plus'],
                ['file' => 'customer_credit_management','page_key' => 'customer_credit_management','label' => 'Credit Limit',    'icon' => 'fa-credit-card',  'folder' => 'cr'],
                ['file' => 'ageing_report',             'page_key' => 'ageing_report',             'label' => 'Ageing Report',   'icon' => 'fa-chart-bar',    'folder' => 'cr'],
                ['file' => 'customer_ledger',           'page_key' => 'customer_ledger',           'label' => 'Customer Ledger', 'icon' => 'fa-book',         'folder' => 'cr'],
                ['file' => 'view',                      'page_key' => 'view',                      'label' => 'Customer Detail',  'icon' => 'fa-user'],
            ],
            'page_actions' => [
                'index'  => ['can_export' => 'Export CSV'],
                'manage' => ['can_create' => 'Create Customer', 'can_edit' => 'Edit Customer', 'can_delete' => 'Delete Customer'],
                'view'   => ['can_view' => 'View Customer Profile'],
            ],
        ],
        'products' => [
            'folder' => 'product',
            'label'  => 'Products',
            'icon'   => 'fa-box',
            'color'  => 'purple',
            'nav' => [
                ['file' => 'products_overview','page_key' => 'products_overview','label' => 'Overview',         'icon' => 'fa-box-open'],
                ['file' => 'products',         'page_key' => 'products',         'label' => 'Price Matrix',     'icon' => 'fa-table'],
                ['file' => 'base_products',    'page_key' => 'base_products',    'label' => 'Base Products',    'icon' => 'fa-cube'],
                ['file' => 'pricing',          'page_key' => 'pricing',          'label' => 'Pricing',          'icon' => 'fa-tags'],
                ['file' => 'pricing_engine',   'page_key' => 'pricing_engine',   'label' => 'Smart Pricing',    'icon' => 'fa-bolt',   'admin_only' => true],
                ['file' => 'inventory',        'page_key' => 'inventory',        'label' => 'Inventory',        'icon' => 'fa-warehouse'],
                ['file' => 'manage_variants',  'page_key' => 'manage_variants',  'label' => 'Manage Variants',  'icon' => 'fa-list'],
            ],
            'page_actions' => [
                'pricing'         => ['can_edit' => 'Edit Pricing'],
                'inventory'       => ['can_adjust' => 'Adjust Stock'],
                'base_products'   => ['can_create' => 'Add Products', 'can_edit' => 'Edit Products'],
                'manage_variants' => ['can_create' => 'Add Variants', 'can_edit' => 'Edit Variants'],
                'pricing_engine'  => ['can_configure' => 'Configure Smart Pricing'],
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
                ['file' => 'expense_voucher_create', 'page_key' => 'expense_voucher_create', 'label' => 'Create Voucher',  'icon' => 'fa-plus-circle'],
                ['file' => 'view_expense_voucher',   'page_key' => 'view_expense_voucher',   'label' => 'View Voucher',    'icon' => 'fa-file-alt'],
            ],
            'page_actions' => [
                'create_expense'         => ['can_create' => 'Create Expense'],
                'approve_expense'        => ['can_approve' => 'Approve Expenses', 'can_reject' => 'Reject Expenses'],
                'edit_expense'           => ['can_edit' => 'Edit Expenses'],
                'expense_history'        => ['can_export' => 'Export CSV', 'can_delete' => 'Delete Records'],
                'expense_categories'     => ['can_manage' => 'Manage Categories'],
                'expense_voucher_create' => ['can_create' => 'Create Expense Voucher'],
                'view_expense_voucher'   => ['can_view' => 'View Expense Voucher'],
                'expense_voucher_list'   => ['can_delete' => 'Delete Vouchers', 'can_export' => 'Export Voucher List'],
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
                ['file' => 'central_statement',  'page_key' => 'central_statement',  'label' => 'Central Statement',     'icon' => 'fa-landmark', 'admin_only' => true],
                ['file' => 'manage_accounts',    'page_key' => 'manage_accounts',    'label' => 'Manage Accounts',       'icon' => 'fa-piggy-bank'],
                ['file' => 'manage_types',       'page_key' => 'manage_types',       'label' => 'Transaction Types',     'icon' => 'fa-tags'],
                ['file' => 'bulk_manage',        'page_key' => 'bulk_manage',        'label' => 'Bulk Manage',           'icon' => 'fa-layer-group'],
                ['file' => 'view_transaction',   'page_key' => 'view_transaction',   'label' => 'View Transaction',      'icon' => 'fa-eye'],
            ],
            'page_actions' => [
                'create_transaction' => ['can_create' => 'Create Transaction', 'can_approve' => 'Approve Transaction'],
                'transfer'           => ['can_transfer' => 'Transfer Funds'],
                'statement'          => ['can_export' => 'Export Statement'],
                'view_transaction'   => ['can_view' => 'View Transaction Detail'],
                'manage_accounts'    => ['can_create' => 'Add Bank Account', 'can_edit' => 'Edit Bank Account'],
                'manage_types'       => ['can_create' => 'Add Transaction Type', 'can_edit' => 'Edit Transaction Type'],
                'bulk_manage'        => ['can_update' => 'Bulk Update', 'can_delete' => 'Bulk Delete'],
                'central_statement'  => ['can_export' => 'Export Central Statement', 'can_delete' => 'Delete Entries'],
            ],
        ],
        'accounts' => [
            'folder' => 'accounts',
            'label'  => 'Accounts',
            'icon'   => 'fa-book',
            'color'  => 'teal',
            'nav' => [
                ['file' => 'index',                   'page_key' => 'index',                   'label' => 'Dashboard',           'icon' => 'fa-tachometer-alt'],
                ['file' => 'chart_of_accounts',       'page_key' => 'chart_of_accounts',       'label' => 'Chart of Accounts',   'icon' => 'fa-sitemap'],
                ['file' => 'new_transaction',         'page_key' => 'new_transaction',         'label' => 'New Transaction',     'icon' => 'fa-plus-circle'],
                ['file' => 'all_accounts',            'page_key' => 'all_accounts',            'label' => 'All Statements',      'icon' => 'fa-list'],
                ['file' => 'bank_accounts',           'page_key' => 'bank_accounts',           'label' => 'Bank Accounts',       'icon' => 'fa-university'],
                ['file' => 'manage_bank_account',     'page_key' => 'manage_bank_account',     'label' => 'Manage Bank Account', 'icon' => 'fa-piggy-bank'],
                ['file' => 'internal_transfer',       'page_key' => 'internal_transfer',       'label' => 'Internal Transfer',   'icon' => 'fa-exchange-alt'],
                ['file' => 'receive_payment',         'page_key' => 'receive_payment',         'label' => 'Receive Payment',     'icon' => 'fa-hand-holding-usd'],
                ['file' => 'debit_voucher',           'page_key' => 'debit_voucher',           'label' => 'Debit Voucher',       'icon' => 'fa-receipt'],
                ['file' => 'daily_log',               'page_key' => 'daily_log',               'label' => 'Daily Log',           'icon' => 'fa-calendar-day'],
                ['file' => 'account_statement',       'page_key' => 'account_statement',       'label' => 'Account Statement',   'icon' => 'fa-file-alt'],
                ['file' => 'chart_account_statement', 'page_key' => 'chart_account_statement', 'label' => 'Chart Statement',     'icon' => 'fa-chart-area'],
                ['file' => 'reconcile',               'page_key' => 'reconcile',               'label' => 'Reconciliation',      'icon' => 'fa-balance-scale'],
                ['file' => 'tax_statement',           'page_key' => 'tax_statement',           'label' => 'Tax Statement',       'icon' => 'fa-file-invoice-dollar'],
            ],
            'page_actions' => [
                'new_transaction'         => ['can_create' => 'Create Transaction', 'can_delete' => 'Delete Transaction'],
                'debit_voucher'           => ['can_create' => 'Create Voucher', 'can_approve' => 'Approve Voucher'],
                'internal_transfer'       => ['can_transfer' => 'Make Transfer'],
                'all_accounts'            => ['can_export' => 'Export Data'],
                'daily_log'               => ['can_export' => 'Export Log'],
                'reconcile'               => ['can_export' => 'Export Reconciliation'],
                'manage_bank_account'     => ['can_create' => 'Add Bank Account', 'can_edit' => 'Edit Account', 'can_delete' => 'Delete Account'],
                'receive_payment'         => ['can_receive' => 'Record Received Payment'],
                'chart_account_statement' => ['can_export' => 'Export Chart Statement'],
                'debit_voucher_view'      => ['can_view' => 'View Voucher Detail'],
                'journal_entry_view'      => ['can_view' => 'View Journal Entry'],
                'expense_history'         => ['can_export' => 'Export Expense History'],
                'account_statement'       => ['can_export' => 'Export Account Statement'],
                'chart_of_accounts'       => ['can_create' => 'Add Accounts', 'can_edit' => 'Edit Accounts', 'can_delete' => 'Delete Accounts'],
                'bank_accounts'           => ['can_create' => 'Add Bank Account', 'can_edit' => 'Edit Bank Account'],
            ],
        ],
        'purchase' => [
            'folder' => 'purchase',
            'label'  => 'Purchase',
            'icon'   => 'fa-shopping-cart',
            'color'  => 'orange',
            // Legacy pages pre-dating the purchase_adnan_* refactor — excluded from privileges UI
            'excluded_pages' => [
                'create_po', 'index', 'purchase_orders',
                'suppliers', 'supplier_form', 'supplier_edit',
                'supplier_ledger', 'view_supplier', 'supplier_payment_view',
                'variance_report',
                'purchase_adnan_update_grn',     // POST handler for edit_grn
                'purchase_adnan_update_payment', // POST handler for edit_payment
            ],
            'nav' => [
                ['file' => 'purchase_adnan_index',            'page_key' => 'purchase_adnan_index',            'label' => 'Dashboard',        'icon' => 'fa-tachometer-alt'],
                ['file' => 'purchase_adnan_supplier_summary', 'page_key' => 'purchase_adnan_supplier_summary', 'label' => 'All Suppliers',    'icon' => 'fa-users'],
                // Visibility follows the All Suppliers grant; supplier_form.php itself
                // additionally requires the can_create ('Add Supplier') action.
                ['file' => 'supplier_form',                   'page_key' => 'purchase_adnan_supplier_summary', 'label' => 'Add Supplier',     'icon' => 'fa-user-plus'],
                ['file' => 'purchase_adnan_supplier_ledger',  'page_key' => 'purchase_adnan_supplier_ledger',  'label' => 'Supplier Ledger',  'icon' => 'fa-book'],
                ['file' => 'procurement_catalog',             'page_key' => 'purchase_procurement_catalog',    'label' => 'Procurement Catalog', 'icon' => 'fa-boxes-stacked', 'admin_only' => true],
                ['file' => 'all_po',                          'page_key' => 'all_po',                          'label' => 'All POs',          'icon' => 'fa-file-invoice'],
                ['file' => 'purchase_adnan_create_po',        'page_key' => 'purchase_adnan_create_po',        'label' => 'Create PO',        'icon' => 'fa-plus-circle'],
                ['file' => 'goods_received',                  'page_key' => 'goods_received',                  'label' => 'Goods Received',   'icon' => 'fa-clipboard-check'],
                ['file' => 'purchase_adnan_record_grn',       'page_key' => 'purchase_adnan_record_grn',       'label' => 'Record GRN',       'icon' => 'fa-plus'],
                ['file' => 'payments',                        'page_key' => 'payments',                        'label' => 'All Payments',     'icon' => 'fa-money-bill-wave'],
                ['file' => 'purchase_adnan_record_payment',   'page_key' => 'purchase_adnan_record_payment',   'label' => 'Record Payment',   'icon' => 'fa-plus'],
                ['file' => 'purchase_adnan_adjustments',      'page_key' => 'purchase_adnan_adjustments',      'label' => 'Adjustments',      'icon' => 'fa-sliders-h'],
                ['file' => 'purchase_adnan_balance_summary',  'page_key' => 'purchase_adnan_balance_summary',  'label' => 'Balance Summary',  'icon' => 'fa-balance-scale'],
                ['file' => 'purchase_adnan_variance_report',  'page_key' => 'purchase_adnan_variance_report',  'label' => 'Variance Report',  'icon' => 'fa-chart-bar'],
                ['file' => 'reports',                         'page_key' => 'reports',                         'label' => 'Reports',          'icon' => 'fa-chart-bar'],
                ['file' => 'purchase_adnan_reconciliation',   'page_key' => 'purchase_adnan_reconciliation',   'label' => 'Reconciliation',   'icon' => 'fa-clipboard-check', 'admin_only' => true],
                ['file' => 'bank_statement',                  'page_key' => 'bank_statement',                  'label' => 'Bank Statement',   'icon' => 'fa-university',      'admin_only' => true],
            ],
            'page_actions' => [
                'purchase_adnan_create_po'        => ['can_create' => 'Create PO', 'can_approve' => 'Approve PO'],
                'purchase_adnan_record_payment'   => ['can_pay' => 'Record Payment'],
                'purchase_adnan_supplier_summary' => ['can_create' => 'Add Supplier'],
                'purchase_procurement_catalog'    => ['can_manage' => 'Manage Procurement Catalog'],
                'all_po'                          => ['can_export' => 'Export', 'can_delete' => 'Delete PO'],
                'goods_received'                  => ['can_receive' => 'Record GRN', 'can_delete' => 'Delete GRN'],
                'payments'                        => ['can_delete' => 'Delete Payment', 'can_edit' => 'Edit Payment'],
                'reports'                         => ['can_export' => 'Export Reports'],
                'bank_statement'                  => ['can_export' => 'Export Bank Statement'],
                'purchase_adnan_view_po'          => ['can_view' => 'View PO Detail'],
                'purchase_adnan_edit_po'          => ['can_edit' => 'Edit PO'],
                'purchase_adnan_close_po'         => ['can_close' => 'Close PO'],
                'purchase_adnan_record_grn'       => ['can_create' => 'Record GRN'],
                'purchase_adnan_edit_grn'         => ['can_edit' => 'Edit GRN'],
                'purchase_adnan_edit_payment'     => ['can_edit' => 'Edit Payment'],
                'purchase_adnan_adjustments'      => ['can_view' => 'View Adjustments', 'can_export' => 'Export Adjustments'],
                'purchase_adnan_record_adjustment'=> ['can_create' => 'Record Adjustment'],
                'purchase_adnan_view_adjustment'  => ['can_view' => 'View Adjustment Detail'],
                'purchase_adnan_balance_summary'  => ['can_export' => 'Export Balance Summary'],
                'purchase_adnan_variance_report'  => ['can_export' => 'Export Variance Report'],
                'purchase_adnan_reconciliation'   => ['can_reconcile' => 'Run Reconciliation', 'can_delete' => 'Delete Entries'],
                'purchase_adnan_supplier_ledger'  => ['can_export' => 'Export Supplier Ledger', 'can_delete' => 'Delete Ledger Entries'],
            ],
        ],
        // Commodity Trading (Jul 2026) — reselling surplus wheat/commodities
        // directly, separate from the milled-flour Credit Sales pipeline.
        // Role fallback defaults Superadmin/admin; fully delegable via Privileges
        // like every other module.
        'trading' => [
            'folder' => 'trading',
            'label'  => 'Trading',
            'icon'   => 'fa-right-left',
            'color'  => 'rose',
            'nav' => [
                ['file' => 'dashboard',           'page_key' => 'dashboard',           'label' => 'Trading Dashboard',   'icon' => 'fa-gauge-high'],
                ['file' => 'commodity_sale',      'page_key' => 'commodity_sale',      'label' => 'Commodity Sale',      'icon' => 'fa-money-bill-transfer'],
                ['file' => 'collect_commodity_payment', 'page_key' => 'commodity_sale', 'label' => 'Collect Commodity Payment', 'icon' => 'fa-hand-holding-dollar', 'hidden' => true],
                ['file' => 'view_commodity_sale', 'page_key' => 'commodity_sale', 'label' => 'View Commodity Sale', 'icon' => 'fa-eye', 'hidden' => true],
                ['file' => 'edit_commodity_sale', 'page_key' => 'commodity_sale', 'label' => 'Edit Commodity Sale', 'icon' => 'fa-pen', 'hidden' => true],
                ['file' => 'commodity_dispatch',   'page_key' => 'commodity_dispatch',   'label' => 'Commodity Dispatch',   'icon' => 'fa-truck-fast'],
                ['file' => 'commodity_invoice',    'page_key' => 'commodity_dispatch',   'label' => 'Commodity Invoice',    'icon' => 'fa-file-invoice', 'hidden' => true],
                ['file' => 'commodity_gate_pass',  'page_key' => 'commodity_dispatch',   'label' => 'Commodity Gate Pass',  'icon' => 'fa-qrcode', 'hidden' => true],
                ['file' => 'commodity_verify_delivery', 'page_key' => 'commodity_dispatch', 'label' => 'Commodity Verify Delivery', 'icon' => 'fa-truck-ramp-box', 'hidden' => true],
                ['file' => 'commodity_inventory', 'page_key' => 'commodity_inventory', 'label' => 'Commodity Inventory', 'icon' => 'fa-warehouse'],
                ['file' => 'margin_report',       'page_key' => 'margin_report',       'label' => 'Margin Report',       'icon' => 'fa-chart-line'],
                ['file' => 'business_partners',   'page_key' => 'business_partners',   'label' => 'Business Partners',   'icon' => 'fa-handshake'],
                ['file' => 'partner_settlement',  'page_key' => 'partner_settlement',  'label' => 'Partner Settlement',  'icon' => 'fa-scale-balanced'],
            ],
            'page_actions' => [
                'commodity_sale'     => ['can_sell' => 'Record Commodity Sale', 'can_approve' => 'Approve Commodity Sale (not own)', 'can_delete' => 'Delete/Reverse Commodity Sale or Payment', 'can_edit' => 'Edit Commodity Sale (unpaid only)'],
                'business_partners'  => ['can_link' => 'Link Business Partners'],
                'partner_settlement' => ['can_settle' => 'Post Partner Settlement (netting)'],
            ],
        ],
        'loans' => [
            'folder' => 'loans',
            'label'  => 'Loans',
            'icon'   => 'fa-hand-holding-dollar',
            'color'  => 'amber',
            'nav' => [
                ['file' => 'dashboard',   'page_key' => 'dashboard',   'label' => 'Loans Dashboard', 'icon' => 'fa-gauge-high'],
                ['file' => 'loan',        'page_key' => 'loan',        'label' => 'New Loan',        'icon' => 'fa-money-bill-transfer'],
                ['file' => 'view_loan',   'page_key' => 'loan',        'label' => 'View Loan',       'icon' => 'fa-eye', 'hidden' => true],
                ['file' => 'repay_loan',  'page_key' => 'loan',        'label' => 'Collect Repayment', 'icon' => 'fa-hand-holding-dollar', 'hidden' => true],
            ],
            'page_actions' => [
                'loan' => ['can_lend' => 'Disburse Loan', 'can_approve' => 'Approve Loan Disbursement (not own)', 'can_delete' => 'Delete/Reverse Loan or Repayment', 'can_edit' => 'Edit Loan (undisbursed/unrepaid only)'],
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
                ['file' => 'index',             'page_key' => 'index',             'label' => 'POS Terminal',     'icon' => 'fa-cash-register'],
                ['file' => 'dashboard',         'page_key' => 'dashboard',         'label' => 'Dashboard',        'icon' => 'fa-chart-line'],
                ['file' => 'todays_sales',      'page_key' => 'todays_sales',      'label' => "Today's Sales",    'icon' => 'fa-receipt'],
                ['file' => 'customer_ledger',   'page_key' => 'customer_ledger',   'label' => 'Customer Ledger',  'icon' => 'fa-book'],
                ['file' => 'collect_payment',   'page_key' => 'collect_payment',   'label' => 'Collect Payment',  'icon' => 'fa-money-bill-wave'],
                ['file' => 'reports',           'page_key' => 'reports',           'label' => 'Reports',          'icon' => 'fa-chart-bar'],
                ['file' => 'cash_verification', 'page_key' => 'cash_verification', 'label' => 'Cash Verification','icon' => 'fa-coins'],
                ['file' => 'eod',               'page_key' => 'eod',               'label' => 'End of Day',       'icon' => 'fa-calendar-check'],
                ['file' => 'confirm_deposit',   'page_key' => 'confirm_deposit',   'label' => 'Confirm Bank Deposit', 'icon' => 'fa-piggy-bank'],
                ['file' => 'eod_reopen',        'page_key' => 'eod_reopen',        'label' => 'Reopen EOD',       'icon' => 'fa-redo', 'admin_only' => true],
            ],
            'page_actions' => [
                'index'              => ['can_edit' => 'Edit Sale', 'can_delete' => 'Delete Sale (Recycle Bin)'],
                'todays_sales'       => ['can_export' => 'Export CSV', 'can_edit' => 'Edit Sale', 'can_delete' => 'Delete Sale (Recycle Bin)'],
                'collect_payment'    => ['can_collect' => 'Collect POS Credit Payment'],
                'cash_verification'  => ['can_verify' => 'Verify Cash Count'],
                'eod'                => ['can_close' => 'Close Day', 'can_manage' => 'Manage EOD'],
                'eod_reopen'         => ['can_reopen' => 'Reopen Closed Day'],
                'confirm_deposit'    => ['can_confirm' => 'Confirm Bank Deposit'],
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
                ['file' => 'daily_summary',        'page_key' => 'daily_summary',        'label' => 'Daily Summary',       'icon' => 'fa-calendar-check'],
                ['file' => 'cashflow_dashboard',  'page_key' => 'cashflow_dashboard',  'label' => 'Cash Flow & Ops',    'icon' => 'fa-chart-line'],
                ['file' => 'operations_center',   'page_key' => 'operations_center',   'label' => 'Operations Center',  'icon' => 'fa-tower-broadcast'],
                ['file' => 'users',               'page_key' => 'users',               'label' => 'Users',              'icon' => 'fa-users'],
                ['file' => 'employees',          'page_key' => 'employees',          'label' => 'Employees',          'icon' => 'fa-id-badge'],
                ['file' => 'user_activity',      'page_key' => 'user_activity',      'label' => 'Audit Trail',        'icon' => 'fa-history'],
                ['file' => 'drivers_trucks',      'page_key' => 'drivers_trucks',      'label' => 'Drivers & Trucks',   'icon' => 'fa-truck'],
                ['file' => 'settings',           'page_key' => 'settings',           'label' => 'Settings',           'icon' => 'fa-cog'],
                ['file' => 'privileges',         'page_key' => 'privileges',         'label' => 'User Privileges',    'icon' => 'fa-shield-alt'],
                ['file' => 'role_matrix',        'page_key' => 'role_matrix',        'label' => 'Role Access Matrix', 'icon' => 'fa-table-cells', 'admin_only' => true],
                ['file' => 'db_viewer',          'page_key' => 'db_viewer',          'label' => 'DB Viewer',          'icon' => 'fa-database',      'admin_only' => true],
                ['file' => 'drive_manager',      'page_key' => 'drive_manager',      'label' => 'Drive & Backups',    'icon' => 'fa-cloud-arrow-up',   'admin_only' => true],
                ['file' => 'recycle_bin',        'page_key' => 'recycle_bin',        'label' => 'Recycle Bin',        'icon' => 'fa-trash-restore', 'admin_only' => true],
                ['file' => 'telegram_ai_users',  'page_key' => 'telegram_ai_users',  'label' => 'AI Query Access',    'icon' => 'fa-shield-halved', 'admin_only' => true],
            ],
            'page_actions' => [
                'users'               => ['can_create' => 'Create Users', 'can_edit' => 'Edit Users', 'can_delete' => 'Delete Users'],
                'manage_user'         => ['can_create' => 'Create Users', 'can_edit' => 'Edit Users', 'can_delete' => 'Delete Users'],
                'employees'           => ['can_create' => 'Add Employees', 'can_edit' => 'Edit Employees', 'can_delete' => 'Delete Employees'],
                'add_employee'        => ['can_create' => 'Add Employees'],
                'edit_employee'       => ['can_edit' => 'Edit Employees'],
                'manage_employee'     => ['can_edit' => 'Manage Employees'],
                'drivers_trucks'      => ['can_create' => 'Add Drivers/Trucks', 'can_edit' => 'Edit Drivers/Trucks', 'can_delete' => 'Delete Drivers/Trucks'],
                'settings'            => ['can_update' => 'Update Settings'],
                'reports'             => ['can_export' => 'Export Reports'],
                'user_activity'       => ['can_export' => 'Export Audit Trail'],
                'daily_summary'       => ['can_export' => 'Export Daily Summary'],
                'cashflow_dashboard'  => ['can_export' => 'Export Cash Flow'],
                'balance_sheet'       => ['can_export' => 'Export Balance Sheet'],
                'accounting'          => ['can_export' => 'Export Accounting Data'],
                'drive_manager'       => ['can_backup' => 'Run Backup', 'can_restore' => 'Restore / Manage Backups'],
            ],
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
    // Print / receipt pages (opened programmatically; access is governed by the parent page)
    if (str_starts_with($basename, 'print_')) return true;
    if (str_ends_with($basename, '_print')) return true;
    if (str_ends_with($basename, '_receipt')) return true;
    // Export download handlers (not user-navigable screens)
    if (str_ends_with($basename, '_export')) return true;
    // Inline delete-action handlers (permission handled via can_delete on the list page)
    if (str_contains($basename, '_delete_')) return true;
    if (str_starts_with($basename, 'delete_')) return true;
    // AJAX / download handler endpoints (not user-navigable screens)
    if (str_ends_with($basename, '_ajax')) return true;
    if (str_ends_with($basename, '_handler')) return true;
    // Diagnostic / migration utility files
    if (str_starts_with($basename, 'diag_')) return true;
    // AJAX-style handler pages whose names don't start with 'ajax'
    if (str_contains($basename, '_ajax_')) return true;
    // Specific utility files that don't warrant access control
    if (in_array($basename, [
        'check_functions', 'get_voucher_details', 'payment_debug', 'adnan_index', 'receipt',
        'admin_edit',       // admin-only utility, not a privilege-controlled page
        'migrate_fix_view', // one-time migration utility
        'eod_process',      // POS EOD POST handler, not a screen
        'index_claude',     // dev/experimental copy
        'index_last',       // old backup copy
        'drive_test',           // Google Drive diagnostic script
        'ledger_repair',        // one-time repair utility
        'settings_gem',         // legacy copy of settings
        'accounting_complete',  // legacy copy of accounting
        'update_photo',         // employee photo POST handler, not a screen
    ])) return true;

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
    // Invalidate when the folder OR this file (registry/rules) changes
    $folderMtime = max(@filemtime($folder) ?: 0, @filemtime(__FILE__) ?: 0);
    if (is_file($cacheFile) && @filemtime($cacheFile) > $folderMtime) {
        $cached = @json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached)) return $mem[$module_key] = $cached;
    }

    // — Scan —
    $pageActions = $registry[$module_key]['page_actions'] ?? [];

    // When two modules share one folder (credit_sales + production both live in cr/),
    // a page claimed by the OTHER module must not appear under this one — otherwise
    // every page shows twice in the privileges UI. A module "claims" a page via its
    // active_files, nav entries, or page_actions keys. Pages claimed by both (or by
    // neither) stay visible in this module.
    $this_claims  = array_unique(array_merge(
        $registry[$module_key]['active_files'] ?? [],
        array_column($registry[$module_key]['nav'] ?? [], 'file'),
        array_keys($pageActions)
    ));
    $other_claims = [];
    foreach ($registry as $other_key => $other_def) {
        if ($other_key === $module_key) continue;
        if (($other_def['folder'] ?? '') !== $registry[$module_key]['folder']) continue;
        $other_claims = array_merge(
            $other_claims,
            $other_def['active_files'] ?? [],
            array_column($other_def['nav'] ?? [], 'file'),
            array_keys($other_def['page_actions'] ?? [])
        );
    }
    $other_claims = array_unique($other_claims);

    $files = glob($folder . '/*.php') ?: [];
    $pages = [];
    foreach ($files as $filepath) {
        $basename = basename($filepath, '.php');
        if (isAutoHiddenPage($basename)) continue;
        // Module-specific exclusions for legacy/deprecated pages that share a folder
        if (in_array($basename, $registry[$module_key]['excluded_pages'] ?? [])) continue;
        // Claimed by a sibling module sharing this folder, and not by this one
        if (in_array($basename, $other_claims) && !in_array($basename, $this_claims)) continue;
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
 * Non-redirecting check: does the current user hold a privilege grant for a
 * page under a specific module? Mirrors restrict_access rules 2 + 3b–3d.
 * Use for pages that historically lived under two modules (e.g. credit_dispatch
 * and partial_delivery appear in old credit_sales rows AND new production rows).
 */
function userHasPageGrant(string $module, string $page_key): bool {
    if (in_array($_SESSION['user_role'] ?? '', ['Superadmin', 'admin'])) return true;
    $perms = getUserCustomPerms();
    $mod = $perms[$module] ?? null;
    if (!$mod || empty($mod['enabled'])) return false;
    if (empty($mod['allowed_pages'])) return true; // whole-module grant
    return in_array($page_key, $mod['allowed_pages']);
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
/**
 * Return a SAFE internal return-to path saved before a login redirect, or null.
 * Same-origin root-relative only (blocks open-redirect / header injection / auth loops).
 * Consumes the value (unsets it).
 */
function safe_return_to(): ?string {
    $rt = $_SESSION['return_to'] ?? null;
    unset($_SESSION['return_to']);
    if (!is_string($rt) || $rt === '' || strlen($rt) > 1000) return null;
    if ($rt[0] !== '/' || substr($rt, 0, 2) === '//' || substr($rt, 0, 2) === '/\\') return null; // no protocol-relative
    if (preg_match('/[\r\n\t]/', $rt)) return null;                                                 // no header injection
    if (stripos($rt, '/auth/') !== false) return null;                                              // don't loop back to login
    return $rt;
}

function restrict_access(array $allowed_roles = [], ?string $module = null, ?string $page_key = null): void {
    // ── Must be logged in ─────────────────────────────────────────────────────
    if (!isLoggedIn()) {
        // Deep-link: remember where they were headed so login returns them here
        // (e.g. scanning the delivery QR in a browser without a session).
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !empty($_SERVER['REQUEST_URI'])) {
            $_SESSION['return_to'] = $_SERVER['REQUEST_URI'];
        }
        $_SESSION['error_flash'] = 'Please sign in to continue.';
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

        // No privilege entry for this module — fall back to role list, then deny
        if ($mod === null) {
            if (!empty($allowed_roles) && in_array($user_role, $allowed_roles)) {
                return; // role-based grant: no custom-perm entry needed
            }
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
            // Utility pages (invoice prints, receipts, delete/ajax handlers) are
            // auto-hidden from the privileges UI, so they can never appear in
            // allowed_pages. For these, the page's own $allowed_roles list is the
            // gate — NOT a blanket grant, because handlers like delete_order must
            // keep their role restriction even for module-enabled users.
            if (isAutoHiddenPage($page_key)) {
                if (empty($allowed_roles) || in_array($user_role, $allowed_roles)) {
                    return;
                }
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
 * GOOGLE DRIVE HELPERS
 * ============================================================================
 */

/**
 * Upload a file to Google Drive.
 * Returns the Drive file ID on success, or null on failure / Drive disabled.
 *
 * Usage example (in a file-upload form handler):
 *   $driveId = drive_upload($_FILES['receipt']['tmp_name'], 'Receipt_2026.pdf', 'receipts');
 *
 * @param string $localPath   Temp path of the uploaded file ($_FILES[...]['tmp_name'])
 * @param string $filename    Desired filename on Drive
 * @param string $category    'receipts' | 'images' | 'docs'
 * @return string|null        Drive file ID, or null on failure
 */
function drive_upload(string $localPath, string $filename, string $category = 'receipts'): ?string
{
    if (!defined('GOOGLE_DRIVE_ENABLED') || !GOOGLE_DRIVE_ENABLED) {
        return null;
    }

    $folderMap = [
        'receipts' => GOOGLE_DRIVE_RECEIPTS_FOLDER_ID,
        'images'   => GOOGLE_DRIVE_IMAGES_FOLDER_ID,
        'docs'     => GOOGLE_DRIVE_DOCS_FOLDER_ID,
    ];

    $folderId = $folderMap[$category] ?? GOOGLE_DRIVE_RECEIPTS_FOLDER_ID;
    if (empty($folderId)) return null;

    try {
        $drive = new GoogleDriveService(GOOGLE_SERVICE_ACCOUNT_JSON);
        $mime  = mime_content_type($localPath) ?: 'application/octet-stream';
        $meta  = $drive->uploadFile($localPath, date('Ymd_His_') . basename($filename), $mime, $folderId);
        return $meta['id'] ?? null;
    } catch (Throwable) {
        return null; // Non-fatal — local upload still proceeds
    }
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
 * ORDER APPROVAL GATES (production hold / dispatch clearance)
 * ============================================================================
 */

/**
 * Self-migrating table for admin approval conditions.
 * CREATE TABLE IF NOT EXISTS only — never touches existing tables.
 * Safe to call on every request; runs at most once per request.
 */
function ensureApprovalGateTables(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    global $db;
    // Existence probe FIRST: any DDL (even CREATE TABLE IF NOT EXISTS on an
    // existing table) implicit-commits an open MySQL transaction. One
    // information_schema query covers both table existence and enum state.
    try {
        $col = $db->getPdo()->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'order_approval_conditions'
                AND COLUMN_NAME = 'condition_type'"
        )->fetchColumn();
        if ($col !== false && stripos($col, 'outstanding_after_ship') !== false) return;
    } catch (Exception $e) { $col = false; }
    try {
        $db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS `order_approval_conditions` (
              `id`                     bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `order_id`               bigint UNSIGNED NOT NULL,
              `approved_by_user_id`    bigint UNSIGNED NOT NULL,
              `approved_at`            timestamp NOT NULL DEFAULT current_timestamp(),
              `production_hold`        tinyint(1) NOT NULL DEFAULT 0,
              `production_note`        text DEFAULT NULL,
              `production_released_by` bigint UNSIGNED DEFAULT NULL,
              `production_released_at` datetime DEFAULT NULL,
              `dispatch_hold`          tinyint(1) NOT NULL DEFAULT 0,
              `condition_type`         enum('manual','outstanding_below','amount_received') NOT NULL DEFAULT 'manual',
              `condition_amount`       decimal(15,2) DEFAULT NULL,
              `auto_release`           tinyint(1) NOT NULL DEFAULT 0,
              `accounts_note`          text DEFAULT NULL,
              `dispatch_cleared`       tinyint(1) NOT NULL DEFAULT 0,
              `cleared_by`             bigint UNSIGNED DEFAULT NULL,
              `cleared_at`             datetime DEFAULT NULL,
              `clearance_note`         text DEFAULT NULL,
              `updated_at`             timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_gate_order` (`order_id`),
              KEY `idx_gate_holds` (`dispatch_hold`, `dispatch_cleared`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log('ensureApprovalGateTables: ' . $e->getMessage());
    }
    // Extend condition types on our own feature table (idempotent; harmless re-run)
    try {
        $db->getPdo()->exec("
            ALTER TABLE `order_approval_conditions`
            MODIFY `condition_type` enum('manual','outstanding_below','outstanding_after_ship','amount_received')
                   NOT NULL DEFAULT 'manual'
        ");
    } catch (Exception $e) {
        error_log('ensureApprovalGateTables enum: ' . $e->getMessage());
    }
}

/**
 * Self-migrating table for per-user order approval limits.
 * CREATE TABLE IF NOT EXISTS only — never touches existing tables.
 */
function ensureApprovalLimitTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    global $db;
    // Probe first — DDL implicit-commits open transactions (see ensureApprovalGateTables)
    try { $db->getPdo()->query("SELECT 1 FROM `user_approval_limits` LIMIT 1"); return; } catch (Exception $e) {}
    try {
        $db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS `user_approval_limits` (
              `id`               bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id`          bigint UNSIGNED NOT NULL,
              `max_order_amount` decimal(15,2) NOT NULL,
              `set_by_user_id`   bigint UNSIGNED DEFAULT NULL,
              `updated_at`       timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_ual_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log('ensureApprovalLimitTable: ' . $e->getMessage());
    }
}

/* ═══════════════ Recycle Bin — reversible soft-delete (Feature #3) ═══════════
   Instead of hard-deleting, callers snapshot the affected rows into an archive
   and remove them from the live tables. A batch groups one logical delete (an
   order + its items + payments + ledger rows) so the whole set restores together.
   Two op kinds keep restores exact:
     • 'delete' — row removed from source_table → restore re-INSERTs it (with PK).
     • 'update' — before-image of a row that was modified (e.g. amount_paid) →
                  restore re-applies the saved column values.
   Superadmin-only surfaces call these; nothing here decides authorisation.     */

function ensureRecycleBinTables(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    // Resolve the wrapper via the singleton, NOT `global $db` — some pages
    // (purchase module, managers) reassign the global to a raw PDO.
    $db = Database::getInstance();
    // Probe first — DDL implicit-commits open transactions.
    try { $db->getPdo()->query("SELECT 1 FROM `cr_recycle_bin_rows` LIMIT 1"); return; } catch (Exception $e) {}
    try {
        $db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS `cr_recycle_bin` (
              `id`                 bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `entity_type`        varchar(40) NOT NULL,
              `label`              varchar(255) DEFAULT NULL,
              `customer_id`        bigint UNSIGNED DEFAULT NULL,
              `deleted_by_user_id` bigint UNSIGNED DEFAULT NULL,
              `deleted_by_name`    varchar(120) DEFAULT NULL,
              `deleted_at`         timestamp NOT NULL DEFAULT current_timestamp(),
              `status`             enum('deleted','restored','purged') NOT NULL DEFAULT 'deleted',
              `restored_by_name`   varchar(120) DEFAULT NULL,
              `restored_at`        timestamp NULL DEFAULT NULL,
              `row_count`          int NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              KEY `idx_rb_status` (`status`),
              KEY `idx_rb_customer` (`customer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS `cr_recycle_bin_rows` (
              `id`           bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `batch_id`     bigint UNSIGNED NOT NULL,
              `op`           enum('delete','update') NOT NULL DEFAULT 'delete',
              `source_table` varchar(64) NOT NULL,
              `source_pk`    varchar(64) DEFAULT NULL,
              `pk_col`       varchar(64) NOT NULL DEFAULT 'id',
              `row_json`     longtext NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_rbr_batch` (`batch_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log('ensureRecycleBinTables: ' . $e->getMessage());
    }
}

/** Open a recycle batch; returns its id. */
function recycleBegin(string $entityType, string $label, ?int $customerId = null): int {
    $db = Database::getInstance();
    ensureRecycleBinTables();
    return (int)$db->insert('cr_recycle_bin', [
        'entity_type'        => $entityType,
        'label'              => mb_substr($label, 0, 255),
        'customer_id'        => $customerId,
        'deleted_by_user_id' => (int)($_SESSION['user_id'] ?? 0) ?: null,
        'deleted_by_name'    => $_SESSION['user_display_name'] ?? 'System',
    ]);
}

/**
 * Snapshot every row matching $whereCol=$whereVal as op=delete, then DELETE them.
 * Returns the number of rows archived+deleted.
 */
function recycleArchiveDelete(int $batch, string $table, string $whereCol, $whereVal, string $pkCol = 'id'): int {
    $db = Database::getInstance();
    $rows = [];
    try {
        $rows = $db->query("SELECT * FROM `{$table}` WHERE `{$whereCol}` = ?", [$whereVal])->results() ?: [];
    } catch (Exception $e) { return 0; }
    $n = 0;
    foreach ($rows as $r) {
        $arr = (array)$r;
        // This archive-copy insert was previously unchecked, even though the
        // DELETE right below it already was (see the comment there) — a
        // silently swallowed failure here (Database::insert() returns false,
        // never throws) let $n still increment and the guarded DELETE proceed
        // anyway, permanently removing the row with NO backup copy despite the
        // caller believing it was archived into a "restorable" recycle batch.
        $archive_id = $db->insert('cr_recycle_bin_rows', [
            'batch_id'     => $batch,
            'op'           => 'delete',
            'source_table' => $table,
            'source_pk'    => (string)($arr[$pkCol] ?? ''),
            'pk_col'       => $pkCol,
            'row_json'     => json_encode($arr, JSON_UNESCAPED_UNICODE),
        ]);
        if (!$archive_id) {
            throw new Exception("Failed to archive a row from `{$table}` (batch #{$batch}) before deleting it.");
        }
        $n++;
    }
    if ($n > 0) {
        // Do NOT swallow this. A failed DELETE here (most commonly a foreign-key
        // constraint from a table this caller forgot to archive first) used to
        // be silently ignored — the row got archived into the recycle bin
        // looking like a success, while the LIVE row survived untouched. Any
        // later delete attempt on the same row would then archive ANOTHER
        // duplicate copy, forever, without ever actually removing it.
        //
        // Database::query() itself catches PDOException internally and never
        // rethrows (it just flags ->error() and logs), so a bare try/catch
        // here would never see the failure either — it has to be checked
        // explicitly and re-raised so the caller's transaction can roll back
        // and report the real error instead of quietly leaving data half-deleted.
        $db->query("DELETE FROM `{$table}` WHERE `{$whereCol}` = ?", [$whereVal]);
        if ($db->error()) {
            $info = $db->errorInfo();
            throw new Exception("Failed to delete from `{$table}` (batch #{$batch}): " . ($info[2] ?? 'unknown DB error'));
        }
    }
    return $n;
}

/**
 * Cascade-archive a customer's ENTIRE footprint into an open recycle batch, in
 * FK-safe order (leaf children first → parents last), so deleting the customer
 * leaves nothing orphaned and the whole account can be restored in one click.
 * Caller must open the batch, call this, then archive the `customers` row, then
 * finalize — all inside one transaction. Only meant for a zero-balance customer.
 */
function recycleArchiveCustomerCascade(int $batch, int $customer_id): void {
    $db = Database::getInstance();
    $ids = function (string $sql, $p) use ($db): array {
        try { return array_map(fn($r) => (int)$r->id, $db->query($sql, $p)->results() ?: []); }
        catch (Exception $e) { return []; }
    };

    $order_ids = $ids("SELECT id FROM credit_orders WHERE customer_id = ?", [$customer_id]);
    $pay_ids   = $ids("SELECT id FROM customer_payments WHERE customer_id = ?", [$customer_id]);

    // ── Deepest grandchildren first (delivery items, return items) ──
    foreach ($order_ids as $oid) {
        foreach ($ids("SELECT id FROM credit_order_deliveries WHERE order_id = ?", [$oid]) as $did) {
            recycleArchiveDelete($batch, 'credit_order_delivery_items', 'delivery_id', $did);
        }
        foreach ($ids("SELECT id FROM credit_order_returns WHERE order_id = ?", [$oid]) as $rid) {
            recycleArchiveDelete($batch, 'credit_order_return_items', 'return_id', $rid);
        }
    }

    // ── Payment allocations (by payment and by order) ──
    foreach ($pay_ids   as $pid) recycleArchiveDelete($batch, 'payment_allocations', 'payment_id', $pid);
    foreach ($order_ids as $oid) recycleArchiveDelete($batch, 'payment_allocations', 'order_id',   $oid);

    // ── Order-level children (per order) ──
    $order_children = [
        'credit_order_items', 'credit_order_workflow', 'credit_order_shipping', 'credit_order_audit',
        'credit_order_deliveries', 'credit_order_over_deliveries', 'credit_order_returns',
        'invoice_snapshots', 'order_amendments', 'order_approval_conditions', 'production_schedule',
        'dispatch_adhoc_fleet', 'trip_order_assignments', 'cr_delivery_confirmations',
    ];
    foreach ($order_ids as $oid) {
        foreach ($order_children as $tbl) recycleArchiveDelete($batch, $tbl, 'order_id', $oid);
    }

    // ── Customer-level parents ──
    recycleArchiveDelete($batch, 'customer_payments',        'customer_id', $customer_id);
    recycleArchiveDelete($batch, 'customer_ledger',          'customer_id', $customer_id);
    recycleArchiveDelete($batch, 'credit_orders',            'customer_id', $customer_id);
    recycleArchiveDelete($batch, 'orders',                   'customer_id', $customer_id);
    recycleArchiveDelete($batch, 'vehicle_rentals',          'customer_id', $customer_id);
    recycleArchiveDelete($batch, 'wheat_shipment_positions', 'customer_id', $customer_id);
    // The `customers` row itself is archived by the caller, last.
}

/**
 * Snapshot the CURRENT state of a single row as an op=update before-image, so a
 * later restore can put its columns back. Does NOT modify the row — the caller
 * runs its UPDATE afterwards.
 */
function recycleSnapshotBefore(int $batch, string $table, string $pkCol, $pkVal): void {
    $db = Database::getInstance();
    try {
        $row = $db->query("SELECT * FROM `{$table}` WHERE `{$pkCol}` = ?", [$pkVal])->first();
        if (!$row) return;
        $db->insert('cr_recycle_bin_rows', [
            'batch_id'     => $batch,
            'op'           => 'update',
            'source_table' => $table,
            'source_pk'    => (string)$pkVal,
            'pk_col'       => $pkCol,
            'row_json'     => json_encode((array)$row, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Exception $e) {
        error_log('recycleSnapshotBefore: ' . $e->getMessage());
    }
}

/** Stamp the batch's final row count (call once after all captures). */
function recycleFinalize(int $batch): void {
    $db = Database::getInstance();
    try {
        $db->query("UPDATE cr_recycle_bin SET row_count =
                    (SELECT COUNT(*) FROM cr_recycle_bin_rows WHERE batch_id = ?) WHERE id = ?",
                   [$batch, $batch]);
    } catch (Exception $e) {}
}

/**
 * Restore a deleted batch: re-INSERT the deleted rows (parents first, i.e. reverse
 * capture order) then re-apply the update before-images. Returns [ok, message].
 */
function restoreRecycleBatch(int $batch): array {
    global $db;
    ensureRecycleBinTables();
    $pdo = $db->getPdo();
    $ownTx = !$pdo->inTransaction();
    try {
        $head = $db->query("SELECT * FROM cr_recycle_bin WHERE id = ?", [$batch])->first();
        if (!$head) return [false, 'Recycle batch not found.'];
        if ($head->status !== 'deleted') return [false, 'This batch has already been ' . $head->status . '.'];

        if ($ownTx) $pdo->beginTransaction();

        // Re-insert deleted rows, parents first (DESC capture id = reverse of delete order)
        $delRows = $db->query(
            "SELECT * FROM cr_recycle_bin_rows WHERE batch_id = ? AND op = 'delete' ORDER BY id DESC",
            [$batch]
        )->results() ?: [];
        foreach ($delRows as $r) {
            $data = json_decode($r->row_json, true);
            if (!is_array($data) || !$data) continue;
            $cols = array_keys($data);
            $ph   = implode(', ', array_fill(0, count($cols), '?'));
            $colSql = '`' . implode('`, `', $cols) . '`';
            try {
                $db->query("INSERT INTO `{$r->source_table}` ({$colSql}) VALUES ({$ph})", array_values($data));
            } catch (Exception $e) {
                // Row may already exist — ignore and continue so a partial restore still proceeds
                error_log("restore reinsert {$r->source_table}: " . $e->getMessage());
            }
        }

        // Re-apply update before-images
        $updRows = $db->query(
            "SELECT * FROM cr_recycle_bin_rows WHERE batch_id = ? AND op = 'update' ORDER BY id ASC",
            [$batch]
        )->results() ?: [];
        foreach ($updRows as $r) {
            $data = json_decode($r->row_json, true);
            if (!is_array($data) || !$data) continue;
            $pkCol = $r->pk_col ?: 'id';
            $sets = [];
            $vals = [];
            foreach ($data as $col => $val) {
                if ($col === $pkCol) continue;
                $sets[] = "`{$col}` = ?";
                $vals[] = $val;
            }
            if (!$sets) continue;
            $vals[] = $r->source_pk;
            try {
                $db->query("UPDATE `{$r->source_table}` SET " . implode(', ', $sets) . " WHERE `{$pkCol}` = ?", $vals);
            } catch (Exception $e) {
                error_log("restore update {$r->source_table}: " . $e->getMessage());
            }
        }

        $db->query("UPDATE cr_recycle_bin SET status = 'restored', restored_by_name = ?, restored_at = NOW() WHERE id = ?",
                   [$_SESSION['user_display_name'] ?? 'System', $batch]);

        if ($ownTx) $pdo->commit();
        return [true, "Restored “{$head->label}” ({$head->row_count} record(s))."];
    } catch (Exception $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        return [false, 'Restore failed: ' . $e->getMessage()];
    }
}

/** Permanently discard an archived batch (cannot be restored afterwards). */
function purgeRecycleBatch(int $batch): bool {
    global $db;
    ensureRecycleBinTables();
    try {
        $db->query("DELETE FROM cr_recycle_bin_rows WHERE batch_id = ?", [$batch]);
        $db->query("UPDATE cr_recycle_bin SET status = 'purged', row_count = 0 WHERE id = ?", [$batch]);
        return true;
    } catch (Exception $e) {
        error_log('purgeRecycleBatch: ' . $e->getMessage());
        return false;
    }
}

/* ═══════════════ Invoice QR verification (Feature #17) ═══════════════
   A signed, non-enumerable verify URL is encoded into a QR on the printed
   invoice. Scanning opens a public read-only summary (cr/verify_invoice.php)
   that only renders when the signature matches — so it confirms the original
   without exposing every invoice. */

function getInvoiceQrSecret(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    $db = Database::getInstance();
    try {
        $r = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'invoice_qr_secret'")->first();
        if ($r && !empty($r->setting_value)) return $cached = $r->setting_value;
        $secret = bin2hex(random_bytes(24));
        $db->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('invoice_qr_secret', ?)", [$secret]);
        return $cached = $secret;
    } catch (Exception $e) {
        // Fallback: a stable server-derived key (never leaves the server; only the digest is public)
        return $cached = hash('sha256', (defined('DB_PASS') ? DB_PASS : '') . (defined('APP_NAME') ? APP_NAME : 'ufm') . '|invoice-qr');
    }
}

/** 16-char signature binding invoice number + amount + date. */
function invoiceQrSignature(string $orderNumber, float $amount, string $date): string {
    $payload = $orderNumber . '|' . number_format($amount, 2, '.', '') . '|' . $date;
    return substr(hash_hmac('sha256', $payload, getInvoiceQrSecret()), 0, 16);
}

/** Signature for the delivery-confirmation QR (binds the order number). */
function deliveryQrSignature(string $orderNumber): string {
    return substr(hash_hmac('sha256', 'DELIV|' . $orderNumber, getInvoiceQrSecret()), 0, 16);
}

/**
 * One-time delivery-confirmation record per order (Feature #17 delivery control).
 * A UNIQUE order_id enforces "prevent double delivery" at the DB level.
 */
function ensureDeliveryConfirmTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db  = Database::getInstance();
    $pdo = $db->getPdo();
    // Already has the two-stage (gate-pass) columns? then we're done.
    try { $pdo->query("SELECT `gate_out_at` FROM `cr_delivery_confirmations` LIMIT 1"); return; } catch (Exception $e) {}

    // Does the table exist at all (old delivery-only version)?
    $exists = false;
    try { $pdo->query("SELECT 1 FROM `cr_delivery_confirmations` LIMIT 1"); $exists = true; } catch (Exception $e) {}

    try {
        if (!$exists) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `cr_delivery_confirmations` (
                  `id`                   bigint UNSIGNED NOT NULL AUTO_INCREMENT,
                  `order_id`             bigint UNSIGNED NOT NULL,
                  `order_number`         varchar(50) DEFAULT NULL,
                  -- Stage 1: GATE PASS (goods leaving the factory)
                  `gate_out_at`          timestamp NULL DEFAULT NULL,
                  `gate_out_by_user_id`  bigint UNSIGNED DEFAULT NULL,
                  `gate_out_by_name`     varchar(120) DEFAULT NULL,
                  `driver_name`          varchar(150) DEFAULT NULL,
                  `vehicle_number`       varchar(100) DEFAULT NULL,
                  `gate_note`            varchar(500) DEFAULT NULL,
                  -- Stage 2: DELIVERY confirmation (goods received by customer)
                  `confirmed_at`         timestamp NULL DEFAULT NULL,
                  `confirmed_by_user_id` bigint UNSIGNED DEFAULT NULL,
                  `confirmed_by_name`    varchar(120) DEFAULT NULL,
                  `received_by`          varchar(150) DEFAULT NULL,
                  `note`                 varchar(500) DEFAULT NULL,
                  `created_at`           timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uk_dc_order` (`order_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } else {
            // Migrate the old delivery-only table → add the gate-pass stage,
            // and make confirmed_at nullable (a row now starts at gate-out).
            foreach ([
                "ALTER TABLE `cr_delivery_confirmations` ADD COLUMN `gate_out_at` timestamp NULL DEFAULT NULL",
                "ALTER TABLE `cr_delivery_confirmations` ADD COLUMN `gate_out_by_user_id` bigint UNSIGNED DEFAULT NULL",
                "ALTER TABLE `cr_delivery_confirmations` ADD COLUMN `gate_out_by_name` varchar(120) DEFAULT NULL",
                "ALTER TABLE `cr_delivery_confirmations` ADD COLUMN `gate_note` varchar(500) DEFAULT NULL",
                "ALTER TABLE `cr_delivery_confirmations` MODIFY `confirmed_at` timestamp NULL DEFAULT NULL",
            ] as $sql) {
                try { $pdo->exec($sql); } catch (Exception $e) { /* column may already exist */ }
            }
        }
    } catch (Exception $e) {
        error_log('ensureDeliveryConfirmTable: ' . $e->getMessage());
    }
}

/**
 * Log a QR scan (Feature #9). Each scan of the dispatch-slip QR is recorded; a
 * scan on an ALREADY-DELIVERED order is flagged as a reuse and pings admins so a
 * duplicate-delivery attempt is caught. Returns the total scan count for the order.
 */
function ensureQrScanLogTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = Database::getInstance();
    try { $db->getPdo()->query("SELECT 1 FROM `cr_qr_scan_log` LIMIT 1"); return; } catch (Exception $e) {}
    try {
        $db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS `cr_qr_scan_log` (
              `id`                 bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `order_id`           bigint UNSIGNED NOT NULL,
              `order_number`       varchar(50) DEFAULT NULL,
              `stage`              varchar(20) DEFAULT NULL,
              `reused`             tinyint(1) NOT NULL DEFAULT 0,
              `scanned_by_user_id` bigint UNSIGNED DEFAULT NULL,
              `scanned_by_name`    varchar(120) DEFAULT NULL,
              `ip`                 varchar(64) DEFAULT NULL,
              `scanned_at`         timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`), KEY `idx_qsl_order` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) { error_log('ensureQrScanLogTable: ' . $e->getMessage()); }
}

function recordQrScan(int $order_id, string $order_number, string $stage, string $scanner): int {
    $db = Database::getInstance();
    ensureQrScanLogTable();
    $reused = ($stage === 'done') ? 1 : 0;   // scanning an order that is already delivered
    try {
        $db->insert('cr_qr_scan_log', [
            'order_id'           => $order_id,
            'order_number'       => $order_number,
            'stage'              => $stage,
            'reused'             => $reused,
            'scanned_by_user_id' => (int)($_SESSION['user_id'] ?? 0),
            'scanned_by_name'    => $scanner,
            'ip'                 => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        $row   = $db->query("SELECT COUNT(*) AS c FROM cr_qr_scan_log WHERE order_id = ?", [$order_id])->first();
        $total = (int)($row->c ?? 0);
        if ($reused) notifyQrReuse($order_number, $scanner, $total);
        return $total;
    } catch (Exception $e) { error_log('recordQrScan: ' . $e->getMessage()); return 0; }
}

function notifyQrReuse(string $order_number, string $scanner, int $total): void {
    if (!defined('TELEGRAM_NOTIFICATIONS_ENABLED') || !TELEGRAM_NOTIFICATIONS_ENABLED) return;
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID')) return;
    try {
        require_once dirname(__DIR__) . '/classes/TelegramNotifier.php';
        $msg = "<b>⚠ QR RE-SCANNED AFTER DELIVERY</b>\n"
             . "───────────────────────────────\n\n"
             . "• Order: <code>{$order_number}</code>\n"
             . "• Already delivered — scanned again by <b>{$scanner}</b>\n"
             . "• Total scans on this QR: <b>{$total}</b>\n\n"
             . "<i>Possible duplicate-delivery attempt — please verify.</i>";
        (new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('dispatch')))->sendMessage($msg);
    } catch (\Throwable $e) { error_log('notifyQrReuse: ' . $e->getMessage()); }
}

/* ═══════════════ Daily Production Requirement (Aug 2026) ═════════════════════
   Per-product-variant, per-branch, per-day tracking of "already in hand" stock
   and cumulative "produced today" quantity, so cr/production_requirement.php
   can show how much more of each variant still needs producing to cover
   today's approved/in-production orders. Two tables: a current-state row per
   (date, branch, variant) that the page reads/updates, and an append-only log
   of every update for a visible audit trail (mirrors this app's convention of
   never mutating a running total without a traceable history entry). */

function ensureProductionDailyStockTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = Database::getInstance();
    try { $db->getPdo()->query("SELECT 1 FROM `production_daily_stock` LIMIT 1"); return; } catch (Exception $e) {}
    try {
        $db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS `production_daily_stock` (
              `id`                  bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `production_date`     date NOT NULL,
              `branch_id`           bigint UNSIGNED NOT NULL,
              `variant_id`          bigint UNSIGNED NOT NULL,
              `in_hand_qty`         decimal(10,2) NOT NULL DEFAULT 0.00,
              `produced_qty`        decimal(10,2) NOT NULL DEFAULT 0.00,
              `updated_by_user_id`  bigint UNSIGNED DEFAULT NULL,
              `created_at`          timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at`          timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_pds_date_branch_variant` (`production_date`, `branch_id`, `variant_id`),
              KEY `idx_pds_date` (`production_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) { error_log('ensureProductionDailyStockTable: ' . $e->getMessage()); }
}

function ensureProductionDailyLogTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = Database::getInstance();
    try { $db->getPdo()->query("SELECT 1 FROM `production_daily_log` LIMIT 1"); return; } catch (Exception $e) {}
    try {
        $db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS `production_daily_log` (
              `id`                bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `production_date`   date NOT NULL,
              `branch_id`         bigint UNSIGNED NOT NULL,
              `variant_id`        bigint UNSIGNED NOT NULL,
              `event_type`        enum('in_hand_set','produced_added') NOT NULL,
              `qty`               decimal(10,2) NOT NULL,
              `user_id`           bigint UNSIGNED DEFAULT NULL,
              `created_at`        timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_pdl_date_branch_variant` (`production_date`, `branch_id`, `variant_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) { error_log('ensureProductionDailyLogTable: ' . $e->getMessage()); }
}

/**
 * Adds edit-tracking columns to the (already-live) production_daily_log table.
 * `qty` stays the CURRENT/correct value (what every recompute sums against);
 * `edit_count`/`updated_at` are a cheap "(edited)" flag for the UI without a
 * join. The actual before/after/who/why history lives in the separate
 * production_daily_log_edits table (ensureProductionDailyLogEditsTable()) —
 * this pair of ALTERs just makes the parent row aware it's been touched.
 */
function ensureProductionDailyLogEditColumns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = Database::getInstance();
    try {
        $col = $db->getPdo()->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_daily_log' AND COLUMN_NAME = 'edit_count'"
        )->fetchColumn();
        if ($col !== false) return;
    } catch (Exception $e) { /* proceed to attempt the ALTER */ }
    try {
        $db->getPdo()->exec("
            ALTER TABLE `production_daily_log`
              ADD COLUMN `edit_count` int NOT NULL DEFAULT 0 AFTER `qty`,
              ADD COLUMN `updated_at` timestamp NULL DEFAULT NULL AFTER `created_at`
        ");
    } catch (Exception $e) { error_log('ensureProductionDailyLogEditColumns: ' . $e->getMessage()); }
}

/**
 * Append-only history of every correction made to a production_daily_log
 * entry — "reduced or added more on an existing entry" per the user's ask.
 * One row per edit (not per-log-entry) so a value corrected twice keeps
 * both corrections visible, not just the latest.
 */
function ensureProductionDailyLogEditsTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = Database::getInstance();
    try { $db->getPdo()->query("SELECT 1 FROM `production_daily_log_edits` LIMIT 1"); return; } catch (Exception $e) {}
    try {
        $db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS `production_daily_log_edits` (
              `id`        bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `log_id`    bigint UNSIGNED NOT NULL,
              `old_qty`   decimal(10,2) NOT NULL,
              `new_qty`   decimal(10,2) NOT NULL,
              `reason`    varchar(255) DEFAULT NULL,
              `user_id`   bigint UNSIGNED DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_pdle_log` (`log_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) { error_log('ensureProductionDailyLogEditsTable: ' . $e->getMessage()); }
}

/* ═══════════════ Telegram AI Query bot (Aug 2026) ═════════════════════════════
   Lets admins/superadmins ask a natural-language question in a dedicated,
   allow-listed Telegram group ("/ask <question>") and get an answer straight
   from the live database — reuses answerNaturalLanguageQuery() (the same
   engine behind the admin dashboard's "Ask DB" box) rather than a second
   implementation. Authorization is a flat Telegram-user-ID allow-list, NOT
   tied to the ERP's own role/privilege system — Telegram identity and ERP
   identity are separate namespaces with no existing mapping between them. */

function ensureTelegramAiAuthorizedUsersTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = Database::getInstance();
    try { $db->getPdo()->query("SELECT 1 FROM `telegram_ai_authorized_users` LIMIT 1"); return; } catch (Exception $e) {}
    try {
        $db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS `telegram_ai_authorized_users` (
              `id`                bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `telegram_user_id`  bigint NOT NULL,
              `telegram_username` varchar(64) DEFAULT NULL,
              `label`             varchar(120) DEFAULT NULL,
              `added_by_user_id`  bigint UNSIGNED DEFAULT NULL,
              `created_at`        timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_taau_telegram_user` (`telegram_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) { error_log('ensureTelegramAiAuthorizedUsersTable: ' . $e->getMessage()); }
}

function ensureTelegramAiQueryLogTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = Database::getInstance();
    try { $db->getPdo()->query("SELECT 1 FROM `telegram_ai_query_log` LIMIT 1"); return; } catch (Exception $e) {}
    try {
        $db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS `telegram_ai_query_log` (
              `id`                bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `telegram_user_id`  bigint NOT NULL,
              `telegram_username` varchar(64) DEFAULT NULL,
              `question`          text NOT NULL,
              `generated_sql`     text DEFAULT NULL,
              `row_count`         int DEFAULT NULL,
              `success`           tinyint(1) NOT NULL DEFAULT 0,
              `error_message`     text DEFAULT NULL,
              `authorized`        tinyint(1) NOT NULL DEFAULT 1,
              `created_at`        timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_taql_user` (`telegram_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) { error_log('ensureTelegramAiQueryLogTable: ' . $e->getMessage()); }
}

/** True if this Telegram numeric user id is on the allow-list. */
function isTelegramAiQueryAuthorized(int $telegram_user_id): bool {
    ensureTelegramAiAuthorizedUsersTable();
    $db = Database::getInstance();
    $row = $db->query("SELECT id FROM telegram_ai_authorized_users WHERE telegram_user_id = ?", [$telegram_user_id])->first();
    return (bool)$row;
}

/* ═══════════════ Commodity Trading dispatch (Jul 2026) ═══════════════════════
   Dedicated gate-pass / delivery-confirmation pipeline for commodity sales,
   mirroring the credit-sales cr_delivery_confirmations/cr_qr_scan_log pattern
   exactly (same two-stage flow, same HMAC-signed QR approach) but on its OWN
   tables — a commodity sale is not a credit_orders row, so it can't reuse
   those FK-shaped tables. Deliberately does NOT reuse credit_orders.status'
   lifecycle either: dispatch state lives entirely in
   commodity_dispatch_confirmations (gate_out_at/confirmed_at), keeping
   commodity_sales.status focused on approval state only, not delivery state. */

/** Signature for a commodity sale's delivery-confirmation QR (own salt namespace). */
function commodityDeliveryQrSignature(string $saleNumber): string {
    return substr(hash_hmac('sha256', 'CTDELIV|' . $saleNumber, getInvoiceQrSecret()), 0, 16);
}

function ensureCommodityDispatchConfirmTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `commodity_dispatch_confirmations` (
        `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `sale_id`               BIGINT UNSIGNED NOT NULL,
        `sale_number`           VARCHAR(50) NOT NULL,
        `gate_out_at`           TIMESTAMP NULL DEFAULT NULL,
        `gate_out_by_user_id`   BIGINT UNSIGNED NULL,
        `gate_out_by_name`      VARCHAR(120) NULL,
        `driver_name`           VARCHAR(150) NULL,
        `vehicle_number`        VARCHAR(100) NULL,
        `gate_note`             VARCHAR(500) NULL,
        `confirmed_at`          TIMESTAMP NULL DEFAULT NULL,
        `confirmed_by_user_id`  BIGINT UNSIGNED NULL,
        `confirmed_by_name`     VARCHAR(120) NULL,
        `received_by`           VARCHAR(150) NULL,
        `note`                  VARCHAR(500) NULL,
        `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_cdc_sale` (`sale_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ensureCommodityQrScanLogTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `commodity_qr_scan_log` (
        `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `sale_id`            BIGINT UNSIGNED NOT NULL,
        `sale_number`        VARCHAR(50) NULL,
        `stage`              VARCHAR(20) NULL,
        `reused`             TINYINT(1) NOT NULL DEFAULT 0,
        `scanned_by_user_id` BIGINT UNSIGNED NULL,
        `scanned_by_name`    VARCHAR(120) NULL,
        `ip`                 VARCHAR(64) NULL,
        `scanned_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_cqsl_sale` (`sale_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Log a commodity-sale QR scan; a scan on an already-delivered sale is flagged as reuse. */
function recordCommodityQrScan(int $sale_id, string $sale_number, string $stage, string $scanner): int {
    $db = Database::getInstance();
    ensureCommodityQrScanLogTable();
    $reused = ($stage === 'done') ? 1 : 0;
    try {
        $db->insert('commodity_qr_scan_log', [
            'sale_id' => $sale_id, 'sale_number' => $sale_number, 'stage' => $stage, 'reused' => $reused,
            'scanned_by_user_id' => (int)($_SESSION['user_id'] ?? 0), 'scanned_by_name' => $scanner,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        $row = $db->query("SELECT COUNT(*) AS c FROM commodity_qr_scan_log WHERE sale_id = ?", [$sale_id])->first();
        $total = (int)($row->c ?? 0);
        if ($reused) notifyCommodityQrReuse($sale_number, $scanner, $total);
        return $total;
    } catch (Exception $e) { error_log('recordCommodityQrScan: ' . $e->getMessage()); return 0; }
}

function notifyCommodityQrReuse(string $sale_number, string $scanner, int $total): void {
    if (!defined('TELEGRAM_NOTIFICATIONS_ENABLED') || !TELEGRAM_NOTIFICATIONS_ENABLED) return;
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID')) return;
    try {
        require_once dirname(__DIR__) . '/classes/TelegramNotifier.php';
        $msg = "<b>⚠ COMMODITY QR RE-SCANNED AFTER DELIVERY</b>\n"
             . "───────────────────────────────\n\n"
             . "• Sale: <code>{$sale_number}</code>\n"
             . "• Already delivered — scanned again by <b>{$scanner}</b>\n"
             . "• Total scans on this QR: <b>{$total}</b>\n\n"
             . "<i>Ujjal Flour Mills ERP</i>";
        (new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('dispatch')))->sendMessage($msg);
    } catch (\Throwable $e) { error_log('notifyCommodityQrReuse: ' . $e->getMessage()); }
}

/* ═══════════════ "Other Sales" — Trading commodities via Create Order (Jul 2026) ═══
   A new order-type toggle on cr/create_order.php that sells raw commodities
   (from the SAME sellable catalog/stock pools Trading uses) through the
   familiar Credit Sales screen, but with a shortcut lifecycle: no production,
   no manual "ready to ship" step — approval jumps the order straight to
   'ready_to_ship' so it's immediately eligible for Goods on Board, exactly
   like a normal flour order that's already been produced.

   credit_order_items.product_id/variant_id are NOT NULL, legacy-table
   constraints this codebase never loosens. Rather than relax them, each
   sellable commodity gets a lightweight "shadow" product+variant row
   (named identically to the commodity, status='inactive' so it never shows
   up in the normal product picker) — credit_order_items points at that valid
   product/variant, while the REAL commodity linkage (for weighted-average
   costing) lives in the new commodity_id/origin columns added here. */

/** Additive: is_other_sales flag on credit_orders + commodity linkage on credit_order_items. */
function ensureOtherSalesColumns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    try { $pdo->query("SELECT is_other_sales FROM credit_orders LIMIT 1"); }
    catch (Exception $e) { try { $pdo->exec("ALTER TABLE `credit_orders` ADD COLUMN `is_other_sales` TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e2) {} }

    try { $pdo->query("SELECT commodity_id FROM credit_order_items LIMIT 1"); }
    catch (Exception $e) { try { $pdo->exec("ALTER TABLE `credit_order_items` ADD COLUMN `commodity_id` BIGINT UNSIGNED NULL"); } catch (Exception $e2) {} }
    try { $pdo->query("SELECT origin FROM credit_order_items LIMIT 1"); }
    catch (Exception $e) { try { $pdo->exec("ALTER TABLE `credit_order_items` ADD COLUMN `origin` VARCHAR(120) NULL"); } catch (Exception $e2) {} }
    try { $pdo->query("SELECT cogs_amount FROM credit_order_items LIMIT 1"); }
    catch (Exception $e) { try { $pdo->exec("ALTER TABLE `credit_order_items` ADD COLUMN `cogs_amount` DECIMAL(14,2) NULL"); } catch (Exception $e2) {} }
    try { $pdo->query("SELECT source_purchase_order_id FROM credit_order_items LIMIT 1"); }
    catch (Exception $e) { try { $pdo->exec("ALTER TABLE `credit_order_items` ADD COLUMN `source_purchase_order_id` BIGINT UNSIGNED NULL"); } catch (Exception $e2) {} }

    // Marks a product/variant as an auto-provisioned stand-in for a commodity
    // rather than a real catalog item — lets UI pages hide it from pickers.
    try { $pdo->query("SELECT is_trading_shadow FROM products LIMIT 1"); }
    catch (Exception $e) { try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `is_trading_shadow` TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e2) {} }
}

/**
 * The shadow product+variant for a sellable commodity, auto-provisioned on
 * first use (idempotent — reuses the same pair every time). Named identically
 * to the commodity so every existing order-display page (notifications,
 * invoices, dispatch board, order view) shows the right thing with zero
 * special-casing, since it just reads a normal product/variant row.
 */
function getShadowProductForCommodity(int $commodity_id): array {
    ensureOtherSalesColumns();
    $db = Database::getInstance();
    $commodity = $db->query("SELECT * FROM purchase_commodities WHERE id = ?", [$commodity_id])->first();
    if (!$commodity) throw new Exception('Commodity not found.');

    $existing = $db->query(
        "SELECT p.id AS product_id, pv.id AS variant_id
         FROM products p JOIN product_variants pv ON pv.product_id = p.id
         WHERE p.is_trading_shadow = 1 AND p.base_sku = ? LIMIT 1",
        ["TRADING-SHADOW-{$commodity_id}"]
    )->first();
    if ($existing) return ['product_id' => (int)$existing->product_id, 'variant_id' => (int)$existing->variant_id];

    $product_id = $db->insert('products', [
        'base_name' => $commodity->name, 'base_sku' => "TRADING-SHADOW-{$commodity_id}",
        'description' => 'Auto-created stand-in for the Trading commodity "' . $commodity->name . '" — sold via Other Sales on Create Order. Not a real catalog product.',
        'category' => 'Other Sales (Trading)', 'status' => 'inactive', 'is_trading_shadow' => 1,
    ]);
    if (!$product_id) throw new Exception('Failed to provision the commodity stand-in product.');

    // unit_of_measure is a strict ENUM('Piece','Litre','kg','Meter') — map, don't guess.
    $uom = strtolower(trim((string)$commodity->unit)) === 'kg' ? 'kg' : 'Piece';
    $variant_id = $db->insert('product_variants', [
        'product_id' => $product_id, 'grade' => null, 'weight_variant' => 'Bulk',
        'unit_of_measure' => $uom, 'sku' => "TRADING-SHADOW-{$commodity_id}-V", 'status' => 'inactive',
    ]);
    if (!$variant_id) throw new Exception('Failed to provision the commodity stand-in variant.');

    return ['product_id' => (int)$product_id, 'variant_id' => (int)$variant_id];
}

/* ═══════════════ Related-Party Loans (Jul 2026) ═══════════════════════════════
   Some customers/suppliers/business partners (e.g. a sister concern funding
   tender participation) borrow cash from the company and repay it later — no
   goods change hands. Deliberately its OWN table, NOT posted into
   customer_ledger/supplier_ledger: none of those tables' transaction_type
   ENUM values ('invoice','payment','advance_payment','adjustment','credit_note',
   'debit_note','opening_balance') honestly describe "we lent you cash", and
   this codebase has hit real bugs before from overloading a mismatched ENUM
   value. A party's trading balance (AR/AP) and loan balance are therefore two
   separate, clearly-labeled numbers — never merged into one. */

/** Seeds the "Loans & Advances Receivable" GL account (Other Current Asset) — row only, no schema change. */
function ensureLoanAccounts(): int {
    $db = Database::getInstance();
    $acc = $db->query("SELECT id FROM chart_of_accounts WHERE name = 'Loans & Advances Receivable'")->first();
    if ($acc) return (int)$acc->id;
    $db->query(
        "INSERT INTO chart_of_accounts (account_number, account_type, normal_balance, status, name, description)
         VALUES ('1450', 'Other Current Asset', 'Debit', 'active', 'Loans & Advances Receivable', 'Cash advances/loans given to customers, suppliers, or related parties — repayable, not tied to goods')"
    );
    return (int)$db->getPdo()->lastInsertId();
}

function ensureLoansTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `loans` (
        `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `loan_number`         VARCHAR(50) NOT NULL,
        `customer_id`         BIGINT UNSIGNED NULL,
        `supplier_id`         BIGINT UNSIGNED NULL,
        `principal_amount`    DECIMAL(14,2) NOT NULL,
        `amount_repaid`       DECIMAL(14,2) NOT NULL DEFAULT 0,
        `balance_due`         DECIMAL(14,2) NOT NULL DEFAULT 0,
        `loan_date`           DATE NOT NULL,
        `expected_return_date` DATE NULL,
        `purpose`             VARCHAR(500) NULL,
        `status`              ENUM('pending_approval','active','closed','rejected') NOT NULL DEFAULT 'active',
        `notes`               TEXT NULL,
        `journal_entry_id`    BIGINT UNSIGNED NULL,
        `created_by_user_id`  BIGINT UNSIGNED NOT NULL,
        `approved_by_user_id` BIGINT UNSIGNED NULL,
        `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_loan_number` (`loan_number`),
        KEY `idx_loan_customer` (`customer_id`),
        KEY `idx_loan_supplier` (`supplier_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ensureLoanRepaymentsTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `loan_repayments` (
        `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `repayment_number`    VARCHAR(50) NOT NULL,
        `loan_id`             BIGINT UNSIGNED NOT NULL,
        `customer_id`         BIGINT UNSIGNED NULL,
        `supplier_id`         BIGINT UNSIGNED NULL,
        `amount`              DECIMAL(14,2) NOT NULL,
        `repayment_date`      DATE NOT NULL,
        `payment_method`      VARCHAR(50) NOT NULL,
        `bank_account_id`     BIGINT UNSIGNED NULL,
        `reference_number`    VARCHAR(100) NULL,
        `notes`               TEXT NULL,
        `journal_entry_id`    BIGINT UNSIGNED NULL,
        `created_by_user_id`  BIGINT UNSIGNED NOT NULL,
        `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_loanrepay_number` (`repayment_number`),
        KEY `idx_loanrepay_loan` (`loan_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Global "require approval for every loan disbursement" policy toggle (mirrors commoditySaleApprovalRequiredForAll). */
function loanDisbursementApprovalRequiredForAll(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $db = Database::getInstance();
    try {
        $row = $db->query("SELECT value FROM settings WHERE name = 'loan_disbursement_require_approval'")->first();
        $cached = $row === false || $row === null || $row->value === null ? true : ((string)$row->value === '1');
    } catch (Exception $e) {
        $cached = true; // fail safe: require approval rather than post unreviewed
    }
    return $cached;
}

/* ═══════════════ Exception radar + daily digest (owner visibility) ═══════════════ */

/**
 * "What needs a human" counts — shared by the dashboard tile and the daily digest.
 * Every query is defensive (missing table → 0).
 */
function getExceptionRadar(): array {
    $db = Database::getInstance();
    $c = function (string $sql, array $p = []) use ($db): int {
        try { return (int)($db->query($sql, $p)->first()->c ?? 0); } catch (Exception $e) { return 0; }
    };
    return [
        'pending_approvals' => $c("SELECT COUNT(*) c FROM cr_pending_requests WHERE status = 'pending'"),
        'escalated_orders'  => $c("SELECT COUNT(*) c FROM credit_orders WHERE status = 'escalated'"),
        'held_orders'       => $c("SELECT COUNT(*) c FROM order_approval_conditions oac
                                    JOIN credit_orders co ON co.id = oac.order_id
                                    WHERE oac.dispatch_hold = 1 AND oac.dispatch_cleared = 0
                                      AND co.status IN ('approved','in_production','produced','ready_to_ship','goods_on_board')"),
        'in_transit'        => $c("SELECT COUNT(*) c FROM credit_orders WHERE status IN ('goods_on_board','shipped')"),
        'pending_expenses'  => $c("SELECT COUNT(*) c FROM expense_vouchers WHERE status = 'pending'"),
        'qr_reuses'         => $c("SELECT COUNT(*) c FROM cr_qr_scan_log WHERE reused = 1 AND scanned_at >= (NOW() - INTERVAL 7 DAY)"),
        // pending_approvals already covers commodity_sale/commodity_payment/loan_* — cr_pending_requests is shared across all maker/checker types.
        'negative_stock'    => $c("SELECT COUNT(*) c FROM commodity_inventory WHERE quantity_on_hand < 0"),
        'overdue_loans'     => $c("SELECT COUNT(*) c FROM loans WHERE balance_due > 0.01 AND expected_return_date IS NOT NULL AND expected_return_date < CURDATE()"),
        // pending_approvals already covers pos_credit_sale (shared cr_pending_requests table).
        'pos_unverified_exits' => $c("SELECT COUNT(*) c FROM pos_exit_verifications WHERE verified_at IS NULL AND created_at < (NOW() - INTERVAL 2 HOUR)"),
        'pos_pending_deposits' => $c("SELECT COUNT(*) c FROM eod_summary WHERE cash_disposition = 'bank_deposit_pending' AND deposited_at IS NULL"),
    ];
}

/** Latest local DB-backup status string for the digest. */
function getBackupStatusLine(): string {
    if (!defined('DB_LOCAL_BACKUP_DIR') || !is_dir(DB_LOCAL_BACKUP_DIR)) return 'not configured';
    $files = glob(rtrim(DB_LOCAL_BACKUP_DIR, '/') . '/*');
    if (!$files) return '⚠ no backups found';
    $newest = max(array_map('filemtime', $files));
    return 'last ' . date('d M, H:i', $newest) . ($newest < strtotime('-2 days') ? ' ⚠ STALE' : '');
}

/**
 * Compose + send the daily owner digest to Telegram. Idempotent per day via
 * settings.last_owner_digest. $force bypasses the once-a-day + after-6am guard
 * (used by the cron entry point). Returns true if a message was sent.
 */
function sendDailyOwnerDigest(bool $force = false): bool {
    if (!defined('TELEGRAM_NOTIFICATIONS_ENABLED') || !TELEGRAM_NOTIFICATIONS_ENABLED) return false;
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID')) return false;
    $db = Database::getInstance();
    $today = date('Y-m-d');

    if (!$force) {
        if ((int)date('G') < 6) return false;                       // only auto-send after 6am
        try {
            $r = $db->query("SELECT value FROM settings WHERE name = 'last_owner_digest'")->first();
            if ($r && ($r->value ?? '') === $today) return false;   // already sent today
        } catch (Exception $e) { /* settings may be missing — proceed */ }
    }

    $radar = getExceptionRadar();
    $one = function (string $sql) use ($db) { try { return $db->query($sql)->first(); } catch (Exception $e) { return null; } };

    // The digest goes out in the MORNING → report YESTERDAY's business (today ≈ 0 at 7am).
    $pay = $one("SELECT COALESCE(SUM(amount),0) t,
                        COALESCE(SUM(CASE WHEN payment_method='Cash' THEN amount ELSE 0 END),0) cash,
                        COALESCE(SUM(CASE WHEN payment_method<>'Cash' THEN amount ELSE 0 END),0) bank,
                        COUNT(*) n
                 FROM customer_payments WHERE DATE(payment_date) = CURDATE() - INTERVAL 1 DAY");
    $ord = $one("SELECT COUNT(*) n, COALESCE(SUM(total_amount),0) v
                 FROM credit_orders WHERE DATE(order_date) = CURDATE() - INTERVAL 1 DAY AND status NOT IN ('draft','rejected','cancelled')");
    $overdue = [];
    try {
        $overdue = $db->query(
            "SELECT c.name, COALESCE(tb.b, c.initial_due, 0) AS bal
             FROM customers c
             LEFT JOIN (SELECT customer_id, balance_after AS b FROM customer_ledger
                        WHERE id IN (SELECT MAX(id) FROM customer_ledger GROUP BY customer_id)) tb ON tb.customer_id = c.id
             WHERE COALESCE(tb.b, c.initial_due, 0) > 0.01
             ORDER BY bal DESC LIMIT 3"
        )->results();
    } catch (Exception $e) {}

    $m  = "🌅 <b>Ujjal FM — Daily Digest</b>\n" . date('l, d M Y') . "\n";
    $m .= "───────────────────────────────\n";
    $m .= "💰 <b>Collected yesterday:</b> ৳" . number_format((float)($pay->t ?? 0), 0) . " (" . (int)($pay->n ?? 0) . ")\n";
    $m .= "   cash ৳" . number_format((float)($pay->cash ?? 0), 0) . " · bank ৳" . number_format((float)($pay->bank ?? 0), 0) . "\n";
    $m .= "🧾 <b>New orders yesterday:</b> " . (int)($ord->n ?? 0) . " · ৳" . number_format((float)($ord->v ?? 0), 0) . "\n\n";
    $m .= "⚠ <b>Needs attention</b>\n";
    $m .= "• " . $radar['pending_approvals'] . " payment(s) awaiting approval\n";
    $m .= "• " . $radar['escalated_orders']  . " order(s) escalated (over limit)\n";
    $m .= "• " . $radar['held_orders']       . " held for dispatch clearance\n";
    $m .= "• " . $radar['in_transit']        . " on board / shipped, not delivered\n";
    $m .= "• " . $radar['pending_expenses']  . " expense(s) pending\n";
    if ($radar['qr_reuses'] > 0) $m .= "• ⚠ " . $radar['qr_reuses'] . " QR re-scan(s) flagged (7d)\n";
    if (!empty($overdue)) {
        $m .= "\n🔴 <b>Top overdue</b>\n";
        $i = 1;
        foreach ($overdue as $o) $m .= ($i++) . ". " . htmlspecialchars($o->name) . " — ৳" . number_format((float)$o->bal, 0) . "\n";
    }
    $m .= "\n💾 Backup: " . getBackupStatusLine();

    try {
        require_once dirname(__DIR__) . '/classes/TelegramNotifier.php';
        (new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID))->sendMessage($m);
        // mark sent for today (works whether or not settings.name is unique)
        $ex = null;
        try { $ex = $db->query("SELECT id FROM settings WHERE name = 'last_owner_digest'")->first(); } catch (Exception $e) {}
        try {
            if ($ex) $db->query("UPDATE settings SET value = ? WHERE name = 'last_owner_digest'", [$today]);
            else     $db->query("INSERT INTO settings (name, value) VALUES ('last_owner_digest', ?)", [$today]);
        } catch (Exception $e) {}
        return true;
    } catch (\Throwable $e) {
        error_log('sendDailyOwnerDigest: ' . $e->getMessage());
        return false;
    }
}

/**
 * Hourly production-shortfall check — pings the 'production' Telegram group
 * with which products still need producing to cover TODAY's approved/
 * in-production orders, grouped by branch. Same dedup/fallback shape as
 * sendDailyOwnerDigest(): cron calls this with $force=true every hour from
 * 3am-11am (see scripts/production_shortfall_alert.php); a non-forced call
 * (wired into cr/production_requirement.php's page load) fires at most once
 * per hour-slot as a fallback if the cron job is missing or hasn't run yet.
 *
 * Skips sending entirely when nothing is required today (nothing to report),
 * but sends a positive "all covered" message when requirements exist and are
 * fully met — silence would be indistinguishable from "nobody checked."
 */
function sendProductionShortfallAlert(bool $force = false): bool {
    if (!defined('TELEGRAM_NOTIFICATIONS_ENABLED') || !TELEGRAM_NOTIFICATIONS_ENABLED) return false;
    if (!defined('TELEGRAM_BOT_TOKEN')) return false;
    $db = Database::getInstance();
    $today = date('Y-m-d');
    $hour  = (int)date('G');
    $slot  = $today . ' ' . date('H');   // once-per-hour dedup key

    if (!$force) {
        if ($hour < 3 || $hour > 11) return false;   // outside the 3am-11am window
        try {
            $r = $db->query("SELECT value FROM settings WHERE name = 'last_production_alert'")->first();
            if ($r && ($r->value ?? '') === $slot) return false;   // already sent this hour
        } catch (Exception $e) { /* settings may be missing — proceed */ }
    }

    ensureProductionDailyStockTable();

    $required = $db->query(
        "SELECT co.assigned_branch_id AS branch_id, b.name AS branch_name,
                pv.id AS variant_id, p.base_name AS product_name, pv.grade, pv.weight_variant, pv.unit_of_measure,
                SUM(coi.quantity) AS required_qty
         FROM credit_order_items coi
         JOIN credit_orders co ON coi.order_id = co.id
         JOIN product_variants pv ON coi.variant_id = pv.id
         JOIN products p ON coi.product_id = p.id
         LEFT JOIN branches b ON co.assigned_branch_id = b.id
         WHERE co.required_date = ? AND co.status IN ('approved','in_production') AND co.assigned_branch_id IS NOT NULL
         GROUP BY co.assigned_branch_id, pv.id
         ORDER BY b.name, p.base_name, pv.grade, pv.weight_variant",
        [$today]
    )->results();

    if (empty($required)) return false;   // nothing due today — nothing to report

    $stock_rows = $db->query(
        "SELECT branch_id, variant_id, in_hand_qty, produced_qty FROM production_daily_stock WHERE production_date = ?",
        [$today]
    )->results();
    $stock_by_key = [];
    foreach ($stock_rows as $s) { $stock_by_key[$s->branch_id . ':' . $s->variant_id] = $s; }

    $by_branch = [];
    foreach ($required as $r) {
        $key   = $r->branch_id . ':' . $r->variant_id;
        $stock = $stock_by_key[$key] ?? null;
        $available    = ($stock ? (float)$stock->in_hand_qty + (float)$stock->produced_qty : 0.0);
        $still_needed = max(round((float)$r->required_qty - $available, 2), 0);
        $by_branch[$r->branch_name ?? 'Unassigned'][] = [
            'label'        => trim(($r->grade ? $r->grade . ' ' : '') . $r->weight_variant . $r->unit_of_measure),
            'product_name' => $r->product_name,
            'still_needed' => $still_needed,
            'weight'       => ($r->unit_of_measure === 'kg' && is_numeric($r->weight_variant))
                ? $still_needed * (float)$r->weight_variant : null,
        ];
    }

    $m  = "🏭 <b>Production Shortfall Check</b> — " . date('h:i A') . "\n" . date('l, d M Y') . "\n";
    $m .= "───────────────────────────────\n";
    $any_shortfall = false;
    foreach ($by_branch as $branch_name => $items) {
        $shortfalls = array_filter($items, fn($i) => $i['still_needed'] > 0);
        $covered_n  = count($items) - count($shortfalls);
        $m .= "\n📍 <b>" . htmlspecialchars($branch_name) . "</b>\n";
        if (empty($shortfalls)) {
            $m .= "✅ All " . count($items) . " product(s) covered\n";
            continue;
        }
        $any_shortfall = true;
        if ($covered_n > 0) $m .= "✅ {$covered_n} product(s) covered\n";
        foreach ($shortfalls as $i) {
            $m .= "⚠ " . htmlspecialchars($i['product_name'] . ' (' . $i['label'] . ')')
                . ": need " . number_format($i['still_needed'], 2) . " more bag"
                . ($i['weight'] !== null ? ' (' . number_format($i['weight'], 1) . ' kg)' : '') . "\n";
        }
    }
    if (!$any_shortfall) $m .= "\n🎉 Nothing outstanding right now.";

    try {
        require_once dirname(__DIR__) . '/classes/TelegramNotifier.php';
        (new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('production')))->sendMessage($m);
        $ex = null;
        try { $ex = $db->query("SELECT id FROM settings WHERE name = 'last_production_alert'")->first(); } catch (Exception $e) {}
        try {
            if ($ex) $db->query("UPDATE settings SET value = ? WHERE name = 'last_production_alert'", [$slot]);
            else     $db->query("INSERT INTO settings (name, value) VALUES ('last_production_alert', ?)", [$slot]);
        } catch (Exception $e) {}
        return true;
    } catch (\Throwable $e) {
        error_log('sendProductionShortfallAlert: ' . $e->getMessage());
        return false;
    }
}

/* ═══════════════ Stock adjustments (Feature #7) ═══════════════
   Create (Accounts/Sales) → approve by a DIFFERENT can_approve user → applies the
   inventory delta AND posts a journal entry. Value uses a creator-entered unit
   cost (the system has no reliable finished-goods cost basis). */

/**
 * Reverse a posted customer payment INSIDE a caller-managed transaction, archiving
 * everything to a recycle batch so it stays restorable. Used by customer_payment.php
 * (Edit = reverse old + repost corrected).
 *
 * NOTE: delete_payment.php intentionally keeps its own inline equivalent of steps
 * 1–7 (the working Delete flow, left untouched). This function is a faithful copy
 * of that logic — if you change the reversal accounting, update BOTH places.
 *
 * Caller MUST: open the transaction, and call ensureRecycleBinTables() BEFORE the
 * transaction (DDL implicit-commits). This function neither begins nor commits.
 *
 * Returns ['batch','payment_number','customer_id','amount','allocations','pay'] for
 * the caller's audit/notification. Throws on a missing payment.
 */
function reverseCustomerPaymentInTxn(Database $db, int $payment_id, string $batchLabel = 'Payment'): array {
    $pay = $db->query(
        "SELECT cp.*, c.name AS customer_name, c.initial_due
         FROM customer_payments cp JOIN customers c ON cp.customer_id = c.id
         WHERE cp.id = ? FOR UPDATE",
        [$payment_id]
    )->first();
    if (!$pay) throw new Exception("Payment #$payment_id not found");

    $customer_id    = (int)$pay->customer_id;
    $payment_number = $pay->payment_number;
    $pay_amount     = (float)$pay->amount;

    $batch = recycleBegin('payment',
        "{$batchLabel}: {$payment_number} — {$pay->customer_name} · ৳" . number_format($pay_amount, 2),
        $customer_id);

    // 2. Reverse payment_allocations → restore order balances.
    $allocations = $db->query(
        "SELECT order_id, allocated_amount FROM payment_allocations WHERE payment_id = ?",
        [$payment_id]
    )->results();
    if (empty($allocations) && !empty($pay->allocated_to_invoices)) {
        foreach ((json_decode($pay->allocated_to_invoices, true) ?: []) as $oid => $amt) {
            if ((int)$oid > 0 && (float)$amt > 0) {
                $a = new stdClass(); $a->order_id = (int)$oid; $a->allocated_amount = (float)$amt;
                $allocations[] = $a;
            }
        }
    }
    foreach ($allocations as $alloc) {
        recycleSnapshotBefore($batch, 'credit_orders', 'id', (int)$alloc->order_id);
        $db->query(
            "UPDATE credit_orders
             SET amount_paid = GREATEST(0, amount_paid - ?),
                 balance_due = total_amount - amount_paid
             WHERE id = ?",
            [(float)$alloc->allocated_amount, (int)$alloc->order_id]
        );
    }
    recycleArchiveDelete($batch, 'payment_allocations', 'payment_id', $payment_id);

    // 3. Delete customer_ledger entry/entries and recompute the subsequent chain.
    $ledger_entries = $db->query(
        "SELECT id FROM customer_ledger
         WHERE reference_type = 'customer_payments' AND reference_id = ?
         ORDER BY id ASC",
        [$payment_id]
    )->results();
    if (!empty($ledger_entries)) {
        $first_le_id = $ledger_entries[0]->id;
        $last_le_id  = end($ledger_entries)->id;
        foreach ($ledger_entries as $le) {
            recycleArchiveDelete($batch, 'customer_ledger', 'id', (int)$le->id);
        }
        $agg_dp = $db->query(
            "SELECT COALESCE(SUM(debit_amount),0) AS td, COALESCE(SUM(credit_amount),0) AS tc
             FROM customer_ledger WHERE customer_id = ? AND id < ?",
            [$customer_id, $first_le_id]
        )->first();
        $agg_dp_td = (float)($agg_dp->td ?? 0);
        $agg_dp_tc = (float)($agg_dp->tc ?? 0);
        if ($agg_dp_td > 0 || $agg_dp_tc > 0) {
            $ob_chk = $db->query(
                "SELECT 1 FROM customer_ledger WHERE customer_id = ? AND reference_type = 'initial_due' LIMIT 1",
                [$customer_id]
            )->first();
            $running = ($ob_chk ? 0.0 : (float)($db->query("SELECT initial_due FROM customers WHERE id = ?", [$customer_id])->first()->initial_due ?? 0))
                     + $agg_dp_td - $agg_dp_tc;
        } else {
            $running = (float)($db->query("SELECT initial_due FROM customers WHERE id = ?", [$customer_id])->first()->initial_due ?? 0);
        }
        $subsequent = $db->query(
            "SELECT id, debit_amount, credit_amount FROM customer_ledger
             WHERE customer_id = ? AND id > ? ORDER BY transaction_date ASC, id ASC",
            [$customer_id, $last_le_id]
        )->results();
        foreach ($subsequent as $sub) {
            recycleSnapshotBefore($batch, 'customer_ledger', 'id', (int)$sub->id);
            $running += (float)$sub->debit_amount - (float)$sub->credit_amount;
            $db->query("UPDATE customer_ledger SET balance_after = ? WHERE id = ?", [$running, $sub->id]);
        }
    }

    // 4. Sync customers.current_balance from ledger truth.
    recycleSnapshotBefore($batch, 'customers', 'id', $customer_id);
    $last_le = $db->query(
        "SELECT balance_after FROM customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1",
        [$customer_id]
    )->first();
    if ($last_le) {
        $db->query("UPDATE customers SET current_balance = ? WHERE id = ?", [(float)$last_le->balance_after, $customer_id]);
    } else {
        $init = $db->query("SELECT initial_due FROM customers WHERE id = ?", [$customer_id])->first();
        $db->query("UPDATE customers SET current_balance = ? WHERE id = ?", [(float)($init->initial_due ?? 0), $customer_id]);
    }

    // 5. Delete journal entry + lines.
    foreach ($db->query(
        "SELECT id FROM journal_entries WHERE related_document_type = 'customer_payments' AND related_document_id = ?",
        [$payment_id]
    )->results() as $je) {
        recycleArchiveDelete($batch, 'transaction_lines', 'journal_entry_id', (int)$je->id);
        recycleArchiveDelete($batch, 'journal_entries',   'id',               (int)$je->id);
    }

    // 6. Void the pending bank_transactions bridge row.
    foreach ($db->query(
        "SELECT id FROM bank_transactions WHERE reference_number = ? AND status = 'pending'",
        [$payment_number]
    )->results() as $bt) {
        recycleArchiveDelete($batch, 'bank_transactions', 'id', (int)$bt->id);
    }

    // 7. Archive the payment record itself.
    recycleArchiveDelete($batch, 'customer_payments', 'id', $payment_id);
    recycleFinalize($batch);

    return [
        'batch'          => $batch,
        'payment_number' => $payment_number,
        'customer_id'    => $customer_id,
        'amount'         => $pay_amount,
        'allocations'    => $allocations,
        'pay'            => $pay,
    ];
}

/**
 * Procurement catalog (Phase 1) — self-migrating schema for multi-commodity
 * purchasing: commodities, per-commodity origins, supplier↔commodity links, plus
 * commodity_id/unit columns on purchase_orders_adnan. Seeds a "Wheat" commodity
 * (with the legacy origin list) and backfills every existing PO to it, so all
 * current purchase pages keep working unchanged. Probes with raw PDO (the Database
 * wrapper swallows errors) and runs at most once per request.
 */
function ensureProcurementCatalogTables(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db  = Database::getInstance();
    $pdo = $db->getPdo();

    $pdo->exec("CREATE TABLE IF NOT EXISTS `purchase_commodities` (
        `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name`                 VARCHAR(120) NOT NULL,
        `unit`                 VARCHAR(20)  NOT NULL DEFAULT 'KG',
        `inventory_account_id` BIGINT UNSIGNED NULL,
        `notes`                VARCHAR(255) NULL,
        `status`               ENUM('active','inactive') NOT NULL DEFAULT 'active',
        `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_commodity_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `purchase_commodity_origins` (
        `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `commodity_id` BIGINT UNSIGNED NOT NULL,
        `origin_name`  VARCHAR(120) NOT NULL,
        `status`       ENUM('active','inactive') NOT NULL DEFAULT 'active',
        `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_pco` (`commodity_id`,`origin_name`),
        KEY `idx_pco_commodity` (`commodity_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `supplier_commodities` (
        `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `supplier_id`  BIGINT UNSIGNED NOT NULL,
        `commodity_id` BIGINT UNSIGNED NOT NULL,
        `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_sc` (`supplier_id`,`commodity_id`),
        KEY `idx_sc_supplier` (`supplier_id`),
        KEY `idx_sc_commodity` (`commodity_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Add commodity_id + unit to the (existing) single-line PO table. Probe first.
    try { $pdo->query("SELECT commodity_id FROM purchase_orders_adnan LIMIT 1"); }
    catch (Exception $e) { try { $pdo->exec("ALTER TABLE `purchase_orders_adnan` ADD COLUMN `commodity_id` BIGINT UNSIGNED NULL"); } catch (Exception $e2) {} }
    try { $pdo->query("SELECT unit FROM purchase_orders_adnan LIMIT 1"); }
    catch (Exception $e) { try { $pdo->exec("ALTER TABLE `purchase_orders_adnan` ADD COLUMN `unit` VARCHAR(20) NOT NULL DEFAULT 'KG'"); } catch (Exception $e2) {} }

    // Seed the Wheat commodity + its legacy origins.
    $wheat = $db->query("SELECT id FROM purchase_commodities WHERE name = 'Wheat'")->first();
    if ($wheat) {
        $wheat_id = (int)$wheat->id;
    } else {
        $db->query("INSERT INTO purchase_commodities (name, unit, status) VALUES ('Wheat','KG','active')");
        $wheat_id = (int)$pdo->lastInsertId();
    }
    if ($wheat_id) {
        $has = $db->query("SELECT id FROM purchase_commodity_origins WHERE commodity_id = ? LIMIT 1", [$wheat_id])->first();
        if (!$has) {
            foreach (['কানাডা','রাশিয়া','Argentina','Brazil','Australia','Ukraine','India','USA','Local','Other'] as $o) {
                try { $db->query("INSERT INTO purchase_commodity_origins (commodity_id, origin_name) VALUES (?, ?)", [$wheat_id, $o]); } catch (Exception $e) {}
            }
        }
        // Backfill every existing PO to Wheat/KG so current reports are unaffected.
        try { $db->query("UPDATE purchase_orders_adnan SET commodity_id = ? WHERE commodity_id IS NULL", [$wheat_id]); } catch (Exception $e) {}
        try { $db->query("UPDATE purchase_orders_adnan SET unit = 'KG' WHERE unit IS NULL OR unit = ''"); } catch (Exception $e) {}
    }
}

/* ═══════════════ Commodity Trading (Jul 2026) ═══════════════════════════════
   Buying wheat for milling, but also reselling surplus wheat (and future
   commodities) directly — some buyers are existing credit customers, some are
   new, and some parties are BOTH a customer and a supplier (netting needed).
   Design: /Users/apple/Desktop/saas/COMMODITY_TRADING_PLAN.md
   Additive only — no existing table is altered destructively, no existing FK
   is touched. Business Partner is a LINKING layer, not a merge. */

/**
 * business_partners = canonical identity when the same real-world party is
 * BOTH a customer and a supplier. customers.business_partner_id and
 * suppliers.business_partner_id are nullable link columns — every existing
 * page keeps working completely unchanged; this sits purely on top.
 */
function ensureBusinessPartnersTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db  = Database::getInstance();
    $pdo = $db->getPdo();

    $pdo->exec("CREATE TABLE IF NOT EXISTS `business_partners` (
        `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `partner_name` VARCHAR(255) NOT NULL,
        `business_name` VARCHAR(255) NULL,
        `phone_number` VARCHAR(20) NULL,
        `address`      TEXT NULL,
        `notes`        TEXT NULL,
        `status`       ENUM('active','inactive') NOT NULL DEFAULT 'active',
        `created_by_user_id` BIGINT UNSIGNED NULL,
        `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Additive link columns — probe first, never ALTER unconditionally.
    try { $pdo->query("SELECT business_partner_id FROM customers LIMIT 1"); }
    catch (Exception $e) { try { $pdo->exec("ALTER TABLE `customers` ADD COLUMN `business_partner_id` BIGINT UNSIGNED NULL"); } catch (Exception $e2) {} }
    try { $pdo->query("SELECT business_partner_id FROM suppliers LIMIT 1"); }
    catch (Exception $e) { try { $pdo->exec("ALTER TABLE `suppliers` ADD COLUMN `business_partner_id` BIGINT UNSIGNED NULL"); } catch (Exception $e2) {} }
}

/**
 * Both-sides-linked partners only — these are the ones eligible for netting.
 * Returns rows: business_partner_id, partner_name, customer_id, customer_name,
 * customer_balance (true AR), supplier_id, supplier_name, supplier_balance (AP).
 */
function getLinkedBusinessPartners(): array {
    $db = Database::getInstance();
    ensureBusinessPartnersTable();
    try {
        return $db->query(
            "SELECT bp.id AS business_partner_id, bp.partner_name,
                    c.id AS customer_id, c.name AS customer_name,
                    COALESCE(c.initial_due,0) + COALESCE(cl.d,0) - COALESCE(cl.c,0) AS customer_balance,
                    s.id AS supplier_id, s.company_name AS supplier_name,
                    s.current_balance AS supplier_balance
             FROM business_partners bp
             JOIN customers c ON c.business_partner_id = bp.id
             JOIN suppliers s ON s.business_partner_id = bp.id
             LEFT JOIN (
                 SELECT customer_id, SUM(debit_amount) d, SUM(credit_amount) c
                 FROM customer_ledger WHERE reference_type != 'initial_due'
                 GROUP BY customer_id
             ) cl ON cl.customer_id = c.id
             WHERE bp.status = 'active'
             ORDER BY bp.partner_name ASC"
        )->results();
    } catch (Exception $e) { return []; }
}

/**
 * Extends the Procurement Catalog (Phase 1/2 multi-commodity purchase work)
 * with a sellable flag — the SAME commodity master serves both buying and
 * selling, per the locked design decision. Purchasing stays implicit/always-on.
 */
function ensureCommodityIsSellableColumn(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    try { $pdo->query("SELECT is_sellable FROM purchase_commodities LIMIT 1"); }
    catch (Exception $e) { try { $pdo->exec("ALTER TABLE `purchase_commodities` ADD COLUMN `is_sellable` TINYINT(1) NOT NULL DEFAULT 1"); } catch (Exception $e2) {} }
}

/**
 * commodity_inventory — quantity + weighted-average cost per commodity×branch.
 * No lot-tracking; moving-average costing (the standard method for a trading
 * operation without per-batch cost visibility). Distinct from the finished-
 * goods `inventory` table (which has no cost basis at all, by design).
 */
function ensureCommodityInventoryTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `commodity_inventory` (
        `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `commodity_id`     BIGINT UNSIGNED NOT NULL,
        `branch_id`        BIGINT UNSIGNED NOT NULL,
        `quantity_on_hand` DECIMAL(14,3) NOT NULL DEFAULT 0,
        `weighted_avg_cost` DECIMAL(14,4) NOT NULL DEFAULT 0,
        `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_ci_commodity_branch` (`commodity_id`,`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Origin split (Jul 2026): different origins of the same commodity (e.g.
    // Canadian vs Australian wheat) carry different costs — each origin now
    // keeps its OWN stock pool at each branch, not a blended average. '' (not
    // NULL) is the "no origin tracked" bucket, because MySQL treats multiple
    // NULLs in a unique key as distinct — using NULL there would silently let
    // duplicate commodity+branch rows slip in.
    try { $pdo->query("SELECT origin FROM commodity_inventory LIMIT 1"); }
    catch (Exception $e) {
        try { $pdo->exec("ALTER TABLE `commodity_inventory` ADD COLUMN `origin` VARCHAR(120) NOT NULL DEFAULT '' AFTER `branch_id`"); } catch (Exception $e2) {}
    }
    // Re-key uniqueness to include origin (old key would collide two origins
    // of the same commodity+branch into one row).
    try {
        $idx = $pdo->query("SHOW INDEX FROM `commodity_inventory` WHERE Key_name = 'uk_ci_commodity_branch_origin'")->fetchAll();
        if (empty($idx)) {
            try { $pdo->exec("ALTER TABLE `commodity_inventory` DROP INDEX `uk_ci_commodity_branch`"); } catch (Exception $e2) {}
            try { $pdo->exec("ALTER TABLE `commodity_inventory` ADD UNIQUE KEY `uk_ci_commodity_branch_origin` (`commodity_id`,`branch_id`,`origin`)"); } catch (Exception $e3) {}
        }
    } catch (Exception $e) {}
}

/** Current on-hand + avg cost for one commodity at one branch (+ optional origin pool). */
function getCommodityInventory(int $commodity_id, int $branch_id, string $origin = ''): array {
    ensureCommodityInventoryTable();
    $db = Database::getInstance();
    $row = $db->query(
        "SELECT quantity_on_hand, weighted_avg_cost FROM commodity_inventory WHERE commodity_id = ? AND branch_id = ? AND origin = ?",
        [$commodity_id, $branch_id, $origin]
    )->first();
    return [
        'quantity_on_hand'  => $row ? (float)$row->quantity_on_hand  : 0.0,
        'weighted_avg_cost' => $row ? (float)$row->weighted_avg_cost : 0.0,
    ];
}

/** Every origin pool recorded for one commodity at one branch (for "stock by origin" views). */
function getCommodityInventoryByOrigin(int $commodity_id, int $branch_id): array {
    ensureCommodityInventoryTable();
    $db = Database::getInstance();
    return $db->query(
        "SELECT origin, quantity_on_hand, weighted_avg_cost FROM commodity_inventory
         WHERE commodity_id = ? AND branch_id = ? ORDER BY origin ASC",
        [$commodity_id, $branch_id]
    )->results();
}

/**
 * Moving-average cost update on a purchase receipt (GRN). Called as a
 * best-effort follow-up AFTER the GRN itself commits — the GRN is the source
 * of truth for the purchase; a costing hiccup shouldn't roll back a receipt
 * that already happened. Safe to skip (returns false) if commodity/branch/qty
 * are missing — most legacy (non-commodity-tagged) POs have no commodity_id.
 */
function postCommodityGRNCost(?int $commodity_id, ?int $branch_id, float $received_qty, float $unit_cost, string $origin = ''): bool {
    if (!$commodity_id || !$branch_id || $received_qty <= 0) return false;
    ensureCommodityInventoryTable();
    $db  = Database::getInstance();
    $cur = getCommodityInventory($commodity_id, $branch_id, $origin);
    $new_qty = $cur['quantity_on_hand'] + $received_qty;
    $new_avg = $new_qty > 0
        ? (($cur['quantity_on_hand'] * $cur['weighted_avg_cost']) + ($received_qty * $unit_cost)) / $new_qty
        : 0.0;
    try {
        $db->query(
            "INSERT INTO commodity_inventory (commodity_id, branch_id, origin, quantity_on_hand, weighted_avg_cost)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity_on_hand = VALUES(quantity_on_hand), weighted_avg_cost = VALUES(weighted_avg_cost)",
            [$commodity_id, $branch_id, $origin, $new_qty, $new_avg]
        );
        return true;
    } catch (Exception $e) {
        error_log('postCommodityGRNCost: ' . $e->getMessage());
        return false;
    }
}

/**
 * Decrements inventory on a sale (over-sell is the caller's responsibility to
 * warn/confirm — this function does not block it, per the warn+override
 * design decision; it will let quantity_on_hand go negative if forced).
 * Returns the COGS = sold_qty × the cost locked in at the moment of sale.
 */
function postCommoditySaleCost(int $commodity_id, int $branch_id, float $sold_qty, string $origin = ''): float {
    ensureCommodityInventoryTable();
    $db  = Database::getInstance();
    $cur = getCommodityInventory($commodity_id, $branch_id, $origin);
    $cogs = $sold_qty * $cur['weighted_avg_cost'];
    $new_qty = $cur['quantity_on_hand'] - $sold_qty;
    try {
        $db->query(
            "INSERT INTO commodity_inventory (commodity_id, branch_id, origin, quantity_on_hand, weighted_avg_cost)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity_on_hand = VALUES(quantity_on_hand)",
            [$commodity_id, $branch_id, $origin, $new_qty, $cur['weighted_avg_cost']]
        );
    } catch (Exception $e) {
        error_log('postCommoditySaleCost: ' . $e->getMessage());
    }
    return $cogs;
}

/**
 * commodity_sales — the dedicated sale record (NOT overloaded onto
 * credit_orders, whose order_type is a strict ENUM tied to finished-goods/
 * production assumptions that don't fit a raw-commodity trade).
 */
function ensureCommoditySalesTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `commodity_sales` (
        `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `sale_number`         VARCHAR(50) NOT NULL,
        `customer_id`         BIGINT UNSIGNED NOT NULL,
        `commodity_id`        BIGINT UNSIGNED NOT NULL,
        `branch_id`           BIGINT UNSIGNED NOT NULL,
        `sale_date`           DATE NOT NULL,
        `quantity`            DECIMAL(14,3) NOT NULL,
        `unit_price`          DECIMAL(14,4) NOT NULL,
        `total_amount`        DECIMAL(14,2) NOT NULL,
        `cogs_amount`         DECIMAL(14,2) NOT NULL DEFAULT 0,
        `advance_paid`        DECIMAL(14,2) NOT NULL DEFAULT 0,
        `amount_paid`         DECIMAL(14,2) NOT NULL DEFAULT 0,
        `balance_due`         DECIMAL(14,2) NOT NULL DEFAULT 0,
        `stock_overridden`    TINYINT(1) NOT NULL DEFAULT 0,
        `status`              ENUM('pending_approval','approved','rejected') NOT NULL DEFAULT 'approved',
        `notes`               TEXT NULL,
        `journal_entry_id`    BIGINT UNSIGNED NULL,
        `created_by_user_id`  BIGINT UNSIGNED NOT NULL,
        `approved_by_user_id` BIGINT UNSIGNED NULL,
        `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_cs_sale_number` (`sale_number`),
        KEY `idx_cs_customer` (`customer_id`),
        KEY `idx_cs_commodity` (`commodity_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Which origin pool this sale drew from — '' means "not tracked" (matches
    // commodity_inventory's no-origin bucket).
    try { $pdo->query("SELECT origin FROM commodity_sales LIMIT 1"); }
    catch (Exception $e) { try { $pdo->exec("ALTER TABLE `commodity_sales` ADD COLUMN `origin` VARCHAR(120) NOT NULL DEFAULT '' AFTER `commodity_id`"); } catch (Exception $e2) {} }

    // Optional traceability tag back to the Purchase Order this stock was
    // originally procured under — purely informational, no FK enforced (this
    // codebase never enforces cross-table FKs, per its own convention).
    try { $pdo->query("SELECT source_purchase_order_id FROM commodity_sales LIMIT 1"); }
    catch (Exception $e) { try { $pdo->exec("ALTER TABLE `commodity_sales` ADD COLUMN `source_purchase_order_id` BIGINT UNSIGNED NULL AFTER `origin`"); } catch (Exception $e2) {} }
}

/**
 * Payments collected against a commodity_sales balance. Deliberately its OWN
 * table, NOT a customer_payments row — customer_payments.allocated_to_invoices
 * is parsed by delete_payment.php/reverseCustomerPaymentInTxn() as a
 * {credit_order_id: amount} map; reusing that table for a commodity-sale
 * reference would risk those reversal paths misinterpreting it. The
 * customer_ledger entry (the thing that actually matters for the customer's
 * true balance) is posted identically either way.
 */
function ensureCommoditySalePaymentsTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `commodity_sale_payments` (
        `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `payment_number`     VARCHAR(50) NOT NULL,
        `sale_id`             BIGINT UNSIGNED NOT NULL,
        `customer_id`         BIGINT UNSIGNED NOT NULL,
        `amount`              DECIMAL(14,2) NOT NULL,
        `payment_method`      VARCHAR(50) NOT NULL,
        `bank_account_id`     BIGINT UNSIGNED NULL,
        `reference_number`    VARCHAR(100) NULL,
        `notes`               TEXT NULL,
        `customer_ledger_id`  BIGINT UNSIGNED NULL,
        `journal_entry_id`    BIGINT UNSIGNED NULL,
        `created_by_user_id`  BIGINT UNSIGNED NOT NULL,
        `payment_date`        DATE NOT NULL,
        `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_csp_payment_number` (`payment_number`),
        KEY `idx_csp_sale` (`sale_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Audit trail for every commodity-sale edit — both the diff shown to a
 * checker/on the View page timeline, AND the maker/checker link (old_sale_id
 * -> new_sale_id) that lets view_commodity_sale.php walk a sale's full
 * edit history even after it's been replaced. One row per edit ATTEMPT
 * (pending/approved/rejected), not per field change.
 */
function ensureCommoditySaleEditsTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `commodity_sale_edits` (
        `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `old_sale_id`           BIGINT UNSIGNED NOT NULL,
        `old_sale_number`       VARCHAR(50) NOT NULL,
        `new_sale_id`           BIGINT UNSIGNED NULL,
        `new_sale_number`       VARCHAR(50) NULL,
        `change_summary`        TEXT NOT NULL,
        `reason`                VARCHAR(500) NULL,
        `status`                ENUM('pending_approval','approved','rejected') NOT NULL DEFAULT 'pending_approval',
        `requested_by_user_id`  BIGINT UNSIGNED NOT NULL,
        `decided_by_user_id`    BIGINT UNSIGNED NULL,
        `decided_at`            TIMESTAMP NULL,
        `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_cse_old_sale` (`old_sale_id`),
        KEY `idx_cse_new_sale` (`new_sale_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Field-by-field diff between a live commodity_sales row and a proposed new
 * set of values — used both to decide "did anything actually change?" and as
 * the change_summary JSON stored on commodity_sale_edits / shown to checkers.
 */
function diffCommoditySaleFields(object $old, array $new): array {
    $labels = [
        'customer_id' => 'Customer', 'commodity_id' => 'Commodity', 'branch_id' => 'Branch', 'origin' => 'Origin',
        'sale_date' => 'Sale Date', 'quantity' => 'Quantity', 'unit_price' => 'Unit Price', 'advance_paid' => 'Advance Paid',
    ];
    $diff = [];
    foreach ($labels as $key => $label) {
        $oldVal = $old->{$key} ?? null;
        $newVal = $new[$key] ?? null;
        $changed = is_float($oldVal) || is_numeric($oldVal)
            ? abs((float)$oldVal - (float)$newVal) > 0.0001
            : (string)$oldVal !== (string)$newVal;
        if ($changed) {
            $diff[$key] = ['label' => $label, 'old' => $oldVal, 'new' => $newVal];
        }
    }
    return $diff;
}

/** Seeds the two new GL accounts this feature needs — rows only, no schema change
 *  (chart_of_accounts.account_type already has 'Revenue' and 'Cost of Goods Sold'
 *  in its ENUM). Commodity inventory itself reuses each commodity's own
 *  inventory_account_id — no new account needed there. */
function ensureCommodityTradingAccounts(): array {
    $db = Database::getInstance();
    $rev = $db->query("SELECT id FROM chart_of_accounts WHERE name = 'Commodity Trading Revenue'")->first();
    if (!$rev) {
        $db->query(
            "INSERT INTO chart_of_accounts (account_number, account_type, normal_balance, status, name, description)
             VALUES ('4900', 'Revenue', 'Credit', 'active', 'Commodity Trading Revenue', 'Revenue from direct resale of purchased commodities (e.g. surplus wheat), kept separate from milled-flour Credit Sales Revenue')"
        );
        $rev_id = (int)$db->getPdo()->lastInsertId();
    } else {
        $rev_id = (int)$rev->id;
    }
    $cogs = $db->query("SELECT id FROM chart_of_accounts WHERE name = 'Commodity Cost of Goods Sold'")->first();
    if (!$cogs) {
        $db->query(
            "INSERT INTO chart_of_accounts (account_number, account_type, normal_balance, status, name, description)
             VALUES ('5900', 'Cost of Goods Sold', 'Debit', 'active', 'Commodity Cost of Goods Sold', 'Weighted-average cost of commodities sold via direct resale')"
        );
        $cogs_id = (int)$db->getPdo()->lastInsertId();
    } else {
        $cogs_id = (int)$cogs->id;
    }
    return ['revenue_account_id' => $rev_id, 'cogs_account_id' => $cogs_id];
}

/**
 * Records each posted contra/netting entry between a linked Business Partner's
 * AR (customer_ledger) and AP (supplier_ledger) sides, so it can be listed and
 * — for the simple, common case — reversed. The settlement row itself is never
 * deleted (status flips to 'reversed'); the underlying ledger/journal rows ARE
 * archived to the Recycle Bin on reversal, same as every other reversal in the app.
 */
function ensureBusinessPartnerSettlementsTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `business_partner_settlements` (
        `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `business_partner_id` BIGINT UNSIGNED NOT NULL,
        `customer_id`         BIGINT UNSIGNED NOT NULL,
        `supplier_id`         BIGINT UNSIGNED NOT NULL,
        `offset_amount`       DECIMAL(14,2) NOT NULL,
        `note`                TEXT NOT NULL,
        `customer_ledger_id`  BIGINT UNSIGNED NULL,
        `supplier_ledger_id`  BIGINT UNSIGNED NULL,
        `journal_entry_id`    BIGINT UNSIGNED NULL,
        `status`              ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
        `created_by_user_id`  BIGINT UNSIGNED NOT NULL,
        `reversed_by_user_id` BIGINT UNSIGNED NULL,
        `reversed_at`         TIMESTAMP NULL DEFAULT NULL,
        `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_bps_partner` (`business_partner_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Mirrors paymentApprovalRequiredForAll(): when ON (default — fail-safe, this
 * is real inventory + COGS territory), every non-admin commodity sale queues
 * for approval regardless of any delegated limit. Toggle via
 * settings.commodity_sale_require_approval ('0' to disable).
 */
function commoditySaleApprovalRequiredForAll(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $db = Database::getInstance();
    try {
        $row = $db->query("SELECT value FROM settings WHERE name = 'commodity_sale_require_approval'")->first();
        $cached = $row === false || $row === null || $row->value === null ? true : ((string)$row->value === '1');
    } catch (Exception $e) {
        $cached = true; // fail safe: require approval rather than post unreviewed
    }
    return $cached;
}

function ensureStockAdjustmentsTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = Database::getInstance();
    try { $db->getPdo()->query("SELECT 1 FROM `cr_stock_adjustments` LIMIT 1"); return; } catch (Exception $e) {}
    try {
        $db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS `cr_stock_adjustments` (
              `id`                  bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `adjustment_number`   varchar(30) NOT NULL,
              `variant_id`          bigint UNSIGNED NOT NULL,
              `branch_id`           bigint UNSIGNED NOT NULL,
              `direction`           enum('increase','decrease') NOT NULL,
              `quantity`            decimal(15,3) NOT NULL,
              `unit_value`          decimal(15,2) NOT NULL DEFAULT 0.00,
              `total_value`         decimal(15,2) NOT NULL DEFAULT 0.00,
              `reason`              varchar(255) DEFAULT NULL,
              `offset_account_id`   bigint UNSIGNED DEFAULT NULL,
              `status`              enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
              `created_by_user_id`  bigint UNSIGNED NOT NULL,
              `approved_by_user_id` bigint UNSIGNED DEFAULT NULL,
              `approved_at`         timestamp NULL DEFAULT NULL,
              `journal_entry_id`    bigint UNSIGNED DEFAULT NULL,
              `notes`               text DEFAULT NULL,
              `created_at`          timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at`          timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_sa_number` (`adjustment_number`),
              KEY `idx_sa_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log('ensureStockAdjustmentsTable: ' . $e->getMessage());
    }
}

/**
 * Find (or create) the Inventory asset account used as the balancing side of a
 * stock adjustment. Returns its chart_of_accounts id, or 0 on failure.
 */
function getOrCreateInventoryAccount(): int {
    $db = Database::getInstance();
    try {
        $row = $db->query(
            "SELECT id FROM chart_of_accounts
             WHERE account_type_group = 'Asset' AND (name LIKE '%Inventory%' OR name LIKE '%Stock%')
               AND status = 'active' ORDER BY id ASC LIMIT 1"
        )->first();
        if ($row) return (int)$row->id;
        return (int)$db->insert('chart_of_accounts', [
            'name'               => 'Inventory (Stock)',
            'description'        => 'Auto-created for stock adjustments',
            'account_type'       => 'Asset',
            'account_type_group' => 'Asset',
            'normal_balance'     => 'debit',
            'status'             => 'active',
        ]);
    } catch (Exception $e) {
        error_log('getOrCreateInventoryAccount: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Registry of limit-bearing actions: action_key => label. The privileges page
 * renders a ৳ input next to each of these; enforcement pages look them up via
 * getUserActionLimit(). Keys are global per user (not per module).
 */
function getLimitBearingActions(): array {
    return [
        'approve_order'    => 'Approve Orders — max order value',
        'amend_order'      => 'Amend Orders — max resulting order value',
        'early_release'    => 'Payment Watch — early-release orders up to',
        'collect_payment'  => 'Collect Payment — max single receipt',
        'partial_delivery' => 'Partial Delivery — max delivery value',
        'commodity_sale'   => 'Commodity Sale — max single sale value',
        'loan_disbursement' => 'Loan Disbursement — max single loan amount',
        'pos_exit_release' => 'POS Exit Release — max unpaid amount released before payment',
    ];
}

/**
 * Self-migrating per-action limits (Phase 1 of the privileges redesign).
 * CREATE TABLE IF NOT EXISTS only — never touches existing tables.
 */
function ensureActionLimitsTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    global $db;
    // Probe first — DDL implicit-commits open transactions (see ensureApprovalGateTables)
    try { $db->getPdo()->query("SELECT 1 FROM `user_action_limits` LIMIT 1"); return; } catch (Exception $e) {}
    try {
        $db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS `user_action_limits` (
              `id`             bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id`        bigint UNSIGNED NOT NULL,
              `action_key`     varchar(50) NOT NULL,
              `max_amount`     decimal(15,2) NOT NULL,
              `set_by_user_id` bigint UNSIGNED DEFAULT NULL,
              `updated_at`     timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_ual_user_action` (`user_id`, `action_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log('ensureActionLimitsTable: ' . $e->getMessage());
    }
}

/**
 * Per-action limit for a user, in ৳. Returns null = no cap for this action.
 *
 * $legacy_fallback: when true and no per-action row exists, falls back to the
 * old single approval limit (user_approval_limits). Use TRUE only for the
 * actions that historically used that limit (approve_order, amend_order,
 * early_release) — NEVER for new ceilings like collect_payment, otherwise a
 * user's approval limit would silently start blocking their payment desk.
 */
function getUserActionLimit(int $user_id, string $action_key, bool $legacy_fallback = false): ?float {
    global $db;
    ensureActionLimitsTable();
    try {
        $row = $db->query(
            "SELECT max_amount FROM user_action_limits WHERE user_id = ? AND action_key = ?",
            [$user_id, $action_key]
        )->first();
        if ($row) return (float)$row->max_amount;
    } catch (Exception $e) {
        error_log('getUserActionLimit: ' . $e->getMessage());
    }
    return $legacy_fallback ? getUserApprovalLimit($user_id) : null;
}

/* ═══════════════ Maker/Checker pending requests (Phase 3) ═══════════════
   Over-limit payments/deliveries are parked here instead of being rejected.
   NOTHING posts to accounting until a checker reviews the prefilled form and
   posts it themselves through the normal page — the ledger stays the truth. */

function ensurePendingRequestsTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    global $db;
    // Probe first — DDL implicit-commits open transactions (see ensureApprovalGateTables)
    try { $db->getPdo()->query("SELECT 1 FROM `cr_pending_requests` LIMIT 1"); return; } catch (Exception $e) {}
    try {
        $db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS `cr_pending_requests` (
              `id`              bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `request_type`    varchar(20) NOT NULL COMMENT 'payment | delivery',
              `order_id`        bigint UNSIGNED DEFAULT NULL,
              `customer_id`     bigint UNSIGNED DEFAULT NULL,
              `amount`          decimal(15,2) NOT NULL,
              `summary`         varchar(500) DEFAULT NULL,
              `payload`         text NOT NULL COMMENT 'JSON of the original form submission',
              `status`          enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
              `maker_user_id`   bigint UNSIGNED NOT NULL,
              `maker_limit`     decimal(15,2) DEFAULT NULL COMMENT 'the cap that blocked the maker',
              `checker_user_id` bigint UNSIGNED DEFAULT NULL,
              `checker_note`    varchar(500) DEFAULT NULL,
              `executed_ref`    varchar(100) DEFAULT NULL COMMENT 'receipt/delivery number posted at approval',
              `created_at`      timestamp NOT NULL DEFAULT current_timestamp(),
              `decided_at`      timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_cpr_status` (`status`),
              KEY `idx_cpr_maker` (`maker_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log('ensurePendingRequestsTable: ' . $e->getMessage());
    }
}

/**
 * Park an over-limit action as a pending request. Returns the request id (0 on failure).
 * $opts: order_id, customer_id, summary, maker_limit
 */
function submitPendingRequest(string $type, float $amount, array $payload, array $opts = []): int {
    $db = Database::getInstance();
    ensurePendingRequestsTable();
    try {
        $req_id = (int)$db->insert('cr_pending_requests', [
            'request_type'  => $type,
            'order_id'      => isset($opts['order_id']) ? (int)$opts['order_id'] : null,
            'customer_id'   => isset($opts['customer_id']) ? (int)$opts['customer_id'] : null,
            'amount'        => $amount,
            'summary'       => mb_substr((string)($opts['summary'] ?? ''), 0, 500),
            'payload'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'maker_user_id' => (int)($_SESSION['user_id'] ?? 0),
            'maker_limit'   => isset($opts['maker_limit']) ? (float)$opts['maker_limit'] : null,
        ]);
        // Scope B: alert approvers immediately so queued receipts don't pile up unseen.
        if ($req_id) notifyPendingRequestQueued($req_id, $type, $amount, (string)($opts['summary'] ?? ''));
        return $req_id;
    } catch (Exception $e) {
        error_log('submitPendingRequest: ' . $e->getMessage());
        return 0;
    }
}

/** Best-effort Telegram ping when an approval request is queued. Never throws. */
function notifyPendingRequestQueued(int $req_id, string $type, float $amount, string $summary): void {
    if (!defined('TELEGRAM_NOTIFICATIONS_ENABLED') || !TELEGRAM_NOTIFICATIONS_ENABLED) return;
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID')) return;
    try {
        require_once dirname(__DIR__) . '/classes/TelegramNotifier.php';
        $maker = $_SESSION['user_display_name'] ?? 'Staff';
        $label_map = [
            'delivery' => 'DELIVERY', 'advance' => 'ADVANCE', 'commodity_sale' => 'COMMODITY SALE',
            'commodity_sale_edit' => 'COMMODITY SALE EDIT', 'loan_disbursement' => 'LOAN DISBURSEMENT',
            'loan_repayment' => 'LOAN REPAYMENT', 'pos_credit_sale' => 'POS CREDIT SALE',
            'pos_sale_edit' => 'POS SALE EDIT', 'pos_exit_release' => 'POS EXIT RELEASE',
        ];
        $label = $label_map[$type] ?? 'PAYMENT';
        // Route the "awaiting approval" ping to the same group the eventual
        // posted transaction would land in, so approvers watch one place.
        $category_map = [
            'payment' => 'payment_received', 'advance' => 'payment_received', 'commodity_payment' => 'payment_received', 'loan_repayment' => 'payment_received',
            'delivery' => 'dispatch',
            'commodity_sale' => 'orders', 'commodity_sale_edit' => 'orders',
            'loan_disbursement' => 'payment',
            'pos_credit_sale' => 'orders', 'pos_sale_edit' => 'orders', 'pos_exit_release' => 'dispatch',
        ];
        $is_pos_type = in_array($type, ['pos_credit_sale', 'pos_sale_edit', 'pos_exit_release'], true);
        $chat_id = getTelegramChatId($category_map[$type] ?? 'payment_received');
        $msg = "<b>⏳ {$label} AWAITING APPROVAL</b>\n"
             . "───────────────────────────────\n\n"
             . "• Request: <code>#{$req_id}</code>\n"
             . "• Amount: <b>৳" . number_format($amount, 2) . "</b>\n"
             . ($summary !== '' ? "• {$summary}\n" : '')
             . "• Submitted by: <b>{$maker}</b>\n\n"
             . ($is_pos_type ? "<i>Approve in POS → Pending Approvals.</i>" : "<i>Approve in Credit Sales → Approval Requests.</i>");
        (new TelegramNotifier(TELEGRAM_BOT_TOKEN, $chat_id))->sendMessage($msg);
    } catch (\Throwable $e) {
        error_log('notifyPendingRequestQueued: ' . $e->getMessage());
    }
}

/**
 * Mark a pending request decided. $status: 'approved' | 'rejected' | 'cancelled'.
 * Only transitions rows still in 'pending' — returns false if already decided.
 */
function decidePendingRequest(int $request_id, string $status, ?string $note = null, ?string $executed_ref = null): bool {
    global $db;
    ensurePendingRequestsTable();
    if (!in_array($status, ['approved', 'rejected', 'cancelled'])) return false;
    try {
        $db->query(
            "UPDATE cr_pending_requests
             SET status = ?, checker_user_id = ?, checker_note = ?, executed_ref = ?, decided_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [$status, (int)($_SESSION['user_id'] ?? 0),
             $note !== null ? mb_substr($note, 0, 500) : null,
             $executed_ref, $request_id]
        );
        $chk = $db->query("SELECT status FROM cr_pending_requests WHERE id = ?", [$request_id])->first();
        return $chk && $chk->status === $status;
    } catch (Exception $e) {
        error_log('decidePendingRequest: ' . $e->getMessage());
        return false;
    }
}

/**
 * A pending request loaded for the prefill/execute flow, or null when the id is
 * not a live pending request of the expected type.
 */
function getPendingRequest(int $request_id, string $expected_type): ?object {
    global $db;
    if (!$request_id) return null;
    ensurePendingRequestsTable();
    try {
        $r = $db->query(
            "SELECT * FROM cr_pending_requests WHERE id = ? AND status = 'pending' AND request_type = ?",
            [$request_id, $expected_type]
        )->first();
        if ($r) $r->payload_arr = json_decode($r->payload ?? '{}', true) ?: [];
        return $r ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * The user's personal order-approval limit in ৳, or null when no limit is set
 * (null = no personal cap; only the standard 80% credit-usage rule applies).
 */
function getUserApprovalLimit(int $user_id): ?float {
    global $db;
    ensureApprovalLimitTable();
    try {
        $row = $db->query("SELECT max_order_amount FROM user_approval_limits WHERE user_id = ?", [$user_id])->first();
        return $row ? (float)$row->max_order_amount : null;
    } catch (Exception $e) {
        error_log('getUserApprovalLimit: ' . $e->getMessage());
        return null;
    }
}

/**
 * Customer's true outstanding = initial_due + net ledger transactions.
 * Same formula as customer_ledger.php / create_order.php.
 */
function getCustomerOutstanding(int $customer_id): float {
    global $db;
    try {
        $row = $db->query(
            "SELECT COALESCE(c.initial_due, 0)
                    + COALESCE((SELECT SUM(debit_amount) - SUM(credit_amount)
                                FROM customer_ledger
                                WHERE customer_id = c.id AND reference_type != 'initial_due'), 0)
                    AS outstanding
             FROM customers c WHERE c.id = ?",
            [$customer_id]
        )->first();
        return $row ? (float)$row->outstanding : 0.0;
    } catch (Exception $e) {
        error_log('getCustomerOutstanding: ' . $e->getMessage());
        return 0.0;
    }
}

/**
 * Single evaluator for an order's approval gates. Used by production,
 * dispatch, partial delivery, order view and payment watch pages so the
 * condition logic lives in exactly one place.
 *
 * Returns:
 *   [
 *     'has_conditions' => bool,
 *     'row'            => object|null,   // raw order_approval_conditions row
 *     'production'     => 'open'|'held',
 *     'dispatch'       => 'open'|'held'|'condition_met'|'cleared',
 *     'threshold'      => float|null,    // condition_amount
 *     'current'        => float|null,    // live outstanding OR amount received
 *     'shortfall'      => float|null,    // how far from meeting the condition
 *   ]
 *
 * Fails OPEN (no gate) if the table is missing — feature degrades
 * gracefully, existing data and workflows are never blocked by an error.
 */
/**
 * New rule (15 Jul 2026, admin-settings toggle): when ON, approving an order that
 * crosses the customer's CREDIT LIMIT does not change who may approve it — it
 * automatically attaches a payment-linked dispatch hold instead of requiring the
 * approver to set one manually: condition_type=outstanding_after_ship, amount =
 * the customer's credit limit, auto_release=1. Production and "ready to ship"
 * proceed normally; only the final ship/dispatch step is held, and it auto-
 * releases (via the existing getOrderGateState() evaluator) the moment the
 * customer's total due — including this invoice — is brought back within their
 * limit through an authorized (posted) payment. Toggle via
 * settings.credit_limit_auto_release ('1' to enable). Defaults OFF — "if marked
 * off, the process is exactly as before this feature." Cached per request.
 */
function creditLimitAutoReleaseEnabled(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $db = Database::getInstance();
    try {
        $row = $db->query("SELECT value FROM settings WHERE name = 'credit_limit_auto_release'")->first();
        $cached = $row !== null && $row !== false && (string)$row->value === '1';
    } catch (Exception $e) {
        $cached = false; // fail safe: keep the existing (unaltered) approval flow
    }
    return $cached;
}

/**
 * Feature #5 — global dispatch auto-hold. When ON (default), EVERY order is held
 * from dispatch until Accounts/Admin explicitly clears it, regardless of payment
 * status. Toggle via settings.dispatch_global_hold ('0' to disable). Cached per
 * request.
 */
function dispatchGlobalHoldEnabled(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $db = Database::getInstance();
    try {
        $row = $db->query("SELECT value FROM settings WHERE name = 'dispatch_global_hold'")->first();
        // Default ON when the setting has never been saved.
        $cached = $row === false || $row === null || $row->value === null ? true : ((string)$row->value === '1');
    } catch (Exception $e) {
        $cached = true; // fail safe: hold rather than ship unguarded
    }
    return $cached;
}

/**
 * Whether the printed credit invoice shows the "Previous Due" and "Total Due"
 * (total outstanding) lines. ON (shown) by default — matches current behavior
 * until admin explicitly turns it off. Toggle via settings.show_invoice_outstanding.
 */
function showInvoiceOutstandingEnabled(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $db = Database::getInstance();
    try {
        $row = $db->query("SELECT value FROM settings WHERE name = 'show_invoice_outstanding'")->first();
        // Default ON (shown) when the setting has never been saved.
        $cached = $row === false || $row === null || $row->value === null ? true : ((string)$row->value === '1');
    } catch (Exception $e) {
        $cached = true; // fail safe: keep current behavior (shown)
    }
    return $cached;
}

/**
 * Feature #6 — when ON (default), EVERY payment an Accounts user collects must be
 * approved before it posts to the ledger (maker/checker). When OFF, only receipts
 * over the user's collect_payment limit queue. Admins always post directly (they
 * are the approving authority). Toggle via settings.payment_require_approval.
 */
function paymentApprovalRequiredForAll(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $db = Database::getInstance();
    try {
        $row = $db->query("SELECT value FROM settings WHERE name = 'payment_require_approval'")->first();
        $cached = $row === false || $row === null || $row->value === null ? true : ((string)$row->value === '1');
    } catch (Exception $e) {
        $cached = true; // fail safe: require approval rather than post unreviewed
    }
    return $cached;
}

/**
 * Ensure a default dispatch-hold row exists for an order (idempotent). Used so
 * every order surfaces in Payment Watch for clearance under the global hold.
 * Never clobbers an existing row (INSERT IGNORE on the unique order_id key).
 */
function ensureDefaultDispatchHold(int $order_id): void {
    if (!dispatchGlobalHoldEnabled()) return;
    $db = Database::getInstance();
    ensureApprovalGateTables();
    try {
        $db->query(
            "INSERT IGNORE INTO order_approval_conditions
                (order_id, approved_by_user_id, dispatch_hold, condition_type, dispatch_cleared)
             VALUES (?, 0, 1, 'manual', 0)",
            [$order_id]
        );
    } catch (Exception $e) {
        error_log('ensureDefaultDispatchHold: ' . $e->getMessage());
    }
}

/**
 * Bulk-provision default hold rows for every dispatchable order that lacks one,
 * so existing orders appear in Payment Watch. Idempotent; returns rows created.
 */
function provisionDefaultDispatchHolds(): int {
    if (!dispatchGlobalHoldEnabled()) return 0;
    $db = Database::getInstance();
    ensureApprovalGateTables();
    try {
        $db->query(
            "INSERT IGNORE INTO order_approval_conditions
                (order_id, approved_by_user_id, dispatch_hold, condition_type, dispatch_cleared)
             SELECT co.id, 0, 1, 'manual', 0
             FROM credit_orders co
             LEFT JOIN order_approval_conditions oac ON oac.order_id = co.id
             WHERE oac.id IS NULL
               AND co.status IN ('approved','in_production','produced','ready_to_ship')"
        );
        $created = (int)$db->getPdo()->query("SELECT ROW_COUNT()")->fetchColumn();
        // Also enforce the hold on legacy rows that had dispatch_hold=0 (e.g. a
        // production-hold-only approval) and were never cleared.
        $db->query(
            "UPDATE order_approval_conditions oac
             JOIN credit_orders co ON co.id = oac.order_id
             SET oac.dispatch_hold = 1
             WHERE oac.dispatch_hold = 0 AND oac.dispatch_cleared = 0
               AND co.status IN ('approved','in_production','produced','ready_to_ship')"
        );
        return $created;
    } catch (Exception $e) {
        error_log('provisionDefaultDispatchHolds: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Grant/clear dispatch for an order — UPSERTS the gate row so it works even for
 * orders that have no conditions row yet (default-held under the global policy).
 */
function clearOrderDispatch(int $order_id, ?int $cleared_by, string $note = ''): void {
    $db = Database::getInstance();
    ensureApprovalGateTables();
    $note = $note !== '' ? $note : 'Dispatch cleared';
    try {
        $db->query(
            "INSERT INTO order_approval_conditions
                (order_id, approved_by_user_id, dispatch_hold, condition_type, dispatch_cleared, cleared_by, cleared_at, clearance_note)
             VALUES (?, 0, 1, 'manual', 1, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                dispatch_hold = 1, dispatch_cleared = 1, cleared_by = VALUES(cleared_by),
                cleared_at = NOW(), clearance_note = VALUES(clearance_note)",
            [$order_id, $cleared_by, $note]
        );
    } catch (Exception $e) {
        error_log('clearOrderDispatch: ' . $e->getMessage());
    }
}

function getOrderGateState(int $order_id): array {
    global $db;
    $state = [
        'has_conditions' => false, 'row' => null,
        'production' => 'open', 'dispatch' => 'open',
        'threshold' => null, 'current' => null, 'shortfall' => null,
        'default_hold' => false,
    ];

    ensureApprovalGateTables();
    try {
        $row = $db->query("SELECT * FROM order_approval_conditions WHERE order_id = ?", [$order_id])->first();
    } catch (Exception $e) {
        error_log('getOrderGateState: ' . $e->getMessage());
        return $state;
    }
    if (!$row) {
        // Feature #5: with the global hold ON, an order with no explicit gate row
        // is still HELD from dispatch until Accounts/Admin clears it — but only
        // while it's still dispatchable (don't cosmetically flag terminal orders).
        if (dispatchGlobalHoldEnabled()) {
            try {
                $st = $db->query("SELECT status FROM credit_orders WHERE id = ?", [$order_id])->first();
                if ($st && !in_array($st->status, ['shipped','delivered','cancelled','rejected','pending_approval','escalated'])) {
                    $state['dispatch']     = 'held';
                    $state['default_hold'] = true;
                }
            } catch (Exception $e) {}
        }
        return $state;
    }

    $state['has_conditions'] = true;
    $state['row'] = $row;

    // ── Production gate ──
    if ((int)$row->production_hold === 1 && empty($row->production_released_at)) {
        $state['production'] = 'held';
    }

    // ── Dispatch gate ──
    if ((int)$row->dispatch_hold === 1) {
        if ((int)$row->dispatch_cleared === 1) {
            $state['dispatch'] = 'cleared';
        } else {
            $state['dispatch']  = 'held';
            $state['threshold'] = $row->condition_amount !== null ? (float)$row->condition_amount : null;

            $order = $db->query("SELECT customer_id, total_amount FROM credit_orders WHERE id = ?", [$order_id])->first();
            if ($order && $state['threshold'] !== null) {
                if ($row->condition_type === 'outstanding_below') {
                    // Condition: total outstanding must drop to <= threshold
                    $state['current']   = getCustomerOutstanding((int)$order->customer_id);
                    $state['shortfall'] = max(0, $state['current'] - $state['threshold']);
                    if ($state['current'] <= $state['threshold']) {
                        $state['dispatch'] = 'condition_met';
                    }
                } elseif ($row->condition_type === 'outstanding_after_ship') {
                    // Condition: outstanding INCLUDING this (not yet posted) invoice
                    // must drop to <= threshold. Threshold 0 = full settlement of
                    // everything, this order included, before the truck leaves.
                    $state['current']   = getCustomerOutstanding((int)$order->customer_id) + (float)$order->total_amount;
                    $state['shortfall'] = max(0, $state['current'] - $state['threshold']);
                    if ($state['current'] <= $state['threshold']) {
                        $state['dispatch'] = 'condition_met';
                    }
                } elseif ($row->condition_type === 'amount_received') {
                    // Condition: payments received since approval >= threshold.
                    // MUST compare against created_at (full timestamp = moment the
                    // receipt was recorded), NOT payment_date — that column is a
                    // DATE, so a date comparison counts money deposited earlier
                    // the SAME DAY, before the condition even existed.
                    try {
                        $rec = $db->query(
                            "SELECT COALESCE(SUM(amount), 0) AS total
                             FROM customer_payments
                             WHERE customer_id = ? AND created_at >= ?",
                            [(int)$order->customer_id, $row->approved_at]
                        )->first();
                        $state['current']   = $rec ? (float)$rec->total : 0.0;
                        $state['shortfall'] = max(0, $state['threshold'] - $state['current']);
                        if ($state['current'] >= $state['threshold']) {
                            $state['dispatch'] = 'condition_met';
                        }
                    } catch (Exception $e) {
                        error_log('getOrderGateState received: ' . $e->getMessage());
                    }
                }
            }

            // Auto-release: condition met + admin opted in → treat as cleared and persist
            if ($state['dispatch'] === 'condition_met' && (int)$row->auto_release === 1) {
                try {
                    $db->query(
                        "UPDATE order_approval_conditions
                         SET dispatch_cleared = 1, cleared_by = NULL, cleared_at = NOW(),
                             clearance_note = 'Auto-released: payment condition met'
                         WHERE order_id = ? AND dispatch_cleared = 0",
                        [$order_id]
                    );
                    logGateEvent($order_id, 'dispatch_auto_cleared', 'Dispatch auto-released — payment condition met (system)');
                    $state['dispatch'] = 'cleared';
                } catch (Exception $e) {
                    error_log('getOrderGateState auto-release: ' . $e->getMessage());
                }
            }
        }
    }

    return $state;
}

/**
 * True when the order may be dispatched (fully or partially).
 * Call inside POST handlers, not just when rendering buttons.
 */
function orderDispatchAllowed(int $order_id): bool {
    $gate = getOrderGateState($order_id);
    return in_array($gate['dispatch'], ['open', 'cleared']);
}

/**
 * Audit a gate event into the existing credit_order_workflow trail.
 * from_status = to_status = current order status (gates are a layer, not a status).
 */
function logGateEvent(int $order_id, string $action, string $comments): void {
    global $db;
    try {
        $order = $db->query("SELECT status FROM credit_orders WHERE id = ?", [$order_id])->first();
        $status = $order->status ?? 'unknown';
        $db->insert('credit_order_workflow', [
            'order_id'             => $order_id,
            'from_status'          => $status,
            'to_status'            => $status,
            'action'               => $action,
            'performed_by_user_id' => $_SESSION['user_id'] ?? null,
            'comments'             => $comments,
        ]);
    } catch (Exception $e) {
        error_log('logGateEvent: ' . $e->getMessage());
    }
}

/* ═══════════════ POS module rebuild (Jul 2026) ═══════════════════════════════
   Brings the standalone POS terminal (orders/order_items, order_type='POS')
   up to the same maturity as Credit Sales / Trading: its own ledger (a THIRD
   ledger space alongside customer_ledger and loans — same reasoning as Loans,
   a POS-type customer's till-counter balance isn't a "credit_orders invoice"
   and forcing it into customer_ledger's ENUM would repeat a mistake this
   codebase has already paid for once), a standalone (not delegated-limit)
   credit control, a single-stage QR exit verification (POS is a walk-out
   counter — one physical checkpoint, not credit sales' gate-then-deliver
   two-stage truck journey), and a real EOD-to-bank-deposit bridge. */

/** Own ledger for POS-type customers — deliberately separate from customer_ledger. */
function ensurePosLedgerTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    try { $pdo->query("SELECT 1 FROM `pos_customer_ledger` LIMIT 1"); return; } catch (Exception $e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `pos_customer_ledger` (
            `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `customer_id`        BIGINT UNSIGNED NOT NULL,
            `branch_id`          BIGINT UNSIGNED NULL,
            `transaction_date`   DATE NOT NULL,
            `transaction_type`   ENUM('sale','payment','adjustment','opening_balance') NOT NULL,
            `reference_type`     VARCHAR(50) NULL,
            `reference_id`       BIGINT UNSIGNED NULL,
            `order_number`       VARCHAR(50) NULL,
            `description`        TEXT NULL,
            `debit_amount`       DECIMAL(12,2) NOT NULL DEFAULT 0,
            `credit_amount`      DECIMAL(12,2) NOT NULL DEFAULT 0,
            `balance_after`      DECIMAL(12,2) NOT NULL DEFAULT 0,
            `journal_entry_id`   BIGINT UNSIGNED NULL,
            `created_by_user_id` BIGINT UNSIGNED NULL,
            `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_pcl_customer` (`customer_id`),
            KEY `idx_pcl_reference` (`reference_type`, `reference_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) { error_log('ensurePosLedgerTable: ' . $e->getMessage()); }
}

/**
 * Post one POS ledger row with a running per-customer balance, same
 * aggregate-based pattern used for customer_ledger elsewhere in this app.
 * $debit increases what the customer owes (a sale); $credit decreases it (a payment).
 */
function postPosLedgerEntry(int $customer_id, string $type, float $debit, float $credit, array $opts = []): int {
    $db = Database::getInstance();
    ensurePosLedgerTable();
    $prev = $db->query("SELECT balance_after FROM pos_customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1", [$customer_id])->first();
    $running = ($prev ? (float)$prev->balance_after : 0) + $debit - $credit;
    $id = $db->insert('pos_customer_ledger', [
        'customer_id'        => $customer_id,
        'branch_id'          => $opts['branch_id'] ?? null,
        'transaction_date'   => $opts['transaction_date'] ?? date('Y-m-d'),
        'transaction_type'   => $type,
        'reference_type'     => $opts['reference_type'] ?? null,
        'reference_id'       => $opts['reference_id'] ?? null,
        'order_number'       => $opts['order_number'] ?? null,
        'description'        => $opts['description'] ?? null,
        'debit_amount'       => $debit,
        'credit_amount'      => $credit,
        'balance_after'      => $running,
        'journal_entry_id'   => $opts['journal_entry_id'] ?? null,
        'created_by_user_id' => $_SESSION['user_id'] ?? null,
    ]);
    if (!$id) throw new Exception('Failed to post POS ledger entry.');
    return (int)$id;
}

/** True running POS balance for a customer — never trust customers.current_balance (same rule as Credit Sales). */
function getPosCustomerOutstanding(int $customer_id): float {
    $db = Database::getInstance();
    ensurePosLedgerTable();
    $row = $db->query("SELECT balance_after FROM pos_customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1", [$customer_id])->first();
    return $row ? (float)$row->balance_after : 0.0;
}

/** Split-payment + branch-account linkage columns on orders (additive, idempotent). */
function ensurePosSaleColumns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    try { $pdo->query("SELECT cash_paid FROM orders LIMIT 1"); }
    catch (Exception $e) { try { $pdo->exec("ALTER TABLE `orders` ADD COLUMN `cash_paid` DECIMAL(12,2) NOT NULL DEFAULT 0"); } catch (Exception $e2) {} }
    try { $pdo->query("SELECT credit_amount FROM orders LIMIT 1"); }
    catch (Exception $e) { try { $pdo->exec("ALTER TABLE `orders` ADD COLUMN `credit_amount` DECIMAL(12,2) NOT NULL DEFAULT 0"); } catch (Exception $e2) {} }
}

/** Signature for the POS exit-verification QR (own salt namespace — a walk-out counter check, not a delivery). */
function posExitQrSignature(string $orderNumber): string {
    return substr(hash_hmac('sha256', 'POSEXIT|' . $orderNumber, getInvoiceQrSecret()), 0, 16);
}

/** One-time exit-verification record per POS order (single-stage — a counter handoff, not a two-stage truck journey). */
function ensurePosExitTables(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    try { $pdo->query("SELECT 1 FROM `pos_exit_verifications` LIMIT 1"); }
    catch (Exception $e) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `pos_exit_verifications` (
                `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id`           BIGINT UNSIGNED NOT NULL,
                `order_number`       VARCHAR(50) NULL,
                `verified_at`        TIMESTAMP NULL DEFAULT NULL,
                `verified_by_user_id` BIGINT UNSIGNED NULL,
                `verified_by_name`   VARCHAR(120) NULL,
                `note`               VARCHAR(500) NULL,
                `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_pev_order` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Exception $e2) { error_log('ensurePosExitTables: ' . $e2->getMessage()); }
    }
    try { $pdo->query("SELECT 1 FROM `pos_qr_scan_log` LIMIT 1"); }
    catch (Exception $e) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `pos_qr_scan_log` (
                `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id`           BIGINT UNSIGNED NOT NULL,
                `order_number`       VARCHAR(50) NULL,
                `reused`             TINYINT(1) NOT NULL DEFAULT 0,
                `scanned_by_user_id` BIGINT UNSIGNED NULL,
                `scanned_by_name`    VARCHAR(120) NULL,
                `ip`                 VARCHAR(64) NULL,
                `scanned_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`), KEY `idx_pqsl_order` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Exception $e2) { error_log('ensurePosExitTables (scan log): ' . $e2->getMessage()); }
    }
}

/** Record a POS exit-QR scan; a scan on an already-verified order is flagged as reuse (theft/duplicate-exit signal). */
function recordPosExitScan(int $order_id, string $order_number, string $scanner, bool $reused): int {
    $db = Database::getInstance();
    ensurePosExitTables();
    try {
        $db->insert('pos_qr_scan_log', [
            'order_id' => $order_id, 'order_number' => $order_number, 'reused' => $reused ? 1 : 0,
            'scanned_by_user_id' => (int)($_SESSION['user_id'] ?? 0), 'scanned_by_name' => $scanner,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        $row = $db->query("SELECT COUNT(*) AS c FROM pos_qr_scan_log WHERE order_id = ?", [$order_id])->first();
        $total = (int)($row->c ?? 0);
        if ($reused) notifyPosQrReuse($order_number, $scanner, $total);
        return $total;
    } catch (Exception $e) { error_log('recordPosExitScan: ' . $e->getMessage()); return 0; }
}

function notifyPosQrReuse(string $order_number, string $scanner, int $total): void {
    if (!defined('TELEGRAM_NOTIFICATIONS_ENABLED') || !TELEGRAM_NOTIFICATIONS_ENABLED) return;
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID')) return;
    try {
        require_once dirname(__DIR__) . '/classes/TelegramNotifier.php';
        $msg = "<b>⚠ POS EXIT QR RE-SCANNED</b>\n"
             . "───────────────────────────────\n\n"
             . "• Order: <code>{$order_number}</code>\n"
             . "• Already verified out — scanned again by <b>{$scanner}</b>\n"
             . "• Total scans on this QR: <b>{$total}</b>\n\n"
             . "<i>Possible duplicate exit / gate bypass attempt — please verify.</i>";
        (new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('dispatch')))->sendMessage($msg);
    } catch (\Throwable $e) { error_log('notifyPosQrReuse: ' . $e->getMessage()); }
}

/** EOD -> next-day bank-deposit bridge columns (additive, idempotent). */
function ensureEodDepositColumns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    $cols = [
        'cash_disposition'        => "VARCHAR(30) NOT NULL DEFAULT 'petty_cash'",
        'deposit_bank_account_id' => "BIGINT UNSIGNED NULL",
        'deposit_reference'       => "VARCHAR(100) NULL",
        'deposited_at'            => "TIMESTAMP NULL DEFAULT NULL",
        'deposited_by_user_id'    => "BIGINT UNSIGNED NULL",
        'deposit_journal_entry_id' => "BIGINT UNSIGNED NULL",
    ];
    foreach ($cols as $col => $def) {
        try { $pdo->query("SELECT `{$col}` FROM eod_summary LIMIT 1"); }
        catch (Exception $e) { try { $pdo->exec("ALTER TABLE `eod_summary` ADD COLUMN `{$col}` {$def}"); } catch (Exception $e2) {} }
    }
}

/** Edit-approval table for POS sales — mirrors commodity_sale_edits' diff/approval-queue shape. */
function ensurePosSaleEditsTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    try { $pdo->query("SELECT 1 FROM `pos_sale_edits` LIMIT 1"); return; } catch (Exception $e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `pos_sale_edits` (
            `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id`            BIGINT UNSIGNED NOT NULL,
            `order_number`        VARCHAR(50) NULL,
            `proposed_json`       TEXT NOT NULL,
            `original_json`       TEXT NOT NULL,
            `reason`              VARCHAR(500) NULL,
            `status`              ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            `requested_by_user_id` BIGINT UNSIGNED NOT NULL,
            `decided_by_user_id`  BIGINT UNSIGNED NULL,
            `decided_at`          TIMESTAMP NULL DEFAULT NULL,
            `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), KEY `idx_pse_order` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) { error_log('ensurePosSaleEditsTable: ' . $e->getMessage()); }
}

/**
 * Branch's designated Petty Cash / POS Cash chart_of_accounts row — resolved via
 * branch_petty_cash_accounts (an explicit mapping table that already exists),
 * NOT the old fuzzy `LIKE '%POS Cash%branch_code%'` name-guessing that silently
 * missed accounts named e.g. "POS Sales Sirajgonj" or "Petty Cash HO".
 */
function getBranchPettyCashAccountId(int $branch_id): ?int {
    $db = Database::getInstance();
    $row = $db->query(
        "SELECT chart_of_account_id FROM branch_petty_cash_accounts WHERE branch_id = ? AND status = 'active' LIMIT 1",
        [$branch_id]
    )->first();
    return $row ? (int)$row->chart_of_account_id : null;
}

/**
 * Post a POS cash movement into the branch's petty cash ledger, keeping
 * branch_petty_cash_accounts.current_balance and branch_petty_cash_transactions
 * in sync — this is the piece the original POS build never wired up, which is
 * why EOD's cash reconciliation could never see today's actual register sales.
 */
function postBranchPettyCashTransaction(int $branch_id, string $type, float $amount, array $opts = []): void {
    $db = Database::getInstance();
    $account = $db->query("SELECT id, chart_of_account_id, current_balance FROM branch_petty_cash_accounts WHERE branch_id = ? AND status = 'active' LIMIT 1", [$branch_id])->first();
    if (!$account) throw new Exception("No active petty cash account configured for this branch — set one up in Chart of Accounts / Branch Petty Cash before taking cash sales.");
    $delta = in_array($type, ['cash_in', 'transfer_in', 'opening_balance']) ? $amount : -$amount;
    $new_balance = (float)$account->current_balance + $delta;
    $db->query("UPDATE branch_petty_cash_accounts SET current_balance = ?, updated_at = NOW() WHERE id = ?", [$new_balance, $account->id]);
    if ($db->error()) { $info = $db->errorInfo(); throw new Exception('Failed to update petty cash balance: ' . ($info[2] ?? 'unknown DB error')); }
    $db->insert('branch_petty_cash_transactions', [
        'branch_id'          => $branch_id,
        'account_id'         => $account->chart_of_account_id,
        'transaction_date'   => date('Y-m-d H:i:s'),
        'transaction_type'   => $type,
        'amount'             => $amount,
        'balance_after'      => $new_balance,
        'reference_type'     => $opts['reference_type'] ?? null,
        'reference_id'       => $opts['reference_id'] ?? null,
        'description'        => $opts['description'] ?? null,
        'payment_method'     => $opts['payment_method'] ?? null,
        'created_by_user_id' => $_SESSION['user_id'] ?? null,
    ]);
}

/**
 * Which factory/branch actually manufactured this variant (Jul 2026) —
 * distinct from which branch is selling it. Informational only, same
 * not-enforced-FK convention as the rest of this codebase. Set on
 * product/manage_variants.php, shown on the POS product card.
 */
function ensureProductOriginColumn(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    try { $pdo->query("SELECT origin_branch_id FROM product_variants LIMIT 1"); }
    catch (Exception $e) { try { $pdo->exec("ALTER TABLE `product_variants` ADD COLUMN `origin_branch_id` BIGINT UNSIGNED NULL"); } catch (Exception $e2) {} }
}

/**
 * Post the journal entry + customer_ledger invoice row + invoice_snapshot for
 * a credit order at the point goods change hands. This is the exact
 * accounting logic from cr/credit_dispatch.php's dispatch step, factored out
 * so ANY path that moves an order into goods_on_board/shipped/delivered posts
 * the identical, correct accounting — not just the dispatch page.
 *
 * Found + fixed 4 Aug 2026: cr/admin_edit.php lets an admin jump an order's
 * status straight to 'shipped'/'delivered' via a raw UPDATE with zero
 * accounting side-effects — 7 real orders were shipped/delivered this way
 * with no customer_ledger entry and no journal entry ever posted, including
 * one that had already collected a real payment against an invoice that
 * technically didn't exist in the books. admin_edit.php now calls this
 * helper too, so the workflow-gate override stays available to admins (per
 * explicit product decision) but the accounting can no longer be skipped.
 *
 * Idempotent — checks for an existing journal_entries row
 * (related_document_type='credit_orders') first and does nothing if found,
 * so it's safe to call from multiple paths without double-posting.
 *
 * @param array $opts transaction_date (default today — pass the real
 *   historical date for a backfill), snapshot_trigger ('dispatch'|'manual'|
 *   'backfill'), truck_number, driver_name, driver_contact, shipped_date.
 * @return array ['journal_id' => int, 'posted' => bool] — posted=false means
 *   it was already posted and nothing new was written.
 */
function postCreditOrderDispatchAccounting(Database $db, int $order_id, int $user_id, array $opts = []): array {
    $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$order_id])->first();
    if (!$order) throw new Exception("Order not found for accounting post (id={$order_id})");

    $already = $db->query(
        "SELECT id FROM journal_entries WHERE related_document_type = 'credit_orders' AND related_document_id = ?",
        [$order_id]
    )->first();
    if ($already) return ['journal_id' => (int)$already->id, 'posted' => false];

    $transaction_date = $opts['transaction_date'] ?? date('Y-m-d');
    $snapshot_trigger = $opts['snapshot_trigger'] ?? 'dispatch';

    $ar_account = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Accounts Receivable' LIMIT 1")->first();
    if (!$ar_account) throw new Exception("No 'Accounts Receivable' account found in Chart of Accounts.");
    $ar_account_id = $ar_account->id;

    $default_sales_account = $db->query(
        "SELECT id FROM chart_of_accounts WHERE account_type = 'Revenue' AND branch_id IS NULL
         AND (LOWER(name) LIKE '%credit%' OR LOWER(description) LIKE '%credit%') ORDER BY id ASC LIMIT 1"
    )->first();
    if (!$default_sales_account) {
        $default_sales_account = $db->query(
            "SELECT id FROM chart_of_accounts WHERE account_type = 'Revenue' AND branch_id IS NULL
             AND LOWER(name) NOT LIKE '%pos%' ORDER BY id ASC LIMIT 1"
        )->first();
    }
    if (!$default_sales_account) throw new Exception("No Credit Sales Revenue account found in Chart of Accounts.");
    $sales_account_id = $default_sales_account->id;

    if ($order->assigned_branch_id) {
        $branch_acct = $db->query(
            "SELECT id FROM chart_of_accounts WHERE account_type = 'Revenue' AND branch_id = ?
             AND (LOWER(name) LIKE '%credit%' OR LOWER(description) LIKE '%credit%')
             AND LOWER(name) NOT LIKE '%pos%' ORDER BY id ASC LIMIT 1",
            [$order->assigned_branch_id]
        )->first();
        if ($branch_acct) $sales_account_id = $branch_acct->id;
    }

    $customer_data = $db->query(
        "SELECT initial_due, current_balance, name, phone_number, email, business_address FROM customers WHERE id = ?",
        [$order->customer_id]
    )->first();
    $customer_name = $customer_data ? $customer_data->name : 'Unknown Customer';
    $invoice_amount = (float)$order->total_amount;

    $journal_desc = "Credit Sale Invoice #" . $order->order_number . " to " . $customer_name;
    $journal_id = $db->insert('journal_entries', [
        'transaction_date' => $transaction_date,
        'description' => $journal_desc,
        'related_document_type' => 'credit_orders',
        'related_document_id' => $order_id,
        'created_by_user_id' => $user_id,
    ]);
    if (!$journal_id) throw new Exception("Failed to create journal entry header for order {$order->order_number}.");

    $db->insert('transaction_lines', ['journal_entry_id' => $journal_id, 'account_id' => $ar_account_id, 'debit_amount' => $invoice_amount, 'credit_amount' => 0.00, 'description' => $journal_desc]);
    $db->insert('transaction_lines', ['journal_entry_id' => $journal_id, 'account_id' => $sales_account_id, 'debit_amount' => 0.00, 'credit_amount' => $invoice_amount, 'description' => $journal_desc]);

    if (!empty($order->is_other_sales)) {
        $os_items = $db->query("SELECT id, commodity_id, origin, quantity FROM credit_order_items WHERE order_id = ? AND commodity_id IS NOT NULL", [$order_id])->results();
        if (!empty($os_items)) {
            $os_gl = ensureCommodityTradingAccounts();
            foreach ($os_items as $osi) {
                $os_commodity = $db->query("SELECT name, inventory_account_id FROM purchase_commodities WHERE id = ?", [$osi->commodity_id])->first();
                if (!$os_commodity || empty($os_commodity->inventory_account_id)) {
                    throw new Exception('Commodity "' . ($os_commodity->name ?? $osi->commodity_id) . '" has no Inventory account configured — set one in Purchase → Procurement Catalog.');
                }
                $os_cogs_amount = postCommoditySaleCost((int)$osi->commodity_id, (int)$order->assigned_branch_id, (float)$osi->quantity, (string)($osi->origin ?? ''));
                $db->query("UPDATE credit_order_items SET cogs_amount = ? WHERE id = ?", [$os_cogs_amount, $osi->id]);
                if ($os_cogs_amount > 0) {
                    $db->insert('transaction_lines', ['journal_entry_id' => $journal_id, 'account_id' => $os_gl['cogs_account_id'], 'debit_amount' => $os_cogs_amount, 'credit_amount' => 0, 'description' => $journal_desc . ' (COGS: ' . $os_commodity->name . ')']);
                    $db->insert('transaction_lines', ['journal_entry_id' => $journal_id, 'account_id' => $os_commodity->inventory_account_id, 'debit_amount' => 0, 'credit_amount' => $os_cogs_amount, 'description' => $journal_desc . ' (inventory drawdown: ' . $os_commodity->name . ')']);
                }
            }
        }
    }

    // Aggregate-based running balance — immune to stored balance_after drift.
    $agg = $db->query("SELECT COALESCE(SUM(debit_amount),0) AS td, COALESCE(SUM(credit_amount),0) AS tc FROM customer_ledger WHERE customer_id = ?", [$order->customer_id])->first();
    $agg_td = (float)($agg->td ?? 0);
    $agg_tc = (float)($agg->tc ?? 0);
    $prev_balance = ($agg_td > 0 || $agg_tc > 0) ? $agg_td - $agg_tc : (float)($customer_data->initial_due ?? 0);
    $balance_after = $prev_balance + $invoice_amount;

    $db->insert('customer_ledger', [
        'customer_id' => $order->customer_id, 'transaction_date' => $transaction_date, 'transaction_type' => 'invoice',
        'reference_type' => 'credit_orders', 'reference_id' => $order_id, 'invoice_number' => $order->order_number,
        'description' => 'Credit sale — ' . $order->order_number, 'debit_amount' => $invoice_amount, 'credit_amount' => 0.00,
        'balance_after' => $balance_after, 'created_by_user_id' => $user_id, 'journal_entry_id' => $journal_id,
    ]);

    $snap_exists = $db->query("SELECT id FROM invoice_snapshots WHERE order_id = ? LIMIT 1", [$order_id])->first();
    if (!$snap_exists) {
        $snap_items = $db->query(
            "SELECT coi.product_id, coi.variant_id, coi.quantity, coi.unit_price, coi.discount_amount, coi.tax_amount, coi.line_total,
                    p.base_name AS product_name, pv.grade, pv.weight_variant, pv.unit_of_measure, pv.sku AS variant_sku
             FROM credit_order_items coi JOIN products p ON coi.product_id = p.id
             LEFT JOIN product_variants pv ON coi.variant_id = pv.id WHERE coi.order_id = ? ORDER BY coi.id ASC",
            [$order_id]
        )->results();
        $items_arr = [];
        foreach ($snap_items as $si) {
            $vd = [];
            if ($si->grade) $vd[] = 'Grade ' . $si->grade;
            if ($si->weight_variant) $vd[] = $si->weight_variant;
            $items_arr[] = [
                'product_id' => (int)$si->product_id, 'variant_id' => $si->variant_id ? (int)$si->variant_id : null,
                'product_name' => $si->product_name, 'variant_detail' => implode(' · ', $vd), 'sku' => $si->variant_sku,
                'unit' => $si->unit_of_measure, 'quantity' => (float)$si->quantity, 'unit_price' => (float)$si->unit_price,
                'discount_amount' => (float)$si->discount_amount, 'tax_amount' => (float)$si->tax_amount, 'line_total' => (float)$si->line_total,
            ];
        }
        $previous_due = $prev_balance;
        $total_outstanding = $previous_due + max(0, (float)$order->balance_due);
        $branch_name_q = $db->query("SELECT name, address, phone_number FROM branches WHERE id = ? LIMIT 1", [$order->assigned_branch_id])->first();

        $db->insert('invoice_snapshots', [
            'order_id' => $order_id, 'order_number' => $order->order_number, 'snapshot_trigger' => $snapshot_trigger,
            'snapshot_at' => date('Y-m-d H:i:s'), 'customer_id' => $order->customer_id, 'customer_name' => $customer_name,
            'customer_phone' => $customer_data->phone_number ?? null, 'customer_email' => $customer_data->email ?? null,
            'customer_address' => $customer_data->business_address ?? null,
            'previous_due' => $previous_due, 'subtotal' => $order->subtotal, 'discount_amount' => $order->discount_amount,
            'tax_amount' => $order->tax_amount, 'total_amount' => $order->total_amount, 'advance_paid' => $order->advance_paid,
            'balance_due' => $order->balance_due, 'total_outstanding' => $total_outstanding,
            'company_name_bn' => 'উজ্জল ফ্লাওয়ার মিলস', 'company_name_en' => 'Ujjal Flour Mills',
            'company_address' => ($branch_name_q && !empty($branch_name_q->address)) ? $branch_name_q->address : '১৭, নুরাইবাগ, ডেমরা, ঢাকা',
            'company_phone' => ($branch_name_q && !empty($branch_name_q->phone_number)) ? $branch_name_q->phone_number : '+880-XXX-XXXXXX',
            'company_email' => 'info@ujjalfm.com', 'items_json' => json_encode($items_arr, JSON_UNESCAPED_UNICODE),
            'shipping_address' => $order->shipping_address ?? null, 'branch_name' => $branch_name_q ? $branch_name_q->name : null,
            'truck_number' => $opts['truck_number'] ?? null, 'driver_name' => $opts['driver_name'] ?? null, 'driver_contact' => $opts['driver_contact'] ?? null,
            'shipped_date' => $opts['shipped_date'] ?? null, 'order_date' => $order->order_date,
            'required_date' => $order->required_date ?? null, 'invoice_date' => $transaction_date,
            'order_type' => $order->order_type, 'order_status' => $order->status,
            'special_instructions' => $order->special_instructions ?? null, 'created_by_user_id' => $user_id,
        ]);
    }

    return ['journal_id' => (int)$journal_id, 'posted' => true];
}

/**
 * Self-migrate credit_orders.status's ENUM to add 'hold' — cr/order_status.php
 * has offered an "⏸️ On Hold" option (with its own CSS class and valid-transition
 * rules) for a while, but the ENUM itself was never actually migrated to include
 * it. Every attempt to set an order on hold was silently failing (Database::query()
 * swallows the ENUM-constraint PDOException) while the page reported success —
 * found 4 Aug 2026 during a codebase-wide ENUM-mismatch sweep. Probe first, same
 * convention as every other ensure*() helper (DDL implicit-commits open transactions).
 */
function ensureCreditOrderHoldStatus(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    try {
        $col = $pdo->query(
            "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_orders' AND COLUMN_NAME = 'status'"
        )->fetch(PDO::FETCH_OBJ);
        if ($col && strpos($col->t ?? '', "'hold'") === false) {
            $pdo->exec("ALTER TABLE `credit_orders` MODIFY `status`
                ENUM('draft','pending_approval','approved','escalated','rejected','in_production','produced','ready_to_ship','goods_on_board','shipped','delivered','cancelled','hold')
                NOT NULL DEFAULT 'draft'");
        }
    } catch (\Throwable $e) { error_log('ensureCreditOrderHoldStatus: ' . $e->getMessage()); }
}

/**
 * Adds 'barter' to supplier_ledger_adjustments.payment_method's ENUM. The Supplier
 * Sale (Barter/Netting) feature was fully built in purchase_adnan_record_payment.php
 * with 'barter' as the pre-checked default settlement option, but this ENUM migration
 * was never added — so every submission left at the default setting failed. Found
 * during the codebase sweep, 8 Aug 2026.
 */
function ensureSupplierLedgerAdjustmentBarterMethod(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = Database::getInstance()->getPdo();
    try {
        $col = $pdo->query(
            "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_ledger_adjustments' AND COLUMN_NAME = 'payment_method'"
        )->fetch(PDO::FETCH_OBJ);
        if ($col && strpos($col->t ?? '', "'barter'") === false) {
            $pdo->exec("ALTER TABLE `supplier_ledger_adjustments` MODIFY `payment_method`
                ENUM('none','cash','bank','cheque','barter')
                NOT NULL DEFAULT 'none'");
        }
    } catch (\Throwable $e) { error_log('ensureSupplierLedgerAdjustmentBarterMethod: ' . $e->getMessage()); }
}

/**
 * ============================================================================
 * END OF HELPERS
 * ============================================================================
 */
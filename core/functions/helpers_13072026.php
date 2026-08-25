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
                'approval_requests', 'stock_adjustment', 'outstanding_invoices',
            ],
            'nav' => [
                ['file' => 'sales_hub',             'page_key' => 'sales_hub',             'label' => 'Sales Hub',       'icon' => 'fa-route'],
                ['file' => 'sales_dashboard',       'page_key' => 'sales_dashboard',       'label' => 'Sales Dashboard', 'icon' => 'fa-tachometer-alt'],
                ['file' => 'index',                 'page_key' => 'index',                 'label' => 'Credit Dashboard','icon' => 'fa-chart-line', 'hidden' => true],
                ['file' => 'all_sales',             'page_key' => 'all_sales',             'label' => 'All Sales',       'icon' => 'fa-list-alt'],
                ['file' => 'create_order',          'page_key' => 'create_order',          'label' => 'Create Order',    'icon' => 'fa-plus-circle'],
                ['file' => 'credit_order_approval', 'page_key' => 'credit_order_approval', 'label' => 'Approve Orders',  'icon' => 'fa-check-circle'],
                ['file' => 'customer_payment',      'page_key' => 'customer_payment',      'label' => 'Collect Payment', 'icon' => 'fa-money-bill-wave'],
                ['file' => 'outstanding_invoices',  'page_key' => 'outstanding_invoices',  'label' => 'Outstanding Invoices', 'icon' => 'fa-file-invoice-dollar'],
                ['file' => 'payment_history',       'page_key' => 'payment_history',       'label' => 'Payment History', 'icon' => 'fa-history'],
                ['file' => 'advance_payment_collection', 'page_key' => 'advance_payment_collection', 'label' => 'Advance Collection', 'icon' => 'fa-money-bill-wave'],
                ['file' => 'returns',                   'page_key' => 'returns',                   'label' => 'Returns & Adjustments', 'icon' => 'fa-undo'],
                ['file' => 'stock_adjustment',          'page_key' => 'stock_adjustment',          'label' => 'Stock Adjustments',   'icon' => 'fa-sliders-h', 'hidden' => true],
                ['file' => 'payment_watch',             'page_key' => 'payment_watch',             'label' => 'Payment Watch',       'icon' => 'fa-eye'],
                ['file' => 'over_delivery',             'page_key' => 'over_delivery',             'label' => 'Over-Delivery',       'icon' => 'fa-truck-loading'],
                ['file' => 'approval_requests',         'page_key' => 'approval_requests',         'label' => 'Approval Requests',   'icon' => 'fa-user-check'],
            ],
            'page_actions' => [
                'all_sales'                  => ['can_export' => 'Export CSV', 'can_delete' => 'Delete Orders', 'can_edit' => 'Edit Orders'],
                'credit_order_approval'      => ['can_approve' => 'Approve Orders', 'can_reject' => 'Reject Orders', 'can_escalate_override' => 'Override 80% Credit Escalation'],
                'customer_payment'           => ['can_collect' => 'Collect Payment', 'can_override' => 'Override Amount'],
                'create_order'               => ['can_create' => 'Create Orders'],
                'customer_ledger'            => ['can_export' => 'Export Ledger'],
                'payment_history'            => ['can_export' => 'Export Payment History'],
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
                'over_delivery'              => ['can_record' => 'Record Over-Delivery', 'can_approve' => 'Approve / Resolve Over-Delivery'],
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
            'active_files' => ['credit_production', 'credit_dispatch', 'partial_delivery', 'order_status', 'sales_report'],
            'nav' => [
                ['file' => 'credit_production', 'page_key' => 'credit_production', 'label' => 'Production',       'icon' => 'fa-industry'],
                ['file' => 'credit_dispatch',   'page_key' => 'credit_dispatch',   'label' => 'Dispatch',         'icon' => 'fa-shipping-fast'],
                ['file' => 'partial_delivery',  'page_key' => 'partial_delivery',  'label' => 'Partial Delivery', 'icon' => 'fa-dolly'],
                ['file' => 'order_status',      'page_key' => 'order_status',      'label' => 'Track Order',      'icon' => 'fa-map-marker-alt'],
                ['file' => 'sales_report',      'page_key' => 'sales_report',      'label' => 'Sales Report',     'icon' => 'fa-chart-bar'],
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
                ['file' => 'index',                     'page_key' => 'index',                     'label' => 'All Customers',   'icon' => 'fa-users'],
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
                ['file' => 'todays_sales',      'page_key' => 'todays_sales',      'label' => "Today's Sales",    'icon' => 'fa-receipt'],
                ['file' => 'cash_verification', 'page_key' => 'cash_verification', 'label' => 'Cash Verification','icon' => 'fa-coins'],
                ['file' => 'eod',               'page_key' => 'eod',               'label' => 'End of Day',       'icon' => 'fa-calendar-check'],
                ['file' => 'eod_reopen',        'page_key' => 'eod_reopen',        'label' => 'Reopen EOD',       'icon' => 'fa-redo', 'admin_only' => true],
            ],
            'page_actions' => [
                'todays_sales'      => ['can_export' => 'Export CSV'],
                'cash_verification' => ['can_verify' => 'Verify Cash Count'],
                'eod'               => ['can_close' => 'Close Day', 'can_manage' => 'Manage EOD'],
                'eod_reopen'        => ['can_reopen' => 'Reopen Closed Day'],
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
        $db->insert('cr_recycle_bin_rows', [
            'batch_id'     => $batch,
            'op'           => 'delete',
            'source_table' => $table,
            'source_pk'    => (string)($arr[$pkCol] ?? ''),
            'pk_col'       => $pkCol,
            'row_json'     => json_encode($arr, JSON_UNESCAPED_UNICODE),
        ]);
        $n++;
    }
    if ($n > 0) {
        try { $db->query("DELETE FROM `{$table}` WHERE `{$whereCol}` = ?", [$whereVal]); } catch (Exception $e) {}
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

/* ═══════════════ Stock adjustments (Feature #7) ═══════════════
   Create (Accounts/Sales) → approve by a DIFFERENT can_approve user → applies the
   inventory delta AND posts a journal entry. Value uses a creator-entered unit
   cost (the system has no reliable finished-goods cost basis). */

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
        $label = $type === 'delivery' ? 'DELIVERY' : ($type === 'advance' ? 'ADVANCE' : 'PAYMENT');
        $msg = "<b>⏳ {$label} AWAITING APPROVAL</b>\n"
             . "───────────────────────────────\n\n"
             . "• Request: <code>#{$req_id}</code>\n"
             . "• Amount: <b>৳" . number_format($amount, 2) . "</b>\n"
             . ($summary !== '' ? "• {$summary}\n" : '')
             . "• Submitted by: <b>{$maker}</b>\n\n"
             . "<i>Approve in Credit Sales → Approval Requests.</i>";
        (new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID))->sendMessage($msg);
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

/**
 * ============================================================================
 * END OF HELPERS
 * ============================================================================
 */
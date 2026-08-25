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
            ],
            'nav' => [
                ['file' => 'sales_hub',             'page_key' => 'sales_hub',             'label' => 'Sales Hub',       'icon' => 'fa-route'],
                ['file' => 'sales_dashboard',       'page_key' => 'sales_dashboard',       'label' => 'Sales Dashboard', 'icon' => 'fa-tachometer-alt'],
                ['file' => 'index',                 'page_key' => 'index',                 'label' => 'Credit Dashboard','icon' => 'fa-chart-line', 'hidden' => true],
                ['file' => 'all_sales',             'page_key' => 'all_sales',             'label' => 'All Sales',       'icon' => 'fa-list-alt'],
                ['file' => 'create_order',          'page_key' => 'create_order',          'label' => 'Create Order',    'icon' => 'fa-plus-circle'],
                ['file' => 'credit_order_approval', 'page_key' => 'credit_order_approval', 'label' => 'Approve Orders',  'icon' => 'fa-check-circle'],
                ['file' => 'customer_payment',      'page_key' => 'customer_payment',      'label' => 'Collect Payment', 'icon' => 'fa-money-bill-wave'],
                ['file' => 'payment_history',       'page_key' => 'payment_history',       'label' => 'Payment History', 'icon' => 'fa-history'],
                ['file' => 'advance_payment_collection', 'page_key' => 'advance_payment_collection', 'label' => 'Advance Collection', 'icon' => 'fa-money-bill-wave'],
                ['file' => 'returns',                   'page_key' => 'returns',                   'label' => 'Returns',             'icon' => 'fa-undo'],
                ['file' => 'payment_watch',             'page_key' => 'payment_watch',             'label' => 'Payment Watch',       'icon' => 'fa-eye'],
                ['file' => 'over_delivery',             'page_key' => 'over_delivery',             'label' => 'Over-Delivery',       'icon' => 'fa-truck-loading'],
            ],
            'page_actions' => [
                'all_sales'                  => ['can_export' => 'Export CSV', 'can_delete' => 'Delete Orders', 'can_edit' => 'Edit Orders'],
                'credit_order_approval'      => ['can_approve' => 'Approve Orders', 'can_reject' => 'Reject Orders', 'can_escalate_override' => 'Override 80% Credit Escalation'],
                'customer_payment'           => ['can_collect' => 'Collect Payment', 'can_override' => 'Override Amount'],
                'create_order'               => ['can_create' => 'Create Orders'],
                'customer_ledger'            => ['can_export' => 'Export Ledger'],
                'payment_history'            => ['can_export' => 'Export Payment History'],
                'returns'                    => ['can_approve' => 'Auto-Approve Returns', 'can_reject' => 'Reject Returns'],
                'advance_payment_collection' => ['can_collect' => 'Collect Advance Payment'],
                'credit_order_view'          => ['can_view' => 'View Order Detail'],
                'credit_payment_collect'     => ['can_collect' => 'Collect Payment'],
                'ageing_report'              => ['can_export' => 'Export Ageing Report'],
                'bank_statement'             => ['can_export' => 'Export Bank Statement'],
                'payment_watch'              => ['can_grant_clearance' => 'Grant Dispatch Clearance', 'can_revoke_clearance' => 'Revoke Clearance'],
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
                ['file' => 'products',         'page_key' => 'products',         'label' => 'Overview',         'icon' => 'fa-box'],
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
                ['file' => 'db_viewer',          'page_key' => 'db_viewer',          'label' => 'DB Viewer',          'icon' => 'fa-database',      'admin_only' => true],
                ['file' => 'drive_manager',      'page_key' => 'drive_manager',      'label' => 'Drive & Backups',    'icon' => 'fa-cloud-arrow-up',   'admin_only' => true],
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
function getOrderGateState(int $order_id): array {
    global $db;
    $state = [
        'has_conditions' => false, 'row' => null,
        'production' => 'open', 'dispatch' => 'open',
        'threshold' => null, 'current' => null, 'shortfall' => null,
    ];

    ensureApprovalGateTables();
    try {
        $row = $db->query("SELECT * FROM order_approval_conditions WHERE order_id = ?", [$order_id])->first();
    } catch (Exception $e) {
        error_log('getOrderGateState: ' . $e->getMessage());
        return $state;
    }
    if (!$row) return $state;

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
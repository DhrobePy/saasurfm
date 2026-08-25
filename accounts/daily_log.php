<?php
require_once '../core/init.php';

global $db;

// Restrict access to accounts roles
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle = "Daily Activities - Accounts";

// Get current user
$currentUser = getCurrentUser();

// Get selected date (default to today)
$selected_date = $_GET['date'] ?? date('Y-m-d');
$date_obj = new DateTime($selected_date);

// Get all branches for filter
$branches = $db->query("SELECT id, name FROM branches ORDER BY name")->results();

// Get selected branch (default to all)
$selected_branch = $_GET['branch'] ?? 'all';

// Build branch filter for queries (positional style)
$branch_where = ($selected_branch !== 'all') ? ' AND {table}.branch_id = ?' : '';
$branch_val   = ($selected_branch !== 'all') ? [intval($selected_branch)] : [];

// 1. Get Journal Entries (All Accounting Transactions)
$journal_entries = $db->query("
    SELECT
        je.id,
        je.uuid,
        je.transaction_date,
        je.related_document_type,
        je.related_document_id,
        je.description,
        je.created_by_user_id,
        u.display_name as posted_by,
        je.created_at,
        (SELECT SUM(debit_amount) FROM transaction_lines WHERE journal_entry_id = je.id) as total_debit,
        (SELECT SUM(credit_amount) FROM transaction_lines WHERE journal_entry_id = je.id) as total_credit
    FROM journal_entries je
    LEFT JOIN users u ON je.created_by_user_id = u.id
    WHERE DATE(je.transaction_date) = ?
    ORDER BY je.created_at DESC
", [$selected_date])->results();

// 2. Get Credit Orders
$co_branch = str_replace('{table}', 'co', $branch_where);
$credit_orders = $db->query("
    SELECT
        co.id,
        co.order_number,
        co.order_date,
        co.required_date,
        co.status,
        co.total_amount,
        co.amount_paid,
        co.balance_due,
        co.total_weight_kg,
        c.name as customer_name,
        c.phone_number as customer_phone,
        b.name as branch_name,
        u.display_name as created_by,
        co.created_at
    FROM credit_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN branches b ON co.assigned_branch_id = b.id
    LEFT JOIN users u ON co.created_by_user_id = u.id
    WHERE DATE(co.order_date) = ?
    " . str_replace('{table}', 'co', str_replace('branch_id', 'assigned_branch_id', $branch_where)) . "
    ORDER BY co.created_at DESC
", array_merge([$selected_date], $branch_val))->results();

// 3. Get POS Sales (from orders table where order_type = 'POS')
$pos_sales = $db->query("
    SELECT
        o.id,
        o.order_number,
        o.order_date,
        o.total_amount,
        o.payment_method,
        o.order_type,
        c.name as customer_name,
        c.phone_number as customer_phone,
        b.name as branch_name,
        u.display_name as cashier,
        o.created_at
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    LEFT JOIN branches b ON o.branch_id = b.id
    LEFT JOIN users u ON o.created_by_user_id = u.id
    WHERE DATE(o.order_date) = ?
    AND o.order_type = 'POS'
    " . str_replace('{table}', 'o', $branch_where) . "
    ORDER BY o.order_date DESC
", array_merge([$selected_date], $branch_val))->results();

// 4. Get Purchase Invoices
$purchase_invoices = $db->query("
    SELECT
        pi.id,
        pi.invoice_number,
        pi.invoice_date,
        pi.total_amount,
        pi.payment_status,
        s.company_name as supplier_name,
        s.phone as supplier_phone,
        b.name as branch_name,
        u.display_name as created_by,
        pi.created_at,
        'invoice' as purchase_source
    FROM purchase_invoices pi
    LEFT JOIN suppliers s ON pi.supplier_id = s.id
    LEFT JOIN branches b ON pi.branch_id = b.id
    LEFT JOIN users u ON pi.created_by_user_id = u.id
    WHERE DATE(pi.invoice_date) = ?
    " . str_replace('{table}', 'pi', $branch_where) . "
    ORDER BY pi.created_at DESC
", array_merge([$selected_date], $branch_val))->results();

// Add goods received (wheat GRN)
$grn_records = $db->query("
    SELECT
        grn.id,
        grn.grn_number as invoice_number,
        grn.grn_date as invoice_date,
        grn.total_value as total_amount,
        'received' as payment_status,
        grn.supplier_name,
        s.phone as supplier_phone,
        b.name as branch_name,
        u.display_name as created_by,
        grn.created_at,
        'grn' as purchase_source
    FROM goods_received_adnan grn
    LEFT JOIN suppliers s ON grn.supplier_id = s.id
    LEFT JOIN branches b ON grn.unload_point_branch_id = b.id
    LEFT JOIN users u ON grn.receiver_user_id = u.id
    WHERE DATE(grn.grn_date) = ?
    ORDER BY grn.created_at DESC
", [$selected_date])->results();

// Merge invoices and GRNs
$purchase_invoices = array_merge($purchase_invoices, $grn_records);

// 5. Get Customer Payments
$customer_payments = $db->query("
    SELECT
        cp.id,
        cp.payment_number,
        cp.payment_date,
        cp.amount,
        cp.payment_method,
        cp.allocation_status,
        c.name as customer_name,
        c.phone_number as customer_phone,
        b.name as branch_name,
        COALESCE(ba.account_name, ca.name) as payment_account,
        u.display_name as received_by,
        cp.created_at
    FROM customer_payments cp
    LEFT JOIN customers c ON cp.customer_id = c.id
    LEFT JOIN branches b ON cp.branch_id = b.id
    LEFT JOIN bank_accounts ba ON cp.bank_account_id = ba.id
    LEFT JOIN chart_of_accounts ca ON cp.cash_account_id = ca.id
    LEFT JOIN users u ON cp.created_by_user_id = u.id
    WHERE DATE(cp.payment_date) = ?
    " . str_replace('{table}', 'cp', $branch_where) . "
    ORDER BY cp.created_at DESC
", array_merge([$selected_date], $branch_val))->results();

// 6. Get Supplier Payments
$supplier_payments = $db->query("
    SELECT
        sp.id,
        sp.payment_number,
        sp.payment_date,
        sp.amount,
        sp.payment_method,
        s.company_name as supplier_name,
        s.phone as supplier_phone,
        b.name as branch_name,
        coa.name as payment_account,
        u.display_name as paid_by,
        sp.created_at,
        'general' as payment_source
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON sp.supplier_id = s.id
    LEFT JOIN branches b ON sp.branch_id = b.id
    LEFT JOIN chart_of_accounts coa ON sp.payment_account_id = coa.id
    LEFT JOIN users u ON sp.created_by_user_id = u.id
    WHERE DATE(sp.payment_date) = ?
    " . str_replace('{table}', 'sp', $branch_where) . "
    ORDER BY sp.created_at DESC
", array_merge([$selected_date], $branch_val))->results();

// Add wheat purchase payments (Adnan module)
$wheat_payments = $db->query("
    SELECT
        pp.id,
        pp.payment_voucher_number as payment_number,
        pp.payment_date,
        pp.amount_paid as amount,
        pp.payment_method,
        pp.supplier_name,
        s.phone as supplier_phone,
        NULL as branch_name,
        pp.bank_name as payment_account,
        u.display_name as paid_by,
        pp.created_at,
        'wheat' as payment_source
    FROM purchase_payments_adnan pp
    LEFT JOIN suppliers s ON pp.supplier_id = s.id
    LEFT JOIN users u ON pp.created_by_user_id = u.id
    WHERE DATE(pp.payment_date) = ?
    ORDER BY pp.created_at DESC
", [$selected_date])->results();

// Merge both payment types
$supplier_payments = array_merge($supplier_payments, $wheat_payments);

// 7. Get Transport Expenses
$transport_expenses = $db->query("
    SELECT
        te.id,
        te.expense_date,
        te.expense_type,
        te.amount,
        te.description,
        v.vehicle_number as vehicle,
        d.driver_name as driver_name,
        ta.id as trip_number,
        u.display_name as created_by,
        te.created_at,
        'transport' as expense_source
    FROM transport_expenses te
    LEFT JOIN vehicles v ON te.vehicle_id = v.id
    LEFT JOIN trip_assignments ta ON te.trip_id = ta.id
    LEFT JOIN drivers d ON ta.driver_id = d.id
    LEFT JOIN users u ON te.created_by_user_id = u.id
    WHERE DATE(te.expense_date) = ?
    ORDER BY te.created_at DESC
", [$selected_date])->results();

// Add fuel logs
$fuel_logs = $db->query("
    SELECT
        fl.id,
        fl.fuel_date as expense_date,
        CONCAT('Fuel - ', COALESCE(fl.fuel_type, 'Unknown')) as expense_type,
        fl.total_cost as amount,
        CONCAT(fl.quantity_liters, 'L @ ৳', fl.price_per_liter, '/L',
               CASE WHEN fl.station_name IS NOT NULL THEN CONCAT(' from ', fl.station_name) ELSE '' END) as description,
        v.vehicle_number as vehicle,
        d.driver_name as driver_name,
        ta.id as trip_number,
        u.display_name as created_by,
        fl.created_at,
        'fuel' as expense_source
    FROM fuel_logs fl
    LEFT JOIN vehicles v ON fl.vehicle_id = v.id
    LEFT JOIN trip_assignments ta ON fl.trip_id = ta.id
    LEFT JOIN drivers d ON ta.driver_id = d.id
    LEFT JOIN users u ON fl.created_by_user_id = u.id
    WHERE DATE(fl.fuel_date) = ?
    ORDER BY fl.created_at DESC
", [$selected_date])->results();

// Merge transport expenses and fuel logs
$transport_expenses = array_merge($transport_expenses, $fuel_logs);

// 8. Get Debit Vouchers (Other Expenses)
$debit_vouchers = $db->query("
    SELECT
        dv.id,
        dv.voucher_number,
        dv.voucher_date,
        dv.amount,
        dv.description,
        dv.paid_to,
        b.name as branch_name,
        coa.name as expense_account,
        u.display_name as created_by,
        dv.created_at
    FROM debit_vouchers dv
    LEFT JOIN branches b ON dv.branch_id = b.id
    LEFT JOIN chart_of_accounts coa ON dv.expense_account_id = coa.id
    LEFT JOIN users u ON dv.created_by_user_id = u.id
    WHERE DATE(dv.voucher_date) = ?
    " . str_replace('{table}', 'dv', $branch_where) . "
    ORDER BY dv.created_at DESC
", array_merge([$selected_date], $branch_val))->results();

// 9. Get Credit Order Returns
try {
    $returns = $db->query("
        SELECT cor.id, cor.return_number, cor.return_date, cor.return_type,
               cor.total_returned_amount, cor.total_returned_qty, cor.status, cor.reason,
               co.order_number, c.name as customer_name, c.phone_number as customer_phone,
               u.display_name as created_by, cor.created_at
        FROM credit_order_returns cor
        JOIN credit_orders co ON cor.order_id = co.id
        JOIN customers c ON cor.customer_id = c.id
        LEFT JOIN users u ON cor.created_by_user_id = u.id
        WHERE DATE(cor.return_date) = ?
        ORDER BY cor.created_at DESC
    ", [$selected_date])->results();
} catch (\Throwable $e) {
    $returns = [];
}

// 10. Get Partial Deliveries
try {
    $deliveries = $db->query("
        SELECT cod.id, cod.delivery_number, cod.delivery_date, cod.total_amount_delivered,
               cod.total_qty_delivered, cod.truck_number, cod.driver_name, cod.is_final,
               co.order_number, c.name as customer_name, c.phone_number as customer_phone,
               u.display_name as created_by, cod.created_at
        FROM credit_order_deliveries cod
        JOIN credit_orders co ON cod.order_id = co.id
        JOIN customers c ON co.customer_id = c.id
        LEFT JOIN users u ON cod.created_by_user_id = u.id
        WHERE DATE(cod.delivery_date) = ?
        ORDER BY cod.created_at DESC
    ", [$selected_date])->results();
} catch (\Throwable $e) {
    $deliveries = [];
}

// 11. Get Expense Vouchers
try {
    $ev_branch_where = ($selected_branch !== 'all') ? ' AND ev.branch_id = ?' : '';
    $ev_params = array_merge([$selected_date], ($selected_branch !== 'all') ? [intval($selected_branch)] : []);
    $expense_vouchers = $db->query("
        SELECT ev.id, ev.voucher_number, ev.expense_date, ev.total_amount,
               ev.payment_method, ev.status, ev.remarks, ev.handled_by_person,
               ec.category_name, esc.subcategory_name,
               b.name as branch_name, u.display_name as created_by, ev.created_at
        FROM expense_vouchers ev
        LEFT JOIN expense_categories ec ON ev.category_id = ec.id
        LEFT JOIN expense_subcategories esc ON ev.subcategory_id = esc.id
        LEFT JOIN branches b ON ev.branch_id = b.id
        LEFT JOIN users u ON ev.created_by_user_id = u.id
        WHERE DATE(ev.expense_date) = ?{$ev_branch_where}
        ORDER BY ev.created_at DESC
    ", $ev_params)->results();
} catch (\Throwable $e) {
    $expense_vouchers = [];
}

// 12. Get Bank Transactions
try {
    $bt_branch_where = ($selected_branch !== 'all') ? ' AND bt.branch_id = ?' : '';
    $bt_params = array_merge([$selected_date], ($selected_branch !== 'all') ? [intval($selected_branch)] : []);
    $bank_transactions = $db->query("
        SELECT bt.id, bt.transaction_number, bt.transaction_date, bt.entry_type,
               bt.amount, bt.reference_number, bt.payee_payer_name, bt.description,
               bt.status, bta.account_name, bta.bank_name,
               b.name as branch_name, u.display_name as created_by, bt.created_at
        FROM bank_transactions bt
        LEFT JOIN bank_tx_accounts bta ON bt.bank_tx_account_id = bta.id
        LEFT JOIN branches b ON bt.branch_id = b.id
        LEFT JOIN users u ON bt.created_by_user_id = u.id
        WHERE DATE(bt.transaction_date) = ?{$bt_branch_where}
        ORDER BY bt.created_at DESC
    ", $bt_params)->results();
} catch (\Throwable $e) {
    $bank_transactions = [];
}

// 13. Get Bank Transfers
try {
    $bank_transfers = $db->query("
        SELECT btt.id, btt.transfer_number, btt.transfer_date,
               btt.amount, btt.reference_number, btt.description, btt.status,
               fa.account_name as from_account, fa.bank_name as from_bank,
               ta.account_name as to_account, ta.bank_name as to_bank,
               u.display_name as initiated_by, btt.created_at
        FROM bank_tx_transfers btt
        LEFT JOIN bank_tx_accounts fa ON btt.from_account_id = fa.id
        LEFT JOIN bank_tx_accounts ta ON btt.to_account_id = ta.id
        LEFT JOIN users u ON btt.initiated_by_user_id = u.id
        WHERE DATE(btt.transfer_date) = ?
        ORDER BY btt.created_at DESC
    ", [$selected_date])->results();
} catch (\Throwable $e) {
    $bank_transfers = [];
}

// 14. Get Purchase Returns
try {
    $pr_branch_where = ($selected_branch !== 'all') ? ' AND pr.branch_id = ?' : '';
    $pr_params = array_merge([$selected_date], ($selected_branch !== 'all') ? [intval($selected_branch)] : []);
    $purchase_returns = $db->query("
        SELECT pr.id, pr.return_number, pr.return_date, pr.return_reason,
               pr.subtotal, s.company_name as supplier_name, s.phone as supplier_phone,
               b.name as branch_name, u.display_name as created_by, pr.created_at
        FROM purchase_returns pr
        LEFT JOIN suppliers s ON pr.supplier_id = s.id
        LEFT JOIN branches b ON pr.branch_id = b.id
        LEFT JOIN users u ON pr.created_by_user_id = u.id
        WHERE DATE(pr.return_date) = ?{$pr_branch_where}
        ORDER BY pr.created_at DESC
    ", $pr_params)->results();
} catch (\Throwable $e) {
    $purchase_returns = [];
}

// 15. Get Petty Cash Transactions
try {
    $pc_branch_where = ($selected_branch !== 'all') ? ' AND pct.branch_id = ?' : '';
    $pc_params = array_merge([$selected_date], ($selected_branch !== 'all') ? [intval($selected_branch)] : []);
    $petty_cash = $db->query("
        SELECT pct.id, pct.transaction_date, pct.transaction_type, pct.amount,
               pct.balance_after, pct.reference_type, pct.description, pct.payment_method,
               pca.account_name, b.name as branch_name,
               u.display_name as created_by, pct.created_at
        FROM branch_petty_cash_transactions pct
        LEFT JOIN branch_petty_cash_accounts pca ON pct.account_id = pca.id
        LEFT JOIN branches b ON pct.branch_id = b.id
        LEFT JOIN users u ON pct.created_by_user_id = u.id
        WHERE DATE(pct.transaction_date) = ?{$pc_branch_where}
        ORDER BY pct.created_at DESC
    ", $pc_params)->results();
} catch (\Throwable $e) {
    $petty_cash = [];
}

// Calculate Summary Statistics
$summary_stats = [
    'total_journal_entries' => count($journal_entries),
    'total_debit' => array_sum(array_map(fn($j) => $j->total_debit ?? 0, $journal_entries)),
    'total_credit' => array_sum(array_map(fn($j) => $j->total_credit ?? 0, $journal_entries)),
    'credit_orders_count' => count($credit_orders),
    'credit_orders_amount' => array_sum(array_map(fn($o) => $o->total_amount ?? 0, $credit_orders)),
    'pos_sales_count' => count($pos_sales),
    'pos_sales_amount' => array_sum(array_map(fn($p) => $p->total_amount ?? 0, $pos_sales)),
    'purchase_invoices_count' => count($purchase_invoices),
    'purchase_invoices_amount' => array_sum(array_map(fn($pi) => $pi->total_amount ?? 0, $purchase_invoices)),
    'customer_payments_count' => count($customer_payments),
    'customer_payments_amount' => array_sum(array_map(fn($cp) => $cp->amount ?? 0, $customer_payments)),
    'supplier_payments_count' => count($supplier_payments),
    'supplier_payments_amount' => array_sum(array_map(fn($sp) => $sp->amount ?? 0, $supplier_payments)),
    'transport_expenses_count' => count($transport_expenses),
    'transport_expenses_amount' => array_sum(array_map(fn($te) => $te->amount ?? 0, $transport_expenses)),
    'debit_vouchers_count' => count($debit_vouchers),
    'debit_vouchers_amount' => array_sum(array_map(fn($dv) => $dv->amount ?? 0, $debit_vouchers)),
    'returns_count'  => count($returns),
    'returns_amount' => array_sum(array_map(fn($r) => $r->total_returned_amount ?? 0, $returns)),
    'deliveries_count'  => count($deliveries),
    'deliveries_amount' => array_sum(array_map(fn($d) => $d->total_amount_delivered ?? 0, $deliveries)),
    'expense_vouchers_count'  => count($expense_vouchers),
    'expense_vouchers_amount' => array_sum(array_map(fn($ev) => $ev->total_amount ?? 0, $expense_vouchers)),
    'bank_transactions_count'  => count($bank_transactions),
    'bank_transactions_debit'  => array_sum(array_map(fn($bt) => ($bt->entry_type === 'debit')  ? ($bt->amount ?? 0) : 0, $bank_transactions)),
    'bank_transactions_credit' => array_sum(array_map(fn($bt) => ($bt->entry_type === 'credit') ? ($bt->amount ?? 0) : 0, $bank_transactions)),
    'bank_transfers_count'     => count($bank_transfers),
    'bank_transfers_amount'    => array_sum(array_map(fn($bt) => $bt->amount ?? 0, $bank_transfers)),
    'purchase_returns_count'   => count($purchase_returns),
    'purchase_returns_amount'  => array_sum(array_map(fn($pr) => $pr->subtotal ?? 0, $purchase_returns)),
    'petty_cash_count'         => count($petty_cash),
    'petty_cash_in'            => array_sum(array_map(fn($pc) => in_array($pc->transaction_type, ['cash_in', 'transfer_in', 'opening_balance', 'adjustment']) && ($pc->amount ?? 0) > 0 ? ($pc->amount ?? 0) : 0, $petty_cash)),
    'petty_cash_out'           => array_sum(array_map(fn($pc) => in_array($pc->transaction_type, ['cash_out', 'transfer_out']) ? ($pc->amount ?? 0) : 0, $petty_cash)),
];

require_once '../templates/header.php';
?>

<style>
    .activity-card {
        transition: all 0.3s ease;
    }
    .activity-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    }
    .stat-card {
        transition: all 0.3s ease;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    }
    .tab-button {
        transition: all 0.2s ease;
    }
    .tab-button.active {
        border-bottom: 3px solid #0ea5e9;
        color: #0ea5e9;
    }
    @media print {
        .no-print {
            display: none;
        }
        .activity-card {
            break-inside: avoid;
        }
    }
</style>

<div class="container mx-auto px-4 py-8">
    <!-- Page Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">
            <i class="fas fa-calendar-day text-primary-600 mr-3"></i>
            Daily Activities Dashboard
        </h1>
        <p class="text-gray-600">Comprehensive view of all business activities for <?php echo $date_obj->format('l, F j, Y'); ?></p>
    </div>

    <!-- Flash Messages -->
    <?php echo display_message(); ?>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6 no-print">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-calendar mr-2"></i>Select Date
                </label>
                <input type="date"
                       name="date"
                       value="<?php echo htmlspecialchars($selected_date); ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-building mr-2"></i>Branch
                </label>
                <select name="branch"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    <option value="all" <?php echo $selected_branch === 'all' ? 'selected' : ''; ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo $branch->id; ?>"
                                <?php echo $selected_branch == $branch->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($branch->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex items-end">
                <button type="submit"
                        class="w-full bg-primary-600 text-white px-6 py-2 rounded-lg hover:bg-primary-700 transition-colors">
                    <i class="fas fa-filter mr-2"></i>Apply Filter
                </button>
            </div>
        </form>
    </div>

    <!-- Summary Statistics - Row 1 -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-4">
        <!-- Journal Entries -->
        <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-white bg-opacity-20 rounded-lg">
                    <i class="fas fa-book-open text-2xl"></i>
                </div>
                <span class="text-3xl font-bold"><?php echo $summary_stats['total_journal_entries']; ?></span>
            </div>
            <h3 class="text-lg font-semibold mb-1">Journal Entries</h3>
            <p class="text-sm opacity-90">Dr: ৳<?php echo number_format($summary_stats['total_debit'], 2); ?></p>
            <p class="text-sm opacity-90">Cr: ৳<?php echo number_format($summary_stats['total_credit'], 2); ?></p>
        </div>

        <!-- Sales Revenue -->
        <div class="stat-card bg-gradient-to-br from-green-500 to-green-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-white bg-opacity-20 rounded-lg">
                    <i class="fas fa-shopping-cart text-2xl"></i>
                </div>
                <span class="text-3xl font-bold"><?php echo $summary_stats['credit_orders_count'] + $summary_stats['pos_sales_count']; ?></span>
            </div>
            <h3 class="text-lg font-semibold mb-1">Total Sales</h3>
            <p class="text-sm opacity-90">Credit: ৳<?php echo number_format($summary_stats['credit_orders_amount'], 2); ?></p>
            <p class="text-sm opacity-90">POS: ৳<?php echo number_format($summary_stats['pos_sales_amount'], 2); ?></p>
        </div>

        <!-- Payments -->
        <div class="stat-card bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-white bg-opacity-20 rounded-lg">
                    <i class="fas fa-money-bill-wave text-2xl"></i>
                </div>
                <span class="text-3xl font-bold"><?php echo $summary_stats['customer_payments_count'] + $summary_stats['supplier_payments_count']; ?></span>
            </div>
            <h3 class="text-lg font-semibold mb-1">Payments</h3>
            <p class="text-sm opacity-90">Received: ৳<?php echo number_format($summary_stats['customer_payments_amount'], 2); ?></p>
            <p class="text-sm opacity-90">Paid: ৳<?php echo number_format($summary_stats['supplier_payments_amount'], 2); ?></p>
        </div>

        <!-- Expenses -->
        <div class="stat-card bg-gradient-to-br from-red-500 to-red-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-white bg-opacity-20 rounded-lg">
                    <i class="fas fa-receipt text-2xl"></i>
                </div>
                <span class="text-3xl font-bold"><?php echo $summary_stats['purchase_invoices_count'] + $summary_stats['transport_expenses_count'] + $summary_stats['debit_vouchers_count'] + $summary_stats['expense_vouchers_count']; ?></span>
            </div>
            <h3 class="text-lg font-semibold mb-1">Expenses</h3>
            <p class="text-sm opacity-90">Purchases: ৳<?php echo number_format($summary_stats['purchase_invoices_amount'], 2); ?></p>
            <p class="text-sm opacity-90">Others: ৳<?php echo number_format($summary_stats['transport_expenses_amount'] + $summary_stats['debit_vouchers_amount'] + $summary_stats['expense_vouchers_amount'], 2); ?></p>
        </div>
    </div>

    <!-- Summary Statistics - Row 2 (Returns + Deliveries) -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
        <!-- Returns -->
        <div class="stat-card bg-gradient-to-br from-rose-500 to-pink-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-white bg-opacity-20 rounded-lg">
                    <i class="fas fa-undo-alt text-2xl"></i>
                </div>
                <span class="text-3xl font-bold"><?php echo $summary_stats['returns_count']; ?></span>
            </div>
            <h3 class="text-lg font-semibold mb-1">Returns</h3>
            <p class="text-sm opacity-90">Amount: ৳<?php echo number_format($summary_stats['returns_amount'], 2); ?></p>
        </div>

        <!-- Deliveries -->
        <div class="stat-card bg-gradient-to-br from-teal-500 to-teal-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-white bg-opacity-20 rounded-lg">
                    <i class="fas fa-dolly text-2xl"></i>
                </div>
                <span class="text-3xl font-bold"><?php echo $summary_stats['deliveries_count']; ?></span>
            </div>
            <h3 class="text-lg font-semibold mb-1">Deliveries</h3>
            <p class="text-sm opacity-90">Amount: ৳<?php echo number_format($summary_stats['deliveries_amount'], 2); ?></p>
        </div>
    </div>

    <!-- Summary Statistics - Row 3 (Bank + Purchase Returns + Petty Cash) -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Bank Transactions -->
        <div class="stat-card bg-gradient-to-br from-sky-500 to-sky-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-white bg-opacity-20 rounded-lg">
                    <i class="fas fa-university text-2xl"></i>
                </div>
                <span class="text-3xl font-bold"><?php echo $summary_stats['bank_transactions_count']; ?></span>
            </div>
            <h3 class="text-lg font-semibold mb-1">Bank Transactions</h3>
            <p class="text-sm opacity-90">Debit: ৳<?php echo number_format($summary_stats['bank_transactions_debit'], 2); ?></p>
            <p class="text-sm opacity-90">Credit: ৳<?php echo number_format($summary_stats['bank_transactions_credit'], 2); ?></p>
        </div>

        <!-- Bank Transfers -->
        <div class="stat-card bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-white bg-opacity-20 rounded-lg">
                    <i class="fas fa-exchange-alt text-2xl"></i>
                </div>
                <span class="text-3xl font-bold"><?php echo $summary_stats['bank_transfers_count']; ?></span>
            </div>
            <h3 class="text-lg font-semibold mb-1">Bank Transfers</h3>
            <p class="text-sm opacity-90">Amount: ৳<?php echo number_format($summary_stats['bank_transfers_amount'], 2); ?></p>
        </div>

        <!-- Purchase Returns -->
        <div class="stat-card bg-gradient-to-br from-orange-500 to-orange-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-white bg-opacity-20 rounded-lg">
                    <i class="fas fa-undo text-2xl"></i>
                </div>
                <span class="text-3xl font-bold"><?php echo $summary_stats['purchase_returns_count']; ?></span>
            </div>
            <h3 class="text-lg font-semibold mb-1">Purchase Returns</h3>
            <p class="text-sm opacity-90">Amount: ৳<?php echo number_format($summary_stats['purchase_returns_amount'], 2); ?></p>
        </div>

        <!-- Petty Cash -->
        <div class="stat-card bg-gradient-to-br from-lime-500 to-lime-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-white bg-opacity-20 rounded-lg">
                    <i class="fas fa-coins text-2xl"></i>
                </div>
                <span class="text-3xl font-bold"><?php echo $summary_stats['petty_cash_count']; ?></span>
            </div>
            <h3 class="text-lg font-semibold mb-1">Petty Cash</h3>
            <p class="text-sm opacity-90">In: ৳<?php echo number_format($summary_stats['petty_cash_in'], 2); ?></p>
            <p class="text-sm opacity-90">Out: ৳<?php echo number_format($summary_stats['petty_cash_out'], 2); ?></p>
        </div>
    </div>

    <!-- Tabbed Activity Sections -->
    <div class="bg-white rounded-lg shadow-lg overflow-hidden" x-data="{ activeTab: 'journal' }">
        <!-- Tab Navigation -->
        <div class="border-b border-gray-200 overflow-x-auto no-print">
            <nav class="flex space-x-4 px-6 min-w-max">
                <button @click="activeTab = 'journal'"
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'journal' }">
                    <i class="fas fa-book-open mr-2"></i>Journal Entries (<?php echo count($journal_entries); ?>)
                </button>
                <button @click="activeTab = 'credit'"
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'credit' }">
                    <i class="fas fa-file-invoice mr-2"></i>Credit Orders (<?php echo count($credit_orders); ?>)
                </button>
                <button @click="activeTab = 'pos'"
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'pos' }">
                    <i class="fas fa-cash-register mr-2"></i>POS Sales (<?php echo count($pos_sales); ?>)
                </button>
                <button @click="activeTab = 'purchases'"
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'purchases' }">
                    <i class="fas fa-shopping-bag mr-2"></i>Purchases (<?php echo count($purchase_invoices); ?>)
                </button>
                <button @click="activeTab = 'customer_payments'"
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'customer_payments' }">
                    <i class="fas fa-hand-holding-usd mr-2"></i>Customer Payments (<?php echo count($customer_payments); ?>)
                </button>
                <button @click="activeTab = 'supplier_payments'"
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'supplier_payments' }">
                    <i class="fas fa-money-check mr-2"></i>Supplier Payments (<?php echo count($supplier_payments); ?>)
                </button>
                <button @click="activeTab = 'expenses'"
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'expenses' }">
                    <i class="fas fa-truck mr-2"></i>Transport (<?php echo count($transport_expenses); ?>)
                </button>
                <button @click="activeTab = 'vouchers'"
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'vouchers' }">
                    <i class="fas fa-file-invoice-dollar mr-2"></i>Debit Vouchers (<?php echo count($debit_vouchers); ?>)
                </button>
                <button @click="activeTab = 'returns'"
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'returns' }">
                    <i class="fas fa-undo-alt mr-2"></i>Returns (<?php echo count($returns); ?>)
                </button>
                <button @click="activeTab = 'deliveries'"
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'deliveries' }">
                    <i class="fas fa-dolly mr-2"></i>Deliveries (<?php echo count($deliveries); ?>)
                </button>
                <button @click="activeTab = 'expense_vouchers'"
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'expense_vouchers' }">
                    <i class="fas fa-file-alt mr-2"></i>Expense Vouchers (<?php echo count($expense_vouchers); ?>)
                </button>
                <button @click="activeTab = 'bank_transactions'"
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'bank_transactions' }">
                    <i class="fas fa-university mr-2"></i>Bank Transactions (<?php echo count($bank_transactions); ?>)
                </button>
                <button @click="activeTab = 'bank_transfers'"
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'bank_transfers' }">
                    <i class="fas fa-exchange-alt mr-2"></i>Bank Transfers (<?php echo count($bank_transfers); ?>)
                </button>
                <button @click="activeTab = 'purchase_returns'"
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'purchase_returns' }">
                    <i class="fas fa-undo mr-2"></i>Purchase Returns (<?php echo count($purchase_returns); ?>)
                </button>
                <button @click="activeTab = 'petty_cash'"
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'petty_cash' }">
                    <i class="fas fa-coins mr-2"></i>Petty Cash (<?php echo count($petty_cash); ?>)
                </button>
            </nav>
        </div>

        <!-- Tab Content -->
        <div class="p-6">
            <!-- Journal Entries Tab -->
            <div x-show="activeTab === 'journal'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-book-open text-primary-600 mr-2"></i>Journal Entries
                </h2>
                <?php if (empty($journal_entries)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No journal entries found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($journal_entries as $entry): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            Entry #<?php echo htmlspecialchars($entry->id); ?>
                                            <span class="ml-2 text-sm text-gray-500">
                                                <?php echo htmlspecialchars($entry->related_document_type ?? 'General'); ?>
                                            </span>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <?php echo htmlspecialchars($entry->description ?? ''); ?>
                                        </p>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-lg font-semibold text-green-600">
                                            Dr: ৳<?php echo number_format($entry->total_debit ?? 0, 2); ?>
                                        </div>
                                        <div class="text-lg font-semibold text-blue-600">
                                            Cr: ৳<?php echo number_format($entry->total_credit ?? 0, 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($entry->posted_by ?? 'System'); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($entry->created_at)); ?></span>
                                    <a href="journal_entry_view.php?id=<?php echo $entry->id; ?>"
                                       class="ml-auto px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors text-xs">
                                        <i class="fas fa-eye mr-1"></i>View
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Credit Orders Tab -->
            <div x-show="activeTab === 'credit'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-file-invoice text-primary-600 mr-2"></i>Credit Orders
                </h2>
                <?php if (empty($credit_orders)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No credit orders found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($credit_orders as $order): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($order->order_number); ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php
                                                echo match($order->status) {
                                                    'approved' => 'bg-green-100 text-green-800',
                                                    'pending_approval' => 'bg-yellow-100 text-yellow-800',
                                                    'ready_to_ship' => 'bg-blue-100 text-blue-800',
                                                    'shipped' => 'bg-purple-100 text-purple-800',
                                                    default => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $order->status)); ?>
                                            </span>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($order->customer_name); ?>
                                            <?php if ($order->customer_phone): ?>
                                                <span class="ml-2"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($order->customer_phone); ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-gray-900">
                                            ৳<?php echo number_format($order->total_amount, 2); ?>
                                        </div>
                                        <?php if ($order->balance_due > 0): ?>
                                            <div class="text-sm text-red-600">
                                                Due: ৳<?php echo number_format($order->balance_due, 2); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <?php if ($order->branch_name): ?>
                                        <span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($order->branch_name); ?></span>
                                    <?php endif; ?>
                                    <?php if ($order->total_weight_kg): ?>
                                        <span><i class="fas fa-weight mr-1"></i><?php echo number_format($order->total_weight_kg, 2); ?> kg</span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($order->created_by ?? 'Unknown'); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($order->created_at)); ?></span>
                                    <a href="../cr/credit_order_view.php?id=<?php echo $order->id; ?>"
                                       class="ml-auto px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors text-xs">
                                        <i class="fas fa-eye mr-1"></i>View
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- POS Sales Tab -->
            <div x-show="activeTab === 'pos'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-cash-register text-primary-600 mr-2"></i>POS Sales
                </h2>
                <?php if (empty($pos_sales)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No POS sales found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($pos_sales as $sale): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($sale->order_number); ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php
                                                echo match($sale->payment_method) {
                                                    'Cash' => 'bg-green-100 text-green-800',
                                                    'Card' => 'bg-blue-100 text-blue-800',
                                                    'Mobile Banking' => 'bg-purple-100 text-purple-800',
                                                    default => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst($sale->payment_method); ?>
                                            </span>
                                        </h3>
                                        <?php if ($sale->customer_name): ?>
                                            <p class="text-sm text-gray-600 mt-1">
                                                <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($sale->customer_name); ?>
                                                <?php if ($sale->customer_phone): ?>
                                                    <span class="ml-2"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($sale->customer_phone); ?></span>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-green-600">
                                            ৳<?php echo number_format($sale->total_amount, 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <?php if ($sale->branch_name): ?>
                                        <span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($sale->branch_name); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($sale->cashier ?? 'Unknown'); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($sale->order_date)); ?></span>
                                    <a href="../pos/receipt_view.php?id=<?php echo $sale->id; ?>"
                                       class="ml-auto px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors text-xs">
                                        <i class="fas fa-eye mr-1"></i>View
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Purchase Invoices Tab -->
            <div x-show="activeTab === 'purchases'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-shopping-bag text-primary-600 mr-2"></i>Purchase Invoices
                </h2>
                <?php if (empty($purchase_invoices)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No purchase invoices found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($purchase_invoices as $invoice): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($invoice->invoice_number); ?>
                                            <?php if (isset($invoice->purchase_source)): ?>
                                                <span class="ml-2 px-2 py-1 text-xs rounded-full <?php echo $invoice->purchase_source === 'grn' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800'; ?>">
                                                    <?php echo $invoice->purchase_source === 'grn' ? 'Wheat GRN' : 'Invoice'; ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php
                                                echo match($invoice->payment_status) {
                                                    'paid' => 'bg-green-100 text-green-800',
                                                    'partially_paid' => 'bg-yellow-100 text-yellow-800',
                                                    'unpaid' => 'bg-red-100 text-red-800',
                                                    'received' => 'bg-green-100 text-green-800',
                                                    default => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $invoice->payment_status)); ?>
                                            </span>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <i class="fas fa-truck mr-1"></i><?php echo htmlspecialchars($invoice->supplier_name); ?>
                                            <?php if ($invoice->supplier_phone): ?>
                                                <span class="ml-2"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($invoice->supplier_phone); ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-red-600">
                                            ৳<?php echo number_format($invoice->total_amount, 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <?php if ($invoice->branch_name): ?>
                                        <span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($invoice->branch_name); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($invoice->created_by ?? 'Unknown'); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($invoice->created_at)); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Customer Payments Tab -->
            <div x-show="activeTab === 'customer_payments'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-hand-holding-usd text-primary-600 mr-2"></i>Customer Payments
                </h2>
                <?php if (empty($customer_payments)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No customer payments found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($customer_payments as $payment): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($payment->payment_number); ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php
                                                echo match($payment->payment_method) {
                                                    'Cash' => 'bg-green-100 text-green-800',
                                                    'Cheque' => 'bg-blue-100 text-blue-800',
                                                    'Bank Transfer' => 'bg-purple-100 text-purple-800',
                                                    default => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $payment->payment_method)); ?>
                                            </span>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php
                                                echo match($payment->allocation_status) {
                                                    'allocated' => 'bg-green-100 text-green-800',
                                                    'partial' => 'bg-yellow-100 text-yellow-800',
                                                    'unallocated' => 'bg-red-100 text-red-800',
                                                    default => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst($payment->allocation_status); ?>
                                            </span>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($payment->customer_name); ?>
                                            <?php if ($payment->customer_phone): ?>
                                                <span class="ml-2"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($payment->customer_phone); ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($payment->payment_account): ?>
                                            <p class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-university mr-1"></i><?php echo htmlspecialchars($payment->payment_account); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-green-600">
                                            ৳<?php echo number_format($payment->amount, 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <?php if ($payment->branch_name): ?>
                                        <span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($payment->branch_name); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($payment->received_by ?? 'Unknown'); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($payment->created_at)); ?></span>
                                    <a href="../cr/credit_payment_receipt.php?id=<?php echo $payment->id; ?>"
                                       class="ml-auto px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors text-xs">
                                        <i class="fas fa-eye mr-1"></i>View
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Supplier Payments Tab -->
            <div x-show="activeTab === 'supplier_payments'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-money-check text-primary-600 mr-2"></i>Supplier Payments
                </h2>
                <?php if (empty($supplier_payments)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No supplier payments found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($supplier_payments as $payment): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($payment->payment_number); ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php
                                                echo match($payment->payment_method) {
                                                    'Cash' => 'bg-green-100 text-green-800',
                                                    'Cheque' => 'bg-blue-100 text-blue-800',
                                                    'Bank Transfer' => 'bg-purple-100 text-purple-800',
                                                    default => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $payment->payment_method)); ?>
                                            </span>
                                            <?php if (isset($payment->payment_source) && $payment->payment_source === 'wheat'): ?>
                                                <span class="ml-2 px-2 py-1 text-xs rounded-full bg-amber-100 text-amber-800">
                                                    Wheat
                                                </span>
                                            <?php endif; ?>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <i class="fas fa-truck mr-1"></i><?php echo htmlspecialchars($payment->supplier_name); ?>
                                            <?php if ($payment->supplier_phone): ?>
                                                <span class="ml-2"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($payment->supplier_phone); ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($payment->payment_account): ?>
                                            <p class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-university mr-1"></i><?php echo htmlspecialchars($payment->payment_account); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-red-600">
                                            ৳<?php echo number_format($payment->amount, 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <?php if ($payment->branch_name): ?>
                                        <span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($payment->branch_name); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($payment->paid_by ?? 'Unknown'); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($payment->created_at)); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Transport Expenses Tab -->
            <div x-show="activeTab === 'expenses'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-truck text-primary-600 mr-2"></i>Transport Expenses
                </h2>
                <?php if (empty($transport_expenses)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No transport expenses found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($transport_expenses as $expense): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo ucfirst(str_replace('_', ' ', $expense->expense_type)); ?>
                                            <?php if ($expense->trip_number): ?>
                                                <span class="ml-2 text-sm text-gray-500">
                                                    Trip: <?php echo htmlspecialchars($expense->trip_number); ?>
                                                </span>
                                            <?php endif; ?>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <?php echo htmlspecialchars($expense->description); ?>
                                        </p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            <?php if ($expense->vehicle): ?>
                                                <span><i class="fas fa-truck mr-1"></i><?php echo htmlspecialchars($expense->vehicle); ?></span>
                                            <?php endif; ?>
                                            <?php if ($expense->driver_name): ?>
                                                <span class="ml-3"><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($expense->driver_name); ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-red-600">
                                            ৳<?php echo number_format($expense->amount, 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($expense->created_by ?? 'Unknown'); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($expense->created_at)); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Debit Vouchers Tab -->
            <div x-show="activeTab === 'vouchers'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-file-invoice-dollar text-primary-600 mr-2"></i>Debit Vouchers
                </h2>
                <?php if (empty($debit_vouchers)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No debit vouchers found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($debit_vouchers as $voucher): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($voucher->voucher_number); ?>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <?php echo htmlspecialchars($voucher->description); ?>
                                        </p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            <?php if ($voucher->paid_to): ?>
                                                <span><i class="fas fa-user mr-1"></i>Paid to: <?php echo htmlspecialchars($voucher->paid_to); ?></span>
                                            <?php endif; ?>
                                            <?php if ($voucher->expense_account): ?>
                                                <span class="ml-3"><i class="fas fa-tag mr-1"></i><?php echo htmlspecialchars($voucher->expense_account); ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-red-600">
                                            ৳<?php echo number_format($voucher->amount, 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <?php if ($voucher->branch_name): ?>
                                        <span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($voucher->branch_name); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($voucher->created_by ?? 'Unknown'); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($voucher->created_at)); ?></span>
                                    <a href="debit_voucher_view.php?id=<?php echo $voucher->id; ?>"
                                       class="ml-auto px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors text-xs">
                                        <i class="fas fa-eye mr-1"></i>View
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Returns Tab -->
            <div x-show="activeTab === 'returns'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-undo-alt text-rose-600 mr-2"></i>Credit Order Returns
                </h2>
                <?php if (empty($returns)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No returns found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($returns as $ret): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($ret->return_number); ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-700">
                                                Order: <?php echo htmlspecialchars($ret->order_number); ?>
                                            </span>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php echo $ret->return_type === 'full' ? 'bg-red-100 text-red-800' : 'bg-orange-100 text-orange-800'; ?>">
                                                <?php echo ucfirst($ret->return_type); ?>
                                            </span>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php
                                                echo match($ret->status) {
                                                    'approved' => 'bg-green-100 text-green-800',
                                                    'pending' => 'bg-yellow-100 text-yellow-800',
                                                    'rejected' => 'bg-red-100 text-red-800',
                                                    default => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst($ret->status); ?>
                                            </span>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($ret->customer_name); ?>
                                            <?php if ($ret->customer_phone): ?>
                                                <span class="ml-2"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($ret->customer_phone); ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($ret->reason): ?>
                                            <p class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-comment mr-1"></i><?php echo htmlspecialchars($ret->reason); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-rose-600">
                                            ৳<?php echo number_format($ret->total_returned_amount ?? 0, 2); ?>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            Qty: <?php echo htmlspecialchars($ret->total_returned_qty ?? 0); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($ret->created_by ?? 'Unknown'); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($ret->created_at)); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Deliveries Tab -->
            <div x-show="activeTab === 'deliveries'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-dolly text-teal-600 mr-2"></i>Partial Deliveries
                </h2>
                <?php if (empty($deliveries)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No deliveries found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($deliveries as $delivery): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($delivery->delivery_number); ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-700">
                                                Order: <?php echo htmlspecialchars($delivery->order_number); ?>
                                            </span>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php echo $delivery->is_final ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>">
                                                <?php echo $delivery->is_final ? 'Final Delivery' : 'Partial'; ?>
                                            </span>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($delivery->customer_name); ?>
                                            <?php if ($delivery->customer_phone): ?>
                                                <span class="ml-2"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($delivery->customer_phone); ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($delivery->truck_number || $delivery->driver_name): ?>
                                            <p class="text-xs text-gray-500 mt-1">
                                                <?php if ($delivery->truck_number): ?>
                                                    <span><i class="fas fa-truck mr-1"></i><?php echo htmlspecialchars($delivery->truck_number); ?></span>
                                                <?php endif; ?>
                                                <?php if ($delivery->driver_name): ?>
                                                    <span class="ml-3"><i class="fas fa-id-card mr-1"></i><?php echo htmlspecialchars($delivery->driver_name); ?></span>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-teal-600">
                                            ৳<?php echo number_format($delivery->total_amount_delivered ?? 0, 2); ?>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            Qty: <?php echo htmlspecialchars($delivery->total_qty_delivered ?? 0); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($delivery->created_by ?? 'Unknown'); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($delivery->created_at)); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Expense Vouchers Tab -->
            <div x-show="activeTab === 'expense_vouchers'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-file-alt text-primary-600 mr-2"></i>Expense Vouchers
                </h2>
                <?php if (empty($expense_vouchers)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No expense vouchers found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($expense_vouchers as $ev): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($ev->voucher_number); ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php
                                                echo match($ev->status) {
                                                    'approved' => 'bg-green-100 text-green-800',
                                                    'pending' => 'bg-yellow-100 text-yellow-800',
                                                    'rejected' => 'bg-red-100 text-red-800',
                                                    default => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst($ev->status); ?>
                                            </span>
                                            <?php if ($ev->payment_method): ?>
                                                <span class="ml-2 px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">
                                                    <?php echo htmlspecialchars($ev->payment_method); ?>
                                                </span>
                                            <?php endif; ?>
                                        </h3>
                                        <?php if ($ev->category_name || $ev->subcategory_name): ?>
                                            <p class="text-sm text-gray-600 mt-1">
                                                <i class="fas fa-tag mr-1"></i>
                                                <?php echo htmlspecialchars($ev->category_name ?? ''); ?>
                                                <?php if ($ev->subcategory_name): ?>
                                                    / <?php echo htmlspecialchars($ev->subcategory_name); ?>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($ev->handled_by_person): ?>
                                            <p class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-user mr-1"></i>Handled by: <?php echo htmlspecialchars($ev->handled_by_person); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($ev->remarks): ?>
                                            <p class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-comment mr-1"></i><?php echo htmlspecialchars($ev->remarks); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($ev->branch_name): ?>
                                            <p class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($ev->branch_name); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-red-600">
                                            ৳<?php echo number_format($ev->total_amount ?? 0, 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($ev->created_by ?? 'Unknown'); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($ev->created_at)); ?></span>
                                    <a href="../expense/expense_voucher_list.php"
                                       class="ml-auto px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors text-xs">
                                        <i class="fas fa-list mr-1"></i>List
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Bank Transactions Tab -->
            <div x-show="activeTab === 'bank_transactions'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-university text-sky-600 mr-2"></i>Bank Transactions
                </h2>
                <?php if (empty($bank_transactions)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No bank transactions found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($bank_transactions as $bt): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($bt->transaction_number); ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php echo $bt->entry_type === 'debit' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'; ?>">
                                                <?php echo ucfirst($bt->entry_type); ?>
                                            </span>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php
                                                echo match($bt->status) {
                                                    'approved'  => 'bg-green-100 text-green-800',
                                                    'pending'   => 'bg-yellow-100 text-yellow-800',
                                                    'rejected'  => 'bg-red-100 text-red-800',
                                                    'cancelled' => 'bg-gray-100 text-gray-500',
                                                    'unposted'  => 'bg-orange-100 text-orange-800',
                                                    default     => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst($bt->status); ?>
                                            </span>
                                        </h3>
                                        <?php if ($bt->payee_payer_name): ?>
                                            <p class="text-sm text-gray-600 mt-1">
                                                <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($bt->payee_payer_name); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($bt->description): ?>
                                            <p class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-comment mr-1"></i><?php echo htmlspecialchars($bt->description); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($bt->account_name): ?>
                                            <p class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-university mr-1"></i>
                                                <?php echo htmlspecialchars($bt->account_name); ?>
                                                <?php if ($bt->bank_name): ?> — <?php echo htmlspecialchars($bt->bank_name); ?><?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold <?php echo $bt->entry_type === 'debit' ? 'text-red-600' : 'text-green-600'; ?>">
                                            ৳<?php echo number_format($bt->amount ?? 0, 2); ?>
                                        </div>
                                        <?php if ($bt->reference_number): ?>
                                            <div class="text-xs text-gray-500 mt-1">Ref: <?php echo htmlspecialchars($bt->reference_number); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <?php if ($bt->branch_name): ?>
                                        <span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($bt->branch_name); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($bt->created_by ?? 'Unknown'); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($bt->created_at)); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Bank Transfers Tab -->
            <div x-show="activeTab === 'bank_transfers'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-exchange-alt text-indigo-600 mr-2"></i>Bank Transfers
                </h2>
                <?php if (empty($bank_transfers)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No bank transfers found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($bank_transfers as $btt): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($btt->transfer_number); ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php
                                                echo match($btt->status) {
                                                    'approved'  => 'bg-green-100 text-green-800',
                                                    'pending'   => 'bg-yellow-100 text-yellow-800',
                                                    'rejected'  => 'bg-red-100 text-red-800',
                                                    'cancelled' => 'bg-gray-100 text-gray-500',
                                                    default     => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst($btt->status); ?>
                                            </span>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <i class="fas fa-arrow-right mr-1"></i>
                                            From: <strong><?php echo htmlspecialchars($btt->from_account ?? 'N/A'); ?></strong>
                                            <?php if ($btt->from_bank): ?>(<?php echo htmlspecialchars($btt->from_bank); ?>)<?php endif; ?>
                                            &nbsp;→&nbsp;
                                            To: <strong><?php echo htmlspecialchars($btt->to_account ?? 'N/A'); ?></strong>
                                            <?php if ($btt->to_bank): ?>(<?php echo htmlspecialchars($btt->to_bank); ?>)<?php endif; ?>
                                        </p>
                                        <?php if ($btt->description): ?>
                                            <p class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-comment mr-1"></i><?php echo htmlspecialchars($btt->description); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-indigo-600">
                                            ৳<?php echo number_format($btt->amount ?? 0, 2); ?>
                                        </div>
                                        <?php if ($btt->reference_number): ?>
                                            <div class="text-xs text-gray-500 mt-1">Ref: <?php echo htmlspecialchars($btt->reference_number); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($btt->initiated_by ?? 'Unknown'); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($btt->created_at)); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Purchase Returns Tab -->
            <div x-show="activeTab === 'purchase_returns'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-undo text-orange-600 mr-2"></i>Purchase Returns
                </h2>
                <?php if (empty($purchase_returns)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No purchase returns found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($purchase_returns as $pr): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($pr->return_number); ?>
                                            <?php if ($pr->return_reason): ?>
                                                <span class="ml-2 px-2 py-1 text-xs rounded-full bg-orange-100 text-orange-800">
                                                    <?php echo ucfirst(str_replace('_', ' ', $pr->return_reason)); ?>
                                                </span>
                                            <?php endif; ?>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <i class="fas fa-truck mr-1"></i><?php echo htmlspecialchars($pr->supplier_name ?? 'N/A'); ?>
                                            <?php if ($pr->supplier_phone): ?>
                                                <span class="ml-2"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($pr->supplier_phone); ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-orange-600">
                                            ৳<?php echo number_format($pr->subtotal ?? 0, 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <?php if ($pr->branch_name): ?>
                                        <span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($pr->branch_name); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($pr->created_by ?? 'Unknown'); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($pr->created_at)); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Petty Cash Tab -->
            <div x-show="activeTab === 'petty_cash'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-coins text-lime-600 mr-2"></i>Petty Cash Transactions
                </h2>
                <?php if (empty($petty_cash)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No petty cash transactions found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($petty_cash as $pc): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php if ($pc->account_name): ?>
                                                <?php echo htmlspecialchars($pc->account_name); ?>
                                            <?php else: ?>
                                                Petty Cash Entry
                                            <?php endif; ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php
                                                echo match($pc->transaction_type) {
                                                    'cash_in', 'transfer_in', 'opening_balance' => 'bg-green-100 text-green-800',
                                                    'cash_out', 'transfer_out'                  => 'bg-red-100 text-red-800',
                                                    'adjustment'                                 => 'bg-yellow-100 text-yellow-800',
                                                    default                                      => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $pc->transaction_type)); ?>
                                            </span>
                                            <?php if ($pc->payment_method): ?>
                                                <span class="ml-2 px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">
                                                    <?php echo htmlspecialchars($pc->payment_method); ?>
                                                </span>
                                            <?php endif; ?>
                                        </h3>
                                        <?php if ($pc->description): ?>
                                            <p class="text-sm text-gray-600 mt-1">
                                                <i class="fas fa-comment mr-1"></i><?php echo htmlspecialchars($pc->description); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($pc->reference_type): ?>
                                            <p class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-tag mr-1"></i>Ref: <?php echo htmlspecialchars($pc->reference_type); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold <?php echo in_array($pc->transaction_type, ['cash_out','transfer_out']) ? 'text-red-600' : 'text-lime-600'; ?>">
                                            ৳<?php echo number_format($pc->amount ?? 0, 2); ?>
                                        </div>
                                        <?php if ($pc->balance_after !== null): ?>
                                            <div class="text-xs text-gray-500 mt-1">Balance: ৳<?php echo number_format($pc->balance_after, 2); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <?php if ($pc->branch_name): ?>
                                        <span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($pc->branch_name); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($pc->created_by ?? 'Unknown'); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($pc->created_at)); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- Action Buttons -->
    <div class="mt-8 flex justify-center space-x-4 no-print">
        <button onclick="window.print()"
                class="bg-gray-600 text-white px-6 py-3 rounded-lg hover:bg-gray-700 transition-colors">
            <i class="fas fa-print mr-2"></i>Print Report
        </button>
        <a href="<?php echo url('index.php'); ?>"
           class="bg-primary-600 text-white px-6 py-3 rounded-lg hover:bg-primary-700 transition-colors inline-block">
            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
        </a>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>

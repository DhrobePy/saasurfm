<?php
/**
 * Operations Center — Income & Expense KPI Dashboard
 * Superadmin / Admin only — real-time operational snapshot with modals
 */
require_once '../core/init.php';
restrict_access(['Superadmin', 'admin']);
$pageTitle = 'Operations Center';
global $db;

$today = date('Y-m-d');

// Safe query helpers
function oq(string $sql, array $p = [], $def = 0) {
    global $db;
    $r = $db->query($sql, $p);
    if ($r->error()) return $def;
    $row = $r->first();
    return $row ? ($row->v ?? $def) : $def;
}
function oql(string $sql, array $p = []): array {
    global $db;
    $r = $db->query($sql, $p);
    return $r->error() ? [] : ($r->results() ?: []);
}

// ══════════════════════════════════════════════════════════════════════════════
// INCOME KPI 1 — New Credit Orders Today
// ══════════════════════════════════════════════════════════════════════════════
$kpi1_count = (int) oq("SELECT COUNT(id) AS v FROM credit_orders WHERE DATE(created_at)=?", [$today]);
$kpi1_value = (float) oq("SELECT IFNULL(SUM(total_amount),0) AS v FROM credit_orders WHERE DATE(created_at)=?", [$today]);

$orders_today_raw = oql("
    SELECT co.id, co.order_number, co.created_at, co.order_date, co.required_date,
           co.total_amount, co.status, co.priority, co.balance_due,
           co.advance_paid, co.amount_paid, co.special_instructions,
           c.id AS customer_id, c.name AS customer_name, c.business_name,
           c.current_balance, c.credit_limit, c.phone_number
    FROM credit_orders co JOIN customers c ON co.customer_id = c.id
    WHERE DATE(co.created_at) = ?
    ORDER BY co.created_at DESC
", [$today]);

$orders_today = [];
foreach ($orders_today_raw as $ord) {
    $ord->items = oql("
        SELECT coi.quantity, coi.unit_price, coi.line_total, coi.total_weight_kg,
               p.base_name AS product_name, pv.grade, pv.sku, pv.weight_variant, pv.unit_of_measure
        FROM credit_order_items coi
        JOIN products p ON coi.product_id = p.id
        LEFT JOIN product_variants pv ON coi.variant_id = pv.id
        WHERE coi.order_id = ?
    ", [$ord->id]);
    $ord->mini_statement = oql("
        SELECT transaction_date, transaction_type, description,
               debit_amount, credit_amount, balance_after
        FROM customer_ledger WHERE customer_id = ?
        ORDER BY created_at DESC LIMIT 6
    ", [$ord->customer_id]);
    $orders_today[] = $ord;
}

// ══════════════════════════════════════════════════════════════════════════════
// INCOME KPI 2 — Orders Scheduled for Shipment Today
// ══════════════════════════════════════════════════════════════════════════════
$shipment_orders = oql("
    SELECT co.id, co.order_number, co.required_date, co.order_date,
           co.total_amount, co.status, co.priority, co.sort_order,
           co.special_instructions,
           c.id AS customer_id, c.name AS customer_name, c.business_name,
           (SELECT COUNT(*) FROM credit_order_items WHERE order_id=co.id) AS item_count
    FROM credit_orders co JOIN customers c ON co.customer_id = c.id
    WHERE co.required_date = ? AND co.status NOT IN ('delivered','cancelled','rejected')
    ORDER BY co.sort_order ASC, FIELD(co.priority,'urgent','high','normal','low')
", [$today]);
$kpi2_count = count($shipment_orders);

// ══════════════════════════════════════════════════════════════════════════════
// INCOME KPI 3 — Products Under Active Orders
// ══════════════════════════════════════════════════════════════════════════════
$products_under_order = oql("
    SELECT p.id AS product_id, p.base_name AS product_name, p.category,
           pv.id AS variant_id, pv.grade, pv.weight_variant, pv.sku,
           SUM(coi.quantity) AS total_ordered_qty,
           SUM(coi.line_total) AS total_ordered_value,
           IFNULL(inv_sum.total_stock, 0) AS stock_in_hand,
           COUNT(DISTINCT coi.order_id) AS order_count
    FROM credit_order_items coi
    JOIN credit_orders co ON coi.order_id = co.id
        AND co.status NOT IN ('delivered','cancelled','rejected')
    JOIN products p ON coi.product_id = p.id
    LEFT JOIN product_variants pv ON coi.variant_id = pv.id
    LEFT JOIN (SELECT variant_id, SUM(quantity) AS total_stock FROM inventory GROUP BY variant_id) inv_sum
        ON inv_sum.variant_id = pv.id
    GROUP BY p.id, p.base_name, p.category, pv.id, pv.grade, pv.weight_variant, pv.sku
    ORDER BY total_ordered_qty DESC
", []);
$kpi3_count = count($products_under_order);
$kpi3_total_bags = array_sum(array_map(fn($p) => (float)($p->total_ordered_qty ?? 0), $products_under_order));

$branches_list = oql("SELECT id, name, code FROM branches WHERE status='active' ORDER BY name");

// ══════════════════════════════════════════════════════════════════════════════
// INCOME KPI 4 — Payments Received Today
// ══════════════════════════════════════════════════════════════════════════════
$payments_in_today = oql("
    SELECT cp.id, cp.payment_number, cp.payment_date, cp.created_at,
           cp.amount, cp.payment_method, cp.payment_type, cp.reference_number,
           cp.cheque_number, cp.bank_transaction_type, cp.allocation_status, cp.notes,
           c.id AS customer_id, c.name AS customer_name, c.business_name,
           ba.bank_name, ba.account_name, ba.account_number, ba.branch_name AS bank_branch,
           ba2.bank_name AS cash_bank_name, ba2.account_name AS cash_account_name
    FROM customer_payments cp
    JOIN customers c ON cp.customer_id = c.id
    LEFT JOIN bank_accounts ba  ON cp.bank_account_id = ba.id
    LEFT JOIN bank_accounts ba2 ON cp.cash_account_id  = ba2.id
    WHERE cp.payment_date = ?
    ORDER BY cp.created_at DESC
", [$today]);
$kpi4_total = array_sum(array_map(fn($p) => (float)$p->amount, $payments_in_today));
$kpi4_count = count($payments_in_today);

// ══════════════════════════════════════════════════════════════════════════════
// INCOME KPI 5 — Receivables Outstanding
// ══════════════════════════════════════════════════════════════════════════════
$receivables_list = oql("
    SELECT c.id, c.name, c.business_name, c.phone_number,
           c.current_balance, c.credit_limit
    FROM customers c
    WHERE c.current_balance > 0.01 AND c.customer_type = 'Credit'
    ORDER BY c.current_balance DESC
    LIMIT 100
");
$kpi5_total = (float) oq("SELECT IFNULL(SUM(current_balance),0) AS v FROM customers WHERE current_balance > 0.01 AND customer_type='Credit'");
$kpi5_count = count($receivables_list);

// ══════════════════════════════════════════════════════════════════════════════
// EXPENSE KPI 1 — Total Payable to Suppliers (Outstanding)
// ══════════════════════════════════════════════════════════════════════════════
// Shared subquery CTE-style fragment (reused in both payables and advances)
// Active = not closed, not cancelled (mirrors "All Active" filter in purchase index)
$sup_bal_sql = "
    FROM purchase_orders_adnan poa
    LEFT JOIN suppliers s ON poa.supplier_id = s.id
    LEFT JOIN (
        SELECT purchase_order_id, SUM(expected_quantity) AS total_expected_qty
        FROM goods_received_adnan WHERE grn_status != 'cancelled'
        GROUP BY purchase_order_id
    ) grn ON poa.id = grn.purchase_order_id
    LEFT JOIN (
        SELECT purchase_order_id, SUM(amount_paid) AS total_paid
        FROM purchase_payments_adnan WHERE is_posted = 1
        GROUP BY purchase_order_id
    ) pmt ON poa.id = pmt.purchase_order_id
    WHERE poa.po_status != 'cancelled' AND poa.delivery_status != 'closed'
";

$supplier_payables = oql("
    SELECT poa.id, poa.po_number, poa.po_date,
           COALESCE(s.company_name, poa.supplier_name) AS supplier_name,
           poa.unit_price_per_kg,
           COALESCE(grn.total_expected_qty, 0) * poa.unit_price_per_kg AS expected_payable,
           COALESCE(pmt.total_paid, 0) AS total_paid,
           poa.payment_status, poa.delivery_status,
           COALESCE(grn.total_expected_qty, 0) * poa.unit_price_per_kg
               - COALESCE(pmt.total_paid, 0) AS balance_due
    $sup_bal_sql
    HAVING balance_due > 0.01
    ORDER BY balance_due DESC
    LIMIT 60
");
$kpi6_total = (float) oq("
    SELECT IFNULL(SUM(bal.balance_due), 0) AS v FROM (
        SELECT COALESCE(grn.total_expected_qty, 0) * poa.unit_price_per_kg
                   - COALESCE(pmt.total_paid, 0) AS balance_due
        $sup_bal_sql
        HAVING balance_due > 0.01
    ) bal
");
$kpi6_count = count($supplier_payables);

// ══════════════════════════════════════════════════════════════════════════════
// EXPENSE KPI 1b — Supplier Advance Paid (overpayments on active POs)
// ══════════════════════════════════════════════════════════════════════════════
$supplier_advances = oql("
    SELECT poa.id, poa.po_number, poa.po_date,
           COALESCE(s.company_name, poa.supplier_name) AS supplier_name,
           poa.unit_price_per_kg,
           COALESCE(grn.total_expected_qty, 0) * poa.unit_price_per_kg AS expected_payable,
           COALESCE(pmt.total_paid, 0) AS total_paid,
           poa.payment_status, poa.delivery_status,
           COALESCE(pmt.total_paid, 0)
               - COALESCE(grn.total_expected_qty, 0) * poa.unit_price_per_kg AS advance_amount
    $sup_bal_sql
    HAVING advance_amount > 0.01
    ORDER BY advance_amount DESC
    LIMIT 60
");
$kpi_adv_total = (float) oq("
    SELECT IFNULL(SUM(bal.advance_amount), 0) AS v FROM (
        SELECT COALESCE(pmt.total_paid, 0)
                   - COALESCE(grn.total_expected_qty, 0) * poa.unit_price_per_kg AS advance_amount
        $sup_bal_sql
        HAVING advance_amount > 0.01
    ) bal
");
$kpi_adv_count = count($supplier_advances);

// ══════════════════════════════════════════════════════════════════════════════
// EXPENSE KPI 2 — Supplier Payments Made Today
// ══════════════════════════════════════════════════════════════════════════════
$supplier_payments_today = oql("
    SELECT ppa.id, ppa.payment_voucher_number, ppa.payment_date, ppa.created_at,
           ppa.amount_paid, ppa.payment_method, ppa.po_number, ppa.supplier_name,
           ppa.payment_type, ppa.reference_number, ppa.bank_name AS cached_bank,
           ba.bank_name AS ba_bank_name, ba.account_name, ba.account_number,
           ba.branch_name AS bank_branch
    FROM purchase_payments_adnan ppa
    LEFT JOIN bank_accounts ba ON ppa.bank_account_id = ba.id
    WHERE ppa.payment_date = ?
    ORDER BY ppa.created_at DESC
", [$today]);
$kpi7_total = array_sum(array_map(fn($p) => (float)$p->amount_paid, $supplier_payments_today));
$kpi7_count = count($supplier_payments_today);

// ══════════════════════════════════════════════════════════════════════════════
// EXPENSE KPI 3 — Expense Payments Today
// ══════════════════════════════════════════════════════════════════════════════
$expense_payments_today = oql("
    SELECT ev.id, ev.voucher_number, ev.expense_date, ev.created_at,
           ev.total_amount, ev.payment_method, ev.remarks,
           ev.payment_reference, ev.payment_account_name, ev.status,
           ec.category_name, esc.subcategory_name,
           ba.bank_name, ba.account_name, ba.account_number,
           ba2.bank_name AS cash_bank_name, ba2.account_name AS cash_account_name
    FROM expense_vouchers ev
    LEFT JOIN expense_categories ec    ON ev.category_id    = ec.id
    LEFT JOIN expense_subcategories esc ON ev.subcategory_id = esc.id
    LEFT JOIN bank_accounts ba  ON ev.bank_account_id = ba.id
    LEFT JOIN bank_accounts ba2 ON ev.cash_account_id  = ba2.id
    WHERE ev.expense_date = ? AND ev.status = 'approved'
    ORDER BY ev.created_at DESC
", [$today]);
$kpi8_total = array_sum(array_map(fn($e) => (float)$e->total_amount, $expense_payments_today));
$kpi8_count = count($expense_payments_today);

// ── JSON encode all data for Alpine.js ────────────────────────────────────────
$F = JSON_UNESCAPED_UNICODE;
$j_orders      = json_encode($orders_today,         $F);
$j_shipment    = json_encode($shipment_orders,       $F);
$j_products    = json_encode($products_under_order,  $F);
$j_branches    = json_encode($branches_list,         $F);
$j_payments_in = json_encode($payments_in_today,     $F);
$j_receivables = json_encode($receivables_list,      $F);
$j_sup_pay     = json_encode($supplier_payables,      $F);
$j_sup_adv     = json_encode($supplier_advances,      $F);
$j_sup_pmts    = json_encode($supplier_payments_today, $F);
$j_expenses    = json_encode($expense_payments_today,  $F);

require_once '../templates/header.php';
?>

<div x-data="opsCenter()" x-init="init()" @keydown.escape.window="closeAll()">

<!-- ══════════════════════════════════════════════════════════════════════════
     PAGE HEADER
══════════════════════════════════════════════════════════════════════════════ -->
<div class="w-full px-4 sm:px-6 py-5 space-y-6">

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
        <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-600 text-white">
                <i class="fas fa-tower-broadcast text-sm"></i>
            </span>
            Operations Center
        </h2>
        <p class="text-sm text-gray-500 mt-0.5">
            <?php echo date('l, d F Y'); ?>
            <span class="mx-2 text-gray-300">·</span>
            <span x-text="currentTime" class="font-mono"></span>
        </p>
    </div>
    <div class="flex gap-2 text-xs">
        <a href="cashflow_dashboard.php" class="inline-flex items-center gap-1.5 border border-gray-300 text-gray-600 hover:bg-gray-50 px-3 py-2 rounded-lg transition-colors">
            <i class="fas fa-chart-line"></i> Cash Flow
        </a>
        <a href="../cr/create_order.php" class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg transition-colors font-medium">
            <i class="fas fa-plus"></i> New Order
        </a>
    </div>
</div>

<!-- Toast notification -->
<div x-show="toast.show" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2"
     x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     class="fixed top-5 right-5 z-[100] flex items-center gap-3 px-5 py-3 rounded-xl shadow-2xl text-sm font-medium"
     :class="toast.ok ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white'">
    <i :class="toast.ok ? 'fas fa-check-circle' : 'fas fa-exclamation-circle'"></i>
    <span x-text="toast.msg"></span>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     INCOME KPIs
══════════════════════════════════════════════════════════════════════════════ -->
<div>
    <div class="flex items-center gap-2 mb-4">
        <span class="w-1 h-6 rounded-full bg-emerald-500 inline-block"></span>
        <h3 class="text-sm font-bold text-gray-700 uppercase tracking-widest">Income Indicators</h3>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">

        <!-- KPI 1: New Orders Today -->
        <div @click="openModal('orders')"
             class="group relative bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-0.5 transition-all duration-200 cursor-pointer overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-violet-50 to-purple-50 opacity-0 group-hover:opacity-100 transition-opacity duration-200"></div>
            <div class="relative p-5">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-11 h-11 rounded-xl bg-violet-100 flex items-center justify-center group-hover:bg-violet-600 transition-colors duration-200">
                        <i class="fas fa-shopping-cart text-violet-600 group-hover:text-white transition-colors duration-200"></i>
                    </div>
                    <span class="text-[10px] font-bold text-violet-600 bg-violet-50 border border-violet-200 px-2 py-0.5 rounded-full"><?php echo date('d M'); ?></span>
                </div>
                <p class="text-3xl font-extrabold text-gray-900"><?php echo $kpi1_count; ?></p>
                <p class="text-xs font-medium text-gray-500 mt-0.5">New Orders Today</p>
                <p class="text-sm font-bold text-violet-700 mt-2">৳<?php echo number_format($kpi1_value, 0); ?></p>
                <p class="text-[10px] text-gray-400 mt-1 flex items-center gap-1"><i class="fas fa-arrow-up-right-from-square"></i> Click for details</p>
            </div>
        </div>

        <!-- KPI 2: Shipment Scheduled Today -->
        <div @click="openModal('shipment')"
             class="group relative bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-0.5 transition-all duration-200 cursor-pointer overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-blue-50 to-sky-50 opacity-0 group-hover:opacity-100 transition-opacity duration-200"></div>
            <div class="relative p-5">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-11 h-11 rounded-xl bg-blue-100 flex items-center justify-center group-hover:bg-blue-600 transition-colors duration-200">
                        <i class="fas fa-truck text-blue-600 group-hover:text-white transition-colors duration-200"></i>
                    </div>
                    <span class="text-[10px] font-bold text-blue-600 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded-full">Due Today</span>
                </div>
                <p class="text-3xl font-extrabold text-gray-900"><?php echo $kpi2_count; ?></p>
                <p class="text-xs font-medium text-gray-500 mt-0.5">For Shipment Today</p>
                <a href="../cr/credit_dispatch.php" class="text-sm font-bold text-blue-700 mt-2 block hover:underline" @click.stop>→ Dispatch page</a>
                <p class="text-[10px] text-gray-400 mt-1 flex items-center gap-1"><i class="fas fa-sliders"></i> Manage priorities</p>
            </div>
        </div>

        <!-- KPI 3: Products Under Order -->
        <div @click="openModal('products')"
             class="group relative bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-0.5 transition-all duration-200 cursor-pointer overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-amber-50 to-yellow-50 opacity-0 group-hover:opacity-100 transition-opacity duration-200"></div>
            <div class="relative p-5">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-11 h-11 rounded-xl bg-amber-100 flex items-center justify-center group-hover:bg-amber-600 transition-colors duration-200">
                        <i class="fas fa-boxes-stacked text-amber-600 group-hover:text-white transition-colors duration-200"></i>
                    </div>
                    <span class="text-[10px] font-bold text-amber-600 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-full">Active</span>
                </div>
                <p class="text-3xl font-extrabold text-gray-900"><?php echo $kpi3_count; ?></p>
                <p class="text-xs font-medium text-gray-500 mt-0.5">Product Lines Ordered</p>
                <p class="text-sm font-bold text-amber-700 mt-2"><?php echo number_format($kpi3_total_bags, 0); ?> bags</p>
                <p class="text-[10px] text-gray-400 mt-1 flex items-center gap-1"><i class="fas fa-industry"></i> Order production</p>
            </div>
        </div>

        <!-- KPI 4: Payments Received Today -->
        <div @click="openModal('payments_in')"
             class="group relative bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-0.5 transition-all duration-200 cursor-pointer overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-emerald-50 to-green-50 opacity-0 group-hover:opacity-100 transition-opacity duration-200"></div>
            <div class="relative p-5">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-11 h-11 rounded-xl bg-emerald-100 flex items-center justify-center group-hover:bg-emerald-600 transition-colors duration-200">
                        <i class="fas fa-money-bill-wave text-emerald-600 group-hover:text-white transition-colors duration-200"></i>
                    </div>
                    <span class="text-[10px] font-bold text-emerald-600 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full"><?php echo $kpi4_count; ?> rcpts</span>
                </div>
                <p class="text-3xl font-extrabold text-gray-900">৳<?php echo number_format($kpi4_total / 1000, 1); ?>K</p>
                <p class="text-xs font-medium text-gray-500 mt-0.5">Received Today</p>
                <p class="text-sm font-bold text-emerald-700 mt-2">৳<?php echo number_format($kpi4_total, 0); ?></p>
                <p class="text-[10px] text-gray-400 mt-1 flex items-center gap-1"><i class="fas fa-university"></i> Bank / cash breakdown</p>
            </div>
        </div>

        <!-- KPI 5: Receivables -->
        <div @click="openModal('receivables')"
             class="group relative bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-0.5 transition-all duration-200 cursor-pointer overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-50 to-blue-50 opacity-0 group-hover:opacity-100 transition-opacity duration-200"></div>
            <div class="relative p-5">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-11 h-11 rounded-xl bg-indigo-100 flex items-center justify-center group-hover:bg-indigo-600 transition-colors duration-200">
                        <i class="fas fa-coins text-indigo-600 group-hover:text-white transition-colors duration-200"></i>
                    </div>
                    <span class="text-[10px] font-bold text-indigo-600 bg-indigo-50 border border-indigo-200 px-2 py-0.5 rounded-full"><?php echo $kpi5_count; ?> cust.</span>
                </div>
                <p class="text-2xl font-extrabold text-gray-900">৳<?php echo number_format($kpi5_total / 1000000, 2); ?>M</p>
                <p class="text-xs font-medium text-gray-500 mt-0.5">Total Receivables</p>
                <p class="text-sm text-gray-500 mt-2">৳<?php echo number_format($kpi5_total, 0); ?></p>
                <p class="text-[10px] text-gray-400 mt-1 flex items-center gap-1"><i class="fas fa-sort-amount-desc"></i> Sorted by size</p>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     EXPENSE KPIs
══════════════════════════════════════════════════════════════════════════════ -->
<div>
    <div class="flex items-center gap-2 mb-4">
        <span class="w-1 h-6 rounded-full bg-red-500 inline-block"></span>
        <h3 class="text-sm font-bold text-gray-700 uppercase tracking-widest">Expense Indicators</h3>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">

        <!-- KPI 6: Payable to Suppliers -->
        <div @click="openModal('sup_payable')"
             class="group relative bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-0.5 transition-all duration-200 cursor-pointer overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-orange-50 to-amber-50 opacity-0 group-hover:opacity-100 transition-opacity duration-200"></div>
            <div class="relative p-5">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-11 h-11 rounded-xl bg-orange-100 flex items-center justify-center group-hover:bg-orange-600 transition-colors duration-200">
                        <i class="fas fa-file-invoice-dollar text-orange-600 group-hover:text-white transition-colors duration-200"></i>
                    </div>
                    <span class="text-[10px] font-bold text-orange-600 bg-orange-50 border border-orange-200 px-2 py-0.5 rounded-full"><?php echo $kpi6_count; ?> POs</span>
                </div>
                <p class="text-2xl font-extrabold text-gray-900">৳<?php echo number_format($kpi6_total / 1000000, 2); ?>M</p>
                <p class="text-xs font-medium text-gray-500 mt-0.5">Supplier Payables</p>
                <p class="text-sm text-gray-500 mt-2">৳<?php echo number_format($kpi6_total, 0); ?></p>
                <p class="text-[10px] text-gray-400 mt-1 flex items-center gap-1"><i class="fas fa-list"></i> By outstanding PO</p>
            </div>
        </div>

        <!-- KPI 6b: Supplier Advance Paid -->
        <div @click="openModal('sup_advance')"
             class="group relative bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-0.5 transition-all duration-200 cursor-pointer overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-teal-50 to-cyan-50 opacity-0 group-hover:opacity-100 transition-opacity duration-200"></div>
            <div class="relative p-5">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-11 h-11 rounded-xl bg-teal-100 flex items-center justify-center group-hover:bg-teal-600 transition-colors duration-200">
                        <i class="fas fa-arrow-circle-up text-teal-600 group-hover:text-white transition-colors duration-200"></i>
                    </div>
                    <span class="text-[10px] font-bold text-teal-600 bg-teal-50 border border-teal-200 px-2 py-0.5 rounded-full"><?php echo $kpi_adv_count; ?> POs</span>
                </div>
                <p class="text-2xl font-extrabold text-gray-900">৳<?php echo number_format($kpi_adv_total / 1000000, 2); ?>M</p>
                <p class="text-xs font-medium text-gray-500 mt-0.5">Advance to Suppliers</p>
                <p class="text-sm text-gray-500 mt-2">৳<?php echo number_format($kpi_adv_total, 0); ?></p>
                <p class="text-[10px] text-gray-400 mt-1 flex items-center gap-1"><i class="fas fa-forward"></i> Overpaid on active POs</p>
            </div>
        </div>

        <!-- KPI 7: Supplier Payments Today -->
        <div @click="openModal('sup_payments')"
             class="group relative bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-0.5 transition-all duration-200 cursor-pointer overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-red-50 to-rose-50 opacity-0 group-hover:opacity-100 transition-opacity duration-200"></div>
            <div class="relative p-5">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-11 h-11 rounded-xl bg-red-100 flex items-center justify-center group-hover:bg-red-600 transition-colors duration-200">
                        <i class="fas fa-handshake text-red-600 group-hover:text-white transition-colors duration-200"></i>
                    </div>
                    <span class="text-[10px] font-bold text-red-600 bg-red-50 border border-red-200 px-2 py-0.5 rounded-full"><?php echo $kpi7_count; ?> pmts</span>
                </div>
                <p class="text-3xl font-extrabold text-gray-900">৳<?php echo number_format($kpi7_total, 0); ?></p>
                <p class="text-xs font-medium text-gray-500 mt-0.5">Paid to Suppliers Today</p>
                <p class="text-[10px] text-gray-400 mt-3 flex items-center gap-1"><i class="fas fa-university"></i> Bank / cash details</p>
            </div>
        </div>

        <!-- KPI 8: Expense Payments Today -->
        <div @click="openModal('expenses')"
             class="group relative bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-0.5 transition-all duration-200 cursor-pointer overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-yellow-50 to-orange-50 opacity-0 group-hover:opacity-100 transition-opacity duration-200"></div>
            <div class="relative p-5">
                <div class="flex items-start justify-between mb-3">
                    <div class="w-11 h-11 rounded-xl bg-yellow-100 flex items-center justify-center group-hover:bg-yellow-600 transition-colors duration-200">
                        <i class="fas fa-receipt text-yellow-600 group-hover:text-white transition-colors duration-200"></i>
                    </div>
                    <span class="text-[10px] font-bold text-yellow-700 bg-yellow-50 border border-yellow-200 px-2 py-0.5 rounded-full"><?php echo $kpi8_count; ?> vouchers</span>
                </div>
                <p class="text-3xl font-extrabold text-gray-900">৳<?php echo number_format($kpi8_total, 0); ?></p>
                <p class="text-xs font-medium text-gray-500 mt-0.5">Expenses Paid Today</p>
                <p class="text-[10px] text-gray-400 mt-3 flex items-center gap-1"><i class="fas fa-credit-card"></i> Account breakdown</p>
            </div>
        </div>

        <!-- Quick Links Card -->
        <div class="bg-gradient-to-br from-gray-900 to-gray-800 rounded-2xl p-5 text-white">
            <p class="text-xs font-bold uppercase tracking-widest text-gray-400 mb-4">Quick Actions</p>
            <div class="space-y-2">
                <a href="../cr/credit_order_approval.php" class="flex items-center gap-2 text-sm hover:text-emerald-400 transition-colors group">
                    <i class="fas fa-check-circle w-4 text-gray-500 group-hover:text-emerald-400"></i> Approve Orders
                </a>
                <a href="../cr/credit_dispatch.php" class="flex items-center gap-2 text-sm hover:text-blue-400 transition-colors group">
                    <i class="fas fa-truck w-4 text-gray-500 group-hover:text-blue-400"></i> Dispatch Center
                </a>
                <a href="../purchase/purchase_adnan_record_payment.php" class="flex items-center gap-2 text-sm hover:text-orange-400 transition-colors group">
                    <i class="fas fa-money-bill w-4 text-gray-500 group-hover:text-orange-400"></i> Record Payment
                </a>
                <a href="../expense/create_expense.php" class="flex items-center gap-2 text-sm hover:text-yellow-400 transition-colors group">
                    <i class="fas fa-receipt w-4 text-gray-500 group-hover:text-yellow-400"></i> New Expense
                </a>
                <a href="../cr/customer_payment.php" class="flex items-center gap-2 text-sm hover:text-purple-400 transition-colors group">
                    <i class="fas fa-hand-holding-usd w-4 text-gray-500 group-hover:text-purple-400"></i> Collect Payment
                </a>
            </div>
        </div>
    </div>
</div>
</div><!-- /page container -->


<!-- ══════════════════════════════════════════════════════════════════════════════════
     MODAL SHELL — reusable backdrop + panel
═══════════════════════════════════════════════════════════════════════════════════ -->

<!-- ─────────── MODAL 1: NEW ORDERS TODAY (Kanban) ─────────────────────────── -->
<template x-teleport="body">
<div x-show="activeModal === 'orders'" class="fixed inset-0 z-50 flex items-start justify-center pt-10 px-4 pb-4"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeAll()"></div>
  <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-6xl max-h-[88vh] flex flex-col overflow-hidden"
       x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95 -translate-y-4"
       x-transition:enter-end="opacity-100 scale-100 translate-y-0"
       x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100"
       x-transition:leave-end="opacity-0 scale-95">
    <!-- Header -->
    <div class="bg-gradient-to-r from-violet-600 to-purple-700 px-6 py-4 flex items-center justify-between flex-shrink-0">
      <div class="flex items-center gap-3">
        <i class="fas fa-shopping-cart text-white/80"></i>
        <div>
          <h3 class="text-white font-bold text-lg">New Credit Orders — <?php echo date('d F Y'); ?></h3>
          <p class="text-violet-200 text-xs"><?php echo $kpi1_count; ?> orders &bull; Total ৳<?php echo number_format($kpi1_value, 0); ?></p>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <a href="../cr/all_sales.php" class="text-xs text-violet-200 hover:text-white underline" target="_blank">All Sales →</a>
        <button @click="closeAll()" class="text-white/70 hover:text-white w-8 h-8 rounded-full hover:bg-white/20 flex items-center justify-center transition-colors">
          <i class="fas fa-times"></i>
        </button>
      </div>
    </div>
    <!-- Body: Kanban grid -->
    <div class="overflow-y-auto flex-1 p-6 bg-gray-50">
      <template x-if="ordersToday.length === 0">
        <div class="flex flex-col items-center justify-center py-20 text-gray-400">
          <i class="fas fa-inbox text-5xl mb-4 opacity-30"></i>
          <p class="text-lg font-medium">No new orders today</p>
        </div>
      </template>
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <template x-for="order in ordersToday" :key="order.id">
          <div @click="openDetail(order)"
               class="bg-white rounded-xl border shadow-sm hover:shadow-xl hover:-translate-y-0.5 transition-all duration-200 cursor-pointer group overflow-hidden"
               :class="{'border-l-4 border-l-red-500': order.priority==='urgent', 'border-l-4 border-l-orange-400': order.priority==='high', 'border-l-4 border-l-blue-400': order.priority==='normal', 'border-l-4 border-l-gray-300': order.priority==='low' || !order.priority}">
            <div class="p-4">
              <div class="flex items-start justify-between mb-3">
                <span class="text-xs font-bold text-gray-500" x-text="fmtTime(order.created_at)"></span>
                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border"
                      :class="statusBadge(order.status)"
                      x-text="statusLabel(order.status)"></span>
              </div>
              <p class="text-xs text-gray-400 font-mono mb-0.5" x-text="order.order_number"></p>
              <p class="font-bold text-gray-900 text-base leading-tight" x-text="order.customer_name"></p>
              <p class="text-xs text-gray-500 mb-3" x-text="order.business_name || ''"></p>
              <div class="flex items-end justify-between">
                <div>
                  <p class="text-2xl font-extrabold text-violet-700" x-text="fmtMoney(order.total_amount)"></p>
                  <p class="text-xs text-gray-400 mt-0.5" x-text="(order.items?.length || 0) + ' line(s)'"></p>
                </div>
                <div class="text-right">
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border"
                        :class="priorityBadge(order.priority)"
                        x-text="(order.priority || 'normal').toUpperCase()"></span>
                </div>
              </div>
            </div>
            <div class="border-t px-4 py-2 bg-gray-50 flex items-center justify-between">
              <span class="text-xs text-gray-500">Balance due: <span class="font-semibold text-red-600" x-text="fmtMoney(order.balance_due)"></span></span>
              <span class="text-xs text-violet-600 font-semibold group-hover:text-violet-800 flex items-center gap-1">View Details <i class="fas fa-chevron-right text-[10px]"></i></span>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</div>
</template>

<!-- ─────────── SUB-MODAL: ORDER DETAIL + CUSTOMER MINI STATEMENT ─────────── -->
<template x-teleport="body">
<div x-show="detailModal" class="fixed inset-0 z-[60] flex items-start justify-center pt-10 px-4 pb-4"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
  <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="detailModal = false; selectedItem = null"></div>
  <template x-if="selectedItem">
  <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[88vh] flex flex-col overflow-hidden"
       x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-90"
       x-transition:enter-end="opacity-100 scale-100">
    <!-- Header -->
    <div class="bg-gradient-to-r from-gray-800 to-gray-900 px-6 py-4 flex items-center justify-between flex-shrink-0">
      <div>
        <h4 class="text-white font-bold" x-text="'Order #' + selectedItem.order_number"></h4>
        <p class="text-gray-400 text-xs" x-text="selectedItem.customer_name + (selectedItem.business_name ? ' · ' + selectedItem.business_name : '')"></p>
      </div>
      <div class="flex items-center gap-3">
        <a :href="'../cr/credit_order_view.php?id=' + selectedItem.id" target="_blank"
           class="text-xs bg-white/10 hover:bg-white/20 text-white px-3 py-1.5 rounded-lg transition-colors flex items-center gap-1.5">
          <i class="fas fa-external-link-alt"></i> Full Page
        </a>
        <button @click="detailModal = false; selectedItem = null" class="text-white/70 hover:text-white w-8 h-8 rounded-full hover:bg-white/20 flex items-center justify-center">
          <i class="fas fa-times"></i>
        </button>
      </div>
    </div>
    <!-- Two-column body -->
    <div class="overflow-y-auto flex-1 grid grid-cols-1 lg:grid-cols-5 divide-y lg:divide-y-0 lg:divide-x divide-gray-100">
      <!-- Left: Order details (3/5) -->
      <div class="lg:col-span-3 p-6 space-y-5">
        <!-- Order meta -->
        <div class="grid grid-cols-3 gap-3">
          <div class="bg-gray-50 rounded-xl p-3 text-center">
            <p class="text-[11px] text-gray-500">Total Value</p>
            <p class="text-lg font-bold text-violet-700" x-text="fmtMoney(selectedItem.total_amount)"></p>
          </div>
          <div class="bg-gray-50 rounded-xl p-3 text-center">
            <p class="text-[11px] text-gray-500">Advance Paid</p>
            <p class="text-lg font-bold text-emerald-600" x-text="fmtMoney(selectedItem.advance_paid)"></p>
          </div>
          <div class="bg-gray-50 rounded-xl p-3 text-center">
            <p class="text-[11px] text-gray-500">Balance Due</p>
            <p class="text-lg font-bold text-red-600" x-text="fmtMoney(selectedItem.balance_due)"></p>
          </div>
        </div>
        <!-- Status + Priority badges -->
        <div class="flex items-center gap-3 flex-wrap">
          <span class="text-xs font-bold px-3 py-1 rounded-full border" :class="statusBadge(selectedItem.status)" x-text="statusLabel(selectedItem.status)"></span>
          <span class="text-xs font-bold px-3 py-1 rounded-full border" :class="priorityBadge(selectedItem.priority)" x-text="'Priority: ' + (selectedItem.priority || 'normal').toUpperCase()"></span>
          <span x-show="selectedItem.required_date" class="text-xs text-gray-500 flex items-center gap-1">
            <i class="fas fa-calendar-day"></i> Due: <span class="font-semibold text-gray-700" x-text="fmtDate(selectedItem.required_date)"></span>
          </span>
        </div>
        <!-- Items table -->
        <div>
          <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Order Items</p>
          <div class="rounded-xl border overflow-hidden">
            <table class="w-full text-xs">
              <thead class="bg-gray-50 border-b">
                <tr>
                  <th class="px-3 py-2 text-left text-gray-500 font-medium">Product</th>
                  <th class="px-3 py-2 text-right text-gray-500 font-medium">Qty</th>
                  <th class="px-3 py-2 text-right text-gray-500 font-medium">Unit Price</th>
                  <th class="px-3 py-2 text-right text-gray-500 font-medium">Line Total</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-50">
                <template x-for="(item, idx) in (selectedItem.items || [])" :key="idx">
                  <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 font-medium text-gray-800">
                      <span x-text="item.product_name"></span>
                      <span x-show="item.grade" class="ml-1 text-gray-400 text-[10px]" x-text="'(' + item.grade + ')'"></span>
                    </td>
                    <td class="px-3 py-2 text-right font-semibold" x-text="parseFloat(item.quantity).toLocaleString() + ' bags'"></td>
                    <td class="px-3 py-2 text-right text-gray-600" x-text="fmtMoney(item.unit_price)"></td>
                    <td class="px-3 py-2 text-right font-bold text-violet-700" x-text="fmtMoney(item.line_total)"></td>
                  </tr>
                </template>
                <template x-if="!selectedItem.items || selectedItem.items.length === 0">
                  <tr><td colspan="4" class="px-3 py-4 text-center text-gray-400">No items</td></tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>
        <!-- Special instructions -->
        <div x-show="selectedItem.special_instructions" class="bg-yellow-50 border border-yellow-200 rounded-xl p-3">
          <p class="text-[11px] font-bold text-yellow-700 mb-1"><i class="fas fa-sticky-note mr-1"></i>Notes</p>
          <p class="text-xs text-yellow-800" x-text="selectedItem.special_instructions"></p>
        </div>
      </div>
      <!-- Right: Customer mini statement (2/5) -->
      <div class="lg:col-span-2 p-6 space-y-4">
        <div>
          <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Customer Snapshot</p>
          <div class="space-y-2">
            <div class="flex justify-between items-center">
              <span class="text-xs text-gray-500">Credit Limit</span>
              <span class="text-xs font-bold text-gray-900" x-text="fmtMoney(selectedItem.credit_limit)"></span>
            </div>
            <div class="flex justify-between items-center">
              <span class="text-xs text-gray-500">Outstanding Balance</span>
              <span class="text-xs font-bold text-red-600" x-text="fmtMoney(selectedItem.current_balance)"></span>
            </div>
            <!-- Utilisation bar -->
            <div class="mt-2">
              <div class="flex justify-between text-[10px] text-gray-400 mb-1">
                <span>Credit utilisation</span>
                <span x-text="selectedItem.credit_limit > 0 ? ((selectedItem.current_balance / selectedItem.credit_limit) * 100).toFixed(1) + '%' : 'N/A'"></span>
              </div>
              <div class="w-full bg-gray-200 rounded-full h-2">
                <div class="h-2 rounded-full transition-all duration-500"
                     :class="(selectedItem.current_balance / (selectedItem.credit_limit || 1)) > 0.8 ? 'bg-red-500' : 'bg-emerald-500'"
                     :style="'width:' + Math.min((selectedItem.current_balance / (selectedItem.credit_limit || 1)) * 100, 100) + '%'"></div>
              </div>
            </div>
          </div>
          <a :href="'../cr/customer_ledger.php?customer_id=' + selectedItem.customer_id" target="_blank"
             class="mt-3 text-xs text-indigo-600 hover:underline flex items-center gap-1">
            <i class="fas fa-book"></i> Full Ledger →
          </a>
        </div>
        <div>
          <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Recent Transactions</p>
          <div class="space-y-2">
            <template x-for="(tx, idx) in (selectedItem.mini_statement || [])" :key="idx">
              <div class="flex items-start justify-between bg-gray-50 rounded-lg px-3 py-2">
                <div class="flex-1 min-w-0 mr-2">
                  <p class="text-[10px] font-bold text-gray-700 capitalize" x-text="(tx.transaction_type || '').replace(/_/g,' ')"></p>
                  <p class="text-[10px] text-gray-400 truncate" x-text="tx.description"></p>
                  <p class="text-[10px] text-gray-400" x-text="fmtDate(tx.transaction_date)"></p>
                </div>
                <div class="text-right flex-shrink-0">
                  <p class="text-xs font-bold" :class="tx.debit_amount > 0 ? 'text-red-600' : 'text-emerald-600'"
                     x-text="tx.debit_amount > 0 ? '+' + fmtMoney(tx.debit_amount) : '-' + fmtMoney(tx.credit_amount)"></p>
                  <p class="text-[10px] text-gray-400" x-text="'Bal: ' + fmtMoney(tx.balance_after)"></p>
                </div>
              </div>
            </template>
            <template x-if="!selectedItem.mini_statement || selectedItem.mini_statement.length === 0">
              <p class="text-xs text-gray-400 text-center py-4">No ledger entries yet</p>
            </template>
          </div>
        </div>
      </div>
    </div>
  </div>
  </template>
</div>
</template>


<!-- ─────────── MODAL 2: SHIPMENT SCHEDULED TODAY ─────────────────────────── -->
<template x-teleport="body">
<div x-show="activeModal === 'shipment'" class="fixed inset-0 z-50 flex items-start justify-center pt-10 px-4 pb-4"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeAll()"></div>
  <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[88vh] flex flex-col overflow-hidden"
       x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95 -translate-y-4"
       x-transition:enter-end="opacity-100 scale-100 translate-y-0">
    <div class="bg-gradient-to-r from-blue-600 to-sky-700 px-6 py-4 flex items-center justify-between flex-shrink-0">
      <div>
        <h3 class="text-white font-bold text-lg">Shipment Schedule — Today</h3>
        <p class="text-blue-200 text-xs"><?php echo $kpi2_count; ?> orders due &bull; Set priority, pause or escalate</p>
      </div>
      <div class="flex items-center gap-3">
        <a href="../cr/credit_dispatch.php" target="_blank" class="text-xs text-blue-200 hover:text-white underline">Dispatch Page →</a>
        <button @click="closeAll()" class="text-white/70 hover:text-white w-8 h-8 rounded-full hover:bg-white/20 flex items-center justify-center"><i class="fas fa-times"></i></button>
      </div>
    </div>
    <div class="overflow-y-auto flex-1 p-6">
      <template x-if="shipmentOrders.length === 0">
        <div class="text-center py-16 text-gray-400">
          <i class="fas fa-truck-fast text-5xl mb-4 opacity-30"></i>
          <p class="text-lg font-medium">No shipments scheduled for today</p>
        </div>
      </template>
      <div class="space-y-3">
        <template x-for="order in shipmentOrders" :key="order.id">
          <div class="bg-white border rounded-xl p-4 hover:shadow-md transition-all"
               :class="{'border-l-4 border-l-red-500': order.priority==='urgent', 'border-l-4 border-l-orange-400': order.priority==='high', 'border-l-4 border-l-blue-400': order.priority==='normal', 'border-l-4 border-l-gray-300': order.priority==='low' || !order.priority, 'opacity-60': order._paused}">
            <div class="flex flex-col sm:flex-row sm:items-center gap-4">
              <!-- Order info -->
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1 flex-wrap">
                  <span class="text-xs font-bold text-gray-500 font-mono" x-text="order.order_number"></span>
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border" :class="statusBadge(order.status)" x-text="statusLabel(order.status)"></span>
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border" :class="priorityBadge(order.priority)" x-text="(order.priority || 'normal').toUpperCase()"></span>
                </div>
                <p class="font-bold text-gray-900" x-text="order.customer_name"></p>
                <p class="text-xs text-gray-500" x-text="'৳' + parseFloat(order.total_amount).toLocaleString() + ' · ' + (order.item_count || 0) + ' items'"></p>
              </div>
              <!-- Controls -->
              <div class="flex items-center gap-2 flex-wrap">
                <!-- Priority selector -->
                <select @change="doAction('set_priority', order.id, {priority: $event.target.value}); order.priority = $event.target.value"
                        :value="order.priority || 'normal'"
                        class="text-xs border border-gray-200 rounded-lg px-2 py-1.5 bg-white focus:ring-2 focus:ring-blue-300">
                  <option value="low">⬇ Low</option>
                  <option value="normal">→ Normal</option>
                  <option value="high">↑ High</option>
                  <option value="urgent">🔴 Urgent</option>
                </select>
                <!-- Reschedule -->
                <button @click="startReschedule(order)"
                        class="text-xs border border-blue-200 text-blue-600 hover:bg-blue-50 rounded-lg px-2.5 py-1.5 transition-colors flex items-center gap-1">
                  <i class="fas fa-calendar-alt"></i> Reschedule
                </button>
                <!-- Pause -->
                <button @click="doAction('pause', order.id, {}); order._paused = true"
                        class="text-xs border border-yellow-200 text-yellow-600 hover:bg-yellow-50 rounded-lg px-2.5 py-1.5 transition-colors flex items-center gap-1">
                  <i class="fas fa-pause"></i> Pause
                </button>
                <!-- Escalate -->
                <button @click="doAction('escalate', order.id, {}); order.status='escalated'; order.priority='urgent'"
                        class="text-xs border border-red-200 text-red-600 hover:bg-red-50 rounded-lg px-2.5 py-1.5 transition-colors flex items-center gap-1">
                  <i class="fas fa-arrow-up"></i> Escalate
                </button>
                <!-- Open detail -->
                <a :href="'../cr/credit_order_view.php?id=' + order.id" target="_blank"
                   class="text-xs border border-gray-200 text-gray-600 hover:bg-gray-50 rounded-lg px-2.5 py-1.5 transition-colors flex items-center gap-1">
                  <i class="fas fa-external-link-alt"></i>
                </a>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</div>
</template>

<!-- ─────────── RESCHEDULE MINI-MODAL ─────────────────────────────────────── -->
<template x-teleport="body">
<div x-show="rescheduleModal" class="fixed inset-0 z-[70] flex items-center justify-center px-4"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-90"
     x-transition:enter-end="opacity-100 scale-100">
  <div class="absolute inset-0 bg-black/40" @click="rescheduleModal = false"></div>
  <div class="relative bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm">
    <h4 class="font-bold text-gray-900 mb-1">Reschedule Shipment</h4>
    <p class="text-sm text-gray-500 mb-4" x-text="'Order #' + (rescheduleOrder?.order_number || '')"></p>
    <input type="date" x-model="rescheduleDate"
           :min="new Date().toISOString().split('T')[0]"
           class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-400 focus:border-transparent mb-4">
    <div class="flex gap-3">
      <button @click="confirmReschedule()"
              :disabled="!rescheduleDate || actionLoading"
              class="flex-1 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white py-2.5 rounded-xl text-sm font-semibold transition-colors">
        <span x-show="!actionLoading">Confirm</span>
        <span x-show="actionLoading"><i class="fas fa-spinner fa-spin mr-1"></i>Saving…</span>
      </button>
      <button @click="rescheduleModal = false" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 py-2.5 rounded-xl text-sm transition-colors">Cancel</button>
    </div>
  </div>
</div>
</template>


<!-- ─────────── MODAL 3: PRODUCTS UNDER ORDER ─────────────────────────────── -->
<template x-teleport="body">
<div x-show="activeModal === 'products'" class="fixed inset-0 z-50 flex items-start justify-center pt-10 px-4 pb-4"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeAll()"></div>
  <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[88vh] flex flex-col overflow-hidden"
       x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95 -translate-y-4"
       x-transition:enter-end="opacity-100 scale-100 translate-y-0">
    <div class="bg-gradient-to-r from-amber-500 to-yellow-600 px-6 py-4 flex items-center justify-between flex-shrink-0">
      <div>
        <h3 class="text-white font-bold text-lg">Products Under Active Orders</h3>
        <p class="text-amber-100 text-xs"><?php echo $kpi3_count; ?> product lines &bull; <?php echo number_format($kpi3_total_bags, 0); ?> total bags ordered</p>
      </div>
      <div class="flex items-center gap-3">
        <a href="../cr/credit_production.php" target="_blank" class="text-xs text-amber-100 hover:text-white underline">Production Page →</a>
        <button @click="closeAll()" class="text-white/70 hover:text-white w-8 h-8 rounded-full hover:bg-white/20 flex items-center justify-center"><i class="fas fa-times"></i></button>
      </div>
    </div>
    <div class="overflow-y-auto flex-1 p-6">
      <template x-if="productsData.length === 0">
        <div class="text-center py-16 text-gray-400">
          <i class="fas fa-boxes text-5xl mb-4 opacity-30"></i>
          <p class="text-lg font-medium">No active product orders found</p>
        </div>
      </template>
      <div class="rounded-xl border overflow-hidden">
        <table class="w-full text-xs">
          <thead class="bg-gray-50 border-b">
            <tr>
              <th class="px-4 py-3 text-left text-gray-500 font-semibold">Product / Variant</th>
              <th class="px-4 py-3 text-right text-gray-500 font-semibold">Ordered</th>
              <th class="px-4 py-3 text-right text-gray-500 font-semibold">Order Value</th>
              <th class="px-4 py-3 text-right text-gray-500 font-semibold">Stock in Hand</th>
              <th class="px-4 py-3 text-right text-gray-500 font-semibold">Gap</th>
              <th class="px-4 py-3 text-center text-gray-500 font-semibold">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <template x-for="(prod, idx) in productsData" :key="idx">
              <template x-if="prodForm.idx !== idx">
              <tr class="hover:bg-amber-50 transition-colors">
                <td class="px-4 py-3">
                  <p class="font-semibold text-gray-900" x-text="prod.product_name"></p>
                  <p class="text-[10px] text-gray-400" x-text="(prod.sku || '') + (prod.grade ? ' · Grade ' + prod.grade : '')"></p>
                  <span class="inline-block mt-0.5 text-[10px] text-gray-500 bg-gray-100 px-1.5 py-0.5 rounded" x-text="prod.order_count + ' order(s)'"></span>
                </td>
                <td class="px-4 py-3 text-right font-bold text-amber-700" x-text="parseFloat(prod.total_ordered_qty).toLocaleString() + ' bags'"></td>
                <td class="px-4 py-3 text-right text-gray-700" x-text="fmtMoney(prod.total_ordered_value)"></td>
                <td class="px-4 py-3 text-right" :class="parseFloat(prod.stock_in_hand) < parseFloat(prod.total_ordered_qty) ? 'text-red-600 font-bold' : 'text-emerald-600 font-bold'"
                    x-text="parseFloat(prod.stock_in_hand).toLocaleString() + ' bags'"></td>
                <td class="px-4 py-3 text-right">
                  <span :class="(parseFloat(prod.total_ordered_qty) - parseFloat(prod.stock_in_hand)) > 0 ? 'text-red-600 font-bold' : 'text-emerald-600'"
                        x-text="((parseFloat(prod.total_ordered_qty) - parseFloat(prod.stock_in_hand)) > 0 ? '-' : '+') + Math.abs(parseFloat(prod.total_ordered_qty) - parseFloat(prod.stock_in_hand)).toLocaleString()">
                  </span>
                </td>
                <td class="px-4 py-3 text-center">
                  <button @click="prodForm = {idx: idx, product_id: prod.product_id, variant_id: prod.variant_id, qty: Math.max(0, parseFloat(prod.total_ordered_qty) - parseFloat(prod.stock_in_hand)), branch_id: '', date: new Date().toISOString().split('T')[0]}"
                          class="text-xs bg-amber-600 hover:bg-amber-700 text-white px-3 py-1.5 rounded-lg transition-colors flex items-center gap-1 mx-auto">
                    <i class="fas fa-industry"></i> Produce
                  </button>
                </td>
              </tr>
              </template>
              <!-- Production inline form row -->
              <template x-if="prodForm.idx === idx">
              <tr class="bg-amber-50">
                <td colspan="6" class="px-4 py-4">
                  <div class="flex items-end gap-3 flex-wrap">
                    <div>
                      <p class="text-[11px] font-bold text-amber-800 mb-1" x-text="'Schedule Production — ' + prod.product_name"></p>
                    </div>
                    <div>
                      <label class="text-[10px] text-gray-500 block mb-1">Branch</label>
                      <select x-model="prodForm.branch_id" class="text-xs border rounded-lg px-2 py-1.5 focus:ring-2 focus:ring-amber-400">
                        <option value="">Select branch…</option>
                        <template x-for="br in branches" :key="br.id">
                          <option :value="br.id" x-text="br.name"></option>
                        </template>
                      </select>
                    </div>
                    <div>
                      <label class="text-[10px] text-gray-500 block mb-1">Scheduled Date</label>
                      <input type="date" x-model="prodForm.date" class="text-xs border rounded-lg px-2 py-1.5 focus:ring-2 focus:ring-amber-400">
                    </div>
                    <div class="flex gap-2">
                      <button @click="submitProduction(prod)"
                              :disabled="!prodForm.branch_id || !prodForm.date || actionLoading"
                              class="text-xs bg-amber-600 hover:bg-amber-700 disabled:opacity-50 text-white px-4 py-1.5 rounded-lg transition-colors font-semibold">
                        <span x-show="!actionLoading">Confirm</span>
                        <span x-show="actionLoading"><i class="fas fa-spinner fa-spin"></i></span>
                      </button>
                      <button @click="prodForm = {idx: -1}" class="text-xs bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-1.5 rounded-lg transition-colors">Cancel</button>
                    </div>
                  </div>
                </td>
              </tr>
              </template>
            </template>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</template>


<!-- ─────────── MODAL 4: PAYMENTS RECEIVED TODAY ──────────────────────────── -->
<template x-teleport="body">
<div x-show="activeModal === 'payments_in'" class="fixed inset-0 z-50 flex items-start justify-center pt-10 px-4 pb-4"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeAll()"></div>
  <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[88vh] flex flex-col overflow-hidden"
       x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95 -translate-y-4"
       x-transition:enter-end="opacity-100 scale-100 translate-y-0">
    <div class="bg-gradient-to-r from-emerald-500 to-green-600 px-6 py-4 flex items-center justify-between flex-shrink-0">
      <div>
        <h3 class="text-white font-bold text-lg">Payments Received — Today</h3>
        <p class="text-emerald-100 text-xs"><?php echo $kpi4_count; ?> receipts &bull; Total ৳<?php echo number_format($kpi4_total, 0); ?></p>
      </div>
      <div class="flex items-center gap-3">
        <a href="../cr/customer_payment.php" target="_blank" class="text-xs text-emerald-100 hover:text-white underline">Collect More →</a>
        <button @click="closeAll()" class="text-white/70 hover:text-white w-8 h-8 rounded-full hover:bg-white/20 flex items-center justify-center"><i class="fas fa-times"></i></button>
      </div>
    </div>
    <div class="overflow-y-auto flex-1 p-6">
      <template x-if="paymentsIn.length === 0">
        <div class="text-center py-16 text-gray-400">
          <i class="fas fa-money-bill-wave text-5xl mb-4 opacity-30"></i>
          <p class="text-lg font-medium">No payments received today</p>
        </div>
      </template>
      <div class="space-y-3">
        <template x-for="pmt in paymentsIn" :key="pmt.id">
          <div class="bg-white border rounded-xl p-4 hover:shadow-md transition-all">
            <div class="flex flex-col sm:flex-row sm:items-start gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1 flex-wrap">
                  <a :href="'../cr/credit_payment_receipt.php?id=' + pmt.id" target="_blank"
                     class="text-xs font-bold text-indigo-600 hover:underline font-mono" x-text="pmt.payment_number"></a>
                  <span class="text-[10px] px-2 py-0.5 rounded-full font-bold"
                        :class="{'bg-blue-100 text-blue-700': pmt.payment_type==='invoice_payment', 'bg-purple-100 text-purple-700': pmt.payment_type==='advance', 'bg-teal-100 text-teal-700': pmt.payment_type==='partial_payment'}"
                        x-text="(pmt.payment_type||'').replace(/_/g,' ')"></span>
                  <span class="text-[10px] px-2 py-0.5 rounded-full font-bold"
                        :class="{'bg-emerald-100 text-emerald-700': pmt.allocation_status==='allocated', 'bg-yellow-100 text-yellow-700': pmt.allocation_status==='partial', 'bg-gray-100 text-gray-600': pmt.allocation_status==='unallocated'}"
                        x-text="pmt.allocation_status"></span>
                </div>
                <p class="font-bold text-gray-900" x-text="pmt.customer_name"></p>
                <p class="text-xs text-gray-500" x-text="pmt.business_name || ''"></p>
              </div>
              <div class="text-right flex-shrink-0">
                <p class="text-2xl font-extrabold text-emerald-700" x-text="fmtMoney(pmt.amount)"></p>
                <p class="text-xs text-gray-500 mt-0.5" x-text="fmtTime(pmt.created_at)"></p>
              </div>
            </div>
            <!-- Account / bank info bar -->
            <div class="mt-3 pt-3 border-t border-gray-100 flex items-center gap-3 flex-wrap">
              <div class="flex items-center gap-2 bg-gray-50 rounded-lg px-3 py-1.5">
                <i :class="pmt.payment_method === 'Cash' ? 'fas fa-money-bill text-green-500' : 'fas fa-university text-blue-500'" class="text-sm"></i>
                <span class="text-xs font-semibold text-gray-700" x-text="pmt.payment_method"></span>
              </div>
              <template x-if="pmt.bank_name">
                <div class="flex items-center gap-1.5 bg-blue-50 rounded-lg px-3 py-1.5">
                  <i class="fas fa-university text-blue-400 text-xs"></i>
                  <span class="text-xs text-blue-700 font-medium" x-text="pmt.bank_name + (pmt.account_name ? ' · ' + pmt.account_name : '')"></span>
                </div>
              </template>
              <template x-if="pmt.cash_bank_name && !pmt.bank_name">
                <div class="flex items-center gap-1.5 bg-green-50 rounded-lg px-3 py-1.5">
                  <i class="fas fa-vault text-green-400 text-xs"></i>
                  <span class="text-xs text-green-700 font-medium" x-text="pmt.cash_bank_name + (pmt.cash_account_name ? ' · ' + pmt.cash_account_name : '')"></span>
                </div>
              </template>
              <template x-if="pmt.reference_number">
                <span class="text-xs text-gray-400 font-mono" x-text="'Ref: ' + pmt.reference_number"></span>
              </template>
              <template x-if="pmt.cheque_number">
                <span class="text-xs text-gray-400" x-text="'Cheque: ' + pmt.cheque_number"></span>
              </template>
              <a :href="'../cr/credit_payment_receipt.php?id=' + pmt.id" target="_blank"
                 class="ml-auto text-xs text-indigo-500 hover:text-indigo-700 flex items-center gap-1">
                <i class="fas fa-receipt"></i> Receipt
              </a>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</div>
</template>


<!-- ─────────── MODAL 5: RECEIVABLES ─────────────────────────────────────── -->
<template x-teleport="body">
<div x-show="activeModal === 'receivables'" class="fixed inset-0 z-50 flex items-start justify-center pt-10 px-4 pb-4"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeAll()"></div>
  <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[88vh] flex flex-col overflow-hidden"
       x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95 -translate-y-4"
       x-transition:enter-end="opacity-100 scale-100 translate-y-0">
    <div class="bg-gradient-to-r from-indigo-600 to-blue-700 px-6 py-4 flex items-center justify-between flex-shrink-0">
      <div>
        <h3 class="text-white font-bold text-lg">Outstanding Receivables</h3>
        <p class="text-indigo-200 text-xs"><?php echo $kpi5_count; ?> customers &bull; Total ৳<?php echo number_format($kpi5_total, 0); ?></p>
      </div>
      <div class="flex items-center gap-3">
        <a href="../cr/ageing_report.php" target="_blank" class="text-xs text-indigo-200 hover:text-white underline">Ageing Report →</a>
        <button @click="closeAll()" class="text-white/70 hover:text-white w-8 h-8 rounded-full hover:bg-white/20 flex items-center justify-center"><i class="fas fa-times"></i></button>
      </div>
    </div>
    <div class="overflow-y-auto flex-1 p-6">
      <div class="rounded-xl border overflow-hidden">
        <table class="w-full text-xs">
          <thead class="bg-gray-50 border-b">
            <tr>
              <th class="px-4 py-3 text-left text-gray-500 font-semibold">#</th>
              <th class="px-4 py-3 text-left text-gray-500 font-semibold">Customer</th>
              <th class="px-4 py-3 text-right text-gray-500 font-semibold">Balance</th>
              <th class="px-4 py-3 text-right text-gray-500 font-semibold">Credit Limit</th>
              <th class="px-4 py-3 text-right text-gray-500 font-semibold">Utilisation</th>
              <th class="px-4 py-3 text-center text-gray-500 font-semibold">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <template x-for="(cust, idx) in receivables" :key="cust.id">
              <tr class="hover:bg-indigo-50 transition-colors">
                <td class="px-4 py-3 text-gray-400 font-mono" x-text="idx + 1"></td>
                <td class="px-4 py-3">
                  <p class="font-semibold text-gray-900" x-text="cust.name"></p>
                  <p x-show="cust.business_name" class="text-[10px] text-gray-400" x-text="cust.business_name"></p>
                </td>
                <td class="px-4 py-3 text-right font-extrabold text-red-600" x-text="fmtMoney(cust.current_balance)"></td>
                <td class="px-4 py-3 text-right text-gray-600" x-text="fmtMoney(cust.credit_limit)"></td>
                <td class="px-4 py-3 text-right">
                  <div class="flex items-center justify-end gap-2">
                    <div class="w-16 bg-gray-200 rounded-full h-1.5">
                      <div class="h-1.5 rounded-full"
                           :class="(parseFloat(cust.current_balance) / (parseFloat(cust.credit_limit) || 1)) > 0.8 ? 'bg-red-500' : 'bg-blue-500'"
                           :style="'width:' + Math.min((parseFloat(cust.current_balance) / (parseFloat(cust.credit_limit) || 1)) * 100, 100) + '%'"></div>
                    </div>
                    <span x-text="cust.credit_limit > 0 ? ((parseFloat(cust.current_balance) / parseFloat(cust.credit_limit)) * 100).toFixed(0) + '%' : '—'"></span>
                  </div>
                </td>
                <td class="px-4 py-3 text-center">
                  <div class="flex items-center justify-center gap-2">
                    <a :href="'../cr/customer_ledger.php?customer_id=' + cust.id" target="_blank"
                       class="text-[10px] text-indigo-600 hover:underline flex items-center gap-0.5">
                      <i class="fas fa-book"></i> Ledger
                    </a>
                    <a :href="'../cr/customer_payment.php?customer_id=' + cust.id" target="_blank"
                       class="text-[10px] text-emerald-600 hover:underline flex items-center gap-0.5">
                      <i class="fas fa-dollar-sign"></i> Pay
                    </a>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
          <!-- Total row -->
          <tfoot class="bg-indigo-50 border-t-2 border-indigo-200">
            <tr>
              <td colspan="2" class="px-4 py-3 text-xs font-bold text-gray-700">Total Receivables</td>
              <td class="px-4 py-3 text-right text-sm font-extrabold text-red-700"><?php echo '৳' . number_format($kpi5_total, 0); ?></td>
              <td colspan="3"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</div>
</template>


<!-- ─────────── MODAL 6: SUPPLIER PAYABLES ───────────────────────────────── -->
<template x-teleport="body">
<div x-show="activeModal === 'sup_payable'" class="fixed inset-0 z-50 flex items-start justify-center pt-10 px-4 pb-4"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeAll()"></div>
  <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[88vh] flex flex-col overflow-hidden"
       x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95 -translate-y-4"
       x-transition:enter-end="opacity-100 scale-100 translate-y-0">
    <div class="bg-gradient-to-r from-orange-500 to-amber-600 px-6 py-4 flex items-center justify-between flex-shrink-0">
      <div>
        <h3 class="text-white font-bold text-lg">Supplier Payables — Outstanding</h3>
        <p class="text-orange-100 text-xs"><?php echo $kpi6_count; ?> POs &bull; Total ৳<?php echo number_format($kpi6_total, 0); ?></p>
      </div>
      <div class="flex items-center gap-3">
        <a href="../purchase/purchase_adnan_record_payment.php" target="_blank" class="text-xs text-orange-100 hover:text-white underline">Record Payment →</a>
        <button @click="closeAll()" class="text-white/70 hover:text-white w-8 h-8 rounded-full hover:bg-white/20 flex items-center justify-center"><i class="fas fa-times"></i></button>
      </div>
    </div>
    <div class="overflow-y-auto flex-1 p-6">
      <div class="rounded-xl border overflow-hidden">
        <table class="w-full text-xs">
          <thead class="bg-gray-50 border-b">
            <tr>
              <th class="px-4 py-3 text-left text-gray-500 font-semibold">PO #</th>
              <th class="px-4 py-3 text-left text-gray-500 font-semibold">Supplier</th>
              <th class="px-4 py-3 text-left text-gray-500 font-semibold">Date</th>
              <th class="px-4 py-3 text-right text-gray-500 font-semibold">Exp. Payable</th>
              <th class="px-4 py-3 text-right text-gray-500 font-semibold">Paid</th>
              <th class="px-4 py-3 text-right text-gray-500 font-semibold">Balance Due</th>
              <th class="px-4 py-3 text-center text-gray-500 font-semibold">Status</th>
              <th class="px-4 py-3 text-center text-gray-500 font-semibold">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <template x-for="po in supplierPayables" :key="po.id">
              <tr class="hover:bg-orange-50 transition-colors">
                <td class="px-4 py-3">
                  <a :href="'../purchase/purchase_adnan_view_po.php?id=' + po.id" target="_blank"
                     class="text-xs font-bold text-indigo-600 hover:underline font-mono" x-text="'PO-' + po.po_number"></a>
                </td>
                <td class="px-4 py-3 font-medium text-gray-800" x-text="po.supplier_name"></td>
                <td class="px-4 py-3 text-gray-500" x-text="fmtDate(po.po_date)"></td>
                <td class="px-4 py-3 text-right text-gray-700" x-text="fmtMoney(po.expected_payable)"></td>
                <td class="px-4 py-3 text-right text-emerald-600 font-semibold" x-text="fmtMoney(po.total_paid)"></td>
                <td class="px-4 py-3 text-right font-extrabold text-orange-700" x-text="fmtMoney(po.balance_due)"></td>
                <td class="px-4 py-3 text-center">
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-orange-100 text-orange-700" x-text="po.payment_status"></span>
                </td>
                <td class="px-4 py-3 text-center">
                  <a :href="'../purchase/purchase_adnan_record_payment.php?po_id=' + po.id" target="_blank"
                     class="text-[10px] text-orange-600 hover:underline flex items-center gap-0.5 justify-center">
                    <i class="fas fa-money-bill"></i> Pay
                  </a>
                </td>
              </tr>
            </template>
          </tbody>
          <tfoot class="bg-orange-50 border-t-2 border-orange-200">
            <tr>
              <td colspan="5" class="px-4 py-3 text-xs font-bold text-gray-700">Total Outstanding</td>
              <td class="px-4 py-3 text-right text-sm font-extrabold text-orange-800"><?php echo '৳' . number_format($kpi6_total, 0); ?></td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</div>
</template>


<!-- ─────────── MODAL 6b: SUPPLIER ADVANCE PAID ─────────────────────────── -->
<template x-teleport="body">
<div x-show="activeModal === 'sup_advance'" class="fixed inset-0 z-50 flex items-start justify-center pt-10 px-4 pb-4"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeAll()"></div>
  <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[88vh] flex flex-col overflow-hidden"
       x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95 -translate-y-4"
       x-transition:enter-end="opacity-100 scale-100 translate-y-0"
       x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100"
       x-transition:leave-end="opacity-0 scale-95">
    <div class="bg-gradient-to-r from-teal-600 to-cyan-700 px-6 py-4 flex items-center justify-between flex-shrink-0">
      <div>
        <h3 class="text-white font-bold text-lg">Supplier Advance — Overpaid on Active POs</h3>
        <p class="text-teal-100 text-xs"><?php echo $kpi_adv_count; ?> POs &bull; Total ৳<?php echo number_format($kpi_adv_total, 0); ?></p>
      </div>
      <div class="flex items-center gap-3">
        <a href="../purchase/purchase_adnan_index.php" target="_blank" class="text-xs text-teal-100 hover:text-white underline">Purchase Index →</a>
        <button @click="closeAll()" class="text-white/70 hover:text-white w-8 h-8 rounded-full hover:bg-white/20 flex items-center justify-center"><i class="fas fa-times"></i></button>
      </div>
    </div>
    <div class="overflow-y-auto flex-1 p-6">
      <div class="bg-teal-50 border border-teal-200 rounded-xl px-4 py-3 mb-4 text-xs text-teal-800">
        <i class="fas fa-info-circle mr-1"></i>
        These POs have received <strong>more payment than the GRN-based expected amount</strong>. The advance will offset future deliveries.
      </div>
      <div class="rounded-xl border overflow-hidden">
        <table class="w-full text-xs">
          <thead class="bg-gray-50 border-b">
            <tr>
              <th class="px-4 py-3 text-left text-gray-500 font-semibold">PO #</th>
              <th class="px-4 py-3 text-left text-gray-500 font-semibold">Supplier</th>
              <th class="px-4 py-3 text-left text-gray-500 font-semibold">Date</th>
              <th class="px-4 py-3 text-right text-gray-500 font-semibold">Exp. Payable</th>
              <th class="px-4 py-3 text-right text-gray-500 font-semibold">Total Paid</th>
              <th class="px-4 py-3 text-right text-gray-500 font-semibold">Advance</th>
              <th class="px-4 py-3 text-center text-gray-500 font-semibold">Status</th>
              <th class="px-4 py-3 text-center text-gray-500 font-semibold">Link</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <template x-for="po in supplierAdvances" :key="po.id">
              <tr class="hover:bg-teal-50 transition-colors">
                <td class="px-4 py-3">
                  <a :href="'../purchase/purchase_adnan_view_po.php?id=' + po.id" target="_blank"
                     class="text-xs font-bold text-indigo-600 hover:underline font-mono" x-text="'PO-' + po.po_number"></a>
                </td>
                <td class="px-4 py-3 font-medium text-gray-800" x-text="po.supplier_name"></td>
                <td class="px-4 py-3 text-gray-500" x-text="fmtDate(po.po_date)"></td>
                <td class="px-4 py-3 text-right text-gray-600" x-text="fmtMoney(po.expected_payable)"></td>
                <td class="px-4 py-3 text-right text-emerald-600 font-semibold" x-text="fmtMoney(po.total_paid)"></td>
                <td class="px-4 py-3 text-right font-extrabold text-teal-700" x-text="'+' + fmtMoney(po.advance_amount)"></td>
                <td class="px-4 py-3 text-center">
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-teal-100 text-teal-700" x-text="po.delivery_status"></span>
                </td>
                <td class="px-4 py-3 text-center">
                  <a :href="'../purchase/purchase_adnan_view_po.php?id=' + po.id" target="_blank"
                     class="text-[10px] text-indigo-500 hover:underline flex items-center gap-0.5 justify-center">
                    <i class="fas fa-external-link-alt"></i> View
                  </a>
                </td>
              </tr>
            </template>
          </tbody>
          <tfoot class="bg-teal-50 border-t-2 border-teal-200">
            <tr>
              <td colspan="5" class="px-4 py-3 text-xs font-bold text-gray-700">Total Advance</td>
              <td class="px-4 py-3 text-right text-sm font-extrabold text-teal-800">+৳<?php echo number_format($kpi_adv_total, 0); ?></td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</div>
</template>

<!-- ─────────── MODAL 7: SUPPLIER PAYMENTS TODAY ─────────────────────────── -->
<template x-teleport="body">
<div x-show="activeModal === 'sup_payments'" class="fixed inset-0 z-50 flex items-start justify-center pt-10 px-4 pb-4"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeAll()"></div>
  <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[88vh] flex flex-col overflow-hidden"
       x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95 -translate-y-4"
       x-transition:enter-end="opacity-100 scale-100 translate-y-0">
    <div class="bg-gradient-to-r from-red-600 to-rose-700 px-6 py-4 flex items-center justify-between flex-shrink-0">
      <div>
        <h3 class="text-white font-bold text-lg">Supplier Payments — Today</h3>
        <p class="text-red-200 text-xs"><?php echo $kpi7_count; ?> payments &bull; Total ৳<?php echo number_format($kpi7_total, 0); ?></p>
      </div>
      <div class="flex items-center gap-3">
        <a href="../purchase/payments.php" target="_blank" class="text-xs text-red-200 hover:text-white underline">All Payments →</a>
        <button @click="closeAll()" class="text-white/70 hover:text-white w-8 h-8 rounded-full hover:bg-white/20 flex items-center justify-center"><i class="fas fa-times"></i></button>
      </div>
    </div>
    <div class="overflow-y-auto flex-1 p-6">
      <template x-if="supplierPayments.length === 0">
        <div class="text-center py-16 text-gray-400">
          <i class="fas fa-handshake text-5xl mb-4 opacity-30"></i>
          <p class="text-lg font-medium">No supplier payments made today</p>
        </div>
      </template>
      <div class="space-y-3">
        <template x-for="pmt in supplierPayments" :key="pmt.id">
          <div class="bg-white border rounded-xl p-4 hover:shadow-md transition-all">
            <div class="flex flex-col sm:flex-row sm:items-start gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                  <a :href="'../purchase/purchase_adnan_payment_receipt.php?id=' + pmt.id" target="_blank"
                     class="text-xs font-bold text-indigo-600 hover:underline font-mono" x-text="pmt.payment_voucher_number"></a>
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-orange-100 text-orange-700" x-text="pmt.payment_type || 'regular'"></span>
                </div>
                <p class="font-bold text-gray-900" x-text="pmt.supplier_name"></p>
                <p class="text-xs text-gray-500" x-text="'PO #' + pmt.po_number"></p>
              </div>
              <div class="text-right flex-shrink-0">
                <p class="text-2xl font-extrabold text-red-700" x-text="fmtMoney(pmt.amount_paid)"></p>
                <p class="text-xs text-gray-500 mt-0.5" x-text="fmtTime(pmt.created_at)"></p>
              </div>
            </div>
            <!-- Account info bar -->
            <div class="mt-3 pt-3 border-t border-gray-100 flex items-center gap-3 flex-wrap">
              <div class="flex items-center gap-2 bg-gray-50 rounded-lg px-3 py-1.5">
                <i :class="pmt.payment_method === 'cash' ? 'fas fa-money-bill text-green-500' : 'fas fa-university text-blue-500'" class="text-sm"></i>
                <span class="text-xs font-semibold text-gray-700" x-text="pmt.payment_method?.toUpperCase()"></span>
              </div>
              <template x-if="pmt.ba_bank_name">
                <div class="flex items-center gap-1.5 bg-blue-50 rounded-lg px-3 py-1.5">
                  <i class="fas fa-university text-blue-400 text-xs"></i>
                  <span class="text-xs text-blue-700 font-medium"
                        x-text="pmt.ba_bank_name + (pmt.account_name ? ' · ' + pmt.account_name : '') + (pmt.bank_branch ? ' · ' + pmt.bank_branch : '')"></span>
                </div>
              </template>
              <template x-if="!pmt.ba_bank_name && pmt.cached_bank">
                <div class="flex items-center gap-1.5 bg-blue-50 rounded-lg px-3 py-1.5">
                  <i class="fas fa-university text-blue-400 text-xs"></i>
                  <span class="text-xs text-blue-700 font-medium" x-text="pmt.cached_bank"></span>
                </div>
              </template>
              <template x-if="pmt.reference_number">
                <span class="text-xs text-gray-400 font-mono" x-text="'Ref: ' + pmt.reference_number"></span>
              </template>
              <a :href="'../purchase/purchase_adnan_payment_receipt.php?id=' + pmt.id" target="_blank"
                 class="ml-auto text-xs text-indigo-500 hover:text-indigo-700 flex items-center gap-1">
                <i class="fas fa-receipt"></i> Receipt
              </a>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</div>
</template>


<!-- ─────────── MODAL 8: EXPENSE PAYMENTS TODAY ──────────────────────────── -->
<template x-teleport="body">
<div x-show="activeModal === 'expenses'" class="fixed inset-0 z-50 flex items-start justify-center pt-10 px-4 pb-4"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeAll()"></div>
  <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[88vh] flex flex-col overflow-hidden"
       x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95 -translate-y-4"
       x-transition:enter-end="opacity-100 scale-100 translate-y-0">
    <div class="bg-gradient-to-r from-yellow-500 to-orange-500 px-6 py-4 flex items-center justify-between flex-shrink-0">
      <div>
        <h3 class="text-white font-bold text-lg">Expense Payments — Today</h3>
        <p class="text-yellow-100 text-xs"><?php echo $kpi8_count; ?> vouchers &bull; Total ৳<?php echo number_format($kpi8_total, 0); ?></p>
      </div>
      <div class="flex items-center gap-3">
        <a href="../expense/expense_voucher_list.php" target="_blank" class="text-xs text-yellow-100 hover:text-white underline">All Vouchers →</a>
        <button @click="closeAll()" class="text-white/70 hover:text-white w-8 h-8 rounded-full hover:bg-white/20 flex items-center justify-center"><i class="fas fa-times"></i></button>
      </div>
    </div>
    <div class="overflow-y-auto flex-1 p-6">
      <template x-if="expensePayments.length === 0">
        <div class="text-center py-16 text-gray-400">
          <i class="fas fa-receipt text-5xl mb-4 opacity-30"></i>
          <p class="text-lg font-medium">No approved expense payments today</p>
        </div>
      </template>
      <div class="space-y-3">
        <template x-for="exp in expensePayments" :key="exp.id">
          <div class="bg-white border rounded-xl p-4 hover:shadow-md transition-all">
            <div class="flex flex-col sm:flex-row sm:items-start gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1 flex-wrap">
                  <a :href="'../expense/view_expense_voucher.php?id=' + exp.id" target="_blank"
                     class="text-xs font-bold text-indigo-600 hover:underline font-mono" x-text="exp.voucher_number"></a>
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700" x-text="exp.status"></span>
                </div>
                <p class="font-bold text-gray-900" x-text="exp.category_name || 'Uncategorized'"></p>
                <p class="text-xs text-gray-500" x-text="exp.subcategory_name || ''"></p>
                <p x-show="exp.remarks" class="text-xs text-gray-400 mt-1 italic" x-text="exp.remarks"></p>
              </div>
              <div class="text-right flex-shrink-0">
                <p class="text-2xl font-extrabold text-yellow-700" x-text="fmtMoney(exp.total_amount)"></p>
                <p class="text-xs text-gray-500 mt-0.5" x-text="fmtTime(exp.created_at)"></p>
              </div>
            </div>
            <!-- Account info bar -->
            <div class="mt-3 pt-3 border-t border-gray-100 flex items-center gap-3 flex-wrap">
              <div class="flex items-center gap-2 bg-gray-50 rounded-lg px-3 py-1.5">
                <i :class="exp.payment_method === 'cash' ? 'fas fa-money-bill text-green-500' : 'fas fa-university text-blue-500'" class="text-sm"></i>
                <span class="text-xs font-semibold text-gray-700" x-text="exp.payment_method?.toUpperCase()"></span>
              </div>
              <template x-if="exp.bank_name">
                <div class="flex items-center gap-1.5 bg-blue-50 rounded-lg px-3 py-1.5">
                  <i class="fas fa-university text-blue-400 text-xs"></i>
                  <span class="text-xs text-blue-700 font-medium"
                        x-text="exp.bank_name + (exp.account_name ? ' · ' + exp.account_name : '')"></span>
                </div>
              </template>
              <template x-if="!exp.bank_name && exp.cash_bank_name">
                <div class="flex items-center gap-1.5 bg-green-50 rounded-lg px-3 py-1.5">
                  <i class="fas fa-vault text-green-400 text-xs"></i>
                  <span class="text-xs text-green-700 font-medium"
                        x-text="exp.cash_bank_name + (exp.cash_account_name ? ' · ' + exp.cash_account_name : '')"></span>
                </div>
              </template>
              <template x-if="exp.payment_account_name && !exp.bank_name && !exp.cash_bank_name">
                <div class="flex items-center gap-1.5 bg-gray-50 rounded-lg px-3 py-1.5">
                  <i class="fas fa-wallet text-gray-400 text-xs"></i>
                  <span class="text-xs text-gray-600 font-medium" x-text="exp.payment_account_name"></span>
                </div>
              </template>
              <template x-if="exp.payment_reference">
                <span class="text-xs text-gray-400 font-mono" x-text="'Ref: ' + exp.payment_reference"></span>
              </template>
              <a :href="'../expense/view_expense_voucher.php?id=' + exp.id" target="_blank"
                 class="ml-auto text-xs text-indigo-500 hover:text-indigo-700 flex items-center gap-1">
                <i class="fas fa-external-link-alt"></i> Details
              </a>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</div>
</template>


<!-- ══════════════════════════════════════════════════════════════════════════
     ALPINE.JS COMPONENT
══════════════════════════════════════════════════════════════════════════════ -->
<script>
function opsCenter() {
    return {
        // ── State ──────────────────────────────────────────────────────────
        activeModal:      null,
        detailModal:      false,
        selectedItem:     null,
        rescheduleModal:  false,
        rescheduleOrder:  null,
        rescheduleDate:   '',
        prodForm:         { idx: -1, product_id: null, variant_id: null, branch_id: '', date: '' },
        actionLoading:    false,
        currentTime:      '',
        toast:            { show: false, ok: true, msg: '' },

        // ── Data (PHP-injected) ────────────────────────────────────────────
        ordersToday:      <?php echo $j_orders; ?>,
        shipmentOrders:   <?php echo $j_shipment; ?>,
        productsData:     <?php echo $j_products; ?>,
        branches:         <?php echo $j_branches; ?>,
        paymentsIn:       <?php echo $j_payments_in; ?>,
        receivables:      <?php echo $j_receivables; ?>,
        supplierPayables: <?php echo $j_sup_pay; ?>,
        supplierAdvances: <?php echo $j_sup_adv; ?>,
        supplierPayments: <?php echo $j_sup_pmts; ?>,
        expensePayments:  <?php echo $j_expenses; ?>,

        // ── Lifecycle ─────────────────────────────────────────────────────
        init() {
            this.tickClock();
            setInterval(() => this.tickClock(), 1000);
        },
        tickClock() {
            this.currentTime = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        },

        // ── Modal controls ────────────────────────────────────────────────
        openModal(name)  { this.activeModal = name; this.prodForm = { idx: -1 }; },
        closeAll()       { this.activeModal = null; this.detailModal = false; this.selectedItem = null; this.rescheduleModal = false; },
        openDetail(item) { this.selectedItem = item; this.detailModal = true; },

        // ── Shipment actions ──────────────────────────────────────────────
        startReschedule(order) {
            this.rescheduleOrder = order;
            this.rescheduleDate  = order.required_date || new Date().toISOString().split('T')[0];
            this.rescheduleModal = true;
        },
        async confirmReschedule() {
            if (!this.rescheduleDate || !this.rescheduleOrder) return;
            const ok = await this.doAction('reschedule', this.rescheduleOrder.id, { new_date: this.rescheduleDate });
            if (ok) {
                this.rescheduleOrder.required_date = this.rescheduleDate;
                this.rescheduleModal = false;
            }
        },

        // ── Production ───────────────────────────────────────────────────
        async submitProduction(prod) {
            const ok = await this.doAction('order_production', 0, {
                product_id:     prod.product_id,
                variant_id:     prod.variant_id,
                branch_id:      this.prodForm.branch_id,
                scheduled_date: this.prodForm.date,
            });
            if (ok) this.prodForm = { idx: -1 };
        },

        // ── AJAX mutation ─────────────────────────────────────────────────
        async doAction(action, orderId, extra = {}) {
            this.actionLoading = true;
            try {
                const fd = new FormData();
                fd.append('action', action);
                if (orderId) fd.append('order_id', orderId);
                for (const [k, v] of Object.entries(extra)) fd.append(k, v);

                const res  = await fetch('ops_ajax.php', { method: 'POST', body: fd });
                const data = await res.json();
                this.showToast(data.success, data.message);
                return data.success;
            } catch (e) {
                this.showToast(false, 'Network error — please try again');
                return false;
            } finally {
                this.actionLoading = false;
            }
        },

        // ── Toast ─────────────────────────────────────────────────────────
        showToast(ok, msg) {
            this.toast = { show: true, ok, msg };
            setTimeout(() => this.toast.show = false, 3500);
        },

        // ── Formatters ───────────────────────────────────────────────────
        fmtMoney(val) {
            const n = parseFloat(val || 0);
            return '৳' + n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        },
        fmtDate(val) {
            if (!val) return '—';
            const d = new Date(val + (val.length === 10 ? 'T00:00:00' : ''));
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        },
        fmtTime(val) {
            if (!val) return '';
            return new Date(val).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        },

        // ── Badge helpers ─────────────────────────────────────────────────
        statusBadge(s) {
            const m = {
                draft:'bg-gray-100 text-gray-600 border-gray-200',
                pending_approval:'bg-yellow-100 text-yellow-700 border-yellow-200',
                approved:'bg-green-100 text-green-700 border-green-200',
                in_production:'bg-purple-100 text-purple-700 border-purple-200',
                produced:'bg-indigo-100 text-indigo-700 border-indigo-200',
                ready_to_ship:'bg-teal-100 text-teal-700 border-teal-200',
                shipped:'bg-blue-100 text-blue-700 border-blue-200',
                delivered:'bg-emerald-100 text-emerald-700 border-emerald-200',
                escalated:'bg-red-100 text-red-700 border-red-200',
                cancelled:'bg-gray-200 text-gray-500 border-gray-300',
            };
            return m[(s||'').toLowerCase()] || 'bg-gray-100 text-gray-600 border-gray-200';
        },
        statusLabel(s) {
            return (s || '').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        },
        priorityBadge(p) {
            return { urgent:'bg-red-100 text-red-700 border-red-200', high:'bg-orange-100 text-orange-700 border-orange-200', normal:'bg-blue-100 text-blue-600 border-blue-200', low:'bg-gray-100 text-gray-500 border-gray-200' }[p] || 'bg-gray-100 text-gray-500 border-gray-200';
        },
    };
}
</script>

<?php require_once '../templates/footer.php'; ?>

<?php
/**
 * Cash Flow & Operations Dashboard
 * Cash inflow, outflow, raw material inflow, product outflow KPIs
 *
 * @package   Ujjal Flour Mills
 * @subpackage Admin
 */

require_once '../core/init.php';
restrict_access(['Superadmin', 'admin']);

$pageTitle = 'Cash Flow & Operations';
global $db;

// ── Helpers ──────────────────────────────────────────────────────────────────
function cfq(string $sql, array $p = [], $default = 0) {
    global $db;
    $res = $db->query($sql, $p);
    if ($res->error()) return $default;
    $row = $res->first();
    return $row ? ($row->v ?? $default) : $default;
}
function cfql(string $sql, array $p = []): array {
    global $db;
    $res = $db->query($sql, $p);
    return $res->error() ? [] : ($res->results() ?: []);
}
function badge_pct(float $now, float $prev, bool $invert = false): string {
    if ($prev == 0) return '<span class="text-xs text-gray-400 ml-1">vs prev —</span>';
    $pct   = (($now - $prev) / $prev) * 100;
    $up    = $pct >= 0;
    // invert=true → rising is bad (e.g. outflow)
    $good  = $invert ? !$up : $up;
    $color = $good ? 'text-emerald-700 bg-emerald-50 border border-emerald-200'
                   : 'text-red-700 bg-red-50 border border-red-200';
    $arrow = $up ? '↑' : '↓';
    $sign  = $up ? '+' : '';
    return "<span class=\"inline-flex items-center text-[11px] px-1.5 py-0.5 rounded $color font-semibold ml-1\">"
         . "$arrow $sign" . number_format(abs($pct), 1) . "%</span>";
}

// ── Date ranges ──────────────────────────────────────────────────────────────
$today        = date('Y-m-d');
$month_s      = date('Y-m-01');
$month_e      = date('Y-m-t');
$prev_ms      = date('Y-m-01', strtotime('-1 month'));
$prev_me      = date('Y-m-t',  strtotime('-1 month'));
$day30_s      = date('Y-m-d',  strtotime('-29 days'));

// ── CASH INFLOW: Customer payments ───────────────────────────────────────────
$inflow_today      = (float) cfq("SELECT IFNULL(SUM(amount),0) AS v FROM customer_payments WHERE payment_date=?", [$today]);
$inflow_month      = (float) cfq("SELECT IFNULL(SUM(amount),0) AS v FROM customer_payments WHERE payment_date BETWEEN ? AND ?", [$month_s,$month_e]);
$inflow_prev       = (float) cfq("SELECT IFNULL(SUM(amount),0) AS v FROM customer_payments WHERE payment_date BETWEEN ? AND ?", [$prev_ms,$prev_me]);
$inflow_count      = (int)   cfq("SELECT COUNT(id) AS v FROM customer_payments WHERE payment_date BETWEEN ? AND ?", [$month_s,$month_e]);
// Allocated = applied against specific orders (allocation_status='allocated' OR invoice_payment type)
$inflow_allocated  = (float) cfq("SELECT IFNULL(SUM(amount),0) AS v FROM customer_payments WHERE payment_date BETWEEN ? AND ? AND (allocation_status='allocated' OR payment_type='invoice_payment')", [$month_s,$month_e]);
// Unallocated = pure advance balance sitting with customers
$inflow_unallocated = $inflow_month - $inflow_allocated;
// Bank vs Cash breakdown for additional insight
$inflow_bank       = (float) cfq("SELECT IFNULL(SUM(amount),0) AS v FROM customer_payments WHERE payment_date BETWEEN ? AND ? AND payment_method != 'Cash'", [$month_s,$month_e]);
$inflow_cash       = $inflow_month - $inflow_bank;

// ── CASH OUTFLOW: Supplier payments + Expenses ────────────────────────────────
$out_supplier_today = (float) cfq("SELECT IFNULL(SUM(amount_paid),0) AS v FROM purchase_payments_adnan WHERE is_posted=1 AND payment_date=?", [$today]);
$out_supplier_month = (float) cfq("SELECT IFNULL(SUM(amount_paid),0) AS v FROM purchase_payments_adnan WHERE is_posted=1 AND payment_date BETWEEN ? AND ?", [$month_s,$month_e]);
$out_supplier_prev  = (float) cfq("SELECT IFNULL(SUM(amount_paid),0) AS v FROM purchase_payments_adnan WHERE is_posted=1 AND payment_date BETWEEN ? AND ?", [$prev_ms,$prev_me]);
$out_supplier_count = (int)   cfq("SELECT COUNT(id) AS v FROM purchase_payments_adnan WHERE is_posted=1 AND payment_date BETWEEN ? AND ?", [$month_s,$month_e]);

$out_expense_today  = (float) cfq("SELECT IFNULL(SUM(total_amount),0) AS v FROM expense_vouchers WHERE expense_date=? AND status='approved'", [$today]);
$out_expense_month  = (float) cfq("SELECT IFNULL(SUM(total_amount),0) AS v FROM expense_vouchers WHERE expense_date BETWEEN ? AND ? AND status='approved'", [$month_s,$month_e]);
$out_expense_prev   = (float) cfq("SELECT IFNULL(SUM(total_amount),0) AS v FROM expense_vouchers WHERE expense_date BETWEEN ? AND ? AND status='approved'", [$prev_ms,$prev_me]);
$out_expense_count  = (int)   cfq("SELECT COUNT(id) AS v FROM expense_vouchers WHERE expense_date BETWEEN ? AND ? AND status='approved'", [$month_s,$month_e]);

$outflow_today  = $out_supplier_today + $out_expense_today;
$outflow_month  = $out_supplier_month + $out_expense_month;
$outflow_prev   = $out_supplier_prev  + $out_expense_prev;

// ── NET CASH ─────────────────────────────────────────────────────────────────
$net_today = $inflow_today - $outflow_today;
$net_month = $inflow_month - $outflow_month;
$net_prev  = $inflow_prev  - $outflow_prev;

// ── RECEIVABLES & PAYABLES ────────────────────────────────────────────────────
$receivables   = (float) cfq("SELECT IFNULL(SUM(current_balance),0) AS v FROM customers WHERE current_balance > 0.01 AND customer_type='Credit'", []);
$payables      = (float) cfq("
    SELECT IFNULL(SUM(bal.balance_due), 0) AS v FROM (
        SELECT COALESCE(grn.total_expected_qty, 0) * poa.unit_price_per_kg
                   - COALESCE(pmt.total_paid, 0) AS balance_due
        FROM purchase_orders_adnan poa
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
        HAVING balance_due > 0.01
    ) bal
", []);

// ── RAW MATERIALS INFLOW (Wheat GRNs) ────────────────────────────────────────
$rm_qty_today   = (float) cfq("SELECT IFNULL(SUM(quantity_received_kg),0) AS v FROM goods_received_adnan WHERE grn_date=? AND grn_status NOT IN ('cancelled')", [$today]);
$rm_qty_month   = (float) cfq("SELECT IFNULL(SUM(quantity_received_kg),0) AS v FROM goods_received_adnan WHERE grn_date BETWEEN ? AND ? AND grn_status NOT IN ('cancelled')", [$month_s,$month_e]);
$rm_value_month = (float) cfq("SELECT IFNULL(SUM(total_value),0) AS v FROM goods_received_adnan WHERE grn_date BETWEEN ? AND ? AND grn_status NOT IN ('cancelled')", [$month_s,$month_e]);
$rm_qty_prev    = (float) cfq("SELECT IFNULL(SUM(quantity_received_kg),0) AS v FROM goods_received_adnan WHERE grn_date BETWEEN ? AND ? AND grn_status NOT IN ('cancelled')", [$prev_ms,$prev_me]);
$rm_grn_count   = (int)   cfq("SELECT COUNT(id) AS v FROM goods_received_adnan WHERE grn_date BETWEEN ? AND ? AND grn_status NOT IN ('cancelled')", [$month_s,$month_e]);

$open_po_count  = (int)   cfq("SELECT COUNT(id) AS v FROM purchase_orders_adnan WHERE delivery_status NOT IN ('completed','closed','cancelled')", []);
$open_po_value  = (float) cfq("SELECT IFNULL(SUM(total_order_value),0) AS v FROM purchase_orders_adnan WHERE delivery_status NOT IN ('completed','closed','cancelled')", []);

// ── PRODUCT OUTFLOW (Sales dispatched/delivered) ──────────────────────────────
$sales_today    = (float) cfq("SELECT IFNULL(SUM(total_amount),0) AS v FROM credit_orders WHERE DATE(updated_at)=? AND status IN ('shipped','delivered')", [$today]);
$sales_month    = (float) cfq("SELECT IFNULL(SUM(total_amount),0) AS v FROM credit_orders WHERE order_date BETWEEN ? AND ? AND status IN ('shipped','delivered')", [$month_s,$month_e]);
$sales_prev     = (float) cfq("SELECT IFNULL(SUM(total_amount),0) AS v FROM credit_orders WHERE order_date BETWEEN ? AND ? AND status IN ('shipped','delivered')", [$prev_ms,$prev_me]);
$sales_count    = (int)   cfq("SELECT COUNT(id) AS v FROM credit_orders WHERE order_date BETWEEN ? AND ? AND status IN ('shipped','delivered')", [$month_s,$month_e]);
$sales_pending  = (int)   cfq("SELECT COUNT(id) AS v FROM credit_orders WHERE status IN ('approved','in_production','produced','ready_to_ship')", []);
$sales_pending_val = (float) cfq("SELECT IFNULL(SUM(total_amount),0) AS v FROM credit_orders WHERE status IN ('approved','in_production','produced','ready_to_ship')", []);

// ── 30-day trend: cash inflow vs outflow ──────────────────────────────────────
$_in_rows  = cfql("SELECT payment_date AS d, IFNULL(SUM(amount),0) AS v FROM customer_payments WHERE payment_date BETWEEN ? AND ? GROUP BY payment_date", [$day30_s,$today]);
$_out_rows = cfql("SELECT payment_date AS d, IFNULL(SUM(amount_paid),0) AS v FROM purchase_payments_adnan WHERE payment_date BETWEEN ? AND ? GROUP BY payment_date", [$day30_s,$today]);
$_exp_rows = cfql("SELECT expense_date AS d, IFNULL(SUM(total_amount),0) AS v FROM expense_vouchers WHERE expense_date BETWEEN ? AND ? AND status='approved' GROUP BY expense_date", [$day30_s,$today]);

$_in_map = $_out_map = [];
foreach ($_in_rows  as $r) $_in_map[$r->d]   = (float)$r->v;
foreach ($_out_rows as $r) $_out_map[$r->d]   = (float)$r->v;
foreach ($_exp_rows as $r) $_out_map[$r->d]   = ($_out_map[$r->d] ?? 0) + (float)$r->v;

$trend_labels = $trend_in = $trend_out = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $trend_labels[] = date('d M', strtotime($d));
    $trend_in[]     = $_in_map[$d]  ?? 0;
    $trend_out[]    = $_out_map[$d] ?? 0;
}

// ── 6-month: raw materials (KG) vs sales value ───────────────────────────────
$_6m_labels = $rm_6m_qty = $sales_6m_val = [];
for ($i = 5; $i >= 0; $i--) {
    $ms = date('Y-m-01', strtotime("-{$i} months"));
    $me = date('Y-m-t',  strtotime("-{$i} months"));
    $_6m_labels[]  = date('M Y', strtotime($ms));
    $rm_6m_qty[]   = (float) cfq("SELECT IFNULL(SUM(quantity_received_kg),0) AS v FROM goods_received_adnan WHERE grn_date BETWEEN ? AND ? AND grn_status NOT IN ('cancelled')", [$ms,$me]);
    $sales_6m_val[] = (float) cfq("SELECT IFNULL(SUM(total_amount),0) AS v FROM credit_orders WHERE order_date BETWEEN ? AND ? AND status IN ('shipped','delivered')", [$ms,$me]);
}

// ── Recent activity ───────────────────────────────────────────────────────────
$recent_in  = cfql("SELECT cp.id, cp.payment_date, cp.payment_number, cp.amount, cp.payment_method, cp.payment_type, c.name AS customer_name FROM customer_payments cp JOIN customers c ON cp.customer_id=c.id ORDER BY cp.created_at DESC LIMIT 8");
$recent_out = cfql("SELECT id, payment_date, payment_voucher_number, amount_paid, payment_method, supplier_name FROM purchase_payments_adnan WHERE is_posted=1 ORDER BY created_at DESC LIMIT 8");
$recent_grn = cfql("SELECT id, grn_date, grn_number, quantity_received_kg, total_value, supplier_name FROM goods_received_adnan WHERE grn_status NOT IN ('cancelled') ORDER BY created_at DESC LIMIT 8");
$recent_dis = cfql("SELECT co.id, co.order_number, co.order_date, co.total_amount, co.status, c.name AS customer_name FROM credit_orders co JOIN customers c ON co.customer_id=c.id WHERE co.status IN ('shipped','delivered') ORDER BY co.updated_at DESC LIMIT 8");

require_once '../templates/header.php';
?>

<div class="w-full px-4 sm:px-6 py-6 space-y-6">

    <!-- ══ PAGE HEADER ════════════════════════════════════════════════════ -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-chart-line text-indigo-600"></i>
                Cash Flow &amp; Operations
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">
                <?php echo date('l, d F Y'); ?> &mdash; Real-time financial snapshot
            </p>
        </div>
        <div class="flex gap-2 text-xs">
            <a href="../cr/customer_payment.php"
               class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded-lg transition-colors font-medium">
                <i class="fas fa-plus-circle"></i> Collect Payment
            </a>
            <a href="../purchase/purchase_adnan_record_payment.php"
               class="inline-flex items-center gap-1.5 bg-orange-600 hover:bg-orange-700 text-white px-3 py-2 rounded-lg transition-colors font-medium">
                <i class="fas fa-money-bill-wave"></i> Record Payment
            </a>
            <a href="../expense/create_expense.php"
               class="inline-flex items-center gap-1.5 bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded-lg transition-colors font-medium">
                <i class="fas fa-receipt"></i> New Expense
            </a>
        </div>
    </div>

    <!-- ══ TOP KPI STRIP ══════════════════════════════════════════════════ -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">

        <!-- Net Cash Today -->
        <div class="bg-white rounded-xl shadow-sm border p-4 flex flex-col">
            <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Net Cash Today</p>
            <p class="text-2xl font-bold mt-1 <?php echo $net_today >= 0 ? 'text-emerald-600' : 'text-red-600'; ?>">
                ৳<?php echo number_format(abs($net_today), 0); ?>
            </p>
            <p class="text-xs text-gray-400 mt-1">
                <?php echo $net_today >= 0 ? 'Surplus' : 'Deficit'; ?>
                &bull; In: <span class="text-emerald-600 font-medium">৳<?php echo number_format($inflow_today, 0); ?></span>
                &bull; Out: <span class="text-red-500 font-medium">৳<?php echo number_format($outflow_today, 0); ?></span>
            </p>
        </div>

        <!-- Net Cash Month -->
        <div class="bg-white rounded-xl shadow-sm border p-4 flex flex-col">
            <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Net Cash This Month</p>
            <p class="text-2xl font-bold mt-1 <?php echo $net_month >= 0 ? 'text-emerald-600' : 'text-red-600'; ?>">
                ৳<?php echo number_format(abs($net_month), 0); ?>
            </p>
            <p class="text-xs mt-1 text-gray-400">
                <?php echo $net_month >= 0 ? 'Surplus' : 'Deficit'; ?>
                <?php echo badge_pct($net_month, $net_prev); ?>
            </p>
        </div>

        <!-- Total Inflow -->
        <div class="bg-white rounded-xl shadow-sm border p-4 flex flex-col">
            <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Cash Inflow (Month)</p>
            <p class="text-2xl font-bold mt-1 text-emerald-600">
                ৳<?php echo number_format($inflow_month, 0); ?>
            </p>
            <p class="text-xs mt-1 text-gray-400">
                <?php echo $inflow_count; ?> payments
                <?php echo badge_pct($inflow_month, $inflow_prev); ?>
            </p>
        </div>

        <!-- Total Outflow -->
        <div class="bg-white rounded-xl shadow-sm border p-4 flex flex-col">
            <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Cash Outflow (Month)</p>
            <p class="text-2xl font-bold mt-1 text-red-600">
                ৳<?php echo number_format($outflow_month, 0); ?>
            </p>
            <p class="text-xs mt-1 text-gray-400">
                <?php echo ($out_supplier_count + $out_expense_count); ?> transactions
                <?php echo badge_pct($outflow_month, $outflow_prev, true); ?>
            </p>
        </div>

        <!-- Receivables -->
        <div class="bg-white rounded-xl shadow-sm border p-4 flex flex-col">
            <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Outstanding Receivables</p>
            <p class="text-2xl font-bold mt-1 text-blue-600">
                ৳<?php echo number_format($receivables, 0); ?>
            </p>
            <p class="text-xs mt-1 text-gray-400">
                Supplier payables: <span class="text-orange-600 font-medium">৳<?php echo number_format($payables, 0); ?></span>
            </p>
        </div>
    </div>

    <!-- ══ INFLOW + OUTFLOW DETAIL CARDS ══════════════════════════════════ -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Cash Inflow Card -->
        <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
            <div class="bg-gradient-to-r from-emerald-500 to-emerald-600 px-5 py-3 flex items-center justify-between">
                <h3 class="text-white font-semibold flex items-center gap-2">
                    <i class="fas fa-arrow-circle-down"></i> Cash Inflow — <?php echo date('F Y'); ?>
                </h3>
                <a href="../cr/customer_payment.php"
                   class="text-emerald-100 hover:text-white text-xs underline">View All →</a>
            </div>
            <div class="p-5 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-emerald-50 rounded-lg p-3 text-center">
                        <p class="text-xs text-gray-500 mb-1">Today's Collections</p>
                        <p class="text-xl font-bold text-emerald-700">৳<?php echo number_format($inflow_today, 0); ?></p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-3 text-center">
                        <p class="text-xs text-gray-500 mb-1">Previous Month</p>
                        <p class="text-xl font-bold text-gray-700">৳<?php echo number_format($inflow_prev, 0); ?></p>
                    </div>
                </div>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between items-center py-1.5 border-b border-gray-100">
                        <span class="text-gray-600 flex items-center gap-2">
                            <i class="fas fa-check-circle text-emerald-400 w-4"></i> Allocated to Orders
                            <span class="text-[10px] text-gray-400">(applied against invoices)</span>
                        </span>
                        <span class="font-semibold text-gray-900">৳<?php echo number_format($inflow_allocated, 0); ?></span>
                    </div>
                    <div class="flex justify-between items-center py-1.5 border-b border-gray-100">
                        <span class="text-gray-600 flex items-center gap-2">
                            <i class="fas fa-hourglass-half text-purple-400 w-4"></i> Unallocated Advance
                            <span class="text-[10px] text-gray-400">(sitting as balance)</span>
                        </span>
                        <span class="font-semibold text-gray-900">৳<?php echo number_format($inflow_unallocated, 0); ?></span>
                    </div>
                    <div class="flex justify-between items-center py-1.5 border-b border-gray-100">
                        <span class="text-gray-600 flex items-center gap-2"><i class="fas fa-university text-blue-400 w-4"></i> Via Bank Transfer</span>
                        <span class="font-semibold text-gray-700">৳<?php echo number_format($inflow_bank, 0); ?></span>
                    </div>
                    <div class="flex justify-between items-center py-1.5">
                        <span class="text-gray-600 flex items-center gap-2"><i class="fas fa-money-bill text-green-400 w-4"></i> Via Cash
                            <span class="text-[10px] text-gray-400 ml-1"><?php echo $inflow_count; ?> total payments</span>
                        </span>
                        <span class="font-semibold text-gray-700">৳<?php echo number_format($inflow_cash, 0); ?></span>
                    </div>
                </div>
                <div class="flex gap-2 pt-2">
                    <a href="../cr/customer_payment.php"
                       class="flex-1 text-center text-xs bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded-lg transition-colors font-medium">
                        <i class="fas fa-plus mr-1"></i> Collect Payment
                    </a>
                    <a href="../cr/customer_ledger.php"
                       class="flex-1 text-center text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg transition-colors font-medium">
                        <i class="fas fa-book mr-1"></i> Customer Ledger
                    </a>
                    <a href="../cr/ageing_report.php"
                       class="flex-1 text-center text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 px-3 py-2 rounded-lg transition-colors font-medium">
                        <i class="fas fa-chart-bar mr-1"></i> Ageing
                    </a>
                </div>
            </div>
        </div>

        <!-- Cash Outflow Card -->
        <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
            <div class="bg-gradient-to-r from-red-500 to-red-600 px-5 py-3 flex items-center justify-between">
                <h3 class="text-white font-semibold flex items-center gap-2">
                    <i class="fas fa-arrow-circle-up"></i> Cash Outflow — <?php echo date('F Y'); ?>
                </h3>
                <a href="../purchase/payments.php"
                   class="text-red-100 hover:text-white text-xs underline">View All →</a>
            </div>
            <div class="p-5 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-red-50 rounded-lg p-3 text-center">
                        <p class="text-xs text-gray-500 mb-1">Today's Outflow</p>
                        <p class="text-xl font-bold text-red-700">৳<?php echo number_format($outflow_today, 0); ?></p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-3 text-center">
                        <p class="text-xs text-gray-500 mb-1">Previous Month</p>
                        <p class="text-xl font-bold text-gray-700">৳<?php echo number_format($outflow_prev, 0); ?></p>
                    </div>
                </div>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between items-center py-1.5 border-b border-gray-100">
                        <span class="text-gray-600 flex items-center gap-2"><i class="fas fa-truck-loading text-orange-400 w-4"></i> Supplier Payments</span>
                        <div class="text-right">
                            <span class="font-semibold text-gray-900">৳<?php echo number_format($out_supplier_month, 0); ?></span>
                            <span class="text-xs text-gray-400 ml-1">(<?php echo $out_supplier_count; ?>)</span>
                        </div>
                    </div>
                    <div class="flex justify-between items-center py-1.5 border-b border-gray-100">
                        <span class="text-gray-600 flex items-center gap-2"><i class="fas fa-receipt text-yellow-500 w-4"></i> Approved Expenses</span>
                        <div class="text-right">
                            <span class="font-semibold text-gray-900">৳<?php echo number_format($out_expense_month, 0); ?></span>
                            <span class="text-xs text-gray-400 ml-1">(<?php echo $out_expense_count; ?>)</span>
                        </div>
                    </div>
                    <div class="flex justify-between items-center py-1.5">
                        <span class="text-gray-600 flex items-center gap-2"><i class="fas fa-balance-scale text-red-400 w-4"></i> Supplier Payables</span>
                        <span class="font-semibold text-orange-600">৳<?php echo number_format($payables, 0); ?></span>
                    </div>
                </div>
                <div class="flex gap-2 pt-2">
                    <a href="../purchase/purchase_adnan_record_payment.php"
                       class="flex-1 text-center text-xs bg-orange-600 hover:bg-orange-700 text-white px-3 py-2 rounded-lg transition-colors font-medium">
                        <i class="fas fa-plus mr-1"></i> Record Payment
                    </a>
                    <a href="../expense/expense_voucher_list.php"
                       class="flex-1 text-center text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg transition-colors font-medium">
                        <i class="fas fa-list mr-1"></i> Expense Vouchers
                    </a>
                    <a href="../purchase/purchase_adnan_supplier_ledger.php"
                       class="flex-1 text-center text-xs bg-orange-50 hover:bg-orange-100 text-orange-700 px-3 py-2 rounded-lg transition-colors font-medium">
                        <i class="fas fa-book mr-1"></i> Supplier Ledger
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ 30-DAY TREND CHART ══════════════════════════════════════════════ -->
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fas fa-wave-square text-indigo-500"></i> 30-Day Cash Flow Trend
            </h3>
            <div class="flex gap-4 text-xs text-gray-500">
                <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-emerald-500 inline-block"></span> Inflow</span>
                <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-red-500 inline-block"></span> Outflow</span>
            </div>
        </div>
        <div style="height:220px;">
            <canvas id="trendChart"></canvas>
        </div>
    </div>

    <!-- ══ RAW MATERIALS + PRODUCT OUTFLOW CARDS ══════════════════════════ -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Raw Materials Inflow -->
        <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
            <div class="bg-gradient-to-r from-amber-500 to-amber-600 px-5 py-3 flex items-center justify-between">
                <h3 class="text-white font-semibold flex items-center gap-2">
                    <i class="fas fa-wheat-awn"></i> Raw Material Inflow (Wheat)
                </h3>
                <a href="../purchase/goods_received.php"
                   class="text-amber-100 hover:text-white text-xs underline">All GRNs →</a>
            </div>
            <div class="p-5 space-y-4">
                <div class="grid grid-cols-3 gap-3">
                    <div class="bg-amber-50 rounded-lg p-3 text-center">
                        <p class="text-[11px] text-gray-500 mb-1">Today (KG)</p>
                        <p class="text-lg font-bold text-amber-700"><?php echo number_format($rm_qty_today, 0); ?></p>
                    </div>
                    <div class="bg-amber-50 rounded-lg p-3 text-center">
                        <p class="text-[11px] text-gray-500 mb-1">Month (KG)</p>
                        <p class="text-lg font-bold text-amber-700"><?php echo number_format($rm_qty_month, 0); ?></p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-3 text-center">
                        <p class="text-[11px] text-gray-500 mb-1">Prev Month (KG)</p>
                        <p class="text-lg font-bold text-gray-600"><?php echo number_format($rm_qty_prev, 0); ?></p>
                    </div>
                </div>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between items-center py-1.5 border-b border-gray-100">
                        <span class="text-gray-600 flex items-center gap-2"><i class="fas fa-taka-sign text-amber-400 w-4"></i> Month Total Value</span>
                        <span class="font-semibold text-gray-900">৳<?php echo number_format($rm_value_month, 0); ?></span>
                    </div>
                    <div class="flex justify-between items-center py-1.5 border-b border-gray-100">
                        <span class="text-gray-600 flex items-center gap-2"><i class="fas fa-clipboard-check text-green-400 w-4"></i> GRNs This Month</span>
                        <span class="font-semibold text-gray-900"><?php echo $rm_grn_count; ?> receipts
                            <?php echo badge_pct($rm_qty_month, $rm_qty_prev); ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center py-1.5">
                        <span class="text-gray-600 flex items-center gap-2"><i class="fas fa-file-invoice text-orange-400 w-4"></i> Open Purchase Orders</span>
                        <div class="text-right">
                            <span class="font-semibold text-orange-600"><?php echo $open_po_count; ?> POs</span>
                            <p class="text-xs text-gray-400">৳<?php echo number_format($open_po_value, 0); ?> total</p>
                        </div>
                    </div>
                </div>
                <div class="flex gap-2 pt-2">
                    <a href="../purchase/purchase_adnan_record_grn.php"
                       class="flex-1 text-center text-xs bg-amber-600 hover:bg-amber-700 text-white px-3 py-2 rounded-lg transition-colors font-medium">
                        <i class="fas fa-plus mr-1"></i> Record GRN
                    </a>
                    <a href="../purchase/purchase_adnan_index.php"
                       class="flex-1 text-center text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg transition-colors font-medium">
                        <i class="fas fa-list mr-1"></i> All POs
                    </a>
                    <a href="../purchase/purchase_adnan_create_po.php"
                       class="flex-1 text-center text-xs bg-amber-50 hover:bg-amber-100 text-amber-700 px-3 py-2 rounded-lg transition-colors font-medium">
                        <i class="fas fa-plus-circle mr-1"></i> New PO
                    </a>
                </div>
            </div>
        </div>

        <!-- Product Outflow (Sales) -->
        <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
            <div class="bg-gradient-to-r from-blue-500 to-indigo-600 px-5 py-3 flex items-center justify-between">
                <h3 class="text-white font-semibold flex items-center gap-2">
                    <i class="fas fa-dolly"></i> Product Outflow (Sales &amp; Dispatch)
                </h3>
                <a href="../cr/all_sales.php"
                   class="text-blue-100 hover:text-white text-xs underline">All Sales →</a>
            </div>
            <div class="p-5 space-y-4">
                <div class="grid grid-cols-3 gap-3">
                    <div class="bg-blue-50 rounded-lg p-3 text-center">
                        <p class="text-[11px] text-gray-500 mb-1">Today (৳)</p>
                        <p class="text-lg font-bold text-blue-700"><?php echo number_format($sales_today, 0); ?></p>
                    </div>
                    <div class="bg-blue-50 rounded-lg p-3 text-center">
                        <p class="text-[11px] text-gray-500 mb-1">Month (৳)</p>
                        <p class="text-lg font-bold text-blue-700"><?php echo number_format($sales_month, 0); ?></p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-3 text-center">
                        <p class="text-[11px] text-gray-500 mb-1">Prev Month (৳)</p>
                        <p class="text-lg font-bold text-gray-600"><?php echo number_format($sales_prev, 0); ?></p>
                    </div>
                </div>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between items-center py-1.5 border-b border-gray-100">
                        <span class="text-gray-600 flex items-center gap-2"><i class="fas fa-shipping-fast text-blue-400 w-4"></i> Orders Dispatched</span>
                        <span class="font-semibold text-gray-900"><?php echo $sales_count; ?> orders
                            <?php echo badge_pct($sales_month, $sales_prev); ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center py-1.5 border-b border-gray-100">
                        <span class="text-gray-600 flex items-center gap-2"><i class="fas fa-clock text-yellow-500 w-4"></i> Pending Dispatch</span>
                        <div class="text-right">
                            <span class="font-semibold text-yellow-600"><?php echo $sales_pending; ?> orders</span>
                            <p class="text-xs text-gray-400">৳<?php echo number_format($sales_pending_val, 0); ?></p>
                        </div>
                    </div>
                    <div class="flex justify-between items-center py-1.5">
                        <span class="text-gray-600 flex items-center gap-2"><i class="fas fa-coins text-emerald-400 w-4"></i> Outstanding Receivables</span>
                        <span class="font-semibold text-emerald-700">৳<?php echo number_format($receivables, 0); ?></span>
                    </div>
                </div>
                <div class="flex gap-2 pt-2">
                    <a href="../cr/credit_dispatch.php"
                       class="flex-1 text-center text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg transition-colors font-medium">
                        <i class="fas fa-truck mr-1"></i> Dispatch
                    </a>
                    <a href="../cr/credit_order_approval.php"
                       class="flex-1 text-center text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg transition-colors font-medium">
                        <i class="fas fa-check-circle mr-1"></i> Approve Orders
                    </a>
                    <a href="../cr/create_order.php"
                       class="flex-1 text-center text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 px-3 py-2 rounded-lg transition-colors font-medium">
                        <i class="fas fa-plus-circle mr-1"></i> New Order
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ 6-MONTH BAR CHART ═══════════════════════════════════════════════ -->
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fas fa-chart-bar text-amber-500"></i> 6-Month: Raw Material Received (KG) vs Sales Value (৳)
            </h3>
            <div class="flex gap-4 text-xs text-gray-500">
                <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-amber-400 inline-block"></span> Wheat (KG)</span>
                <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-blue-500 inline-block"></span> Sales (৳)</span>
            </div>
        </div>
        <div style="height:220px;">
            <canvas id="sixMonthChart"></canvas>
        </div>
    </div>

    <!-- ══ RECENT ACTIVITY TABLES ═════════════════════════════════════════ -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Recent Cash In -->
        <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3 border-b bg-gray-50">
                <h4 class="font-semibold text-gray-800 text-sm flex items-center gap-2">
                    <i class="fas fa-arrow-down text-emerald-500"></i> Recent Payments Received
                </h4>
                <a href="../cr/customer_payment.php" class="text-xs text-primary-600 hover:underline">View All →</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-3 py-2 text-left text-gray-500 font-medium">Customer</th>
                            <th class="px-3 py-2 text-left text-gray-500 font-medium">Date</th>
                            <th class="px-3 py-2 text-right text-gray-500 font-medium">Amount</th>
                            <th class="px-3 py-2 text-left text-gray-500 font-medium">Method</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($recent_in as $r): ?>
                        <tr class="hover:bg-emerald-50 cursor-pointer transition-colors"
                            onclick="window.open('../cr/credit_payment_receipt.php?id=<?php echo (int)$r->id; ?>', '_blank')">
                            <td class="px-3 py-2 font-medium text-gray-800 max-w-[120px] truncate" title="<?php echo htmlspecialchars($r->customer_name); ?>">
                                <?php echo htmlspecialchars($r->customer_name); ?>
                            </td>
                            <td class="px-3 py-2 text-gray-500"><?php echo date('d M', strtotime($r->payment_date)); ?></td>
                            <td class="px-3 py-2 text-right font-bold text-emerald-700">৳<?php echo number_format($r->amount, 0); ?></td>
                            <td class="px-3 py-2">
                                <span class="px-1.5 py-0.5 rounded text-[10px] bg-blue-100 text-blue-700"><?php echo htmlspecialchars($r->payment_method); ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recent_in)): ?>
                        <tr><td colspan="4" class="px-3 py-6 text-center text-gray-400">No payments recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Cash Out (Supplier Payments) -->
        <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3 border-b bg-gray-50">
                <h4 class="font-semibold text-gray-800 text-sm flex items-center gap-2">
                    <i class="fas fa-arrow-up text-red-500"></i> Recent Supplier Payments
                </h4>
                <a href="../purchase/payments.php" class="text-xs text-primary-600 hover:underline">View All →</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-3 py-2 text-left text-gray-500 font-medium">Supplier</th>
                            <th class="px-3 py-2 text-left text-gray-500 font-medium">Date</th>
                            <th class="px-3 py-2 text-right text-gray-500 font-medium">Amount</th>
                            <th class="px-3 py-2 text-left text-gray-500 font-medium">Method</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($recent_out as $r): ?>
                        <tr class="hover:bg-red-50 cursor-pointer transition-colors"
                            onclick="window.location='../purchase/purchase_adnan_payment_receipt.php?id=<?php echo (int)$r->id; ?>'">
                            <td class="px-3 py-2 font-medium text-gray-800 max-w-[120px] truncate" title="<?php echo htmlspecialchars($r->supplier_name); ?>">
                                <?php echo htmlspecialchars($r->supplier_name); ?>
                            </td>
                            <td class="px-3 py-2 text-gray-500"><?php echo date('d M', strtotime($r->payment_date)); ?></td>
                            <td class="px-3 py-2 text-right font-bold text-red-600">৳<?php echo number_format($r->amount_paid, 0); ?></td>
                            <td class="px-3 py-2">
                                <span class="px-1.5 py-0.5 rounded text-[10px] bg-orange-100 text-orange-700"><?php echo htmlspecialchars($r->payment_method); ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recent_out)): ?>
                        <tr><td colspan="4" class="px-3 py-6 text-center text-gray-400">No supplier payments recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent GRNs -->
        <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3 border-b bg-gray-50">
                <h4 class="font-semibold text-gray-800 text-sm flex items-center gap-2">
                    <i class="fas fa-boxes text-amber-500"></i> Recent Wheat Receipts (GRNs)
                </h4>
                <a href="../purchase/goods_received.php" class="text-xs text-primary-600 hover:underline">View All →</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-3 py-2 text-left text-gray-500 font-medium">Supplier</th>
                            <th class="px-3 py-2 text-left text-gray-500 font-medium">Date</th>
                            <th class="px-3 py-2 text-right text-gray-500 font-medium">KG</th>
                            <th class="px-3 py-2 text-right text-gray-500 font-medium">Value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($recent_grn as $r): ?>
                        <tr class="hover:bg-amber-50 cursor-pointer transition-colors"
                            onclick="window.location='../purchase/purchase_adnan_grn_receipt.php?id=<?php echo (int)$r->id; ?>'">
                            <td class="px-3 py-2 font-medium text-gray-800 max-w-[120px] truncate" title="<?php echo htmlspecialchars($r->supplier_name); ?>">
                                <?php echo htmlspecialchars($r->supplier_name); ?>
                            </td>
                            <td class="px-3 py-2 text-gray-500"><?php echo date('d M', strtotime($r->grn_date)); ?></td>
                            <td class="px-3 py-2 text-right font-bold text-amber-700"><?php echo number_format($r->quantity_received_kg, 0); ?></td>
                            <td class="px-3 py-2 text-right text-gray-700">৳<?php echo number_format($r->total_value, 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recent_grn)): ?>
                        <tr><td colspan="4" class="px-3 py-6 text-center text-gray-400">No GRNs recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Dispatches -->
        <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3 border-b bg-gray-50">
                <h4 class="font-semibold text-gray-800 text-sm flex items-center gap-2">
                    <i class="fas fa-truck text-blue-500"></i> Recent Dispatches
                </h4>
                <a href="../cr/credit_dispatch.php" class="text-xs text-primary-600 hover:underline">Dispatch →</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-3 py-2 text-left text-gray-500 font-medium">Customer</th>
                            <th class="px-3 py-2 text-left text-gray-500 font-medium">Date</th>
                            <th class="px-3 py-2 text-right text-gray-500 font-medium">Value</th>
                            <th class="px-3 py-2 text-left text-gray-500 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php
                        $status_colors = [
                            'shipped'   => 'bg-blue-100 text-blue-700',
                            'delivered' => 'bg-emerald-100 text-emerald-700',
                        ];
                        foreach ($recent_dis as $r):
                            $sc = $status_colors[strtolower($r->status)] ?? 'bg-gray-100 text-gray-700';
                        ?>
                        <tr class="hover:bg-blue-50 cursor-pointer transition-colors"
                            onclick="window.location='../cr/credit_order_view.php?id=<?php echo (int)$r->id; ?>'">
                            <td class="px-3 py-2 font-medium text-gray-800 max-w-[120px] truncate" title="<?php echo htmlspecialchars($r->customer_name); ?>">
                                <?php echo htmlspecialchars($r->customer_name); ?>
                            </td>
                            <td class="px-3 py-2 text-gray-500"><?php echo date('d M', strtotime($r->order_date)); ?></td>
                            <td class="px-3 py-2 text-right font-bold text-blue-700">৳<?php echo number_format($r->total_amount, 0); ?></td>
                            <td class="px-3 py-2">
                                <span class="px-1.5 py-0.5 rounded text-[10px] <?php echo $sc; ?>"><?php echo ucfirst($r->status); ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recent_dis)): ?>
                        <tr><td colspan="4" class="px-3 py-6 text-center text-gray-400">No dispatches recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- /container -->

<script>
// ── 30-day trend line chart ────────────────────────────────────────────────────
(function() {
    const ctx = document.getElementById('trendChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($trend_labels); ?>,
            datasets: [
                {
                    label: 'Cash Inflow (৳)',
                    data: <?php echo json_encode($trend_in); ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,0.08)',
                    borderWidth: 2,
                    pointRadius: 2,
                    pointHoverRadius: 5,
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Cash Outflow (৳)',
                    data: <?php echo json_encode($trend_out); ?>,
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239,68,68,0.06)',
                    borderWidth: 2,
                    pointRadius: 2,
                    pointHoverRadius: 5,
                    fill: true,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.dataset.label + ': ৳' + ctx.parsed.y.toLocaleString('en-US', {minimumFractionDigits:0})
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 10 }, maxRotation: 45, color: '#9ca3af',
                             callback: function(val, idx) { return idx % 5 === 0 ? this.getLabelForValue(val) : ''; } }
                },
                y: {
                    grid: { color: '#f3f4f6' },
                    ticks: {
                        font: { size: 10 }, color: '#9ca3af',
                        callback: v => '৳' + (v >= 1000000 ? (v/1000000).toFixed(1)+'M' : v >= 1000 ? (v/1000).toFixed(0)+'K' : v)
                    }
                }
            }
        }
    });
})();

// ── 6-month bar chart ─────────────────────────────────────────────────────────
(function() {
    const ctx = document.getElementById('sixMonthChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($_6m_labels); ?>,
            datasets: [
                {
                    label: 'Wheat Received (KG)',
                    data: <?php echo json_encode($rm_6m_qty); ?>,
                    backgroundColor: 'rgba(245,158,11,0.75)',
                    borderColor: '#f59e0b',
                    borderWidth: 1,
                    yAxisID: 'y'
                },
                {
                    label: 'Sales Value (৳)',
                    data: <?php echo json_encode($sales_6m_val); ?>,
                    backgroundColor: 'rgba(59,130,246,0.7)',
                    borderColor: '#3b82f6',
                    borderWidth: 1,
                    yAxisID: 'y2'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            if (ctx.datasetIndex === 0)
                                return 'Wheat: ' + ctx.parsed.y.toLocaleString('en-US') + ' KG';
                            return 'Sales: ৳' + ctx.parsed.y.toLocaleString('en-US', {minimumFractionDigits:0});
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 11 }, color: '#6b7280' }
                },
                y: {
                    position: 'left',
                    grid: { color: '#f3f4f6' },
                    title: { display: true, text: 'KG', font: { size: 10 }, color: '#f59e0b' },
                    ticks: {
                        font: { size: 10 }, color: '#f59e0b',
                        callback: v => v >= 1000000 ? (v/1000000).toFixed(1)+'M' : v >= 1000 ? (v/1000).toFixed(0)+'K' : v
                    }
                },
                y2: {
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    title: { display: true, text: '৳', font: { size: 10 }, color: '#3b82f6' },
                    ticks: {
                        font: { size: 10 }, color: '#3b82f6',
                        callback: v => '৳' + (v >= 1000000 ? (v/1000000).toFixed(1)+'M' : v >= 1000 ? (v/1000).toFixed(0)+'K' : v)
                    }
                }
            }
        }
    });
})();
</script>

<?php require_once '../templates/footer.php'; ?>

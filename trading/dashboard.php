<?php
/**
 * Trading Dashboard — admin overview of the Commodity Trading module: a
 * filterable (date range / customer / commodity / origin) revenue/COGS/
 * margin summary, inventory value, negative-stock alerts, pending approvals,
 * top commodities, a combined recent-activity feed, and a full filtered
 * Sale History table.
 */
require_once '../core/init.php';

restrict_access(['Superadmin', 'admin'], 'trading', 'dashboard');

global $db;
$pageTitle = 'Trading Dashboard';

ensureCommodityInventoryTable();
ensureCommoditySalesTable();
ensureCommoditySalePaymentsTable();
ensureBusinessPartnersTable();

// ── Filters (GET, shareable/bookmarkable — mirrors margin_report.php) ──────
$month_start = date('Y-m-01');
$today       = date('Y-m-d');
$date_from = !empty($_GET['date_from']) ? $_GET['date_from'] : $month_start;
$date_to   = !empty($_GET['date_to'])   ? $_GET['date_to']   : $today;
$date_from = date('Y-m-d', strtotime($date_from));
$date_to   = date('Y-m-d', strtotime($date_to));
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];
$is_default_period = ($date_from === $month_start && $date_to === $today);

$f_customer_id  = isset($_GET['customer_id'])  && (int)$_GET['customer_id']  > 0 ? (int)$_GET['customer_id']  : null;
$f_commodity_id = isset($_GET['commodity_id']) && (int)$_GET['commodity_id'] > 0 ? (int)$_GET['commodity_id'] : null;
$f_origin       = isset($_GET['origin']) && $_GET['origin'] !== '' ? trim($_GET['origin']) : null;

$filter_sql = "cs.sale_date BETWEEN ? AND ?";
$filter_params = [$date_from, $date_to];
if ($f_customer_id)  { $filter_sql .= " AND cs.customer_id = ?";  $filter_params[] = $f_customer_id; }
if ($f_commodity_id) { $filter_sql .= " AND cs.commodity_id = ?"; $filter_params[] = $f_commodity_id; }
if ($f_origin !== null) { $filter_sql .= " AND cs.origin = ?"; $filter_params[] = $f_origin; }
$has_active_filter = !$is_default_period || $f_customer_id || $f_commodity_id || $f_origin !== null;

// ── Period KPIs (scoped to the filters above) ───────────────────────────
$mtd = $db->query(
    "SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS revenue, COALESCE(SUM(cogs_amount),0) AS cogs
     FROM commodity_sales cs WHERE {$filter_sql}", $filter_params
)->first();
$mtd_revenue = (float)($mtd->revenue ?? 0);
$mtd_cogs    = (float)($mtd->cogs ?? 0);
$mtd_margin  = $mtd_revenue - $mtd_cogs;
$mtd_count   = (int)($mtd->n ?? 0);

$collected_mtd = (float)($db->query(
    "SELECT COALESCE(SUM(csp.amount),0) AS t FROM commodity_sale_payments csp
     JOIN commodity_sales cs ON cs.id = csp.sale_id WHERE {$filter_sql}",
    $filter_params
)->first()->t ?? 0);

$inventory_value = (float)($db->query(
    "SELECT COALESCE(SUM(quantity_on_hand * weighted_avg_cost),0) AS v FROM commodity_inventory"
)->first()->v ?? 0);

$negative_stock_rows = $db->query(
    "SELECT ci.*, pc.name AS commodity_name, pc.unit, b.name AS branch_name
     FROM commodity_inventory ci
     JOIN purchase_commodities pc ON pc.id = ci.commodity_id
     JOIN branches b ON b.id = ci.branch_id
     WHERE ci.quantity_on_hand < 0 ORDER BY ci.quantity_on_hand ASC"
)->results();

$outstanding_total = (float)($db->query("SELECT COALESCE(SUM(balance_due),0) AS t FROM commodity_sales")->first()->t ?? 0);

$pending_count = (int)($db->query(
    "SELECT COUNT(*) AS c FROM cr_pending_requests WHERE status = 'pending' AND request_type IN ('commodity_sale','commodity_payment','commodity_sale_edit')"
)->first()->c ?? 0);

// business_partners itself has no customer_id/supplier_id columns — customers
// and suppliers each point AT it via their own business_partner_id. A partner
// is "linked" (both-sides) when at least one customer AND one supplier both
// reference the same business_partners.id — reuse the already-tested query.
$linked_partners = count(getLinkedBusinessPartners());

// ── Top commodities in the selected period, by margin ───────────────────
$top_commodities = $db->query(
    "SELECT pc.name, pc.unit, SUM(cs.quantity) AS qty, SUM(cs.total_amount) AS revenue, SUM(cs.cogs_amount) AS cogs
     FROM commodity_sales cs JOIN purchase_commodities pc ON pc.id = cs.commodity_id
     WHERE {$filter_sql}
     GROUP BY pc.id, pc.name, pc.unit ORDER BY (SUM(cs.total_amount) - SUM(cs.cogs_amount)) DESC LIMIT 6",
    $filter_params
)->results();

// ── Filtered Sale History (the actual ask: find trading sale history) ───
$sale_history = $db->query(
    "SELECT cs.id, cs.sale_number, cs.sale_date, cs.origin, cs.total_amount, cs.cogs_amount, cs.balance_due, cs.status,
            c.name AS customer_name, pc.name AS commodity_name, pc.unit
     FROM commodity_sales cs
     JOIN customers c ON c.id = cs.customer_id
     JOIN purchase_commodities pc ON pc.id = cs.commodity_id
     WHERE {$filter_sql}
     ORDER BY cs.sale_date DESC, cs.id DESC LIMIT 200",
    $filter_params
)->results();

$filter_customers  = $db->query("SELECT id, name, business_name, phone_number FROM customers WHERE status = 'active' ORDER BY name ASC")->results();
$filter_commodities = $db->query("SELECT id, name FROM purchase_commodities WHERE status = 'active' ORDER BY name ASC")->results();
$filter_origins_by_commodity = [];
foreach ($db->query("SELECT commodity_id, origin_name FROM purchase_commodity_origins WHERE status = 'active' ORDER BY origin_name ASC")->results() as $o) {
    $filter_origins_by_commodity[(int)$o->commodity_id][] = $o->origin_name;
}
$filter_customer_name = '';
if ($f_customer_id) {
    $fc = $db->query("SELECT name FROM customers WHERE id = ?", [$f_customer_id])->first();
    $filter_customer_name = $fc->name ?? '';
}

// ── Combined recent activity (sales + payments) ─────────────────────────
$recent_sales_feed = $db->query(
    "SELECT 'sale' AS kind, cs.id, cs.sale_number AS ref, cs.total_amount AS amount, cs.created_at, c.name AS customer_name
     FROM commodity_sales cs JOIN customers c ON c.id = cs.customer_id
     ORDER BY cs.created_at DESC LIMIT 8"
)->results();
$recent_payments_feed = $db->query(
    "SELECT 'payment' AS kind, csp.id, csp.payment_number AS ref, csp.amount AS amount, csp.created_at, c.name AS customer_name
     FROM commodity_sale_payments csp JOIN customers c ON c.id = csp.customer_id
     ORDER BY csp.created_at DESC LIMIT 8"
)->results();
$activity = array_merge($recent_sales_feed, $recent_payments_feed);
usort($activity, fn($a, $b) => strtotime($b->created_at) <=> strtotime($a->created_at));
$activity = array_slice($activity, 0, 10);

require_once '../templates/header.php';
?>
<div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <div class="mb-6 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-right-left text-rose-600 mr-2"></i>Trading Dashboard</h1>
            <p class="text-gray-600 mt-1 text-sm">
                Commodity Trading at a glance for <strong><?php echo date('d M Y', strtotime($date_from)); ?> – <?php echo date('d M Y', strtotime($date_to)); ?></strong><?php echo $has_active_filter ? ' (filtered)' : ''; ?> — numbers, stock health, and what needs attention.
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="commodity_sale.php" class="px-3 py-2 text-sm bg-rose-600 text-white rounded-lg hover:bg-rose-700"><i class="fas fa-money-bill-transfer mr-1"></i>Record Sale</a>
            <a href="margin_report.php" class="px-3 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"><i class="fas fa-chart-line mr-1"></i>Margin Report</a>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6 flex flex-wrap items-end gap-4">
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="px-3 py-2 border rounded-lg text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="px-3 py-2 border rounded-lg text-sm">
        </div>
        <div class="relative">
            <label class="block text-xs font-medium text-gray-600 mb-1">Customer</label>
            <input type="text" id="dbf_customer_search" autocomplete="off" placeholder="Search name, business, phone..."
                   value="<?php echo htmlspecialchars($filter_customer_name); ?>"
                   class="px-3 py-2 border rounded-lg text-sm w-56" oninput="dbfSearchCustomers(this.value)" onfocus="dbfSearchCustomers(this.value)">
            <div id="dbf_customer_dropdown" class="absolute z-50 left-0 right-0 mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-56 overflow-y-auto hidden"></div>
            <input type="hidden" name="customer_id" id="dbf_customer_id" value="<?php echo (int)($f_customer_id ?? 0); ?>">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Commodity</label>
            <select name="commodity_id" id="dbf_commodity" class="px-3 py-2 border rounded-lg text-sm" onchange="dbfCommodityChanged()">
                <option value="">All commodities</option>
                <?php foreach ($filter_commodities as $c): ?>
                <option value="<?php echo (int)$c->id; ?>" <?php echo $f_commodity_id === (int)$c->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($c->name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Origin</label>
            <select name="origin" id="dbf_origin" class="px-3 py-2 border rounded-lg text-sm">
                <option value="">All origins</option>
            </select>
        </div>
        <button type="submit" class="px-4 py-2 bg-rose-600 text-white rounded-lg text-sm font-semibold hover:bg-rose-700"><i class="fas fa-filter mr-1"></i>Apply</button>
        <?php if ($has_active_filter): ?><a href="dashboard.php" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Clear</a><?php endif; ?>
    </form>

    <?php if (!empty($negative_stock_rows)): ?>
    <div class="mb-6 rounded-lg border border-red-300 bg-red-50 px-4 py-3">
        <p class="text-sm font-semibold text-red-800"><i class="fas fa-triangle-exclamation mr-1"></i><?php echo count($negative_stock_rows); ?> commodity/branch combination(s) have gone NEGATIVE in stock (sold using the "sell anyway" override beyond what was on hand):</p>
        <ul class="mt-2 text-xs text-red-700 list-disc list-inside">
            <?php foreach ($negative_stock_rows as $ns): ?>
            <li><?php echo htmlspecialchars($ns->commodity_name . ' @ ' . $ns->branch_name); ?>: <strong><?php echo number_format((float)$ns->quantity_on_hand, 3); ?> <?php echo htmlspecialchars($ns->unit); ?></strong></li>
            <?php endforeach; ?>
        </ul>
        <a href="commodity_inventory.php" class="inline-block mt-2 text-xs font-semibold text-red-800 underline">Review in Commodity Inventory →</a>
    </div>
    <?php endif; ?>

    <?php if ($pending_count > 0): ?>
    <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 flex items-center justify-between">
        <p class="text-sm text-amber-800"><i class="fas fa-hourglass-half mr-1"></i><strong><?php echo $pending_count; ?></strong> commodity sale/payment request(s) waiting for approval.</p>
        <a href="../cr/approval_requests.php" class="text-xs font-semibold text-amber-800 underline">Review queue →</a>
    </div>
    <?php endif; ?>

    <!-- KPI tiles -->
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Period Revenue</p>
            <p class="text-xl font-bold text-blue-700 mt-1">৳<?php echo number_format($mtd_revenue, 0); ?></p>
            <p class="text-[11px] text-gray-400 mt-0.5"><?php echo $mtd_count; ?> sale(s)</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Period COGS</p>
            <p class="text-xl font-bold text-gray-700 mt-1">৳<?php echo number_format($mtd_cogs, 0); ?></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Period Margin</p>
            <p class="text-xl font-bold <?php echo $mtd_margin >= 0 ? 'text-green-700' : 'text-red-700'; ?> mt-1">৳<?php echo number_format($mtd_margin, 0); ?></p>
            <p class="text-[11px] text-gray-400 mt-0.5"><?php echo $mtd_revenue > 0 ? number_format(($mtd_margin / $mtd_revenue) * 100, 1) . '%' : '—'; ?></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Period Collected</p>
            <p class="text-xl font-bold text-emerald-700 mt-1">৳<?php echo number_format($collected_mtd, 0); ?></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Inventory Value</p>
            <p class="text-xl font-bold text-indigo-700 mt-1">৳<?php echo number_format($inventory_value, 0); ?></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Outstanding Due</p>
            <p class="text-xl font-bold text-amber-700 mt-1">৳<?php echo number_format($outstanding_total, 0); ?></p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Top commodities -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-700">Top Commodities in Period (by margin)</h2></div>
            <div class="overflow-x-auto">
            <?php if (!empty($top_commodities)): ?>
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 border-b"><tr>
                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Commodity</th>
                    <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">Qty Sold</th>
                    <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">Revenue</th>
                    <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">Margin</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($top_commodities as $tc): $m = (float)$tc->revenue - (float)$tc->cogs; ?>
                    <tr>
                        <td class="px-4 py-2 font-medium text-gray-800"><?php echo htmlspecialchars($tc->name); ?></td>
                        <td class="px-4 py-2 text-right"><?php echo number_format((float)$tc->qty, 3); ?> <?php echo htmlspecialchars($tc->unit); ?></td>
                        <td class="px-4 py-2 text-right">৳<?php echo number_format((float)$tc->revenue, 2); ?></td>
                        <td class="px-4 py-2 text-right font-semibold <?php echo $m >= 0 ? 'text-green-700' : 'text-red-700'; ?>">৳<?php echo number_format($m, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="p-8 text-center text-gray-500 text-sm">No sales this month yet.</div>
            <?php endif; ?>
            </div>
        </div>

        <!-- Quick links -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Quick Links</h2>
            <div class="space-y-2 text-sm">
                <a href="commodity_sale.php" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-50 text-gray-700"><i class="fas fa-money-bill-transfer w-4 text-rose-500"></i>Commodity Sale</a>
                <a href="commodity_dispatch.php" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-50 text-gray-700"><i class="fas fa-truck-fast w-4 text-rose-500"></i>Commodity Dispatch</a>
                <a href="commodity_inventory.php" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-50 text-gray-700"><i class="fas fa-warehouse w-4 text-rose-500"></i>Commodity Inventory</a>
                <a href="margin_report.php" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-50 text-gray-700"><i class="fas fa-chart-line w-4 text-rose-500"></i>Margin Report</a>
                <a href="business_partners.php" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-50 text-gray-700"><i class="fas fa-handshake w-4 text-rose-500"></i>Business Partners <span class="ml-auto text-xs text-gray-400"><?php echo $linked_partners; ?> linked</span></a>
                <a href="partner_settlement.php" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-50 text-gray-700"><i class="fas fa-scale-balanced w-4 text-rose-500"></i>Partner Settlement</a>
                <a href="../cr/approval_requests.php" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-50 text-gray-700"><i class="fas fa-user-check w-4 text-rose-500"></i>Approval Requests <?php if ($pending_count > 0): ?><span class="ml-auto text-xs bg-amber-100 text-amber-800 px-1.5 rounded-full font-bold"><?php echo $pending_count; ?></span><?php endif; ?></a>
                <a href="../admin/recycle_bin.php" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-50 text-gray-700"><i class="fas fa-trash-restore w-4 text-rose-500"></i>Recycle Bin</a>
            </div>
        </div>
    </div>

    <!-- Recent activity -->
    <div class="mt-6 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-700">Recent Activity</h2></div>
        <div class="overflow-x-auto">
        <?php if (!empty($activity)): ?>
        <table class="min-w-full text-xs">
            <thead class="bg-gray-50 border-b"><tr>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Type</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Reference</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Customer</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase text-gray-500">Amount</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">When</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($activity as $a): ?>
                <tr>
                    <td class="px-3 py-2">
                        <?php if ($a->kind === 'sale'): ?>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-700">SALE</span>
                        <?php else: ?>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700">PAYMENT</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-2 font-mono text-gray-600"><?php echo htmlspecialchars($a->ref); ?></td>
                    <td class="px-3 py-2"><?php echo htmlspecialchars($a->customer_name); ?></td>
                    <td class="px-3 py-2 text-right font-semibold">৳<?php echo number_format((float)$a->amount, 2); ?></td>
                    <td class="px-3 py-2 text-gray-400"><?php echo date('d M Y, g:i A', strtotime($a->created_at)); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-8 text-center text-gray-500 text-xs">No activity yet.</div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Sale History (filtered) -->
    <div class="mt-6 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-700">Sale History<?php echo $has_active_filter ? ' (filtered)' : ''; ?></h2>
            <span class="text-xs text-gray-400"><?php echo count($sale_history); ?> sale(s)<?php echo count($sale_history) >= 200 ? ' (showing latest 200)' : ''; ?></span>
        </div>
        <div class="overflow-x-auto">
        <?php if (!empty($sale_history)): ?>
        <table class="min-w-full text-xs">
            <thead class="bg-gray-50 border-b"><tr>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Sale #</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Date</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Customer</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Commodity</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase text-gray-500">Total</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase text-gray-500">Margin</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase text-gray-500">Balance Due</th>
                <th class="px-3 py-2 text-center text-[10px] font-semibold uppercase text-gray-500">Status</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($sale_history as $s): $sh_margin = (float)$s->total_amount - (float)$s->cogs_amount; ?>
                <tr>
                    <td class="px-3 py-2 font-mono"><a href="view_commodity_sale.php?id=<?php echo (int)$s->id; ?>" class="text-rose-600 hover:underline"><?php echo htmlspecialchars($s->sale_number); ?></a></td>
                    <td class="px-3 py-2 text-gray-500"><?php echo date('d M Y', strtotime($s->sale_date)); ?></td>
                    <td class="px-3 py-2"><?php echo htmlspecialchars($s->customer_name); ?></td>
                    <td class="px-3 py-2"><?php echo htmlspecialchars($s->commodity_name); ?><?php if (!empty($s->origin)): ?> <span class="text-gray-400 text-[10px]">(<?php echo htmlspecialchars($s->origin); ?>)</span><?php endif; ?></td>
                    <td class="px-3 py-2 text-right font-semibold">৳<?php echo number_format((float)$s->total_amount, 2); ?></td>
                    <td class="px-3 py-2 text-right <?php echo $sh_margin >= 0 ? 'text-green-700' : 'text-red-700'; ?>">৳<?php echo number_format($sh_margin, 2); ?></td>
                    <td class="px-3 py-2 text-right <?php echo (float)$s->balance_due > 0.01 ? 'text-amber-700 font-semibold' : 'text-gray-400'; ?>">৳<?php echo number_format((float)$s->balance_due, 2); ?></td>
                    <td class="px-3 py-2 text-center"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo $s->status === 'approved' ? 'bg-green-100 text-green-700' : ($s->status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'); ?>"><?php echo strtoupper($s->status); ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-8 text-center text-gray-500 text-xs">No commodity sales match this filter.</div>
        <?php endif; ?>
        </div>
    </div>

</div>
<script>
const dbfCustomers = <?php echo json_encode(array_map(function($c) {
    return ['id' => $c->id, 'name' => $c->name, 'business' => $c->business_name ?? '', 'phone' => $c->phone_number ?? ''];
}, $filter_customers)); ?>;
const dbfOriginsByCommodity = <?php echo json_encode($filter_origins_by_commodity); ?>;
const dbfCurrentOrigin = <?php echo json_encode($f_origin ?? ''); ?>;

function dbfSearchCustomers(query) {
    const dd = document.getElementById('dbf_customer_dropdown');
    const q = query.toLowerCase().trim();
    if (q.length === 0) { dd.classList.add('hidden'); document.getElementById('dbf_customer_id').value = ''; return; }
    const matches = dbfCustomers.filter(c =>
        c.name.toLowerCase().includes(q) || c.business.toLowerCase().includes(q) || c.phone.includes(q)
    ).slice(0, 20);
    dd.innerHTML = matches.length === 0 ? '<div class="px-4 py-2 text-sm text-gray-500">No customers found</div>' :
        matches.map(c => `<div class="px-4 py-2 hover:bg-rose-50 cursor-pointer text-sm border-b border-gray-100" onclick="dbfSelectCustomer(${c.id})">
            <span class="font-medium text-gray-900">${c.name}</span>${c.business ? `<span class="text-gray-400 text-xs ml-1">(${c.business})</span>` : ''}
            <span class="text-gray-400 text-xs ml-2">${c.phone}</span></div>`).join('');
    dd.classList.remove('hidden');
}
function dbfSelectCustomer(id) {
    const c = dbfCustomers.find(x => x.id === id);
    if (!c) return;
    document.getElementById('dbf_customer_id').value = c.id;
    document.getElementById('dbf_customer_search').value = c.name;
    document.getElementById('dbf_customer_dropdown').classList.add('hidden');
}
document.addEventListener('click', e => {
    if (!e.target.closest('#dbf_customer_search') && !e.target.closest('#dbf_customer_dropdown')) {
        document.getElementById('dbf_customer_dropdown').classList.add('hidden');
    }
});
function dbfCommodityChanged() {
    const commodityId = parseInt(document.getElementById('dbf_commodity').value) || 0;
    const originSel = document.getElementById('dbf_origin');
    const origins = dbfOriginsByCommodity[commodityId] || [];
    originSel.innerHTML = '<option value="">All origins</option>' + origins.map(o => `<option value="${o}">${o}</option>`).join('');
    if (origins.includes(dbfCurrentOrigin)) originSel.value = dbfCurrentOrigin;
}
document.addEventListener('DOMContentLoaded', dbfCommodityChanged);
</script>
<?php require_once '../templates/footer.php'; ?>

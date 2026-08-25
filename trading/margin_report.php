<?php
/**
 * Commodity Margin Report — per-commodity quantity sold / revenue / COGS / margin
 * over a date range, so the trading side's real profitability is visible (COGS
 * is weighted-average cost locked in at time of sale, not an estimate).
 */
require_once '../core/init.php';

restrict_access(['Superadmin', 'admin'], 'trading', 'margin_report');

global $db;
$pageTitle = 'Commodity Margin Report';

$date_from = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to   = !empty($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');
$date_from = date('Y-m-d', strtotime($date_from));
$date_to   = date('Y-m-d', strtotime($date_to));
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];

$filter_commodity = isset($_GET['commodity_id']) && (int)$_GET['commodity_id'] > 0 ? (int)$_GET['commodity_id'] : null;
$commodity_sql = $filter_commodity ? "AND cs.commodity_id = ?" : "";
$base_params   = $filter_commodity ? [$date_from, $date_to, $filter_commodity] : [$date_from, $date_to];

// ── CSV export ──────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $db->query(
        "SELECT cs.sale_number, cs.sale_date, c.name AS customer_name, pc.name AS commodity_name, pc.unit,
                cs.quantity, cs.unit_price, cs.total_amount, cs.cogs_amount, cs.stock_overridden
         FROM commodity_sales cs
         JOIN customers c ON c.id = cs.customer_id
         JOIN purchase_commodities pc ON pc.id = cs.commodity_id
         WHERE cs.sale_date BETWEEN ? AND ? $commodity_sql
         ORDER BY cs.sale_date ASC, cs.id ASC", $base_params
    )->results();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="commodity_margin_' . $date_from . '_to_' . $date_to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Sale #','Date','Customer','Commodity','Qty','Unit','Unit Price (৳)','Revenue (৳)','COGS (৳)','Margin (৳)','Margin %','Stock Override']);
    foreach ($rows as $r) {
        $margin = (float)$r->total_amount - (float)$r->cogs_amount;
        $pct = (float)$r->total_amount > 0 ? ($margin / (float)$r->total_amount) * 100 : 0;
        fputcsv($out, [
            $r->sale_number, $r->sale_date, $r->customer_name, $r->commodity_name,
            $r->quantity, $r->unit, number_format((float)$r->unit_price, 4, '.', ''),
            number_format((float)$r->total_amount, 2, '.', ''), number_format((float)$r->cogs_amount, 2, '.', ''),
            number_format($margin, 2, '.', ''), number_format($pct, 2, '.', ''),
            $r->stock_overridden ? 'Yes' : 'No',
        ]);
    }
    fclose($out);
    exit();
}

$commodities = $db->query("SELECT id, name FROM purchase_commodities WHERE is_sellable = 1 ORDER BY name ASC")->results();

// ── Per-commodity summary ──────────────────────────────────────────────
$summary = $db->query(
    "SELECT pc.id AS commodity_id, pc.name AS commodity_name, pc.unit,
            SUM(cs.quantity) AS qty_sold, SUM(cs.total_amount) AS revenue, SUM(cs.cogs_amount) AS cogs,
            COUNT(*) AS sale_count
     FROM commodity_sales cs
     JOIN purchase_commodities pc ON pc.id = cs.commodity_id
     WHERE cs.sale_date BETWEEN ? AND ? $commodity_sql
     GROUP BY pc.id, pc.name, pc.unit
     ORDER BY revenue DESC", $base_params
)->results();

$grand_revenue = 0; $grand_cogs = 0; $grand_qty_count = 0;
foreach ($summary as $s) { $grand_revenue += (float)$s->revenue; $grand_cogs += (float)$s->cogs; $grand_qty_count += (int)$s->sale_count; }
$grand_margin = $grand_revenue - $grand_cogs;

// ── Detail rows ─────────────────────────────────────────────────────────
$detail = $db->query(
    "SELECT cs.*, c.name AS customer_name, pc.name AS commodity_name, pc.unit
     FROM commodity_sales cs
     JOIN customers c ON c.id = cs.customer_id
     JOIN purchase_commodities pc ON pc.id = cs.commodity_id
     WHERE cs.sale_date BETWEEN ? AND ? $commodity_sql
     ORDER BY cs.sale_date DESC, cs.id DESC", $base_params
)->results();

require_once '../templates/header.php';
?>
<div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-chart-line text-rose-600 mr-2"></i>Commodity Margin Report</h1>
            <p class="text-gray-600 mt-1 text-sm">Revenue, real weighted-average COGS, and margin per commodity for the selected period.</p>
        </div>
        <a href="commodity_sale.php" class="px-3 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"><i class="fas fa-money-bill-transfer mr-1"></i>Record Sale</a>
    </div>

    <form method="GET" class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6 flex flex-wrap items-end gap-4">
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="px-3 py-2 border rounded-lg text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="px-3 py-2 border rounded-lg text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Commodity</label>
            <select name="commodity_id" class="px-3 py-2 border rounded-lg text-sm">
                <option value="">All commodities</option>
                <?php foreach ($commodities as $c): ?>
                <option value="<?php echo (int)$c->id; ?>" <?php echo $filter_commodity === (int)$c->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($c->name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="px-4 py-2 bg-rose-600 text-white rounded-lg text-sm font-semibold hover:bg-rose-700"><i class="fas fa-filter mr-1"></i>Apply</button>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50"><i class="fas fa-download mr-1"></i>Export CSV</a>
    </form>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
            <span class="text-sm text-gray-500">Total Revenue</span>
            <div class="text-2xl font-bold text-blue-700">৳<?php echo number_format($grand_revenue, 2); ?></div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
            <span class="text-sm text-gray-500">Total COGS</span>
            <div class="text-2xl font-bold text-gray-700">৳<?php echo number_format($grand_cogs, 2); ?></div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
            <span class="text-sm text-gray-500">Total Margin<?php echo $grand_revenue > 0 ? ' (' . number_format(($grand_margin / $grand_revenue) * 100, 1) . '%)' : ''; ?></span>
            <div class="text-2xl font-bold <?php echo $grand_margin >= 0 ? 'text-green-700' : 'text-red-700'; ?>">৳<?php echo number_format($grand_margin, 2); ?></div>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-700">By Commodity</h2></div>
        <div class="overflow-x-auto">
        <?php if (!empty($summary)): ?>
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b"><tr>
                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Commodity</th>
                <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">Sales</th>
                <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">Qty Sold</th>
                <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">Revenue</th>
                <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">COGS</th>
                <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">Margin</th>
                <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">Margin %</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($summary as $s): $margin = (float)$s->revenue - (float)$s->cogs; $pct = (float)$s->revenue > 0 ? ($margin / (float)$s->revenue) * 100 : 0; ?>
                <tr>
                    <td class="px-4 py-2 font-medium text-gray-800"><?php echo htmlspecialchars($s->commodity_name); ?></td>
                    <td class="px-4 py-2 text-right"><?php echo (int)$s->sale_count; ?></td>
                    <td class="px-4 py-2 text-right"><?php echo number_format((float)$s->qty_sold, 3); ?> <?php echo htmlspecialchars($s->unit); ?></td>
                    <td class="px-4 py-2 text-right">৳<?php echo number_format((float)$s->revenue, 2); ?></td>
                    <td class="px-4 py-2 text-right">৳<?php echo number_format((float)$s->cogs, 2); ?></td>
                    <td class="px-4 py-2 text-right font-semibold <?php echo $margin >= 0 ? 'text-green-700' : 'text-red-700'; ?>">৳<?php echo number_format($margin, 2); ?></td>
                    <td class="px-4 py-2 text-right <?php echo $margin >= 0 ? 'text-green-700' : 'text-red-700'; ?>"><?php echo number_format($pct, 1); ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-8 text-center text-gray-500 text-sm">No commodity sales in this period.</div>
        <?php endif; ?>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-700">Sale-by-Sale Detail</h2></div>
        <div class="overflow-x-auto">
        <?php if (!empty($detail)): ?>
        <table class="min-w-full text-xs">
            <thead class="bg-gray-50 border-b"><tr>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Sale #</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Date</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Customer</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Commodity</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase text-gray-500">Qty</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase text-gray-500">Revenue</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase text-gray-500">COGS</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase text-gray-500">Margin</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($detail as $d): $margin = (float)$d->total_amount - (float)$d->cogs_amount; ?>
                <tr>
                    <td class="px-3 py-2 font-mono text-rose-600"><?php echo htmlspecialchars($d->sale_number); ?><?php if ($d->stock_overridden): ?> <i class="fas fa-triangle-exclamation text-amber-500" title="Stock override used"></i><?php endif; ?></td>
                    <td class="px-3 py-2 text-gray-500"><?php echo date('d M Y', strtotime($d->sale_date)); ?></td>
                    <td class="px-3 py-2"><?php echo htmlspecialchars($d->customer_name); ?></td>
                    <td class="px-3 py-2"><?php echo htmlspecialchars($d->commodity_name); ?><?php if (!empty($d->origin)): ?> <span class="text-gray-400 text-[10px]">(<?php echo htmlspecialchars($d->origin); ?>)</span><?php endif; ?></td>
                    <td class="px-3 py-2 text-right"><?php echo number_format((float)$d->quantity, 3); ?> <?php echo htmlspecialchars($d->unit); ?></td>
                    <td class="px-3 py-2 text-right">৳<?php echo number_format((float)$d->total_amount, 2); ?></td>
                    <td class="px-3 py-2 text-right">৳<?php echo number_format((float)$d->cogs_amount, 2); ?></td>
                    <td class="px-3 py-2 text-right font-semibold <?php echo $margin >= 0 ? 'text-green-700' : 'text-red-700'; ?>">৳<?php echo number_format($margin, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-8 text-center text-gray-500 text-xs">No commodity sales in this period.</div>
        <?php endif; ?>
        </div>
    </div>

</div>
<?php require_once '../templates/footer.php'; ?>

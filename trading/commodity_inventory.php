<?php
/**
 * Commodity Inventory — read-only view of on-hand stock and weighted-average
 * cost per commodity × branch, fed by GRN receipts (purchase_adnan_record_grn.php)
 * and drawn down by Commodity Sales. Lets you verify the costing engine and see
 * how much capital is tied up in commodity stock.
 */
require_once '../core/init.php';

restrict_access(['Superadmin', 'admin'], 'trading', 'commodity_inventory');

global $db;
$pageTitle = 'Commodity Inventory';

ensureCommodityInventoryTable();

$rows = $db->query(
    "SELECT ci.*, pc.name AS commodity_name, pc.unit, b.name AS branch_name
     FROM commodity_inventory ci
     JOIN purchase_commodities pc ON pc.id = ci.commodity_id
     JOIN branches b ON b.id = ci.branch_id
     ORDER BY pc.name ASC, b.name ASC"
)->results();

$total_value = 0;
foreach ($rows as $r) { $total_value += (float)$r->quantity_on_hand * (float)$r->weighted_avg_cost; }

require_once '../templates/header.php';
?>
<div class="max-w-screen-lg mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-warehouse text-rose-600 mr-2"></i>Commodity Inventory</h1>
            <p class="text-gray-600 mt-1 text-sm">On-hand stock and weighted-average cost per commodity — updated automatically by GRN receipts and commodity sales.</p>
        </div>
        <a href="commodity_sale.php" class="px-3 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"><i class="fas fa-money-bill-transfer mr-1"></i>Record Sale</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 mb-6">
        <span class="text-sm text-gray-500">Total inventory value on hand (qty × weighted-avg cost)</span>
        <div class="text-3xl font-bold text-blue-700">৳<?php echo number_format($total_value, 2); ?></div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
        <?php if (!empty($rows)): ?>
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b"><tr>
                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Commodity</th>
                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Origin</th>
                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Branch / Warehouse / Dock</th>
                <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">On Hand</th>
                <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">Weighted Avg Cost</th>
                <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">Value</th>
                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Updated</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($rows as $r): $qty = (float)$r->quantity_on_hand; $value = $qty * (float)$r->weighted_avg_cost; ?>
                <tr class="<?php echo $qty < 0 ? 'bg-red-50' : ''; ?>">
                    <td class="px-4 py-2 font-medium text-gray-800"><?php echo htmlspecialchars($r->commodity_name); ?></td>
                    <td class="px-4 py-2 text-gray-600"><?php echo $r->origin !== '' ? htmlspecialchars($r->origin) : '<span class="text-gray-300">—</span>'; ?></td>
                    <td class="px-4 py-2 text-gray-600"><?php echo htmlspecialchars($r->branch_name); ?></td>
                    <td class="px-4 py-2 text-right <?php echo $qty < 0 ? 'text-red-600 font-semibold' : ''; ?>"><?php echo number_format($qty, 3); ?> <?php echo htmlspecialchars($r->unit); ?></td>
                    <td class="px-4 py-2 text-right">৳<?php echo number_format((float)$r->weighted_avg_cost, 4); ?></td>
                    <td class="px-4 py-2 text-right font-semibold">৳<?php echo number_format($value, 2); ?></td>
                    <td class="px-4 py-2 text-gray-400 text-xs"><?php echo date('d M Y, H:i', strtotime($r->updated_at)); ?></td>
                </tr>
                <?php if ($qty < 0): ?>
                <tr class="bg-red-50"><td colspan="7" class="px-4 pb-2 -mt-1 text-xs text-red-600"><i class="fas fa-triangle-exclamation mr-1"></i>Negative stock — sales here used the "sell anyway" override beyond what was on hand.</td></tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-8 text-center text-gray-500 text-sm">No commodity inventory yet — receive a commodity-tagged GRN in Purchase to get started.</div>
        <?php endif; ?>
        </div>
    </div>

</div>
<?php require_once '../templates/footer.php'; ?>

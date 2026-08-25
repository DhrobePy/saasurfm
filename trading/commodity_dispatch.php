<?php
/**
 * Commodity Dispatch Board — lists posted commodity sales not yet delivered,
 * with links to print the Invoice and Gate Pass (the gate pass carries the
 * signed QR that drives the two-stage gate-release/delivery-confirm flow at
 * commodity_verify_delivery.php — mirrors cr/credit_dispatch.php's model).
 */
require_once '../core/init.php';

restrict_access(['Superadmin', 'admin'], 'trading', 'commodity_dispatch');

global $db;
$pageTitle = 'Commodity Dispatch';

ensureCommodityDispatchConfirmTable();

$rows = $db->query(
    "SELECT cs.id, cs.sale_number, cs.sale_date, cs.quantity, cs.total_amount,
            c.name AS customer_name, pc.name AS commodity_name, pc.unit, b.name AS branch_name,
            cdc.gate_out_at, cdc.gate_out_by_name, cdc.driver_name, cdc.vehicle_number,
            cdc.confirmed_at, cdc.confirmed_by_name
     FROM commodity_sales cs
     JOIN customers c ON c.id = cs.customer_id
     JOIN purchase_commodities pc ON pc.id = cs.commodity_id
     JOIN branches b ON b.id = cs.branch_id
     LEFT JOIN commodity_dispatch_confirmations cdc ON cdc.sale_id = cs.id
     WHERE cs.status = 'approved' AND (cdc.confirmed_at IS NULL)
     ORDER BY cs.created_at DESC"
)->results();

$delivered_recent = $db->query(
    "SELECT cs.id, cs.sale_number, c.name AS customer_name, cdc.confirmed_at, cdc.confirmed_by_name
     FROM commodity_dispatch_confirmations cdc
     JOIN commodity_sales cs ON cs.id = cdc.sale_id
     JOIN customers c ON c.id = cs.customer_id
     WHERE cdc.confirmed_at IS NOT NULL ORDER BY cdc.confirmed_at DESC LIMIT 15"
)->results();

require_once '../templates/header.php';
?>
<div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-truck-fast text-rose-600 mr-2"></i>Commodity Dispatch</h1>
            <p class="text-gray-600 mt-1 text-sm">Print the invoice and gate pass for each sale. Scanning the gate pass QR releases the goods at the gate, then confirms delivery at the customer — same two-stage flow as Credit Sales.</p>
        </div>
        <a href="commodity_sale.php" class="px-3 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"><i class="fas fa-money-bill-transfer mr-1"></i>Record Sale</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-700">Awaiting Delivery</h2></div>
        <div class="overflow-x-auto">
        <?php if (!empty($rows)): ?>
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b"><tr>
                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Sale #</th>
                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Customer</th>
                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Commodity</th>
                <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">Qty</th>
                <th class="px-4 py-2 text-center text-xs font-semibold uppercase text-gray-500">Status</th>
                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Driver / Vehicle</th>
                <th class="px-4 py-2 text-center text-xs font-semibold uppercase text-gray-500">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($rows as $r):
                    $gate_done = !empty($r->gate_out_at);
                    $sig = commodityDeliveryQrSignature($r->sale_number);
                    $verify_url = 'commodity_verify_delivery.php?inv=' . urlencode($r->sale_number) . '&sig=' . $sig;
                ?>
                <tr>
                    <td class="px-4 py-2 font-mono"><a href="view_commodity_sale.php?id=<?php echo (int)$r->id; ?>" class="text-rose-600 hover:underline"><?php echo htmlspecialchars($r->sale_number); ?></a></td>
                    <td class="px-4 py-2"><?php echo htmlspecialchars($r->customer_name); ?></td>
                    <td class="px-4 py-2"><?php echo htmlspecialchars($r->commodity_name); ?></td>
                    <td class="px-4 py-2 text-right"><?php echo number_format((float)$r->quantity, 3); ?> <?php echo htmlspecialchars($r->unit); ?></td>
                    <td class="px-4 py-2 text-center">
                        <?php if ($gate_done): ?>
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-blue-100 text-blue-700">In Transit</span>
                        <?php else: ?>
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-500">Not Dispatched</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-2 text-xs text-gray-500"><?php echo $gate_done ? htmlspecialchars(trim(($r->driver_name ?? '') . ' / ' . ($r->vehicle_number ?? ''))) : '—'; ?></td>
                    <td class="px-4 py-2 text-center whitespace-nowrap">
                        <a href="commodity_invoice.php?id=<?php echo (int)$r->id; ?>" target="_blank" class="px-2 py-1 text-xs border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"><i class="fas fa-file-invoice mr-1"></i>Invoice</a>
                        <a href="commodity_gate_pass.php?id=<?php echo (int)$r->id; ?>" target="_blank" class="px-2 py-1 text-xs border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"><i class="fas fa-qrcode mr-1"></i>Gate Pass</a>
                        <a href="<?php echo htmlspecialchars($verify_url); ?>" class="px-2 py-1 text-xs bg-rose-50 border border-rose-200 rounded-md text-rose-700 hover:bg-rose-100"><i class="fas fa-door-open mr-1"></i><?php echo $gate_done ? 'Confirm Delivery' : 'Gate Release'; ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-8 text-center text-gray-500 text-sm">Nothing awaiting dispatch — every posted sale has been delivered.</div>
        <?php endif; ?>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-700">Recently Delivered</h2></div>
        <div class="overflow-x-auto">
        <?php if (!empty($delivered_recent)): ?>
        <table class="min-w-full text-xs">
            <thead class="bg-gray-50 border-b"><tr>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Sale #</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Customer</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Delivered</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Confirmed By</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($delivered_recent as $d): ?>
                <tr>
                    <td class="px-3 py-2 font-mono text-rose-600"><a href="view_commodity_sale.php?id=<?php echo (int)$d->id; ?>" class="hover:underline"><?php echo htmlspecialchars($d->sale_number); ?></a></td>
                    <td class="px-3 py-2"><?php echo htmlspecialchars($d->customer_name); ?></td>
                    <td class="px-3 py-2 text-gray-500"><?php echo date('d M Y, g:i A', strtotime($d->confirmed_at)); ?></td>
                    <td class="px-3 py-2 text-gray-500"><?php echo htmlspecialchars($d->confirmed_by_name ?? '—'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-6 text-center text-gray-500 text-xs">No deliveries confirmed yet.</div>
        <?php endif; ?>
        </div>
    </div>

</div>
<?php require_once '../templates/footer.php'; ?>

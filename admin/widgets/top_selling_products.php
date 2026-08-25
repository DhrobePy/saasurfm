<?php
// Widget: top_selling_products — Best selling product variants by revenue this month
$products = [];
try {
    $month_start = date('Y-m-01 00:00:00');
    $month_end   = date('Y-m-t 23:59:59');
    $products = $db->query(
        "SELECT p.base_name, pv.grade, pv.weight_variant, pv.sku,
                SUM(oi.quantity) AS total_qty, SUM(oi.line_total) AS total_revenue
         FROM credit_order_items oi
         JOIN product_variants pv ON oi.variant_id = pv.id
         JOIN products p ON pv.product_id = p.id
         JOIN credit_orders co ON oi.order_id = co.id
         WHERE co.status IN ('Shipped','Dispatched','Delivered')
           AND co.order_date BETWEEN ? AND ?
         GROUP BY pv.id
         ORDER BY total_revenue DESC
         LIMIT 8",
        [$month_start, $month_end]
    )->results();
} catch (Exception $e) { error_log('Widget top_selling_products: ' . $e->getMessage()); }
?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 flex flex-col overflow-hidden h-full">
    <div class="p-4 border-b border-gray-100 flex items-center gap-3">
        <div class="w-9 h-9 rounded-full bg-<?php echo $widget_color; ?>-100 flex items-center justify-center">
            <i class="fas <?php echo $widget_icon; ?> text-<?php echo $widget_color; ?>-600"></i>
        </div>
        <div>
            <h3 class="font-bold text-gray-800 text-sm"><?php echo htmlspecialchars($widget_title); ?></h3>
            <p class="text-xs text-gray-400"><?php echo date('F Y'); ?></p>
        </div>
    </div>
    <div class="flex-1 overflow-y-auto" style="max-height:280px;">
        <?php if (empty($products)): ?>
        <div class="py-8 text-center text-gray-400 text-sm">
            <i class="fas fa-chart-bar text-3xl mb-2 opacity-30"></i><br>No sales data yet this month
        </div>
        <?php else: ?>
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase">
                    <th class="px-4 py-2 text-left">Product</th>
                    <th class="px-4 py-2 text-right">Qty</th>
                    <th class="px-4 py-2 text-right">Revenue</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($products as $i => $p): ?>
                <tr class="hover:bg-gray-50 transition">
                    <td class="px-4 py-2.5">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold text-gray-400 w-5 text-center"><?php echo $i + 1; ?></span>
                            <div>
                                <p class="font-semibold text-gray-800 text-xs leading-tight">
                                    <?php echo htmlspecialchars($p->base_name); ?>
                                    <?php if (!empty($p->grade)): ?><span class="text-gray-400">(<?php echo $p->grade; ?>)</span><?php endif; ?>
                                </p>
                                <p class="text-xs text-gray-400"><?php echo htmlspecialchars($p->weight_variant ?? $p->sku); ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-2.5 text-right text-xs font-semibold text-gray-700"><?php echo number_format($p->total_qty); ?></td>
                    <td class="px-4 py-2.5 text-right text-xs font-bold text-green-700">৳<?php echo number_format($p->total_revenue, 0); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

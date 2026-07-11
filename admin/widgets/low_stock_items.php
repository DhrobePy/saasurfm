<?php
// Widget: low_stock_items — Product variants with zero or very low inventory
$low_items = [];
$total_low = 0;
try {
    $count_r = $db->query(
        "SELECT COUNT(DISTINCT i.variant_id) AS v
         FROM inventory i WHERE i.quantity <= 0"
    )->first();
    $total_low = $count_r ? (int)$count_r->v : 0;

    if ($total_low > 0) {
        $low_items = $db->query(
            "SELECT p.base_name AS product_name, pv.grade, pv.weight_variant, pv.sku,
                    SUM(i.quantity) AS total_qty, b.name AS branch_name
             FROM inventory i
             JOIN product_variants pv ON i.variant_id = pv.id
             JOIN products p ON pv.product_id = p.id
             LEFT JOIN branches b ON i.branch_id = b.id
             WHERE i.quantity <= 0
             GROUP BY pv.id, b.id
             ORDER BY total_qty ASC
             LIMIT 8"
        )->results();
    }
} catch (Exception $e) { error_log('Widget low_stock_items: ' . $e->getMessage()); }
?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 flex flex-col overflow-hidden h-full">
    <div class="p-4 border-b border-gray-100 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-full bg-<?php echo $widget_color; ?>-100 flex items-center justify-center">
                <i class="fas <?php echo $widget_icon; ?> text-<?php echo $widget_color; ?>-600"></i>
            </div>
            <h3 class="font-bold text-gray-800 text-sm"><?php echo htmlspecialchars($widget_title); ?></h3>
        </div>
        <?php if ($total_low > 0): ?>
        <span class="bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded-full"><?php echo $total_low; ?></span>
        <?php endif; ?>
    </div>
    <div class="flex-1 divide-y divide-gray-50 overflow-y-auto" style="max-height:280px;">
        <?php if (empty($low_items)): ?>
        <div class="py-8 text-center text-gray-400 text-sm">
            <i class="fas fa-check-circle text-green-400 text-2xl mb-2"></i><br>All items in stock!
        </div>
        <?php else: foreach ($low_items as $item): ?>
        <div class="px-4 py-2.5 flex items-center justify-between">
            <div>
                <p class="text-sm font-semibold text-gray-800 leading-tight">
                    <?php echo htmlspecialchars($item->product_name); ?>
                    <?php if (!empty($item->grade)): ?><span class="text-xs text-gray-400">(<?php echo $item->grade; ?>)</span><?php endif; ?>
                </p>
                <p class="text-xs text-gray-400"><?php echo htmlspecialchars($item->sku); ?> · <?php echo htmlspecialchars($item->branch_name ?? 'All'); ?></p>
            </div>
            <span class="text-sm font-bold <?php echo $item->total_qty <= 0 ? 'text-red-600' : 'text-orange-500'; ?>">
                <?php echo number_format($item->total_qty); ?> kg
            </span>
        </div>
        <?php endforeach; endif; ?>
    </div>
    <div class="bg-gray-50 px-4 py-2 text-center border-t border-gray-100">
        <a href="../product/products.php" class="text-xs text-blue-600 hover:text-blue-800 font-semibold">View all inventory →</a>
    </div>
</div>

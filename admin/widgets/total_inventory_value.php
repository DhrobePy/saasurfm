<?php
// Widget: total_inventory_value — Total value of current inventory (qty × active price)
$stat_value  = '0.00';
$item_count  = 0;
try {
    $result = $db->query(
        "SELECT IFNULL(SUM(i.quantity * pp.unit_price), 0) AS v,
                COUNT(DISTINCT i.variant_id) AS c
         FROM inventory i
         JOIN product_prices pp ON pp.variant_id = i.variant_id AND pp.is_active = 1
         WHERE i.quantity > 0"
    )->first();
    if ($result) {
        $stat_value = number_format((float)$result->v, 2);
        $item_count = (int)$result->c;
    }
} catch (Exception $e) { error_log('Widget total_inventory_value: ' . $e->getMessage()); }
?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-full bg-<?php echo $widget_color; ?>-100 flex items-center justify-center flex-shrink-0">
            <i class="fas <?php echo $widget_icon; ?> text-<?php echo $widget_color; ?>-600 text-lg"></i>
        </div>
        <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide"><?php echo htmlspecialchars($widget_title); ?></p>
            <p class="text-2xl font-bold text-gray-900">৳<?php echo $stat_value; ?></p>
        </div>
    </div>
    <div class="bg-gray-50 px-5 py-2 text-xs text-gray-500"><?php echo $item_count; ?> variant<?php echo $item_count !== 1 ? 's' : ''; ?> in stock · valued at active price</div>
</div>

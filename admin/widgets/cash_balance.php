<?php
// Widget: cash_balance — Total petty cash across all active branches
$stat_value = '0.00';
try {
    $result = $db->query(
        "SELECT IFNULL(SUM(current_balance), 0) AS v
         FROM branch_petty_cash_accounts WHERE status = 'active'"
    )->first();
    if ($result) $stat_value = number_format((float)$result->v, 2);
} catch (Exception $e) { error_log('Widget cash_balance: ' . $e->getMessage()); }
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
    <div class="bg-gray-50 px-5 py-2 text-xs text-gray-500">Petty cash · all active branches</div>
</div>

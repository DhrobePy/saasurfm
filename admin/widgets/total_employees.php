<?php
// Widget: total_employees — Active employee headcount
$active_count    = 0;
$on_leave_count  = 0;
try {
    $rows = $db->query(
        "SELECT status, COUNT(id) AS c FROM employees GROUP BY status"
    )->results();
    foreach ($rows as $r) {
        if ($r->status === 'active')   $active_count   = (int)$r->c;
        if ($r->status === 'on_leave') $on_leave_count = (int)$r->c;
    }
} catch (Exception $e) { error_log('Widget total_employees: ' . $e->getMessage()); }
?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-full bg-<?php echo $widget_color; ?>-100 flex items-center justify-center flex-shrink-0">
            <i class="fas <?php echo $widget_icon; ?> text-<?php echo $widget_color; ?>-600 text-lg"></i>
        </div>
        <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide"><?php echo htmlspecialchars($widget_title); ?></p>
            <p class="text-2xl font-bold text-gray-900"><?php echo number_format($active_count); ?></p>
            <?php if ($on_leave_count > 0): ?>
            <p class="text-xs text-orange-500 mt-0.5"><?php echo $on_leave_count; ?> on leave</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="bg-gray-50 px-5 py-2 text-xs text-gray-500 flex justify-between">
        <span>Active employees</span>
        <a href="../admin/employees.php" class="text-blue-600 hover:underline font-semibold">View →</a>
    </div>
</div>

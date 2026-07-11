<?php
// Widget: link_create_invoice — Quick link to create a new credit order
?>
<a href="../cr/create_order.php"
   class="flex items-center gap-4 bg-white rounded-xl shadow-sm border border-gray-100 p-5 hover:shadow-md hover:border-<?php echo $widget_color; ?>-300 transition group">
    <div class="w-12 h-12 rounded-full bg-<?php echo $widget_color; ?>-100 flex items-center justify-center flex-shrink-0 group-hover:scale-110 transition-transform">
        <i class="fas <?php echo $widget_icon; ?> text-<?php echo $widget_color; ?>-600 text-lg"></i>
    </div>
    <div>
        <p class="font-bold text-gray-800"><?php echo htmlspecialchars($widget_title); ?></p>
        <p class="text-xs text-gray-500 mt-0.5">Open the credit order form</p>
    </div>
    <i class="fas fa-chevron-right text-gray-300 ml-auto group-hover:text-<?php echo $widget_color; ?>-500 transition-colors"></i>
</a>

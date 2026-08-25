<?php
// This widget doesn't need database access or date logic
global $currentUser;

// Use 'display_name' from your 'users' table schema
$display_name = $currentUser['display_name'] ?? 'Admin';

// Get current hour for a time-based greeting
$current_hour = (int)date('G'); // 24-hour format
$greeting = "Welcome";

if ($current_hour >= 5 && $current_hour < 12) {
    $greeting = "Good Morning";
} elseif ($current_hour >= 12 && $current_hour < 18) {
    $greeting = "Good Afternoon";
} elseif ($current_hour >= 18 && $current_hour < 22) {
    $greeting = "Good Evening";
} else {
    $greeting = "Welcome"; // For late night / early morning
}
?>

<div class="bg-gradient-to-r from-blue-600 to-blue-500 text-white rounded-lg shadow-md p-6">
    <div class="flex items-center">
        <i class="fas <?php echo $widget_icon; ?> text-4xl mr-4 opacity-80 animate-pulse"></i>
        <div>
            <h2 class="text-2xl font-bold"><?php echo htmlspecialchars($greeting); ?>, <?php echo htmlspecialchars($display_name); ?>!</h2>
            <p class="text-blue-100 mt-1">
                <?php echo htmlspecialchars($widget->widget_description ?? "It's great to see you! Here's a look at what's happening."); ?>
            </p>
        </div>
    </div>
</div>


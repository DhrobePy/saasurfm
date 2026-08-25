<?php
require_once '../core/init.php';

// 1. Security & Setup
$allowed_roles = ['Superadmin', 'admin'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? 'guest'; // Get the user's role for widget filtering
$pageTitle = 'Dashboard';

// Include the main header
require_once '../templates/header.php';

// 2. Fetch User-Specific Widgets
// Get the widgets this user has selected, in the order they saved them
$widgets = $db->query(
    "SELECT 
        dw.widget_key, 
        dw.widget_name, 
        dw.widget_type, 
        dw.icon, 
        dw.color, 
        dw.required_roles,
        udp.size, 
        udp.date_range,
        udp.refresh_interval,
        udp.custom_config
     FROM 
        user_dashboard_preferences udp
     JOIN 
        dashboard_widgets dw ON udp.widget_id = dw.id
     WHERE 
        udp.user_id = ? AND dw.is_active = 1 AND udp.is_enabled = 1
     ORDER BY 
        udp.position ASC",
    [$user_id]
)->results();

// 3. Fallback to Default Widgets
// If the user has no preferences (e.g., new user), load the default set
if (empty($widgets)) {
    $widgets = $db->query(
        "SELECT 
            widget_key, 
            widget_name, 
            widget_type, 
            icon, 
            color,
            required_roles,
            'medium' as size,  -- Provide a default size
            'today' as date_range, -- Provide a default date range
            0 as refresh_interval, -- Default refresh
            NULL as custom_config -- Default config
         FROM 
            dashboard_widgets
         WHERE 
            is_active = 1 AND default_enabled = 1
         ORDER BY 
            widget_category, sort_order ASC"
    )->results();
}

// 4. Filter Widgets by User Role
// We must check if the user's role permits them to see each widget
$filtered_widgets = array_filter($widgets, function($widget) use ($user_role) {
    // Superadmin sees everything
    if ($user_role === 'Superadmin') {
        return true;
    }
    
    // If required_roles is empty or NULL, the widget is public (to admins)
    if (empty($widget->required_roles)) {
        return true;
    }
    
    // Check if the user's role is in the comma-separated list
    $required_roles = array_map('trim', explode(',', $widget->required_roles));
    
    if (in_array($user_role, $required_roles)) {
        return true;
    }
    
    return false; // User does not have permission
});


?>

<!-- Main Dashboard Container -->
<div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <!-- Dashboard Header -->
    <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                Welcome, <?php echo htmlspecialchars($currentUser['display_name'] ?? 'Admin'); ?>!
            </h1>
            <p class="text-lg text-gray-600 mt-1">Here's what's happening today.</p>
        </div>
        <a href="settings.php" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition shadow-sm">
            <i class="fas fa-cog mr-2"></i>Customize Dashboard
        </a>
    </div>

    <?php if (empty($filtered_widgets)): ?>
        <!-- Show this if there are no custom OR default widgets configured *or* user has no permission -->
        <div class="bg-white rounded-lg shadow-md p-12 text-center">
            <i class="fas fa-exclamation-circle text-5xl text-gray-400 mb-4"></i>
            <h3 class="text-xl font-bold text-gray-800">No Widgets Found</h3>
            <p class="text-gray-600 mt-2">There are no dashboard widgets enabled for your account or role.</p>
            <a href="settings.php" class="mt-4 inline-block px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-bold transition">
                <i class="fas fa-cog mr-2"></i>Setup Your Dashboard
            </a>
        </div>
        
    <?php else: ?>
        <!-- 
        5. Dynamic Widget Grid
        This grid is 4 columns wide on medium screens and up.
        On mobile, it's 1 column (grid-cols-1), so all widgets stack.
        -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

            <?php foreach ($filtered_widgets as $widget): ?>
                
                <?php
                // 6. Calculate Widget Size
                // Convert the 'size' string from the DB into a Tailwind class
                $col_span_class = 'md:col-span-4'; // Default to full-width
                switch ($widget->size) {
                    case 'small':
                        $col_span_class = 'md:col-span-1';
                        break;
                    case 'medium':
                        $col_span_class = 'md:col-span-2';
                        break;
                    case 'large':
                        $col_span_class = 'md:col-span-3';
                        break;
                }
                
                // 7. Securely Include the Widget File (using widget_key)
                // e.g., 'pos_sales_summary' becomes 'widgets/pos_sales_summary.php'
                $widget_file_path = __DIR__ . '/widgets/' . basename($widget->widget_key) . '.php';
                
                if (file_exists($widget_file_path)):
                    // These variables are now "passed" to the included widget file
                    $date_range = $widget->date_range;
                    $widget_title = $widget->widget_name;
                    $widget_icon = $widget->icon;
                    $widget_color = $widget->color;
                    $widget_size = $widget->size;
                    $refresh_interval = $widget->refresh_interval;
                    $custom_config = $widget->custom_config;
                ?>
                    <!-- This outer div applies the column span (size) -->
                    <div class="<?php echo $col_span_class; ?>">
                        <?php
                        // The included file (e.g., 'widgets/pos_sales_summary.php')
                        // is responsible for its own HTML (card, content, etc.)
                        include $widget_file_path;
                        ?>
                    </div>
                <?php else: ?>
                    <!-- Error case if the widget file is missing -->
                    <div class="<?php echo $col_span_class; ?>">
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg shadow-md h-full">
                            <p class="font-bold"><i class="fas fa-exclamation-triangle mr-2"></i>Widget Error</p>
                            <p>Could not load widget: <?php echo htmlspecialchars($widget->widget_name); ?></p>
                            <small class="text-xs">File not found: <?php echo htmlspecialchars(basename($widget->widget_key) . '.php'); ?></small>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endforeach; ?>

        </div> <!-- end .grid -->
    <?php endif; ?>

</div> <!-- end .container -->

<?php
// Include the main footer
require_once '../templates/footer.php';
?>


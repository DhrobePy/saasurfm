<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Dashboard Settings';
$success = null;
$error = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_preferences') {
    try {
        $db->getPdo()->beginTransaction();
        
        $selected_widgets = $_POST['widgets'] ?? [];
        
        // Delete all existing preferences for this user
        $db->query("DELETE FROM user_dashboard_preferences WHERE user_id = ?", [$user_id]);
        
        // Insert new preferences
        $position = 0;
        foreach ($selected_widgets as $widget_id) {
            $widget_id = (int)$widget_id;
            $size = $_POST['size_' . $widget_id] ?? 'medium';
            
            $db->insert('user_dashboard_preferences', [
                'user_id' => $user_id,
                'widget_id' => $widget_id,
                'is_enabled' => 1,
                'position' => $position,
                'size' => $size
            ]);
            
            $position++;
        }
        
        $db->getPdo()->commit();
        $success = "Dashboard preferences saved successfully!";
        
    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        $error = "Failed to save preferences: " . $e->getMessage();
    }
}

// Get all available widgets
$all_widgets = $db->query(
    "SELECT * FROM dashboard_widgets 
     WHERE is_active = 1 
     ORDER BY widget_category, sort_order"
)->results();

// Get user's current preferences
$user_preferences = $db->query(
    "SELECT widget_id, size, position 
     FROM user_dashboard_preferences 
     WHERE user_id = ? AND is_enabled = 1",
    [$user_id]
)->results();

// Create lookup array for user preferences
$user_widget_ids = [];
$user_widget_sizes = [];
foreach ($user_preferences as $pref) {
    $user_widget_ids[] = $pref->widget_id;
    $user_widget_sizes[$pref->widget_id] = $pref->size;
}

// Group widgets by category
$widgets_by_category = [];
foreach ($all_widgets as $widget) {
    $widgets_by_category[$widget->widget_category][] = $widget;
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
            <p class="text-lg text-gray-600 mt-1">Customize your dashboard by selecting widgets to display</p>
        </div>
        <a href="../index.php" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
        </a>
    </div>
</div>

<?php if ($success): ?>
<div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Success!</p>
    <p><?php echo htmlspecialchars($success); ?></p>
    <a href="../index.php" class="text-green-800 underline mt-2 inline-block">View Dashboard</a>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Error</p>
    <p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<form method="POST" class="space-y-6">
    <input type="hidden" name="action" value="save_preferences">
    
    <?php
    $category_labels = [
        'sales' => ['title' => 'Sales & Orders', 'icon' => 'fa-shopping-cart', 'color' => 'blue'],
        'finance' => ['title' => 'Finance & Payments', 'icon' => 'fa-coins', 'color' => 'green'],
        'inventory' => ['title' => 'Inventory', 'icon' => 'fa-boxes', 'color' => 'purple'],
        'hr' => ['title' => 'Human Resources', 'icon' => 'fa-users', 'color' => 'indigo'],
        'quick_links' => ['title' => 'Quick Actions', 'icon' => 'fa-bolt', 'color' => 'yellow'],
        'reports' => ['title' => 'Reports', 'icon' => 'fa-chart-bar', 'color' => 'red']
    ];
    
    foreach ($category_labels as $category => $cat_info):
        if (empty($widgets_by_category[$category])) continue;
    ?>
    
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="p-4 bg-<?php echo $cat_info['color']; ?>-50 border-b border-<?php echo $cat_info['color']; ?>-200">
            <h2 class="text-xl font-bold text-gray-900">
                <i class="fas <?php echo $cat_info['icon']; ?> text-<?php echo $cat_info['color']; ?>-600 mr-2"></i>
                <?php echo $cat_info['title']; ?>
            </h2>
        </div>
        
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach ($widgets_by_category[$category] as $widget): 
                    $is_checked = in_array($widget->id, $user_widget_ids);
                    $current_size = $user_widget_sizes[$widget->id] ?? 'medium';
                ?>
                
                <div class="border rounded-lg p-4 hover:bg-gray-50 <?php echo $is_checked ? 'border-blue-500 bg-blue-50' : 'border-gray-200'; ?>">
                    <div class="flex items-start">
                        <input type="checkbox" 
                               name="widgets[]" 
                               value="<?php echo $widget->id; ?>"
                               id="widget_<?php echo $widget->id; ?>"
                               class="mt-1 h-5 w-5 text-blue-600"
                               <?php echo $is_checked ? 'checked' : ''; ?>>
                        
                        <div class="ml-3 flex-1">
                            <label for="widget_<?php echo $widget->id; ?>" class="cursor-pointer">
                                <div class="flex items-center">
                                    <i class="fas <?php echo $widget->icon; ?> text-<?php echo $widget->color; ?>-600 mr-2"></i>
                                    <span class="font-bold text-gray-900"><?php echo htmlspecialchars($widget->widget_name); ?></span>
                                </div>
                                
                                <?php if ($widget->widget_description): ?>
                                <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($widget->widget_description); ?></p>
                                <?php endif; ?>
                                
                                <span class="inline-block mt-2 px-2 py-1 text-xs rounded bg-gray-100 text-gray-700">
                                    <?php echo ucfirst($widget->widget_type); ?>
                                </span>
                            </label>
                            
                            <?php if ($widget->widget_type === 'stat_card' || $widget->widget_type === 'chart'): ?>
                            <div class="mt-3">
                                <label class="text-xs text-gray-600">Widget Size:</label>
                                <select name="size_<?php echo $widget->id; ?>" 
                                        class="mt-1 text-sm px-2 py-1 border rounded">
                                    <option value="small" <?php echo $current_size === 'small' ? 'selected' : ''; ?>>Small</option>
                                    <option value="medium" <?php echo $current_size === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="large" <?php echo $current_size === 'large' ? 'selected' : ''; ?>>Large</option>
                                    <option value="full" <?php echo $current_size === 'full' ? 'selected' : ''; ?>>Full Width</option>
                                </select>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <?php endforeach; ?>
    
    <!-- Save Button -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex justify-between items-center">
            <div>
                <p class="text-sm text-gray-600">
                    <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                    Selected widgets will appear on your dashboard in the order shown above.
                </p>
            </div>
            <div class="flex gap-3">
                <a href="../index.php" 
                   class="px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                    Cancel
                </a>
                <button type="submit" 
                        class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-bold">
                    <i class="fas fa-save mr-2"></i>Save Dashboard Settings
                </button>
            </div>
        </div>
    </div>
</form>

</div>

<?php require_once '../templates/footer.php'; ?>
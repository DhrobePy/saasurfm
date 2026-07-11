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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'save_preferences') {
        try {
            $db->getPdo()->beginTransaction();
            
            $selected_widgets = $_POST['widgets'] ?? [];
            
            // Validation
            if (empty($selected_widgets)) {
                throw new Exception("Please select at least one widget");
            }
            
            if (count($selected_widgets) > 20) {
                throw new Exception("Maximum 20 widgets allowed");
            }
            
            // Delete all existing preferences for this user
            $db->query("DELETE FROM user_dashboard_preferences WHERE user_id = ?", [$user_id]);
            
            // Insert new preferences with order from drag-and-drop
            $widget_order = isset($_POST['widget_order']) ? json_decode($_POST['widget_order'], true) : [];
            
            foreach ($selected_widgets as $widget_id) {
                $widget_id = (int)$widget_id;
                $size = $_POST['size_' . $widget_id] ?? 'medium';
                $date_range = $_POST['date_range_' . $widget_id] ?? 'today';
                
                // Get position from widget_order, or use default
                $position = array_search($widget_id, $widget_order);
                if ($position === false) {
                    $position = array_search($widget_id, $selected_widgets);
                }
                
                $db->insert('user_dashboard_preferences', [
                    'user_id' => $user_id,
                    'widget_id' => $widget_id,
                    'is_enabled' => 1,
                    'position' => $position,
                    'size' => $size,
                    'date_range' => $date_range
                ]);
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
    
    // Handle Reset to Default
    elseif ($_POST['action'] === 'reset_to_default') {
        try {
            $db->getPdo()->beginTransaction();
            
            // Delete all existing preferences
            $db->query("DELETE FROM user_dashboard_preferences WHERE user_id = ?", [$user_id]);
            
            // Get default widgets
            $default_widgets = $db->query(
                "SELECT id, widget_type FROM dashboard_widgets 
                 WHERE is_active = 1 AND default_enabled = 1 
                 ORDER BY widget_category, sort_order"
            )->results();
            
            $position = 0;
            foreach ($default_widgets as $widget) {
                $db->insert('user_dashboard_preferences', [
                    'user_id' => $user_id,
                    'widget_id' => $widget->id,
                    'is_enabled' => 1,
                    'position' => $position,
                    'size' => 'medium',
                    'date_range' => 'today'
                ]);
                $position++;
            }
            
            $db->getPdo()->commit();
            $success = "Dashboard reset to default settings successfully!";
            
        } catch (Exception $e) {
            if ($db->getPdo()->inTransaction()) {
                $db->getPdo()->rollBack();
            }
            $error = "Failed to reset preferences: " . $e->getMessage();
        }
    }
}

// Get all available widgets
$all_widgets = $db->query(
    "SELECT * FROM dashboard_widgets 
     WHERE is_active = 1 
     ORDER BY widget_category, sort_order"
)->results();

// Get user's current preferences (ordered by position)
$user_preferences = $db->query(
    "SELECT widget_id, size, position, date_range 
     FROM user_dashboard_preferences 
     WHERE user_id = ? AND is_enabled = 1
     ORDER BY position ASC",
    [$user_id]
)->results();

// Create lookup arrays for user preferences
$user_widget_ids = [];
$user_widget_sizes = [];
$user_widget_dates = [];
$user_widget_positions = [];

foreach ($user_preferences as $pref) {
    $user_widget_ids[] = $pref->widget_id;
    $user_widget_sizes[$pref->widget_id] = $pref->size;
    $user_widget_dates[$pref->widget_id] = $pref->date_range ?? 'today';
    $user_widget_positions[$pref->widget_id] = $pref->position;
}

// Sort widgets by user's position if available
usort($all_widgets, function($a, $b) use ($user_widget_positions, $user_widget_ids) {
    $a_selected = in_array($a->id, $user_widget_ids);
    $b_selected = in_array($b->id, $user_widget_ids);
    
    // Selected widgets come first, sorted by position
    if ($a_selected && !$b_selected) return -1;
    if (!$a_selected && $b_selected) return 1;
    
    if ($a_selected && $b_selected) {
        $a_pos = $user_widget_positions[$a->id] ?? 999;
        $b_pos = $user_widget_positions[$b->id] ?? 999;
        return $a_pos - $b_pos;
    }
    
    // Unselected widgets sorted by category and sort_order
    if ($a->widget_category != $b->widget_category) {
        return strcmp($a->widget_category, $b->widget_category);
    }
    return $a->sort_order - $b->sort_order;
});

// Group widgets by category
$widgets_by_category = [];
foreach ($all_widgets as $widget) {
    $widgets_by_category[$widget->widget_category][] = $widget;
}

require_once '../templates/header.php';
?>

<!-- Add SortableJS CDN -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<style>
.sortable-ghost {
    opacity: 0.4;
    background: #f3f4f6;
}

.sortable-drag {
    opacity: 1;
    cursor: grabbing !important;
}

.widget-card {
    cursor: grab;
    transition: all 0.2s ease;
}

.widget-card:active {
    cursor: grabbing;
}

.widget-card.selected {
    border-color: #3b82f6;
    background-color: #eff6ff;
}

.drag-handle {
    cursor: grab;
    color: #9ca3af;
}

.drag-handle:hover {
    color: #3b82f6;
}

#selected-widgets-preview {
    max-height: 600px;
    overflow-y: auto;
}

.widget-preview-item {
    transition: all 0.2s ease;
}

.widget-preview-item:hover {
    background-color: #f9fafb;
}
</style>

<div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
            <p class="text-lg text-gray-600 mt-1">Customize your dashboard by selecting and arranging widgets</p>
        </div>
        <a href="../index.php" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition">
            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
        </a>
    </div>
</div>

<?php if ($success): ?>
<div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-r-lg shadow-sm">
    <div class="flex items-center">
        <i class="fas fa-check-circle text-2xl mr-3"></i>
        <div>
            <p class="font-bold">Success!</p>
            <p><?php echo htmlspecialchars($success); ?></p>
        </div>
    </div>
    <a href="../index.php" class="text-green-800 underline mt-2 inline-block font-semibold">
        <i class="fas fa-eye mr-1"></i>View Dashboard
    </a>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg shadow-sm">
    <div class="flex items-center">
        <i class="fas fa-exclamation-circle text-2xl mr-3"></i>
        <div>
            <p class="font-bold">Error</p>
            <p><?php echo htmlspecialchars($error); ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Quick Actions Bar -->
<div class="bg-white rounded-lg shadow-md p-4 mb-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            <button type="button" id="selectAllBtn" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                <i class="fas fa-check-double mr-2"></i>Select All
            </button>
            <button type="button" id="deselectAllBtn" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition">
                <i class="fas fa-times mr-2"></i>Deselect All
            </button>
            <button type="button" id="resetDefaultBtn" class="px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition">
                <i class="fas fa-undo mr-2"></i>Reset to Default
            </button>
        </div>
        
        <div class="flex items-center gap-4">
            <div class="text-sm">
                <span class="font-semibold text-gray-700">Selected: </span>
                <span id="selectedCount" class="text-blue-600 font-bold text-lg">0</span>
                <span class="text-gray-500">/ 20 max</span>
            </div>
            <input type="search" 
                   id="widgetSearch" 
                   placeholder="Search widgets..."
                   class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    
    <!-- Left Column: Widget Selection -->
    <div class="lg:col-span-2">
        <form method="POST" id="preferencesForm" class="space-y-6">
            <input type="hidden" name="action" value="save_preferences">
            <input type="hidden" name="widget_order" id="widgetOrderInput" value="">
            
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
            
            <div class="bg-white rounded-lg shadow-md overflow-hidden widget-category" data-category="<?php echo $category; ?>">
                <div class="p-4 bg-<?php echo $cat_info['color']; ?>-50 border-b border-<?php echo $cat_info['color']; ?>-200 flex justify-between items-center">
                    <h2 class="text-xl font-bold text-gray-900">
                        <i class="fas <?php echo $cat_info['icon']; ?> text-<?php echo $cat_info['color']; ?>-600 mr-2"></i>
                        <?php echo $cat_info['title']; ?>
                    </h2>
                    <button type="button" class="select-category-btn text-sm px-3 py-1 bg-<?php echo $cat_info['color']; ?>-600 text-white rounded hover:bg-<?php echo $cat_info['color']; ?>-700 transition">
                        <i class="fas fa-check mr-1"></i>Select All
                    </button>
                </div>
                
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($widgets_by_category[$category] as $widget): 
                            $is_checked = in_array($widget->id, $user_widget_ids);
                            $current_size = $user_widget_sizes[$widget->id] ?? 'medium';
                            $current_date_range = $user_widget_dates[$widget->id] ?? 'today';
                            $supports_date_range = in_array($widget->widget_type, ['stat_card', 'chart', 'table']) && 
                                                   in_array($widget->widget_category, ['sales', 'finance', 'inventory', 'reports']);
                        ?>
                        
                        <div class="widget-card border rounded-lg p-4 hover:shadow-md <?php echo $is_checked ? 'selected border-blue-500 bg-blue-50' : 'border-gray-200'; ?>" 
                             data-widget-id="<?php echo $widget->id; ?>"
                             data-widget-name="<?php echo htmlspecialchars($widget->widget_name); ?>">
                            <div class="flex items-start">
                                <div class="drag-handle mr-2 mt-1">
                                    <i class="fas fa-grip-vertical"></i>
                                </div>
                                
                                <input type="checkbox" 
                                       name="widgets[]" 
                                       value="<?php echo $widget->id; ?>"
                                       id="widget_<?php echo $widget->id; ?>"
                                       class="widget-checkbox mt-1 h-5 w-5 text-blue-600 rounded focus:ring-blue-500"
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
                                            <?php echo ucfirst(str_replace('_', ' ', $widget->widget_type)); ?>
                                        </span>
                                    </label>
                                    
                                    <!-- Widget Configuration Options -->
                                    <div class="widget-config mt-3 space-y-2 <?php echo !$is_checked ? 'hidden' : ''; ?>">
                                        
                                        <?php if ($widget->widget_type === 'stat_card' || $widget->widget_type === 'chart'): ?>
                                        <div>
                                            <label class="text-xs font-semibold text-gray-600">Widget Size:</label>
                                            <select name="size_<?php echo $widget->id; ?>" 
                                                    class="widget-size-select mt-1 text-sm px-2 py-1 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 w-full">
                                                <option value="small" <?php echo $current_size === 'small' ? 'selected' : ''; ?>>Small (1/4 width)</option>
                                                <option value="medium" <?php echo $current_size === 'medium' ? 'selected' : ''; ?>>Medium (1/2 width)</option>
                                                <option value="large" <?php echo $current_size === 'large' ? 'selected' : ''; ?>>Large (3/4 width)</option>
                                                <option value="full" <?php echo $current_size === 'full' ? 'selected' : ''; ?>>Full Width</option>
                                            </select>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($supports_date_range): ?>
                                        <div>
                                            <label class="text-xs font-semibold text-gray-600">Date Range:</label>
                                            <select name="date_range_<?php echo $widget->id; ?>" 
                                                    class="widget-daterange-select mt-1 text-sm px-2 py-1 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 w-full">
                                                <option value="today" <?php echo $current_date_range === 'today' ? 'selected' : ''; ?>>Today</option>
                                                <option value="yesterday" <?php echo $current_date_range === 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                                                <option value="this_week" <?php echo $current_date_range === 'this_week' ? 'selected' : ''; ?>>This Week</option>
                                                <option value="last_week" <?php echo $current_date_range === 'last_week' ? 'selected' : ''; ?>>Last Week</option>
                                                <option value="this_month" <?php echo $current_date_range === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                                                <option value="last_month" <?php echo $current_date_range === 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                                                <option value="this_quarter" <?php echo $current_date_range === 'this_quarter' ? 'selected' : ''; ?>>This Quarter</option>
                                                <option value="this_year" <?php echo $current_date_range === 'this_year' ? 'selected' : ''; ?>>This Year</option>
                                                <option value="last_30_days" <?php echo $current_date_range === 'last_30_days' ? 'selected' : ''; ?>>Last 30 Days</option>
                                                <option value="last_90_days" <?php echo $current_date_range === 'last_90_days' ? 'selected' : ''; ?>>Last 90 Days</option>
                                            </select>
                                        </div>
                                        <?php endif; ?>
                                    </div>
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
                            Drag widgets to reorder them on your dashboard
                        </p>
                    </div>
                    <div class="flex gap-3">
                        <a href="../index.php" 
                           class="px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">
                            Cancel
                        </a>
                        <button type="submit" 
                                id="saveBtn"
                                class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-bold transition">
                            <i class="fas fa-save mr-2"></i>Save Dashboard Settings
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Right Column: Selected Widgets Preview -->
    <div class="lg:col-span-1">
        <div class="sticky top-6">
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="p-4 bg-gradient-to-r from-blue-500 to-blue-600 text-white">
                    <h3 class="text-lg font-bold flex items-center">
                        <i class="fas fa-list-ol mr-2"></i>
                        Selected Widgets
                    </h3>
                    <p class="text-sm text-blue-100 mt-1">Drag to reorder</p>
                </div>
                
                <div id="selected-widgets-preview" class="p-4">
                    <div id="selectedWidgetsList" class="space-y-2">
                        <p class="text-gray-500 text-center py-8">
                            <i class="fas fa-mouse-pointer text-4xl mb-2"></i><br>
                            Select widgets to see them here
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
</div>

</div>

<!-- Reset to Default Confirmation Modal -->
<div id="resetModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl max-w-md mx-4 p-6">
        <div class="flex items-center mb-4">
            <i class="fas fa-exclamation-triangle text-orange-500 text-3xl mr-3"></i>
            <h3 class="text-xl font-bold text-gray-900">Reset to Default?</h3>
        </div>
        <p class="text-gray-600 mb-6">
            This will remove all your custom settings and restore the default dashboard configuration. This action cannot be undone.
        </p>
        <div class="flex justify-end gap-3">
            <button type="button" id="cancelReset" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                Cancel
            </button>
            <form method="POST" id="resetForm" class="inline">
                <input type="hidden" name="action" value="reset_to_default">
                <button type="submit" class="px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700">
                    <i class="fas fa-undo mr-2"></i>Reset to Default
                </button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // Initialize Sortable on the selected widgets preview
    const selectedWidgetsList = document.getElementById('selectedWidgetsList');
    let sortableInstance = null;
    
    function initializeSortable() {
        if (sortableInstance) {
            sortableInstance.destroy();
        }
        
        sortableInstance = new Sortable(selectedWidgetsList, {
            animation: 150,
            handle: '.preview-drag-handle',
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            onEnd: function() {
                updateWidgetOrder();
            }
        });
    }
    
    // Update selected count
    function updateSelectedCount() {
        const count = document.querySelectorAll('.widget-checkbox:checked').length;
        document.getElementById('selectedCount').textContent = count;
        
        // Update preview
        updateSelectedPreview();
    }
    
    // Update selected widgets preview
    function updateSelectedPreview() {
        const checkedBoxes = document.querySelectorAll('.widget-checkbox:checked');
        const previewList = document.getElementById('selectedWidgetsList');
        
        if (checkedBoxes.length === 0) {
            previewList.innerHTML = `
                <p class="text-gray-500 text-center py-8">
                    <i class="fas fa-mouse-pointer text-4xl mb-2"></i><br>
                    Select widgets to see them here
                </p>
            `;
            return;
        }
        
        previewList.innerHTML = '';
        
        checkedBoxes.forEach((checkbox, index) => {
            const widgetCard = checkbox.closest('.widget-card');
            const widgetId = widgetCard.dataset.widgetId;
            const widgetName = widgetCard.dataset.widgetName;
            const icon = widgetCard.querySelector('.fa-grip-vertical').nextElementSibling.nextElementSibling.querySelector('i').className;
            
            const previewItem = document.createElement('div');
            previewItem.className = 'widget-preview-item flex items-center p-3 bg-gray-50 rounded-lg border border-gray-200 hover:border-blue-500 transition';
            previewItem.dataset.widgetId = widgetId;
            previewItem.innerHTML = `
                <div class="preview-drag-handle cursor-grab mr-3 text-gray-400 hover:text-blue-600">
                    <i class="fas fa-grip-vertical"></i>
                </div>
                <div class="flex-1">
                    <div class="flex items-center">
                        <span class="font-semibold text-gray-700 text-sm mr-2">#${index + 1}</span>
                        <i class="${icon} mr-2 text-gray-600"></i>
                        <span class="text-sm font-medium text-gray-800">${widgetName}</span>
                    </div>
                </div>
                <button type="button" class="remove-widget-btn text-red-500 hover:text-red-700" data-widget-id="${widgetId}">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            previewList.appendChild(previewItem);
        });
        
        // Initialize sortable after updating preview
        initializeSortable();
        
        // Add event listeners to remove buttons
        document.querySelectorAll('.remove-widget-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const widgetId = this.dataset.widgetId;
                const checkbox = document.querySelector(`#widget_${widgetId}`);
                if (checkbox) {
                    checkbox.checked = false;
                    checkbox.dispatchEvent(new Event('change'));
                }
            });
        });
    }
    
    // Update widget order hidden input
    function updateWidgetOrder() {
        const previewItems = document.querySelectorAll('.widget-preview-item');
        const order = Array.from(previewItems).map(item => item.dataset.widgetId);
        document.getElementById('widgetOrderInput').value = JSON.stringify(order);
    }
    
    // Handle checkbox changes
    document.querySelectorAll('.widget-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const card = this.closest('.widget-card');
            const config = card.querySelector('.widget-config');
            
            if (this.checked) {
                card.classList.add('selected', 'border-blue-500', 'bg-blue-50');
                card.classList.remove('border-gray-200');
                if (config) config.classList.remove('hidden');
            } else {
                card.classList.remove('selected', 'border-blue-500', 'bg-blue-50');
                card.classList.add('border-gray-200');
                if (config) config.classList.add('hidden');
            }
            
            updateSelectedCount();
        });
    });
    
    // Select All button
    document.getElementById('selectAllBtn').addEventListener('click', function() {
        document.querySelectorAll('.widget-checkbox:not(:checked)').forEach(checkbox => {
            checkbox.checked = true;
            checkbox.dispatchEvent(new Event('change'));
        });
    });
    
    // Deselect All button
    document.getElementById('deselectAllBtn').addEventListener('click', function() {
        document.querySelectorAll('.widget-checkbox:checked').forEach(checkbox => {
            checkbox.checked = false;
            checkbox.dispatchEvent(new Event('change'));
        });
    });
    
    // Select Category buttons
    document.querySelectorAll('.select-category-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const category = this.closest('.widget-category');
            const checkboxes = category.querySelectorAll('.widget-checkbox');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = !allChecked;
                checkbox.dispatchEvent(new Event('change'));
            });
            
            this.innerHTML = allChecked ? 
                '<i class="fas fa-check mr-1"></i>Select All' : 
                '<i class="fas fa-times mr-1"></i>Deselect All';
        });
    });
    
    // Reset to Default button
    document.getElementById('resetDefaultBtn').addEventListener('click', function() {
        document.getElementById('resetModal').classList.remove('hidden');
    });
    
    document.getElementById('cancelReset').addEventListener('click', function() {
        document.getElementById('resetModal').classList.add('hidden');
    });
    
    // Close modal on background click
    document.getElementById('resetModal').addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.add('hidden');
        }
    });
    
    // Widget Search
    document.getElementById('widgetSearch').addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        
        document.querySelectorAll('.widget-card').forEach(card => {
            const widgetName = card.dataset.widgetName.toLowerCase();
            const description = card.querySelector('p')?.textContent.toLowerCase() || '';
            
            if (widgetName.includes(searchTerm) || description.includes(searchTerm)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    });
    
    // Form submission with loading state
    document.getElementById('preferencesForm').addEventListener('submit', function() {
        const saveBtn = document.getElementById('saveBtn');
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
        
        updateWidgetOrder();
    });
    
    // Initialize on page load
    updateSelectedCount();
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + S to save
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            document.getElementById('preferencesForm').dispatchEvent(new Event('submit'));
        }
        
        // Escape to close modal
        if (e.key === 'Escape') {
            document.getElementById('resetModal').classList.add('hidden');
        }
    });
    
});
</script>

<?php require_once '../templates/footer.php'; ?>
<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Dashboard Settings';
$success = null;
$error   = null;
$csrfToken = $_SESSION['csrf_token'] ?? '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // CSRF guard
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed. Please refresh the page and try again.";
    } elseif ($_POST['action'] === 'save_preferences') {
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
    } elseif ($_POST['action'] === 'save_company_logo') {
        $upload_dir = dirname(__DIR__) . '/uploads/company/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        if (!empty($_FILES['company_logo']['tmp_name']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $detected_type = mime_content_type($_FILES['company_logo']['tmp_name']);
            if (!in_array($detected_type, $allowed_types)) {
                $error = "Only JPEG, PNG, GIF, and WebP images are allowed.";
            } elseif ($_FILES['company_logo']['size'] > 2 * 1024 * 1024) {
                $error = "Logo file must be under 2 MB.";
            } else {
                $ext  = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
                foreach (glob($upload_dir . 'logo.*') as $old) @unlink($old);
                $dest = $upload_dir . 'logo.' . $ext;
                if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $dest)) {
                    auditLog('company_settings', 'logo_updated', "Company logo updated by " . ($currentUser['display_name'] ?? 'admin'), []);
                    $success = "Company logo updated successfully!";
                } else {
                    $error = "Failed to save logo. Check directory write permissions.";
                }
            }
        } elseif (!empty($_POST['remove_logo'])) {
            foreach (glob($upload_dir . 'logo.*') as $old) @unlink($old);
            auditLog('company_settings', 'logo_removed', "Company logo removed by " . ($currentUser['display_name'] ?? 'admin'), []);
            $success = "Company logo removed.";
        } else {
            $error = "No logo file selected.";
        }
    } elseif ($_POST['action'] === 'save_branches') {
        $branch_ids = $_POST['branch_id'] ?? [];
        if (empty($branch_ids)) {
            $error = "No branch data submitted.";
        } else {
            $updated = 0;
            foreach ($branch_ids as $bid) {
                $bid = (int)$bid;
                if ($bid < 1) continue;
                $new_name    = trim($_POST['branch_name'][$bid]    ?? '');
                $new_address = trim($_POST['branch_address'][$bid] ?? '');
                $new_phone   = trim($_POST['branch_phone'][$bid]   ?? '');
                if ($new_name === '') continue;
                $is_factory = isset($_POST['is_factory'][$bid]) ? 1 : 0;
                $db->query(
                    "UPDATE branches SET name = ?, address = ?, phone_number = ?, is_factory = ? WHERE id = ?",
                    [$new_name, $new_address ?: null, $new_phone ?: null, $is_factory, $bid]
                );
                $updated++;
            }
            auditLog('company_settings', 'branches_updated', "Branch addresses updated by " . ($currentUser['display_name'] ?? 'admin'), []);
            $success = "Branch settings saved successfully! ({$updated} branches updated)";
        }
    } elseif ($_POST['action'] === 'add_branch') {
        $new_name    = trim($_POST['new_branch_name']    ?? '');
        $new_code    = strtoupper(trim($_POST['new_branch_code'] ?? ''));
        $new_address = trim($_POST['new_branch_address'] ?? '');
        $new_phone   = trim($_POST['new_branch_phone']   ?? '');
        $new_factory = isset($_POST['new_branch_factory']) ? 1 : 0;
        if ($new_name === '') {
            $error = "Branch name is required.";
        } else {
            if ($new_code === '') {
                $words = preg_split('/\s+/', $new_name);
                $new_code = strtoupper(implode('', array_map(fn($w) => substr($w, 0, 1), $words)));
                if (strlen($new_code) < 2) $new_code = strtoupper(substr($new_name, 0, 4));
            }
            $existing = $db->query("SELECT id FROM branches WHERE code = ?", [$new_code])->first();
            if ($existing) $new_code .= rand(10, 99);
            $db->insert('branches', [
                'name'         => $new_name,
                'code'         => $new_code,
                'address'      => $new_address ?: null,
                'phone_number' => $new_phone   ?: null,
                'is_factory'   => $new_factory,
                'status'       => 'active',
            ]);
            auditLog('company_settings', 'branch_added', "Branch '{$new_name}' added by " . ($currentUser['display_name'] ?? 'admin'), []);
            $success = "Branch '{$new_name}' ({$new_code}) added successfully!";
        }
    } elseif ($_POST['action'] === 'save_payment_approval') {
        // Feature #6: require-approval-for-all-payments toggle
        try {
            $db->getPdo()->exec("CREATE TABLE IF NOT EXISTS `settings` (
                `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                `value` text DEFAULT NULL,
                PRIMARY KEY (`id`), UNIQUE KEY `uk_settings_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $val = !empty($_POST['payment_require_approval']) ? '1' : '0';
            $db->query(
                "INSERT INTO settings (name, value) VALUES ('payment_require_approval', ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [$val]
            );
            auditLog('company_settings', 'payment_approval_toggled',
                'Payment approval-for-all ' . ($val === '1' ? 'ENABLED' : 'DISABLED') . ' by ' . ($currentUser['display_name'] ?? 'admin'), []);
            $success = 'Payment approval policy saved: '
                     . ($val === '1' ? 'every Accounts receipt now needs approval before posting.' : 'only over-limit receipts need approval.');
        } catch (\Throwable $e) {
            $error = 'Failed to save payment approval setting: ' . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'save_dispatch_hold') {
        // Feature #5: global dispatch auto-hold toggle
        try {
            $db->getPdo()->exec("CREATE TABLE IF NOT EXISTS `settings` (
                `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                `value` text DEFAULT NULL,
                PRIMARY KEY (`id`), UNIQUE KEY `uk_settings_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $val = !empty($_POST['dispatch_global_hold']) ? '1' : '0';
            $db->query(
                "INSERT INTO settings (name, value) VALUES ('dispatch_global_hold', ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [$val]
            );
            auditLog('company_settings', 'dispatch_hold_toggled',
                'Global dispatch auto-hold ' . ($val === '1' ? 'ENABLED' : 'DISABLED') . ' by ' . ($currentUser['display_name'] ?? 'admin'), []);
            $success = 'Dispatch hold policy saved: every order '
                     . ($val === '1' ? 'is held until Accounts/Admin clears it.' : 'may dispatch without clearance (hold OFF).');
        } catch (\Throwable $e) {
            $error = 'Failed to save dispatch hold setting: ' . $e->getMessage();
        }
    }
} // end CSRF elseif / outer POST block

// Current dispatch-hold policy for the toggle UI
$dispatch_hold_on = dispatchGlobalHoldEnabled();
// Current payment-approval policy for the toggle UI
$payment_approval_on = paymentApprovalRequiredForAll();

// Load branches for editor
try { $db->getPdo()->exec("ALTER TABLE `branches` ADD COLUMN `is_factory` TINYINT(1) NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
$any_factory = $db->query("SELECT COUNT(*) AS cnt FROM branches WHERE is_factory = 1")->first();
if (!$any_factory || (int)$any_factory->cnt === 0) {
    try { $db->getPdo()->exec("UPDATE `branches` SET is_factory = 1 WHERE code IN ('DEMRA', 'SRG')"); } catch (\Throwable $e) {}
}
$branches = $db->query("SELECT id, name, code, address, phone_number, is_factory, status FROM branches ORDER BY id ASC")->results();

// Detect current logo
$_logo_dir = dirname(__DIR__) . '/uploads/company/';
$current_logo_url = null;
foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $_le) {
    if (file_exists($_logo_dir . 'logo.' . $_le)) {
        $current_logo_url = '../uploads/company/logo.' . $_le . '?v=' . filemtime($_logo_dir . 'logo.' . $_le);
        break;
    }
}

// Get all available widgets (guard against missing table)
$tables_ok = true;
try {
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
} catch (Exception $e) {
    $tables_ok = false;
    $all_widgets = [];
    $user_preferences = [];
    $error = "Dashboard widget tables are not yet set up. Please run the database migration or contact your system administrator. Detail: " . $e->getMessage();
}

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

<!-- ═══ Payment Approval Policy (Feature #6) ══════════════════════════════════ -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
    <h2 class="text-lg font-bold text-gray-800 mb-1 flex items-center gap-2">
        <i class="fas fa-user-check text-purple-600"></i> Payment Approval Policy
    </h2>
    <p class="text-sm text-gray-500 mb-4">
        When ON, <strong>every receipt an Accounts user collects</strong> is parked as a pending
        request and does <strong>not</strong> post to the ledger until a senior officer approves it
        (Approval Requests page). When OFF, only receipts above a user's collection limit need approval.
        Admins always post directly.
    </p>
    <form method="POST" class="flex items-center gap-4">
        <input type="hidden" name="action" value="save_payment_approval">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <input type="hidden" name="payment_require_approval" value="0">
        <label class="inline-flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="payment_require_approval" value="1"
                   <?php echo $payment_approval_on ? 'checked' : ''; ?>
                   class="w-5 h-5 accent-purple-600">
            <span class="text-sm font-medium text-gray-700">
                Require approval for every payment
                <span class="ml-1 text-xs px-2 py-0.5 rounded-full <?php echo $payment_approval_on ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>">
                    <?php echo $payment_approval_on ? 'ON' : 'OFF'; ?>
                </span>
            </span>
        </label>
        <button type="submit" class="px-4 py-2 bg-purple-600 text-white text-sm font-semibold rounded-lg hover:bg-purple-700">
            <i class="fas fa-save mr-1"></i>Save Policy
        </button>
    </form>
</div>

<!-- ═══ Dispatch Hold Policy (Feature #5) ═════════════════════════════════════ -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
    <h2 class="text-lg font-bold text-gray-800 mb-1 flex items-center gap-2">
        <i class="fas fa-hand-paper text-amber-600"></i> Dispatch Hold Policy
    </h2>
    <p class="text-sm text-gray-500 mb-4">
        When ON, <strong>every credit order is held from dispatch</strong> until Accounts or Admin
        explicitly clears it in Payment Watch — a dispatched/shipped status alone never releases the hold.
        Turn OFF to revert to per-order holds set at approval only.
    </p>
    <form method="POST" class="flex items-center gap-4">
        <input type="hidden" name="action" value="save_dispatch_hold">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <input type="hidden" name="dispatch_global_hold" value="0">
        <label class="inline-flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="dispatch_global_hold" value="1"
                   <?php echo $dispatch_hold_on ? 'checked' : ''; ?>
                   class="w-5 h-5 accent-amber-600">
            <span class="text-sm font-medium text-gray-700">
                Hold every order for dispatch clearance
                <span class="ml-1 text-xs px-2 py-0.5 rounded-full <?php echo $dispatch_hold_on ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>">
                    <?php echo $dispatch_hold_on ? 'ON' : 'OFF'; ?>
                </span>
            </span>
        </label>
        <button type="submit" class="px-4 py-2 bg-amber-600 text-white text-sm font-semibold rounded-lg hover:bg-amber-700">
            <i class="fas fa-save mr-1"></i>Save Policy
        </button>
    </form>
</div>

<!-- ═══ Company Logo ══════════════════════════════════════════════════════════ -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
    <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
        <i class="fas fa-image text-blue-600"></i> Company Logo
        <span class="text-sm font-normal text-gray-500 ml-2">Appears on printed invoices</span>
    </h2>
    <div class="flex flex-col md:flex-row gap-6 items-start">
        <!-- Current logo preview -->
        <div class="flex-shrink-0 w-36 h-28 border-2 border-dashed border-gray-300 rounded-lg flex items-center justify-center bg-gray-50 overflow-hidden">
            <?php if ($current_logo_url): ?>
            <img src="<?php echo htmlspecialchars($current_logo_url); ?>" alt="Current Logo"
                 class="max-h-24 max-w-full object-contain p-2" id="logoPreview">
            <?php else: ?>
            <div class="text-center text-gray-400 p-2" id="logoPreviewPlaceholder">
                <i class="fas fa-image text-3xl mb-1"></i>
                <p class="text-xs">No logo</p>
            </div>
            <img src="" alt="" class="hidden max-h-24 max-w-full object-contain p-2" id="logoPreview">
            <?php endif; ?>
        </div>

        <!-- Upload form -->
        <div class="flex-1">
            <form method="POST" enctype="multipart/form-data" class="space-y-3">
                <input type="hidden" name="action" value="save_company_logo">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Upload new logo</label>
                    <input type="file" name="company_logo" accept="image/png,image/jpeg,image/gif,image/webp"
                           class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer"
                           onchange="previewLogo(this)">
                    <p class="text-xs text-gray-500 mt-1">Accepted: PNG, JPG, GIF, WebP — max 2 MB. Recommended: transparent PNG, ~300×100 px.</p>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-semibold">
                        <i class="fas fa-upload mr-2"></i>Upload Logo
                    </button>
                    <?php if ($current_logo_url): ?>
                    <button type="submit" name="remove_logo" value="1"
                            onclick="return confirm('Remove the company logo?')"
                            class="px-5 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm font-semibold">
                        <i class="fas fa-trash mr-2"></i>Remove Logo
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function previewLogo(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = document.getElementById('logoPreview');
        const ph  = document.getElementById('logoPreviewPlaceholder');
        img.src = e.target.result;
        img.classList.remove('hidden');
        if (ph) ph.classList.add('hidden');
    };
    reader.readAsDataURL(input.files[0]);
}
</script>
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<!-- ═══ Branch / Factory Settings ══════════════════════════════════════════ -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
    <h2 class="text-lg font-bold text-gray-800 mb-1 flex items-center gap-2">
        <i class="fas fa-industry text-green-600"></i> Branch / Factory Addresses
        <span class="text-sm font-normal text-gray-500 ml-2">Printed on invoices based on the order's assigned branch</span>
    </h2>
    <p class="text-sm text-gray-500 mb-5">Each invoice will automatically show the address of the branch the order was assigned to.</p>

    <form method="POST">
        <input type="hidden" name="action" value="save_branches">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

        <div class="space-y-4">
        <?php foreach ($branches as $br): ?>
        <input type="hidden" name="branch_id[<?php echo $br->id; ?>]" value="<?php echo $br->id; ?>">
        <div class="border border-gray-200 rounded-lg p-4">
            <div class="flex items-center gap-3 mb-3">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold
                    <?php echo $br->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500'; ?>">
                    <?php echo htmlspecialchars($br->code ?? $br->id); ?>
                </span>
                <div class="flex-1">
                    <input type="text" name="branch_name[<?php echo $br->id; ?>]"
                           value="<?php echo htmlspecialchars($br->name ?? ''); ?>"
                           placeholder="Branch name"
                           class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-800 focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
                <label class="flex items-center gap-1.5 cursor-pointer flex-shrink-0 select-none">
                    <input type="checkbox"
                           name="is_factory[<?php echo $br->id; ?>]" value="1"
                           <?php echo ($br->is_factory ?? 0) ? 'checked' : ''; ?>
                           class="w-4 h-4 rounded text-green-600 border-gray-300 focus:ring-green-500">
                    <span class="text-xs text-gray-600 leading-tight">
                        Factory<br>
                        <span class="text-[10px] text-gray-400">pricing active</span>
                    </span>
                </label>
                <?php if ($br->status !== 'active'): ?>
                <span class="text-xs text-gray-400 italic">inactive</span>
                <?php endif; ?>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Factory Address (appears on invoice)</label>
                    <textarea name="branch_address[<?php echo $br->id; ?>]" rows="2"
                              placeholder="e.g. ১৭, নুরাইবাগ, ডেমরা, ঢাকা-১৩৬১"
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500"><?php echo htmlspecialchars($br->address ?? ''); ?></textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Phone Number</label>
                    <input type="text" name="branch_phone[<?php echo $br->id; ?>]"
                           value="<?php echo htmlspecialchars($br->phone_number ?? ''); ?>"
                           placeholder="e.g. +880-1XXX-XXXXXX"
                           class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <div class="mt-4 flex items-center gap-3">
            <button type="submit"
                    class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
                <i class="fas fa-save mr-2"></i>Save Branch Settings
            </button>
        </div>
    </form>

    <!-- Add New Branch -->
    <div class="mt-5 pt-5 border-t border-gray-200">
        <button type="button" id="toggleAddBranch"
                onclick="document.getElementById('addBranchPanel').classList.toggle('hidden');this.classList.toggle('hidden');"
                class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition">
            <i class="fas fa-plus"></i> Add New Branch
        </button>

        <div id="addBranchPanel" class="hidden mt-4">
            <form method="POST" class="border-2 border-dashed border-blue-300 rounded-xl p-5 bg-blue-50 space-y-4">
                <input type="hidden" name="action" value="add_branch">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <h3 class="text-sm font-bold text-blue-800 flex items-center gap-2">
                    <i class="fas fa-plus-circle"></i> New Branch
                </h3>

                <div class="flex flex-wrap items-start gap-3">
                    <div class="flex-1 min-w-[180px]">
                        <label class="block text-xs font-medium text-gray-700 mb-1">Branch Name <span class="text-red-500">*</span></label>
                        <input type="text" name="new_branch_name" required
                               placeholder="e.g. Chittagong Factory"
                               class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-800 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div class="w-32">
                        <label class="block text-xs font-medium text-gray-700 mb-1">Code <span class="text-gray-400">(auto)</span></label>
                        <input type="text" name="new_branch_code" maxlength="10"
                               placeholder="e.g. CTG"
                               class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm uppercase focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div class="flex-shrink-0 pt-5">
                        <label class="flex items-center gap-1.5 cursor-pointer select-none">
                            <input type="checkbox" name="new_branch_factory" value="1"
                                   class="w-4 h-4 rounded text-green-600 border-gray-300 focus:ring-green-500">
                            <span class="text-xs text-gray-600 leading-tight">
                                Factory<br><span class="text-[10px] text-gray-400">pricing active</span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Address (appears on invoice)</label>
                        <textarea name="new_branch_address" rows="2"
                                  placeholder="Full address…"
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Phone Number</label>
                        <input type="text" name="new_branch_phone"
                               placeholder="e.g. +880-1XXX-XXXXXX"
                               class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <div class="flex gap-3">
                    <button type="submit"
                            class="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-semibold transition">
                        <i class="fas fa-plus mr-1"></i> Add Branch
                    </button>
                    <button type="button"
                            onclick="document.getElementById('addBranchPanel').classList.add('hidden');document.getElementById('toggleAddBranch').classList.remove('hidden');"
                            class="px-5 py-2 border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50 text-sm transition">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

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
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
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
                             data-widget-name="<?php echo htmlspecialchars($widget->widget_name); ?>"
                             data-widget-icon="<?php echo htmlspecialchars($widget->icon ?? 'fas fa-chart-bar'); ?>"
                             data-widget-color="<?php echo htmlspecialchars($widget->color ?? 'gray'); ?>">
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
            

            <!-- AI Advisor Configuration Card -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden border-l-4 border-purple-500" id="ai-advisor-section">
                <div class="p-4 bg-purple-50 border-b border-purple-200 flex justify-between items-center">
                    <h2 class="text-xl font-bold text-gray-900">
                        <i class="fas fa-robot text-purple-600 mr-2"></i>
                        AI Business Advisor
                    </h2>
                    <span class="text-xs bg-purple-600 text-white px-2 py-1 rounded-full font-semibold">Groq Powered</span>
                </div>
                <div class="p-6 space-y-5">
                    <p class="text-sm text-gray-600">
                        The AI Advisor analyzes your live ERP data (sales, receivables, inventory, production) and delivers smart business insights.
                        It appears as a collapsible sidebar panel on your dashboard.
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="border rounded-lg p-4 bg-gradient-to-br from-purple-50 to-white">
                            <div class="flex items-center gap-2 mb-2">
                                <i class="fas fa-sun text-yellow-500"></i>
                                <span class="font-bold text-gray-800 text-sm">Daily Brief</span>
                                <span class="text-xs bg-green-100 text-green-700 px-1.5 py-0.5 rounded font-medium">Auto-loads</span>
                            </div>
                            <p class="text-xs text-gray-600">Morning snapshot with highlights, urgent actions, and one strategic tip. Auto-displays as a banner when you open the dashboard.</p>
                        </div>
                        <div class="border rounded-lg p-4 bg-gradient-to-br from-green-50 to-white">
                            <div class="flex items-center gap-2 mb-2">
                                <i class="fas fa-coins text-green-500"></i>
                                <span class="font-bold text-gray-800 text-sm">Cash Flow Analysis</span>
                            </div>
                            <p class="text-xs text-gray-600">Collection efficiency, expense trends, which customers to chase, and overall liquidity assessment.</p>
                        </div>
                        <div class="border rounded-lg p-4 bg-gradient-to-br from-red-50 to-white">
                            <div class="flex items-center gap-2 mb-2">
                                <i class="fas fa-shield-alt text-red-500"></i>
                                <span class="font-bold text-gray-800 text-sm">Credit Risk Report</span>
                            </div>
                            <p class="text-xs text-gray-600">Identifies over-limit customers, flags risky accounts, and recommends credit actions per customer.</p>
                        </div>
                        <div class="border rounded-lg p-4 bg-gradient-to-br from-orange-50 to-white">
                            <div class="flex items-center gap-2 mb-2">
                                <i class="fas fa-industry text-orange-500"></i>
                                <span class="font-bold text-gray-800 text-sm">Operations Briefing</span>
                            </div>
                            <p class="text-xs text-gray-600">Production pipeline, inventory gaps, procurement status, and factory floor priorities for the day.</p>
                        </div>
                        <div class="border rounded-lg p-4 bg-gradient-to-br from-blue-50 to-white">
                            <div class="flex items-center gap-2 mb-2">
                                <i class="fas fa-chart-line text-blue-500"></i>
                                <span class="font-bold text-gray-800 text-sm">Sales Analysis</span>
                            </div>
                            <p class="text-xs text-gray-600">Day-over-day comparison, month projection, bottleneck identification, and sales acceleration tips.</p>
                        </div>
                        <div class="border rounded-lg p-4 bg-gradient-to-br from-indigo-50 to-white">
                            <div class="flex items-center gap-2 mb-2">
                                <i class="fas fa-comments text-indigo-500"></i>
                                <span class="font-bold text-gray-800 text-sm">Ask Anything</span>
                            </div>
                            <p class="text-xs text-gray-600">Free-form questions answered using live ERP context. e.g. "Which customers haven't paid this month?"</p>
                        </div>
                    </div>

                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                        <p class="text-xs font-bold text-gray-600 uppercase tracking-wide mb-2">
                            <i class="fas fa-key mr-1"></i>API Configuration (config.php)
                        </p>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border">
                                <?php $groq_set = defined('GROQ_API_KEY') && GROQ_API_KEY; ?>
                                <span class="w-2 h-2 rounded-full <?php echo $groq_set ? 'bg-green-500' : 'bg-red-400'; ?>"></span>
                                <span class="font-semibold">Groq (Primary)</span>
                                <span class="text-gray-500 ml-auto"><?php echo $groq_set ? 'Configured ✓' : 'Not set'; ?></span>
                            </div>
                            <div class="flex items-center gap-2 p-2 bg-white rounded border">
                                <?php $gemini_set = defined('GEMINI_API_KEY') && GEMINI_API_KEY; ?>
                                <span class="w-2 h-2 rounded-full <?php echo $gemini_set ? 'bg-green-500' : 'bg-yellow-400'; ?>"></span>
                                <span class="font-semibold">Gemini (Fallback)</span>
                                <span class="text-gray-500 ml-auto"><?php echo $gemini_set ? 'Configured ✓' : 'Not set'; ?></span>
                            </div>
                            <div class="flex items-center gap-2 p-2 bg-white rounded border">
                                <?php $anthropic_set = defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY; ?>
                                <span class="w-2 h-2 rounded-full <?php echo $anthropic_set ? 'bg-green-500' : 'bg-gray-300'; ?>"></span>
                                <span class="font-semibold">Claude (Last resort)</span>
                                <span class="text-gray-500 ml-auto"><?php echo $anthropic_set ? 'Configured ✓' : 'Optional'; ?></span>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-2">
                            <i class="fas fa-info-circle text-blue-500 mr-1"></i>
                            The AI advisor calls each provider in order and falls back automatically on failure.
                            All API keys are defined in <code class="bg-gray-200 px-1 rounded">core/config.php</code>.
                        </p>
                    </div>
                </div>
            </div>

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
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
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
            const widgetId   = widgetCard.dataset.widgetId;
            const widgetName = widgetCard.dataset.widgetName;
            // Read icon directly from data attribute — avoids fragile DOM traversal
            const iconClass  = widgetCard.dataset.widgetIcon || 'fas fa-chart-bar';
            const iconColor  = widgetCard.dataset.widgetColor || 'gray';
            
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
                        <i class="fas ${iconClass} text-${iconColor}-500 mr-2"></i>
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
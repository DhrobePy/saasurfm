<?php
require_once '../core/init.php';

// --- SECURITY & CONTEXT ---
$allowed_roles = [
    'Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra',
    'accountspos-demra', 'accountspos-srg', 'production manager-srg', 'production manager-demra',
    'dispatch-srg', 'dispatch-demra', 'dispatchpos-demra', 'dispatchpos-srg',
    'sales-srg', 'sales-demra', 'sales-other', 'collector'
];
restrict_access($allowed_roles); // Allow all logged-in users to see their dashboard

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'];
$pageTitle = 'Dashboard';
$error = null;
$dashboard_modules = []; // This will hold the keys of modules to show

// --- MASTER MODULE LIST (Must match settings.php) ---
// We need this to get file paths, etc.
$master_modules_config = [
    'welcome' => ['file' => 'welcome.php', 'col_span' => 'lg:col-span-2'],
    'quick_links' => ['file' => 'quick_links.php', 'col_span' => 'lg:col-span-1'],
    'pos_sales_summary' => ['file' => 'pos_sales_summary.php', 'col_span' => 'lg:col-span-1'],
    'pending_approvals' => ['file' => 'pending_approvals.php', 'col_span' => 'lg:col-span-2'],
    'key_account_balances' => ['file' => 'key_account_balances.php', 'col_span' => 'lg:col-span-1'],
    // ... add all other modules here
];


// --- LOAD USER'S DASHBOARD PREFERENCES ---
try {
    $user_data = $db->query("SELECT dashboard_preferences FROM users WHERE id = ?", [$user_id])->first();
    
    if ($user_data && $user_data->dashboard_preferences) {
        $dashboard_modules = json_decode($user_data->dashboard_preferences, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Handle corrupted JSON
            $error = "Could not load dashboard layout. Using default.";
            $dashboard_modules = ['welcome', 'quick_links']; // Safe default
        }
    } else {
        // No preferences set, use a default layout
        $dashboard_modules = ['welcome', 'quick_links', 'pos_sales_summary'];
    }
} catch (Exception $e) {
    $error = "Error loading dashboard: " . $e->getMessage();
}

require_once '../templates/header.php';
?>

<!-- ======================================== -->
<!-- Page Header -->
<!-- ======================================== -->
<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900">Welcome, <?php echo htmlspecialchars($currentUser['display_name']); ?>!</h1>
    <p class="text-lg text-gray-600 mt-1">
        Here is a summary of your operations for <?php echo date('l, F j, Y'); ?>.
    </p>
</div>

<?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
        <p class="font-bold">Error</p>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>
<?php endif; ?>

<!-- ======================================== -->
<!-- Dynamic Dashboard Grid -->
<!-- ======================================== -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <?php if (empty($dashboard_modules)): ?>
        <div class="lg:col-span-3 bg-white rounded-lg shadow-md border p-12 text-center">
            <i class="fas fa-th-large text-5xl text-gray-300 mb-4"></i>
            <h2 class="text-xl font-semibold text-gray-700">Your dashboard is empty.</h2>
            <p class="text-gray-500 mt-2">
                You can add widgets to your dashboard from the settings page.
            </p>
            <a href="settings.php" class="mt-4 inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                Go to Dashboard Settings
            </a>
        </div>
    <?php else: ?>
        <?php
        // Loop through the user's preferred modules and include the widget file
        foreach ($dashboard_modules as $module_key) {
            if (isset($master_modules_config[$module_key])) {
                $config = $master_modules_config[$module_key];
                $widget_file = __DIR__ . '/widgets/' . $config['file'];
                
                // Set the column span for the widget
                echo '<div class="' . htmlspecialchars($config['col_span']) . '">';
                
                if (file_exists($widget_file)) {
                    include $widget_file; // This includes the widget
                } else {
                    // Show an error if the widget file is missing
                    echo '<div class="bg-red-50 border border-red-200 rounded-lg p-4 h-full">';
                    echo '<p class="font-bold text-red-700">Error</p>';
                    echo '<p class="text-sm text-red-600">Widget file not found: <code>' . htmlspecialchars($widget_file) . '</code></p>';
                    echo '</div>';
                }
                
                echo '</div>'; // Close column span div
            }
        }
        ?>
    <?php endif; ?>

</div>

<?php
require_once '../templates/footer.php';
?>

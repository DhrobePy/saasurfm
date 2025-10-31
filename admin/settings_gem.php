<?php
require_once '../core/init.php';

// --- SECURITY & CONTEXT ---
$allowed_roles = ['Superadmin', 'admin'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'];
$pageTitle = "Customize Dashboard";
$error = null;
$success = null;

// --- MASTER MODULE LIST ---
// This is the master list of all available dashboard widgets.
// 'key' is the file name (e.g., 'welcome.php') and must be unique.
$master_modules = [
    'core' => [
        ['key' => 'welcome', 'name' => 'Welcome Summary', 'description' => 'A quick overview and welcome message.'],
        ['key' => 'quick_links', 'name' => 'Quick Links', 'description' => 'Buttons for common actions like "New Order" or "New Transaction".']
    ],
    'pos' => [
        ['key' => 'pos_sales_summary', 'name' => 'POS Sales Summary', 'description' => "Today's total sales, orders, and average from the POS."],
        ['key' => 'pos_top_products', 'name' => 'Top Selling Products (POS)', 'description' => 'A list of today\'s best-selling products.']
    ],
    'sales' => [
        ['key' => 'pending_approvals', 'name' => 'Pending Credit Orders', 'description' => 'A list of credit orders awaiting approval.'],
        ['key' => 'sales_team_performance', 'name' => 'Sales Team Performance', 'description' => 'Chart showing sales by sales representative. (Coming Soon)']
    ],
    'accounting' => [
        ['key' => 'key_account_balances', 'name' => 'Key Account Balances', 'description' => 'Live balances for your main Bank and Cash accounts.'],
        ['key' => 'receivables_summary', 'name' => 'A/R Receivables Summary', 'description' => 'Total amount owed by credit customers.']
    ]
];

// --- Get User's Current Preferences ---
try {
    $user_data = $db->query("SELECT dashboard_preferences FROM users WHERE id = ?", [$user_id])->first();
    $current_prefs_json = $user_data->dashboard_preferences;
    
    if ($current_prefs_json) {
        $current_prefs = json_decode($current_prefs_json, true);
    } else {
        // Default layout for new users
        $current_prefs = ['welcome', 'quick_links', 'pos_sales_summary', 'pending_approvals'];
    }
} catch (Exception $e) {
    $error = "Error loading preferences: " . $e->getMessage();
    $current_prefs = [];
}

require_once '../templates/header.php';
?>

<!-- Include Alpine.js Sortable plugin -->
<script src="https://cdn.jsdelivr.net/npm/@alpinejs/sort@3.x.x/dist/cdn.min.js"></script>

<!-- ======================================== -->
<!-- Page Header -->
<!-- ======================================== -->
<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">
        Select and reorder the widgets you want to see on your main dashboard.
    </p>
</div>

<?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
        <p class="font-bold">Error</p>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>
<?php endif; ?>

<!-- ======================================== -->
<!-- Settings Interface -->
<!-- ======================================== -->
<div x-data="dashboardSettings(<?php echo htmlspecialchars(json_encode($master_modules), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($current_prefs), ENT_QUOTES); ?>)">
    
    <!-- Save Status -->
    <div x-show="saveStatus" x-cloak x-transition
         class="mb-4 p-4 rounded-lg"
         :class="{ 'bg-green-100 border-green-500 text-green-700': saveStatus === 'success', 'bg-red-100 border-red-500 text-red-700': saveStatus === 'error' }">
        <p class="font-bold" x-text="saveMessage"></p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Left Column: Available Modules -->
        <div class="lg:col-span-1 bg-white p-6 rounded-xl shadow-md border border-gray-200">
            <h2 class="text-xl font-bold text-gray-800 mb-4 pb-2 border-b">Available Modules</h2>
            <div class="space-y-4">
                <?php foreach ($master_modules as $category => $modules): ?>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2"><?php echo htmlspecialchars($category); ?></h3>
                        <div class="space-y-2">
                            <?php foreach ($modules as $module): ?>
                                <div class="p-3 border rounded-lg bg-gray-50 hover:bg-gray-100"
                                     x-show="!isSelected('<?php echo $module['key']; ?>')">
                                    <h4 class="font-semibold text-gray-900"><?php echo htmlspecialchars($module['name']); ?></h4>
                                    <p class="text-xs text-gray-600 mb-2"><?php echo htmlspecialchars($module['description']); ?></p>
                                    <button @click="addModule('<?php echo $module['key']; ?>')"
                                            class="text-sm font-medium text-primary-600 hover:text-primary-800">
                                        + Add to Dashboard
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Right Column: Active Dashboard Layout -->
        <div class="lg:col-span-2">
            <div class="bg-white p-6 rounded-xl shadow-md border border-gray-200">
                <div class="flex justify-between items-center mb-4 pb-2 border-b">
                    <h2 class="text-xl font-bold text-gray-800">Your Dashboard Layout</h2>
                    <button @click="saveSettings" :disabled="isSaving"
                            class="px-5 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50">
                        <i class="fas fa-save mr-2" x-show="!isSaving"></i>
                        <i class="fas fa-spinner fa-spin mr-2" x-show="isSaving" x-cloak></i>
                        <span x-text="isSaving ? 'Saving...' : 'Save Settings'"></span>
                    </button>
                </div>
                
                <p class="text-sm text-gray-500 mb-4">Drag and drop the handles <i class="fas fa-grip-vertical"></i> to reorder your dashboard.</p>

                <!-- Sortable List -->
                <div x-ref="sortableList" class="space-y-3">
                    <template x-for="moduleKey in activeModules" :key="moduleKey">
                        <div class="flex items-center p-4 border rounded-lg bg-white shadow-sm">
                            <i class="fas fa-grip-vertical text-gray-400 mr-4 cursor-grab" x-sortable-handle></i>
                            <div class="flex-grow">
                                <h4 class="font-semibold text-gray-900" x-text="getModuleName(moduleKey)"></h4>
                                <p class="text-xs text-gray-600" x-text="getModuleDescription(moduleKey)"></p>
                            </div>
                            <button @click="removeModule(moduleKey)"
                                    class="text-red-500 hover:text-red-700 ml-4" title="Remove">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </template>
                </div>
                
                <template x-if="activeModules.length === 0">
                    <div class="text-center py-10 border border-dashed rounded-lg">
                        <p class="text-gray-500">Your dashboard is empty.</p>
                        <p class="text-sm text-gray-400 mt-1">Add modules from the "Available Modules" list.</p>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('dashboardSettings', (masterModules, currentPrefs) => ({
        masterModules: masterModules,
        activeModules: currentPrefs,
        isSaving: false,
        saveStatus: '', // 'success' or 'error'
        saveMessage: '',
        
        init() {
            // Initialize Alpine.js Sortable
             Alpine.plugin(Sortable).init(this.$refs.sortableList, this.activeModules);
        },

        getModuleName(key) {
            for (const category in this.masterModules) {
                const found = this.masterModules[category].find(m => m.key === key);
                if (found) return found.name;
            }
            return 'Unknown Module';
        },
        getModuleDescription(key) {
            for (const category in this.masterModules) {
                const found = this.masterModules[category].find(m => m.key === key);
                if (found) return found.description;
            }
            return '';
        },
        isSelected(key) {
            return this.activeModules.includes(key);
        },
        addModule(key) {
            if (!this.isSelected(key)) {
                this.activeModules.push(key);
            }
        },
        removeModule(key) {
            this.activeModules = this.activeModules.filter(m => m !== key);
        },
        async saveSettings() {
            this.isSaving = true;
            this.saveStatus = '';
            this.saveMessage = '';

            try {
                const response = await fetch('ajax_handler.php', { // Assumes ajax_handler.php is in same /admin/ folder
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': '<?php echo $_SESSION['csrf_token']; ?>' // Add CSRF
                    },
                    body: JSON.stringify({
                        action: 'save_dashboard_settings',
                        preferences: this.activeModules
                    })
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.error || 'Failed to save settings.');
                }

                this.saveStatus = 'success';
                this.saveMessage = 'Dashboard settings saved successfully!';

            } catch (error) {
                console.error('Save Settings Error:', error);
                this.saveStatus = 'error';
                this.saveMessage = error.message;
            } finally {
                this.isSaving = false;
                setTimeout(() => this.saveStatus = '', 3000); // Hide message after 3s
            }
        }
    }));
});
</script>

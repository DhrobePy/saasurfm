<?php
require_once '../core/init.php';

// --- SECURITY ---
// Set which roles can access this page.
$allowed_roles = [
    'Superadmin', 
    'admin', 
    'production manager-srg',
    'production manager-demra',
    'dispatch-srg',
    'dispatch-demra',
    'dispatchpos-demra',
    'dispatchpos-srg'
];
restrict_access($allowed_roles);

// Get the $db instance
global $db; 

// --- VARIABLE INITIALIZATION ---
$pageTitle = 'Manage Stock Levels';
$variant_id = null;
$variant = null;

// --- LOGIC: GET PRODUCT VARIANT ---
// This page REQUIRES a variant_id.
if (!isset($_GET['variant_id'])) {
    $_SESSION['error_flash'] = 'No product variant selected. Please select a variant to manage its stock.';
    header('Location: base_products.php');
    exit();
}

$variant_id = (int)$_GET['variant_id'];
// Fetch the variant and its base product name
$variant = $db->query(
    "SELECT pv.*, p.base_name 
     FROM product_variants pv
     JOIN products p ON pv.product_id = p.id
     WHERE pv.id = ?", 
    [$variant_id]
)->first();

// If the variant doesn't exist, redirect back.
if (!$variant) {
    $_SESSION['error_flash'] = 'Invalid product variant ID.';
    header('Location: base_products.php');
    exit();
}

// Set page title to include variant name
$pageTitle = 'Stock for: ' . htmlspecialchars($variant->sku);

// --- LOGIC: HANDLE POST REQUEST (UPDATE STOCK) ---
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
        
        $branch_id = (int)$_POST['branch_id'];
        $quantity = (int)$_POST['quantity'];

        if ($quantity < 0) {
             $_SESSION['error_flash'] = 'Quantity cannot be negative.';
        } else {
            // This is the safest way to update inventory.
            // It will CREATE a new record if one doesn't exist for this variant/branch combo,
            // or UPDATE the quantity if it does.
            $db->query(
                "INSERT INTO inventory (variant_id, branch_id, quantity) 
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantity = ?",
                [$variant_id, $branch_id, $quantity, $quantity]
            );

            $_SESSION['success_flash'] = 'Stock quantity updated successfully.';
        }
        
        header('Location: inventory.php?variant_id=' . $variant_id); // Redirect to refresh data
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error_flash'] = 'Database Error: ' . $e->getMessage();
    header('Location: inventory.php?variant_id=' . $variant_id);
    exit();
}

// --- DATA: GET BRANCHES AND CURRENT INVENTORY ---
$branches = $db->query("SELECT * FROM branches WHERE status = 'active' ORDER BY name ASC")->results();

// Get all inventory records for this variant
$inventory_records_raw = $db->query(
    "SELECT branch_id, quantity 
     FROM inventory 
     WHERE variant_id = ?", 
    [$variant_id]
)->results();

// Re-format inventory records into an associative array for easy lookup
// (e.g., $inventory_levels[branch_id] => quantity)
$inventory_levels = [];
foreach ($inventory_records_raw as $record) {
    $inventory_levels[$record->branch_id] = $record->quantity;
}

// --- Include Header ---
require_once '../templates/header.php'; 
?>

<!-- ======================================== -->
<!-- 1. PAGE HEADER & NAVIGATION -->
<!-- ======================================== -->
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Manage Stock Levels</h1>
        <p class="text-lg text-gray-600">
            Variant: 
            <strong class="text-primary-600"><?php echo htmlspecialchars($variant->sku); ?></strong>
            (<?php echo htmlspecialchars($variant->base_name . ' - ' . $variant->weight_variant . ' / ' . $variant->grade); ?>)
        </p>
    </div>
    <a href="products.php" class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
        <i class="fas fa-arrow-left mr-2"></i>Back to Products List
    </a>
</div>

<!-- ======================================== -->
<!-- 2. INVENTORY LIST BY BRANCH -->
<!-- ======================================== -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <h3 class="text-xl font-bold text-gray-800 p-6 border-b border-gray-200">
        Stock Levels by Factory / Branch
    </h3>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Factory / Branch</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Quantity</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Set New Quantity</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($branches)): ?>
                    <tr>
                        <td colspan="3" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                            No active branches found. Please add branches in settings.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($branches as $branch): 
                        // Get current quantity for this branch, default to 0
                        $current_quantity = $inventory_levels[$branch->id] ?? 0;
                    ?>
                        <tr>
                            <!-- Branch Name -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($branch->name); ?>
                            </td>
                            
                            <!-- Current Quantity -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold <?php echo ($current_quantity > 0) ? 'text-gray-900' : 'text-red-600'; ?>">
                                <?php echo $current_quantity; ?> 
                                <span class="text-gray-500 font-normal">(<?php echo htmlspecialchars($variant->unit_of_measure); ?>)</span>
                            </td>

                            <!-- Update Form -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <form action="inventory.php?variant_id=<?php echo $variant_id; ?>" method="POST" class="flex items-center space-x-2">
                                    <input type="hidden" name="update_stock" value="1">
                                    <input type="hidden" name="branch_id" value="<?php echo $branch->id; ?>">
                                    
                                    <input type="number" name="quantity" 
                                           class="w-32 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-sm"
                                           value="<?php echo $current_quantity; ?>"
                                           placeholder="Set Qty"
                                           required>
                                           
                                    <button type="submit" class="px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none">
                                        Update
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
// --- Include Footer ---
require_once '../templates/footer.php'; 
?>

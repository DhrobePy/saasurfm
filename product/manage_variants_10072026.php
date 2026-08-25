<?php
require_once '../core/init.php';

// --- SECURITY ---
// Set which roles can access this page.
$allowed_roles = [
    'Superadmin', 
    'admin', 
    'production manager-srg', 
    'production manager-demra'
];
restrict_access($allowed_roles);

// Get the $db instance
global $db; 

// --- VARIABLE INITIALIZATION ---
$pageTitle = 'Manage Product Variants';
$edit_mode = false;
$variant_to_edit = null;
$form_action = 'add_variant';
$product_id = null;
$base_product = null;

// --- LOGIC: GET BASE PRODUCT ---
// This page REQUIRES a product_id.
if (!isset($_GET['product_id'])) {
    $_SESSION['error_flash'] = 'No product selected. Please select a product to manage its variants.';
    header('Location: base_products.php');
    exit();
}

$product_id = (int)$_GET['product_id'];
$base_product = $db->query("SELECT * FROM products WHERE id = ?", [$product_id])->first();

// If the product doesn't exist, redirect back.
if (!$base_product) {
    $_SESSION['error_flash'] = 'Invalid product ID.';
    header('Location: base_products.php');
    exit();
}

// Set page title to include product name
$pageTitle = 'Variants for: ' . htmlspecialchars($base_product->base_name);


// --- HELPER FUNCTION: SKU GENERATOR ---
/**
 * Generates a clean, unique SKU.
 * Example: 'Ujjwal Super Enamel', '1 Litre', 'A-Grade' -> 'USE-1L-A'
 * @param string $base_sku The product's base SKU (e.g., 'USE')
 * @param string $weight The weight variant (e.g., '1 Litre', '500 gm')
 * @param string $grade The grade variant (e.g., 'A-Grade')
 * @return string The generated SKU (e.g., 'USE-1L-A')
 */
function generate_sku($base_sku, $weight, $grade) {
    // 1. Clean Base SKU (already clean, but good practice)
    $sku_prefix = strtoupper(trim($base_sku));

    // 2. Clean Weight (e.g., "1 Litre" -> "1L", "500 gm" -> "500GM")
    $sku_weight = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $weight));
    
    // 3. Clean Grade (e.g., "A-Grade" -> "A", "B Grade" -> "B")
    $sku_grade = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $grade));
    // Just take the first character if it's like "A-Grade"
    if (strlen($sku_grade) > 1) {
        $sku_grade = substr($sku_grade, 0, 1); 
    }

    return $sku_prefix . '-' . $sku_weight . '-' . $sku_grade;
}


// --- LOGIC: HANDLE POST REQUESTS (ADD & UPDATE) ---
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // --- ADD NEW VARIANT ---
        if (isset($_POST['add_variant'])) {
            // Auto-generate the SKU
            $generated_sku = generate_sku(
                $base_product->base_sku,
                $_POST['weight_variant'],
                $_POST['grade']
            );
            
            $db->query("INSERT INTO product_variants (product_id, sku, weight_variant, grade, unit_of_measure, status) VALUES (?, ?, ?, ?, ?, ?)", [
                $product_id,
                $generated_sku,
                $_POST['weight_variant'],
                $_POST['grade'],
                $_POST['unit_of_measure'],
                $_POST['status']
            ]);
            $_SESSION['success_flash'] = 'Product Variant successfully added.';
            header('Location: manage_variants.php?product_id=' . $product_id); // Redirect to clean form
            exit();
        }

        // --- UPDATE EXISTING VARIANT ---
        if (isset($_POST['update_variant'])) {
            $variant_id = (int)$_POST['variant_id'];

            // Re-generate the SKU in case details changed
            $generated_sku = generate_sku(
                $base_product->base_sku,
                $_POST['weight_variant'],
                $_POST['grade']
            );

            $db->query("UPDATE product_variants SET sku = ?, weight_variant = ?, grade = ?, unit_of_measure = ?, status = ? WHERE id = ? AND product_id = ?", [
                $generated_sku,
                $_POST['weight_variant'],
                $_POST['grade'],
                $_POST['unit_of_measure'],
                $_POST['status'],
                $variant_id,
                $product_id
            ]);
            $_SESSION['success_flash'] = 'Product Variant successfully updated.';
            header('Location: manage_variants.php?product_id=' . $product_id); // Redirect to clear edit state
            exit();
        }
    }

    // --- LOGIC: HANDLE GET REQUESTS (EDIT & DELETE) ---

    // --- DELETE VARIANT ---
    if (isset($_GET['delete'])) {
        $delete_id = (int)$_GET['delete'];
        // Note: ON DELETE CASCADE will also delete inventory and pricing.
        $db->query("DELETE FROM product_variants WHERE id = ? AND product_id = ?", [$delete_id, $product_id]);
        $_SESSION['success_flash'] = 'Product Variant successfully deleted.';
        header('Location: manage_variants.php?product_id=' . $product_id); // Redirect to remove query string
        exit();
    }

    // --- GET VARIANT TO EDIT ---
    if (isset($_GET['edit'])) {
        $edit_id = (int)$_GET['edit'];
        $variant_to_edit = $db->query("SELECT * FROM product_variants WHERE id = ? AND product_id = ?", [$edit_id, $product_id])->first();
        if ($variant_to_edit) {
            $edit_mode = true;
            $form_action = 'update_variant';
        }
    }

} catch (PDOException $e) {
    // Handle database errors
    if ($e->getCode() == '23000') { // Integrity constraint violation (e.g., duplicate SKU)
        $_SESSION['error_flash'] = 'Error: A variant with this SKU already exists. Change the weight or grade.';
    } else {
        $_SESSION['error_flash'] = 'Database Error: ' . $e->getMessage();
    }
    // Redirect back to the page to show the error
    header('Location: manage_variants.php?product_id=' . $product_id);
    exit();
} catch (Exception $e) {
    $_SESSION['error_flash'] = 'An unexpected error occurred: ' . $e->getMessage();
    header('Location: manage_variants.php?product_id=' . $product_id);
    exit();
}


// --- DATA: GET ALL VARIANTS FOR THIS PRODUCT ---
$variants = $db->query("SELECT * FROM product_variants WHERE product_id = ? ORDER BY weight_variant ASC, grade ASC", [$product_id])->results();

// --- Include Header ---
require_once '../templates/header.php'; 
?>

<!-- ======================================== -->
<!-- 1. PAGE HEADER & NAVIGATION -->
<!-- ======================================== -->
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Manage Variants</h1>
        <p class="text-lg text-gray-600">
            For Base Product: 
            <strong class="text-primary-600"><?php echo htmlspecialchars($base_product->base_name); ?></strong>
            (Base SKU: <?php echo htmlspecialchars($base_product->base_sku); ?>)
        </p>
    </div>
    <a href="base_products.php" class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
        <i class="fas fa-arrow-left mr-2"></i>Back to Base Products
    </a>
</div>

<!-- ======================================== -->
<!-- 2. ADD / EDIT VARIANT FORM -->
<!-- ======================================== -->
<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <h2 class="text-2xl font-bold text-gray-800 mb-4">
        <?php echo $edit_mode ? 'Edit Variant' : 'Add New Variant'; ?>
    </h2>
    
    <form action="manage_variants.php?product_id=<?php echo $product_id; ?>" method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-6">
        
        <!-- Hidden fields -->
        <input type="hidden" name="<?php echo $form_action; ?>" value="1">
        <?php if ($edit_mode): ?>
            <input type="hidden" name="variant_id" value="<?php echo htmlspecialchars($variant_to_edit->id); ?>">
        <?php endif; ?>

        <!-- Weight Variant -->
        <div>
            <label for="weight_variant" class="block text-sm font-medium text-gray-700 mb-1">Weight / Size <span class="text-red-500">*</span></label>
            <input type="text" id="weight_variant" name="weight_variant" required
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                   value="<?php echo htmlspecialchars($variant_to_edit->weight_variant ?? ''); ?>"
                   placeholder="e.g., 1 Litre or 50 KG">
        </div>

        <!-- Grade -->
        <div>
            <label for="grade" class="block text-sm font-medium text-gray-700 mb-1">Grade <span class="text-red-500">*</span></label>
            <input type="text" id="grade" name="grade" required
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                   value="<?php echo htmlspecialchars($variant_to_edit->grade ?? ''); ?>"
                   placeholder="e.g., A-Grade or B">
        </div>

        <!-- Unit of Measure -->
        <div>
            <label for="unit_of_measure" class="block text-sm font-medium text-gray-700 mb-1">Unit of Measure <span class="text-red-500">*</span></label>
            <select id="unit_of_measure" name="unit_of_measure" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                <option value="pcs" <?php echo ($variant_to_edit->unit_of_measure ?? 'pcs') === 'pcs' ? 'selected' : ''; ?>>Pieces (pcs)</option>
                <option value="litre" <?php echo ($variant_to_edit->unit_of_measure ?? '') === 'litre' ? 'selected' : ''; ?>>Litre (L)</option>
                <option value="kg" <?php echo ($variant_to_edit->unit_of_measure ?? '') === 'kg' ? 'selected' : ''; ?>>Kilogram (kg)</option>
                <option value="gm" <?php echo ($variant_to_edit->unit_of_measure ?? '') === 'gm' ? 'selected' : ''; ?>>Gram (gm)</option>
                <option value="bag" <?php echo ($variant_to_edit->unit_of_measure ?? '') === 'bag' ? 'selected' : ''; ?>>Bag</option>
            </select>
        </div>

        <!-- Status -->
        <div class="md:col-span-2">
            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select id="status" name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                <option value="active" <?php echo ($variant_to_edit->status ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo ($variant_to_edit->status ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>
        
        <!-- Auto-SKU Info & Submit -->
        <div class="md:col-span-3 flex items-center justify-between">
            <div class="text-sm text-gray-600">
                <i class="fas fa-info-circle mr-1 text-primary-600"></i>
                The unique SKU will be auto-generated. Price is set per-factory.
            </div>
            
            <div class="flex space-x-3">
                <?php if ($edit_mode): ?>
                    <a href="manage_variants.php?product_id=<?php echo $product_id; ?>" class="px-5 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Cancel
                    </a>
                    <button type="submit" class="px-5 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        <i class="fas fa-save mr-2"></i>Update Variant
                    </button>
                <?php else: ?>
                    <button type="submit" class="px-5 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        <i class="fas fa-plus mr-2"></i>Add Variant
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- ======================================== -->
<!-- 3. VARIANTS LIST TABLE -->
<!-- ======================================== -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SKU (Auto-Generated)</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Variant Details</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unit</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th scope="col" "class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($variants)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                            No variants found for this product. Start by adding one above.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($variants as $variant): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($variant->sku); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900"><?php echo htmlspecialchars($variant->weight_variant); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($variant->grade); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo htmlspecialchars($variant->unit_of_measure); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($variant->status == 'active'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                        Active
                                    </span>
                                <?php else: ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                        Inactive
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-3">
                                <!-- THIS IS THE IMPORTANT NEXT STEP -->
                                <a href="inventory.php?variant_id=<?php echo $variant->id; ?>" class="text-white bg-blue-600 hover:bg-blue-700 px-3 py-1 rounded-md text-xs font-medium">
                                    Manage Stock
                                </a>
                                <!-- THIS IS THE OTHER IMPORTANT NEXT STEP -->
                                <a href="pricing.php?variant_id=<?php echo $variant->id; ?>" class="text-white bg-green-600 hover:bg-green-700 px-3 py-1 rounded-md text-xs font-medium">
                                    Set Prices
                                </a>
                                <a href="manage_variants.php?product_id=<?php echo $product_id; ?>&edit=<?php echo $variant->id; ?>" class="text-primary-600 hover:text-primary-900" title="Edit Variant">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="manage_variants.php?product_id=<?php echo $product_id; ?>&delete=<?php echo $variant->id; ?>" class="text-red-600 hover:text-red-900" 
                                   title="Delete Variant"
                                   onclick="return confirm('Are you sure you want to delete this variant? This will also delete its inventory and pricing records. This action cannot be undone.');">
                                    <i class="fas fa-trash"></i>
                                </a>
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


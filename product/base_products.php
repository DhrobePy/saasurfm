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
$pageTitle = 'Base Product Management';
$edit_mode = false;
$product_to_edit = null;
$form_action = 'add_product';

// --- LOGIC: HANDLE POST REQUESTS (ADD & UPDATE) ---
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // --- ADD NEW PRODUCT ---
        if (isset($_POST['add_product'])) {
            $db->query("INSERT INTO products (base_name, base_sku, description, category, status) VALUES (?, ?, ?, ?, ?)", [
                $_POST['base_name'],
                $_POST['base_sku'],
                $_POST['description'],
                $_POST['category'],
                $_POST['status']
            ]);
            $_SESSION['success_flash'] = 'Base Product successfully added.';
            header('Location: base_products.php'); // Redirect to clean the form
            exit();
        }

        // --- UPDATE EXISTING PRODUCT ---
        if (isset($_POST['update_product'])) {
            $db->query("UPDATE products SET base_name = ?, base_sku = ?, description = ?, category = ?, status = ? WHERE id = ?", [
                $_POST['base_name'],
                $_POST['base_sku'],
                $_POST['description'],
                $_POST['category'],
                $_POST['status'],
                $_POST['product_id']
            ]);
            $_SESSION['success_flash'] = 'Base Product successfully updated.';
            header('Location: base_products.php'); // Redirect to clear edit state
            exit();
        }
    }

    // --- LOGIC: HANDLE GET REQUESTS (EDIT & DELETE) ---

    // --- DELETE PRODUCT ---
    if (isset($_GET['delete'])) {
        $delete_id = (int)$_GET['delete'];
        // Note: ON DELETE CASCADE will also delete variants and inventory.
        $db->query("DELETE FROM products WHERE id = ?", [$delete_id]);
        $_SESSION['success_flash'] = 'Base Product and all its variants successfully deleted.';
        header('Location: base_products.php'); // Redirect to remove query string
        exit();
    }

    // --- GET PRODUCT TO EDIT ---
    if (isset($_GET['edit'])) {
        $edit_id = (int)$_GET['edit'];
        $product_to_edit = $db->query("SELECT * FROM products WHERE id = ?", [$edit_id])->first();
        if ($product_to_edit) {
            $edit_mode = true;
            $form_action = 'update_product';
        }
    }

} catch (PDOException $e) {
    // Handle database errors
    if ($e->getCode() == '23000') { // Integrity constraint violation (e.g., duplicate SKU)
        $_SESSION['error_flash'] = 'Error: A product with this Base SKU Prefix already exists.';
    } else {
        $_SESSION['error_flash'] = 'Database Error: ' . $e->getMessage();
    }
    // Redirect back to the page to show the error
    header('Location: base_products.php');
    exit();
} catch (Exception $e) {
    $_SESSION['error_flash'] = 'An unexpected error occurred: ' . $e->getMessage();
    header('Location: base_products.php');
    exit();
}


// --- DATA: GET ALL PRODUCTS FOR DISPLAY ---
$products = $db->query("SELECT * FROM products ORDER BY base_name ASC")->results();

// --- Include Header ---
require_once '../templates/header.php'; 
?>

<!-- ======================================== -->
<!-- 1. ADD / EDIT PRODUCT FORM -->
<!-- ======================================== -->
<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <h2 class="text-2xl font-bold text-gray-800 mb-4">
        <?php echo $edit_mode ? 'Edit Base Product' : 'Add New Base Product'; ?>
    </h2>
    
    <form action="base_products.php" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <!-- Hidden fields -->
        <input type="hidden" name="<?php echo $form_action; ?>" value="1">
        <?php if ($edit_mode): ?>
            <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($product_to_edit->id); ?>">
        <?php endif; ?>

        <!-- Base Product Name -->
        <div>
            <label for="base_name" class="block text-sm font-medium text-gray-700 mb-1">Base Product Name <span class="text-red-500">*</span></label>
            <input type="text" id="base_name" name="base_name" required
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                   value="<?php echo htmlspecialchars($product_to_edit->base_name ?? ''); ?>"
                   placeholder="e.g., Ujjwal Premium Enamel">
        </div>

        <!-- ====================================================== -->
        <!-- UPDATED: Base SKU Field -->
        <!-- =Example: UPE -->
        <!-- ====================================================== -->
        <div>
            <label for="base_sku" class="block text-sm font-medium text-gray-700 mb-1">
                Base SKU Prefix <span class="text-red-500">*</span>
            </label>
            <input type="text" id="base_sku" name="base_sku" required
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                   value="<?php echo htmlspecialchars($product_to_edit->base_sku ?? ''); ?>"
                   placeholder="e.g., UPE (This will prefix variant SKUs like UPE-1L-A)">
            <p class="text-xs text-gray-500 mt-1">Short code for the product line (e.g., UPE, USC). This will be used to auto-generate variant SKUs.</p>
        </div>
        <!-- ====================================================== -->

        <!-- Category -->
        <div class="md:col-span-1">
            <label for="category" class="block text-sm font-medium text-gray-700 mb-1">Category</label>
            <input type="text" id="category" name="category"
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                   value="<?php echo htmlspecialchars($product_to_edit->category ?? ''); ?>"
                   placeholder="e.g., Paint, Cement">
        </div>

        <!-- Status -->
        <div class="md:col-span-1">
            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select id="status" name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                <option value="active" <?php echo ($product_to_edit->status ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo ($product_to_edit->status ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>

        <!-- Description -->
        <div class="md:col-span-2">
            <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea id="description" name="description" rows="3"
                      class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"><?php echo htmlspecialchars($product_to_edit->description ?? ''); ?></textarea>
        </div>

        <!-- Submit Buttons -->
        <div class="md:col-span-2 flex items-center justify-end space-x-3">
            <?php if ($edit_mode): ?>
                <a href="base_products.php" class="px-5 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </a>
                <button type="submit" class="px-5 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-save mr-2"></i>Update Base Product
                </button>
            <?php else: ?>
                <button type="submit" class="px-5 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-plus mr-2"></i>Add Base Product
                </button>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ======================================== -->
<!-- 2. PRODUCT LIST TABLE -->
<!-- ======================================== -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product Name</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Base SKU Prefix</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                            No base products found. Start by adding one above.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($product->base_name); ?></div>
                                <div class="text-sm text-gray-500 truncate" style="max-width: 250px;"><?php echo htmlspecialchars($product->description ?? 'N/A'); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-700"><?php echo htmlspecialchars($product->base_sku ?? 'N/A'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($product->category ?? 'N/A'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($product->status == 'active'): ?>
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
                                <a href="manage_variants.php?product_id=<?php echo $product->id; ?>" class="text-white bg-green-600 hover:bg-green-700 px-3 py-1 rounded-md text-xs font-medium">
                                    Manage Variants
                                </a>
                                <a href="base_products.php?edit=<?php echo $product->id; ?>" class="text-primary-600 hover:text-primary-900" title="Edit Base Product">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="base_products.php?delete=<?php echo $product->id; ?>" class="text-red-600 hover:text-red-900" 
                                   title="Delete Base Product"
                                   onclick="return confirm('Are you sure you want to delete this base product? This will also delete ALL of its variants and inventory. This action cannot be undone.');">
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


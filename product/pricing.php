<?php
require_once '../core/init.php';

// --- SECURITY ---
$allowed_roles = [
    'Superadmin', 'admin', 'Accounts',
    'accounts-rampura', 'accounts-srg', 'accounts-demra',
];
restrict_access($allowed_roles);

global $db; 

// --- VARIABLE INITIALIZATION ---
$pageTitle = 'Manage Product Pricing';
$edit_mode = false;
$price_to_edit = null;
$form_action = 'add_or_update_price';
$variant_id = null;
$variant = null;

// --- LOGIC: GET PRODUCT VARIANT ---
if (!isset($_GET['variant_id'])) {
    $_SESSION['error_flash'] = 'No product variant selected.';
    header('Location: base_products.php');
    exit();
}

$variant_id = (int)$_GET['variant_id'];
$variant = $db->query(
    "SELECT pv.*, p.base_name 
     FROM product_variants pv
     JOIN products p ON pv.product_id = p.id
     WHERE pv.id = ?", 
    [$variant_id]
)->first();

if (!$variant) {
    $_SESSION['error_flash'] = 'Invalid product variant ID.';
    header('Location: base_products.php');
    exit();
}

$pageTitle = 'Pricing for: ' . htmlspecialchars($variant->sku);

// --- LOGIC: HANDLE POST REQUESTS (ADD, UPDATE, DELETE) ---
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // --- ADD OR UPDATE PRICE (NEW HISTORY LOGIC) ---
        if (isset($_POST['add_or_update_price'])) {
            $branch_id = (int)$_POST['branch_id'];
            $unit_price = $_POST['unit_price'];
            $effective_date = $_POST['effective_date'];
            $status = $_POST['status'];

            // 1. Deactivate any existing *active* price for this variant/branch
            $db->query(
                "UPDATE product_prices 
                 SET is_active = 0 
                 WHERE variant_id = ? AND branch_id = ? AND is_active = 1",
                [$variant_id, $branch_id]
            );

            // 2. Insert the new price as the currently active one
            $db->query(
                "INSERT INTO product_prices 
                 (variant_id, branch_id, unit_price, effective_date, status, is_active) 
                 VALUES (?, ?, ?, ?, ?, 1)", 
                [$variant_id, $branch_id, $unit_price, $effective_date, $status]
            );
            
            $_SESSION['success_flash'] = 'Price successfully set. Old price (if any) has been archived.';
            header('Location: pricing.php?variant_id=' . $variant_id); 
            exit();
        }

        // --- DELETE PRICE (Now just deactivates the active price) ---
        // We no longer truly delete, we just deactivate to preserve history.
        if (isset($_POST['deactivate_price'])) {
            $price_id = (int)$_POST['price_id'];
            $db->query(
                "UPDATE product_prices SET is_active = 0, status = 'inactive' 
                 WHERE id = ? AND variant_id = ?", 
                [$price_id, $variant_id]
            );
            $_SESSION['success_flash'] = 'Price successfully deactivated and archived.';
            header('Location: pricing.php?variant_id=' . $variant_id);
            exit();
        }
    }

    // --- LOGIC: GET PRICE TO EDIT ---
    // "Edit" now means "Add a new price for this branch"
    if (isset($_GET['edit'])) {
        $edit_id = (int)$_GET['edit'];
        // We load the *active* price for this branch to pre-fill the form
        $price_to_edit = $db->query(
            "SELECT * FROM product_prices WHERE id = ? AND variant_id = ? AND is_active = 1", 
            [$edit_id, $variant_id]
        )->first();
        if ($price_to_edit) {
            $edit_mode = true;
        }
    }

} catch (PDOException $e) {
    if ($e->getCode() == '23000') { 
        $_SESSION['error_flash'] = 'Database Error: A unique constraint failed. This may be a bug.';
    } else {
        $_SESSION['error_flash'] = 'Database Error: ' . $e->getMessage();
    }
    header('Location: pricing.php?variant_id=' . $variant_id);
    exit();
}

// --- DATA: GET ALL BRANCHES AND PRICE HISTORY ---
$branches = $db->query("SELECT * FROM branches WHERE status = 'active' ORDER BY name ASC")->results();

// Get all price history, with the active price on top
$price_history = $db->query(
    "SELECT pp.*, b.name as branch_name 
     FROM product_prices pp
     JOIN branches b ON pp.branch_id = b.id
     WHERE pp.variant_id = ?
     ORDER BY pp.branch_id, pp.is_active DESC, pp.effective_date DESC", 
    [$variant_id]
)->results();

// Get *only* the currently active prices
$active_prices = [];
foreach ($price_history as $price) {
    if ($price->is_active) {
        $active_prices[$price->branch_id] = $price;
    }
}

// Filter branches that do NOT have an active price
$available_branches = array_filter($branches, function($branch) use ($active_prices) {
    return !isset($active_prices[$branch->id]);
});

// --- Include Header ---
require_once '../templates/header.php'; 
?>

<!-- ======================================== -->
<!-- 1. PAGE HEADER & NAVIGATION -->
<!-- ======================================== -->
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Manage Factory Pricing</h1>
        <p class="text-lg text-gray-600">
            Variant: 
            <strong class="text-primary-600"><?php echo htmlspecialchars($variant->sku); ?></strong>
            (<?php echo htmlspecialchars($variant->base_name . ' - ' . $variant->weight_variant . ' / ' . $variant->grade); ?>)
        </p>
    </div>
    <a href="manage_variants.php?product_id=<?php echo $variant->product_id; ?>" class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
        <i class="fas fa-arrow-left mr-2"></i>Back to Variants
    </a>
</div>

<!-- ======================================== -->
<!-- 2. ADD / UPDATE PRICE FORM -->
<!-- ======================================== -->
<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <h2 class="text-2xl font-bold text-gray-800 mb-4">
        <?php echo $edit_mode ? 'Update Price for Branch' : 'Set New Price for Branch'; ?>
    </h2>
    
    <form action="pricing.php?variant_id=<?php echo $variant_id; ?>" method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-6">
        
        <input type="hidden" name="<?php echo $form_action; ?>" value="1">

        <!-- Branch (Factory) -->
        <div>
            <label for="branch_id" class="block text-sm font-medium text-gray-700 mb-1">Factory / Branch <span class="text-red-500">*</span></label>
            <select id="branch_id" name="branch_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500" <?php echo $edit_mode ? 'disabled' : 'required'; ?>>
                <?php if ($edit_mode): ?>
                    <!-- In edit mode, show the branch for this price, but disabled -->
                    <?php $branch_name = $db->query("SELECT name FROM branches WHERE id = ?", [$price_to_edit->branch_id])->first()->name; ?>
                    <option value="<?php echo $price_to_edit->branch_id; ?>" selected><?php echo htmlspecialchars($branch_name); ?></option>
                    <!-- Also add a hidden field to pass the branch_id -->
                    <input type="hidden" name="branch_id" value="<?php echo $price_to_edit->branch_id; ?>">
                <?php else: ?>
                    <!-- In add mode, show available, un-priced branches -->
                    <option value="" disabled selected>Select a branch...</option>
                    <?php foreach ($available_branches as $branch): ?>
                        <option value="<?php echo $branch->id; ?>"><?php echo htmlspecialchars($branch->name); ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <?php if (!$edit_mode && empty($available_branches)): ?>
                <p class="text-xs text-red-500 mt-1">All branches have active prices. Edit one below to update it.</p>
            <?php endif; ?>
        </div>

        <!-- Unit Price -->
        <div>
            <label for="unit_price" class="block text-sm font-medium text-gray-700 mb-1">New Unit Price (BDT) <span class="text-red-500">*</span></label>
            <input type="number" step="0.01" id="unit_price" name="unit_price" required
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                   value="<?php echo htmlspecialchars($price_to_edit->unit_price ?? '0.00'); ?>"
                   placeholder="e.g., 1500.50">
        </div>

        <!-- Effective Date -->
        <div>
            <label for="effective_date" class="block text-sm font-medium text-gray-700 mb-1">Effective Date <span class="text-red-500">*</span></label>
            <input type="date" id="effective_date" name="effective_date" required
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                   value="<?php echo date('Y-m-d'); ?>">
        </div>

        <!-- Status -->
        <div>
            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Price Status</label>
            <select id="status" name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                <option value="active" <?php echo ($price_to_edit->status ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="promotional" <?php echo ($price_to_edit->status ?? '') === 'promotional' ? 'selected' : ''; ?>>Promotional</option>
            </select>
        </div>
        
        <!-- Submit Button -->
        <div class="md:col-span-4 flex justify-between items-center">
            <p class="text-sm text-gray-600">
                <i class="fas fa-info-circle mr-1 text-primary-600"></i>
                Saving will archive the old price and create a new, active price record.
            </p>
            <div class="flex space-x-3">
                <?php if ($edit_mode): ?>
                    <a href="pricing.php?variant_id=<?php echo $variant_id; ?>" class="px-5 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Cancel
                    </a>
                <?php endif; ?>
                <button type="submit" class="px-5 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500"
                    <?php echo !$edit_mode && empty($available_branches) ? 'disabled' : ''; ?>>
                    <i class="fas fa-save mr-2"></i><?php echo $edit_mode ? 'Update Price' : 'Set Price'; ?>
                </button>
            </div>
        </div>
    </form>
</div>

<!-- ======================================== -->
<!-- 3. PRICE HISTORY LIST -->
<!-- ======================================== -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <h3 class="text-xl font-bold text-gray-800 p-6 border-b border-gray-200">
        Price History for <?php echo htmlspecialchars($variant->sku); ?>
    </h3>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Factory / Branch</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Price (BDT)</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Effective Date</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($price_history)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                            No prices have been set for this variant. Add one above.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($price_history as $price): ?>
                        <tr class="<?php echo $price->is_active ? 'bg-green-50' : 'bg-gray-50 opacity-60'; ?>">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium <?php echo $price->is_active ? 'text-gray-900' : 'text-gray-500'; ?>">
                                <?php echo htmlspecialchars($price->branch_name); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm <?php echo $price->is_active ? 'text-gray-900' : 'text-gray-500'; ?>">
                                <?php echo number_format($price->unit_price, 2); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo date('d M, Y', strtotime($price->effective_date)); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($price->is_active): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                                <?php else: ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-200 text-gray-700">Archived</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-3">
                                <?php if ($price->is_active): ?>
                                    <a href="pricing.php?variant_id=<?php echo $variant_id; ?>&edit=<?php echo $price->id; ?>" class="text-primary-600 hover:text-primary-900" title="Set New Price">
                                        <i class="fas fa-edit"></i> Update
                                    </a>
                                    <!-- This form deactivates the price -->
                                    <form action="pricing.php?variant_id=<?php echo $variant_id; ?>" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to deactivate this price? This will archive it.');">
                                        <input type="hidden" name="deactivate_price" value="1">
                                        <input type="hidden" name="price_id" value="<?php echo $price->id; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-900" title="Deactivate Price">
                                            <i class="fas fa-trash"></i> Archive
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-gray-400 text-xs">Archived</span>
                                <?php endif; ?>
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


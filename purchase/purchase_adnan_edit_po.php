<?php
/**
 * Edit Purchase Order - FULL EDIT MODE
 * All fields editable with comprehensive audit trail
 * 
 * @package Ujjal Flour Mills
 * @subpackage Purchase (Adnan) Module
 */

require_once '../core/init.php';
require_once '../core/classes/Purchaseadnanmanager.php';

// Restrict to Superadmin only
restrict_access(['Superadmin']);

$pageTitle = "Edit Purchase Order";

// Get current user
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_name = $currentUser['display_name'] ?? 'System';

// Get PO ID
$po_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$po_id) {
    set_message("PO ID is required", 'error');
    redirect('purchase/purchase_adnan_index.php');
}

// Initialize manager
$po_manager = new PurchaseAdnanManager();
$po = $po_manager->getPurchaseOrder($po_id);

if (!$po) {
    set_message("Purchase order not found", 'error');
    redirect('purchase/purchase_adnan_index.php');
}

// Get suppliers
$suppliers = $po_manager->getAllSuppliers();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = Database::getInstance()->getPdo();
        $db->beginTransaction();
        
        // Get new values
        $po_number = trim($_POST['po_number']);
        $po_date = trim($_POST['po_date']);
        $supplier_id = (int)$_POST['supplier_id'];
        $wheat_origin = trim($_POST['wheat_origin']);
        $quantity_kg = floatval($_POST['quantity_kg']);
        $unit_price_per_kg = floatval($_POST['unit_price_per_kg']);
        $expected_delivery_date = $_POST['expected_delivery_date'] ?: null;
        $remarks = trim($_POST['remarks'] ?? '');
        
        // Validate
        if (empty($po_number)) {
            throw new Exception("PO Number is required");
        }
        if (empty($po_date)) {
            throw new Exception("PO Date is required");
        }
        if ($quantity_kg <= 0) {
            throw new Exception("Quantity must be greater than zero");
        }
        if ($unit_price_per_kg <= 0) {
            throw new Exception("Unit price must be greater than zero");
        }
        
        // Check for duplicate PO number (if changed)
        if ($po_number !== $po->po_number) {
            $check_sql = "SELECT id FROM purchase_orders_adnan WHERE po_number = ? AND id != ?";
            $check_stmt = $db->prepare($check_sql);
            $check_stmt->execute([$po_number, $po_id]);
            
            if ($check_stmt->fetch()) {
                throw new Exception("PO Number '{$po_number}' already exists. Please use a different number.");
            }
        }
        
        // Get supplier name
        $supplier = null;
        foreach ($suppliers as $s) {
            if ($s->id == $supplier_id) {
                $supplier = $s;
                break;
            }
        }
        
        if (!$supplier) {
            throw new Exception("Invalid supplier selected");
        }
        
        // Calculate total
        $total_order_value = $quantity_kg * $unit_price_per_kg;
        
        // Track changes for audit
        $changes = [];
        $old_values = [];
        $new_values = [];
        
        // PO Number change
        if ($po->po_number != $po_number) {
            $changes[] = "PO Number: {$po->po_number} → {$po_number}";
            $old_values['po_number'] = $po->po_number;
            $new_values['po_number'] = $po_number;
        }
        
        // PO Date change
        if ($po->po_date != $po_date) {
            $old_date = date('d M Y', strtotime($po->po_date));
            $new_date = date('d M Y', strtotime($po_date));
            $changes[] = "PO Date: {$old_date} → {$new_date}";
            $old_values['po_date'] = $po->po_date;
            $new_values['po_date'] = $po_date;
        }
        
        // Supplier change
        if ($po->supplier_id != $supplier_id) {
            $changes[] = "Supplier: {$po->supplier_name} → {$supplier->name}";
            $old_values['supplier'] = $po->supplier_name;
            $new_values['supplier'] = $supplier->name;
        }
        
        // Wheat origin change
        if ($po->wheat_origin != $wheat_origin) {
            $changes[] = "Origin: {$po->wheat_origin} → {$wheat_origin}";
            $old_values['wheat_origin'] = $po->wheat_origin;
            $new_values['wheat_origin'] = $wheat_origin;
        }
        
        // Quantity change
        if ((float)$po->quantity_kg != $quantity_kg) {
            $changes[] = "Quantity: " . number_format($po->quantity_kg, 2) . " KG → " . number_format($quantity_kg, 2) . " KG";
            $old_values['quantity_kg'] = $po->quantity_kg;
            $new_values['quantity_kg'] = $quantity_kg;
        }
        
        // Unit price change
        if ((float)$po->unit_price_per_kg != $unit_price_per_kg) {
            $changes[] = "Unit Price: ৳" . number_format($po->unit_price_per_kg, 2) . " → ৳" . number_format($unit_price_per_kg, 2);
            $old_values['unit_price_per_kg'] = $po->unit_price_per_kg;
            $new_values['unit_price_per_kg'] = $unit_price_per_kg;
        }
        
        // Total value change
        if ((float)$po->total_order_value != $total_order_value) {
            $changes[] = "Total Value: ৳" . number_format($po->total_order_value, 2) . " → ৳" . number_format($total_order_value, 2);
            $old_values['total_order_value'] = $po->total_order_value;
            $new_values['total_order_value'] = $total_order_value;
        }
        
        // Expected delivery date change
        if ($po->expected_delivery_date != $expected_delivery_date) {
            $old_date = $po->expected_delivery_date ? date('d M Y', strtotime($po->expected_delivery_date)) : 'Not set';
            $new_date = $expected_delivery_date ? date('d M Y', strtotime($expected_delivery_date)) : 'Not set';
            $changes[] = "Expected Delivery: {$old_date} → {$new_date}";
            $old_values['expected_delivery_date'] = $po->expected_delivery_date;
            $new_values['expected_delivery_date'] = $expected_delivery_date;
        }
        
        // Remarks change
        if (trim($po->remarks ?? '') != $remarks) {
            $changes[] = "Remarks updated";
            $old_values['remarks'] = $po->remarks;
            $new_values['remarks'] = $remarks;
        }
        
        // If no changes, don't update
        if (empty($changes)) {
            $db->rollBack();
            //set_message("No changes detected", 'info');
            redirect('purchase/purchase_adnan_index.php');
        }
        
        // Update PO
        $sql = "UPDATE purchase_orders_adnan SET 
                po_number = ?,
                po_date = ?,
                supplier_id = ?,
                supplier_name = ?,
                wheat_origin = ?,
                quantity_kg = ?,
                unit_price_per_kg = ?,
                total_order_value = ?,
                expected_delivery_date = ?,
                remarks = ?,
                updated_at = NOW()
                WHERE id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $po_number,
            $po_date,
            $supplier_id,
            $supplier->name,
            $wheat_origin,
            $quantity_kg,
            $unit_price_per_kg,
            $total_order_value,
            $expected_delivery_date,
            $remarks,
            $po_id
        ]);
        
        // Audit trail
        if (function_exists('auditLog')) {
            auditLog(
                'purchase',
                'updated',
                "Purchase Order {$po->po_number} updated: " . implode(', ', $changes),
                [
                    'record_type' => 'purchase_order',
                    'record_id' => $po_id,
                    'reference_number' => $po_number, // Use new PO number
                    'old_po_number' => $po->po_number,
                    'new_po_number' => $po_number,
                    'changes_count' => count($changes),
                    'changes' => $changes,
                    'old_values' => $old_values,
                    'new_values' => $new_values,
                    'updated_by' => $user_name
                ]
            );
        }
        
        $db->commit();
        
        //set_message("Purchase order updated successfully! " . count($changes) . " change(s) made: " . implode(', ', array_slice($changes, 0, 3)) . (count($changes) > 3 ? '...' : ''), 'success');
        redirect('purchase/purchase_adnan_index.php');
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Error updating PO: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Error updating PO: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
}

require_once '../templates/header.php';
?>

<div class="w-full px-4 py-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-edit text-blue-600"></i> Edit Purchase Order #<?php echo htmlspecialchars($po->po_number); ?>
            </h2>
            <nav class="text-sm text-gray-600 mt-1">
                <a href="purchase_adnan_index.php" class="hover:text-primary-600">Purchase (Adnan)</a>
                <span class="mx-2">›</span>
                <a href="purchase_adnan_view_po.php?id=<?php echo $po->id; ?>" class="hover:text-primary-600">
                    PO #<?php echo htmlspecialchars($po->po_number); ?>
                </a>
                <span class="mx-2">›</span>
                <span>Full Edit</span>
            </nav>
        </div>
        <a href="purchase_adnan_view_po.php?id=<?php echo $po->id; ?>" 
           class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <?php echo display_message(); ?>

    <!-- Warning Notice -->
    <div class="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6">
        <div class="flex items-start">
            <i class="fas fa-exclamation-triangle text-yellow-600 mt-1 mr-3 text-xl"></i>
            <div>
                <p class="font-semibold text-yellow-800">⚠️ Full Edit Mode - All Fields Editable</p>
                <ul class="text-sm text-yellow-700 mt-2 space-y-1 list-disc list-inside">
                    <li>All changes will be logged in the audit trail for compliance</li>
                    <li>Editing PO Number or Date may affect reporting and references</li>
                    <li>Changes will NOT automatically update related GRNs and payments</li>
                    <li>PO Number must be unique across all purchase orders</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Current PO Info Card -->
    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border-l-4 border-blue-500 p-4 mb-6 rounded-r-lg">
        <h3 class="font-semibold text-gray-900 mb-3 flex items-center gap-2">
            <i class="fas fa-info-circle text-blue-600"></i> Current Values
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
            <div>
                <p class="text-gray-600">PO Number</p>
                <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($po->po_number); ?></p>
            </div>
            <div>
                <p class="text-gray-600">PO Date</p>
                <p class="font-semibold text-gray-900"><?php echo date('d M Y', strtotime($po->po_date)); ?></p>
            </div>
            <div>
                <p class="text-gray-600">Supplier</p>
                <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($po->supplier_name); ?></p>
            </div>
            <div>
                <p class="text-gray-600">Origin</p>
                <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($po->wheat_origin); ?></p>
            </div>
            <div>
                <p class="text-gray-600">Quantity</p>
                <p class="font-semibold text-gray-900"><?php echo number_format($po->quantity_kg, 2); ?> KG</p>
            </div>
            <div>
                <p class="text-gray-600">Unit Price</p>
                <p class="font-semibold text-gray-900">৳<?php echo number_format($po->unit_price_per_kg, 2); ?>/KG</p>
            </div>
            <div>
                <p class="text-gray-600">Total Value</p>
                <p class="font-semibold text-gray-900">৳<?php echo number_format($po->total_order_value, 2); ?></p>
            </div>
            <div>
                <p class="text-gray-600">Expected Delivery</p>
                <p class="font-semibold text-gray-900">
                    <?php echo $po->expected_delivery_date ? date('d M Y', strtotime($po->expected_delivery_date)) : 'Not set'; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Edit Form -->
    <div class="bg-white rounded-lg shadow-lg">
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-6 py-4 rounded-t-lg">
            <h5 class="font-semibold flex items-center gap-2">
                <i class="fas fa-file-invoice-dollar"></i> Edit Purchase Order Details
            </h5>
        </div>
        
        <form method="POST" id="editForm" class="p-6">
            
            <!-- Critical Fields Section -->
            <div class="mb-8">
                <h3 class="text-lg font-semibold text-gray-900 mb-4 pb-2 border-b flex items-center gap-2">
                    <i class="fas fa-key text-orange-600"></i> Critical Fields
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <!-- PO Number (EDITABLE) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            PO Number <span class="text-red-500">*</span>
                            <i class="fas fa-exclamation-triangle text-orange-500 ml-1" title="Changing PO Number affects all references"></i>
                        </label>
                        <input type="text" name="po_number" id="po_number" required
                               class="w-full px-3 py-2 border-2 border-orange-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 bg-orange-50"
                               value="<?php echo htmlspecialchars($po->po_number); ?>"
                               placeholder="e.g., PO-2026-0001">
                        <p class="text-xs text-orange-600 mt-1">
                            <i class="fas fa-info-circle"></i> Must be unique. Original: <strong><?php echo htmlspecialchars($po->po_number); ?></strong>
                        </p>
                    </div>

                    <!-- PO Date (EDITABLE) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            PO Date <span class="text-red-500">*</span>
                            <i class="fas fa-exclamation-triangle text-orange-500 ml-1" title="Changing date may affect reporting"></i>
                        </label>
                        <input type="date" name="po_date" id="po_date" required
                               class="w-full px-3 py-2 border-2 border-orange-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 bg-orange-50"
                               value="<?php echo $po->po_date; ?>">
                        <p class="text-xs text-orange-600 mt-1">
                            <i class="fas fa-info-circle"></i> Original: <strong><?php echo date('d M Y', strtotime($po->po_date)); ?></strong>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Supplier & Product Details -->
            <div class="mb-8">
                <h3 class="text-lg font-semibold text-gray-900 mb-4 pb-2 border-b flex items-center gap-2">
                    <i class="fas fa-box text-blue-600"></i> Supplier & Product Details
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <!-- Supplier -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Supplier <span class="text-red-500">*</span>
                        </label>
                        <select name="supplier_id" id="supplier_select" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier->id; ?>" 
                                    <?php echo $po->supplier_id == $supplier->id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier->name); ?>
                                <?php if (!empty($supplier->supplier_code)): ?>
                                    (<?php echo htmlspecialchars($supplier->supplier_code); ?>)
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Wheat Origin -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Wheat Origin <span class="text-red-500">*</span>
                        </label>
                        <select name="wheat_origin" id="wheat_origin" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Select Origin</option>
                            <option value="কানাডা" <?php echo $po->wheat_origin === 'কানাডা' ? 'selected' : ''; ?>>কানাডা (Canada)</option>
                            <option value="রাশিয়া" <?php echo $po->wheat_origin === 'রাশিয়া' ? 'selected' : ''; ?>>রাশিয়া (Russia)</option>
                            <option value="Australia" <?php echo $po->wheat_origin === 'Australia' ? 'selected' : ''; ?>>Australia</option>
                            <option value="Ukraine" <?php echo $po->wheat_origin === 'Ukraine' ? 'selected' : ''; ?>>Ukraine</option>
                            <option value="India" <?php echo $po->wheat_origin === 'India' ? 'selected' : ''; ?>>India</option>
                            <option value="USA" <?php echo $po->wheat_origin === 'USA' ? 'selected' : ''; ?>>USA</option>
                            <option value="Argentina" <?php echo $po->wheat_origin === 'Argentina' ? 'selected' : ''; ?>>Argentina</option>
                            <option value="Local" <?php echo $po->wheat_origin === 'Local' ? 'selected' : ''; ?>>Local</option>
                            <option value="Other" <?php echo $po->wheat_origin === 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Pricing & Quantity -->
            <div class="mb-8">
                <h3 class="text-lg font-semibold text-gray-900 mb-4 pb-2 border-b flex items-center gap-2">
                    <i class="fas fa-calculator text-green-600"></i> Pricing & Quantity
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <!-- Quantity -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Quantity (KG) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="quantity_kg" id="quantity_kg" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                               value="<?php echo $po->quantity_kg; ?>"
                               step="0.01" min="0.01">
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-info-circle"></i> Original: <strong><?php echo number_format($po->quantity_kg, 2); ?> KG</strong>
                        </p>
                    </div>

                    <!-- Unit Price -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Unit Price (৳/KG) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="unit_price_per_kg" id="unit_price_per_kg" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                               value="<?php echo $po->unit_price_per_kg; ?>"
                               step="0.01" min="0.01">
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-info-circle"></i> Original: <strong>৳<?php echo number_format($po->unit_price_per_kg, 2); ?>/KG</strong>
                        </p>
                    </div>

                    <!-- Expected Delivery Date -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Expected Delivery Date
                        </label>
                        <input type="date" name="expected_delivery_date" id="expected_delivery_date"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                               value="<?php echo $po->expected_delivery_date; ?>">
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-info-circle"></i> Original: <strong>
                                <?php echo $po->expected_delivery_date ? date('d M Y', strtotime($po->expected_delivery_date)) : 'Not set'; ?>
                            </strong>
                        </p>
                    </div>

                    <!-- Total Value (Calculated) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Total Order Value (Calculated)
                        </label>
                        <div class="relative">
                            <input type="text" id="total_value" readonly
                                   class="w-full px-3 py-2 border-2 border-green-300 rounded-lg bg-green-50 font-bold text-green-700 text-lg">
                            <div class="absolute right-3 top-2">
                                <i class="fas fa-calculator text-green-600"></i>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-info-circle"></i> Auto-calculated: Quantity × Unit Price
                        </p>
                    </div>
                </div>
            </div>

            <!-- Additional Information -->
            <div class="mb-8">
                <h3 class="text-lg font-semibold text-gray-900 mb-4 pb-2 border-b flex items-center gap-2">
                    <i class="fas fa-clipboard text-purple-600"></i> Additional Information
                </h3>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Remarks / Notes
                    </label>
                    <textarea name="remarks" id="remarks" rows="4"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                              placeholder="Any special instructions, terms, or notes..."><?php echo htmlspecialchars($po->remarks ?? ''); ?></textarea>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="flex justify-between items-center pt-6 border-t">
                <a href="purchase_adnan_view_po.php?id=<?php echo $po->id; ?>" 
                   class="border border-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <button type="submit" id="submitBtn"
                        class="bg-blue-600 text-white px-8 py-3 rounded-lg hover:bg-blue-700 flex items-center gap-2 font-semibold shadow-lg">
                    <i class="fas fa-save"></i> Update Purchase Order
                </button>
            </div>
        </form>
    </div>

    <!-- Change Preview Card -->
    <div id="changesPreview" class="mt-6 bg-white rounded-lg shadow-lg p-6 hidden border-l-4 border-orange-500">
        <h3 class="font-semibold text-gray-900 mb-3 flex items-center gap-2">
            <i class="fas fa-list-check text-orange-600"></i> Changes to be Saved
            <span id="changeCount" class="ml-2 px-2 py-1 bg-orange-100 text-orange-800 rounded-full text-xs"></span>
        </h3>
        <ul id="changesList" class="list-disc list-inside text-sm text-gray-700 space-y-2"></ul>
    </div>
</div>

<script>
// Track original values
const originalValues = {
    po_number: '<?php echo addslashes($po->po_number); ?>',
    po_date: '<?php echo $po->po_date; ?>',
    supplier_id: <?php echo $po->supplier_id; ?>,
    wheat_origin: '<?php echo addslashes($po->wheat_origin); ?>',
    quantity_kg: <?php echo $po->quantity_kg; ?>,
    unit_price_per_kg: <?php echo $po->unit_price_per_kg; ?>,
    total_order_value: <?php echo $po->total_order_value; ?>,
    expected_delivery_date: '<?php echo $po->expected_delivery_date ?? ''; ?>',
    remarks: '<?php echo addslashes($po->remarks ?? ''); ?>'
};

// Calculate total value
function calculateTotal() {
    const quantity = parseFloat(document.getElementById('quantity_kg').value) || 0;
    const unitPrice = parseFloat(document.getElementById('unit_price_per_kg').value) || 0;
    const total = quantity * unitPrice;
    
    document.getElementById('total_value').value = '৳' + total.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    
    detectChanges();
}

// Detect changes
function detectChanges() {
    const changes = [];
    
    const poNumber = document.getElementById('po_number').value.trim();
    const poDate = document.getElementById('po_date').value;
    const supplierId = parseInt(document.querySelector('[name="supplier_id"]').value);
    const supplierName = document.querySelector('[name="supplier_id"] option:checked').text;
    const wheatOrigin = document.querySelector('[name="wheat_origin"]').value;
    const quantity = parseFloat(document.getElementById('quantity_kg').value);
    const unitPrice = parseFloat(document.getElementById('unit_price_per_kg').value);
    const total = quantity * unitPrice;
    const expectedDate = document.querySelector('[name="expected_delivery_date"]').value;
    const remarks = document.querySelector('[name="remarks"]').value;
    
    // Check each field
    if (poNumber !== originalValues.po_number) {
        changes.push(`<strong>PO Number:</strong> ${originalValues.po_number} → ${poNumber}`);
    }
    if (poDate !== originalValues.po_date) {
        const oldDate = new Date(originalValues.po_date).toLocaleDateString('en-GB');
        const newDate = new Date(poDate).toLocaleDateString('en-GB');
        changes.push(`<strong>PO Date:</strong> ${oldDate} → ${newDate}`);
    }
    if (supplierId !== originalValues.supplier_id) {
        changes.push(`<strong>Supplier:</strong> Changed to ${supplierName}`);
    }
    if (wheatOrigin !== originalValues.wheat_origin) {
        changes.push(`<strong>Origin:</strong> ${originalValues.wheat_origin} → ${wheatOrigin}`);
    }
    if (Math.abs(quantity - originalValues.quantity_kg) > 0.001) {
        changes.push(`<strong>Quantity:</strong> ${originalValues.quantity_kg.toFixed(2)} KG → ${quantity.toFixed(2)} KG`);
    }
    if (Math.abs(unitPrice - originalValues.unit_price_per_kg) > 0.001) {
        changes.push(`<strong>Unit Price:</strong> ৳${originalValues.unit_price_per_kg.toFixed(2)} → ৳${unitPrice.toFixed(2)}`);
    }
    if (Math.abs(total - originalValues.total_order_value) > 0.01) {
        changes.push(`<strong>Total Value:</strong> ৳${originalValues.total_order_value.toFixed(2)} → ৳${total.toFixed(2)}`);
    }
    if (expectedDate !== originalValues.expected_delivery_date) {
        const oldDate = originalValues.expected_delivery_date ? new Date(originalValues.expected_delivery_date).toLocaleDateString('en-GB') : 'Not set';
        const newDate = expectedDate ? new Date(expectedDate).toLocaleDateString('en-GB') : 'Not set';
        changes.push(`<strong>Expected Delivery:</strong> ${oldDate} → ${newDate}`);
    }
    if (remarks !== originalValues.remarks) {
        changes.push(`<strong>Remarks:</strong> Updated`);
    }
    
    // Show changes preview
    const previewCard = document.getElementById('changesPreview');
    const changesList = document.getElementById('changesList');
    const changeCount = document.getElementById('changeCount');
    
    if (changes.length > 0) {
        changesList.innerHTML = changes.map(change => `<li>${change}</li>`).join('');
        changeCount.textContent = `${changes.length} change${changes.length > 1 ? 's' : ''}`;
        previewCard.classList.remove('hidden');
    } else {
        previewCard.classList.add('hidden');
    }
}

// Event listeners
document.getElementById('po_number').addEventListener('input', detectChanges);
document.getElementById('po_date').addEventListener('change', detectChanges);
document.getElementById('quantity_kg').addEventListener('input', calculateTotal);
document.getElementById('unit_price_per_kg').addEventListener('input', calculateTotal);
document.querySelector('[name="supplier_id"]').addEventListener('change', detectChanges);
document.querySelector('[name="wheat_origin"]').addEventListener('change', detectChanges);
document.querySelector('[name="expected_delivery_date"]').addEventListener('change', detectChanges);
document.querySelector('[name="remarks"]').addEventListener('input', detectChanges);

// Initial calculation
calculateTotal();

// Form submission confirmation
document.getElementById('editForm').addEventListener('submit', function(e) {
    const changesList = document.getElementById('changesList');
    
    if (changesList.children.length === 0) {
        e.preventDefault();
        alert('⚠️ No changes detected.\n\nPlease modify at least one field to update the purchase order.');
        return false;
    }
    
    const changeCount = changesList.children.length;
    const changesText = Array.from(changesList.children).map(li => '• ' + li.textContent).join('\n');
    
    const confirmed = confirm(`⚠️ CONFIRM PURCHASE ORDER UPDATE\n\n${changeCount} change(s) will be saved:\n\n${changesText}\n\nAll changes will be logged in the audit trail.\n\nProceed with update?`);
    
    if (!confirmed) {
        e.preventDefault();
        return false;
    }
    
    // Disable submit button to prevent double submission
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Updating...';
});
</script>

<?php require_once '../templates/footer.php'; ?>
<?php
require_once '../core/init.php';
restrict_access(['Superadmin']);

$pageTitle = "Edit Purchase Order";

$po_id = $_GET['id'] ?? null;
if (!$po_id) {
    $_SESSION['error'] = "PO ID is required";
    redirect('purchase/purchase_adnan_index.php');
}

$po_manager = new Purchaseadnanmanager();
$po = $po_manager->getPurchaseOrder($po_id);

if (!$po) {
    $_SESSION['error'] = "Purchase order not found";
    redirect('purchase/purchase_adnan_index.php');
}

// Get suppliers
$suppliers = $po_manager->getAllSuppliers();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = Database::getInstance()->getPdo();
        
        $sql = "UPDATE purchase_orders_adnan SET 
                supplier_id = ?,
                supplier_name = ?,
                wheat_origin = ?,
                quantity_kg = ?,
                unit_price_per_kg = ?,
                total_order_value = ?,
                expected_delivery_date = ?,
                remarks = ?
                WHERE id = ?";
        
        $supplier_id = $_POST['supplier_id'];
        $supplier = null;
        foreach ($suppliers as $s) {
            if ($s->id == $supplier_id) {
                $supplier = $s;
                break;
            }
        }
        
        $quantity = $_POST['quantity_kg'];
        $unit_price = $_POST['unit_price_per_kg'];
        $total_value = $quantity * $unit_price;
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $supplier_id,
            $supplier->name,
            $_POST['wheat_origin'],
            $quantity,
            $unit_price,
            $total_value,
            $_POST['expected_delivery_date'] ?: null,
            $_POST['remarks'] ?: null,
            $po_id
        ]);
        
        $_SESSION['success'] = "Purchase order updated successfully!";
        redirect('purchase/purchase_adnan_view_po.php?id=' . $po_id);
        
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

require_once '../templates/header.php';
?>

<div class="w-full px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-edit text-blue-600"></i> Edit Purchase Order #<?php echo htmlspecialchars($po->po_number); ?>
            </h2>
            <nav class="text-sm text-gray-600 mt-1">
                <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="hover:text-primary-600">Purchase (Adnan)</a>
                <span class="mx-2">›</span>
                <a href="<?php echo url('purchase/purchase_adnan_view_po.php?id=' . $po->id); ?>" class="hover:text-primary-600">PO #<?php echo $po->po_number; ?></a>
                <span class="mx-2">›</span>
                <span>Edit</span>
            </nav>
        </div>
        <a href="<?php echo url('purchase/purchase_adnan_view_po.php?id=' . $po->id); ?>" 
           class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-6">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg mb-6">
        <i class="fas fa-exclamation-triangle"></i> 
        <strong>Warning:</strong> Editing this PO will NOT update related GRNs and payments. Only edit if absolutely necessary.
    </div>

    <div class="bg-white rounded-lg shadow max-w-4xl">
        <div class="bg-blue-600 text-white px-6 py-4 rounded-t-lg">
            <h5 class="font-semibold">PO Details</h5>
        </div>
        <div class="p-6">
            <form method="POST">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <!-- Supplier -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Supplier <span class="text-red-500">*</span>
                        </label>
                        <select name="supplier_id" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500" 
                                required>
                            <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier->id; ?>" <?php echo $po->supplier_id == $supplier->id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier->name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Wheat Origin -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Wheat Origin <span class="text-red-500">*</span>
                        </label>
                        <select name="wheat_origin" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500" 
                                required>
                            <option value="কানাডা" <?php echo $po->wheat_origin === 'কানাডা' ? 'selected' : ''; ?>>কানাডা (Canada)</option>
                            <option value="রাশিয়া" <?php echo $po->wheat_origin === 'রাশিয়া' ? 'selected' : ''; ?>>রাশিয়া (Russia)</option>
                            <option value="Australia" <?php echo $po->wheat_origin === 'Australia' ? 'selected' : ''; ?>>Australia</option>
                            <option value="Ukraine" <?php echo $po->wheat_origin === 'Ukraine' ? 'selected' : ''; ?>>Ukraine</option>
                            <option value="India" <?php echo $po->wheat_origin === 'India' ? 'selected' : ''; ?>>India</option>
                            <option value="Local" <?php echo $po->wheat_origin === 'Local' ? 'selected' : ''; ?>>Local</option>
                            <option value="Brazil" <?php echo $po->wheat_origin === 'Brazil' ? 'selected' : ''; ?>>Brazil</option>
                            <option value="Other" <?php echo $po->wheat_origin === 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <!-- Quantity -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Quantity (KG) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="quantity_kg" id="quantity_kg"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                               value="<?php echo $po->quantity_kg; ?>"
                               step="0.01" min="1" required>
                    </div>

                    <!-- Unit Price -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Unit Price (৳/KG) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="unit_price_per_kg" id="unit_price_per_kg"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                               value="<?php echo $po->unit_price_per_kg; ?>"
                               step="0.01" min="0.01" required>
                    </div>

                    <!-- Expected Delivery Date -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Expected Delivery Date
                        </label>
                        <input type="date" name="expected_delivery_date"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                               value="<?php echo $po->expected_delivery_date; ?>">
                    </div>

                    <!-- Total Value (calculated) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Total Order Value
                        </label>
                        <input type="text" id="total_value" readonly
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-100"
                               value="৳<?php echo number_format($po->total_order_value, 2); ?>">
                    </div>
                </div>

                <!-- Remarks -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Remarks
                    </label>
                    <textarea name="remarks" rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                              placeholder="Any special instructions or notes..."><?php echo htmlspecialchars($po->remarks ?? ''); ?></textarea>
                </div>

                <!-- Submit Buttons -->
                <div class="flex justify-between">
                    <a href="<?php echo url('purchase/purchase_adnan_view_po.php?id=' . $po->id); ?>" 
                       class="border border-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-50">
                        Cancel
                    </a>
                    <button type="submit" 
                            class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 flex items-center gap-2">
                        <i class="fas fa-save"></i> Update Purchase Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Calculate total value
function calculateTotal() {
    const quantity = parseFloat(document.getElementById('quantity_kg').value) || 0;
    const unitPrice = parseFloat(document.getElementById('unit_price_per_kg').value) || 0;
    const total = quantity * unitPrice;
    document.getElementById('total_value').value = '৳' + total.toFixed(2);
}

document.getElementById('quantity_kg').addEventListener('input', calculateTotal);
document.getElementById('unit_price_per_kg').addEventListener('input', calculateTotal);
</script>

<?php require_once '../templates/footer.php'; ?>
<?php
require_once __DIR__ . '/../core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle = "Record Goods Received";

// Get PO ID
$po_id = $_GET['po_id'] ?? null;

// Initialize managers
$po_manager = new Purchaseadnanmanager();
$grn_manager = new Goodsreceivedadnanmanager();

// Get list of open POs for dropdown
$open_pos = $po_manager->listPurchaseOrders(['delivery_status' => ['pending', 'partial']]);

// If PO ID provided, get PO details
$selected_po = null;
if ($po_id) {
    $selected_po = $po_manager->getPurchaseOrder($po_id);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = [
            'purchase_order_id' => $_POST['purchase_order_id'],
            'grn_date' => $_POST['grn_date'],
            'truck_number' => $_POST['truck_number'] ?? null,
            'quantity_received_kg' => $_POST['quantity_received_kg'],
            'expected_quantity' => $_POST['expected_quantity'] ?? null,
            'unload_point_branch_id' => $_POST['unload_point_branch_id'] ?? null,
            'unload_point_name' => $_POST['unload_point_name'],
            'remarks' => $_POST['remarks'] ?? null
        ];

        $grn_id = $grn_manager->recordGoodsReceived($data);
        
        $_SESSION['success'] = "Goods received recorded successfully! GRN ID: {$grn_id}";
        redirect('purchase/purchase_adnan_view_po.php?id=' . $data['purchase_order_id']);
        
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

require_once '../templates/header.php';
?>

<div class="w-full px-4 py-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-truck-loading text-green-600"></i> Record Goods Received
            </h2>
            <nav class="text-sm text-gray-600 mt-1">
                <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="hover:text-primary-600">Purchase (Adnan)</a>
                <span class="mx-2">›</span>
                <span>Record GRN</span>
            </nav>
        </div>
        <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-6 flex items-center justify-between">
            <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
            <button onclick="this.parentElement.remove()" class="text-red-600 hover:text-red-800">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow">
                <div class="bg-green-600 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold">GRN Details</h5>
                </div>
                <div class="p-6">
                    <form method="POST" id="grnForm">
                        <!-- Purchase Order Selection -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Purchase Order <span class="text-red-500">*</span>
                            </label>
                            <select name="purchase_order_id" id="purchase_order_id" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500" 
                                    required>
                                <option value="">-- Select Purchase Order --</option>
                                <?php foreach ($open_pos as $po): ?>
                                <option value="<?php echo $po->id; ?>" 
                                        data-supplier="<?php echo htmlspecialchars($po->supplier_name); ?>"
                                        data-origin="<?php echo htmlspecialchars($po->wheat_origin); ?>"
                                        data-ordered="<?php echo $po->quantity_kg; ?>"
                                        data-received="<?php echo $po->total_received_qty; ?>"
                                        data-pending="<?php echo $po->qty_yet_to_receive; ?>"
                                        data-unit-price="<?php echo $po->unit_price_per_kg; ?>"
                                        <?php echo $selected_po && $selected_po->id == $po->id ? 'selected' : ''; ?>>
                                    PO #<?php echo $po->po_number; ?> - <?php echo htmlspecialchars($po->supplier_name); ?> - 
                                    <?php echo htmlspecialchars($po->wheat_origin); ?> 
                                    (Pending: <?php echo number_format($po->qty_yet_to_receive, 2); ?> KG)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- PO Summary (shown when PO selected) -->
                        <div id="poSummary" class="mb-4 hidden">
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-1">
                                        <div><strong>Supplier:</strong> <span id="poSupplier"></span></div>
                                        <div><strong>Origin:</strong> <span id="poOrigin"></span></div>
                                        <div><strong>Unit Price:</strong> ৳<span id="poUnitPrice"></span>/KG</div>
                                    </div>
                                    <div class="space-y-1">
                                        <div><strong>Ordered:</strong> <span id="poOrdered"></span> KG</div>
                                        <div><strong>Already Received:</strong> <span id="poReceived"></span> KG</div>
                                        <div><strong>Yet to Receive:</strong> <span id="poPending"></span> KG</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <!-- GRN Date -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    GRN Date <span class="text-red-500">*</span>
                                </label>
                                <input type="date" name="grn_date" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                       value="<?php echo date('Y-m-d'); ?>" required max="<?php echo date('Y-m-d'); ?>">
                            </div>

                            <!-- Truck Number -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Truck Number
                                </label>
                                <input type="text" name="truck_number" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                       placeholder="e.g., 1234" maxlength="20">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <!-- Quantity Received -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Quantity Received (KG) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="quantity_received_kg" id="quantity_received_kg" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                       step="0.01" min="0.01" required>
                            </div>

                            <!-- Expected Quantity -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Expected Quantity (KG)
                                </label>
                                <input type="number" name="expected_quantity" id="expected_quantity" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                       step="0.01" min="0">
                                <small class="text-gray-500">Optional: For weight variance tracking</small>
                            </div>
                        </div>

                        <!-- Variance Display -->
                        <div id="varianceDisplay" class="mb-4 hidden">
                            <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg">
                                <strong>Weight Variance:</strong> 
                                <span id="varianceAmount"></span> KG 
                                (<span id="variancePercent"></span>%)
                            </div>
                        </div>

                        <!-- Unload Location -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Unload Location <span class="text-red-500">*</span>
                            </label>
                            <select name="unload_point_name" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500" 
                                    required>
                                <option value="">-- Select Location --</option>
                                <option value="সিরাজগঞ্জ">সিরাজগঞ্জ (Sirajganj)</option>
                                <option value="ডেমরা">ডেমরা (Demra)</option>
                                <option value="রামপুরা">রামপুরা (Rampura)</option>
                                <option value="Head Office">Head Office</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <!-- Remarks -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Remarks
                            </label>
                            <textarea name="remarks" rows="3"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                      placeholder="Any notes about this delivery..."></textarea>
                        </div>

                        <!-- Calculated Value Display -->
                        <div class="mb-6">
                            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                <h5 class="text-sm font-medium text-gray-700 mb-1">Calculated Value:</h5>
                                <h3 class="text-3xl font-bold text-primary-600">৳<span id="calculatedValue">0.00</span></h3>
                                <small class="text-gray-500">Quantity × Unit Price</small>
                            </div>
                        </div>

                        <!-- Submit Buttons -->
                        <div class="flex justify-between">
                            <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" 
                               class="border border-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-50">
                                Cancel
                            </a>
                            <button type="submit" 
                                    class="bg-green-600 text-white px-6 py-2 rounded-lg hover:bg-green-700 flex items-center gap-2">
                                <i class="fas fa-save"></i> Record GRN
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div>
            <!-- Help Card -->
            <div class="bg-white rounded-lg shadow">
                <div class="bg-blue-600 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold flex items-center gap-2">
                        <i class="fas fa-info-circle"></i> Instructions
                    </h5>
                </div>
                <div class="p-6">
                    <h6 class="font-semibold mb-2">Recording Goods Receipt:</h6>
                    <ol class="list-decimal list-inside space-y-1 text-sm text-gray-700">
                        <li>Select the purchase order</li>
                        <li>Enter the date goods were received</li>
                        <li>Enter truck number (optional)</li>
                        <li>Enter actual quantity received</li>
                        <li>Enter expected quantity (for variance tracking)</li>
                        <li>Select unload location</li>
                        <li>Add any remarks if needed</li>
                        <li>Review calculated value</li>
                        <li>Click "Record GRN"</li>
                    </ol>

                    <hr class="my-4">

                    <h6 class="font-semibold mb-2">Weight Variance:</h6>
                    <p class="text-sm text-gray-700">
                        If actual weight differs from expected by more than 0.5%, 
                        a variance record will be automatically created for analysis.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const poSelect = document.getElementById('purchase_order_id');
    const qtyReceived = document.getElementById('quantity_received_kg');
    const qtyExpected = document.getElementById('expected_quantity');
    const poSummary = document.getElementById('poSummary');
    const varianceDisplay = document.getElementById('varianceDisplay');
    const calculatedValue = document.getElementById('calculatedValue');

    // Update PO summary when selection changes
    poSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (this.value) {
            document.getElementById('poSupplier').textContent = option.dataset.supplier;
            document.getElementById('poOrigin').textContent = option.dataset.origin;
            document.getElementById('poOrdered').textContent = parseFloat(option.dataset.ordered).toFixed(2);
            document.getElementById('poReceived').textContent = parseFloat(option.dataset.received).toFixed(2);
            document.getElementById('poPending').textContent = parseFloat(option.dataset.pending).toFixed(2);
            document.getElementById('poUnitPrice').textContent = parseFloat(option.dataset.unitPrice).toFixed(2);
            
            poSummary.classList.remove('hidden');
        } else {
            poSummary.classList.add('hidden');
        }
        calculateValue();
    });

    // Calculate variance
    function calculateVariance() {
        const received = parseFloat(qtyReceived.value) || 0;
        const expected = parseFloat(qtyExpected.value) || 0;

        if (expected > 0 && received > 0) {
            const variance = received - expected;
            const variancePercent = (variance / expected * 100).toFixed(2);
            
            document.getElementById('varianceAmount').textContent = variance.toFixed(2);
            document.getElementById('variancePercent').textContent = variancePercent;
            
            varianceDisplay.classList.remove('hidden');
            
            // Change color based on variance
            const alertDiv = varianceDisplay.querySelector('div');
            if (Math.abs(variancePercent) > 1) {
                alertDiv.className = 'bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg';
            } else if (Math.abs(variancePercent) > 0.5) {
                alertDiv.className = 'bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg';
            } else {
                alertDiv.className = 'bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg';
            }
        } else {
            varianceDisplay.classList.add('hidden');
        }
    }

    // Calculate total value
    function calculateValue() {
        const option = poSelect.options[poSelect.selectedIndex];
        const unitPrice = parseFloat(option.dataset.unitPrice) || 0;
        const received = parseFloat(qtyReceived.value) || 0;
        
        const value = unitPrice * received;
        calculatedValue.textContent = value.toFixed(2);
    }

    qtyReceived.addEventListener('input', function() {
        calculateVariance();
        calculateValue();
    });

    qtyExpected.addEventListener('input', calculateVariance);

    // Trigger on page load if PO already selected
    if (poSelect.value) {
        poSelect.dispatchEvent(new Event('change'));
    }
});
</script>

<?php require_once '../templates/footer.php'; ?>
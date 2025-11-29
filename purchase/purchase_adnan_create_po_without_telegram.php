<?php
/**
 * Purchase (Adnan) Module - Create Purchase Order
 * Form for creating new wheat purchase orders
 * 
 * @package Ujjal Flour Mills
 * @subpackage Purchase (Adnan) Module
 */


require_once '../core/init.php';
require_once '../core/config/config.php';
require_once '../core/classes/Database.php';
require_once '../core/functions/helpers.php';
require_once '../core/classes/Purchaseadnanmanager.php';

// Restrict access
restrict_access(['Superadmin', 'admin', 'Accounts']);

$pageTitle = "Create Purchase Order";
$purchaseManager = new PurchaseAdnanManager();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $purchaseManager->createPurchaseOrder($_POST);
    
    if ($result['success']) {
        $_SESSION['success_message'] = $result['message'] . " (PO #" . $result['po_number'] . ")";
        header('Location: view_po.php?id=' . $result['po_id']);
        exit;
    } else {
        $error_message = $result['message'];
    }
}

// Get suppliers
$suppliers = $purchaseManager->getAllSuppliers();

include '../templates/header.php';
?>

<div class="container mx-auto px-4 py-8 max-w-4xl">
    <!-- Page Header -->
    <div class="mb-6">
        <div class="flex items-center gap-2 text-sm text-gray-600 mb-2">
            <a href="index.php" class="hover:text-primary-600">Purchase (Adnan)</a>
            <i class="fas fa-chevron-right text-xs"></i>
            <span>Create Purchase Order</span>
        </div>
        <h1 class="text-3xl font-bold text-gray-900">Create Purchase Order</h1>
    </div>

    <?php if (isset($error_message)): ?>
        <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle text-red-500 mr-2"></i>
                <p class="text-red-700"><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" class="bg-white rounded-lg shadow-md" id="poForm">
        <!-- Basic Information -->
        <div class="p-6 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Basic Information</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- PO Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        PO Date <span class="text-red-500">*</span>
                    </label>
                    <input type="date" name="po_date" required
                           value="<?php echo date('Y-m-d'); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                </div>

                <!-- Supplier -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Supplier <span class="text-red-500">*</span>
                    </label>
                    <select name="supplier_id" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                        <option value="">Select Supplier</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier->id; ?>">
                                <?php echo htmlspecialchars($supplier->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Wheat Details -->
        <div class="p-6 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Wheat Details</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Wheat Origin -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Wheat Origin <span class="text-red-500">*</span>
                    </label>
                    <select name="wheat_origin" required id="wheat_origin"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                        <option value="">Select Origin</option>
                        <option value="কানাডা">কানাডা (Canada)</option>
                        <option value="রাশিয়া">রাশিয়া (Russia)</option>
                        <option value="Australia">Australia</option>
                        <option value="Ukraine">Ukraine</option>
                        <option value="India">India</option>
                        <option value="Local">Local</option>
                        <option value="Brazil">Brazil</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <!-- Expected Delivery Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Expected Delivery Date
                    </label>
                    <input type="date" name="expected_delivery_date"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                </div>

                <!-- Quantity -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Quantity (KG) <span class="text-red-500">*</span>
                    </label>
                    <input type="number" name="quantity_kg" required step="0.01" min="0" id="quantity"
                           placeholder="Enter quantity in kilograms"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <p class="mt-1 text-sm text-gray-500">Enter total order quantity in KG</p>
                </div>

                <!-- Unit Price -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Unit Price Per KG (৳) <span class="text-red-500">*</span>
                    </label>
                    <input type="number" name="unit_price_per_kg" required step="0.01" min="0" id="unit_price"
                           placeholder="Enter price per kilogram"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <p class="mt-1 text-sm text-gray-500">Price per KG in Taka</p>
                </div>
            </div>
        </div>

        <!-- Calculated Total -->
        <div class="p-6 bg-gray-50 border-b border-gray-200">
            <div class="flex justify-between items-center">
                <span class="text-lg font-semibold text-gray-900">Total Order Value:</span>
                <span class="text-2xl font-bold text-primary-600" id="total_value">৳0.00</span>
            </div>
        </div>

        <!-- Additional Information -->
        <div class="p-6 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Additional Information</h2>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Remarks / Notes
                </label>
                <textarea name="remarks" rows="3"
                          placeholder="Enter any additional notes or remarks"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"></textarea>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="p-6 bg-gray-50 flex justify-between items-center">
            <a href="index.php" class="text-gray-600 hover:text-gray-800">
                <i class="fas fa-times mr-1"></i> Cancel
            </a>
            <button type="submit" class="bg-primary-600 text-white px-6 py-2 rounded-lg hover:bg-primary-700 flex items-center gap-2">
                <i class="fas fa-save"></i> Create Purchase Order
            </button>
        </div>
    </form>
</div>

<script>
// Calculate total on input change
document.addEventListener('DOMContentLoaded', function() {
    const quantityInput = document.getElementById('quantity');
    const unitPriceInput = document.getElementById('unit_price');
    const totalValueSpan = document.getElementById('total_value');
    
    function calculateTotal() {
        const quantity = parseFloat(quantityInput.value) || 0;
        const unitPrice = parseFloat(unitPriceInput.value) || 0;
        const total = quantity * unitPrice;
        
        totalValueSpan.textContent = '৳' + total.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    
    quantityInput.addEventListener('input', calculateTotal);
    unitPriceInput.addEventListener('input', calculateTotal);
});
</script>

<?php include '../templates/footer.php'; ?>

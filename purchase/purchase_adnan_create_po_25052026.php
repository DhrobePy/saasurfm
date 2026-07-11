<?php
/**
 * Purchase (Adnan) Module - Create Purchase Order
 * Form for creating new wheat purchase orders with manual PO number option
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
        // ============================================
        // AUDIT TRAIL - PO CREATION
        // ============================================
        // ============================================
// AUDIT TRAIL - PO CREATION
// ============================================
        try {
            if (function_exists('auditLog')) {
                $currentUser = getCurrentUser();
                $user_name = $currentUser['display_name'] ?? 'System User';
                
                auditLog(
                    'purchase',  // ✓ Valid ENUM value
                    'created',   // ✓ Valid ENUM value (was 'po_created')
                    "Purchase Order {$result['po_number']} created - {$_POST['quantity_kg']} KG {$_POST['wheat_origin']} wheat @ ৳{$_POST['unit_price_per_kg']}/KG = ৳" . number_format($result['total_value'], 2),
                    [
                        'record_type' => 'purchase_order',
                        'record_id' => $result['po_id'],
                        'reference_number' => $result['po_number'],
                        'po_id' => $result['po_id'],
                        'po_number' => $result['po_number'],
                        'supplier_id' => $_POST['supplier_id'],
                        'wheat_origin' => $_POST['wheat_origin'],
                        'quantity_kg' => $_POST['quantity_kg'],
                        'unit_price_per_kg' => $_POST['unit_price_per_kg'],
                        'total_order_value' => $result['total_value'],
                        'expected_delivery_date' => $_POST['expected_delivery_date'] ?? null,
                        'manual_po_number' => !empty($_POST['po_number']),
                        'created_by' => $user_name
                    ]
                );
            }
        } catch (Exception $e) {
            error_log("✗ Audit log error: " . $e->getMessage());
        }
        
        // ============================================
        // TELEGRAM NOTIFICATION - PURCHASE ORDER CREATED
        // ============================================
        try {
            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                require_once '../core/classes/TelegramNotifier.php';
                $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                
                $db = Database::getInstance();
                
                // Get complete PO details
                $po = $db->query(
                    "SELECT po.*, 
                            s.company_name as supplier_name, 
                            s.phone as supplier_phone, 
                            s.email as supplier_email
                     FROM purchase_orders_adnan po
                     LEFT JOIN suppliers s ON po.supplier_id = s.id
                     WHERE po.id = ?",
                    [$result['po_id']]
                )->first();
                
                if ($po) {
                    // Get current user info
                    $currentUser = getCurrentUser();
                    $user_name = $currentUser['display_name'] ?? 'System User';
                    
                    // Prepare PO data
                    $poData = [
                        'po_number' => $po->po_number,
                        'po_date' => date('d M Y', strtotime($po->po_date)),
                        'supplier_name' => $po->supplier_name,
                        'supplier_phone' => $po->supplier_phone ?: '',
                        'supplier_email' => $po->supplier_email ?: '',
                        'wheat_origin' => $po->wheat_origin,
                        'quantity_kg' => floatval($po->quantity_kg),
                        'unit_price_per_kg' => floatval($po->unit_price_per_kg),
                        'total_amount' => floatval($po->total_order_value),
                        'expected_delivery_date' => $po->expected_delivery_date ? date('d M Y', strtotime($po->expected_delivery_date)) : '',
                        'remarks' => $po->remarks ?: '',
                        'status' => ucfirst($po->po_status),
                        'created_by' => $user_name
                    ];
                    
                    // Send notification
                    $notif_result = $telegram->sendPurchaseOrderNotification($poData);
                    
                    if ($notif_result['success']) {
                        error_log("✓ Telegram purchase order notification sent: " . $po->po_number);
                    } else {
                        error_log("✗ Telegram purchase order notification failed: " . json_encode($notif_result['response']));
                    }
                }
            }
        } catch (Exception $e) {
            error_log("✗ Telegram purchase order notification error: " . $e->getMessage());
        }
        // END TELEGRAM NOTIFICATION
        
        $_SESSION['success_message'] = $result['message'] . " (PO #" . $result['po_number'] . ")";
        header('Location: purchase_adnan_view_po.php?id=' . $result['po_id']);
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
            <a href="purchase_adnan_index.php" class="hover:text-primary-600">Purchase (Adnan)</a>
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
                <!-- PO Number (Manual) -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        PO Number (Optional)
                        <i class="fas fa-info-circle text-blue-500 ml-1" title="Leave blank for auto-generation"></i>
                    </label>
                    <input type="text" 
                           name="po_number" 
                           id="po_number"
                           placeholder="Auto-generated if left blank"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <p class="mt-1 text-xs text-gray-500">
                        <i class="fas fa-magic text-primary-500"></i> Leave blank to auto-generate (e.g., PO-2024-0001)
                    </p>
                </div>

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
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Supplier <span class="text-red-500">*</span>
                    </label>
                    <select name="supplier_id" required id="supplier_select"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                        <option value="">Select Supplier</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier->id; ?>" 
                                    data-name="<?php echo htmlspecialchars($supplier->name); ?>"
                                    data-contact="<?php echo htmlspecialchars($supplier->contact_person ?? ''); ?>"
                                    data-phone="<?php echo htmlspecialchars($supplier->phone ?? ''); ?>">
                                <?php echo htmlspecialchars($supplier->name); ?>
                                <?php if ($supplier->supplier_code): ?>
                                    (<?php echo htmlspecialchars($supplier->supplier_code); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="supplier_info" class="mt-2 text-xs text-gray-600 hidden">
                        <i class="fas fa-user mr-1"></i>
                        <span id="supplier_contact"></span>
                        <span id="supplier_phone" class="ml-3"></span>
                    </div>
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
                        <option value="USA">USA</option>
                        <option value="Argentina">Argentina</option>
                        <option value="Argentina">Brazil</option>
                        <option value="Local">Local</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <!-- Expected Delivery Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Expected Delivery Date
                    </label>
                    <input type="date" name="expected_delivery_date"
                           min="<?php echo date('Y-m-d'); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                </div>

                <!-- Quantity -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Quantity (KG) <span class="text-red-500">*</span>
                    </label>
                    <input type="number" name="quantity_kg" required step="0.01" min="0.01" id="quantity"
                           placeholder="Enter quantity in kilograms"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <p class="mt-1 text-sm text-gray-500">
                        <i class="fas fa-weight text-gray-400"></i> 
                        Total order quantity in KG
                    </p>
                </div>

                <!-- Unit Price -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Unit Price Per KG (৳) <span class="text-red-500">*</span>
                    </label>
                    <input type="number" name="unit_price_per_kg" required step="0.01" min="0.01" id="unit_price"
                           placeholder="Enter price per kilogram"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <p class="mt-1 text-sm text-gray-500">
                        <i class="fas fa-tag text-gray-400"></i> 
                        Price per KG in Taka (৳)
                    </p>
                </div>
            </div>
        </div>

        <!-- Calculated Total -->
        <div class="p-6 bg-gradient-to-r from-blue-50 to-indigo-50 border-b border-gray-200">
            <div class="flex justify-between items-center">
                <div>
                    <span class="text-lg font-semibold text-gray-900">Total Order Value:</span>
                    <p class="text-xs text-gray-600 mt-1">
                        <span id="quantity_display">0</span> KG × ৳<span id="price_display">0.00</span>
                    </p>
                </div>
                <div class="text-right">
                    <span class="text-3xl font-bold text-primary-600" id="total_value">৳0.00</span>
                    <p class="text-xs text-gray-600 mt-1">
                        <span id="total_in_millions">৳0.00M</span>
                    </p>
                </div>
            </div>
        </div>

        <!-- Additional Information -->
        <div class="p-6 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Additional Information</h2>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Remarks / Notes
                </label>
                <textarea name="remarks" rows="4"
                          placeholder="Enter any additional notes, terms, or special conditions for this order..."
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"></textarea>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="p-6 bg-gray-50 flex justify-between items-center">
            <a href="purchase_adnan_index.php" class="text-gray-600 hover:text-gray-800 flex items-center gap-2">
                <i class="fas fa-times"></i> Cancel
            </a>
            <button type="submit" class="bg-primary-600 text-white px-6 py-2 rounded-lg hover:bg-primary-700 flex items-center gap-2 transition">
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
    const quantityDisplay = document.getElementById('quantity_display');
    const priceDisplay = document.getElementById('price_display');
    const totalInMillions = document.getElementById('total_in_millions');
    const supplierSelect = document.getElementById('supplier_select');
    const supplierInfo = document.getElementById('supplier_info');
    const supplierContact = document.getElementById('supplier_contact');
    const supplierPhone = document.getElementById('supplier_phone');
    
    function calculateTotal() {
        const quantity = parseFloat(quantityInput.value) || 0;
        const unitPrice = parseFloat(unitPriceInput.value) || 0;
        const total = quantity * unitPrice;
        
        // Update displays
        quantityDisplay.textContent = quantity.toLocaleString('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        });
        
        priceDisplay.textContent = unitPrice.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        
        totalValueSpan.textContent = '৳' + total.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        
        // Show in millions if over 1M
        if (total >= 1000000) {
            totalInMillions.textContent = '৳' + (total / 1000000).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + 'M';
            totalInMillions.style.display = 'inline';
        } else {
            totalInMillions.style.display = 'none';
        }
    }
    
    // Show supplier info on selection
    supplierSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption.value) {
            const contact = selectedOption.dataset.contact;
            const phone = selectedOption.dataset.phone;
            
            if (contact || phone) {
                supplierContact.textContent = contact || '';
                supplierPhone.textContent = phone ? '📱 ' + phone : '';
                supplierInfo.classList.remove('hidden');
            } else {
                supplierInfo.classList.add('hidden');
            }
        } else {
            supplierInfo.classList.add('hidden');
        }
    });
    
    quantityInput.addEventListener('input', calculateTotal);
    unitPriceInput.addEventListener('input', calculateTotal);
    
    // PO Number validation
    const poNumberInput = document.getElementById('po_number');
    if (poNumberInput) {
        poNumberInput.addEventListener('blur', function() {
            if (this.value.trim()) {
                // Basic format validation
                const validPattern = /^[A-Z0-9\-\/]+$/i;
                if (!validPattern.test(this.value)) {
                    alert('PO Number should only contain letters, numbers, hyphens, and slashes');
                    this.focus();
                }
            }
        });
    }
});
</script>

<?php include '../templates/footer.php'; ?>
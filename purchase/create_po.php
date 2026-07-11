<?php
require_once '../core/init.php';

global $db;

restrict_access();

$currentUser = getCurrentUser();
$user_id = $currentUser['id'];
$user_branch_id = $currentUser['branch_id'];

$pageTitle = "Create Purchase Order";
$po = null;
$po_items = [];
$errors = [];

// Check if editing
$po_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($po_id > 0) {
    $pageTitle = "Edit Purchase Order";
    
    // Get PO
    $db->query("SELECT * FROM purchase_orders WHERE id = :id", ['id' => $po_id]);
    $po = $db->first();
    
    if (!$po) {
        redirect('purchase_orders.php', 'Purchase order not found', 'error');
    }
    
    // Only allow editing drafts
    if ($po->status !== 'draft') {
        redirect('view_po.php?id=' . $po_id, 'Only draft POs can be edited', 'error');
    }
    
    // Get PO items
    $db->query("SELECT * FROM purchase_order_items WHERE purchase_order_id = :po_id ORDER BY id", ['po_id' => $po_id]);
    $po_items = $db->results();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Get form data
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    $branch_id = (int)($_POST['branch_id'] ?? $user_branch_id);
    $po_date = $_POST['po_date'] ?? date('Y-m-d');
    $expected_delivery_date = $_POST['expected_delivery_date'] ?? null;
    $payment_terms = trim($_POST['payment_terms'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $terms_conditions = trim($_POST['terms_conditions'] ?? '');
    $action = $_POST['action'] ?? 'draft'; // draft or submit
    
    // Financial totals
    $subtotal = floatval($_POST['subtotal'] ?? 0);
    $tax_amount = floatval($_POST['tax_amount'] ?? 0);
    $discount_amount = floatval($_POST['discount_amount'] ?? 0);
    $shipping_cost = floatval($_POST['shipping_cost'] ?? 0);
    $other_charges = floatval($_POST['other_charges'] ?? 0);
    $total_amount = floatval($_POST['total_amount'] ?? 0);
    
    // Get items
    $items = [];
    if (isset($_POST['items']) && is_array($_POST['items'])) {
        foreach ($_POST['items'] as $item) {
            if (!empty($item['item_name']) && floatval($item['quantity']) > 0) {
                $items[] = [
                    'item_type' => $item['item_type'] ?? 'raw_material',
                    'item_name' => trim($item['item_name']),
                    'item_code' => trim($item['item_code'] ?? ''),
                    'unit_of_measure' => trim($item['unit_of_measure'] ?? 'kg'),
                    'quantity' => floatval($item['quantity']),
                    'unit_price' => floatval($item['unit_price']),
                    'discount_percentage' => floatval($item['discount_percentage'] ?? 0),
                    'discount_amount' => floatval($item['discount_amount'] ?? 0),
                    'tax_percentage' => floatval($item['tax_percentage'] ?? 0),
                    'tax_amount' => floatval($item['tax_amount'] ?? 0),
                    'line_total' => floatval($item['line_total']),
                    'notes' => trim($item['notes'] ?? '')
                ];
            }
        }
    }
    
    // Validation
    if ($supplier_id === 0) {
        $errors[] = "Please select a supplier";
    }
    
    if (empty($items)) {
        $errors[] = "Please add at least one item";
    }
    
    if (empty($errors)) {
        try {
            $db->getPdo()->beginTransaction();
            
            if ($po_id > 0) {
                // Update existing PO
                $update_sql = "UPDATE purchase_orders SET
                    supplier_id = :supplier_id,
                    branch_id = :branch_id,
                    po_date = :po_date,
                    expected_delivery_date = :expected_delivery_date,
                    status = :status,
                    payment_terms = :payment_terms,
                    subtotal = :subtotal,
                    tax_amount = :tax_amount,
                    discount_amount = :discount_amount,
                    shipping_cost = :shipping_cost,
                    other_charges = :other_charges,
                    total_amount = :total_amount,
                    notes = :notes,
                    terms_conditions = :terms_conditions,
                    updated_at = NOW()
                WHERE id = :id";
                
                $db->query($update_sql, [
                    'id' => $po_id,
                    'supplier_id' => $supplier_id,
                    'branch_id' => $branch_id,
                    'po_date' => $po_date,
                    'expected_delivery_date' => $expected_delivery_date ?: null,
                    'status' => $action === 'submit' ? 'pending_approval' : 'draft',
                    'payment_terms' => $payment_terms ?: null,
                    'subtotal' => $subtotal,
                    'tax_amount' => $tax_amount,
                    'discount_amount' => $discount_amount,
                    'shipping_cost' => $shipping_cost,
                    'other_charges' => $other_charges,
                    'total_amount' => $total_amount,
                    'notes' => $notes ?: null,
                    'terms_conditions' => $terms_conditions ?: null
                ]);
                
                // Delete old items
                $db->query("DELETE FROM purchase_order_items WHERE purchase_order_id = :po_id", ['po_id' => $po_id]);
                
                $new_po_id = $po_id;
                
            } else {
                // Generate PO number
                $po_number = 'PO-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                // Insert new PO
                $insert_sql = "INSERT INTO purchase_orders (
                    po_number, supplier_id, branch_id, po_date, expected_delivery_date,
                    status, payment_terms, subtotal, tax_amount, discount_amount,
                    shipping_cost, other_charges, total_amount, notes, terms_conditions,
                    created_by_user_id, created_at
                ) VALUES (
                    :po_number, :supplier_id, :branch_id, :po_date, :expected_delivery_date,
                    :status, :payment_terms, :subtotal, :tax_amount, :discount_amount,
                    :shipping_cost, :other_charges, :total_amount, :notes, :terms_conditions,
                    :user_id, NOW()
                )";
                
                $db->query($insert_sql, [
                    'po_number' => $po_number,
                    'supplier_id' => $supplier_id,
                    'branch_id' => $branch_id,
                    'po_date' => $po_date,
                    'expected_delivery_date' => $expected_delivery_date ?: null,
                    'status' => $action === 'submit' ? 'pending_approval' : 'draft',
                    'payment_terms' => $payment_terms ?: null,
                    'subtotal' => $subtotal,
                    'tax_amount' => $tax_amount,
                    'discount_amount' => $discount_amount,
                    'shipping_cost' => $shipping_cost,
                    'other_charges' => $other_charges,
                    'total_amount' => $total_amount,
                    'notes' => $notes ?: null,
                    'terms_conditions' => $terms_conditions ?: null,
                    'user_id' => $user_id
                ]);
                
                $new_po_id = $db->getPdo()->lastInsertId();
            }
            
            // Insert items
            foreach ($items as $item) {
                $item_sql = "INSERT INTO purchase_order_items (
                    purchase_order_id, item_type, item_name, item_code, unit_of_measure,
                    quantity, unit_price, discount_percentage, discount_amount,
                    tax_percentage, tax_amount, line_total, notes
                ) VALUES (
                    :po_id, :item_type, :item_name, :item_code, :unit_of_measure,
                    :quantity, :unit_price, :discount_percentage, :discount_amount,
                    :tax_percentage, :tax_amount, :line_total, :notes
                )";
                
                $db->query($item_sql, array_merge(['po_id' => $new_po_id], $item));
            }
            
            $db->getPdo()->commit();
            
            $message = $po_id > 0 ? 'Purchase order updated successfully' : 'Purchase order created successfully';
            if ($action === 'submit') {
                $message .= ' and submitted for approval';
            }
            
            redirect('view_po.php?id=' . $new_po_id, $message, 'success');
            
        } catch (Exception $e) {
            $db->getPdo()->rollBack();
            $errors[] = "Error: " . $e->getMessage();
        }
    }
}

// Get suppliers
$db->query("SELECT id, company_name, payment_terms FROM suppliers WHERE status = 'active' ORDER BY company_name");
$suppliers = $db->results();

// Get branches
$db->query("SELECT id, name FROM branches ORDER BY name");
$branches = $db->results();

require_once '../templates/header.php';
?>

<div class="container mx-auto max-w-7xl">
    
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
            <p class="mt-2 text-gray-600">
                <?php echo $po_id > 0 ? 'Update purchase order details' : 'Create a new purchase order'; ?>
            </p>
        </div>
        <a href="purchase_orders.php" class="text-gray-600 hover:text-gray-800">
            <i class="fas fa-arrow-left mr-2"></i>Back to POs
        </a>
    </div>

    <!-- Error Messages -->
    <?php if (!empty($errors)): ?>
    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
        <div class="flex">
            <i class="fas fa-exclamation-circle text-red-500 mt-1 mr-3"></i>
            <div>
                <h3 class="text-red-800 font-medium">Please fix the following errors:</h3>
                <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" action="" id="poForm" class="space-y-6">
        
        <!-- Basic Information -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-900 mb-4 pb-2 border-b">Basic Information</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                
                <!-- Supplier -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Supplier <span class="text-red-500">*</span>
                    </label>
                    <select name="supplier_id" 
                            id="supplier_id"
                            required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                        <option value="">Select Supplier</option>
                        <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo $supplier->id; ?>" 
                                data-payment-terms="<?php echo htmlspecialchars($supplier->payment_terms ?? ''); ?>"
                                <?php echo ($po->supplier_id ?? '') == $supplier->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($supplier->company_name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Branch -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Branch <span class="text-red-500">*</span>
                    </label>
                    <select name="branch_id" 
                            required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                        <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo $branch->id; ?>" 
                                <?php echo ($po->branch_id ?? $user_branch_id) == $branch->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($branch->name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- PO Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        PO Date <span class="text-red-500">*</span>
                    </label>
                    <input type="date" 
                           name="po_date" 
                           value="<?php echo $po->po_date ?? date('Y-m-d'); ?>"
                           required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                </div>

                <!-- Expected Delivery Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Expected Delivery Date
                    </label>
                    <input type="date" 
                           name="expected_delivery_date" 
                           value="<?php echo $po->expected_delivery_date ?? ''; ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                </div>

                <!-- Payment Terms -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Payment Terms
                    </label>
                    <input type="text" 
                           name="payment_terms" 
                           id="payment_terms"
                           value="<?php echo htmlspecialchars($po->payment_terms ?? ''); ?>"
                           placeholder="e.g., Net 30, Net 60, COD"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                </div>

            </div>
        </div>

        <!-- Line Items -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between mb-4 pb-2 border-b">
                <h2 class="text-xl font-bold text-gray-900">Line Items</h2>
                <button type="button" 
                        onclick="addItemRow()" 
                        class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm transition">
                    <i class="fas fa-plus mr-2"></i>Add Item
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full" id="itemsTable">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">Type</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">Item Name *</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">Code</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">UOM</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Qty *</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Unit Price *</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Disc %</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Tax %</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Line Total</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500">Action</th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                        <?php if (!empty($po_items)): ?>
                            <?php foreach ($po_items as $item): ?>
                            <tr class="item-row border-b">
                                <td class="px-3 py-2">
                                    <select name="items[]['item_type']" class="w-full px-2 py-1 border rounded text-sm">
                                        <option value="raw_material" <?php echo $item->item_type === 'raw_material' ? 'selected' : ''; ?>>Raw Material</option>
                                        <option value="finished_goods" <?php echo $item->item_type === 'finished_goods' ? 'selected' : ''; ?>>Finished Goods</option>
                                        <option value="packaging" <?php echo $item->item_type === 'packaging' ? 'selected' : ''; ?>>Packaging</option>
                                        <option value="supplies" <?php echo $item->item_type === 'supplies' ? 'selected' : ''; ?>>Supplies</option>
                                        <option value="other" <?php echo $item->item_type === 'other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </td>
                                <td class="px-3 py-2">
                                    <input type="text" name="items[][item_name]" value="<?php echo htmlspecialchars($item->item_name); ?>" required class="w-full px-2 py-1 border rounded text-sm" placeholder="Item name">
                                </td>
                                <td class="px-3 py-2">
                                    <input type="text" name="items[][item_code]" value="<?php echo htmlspecialchars($item->item_code ?? ''); ?>" class="w-full px-2 py-1 border rounded text-sm" placeholder="Code">
                                </td>
                                <td class="px-3 py-2">
                                    <input type="text" name="items[][unit_of_measure]" value="<?php echo htmlspecialchars($item->unit_of_measure ?? 'kg'); ?>" class="w-full px-2 py-1 border rounded text-sm" placeholder="kg">
                                </td>
                                <td class="px-3 py-2">
                                    <input type="number" name="items[][quantity]" value="<?php echo $item->quantity; ?>" step="0.001" required class="item-qty w-24 px-2 py-1 border rounded text-sm text-right" onchange="calculateLineTotal(this)">
                                </td>
                                <td class="px-3 py-2">
                                    <input type="number" name="items[][unit_price]" value="<?php echo $item->unit_price; ?>" step="0.01" required class="item-price w-24 px-2 py-1 border rounded text-sm text-right" onchange="calculateLineTotal(this)">
                                </td>
                                <td class="px-3 py-2">
                                    <input type="number" name="items[][discount_percentage]" value="<?php echo $item->discount_percentage; ?>" step="0.01" class="item-disc-pct w-20 px-2 py-1 border rounded text-sm text-right" onchange="calculateLineTotal(this)">
                                    <input type="hidden" name="items[][discount_amount]" class="item-disc-amt" value="<?php echo $item->discount_amount; ?>">
                                </td>
                                <td class="px-3 py-2">
                                    <input type="number" name="items[][tax_percentage]" value="<?php echo $item->tax_percentage; ?>" step="0.01" class="item-tax-pct w-20 px-2 py-1 border rounded text-sm text-right" onchange="calculateLineTotal(this)">
                                    <input type="hidden" name="items[][tax_amount]" class="item-tax-amt" value="<?php echo $item->tax_amount; ?>">
                                </td>
                                <td class="px-3 py-2">
                                    <input type="number" name="items[][line_total]" value="<?php echo $item->line_total; ?>" readonly class="item-total w-28 px-2 py-1 border rounded text-sm text-right font-bold bg-gray-50">
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <button type="button" onclick="removeItemRow(this)" class="text-red-600 hover:text-red-800">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Totals -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-900 mb-4 pb-2 border-b">Totals & Charges</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Shipping Cost (BDT)</label>
                        <input type="number" 
                               name="shipping_cost" 
                               id="shipping_cost"
                               value="<?php echo $po->shipping_cost ?? '0'; ?>"
                               step="0.01"
                               onchange="calculateGrandTotal()"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Other Charges (BDT)</label>
                        <input type="number" 
                               name="other_charges" 
                               id="other_charges"
                               value="<?php echo $po->other_charges ?? '0'; ?>"
                               step="0.01"
                               onchange="calculateGrandTotal()"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                    </div>
                </div>

                <div class="bg-gray-50 p-4 rounded-lg space-y-3">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Subtotal:</span>
                        <span class="font-semibold" id="subtotal_display">BDT 0.00</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Total Discount:</span>
                        <span class="font-semibold text-green-600" id="discount_display">BDT 0.00</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Total Tax:</span>
                        <span class="font-semibold" id="tax_display">BDT 0.00</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Shipping:</span>
                        <span class="font-semibold" id="shipping_display">BDT 0.00</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Other Charges:</span>
                        <span class="font-semibold" id="other_display">BDT 0.00</span>
                    </div>
                    <div class="border-t border-gray-300 pt-3 flex justify-between">
                        <span class="font-bold text-lg text-gray-900">Grand Total:</span>
                        <span class="font-bold text-lg text-primary-600" id="total_display">BDT 0.00</span>
                    </div>
                </div>

                <!-- Hidden fields for totals -->
                <input type="hidden" name="subtotal" id="subtotal" value="0">
                <input type="hidden" name="tax_amount" id="tax_amount" value="0">
                <input type="hidden" name="discount_amount" id="discount_amount" value="0">
                <input type="hidden" name="total_amount" id="total_amount" value="0">
            </div>
        </div>

        <!-- Notes -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-900 mb-4 pb-2 border-b">Additional Information</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Internal Notes</label>
                    <textarea name="notes" 
                              rows="4"
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                              placeholder="Add any internal notes..."><?php echo htmlspecialchars($po->notes ?? ''); ?></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Terms & Conditions</label>
                    <textarea name="terms_conditions" 
                              rows="4"
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                              placeholder="Add terms and conditions..."><?php echo htmlspecialchars($po->terms_conditions ?? ''); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="flex items-center justify-end gap-4 pb-6">
            <a href="purchase_orders.php" class="px-6 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                Cancel
            </a>
            <button type="submit" 
                    name="action" 
                    value="draft"
                    class="px-6 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition">
                <i class="fas fa-save mr-2"></i>Save as Draft
            </button>
            <button type="submit" 
                    name="action" 
                    value="submit"
                    class="px-6 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg transition">
                <i class="fas fa-paper-plane mr-2"></i>Submit for Approval
            </button>
        </div>

    </form>

</div>

<script>
// Auto-fill payment terms when supplier is selected
document.getElementById('supplier_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const paymentTerms = selectedOption.getAttribute('data-payment-terms');
    if (paymentTerms) {
        document.getElementById('payment_terms').value = paymentTerms;
    }
});

// Add new item row
function addItemRow() {
    const tbody = document.getElementById('itemsBody');
    const row = document.createElement('tr');
    row.className = 'item-row border-b';
    row.innerHTML = `
        <td class="px-3 py-2">
            <select name="items[][item_type]" class="w-full px-2 py-1 border rounded text-sm">
                <option value="raw_material">Raw Material</option>
                <option value="finished_goods">Finished Goods</option>
                <option value="packaging">Packaging</option>
                <option value="supplies">Supplies</option>
                <option value="other">Other</option>
            </select>
        </td>
        <td class="px-3 py-2">
            <input type="text" name="items[][item_name]" required class="w-full px-2 py-1 border rounded text-sm" placeholder="Item name">
        </td>
        <td class="px-3 py-2">
            <input type="text" name="items[][item_code]" class="w-full px-2 py-1 border rounded text-sm" placeholder="Code">
        </td>
        <td class="px-3 py-2">
            <input type="text" name="items[][unit_of_measure]" value="kg" class="w-full px-2 py-1 border rounded text-sm" placeholder="kg">
        </td>
        <td class="px-3 py-2">
            <input type="number" name="items[][quantity]" value="0" step="0.001" required class="item-qty w-24 px-2 py-1 border rounded text-sm text-right" onchange="calculateLineTotal(this)">
        </td>
        <td class="px-3 py-2">
            <input type="number" name="items[][unit_price]" value="0" step="0.01" required class="item-price w-24 px-2 py-1 border rounded text-sm text-right" onchange="calculateLineTotal(this)">
        </td>
        <td class="px-3 py-2">
            <input type="number" name="items[][discount_percentage]" value="0" step="0.01" class="item-disc-pct w-20 px-2 py-1 border rounded text-sm text-right" onchange="calculateLineTotal(this)">
            <input type="hidden" name="items[][discount_amount]" class="item-disc-amt" value="0">
        </td>
        <td class="px-3 py-2">
            <input type="number" name="items[][tax_percentage]" value="0" step="0.01" class="item-tax-pct w-20 px-2 py-1 border rounded text-sm text-right" onchange="calculateLineTotal(this)">
            <input type="hidden" name="items[][tax_amount]" class="item-tax-amt" value="0">
        </td>
        <td class="px-3 py-2">
            <input type="number" name="items[][line_total]" value="0" readonly class="item-total w-28 px-2 py-1 border rounded text-sm text-right font-bold bg-gray-50">
        </td>
        <td class="px-3 py-2 text-center">
            <button type="button" onclick="removeItemRow(this)" class="text-red-600 hover:text-red-800">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    `;
    tbody.appendChild(row);
}

// Remove item row
function removeItemRow(button) {
    button.closest('tr').remove();
    calculateGrandTotal();
}

// Calculate line total
function calculateLineTotal(input) {
    const row = input.closest('tr');
    const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
    const price = parseFloat(row.querySelector('.item-price').value) || 0;
    const discPct = parseFloat(row.querySelector('.item-disc-pct').value) || 0;
    const taxPct = parseFloat(row.querySelector('.item-tax-pct').value) || 0;
    
    // Calculate base amount
    const baseAmount = qty * price;
    
    // Calculate discount
    const discAmount = baseAmount * (discPct / 100);
    row.querySelector('.item-disc-amt').value = discAmount.toFixed(2);
    
    // Amount after discount
    const afterDiscount = baseAmount - discAmount;
    
    // Calculate tax
    const taxAmount = afterDiscount * (taxPct / 100);
    row.querySelector('.item-tax-amt').value = taxAmount.toFixed(2);
    
    // Line total
    const lineTotal = afterDiscount + taxAmount;
    row.querySelector('.item-total').value = lineTotal.toFixed(2);
    
    calculateGrandTotal();
}

// Calculate grand total
function calculateGrandTotal() {
    let subtotal = 0;
    let totalDiscount = 0;
    let totalTax = 0;
    
    document.querySelectorAll('.item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
        const price = parseFloat(row.querySelector('.item-price').value) || 0;
        const discAmt = parseFloat(row.querySelector('.item-disc-amt').value) || 0;
        const taxAmt = parseFloat(row.querySelector('.item-tax-amt').value) || 0;
        
        subtotal += (qty * price);
        totalDiscount += discAmt;
        totalTax += taxAmt;
    });
    
    const shipping = parseFloat(document.getElementById('shipping_cost').value) || 0;
    const other = parseFloat(document.getElementById('other_charges').value) || 0;
    
    const grandTotal = subtotal - totalDiscount + totalTax + shipping + other;
    
    // Update displays
    document.getElementById('subtotal_display').textContent = 'BDT ' + subtotal.toFixed(2);
    document.getElementById('discount_display').textContent = 'BDT ' + totalDiscount.toFixed(2);
    document.getElementById('tax_display').textContent = 'BDT ' + totalTax.toFixed(2);
    document.getElementById('shipping_display').textContent = 'BDT ' + shipping.toFixed(2);
    document.getElementById('other_display').textContent = 'BDT ' + other.toFixed(2);
    document.getElementById('total_display').textContent = 'BDT ' + grandTotal.toFixed(2);
    
    // Update hidden fields
    document.getElementById('subtotal').value = subtotal.toFixed(2);
    document.getElementById('discount_amount').value = totalDiscount.toFixed(2);
    document.getElementById('tax_amount').value = totalTax.toFixed(2);
    document.getElementById('total_amount').value = grandTotal.toFixed(2);
}

// Add first row if empty
<?php if (empty($po_items)): ?>
window.addEventListener('DOMContentLoaded', function() {
    addItemRow();
});
<?php else: ?>
window.addEventListener('DOMContentLoaded', function() {
    calculateGrandTotal();
});
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>
<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts','sales-srg', 'sales-demra', 'sales-other'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Create Credit Order';
$error = null;
$success = null;

// Get customers with credit limits
$customers = $db->query(
    "SELECT id, name, phone_number, credit_limit, current_balance, status
     FROM customers 
     WHERE status = 'active'
     ORDER BY name ASC"
)->results();

// Get all active products
$products = $db->query(
    "SELECT id, base_name, base_sku, status
     FROM products
     WHERE status = 'active'
     ORDER BY base_name ASC"
)->results();

// Get variants with prices BY BRANCH and available stock
$variants = $db->query(
    "SELECT pv.id as variant_id, 
            pv.product_id, 
            pv.grade, 
            pv.weight_variant,
            pv.unit_of_measure,
            pv.sku,
            pp.id as price_id,
            pp.branch_id,
            pp.unit_price,
            b.name as branch_name,
            b.code as branch_code,
            COALESCE(inv.quantity, 0) as stock_usable
     FROM product_variants pv
     JOIN product_prices pp ON pv.id = pp.variant_id
     JOIN branches b ON pp.branch_id = b.id
     LEFT JOIN inventory inv ON pv.id = inv.variant_id AND pp.branch_id = inv.branch_id
     WHERE pv.status = 'active' 
       AND pp.status = 'active' 
       AND pp.is_active = 1
       AND b.status = 'active'
     ORDER BY pv.product_id, pv.grade, pv.weight_variant, b.name"
)->results();

// Debug: Log the variants to check data
error_log("Total variants fetched: " . count($variants));
if (count($variants) > 0) {
    error_log("Sample variant: " . json_encode($variants[0]));
}

// Group variants by product for dropdown
$product_variants = [];
foreach ($variants as $v) {
    if (!isset($product_variants[$v->product_id])) {
        $product_variants[$v->product_id] = [];
    }
    $product_variants[$v->product_id][] = $v;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_order') {
    try {
        $db->getPdo()->beginTransaction();
        
        $customer_id = (int)$_POST['customer_id'];
        $order_type = $_POST['order_type'];
        $required_date = $_POST['required_date'];
        $shipping_address = trim($_POST['shipping_address']);
        $special_instructions = trim($_POST['special_instructions']);
        $advance_paid = floatval($_POST['advance_paid'] ?? 0);
        
        $subtotal = floatval($_POST['subtotal']);
        $discount = floatval($_POST['discount_amount']);
        $tax = floatval($_POST['tax_amount']);
        $total = $subtotal - $discount + $tax;
        $balance_due = $total - $advance_paid;
        
        $order_number = 'CR-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $exists = $db->query("SELECT id FROM credit_orders WHERE order_number = ?", [$order_number])->first();
        if ($exists) {
            $order_number .= '-' . time();
        }
        
        $order_id = $db->insert('credit_orders', [
            'order_number' => $order_number,
            'customer_id' => $customer_id,
            'order_date' => date('Y-m-d'),
            'required_date' => $required_date,
            'order_type' => $order_type,
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'advance_paid' => $advance_paid,
            'balance_due' => $balance_due,
            'status' => 'pending_approval',
            'shipping_address' => $shipping_address,
            'special_instructions' => $special_instructions,
            'created_by_user_id' => $user_id
        ]);
        
        if (!$order_id) {
            throw new Exception("Failed to create order");
        }
        
        $items = json_decode($_POST['items_json'], true);
        foreach ($items as $item) {
            $db->insert('credit_order_items', [
                'order_id' => $order_id,
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'discount_amount' => $item['discount'] ?? 0,
                'tax_amount' => $item['tax'] ?? 0,
                'line_total' => $item['line_total']
            ]);
        }
        
        $db->insert('credit_order_workflow', [
            'order_id' => $order_id,
            'from_status' => 'draft',
            'to_status' => 'pending_approval',
            'action' => 'submit',
            'performed_by_user_id' => $user_id,
            'comments' => 'Order created and submitted for approval'
        ]);
        
        $db->getPdo()->commit();
        $_SESSION['success_flash'] = "Order $order_number created successfully! Awaiting approval.";
        header('Location: index.php');
        exit();
        
    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        $error = "Failed to create order: " . $e->getMessage();
    }
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">Create a new credit sale order</p>
</div>

<?php if ($error): ?>

<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Error</p>
    <p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<form method="POST" id="creditOrderForm" class="space-y-6">
    <input type="hidden" name="action" value="create_order">
    <input type="hidden" name="subtotal" id="subtotal">
    <input type="hidden" name="discount_amount" id="discount_amount">
    <input type="hidden" name="tax_amount" id="tax_amount">
    <input type="hidden" name="items_json" id="items_json">

```
<!-- Customer & Order Info -->
<div class="bg-white rounded-lg shadow-md p-6">
    <h2 class="text-xl font-bold text-gray-800 mb-4">Customer Information</h2>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Customer *</label>
            <select name="customer_id" id="customer_id" required class="w-full px-4 py-2 border rounded-lg" onchange="updateCustomerInfo()">
                <option value="">-- Select Customer --</option>
                <?php foreach ($customers as $customer): ?>
                <option value="<?php echo $customer->id; ?>" 
                        data-credit-limit="<?php echo $customer->credit_limit; ?>"
                        data-balance="<?php echo $customer->current_balance; ?>">
                    <?php echo htmlspecialchars($customer->name); ?> 
                    <?php if ($customer->credit_limit > 0): ?>
                        (Limit: ৳<?php echo number_format($customer->credit_limit, 0); ?>)
                    <?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Order Type *</label>
            <select name="order_type" required class="w-full px-4 py-2 border rounded-lg">
                <option value="credit">Credit Sale</option>
                <option value="advance_payment">Advance Payment</option>
            </select>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Required Date *</label>
            <input type="date" name="required_date" required class="w-full px-4 py-2 border rounded-lg"
                value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Advance Payment</label>
            <input type="number" name="advance_paid" step="0.01" class="w-full px-4 py-2 border rounded-lg" 
                   value="0.00" onchange="calculateTotals()">
        </div>
    </div>
    
    <div class="mt-4">
        <label class="block text-sm font-medium text-gray-700 mb-2">Shipping Address *</label>
        <textarea name="shipping_address" required rows="2" class="w-full px-4 py-2 border rounded-lg"></textarea>
    </div>
    
    <div class="mt-4">
        <label class="block text-sm font-medium text-gray-700 mb-2">Special Instructions</label>
        <textarea name="special_instructions" rows="2" class="w-full px-4 py-2 border rounded-lg"></textarea>
    </div>
    
    <!-- Credit Info Display -->
    <div id="creditInfo" class="mt-4 hidden">
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="grid grid-cols-3 gap-4 text-sm">
                <div>
                    <p class="text-gray-600">Credit Limit</p>
                    <p class="text-lg font-bold text-gray-900" id="creditLimit">৳0</p>
                </div>
                <div>
                    <p class="text-gray-600">Available Credit</p>
                    <p class="text-lg font-bold text-green-600" id="availableCredit">৳0</p>
                </div>
                <div>
                    <p class="text-gray-600">Order Will Use</p>
                    <p class="text-lg font-bold text-blue-600" id="creditUsage">0%</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Order Items -->
<div class="bg-white rounded-lg shadow-md p-6">
    <h2 class="text-xl font-bold text-gray-800 mb-4">Order Items</h2>
    
    <!-- Add Item Row -->
    <div class="grid grid-cols-12 gap-3 mb-4 p-4 bg-gray-50 rounded-lg">
        <div class="col-span-3">
            <label class="block text-sm font-medium text-gray-700 mb-2">Product *</label>
            <select id="add_product" class="w-full px-3 py-2 border rounded-lg text-sm">
                <option value="">-- Select Product --</option>
                <?php foreach ($products as $product): ?>
                <option value="<?php echo $product->id; ?>">
                    <?php echo htmlspecialchars($product->base_name); ?>
                    <?php if ($product->base_sku): ?>
                        (<?php echo htmlspecialchars($product->base_sku); ?>)
                    <?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="col-span-3">
            <label class="block text-sm font-medium text-gray-700 mb-2">Variant & Source *</label>
            <select id="add_variant" class="w-full px-3 py-2 border rounded-lg text-xs" disabled>
                <option value="">-- Select Variant & Source --</option>
            </select>
        </div>
        
        <div class="col-span-1">
            <label class="block text-sm font-medium text-gray-700 mb-2">Unit</label>
            <input type="text" id="add_unit" class="w-full px-2 py-2 border rounded-lg bg-gray-100 text-center text-sm" readonly>
        </div>
        
        <div class="col-span-1">
            <label class="block text-sm font-medium text-gray-700 mb-2">Stock</label>
            <input type="text" id="add_stock" class="w-full px-2 py-2 border rounded-lg bg-gray-100 text-center font-bold text-sm" readonly>
        </div>
        
        <div class="col-span-1">
            <label class="block text-sm font-medium text-gray-700 mb-2">Qty *</label>
            <input type="number" id="add_quantity" step="1" min="1" class="w-full px-2 py-2 border rounded-lg text-sm" value="1">
        </div>
        
        <div class="col-span-1">
            <label class="block text-sm font-medium text-gray-700 mb-2">Price</label>
            <input type="number" id="add_price" step="0.01" class="w-full px-2 py-2 border rounded-lg bg-gray-100 text-sm" readonly>
        </div>
        
        <div class="col-span-1">
            <label class="block text-sm font-medium text-gray-700 mb-2">Disc</label>
            <div class="flex gap-1">
                <select id="add_discount_type" class="w-10 px-1 py-2 border rounded-l-lg text-xs">
                    <option value="percent">%</option>
                    <option value="fixed">৳</option>
                </select>
                <input type="number" id="add_discount" step="0.01" min="0" class="w-14 px-1 py-2 border rounded-r-lg text-xs" value="0">
            </div>
        </div>
        
        <div class="col-span-1 flex items-end">
            <button type="button" onclick="addItem()" class="w-full px-3 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium text-sm">
                <i class="fas fa-cart-plus"></i> Add
            </button>
        </div>
    </div>
    
    <!-- Items Table -->
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Variant</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Source</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">SKU</th>
                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Discount</th>
                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                </tr>
            </thead>
            <tbody id="itemsBody" class="bg-white divide-y divide-gray-200">
                <tr>
                    <td colspan="9" class="px-4 py-8 text-center text-gray-400">
                        <i class="fas fa-shopping-cart text-5xl mb-3 opacity-50"></i>
                        <p class="text-lg">Cart is empty - Add products above</p>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <!-- Totals -->
    <div class="mt-6 border-t pt-6">
        <div class="flex justify-end">
            <div class="w-full md:w-1/2 lg:w-1/3 space-y-3">
                <div class="flex justify-between text-base">
                    <span class="text-gray-600">Subtotal:</span>
                    <span class="font-bold" id="display_subtotal">৳0.00</span>
                </div>
                
                <div class="flex justify-between text-base">
                    <span class="text-gray-600">Item Discounts:</span>
                    <span class="font-bold text-red-600" id="display_item_discount">৳0.00</span>
                </div>
                
                <!-- Cart Discount Section -->
                <div class="border-t pt-3">
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-sm font-medium text-gray-700">Cart Discount:</label>
                        <div class="flex gap-2 items-center">
                            <select id="cart_discount_type" class="px-2 py-1 border rounded text-xs" onchange="calculateTotals()">
                                <option value="percent">%</option>
                                <option value="fixed">৳</option>
                            </select>
                            <input type="number" id="cart_discount_value" step="0.01" min="0" 
                                   class="w-24 px-2 py-1 border rounded text-sm" 
                                   value="0" 
                                   onchange="calculateTotals()"
                                   placeholder="0.00">
                            <button type="button" onclick="applyCartDiscount()" 
                                    class="px-3 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">
                                Apply
                            </button>
                        </div>
                    </div>
                    <div class="flex justify-between text-base">
                        <span class="text-gray-600">Cart Discount Applied:</span>
                        <span class="font-bold text-orange-600" id="display_cart_discount">৳0.00</span>
                    </div>
                </div>
                
                <div class="flex justify-between text-base">
                    <span class="text-gray-600">Total Discount:</span>
                    <span class="font-bold text-red-600" id="display_total_discount">৳0.00</span>
                </div>
                
                <div class="flex justify-between text-base">
                    <span class="text-gray-600">Tax:</span>
                    <span class="font-bold" id="display_tax">৳0.00</span>
                </div>
                
                <div class="flex justify-between text-lg border-t pt-3 mt-3">
                    <span class="font-bold text-gray-900">Total:</span>
                    <span class="font-bold text-blue-600" id="display_total">৳0.00</span>
                </div>
                
                <div class="flex justify-between text-lg">
                    <span class="font-bold text-gray-900">Balance Due:</span>
                    <span class="font-bold text-green-600" id="display_balance">৳0.00</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Submit -->
<div class="flex justify-end gap-4">
    <a href="index.php" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
        Cancel
    </a>
    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
        <i class="fas fa-check mr-2"></i>Submit for Approval
    </button>
</div>
```

</form>

</div>

<script>
const productVariants = <?php echo json_encode($product_variants); ?>;
console.log('Product Variants Object:', productVariants);
console.log('Available Product IDs:', Object.keys(productVariants));

let orderItems = [];
let cartDiscount = {
    type: 'percent',
    value: 0,
    amount: 0
};

// Product selection handler
document.getElementById('add_product').addEventListener('change', function() {
    console.log('Product selected:', this.value);
    loadVariants();
});

function loadVariants() {
    const productSelect = document.getElementById('add_product');
    const productId = productSelect.value;
    const variantSelect = document.getElementById('add_variant');
    const priceInput = document.getElementById('add_price');
    const unitInput = document.getElementById('add_unit');
    const stockInput = document.getElementById('add_stock');
    
    console.log('loadVariants called with productId:', productId);
    console.log('productVariants[productId]:', productVariants[productId]);
    
    variantSelect.innerHTML = '<option value="">-- Select Variant & Source --</option>';
    priceInput.value = '';
    unitInput.value = '';
    stockInput.value = '';
    
    if (!productId) {
        console.log('No product selected');
        variantSelect.disabled = true;
        return;
    }
    
    if (!productVariants[productId]) {
        console.error('No variants found for product ID:', productId);
        console.log('Available products in productVariants:', Object.keys(productVariants));
        variantSelect.disabled = true;
        return;
    }
    
    variantSelect.disabled = false;
    const variants = productVariants[productId];
    console.log('Number of variants found:', variants.length);
    
    variants.forEach((v, index) => {
        console.log(`Processing variant ${index}:`, v);
        
        const opt = document.createElement('option');
        
        // Create unique identifier using price_id
        opt.value = v.price_id;
        
        // Build variant display name
        let variantName = [];
        if (v.grade) variantName.push(v.grade);
        if (v.weight_variant) variantName.push(v.weight_variant);
        
        // Build display text with source and stock info
        const stockStatus = v.stock_usable > 0 ? 
            `Stock: ${parseFloat(v.stock_usable).toFixed(0)}` : 
            'Out of Stock';
        
        const stockClass = v.stock_usable > 0 ? '' : ' [OUT]';
        
        opt.text = `${variantName.join(' - ')} | ${v.branch_name} | ৳${parseFloat(v.unit_price).toFixed(0)} | ${stockStatus}${stockClass}`;
        
        console.log('Creating option:', opt.text);
        
        // Store all data in dataset
        opt.dataset.variantId = v.variant_id;
        opt.dataset.priceId = v.price_id;
        opt.dataset.branchId = v.branch_id;
        opt.dataset.branchName = v.branch_name;
        opt.dataset.branchCode = v.branch_code;
        opt.dataset.price = v.unit_price;
        opt.dataset.sku = v.sku;
        opt.dataset.unit = v.unit_of_measure;
        opt.dataset.grade = v.grade || '';
        opt.dataset.weight = v.weight_variant || '';
        opt.dataset.stock = v.stock_usable;
        
        // Disable if out of stock
        if (v.stock_usable <= 0) {
            opt.disabled = true;
            opt.style.color = '#999';
        }
        
        variantSelect.appendChild(opt);
    });
    
    console.log('Total options added:', variantSelect.options.length - 1); // Subtract the placeholder
}

// Variant selection handler
document.getElementById('add_variant').addEventListener('change', function() {
    const option = this.options[this.selectedIndex];
    if (option.value) {
        document.getElementById('add_price').value = option.dataset.price || '';
        document.getElementById('add_unit').value = option.dataset.unit || '';
        
        const stock = parseFloat(option.dataset.stock) || 0;
        const stockInput = document.getElementById('add_stock');
        stockInput.value = stock.toFixed(0);
        
        // Color code based on stock level
        if (stock <= 0) {
            stockInput.style.color = '#dc2626';
            stockInput.style.fontWeight = 'bold';
        } else if (stock < 10) {
            stockInput.style.color = '#ea580c';
            stockInput.style.fontWeight = 'bold';
        } else {
            stockInput.style.color = '#16a34a';
            stockInput.style.fontWeight = 'bold';
        }
    } else {
        document.getElementById('add_price').value = '';
        document.getElementById('add_unit').value = '';
        document.getElementById('add_stock').value = '';
    }
});

function addItem() {
    const productSelect = document.getElementById('add_product');
    const variantSelect = document.getElementById('add_variant');
    const quantity = parseFloat(document.getElementById('add_quantity').value) || 0;
    const price = parseFloat(document.getElementById('add_price').value) || 0;
    const discountValue = parseFloat(document.getElementById('add_discount').value) || 0;
    const discountType = document.getElementById('add_discount_type').value;
    
    if (!productSelect.value) {
        alert('Please select a product');
        return;
    }
    
    if (!variantSelect.value) {
        alert('Please select a variant and source');
        return;
    }
    
    if (quantity <= 0) {
        alert('Please enter a valid quantity');
        return;
    }
    
    if (price <= 0) {
        alert('Invalid price');
        return;
    }
    
    const variantOption = variantSelect.options[variantSelect.selectedIndex];
    const availableStock = parseFloat(variantOption.dataset.stock) || 0;
    
    // Check stock availability
    if (quantity > availableStock) {
        if (!confirm(`Warning: Requested quantity (${quantity}) exceeds available stock (${availableStock}). Continue anyway?`)) {
            return;
        }
    }
    
    // Calculate discount amount per unit
    let discountPerUnit = 0;
    if (discountType === 'percent') {
        discountPerUnit = (price * discountValue) / 100;
    } else {
        discountPerUnit = discountValue;
    }
    
    const totalDiscountAmount = discountPerUnit * quantity;
    const finalPricePerUnit = price - discountPerUnit;
    const lineTotal = quantity * finalPricePerUnit;
    
    let variantDisplay = [];
    if (variantOption.dataset.grade) variantDisplay.push(variantOption.dataset.grade);
    if (variantOption.dataset.weight) variantDisplay.push(variantOption.dataset.weight);
    
    const item = {
        product_id: productSelect.value,
        product_name: productSelect.options[productSelect.selectedIndex].text.split('(')[0].trim(),
        variant_id: variantOption.dataset.variantId,
        price_id: variantOption.dataset.priceId,
        branch_id: variantOption.dataset.branchId,
        branch_name: variantOption.dataset.branchName,
        branch_code: variantOption.dataset.branchCode,
        variant_name: variantDisplay.join(' - '),
        variant_sku: variantOption.dataset.sku,
        unit_of_measure: variantOption.dataset.unit,
        quantity: quantity,
        unit_price: price,
        discount_type: discountType,
        discount_value: discountValue,
        discount_per_unit: discountPerUnit,
        discount: totalDiscountAmount,
        tax: 0,
        line_total: lineTotal,
        available_stock: availableStock
    };
    
    orderItems.push(item);
    renderItems();
    calculateTotals();
    
    // Reset form
    document.getElementById('add_product').value = '';
    document.getElementById('add_variant').innerHTML = '<option value="">-- Select Variant & Source --</option>';
    document.getElementById('add_variant').disabled = true;
    document.getElementById('add_quantity').value = '1';
    document.getElementById('add_price').value = '';
    document.getElementById('add_discount').value = '0';
    document.getElementById('add_discount_type').value = 'percent';
    document.getElementById('add_unit').value = '';
    document.getElementById('add_stock').value = '';
}

function renderItems() {
    const tbody = document.getElementById('itemsBody');
    tbody.innerHTML = '';
    
    if (orderItems.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="px-4 py-8 text-center text-gray-400">
                    <i class="fas fa-shopping-cart text-5xl mb-3 opacity-50"></i>
                    <p class="text-lg">Cart is empty - Add products above</p>
                </td>
            </tr>
        `;
        return;
    }
    
    orderItems.forEach((item, index) => {
        const discountDisplay = item.discount_type === 'percent' 
            ? `${item.discount_value}%` 
            : `৳${item.discount_value.toFixed(2)}`;
        
        const stockWarning = item.quantity > item.available_stock ? 
            `<span class="text-red-600 text-xs block mt-1">⚠ Exceeds stock!</span>` : '';
        
        const row = document.createElement('tr');
        row.className = 'hover:bg-gray-50';
        row.innerHTML = `
            <td class="px-3 py-3 text-sm">${item.product_name}</td>
            <td class="px-3 py-3 text-sm">${item.variant_name || '-'}</td>
            <td class="px-3 py-3 text-sm">
                <span class="inline-block px-2 py-1 bg-blue-100 text-blue-800 rounded text-xs font-semibold">
                    ${item.branch_code}
                </span>
            </td>
            <td class="px-3 py-3 text-xs text-gray-600">${item.variant_sku}</td>
            <td class="px-3 py-3 text-sm text-right">
                <input type="number" value="${item.quantity}" min="1" step="1"
                       onchange="updateQuantity(${index}, this.value)"
                       class="w-16 px-2 py-1 border rounded text-right text-sm">
                <span class="text-xs text-gray-500 ml-1">${item.unit_of_measure}</span>
                ${stockWarning}
            </td>
            <td class="px-3 py-3 text-sm text-right">৳${item.unit_price.toFixed(2)}</td>
            <td class="px-3 py-3 text-sm text-right text-red-600">
                <span class="text-xs text-gray-500 block">${discountDisplay}/unit</span>
                ৳${item.discount.toFixed(2)}
            </td>
            <td class="px-3 py-3 text-sm text-right font-bold text-green-600">৳${item.line_total.toFixed(2)}</td>
            <td class="px-3 py-3 text-center">
                <button type="button" onclick="removeItem(${index})" class="text-red-600 hover:text-red-900">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

function updateQuantity(index, newQty) {
    const qty = parseFloat(newQty) || 1;
    orderItems[index].quantity = qty;
    orderItems[index].discount = orderItems[index].discount_per_unit * qty;
    orderItems[index].line_total = qty * (orderItems[index].unit_price - orderItems[index].discount_per_unit);
    
    renderItems();
    calculateTotals();
}

function removeItem(index) {
    orderItems.splice(index, 1);
    renderItems();
    calculateTotals();
}

function applyCartDiscount() {
    cartDiscount.type = document.getElementById('cart_discount_type').value;
    cartDiscount.value = parseFloat(document.getElementById('cart_discount_value').value) || 0;
    calculateTotals();
}

function calculateTotals() {
    // Calculate subtotal (before any discounts)
    const subtotal = orderItems.reduce((sum, item) => sum + (item.unit_price * item.quantity), 0);
    
    // Calculate item-level discounts
    const itemDiscount = orderItems.reduce((sum, item) => sum + item.discount, 0);
    
    // Calculate subtotal after item discounts
    const subtotalAfterItemDiscount = subtotal - itemDiscount;
    
    // Calculate cart discount
    let cartDiscountAmount = 0;
    if (cartDiscount.value > 0) {
        if (cartDiscount.type === 'percent') {
            cartDiscountAmount = (subtotalAfterItemDiscount * cartDiscount.value) / 100;
        } else {
            cartDiscountAmount = cartDiscount.value;
            // Don't allow cart discount to exceed subtotal after item discounts
            if (cartDiscountAmount > subtotalAfterItemDiscount) {
                cartDiscountAmount = subtotalAfterItemDiscount;
            }
        }
    }
    cartDiscount.amount = cartDiscountAmount;
    
    // Calculate total discount (item + cart)
    const totalDiscount = itemDiscount + cartDiscountAmount;
    
    // Calculate tax (currently 0, but structure is ready)
    const tax = 0;
    
    // Calculate final total
    const total = subtotal - totalDiscount + tax;
    
    // Calculate balance due
    const advance = parseFloat(document.querySelector('[name="advance_paid"]').value) || 0;
    const balance = total - advance;
    
    // Update hidden form fields
    document.getElementById('subtotal').value = subtotal.toFixed(2);
    document.getElementById('discount_amount').value = totalDiscount.toFixed(2);
    document.getElementById('tax_amount').value = tax.toFixed(2);
    document.getElementById('items_json').value = JSON.stringify(orderItems);
    
    // Update display fields
    document.getElementById('display_subtotal').textContent = '৳' + subtotal.toFixed(2);
    document.getElementById('display_item_discount').textContent = '৳' + itemDiscount.toFixed(2);
    document.getElementById('display_cart_discount').textContent = '৳' + cartDiscountAmount.toFixed(2);
    document.getElementById('display_total_discount').textContent = '৳' + totalDiscount.toFixed(2);
    document.getElementById('display_tax').textContent = '৳' + tax.toFixed(2);
    document.getElementById('display_total').textContent = '৳' + total.toFixed(2);
    document.getElementById('display_balance').textContent = '৳' + balance.toFixed(2);
    
    updateCreditUsage(total);
}

function updateCustomerInfo() {
    const select = document.getElementById('customer_id');
    const option = select.options[select.selectedIndex];
    
    if (!option.value) {
        document.getElementById('creditInfo').classList.add('hidden');
        return;
    }
    
    const creditLimit = parseFloat(option.dataset.creditLimit) || 0;
    const currentBalance = parseFloat(option.dataset.balance) || 0;
    const available = creditLimit - currentBalance;
    
    document.getElementById('creditLimit').textContent = '৳' + creditLimit.toFixed(0);
    document.getElementById('availableCredit').textContent = '৳' + available.toFixed(0);
    document.getElementById('creditInfo').classList.remove('hidden');
    
    const total = parseFloat(document.getElementById('subtotal').value) || 0;
    updateCreditUsage(total);
}

function updateCreditUsage(orderTotal) {
    const select = document.getElementById('customer_id');
    if (!select.value) return;
    
    const option = select.options[select.selectedIndex];
    const creditLimit = parseFloat(option.dataset.creditLimit) || 0;
    const currentBalance = parseFloat(option.dataset.balance) || 0;
    const available = creditLimit - currentBalance;
    
    if (creditLimit > 0 && available > 0) {
        const usage = (orderTotal / available) * 100;
        document.getElementById('creditUsage').textContent = usage.toFixed(1) + '%';
        
        if (usage > 80) {
            document.getElementById('creditUsage').classList.add('text-red-600');
            document.getElementById('creditUsage').classList.remove('text-blue-600');
        } else {
            document.getElementById('creditUsage').classList.add('text-blue-600');
            document.getElementById('creditUsage').classList.remove('text-red-600');
        }
    } else {
        document.getElementById('creditUsage').textContent = creditLimit > 0 ? '0%' : 'No limit';
    }
}

document.getElementById('creditOrderForm').addEventListener('submit', function(e) {
    if (orderItems.length === 0) {
        e.preventDefault();
        alert('Please add at least one item to the order');
        return false;
    }
});
</script>

<?php require_once '../templates/footer.php'; ?>
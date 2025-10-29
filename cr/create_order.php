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

// Get variants with prices (use average price across all branches for credit sales)
$variants = $db->query(
    "SELECT pv.id, 
            pv.product_id, 
            pv.grade, 
            pv.weight_variant,
            pv.unit_of_measure,
            pv.sku,
            AVG(pp.unit_price) as avg_price,
            MIN(pp.unit_price) as min_price,
            MAX(pp.unit_price) as max_price
     FROM product_variants pv
     JOIN product_prices pp ON pv.id = pp.variant_id
     WHERE pv.status = 'active' AND pp.status = 'active' AND pp.is_active = 1
     GROUP BY pv.id, pv.product_id, pv.grade, pv.weight_variant, pv.unit_of_measure, pv.sku"
)->results();

// Group variants by product
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
        header('Location: credit_dashboard.php');
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
                <select id="add_product" class="w-full px-4 py-2 border rounded-lg">
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
            
            <div class="col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-2">Variant *</label>
                <select id="add_variant" class="w-full px-4 py-2 border rounded-lg" disabled>
                    <option value="">-- Select Variant --</option>
                </select>
            </div>
            
            <div class="col-span-1">
                <label class="block text-sm font-medium text-gray-700 mb-2">Unit</label>
                <input type="text" id="add_unit" class="w-full px-4 py-2 border rounded-lg bg-white" readonly>
            </div>
            
            <div class="col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-2">Quantity *</label>
                <input type="number" id="add_quantity" step="1" min="1" class="w-full px-4 py-2 border rounded-lg" value="1">
            </div>
            
            <div class="col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-2">Unit Price</label>
                <input type="number" id="add_price" step="0.01" class="w-full px-4 py-2 border rounded-lg bg-white" readonly>
            </div>
            
            <div class="col-span-1">
                <label class="block text-sm font-medium text-gray-700 mb-2">Disc %</label>
                <input type="number" id="add_discount" step="0.01" min="0" max="100" class="w-full px-4 py-2 border rounded-lg" value="0">
            </div>
            
            <div class="col-span-1 flex items-end">
                <button type="button" onclick="addItem()" class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium">
                    <i class="fas fa-cart-plus"></i> Add
                </button>
            </div>
        </div>
        
        <!-- Items Table -->
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Variant</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">SKU</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody id="itemsBody" class="bg-white divide-y divide-gray-200">
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-gray-400">
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
                <div class="w-full md:w-1/3 space-y-2">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Subtotal:</span>
                        <span class="font-bold" id="display_subtotal">৳0.00</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Discount:</span>
                        <span class="font-bold text-red-600" id="display_discount">৳0.00</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Tax:</span>
                        <span class="font-bold" id="display_tax">৳0.00</span>
                    </div>
                    <div class="flex justify-between text-lg border-t pt-2">
                        <span class="font-bold">Total:</span>
                        <span class="font-bold text-blue-600" id="display_total">৳0.00</span>
                    </div>
                    <div class="flex justify-between text-lg">
                        <span class="font-bold">Balance Due:</span>
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
</form>

</div>

<script>
const productVariants = <?php echo json_encode($product_variants); ?>;
let orderItems = [];

// Product selection handler
document.getElementById('add_product').addEventListener('change', function() {
    loadVariants();
});

function loadVariants() {
    const productSelect = document.getElementById('add_product');
    const productId = productSelect.value;
    const variantSelect = document.getElementById('add_variant');
    const priceInput = document.getElementById('add_price');
    const unitInput = document.getElementById('add_unit');
    
    variantSelect.innerHTML = '<option value="">-- Select Variant --</option>';
    priceInput.value = '';
    unitInput.value = '';
    
    if (!productId || !productVariants[productId]) {
        variantSelect.disabled = true;
        return;
    }
    
    variantSelect.disabled = false;
    
    productVariants[productId].forEach(v => {
        const opt = document.createElement('option');
        opt.value = v.id;
        
        let variantName = [];
        if (v.grade) variantName.push(v.grade);
        if (v.weight_variant) variantName.push(v.weight_variant);
        
        opt.text = variantName.join(' - ') + ' (৳' + parseFloat(v.avg_price).toFixed(0) + ')';
        opt.dataset.price = v.avg_price;
        opt.dataset.sku = v.sku;
        opt.dataset.unit = v.unit_of_measure;
        opt.dataset.grade = v.grade || '';
        opt.dataset.weight = v.weight_variant || '';
        
        variantSelect.appendChild(opt);
    });
}

// Variant selection handler
document.getElementById('add_variant').addEventListener('change', function() {
    const option = this.options[this.selectedIndex];
    if (option.value) {
        document.getElementById('add_price').value = option.dataset.price || '';
        document.getElementById('add_unit').value = option.dataset.unit || '';
    } else {
        document.getElementById('add_price').value = '';
        document.getElementById('add_unit').value = '';
    }
});

function addItem() {
    const productSelect = document.getElementById('add_product');
    const variantSelect = document.getElementById('add_variant');
    const quantity = parseFloat(document.getElementById('add_quantity').value) || 0;
    const price = parseFloat(document.getElementById('add_price').value) || 0;
    const discount = parseFloat(document.getElementById('add_discount').value) || 0;
    
    if (!productSelect.value) {
        alert('Please select a product');
        return;
    }
    
    if (!variantSelect.value) {
        alert('Please select a variant');
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
    const discountAmount = (price * discount) / 100;
    const finalPrice = price - discountAmount;
    const lineTotal = quantity * finalPrice;
    
    let variantDisplay = [];
    if (variantOption.dataset.grade) variantDisplay.push(variantOption.dataset.grade);
    if (variantOption.dataset.weight) variantDisplay.push(variantOption.dataset.weight);
    
    const item = {
        product_id: productSelect.value,
        product_name: productSelect.options[productSelect.selectedIndex].text.split('(')[0].trim(),
        variant_id: variantSelect.value,
        variant_name: variantDisplay.join(' - '),
        variant_sku: variantOption.dataset.sku,
        unit_of_measure: variantOption.dataset.unit,
        quantity: quantity,
        unit_price: price,
        discount: discountAmount * quantity,
        tax: 0,
        line_total: lineTotal
    };
    
    orderItems.push(item);
    renderItems();
    calculateTotals();
    
    // Reset form
    document.getElementById('add_product').value = '';
    document.getElementById('add_variant').innerHTML = '<option value="">-- Select Variant --</option>';
    document.getElementById('add_variant').disabled = true;
    document.getElementById('add_quantity').value = '1';
    document.getElementById('add_price').value = '';
    document.getElementById('add_discount').value = '0';
    document.getElementById('add_unit').value = '';
}

function renderItems() {
    const tbody = document.getElementById('itemsBody');
    tbody.innerHTML = '';
    
    if (orderItems.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="px-4 py-8 text-center text-gray-400">
                    <i class="fas fa-shopping-cart text-5xl mb-3 opacity-50"></i>
                    <p class="text-lg">Cart is empty - Add products above</p>
                </td>
            </tr>
        `;
        return;
    }
    
    orderItems.forEach((item, index) => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td class="px-4 py-3 text-sm">${item.product_name}</td>
            <td class="px-4 py-3 text-sm">${item.variant_name || '-'}</td>
            <td class="px-4 py-3 text-sm text-gray-600">${item.variant_sku}</td>
            <td class="px-4 py-3 text-sm text-right">
                <input type="number" value="${item.quantity}" min="1" step="1"
                       onchange="updateQuantity(${index}, this.value)"
                       class="w-20 px-2 py-1 border rounded text-right">
                <span class="text-xs text-gray-500 ml-1">${item.unit_of_measure}</span>
            </td>
            <td class="px-4 py-3 text-sm text-right">৳${item.unit_price.toFixed(2)}</td>
            <td class="px-4 py-3 text-sm text-right font-bold text-green-600">৳${item.line_total.toFixed(2)}</td>
            <td class="px-4 py-3 text-center">
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
    
    const discountPerUnit = orderItems[index].discount / (orderItems[index].quantity || 1);
    orderItems[index].discount = discountPerUnit * qty;
    const finalPrice = orderItems[index].unit_price - discountPerUnit;
    orderItems[index].line_total = qty * finalPrice;
    
    renderItems();
    calculateTotals();
}

function removeItem(index) {
    orderItems.splice(index, 1);
    renderItems();
    calculateTotals();
}

function calculateTotals() {
    const subtotal = orderItems.reduce((sum, item) => sum + item.line_total, 0);
    const discount = 0;
    const tax = 0;
    const total = subtotal - discount + tax;
    const advance = parseFloat(document.querySelector('[name="advance_paid"]').value) || 0;
    const balance = total - advance;
    
    document.getElementById('subtotal').value = subtotal.toFixed(2);
    document.getElementById('discount_amount').value = discount.toFixed(2);
    document.getElementById('tax_amount').value = tax.toFixed(2);
    document.getElementById('items_json').value = JSON.stringify(orderItems);
    
    document.getElementById('display_subtotal').textContent = '৳' + subtotal.toFixed(2);
    document.getElementById('display_discount').textContent = '৳' + discount.toFixed(2);
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
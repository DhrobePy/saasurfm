<?php
/**
 * Admin Edit Order Page
 * 
 * Comprehensive order editing with:
 * - Full order details modification
 * - Item addition/removal/update
 * - Automatic recalculation
 * - Accounting integrity
 * - Complete audit trail
 * - Payment tracking
 * 
 * @version 1.0.0
 * @date 2025-11-01
 */

require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Edit Order';
$success = null;
$error = null;

// Get order ID
$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$order_id) {
    header('Location: order_status.php');
    exit;
}

// Fetch order details
$order = $db->query(
    "SELECT 
        co.*,
        c.name as customer_name,
        c.phone_number as customer_phone,
        c.email as customer_email,
        c.credit_limit,
        c.current_balance,
        b.name as branch_name
    FROM credit_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN branches b ON co.assigned_branch_id = b.id
    WHERE co.id = ?",
    [$order_id]
)->first();

if (!$order) {
    $_SESSION['error'] = 'Order not found';
    header('Location: order_status.php');
    exit;
}

// Check if order can be edited
if (in_array($order->status, ['delivered', 'cancelled'])) {
    $_SESSION['error'] = 'Cannot edit delivered or cancelled orders';
    header('Location: order_status.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'update_order') {
        try {
            $db->getPdo()->beginTransaction();
            
            // Store old values for audit
            $old_order = clone $order;
            
            // Validate and sanitize inputs
            $customer_id = (int)$_POST['customer_id'];
            $order_date = $_POST['order_date'];
            $required_date = $_POST['required_date'] ?? null;
            $order_type = $_POST['order_type'];
            $status = $_POST['status'];
            $priority = $_POST['priority'];
            $assigned_branch_id = !empty($_POST['assigned_branch_id']) ? (int)$_POST['assigned_branch_id'] : null;
            $shipping_address = $_POST['shipping_address'] ?? '';
            $special_instructions = $_POST['special_instructions'] ?? '';
            $internal_notes = $_POST['internal_notes'] ?? '';
            
            // Process order items
            $items = json_decode($_POST['items_json'], true);
            if (!is_array($items) || empty($items)) {
                throw new Exception('Order must have at least one item');
            }
            
            // Calculate totals
            $subtotal = 0;
            $total_discount = 0;
            $total_tax = 0;
            
            foreach ($items as $item) {
                $item_subtotal = $item['quantity'] * $item['unit_price'];
                $subtotal += $item_subtotal;
                $total_discount += $item['discount_amount'];
                $total_tax += $item['tax_amount'];
            }
            
            $total_amount = $subtotal - $total_discount + $total_tax;
            $balance_due = $total_amount - $order->amount_paid;
            
            // Update order
            $db->update('credit_orders', [
                'customer_id' => $customer_id,
                'order_date' => $order_date,
                'required_date' => $required_date,
                'order_type' => $order_type,
                'subtotal' => $subtotal,
                'discount_amount' => $total_discount,
                'tax_amount' => $total_tax,
                'total_amount' => $total_amount,
                'balance_due' => $balance_due,
                'status' => $status,
                'priority' => $priority,
                'assigned_branch_id' => $assigned_branch_id,
                'shipping_address' => $shipping_address,
                'special_instructions' => $special_instructions,
                'internal_notes' => $internal_notes
            ], ['id' => $order_id]);
            
            // Delete old items
            $db->query("DELETE FROM credit_order_items WHERE order_id = ?", [$order_id]);
            
            // Insert updated items
            foreach ($items as $item) {
                $line_total = ($item['quantity'] * $item['unit_price']) - $item['discount_amount'] + $item['tax_amount'];
                
                $db->insert('credit_order_items', [
                    'order_id' => $order_id,
                    'product_id' => $item['product_id'],
                    'variant_id' => !empty($item['variant_id']) ? $item['variant_id'] : null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_amount' => $item['discount_amount'],
                    'tax_amount' => $item['tax_amount'],
                    'line_total' => $line_total,
                    'notes' => $item['notes'] ?? null
                ]);
            }
            
            // Create audit trail
            $changes = [];
            
            if ($old_order->customer_id != $customer_id) {
                $changes[] = "Customer changed";
            }
            if ($old_order->order_date != $order_date) {
                $changes[] = "Order date: {$old_order->order_date} → {$order_date}";
            }
            if ($old_order->status != $status) {
                $changes[] = "Status: {$old_order->status} → {$status}";
            }
            if ($old_order->priority != $priority) {
                $changes[] = "Priority: {$old_order->priority} → {$priority}";
            }
            if ($old_order->total_amount != $total_amount) {
                $changes[] = "Total: ৳{$old_order->total_amount} → ৳{$total_amount}";
            }
            
            $changes_json = json_encode([
                'old_subtotal' => $old_order->subtotal,
                'new_subtotal' => $subtotal,
                'old_total' => $old_order->total_amount,
                'new_total' => $total_amount,
                'old_status' => $old_order->status,
                'new_status' => $status,
                'item_count' => count($items)
            ]);
            
            // Log audit trail
            $db->insert('credit_order_audit', [
                'order_id' => $order_id,
                'user_id' => $user_id,
                'action_type' => 'updated',
                'field_name' => 'order_edit',
                'old_value' => json_encode([
                    'subtotal' => $old_order->subtotal,
                    'total' => $old_order->total_amount,
                    'status' => $old_order->status
                ]),
                'new_value' => json_encode([
                    'subtotal' => $subtotal,
                    'total' => $total_amount,
                    'status' => $status
                ]),
                'changes_json' => $changes_json,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'notes' => implode('; ', $changes)
            ]);
            
            $db->getPdo()->commit();
            $success = "Order updated successfully! " . implode(', ', $changes);
            
            // Reload order data
            $order = $db->query(
                "SELECT 
                    co.*,
                    c.name as customer_name,
                    c.phone_number as customer_phone,
                    c.email as customer_email,
                    c.credit_limit,
                    c.current_balance,
                    b.name as branch_name
                FROM credit_orders co
                LEFT JOIN customers c ON co.customer_id = c.id
                LEFT JOIN branches b ON co.assigned_branch_id = b.id
                WHERE co.id = ?",
                [$order_id]
            )->first();
            
        } catch (Exception $e) {
            if ($db->getPdo()->inTransaction()) {
                $db->getPdo()->rollBack();
            }
            $error = "Failed to update order: " . $e->getMessage();
        }
    }
}

// Fetch order items
$order_items = $db->query(
    "SELECT 
        coi.*,
        p.name as product_name,
        p.sku as product_sku,
        p.unit as product_unit,
        pv.variant_name,
        pv.sku as variant_sku
    FROM credit_order_items coi
    LEFT JOIN products p ON coi.product_id = p.id
    LEFT JOIN product_variants pv ON coi.variant_id = pv.id
    WHERE coi.order_id = ?
    ORDER BY coi.id",
    [$order_id]
)->results();

// Fetch all customers for dropdown
$customers = $db->query(
    "SELECT id, name, phone_number, credit_limit, current_balance 
     FROM customers 
     WHERE status = 'active'
     ORDER BY name"
)->results();

// Fetch all branches
$branches = $db->query(
    "SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name"
)->results();

// Fetch all products with variants
$products = $db->query(
    "SELECT 
        p.id,
        p.name,
        p.sku,
        p.unit,
        p.sale_price,
        GROUP_CONCAT(
            CONCAT(pv.id, '|', pv.variant_name, '|', pv.sku, '|', COALESCE(pv.sale_price, p.sale_price))
            SEPARATOR '||'
        ) as variants
    FROM products p
    LEFT JOIN product_variants pv ON p.id = pv.product_id AND pv.is_active = 1
    WHERE p.is_active = 1
    GROUP BY p.id
    ORDER BY p.name"
)->results();

// Fetch audit history
$audit_history = $db->query(
    "SELECT 
        coa.*,
        u.display_name as changed_by_name,
        u.role as user_role
    FROM credit_order_audit coa
    LEFT JOIN users u ON coa.user_id = u.id
    WHERE coa.order_id = ?
    ORDER BY coa.created_at DESC
    LIMIT 20",
    [$order_id]
)->results();

require_once '../templates/header.php';
?>

<style>
.item-row {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    padding: 1rem;
    border-radius: 0.5rem;
    margin-bottom: 0.5rem;
}

.item-row:hover {
    background: #f3f4f6;
    border-color: #d1d5db;
}

.remove-item-btn {
    background: #ef4444;
    color: white;
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    border: none;
    cursor: pointer;
}

.remove-item-btn:hover {
    background: #dc2626;
}

.audit-entry {
    padding: 0.75rem;
    border-left: 3px solid #3b82f6;
    background: #f8fafc;
    margin-bottom: 0.5rem;
    border-radius: 0.25rem;
}

.audit-entry.updated { border-left-color: #3b82f6; }
.audit-entry.status_changed { border-left-color: #8b5cf6; }
.audit-entry.priority_changed { border-left-color: #f59e0b; }
.audit-entry.payment_collected { border-left-color: #10b981; }
</style>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Page Header -->
<div class="mb-6">
    <div class="flex justify-between items-center flex-wrap gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
            <p class="text-sm text-gray-600 mt-1">
                Order #<?php echo htmlspecialchars($order->order_number); ?> • 
                Status: <span class="font-semibold"><?php echo ucwords(str_replace('_', ' ', $order->status)); ?></span>
            </p>
        </div>
        <div class="flex gap-2">
            <a href="order_status.php" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition">
                <i class="fas fa-arrow-left mr-2"></i>Back to Orders
            </a>
            <a href="order_details.php?id=<?php echo $order_id; ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                <i class="fas fa-eye mr-2"></i>View Only
            </a>
        </div>
    </div>
</div>

<?php if ($success): ?>
<div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-r-lg shadow-sm">
    <div class="flex items-center">
        <i class="fas fa-check-circle text-2xl mr-3"></i>
        <div>
            <p class="font-bold">Success!</p>
            <p><?php echo htmlspecialchars($success); ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg shadow-sm">
    <div class="flex items-center">
        <i class="fas fa-exclamation-circle text-2xl mr-3"></i>
        <div>
            <p class="font-bold">Error</p>
            <p><?php echo htmlspecialchars($error); ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Warning Notice -->
<div class="bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 p-4 mb-6 rounded-r-lg">
    <div class="flex items-start">
        <i class="fas fa-exclamation-triangle text-2xl mr-3 mt-1"></i>
        <div>
            <p class="font-bold">⚠️ Warning: Editing Live Order</p>
            <p class="text-sm mt-1">
                Changes will affect accounting, inventory, and production. All changes are logged in the audit trail.
                Current payment collected: <span class="font-bold">৳<?php echo number_format($order->amount_paid, 2); ?></span>
            </p>
        </div>
    </div>
</div>

<form method="POST" id="orderEditForm">
    <input type="hidden" name="action" value="update_order">
    <input type="hidden" name="items_json" id="items_json">
    
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Left Column: Order Details -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- Basic Information -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                    Basic Information
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Customer -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Customer <span class="text-red-500">*</span>
                        </label>
                        <select name="customer_id" 
                                id="customer_id"
                                required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer->id; ?>" 
                                    <?php echo $customer->id == $order->customer_id ? 'selected' : ''; ?>
                                    data-credit-limit="<?php echo $customer->credit_limit; ?>"
                                    data-balance="<?php echo $customer->current_balance; ?>">
                                <?php echo htmlspecialchars($customer->name); ?> (<?php echo $customer->phone_number; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Order Date -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Order Date <span class="text-red-500">*</span>
                        </label>
                        <input type="date" 
                               name="order_date"
                               value="<?php echo $order->order_date; ?>"
                               required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <!-- Required Date -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Required Date
                        </label>
                        <input type="date" 
                               name="required_date"
                               value="<?php echo $order->required_date; ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <!-- Order Type -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Order Type <span class="text-red-500">*</span>
                        </label>
                        <select name="order_type" 
                                required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="credit" <?php echo $order->order_type === 'credit' ? 'selected' : ''; ?>>Credit</option>
                            <option value="advance_payment" <?php echo $order->order_type === 'advance_payment' ? 'selected' : ''; ?>>Advance Payment</option>
                        </select>
                    </div>
                    
                    <!-- Status -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Status <span class="text-red-500">*</span>
                        </label>
                        <select name="status" 
                                required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="draft" <?php echo $order->status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="pending_approval" <?php echo $order->status === 'pending_approval' ? 'selected' : ''; ?>>Pending Approval</option>
                            <option value="approved" <?php echo $order->status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="in_production" <?php echo $order->status === 'in_production' ? 'selected' : ''; ?>>In Production</option>
                            <option value="produced" <?php echo $order->status === 'produced' ? 'selected' : ''; ?>>Produced</option>
                            <option value="ready_to_ship" <?php echo $order->status === 'ready_to_ship' ? 'selected' : ''; ?>>Ready to Ship</option>
                            <option value="shipped" <?php echo $order->status === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                        </select>
                    </div>
                    
                    <!-- Priority -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Priority <span class="text-red-500">*</span>
                        </label>
                        <select name="priority" 
                                required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="low" <?php echo $order->priority === 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="normal" <?php echo $order->priority === 'normal' ? 'selected' : ''; ?>>Normal</option>
                            <option value="high" <?php echo $order->priority === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="urgent" <?php echo $order->priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        </select>
                    </div>
                    
                    <!-- Branch -->
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Assigned Branch
                        </label>
                        <select name="assigned_branch_id" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">-- Select Branch --</option>
                            <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo $branch->id; ?>" 
                                    <?php echo $branch->id == $order->assigned_branch_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($branch->name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Order Items -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-boxes text-purple-600 mr-2"></i>
                        Order Items
                    </h2>
                    <button type="button" 
                            onclick="addItem()" 
                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                        <i class="fas fa-plus mr-2"></i>Add Item
                    </button>
                </div>
                
                <div id="itemsContainer">
                    <?php foreach ($order_items as $index => $item): ?>
                    <div class="item-row" data-item-index="<?php echo $index; ?>">
                        <div class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
                            <!-- Product -->
                            <div class="md:col-span-2">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Product</label>
                                <select class="item-product w-full px-2 py-2 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-blue-500" 
                                        data-current-product="<?php echo $item->product_id; ?>"
                                        data-current-variant="<?php echo $item->variant_id; ?>"
                                        onchange="updateItemPrice(this)">
                                    <option value="">Select...</option>
                                    <?php foreach ($products as $product): ?>
                                    <optgroup label="<?php echo htmlspecialchars($product->name); ?>">
                                        <option value="<?php echo $product->id; ?>|0" 
                                                data-price="<?php echo $product->sale_price; ?>"
                                                <?php echo ($item->product_id == $product->id && empty($item->variant_id)) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($product->name); ?> (<?php echo $product->sku; ?>)
                                        </option>
                                        <?php if ($product->variants): 
                                            $variants = explode('||', $product->variants);
                                            foreach ($variants as $variant_str):
                                                if (empty($variant_str)) continue;
                                                list($var_id, $var_name, $var_sku, $var_price) = explode('|', $variant_str);
                                        ?>
                                        <option value="<?php echo $product->id; ?>|<?php echo $var_id; ?>" 
                                                data-price="<?php echo $var_price; ?>"
                                                <?php echo ($item->product_id == $product->id && $item->variant_id == $var_id) ? 'selected' : ''; ?>>
                                            &nbsp;&nbsp;├─ <?php echo htmlspecialchars($var_name); ?> (<?php echo $var_sku; ?>)
                                        </option>
                                        <?php 
                                            endforeach;
                                        endif; ?>
                                    </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Quantity -->
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Qty</label>
                                <input type="number" 
                                       class="item-quantity w-full px-2 py-2 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-blue-500" 
                                       value="<?php echo $item->quantity; ?>"
                                       step="0.01"
                                       min="0.01"
                                       onchange="calculateLineTotal(this)">
                            </div>
                            
                            <!-- Unit Price -->
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Price</label>
                                <input type="number" 
                                       class="item-price w-full px-2 py-2 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-blue-500" 
                                       value="<?php echo $item->unit_price; ?>"
                                       step="0.01"
                                       min="0"
                                       onchange="calculateLineTotal(this)">
                            </div>
                            
                            <!-- Discount -->
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Discount</label>
                                <input type="number" 
                                       class="item-discount w-full px-2 py-2 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-blue-500" 
                                       value="<?php echo $item->discount_amount; ?>"
                                       step="0.01"
                                       min="0"
                                       onchange="calculateLineTotal(this)">
                            </div>
                            
                            <!-- Line Total -->
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Total</label>
                                <div class="flex gap-2">
                                    <input type="text" 
                                           class="item-total flex-1 px-2 py-2 text-sm border border-gray-300 rounded bg-gray-50" 
                                           value="<?php echo number_format($item->line_total, 2); ?>"
                                           readonly>
                                    <button type="button" 
                                            onclick="removeItem(this)" 
                                            class="remove-item-btn px-2 py-1 text-xs">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Totals Summary -->
                <div class="mt-6 bg-blue-50 rounded-lg p-4 border border-blue-200">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                        <div>
                            <p class="text-gray-600">Subtotal:</p>
                            <p class="text-lg font-bold text-gray-900" id="display_subtotal">৳0.00</p>
                        </div>
                        <div>
                            <p class="text-gray-600">Discount:</p>
                            <p class="text-lg font-bold text-red-600" id="display_discount">৳0.00</p>
                        </div>
                        <div>
                            <p class="text-gray-600">Tax:</p>
                            <p class="text-lg font-bold text-gray-900" id="display_tax">৳0.00</p>
                        </div>
                        <div>
                            <p class="text-gray-600">Total:</p>
                            <p class="text-2xl font-bold text-blue-600" id="display_total">৳0.00</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Additional Details -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-clipboard text-green-600 mr-2"></i>
                    Additional Details
                </h2>
                
                <div class="space-y-4">
                    <!-- Shipping Address -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Shipping Address
                        </label>
                        <textarea name="shipping_address" 
                                  rows="2"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($order->shipping_address); ?></textarea>
                    </div>
                    
                    <!-- Special Instructions -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Special Instructions
                        </label>
                        <textarea name="special_instructions" 
                                  rows="2"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($order->special_instructions); ?></textarea>
                    </div>
                    
                    <!-- Internal Notes -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Internal Notes (Not visible to customer)
                        </label>
                        <textarea name="internal_notes" 
                                  rows="2"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($order->internal_notes); ?></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Form Actions -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex flex-wrap gap-3 justify-between items-center">
                    <div class="text-sm text-gray-600">
                        <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                        All changes will be logged in the audit trail
                    </div>
                    <div class="flex gap-3">
                        <a href="order_status.php" 
                           class="px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <button type="submit" 
                                class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-bold transition">
                            <i class="fas fa-save mr-2"></i>Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Summary & Audit Trail -->
        <div class="space-y-6">
            
            <!-- Order Summary -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-chart-pie text-blue-600 mr-2"></i>
                    Order Summary
                </h2>
                
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Order Number:</dt>
                        <dd class="font-semibold"><?php echo htmlspecialchars($order->order_number); ?></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Customer:</dt>
                        <dd class="font-semibold"><?php echo htmlspecialchars($order->customer_name); ?></dd>
                    </div>
                    <div class="flex justify-between border-t pt-3">
                        <dt class="text-gray-600">Current Total:</dt>
                        <dd class="font-bold text-lg">৳<?php echo number_format($order->total_amount, 2); ?></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Amount Paid:</dt>
                        <dd class="font-semibold text-green-700">৳<?php echo number_format($order->amount_paid, 2); ?></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Balance Due:</dt>
                        <dd class="font-semibold text-red-700">৳<?php echo number_format($order->total_amount - $order->amount_paid, 2); ?></dd>
                    </div>
                    <div class="flex justify-between border-t pt-3">
                        <dt class="text-gray-600">Created:</dt>
                        <dd class="text-gray-600"><?php echo date('M j, Y g:i A', strtotime($order->created_at)); ?></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Last Updated:</dt>
                        <dd class="text-gray-600"><?php echo date('M j, Y g:i A', strtotime($order->updated_at)); ?></dd>
                    </div>
                </dl>
            </div>
            
            <!-- Customer Info -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-user text-green-600 mr-2"></i>
                    Customer Information
                </h2>
                
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Name:</dt>
                        <dd class="font-semibold"><?php echo htmlspecialchars($order->customer_name); ?></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Phone:</dt>
                        <dd class="font-semibold"><?php echo htmlspecialchars($order->customer_phone); ?></dd>
                    </div>
                    <?php if ($order->customer_email): ?>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Email:</dt>
                        <dd class="font-semibold"><?php echo htmlspecialchars($order->customer_email); ?></dd>
                    </div>
                    <?php endif; ?>
                    <div class="flex justify-between border-t pt-3">
                        <dt class="text-gray-600">Credit Limit:</dt>
                        <dd class="font-semibold">৳<?php echo number_format($order->credit_limit, 2); ?></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Current Balance:</dt>
                        <dd class="font-semibold <?php echo $order->current_balance > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                            ৳<?php echo number_format($order->current_balance, 2); ?>
                        </dd>
                    </div>
                </dl>
            </div>
            
            <!-- Audit Trail -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-history text-purple-600 mr-2"></i>
                    Audit Trail (Last 20)
                </h2>
                
                <div class="space-y-2 max-h-96 overflow-y-auto">
                    <?php if (empty($audit_history)): ?>
                    <p class="text-sm text-gray-500 text-center py-4">No audit history yet</p>
                    <?php else: ?>
                    <?php foreach ($audit_history as $audit): ?>
                    <div class="audit-entry <?php echo $audit->action_type; ?>">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <p class="text-xs font-semibold text-gray-900">
                                    <?php echo ucwords(str_replace('_', ' ', $audit->action_type)); ?>
                                </p>
                                <?php if ($audit->notes): ?>
                                <p class="text-xs text-gray-600 mt-1"><?php echo htmlspecialchars($audit->notes); ?></p>
                                <?php endif; ?>
                                <p class="text-xs text-gray-500 mt-1">
                                    by <?php echo htmlspecialchars($audit->changed_by_name); ?> (<?php echo $audit->user_role; ?>)
                                </p>
                            </div>
                            <span class="text-xs text-gray-500 ml-2">
                                <?php echo date('M j, g:i A', strtotime($audit->created_at)); ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>

</div>

<script>
let itemCounter = <?php echo count($order_items); ?>;

// Initialize totals calculation on page load
document.addEventListener('DOMContentLoaded', function() {
    recalculateAllTotals();
});

// Add new item row
function addItem() {
    const container = document.getElementById('itemsContainer');
    const newRow = document.createElement('div');
    newRow.className = 'item-row';
    newRow.dataset.itemIndex = itemCounter;
    
    newRow.innerHTML = `
        <div class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">Product</label>
                <select class="item-product w-full px-2 py-2 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-blue-500" 
                        onchange="updateItemPrice(this)">
                    <option value="">Select...</option>
                    <?php foreach ($products as $product): ?>
                    <optgroup label="<?php echo htmlspecialchars($product->name); ?>">
                        <option value="<?php echo $product->id; ?>|0" data-price="<?php echo $product->sale_price; ?>">
                            <?php echo htmlspecialchars($product->name); ?> (<?php echo $product->sku; ?>)
                        </option>
                        <?php if ($product->variants): 
                            $variants = explode('||', $product->variants);
                            foreach ($variants as $variant_str):
                                if (empty($variant_str)) continue;
                                list($var_id, $var_name, $var_sku, $var_price) = explode('|', $variant_str);
                        ?>
                        <option value="<?php echo $product->id; ?>|<?php echo $var_id; ?>" data-price="<?php echo $var_price; ?>">
                            &nbsp;&nbsp;├─ <?php echo htmlspecialchars($var_name); ?> (<?php echo $var_sku; ?>)
                        </option>
                        <?php 
                            endforeach;
                        endif; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Qty</label>
                <input type="number" class="item-quantity w-full px-2 py-2 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-blue-500" 
                       value="1" step="0.01" min="0.01" onchange="calculateLineTotal(this)">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Price</label>
                <input type="number" class="item-price w-full px-2 py-2 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-blue-500" 
                       value="0" step="0.01" min="0" onchange="calculateLineTotal(this)">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Discount</label>
                <input type="number" class="item-discount w-full px-2 py-2 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-blue-500" 
                       value="0" step="0.01" min="0" onchange="calculateLineTotal(this)">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Total</label>
                <div class="flex gap-2">
                    <input type="text" class="item-total flex-1 px-2 py-2 text-sm border border-gray-300 rounded bg-gray-50" 
                           value="0.00" readonly>
                    <button type="button" onclick="removeItem(this)" class="remove-item-btn px-2 py-1 text-xs">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    `;
    
    container.appendChild(newRow);
    itemCounter++;
}

// Remove item row
function removeItem(button) {
    if (confirm('Remove this item?')) {
        button.closest('.item-row').remove();
        recalculateAllTotals();
    }
}

// Update item price when product is selected
function updateItemPrice(select) {
    const selectedOption = select.options[select.selectedIndex];
    const price = selectedOption.dataset.price || 0;
    const row = select.closest('.item-row');
    const priceInput = row.querySelector('.item-price');
    
    priceInput.value = price;
    calculateLineTotal(priceInput);
}

// Calculate line total for a specific item
function calculateLineTotal(input) {
    const row = input.closest('.item-row');
    const quantity = parseFloat(row.querySelector('.item-quantity').value) || 0;
    const price = parseFloat(row.querySelector('.item-price').value) || 0;
    const discount = parseFloat(row.querySelector('.item-discount').value) || 0;
    
    const lineTotal = (quantity * price) - discount;
    row.querySelector('.item-total').value = lineTotal.toFixed(2);
    
    recalculateAllTotals();
}

// Recalculate all totals
function recalculateAllTotals() {
    let subtotal = 0;
    let totalDiscount = 0;
    let totalTax = 0;
    
    document.querySelectorAll('.item-row').forEach(row => {
        const quantity = parseFloat(row.querySelector('.item-quantity').value) || 0;
        const price = parseFloat(row.querySelector('.item-price').value) || 0;
        const discount = parseFloat(row.querySelector('.item-discount').value) || 0;
        
        subtotal += (quantity * price);
        totalDiscount += discount;
    });
    
    const grandTotal = subtotal - totalDiscount + totalTax;
    
    document.getElementById('display_subtotal').textContent = '৳' + subtotal.toFixed(2);
    document.getElementById('display_discount').textContent = '৳' + totalDiscount.toFixed(2);
    document.getElementById('display_tax').textContent = '৳' + totalTax.toFixed(2);
    document.getElementById('display_total').textContent = '৳' + grandTotal.toFixed(2);
}

// Form submission - collect all items data
document.getElementById('orderEditForm').addEventListener('submit', function(e) {
    const items = [];
    
    document.querySelectorAll('.item-row').forEach(row => {
        const productSelect = row.querySelector('.item-product');
        const selectedValue = productSelect.value;
        
        if (!selectedValue) return;
        
        const [product_id, variant_id] = selectedValue.split('|');
        
        items.push({
            product_id: parseInt(product_id),
            variant_id: parseInt(variant_id) || null,
            quantity: parseFloat(row.querySelector('.item-quantity').value) || 0,
            unit_price: parseFloat(row.querySelector('.item-price').value) || 0,
            discount_amount: parseFloat(row.querySelector('.item-discount').value) || 0,
            tax_amount: 0,
            notes: null
        });
    });
    
    if (items.length === 0) {
        e.preventDefault();
        alert('Please add at least one item to the order');
        return false;
    }
    
    document.getElementById('items_json').value = JSON.stringify(items);
    
    // Show loading
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+S to save
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        document.getElementById('orderEditForm').dispatchEvent(new Event('submit'));
    }
});
</script>

<?php require_once '../templates/footer.php'; ?>
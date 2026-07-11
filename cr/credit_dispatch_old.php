<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'Accounts', 'admin', 'dispatch-srg', 'dispatch-demra', 'dispatchpos-demra', 'dispatchpos-srg'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Dispatch Management';
$error = null;
$success = null;

$is_admin = in_array($user_role, ['Superadmin', 'admin']);

// Get user's branch
$user_branch = null;
if (!$is_admin && $user_id) {
    $emp = $db->query("SELECT branch_id FROM employees WHERE user_id = ?", [$user_id])->first();
    if ($emp && $emp->branch_id) {
        $user_branch = $emp->branch_id;
    } 
}

// Build branch filter
$branch_filter = "";
$branch_params = [];
if (!$is_admin && $user_branch) {
    $branch_filter = "AND co.assigned_branch_id = ?";
    $branch_params[] = $user_branch;
}

// --- START: RECTIFIED CODE - Fetch necessary accounts ---
// We need these for the double-entry journal
$ar_account_q = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Accounts Receivable' LIMIT 1");
$ar_account = $ar_account_q->first();
if (!$ar_account) {
    $error = "FATAL ERROR: 'Accounts Receivable' account not found in Chart of Accounts. Cannot proceed.";
}
$ar_account_id = $ar_account->id;

// We need a fallback sales account
$default_sales_account_q = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Revenue' AND branch_id IS NULL LIMIT 1");
$default_sales_account = $default_sales_account_q->first();
if (!$default_sales_account) {
     $error = "FATAL ERROR: No default 'Revenue' account found in Chart of Accounts. Cannot proceed.";
}
$default_sales_account_id = $default_sales_account->id;
// --- END: RECTIFIED CODE ---


// Get orders ready to ship and shipped
$orders = $db->query(
    "SELECT co.*, 
            c.name as customer_name,
            c.phone_number as customer_phone,
            b.name as branch_name,
            cos.truck_number,
            cos.driver_name,
            cos.driver_contact,
            cos.shipped_date,
            cos.delivered_date,
            cos.delivery_notes
     FROM credit_orders co
     JOIN customers c ON co.customer_id = c.id
     LEFT JOIN branches b ON co.assigned_branch_id = b.id
     LEFT JOIN credit_order_shipping cos ON co.id = cos.order_id
     WHERE co.status IN ('ready_to_ship', 'shipped', 'delivered') 
     AND co.assigned_branch_id IS NOT NULL
     $branch_filter
     ORDER BY 
         CASE co.status 
             WHEN 'ready_to_ship' THEN 1
             WHEN 'shipped' THEN 2
             WHEN 'delivered' THEN 3
         END,
         co.required_date ASC",
    $branch_params
)->results();

// Handle shipping action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'ship' && !$error) {
    $order_id = (int)$_POST['order_id'];
    $truck_number = trim($_POST['truck_number']);
    $driver_name = trim($_POST['driver_name']);
    $driver_contact = trim($_POST['driver_contact']);
    
    if (empty($truck_number) || empty($driver_name) || empty($driver_contact)) {
        $error = "Please fill all shipping details";
    } else {
        try {
            $pdo = $db->getPdo();
            $pdo->beginTransaction();
            
            $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$order_id])->first();
            if (!$order) throw new Exception("Order not found");
            
            // --- 1. Insert/Update Shipping Record ---
            $shipping_exists = $db->query("SELECT id FROM credit_order_shipping WHERE order_id = ?", [$order_id])->first();
            
            if ($shipping_exists) {
                $db->query(
                    "UPDATE credit_order_shipping 
                     SET truck_number = ?, driver_name = ?, driver_contact = ?, 
                         shipped_date = NOW(), shipped_by_user_id = ?
                     WHERE order_id = ?",
                    [$truck_number, $driver_name, $driver_contact, $user_id, $order_id]
                );
            } else {
                $db->insert('credit_order_shipping', [
                    'order_id' => $order_id,
                    'truck_number' => $truck_number,
                    'driver_name' => $driver_name,
                    'driver_contact' => $driver_contact,
                    'shipped_date' => date('Y-m-d H:i:s'),
                    'shipped_by_user_id' => $user_id
                ]);
            }
            
            // --- 2. Update order status to shipped ---
            $db->query("UPDATE credit_orders SET status = 'shipped' WHERE id = ?", [$order_id]);
            
            // =================================================================
            // --- 3. START: RECTIFIED CUSTOMER LEDGER & BALANCE LOGIC ---
            // =================================================================
            
            // --- 3a. Get the REAL previous balance ---
            $prev_balance_result = $db->query(
                "SELECT balance_after FROM customer_ledger 
                 WHERE customer_id = ? ORDER BY transaction_date DESC, id DESC LIMIT 1",
                [$order->customer_id]
            )->first();

            $customer_data = $db->query("SELECT initial_due, name FROM customers WHERE id = ?", [$order->customer_id])->first();
            $customer_name = $customer_data ? $customer_data->name : 'Unknown Customer';

            if ($prev_balance_result) {
                // A ledger history exists, use its last balance
                $prev_balance = (float)$prev_balance_result->balance_after;
            } else {
                // No ledger history, use the customer's initial_due
                $prev_balance = $customer_data ? (float)$customer_data->initial_due : 0;
            }
            
            // --- 3b. Calculate new balance ---
            // Use the order's balance_due field. We assume this is the correct amount to invoice.
            $invoice_amount = (float)$order->balance_due; 

            // **Safety Check**: If balance_due is 0 (e.g., from an old bug), use total_amount
            // and update the order's balance_due to match.
            if ($invoice_amount <= 0 && (float)$order->total_amount > 0) {
                $invoice_amount = (float)$order->total_amount;
                $db->update('credit_orders', $order_id, ['balance_due' => $invoice_amount]);
            }

            $new_balance = $prev_balance + $invoice_amount;
            
            // --- 3c. Insert ledger entry (using the invoice_amount) ---
            $ledger_id = $db->insert('customer_ledger', [
                'customer_id' => $order->customer_id,
                'transaction_date' => date('Y-m-d'),
                'transaction_type' => 'invoice',
                'reference_type' => 'credit_orders',
                'reference_id' => $order_id,
                'invoice_number' => $order->order_number,
                'description' => "Credit sale - Invoice #" . $order->order_number,
                'debit_amount' => $invoice_amount,
                'credit_amount' => 0,
                'balance_after' => $new_balance, // Use the new correct balance
                'created_by_user_id' => $user_id
            ]);
            if (!$ledger_id) {
                 throw new Exception("Failed to create customer ledger entry.");
            }

            // --- 3d. Update customer balance (Absolute Update) ---
            $db->update('customers', $order->customer_id, ['current_balance' => $new_balance]);

            // =================================================================
            // --- END: RECTIFIED LEDGER LOGIC ---
            // =================================================================


            // =================================================================
            // --- 4. START: NEW DOUBLE-ENTRY JOURNAL LOGIC ---
            // =================================================================
            
            // --- 4a. Find the correct Sales Revenue account ---
            $sales_account_q = $db->query(
                "SELECT id FROM chart_of_accounts 
                 WHERE account_type = 'Revenue' AND branch_id = ? 
                 LIMIT 1",
                [$order->assigned_branch_id]
            );
            $sales_account = $sales_account_q->first();
            
            $sales_account_id = $sales_account ? $sales_account->id : $default_sales_account_id;

            // --- 4b. Create Journal Entry Header ---
            $journal_desc = "Credit Sale Invoice #" . $order->order_number . " to " . $customer_name;
            $journal_id = $db->insert('journal_entries', [
                'transaction_date' => date('Y-m-d'),
                'description' => $journal_desc,
                'related_document_type' => 'credit_orders',
                'related_document_id' => $order_id,
                'created_by_user_id' => $user_id
            ]);
            if (!$journal_id) {
                throw new Exception("Failed to create journal entry header.");
            }

            // --- 4c. Create Transaction Lines (Debit & Credit) ---
            
            // Line 1: DEBIT "Accounts Receivable"
            $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_id,
                'account_id' => $ar_account_id,
                'debit_amount' => $invoice_amount,
                'credit_amount' => 0.00,
                'description' => $journal_desc
            ]);

            // Line 2: CREDIT "Sales Revenue"
            $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_id,
                'account_id' => $sales_account_id,
                'debit_amount' => 0.00,
                'credit_amount' => $invoice_amount,
                'description' => $journal_desc
            ]);

            // --- 4d. Link Journal ID back to Ledger ---
            $db->update('customer_ledger', $ledger_id, ['journal_entry_id' => $journal_id]);

            // =================================================================
            // --- END: NEW DOUBLE-ENTRY JOURNAL LOGIC ---
            // =================================================================


            // --- 5. Log workflow (Your existing logic is fine) ---
            $db->insert('credit_order_workflow', [
                'order_id' => $order_id,
                'from_status' => 'ready_to_ship',
                'to_status' => 'shipped',
                'action' => 'ship',
                'performed_by_user_id' => $user_id,
                'comments' => "Shipped with truck $truck_number, driver: $driver_name"
            ]);
            
            $pdo->commit();
            $_SESSION['success_flash'] = "Order shipped successfully. Customer ledger and journal updated.";
            header('Location: credit_dispatch.php');
            exit();
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Failed to ship order: " . $e->getMessage();
        }
    }
}

// Handle delivery confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'delivered') {
    // ... (Your existing, correct logic for delivery confirmation) ...
    // ... (No changes needed in this block) ...
    $order_id = (int)$_POST['order_id'];
    $delivery_notes = trim($_POST['delivery_notes'] ?? '');
    
    try {
        $db->getPdo()->beginTransaction();
        
        // Update shipping record
        $db->query(
            "UPDATE credit_order_shipping 
             SET delivered_date = NOW(), 
                 delivered_by_user_id = ?,
                 delivery_notes = ?
             WHERE order_id = ?",
            [$user_id, $delivery_notes, $order_id]
        );
        
        // Update order status
        $db->query("UPDATE credit_orders SET status = 'delivered' WHERE id = ?", [$order_id]);
        
        // Log workflow
        $db->insert('credit_order_workflow', [
            'order_id' => $order_id,
            'from_status' => 'shipped',
            'to_status' => 'delivered',
            'action' => 'deliver',
            'performed_by_user_id' => $user_id,
            'comments' => 'Order delivered to customer' . ($delivery_notes ? ': ' . $delivery_notes : '')
        ]);
        
        $db->getPdo()->commit();
        $_SESSION['success_flash'] = "Order marked as delivered";
        header('Location: credit_dispatch.php');
        exit();
        
    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        $error = "Failed to confirm delivery: " . $e->getMessage();
    }
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">Ship orders and track deliveries</p>
</div>

<?php if ($error): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Error</p>
    <p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['success_flash'])): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-r-lg shadow-md">
        <p class="font-bold">Success</p>
        <p><?php echo htmlspecialchars($_SESSION['success_flash']); ?></p>
    </div>
    <?php unset($_SESSION['success_flash']); ?>
<?php endif; ?>


<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <?php
    // Get statistics safely with proper error handling
    try {
        $ready_result = $db->query("SELECT COUNT(*) as c FROM credit_orders WHERE status = 'ready_to_ship' $branch_filter", $branch_params)->first();
        $shipped_result = $db->query("SELECT COUNT(*) as c FROM credit_orders WHERE status = 'shipped' $branch_filter", $branch_params)->first();
        $delivered_result = $db->query("SELECT COUNT(*) as c FROM credit_orders WHERE status = 'delivered' $branch_filter", $branch_params)->first();

        $stats = [
            'ready_to_ship' => ($ready_result && isset($ready_result->c)) ? $ready_result->c : 0,
            'shipped' => ($shipped_result && isset($shipped_result->c)) ? $shipped_result->c : 0,
            'delivered' => ($delivered_result && isset($delivered_result->c)) ? $delivered_result->c : 0
        ];
    } catch (Exception $e) {
        $stats = ['ready_to_ship' => 0, 'shipped' => 0, 'delivered' => 0];
    }
    ?>
    <div class="bg-orange-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Ready to Ship</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['ready_to_ship']; ?></p>
    </div>
    <div class="bg-blue-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Shipped</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['shipped']; ?></p>
    </div>
    <div class="bg-green-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Delivered</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['delivered']; ?></p>
    </div>
</div>

<?php if (!empty($orders)): ?>
<?php foreach ($orders as $order): 
    // Get items for this order
    $items = $db->query(
        "SELECT coi.*, 
                p.base_name as product_name,
                pv.grade,
                pv.weight_variant,
                pv.unit_of_measure,
                pv.sku as variant_sku
         FROM credit_order_items coi
         JOIN products p ON coi.product_id = p.id
         LEFT JOIN product_variants pv ON coi.variant_id = pv.id
         WHERE coi.order_id = ?",
        [$order->id]
    )->results();
    
    $items_count = count($items);
    
    $status_colors = [
        'ready_to_ship' => 'orange',
        'shipped' => 'blue',
        'delivered' => 'green'
    ];
    $color = $status_colors[$order->status] ?? 'gray';
?>
<div class="bg-white rounded-lg shadow-md mb-6 overflow-hidden">
    <div class="p-6 border-b border-gray-200 bg-gray-50">
        <div class="flex justify-between items-start">
            <div>
                <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($order->order_number); ?></h3>
                <span class="inline-block mt-2 px-3 py-1 text-xs font-semibold rounded-full bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-800">
                    <?php echo ucwords(str_replace('_', ' ', $order->status)); ?>
                </span>
            </div>
            <div class="text-right">
                <p class="text-2xl font-bold text-blue-600">৳<?php echo number_format($order->total_amount, 2); ?></p>
                <p class="text-sm text-gray-600"><?php echo $items_count; ?> items</p>
            </div>
        </div>
    </div>
    
    <div class="p-6 border-b border-gray-200">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h4 class="font-semibold text-gray-800 mb-2">Customer</h4>
                <p class="text-sm"><strong><?php echo htmlspecialchars($order->customer_name); ?></strong></p>
                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($order->customer_phone); ?></p>
            </div>
            <div>
                <h4 class="font-semibold text-gray-800 mb-2">Delivery</h4>
                <p class="text-sm"><strong>Address:</strong></p>
                <p class="text-sm text-gray-700"><?php echo htmlspecialchars($order->shipping_address); ?></p>
                <p class="text-sm mt-2"><strong>Branch:</strong> <?php echo htmlspecialchars($order->branch_name); ?></p>
                <?php if ($order->special_instructions): ?>
                <p class="text-sm mt-2 p-2 bg-blue-50 border border-blue-200 rounded">
                    <i class="fas fa-info-circle text-blue-600 mr-1"></i>
                    <?php echo htmlspecialchars($order->special_instructions); ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="p-6 border-b border-gray-200">
        <h4 class="font-semibold text-gray-800 mb-3">Order Items</h4>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Variant</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($items as $item): 
                        $variant_display = [];
                        if ($item->grade) $variant_display[] = $item->grade;
                        if ($item->weight_variant) $variant_display[] = $item->weight_variant;
                    ?>
                    <tr>
                        <td class="px-4 py-2"><?php echo htmlspecialchars($item->product_name); ?></td>
                        <td class="px-4 py-2">
                            <?php echo htmlspecialchars(implode(' - ', $variant_display)); ?>
                            <?php if ($item->variant_sku): ?>
                                <span class="text-xs text-gray-500">(<?php echo htmlspecialchars($item->variant_sku); ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <?php echo $item->quantity; ?> <?php echo $item->unit_of_measure; ?>
                        </td>
                        <td class="px-4 py-2 text-right">৳<?php echo number_format($item->unit_price, 2); ?></td>
                        <td class="px-4 py-2 text-right font-medium">৳<?php echo number_format($item->line_total, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-50">
                    <tr>
                        <td colspan="4" class="px-4 py-2 text-right font-semibold">Total:</td>
                        <td class="px-4 py-2 text-right font-bold text-blue-600">৳<?php echo number_format($order->total_amount, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    
    <div class="p-6 bg-gray-50">
        <?php if ($order->status === 'ready_to_ship'): ?>
        <h4 class="font-semibold text-gray-800 mb-4">Enter Shipping Details</h4>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="ship">
            <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Truck Number *</label>
                    <input type="text" name="truck_number" required 
                           class="w-full px-4 py-2 border rounded-lg" 
                           placeholder="e.g., DHA-1234">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Driver Name *</label>
                    <input type="text" name="driver_name" required 
                           class="w-full px-4 py-2 border rounded-lg" 
                           placeholder="Full name">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Driver Contact *</label>
                    <input type="text" name="driver_contact" required 
                           class="w-full px-4 py-2 border rounded-lg" 
                           placeholder="01XXXXXXXXX">
                </div>
            </div>
            
            <div class="flex gap-3">
                <button type="submit" 
                        onclick="return confirm('Ship this order and create invoice in customer ledger?');"
                        class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-truck mr-2"></i>Ship Order & Update Ledger
                </button>
                <a href="./credit_order_view.php?id=<?php echo $order->id; ?>" 
                   class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100">
                    <i class="fas fa-eye mr-2"></i>View Details
                </a>
            </div>
        </form>
        
        <?php elseif ($order->status === 'shipped'): ?>
        <div class="space-y-4">
            <h4 class="font-semibold text-gray-800">Shipping Details</h4>
            <div class="grid grid-cols-3 gap-4 text-sm">
                <div>
                    <p class="text-gray-600">Truck Number:</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->truck_number); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Driver Name:</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->driver_name); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Driver Contact:</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->driver_contact); ?></p>
                </div>
            </div>
            <p class="text-sm text-gray-600">
                <i class="fas fa-calendar mr-1"></i>Shipped: <?php echo date('M j, Y g:i A', strtotime($order->shipped_date)); ?>
            </p>
            
            <div class="pt-4 border-t border-gray-200 flex gap-3">
                <a href="credit_invoice_print.php?id=<?php echo $order->id; ?>" target="_blank"
                   class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                    <i class="fas fa-print mr-2"></i>Print Invoice
                </a>
                <a href="./credit_order_view.php?id=<?php echo $order->id; ?>" 
                   class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100">
                    <i class="fas fa-eye mr-2"></i>View Details
                </a>
            </div>
            
            <form method="POST" class="pt-4 border-t border-gray-200">
                <input type="hidden" name="action" value="delivered">
                <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
                
                <h4 class="font-semibold text-gray-800 mb-2">Mark as Delivered</h4>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Delivery Notes (Optional)</label>
                    <textarea name="delivery_notes" rows="2" 
                              class="w-full px-4 py-2 border rounded-lg" 
                              placeholder="Any notes about the delivery..."></textarea>
                </div>
                <button type="submit" 
                        onclick="return confirm('Confirm that this order has been delivered to customer?');"
                        class="mt-3 px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-check-circle mr-2"></i>Confirm Delivery
                </button>
            </form>
        </div>
        
        <?php elseif ($order->status === 'delivered'): ?>
        <div class="space-y-4">
            <div class="flex items-center gap-2 text-green-600">
                <i class="fas fa-check-circle text-2xl"></i>
                <h4 class="font-semibold text-lg">Order Delivered</h4>
            </div>
            
            <div class="grid grid-cols-3 gap-4 text-sm">
                <div>
                    <p class="text-gray-600">Truck Number:</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->truck_number); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Driver Name:</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->driver_name); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Driver Contact:</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->driver_contact); ?></p>
                </div>
            </div>
            
            <div class="text-sm">
                <p><i class="fas fa-calendar mr-1 text-gray-500"></i>Shipped: <?php echo date('M j, Y g:i A', strtotime($order->shipped_date)); ?></p>
                <p><i class="fas fa-check mr-1 text-green-500"></i>Delivered: <?php echo date('M j, Y g:i A', strtotime($order->delivered_date)); ?></p>
            </div>
            
            <?php if ($order->delivery_notes): ?>
            <div class="p-3 bg-gray-100 rounded">
                <p class="text-sm text-gray-600"><strong>Delivery Notes:</strong></p>
                <p class="text-sm"><?php echo htmlspecialchars($order->delivery_notes); ?></p>
            </div>
            <?php endif; ?>
            
            <div class="pt-4 border-t border-gray-200 flex gap-3">
                <a href="credit_invoice_print.php?id=<?php echo $order->id; ?>" target="_blank"
                   class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                    <i class="fas fa-print mr-2"></i>Print Invoice
                </a>
                <a href="./credit_order_view.php?id=<?php echo $order->id; ?>" 
                   class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100">
                    <i class="fas fa-eye mr-2"></i>View Details
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php else: ?>
<div class="bg-white rounded-lg shadow-md p-12 text-center">
    <i class="fas fa-truck text-6xl text-gray-400 mb-4"></i>
    <h3 class="text-xl font-semibold text-gray-700 mb-2">No Orders for Dispatch</h3>
    <p class="text-gray-600">No orders are ready to ship at this time.</p>
</div>
<?php endif; ?>

</div>

<?php require_once '../templates/footer.php'; ?>
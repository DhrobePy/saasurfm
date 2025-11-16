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

// Fetch necessary accounts
$ar_account_q = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Accounts Receivable' LIMIT 1");
$ar_account = $ar_account_q->first();
if (!$ar_account) {
    $error = "FATAL ERROR: 'Accounts Receivable' account not found in Chart of Accounts. Cannot proceed.";
}
$ar_account_id = $ar_account->id;

$default_sales_account_q = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Revenue' AND branch_id IS NULL LIMIT 1");
$default_sales_account = $default_sales_account_q->first();
if (!$default_sales_account) {
     $error = "FATAL ERROR: No default 'Revenue' account found in Chart of Accounts. Cannot proceed.";
}
$default_sales_account_id = $default_sales_account->id;

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
    $vehicle_id = (int)$_POST['vehicle_id'];
    $driver_id = (int)$_POST['driver_id'];
    $trip_date = $_POST['trip_date'] ?? date('Y-m-d');
    $scheduled_time = $_POST['scheduled_time'] ?? null;
    
    if (empty($vehicle_id) || empty($driver_id)) {
        $error = "Please select both vehicle and driver";
    } else {
        try {
            $pdo = $db->getPdo();
            $pdo->beginTransaction();
            
            $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$order_id])->first();
            if (!$order) throw new Exception("Order not found");
            
            // Get vehicle and driver info
            $vehicle = $db->query("SELECT * FROM vehicles WHERE id = ?", [$vehicle_id])->first();
            $driver = $db->query("SELECT * FROM drivers WHERE id = ?", [$driver_id])->first();
            
            if (!$vehicle || !$driver) {
                throw new Exception("Invalid vehicle or driver selected");
            }
            
            $truck_number = $vehicle->vehicle_number;
            $driver_name = $driver->driver_name;
            $driver_contact = $driver->phone_number;
            
            // Create trip assignment
            $trip_data = [
                'order_id' => $order_id,
                'vehicle_id' => $vehicle_id,
                'driver_id' => $driver_id,
                'trip_date' => $trip_date,
                'scheduled_time' => $scheduled_time,
                'destination' => $order->shipping_address,
                'status' => 'In Progress',
                'created_by_user_id' => $user_id
            ];
            
            $trip_id = $db->insert('trip_assignments', $trip_data);
            
            // Insert/Update Shipping Record
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
            
            // Update order status to shipped (FIXED - removed truck/driver columns)
            $db->query("UPDATE credit_orders SET status = 'shipped' WHERE id = ?", [$order_id]);
            
            // Update driver assignment
            $db->query("UPDATE drivers SET assigned_vehicle_id = ? WHERE id = ?", [$vehicle_id, $driver_id]);
            
            // Customer Ledger & Balance Logic
            $prev_balance_result = $db->query(
                "SELECT balance_after FROM customer_ledger 
                 WHERE customer_id = ? ORDER BY transaction_date DESC, id DESC LIMIT 1",
                [$order->customer_id]
            )->first();

            $customer_data = $db->query("SELECT initial_due, name FROM customers WHERE id = ?", [$order->customer_id])->first();
            $customer_name = $customer_data ? $customer_data->name : 'Unknown Customer';

            if ($prev_balance_result) {
                $prev_balance = (float)$prev_balance_result->balance_after;
            } else {
                $prev_balance = $customer_data ? (float)$customer_data->initial_due : 0;
            }
            
            $invoice_amount = (float)$order->balance_due; 

            if ($invoice_amount <= 0 && (float)$order->total_amount > 0) {
                $invoice_amount = (float)$order->total_amount;
                // FIXED - correct update syntax
                $db->query(
                    "UPDATE credit_orders SET balance_due = ? WHERE id = ?",
                    [$invoice_amount, $order_id]
                );
            }

            $new_balance = $prev_balance + $invoice_amount;
            
            // Insert ledger entry
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
                'balance_after' => $new_balance,
                'created_by_user_id' => $user_id
            ]);
            if (!$ledger_id) {
                 throw new Exception("Failed to create customer ledger entry.");
            }

            // Update customer balance (FIXED - correct update syntax)
            $db->query(
                "UPDATE customers SET current_balance = ? WHERE id = ?",
                [$new_balance, $order->customer_id]
            );

            // Find the correct Sales Revenue account
            $sales_account_q = $db->query(
                "SELECT id FROM chart_of_accounts 
                 WHERE account_type = 'Revenue' AND branch_id = ? 
                 LIMIT 1",
                [$order->assigned_branch_id]
            );
            $sales_account = $sales_account_q->first();
            
            $sales_account_id = $sales_account ? $sales_account->id : $default_sales_account_id;

            // Create Journal Entry Header
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

            // DEBIT "Accounts Receivable"
            $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_id,
                'account_id' => $ar_account_id,
                'debit_amount' => $invoice_amount,
                'credit_amount' => 0.00,
                'description' => $journal_desc
            ]);

            // CREDIT "Sales Revenue"
            $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_id,
                'account_id' => $sales_account_id,
                'debit_amount' => 0.00,
                'credit_amount' => $invoice_amount,
                'description' => $journal_desc
            ]);

            // Link Journal ID back to Ledger (FIXED - correct update syntax)
            $db->query(
                "UPDATE customer_ledger SET journal_entry_id = ? WHERE id = ?",
                [$journal_id, $ledger_id]
            );

            // Log workflow
            $db->insert('credit_order_workflow', [
                'order_id' => $order_id,
                'from_status' => 'ready_to_ship',
                'to_status' => 'shipped',
                'action' => 'ship',
                'performed_by_user_id' => $user_id,
                'comments' => "Shipped with truck $truck_number, driver: $driver_name (Trip ID: $trip_id)"
            ]);
            
            $pdo->commit();
            $_SESSION['success_flash'] = "Order shipped successfully. Trip assigned, customer ledger and journal updated.";
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
        
        // Update trip status to Completed
        $db->query("UPDATE trip_assignments SET status = 'Completed', actual_end_time = NOW() WHERE order_id = ?", [$order_id]);
        
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
        <h4 class="font-semibold text-gray-800 mb-4">Assign Transport</h4>
        <form method="POST" class="space-y-4" id="shipForm_<?php echo $order->id; ?>">
            <input type="hidden" name="action" value="ship">
            <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
            <input type="hidden" name="vehicle_id" id="vehicle_id_<?php echo $order->id; ?>">
            <input type="hidden" name="driver_id" id="driver_id_<?php echo $order->id; ?>">
            <input type="hidden" name="trip_date" value="<?php echo date('Y-m-d'); ?>">
            <input type="hidden" name="scheduled_time" value="<?php echo date('H:i'); ?>">
            
            <!-- Vehicle Selection -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Select Vehicle *</label>
                <div id="vehiclesList_<?php echo $order->id; ?>">
                    <p class="text-gray-500 text-center py-4">Loading vehicles...</p>
                </div>
            </div>
            
            <!-- Driver Selection -->
            <div id="driversSection_<?php echo $order->id; ?>" style="display: none;">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Select Driver * <span class="text-xs text-gray-500">(⭐ = Recommended)</span>
                </label>
                <div id="driversList_<?php echo $order->id; ?>">
                    <p class="text-gray-500 text-center py-4">Select a vehicle first</p>
                </div>
            </div>
            
            <div class="flex gap-3" id="submitSection_<?php echo $order->id; ?>" style="display: none;">
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
        
        <script>
        (function() {
            const orderId = <?php echo $order->id; ?>;
            let selectedVehicleId = null;
            let selectedDriverId = null;
            
            // Load vehicles on page load
            fetch('../logistics/ajax/get_available_vehicles.php')
                .then(r => r.json())
                .then(data => {
                    const container = document.getElementById('vehiclesList_' + orderId);
                    if (!data.vehicles || data.vehicles.length === 0) {
                        container.innerHTML = '<p class="text-red-500 text-center py-4">No vehicles available</p>';
                        return;
                    }
                    
                    let html = '<div class="space-y-2">';
                    data.vehicles.forEach(v => {
                        html += `
                            <div class="border rounded-lg p-3 cursor-pointer hover:bg-blue-50 hover:border-blue-500 vehicle-option" 
                                 onclick="selectVehicle_${orderId}(${v.id}, '${v.vehicle_number}')">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <p class="font-medium">${v.vehicle_number}</p>
                                        <p class="text-sm text-gray-600">${v.category} - ${v.fuel_type} - Capacity: ${parseFloat(v.capacity_kg).toLocaleString()} kg</p>
                                        <span class="inline-block mt-1 px-2 py-1 text-xs rounded-full ${v.vehicle_type === 'Own' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800'}">
                                            ${v.vehicle_type}
                                        </span>
                                    </div>
                                    <input type="radio" name="vehicle_radio_${orderId}" value="${v.id}" class="ml-3" />
                                </div>
                            </div>
                        `;
                    });
                    html += '</div>';
                    container.innerHTML = html;
                });
            
            window['selectVehicle_' + orderId] = function(vehicleId, vehicleNumber) {
                selectedVehicleId = vehicleId;
                document.getElementById('vehicle_id_' + orderId).value = vehicleId;
                document.querySelector(`input[name="vehicle_radio_${orderId}"][value="${vehicleId}"]`).checked = true;
                
                // Show drivers section
                document.getElementById('driversSection_' + orderId).style.display = 'block';
                
                // Load available drivers
                loadAvailableDrivers(vehicleId);
            };
            
            function loadAvailableDrivers(vehicleId) {
                const tripDate = '<?php echo date('Y-m-d'); ?>';
                fetch(`../logistics/ajax/get_available_drivers.php?vehicle_id=${vehicleId}&date=${tripDate}`)
                    .then(r => r.json())
                    .then(data => {
                        const container = document.getElementById('driversList_' + orderId);
                        if (!data.drivers || data.drivers.length === 0) {
                            container.innerHTML = '<p class="text-red-500 text-center py-4">No drivers available on this date</p>';
                            return;
                        }
                        
                        let html = '<div class="space-y-2">';
                        data.drivers.forEach(d => {
                            const isRecommended = d.is_recommended || false;
                            html += `
                                <div class="border rounded-lg p-3 cursor-pointer hover:bg-green-50 hover:border-green-500 ${isRecommended ? 'border-green-500 bg-green-50' : ''} driver-option" 
                                     onclick="selectDriver_${orderId}(${d.id}, '${d.driver_name}')">
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1">
                                            <p class="font-medium">
                                                ${d.driver_name} 
                                                ${isRecommended ? '<span class="text-green-600">⭐ Recommended</span>' : ''}
                                            </p>
                                            <p class="text-sm text-gray-600">${d.phone_number}</p>
                                            <p class="text-sm text-gray-600">
                                                ${d.driver_type} | Rating: ${parseFloat(d.rating || 0).toFixed(1)}/5 | Trips: ${d.total_trips || 0}
                                            </p>
                                        </div>
                                        <input type="radio" name="driver_radio_${orderId}" value="${d.id}" class="ml-3" />
                                    </div>
                                </div>
                            `;
                        });
                        html += '</div>';
                        container.innerHTML = html;
                    });
            }
            
            window['selectDriver_' + orderId] = function(driverId, driverName) {
                selectedDriverId = driverId;
                document.getElementById('driver_id_' + orderId).value = driverId;
                document.querySelector(`input[name="driver_radio_${orderId}"][value="${driverId}"]`).checked = true;
                
                // Show submit button
                document.getElementById('submitSection_' + orderId).style.display = 'flex';
            };
        })();
        </script>
        
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
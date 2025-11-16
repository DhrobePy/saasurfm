<?php
require_once '../../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'dispatch-srg', 'dispatch-demra', 'dispatchpos-demra', 'dispatchpos-srg'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Create New Trip';
$error = null;
$success = null;

// Get preselected order if any
$preselected_order_id = (int)($_GET['order_id'] ?? 0);

// Get available orders (not yet in any trip)
$available_orders = $db->query("
    SELECT co.*, 
           c.name as customer_name,
           c.phone_number as customer_phone,
           b.name as branch_name
    FROM credit_orders co
    JOIN customers c ON co.customer_id = c.id
    LEFT JOIN branches b ON co.assigned_branch_id = b.id
    LEFT JOIN trip_order_assignments toa ON co.id = toa.order_id
    WHERE co.status = 'ready_to_ship'
    AND toa.id IS NULL
    ORDER BY co.required_date ASC
")->results();

// Get available vehicles
$vehicles = $db->query("
    SELECT v.* 
    FROM vehicles v
    WHERE v.status = 'active'
    ORDER BY v.vehicle_number
")->results();

// Get available drivers
$drivers = $db->query("
    SELECT d.* 
    FROM drivers d
    WHERE d.status = 'active'
    ORDER BY d.driver_name
")->results();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_orders = $_POST['selected_orders'] ?? [];
    $vehicle_id = (int)$_POST['vehicle_id'];
    $driver_id = (int)$_POST['driver_id'];
    $trip_date = $_POST['trip_date'];
    $scheduled_time = $_POST['scheduled_time'] ?? null;
    
    if (empty($selected_orders)) {
        $error = "Please select at least one order";
    } elseif (empty($vehicle_id) || empty($driver_id)) {
        $error = "Please select vehicle and driver";
    } elseif (empty($trip_date)) {
        $error = "Please select trip date";
    } else {
        try {
            $pdo = $db->getPdo();
            $pdo->beginTransaction();
            
            // Get vehicle capacity
            $vehicle = $db->query("SELECT * FROM vehicles WHERE id = ?", [$vehicle_id])->first();
            if (!$vehicle) throw new Exception("Vehicle not found");
            
            // Calculate total weight and get order details
            $total_weight = 0;
            $order_details = [];
            
            foreach ($selected_orders as $order_id) {
                // Calculate weight for this order
                try {
                    $db->query("CALL sp_calculate_order_weight(?)", [$order_id]);
                } catch (Exception $e) {
                    error_log("Weight calculation warning: " . $e->getMessage());
                }
                
                $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$order_id])->first();
                if ($order) {
                    $order_weight = (float)($order->total_weight_kg ?? 0);
                    $total_weight += $order_weight;
                    $order_details[] = $order;
                }
            }
            
            // Check capacity
            if ($total_weight > $vehicle->capacity_kg) {
                throw new Exception("Total weight ({$total_weight}kg) exceeds vehicle capacity ({$vehicle->capacity_kg}kg)");
            }
            
            // Determine trip type
            $trip_type = count($selected_orders) > 1 ? 'consolidated' : 'single';
            
            // Create trip
            $trip_id = $db->insert('trip_assignments', [
                'vehicle_id' => $vehicle_id,
                'driver_id' => $driver_id,
                'trip_date' => $trip_date,
                'scheduled_time' => $scheduled_time,
                'trip_type' => $trip_type,
                'total_orders' => count($selected_orders),
                'total_weight_kg' => $total_weight,
                'remaining_capacity_kg' => $vehicle->capacity_kg - $total_weight,
                'status' => 'Scheduled',
                'created_by_user_id' => $user_id
            ]);
            
            if (!$trip_id) throw new Exception("Failed to create trip");
            
            // Get driver and vehicle info
            $driver = $db->query("SELECT * FROM drivers WHERE id = ?", [$driver_id])->first();
            
            // Add orders to trip
            $sequence = 1;
            foreach ($order_details as $order) {
                // Add to trip_order_assignments
                $db->insert('trip_order_assignments', [
                    'trip_id' => $trip_id,
                    'order_id' => $order->id,
                    'sequence_number' => $sequence,
                    'destination_address' => $order->shipping_address,
                    'delivery_status' => 'pending'
                ]);
                
                // Update order status to shipped
                $db->query("UPDATE credit_orders SET status = 'shipped' WHERE id = ?", [$order->id]);
                
                // Update/create shipping record
                $shipping_exists = $db->query("SELECT id FROM credit_order_shipping WHERE order_id = ?", [$order->id])->first();
                
                if ($shipping_exists) {
                    $db->query("
                        UPDATE credit_order_shipping 
                        SET trip_id = ?,
                            truck_number = ?,
                            driver_name = ?,
                            driver_contact = ?,
                            shipped_date = NOW(),
                            shipped_by_user_id = ?
                        WHERE order_id = ?
                    ", [$trip_id, $vehicle->vehicle_number, $driver->driver_name, $driver->phone_number, $user_id, $order->id]);
                } else {
                    $db->insert('credit_order_shipping', [
                        'order_id' => $order->id,
                        'trip_id' => $trip_id,
                        'truck_number' => $vehicle->vehicle_number,
                        'driver_name' => $driver->driver_name,
                        'driver_contact' => $driver->phone_number,
                        'shipped_date' => date('Y-m-d H:i:s'),
                        'shipped_by_user_id' => $user_id
                    ]);
                }
                
                // Create accounting entries for each order
                // Get necessary accounts
                $ar_account = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Accounts Receivable' LIMIT 1")->first();
                $sales_account = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Revenue' AND branch_id = ? LIMIT 1", [$order->assigned_branch_id])->first();
                
                if (!$sales_account) {
                    $sales_account = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Revenue' AND branch_id IS NULL LIMIT 1")->first();
                }
                
                // Get customer data
                $customer_data = $db->query("SELECT initial_due, name FROM customers WHERE id = ?", [$order->customer_id])->first();
                
                // Get previous balance
                $prev_balance_result = $db->query(
                    "SELECT balance_after FROM customer_ledger 
                     WHERE customer_id = ? ORDER BY transaction_date DESC, id DESC LIMIT 1",
                    [$order->customer_id]
                )->first();
                
                $prev_balance = $prev_balance_result ? (float)$prev_balance_result->balance_after : (float)$customer_data->initial_due;
                
                $invoice_amount = (float)$order->total_amount;
                $new_balance = $prev_balance + $invoice_amount;
                
                // Insert ledger entry
                $ledger_id = $db->insert('customer_ledger', [
                    'customer_id' => $order->customer_id,
                    'transaction_date' => date('Y-m-d'),
                    'transaction_type' => 'invoice',
                    'reference_type' => 'credit_orders',
                    'reference_id' => $order->id,
                    'invoice_number' => $order->order_number,
                    'description' => "Credit sale - Invoice #" . $order->order_number,
                    'debit_amount' => $invoice_amount,
                    'credit_amount' => 0,
                    'balance_after' => $new_balance,
                    'created_by_user_id' => $user_id
                ]);
                
                // Update customer balance
                $db->query("UPDATE customers SET current_balance = ? WHERE id = ?", [$new_balance, $order->customer_id]);
                
                // Create Journal Entry
                $journal_desc = "Credit Sale Invoice #" . $order->order_number . " to " . $customer_data->name;
                $journal_id = $db->insert('journal_entries', [
                    'transaction_date' => date('Y-m-d'),
                    'description' => $journal_desc,
                    'related_document_type' => 'credit_orders',
                    'related_document_id' => $order->id,
                    'created_by_user_id' => $user_id
                ]);
                
                // DEBIT Accounts Receivable
                $db->insert('transaction_lines', [
                    'journal_entry_id' => $journal_id,
                    'account_id' => $ar_account->id,
                    'debit_amount' => $invoice_amount,
                    'credit_amount' => 0.00,
                    'description' => $journal_desc
                ]);
                
                // CREDIT Sales Revenue
                $db->insert('transaction_lines', [
                    'journal_entry_id' => $journal_id,
                    'account_id' => $sales_account->id,
                    'debit_amount' => 0.00,
                    'credit_amount' => $invoice_amount,
                    'description' => $journal_desc
                ]);
                
                // Link journal to ledger
                $db->query("UPDATE customer_ledger SET journal_entry_id = ? WHERE id = ?", [$journal_id, $ledger_id]);
                
                // Log workflow
                $db->insert('credit_order_workflow', [
                    'order_id' => $order->id,
                    'from_status' => 'ready_to_ship',
                    'to_status' => 'shipped',
                    'action' => 'ship',
                    'performed_by_user_id' => $user_id,
                    'comments' => "Shipped with Trip #$trip_id"
                ]);
                
                $sequence++;
            }
            
            // Update driver status
            $db->query("UPDATE drivers SET is_available = 0, assigned_vehicle_id = ? WHERE id = ?", [$vehicle_id, $driver_id]);
            
            $pdo->commit();
            
            $_SESSION['success_flash'] = "Trip #$trip_id created successfully with " . count($selected_orders) . " order(s)";
            header('Location: view.php?id=' . $trip_id);
            exit();
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
            error_log("Trip creation error: " . $e->getMessage());
        }
    }
}

require_once '../../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6 flex justify-between items-start">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Create New Trip</h1>
        <p class="text-lg text-gray-600 mt-1">Select orders and assign vehicle & driver</p>
    </div>
    <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100">
        <i class="fas fa-arrow-left mr-2"></i>Back to Trips
    </a>
</div>

<?php if ($error): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Error</p>
    <p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<?php if (empty($available_orders)): ?>
<div class="bg-white rounded-lg shadow-md p-12 text-center">
    <i class="fas fa-inbox text-6xl text-gray-400 mb-4"></i>
    <h3 class="text-xl font-semibold text-gray-700 mb-2">No Orders Available</h3>
    <p class="text-gray-600">All orders are either already in trips or not ready for shipping.</p>
</div>
<?php else: ?>

<form method="POST" id="createTripForm">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Left Column: Order Selection -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="p-6 border-b border-gray-200 bg-gray-50">
                    <h2 class="text-xl font-bold text-gray-800">Select Orders</h2>
                    <p class="text-sm text-gray-600 mt-1">Choose one or more orders to include in this trip</p>
                </div>
                
                <div class="p-6">
                    <div class="space-y-4" id="ordersList">
                        <?php foreach ($available_orders as $order): ?>
                        <div class="border-2 rounded-lg p-4 hover:border-blue-400 transition-colors order-item"
                             data-order-id="<?php echo $order->id; ?>"
                             data-weight="<?php echo $order->total_weight_kg ?? 0; ?>">
                            <label class="flex items-start cursor-pointer">
                                <input type="checkbox" 
                                       name="selected_orders[]" 
                                       value="<?php echo $order->id; ?>"
                                       class="mt-1 mr-3 h-5 w-5 text-blue-600 order-checkbox"
                                       <?php echo $preselected_order_id == $order->id ? 'checked' : ''; ?>
                                       onchange="updateTripSummary()">
                                <div class="flex-1">
                                    <div class="flex justify-between items-start mb-2">
                                        <div>
                                            <h3 class="font-bold text-gray-900"><?php echo htmlspecialchars($order->order_number); ?></h3>
                                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($order->customer_name); ?></p>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-bold text-blue-600">৳<?php echo number_format($order->total_amount, 2); ?></p>
                                            <?php if ($order->total_weight_kg): ?>
                                            <p class="text-sm text-gray-600"><?php echo number_format($order->total_weight_kg, 2); ?> kg</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <p class="text-sm text-gray-600 mb-2">
                                        <i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($order->customer_phone); ?>
                                    </p>
                                    <p class="text-sm text-gray-600 mb-2">
                                        <i class="fas fa-map-marker-alt text-red-500 mr-1"></i>
                                        <?php echo htmlspecialchars($order->shipping_address); ?>
                                    </p>
                                    <div class="flex gap-2 text-xs">
                                        <?php if ($order->branch_name): ?>
                                        <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded">
                                            <i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($order->branch_name); ?>
                                        </span>
                                        <?php endif; ?>
                                        <span class="px-2 py-1 bg-orange-100 text-orange-800 rounded">
                                            <i class="fas fa-calendar mr-1"></i>Due: <?php echo date('M j', strtotime($order->required_date)); ?>
                                        </span>
                                    </div>
                                </div>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Trip Configuration -->
        <div class="space-y-6">
            
            <!-- Trip Summary -->
            <div class="bg-gradient-to-br from-blue-50 to-purple-50 rounded-lg shadow-md p-6 border-2 border-blue-200">
                <h3 class="font-bold text-gray-800 mb-4">Trip Summary</h3>
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-700">Selected Orders:</span>
                        <span class="font-bold text-blue-600" id="selectedCount">0</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-700">Total Weight:</span>
                        <span class="font-bold text-purple-600" id="totalWeight">0 kg</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-700">Total Value:</span>
                        <span class="font-bold text-green-600" id="totalValue">৳0</span>
                    </div>
                    <div id="capacityWarning" class="hidden mt-4 p-3 bg-red-100 border border-red-300 rounded text-sm text-red-800">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <span id="capacityWarningText"></span>
                    </div>
                </div>
            </div>
            
            <!-- Vehicle Selection -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="p-6 border-b border-gray-200 bg-gray-50">
                    <h3 class="font-bold text-gray-800">Select Vehicle</h3>
                </div>
                <div class="p-6">
                    <select name="vehicle_id" id="vehicle_id" required
                            onchange="updateVehicleInfo()"
                            class="w-full px-4 py-2 border rounded-lg">
                        <option value="">-- Choose Vehicle --</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                        <option value="<?php echo $vehicle->id; ?>"
                                data-capacity="<?php echo $vehicle->capacity_kg; ?>"
                                data-type="<?php echo $vehicle->vehicle_type; ?>"
                                data-category="<?php echo $vehicle->category; ?>">
                            <?php echo htmlspecialchars($vehicle->vehicle_number); ?> - 
                            <?php echo htmlspecialchars($vehicle->category); ?>
                            (<?php echo number_format($vehicle->capacity_kg, 0); ?> kg)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="vehicleInfo" class="hidden mt-3 p-3 bg-blue-50 border border-blue-200 rounded text-sm"></div>
                </div>
            </div>
            
            <!-- Driver Selection -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="p-6 border-b border-gray-200 bg-gray-50">
                    <h3 class="font-bold text-gray-800">Select Driver</h3>
                </div>
                <div class="p-6">
                    <select name="driver_id" id="driver_id" required
                            onchange="updateDriverInfo()"
                            class="w-full px-4 py-2 border rounded-lg">
                        <option value="">-- Choose Driver --</option>
                        <?php foreach ($drivers as $driver): ?>
                        <option value="<?php echo $driver->id; ?>"
                                data-phone="<?php echo $driver->phone_number; ?>"
                                data-type="<?php echo $driver->driver_type; ?>"
                                data-rating="<?php echo $driver->rating ?? 0; ?>">
                            <?php echo htmlspecialchars($driver->driver_name); ?> - 
                            <?php echo htmlspecialchars($driver->driver_type); ?>
                            (<?php echo number_format($driver->rating ?? 0, 1); ?>⭐)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="driverInfo" class="hidden mt-3 p-3 bg-green-50 border border-green-200 rounded text-sm"></div>
                </div>
            </div>
            
            <!-- Trip Schedule -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="p-6 border-b border-gray-200 bg-gray-50">
                    <h3 class="font-bold text-gray-800">Trip Schedule</h3>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Trip Date *</label>
                        <input type="date" name="trip_date" required
                               value="<?php echo date('Y-m-d'); ?>"
                               min="<?php echo date('Y-m-d'); ?>"
                               class="w-full px-4 py-2 border rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Scheduled Time</label>
                        <input type="time" name="scheduled_time"
                               value="<?php echo date('H:i'); ?>"
                               class="w-full px-4 py-2 border rounded-lg">
                    </div>
                </div>
            </div>
            
            <!-- Submit Button -->
            <button type="submit" 
                    class="w-full px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-bold text-lg">
                <i class="fas fa-check-circle mr-2"></i>Create Trip
            </button>
        </div>
    </div>
</form>

<?php endif; ?>

</div>

<script>
let selectedOrders = [];
let vehicleCapacity = 0;

function updateTripSummary() {
    const checkboxes = document.querySelectorAll('.order-checkbox:checked');
    let totalWeight = 0;
    let totalValue = 0;
    
    selectedOrders = [];
    
    checkboxes.forEach(cb => {
        const orderItem = cb.closest('.order-item');
        const weight = parseFloat(orderItem.dataset.weight || 0);
        const orderId = orderItem.dataset.orderId;
        
        // Get the value from the display
        const valueText = orderItem.querySelector('.text-blue-600').textContent;
        const value = parseFloat(valueText.replace('৳', '').replace(/,/g, ''));
        
        totalWeight += weight;
        totalValue += value;
        selectedOrders.push(orderId);
    });
    
    document.getElementById('selectedCount').textContent = checkboxes.length;
    document.getElementById('totalWeight').textContent = totalWeight.toFixed(2) + ' kg';
    document.getElementById('totalValue').textContent = '৳' + totalValue.toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    checkCapacity(totalWeight);
}

function updateVehicleInfo() {
    const select = document.getElementById('vehicle_id');
    const option = select.options[select.selectedIndex];
    const info = document.getElementById('vehicleInfo');
    
    if (option.value) {
        vehicleCapacity = parseFloat(option.dataset.capacity);
        info.innerHTML = `
            <strong>Category:</strong> ${option.dataset.category}<br>
            <strong>Type:</strong> ${option.dataset.type}<br>
            <strong>Capacity:</strong> ${vehicleCapacity.toLocaleString()} kg
        `;
        info.classList.remove('hidden');
        
        // Recheck capacity
        const totalWeight = parseFloat(document.getElementById('totalWeight').textContent);
        checkCapacity(totalWeight);
    } else {
        info.classList.add('hidden');
        vehicleCapacity = 0;
    }
}

function updateDriverInfo() {
    const select = document.getElementById('driver_id');
    const option = select.options[select.selectedIndex];
    const info = document.getElementById('driverInfo');
    
    if (option.value) {
        info.innerHTML = `
            <strong>Phone:</strong> ${option.dataset.phone}<br>
            <strong>Type:</strong> ${option.dataset.type}<br>
            <strong>Rating:</strong> ${option.dataset.rating}⭐
        `;
        info.classList.remove('hidden');
    } else {
        info.classList.add('hidden');
    }
}

function checkCapacity(totalWeight) {
    const warning = document.getElementById('capacityWarning');
    const warningText = document.getElementById('capacityWarningText');
    
    if (vehicleCapacity > 0 && totalWeight > vehicleCapacity) {
        warningText.textContent = `Total weight (${totalWeight.toFixed(2)} kg) exceeds vehicle capacity (${vehicleCapacity.toFixed(2)} kg)`;
        warning.classList.remove('hidden');
    } else {
        warning.classList.add('hidden');
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateTripSummary();
});
</script>

<?php require_once '../../templates/footer.php'; ?>
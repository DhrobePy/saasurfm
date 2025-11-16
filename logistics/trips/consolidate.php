<?php
require_once '../../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'dispatch-srg', 'dispatch-demra', 'dispatchpos-demra', 'dispatchpos-srg'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Smart Consolidation';
$error = null;
$success = null;

// Refresh consolidation suggestions
if (isset($_GET['refresh'])) {
    try {
        // Run consolidation for all ready orders
        $ready_orders = $db->query("
            SELECT id FROM credit_orders 
            WHERE status = 'ready_to_ship'
        ")->results();
        
        foreach ($ready_orders as $order) {
            try {
                $db->query("CALL sp_find_consolidation_opportunities(?)", [$order->id]);
            } catch (Exception $e) {
                error_log("Consolidation suggestion error: " . $e->getMessage());
            }
        }
        
        $_SESSION['success_flash'] = "Consolidation opportunities refreshed";
        header('Location: consolidate.php');
        exit();
    } catch (Exception $e) {
        $error = "Failed to refresh: " . $e->getMessage();
    }
}

// Get consolidation opportunities
$opportunities = $db->query("
    SELECT 
        ocs.*,
        co1.order_number as order1_number,
        co1.total_amount as order1_amount,
        co1.total_weight_kg as order1_weight,
        co1.shipping_address as order1_address,
        co2.order_number as order2_number,
        co2.total_amount as order2_amount,
        co2.total_weight_kg as order2_weight,
        co2.shipping_address as order2_address,
        c1.name as customer1_name,
        c1.phone_number as customer1_phone,
        c2.name as customer2_name,
        c2.phone_number as customer2_phone
    FROM order_consolidation_suggestions ocs
    JOIN credit_orders co1 ON ocs.order_id_1 = co1.id
    JOIN credit_orders co2 ON ocs.order_id_2 = co2.id
    JOIN customers c1 ON co1.customer_id = c1.id
    JOIN customers c2 ON co2.customer_id = c2.id
    WHERE ocs.status = 'pending'
    AND co1.status = 'ready_to_ship'
    AND co2.status = 'ready_to_ship'
    ORDER BY ocs.potential_savings DESC
")->results();

// Handle accept/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $suggestion_id = (int)($_POST['suggestion_id'] ?? 0);
    
    try {
        $pdo = $db->getPdo();
        $pdo->beginTransaction();
        
        if ($action === 'accept') {
            // Get suggestion details
            $suggestion = $db->query("SELECT * FROM order_consolidation_suggestions WHERE id = ?", [$suggestion_id])->first();
            if (!$suggestion) throw new Exception("Suggestion not found");
            
            // Get both orders
            $order1 = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$suggestion->order_id_1])->first();
            $order2 = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$suggestion->order_id_2])->first();
            
            if (!$order1 || !$order2) throw new Exception("Orders not found");
            
            // Calculate weights
            $total_weight = (float)($order1->total_weight_kg ?? 0) + (float)($order2->total_weight_kg ?? 0);
            
            // Get an available vehicle with sufficient capacity
            $vehicle = $db->query("
                SELECT * FROM vehicles 
                WHERE status = 'active' 
                AND capacity_kg >= ?
                ORDER BY capacity_kg ASC
                LIMIT 1
            ", [$total_weight])->first();
            
            if (!$vehicle) throw new Exception("No vehicle available with sufficient capacity");
            
            // Get an available driver
            $driver = $db->query("
                SELECT * FROM drivers 
                WHERE status = 'active' 
                AND is_available = 1
                ORDER BY rating DESC
                LIMIT 1
            ")->first();
            
            if (!$driver) throw new Exception("No driver available");
            
            // Create consolidated trip
            $trip_id = $db->insert('trip_assignments', [
                'vehicle_id' => $vehicle->id,
                'driver_id' => $driver->id,
                'trip_date' => date('Y-m-d'),
                'scheduled_time' => date('H:i:s'),
                'trip_type' => 'consolidated',
                'total_orders' => 2,
                'total_weight_kg' => $total_weight,
                'remaining_capacity_kg' => $vehicle->capacity_kg - $total_weight,
                'status' => 'Scheduled',
                'notes' => "Auto-created via smart consolidation. Savings: ৳" . number_format($suggestion->potential_savings, 2),
                'created_by_user_id' => $user_id
            ]);
            
            if (!$trip_id) throw new Exception("Failed to create trip");
            
            // Add both orders to trip
            $orders = [$order1, $order2];
            $sequence = 1;
            
            foreach ($orders as $order) {
                // Add to trip_order_assignments
                $db->insert('trip_order_assignments', [
                    'trip_id' => $trip_id,
                    'order_id' => $order->id,
                    'sequence_number' => $sequence,
                    'destination_address' => $order->shipping_address,
                    'delivery_status' => 'pending'
                ]);
                
                // Update order status
                $db->query("UPDATE credit_orders SET status = 'shipped' WHERE id = ?", [$order->id]);
                
                // Create shipping record
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
                
                // Create accounting entries (same as in create.php)
                $ar_account = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Accounts Receivable' LIMIT 1")->first();
                $sales_account = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Revenue' AND branch_id = ? LIMIT 1", [$order->assigned_branch_id])->first();
                
                if (!$sales_account) {
                    $sales_account = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Revenue' AND branch_id IS NULL LIMIT 1")->first();
                }
                
                $customer_data = $db->query("SELECT initial_due, name FROM customers WHERE id = ?", [$order->customer_id])->first();
                
                $prev_balance_result = $db->query(
                    "SELECT balance_after FROM customer_ledger 
                     WHERE customer_id = ? ORDER BY transaction_date DESC, id DESC LIMIT 1",
                    [$order->customer_id]
                )->first();
                
                $prev_balance = $prev_balance_result ? (float)$prev_balance_result->balance_after : (float)$customer_data->initial_due;
                
                $invoice_amount = (float)$order->total_amount;
                $new_balance = $prev_balance + $invoice_amount;
                
                $ledger_id = $db->insert('customer_ledger', [
                    'customer_id' => $order->customer_id,
                    'transaction_date' => date('Y-m-d'),
                    'transaction_type' => 'invoice',
                    'reference_type' => 'credit_orders',
                    'reference_id' => $order->id,
                    'invoice_number' => $order->order_number,
                    'description' => "Credit sale - Invoice #" . $order->order_number . " (Consolidated)",
                    'debit_amount' => $invoice_amount,
                    'credit_amount' => 0,
                    'balance_after' => $new_balance,
                    'created_by_user_id' => $user_id
                ]);
                
                $db->query("UPDATE customers SET current_balance = ? WHERE id = ?", [$new_balance, $order->customer_id]);
                
                $journal_desc = "Credit Sale Invoice #" . $order->order_number . " to " . $customer_data->name;
                $journal_id = $db->insert('journal_entries', [
                    'transaction_date' => date('Y-m-d'),
                    'description' => $journal_desc,
                    'related_document_type' => 'credit_orders',
                    'related_document_id' => $order->id,
                    'created_by_user_id' => $user_id
                ]);
                
                $db->insert('transaction_lines', [
                    'journal_entry_id' => $journal_id,
                    'account_id' => $ar_account->id,
                    'debit_amount' => $invoice_amount,
                    'credit_amount' => 0.00,
                    'description' => $journal_desc
                ]);
                
                $db->insert('transaction_lines', [
                    'journal_entry_id' => $journal_id,
                    'account_id' => $sales_account->id,
                    'debit_amount' => 0.00,
                    'credit_amount' => $invoice_amount,
                    'description' => $journal_desc
                ]);
                
                $db->query("UPDATE customer_ledger SET journal_entry_id = ? WHERE id = ?", [$journal_id, $ledger_id]);
                
                $db->insert('credit_order_workflow', [
                    'order_id' => $order->id,
                    'from_status' => 'ready_to_ship',
                    'to_status' => 'shipped',
                    'action' => 'ship',
                    'performed_by_user_id' => $user_id,
                    'comments' => "Consolidated trip #$trip_id - Savings: ৳" . number_format($suggestion->potential_savings, 2)
                ]);
                
                $sequence++;
            }
            
            // Update driver status
            $db->query("UPDATE drivers SET is_available = 0, assigned_vehicle_id = ? WHERE id = ?", [$vehicle->id, $driver->id]);
            
            // Mark suggestion as accepted
            $db->query("UPDATE order_consolidation_suggestions SET status = 'accepted' WHERE id = ?", [$suggestion_id]);
            
            $pdo->commit();
            
            $_SESSION['success_flash'] = "Consolidated trip #$trip_id created! Estimated savings: ৳" . number_format($suggestion->potential_savings, 2);
            header('Location: view.php?id=' . $trip_id);
            exit();
            
        } elseif ($action === 'reject') {
            $db->query("UPDATE order_consolidation_suggestions SET status = 'rejected' WHERE id = ?", [$suggestion_id]);
            $pdo->commit();
            
            $_SESSION['success_flash'] = "Consolidation suggestion rejected";
            header('Location: consolidate.php');
            exit();
        }
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        error_log("Consolidation action error: " . $e->getMessage());
    }
}

require_once '../../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6 flex justify-between items-start">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">💡 Smart Consolidation</h1>
        <p class="text-lg text-gray-600 mt-1">Reduce transportation costs by combining nearby orders</p>
    </div>
    <div class="flex gap-3">
        <a href="consolidate.php?refresh=1" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
            <i class="fas fa-sync mr-2"></i>Refresh Suggestions
        </a>
        <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100">
            <i class="fas fa-arrow-left mr-2"></i>Back to Trips
        </a>
    </div>
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

<!-- Summary Stats -->
<?php if (!empty($opportunities)): ?>
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg shadow-lg p-6 border-2 border-green-300">
        <p class="text-sm text-green-800 opacity-90">Total Opportunities</p>
        <p class="text-4xl font-bold mt-2 text-green-900"><?php echo count($opportunities); ?></p>
    </div>
    <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg shadow-lg p-6 border-2 border-blue-300">
        <p class="text-sm text-blue-800 opacity-90">Potential Savings</p>
        <p class="text-4xl font-bold mt-2 text-blue-900">৳<?php echo number_format(array_sum(array_column($opportunities, 'potential_savings')), 2); ?></p>
    </div>
    <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg shadow-lg p-6 border-2 border-purple-300">
        <p class="text-sm text-purple-800 opacity-90">Avg. Distance</p>
        <p class="text-4xl font-bold mt-2 text-purple-900">
            <?php echo number_format(array_sum(array_column($opportunities, 'distance_km')) / count($opportunities), 1); ?> km
        </p>
    </div>
</div>
<?php endif; ?>

<!-- Consolidation Opportunities -->
<?php if (!empty($opportunities)): ?>
    <?php foreach ($opportunities as $opp): ?>
    <div class="bg-white rounded-lg shadow-lg mb-6 overflow-hidden border-2 border-purple-200">
        <div class="p-6 bg-gradient-to-r from-purple-50 to-blue-50">
            <div class="flex justify-between items-start">
                <div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">
                        <i class="fas fa-lightbulb text-yellow-500 mr-2"></i>
                        Consolidation Opportunity #<?php echo $opp->id; ?>
                    </h3>
                    <div class="flex items-center gap-4 text-sm">
                        <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full font-semibold">
                            <i class="fas fa-piggy-bank mr-1"></i>
                            Save ৳<?php echo number_format($opp->potential_savings, 2); ?>
                        </span>
                        <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full font-semibold">
                            <i class="fas fa-map-marker-alt mr-1"></i>
                            <?php echo number_format($opp->distance_km, 1); ?> km apart
                        </span>
                        <span class="text-gray-600">
                            <i class="fas fa-calendar mr-1"></i>
                            Suggested: <?php echo date('M j, Y g:i A', strtotime($opp->suggested_at)); ?>
                        </span>
                    </div>
                </div>
                <div class="flex gap-2">
                    <form method="POST" class="inline">
                        <input type="hidden" name="suggestion_id" value="<?php echo $opp->id; ?>">
                        <button type="submit" name="action" value="accept"
                                onclick="return confirm('Create consolidated trip for these orders?');"
                                class="px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium">
                            <i class="fas fa-check-circle mr-2"></i>Accept & Create Trip
                        </button>
                    </form>
                    <form method="POST" class="inline">
                        <input type="hidden" name="suggestion_id" value="<?php echo $opp->id; ?>">
                        <button type="submit" name="action" value="reject"
                                onclick="return confirm('Reject this consolidation suggestion?');"
                                class="px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 font-medium">
                            <i class="fas fa-times-circle mr-2"></i>Reject
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <!-- Order 1 -->
                <div class="border-2 border-blue-200 rounded-lg p-5 bg-blue-50">
                    <h4 class="font-bold text-lg text-blue-900 mb-3">
                        <i class="fas fa-box mr-2"></i>Order #1
                    </h4>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-700">Order Number:</span>
                            <span class="font-bold text-gray-900"><?php echo htmlspecialchars($opp->order1_number); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-700">Customer:</span>
                            <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($opp->customer1_name); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-700">Phone:</span>
                            <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($opp->customer1_phone); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-700">Amount:</span>
                            <span class="font-bold text-blue-600">৳<?php echo number_format($opp->order1_amount, 2); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-700">Weight:</span>
                            <span class="font-bold text-purple-600"><?php echo number_format($opp->order1_weight, 2); ?> kg</span>
                        </div>
                        <div class="pt-2 mt-2 border-t border-blue-300">
                            <p class="text-gray-700 font-semibold mb-1">
                                <i class="fas fa-map-marker-alt text-red-500 mr-1"></i>Delivery Address:
                            </p>
                            <p class="text-gray-900"><?php echo htmlspecialchars($opp->order1_address); ?></p>
                        </div>
                        <a href="../../credit/credit_order_view.php?id=<?php echo $opp->order_id_1; ?>" 
                           class="block mt-3 text-center px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                            <i class="fas fa-eye mr-1"></i>View Order Details
                        </a>
                    </div>
                </div>
                
                <!-- Order 2 -->
                <div class="border-2 border-green-200 rounded-lg p-5 bg-green-50">
                    <h4 class="font-bold text-lg text-green-900 mb-3">
                        <i class="fas fa-box mr-2"></i>Order #2
                    </h4>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-700">Order Number:</span>
                            <span class="font-bold text-gray-900"><?php echo htmlspecialchars($opp->order2_number); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-700">Customer:</span>
                            <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($opp->customer2_name); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-700">Phone:</span>
                            <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($opp->customer2_phone); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-700">Amount:</span>
                            <span class="font-bold text-blue-600">৳<?php echo number_format($opp->order2_amount, 2); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-700">Weight:</span>
                            <span class="font-bold text-purple-600"><?php echo number_format($opp->order2_weight, 2); ?> kg</span>
                        </div>
                        <div class="pt-2 mt-2 border-t border-green-300">
                            <p class="text-gray-700 font-semibold mb-1">
                                <i class="fas fa-map-marker-alt text-red-500 mr-1"></i>Delivery Address:
                            </p>
                            <p class="text-gray-900"><?php echo htmlspecialchars($opp->order2_address); ?></p>
                        </div>
                        <a href="../../credit/credit_order_view.php?id=<?php echo $opp->order_id_2; ?>" 
                           class="block mt-3 text-center px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                            <i class="fas fa-eye mr-1"></i>View Order Details
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Consolidation Benefits -->
            <div class="mt-6 p-4 bg-yellow-50 border-2 border-yellow-300 rounded-lg">
                <h5 class="font-bold text-yellow-900 mb-3">
                    <i class="fas fa-chart-line mr-2"></i>Consolidation Benefits
                </h5>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <div>
                        <p class="text-gray-700 mb-1">Combined Weight:</p>
                        <p class="text-2xl font-bold text-purple-600">
                            <?php echo number_format($opp->order1_weight + $opp->order2_weight, 2); ?> kg
                        </p>
                    </div>
                    <div>
                        <p class="text-gray-700 mb-1">Combined Value:</p>
                        <p class="text-2xl font-bold text-blue-600">
                            ৳<?php echo number_format($opp->order1_amount + $opp->order2_amount, 2); ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-gray-700 mb-1">Estimated Savings:</p>
                        <p class="text-2xl font-bold text-green-600">
                            ৳<?php echo number_format($opp->potential_savings, 2); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

<?php else: ?>
<div class="bg-white rounded-lg shadow-md p-12 text-center">
    <i class="fas fa-check-circle text-6xl text-green-400 mb-4"></i>
    <h3 class="text-xl font-semibold text-gray-700 mb-2">No Consolidation Opportunities</h3>
    <p class="text-gray-600 mb-6">All orders are optimally dispatched or no suitable pairs found.</p>
    <a href="consolidate.php?refresh=1" class="inline-block px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
        <i class="fas fa-sync mr-2"></i>Refresh Suggestions
    </a>
</div>
<?php endif; ?>

</div>

<?php require_once '../../templates/footer.php'; ?>
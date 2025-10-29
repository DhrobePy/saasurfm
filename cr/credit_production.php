<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'Accounts', 'admin', 'production manager-srg', 'production manager-demra', 'production-rampura'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Production Management';
$error = null;
$success = null;

$is_admin = in_array($user_role, ['Superadmin', 'admin']);

// Get user's branch
// Get user's branch - Check both employees and users table
$user_branch = null;
if (!$is_admin) {
    // First try employees table
    $emp = $db->query("SELECT branch_id FROM employees WHERE user_id = ?", [$user_id])->first();
    if ($emp && $emp->branch_id) {
        $user_branch = $emp->branch_id;
    } else {
        // Fallback: try users table if it has branch_id column
        $user_record = $db->query("SELECT branch_id FROM users WHERE id = ?", [$user_id])->first();
        if ($user_record && isset($user_record->branch_id)) {
            $user_branch = $user_record->branch_id;
        }
    }
}

// Build branch filter
$branch_filter = "";
$branch_params = [];
if (!$is_admin && $user_branch) {
    $branch_filter = "AND co.assigned_branch_id = ?";
    $branch_params[] = $user_branch;
}

// Get orders for production
$orders = $db->query(
    "SELECT co.*, 
            c.name as customer_name,
            c.phone_number as customer_phone,
            b.name as branch_name,
            u.display_name as created_by_name,
            ps.scheduled_date,
            ps.production_started_at,
            ps.production_completed_at
     FROM credit_orders co
     JOIN customers c ON co.customer_id = c.id
     LEFT JOIN branches b ON co.assigned_branch_id = b.id
     LEFT JOIN users u ON co.created_by_user_id = u.id
     LEFT JOIN production_schedule ps ON co.id = ps.order_id
     WHERE co.status IN ('approved', 'in_production', 'produced', 'ready_to_ship') 
     AND co.assigned_branch_id IS NOT NULL
     $branch_filter
     ORDER BY 
        CASE co.status 
            WHEN 'approved' THEN 1
            WHEN 'in_production' THEN 2
            WHEN 'produced' THEN 3
            WHEN 'ready_to_ship' THEN 4
        END,
        co.required_date ASC",
    $branch_params
)->results();

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $order_id = (int)$_POST['order_id'];
    
    try {
        $db->getPdo()->beginTransaction();
        
        $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$order_id])->first();
        if (!$order) throw new Exception("Order not found");
        
        $old_status = $order->status;
        $new_status = $old_status;
        $workflow_action = '';
        
        if ($action === 'start') {
            $new_status = 'in_production';
            $workflow_action = 'start_production';
            
            $db->query("UPDATE credit_orders SET status = ? WHERE id = ?", [$new_status, $order_id]);
            $db->query("UPDATE production_schedule SET production_started_at = NOW(), status = 'in_progress' WHERE order_id = ?", [$order_id]);
            
            $success = "Production started for order " . $order->order_number;
            
        } elseif ($action === 'complete') {
            $new_status = 'produced';
            $workflow_action = 'complete_production';
            
            $db->query("UPDATE credit_orders SET status = ? WHERE id = ?", [$new_status, $order_id]);
            $db->query("UPDATE production_schedule SET production_completed_at = NOW(), status = 'completed' WHERE order_id = ?", [$order_id]);
            
            $success = "Production completed for order " . $order->order_number;
            
        } elseif ($action === 'ready') {
            $new_status = 'ready_to_ship';
            $workflow_action = 'ship';
            
            $db->query("UPDATE credit_orders SET status = ? WHERE id = ?", [$new_status, $order_id]);
            
            $success = "Order marked as ready to ship: " . $order->order_number;
            
        } elseif ($action === 'update_priority') {
            $priority = (int)$_POST['priority'];
            $db->query("UPDATE production_schedule SET priority_order = ? WHERE order_id = ?", [$priority, $order_id]);
            
            $success = "Priority updated";
            
        } else {
            throw new Exception("Invalid action");
        }
        
        // Log workflow
        $db->insert('credit_order_workflow', [
            'order_id' => $order_id,
            'from_status' => $old_status,
            'to_status' => $new_status,
            'action' => $workflow_action,
            'performed_by_user_id' => $user_id,
            'comments' => 'Production status updated'
        ]);
        
        $db->getPdo()->commit();
        $_SESSION['success_flash'] = $success;
        header('Location: credit_production.php');
        exit();
        
    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        $error = $e->getMessage();
    }
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">Manage production queue and track order progress</p>
</div>

<?php if ($error): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Error</p>
    <p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<!-- Statistics -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <?php
    $approved_result = $db->query("SELECT COUNT(*) as c FROM credit_orders WHERE status = 'approved' $branch_filter", $branch_params)->first();
$production_result = $db->query("SELECT COUNT(*) as c FROM credit_orders WHERE status = 'in_production' $branch_filter", $branch_params)->first();
$produced_result = $db->query("SELECT COUNT(*) as c FROM credit_orders WHERE status = 'produced' $branch_filter", $branch_params)->first();
$ready_result = $db->query("SELECT COUNT(*) as c FROM credit_orders WHERE status = 'ready_to_ship' $branch_filter", $branch_params)->first();

$stats = [
    'approved' => $approved_result ? $approved_result->c : 0,
    'in_production' => $production_result ? $production_result->c : 0,
    'produced' => $produced_result ? $produced_result->c : 0,
    'ready_to_ship' => $ready_result ? $ready_result->c : 0
];
    ?>
    <div class="bg-blue-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Pending Start</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['approved']; ?></p>
    </div>
    <div class="bg-purple-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">In Production</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['in_production']; ?></p>
    </div>
    <div class="bg-green-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Produced</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['produced']; ?></p>
    </div>
    <div class="bg-orange-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Ready to Ship</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['ready_to_ship']; ?></p>
    </div>
</div>

<!-- Production Queue -->
<?php if (!empty($orders)): ?>
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <div class="p-6 border-b border-gray-200 bg-gray-50">
        <h2 class="text-xl font-bold text-gray-800">Production Queue</h2>
        <p class="text-sm text-gray-600 mt-1">Orders are prioritized by required date. Drag to reorder priority.</p>
    </div>
    
    <div class="divide-y divide-gray-200">
        <?php foreach ($orders as $idx => $order): 
            // Get items count
            $items_count = $db->query("SELECT COUNT(*) as c FROM credit_order_items WHERE order_id = ?", [$order->id])->first()->c;
            
            $status_colors = [
                'approved' => 'blue',
                'in_production' => 'purple',
                'produced' => 'green',
                'ready_to_ship' => 'orange'
            ];
            $color = $status_colors[$order->status] ?? 'gray';
        ?>
        <div class="p-6 hover:bg-gray-50 transition-colors" data-order-id="<?php echo $order->id; ?>">
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <!-- Priority Badge -->
                    <div class="flex items-center gap-3 mb-2">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-200 text-gray-800">
                            #<?php echo $order->priority_order ?? ($idx + 1); ?>
                        </span>
                        <span class="px-3 py-1 text-xs font-semibold rounded-full bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-800">
                            <?php echo ucwords(str_replace('_', ' ', $order->status)); ?>
                        </span>
                    </div>
                    
                    <!-- Order Info -->
                    <h3 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($order->order_number); ?></h3>
                    <p class="text-sm text-gray-600 mt-1">
                        <strong>Customer:</strong> <?php echo htmlspecialchars($order->customer_name); ?> •
                        <strong>Items:</strong> <?php echo $items_count; ?> •
                        <strong>Required:</strong> <?php echo date('M j, Y', strtotime($order->required_date)); ?>
                    </p>
                    
                    <?php if ($order->scheduled_date): ?>
                    <p class="text-sm text-gray-600 mt-1">
                        <i class="fas fa-calendar mr-1"></i>Scheduled: <?php echo date('M j, Y', strtotime($order->scheduled_date)); ?>
                    </p>
                    <?php endif; ?>
                    
                    <?php if ($order->production_started_at): ?>
                    <p class="text-sm text-green-600 mt-1">
                        <i class="fas fa-play-circle mr-1"></i>Started: <?php echo date('M j, g:i A', strtotime($order->production_started_at)); ?>
                    </p>
                    <?php endif; ?>
                    
                    <?php if ($order->special_instructions): ?>
                    <p class="text-sm text-blue-600 mt-2">
                        <i class="fas fa-info-circle mr-1"></i><?php echo htmlspecialchars($order->special_instructions); ?>
                    </p>
                    <?php endif; ?>
                </div>
                
                <!-- Actions -->
                <div class="ml-6">
                    <form method="POST" class="space-y-2">
                        <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
                        
                        <?php if ($order->status === 'approved'): ?>
                        <button type="submit" name="action" value="start" 
                                class="w-full px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm font-medium">
                            <i class="fas fa-play mr-1"></i>Start Production
                        </button>
                        <?php endif; ?>
                        
                        <?php if ($order->status === 'in_production'): ?>
                        <button type="submit" name="action" value="complete" 
                                class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-medium">
                            <i class="fas fa-check mr-1"></i>Mark Complete
                        </button>
                        <?php endif; ?>
                        
                        <?php if ($order->status === 'produced'): ?>
                        <button type="submit" name="action" value="ready" 
                                class="w-full px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 text-sm font-medium">
                            <i class="fas fa-truck mr-1"></i>Ready to Ship
                        </button>
                        <?php endif; ?>
                        
                        <a href="credit_order_view.php?id=<?php echo $order->id; ?>" 
   class="text-blue-600 hover:text-blue-900">
    <i class="fas fa-eye mr-2"></i>View Details
</a>
                        
                        <!-- Priority Adjuster -->
                        <?php if ($is_admin): ?>
                        <div class="mt-2 pt-2 border-t border-gray-200">
                            <label class="block text-xs text-gray-600 mb-1">Priority:</label>
                            <div class="flex gap-1">
                                <input type="number" name="priority" value="<?php echo $order->priority_order ?? ($idx + 1); ?>" 
                                       class="w-16 px-2 py-1 border rounded text-xs">
                                <button type="submit" name="action" value="update_priority" 
                                        class="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">
                                    <i class="fas fa-save"></i>
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php else: ?>
<div class="bg-white rounded-lg shadow-md p-12 text-center">
    <i class="fas fa-industry text-6xl text-gray-400 mb-4"></i>
    <h3 class="text-xl font-semibold text-gray-700 mb-2">No Orders in Production Queue</h3>
    <p class="text-gray-600">All orders have been completed or no new orders assigned yet.</p>
</div>
<?php endif; ?>

</div>

<?php require_once '../templates/footer.php'; ?>
<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'Accounts', 'admin', 'production manager-srg', 'production manager-demra'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Production Management';
$error = null;
$success = null;

$is_admin = in_array($user_role, ['Superadmin', 'admin']);

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

// Admin branch filter from GET (admins can pick any factory)
$filter_branch_id = 0;
$filter_branch_name = '';
$all_branches = [];
if ($is_admin) {
    $all_branches = $db->query(
        "SELECT id, name FROM branches WHERE status = 'active' ORDER BY name"
    )->results();
    $filter_branch_id = (int)($_GET['branch_id'] ?? 0);
    if ($filter_branch_id > 0) {
        $branch_filter = "AND co.assigned_branch_id = ?";
        $branch_params = [$filter_branch_id];
        foreach ($all_branches as $br) {
            if ((int)$br->id === $filter_branch_id) {
                $filter_branch_name = $br->name;
                break;
            }
        }
    }
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
            ps.production_completed_at,
            ps.priority_order
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
        ps.priority_order ASC,
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
            
            // Update or create production schedule
            $schedule_exists = $db->query("SELECT id FROM production_schedule WHERE order_id = ?", [$order_id])->first();
            if ($schedule_exists) {
                $db->query("UPDATE production_schedule SET production_started_at = NOW(), status = 'in_progress' WHERE order_id = ?", [$order_id]);
            } else {
                $db->insert('production_schedule', [
                    'order_id' => $order_id,
                    'production_started_at' => date('Y-m-d H:i:s'),
                    'status' => 'in_progress'
                ]);
            }
            
            $success = "Production started for order " . $order->order_number;
            
            // ============================================
            // TELEGRAM NOTIFICATION - PRODUCTION STARTED
            // ============================================
            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                try {
                    require_once '../core/classes/TelegramNotifier.php';
                    $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                    
                    // Get customer and branch details
                    $customer_info = $db->query("SELECT name, phone_number FROM customers WHERE id = ?", [$order->customer_id])->first();
                    $branch_info = $db->query("SELECT name FROM branches WHERE id = ?", [$order->assigned_branch_id])->first();
                    
                    // Get user name
                    $user_info = $db->query("SELECT display_name FROM users WHERE id = ?", [$user_id])->first();
                    
                    // Get order items
                    $items = $db->query(
                        "SELECT coi.*, p.base_name as product_name, pv.grade, pv.weight_variant, pv.unit_of_measure
                         FROM credit_order_items coi
                         JOIN products p ON coi.product_id = p.id
                         LEFT JOIN product_variants pv ON coi.variant_id = pv.id
                         WHERE coi.order_id = ?",
                        [$order_id]
                    )->results();
                    
                    $notification_items = [];
                    foreach ($items as $item) {
                        $variant_name = trim(($item->grade ?? '') . ' ' . ($item->weight_variant ?? ''));
                        $notification_items[] = [
                            'product_name' => $item->product_name,
                            'variant_name' => $variant_name,
                            'quantity' => floatval($item->quantity),
                            'unit' => $item->unit_of_measure ?? 'pcs'
                        ];
                    }
                    
                    $productionData = [
                        'order_number' => $order->order_number,
                        'started_at' => date('d M Y, h:i A'),
                        'customer_name' => $customer_info ? $customer_info->name : 'Unknown',
                        'customer_phone' => $customer_info ? $customer_info->phone_number : 'N/A',
                        'branch_name' => $branch_info ? $branch_info->name : 'Unknown Branch',
                        'required_date' => date('d M Y', strtotime($order->required_date)),
                        'items' => $notification_items,
                        'total_amount' => floatval($order->total_amount),
                        'started_by' => $user_info ? $user_info->display_name : 'Unknown User'
                    ];
                    
                    $telegram->sendProductionStartedNotification($productionData);
                    
                } catch (Exception $e) {
                    error_log("Telegram production started notification failed: " . $e->getMessage());
                }
            }
            // END TELEGRAM NOTIFICATION
            
        } elseif ($action === 'complete') {
            $new_status = 'produced';
            $workflow_action = 'complete_production';
            
            $db->query("UPDATE credit_orders SET status = ? WHERE id = ?", [$new_status, $order_id]);
            $db->query("UPDATE production_schedule SET production_completed_at = NOW(), status = 'completed' WHERE order_id = ?", [$order_id]);
            
            $success = "Production completed for order " . $order->order_number;
            
            // ============================================
            // TELEGRAM NOTIFICATION - PRODUCTION COMPLETED
            // ============================================
            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                try {
                    require_once '../core/classes/TelegramNotifier.php';
                    $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                    
                    // Get customer and branch details
                    $customer_info = $db->query("SELECT name, phone_number FROM customers WHERE id = ?", [$order->customer_id])->first();
                    $branch_info = $db->query("SELECT name FROM branches WHERE id = ?", [$order->assigned_branch_id])->first();
                    
                    // Get user name
                    $user_info = $db->query("SELECT display_name FROM users WHERE id = ?", [$user_id])->first();
                    
                    // Get production schedule for duration
                    $schedule = $db->query("SELECT production_started_at, production_completed_at FROM production_schedule WHERE order_id = ?", [$order_id])->first();
                    $duration = '';
                    if ($schedule && $schedule->production_started_at) {
                        $start = new DateTime($schedule->production_started_at);
                        $end = new DateTime();
                        $interval = $start->diff($end);
                        
                        if ($interval->d > 0) {
                            $duration = $interval->d . ' day(s) ' . $interval->h . ' hour(s)';
                        } elseif ($interval->h > 0) {
                            $duration = $interval->h . ' hour(s) ' . $interval->i . ' min(s)';
                        } else {
                            $duration = $interval->i . ' minute(s)';
                        }
                    }
                    
                    // Get order items
                    $items = $db->query(
                        "SELECT coi.*, p.base_name as product_name, pv.grade, pv.weight_variant, pv.unit_of_measure
                         FROM credit_order_items coi
                         JOIN products p ON coi.product_id = p.id
                         LEFT JOIN product_variants pv ON coi.variant_id = pv.id
                         WHERE coi.order_id = ?",
                        [$order_id]
                    )->results();
                    
                    $notification_items = [];
                    foreach ($items as $item) {
                        $variant_name = trim(($item->grade ?? '') . ' ' . ($item->weight_variant ?? ''));
                        $notification_items[] = [
                            'product_name' => $item->product_name,
                            'variant_name' => $variant_name,
                            'quantity' => floatval($item->quantity),
                            'unit' => $item->unit_of_measure ?? 'pcs'
                        ];
                    }
                    
                    $productionData = [
                        'order_number' => $order->order_number,
                        'completed_at' => date('d M Y, h:i A'),
                        'customer_name' => $customer_info ? $customer_info->name : 'Unknown',
                        'customer_phone' => $customer_info ? $customer_info->phone_number : 'N/A',
                        'branch_name' => $branch_info ? $branch_info->name : 'Unknown Branch',
                        'required_date' => date('d M Y', strtotime($order->required_date)),
                        'items' => $notification_items,
                        'total_amount' => floatval($order->total_amount),
                        'duration' => $duration,
                        'completed_by' => $user_info ? $user_info->display_name : 'Unknown User'
                    ];
                    
                    $telegram->sendProductionCompletedNotification($productionData);
                    
                } catch (Exception $e) {
                    error_log("Telegram production completed notification failed: " . $e->getMessage());
                }
            }
            // END TELEGRAM NOTIFICATION
            
        } elseif ($action === 'ready') {
            $new_status = 'ready_to_ship';
            $workflow_action = 'mark_ready_to_ship';
            
            // FIXED: Calculate weight and find consolidation BEFORE and AFTER updating status
            // This avoids MySQL trigger conflict
            
            // 1. Calculate order weight first
            try {
                $db->query("CALL sp_calculate_order_weight(?)", [$order_id]);
            } catch (Exception $e) {
                // Log but don't fail if weight calculation has issues
                error_log("Weight calculation warning for order $order_id: " . $e->getMessage());
            }
            
            // 2. Update status
            $db->query("UPDATE credit_orders SET status = ? WHERE id = ?", [$new_status, $order_id]);
            
            // 3. Find consolidation opportunities after status update
            try {
                $db->query("CALL sp_find_consolidation_opportunities(?)", [$order_id]);
            } catch (Exception $e) {
                // Log but don't fail if consolidation suggestion has issues
                error_log("Consolidation suggestion warning for order $order_id: " . $e->getMessage());
            }
            
            $success = "Order marked as ready to ship: " . $order->order_number;
            
            // ============================================
            // TELEGRAM NOTIFICATION - READY TO SHIP
            // ============================================
            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                try {
                    require_once '../core/classes/TelegramNotifier.php';
                    $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                    
                    // Get customer and branch details
                    $customer_info = $db->query("SELECT name, phone_number FROM customers WHERE id = ?", [$order->customer_id])->first();
                    $branch_info = $db->query("SELECT name FROM branches WHERE id = ?", [$order->assigned_branch_id])->first();
                    
                    // Get user name
                    $user_info = $db->query("SELECT display_name FROM users WHERE id = ?", [$user_id])->first();
                    
                    // Get order items
                    $items = $db->query(
                        "SELECT coi.*, p.base_name as product_name, pv.grade, pv.weight_variant, pv.unit_of_measure
                         FROM credit_order_items coi
                         JOIN products p ON coi.product_id = p.id
                         LEFT JOIN product_variants pv ON coi.variant_id = pv.id
                         WHERE coi.order_id = ?",
                        [$order_id]
                    )->results();
                    
                    $notification_items = [];
                    foreach ($items as $item) {
                        $variant_name = trim(($item->grade ?? '') . ' ' . ($item->weight_variant ?? ''));
                        $notification_items[] = [
                            'product_name' => $item->product_name,
                            'variant_name' => $variant_name,
                            'quantity' => floatval($item->quantity),
                            'unit' => $item->unit_of_measure ?? 'pcs'
                        ];
                    }
                    
                    $shipmentData = [
                        'order_number' => $order->order_number,
                        'ready_at' => date('d M Y, h:i A'),
                        'customer_name' => $customer_info ? $customer_info->name : 'Unknown',
                        'customer_phone' => $customer_info ? $customer_info->phone_number : 'N/A',
                        'shipping_address' => $order->shipping_address ?? '',
                        'branch_name' => $branch_info ? $branch_info->name : 'Unknown Branch',
                        'items' => $notification_items,
                        'total_amount' => floatval($order->total_amount),
                        'balance_due' => floatval($order->balance_due),
                        'marked_by' => $user_info ? $user_info->display_name : 'Unknown User'
                    ];
                    
                    $telegram->sendReadyToShipNotification($shipmentData);
                    
                } catch (Exception $e) {
                    error_log("Telegram ready to ship notification failed: " . $e->getMessage());
                }
            }
            // END TELEGRAM NOTIFICATION
            
        } elseif ($action === 'update_priority') {
            $priority = (int)$_POST['priority'];
            
            // Update or create production schedule
            $schedule_exists = $db->query("SELECT id FROM production_schedule WHERE order_id = ?", [$order_id])->first();
            if ($schedule_exists) {
                $db->query("UPDATE production_schedule SET priority_order = ? WHERE order_id = ?", [$priority, $order_id]);
            } else {
                $db->insert('production_schedule', [
                    'order_id' => $order_id,
                    'priority_order' => $priority
                ]);
            }
            
            $success = "Priority updated";
            
            // ============================================
            // TELEGRAM NOTIFICATION - PRIORITY UPDATED
            // ============================================
            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                try {
                    require_once '../core/classes/TelegramNotifier.php';
                    $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                    
                    // Get customer and branch details
                    $customer_info = $db->query("SELECT name FROM customers WHERE id = ?", [$order->customer_id])->first();
                    $branch_info = $db->query("SELECT name FROM branches WHERE id = ?", [$order->assigned_branch_id])->first();
                    
                    // Get user name
                    $user_info = $db->query("SELECT display_name FROM users WHERE id = ?", [$user_id])->first();
                    
                    $priorityData = [
                        'order_number' => $order->order_number,
                        'new_priority' => $priority,
                        'updated_at' => date('d M Y, h:i A'),
                        'customer_name' => $customer_info ? $customer_info->name : 'Unknown',
                        'branch_name' => $branch_info ? $branch_info->name : 'Unknown Branch',
                        'updated_by' => $user_info ? $user_info->display_name : 'Unknown User'
                    ];
                    
                    $telegram->sendPriorityUpdateNotification($priorityData);
                    
                } catch (Exception $e) {
                    error_log("Telegram priority update notification failed: " . $e->getMessage());
                }
            }
            // END TELEGRAM NOTIFICATION
            
        } else {
            throw new Exception("Invalid action");
        }
        
        // Log workflow (only if status actually changed)
        if ($workflow_action) {
            $db->insert('credit_order_workflow', [
                'order_id' => $order_id,
                'from_status' => $old_status,
                'to_status' => $new_status,
                'action' => $workflow_action,
                'performed_by_user_id' => $user_id,
                'comments' => 'Production status updated'
            ]);
        }
        
        $db->getPdo()->commit();
        $_SESSION['success_flash'] = $success;
        header('Location: credit_production.php');
        exit();
        
    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        $error = $e->getMessage();
        error_log("Production error for order $order_id: " . $e->getMessage());
    }
}

// Pre-fetch all items for displayed orders in one query
$all_items_by_order = [];
if (!empty($orders)) {
    $order_ids    = array_map(fn($o) => (int)$o->id, $orders);
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $all_items_flat = $db->query(
        "SELECT coi.order_id, coi.quantity, coi.unit_price, coi.line_total,
                p.base_name AS product_name,
                pv.grade, pv.weight_variant, pv.unit_of_measure
         FROM credit_order_items coi
         JOIN products p ON coi.product_id = p.id
         LEFT JOIN product_variants pv ON coi.variant_id = pv.id
         WHERE coi.order_id IN ($placeholders)
         ORDER BY coi.order_id, coi.id",
        $order_ids
    )->results();
    foreach ($all_items_flat as $item) {
        $all_items_by_order[$item->order_id][] = $item;
    }
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-4">

<!-- Page Header + Stats -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">
            <?php echo $pageTitle; ?>
            <?php if ($filter_branch_name): ?>
            <span class="ml-2 text-sm font-normal text-blue-600">— <?php echo htmlspecialchars($filter_branch_name); ?></span>
            <?php endif; ?>
        </h1>
        <p class="text-xs text-gray-500 mt-0.5">Prioritized by status → priority # → required date</p>
    </div>
    <div class="flex gap-2 flex-wrap items-center">
        <?php
        try {
            $s_approved    = $db->query("SELECT COUNT(*) as c FROM credit_orders co WHERE co.status='approved'    $branch_filter", $branch_params)->first();
            $s_inprod      = $db->query("SELECT COUNT(*) as c FROM credit_orders co WHERE co.status='in_production' $branch_filter", $branch_params)->first();
            $s_produced    = $db->query("SELECT COUNT(*) as c FROM credit_orders co WHERE co.status='produced'    $branch_filter", $branch_params)->first();
            $s_ready       = $db->query("SELECT COUNT(*) as c FROM credit_orders co WHERE co.status='ready_to_ship' $branch_filter", $branch_params)->first();
            $stats = [
                'approved'      => $s_approved  ? (int)$s_approved->c  : 0,
                'in_production' => $s_inprod    ? (int)$s_inprod->c    : 0,
                'produced'      => $s_produced  ? (int)$s_produced->c  : 0,
                'ready_to_ship' => $s_ready     ? (int)$s_ready->c     : 0,
            ];
        } catch (Exception $e) {
            $stats = ['approved' => 0, 'in_production' => 0, 'produced' => 0, 'ready_to_ship' => 0];
        }
        ?>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-blue-100 text-blue-800 text-xs font-semibold">
            <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
            <?php echo $stats['approved']; ?> Pending
        </span>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-purple-100 text-purple-800 text-xs font-semibold">
            <span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>
            <?php echo $stats['in_production']; ?> In Production
        </span>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-green-100 text-green-800 text-xs font-semibold">
            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
            <?php echo $stats['produced']; ?> Produced
        </span>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-orange-100 text-orange-800 text-xs font-semibold">
            <span class="w-1.5 h-1.5 rounded-full bg-orange-500"></span>
            <?php echo $stats['ready_to_ship']; ?> Ready
        </span>
    </div>
</div>

<?php if ($error): ?>
<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 mb-4 rounded-lg text-sm">
    <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['success_flash'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 mb-4 rounded-lg text-sm">
    <i class="fas fa-check-circle mr-1"></i> <?php echo htmlspecialchars($_SESSION['success_flash']); ?>
</div>
<?php unset($_SESSION['success_flash']); ?>
<?php endif; ?>

<!-- Branch Filter (admin only) -->
<?php if ($is_admin && !empty($all_branches)): ?>
<form method="GET" class="flex flex-wrap items-end gap-2 mb-4 bg-white rounded-lg border border-gray-200 shadow-sm px-4 py-3">
    <div class="flex flex-col gap-0.5">
        <label class="text-xs text-gray-500 font-medium">Factory / Branch</label>
        <select name="branch_id"
                class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 min-w-[180px]">
            <option value="0">All Factories</option>
            <?php foreach ($all_branches as $br): ?>
            <option value="<?php echo $br->id; ?>" <?php echo $filter_branch_id === (int)$br->id ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($br->name); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit"
            class="px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded hover:bg-blue-700 transition-colors cursor-pointer">
        <i class="fas fa-filter mr-1"></i>Apply
    </button>
    <?php if ($filter_branch_id > 0): ?>
    <a href="credit_production.php"
       class="px-3 py-1.5 bg-gray-100 text-gray-600 text-xs rounded hover:bg-gray-200 transition-colors">
        <i class="fas fa-times mr-1"></i>All
    </a>
    <?php endif; ?>
    <span class="ml-auto self-end text-xs text-gray-500 pb-1">
        <strong><?php echo count($orders); ?></strong> orders in queue
    </span>
</form>
<?php endif; ?>

<?php if (!empty($orders)): ?>
<!-- Compact Production Table -->
<div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200 text-left">
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap text-center">#</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Order</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Customer · Branch</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Items</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap text-right">Amount</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Req. Date</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Status</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Timeline</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap text-center">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
        <?php foreach ($orders as $idx => $order):
            $items       = $all_items_by_order[$order->id] ?? [];
            $items_count = count($items);
            $first_item  = !empty($items) ? $items[0]->product_name : '';

            $is_overdue  = $order->required_date &&
                           strtotime($order->required_date) < strtotime('today') &&
                           in_array($order->status, ['approved', 'in_production']);

            $status_cls = [
                'approved'      => 'bg-blue-100 text-blue-800',
                'in_production' => 'bg-purple-100 text-purple-800',
                'produced'      => 'bg-green-100 text-green-800',
                'ready_to_ship' => 'bg-orange-100 text-orange-800',
            ];
            $status_labels = [
                'approved'      => 'Pending',
                'in_production' => 'In Production',
                'produced'      => 'Produced',
                'ready_to_ship' => 'Ready',
            ];
            $priority_val = $order->priority_order ?? ($idx + 1);
        ?>
        <tr class="hover:bg-blue-50/30 transition-colors">
            <!-- Priority # -->
            <td class="px-3 py-2.5 text-center whitespace-nowrap">
                <?php if ($is_admin): ?>
                <form method="POST" class="inline-flex items-center gap-1">
                    <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
                    <input type="number" name="priority" value="<?php echo $priority_val; ?>" min="1"
                           class="w-10 px-1 py-0.5 border border-gray-300 rounded text-xs text-center focus:ring-1 focus:ring-blue-500">
                    <button type="submit" name="action" value="update_priority"
                            class="text-blue-600 hover:text-blue-800 cursor-pointer" title="Save priority">
                        <i class="fas fa-save text-xs"></i>
                    </button>
                </form>
                <?php else: ?>
                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-200 text-gray-700 font-bold text-xs">
                    <?php echo $priority_val; ?>
                </span>
                <?php endif; ?>
            </td>
            <!-- Order # -->
            <td class="px-3 py-2.5 whitespace-nowrap">
                <a href="credit_order_view.php?id=<?php echo $order->id; ?>"
                   class="font-mono font-bold text-blue-700 hover:underline">
                    <?php echo htmlspecialchars($order->order_number); ?>
                </a>
                <div class="text-gray-400 mt-0.5"><?php echo $order->order_date ? date('d M', strtotime($order->order_date)) : '—'; ?></div>
            </td>
            <!-- Customer + Branch -->
            <td class="px-3 py-2.5 max-w-[180px]">
                <div class="font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($order->customer_name); ?></div>
                <div class="text-gray-400 truncate"><?php echo htmlspecialchars($order->customer_phone ?? ''); ?></div>
                <?php if ($order->branch_name): ?>
                <div class="text-gray-400 truncate"><i class="fas fa-building mr-1 text-gray-300"></i><?php echo htmlspecialchars($order->branch_name); ?></div>
                <?php endif; ?>
            </td>
            <!-- Items -->
            <td class="px-3 py-2.5">
                <button type="button"
                        onclick="openItemsModal(<?php echo $order->id; ?>)"
                        class="font-semibold text-blue-600 hover:text-blue-800 underline decoration-dashed cursor-pointer">
                    <?php echo $items_count; ?> item<?php echo $items_count !== 1 ? 's' : ''; ?>
                </button>
                <?php if ($first_item): ?>
                <div class="text-gray-400 truncate max-w-[120px] mt-0.5"><?php echo htmlspecialchars($first_item); ?></div>
                <?php endif; ?>
            </td>
            <!-- Amount -->
            <td class="px-3 py-2.5 text-right whitespace-nowrap font-bold text-gray-900">
                ৳<?php echo number_format($order->total_amount, 0); ?>
            </td>
            <!-- Required Date -->
            <td class="px-3 py-2.5 whitespace-nowrap <?php echo $is_overdue ? 'font-semibold text-red-600' : 'text-gray-600'; ?>">
                <?php if ($order->required_date): ?>
                    <?php echo date('d M', strtotime($order->required_date)); ?>
                    <?php if ($is_overdue): ?>
                        <i class="fas fa-exclamation-triangle text-red-400 ml-1" title="Overdue"></i>
                    <?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <!-- Status -->
            <td class="px-3 py-2.5 whitespace-nowrap">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full font-semibold text-xs <?php echo $status_cls[$order->status] ?? 'bg-gray-100 text-gray-700'; ?>">
                    <?php echo $status_labels[$order->status] ?? ucfirst($order->status); ?>
                </span>
            </td>
            <!-- Timeline -->
            <td class="px-3 py-2.5 whitespace-nowrap text-gray-500 space-y-0.5">
                <?php if ($order->production_started_at): ?>
                <div><i class="fas fa-play-circle text-purple-400 w-3 mr-1"></i><?php echo date('d M g:ia', strtotime($order->production_started_at)); ?></div>
                <?php endif; ?>
                <?php if ($order->production_completed_at): ?>
                <div><i class="fas fa-check-circle text-green-400 w-3 mr-1"></i><?php echo date('d M g:ia', strtotime($order->production_completed_at)); ?></div>
                <?php elseif ($order->scheduled_date && !$order->production_started_at): ?>
                <div><i class="fas fa-calendar text-gray-300 w-3 mr-1"></i><?php echo date('d M', strtotime($order->scheduled_date)); ?></div>
                <?php endif; ?>
                <?php if (!$order->production_started_at && !$order->scheduled_date): ?>
                <span class="text-gray-300">—</span>
                <?php endif; ?>
            </td>
            <!-- Actions -->
            <td class="px-3 py-2.5 text-center whitespace-nowrap">
                <form method="POST" class="inline-flex items-center gap-1">
                    <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
                    <?php if ($order->status === 'approved'): ?>
                    <button type="submit" name="action" value="start"
                            onclick="return confirm('Start production for <?php echo addslashes($order->order_number); ?>?')"
                            class="inline-flex items-center gap-1 px-2.5 py-1 bg-purple-600 text-white rounded hover:bg-purple-700 font-semibold transition-colors cursor-pointer">
                        <i class="fas fa-play text-xs"></i> Start
                    </button>
                    <?php elseif ($order->status === 'in_production'): ?>
                    <button type="submit" name="action" value="complete"
                            onclick="return confirm('Mark <?php echo addslashes($order->order_number); ?> as produced?')"
                            class="inline-flex items-center gap-1 px-2.5 py-1 bg-green-600 text-white rounded hover:bg-green-700 font-semibold transition-colors cursor-pointer">
                        <i class="fas fa-check text-xs"></i> Complete
                    </button>
                    <?php elseif ($order->status === 'produced'): ?>
                    <button type="submit" name="action" value="ready"
                            onclick="return confirm('Mark <?php echo addslashes($order->order_number); ?> as ready to ship?')"
                            class="inline-flex items-center gap-1 px-2.5 py-1 bg-orange-600 text-white rounded hover:bg-orange-700 font-semibold transition-colors cursor-pointer">
                        <i class="fas fa-truck text-xs"></i> Ready
                    </button>
                    <?php elseif ($order->status === 'ready_to_ship'): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-orange-100 text-orange-700 rounded font-medium">
                        <i class="fas fa-check-circle text-xs"></i> Ready
                    </span>
                    <?php endif; ?>
                    <a href="credit_order_view.php?id=<?php echo $order->id; ?>"
                       class="inline-flex items-center px-2 py-1 border border-gray-300 text-gray-600 rounded hover:bg-gray-100 transition-colors" title="View">
                        <i class="fas fa-eye text-xs"></i>
                    </a>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php else: ?>
<div class="bg-white rounded-lg border border-gray-200 shadow-sm p-12 text-center">
    <i class="fas fa-industry text-5xl text-gray-300 mb-4"></i>
    <h3 class="text-base font-semibold text-gray-600 mb-1">No Orders in Production Queue</h3>
    <p class="text-sm text-gray-400">All orders have been completed or no new orders assigned yet.</p>
</div>
<?php endif; ?>

</div><!-- /container -->

<!-- ═══════════════════════════════════════
     ITEMS MODAL
═══════════════════════════════════════ -->
<div id="itemsModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4 max-h-[88vh] flex flex-col"
         onclick="event.stopPropagation()">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between flex-shrink-0">
            <h3 class="font-bold text-gray-900 text-sm" id="itemsModalTitle">Order Items</h3>
            <button type="button" onclick="closeItemsModal()"
                    class="text-gray-400 hover:text-gray-600 cursor-pointer">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="itemsModalBody" class="overflow-y-auto p-5 text-sm flex-1"></div>
    </div>
</div>

<script>
const _prodData = {
<?php foreach ($orders as $idx => $order):
    $oi = $all_items_by_order[$order->id] ?? [];
    $oi_js = json_encode(array_map(fn($i) => [
        'product' => $i->product_name,
        'variant' => trim(($i->grade ?? '') . ' ' . ($i->weight_variant ?? '')),
        'qty'     => (float)$i->quantity,
        'unit'    => $i->unit_of_measure ?? '',
        'price'   => (float)$i->unit_price,
        'total'   => (float)$i->line_total,
    ], $oi), JSON_UNESCAPED_UNICODE);
?>
    <?php echo (int)$order->id; ?>: {
        order_number:  <?php echo json_encode($order->order_number); ?>,
        customer:      <?php echo json_encode($order->customer_name); ?>,
        phone:         <?php echo json_encode($order->customer_phone ?? ''); ?>,
        branch:        <?php echo json_encode($order->branch_name ?? ''); ?>,
        address:       <?php echo json_encode($order->shipping_address ?? ''); ?>,
        instructions:  <?php echo json_encode($order->special_instructions ?? ''); ?>,
        required_date: <?php echo json_encode($order->required_date ? date('d M Y', strtotime($order->required_date)) : ''); ?>,
        started_at:    <?php echo json_encode($order->production_started_at   ? date('d M Y, g:i A', strtotime($order->production_started_at))   : ''); ?>,
        completed_at:  <?php echo json_encode($order->production_completed_at ? date('d M Y, g:i A', strtotime($order->production_completed_at)) : ''); ?>,
        total:         <?php echo (float)$order->total_amount; ?>,
        items:         <?php echo $oi_js; ?>
    },
<?php endforeach; ?>
};

function openItemsModal(orderId) {
    const d = _prodData[orderId];
    if (!d) return;
    document.getElementById('itemsModalTitle').textContent = d.order_number + ' — Production Items';

    let html = `<div class="flex items-start justify-between mb-3">
        <div>
            <div class="font-semibold text-gray-900">${d.customer}</div>
            <div class="text-xs text-gray-500">${d.branch}${d.phone ? ' · ' + d.phone : ''}</div>
        </div>
        <a href="credit_order_view.php?id=${orderId}" class="text-xs text-blue-600 hover:underline ml-4 flex-shrink-0">View Order</a>
    </div>`;
    if (d.required_date) {
        html += `<div class="mb-3 text-xs text-gray-500"><i class="fas fa-calendar mr-1 text-gray-400"></i>Required by: <strong>${d.required_date}</strong></div>`;
    }
    if (d.instructions) {
        html += `<div class="mb-3 text-xs bg-blue-50 border border-blue-100 text-blue-800 px-3 py-2 rounded">
                     <i class="fas fa-info-circle mr-1"></i>${d.instructions}</div>`;
    }

    html += `<table class="w-full border-collapse mb-4 text-xs">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200">
                <th class="py-1.5 px-2 text-left font-semibold text-gray-500">Product</th>
                <th class="py-1.5 px-2 text-right font-semibold text-gray-500">Qty</th>
                <th class="py-1.5 px-2 text-right font-semibold text-gray-500">Price</th>
                <th class="py-1.5 px-2 text-right font-semibold text-gray-500">Total</th>
            </tr>
        </thead><tbody>`;
    d.items.forEach(item => {
        html += `<tr class="border-b border-gray-100">
            <td class="py-2 px-2">
                <div class="font-medium text-gray-900">${item.product}</div>
                ${item.variant ? `<div class="text-gray-400">${item.variant}</div>` : ''}
            </td>
            <td class="py-2 px-2 text-right text-gray-700 font-semibold">${item.qty} <span class="text-gray-400 font-normal">${item.unit}</span></td>
            <td class="py-2 px-2 text-right text-gray-600">৳${Number(item.price).toLocaleString()}</td>
            <td class="py-2 px-2 text-right font-semibold text-gray-900">৳${Number(item.total).toLocaleString()}</td>
        </tr>`;
    });
    html += `</tbody><tfoot>
        <tr class="border-t-2 border-gray-300 bg-gray-50">
            <td colspan="3" class="py-2 px-2 text-right font-bold text-gray-700">Total</td>
            <td class="py-2 px-2 text-right font-bold text-blue-700">৳${Number(d.total).toLocaleString()}</td>
        </tr>
    </tfoot></table>`;

    if (d.started_at || d.completed_at) {
        html += `<div class="bg-gray-50 rounded-lg p-3 text-xs border border-gray-200 space-y-1">`;
        if (d.started_at)   html += `<div><i class="fas fa-play-circle text-purple-400 w-4 mr-1"></i>Started: ${d.started_at}</div>`;
        if (d.completed_at) html += `<div><i class="fas fa-check-circle text-green-500 w-4 mr-1"></i>Completed: ${d.completed_at}</div>`;
        html += `</div>`;
    }

    document.getElementById('itemsModalBody').innerHTML = html;
    document.getElementById('itemsModal').classList.remove('hidden');
}
function closeItemsModal() { document.getElementById('itemsModal').classList.add('hidden'); }

document.getElementById('itemsModal').addEventListener('click', function(e) {
    if (e.target === this) closeItemsModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeItemsModal();
});
</script>

<?php require_once '../templates/footer.php'; ?>
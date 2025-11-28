<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Credit Order Approval';
$error = null;
$success = null;

// Check if user can approve high amounts (admin only)
$is_admin = in_array($user_role, ['Superadmin', 'admin']);

// Get branches for assignment
$branches = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name")->results();

// Get pending and escalated orders
$status_filter = $is_admin ? "('pending_approval', 'escalated')" : "('pending_approval')";

$orders = $db->query(
    "SELECT co.*, 
           c.name as customer_name,
           c.phone_number as customer_phone,
           c.credit_limit,
           c.current_balance,
           (c.credit_limit - c.current_balance) as available_credit,
           u.display_name as created_by_name,
           ROUND((co.balance_due / NULLIF((c.credit_limit - c.current_balance), 0)) * 100, 2) as credit_usage_percent
     FROM credit_orders co
     JOIN customers c ON co.customer_id = c.id
     LEFT JOIN users u ON co.created_by_user_id = u.id
     WHERE co.status IN $status_filter
     ORDER BY 
        CASE co.status 
            WHEN 'escalated' THEN 1
            WHEN 'pending_approval' THEN 2
        END,
        co.order_date ASC"
)->results();

// Handle approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'approve') {
    $order_id = (int)$_POST['order_id'];
    $comments = trim($_POST['comments']);
    $branch_id = (int)$_POST['branch_id'];
    
    if (!$branch_id) {
        $error = "Please select a branch for production";
    } else {
        try {
            $db->getPdo()->beginTransaction();
            
            $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$order_id])->first();
            if (!$order) throw new Exception("Order not found");
            
            $customer = $db->query("SELECT credit_limit, current_balance FROM customers WHERE id = ?", [$order->customer_id])->first();
            if (!$customer) throw new Exception("Customer not found");
            
            $available_credit = $customer->credit_limit - $customer->current_balance;
            $usage_percent = $available_credit > 0 ? ($order->balance_due / $available_credit) * 100 : 0;
            
            // Allow order even if no credit limit set
            if ($customer->credit_limit == 0) {
                $usage_percent = 0;
            }
            
            // Determine new status based on role and usage
            if ($usage_percent >= 80 && !$is_admin) {
                // Escalate to admin
                $new_status = 'escalated';
                $action = 'escalated to admin';
            } else {
                // Approve
                $new_status = 'approved';
                $action = 'approved';
            }
            
            // Update order status and assign branch
            // Update order status, assign branch, and set required date
            $required_date = $_POST['required_date'];
            $db->query(
                "UPDATE credit_orders SET status = ?, assigned_branch_id = ?, required_date = ? WHERE id = ?", 
                [$new_status, $branch_id, $required_date, $order_id]
            );
            
            // Log workflow
            $db->insert('credit_order_workflow', [
                'order_id' => $order_id,
                'from_status' => $order->status,
                'to_status' => $new_status,
                'action' => $action,
                'performed_by_user_id' => $user_id,
                'comments' => $comments ?: "Order $action"
            ]);
            
            $db->getPdo()->commit();
            $_SESSION['success_flash'] = "Order $action successfully";
            header('Location: credit_order_approval.php');
            exit();
            
        } catch (Exception $e) {
            if ($db->getPdo()->inTransaction()) {
                $db->getPdo()->rollBack();
            }
            $error = "Failed to approve order: " . $e->getMessage();
        }
    }
}

// Handle rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'reject') {
    $order_id = (int)$_POST['order_id'];
    $comments = trim($_POST['reject_reason']);
    
    try {
        $db->getPdo()->beginTransaction();
        
        $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$order_id])->first();
        if (!$order) throw new Exception("Order not found");
        
        // Update order status
        $db->query("UPDATE credit_orders SET status = 'rejected' WHERE id = ?", [$order_id]);
        
        // Log workflow
        $db->insert('credit_order_workflow', [
            'order_id' => $order_id,
            'from_status' => $order->status,
            'to_status' => 'rejected',
            'action' => 'reject',
            'performed_by_user_id' => $user_id,
            'comments' => $comments ?: 'Order rejected'
        ]);
        
        $db->getPdo()->commit();
        $_SESSION['success_flash'] = "Order rejected";
        header('Location: credit_order_approval.php');
        exit();
        
    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        $error = "Failed to reject order: " . $e->getMessage();
    }
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">Review and approve credit orders</p>
</div>

<?php if ($error): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Error</p>
    <p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<!-- Statistics -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <?php
    $stats = [
        'pending' => $db->query("SELECT COUNT(*) as c FROM credit_orders WHERE status = 'pending_approval'")->first()->c,
        'escalated' => $db->query("SELECT COUNT(*) as c FROM credit_orders WHERE status = 'escalated'")->first()->c,
        'approved_today' => $db->query("SELECT COUNT(*) as c FROM credit_orders WHERE status = 'approved' AND DATE(updated_at) = CURDATE()")->first()->c
    ];
    ?>
    <div class="bg-orange-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Pending Approval</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['pending']; ?></p>
    </div>
    <div class="bg-red-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Escalated (>80%)</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['escalated']; ?></p>
    </div>
    <div class="bg-green-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Approved Today</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['approved_today']; ?></p>
    </div>
</div>

<!-- Orders List -->
<?php if (!empty($orders)): ?>
<?php foreach ($orders as $order): 
    $status_colors = [
        'pending_approval' => 'orange',
        'escalated' => 'red'
    ];
    $color = $status_colors[$order->status] ?? 'gray';
    
    $usage_color = 'blue';
    if ($order->credit_usage_percent >= 80) $usage_color = 'red';
    elseif ($order->credit_usage_percent >= 60) $usage_color = 'yellow';
?>
<div class="bg-white rounded-lg shadow-md mb-6 overflow-hidden">
    <!-- Order Header -->
    <div class="p-6 border-b border-gray-200 bg-gray-50">
        <div class="flex justify-between items-start">
            <div>
                <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($order->order_number); ?></h3>
                <span class="inline-block mt-2 px-3 py-1 text-xs font-semibold rounded-full bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-800">
                    <?php echo ucwords(str_replace('_', ' ', $order->status)); ?>
                </span>
                <?php if ($order->status === 'escalated'): ?>
                <span class="inline-block mt-2 ml-2 px-3 py-1 text-xs font-semibold rounded-full bg-purple-100 text-purple-800">
                    <i class="fas fa-exclamation-triangle"></i> Requires Admin Approval
                </span>
                <?php endif; ?>
            </div>
            <div class="text-right">
                <p class="text-2xl font-bold text-blue-600">৳<?php echo number_format($order->total_amount, 2); ?></p>
                <p class="text-sm text-gray-600">
                    Balance Due: ৳<?php echo number_format($order->balance_due, 2); ?>
                </p>
                <p class="text-xs text-gray-500 mt-1">
                    Created: <?php echo date('M j, Y g:i A', strtotime($order->order_date)); ?>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Customer & Credit Info -->
    <div class="p-6 border-b border-gray-200">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h4 class="font-semibold text-gray-800 mb-2">Customer Information</h4>
                <p class="text-sm"><strong>Name:</strong> <?php echo htmlspecialchars($order->customer_name); ?></p>
                <p class="text-sm"><strong>Phone:</strong> <?php echo htmlspecialchars($order->customer_phone); ?></p>
                <p class="text-sm"><strong>Delivery Address:</strong></p>
                <p class="text-sm text-gray-700 ml-2"><?php echo htmlspecialchars($order->shipping_address); ?></p>
                <?php if ($order->special_instructions): ?>
                <p class="text-sm mt-2 p-2 bg-blue-50 border border-blue-200 rounded">
                    <i class="fas fa-info-circle text-blue-600 mr-1"></i>
                    <?php echo htmlspecialchars($order->special_instructions); ?>
                </p>
                <?php endif; ?>
            </div>
            <div>
                <h4 class="font-semibold text-gray-800 mb-2">Credit Analysis</h4>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Credit Limit:</span>
                        <span class="font-bold">৳<?php echo number_format($order->credit_limit, 0); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Current Balance:</span>
                        <span class="font-bold text-orange-600">৳<?php echo number_format($order->current_balance, 0); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Available Credit:</span>
                        <span class="font-bold text-green-600">৳<?php echo number_format($order->available_credit, 0); ?></span>
                    </div>
                    <div class="flex justify-between pt-2 border-t">
                        <span class="text-gray-600">Order Amount:</span>
                        <span class="font-bold text-blue-600">৳<?php echo number_format($order->balance_due, 0); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Credit Usage:</span>
                        <span class="font-bold text-<?php echo $usage_color; ?>-600">
                            <?php echo number_format($order->credit_usage_percent, 1); ?>%
                        </span>
                    </div>
                </div>
                
                <?php if ($order->credit_usage_percent >= 80): ?>
                <div class="mt-3 p-3 bg-red-50 border border-red-200 rounded">
                    <p class="text-sm text-red-700 font-semibold">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        High Credit Usage - Requires Admin Approval
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Order Items -->
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
                    <?php
                    // Get order items
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
                    
                    foreach ($items as $item):
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
                        <td colspan="4" class="px-4 py-2 text-right font-semibold">Subtotal:</td>
                        <td class="px-4 py-2 text-right font-bold">৳<?php echo number_format($order->subtotal, 2); ?></td>
                    </tr>
                    <?php if ($order->discount_amount > 0): ?>
                    <tr>
                        <td colspan="4" class="px-4 py-2 text-right font-semibold">Discount:</td>
                        <td class="px-4 py-2 text-right font-bold text-red-600">-৳<?php echo number_format($order->discount_amount, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($order->advance_paid > 0): ?>
                    <tr>
                        <td colspan="4" class="px-4 py-2 text-right font-semibold">Advance Paid:</td>
                        <td class="px-4 py-2 text-right font-bold text-green-600">-৳<?php echo number_format($order->advance_paid, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="bg-blue-50">
                        <td colspan="4" class="px-4 py-2 text-right font-semibold text-lg">Balance Due:</td>
                        <td class="px-4 py-2 text-right font-bold text-blue-600 text-lg">৳<?php echo number_format($order->balance_due, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    
    <!-- Actions -->
    <div class="p-6 bg-gray-50">
        <?php
        // Can approve if: admin OR (accounts and usage < 80%)
        $can_approve = $is_admin || ($order->credit_usage_percent < 80);
        ?>
        
        <?php if ($can_approve): ?>
        <h4 class="font-semibold text-gray-800 mb-4">Approval Decision</h4>
        
        <!-- Approve Form -->
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
            
            <!-- Branch Assignment -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Assign to Branch for Production *</label>
                <select name="branch_id" required class="w-full px-4 py-2 border rounded-lg">
                    <option value="">-- Select Branch --</option>
                    <?php foreach ($branches as $branch): ?>
                    <option value="<?php echo $branch->id; ?>"><?php echo htmlspecialchars($branch->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Required Delivery Date *</label>
                <input type="date" name="required_date" required class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo $order->required_date; ?>"
                       min="<?php echo date('Y-m-d'); ?>">
                <p class="text-xs text-gray-500 mt-1">Current: <?php echo date('M j, Y', strtotime($order->required_date)); ?> - Adjust if needed</p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Comments (Optional)</label>
                <textarea name="comments" rows="2" class="w-full px-4 py-2 border rounded-lg" 
                          placeholder="Add any comments about this approval..."></textarea>
            </div>
            
            <div class="flex gap-3">
                <button type="submit" 
                        onclick="return confirm('Approve this order and assign to production?');"
                        class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-check mr-2"></i>
                    <?php echo $order->credit_usage_percent >= 80 ? 'Approve (Admin Override)' : 'Approve Order'; ?>
                </button>
                <button type="button" 
                        onclick="showRejectForm(<?php echo $order->id; ?>)"
                        class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                    <i class="fas fa-times mr-2"></i>Reject Order
                </button>
            </div>
        </form>
        
        <!-- Reject Form (Hidden) -->
        <form method="POST" id="rejectForm<?php echo $order->id; ?>" class="hidden mt-4 p-4 bg-red-50 border border-red-200 rounded">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
            
            <label class="block text-sm font-medium text-gray-700 mb-2">Rejection Reason *</label>
            <textarea name="reject_reason" rows="3" required class="w-full px-4 py-2 border rounded-lg" 
                      placeholder="Please provide reason for rejection..."></textarea>
            
            <div class="flex gap-3 mt-3">
                <button type="submit" 
                        onclick="return confirm('Are you sure you want to reject this order?');"
                        class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                    Confirm Rejection
                </button>
                <button type="button" 
                        onclick="hideRejectForm(<?php echo $order->id; ?>)"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
            </div>
        </form>
        
        <?php else: ?>
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <p class="text-yellow-800">
                <i class="fas fa-info-circle mr-2"></i>
                This order requires admin approval due to high credit usage (≥80%). It has been escalated automatically.
            </p>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php else: ?>
<div class="bg-white rounded-lg shadow-md p-12 text-center">
    <i class="fas fa-check-circle text-6xl text-green-400 mb-4"></i>
    <h3 class="text-xl font-semibold text-gray-700 mb-2">All Caught Up!</h3>
    <p class="text-gray-600">No orders pending approval at this time.</p>
</div>
<?php endif; ?>

</div>

<script>
function showRejectForm(orderId) {
    document.getElementById('rejectForm' + orderId).classList.remove('hidden');
}

function hideRejectForm(orderId) {
    document.getElementById('rejectForm' + orderId).classList.add('hidden');
}
</script>

<?php require_once '../templates/footer.php'; ?>
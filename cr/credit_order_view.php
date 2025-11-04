<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'sales-srg', 'sales-demra', 'sales-other', 'production manager-srg', 'production manager-demra', 
                  'dispatcher-srg', 'dispatcher-demra', 'dispatchpos-srg', 'dispatchpos-demra'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$pageTitle = 'Order Details';

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$order_id) {
    header('Location: credit_dashboard.php');
    exit();
}

// Get order details
$order = $db->query(
    "SELECT co.*, 
            c.name as customer_name,
            c.phone_number as customer_phone,
            c.email as customer_email,
            c.credit_limit,
            c.current_balance,
            (c.credit_limit - c.current_balance) as available_credit,
            b.name as branch_name,
            u.display_name as created_by_name,
            ps.scheduled_date,
            ps.production_started_at,
            ps.production_completed_at,
            cos.truck_number,
            cos.driver_name,
            cos.driver_contact,
            cos.shipped_date,
            cos.delivered_date
     FROM credit_orders co
     JOIN customers c ON co.customer_id = c.id
     LEFT JOIN branches b ON co.assigned_branch_id = b.id
     LEFT JOIN users u ON co.created_by_user_id = u.id
     LEFT JOIN production_schedule ps ON co.id = ps.order_id
     LEFT JOIN credit_order_shipping cos ON co.id = cos.order_id
     WHERE co.id = ?",
    [$order_id]
)->first();

if (!$order) {
    $_SESSION['error_flash'] = "Order not found";
    header('Location: credit_dashboard.php');
    exit();
}

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
    [$order_id]
)->results();

// Get workflow history
$workflow = $db->query(
    "SELECT cow.*, u.display_name as performed_by_name
     FROM credit_order_workflow cow
     LEFT JOIN users u ON cow.performed_by_user_id = u.id
     WHERE cow.order_id = ?
     ORDER BY cow.created_at DESC",
    [$order_id]
)->results();

require_once '../templates/header.php';

$status_colors = [
    'pending_approval' => 'orange',
    'escalated' => 'red',
    'approved' => 'blue',
    'rejected' => 'gray',
    'in_production' => 'purple',
    'produced' => 'indigo',
    'ready_to_ship' => 'teal',
    'shipped' => 'cyan',
    'delivered' => 'green'
];
$color = $status_colors[$order->status] ?? 'gray';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Header -->
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Order #<?php echo htmlspecialchars($order->order_number); ?></h1>
        <p class="text-lg text-gray-600 mt-1">Complete order details and history</p>
    </div>
    <div class="flex gap-3">
        <a href="credit_dashboard.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
        </a>
        <button onclick="window.print()" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
            <i class="fas fa-print mr-2"></i>Print
        </button>
    </div>
</div>

<!-- Status Badge -->
<div class="mb-6">
    <span class="inline-block px-4 py-2 text-sm font-semibold rounded-full bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-800">
        <i class="fas fa-circle mr-2"></i><?php echo ucwords(str_replace('_', ' ', $order->status)); ?>
    </span>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Main Content -->
    <div class="lg:col-span-2 space-y-6">
        
        <!-- Order Summary -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Order Summary</h2>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="text-gray-600">Order Number</p>
                    <p class="font-bold text-lg"><?php echo htmlspecialchars($order->order_number); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Order Date</p>
                    <p class="font-bold"><?php echo date('M j, Y', strtotime($order->order_date)); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Required Date</p>
                    <p class="font-bold"><?php echo date('M j, Y', strtotime($order->required_date)); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Order Type</p>
                    <p class="font-bold"><?php echo ucwords(str_replace('_', ' ', $order->order_type)); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Assigned Branch</p>
                    <p class="font-bold"><?php echo $order->branch_name ? htmlspecialchars($order->branch_name) : 'Not assigned'; ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Created By</p>
                    <p class="font-bold"><?php echo htmlspecialchars($order->created_by_name); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Customer Information -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Customer Information</h2>
            <div class="space-y-3 text-sm">
                <div>
                    <p class="text-gray-600">Customer Name</p>
                    <p class="font-bold text-lg"><?php echo htmlspecialchars($order->customer_name); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Phone</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->customer_phone); ?></p>
                </div>
                <?php if ($order->customer_email): ?>
                <div>
                    <p class="text-gray-600">Email</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->customer_email); ?></p>
                </div>
                <?php endif; ?>
                <div>
                    <p class="text-gray-600">Shipping Address</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->shipping_address); ?></p>
                </div>
                <?php if ($order->special_instructions): ?>
                <div class="p-3 bg-blue-50 border border-blue-200 rounded">
                    <p class="text-gray-600 text-xs mb-1">Special Instructions</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->special_instructions); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Order Items -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Order Items</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Variant</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($items as $item): 
                            $variant_display = [];
                            if ($item->grade) $variant_display[] = $item->grade;
                            if ($item->weight_variant) $variant_display[] = $item->weight_variant;
                        ?>
                        <tr>
                            <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars($item->product_name); ?></td>
                            <td class="px-4 py-3 text-sm">
                                <?php echo htmlspecialchars(implode(' - ', $variant_display)); ?>
                                <?php if ($item->variant_sku): ?>
                                    <span class="text-xs text-gray-500">(<?php echo htmlspecialchars($item->variant_sku); ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-right">
                                <?php echo $item->quantity; ?> <?php echo $item->unit_of_measure; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-right">৳<?php echo number_format($item->unit_price, 2); ?></td>
                            <td class="px-4 py-3 text-sm text-right font-medium">৳<?php echo number_format($item->line_total, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-right font-semibold">Subtotal:</td>
                            <td class="px-4 py-3 text-right font-bold">৳<?php echo number_format($order->subtotal, 2); ?></td>
                        </tr>
                        <?php if ($order->discount_amount > 0): ?>
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-right font-semibold">Discount:</td>
                            <td class="px-4 py-3 text-right font-bold text-red-600">-৳<?php echo number_format($order->discount_amount, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($order->tax_amount > 0): ?>
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-right font-semibold">Tax:</td>
                            <td class="px-4 py-3 text-right font-bold">৳<?php echo number_format($order->tax_amount, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="bg-blue-50">
                            <td colspan="4" class="px-4 py-3 text-right font-semibold text-lg">Total:</td>
                            <td class="px-4 py-3 text-right font-bold text-blue-600 text-lg">৳<?php echo number_format($order->total_amount, 2); ?></td>
                        </tr>
                        <?php if ($order->advance_paid > 0): ?>
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-right font-semibold">Advance Paid:</td>
                            <td class="px-4 py-3 text-right font-bold text-green-600">-৳<?php echo number_format($order->advance_paid, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="bg-green-50">
                            <td colspan="4" class="px-4 py-3 text-right font-semibold text-lg">Balance Due:</td>
                            <td class="px-4 py-3 text-right font-bold text-green-600 text-lg">৳<?php echo number_format($order->balance_due, 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        
        <!-- Workflow History -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Order History</h2>
            <div class="space-y-3">
                <?php foreach ($workflow as $entry): ?>
                <div class="flex gap-4 p-3 bg-gray-50 rounded-lg">
                    <div class="flex-shrink-0">
                        <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center">
                            <i class="fas fa-arrow-right text-blue-600"></i>
                        </div>
                    </div>
                    <div class="flex-1">
                        <p class="font-semibold text-sm">
                            <?php echo ucwords(str_replace('_', ' ', $entry->action)); ?>
                        </p>
                        <p class="text-xs text-gray-600">
                            <?php echo ucwords(str_replace('_', ' ', $entry->from_status)); ?> 
                            → 
                            <?php echo ucwords(str_replace('_', ' ', $entry->to_status)); ?>
                        </p>
                        <?php if ($entry->comments): ?>
                        <p class="text-sm text-gray-700 mt-1"><?php echo htmlspecialchars($entry->comments); ?></p>
                        <?php endif; ?>
                        <p class="text-xs text-gray-500 mt-1">
                            By <?php echo htmlspecialchars($entry->performed_by_name); ?> 
                            on <?php echo date('M j, Y g:i A', strtotime($entry->created_at)); ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Sidebar -->
    <div class="space-y-6">
        
        <!-- Credit Information -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Credit Information</h3>
            <div class="space-y-3 text-sm">
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
                    <span class="text-gray-600">This Order:</span>
                    <span class="font-bold text-blue-600">৳<?php echo number_format($order->balance_due, 0); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600">Usage:</span>
                    <span class="font-bold">
                        <?php 
                        $usage = $order->available_credit > 0 ? ($order->balance_due / $order->available_credit) * 100 : 0;
                        echo number_format($usage, 1); 
                        ?>%
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Production Info -->
        <?php if (in_array($order->status, ['in_production', 'produced', 'ready_to_ship', 'shipped', 'delivered'])): ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Production Details</h3>
            <div class="space-y-2 text-sm">
                <?php if ($order->scheduled_date): ?>
                <div>
                    <p class="text-gray-600">Scheduled Date</p>
                    <p class="font-bold"><?php echo date('M j, Y', strtotime($order->scheduled_date)); ?></p>
                </div>
                <?php endif; ?>
                <?php if ($order->production_started_at): ?>
                <div>
                    <p class="text-gray-600">Started</p>
                    <p class="font-bold"><?php echo date('M j, Y g:i A', strtotime($order->production_started_at)); ?></p>
                </div>
                <?php endif; ?>
                <?php if ($order->production_completed_at): ?>
                <div>
                    <p class="text-gray-600">Completed</p>
                    <p class="font-bold"><?php echo date('M j, Y g:i A', strtotime($order->production_completed_at)); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Shipping Info -->
        <?php if (in_array($order->status, ['shipped', 'delivered'])): ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Shipping Details</h3>
            <div class="space-y-2 text-sm">
                <div>
                    <p class="text-gray-600">Truck Number</p>
                    <p class="font-bold"><?php echo htmlspecialchars($order->truck_number); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Driver</p>
                    <p class="font-bold"><?php echo htmlspecialchars($order->driver_name); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Contact</p>
                    <p class="font-bold"><?php echo htmlspecialchars($order->driver_contact); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Shipped Date</p>
                    <p class="font-bold"><?php echo date('M j, Y g:i A', strtotime($order->shipped_date)); ?></p>
                </div>
                <?php if ($order->delivered_date): ?>
                <div>
                    <p class="text-gray-600">Delivered Date</p>
                    <p class="font-bold text-green-600"><?php echo date('M j, Y g:i A', strtotime($order->delivered_date)); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Quick Actions -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Quick Actions</h3>
            <div class="space-y-2">
                <a href="customer_ledger.php?customer_id=<?php echo $order->customer_id; ?>" 
                   class="block px-4 py-2 text-sm text-center bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-book mr-2"></i>View Customer Ledger
                </a>
                <?php if (in_array($order->status, ['shipped', 'delivered'])): ?>
                <a href="credit_invoice_print.php?id=<?php echo $order->id; ?>" target="_blank"
                   class="block px-4 py-2 text-sm text-center bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                    <i class="fas fa-print mr-2"></i>Print Invoice
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</div>

<style media="print">
@media print {
    .no-print { display: none !important; }
    body { font-size: 12px; }
    .shadow-md { box-shadow: none !important; }
}
</style>

<?php require_once '../templates/footer.php'; ?>
<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'sales-srg', 'sales-demra', 'sales-other', 'production manager-srg', 'production manager-demra',
                  'dispatch-srg', 'dispatch-demra', 'dispatchpos-srg', 'dispatchpos-demra'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$pageTitle = 'Order Details';

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$order_id) {
    header('Location: index.php');
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
            c.initial_due,
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
    header('Location: index.php');
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
     ORDER BY cow.created_at ASC",
    [$order_id]
)->results();

// Get returns for this order
$returns = [];
try {
    $returns = $db->query(
        "SELECT r.*, u.display_name AS created_by_name, au.display_name AS approved_by_name
         FROM credit_order_returns r
         LEFT JOIN users u  ON r.created_by_user_id  = u.id
         LEFT JOIN users au ON r.approved_by_user_id = au.id
         WHERE r.order_id = ?
         ORDER BY r.created_at ASC",
        [$order_id]
    )->results();
} catch (Exception $e) {}

// Get deliveries for this order
$deliveries = [];
try {
    $deliveries = $db->query(
        "SELECT d.*, u.display_name AS delivered_by_name
         FROM credit_order_deliveries d
         LEFT JOIN users u ON d.created_by_user_id = u.id
         WHERE d.order_id = ?
         ORDER BY d.delivery_date ASC, d.id ASC",
        [$order_id]
    )->results();
} catch (Exception $e) {}

// Payment history is accessible via customer ledger link
$payments = [];

$is_admin = in_array($currentUser['role'] ?? '', ['Superadmin', 'admin']);

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
        <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
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
                    <p class="font-bold"><?php echo htmlspecialchars($order->created_by_name ?? '—'); ?></p>
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
        
        <!-- Workflow History / Timeline -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4"><i class="fas fa-history mr-2 text-blue-500"></i>Order Timeline</h2>
            <?php if (empty($workflow)): ?>
            <div class="text-center py-6 text-gray-400">
                <i class="fas fa-clock text-3xl mb-2"></i>
                <p class="text-sm">No workflow history yet. Actions will appear here as the order progresses.</p>
            </div>
            <?php else: ?>
            <div class="relative">
                <div class="absolute left-5 top-0 bottom-0 w-0.5 bg-blue-100"></div>
                <div class="space-y-4">
                <?php
                $wf_icons = [
                    'create'   => ['bg'=>'bg-blue-100','ic'=>'fas fa-plus text-blue-600'],
                    'approve'  => ['bg'=>'bg-green-100','ic'=>'fas fa-check text-green-600'],
                    'reject'   => ['bg'=>'bg-red-100','ic'=>'fas fa-times text-red-600'],
                    'produce'  => ['bg'=>'bg-purple-100','ic'=>'fas fa-industry text-purple-600'],
                    'ship'     => ['bg'=>'bg-teal-100','ic'=>'fas fa-truck text-teal-600'],
                    'deliver'  => ['bg'=>'bg-green-100','ic'=>'fas fa-box-open text-green-600'],
                    'escalate' => ['bg'=>'bg-orange-100','ic'=>'fas fa-exclamation text-orange-600'],
                    'payment'  => ['bg'=>'bg-emerald-100','ic'=>'fas fa-money-bill text-emerald-600'],
                ];
                foreach ($workflow as $entry):
                    $wi = $wf_icons[$entry->action] ?? ['bg'=>'bg-gray-100','ic'=>'fas fa-arrow-right text-gray-500'];
                ?>
                <div class="flex gap-4 relative">
                    <div class="flex-shrink-0 relative z-10">
                        <div class="w-10 h-10 rounded-full <?php echo $wi['bg']; ?> flex items-center justify-center border-2 border-white shadow-sm">
                            <i class="<?php echo $wi['ic']; ?> text-sm"></i>
                        </div>
                    </div>
                    <div class="flex-1 bg-gray-50 rounded-lg p-3 min-h-[60px]">
                        <div class="flex justify-between items-start">
                            <p class="font-semibold text-sm text-gray-800">
                                <?php echo ucwords(str_replace('_', ' ', $entry->action)); ?>
                            </p>
                            <span class="text-xs text-gray-400 whitespace-nowrap ml-2">
                                <?php echo date('d M Y, H:i', strtotime($entry->created_at)); ?>
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 mt-0.5">
                            <span class="inline-block px-1.5 py-0.5 rounded bg-gray-200 text-gray-700"><?php echo ucwords(str_replace('_', ' ', $entry->from_status)); ?></span>
                            <i class="fas fa-long-arrow-alt-right mx-1"></i>
                            <span class="inline-block px-1.5 py-0.5 rounded bg-blue-100 text-blue-700"><?php echo ucwords(str_replace('_', ' ', $entry->to_status)); ?></span>
                        </p>
                        <?php if ($entry->comments): ?>
                        <p class="text-sm text-gray-600 mt-1 italic"><?php echo htmlspecialchars($entry->comments); ?></p>
                        <?php endif; ?>
                        <p class="text-xs text-gray-400 mt-1">
                            <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($entry->performed_by_name ?? 'System'); ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Deliveries Section -->
        <?php if (!empty($deliveries)): ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4"><i class="fas fa-truck mr-2 text-indigo-500"></i>Delivery History</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Delivery #</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Truck / Driver</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($deliveries as $d): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2 font-medium text-indigo-700"><?php echo htmlspecialchars($d->delivery_number); ?></td>
                            <td class="px-3 py-2 text-gray-600"><?php echo date('d-M-Y', strtotime($d->delivery_date)); ?></td>
                            <td class="px-3 py-2 text-gray-600">
                                <?php echo htmlspecialchars($d->truck_number ?: '—'); ?>
                                <?php if ($d->driver_name): ?><br><span class="text-xs text-gray-400"><?php echo htmlspecialchars($d->driver_name); ?></span><?php endif; ?>
                            </td>
                            <td class="px-3 py-2 text-right"><?php echo number_format($d->total_qty_delivered, 2); ?></td>
                            <td class="px-3 py-2 text-right font-semibold">৳<?php echo number_format($d->total_amount_delivered, 2); ?></td>
                            <td class="px-3 py-2 text-center">
                                <span class="px-2 py-0.5 text-xs rounded-full <?php echo $d->is_final ? 'bg-green-100 text-green-800' : 'bg-indigo-100 text-indigo-800'; ?>">
                                    <?php echo $d->is_final ? 'Final' : 'Partial'; ?>
                                </span>
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-500"><?php echo htmlspecialchars($d->delivered_by_name ?? '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50 border-t-2 border-gray-300">
                        <tr>
                            <td colspan="3" class="px-3 py-2 text-right font-bold text-gray-700">Total Delivered:</td>
                            <td class="px-3 py-2 text-right font-bold"><?php echo number_format(array_sum(array_column((array)$deliveries,'total_qty_delivered')),2); ?></td>
                            <td class="px-3 py-2 text-right font-bold text-indigo-700">৳<?php echo number_format(array_sum(array_column((array)$deliveries,'total_amount_delivered')),2); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Returns Section -->
        <?php if (!empty($returns)): ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4"><i class="fas fa-undo-alt mr-2 text-orange-500"></i>Returns History</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Return #</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Credit Note</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Approved By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($returns as $r):
                            $rsc = ['pending'=>'bg-yellow-100 text-yellow-800','approved'=>'bg-green-100 text-green-800','rejected'=>'bg-red-100 text-red-800'][$r->status] ?? 'bg-gray-100 text-gray-800';
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2 font-medium text-orange-700"><?php echo htmlspecialchars($r->return_number); ?></td>
                            <td class="px-3 py-2 text-gray-600"><?php echo date('d-M-Y', strtotime($r->return_date)); ?></td>
                            <td class="px-3 py-2 text-gray-600 max-w-[180px] truncate" title="<?php echo htmlspecialchars($r->return_reason ?? ''); ?>">
                                <?php echo htmlspecialchars($r->return_reason ?: '—'); ?>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <span class="px-2 py-0.5 text-xs rounded-full <?php echo $r->return_type === 'full' ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700'; ?>">
                                    <?php echo ucfirst($r->return_type ?? ''); ?>
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right font-bold text-orange-700">৳<?php echo number_format($r->total_returned_amount ?? 0, 2); ?></td>
                            <td class="px-3 py-2 text-center">
                                <span class="px-2 py-0.5 text-xs font-bold rounded-full <?php echo $rsc; ?>"><?php echo ucfirst($r->status ?? ''); ?></span>
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-500"><?php echo htmlspecialchars($r->approved_by_name ?? '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50 border-t-2 border-gray-300">
                        <tr>
                            <td colspan="4" class="px-3 py-2 text-right font-bold text-gray-700">Total Returned:</td>
                            <td class="px-3 py-2 text-right font-bold text-orange-700">
                                ৳<?php echo number_format(array_sum(array_filter(array_map(function($r){ return $r->status==='approved' ? (float)$r->total_returned_amount : 0; }, (array)$returns))),2); ?>
                                <div class="text-xs text-gray-400 font-normal">(approved only)</div>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>
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
                <div class="flex justify-between">
                    <span class="text-gray-600">Initial Due:</span>
                    <span class="font-bold text-gray-500">৳<?php echo number_format($order->initial_due ?? 0, 0); ?></span>
                </div>
                <div class="flex justify-between pt-2 border-t">
                    <span class="text-gray-600">This Order Balance:</span>
                    <span class="font-bold text-blue-600">৳<?php echo number_format($order->balance_due, 0); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600">Credit Usage:</span>
                    <span class="font-bold">
                        <?php
                        $credit_used  = max(0, (float)$order->current_balance - (float)($order->initial_due ?? 0));
                        $usage = (float)$order->credit_limit > 0 ? ($credit_used / (float)$order->credit_limit) * 100 : 0;
                        $usage_color  = $usage > 90 ? 'text-red-600' : ($usage > 70 ? 'text-orange-600' : 'text-green-600');
                        echo '<span class="' . $usage_color . '">' . number_format($usage, 1) . '%</span>';
                        ?>
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
                    <p class="font-bold"><?php echo htmlspecialchars($order->truck_number ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Driver</p>
                    <p class="font-bold"><?php echo htmlspecialchars($order->driver_name ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Contact</p>
                    <p class="font-bold"><?php echo htmlspecialchars($order->driver_contact ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Shipped Date</p>
                    <p class="font-bold"><?php echo $order->shipped_date ? date('M j, Y g:i A', strtotime($order->shipped_date)) : '—'; ?></p>
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
                <?php
                $partial_delivery_statuses = ['approved', 'in_production', 'produced', 'ready_to_ship', 'shipped'];
                $can_partial_deliver = in_array($order->status, $partial_delivery_statuses)
                    && in_array($currentUser['role'] ?? '', ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra', 'dispatch-srg', 'dispatch-demra', 'dispatchpos-srg', 'dispatchpos-demra']);
                ?>
                <?php if ($can_partial_deliver): ?>
                <a href="partial_delivery.php?order_id=<?php echo $order->id; ?>"
                   class="block px-4 py-2 text-sm text-center bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                    <i class="fas fa-truck mr-2"></i>Record Delivery
                </a>
                <?php endif; ?>
                <?php if (in_array($order->status, ['shipped', 'delivered'])): ?>
                <a href="returns.php?order_id=<?php echo $order->id; ?>"
                   class="block px-4 py-2 text-sm text-center bg-red-600 text-white rounded-lg hover:bg-red-700">
                    <i class="fas fa-undo mr-2"></i>Record Return
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
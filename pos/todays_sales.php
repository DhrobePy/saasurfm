<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra', 'accountspos-demra', 'accountspos-srg'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = "Today's POS Sales";
$error = null;
$branch_id_to_view = null;
$branch_name = 'All Branches';
$orders = [];
$order_items = [];
$summary = ['total_orders' => 0, 'total_sales' => 0.00, 'total_discount' => 0.00, 'net_sales' => 0.00, 'total_items_sold' => 0, 'payment_methods' => []];

try {
    if (!in_array($user_role, ['Superadmin', 'admin', 'Accounts'])) {
        $employee_info = $db->query("SELECT branch_id, b.name as branch_name FROM employees e JOIN branches b ON e.branch_id = b.id WHERE e.user_id = ?", [$user_id])->first();
        if ($employee_info && $employee_info->branch_id) {
            $branch_id_to_view = $employee_info->branch_id;
            $branch_name = $employee_info->branch_name;
        } else {
            throw new Exception("Your account is not linked to a specific branch.");
        }
    } else {
        $pageTitle .= " (All Branches)";
    }
    if ($branch_id_to_view) {
        $pageTitle .= ' - ' . htmlspecialchars($branch_name);
    }
} catch (Exception $e) {
    $error = "Error determining scope: " . $e->getMessage();
}

if (!$error) {
    try {
        $today_date = date('Y-m-d');
        $params = [$today_date];
        $branch_condition = "";
        if ($branch_id_to_view !== null) {
            $branch_condition = "AND o.branch_id = ?";
            $params[] = $branch_id_to_view;
        }

        $sql = "SELECT o.id, o.order_number, o.order_date, o.total_amount, o.discount_amount, o.payment_method,
                c.name as customer_name, u.display_name as user_name, b.name as branch_name_order
                FROM orders o
                LEFT JOIN customers c ON o.customer_id = c.id
                JOIN users u ON o.created_by_user_id = u.id
                JOIN branches b ON o.branch_id = b.id
                WHERE o.order_type = 'POS' AND DATE(o.order_date) = ? {$branch_condition}
                ORDER BY o.order_date DESC";

        $orders = $db->query($sql, $params)->results();

        if (!empty($orders)) {
            $order_ids = array_map(function($o) { return $o->id; }, $orders);
            $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
            
            $items_sql = "SELECT 
                            oi.order_id,
                            oi.quantity,
                            oi.unit_price,
                            oi.discount_amount,
                            oi.total_amount,
                            p.base_name,
                            pv.weight_variant,
                            pv.unit_of_measure,
                            pv.grade,
                            pv.sku
                        FROM order_items oi
                        JOIN product_variants pv ON oi.variant_id = pv.id
                        JOIN products p ON pv.product_id = p.id
                        WHERE oi.order_id IN ($placeholders)
                        ORDER BY oi.order_id, oi.id";
            
            $all_items = $db->query($items_sql, $order_ids)->results();
            
            foreach ($all_items as $item) {
                if (!isset($order_items[$item->order_id])) {
                    $order_items[$item->order_id] = [];
                }
                $order_items[$item->order_id][] = $item;
                $summary['total_items_sold'] += (int)$item->quantity;
            }
        }

        $summary['total_orders'] = count($orders);
        foreach ($orders as $order) {
            // Calculate total item discounts for this order
            $item_discounts = 0;
            if (isset($order_items[$order->id])) {
                foreach ($order_items[$order->id] as $item) {
                    $item_discounts += floatval($item->discount_amount ?? 0);
                }
            }
            
            // Total discount = order discount + all item discounts
            $order_discount = floatval($order->discount_amount ?? 0);
            $total_order_discount = $order_discount + $item_discounts;
            
            // Store total discount back to order object for display
            $order->total_discount = $total_order_discount;
            
            $summary['total_sales'] += floatval($order->total_amount) + $total_order_discount;
            $summary['total_discount'] += $total_order_discount;
            $summary['net_sales'] += floatval($order->total_amount);
            $method = $order->payment_method ?? 'Unknown';
            if (!isset($summary['payment_methods'][$method])) {
                $summary['payment_methods'][$method] = ['count' => 0, 'amount' => 0.00];
            }
            $summary['payment_methods'][$method]['count']++;
            $summary['payment_methods'][$method]['amount'] += floatval($order->total_amount);
        }
    } catch (Exception $e) {
        $error = "Error fetching sales data: " . $e->getMessage();
        error_log("Today's POS Sales Error: " . $e->getMessage());
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'csv' && !$error) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="todays_sales_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ["Today's POS Sales Report"]);
    fputcsv($output, ['Date', date('l, F j, Y')]);
    fputcsv($output, ['Branch', $branch_name]);
    fputcsv($output, []);
    fputcsv($output, ['Summary Statistics']);
    fputcsv($output, ['Total Orders', $summary['total_orders']]);
    fputcsv($output, ['Total Items Sold', $summary['total_items_sold']]);
    fputcsv($output, ['Gross Sales', number_format($summary['total_sales'], 2)]);
    fputcsv($output, ['Total Discount', number_format($summary['total_discount'], 2)]);
    fputcsv($output, ['Net Sales', number_format($summary['net_sales'], 2)]);
    fputcsv($output, []);
    fputcsv($output, ['Payment Method Breakdown']);
    fputcsv($output, ['Payment Method', 'Order Count', 'Total Amount']);
    foreach ($summary['payment_methods'] as $method => $data) {
        fputcsv($output, [$method, $data['count'], number_format($data['amount'], 2)]);
    }
    fputcsv($output, []);
    fputcsv($output, ['Detailed Orders with Items']);
    fputcsv($output, []);
    foreach ($orders as $order) {
        fputcsv($output, ['Order', $order->order_number, date('h:i A', strtotime($order->order_date)), $order->customer_name ?? 'Walk-in', $order->payment_method]);
        fputcsv($output, ['Product', 'Variant', 'Qty', 'Unit Price', 'Discount', 'Total']);
        
        $order_item_discount_total = 0;
        if (isset($order_items[$order->id])) {
            foreach ($order_items[$order->id] as $item) {
                $variant = trim(($item->weight_variant ?? '') . ' ' . ($item->unit_of_measure ?? ''));
                if (!empty($item->grade)) {
                    $variant .= ' (Grade ' . $item->grade . ')';
                }
                $item_disc = floatval($item->discount_amount ?? 0);
                $order_item_discount_total += $item_disc;
                fputcsv($output, [
                    $item->base_name,
                    $variant ?: '-',
                    $item->quantity,
                    number_format($item->unit_price, 2),
                    number_format($item_disc, 2),
                    number_format($item->total_amount, 2)
                ]);
            }
        }
        $order_level_disc = floatval($order->discount_amount ?? 0);
        if ($order_level_disc > 0) {
            fputcsv($output, ['', '', '', 'Additional Order Discount:', number_format($order_level_disc, 2), '']);
        }
        fputcsv($output, ['', '', '', 'Total Discount:', number_format($order_item_discount_total + $order_level_disc, 2), '']);
        fputcsv($output, ['', '', '', 'Order Total:', '', number_format($order->total_amount, 2)]);
        fputcsv($output, []);
    }
    fclose($output);
    exit;
}

require_once '../templates/header.php';
?>
<style>
@media print { 
    .no-print {display:none!important;} 
    body{background:white;} 
    .print-title{display:block!important;text-align:center;margin-bottom:20px;} 
    .order-items-row{display:table-row!important;}
    table th:first-child, table td:first-child {display:none;}
}
.print-title{display:none;} 
.order-items-row{background-color:#f9fafb; display:none;}
.order-items-row.show{display:table-row;}
.toggle-items{cursor:pointer; transition:transform 0.2s;}
.toggle-items.active{transform:rotate(90deg);}
</style>
<script>
function toggleItems(orderId) {
    const row = document.getElementById('items-' + orderId);
    const icon = document.getElementById('icon-' + orderId);
    if (row.classList.contains('show')) {
        row.classList.remove('show');
        icon.classList.remove('active');
    } else {
        row.classList.add('show');
        icon.classList.add('active');
    }
}
</script>
<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
<div class="mb-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
            <p class="text-lg text-gray-600 mt-1">Summary of Point of Sale transactions for <?php echo date('l, F j, Y'); ?>.</p>
        </div>
        <div class="flex flex-wrap gap-3 no-print">
            <button onclick="window.print()" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors"><i class="fas fa-print mr-2"></i>Print</button>
            <a href="?export=csv" class="inline-flex items-center px-4 py-2 border border-green-600 rounded-lg shadow-sm text-sm font-medium text-green-700 bg-white hover:bg-green-50 transition-colors"><i class="fas fa-file-csv mr-2"></i>Export CSV</a>
            <button onclick="window.print()" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 transition-colors"><i class="fas fa-file-pdf mr-2"></i>Export PDF</button>
        </div>
    </div>
</div>
<div class="print-title"><h1 style="font-size:24px;font-weight:bold;margin-bottom:10px;">Today's POS Sales Report</h1><p style="font-size:16px;color:#666;"><?php echo $branch_name; ?> - <?php echo date('l, F j, Y'); ?></p></div>
<?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert"><p class="font-bold">Error</p><p><?php echo htmlspecialchars($error); ?></p></div>
<?php endif; ?>
<?php if (!$error): ?>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center justify-between"><div><p class="text-blue-100 text-sm font-medium uppercase tracking-wider">Total Orders</p><p class="text-4xl font-bold mt-1"><?php echo $summary['total_orders']; ?></p></div><div class="bg-blue-400 bg-opacity-30 rounded-full p-3"><i class="fas fa-shopping-cart text-2xl"></i></div></div>
    </div>
    <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center justify-between"><div><p class="text-indigo-100 text-sm font-medium uppercase tracking-wider">Items Sold</p><p class="text-4xl font-bold mt-1"><?php echo $summary['total_items_sold']; ?></p></div><div class="bg-indigo-400 bg-opacity-30 rounded-full p-3"><i class="fas fa-box text-2xl"></i></div></div>
    </div>
    <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center justify-between"><div><p class="text-green-100 text-sm font-medium uppercase tracking-wider">Net Sales</p><p class="text-3xl font-bold mt-1">৳<?php echo number_format($summary['net_sales'], 2); ?></p></div><div class="bg-green-400 bg-opacity-30 rounded-full p-3"><i class="fas fa-money-bill-wave text-2xl"></i></div></div>
    </div>
    <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center justify-between"><div><p class="text-yellow-100 text-sm font-medium uppercase tracking-wider">Total Discount</p><p class="text-3xl font-bold mt-1">৳<?php echo number_format($summary['total_discount'], 2); ?></p></div><div class="bg-yellow-400 bg-opacity-30 rounded-full p-3"><i class="fas fa-tag text-2xl"></i></div></div>
    </div>
</div>
<div class="bg-white rounded-lg shadow-md p-6 mb-8">
    <h2 class="text-xl font-bold text-gray-800 mb-4">Sales by Payment Method</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        <?php if (empty($summary['payment_methods'])): ?>
            <p class="text-gray-500 col-span-full">No sales recorded today.</p>
        <?php else: ?>
            <?php foreach ($summary['payment_methods'] as $method => $data): ?>
                <div class="border border-gray-200 rounded-lg p-4 bg-gray-50 text-center"><p class="text-sm font-semibold text-gray-700"><?php echo htmlspecialchars($method); ?></p><p class="text-2xl font-bold text-primary-600 mt-1">৳<?php echo number_format($data['amount'], 2); ?></p><p class="text-xs text-gray-500 mt-1">(<?php echo $data['count']; ?> Order<?php echo $data['count']!=1?'s':''; ?>)</p></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <h2 class="text-xl font-bold text-gray-800 p-5 border-b border-gray-200">Today's POS Orders with Items</h2>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr><th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">View</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order #</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th><?php if($branch_id_to_view===null):?><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Branch</th><?php endif;?><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Items</th><th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Discount</th><th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sold By</th><th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider no-print">Actions</th></tr>
            </thead>
            <tbody class="bg-white">
                <?php if(empty($orders)):?>
                    <tr><td colspan="11" class="px-6 py-10 text-center text-sm text-gray-500">No POS orders found for today.</td></tr>
                <?php else:?>
                    <?php foreach($orders as $order):?>
                        <tr class="border-t border-gray-200 hover:bg-gray-50">
                            <td class="px-6 py-4 text-center">
                                <button onclick="toggleItems(<?php echo $order->id; ?>)" class="text-blue-600 hover:text-blue-900 no-print">
                                    <i id="icon-<?php echo $order->id; ?>" class="fas fa-chevron-right toggle-items"></i>
                                </button>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium text-primary-600"><?php echo htmlspecialchars($order->order_number); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap"><?php echo date('h:i A',strtotime($order->order_date)); ?></td>
                            <?php if($branch_id_to_view===null):?><td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap"><?php echo htmlspecialchars($order->branch_name_order); ?></td><?php endif;?>
                            <td class="px-6 py-4 text-sm text-gray-800"><?php echo htmlspecialchars($order->customer_name??'Walk-in'); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?php $item_count=isset($order_items[$order->id])?count($order_items[$order->id]):0;$total_qty=0;if(isset($order_items[$order->id])){foreach($order_items[$order->id] as $item){$total_qty+=$item->quantity;}}echo "$item_count item".($item_count!=1?'s':'')." ($total_qty units)";?></td>
                            <td class="px-6 py-4 text-sm text-red-600 text-right font-mono whitespace-nowrap">৳<?php echo number_format($order->total_discount ?? 0,2); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900 text-right font-mono font-bold whitespace-nowrap">৳<?php echo number_format($order->total_amount,2); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><span class="px-2 py-0.5 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo($order->payment_method=='Cash')?'bg-green-100 text-green-800':'bg-blue-100 text-blue-800';?>"><?php echo htmlspecialchars($order->payment_method); ?></span></td>
                            <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap"><?php echo htmlspecialchars($order->user_name); ?></td>
                            <td class="px-6 py-4 text-right text-sm font-medium space-x-3 no-print whitespace-nowrap">
                                <a href="../pos/print_receipt.php?order_number=<?php echo urlencode($order->order_number);?>&copy_type=customer" target="_blank" class="text-indigo-600 hover:text-indigo-900" title="Customer Receipt"><i class="fas fa-receipt"></i></a>
                                <a href="../pos/print_receipt.php?order_number=<?php echo urlencode($order->order_number);?>&copy_type=office" target="_blank" class="text-gray-500 hover:text-gray-900" title="Office Receipt"><i class="fas fa-file-invoice"></i></a>
                                <a href="../pos/print_receipt.php?order_number=<?php echo urlencode($order->order_number);?>&copy_type=delivery" target="_blank" class="text-green-600 hover:text-green-900" title="Delivery Receipt"><i class="fas fa-truck"></i></a>
                            </td>
                        </tr>
                        <?php if(isset($order_items[$order->id])&&!empty($order_items[$order->id])):?>
                            <tr class="order-items-row" id="items-<?php echo $order->id; ?>">
                                <td colspan="11" class="px-6 py-3">
                                    <div class="ml-8">
                                        <p class="text-xs font-semibold text-gray-600 uppercase mb-2">Order Items:</p>
                                        <table class="min-w-full text-sm">
                                            <thead><tr class="text-xs text-gray-500"><th class="text-left py-1">Product</th><th class="text-left py-1">Variant</th><th class="text-center py-1">Qty</th><th class="text-right py-1">Unit Price</th><th class="text-right py-1">Discount</th><th class="text-right py-1">Total</th></tr></thead>
                                            <tbody>
                                                <?php foreach($order_items[$order->id] as $item):?>
                                                    <tr class="border-t border-gray-200">
                                                        <td class="py-2 text-gray-800 font-medium"><?php echo htmlspecialchars($item->base_name);?></td>
                                                        <td class="py-2 text-gray-600"><?php $variant=trim(($item->weight_variant??'').' '.($item->unit_of_measure??'')); echo htmlspecialchars($variant?:'-'); if(!empty($item->grade)){echo ' <span class="text-xs text-gray-500">(Grade '.htmlspecialchars($item->grade).')</span>';}?></td>
                                                        <td class="py-2 text-center font-mono"><?php echo $item->quantity;?></td>
                                                        <td class="py-2 text-right font-mono">৳<?php echo number_format($item->unit_price,2);?></td>
                                                        <td class="py-2 text-right font-mono text-red-600">৳<?php echo number_format($item->discount_amount ?? 0,2);?></td>
                                                        <td class="py-2 text-right font-mono font-bold">৳<?php echo number_format($item->total_amount,2);?></td>
                                                    </tr>
                                                <?php endforeach;?>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        <?php endif;?>
                    <?php endforeach;?>
                <?php endif;?>
            </tbody>
        </table>
    </div>
</div>
<?php endif;?>
</div>
<?php require_once '../templates/footer.php'; ?>
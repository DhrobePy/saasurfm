<?php
/**
 * View Purchase Order - Complete Styled Version
 * Combines working functionality with beautiful UI
 * File: /purchase/purchase_adnan_view_po.php
 */

require_once '../core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$currentUser = getCurrentUser();
$user_role = $currentUser['role'] ?? '';
$is_superadmin = ($user_role === 'Superadmin');

$po_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($po_id === 0) {
    redirect('purchase_adnan_index.php', 'Invalid PO', 'error');
}

$db = Database::getInstance()->getPdo();

// Get PO details with supplier info
$po_sql = "
    SELECT 
        po.*,
        s.company_name,
        s.contact_person,
        s.phone,
        s.email,
        s.address,
        u.display_name as created_by_name
    FROM purchase_orders_adnan po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN users u ON po.created_by_user_id = u.id
    WHERE po.id = ?
";

$stmt = $db->prepare($po_sql);
$stmt->execute([$po_id]);
$po = $stmt->fetch(PDO::FETCH_OBJ);

if (!$po) {
    redirect('purchase_adnan_index.php', 'PO not found', 'error');
}

$pageTitle = "Purchase Order - " . $po->po_number;

// Get GRNs
$grns_sql = "
    SELECT 
        grn.*,
        u.display_name as receiver_name
    FROM goods_received_adnan grn
    LEFT JOIN users u ON grn.receiver_user_id = u.id
    WHERE grn.purchase_order_id = ?
    AND grn.grn_status != 'cancelled'
    ORDER BY grn.grn_date DESC
";

$stmt = $db->prepare($grns_sql);
$stmt->execute([$po_id]);
$grns = $stmt->fetchAll(PDO::FETCH_OBJ);

// Get Payments
$payments_sql = "
    SELECT 
        pmt.*,
        u.display_name as created_by_name
    FROM purchase_payments_adnan pmt
    LEFT JOIN users u ON pmt.created_by_user_id = u.id
    WHERE pmt.purchase_order_id = ?
    ORDER BY pmt.payment_date DESC
";

$stmt = $db->prepare($payments_sql);
$stmt->execute([$po_id]);
$payments = $stmt->fetchAll(PDO::FETCH_OBJ);

require_once '../templates/header.php';
?>

<div class="w-full px-4 py-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-900">Purchase Order #<?php echo htmlspecialchars($po->po_number); ?></h2>
            <nav class="text-sm text-gray-600 mt-1">
                <a href="purchase_adnan_index.php" class="hover:text-primary-600">Purchase (Adnan)</a>
                <span class="mx-2">›</span>
                <span>PO #<?php echo htmlspecialchars($po->po_number); ?></span>
            </nav>
        </div>
        <div class="flex gap-2">
            <a href="purchase_adnan_index.php" class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <?php if ($is_superadmin): ?>
            <a href="purchase_adnan_edit_po.php?id=<?php echo $po_id; ?>" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 flex items-center gap-2">
                <i class="fas fa-edit"></i> Edit PO
            </a>
            <?php endif; ?>
            <button onclick="window.print()" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 flex items-center gap-2">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column: PO Details & Tables -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- PO Details Card -->
            <div class="bg-white rounded-lg shadow">
                <div class="bg-primary-600 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold flex items-center gap-2">
                        <i class="fas fa-file-invoice"></i> Purchase Order Details
                    </h5>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-3">
                            <div class="flex">
                                <span class="font-semibold w-40">PO Number:</span>
                                <span><?php echo htmlspecialchars($po->po_number); ?></span>
                            </div>
                            <div class="flex">
                                <span class="font-semibold w-40">PO Date:</span>
                                <span><?php echo date('d M Y', strtotime($po->po_date)); ?></span>
                            </div>
                            <div class="flex">
                                <span class="font-semibold w-40">Supplier:</span>
                                <span><?php echo htmlspecialchars($po->supplier_name); ?></span>
                            </div>
                            <div class="flex">
                                <span class="font-semibold w-40">Wheat Origin:</span>
                                <span class="inline-block bg-blue-100 text-blue-800 px-2 py-1 rounded text-sm">
                                    <?php echo htmlspecialchars($po->wheat_origin); ?>
                                </span>
                            </div>
                            <div class="flex">
                                <span class="font-semibold w-40">Expected Delivery:</span>
                                <span><?php echo $po->expected_delivery_date ? date('d M Y', strtotime($po->expected_delivery_date)) : 'N/A'; ?></span>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <div class="flex">
                                <span class="font-semibold w-40">Ordered Qty:</span>
                                <span><?php echo number_format($po->quantity_kg, 2); ?> KG</span>
                            </div>
                            <div class="flex">
                                <span class="font-semibold w-40">Unit Price:</span>
                                <span>৳<?php echo number_format($po->unit_price_per_kg, 2); ?> /KG</span>
                            </div>
                            <div class="flex">
                                <span class="font-semibold w-40">Total Value:</span>
                                <span class="font-bold">৳<?php echo number_format($po->total_order_value, 2); ?></span>
                            </div>
                            <div class="flex">
                                <span class="font-semibold w-40">Delivery Status:</span>
                                <?php
                                $status_colors = [
                                    'pending' => 'bg-gray-100 text-gray-800',
                                    'partial' => 'bg-yellow-100 text-yellow-800',
                                    'completed' => 'bg-green-100 text-green-800',
                                    'over_received' => 'bg-blue-100 text-blue-800',
                                    'closed' => 'bg-gray-100 text-gray-800'
                                ];
                                ?>
                                <span class="inline-block px-2 py-1 rounded text-sm <?php echo $status_colors[$po->delivery_status] ?? 'bg-gray-100 text-gray-800'; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $po->delivery_status)); ?>
                                </span>
                            </div>
                            <div class="flex">
                                <span class="font-semibold w-40">Created:</span>
                                <span><?php echo date('d M Y H:i', strtotime($po->created_at)); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php if ($po->remarks): ?>
                    <div class="mt-4 pt-4 border-t">
                        <strong class="block mb-1">Remarks:</strong>
                        <p class="text-gray-600"><?php echo nl2br(htmlspecialchars($po->remarks)); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Goods Received Notes -->
            <div class="bg-white rounded-lg shadow">
                <div class="bg-green-600 text-white px-6 py-4 rounded-t-lg flex justify-between items-center">
                    <h5 class="font-semibold flex items-center gap-2">
                        <i class="fas fa-truck-loading"></i> Goods Received Notes (<?php echo count($grns); ?>)
                    </h5>
                    <a href="purchase_adnan_record_grn.php?po_id=<?php echo $po_id; ?>" 
                       class="bg-white text-green-600 px-3 py-1 rounded text-sm hover:bg-green-50 flex items-center gap-1">
                        <i class="fas fa-plus"></i> Record GRN
                    </a>
                </div>
                <div class="p-6">
                    <?php if (count($grns) > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">GRN #</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Truck</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Expected Qty (KG)</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Expected Value</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Received Qty (KG)</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Received Value</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Unload Point</th>
                                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase print:hidden">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php 
                                        $total_received_qty = 0;
                                        $total_expected_qty = 0;  // NEW
                                        $total_received_value = 0;
                                        $total_expected_value = 0;  // NEW
                                    foreach ($grns as $grn): 
                                        $total_received_qty += $grn->quantity_received_kg;
                                        $total_expected_qty += ($grn->expected_quantity ?? 0);  // NEW
                                        $total_received_value += $grn->total_value;
                                        $total_expected_value += (($grn->expected_quantity ?? 0) * $po->unit_price_per_kg);  // NEW
                                    ?>
                                
                                    
                                    
                                    
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-3 py-2 text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($grn->grn_number); ?>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-gray-500">
                                            <?php echo date('d M Y', strtotime($grn->grn_date)); ?>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-gray-500">
                                            <?php echo htmlspecialchars($grn->truck_number ?? 'N/A'); ?>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-right font-medium text-blue-600">
                                            <?php echo number_format($grn->expected_quantity ?? 0, 2); ?>
                                        </td>
                                        
                                        <td class="px-3 py-2 text-sm text-right font-bold text-purple-600">
                                            ৳<?php echo number_format(($grn->expected_quantity ?? 0) * $po->unit_price_per_kg, 2); ?>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-right font-medium text-gray-900">
                                            <?php echo number_format($grn->quantity_received_kg, 2); ?>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-right font-bold text-gray-900">
                                            ৳<?php echo number_format($grn->total_value, 2); ?>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-gray-500">
                                            <?php echo htmlspecialchars($grn->unload_point_name ?? 'N/A'); ?>
                                        </td>
                                        <td class="px-3 py-2 text-center">
                                            <?php
                                            $grn_status_colors = [
                                                'draft' => 'bg-gray-100 text-gray-800',
                                                'verified' => 'bg-yellow-100 text-yellow-800',
                                                'posted' => 'bg-green-100 text-green-800',
                                                'cancelled' => 'bg-red-100 text-red-800'
                                            ];
                                            ?>
                                            <span class="inline-block px-2 py-1 rounded text-xs <?php echo $grn_status_colors[$grn->grn_status] ?? 'bg-gray-100 text-gray-800'; ?>">
                                                <?php echo ucfirst($grn->grn_status); ?>
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 text-center print:hidden">
                                            <div class="flex items-center justify-center gap-2">
                                                <!-- View Receipt -->
                                                <a href="purchase_adnan_grn_receipt.php?id=<?php echo $grn->id; ?>" 
                                                   class="text-blue-600 hover:text-blue-800" 
                                                   title="View Receipt">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                
                                                <!-- Print -->
                                                <a href="purchase_adnan_grn_receipt.php?id=<?php echo $grn->id; ?>&print=1" 
                                                   target="_blank"
                                                   class="text-gray-600 hover:text-gray-800" 
                                                   title="Print Receipt">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                                
                                                <?php if ($is_superadmin): ?>
                                                <!-- Edit -->
                                                <a href="purchase_adnan_edit_grn.php?id=<?php echo $grn->id; ?>" 
                                                   class="text-orange-600 hover:text-orange-800" 
                                                   title="Edit GRN">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                
                                                <!-- Delete -->
                                                <button onclick="deleteGRN(<?php echo $grn->id; ?>, '<?php echo htmlspecialchars($grn->grn_number); ?>')" 
                                                        class="text-red-600 hover:text-red-800" 
                                                        title="Delete GRN">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Totals Row -->
                                    <!-- Totals Row -->
                                    <!-- Totals Row -->
                                    <tr class="bg-gray-100 font-semibold">
                                        <td colspan="3" class="px-3 py-2 text-sm text-gray-900">Totals</td>
                                        <td class="px-3 py-2 text-sm text-right text-blue-600">
                                            <?php echo number_format($total_expected_qty, 2); ?>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-right text-purple-600">
                                            ৳<?php echo number_format($total_expected_value, 2); ?>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-right text-gray-900">
                                            <?php echo number_format($total_received_qty, 2); ?>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-right text-gray-900">
                                            ৳<?php echo number_format($total_received_value, 2); ?>
                                        </td>
                                        
                                        <td colspan="3"></td>
                                    </tr>
                                    
                                    
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-500 text-center py-4">No goods received yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payments -->
            <div class="bg-white rounded-lg shadow">
                <div class="bg-yellow-500 text-white px-6 py-4 rounded-t-lg flex justify-between items-center">
                    <h5 class="font-semibold flex items-center gap-2">
                        <i class="fas fa-money-bill-wave"></i> Payments (<?php echo count($payments); ?>)
                    </h5>
                    <a href="purchase_adnan_record_payment.php?po_id=<?php echo $po_id; ?>" 
                       class="bg-white text-yellow-600 px-3 py-1 rounded text-sm hover:bg-yellow-50 flex items-center gap-1">
                        <i class="fas fa-plus"></i> Record Payment
                    </a>
                </div>
                <div class="p-6">
                    <?php if (count($payments) > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Payment #</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Bank/Account</th>
                                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase print:hidden">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php 
                                    $total_paid = 0;
                                    foreach ($payments as $payment): 
                                        if ($payment->is_posted == 1) {
                                            $total_paid += $payment->amount_paid;
                                        }
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-3 py-2 text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($payment->payment_voucher_number); ?>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-gray-500">
                                            <?php echo date('d M Y', strtotime($payment->payment_date)); ?>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-right font-bold text-green-600">
                                            ৳<?php echo number_format($payment->amount_paid, 2); ?>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-gray-500">
                                            <?php
                                            $method_colors = [
                                                'bank' => 'bg-blue-100 text-blue-800',
                                                'cash' => 'bg-green-100 text-green-800',
                                                'cheque' => 'bg-purple-100 text-purple-800'
                                            ];
                                            ?>
                                            <span class="inline-block px-2 py-1 rounded text-xs <?php echo $method_colors[$payment->payment_method] ?? 'bg-gray-100 text-gray-800'; ?>">
                                                <i class="fas fa-<?php 
                                                    echo $payment->payment_method === 'bank' ? 'university' : 
                                                         ($payment->payment_method === 'cheque' ? 'money-check' : 'money-bill-wave'); 
                                                ?> mr-1"></i>
                                                <?php echo ucfirst($payment->payment_method); ?>
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-gray-500">
                                            <?php echo htmlspecialchars($payment->bank_name ?? 'N/A'); ?>
                                        </td>
                                        <td class="px-3 py-2 text-center">
                                            <span class="inline-block px-2 py-1 rounded text-xs <?php echo $payment->is_posted ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                                <?php echo $payment->is_posted ? 'Posted' : 'Pending'; ?>
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 text-center print:hidden">
                                            <div class="flex items-center justify-center gap-2">
                                                <!-- View Receipt -->
                                                <a href="purchase_adnan_payment_receipt.php?id=<?php echo $payment->id; ?>" 
                                                   class="text-blue-600 hover:text-blue-800" 
                                                   title="View Receipt">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                
                                                <!-- Print -->
                                                <a href="purchase_adnan_payment_receipt.php?id=<?php echo $payment->id; ?>&print=1" 
                                                   target="_blank"
                                                   class="text-gray-600 hover:text-gray-800" 
                                                   title="Print Receipt">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                                
                                                <?php if ($is_superadmin): ?>
                                                <!-- Edit -->
                                                <a href="purchase_adnan_edit_payment.php?id=<?php echo $payment->id; ?>" 
                                                   class="text-orange-600 hover:text-orange-800" 
                                                   title="Edit Payment">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                
                                                <!-- Delete -->
                                                <button onclick="deletePayment(<?php echo $payment->id; ?>, '<?php echo htmlspecialchars($payment->payment_voucher_number); ?>')" 
                                                        class="text-red-600 hover:text-red-800" 
                                                        title="Delete Payment">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Totals Row -->
                                    <tr class="bg-gray-100 font-semibold">
                                        <td colspan="2" class="px-3 py-2 text-sm text-gray-900">Total Paid (Posted Only)</td>
                                        <td class="px-3 py-2 text-sm text-right text-gray-900">
                                            ৳<?php echo number_format($total_paid, 2); ?>
                                        </td>
                                        <td colspan="4"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-500 text-center py-4">No payments recorded yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Summary Cards -->
        <div class="space-y-6">
            <!-- Financial Summary -->
            <div class="bg-white rounded-lg shadow">
                <div class="bg-blue-600 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold flex items-center gap-2">
                        <i class="fas fa-calculator"></i> Financial Summary
                    </h5>
                </div>
                <div class="p-6 space-y-3">
                    <div class="flex justify-between">
                        <span class="font-semibold">Total Order Value:</span>
                        <span>৳<?php echo number_format($po->total_order_value, 2); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-semibold">Received Value:</span>
                        <span class="text-green-600">৳<?php echo number_format($po->total_received_value, 2); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-semibold">Expected Payable:</span>
                        <span class="text-purple-600">৳<?php 
                            $expected_payable = $total_expected_qty * $po->unit_price_per_kg;
                            echo number_format($expected_payable, 2); 
                        ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-semibold">Total Paid:</span>
                        <span class="text-blue-600">৳<?php echo number_format($po->total_paid, 2); ?></span>
                    </div>
                    
                    <div class="flex justify-between pt-3 border-t font-bold">
                        <span>Balance Payable:</span>
                        <?php 
                            $balance_payable_calc = $expected_payable - $po->total_paid;
                        ?>
                        <span class="<?php echo $balance_payable_calc > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                            ৳<?php echo number_format($balance_payable_calc, 2); ?>
                        </span>
                    </div>
                    
                    
                </div>
            </div>

            <!-- Delivery Summary -->
            <div class="bg-white rounded-lg shadow">
                <div class="bg-gray-600 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold flex items-center gap-2">
                        <i class="fas fa-boxes"></i> Delivery Summary
                    </h5>
                </div>
                <div class="p-6 space-y-3">
                    <div class="flex justify-between">
                        <span class="font-semibold">Ordered:</span>
                        <span><?php echo number_format($po->quantity_kg, 2); ?> KG</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-semibold">Received:</span>
                        <span class="text-green-600"><?php echo number_format($po->total_received_qty, 2); ?> KG</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-semibold">Expected Received:</span>
                        <span class="text-blue-600"><?php echo number_format($total_expected_qty, 2); ?> KG</span>
                    </div>
                    <div class="flex justify-between pt-3 border-t font-bold">
                        <span>Yet to Receive:</span>
                        <?php $yet_to_receive_calc = $po->quantity_kg - $total_expected_qty; ?>
                        <span class="<?php echo $yet_to_receive_calc > 0 ? 'text-yellow-600' : 'text-green-600'; ?>">
                            <?php echo number_format($yet_to_receive_calc, 2); ?> KG
                        </span>
                    </div>
                        <div class="text-sm text-gray-600 mb-1">Completion:</div>
                        <?php $completion = $po->quantity_kg > 0 ? ($po->total_received_qty / $po->quantity_kg * 100) : 0; ?>
                        <div class="w-full bg-gray-200 rounded-full h-6">
                            <div class="<?php echo $completion >= 100 ? 'bg-green-600' : 'bg-yellow-500'; ?> h-6 rounded-full flex items-center justify-center text-white text-xs font-semibold" 
                                 style="width: <?php echo min($completion, 100); ?>%">
                                <?php echo number_format($completion, 1); ?>%
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Status -->
            <div class="bg-white rounded-lg shadow">
                <div class="bg-yellow-500 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold flex items-center gap-2">
                        <i class="fas fa-credit-card"></i> Payment Status
                    </h5>
                </div>
                <div class="p-6">
                    <?php
                    $payment_colors = [
                        'unpaid' => 'bg-red-100 text-red-800',
                        'partial' => 'bg-yellow-100 text-yellow-800',
                        'paid' => 'bg-green-100 text-green-800',
                        'overpaid' => 'bg-blue-100 text-blue-800'
                    ];
                    ?>
                    <div class="text-center mb-4">
                        <span class="inline-block px-4 py-2 rounded-lg text-lg font-bold <?php echo $payment_colors[$po->payment_status] ?? 'bg-gray-100 text-gray-800'; ?>">
                            <?php echo strtoupper($po->payment_status); ?>
                        </span>
                    </div>
                    <div>
                        <div class="text-sm text-gray-600 mb-1">Payment Progress:</div>
                        <?php $payment_progress = $po->total_received_value > 0 ? ($po->total_paid / $po->total_received_value * 100) : 0; ?>
                        <div class="w-full bg-gray-200 rounded-full h-6">
                            <div class="<?php echo $payment_progress >= 100 ? 'bg-green-600' : 'bg-red-500'; ?> h-6 rounded-full flex items-center justify-center text-white text-xs font-semibold" 
                                 style="width: <?php echo min($payment_progress, 100); ?>%">
                                <?php echo number_format($payment_progress, 1); ?>%
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript for Delete Functions (KEPT EXACTLY AS-IS) -->
<script>
function deleteGRN(grnId, grnNumber) {
    if (!confirm(`Are you sure you want to delete GRN ${grnNumber}?\n\nThis will:\n- Cancel the GRN\n- Reverse the journal entry\n- Recalculate PO totals\n\nThis action can be undone by Superadmin.`)) {
        return;
    }
    
    const reason = prompt('Please enter reason for deletion (required):');
    if (!reason || reason.trim() === '') {
        alert('Reason is required for deletion');
        return;
    }
    
    // Show loading
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    
    // Send delete request
    fetch('purchase_adnan_delete_grn.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `id=${grnId}&reason=${encodeURIComponent(reason)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message || 'GRN deleted successfully');
            window.location.reload();
        } else {
            alert(data.message || 'Failed to delete GRN');
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    });
}

function deletePayment(paymentId, paymentNumber) {
    if (!confirm(`Are you sure you want to delete Payment ${paymentNumber}?\n\nThis will:\n- Unpost the payment\n- Reverse the journal entry (if posted)\n- Recalculate PO totals\n\nThis action can be undone by Superadmin.`)) {
        return;
    }
    
    const reason = prompt('Please enter reason for deletion (required):');
    if (!reason || reason.trim() === '') {
        alert('Reason is required for deletion');
        return;
    }
    
    // Show loading
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    
    // Send delete request
    fetch('purchase_adnan_delete_payment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `id=${paymentId}&reason=${encodeURIComponent(reason)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message || 'Payment deleted successfully');
            window.location.reload();
        } else {
            alert(data.message || 'Failed to delete payment');
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    });
}
</script>

<!-- Print Styles -->
<style>
@media print {
    .print\:hidden {
        display: none !important;
    }
}
</style>

<?php require_once '../templates/footer.php'; ?>
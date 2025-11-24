<?php
require_once __DIR__ . '/../core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle = "View Purchase Order";

// Get PO ID
$po_id = $_GET['id'] ?? null;
if (!$po_id) {
    redirect('purchase/purchase_adnan_index.php');
}

// Initialize manager
$manager = new Purchaseadnanmanager();

// Get PO details
$po = $manager->getPurchaseOrder($po_id);
if (!$po) {
    $_SESSION['error'] = "Purchase order not found";
    redirect('purchase/purchase_adnan_index.php');
}

// Get GRNs and Payments
$grns = $manager->getGRNsByPO($po_id);
$payments = $manager->getPaymentsByPO($po_id);

require_once '../templates/header.php';
?>

<div class="w-full px-4 py-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-900">Purchase Order #<?php echo htmlspecialchars($po->po_number); ?></h2>
            <nav class="text-sm text-gray-600 mt-1">
                <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="hover:text-primary-600">Purchase (Adnan)</a>
                <span class="mx-2">›</span>
                <span>PO #<?php echo htmlspecialchars($po->po_number); ?></span>
            </nav>
        </div>
        <div class="flex gap-2">
            <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <button onclick="window.print()" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 flex items-center gap-2">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column: PO Details -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Basic Info Card -->
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
                                <span class="font-semibold w-40">Status:</span>
                                <?php
                                $status_colors = [
                                    'pending' => 'bg-gray-100 text-gray-800',
                                    'partial' => 'bg-yellow-100 text-yellow-800',
                                    'completed' => 'bg-green-100 text-green-800',
                                    'over_received' => 'bg-blue-100 text-blue-800'
                                ];
                                ?>
                                <span class="inline-block px-2 py-1 rounded text-sm <?php echo $status_colors[$po->delivery_status] ?? 'bg-gray-100 text-gray-800'; ?>">
                                    <?php echo ucfirst($po->delivery_status); ?>
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
                    <a href="<?php echo url('purchase/purchase_adnan_record_grn.php?po_id=' . $po->id); ?>" 
                       class="bg-white text-green-600 px-3 py-1 rounded text-sm hover:bg-green-50">
                        <i class="fas fa-plus"></i> Record GRN
                    </a>
                </div>
                <div class="p-6">
                    <?php if (empty($grns)): ?>
                        <p class="text-gray-500">No goods received yet.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">GRN#</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Truck#</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Quantity</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Value</th>
                                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Variance</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($grns as $grn): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-3 py-2 text-sm"><?php echo htmlspecialchars($grn->grn_number); ?></td>
                                        <td class="px-3 py-2 text-sm"><?php echo date('d M Y', strtotime($grn->grn_date)); ?></td>
                                        <td class="px-3 py-2 text-sm"><?php echo htmlspecialchars($grn->truck_number ?: 'N/A'); ?></td>
                                        <td class="px-3 py-2 text-sm text-right"><?php echo number_format($grn->quantity_received_kg, 2); ?> KG</td>
                                        <td class="px-3 py-2 text-sm text-right">৳<?php echo number_format($grn->total_value, 2); ?></td>
                                        <td class="px-3 py-2 text-sm text-center">
                                            <?php if ($grn->variance_percentage): ?>
                                                <span class="inline-block px-2 py-1 rounded text-xs <?php echo abs($grn->variance_percentage) > 1 ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                                    <?php echo $grn->variance_percentage > 0 ? '+' : ''; ?><?php echo $grn->variance_percentage; ?>%
                                                </span>
                                            <?php else: ?>
                                                <span class="text-gray-400">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-3 py-2 text-sm">
                                            <span class="inline-block px-2 py-1 rounded text-xs <?php echo $grn->grn_status == 'posted' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                                <?php echo ucfirst($grn->grn_status); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payments -->
            <div class="bg-white rounded-lg shadow">
                <div class="bg-yellow-500 text-white px-6 py-4 rounded-t-lg flex justify-between items-center">
                    <h5 class="font-semibold flex items-center gap-2">
                        <i class="fas fa-money-bill-wave"></i> Payments (<?php echo count($payments); ?>)
                    </h5>
                    <a href="<?php echo url('purchase/purchase_adnan_record_payment.php?po_id=' . $po->id); ?>" 
                       class="bg-white text-yellow-600 px-3 py-1 rounded text-sm hover:bg-yellow-50">
                        <i class="fas fa-plus"></i> Record Payment
                    </a>
                </div>
                <div class="p-6">
                    <?php if (empty($payments)): ?>
                        <p class="text-gray-500">No payments recorded yet.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Voucher#</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Method</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($payments as $payment): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-3 py-2 text-sm"><?php echo htmlspecialchars($payment->payment_voucher_number); ?></td>
                                        <td class="px-3 py-2 text-sm"><?php echo date('d M Y', strtotime($payment->payment_date)); ?></td>
                                        <td class="px-3 py-2 text-sm text-right font-semibold">৳<?php echo number_format($payment->amount_paid, 2); ?></td>
                                        <td class="px-3 py-2 text-sm text-center">
                                            <span class="inline-block px-2 py-1 rounded text-xs <?php echo $payment->payment_method == 'bank' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'; ?>">
                                                <?php echo ucfirst($payment->payment_method); ?>
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 text-sm"><?php echo htmlspecialchars($payment->reference_number ?: 'N/A'); ?></td>
                                        <td class="px-3 py-2 text-sm text-center">
                                            <span class="inline-block px-2 py-1 rounded text-xs <?php echo $payment->is_posted ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                                <?php echo $payment->is_posted ? 'Posted' : 'Pending'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Summary -->
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
                        <span class="font-semibold">Total Paid:</span>
                        <span class="text-blue-600">৳<?php echo number_format($po->total_paid, 2); ?></span>
                    </div>
                    <div class="flex justify-between pt-3 border-t font-bold">
                        <span>Balance Payable:</span>
                        <span class="<?php echo $po->balance_payable > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                            ৳<?php echo number_format($po->balance_payable, 2); ?>
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
                    <div class="flex justify-between pt-3 border-t font-bold">
                        <span>Yet to Receive:</span>
                        <span class="<?php echo $po->qty_yet_to_receive > 0 ? 'text-yellow-600' : 'text-green-600'; ?>">
                            <?php echo number_format($po->qty_yet_to_receive, 2); ?> KG
                        </span>
                    </div>
                    <div>
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

<?php require_once '../templates/footer.php'; ?>
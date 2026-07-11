<?php
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
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Purchase Order</h1>
            <p class="text-gray-600 mt-1"><?php echo htmlspecialchars($po->po_number); ?></p>
        </div>
        <div class="flex items-center gap-3">
            <?php if ($is_superadmin): ?>
            <a href="purchase_adnan_edit_po.php?id=<?php echo $po_id; ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-edit mr-2"></i>Edit
            </a>
            <?php endif; ?>
            <button onclick="window.print()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-print mr-2"></i>Print
            </button>
            <a href="purchase_adnan_index.php" class="text-gray-600 hover:text-gray-800">
                <i class="fas fa-arrow-left mr-2"></i>Back
            </a>
        </div>
    </div>

    <!-- Status & Supplier sections... (keep existing code) -->
    
    <!-- Goods Received Notes Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden mt-6">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h2 class="text-lg font-semibold text-gray-900">Goods Received Notes</h2>
        </div>

        <?php if (count($grns) > 0): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">GRN #</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Truck</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty (KG)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Value</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Unload Point</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase print:hidden">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php 
                    $total_received_qty = 0;
                    $total_received_value = 0;
                    foreach ($grns as $grn): 
                        $total_received_qty += $grn->quantity_received_kg;
                        $total_received_value += $grn->total_value;
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($grn->grn_number); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo date('d M Y', strtotime($grn->grn_date)); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo htmlspecialchars($grn->truck_number ?? 'N/A'); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                            <?php echo number_format($grn->quantity_received_kg, 0); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-gray-900">
                            ৳<?php echo number_format($grn->total_value, 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo htmlspecialchars($grn->unload_point_name ?? 'N/A'); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">
                                <?php echo ucfirst($grn->grn_status); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center print:hidden">
                            <div class="flex items-center justify-center gap-2">
                                <!-- View Receipt (Everyone) -->
                                <a href="purchase_adnan_grn_receipt.php?id=<?php echo $grn->id; ?>" 
                                   class="text-blue-600 hover:text-blue-800" 
                                   title="View Receipt">
                                    <i class="fas fa-eye"></i>
                                </a>
                                
                                <!-- Print (Everyone) -->
                                <a href="purchase_adnan_grn_receipt.php?id=<?php echo $grn->id; ?>&print=1" 
                                   target="_blank"
                                   class="text-gray-600 hover:text-gray-800" 
                                   title="Print Receipt">
                                    <i class="fas fa-print"></i>
                                </a>
                                
                                <?php if ($is_superadmin): ?>
                                <!-- Edit (Superadmin Only) -->
                                <a href="purchase_adnan_edit_grn.php?id=<?php echo $grn->id; ?>" 
                                   class="text-orange-600 hover:text-orange-800" 
                                   title="Edit GRN">
                                    <i class="fas fa-edit"></i>
                                </a>
                                
                                <!-- Delete (Superadmin Only) -->
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
                    <tr class="bg-gray-100 font-semibold">
                        <td colspan="3" class="px-6 py-4 text-sm text-gray-900">Totals</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                            <?php echo number_format($total_received_qty, 0); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                            ৳<?php echo number_format($total_received_value, 2); ?>
                        </td>
                        <td colspan="3"></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-12">
            <i class="fas fa-truck-loading text-gray-300 text-5xl mb-4"></i>
            <p class="text-gray-500 mb-4">No goods received yet</p>
        </div>
        <?php endif; ?>
        
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
            <a href="purchase_adnan_record_grn.php?po_id=<?php echo $po_id; ?>" 
               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg inline-flex items-center gap-2">
                <i class="fas fa-plus"></i> Record New GRN
            </a>
        </div>
    </div>

    <!-- Payments Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden mt-6">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h2 class="text-lg font-semibold text-gray-900">Payments</h2>
        </div>

        <?php if (count($payments) > 0): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment #</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Bank/Account</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase print:hidden">Actions</th>
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
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($payment->payment_voucher_number); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo date('d M Y', strtotime($payment->payment_date)); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-green-600">
                            ৳<?php echo number_format($payment->amount_paid, 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <i class="fas fa-<?php 
                                echo $payment->payment_method === 'bank' ? 'university' : 
                                     ($payment->payment_method === 'cheque' ? 'money-check' : 'money-bill-wave'); 
                            ?> mr-1"></i>
                            <?php echo ucfirst($payment->payment_method); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo htmlspecialchars($payment->bank_name ?? 'N/A'); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $payment->is_posted ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                <?php echo $payment->is_posted ? 'Posted' : 'Pending'; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center print:hidden">
                            <div class="flex items-center justify-center gap-2">
                                <!-- View Receipt (Everyone) -->
                                <a href="purchase_adnan_payment_receipt.php?id=<?php echo $payment->id; ?>" 
                                   class="text-blue-600 hover:text-blue-800" 
                                   title="View Receipt">
                                    <i class="fas fa-eye"></i>
                                </a>
                                
                                <!-- Print (Everyone) -->
                                <a href="purchase_adnan_payment_receipt.php?id=<?php echo $payment->id; ?>&print=1" 
                                   target="_blank"
                                   class="text-gray-600 hover:text-gray-800" 
                                   title="Print Receipt">
                                    <i class="fas fa-print"></i>
                                </a>
                                
                                <?php if ($is_superadmin): ?>
                                <!-- Edit (Superadmin Only) -->
                                <a href="purchase_adnan_edit_payment.php?id=<?php echo $payment->id; ?>" 
                                   class="text-orange-600 hover:text-orange-800" 
                                   title="Edit Payment">
                                    <i class="fas fa-edit"></i>
                                </a>
                                
                                <!-- Delete (Superadmin Only) -->
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
                        <td colspan="2" class="px-6 py-4 text-sm text-gray-900">Total Paid (Posted Only)</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                            ৳<?php echo number_format($total_paid, 2); ?>
                        </td>
                        <td colspan="4"></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-12">
            <i class="fas fa-money-bill-wave text-gray-300 text-5xl mb-4"></i>
            <p class="text-gray-500 mb-4">No payments recorded yet</p>
        </div>
        <?php endif; ?>
        
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
            <a href="purchase_adnan_record_payment.php?po_id=<?php echo $po_id; ?>" 
               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg inline-flex items-center gap-2">
                <i class="fas fa-plus"></i> Record Payment
            </a>
        </div>
    </div>

</div>

<!-- JavaScript for Delete Functions -->
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
<?php
require_once __DIR__ . '/../core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle = "Payment Receipt";

// Get Payment ID
$payment_id = $_GET['id'] ?? null;
if (!$payment_id) {
    $_SESSION['error'] = "Payment ID is required";
    redirect('purchase/purchase_adnan_index.php');
}

// Initialize managers
$payment_manager = new Purchasepaymentadnanmanager();
$po_manager = new Purchaseadnanmanager();

// Get payment details
$payment = $payment_manager->getPayment($payment_id);
if (!$payment) {
    $_SESSION['error'] = "Payment not found";
    redirect('purchase/purchase_adnan_index.php');
}

// Get PO details
$po = $po_manager->getPurchaseOrder($payment->purchase_order_id);

// Calculate balances
$balance_before = $po->total_received_value - ($po->total_paid - $payment->amount_paid);
$balance_after = $po->balance_payable;

// Get bank/employee details
$payment_source = '';
if ($payment->payment_method === 'bank') {
    $payment_source = $payment->bank_name;
    if ($payment->reference_number) {
        $payment_source .= ' (Ref: ' . $payment->reference_number . ')';
    }
} elseif ($payment->payment_method === 'cash') {
    $payment_source = 'Cash';
    if ($payment->handled_by_employee) {
        $payment_source .= ' - Handled by: ' . $payment->handled_by_employee;
    }
} elseif ($payment->payment_method === 'cheque') {
    $payment_source = 'Cheque';
    if ($payment->reference_number) {
        $payment_source .= ' #' . $payment->reference_number;
    }
}

require_once '../templates/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    body { margin: 0; padding: 20px; }
    .print-container { max-width: 100% !important; }
}
</style>

<div class="w-full px-4 py-6 no-print">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-gray-900">Payment Receipt</h2>
        <div class="flex gap-2">
            <button onclick="window.print()" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 flex items-center gap-2">
                <i class="fas fa-print"></i> Print
            </button>
            <a href="<?php echo url('purchase/purchase_adnan_view_po.php?id=' . $po->id); ?>" 
               class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
                <i class="fas fa-arrow-left"></i> Back to PO
            </a>
        </div>
    </div>
</div>

<div class="print-container max-w-4xl mx-auto bg-white p-8">
    <!-- Company Header -->
    <div class="text-center border-b-2 border-gray-800 pb-6 mb-6">
        <h1 class="text-3xl font-bold text-gray-900">UJJAL FLOUR MILLS</h1>
        <p class="text-gray-600 mt-2">Sirajganj, Demra, Rampura</p>
        <p class="text-gray-600">Phone: +880-XXX-XXXXXX | Email: info@ujjalfm.com</p>
    </div>

    <!-- Receipt Title -->
    <div class="text-center mb-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-2">PAYMENT RECEIPT</h2>
        <div class="inline-block bg-yellow-100 text-yellow-800 px-4 py-2 rounded-lg">
            <strong>Voucher #:</strong> <?php echo htmlspecialchars($payment->payment_voucher_number); ?>
        </div>
        <?php if ($payment->payment_type === 'advance'): ?>
        <div class="inline-block bg-orange-100 text-orange-800 px-3 py-1 rounded-lg ml-2">
            <i class="fas fa-info-circle"></i> ADVANCE PAYMENT
        </div>
        <?php endif; ?>
        <?php if ($payment->is_posted): ?>
        <div class="inline-block bg-green-100 text-green-800 px-3 py-1 rounded-lg ml-2">
            <i class="fas fa-check-circle"></i> POSTED
        </div>
        <?php else: ?>
        <div class="inline-block bg-gray-100 text-gray-800 px-3 py-1 rounded-lg ml-2">
            <i class="fas fa-clock"></i> PENDING
        </div>
        <?php endif; ?>
    </div>

    <!-- Payment Details Grid -->
    <div class="grid grid-cols-2 gap-6 mb-6">
        <!-- Left Column -->
        <div class="space-y-3">
            <div class="flex justify-between border-b border-gray-300 pb-2">
                <span class="font-semibold text-gray-700">Payment Date:</span>
                <span><?php echo date('d M Y', strtotime($payment->payment_date)); ?></span>
            </div>
            <div class="flex justify-between border-b border-gray-300 pb-2">
                <span class="font-semibold text-gray-700">PO Number:</span>
                <span><?php echo htmlspecialchars($payment->po_number); ?></span>
            </div>
            <div class="flex justify-between border-b border-gray-300 pb-2">
                <span class="font-semibold text-gray-700">Supplier:</span>
                <span><?php echo htmlspecialchars($payment->supplier_name); ?></span>
            </div>
            <div class="flex justify-between border-b border-gray-300 pb-2">
                <span class="font-semibold text-gray-700">Payment Method:</span>
                <span class="uppercase"><?php echo htmlspecialchars($payment->payment_method); ?></span>
            </div>
        </div>

        <!-- Right Column -->
        <div class="space-y-3">
            <div class="flex justify-between border-b border-gray-300 pb-2">
                <span class="font-semibold text-gray-700">Payment Type:</span>
                <span class="capitalize"><?php echo htmlspecialchars($payment->payment_type); ?></span>
            </div>
            <div class="flex justify-between border-b border-gray-300 pb-2">
                <span class="font-semibold text-gray-700">Payment Via:</span>
                <span class="text-sm"><?php echo htmlspecialchars($payment_source); ?></span>
            </div>
            <?php if ($payment->journal_entry_id): ?>
            <div class="flex justify-between border-b border-gray-300 pb-2">
                <span class="font-semibold text-gray-700">Journal Entry:</span>
                <span><?php echo $payment->journal_entry_id; ?></span>
            </div>
            <?php endif; ?>
            <div class="flex justify-between border-b border-gray-300 pb-2">
                <span class="font-semibold text-gray-700">Recorded By:</span>
                <span><?php echo htmlspecialchars($payment->created_by_user_name ?? 'System'); ?></span>
            </div>
        </div>
    </div>

    <!-- Payment Summary -->
    <div class="bg-gray-50 border border-gray-300 rounded-lg p-6 mb-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4">Payment Summary</h3>
        <table class="w-full">
            <tbody>
                <tr class="border-b border-gray-200">
                    <td class="py-2 font-semibold text-gray-700">Total Order Value:</td>
                    <td class="text-right">৳<?php echo number_format($po->total_order_value, 2); ?></td>
                </tr>
                <tr class="border-b border-gray-200">
                    <td class="py-2 font-semibold text-gray-700">Goods Received Value:</td>
                    <td class="text-right">৳<?php echo number_format($po->total_received_value, 2); ?></td>
                </tr>
                <tr class="border-b border-gray-200">
                    <td class="py-2 font-semibold text-gray-700">Previous Payments:</td>
                    <td class="text-right">৳<?php echo number_format($po->total_paid - $payment->amount_paid, 2); ?></td>
                </tr>
                <tr class="border-b border-gray-200">
                    <td class="py-2 font-semibold text-gray-700">Balance Before Payment:</td>
                    <td class="text-right">৳<?php echo number_format($balance_before, 2); ?></td>
                </tr>
                <tr class="border-b-2 border-gray-400 bg-yellow-50">
                    <td class="py-3 font-bold text-gray-900">AMOUNT PAID:</td>
                    <td class="text-right font-bold text-lg">৳<?php echo number_format($payment->amount_paid, 2); ?></td>
                </tr>
                <tr class="bg-primary-50">
                    <td class="py-3 font-bold text-gray-900">BALANCE AFTER PAYMENT:</td>
                    <td class="text-right font-bold text-lg <?php echo $balance_after > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                        ৳<?php echo number_format($balance_after, 2); ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Amount in Words -->
    <div class="bg-green-50 border border-green-300 rounded-lg p-4 mb-6">
        <p class="text-sm text-gray-700">
            <strong>Amount in words:</strong> 
            <span class="text-green-800 font-semibold">
                <?php
                // Simple number to words (you can replace with better library)
                $amount = floor($payment->amount_paid);
                echo "Taka " . ucwords(strtolower(NumberFormatter::create('en', NumberFormatter::SPELLOUT)->format($amount))) . " Only";
                ?>
            </span>
        </p>
    </div>

    <?php if ($payment->remarks): ?>
    <!-- Remarks -->
    <div class="mb-6">
        <h3 class="text-lg font-bold text-gray-900 mb-2">Remarks:</h3>
        <p class="text-gray-700 bg-gray-50 p-4 rounded border border-gray-200">
            <?php echo nl2br(htmlspecialchars($payment->remarks)); ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- Important Notice -->
    <div class="bg-blue-50 border border-blue-300 rounded-lg p-4 mb-6">
        <p class="text-sm text-blue-800">
            <i class="fas fa-info-circle"></i> 
            <strong>Note:</strong> This is an official payment receipt. Please keep it for your records. 
            <?php if (!$payment->is_posted): ?>
            <span class="text-red-600 font-semibold">This payment is pending journal posting.</span>
            <?php endif; ?>
        </p>
    </div>

    <!-- Signatures -->
    <div class="grid grid-cols-3 gap-8 mt-12 pt-6 border-t-2 border-gray-300">
        <div class="text-center">
            <div class="border-t-2 border-gray-400 pt-2 mt-16">
                <p class="font-semibold">Prepared By</p>
                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($payment->created_by_user_name ?? 'System'); ?></p>
                <p class="text-xs text-gray-500"><?php echo date('d M Y', strtotime($payment->created_at)); ?></p>
            </div>
        </div>
        <div class="text-center">
            <div class="border-t-2 border-gray-400 pt-2 mt-16">
                <p class="font-semibold">Verified By</p>
                <p class="text-sm text-gray-600">Accounts Manager</p>
            </div>
        </div>
        <div class="text-center">
            <div class="border-t-2 border-gray-400 pt-2 mt-16">
                <p class="font-semibold">Approved By</p>
                <p class="text-sm text-gray-600">Director</p>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="text-center mt-8 pt-4 border-t border-gray-300 text-sm text-gray-600">
        <p>This is a computer-generated document. Generated on <?php echo date('d M Y, h:i A'); ?></p>
        <p class="mt-1">Payment ID: <?php echo $payment->id; ?> | Document printed by: <?php echo getCurrentUser()['display_name']; ?></p>
        <?php if ($payment->journal_entry_id): ?>
        <p class="mt-1 text-green-600">✓ Journal Entry ID: <?php echo $payment->journal_entry_id; ?> | Posted</p>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>
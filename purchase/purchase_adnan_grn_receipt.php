<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
//ini_set('error_log', '/tmp/receipt_debug.log');

require_once '../core/init.php';
restrict_access(['Superadmin', 'superadmin','admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle = "GRN Receipt";

// Get GRN ID
$grn_id = $_GET['id'] ?? null;
if (!$grn_id) {
    $_SESSION['error'] = "GRN ID is required";
    redirect('purchase/purchase_adnan_index.php');
}

// Initialize manager
$grn_manager = new Goodsreceivedadnanmanager();
$po_manager = new Purchaseadnanmanager();

// Get GRN details
$grn = $grn_manager->getGRN($grn_id);
if (!$grn) {
    $_SESSION['error'] = "GRN not found";
    redirect('purchase/purchase_adnan_index.php');
}

// Get PO details
$po = $po_manager->getPurchaseOrder($grn->purchase_order_id);

// Get receiver user name if available
$receiver_name = 'System';
if (!empty($grn->receiver_user_id)) {
    $db = Database::getInstance()->getPdo();
    $stmt = $db->prepare("SELECT display_name FROM users WHERE id = ?");
    $stmt->execute([$grn->receiver_user_id]);
    $user = $stmt->fetch(PDO::FETCH_OBJ);
    if ($user) {
        $receiver_name = $user->display_name;
    }
}

// Calculate variance
$variance = 0;
$variance_percent = 0;
if ($grn->expected_quantity > 0) {
    $variance = $grn->quantity_received_kg - $grn->expected_quantity;
    $variance_percent = ($variance / $grn->expected_quantity) * 100;
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
        <h2 class="text-2xl font-bold text-gray-900">GRN Receipt</h2>
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
        <h2 class="text-2xl font-bold text-gray-900 mb-2">GOODS RECEIVED NOTE</h2>
        <div class="inline-block bg-green-100 text-green-800 px-4 py-2 rounded-lg">
            <strong>GRN #:</strong> <?php echo htmlspecialchars($grn->grn_number); ?>
        </div>
    </div>

    <!-- GRN Details Grid -->
    <div class="grid grid-cols-2 gap-6 mb-6">
        <!-- Left Column -->
        <div class="space-y-3">
            <div class="flex justify-between border-b border-gray-300 pb-2">
                <span class="font-semibold text-gray-700">GRN Date:</span>
                <span><?php echo date('d M Y', strtotime($grn->grn_date)); ?></span>
            </div>
            <div class="flex justify-between border-b border-gray-300 pb-2">
                <span class="font-semibold text-gray-700">PO Number:</span>
                <span><?php echo htmlspecialchars($po->po_number); ?></span>
            </div>
            <div class="flex justify-between border-b border-gray-300 pb-2">
                <span class="font-semibold text-gray-700">Supplier:</span>
                <span><?php echo htmlspecialchars($po->supplier_name); ?></span>
            </div>
            <div class="flex justify-between border-b border-gray-300 pb-2">
                <span class="font-semibold text-gray-700">Wheat Origin:</span>
                <span><?php echo htmlspecialchars($po->wheat_origin); ?></span>
            </div>
        </div>

        <!-- Right Column -->
        <div class="space-y-3">
            <div class="flex justify-between border-b border-gray-300 pb-2">
                <span class="font-semibold text-gray-700">Truck Number:</span>
                <span><?php echo htmlspecialchars($grn->truck_number ?? 'N/A'); ?></span>
            </div>
            <div class="flex justify-between border-b border-gray-300 pb-2">
                <span class="font-semibold text-gray-700">Unload Point:</span>
                <span><?php echo htmlspecialchars($grn->unload_point_name); ?></span>
            </div>
            <div class="flex justify-between border-b border-gray-300 pb-2">
                <span class="font-semibold text-gray-700">Unit Price:</span>
                <span>৳<?php echo number_format($po->unit_price_per_kg, 2); ?>/KG</span>
            </div>
            <div class="flex justify-between border-b border-gray-300 pb-2">
                <span class="font-semibold text-gray-700">Received By:</span>
                <span><?php echo htmlspecialchars($receiver_name); ?></span>
            </div>
        </div>
    </div>

    <!-- Quantity Details -->
    <div class="bg-gray-50 border border-gray-300 rounded-lg p-6 mb-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4">Quantity Details</h3>
        <table class="w-full">
            <thead>
                <tr class="border-b-2 border-gray-300">
                    <th class="text-left py-2">Description</th>
                    <th class="text-right py-2">Weight (KG)</th>
                    <th class="text-right py-2">Value (৳)</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($grn->expected_quantity > 0): ?>
                <tr class="border-b border-gray-200">
                    <td class="py-2">Expected Quantity</td>
                    <td class="text-right"><?php echo number_format($grn->expected_quantity, 2); ?></td>
                    <td class="text-right">৳<?php echo number_format($grn->expected_quantity * $po->unit_price_per_kg, 2); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="border-b border-gray-200 font-semibold">
                    <td class="py-2">Actual Quantity Received</td>
                    <td class="text-right"><?php echo number_format($grn->quantity_received_kg, 2); ?></td>
                    <td class="text-right">৳<?php echo number_format($grn->total_value, 2); ?></td>
                </tr>
                <?php if ($grn->expected_quantity > 0): ?>
                <tr class="<?php echo $variance > 0 ? 'text-green-700' : ($variance < 0 ? 'text-red-700' : 'text-gray-700'); ?>">
                    <td class="py-2 font-semibold">Variance</td>
                    <td class="text-right font-semibold">
                        <?php echo $variance > 0 ? '+' : ''; ?><?php echo number_format($variance, 2); ?>
                        (<?php echo $variance > 0 ? '+' : ''; ?><?php echo number_format($variance_percent, 2); ?>%)
                    </td>
                    <td class="text-right font-semibold">
                        <?php echo $variance > 0 ? '+' : ''; ?>৳<?php echo number_format($variance * $po->unit_price_per_kg, 2); ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Total Value -->
    <div class="bg-primary-50 border-2 border-primary-600 rounded-lg p-4 mb-6">
        <div class="flex justify-between items-center">
            <span class="text-lg font-bold text-gray-900">TOTAL RECEIVED VALUE:</span>
            <span class="text-2xl font-bold text-primary-600">৳<?php echo number_format($grn->total_value, 2); ?></span>
        </div>
    </div>

    <?php if ($grn->remarks): ?>
    <!-- Remarks -->
    <div class="mb-6">
        <h3 class="text-lg font-bold text-gray-900 mb-2">Remarks:</h3>
        <p class="text-gray-700 bg-gray-50 p-4 rounded border border-gray-200">
            <?php echo nl2br(htmlspecialchars($grn->remarks)); ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="grid grid-cols-3 gap-8 mt-12 pt-6 border-t-2 border-gray-300">
        <div class="text-center">
            <div class="border-t-2 border-gray-400 pt-2 mt-16">
                <p class="font-semibold">Received By</p>
                <p class="text-sm text-gray-600">Warehouse Staff</p>
            </div>
        </div>
        <div class="text-center">
            <div class="border-t-2 border-gray-400 pt-2 mt-16">
                <p class="font-semibold">Verified By</p>
                <p class="text-sm text-gray-600">Production Manager</p>
            </div>
        </div>
        <div class="text-center">
            <div class="border-t-2 border-gray-400 pt-2 mt-16">
                <p class="font-semibold">Approved By</p>
                <p class="text-sm text-gray-600">Accounts Department</p>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="text-center mt-8 pt-4 border-t border-gray-300 text-sm text-gray-600">
        <p>This is a computer-generated document. Generated on <?php echo date('d M Y, h:i A'); ?></p>
        <p class="mt-1">GRN ID: <?php echo $grn->id; ?> | Document printed by: <?php echo getCurrentUser()['display_name']; ?></p>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>
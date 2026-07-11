<?php
/**
 * Public read-only invoice verification (Feature #17).
 * Opened by scanning the QR code on a printed invoice. Shows a minimal summary
 * ONLY when the signature matches — not enumerable, no login, no sensitive detail
 * beyond what is already printed on the paper invoice the scanner is holding.
 */
require_once '../core/init.php';

global $db;

$inv = trim($_GET['inv'] ?? '');
$sig = trim($_GET['sig'] ?? '');
$order = null; $valid = false; $reason = '';

if ($inv === '' || $sig === '') {
    $reason = 'Missing verification parameters.';
} else {
    $order = $db->query(
        "SELECT co.order_number, co.total_amount, co.status, co.order_date, co.shipped_date,
                c.name AS customer_name
         FROM credit_orders co JOIN customers c ON c.id = co.customer_id
         WHERE co.order_number = ? LIMIT 1",
        [$inv]
    )->first();

    if (!$order) {
        $reason = 'No invoice found with this number.';
    } else {
        $inv_date = $order->shipped_date ?: $order->order_date;
        $expected = invoiceQrSignature($order->order_number, (float)$order->total_amount, (string)$inv_date);
        // Constant-time compare
        if (hash_equals($expected, $sig)) {
            $valid = true;
        } else {
            $reason = 'Signature does not match — this QR may be altered.';
            $order  = null;
        }
    }
}

$pageTitle = 'Invoice Verification';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Verification</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-lg overflow-hidden">
        <?php if ($valid && $order):
            $inv_date = $order->shipped_date ?: $order->order_date; ?>
        <div class="bg-green-600 text-white px-6 py-5 text-center">
            <div class="text-4xl mb-1">✓</div>
            <h1 class="text-lg font-bold">Verified Invoice</h1>
            <p class="text-green-100 text-sm">This matches an original record.</p>
        </div>
        <div class="p-6 space-y-3 text-sm">
            <div class="flex justify-between"><span class="text-gray-500">Invoice No.</span><span class="font-mono font-bold text-gray-900"><?php echo htmlspecialchars($order->order_number); ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Customer</span><span class="font-medium text-gray-800"><?php echo htmlspecialchars($order->customer_name); ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Date</span><span class="text-gray-800"><?php echo $inv_date ? date('d M Y', strtotime($inv_date)) : '—'; ?></span></div>
            <div class="flex justify-between border-t border-gray-100 pt-3"><span class="text-gray-500">Total Amount</span><span class="text-xl font-bold text-green-700">৳<?php echo number_format((float)$order->total_amount, 2); ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Status</span><span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700"><?php echo ucwords(str_replace('_',' ',$order->status)); ?></span></div>
        </div>
        <div class="px-6 pb-5 text-center text-[11px] text-gray-400">Ujjal Flour Mills — verified <?php echo date('d M Y, g:i A'); ?></div>
        <?php else: ?>
        <div class="bg-red-600 text-white px-6 py-5 text-center">
            <div class="text-4xl mb-1">✕</div>
            <h1 class="text-lg font-bold">Not Verified</h1>
        </div>
        <div class="p-6 text-center text-sm text-gray-600">
            <p><?php echo htmlspecialchars($reason ?: 'This invoice could not be verified.'); ?></p>
            <p class="text-xs text-gray-400 mt-3">If you believe this is an error, contact Ujjal Flour Mills.</p>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>

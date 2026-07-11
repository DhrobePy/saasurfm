<?php
/**
 * Delivery verification & confirmation (Feature #17).
 * Reached by scanning the QR on the dispatch slip. LOGIN REQUIRED — the person
 * confirming (driver/dispatch/admin) is recorded. Shows the order's products and
 * assigned driver/vehicle, and locks the order against a second delivery.
 */
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra',
                  'dispatch-srg', 'dispatch-demra', 'dispatchpos-srg', 'dispatchpos-demra',
                  'production manager-srg', 'production manager-demra'];
// Reached by scanning the dispatch-slip QR — accept the order-view / dispatch grant.
if (!userHasPageGrant('credit_sales', 'credit_order_view') && !userHasPageGrant('production', 'credit_dispatch')) {
    restrict_access($allowed_roles, 'credit_sales', 'verify_delivery');
}

global $db;
$currentUser = getCurrentUser();
$user_id     = (int)($currentUser['id'] ?? 0);
$pageTitle   = 'Confirm Delivery';

ensureDeliveryConfirmTable();

$inv = trim($_GET['inv'] ?? ($_POST['inv'] ?? ''));
$sig = trim($_GET['sig'] ?? ($_POST['sig'] ?? ''));
$error = null; $order = null; $confirmed = null;

/* ─── Resolve + validate the signed order ───────────────────── */
if ($inv === '' || $sig === '') {
    $error = 'Missing verification parameters.';
} else {
    $order = $db->query(
        "SELECT co.id, co.order_number, co.status, co.total_amount, co.order_date, co.shipped_date,
                c.name AS customer_name, c.phone_number, co.shipping_address,
                b.name AS branch_name
         FROM credit_orders co
         JOIN customers c ON c.id = co.customer_id
         LEFT JOIN branches b ON b.id = co.assigned_branch_id
         WHERE co.order_number = ? LIMIT 1",
        [$inv]
    )->first();
    if (!$order) {
        $error = 'No order found for this code.';
    } elseif (!hash_equals(deliveryQrSignature($order->order_number), $sig)) {
        $error = 'Invalid or altered QR code — this is not a genuine dispatch slip.';
        $order = null;
    }
}

/* ─── Existing confirmation (prevents double delivery) ──────── */
if ($order) {
    $confirmed = $db->query("SELECT * FROM cr_delivery_confirmations WHERE order_id = ?", [$order->id])->first();

    // Assigned driver/vehicle from the dispatch record
    $ship = $db->query("SELECT truck_number, driver_name FROM credit_order_shipping WHERE order_id = ?", [$order->id])->first();
    $assigned_driver  = $ship->driver_name   ?? '';
    $assigned_vehicle = $ship->truck_number  ?? '';

    // Product lines
    $items = $db->query(
        "SELECT coi.quantity, coi.unit_price, p.base_name AS product_name, pv.weight_variant, pv.grade
         FROM credit_order_items coi
         JOIN products p ON p.id = coi.product_id
         LEFT JOIN product_variants pv ON pv.id = coi.variant_id
         WHERE coi.order_id = ?",
        [$order->id]
    )->results();
}

/* ─── POST: confirm delivery (locks the order) ──────────────── */
if ($order && !$confirmed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_delivery') {
    $received_by = trim($_POST['received_by'] ?? '');
    $note        = trim($_POST['note'] ?? '');
    try {
        // UNIQUE(order_id) makes this atomic — a racing second confirm fails here.
        $db->query(
            "INSERT INTO cr_delivery_confirmations
                (order_id, order_number, confirmed_by_user_id, confirmed_by_name, driver_name, vehicle_number, received_by, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$order->id, $order->order_number, $user_id, $currentUser['display_name'] ?? 'staff',
             $assigned_driver ?: null, $assigned_vehicle ?: null, $received_by ?: null, $note ?: null]
        );
        // Mark the order delivered if it isn't already
        if (!in_array($order->status, ['delivered', 'cancelled'])) {
            $db->query("UPDATE credit_orders SET status = 'delivered' WHERE id = ?", [$order->id]);
            $order->status = 'delivered';
        }
        auditLogOrder('delivery_confirmed', (int)$order->id, $order->order_number,
            'Delivery confirmed by QR scan by ' . ($currentUser['display_name'] ?? 'staff')
            . ($received_by ? " — received by {$received_by}" : ''),
            ['driver' => $assigned_driver, 'vehicle' => $assigned_vehicle]);
        $confirmed = $db->query("SELECT * FROM cr_delivery_confirmations WHERE order_id = ?", [$order->id])->first();
    } catch (Exception $e) {
        // Duplicate key → someone confirmed it a moment ago
        $confirmed = $db->query("SELECT * FROM cr_delivery_confirmations WHERE order_id = ?", [$order->id])->first();
        if (!$confirmed) $error = 'Could not confirm delivery: ' . $e->getMessage();
    }
}

require_once '../templates/header.php';
?>
<div class="max-w-lg mx-auto px-4 py-6">

<?php if ($error): ?>
<div class="bg-white rounded-2xl shadow-lg overflow-hidden">
    <div class="bg-red-600 text-white px-6 py-6 text-center">
        <div class="text-5xl mb-1">✕</div><h1 class="text-lg font-bold">Not Verified</h1>
    </div>
    <div class="p-6 text-center text-sm text-gray-600"><?php echo htmlspecialchars($error); ?></div>
</div>

<?php elseif ($order): ?>
<div class="bg-white rounded-2xl shadow-lg overflow-hidden">
    <?php if ($confirmed): ?>
    <!-- Already delivered → block double delivery -->
    <div class="bg-amber-500 text-white px-6 py-5 text-center">
        <div class="text-5xl mb-1">⚠</div>
        <h1 class="text-lg font-bold">ALREADY DELIVERED</h1>
        <p class="text-amber-50 text-sm">This order was already delivered — do not deliver again.</p>
    </div>
    <div class="p-6 text-sm space-y-2">
        <div class="flex justify-between"><span class="text-gray-500">Confirmed by</span><span class="font-medium"><?php echo htmlspecialchars($confirmed->confirmed_by_name ?? '—'); ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">When</span><span><?php echo date('d M Y, g:i A', strtotime($confirmed->confirmed_at)); ?></span></div>
        <?php if ($confirmed->received_by): ?><div class="flex justify-between"><span class="text-gray-500">Received by</span><span><?php echo htmlspecialchars($confirmed->received_by); ?></span></div><?php endif; ?>
    </div>
    <?php else: ?>
    <div class="bg-green-600 text-white px-6 py-5 text-center">
        <div class="text-4xl mb-1">✓</div>
        <h1 class="text-lg font-bold">Genuine Dispatch</h1>
        <p class="text-green-100 text-sm">Verify the items and driver, then confirm delivery.</p>
    </div>
    <?php endif; ?>

    <div class="px-6 py-4 border-t border-gray-100 space-y-2 text-sm">
        <div class="flex justify-between"><span class="text-gray-500">Invoice No.</span><span class="font-mono font-bold"><?php echo htmlspecialchars($order->order_number); ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Customer</span><span class="font-medium"><?php echo htmlspecialchars($order->customer_name); ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Total</span><span class="font-bold text-gray-800">৳<?php echo number_format((float)$order->total_amount, 2); ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Assigned Driver</span><span class="font-medium <?php echo $assigned_driver ? '' : 'text-amber-500'; ?>"><?php echo htmlspecialchars($assigned_driver ?: 'not set'); ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Vehicle</span><span class="font-medium <?php echo $assigned_vehicle ? '' : 'text-amber-500'; ?>"><?php echo htmlspecialchars($assigned_vehicle ?: 'not set'); ?></span></div>
    </div>

    <div class="px-6 py-3 border-t border-gray-100">
        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Items to deliver</p>
        <table class="w-full text-sm">
            <?php foreach ($items as $it): ?>
            <tr class="border-b border-gray-50">
                <td class="py-1.5"><?php echo htmlspecialchars(trim($it->product_name . ' ' . ($it->weight_variant ?? '') . ($it->grade ? ' · ' . $it->grade : ''))); ?></td>
                <td class="py-1.5 text-right font-semibold whitespace-nowrap"><?php echo rtrim(rtrim(number_format((float)$it->quantity, 3), '0'), '.'); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php if (!$confirmed): ?>
    <form method="POST" class="px-6 py-4 border-t border-gray-100 space-y-3"
          onsubmit="return confirm('Confirm this delivery? This locks the order and cannot be undone.');">
        <input type="hidden" name="action" value="confirm_delivery">
        <input type="hidden" name="inv" value="<?php echo htmlspecialchars($order->order_number); ?>">
        <input type="hidden" name="sig" value="<?php echo htmlspecialchars($sig); ?>">
        <input type="text" name="received_by" maxlength="150" placeholder="Received by (customer name, optional)"
               class="w-full px-3 py-2 border rounded-lg text-sm">
        <input type="text" name="note" maxlength="500" placeholder="Note (optional)"
               class="w-full px-3 py-2 border rounded-lg text-sm">
        <button type="submit" class="w-full py-3 bg-green-600 text-white font-bold rounded-lg hover:bg-green-700">
            <i class="fas fa-check-circle mr-1"></i>Confirm Delivery
        </button>
        <p class="text-[11px] text-gray-400 text-center">Confirming as <strong><?php echo htmlspecialchars($currentUser['display_name'] ?? 'staff'); ?></strong>. Check the items and driver/vehicle match before confirming.</p>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>
<?php require_once '../templates/footer.php'; ?>

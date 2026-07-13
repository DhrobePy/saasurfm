<?php
/**
 * Dispatch QR — two stages (Feature #17):
 *   1) GATE PASS   — scanned as goods leave the factory gate. Verifies the order is
 *      genuine + CLEARED for dispatch (enforces the payment/dispatch hold at the
 *      gate), records who released it, driver & vehicle. Nothing uncleared leaves.
 *   2) DELIVERY    — scanned at the customer. Confirms receipt and LOCKS the order
 *      against a second delivery.
 * LOGIN REQUIRED — every scan records who did it.
 */
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra',
                  'dispatch-srg', 'dispatch-demra', 'dispatchpos-srg', 'dispatchpos-demra',
                  'production manager-srg', 'production manager-demra', 'security', 'gate'];
// Reached by scanning the dispatch-slip QR — accept the order-view / dispatch grant.
if (!userHasPageGrant('credit_sales', 'credit_order_view') && !userHasPageGrant('production', 'credit_dispatch')) {
    restrict_access($allowed_roles, 'credit_sales', 'verify_delivery');
}

global $db;
$currentUser = getCurrentUser();
$user_id     = (int)($currentUser['id'] ?? 0);
$user_name   = $currentUser['display_name'] ?? 'staff';
$pageTitle   = 'Gate Pass & Delivery';

ensureDeliveryConfirmTable();

$inv = trim($_GET['inv'] ?? ($_POST['inv'] ?? ''));
$sig = trim($_GET['sig'] ?? ($_POST['sig'] ?? ''));
$error = null; $flash = null; $order = null;

/* ─── Resolve + validate the signed order ───────────────────── */
if ($inv === '' || $sig === '') {
    $error = 'Missing verification parameters.';
} else {
    $order = $db->query(
        "SELECT co.id, co.order_number, co.status, co.total_amount, co.order_date,
                c.name AS customer_name, c.phone_number, co.shipping_address, b.name AS branch_name
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

$conf = null; $items = []; $assigned_driver = ''; $assigned_vehicle = ''; $dispatch_ok = false;
$loadState = function () use ($db, &$order, &$conf) {
    $conf = $order ? $db->query("SELECT * FROM cr_delivery_confirmations WHERE order_id = ?", [$order->id])->first() : null;
};
if ($order) {
    $loadState();
    $ship = $db->query("SELECT truck_number, driver_name FROM credit_order_shipping WHERE order_id = ?", [$order->id])->first();
    $assigned_driver  = $ship->driver_name  ?? '';
    $assigned_vehicle = $ship->truck_number ?? '';
    $items = $db->query(
        "SELECT coi.quantity, p.base_name AS product_name, pv.weight_variant, pv.grade
         FROM credit_order_items coi
         JOIN products p ON p.id = coi.product_id
         LEFT JOIN product_variants pv ON pv.id = coi.variant_id
         WHERE coi.order_id = ?",
        [$order->id]
    )->results();
    $dispatch_ok = orderDispatchAllowed((int)$order->id);   // dispatch hold cleared?
}

$gate_done = $order && $conf && !empty($conf->gate_out_at);
$delivered = $order && $conf && !empty($conf->confirmed_at);

/* ─── POST 1: GATE PASS (goods leaving the gate) ─────────────── */
if ($order && !$gate_done && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_gate') {
    $blocked_status = in_array($order->status, ['draft', 'pending_approval', 'escalated', 'rejected', 'cancelled']);
    if ($blocked_status) {
        $error = 'This order is not ready to dispatch (status: ' . str_replace('_', ' ', $order->status) . ').';
    } elseif (!$dispatch_ok) {
        $error = 'DO NOT RELEASE — this order is HELD and not cleared for dispatch. Clear it in Payment Watch first.';
    } else {
        // Driver & vehicle are captured/confirmed AT THE GATE (accurate tracking).
        $gate_driver  = trim($_POST['gate_driver'] ?? '');
        $gate_vehicle = trim($_POST['gate_vehicle'] ?? '');
        $gate_note    = trim($_POST['gate_note'] ?? '');
        if ($gate_driver === '' || $gate_vehicle === '') {
            $error = 'Enter the driver name and vehicle number to release the goods.';
        } else {
            try {
                $db->query(
                    "INSERT INTO cr_delivery_confirmations
                        (order_id, order_number, gate_out_at, gate_out_by_user_id, gate_out_by_name, driver_name, vehicle_number, gate_note)
                     VALUES (?, ?, NOW(), ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        gate_out_at = NOW(), gate_out_by_user_id = VALUES(gate_out_by_user_id),
                        gate_out_by_name = VALUES(gate_out_by_name), driver_name = VALUES(driver_name),
                        vehicle_number = VALUES(vehicle_number), gate_note = VALUES(gate_note)",
                    [$order->id, $order->order_number, $user_id, $user_name,
                     $gate_driver, $gate_vehicle, $gate_note ?: null]
                );
                // If the gate driver/vehicle differ from what was assigned, keep the
                // dispatch record in step so the whole system shows the actual truck.
                if ($gate_driver !== $assigned_driver || $gate_vehicle !== $assigned_vehicle) {
                    try {
                        $db->query(
                            "UPDATE credit_order_shipping SET driver_name = ?, truck_number = ? WHERE order_id = ?",
                            [$gate_driver, $gate_vehicle, $order->id]
                        );
                    } catch (Exception $e) { /* shipping row may not exist yet — non-fatal */ }
                }
                // Physically left the gate → mark shipped if it hasn't been already.
                if (in_array($order->status, ['approved', 'in_production', 'produced', 'ready_to_ship'])) {
                    $db->query("UPDATE credit_orders SET status = 'shipped' WHERE id = ?", [$order->id]);
                    $order->status = 'shipped';
                }
                auditLogOrder('gate_out', (int)$order->id, $order->order_number,
                    "Gate pass — goods released at gate by {$user_name} (driver {$gate_driver}, vehicle {$gate_vehicle})",
                    ['driver' => $gate_driver, 'vehicle' => $gate_vehicle]);
                $flash = 'Gate pass recorded — goods released. Scan again at the customer to confirm delivery.';
                $loadState(); $gate_done = true;
                $assigned_driver = $gate_driver; $assigned_vehicle = $gate_vehicle;
            } catch (Exception $e) {
                $loadState(); $gate_done = $conf && !empty($conf->gate_out_at);
                if (!$gate_done) $error = 'Could not record gate pass: ' . $e->getMessage();
            }
        }
    }
}

/* ─── POST 2: DELIVERY confirmation (at the customer) ────────── */
if ($order && $gate_done && !$delivered && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_delivery') {
    $received_by = trim($_POST['received_by'] ?? '');
    $note        = trim($_POST['note'] ?? '');
    try {
        $db->query(
            "UPDATE cr_delivery_confirmations
             SET confirmed_at = NOW(), confirmed_by_user_id = ?, confirmed_by_name = ?, received_by = ?, note = ?
             WHERE order_id = ? AND confirmed_at IS NULL",
            [$user_id, $user_name, $received_by ?: null, $note ?: null, $order->id]
        );
        if (!in_array($order->status, ['delivered', 'cancelled'])) {
            $db->query("UPDATE credit_orders SET status = 'delivered' WHERE id = ?", [$order->id]);
            $order->status = 'delivered';
        }
        auditLogOrder('delivery_confirmed', (int)$order->id, $order->order_number,
            'Delivery confirmed by QR scan by ' . $user_name . ($received_by ? " — received by {$received_by}" : ''),
            ['driver' => $assigned_driver, 'vehicle' => $assigned_vehicle]);
        $flash = 'Delivery confirmed. This order is now locked.';
        $loadState(); $delivered = true;
    } catch (Exception $e) {
        $loadState(); $delivered = $conf && !empty($conf->confirmed_at);
        if (!$delivered) $error = 'Could not confirm delivery: ' . $e->getMessage();
    }
}

// Current stage after any action
$stage = !$order ? 'error' : ($delivered ? 'done' : ($gate_done ? 'delivery' : 'gate'));

require_once '../templates/header.php';
?>
<div class="max-w-lg mx-auto px-4 py-6">

<?php if ($flash): ?>
<div class="mb-3 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm"><i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars($flash); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-3 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm"><i class="fas fa-exclamation-triangle mr-1"></i><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($stage === 'error'): ?>
<div class="bg-white rounded-2xl shadow-lg overflow-hidden">
    <div class="bg-red-600 text-white px-6 py-6 text-center"><div class="text-5xl mb-1">✕</div><h1 class="text-lg font-bold">Not Verified</h1></div>
    <div class="p-6 text-center text-sm text-gray-600"><?php echo htmlspecialchars($error ?: 'Could not verify this code.'); ?></div>
</div>

<?php else: ?>
<div class="bg-white rounded-2xl shadow-lg overflow-hidden">

    <?php /* ── Header band per stage ── */ ?>
    <?php if ($stage === 'done'): ?>
    <div class="bg-gray-700 text-white px-6 py-5 text-center">
        <div class="text-4xl mb-1">✓</div><h1 class="text-lg font-bold">COMPLETED</h1>
        <p class="text-gray-200 text-sm">Gate pass &amp; delivery both recorded.</p>
    </div>
    <?php elseif ($stage === 'delivery'): ?>
    <div class="bg-green-600 text-white px-6 py-5 text-center">
        <div class="text-4xl mb-1">📦</div><h1 class="text-lg font-bold">CONFIRM DELIVERY</h1>
        <p class="text-green-100 text-sm">Goods already left the gate — confirm the customer received them.</p>
    </div>
    <?php elseif (!$dispatch_ok): ?>
    <div class="bg-red-600 text-white px-6 py-5 text-center">
        <div class="text-4xl mb-1">⛔</div><h1 class="text-lg font-bold">HELD — DO NOT RELEASE</h1>
        <p class="text-red-100 text-sm">This order is not cleared for dispatch.</p>
    </div>
    <?php else: ?>
    <div class="bg-blue-600 text-white px-6 py-5 text-center">
        <div class="text-4xl mb-1">🚪</div><h1 class="text-lg font-bold">GATE PASS</h1>
        <p class="text-blue-100 text-sm">Verify the load &amp; driver, then release the goods.</p>
    </div>
    <?php endif; ?>

    <?php /* ── Order facts ── */ ?>
    <div class="px-6 py-4 border-t border-gray-100 space-y-2 text-sm">
        <div class="flex justify-between"><span class="text-gray-500">Invoice No.</span><span class="font-mono font-bold"><?php echo htmlspecialchars($order->order_number); ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Customer</span><span class="font-medium"><?php echo htmlspecialchars($order->customer_name); ?></span></div>
        <?php if ($order->branch_name): ?><div class="flex justify-between"><span class="text-gray-500">From</span><span><?php echo htmlspecialchars($order->branch_name); ?></span></div><?php endif; ?>
        <div class="flex justify-between"><span class="text-gray-500">Total</span><span class="font-bold text-gray-800">৳<?php echo number_format((float)$order->total_amount, 2); ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Assigned Driver</span><span class="font-medium <?php echo $assigned_driver ? '' : 'text-amber-500'; ?>"><?php echo htmlspecialchars($assigned_driver ?: 'not set'); ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Vehicle</span><span class="font-medium <?php echo $assigned_vehicle ? '' : 'text-amber-500'; ?>"><?php echo htmlspecialchars($assigned_vehicle ?: 'not set'); ?></span></div>
    </div>

    <?php /* ── Progress ── */ ?>
    <div class="px-6 py-2 flex items-center gap-2 text-[11px]">
        <span class="px-2 py-0.5 rounded-full font-bold <?php echo $gate_done ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700'; ?>">
            <?php echo $gate_done ? '✓ Gate out' : '1 · Gate out'; ?>
        </span>
        <span class="text-gray-300">→</span>
        <span class="px-2 py-0.5 rounded-full font-bold <?php echo $delivered ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400'; ?>">
            <?php echo $delivered ? '✓ Delivered' : '2 · Delivered'; ?>
        </span>
    </div>

    <?php /* ── Items ── */ ?>
    <div class="px-6 py-2">
        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Items</p>
        <table class="w-full text-sm">
            <?php foreach ($items as $it): ?>
            <tr class="border-b border-gray-50">
                <td class="py-1.5"><?php echo htmlspecialchars(trim($it->product_name . ' ' . ($it->weight_variant ?? '') . ($it->grade ? ' · ' . $it->grade : ''))); ?></td>
                <td class="py-1.5 text-right font-semibold whitespace-nowrap"><?php echo rtrim(rtrim(number_format((float)$it->quantity, 3), '0'), '.'); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php /* ── Stage actions ── */ ?>
    <?php if ($stage === 'gate'): ?>
        <?php if (!$dispatch_ok): ?>
        <div class="px-6 py-4 border-t border-gray-100 text-sm text-red-700 bg-red-50">
            <i class="fas fa-lock mr-1"></i>This order is <strong>held</strong>. It cannot leave until Accounts/Admin clears the dispatch hold in <strong>Payment Watch</strong>.
        </div>
        <?php else: ?>
        <?php $f_driver  = trim($_POST['gate_driver']  ?? '') ?: $assigned_driver;
              $f_vehicle = trim($_POST['gate_vehicle'] ?? '') ?: $assigned_vehicle; ?>
        <form method="POST" class="px-6 py-4 border-t border-gray-100 space-y-3"
              onsubmit="return confirm('Release these goods at the gate? This records the gate pass.');">
            <input type="hidden" name="action" value="confirm_gate">
            <input type="hidden" name="inv" value="<?php echo htmlspecialchars($order->order_number); ?>">
            <input type="hidden" name="sig" value="<?php echo htmlspecialchars($sig); ?>">
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Driver name <span class="text-red-500">*</span></label>
                <input type="text" name="gate_driver" required maxlength="150" value="<?php echo htmlspecialchars($f_driver); ?>"
                       placeholder="Driver at the gate" class="w-full px-3 py-2 border rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Vehicle / Truck no. <span class="text-red-500">*</span></label>
                <input type="text" name="gate_vehicle" required maxlength="100" value="<?php echo htmlspecialchars($f_vehicle); ?>"
                       placeholder="Vehicle leaving the gate" class="w-full px-3 py-2 border rounded-lg text-sm">
            </div>
            <input type="text" name="gate_note" maxlength="500" placeholder="Gate note (optional — e.g. seal no., discrepancy)" class="w-full px-3 py-2 border rounded-lg text-sm">
            <button type="submit" class="w-full py-3 bg-blue-600 text-white font-bold rounded-lg hover:bg-blue-700"><i class="fas fa-door-open mr-1"></i>Confirm Gate Pass (release goods)</button>
            <p class="text-[11px] text-gray-400 text-center">Releasing as <strong><?php echo htmlspecialchars($user_name); ?></strong>. Confirm the driver &amp; vehicle match the truck at the gate.</p>
        </form>
        <?php endif; ?>

    <?php elseif ($stage === 'delivery'): ?>
        <div class="px-6 pt-1 pb-2 text-[11px] text-gray-500">Released at gate by <strong><?php echo htmlspecialchars($conf->gate_out_by_name ?? 'staff'); ?></strong> on <?php echo date('d M Y, g:i A', strtotime($conf->gate_out_at)); ?><?php echo !empty($conf->gate_note) ? ' · ' . htmlspecialchars($conf->gate_note) : ''; ?>.</div>
        <form method="POST" class="px-6 py-4 border-t border-gray-100 space-y-3"
              onsubmit="return confirm('Confirm this delivery? This locks the order.');">
            <input type="hidden" name="action" value="confirm_delivery">
            <input type="hidden" name="inv" value="<?php echo htmlspecialchars($order->order_number); ?>">
            <input type="hidden" name="sig" value="<?php echo htmlspecialchars($sig); ?>">
            <input type="text" name="received_by" maxlength="150" placeholder="Received by (customer name, optional)" class="w-full px-3 py-2 border rounded-lg text-sm">
            <input type="text" name="note" maxlength="500" placeholder="Note (optional)" class="w-full px-3 py-2 border rounded-lg text-sm">
            <button type="submit" class="w-full py-3 bg-green-600 text-white font-bold rounded-lg hover:bg-green-700"><i class="fas fa-check-circle mr-1"></i>Confirm Delivery</button>
            <p class="text-[11px] text-gray-400 text-center">Confirming as <strong><?php echo htmlspecialchars($user_name); ?></strong>.</p>
        </form>

    <?php else: /* done */ ?>
        <div class="px-6 py-4 border-t border-gray-100 text-sm space-y-1.5">
            <div class="flex justify-between"><span class="text-gray-500">Gate out</span><span><?php echo htmlspecialchars($conf->gate_out_by_name ?? '—'); ?> · <?php echo $conf->gate_out_at ? date('d M Y, g:i A', strtotime($conf->gate_out_at)) : '—'; ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Delivered</span><span><?php echo htmlspecialchars($conf->confirmed_by_name ?? '—'); ?> · <?php echo $conf->confirmed_at ? date('d M Y, g:i A', strtotime($conf->confirmed_at)) : '—'; ?></span></div>
            <?php if (!empty($conf->received_by)): ?><div class="flex justify-between"><span class="text-gray-500">Received by</span><span><?php echo htmlspecialchars($conf->received_by); ?></span></div><?php endif; ?>
            <p class="text-[11px] text-amber-600 mt-2"><i class="fas fa-lock mr-1"></i>This order is locked — it cannot be delivered again.</p>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>
<?php require_once '../templates/footer.php'; ?>
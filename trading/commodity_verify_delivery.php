<?php
/**
 * Commodity Dispatch QR — two stages, adapted from cr/verify_delivery.php:
 *   1) GATE PASS — scanned as goods leave the gate. Records driver & vehicle.
 *   2) DELIVERY  — scanned at the customer. Confirms receipt and LOCKS the
 *      sale against a second delivery.
 * LOGIN REQUIRED — every scan records who did it. No dispatch-hold gate here
 * (commodity trading doesn't have Payment Watch-style clearance — any posted
 * sale can be released).
 */
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra',
                  'dispatch-srg', 'dispatch-demra', 'dispatchpos-srg', 'dispatchpos-demra', 'security', 'gate'];
if (!userHasPageGrant('trading', 'commodity_dispatch')) {
    restrict_access($allowed_roles, 'trading', 'commodity_dispatch');
}

global $db;
$currentUser = getCurrentUser();
$user_id     = (int)($currentUser['id'] ?? 0);
$user_name   = $currentUser['display_name'] ?? 'staff';
$pageTitle   = 'Commodity Gate Pass & Delivery';

ensureCommodityDispatchConfirmTable();

$inv = trim($_GET['inv'] ?? ($_POST['inv'] ?? ''));
$sig = trim($_GET['sig'] ?? ($_POST['sig'] ?? ''));
$error = null; $flash = null; $sale = null;

if ($inv === '' || $sig === '') {
    $error = 'Missing verification parameters.';
} else {
    $sale = $db->query(
        "SELECT cs.id, cs.sale_number, cs.status, cs.total_amount, cs.sale_date, cs.quantity,
                pc.name AS commodity_name, pc.unit, c.name AS customer_name, c.phone_number, b.name AS branch_name
         FROM commodity_sales cs
         JOIN customers c ON c.id = cs.customer_id
         JOIN purchase_commodities pc ON pc.id = cs.commodity_id
         LEFT JOIN branches b ON b.id = cs.branch_id
         WHERE cs.sale_number = ? LIMIT 1",
        [$inv]
    )->first();
    if (!$sale) {
        $error = 'No commodity sale found for this code.';
    } elseif (!hash_equals(commodityDeliveryQrSignature($sale->sale_number), $sig)) {
        $error = 'Invalid or altered QR code — this is not a genuine gate pass.';
        $sale = null;
    }
}

$conf = null;
$loadState = function () use ($db, &$sale, &$conf) {
    $conf = $sale ? $db->query("SELECT * FROM commodity_dispatch_confirmations WHERE sale_id = ?", [$sale->id])->first() : null;
};
if ($sale) $loadState();

$gate_done = $sale && $conf && !empty($conf->gate_out_at);
$delivered = $sale && $conf && !empty($conf->confirmed_at);

/* ─── POST 1: GATE PASS ─────────────────────────────────────── */
if ($sale && !$gate_done && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_gate') {
    if (in_array($sale->status, ['pending_approval', 'rejected'])) {
        $error = 'This sale is not ready to dispatch (status: ' . str_replace('_', ' ', $sale->status) . ').';
    } else {
        $gate_driver  = trim($_POST['gate_driver'] ?? '');
        $gate_vehicle = trim($_POST['gate_vehicle'] ?? '');
        $gate_note    = trim($_POST['gate_note'] ?? '');
        if ($gate_driver === '' || $gate_vehicle === '') {
            $error = 'Enter the driver name and vehicle number to release the goods.';
        } else {
            try {
                $db->query(
                    "INSERT INTO commodity_dispatch_confirmations
                        (sale_id, sale_number, gate_out_at, gate_out_by_user_id, gate_out_by_name, driver_name, vehicle_number, gate_note)
                     VALUES (?, ?, NOW(), ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        gate_out_at = NOW(), gate_out_by_user_id = VALUES(gate_out_by_user_id),
                        gate_out_by_name = VALUES(gate_out_by_name), driver_name = VALUES(driver_name),
                        vehicle_number = VALUES(vehicle_number), gate_note = VALUES(gate_note)",
                    [$sale->id, $sale->sale_number, $user_id, $user_name, $gate_driver, $gate_vehicle, $gate_note ?: null]
                );
                auditLog('other', 'updated',
                    "Commodity gate pass — goods released at gate by {$user_name} for {$sale->sale_number} (driver {$gate_driver}, vehicle {$gate_vehicle})",
                    ['sale_id' => (int)$sale->id, 'driver' => $gate_driver, 'vehicle' => $gate_vehicle]);
                $flash = 'Gate pass recorded — goods released. Scan again at the customer to confirm delivery.';
                $loadState(); $gate_done = true;
            } catch (Exception $e) {
                $loadState(); $gate_done = $conf && !empty($conf->gate_out_at);
                if (!$gate_done) $error = 'Could not record gate pass: ' . $e->getMessage();
            }
        }
    }
}

/* ─── POST 2: DELIVERY confirmation ─────────────────────────── */
if ($sale && $gate_done && !$delivered && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_delivery') {
    $received_by = trim($_POST['received_by'] ?? '');
    $note        = trim($_POST['note'] ?? '');
    try {
        $db->query(
            "UPDATE commodity_dispatch_confirmations
             SET confirmed_at = NOW(), confirmed_by_user_id = ?, confirmed_by_name = ?, received_by = ?, note = ?
             WHERE sale_id = ? AND confirmed_at IS NULL",
            [$user_id, $user_name, $received_by ?: null, $note ?: null, $sale->id]
        );
        auditLog('other', 'updated',
            "Commodity delivery confirmed by QR scan by {$user_name} for {$sale->sale_number}" . ($received_by ? " — received by {$received_by}" : ''),
            ['sale_id' => (int)$sale->id]);
        $flash = 'Delivery confirmed. This sale is now locked.';
        $loadState(); $delivered = true;
    } catch (Exception $e) {
        $loadState(); $delivered = $conf && !empty($conf->confirmed_at);
        if (!$delivered) $error = 'Could not confirm delivery: ' . $e->getMessage();
    }
}

$stage = !$sale ? 'error' : ($delivered ? 'done' : ($gate_done ? 'delivery' : 'gate'));

$scan_total = ($sale && $_SERVER['REQUEST_METHOD'] === 'GET')
    ? recordCommodityQrScan((int)$sale->id, $sale->sale_number, $stage, $user_name)
    : 0;
$is_reuse_scan = ($sale && $_SERVER['REQUEST_METHOD'] === 'GET' && $stage === 'done');

require_once '../templates/header.php';
?>
<div class="max-w-lg mx-auto px-4 py-6">

<?php if ($flash): ?>
<div class="mb-3 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm"><i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars($flash); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-3 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm"><i class="fas fa-exclamation-triangle mr-1"></i><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if (!empty($is_reuse_scan)): ?>
<div class="mb-3 p-3 rounded-lg bg-red-50 border-2 border-red-300 text-red-800 text-sm">
    <i class="fas fa-triangle-exclamation mr-1"></i><strong>This QR has already been used.</strong>
    Scanned <?php echo (int)$scan_total; ?> time(s) — admins have been notified.
</div>
<?php endif; ?>

<?php if ($stage === 'error'): ?>
<div class="bg-white rounded-2xl shadow-lg overflow-hidden">
    <div class="bg-red-600 text-white px-6 py-6 text-center"><div class="text-5xl mb-1">✕</div><h1 class="text-lg font-bold">Not Verified</h1></div>
    <div class="p-6 text-center text-sm text-gray-600"><?php echo htmlspecialchars($error ?: 'Could not verify this code.'); ?></div>
</div>

<?php else: ?>
<div class="bg-white rounded-2xl shadow-lg overflow-hidden">

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
    <?php else: ?>
    <div class="bg-rose-600 text-white px-6 py-5 text-center">
        <div class="text-4xl mb-1">🚪</div><h1 class="text-lg font-bold">GATE PASS</h1>
        <p class="text-rose-100 text-sm">Verify the load &amp; driver, then release the goods.</p>
    </div>
    <?php endif; ?>

    <div class="px-6 py-4 border-t border-gray-100 space-y-2 text-sm">
        <div class="flex justify-between"><span class="text-gray-500">Gate Pass No. (Sale)</span><span class="font-mono font-bold"><?php echo htmlspecialchars($sale->sale_number); ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Customer</span><span class="font-medium"><?php echo htmlspecialchars($sale->customer_name); ?></span></div>
        <?php if ($sale->branch_name): ?><div class="flex justify-between"><span class="text-gray-500">From</span><span><?php echo htmlspecialchars($sale->branch_name); ?></span></div><?php endif; ?>
        <div class="flex justify-between"><span class="text-gray-500">Commodity</span><span class="font-medium"><?php echo htmlspecialchars($sale->commodity_name); ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Quantity</span><span class="font-medium"><?php echo number_format((float)$sale->quantity, 3); ?> <?php echo htmlspecialchars($sale->unit); ?></span></div>
    </div>

    <div class="px-6 py-2 flex items-center gap-2 text-[11px]">
        <span class="px-2 py-0.5 rounded-full font-bold <?php echo $gate_done ? 'bg-green-100 text-green-700' : 'bg-rose-100 text-rose-700'; ?>">
            <?php echo $gate_done ? '✓ Gate out' : '1 · Gate out'; ?>
        </span>
        <span class="text-gray-300">→</span>
        <span class="px-2 py-0.5 rounded-full font-bold <?php echo $delivered ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400'; ?>">
            <?php echo $delivered ? '✓ Delivered' : '2 · Delivered'; ?>
        </span>
    </div>

    <?php if ($stage === 'gate'): ?>
        <?php $f_driver  = trim($_POST['gate_driver']  ?? '');
              $f_vehicle = trim($_POST['gate_vehicle'] ?? ''); ?>
        <form method="POST" class="px-6 py-4 border-t border-gray-100 space-y-3"
              onsubmit="return confirm('Release these goods at the gate? This records the gate pass.');">
            <input type="hidden" name="action" value="confirm_gate">
            <input type="hidden" name="inv" value="<?php echo htmlspecialchars($sale->sale_number); ?>">
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
            <input type="text" name="gate_note" maxlength="500" placeholder="Gate note (optional — e.g. seal no.)" class="w-full px-3 py-2 border rounded-lg text-sm">
            <button type="submit" class="w-full py-3 bg-rose-600 text-white font-bold rounded-lg hover:bg-rose-700"><i class="fas fa-door-open mr-1"></i>Confirm Gate Pass (release goods)</button>
            <p class="text-[11px] text-gray-400 text-center">Releasing as <strong><?php echo htmlspecialchars($user_name); ?></strong>.</p>
        </form>

    <?php elseif ($stage === 'delivery'): ?>
        <div class="px-6 pt-1 pb-2 text-[11px] text-gray-500">Released at gate by <strong><?php echo htmlspecialchars($conf->gate_out_by_name ?? 'staff'); ?></strong> on <?php echo date('d M Y, g:i A', strtotime($conf->gate_out_at)); ?><?php echo !empty($conf->gate_note) ? ' · ' . htmlspecialchars($conf->gate_note) : ''; ?>.</div>
        <form method="POST" class="px-6 py-4 border-t border-gray-100 space-y-3"
              onsubmit="return confirm('Confirm this delivery? This locks the sale.');">
            <input type="hidden" name="action" value="confirm_delivery">
            <input type="hidden" name="inv" value="<?php echo htmlspecialchars($sale->sale_number); ?>">
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
            <?php if ($scan_total > 0): ?><div class="flex justify-between"><span class="text-gray-500">QR scanned</span><span class="<?php echo $scan_total > 2 ? 'text-red-600 font-semibold' : ''; ?>"><?php echo (int)$scan_total; ?> time(s)</span></div><?php endif; ?>
            <p class="text-[11px] text-amber-600 mt-2"><i class="fas fa-lock mr-1"></i>This sale is locked — it cannot be delivered again.</p>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>
<?php require_once '../templates/footer.php'; ?>

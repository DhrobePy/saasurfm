<?php
/**
 * POS Exit QR — single-stage gate-pass check (Jul 2026).
 * A POS sale is a walk-out counter handoff, not a two-stage truck journey like
 * Credit Sales dispatch — one scan by security/gate staff confirms the goods
 * being carried out match a paid sale. LOGIN REQUIRED — every scan records who did it.
 */
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'accountspos-demra', 'accountspos-srg', 'dispatchpos-demra', 'dispatchpos-srg', 'security', 'gate'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id     = (int)($currentUser['id'] ?? 0);
$user_name   = $currentUser['display_name'] ?? 'staff';
$pageTitle   = 'POS Exit Verification';

ensurePosExitTables();

$order_number = trim($_GET['order'] ?? ($_POST['order'] ?? ''));
$sig = trim($_GET['sig'] ?? ($_POST['sig'] ?? ''));
$error = null; $flash = null; $order = null;

if ($order_number === '' || $sig === '') {
    $error = 'Missing verification parameters.';
} else {
    $order = $db->query(
        "SELECT o.id, o.order_number, o.total_amount, o.cash_paid, o.credit_amount, o.payment_method,
                o.payment_status, o.order_date, o.branch_id, b.name AS branch_name,
                c.name AS customer_name, c.phone_number AS customer_phone
         FROM orders o
         LEFT JOIN branches b ON b.id = o.branch_id
         LEFT JOIN customers c ON c.id = o.customer_id
         WHERE o.order_number = ? AND o.order_type = 'POS' LIMIT 1",
        [$order_number]
    )->first();
    if (!$order) {
        $error = 'No POS order found for this code.';
    } elseif (!hash_equals(posExitQrSignature($order->order_number), $sig)) {
        $error = 'Invalid or altered QR code — this is not a genuine POS receipt.';
        $order = null;
    }
}

$verif = null;
if ($order) {
    $verif = $db->query("SELECT * FROM pos_exit_verifications WHERE order_id = ?", [$order->id])->first();
}
$already_verified = $order && $verif && !empty($verif->verified_at);

$items = [];
if ($order) {
    $items = $db->query(
        "SELECT oi.quantity, oi.unit_price, oi.total_amount, p.base_name, pv.weight_variant, pv.unit_of_measure
         FROM order_items oi
         JOIN product_variants pv ON pv.id = oi.variant_id
         JOIN products p ON p.id = pv.product_id
         WHERE oi.order_id = ?",
        [$order->id]
    )->results();
}

/* ─── POST: confirm exit ─────────────────────────────────────── */
if ($order && !$already_verified && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_exit') {
    $note = trim($_POST['note'] ?? '');
    try {
        if ($verif) {
            $db->query(
                "UPDATE pos_exit_verifications SET verified_at = NOW(), verified_by_user_id = ?, verified_by_name = ?, note = ? WHERE order_id = ? AND verified_at IS NULL",
                [$user_id, $user_name, $note ?: null, $order->id]
            );
        } else {
            $db->query(
                "INSERT INTO pos_exit_verifications (order_id, order_number, verified_at, verified_by_user_id, verified_by_name, note) VALUES (?, ?, NOW(), ?, ?, ?)",
                [$order->id, $order->order_number, $user_id, $user_name, $note ?: null]
            );
        }
        if (function_exists('auditLog')) {
            auditLog('pos', 'exit_verified', "POS exit verified for {$order->order_number} by {$user_name}",
                ['record_type' => 'pos_sale', 'record_id' => $order->id, 'reference_number' => $order->order_number]);
        }
        $flash = 'Exit verified — goods cleared to leave.';
        $verif = $db->query("SELECT * FROM pos_exit_verifications WHERE order_id = ?", [$order->id])->first();
        $already_verified = $verif && !empty($verif->verified_at);
    } catch (Exception $e) {
        $error = 'Could not record exit verification: ' . $e->getMessage();
    }
}

$stage = !$order ? 'error' : ($already_verified ? 'done' : 'pending');
$reused = ($order && $_SERVER['REQUEST_METHOD'] === 'GET' && $stage === 'done');
$scan_total = ($order && $_SERVER['REQUEST_METHOD'] === 'GET')
    ? recordPosExitScan((int)$order->id, $order->order_number, $user_name, $reused)
    : 0;

require_once '../templates/header.php';
?>
<div class="max-w-lg mx-auto px-4 py-6">

<?php if ($flash): ?>
<div class="mb-3 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm"><i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars($flash); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-3 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm"><i class="fas fa-exclamation-triangle mr-1"></i><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($reused): ?>
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
        <div class="text-4xl mb-1">✓</div><h1 class="text-lg font-bold">EXIT VERIFIED</h1>
        <p class="text-gray-200 text-sm">Cleared by <?php echo htmlspecialchars($verif->verified_by_name ?? ''); ?> at <?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($verif->verified_at))); ?></p>
    </div>
    <?php else: ?>
    <div class="bg-blue-600 text-white px-6 py-5 text-center">
        <div class="text-4xl mb-1">🛒</div><h1 class="text-lg font-bold">VERIFY POS EXIT</h1>
        <p class="text-blue-100 text-sm">Check the receipt matches what's being carried out, then clear it.</p>
    </div>
    <?php endif; ?>

    <div class="p-6">
        <div class="flex justify-between items-start mb-4 pb-4 border-b border-gray-100">
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wide">Order</div>
                <div class="font-mono font-bold text-lg"><?php echo htmlspecialchars($order->order_number); ?></div>
                <div class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($order->branch_name ?? ''); ?> · <?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($order->order_date))); ?></div>
            </div>
            <div class="text-right">
                <div class="text-xs text-gray-500 uppercase tracking-wide">Total</div>
                <div class="font-bold text-lg text-green-600">৳<?php echo number_format($order->total_amount, 2); ?></div>
                <div class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($order->payment_status); ?></div>
            </div>
        </div>

        <?php if ($order->customer_name): ?>
        <div class="mb-4 text-sm text-gray-700">
            <span class="text-gray-500">Customer:</span> <strong><?php echo htmlspecialchars($order->customer_name); ?></strong>
            <?php if ($order->customer_phone): ?><span class="text-gray-400">· <?php echo htmlspecialchars($order->customer_phone); ?></span><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="mb-4">
            <div class="text-xs text-gray-500 uppercase tracking-wide mb-2">Items</div>
            <div class="space-y-1">
                <?php foreach ($items as $it): ?>
                <div class="flex justify-between text-sm">
                    <span><?php echo htmlspecialchars($it->base_name); ?> <span class="text-gray-400">× <?php echo (int)$it->quantity; ?></span></span>
                    <span class="font-medium">৳<?php echo number_format($it->total_amount, 2); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($order->credit_amount > 0.009): ?>
        <div class="mb-4 text-xs bg-purple-50 border border-purple-200 rounded-lg p-3 text-purple-800">
            ৳<?php echo number_format($order->credit_amount, 2); ?> of this sale is on customer credit.
        </div>
        <?php endif; ?>

        <?php if ($stage === 'pending'): ?>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="action" value="confirm_exit">
            <input type="hidden" name="order" value="<?php echo htmlspecialchars($order_number); ?>">
            <input type="hidden" name="sig" value="<?php echo htmlspecialchars($sig); ?>">
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Note (optional)</label>
                <input type="text" name="note" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="e.g. vehicle/plate number">
            </div>
            <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg">
                Clear for Exit
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</div>
<?php require_once '../templates/footer.php'; ?>

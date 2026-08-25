<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'sales-srg', 'sales-demra', 'sales-other', 'production manager-srg', 'production manager-demra',
                  'dispatch-srg', 'dispatch-demra', 'dispatchpos-srg', 'dispatchpos-demra'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$pageTitle = 'Order Details';

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$order_id) {
    header('Location: index.php');
    exit();
}

// Get order details — true balance = initial_due + net ledger transactions
$order = $db->query(
    "SELECT co.*,
            c.name as customer_name,
            c.phone_number as customer_phone,
            c.email as customer_email,
            c.credit_limit,
            c.initial_due,
            COALESCE(c.initial_due, 0)
                + COALESCE(tb.total_debit,  0)
                - COALESCE(tb.total_credit, 0) AS current_balance,
            c.credit_limit - (
                COALESCE(c.initial_due, 0)
                + COALESCE(tb.total_debit,  0)
                - COALESCE(tb.total_credit, 0)
            ) AS available_credit,
            b.name as branch_name,
            u.display_name as created_by_name,
            ps.scheduled_date,
            ps.production_started_at,
            ps.production_completed_at,
            cos.truck_number,
            cos.driver_name,
            cos.driver_contact,
            cos.shipped_date,
            cos.delivered_date
     FROM credit_orders co
     JOIN customers c ON co.customer_id = c.id
     LEFT JOIN (
         SELECT customer_id,
                SUM(debit_amount)  AS total_debit,
                SUM(credit_amount) AS total_credit
         FROM customer_ledger
         WHERE reference_type != 'initial_due'
         GROUP BY customer_id
     ) tb ON tb.customer_id = c.id
     LEFT JOIN branches b ON co.assigned_branch_id = b.id
     LEFT JOIN users u ON co.created_by_user_id = u.id
     LEFT JOIN production_schedule ps ON co.id = ps.order_id
     LEFT JOIN credit_order_shipping cos ON co.id = cos.order_id
     WHERE co.id = ?",
    [$order_id]
)->first();

if (!$order) {
    $_SESSION['error_flash'] = "Order not found";
    header('Location: index.php');
    exit();
}

// Get order items
$items = $db->query(
    "SELECT coi.*, 
            p.base_name as product_name,
            pv.grade,
            pv.weight_variant,
            pv.unit_of_measure,
            pv.sku as variant_sku
     FROM credit_order_items coi
     JOIN products p ON coi.product_id = p.id
     LEFT JOIN product_variants pv ON coi.variant_id = pv.id
     WHERE coi.order_id = ?",
    [$order_id]
)->results();

// Get workflow history.
// NOTE: the timestamp column is `performed_at` (NOT created_at) — ordering by id
// keeps chronological sequence and works on any schema variant.
$workflow = [];
try {
    $workflow = $db->query(
        "SELECT cow.*, u.display_name as performed_by_name
         FROM credit_order_workflow cow
         LEFT JOIN users u ON cow.performed_by_user_id = u.id
         WHERE cow.order_id = ?
         ORDER BY cow.id ASC",
        [$order_id]
    )->results();
} catch (Exception $e) {
    error_log('Order view workflow fetch: ' . $e->getMessage());
}

// Get returns for this order
$returns = [];
try {
    $returns = $db->query(
        "SELECT r.*, u.display_name AS created_by_name, au.display_name AS approved_by_name
         FROM credit_order_returns r
         LEFT JOIN users u  ON r.created_by_user_id  = u.id
         LEFT JOIN users au ON r.approved_by_user_id = au.id
         WHERE r.order_id = ?
         ORDER BY r.created_at ASC",
        [$order_id]
    )->results();
} catch (Exception $e) {}

// Get deliveries for this order
$deliveries = [];
try {
    $deliveries = $db->query(
        "SELECT d.*, u.display_name AS delivered_by_name
         FROM credit_order_deliveries d
         LEFT JOIN users u ON d.created_by_user_id = u.id
         WHERE d.order_id = ?
         ORDER BY d.delivery_date ASC, d.id ASC",
        [$order_id]
    )->results();
} catch (Exception $e) {}

// Over-deliveries recorded against this order
$over_deliveries = [];
try {
    $over_deliveries = $db->query(
        "SELECT od.*, u.display_name AS created_by_name, au.display_name AS approved_by_name
         FROM credit_order_over_deliveries od
         LEFT JOIN users u  ON od.created_by_user_id  = u.id
         LEFT JOIN users au ON od.approved_by_user_id = au.id
         WHERE od.order_id = ?
         ORDER BY od.created_at ASC",
        [$order_id]
    )->results();
} catch (Exception $e) {}

// Payments allocated to this order (via payment_allocations → customer_payments)
$payments = [];
try {
    $payments = $db->query(
        "SELECT pa.allocated_amount,
                cp.payment_number, cp.payment_date, cp.payment_method,
                cp.reference_number, cp.amount AS payment_total, cp.created_at AS pay_created,
                u.display_name AS collected_by,
                ba.bank_name, ba.account_name, ba.account_number
         FROM payment_allocations pa
         JOIN customer_payments cp ON pa.payment_id = cp.id
         LEFT JOIN users u ON cp.created_by_user_id = u.id
         LEFT JOIN bank_accounts ba ON cp.bank_account_id = ba.id
         WHERE pa.order_id = ?
         ORDER BY cp.payment_date ASC, cp.id ASC",
        [$order_id]
    )->results();
} catch (Exception $e) {}

$is_admin = in_array($currentUser['role'] ?? '', ['Superadmin', 'admin']);
$user_id  = $currentUser['id'] ?? null;

// ── Approval gate actions (admin) ────────────────────────────────────────────
ensureApprovalGateTables();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin && isset($_POST['gate_action'])) {
    $ga = $_POST['gate_action'];
    try {
        if ($ga === 'release_production_hold') {
            $db->query(
                "UPDATE order_approval_conditions
                 SET production_released_by = ?, production_released_at = NOW()
                 WHERE order_id = ? AND production_hold = 1 AND production_released_at IS NULL",
                [$user_id, $order_id]
            );
            logGateEvent($order_id, 'production_released',
                'Production hold RELEASED by ' . ($currentUser['display_name'] ?? 'admin'));
            $_SESSION['success_flash'] = 'Production hold released.';

        } elseif ($ga === 'admin_clear_dispatch') {
            $note = trim($_POST['clearance_note'] ?? '');
            $db->query(
                "UPDATE order_approval_conditions
                 SET dispatch_cleared = 1, cleared_by = ?, cleared_at = NOW(), clearance_note = ?
                 WHERE order_id = ? AND dispatch_hold = 1 AND dispatch_cleared = 0",
                [$user_id, $note !== '' ? $note : 'Admin override', $order_id]
            );
            logGateEvent($order_id, 'dispatch_cleared',
                'Dispatch clearance GRANTED (admin override) by ' . ($currentUser['display_name'] ?? 'admin')
                . ($note !== '' ? ' — ' . $note : ''));
            $_SESSION['success_flash'] = 'Dispatch clearance granted (admin override).';

        } elseif ($ga === 'admin_revoke_dispatch') {
            $reason = trim($_POST['revoke_reason'] ?? '');
            if ($reason === '') throw new Exception('A reason is required to revoke clearance.');
            if (in_array($order->status, ['shipped', 'delivered'])) {
                throw new Exception('Order already dispatched — clearance can no longer be revoked.');
            }
            $db->query(
                "UPDATE order_approval_conditions
                 SET dispatch_cleared = 0, cleared_by = NULL, cleared_at = NULL, clearance_note = NULL, auto_release = 0
                 WHERE order_id = ? AND dispatch_hold = 1 AND dispatch_cleared = 1",
                [$order_id]
            );
            logGateEvent($order_id, 'clearance_revoked',
                'Dispatch clearance REVOKED by ' . ($currentUser['display_name'] ?? 'admin') . ' — ' . $reason);
            $_SESSION['success_flash'] = 'Clearance revoked. Dispatch is held again.';

        } elseif ($ga === 'update_conditions') {
            $old = $db->query("SELECT * FROM order_approval_conditions WHERE order_id = ?", [$order_id])->first();
            if (!$old) throw new Exception('No conditions found for this order.');
            $cond_type = in_array($_POST['condition_type'] ?? '', ['manual', 'outstanding_below', 'outstanding_after_ship', 'amount_received'])
                       ? $_POST['condition_type'] : 'manual';
            $amt_raw   = trim($_POST['condition_amount'] ?? '');
            $cond_amt  = ($cond_type !== 'manual' && $amt_raw !== '') ? (float)$amt_raw : null;
            if ($cond_type !== 'manual' && $cond_amt === null) {
                throw new Exception('Please enter the amount for the payment condition.');
            }
            $auto = (!empty($_POST['auto_release']) && $cond_type !== 'manual') ? 1 : 0;
            $db->query(
                "UPDATE order_approval_conditions
                 SET condition_type = ?, condition_amount = ?, auto_release = ?
                 WHERE order_id = ?",
                [$cond_type, $cond_amt, $auto, $order_id]
            );
            logGateEvent($order_id, 'conditions_updated',
                sprintf('Dispatch condition changed by %s: %s ৳%s → %s ৳%s%s',
                    $currentUser['display_name'] ?? 'admin',
                    $old->condition_type, number_format((float)($old->condition_amount ?? 0), 0),
                    $cond_type, number_format((float)($cond_amt ?? 0), 0),
                    $auto ? ' (auto-release)' : ''));
            $_SESSION['success_flash'] = 'Conditions updated.';
        }
    } catch (Exception $e) {
        $_SESSION['error_flash'] = $e->getMessage();
    }
    header('Location: credit_order_view.php?id=' . $order_id);
    exit();
}

// Live gate state for the conditions card
$gate = getOrderGateState($order_id);

require_once '../templates/header.php';

$status_colors = [
    'pending_approval' => 'orange',
    'escalated' => 'red',
    'approved' => 'blue',
    'rejected' => 'gray',
    'in_production' => 'purple',
    'produced' => 'indigo',
    'ready_to_ship' => 'teal',
    'shipped' => 'cyan',
    'delivered' => 'green'
];
$color = $status_colors[$order->status] ?? 'gray';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Header -->
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Order #<?php echo htmlspecialchars($order->order_number); ?></h1>
        <p class="text-lg text-gray-600 mt-1">Complete order details and history</p>
    </div>
    <div class="flex gap-3">
        <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
        </a>
        <button onclick="window.print()" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
            <i class="fas fa-print mr-2"></i>Print
        </button>
    </div>
</div>

<!-- Status Badge -->
<div class="mb-6">
    <span class="inline-block px-4 py-2 text-sm font-semibold rounded-full bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-800">
        <i class="fas fa-circle mr-2"></i><?php echo ucwords(str_replace('_', ' ', $order->status)); ?>
    </span>
</div>

<?php if (isset($_SESSION['success_flash'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 mb-4 rounded-lg text-sm">
    <i class="fas fa-check-circle mr-1"></i> <?php echo htmlspecialchars($_SESSION['success_flash']); unset($_SESSION['success_flash']); ?>
</div>
<?php endif; ?>
<?php if (isset($_SESSION['error_flash'])): ?>
<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 mb-4 rounded-lg text-sm">
    <strong>Error:</strong> <?php echo htmlspecialchars($_SESSION['error_flash']); unset($_SESSION['error_flash']); ?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════
     ORDER TIMELINE — horizontal, animated
     placement → escalation → approval (conditions) → production →
     shipment → delivery → payments → completion
═══════════════════════════════════════════════════════════════════════ -->
<?php
$timeline = [];
$seq = 0;
$tl_add = function (string $ts, string $bg, string $ic, string $title, ?string $meta, ?string $comment, ?string $by) use (&$timeline, &$seq) {
    $timeline[] = [
        'sort' => strtotime($ts) ?: 0, 'seq' => $seq++, 'ts' => $ts,
        'bg' => $bg, 'ic' => $ic, 'title' => $title,
        'meta' => $meta, 'comment' => $comment, 'by' => $by,
    ];
};

// 1) Workflow events — keyword-mapped to friendly titles/icons
foreach ($workflow as $w) {
    $a = strtolower($w->action ?? '');
    if     (str_contains($a, 'creat') || str_contains($a, 'submit') || str_contains($a, 'placed'))
         $m = ['bg-blue-100',    'fas fa-cart-plus text-blue-600',      'Order Placed'];
    elseif (str_contains($a, 'escalat'))
         $m = ['bg-orange-100',  'fas fa-level-up-alt text-orange-600', 'Escalated to Admin'];
    elseif ($a === 'conditions_set' || $a === 'conditions_updated')
         $m = ['bg-amber-100',   'fas fa-hand-paper text-amber-600',    'Special Instructions'];
    elseif (str_contains($a, 'reject'))
         $m = ['bg-red-100',     'fas fa-times text-red-600',           'Order Rejected'];
    elseif (str_contains($a, 'approv'))
         $m = ['bg-green-100',   'fas fa-check text-green-600',         'Final Approval'];
    elseif ($a === 'start_production')
         $m = ['bg-purple-100',  'fas fa-play text-purple-600',         'Production Started'];
    elseif ($a === 'complete_production')
         $m = ['bg-purple-100',  'fas fa-check-double text-purple-600', 'Production Completed'];
    elseif (str_contains($a, 'ready'))
         $m = ['bg-orange-100',  'fas fa-boxes text-orange-600',        'Ready to Ship'];
    elseif ($a === 'ship')
         $m = ['bg-teal-100',    'fas fa-truck text-teal-600',          'Shipped / Dispatched'];
    elseif (str_contains($a, 'deliver'))
         $m = ['bg-green-100',   'fas fa-box-open text-green-600',      'Delivered'];
    elseif (str_contains($a, 'amend'))
         $m = ['bg-teal-100',    'fas fa-file-signature text-teal-600',
               $a === 'amendment_requested' ? 'Amendment Requested' : 'Order Amended'];
    elseif ($a === 'production_released')
         $m = ['bg-purple-100',  'fas fa-unlock text-purple-600',       'Production Hold Released'];
    elseif (str_contains($a, 'cleared') && !str_contains($a, 'revoked'))
         $m = ['bg-emerald-100', 'fas fa-unlock text-emerald-600',      'Dispatch Clearance Granted'];
    elseif (str_contains($a, 'revoked'))
         $m = ['bg-red-100',     'fas fa-ban text-red-600',             'Dispatch Clearance Revoked'];
    else $m = ['bg-gray-100',    'fas fa-arrow-right text-gray-500',    ucwords(str_replace('_', ' ', $w->action))];

    // Production schema uses `performed_at`; fall back for older variants
    $w_ts = $w->performed_at ?? $w->created_at ?? date('Y-m-d H:i:s');
    $tl_add($w_ts, $m[0], $m[1], $m[2], null, $w->comments ?: null, $w->performed_by_name ?? 'System');
}

// 1b) Older orders may lack a "created" workflow row — synthesize placement
$has_placement = false;
foreach ($timeline as $ev) { if ($ev['title'] === 'Order Placed') { $has_placement = true; break; } }
if (!$has_placement) {
    $tl_add($order->created_at ?? $order->order_date, 'bg-blue-100', 'fas fa-cart-plus text-blue-600',
        'Order Placed', null,
        'Order ' . $order->order_number . ' created — ৳' . number_format($order->total_amount, 2),
        $order->created_by_name ?? null);
}

// 2) Advance payment at placement
if ((float)($order->advance_paid ?? 0) > 0) {
    $tl_add($order->order_date, 'bg-emerald-100', 'fas fa-money-bill text-emerald-600',
        'Advance Payment', null, '৳' . number_format($order->advance_paid, 2) . ' paid in advance', null);
}

// 3) Partial / final deliveries
foreach ($deliveries as $d) {
    $tl_add($d->created_at ?? $d->delivery_date, 'bg-indigo-100', 'fas fa-dolly text-indigo-600',
        ($d->is_final ? 'Final Delivery ' : 'Partial Delivery ') . ($d->delivery_number ?? ''),
        null,
        number_format($d->total_qty_delivered, 0) . ' units — ৳' . number_format($d->total_amount_delivered, 2)
            . ($d->truck_number ? ' · Truck ' . $d->truck_number : '')
            . ($d->driver_name ? ' · ' . $d->driver_name : ''),
        $d->delivered_by_name ?? null);
}

// 4) Payments allocated to this order — with full deposit destination
foreach ($payments as $p) {
    if (!empty($p->bank_name)) {
        $dep = $p->bank_name . ' — ' . ($p->account_name ?? '')
             . ($p->account_number ? ' (A/C ' . $p->account_number . ')' : '');
    } else {
        $dep = 'Cash' . ($p->collected_by ? ' — collected by ' . $p->collected_by : '');
    }
    $tl_add($p->pay_created ?? $p->payment_date, 'bg-emerald-100', 'fas fa-money-bill-wave text-emerald-600',
        'Payment Received', null,
        '৳' . number_format($p->allocated_amount, 2) . ' — Receipt '
            . ($p->payment_number ?? '—') . ' (' . ($p->payment_method ?? '—')
            . ($p->reference_number ? ', ref ' . $p->reference_number : '') . ') · ' . $dep,
        $p->collected_by ?? null);
}

// 4b) Legacy fallback: money recorded on the order before per-receipt
//     allocation tracking existed (amount_paid with no allocation rows)
$legacy_paid = 0.0;
if (empty($payments)) {
    $legacy_paid = max(0, (float)($order->amount_paid ?? 0) - (float)($order->advance_paid ?? 0));
    if ($legacy_paid > 0) {
        $tl_add($order->updated_at ?? $order->order_date, 'bg-emerald-100', 'fas fa-money-bill-wave text-emerald-600',
            'Payment Received', null,
            '৳' . number_format($legacy_paid, 2) . ' recorded against this order (before receipt-level tracking)',
            null);
    }
}

// 5) Returns
foreach ($returns as $r) {
    $tl_add($r->created_at ?? $r->return_date, 'bg-orange-100', 'fas fa-undo text-orange-600',
        'Goods Return — ' . ucfirst($r->status), null,
        $r->return_number . ' · ৳' . number_format($r->total_returned_amount ?? 0, 2)
            . ($r->return_reason ? ' · ' . $r->return_reason : ''),
        $r->approved_by_name ?? $r->created_by_name ?? null);
}

// 6) Over-deliveries
foreach ($over_deliveries as $od) {
    $od_res = ['bill' => 'Bill Customer', 'retrieve' => 'Retrieve Goods', 'writeoff' => 'Write Off'][$od->resolution] ?? $od->resolution;
    $tl_add($od->created_at ?? $od->od_date, 'bg-blue-100', 'fas fa-truck-loading text-blue-600',
        'Over-Delivery — ' . ucfirst($od->status), null,
        $od->od_number . ' · ৳' . number_format($od->total_extra_amount ?? 0, 2) . ' extra · ' . $od_res,
        $od->approved_by_name ?? $od->created_by_name ?? null);
}

// 7) Completion marker: delivered AND fully paid
if ($order->status === 'delivered' && (float)$order->balance_due <= 0 && !empty($timeline)) {
    $last_ts = date('Y-m-d H:i:s', max(array_column($timeline, 'sort')));
    $tl_add($last_ts, 'bg-emerald-100', 'fas fa-flag-checkered text-emerald-700',
        'Order Completed', null, 'Delivered in full and fully paid.', null);
}

// Chronological order; equal timestamps keep insertion order
usort($timeline, fn($a, $b) => $a['sort'] <=> $b['sort'] ?: $a['seq'] <=> $b['seq']);
?>
<style>
@keyframes tlPop  { from { opacity:0; transform:translateY(18px) scale(.75); } to { opacity:1; transform:none; } }
@keyframes tlGrow { from { transform:scaleX(0); } to { transform:scaleX(1); } }
@keyframes tlPulse{ 0%,100% { box-shadow:0 0 0 0 rgba(59,130,246,.45);} 50% { box-shadow:0 0 0 10px rgba(59,130,246,0);} }
.tl-item { opacity:0; animation: tlPop .5s cubic-bezier(.22,.9,.32,1.18) forwards; }
.tl-rail { transform-origin:left; animation: tlGrow 1.1s ease-out forwards; }
.tl-latest .tl-dot { animation: tlPulse 1.6s ease-out .9s 3; }
.tl-scroll::-webkit-scrollbar { height:6px; }
.tl-scroll::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:3px; }
@media (prefers-reduced-motion: reduce) {
  .tl-item, .tl-rail, .tl-latest .tl-dot { animation:none !important; opacity:1 !important; transform:none !important; }
}
</style>
<div class="mb-6 bg-white rounded-lg shadow-md p-5">
    <div class="flex items-center justify-between mb-2">
        <h2 class="text-lg font-bold text-gray-800"><i class="fas fa-history mr-2 text-blue-500"></i>Order Timeline</h2>
        <span class="text-xs text-gray-400"><?php echo count($timeline); ?> event<?php echo count($timeline) !== 1 ? 's' : ''; ?></span>
    </div>
    <?php if (empty($timeline)): ?>
    <div class="text-center py-5 text-gray-400">
        <i class="fas fa-clock text-2xl mb-2"></i>
        <p class="text-sm">No history yet. Events will appear here as the order progresses.</p>
    </div>
    <?php else: ?>
    <div class="tl-scroll overflow-x-auto" id="tlScroll">
        <div class="relative flex items-start min-w-max px-3 pt-1 pb-2">
            <!-- rail connecting the dots, draws itself in -->
            <div class="tl-rail absolute h-1 bg-gradient-to-r from-blue-300 via-purple-300 to-emerald-400 rounded"
                 style="top:25px; left:130px; right:130px;"></div>
            <?php $tl_n = count($timeline); foreach ($timeline as $i => $ev): ?>
            <div class="tl-item relative z-10 flex flex-col items-center w-[260px] shrink-0 px-2 <?php echo $i === $tl_n - 1 ? 'tl-latest' : ''; ?>"
                 style="animation-delay: <?php echo min($i * 110, 1900); ?>ms">
                <div class="tl-dot w-12 h-12 rounded-full <?php echo $ev['bg']; ?> flex items-center justify-center border-4 border-white shadow-md">
                    <i class="<?php echo $ev['ic']; ?>"></i>
                </div>
                <p class="mt-2 text-[13px] font-bold text-gray-800 text-center leading-tight">
                    <?php echo htmlspecialchars($ev['title']); ?>
                </p>
                <p class="text-[11px] text-gray-400 mt-0.5"><?php echo date('d M Y, H:i', $ev['sort']); ?></p>
                <?php if ($ev['comment'] || $ev['by']): ?>
                <?php
                // Remark card tinted to match the event's color — soft pastels.
                // Inline hex (not Tailwind classes) so purged builds can't break it.
                $tl_tints = [
                    'bg-blue-100'    => ['#eff6ff', '#bfdbfe', '#1e3a8a'],
                    'bg-orange-100'  => ['#fff7ed', '#fed7aa', '#7c2d12'],
                    'bg-amber-100'   => ['#fffbeb', '#fde68a', '#78350f'],
                    'bg-red-100'     => ['#fef2f2', '#fecaca', '#7f1d1d'],
                    'bg-green-100'   => ['#f0fdf4', '#bbf7d0', '#14532d'],
                    'bg-purple-100'  => ['#faf5ff', '#e9d5ff', '#581c87'],
                    'bg-teal-100'    => ['#f0fdfa', '#99f6e4', '#134e4a'],
                    'bg-emerald-100' => ['#ecfdf5', '#a7f3d0', '#064e3b'],
                    'bg-indigo-100'  => ['#eef2ff', '#c7d2fe', '#312e81'],
                ];
                [$c_bg, $c_bd, $c_tx] = $tl_tints[$ev['bg']] ?? ['#f9fafb', '#e5e7eb', '#374151'];
                ?>
                <!-- Full remarks card — never truncated, tinted per event type -->
                <div class="mt-1.5 w-full rounded-lg px-2.5 py-2 text-left shadow-sm"
                     style="background:<?php echo $c_bg; ?>; border:1px solid <?php echo $c_bd; ?>;">
                    <?php if ($ev['comment']): ?>
                    <p class="text-[11.5px] leading-snug whitespace-normal break-words"
                       style="color:<?php echo $c_tx; ?>;">
                        <?php echo htmlspecialchars($ev['comment']); ?>
                    </p>
                    <?php endif; ?>
                    <?php if ($ev['by']): ?>
                    <p class="text-[10.5px] mt-1 <?php echo $ev['comment'] ? 'pt-1' : ''; ?>"
                       style="color:<?php echo $c_tx; ?>; opacity:.55; <?php echo $ev['comment'] ? 'border-top:1px solid ' . $c_bd . ';' : ''; ?>">
                        <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($ev['by']); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <script>
    // Glide to the latest event once the pop-in has started
    (function () {
        var s = document.getElementById('tlScroll');
        if (s && s.scrollWidth > s.clientWidth) {
            setTimeout(function () { s.scrollTo({ left: s.scrollWidth, behavior: 'smooth' }); }, 500);
        }
    })();
    </script>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     ACTION BAR — every credit-sales action for this order, done in modals
     without leaving the page. Each modal loads the real tool (permissions,
     ledger logic, Telegram all enforced by the tool itself).
═══════════════════════════════════════════════════════════════════════ -->
<?php
$ov_st = $order->status;
$ov_actions = [];   // [icon, color, label, url]

if (in_array($ov_st, ['pending_approval', 'escalated'])) {
    $ov_actions[] = ['fa-check-circle', 'green',  'Approve / Reject',  'credit_order_approval.php?focus=' . $order_id];
}
if (in_array($ov_st, ['approved', 'in_production', 'produced'])) {
    $ov_actions[] = ['fa-industry',     'purple', 'Production Board',  'credit_production.php'];
}
if (in_array($ov_st, ['ready_to_ship', 'shipped'])) {
    $ov_actions[] = ['fa-truck',        'orange', 'Dispatch Board',    'credit_dispatch.php'];
}
if (in_array($ov_st, ['produced', 'ready_to_ship', 'shipped'])) {
    $ov_actions[] = ['fa-dolly',        'indigo', 'Partial Delivery',  'partial_delivery.php?order_id=' . $order_id];
}
if ((float)$order->balance_due > 0 && !in_array($ov_st, ['pending_approval', 'escalated', 'rejected', 'cancelled'])) {
    $ov_actions[] = ['fa-money-bill-wave', 'green', 'Collect Payment', 'customer_payment.php?order_id=' . $order_id];
}
if (in_array($ov_st, ['shipped', 'delivered'])) {
    $ov_actions[] = ['fa-undo',          'orange', 'Return Goods',     'returns.php?order_id=' . $order_id];
    $ov_actions[] = ['fa-truck-loading', 'blue',   'Over-Delivery',    'over_delivery.php?order_id=' . $order_id];
}
if (!in_array($ov_st, ['pending_approval', 'escalated', 'rejected', 'cancelled'])) {
    $ov_actions[] = ['fa-file-signature', 'teal',  'Amend Order',      'order_amendment.php?order_id=' . $order_id];
}
if ($gate['has_conditions'] && (int)($gate['row']->dispatch_hold ?? 0) === 1) {
    $ov_actions[] = ['fa-eye',           'red',    'Payment Watch',    'payment_watch.php?show=all&f_order=' . urlencode($order->order_number)];
}
?>
<?php if (!empty($ov_actions) || in_array($ov_st, ['shipped', 'delivered'])): ?>
<div class="mb-6 bg-white rounded-lg shadow-md px-4 py-3 flex flex-wrap items-center gap-2">
    <span class="text-xs font-bold text-gray-400 uppercase tracking-wide mr-2"><i class="fas fa-bolt mr-1"></i>Actions</span>
    <?php foreach ($ov_actions as $a): ?>
    <button type="button" onclick="ovOpen(<?php echo htmlspecialchars(json_encode($a[2]), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($a[3]), ENT_QUOTES); ?>)"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-<?php echo $a[1]; ?>-600 text-white text-xs font-semibold rounded-lg hover:bg-<?php echo $a[1]; ?>-700 transition-colors cursor-pointer">
        <i class="fas <?php echo $a[0]; ?>"></i><?php echo $a[2]; ?>
    </button>
    <?php endforeach; ?>
    <?php if (in_array($ov_st, ['shipped', 'delivered'])): ?>
    <a href="credit_invoice_print.php?id=<?php echo $order_id; ?>" target="_blank"
       class="inline-flex items-center gap-1.5 px-3 py-1.5 border-2 border-gray-300 text-gray-700 text-xs font-semibold rounded-lg hover:bg-gray-50 transition-colors cursor-pointer">
        <i class="fas fa-print"></i>Print Invoice
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Approval Conditions Card ─────────────────────────────────────────── -->
<?php if ($gate['has_conditions']):
    $grow          = $gate['row'];
    $prod_held     = $gate['production'] === 'held';
    $disp_state    = $gate['dispatch'];     // open|held|condition_met|cleared
    $has_disp_gate = (int)$grow->dispatch_hold === 1;
    $cond_labels   = [
        'manual'                 => 'Manual clearance by Accounts',
        'outstanding_below'      => 'Outstanding must drop to ≤ threshold (excl. this invoice)',
        'outstanding_after_ship' => 'Outstanding INCL. this invoice must drop to ≤ threshold',
        'amount_received'        => 'Payments received since approval ≥ threshold',
    ];
?>
<div class="mb-6 bg-white rounded-lg shadow-md border-l-4 <?php echo ($prod_held || in_array($disp_state, ['held', 'condition_met'])) ? 'border-amber-500' : 'border-green-500'; ?> overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <h2 class="text-lg font-bold text-gray-800">
            <i class="fas fa-hand-paper mr-2 text-amber-500"></i>Approval Conditions
        </h2>
        <span class="text-xs text-gray-400">
            Set by <?php
                $setter = $db->query("SELECT display_name FROM users WHERE id = ?", [$grow->approved_by_user_id])->first();
                echo htmlspecialchars($setter->display_name ?? 'admin');
            ?> on <?php echo date('d M Y, g:i A', strtotime($grow->approved_at)); ?>
        </span>
    </div>
    <div class="px-6 py-4 grid grid-cols-1 md:grid-cols-2 gap-6">

        <!-- Production gate -->
        <div>
            <p class="text-xs text-gray-400 uppercase tracking-wide font-semibold mb-2">Production</p>
            <?php if ((int)$grow->production_hold !== 1): ?>
            <p class="text-sm text-green-700"><i class="fas fa-check-circle mr-1"></i>No hold — production may proceed normally.</p>
            <?php elseif ($prod_held): ?>
            <p class="text-sm font-bold text-red-700"><i class="fas fa-lock mr-1"></i>PRODUCTION HELD</p>
            <?php if (!empty($grow->production_note)): ?>
            <p class="text-xs text-gray-600 mt-1 italic">"<?php echo htmlspecialchars($grow->production_note); ?>"</p>
            <?php endif; ?>
            <?php if ($is_admin): ?>
            <form method="POST" class="mt-3" onsubmit="return confirm('Release the production hold? Production team will be able to start.');">
                <input type="hidden" name="gate_action" value="release_production_hold">
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm font-semibold hover:bg-purple-700 cursor-pointer">
                    <i class="fas fa-play mr-1"></i>Release Production Hold
                </button>
            </form>
            <?php endif; ?>
            <?php else: ?>
            <p class="text-sm text-green-700"><i class="fas fa-check-circle mr-1"></i>Hold released
                <?php if ($grow->production_released_at): ?>
                on <?php echo date('d M Y, g:i A', strtotime($grow->production_released_at)); ?>
                <?php endif; ?>
            </p>
            <?php endif; ?>
        </div>

        <!-- Dispatch gate -->
        <div>
            <p class="text-xs text-gray-400 uppercase tracking-wide font-semibold mb-2">Dispatch</p>
            <?php if (!$has_disp_gate): ?>
            <p class="text-sm text-green-700"><i class="fas fa-check-circle mr-1"></i>No hold — dispatch allowed when ready.</p>
            <?php else: ?>

                <?php if ($disp_state === 'cleared'): ?>
                <p class="text-sm font-bold text-green-700"><i class="fas fa-unlock mr-1"></i>CLEARED
                    <?php if ($grow->cleared_at): ?>
                    <span class="font-normal text-gray-500">on <?php echo date('d M Y, g:i A', strtotime($grow->cleared_at)); ?></span>
                    <?php endif; ?>
                </p>
                <?php if (!empty($grow->clearance_note)): ?>
                <p class="text-xs text-gray-600 mt-1 italic">"<?php echo htmlspecialchars($grow->clearance_note); ?>"</p>
                <?php endif; ?>
                <?php if ($is_admin && !in_array($order->status, ['shipped', 'delivered'])): ?>
                <form method="POST" class="mt-3 flex gap-2" onsubmit="return confirm('Revoke clearance? Dispatch will be locked again.');">
                    <input type="hidden" name="gate_action" value="admin_revoke_dispatch">
                    <input type="text" name="revoke_reason" required maxlength="500"
                           class="flex-1 px-3 py-2 border rounded-lg text-xs" placeholder="Reason (e.g. cheque bounced)...">
                    <button type="submit" class="px-3 py-2 border-2 border-red-500 text-red-600 rounded-lg text-xs font-bold hover:bg-red-50 cursor-pointer">
                        <i class="fas fa-ban mr-1"></i>Revoke
                    </button>
                </form>
                <?php endif; ?>

                <?php else: ?>
                <p class="text-sm font-bold <?php echo $disp_state === 'condition_met' ? 'text-blue-700' : 'text-red-700'; ?>">
                    <i class="fas fa-lock mr-1"></i>DISPATCH HELD
                    <?php if ($disp_state === 'condition_met'): ?>
                    <span class="text-blue-600 font-semibold">— condition met, awaiting clearance</span>
                    <?php endif; ?>
                </p>
                <p class="text-xs text-gray-600 mt-1"><?php echo $cond_labels[$grow->condition_type] ?? $grow->condition_type; ?>
                    <?php if ($gate['threshold'] !== null): ?>
                    · Threshold <strong>৳<?php echo number_format($gate['threshold'], 0); ?></strong>
                    <?php endif; ?>
                    <?php if ((int)$grow->auto_release === 1): ?>
                    · <span class="text-green-600"><i class="fas fa-bolt"></i> auto-release</span>
                    <?php endif; ?>
                </p>
                <?php if ($gate['current'] !== null): ?>
                <p class="text-xs text-gray-600 mt-1">
                    <?php echo ['outstanding_below' => 'Outstanding now',
                                'outstanding_after_ship' => 'Outstanding incl. this invoice',
                                'amount_received' => 'Received so far'][$grow->condition_type] ?? 'Current'; ?>:
                    <strong>৳<?php echo number_format($gate['current'], 0); ?></strong>
                    <?php if ($gate['shortfall'] !== null && $gate['shortfall'] > 0): ?>
                    — <span class="text-red-600 font-semibold">৳<?php echo number_format($gate['shortfall'], 0); ?> to go</span>
                    <?php endif; ?>
                </p>
                <?php endif; ?>
                <?php if (!empty($grow->accounts_note)): ?>
                <p class="text-xs text-gray-600 mt-1 italic">"<?php echo htmlspecialchars($grow->accounts_note); ?>"</p>
                <?php endif; ?>

                <?php if ($is_admin): ?>
                <div class="mt-3 space-y-2">
                    <form method="POST" class="flex gap-2" onsubmit="return confirm('Clear dispatch by admin override?');">
                        <input type="hidden" name="gate_action" value="admin_clear_dispatch">
                        <input type="text" name="clearance_note" maxlength="500"
                               class="flex-1 px-3 py-2 border rounded-lg text-xs" placeholder="Override note (optional)...">
                        <button type="submit" class="px-3 py-2 bg-green-600 text-white rounded-lg text-xs font-bold hover:bg-green-700 cursor-pointer">
                            <i class="fas fa-unlock mr-1"></i>Clear Now (Override)
                        </button>
                    </form>
                    <details class="text-xs">
                        <summary class="cursor-pointer text-blue-600 hover:underline">Edit condition…</summary>
                        <form method="POST" class="mt-2 space-y-2 p-3 bg-gray-50 rounded-lg border border-gray-200">
                            <input type="hidden" name="gate_action" value="update_conditions">

                            <!-- Full picture: this invoice posts to the ledger at dispatch -->
                            <?php
                            $ev_out = (float)$order->current_balance;   // true outstanding now
                            $ev_inv = (float)$order->total_amount;      // this invoice
                            ?>
                            <div class="p-2 bg-blue-50 border border-blue-200 rounded text-[11px] text-blue-900 space-y-0.5">
                                <div class="flex justify-between"><span>Previous due (now):</span><strong>৳<?php echo number_format($ev_out, 0); ?></strong></div>
                                <div class="flex justify-between"><span>This invoice (posts at dispatch):</span><strong>+ ৳<?php echo number_format($ev_inv, 0); ?></strong></div>
                                <div class="flex justify-between border-t border-blue-300 pt-0.5">
                                    <span class="font-semibold">After this shipment (if unpaid):</span>
                                    <strong class="text-red-700">৳<?php echo number_format($ev_out + $ev_inv, 0); ?></strong>
                                </div>
                                <div class="pt-0.5 text-blue-700">
                                    Full settlement incl. this invoice: pick
                                    "<em>Outstanding incl. this invoice</em>" with amount <strong>0</strong>
                                    (deposit needed: ৳<?php echo number_format($ev_out + $ev_inv, 0); ?>).
                                </div>
                            </div>

                            <select name="condition_type" class="w-full px-3 py-2 border rounded-lg text-xs"
                                    onchange="this.form.querySelector('.cond-amt-row').style.display = this.value === 'manual' ? 'none' : 'block';">
                                <option value="manual" <?php echo $grow->condition_type === 'manual' ? 'selected' : ''; ?>>Manual clearance</option>
                                <option value="outstanding_below" <?php echo $grow->condition_type === 'outstanding_below' ? 'selected' : ''; ?>>Current outstanding ≤ amount (excl. this invoice)</option>
                                <option value="outstanding_after_ship" <?php echo $grow->condition_type === 'outstanding_after_ship' ? 'selected' : ''; ?>>Outstanding incl. this invoice ≤ amount (0 = pay all)</option>
                                <option value="amount_received" <?php echo $grow->condition_type === 'amount_received' ? 'selected' : ''; ?>>Received ≥ amount</option>
                            </select>
                            <div class="cond-amt-row" style="display: <?php echo $grow->condition_type === 'manual' ? 'none' : 'block'; ?>">
                                <input type="number" name="condition_amount" min="0" step="0.01"
                                       value="<?php echo $grow->condition_amount !== null ? htmlspecialchars($grow->condition_amount) : ''; ?>"
                                       class="w-full px-3 py-2 border rounded-lg text-xs" placeholder="Amount (৳)">
                                <label class="flex items-center gap-1.5 mt-1.5 cursor-pointer text-gray-600">
                                    <input type="checkbox" name="auto_release" value="1" <?php echo (int)$grow->auto_release === 1 ? 'checked' : ''; ?> class="accent-green-600">
                                    Auto-release when met
                                </label>
                            </div>
                            <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-xs font-semibold hover:bg-blue-700 cursor-pointer">
                                Save Condition
                            </button>
                        </form>
                    </details>
                </div>
                <?php endif; ?>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Main Content -->
    <div class="lg:col-span-2 space-y-6">
        
        <!-- Order Summary -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Order Summary</h2>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="text-gray-600">Order Number</p>
                    <p class="font-bold text-lg"><?php echo htmlspecialchars($order->order_number); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Order Date</p>
                    <p class="font-bold"><?php echo date('M j, Y', strtotime($order->order_date)); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Required Date</p>
                    <p class="font-bold"><?php echo date('M j, Y', strtotime($order->required_date)); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Order Type</p>
                    <p class="font-bold"><?php echo ucwords(str_replace('_', ' ', $order->order_type)); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Delivery Type</p>
                    <?php
                    $dt = $order->delivery_type ?? 'big_truck';
                    if ($dt === 'mini_truck'):
                    ?>
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-semibold bg-orange-100 text-orange-700">
                        <i class="fas fa-truck-pickup"></i> Mini Truck
                    </span>
                    <?php else: ?>
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">
                        <i class="fas fa-truck"></i> Big Truck (25MT)
                    </span>
                    <?php endif; ?>
                </div>
                <div>
                    <p class="text-gray-600">Assigned Branch</p>
                    <p class="font-bold"><?php echo $order->branch_name ? htmlspecialchars($order->branch_name) : 'Not assigned'; ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Created By</p>
                    <p class="font-bold"><?php echo htmlspecialchars($order->created_by_name ?? '—'); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Customer Information -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Customer Information</h2>
            <div class="space-y-3 text-sm">
                <div>
                    <p class="text-gray-600">Customer Name</p>
                    <p class="font-bold text-lg"><?php echo htmlspecialchars($order->customer_name); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Phone</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->customer_phone); ?></p>
                </div>
                <?php if ($order->customer_email): ?>
                <div>
                    <p class="text-gray-600">Email</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->customer_email); ?></p>
                </div>
                <?php endif; ?>
                <div>
                    <p class="text-gray-600">Shipping Address</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->shipping_address); ?></p>
                </div>
                <?php if ($order->special_instructions): ?>
                <div class="p-3 bg-blue-50 border border-blue-200 rounded">
                    <p class="text-gray-600 text-xs mb-1">Special Instructions</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->special_instructions); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Order Items -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-gray-800">Order Items</h2>
                <?php if (($order->delivery_type ?? 'big_truck') === 'mini_truck'): ?>
                <span class="inline-flex items-center gap-2 px-3 py-1 bg-orange-50 border border-orange-200 rounded-lg text-xs text-orange-700 font-medium">
                    <i class="fas fa-truck-pickup"></i>
                    Mini Truck — item prices include delivery surcharge
                </span>
                <?php endif; ?>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Variant</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($items as $item): 
                            $variant_display = [];
                            if ($item->grade) $variant_display[] = $item->grade;
                            if ($item->weight_variant) $variant_display[] = $item->weight_variant;
                        ?>
                        <tr>
                            <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars($item->product_name); ?></td>
                            <td class="px-4 py-3 text-sm">
                                <?php echo htmlspecialchars(implode(' - ', $variant_display)); ?>
                                <?php if ($item->variant_sku): ?>
                                    <span class="text-xs text-gray-500">(<?php echo htmlspecialchars($item->variant_sku); ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-right">
                                <?php echo $item->quantity; ?> <?php echo $item->unit_of_measure; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-right">৳<?php echo number_format($item->unit_price, 2); ?></td>
                            <td class="px-4 py-3 text-sm text-right font-medium">৳<?php echo number_format($item->line_total, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-right font-semibold">Subtotal:</td>
                            <td class="px-4 py-3 text-right font-bold">৳<?php echo number_format($order->subtotal, 2); ?></td>
                        </tr>
                        <?php if ($order->discount_amount > 0): ?>
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-right font-semibold">Discount:</td>
                            <td class="px-4 py-3 text-right font-bold text-red-600">-৳<?php echo number_format($order->discount_amount, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($order->tax_amount > 0): ?>
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-right font-semibold">Tax:</td>
                            <td class="px-4 py-3 text-right font-bold">৳<?php echo number_format($order->tax_amount, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="bg-blue-50">
                            <td colspan="4" class="px-4 py-3 text-right font-semibold text-lg">Total:</td>
                            <td class="px-4 py-3 text-right font-bold text-blue-600 text-lg">৳<?php echo number_format($order->total_amount, 2); ?></td>
                        </tr>
                        <?php if ($order->advance_paid > 0): ?>
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-right font-semibold">Advance Paid:</td>
                            <td class="px-4 py-3 text-right font-bold text-green-600">-৳<?php echo number_format($order->advance_paid, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="bg-green-50">
                            <td colspan="4" class="px-4 py-3 text-right font-semibold text-lg">Balance Due:</td>
                            <td class="px-4 py-3 text-right font-bold text-green-600 text-lg">৳<?php echo number_format($order->balance_due, 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        
        <?php /* Order Timeline moved to the top of the page (horizontal, animated) */ ?>

        <!-- Deliveries Section -->
        <?php if (!empty($deliveries)): ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4"><i class="fas fa-truck mr-2 text-indigo-500"></i>Delivery History</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Delivery #</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Truck / Driver</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($deliveries as $d): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2 font-medium text-indigo-700"><?php echo htmlspecialchars($d->delivery_number); ?></td>
                            <td class="px-3 py-2 text-gray-600"><?php echo date('d-M-Y', strtotime($d->delivery_date)); ?></td>
                            <td class="px-3 py-2 text-gray-600">
                                <?php echo htmlspecialchars($d->truck_number ?: '—'); ?>
                                <?php if ($d->driver_name): ?><br><span class="text-xs text-gray-400"><?php echo htmlspecialchars($d->driver_name); ?></span><?php endif; ?>
                            </td>
                            <td class="px-3 py-2 text-right"><?php echo number_format($d->total_qty_delivered, 2); ?></td>
                            <td class="px-3 py-2 text-right font-semibold">৳<?php echo number_format($d->total_amount_delivered, 2); ?></td>
                            <td class="px-3 py-2 text-center">
                                <span class="px-2 py-0.5 text-xs rounded-full <?php echo $d->is_final ? 'bg-green-100 text-green-800' : 'bg-indigo-100 text-indigo-800'; ?>">
                                    <?php echo $d->is_final ? 'Final' : 'Partial'; ?>
                                </span>
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-500"><?php echo htmlspecialchars($d->delivered_by_name ?? '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50 border-t-2 border-gray-300">
                        <tr>
                            <td colspan="3" class="px-3 py-2 text-right font-bold text-gray-700">Total Delivered:</td>
                            <td class="px-3 py-2 text-right font-bold"><?php echo number_format(array_sum(array_column((array)$deliveries,'total_qty_delivered')),2); ?></td>
                            <td class="px-3 py-2 text-right font-bold text-indigo-700">৳<?php echo number_format(array_sum(array_column((array)$deliveries,'total_amount_delivered')),2); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Returns Section -->
        <?php if (!empty($returns)): ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4"><i class="fas fa-undo-alt mr-2 text-orange-500"></i>Returns History</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Return #</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Credit Note</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Approved By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($returns as $r):
                            $rsc = ['pending'=>'bg-yellow-100 text-yellow-800','approved'=>'bg-green-100 text-green-800','rejected'=>'bg-red-100 text-red-800'][$r->status] ?? 'bg-gray-100 text-gray-800';
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2 font-medium text-orange-700"><?php echo htmlspecialchars($r->return_number); ?></td>
                            <td class="px-3 py-2 text-gray-600"><?php echo date('d-M-Y', strtotime($r->return_date)); ?></td>
                            <td class="px-3 py-2 text-gray-600 max-w-[180px] truncate" title="<?php echo htmlspecialchars($r->return_reason ?? ''); ?>">
                                <?php echo htmlspecialchars($r->return_reason ?: '—'); ?>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <span class="px-2 py-0.5 text-xs rounded-full <?php echo $r->return_type === 'full' ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700'; ?>">
                                    <?php echo ucfirst($r->return_type ?? ''); ?>
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right font-bold text-orange-700">৳<?php echo number_format($r->total_returned_amount ?? 0, 2); ?></td>
                            <td class="px-3 py-2 text-center">
                                <span class="px-2 py-0.5 text-xs font-bold rounded-full <?php echo $rsc; ?>"><?php echo ucfirst($r->status ?? ''); ?></span>
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-500"><?php echo htmlspecialchars($r->approved_by_name ?? '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50 border-t-2 border-gray-300">
                        <tr>
                            <td colspan="4" class="px-3 py-2 text-right font-bold text-gray-700">Total Returned:</td>
                            <td class="px-3 py-2 text-right font-bold text-orange-700">
                                ৳<?php echo number_format(array_sum(array_filter(array_map(function($r){ return $r->status==='approved' ? (float)$r->total_returned_amount : 0; }, (array)$returns))),2); ?>
                                <div class="text-xs text-gray-400 font-normal">(approved only)</div>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Over-Delivery Section -->
        <?php if (!empty($over_deliveries)): ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4"><i class="fas fa-truck-loading mr-2 text-blue-500"></i>Over-Delivery History</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">OD #</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Resolution</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Extra Value</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php
                        $od_res_meta = [
                            'bill'     => ['Bill Customer',  'bg-blue-100 text-blue-800'],
                            'retrieve' => ['Retrieve Goods', 'bg-orange-100 text-orange-800'],
                            'writeoff' => ['Write Off',      'bg-gray-200 text-gray-700'],
                        ];
                        foreach ($over_deliveries as $od):
                            $odsc = ['pending'=>'bg-yellow-100 text-yellow-800','approved'=>'bg-green-100 text-green-800','rejected'=>'bg-red-100 text-red-800'][$od->status] ?? 'bg-gray-100 text-gray-800';
                            $odr  = $od_res_meta[$od->resolution] ?? [$od->resolution, 'bg-gray-100 text-gray-700'];
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2 font-medium text-blue-700"><?php echo htmlspecialchars($od->od_number); ?></td>
                            <td class="px-3 py-2 text-gray-600"><?php echo date('d-M-Y', strtotime($od->od_date)); ?></td>
                            <td class="px-3 py-2 text-gray-600 max-w-[180px] truncate" title="<?php echo htmlspecialchars($od->reason ?? ''); ?>">
                                <?php echo htmlspecialchars($od->reason ?: '—'); ?>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <span class="px-2 py-0.5 text-xs rounded-full <?php echo $odr[1]; ?>"><?php echo $odr[0]; ?></span>
                                <?php if ($od->resolution === 'retrieve' && $od->status === 'approved'): ?>
                                <div class="text-[10px] mt-0.5 <?php echo $od->retrieved_at ? 'text-green-600' : 'text-orange-600'; ?>">
                                    <?php echo $od->retrieved_at ? '✓ Retrieved ' . date('d M', strtotime($od->retrieved_at)) : '⏳ Awaiting collection'; ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-2 text-right font-bold text-blue-700">৳<?php echo number_format($od->total_extra_amount ?? 0, 2); ?></td>
                            <td class="px-3 py-2 text-center">
                                <span class="px-2 py-0.5 text-xs font-bold rounded-full <?php echo $odsc; ?>"><?php echo ucfirst($od->status ?? ''); ?></span>
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-500"><?php echo htmlspecialchars($od->approved_by_name ?? $od->created_by_name ?? '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Payments & Collections Section -->
        <?php if (!empty($payments) || (float)($order->advance_paid ?? 0) > 0 || $legacy_paid > 0): ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4"><i class="fas fa-money-bill-wave mr-2 text-green-500"></i>Payments &amp; Collections</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Receipt #</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Method / Ref</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Applied to This Order</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Collected By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if ((float)($order->advance_paid ?? 0) > 0): ?>
                        <tr class="hover:bg-gray-50 bg-emerald-50/40">
                            <td class="px-3 py-2 font-medium text-emerald-700">Advance</td>
                            <td class="px-3 py-2 text-gray-600"><?php echo date('d-M-Y', strtotime($order->order_date)); ?></td>
                            <td class="px-3 py-2 text-gray-500 text-xs">Paid with / after order placement</td>
                            <td class="px-3 py-2 text-right font-semibold text-emerald-700">৳<?php echo number_format($order->advance_paid, 2); ?></td>
                            <td class="px-3 py-2 text-xs text-gray-400">—</td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($legacy_paid > 0): ?>
                        <tr class="hover:bg-gray-50 bg-gray-50/60">
                            <td class="px-3 py-2 font-medium text-gray-600">On Record</td>
                            <td class="px-3 py-2 text-gray-600"><?php echo $order->updated_at ? date('d-M-Y', strtotime($order->updated_at)) : '—'; ?></td>
                            <td class="px-3 py-2 text-gray-500 text-xs">Collected before receipt-level tracking — see customer ledger for detail</td>
                            <td class="px-3 py-2 text-right font-semibold text-green-700">৳<?php echo number_format($legacy_paid, 2); ?></td>
                            <td class="px-3 py-2 text-xs text-gray-400">—</td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($payments as $p): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2 font-medium text-green-700"><?php echo htmlspecialchars($p->payment_number ?? '—'); ?></td>
                            <td class="px-3 py-2 text-gray-600"><?php echo date('d-M-Y', strtotime($p->payment_date)); ?></td>
                            <td class="px-3 py-2 text-gray-600">
                                <span class="capitalize"><?php echo htmlspecialchars($p->payment_method ?? '—'); ?></span>
                                <?php if (!empty($p->reference_number)): ?>
                                <span class="text-xs text-gray-400 font-mono ml-1"><?php echo htmlspecialchars($p->reference_number); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($p->bank_name)): ?>
                                <div class="text-[11px] text-gray-500 mt-0.5">
                                    <i class="fas fa-university mr-1 text-gray-300"></i><?php
                                    echo htmlspecialchars($p->bank_name . ' — ' . ($p->account_name ?? ''));
                                    if (!empty($p->account_number)) echo ' · A/C ' . htmlspecialchars($p->account_number);
                                    ?>
                                </div>
                                <?php else: ?>
                                <div class="text-[11px] text-gray-500 mt-0.5">
                                    <i class="fas fa-hand-holding-usd mr-1 text-gray-300"></i>Cash — collected by <?php echo htmlspecialchars($p->collected_by ?? '—'); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-2 text-right font-semibold text-green-700">৳<?php echo number_format($p->allocated_amount, 2); ?></td>
                            <td class="px-3 py-2 text-xs text-gray-500"><?php echo htmlspecialchars($p->collected_by ?? '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50 border-t-2 border-gray-300">
                        <?php
                        $total_collected = (float)($order->advance_paid ?? 0)
                                         + $legacy_paid
                                         + array_sum(array_map(fn($p) => (float)$p->allocated_amount, $payments));
                        ?>
                        <tr>
                            <td colspan="3" class="px-3 py-2 text-right font-bold text-gray-700">Total Collected:</td>
                            <td class="px-3 py-2 text-right font-bold text-green-700">৳<?php echo number_format($total_collected, 2); ?></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td colspan="3" class="px-3 py-2 text-right font-bold text-gray-700">Remaining Balance:</td>
                            <td class="px-3 py-2 text-right font-bold <?php echo (float)$order->balance_due > 0 ? 'text-red-600' : 'text-green-700'; ?>">
                                ৳<?php echo number_format($order->balance_due, 2); ?>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sidebar -->
    <div class="space-y-6">
        
        <!-- Credit Information -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Credit Information</h3>
            <div class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-600">Credit Limit:</span>
                    <span class="font-bold">৳<?php echo number_format($order->credit_limit, 0); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600">Current Balance:</span>
                    <span class="font-bold text-orange-600">৳<?php echo number_format($order->current_balance, 0); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600">Available Credit:</span>
                    <span class="font-bold text-green-600">৳<?php echo number_format($order->available_credit, 0); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600">Initial Due:</span>
                    <span class="font-bold text-gray-500">৳<?php echo number_format($order->initial_due ?? 0, 0); ?></span>
                </div>
                <div class="flex justify-between pt-2 border-t">
                    <span class="text-gray-600">This Order Balance:</span>
                    <span class="font-bold text-blue-600">৳<?php echo number_format($order->balance_due, 0); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600">Credit Usage:</span>
                    <span class="font-bold">
                        <?php
                        $credit_used  = max(0, (float)$order->current_balance - (float)($order->initial_due ?? 0));
                        $usage = (float)$order->credit_limit > 0 ? ($credit_used / (float)$order->credit_limit) * 100 : 0;
                        $usage_color  = $usage > 90 ? 'text-red-600' : ($usage > 70 ? 'text-orange-600' : 'text-green-600');
                        echo '<span class="' . $usage_color . '">' . number_format($usage, 1) . '%</span>';
                        ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Production Info -->
        <?php if (in_array($order->status, ['in_production', 'produced', 'ready_to_ship', 'shipped', 'delivered'])): ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Production Details</h3>
            <div class="space-y-2 text-sm">
                <?php if ($order->scheduled_date): ?>
                <div>
                    <p class="text-gray-600">Scheduled Date</p>
                    <p class="font-bold"><?php echo date('M j, Y', strtotime($order->scheduled_date)); ?></p>
                </div>
                <?php endif; ?>
                <?php if ($order->production_started_at): ?>
                <div>
                    <p class="text-gray-600">Started</p>
                    <p class="font-bold"><?php echo date('M j, Y g:i A', strtotime($order->production_started_at)); ?></p>
                </div>
                <?php endif; ?>
                <?php if ($order->production_completed_at): ?>
                <div>
                    <p class="text-gray-600">Completed</p>
                    <p class="font-bold"><?php echo date('M j, Y g:i A', strtotime($order->production_completed_at)); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Shipping Info -->
        <?php if (in_array($order->status, ['shipped', 'delivered'])): ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Shipping Details</h3>
            <div class="space-y-2 text-sm">
                <div>
                    <p class="text-gray-600">Truck Number</p>
                    <p class="font-bold"><?php echo htmlspecialchars($order->truck_number ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Driver</p>
                    <p class="font-bold"><?php echo htmlspecialchars($order->driver_name ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Contact</p>
                    <p class="font-bold"><?php echo htmlspecialchars($order->driver_contact ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Shipped Date</p>
                    <p class="font-bold"><?php echo $order->shipped_date ? date('M j, Y g:i A', strtotime($order->shipped_date)) : '—'; ?></p>
                </div>
                <?php if ($order->delivered_date): ?>
                <div>
                    <p class="text-gray-600">Delivered Date</p>
                    <p class="font-bold text-green-600"><?php echo date('M j, Y g:i A', strtotime($order->delivered_date)); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Quick Actions -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Quick Actions</h3>
            <div class="space-y-2">
                <a href="customer_ledger.php?customer_id=<?php echo $order->customer_id; ?>"
                   class="block px-4 py-2 text-sm text-center bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-book mr-2"></i>View Customer Ledger
                </a>
                <?php if (in_array($order->status, ['shipped', 'delivered'])): ?>
                <a href="credit_invoice_print.php?id=<?php echo $order->id; ?>" target="_blank"
                   class="block px-4 py-2 text-sm text-center bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                    <i class="fas fa-print mr-2"></i>Print Invoice
                </a>
                <?php endif; ?>
                <?php
                $partial_delivery_statuses = ['approved', 'in_production', 'produced', 'ready_to_ship', 'shipped'];
                $can_partial_deliver = in_array($order->status, $partial_delivery_statuses)
                    && in_array($currentUser['role'] ?? '', ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra', 'dispatch-srg', 'dispatch-demra', 'dispatchpos-srg', 'dispatchpos-demra']);
                ?>
                <?php if ($can_partial_deliver): ?>
                <a href="partial_delivery.php?order_id=<?php echo $order->id; ?>"
                   class="block px-4 py-2 text-sm text-center bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                    <i class="fas fa-truck mr-2"></i>Record Delivery
                </a>
                <?php endif; ?>
                <?php if (in_array($order->status, ['shipped', 'delivered'])): ?>
                <a href="returns.php?order_id=<?php echo $order->id; ?>"
                   class="block px-4 py-2 text-sm text-center bg-red-600 text-white rounded-lg hover:bg-red-700">
                    <i class="fas fa-undo mr-2"></i>Record Return
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</div>

<style media="print">
@media print {
    .no-print { display: none !important; }
    body { font-size: 12px; }
    .shadow-md { box-shadow: none !important; }
}
</style>

<!-- ══════════════════════════════════════════════════════════════════════
     ACTION MODAL — loads the real tool pages in-place (same-origin iframe,
     site chrome stripped). Closing after any change reloads the order view.
═══════════════════════════════════════════════════════════════════════ -->
<div id="ovModal" class="fixed inset-0 bg-black/60 z-50 hidden">
    <div class="absolute inset-2 md:inset-x-12 md:inset-y-6 bg-white rounded-xl shadow-2xl flex flex-col overflow-hidden"
         onclick="event.stopPropagation()">
        <div class="px-4 py-3 border-b border-gray-200 bg-gray-50 flex items-center justify-between flex-shrink-0">
            <h3 id="ovTitle" class="font-bold text-gray-800 text-sm"><i class="fas fa-bolt mr-1 text-blue-500"></i><span></span></h3>
            <div class="flex items-center gap-3">
                <a id="ovPop" href="#" target="_blank" class="text-gray-400 hover:text-blue-600 text-sm" title="Open as full page">
                    <i class="fas fa-external-link-alt"></i>
                </a>
                <button type="button" onclick="ovClose()" class="text-gray-400 hover:text-red-600 cursor-pointer" title="Close">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
        </div>
        <div class="relative flex-1">
            <div id="ovLoad" class="absolute inset-0 flex items-center justify-center bg-white/80 z-10" style="display:none">
                <i class="fas fa-circle-notch fa-spin text-3xl text-blue-500"></i>
            </div>
            <iframe id="ovFrame" class="w-full h-full border-0" title="Order action"></iframe>
        </div>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('ovModal');
    const frame = document.getElementById('ovFrame');
    const loadr = document.getElementById('ovLoad');
    let dirty = false;   // any navigation after first load = something changed

    window.ovOpen = function (title, url) {
        dirty = false;
        frame.dataset.loads = '0';
        document.querySelector('#ovTitle span').textContent = title;
        document.getElementById('ovPop').href = url;
        loadr.style.display = 'flex';
        frame.src = url;
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    };
    window.ovClose = function () {
        modal.classList.add('hidden');
        frame.src = 'about:blank';
        document.body.style.overflow = '';
        if (dirty) location.reload();   // reflect whatever the tool changed
    };

    frame.addEventListener('load', function () {
        if (frame.src === 'about:blank' || !frame.src) return;
        loadr.style.display = 'none';
        // A second load means a form submitted / redirect happened inside
        const n = parseInt(frame.dataset.loads || '0', 10) + 1;
        frame.dataset.loads = String(n);
        if (n > 1) dirty = true;
        // Strip the site chrome so the tool renders like a native panel
        try {
            const doc = frame.contentDocument;
            if (doc && !doc.getElementById('ovEmbedCss')) {
                const s = doc.createElement('style');
                s.id = 'ovEmbedCss';
                s.textContent = 'nav{display:none !important}footer{display:none !important}body{padding-top:0 !important}';
                (doc.head || doc.documentElement).appendChild(s);
            }
        } catch (e) { /* cross-origin — ignore */ }
    });

    modal.addEventListener('click', function (e) { if (e.target === modal) ovClose(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) ovClose();
    });
})();
</script>

<?php require_once '../templates/footer.php'; ?>
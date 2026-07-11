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

// Get workflow history
$workflow = $db->query(
    "SELECT cow.*, u.display_name as performed_by_name
     FROM credit_order_workflow cow
     LEFT JOIN users u ON cow.performed_by_user_id = u.id
     WHERE cow.order_id = ?
     ORDER BY cow.created_at ASC",
    [$order_id]
)->results();

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

// Payment history is accessible via customer ledger link
$payments = [];

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
            $cond_type = in_array($_POST['condition_type'] ?? '', ['manual', 'outstanding_below', 'amount_received'])
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

<!-- ── Approval Conditions Card ─────────────────────────────────────────── -->
<?php if ($gate['has_conditions']):
    $grow          = $gate['row'];
    $prod_held     = $gate['production'] === 'held';
    $disp_state    = $gate['dispatch'];     // open|held|condition_met|cleared
    $has_disp_gate = (int)$grow->dispatch_hold === 1;
    $cond_labels   = [
        'manual'            => 'Manual clearance by Accounts',
        'outstanding_below' => 'Outstanding must drop to ≤ threshold',
        'amount_received'   => 'Payments received since approval ≥ threshold',
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
                    <?php echo $grow->condition_type === 'outstanding_below' ? 'Outstanding now' : 'Received so far'; ?>:
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
                            <select name="condition_type" class="w-full px-3 py-2 border rounded-lg text-xs"
                                    onchange="this.form.querySelector('.cond-amt-row').style.display = this.value === 'manual' ? 'none' : 'block';">
                                <option value="manual" <?php echo $grow->condition_type === 'manual' ? 'selected' : ''; ?>>Manual clearance</option>
                                <option value="outstanding_below" <?php echo $grow->condition_type === 'outstanding_below' ? 'selected' : ''; ?>>Outstanding ≤ amount</option>
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
        
        <!-- Workflow History / Timeline -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4"><i class="fas fa-history mr-2 text-blue-500"></i>Order Timeline</h2>
            <?php if (empty($workflow)): ?>
            <div class="text-center py-6 text-gray-400">
                <i class="fas fa-clock text-3xl mb-2"></i>
                <p class="text-sm">No workflow history yet. Actions will appear here as the order progresses.</p>
            </div>
            <?php else: ?>
            <div class="relative">
                <div class="absolute left-5 top-0 bottom-0 w-0.5 bg-blue-100"></div>
                <div class="space-y-4">
                <?php
                $wf_icons = [
                    'create'   => ['bg'=>'bg-blue-100','ic'=>'fas fa-plus text-blue-600'],
                    'approve'  => ['bg'=>'bg-green-100','ic'=>'fas fa-check text-green-600'],
                    'reject'   => ['bg'=>'bg-red-100','ic'=>'fas fa-times text-red-600'],
                    'produce'  => ['bg'=>'bg-purple-100','ic'=>'fas fa-industry text-purple-600'],
                    'ship'     => ['bg'=>'bg-teal-100','ic'=>'fas fa-truck text-teal-600'],
                    'deliver'  => ['bg'=>'bg-green-100','ic'=>'fas fa-box-open text-green-600'],
                    'escalate' => ['bg'=>'bg-orange-100','ic'=>'fas fa-exclamation text-orange-600'],
                    'payment'  => ['bg'=>'bg-emerald-100','ic'=>'fas fa-money-bill text-emerald-600'],
                ];
                foreach ($workflow as $entry):
                    $wi = $wf_icons[$entry->action] ?? ['bg'=>'bg-gray-100','ic'=>'fas fa-arrow-right text-gray-500'];
                ?>
                <div class="flex gap-4 relative">
                    <div class="flex-shrink-0 relative z-10">
                        <div class="w-10 h-10 rounded-full <?php echo $wi['bg']; ?> flex items-center justify-center border-2 border-white shadow-sm">
                            <i class="<?php echo $wi['ic']; ?> text-sm"></i>
                        </div>
                    </div>
                    <div class="flex-1 bg-gray-50 rounded-lg p-3 min-h-[60px]">
                        <div class="flex justify-between items-start">
                            <p class="font-semibold text-sm text-gray-800">
                                <?php echo ucwords(str_replace('_', ' ', $entry->action)); ?>
                            </p>
                            <span class="text-xs text-gray-400 whitespace-nowrap ml-2">
                                <?php echo date('d M Y, H:i', strtotime($entry->created_at)); ?>
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 mt-0.5">
                            <span class="inline-block px-1.5 py-0.5 rounded bg-gray-200 text-gray-700"><?php echo ucwords(str_replace('_', ' ', $entry->from_status)); ?></span>
                            <i class="fas fa-long-arrow-alt-right mx-1"></i>
                            <span class="inline-block px-1.5 py-0.5 rounded bg-blue-100 text-blue-700"><?php echo ucwords(str_replace('_', ' ', $entry->to_status)); ?></span>
                        </p>
                        <?php if ($entry->comments): ?>
                        <p class="text-sm text-gray-600 mt-1 italic"><?php echo htmlspecialchars($entry->comments); ?></p>
                        <?php endif; ?>
                        <p class="text-xs text-gray-400 mt-1">
                            <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($entry->performed_by_name ?? 'System'); ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

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

<?php require_once '../templates/footer.php'; ?>
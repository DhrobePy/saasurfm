<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra'];
restrict_access($allowed_roles, 'credit_sales', 'payment_watch');

global $db;
$currentUser = getCurrentUser();
$user_id   = $currentUser['id']   ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Payment Watch — Dispatch Clearance';

$is_admin = in_array($user_role, ['Superadmin', 'admin']);

$is_accounts_role = in_array($user_role, ['Accounts', 'accounts-srg', 'accounts-demra']);

// Clearance is an Accounts function BY DEFAULT, but it is delegable: an admin can
// hand it to ANY user by ticking "Grant Dispatch Clearance" in User Privileges
// (which also grants them the Payment Watch page). So the privilege toggle — not
// the role — decides it. Admins always may; anyone with the tick may.
$can_grant  = $is_admin || userCanPageAction('credit_sales', 'payment_watch', 'can_grant_clearance');
$can_revoke = $is_admin || userCanPageAction('credit_sales', 'payment_watch', 'can_revoke_clearance');
// Feature #12: Accounts/Admin can also collect payment right here, beside the override.
$can_collect = $is_admin || $is_accounts_role;

// Delegated early-release authority — SAME pattern as credit_order_approval.php
// and order_amendment.php: admin/privileges.php sets a personal ৳ limit per
// user; an officer with can_grant_clearance ticked AND a limit set may release
// an unmet condition early as long as the ORDER's value is within their limit.
// Without a limit, they can only grant once the condition is genuinely met
// (or it's a manual condition, which is accounts' judgment call by definition).
$my_limit = $is_admin ? null : getUserActionLimit((int)$user_id, 'early_release', true);

// Table must exist before any POST transaction (DDL would implicitly commit)
ensureApprovalGateTables();

// Feature #5: under the global dispatch hold, make sure every dispatchable order
// has a hold row so it appears here for Accounts/Admin to clear. Idempotent.
provisionDefaultDispatchHolds();

/* ── POST: Grant clearance ──────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'grant_clearance') {
    if (!$can_grant) {
        $_SESSION['error_flash'] = 'You do not have permission to grant clearance.';
    } else {
        $order_id = (int)($_POST['order_id'] ?? 0);
        $note     = trim($_POST['clearance_note'] ?? '');
        try {
            $cond = $db->query(
                "SELECT oac.*, co.order_number, co.status AS order_status, co.total_amount
                 FROM order_approval_conditions oac
                 JOIN credit_orders co ON co.id = oac.order_id
                 WHERE oac.order_id = ? AND oac.dispatch_hold = 1 AND oac.dispatch_cleared = 0",
                [$order_id]
            )->first();
            if (!$cond) throw new Exception('Hold not found or already cleared.');

            $used_delegated_authority = false;

            // Amount-based conditions can only be cleared EARLY by an admin, OR by
            // an officer whose personal delegated limit covers this order's value.
            // Accounts may always grant once the numbers are genuinely satisfied;
            // 'manual' conditions are accounts' judgment call by definition.
            if (!$is_admin && $cond->condition_type !== 'manual') {
                $g = getOrderGateState($order_id);
                if (!in_array($g['dispatch'], ['condition_met', 'cleared'])) {
                    $delegated_ok = $my_limit !== null && (float)$cond->total_amount <= $my_limit;
                    if (!$delegated_ok) {
                        $short = ($g['shortfall'] !== null && $g['shortfall'] > 0)
                               ? ' (৳' . number_format($g['shortfall'], 0) . ' still short)'
                               : '';
                        $limit_note = $my_limit !== null
                            ? " Your delegated limit is ৳" . number_format($my_limit, 0)
                              . ", but this order is ৳" . number_format((float)$cond->total_amount, 0) . "."
                            : '';
                        throw new Exception("Payment condition for {$cond->order_number} is NOT met yet{$short}. "
                            . "Only an admin (or an officer with sufficient delegated limit) can clear it early."
                            . $limit_note);
                    }
                    $used_delegated_authority = true;
                }
                if ($g['dispatch'] === 'cleared') {
                    // Auto-release already handled it during evaluation
                    $_SESSION['success_flash'] = "Dispatch already released for {$cond->order_number}.";
                    header('Location: payment_watch.php');
                    exit();
                }
            }

            $db->query(
                "UPDATE order_approval_conditions
                 SET dispatch_cleared = 1, cleared_by = ?, cleared_at = NOW(), clearance_note = ?
                 WHERE order_id = ?",
                [$user_id, $note ?: null, $order_id]
            );
            logGateEvent($order_id, 'dispatch_cleared',
                'Dispatch clearance GRANTED by ' . ($currentUser['display_name'] ?? 'user')
                . ($used_delegated_authority ? ' [EARLY — delegated limit ৳' . number_format($my_limit, 0) . ']' : '')
                . ($note !== '' ? ' — ' . $note : ''));

            $_SESSION['success_flash'] = "Dispatch clearance granted for {$cond->order_number}.";
            // One-shot undo offer on the next page load (for accidental grants)
            $_SESSION['undo_clearance'] = ['order_id' => $order_id, 'order_number' => $cond->order_number];
        } catch (Exception $e) {
            $_SESSION['error_flash'] = $e->getMessage();
        }
    }
    header('Location: payment_watch.php');
    exit();
}

/* ── POST: Revoke clearance (before dispatch only) ──────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke_clearance') {
    if (!$can_revoke) {
        $_SESSION['error_flash'] = 'You do not have permission to revoke clearance.';
    } else {
        $order_id = (int)($_POST['order_id'] ?? 0);
        $reason   = trim($_POST['revoke_reason'] ?? '');
        try {
            if ($reason === '') throw new Exception('A reason is required to revoke clearance.');
            $cond = $db->query(
                "SELECT oac.*, co.order_number, co.status AS order_status
                 FROM order_approval_conditions oac
                 JOIN credit_orders co ON co.id = oac.order_id
                 WHERE oac.order_id = ? AND oac.dispatch_hold = 1 AND oac.dispatch_cleared = 1",
                [$order_id]
            )->first();
            if (!$cond) throw new Exception('Clearance not found.');
            if (in_array($cond->order_status, ['shipped', 'delivered'])) {
                throw new Exception('Order already dispatched — clearance can no longer be revoked.');
            }

            $db->query(
                "UPDATE order_approval_conditions
                 SET dispatch_cleared = 0, cleared_by = NULL, cleared_at = NULL, clearance_note = NULL,
                     auto_release = 0
                 WHERE order_id = ?",
                [$order_id]
            );
            logGateEvent($order_id, 'clearance_revoked',
                'Dispatch clearance REVOKED by ' . ($currentUser['display_name'] ?? 'user') . ' — ' . $reason);

            $_SESSION['success_flash'] = "Clearance revoked for {$cond->order_number}. Dispatch is held again.";
        } catch (Exception $e) {
            $_SESSION['error_flash'] = $e->getMessage();
        }
    }
    header('Location: payment_watch.php');
    exit();
}

/* ── Filters, tabs & pagination ─────────────────────────────────────────── */
$show       = $_GET['show'] ?? 'active';   // active | cleared | all
$f_user     = (int)($_GET['f_user'] ?? 0);
$f_customer = (int)($_GET['f_customer'] ?? 0);
$f_order    = trim($_GET['f_order'] ?? '');
$f_status   = trim($_GET['f_status'] ?? '');
$f_from     = trim($_GET['f_from'] ?? '');
$f_to       = trim($_GET['f_to'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 20;

$where  = ["oac.dispatch_hold = 1"];
$params = [];
if ($show === 'active')  $where[] = "oac.dispatch_cleared = 0";
if ($show === 'cleared') $where[] = "oac.dispatch_cleared = 1";

if ($f_status !== '') {
    $where[]  = "co.status = ?";
    $params[] = $f_status;
} else {
    // Default views hide finished orders — an explicit status filter overrides
    $where[] = "co.status NOT IN ('delivered','cancelled','rejected')";
}
if ($f_user > 0)      { $where[] = "(oac.approved_by_user_id = ? OR oac.cleared_by = ?)"; $params[] = $f_user; $params[] = $f_user; }
if ($f_customer > 0)  { $where[] = "co.customer_id = ?";            $params[] = $f_customer; }
if ($f_order !== '')  { $where[] = "co.order_number LIKE ?";        $params[] = '%' . $f_order . '%'; }
if ($f_from  !== '')  { $where[] = "DATE(oac.approved_at) >= ?";    $params[] = $f_from; }
if ($f_to    !== '')  { $where[] = "DATE(oac.approved_at) <= ?";    $params[] = $f_to; }
$where_sql = implode(' AND ', $where);

// Count for pagination
$total_rows = 0;
try {
    $total_rows = (int)($db->query(
        "SELECT COUNT(*) c
         FROM order_approval_conditions oac
         JOIN credit_orders co ON co.id = oac.order_id
         WHERE $where_sql", $params
    )->first()->c ?? 0);
} catch (Exception $e) {}
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$held_orders = $db->query(
    "SELECT oac.*, co.order_number, co.status AS order_status, co.total_amount, co.balance_due,
            co.customer_id, co.order_date, co.required_date,
            c.name AS customer_name, c.phone_number AS customer_phone, c.credit_limit,
            b.name AS branch_name,
            ua.display_name AS approved_by_name,
            uc.display_name AS cleared_by_name
     FROM order_approval_conditions oac
     JOIN credit_orders co ON co.id = oac.order_id
     JOIN customers c ON co.customer_id = c.id
     LEFT JOIN branches b ON co.assigned_branch_id = b.id
     LEFT JOIN users ua ON oac.approved_by_user_id = ua.id
     LEFT JOIN users uc ON oac.cleared_by = uc.id
     WHERE $where_sql
     ORDER BY oac.dispatch_cleared ASC, oac.approved_at DESC
     LIMIT $per_page OFFSET $offset",
    $params
)->results();

// Dropdown data — only users/customers that actually appear in holds
$filter_users = [];
$filter_customers = [];
try {
    $filter_users = $db->query(
        "SELECT DISTINCT u.id, u.display_name
         FROM users u
         WHERE u.id IN (SELECT approved_by_user_id FROM order_approval_conditions)
            OR u.id IN (SELECT cleared_by FROM order_approval_conditions WHERE cleared_by IS NOT NULL)
         ORDER BY u.display_name"
    )->results();
    $filter_customers = $db->query(
        "SELECT DISTINCT c.id, c.name
         FROM order_approval_conditions oac
         JOIN credit_orders co ON co.id = oac.order_id
         JOIN customers c ON c.id = co.customer_id
         ORDER BY c.name"
    )->results();
} catch (Exception $e) {}

// Query string base for tab/pager links (keeps filters, swaps show/page)
$qs_base = $_GET;
unset($qs_base['show'], $qs_base['page']);

// Evaluate live gate state per order (also triggers auto-release where opted in)
$gate_by_order = [];
foreach ($held_orders as $h) {
    $gate_by_order[$h->order_id] = getOrderGateState((int)$h->order_id);
}

require_once '../templates/header.php';

$cond_labels = [
    'manual'                 => 'Manual clearance by Accounts',
    'outstanding_below'      => 'Outstanding must drop to threshold (excl. this invoice)',
    'outstanding_after_ship' => 'Outstanding INCL. this invoice must drop to threshold',
    'amount_received'        => 'Payment received since approval',
];
?>

<div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-eye mr-2 text-blue-500"></i><?php echo $pageTitle; ?></h1>
        <p class="text-sm text-gray-500 mt-1">Orders held by admin pending payment — grant clearance to unlock dispatch</p>
    </div>
    <div class="flex gap-1 bg-gray-100 rounded-lg p-1">
        <?php foreach (['active' => 'Awaiting Clearance', 'cleared' => 'Cleared', 'all' => 'All'] as $key => $lbl): ?>
        <a href="?<?php echo http_build_query(array_merge($qs_base, ['show' => $key])); ?>"
           class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors cursor-pointer
                  <?php echo $show === $key ? 'bg-white shadow text-blue-700' : 'text-gray-600 hover:text-gray-900'; ?>">
            <?php echo $lbl; ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── Filter bar ─────────────────────────────────────────────────────────── -->
<form method="GET" class="bg-white rounded-xl border border-gray-200 shadow-sm px-4 py-3 mb-4">
    <input type="hidden" name="show" value="<?php echo htmlspecialchars($show); ?>">
    <div class="flex flex-wrap gap-3 items-end">
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Order #</label>
            <input type="text" name="f_order" value="<?php echo htmlspecialchars($f_order); ?>"
                   placeholder="CR-2026..." class="text-xs px-2 py-1.5 border border-gray-300 rounded w-32 focus:ring-1 focus:ring-blue-500">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Customer</label>
            <select name="f_customer" class="text-xs px-2 py-1.5 border border-gray-300 rounded min-w-[150px]">
                <option value="0">All Customers</option>
                <?php foreach ($filter_customers as $fc): ?>
                <option value="<?php echo $fc->id; ?>" <?php echo $f_customer === (int)$fc->id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($fc->name); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">User (held / cleared by)</label>
            <select name="f_user" class="text-xs px-2 py-1.5 border border-gray-300 rounded min-w-[140px]">
                <option value="0">All Users</option>
                <?php foreach ($filter_users as $fu): ?>
                <option value="<?php echo $fu->id; ?>" <?php echo $f_user === (int)$fu->id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($fu->display_name); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Order Status</label>
            <select name="f_status" class="text-xs px-2 py-1.5 border border-gray-300 rounded min-w-[130px]">
                <option value="">Default (open)</option>
                <?php foreach (['approved' => 'Approved', 'in_production' => 'In Production', 'produced' => 'Produced',
                               'ready_to_ship' => 'Ready to Ship', 'shipped' => 'Shipped', 'delivered' => 'Delivered'] as $sv => $sl): ?>
                <option value="<?php echo $sv; ?>" <?php echo $f_status === $sv ? 'selected' : ''; ?>><?php echo $sl; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Date Range (held on)</label>
            <select id="pwRange" class="text-xs px-2 py-1.5 border border-gray-300 rounded min-w-[120px]">
                <option value="">Custom / All</option>
                <option value="today">Today</option>
                <option value="7d">Last 7 Days</option>
                <option value="month">This Month</option>
                <option value="last_month">Last Month</option>
            </select>
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">From</label>
            <input type="date" name="f_from" id="pwFrom" value="<?php echo htmlspecialchars($f_from); ?>"
                   class="text-xs px-2 py-1.5 border border-gray-300 rounded">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">To</label>
            <input type="date" name="f_to" id="pwTo" value="<?php echo htmlspecialchars($f_to); ?>"
                   class="text-xs px-2 py-1.5 border border-gray-300 rounded">
        </div>
        <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded hover:bg-blue-700 transition-colors cursor-pointer">
            <i class="fas fa-filter mr-1"></i>Apply
        </button>
        <a href="payment_watch.php?show=<?php echo htmlspecialchars($show); ?>"
           class="px-3 py-1.5 bg-gray-100 text-gray-600 text-xs rounded hover:bg-gray-200 transition-colors cursor-pointer">
            <i class="fas fa-times mr-1"></i>Reset
        </a>
        <span class="ml-auto self-end text-xs text-gray-400 pb-1">
            <strong><?php echo number_format($total_rows); ?></strong> record<?php echo $total_rows !== 1 ? 's' : ''; ?>
        </span>
    </div>
</form>

<script>
// Date-range templates fill the From/To fields
document.getElementById('pwRange').addEventListener('change', function () {
    const f = document.getElementById('pwFrom'), t = document.getElementById('pwTo');
    const d = new Date(), pad = n => String(n).padStart(2, '0');
    const iso = x => x.getFullYear() + '-' + pad(x.getMonth() + 1) + '-' + pad(x.getDate());
    if (this.value === 'today')       { f.value = iso(d); t.value = iso(d); }
    else if (this.value === '7d')     { const s = new Date(d); s.setDate(d.getDate() - 6); f.value = iso(s); t.value = iso(d); }
    else if (this.value === 'month')  { f.value = iso(new Date(d.getFullYear(), d.getMonth(), 1)); t.value = iso(d); }
    else if (this.value === 'last_month') {
        f.value = iso(new Date(d.getFullYear(), d.getMonth() - 1, 1));
        t.value = iso(new Date(d.getFullYear(), d.getMonth(), 0));
    }
    else { f.value = ''; t.value = ''; }
});
</script>

<?php if (isset($_SESSION['success_flash'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 mb-4 rounded-lg text-sm flex flex-wrap items-center gap-3">
    <span><i class="fas fa-check-circle mr-1"></i> <?php echo htmlspecialchars($_SESSION['success_flash']); unset($_SESSION['success_flash']); ?></span>
    <?php
    // One-shot Undo for a clearance granted just now (accidental clicks)
    $undo = $_SESSION['undo_clearance'] ?? null;
    unset($_SESSION['undo_clearance']);
    if ($undo && $can_revoke):
    ?>
    <form method="POST" class="inline"
          onsubmit="return confirm('Undo the clearance for <?php echo addslashes($undo['order_number']); ?>? The dispatch hold will re-engage.');">
        <input type="hidden" name="action" value="revoke_clearance">
        <input type="hidden" name="order_id" value="<?php echo (int)$undo['order_id']; ?>">
        <input type="hidden" name="revoke_reason" value="Accidental grant — undone immediately">
        <button type="submit"
                class="px-3 py-1 bg-white border-2 border-red-500 text-red-600 rounded-lg text-xs font-bold hover:bg-red-50 transition-colors cursor-pointer">
            <i class="fas fa-undo mr-1"></i>Undo — Re-lock Dispatch
        </button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php if (isset($_SESSION['error_flash'])): ?>
<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 mb-4 rounded-lg text-sm">
    <strong>Error:</strong> <?php echo htmlspecialchars($_SESSION['error_flash']); unset($_SESSION['error_flash']); ?>
</div>
<?php endif; ?>

<?php if (empty($held_orders)): ?>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-12 text-center">
    <i class="fas fa-clipboard-check text-5xl text-gray-300 mb-4"></i>
    <h3 class="text-base font-semibold text-gray-600 mb-1">Nothing to Watch</h3>
    <p class="text-sm text-gray-400">No orders are currently held for payment clearance.</p>
</div>
<?php else: ?>

<div class="space-y-4">
<?php foreach ($held_orders as $h):
    $gate      = $gate_by_order[$h->order_id];
    $is_cleared = $gate['dispatch'] === 'cleared';
    $cond_met   = $gate['dispatch'] === 'condition_met';
    $threshold  = $gate['threshold'];
    $current    = $gate['current'];
    $shortfall  = $gate['shortfall'];

    // Progress percent for the bar
    $pct = null;
    if ($threshold !== null && $current !== null && $threshold > 0) {
        if (in_array($h->condition_type, ['outstanding_below', 'outstanding_after_ship'])) {
            // Bar shows how close outstanding is to the target (full = met)
            $pct = $current <= $threshold ? 100 : max(2, min(98, ($threshold / $current) * 100));
        } else {
            $pct = min(100, ($current / $threshold) * 100);
        }
    } elseif ($threshold !== null && (float)$threshold == 0.0 && $current !== null) {
        // Threshold 0 (full settlement): bar = share already paid off
        $pct = $current <= 0 ? 100 : max(2, min(98, 100 - min(100, $current)));
    }
?>
    <div class="bg-white rounded-xl border shadow-sm overflow-hidden
                <?php echo $is_cleared ? 'border-green-300' : ($cond_met ? 'border-blue-400' : 'border-orange-300'); ?>">
        <!-- Header row -->
        <div class="px-5 py-3 flex flex-wrap items-center justify-between gap-2
                    <?php echo $is_cleared ? 'bg-green-50' : ($cond_met ? 'bg-blue-50' : 'bg-orange-50'); ?>">
            <div class="flex items-center gap-3">
                <a href="credit_order_view.php?id=<?php echo $h->order_id; ?>"
                   class="font-mono font-bold text-blue-700 hover:underline text-sm"><?php echo htmlspecialchars($h->order_number); ?></a>
                <span class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($h->customer_name); ?></span>
                <span class="text-xs text-gray-500"><?php echo htmlspecialchars($h->customer_phone ?? ''); ?></span>
                <?php if ($h->branch_name): ?>
                <span class="text-xs text-gray-400"><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($h->branch_name); ?></span>
                <?php endif; ?>
            </div>
            <div>
                <?php if ($is_cleared): ?>
                <span class="inline-flex items-center gap-1 px-3 py-1 bg-green-600 text-white rounded-full text-xs font-bold">
                    <i class="fas fa-unlock"></i> CLEARED
                </span>
                <?php elseif ($cond_met): ?>
                <span class="inline-flex items-center gap-1 px-3 py-1 bg-blue-600 text-white rounded-full text-xs font-bold animate-pulse">
                    <i class="fas fa-bell"></i> CONDITION MET — READY TO CLEAR
                </span>
                <?php else: ?>
                <span class="inline-flex items-center gap-1 px-3 py-1 bg-orange-500 text-white rounded-full text-xs font-bold">
                    <i class="fas fa-lock"></i> HELD
                </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="px-5 py-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
            <!-- Condition info -->
            <div class="space-y-1.5 text-sm">
                <p class="text-xs text-gray-400 uppercase tracking-wide font-semibold">Condition</p>
                <p class="text-gray-800 font-medium"><?php echo $cond_labels[$h->condition_type] ?? $h->condition_type; ?></p>
                <?php if ($threshold !== null): ?>
                <p class="text-gray-600">Threshold: <strong>৳<?php echo number_format($threshold, 0); ?></strong></p>
                <?php endif; ?>
                <?php if ((int)$h->auto_release === 1 && !$is_cleared): ?>
                <p class="text-xs text-green-600"><i class="fas fa-bolt mr-1"></i>Auto-release enabled</p>
                <?php endif; ?>
                <p class="text-xs text-gray-400 mt-2">
                    Held by <?php echo htmlspecialchars($h->approved_by_name ?? 'admin'); ?>
                    on <?php echo date('d M Y', strtotime($h->approved_at)); ?>
                </p>
                <?php if (!empty($h->accounts_note)): ?>
                <p class="text-xs bg-yellow-50 border border-yellow-200 text-yellow-800 rounded px-2 py-1.5 mt-1">
                    <i class="fas fa-sticky-note mr-1"></i><?php echo htmlspecialchars($h->accounts_note); ?>
                </p>
                <?php endif; ?>
            </div>

            <!-- Live numbers -->
            <div class="space-y-1.5 text-sm">
                <p class="text-xs text-gray-400 uppercase tracking-wide font-semibold">Live Position</p>
                <?php if ($current !== null && $threshold !== null): ?>
                    <?php if ($h->condition_type === 'outstanding_below'): ?>
                    <p class="text-gray-600">Outstanding now: <strong class="<?php echo $current <= $threshold ? 'text-green-700' : 'text-red-600'; ?>">৳<?php echo number_format($current, 0); ?></strong></p>
                    <?php elseif ($h->condition_type === 'outstanding_after_ship'): ?>
                    <p class="text-gray-600">Outstanding incl. this invoice: <strong class="<?php echo $current <= $threshold ? 'text-green-700' : 'text-red-600'; ?>">৳<?php echo number_format($current, 0); ?></strong></p>
                    <?php else: ?>
                    <p class="text-gray-600">Received since approval: <strong class="text-green-700">৳<?php echo number_format($current, 0); ?></strong></p>
                    <?php endif; ?>
                    <?php if ($pct !== null): ?>
                    <div class="w-full bg-gray-100 rounded-full h-2.5 mt-2">
                        <div class="h-2.5 rounded-full <?php echo ($shortfall !== null && $shortfall <= 0) || $cond_met || $is_cleared ? 'bg-green-500' : 'bg-orange-400'; ?>"
                             style="width: <?php echo round($pct); ?>%"></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($shortfall !== null && $shortfall > 0): ?>
                    <p class="text-xs text-red-600 font-semibold mt-1">৳<?php echo number_format($shortfall, 0); ?> to go</p>
                    <?php elseif (!$is_cleared): ?>
                    <p class="text-xs text-green-600 font-semibold mt-1"><i class="fas fa-check mr-1"></i>Condition satisfied</p>
                    <?php endif; ?>
                <?php else: ?>
                <p class="text-gray-500 italic">Manual clearance — use your judgment based on the customer's payments.</p>
                <?php endif; ?>

                <?php
                // Full picture: this invoice posts to the ledger AT DISPATCH, so the
                // outstanding shown above does not yet include it. Spell it out.
                $pw_out = getCustomerOutstanding((int)$h->customer_id);
                $pw_inv = (float)$h->total_amount;
                $pw_not_shipped = !in_array($h->order_status, ['shipped', 'delivered']);
                ?>
                <div class="mt-2 pt-2 border-t border-gray-100 text-xs space-y-0.5">
                    <div class="flex justify-between text-gray-600">
                        <span>Previous due (outstanding now)</span><strong>৳<?php echo number_format($pw_out, 0); ?></strong>
                    </div>
                    <?php if ($pw_not_shipped): ?>
                    <div class="flex justify-between text-gray-600">
                        <span>This invoice (posts at dispatch)</span><strong>+ ৳<?php echo number_format($pw_inv, 0); ?></strong>
                    </div>
                    <div class="flex justify-between text-gray-800 border-t border-gray-100 pt-0.5">
                        <span class="font-semibold">Will owe after this shipment</span>
                        <strong class="text-red-600">৳<?php echo number_format($pw_out + $pw_inv, 0); ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if (!$is_cleared && $shortfall !== null && $shortfall > 0): ?>
                    <div class="flex justify-between text-amber-700 font-semibold">
                        <span>Deposit needed for clearance</span><strong>৳<?php echo number_format($shortfall, 0); ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
                <p class="text-[11px] text-gray-400 mt-1">Order status: <?php echo ucwords(str_replace('_', ' ', $h->order_status)); ?></p>
            </div>

            <!-- Actions -->
            <div class="flex flex-col justify-center gap-2">
                <?php
                // Feature #12: Override (grant clearance) and Collect Payment live side by
                // side on this screen. Collect preloads this order into the payment page.
                ?>
                <?php if ($can_collect): ?>
                <a href="customer_payment.php?order_id=<?php echo (int)$h->order_id; ?>"
                   class="w-full py-2 rounded-lg text-sm font-bold text-center bg-primary-600 text-white hover:bg-primary-700 transition-colors">
                    <i class="fas fa-money-bill-wave mr-1"></i>Collect Payment
                </a>
                <?php if (!$is_cleared && $can_grant): ?>
                <p class="text-[10px] text-gray-400 text-center -mt-1">— or override the hold below —</p>
                <?php endif; ?>
                <?php endif; ?>
                <?php
                // Non-admins may grant early when (a) they have a delegated limit
                // AND (b) this order's value is within it. Otherwise amount-based
                // conditions stay locked until genuinely met (manual is always open).
                $delegated_covers = $my_limit !== null && (float)$h->total_amount <= $my_limit;
                $grant_locked = !$is_admin && $h->condition_type !== 'manual' && !$cond_met && !$delegated_covers;
                $early_release = !$cond_met && ($is_admin || $delegated_covers);
                ?>
                <?php if (!$is_cleared && $can_grant && $grant_locked): ?>
                <div class="text-xs text-gray-600 bg-gray-50 border border-gray-200 rounded-lg p-3">
                    <i class="fas fa-lock text-gray-400 mr-1"></i>
                    <strong>Locked until the payment condition is met.</strong>
                    Clearance unlocks automatically when the target is reached.
                    <?php if ($my_limit !== null): ?>
                    Early release needs your delegated limit (৳<?php echo number_format($my_limit, 0); ?>)
                    to cover this order's ৳<?php echo number_format($h->total_amount, 0); ?> value — it doesn't here.
                    <?php else: ?>
                    Early release requires an admin, or a delegated approval limit (set in User Privileges).
                    <?php endif; ?>
                </div>
                <?php elseif (!$is_cleared && $can_grant): ?>
                <form method="POST" class="space-y-2"
                      onsubmit="return confirm('Grant dispatch clearance for <?php echo addslashes($h->order_number); ?>?');">
                    <input type="hidden" name="action" value="grant_clearance">
                    <input type="hidden" name="order_id" value="<?php echo $h->order_id; ?>">
                    <input type="text" name="clearance_note" maxlength="500"
                           class="w-full px-3 py-2 border rounded-lg text-xs"
                           placeholder="Clearance note (optional, e.g. payment ref)...">
                    <button type="submit"
                            class="w-full py-2 rounded-lg text-sm font-bold transition-colors cursor-pointer
                                   <?php echo $cond_met ? 'bg-green-600 text-white hover:bg-green-700' : 'bg-white border-2 border-green-600 text-green-700 hover:bg-green-50'; ?>">
                        <i class="fas fa-unlock mr-1"></i>Grant Dispatch Clearance
                        <?php if ($early_release && $is_admin): ?><span class="font-normal text-xs">(admin early release)</span>
                        <?php elseif ($early_release): ?><span class="font-normal text-xs">(delegated early release)</span><?php endif; ?>
                    </button>
                </form>
                <?php elseif ($is_cleared): ?>
                <div class="text-xs text-gray-500 bg-green-50 border border-green-200 rounded-lg p-3">
                    <i class="fas fa-check-circle text-green-500 mr-1"></i>
                    Cleared <?php echo $h->cleared_at ? 'on ' . date('d M Y, g:i A', strtotime($h->cleared_at)) : ''; ?>
                    by <strong><?php echo htmlspecialchars($h->cleared_by_name ?? 'System (auto-release)'); ?></strong>
                    <?php if (!empty($h->clearance_note)): ?>
                    <div class="mt-1 italic">"<?php echo htmlspecialchars($h->clearance_note); ?>"</div>
                    <?php endif; ?>
                </div>
                <?php if ($can_revoke && !in_array($h->order_status, ['shipped', 'delivered'])): ?>
                <form method="POST" class="space-y-2"
                      onsubmit="return confirm('Revoke clearance for <?php echo addslashes($h->order_number); ?>? Dispatch will be locked again.');">
                    <input type="hidden" name="action" value="revoke_clearance">
                    <input type="hidden" name="order_id" value="<?php echo $h->order_id; ?>">
                    <input type="text" name="revoke_reason" required maxlength="500"
                           class="w-full px-3 py-2 border rounded-lg text-xs"
                           placeholder="Reason (required, e.g. cheque bounced)...">
                    <button type="submit"
                            class="w-full py-2 bg-white border-2 border-red-500 text-red-600 rounded-lg text-sm font-bold hover:bg-red-50 transition-colors cursor-pointer">
                        <i class="fas fa-ban mr-1"></i>Revoke Clearance
                    </button>
                </form>
                <?php endif; ?>
                <?php else: ?>
                <p class="text-xs text-gray-400 italic text-center">You don't have clearance permission.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<!-- ── Pagination ─────────────────────────────────────────────────────────── -->
<?php if ($total_pages > 1): ?>
<div class="flex items-center justify-between mt-5 bg-white rounded-xl border border-gray-200 shadow-sm px-4 py-3">
    <?php
    $pg_qs = array_merge($qs_base, ['show' => $show]);
    $prev  = '?' . http_build_query(array_merge($pg_qs, ['page' => $page - 1]));
    $next  = '?' . http_build_query(array_merge($pg_qs, ['page' => $page + 1]));
    ?>
    <a href="<?php echo $page > 1 ? $prev : '#'; ?>"
       class="px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors
              <?php echo $page > 1 ? 'border-gray-300 text-gray-700 hover:bg-gray-50 cursor-pointer' : 'border-gray-200 text-gray-300 pointer-events-none'; ?>">
        <i class="fas fa-chevron-left mr-1"></i>Previous
    </a>
    <span class="text-xs text-gray-500">
        Page <strong><?php echo $page; ?></strong> of <strong><?php echo $total_pages; ?></strong>
        &nbsp;·&nbsp; <?php echo number_format($total_rows); ?> records
    </span>
    <a href="<?php echo $page < $total_pages ? $next : '#'; ?>"
       class="px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors
              <?php echo $page < $total_pages ? 'border-gray-300 text-gray-700 hover:bg-gray-50 cursor-pointer' : 'border-gray-200 text-gray-300 pointer-events-none'; ?>">
        Next<i class="fas fa-chevron-right ml-1"></i>
    </a>
</div>
<?php endif; ?>
<?php endif; ?>

</div>

<?php require_once '../templates/footer.php'; ?>
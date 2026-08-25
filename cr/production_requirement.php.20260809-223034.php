<?php
/**
 * Today's Production Requirement — per-product-variant view of what still
 * needs to be produced to cover orders required (delivery-scheduled) on the
 * selected date, with periodic "already in hand" / "produced so far" tracking
 * so the remaining-to-produce figure updates as the shift progresses.
 *
 * Built 9 Aug 2026 under the Production module, alongside credit_production.php
 * (which tracks individual orders through the pipeline) — this page instead
 * aggregates by PRODUCT, since a production floor plans by "how many bags of
 * X" rather than by order. Independent of credit_orders.status — it never
 * changes an order's workflow state, purely a planning/tracking tool on top.
 */
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'Accounts', 'admin', 'production manager-srg', 'production manager-demra'];
restrict_access($allowed_roles, 'production', 'production_requirement');

global $db;
$currentUser = getCurrentUser();
$user_id     = $currentUser['id']   ?? null;
$user_role   = $currentUser['role'] ?? '';
$pageTitle   = "Today's Production Requirement";

$is_admin   = in_array($user_role, ['Superadmin', 'admin']);
$can_update = $is_admin || userCanPageAction('production', 'production_requirement', 'can_update');

// Tables must exist before any POST transaction (DDL implicit-commits)
ensureProductionDailyStockTable();
ensureProductionDailyLogTable();

// Fallback trigger for the hourly Telegram shortfall check (3am-11am) in case
// the cPanel cron job is missing/hasn't fired yet — rate-limited to once per
// hour-slot inside sendProductionShortfallAlert() itself, so this is cheap.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try { sendProductionShortfallAlert(false); } catch (Throwable $e) { error_log('production_requirement fallback alert: ' . $e->getMessage()); }
}

// ── Branch detection (same pattern as credit_production.php) ─────────────────
$user_branch = null;
if (!$is_admin) {
    $emp = $db->query("SELECT branch_id FROM employees WHERE user_id = ?", [$user_id])->first();
    if ($emp && $emp->branch_id) {
        $user_branch = $emp->branch_id;
    } else {
        $ur = $db->query("SELECT branch_id FROM users WHERE id = ?", [$user_id])->first();
        if ($ur && isset($ur->branch_id)) $user_branch = $ur->branch_id;
    }
}

$all_branches       = [];
$filter_branch_id   = 0;
$filter_branch_name = '';
if ($is_admin) {
    $all_branches     = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name")->results();
    $filter_branch_id = (int)($_GET['branch_id'] ?? 0);
    foreach ($all_branches as $br) {
        if ((int)$br->id === $filter_branch_id) { $filter_branch_name = $br->name; break; }
    }
}

// The single branch this view/edit is scoped to. Non-admin: always their own
// branch. Admin: only once they've picked one — "All Branches" is a read-only
// aggregate, since a stock update has to land on one specific production floor.
$scope_branch_id   = $is_admin ? $filter_branch_id : (int)($user_branch ?? 0);
$editing_enabled   = $can_update && $scope_branch_id > 0;

// ── Date ───────────────────────────────────────────────────────────────────
$prod_date = trim($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prod_date)) $prod_date = date('Y-m-d');

$redirect_qs = 'date=' . urlencode($prod_date) . ($is_admin && $filter_branch_id > 0 ? '&branch_id=' . $filter_branch_id : '');

// ── POST: update a row's in-hand / produced quantities ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_row') {
    if (!$editing_enabled) {
        $_SESSION['error_flash'] = 'You do not have permission to update production quantities, or no specific branch is selected.';
        header('Location: production_requirement.php?' . $redirect_qs);
        exit;
    }

    $variant_id   = (int)($_POST['variant_id'] ?? 0);
    $post_date    = trim($_POST['production_date'] ?? $prod_date);
    $in_hand_qty  = round((float)($_POST['in_hand_qty'] ?? 0), 2);
    $add_produced = round((float)($_POST['add_produced_qty'] ?? 0), 2);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $post_date)) $post_date = $prod_date;

    if ($variant_id <= 0) {
        $_SESSION['error_flash'] = 'Invalid product row.';
    } elseif ($in_hand_qty < 0 || $add_produced < 0) {
        $_SESSION['error_flash'] = 'Quantities cannot be negative.';
    } else {
        try {
            $db->getPdo()->beginTransaction();

            $row = $db->query(
                "SELECT id, produced_qty FROM production_daily_stock
                 WHERE production_date = ? AND branch_id = ? AND variant_id = ?",
                [$post_date, $scope_branch_id, $variant_id]
            )->first();

            if ($row) {
                $new_produced = round((float)$row->produced_qty + $add_produced, 2);
                if (!$db->update('production_daily_stock',
                    ['in_hand_qty' => $in_hand_qty, 'produced_qty' => $new_produced, 'updated_by_user_id' => $user_id],
                    ['id' => $row->id]
                )) throw new Exception('Failed to update the production stock record.');
            } else {
                if (!$db->insert('production_daily_stock', [
                    'production_date' => $post_date, 'branch_id' => $scope_branch_id, 'variant_id' => $variant_id,
                    'in_hand_qty' => $in_hand_qty, 'produced_qty' => $add_produced, 'updated_by_user_id' => $user_id,
                ])) throw new Exception('Failed to create the production stock record.');
            }

            if (!$db->insert('production_daily_log', [
                'production_date' => $post_date, 'branch_id' => $scope_branch_id, 'variant_id' => $variant_id,
                'event_type' => 'in_hand_set', 'qty' => $in_hand_qty, 'user_id' => $user_id,
            ])) throw new Exception('Failed to log the on-hand update.');

            if ($add_produced > 0) {
                if (!$db->insert('production_daily_log', [
                    'production_date' => $post_date, 'branch_id' => $scope_branch_id, 'variant_id' => $variant_id,
                    'event_type' => 'produced_added', 'qty' => $add_produced, 'user_id' => $user_id,
                ])) throw new Exception('Failed to log the produced update.');
            }

            $db->getPdo()->commit();
            $_SESSION['success_flash'] = 'Production quantities updated.';
        } catch (Exception $e) {
            $db->getPdo()->rollBack();
            $_SESSION['error_flash'] = $e->getMessage();
        }
    }

    header('Location: production_requirement.php?' . $redirect_qs . '#row-' . $variant_id);
    exit;
}

// ── Required-today aggregation, grouped by variant ────────────────────────────
$params = [$prod_date];
$branch_sql = '';
if ($scope_branch_id > 0) { $branch_sql = 'AND co.assigned_branch_id = ?'; $params[] = $scope_branch_id; }

$required_rows = $db->query(
    "SELECT pv.id AS variant_id, p.base_name AS product_name, pv.grade, pv.weight_variant, pv.unit_of_measure,
            SUM(coi.quantity) AS required_qty, COUNT(DISTINCT co.id) AS order_count
     FROM credit_order_items coi
     JOIN credit_orders co ON coi.order_id = co.id
     JOIN product_variants pv ON coi.variant_id = pv.id
     JOIN products p ON coi.product_id = p.id
     WHERE co.required_date = ?
       AND co.status IN ('approved','in_production')
       AND co.assigned_branch_id IS NOT NULL
       $branch_sql
     GROUP BY pv.id
     ORDER BY p.base_name, pv.grade, pv.weight_variant",
    $params
)->results();

// ── Orders under production, due today ────────────────────────────────────────
$orders_today = $db->query(
    "SELECT co.id, co.order_number, co.status, co.required_date,
            c.name AS customer_name, b.name AS branch_name,
            ps.status AS prod_status, ps.production_started_at
     FROM credit_orders co
     JOIN customers c ON co.customer_id = c.id
     LEFT JOIN branches b ON co.assigned_branch_id = b.id
     LEFT JOIN production_schedule ps ON co.id = ps.order_id
     WHERE co.required_date = ?
       AND co.status IN ('approved','in_production')
       AND co.assigned_branch_id IS NOT NULL
       $branch_sql
     ORDER BY co.status, co.id",
    $params
)->results();

// ── Existing in-hand / produced state for this date (+ branch scope) ─────────
$stock_params = [$prod_date];
$stock_branch_sql = '';
if ($scope_branch_id > 0) { $stock_branch_sql = 'AND branch_id = ?'; $stock_params[] = $scope_branch_id; }

$stock_rows = $db->query(
    "SELECT variant_id, SUM(in_hand_qty) AS in_hand_qty, SUM(produced_qty) AS produced_qty, MAX(updated_at) AS updated_at
     FROM production_daily_stock
     WHERE production_date = ? $stock_branch_sql
     GROUP BY variant_id",
    $stock_params
)->results();

$stock_by_variant = [];
foreach ($stock_rows as $s) { $stock_by_variant[(int)$s->variant_id] = $s; }

// ── Merge required + stock into one row set ───────────────────────────────────
$rows = [];
foreach ($required_rows as $r) {
    $vid   = (int)$r->variant_id;
    $stock = $stock_by_variant[$vid] ?? null;
    $rows[$vid] = [
        'variant_id'     => $vid,
        'product_name'   => $r->product_name,
        'grade'          => $r->grade,
        'weight_variant' => $r->weight_variant,
        'unit_of_measure'=> $r->unit_of_measure,
        'required_qty'   => (float)$r->required_qty,
        'order_count'    => (int)$r->order_count,
        'in_hand_qty'    => $stock ? (float)$stock->in_hand_qty : 0.0,
        'produced_qty'   => $stock ? (float)$stock->produced_qty : 0.0,
        'updated_at'     => $stock->updated_at ?? null,
    ];
    unset($stock_by_variant[$vid]);
}

// Variants with tracked stock today but no (or no longer any) required order —
// still show them rather than silently hiding entered data.
if (!empty($stock_by_variant)) {
    $leftover_ids = array_keys($stock_by_variant);
    $placeholders = implode(',', array_fill(0, count($leftover_ids), '?'));
    $extra = $db->query(
        "SELECT pv.id AS variant_id, p.base_name AS product_name, pv.grade, pv.weight_variant, pv.unit_of_measure
         FROM product_variants pv JOIN products p ON pv.product_id = p.id
         WHERE pv.id IN ($placeholders)",
        $leftover_ids
    )->results();
    foreach ($extra as $e) {
        $vid   = (int)$e->variant_id;
        $stock = $stock_by_variant[$vid];
        $rows[$vid] = [
            'variant_id'     => $vid,
            'product_name'   => $e->product_name,
            'grade'          => $e->grade,
            'weight_variant' => $e->weight_variant,
            'unit_of_measure'=> $e->unit_of_measure,
            'required_qty'   => 0.0,
            'order_count'    => 0,
            'in_hand_qty'    => (float)$stock->in_hand_qty,
            'produced_qty'   => (float)$stock->produced_qty,
            'updated_at'     => $stock->updated_at,
        ];
    }
}

usort($rows, fn($a, $b) => [$a['product_name'], (string)$a['grade'], (string)$a['weight_variant']]
                        <=> [$b['product_name'], (string)$b['grade'], (string)$b['weight_variant']]);

// ── Per-row computed columns (bags + weight where the size is a real number) ──
$totals = ['required_qty' => 0, 'still_needed_qty' => 0, 'fulfilled_count' => 0, 'pending_count' => 0];
foreach ($rows as &$row) {
    $kg_per_unit = ($row['unit_of_measure'] === 'kg' && is_numeric($row['weight_variant']))
        ? (float)$row['weight_variant'] : null;
    $row['kg_per_unit']   = $kg_per_unit;
    $row['available_qty'] = round($row['in_hand_qty'] + $row['produced_qty'], 2);
    $row['still_needed_qty'] = max(round($row['required_qty'] - $row['available_qty'], 2), 0);
    $row['fulfilled'] = $row['required_qty'] > 0 && $row['still_needed_qty'] <= 0;

    if ($kg_per_unit !== null) {
        $row['required_weight']     = $row['required_qty']     * $kg_per_unit;
        $row['in_hand_weight']      = $row['in_hand_qty']       * $kg_per_unit;
        $row['produced_weight']     = $row['produced_qty']      * $kg_per_unit;
        $row['still_needed_weight'] = $row['still_needed_qty']  * $kg_per_unit;
    } else {
        $row['required_weight'] = $row['in_hand_weight'] = $row['produced_weight'] = $row['still_needed_weight'] = null;
    }

    $totals['required_qty']     += $row['required_qty'];
    $totals['still_needed_qty'] += $row['still_needed_qty'];
    if ($row['required_qty'] > 0) {
        if ($row['fulfilled']) $totals['fulfilled_count']++; else $totals['pending_count']++;
    }
}
unset($row);

// ── Recent activity log (last 30, this date + branch scope) ──────────────────
$log_params = [$prod_date];
$log_branch_sql = '';
if ($scope_branch_id > 0) { $log_branch_sql = 'AND pdl.branch_id = ?'; $log_params[] = $scope_branch_id; }
$recent_log = $db->query(
    "SELECT pdl.event_type, pdl.qty, pdl.created_at, p.base_name AS product_name, pv.grade, pv.weight_variant,
            u.display_name AS user_name, b.name AS branch_name
     FROM production_daily_log pdl
     JOIN product_variants pv ON pdl.variant_id = pv.id
     JOIN products p ON pv.product_id = p.id
     LEFT JOIN users u ON pdl.user_id = u.id
     LEFT JOIN branches b ON pdl.branch_id = b.id
     WHERE pdl.production_date = ? $log_branch_sql
     ORDER BY pdl.id DESC
     LIMIT 30",
    $log_params
)->results();

require_once '../templates/header.php';
?>
<div class="max-w-screen-xl mx-auto px-4 sm:px-6 py-6">

    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-industry text-amber-600 mr-2"></i><?php echo htmlspecialchars($pageTitle); ?></h1>
            <p class="text-gray-600 mt-1 text-sm">What needs producing to cover orders required (delivery-scheduled) on this date, and how much is already covered by stock in hand or produced so far.</p>
        </div>
    </div>

    <form method="GET" class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-4 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Production Date</label>
            <input type="date" name="date" value="<?php echo htmlspecialchars($prod_date); ?>" class="px-3 py-1.5 border rounded-lg text-sm">
        </div>
        <?php if ($is_admin): ?>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Branch</label>
            <select name="branch_id" class="px-3 py-1.5 border rounded-lg text-sm min-w-[10rem]">
                <option value="0">All Branches (view only)</option>
                <?php foreach ($all_branches as $br): ?>
                <option value="<?php echo (int)$br->id; ?>" <?php echo $filter_branch_id === (int)$br->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($br->name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="px-4 py-1.5 bg-amber-600 text-white text-sm font-semibold rounded-lg hover:bg-amber-700"><i class="fas fa-filter mr-1"></i>View</button>
        <?php if ($prod_date === date('Y-m-d')): ?>
        <span class="text-xs text-gray-400 pb-2">Showing today.</span>
        <?php else: ?>
        <a href="production_requirement.php<?php echo $is_admin && $filter_branch_id > 0 ? '?branch_id=' . $filter_branch_id : ''; ?>" class="text-xs text-amber-700 pb-2 hover:underline">Jump to today</a>
        <?php endif; ?>
    </form>

    <?php if (!empty($_SESSION['success_flash'])): ?>
    <div class="mb-4 px-4 py-2.5 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm"><i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars($_SESSION['success_flash']); unset($_SESSION['success_flash']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error_flash'])): ?>
    <div class="mb-4 px-4 py-2.5 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"><i class="fas fa-triangle-exclamation mr-1"></i><?php echo htmlspecialchars($_SESSION['error_flash']); unset($_SESSION['error_flash']); ?></div>
    <?php endif; ?>

    <?php if ($is_admin && $scope_branch_id === 0): ?>
    <div class="mb-4 px-4 py-2.5 bg-blue-50 border border-blue-200 text-blue-700 rounded-lg text-sm">
        <i class="fas fa-circle-info mr-1"></i>Viewing all branches combined — figures are read-only. Select a specific branch above to update on-hand / produced quantities.
    </div>
    <?php elseif (!$can_update): ?>
    <div class="mb-4 px-4 py-2.5 bg-gray-50 border border-gray-200 text-gray-600 rounded-lg text-sm">
        <i class="fas fa-lock mr-1"></i>You have view-only access to this page.
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
            <div class="text-2xl font-bold text-gray-800"><?php echo count($orders_today); ?></div>
            <div class="text-xs text-gray-500">Orders due today</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
            <div class="text-2xl font-bold text-gray-800"><?php echo number_format($totals['required_qty'], 0); ?></div>
            <div class="text-xs text-gray-500">Bags required (all products)</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border <?php echo $totals['still_needed_qty'] > 0 ? 'border-red-200' : 'border-green-200'; ?> p-4">
            <div class="text-2xl font-bold <?php echo $totals['still_needed_qty'] > 0 ? 'text-red-600' : 'text-green-600'; ?>"><?php echo number_format($totals['still_needed_qty'], 0); ?></div>
            <div class="text-xs text-gray-500">Bags still to produce</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
            <div class="text-2xl font-bold text-gray-800"><?php echo $totals['fulfilled_count']; ?> / <?php echo $totals['fulfilled_count'] + $totals['pending_count']; ?></div>
            <div class="text-xs text-gray-500">Products fully covered</div>
        </div>
    </div>

    <?php if (!empty($orders_today)): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <h2 class="font-semibold text-gray-800 text-sm"><i class="fas fa-list-check text-amber-600 mr-1"></i>Orders Under Production — Due <?php echo date('d M Y', strtotime($prod_date)); ?></h2>
            <span class="text-xs text-gray-400"><?php echo count($orders_today); ?> order(s)</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="text-left px-4 py-2">Order #</th>
                        <th class="text-left px-4 py-2">Customer</th>
                        <?php if ($is_admin && $scope_branch_id === 0): ?><th class="text-left px-4 py-2">Branch</th><?php endif; ?>
                        <th class="text-left px-4 py-2">Order Status</th>
                        <th class="text-left px-4 py-2">Production</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($orders_today as $o): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-medium"><a href="credit_order_view.php?id=<?php echo (int)$o->id; ?>" class="text-blue-600 hover:underline"><?php echo htmlspecialchars($o->order_number); ?></a></td>
                        <td class="px-4 py-2 text-gray-600"><?php echo htmlspecialchars($o->customer_name); ?></td>
                        <?php if ($is_admin && $scope_branch_id === 0): ?><td class="px-4 py-2 text-gray-500"><?php echo htmlspecialchars($o->branch_name ?? '—'); ?></td><?php endif; ?>
                        <td class="px-4 py-2">
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold <?php echo $o->status === 'in_production' ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700'; ?>">
                                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $o->status))); ?>
                            </span>
                        </td>
                        <td class="px-4 py-2 text-gray-500">
                            <?php if ($o->production_started_at): ?>Started <?php echo date('d M, h:i A', strtotime($o->production_started_at)); ?>
                            <?php else: ?><span class="text-gray-400">Not started</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <h2 class="font-semibold text-gray-800 text-sm"><i class="fas fa-boxes-stacked text-amber-600 mr-1"></i>Production Requirement by Product</h2>
            <span class="text-xs text-gray-400"><?php echo count($rows); ?> product row(s)</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="text-left px-3 py-2">Product</th>
                        <th class="text-right px-3 py-2">Required</th>
                        <th class="text-right px-3 py-2">In Hand</th>
                        <th class="text-right px-3 py-2">Produced</th>
                        <th class="text-right px-3 py-2">Still Needs</th>
                        <?php if ($editing_enabled): ?><th class="text-center px-3 py-2">Update</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="6" class="text-center py-10 text-gray-400">No orders require production on this date<?php echo $scope_branch_id > 0 ? ' for this branch' : ''; ?>.</td></tr>
                    <?php else: foreach ($rows as $row): ?>
                    <tr id="row-<?php echo $row['variant_id']; ?>" class="<?php echo $row['fulfilled'] ? 'bg-green-50/40' : ''; ?> align-top">
                        <td class="px-3 py-3">
                            <div class="font-medium text-gray-800"><?php echo htmlspecialchars($row['product_name']); ?></div>
                            <div class="text-xs text-gray-400">
                                <?php echo htmlspecialchars(trim(($row['grade'] ? $row['grade'] . ' · ' : '') . $row['weight_variant'] . ' ' . $row['unit_of_measure'])); ?>
                                <?php if ($row['order_count'] > 0): ?> · <?php echo $row['order_count']; ?> order(s)<?php endif; ?>
                            </div>
                        </td>
                        <td class="px-3 py-3 text-right">
                            <div class="font-medium text-gray-800"><?php echo number_format($row['required_qty'], 2); ?> bag</div>
                            <?php if ($row['required_weight'] !== null): ?><div class="text-xs text-gray-400"><?php echo number_format($row['required_weight'], 1); ?> kg</div><?php endif; ?>
                        </td>
                        <td class="px-3 py-3 text-right">
                            <div class="text-gray-700"><?php echo number_format($row['in_hand_qty'], 2); ?> bag</div>
                            <?php if ($row['in_hand_weight'] !== null): ?><div class="text-xs text-gray-400"><?php echo number_format($row['in_hand_weight'], 1); ?> kg</div><?php endif; ?>
                        </td>
                        <td class="px-3 py-3 text-right">
                            <div class="text-gray-700"><?php echo number_format($row['produced_qty'], 2); ?> bag</div>
                            <?php if ($row['produced_weight'] !== null): ?><div class="text-xs text-gray-400"><?php echo number_format($row['produced_weight'], 1); ?> kg</div><?php endif; ?>
                            <?php if ($row['updated_at']): ?><div class="text-xs text-gray-300 mt-0.5">upd. <?php echo date('d M h:i A', strtotime($row['updated_at'])); ?></div><?php endif; ?>
                        </td>
                        <td class="px-3 py-3 text-right">
                            <?php if ($row['fulfilled']): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-semibold"><i class="fas fa-check"></i> Covered</span>
                            <?php else: ?>
                                <div class="font-bold text-red-600"><?php echo number_format($row['still_needed_qty'], 2); ?> bag</div>
                                <?php if ($row['still_needed_weight'] !== null): ?><div class="text-xs text-red-400"><?php echo number_format($row['still_needed_weight'], 1); ?> kg</div><?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <?php if ($editing_enabled): ?>
                        <td class="px-3 py-3">
                            <form method="POST" class="flex flex-col gap-1.5 items-stretch min-w-[9rem]">
                                <input type="hidden" name="action" value="update_row">
                                <input type="hidden" name="variant_id" value="<?php echo $row['variant_id']; ?>">
                                <input type="hidden" name="production_date" value="<?php echo htmlspecialchars($prod_date); ?>">
                                <label class="text-[10px] text-gray-400 uppercase tracking-wide -mb-1">In hand (bags)</label>
                                <input type="number" step="0.01" min="0" name="in_hand_qty" value="<?php echo $row['in_hand_qty'] > 0 ? htmlspecialchars((string)$row['in_hand_qty']) : ''; ?>" placeholder="0" class="px-2 py-1 border rounded text-xs w-full">
                                <label class="text-[10px] text-gray-400 uppercase tracking-wide -mb-1">+ Produced now (bags)</label>
                                <input type="number" step="0.01" min="0" name="add_produced_qty" value="" placeholder="0" class="px-2 py-1 border rounded text-xs w-full">
                                <button type="submit" class="mt-1 px-2 py-1 bg-amber-600 text-white text-xs font-semibold rounded hover:bg-amber-700"><i class="fas fa-save mr-1"></i>Save</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($recent_log)): ?>
    <details class="bg-white rounded-xl shadow-sm border border-gray-200">
        <summary class="px-4 py-3 cursor-pointer font-semibold text-gray-800 text-sm select-none"><i class="fas fa-clock-rotate-left text-amber-600 mr-1"></i>Recent Activity (last 30 updates)</summary>
        <div class="overflow-x-auto border-t border-gray-100">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="text-left px-4 py-2">Product</th>
                        <?php if ($is_admin && $scope_branch_id === 0): ?><th class="text-left px-4 py-2">Branch</th><?php endif; ?>
                        <th class="text-left px-4 py-2">Event</th>
                        <th class="text-right px-4 py-2">Qty</th>
                        <th class="text-left px-4 py-2">By</th>
                        <th class="text-left px-4 py-2">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($recent_log as $l): ?>
                    <tr>
                        <td class="px-4 py-2 text-gray-700"><?php echo htmlspecialchars($l->product_name . ' (' . trim(($l->grade ? $l->grade . ' ' : '') . $l->weight_variant) . ')'); ?></td>
                        <?php if ($is_admin && $scope_branch_id === 0): ?><td class="px-4 py-2 text-gray-500"><?php echo htmlspecialchars($l->branch_name ?? '—'); ?></td><?php endif; ?>
                        <td class="px-4 py-2 text-gray-500"><?php echo $l->event_type === 'produced_added' ? 'Produced +added' : 'In-hand set'; ?></td>
                        <td class="px-4 py-2 text-right font-medium"><?php echo number_format((float)$l->qty, 2); ?></td>
                        <td class="px-4 py-2 text-gray-500"><?php echo htmlspecialchars($l->user_name ?? '—'); ?></td>
                        <td class="px-4 py-2 text-gray-400"><?php echo date('d M h:i A', strtotime($l->created_at)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php endif; ?>

</div>
<?php require_once '../templates/footer.php'; ?>

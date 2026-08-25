<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'Accounts', 'admin', 'production manager-srg', 'production manager-demra'];
restrict_access($allowed_roles, 'production', 'delivery_schedule');

global $db;
$currentUser = getCurrentUser();
$user_id     = $currentUser['id']   ?? null;
$user_role   = $currentUser['role'] ?? '';
$user_name   = $currentUser['display_name'] ?? $currentUser['name'] ?? 'Unknown';
$pageTitle   = 'Delivery Schedule';

$is_admin = in_array($user_role, ['Superadmin', 'admin']);

// Self-migrating schema — delivery_priority drives THIS page's drag-drop
// ordering. Deliberately a separate column from production_schedule.priority_order
// (that one is scoped to production-START scheduling, keyed by scheduled_date,
// and is essentially unused today — confirmed live, 245/247 rows still default 0).
// DDL runs outside any transaction (implicit-commit rule).
try { $db->getPdo()->exec("ALTER TABLE `credit_orders` ADD COLUMN `delivery_priority` INT NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

// ── Branch detection ─────────────────────────────────────────────────────────
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

$filter_branch_id   = 0;
$filter_branch_name = '';
$all_branches       = [];
if ($is_admin) {
    $all_branches     = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name")->results();
    $filter_branch_id = (int)($_GET['branch_id'] ?? 0);
    foreach ($all_branches as $br) {
        if ((int)$br->id === $filter_branch_id) { $filter_branch_name = $br->name; break; }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$fo_order      = trim($_GET['fo_order']      ?? '');
$fo_odate_from = trim($_GET['fo_odate_from'] ?? '');
$fo_odate_to   = trim($_GET['fo_odate_to']   ?? '');
$fo_rdate_from = trim($_GET['fo_rdate_from'] ?? '');
$fo_rdate_to   = trim($_GET['fo_rdate_to']   ?? '');
$fo_vehicle    = in_array($_GET['fo_vehicle'] ?? '', ['big_truck', 'mini_truck'], true) ? $_GET['fo_vehicle'] : '';
$fo_customer   = trim($_GET['fo_customer']   ?? '');
$fo_product    = trim($_GET['fo_product']    ?? '');

$active_tab = ($_GET['tab'] ?? 'schedule') === 'overdue' ? 'overdue' : 'schedule';
$today      = date('Y-m-d');

function dsConditions(
    string $boundary_op, string $today, bool $is_admin, $user_branch, int $filter_branch_id,
    string $fo_order, string $fo_odate_from, string $fo_odate_to, string $fo_rdate_from, string $fo_rdate_to,
    string $fo_vehicle, string $fo_customer, string $fo_product
): array {
    $where  = ["co.status IN ('approved','in_production','produced','ready_to_ship')", 'co.assigned_branch_id IS NOT NULL', "co.required_date {$boundary_op} ?"];
    $params = [$today];

    if (!$is_admin && $user_branch) { $where[] = 'co.assigned_branch_id = ?'; $params[] = $user_branch; }
    elseif ($is_admin && $filter_branch_id > 0) { $where[] = 'co.assigned_branch_id = ?'; $params[] = $filter_branch_id; }

    if ($fo_order !== '')      { $where[] = 'co.order_number LIKE ?';   $params[] = '%' . $fo_order . '%'; }
    if ($fo_odate_from !== '') { $where[] = 'co.order_date >= ?';       $params[] = $fo_odate_from; }
    if ($fo_odate_to !== '')   { $where[] = 'co.order_date <= ?';       $params[] = $fo_odate_to; }
    if ($fo_rdate_from !== '') { $where[] = 'co.required_date >= ?';    $params[] = $fo_rdate_from; }
    if ($fo_rdate_to !== '')   { $where[] = 'co.required_date <= ?';    $params[] = $fo_rdate_to; }
    if ($fo_vehicle !== '')    { $where[] = 'co.delivery_type = ?';     $params[] = $fo_vehicle; }
    if ($fo_customer !== '')  { $where[] = '(c.name LIKE ? OR c.phone_number LIKE ?)'; $params[] = '%' . $fo_customer . '%'; $params[] = '%' . $fo_customer . '%'; }

    $join = '';
    if ($fo_product !== '') {
        $join = 'JOIN credit_order_items _coi ON _coi.order_id = co.id JOIN products _p ON _coi.product_id = _p.id AND _p.base_name LIKE ?';
        array_unshift($params, '%' . $fo_product . '%');
    }
    return [implode(' AND ', $where), $params, $join];
}

$filter_args = [$is_admin, $user_branch, $filter_branch_id, $fo_order, $fo_odate_from, $fo_odate_to,
                 $fo_rdate_from, $fo_rdate_to, $fo_vehicle, $fo_customer, $fo_product];

$schedule_orders = [];
$overdue_orders  = [];
try {
    [$sched_where, $sched_params, $sched_join] = dsConditions('>=', $today, ...$filter_args);
    $schedule_orders = $db->query(
        "SELECT co.id, co.order_number, co.order_date, co.required_date, co.delivery_type, co.status,
                co.assigned_branch_id, co.delivery_priority,
                c.name AS customer_name, c.phone_number AS customer_phone, b.name AS branch_name
         FROM credit_orders co
         JOIN customers c ON co.customer_id = c.id
         LEFT JOIN branches b ON co.assigned_branch_id = b.id
         $sched_join
         WHERE $sched_where
         GROUP BY co.id
         ORDER BY co.required_date ASC, co.delivery_priority ASC, co.id ASC",
        $sched_params
    )->results();

    [$over_where, $over_params, $over_join] = dsConditions('<', $today, ...$filter_args);
    $overdue_orders = $db->query(
        "SELECT co.id, co.order_number, co.order_date, co.required_date, co.delivery_type, co.status,
                co.assigned_branch_id, co.delivery_priority,
                c.name AS customer_name, c.phone_number AS customer_phone, b.name AS branch_name
         FROM credit_orders co
         JOIN customers c ON co.customer_id = c.id
         LEFT JOIN branches b ON co.assigned_branch_id = b.id
         $over_join
         WHERE $over_where
         GROUP BY co.id
         ORDER BY co.required_date ASC, co.delivery_priority ASC, co.id ASC",
        $over_params
    )->results();
} catch (Exception $e) {
    error_log('delivery_schedule.php query: ' . $e->getMessage());
}

// ── Items (product/variant/qty) per order, batched ────────────────────────────
$items_by_order = [];
$all_ids = array_unique(array_merge(array_map(fn($o) => (int)$o->id, $schedule_orders), array_map(fn($o) => (int)$o->id, $overdue_orders)));
if (!empty($all_ids)) {
    $ph = implode(',', array_fill(0, count($all_ids), '?'));
    $flat = $db->query(
        "SELECT coi.order_id, coi.quantity, p.base_name AS product_name, pv.grade, pv.weight_variant, pv.unit_of_measure
         FROM credit_order_items coi
         JOIN products p ON coi.product_id = p.id
         LEFT JOIN product_variants pv ON coi.variant_id = pv.id
         WHERE coi.order_id IN ($ph)
         ORDER BY coi.order_id, coi.id",
        array_values($all_ids)
    )->results();
    foreach ($flat as $it) {
        $variant = trim(($it->grade ? $it->grade . ' ' : '') . ($it->weight_variant ?? '') . ($it->unit_of_measure ?? ''));
        $items_by_order[$it->order_id][] = number_format((float)$it->quantity, 0) . 'x ' . $it->product_name . ($variant !== '' ? " ({$variant})" : '');
    }
}

require_once '../templates/header.php';

$status_cls = [
    'approved'      => 'bg-blue-100 text-blue-800',
    'in_production' => 'bg-purple-100 text-purple-800',
    'produced'      => 'bg-green-100 text-green-800',
    'ready_to_ship' => 'bg-orange-100 text-orange-800',
];
$status_labels = [
    'approved'      => 'Approved',
    'in_production' => 'In Production',
    'produced'      => 'Produced',
    'ready_to_ship' => 'Ready to Ship',
];
$day_palette = [
    ['bg' => 'bg-blue-50',    'accent' => 'border-blue-400'],
    ['bg' => 'bg-amber-50',   'accent' => 'border-amber-400'],
    ['bg' => 'bg-emerald-50', 'accent' => 'border-emerald-400'],
    ['bg' => 'bg-violet-50',  'accent' => 'border-violet-400'],
    ['bg' => 'bg-rose-50',    'accent' => 'border-rose-400'],
    ['bg' => 'bg-cyan-50',    'accent' => 'border-cyan-400'],
    ['bg' => 'bg-orange-50',  'accent' => 'border-orange-400'],
    ['bg' => 'bg-teal-50',    'accent' => 'border-teal-400'],
];
$vehicle_badge = function ($type) {
    if ($type === 'mini_truck') {
        return '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 font-semibold whitespace-nowrap"><i class="fas fa-truck-pickup text-xs"></i>Mini Truck</span>';
    }
    return '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 font-semibold whitespace-nowrap"><i class="fas fa-truck text-xs"></i>Big Truck</span>';
};
$day_label = function (string $date, string $today) {
    $diff = (strtotime($date) - strtotime($today)) / 86400;
    if ($diff == 0) return 'Today';
    if ($diff == 1) return 'Tomorrow';
    if ($diff == -1) return 'Yesterday';
    return date('D, d M Y', strtotime($date));
};
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-4">

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">
            <?php echo $pageTitle; ?>
            <?php if ($filter_branch_name): ?>
            <span class="ml-2 text-sm font-normal text-blue-600">— <?php echo htmlspecialchars($filter_branch_name); ?></span>
            <?php endif; ?>
        </h1>
        <p class="text-xs text-gray-500 mt-0.5">
            Ordered by closest delivery date<?php echo $is_admin ? ' — drag rows to reorder or reschedule' : ''; ?>
        </p>
    </div>
    <div class="flex gap-2 flex-wrap items-center">
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-blue-100 text-blue-800 text-xs font-semibold">
            <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span><?php echo count($schedule_orders); ?> Scheduled
        </span>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-red-100 text-red-800 text-xs font-semibold">
            <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span><?php echo count($overdue_orders); ?> Overdue
        </span>
    </div>
</div>

<div id="flashBanner"></div>

<!-- Filters -->
<form method="GET" class="bg-white rounded-lg border border-gray-200 shadow-sm px-4 py-3 mb-4">
    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
        <?php if ($is_admin): ?>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Branch</label>
            <select name="branch_id" class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
                <option value="0">All Branches</option>
                <?php foreach ($all_branches as $br): ?>
                <option value="<?php echo $br->id; ?>" <?php echo $filter_branch_id === (int)$br->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($br->name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Order Number</label>
            <input type="text" name="fo_order" value="<?php echo htmlspecialchars($fo_order); ?>" placeholder="CR-..." class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Customer</label>
            <input type="text" name="fo_customer" value="<?php echo htmlspecialchars($fo_customer); ?>" placeholder="Name or phone..." class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Product</label>
            <input type="text" name="fo_product" value="<?php echo htmlspecialchars($fo_product); ?>" placeholder="Product name..." class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Truck</label>
            <select name="fo_vehicle" class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
                <option value="">All</option>
                <option value="big_truck" <?php echo $fo_vehicle === 'big_truck' ? 'selected' : ''; ?>>Big Truck</option>
                <option value="mini_truck" <?php echo $fo_vehicle === 'mini_truck' ? 'selected' : ''; ?>>Mini Truck</option>
            </select>
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Order Created From</label>
            <input type="date" name="fo_odate_from" value="<?php echo htmlspecialchars($fo_odate_from); ?>" class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Order Created To</label>
            <input type="date" name="fo_odate_to" value="<?php echo htmlspecialchars($fo_odate_to); ?>" class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Required Date From</label>
            <input type="date" name="fo_rdate_from" value="<?php echo htmlspecialchars($fo_rdate_from); ?>" class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Required Date To</label>
            <input type="date" name="fo_rdate_to" value="<?php echo htmlspecialchars($fo_rdate_to); ?>" class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded hover:bg-blue-700 transition-colors cursor-pointer">
                <i class="fas fa-filter mr-1"></i>Apply
            </button>
            <a href="delivery_schedule.php?tab=<?php echo $active_tab; ?>" class="px-3 py-1.5 bg-gray-100 text-gray-600 text-xs rounded hover:bg-gray-200 transition-colors cursor-pointer">
                <i class="fas fa-times mr-1"></i>Reset
            </a>
        </div>
    </div>
</form>

<?php
// Tab links preserve every current filter param except tab
$qs = $_GET; unset($qs['tab']);
$qs_str = http_build_query($qs);
$qs_str = $qs_str !== '' ? '&' . $qs_str : '';
?>
<div class="flex gap-1 mb-4 border-b border-gray-200">
    <a href="?tab=schedule<?php echo $qs_str; ?>"
       class="flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium rounded-t-lg border border-b-0 -mb-px transition-colors cursor-pointer
              <?php echo $active_tab === 'schedule' ? 'bg-white border-gray-200 text-blue-700 border-b-white' : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50 mb-0'; ?>">
        <i class="fas fa-calendar-days text-xs"></i>Schedule (<?php echo count($schedule_orders); ?>)
    </a>
    <a href="?tab=overdue<?php echo $qs_str; ?>"
       class="flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium rounded-t-lg border border-b-0 -mb-px transition-colors cursor-pointer
              <?php echo $active_tab === 'overdue' ? 'bg-white border-gray-200 text-red-700 border-b-white' : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50 mb-0'; ?>">
        <i class="fas fa-triangle-exclamation text-xs"></i>Overdue (<?php echo count($overdue_orders); ?>)
    </a>
</div>

<?php
function renderDeliveryBoard(array $rows, string $tab_key, bool $is_overdue_tab, bool $is_admin, array $items_by_order,
                              array $status_cls, array $status_labels, array $day_palette, Closure $vehicle_badge, Closure $day_label, string $today) {
    if (empty($rows)) {
        echo '<div class="bg-white rounded-lg border border-gray-200 shadow-sm p-12 text-center">
                <i class="fas fa-calendar-check text-5xl text-gray-300 mb-4"></i>
                <h3 class="text-base font-semibold text-gray-600 mb-1">Nothing here</h3>
                <p class="text-sm text-gray-400">No orders match the current filters.</p>
              </div>';
        return;
    }

    $by_date = [];
    foreach ($rows as $r) $by_date[$r->required_date][] = $r;

    $color_idx = 0;
    ?>
    <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-200 text-left">
                    <?php if ($is_admin): ?><th class="px-2 py-2 w-6"></th><?php endif; ?>
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap text-center">#</th>
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Order</th>
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Customer</th>
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Branch</th>
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Truck</th>
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Products</th>
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Status</th>
                    <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap text-center">Actions</th>
                </tr>
            </thead>
            <tbody id="tbody_<?php echo $tab_key; ?>" data-tab="<?php echo $tab_key; ?>">
            <?php foreach ($by_date as $date => $day_orders):
                $palette = $day_palette[$color_idx % count($day_palette)];
                $color_idx++;
                $days_overdue = $is_overdue_tab ? (int)floor((strtotime('today') - strtotime($date)) / 86400) : 0;
            ?>
                <tr class="day-sep <?php echo $palette['bg']; ?> border-l-4 <?php echo $palette['accent']; ?>" data-date="<?php echo $date; ?>">
                    <td colspan="<?php echo $is_admin ? 9 : 8; ?>" class="px-3 py-1.5 font-bold text-gray-700">
                        <i class="fas fa-calendar-day mr-1.5 text-gray-400"></i><?php echo $day_label($date, $today); ?>
                        <span class="font-normal text-gray-400">(<?php echo date('d M Y', strtotime($date)); ?>)</span>
                        <span class="ml-2 px-1.5 py-0.5 rounded-full bg-white/70 text-[10px] font-semibold"><?php echo count($day_orders); ?> order<?php echo count($day_orders) !== 1 ? 's' : ''; ?></span>
                        <?php if ($is_overdue_tab): ?>
                        <span class="ml-1 px-1.5 py-0.5 rounded-full bg-red-600 text-white text-[10px] font-bold"><?php echo $days_overdue; ?>d overdue</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php foreach ($day_orders as $pos => $o):
                    $order_items = $items_by_order[$o->id] ?? [];
                    $items_txt   = implode(', ', $order_items);
                ?>
                <tr class="order-row <?php echo $palette['bg']; ?> hover:brightness-95 transition-colors <?php echo $is_admin ? 'cursor-move' : ''; ?>"
                    <?php echo $is_admin ? 'draggable="true"' : ''; ?> data-order-id="<?php echo $o->id; ?>">
                    <?php if ($is_admin): ?>
                    <td class="px-2 py-2 text-center text-gray-300"><i class="fas fa-grip-vertical"></i></td>
                    <?php endif; ?>
                    <td class="px-3 py-2.5 text-center whitespace-nowrap">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-white/80 text-gray-700 font-bold text-xs"><?php echo $pos + 1; ?></span>
                    </td>
                    <td class="px-3 py-2.5 whitespace-nowrap">
                        <a href="credit_order_view.php?id=<?php echo $o->id; ?>" class="font-mono font-bold text-blue-700 hover:underline"><?php echo htmlspecialchars($o->order_number); ?></a>
                        <div class="text-gray-400 mt-0.5"><?php echo $o->order_date ? date('d M', strtotime($o->order_date)) : '—'; ?></div>
                    </td>
                    <td class="px-3 py-2.5 max-w-[160px]">
                        <div class="font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($o->customer_name); ?></div>
                        <div class="text-gray-400 truncate"><?php echo htmlspecialchars($o->customer_phone ?? ''); ?></div>
                    </td>
                    <td class="px-3 py-2.5 whitespace-nowrap text-gray-600"><?php echo htmlspecialchars($o->branch_name ?? '—'); ?></td>
                    <td class="px-3 py-2.5 whitespace-nowrap"><?php echo $vehicle_badge($o->delivery_type); ?></td>
                    <td class="px-3 py-2.5 max-w-[260px]">
                        <?php if ($items_txt !== ''): ?>
                        <span class="text-gray-700" title="<?php echo htmlspecialchars($items_txt); ?>"><?php echo htmlspecialchars(mb_strimwidth($items_txt, 0, 90, '…')); ?></span>
                        <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
                    </td>
                    <td class="px-3 py-2.5 whitespace-nowrap">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full font-semibold text-xs <?php echo $status_cls[$o->status] ?? 'bg-gray-100 text-gray-700'; ?>">
                            <?php echo $status_labels[$o->status] ?? ucfirst($o->status); ?>
                        </span>
                    </td>
                    <td class="px-3 py-2.5 text-center whitespace-nowrap">
                        <div class="inline-flex items-center gap-1">
                            <a href="credit_order_view.php?id=<?php echo $o->id; ?>" class="inline-flex items-center px-2 py-1 border border-gray-300 text-gray-600 rounded hover:bg-gray-100 cursor-pointer" title="View"><i class="fas fa-eye text-xs"></i></a>
                            <?php if ($is_admin): ?>
                            <button type="button" title="Edit"
                                    onclick='openEditModal(<?php echo json_encode([
                                        'id' => (int)$o->id, 'order_number' => $o->order_number,
                                        'required_date' => $o->required_date, 'delivery_type' => $o->delivery_type,
                                        'branch_id' => (int)$o->assigned_branch_id,
                                    ], JSON_UNESCAPED_UNICODE); ?>)'
                                    class="inline-flex items-center px-2 py-1 border border-blue-300 text-blue-600 rounded hover:bg-blue-50 cursor-pointer">
                                <i class="fas fa-pen text-xs"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php
}

echo '<div class="' . ($active_tab !== 'schedule' ? 'hidden' : '') . '">';
renderDeliveryBoard($schedule_orders, 'schedule', false, $is_admin, $items_by_order, $status_cls, $status_labels, $day_palette, $vehicle_badge, $day_label, $today);
echo '</div>';

echo '<div class="' . ($active_tab !== 'overdue' ? 'hidden' : '') . '">';
renderDeliveryBoard($overdue_orders, 'overdue', true, $is_admin, $items_by_order, $status_cls, $status_labels, $day_palette, $vehicle_badge, $day_label, $today);
echo '</div>';
?>

<?php if ($is_admin): ?>
<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-black/50">
    <div class="bg-white rounded-2xl shadow-xl max-w-md w-full p-6">
        <h2 class="text-lg font-bold text-gray-900 mb-4">Edit Delivery — <span id="editOrderNum" class="font-mono text-blue-700"></span></h2>
        <div class="space-y-3">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Required Delivery Date</label>
                <input type="date" id="editReqDate" class="w-full px-3 py-2 border rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Truck</label>
                <select id="editVehicle" class="w-full px-3 py-2 border rounded-lg text-sm">
                    <option value="big_truck">Big Truck</option>
                    <option value="mini_truck">Mini Truck</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Branch</label>
                <select id="editBranch" class="w-full px-3 py-2 border rounded-lg text-sm">
                    <?php foreach ($all_branches as $br): ?>
                    <option value="<?php echo $br->id; ?>"><?php echo htmlspecialchars($br->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <p id="editError" class="text-xs text-red-600 mt-3 hidden"></p>
        <div class="flex gap-3 justify-end mt-5">
            <button onclick="closeEditModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-sm cursor-pointer">Cancel</button>
            <button id="editSaveBtn" onclick="submitEdit()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium cursor-pointer">Save</button>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;

// ── Edit modal ──────────────────────────────────────────────────────────────
let _editOrder = null;
function openEditModal(o) {
    _editOrder = o;
    document.getElementById('editOrderNum').textContent = o.order_number;
    document.getElementById('editReqDate').value = o.required_date;
    document.getElementById('editVehicle').value = o.delivery_type;
    document.getElementById('editBranch').value = o.branch_id;
    document.getElementById('editError').classList.add('hidden');
    document.getElementById('editModal').style.display = 'flex';
}
function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    _editOrder = null;
}
function submitEdit() {
    if (!_editOrder) return;
    const payload = {
        action: 'delivery_schedule_edit_order',
        order_id: _editOrder.id,
        required_date: document.getElementById('editReqDate').value,
        delivery_type: document.getElementById('editVehicle').value,
        branch_id: parseInt(document.getElementById('editBranch').value, 10),
    };
    const btn = document.getElementById('editSaveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    fetch('ajax_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { location.reload(); }
        else {
            const err = document.getElementById('editError');
            err.textContent = d.error || 'Save failed.';
            err.classList.remove('hidden');
            btn.disabled = false; btn.textContent = 'Save';
        }
    })
    .catch(() => {
        const err = document.getElementById('editError');
        err.textContent = 'Network error — please try again.';
        err.classList.remove('hidden');
        btn.disabled = false; btn.textContent = 'Save';
    });
}
document.getElementById('editModal').addEventListener('click', function (e) {
    if (e.target === this) closeEditModal();
});

// ── Drag-and-drop reorder / reschedule ───────────────────────────────────────
let _dragEl = null;

function initDragDrop(tbodyId) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;

    tbody.querySelectorAll('tr.order-row').forEach(row => {
        row.addEventListener('dragstart', () => {
            _dragEl = row;
            row.classList.add('opacity-40');
        });
        row.addEventListener('dragend', () => {
            row.classList.remove('opacity-40');
            _dragEl = null;
        });
    });

    tbody.addEventListener('dragover', function (e) {
        e.preventDefault();
        if (!_dragEl) return;
        const targetRow = e.target.closest('tr');
        if (!targetRow || targetRow === _dragEl) return;

        if (targetRow.classList.contains('day-sep')) {
            tbody.insertBefore(_dragEl, targetRow.nextSibling);
            return;
        }
        const rect = targetRow.getBoundingClientRect();
        const before = (e.clientY - rect.top) < rect.height / 2;
        tbody.insertBefore(_dragEl, before ? targetRow : targetRow.nextSibling);
    });

    tbody.addEventListener('drop', function (e) {
        e.preventDefault();
        if (!_dragEl) return;
        saveReorder(tbody);
    });
}

function saveReorder(tbody) {
    let currentDate = null;
    const items = [];
    tbody.querySelectorAll('tr').forEach(row => {
        if (row.classList.contains('day-sep')) {
            currentDate = row.dataset.date;
        } else if (row.classList.contains('order-row') && currentDate) {
            items.push({ order_id: parseInt(row.dataset.orderId, 10), required_date: currentDate });
        }
    });
    if (items.length === 0) return;

    fetch('ajax_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify({ action: 'delivery_schedule_reorder', items })
    })
    .then(r => r.json())
    .then(d => { location.reload(); })
    .catch(() => { alert('Network error saving the new order — please retry.'); location.reload(); });
}

initDragDrop('tbody_schedule');
initDragDrop('tbody_overdue');
</script>
<?php endif; ?>

</div>
<?php require_once '../templates/footer.php'; ?>

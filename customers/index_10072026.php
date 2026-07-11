<?php
require_once '../core/init.php';

$allowed_roles = [
    'Superadmin','admin','Accounts',
    'accounts-srg','accounts-demra','accountspos-demra','accountspos-srg',
    'sales-srg','sales-demra','sales-other','collector',
];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Customers';
$csrfToken = $_SESSION['csrf_token'] ?? '';
$userRole  = $_SESSION['user_role']  ?? '';

// ── Handle Delete ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_customer'])) {
    try {
        if (!in_array($userRole, ['Superadmin','admin']))
            throw new Exception('Permission denied.');
        if (!$csrfToken || !hash_equals($csrfToken, $_POST['csrf_token'] ?? ''))
            throw new Exception('Invalid security token.');
        $del_id = (int)$_POST['delete_id'];
        $cust = $db->query("SELECT photo_url FROM customers WHERE id = ?", [$del_id])->first();
        if ($cust && $cust->photo_url && file_exists('../' . $cust->photo_url))
            unlink('../' . $cust->photo_url);
        $db->query("DELETE FROM customers WHERE id = ?", [$del_id]);
        $_SESSION['success_flash'] = 'Customer deleted.';
    } catch (Exception $e) {
        $_SESSION['error_flash'] = $e->getMessage();
    }
    header('Location: index.php');
    exit();
}

// ── Filters ────────────────────────────────────────────────────────────────
$search   = trim($_GET['search']  ?? '');
$f_type   = $_GET['type']   ?? 'Credit';
$f_status = $_GET['status'] ?? 'active';
$today    = date('Y-m-d');

// Receivable movement date range (defaults to today)
$mov_from = !empty($_GET['mov_from']) ? $_GET['mov_from'] : $today;
$mov_to   = !empty($_GET['mov_to'])   ? $_GET['mov_to']   : $today;
if ($mov_from > $mov_to) [$mov_from, $mov_to] = [$mov_to, $mov_from];

// ── WHERE clause ──────────────────────────────────────────────────────────
$where  = [];
$params = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = "(c.name LIKE ? OR c.business_name LIKE ? OR c.phone_number LIKE ?)";
    array_push($params, $like, $like, $like);
}
if ($f_type   !== '') { $where[] = "c.customer_type = ?"; $params[] = $f_type; }
if ($f_status !== '') { $where[] = "c.status = ?";        $params[] = $f_status; }
$where_sql = $where ? ('AND ' . implode(' AND ', $where)) : '';

// ── Summary counts ─────────────────────────────────────────────────────────
// Use true_balance from ledger (latest balance_after per customer) to avoid stale cache
$summary = $db->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN COALESCE(tb.true_balance, c.initial_due, 0) >  0.01 THEN 1 ELSE 0 END) AS cnt_due,
            SUM(CASE WHEN COALESCE(tb.true_balance, c.initial_due, 0) < -0.01 THEN 1 ELSE 0 END) AS cnt_advance,
            COALESCE(SUM(CASE WHEN COALESCE(tb.true_balance, c.initial_due, 0) > 0 THEN COALESCE(tb.true_balance, c.initial_due, 0) ELSE 0 END), 0) AS total_due,
            COALESCE(SUM(CASE WHEN COALESCE(tb.true_balance, c.initial_due, 0) < 0 THEN ABS(COALESCE(tb.true_balance, c.initial_due, 0)) ELSE 0 END), 0) AS total_advance
     FROM customers c
     LEFT JOIN (
         SELECT customer_id, balance_after AS true_balance
         FROM customer_ledger
         WHERE id IN (SELECT MAX(id) FROM customer_ledger GROUP BY customer_id)
     ) tb ON tb.customer_id = c.id
     WHERE 1=1 $where_sql",
    $params
)->first();

// ── Shared subqueries for enrichment ───────────────────────────────────────
$base_enriched_sql = "
    FROM customers c
    LEFT JOIN (
        SELECT customer_id, MAX(order_date) AS last_order_date
        FROM credit_orders WHERE status NOT IN ('cancelled','rejected','draft')
        GROUP BY customer_id
    ) lo ON lo.customer_id = c.id
    LEFT JOIN (
        SELECT customer_id, MAX(payment_date) AS last_payment_date
        FROM customer_payments GROUP BY customer_id
    ) lp ON lp.customer_id = c.id
    LEFT JOIN (
        SELECT customer_id, COUNT(*) AS open_orders
        FROM credit_orders WHERE status NOT IN ('delivered','cancelled','rejected','draft')
        GROUP BY customer_id
    ) oo ON oo.customer_id = c.id
    LEFT JOIN (
        SELECT customer_id, balance_after AS true_balance
        FROM customer_ledger
        WHERE id IN (SELECT MAX(id) FROM customer_ledger GROUP BY customer_id)
    ) tb ON tb.customer_id = c.id
";

// ── Customers with Due (balance > 0) ──────────────────────────────────────
$customers_due = $db->query(
    "SELECT c.id, c.name, c.business_name, c.phone_number, c.credit_limit,
            COALESCE(tb.true_balance, c.initial_due, 0) AS current_balance,
            lo.last_order_date, lp.last_payment_date, COALESCE(oo.open_orders, 0) AS open_orders
     $base_enriched_sql
     WHERE COALESCE(tb.true_balance, c.initial_due, 0) > 0.01 $where_sql
     ORDER BY current_balance DESC LIMIT 500",
    $params
)->results();

// ── Customers in Advance (balance < 0) ────────────────────────────────────
$customers_advance = $db->query(
    "SELECT c.id, c.name, c.business_name, c.phone_number, c.credit_limit,
            COALESCE(tb.true_balance, c.initial_due, 0) AS current_balance,
            lo.last_order_date, lp.last_payment_date
     $base_enriched_sql
     WHERE COALESCE(tb.true_balance, c.initial_due, 0) < -0.01 $where_sql
     ORDER BY current_balance ASC LIMIT 500",
    $params
)->results();

// ── Receivable Movement (Credit + active customers with activity in range) ─
$mov_search_where  = '';
$mov_search_params = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $mov_search_where  = "AND (c.name LIKE ? OR c.phone_number LIKE ?)";
    $mov_search_params = [$like, $like];
}
$movement = $db->query(
    "SELECT c.id, c.name,
            COALESCE(tb.true_balance, c.initial_due, 0) AS current_balance,
            COALESCE(ord.orders_amount, 0)   AS orders_in_range,
            COALESCE(pay.payments_amount, 0) AS payments_in_range,
            (COALESCE(tb.true_balance, c.initial_due, 0)
               - COALESCE(ord.orders_amount,   0)
               + COALESCE(pay.payments_amount, 0)) AS opening_balance
     FROM customers c
     LEFT JOIN (
         SELECT customer_id, balance_after AS true_balance
         FROM customer_ledger
         WHERE id IN (SELECT MAX(id) FROM customer_ledger GROUP BY customer_id)
     ) tb ON tb.customer_id = c.id
     LEFT JOIN (
         SELECT customer_id, SUM(total_amount) AS orders_amount
         FROM credit_orders
         WHERE DATE(order_date) BETWEEN ? AND ?
           AND status NOT IN ('cancelled','rejected','draft')
         GROUP BY customer_id
     ) ord ON ord.customer_id = c.id
     LEFT JOIN (
         SELECT customer_id, SUM(amount) AS payments_amount
         FROM customer_payments
         WHERE DATE(payment_date) BETWEEN ? AND ?
         GROUP BY customer_id
     ) pay ON pay.customer_id = c.id
     WHERE c.customer_type = 'Credit' AND c.status = 'active'
       AND (COALESCE(ord.orders_amount, 0) > 0 OR COALESCE(pay.payments_amount, 0) > 0)
       $mov_search_where
     ORDER BY current_balance DESC LIMIT 200",
    array_merge([$mov_from, $mov_to, $mov_from, $mov_to], $mov_search_params)
)->results();

// ── Helpers ───────────────────────────────────────────────────────────────
function get_initials(string $name): string {
    $words = array_values(array_filter(explode(' ', trim($name))));
    return count($words) >= 2
        ? strtoupper(substr($words[0],0,1) . substr(end($words),0,1))
        : strtoupper(substr($name, 0, 2));
}

function days_ago(?string $date): ?int {
    if (!$date || $date === '0000-00-00') return null;
    return (int)floor((time() - strtotime($date)) / 86400);
}

function fmt_days(?int $d): string {
    if ($d === null) return '<span class="text-gray-300">—</span>';
    if ($d === 0)    return '<span class="text-green-600 font-semibold">Today</span>';
    if ($d <= 7)     return '<span class="text-green-600">' . $d . 'd ago</span>';
    if ($d <= 30)    return '<span class="text-gray-500">' . $d . 'd ago</span>';
    if ($d <= 60)    return '<span class="text-amber-600 font-semibold">' . $d . 'd ago</span>';
    return '<span class="text-red-600 font-bold">' . $d . 'd ago</span>';
}

function fmt_money(float $n): string {
    return '৳' . number_format($n, 0);
}

$mov_is_single = ($mov_from === $mov_to);
$mov_label     = $mov_is_single
    ? ($mov_from === date('Y-m-d') ? 'Today' : date('d M Y', strtotime($mov_from)))
    : date('d M Y', strtotime($mov_from)) . ' — ' . date('d M Y', strtotime($mov_to));

$successMsg = $_SESSION['success_flash'] ?? null;
$errorMsg   = $_SESSION['error_flash']   ?? null;
unset($_SESSION['success_flash'], $_SESSION['error_flash']);

require_once '../templates/header.php';
?>

<div class="w-full px-4 sm:px-6 lg:px-8 py-4">

<!-- ── Page Header ──────────────────────────────────────────────────────── -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-3">
  <div>
    <h1 class="text-lg font-bold text-gray-900 flex items-center gap-2">
      <i class="fas fa-users text-primary-500 text-base"></i> Customers
    </h1>
    <p class="text-xs text-gray-400 mt-0.5">
      <?php echo (int)($summary->total ?? 0); ?> matching &nbsp;&middot;&nbsp;
      <?php echo (int)($summary->cnt_due ?? 0); ?> with due &nbsp;&middot;&nbsp;
      <?php echo (int)($summary->cnt_advance ?? 0); ?> in advance
      <?php if ($search || $f_type !== 'Credit' || $f_status !== 'active'): ?>
        &middot; <a href="index.php" class="text-primary-500 hover:underline">reset</a>
      <?php endif; ?>
    </p>
  </div>
  <a href="manage.php"
     class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition-colors">
    <i class="fas fa-plus"></i> Add Customer
  </a>
</div>

<!-- ── Flash Messages ───────────────────────────────────────────────────── -->
<?php if ($successMsg): ?>
<div class="mb-3 flex items-center gap-2 bg-green-50 border border-green-200 text-green-800 px-3 py-2 rounded-lg text-xs">
  <i class="fas fa-check-circle text-green-500"></i><?php echo htmlspecialchars($successMsg); ?>
</div>
<?php endif; ?>
<?php if ($errorMsg): ?>
<div class="mb-3 flex items-center gap-2 bg-red-50 border border-red-200 text-red-800 px-3 py-2 rounded-lg text-xs">
  <i class="fas fa-exclamation-circle text-red-500"></i><?php echo htmlspecialchars($errorMsg); ?>
</div>
<?php endif; ?>

<!-- ── Filter Bar ────────────────────────────────────────────────────────── -->
<form method="GET" action="index.php" class="bg-white border border-gray-100 rounded-xl shadow-sm px-4 py-3 mb-4">
  <div class="flex flex-wrap items-end gap-2">
    <div class="flex-1 min-w-[160px]">
      <label class="block text-[10px] font-semibold text-gray-400 uppercase mb-1">Search</label>
      <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
             placeholder="Name, phone, business…"
             class="w-full border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs focus:ring-1 focus:ring-primary-400 outline-none">
    </div>
    <div>
      <label class="block text-[10px] font-semibold text-gray-400 uppercase mb-1">Type</label>
      <select name="type" class="border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs bg-white focus:ring-1 focus:ring-primary-400 outline-none">
        <option value="">All Types</option>
        <option value="Credit" <?php echo $f_type === 'Credit' ? 'selected' : ''; ?>>Credit</option>
        <option value="POS"    <?php echo $f_type === 'POS'    ? 'selected' : ''; ?>>POS</option>
      </select>
    </div>
    <div>
      <label class="block text-[10px] font-semibold text-gray-400 uppercase mb-1">Status</label>
      <select name="status" class="border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs bg-white focus:ring-1 focus:ring-primary-400 outline-none">
        <option value="">All</option>
        <option value="active"      <?php echo $f_status === 'active'      ? 'selected' : ''; ?>>Active</option>
        <option value="inactive"    <?php echo $f_status === 'inactive'    ? 'selected' : ''; ?>>Inactive</option>
        <option value="blacklisted" <?php echo $f_status === 'blacklisted' ? 'selected' : ''; ?>>Blacklisted</option>
      </select>
    </div>
    <!-- Pass movement range through so it survives filter resubmit -->
    <input type="hidden" name="mov_from" value="<?php echo $mov_from; ?>">
    <input type="hidden" name="mov_to"   value="<?php echo $mov_to; ?>">
    <div class="flex gap-1.5">
      <button type="submit"
              class="px-3 py-1.5 bg-primary-600 text-white text-xs font-semibold rounded-lg hover:bg-primary-700 transition-colors">
        <i class="fas fa-search text-[10px] mr-1"></i>Apply
      </button>
      <a href="index.php" class="px-3 py-1.5 border border-gray-200 text-gray-600 text-xs rounded-lg hover:bg-gray-50 transition-colors">
        Reset
      </a>
    </div>
  </div>
</form>

<!-- ── Summary Cards ─────────────────────────────────────────────────────── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
  <div class="bg-red-600 text-white rounded-xl px-4 py-3 shadow-sm">
    <p class="text-[10px] font-semibold uppercase tracking-wide opacity-80">Customers with Due</p>
    <p class="text-2xl font-bold mt-0.5"><?php echo (int)($summary->cnt_due ?? 0); ?></p>
    <p class="text-xs font-semibold mt-0.5 opacity-90"><?php echo fmt_money((float)($summary->total_due ?? 0)); ?> receivable</p>
  </div>
  <div class="bg-green-600 text-white rounded-xl px-4 py-3 shadow-sm">
    <p class="text-[10px] font-semibold uppercase tracking-wide opacity-80">Customers in Advance</p>
    <p class="text-2xl font-bold mt-0.5"><?php echo (int)($summary->cnt_advance ?? 0); ?></p>
    <p class="text-xs font-semibold mt-0.5 opacity-90"><?php echo fmt_money((float)($summary->total_advance ?? 0)); ?> advance held</p>
  </div>
  <div class="bg-white border border-gray-100 rounded-xl px-4 py-3 shadow-sm">
    <p class="text-[10px] font-semibold text-red-500 uppercase tracking-wide">Total Receivable</p>
    <p class="text-2xl font-bold text-red-700 mt-0.5"><?php echo fmt_money((float)($summary->total_due ?? 0)); ?></p>
    <p class="text-xs text-gray-400 mt-0.5">from <?php echo (int)($summary->cnt_due ?? 0); ?> customers</p>
  </div>
  <div class="bg-white border border-gray-100 rounded-xl px-4 py-3 shadow-sm">
    <p class="text-[10px] font-semibold text-green-600 uppercase tracking-wide">Total Advance Held</p>
    <p class="text-2xl font-bold text-green-700 mt-0.5"><?php echo fmt_money((float)($summary->total_advance ?? 0)); ?></p>
    <p class="text-xs text-gray-400 mt-0.5">from <?php echo (int)($summary->cnt_advance ?? 0); ?> customers</p>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     RECEIVABLE MOVEMENT
════════════════════════════════════════════════════════════════════════════ -->
<div class="bg-white rounded-xl shadow-sm border border-blue-100 overflow-hidden mb-4">
  <div class="px-4 py-2.5 bg-blue-50 border-b border-blue-100">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xs font-bold text-blue-900">
          <i class="fas fa-exchange-alt mr-1.5 text-blue-500"></i>Receivable Movement
        </h2>
        <p class="text-[10px] text-blue-400 mt-0.5">Opening + orders − payments = closing</p>
      </div>
      <form method="GET" action="index.php" class="flex items-center gap-2 flex-wrap">
        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
        <input type="hidden" name="type"   value="<?php echo htmlspecialchars($f_type); ?>">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($f_status); ?>">
        <div class="flex gap-1">
          <?php
          $mov_presets = [
              ['Today',      $today,                                          $today],
              ['Yesterday',  date('Y-m-d', strtotime('-1 day')),             date('Y-m-d', strtotime('-1 day'))],
              ['This Week',  date('Y-m-d', strtotime('monday this week')),   $today],
              ['This Month', date('Y-m-01'),                                  $today],
              ['Last 30d',   date('Y-m-d', strtotime('-29 days')),           $today],
          ];
          foreach ($mov_presets as [$plabel, $pf, $pt]):
            $active = ($mov_from === $pf && $mov_to === $pt);
          ?>
          <a href="?<?php echo http_build_query(array_filter(['search'=>$search,'type'=>$f_type,'status'=>$f_status,'mov_from'=>$pf,'mov_to'=>$pt])); ?>"
             class="px-2.5 py-1 text-[10px] font-semibold rounded border transition-colors
                    <?php echo $active ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-200 text-gray-500 hover:bg-gray-50'; ?>">
            <?php echo $plabel; ?>
          </a>
          <?php endforeach; ?>
        </div>
        <div class="flex items-center gap-1.5">
          <input type="date" name="mov_from" value="<?php echo $mov_from; ?>"
                 class="text-xs border border-gray-200 rounded-lg px-2.5 py-1 focus:outline-none focus:border-blue-400">
          <span class="text-[10px] text-gray-400">to</span>
          <input type="date" name="mov_to" value="<?php echo $mov_to; ?>"
                 class="text-xs border border-gray-200 rounded-lg px-2.5 py-1 focus:outline-none focus:border-blue-400">
          <button type="submit"
                  class="px-2.5 py-1 bg-blue-600 text-white text-[10px] font-semibold rounded-lg hover:bg-blue-700 transition-colors">
            Apply
          </button>
        </div>
      </form>
    </div>
  </div>

  <?php if (empty($movement)): ?>
  <div class="py-8 text-center">
    <i class="fas fa-calendar-times text-gray-300 text-3xl mb-2 block"></i>
    <p class="text-sm text-gray-400">No orders or payments for <strong><?php echo $mov_label; ?></strong></p>
  </div>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="w-full">
      <thead class="bg-gray-50 border-b border-gray-100">
        <tr>
          <th class="px-2 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase w-6">#</th>
          <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-500 uppercase">Customer</th>
          <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-500 uppercase whitespace-nowrap">Opening</th>
          <th class="px-3 py-2 text-right text-[10px] font-semibold text-blue-600 uppercase whitespace-nowrap">+ Orders</th>
          <th class="px-3 py-2 text-right text-[10px] font-semibold text-green-600 uppercase whitespace-nowrap">− Payments</th>
          <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-700 uppercase whitespace-nowrap">Closing</th>
          <th class="px-3 py-2 text-center text-[10px] font-semibold text-gray-400 uppercase whitespace-nowrap">Net</th>
          <th class="px-2 py-2 text-center text-[10px] font-semibold text-gray-400 uppercase"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50">
      <?php
      $mov_tot_opening  = 0;
      $mov_tot_orders   = 0;
      $mov_tot_payments = 0;
      $mov_tot_closing  = 0;
      foreach ($movement as $i => $m):
        $opening  = (float)$m->opening_balance;
        $orders   = (float)$m->orders_in_range;
        $payments = (float)$m->payments_in_range;
        $closing  = (float)$m->current_balance;
        $net      = $orders - $payments;
        $mov_tot_opening  += $opening;
        $mov_tot_orders   += $orders;
        $mov_tot_payments += $payments;
        $mov_tot_closing  += $closing;
      ?>
      <tr class="hover:bg-blue-50 transition-colors">
        <td class="px-2 py-1 text-[10px] text-gray-300 font-mono"><?php echo $i+1; ?></td>
        <td class="px-3 py-1 whitespace-nowrap">
          <a href="view.php?id=<?php echo $m->id; ?>"
             class="text-xs font-semibold text-gray-800 hover:text-primary-600 transition-colors">
            <?php echo htmlspecialchars($m->name); ?>
          </a>
        </td>
        <td class="px-3 py-1 text-right text-xs whitespace-nowrap">
          <?php if (abs($opening) < 0.01): ?>
            <span class="text-gray-400">0</span>
          <?php elseif ($opening > 0): ?>
            <span class="text-red-600 font-semibold"><?php echo fmt_money($opening); ?></span>
          <?php else: ?>
            <span class="text-green-600 font-semibold">(<?php echo fmt_money(abs($opening)); ?>)</span>
          <?php endif; ?>
        </td>
        <td class="px-3 py-1.5 text-right text-xs whitespace-nowrap">
          <?php if ($orders > 0): ?>
          <span class="font-semibold text-blue-600"><?php echo fmt_money($orders); ?></span>
          <?php else: ?>
          <span class="text-gray-300">—</span>
          <?php endif; ?>
        </td>
        <td class="px-3 py-1.5 text-right text-xs whitespace-nowrap">
          <?php if ($payments > 0): ?>
          <span class="font-semibold text-green-600"><?php echo fmt_money($payments); ?></span>
          <?php else: ?>
          <span class="text-gray-300">—</span>
          <?php endif; ?>
        </td>
        <td class="px-3 py-1.5 text-right text-xs whitespace-nowrap">
          <?php if (abs($closing) < 0.01): ?>
            <span class="px-2 py-0.5 bg-green-100 text-green-700 text-[10px] font-bold rounded-full">Settled</span>
          <?php elseif ($closing > 0): ?>
            <span class="font-bold text-red-600"><?php echo fmt_money($closing); ?></span>
          <?php else: ?>
            <span class="font-bold text-green-600">(<?php echo fmt_money(abs($closing)); ?>)</span>
          <?php endif; ?>
        </td>
        <td class="px-3 py-1.5 text-center whitespace-nowrap">
          <?php if (abs($net) < 0.01): ?>
            <span class="text-gray-300 text-xs">—</span>
          <?php elseif ($net > 0): ?>
            <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-red-100 text-red-700 text-[10px] font-bold rounded-full">
              <i class="fas fa-arrow-up text-[8px]"></i><?php echo fmt_money($net); ?>
            </span>
          <?php else: ?>
            <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-green-100 text-green-700 text-[10px] font-bold rounded-full">
              <i class="fas fa-arrow-down text-[8px]"></i><?php echo fmt_money(abs($net)); ?>
            </span>
          <?php endif; ?>
        </td>
        <td class="px-3 py-1.5 text-center whitespace-nowrap">
          <div class="flex items-center justify-center gap-2">
            <a href="<?php echo url('cr/customer_ledger.php'); ?>?customer_id=<?php echo $m->id; ?>"
               class="text-blue-400 hover:text-blue-600 transition-colors" title="Ledger">
              <i class="fas fa-book text-xs"></i>
            </a>
            <?php if ($closing > 0): ?>
            <a href="<?php echo url('cr/customer_payment.php'); ?>?customer_id=<?php echo $m->id; ?>"
               class="text-green-500 hover:text-green-700 transition-colors" title="Collect Payment">
              <i class="fas fa-hand-holding-usd text-xs"></i>
            </a>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="bg-gray-800 text-white">
          <td colspan="2" class="px-3 py-2 text-xs font-bold text-gray-300">
            Totals — <?php echo count($movement); ?> active customers — <?php echo $mov_label; ?>
          </td>
          <td class="px-3 py-2 text-right text-xs font-bold whitespace-nowrap">
            <?php echo fmt_money($mov_tot_opening); ?>
          </td>
          <td class="px-3 py-2 text-right text-xs font-bold text-blue-300 whitespace-nowrap">
            <?php echo fmt_money($mov_tot_orders); ?>
          </td>
          <td class="px-3 py-2 text-right text-xs font-bold text-green-300 whitespace-nowrap">
            <?php echo fmt_money($mov_tot_payments); ?>
          </td>
          <td class="px-3 py-2 text-right text-xs font-bold whitespace-nowrap">
            <?php echo fmt_money($mov_tot_closing); ?>
          </td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php if (count($movement) >= 200): ?>
  <p class="text-center text-[10px] text-amber-600 py-2 border-t border-amber-100 bg-amber-50">
    Showing first 200 — use search to narrow
  </p>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     DUE + ADVANCE — side by side
════════════════════════════════════════════════════════════════════════════ -->
<div class="grid grid-cols-1 xl:grid-cols-2 gap-4">

  <!-- TABLE A: WITH DUE -->
  <div class="bg-white rounded-xl shadow-sm border border-red-100 overflow-hidden">
    <div class="px-3 py-2 bg-red-50 border-b border-red-100 flex items-center justify-between">
      <div class="flex items-center gap-1.5">
        <i class="fas fa-file-invoice-dollar text-red-500 text-[10px]"></i>
        <span class="text-xs font-bold text-red-800">Outstanding Due</span>
        <span class="text-[10px] text-red-400">(<?php echo count($customers_due); ?>)</span>
      </div>
      <span class="text-xs font-bold text-red-700"><?php echo fmt_money((float)($summary->total_due ?? 0)); ?></span>
    </div>

    <?php if (empty($customers_due)): ?>
    <div class="py-8 text-center">
      <i class="fas fa-check-circle text-green-400 text-2xl mb-1 block"></i>
      <p class="text-xs text-gray-400">All settled</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full">
        <thead class="bg-gray-50 border-b border-gray-100">
          <tr>
            <th class="px-2 py-1.5 text-[10px] font-semibold text-gray-400 uppercase text-left w-5">#</th>
            <th class="px-2 py-1.5 text-[10px] font-semibold text-gray-500 uppercase text-left">Customer</th>
            <th class="px-2 py-1.5 text-[10px] font-semibold text-red-500 uppercase text-right whitespace-nowrap">Due</th>
            <th class="px-2 py-1.5 text-[10px] font-semibold text-gray-400 uppercase text-center whitespace-nowrap">Limit%</th>
            <th class="px-2 py-1.5 text-[10px] font-semibold text-gray-400 uppercase text-center whitespace-nowrap">Orders</th>
            <th class="px-2 py-1.5 text-[10px] font-semibold text-gray-400 uppercase text-center whitespace-nowrap">Last Pay</th>
            <th class="px-2 py-1.5 text-[10px] font-semibold text-gray-400 uppercase text-center"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
        <?php
        $due_total = 0;
        foreach ($customers_due as $i => $c):
          $balance  = (float)$c->current_balance;
          $cl       = (float)$c->credit_limit;
          $util     = ($cl > 0) ? min(200, round($balance / $cl * 100)) : null;
          $over     = ($cl > 0 && $balance > $cl);
          $util_cls = $over ? 'text-red-600 font-bold' : ($util >= 80 ? 'text-orange-500 font-semibold' : 'text-gray-500');
          $days_pay = days_ago($c->last_payment_date ?? null);
          $due_total += $balance;
        ?>
        <tr class="hover:bg-red-50 transition-colors">
          <td class="px-2 py-1 text-[10px] text-gray-300 font-mono"><?php echo $i+1; ?></td>
          <td class="px-2 py-1">
            <a href="view.php?id=<?php echo $c->id; ?>"
               class="text-xs font-semibold text-gray-800 hover:text-primary-600 transition-colors truncate block max-w-[130px]">
              <?php echo htmlspecialchars($c->name); ?>
            </a>
          </td>
          <td class="px-2 py-1 text-right whitespace-nowrap">
            <span class="text-xs font-bold text-red-600"><?php echo fmt_money($balance); ?></span>
          </td>
          <td class="px-2 py-1 text-center whitespace-nowrap">
            <?php if ($util !== null): ?>
            <span class="text-[10px] <?php echo $util_cls; ?>"><?php echo $util; ?>%</span>
            <?php else: ?>
            <span class="text-gray-200 text-[10px]">—</span>
            <?php endif; ?>
          </td>
          <td class="px-2 py-1 text-center">
            <?php $oo = (int)$c->open_orders; ?>
            <?php if ($oo > 0): ?>
            <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-blue-100 text-blue-700 text-[10px] font-semibold rounded-full">
              <i class="fas fa-box text-[8px]"></i><?php echo $oo; ?>
            </span>
            <?php else: ?>
            <span class="text-gray-200 text-[10px]">—</span>
            <?php endif; ?>
          </td>
          <td class="px-2 py-1 text-center text-[10px]"><?php echo fmt_days($days_pay); ?></td>
          <td class="px-2 py-1 text-center whitespace-nowrap">
            <div class="flex items-center justify-center gap-1.5">
              <a href="<?php echo url('cr/customer_payment.php'); ?>?customer_id=<?php echo $c->id; ?>"
                 class="text-green-500 hover:text-green-700" title="Collect">
                <i class="fas fa-hand-holding-usd text-[11px]"></i>
              </a>
              <a href="<?php echo url('cr/customer_ledger.php'); ?>?customer_id=<?php echo $c->id; ?>"
                 class="text-blue-400 hover:text-blue-600" title="Ledger">
                <i class="fas fa-book text-[11px]"></i>
              </a>
              <a href="manage.php?id=<?php echo $c->id; ?>"
                 class="text-gray-300 hover:text-blue-500" title="Edit">
                <i class="fas fa-pencil-alt text-[11px]"></i>
              </a>
              <?php if (in_array($userRole, ['Superadmin','admin'])): ?>
              <form method="POST" class="inline"
                    onsubmit="return confirm('Delete <?php echo htmlspecialchars(addslashes($c->name)); ?>?');">
                <input type="hidden" name="delete_customer" value="1">
                <input type="hidden" name="delete_id" value="<?php echo $c->id; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <button type="submit" class="text-gray-200 hover:text-red-500" title="Delete">
                  <i class="fas fa-trash text-[11px]"></i>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="bg-red-50 border-t-2 border-red-100">
            <td colspan="2" class="px-2 py-1.5 text-[10px] font-bold text-gray-500 text-right">
              Total (<?php echo count($customers_due); ?>)
            </td>
            <td class="px-2 py-1.5 text-right text-xs font-bold text-red-700"><?php echo fmt_money($due_total); ?></td>
            <td colspan="4"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php if (count($customers_due) >= 500): ?>
    <p class="text-center text-[10px] text-amber-600 py-1.5 border-t border-amber-100 bg-amber-50">
      Showing first 500 — use search to narrow
    </p>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- TABLE B: IN ADVANCE -->
  <div class="bg-white rounded-xl shadow-sm border border-green-100 overflow-hidden">
    <div class="px-3 py-2 bg-green-50 border-b border-green-100 flex items-center justify-between">
      <div class="flex items-center gap-1.5">
        <i class="fas fa-hand-holding-usd text-green-500 text-[10px]"></i>
        <span class="text-xs font-bold text-green-800">In Advance</span>
        <span class="text-[10px] text-green-400">(<?php echo count($customers_advance); ?>)</span>
      </div>
      <span class="text-xs font-bold text-green-700"><?php echo fmt_money((float)($summary->total_advance ?? 0)); ?></span>
    </div>

    <?php if (empty($customers_advance)): ?>
    <p class="text-center text-xs text-gray-400 py-8">No customers with advance balance</p>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full">
        <thead class="bg-gray-50 border-b border-gray-100">
          <tr>
            <th class="px-2 py-1.5 text-[10px] font-semibold text-gray-400 uppercase text-left w-5">#</th>
            <th class="px-2 py-1.5 text-[10px] font-semibold text-gray-500 uppercase text-left">Customer</th>
            <th class="px-2 py-1.5 text-[10px] font-semibold text-green-600 uppercase text-right whitespace-nowrap">Advance</th>
            <th class="px-2 py-1.5 text-[10px] font-semibold text-gray-400 uppercase text-center whitespace-nowrap">Last Order</th>
            <th class="px-2 py-1.5 text-[10px] font-semibold text-gray-400 uppercase text-center whitespace-nowrap">Last Pay</th>
            <th class="px-2 py-1.5 text-[10px] font-semibold text-gray-400 uppercase text-center"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
        <?php
        $adv_total = 0;
        foreach ($customers_advance as $i => $c):
          $adv_bal  = abs((float)$c->current_balance);
          $days_ord = days_ago($c->last_order_date  ?? null);
          $days_pay = days_ago($c->last_payment_date ?? null);
          $adv_total += $adv_bal;
        ?>
        <tr class="hover:bg-green-50 transition-colors">
          <td class="px-2 py-1 text-[10px] text-gray-300 font-mono"><?php echo $i+1; ?></td>
          <td class="px-2 py-1">
            <a href="view.php?id=<?php echo $c->id; ?>"
               class="text-xs font-semibold text-gray-800 hover:text-primary-600 transition-colors truncate block max-w-[130px]">
              <?php echo htmlspecialchars($c->name); ?>
            </a>
          </td>
          <td class="px-2 py-1 text-right whitespace-nowrap">
            <span class="text-xs font-bold text-green-600"><?php echo fmt_money($adv_bal); ?></span>
          </td>
          <td class="px-2 py-1 text-center text-[10px]"><?php echo fmt_days($days_ord); ?></td>
          <td class="px-2 py-1 text-center text-[10px]"><?php echo fmt_days($days_pay); ?></td>
          <td class="px-2 py-1 text-center whitespace-nowrap">
            <div class="flex items-center justify-center gap-1.5">
              <a href="<?php echo url('cr/create_order.php'); ?>?customer_id=<?php echo $c->id; ?>"
                 class="text-blue-400 hover:text-blue-600" title="New Order">
                <i class="fas fa-plus-circle text-[11px]"></i>
              </a>
              <a href="<?php echo url('cr/customer_ledger.php'); ?>?customer_id=<?php echo $c->id; ?>"
                 class="text-blue-400 hover:text-blue-600" title="Ledger">
                <i class="fas fa-book text-[11px]"></i>
              </a>
              <a href="manage.php?id=<?php echo $c->id; ?>"
                 class="text-gray-300 hover:text-blue-500" title="Edit">
                <i class="fas fa-pencil-alt text-[11px]"></i>
              </a>
              <?php if (in_array($userRole, ['Superadmin','admin'])): ?>
              <form method="POST" class="inline"
                    onsubmit="return confirm('Delete <?php echo htmlspecialchars(addslashes($c->name)); ?>?');">
                <input type="hidden" name="delete_customer" value="1">
                <input type="hidden" name="delete_id" value="<?php echo $c->id; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <button type="submit" class="text-gray-200 hover:text-red-500" title="Delete">
                  <i class="fas fa-trash text-[11px]"></i>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="bg-green-50 border-t-2 border-green-100">
            <td colspan="2" class="px-2 py-1.5 text-[10px] font-bold text-gray-500 text-right">
              Total (<?php echo count($customers_advance); ?>)
            </td>
            <td class="px-2 py-1.5 text-right text-xs font-bold text-green-700"><?php echo fmt_money($adv_total); ?></td>
            <td colspan="3"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php if (count($customers_advance) >= 500): ?>
    <p class="text-center text-[10px] text-amber-600 py-1.5 border-t border-amber-100 bg-amber-50">
      Showing first 500 — use search to narrow
    </p>
    <?php endif; ?>
    <?php endif; ?>
  </div>

</div><!-- /grid -->

</div><!-- /wrapper -->

<?php require_once '../templates/footer.php'; ?>
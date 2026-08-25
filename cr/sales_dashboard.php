<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$pageTitle   = 'Credit Sales Dashboard';

$today = date('Y-m-d');

// ── Date range (defaults to today) ────────────────────────────────────────
$date_from = !empty($_GET['date_from']) ? $_GET['date_from'] : $today;
$date_to   = !empty($_GET['date_to'])   ? $_GET['date_to']   : $today;

// Clamp: from ≤ to
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];

$is_single_day  = ($date_from === $date_to);
$is_today       = ($date_from === $today && $date_to === $today);

function range_label(string $from, string $to): string {
    if ($from === $to) return date('d M Y', strtotime($from));
    return date('d M Y', strtotime($from)) . ' — ' . date('d M Y', strtotime($to));
}

/* ── 1. KPI — orders in range ─────────────────────────────────────────── */
$kpi_orders = $db->query(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS revenue
     FROM credit_orders
     WHERE DATE(order_date) BETWEEN ? AND ?
       AND status NOT IN ('cancelled','rejected','draft')",
    [$date_from, $date_to]
)->first();

/* ── 2. KPI — payments in range ───────────────────────────────────────── */
$kpi_payments = $db->query(
    "SELECT COALESCE(SUM(amount),0) AS total,
            COALESCE(SUM(CASE WHEN payment_type='advance' THEN amount ELSE 0 END),0) AS advance,
            COALESCE(SUM(CASE WHEN payment_type!='advance' THEN amount ELSE 0 END),0) AS due_collected
     FROM customer_payments
     WHERE DATE(payment_date) BETWEEN ? AND ?",
    [$date_from, $date_to]
)->first();

/* ── 3. KPI — outstanding (always current snapshot) ──────────────────── */
$kpi_outstanding = $db->query(
    "SELECT COALESCE(SUM(co.balance_due),0) AS due
     FROM credit_orders co
     INNER JOIN customers c ON co.customer_id = c.id
     WHERE co.balance_due > 0.01 AND co.status NOT IN ('cancelled','rejected','draft')"
)->first();

/* ── 4. KPI — customer counts (always current snapshot) ─────────────── */
$kpi_customers = $db->query(
    "SELECT COUNT(*) AS active,
            SUM(CASE WHEN current_balance > credit_limit AND credit_limit > 0 THEN 1 ELSE 0 END) AS over_limit,
            SUM(CASE WHEN current_balance < 0 THEN 1 ELSE 0 END) AS in_advance
     FROM customers WHERE status='active' AND customer_type='Credit'"
)->first();

/* ── 5. ORDER PIPELINE (always current, not date-filtered) ──────────── */
$pipeline = $db->query(
    "SELECT co.status, COUNT(*) AS cnt, COALESCE(SUM(co.total_amount),0) AS total
     FROM credit_orders co
     INNER JOIN customers c ON co.customer_id = c.id
     WHERE co.status NOT IN ('cancelled','rejected','draft')
     GROUP BY co.status"
)->results();

/* ── 6. BRANCH PERFORMANCE (date range) ─────────────────────────────── */
$branch_stats = $db->query(
    "SELECT COALESCE(b.name,'Unassigned') AS branch_name,
            COUNT(co.id) AS orders,
            COALESCE(SUM(co.total_amount),0) AS revenue,
            COALESCE(SUM(co.amount_paid),0) AS collected,
            COALESCE(SUM(co.balance_due),0) AS outstanding
     FROM credit_orders co
     INNER JOIN customers c ON co.customer_id = c.id
     LEFT JOIN branches b ON co.assigned_branch_id = b.id
     WHERE DATE(co.order_date) BETWEEN ? AND ?
       AND co.status NOT IN ('cancelled','rejected','draft')
     GROUP BY b.id ORDER BY revenue DESC",
    [$date_from, $date_to]
)->results();

/* ── 7. PAYMENT BY ACCOUNT & METHOD (date range) ────────────────────── */
$payment_by_account = $db->query(
    "SELECT COALESCE(ba.account_name,'Cash') AS account,
            cp.payment_method,
            COALESCE(SUM(cp.amount),0) AS total
     FROM customer_payments cp
     LEFT JOIN bank_accounts ba ON cp.bank_account_id = ba.id
     WHERE DATE(cp.payment_date) BETWEEN ? AND ?
     GROUP BY ba.id, cp.payment_method
     ORDER BY total DESC",
    [$date_from, $date_to]
)->results();

$payment_totals_method = $db->query(
    "SELECT payment_method, COALESCE(SUM(amount),0) AS total
     FROM customer_payments
     WHERE DATE(payment_date) BETWEEN ? AND ?
     GROUP BY payment_method ORDER BY total DESC",
    [$date_from, $date_to]
)->results();

/* ── 8. TOP PRODUCTS (date range) ───────────────────────────────────── */
$top_products = $db->query(
    "SELECT p.base_name AS product_name,
            COALESCE(SUM(coi.quantity),0) AS total_qty,
            COALESCE(SUM(coi.quantity * coi.unit_price),0) AS revenue
     FROM credit_order_items coi
     JOIN credit_orders co ON coi.order_id = co.id
     JOIN products p ON coi.product_id = p.id
     WHERE DATE(co.order_date) BETWEEN ? AND ?
       AND co.status NOT IN ('cancelled','rejected','draft')
     GROUP BY p.id ORDER BY total_qty DESC LIMIT 8",
    [$date_from, $date_to]
)->results();

/* ── 9. CUSTOMER CREDIT HEALTH (always current snapshot) ─────────────── */
$credit_health = $db->query(
    "SELECT id, name, credit_limit, current_balance,
            CASE WHEN credit_limit > 0 THEN LEAST((current_balance / credit_limit)*100, 200) ELSE 0 END AS usage_pct
     FROM customers
     WHERE status='active' AND customer_type='Credit' AND current_balance > 0
     ORDER BY current_balance DESC LIMIT 15"
)->results();

$over_limit_list = $db->query(
    "SELECT id, name, credit_limit, current_balance,
            (current_balance - credit_limit) AS excess
     FROM customers
     WHERE status='active' AND customer_type='Credit'
       AND credit_limit > 0 AND current_balance > credit_limit
     ORDER BY excess DESC LIMIT 8"
)->results();

/* ── 10. RECENT ORDERS (date range) ─────────────────────────────────── */
$recent_orders = $db->query(
    "SELECT co.id, co.order_number, c.name AS customer_name,
            COALESCE(b.name,'—') AS branch_name,
            co.total_amount, co.balance_due, co.status, co.order_date
     FROM credit_orders co
     JOIN customers c ON co.customer_id = c.id
     LEFT JOIN branches b ON co.assigned_branch_id = b.id
     WHERE DATE(co.order_date) BETWEEN ? AND ?
       AND co.status NOT IN ('draft')
     ORDER BY co.id DESC LIMIT 15",
    [$date_from, $date_to]
)->results();

/* ── 11. RECENT PAYMENTS (date range) ───────────────────────────────── */
$recent_payments = $db->query(
    "SELECT cp.id, cp.payment_number, c.name AS customer_name,
            cp.amount, cp.payment_method, cp.payment_type,
            COALESCE(ba.account_name,'Cash') AS account_name,
            cp.payment_date
     FROM customer_payments cp
     JOIN customers c ON cp.customer_id = c.id
     LEFT JOIN bank_accounts ba ON cp.bank_account_id = ba.id
     WHERE DATE(cp.payment_date) BETWEEN ? AND ?
     ORDER BY cp.id DESC LIMIT 15",
    [$date_from, $date_to]
)->results();

/* ── Helpers ─────────────────────────────────────────────────────────── */
$status_map = [
    'pending_approval' => ['label'=>'Pending Approval','url'=>'cr/credit_order_approval.php', 'bg'=>'bg-yellow-100','text'=>'text-yellow-800','dot'=>'#D97706','border'=>'border-yellow-300'],
    'escalated'        => ['label'=>'Escalated',       'url'=>'cr/credit_order_approval.php', 'bg'=>'bg-red-100',   'text'=>'text-red-800',   'dot'=>'#DC2626','border'=>'border-red-300'],
    'approved'         => ['label'=>'Approved',        'url'=>'cr/all_sales.php',             'bg'=>'bg-blue-100',  'text'=>'text-blue-800',  'dot'=>'#2563EB','border'=>'border-blue-300'],
    'in_production'    => ['label'=>'In Production',   'url'=>'cr/credit_production.php',     'bg'=>'bg-purple-100','text'=>'text-purple-800','dot'=>'#7C3AED','border'=>'border-purple-300'],
    'ready_to_ship'    => ['label'=>'Ready to Ship',   'url'=>'cr/credit_dispatch.php',       'bg'=>'bg-cyan-100',  'text'=>'text-cyan-800',  'dot'=>'#0E7490','border'=>'border-cyan-300'],
    'shipped'          => ['label'=>'Shipped',         'url'=>'cr/credit_dispatch.php',       'bg'=>'bg-indigo-100','text'=>'text-indigo-800','dot'=>'#4338CA','border'=>'border-indigo-300'],
    'delivered'        => ['label'=>'Delivered',       'url'=>'cr/all_sales.php',             'bg'=>'bg-green-100', 'text'=>'text-green-800', 'dot'=>'#059669','border'=>'border-green-300'],
];
function fmt($n) { return '৳' . number_format((float)$n, 0); }

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

  <!-- ── Page Header ─────────────────────────────────────────────────── -->
  <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Credit Sales Dashboard</h1>
      <p class="text-sm text-gray-500 mt-0.5">
        <i class="fas fa-calendar-alt mr-1 text-blue-500"></i>
        <?php echo range_label($date_from, $date_to); ?>
        <?php if ($is_today): ?>
          <span class="ml-1.5 text-xs bg-blue-100 text-blue-700 font-semibold px-2 py-0.5 rounded-full">Live</span>
        <?php endif; ?>
      </p>
    </div>
    <div class="flex flex-wrap gap-2">
      <a href="<?php echo url('cr/create_order.php'); ?>"
         class="inline-flex items-center gap-1.5 px-3 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors">
        <i class="fas fa-plus text-xs"></i> New Order
      </a>
      <a href="<?php echo url('cr/customer_payment.php'); ?>"
         class="inline-flex items-center gap-1.5 px-3 py-2 bg-green-600 text-white text-sm font-semibold rounded-lg hover:bg-green-700 transition-colors">
        <i class="fas fa-money-bill-wave text-xs"></i> Collect Payment
      </a>
      <a href="<?php echo url('cr/ageing_report.php'); ?>"
         class="inline-flex items-center gap-1.5 px-3 py-2 border border-gray-300 text-gray-700 text-sm font-semibold rounded-lg hover:bg-gray-50 transition-colors">
        <i class="fas fa-clock text-red-500 text-xs"></i> Ageing
      </a>
    </div>
  </div>

  <!-- ── Date Range Filter ────────────────────────────────────────────── -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 px-4 py-3 mb-5">
    <form method="GET" class="flex flex-wrap items-center gap-3">
      <!-- Quick presets -->
      <div class="flex flex-wrap gap-1.5">
        <?php
        $presets = [
            'today'      => ['Today',       $today,                                          $today],
            'yesterday'  => ['Yesterday',   date('Y-m-d', strtotime('-1 day')),             date('Y-m-d', strtotime('-1 day'))],
            'this_week'  => ['This Week',   date('Y-m-d', strtotime('monday this week')),   $today],
            'this_month' => ['This Month',  date('Y-m-01'),                                  $today],
            'last30'     => ['Last 30d',    date('Y-m-d', strtotime('-29 days')),            $today],
        ];
        foreach ($presets as $key => [$label, $pf, $pt]):
            $active = ($date_from === $pf && $date_to === $pt);
        ?>
        <a href="?date_from=<?php echo $pf; ?>&date_to=<?php echo $pt; ?>"
           class="px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors cursor-pointer
                  <?php echo $active
                      ? 'bg-blue-600 text-white border-blue-600'
                      : 'border-gray-200 text-gray-600 hover:bg-gray-50 hover:border-gray-300'; ?>">
          <?php echo $label; ?>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Divider -->
      <div class="w-px h-6 bg-gray-200 hidden sm:block"></div>

      <!-- Custom date inputs -->
      <div class="flex items-center gap-2">
        <input type="date" name="date_from" value="<?php echo $date_from; ?>"
               class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:border-blue-400 focus:ring-1 focus:ring-blue-200">
        <span class="text-xs text-gray-400 font-medium">to</span>
        <input type="date" name="date_to" value="<?php echo $date_to; ?>"
               class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:border-blue-400 focus:ring-1 focus:ring-blue-200">
        <button type="submit"
                class="px-3 py-1.5 bg-blue-600 text-white text-xs font-semibold rounded-lg hover:bg-blue-700 transition-colors cursor-pointer">
          Apply
        </button>
      </div>
    </form>
  </div>

  <!-- ── KPI Cards ────────────────────────────────────────────────────── -->
  <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-5">

    <!-- Orders Placed -->
    <a href="<?php echo url('cr/all_sales.php'); ?>"
       class="bg-blue-600 rounded-xl shadow-md p-4 text-white hover:bg-blue-700 transition-colors cursor-pointer block">
      <div class="flex items-center justify-between mb-2">
        <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center">
          <i class="fas fa-receipt text-white text-xs"></i>
        </div>
        <span class="text-[10px] font-semibold bg-white/20 px-2 py-0.5 rounded-full">
          <?php echo $is_today ? 'Today' : ($is_single_day ? date('d M', strtotime($date_from)) : 'Range'); ?>
        </span>
      </div>
      <p class="text-2xl font-bold mt-1"><?php echo (int)($kpi_orders->cnt ?? 0); ?></p>
      <p class="text-xs opacity-80 mt-0.5">Orders Placed</p>
      <p class="text-xs font-semibold mt-1 opacity-90"><?php echo fmt($kpi_orders->revenue ?? 0); ?> invoiced</p>
    </a>

    <!-- Revenue (range) -->
    <a href="<?php echo url('cr/all_sales.php'); ?>"
       class="bg-indigo-600 rounded-xl shadow-md p-4 text-white hover:bg-indigo-700 transition-colors cursor-pointer block">
      <div class="flex items-center justify-between mb-2">
        <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center">
          <i class="fas fa-chart-line text-white text-xs"></i>
        </div>
        <span class="text-[10px] font-semibold bg-white/20 px-2 py-0.5 rounded-full">Revenue</span>
      </div>
      <p class="text-2xl font-bold mt-1"><?php
        $rev = (float)($kpi_orders->revenue ?? 0);
        echo $rev >= 1000 ? '৳'.number_format($rev/1000, 1).'K' : fmt($rev);
      ?></p>
      <p class="text-xs opacity-80 mt-0.5">Total Invoiced</p>
      <p class="text-xs font-semibold mt-1 opacity-90">
        <?php
          $total_payments = (float)($kpi_payments->total ?? 0);
          echo fmt($total_payments); ?> collected
      </p>
    </a>

    <!-- Payments Collected -->
    <a href="<?php echo url('cr/payment_history.php'); ?>?date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"
       class="bg-green-600 rounded-xl shadow-md p-4 text-white hover:bg-green-700 transition-colors cursor-pointer block">
      <div class="flex items-center justify-between mb-2">
        <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center">
          <i class="fas fa-coins text-white text-xs"></i>
        </div>
        <span class="text-[10px] font-semibold bg-white/20 px-2 py-0.5 rounded-full">Payments</span>
      </div>
      <p class="text-2xl font-bold mt-1"><?php echo fmt($kpi_payments->total ?? 0); ?></p>
      <p class="text-xs opacity-80 mt-0.5">Collected</p>
      <p class="text-xs font-semibold mt-1 opacity-90">
        Adv <?php echo fmt($kpi_payments->advance ?? 0); ?> &nbsp;|&nbsp; Due <?php echo fmt($kpi_payments->due_collected ?? 0); ?>
      </p>
    </a>

    <!-- Total Outstanding Due -->
    <a href="<?php echo url('cr/ageing_report.php'); ?>"
       class="bg-red-600 rounded-xl shadow-md p-4 text-white hover:bg-red-700 transition-colors cursor-pointer block">
      <div class="flex items-center justify-between mb-2">
        <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center">
          <i class="fas fa-exclamation-circle text-white text-xs"></i>
        </div>
        <span class="text-[10px] font-semibold bg-white/20 px-2 py-0.5 rounded-full">All-time</span>
      </div>
      <p class="text-2xl font-bold mt-1"><?php echo fmt($kpi_outstanding->due ?? 0); ?></p>
      <p class="text-xs opacity-80 mt-0.5">Outstanding Due</p>
      <p class="text-xs font-semibold mt-1 opacity-90">Click for ageing</p>
    </a>

    <!-- Active Customers -->
    <a href="<?php echo url('cr/customer_credit_management.php'); ?>"
       class="bg-orange-500 rounded-xl shadow-md p-4 text-white hover:bg-orange-600 transition-colors cursor-pointer block">
      <div class="flex items-center justify-between mb-2">
        <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center">
          <i class="fas fa-users text-white text-xs"></i>
        </div>
        <span class="text-[10px] font-semibold bg-white/20 px-2 py-0.5 rounded-full">Snapshot</span>
      </div>
      <p class="text-2xl font-bold mt-1"><?php echo (int)($kpi_customers->active ?? 0); ?></p>
      <p class="text-xs opacity-80 mt-0.5">Active Customers</p>
      <p class="text-xs font-semibold mt-1 opacity-90"><?php echo (int)($kpi_customers->in_advance ?? 0); ?> in advance</p>
    </a>

    <!-- Over Credit Limit -->
    <a href="<?php echo url('cr/customer_credit_management.php'); ?>"
       class="bg-rose-600 rounded-xl shadow-md p-4 text-white hover:bg-rose-700 transition-colors cursor-pointer block">
      <div class="flex items-center justify-between mb-2">
        <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center">
          <i class="fas fa-ban text-white text-xs"></i>
        </div>
        <span class="text-[10px] font-semibold bg-white/20 px-2 py-0.5 rounded-full">Snapshot</span>
      </div>
      <p class="text-2xl font-bold mt-1"><?php echo (int)($kpi_customers->over_limit ?? 0); ?></p>
      <p class="text-xs opacity-80 mt-0.5">Over Credit Limit</p>
      <p class="text-xs font-semibold mt-1 opacity-90">Click to manage</p>
    </a>

  </div>

  <!-- ── Order Pipeline (compact, always current) ─────────────────────── -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 px-5 py-4 mb-5">
    <div class="flex items-center justify-between mb-3">
      <div>
        <h2 class="text-sm font-bold text-gray-900">Order Pipeline</h2>
        <p class="text-xs text-gray-400 mt-0.5">Current active orders by status — click any status to open the list</p>
      </div>
      <a href="<?php echo url('cr/all_sales.php'); ?>" class="text-xs font-semibold text-blue-600 hover:text-blue-800">
        All Orders <i class="fas fa-arrow-right ml-0.5 text-[10px]"></i>
      </a>
    </div>
    <?php
    $pipeline_map = [];
    foreach ($pipeline as $p) $pipeline_map[$p->status] = $p;
    $pipeline_statuses = ['pending_approval','escalated','approved','in_production','ready_to_ship','shipped','delivered'];
    ?>
    <div class="flex flex-wrap gap-2">
      <?php foreach ($pipeline_statuses as $st):
        $row = $pipeline_map[$st] ?? null;
        $cnt = $row ? (int)$row->cnt : 0;
        $tot = $row ? (float)$row->total : 0;
        $sm  = $status_map[$st];
      ?>
      <a href="<?php echo url($sm['url']); ?>"
         class="flex items-center gap-2 px-3 py-2 rounded-lg border <?php echo $sm['border']; ?> <?php echo $sm['bg']; ?>
                hover:opacity-80 transition-opacity cursor-pointer group min-w-[140px] flex-1">
        <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:<?php echo $sm['dot']; ?>"></span>
        <div class="min-w-0 flex-1">
          <p class="text-xs font-semibold <?php echo $sm['text']; ?> truncate"><?php echo $sm['label']; ?></p>
          <p class="text-xs text-gray-500 mt-0.5"><?php echo fmt($tot); ?></p>
        </div>
        <span class="text-lg font-bold <?php echo $sm['text']; ?> flex-shrink-0"><?php echo $cnt; ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── Branch + Payments ─────────────────────────────────────────────── -->
  <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 mb-5">

    <!-- Branch Performance -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
        <div>
          <h2 class="text-sm font-bold text-gray-900">Branch Performance</h2>
          <p class="text-xs text-gray-400"><?php echo range_label($date_from, $date_to); ?></p>
        </div>
      </div>
      <div class="divide-y divide-gray-50">
        <?php if (empty($branch_stats)): ?>
        <p class="text-sm text-center text-gray-400 py-8">No orders in this period</p>
        <?php else:
          $branch_colors = ['blue','green','purple','orange','cyan','red'];
          $max_rev = max(array_map(fn($b)=>$b->revenue, $branch_stats)) ?: 1;
          foreach ($branch_stats as $i => $br):
            $c = $branch_colors[$i % count($branch_colors)];
            $pct = round($br->revenue / $max_rev * 100);
        ?>
        <div class="px-5 py-3">
          <div class="flex items-center justify-between mb-1.5">
            <div class="flex items-center gap-2">
              <span class="w-2 h-2 rounded-full bg-<?php echo $c; ?>-500 flex-shrink-0"></span>
              <span class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($br->branch_name); ?></span>
              <span class="text-xs text-gray-400"><?php echo (int)$br->orders; ?> orders</span>
            </div>
            <span class="text-sm font-bold text-gray-900"><?php echo fmt($br->revenue); ?></span>
          </div>
          <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden mb-1.5">
            <div class="h-full rounded-full bg-<?php echo $c; ?>-500 transition-all" style="width:<?php echo $pct; ?>%"></div>
          </div>
          <div class="flex justify-between text-xs text-gray-500">
            <span class="text-green-600 font-medium">Collected <?php echo fmt($br->collected); ?></span>
            <span class="text-red-500 font-medium">Due <?php echo fmt($br->outstanding); ?></span>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Payments Breakdown -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
        <div>
          <h2 class="text-sm font-bold text-gray-900">Payment Collections</h2>
          <p class="text-xs text-gray-400"><?php echo range_label($date_from, $date_to); ?> — by method &amp; account</p>
        </div>
        <a href="<?php echo url('cr/payment_history.php'); ?>?date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"
           class="text-xs font-semibold text-blue-600 hover:text-blue-800">
          Full History <i class="fas fa-arrow-right ml-0.5 text-[10px]"></i>
        </a>
      </div>

      <!-- By method -->
      <div class="px-5 py-3 border-b border-gray-100">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">By Method</p>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
          <?php
          $method_colors = ['Cash'=>'green','Bank Transfer'=>'blue','Cheque'=>'amber','Mobile Banking'=>'purple'];
          $total_all = array_sum(array_map(fn($m)=>$m->total, $payment_totals_method)) ?: 1;
          foreach ($payment_totals_method as $m):
            $pct = round($m->total/$total_all*100);
            $c   = $method_colors[$m->payment_method] ?? 'gray';
          ?>
          <div class="border border-<?php echo $c; ?>-200 bg-<?php echo $c; ?>-50 rounded-lg p-2.5 text-center">
            <p class="text-[10px] text-gray-500 truncate"><?php echo htmlspecialchars($m->payment_method); ?></p>
            <p class="text-sm font-bold text-<?php echo $c; ?>-700 mt-0.5"><?php echo fmt($m->total); ?></p>
            <p class="text-[10px] text-gray-400"><?php echo $pct; ?>%</p>
          </div>
          <?php endforeach; ?>
          <?php if (empty($payment_totals_method)): ?>
          <p class="col-span-4 text-sm text-gray-400 text-center py-3">No payments this period</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- By account -->
      <div class="px-5 py-3">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">By Account</p>
        <div class="space-y-1.5 max-h-44 overflow-y-auto">
          <?php foreach ($payment_by_account as $pa):
            $is_cash = ($pa->payment_method === 'Cash');
          ?>
          <div class="flex items-center justify-between py-1">
            <div class="flex items-center gap-2 min-w-0">
              <i class="fas <?php echo $is_cash ? 'fa-money-bill-wave text-green-500' : 'fa-university text-blue-500'; ?> w-4 text-center text-xs flex-shrink-0"></i>
              <div class="min-w-0">
                <p class="text-xs text-gray-800 font-medium truncate"><?php echo htmlspecialchars($pa->account ?? 'Cash'); ?></p>
                <p class="text-[10px] text-gray-400"><?php echo htmlspecialchars($pa->payment_method); ?></p>
              </div>
            </div>
            <p class="text-sm font-bold text-green-600 flex-shrink-0 ml-3"><?php echo fmt($pa->total); ?></p>
          </div>
          <?php endforeach; ?>
          <?php if (empty($payment_by_account)): ?>
          <p class="text-sm text-gray-400 text-center py-3">No payment data</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>

  <!-- ── Products + Credit Health ─────────────────────────────────────── -->
  <div class="grid grid-cols-1 xl:grid-cols-5 gap-5 mb-5">

    <!-- Top Products -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden xl:col-span-2">
      <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
        <h2 class="text-sm font-bold text-gray-900">Top Products</h2>
        <p class="text-xs text-gray-400"><?php echo range_label($date_from, $date_to); ?> — by quantity</p>
      </div>
      <?php if (empty($top_products)): ?>
      <p class="text-sm text-gray-400 text-center py-10">No product data this period</p>
      <?php else:
        $max_qty = max(array_map(fn($p)=>$p->total_qty, $top_products)) ?: 1;
        $prod_colors = ['blue','green','purple','orange','cyan','red','pink','lime'];
      ?>
      <div class="px-5 py-4 space-y-3">
        <?php foreach ($top_products as $i => $prod):
          $c = $prod_colors[$i % count($prod_colors)];
          $pct = round($prod->total_qty / $max_qty * 100);
        ?>
        <div>
          <div class="flex items-center justify-between mb-1">
            <span class="text-xs text-gray-700 font-medium truncate" style="max-width:55%"><?php echo htmlspecialchars($prod->product_name); ?></span>
            <div class="flex gap-3 text-xs text-right flex-shrink-0">
              <span class="font-bold text-<?php echo $c; ?>-600"><?php echo number_format($prod->total_qty, 0); ?> kg</span>
              <span class="text-gray-400"><?php echo fmt($prod->revenue); ?></span>
            </div>
          </div>
          <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
            <div class="h-full rounded-full bg-<?php echo $c; ?>-500" style="width:<?php echo $pct; ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Customer Credit Health -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden xl:col-span-3">
      <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
        <div>
          <h2 class="text-sm font-bold text-gray-900">Customer Credit Utilisation</h2>
          <p class="text-xs text-gray-400">Current snapshot — top by balance</p>
        </div>
        <a href="<?php echo url('cr/customer_credit_management.php'); ?>"
           class="text-xs font-semibold text-blue-600 hover:text-blue-800">
          Manage <i class="fas fa-arrow-right ml-0.5 text-[10px]"></i>
        </a>
      </div>

      <?php if (!empty($over_limit_list)): ?>
      <div class="mx-5 mt-3 mb-1 border border-red-200 bg-red-50 rounded-lg p-2.5 flex items-start gap-2">
        <i class="fas fa-exclamation-triangle text-red-500 mt-0.5 flex-shrink-0 text-xs"></i>
        <div class="flex-1 min-w-0">
          <p class="text-xs font-semibold text-red-700 mb-1.5">
            <?php echo count($over_limit_list); ?> customer<?php echo count($over_limit_list)>1?'s':''; ?> over credit limit
          </p>
          <div class="flex flex-wrap gap-1">
            <?php foreach ($over_limit_list as $ol): ?>
            <a href="<?php echo url('cr/customer_ledger.php'); ?>?customer_id=<?php echo $ol->id; ?>"
               class="inline-flex items-center gap-1 px-2 py-0.5 bg-red-100 border border-red-200 text-red-700 text-[10px] font-semibold rounded-full hover:bg-red-200 cursor-pointer transition-colors">
              <?php echo htmlspecialchars($ol->name); ?> <span>+<?php echo fmt($ol->excess); ?></span>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="overflow-x-auto">
        <table class="min-w-full">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
              <th class="px-4 py-2 text-left text-[10px] font-semibold text-gray-500 uppercase">Customer</th>
              <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-500 uppercase whitespace-nowrap">Balance</th>
              <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-500 uppercase whitespace-nowrap">Limit</th>
              <th class="px-3 py-2 text-[10px] font-semibold text-gray-500 uppercase" style="min-width:100px">Usage</th>
              <th class="px-3 py-2 text-center text-[10px] font-semibold text-gray-500 uppercase">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($credit_health as $ch):
              $pct     = (float)$ch->usage_pct;
              if     ($pct >= 100) { $bar = 'bg-red-500';    $chip = '<span class="px-2 py-0.5 bg-red-100 text-red-700 text-[10px] font-semibold rounded-full">Over</span>'; }
              elseif ($pct >= 80)  { $bar = 'bg-orange-400'; $chip = '<span class="px-2 py-0.5 bg-orange-100 text-orange-700 text-[10px] font-semibold rounded-full">Near</span>'; }
              else                 { $bar = 'bg-green-500';  $chip = '<span class="px-2 py-0.5 bg-green-100 text-green-700 text-[10px] font-semibold rounded-full">OK</span>'; }
            ?>
            <tr class="hover:bg-gray-50 transition-colors cursor-pointer"
                onclick="window.location='<?php echo url('cr/customer_ledger.php'); ?>?customer_id=<?php echo $ch->id; ?>'">
              <td class="px-4 py-2.5 text-xs font-medium text-gray-900"><?php echo htmlspecialchars($ch->name); ?></td>
              <td class="px-3 py-2.5 text-xs font-bold text-red-600 text-right whitespace-nowrap"><?php echo fmt($ch->current_balance); ?></td>
              <td class="px-3 py-2.5 text-xs text-gray-500 text-right whitespace-nowrap"><?php echo fmt($ch->credit_limit); ?></td>
              <td class="px-3 py-2.5">
                <div class="flex items-center gap-1.5">
                  <div class="flex-1 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full rounded-full <?php echo $bar; ?>" style="width:<?php echo min($pct,100); ?>%"></div>
                  </div>
                  <span class="text-[10px] font-semibold text-gray-500 w-7 text-right"><?php echo round($pct); ?>%</span>
                </div>
              </td>
              <td class="px-3 py-2.5 text-center"><?php echo $chip; ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($credit_health)): ?>
            <tr><td colspan="5" class="text-center text-sm text-gray-400 py-8">No customer data</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <!-- ── Recent Orders + Payments ─────────────────────────────────────── -->
  <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">

    <!-- Recent Orders -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
        <div>
          <h2 class="text-sm font-bold text-gray-900">Orders</h2>
          <p class="text-xs text-gray-400"><?php echo range_label($date_from, $date_to); ?></p>
        </div>
        <a href="<?php echo url('cr/all_sales.php'); ?>" class="text-xs font-semibold text-blue-600 hover:text-blue-800">
          All Orders <i class="fas fa-arrow-right ml-0.5 text-[10px]"></i>
        </a>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
              <th class="px-4 py-2 text-left text-[10px] font-semibold text-gray-500 uppercase">Order #</th>
              <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-500 uppercase">Customer</th>
              <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-500 uppercase">Amount</th>
              <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-500 uppercase">Due</th>
              <th class="px-3 py-2 text-center text-[10px] font-semibold text-gray-500 uppercase">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($recent_orders as $ord):
              $sm = $status_map[$ord->status] ?? ['label'=>$ord->status,'bg'=>'bg-gray-100','text'=>'text-gray-700','dot'=>'#6B7280'];
            ?>
            <tr class="hover:bg-gray-50 cursor-pointer transition-colors"
                onclick="window.location='<?php echo url('cr/credit_order_view.php'); ?>?id=<?php echo $ord->id; ?>'">
              <td class="px-4 py-2.5 text-xs font-mono text-blue-600 whitespace-nowrap"><?php echo htmlspecialchars($ord->order_number); ?></td>
              <td class="px-3 py-2.5 text-xs font-medium text-gray-900 max-w-[120px] truncate"><?php echo htmlspecialchars($ord->customer_name); ?></td>
              <td class="px-3 py-2.5 text-xs font-bold text-gray-900 text-right whitespace-nowrap"><?php echo fmt($ord->total_amount); ?></td>
              <td class="px-3 py-2.5 text-xs text-right whitespace-nowrap">
                <?php if ($ord->balance_due > 0.01): ?>
                  <span class="font-semibold text-red-600"><?php echo fmt($ord->balance_due); ?></span>
                <?php else: ?>
                  <span class="text-green-600 font-semibold">Paid</span>
                <?php endif; ?>
              </td>
              <td class="px-3 py-2.5 text-center whitespace-nowrap">
                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] font-semibold <?php echo $sm['bg'].' '.$sm['text']; ?>">
                  <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:<?php echo $sm['dot']; ?>"></span>
                  <?php echo $sm['label']; ?>
                </span>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recent_orders)): ?>
            <tr><td colspan="5" class="text-center text-sm text-gray-400 py-8">No orders in this period</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Recent Payments -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
        <div>
          <h2 class="text-sm font-bold text-gray-900">Payments</h2>
          <p class="text-xs text-gray-400"><?php echo range_label($date_from, $date_to); ?></p>
        </div>
        <a href="<?php echo url('cr/payment_history.php'); ?>?date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"
           class="text-xs font-semibold text-blue-600 hover:text-blue-800">
          Full History <i class="fas fa-arrow-right ml-0.5 text-[10px]"></i>
        </a>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
              <th class="px-4 py-2 text-left text-[10px] font-semibold text-gray-500 uppercase">Ref #</th>
              <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-500 uppercase">Customer</th>
              <th class="px-3 py-2 text-right text-[10px] font-semibold text-gray-500 uppercase">Amount</th>
              <th class="px-3 py-2 text-left text-[10px] font-semibold text-gray-500 uppercase">Account</th>
              <th class="px-3 py-2 text-center text-[10px] font-semibold text-gray-500 uppercase">Type</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($recent_payments as $pay): ?>
            <tr class="hover:bg-gray-50 cursor-pointer transition-colors"
                onclick="window.location='<?php echo url('cr/payment_history.php'); ?>?date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&search=<?php echo urlencode($pay->payment_number); ?>'">
              <td class="px-4 py-2.5 text-xs font-mono text-green-600 whitespace-nowrap"><?php echo htmlspecialchars($pay->payment_number); ?></td>
              <td class="px-3 py-2.5 text-xs font-medium text-gray-900 max-w-[110px] truncate"><?php echo htmlspecialchars($pay->customer_name); ?></td>
              <td class="px-3 py-2.5 text-xs font-bold text-green-600 text-right whitespace-nowrap"><?php echo fmt($pay->amount); ?></td>
              <td class="px-3 py-2.5 text-xs text-gray-600 whitespace-nowrap">
                <i class="fas <?php echo $pay->payment_method==='Cash' ? 'fa-money-bill-wave text-green-500' : 'fa-university text-blue-500'; ?> mr-1 text-[10px]"></i>
                <?php echo htmlspecialchars($pay->account_name); ?>
              </td>
              <td class="px-3 py-2.5 text-center whitespace-nowrap">
                <?php if ($pay->payment_type === 'advance'): ?>
                <span class="px-1.5 py-0.5 bg-blue-100 text-blue-700 text-[10px] font-semibold rounded-full">Advance</span>
                <?php else: ?>
                <span class="px-1.5 py-0.5 bg-green-100 text-green-700 text-[10px] font-semibold rounded-full">Due Recv.</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recent_payments)): ?>
            <tr><td colspan="5" class="text-center text-sm text-gray-400 py-8">No payments in this period</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <p class="mt-5 text-center text-xs text-gray-400">
    Ujjal Flour Mills ERP &mdash; Credit Sales Dashboard &mdash; <?php echo range_label($date_from, $date_to); ?>
  </p>

</div>

<?php require_once '../templates/footer.php'; ?>
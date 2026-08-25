<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra', 'collector'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$pageTitle   = 'Payment History';

// Manage-payment capabilities: admins always, or anyone granted the action in
// Privileges (Credit Sales → Payment History → Edit / Delete Payment).
$is_admin_ph    = in_array(($currentUser['role'] ?? ''), ['Superadmin', 'admin'], true);
$can_edit_pay   = $is_admin_ph || userCanPageAction('credit_sales', 'payment_history', 'can_edit');
$can_delete_pay = $is_admin_ph || userCanPageAction('credit_sales', 'payment_history', 'can_delete');
$can_manage_pay = $can_edit_pay || $can_delete_pay;

/* ── Filters ──────────────────────────────────────────────── */
$f_date_from   = $_GET['date_from']   ?? date('Y-m-d');
$f_date_to     = $_GET['date_to']     ?? date('Y-m-d');
$f_customer    = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$f_method      = $_GET['payment_method'] ?? '';
$f_type        = $_GET['payment_type']   ?? '';
$f_account     = isset($_GET['bank_account_id']) ? (int)$_GET['bank_account_id'] : 0;
$f_search      = trim($_GET['search'] ?? '');

// Single-day view shows time column; range view shows date only
$single_day = ($f_date_from === $f_date_to);

/* ── Dropdown data ────────────────────────────────────────── */
$customers = $db->query(
    "SELECT id, name FROM customers WHERE status = 'active' AND customer_type = 'Credit' ORDER BY name"
)->results();

$bank_accounts = $db->query(
    "SELECT id, CONCAT(bank_name, ' — ', account_name) AS label FROM bank_accounts WHERE status = 'active' ORDER BY account_name"
)->results();

/* ── WHERE builder ────────────────────────────────────────── */
$where  = "WHERE cp.payment_date BETWEEN ? AND ?";
$params = [$f_date_from, $f_date_to];

if ($f_customer) {
    $where   .= " AND cp.customer_id = ?";
    $params[] = $f_customer;
}
if ($f_method) {
    $where   .= " AND cp.payment_method = ?";
    $params[] = $f_method;
}
if ($f_type) {
    $where   .= " AND cp.payment_type = ?";
    $params[] = $f_type;
}
if ($f_account) {
    $where   .= " AND cp.bank_account_id = ?";
    $params[] = $f_account;
}
if ($f_search) {
    $like     = '%' . $f_search . '%';
    $where   .= " AND (cp.payment_number LIKE ? OR c.name LIKE ? OR cp.reference_number LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

/* ── Summary stats ────────────────────────────────────────── */
$summary = $db->query(
    "SELECT
        COUNT(*)                                                                      AS total_payments,
        COALESCE(SUM(cp.amount), 0)                                                  AS total_collected,
        COALESCE(SUM(CASE WHEN cp.payment_type = 'advance'        THEN cp.amount ELSE 0 END), 0) AS advance_total,
        COALESCE(SUM(CASE WHEN cp.payment_type != 'advance'       THEN cp.amount ELSE 0 END), 0) AS due_collected,
        COALESCE(SUM(CASE WHEN cp.payment_method = 'Cash'         THEN cp.amount ELSE 0 END), 0) AS cash_total,
        COALESCE(SUM(CASE WHEN cp.payment_method != 'Cash'        THEN cp.amount ELSE 0 END), 0) AS bank_total,
        COUNT(DISTINCT cp.customer_id)                                               AS unique_customers,
        COALESCE(AVG(cp.amount), 0)                                                  AS avg_payment
     FROM customer_payments cp
     JOIN customers c ON cp.customer_id = c.id
     $where",
    $params
)->first();

/* ── By-account breakdown ─────────────────────────────────── */
$by_account = $db->query(
    "SELECT
        CASE WHEN cp.payment_method = 'Cash' THEN 'Cash'
             ELSE COALESCE(CONCAT(ba.bank_name, ' — ', ba.account_name), 'Unknown')
        END AS account_label,
        cp.payment_method,
        COUNT(*)               AS cnt,
        COALESCE(SUM(cp.amount), 0) AS total
     FROM customer_payments cp
     JOIN customers c ON cp.customer_id = c.id
     LEFT JOIN bank_accounts ba ON cp.bank_account_id = ba.id
     $where
     GROUP BY cp.bank_account_id, cp.payment_method
     ORDER BY total DESC",
    $params
)->results();

/* ── Main ledger query ────────────────────────────────────── */
$payments = $db->query(
    "SELECT
        cp.id,
        cp.payment_number,
        cp.payment_date,
        cp.created_at,
        cp.amount,
        cp.payment_method,
        cp.payment_type,
        cp.reference_number,
        cp.notes,
        c.id                AS customer_id,
        c.name              AS customer_name,
        c.phone_number,
        CASE
            WHEN cp.payment_method = 'Cash'
                 THEN 'Cash'
            ELSE COALESCE(CONCAT(ba.bank_name, ' — ', ba.account_name), 'Cash')
        END                 AS deposit_account,
        ba.account_number   AS deposit_account_no,
        u.display_name      AS collected_by,
        -- Orders this payment was allocated against (may be empty for advance)
        (SELECT GROUP_CONCAT(co.order_number ORDER BY co.id SEPARATOR ', ')
         FROM payment_allocations pa
         JOIN credit_orders co ON pa.order_id = co.id
         WHERE pa.payment_id = cp.id)        AS allocated_orders,
        -- Sum of original invoice totals
        (SELECT COALESCE(SUM(co.total_amount), 0)
         FROM payment_allocations pa
         JOIN credit_orders co ON pa.order_id = co.id
         WHERE pa.payment_id = cp.id)        AS original_invoice_total,
        -- Sum of allocated amounts for this payment
        (SELECT COALESCE(SUM(pa.allocated_amount), 0)
         FROM payment_allocations pa
         WHERE pa.payment_id = cp.id)        AS total_allocated,
        -- Remaining due on those orders after this payment
        (SELECT COALESCE(SUM(co.balance_due), 0)
         FROM payment_allocations pa
         JOIN credit_orders co ON pa.order_id = co.id
         WHERE pa.payment_id = cp.id)        AS remaining_due
     FROM customer_payments cp
     JOIN customers c ON cp.customer_id = c.id
     LEFT JOIN bank_accounts ba ON cp.bank_account_id = ba.id
     LEFT JOIN users u ON cp.created_by_user_id = u.id
     $where
     ORDER BY cp.payment_date DESC, cp.id DESC
     LIMIT 500",
    $params
)->results();

/* ── Totals row ───────────────────────────────────────────── */
$grand_total      = array_sum(array_map(fn($p) => (float)$p->amount, $payments));
$grand_inv_total  = array_sum(array_map(fn($p) => (float)$p->original_invoice_total, $payments));
$grand_remaining  = array_sum(array_map(fn($p) => (float)$p->remaining_due, $payments));

function fmt($n)  { return '৳' . number_format((float)$n, 0); }
function fmtd($n) { return '৳' . number_format((float)$n, 2); }

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

  <!-- ── Page Header ──────────────────────────────────────── -->
  <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
    <div>
      <h1 class="text-3xl font-bold text-gray-900">Payment History</h1>
      <p class="text-gray-500 mt-1">
        <i class="fas fa-calendar-alt mr-1 text-blue-500"></i>
        <?php echo date('d M Y', strtotime($f_date_from)); ?>
        <?php if (!$single_day): ?> &mdash; <?php echo date('d M Y', strtotime($f_date_to)); ?><?php endif; ?>
        &nbsp;&middot;&nbsp; <?php echo count($payments); ?> payment<?php echo count($payments) !== 1 ? 's' : ''; ?> found
      </p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <a href="<?php echo url('cr/customer_payment.php'); ?>"
         class="inline-flex items-center gap-1.5 px-4 py-2 bg-green-600 text-white text-sm font-semibold rounded-lg hover:bg-green-700 transition-colors cursor-pointer">
        <i class="fas fa-plus"></i> Record Payment
      </a>
      <a href="<?php echo url('cr/sales_dashboard.php'); ?>"
         class="inline-flex items-center gap-1.5 px-4 py-2 border border-gray-300 text-gray-700 text-sm font-semibold rounded-lg hover:bg-gray-50 transition-colors cursor-pointer">
        <i class="fas fa-arrow-left"></i> Dashboard
      </a>
    </div>
  </div>

  <!-- ── Filter Bar ───────────────────────────────────────── -->
  <div class="bg-white rounded-xl shadow-md p-4 mb-5">
    <form method="GET" class="flex flex-wrap gap-3 items-end">

      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">From</label>
        <input type="date" name="date_from" value="<?php echo $f_date_from; ?>"
               class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
      </div>

      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">To</label>
        <input type="date" name="date_to" value="<?php echo $f_date_to; ?>"
               class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
      </div>

      <div class="min-w-[180px]">
        <label class="block text-xs font-semibold text-gray-600 mb-1">Customer</label>
        <select name="customer_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
          <option value="">All Customers</option>
          <?php foreach ($customers as $cu): ?>
          <option value="<?php echo $cu->id; ?>" <?php echo $f_customer == $cu->id ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($cu->name); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Method</label>
        <select name="payment_method" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
          <option value="">All Methods</option>
          <?php foreach (['Cash','Bank Transfer','Cheque','Mobile Banking'] as $m): ?>
          <option value="<?php echo $m; ?>" <?php echo $f_method === $m ? 'selected' : ''; ?>><?php echo $m; ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Type</label>
        <select name="payment_type" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
          <option value="">All Types</option>
          <option value="invoice_payment" <?php echo $f_type === 'invoice_payment' ? 'selected' : ''; ?>>Due Collection</option>
          <option value="advance"         <?php echo $f_type === 'advance'         ? 'selected' : ''; ?>>Advance</option>
        </select>
      </div>

      <div class="min-w-[180px]">
        <label class="block text-xs font-semibold text-gray-600 mb-1">Account</label>
        <select name="bank_account_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
          <option value="">All Accounts</option>
          <?php foreach ($bank_accounts as $ba): ?>
          <option value="<?php echo $ba->id; ?>" <?php echo $f_account == $ba->id ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($ba->label); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="min-w-[160px]">
        <label class="block text-xs font-semibold text-gray-600 mb-1">Search</label>
        <input type="text" name="search" value="<?php echo htmlspecialchars($f_search); ?>"
               placeholder="Receipt # / name / ref"
               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
      </div>

      <div class="flex gap-2">
        <button type="submit"
                class="px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors cursor-pointer">
          <i class="fas fa-filter mr-1"></i> Apply
        </button>
        <a href="payment_history.php"
           class="px-3 py-2 border border-gray-300 text-sm text-gray-600 rounded-lg hover:bg-gray-50 transition-colors cursor-pointer">
          Reset
        </a>
      </div>

      <!-- Quick presets -->
      <div class="ml-auto flex gap-2 flex-wrap">
        <?php
        $presets = [
          'Today'      => [date('Y-m-d'),           date('Y-m-d')],
          'Yesterday'  => [date('Y-m-d',strtotime('-1 day')), date('Y-m-d',strtotime('-1 day'))],
          'This week'  => [date('Y-m-d',strtotime('monday this week')), date('Y-m-d')],
          'This month' => [date('Y-m-01'), date('Y-m-d')],
          'Last 30d'   => [date('Y-m-d',strtotime('-30 days')), date('Y-m-d')],
        ];
        foreach ($presets as $label => [$from, $to]):
          $active = ($f_date_from === $from && $f_date_to === $to);
        ?>
        <a href="?date_from=<?php echo $from; ?>&date_to=<?php echo $to; ?>&customer_id=<?php echo $f_customer; ?>&payment_method=<?php echo urlencode($f_method); ?>&payment_type=<?php echo $f_type; ?>&bank_account_id=<?php echo $f_account; ?>&search=<?php echo urlencode($f_search); ?>"
           class="px-3 py-2 text-xs font-semibold rounded-lg cursor-pointer transition-colors <?php echo $active ? 'bg-blue-600 text-white' : 'border border-gray-300 text-gray-600 hover:bg-gray-50'; ?>">
          <?php echo $label; ?>
        </a>
        <?php endforeach; ?>
      </div>

    </form>
  </div>

  <!-- ── Summary Cards ────────────────────────────────────── -->
  <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-3 mb-5">

    <div class="bg-green-600 rounded-xl shadow-md p-4 text-white xl:col-span-2">
      <p class="text-xs opacity-80 font-semibold uppercase tracking-wide">Total Collected</p>
      <p class="text-2xl font-bold mt-1"><?php echo fmt($summary->total_collected ?? 0); ?></p>
      <p class="text-xs opacity-75 mt-0.5"><?php echo (int)($summary->total_payments ?? 0); ?> payment<?php echo $summary->total_payments != 1 ? 's' : ''; ?></p>
    </div>

    <div class="bg-blue-600 rounded-xl shadow-md p-4 text-white xl:col-span-2">
      <p class="text-xs opacity-80 font-semibold uppercase tracking-wide">Due Collections</p>
      <p class="text-2xl font-bold mt-1"><?php echo fmt($summary->due_collected ?? 0); ?></p>
      <p class="text-xs opacity-75 mt-0.5">Against invoices</p>
    </div>

    <div class="bg-indigo-600 rounded-xl shadow-md p-4 text-white xl:col-span-2">
      <p class="text-xs opacity-80 font-semibold uppercase tracking-wide">Advance Received</p>
      <p class="text-2xl font-bold mt-1"><?php echo fmt($summary->advance_total ?? 0); ?></p>
      <p class="text-xs opacity-75 mt-0.5">Pre-payment / advance</p>
    </div>

    <div class="bg-white rounded-xl shadow-md p-4 border border-gray-200">
      <p class="text-xs text-gray-500 font-semibold uppercase tracking-wide">Cash</p>
      <p class="text-xl font-bold text-green-700 mt-1"><?php echo fmt($summary->cash_total ?? 0); ?></p>
    </div>

    <div class="bg-white rounded-xl shadow-md p-4 border border-gray-200">
      <p class="text-xs text-gray-500 font-semibold uppercase tracking-wide">Bank / Other</p>
      <p class="text-xl font-bold text-blue-700 mt-1"><?php echo fmt($summary->bank_total ?? 0); ?></p>
    </div>

  </div>

  <!-- ── Secondary stats row ──────────────────────────────── -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 border-l-4 border-l-orange-400">
      <p class="text-xs text-gray-500 font-semibold uppercase">Customers Paid</p>
      <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo (int)($summary->unique_customers ?? 0); ?></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 border-l-4 border-l-purple-400">
      <p class="text-xs text-gray-500 font-semibold uppercase">Avg per Payment</p>
      <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo fmt($summary->avg_payment ?? 0); ?></p>
    </div>
    <!-- By-account mini breakdown -->
    <?php foreach (array_slice($by_account, 0, 2) as $ba): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 border-l-4 border-l-blue-400">
      <p class="text-xs text-gray-500 font-semibold uppercase truncate"><?php echo htmlspecialchars($ba->account_label); ?></p>
      <p class="text-xl font-bold text-gray-900 mt-1"><?php echo fmt($ba->total); ?></p>
      <p class="text-xs text-gray-400 mt-0.5"><?php echo $ba->cnt; ?> payment<?php echo $ba->cnt != 1 ? 's' : ''; ?></p>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Account breakdown (if >2 accounts) ───────────────── -->
  <?php if (count($by_account) > 2): ?>
  <div class="bg-white rounded-xl shadow-md p-4 mb-5">
    <p class="text-sm font-semibold text-gray-700 mb-3">Collections by Deposit Account</p>
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3">
      <?php foreach ($by_account as $ba):
        $is_cash = ($ba->payment_method === 'Cash');
        $pct = $summary->total_collected > 0 ? round($ba->total / $summary->total_collected * 100) : 0;
      ?>
      <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg border border-gray-200">
        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 <?php echo $is_cash ? 'bg-green-100' : 'bg-blue-100'; ?>">
          <i class="fas <?php echo $is_cash ? 'fa-money-bill-wave text-green-600' : 'fa-university text-blue-600'; ?> text-xs"></i>
        </div>
        <div class="min-w-0 flex-1">
          <p class="text-xs font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($ba->account_label); ?></p>
          <p class="text-sm font-bold text-gray-900"><?php echo fmt($ba->total); ?></p>
          <div class="flex items-center gap-1 mt-0.5">
            <div class="flex-1 h-1 bg-gray-200 rounded-full overflow-hidden">
              <div class="h-full rounded-full <?php echo $is_cash ? 'bg-green-500' : 'bg-blue-500'; ?>" style="width:<?php echo $pct; ?>%"></div>
            </div>
            <span class="text-xs text-gray-400"><?php echo $pct; ?>%</span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Payment Ledger Table ──────────────────────────────── -->
  <div class="bg-white rounded-xl shadow-md overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between flex-wrap gap-2">
      <div>
        <h2 class="text-base font-bold text-gray-900">Payment Ledger</h2>
        <p class="text-xs text-gray-500 mt-0.5">
          <?php if ($single_day): ?>
            All payments for <?php echo date('d M Y', strtotime($f_date_from)); ?> — time shown
          <?php else: ?>
            <?php echo date('d M Y', strtotime($f_date_from)); ?> to <?php echo date('d M Y', strtotime($f_date_to)); ?>
          <?php endif; ?>
          &nbsp;&middot;&nbsp; Showing <?php echo min(count($payments), 500); ?> of <?php echo count($payments); ?> records
        </p>
      </div>
      <a href="payment_history.php?<?php echo http_build_query($_GET + ['export' => '1']); ?>"
         class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-gray-300 text-xs font-semibold text-gray-700 rounded-lg hover:bg-gray-50 cursor-pointer transition-colors">
        <i class="fas fa-download text-gray-500"></i> Export
      </a>
    </div>

    <?php if (empty($payments)): ?>
    <div class="py-16 text-center">
      <i class="fas fa-search text-4xl text-gray-300 mb-3"></i>
      <p class="text-gray-500 font-medium">No payments found for the selected filters</p>
      <p class="text-sm text-gray-400 mt-1">Try adjusting the date range or clearing filters</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">
              Receipt #
            </th>
            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">
              <?php echo $single_day ? 'Time' : 'Date'; ?>
            </th>
            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Customer</th>
            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Orders</th>
            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Invoice Total</th>
            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Received</th>
            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Due After</th>
            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Deposited To</th>
            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Collected By</th>
            <th class="px-3 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Type</th>
            <th class="px-3 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Method</th>
            <?php if ($can_manage_pay): ?>
            <th class="px-3 py-3 text-center text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Manage</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($payments as $pay):
            $is_advance  = ($pay->payment_type === 'advance');
            $has_orders  = !empty($pay->allocated_orders);
            $due_after   = (float)$pay->remaining_due;
            $inv_total   = (float)$pay->original_invoice_total;
            $method_icon = match($pay->payment_method) {
              'Cash'           => 'fa-money-bill-wave text-green-600',
              'Bank Transfer'  => 'fa-university text-blue-600',
              'Cheque'         => 'fa-money-check text-indigo-600',
              'Mobile Banking' => 'fa-mobile-alt text-purple-600',
              default          => 'fa-circle-dollar-to-slot text-gray-500',
            };
            $date_display = $single_day
              ? date('h:i A', strtotime($pay->created_at))
              : date('d M Y', strtotime($pay->payment_date));
          ?>
          <tr class="hover:bg-blue-50/30 transition-colors cursor-pointer"
              onclick="window.location='<?php echo url('cr/credit_payment_receipt.php'); ?>?id=<?php echo $pay->id; ?>'">

            <td class="px-4 py-3 whitespace-nowrap">
              <span class="font-mono text-xs font-semibold text-blue-700"><?php echo htmlspecialchars($pay->payment_number); ?></span>
              <?php if ($pay->reference_number): ?>
              <div class="text-xs text-gray-400 mt-0.5">Ref: <?php echo htmlspecialchars($pay->reference_number); ?></div>
              <?php endif; ?>
            </td>

            <td class="px-3 py-3 text-sm text-gray-600 whitespace-nowrap font-medium">
              <?php echo $date_display; ?>
              <?php if (!$single_day && $pay->created_at): ?>
              <div class="text-xs text-gray-400"><?php echo date('h:i A', strtotime($pay->created_at)); ?></div>
              <?php endif; ?>
            </td>

            <td class="px-3 py-3">
              <p class="font-semibold text-gray-900 whitespace-nowrap"><?php echo htmlspecialchars($pay->customer_name); ?></p>
              <?php if ($pay->phone_number && $pay->phone_number !== '0'): ?>
              <p class="text-xs text-gray-400"><?php echo htmlspecialchars($pay->phone_number); ?></p>
              <?php endif; ?>
            </td>

            <td class="px-3 py-3 max-w-[160px]">
              <?php if ($has_orders): ?>
                <div class="flex flex-wrap gap-1">
                  <?php foreach (explode(', ', $pay->allocated_orders) as $on): ?>
                  <span class="inline-block px-1.5 py-0.5 bg-orange-50 border border-orange-200 text-orange-700 text-xs font-mono rounded">
                    <?php echo htmlspecialchars(trim($on)); ?>
                  </span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span class="text-xs text-gray-400 italic">Advance — not allocated</span>
              <?php endif; ?>
            </td>

            <td class="px-3 py-3 text-right whitespace-nowrap">
              <?php if ($inv_total > 0): ?>
                <span class="text-sm font-medium text-gray-700"><?php echo fmtd($inv_total); ?></span>
              <?php else: ?>
                <span class="text-xs text-gray-400">—</span>
              <?php endif; ?>
            </td>

            <td class="px-3 py-3 text-right whitespace-nowrap">
              <span class="text-base font-bold text-green-700"><?php echo fmtd($pay->amount); ?></span>
            </td>

            <td class="px-3 py-3 text-right whitespace-nowrap">
              <?php if ($has_orders): ?>
                <?php if ($due_after <= 0.01): ?>
                  <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-green-100 text-green-700 text-xs font-semibold rounded-full">
                    <i class="fas fa-check-circle text-xs"></i> Cleared
                  </span>
                <?php else: ?>
                  <span class="text-sm font-semibold text-red-600"><?php echo fmtd($due_after); ?></span>
                <?php endif; ?>
              <?php elseif ($is_advance): ?>
                <span class="text-xs text-indigo-500 italic">Advance held</span>
              <?php else: ?>
                <span class="text-xs text-gray-400">—</span>
              <?php endif; ?>
            </td>

            <td class="px-3 py-3 whitespace-nowrap">
              <div class="flex items-center gap-1.5">
                <i class="fas <?php echo strpos($pay->deposit_account, 'Cash') !== false ? 'fa-money-bill-wave text-green-500' : 'fa-university text-blue-500'; ?> text-xs flex-shrink-0"></i>
                <span class="text-sm text-gray-700 truncate max-w-[130px]"><?php echo htmlspecialchars($pay->deposit_account); ?></span>
              </div>
              <?php if ($pay->deposit_account_no): ?>
              <p class="text-xs text-gray-400 ml-4 mt-0.5">A/C <?php echo htmlspecialchars($pay->deposit_account_no); ?></p>
              <?php endif; ?>
            </td>

            <td class="px-3 py-3 whitespace-nowrap">
              <div class="flex items-center gap-1.5">
                <div class="w-6 h-6 rounded-full bg-gray-100 flex items-center justify-center flex-shrink-0">
                  <i class="fas fa-user text-gray-400 text-xs"></i>
                </div>
                <span class="text-sm text-gray-700"><?php echo htmlspecialchars($pay->collected_by ?? 'System'); ?></span>
              </div>
            </td>

            <td class="px-3 py-3 text-center whitespace-nowrap">
              <?php if ($is_advance): ?>
              <span class="px-2 py-1 bg-indigo-100 text-indigo-700 text-xs font-semibold rounded-full">Advance</span>
              <?php else: ?>
              <span class="px-2 py-1 bg-green-100 text-green-700 text-xs font-semibold rounded-full">Due Recv.</span>
              <?php endif; ?>
            </td>

            <td class="px-3 py-3 text-center whitespace-nowrap">
              <span class="inline-flex items-center gap-1 text-xs font-medium text-gray-600">
                <i class="fas <?php echo $method_icon; ?> text-xs"></i>
                <?php echo htmlspecialchars($pay->payment_method); ?>
              </span>
            </td>

            <?php if ($can_manage_pay): ?>
            <td class="px-3 py-3 text-center whitespace-nowrap" onclick="event.stopPropagation()">
              <div class="flex items-center justify-center gap-1">
                <?php if ($can_edit_pay): ?>
                <a href="customer_payment.php?edit=<?php echo (int)$pay->id; ?>"
                   title="Edit this receipt — opens the payment form prefilled"
                   class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold text-blue-600 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 transition-colors">
                  <i class="fas fa-pen text-xs"></i>
                </a>
                <?php endif; ?>
                <?php if ($can_delete_pay): ?>
                <button type="button"
                        onclick="confirmDeletePayment(<?php echo $pay->id; ?>, '<?php echo htmlspecialchars($pay->payment_number, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($pay->customer_name, ENT_QUOTES); ?>', '<?php echo htmlspecialchars(number_format($pay->amount,2), ENT_QUOTES); ?>')"
                        title="Delete payment (to Recycle Bin)"
                        class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold text-red-600 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 transition-colors">
                  <i class="fas fa-trash-alt text-xs"></i>
                </button>
                <?php endif; ?>
              </div>
            </td>
            <?php endif; ?>

          </tr>
          <?php endforeach; ?>
        </tbody>

        <!-- Grand Total Row -->
        <tfoot>
          <tr class="bg-gray-900 text-white">
            <td colspan="4" class="px-4 py-4 text-sm font-bold text-right text-gray-300">
              TOTAL — <?php echo count($payments); ?> payment<?php echo count($payments) !== 1 ? 's' : ''; ?>
            </td>
            <td class="px-3 py-4 text-right text-sm font-bold text-gray-300 whitespace-nowrap">
              <?php echo $grand_inv_total > 0 ? fmtd($grand_inv_total) : '—'; ?>
            </td>
            <td class="px-3 py-4 text-right text-base font-bold text-green-400 whitespace-nowrap">
              <?php echo fmtd($grand_total); ?>
            </td>
            <td class="px-3 py-4 text-right text-sm font-bold text-red-400 whitespace-nowrap">
              <?php echo $grand_remaining > 0 ? fmtd($grand_remaining) : '<span class="text-gray-500">—</span>'; ?>
            </td>
            <td colspan="4" class="px-3 py-4 text-xs text-gray-400 text-right">
              Cash <?php echo fmt($summary->cash_total ?? 0); ?> &nbsp;&nbsp;
              Bank <?php echo fmt($summary->bank_total ?? 0); ?>
            </td>
          </tr>
        </tfoot>

      </table>
    </div>

    <?php if (count($payments) >= 500): ?>
    <div class="px-5 py-3 bg-amber-50 border-t border-amber-200">
      <p class="text-xs text-amber-700">
        <i class="fas fa-info-circle mr-1"></i>
        Showing first 500 records. Narrow the date range or apply filters to see all results.
      </p>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>

  <p class="mt-5 text-center text-xs text-gray-400">
    Ujjal Flour Mills ERP &mdash; Payment History &mdash; Generated <?php echo date('d M Y H:i'); ?>
  </p>

</div>

<?php if ($can_delete_pay): ?>
<!-- ── Delete Payment Modal ────────────────────────────────── -->
<div id="deletePaymentModal"
     class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm"
     onclick="if(event.target===this) closeDeleteModal()">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
    <!-- Header -->
    <div class="bg-red-600 px-6 py-4 flex items-center gap-3">
      <div class="w-10 h-10 bg-red-500 rounded-full flex items-center justify-center flex-shrink-0">
        <i class="fas fa-trash-alt text-white text-base"></i>
      </div>
      <div>
        <h3 class="text-white font-bold text-base">Delete Payment</h3>
        <p class="text-red-200 text-xs mt-0.5">This action cannot be undone</p>
      </div>
    </div>

    <!-- Body -->
    <div class="px-6 py-5">
      <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
        <p class="text-sm text-red-800 font-semibold mb-1">You are about to permanently delete:</p>
        <p class="text-sm text-red-900">Receipt: <strong id="del_payment_number" class="font-mono"></strong></p>
        <p class="text-sm text-red-900">Customer: <strong id="del_customer_name"></strong></p>
        <p class="text-sm text-red-900">Amount: <strong id="del_amount" class="text-red-700"></strong></p>
      </div>

      <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 mb-4 text-xs text-amber-800 space-y-1">
        <p class="font-semibold">The following will be reversed automatically:</p>
        <p>• Customer ledger balance restored</p>
        <p>• Allocated invoice balances restored</p>
        <p>• Journal entry and transaction lines removed</p>
        <p>• Pending bank statement entry voided</p>
        <p>• Audit trail entry recorded as critical</p>
        <p>• Telegram notification sent</p>
      </div>

      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">
          Reason for deletion <span class="text-gray-400 font-normal">(optional but recommended)</span>
        </label>
        <input type="text" id="del_reason" maxlength="255"
               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-red-400 focus:border-red-400"
               placeholder="e.g. Entered in error, duplicate entry…">
      </div>
    </div>

    <!-- Footer -->
    <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-3">
      <button type="button" onclick="closeDeleteModal()"
              class="px-4 py-2 text-sm font-semibold text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-100 transition-colors">
        Cancel
      </button>
      <button type="button" id="del_confirm_btn" onclick="executeDeletePayment()"
              class="px-5 py-2 text-sm font-bold text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors flex items-center gap-2">
        <i class="fas fa-trash-alt text-xs"></i>
        Confirm Delete
      </button>
    </div>
  </div>
</div>

<script>
const csrfMeta = document.querySelector('meta[name="csrf-token"]');
const CSRF_TOKEN = csrfMeta ? csrfMeta.content : '';
let _del_payment_id = null;

// Feature #1: "Edit" now opens the payment form prefilled (customer_payment.php?edit=ID).
// Saving there reverses the original into the Recycle Bin and reposts the corrected
// receipt — see the Edit link above. (Delete stays available for pure reversal.)

function confirmDeletePayment(id, receiptNo, customerName, amount) {
    _del_payment_id = id;
    document.getElementById('del_payment_number').textContent = receiptNo;
    document.getElementById('del_customer_name').textContent  = customerName;
    document.getElementById('del_amount').textContent         = '৳' + amount;
    document.getElementById('del_reason').value              = '';

    const modal = document.getElementById('deletePaymentModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.getElementById('del_reason').focus();
}

function closeDeleteModal() {
    const modal = document.getElementById('deletePaymentModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    _del_payment_id = null;
}

function executeDeletePayment() {
    if (!_del_payment_id) return;

    const btn    = document.getElementById('del_confirm_btn');
    const reason = document.getElementById('del_reason').value.trim();

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin text-xs"></i> Deleting…';

    const body = new URLSearchParams({
        payment_id:  _del_payment_id,
        reason:      reason,
        csrf_token:  CSRF_TOKEN,
    });

    fetch('delete_payment.php', {
        method:  'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': CSRF_TOKEN,
        },
        body: body.toString(),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeDeleteModal();
            // Remove the row from the table immediately, then refresh totals
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach(row => {
                // Find the row with the matching receipt # in its first cell
                const cell = row.querySelector('td:first-child span.font-mono');
                if (cell && cell.textContent.trim() === document.getElementById('del_payment_number').textContent.trim()) {
                    row.style.transition = 'opacity 0.3s, background 0.3s';
                    row.style.background = '#fef2f2';
                    row.style.opacity    = '0';
                    setTimeout(() => { row.remove(); }, 350);
                }
            });
            // Show a toast-style success message then reload
            setTimeout(() => { window.location.reload(); }, 800);
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-alt text-xs"></i> Confirm Delete';
            alert('Error: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash-alt text-xs"></i> Confirm Delete';
        alert('Network error: ' + err.message);
    });
}
</script>
<?php endif; ?>

<?php require_once '../templates/footer.php'; ?>
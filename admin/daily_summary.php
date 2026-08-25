<?php
require_once '../core/init.php';
restrict_access(['Superadmin', 'admin']);

$pageTitle = 'Daily Financial Summary';
global $db;

$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');
$df = $date_from;
$dt = $date_to;

// ── Helpers ───────────────────────────────────────────────────────────────────
function dsq(string $sql, array $p = [], $default = 0) {
    global $db;
    $r = $db->query($sql, $p);
    if ($r->error()) return $default;
    $row = $r->first();
    return $row ? ($row->v ?? $default) : $default;
}
function dsql(string $sql, array $p = []): array {
    global $db;
    $r = $db->query($sql, $p);
    return $r->error() ? [] : ($r->results() ?: []);
}
function ds_fmt(float $n): string { return '৳' . number_format($n, 0); }
function ds_diff_badge(float $n): string {
    if ($n > 0) return '<span class="text-green-600 font-semibold">+' . ds_fmt($n) . '</span>';
    if ($n < 0) return '<span class="text-red-600 font-semibold">' . ds_fmt($n) . '</span>';
    return '<span class="text-gray-400">—</span>';
}

// ─── A. CURRENT POSITION (snapshot, date-independent) ────────────────────────
$bank_accounts    = dsql("SELECT id, account_name, bank_name, current_balance, chart_of_account_id FROM bank_accounts WHERE status='active' ORDER BY account_name");
$total_bank       = array_sum(array_map(fn($b) => (float)$b->current_balance, $bank_accounts));
$petty_accounts   = dsql("SELECT id, account_name, current_balance FROM branch_petty_cash_accounts WHERE status='active' ORDER BY account_name");
$total_petty_cash = array_sum(array_map(fn($p) => (float)$p->current_balance, $petty_accounts));
$total_position   = $total_bank + $total_petty_cash;
$total_receivables = (float)dsq("SELECT SUM(balance_due) AS v FROM credit_orders WHERE balance_due > 0.01 AND status NOT IN ('cancelled','rejected','draft')");

// ─── B. COLLECTIONS ───────────────────────────────────────────────────────────
$coll_advance = (float)dsq("SELECT SUM(amount) AS v FROM customer_payments WHERE DATE(payment_date) BETWEEN ? AND ? AND payment_type='advance'", [$df, $dt]);
$coll_due     = (float)dsq("SELECT SUM(amount) AS v FROM customer_payments WHERE DATE(payment_date) BETWEEN ? AND ? AND payment_type='invoice_payment'", [$df, $dt]);
$coll_total   = $coll_advance + $coll_due;
$coll_count   = (int)dsq("SELECT COUNT(*) AS v FROM customer_payments WHERE DATE(payment_date) BETWEEN ? AND ?", [$df, $dt]);
$coll_detail  = dsql("
    SELECT cp.payment_number, DATE(cp.payment_date) AS pdate, c.name AS customer_name,
           cp.amount, cp.payment_type, cp.payment_method,
           COALESCE(ba.account_name, 'Cash') AS bank_name
    FROM customer_payments cp
    JOIN customers c ON c.id = cp.customer_id
    LEFT JOIN bank_accounts ba ON ba.id = cp.bank_account_id
    WHERE DATE(cp.payment_date) BETWEEN ? AND ?
    ORDER BY cp.payment_date DESC, cp.id DESC LIMIT 200
", [$df, $dt]);

// ─── C. DELIVERIES (shipped date) ────────────────────────────────────────────
$del_value = (float)dsq("
    SELECT SUM(co.total_amount) AS v
    FROM credit_orders co
    INNER JOIN credit_order_shipping cos ON cos.order_id = co.id
    WHERE DATE(cos.shipped_date) BETWEEN ? AND ?
    AND co.status NOT IN ('cancelled','rejected','draft')
", [$df, $dt]);
$del_count = (int)dsq("
    SELECT COUNT(DISTINCT co.id) AS v
    FROM credit_orders co
    INNER JOIN credit_order_shipping cos ON cos.order_id = co.id
    WHERE DATE(cos.shipped_date) BETWEEN ? AND ?
    AND co.status NOT IN ('cancelled','rejected','draft')
", [$df, $dt]);
$del_detail = dsql("
    SELECT co.order_number, DATE(cos.shipped_date) AS shipped_date,
           c.name AS customer_name, co.total_amount, co.balance_due, co.status
    FROM credit_orders co
    INNER JOIN customers c ON c.id = co.customer_id
    INNER JOIN credit_order_shipping cos ON cos.order_id = co.id
    WHERE DATE(cos.shipped_date) BETWEEN ? AND ?
    AND co.status NOT IN ('cancelled','rejected','draft')
    ORDER BY cos.shipped_date DESC, co.id DESC LIMIT 200
", [$df, $dt]);

// ─── D. PURCHASE ORDERS PLACED ───────────────────────────────────────────────
$po_adn_cnt = (int)dsq("SELECT COUNT(*) AS v FROM purchase_orders_adnan WHERE DATE(po_date) BETWEEN ? AND ? AND po_status NOT IN ('cancelled','draft')", [$df, $dt]);
$po_adn_val = (float)dsq("SELECT SUM(total_order_value) AS v FROM purchase_orders_adnan WHERE DATE(po_date) BETWEEN ? AND ? AND po_status NOT IN ('cancelled','draft')", [$df, $dt]);
$po_std_cnt = (int)dsq("SELECT COUNT(*) AS v FROM purchase_orders WHERE DATE(po_date) BETWEEN ? AND ? AND status NOT IN ('cancelled','draft')", [$df, $dt]);
$po_std_val = (float)dsq("SELECT SUM(total_amount) AS v FROM purchase_orders WHERE DATE(po_date) BETWEEN ? AND ? AND status NOT IN ('cancelled','draft')", [$df, $dt]);
$po_total_val = $po_adn_val + $po_std_val;
$po_total_cnt = $po_adn_cnt + $po_std_cnt;
$po_adn_detail = dsql("
    SELECT po_number, po_date, supplier_name, quantity_kg, unit_price_per_kg, total_order_value, po_status
    FROM purchase_orders_adnan
    WHERE DATE(po_date) BETWEEN ? AND ? AND po_status NOT IN ('cancelled','draft')
    ORDER BY po_date DESC LIMIT 100
", [$df, $dt]);

// ─── E. GOODS RECEIVED ───────────────────────────────────────────────────────
$grn_adn_val = (float)dsq("SELECT SUM(total_value) AS v FROM goods_received_adnan WHERE DATE(grn_date) BETWEEN ? AND ?", [$df, $dt]);
$grn_std_val = (float)dsq("SELECT SUM(gi.line_total) AS v FROM goods_received_notes grn JOIN goods_received_items gi ON gi.grn_id = grn.id WHERE DATE(grn.received_date) BETWEEN ? AND ?", [$df, $dt]);
$grn_total   = $grn_adn_val + $grn_std_val;
$grn_adn_detail = dsql("
    SELECT grn_number, grn_date, supplier_name, quantity_received_kg, unit_price_per_kg, total_value
    FROM goods_received_adnan
    WHERE DATE(grn_date) BETWEEN ? AND ?
    ORDER BY grn_date DESC LIMIT 100
", [$df, $dt]);

// ─── F. SUPPLIER PAYMENTS ────────────────────────────────────────────────────
$sup_paid_adn = (float)dsq("SELECT SUM(amount_paid) AS v FROM purchase_payments_adnan WHERE DATE(payment_date) BETWEEN ? AND ? AND is_posted=1", [$df, $dt]);
$sup_paid_std = (float)dsq("SELECT SUM(amount) AS v FROM supplier_payments WHERE DATE(payment_date) BETWEEN ? AND ?", [$df, $dt]);
$sup_paid_total = $sup_paid_adn + $sup_paid_std;
$sup_paid_detail = dsql("
    SELECT payment_voucher_number AS payment_number, payment_date, supplier_name, amount_paid, payment_method
    FROM purchase_payments_adnan
    WHERE DATE(payment_date) BETWEEN ? AND ? AND is_posted=1
    ORDER BY payment_date DESC LIMIT 100
", [$df, $dt]);
$sup_paid_std_detail = dsql("
    SELECT sp.payment_number, sp.payment_date, s.company_name AS supplier_name, sp.amount, sp.payment_method
    FROM supplier_payments sp
    JOIN suppliers s ON s.id = sp.supplier_id
    WHERE DATE(sp.payment_date) BETWEEN ? AND ?
    ORDER BY sp.payment_date DESC LIMIT 100
", [$df, $dt]);

// ─── G. EXPENSES ─────────────────────────────────────────────────────────────
$exp_total  = (float)dsq("SELECT SUM(total_amount) AS v FROM expense_vouchers WHERE DATE(expense_date) BETWEEN ? AND ? AND status='approved'", [$df, $dt]);
$exp_by_cat = dsql("
    SELECT COALESCE(ec.category_name,'Uncategorized') AS category, SUM(ev.total_amount) AS cat_total
    FROM expense_vouchers ev
    LEFT JOIN expense_categories ec ON ec.id = ev.category_id
    WHERE DATE(ev.expense_date) BETWEEN ? AND ? AND ev.status='approved'
    GROUP BY ec.id, ec.category_name
    ORDER BY cat_total DESC
", [$df, $dt]);
$exp_detail = dsql("
    SELECT ev.voucher_number, ev.expense_date, ec.category_name AS category,
           ev.remarks, ev.total_amount, ev.payment_method
    FROM expense_vouchers ev
    LEFT JOIN expense_categories ec ON ec.id = ev.category_id
    WHERE DATE(ev.expense_date) BETWEEN ? AND ? AND ev.status='approved'
    ORDER BY ev.expense_date DESC, ev.id DESC LIMIT 200
", [$df, $dt]);

// ─── H. BANK MOVEMENT ────────────────────────────────────────────────────────
$cp_in_rows = dsql("SELECT bank_account_id, SUM(amount) AS amt FROM customer_payments WHERE DATE(payment_date) BETWEEN ? AND ? AND bank_account_id IS NOT NULL GROUP BY bank_account_id", [$df, $dt]);
$cp_in_map  = [];
foreach ($cp_in_rows as $r) $cp_in_map[$r->bank_account_id] = (float)$r->amt;

$exp_out_rows = dsql("SELECT bank_account_id, SUM(total_amount) AS amt FROM expense_vouchers WHERE DATE(expense_date) BETWEEN ? AND ? AND status='approved' AND payment_method='bank' AND bank_account_id IS NOT NULL GROUP BY bank_account_id", [$df, $dt]);
$exp_out_map  = [];
foreach ($exp_out_rows as $r) $exp_out_map[$r->bank_account_id] = (float)$r->amt;

$pp_out_rows = dsql("SELECT bank_account_id, SUM(amount_paid) AS amt FROM purchase_payments_adnan WHERE DATE(payment_date) BETWEEN ? AND ? AND payment_method='bank' AND is_posted=1 AND bank_account_id IS NOT NULL GROUP BY bank_account_id", [$df, $dt]);
$pp_out_map  = [];
foreach ($pp_out_rows as $r) $pp_out_map[$r->bank_account_id] = (float)$r->amt;

$sp_out_rows = dsql("SELECT ba.id AS bank_id, SUM(sp.amount) AS amt FROM supplier_payments sp JOIN bank_accounts ba ON ba.chart_of_account_id = sp.payment_account_id WHERE DATE(sp.payment_date) BETWEEN ? AND ? GROUP BY ba.id", [$df, $dt]);
$sp_out_map  = [];
foreach ($sp_out_rows as $r) $sp_out_map[$r->bank_id] = (float)$r->amt;

$bank_movement    = [];
$mv_bank_inflow   = 0;
$mv_bank_outflow  = 0;
foreach ($bank_accounts as $ba) {
    $inflow  = $cp_in_map[$ba->id] ?? 0;
    $outflow = ($exp_out_map[$ba->id] ?? 0) + ($pp_out_map[$ba->id] ?? 0) + ($sp_out_map[$ba->id] ?? 0);
    $closing = (float)$ba->current_balance;
    $opening = $closing - $inflow + $outflow;
    $bank_movement[] = ['name' => $ba->account_name . ($ba->bank_name ? ' — ' . $ba->bank_name : ''), 'opening' => $opening, 'inflow' => $inflow, 'outflow' => $outflow, 'closing' => $closing];
    $mv_bank_inflow  += $inflow;
    $mv_bank_outflow += $outflow;
}

// Cash movement (petty cash — outflows only via expense_vouchers)
$cash_exp_rows = dsql("SELECT cash_account_id, SUM(total_amount) AS amt FROM expense_vouchers WHERE DATE(expense_date) BETWEEN ? AND ? AND status='approved' AND payment_method='cash' AND cash_account_id IS NOT NULL GROUP BY cash_account_id", [$df, $dt]);
$cash_exp_map  = [];
foreach ($cash_exp_rows as $r) $cash_exp_map[$r->cash_account_id] = (float)$r->amt;
// Cash collections (customer payments without bank account)
$cash_coll = (float)dsq("SELECT SUM(amount) AS v FROM customer_payments WHERE DATE(payment_date) BETWEEN ? AND ? AND bank_account_id IS NULL", [$df, $dt]);

$cash_movement   = [];
$mv_cash_inflow  = 0;
$mv_cash_outflow = 0;
foreach ($petty_accounts as $pa) {
    $outflow = $cash_exp_map[$pa->id] ?? 0;
    $closing = (float)$pa->current_balance;
    $opening = $closing + $outflow; // no per-account inflow tracking; conservative estimate
    $cash_movement[] = ['name' => $pa->account_name, 'opening' => $opening, 'outflow' => $outflow, 'closing' => $closing];
    $mv_cash_outflow += $outflow;
}

// ─── I. NET FLOW ─────────────────────────────────────────────────────────────
$net_inflow  = $coll_total;
$net_outflow = $sup_paid_total + $exp_total;
$net_flow    = $net_inflow - $net_outflow;

require_once '../templates/header.php';
?>

<div class="max-w-7xl mx-auto px-4 py-4" x-data="{ modal: '' }">

  <!-- ── Page Header + Date Filter ──────────────────────────────────────────── -->
  <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <div>
      <h1 class="text-lg font-bold text-gray-800">Daily Financial Summary</h1>
      <p class="text-xs text-gray-400">Snapshot position + activity for the selected period</p>
    </div>
    <form method="GET" class="flex flex-wrap items-center gap-2">
      <input type="date" name="date_from" value="<?= htmlspecialchars($df) ?>" class="border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-blue-400">
      <span class="text-gray-400 text-xs">to</span>
      <input type="date" name="date_to" value="<?= htmlspecialchars($dt) ?>" class="border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-blue-400">
      <button type="submit" class="bg-blue-600 text-white text-xs px-3 py-1.5 rounded-lg hover:bg-blue-700 transition-colors">Apply</button>
      <!-- Presets -->
      <div class="flex gap-1 flex-wrap">
        <?php
        $presets = [
            'Today'      => [date('Y-m-d'), date('Y-m-d')],
            'Yesterday'  => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
            'This Week'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
            'This Month' => [date('Y-m-01'), date('Y-m-d')],
            'Last 30d'   => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
        ];
        foreach ($presets as $label => [$pf, $pt]):
            $active = ($df === $pf && $dt === $pt);
        ?>
        <a href="?date_from=<?= $pf ?>&date_to=<?= $pt ?>"
           class="text-[10px] px-2 py-1 rounded-md border transition-colors <?= $active ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50' ?>">
          <?= $label ?>
        </a>
        <?php endforeach; ?>
      </div>
    </form>
  </div>

  <!-- ── A. CURRENT POSITION ─────────────────────────────────────────────────── -->
  <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-2">Current Position (Live Snapshot)</p>
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">

    <!-- Bank Balance -->
    <div @click="modal = 'banks'" class="bg-blue-600 rounded-xl p-4 cursor-pointer hover:bg-blue-700 transition-colors shadow-sm">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs text-blue-100">Total Bank Balance</span>
        <i class="fas fa-university text-blue-200 text-base"></i>
      </div>
      <div class="text-2xl font-bold text-white"><?= ds_fmt($total_bank) ?></div>
      <div class="text-[10px] text-blue-200 mt-1"><?= count($bank_accounts) ?> active accounts · tap to view</div>
    </div>

    <!-- Petty Cash -->
    <div @click="modal = 'cash'" class="bg-emerald-600 rounded-xl p-4 cursor-pointer hover:bg-emerald-700 transition-colors shadow-sm">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs text-emerald-100">Cash / Petty Cash</span>
        <i class="fas fa-money-bill-wave text-emerald-200 text-base"></i>
      </div>
      <div class="text-2xl font-bold text-white"><?= ds_fmt($total_petty_cash) ?></div>
      <div class="text-[10px] text-emerald-200 mt-1">Total liquid: <strong class="text-white"><?= ds_fmt($total_position) ?></strong></div>
    </div>

    <!-- Receivables -->
    <div @click="modal = 'receivables'" class="bg-orange-500 rounded-xl p-4 cursor-pointer hover:bg-orange-600 transition-colors shadow-sm">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs text-orange-100">Outstanding Receivables</span>
        <i class="fas fa-file-invoice-dollar text-orange-200 text-base"></i>
      </div>
      <div class="text-2xl font-bold text-white"><?= ds_fmt($total_receivables) ?></div>
      <div class="text-[10px] text-orange-100 mt-1">Balance due on active orders</div>
    </div>

  </div>

  <!-- ── B. PERIOD ACTIVITY ───────────────────────────────────────────────────── -->
  <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-2">
    Period Activity
    <span class="normal-case font-normal text-gray-400">
      (<?= $df === $dt ? date('d M Y', strtotime($df)) : date('d M Y', strtotime($df)) . ' – ' . date('d M Y', strtotime($dt)) ?>)
    </span>
  </p>

  <!-- Row 1: Inflow-side activity -->
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-3">

    <!-- Collections -->
    <div @click="modal = 'collections'" class="bg-green-600 rounded-xl p-4 cursor-pointer hover:bg-green-700 transition-colors shadow-sm">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs text-green-100">Collections</span>
        <i class="fas fa-hand-holding-usd text-green-200 text-base"></i>
      </div>
      <div class="text-xl font-bold text-white"><?= ds_fmt($coll_total) ?></div>
      <div class="flex gap-2 mt-2">
        <span class="text-[10px] bg-white/20 text-white px-2 py-0.5 rounded-full">Adv <?= ds_fmt($coll_advance) ?></span>
        <span class="text-[10px] bg-white/20 text-white px-2 py-0.5 rounded-full">Due <?= ds_fmt($coll_due) ?></span>
      </div>
      <div class="text-[10px] text-green-200 mt-1"><?= $coll_count ?> payment<?= $coll_count != 1 ? 's' : '' ?></div>
    </div>

    <!-- Deliveries -->
    <div @click="modal = 'deliveries'" class="bg-indigo-600 rounded-xl p-4 cursor-pointer hover:bg-indigo-700 transition-colors shadow-sm">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs text-indigo-100">Orders Dispatched</span>
        <i class="fas fa-truck text-indigo-200 text-base"></i>
      </div>
      <div class="text-xl font-bold text-white"><?= ds_fmt($del_value) ?></div>
      <div class="text-[10px] text-indigo-200 mt-2"><?= $del_count ?> order<?= $del_count != 1 ? 's' : '' ?> shipped</div>
    </div>

    <!-- Expenses -->
    <div @click="modal = 'expenses'" class="bg-red-500 rounded-xl p-4 cursor-pointer hover:bg-red-600 transition-colors shadow-sm">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs text-red-100">Expenses</span>
        <i class="fas fa-receipt text-red-200 text-base"></i>
      </div>
      <div class="text-xl font-bold text-white"><?= ds_fmt($exp_total) ?></div>
      <div class="flex flex-wrap gap-1 mt-2">
        <?php foreach (array_slice($exp_by_cat, 0, 2) as $cat): ?>
        <span class="text-[10px] bg-white/20 text-white px-1.5 py-0.5 rounded-full truncate max-w-[120px]"><?= htmlspecialchars($cat->category) ?></span>
        <?php endforeach; ?>
        <?php if (count($exp_by_cat) > 2): ?><span class="text-[10px] text-red-200">+<?= count($exp_by_cat) - 2 ?> more</span><?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Row 2: Purchase activity -->
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">

    <!-- POs Placed -->
    <div @click="modal = 'po'" class="bg-purple-600 rounded-xl p-4 cursor-pointer hover:bg-purple-700 transition-colors shadow-sm">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs text-purple-100">Purchase Orders Placed</span>
        <i class="fas fa-clipboard-list text-purple-200 text-base"></i>
      </div>
      <div class="text-xl font-bold text-white"><?= ds_fmt($po_total_val) ?></div>
      <div class="text-[10px] text-purple-200 mt-1"><?= $po_total_cnt ?> PO<?= $po_total_cnt != 1 ? 's' : '' ?> placed</div>
    </div>

    <!-- Goods Received -->
    <div @click="modal = 'grn'" class="bg-amber-500 rounded-xl p-4 cursor-pointer hover:bg-amber-600 transition-colors shadow-sm">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs text-amber-100">Goods Received</span>
        <i class="fas fa-boxes text-amber-100 text-base"></i>
      </div>
      <div class="text-xl font-bold text-white"><?= ds_fmt($grn_total) ?></div>
      <div class="text-[10px] text-amber-100 mt-1">Value of stock received</div>
    </div>

    <!-- Supplier Payments -->
    <div @click="modal = 'supplier_paid'" class="bg-rose-600 rounded-xl p-4 cursor-pointer hover:bg-rose-700 transition-colors shadow-sm">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs text-rose-100">Paid to Suppliers</span>
        <i class="fas fa-file-invoice text-rose-200 text-base"></i>
      </div>
      <div class="text-xl font-bold text-white"><?= ds_fmt($sup_paid_total) ?></div>
      <div class="text-[10px] text-rose-200 mt-1">Wheat <?= ds_fmt($sup_paid_adn) ?> · Other <?= ds_fmt($sup_paid_std) ?></div>
    </div>

  </div>

  <!-- ── C. BANK & CASH MOVEMENT ──────────────────────────────────────────────── -->
  <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-2">Bank & Cash Movement</p>
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-5">
    <table class="w-full text-xs">
      <thead class="bg-gray-50 border-b border-gray-100">
        <tr>
          <th class="text-left py-2 px-3 text-gray-500 font-medium">Account</th>
          <th class="text-right py-2 px-3 text-gray-500 font-medium">Opening</th>
          <th class="text-right py-2 px-3 text-green-600 font-medium">+ Inflows</th>
          <th class="text-right py-2 px-3 text-red-500 font-medium">− Outflows</th>
          <th class="text-right py-2 px-3 text-gray-700 font-semibold">Closing</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($bank_movement)): ?>
        <tr><td colspan="5" class="text-center text-gray-400 py-4">No bank accounts</td></tr>
        <?php else: ?>
        <?php foreach ($bank_movement as $row): ?>
        <tr class="hover:bg-gray-50/50">
          <td class="py-1.5 px-3 text-gray-700 font-medium"><?= htmlspecialchars($row['name']) ?></td>
          <td class="py-1.5 px-3 text-right text-gray-500"><?= ds_fmt($row['opening']) ?></td>
          <td class="py-1.5 px-3 text-right text-green-600"><?= $row['inflow'] > 0 ? ds_fmt($row['inflow']) : '<span class="text-gray-300">—</span>' ?></td>
          <td class="py-1.5 px-3 text-right text-red-500"><?= $row['outflow'] > 0 ? ds_fmt($row['outflow']) : '<span class="text-gray-300">—</span>' ?></td>
          <td class="py-1.5 px-3 text-right font-semibold text-gray-800"><?= ds_fmt($row['closing']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($cash_movement)): ?>
        <!-- Petty Cash rows -->
        <?php foreach ($cash_movement as $row): ?>
        <tr class="hover:bg-gray-50/50 bg-green-50/30">
          <td class="py-1.5 px-3 text-gray-700"><i class="fas fa-coins text-green-400 mr-1 text-[10px]"></i><?= htmlspecialchars($row['name']) ?></td>
          <td class="py-1.5 px-3 text-right text-gray-500"><?= ds_fmt($row['opening']) ?></td>
          <td class="py-1.5 px-3 text-right text-green-600"><span class="text-gray-300">—</span></td>
          <td class="py-1.5 px-3 text-right text-red-500"><?= $row['outflow'] > 0 ? ds_fmt($row['outflow']) : '<span class="text-gray-300">—</span>' ?></td>
          <td class="py-1.5 px-3 text-right font-semibold text-gray-800"><?= ds_fmt($row['closing']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <tfoot class="bg-gray-800 text-white">
        <tr>
          <td class="py-2 px-3 font-semibold text-xs">Total</td>
          <td class="py-2 px-3 text-right text-xs">—</td>
          <td class="py-2 px-3 text-right text-green-300 font-semibold text-xs"><?= ds_fmt($mv_bank_inflow) ?></td>
          <td class="py-2 px-3 text-right text-red-300 font-semibold text-xs"><?= ds_fmt($mv_bank_outflow + $mv_cash_outflow) ?></td>
          <td class="py-2 px-3 text-right font-bold text-xs"><?= ds_fmt($total_position) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- ── D. NET FLOW SUMMARY ───────────────────────────────────────────────────── -->
  <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-2">Net Flow Summary</p>
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-6 max-w-lg">
    <table class="w-full text-xs">
      <tbody class="divide-y divide-gray-50">
        <tr class="hover:bg-gray-50/50">
          <td class="py-2 px-4 text-gray-600">Collections (Inflow)</td>
          <td class="py-2 px-4 text-right font-semibold text-green-700"><?= ds_fmt($coll_total) ?></td>
        </tr>
        <tr class="hover:bg-gray-50/50">
          <td class="py-2 px-4 text-gray-500 pl-8">— Advance collected</td>
          <td class="py-2 px-4 text-right text-green-600"><?= ds_fmt($coll_advance) ?></td>
        </tr>
        <tr class="hover:bg-gray-50/50">
          <td class="py-2 px-4 text-gray-500 pl-8">— Due collected</td>
          <td class="py-2 px-4 text-right text-green-600"><?= ds_fmt($coll_due) ?></td>
        </tr>
        <tr class="hover:bg-gray-50/50">
          <td class="py-2 px-4 text-gray-600">Supplier Payments (Outflow)</td>
          <td class="py-2 px-4 text-right font-semibold text-red-600"><?= ds_fmt($sup_paid_total) ?></td>
        </tr>
        <tr class="hover:bg-gray-50/50">
          <td class="py-2 px-4 text-gray-600">Expenses (Outflow)</td>
          <td class="py-2 px-4 text-right font-semibold text-red-600"><?= ds_fmt($exp_total) ?></td>
        </tr>
        <tr class="bg-gray-50">
          <td class="py-2 px-4 text-gray-700 font-semibold">Total Outflows</td>
          <td class="py-2 px-4 text-right font-semibold text-red-700"><?= ds_fmt($net_outflow) ?></td>
        </tr>
      </tbody>
      <tfoot>
        <tr class="<?= $net_flow >= 0 ? 'bg-green-700' : 'bg-red-700' ?> text-white">
          <td class="py-2.5 px-4 font-bold">Net Flow</td>
          <td class="py-2.5 px-4 text-right font-bold"><?= ds_fmt($net_flow) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- ════════════════════════════════════════════════════════════════════════════ -->
  <!-- MODALS                                                                       -->
  <!-- ════════════════════════════════════════════════════════════════════════════ -->

  <!-- Modal Backdrop -->
  <div x-show="modal !== ''" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="modal = ''" class="fixed inset-0 bg-black/40 z-40" style="display:none;"></div>

  <!-- Modal Panel Wrapper -->
  <div x-show="modal !== ''" class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none;" @keydown.escape.window="modal = ''">
    <div @click.stop class="bg-white rounded-xl shadow-2xl w-full max-h-[85vh] overflow-y-auto" :class="modal === 'banks' || modal === 'cash' || modal === 'receivables' ? 'max-w-md' : 'max-w-3xl'">

      <!-- ── Modal: Bank Accounts ── -->
      <template x-if="modal === 'banks'">
        <div>
          <div class="flex items-center justify-between p-4 border-b border-gray-100">
            <h2 class="text-sm font-bold text-gray-800"><i class="fas fa-university text-blue-400 mr-2"></i>Bank Account Balances</h2>
            <button @click="modal = ''" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
          </div>
          <div class="p-4">
            <table class="w-full text-xs">
              <thead><tr class="text-left border-b border-gray-100"><th class="pb-1 text-gray-500">Account</th><th class="pb-1 text-right text-gray-500">Balance</th></tr></thead>
              <tbody class="divide-y divide-gray-50">
                <?php foreach ($bank_accounts as $ba): ?>
                <tr class="py-1">
                  <td class="py-1.5 text-gray-700"><?= htmlspecialchars($ba->account_name) ?><?= $ba->bank_name ? ' <span class="text-gray-400">— ' . htmlspecialchars($ba->bank_name) . '</span>' : '' ?></td>
                  <td class="py-1.5 text-right font-semibold text-gray-800"><?= ds_fmt((float)$ba->current_balance) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($bank_accounts)): ?><tr><td colspan="2" class="text-center text-gray-400 py-4">No active bank accounts</td></tr><?php endif; ?>
              </tbody>
              <tfoot><tr class="border-t border-gray-200 font-bold"><td class="pt-2 text-gray-700">Total</td><td class="pt-2 text-right text-blue-700"><?= ds_fmt($total_bank) ?></td></tr></tfoot>
            </table>
          </div>
        </div>
      </template>

      <!-- ── Modal: Petty Cash ── -->
      <template x-if="modal === 'cash'">
        <div>
          <div class="flex items-center justify-between p-4 border-b border-gray-100">
            <h2 class="text-sm font-bold text-gray-800"><i class="fas fa-money-bill-wave text-green-400 mr-2"></i>Cash / Petty Cash Accounts</h2>
            <button @click="modal = ''" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
          </div>
          <div class="p-4">
            <table class="w-full text-xs">
              <thead><tr class="text-left border-b border-gray-100"><th class="pb-1 text-gray-500">Account</th><th class="pb-1 text-right text-gray-500">Balance</th></tr></thead>
              <tbody class="divide-y divide-gray-50">
                <?php foreach ($petty_accounts as $pa): ?>
                <tr><td class="py-1.5 text-gray-700"><?= htmlspecialchars($pa->account_name) ?></td><td class="py-1.5 text-right font-semibold"><?= ds_fmt((float)$pa->current_balance) ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($petty_accounts)): ?><tr><td colspan="2" class="text-center text-gray-400 py-4">No petty cash accounts</td></tr><?php endif; ?>
              </tbody>
              <tfoot><tr class="border-t border-gray-200 font-bold"><td class="pt-2 text-gray-700">Total</td><td class="pt-2 text-right text-green-700"><?= ds_fmt($total_petty_cash) ?></td></tr></tfoot>
            </table>
            <?php if ($cash_coll > 0): ?>
            <p class="text-[10px] text-gray-400 mt-3">Cash collections in period: <strong class="text-gray-600"><?= ds_fmt($cash_coll) ?></strong></p>
            <?php endif; ?>
          </div>
        </div>
      </template>

      <!-- ── Modal: Receivables ── -->
      <template x-if="modal === 'receivables'">
        <div>
          <div class="flex items-center justify-between p-4 border-b border-gray-100">
            <h2 class="text-sm font-bold text-gray-800"><i class="fas fa-file-invoice-dollar text-orange-400 mr-2"></i>Outstanding Receivables</h2>
            <button @click="modal = ''" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
          </div>
          <div class="p-4 text-xs text-gray-600">
            <div class="bg-orange-50 rounded-lg p-4 text-center">
              <div class="text-3xl font-bold text-orange-700 mb-1"><?= ds_fmt($total_receivables) ?></div>
              <div class="text-[10px] text-orange-500">Total balance due on active credit orders</div>
            </div>
            <p class="text-[10px] text-gray-400 mt-3">This is a live snapshot — not filtered by date range. For detailed breakdown, visit the <a href="../cr/ageing_report.php" class="text-blue-500 hover:underline">Ageing Report</a> or <a href="../customers/index.php" class="text-blue-500 hover:underline">Customer Ledger</a>.</p>
          </div>
        </div>
      </template>

      <!-- ── Modal: Collections ── -->
      <template x-if="modal === 'collections'">
        <div>
          <div class="flex items-center justify-between p-4 border-b border-gray-100">
            <h2 class="text-sm font-bold text-gray-800"><i class="fas fa-hand-holding-usd text-green-500 mr-2"></i>Collections Detail</h2>
            <button @click="modal = ''" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
          </div>
          <div class="flex gap-3 px-4 pt-3 pb-2">
            <div class="bg-green-50 rounded-lg px-3 py-2 flex-1 text-center">
              <div class="text-xs text-green-600 font-semibold"><?= ds_fmt($coll_total) ?></div>
              <div class="text-[10px] text-green-500">Total</div>
            </div>
            <div class="bg-blue-50 rounded-lg px-3 py-2 flex-1 text-center">
              <div class="text-xs text-blue-600 font-semibold"><?= ds_fmt($coll_advance) ?></div>
              <div class="text-[10px] text-blue-500">Advance</div>
            </div>
            <div class="bg-emerald-50 rounded-lg px-3 py-2 flex-1 text-center">
              <div class="text-xs text-emerald-600 font-semibold"><?= ds_fmt($coll_due) ?></div>
              <div class="text-[10px] text-emerald-500">Due Collected</div>
            </div>
          </div>
          <div class="overflow-x-auto px-1 pb-4">
            <table class="w-full text-xs">
              <thead class="bg-gray-50"><tr class="text-left">
                <th class="py-1.5 px-3 text-gray-500 font-medium">Ref</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Date</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Customer</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Type</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Via</th>
                <th class="py-1.5 px-3 text-right text-gray-500 font-medium">Amount</th>
              </tr></thead>
              <tbody class="divide-y divide-gray-50">
                <?php foreach ($coll_detail as $r): ?>
                <tr class="hover:bg-gray-50/50">
                  <td class="py-1 px-3 text-blue-600"><?= htmlspecialchars($r->payment_number ?? '—') ?></td>
                  <td class="py-1 px-3 text-gray-500"><?= htmlspecialchars($r->pdate ?? '') ?></td>
                  <td class="py-1 px-3 text-gray-700"><?= htmlspecialchars($r->customer_name) ?></td>
                  <td class="py-1 px-3">
                    <span class="<?= $r->payment_type === 'advance' ? 'bg-blue-50 text-blue-600' : 'bg-green-50 text-green-600' ?> text-[10px] px-1.5 py-0.5 rounded-full">
                      <?= $r->payment_type === 'advance' ? 'Advance' : 'Invoice' ?>
                    </span>
                  </td>
                  <td class="py-1 px-3 text-gray-500"><?= htmlspecialchars($r->bank_name) ?></td>
                  <td class="py-1 px-3 text-right font-semibold text-green-700"><?= ds_fmt((float)$r->amount) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($coll_detail)): ?><tr><td colspan="6" class="text-center text-gray-400 py-6">No collections in this period</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      <!-- ── Modal: Deliveries ── -->
      <template x-if="modal === 'deliveries'">
        <div>
          <div class="flex items-center justify-between p-4 border-b border-gray-100">
            <h2 class="text-sm font-bold text-gray-800"><i class="fas fa-truck text-indigo-500 mr-2"></i>Orders Dispatched</h2>
            <button @click="modal = ''" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
          </div>
          <div class="flex gap-3 px-4 pt-3 pb-2">
            <div class="bg-indigo-50 rounded-lg px-3 py-2 flex-1 text-center">
              <div class="text-xs font-semibold text-indigo-700"><?= ds_fmt($del_value) ?></div>
              <div class="text-[10px] text-indigo-500">Total Value</div>
            </div>
            <div class="bg-gray-50 rounded-lg px-3 py-2 flex-1 text-center">
              <div class="text-xs font-semibold text-gray-700"><?= $del_count ?></div>
              <div class="text-[10px] text-gray-500">Orders</div>
            </div>
          </div>
          <div class="overflow-x-auto px-1 pb-4">
            <table class="w-full text-xs">
              <thead class="bg-gray-50"><tr class="text-left">
                <th class="py-1.5 px-3 text-gray-500 font-medium">Order #</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Shipped</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Customer</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Status</th>
                <th class="py-1.5 px-3 text-right text-gray-500 font-medium">Value</th>
                <th class="py-1.5 px-3 text-right text-gray-500 font-medium">Due</th>
              </tr></thead>
              <tbody class="divide-y divide-gray-50">
                <?php foreach ($del_detail as $r): ?>
                <tr class="hover:bg-gray-50/50">
                  <td class="py-1 px-3 text-blue-600"><?= htmlspecialchars($r->order_number ?? '—') ?></td>
                  <td class="py-1 px-3 text-gray-500"><?= htmlspecialchars($r->shipped_date ?? '') ?></td>
                  <td class="py-1 px-3 text-gray-700"><?= htmlspecialchars($r->customer_name) ?></td>
                  <td class="py-1 px-3"><span class="text-[10px] px-1.5 py-0.5 rounded-full bg-blue-50 text-blue-600"><?= htmlspecialchars(ucfirst($r->status ?? '')) ?></span></td>
                  <td class="py-1 px-3 text-right font-semibold text-gray-800"><?= ds_fmt((float)$r->total_amount) ?></td>
                  <td class="py-1 px-3 text-right <?= (float)$r->balance_due > 0 ? 'text-red-500' : 'text-green-500' ?>"><?= ds_fmt((float)$r->balance_due) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($del_detail)): ?><tr><td colspan="6" class="text-center text-gray-400 py-6">No dispatches in this period</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      <!-- ── Modal: Purchase Orders ── -->
      <template x-if="modal === 'po'">
        <div>
          <div class="flex items-center justify-between p-4 border-b border-gray-100">
            <h2 class="text-sm font-bold text-gray-800"><i class="fas fa-clipboard-list text-purple-500 mr-2"></i>Purchase Orders Placed</h2>
            <button @click="modal = ''" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
          </div>
          <div class="flex gap-3 px-4 pt-3 pb-2">
            <div class="bg-purple-50 rounded-lg px-3 py-2 flex-1 text-center">
              <div class="text-xs font-semibold text-purple-700"><?= ds_fmt($po_total_val) ?></div>
              <div class="text-[10px] text-purple-500">Total Value</div>
            </div>
            <div class="bg-gray-50 rounded-lg px-3 py-2 flex-1 text-center">
              <div class="text-xs font-semibold text-gray-700"><?= $po_total_cnt ?></div>
              <div class="text-[10px] text-gray-500">POs Placed</div>
            </div>
          </div>
          <?php if (!empty($po_adn_detail)): ?>
          <p class="text-[10px] text-gray-400 px-4 mb-1">Wheat Procurement POs:</p>
          <div class="overflow-x-auto px-1 pb-4">
            <table class="w-full text-xs">
              <thead class="bg-gray-50"><tr class="text-left">
                <th class="py-1.5 px-3 text-gray-500 font-medium">PO #</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Date</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Supplier</th>
                <th class="py-1.5 px-3 text-right text-gray-500 font-medium">Qty (kg)</th>
                <th class="py-1.5 px-3 text-right text-gray-500 font-medium">Rate</th>
                <th class="py-1.5 px-3 text-right text-gray-500 font-medium">Value</th>
              </tr></thead>
              <tbody class="divide-y divide-gray-50">
                <?php foreach ($po_adn_detail as $r): ?>
                <tr class="hover:bg-gray-50/50">
                  <td class="py-1 px-3 text-blue-600"><?= htmlspecialchars($r->po_number) ?></td>
                  <td class="py-1 px-3 text-gray-500"><?= htmlspecialchars($r->po_date) ?></td>
                  <td class="py-1 px-3 text-gray-700"><?= htmlspecialchars($r->supplier_name) ?></td>
                  <td class="py-1 px-3 text-right text-gray-600"><?= number_format((float)$r->quantity_kg, 0) ?></td>
                  <td class="py-1 px-3 text-right text-gray-600">৳<?= number_format((float)$r->unit_price_per_kg, 2) ?></td>
                  <td class="py-1 px-3 text-right font-semibold text-purple-700"><?= ds_fmt((float)$r->total_order_value) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php elseif ($po_total_cnt === 0): ?>
          <p class="text-center text-gray-400 py-6 text-xs">No purchase orders placed in this period</p>
          <?php endif; ?>
        </div>
      </template>

      <!-- ── Modal: Goods Received ── -->
      <template x-if="modal === 'grn'">
        <div>
          <div class="flex items-center justify-between p-4 border-b border-gray-100">
            <h2 class="text-sm font-bold text-gray-800"><i class="fas fa-boxes text-yellow-500 mr-2"></i>Goods Received</h2>
            <button @click="modal = ''" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
          </div>
          <div class="flex gap-3 px-4 pt-3 pb-2">
            <div class="bg-yellow-50 rounded-lg px-3 py-2 flex-1 text-center">
              <div class="text-xs font-semibold text-yellow-700"><?= ds_fmt($grn_total) ?></div>
              <div class="text-[10px] text-yellow-600">Total Value Received</div>
            </div>
            <div class="bg-gray-50 rounded-lg px-3 py-2 flex-1 text-center">
              <div class="text-xs font-semibold text-gray-700"><?= ds_fmt($grn_adn_val) ?> wheat</div>
              <div class="text-[10px] text-gray-500"><?= ds_fmt($grn_std_val) ?> other</div>
            </div>
          </div>
          <?php if (!empty($grn_adn_detail)): ?>
          <p class="text-[10px] text-gray-400 px-4 mb-1">Wheat GRNs:</p>
          <div class="overflow-x-auto px-1 pb-4">
            <table class="w-full text-xs">
              <thead class="bg-gray-50"><tr class="text-left">
                <th class="py-1.5 px-3 text-gray-500 font-medium">GRN #</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Date</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Supplier</th>
                <th class="py-1.5 px-3 text-right text-gray-500 font-medium">Qty (kg)</th>
                <th class="py-1.5 px-3 text-right text-gray-500 font-medium">Value</th>
              </tr></thead>
              <tbody class="divide-y divide-gray-50">
                <?php foreach ($grn_adn_detail as $r): ?>
                <tr class="hover:bg-gray-50/50">
                  <td class="py-1 px-3 text-blue-600"><?= htmlspecialchars($r->grn_number) ?></td>
                  <td class="py-1 px-3 text-gray-500"><?= htmlspecialchars($r->grn_date) ?></td>
                  <td class="py-1 px-3 text-gray-700"><?= htmlspecialchars($r->supplier_name) ?></td>
                  <td class="py-1 px-3 text-right text-gray-600"><?= number_format((float)$r->quantity_received_kg, 0) ?></td>
                  <td class="py-1 px-3 text-right font-semibold text-yellow-700"><?= ds_fmt((float)$r->total_value) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php elseif ($grn_total == 0): ?>
          <p class="text-center text-gray-400 py-6 text-xs">No goods received in this period</p>
          <?php endif; ?>
        </div>
      </template>

      <!-- ── Modal: Supplier Payments ── -->
      <template x-if="modal === 'supplier_paid'">
        <div>
          <div class="flex items-center justify-between p-4 border-b border-gray-100">
            <h2 class="text-sm font-bold text-gray-800"><i class="fas fa-file-invoice text-rose-500 mr-2"></i>Paid to Suppliers</h2>
            <button @click="modal = ''" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
          </div>
          <div class="flex gap-3 px-4 pt-3 pb-2">
            <div class="bg-rose-50 rounded-lg px-3 py-2 flex-1 text-center">
              <div class="text-xs font-semibold text-rose-700"><?= ds_fmt($sup_paid_total) ?></div>
              <div class="text-[10px] text-rose-500">Total Paid</div>
            </div>
            <div class="bg-gray-50 rounded-lg px-3 py-2 flex-1 text-center">
              <div class="text-xs font-semibold text-gray-700"><?= ds_fmt($sup_paid_adn) ?> wheat</div>
              <div class="text-[10px] text-gray-500"><?= ds_fmt($sup_paid_std) ?> other</div>
            </div>
          </div>
          <?php if (!empty($sup_paid_detail)): ?>
          <p class="text-[10px] text-gray-400 px-4 mb-1">Wheat Supplier Payments:</p>
          <div class="overflow-x-auto px-1">
            <table class="w-full text-xs">
              <thead class="bg-gray-50"><tr class="text-left">
                <th class="py-1.5 px-3 text-gray-500 font-medium">Ref</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Date</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Supplier</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Method</th>
                <th class="py-1.5 px-3 text-right text-gray-500 font-medium">Amount</th>
              </tr></thead>
              <tbody class="divide-y divide-gray-50">
                <?php foreach ($sup_paid_detail as $r): ?>
                <tr class="hover:bg-gray-50/50">
                  <td class="py-1 px-3 text-blue-600"><?= htmlspecialchars($r->payment_number ?? '—') ?></td>
                  <td class="py-1 px-3 text-gray-500"><?= htmlspecialchars($r->payment_date) ?></td>
                  <td class="py-1 px-3 text-gray-700"><?= htmlspecialchars($r->supplier_name) ?></td>
                  <td class="py-1 px-3 text-gray-500"><?= htmlspecialchars(ucfirst(str_replace('_',' ',$r->payment_method ?? ''))) ?></td>
                  <td class="py-1 px-3 text-right font-semibold text-rose-700"><?= ds_fmt((float)$r->amount_paid) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
          <?php if (!empty($sup_paid_std_detail)): ?>
          <p class="text-[10px] text-gray-400 px-4 mt-3 mb-1">Other Supplier Payments:</p>
          <div class="overflow-x-auto px-1 pb-4">
            <table class="w-full text-xs">
              <thead class="bg-gray-50"><tr class="text-left">
                <th class="py-1.5 px-3 text-gray-500 font-medium">Ref</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Date</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Supplier</th>
                <th class="py-1.5 px-3 text-right text-gray-500 font-medium">Amount</th>
              </tr></thead>
              <tbody class="divide-y divide-gray-50">
                <?php foreach ($sup_paid_std_detail as $r): ?>
                <tr class="hover:bg-gray-50/50">
                  <td class="py-1 px-3 text-blue-600"><?= htmlspecialchars($r->payment_number ?? '—') ?></td>
                  <td class="py-1 px-3 text-gray-500"><?= htmlspecialchars($r->payment_date) ?></td>
                  <td class="py-1 px-3 text-gray-700"><?= htmlspecialchars($r->supplier_name) ?></td>
                  <td class="py-1 px-3 text-right font-semibold text-rose-700"><?= ds_fmt((float)$r->amount) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
          <?php if (empty($sup_paid_detail) && empty($sup_paid_std_detail)): ?>
          <p class="text-center text-gray-400 py-6 text-xs">No supplier payments in this period</p>
          <?php endif; ?>
        </div>
      </template>

      <!-- ── Modal: Expenses ── -->
      <template x-if="modal === 'expenses'">
        <div>
          <div class="flex items-center justify-between p-4 border-b border-gray-100">
            <h2 class="text-sm font-bold text-gray-800"><i class="fas fa-receipt text-red-400 mr-2"></i>Expenses</h2>
            <button @click="modal = ''" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
          </div>
          <!-- By category summary -->
          <?php if (!empty($exp_by_cat)): ?>
          <div class="px-4 pt-3 pb-2">
            <p class="text-[10px] text-gray-400 mb-2 font-semibold uppercase">By Category</p>
            <div class="space-y-1">
              <?php foreach ($exp_by_cat as $cat):
                $pct = $exp_total > 0 ? round(((float)$cat->cat_total / $exp_total) * 100) : 0;
              ?>
              <div class="flex items-center gap-2">
                <div class="flex-1 text-xs text-gray-700 truncate"><?= htmlspecialchars($cat->category) ?></div>
                <div class="w-20 bg-gray-100 rounded-full h-1.5">
                  <div class="bg-red-400 h-1.5 rounded-full" style="width:<?= $pct ?>%"></div>
                </div>
                <div class="text-xs text-right text-red-600 font-semibold w-20"><?= ds_fmt((float)$cat->cat_total) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="mx-4 my-2 border-t border-gray-100"></div>
          <?php endif; ?>
          <!-- Detail table -->
          <div class="overflow-x-auto px-1 pb-4">
            <table class="w-full text-xs">
              <thead class="bg-gray-50"><tr class="text-left">
                <th class="py-1.5 px-3 text-gray-500 font-medium">Voucher</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Date</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Category</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Remarks</th>
                <th class="py-1.5 px-3 text-gray-500 font-medium">Via</th>
                <th class="py-1.5 px-3 text-right text-gray-500 font-medium">Amount</th>
              </tr></thead>
              <tbody class="divide-y divide-gray-50">
                <?php foreach ($exp_detail as $r): ?>
                <tr class="hover:bg-gray-50/50">
                  <td class="py-1 px-3 text-blue-600"><?= htmlspecialchars($r->voucher_number ?? '—') ?></td>
                  <td class="py-1 px-3 text-gray-500"><?= htmlspecialchars($r->expense_date) ?></td>
                  <td class="py-1 px-3 text-gray-700"><?= htmlspecialchars($r->category ?? '—') ?></td>
                  <td class="py-1 px-3 text-gray-500 max-w-[150px] truncate"><?= htmlspecialchars($r->remarks ?? '') ?></td>
                  <td class="py-1 px-3"><span class="text-[10px] px-1.5 py-0.5 rounded-full <?= $r->payment_method === 'bank' ? 'bg-blue-50 text-blue-600' : 'bg-green-50 text-green-600' ?>"><?= ucfirst($r->payment_method ?? '') ?></span></td>
                  <td class="py-1 px-3 text-right font-semibold text-red-600"><?= ds_fmt((float)$r->total_amount) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($exp_detail)): ?><tr><td colspan="6" class="text-center text-gray-400 py-6">No expenses in this period</td></tr><?php endif; ?>
              </tbody>
              <?php if (!empty($exp_detail)): ?>
              <tfoot class="bg-gray-50 border-t border-gray-200">
                <tr><td colspan="5" class="py-2 px-3 font-semibold text-gray-700">Total</td><td class="py-2 px-3 text-right font-bold text-red-700"><?= ds_fmt($exp_total) ?></td></tr>
              </tfoot>
              <?php endif; ?>
            </table>
          </div>
        </div>
      </template>

    </div><!-- /modal panel -->
  </div><!-- /modal wrapper -->

</div><!-- /page -->

<?php require_once '../templates/footer.php'; ?>
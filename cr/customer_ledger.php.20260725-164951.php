<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$pageTitle = 'Customer Ledger';

// Get all customers
// FIX: Schema has 'phone_number', not 'phone'. Aliasing it to 'phone' to keep variable consistent.
$customers = $db->query(
    "SELECT id, name, phone_number as phone, business_name
     FROM customers
     WHERE status = 'active'
     ORDER BY name ASC"
)->results();

// Feature #2: overview of EVERY active customer with their true running balance
// (initial_due + ledger debits − credits), so all customers — including zero and
// advance balances — are visible in one place, not only those with orders.
$all_customer_balances = $db->query(
    "SELECT c.id, c.name, c.phone_number,
            COALESCE(c.initial_due, 0)
                + COALESCE(tb.total_debit,  0)
                - COALESCE(tb.total_credit, 0) AS true_balance
     FROM customers c
     LEFT JOIN (
         SELECT customer_id,
                SUM(debit_amount)  AS total_debit,
                SUM(credit_amount) AS total_credit
         FROM customer_ledger
         GROUP BY customer_id
     ) tb ON tb.customer_id = c.id
     WHERE c.status = 'active'
     ORDER BY true_balance DESC, c.name ASC"
)->results();

// Get selected customer
$selected_customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01'); // First day of month
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d'); // Today

$customer_info  = null;
$ledger_entries = [];
$summary        = null;
$is_superadmin  = ($currentUser['role'] ?? '') === 'Superadmin';
$is_admin       = in_array(($currentUser['role'] ?? ''), ['Superadmin', 'admin'], true);

/* ─── Feature #3: post a manual reconciliation / adjustment entry ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'post_adjustment' && $is_admin) {
    $adj_cust  = (int)($_POST['customer_id'] ?? 0);
    $direction = in_array($_POST['direction'] ?? '', ['debit', 'credit'], true) ? $_POST['direction'] : '';
    $adj_amt   = round((float)($_POST['amount'] ?? 0), 2);
    $adj_reason= trim($_POST['reason'] ?? '');
    $adj_date  = ($_POST['adj_date'] ?? '') !== '' ? $_POST['adj_date'] : date('Y-m-d');
    try {
        $sess_tok = $_SESSION['csrf_token'] ?? '';
        if (!$sess_tok || !hash_equals($sess_tok, $_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token — refresh the page and try again.');
        }
        if (!$adj_cust)            throw new Exception('No customer selected.');
        if ($direction === '')     throw new Exception('Choose Debit (increase due) or Credit (decrease due).');
        if ($adj_amt <= 0)         throw new Exception('Amount must be greater than zero.');
        if ($adj_reason === '')    throw new Exception('A reason is required for an adjustment.');

        // Running balance = last balance_after, else the customer's opening figure.
        $last = $db->query("SELECT balance_after FROM customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1", [$adj_cust])->first();
        if ($last) {
            $curr_bal = (float)$last->balance_after;
        } else {
            $cb = $db->query("SELECT COALESCE(current_balance, initial_due, 0) AS b FROM customers WHERE id = ?", [$adj_cust])->first();
            $curr_bal = (float)($cb->b ?? 0);
        }
        $debit  = $direction === 'debit'  ? $adj_amt : 0.0;
        $credit = $direction === 'credit' ? $adj_amt : 0.0;
        $new_bal = $curr_bal + $debit - $credit;   // balance_after = prev + debit − credit

        $pdo = $db->getPdo();
        $pdo->beginTransaction();
        $adj_id = $db->insert('customer_ledger', [
            'customer_id'        => $adj_cust,
            'transaction_date'   => $adj_date,
            'transaction_type'   => 'adjustment',
            'reference_type'     => 'manual_adjustment',
            'reference_id'       => null,
            'invoice_number'     => null,
            'description'        => 'Adjustment: ' . $adj_reason,
            'debit_amount'       => $debit,
            'credit_amount'      => $credit,
            'balance_after'      => $new_bal,
            'created_by_user_id' => (int)($currentUser['id'] ?? 0),
        ]);
        $db->query("UPDATE customers SET current_balance = ? WHERE id = ?", [$new_bal, $adj_cust]);
        $pdo->commit();

        auditLog('customer_ledger', 'adjustment',
            'Manual ledger adjustment (' . strtoupper($direction) . ' ৳' . number_format($adj_amt, 2) . ') on customer #' . $adj_cust
            . ' by ' . ($currentUser['display_name'] ?? 'admin') . ' — ' . $adj_reason,
            ['ledger_id' => $adj_id, 'customer_id' => $adj_cust, 'direction' => $direction, 'amount' => $adj_amt]);

        $_SESSION['success_flash'] = 'Adjustment posted: ' . ($direction === 'debit' ? '+' : '−') . '৳' . number_format($adj_amt, 2) . '. New balance ৳' . number_format($new_bal, 2) . '.';
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_flash'] = $e->getMessage();
    }
    header("Location: customer_ledger.php?customer_id={$adj_cust}");
    exit();
}

/* ─── Superadmin: Delete ledger entry + related records ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_ledger_entry' && $is_superadmin) {
    $entry_id = (int)($_POST['entry_id'] ?? 0);
    if ($entry_id) {
        try {
            $sess_tok = $_SESSION['csrf_token'] ?? '';
            if (!$sess_tok || !hash_equals($sess_tok, $_POST['csrf_token'] ?? '')) {
                throw new Exception('Invalid security token — refresh the page and try again.');
            }
            $entry = $db->query("SELECT * FROM customer_ledger WHERE id = ?", [$entry_id])->first();
            if (!$entry) throw new Exception("Ledger entry not found.");

            // Create the recycle tables (if missing) BEFORE opening the transaction —
            // CREATE TABLE implicit-commits, which would break the transaction below.
            ensureRecycleBinTables();

            $pdo = $db->getPdo();
            $pdo->beginTransaction();

            // Recalculate customer balance by removing this entry's impact
            $debit_amt  = (float)$entry->debit_amount;
            $credit_amt = (float)$entry->credit_amount;
            // Debit increases balance, credit decreases it
            $balance_correction = $credit_amt - $debit_amt;  // reversal

            $ref_type = $entry->reference_type ?? '';
            $ref_id   = (int)($entry->reference_id ?? 0);

            // Feature #3: soft-delete. Snapshot everything into a recycle batch and
            // remove from live tables — fully restorable from admin/recycle_bin.php.
            // Child rows are archived first, parents last, so the restore (which
            // re-inserts in reverse capture order) brings parents back first.
            $batch = recycleBegin('ledger_entry',
                "Ledger #{$entry_id} ({$ref_type}) — " . ($entry->description ?? '') . " · impact ৳" . number_format(abs($debit_amt - $credit_amt), 2),
                (int)$entry->customer_id);

            if ($ref_id) {
                if (in_array($ref_type, ['credit_order','credit_orders'])) {
                    recycleArchiveDelete($batch, 'credit_order_workflow', 'order_id', $ref_id);
                    recycleArchiveDelete($batch, 'credit_order_shipping',  'order_id', $ref_id);
                    recycleArchiveDelete($batch, 'production_schedule',    'order_id', $ref_id);
                    // delivery items → snapshot by their own delivery ids, then deliveries
                    try {
                        foreach ($db->query("SELECT id FROM credit_order_deliveries WHERE order_id = ?", [$ref_id])->results() ?: [] as $d) {
                            recycleArchiveDelete($batch, 'credit_order_delivery_items', 'delivery_id', (int)$d->id);
                        }
                    } catch (Exception $e2) {}
                    recycleArchiveDelete($batch, 'credit_order_deliveries', 'order_id', $ref_id);
                    try {
                        foreach ($db->query("SELECT id FROM credit_order_returns WHERE order_id = ?", [$ref_id])->results() ?: [] as $rr) {
                            recycleArchiveDelete($batch, 'credit_order_return_items', 'return_id', (int)$rr->id);
                        }
                    } catch (Exception $e2) {}
                    recycleArchiveDelete($batch, 'credit_order_returns',   'order_id', $ref_id);
                    recycleArchiveDelete($batch, 'payment_allocations',    'order_id', $ref_id);
                    recycleArchiveDelete($batch, 'credit_order_items',     'order_id', $ref_id);
                    recycleArchiveDelete($batch, 'credit_orders',          'id',       $ref_id);
                } elseif (in_array($ref_type, ['customer_payments'])) {
                    // Reverse each order's paid columns; snapshot the BEFORE-image first
                    // so a restore puts amount_paid / balance_due back exactly.
                    $pay_row = null; $allocs = [];
                    try {
                        $pay_row = $db->query("SELECT payment_type FROM customer_payments WHERE id = ?", [$ref_id])->first();
                    } catch(Exception $e2){}
                    $is_advance_pay = $pay_row && stripos((string)$pay_row->payment_type, 'advance') !== false;
                    $paid_col = $is_advance_pay ? 'advance_paid' : 'amount_paid';
                    try {
                        $allocs = $db->query(
                            "SELECT order_id, allocated_amount FROM payment_allocations WHERE payment_id = ?",
                            [$ref_id]
                        )->results() ?: [];
                    } catch(Exception $e2){}
                    foreach ($allocs as $alloc) {
                        recycleSnapshotBefore($batch, 'credit_orders', 'id', (int)$alloc->order_id);
                        try {
                            $db->query(
                                "UPDATE credit_orders
                                 SET {$paid_col} = GREATEST(0, {$paid_col} - ?),
                                     balance_due = balance_due + ?
                                 WHERE id = ?",
                                [(float)$alloc->allocated_amount,
                                 (float)$alloc->allocated_amount,
                                 (int)$alloc->order_id]
                            );
                        } catch(Exception $e2){}
                    }
                    recycleArchiveDelete($batch, 'payment_allocations', 'payment_id', $ref_id);
                    recycleArchiveDelete($batch, 'customer_payments',   'id',         $ref_id);
                } elseif (in_array($ref_type, ['credit_order_returns'])) {
                    recycleArchiveDelete($batch, 'credit_order_return_items', 'return_id', $ref_id);
                    recycleArchiveDelete($batch, 'credit_order_returns',      'id',        $ref_id);
                } elseif (in_array($ref_type, ['credit_order_deliveries'])) {
                    recycleArchiveDelete($batch, 'credit_order_delivery_items', 'delivery_id', $ref_id);
                    recycleArchiveDelete($batch, 'credit_order_deliveries',     'id',          $ref_id);
                }
            }

            // Archive + delete the ledger entry itself
            recycleArchiveDelete($batch, 'customer_ledger', 'id', $entry_id);

            // Correct customer balance (snapshot before-image so restore reverts it)
            recycleSnapshotBefore($batch, 'customers', 'id', (int)$entry->customer_id);
            $db->query(
                "UPDATE customers SET current_balance = GREATEST(0, current_balance + ?) WHERE id = ?",
                [$balance_correction, $entry->customer_id]
            );

            // Re-run balance chain from this point (recalculate balance_after for subsequent entries)
            $subsequent = $db->query(
                "SELECT id, debit_amount, credit_amount FROM customer_ledger
                 WHERE customer_id = ? AND id > ?
                 ORDER BY transaction_date ASC, id ASC",
                [$entry->customer_id, $entry_id]
            )->results();

            if (!empty($subsequent)) {
                $prev_bal_row = $db->query(
                    "SELECT balance_after FROM customer_ledger WHERE customer_id = ? AND id < ? ORDER BY id DESC LIMIT 1",
                    [$entry->customer_id, min(array_column((array)$subsequent, 'id'))]
                )->first();
                $running_bal = $prev_bal_row ? (float)$prev_bal_row->balance_after : (float)$db->query("SELECT initial_due FROM customers WHERE id = ?", [$entry->customer_id])->first()->initial_due;
                foreach ($subsequent as $sub) {
                    // Snapshot each row's balance_after BEFORE rewriting it
                    recycleSnapshotBefore($batch, 'customer_ledger', 'id', (int)$sub->id);
                    $running_bal = $running_bal + (float)$sub->debit_amount - (float)$sub->credit_amount;
                    $db->query("UPDATE customer_ledger SET balance_after = ? WHERE id = ?", [$running_bal, $sub->id]);
                }
            }

            recycleFinalize($batch);

            $pdo->commit();

            auditLog('customer_ledger', 'soft_deleted',
                "Superadmin moved ledger entry #{$entry_id} ref:{$ref_type}#{$ref_id} to Recycle Bin (batch #{$batch}) — impact ৳" . number_format(abs($debit_amt - $credit_amt), 2),
                ['entry_id' => $entry_id, 'ref_type' => $ref_type, 'ref_id' => $ref_id, 'batch_id' => $batch]
            );

            $_SESSION['success_flash'] = "Ledger entry #{$entry_id} and related records moved to the Recycle Bin (batch #{$batch}) — restorable from Admin → Recycle Bin.";
            header("Location: customer_ledger.php?customer_id={$entry->customer_id}&date_from={$_POST['date_from']}&date_to={$_POST['date_to']}");
            exit();

        } catch (Exception $e) {
            if ($db->getPdo()->inTransaction()) $db->getPdo()->rollBack();
            $_SESSION['error_flash'] = "Delete failed: " . $e->getMessage();
            header("Location: customer_ledger.php?customer_id={$_POST['customer_id']}&date_from={$_POST['date_from']}&date_to={$_POST['date_to']}");
            exit();
        }
    }
}

if ($selected_customer_id) {
    // Get customer info with credit details
    $customer_info = $db->query(
            "SELECT id, name, phone_number, email, credit_limit, initial_due, current_balance
             FROM customers
             WHERE id = ?",
            [$selected_customer_id]
        )->first();

    
    if ($customer_info) {
        $initial_due = (float)($customer_info->initial_due ?? 0);

        // True current balance: initial_due + all non-OB debits - all credits.
        // OB entries (reference_type='initial_due') are excluded from the aggregate
        // because initial_due is added explicitly — this works whether the OB entry
        // stored debit_amount=initial_due or debit_amount=0.
        $all_totals = $db->query(
            "SELECT COALESCE(SUM(debit_amount), 0)  AS total_debit,
                    COALESCE(SUM(credit_amount), 0) AS total_credit
             FROM customer_ledger
             WHERE customer_id = ? AND reference_type != 'initial_due'",
            [$selected_customer_id]
        )->first();
        $agg_td = (float)($all_totals->total_debit  ?? 0);
        $agg_tc = (float)($all_totals->total_credit ?? 0);
        $true_current_balance = $initial_due + $agg_td - $agg_tc;

        // Get ledger entries (date-filtered, OB entries excluded — initial_due is
        // shown via the synthetic Opening Balance row instead)
        $ledger_entries = $db->query(
            "SELECT * FROM customer_ledger
             WHERE customer_id = ?
               AND transaction_date BETWEEN ? AND ?
               AND reference_type != 'initial_due'
             ORDER BY transaction_date ASC, id ASC",
            [$selected_customer_id, $date_from, $date_to]
        )->results();

        // Calculate summary
        $summary = [
            'total_debits' => 0,
            'total_credits' => 0,
            'opening_balance' => 0,
            'closing_balance' => 0
        ];

        // Opening balance: initial_due + non-OB debits before date_from - credits before date_from.
        // Never reads stored balance_after. OB entries excluded so initial_due is not double-counted.
        $agg_before = $db->query(
            "SELECT COALESCE(SUM(debit_amount), 0) AS td,
                    COALESCE(SUM(credit_amount), 0) AS tc
             FROM customer_ledger
             WHERE customer_id = ? AND transaction_date < ? AND reference_type != 'initial_due'",
            [$selected_customer_id, $date_from]
        )->first();
        $td_before = (float)($agg_before->td ?? 0);
        $tc_before = (float)($agg_before->tc ?? 0);
        $summary['opening_balance'] = $initial_due + $td_before - $tc_before;

        
        // Calculate totals and per-row running balance from raw amounts (never reads balance_after)
        $row_running = $summary['opening_balance'];
        foreach ($ledger_entries as $entry) {
            $summary['total_debits']  += (float)$entry->debit_amount;
            $summary['total_credits'] += (float)$entry->credit_amount;
            $row_running += (float)$entry->debit_amount - (float)$entry->credit_amount;
            $entry->computed_balance = round($row_running, 2);
        }
        
        // Closing balance = opening + debits − credits (always correct even when
        // stored balance_after values have drifted from the opening balance baseline).
        $summary['closing_balance'] = $summary['opening_balance']
                                    + $summary['total_debits']
                                    - $summary['total_credits'];
    }
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">View customer transaction history and balances</p>
</div>

<?php
$sf = $_SESSION['success_flash'] ?? null;
$ef = $_SESSION['error_flash']   ?? null;
unset($_SESSION['success_flash'], $_SESSION['error_flash']);
?>
<?php if ($sf): ?>
<div class="mb-4 p-4 bg-green-50 border border-green-300 rounded-lg text-green-800">
    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($sf); ?>
</div>
<?php endif; ?>
<?php if ($ef): ?>
<div class="mb-4 p-4 bg-red-50 border border-red-300 rounded-lg text-red-800">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($ef); ?>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <form method="GET" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-2">Customer *</label>
                <select name="customer_id" id="ledgerCustomerSelect" required class="w-full px-4 py-2 border rounded-lg" onchange="this.form.submit()">
                    <option value="">-- Select Customer --</option>
                    <?php foreach ($customers as $customer): ?>
                    <option value="<?php echo $customer->id; ?>" <?php echo $selected_customer_id == $customer->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($customer->name); ?><?php echo !empty($customer->business_name) ? ' — ' . htmlspecialchars($customer->business_name) : ''; ?> (<?php echo htmlspecialchars($customer->phone ?: 'no phone'); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    if (window.jQuery && jQuery.fn.select2) {
                        jQuery('#ledgerCustomerSelect').select2({
                            placeholder: 'Search by name, business or phone…',
                            width: '100%', allowClear: true
                        });
                    }
                });
                </script>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
                <input type="date" name="date_from" value="<?php echo $date_from; ?>" class="w-full px-4 py-2 border rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
                <input type="date" name="date_to" value="<?php echo $date_to; ?>" class="w-full px-4 py-2 border rounded-lg">
            </div>
        </div>
        
        <?php if ($selected_customer_id): ?>
        <div class="flex gap-3">
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fas fa-filter mr-2"></i>Apply Filter
            </button>
            <button type="button" onclick="window.print()" class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                <i class="fas fa-print mr-2"></i>Print Ledger
            </button>
            <!-- Updated Link to CSV Export -->
            <a href="customer_ledger_export.php?customer_id=<?php echo $selected_customer_id; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"
               class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                <i class="fas fa-file-csv mr-2"></i>Export CSV
            </a>
            <?php if ($is_admin): ?>
            <button type="button" onclick="document.getElementById('adjModal').classList.remove('hidden')"
                    class="px-6 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700">
                <i class="fas fa-scale-balanced mr-2"></i>Post Adjustment
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php if ($selected_customer_id && $is_admin): ?>
<!-- Feature #3: manual reconciliation / adjustment entry -->
<div id="adjModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
      <h3 class="font-bold text-gray-800"><i class="fas fa-scale-balanced text-amber-500 mr-2"></i>Post Ledger Adjustment</h3>
      <button type="button" onclick="document.getElementById('adjModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-700"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" class="p-5 space-y-3" onsubmit="return confirm('Post this adjustment to the ledger?');">
      <input type="hidden" name="action" value="post_adjustment">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
      <input type="hidden" name="customer_id" value="<?php echo $selected_customer_id; ?>">
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Type *</label>
        <select name="direction" required class="w-full px-3 py-2 border rounded-lg text-sm">
          <option value="debit">Debit — increase due (customer owes more)</option>
          <option value="credit">Credit — decrease due (reduce what they owe)</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Amount (৳) *</label>
        <input type="number" name="amount" step="0.01" min="0.01" required class="w-full px-3 py-2 border rounded-lg text-sm">
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Date</label>
        <input type="date" name="adj_date" value="<?php echo date('Y-m-d'); ?>" class="w-full px-3 py-2 border rounded-lg text-sm">
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Reason *</label>
        <textarea name="reason" required rows="2" maxlength="255" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="e.g. rounding correction, agreed waiver, bank reconciliation"></textarea>
      </div>
      <p class="text-[11px] text-gray-400">Posts a memo entry to this customer's ledger and updates their balance. Reversible — a Superadmin can delete it (restored via the Recycle Bin).</p>
      <div class="flex gap-2 justify-end pt-1">
        <button type="button" onclick="document.getElementById('adjModal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-sm">Cancel</button>
        <button type="submit" class="px-4 py-2 bg-amber-600 text-white font-semibold rounded-lg text-sm hover:bg-amber-700">Post Adjustment</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (!$selected_customer_id): ?>
<!-- Feature #2: All-customers overview with running balances -->
<?php
$oc_total_due = 0.0; $oc_total_adv = 0.0;
foreach ($all_customer_balances as $__b) {
    if ((float)$__b->true_balance > 0.01)      $oc_total_due += (float)$__b->true_balance;
    elseif ((float)$__b->true_balance < -0.01) $oc_total_adv += abs((float)$__b->true_balance);
}
?>
<div class="bg-white rounded-lg shadow-md overflow-hidden mb-6">
    <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-bold text-gray-800">
            <i class="fas fa-users mr-2 text-blue-500"></i>All Customers — Account Balances
            <span class="text-xs font-normal text-gray-400">(<?php echo count($all_customer_balances); ?>)</span>
        </h2>
        <div class="flex gap-4 text-xs">
            <span class="text-gray-500">Total receivable: <strong class="text-red-600">৳<?php echo number_format($oc_total_due, 2); ?></strong></span>
            <span class="text-gray-500">Total advances: <strong class="text-green-600">৳<?php echo number_format($oc_total_adv, 2); ?></strong></span>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                    <th class="px-6 py-2 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                    <th class="px-6 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-2 text-right text-xs font-medium text-gray-500 uppercase">Ledger</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($all_customer_balances as $b):
                    $bal = (float)$b->true_balance;
                    if ($bal > 0.01)       { $tag = ['Due', 'bg-red-100 text-red-700', 'text-red-600']; }
                    elseif ($bal < -0.01)  { $tag = ['Advance', 'bg-green-100 text-green-700', 'text-green-600']; }
                    else                   { $tag = ['Settled', 'bg-gray-100 text-gray-500', 'text-gray-500']; }
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-2.5 font-medium text-gray-800"><?php echo htmlspecialchars($b->name); ?></td>
                    <td class="px-6 py-2.5 text-gray-500"><?php echo htmlspecialchars($b->phone_number ?? ''); ?></td>
                    <td class="px-6 py-2.5 text-right font-bold <?php echo $tag[2]; ?>">৳<?php echo number_format(abs($bal), 2); ?></td>
                    <td class="px-6 py-2.5 text-center">
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold <?php echo $tag[1]; ?>"><?php echo $tag[0]; ?></span>
                    </td>
                    <td class="px-6 py-2.5 text-right">
                        <a href="customer_ledger.php?customer_id=<?php echo (int)$b->id; ?>"
                           class="text-xs text-blue-600 hover:text-blue-800 font-semibold">
                            View <i class="fas fa-arrow-right text-[10px]"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($customer_info): ?>

<!-- Customer Info & Summary -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
    <!-- Customer Details -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Customer Information</h2>
        <div class="space-y-2 text-sm">
            <div class="flex justify-between">
                <span class="text-gray-600">Name:</span>
                <span class="font-medium"><?php echo htmlspecialchars($customer_info->name); ?></span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-600">Phone:</span>
                <!-- FIX: Mapped variable name for display -->
                <span class="font-medium"><?php echo htmlspecialchars($customer_info->phone_number); ?></span>
            </div>
            <?php if ($customer_info->email): ?>
            <div class="flex justify-between">
                <span class="text-gray-600">Email:</span>
                <span class="font-medium"><?php echo htmlspecialchars($customer_info->email); ?></span>
            </div>
            <?php endif; ?>
            <div class="flex justify-between pt-2 border-t">
                <span class="text-gray-600">Credit Limit:</span>
                <span class="font-bold">৳<?php echo number_format($customer_info->credit_limit ?? 0, 2); ?></span>
            </div>
            
            <?php
            $cb = $true_current_balance;
            $available_credit = ($customer_info->credit_limit ?? 0) - $cb;
            ?>
            <div class="flex justify-between">
                <span class="text-gray-600">Opening Due (Carried):</span>
                <span class="font-bold text-gray-500">৳<?php echo number_format($customer_info->initial_due ?? 0, 2); ?></span>
            </div>
            <?php
            if ($cb > 0) {
                $cb_label = 'Total Due (Unpaid)';
                $cb_class = 'font-bold text-red-600';
                $cb_text  = '৳' . number_format($cb, 2) . ' Due';
            } elseif ($cb < 0) {
                $cb_label = 'Advance / Credit';
                $cb_class = 'font-bold text-green-600';
                $cb_text  = '৳' . number_format(abs($cb), 2) . ' Advance';
            } else {
                $cb_label = 'Outstanding Balance';
                $cb_class = 'font-bold text-gray-500';
                $cb_text  = '৳0.00 Clear';
            }
            ?>
            <div class="flex justify-between items-center py-1 px-2 rounded <?php echo $cb > 0 ? 'bg-red-50' : ($cb < 0 ? 'bg-green-50' : ''); ?>">
                <span class="text-gray-700 font-semibold"><?php echo $cb_label; ?>:</span>
                <span class="<?php echo $cb_class; ?>"><?php echo $cb_text; ?></span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-600">Available Credit:</span>
                <span class="font-bold text-green-600">৳<?php echo number_format($available_credit, 2); ?></span>
            </div>
            
            
            
            
            
        </div>
    </div>
    
    <!-- Summary -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Period Summary</h2>
        <div class="space-y-3">
            <div class="flex justify-between items-center p-3 bg-blue-50 rounded">
                <span class="text-gray-700">Opening Balance:</span>
                <span class="text-xl font-bold text-blue-600">৳<?php echo number_format($summary['opening_balance'], 2); ?></span>
            </div>
            <div class="flex justify-between items-center p-3 bg-red-50 rounded">
                <span class="text-gray-700">Total Debits (Invoices):</span>
                <span class="text-xl font-bold text-red-600">৳<?php echo number_format($summary['total_debits'], 2); ?></span>
            </div>
            <div class="flex justify-between items-center p-3 bg-green-50 rounded">
                <span class="text-gray-700">Total Credits (Payments):</span>
                <span class="text-xl font-bold text-green-600">৳<?php echo number_format($summary['total_credits'], 2); ?></span>
            </div>
            <?php
            $cl = (float)$summary['closing_balance'];
            if ($cl > 0) {
                $cl_label = 'Closing Due (Payable)';
                $cl_class = 'text-2xl font-bold text-red-600';
                $cl_bg    = 'bg-red-50 border-2 border-red-300';
                $cl_text  = '৳' . number_format($cl, 2) . ' Due';
            } elseif ($cl < 0) {
                $cl_label = 'Closing Advance/Credit';
                $cl_class = 'text-2xl font-bold text-green-600';
                $cl_bg    = 'bg-green-50 border-2 border-green-300';
                $cl_text  = '৳' . number_format(abs($cl), 2) . ' Advance';
            } else {
                $cl_label = 'Closing Balance';
                $cl_class = 'text-2xl font-bold text-gray-600';
                $cl_bg    = 'bg-gray-50 border-2 border-gray-300';
                $cl_text  = '৳0.00 Clear';
            }
            ?>
            <div class="flex justify-between items-center p-3 <?php echo $cl_bg; ?> rounded">
                <span class="text-gray-900 font-semibold"><?php echo $cl_label; ?>:</span>
                <span class="<?php echo $cl_class; ?>"><?php echo $cl_text; ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Ledger Entries -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <div class="p-6 border-b border-gray-200 bg-gray-50">
        <h2 class="text-xl font-bold text-gray-800">Transaction History</h2>
        <p class="text-sm text-gray-600 mt-1">
            Period: <?php echo date('M j, Y', strtotime($date_from)); ?> to <?php echo date('M j, Y', strtotime($date_to)); ?>
        </p>
    </div>
    
    <?php if (!empty($ledger_entries)): ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice/Ref</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Debit</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance Due</th>
                    <?php if ($is_superadmin): ?>
                    <th class="px-4 py-3 text-center text-xs font-medium text-red-500 uppercase">Delete</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <!-- Opening Balance Row -->
                <?php if ($summary['opening_balance'] != 0): ?>
                <tr class="bg-blue-50">
                    <td class="px-4 py-3 text-sm font-medium"><?php echo date('M j, Y', strtotime($date_from)); ?></td>
                    <td class="px-4 py-3 text-sm" colspan="3">
                        <span class="font-semibold text-blue-700">Opening Balance</span>
                    </td>
                    <td class="px-4 py-3 text-sm text-right">-</td>
                    <td class="px-4 py-3 text-sm text-right">-</td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-blue-600">
                        ৳<?php echo number_format($summary['opening_balance'], 2); ?>
                    </td>
                </tr>
                <?php endif; ?>
                
                <!-- Transaction Rows -->
                <?php foreach ($ledger_entries as $entry): 
                    $type_colors = [
                        'invoice' => 'red',
                        'payment' => 'green',
                        'advance_payment' => 'blue',
                        'adjustment' => 'orange',
                        'credit_note' => 'purple',
                        'debit_note' => 'pink'
                    ];
                    $color = $type_colors[$entry->transaction_type] ?? 'gray';
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-sm whitespace-nowrap">
                        <?php echo date('M j, Y', strtotime($entry->transaction_date)); ?>
                    </td>
                    <td class="px-4 py-3 text-sm whitespace-nowrap">
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-800">
                            <?php echo ucwords(str_replace('_', ' ', $entry->transaction_type)); ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <?php echo htmlspecialchars($entry->description); ?>
                    </td>
                    <td class="px-4 py-3 text-sm whitespace-nowrap font-medium text-blue-600">
                        <?php echo htmlspecialchars($entry->invoice_number ?? '-'); ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-right font-medium text-red-600">
                        <?php echo $entry->debit_amount > 0 ? '৳' . number_format($entry->debit_amount, 2) : '-'; ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-right font-medium text-green-600">
                        <?php echo $entry->credit_amount > 0 ? '৳' . number_format($entry->credit_amount, 2) : '-'; ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">
                        ৳<?php echo number_format($entry->computed_balance, 2); ?>
                    </td>
                    <?php if ($is_superadmin): ?>
                    <td class="px-4 py-3 text-center">
                        <form method="POST"
                              onsubmit="return confirm('⚠️ Delete this entry and ALL related records (order/payment/return)? They move to the Recycle Bin (restorable by Superadmin).');">
                            <input type="hidden" name="action" value="delete_ledger_entry">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                            <input type="hidden" name="entry_id" value="<?php echo $entry->id; ?>">
                            <input type="hidden" name="customer_id" value="<?php echo $selected_customer_id; ?>">
                            <input type="hidden" name="date_from" value="<?php echo $date_from; ?>">
                            <input type="hidden" name="date_to" value="<?php echo $date_to; ?>">
                            <button type="submit"
                                    class="text-red-500 hover:text-red-700 hover:bg-red-50 p-1 rounded transition-colors"
                                    title="Delete entry + related records">
                                <i class="fas fa-trash text-xs"></i>
                            </button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                
                <!-- Totals Row -->
                <tr class="bg-gray-100 font-semibold">
                    <td colspan="4" class="px-4 py-3 text-sm text-right">TOTALS:</td>
                    <td class="px-4 py-3 text-sm text-right text-red-600">
                        ৳<?php echo number_format($summary['total_debits'], 2); ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-right text-green-600">
                        ৳<?php echo number_format($summary['total_credits'], 2); ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-purple-600">
                        ৳<?php echo number_format($summary['closing_balance'], 2); ?>
                    </td>
                    <?php if ($is_superadmin): ?><td></td><?php endif; ?>
                </tr>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="p-12 text-center text-gray-500">
        <i class="fas fa-inbox text-6xl mb-4"></i>
        <p class="text-lg">No transactions found for the selected period</p>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($selected_customer_id): ?>
<div class="bg-yellow-50 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded-r-lg">
    <p>Customer not found</p>
</div>

<?php else: ?>
<div class="bg-white rounded-lg shadow-md p-12 text-center">
    <i class="fas fa-user-circle text-6xl text-gray-400 mb-4"></i>
    <h3 class="text-xl font-semibold text-gray-700 mb-2">Select a Customer</h3>
    <p class="text-gray-600">Choose a customer from the dropdown above to view their ledger</p>
</div>
<?php endif; ?>

</div>

<style media="print">
@media print {
    .no-print { display: none !important; }
    body { font-size: 12px; }
    .shadow-md { box-shadow: none !important; }
}
</style>

<?php require_once '../templates/footer.php'; ?>

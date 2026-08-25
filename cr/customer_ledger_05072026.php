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
    "SELECT id, name, phone_number as phone 
     FROM customers 
     WHERE status = 'active' 
     ORDER BY name ASC"
)->results();

// Get selected customer
$selected_customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01'); // First day of month
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d'); // Today

$customer_info  = null;
$ledger_entries = [];
$summary        = null;
$is_superadmin  = ($currentUser['role'] ?? '') === 'Superadmin';

/* ─── Superadmin: Delete ledger entry + related records ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_ledger_entry' && $is_superadmin) {
    $entry_id = (int)($_POST['entry_id'] ?? 0);
    if ($entry_id) {
        try {
            $entry = $db->query("SELECT * FROM customer_ledger WHERE id = ?", [$entry_id])->first();
            if (!$entry) throw new Exception("Ledger entry not found.");

            $pdo = $db->getPdo();
            $pdo->beginTransaction();

            // Recalculate customer balance by removing this entry's impact
            $debit_amt  = (float)$entry->debit_amount;
            $credit_amt = (float)$entry->credit_amount;
            // Debit increases balance, credit decreases it
            $balance_correction = $credit_amt - $debit_amt;  // reversal

            // Delete related data based on reference_type
            $ref_type = $entry->reference_type ?? '';
            $ref_id   = (int)($entry->reference_id ?? 0);

            // Bug 12 fix: Replace raw string-interpolation exec() calls with parameterized
            // queries via $db->query().  exec() with interpolation bypasses the PDO
            // prepared-statement layer; using $db->query() is the consistent safe pattern.
            if ($ref_id) {
                if (in_array($ref_type, ['credit_order','credit_orders'])) {
                    $db->query("DELETE FROM credit_order_workflow WHERE order_id = ?", [$ref_id]);
                    $db->query("DELETE FROM credit_order_shipping WHERE order_id = ?", [$ref_id]);
                    $db->query("DELETE FROM production_schedule WHERE order_id = ?", [$ref_id]);
                    try { $db->query("DELETE FROM credit_order_delivery_items WHERE delivery_id IN (SELECT id FROM credit_order_deliveries WHERE order_id = ?)", [$ref_id]); } catch(Exception $e2){}
                    try { $db->query("DELETE FROM credit_order_deliveries WHERE order_id = ?", [$ref_id]); } catch(Exception $e2){}
                    try { $db->query("DELETE FROM credit_order_return_items WHERE return_id IN (SELECT id FROM credit_order_returns WHERE order_id = ?)", [$ref_id]); } catch(Exception $e2){}
                    try { $db->query("DELETE FROM credit_order_returns WHERE order_id = ?", [$ref_id]); } catch(Exception $e2){}
                    try { $db->query("DELETE FROM payment_allocations WHERE order_id = ?", [$ref_id]); } catch(Exception $e2){}
                    $db->query("DELETE FROM credit_order_items WHERE order_id = ?", [$ref_id]);
                    $db->query("DELETE FROM credit_orders WHERE id = ?", [$ref_id]);
                } elseif (in_array($ref_type, ['customer_payments'])) {
                    try { $db->query("DELETE FROM payment_allocations WHERE payment_id = ?", [$ref_id]); } catch(Exception $e2){}
                    try { $db->query("DELETE FROM customer_payments WHERE id = ?", [$ref_id]); } catch(Exception $e2){}
                } elseif (in_array($ref_type, ['credit_order_returns'])) {
                    try { $db->query("DELETE FROM credit_order_return_items WHERE return_id = ?", [$ref_id]); } catch(Exception $e2){}
                    try { $db->query("DELETE FROM credit_order_returns WHERE id = ?", [$ref_id]); } catch(Exception $e2){}
                } elseif (in_array($ref_type, ['credit_order_deliveries'])) {
                    try { $db->query("DELETE FROM credit_order_delivery_items WHERE delivery_id = ?", [$ref_id]); } catch(Exception $e2){}
                    try { $db->query("DELETE FROM credit_order_deliveries WHERE id = ?", [$ref_id]); } catch(Exception $e2){}
                }
            }

            // Delete the ledger entry itself
            $db->query("DELETE FROM customer_ledger WHERE id = ?", [$entry_id]);

            // Correct customer balance
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
                    $running_bal = $running_bal + (float)$sub->debit_amount - (float)$sub->credit_amount;
                    $db->query("UPDATE customer_ledger SET balance_after = ? WHERE id = ?", [$running_bal, $sub->id]);
                }
            }

            $pdo->commit();

            auditLog('customer_ledger', 'deleted',
                "Superadmin deleted ledger entry #{$entry_id} ref:{$ref_type}#{$ref_id} — impact ৳" . number_format(abs($debit_amt - $credit_amt), 2),
                ['entry_id' => $entry_id, 'ref_type' => $ref_type, 'ref_id' => $ref_id]
            );

            $_SESSION['success_flash'] = "Ledger entry #{$entry_id} and all related records deleted.";
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
                <select name="customer_id" required class="w-full px-4 py-2 border rounded-lg" onchange="this.form.submit()">
                    <option value="">-- Select Customer --</option>
                    <?php foreach ($customers as $customer): ?>
                    <option value="<?php echo $customer->id; ?>" <?php echo $selected_customer_id == $customer->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($customer->name); ?> (<?php echo htmlspecialchars($customer->phone); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
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
        </div>
        <?php endif; ?>
    </form>
</div>

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
                              onsubmit="return confirm('⚠️ Delete this entry and ALL related records (order/payment/return)? This is irreversible.');">
                            <input type="hidden" name="action" value="delete_ledger_entry">
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
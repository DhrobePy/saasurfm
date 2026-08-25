<?php
/**
 * POS Customer Ledger (Jul 2026) — dedicated to POS-type customers, reading
 * pos_customer_ledger. Deliberately a separate page/table from Credit Sales'
 * customer_ledger.php, per the locked design decision for this rebuild.
 */
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'accountspos-demra', 'accountspos-srg', 'dispatchpos-demra', 'dispatchpos-srg'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'POS Customer Ledger';
ensurePosLedgerTable();

$customer_id = (int)($_GET['customer_id'] ?? 0);
$search = trim($_GET['q'] ?? '');

if ($customer_id) {
    $customer = $db->query(
        "SELECT id, name, business_name, phone_number, email, credit_limit FROM customers WHERE id = ? AND customer_type = 'POS'",
        [$customer_id]
    )->first();
    if (!$customer) { $customer_id = 0; }
}

require_once '../templates/header.php';
?>
<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">POS Customer Ledger</h1>
            <p class="text-sm text-gray-500 mt-1">Running credit balances for POS customers — separate from the Credit Sales ledger.</p>
        </div>
        <a href="index.php" class="text-sm text-blue-600 hover:text-blue-800"><i class="fas fa-arrow-left mr-1"></i>Back to POS Terminal</a>
    </div>

    <?php if (!$customer_id): ?>
        <?php
        $where = "c.customer_type = 'POS' AND c.status = 'active'";
        $params = [];
        if ($search !== '') {
            $where .= " AND (c.name LIKE ? OR c.business_name LIKE ? OR c.phone_number LIKE ?)";
            $like = "%{$search}%";
            $params = [$like, $like, $like];
        }
        $customers = $db->query(
            "SELECT c.id, c.name, c.business_name, c.phone_number, c.credit_limit,
                    COALESCE((SELECT pl.balance_after FROM pos_customer_ledger pl WHERE pl.customer_id = c.id ORDER BY pl.id DESC LIMIT 1), 0) AS outstanding
             FROM customers c
             WHERE {$where}
             ORDER BY outstanding DESC, c.name ASC
             LIMIT 200",
            $params
        )->results();
        $total_outstanding = array_sum(array_column($customers, 'outstanding'));
        ?>

        <div class="bg-white rounded-xl shadow-sm p-4 mb-4">
            <form method="GET" class="flex gap-2">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name, business, or phone..."
                       class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">Search</button>
                <?php if ($search): ?><a href="customer_ledger.php" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm">Clear</a><?php endif; ?>
            </form>
        </div>

        <div class="bg-white rounded-xl shadow-sm p-4 mb-4 flex items-center justify-between">
            <span class="text-sm text-gray-600">Total POS credit outstanding (<?php echo count($customers); ?> customers)</span>
            <span class="text-xl font-bold text-red-600">৳<?php echo number_format($total_outstanding, 2); ?></span>
        </div>

        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <table class="min-w-full text-sm divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase text-xs">Customer</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase text-xs">Phone</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase text-xs">Outstanding</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase text-xs">Limit</th>
                        <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase text-xs">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($customers)): ?>
                    <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No POS customers found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($customers as $c): ?>
                    <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='customer_ledger.php?customer_id=<?php echo $c->id; ?>'">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900"><?php echo htmlspecialchars($c->name); ?></div>
                            <?php if ($c->business_name): ?><div class="text-xs text-gray-500"><?php echo htmlspecialchars($c->business_name); ?></div><?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars($c->phone_number ?? ''); ?></td>
                        <td class="px-4 py-3 text-right font-bold <?php echo $c->outstanding > 0 ? 'text-red-600' : 'text-gray-400'; ?>">৳<?php echo number_format($c->outstanding, 2); ?></td>
                        <td class="px-4 py-3 text-right text-gray-600">৳<?php echo number_format($c->credit_limit, 2); ?></td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($c->outstanding > (float)$c->credit_limit && $c->credit_limit > 0): ?>
                                <span class="px-2 py-1 bg-red-100 text-red-800 rounded-full text-xs font-bold">Over Limit</span>
                            <?php elseif ($c->outstanding > 0): ?>
                                <span class="px-2 py-1 bg-amber-100 text-amber-800 rounded-full text-xs font-bold">Owing</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs font-bold">Clear</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php else: ?>
        <?php
        // Full purchase history, not just the credit-tracking rows: every POS
        // sale (cash or credit) plus every ledger-only event (payments,
        // edit/delete adjustments). A sale with a credit portion already has
        // a 'sale' ledger row for the same order, so that ledger row is
        // skipped here to avoid showing the same sale twice — its balance
        // impact is what the Balance column tracks, the order row alongside
        // it carries the full cash+credit breakdown for context.
        $orders_history = $db->query(
            "SELECT id, order_number, order_date, total_amount, cash_paid, credit_amount, payment_method, payment_status
             FROM orders WHERE customer_id = ? AND order_type = 'POS' ORDER BY id DESC LIMIT 200",
            [$customer_id]
        )->results();
        $ledger_entries = $db->query(
            "SELECT * FROM pos_customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 200",
            [$customer_id]
        )->results();

        $timeline = [];
        foreach ($orders_history as $o) {
            $timeline[] = (object)[
                'sort_ts' => strtotime($o->order_date) * 10, 'kind' => 'order', 'row' => $o,
            ];
        }
        foreach ($ledger_entries as $e) {
            if ($e->transaction_type === 'sale') continue; // represented by its order row above
            $timeline[] = (object)[
                'sort_ts' => strtotime($e->transaction_date) * 10 + 1, 'kind' => 'ledger', 'row' => $e,
            ];
        }
        usort($timeline, fn($a, $b) => $b->sort_ts <=> $a->sort_ts);
        $timeline = array_slice($timeline, 0, 200);

        $outstanding = getPosCustomerOutstanding($customer_id);
        ?>
        <a href="customer_ledger.php" class="text-sm text-blue-600 hover:text-blue-800 mb-4 inline-block"><i class="fas fa-arrow-left mr-1"></i>All Customers</a>

        <div class="bg-white rounded-xl shadow-sm p-5 mb-4">
            <div class="flex justify-between items-start">
                <div>
                    <h2 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($customer->name); ?></h2>
                    <?php if ($customer->business_name): ?><p class="text-sm text-gray-500"><?php echo htmlspecialchars($customer->business_name); ?></p><?php endif; ?>
                    <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($customer->phone_number ?? ''); ?></p>
                </div>
                <div class="text-right">
                    <div class="text-xs text-gray-500 uppercase">Outstanding</div>
                    <div class="text-2xl font-bold <?php echo $outstanding > 0 ? 'text-red-600' : 'text-green-600'; ?>">৳<?php echo number_format($outstanding, 2); ?></div>
                    <div class="text-xs text-gray-500 mt-1">Limit: ৳<?php echo number_format($customer->credit_limit, 2); ?></div>
                </div>
            </div>
            <div class="mt-4">
                <a href="collect_payment.php?customer_id=<?php echo $customer_id; ?>" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium inline-block">
                    <i class="fas fa-money-bill-wave mr-1"></i>Collect Payment
                </a>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <table class="min-w-full text-sm divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase text-xs">Date</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase text-xs">Type</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase text-xs">Description</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase text-xs">Debit</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase text-xs">Credit</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase text-xs">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($timeline)): ?>
                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No transactions yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($timeline as $t): if ($t->kind === 'order'): $o = $t->row; ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap"><?php echo date('d M Y', strtotime($o->order_date)); ?></td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs font-bold <?php echo $o->credit_amount > 0.009 ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700'; ?>">
                                <?php echo $o->credit_amount > 0.009 ? 'Sale (credit)' : 'Sale (cash)'; ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-700">
                            Order total ৳<?php echo number_format($o->total_amount, 2); ?>
                            <?php if ($o->credit_amount > 0.009 && $o->cash_paid > 0.009): ?>
                                — ৳<?php echo number_format($o->cash_paid, 2); ?> cash + ৳<?php echo number_format($o->credit_amount, 2); ?> credit
                            <?php elseif ($o->credit_amount <= 0.009): ?>
                                — paid <?php echo htmlspecialchars($o->payment_method); ?>
                            <?php endif; ?>
                            <a href="verify_exit.php?order=<?php echo urlencode($o->order_number); ?>&sig=<?php echo urlencode(posExitQrSignature($o->order_number)); ?>" class="text-xs text-blue-600 ml-1"><?php echo htmlspecialchars($o->order_number); ?></a>
                        </td>
                        <td class="px-4 py-3 text-right <?php echo $o->credit_amount > 0.009 ? 'text-red-600 font-medium' : 'text-gray-300'; ?>">
                            <?php echo $o->credit_amount > 0.009 ? '৳' . number_format($o->credit_amount, 2) : '—'; ?>
                        </td>
                        <td class="px-4 py-3 text-right text-gray-300">—</td>
                        <td class="px-4 py-3 text-right font-bold text-gray-400" title="Cash sales don't affect the credit balance">—</td>
                    </tr>
                    <?php else: $e = $t->row; ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap"><?php echo date('d M Y', strtotime($e->transaction_date)); ?></td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs font-bold bg-green-100 text-green-800">
                                <?php echo htmlspecialchars(ucfirst($e->transaction_type)); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-700">
                            <?php echo htmlspecialchars($e->description ?? ''); ?>
                            <?php if ($e->order_number): ?>
                                <a href="verify_exit.php?order=<?php echo urlencode($e->order_number); ?>&sig=<?php echo urlencode(posExitQrSignature($e->order_number)); ?>" class="text-xs text-blue-600 ml-1"><?php echo htmlspecialchars($e->order_number); ?></a>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right <?php echo $e->debit_amount > 0 ? 'text-red-600 font-medium' : 'text-gray-300'; ?>">
                            <?php echo $e->debit_amount > 0 ? '৳' . number_format($e->debit_amount, 2) : '—'; ?>
                        </td>
                        <td class="px-4 py-3 text-right <?php echo $e->credit_amount > 0 ? 'text-green-600 font-medium' : 'text-gray-300'; ?>">
                            <?php echo $e->credit_amount > 0 ? '৳' . number_format($e->credit_amount, 2) : '—'; ?>
                        </td>
                        <td class="px-4 py-3 text-right font-bold text-gray-900">৳<?php echo number_format($e->balance_after, 2); ?></td>
                    </tr>
                    <?php endif; endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require_once '../templates/footer.php'; ?>

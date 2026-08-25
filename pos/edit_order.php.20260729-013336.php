<?php
/**
 * POS Sale Edit (Jul 2026) — Superadmin/admin direct edit ("full CRUD by
 * admin" per the rebuild spec; admin submission IS the approval, same
 * precedent as cr/backdated_order.php). Covers quantity/price/discount
 * corrections on existing line items and the payment split — not adding or
 * removing line items (delete + re-ring covers a wrong-product mistake).
 * Reverses the old inventory/ledger/petty-cash/journal effects and reapplies
 * the new ones inside one transaction, then records a pos_sale_edits diff row
 * purely for the audit trail (already-decided, not a pending approval).
 */
require_once '../core/init.php';

restrict_access(['Superadmin', 'admin']);

global $db;
$pageTitle = 'Edit POS Sale';
ensurePosSaleEditsTable();
ensurePosLedgerTable();

$currentUser = getCurrentUser();
$user_id = (int)($currentUser['id'] ?? 0);
$user_name = $currentUser['display_name'] ?? 'Admin';

$order_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$order_id) { die('Order ID required.'); }

$order = $db->query("SELECT * FROM orders WHERE id = ? AND order_type = 'POS'", [$order_id])->first();
if (!$order) { die('POS order not found.'); }

$error = null; $success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $item_ids = $_POST['item_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_prices = $_POST['unit_price'] ?? [];
    $cash_paid_new = (float)($_POST['cash_paid'] ?? 0);
    $credit_amount_new = (float)($_POST['credit_amount'] ?? 0);
    $payment_method_new = $_POST['payment_method'] ?? $order->payment_method;
    $payment_reference_new = trim($_POST['payment_reference'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    $original_items = $db->query("SELECT * FROM order_items WHERE order_id = ?", [$order_id])->results();
    $original_snapshot = ['order' => (array)$order, 'items' => array_map(fn($i) => (array)$i, $original_items)];

    $db->beginTransaction();
    try {
        $new_subtotal = 0;
        $updates = []; // [item_id, old_qty, new_qty, new_unit_price, new_total]

        foreach ($item_ids as $idx => $item_id) {
            $item_id = (int)$item_id;
            $orig = null;
            foreach ($original_items as $oi) { if ((int)$oi->id === $item_id) { $orig = $oi; break; } }
            if (!$orig) continue;

            $new_qty = max(0, (int)($quantities[$idx] ?? $orig->quantity));
            $new_price = max(0, (float)($unit_prices[$idx] ?? $orig->unit_price));
            $new_line_total = $new_qty * $new_price;
            $new_subtotal += $new_line_total;

            $updates[] = ['item_id' => $item_id, 'variant_id' => (int)$orig->variant_id,
                'old_qty' => (int)$orig->quantity, 'new_qty' => $new_qty, 'new_price' => $new_price, 'new_total' => $new_line_total];
        }

        $new_total = $new_subtotal; // discounts not editable here — corrections are qty/price/payment only
        if (abs(($cash_paid_new + $credit_amount_new) - $new_total) > 0.02) {
            throw new Exception('Cash paid + credit amount must equal the new total ৳' . number_format($new_total, 2));
        }
        if ($credit_amount_new > 0.009 && !$order->customer_id) {
            throw new Exception('This order has no customer — cannot charge any amount to credit.');
        }

        // Reverse + reapply inventory deltas, row-locked per variant.
        foreach ($updates as $u) {
            $delta = $u['new_qty'] - $u['old_qty']; // positive = selling more (reduce stock further)
            if ($delta !== 0) {
                $stock = $db->query("SELECT quantity FROM inventory WHERE variant_id = ? AND branch_id = ? FOR UPDATE", [$u['variant_id'], $order->branch_id])->first();
                if (!$stock) throw new Exception("No inventory row for variant {$u['variant_id']} at this branch.");
                if ($delta > 0 && $stock->quantity < $delta) throw new Exception("Insufficient stock to increase quantity for variant {$u['variant_id']}.");
                $db->query("UPDATE inventory SET quantity = quantity - ?, updated_at = NOW() WHERE variant_id = ? AND branch_id = ?", [$delta, $u['variant_id'], $order->branch_id]);
                if ($db->error()) throw new Exception('Failed to adjust inventory: ' . ($db->errorInfo()[2] ?? ''));
            }
            $db->query("UPDATE order_items SET quantity = ?, unit_price = ?, subtotal = ?, total_amount = ? WHERE id = ?",
                [$u['new_qty'], $u['new_price'], $u['new_total'], $u['new_total'], $u['item_id']]);
            if ($db->error()) throw new Exception('Failed to update order item: ' . ($db->errorInfo()[2] ?? ''));
        }

        // Reverse old cash/credit effects, reapply new ones.
        $old_cash = (float)$order->cash_paid; $old_credit = (float)$order->credit_amount;
        if ($old_cash > 0.009) {
            postBranchPettyCashTransaction((int)$order->branch_id, 'cash_out', $old_cash, [
                'reference_type' => 'pos_sale_edit', 'reference_id' => $order_id, 'description' => "Reversal for edit of {$order->order_number}", 'payment_method' => 'Adjustment',
            ]);
        }
        if ($old_credit > 0.009 && $order->customer_id) {
            postPosLedgerEntry((int)$order->customer_id, 'adjustment', 0, $old_credit, [
                'branch_id' => $order->branch_id, 'reference_type' => 'pos_sale_edit', 'reference_id' => $order_id,
                'order_number' => $order->order_number, 'description' => "Reversal for edit of {$order->order_number}",
            ]);
        }
        if ($cash_paid_new > 0.009) {
            postBranchPettyCashTransaction((int)$order->branch_id, 'cash_in', $cash_paid_new, [
                'reference_type' => 'pos_sale_edit', 'reference_id' => $order_id, 'description' => "Corrected amount for {$order->order_number}", 'payment_method' => $payment_method_new,
            ]);
        }
        if ($credit_amount_new > 0.009 && $order->customer_id) {
            postPosLedgerEntry((int)$order->customer_id, 'adjustment', $credit_amount_new, 0, [
                'branch_id' => $order->branch_id, 'reference_type' => 'pos_sale_edit', 'reference_id' => $order_id,
                'order_number' => $order->order_number, 'description' => "Corrected amount for {$order->order_number}",
            ]);
        }

        $new_payment_status = 'Paid';
        if ($credit_amount_new > 0.009 && $cash_paid_new > 0.009) $new_payment_status = 'Partial';
        elseif ($credit_amount_new > 0.009) $new_payment_status = 'Unpaid';

        $db->query(
            "UPDATE orders SET subtotal = ?, total_amount = ?, cash_paid = ?, credit_amount = ?, payment_method = ?, payment_reference = ?, payment_status = ?, updated_at = NOW() WHERE id = ?",
            [$new_subtotal, $new_total, $cash_paid_new, $credit_amount_new, $payment_method_new, $payment_reference_new ?: null, $new_payment_status, $order_id]
        );
        if ($db->error()) throw new Exception('Failed to update order: ' . ($db->errorInfo()[2] ?? ''));

        // Archive the original journal (Option A convention — archive, don't reverse-entry)
        // and post a fresh balanced one for the corrected amounts.
        if ($order->journal_entry_id) {
            $db->query("UPDATE journal_entries SET description = CONCAT(description, ' [SUPERSEDED BY EDIT]') WHERE id = ?", [$order->journal_entry_id]);
        }
        $revenue_account_id = $db->query("SELECT id FROM chart_of_accounts WHERE name LIKE '%Sales Revenue%' AND account_type = 'Revenue' AND is_active = 1 LIMIT 1")->first();
        $cash_account_id = $cash_paid_new > 0.009 ? getBranchPettyCashAccountId((int)$order->branch_id) : null;
        $ar_account_id = $credit_amount_new > 0.009 ? $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Accounts Receivable' AND is_active = 1 LIMIT 1")->first() : null;
        if (!$revenue_account_id) throw new Exception('No active Revenue account found.');

        $db->query("INSERT INTO journal_entries (transaction_date, description, related_document_id, related_document_type, created_by_user_id) VALUES (CURDATE(), ?, ?, 'Order', ?)",
            ["POS Sale (edited) - Order #{$order->order_number}", $order_id, $user_id]);
        if ($db->error()) throw new Exception('Failed to create replacement journal entry.');
        $new_journal_id = $db->getPdo()->lastInsertId();

        $td = 0; $tc = 0;
        if ($cash_paid_new > 0.009) {
            if (!$cash_account_id) throw new Exception('No active petty cash account for this branch.');
            $db->query("INSERT INTO transaction_lines (journal_entry_id, account_id, debit_amount, credit_amount, description) VALUES (?, ?, ?, 0, ?)", [$new_journal_id, $cash_account_id, $cash_paid_new, "POS Cash (edited) - {$order->order_number}"]);
            $td += $cash_paid_new;
        }
        if ($credit_amount_new > 0.009) {
            if (!$ar_account_id) throw new Exception('No active Accounts Receivable account found.');
            $db->query("INSERT INTO transaction_lines (journal_entry_id, account_id, debit_amount, credit_amount, description) VALUES (?, ?, ?, 0, ?)", [$new_journal_id, $ar_account_id->id, $credit_amount_new, "POS Credit (edited) - {$order->order_number}"]);
            $td += $credit_amount_new;
        }
        $db->query("INSERT INTO transaction_lines (journal_entry_id, account_id, debit_amount, credit_amount, description) VALUES (?, ?, 0, ?, ?)", [$new_journal_id, $revenue_account_id->id, $new_subtotal, "Sales Revenue (edited) - {$order->order_number}"]);
        $tc += $new_subtotal;
        if (abs($td - $tc) > 0.01) throw new Exception('Edited journal entry does not balance.');

        $db->query("UPDATE orders SET journal_entry_id = ? WHERE id = ?", [$new_journal_id, $order_id]);
        if ($db->error()) throw new Exception('Failed to link replacement journal entry.');

        $new_items = $db->query("SELECT * FROM order_items WHERE order_id = ?", [$order_id])->results();
        $new_snapshot = ['order' => ['subtotal' => $new_subtotal, 'total_amount' => $new_total, 'cash_paid' => $cash_paid_new, 'credit_amount' => $credit_amount_new, 'payment_method' => $payment_method_new],
            'items' => array_map(fn($i) => (array)$i, $new_items)];

        $db->insert('pos_sale_edits', [
            'order_id' => $order_id, 'order_number' => $order->order_number,
            'proposed_json' => json_encode($new_snapshot, JSON_UNESCAPED_UNICODE),
            'original_json' => json_encode($original_snapshot, JSON_UNESCAPED_UNICODE),
            'reason' => $reason ?: null, 'status' => 'approved',
            'requested_by_user_id' => $user_id, 'decided_by_user_id' => $user_id, 'decided_at' => date('Y-m-d H:i:s'),
        ]);

        $db->commit();

        if (function_exists('auditLog')) {
            auditLog('pos', 'edited', "POS Sale {$order->order_number} edited by {$user_name}" . ($reason ? " — {$reason}" : '') . " — total ৳" . number_format($order->total_amount, 2) . " → ৳" . number_format($new_total, 2),
                ['record_type' => 'pos_sale', 'record_id' => $order_id, 'reference_number' => $order->order_number]);
        }

        $success = 'Order updated successfully.';
        $order = $db->query("SELECT * FROM orders WHERE id = ?", [$order_id])->first();
    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) $db->rollback();
        $error = 'Could not save changes: ' . $e->getMessage();
    }
}

$items = $db->query(
    "SELECT oi.*, p.base_name, pv.sku, pv.weight_variant
     FROM order_items oi JOIN product_variants pv ON pv.id = oi.variant_id JOIN products p ON p.id = pv.product_id
     WHERE oi.order_id = ? ORDER BY oi.id",
    [$order_id]
)->results();

require_once '../templates/header.php';
?>
<div class="max-w-2xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Edit POS Sale — <?php echo htmlspecialchars($order->order_number); ?></h1>
        <a href="index.php" class="text-sm text-blue-600 hover:text-blue-800">Back</a>
    </div>

    <?php if ($success): ?><div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg text-green-800 text-sm"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-800 text-sm"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <form method="POST" class="bg-white rounded-xl shadow-sm p-5 space-y-4">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo $order_id; ?>">

        <div>
            <h3 class="text-sm font-bold text-gray-700 mb-2">Line Items</h3>
            <?php foreach ($items as $it): ?>
            <div class="flex gap-2 items-center mb-2 p-2 bg-gray-50 rounded-lg">
                <input type="hidden" name="item_id[]" value="<?php echo $it->id; ?>">
                <div class="flex-1 text-sm"><?php echo htmlspecialchars($it->base_name); ?> <span class="text-gray-400">(<?php echo htmlspecialchars($it->sku); ?>)</span></div>
                <input type="number" name="quantity[]" value="<?php echo $it->quantity; ?>" min="0" class="w-20 px-2 py-1 border border-gray-300 rounded text-sm text-center">
                <span class="text-gray-400 text-sm">×</span>
                <input type="number" name="unit_price[]" value="<?php echo $it->unit_price; ?>" step="0.01" min="0" class="w-28 px-2 py-1 border border-gray-300 rounded text-sm text-right">
            </div>
            <?php endforeach; ?>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Cash Paid</label>
                <input type="number" name="cash_paid" value="<?php echo $order->cash_paid; ?>" step="0.01" min="0" class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Credit Amount</label>
                <input type="number" name="credit_amount" value="<?php echo $order->credit_amount; ?>" step="0.01" min="0" class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg" <?php echo !$order->customer_id ? 'disabled' : ''; ?>>
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">Payment Method</label>
            <select name="payment_method" class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg">
                <?php foreach (['Cash', 'Bank Deposit', 'Bank Transfer', 'Card', 'Mobile Banking', 'Credit'] as $pm): ?>
                <option value="<?php echo $pm; ?>" <?php echo $order->payment_method === $pm ? 'selected' : ''; ?>><?php echo $pm; ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">Reference</label>
            <input type="text" name="payment_reference" value="<?php echo htmlspecialchars($order->payment_reference ?? ''); ?>" class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg">
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">Reason for edit (recommended)</label>
            <input type="text" name="reason" class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg" placeholder="e.g. wrong quantity rung up">
        </div>

        <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg">Save Changes</button>
    </form>
</div>
<?php require_once '../templates/footer.php'; ?>

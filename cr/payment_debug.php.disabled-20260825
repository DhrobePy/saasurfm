<?php
require_once '../core/init.php';
global $db;

// Get all customer payments
$payments = $db->query("
    SELECT cp.*, c.name as customer_name 
    FROM customer_payments cp
    LEFT JOIN customers c ON cp.customer_id = c.id
    ORDER BY cp.id DESC
    LIMIT 50
")->results();

// Get all payment allocations
$allocations = $db->query("
    SELECT pa.*, co.order_number 
    FROM payment_allocations pa
    LEFT JOIN credit_orders co ON pa.order_id = co.id
    ORDER BY pa.id DESC
    LIMIT 50
")->results();

// Get credit orders with payments
$orders = $db->query("
    SELECT id, order_number, customer_id, total_amount, amount_paid, balance_due, status, updated_at
    FROM credit_orders
    WHERE amount_paid > 0
    ORDER BY updated_at DESC
    LIMIT 30
")->results();

// Get recent journal entries
$journals = $db->query("
    SELECT * FROM journal_entries
    WHERE related_document_type = 'customer_payments'
    ORDER BY id DESC
    LIMIT 30
")->results();

// Get recent transaction lines
$transactions = $db->query("
    SELECT tl.*, coa.name as account_name
    FROM transaction_lines tl
    LEFT JOIN chart_of_accounts coa ON tl.account_id = coa.id
    ORDER BY tl.id DESC
    LIMIT 50
")->results();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment Debug</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1a1a1a; color: #0f0; }
        h1 { color: #0ff; }
        h2 { color: #ff0; margin-top: 40px; border-top: 2px solid #333; padding-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; background: #000; }
        th { background: #333; color: #0ff; padding: 10px; text-align: left; border: 1px solid #555; }
        td { padding: 8px; border: 1px solid #333; }
        .empty { color: #f00; font-weight: bold; }
        .count { color: #0f0; font-weight: bold; }
        pre { background: #000; padding: 10px; border: 1px solid #333; }
    </style>
</head>
<body>
    <h1>🔍 PAYMENT SYSTEM DEBUG</h1>
    <p>Generated: <?php echo date('Y-m-d H:i:s'); ?></p>

    <h2>📋 CUSTOMER_PAYMENTS TABLE</h2>
    <p class="count">Total Records: <?php echo count($payments); ?></p>
    <?php if (empty($payments)): ?>
        <p class="empty">⚠️ NO RECORDS IN customer_payments TABLE!</p>
    <?php else: ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Payment #</th>
                <th>Receipt #</th>
                <th>Customer</th>
                <th>Date</th>
                <th>Amount</th>
                <th>Method</th>
                <th>Status</th>
                <th>Journal ID</th>
                <th>Created</th>
            </tr>
            <?php foreach ($payments as $p): ?>
            <tr>
                <td><?php echo $p->id; ?></td>
                <td><?php echo $p->payment_number ?? 'NULL'; ?></td>
                <td><?php echo $p->receipt_number ?? 'NULL'; ?></td>
                <td><?php echo $p->customer_name ?? 'ID:'.$p->customer_id; ?></td>
                <td><?php echo $p->payment_date; ?></td>
                <td>৳<?php echo number_format($p->amount, 2); ?></td>
                <td><?php echo $p->payment_method; ?></td>
                <td><?php echo $p->allocation_status ?? 'NULL'; ?></td>
                <td><?php echo $p->journal_entry_id ?? 'NULL'; ?></td>
                <td><?php echo $p->created_at; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h2>🔗 PAYMENT_ALLOCATIONS TABLE</h2>
    <p class="count">Total Records: <?php echo count($allocations); ?></p>
    <?php if (empty($allocations)): ?>
        <p class="empty">⚠️ NO RECORDS IN payment_allocations TABLE!</p>
    <?php else: ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Payment ID</th>
                <th>Order ID</th>
                <th>Order #</th>
                <th>Allocated Amount</th>
                <th>Date</th>
                <th>Created</th>
            </tr>
            <?php foreach ($allocations as $a): ?>
            <tr>
                <td><?php echo $a->id; ?></td>
                <td><?php echo $a->payment_id; ?></td>
                <td><?php echo $a->order_id; ?></td>
                <td><?php echo $a->order_number ?? 'NULL'; ?></td>
                <td>৳<?php echo number_format($a->allocated_amount, 2); ?></td>
                <td><?php echo $a->allocation_date ?? 'NULL'; ?></td>
                <td><?php echo $a->created_at ?? 'NULL'; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h2>📦 CREDIT_ORDERS (with payments)</h2>
    <p class="count">Orders with amount_paid > 0: <?php echo count($orders); ?></p>
    <?php if (empty($orders)): ?>
        <p class="empty">⚠️ NO ORDERS WITH PAYMENTS!</p>
    <?php else: ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Order #</th>
                <th>Customer ID</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Balance Due</th>
                <th>Status</th>
                <th>Updated</th>
            </tr>
            <?php foreach ($orders as $o): ?>
            <tr>
                <td><?php echo $o->id; ?></td>
                <td><?php echo $o->order_number; ?></td>
                <td><?php echo $o->customer_id; ?></td>
                <td>৳<?php echo number_format($o->total_amount, 2); ?></td>
                <td style="color: #0f0;">৳<?php echo number_format($o->amount_paid, 2); ?></td>
                <td style="color: #f00;">৳<?php echo number_format($o->balance_due, 2); ?></td>
                <td><?php echo $o->status; ?></td>
                <td><?php echo $o->updated_at; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h2>📚 JOURNAL_ENTRIES (payment related)</h2>
    <p class="count">Total Records: <?php echo count($journals); ?></p>
    <?php if (empty($journals)): ?>
        <p class="empty">⚠️ NO PAYMENT JOURNAL ENTRIES!</p>
    <?php else: ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Date</th>
                <th>Description</th>
                <th>Doc Type</th>
                <th>Doc ID</th>
                <th>Created By</th>
                <th>Created At</th>
            </tr>
            <?php foreach ($journals as $j): ?>
            <tr>
                <td><?php echo $j->id; ?></td>
                <td><?php echo $j->transaction_date; ?></td>
                <td><?php echo htmlspecialchars($j->description); ?></td>
                <td><?php echo $j->related_document_type ?? 'NULL'; ?></td>
                <td><?php echo $j->related_document_id ?? 'NULL'; ?></td>
                <td><?php echo $j->created_by_user_id; ?></td>
                <td><?php echo $j->created_at; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h2>💰 TRANSACTION_LINES (recent)</h2>
    <p class="count">Total Records: <?php echo count($transactions); ?></p>
    <?php if (empty($transactions)): ?>
        <p class="empty">⚠️ NO TRANSACTION LINES!</p>
    <?php else: ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Journal Entry ID</th>
                <th>Account</th>
                <th>Debit</th>
                <th>Credit</th>
                <th>Description</th>
            </tr>
            <?php foreach ($transactions as $t): ?>
            <tr>
                <td><?php echo $t->id; ?></td>
                <td><?php echo $t->journal_entry_id; ?></td>
                <td><?php echo htmlspecialchars($t->account_name ?? 'ID:'.$t->account_id); ?></td>
                <td style="color: <?php echo $t->debit_amount > 0 ? '#0f0' : '#555'; ?>">
                    <?php echo $t->debit_amount > 0 ? '৳'.number_format($t->debit_amount, 2) : '-'; ?>
                </td>
                <td style="color: <?php echo $t->credit_amount > 0 ? '#f0f' : '#555'; ?>">
                    <?php echo $t->credit_amount > 0 ? '৳'.number_format($t->credit_amount, 2) : '-'; ?>
                </td>
                <td><?php echo htmlspecialchars($t->description ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h2>🔍 DIAGNOSTIC QUERIES</h2>
    
    <h3>Count all records:</h3>
    <pre><?php
    $counts = [
        'customer_payments' => $db->query("SELECT COUNT(*) as c FROM customer_payments")->first()->c,
        'payment_allocations' => $db->query("SELECT COUNT(*) as c FROM payment_allocations")->first()->c,
        'credit_orders (with paid > 0)' => $db->query("SELECT COUNT(*) as c FROM credit_orders WHERE amount_paid > 0")->first()->c,
        'journal_entries (payments)' => $db->query("SELECT COUNT(*) as c FROM journal_entries WHERE related_document_type = 'customer_payments'")->first()->c,
    ];
    foreach ($counts as $table => $count) {
        echo "$table: $count\n";
    }
    ?></pre>

    <h3>Last payment insert attempt would create:</h3>
    <pre>
Payment Number: PAY-<?php echo date('Ymd'); ?>-XXXX
Receipt Number: RCP-<?php echo date('Ymd'); ?>-XXXX
    </pre>

    <h3>Check if tables exist:</h3>
    <pre><?php
    $tables = ['customer_payments', 'payment_allocations', 'journal_entries', 'transaction_lines', 'credit_orders'];
    foreach ($tables as $table) {
        $exists = $db->query("SHOW TABLES LIKE '$table'")->results();
        echo "$table: " . (count($exists) > 0 ? '✓ EXISTS' : '✗ NOT FOUND') . "\n";
    }
    ?></pre>

    <p style="margin-top: 60px; padding-top: 20px; border-top: 2px solid #333; color: #888;">
        Refresh this page after submitting a payment to see if records are created.
    </p>
</body>
</html>

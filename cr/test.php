<?php
require_once '../core/init.php';

// NO SECURITY - DEBUG ONLY
global $db;

echo "<pre style='background:#000;color:#0f0;padding:20px;'>";
echo "=== PAYMENT COLLECTION DEBUG ===\n\n";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "✓ POST REQUEST RECEIVED\n";
    echo "POST Data:\n";
    print_r($_POST);
    echo "\n";
    
    try {
        $customer_id = (int)$_POST['customer_id'];
        $payment_amount = (float)$_POST['payment_amount'];
        $payment_method = trim($_POST['payment_method']);
        $payment_date = $_POST['payment_date'];
        
        echo "✓ Variables parsed:\n";
        echo "  - customer_id: $customer_id\n";
        echo "  - payment_amount: $payment_amount\n";
        echo "  - payment_method: $payment_method\n";
        echo "  - payment_date: $payment_date\n\n";
        
        // Check customer exists
        $customer = $db->query("SELECT * FROM customers WHERE id = ?", [$customer_id])->first();
        echo "✓ Customer found: " . ($customer ? $customer->name : "NOT FOUND") . "\n\n";
        
        // Generate numbers
        $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $payment_number = 'PAY-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        echo "✓ Generated:\n";
        echo "  - receipt_number: $receipt_number\n";
        echo "  - payment_number: $payment_number\n\n";
        
        // Start transaction
        $db->getPdo()->beginTransaction();
        echo "✓ Transaction started\n\n";
        
        // Prepare insert data
        $insert_data = [
            'payment_number' => $payment_number,
            'receipt_number' => $receipt_number,
            'customer_id' => $customer_id,
            'payment_date' => $payment_date,
            'amount' => $payment_amount,
            'payment_method' => $payment_method,
            'payment_type' => 'invoice_payment',
            'reference_number' => $_POST['reference_number'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            'created_by_user_id' => 1,
            'collected_by_employee_id' => !empty($_POST['collected_by_employee_id']) ? (int)$_POST['collected_by_employee_id'] : null,
            'branch_id' => 1,
            'cash_account_id' => !empty($_POST['cash_account_id']) ? (int)$_POST['cash_account_id'] : null,
            'bank_account_id' => !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null,
            'cheque_number' => $_POST['cheque_number'] ?? null,
            'cheque_date' => $_POST['cheque_date'] ?? null,
            'bank_transaction_type' => $_POST['bank_transaction_type'] ?? null
        ];
        
        echo "✓ Insert data prepared:\n";
        print_r($insert_data);
        echo "\n";
        
        // Attempt insert
        echo "⏳ Attempting INSERT into customer_payments...\n";
        $payment_id = $db->insert('customer_payments', $insert_data);
        echo "✓✓✓ SUCCESS! Payment ID: $payment_id\n\n";
        
        // Verify it's really there
        $check = $db->query("SELECT * FROM customer_payments WHERE id = ?", [$payment_id])->first();
        if ($check) {
            echo "✓✓✓ VERIFIED! Payment exists in database:\n";
            print_r($check);
            echo "\n";
        } else {
            echo "✗✗✗ ERROR: Payment not found after insert!\n\n";
        }
        
        $db->getPdo()->commit();
        echo "✓ Transaction committed\n\n";
        
        echo "=== SUCCESS ===\n";
        echo "Payment ID: $payment_id\n";
        echo "Receipt: $receipt_number\n";
        
    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        echo "\n✗✗✗ EXCEPTION CAUGHT ✗✗✗\n";
        echo "Error: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . "\n";
        echo "Line: " . $e->getLine() . "\n";
        echo "Trace:\n" . $e->getTraceAsString() . "\n";
    }
    
} else {
    echo "Waiting for POST request...\n\n";
}

echo "</pre>";
?>

<!DOCTYPE html>
<html>
<head>
    <title>Payment Debug Form</title>
</head>
<body style="background:#1a1a1a;color:#0f0;font-family:monospace;">
    <div style="max-width:800px;margin:40px auto;padding:20px;background:#000;border:2px solid #0f0;">
        <h1 style="color:#0ff;">💳 MINIMAL PAYMENT TEST FORM</h1>
        
        <form method="POST">
            <div style="margin:20px 0;">
                <label style="display:block;margin-bottom:5px;">Customer ID:</label>
                <input type="number" name="customer_id" value="1" required style="width:100%;padding:10px;background:#222;color:#0f0;border:1px solid #0f0;">
            </div>
            
            <div style="margin:20px 0;">
                <label style="display:block;margin-bottom:5px;">Payment Amount (৳):</label>
                <input type="number" name="payment_amount" value="1000" step="0.01" required style="width:100%;padding:10px;background:#222;color:#0f0;border:1px solid #0f0;">
            </div>
            
            <div style="margin:20px 0;">
                <label style="display:block;margin-bottom:5px;">Payment Date:</label>
                <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required style="width:100%;padding:10px;background:#222;color:#0f0;border:1px solid #0f0;">
            </div>
            
            <div style="margin:20px 0;">
                <label style="display:block;margin-bottom:5px;">Payment Method:</label>
                <select name="payment_method" required style="width:100%;padding:10px;background:#222;color:#0f0;border:1px solid #0f0;">
                    <option value="Cash">Cash</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Cheque">Cheque</option>
                    <option value="Mobile Banking">Mobile Banking</option>
                </select>
            </div>
            
            <div style="margin:20px 0;">
                <label style="display:block;margin-bottom:5px;">Cash Account ID (if Cash):</label>
                <input type="number" name="cash_account_id" value="14" style="width:100%;padding:10px;background:#222;color:#0f0;border:1px solid #0f0;">
            </div>
            
            <div style="margin:20px 0;">
                <label style="display:block;margin-bottom:5px;">Bank Account ID (if Bank/Cheque):</label>
                <input type="number" name="bank_account_id" value="12" style="width:100%;padding:10px;background:#222;color:#0f0;border:1px solid #0f0;">
            </div>
            
            <div style="margin:20px 0;">
                <label style="display:block;margin-bottom:5px;">Reference:</label>
                <input type="text" name="reference_number" placeholder="Optional" style="width:100%;padding:10px;background:#222;color:#0f0;border:1px solid #0f0;">
            </div>
            
            <button type="submit" style="width:100%;padding:15px;background:#0f0;color:#000;border:none;font-size:18px;font-weight:bold;cursor:pointer;">
                🚀 TEST PAYMENT INSERT
            </button>
        </form>
        
        <div style="margin-top:40px;padding:20px;border:2px solid #ff0;background:#220;">
            <h3 style="color:#ff0;">📊 CURRENT DATABASE STATE</h3>
            <?php
            $count = $db->query("SELECT COUNT(*) as c FROM customer_payments")->first()->c;
            echo "<p>Total payments in DB: <strong style='color:#0ff;'>$count</strong></p>";
            
            $recent = $db->query("SELECT id, payment_number, receipt_number, amount, payment_date FROM customer_payments ORDER BY id DESC LIMIT 3")->results();
            if ($recent) {
                echo "<p>Last 3 payments:</p><ul>";
                foreach ($recent as $r) {
                    echo "<li>ID: {$r->id} | {$r->receipt_number} | ৳{$r->amount} | {$r->payment_date}</li>";
                }
                echo "</ul>";
            }
            ?>
        </div>
    </div>
</body>
</html>
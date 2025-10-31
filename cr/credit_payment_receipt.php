<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'collection-srg', 'collection-demra'];
restrict_access($allowed_roles);

global $db;

$payment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$payment_id) {
    die('<div style="text-align:center; padding:50px; font-family:Arial;">
         <h2>Invalid Payment ID</h2>
         <p>Please provide a valid payment ID in the URL (e.g., ?id=1)</p>
         <button onclick="window.close()" style="padding:10px 20px; background:#3b82f6; color:white; border:none; border-radius:5px; cursor:pointer;">Close</button>
         </div>');
}

// Get payment details
$payment = $db->query(
    "SELECT cp.*, 
            c.name as customer_name,
            c.phone_number as customer_phone,
            c.email as customer_email,
            c.business_address as customer_address,
            c.business_name as customer_business_name,
            u.display_name as collected_by_name,
            CONCAT(e.first_name, ' ', e.last_name) as employee_name,
            b.name as branch_name,
            b.address as branch_address,
            ca_cash.name as cash_account_name,
            ca_bank.name as bank_account_name
     FROM customer_payments cp
     LEFT JOIN customers c ON cp.customer_id = c.id
     LEFT JOIN users u ON cp.created_by_user_id = u.id
     LEFT JOIN employees e ON cp.collected_by_employee_id = e.id
     LEFT JOIN branches b ON cp.branch_id = b.id
     LEFT JOIN chart_of_accounts ca_cash ON cp.cash_account_id = ca_cash.id
     LEFT JOIN chart_of_accounts ca_bank ON cp.bank_account_id = ca_bank.id
     WHERE cp.id = ?",
    [$payment_id]
)->first();

if (!$payment) {
    die('<div style="text-align:center; padding:50px; font-family:Arial;">
         <h2>Payment Not Found</h2>
         <p>Payment ID #'.$payment_id.' does not exist in the database.</p>
         <button onclick="window.close()" style="padding:10px 20px; background:#3b82f6; color:white; border:none; border-radius:5px; cursor:pointer;">Close</button>
         </div>');
}

// Get allocated invoices
$allocations = $db->query(
    "SELECT pa.*, co.order_number, co.total_amount, co.order_date
     FROM payment_allocations pa
     JOIN credit_orders co ON pa.order_id = co.id
     WHERE pa.payment_id = ?
     ORDER BY co.order_date ASC",
    [$payment_id]
)->results();

// Company info
$company = [
    'name' => 'উজ্জ্বল ফ্লাওয়ার মিলস ',
    'tagline' => '',
    'address' => '১৭, নুরাইবাগ ডেমরা, ঢাকা',
    'phone' => '+880-XXX-XXXXXX',
    'email' => 'info@ujjalfm.com',
    'website' => 'www.ujjalfm.com'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt - <?php echo htmlspecialchars($payment->receipt_number); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            font-size: 13px;
            line-height: 1.6;
            color: #333;
            padding: 20px;
            background: #f5f5f5;
        }
        
        .receipt-container {
            max-width: 700px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            border: 2px solid #10b981;
        }
        
        /* Header */
        .receipt-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #10b981;
        }
        
        .receipt-header h1 {
            font-size: 32px;
            color: #10b981;
            margin-bottom: 5px;
        }
        
        .receipt-header .tagline {
            font-size: 12px;
            color: #666;
            margin-bottom: 15px;
        }
        
        .receipt-title {
            background: #10b981;
            color: white;
            padding: 10px;
            font-size: 24px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 20px 0;
            text-align: center;
        }
        
        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .info-block h3 {
            font-size: 14px;
            color: #10b981;
            margin-bottom: 10px;
            text-transform: uppercase;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 5px;
        }
        
        .info-block p {
            margin: 5px 0;
            font-size: 13px;
        }
        
        .info-block .label {
            color: #666;
            display: inline-block;
            min-width: 120px;
        }
        
        .info-block .value {
            font-weight: bold;
            color: #111;
        }
        
        /* Amount Box */
        .amount-box {
            background: #f0fdf4;
            border: 2px solid #10b981;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 30px 0;
        }
        
        .amount-box .label {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }
        
        .amount-box .amount {
            font-size: 42px;
            font-weight: bold;
            color: #10b981;
        }
        
        .amount-box .words {
            font-size: 14px;
            color: #666;
            margin-top: 10px;
            font-style: italic;
        }
        
        /* Allocations Table */
        .allocations-section {
            margin: 30px 0;
        }
        
        .allocations-section h3 {
            font-size: 14px;
            color: #10b981;
            margin-bottom: 15px;
            text-transform: uppercase;
        }
        
        .allocations-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .allocations-table th {
            background: #f3f4f6;
            padding: 10px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            border-bottom: 2px solid #10b981;
        }
        
        .allocations-table td {
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
        }
        
        .allocations-table .text-right {
            text-align: right;
        }
        
        /* Notes */
        .notes-section {
            background: #fef3c7;
            padding: 15px;
            border-left: 4px solid #f59e0b;
            margin: 20px 0;
        }
        
        .notes-section h4 {
            font-size: 13px;
            color: #92400e;
            margin-bottom: 5px;
        }
        
        .notes-section p {
            font-size: 12px;
            color: #78350f;
        }
        
        /* Signature */
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 60px;
            margin-bottom: 30px;
        }
        
        .signature-box {
            width: 45%;
            text-align: center;
        }
        
        .signature-line {
            border-top: 2px solid #333;
            margin-top: 50px;
            padding-top: 5px;
            font-size: 12px;
            font-weight: bold;
        }
        
        /* Footer */
        .receipt-footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e5e7eb;
            text-align: center;
        }
        
        .receipt-footer p {
            font-size: 11px;
            color: #666;
            margin: 5px 0;
        }
        
        /* Buttons */
        .print-button, .close-button {
            position: fixed;
            top: 20px;
            padding: 12px 24px;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        
        .print-button {
            right: 20px;
            background: #10b981;
        }
        
        .print-button:hover {
            background: #059669;
        }
        
        .close-button {
            right: 180px;
            background: #6b7280;
        }
        
        .close-button:hover {
            background: #4b5563;
        }
        
        @media print {
            body {
                padding: 0;
                background: white;
            }
            
            .receipt-container {
                box-shadow: none;
                max-width: 100%;
            }
            
            .no-print {
                display: none !important;
            }
            
            .amount-box {
                background: #f0fdf4 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .notes-section {
                background: #fef3c7 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <button onclick="window.print()" class="print-button no-print">
        🖨️ Print Receipt
    </button>
    <button onclick="window.close()" class="close-button no-print">
        ✕ Close
    </button>
    
    <div class="receipt-container">
        <!-- Header -->
        <div class="receipt-header">
            <h1><?php echo htmlspecialchars($company['name']); ?></h1>
            <p class="tagline"><?php echo htmlspecialchars($company['tagline']); ?></p>
            <p style="font-size: 11px; color: #666;">
                <?php echo htmlspecialchars($company['address']); ?><br>
                Phone: <?php echo htmlspecialchars($company['phone']); ?> | 
                Email: <?php echo htmlspecialchars($company['email']); ?>
            </p>
        </div>
        
        <div class="receipt-title">
            Payment Receipt
        </div>
        
        <!-- Receipt Info -->
        <div style="text-align: center; margin-bottom: 30px;">
            <p style="font-size: 16px;"><strong>Receipt No:</strong> <?php echo htmlspecialchars($payment->receipt_number); ?></p>
            <p style="font-size: 13px; color: #666;">Date: <?php echo date('F j, Y', strtotime($payment->payment_date)); ?></p>
        </div>
        
        <!-- Info Grid -->
        <div class="info-grid">
            <div class="info-block">
                <h3>Received From</h3>
                <p><strong><?php echo htmlspecialchars($payment->customer_name ?? 'Unknown Customer (ID: '.$payment->customer_id.')'); ?></strong></p>
                <?php if (!empty($payment->customer_business_name)): ?>
                <p><em><?php echo htmlspecialchars($payment->customer_business_name); ?></em></p>
                <?php endif; ?>
                <?php if (!empty($payment->customer_address)): ?>
                <p><?php echo htmlspecialchars($payment->customer_address); ?></p>
                <?php endif; ?>
                <?php if ($payment->customer_phone): ?>
                <p>Phone: <?php echo htmlspecialchars($payment->customer_phone); ?></p>
                <?php endif; ?>
                <?php if ($payment->customer_email): ?>
                <p>Email: <?php echo htmlspecialchars($payment->customer_email); ?></p>
                <?php endif; ?>
            </div>
            
            <div class="info-block">
                <h3>Payment Details</h3>
                <p><span class="label">Method:</span> <span class="value"><?php echo htmlspecialchars($payment->payment_method); ?></span></p>
                
                <?php if ($payment->payment_method === 'Cash' && $payment->cash_account_name): ?>
                <p><span class="label">Deposited To:</span> <span class="value"><?php echo htmlspecialchars($payment->cash_account_name); ?></span></p>
                <?php endif; ?>
                
                <?php if (in_array($payment->payment_method, ['Bank Transfer', 'Cheque']) && $payment->bank_account_name): ?>
                <p><span class="label">Bank Account:</span> <span class="value"><?php echo htmlspecialchars($payment->bank_account_name); ?></span></p>
                <?php endif; ?>
                
                <?php if ($payment->cheque_number): ?>
                <p><span class="label">Cheque No:</span> <span class="value"><?php echo htmlspecialchars($payment->cheque_number); ?></span></p>
                <?php endif; ?>
                
                <?php if ($payment->cheque_date): ?>
                <p><span class="label">Cheque Date:</span> <span class="value"><?php echo date('M j, Y', strtotime($payment->cheque_date)); ?></span></p>
                <?php endif; ?>
                
                <?php if ($payment->bank_transaction_type): ?>
                <p><span class="label">Transaction Type:</span> <span class="value"><?php echo htmlspecialchars($payment->bank_transaction_type); ?></span></p>
                <?php endif; ?>
                
                <?php if ($payment->reference_number): ?>
                <p><span class="label">Reference No:</span> <span class="value"><?php echo htmlspecialchars($payment->reference_number); ?></span></p>
                <?php endif; ?>
                
                <?php if ($payment->employee_name): ?>
                <p><span class="label">Collected By:</span> <span class="value"><?php echo htmlspecialchars($payment->employee_name); ?></span></p>
                <?php else: ?>
                <p><span class="label">Collected By:</span> <span class="value"><?php echo htmlspecialchars($payment->collected_by_name); ?></span></p>
                <?php endif; ?>
                
                <?php if ($payment->branch_name): ?>
                <p><span class="label">Branch:</span> <span class="value"><?php echo htmlspecialchars($payment->branch_name); ?></span></p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Amount Box -->
        <div class="amount-box">
            <p class="label">Amount Received</p>
            <p class="amount">৳<?php echo number_format($payment->amount, 2); ?></p>
            <p class="words"><?php echo ucwords(convertNumberToWords($payment->amount)); ?> Taka Only</p>
        </div>
        
        <!-- Invoice Allocations -->
        <?php if (!empty($allocations)): ?>
        <div class="allocations-section">
            <h3>Payment Allocated To</h3>
            <table class="allocations-table">
                <thead>
                    <tr>
                        <th>Invoice No</th>
                        <th>Invoice Date</th>
                        <th>Invoice Amount</th>
                        <th class="text-right">Payment Allocated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allocations as $alloc): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($alloc->order_number); ?></td>
                        <td><?php echo date('M j, Y', strtotime($alloc->order_date)); ?></td>
                        <td>৳<?php echo number_format($alloc->total_amount, 2); ?></td>
                        <td class="text-right"><strong>৳<?php echo number_format($alloc->allocated_amount, 2); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="background: #f3f4f6; padding: 15px; text-align: center; border-radius: 4px; margin: 20px 0;">
            <p style="color: #666; font-size: 13px;">This payment has not been allocated to specific invoices. It will be used to reduce the customer's general outstanding balance.</p>
        </div>
        <?php endif; ?>
        
        <!-- Notes -->
        
        
        <!-- Signature Section -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line">Customer Signature</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">Authorized Signature</div>
                <p style="font-size: 10px; margin-top: 5px;"><?php echo htmlspecialchars($payment->employee_name ?? $payment->collected_by_name); ?></p>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="receipt-footer">
            <p><strong>Thank you for your payment!</strong></p>
            <p style="margin-top: 10px; font-size: 10px; color: #999;">
                This is a computer-generated receipt. For any queries, please contact us at <?php echo htmlspecialchars($company['phone']); ?>
            </p>
        </div>
    </div>
</body>
</html>

<?php
// Helper function to convert number to words
function convertNumberToWords($number) {
    $number = (int)$number;
    $words = array(
        0 => 'zero', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four',
        5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine',
        10 => 'ten', 11 => 'eleven', 12 => 'twelve', 13 => 'thirteen',
        14 => 'fourteen', 15 => 'fifteen', 16 => 'sixteen', 17 => 'seventeen',
        18 => 'eighteen', 19 => 'nineteen', 20 => 'twenty', 30 => 'thirty',
        40 => 'forty', 50 => 'fifty', 60 => 'sixty', 70 => 'seventy',
        80 => 'eighty', 90 => 'ninety'
    );
    
    if ($number < 21) {
        return $words[$number];
    } elseif ($number < 100) {
        $tens = (int)($number / 10) * 10;
        $units = $number % 10;
        return $words[$tens] . ($units ? ' ' . $words[$units] : '');
    } elseif ($number < 1000) {
        $hundreds = (int)($number / 100);
        $remainder = $number % 100;
        return $words[$hundreds] . ' hundred' . ($remainder ? ' ' . convertNumberToWords($remainder) : '');
    } elseif ($number < 100000) {
        $thousands = (int)($number / 1000);
        $remainder = $number % 1000;
        return convertNumberToWords($thousands) . ' thousand' . ($remainder ? ' ' . convertNumberToWords($remainder) : '');
    } elseif ($number < 10000000) {
        $lakhs = (int)($number / 100000);
        $remainder = $number % 100000;
        return convertNumberToWords($lakhs) . ' lakh' . ($remainder ? ' ' . convertNumberToWords($remainder) : '');
    } else {
        return number_format($number);
    }
}
?>
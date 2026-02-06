<?php
require_once __DIR__ . '/../core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

global $db;

// Get Payment ID
$payment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$payment_id) {
    die('<div style="text-align:center; padding:50px; font-family:Arial;">
         <h2>Invalid Payment ID</h2>
         <button onclick="window.close()" style="padding:10px 20px; background:#3b82f6; color:white; border:none; border-radius:5px; cursor:pointer;">Close</button>
         </div>');
}

// Initialize managers
$payment_manager = new Purchasepaymentadnanmanager();
$po_manager = new Purchaseadnanmanager();

// Get payment details
$payment = $payment_manager->getPayment($payment_id);
if (!$payment) {
    die('<div style="text-align:center; padding:50px; font-family:Arial;">
         <h2>Payment Not Found</h2>
         <button onclick="window.close()" style="padding:10px 20px; background:#3b82f6; color:white; border:none; border-radius:5px; cursor:pointer;">Close</button>
         </div>');
}

// Get PO details
$po = $po_manager->getPurchaseOrder($payment->purchase_order_id);

// Calculate balances
$balance_before = $po->total_received_value - ($po->total_paid - $payment->amount_paid);
$balance_after = $po->balance_payable;

// Get bank/employee details
$payment_source = '';
if ($payment->payment_method === 'bank') {
    $payment_source = $payment->bank_name;
    if ($payment->reference_number) {
        $payment_source .= ' (Ref: ' . $payment->reference_number . ')';
    }
} elseif ($payment->payment_method === 'cash') {
    $payment_source = 'Cash';
    if ($payment->handled_by_employee) {
        $payment_source .= ' - Handled by: ' . $payment->handled_by_employee;
    }
} elseif ($payment->payment_method === 'cheque') {
    $payment_source = 'Cheque';
    if ($payment->reference_number) {
        $payment_source .= ' #' . $payment->reference_number;
    }
}

// Get created by user name
$created_by_query = $db->query("SELECT display_name FROM users WHERE id = ?", [$payment->created_by_user_id])->first();
$created_by_name = $created_by_query ? $created_by_query->display_name : 'System';

// Convert amount to words
function convertNumberToWords($number) {
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    
    // Split into integer and decimal parts
    $parts = explode('.', number_format($number, 2, '.', ''));
    $integer = intval($parts[0]);
    $decimal = isset($parts[1]) ? intval($parts[1]) : 0;
    
    $words = '';
    
    if ($integer == 0) {
        $words = 'Zero';
    } else {
        // Break down into crore, lakh, thousand, hundred, ten
        $crore = floor($integer / 10000000);
        $integer %= 10000000;
        
        $lakh = floor($integer / 100000);
        $integer %= 100000;
        
        $thousand = floor($integer / 1000);
        $integer %= 1000;
        
        $hundred = floor($integer / 100);
        $integer %= 100;
        
        $ten = $integer;
        
        // Convert each part
        if ($crore > 0) {
            if ($crore < 20) {
                $words .= $ones[$crore] . ' Crore ';
            } else {
                $words .= $tens[floor($crore / 10)] . ' ' . $ones[$crore % 10] . ' Crore ';
            }
        }
        
        if ($lakh > 0) {
            if ($lakh < 20) {
                $words .= $ones[$lakh] . ' Lakh ';
            } else {
                $words .= $tens[floor($lakh / 10)] . ' ' . $ones[$lakh % 10] . ' Lakh ';
            }
        }
        
        if ($thousand > 0) {
            if ($thousand < 20) {
                $words .= $ones[$thousand] . ' Thousand ';
            } else {
                $words .= $tens[floor($thousand / 10)] . ' ' . $ones[$thousand % 10] . ' Thousand ';
            }
        }
        
        if ($hundred > 0) {
            $words .= $ones[$hundred] . ' Hundred ';
        }
        
        if ($ten > 0) {
            if ($ten < 20) {
                $words .= $ones[$ten];
            } else {
                $words .= $tens[floor($ten / 10)] . ' ' . $ones[$ten % 10];
            }
        }
    }
    
    $words = trim($words);
    
    // Add paisa if present
    if ($decimal > 0) {
        if ($decimal < 20) {
            $words .= ' and ' . $ones[$decimal] . ' Paisa';
        } else {
            $words .= ' and ' . $tens[floor($decimal / 10)] . ' ' . $ones[$decimal % 10] . ' Paisa';
        }
    }
    
    return 'Taka ' . $words . ' Only';
}

$amount_in_words = convertNumberToWords($payment->amount_paid);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Payment Receipt - <?php echo htmlspecialchars($payment->payment_voucher_number); ?></title>
    <style>
        @media print {
            body { margin: 0; padding: 15px; }
            .no-print { display: none !important; }
            @page { margin: 1cm; }
        }
        
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        
        .receipt-container {
            max-width: 210mm;
            margin: 0 auto;
            background: white;
            padding: 20mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .header {
            text-align: center;
            border-bottom: 3px solid #059669;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .company-name {
            font-size: 28px;
            font-weight: bold;
            color: #1f2937;
            margin: 0;
        }
        
        .receipt-title {
            background: #059669;
            color: white;
            padding: 10px;
            font-size: 20px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 20px 0;
            text-align: center;
        }
        
        .voucher-number-box {
            background: #fef3c7;
            border: 2px solid #f59e0b;
            padding: 10px 20px;
            text-align: center;
            margin: 15px 0;
            border-radius: 5px;
        }
        
        .voucher-number {
            font-size: 18px;
            font-weight: bold;
            color: #92400e;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 20px 0;
        }
        
        .info-row {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .info-label {
            font-weight: bold;
            width: 150px;
            color: #4b5563;
        }
        
        .info-value {
            flex: 1;
            color: #1f2937;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            margin-left: 10px;
        }
        
        .status-posted {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-pending {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .status-advance {
            background: #fed7aa;
            color: #9a3412;
        }
        
        .amount-box {
            background: #ecfdf5;
            border: 2px solid #059669;
            padding: 20px;
            margin: 25px 0;
            text-align: center;
            border-radius: 8px;
        }
        
        .amount-label {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 8px;
        }
        
        .amount-number {
            font-size: 36px;
            font-weight: bold;
            color: #059669;
            margin: 10px 0;
        }
        
        .amount-words {
            font-size: 14px;
            font-style: italic;
            color: #047857;
            margin-top: 8px;
            padding-top: 10px;
            border-top: 1px solid #059669;
        }
        
        .summary-table {
            width: 100%;
            margin: 20px 0;
            border-collapse: collapse;
        }
        
        .summary-table tr {
            border-bottom: 1px solid #e5e7eb;
        }
        
        .summary-table td {
            padding: 10px;
        }
        
        .summary-table td:first-child {
            font-weight: 600;
            color: #4b5563;
        }
        
        .summary-table td:last-child {
            text-align: right;
            color: #1f2937;
        }
        
        .summary-highlight {
            background: #fef3c7;
            font-weight: bold !important;
        }
        
        .summary-total {
            background: #ecfdf5;
            font-weight: bold !important;
            font-size: 16px;
        }
        
        .remarks-box {
            border: 1px solid #d1d5db;
            padding: 15px;
            margin: 20px 0;
            background: #f9fafb;
            border-radius: 5px;
        }
        
        .remarks-label {
            font-weight: bold;
            color: #4b5563;
            margin-bottom: 8px;
        }
        
        .notice-box {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            padding: 12px 15px;
            margin: 20px 0;
            font-size: 13px;
            color: #1e40af;
        }
        
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 30px;
            margin-top: 60px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        
        .signature-box {
            text-align: center;
        }
        
        .signature-line {
            border-top: 2px solid #000;
            margin-top: 50px;
            padding-top: 8px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .signature-info {
            font-size: 11px;
            color: #6b7280;
            margin-top: 5px;
        }
        
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #059669;
            font-size: 11px;
            color: #6b7280;
            text-align: center;
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 24px;
            background: #059669;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        
        .print-button:hover {
            background: #047857;
        }
        
        .section-title {
            font-size: 16px;
            font-weight: bold;
            color: #1f2937;
            margin: 20px 0 10px 0;
            padding-bottom: 5px;
            border-bottom: 2px solid #e5e7eb;
        }
    </style>
</head>
<body>

<button class="print-button no-print" onclick="window.print()">
    🖨️ Print Receipt
</button>

<div class="receipt-container">
    
    <!-- Header -->
    <div class="header">
        <h1 class="company-name">উজ্জল ফ্লাওয়ার মিলস</h1>
        <p style="margin: 5px 0; color: #6b7280;">সিরাজগঞ্জ, ডেমরা, রামপুরা</p>
        <p style="margin: 5px 0; color: #6b7280;">Phone: +880-XXX-XXXXXX | Email: info@ujjalfm.com</p>
    </div>
    
    <!-- Receipt Title -->
    <div class="receipt-title">PAYMENT RECEIPT / VOUCHER</div>
    
    <!-- Voucher Number with Status -->
    <div class="voucher-number-box">
        <span class="voucher-number"><?php echo htmlspecialchars($payment->payment_voucher_number); ?></span>
        <?php if ($payment->is_posted): ?>
        <span class="status-badge status-posted">✓ POSTED</span>
        <?php else: ?>
        <span class="status-badge status-pending">⏳ PENDING</span>
        <?php endif; ?>
        
        <?php if ($payment->payment_type === 'advance'): ?>
        <span class="status-badge status-advance">⚠ ADVANCE PAYMENT</span>
        <?php endif; ?>
    </div>
    
    <!-- Payment Information Grid -->
    <div class="info-grid">
        <div>
            <div class="info-row">
                <span class="info-label">Payment Date:</span>
                <span class="info-value"><?php echo date('d-M-Y', strtotime($payment->payment_date)); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Payment Method:</span>
                <span class="info-value" style="text-transform: uppercase; font-weight: bold;">
                    <?php echo htmlspecialchars($payment->payment_method); ?>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Payment Type:</span>
                <span class="info-value" style="text-transform: capitalize;">
                    <?php echo htmlspecialchars($payment->payment_type); ?>
                </span>
            </div>
        </div>
        
        <div>
            <div class="info-row">
                <span class="info-label">PO Number:</span>
                <span class="info-value" style="font-weight: bold; color: #059669;">
                    <?php echo htmlspecialchars($payment->po_number); ?>
                </span>
            </div>
            <?php if ($payment->reference_number): ?>
            <div class="info-row">
                <span class="info-label">Reference:</span>
                <span class="info-value"><?php echo htmlspecialchars($payment->reference_number); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($payment->journal_entry_id): ?>
            <div class="info-row">
                <span class="info-label">Journal Entry ID:</span>
                <span class="info-value"><?php echo $payment->journal_entry_id; ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Paid To -->
    <div class="info-row" style="background: #f3f4f6; padding: 12px; margin: 20px 0; border-radius: 5px; border: none;">
        <span class="info-label">Paid To:</span>
        <span class="info-value" style="font-size: 16px; font-weight: bold;">
            <?php echo htmlspecialchars($payment->supplier_name); ?>
        </span>
    </div>
    
    <!-- Payment Via -->
    <div class="info-row" style="background: #eff6ff; padding: 12px; margin: 20px 0; border-radius: 5px; border: none;">
        <span class="info-label">Payment Via:</span>
        <span class="info-value" style="font-weight: 600;">
            <?php echo htmlspecialchars($payment_source); ?>
        </span>
    </div>
    
    <!-- Amount Paid -->
    <div class="amount-box">
        <div class="amount-label">AMOUNT PAID</div>
        <div class="amount-number">৳ <?php echo number_format($payment->amount_paid, 2); ?></div>
        <div class="amount-words"><?php echo $amount_in_words; ?></div>
    </div>
    
    <!-- Payment Summary -->
    <div class="section-title">Payment Summary</div>
    <table class="summary-table">
        <tbody>
            <tr>
                <td>Total Order Value:</td>
                <td>৳ <?php echo number_format($po->total_order_value, 2); ?></td>
            </tr>
            <tr>
                <td>Goods Received Value:</td>
                <td>৳ <?php echo number_format($po->total_received_value, 2); ?></td>
            </tr>
            <tr>
                <td>Previous Payments:</td>
                <td>৳ <?php echo number_format($po->total_paid - $payment->amount_paid, 2); ?></td>
            </tr>
            <tr>
                <td>Balance Before This Payment:</td>
                <td>৳ <?php echo number_format($balance_before, 2); ?></td>
            </tr>
            <tr class="summary-highlight">
                <td style="color: #92400e;">Less: This Payment:</td>
                <td style="color: #92400e;">(৳ <?php echo number_format($payment->amount_paid, 2); ?>)</td>
            </tr>
            <tr class="summary-total">
                <td style="color: <?php echo $balance_after > 0 ? '#dc2626' : '#059669'; ?>;">
                    Balance After Payment:
                </td>
                <td style="color: <?php echo $balance_after > 0 ? '#dc2626' : '#059669'; ?>;">
                    ৳ <?php echo number_format($balance_after, 2); ?>
                </td>
            </tr>
        </tbody>
    </table>
    
    <?php if ($payment->remarks): ?>
    <!-- Remarks -->
    <div class="section-title">Remarks / Narration</div>
    <div class="remarks-box">
        <?php echo nl2br(htmlspecialchars($payment->remarks)); ?>
    </div>
    <?php endif; ?>
    
    <!-- Important Notice -->
    <div class="notice-box">
        <strong>📌 Note:</strong> This is an official payment receipt. Please keep it for your records.
        <?php if (!$payment->is_posted): ?>
        <br><strong style="color: #dc2626;">⚠ This payment is pending journal posting.</strong>
        <?php endif; ?>
        <?php if ($payment->payment_type === 'advance'): ?>
        <br><strong style="color: #ea580c;">⚠ This is an advance payment - goods not yet fully received.</strong>
        <?php endif; ?>
    </div>
    
    <!-- Signatures -->
    <div class="signatures">
        <div class="signature-box">
            <div class="signature-line">Prepared By</div>
            <div class="signature-info"><?php echo htmlspecialchars($created_by_name); ?></div>
            <div class="signature-info"><?php echo date('d-M-Y', strtotime($payment->created_at)); ?></div>
        </div>
        <div class="signature-box">
            <div class="signature-line">Verified By</div>
            <div class="signature-info">Accounts Manager</div>
        </div>
        <div class="signature-box">
            <div class="signature-line">Approved By</div>
            <div class="signature-info">Authorized Signatory</div>
        </div>
    </div>
    
    <!-- Footer -->
    <div class="footer">
        <p>This is a computer-generated payment receipt. Generated on <?php echo date('d-M-Y h:i A'); ?></p>
        <p>Payment ID: #<?php echo $payment->id; ?> | Document printed by: <?php echo getCurrentUser()['display_name']; ?></p>
        <?php if ($payment->journal_entry_id): ?>
        <p style="color: #059669; font-weight: bold;">✓ Journal Entry Posted | Entry ID: <?php echo $payment->journal_entry_id; ?></p>
        <?php endif; ?>
    </div>
    
</div>

</body>
</html>
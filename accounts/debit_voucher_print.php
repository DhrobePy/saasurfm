<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts'];
restrict_access($allowed_roles);

global $db;

$voucher_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$voucher_id) {
    die('<div style="text-align:center; padding:50px; font-family:Arial;">
         <h2>Invalid Voucher ID</h2>
         <button onclick="window.close()" style="padding:10px 20px; background:#3b82f6; color:white; border:none; border-radius:5px; cursor:pointer;">Close</button>
         </div>');
}

// Get voucher details
$voucher = $db->query(
    "SELECT dv.*, 
            ea.name as expense_account_name,
            ea.account_number as expense_account_number,
            pa.name as payment_account_name,
            pa.account_number as payment_account_number,
            pa.account_type as payment_account_type,
            b.name as branch_name,
            u.display_name as created_by_name,
            approver.display_name as approved_by_name,
            CONCAT(e.first_name, ' ', e.last_name) as employee_name,
            e.email as employee_email
     FROM debit_vouchers dv
     LEFT JOIN chart_of_accounts ea ON dv.expense_account_id = ea.id
     LEFT JOIN chart_of_accounts pa ON dv.payment_account_id = pa.id
     LEFT JOIN branches b ON dv.branch_id = b.id
     LEFT JOIN users u ON dv.created_by_user_id = u.id
     LEFT JOIN users approver ON dv.approved_by_user_id = approver.id
     LEFT JOIN employees e ON dv.employee_id = e.id
     WHERE dv.id = ?",
    [$voucher_id]
)->first();

if (!$voucher) {
    die('<div style="text-align:center; padding:50px; font-family:Arial;">
         <h2>Voucher Not Found</h2>
         <button onclick="window.close()" style="padding:10px 20px; background:#3b82f6; color:white; border:none; border-radius:5px; cursor:pointer;">Close</button>
         </div>');
}

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

$amount_in_words = convertNumberToWords($voucher->amount);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Debit Voucher - <?php echo htmlspecialchars($voucher->voucher_number); ?></title>
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
        
        .voucher-container {
            max-width: 210mm;
            margin: 0 auto;
            background: white;
            padding: 20mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .header {
            text-align: center;
            border-bottom: 3px solid #dc2626;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .company-name {
            font-size: 28px;
            font-weight: bold;
            color: #1f2937;
            margin: 0;
        }
        
        .voucher-title {
            background: #dc2626;
            color: white;
            padding: 10px;
            font-size: 20px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 20px 0;
            text-align: center;
        }
        
        .voucher-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 20px;
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
        
        .amount-box {
            background: #fef3c7;
            border: 2px solid #f59e0b;
            padding: 15px;
            margin: 20px 0;
            text-align: center;
        }
        
        .amount-number {
            font-size: 32px;
            font-weight: bold;
            color: #dc2626;
            margin: 10px 0;
        }
        
        .amount-words {
            font-size: 14px;
            font-style: italic;
            color: #4b5563;
            margin-top: 5px;
        }
        
        .description-box {
            border: 1px solid #d1d5db;
            padding: 15px;
            margin: 20px 0;
            min-height: 80px;
            background: #f9fafb;
        }
        
        .description-label {
            font-weight: bold;
            color: #4b5563;
            margin-bottom: 8px;
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
        
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #dc2626;
            font-size: 11px;
            color: #6b7280;
            text-align: center;
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 24px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .print-button:hover {
            background: #2563eb;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-draft {
            background: #fef3c7;
            color: #92400e;
        }
    </style>
</head>
<body>

<button class="print-button no-print" onclick="window.print()">
    <i class="fas fa-print"></i> Print Voucher
</button>

<div class="voucher-container">
    
    <!-- Header -->
    <div class="header">
        <h1 class="company-name">উজ্জল ফ্লাওয়ার মিলস </h1>
        <p style="margin: 5px 0; color: #6b7280;">১৭, নুরাইবাগ , ডেমরা ঢাকা </p>
        <p style="margin: 5px 0; color: #6b7280;">+88-0XX-XXXXXXXX </p>
    </div>
    
    <!-- Voucher Title -->
    <div class="voucher-title">DEBIT VOUCHER</div>
    
    <!-- Voucher Info -->
    <div class="voucher-info">
        <div>
            <div class="info-row">
                <span class="info-label">Voucher No:</span>
                <span class="info-value" style="font-weight: bold; color: #dc2626;"><?php echo htmlspecialchars($voucher->voucher_number); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Date:</span>
                <span class="info-value"><?php echo date('d-M-Y', strtotime($voucher->voucher_date)); ?></span>
            </div>
            <?php if ($voucher->branch_name): ?>
            <div class="info-row">
                <span class="info-label">Branch:</span>
                <span class="info-value"><?php echo htmlspecialchars($voucher->branch_name); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <div>
            <div class="info-row">
                <span class="info-label">Status:</span>
                <span class="info-value">
                    <span class="status-badge status-<?php echo $voucher->status; ?>">
                        <?php echo strtoupper($voucher->status); ?>
                    </span>
                </span>
            </div>
            <?php if ($voucher->reference_number): ?>
            <div class="info-row">
                <span class="info-label">Reference:</span>
                <span class="info-value"><?php echo htmlspecialchars($voucher->reference_number); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Paid To -->
    <div class="info-row" style="background: #f3f4f6; padding: 12px; margin: 20px 0; border-radius: 5px;">
        <span class="info-label">Paid To:</span>
        <span class="info-value" style="font-size: 16px; font-weight: bold;"><?php echo htmlspecialchars($voucher->paid_to); ?></span>
    </div>
    
    <?php if ($voucher->employee_name): ?>
    <!-- Employee Info -->
    <div class="info-row" style="background: #eff6ff; padding: 12px; margin: 20px 0; border-radius: 5px;">
        <span class="info-label">Employee:</span>
        <span class="info-value">
            <?php echo htmlspecialchars($voucher->employee_name); ?>
            <?php if ($voucher->employee_email): ?>
            <span style="color: #6b7280; font-size: 12px;">(<?php echo htmlspecialchars($voucher->employee_email); ?>)</span>
            <?php endif; ?>
        </span>
    </div>
    <?php endif; ?>
    
    <!-- Amount -->
    <div class="amount-box">
        <div style="font-size: 14px; color: #6b7280; margin-bottom: 5px;">AMOUNT</div>
        <div class="amount-number">৳ <?php echo number_format($voucher->amount, 2); ?></div>
        <div class="amount-words"><?php echo $amount_in_words; ?></div>
    </div>
    
    <!-- Description/Narration -->
    <div class="description-box">
        <div class="description-label">Narration / Purpose of Payment:</div>
        <div><?php echo nl2br(htmlspecialchars($voucher->description)); ?></div>
    </div>
    
    <!-- Account Details -->
    <div style="margin: 20px 0;">
        <div class="info-row">
            <span class="info-label">Expense Account:</span>
            <span class="info-value">
                <?php if ($voucher->expense_account_number): ?>[<?php echo htmlspecialchars($voucher->expense_account_number); ?>] <?php endif; ?>
                <?php echo htmlspecialchars($voucher->expense_account_name); ?>
                <span style="color: #dc2626; font-weight: bold;">(Dr.)</span>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">Payment From:</span>
            <span class="info-value">
                <?php if ($voucher->payment_account_number): ?>[<?php echo htmlspecialchars($voucher->payment_account_number); ?>] <?php endif; ?>
                <?php echo htmlspecialchars($voucher->payment_account_name); ?>
                <span style="color: #059669; font-weight: bold;">(Cr.)</span>
            </span>
        </div>
    </div>
    
    <!-- Signatures -->
    <div class="signatures">
        <div class="signature-box">
            <div class="signature-line">Prepared By</div>
            <div style="font-size: 10px; margin-top: 5px;"><?php echo htmlspecialchars($voucher->created_by_name ?? ''); ?></div>
        </div>
        <div class="signature-box">
            <div class="signature-line">Approved By</div>
            <div style="font-size: 10px; margin-top: 5px;"><?php echo htmlspecialchars($voucher->approved_by_name ?? ''); ?></div>
        </div>
        <div class="signature-box">
            <div class="signature-line">Received By</div>
            <div style="font-size: 10px; margin-top: 5px;">
                <?php echo htmlspecialchars($voucher->employee_name ?: $voucher->paid_to); ?>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <div class="footer">
        <p>This is a computer generated voucher. Generated on <?php echo date('d-M-Y h:i A'); ?></p>
        <p>Journal Entry ID: <?php echo $voucher->journal_entry_id ?? 'N/A'; ?></p>
    </div>
    
</div>

</body>
</html>
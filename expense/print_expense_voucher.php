<?php
/**
 * Print Expense Voucher - Colorful Professional Design
 * Plain access - no permissions required
 */

require_once '../core/init.php';

global $db;

$expenseId = (int)($_GET['id'] ?? 0);

if (!$expenseId) {
    echo '<div style="padding: 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px;">
        <strong>Error:</strong> Invalid expense ID.
    </div>';
    exit();
}

// Fetch expense details
$sql = "SELECT 
        ev.*,
        ec.category_name,
        es.subcategory_name,
        b.name as branch_name,
        b.address as branch_address,
        creator.display_name as created_by_name,
        approver.display_name as approved_by_name,
        emp.display_name as employee_name,
        ba.account_number as bank_account_number,
        ba.account_name as bank_account_name,
        ba.bank_name,
        bpc.account_name as cash_account_name
    FROM expense_vouchers ev
    LEFT JOIN expense_categories ec ON ev.category_id = ec.id
    LEFT JOIN expense_subcategories es ON ev.subcategory_id = es.id
    LEFT JOIN branches b ON ev.branch_id = b.id
    LEFT JOIN users creator ON ev.created_by_user_id = creator.id
    LEFT JOIN users approver ON ev.approved_by_user_id = approver.id
    LEFT JOIN users emp ON ev.employee_id = emp.id
    LEFT JOIN bank_accounts ba ON ev.bank_account_id = ba.id
    LEFT JOIN branch_petty_cash_accounts bpc ON ev.cash_account_id = bpc.id
    WHERE ev.id = ?";

$expense = $db->query($sql, [$expenseId])->first();

if (!$expense) {
    echo '<div style="padding: 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px;">
        <strong>Error:</strong> Expense voucher not found.
    </div>';
    exit();
}

// Only print approved expenses
if ($expense->status !== 'approved') {
    echo '<div style="padding: 20px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 5px; margin: 20px;">
        <strong>Notice:</strong> This voucher is currently: <strong>' . ucfirst($expense->status) . '</strong>. Only approved vouchers can be printed.
    </div>';
    exit();
}

// Convert amount to words
function numberToWords($number) {
    $words = array(
        0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
        14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen',
        18 => 'Eighteen', 19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty',
        40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy',
        80 => 'Eighty', 90 => 'Ninety'
    );
    
    if ($number < 21) {
        return $words[$number];
    } elseif ($number < 100) {
        $tens = ((int)($number / 10)) * 10;
        $units = $number % 10;
        return $words[$tens] . ($units ? ' ' . $words[$units] : '');
    } elseif ($number < 1000) {
        $hundreds = (int)($number / 100);
        $remainder = $number % 100;
        return $words[$hundreds] . ' Hundred' . ($remainder ? ' and ' . numberToWords($remainder) : '');
    } elseif ($number < 100000) {
        $thousands = (int)($number / 1000);
        $remainder = $number % 1000;
        return numberToWords($thousands) . ' Thousand' . ($remainder ? ' ' . numberToWords($remainder) : '');
    } elseif ($number < 10000000) {
        $lakhs = (int)($number / 100000);
        $remainder = $number % 100000;
        return numberToWords($lakhs) . ' Lakh' . ($remainder ? ' ' . numberToWords($remainder) : '');
    } else {
        $crores = (int)($number / 10000000);
        $remainder = $number % 10000000;
        return numberToWords($crores) . ' Crore' . ($remainder ? ' ' . numberToWords($remainder) : '');
    }
}

$amountInWords = numberToWords((int)$expense->total_amount);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Expense Voucher - <?= htmlspecialchars($expense->voucher_number) ?></title>
    <style>
        @page {
            size: A4;
            margin: 10mm;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #333;
            background: #f5f5f5;
        }
        
        .voucher-container {
            width: 100%;
            max-width: 800px;
            margin: 20px auto;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg width="100" height="100" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40" fill="rgba(255,255,255,0.1)"/></svg>');
            opacity: 0.1;
        }
        
        .company-name {
            font-size: 28pt;
            font-weight: bold;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 2px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
            position: relative;
            z-index: 1;
        }
        
        .branch-info {
            font-size: 11pt;
            margin-bottom: 8px;
            opacity: 0.95;
            position: relative;
            z-index: 1;
        }
        
        .voucher-title {
            font-size: 18pt;
            font-weight: bold;
            margin-top: 15px;
            padding: 10px 30px;
            background: rgba(255,255,255,0.2);
            display: inline-block;
            border-radius: 25px;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            z-index: 1;
        }
        
        .status-badge {
            display: inline-block;
            background: #10b981;
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 10pt;
            margin-top: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            position: relative;
            z-index: 1;
        }
        
        .status-badge::before {
            content: '✓';
            margin-right: 5px;
            font-weight: bold;
        }
        
        .content {
            padding: 25px;
        }
        
        .voucher-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 25px;
            background: linear-gradient(135deg, #667eea15 0%, #764ba215 100%);
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        
        .meta-item {
            display: flex;
            flex-direction: column;
        }
        
        .meta-label {
            font-size: 9pt;
            color: #667eea;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .meta-value {
            font-size: 12pt;
            color: #1f2937;
            font-weight: 600;
        }
        
        .info-section {
            margin-bottom: 20px;
        }
        
        .section-title {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            padding: 10px 15px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 11pt;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        
        .section-title::before {
            content: '▶';
            margin-right: 10px;
            font-size: 8pt;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            background: #f9fafb;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 9pt;
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .info-value {
            font-size: 10pt;
            color: #1f2937;
            font-weight: 500;
        }
        
        .amount-section {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            padding: 25px;
            margin: 25px 0;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(245, 158, 11, 0.2);
            color: white;
        }
        
        .amount-label {
            font-weight: bold;
            font-size: 11pt;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.9;
        }
        
        .amount-number {
            font-size: 32pt;
            font-weight: bold;
            margin: 15px 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
            letter-spacing: 1px;
        }
        
        .amount-words {
            font-style: italic;
            margin: 12px 0;
            font-size: 12pt;
            border-top: 2px solid rgba(255,255,255,0.3);
            padding-top: 12px;
            font-weight: 500;
        }
        
        .remarks-section {
            margin: 20px 0;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .remarks-header {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
            padding: 10px 15px;
            font-weight: bold;
            font-size: 10pt;
        }
        
        .remarks-content {
            padding: 15px;
            font-size: 10pt;
            line-height: 1.7;
            min-height: 60px;
            background: #fefefe;
        }
        
        .signature-section {
            margin-top: 40px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        
        .signature-box {
            text-align: center;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
            border: 2px dashed #d1d5db;
        }
        
        .signature-line {
            margin-top: 50px;
            padding-top: 10px;
            border-top: 2px solid #374151;
            font-size: 9pt;
        }
        
        .signature-role {
            font-weight: bold;
            color: #374151;
            margin-bottom: 5px;
            font-size: 10pt;
        }
        
        .signature-name {
            font-weight: 600;
            color: #1f2937;
            margin-top: 5px;
        }
        
        .signature-date {
            font-size: 8pt;
            color: #6b7280;
            margin-top: 3px;
        }
        
        .footer {
            margin-top: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            border-radius: 8px;
            font-size: 8pt;
            text-align: center;
            color: #6b7280;
            border-top: 3px solid #667eea;
        }
        
        .print-button-area {
            text-align: center;
            padding: 20px;
            background: white;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .btn {
            padding: 12px 30px;
            font-size: 14pt;
            cursor: pointer;
            border: none;
            border-radius: 6px;
            margin: 0 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-print {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            box-shadow: 0 4px 6px rgba(59, 130, 246, 0.3);
        }
        
        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 8px rgba(59, 130, 246, 0.4);
        }
        
        .btn-close {
            background: #6b7280;
            color: white;
            box-shadow: 0 4px 6px rgba(107, 114, 128, 0.3);
        }
        
        .btn-close:hover {
            background: #4b5563;
            transform: translateY(-2px);
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            body {
                background: white;
                margin: 0;
            }
            
            .voucher-container {
                box-shadow: none;
                margin: 0;
                border-radius: 0;
            }
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 9pt;
            font-weight: 600;
        }
        
        .badge-bank {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .badge-cash {
            background: #d1fae5;
            color: #065f46;
        }
    </style>
</head>
<body>

<div class="no-print print-button-area">
    <button onclick="window.print()" class="btn btn-print">
        🖨️ Print Voucher
    </button>
    <button onclick="window.close()" class="btn btn-close">
        ✕ Close Window
    </button>
</div>

<div class="voucher-container">
    
    <!-- Colorful Header -->
    <div class="header">
        <div class="company-name">Ujjal Flour Mills</div>
        <div class="branch-info">
            <?= htmlspecialchars($expense->branch_name ?? 'Head Office') ?>
            <?php if ($expense->branch_address): ?>
                | <?= htmlspecialchars($expense->branch_address) ?>
            <?php endif; ?>
        </div>
        <div class="voucher-title">Expense Voucher</div>
        <div class="status-badge">APPROVED</div>
    </div>
    
    <div class="content">
        
        <!-- Voucher Meta Info -->
        <div class="voucher-meta">
            <div class="meta-item">
                <div class="meta-label">Voucher Number</div>
                <div class="meta-value"><?= htmlspecialchars($expense->voucher_number) ?></div>
            </div>
            <div class="meta-item">
                <div class="meta-label">Expense Date</div>
                <div class="meta-value"><?= date('F d, Y', strtotime($expense->expense_date)) ?></div>
            </div>
        </div>
        
        <!-- Expense Details Section -->
        <div class="info-section">
            <div class="section-title">Expense Details</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Category</div>
                    <div class="info-value"><?= htmlspecialchars($expense->category_name) ?></div>
                </div>
                
                <?php if ($expense->subcategory_name): ?>
                <div class="info-item">
                    <div class="info-label">Subcategory</div>
                    <div class="info-value"><?= htmlspecialchars($expense->subcategory_name) ?></div>
                </div>
                <?php endif; ?>
                
                <div class="info-item">
                    <div class="info-label">Payment Method</div>
                    <div class="info-value">
                        <span class="badge badge-<?= $expense->payment_method ?>">
                            <?= ucfirst($expense->payment_method) ?>
                        </span>
                    </div>
                </div>
                
                <?php if ($expense->handled_by_person): ?>
                <div class="info-item">
                    <div class="info-label">Handled By</div>
                    <div class="info-value"><?= htmlspecialchars($expense->handled_by_person) ?></div>
                </div>
                <?php endif; ?>
                
                <?php if ($expense->employee_name): ?>
                <div class="info-item">
                    <div class="info-label">Employee</div>
                    <div class="info-value"><?= htmlspecialchars($expense->employee_name) ?></div>
                </div>
                <?php endif; ?>
                
                <?php if ($expense->unit_quantity && $expense->per_unit_cost): ?>
                <div class="info-item">
                    <div class="info-label">Quantity</div>
                    <div class="info-value"><?= number_format($expense->unit_quantity, 2) ?> units</div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Per Unit Cost</div>
                    <div class="info-value">৳<?= number_format($expense->per_unit_cost, 2) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Payment Information Section -->
        <div class="info-section">
            <div class="section-title">Payment Information</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Payment Account</div>
                    <div class="info-value">
                        <?php if ($expense->payment_method === 'bank' && $expense->bank_name): ?>
                            <?= htmlspecialchars($expense->bank_name) ?>
                            <?php if ($expense->bank_account_number): ?>
                                <br><small>A/C: <?= htmlspecialchars($expense->bank_account_number) ?></small>
                            <?php endif; ?>
                        <?php elseif ($expense->payment_method === 'cash'): ?>
                            <?= htmlspecialchars($expense->payment_account_name ?? $expense->cash_account_name ?? 'Petty Cash') ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($expense->payment_reference): ?>
                <div class="info-item">
                    <div class="info-label">Reference Number</div>
                    <div class="info-value"><?= htmlspecialchars($expense->payment_reference) ?></div>
                </div>
                <?php endif; ?>
                
                <?php if ($expense->journal_entry_id): ?>
                <div class="info-item">
                    <div class="info-label">Journal Entry</div>
                    <div class="info-value">#<?= $expense->journal_entry_id ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Amount Section -->
        <div class="amount-section">
            <div class="amount-label">Total Amount</div>
            <div class="amount-number">৳ <?= number_format($expense->total_amount, 2) ?></div>
            <div class="amount-words"><?= $amountInWords ?> Taka Only</div>
        </div>
        
        <!-- Remarks Section -->
        <div class="remarks-section">
            <div class="remarks-header">Remarks / Purpose</div>
            <div class="remarks-content">
                <?php if ($expense->remarks): ?>
                    <?= nl2br(htmlspecialchars($expense->remarks)) ?>
                <?php else: ?>
                    <em style="color: #9ca3af;">No remarks provided</em>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Signature Section -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-role">Prepared By</div>
                <div class="signature-line">
                    <div class="signature-name"><?= htmlspecialchars($expense->created_by_name) ?></div>
                    <div class="signature-date"><?= date('M d, Y - h:i A', strtotime($expense->created_at)) ?></div>
                </div>
            </div>
            
            <div class="signature-box">
                <div class="signature-role">Approved By</div>
                <div class="signature-line">
                    <div class="signature-name">
                        <?= htmlspecialchars($expense->approved_by_name ?? 'Auto-Approved') ?>
                    </div>
                    <div class="signature-date">
                        <?php if ($expense->approved_at): ?>
                            <?= date('M d, Y - h:i A', strtotime($expense->approved_at)) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="signature-box">
                <div class="signature-role">Received By</div>
                <div class="signature-line">
                    <div class="signature-name">___________________</div>
                    <div class="signature-date">Date: ___________</div>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <strong>Ujjal Flour Mills ERP System</strong><br>
            This is a computer-generated voucher<br>
            Printed on: <?= date('F d, Y \a\t g:i A') ?>
        </div>
        
    </div>
    
</div>

<script>
// Print shortcut: Ctrl+P
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        e.preventDefault();
        window.print();
    }
});
</script>

</body>
</html>
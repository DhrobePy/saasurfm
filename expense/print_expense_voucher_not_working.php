<?php
/**
 * Print Expense Voucher
 * Professional printable format for approved expense vouchers
 * 
 * CORRECTED VERSION:
 * - Proper SQL table joins (expense_categories, expense_subcategories)
 * - Correct column names (voucher_number, total_amount, remarks)
 * - All fields from actual schema
 * - Professional print layout
 */

require_once '../core/init.php';
require_once '../core/classes/ExpenseManager.php';

global $db;

// Check if user is logged in (no role restriction)


$expenseId = (int)($_GET['id'] ?? 10);

if (!$expenseId) {
    echo '<div style="padding: 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px;">
        <strong>Error:</strong> Invalid expense ID.
    </div>';
    exit();
}

// Fetch expense details - CORRECTED SQL
$sql = "SELECT 
        ev.*,
        ec.category_name,
        es.subcategory_name,
        b.name as branch_name,
        b.address as branch_address,
        b.phone as branch_phone,
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
        <strong>Warning:</strong> Only approved expenses can be printed. This voucher is currently: <strong>' . ucfirst($expense->status) . '</strong>
    </div>';
    exit();
}

// Convert amount to words (Bangladeshi Taka)
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
            font-family: Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #000;
        }
        
        .voucher-container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            border: 2px solid #000;
            padding: 15px;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .company-name {
            font-size: 22pt;
            font-weight: bold;
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        
        .company-address {
            font-size: 9pt;
            margin-bottom: 5px;
            color: #333;
        }
        
        .voucher-title {
            font-size: 16pt;
            font-weight: bold;
            margin-top: 10px;
            text-decoration: underline;
            text-transform: uppercase;
        }
        
        .status-badge {
            display: inline-block;
            background-color: #28a745;
            color: white;
            padding: 4px 12px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 9pt;
            margin-top: 8px;
        }
        
        .voucher-info {
            margin: 15px 0;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .info-table td {
            padding: 6px;
            border: 1px solid #ccc;
            font-size: 10pt;
        }
        
        .info-table td.label {
            font-weight: bold;
            width: 180px;
            background-color: #f5f5f5;
        }
        
        .info-table td.value {
            background-color: #fff;
        }
        
        .amount-section {
            background-color: #f9f9f9;
            padding: 15px;
            margin: 15px 0;
            border: 2px solid #000;
            text-align: center;
        }
        
        .amount-label {
            font-weight: bold;
            font-size: 11pt;
            margin-bottom: 8px;
        }
        
        .amount-number {
            font-size: 24pt;
            font-weight: bold;
            margin: 10px 0;
            color: #000;
        }
        
        .amount-words {
            font-style: italic;
            margin: 8px 0;
            font-size: 11pt;
            border-top: 1px solid #ccc;
            padding-top: 8px;
        }
        
        .remarks-section {
            margin: 15px 0;
            border: 1px solid #000;
            padding: 10px;
            min-height: 60px;
        }
        
        .remarks-label {
            font-weight: bold;
            margin-bottom: 5px;
            font-size: 10pt;
            text-decoration: underline;
        }
        
        .remarks-content {
            font-size: 10pt;
            line-height: 1.6;
        }
        
        .signature-section {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
            gap: 20px;
        }
        
        .signature-box {
            text-align: center;
            flex: 1;
        }
        
        .signature-line {
            border-top: 1px solid #000;
            margin-top: 60px;
            padding-top: 5px;
            font-size: 9pt;
        }
        
        .signature-name {
            font-weight: bold;
            margin-top: 3px;
        }
        
        .signature-date {
            font-size: 8pt;
            color: #666;
            margin-top: 2px;
        }
        
        .footer {
            margin-top: 25px;
            padding-top: 10px;
            border-top: 1px solid #000;
            font-size: 8pt;
            text-align: center;
            color: #666;
        }
        
        .print-button-area {
            text-align: center;
            padding: 15px;
            background: #f0f0f0;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .btn {
            padding: 10px 25px;
            font-size: 14pt;
            cursor: pointer;
            border: none;
            border-radius: 5px;
            margin: 0 5px;
        }
        
        .btn-print {
            background-color: #007bff;
            color: white;
        }
        
        .btn-print:hover {
            background-color: #0056b3;
        }
        
        .btn-close {
            background-color: #6c757d;
            color: white;
        }
        
        .btn-close:hover {
            background-color: #545b62;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            body {
                margin: 0;
            }
            
            .voucher-container {
                border: 2px solid #000;
            }
        }
    </style>
</head>
<body>

<div class="no-print print-button-area">
    <button onclick="window.print()" class="btn btn-print">
        Print Voucher
    </button>
    <button onclick="window.close()" class="btn btn-close">
        Close Window
    </button>
</div>

<div class="voucher-container">
    
    <!-- Header -->
    <div class="header">
        <div class="company-name">Ujjal Flour Mills</div>
        <div class="company-address">
            <?= htmlspecialchars($expense->branch_name ?? 'Head Office') ?>
            <?php if ($expense->branch_address): ?>
                <br><?= htmlspecialchars($expense->branch_address) ?>
            <?php endif; ?>
            <?php if ($expense->branch_phone): ?>
                <br>Phone: <?= htmlspecialchars($expense->branch_phone) ?>
            <?php endif; ?>
        </div>
        <div class="voucher-title">Expense Voucher</div>
        <div class="status-badge">APPROVED</div>
    </div>
    
    <!-- Voucher Information -->
    <div class="voucher-info">
        <table class="info-table">
            <tr>
                <td class="label">Voucher Number:</td>
                <td class="value"><strong><?= htmlspecialchars($expense->voucher_number) ?></strong></td>
                <td class="label">Expense Date:</td>
                <td class="value"><strong><?= date('F d, Y', strtotime($expense->expense_date)) ?></strong></td>
            </tr>
            
            <tr>
                <td class="label">Category:</td>
                <td class="value">
                    <?= htmlspecialchars($expense->category_name) ?>
                    <?php if ($expense->subcategory_name): ?>
                        <br><small style="color: #666;">Subcategory: <?= htmlspecialchars($expense->subcategory_name) ?></small>
                    <?php endif; ?>
                </td>
                <td class="label">Payment Method:</td>
                <td class="value"><?= ucfirst($expense->payment_method) ?></td>
            </tr>
            
            <?php if ($expense->handled_by_person): ?>
            <tr>
                <td class="label">Handled By:</td>
                <td class="value"><?= htmlspecialchars($expense->handled_by_person) ?></td>
                <?php if ($expense->employee_name): ?>
                    <td class="label">Employee:</td>
                    <td class="value"><?= htmlspecialchars($expense->employee_name) ?></td>
                <?php else: ?>
                    <td colspan="2"></td>
                <?php endif; ?>
            </tr>
            <?php endif; ?>
            
            <?php if ($expense->unit_quantity && $expense->per_unit_cost): ?>
            <tr>
                <td class="label">Quantity:</td>
                <td class="value"><?= number_format($expense->unit_quantity, 2) ?> units</td>
                <td class="label">Per Unit Cost:</td>
                <td class="value">৳<?= number_format($expense->per_unit_cost, 2) ?></td>
            </tr>
            <?php endif; ?>
            
            <tr>
                <td class="label">Payment Account:</td>
                <td class="value">
                    <?php if ($expense->payment_method === 'bank' && $expense->bank_name): ?>
                        <?= htmlspecialchars($expense->bank_name) ?>
                        <?php if ($expense->bank_account_number): ?>
                            - A/C: <?= htmlspecialchars($expense->bank_account_number) ?>
                        <?php endif; ?>
                    <?php elseif ($expense->payment_method === 'cash'): ?>
                        <?= htmlspecialchars($expense->payment_account_name ?? $expense->cash_account_name ?? 'Petty Cash') ?>
                    <?php endif; ?>
                </td>
                <?php if ($expense->payment_reference): ?>
                    <td class="label">Reference:</td>
                    <td class="value"><?= htmlspecialchars($expense->payment_reference) ?></td>
                <?php else: ?>
                    <td colspan="2"></td>
                <?php endif; ?>
            </tr>
        </table>
    </div>
    
    <!-- Amount Section -->
    <div class="amount-section">
        <div class="amount-label">TOTAL AMOUNT</div>
        <div class="amount-number">৳ <?= number_format($expense->total_amount, 2) ?></div>
        <div class="amount-words">
            (<?= $amountInWords ?> Taka Only)
        </div>
    </div>
    
    <!-- Remarks/Description -->
    <div class="remarks-section">
        <div class="remarks-label">Remarks / Purpose:</div>
        <div class="remarks-content">
            <?php if ($expense->remarks): ?>
                <?= nl2br(htmlspecialchars($expense->remarks)) ?>
            <?php else: ?>
                <em style="color: #999;">No remarks provided</em>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Signature Section -->
    <div class="signature-section">
        <div class="signature-box">
            <div class="signature-line">
                <div style="font-weight: bold; margin-bottom: 3px;">Prepared By</div>
                <div class="signature-name"><?= htmlspecialchars($expense->created_by_name) ?></div>
                <div class="signature-date"><?= date('M d, Y - h:i A', strtotime($expense->created_at)) ?></div>
            </div>
        </div>
        
        <div class="signature-box">
            <div class="signature-line">
                <div style="font-weight: bold; margin-bottom: 3px;">Approved By</div>
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
            <div class="signature-line">
                <div style="font-weight: bold; margin-bottom: 3px;">Received By</div>
                <div class="signature-name">___________________</div>
                <div class="signature-date">Date: ___________</div>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <div class="footer">
        This is a computer-generated voucher.<br>
        Printed on: <?= date('F d, Y \a\t g:i A') ?> | 
        Printed by: <?= htmlspecialchars($_SESSION['user_display_name'] ?? 'System') ?>
        <?php if ($expense->journal_entry_id): ?>
            | Journal Entry: #<?= $expense->journal_entry_id ?>
        <?php endif; ?>
    </div>
    
</div>

<script>
// Optional: Auto-print on load (uncomment if needed)
// window.onload = function() { 
//     setTimeout(function() { window.print(); }, 500);
// }

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
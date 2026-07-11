<?php
/**
 * bank/receipt.php
 * Printable Bank Transaction Receipt
 * Opens in new tab, auto-print trigger
 */

require_once dirname(__DIR__) . '/core/init.php';
require_once dirname(__DIR__) . '/bank/BankManager.php';

restrict_access();

$currentUser = getCurrentUser();
$userId      = $currentUser['id'];
$userRole    = $currentUser['role'];

$adminRoles  = ['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg'];
$isAdmin     = in_array($userRole, $adminRoles);

$bankManager = new BankManager();
$txId        = (int)($_GET['id'] ?? 0);
$tx          = $bankManager->getTransactionById($txId);

if (!$tx) { die('Transaction not found.'); }
if (!$isAdmin && $tx->created_by_user_id != $userId) { die('Access denied.'); }

$isCredit = $tx->entry_type === 'credit';
require_once dirname(__DIR__) . '/templates/header.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt – <?php echo htmlspecialchars($tx->transaction_number); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f3f4f6; }

        .receipt-wrapper {
            max-width: 480px;
            margin: 30px auto;
            background: #fff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,0.1);
        }

        .receipt-header {
            background: <?php echo $isCredit ? '#059669' : '#dc2626'; ?>;
            color: #fff;
            padding: 24px;
            text-align: center;
        }
        .receipt-header h1 { font-size: 13px; opacity: 0.85; text-transform: uppercase; letter-spacing: 2px; }
        .receipt-header .company { font-size: 20px; font-weight: 700; margin: 4px 0; }
        .receipt-header .ref { font-size: 11px; opacity: 0.7; font-family: monospace; margin-top: 4px; }

        .amount-band {
            background: <?php echo $isCredit ? '#d1fae5' : '#fee2e2'; ?>;
            padding: 20px;
            text-align: center;
            border-bottom: 2px dashed #e5e7eb;
        }
        .amount-label { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 1px; }
        .amount-value { font-size: 40px; font-weight: 800; color: <?php echo $isCredit ? '#065f46' : '#991b1b'; ?>; margin: 4px 0; }
        .entry-badge {
            display: inline-block;
            padding: 4px 16px;
            border-radius: 99px;
            font-size: 12px;
            font-weight: 700;
            background: <?php echo $isCredit ? '#059669' : '#dc2626'; ?>;
            color: #fff;
        }

        .receipt-body { padding: 20px 24px; }

        .row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 8px 0;
            border-bottom: 1px dashed #f3f4f6;
        }
        .row:last-child { border-bottom: none; }
        .row .label { font-size: 11px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; }
        .row .value { font-size: 13px; color: #1f2937; font-weight: 600; text-align: right; max-width: 60%; word-break: break-word; }
        .row .value.mono { font-family: monospace; }

        .status-band {
            margin: 16px 0;
            padding: 10px 16px;
            border-radius: 8px;
            text-align: center;
            font-size: 12px;
            font-weight: 700;
            <?php
            $s = $tx->status;
            if ($s === 'approved')  echo 'background:#d1fae5; color:#065f46;';
            elseif ($s === 'pending') echo 'background:#fef3c7; color:#92400e;';
            else echo 'background:#fee2e2; color:#991b1b;';
            ?>
        }

        .note-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 10px 14px;
            margin-top: 12px;
        }
        .note-box .nt { font-size: 10px; color: #d97706; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; margin-bottom: 4px; }
        .note-box p { font-size: 12px; color: #78350f; }

        .receipt-footer {
            background: #f9fafb;
            padding: 14px 24px;
            text-align: center;
            border-top: 1px solid #e5e7eb;
        }
        .receipt-footer p { font-size: 11px; color: #9ca3af; }
        .receipt-footer .power { font-size: 10px; color: #d1d5db; margin-top: 2px; }

        .print-btn {
            display: block;
            margin: 16px auto;
            padding: 10px 32px;
            background: #1d4ed8;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .print-btn:hover { background: #1e40af; }

        @media print {
            body { background: #fff; }
            .receipt-wrapper { box-shadow: none; margin: 0; border-radius: 0; }
            .print-btn { display: none; }
        }
    </style>
</head>
<body>

<div class="receipt-wrapper">

    <div class="receipt-header">
        <h1>Bank Transaction Receipt</h1>
        <div class="company">Ujjal Flour Mills</div>
        <div class="ref"><?php echo htmlspecialchars($tx->transaction_number); ?></div>
    </div>

    <div class="amount-band">
        <div class="amount-label"><?php echo $isCredit ? 'Amount Received' : 'Amount Paid'; ?></div>
        <div class="amount-value">৳<?php echo number_format($tx->amount, 2); ?></div>
        <span class="entry-badge"><?php echo $isCredit ? '⬇ Credit / Money In' : '⬆ Debit / Money Out'; ?></span>
    </div>

    <div class="receipt-body">

        <div class="status-band">
            STATUS: <?php echo strtoupper($tx->status); ?>
            <?php if ($tx->status === 'approved' && $tx->approved_by_name): ?>
            — Verified by <?php echo htmlspecialchars($tx->approved_by_name); ?>
            <?php endif; ?>
        </div>

        <?php
        $rows = [
            ['Date',           date('d M Y', strtotime($tx->transaction_date))],
            ['Bank',           $tx->bank_name],
            ['Account Name',   $tx->account_name],
            ['Account No.',    $tx->account_number],
            ['Category',       $tx->type_name ?: '—'],
            ['Reference',      $tx->reference_number ?: '—'],
            ['Cheque No.',     $tx->cheque_number ?: '—'],
            ['Payee / Payer',  $tx->payee_payer_name ?: '—'],
            ['Branch',         $tx->branch_name ?: 'Head Office'],
            ['Submitted By',   $tx->created_by_name],
            ['Submitted At',   date('d M Y H:i', strtotime($tx->created_at))],
        ];
        foreach ($rows as $r):
        ?>
        <div class="row">
            <span class="label"><?php echo $r[0]; ?></span>
            <span class="value <?php echo in_array($r[0], ['Reference','Cheque No.','Account No.']) ? 'mono' : ''; ?>">
                <?php echo htmlspecialchars((string)$r[1]); ?>
            </span>
        </div>
        <?php endforeach; ?>

        <?php if ($tx->description): ?>
        <div class="note-box mt-3">
            <div class="nt">Description</div>
            <p><?php echo htmlspecialchars($tx->description); ?></p>
        </div>
        <?php endif; ?>

        <?php if ($tx->special_note): ?>
        <div class="note-box">
            <div class="nt">Special Note</div>
            <p><?php echo htmlspecialchars($tx->special_note); ?></p>
        </div>
        <?php endif; ?>

    </div>

    <div class="receipt-footer">
        <p>Printed: <?php echo date('d M Y, H:i'); ?> | ERP-UFM</p>
        <p class="power">Ujjal Flour Mills ERP System</p>
    </div>

</div>

<button class="print-btn" onclick="window.print()">🖨 Print Receipt</button>

<script>
// Auto-print on load if param present
<?php if (!empty($_GET['autoprint'])): ?>
window.onload = () => setTimeout(() => window.print(), 600);
<?php endif; ?>
</script>

</body>
</html>
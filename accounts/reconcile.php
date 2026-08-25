<?php
/**
 * Bank & Cash Reconciliation
 * Per-account, per-date transaction ledger with automated error-signal detection.
 *
 * @package    Ujjal Flour Mills ERP
 * @subpackage Accounts Module
 */
require_once '../core/init.php';
require_once '../core/config/config.php';
require_once '../core/classes/Database.php';
require_once '../core/functions/helpers.php';

restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

global $db;
$pageTitle   = 'Reconciliation';
$currentUser = getCurrentUser();
$csrfToken   = $_SESSION['csrf_token'] ?? '';

// ── Auto-create reconciliations table ────────────────────────────────────────
try {
    $pdo = Database::getInstance()->getPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS bank_reconciliations (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        account_type        VARCHAR(20)   NOT NULL,
        account_id          INT           NOT NULL,
        account_label       VARCHAR(255)  NOT NULL,
        recon_date          DATE          NOT NULL,
        erp_opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
        erp_total_in        DECIMAL(15,2) NOT NULL DEFAULT 0,
        erp_total_out       DECIMAL(15,2) NOT NULL DEFAULT 0,
        erp_closing_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
        actual_balance      DECIMAL(15,2) NOT NULL DEFAULT 0,
        difference          DECIMAL(15,2) NOT NULL DEFAULT 0,
        recon_status        VARCHAR(20)   NOT NULL DEFAULT 'pending',
        notes               TEXT          NULL,
        verified_by_user_id INT           NULL,
        verified_by_name    VARCHAR(255)  NULL,
        created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_acct_date (account_type, account_id, recon_date),
        INDEX idx_date (recon_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {
    // Table probably already exists; continue
}

// ── POST: Save reconciliation (PRG) ──────────────────────────────────────────
$flash_success = $flash_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_reconciliation'])) {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        $flash_error = 'Security token mismatch. Please refresh and try again.';
    } else {
        $p_type    = $_POST['account_type']  ?? '';
        $p_id      = (int)($_POST['account_id'] ?? 0);
        $p_label   = trim($_POST['account_label'] ?? '');
        $p_date    = $_POST['recon_date']    ?? date('Y-m-d');
        $p_actual  = (float)str_replace(',', '', $_POST['actual_balance'] ?? '0');
        $p_opening = (float)($_POST['erp_opening'] ?? 0);
        $p_in      = (float)($_POST['erp_in']      ?? 0);
        $p_out     = (float)($_POST['erp_out']     ?? 0);
        $p_closing = (float)($_POST['erp_closing'] ?? 0);
        $p_notes   = trim($_POST['notes'] ?? '');
        $p_diff    = $p_actual - $p_closing;
        $p_status  = abs($p_diff) < 0.01 ? 'matched' : 'discrepancy';

        if (!$p_type || !$p_id || !$p_date) {
            $flash_error = 'Missing account or date.';
        } else {
            try {
                $pdo = Database::getInstance()->getPdo();
                $stmt = $pdo->prepare(
                    "INSERT INTO bank_reconciliations
                        (account_type, account_id, account_label, recon_date,
                         erp_opening_balance, erp_total_in, erp_total_out, erp_closing_balance,
                         actual_balance, difference, recon_status, notes,
                         verified_by_user_id, verified_by_name)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                        account_label=VALUES(account_label),
                        erp_opening_balance=VALUES(erp_opening_balance),
                        erp_total_in=VALUES(erp_total_in),
                        erp_total_out=VALUES(erp_total_out),
                        erp_closing_balance=VALUES(erp_closing_balance),
                        actual_balance=VALUES(actual_balance),
                        difference=VALUES(difference),
                        recon_status=VALUES(recon_status),
                        notes=VALUES(notes),
                        verified_by_user_id=VALUES(verified_by_user_id),
                        verified_by_name=VALUES(verified_by_name),
                        updated_at=NOW()"
                );
                $stmt->execute([
                    $p_type, $p_id, $p_label, $p_date,
                    $p_opening, $p_in, $p_out, $p_closing,
                    $p_actual, $p_diff, $p_status, $p_notes ?: null,
                    $currentUser['id'],
                    $currentUser['display_name'] ?? $currentUser['name'] ?? 'Unknown',
                ]);
                header('Location: reconcile.php?account_type=' . urlencode($p_type)
                    . '&account_id=' . $p_id . '&date=' . urlencode($p_date) . '&saved=1');
                exit;
            } catch (\Throwable $e) {
                $flash_error = 'Save failed: ' . $e->getMessage();
            }
        }
    }
}

$show_saved = isset($_GET['saved']);

// ── Build unified account list ────────────────────────────────────────────────
$all_accounts = [];

$bank_accts = $db->query(
    "SELECT id, bank_name, branch_name, account_name, account_number,
            account_type, chart_of_account_id, initial_balance, current_balance
     FROM bank_accounts WHERE status != 'closed'
     ORDER BY bank_name, account_name"
)->results() ?: [];
foreach ($bank_accts as $a) {
    $lbl = trim(($a->bank_name ?? '') . ' — ' . ($a->account_name ?? ''));
    if ($a->account_number) $lbl .= ' (' . $a->account_number . ')';
    if ($a->branch_name)    $lbl .= ' [' . $a->branch_name . ']';
    $all_accounts[] = ['type' => 'bank', 'id' => (int)$a->id, 'label' => $lbl, 'raw' => $a, 'group' => 'Bank Accounts'];
}

try {
    $bktx_accts = $db->query(
        "SELECT id, bank_name, account_name, account_number, opening_balance
         FROM bank_tx_accounts WHERE status = 'active'
         ORDER BY bank_name, account_name"
    )->results() ?: [];
} catch (\Throwable $e) { $bktx_accts = []; }
foreach ($bktx_accts as $a) {
    $lbl = trim(($a->bank_name ?? '') . ' — ' . ($a->account_name ?? ''));
    if ($a->account_number) $lbl .= ' (' . $a->account_number . ')';
    $all_accounts[] = ['type' => 'bank_tx', 'id' => (int)$a->id, 'label' => $lbl, 'raw' => $a, 'group' => 'Bank Ledger (TX Module)'];
}

$cash_accts = $db->query(
    "SELECT pc.id, pc.account_name, pc.current_balance, pc.branch_id, b.name AS branch_name
     FROM branch_petty_cash_accounts pc
     LEFT JOIN branches b ON pc.branch_id = b.id
     WHERE pc.status = 'active'
     ORDER BY b.name, pc.account_name"
)->results() ?: [];
foreach ($cash_accts as $a) {
    $lbl = ($a->account_name ?? '') . ($a->branch_name ? ' — ' . $a->branch_name : '');
    $all_accounts[] = ['type' => 'cash', 'id' => (int)$a->id, 'label' => $lbl, 'raw' => $a, 'group' => 'Cash (Petty Cash)'];
}

// ── Request params ────────────────────────────────────────────────────────────
$sel_type = $_GET['account_type'] ?? '';
$sel_id   = (int)($_GET['account_id'] ?? 0);
$sel_date = $_GET['date'] ?? date('Y-m-d');

$sel_account = null;
$sel_label   = '';
foreach ($all_accounts as $a) {
    if ($a['type'] === $sel_type && $a['id'] === $sel_id) {
        $sel_account = $a;
        $sel_label   = $a['label'];
        break;
    }
}

// ── Risk flag metadata ────────────────────────────────────────────────────────
$FLAG_META = [
    'BACKDATED'    => ['icon' => '🕒', 'label' => 'Backdated',      'cls' => 'bg-red-100 text-red-800 border-red-200',        'tip' => 'Entered on a different date than the transaction date — possible retroactive change'],
    'AFTER_HOURS'  => ['icon' => '🌙', 'label' => 'After Hours',    'cls' => 'bg-orange-100 text-orange-800 border-orange-200','tip' => 'Recorded before 7 AM or after 9 PM'],
    'NO_REF'       => ['icon' => '❓', 'label' => 'No Reference',   'cls' => 'bg-yellow-100 text-yellow-800 border-yellow-200','tip' => 'No reference number — harder to trace to a source document'],
    'ROUND_AMT'    => ['icon' => '🔢', 'label' => 'Round Number',   'cls' => 'bg-yellow-100 text-yellow-800 border-yellow-200','tip' => 'Suspiciously round amount — may be an estimate rather than the actual figure'],
    'LARGE_TX'     => ['icon' => '💰', 'label' => 'Large Amount',   'cls' => 'bg-red-100 text-red-800 border-red-200',        'tip' => 'Single transaction ≥ ৳1,00,000 — verify against source document'],
    'UNALLOCATED'  => ['icon' => '⛓', 'label' => 'Unallocated',    'cls' => 'bg-red-100 text-red-800 border-red-200',        'tip' => 'Payment not fully allocated to an invoice — part of the amount is floating'],
    'UNAPPROVED'   => ['icon' => '⏳', 'label' => 'Unapproved',     'cls' => 'bg-amber-100 text-amber-800 border-amber-200',  'tip' => 'Not yet approved — excluded from official balance calculations'],
    'RAPID_ENTRY'  => ['icon' => '⚡', 'label' => 'Rapid Entry',    'cls' => 'bg-purple-100 text-purple-800 border-purple-200','tip' => 'Same operator entered another large transaction within 2 minutes — review for keying errors'],
    'DUPLICATE_AMT'=> ['icon' => '♊', 'label' => 'Duplicate ৳',    'cls' => 'bg-pink-100 text-pink-800 border-pink-200',     'tip' => 'Another transaction of the exact same amount exists on this account today'],
    'BAL_MISMATCH' => ['icon' => '⚖️','label' => 'Balance Break',   'cls' => 'bg-red-100 text-red-800 border-red-200',        'tip' => 'Stored running balance does not match computed running balance — data may have been edited after posting'],
];

// ── Fetch transactions & compute balances ─────────────────────────────────────
$txns        = [];
$erp_opening = 0.0;
$erp_in      = 0.0;
$erp_out     = 0.0;
$erp_closing = 0.0;
$balance_note = '';

if ($sel_account) {
    $type = $sel_type;
    $id   = $sel_id;
    $date = $sel_date;

    // ────────────────────────────────────────────────────────────────────────
    // BANK TX MODULE (bank_tx_accounts)
    // ────────────────────────────────────────────────────────────────────────
    if ($type === 'bank_tx') {
        $ob = (float)($sel_account['raw']->opening_balance ?? 0);
        $pre = $db->query(
            "SELECT COALESCE(SUM(CASE WHEN entry_type='credit' AND status='approved' THEN amount ELSE 0 END),0) -
                    COALESCE(SUM(CASE WHEN entry_type='debit'  AND status='approved' THEN amount ELSE 0 END),0) AS net
             FROM bank_transactions
             WHERE bank_tx_account_id=? AND DATE(transaction_date)<? AND status='approved'",
            [$id, $date]
        )->first();
        $erp_opening  = $ob + (float)($pre->net ?? 0);
        $balance_note = 'Opening = account opening balance + all approved transactions before ' . $date;

        $day_txs = $db->query(
            "SELECT bt.*, u.display_name AS entered_by_name
             FROM bank_transactions bt
             LEFT JOIN users u ON bt.created_by_user_id = u.id
             WHERE bt.bank_tx_account_id=? AND DATE(bt.transaction_date)=?
             ORDER BY bt.created_at ASC",
            [$id, $date]
        )->results() ?: [];

        foreach ($day_txs as $tx) {
            $dir = $tx->entry_type === 'credit' ? 'in' : 'out';
            $amt = (float)$tx->amount;
            $ref = $tx->reference_number ?? '';
            $txn_dt  = date('Y-m-d', strtotime($tx->transaction_date ?? $date));
            $crt_dt  = date('Y-m-d', strtotime($tx->created_at));
            $crt_hr  = (int)date('H', strtotime($tx->created_at));
            $flags = [];
            if ($crt_dt !== $txn_dt)                  $flags[] = 'BACKDATED';
            if ($crt_hr >= 21 || $crt_hr < 7)         $flags[] = 'AFTER_HOURS';
            if (trim($ref) === '')                     $flags[] = 'NO_REF';
            if ($amt >= 100000)                        $flags[] = 'LARGE_TX';
            if ($amt >= 10000 && fmod($amt,10000)<0.01) $flags[] = 'ROUND_AMT';
            if (!in_array($tx->status??'',['approved'])) $flags[] = 'UNAPPROVED';
            if ($dir === 'in' && in_array($tx->status??'',['approved'])) $erp_in  += $amt;
            if ($dir === 'out'&& in_array($tx->status??'',['approved'])) $erp_out += $amt;
            $txns[] = [
                'sort_key'      => $tx->created_at,
                'direction'     => $dir,
                'amount'        => $amt,
                'reference'     => $tx->transaction_number ?? $ref,
                'description'   => trim(($tx->payee_payer_name ?? '') . ($tx->description ? ' — '.$tx->description : '')),
                'entered_by'    => $tx->entered_by_name ?? '—',
                'entered_by_id' => (int)($tx->created_by_user_id ?? 0),
                'created_at'    => $tx->created_at,
                'txn_date'      => $txn_dt,
                'module'        => 'Bank Transaction',
                'mod_cls'       => 'bg-blue-100 text-blue-800',
                'source_url'    => '../bank/view_transaction.php?id='.$tx->id,
                'extra'         => 'Status: '.ucfirst($tx->status??'—').($tx->special_note ? ' · '.$tx->special_note : ''),
                'stored_bal'    => null,
                'flags'         => $flags,
            ];
        }

        // Transfers IN
        $xin = $db->query(
            "SELECT btt.*, fa.account_name AS from_acct, fa.bank_name AS from_bank,
                    u.display_name AS entered_by_name
             FROM bank_tx_transfers btt
             LEFT JOIN bank_tx_accounts fa ON btt.from_account_id=fa.id
             LEFT JOIN users u ON btt.initiated_by_user_id=u.id
             WHERE btt.to_account_id=? AND DATE(btt.transfer_date)=?
             ORDER BY btt.created_at ASC", [$id, $date]
        )->results() ?: [];
        foreach ($xin as $tx) {
            $amt = (float)$tx->amount; $txn_dt = date('Y-m-d',strtotime($tx->transfer_date));
            $crt_dt = date('Y-m-d',strtotime($tx->created_at)); $crt_hr = (int)date('H',strtotime($tx->created_at));
            $flags = [];
            if ($crt_dt !== $txn_dt) $flags[] = 'BACKDATED';
            if ($crt_hr >= 21 || $crt_hr < 7) $flags[] = 'AFTER_HOURS';
            if (empty($tx->reference_number??'')) $flags[] = 'NO_REF';
            if ($amt >= 100000) $flags[] = 'LARGE_TX';
            if ($amt >= 10000 && fmod($amt,10000)<0.01) $flags[] = 'ROUND_AMT';
            $approved = in_array($tx->status??'',['approved','completed']);
            if (!$approved) $flags[] = 'UNAPPROVED';
            if ($approved) $erp_in += $amt;
            $txns[] = [
                'sort_key'=>$tx->created_at,'direction'=>'in','amount'=>$amt,
                'reference'=>$tx->transfer_number??'',
                'description'=>'Transfer from '.($tx->from_bank??'').($tx->from_acct?' — '.$tx->from_acct:'').($tx->description?' · '.$tx->description:''),
                'entered_by'=>$tx->entered_by_name??'—','entered_by_id'=>(int)($tx->initiated_by_user_id??0),
                'created_at'=>$tx->created_at,'txn_date'=>$txn_dt,'module'=>'Transfer In','mod_cls'=>'bg-teal-100 text-teal-800',
                'source_url'=>'../bank/transfer.php','extra'=>'Status: '.ucfirst($tx->status??''),'stored_bal'=>null,'flags'=>$flags,
            ];
        }

        // Transfers OUT
        $xout = $db->query(
            "SELECT btt.*, ta.account_name AS to_acct, ta.bank_name AS to_bank,
                    u.display_name AS entered_by_name
             FROM bank_tx_transfers btt
             LEFT JOIN bank_tx_accounts ta ON btt.to_account_id=ta.id
             LEFT JOIN users u ON btt.initiated_by_user_id=u.id
             WHERE btt.from_account_id=? AND DATE(btt.transfer_date)=?
             ORDER BY btt.created_at ASC", [$id, $date]
        )->results() ?: [];
        foreach ($xout as $tx) {
            $amt = (float)$tx->amount; $txn_dt = date('Y-m-d',strtotime($tx->transfer_date));
            $crt_dt = date('Y-m-d',strtotime($tx->created_at)); $crt_hr = (int)date('H',strtotime($tx->created_at));
            $flags = [];
            if ($crt_dt !== $txn_dt) $flags[] = 'BACKDATED';
            if ($crt_hr >= 21 || $crt_hr < 7) $flags[] = 'AFTER_HOURS';
            if (empty($tx->reference_number??'')) $flags[] = 'NO_REF';
            if ($amt >= 100000) $flags[] = 'LARGE_TX';
            if ($amt >= 10000 && fmod($amt,10000)<0.01) $flags[] = 'ROUND_AMT';
            $approved = in_array($tx->status??'',['approved','completed']);
            if (!$approved) $flags[] = 'UNAPPROVED';
            if ($approved) $erp_out += $amt;
            $txns[] = [
                'sort_key'=>$tx->created_at,'direction'=>'out','amount'=>$amt,
                'reference'=>$tx->transfer_number??'',
                'description'=>'Transfer to '.($tx->to_bank??'').($tx->to_acct?' — '.$tx->to_acct:'').($tx->description?' · '.$tx->description:''),
                'entered_by'=>$tx->entered_by_name??'—','entered_by_id'=>(int)($tx->initiated_by_user_id??0),
                'created_at'=>$tx->created_at,'txn_date'=>$txn_dt,'module'=>'Transfer Out','mod_cls'=>'bg-teal-100 text-teal-800',
                'source_url'=>'../bank/transfer.php','extra'=>'Status: '.ucfirst($tx->status??''),'stored_bal'=>null,'flags'=>$flags,
            ];
        }
        $erp_closing = $erp_opening + $erp_in - $erp_out;
    }

    // ────────────────────────────────────────────────────────────────────────
    // BANK ACCOUNTS (customer/supplier payments)
    // ────────────────────────────────────────────────────────────────────────
    elseif ($type === 'bank') {
        $raw    = $sel_account['raw'];
        $coa_id = $raw->chart_of_account_id ?? null;
        $initial= (float)($raw->initial_balance ?? 0);

        if ($coa_id) {
            $pre = $db->query(
                "SELECT COALESCE(SUM(tl.debit_amount),0) AS d, COALESCE(SUM(tl.credit_amount),0) AS c
                 FROM transaction_lines tl
                 JOIN journal_entries je ON je.id=tl.journal_entry_id AND DATE(je.transaction_date)<?
                 WHERE tl.account_id=?", [$date, $coa_id]
            )->first();
            $erp_opening  = $initial + (float)($pre->d??0) - (float)($pre->c??0);
            $balance_note = 'Opening computed from journal entries (Debit-normal asset account)';
        } else {
            $erp_opening  = (float)($raw->current_balance ?? 0);
            $balance_note = 'No chart-of-account link — showing stored current balance (approximate)';
        }

        // Customer payments IN
        $cpmts = $db->query(
            "SELECT cp.*, c.name AS customer_name, c.phone_number AS cphone,
                    u.display_name AS entered_by_name
             FROM customer_payments cp
             LEFT JOIN customers c ON cp.customer_id=c.id
             LEFT JOIN users u ON cp.created_by_user_id=u.id
             WHERE cp.bank_account_id=? AND DATE(cp.payment_date)=?
             ORDER BY cp.created_at ASC", [$id, $date]
        )->results() ?: [];

        foreach ($cpmts as $tx) {
            $amt = (float)$tx->amount; $ref = $tx->payment_number??'';
            $txn_dt = date('Y-m-d',strtotime($tx->payment_date));
            $crt_dt = date('Y-m-d',strtotime($tx->created_at)); $crt_hr = (int)date('H',strtotime($tx->created_at));
            $flags = [];
            if ($crt_dt !== $txn_dt) $flags[] = 'BACKDATED';
            if ($crt_hr >= 21 || $crt_hr < 7) $flags[] = 'AFTER_HOURS';
            if (trim($ref) === '') $flags[] = 'NO_REF';
            if ($amt >= 100000) $flags[] = 'LARGE_TX';
            if ($amt >= 10000 && fmod($amt,10000)<0.01) $flags[] = 'ROUND_AMT';
            if (($tx->allocation_status??'') !== 'fully_allocated') $flags[] = 'UNALLOCATED';
            $erp_in += $amt;
            $txns[] = [
                'sort_key'=>$tx->created_at,'direction'=>'in','amount'=>$amt,'reference'=>$ref,
                'description'=>($tx->customer_name??'Customer').($tx->cphone?' · '.$tx->cphone:''),
                'entered_by'=>$tx->entered_by_name??'—','entered_by_id'=>(int)($tx->created_by_user_id??0),
                'created_at'=>$tx->created_at,'txn_date'=>$txn_dt,'module'=>'Customer Payment','mod_cls'=>'bg-green-100 text-green-800',
                'source_url'=>'../cr/customer_payment.php?id='.$tx->id,
                'extra'=>'Alloc: '.ucfirst(str_replace('_',' ',$tx->allocation_status??'?')).' · '.($tx->payment_method??''),
                'stored_bal'=>null,'flags'=>$flags,
            ];
        }

        // Purchase payments OUT (wheat module)
        $ppmts = $db->query(
            "SELECT pp.*, u.display_name AS entered_by_name
             FROM purchase_payments_adnan pp
             LEFT JOIN users u ON pp.created_by_user_id=u.id
             WHERE pp.bank_account_id=? AND DATE(pp.payment_date)=?
             ORDER BY pp.created_at ASC", [$id, $date]
        )->results() ?: [];

        foreach ($ppmts as $tx) {
            $amt = (float)$tx->amount_paid; $ref = $tx->payment_voucher_number??'';
            $txn_dt = date('Y-m-d',strtotime($tx->payment_date));
            $crt_dt = date('Y-m-d',strtotime($tx->created_at)); $crt_hr = (int)date('H',strtotime($tx->created_at));
            $flags = [];
            if ($crt_dt !== $txn_dt) $flags[] = 'BACKDATED';
            if ($crt_hr >= 21 || $crt_hr < 7) $flags[] = 'AFTER_HOURS';
            if (trim($ref) === '') $flags[] = 'NO_REF';
            if ($amt >= 100000) $flags[] = 'LARGE_TX';
            if ($amt >= 10000 && fmod($amt,10000)<0.01) $flags[] = 'ROUND_AMT';
            $erp_out += $amt;
            $txns[] = [
                'sort_key'=>$tx->created_at,'direction'=>'out','amount'=>$amt,'reference'=>$ref,
                'description'=>($tx->supplier_name??'Supplier').($tx->po_number?' · PO#'.$tx->po_number:''),
                'entered_by'=>$tx->entered_by_name??'—','entered_by_id'=>(int)($tx->created_by_user_id??0),
                'created_at'=>$tx->created_at,'txn_date'=>$txn_dt,'module'=>'Purchase Payment','mod_cls'=>'bg-purple-100 text-purple-800',
                'source_url'=>'../purchase/purchase_adnan_supplier_ledger.php?supplier_id='.($tx->supplier_id??''),
                'extra'=>($tx->reference_number?'Ref: '.$tx->reference_number:'').($tx->remarks?' · '.$tx->remarks:''),
                'stored_bal'=>null,'flags'=>$flags,
            ];
        }

        // General supplier payments OUT (via chart_of_account link)
        if ($coa_id) {
            $spmts = $db->query(
                "SELECT sp.*, s.company_name AS supplier_name, u.display_name AS entered_by_name
                 FROM supplier_payments sp
                 LEFT JOIN suppliers s ON sp.supplier_id=s.id
                 LEFT JOIN users u ON sp.created_by_user_id=u.id
                 WHERE sp.payment_account_id=? AND DATE(sp.payment_date)=?
                 ORDER BY sp.created_at ASC", [$coa_id, $date]
            )->results() ?: [];
            foreach ($spmts as $tx) {
                $amt = (float)$tx->amount; $ref = $tx->payment_number??'';
                $txn_dt = date('Y-m-d',strtotime($tx->payment_date));
                $crt_dt = date('Y-m-d',strtotime($tx->created_at)); $crt_hr = (int)date('H',strtotime($tx->created_at));
                $flags = [];
                if ($crt_dt !== $txn_dt) $flags[] = 'BACKDATED';
                if ($crt_hr >= 21 || $crt_hr < 7) $flags[] = 'AFTER_HOURS';
                if (trim($ref) === '') $flags[] = 'NO_REF';
                if ($amt >= 100000) $flags[] = 'LARGE_TX';
                if ($amt >= 10000 && fmod($amt,10000)<0.01) $flags[] = 'ROUND_AMT';
                $erp_out += $amt;
                $txns[] = [
                    'sort_key'=>$tx->created_at,'direction'=>'out','amount'=>$amt,'reference'=>$ref,
                    'description'=>$tx->supplier_name??'Supplier',
                    'entered_by'=>$tx->entered_by_name??'—','entered_by_id'=>(int)($tx->created_by_user_id??0),
                    'created_at'=>$tx->created_at,'txn_date'=>$txn_dt,'module'=>'Supplier Payment','mod_cls'=>'bg-orange-100 text-orange-800',
                    'source_url'=>'#','extra'=>ucfirst($tx->payment_method??''),'stored_bal'=>null,'flags'=>$flags,
                ];
            }
        }
        $erp_closing = $erp_opening + $erp_in - $erp_out;
    }

    // ────────────────────────────────────────────────────────────────────────
    // CASH (branch_petty_cash_accounts)
    // ────────────────────────────────────────────────────────────────────────
    elseif ($type === 'cash') {
        $prev = $db->query(
            "SELECT balance_after FROM branch_petty_cash_transactions
             WHERE account_id=? AND DATE(transaction_date)<? AND balance_after IS NOT NULL
             ORDER BY transaction_date DESC, created_at DESC LIMIT 1", [$id, $date]
        )->first();
        if ($prev) {
            $erp_opening  = (float)$prev->balance_after;
            $balance_note = 'Opening = balance after last transaction before '.$date;
        } else {
            $erp_opening  = (float)($sel_account['raw']->current_balance ?? 0);
            $balance_note = 'No prior transactions found — using stored current balance';
        }

        $pctxns = $db->query(
            "SELECT pct.*, u.display_name AS entered_by_name
             FROM branch_petty_cash_transactions pct
             LEFT JOIN users u ON pct.created_by_user_id=u.id
             WHERE pct.account_id=? AND DATE(pct.transaction_date)=?
             ORDER BY pct.created_at ASC", [$id, $date]
        )->results() ?: [];

        foreach ($pctxns as $tx) {
            $amt   = abs((float)$tx->amount);
            $is_in = in_array($tx->transaction_type??'', ['cash_in','transfer_in','opening_balance','adjustment']);
            $dir   = $is_in ? 'in' : 'out';
            $txn_dt = date('Y-m-d',strtotime($tx->transaction_date??$date));
            $crt_dt = date('Y-m-d',strtotime($tx->created_at)); $crt_hr = (int)date('H',strtotime($tx->created_at));
            $flags = [];
            if ($crt_dt !== $txn_dt) $flags[] = 'BACKDATED';
            if ($crt_hr >= 21 || $crt_hr < 7) $flags[] = 'AFTER_HOURS';
            if (empty(trim($tx->description??''))) $flags[] = 'NO_REF';
            if ($amt >= 100000) $flags[] = 'LARGE_TX';
            if ($amt >= 10000 && fmod($amt,10000)<0.01) $flags[] = 'ROUND_AMT';
            if ($dir === 'in')  $erp_in  += $amt;
            else                $erp_out += $amt;
            $txns[] = [
                'sort_key'=>$tx->created_at,'direction'=>$dir,'amount'=>$amt,
                'reference'=>$tx->reference_type??'',
                'description'=>$tx->description ?? ucfirst(str_replace('_',' ',$tx->transaction_type??'')),
                'entered_by'=>$tx->entered_by_name??'—','entered_by_id'=>(int)($tx->created_by_user_id??0),
                'created_at'=>$tx->created_at,'txn_date'=>$txn_dt,'module'=>'Petty Cash','mod_cls'=>'bg-emerald-100 text-emerald-800',
                'source_url'=>'#',
                'extra'=>ucfirst(str_replace('_',' ',$tx->transaction_type??'')).($tx->payment_method?' · '.$tx->payment_method:'').($tx->balance_after!==null?' · Bal after: ৳'.number_format((float)$tx->balance_after,2):''),
                'stored_bal'=>$tx->balance_after,
                'flags'=>$flags,
            ];
        }
        $erp_closing = $erp_opening + $erp_in - $erp_out;
    }

    // ── Sort by entry timestamp ───────────────────────────────────────────
    usort($txns, fn($a,$b) => strcmp($a['sort_key'], $b['sort_key']));

    // ── Rapid Entry detection ─────────────────────────────────────────────
    $user_last = [];
    foreach ($txns as &$txn) {
        $uid = $txn['entered_by_id'];
        $ts  = strtotime($txn['created_at']);
        if ($uid && isset($user_last[$uid]) && ($ts - $user_last[$uid]['ts']) <= 120) {
            $txn['flags'][] = 'RAPID_ENTRY';
        }
        if ($txn['amount'] >= 10000) {
            $user_last[$uid] = ['ts' => $ts, 'amt' => $txn['amount']];
        }
    }
    unset($txn);

    // ── Duplicate amount detection ────────────────────────────────────────
    $amt_counts = array_count_values(array_map(fn($t) => (string)$t['amount'], $txns));
    foreach ($txns as &$txn) {
        if (($amt_counts[(string)$txn['amount']] ?? 0) > 1 && $txn['amount'] >= 1000) {
            $txn['flags'][] = 'DUPLICATE_AMT';
        }
    }
    unset($txn);

    // ── Running balance + balance-break detection (petty cash) ───────────
    $running = $erp_opening;
    foreach ($txns as &$txn) {
        if ($txn['direction'] === 'in')  $running += $txn['amount'];
        else                             $running -= $txn['amount'];
        $txn['running_balance'] = $running;
        if ($txn['stored_bal'] !== null && abs((float)$txn['stored_bal'] - $running) > 0.01) {
            $txn['flags'][] = 'BAL_MISMATCH';
        }
    }
    unset($txn);
}

// ── Per-user activity summary ─────────────────────────────────────────────────
$user_summary = [];
foreach ($txns as $t) {
    $n = $t['entered_by'];
    if (!isset($user_summary[$n])) $user_summary[$n] = ['count'=>0,'in'=>0.0,'out'=>0.0,'flags'=>0,'last'=>''];
    $user_summary[$n]['count']++;
    if ($t['direction']==='in')  $user_summary[$n]['in']  += $t['amount'];
    else                         $user_summary[$n]['out'] += $t['amount'];
    $user_summary[$n]['flags'] += count($t['flags']);
    if ($t['created_at'] > $user_summary[$n]['last']) $user_summary[$n]['last'] = $t['created_at'];
}

// ── Aggregate risk summary ────────────────────────────────────────────────────
$all_flags = [];
foreach ($txns as $t) {
    foreach ($t['flags'] as $f) $all_flags[$f] = ($all_flags[$f]??0) + 1;
}
$risk_count = array_sum($all_flags);

// ── Previous reconciliation for this account+date ────────────────────────────
$prev_recon = null;
if ($sel_account) {
    $prev_recon = $db->query(
        "SELECT * FROM bank_reconciliations WHERE account_type=? AND account_id=? AND recon_date=?",
        [$sel_type, $sel_id, $sel_date]
    )->first();
}

// ── History ───────────────────────────────────────────────────────────────────
$recon_history = [];
if ($sel_account) {
    $recon_history = $db->query(
        "SELECT * FROM bank_reconciliations
         WHERE account_type=? AND account_id=?
         ORDER BY recon_date DESC LIMIT 15",
        [$sel_type, $sel_id]
    )->results() ?: [];
}

// ── Audit trail: recent system_audit_log entries touching this account ────────
$audit_entries = [];
if ($sel_account && $sel_id) {
    try {
        $audit_entries = $db->query(
            "SELECT sal.action, sal.module, sal.description, sal.old_value, sal.new_value,
                    sal.reference_number, sal.created_at, u.display_name AS user_name,
                    sal.ip_address
             FROM system_audit_log sal
             LEFT JOIN users u ON sal.user_id = u.id
             WHERE DATE(sal.created_at) = ?
             ORDER BY sal.created_at DESC LIMIT 50",
            [$sel_date]
        )->results() ?: [];
    } catch (\Throwable $e) { $audit_entries = []; }
}

include '../templates/header.php';
?>

<div class="w-full px-4 sm:px-6 lg:px-8 py-5 max-w-[1600px] mx-auto">

<!-- ── Page Header ──────────────────────────────────────────────────────────── -->
<div class="flex items-center justify-between mb-5">
    <div>
        <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
            <i class="fas fa-balance-scale text-indigo-600"></i>
            Reconciliation
        </h1>
        <p class="text-xs text-gray-500 mt-0.5">Compare ERP balances with actual bank statements / cash counts — with automated error signals</p>
    </div>
    <a href="daily_log.php<?php echo $sel_date ? '?date='.$sel_date : ''; ?>"
       class="text-xs text-indigo-600 hover:underline flex items-center gap-1">
        <i class="fas fa-list-alt"></i> Daily Log
    </a>
</div>

<?php if ($show_saved): ?>
<div class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg flex items-center gap-2 text-green-800 text-sm">
    <i class="fas fa-check-circle text-green-500"></i>
    Reconciliation saved successfully.
</div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-red-800 text-sm">
    <i class="fas fa-exclamation-triangle mr-1"></i><?php echo htmlspecialchars($flash_error); ?>
</div>
<?php endif; ?>

<!-- ── Account + Date Selector ──────────────────────────────────────────────── -->
<form method="GET" action="reconcile.php" class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-5">
    <div class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[260px]">
            <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Account</label>
            <select name="" id="acct_picker" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-400 outline-none">
                <option value="">— Select account —</option>
                <?php
                $cur_group = '';
                foreach ($all_accounts as $a):
                    if ($a['group'] !== $cur_group):
                        if ($cur_group !== '') echo '</optgroup>';
                        echo '<optgroup label="'.htmlspecialchars($a['group']).'">';
                        $cur_group = $a['group'];
                    endif;
                    $sel = ($a['type'] === $sel_type && $a['id'] === $sel_id) ? 'selected' : '';
                    echo '<option value="'.htmlspecialchars($a['type'].':'.$a['id']).'" '.$sel.'>'.htmlspecialchars($a['label']).'</option>';
                endforeach;
                if ($cur_group) echo '</optgroup>';
                ?>
            </select>
            <input type="hidden" name="account_type" id="h_type" value="<?php echo htmlspecialchars($sel_type); ?>">
            <input type="hidden" name="account_id"   id="h_id"   value="<?php echo $sel_id; ?>">
        </div>
        <div>
            <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Date</label>
            <input type="date" name="date" value="<?php echo htmlspecialchars($sel_date); ?>"
                   class="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-400 outline-none">
        </div>
        <button type="submit" class="px-5 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 transition">
            <i class="fas fa-search mr-1"></i> Load
        </button>
        <?php if ($sel_account): ?>
        <a href="reconcile.php?account_type=<?php echo urlencode($sel_type); ?>&account_id=<?php echo $sel_id; ?>&date=<?php echo date('Y-m-d',strtotime($sel_date.'-1 day')); ?>"
           class="px-3 py-2 bg-gray-100 text-gray-600 rounded-lg text-sm hover:bg-gray-200 transition" title="Previous day">
            <i class="fas fa-chevron-left"></i>
        </a>
        <a href="reconcile.php?account_type=<?php echo urlencode($sel_type); ?>&account_id=<?php echo $sel_id; ?>&date=<?php echo date('Y-m-d',strtotime($sel_date.'+1 day')); ?>"
           class="px-3 py-2 bg-gray-100 text-gray-600 rounded-lg text-sm hover:bg-gray-200 transition" title="Next day">
            <i class="fas fa-chevron-right"></i>
        </a>
        <?php endif; ?>
    </div>
</form>

<?php if (!$sel_account): ?>
<!-- ── No account selected ─────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-12 text-center text-gray-400">
    <i class="fas fa-balance-scale text-5xl mb-4 opacity-30"></i>
    <p class="text-base font-medium">Select an account and date above to begin reconciliation.</p>
    <p class="text-sm mt-1">Transactions, balances, and error signals will appear here.</p>
</div>

<?php else: ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

<!-- ══ LEFT COLUMN (transactions) ══════════════════════════════════════════ -->
<div class="xl:col-span-2 space-y-5">

<!-- ── Risk Alert Banner ─────────────────────────────────────────────────── -->
<?php if ($risk_count > 0): ?>
<div class="bg-red-50 border border-red-200 rounded-xl p-4">
    <div class="flex items-center gap-2 mb-3">
        <i class="fas fa-exclamation-triangle text-red-500 text-lg"></i>
        <span class="font-bold text-red-800"><?php echo $risk_count; ?> Error Signal<?php echo $risk_count>1?'s':''; ?> Detected</span>
        <span class="text-xs text-red-600 ml-1">— review flagged transactions below</span>
    </div>
    <div class="flex flex-wrap gap-2">
        <?php foreach ($all_flags as $flag => $cnt): $fm = $FLAG_META[$flag] ?? null; if (!$fm) continue; ?>
        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border <?php echo $fm['cls']; ?>"
              title="<?php echo htmlspecialchars($fm['tip']); ?>">
            <?php echo $fm['icon']; ?> <?php echo $fm['label']; ?>
            <span class="ml-1 bg-white/60 rounded-full px-1.5 font-bold"><?php echo $cnt; ?></span>
        </span>
        <?php endforeach; ?>
    </div>
    <!-- Flag legend -->
    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-1">
        <?php foreach (array_keys($all_flags) as $flag): $fm = $FLAG_META[$flag] ?? null; if (!$fm) continue; ?>
        <div class="text-xs text-red-700 flex items-start gap-1.5">
            <span class="mt-0.5 flex-shrink-0"><?php echo $fm['icon']; ?></span>
            <span><strong><?php echo $fm['label']; ?>:</strong> <?php echo htmlspecialchars($fm['tip']); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="bg-green-50 border border-green-200 rounded-xl p-3 flex items-center gap-2 text-green-800 text-sm">
    <i class="fas fa-shield-alt text-green-500"></i>
    <span class="font-semibold">No error signals detected</span>
    <span class="text-green-600 text-xs ml-1">on <?php echo date('d M Y',strtotime($sel_date)); ?> for this account.</span>
</div>
<?php endif; ?>

<!-- ── Transaction Ledger ─────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
        <div>
            <h2 class="font-bold text-gray-800 text-sm flex items-center gap-2">
                <i class="fas fa-list text-indigo-400"></i>
                Transaction Ledger
                <span class="text-xs font-normal text-gray-400">— <?php echo htmlspecialchars($sel_label); ?></span>
            </h2>
            <p class="text-[10px] text-gray-400 mt-0.5"><?php echo htmlspecialchars($balance_note); ?></p>
        </div>
        <span class="text-xs text-gray-500"><?php echo count($txns); ?> transaction<?php echo count($txns)!=1?'s':''; ?></span>
    </div>

    <?php if (empty($txns)): ?>
    <div class="p-8 text-center text-gray-400 text-sm">
        <i class="fas fa-inbox text-3xl mb-2 opacity-30 block"></i>
        No transactions recorded on <?php echo date('d M Y',strtotime($sel_date)); ?> for this account.
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
    <table class="w-full text-xs">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-100 text-[10px] uppercase tracking-wide text-gray-500">
                <th class="px-3 py-2.5 text-left w-[90px]">Time</th>
                <th class="px-3 py-2.5 text-left">Description / Ref</th>
                <th class="px-3 py-2.5 text-left">Entered By</th>
                <th class="px-3 py-2.5 text-right">In ↑</th>
                <th class="px-3 py-2.5 text-right">Out ↓</th>
                <th class="px-3 py-2.5 text-right">Running Bal</th>
                <th class="px-3 py-2.5 text-left">Flags</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            <!-- Opening Balance Row -->
            <tr class="bg-blue-50">
                <td class="px-3 py-2 text-gray-400 italic">Opening</td>
                <td class="px-3 py-2 text-gray-500 italic" colspan="4">Balance at start of <?php echo date('d M Y',strtotime($sel_date)); ?></td>
                <td class="px-3 py-2 text-right font-bold text-blue-700">৳<?php echo number_format($erp_opening,2); ?></td>
                <td class="px-3 py-2"></td>
            </tr>
            <?php foreach ($txns as $i => $t):
                $has_flags = !empty($t['flags']);
                $row_cls   = $has_flags ? 'bg-red-50/40' : ($i%2===0?'bg-white':'bg-gray-50/30');
                $created_time = date('H:i:s', strtotime($t['created_at']));
                $is_backdated = $t['txn_date'] !== $sel_date;
            ?>
            <tr class="<?php echo $row_cls; ?> hover:bg-yellow-50/50 transition-colors group">
                <!-- Time -->
                <td class="px-3 py-2 whitespace-nowrap">
                    <div class="font-mono font-semibold text-gray-700"><?php echo $created_time; ?></div>
                    <?php if ($is_backdated): ?>
                    <div class="text-[9px] text-red-500 font-semibold">TXN: <?php echo date('d M',strtotime($t['txn_date'])); ?></div>
                    <?php endif; ?>
                    <div class="text-[9px] text-gray-400"><?php echo htmlspecialchars($t['module']); ?></div>
                </td>
                <!-- Description -->
                <td class="px-3 py-2 max-w-[220px]">
                    <div class="font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($t['description'] ?: '—'); ?></div>
                    <?php if ($t['reference']): ?>
                    <div class="text-[10px] text-indigo-600 font-mono"><?php echo htmlspecialchars($t['reference']); ?></div>
                    <?php endif; ?>
                    <?php if ($t['extra']): ?>
                    <div class="text-[10px] text-gray-400 truncate"><?php echo htmlspecialchars($t['extra']); ?></div>
                    <?php endif; ?>
                    <?php if ($t['source_url'] && $t['source_url'] !== '#'): ?>
                    <a href="<?php echo htmlspecialchars($t['source_url']); ?>" target="_blank"
                       class="text-[9px] text-indigo-400 hover:text-indigo-600 opacity-0 group-hover:opacity-100 transition">
                        <i class="fas fa-external-link-alt"></i> View source
                    </a>
                    <?php endif; ?>
                </td>
                <!-- Entered By -->
                <td class="px-3 py-2 whitespace-nowrap">
                    <div class="font-medium text-gray-700"><?php echo htmlspecialchars($t['entered_by']); ?></div>
                </td>
                <!-- In -->
                <td class="px-3 py-2 text-right whitespace-nowrap">
                    <?php if ($t['direction']==='in'): ?>
                    <span class="font-bold text-green-600">+৳<?php echo number_format($t['amount'],2); ?></span>
                    <?php else: ?>
                    <span class="text-gray-200">—</span>
                    <?php endif; ?>
                </td>
                <!-- Out -->
                <td class="px-3 py-2 text-right whitespace-nowrap">
                    <?php if ($t['direction']==='out'): ?>
                    <span class="font-bold text-red-600">-৳<?php echo number_format($t['amount'],2); ?></span>
                    <?php else: ?>
                    <span class="text-gray-200">—</span>
                    <?php endif; ?>
                </td>
                <!-- Running Balance -->
                <td class="px-3 py-2 text-right whitespace-nowrap">
                    <span class="font-bold <?php echo $t['running_balance']>=0?'text-gray-800':'text-red-600'; ?>">
                        ৳<?php echo number_format($t['running_balance'],2); ?>
                    </span>
                    <?php if (isset($t['stored_bal']) && $t['stored_bal']!==null && in_array('BAL_MISMATCH',$t['flags'])): ?>
                    <div class="text-[9px] text-red-500 font-semibold">Stored: ৳<?php echo number_format((float)$t['stored_bal'],2); ?></div>
                    <?php endif; ?>
                </td>
                <!-- Flags -->
                <td class="px-3 py-2">
                    <div class="flex flex-wrap gap-1">
                        <?php foreach ($t['flags'] as $flag):
                            $fm = $FLAG_META[$flag] ?? null; if (!$fm) continue; ?>
                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[9px] font-bold border <?php echo $fm['cls']; ?> cursor-help whitespace-nowrap"
                              title="<?php echo htmlspecialchars($fm['tip']); ?>">
                            <?php echo $fm['icon']; ?> <?php echo $fm['label']; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <!-- Closing row -->
            <tr class="bg-indigo-50 border-t-2 border-indigo-200 font-bold">
                <td class="px-3 py-2.5 text-indigo-700 text-xs uppercase tracking-wide" colspan="2">ERP Closing Balance</td>
                <td class="px-3 py-2.5"></td>
                <td class="px-3 py-2.5 text-right text-green-600">+৳<?php echo number_format($erp_in,2); ?></td>
                <td class="px-3 py-2.5 text-right text-red-600">-৳<?php echo number_format($erp_out,2); ?></td>
                <td class="px-3 py-2.5 text-right text-indigo-800 text-sm">৳<?php echo number_format($erp_closing,2); ?></td>
                <td class="px-3 py-2.5"></td>
            </tr>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── Audit Trail (system_audit_log for the day) ────────────────────────── -->
<?php if (!empty($audit_entries)): ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100 flex items-center gap-2">
        <i class="fas fa-history text-gray-400"></i>
        <h2 class="font-bold text-gray-800 text-sm">System Audit Trail</h2>
        <span class="text-xs text-gray-400">— all ERP changes recorded on <?php echo date('d M Y',strtotime($sel_date)); ?></span>
    </div>
    <div class="overflow-x-auto max-h-72 overflow-y-auto">
    <table class="w-full text-xs">
        <thead class="bg-gray-50 sticky top-0">
            <tr class="text-[10px] uppercase tracking-wide text-gray-500 border-b border-gray-100">
                <th class="px-3 py-2 text-left">Time</th>
                <th class="px-3 py-2 text-left">User</th>
                <th class="px-3 py-2 text-left">Module / Action</th>
                <th class="px-3 py-2 text-left">Description</th>
                <th class="px-3 py-2 text-left">Changed</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            <?php foreach ($audit_entries as $ae):
                $is_destructive = in_array(strtolower($ae->action??''), ['deleted','updated','edited','reversed','cancelled']);
            ?>
            <tr class="<?php echo $is_destructive?'bg-amber-50/40':''; ?> hover:bg-gray-50">
                <td class="px-3 py-1.5 whitespace-nowrap font-mono text-gray-500"><?php echo date('H:i:s',strtotime($ae->created_at)); ?></td>
                <td class="px-3 py-1.5 font-medium text-gray-700 whitespace-nowrap"><?php echo htmlspecialchars($ae->user_name??'System'); ?></td>
                <td class="px-3 py-1.5 whitespace-nowrap">
                    <span class="px-1.5 py-0.5 rounded text-[9px] font-semibold bg-gray-100 text-gray-700"><?php echo htmlspecialchars(ucfirst($ae->module??'')); ?></span>
                    <span class="ml-1 <?php echo $is_destructive?'text-red-600 font-bold':'text-gray-500'; ?>"><?php echo htmlspecialchars(ucfirst($ae->action??'')); ?></span>
                </td>
                <td class="px-3 py-1.5 text-gray-600 max-w-[200px] truncate"><?php echo htmlspecialchars($ae->description??''); ?></td>
                <td class="px-3 py-1.5">
                    <?php if ($ae->old_value || $ae->new_value): ?>
                    <span class="text-red-500 line-through"><?php echo htmlspecialchars(mb_substr($ae->old_value??'',0,30)); ?></span>
                    <?php if ($ae->new_value): ?>
                    <span class="text-green-600 ml-1">→ <?php echo htmlspecialchars(mb_substr($ae->new_value,0,30)); ?></span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="text-gray-300">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

</div><!-- /left column -->

<!-- ══ RIGHT COLUMN (balance panel + user activity + save + history) ════════ -->
<div class="space-y-5">

<!-- ── Balance Reconciliation Panel ──────────────────────────────────────── -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden sticky top-4">
    <div class="px-4 py-3 bg-indigo-600 text-white">
        <h2 class="font-bold text-sm flex items-center gap-2">
            <i class="fas fa-calculator"></i> Balance Check
        </h2>
        <p class="text-[10px] text-indigo-200 mt-0.5"><?php echo htmlspecialchars(date('d M Y',strtotime($sel_date))); ?> · <?php echo htmlspecialchars($sel_label); ?></p>
    </div>
    <div class="p-4 space-y-2">
        <!-- ERP breakdown -->
        <div class="bg-gray-50 rounded-lg p-3 space-y-1.5 text-sm">
            <div class="flex justify-between text-gray-500">
                <span class="text-xs">Opening Balance</span>
                <span class="font-mono font-semibold text-gray-700">৳<?php echo number_format($erp_opening,2); ?></span>
            </div>
            <div class="flex justify-between text-green-600">
                <span class="text-xs">+ Total In</span>
                <span class="font-mono font-semibold">৳<?php echo number_format($erp_in,2); ?></span>
            </div>
            <div class="flex justify-between text-red-500">
                <span class="text-xs">− Total Out</span>
                <span class="font-mono font-semibold">৳<?php echo number_format($erp_out,2); ?></span>
            </div>
            <div class="border-t border-gray-200 pt-1.5 flex justify-between">
                <span class="text-xs font-bold text-gray-700">ERP Closing</span>
                <span class="font-mono font-bold text-indigo-700 text-base">৳<?php echo number_format($erp_closing,2); ?></span>
            </div>
        </div>

        <!-- Actual balance input -->
        <form method="POST" action="reconcile.php?account_type=<?php echo urlencode($sel_type); ?>&account_id=<?php echo $sel_id; ?>&date=<?php echo urlencode($sel_date); ?>" id="recon_form">
            <input type="hidden" name="save_reconciliation" value="1">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="account_type"  value="<?php echo htmlspecialchars($sel_type); ?>">
            <input type="hidden" name="account_id"    value="<?php echo $sel_id; ?>">
            <input type="hidden" name="account_label" value="<?php echo htmlspecialchars($sel_label); ?>">
            <input type="hidden" name="recon_date"    value="<?php echo htmlspecialchars($sel_date); ?>">
            <input type="hidden" name="erp_opening"   value="<?php echo $erp_opening; ?>">
            <input type="hidden" name="erp_in"        value="<?php echo $erp_in; ?>">
            <input type="hidden" name="erp_out"       value="<?php echo $erp_out; ?>">
            <input type="hidden" name="erp_closing"   value="<?php echo $erp_closing; ?>">

            <div class="mt-3">
                <label class="block text-[10px] font-bold text-gray-600 uppercase tracking-wide mb-1">
                    Actual Balance <span class="text-gray-400 normal-case font-normal">(from bank statement / cash count)</span>
                </label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 font-bold text-sm">৳</span>
                    <input type="number" name="actual_balance" id="actual_balance"
                           value="<?php echo $prev_recon ? number_format((float)$prev_recon->actual_balance,2,'.','') : ''; ?>"
                           step="0.01" placeholder="0.00"
                           class="w-full pl-7 pr-3 py-2.5 border-2 border-indigo-200 rounded-lg text-sm font-mono font-bold text-gray-800 focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400 outline-none"
                           oninput="calcDiff()">
                </div>
            </div>

            <!-- Live difference display -->
            <div id="diff_panel" class="mt-3 p-3 rounded-lg border-2 border-gray-200 text-center hidden">
                <div class="text-[10px] text-gray-500 uppercase tracking-wide">Difference</div>
                <div id="diff_val" class="text-2xl font-black mt-0.5"></div>
                <div id="diff_label" class="text-xs mt-1"></div>
            </div>

            <div class="mt-3">
                <label class="block text-[10px] font-bold text-gray-600 uppercase tracking-wide mb-1">Notes</label>
                <textarea name="notes" rows="2" placeholder="Explain any discrepancy, or note what was verified…"
                          class="w-full border border-gray-200 rounded-lg px-3 py-2 text-xs focus:ring-2 focus:ring-indigo-400 outline-none resize-none"
                ><?php echo htmlspecialchars($prev_recon->notes ?? ''); ?></textarea>
            </div>

            <button type="submit"
                    class="mt-3 w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-sm rounded-lg transition flex items-center justify-center gap-2">
                <i class="fas fa-save"></i>
                <?php echo $prev_recon ? 'Update Reconciliation' : 'Save Reconciliation'; ?>
            </button>
        </form>

        <?php if ($prev_recon): ?>
        <div class="mt-2 p-2.5 rounded-lg <?php echo $prev_recon->recon_status==='matched'?'bg-green-50 border border-green-200':'bg-amber-50 border border-amber-200'; ?> text-xs">
            <div class="flex items-center gap-1.5 font-semibold <?php echo $prev_recon->recon_status==='matched'?'text-green-700':'text-amber-700'; ?>">
                <i class="fas <?php echo $prev_recon->recon_status==='matched'?'fa-check-circle':'fa-exclamation-circle'; ?>"></i>
                Last saved: <?php echo $prev_recon->recon_status==='matched'?'Matched ✓':'Discrepancy ⚠️'; ?>
            </div>
            <div class="text-gray-500 mt-1">
                Diff: <strong>৳<?php echo number_format((float)$prev_recon->difference,2); ?></strong>
                · By: <?php echo htmlspecialchars($prev_recon->verified_by_name??''); ?>
                · <?php echo date('d M H:i',strtotime($prev_recon->updated_at??$prev_recon->created_at)); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Operator Activity Summary ──────────────────────────────────────────── -->
<?php if (!empty($user_summary)): ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100 flex items-center gap-2">
        <i class="fas fa-users text-gray-400"></i>
        <h2 class="font-bold text-gray-800 text-sm">Operator Activity</h2>
        <span class="text-xs text-gray-400 ml-1">on this day</span>
    </div>
    <div class="divide-y divide-gray-50">
        <?php foreach ($user_summary as $uname => $us):
            $has_user_flags = $us['flags'] > 0;
        ?>
        <div class="px-4 py-3 <?php echo $has_user_flags?'bg-amber-50/40':''; ?>">
            <div class="flex items-center justify-between">
                <span class="font-semibold text-gray-800 text-sm"><?php echo htmlspecialchars($uname); ?></span>
                <?php if ($has_user_flags): ?>
                <span class="text-[10px] font-bold text-red-600 bg-red-100 px-2 py-0.5 rounded-full">
                    <?php echo $us['flags']; ?> flag<?php echo $us['flags']>1?'s':''; ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="mt-1.5 grid grid-cols-3 gap-2 text-xs">
                <div class="text-center bg-gray-50 rounded p-1.5">
                    <div class="text-[10px] text-gray-400">Entries</div>
                    <div class="font-bold text-gray-700"><?php echo $us['count']; ?></div>
                </div>
                <div class="text-center bg-green-50 rounded p-1.5">
                    <div class="text-[10px] text-green-500">In</div>
                    <div class="font-bold text-green-700">৳<?php echo number_format($us['in'],0); ?></div>
                </div>
                <div class="text-center bg-red-50 rounded p-1.5">
                    <div class="text-[10px] text-red-400">Out</div>
                    <div class="font-bold text-red-600">৳<?php echo number_format($us['out'],0); ?></div>
                </div>
            </div>
            <?php if ($us['last']): ?>
            <div class="text-[10px] text-gray-400 mt-1">Last entry: <?php echo date('H:i:s',strtotime($us['last'])); ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Reconciliation History ────────────────────────────────────────────── -->
<?php if (!empty($recon_history)): ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100 flex items-center gap-2">
        <i class="fas fa-clock-rotate-left text-gray-400"></i>
        <h2 class="font-bold text-gray-800 text-sm">Reconciliation History</h2>
    </div>
    <div class="divide-y divide-gray-50 max-h-80 overflow-y-auto">
        <?php foreach ($recon_history as $h):
            $is_match = $h->recon_status === 'matched';
            $diff     = (float)$h->difference;
        ?>
        <a href="reconcile.php?account_type=<?php echo urlencode($sel_type); ?>&account_id=<?php echo $sel_id; ?>&date=<?php echo $h->recon_date; ?>"
           class="block px-4 py-2.5 hover:bg-gray-50 transition <?php echo $h->recon_date===$sel_date?'bg-indigo-50':''; ?>">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-gray-700"><?php echo date('d M Y',strtotime($h->recon_date)); ?></span>
                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?php echo $is_match?'bg-green-100 text-green-700':'bg-red-100 text-red-700'; ?>">
                    <?php echo $is_match ? '✓ Matched' : '⚠ Diff ৳'.number_format(abs($diff),0); ?>
                </span>
            </div>
            <div class="text-[10px] text-gray-400 mt-0.5">
                ERP: ৳<?php echo number_format((float)$h->erp_closing_balance,2); ?>
                · Actual: ৳<?php echo number_format((float)$h->actual_balance,2); ?>
                · <?php echo htmlspecialchars($h->verified_by_name??''); ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

</div><!-- /right column -->
</div><!-- /grid -->
<?php endif; ?>
</div><!-- /page wrapper -->

<script>
// Populate hidden account type/id from dropdown picker
document.getElementById('acct_picker')?.addEventListener('change', function() {
    const parts = this.value.split(':');
    document.getElementById('h_type').value = parts[0] || '';
    document.getElementById('h_id').value   = parts[1] || '';
});

// Live difference calculator
const erpClosing = <?php echo json_encode((float)$erp_closing); ?>;

function calcDiff() {
    const actual = parseFloat(document.getElementById('actual_balance')?.value || '0');
    if (isNaN(actual)) return;
    const diff = actual - erpClosing;
    const panel = document.getElementById('diff_panel');
    const valEl = document.getElementById('diff_val');
    const lblEl = document.getElementById('diff_label');
    panel.classList.remove('hidden');
    const fmtd = '৳' + Math.abs(diff).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
    if (Math.abs(diff) < 0.01) {
        panel.className = 'mt-3 p-3 rounded-lg border-2 border-green-400 bg-green-50 text-center';
        valEl.className = 'text-2xl font-black mt-0.5 text-green-600';
        valEl.textContent = '৳0.00';
        lblEl.className = 'text-xs mt-1 text-green-600 font-semibold';
        lblEl.textContent = '✓ Perfectly matched!';
    } else if (diff > 0) {
        panel.className = 'mt-3 p-3 rounded-lg border-2 border-amber-400 bg-amber-50 text-center';
        valEl.className = 'text-2xl font-black mt-0.5 text-amber-700';
        valEl.textContent = '+' + fmtd;
        lblEl.className = 'text-xs mt-1 text-amber-600';
        lblEl.textContent = 'Actual is HIGHER than ERP — unrecorded income or opening error';
    } else {
        panel.className = 'mt-3 p-3 rounded-lg border-2 border-red-400 bg-red-50 text-center';
        valEl.className = 'text-2xl font-black mt-0.5 text-red-700';
        valEl.textContent = '-' + fmtd;
        lblEl.className = 'text-xs mt-1 text-red-600';
        lblEl.textContent = 'Actual is LOWER than ERP — unrecorded expense, theft, or entry error';
    }
}

// Trigger on load if prev value exists
window.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('actual_balance')?.value) calcDiff();
});
</script>

<?php include '../templates/footer.php'; ?>

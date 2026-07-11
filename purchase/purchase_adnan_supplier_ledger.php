<?php
/**
 * Purchase (Adnan) Module - Supplier Ledger
 * Shows detailed transaction history for a specific supplier
 * MODIFIED: GRN debit based on EXPECTED quantity × unit price
 *           v2: Adjustment Entry (Add/Reduce Payable/Receivable)
 *
 * @package Ujjal Flour Mills
 * @subpackage Purchase (Adnan) Module
 */

require_once '../core/init.php';
require_once '../core/config/config.php';
require_once '../core/classes/Database.php';
require_once '../core/functions/helpers.php';

restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle   = "Supplier Ledger - Purchase (Adnan)";
$db          = Database::getInstance()->getPdo();
$currentUser = getCurrentUser();
$user_role   = $currentUser['role'] ?? '';
$is_admin    = in_array($user_role, ['Superadmin', 'admin']);

// Load bank & cash accounts (for modal payment options)
$payment_manager = new Purchasepaymentadnanmanager();
$bank_accounts   = $payment_manager->getAllBankAccounts();
$cash_accounts   = $payment_manager->getAllCashAccounts();

// ─── Supplier ID ──────────────────────────────────────────────────────────────
$supplier_id = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
if ($supplier_id === 0) {
    $_SESSION['error_flash'] = "Invalid supplier ID";
    header('Location: purchase_adnan_supplier_summary.php');
    exit;
}

// ─── POST: Save Adjustment Entry ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_adj'])) {
    try {
        // Validate
        $adj_type = $_POST['adj_type'] ?? '';
        $amount   = floatval($_POST['adj_amount'] ?? 0);
        $remarks  = trim($_POST['adj_remarks'] ?? '');

        $valid_types = ['add_payable', 'reduce_payable', 'add_receivable', 'reduce_receivable'];
        if (!in_array($adj_type, $valid_types))  throw new Exception('Invalid adjustment type.');
        if ($amount <= 0)                         throw new Exception('Amount must be greater than zero.');
        if (empty($remarks))                      throw new Exception('Remarks are required.');

        $payment_method = $_POST['adj_payment_method'] ?? 'none';
        if (!in_array($payment_method, ['none','cash','bank','cheque'])) $payment_method = 'none';

        $payment_reference = trim($_POST['adj_payment_reference'] ?? '') ?: null;
        $bank_account_id   = !empty($_POST['adj_bank_account_id']) ? (int)$_POST['adj_bank_account_id'] : null;
        $bank_account_name = null;
        $event_date        = !empty($_POST['adj_event_date']) ? $_POST['adj_event_date'] : null;
        $entry_date        = date('Y-m-d');

        // Resolve bank account name for display
        if ($bank_account_id && in_array($payment_method, ['bank','cheque'])) {
            $ba_stmt = $db->prepare("SELECT bank_name, account_name FROM bank_accounts WHERE id = ?");
            $ba_stmt->execute([$bank_account_id]);
            $ba = $ba_stmt->fetch(PDO::FETCH_OBJ);
            if ($ba) $bank_account_name = $ba->bank_name . ' — ' . $ba->account_name;
        } elseif ($payment_method === 'cash' && !empty($_POST['adj_cash_account_id'])) {
            $cash_id   = (int)$_POST['adj_cash_account_id'];
            $ca_stmt   = $db->prepare("SELECT account_name FROM branch_petty_cash_accounts WHERE id = ?");
            $ca_stmt->execute([$cash_id]);
            $ca = $ca_stmt->fetch(PDO::FETCH_OBJ);
            if ($ca) $bank_account_name = 'Cash — ' . $ca->account_name;
            $bank_account_id = $cash_id;
        }

        // Determine debit / credit direction
        // add_payable      → debit  (we owe more to supplier)
        // reduce_payable   → credit (we owe less)
        // add_receivable   → credit (supplier owes us → reduces our net payable)
        // reduce_receivable→ debit  (removing a receivable → increases our net payable)
        $debit  = in_array($adj_type, ['add_payable', 'reduce_receivable']) ? $amount : 0;
        $credit = in_array($adj_type, ['reduce_payable', 'add_receivable']) ? $amount : 0;

        // Generate sequential number: ADJ-YYYY-NNNN
        $year     = date('Y');
        $cnt_stmt = $db->prepare("SELECT COUNT(*) FROM supplier_ledger_adjustments WHERE YEAR(created_at) = ?");
        $cnt_stmt->execute([$year]);
        $adj_seq    = str_pad($cnt_stmt->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);
        $adj_number = "ADJ-{$year}-{$adj_seq}";

        $ins = $db->prepare("
            INSERT INTO supplier_ledger_adjustments
                (adj_number, supplier_id, adj_type, amount, debit, credit,
                 remarks, payment_method, payment_reference,
                 bank_account_id, bank_account_name,
                 event_date, entry_date, status, created_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?)
        ");
        $ins->execute([
            $adj_number, $supplier_id, $adj_type, $amount, $debit, $credit,
            $remarks, $payment_method, $payment_reference,
            $bank_account_id, $bank_account_name,
            $event_date, $entry_date,
            $currentUser['id'] ?? null,
        ]);
        $new_adj_id = $db->lastInsertId();

        // Audit trail
        if (function_exists('auditLog')) {
            $type_label = [
                'add_payable'       => 'Add Payable',
                'reduce_payable'    => 'Reduce Payable',
                'add_receivable'    => 'Add Receivable',
                'reduce_receivable' => 'Reduce Receivable',
            ][$adj_type];

            auditLog('purchase', 'adjustment_entry',
                "Ledger Adjustment {$adj_number} — {$type_label} ৳" . number_format($amount, 2)
                    . " for supplier ID {$supplier_id}. Remarks: {$remarks}",
                [
                    'record_type'   => 'supplier_ledger_adjustment',
                    'record_id'     => $new_adj_id,
                    'adj_number'    => $adj_number,
                    'adj_type'      => $adj_type,
                    'amount'        => $amount,
                    'debit'         => $debit,
                    'credit'        => $credit,
                    'payment_method'=> $payment_method,
                    'supplier_id'   => $supplier_id,
                    'created_by'    => $currentUser['display_name'] ?? 'System',
                ]
            );
        }

        $_SESSION['success_flash'] = "Adjustment entry {$adj_number} saved successfully.";

    } catch (Exception $e) {
        $_SESSION['error_flash'] = $e->getMessage();
    }

    // Read date params from GET (they are defined below, NOT yet available here)
    $redir_from = $_GET['date_from'] ?? '';
    $redir_to   = $_GET['date_to']   ?? '';
    header("Location: purchase_adnan_supplier_ledger.php?supplier_id={$supplier_id}"
           . ($redir_from ? "&date_from=" . urlencode($redir_from) : '')
           . ($redir_to   ? "&date_to="   . urlencode($redir_to)   : ''));
    exit;
}

// ─── Date filters ─────────────────────────────────────────────────────────────
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';

// ─── Supplier details ─────────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM suppliers WHERE id = ?");
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch(PDO::FETCH_OBJ);

if (!$supplier) {
    $_SESSION['error_flash'] = "Supplier not found";
    header('Location: purchase_adnan_supplier_summary.php');
    exit;
}

// ─── Summary (GRNs + Payments + posted Adjustments) ─────────────────────────
$stmt = $db->prepare("
    SELECT
        COUNT(DISTINCT po.id) as total_orders,
        COALESCE(SUM(po.quantity_kg), 0) as total_ordered_kg,
        COALESCE(SUM(po.total_order_value), 0) as total_ordered_value,

        COALESCE((
            SELECT SUM(grn.quantity_received_kg)
            FROM goods_received_adnan grn
            WHERE grn.supplier_id = ? AND grn.grn_status IN ('verified','posted')
        ), 0) as total_received_kg,

        COALESCE((
            SELECT SUM(grn.expected_quantity * po2g.unit_price_per_kg)
            FROM goods_received_adnan grn
            LEFT JOIN purchase_orders_adnan po2g ON grn.purchase_order_id = po2g.id
            WHERE grn.supplier_id = ? AND grn.grn_status IN ('verified','posted')
        ), 0) as total_receivable_value,

        COALESCE((
            SELECT SUM(p.amount_paid)
            FROM purchase_payments_adnan p
            JOIN purchase_orders_adnan po2 ON p.purchase_order_id = po2.id
            WHERE po2.supplier_id = ? AND p.is_posted = 1
        ), 0) as total_paid

    FROM purchase_orders_adnan po
    WHERE po.supplier_id = ? AND po.po_status != 'cancelled'
");
$stmt->execute([$supplier_id, $supplier_id, $supplier_id, $supplier_id]);
$summary = $stmt->fetch(PDO::FETCH_OBJ);

// Adjustments totals (posted only)
$adj_sum_stmt = $db->prepare("
    SELECT
        COALESCE(SUM(debit),  0) AS total_adj_debit,
        COALESCE(SUM(credit), 0) AS total_adj_credit
    FROM supplier_ledger_adjustments
    WHERE supplier_id = ? AND status = 'posted'
");
$adj_sum_stmt->execute([$supplier_id]);
$adj_totals = $adj_sum_stmt->fetch(PDO::FETCH_OBJ);

// Final balance = (GRN debits + adj debits) − (Payments + adj credits)
$summary->balance_payable = ($summary->total_receivable_value + $adj_totals->total_adj_debit)
                           - ($summary->total_paid             + $adj_totals->total_adj_credit);

// ─── Transactions: POs ───────────────────────────────────────────────────────
$transactions = [];
$params = [$supplier_id];
$po_sql = "SELECT
        po.id, 'PO' as type,
        po.po_number as reference,
        po.po_date as transaction_date,
        CONCAT(po.wheat_origin, ' (', FORMAT(po.quantity_kg,0), ' KG @ ৳', FORMAT(po.unit_price_per_kg,2), '/KG)') as description,
        po.quantity_kg as quantity,
        po.unit_price_per_kg as unit_price,
        0 as debit, 0 as credit,
        po.delivery_status, po.payment_status, po.remarks
    FROM purchase_orders_adnan po
    WHERE po.supplier_id = ? AND po.po_status != 'cancelled'";
if ($date_from) { $po_sql .= " AND po.po_date >= ?"; $params[] = $date_from; }
if ($date_to)   { $po_sql .= " AND po.po_date <= ?"; $params[] = $date_to;   }
$po_sql .= " ORDER BY po.po_date ASC";
$stmt = $db->prepare($po_sql); $stmt->execute($params);
foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $row) $transactions[] = $row;

// ─── Transactions: GRNs ──────────────────────────────────────────────────────
// IMPORTANT: Filter by grn.supplier_id directly — NOT via JOIN to purchase_orders_adnan.
// Using an INNER JOIN on po.supplier_id silently excludes any GRN whose purchase_order_id
// points to a PO with a mismatched supplier_id (data drift) or NULL purchase_order_id,
// causing GRN debits in the table to be LOWER than the summary subquery (which always uses
// grn.supplier_id). The result was: table total_debit ≠ summary total_receivable_value,
// producing a false zero running balance.
// GRN debit = expected_quantity × PO contract rate (po.unit_price_per_kg).
// We LEFT JOIN purchase_orders to get the PO rate; this also preserves orphaned GRNs.
// Using the PO rate (not the GRN's own stored rate) ensures this ledger matches the
// index page calculation: SUM(grn.expected_qty) × po.unit_price_per_kg.
$params = [$supplier_id];
$grn_sql = "SELECT
        grn.id, 'GRN' as type,
        grn.grn_number as reference,
        grn.grn_date as transaction_date,
        CONCAT(
            'Truck: ', COALESCE(grn.truck_number,'N/A'),
            ' | PO#', COALESCE(po_grn.po_number,'?'),
            ' | Expected: ', FORMAT(COALESCE(grn.expected_quantity,0),2), ' KG',
            ' @ ৳', FORMAT(COALESCE(po_grn.unit_price_per_kg, grn.unit_price_per_kg,0),2), '/KG (PO rate)',
            ' | Rcvd: ', FORMAT(COALESCE(grn.quantity_received_kg,0),2), ' KG'
        ) as description,
        grn.quantity_received_kg as quantity,
        COALESCE(po_grn.unit_price_per_kg, grn.unit_price_per_kg) as unit_price,
        COALESCE(grn.expected_quantity * COALESCE(po_grn.unit_price_per_kg, grn.unit_price_per_kg), 0) as debit,
        0 as credit,
        grn.grn_status as delivery_status, '' as payment_status, grn.remarks
    FROM goods_received_adnan grn
    LEFT JOIN purchase_orders_adnan po_grn ON grn.purchase_order_id = po_grn.id
    WHERE grn.supplier_id = ? AND grn.grn_status IN ('verified','posted')";
if ($date_from) { $grn_sql .= " AND grn.grn_date >= ?"; $params[] = $date_from; }
if ($date_to)   { $grn_sql .= " AND grn.grn_date <= ?"; $params[] = $date_to;   }
$grn_sql .= " ORDER BY grn.grn_date ASC";
$stmt = $db->prepare($grn_sql); $stmt->execute($params);
foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $row) $transactions[] = $row;

// ─── Transactions: Payments ──────────────────────────────────────────────────
$params = [$supplier_id];
$payment_sql = "SELECT
        p.id, 'Payment' as type,
        p.payment_voucher_number as reference,
        p.payment_date as transaction_date,
        CONCAT('Payment via ', UPPER(p.payment_method),
               CASE WHEN p.reference_number IS NOT NULL THEN CONCAT(' - Ref: ', p.reference_number) ELSE '' END,
               CASE WHEN p.payment_type != 'regular' THEN CONCAT(' (', UPPER(p.payment_type), ')') ELSE '' END) as description,
        NULL as quantity, NULL as unit_price,
        0 as debit, p.amount_paid as credit,
        '' as delivery_status, 'posted' as payment_status, p.remarks
    FROM purchase_payments_adnan p
    JOIN purchase_orders_adnan po ON p.purchase_order_id = po.id
    WHERE po.supplier_id = ? AND p.is_posted = 1";
if ($date_from) { $payment_sql .= " AND p.payment_date >= ?"; $params[] = $date_from; }
if ($date_to)   { $payment_sql .= " AND p.payment_date <= ?"; $params[] = $date_to;   }
$payment_sql .= " ORDER BY p.payment_date ASC";
$stmt = $db->prepare($payment_sql); $stmt->execute($params);
foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $row) $transactions[] = $row;

// ─── Transactions: Adjustment Entries ────────────────────────────────────────
$params = [$supplier_id];
$adj_type_labels = [
    'add_payable'       => 'Add Payable',
    'reduce_payable'    => 'Reduce Payable',
    'add_receivable'    => 'Add Receivable',
    'reduce_receivable' => 'Reduce Receivable',
];
$adj_sql = "SELECT
        a.id, 'Adjustment' as type,
        a.adj_number as reference,
        COALESCE(a.event_date, a.entry_date) as transaction_date,
        CONCAT(
            CASE a.adj_type
                WHEN 'add_payable'       THEN 'Add Payable'
                WHEN 'reduce_payable'    THEN 'Reduce Payable'
                WHEN 'add_receivable'    THEN 'Add Receivable'
                WHEN 'reduce_receivable' THEN 'Reduce Receivable'
                ELSE a.adj_type
            END,
            CASE WHEN a.payment_method != 'none'
                 THEN CONCAT(' | via ', UPPER(a.payment_method)) ELSE '' END,
            CASE WHEN a.bank_account_name IS NOT NULL
                 THEN CONCAT(' (', a.bank_account_name, ')') ELSE '' END,
            CASE WHEN a.payment_reference IS NOT NULL
                 THEN CONCAT(' | Ref: ', a.payment_reference) ELSE '' END,
            ' — ', a.remarks
        ) as description,
        NULL as quantity, NULL as unit_price,
        a.debit, a.credit,
        a.adj_type as delivery_status,
        a.status as payment_status,
        a.remarks
    FROM supplier_ledger_adjustments a
    WHERE a.supplier_id = ? AND a.status = 'posted'";
// NOTE: Adjustments are NEVER date-filtered — they are corrections that must always be visible.
// Applying a date filter to adjustments causes the "invisible entry" loophole.
$adj_sql .= " ORDER BY COALESCE(a.event_date, a.entry_date) ASC, a.created_at ASC";
$stmt = $db->prepare($adj_sql); $stmt->execute($params);
foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $row) $transactions[] = $row;

// ─── Sort all transactions by date (oldest first), type as secondary sort ─────
// PO < GRN < Payment < Adjustment within the same date
$type_order = ['PO' => 0, 'GRN' => 1, 'Payment' => 2, 'Adjustment' => 3];
usort($transactions, function($a, $b) use ($type_order) {
    $ta = strtotime($a->transaction_date);
    $tb = strtotime($b->transaction_date);
    if ($ta !== $tb) return $ta - $tb;
    return ($type_order[$a->type] ?? 9) - ($type_order[$b->type] ?? 9);
});

// ─── Running balance (POs have 0 debit/credit — informational) ───────────────
$running_balance = 0;
foreach ($transactions as &$trans) {
    $running_balance += ($trans->debit - $trans->credit);
    $trans->balance   = $running_balance;
}
unset($trans);

// ─── Excluded Entries (soft-deleted / unposted / cancelled) ──────────────────
// Soft-deleted payments: is_posted = 0 (set by delete handler)
$excl_payments_stmt = $db->prepare("
    SELECT
        p.id,
        p.payment_voucher_number,
        p.payment_date,
        p.payment_method,
        p.amount_paid,
        p.payment_type,
        p.remarks,
        p.reference_number,
        po.po_number,
        CASE
            WHEN p.remarks LIKE '%[DELETED:%' THEN 'deleted'
            ELSE 'unposted'
        END AS exclusion_reason
    FROM purchase_payments_adnan p
    JOIN purchase_orders_adnan po ON p.purchase_order_id = po.id
    WHERE po.supplier_id = ? AND p.is_posted = 0
    ORDER BY p.payment_date DESC
");
$excl_payments_stmt->execute([$supplier_id]);
$excl_payments = $excl_payments_stmt->fetchAll(PDO::FETCH_OBJ);

// GRNs excluded (not in verified/posted status)
$excl_grns_stmt = $db->prepare("
    SELECT
        grn.id,
        grn.grn_number,
        grn.grn_date,
        grn.truck_number,
        grn.expected_quantity,
        grn.quantity_received_kg,
        grn.unit_price_per_kg,
        COALESCE(grn.expected_quantity * grn.unit_price_per_kg, 0) AS grn_value,
        grn.grn_status,
        po.po_number
    FROM goods_received_adnan grn
    LEFT JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
    WHERE grn.supplier_id = ? AND grn.grn_status NOT IN ('verified','posted')
    ORDER BY grn.grn_date DESC
");
$excl_grns_stmt->execute([$supplier_id]);
$excl_grns = $excl_grns_stmt->fetchAll(PDO::FETCH_OBJ);

// Cancelled adjustments
$excl_adjs_stmt = $db->prepare("
    SELECT *
    FROM supplier_ledger_adjustments
    WHERE supplier_id = ? AND status = 'cancelled'
    ORDER BY created_at DESC
");
$excl_adjs_stmt->execute([$supplier_id]);
$excl_adjs = $excl_adjs_stmt->fetchAll(PDO::FETCH_OBJ);

// Totals of excluded items
$excl_payment_total = array_sum(array_column($excl_payments, 'amount_paid'));
$excl_grn_total     = array_sum(array_column($excl_grns,     'grn_value'));
$excl_adj_debit     = array_sum(array_map(fn($a) => (float)$a->debit,  $excl_adjs));
$excl_adj_credit    = array_sum(array_map(fn($a) => (float)$a->credit, $excl_adjs));

include '../templates/header.php';
?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     PAGE BODY
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="container mx-auto px-4 py-8">

    <?php if (!empty($_SESSION['success_flash'])): ?>
    <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg mb-5 flex justify-between">
        <span><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['success_flash']); unset($_SESSION['success_flash']); ?></span>
        <button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
    </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error_flash'])): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-5 flex justify-between">
        <span><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['error_flash']); unset($_SESSION['error_flash']); ?></span>
        <button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="flex flex-wrap justify-between items-start gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Supplier Ledger</h1>
            <p class="text-gray-600 mt-1">Detailed transaction history</p>
            <p class="text-xs text-gray-500 mt-1">
                <i class="fas fa-info-circle mr-1"></i>
                <strong>Note:</strong> GRN Debit = Expected Quantity × <strong>PO Contract Rate</strong> (not GRN stored price). Payments = Credit. POs are informational only.
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button onclick="openAdjModal()"
                    class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 flex items-center gap-2 no-print">
                <i class="fas fa-sliders-h"></i> Adjustment Entry
            </button>
            <a href="purchase_adnan_supplier_summary.php"
               class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 flex items-center gap-2 no-print">
                <i class="fas fa-arrow-left"></i> Back to Summary
            </a>
            <button onclick="window.print()"
                    class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 flex items-center gap-2 no-print">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    <!-- Supplier Info Card -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h2 class="text-2xl font-bold text-gray-900 mb-4"><?php echo htmlspecialchars($supplier->company_name); ?></h2>
                <div class="space-y-2">
                    <?php if ($supplier->supplier_code): ?>
                    <p class="text-sm"><span class="font-medium text-gray-700">Code:</span> <?php echo htmlspecialchars($supplier->supplier_code); ?></p>
                    <?php endif; ?>
                    <?php if ($supplier->contact_person): ?>
                    <p class="text-sm"><span class="font-medium text-gray-700">Contact:</span> <?php echo htmlspecialchars($supplier->contact_person); ?></p>
                    <?php endif; ?>
                    <?php if ($supplier->phone): ?>
                    <p class="text-sm"><span class="font-medium text-gray-700">Phone:</span> <?php echo htmlspecialchars($supplier->phone); ?></p>
                    <?php endif; ?>
                    <?php if ($supplier->email): ?>
                    <p class="text-sm"><span class="font-medium text-gray-700">Email:</span> <?php echo htmlspecialchars($supplier->email); ?></p>
                    <?php endif; ?>
                    <?php if ($supplier->address): ?>
                    <p class="text-sm"><span class="font-medium text-gray-700">Address:</span> <?php echo htmlspecialchars($supplier->address); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="bg-blue-50 p-4 rounded-lg">
                    <p class="text-xs text-blue-600 font-medium uppercase">Total Orders</p>
                    <p class="text-2xl font-bold text-blue-900 mt-1"><?php echo number_format($summary->total_orders); ?></p>
                </div>
                <div class="bg-purple-50 p-4 rounded-lg">
                    <p class="text-xs text-purple-600 font-medium uppercase">Ordered (KG)</p>
                    <p class="text-2xl font-bold text-purple-900 mt-1"><?php echo number_format($summary->total_ordered_kg, 0); ?></p>
                </div>
                <div class="bg-green-50 p-4 rounded-lg">
                    <p class="text-xs text-green-600 font-medium uppercase">Total Paid</p>
                    <p class="text-2xl font-bold text-green-900 mt-1">৳<?php echo number_format($summary->total_paid, 0); ?></p>
                    <p class="text-xs text-gray-500 mt-1">All-time · Posted only</p>
                </div>
                <div class="bg-<?php echo $summary->balance_payable >= 0 ? 'red' : 'blue'; ?>-50 p-4 rounded-lg">
                    <p class="text-xs text-<?php echo $summary->balance_payable >= 0 ? 'red' : 'blue'; ?>-600 font-medium uppercase">
                        <?php echo $summary->balance_payable >= 0 ? '⚠ Balance Due' : '✓ Advance Paid'; ?>
                    </p>
                    <p class="text-2xl font-bold text-<?php echo $summary->balance_payable >= 0 ? 'red' : 'blue'; ?>-900 mt-1">
                        ৳<?php echo number_format(abs($summary->balance_payable), 0); ?>
                    </p>
                    <p class="text-xs text-gray-500 mt-1 font-semibold">ALL-TIME · Incl. adjustments</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Date Filter -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6 no-print">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <input type="hidden" name="supplier_id" value="<?php echo $supplier_id; ?>">
            <div class="flex-1 min-w-[160px]">
                <label class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500">
            </div>
            <div class="flex-1 min-w-[160px]">
                <label class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500">
            </div>
            <button type="submit" class="bg-primary-600 text-white px-6 py-2 rounded-lg hover:bg-primary-700">
                <i class="fas fa-search mr-2"></i>Filter
            </button>
            <a href="purchase_adnan_supplier_ledger.php?supplier_id=<?php echo $supplier_id; ?>"
               class="bg-gray-200 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-300">
                <i class="fas fa-times mr-2"></i>Clear
            </a>
        </form>
    </div>

    <?php if ($date_from || $date_to): ?>
    <!-- ── Date filter active warning ──────────────────────────────────────────── -->
    <div class="bg-amber-50 border border-amber-300 rounded-lg px-5 py-4 mb-6 flex items-start gap-3 no-print">
        <i class="fas fa-filter text-amber-500 mt-0.5 flex-shrink-0"></i>
        <div class="text-sm text-amber-800">
            <strong>Date filter is active</strong>
            (<?php echo $date_from ? date('d M Y', strtotime($date_from)) : 'start'; ?>
            → <?php echo $date_to ? date('d M Y', strtotime($date_to)) : 'today'; ?>).
            GRNs &amp; Payments are filtered to this range.
            <br>
            <span class="font-semibold">Manual Adjustments are always shown in full</span> — they are corrections and are never filtered out.
            The <em>Summary Balance</em> above always reflects the <strong>all-time</strong> position;
            the <em>Table Running Balance</em> below reflects only the filtered period.
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Balance Reconciliation (all-time) ────────────────────────────────────── -->
    <div class="bg-white rounded-lg shadow-md p-5 mb-6 text-sm">
        <h4 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
            <i class="fas fa-calculator text-indigo-500"></i>
            All-Time Balance Reconciliation
            <span class="text-xs font-normal text-gray-400 ml-1">(GRN debit = Expected Qty × PO Contract Rate)</span>
        </h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3 text-center">
            <div class="bg-red-50 rounded-lg p-3">
                <p class="text-xs text-red-500 font-medium uppercase mb-1">GRN Debits</p>
                <p class="text-lg font-bold text-red-700">৳<?php echo number_format($summary->total_receivable_value, 2); ?></p>
                <p class="text-xs text-gray-400 mt-0.5">Expected qty × PO rate</p>
            </div>
            <div class="bg-red-50 rounded-lg p-3">
                <p class="text-xs text-red-500 font-medium uppercase mb-1">Adj Debits</p>
                <p class="text-lg font-bold text-red-700">৳<?php echo number_format($adj_totals->total_adj_debit, 2); ?></p>
                <p class="text-xs text-gray-400 mt-0.5">Add Payable / Reduce Receivable</p>
            </div>
            <div class="bg-green-50 rounded-lg p-3">
                <p class="text-xs text-green-600 font-medium uppercase mb-1">Payments</p>
                <p class="text-lg font-bold text-green-700">৳<?php echo number_format($summary->total_paid, 2); ?></p>
                <p class="text-xs text-gray-400 mt-0.5">Posted payments only</p>
            </div>
            <div class="bg-green-50 rounded-lg p-3">
                <p class="text-xs text-green-600 font-medium uppercase mb-1">Adj Credits</p>
                <p class="text-lg font-bold text-green-700">৳<?php echo number_format($adj_totals->total_adj_credit, 2); ?></p>
                <p class="text-xs text-gray-400 mt-0.5">Reduce Payable / Add Receivable</p>
            </div>
            <?php
            $net_balance = ($summary->total_receivable_value + $adj_totals->total_adj_debit)
                         - ($summary->total_paid             + $adj_totals->total_adj_credit);
            $is_advance  = $net_balance < 0;
            ?>
            <div class="bg-<?php echo $is_advance ? 'blue' : 'orange'; ?>-50 rounded-lg p-3 border-2 border-<?php echo $is_advance ? 'blue' : 'orange'; ?>-300">
                <p class="text-xs text-<?php echo $is_advance ? 'blue' : 'orange'; ?>-600 font-medium uppercase mb-1">
                    Net Balance
                </p>
                <p class="text-lg font-bold text-<?php echo $is_advance ? 'blue' : 'orange'; ?>-700">
                    <?php echo $is_advance ? '−' : ''; ?>৳<?php echo number_format(abs($net_balance), 2); ?>
                </p>
                <p class="text-xs text-gray-500 mt-0.5 font-semibold">
                    <?php echo $is_advance ? 'Advance (Overpaid)' : 'Balance Due'; ?>
                </p>
            </div>
        </div>
        <p class="text-xs text-gray-400 mt-3">
            Formula: Net Balance = (GRN Debits + Adj Debits) − (Payments + Adj Credits)
            <?php if ($is_advance): ?>
            — Negative = supplier owes you (advance / overpayment)
            <?php else: ?>
            — Positive = you owe supplier
            <?php endif; ?>
        </p>
    </div>

    <!-- Ledger Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap justify-between items-center gap-3">
            <h3 class="text-lg font-semibold text-gray-900">
                Transaction History
                <span class="text-sm font-normal text-gray-600">(<?php echo count($transactions); ?> transactions)</span>
                <?php if ($date_from || $date_to): ?>
                <span class="ml-2 text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-normal">
                    Filtered period
                </span>
                <?php endif; ?>
            </h3>
            <div class="text-xs text-gray-500 flex flex-wrap gap-2">
                <span class="px-2 py-1 bg-blue-100   text-blue-800   rounded">PO = Informational</span>
                <span class="px-2 py-1 bg-purple-100 text-purple-800 rounded">GRN = Debit (Expected qty × PO rate)</span>
                <span class="px-2 py-1 bg-green-100  text-green-800  rounded">Payment = Credit</span>
                <span class="px-2 py-1 bg-indigo-100 text-indigo-800 rounded">Adjustment = ± (always shown)</span>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left   text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left   text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left   text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                        <th class="px-6 py-3 text-left   text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-6 py-3 text-right  text-xs font-medium text-red-500   uppercase tracking-wider">Debit (৳)</th>
                        <th class="px-6 py-3 text-right  text-xs font-medium text-green-500 uppercase tracking-wider">Credit (৳)</th>
                        <th class="px-6 py-3 text-right  text-xs font-medium text-blue-500  uppercase tracking-wider">Balance (৳)</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500  uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="8" class="px-6 py-8 text-center text-gray-500">
                            <i class="fas fa-inbox text-4xl text-gray-300 mb-2 block"></i>
                            No transactions found
                            <p class="text-xs text-gray-400 mt-2">Only posted payments and verified/posted GRNs are shown</p>
                        </td>
                    </tr>
                    <?php else:
                        $total_debit  = 0;
                        $total_credit = 0;
                        foreach ($transactions as $trans):
                            $total_debit  += $trans->debit;
                            $total_credit += $trans->credit;
                            // Row background
                            $row_bg = '';
                            if ($trans->type === 'PO')         $row_bg = 'bg-gray-50';
                            if ($trans->type === 'Adjustment') $row_bg = 'bg-indigo-50';
                    ?>
                    <tr class="hover:bg-gray-50 <?php echo $row_bg; ?>">

                        <!-- Date -->
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo date('d M Y', strtotime($trans->transaction_date)); ?>
                        </td>

                        <!-- Type badge -->
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php
                            $badge = [
                                'PO'         => 'bg-blue-100   text-blue-800',
                                'GRN'        => 'bg-purple-100 text-purple-800',
                                'Payment'    => 'bg-green-100  text-green-800',
                                'Adjustment' => 'bg-indigo-100 text-indigo-800',
                            ][$trans->type] ?? 'bg-gray-100 text-gray-800';
                            ?>
                            <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $badge; ?>">
                                <?php echo $trans->type; ?>
                                <?php if ($trans->type === 'PO'): ?>
                                <span class="opacity-60">(Info)</span>
                                <?php endif; ?>
                                <?php if ($trans->type === 'Adjustment'): ?>
                                <span class="opacity-80">
                                    <?php echo $trans->debit > 0 ? '▲' : '▼'; ?>
                                </span>
                                <?php endif; ?>
                            </span>
                        </td>

                        <!-- Reference -->
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($trans->reference); ?>
                        </td>

                        <!-- Description -->
                        <td class="px-6 py-4 text-sm text-gray-700 max-w-xs">
                            <?php echo htmlspecialchars($trans->description); ?>
                        </td>

                        <!-- Debit -->
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold">
                            <?php if ($trans->debit > 0): ?>
                            <span class="text-red-600">৳<?php echo number_format($trans->debit, 2); ?></span>
                            <?php else: ?>
                            <span class="text-gray-300">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Credit -->
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold">
                            <?php if ($trans->credit > 0): ?>
                            <span class="text-green-600">৳<?php echo number_format($trans->credit, 2); ?></span>
                            <?php else: ?>
                            <span class="text-gray-300">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Running Balance -->
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold
                            <?php echo $trans->balance >= 0 ? 'text-blue-600' : 'text-orange-600'; ?>">
                            ৳<?php echo number_format($trans->balance, 2); ?>
                        </td>

                        <!-- Status -->
                        <td class="px-6 py-4 whitespace-nowrap text-center text-xs">
                            <?php if ($trans->type === 'GRN'): ?>
                            <span class="px-2 py-1 rounded-full bg-green-100 text-green-700">
                                <?php echo ucfirst($trans->delivery_status ?? ''); ?>
                            </span>
                            <?php elseif ($trans->type === 'Payment'): ?>
                            <span class="px-2 py-1 rounded-full bg-green-100 text-green-700">Posted</span>
                            <?php elseif ($trans->type === 'Adjustment'): ?>
                            <?php
                            $adjLabel = [
                                'add_payable'       => ['label' => 'Add Payable',      'cls' => 'bg-red-100 text-red-700'],
                                'reduce_payable'    => ['label' => 'Reduce Payable',   'cls' => 'bg-green-100 text-green-700'],
                                'add_receivable'    => ['label' => 'Add Receivable',   'cls' => 'bg-blue-100 text-blue-700'],
                                'reduce_receivable' => ['label' => 'Reduce Receivable','cls' => 'bg-orange-100 text-orange-700'],
                            ][$trans->delivery_status] ?? ['label' => $trans->delivery_status, 'cls' => 'bg-gray-100 text-gray-700'];
                            ?>
                            <span class="px-2 py-1 rounded-full text-xs <?php echo $adjLabel['cls']; ?>">
                                <?php echo $adjLabel['label']; ?>
                            </span>
                            <?php elseif ($trans->delivery_status): ?>
                            <span class="px-2 py-1 rounded-full bg-gray-100 text-gray-700">
                                <?php echo ucfirst($trans->delivery_status ?? ''); ?>
                            </span>
                            <?php endif; ?>
                        </td>

                    </tr>
                    <?php endforeach; ?>

                    <!-- Totals row -->
                    <?php
                    $period_net     = $total_debit - $total_credit;
                    $period_label   = ($date_from || $date_to) ? 'Period Running Balance' : 'All-Time Running Balance';
                    $period_is_adv  = $period_net < 0;
                    ?>
                    <tr class="bg-blue-50 font-bold border-t-2 border-blue-200">
                        <td colspan="4" class="px-6 py-4 text-sm text-right text-gray-700">
                            <i class="fas fa-calculator mr-2"></i><?php echo $period_label; ?>:
                            <?php if ($date_from || $date_to): ?>
                            <div class="text-xs font-normal text-amber-600 mt-0.5">
                                ⚠ GRNs &amp; payments filtered to date range · All adjustments included
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-red-600">
                            ৳<?php echo number_format($total_debit, 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-green-600">
                            ৳<?php echo number_format($total_credit, 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold
                            <?php echo $period_is_adv ? 'text-blue-700' : 'text-orange-700'; ?>">
                            <?php echo $period_is_adv ? '−' : ''; ?>৳<?php echo number_format(abs($period_net), 2); ?>
                            <div class="text-xs font-semibold mt-0.5">
                                <?php echo $period_is_adv ? 'Advance' : 'Balance Due'; ?>
                            </div>
                        </td>
                        <td></td>
                    </tr>
                    <?php if ($date_from || $date_to): ?>
                    <tr class="bg-indigo-50">
                        <td colspan="8" class="px-6 py-3 text-xs text-center text-indigo-700">
                            <i class="fas fa-info-circle mr-1"></i>
                            For the <strong>all-time net balance</strong> see the reconciliation panel above the table
                            (৳<?php echo number_format(abs($net_balance), 2); ?> — <?php echo $is_advance ? 'Advance' : 'Balance Due'; ?>).
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div><!-- /ledger table -->

    <!-- ═══════════════════════════════════════════════════════════════════════
         EXCLUDED ENTRIES PANEL
         ═══════════════════════════════════════════════════════════════════════ -->
    <?php
    $has_excluded = count($excl_payments) > 0 || count($excl_grns) > 0 || count($excl_adjs) > 0;
    ?>
    <div class="mt-6 no-print">
        <button onclick="toggleExcluded()"
                class="w-full flex items-center justify-between bg-gray-100 hover:bg-gray-200 border border-gray-300 rounded-lg px-5 py-3 text-sm font-semibold text-gray-700 transition-colors">
            <span class="flex items-center gap-2">
                <i class="fas fa-ban text-red-400"></i>
                Excluded from Ledger
                <span class="ml-1 text-xs font-normal text-gray-500">
                    (soft-deleted payments, unverified GRNs, cancelled adjustments — do not affect the balance above)
                </span>
                <?php if ($has_excluded): ?>
                <span class="bg-red-100 text-red-700 text-xs px-2 py-0.5 rounded-full font-bold">
                    <?php echo count($excl_payments) + count($excl_grns) + count($excl_adjs); ?> entries
                </span>
                <?php endif; ?>
            </span>
            <i class="fas fa-chevron-down text-gray-400" id="excl-chevron"></i>
        </button>

        <div id="excludedPanel" class="hidden mt-2 space-y-4">

            <?php if (!$has_excluded): ?>
            <div class="bg-green-50 border border-green-200 rounded-lg px-5 py-4 text-sm text-green-700 flex items-center gap-2">
                <i class="fas fa-check-circle"></i>
                No excluded entries found. Every payment, GRN, and adjustment for this supplier is either active in the ledger or does not exist.
            </div>
            <?php endif; ?>

            <!-- ── Soft-deleted / Unposted Payments ───────────────────────── -->
            <?php if (count($excl_payments) > 0): ?>
            <div class="bg-white border border-red-200 rounded-lg overflow-hidden">
                <div class="px-5 py-3 bg-red-50 border-b border-red-200 flex items-center justify-between">
                    <h4 class="text-sm font-semibold text-red-800 flex items-center gap-2">
                        <i class="fas fa-times-circle text-red-500"></i>
                        Deleted / Unposted Payments
                        <span class="text-xs font-normal text-red-600">(is_posted = 0 — excluded from credit column)</span>
                    </h4>
                    <span class="text-xs font-bold text-red-700 bg-red-100 px-2 py-0.5 rounded">
                        ৳<?php echo number_format($excl_payment_total, 2); ?> NOT credited
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs divide-y divide-gray-100">
                        <thead class="bg-gray-50 text-gray-500 uppercase">
                            <tr>
                                <th class="px-4 py-2 text-left">Date</th>
                                <th class="px-4 py-2 text-left">Voucher No.</th>
                                <th class="px-4 py-2 text-left">PO</th>
                                <th class="px-4 py-2 text-left">Method</th>
                                <th class="px-4 py-2 text-right">Amount</th>
                                <th class="px-4 py-2 text-center">Reason</th>
                                <th class="px-4 py-2 text-left">Remarks</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($excl_payments as $ep): ?>
                            <tr class="bg-red-50/40 opacity-75">
                                <td class="px-4 py-2 line-through text-gray-400">
                                    <?php echo date('d M Y', strtotime($ep->payment_date)); ?>
                                </td>
                                <td class="px-4 py-2 font-mono line-through text-gray-400">
                                    <?php echo htmlspecialchars($ep->payment_voucher_number); ?>
                                </td>
                                <td class="px-4 py-2 text-gray-500">
                                    <?php echo htmlspecialchars($ep->po_number); ?>
                                </td>
                                <td class="px-4 py-2 text-gray-500 uppercase">
                                    <?php echo htmlspecialchars($ep->payment_method); ?>
                                    <?php if ($ep->payment_type !== 'regular'): ?>
                                    <span class="text-gray-400">(<?php echo $ep->payment_type; ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2 text-right line-through text-red-400 font-semibold">
                                    ৳<?php echo number_format($ep->amount_paid, 2); ?>
                                </td>
                                <td class="px-4 py-2 text-center">
                                    <?php if ($ep->exclusion_reason === 'deleted'): ?>
                                    <span class="bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-medium">
                                        <i class="fas fa-trash-alt mr-1"></i>Deleted
                                    </span>
                                    <?php else: ?>
                                    <span class="bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-medium">
                                        <i class="fas fa-clock mr-1"></i>Unposted
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2 text-gray-400 max-w-xs truncate" title="<?php echo htmlspecialchars($ep->remarks ?? ''); ?>">
                                    <?php
                                    $remark = $ep->remarks ?? '';
                                    // Extract [DELETED: ...] line
                                    if (preg_match('/\[DELETED: (.+?)\]/s', $remark, $m)) {
                                        echo '<span class="text-red-500 font-medium">Deleted by: ' . htmlspecialchars(trim($m[1])) . '</span>';
                                    } else {
                                        echo htmlspecialchars(mb_strimwidth($remark, 0, 60, '...'));
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-red-50 font-semibold text-xs border-t border-red-200">
                            <tr>
                                <td colspan="4" class="px-4 py-2 text-right text-red-700">Total excluded credit:</td>
                                <td class="px-4 py-2 text-right text-red-700 line-through">৳<?php echo number_format($excl_payment_total, 2); ?></td>
                                <td colspan="2" class="px-4 py-2 text-gray-500 text-xs font-normal">
                                    These amounts are <strong>not credited</strong> to the balance.
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Unverified / Cancelled GRNs ────────────────────────────── -->
            <?php if (count($excl_grns) > 0): ?>
            <div class="bg-white border border-orange-200 rounded-lg overflow-hidden">
                <div class="px-5 py-3 bg-orange-50 border-b border-orange-200 flex items-center justify-between">
                    <h4 class="text-sm font-semibold text-orange-800 flex items-center gap-2">
                        <i class="fas fa-exclamation-triangle text-orange-400"></i>
                        Unverified / Pending / Cancelled GRNs
                        <span class="text-xs font-normal text-orange-600">(not 'verified' or 'posted' — excluded from debit column)</span>
                    </h4>
                    <span class="text-xs font-bold text-orange-700 bg-orange-100 px-2 py-0.5 rounded">
                        ৳<?php echo number_format($excl_grn_total, 2); ?> NOT debited
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs divide-y divide-gray-100">
                        <thead class="bg-gray-50 text-gray-500 uppercase">
                            <tr>
                                <th class="px-4 py-2 text-left">Date</th>
                                <th class="px-4 py-2 text-left">GRN No.</th>
                                <th class="px-4 py-2 text-left">PO</th>
                                <th class="px-4 py-2 text-left">Truck</th>
                                <th class="px-4 py-2 text-right">Expected (KG)</th>
                                <th class="px-4 py-2 text-right">Price/KG</th>
                                <th class="px-4 py-2 text-right">Value</th>
                                <th class="px-4 py-2 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($excl_grns as $eg):
                                $status_cls = match($eg->grn_status) {
                                    'pending'   => 'bg-yellow-100 text-yellow-700',
                                    'draft'     => 'bg-gray-100 text-gray-600',
                                    'cancelled' => 'bg-red-100 text-red-700',
                                    default     => 'bg-gray-100 text-gray-600',
                                };
                            ?>
                            <tr class="opacity-70">
                                <td class="px-4 py-2 text-gray-500">
                                    <?php echo date('d M Y', strtotime($eg->grn_date)); ?>
                                </td>
                                <td class="px-4 py-2 font-mono text-gray-400 line-through">
                                    <?php echo htmlspecialchars($eg->grn_number); ?>
                                </td>
                                <td class="px-4 py-2 text-gray-500">
                                    <?php echo htmlspecialchars($eg->po_number ?? '—'); ?>
                                </td>
                                <td class="px-4 py-2 text-gray-500">
                                    <?php echo htmlspecialchars($eg->truck_number ?? '—'); ?>
                                </td>
                                <td class="px-4 py-2 text-right text-gray-500">
                                    <?php echo number_format($eg->expected_quantity, 2); ?>
                                </td>
                                <td class="px-4 py-2 text-right text-gray-500">
                                    ৳<?php echo number_format($eg->unit_price_per_kg, 2); ?>
                                </td>
                                <td class="px-4 py-2 text-right text-orange-500 line-through font-semibold">
                                    ৳<?php echo number_format($eg->grn_value, 2); ?>
                                </td>
                                <td class="px-4 py-2 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php echo $status_cls; ?>">
                                        <?php echo ucfirst($eg->grn_status); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-orange-50 font-semibold text-xs border-t border-orange-200">
                            <tr>
                                <td colspan="6" class="px-4 py-2 text-right text-orange-700">Total excluded debit:</td>
                                <td class="px-4 py-2 text-right text-orange-700 line-through">৳<?php echo number_format($excl_grn_total, 2); ?></td>
                                <td class="px-4 py-2 text-gray-500 text-xs font-normal">Not debited to balance.</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Cancelled Adjustments ───────────────────────────────────── -->
            <?php if (count($excl_adjs) > 0): ?>
            <div class="bg-white border border-gray-300 rounded-lg overflow-hidden">
                <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
                    <h4 class="text-sm font-semibold text-gray-700 flex items-center gap-2">
                        <i class="fas fa-undo text-gray-400"></i>
                        Cancelled Adjustments
                        <span class="text-xs font-normal text-gray-500">(status = cancelled — excluded from balance)</span>
                    </h4>
                    <span class="text-xs font-bold text-gray-600 bg-gray-200 px-2 py-0.5 rounded">
                        <?php echo count($excl_adjs); ?> cancelled
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs divide-y divide-gray-100">
                        <thead class="bg-gray-50 text-gray-500 uppercase">
                            <tr>
                                <th class="px-4 py-2 text-left">Date</th>
                                <th class="px-4 py-2 text-left">Adj No.</th>
                                <th class="px-4 py-2 text-left">Type</th>
                                <th class="px-4 py-2 text-right">Debit</th>
                                <th class="px-4 py-2 text-right">Credit</th>
                                <th class="px-4 py-2 text-left">Remarks</th>
                                <th class="px-4 py-2 text-left">Cancellation Reason</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php
                            $adj_labels = [
                                'add_payable'       => ['Add Payable',       'bg-red-100 text-red-600'],
                                'reduce_payable'    => ['Reduce Payable',    'bg-green-100 text-green-600'],
                                'add_receivable'    => ['Add Receivable',    'bg-blue-100 text-blue-600'],
                                'reduce_receivable' => ['Reduce Receivable', 'bg-orange-100 text-orange-600'],
                            ];
                            foreach ($excl_adjs as $ea):
                                $lbl = $adj_labels[$ea->adj_type] ?? [$ea->adj_type, 'bg-gray-100 text-gray-600'];
                            ?>
                            <tr class="opacity-60">
                                <td class="px-4 py-2 text-gray-400 line-through">
                                    <?php echo date('d M Y', strtotime($ea->event_date ?? $ea->entry_date)); ?>
                                </td>
                                <td class="px-4 py-2 font-mono text-gray-400 line-through">
                                    <?php echo htmlspecialchars($ea->adj_number); ?>
                                </td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium opacity-50 <?php echo $lbl[1]; ?>">
                                        <?php echo $lbl[0]; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right text-gray-400 line-through">
                                    <?php echo $ea->debit > 0 ? '৳' . number_format($ea->debit, 2) : '—'; ?>
                                </td>
                                <td class="px-4 py-2 text-right text-gray-400 line-through">
                                    <?php echo $ea->credit > 0 ? '৳' . number_format($ea->credit, 2) : '—'; ?>
                                </td>
                                <td class="px-4 py-2 text-gray-400 max-w-[160px] truncate">
                                    <?php echo htmlspecialchars(mb_strimwidth($ea->remarks, 0, 50, '...')); ?>
                                </td>
                                <td class="px-4 py-2 text-red-400 italic max-w-[160px] truncate">
                                    <?php echo htmlspecialchars($ea->cancellation_reason ?? '—'); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-gray-50 font-semibold text-xs border-t border-gray-200">
                            <tr>
                                <td colspan="3" class="px-4 py-2 text-right text-gray-600">Cancelled adj totals:</td>
                                <td class="px-4 py-2 text-right text-gray-500 line-through">
                                    <?php echo $excl_adj_debit > 0 ? '৳' . number_format($excl_adj_debit, 2) : '—'; ?>
                                </td>
                                <td class="px-4 py-2 text-right text-gray-500 line-through">
                                    <?php echo $excl_adj_credit > 0 ? '৳' . number_format($excl_adj_credit, 2) : '—'; ?>
                                </td>
                                <td colspan="2" class="px-4 py-2 text-gray-400 text-xs font-normal">None of these affect the ledger balance.</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Net impact of excluded entries ────────────────────────── -->
            <?php if ($has_excluded): ?>
            <div class="bg-slate-50 border border-slate-300 rounded-lg px-5 py-4 text-sm">
                <p class="font-semibold text-slate-700 mb-2 flex items-center gap-2">
                    <i class="fas fa-calculator text-slate-500"></i>
                    Net Impact of Excluded Entries (for reference only)
                </p>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center text-xs">
                    <div class="bg-white rounded p-3 border border-slate-200">
                        <p class="text-orange-500 font-medium uppercase mb-1">Excl. GRN Debit</p>
                        <p class="text-base font-bold text-orange-600 line-through">৳<?php echo number_format($excl_grn_total, 2); ?></p>
                    </div>
                    <div class="bg-white rounded p-3 border border-slate-200">
                        <p class="text-red-500 font-medium uppercase mb-1">Excl. Payment Credit</p>
                        <p class="text-base font-bold text-red-600 line-through">৳<?php echo number_format($excl_payment_total, 2); ?></p>
                    </div>
                    <div class="bg-white rounded p-3 border border-slate-200">
                        <p class="text-gray-500 font-medium uppercase mb-1">Excl. Adj Debit</p>
                        <p class="text-base font-bold text-gray-500 line-through">৳<?php echo number_format($excl_adj_debit, 2); ?></p>
                    </div>
                    <div class="bg-white rounded p-3 border border-slate-200">
                        <p class="text-gray-500 font-medium uppercase mb-1">Excl. Adj Credit</p>
                        <p class="text-base font-bold text-gray-500 line-through">৳<?php echo number_format($excl_adj_credit, 2); ?></p>
                    </div>
                </div>
                <p class="text-xs text-slate-500 mt-3">
                    <i class="fas fa-info-circle mr-1"></i>
                    These amounts are <strong>excluded from the ledger balance above</strong>.
                    They are shown here purely for audit transparency. The active ledger balance
                    (৳<?php echo number_format(abs($net_balance), 2); ?> — <?php echo $is_advance ? 'Advance' : 'Balance Due'; ?>)
                    already correctly reflects only verified/posted/active entries.
                </p>
            </div>
            <?php endif; ?>

        </div><!-- /excludedPanel -->
    </div><!-- /excluded wrapper -->

</div><!-- /container -->


<!-- ═══════════════════════════════════════════════════════════════════════════
     ADJUSTMENT ENTRY MODAL
     ═══════════════════════════════════════════════════════════════════════════ -->
<div id="adjModal"
     class="fixed inset-0 z-50 hidden overflow-y-auto"
     aria-modal="true" role="dialog">

    <!-- Backdrop -->
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeAdjModal()"></div>

    <!-- Panel -->
    <div class="relative min-h-screen flex items-center justify-center p-4">
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl">

            <!-- Modal header -->
            <div class="flex items-center justify-between px-6 py-5 bg-indigo-700 rounded-t-2xl">
                <div>
                    <h3 class="text-xl font-bold text-white flex items-center gap-2">
                        <i class="fas fa-sliders-h"></i> Adjustment Entry
                    </h3>
                    <p class="text-indigo-200 text-sm mt-0.5">
                        Record a missed or corrective entry for
                        <strong><?php echo htmlspecialchars($supplier->company_name); ?></strong>
                    </p>
                </div>
                <button onclick="closeAdjModal()" class="text-white/70 hover:text-white text-2xl leading-none">&times;</button>
            </div>

            <!-- Modal body -->
            <form method="POST" id="adjForm" class="px-6 py-6 space-y-5">
                <input type="hidden" name="action_save_adj" value="1">

                <!-- ── Adjustment Type ────────────────────────────────────── -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-3">
                        Adjustment Type <span class="text-red-500">*</span>
                    </label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">

                        <label class="adj-type-card cursor-pointer border-2 border-gray-200 rounded-xl p-4 hover:border-red-400 transition-colors" data-type="add_payable">
                            <input type="radio" name="adj_type" value="add_payable" class="sr-only" required>
                            <div class="flex items-start gap-3">
                                <div class="w-9 h-9 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-plus text-red-600 text-sm"></i>
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-800 text-sm">Add Payable</p>
                                    <p class="text-xs text-gray-500 mt-0.5">We owe supplier <em>more</em> than recorded. Adds a debit — increases balance due.</p>
                                </div>
                            </div>
                        </label>

                        <label class="adj-type-card cursor-pointer border-2 border-gray-200 rounded-xl p-4 hover:border-green-400 transition-colors" data-type="reduce_payable">
                            <input type="radio" name="adj_type" value="reduce_payable" class="sr-only">
                            <div class="flex items-start gap-3">
                                <div class="w-9 h-9 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-minus text-green-600 text-sm"></i>
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-800 text-sm">Reduce Payable</p>
                                    <p class="text-xs text-gray-500 mt-0.5">We owe supplier <em>less</em> than recorded. Adds a credit — reduces balance due.</p>
                                </div>
                            </div>
                        </label>

                        <label class="adj-type-card cursor-pointer border-2 border-gray-200 rounded-xl p-4 hover:border-blue-400 transition-colors" data-type="add_receivable">
                            <input type="radio" name="adj_type" value="add_receivable" class="sr-only">
                            <div class="flex items-start gap-3">
                                <div class="w-9 h-9 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-hand-holding-usd text-blue-600 text-sm"></i>
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-800 text-sm">Add Receivable</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Supplier owes <em>us</em> something. Adds a credit — reduces our net payable.</p>
                                </div>
                            </div>
                        </label>

                        <label class="adj-type-card cursor-pointer border-2 border-gray-200 rounded-xl p-4 hover:border-orange-400 transition-colors" data-type="reduce_receivable">
                            <input type="radio" name="adj_type" value="reduce_receivable" class="sr-only">
                            <div class="flex items-start gap-3">
                                <div class="w-9 h-9 rounded-full bg-orange-100 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-eraser text-orange-600 text-sm"></i>
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-800 text-sm">Reduce Receivable</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Remove a previously recorded receivable. Adds a debit — increases net payable.</p>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- ── Amount + Event Date ─────────────────────────────────── -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">
                            Amount (৳) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="adj_amount" id="adj_amount"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                               step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">
                            Event Date <span class="text-gray-400 text-xs font-normal">(optional — when it actually occurred)</span>
                        </label>
                        <input type="date" name="adj_event_date" id="adj_event_date"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                               max="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>

                <!-- ── Remarks ────────────────────────────────────────────── -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">
                        Remarks / Reason <span class="text-red-500">*</span>
                    </label>
                    <textarea name="adj_remarks" rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                              placeholder="Explain why this adjustment is needed (e.g. missed GRN from Jan, overstated invoice, returned cash not recorded)..."
                              required></textarea>
                </div>

                <!-- ── Payment Method ─────────────────────────────────────── -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">
                        Payment Method
                        <span class="text-gray-400 text-xs font-normal">(optional — select if a cash/bank movement was involved)</span>
                    </label>
                    <select name="adj_payment_method" id="adj_payment_method"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            onchange="onPaymentMethodChange()">
                        <option value="none">None — No payment involved</option>
                        <option value="cash">Cash</option>
                        <option value="bank">Bank Transfer / Deposit</option>
                        <option value="cheque">Cheque</option>
                    </select>
                </div>

                <!-- ── Bank Account (shown for bank/cheque) ───────────────── -->
                <div id="bankAccountRow" class="hidden">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Bank Account</label>
                    <select name="adj_bank_account_id" id="adj_bank_account_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="">-- Select Bank Account --</option>
                        <?php foreach ($bank_accounts as $ba): ?>
                        <option value="<?php echo $ba->id; ?>">
                            <?php echo htmlspecialchars($ba->bank_name . ' — ' . $ba->account_name); ?>
                            (<?php echo htmlspecialchars($ba->account_number ?? ''); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- ── Cash Account (shown for cash) ─────────────────────── -->
                <div id="cashAccountRow" class="hidden">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Cash Account</label>
                    <select name="adj_cash_account_id" id="adj_cash_account_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="">-- Select Cash Account --</option>
                        <?php foreach ($cash_accounts as $ca): ?>
                        <option value="<?php echo $ca->id; ?>">
                            <?php echo htmlspecialchars($ca->account_name); ?>
                            <?php if (!empty($ca->branch_name)): ?> — <?php echo htmlspecialchars($ca->branch_name); ?><?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- ── Payment Reference (shown when method ≠ none) ──────── -->
                <div id="paymentRefRow" class="hidden">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">
                        Reference / Transaction No.
                    </label>
                    <input type="text" name="adj_payment_reference"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                           placeholder="Cheque no., Bank TXN ID, etc.">
                </div>

                <!-- ── Effect preview ─────────────────────────────────────── -->
                <div id="adjPreview" class="hidden rounded-lg p-4 border text-sm">
                    <div class="font-semibold mb-1" id="adjPreviewTitle">Effect:</div>
                    <div id="adjPreviewBody"></div>
                </div>

                <!-- ── Actions ────────────────────────────────────────────── -->
                <div class="flex justify-between pt-2 border-t border-gray-100">
                    <button type="button" onclick="closeAdjModal()"
                            class="border border-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit"
                            class="bg-indigo-600 text-white px-6 py-2 rounded-lg hover:bg-indigo-700 flex items-center gap-2"
                            onclick="return confirmAdj()">
                        <i class="fas fa-save"></i> Save Adjustment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div><!-- /adjModal -->


<style>
@media print {
    .no-print { display: none !important; }
    .bg-gray-50 { background-color: #f9fafb !important; }
}
.adj-type-card.selected {
    border-color: var(--selected-color, #6366f1);
    background-color: var(--selected-bg, #eef2ff);
}
</style>

<script>
// ─── Modal open / close ───────────────────────────────────────────────────────
function openAdjModal() {
    document.getElementById('adjModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeAdjModal() {
    document.getElementById('adjModal').classList.add('hidden');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAdjModal(); });

// ─── Adjustment type card selection ──────────────────────────────────────────
const typeColors = {
    add_payable:       { color: '#dc2626', bg: '#fef2f2', label: 'Debit',  dir: '▲ Increases balance due'      },
    reduce_payable:    { color: '#16a34a', bg: '#f0fdf4', label: 'Credit', dir: '▼ Decreases balance due'      },
    add_receivable:    { color: '#2563eb', bg: '#eff6ff', label: 'Credit', dir: '▼ Decreases net payable'      },
    reduce_receivable: { color: '#d97706', bg: '#fffbeb', label: 'Debit',  dir: '▲ Increases net payable'      },
};

document.querySelectorAll('.adj-type-card').forEach(card => {
    card.addEventListener('click', function() {
        // Unselect all
        document.querySelectorAll('.adj-type-card').forEach(c => {
            c.classList.remove('selected');
            c.style.removeProperty('--selected-color');
            c.style.removeProperty('--selected-bg');
        });
        // Select this
        const type = this.dataset.type;
        const col  = typeColors[type];
        this.style.setProperty('--selected-color', col.color);
        this.style.setProperty('--selected-bg',    col.bg);
        this.classList.add('selected');
        this.querySelector('input[type=radio]').checked = true;
        updatePreview();
    });
});

// ─── Payment method show/hide ─────────────────────────────────────────────────
function onPaymentMethodChange() {
    const method = document.getElementById('adj_payment_method').value;
    document.getElementById('bankAccountRow').classList.toggle('hidden', method !== 'bank' && method !== 'cheque');
    document.getElementById('cashAccountRow').classList.toggle('hidden', method !== 'cash');
    document.getElementById('paymentRefRow').classList.toggle('hidden',  method === 'none');
}

// ─── Effect preview ───────────────────────────────────────────────────────────
function updatePreview() {
    const type   = document.querySelector('input[name="adj_type"]:checked')?.value;
    const amount = parseFloat(document.getElementById('adj_amount')?.value) || 0;
    const preview = document.getElementById('adjPreview');
    const title   = document.getElementById('adjPreviewTitle');
    const body    = document.getElementById('adjPreviewBody');

    if (!type || amount <= 0) { preview.classList.add('hidden'); return; }

    const col = typeColors[type];
    preview.style.borderColor     = col.color;
    preview.style.backgroundColor = col.bg;
    title.style.color = col.color;
    title.textContent = col.label + ' Entry — ' + col.dir;

    const fmt = v => '৳' + v.toLocaleString('en-BD', {minimumFractionDigits: 2});
    const isDebit = ['add_payable','reduce_receivable'].includes(type);

    body.innerHTML = isDebit
        ? `<span style="color:#dc2626">Debit: <strong>${fmt(amount)}</strong></span> — recorded as amount owed to supplier`
        : `<span style="color:#16a34a">Credit: <strong>${fmt(amount)}</strong></span> — recorded as reduction in what we owe`;
    preview.classList.remove('hidden');
}

document.getElementById('adj_amount')?.addEventListener('input', updatePreview);

// ─── Excluded entries toggle ──────────────────────────────────────────────────
function toggleExcluded() {
    const panel   = document.getElementById('excludedPanel');
    const chevron = document.getElementById('excl-chevron');
    const hidden  = panel.classList.toggle('hidden');
    chevron.style.transform = hidden ? '' : 'rotate(180deg)';
    chevron.style.transition = 'transform 0.2s';
}

// ─── Confirm before submit ────────────────────────────────────────────────────
function confirmAdj() {
    const type   = document.querySelector('input[name="adj_type"]:checked')?.value;
    const amount = parseFloat(document.getElementById('adj_amount')?.value) || 0;
    const labels = {
        add_payable:       'Add Payable (Debit)',
        reduce_payable:    'Reduce Payable (Credit)',
        add_receivable:    'Add Receivable (Credit)',
        reduce_receivable: 'Reduce Receivable (Debit)',
    };
    if (!type)    { alert('Please select an adjustment type.'); return false; }
    if (amount <= 0) { alert('Please enter a valid amount.'); return false; }
    return confirm(`Save ${labels[type]} adjustment of ৳${amount.toLocaleString('en-BD', {minimumFractionDigits:2})}?\n\nThis will be posted immediately to the supplier ledger.`);
}
</script>

<?php include '../templates/footer.php'; ?>

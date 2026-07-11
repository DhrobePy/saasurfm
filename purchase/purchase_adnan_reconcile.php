<?php
/**
 * Procurement Reconciliation Checklist
 * Superadmin/admin tool: live data-quality checks that highlight anomalies
 * users should review and fix through normal UI flows (with full audit trail).
 */
require_once __DIR__ . '/../core/init.php';
restrict_access(['Superadmin', 'admin']);

$pageTitle   = "Procurement Reconciliation Checklist";
$currentUser = getCurrentUser();
$db          = Database::getInstance()->getPdo();

// ─────────────────────────────────────────────────────────────────────────────
// CHECK 1 — Stale balance_payable
// POs where the stored balance_payable column doesn't match the computed balance
// (GRN expected qty × price + adjustments − paid).  Difference > ৳1.
// ─────────────────────────────────────────────────────────────────────────────
$check1 = $db->query("
    SELECT
        po.id,
        po.po_number,
        po.supplier_name,
        po.po_date,
        po.delivery_status,
        po.payment_status,
        po.balance_payable                                                       AS stored_balance,
        ROUND(
            COALESCE(grn_agg.total_expected,0) * po.unit_price_per_kg
            + COALESCE(po.total_adjustment_amount,0)
            - COALESCE(po.total_paid,0)
        , 2)                                                                     AS computed_balance
    FROM purchase_orders_adnan po
    LEFT JOIN (
        SELECT purchase_order_id, SUM(expected_quantity) AS total_expected
        FROM goods_received_adnan WHERE grn_status != 'cancelled'
        GROUP BY purchase_order_id
    ) grn_agg ON grn_agg.purchase_order_id = po.id
    WHERE po.po_status != 'cancelled'
    HAVING ABS(stored_balance - computed_balance) > 1
    ORDER BY ABS(stored_balance - computed_balance) DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_OBJ);

// ─────────────────────────────────────────────────────────────────────────────
// CHECK 2 — Origin confusion
// POs marked as 'Other' but have remarks mentioning a real country,
// OR marked as 'Argentina' (known form bug where Brazil was submitted as Argentina).
// ─────────────────────────────────────────────────────────────────────────────
$check2 = $db->query("
    SELECT id, po_number, supplier_name, wheat_origin, remarks, quantity_kg, po_date, delivery_status
    FROM purchase_orders_adnan
    WHERE po_status != 'cancelled'
      AND (
        (wheat_origin = 'Other' AND remarks IS NOT NULL AND remarks != '')
        OR wheat_origin = 'Argentina'
      )
    ORDER BY po_date DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_OBJ);

// ─────────────────────────────────────────────────────────────────────────────
// CHECK 3 — Stuck adjustment notes (draft or approved, older than 7 days)
// These should have been posted or cancelled. Leaving them in draft means the
// financial effect hasn't hit the accounts yet.
// ─────────────────────────────────────────────────────────────────────────────
$check3 = $db->query("
    SELECT
        pan.id, pan.note_number, pan.note_type, pan.amount, pan.status,
        pan.purchase_order_id, pan.created_at,
        po.po_number, po.supplier_name,
        DATEDIFF(NOW(), pan.created_at) AS days_pending
    FROM purchase_adjustment_notes pan
    JOIN purchase_orders_adnan po ON pan.purchase_order_id = po.id
    WHERE pan.status IN ('draft','approved')
      AND pan.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY pan.created_at ASC
    LIMIT 20
")->fetchAll(PDO::FETCH_OBJ);

// ─────────────────────────────────────────────────────────────────────────────
// CHECK 4 — English unload point names in GRNs
// Production DB has some GRNs with English branch names ('Demra', 'Sirajgonj')
// while most use Bengali. These should be updated to Bengali to avoid
// split-branch issues in reports.
// ─────────────────────────────────────────────────────────────────────────────
$check4 = $db->query("
    SELECT unload_point_name, COUNT(*) AS cnt
    FROM goods_received_adnan
    WHERE unload_point_name IN ('Demra','Sirajgonj','Sirajganj','Rampura')
      AND grn_status != 'cancelled'
    GROUP BY unload_point_name
    ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_OBJ);

// Which GRNs are affected (show a sample)
$check4_grns = $db->query("
    SELECT grn.id, grn.grn_number, grn.grn_date, grn.unload_point_name,
           po.po_number, po.supplier_name
    FROM goods_received_adnan grn
    JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
    WHERE grn.unload_point_name IN ('Demra','Sirajgonj','Sirajganj','Rampura')
      AND grn.grn_status != 'cancelled'
    ORDER BY grn.grn_date DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_OBJ);

// ─────────────────────────────────────────────────────────────────────────────
// CHECK 5 — Payments that have not been posted (is_posted = 0)
// older than 7 days. These may be draft payments that were never confirmed.
// ─────────────────────────────────────────────────────────────────────────────
$check5 = $db->query("
    SELECT id, payment_voucher_number, purchase_order_id, po_number, supplier_name,
           amount_paid, payment_date, payment_method, remarks
    FROM purchase_payments_adnan
    WHERE is_posted = 0
      AND payment_date < DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY payment_date ASC
    LIMIT 20
")->fetchAll(PDO::FETCH_OBJ);

// ─────────────────────────────────────────────────────────────────────────────
// CHECK 6 — GRNs referencing non-existent POs (orphan GRNs)
// ─────────────────────────────────────────────────────────────────────────────
$check6 = $db->query("
    SELECT grn.purchase_order_id, grn.po_number AS grn_po_number,
           COUNT(*) AS grn_count,
           SUM(grn.expected_quantity) AS total_qty,
           MIN(grn.grn_date) AS first_date, MAX(grn.grn_date) AS last_date
    FROM goods_received_adnan grn
    WHERE grn.grn_status != 'cancelled'
      AND NOT EXISTS (SELECT 1 FROM purchase_orders_adnan po WHERE po.id = grn.purchase_order_id)
    GROUP BY grn.purchase_order_id, grn.po_number
    ORDER BY total_qty DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_OBJ);

// ─────────────────────────────────────────────────────────────────────────────
// CHECK 7 — Closed POs with non-zero balance_payable
// These appear in AP listings even though the order is closed,
// causing inflated outstanding figures.
// ─────────────────────────────────────────────────────────────────────────────
$check7 = $db->query("
    SELECT
        po.id, po.po_number, po.supplier_name, po.po_date,
        po.balance_payable,
        ROUND(
            COALESCE(grn_agg.total_expected,0) * po.unit_price_per_kg
            + COALESCE(po.total_adjustment_amount,0)
            - COALESCE(po.total_paid,0)
        , 2) AS computed_balance
    FROM purchase_orders_adnan po
    LEFT JOIN (
        SELECT purchase_order_id, SUM(expected_quantity) AS total_expected
        FROM goods_received_adnan WHERE grn_status != 'cancelled'
        GROUP BY purchase_order_id
    ) grn_agg ON grn_agg.purchase_order_id = po.id
    WHERE po.delivery_status IN ('closed','completed')
      AND po.po_status != 'cancelled'
      AND po.balance_payable > 1
    ORDER BY po.balance_payable DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_OBJ);

// ─────────────────────────────────────────────────────────────────────────────
// Summary counts
// ─────────────────────────────────────────────────────────────────────────────
$total_issues = (count($check1) > 0 ? 1 : 0)
              + (count($check2) > 0 ? 1 : 0)
              + (count($check3) > 0 ? 1 : 0)
              + (count($check4) > 0 ? 1 : 0)
              + (count($check5) > 0 ? 1 : 0)
              + (count($check6) > 0 ? 1 : 0)
              + (count($check7) > 0 ? 1 : 0);

require_once __DIR__ . '/../templates/header.php';
?>

<div class="container mx-auto px-4 py-6">

    <!-- Page header -->
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-clipboard-check text-orange-600"></i>
                Procurement Reconciliation Checklist
            </h1>
            <p class="text-gray-500 text-sm mt-1">
                Live checks that find data anomalies — click any PO/GRN/Note link to open it and fix through normal screens. All changes are logged with an audit trail.
            </p>
        </div>
        <div class="flex items-center gap-3">
            <?php if ($total_issues === 0): ?>
            <span class="inline-flex items-center gap-2 px-4 py-2 bg-green-100 text-green-700 font-semibold rounded-full text-sm">
                <i class="fas fa-check-circle"></i> All checks passed
            </span>
            <?php else: ?>
            <span class="inline-flex items-center gap-2 px-4 py-2 bg-red-100 text-red-700 font-semibold rounded-full text-sm">
                <i class="fas fa-exclamation-circle"></i> <?php echo $total_issues; ?> check<?php echo $total_issues > 1 ? 's' : ''; ?> need attention
            </span>
            <?php endif; ?>
            <a href="purchase_adnan_index.php" class="border border-gray-300 text-gray-600 px-4 py-2 rounded-lg text-sm hover:bg-gray-50">
                <i class="fas fa-arrow-left mr-1"></i> Back to Purchase
            </a>
            <a href="?<?php echo htmlspecialchars($_SERVER['QUERY_STRING'] ?? ''); ?>" class="border border-blue-500 text-blue-600 px-4 py-2 rounded-lg text-sm hover:bg-blue-50">
                <i class="fas fa-sync-alt mr-1"></i> Refresh
            </a>
        </div>
    </div>

    <!-- Instruction banner -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 text-sm text-blue-800">
        <p class="font-semibold mb-1"><i class="fas fa-info-circle mr-1"></i> How to use this page:</p>
        <ul class="list-disc list-inside space-y-1 text-blue-700">
            <li>Each section below describes a specific data problem and lists the affected orders/records.</li>
            <li>Click the PO number to open that order and make corrections (edit wheat origin, cancel a stuck note, etc.).</li>
            <li>Every change you make through the normal screens is automatically recorded in the audit log — no manual SQL needed.</li>
            <li>When a problem is fixed, it disappears from this list automatically the next time you refresh.</li>
        </ul>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         CHECK 1 — Balance mismatch
         ══════════════════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-lg shadow mb-6 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 <?php echo count($check1) > 0 ? 'bg-red-600' : 'bg-green-600'; ?> text-white">
            <div class="flex items-center gap-3">
                <?php if (count($check1) > 0): ?>
                <span class="bg-white text-red-600 text-xs font-bold px-2 py-1 rounded-full"><?php echo count($check1); ?></span>
                <?php else: ?>
                <i class="fas fa-check-circle text-xl"></i>
                <?php endif; ?>
                <div>
                    <h3 class="font-bold text-lg">Check 1 — Payable balance is out of sync</h3>
                    <p class="text-sm opacity-80">The stored balance figure doesn't match what the system would calculate today.</p>
                </div>
            </div>
        </div>

        <?php if (count($check1) === 0): ?>
        <div class="p-6 text-gray-500 text-center"><i class="fas fa-check-circle text-green-500 mr-2"></i>No mismatches found. All balances are correct.</div>
        <?php else: ?>
        <div class="p-4 bg-red-50 border-b border-red-100 text-sm text-red-800">
            <strong>What is the problem?</strong> Each PO's "balance payable" is saved in a column, but adjustments (DAN/CAN) or partial payments can make that saved number go stale.
            The orders below show a difference between what is saved and what is calculated.<br>
            <strong>What to do?</strong> Open each PO → go to its Payments tab → record any missing payment, or go to Adjustments and post/cancel the pending adjustment. The balance will recalculate automatically.
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO Number</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Supplier</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO Date</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Stored Balance</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Computed Balance</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Difference</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($check1 as $row):
                        $diff = $row->stored_balance - $row->computed_balance;
                    ?>
                    <tr class="hover:bg-red-50">
                        <td class="px-4 py-3 font-mono font-semibold text-blue-700">
                            <a href="purchase_adnan_view_po.php?id=<?php echo $row->id; ?>" class="hover:underline"><?php echo htmlspecialchars($row->po_number); ?></a>
                        </td>
                        <td class="px-4 py-3 text-gray-700"><?php echo htmlspecialchars($row->supplier_name); ?></td>
                        <td class="px-4 py-3 text-gray-500"><?php echo date('d M Y', strtotime($row->po_date)); ?></td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold <?php echo $row->delivery_status === 'closed' ? 'bg-gray-200 text-gray-700' : 'bg-yellow-100 text-yellow-800'; ?>">
                                <?php echo $row->delivery_status; ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right text-gray-700">৳<?php echo number_format($row->stored_balance, 0); ?></td>
                        <td class="px-4 py-3 text-right font-semibold text-gray-900">৳<?php echo number_format($row->computed_balance, 0); ?></td>
                        <td class="px-4 py-3 text-right font-bold <?php echo abs($diff) > 100000 ? 'text-red-600' : 'text-orange-500'; ?>">
                            <?php echo $diff > 0 ? '+' : ''; ?>৳<?php echo number_format($diff, 0); ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="purchase_adnan_view_po.php?id=<?php echo $row->id; ?>" class="inline-flex items-center gap-1 px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">
                                <i class="fas fa-eye"></i> Open PO
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         CHECK 2 — Origin confusion
         ══════════════════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-lg shadow mb-6 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 <?php echo count($check2) > 0 ? 'bg-orange-500' : 'bg-green-600'; ?> text-white">
            <div class="flex items-center gap-3">
                <?php if (count($check2) > 0): ?>
                <span class="bg-white text-orange-600 text-xs font-bold px-2 py-1 rounded-full"><?php echo count($check2); ?></span>
                <?php else: ?>
                <i class="fas fa-check-circle text-xl"></i>
                <?php endif; ?>
                <div>
                    <h3 class="font-bold text-lg">Check 2 — Wheat origin may be wrong</h3>
                    <p class="text-sm opacity-80">Orders saved as "Other" or "Argentina" that might be Brazil or another country.</p>
                </div>
            </div>
        </div>

        <?php if (count($check2) === 0): ?>
        <div class="p-6 text-gray-500 text-center"><i class="fas fa-check-circle text-green-500 mr-2"></i>No origin confusion found.</div>
        <?php else: ?>
        <div class="p-4 bg-orange-50 border-b border-orange-100 text-sm text-orange-900">
            <strong>What is the problem?</strong> Some orders were entered before "Brazil" was added to the origin dropdown. They were saved as "Other" with "Brazil" in the remarks. Also, a form bug in early 2025 saved Brazil orders as "Argentina".<br>
            <strong>What to do?</strong> Open each PO → click Edit → change the Wheat Origin to the correct country → Save. Check the Remarks column below to see what origin was intended.
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO Number</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Supplier</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO Date</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Saved As</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Qty (kg)</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Remarks (clue)</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($check2 as $row): ?>
                    <tr class="hover:bg-orange-50">
                        <td class="px-4 py-3 font-mono font-semibold text-blue-700">
                            <a href="purchase_adnan_view_po.php?id=<?php echo $row->id; ?>" class="hover:underline"><?php echo htmlspecialchars($row->po_number); ?></a>
                        </td>
                        <td class="px-4 py-3 text-gray-700"><?php echo htmlspecialchars($row->supplier_name); ?></td>
                        <td class="px-4 py-3 text-gray-500"><?php echo date('d M Y', strtotime($row->po_date)); ?></td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded text-xs font-semibold bg-red-100 text-red-700">
                                <?php echo htmlspecialchars($row->wheat_origin); ?>
                            </span>
                            <?php if ($row->wheat_origin === 'Argentina'): ?>
                            <span class="ml-1 text-xs text-gray-500">→ likely Brazil?</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right text-gray-600"><?php echo number_format($row->quantity_kg, 0); ?></td>
                        <td class="px-4 py-3 text-gray-500 italic text-xs max-w-xs truncate"><?php echo htmlspecialchars($row->remarks ?? '—'); ?></td>
                        <td class="px-4 py-3 text-center">
                            <a href="purchase_adnan_edit_po.php?id=<?php echo $row->id; ?>" class="inline-flex items-center gap-1 px-3 py-1 bg-orange-500 text-white text-xs rounded hover:bg-orange-600">
                                <i class="fas fa-edit"></i> Edit PO
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         CHECK 3 — Stuck adjustment notes
         ══════════════════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-lg shadow mb-6 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 <?php echo count($check3) > 0 ? 'bg-yellow-600' : 'bg-green-600'; ?> text-white">
            <div class="flex items-center gap-3">
                <?php if (count($check3) > 0): ?>
                <span class="bg-white text-yellow-700 text-xs font-bold px-2 py-1 rounded-full"><?php echo count($check3); ?></span>
                <?php else: ?>
                <i class="fas fa-check-circle text-xl"></i>
                <?php endif; ?>
                <div>
                    <h3 class="font-bold text-lg">Check 3 — Adjustment notes sitting in draft/approved for 7+ days</h3>
                    <p class="text-sm opacity-80">These notes were never posted or cancelled, so the financial effect hasn't been recorded.</p>
                </div>
            </div>
        </div>

        <?php if (count($check3) === 0): ?>
        <div class="p-6 text-gray-500 text-center"><i class="fas fa-check-circle text-green-500 mr-2"></i>No stuck adjustment notes found.</div>
        <?php else: ?>
        <div class="p-4 bg-yellow-50 border-b border-yellow-100 text-sm text-yellow-900">
            <strong>What is the problem?</strong> A Debit Adjustment Note (DAN) or Credit Adjustment Note (CAN) was created but not posted. Until posted, it doesn't affect the payable balance.<br>
            <strong>What to do?</strong> Open the note → if the adjustment is correct, click <strong>Post</strong> → it will update the payable amount. If it was created by mistake, click <strong>Cancel</strong> to discard it.
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Note Number</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Supplier</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Amount</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Created</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Days Waiting</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($check3 as $row): ?>
                    <tr class="hover:bg-yellow-50">
                        <td class="px-4 py-3 font-mono font-semibold text-blue-700">
                            <a href="purchase_adnan_view_adjustment.php?id=<?php echo $row->id; ?>" class="hover:underline"><?php echo htmlspecialchars($row->note_number); ?></a>
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded text-xs font-semibold <?php echo $row->note_type === 'DAN' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'; ?>">
                                <?php echo $row->note_type; ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 font-mono text-blue-700">
                            <a href="purchase_adnan_view_po.php?id=<?php echo $row->purchase_order_id; ?>" class="hover:underline"><?php echo htmlspecialchars($row->po_number); ?></a>
                        </td>
                        <td class="px-4 py-3 text-gray-700"><?php echo htmlspecialchars($row->supplier_name); ?></td>
                        <td class="px-4 py-3 text-right font-semibold text-gray-900">৳<?php echo number_format($row->amount, 0); ?></td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded text-xs font-semibold bg-yellow-100 text-yellow-800"><?php echo $row->status; ?></span>
                        </td>
                        <td class="px-4 py-3 text-gray-500"><?php echo date('d M Y', strtotime($row->created_at)); ?></td>
                        <td class="px-4 py-3 text-right font-bold <?php echo $row->days_pending > 30 ? 'text-red-600' : 'text-orange-600'; ?>">
                            <?php echo $row->days_pending; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="purchase_adnan_view_adjustment.php?id=<?php echo $row->id; ?>" class="inline-flex items-center gap-1 px-3 py-1 bg-yellow-600 text-white text-xs rounded hover:bg-yellow-700">
                                <i class="fas fa-eye"></i> Review Note
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         CHECK 4 — English branch names in GRNs
         ══════════════════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-lg shadow mb-6 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 <?php echo count($check4) > 0 ? 'bg-indigo-600' : 'bg-green-600'; ?> text-white">
            <div class="flex items-center gap-3">
                <?php if (count($check4) > 0): ?>
                <span class="bg-white text-indigo-700 text-xs font-bold px-2 py-1 rounded-full"><?php echo count($check4_grns); ?></span>
                <?php else: ?>
                <i class="fas fa-check-circle text-xl"></i>
                <?php endif; ?>
                <div>
                    <h3 class="font-bold text-lg">Check 4 — GRN branch names in English instead of Bengali</h3>
                    <p class="text-sm opacity-80">Causes the same branch to appear twice in branch reports (once in Bengali, once in English).</p>
                </div>
            </div>
        </div>

        <?php if (count($check4) === 0): ?>
        <div class="p-6 text-gray-500 text-center"><i class="fas fa-check-circle text-green-500 mr-2"></i>All GRN branch names are in the correct format.</div>
        <?php else: ?>
        <div class="p-4 bg-indigo-50 border-b border-indigo-100 text-sm text-indigo-900">
            <strong>What is the problem?</strong> GRNs should store the branch name in Bengali (e.g., "ডেমরা") but some were entered in English ("Demra"). This makes branch reports show double entries.<br>
            <strong>What to do?</strong> Open each GRN → click Edit → change the Unload Point to the correct Bengali branch name → Save.
            <div class="mt-2 flex gap-4">
                <?php foreach ($check4 as $b): ?>
                <span class="font-semibold">"<?php echo htmlspecialchars($b->unload_point_name); ?>" — <?php echo $b->cnt; ?> GRNs</span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">GRN Number</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">GRN Date</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Branch (Wrong)</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Should Be</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Supplier</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php
                    $branch_map_correct = [
                        'Demra'     => 'ডেমরা',
                        'Sirajgonj' => 'সিরাজগঞ্জ',
                        'Sirajganj' => 'সিরাজগঞ্জ',
                        'Rampura'   => 'রামপুরা',
                    ];
                    foreach ($check4_grns as $row): ?>
                    <tr class="hover:bg-indigo-50">
                        <td class="px-4 py-3 font-mono font-semibold text-blue-700">
                            <a href="purchase_adnan_edit_grn.php?id=<?php echo $row->id; ?>" class="hover:underline"><?php echo htmlspecialchars($row->grn_number); ?></a>
                        </td>
                        <td class="px-4 py-3 text-gray-500"><?php echo date('d M Y', strtotime($row->grn_date)); ?></td>
                        <td class="px-4 py-3 text-red-600 font-semibold"><?php echo htmlspecialchars($row->unload_point_name); ?></td>
                        <td class="px-4 py-3 text-green-700 font-semibold"><?php echo $branch_map_correct[$row->unload_point_name] ?? '—'; ?></td>
                        <td class="px-4 py-3 font-mono text-blue-700">
                            <a href="purchase_adnan_view_po.php?id=<?php echo $row->id ?? 0; ?>" class="hover:underline"><?php echo htmlspecialchars($row->po_number); ?></a>
                        </td>
                        <td class="px-4 py-3 text-gray-700"><?php echo htmlspecialchars($row->supplier_name); ?></td>
                        <td class="px-4 py-3 text-center">
                            <a href="purchase_adnan_edit_grn.php?id=<?php echo $row->id; ?>" class="inline-flex items-center gap-1 px-3 py-1 bg-indigo-600 text-white text-xs rounded hover:bg-indigo-700">
                                <i class="fas fa-edit"></i> Edit GRN
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         CHECK 5 — Unposted payments
         ══════════════════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-lg shadow mb-6 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 <?php echo count($check5) > 0 ? 'bg-purple-600' : 'bg-green-600'; ?> text-white">
            <div class="flex items-center gap-3">
                <?php if (count($check5) > 0): ?>
                <span class="bg-white text-purple-700 text-xs font-bold px-2 py-1 rounded-full"><?php echo count($check5); ?></span>
                <?php else: ?>
                <i class="fas fa-check-circle text-xl"></i>
                <?php endif; ?>
                <div>
                    <h3 class="font-bold text-lg">Check 5 — Payments not posted (older than 7 days)</h3>
                    <p class="text-sm opacity-80">Payment records exist but have not been posted, so they haven't reduced the payable balance.</p>
                </div>
            </div>
        </div>

        <?php if (count($check5) === 0): ?>
        <div class="p-6 text-gray-500 text-center"><i class="fas fa-check-circle text-green-500 mr-2"></i>No unposted payments found.</div>
        <?php else: ?>
        <div class="p-4 bg-purple-50 border-b border-purple-100 text-sm text-purple-900">
            <strong>What is the problem?</strong> These payment vouchers were saved as drafts and never posted to the accounts. The supplier payable balance hasn't been reduced yet.<br>
            <strong>What to do?</strong> Open each PO → go to Payments → find the payment and click <strong>Post</strong> if it's correct. If it was entered by mistake, click <strong>Delete</strong> to remove it.
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Voucher</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Supplier</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Amount</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Payment Date</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Method</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Remarks</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($check5 as $row): ?>
                    <tr class="hover:bg-purple-50">
                        <td class="px-4 py-3 font-mono font-semibold text-gray-700"><?php echo htmlspecialchars($row->payment_voucher_number ?? '—'); ?></td>
                        <td class="px-4 py-3 text-gray-700"><?php echo htmlspecialchars($row->supplier_name); ?></td>
                        <td class="px-4 py-3 font-mono text-blue-700">
                            <a href="purchase_adnan_view_po.php?id=<?php echo $row->purchase_order_id; ?>" class="hover:underline"><?php echo htmlspecialchars($row->po_number); ?></a>
                        </td>
                        <td class="px-4 py-3 text-right font-semibold text-gray-900">৳<?php echo number_format($row->amount_paid, 0); ?></td>
                        <td class="px-4 py-3 text-gray-500"><?php echo date('d M Y', strtotime($row->payment_date)); ?></td>
                        <td class="px-4 py-3 text-gray-600"><?php echo ucfirst($row->payment_method ?? '—'); ?></td>
                        <td class="px-4 py-3 text-gray-500 italic text-xs max-w-xs truncate"><?php echo htmlspecialchars($row->remarks ?? '—'); ?></td>
                        <td class="px-4 py-3 text-center">
                            <a href="purchase_adnan_view_po.php?id=<?php echo $row->purchase_order_id; ?>#payments" class="inline-flex items-center gap-1 px-3 py-1 bg-purple-600 text-white text-xs rounded hover:bg-purple-700">
                                <i class="fas fa-eye"></i> Open PO
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         CHECK 6 — Orphan GRNs
         ══════════════════════════════════════════════════════════════════════ -->
    <?php if (count($check6) > 0): ?>
    <div class="bg-white rounded-lg shadow mb-6 overflow-hidden">
        <div class="flex items-center gap-3 px-6 py-4 bg-red-700 text-white">
            <span class="bg-white text-red-700 text-xs font-bold px-2 py-1 rounded-full"><?php echo count($check6); ?></span>
            <div>
                <h3 class="font-bold text-lg">Check 6 — GRNs with no matching Purchase Order</h3>
                <p class="text-sm opacity-80">These goods receipts reference PO IDs that no longer exist in the system.</p>
            </div>
        </div>
        <div class="p-4 bg-red-50 border-b border-red-100 text-sm text-red-900">
            <strong>What is the problem?</strong> These GRN records point to Purchase Orders that have been deleted. The received goods are not linked to any order, so they won't appear in any PO report.<br>
            <strong>What to do?</strong> Contact your system administrator. These records may need to be cancelled or re-linked to the correct PO.
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Missing PO ID</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO Number in GRN</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">GRN Count</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Total Qty (kg)</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date Range</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($check6 as $row): ?>
                    <tr class="hover:bg-red-50">
                        <td class="px-4 py-3 font-mono text-red-600 font-bold">#<?php echo $row->purchase_order_id; ?></td>
                        <td class="px-4 py-3 font-mono text-gray-700"><?php echo htmlspecialchars($row->grn_po_number ?? '—'); ?></td>
                        <td class="px-4 py-3 text-right text-gray-700"><?php echo number_format($row->grn_count); ?></td>
                        <td class="px-4 py-3 text-right text-gray-700"><?php echo number_format($row->total_qty, 0); ?></td>
                        <td class="px-4 py-3 text-gray-500"><?php echo date('d M Y', strtotime($row->first_date)); ?> – <?php echo date('d M Y', strtotime($row->last_date)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════════════════
         CHECK 7 — Closed POs still showing a payable
         ══════════════════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-lg shadow mb-6 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 <?php echo count($check7) > 0 ? 'bg-gray-700' : 'bg-green-600'; ?> text-white">
            <div class="flex items-center gap-3">
                <?php if (count($check7) > 0): ?>
                <span class="bg-white text-gray-700 text-xs font-bold px-2 py-1 rounded-full"><?php echo count($check7); ?></span>
                <?php else: ?>
                <i class="fas fa-check-circle text-xl"></i>
                <?php endif; ?>
                <div>
                    <h3 class="font-bold text-lg">Check 7 — Closed / completed POs still showing payable balance</h3>
                    <p class="text-sm opacity-80">These orders are marked as closed but still appear in the accounts payable list.</p>
                </div>
            </div>
        </div>

        <?php if (count($check7) === 0): ?>
        <div class="p-6 text-gray-500 text-center"><i class="fas fa-check-circle text-green-500 mr-2"></i>No closed POs with outstanding balance.</div>
        <?php else: ?>
        <div class="p-4 bg-gray-50 border-b border-gray-200 text-sm text-gray-800">
            <strong>What is the problem?</strong> These orders were closed/completed but the payable balance was not fully cleared. This typically happens when a DAN was posted after the order was already closed.<br>
            <strong>What to do?</strong> Open each PO and review: if the remaining amount is genuinely owed, record a payment to clear it. If it's a data error, the adjustment note may need to be cancelled.
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO Number</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Supplier</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO Date</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Stored Balance</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Computed Balance</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($check7 as $row): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono font-semibold text-blue-700">
                            <a href="purchase_adnan_view_po.php?id=<?php echo $row->id; ?>" class="hover:underline"><?php echo htmlspecialchars($row->po_number); ?></a>
                        </td>
                        <td class="px-4 py-3 text-gray-700"><?php echo htmlspecialchars($row->supplier_name); ?></td>
                        <td class="px-4 py-3 text-gray-500"><?php echo date('d M Y', strtotime($row->po_date)); ?></td>
                        <td class="px-4 py-3 text-right text-red-600 font-semibold">৳<?php echo number_format($row->balance_payable, 0); ?></td>
                        <td class="px-4 py-3 text-right font-semibold text-gray-900">৳<?php echo number_format($row->computed_balance, 0); ?></td>
                        <td class="px-4 py-3 text-center">
                            <a href="purchase_adnan_view_po.php?id=<?php echo $row->id; ?>" class="inline-flex items-center gap-1 px-3 py-1 bg-gray-700 text-white text-xs rounded hover:bg-gray-800">
                                <i class="fas fa-eye"></i> Open PO
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Footer note -->
    <div class="text-center text-xs text-gray-400 mt-6 mb-4">
        <i class="fas fa-shield-alt mr-1"></i>
        This page only reads data. All fixes must be made through the normal purchase screens. Every change is recorded in the system audit log with your name, timestamp, and the old and new values.
        &nbsp;|&nbsp; Last refreshed: <?php echo date('d M Y, h:i A'); ?>
    </div>

</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>

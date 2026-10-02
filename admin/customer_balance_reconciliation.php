<?php
/**
 * Customer Balance Reconciliation
 *
 * Built 2 Sep 2026 after a live sweep found 16 of 202 customers' stored
 * `customers.current_balance` (used across the app — customer lists,
 * dashboards, credit checks) diverging from the mathematically correct
 * ledger-truth balance, by up to ৳159M on one account. Root cause: a
 * customer onboarded with a NEGATIVE initial due never gets an opening-
 * balance row written to customer_ledger (only positive ones do), and
 * customer_ledger.balance_after is a separately-known-unreliable stored
 * chain that at least one balance-sync routine elsewhere blindly copies
 * into current_balance, permanently baking in any drift.
 *
 * This page surfaces any customer where current_balance disagrees with the
 * live, recomputed truth (the same formula cr/customer_ledger.php and
 * cr/customer_ledger_export.php already trust), explains — per customer —
 * the specific transaction the drift traces back to where possible, and
 * lets an admin reconcile it with one click. Two accounts are deliberately
 * left off this list — see $EXCLUDED_CUSTOMER_IDS below.
 *
 * Every reconcile is kept as a permanent, reversible history row (added
 * 2 Sep 2026 per explicit instruction: "keep the history in the reconciliation
 * page with button reconcile replaced with revert to un-reconcile state with
 * proper audit trail") — see customer_balance_reconciliations below. A
 * reconciled row's "Reconcile" button is replaced with "Un-reconcile", which
 * restores the exact stored balance that was overwritten, so nothing here is
 * a one-way, unrecoverable action.
 */
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id     = $currentUser['id'] ?? null;
$pageTitle   = 'Customer Balance Reconciliation';
$csrfToken   = $_SESSION['csrf_token'] ?? '';

// Internal HQ/mill consolidation pseudo-accounts — not real customers, and
// their balances need individual judgment rather than a one-click formula
// reconcile. Left out of this tool entirely, per explicit instruction.
$EXCLUDED_CUSTOMER_IDS = [149, 158]; // "Ujjal Flour Mill's", "Sirajganj Mills"

// ── Self-migrating history table (DDL before any transaction) ────────────
try { $db->getPdo()->exec("
    CREATE TABLE IF NOT EXISTS `customer_balance_reconciliations` (
      `id`                     bigint UNSIGNED NOT NULL AUTO_INCREMENT,
      `customer_id`            bigint UNSIGNED NOT NULL,
      `old_balance`            decimal(15,2) NOT NULL,
      `new_balance`            decimal(15,2) NOT NULL,
      `status`                 enum('active','reverted') NOT NULL DEFAULT 'active',
      `reconciled_by_user_id`  bigint UNSIGNED DEFAULT NULL,
      `reconciled_at`          timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `reverted_by_user_id`    bigint UNSIGNED DEFAULT NULL,
      `reverted_at`            timestamp NULL DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_customer_id` (`customer_id`),
      KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    COMMENT='Audit trail for admin/customer_balance_reconciliation.php — every current_balance correction, reversible.'
"); } catch (\Throwable $e) {}

// ── Handle reconcile / un-reconcile actions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['reconcile', 'unreconcile'], true)) {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        $_SESSION['error_flash'] = 'Security validation failed. Please refresh the page and try again.';
        header('Location: customer_balance_reconciliation.php');
        exit();
    }

    if ($_POST['action'] === 'reconcile') {
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        if (in_array($customer_id, $EXCLUDED_CUSTOMER_IDS, true)) {
            $_SESSION['error_flash'] = 'This account is excluded from automatic reconciliation.';
        } else {
            $c = $db->query("SELECT id, name, initial_due, current_balance FROM customers WHERE id = ?", [$customer_id])->first();
            if (!$c) {
                $_SESSION['error_flash'] = 'Customer not found.';
            } else {
                // Recompute fresh server-side — never trust a client-submitted
                // figure for a financial correction like this.
                $agg = $db->query(
                    "SELECT COALESCE(SUM(debit_amount),0) td, COALESCE(SUM(credit_amount),0) tc
                     FROM customer_ledger WHERE customer_id = ? AND reference_type != 'initial_due'",
                    [$customer_id]
                )->first();
                $true_balance = round((float)$c->initial_due + (float)$agg->td - (float)$agg->tc, 2);
                $old_balance  = (float)$c->current_balance;

                if (abs($true_balance - $old_balance) < 0.5) {
                    $_SESSION['error_flash'] = htmlspecialchars($c->name) . ' is already reconciled — nothing to do.';
                } else {
                    try {
                        $db->getPdo()->beginTransaction();
                        $db->query("UPDATE customers SET current_balance = ? WHERE id = ?", [$true_balance, $customer_id]);
                        $history_id = $db->insert('customer_balance_reconciliations', [
                            'customer_id'           => $customer_id,
                            'old_balance'           => $old_balance,
                            'new_balance'           => $true_balance,
                            'status'                => 'active',
                            'reconciled_by_user_id' => $user_id,
                        ]);
                        if (!$history_id) throw new Exception('Failed to record reconciliation history.');
                        $db->getPdo()->commit();
                    } catch (\Throwable $e) {
                        if ($db->getPdo()->inTransaction()) $db->getPdo()->rollBack();
                        $_SESSION['error_flash'] = 'Reconcile failed: ' . $e->getMessage();
                        header('Location: customer_balance_reconciliation.php');
                        exit();
                    }
                    auditLog('customers', 'current_balance_corrected',
                        "Reconciled current_balance for {$c->name} (#{$customer_id}) via admin/customer_balance_reconciliation.php "
                        . "[history #{$history_id}]: ৳" . number_format($old_balance, 2) . ' -> ৳' . number_format($true_balance, 2)
                        . ' by ' . ($currentUser['display_name'] ?? 'admin'),
                        ['customer_id' => $customer_id, 'old_balance' => $old_balance, 'new_balance' => $true_balance, 'history_id' => $history_id]
                    );
                    $_SESSION['success_flash'] = htmlspecialchars($c->name) . ' reconciled: ৳' . number_format($old_balance, 2)
                        . ' → ৳' . number_format($true_balance, 2) . '. This can be undone from the history below.';
                }
            }
        }
    } else {
        // ── Un-reconcile: restore the exact stored balance that a specific,
        // still-active reconciliation had overwritten. Acts on the history
        // row's own id (not just customer_id) so it's precise even if a
        // customer has been reconciled more than once over time.
        $history_id = (int)($_POST['history_id'] ?? 0);
        $row = $db->query(
            "SELECT r.*, c.name AS customer_name FROM customer_balance_reconciliations r
             JOIN customers c ON c.id = r.customer_id
             WHERE r.id = ? AND r.status = 'active'",
            [$history_id]
        )->first();
        if (!$row) {
            $_SESSION['error_flash'] = 'That reconciliation was not found, or has already been undone.';
        } else {
            try {
                $db->getPdo()->beginTransaction();
                $db->query("UPDATE customers SET current_balance = ? WHERE id = ?", [(float)$row->old_balance, (int)$row->customer_id]);
                $db->query(
                    "UPDATE customer_balance_reconciliations SET status = 'reverted', reverted_by_user_id = ?, reverted_at = NOW() WHERE id = ?",
                    [$user_id, $history_id]
                );
                $db->getPdo()->commit();
            } catch (\Throwable $e) {
                if ($db->getPdo()->inTransaction()) $db->getPdo()->rollBack();
                $_SESSION['error_flash'] = 'Un-reconcile failed: ' . $e->getMessage();
                header('Location: customer_balance_reconciliation.php');
                exit();
            }
            auditLog('customers', 'current_balance_reconciliation_reverted',
                "Un-reconciled {$row->customer_name} (#{$row->customer_id}) via admin/customer_balance_reconciliation.php "
                . "[history #{$history_id}]: restored stored balance to ৳" . number_format((float)$row->old_balance, 2)
                . ' (undoing the ৳' . number_format((float)$row->old_balance, 2) . ' -> ৳' . number_format((float)$row->new_balance, 2)
                . ' reconciliation from ' . $row->reconciled_at . ') by ' . ($currentUser['display_name'] ?? 'admin'),
                ['customer_id' => (int)$row->customer_id, 'history_id' => $history_id, 'restored_balance' => (float)$row->old_balance]
            );
            $_SESSION['success_flash'] = htmlspecialchars($row->customer_name) . ' un-reconciled — stored balance restored to ৳'
                . number_format((float)$row->old_balance, 2) . '.';
        }
    }
    header('Location: customer_balance_reconciliation.php');
    exit();
}

// ── Load current mismatches (live, every page load) ─────────────────────
$mismatches = getCustomerBalanceMismatches($EXCLUDED_CUSTOMER_IDS);
foreach ($mismatches as $m) {
    $diag = diagnoseCustomerBalanceMismatch($db, (int)$m->id, (float)$m->initial_due, (float)$m->diff);
    $m->reason_title = $diag['reason'];
    $m->reason_detail = $diag['detail'];
}

// ── Load full reconciliation history (newest first) ──────────────────────
$history = $db->query(
    "SELECT r.*, c.name AS customer_name, c.business_name,
            ub.display_name AS reconciled_by_name,
            rb.display_name AS reverted_by_name
     FROM customer_balance_reconciliations r
     JOIN customers c ON c.id = r.customer_id
     LEFT JOIN users ub ON ub.id = r.reconciled_by_user_id
     LEFT JOIN users rb ON rb.id = r.reverted_by_user_id
     ORDER BY r.reconciled_at DESC, r.id DESC"
)->results();

require_once '../templates/header.php';
?>

<div class="max-w-6xl mx-auto px-4 py-6">

    <div class="flex items-start justify-between mb-2">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-scale-balanced text-indigo-600"></i> Customer Balance Reconciliation
            </h1>
        </div>
        <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-sm font-semibold
                      <?php echo count($mismatches) > 0 ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'; ?>">
            <i class="fas <?php echo count($mismatches) > 0 ? 'fa-triangle-exclamation' : 'fa-check-circle'; ?>"></i>
            <?php echo count($mismatches); ?> mismatch<?php echo count($mismatches) === 1 ? '' : 'es'; ?>
        </span>
    </div>

    <!-- What this page is / why it exists -->
    <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-4 mb-6 text-sm text-indigo-900">
        <p class="mb-1">
            <strong>What this checks:</strong> every active customer's stored account balance
            (<code class="bg-white px-1 rounded">customers.current_balance</code> — what customer lists, dashboards,
            and credit checks read) against the true balance recomputed live from their full ledger history
            (the same formula the Customer Ledger page and its CSV export already use). Anything more than
            ৳0.50 apart shows up below.
        </p>
        <p>
            <strong>Why it can drift:</strong> the stored balance is meant to be kept in sync automatically as
            invoices and payments post, but a few code paths sync it by copying the ledger's own
            <code class="bg-white px-1 rounded">balance_after</code> column — which is a separately-known-unreliable
            running total that can drift after a historical edit or deletion. When that happens, the stored
            balance silently locks in the wrong number until someone reconciles it here.
        </p>
        <p class="mt-1">
            <strong>Every reconcile is reversible:</strong> nothing here is a one-way action — each correction is
            recorded below with the exact before/after figures, who did it, and when. If a reconcile turns out to
            be wrong, click "Un-reconcile" on it to restore the stored balance exactly as it was.
        </p>
        <p class="mt-1 text-indigo-700">
            <i class="fas fa-info-circle mr-1"></i>"Ujjal Flour Mill's" and "Sirajganj Mills" are intentionally left
            off this list — they're internal mill/HQ accounts, not real customers, and need individual review rather
            than a one-click reconcile.
        </p>
    </div>

    <?php if (empty($mismatches)): ?>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-12 text-center mb-8">
        <i class="fas fa-check-circle text-5xl text-green-300 mb-4"></i>
        <h3 class="text-base font-semibold text-gray-700 mb-1">All customer balances are reconciled</h3>
        <p class="text-sm text-gray-400">No customer's stored balance disagrees with their ledger-computed true balance right now.</p>
    </div>
    <?php else: ?>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-8">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-200 text-left">
                    <th class="px-4 py-3 font-semibold text-gray-500 uppercase text-xs tracking-wide">Customer</th>
                    <th class="px-4 py-3 font-semibold text-gray-500 uppercase text-xs tracking-wide text-right">Stored Balance</th>
                    <th class="px-4 py-3 font-semibold text-gray-500 uppercase text-xs tracking-wide text-right">True Balance</th>
                    <th class="px-4 py-3 font-semibold text-gray-500 uppercase text-xs tracking-wide text-right">Difference</th>
                    <th class="px-4 py-3 font-semibold text-gray-500 uppercase text-xs tracking-wide">Reason &amp; How to Reconcile</th>
                    <th class="px-4 py-3 font-semibold text-gray-500 uppercase text-xs tracking-wide text-center">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($mismatches as $m): ?>
                <tr class="hover:bg-gray-50 align-top">
                    <td class="px-4 py-3">
                        <a href="../cr/customer_ledger.php?customer_id=<?php echo (int)$m->id; ?>" class="font-semibold text-blue-700 hover:underline">
                            <?php echo htmlspecialchars($m->name); ?>
                        </a>
                        <?php if (!empty($m->business_name)): ?>
                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($m->business_name); ?></div>
                        <?php endif; ?>
                        <div class="text-xs text-gray-400">#<?php echo (int)$m->id; ?></div>
                    </td>
                    <td class="px-4 py-3 text-right font-mono">৳<?php echo number_format($m->current_balance, 2); ?></td>
                    <td class="px-4 py-3 text-right font-mono text-green-700">৳<?php echo number_format($m->true_balance, 2); ?></td>
                    <td class="px-4 py-3 text-right font-mono font-bold <?php echo $m->diff >= 0 ? 'text-red-600' : 'text-orange-600'; ?>">
                        <?php echo $m->diff >= 0 ? '+' : ''; ?>৳<?php echo number_format($m->diff, 2); ?>
                    </td>
                    <td class="px-4 py-3 max-w-md">
                        <div class="font-semibold text-gray-800 text-xs mb-1"><?php echo htmlspecialchars($m->reason_title); ?></div>
                        <div class="text-xs text-gray-500 leading-relaxed"><?php echo htmlspecialchars($m->reason_detail); ?></div>
                        <div class="text-xs text-indigo-600 mt-1.5">
                            <i class="fas fa-wrench mr-1"></i>Reconcile by setting the stored balance to the true balance
                            shown above — recomputed live as opening balance + all ledger debits − credits.
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <form method="POST" onsubmit="return confirm('Set <?php echo addslashes($m->name); ?>\'s stored balance from ৳<?php echo number_format($m->current_balance, 2); ?> to ৳<?php echo number_format($m->true_balance, 2); ?>?\n\nThis is fully reversible from the history below.');">
                            <input type="hidden" name="action" value="reconcile">
                            <input type="hidden" name="customer_id" value="<?php echo (int)$m->id; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <button type="submit"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded-lg hover:bg-indigo-700 transition-colors cursor-pointer whitespace-nowrap">
                                <i class="fas fa-check"></i> Reconcile
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ Reconciliation History ═══════════════════════════════════════ -->
    <h2 class="text-base font-bold text-gray-800 mb-2 flex items-center gap-2">
        <i class="fas fa-clock-rotate-left text-gray-500"></i> Reconciliation History
    </h2>
    <p class="text-xs text-gray-500 mb-3">
        Every correction ever made on this page, in both directions. An active reconciliation can be undone with
        "Un-reconcile" — it restores the exact stored balance from before that correction. A reverted row is a
        closed record; if the account needs fixing again, it'll reappear in the mismatch list above and can be
        reconciled fresh (creating a new history row).
    </p>

    <?php if (empty($history)): ?>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-8 text-center">
        <i class="fas fa-inbox text-3xl text-gray-300 mb-2"></i>
        <p class="text-sm text-gray-400">No reconciliations have been made yet.</p>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-200 text-left">
                    <th class="px-4 py-3 font-semibold text-gray-500 uppercase text-xs tracking-wide">Customer</th>
                    <th class="px-4 py-3 font-semibold text-gray-500 uppercase text-xs tracking-wide">Change</th>
                    <th class="px-4 py-3 font-semibold text-gray-500 uppercase text-xs tracking-wide">Reconciled</th>
                    <th class="px-4 py-3 font-semibold text-gray-500 uppercase text-xs tracking-wide">Status</th>
                    <th class="px-4 py-3 font-semibold text-gray-500 uppercase text-xs tracking-wide text-center">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($history as $h): ?>
                <tr class="hover:bg-gray-50 align-top">
                    <td class="px-4 py-3">
                        <a href="../cr/customer_ledger.php?customer_id=<?php echo (int)$h->customer_id; ?>" class="font-semibold text-blue-700 hover:underline">
                            <?php echo htmlspecialchars($h->customer_name); ?>
                        </a>
                        <div class="text-xs text-gray-400">#<?php echo (int)$h->customer_id; ?></div>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs">
                        ৳<?php echo number_format((float)$h->old_balance, 2); ?>
                        <i class="fas fa-arrow-right text-gray-300 mx-1"></i>
                        ৳<?php echo number_format((float)$h->new_balance, 2); ?>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">
                        <?php echo date('d M Y, g:i A', strtotime($h->reconciled_at)); ?>
                        <div class="text-gray-400">by <?php echo htmlspecialchars($h->reconciled_by_name ?? 'system'); ?></div>
                    </td>
                    <td class="px-4 py-3">
                        <?php if ($h->status === 'active'): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700">
                            <i class="fas fa-check-circle"></i> Active
                        </span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600" title="Reverted <?php echo htmlspecialchars(date('d M Y, g:i A', strtotime($h->reverted_at))); ?> by <?php echo htmlspecialchars($h->reverted_by_name ?? 'system'); ?>">
                            <i class="fas fa-rotate-left"></i> Reverted
                        </span>
                        <div class="text-[11px] text-gray-400 mt-0.5">
                            <?php echo date('d M Y, g:i A', strtotime($h->reverted_at)); ?> by <?php echo htmlspecialchars($h->reverted_by_name ?? 'system'); ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($h->status === 'active'): ?>
                        <form method="POST" onsubmit="return confirm('Undo this reconciliation for <?php echo addslashes($h->customer_name); ?>?\n\nThis will restore their stored balance back to ৳<?php echo number_format((float)$h->old_balance, 2); ?> (undoing the change to ৳<?php echo number_format((float)$h->new_balance, 2); ?> made on <?php echo addslashes(date('d M Y', strtotime($h->reconciled_at))); ?>).');">
                            <input type="hidden" name="action" value="unreconcile">
                            <input type="hidden" name="history_id" value="<?php echo (int)$h->id; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <button type="submit"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-gray-100 text-gray-700 border border-gray-300 text-xs font-semibold rounded-lg hover:bg-red-50 hover:border-red-300 hover:text-red-700 transition-colors cursor-pointer whitespace-nowrap">
                                <i class="fas fa-rotate-left"></i> Un-reconcile
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="text-xs text-gray-300">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php require_once '../templates/footer.php'; ?>

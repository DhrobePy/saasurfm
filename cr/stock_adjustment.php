<?php
require_once '../core/init.php';

// Create is open to Accounts/Sales; approval is gated by the can_approve action below.
$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra',
                  'sales-srg', 'sales-demra', 'sales-other'];
// One tab with Returns: accept an explicit stock_adjustment grant OR the returns grant,
// so existing users whose whitelist predates this page aren't locked out.
if (!userHasPageGrant('credit_sales', 'stock_adjustment') && !userHasPageGrant('credit_sales', 'returns')) {
    restrict_access($allowed_roles, 'credit_sales', 'stock_adjustment');
}

global $db;
$currentUser = getCurrentUser();
$user_id     = (int)($currentUser['id'] ?? 0);
$is_superadmin = ($currentUser['role'] ?? '') === 'Superadmin';
$pageTitle   = 'Stock Adjustments';
$error = null; $success = null;

// Feature #7: approve = anyone with can_approve, NEVER the creator.
$can_approve = userCanPageAction('credit_sales', 'stock_adjustment', 'can_approve');

ensureStockAdjustmentsTable();

/* ─── POST: create ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_adjustment') {
    try {
        $variant_id = (int)($_POST['variant_id'] ?? 0);
        $branch_id  = (int)($_POST['branch_id'] ?? 0);
        $direction  = in_array($_POST['direction'] ?? '', ['increase','decrease']) ? $_POST['direction'] : '';
        $quantity   = (float)($_POST['quantity'] ?? 0);
        $unit_value = (float)($_POST['unit_value'] ?? 0);
        $reason     = trim($_POST['reason'] ?? '');
        $offset_account_id = (int)($_POST['offset_account_id'] ?? 0);
        $notes      = trim($_POST['notes'] ?? '');

        if (!$variant_id || !$branch_id) throw new Exception('Select a product variant and branch.');
        if ($direction === '')           throw new Exception('Choose increase or decrease.');
        if ($quantity <= 0)              throw new Exception('Quantity must be greater than zero.');
        if ($unit_value < 0)             throw new Exception('Unit value cannot be negative.');
        if (!$offset_account_id)         throw new Exception('Select the offset account (loss/gain).');
        if ($reason === '')              throw new Exception('A reason is required.');

        $total_value = round($quantity * $unit_value, 2);

        // Adjustment number: ADJ-YYYYMMDD-####
        $adj_prefix = date('Ymd');
        $last = $db->query("SELECT adjustment_number FROM cr_stock_adjustments WHERE adjustment_number LIKE ? ORDER BY id DESC LIMIT 1",
                           ["ADJ-{$adj_prefix}-%"])->first();
        $seq = $last ? ((int)substr($last->adjustment_number, -4) + 1) : 1;
        $adj_number = sprintf("ADJ-%s-%04d", $adj_prefix, $seq);

        $adj_id = $db->insert('cr_stock_adjustments', [
            'adjustment_number'  => $adj_number,
            'variant_id'         => $variant_id,
            'branch_id'          => $branch_id,
            'direction'          => $direction,
            'quantity'           => $quantity,
            'unit_value'         => $unit_value,
            'total_value'        => $total_value,
            'reason'             => $reason,
            'offset_account_id'  => $offset_account_id,
            'status'             => 'pending',
            'created_by_user_id' => $user_id,
            'notes'              => $notes ?: null,
        ]);
        auditLog('cr_stock_adjustments', 'created',
            "Stock adjustment {$adj_number} ({$direction} {$quantity}, ৳" . number_format($total_value,2) . ") created by "
            . ($currentUser['display_name'] ?? 'user') . " — awaiting approval", ['adjustment_id' => $adj_id]);
        $success = "Adjustment {$adj_number} recorded — awaiting approval by another authorised user (you cannot approve your own).";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/* ─── POST: approve / reject ────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['approve_adjustment','reject_adjustment'])) {
    if (!$can_approve) {
        $error = 'You do not have permission to approve stock adjustments.';
    } else {
        try {
            $adj_id = (int)($_POST['adjustment_id'] ?? 0);
            $adj = $db->query("SELECT * FROM cr_stock_adjustments WHERE id = ? AND status = 'pending'", [$adj_id])->first();
            if (!$adj) throw new Exception('Adjustment not found or already processed.');

            // Feature #7: no self-approval.
            if ((int)$adj->created_by_user_id === $user_id) {
                throw new Exception('You created this adjustment — a different authorised user must approve or reject it.');
            }

            if (($_POST['action']) === 'reject_adjustment') {
                $db->query("UPDATE cr_stock_adjustments SET status='rejected', approved_by_user_id=?, approved_at=NOW() WHERE id=?",
                           [$user_id, $adj_id]);
                auditLog('cr_stock_adjustments', 'rejected', "Adjustment {$adj->adjustment_number} rejected", ['adjustment_id' => $adj_id]);
                $success = "Adjustment {$adj->adjustment_number} rejected.";
            } else {
                $pdo = $db->getPdo();
                $pdo->beginTransaction();

                // 1. Apply the inventory delta
                $delta   = $adj->direction === 'increase' ? (float)$adj->quantity : -(float)$adj->quantity;
                $initial = $adj->direction === 'increase' ? (float)$adj->quantity : 0;
                $db->query(
                    "INSERT INTO inventory (variant_id, branch_id, quantity) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE quantity = GREATEST(0, quantity + ?)",
                    [(int)$adj->variant_id, (int)$adj->branch_id, $initial, $delta]
                );

                // 2. Post the balancing journal entry
                $inv_acct = getOrCreateInventoryAccount();
                $off_acct = (int)$adj->offset_account_id;
                $val      = (float)$adj->total_value;
                $journal_id = null;
                if ($inv_acct && $off_acct && $val > 0) {
                    $db->query(
                        "INSERT INTO journal_entries (uuid, transaction_date, description, related_document_type, related_document_id, created_by_user_id)
                         VALUES (UUID(), CURDATE(), ?, 'stock_adjustment', ?, ?)",
                        ["Stock adjustment {$adj->adjustment_number} ({$adj->direction})", $adj_id, $user_id]
                    );
                    $journal_id = (int)$db->getPdo()->lastInsertId();
                    // decrease (loss): DR offset / CR inventory ; increase (gain): DR inventory / CR offset
                    [$dr_acct, $cr_acct] = $adj->direction === 'decrease' ? [$off_acct, $inv_acct] : [$inv_acct, $off_acct];
                    $db->query("INSERT INTO transaction_lines (journal_entry_id, account_id, debit_amount, credit_amount) VALUES (?, ?, ?, 0)", [$journal_id, $dr_acct, $val]);
                    $db->query("INSERT INTO transaction_lines (journal_entry_id, account_id, debit_amount, credit_amount) VALUES (?, ?, 0, ?)", [$journal_id, $cr_acct, $val]);
                }

                $db->query("UPDATE cr_stock_adjustments SET status='approved', approved_by_user_id=?, approved_at=NOW(), journal_entry_id=? WHERE id=?",
                           [$user_id, $journal_id, $adj_id]);
                $pdo->commit();

                auditLog('cr_stock_adjustments', 'approved',
                    "Adjustment {$adj->adjustment_number} approved — inventory {$adj->direction} {$adj->quantity}, journal #".($journal_id ?? 'n/a'),
                    ['adjustment_id' => $adj_id]);
                $success = "Adjustment {$adj->adjustment_number} approved — inventory updated and journal posted.";
            }
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

/* ─── POST: delete (Superadmin, pending/rejected only) → Recycle Bin ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_adjustment' && $is_superadmin) {
    try {
        $adj_id = (int)($_POST['adjustment_id'] ?? 0);
        $adj = $db->query("SELECT * FROM cr_stock_adjustments WHERE id = ?", [$adj_id])->first();
        if (!$adj) throw new Exception('Adjustment not found.');
        if ($adj->status === 'approved') throw new Exception('Approved adjustments cannot be deleted (they moved stock & posted accounting). Reverse via a new opposite adjustment.');
        ensureRecycleBinTables();
        $batch = recycleBegin('stock_adjustment', "Stock adjustment {$adj->adjustment_number} ({$adj->status})");
        recycleArchiveDelete($batch, 'cr_stock_adjustments', 'id', $adj_id);
        recycleFinalize($batch);
        $success = "Adjustment {$adj->adjustment_number} moved to Recycle Bin (batch #{$batch}).";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/* ─── Data for form + lists ─────────────────────────────────── */
$variants = $db->query(
    "SELECT pv.id, p.base_name, pv.weight_variant, pv.grade, pv.sku
     FROM product_variants pv JOIN products p ON pv.product_id = p.id
     ORDER BY p.base_name, pv.weight_variant"
)->results();
$branches = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name")->results();
$offset_accounts = $db->query(
    "SELECT id, name, account_type_group FROM chart_of_accounts
     WHERE status = 'active' AND account_type_group IN ('Expense','Cost of Goods Sold','Other Income','Revenue')
     ORDER BY account_type_group, name"
)->results();

$pending = $db->query(
    "SELECT sa.*, p.base_name, pv.weight_variant, pv.grade, b.name AS branch_name, u.display_name AS maker
     FROM cr_stock_adjustments sa
     JOIN product_variants pv ON pv.id = sa.variant_id
     JOIN products p ON p.id = pv.product_id
     LEFT JOIN branches b ON b.id = sa.branch_id
     LEFT JOIN users u ON u.id = sa.created_by_user_id
     WHERE sa.status = 'pending' ORDER BY sa.created_at ASC"
)->results();
$recent = $db->query(
    "SELECT sa.*, p.base_name, pv.weight_variant, b.name AS branch_name,
            u.display_name AS maker, au.display_name AS approver
     FROM cr_stock_adjustments sa
     JOIN product_variants pv ON pv.id = sa.variant_id
     JOIN products p ON p.id = pv.product_id
     LEFT JOIN branches b ON b.id = sa.branch_id
     LEFT JOIN users u ON u.id = sa.created_by_user_id
     LEFT JOIN users au ON au.id = sa.approved_by_user_id
     WHERE sa.status != 'pending' ORDER BY sa.updated_at DESC LIMIT 40"
)->results();

require_once '../templates/header.php';
$badge = ['pending'=>'bg-amber-100 text-amber-800','approved'=>'bg-green-100 text-green-700','rejected'=>'bg-red-100 text-red-700'];
?>

<div class="max-w-screen-xl mx-auto px-4 sm:px-6 py-6">

<!-- Shared sub-tabs: Returns | Stock Adjustments (Feature #7 one-tab) -->
<div class="mb-5 flex items-center gap-2 border-b border-gray-200">
    <a href="returns.php" class="px-4 py-2 text-sm font-semibold text-gray-500 hover:text-gray-800 border-b-2 border-transparent">
        <i class="fas fa-undo-alt mr-1"></i>Goods Returns
    </a>
    <a href="stock_adjustment.php" class="px-4 py-2 text-sm font-semibold text-orange-600 border-b-2 border-orange-500">
        <i class="fas fa-sliders-h mr-1"></i>Stock Adjustments
    </a>
</div>

<?php if ($error): ?>
<div class="mb-4 p-4 bg-red-50 border border-red-300 rounded-lg text-red-800 text-sm"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="mb-4 p-4 bg-green-50 border border-green-300 rounded-lg text-green-800 text-sm"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Create form -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-md p-5">
            <h2 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-plus-circle text-orange-500 mr-2"></i>New Adjustment</h2>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="create_adjustment">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Product Variant *</label>
                    <select name="variant_id" required class="w-full px-3 py-2 border rounded-lg text-sm">
                        <option value="">Select…</option>
                        <?php foreach ($variants as $v): ?>
                        <option value="<?php echo (int)$v->id; ?>"><?php echo htmlspecialchars($v->base_name . ' — ' . $v->weight_variant . ' / ' . $v->grade); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Branch *</label>
                    <select name="branch_id" required class="w-full px-3 py-2 border rounded-lg text-sm">
                        <option value="">Select…</option>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?php echo (int)$b->id; ?>"><?php echo htmlspecialchars($b->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Direction *</label>
                        <select name="direction" required class="w-full px-3 py-2 border rounded-lg text-sm">
                            <option value="decrease">Decrease (loss)</option>
                            <option value="increase">Increase (gain)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Quantity *</label>
                        <input type="number" name="quantity" step="0.001" min="0" required class="w-full px-3 py-2 border rounded-lg text-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Unit Value (৳ cost/unit) *</label>
                    <input type="number" name="unit_value" step="0.01" min="0" required class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="cost basis per unit">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Offset Account (loss / gain) *</label>
                    <select name="offset_account_id" required class="w-full px-3 py-2 border rounded-lg text-sm">
                        <option value="">Select account…</option>
                        <?php foreach ($offset_accounts as $a): ?>
                        <option value="<?php echo (int)$a->id; ?>"><?php echo htmlspecialchars($a->name . ' (' . $a->account_type_group . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-[10px] text-gray-400 mt-1">Decrease → this account is debited (loss). Increase → this account is credited (gain).</p>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Reason *</label>
                    <input type="text" name="reason" required maxlength="255" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="wastage, spoilage, recount…">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="w-full px-3 py-2 border rounded-lg text-sm"></textarea>
                </div>
                <button type="submit" class="w-full px-4 py-2 bg-orange-600 text-white text-sm font-semibold rounded-lg hover:bg-orange-700">
                    <i class="fas fa-paper-plane mr-1"></i>Submit for Approval
                </button>
            </form>
        </div>
    </div>

    <!-- Pending + recent -->
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-white rounded-xl shadow-md overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                <h2 class="font-bold text-gray-800"><i class="fas fa-hourglass-half text-amber-500 mr-2"></i>Pending Approval</h2>
                <span class="text-xs px-2 py-0.5 rounded-full <?php echo count($pending) ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-700'; ?>"><?php echo count($pending); ?></span>
            </div>
            <?php if (empty($pending)): ?>
            <div class="p-8 text-center text-gray-400 text-sm">Nothing awaiting approval.</div>
            <?php else: ?>
            <div class="divide-y divide-gray-100">
                <?php foreach ($pending as $a):
                    $is_mine = (int)$a->created_by_user_id === $user_id; ?>
                <div class="px-5 py-3 flex flex-wrap items-center gap-3">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-800">
                            <?php echo htmlspecialchars($a->adjustment_number); ?> —
                            <span class="<?php echo $a->direction === 'increase' ? 'text-green-700' : 'text-red-700'; ?>">
                                <?php echo $a->direction === 'increase' ? '+' : '−'; ?><?php echo rtrim(rtrim(number_format((float)$a->quantity,3),'0'),'.'); ?>
                            </span>
                            <?php echo htmlspecialchars($a->base_name . ' ' . $a->weight_variant); ?>
                            · <?php echo htmlspecialchars($a->branch_name ?? ''); ?>
                        </p>
                        <p class="text-xs text-gray-500">৳<?php echo number_format((float)$a->total_value,2); ?> · <?php echo htmlspecialchars($a->reason ?? ''); ?> · by <?php echo htmlspecialchars($a->maker ?? ''); ?></p>
                    </div>
                    <?php if ($can_approve && !$is_mine): ?>
                    <form method="POST" onsubmit="return confirm('Approve <?php echo htmlspecialchars($a->adjustment_number); ?>? This moves stock and posts a journal entry.');">
                        <input type="hidden" name="action" value="approve_adjustment">
                        <input type="hidden" name="adjustment_id" value="<?php echo (int)$a->id; ?>">
                        <button class="px-3 py-1.5 bg-green-600 text-white rounded-lg text-xs font-bold hover:bg-green-700">Approve</button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Reject <?php echo htmlspecialchars($a->adjustment_number); ?>?');">
                        <input type="hidden" name="action" value="reject_adjustment">
                        <input type="hidden" name="adjustment_id" value="<?php echo (int)$a->id; ?>">
                        <button class="px-3 py-1.5 border border-red-400 text-red-600 rounded-lg text-xs font-bold hover:bg-red-50">Reject</button>
                    </form>
                    <?php elseif ($is_mine): ?>
                    <span class="text-[11px] text-gray-400 px-2 py-1">Your request</span>
                    <?php else: ?>
                    <span class="text-[11px] text-gray-400 px-2 py-1">Awaiting approver</span>
                    <?php endif; ?>
                    <?php if ($is_superadmin): ?>
                    <form method="POST" onsubmit="return confirm('Move to Recycle Bin?');">
                        <input type="hidden" name="action" value="delete_adjustment">
                        <input type="hidden" name="adjustment_id" value="<?php echo (int)$a->id; ?>">
                        <button class="px-2 py-1.5 text-gray-300 hover:text-red-500" title="Delete"><i class="fas fa-trash text-xs"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-xl shadow-md overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-bold text-gray-800"><i class="fas fa-history text-gray-400 mr-2"></i>Recent</h2></div>
            <?php if (empty($recent)): ?>
            <div class="p-8 text-center text-gray-400 text-sm">No processed adjustments yet.</div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm divide-y divide-gray-200">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Value</th>
                        <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Maker → Approver</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($recent as $a): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs"><?php echo htmlspecialchars($a->adjustment_number); ?></td>
                            <td class="px-4 py-2 text-xs"><?php echo htmlspecialchars($a->base_name . ' ' . $a->weight_variant . ' · ' . ($a->branch_name ?? '')); ?></td>
                            <td class="px-4 py-2 text-right <?php echo $a->direction === 'increase' ? 'text-green-700' : 'text-red-700'; ?>">
                                <?php echo $a->direction === 'increase' ? '+' : '−'; ?><?php echo rtrim(rtrim(number_format((float)$a->quantity,3),'0'),'.'); ?>
                            </td>
                            <td class="px-4 py-2 text-right">৳<?php echo number_format((float)$a->total_value,2); ?></td>
                            <td class="px-4 py-2 text-center"><span class="px-2 py-0.5 rounded-full text-[11px] font-bold <?php echo $badge[$a->status] ?? ''; ?>"><?php echo strtoupper($a->status); ?></span></td>
                            <td class="px-4 py-2 text-xs text-gray-500"><?php echo htmlspecialchars(($a->maker ?? '') . ' → ' . ($a->approver ?? '—')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<?php require_once '../templates/footer.php'; ?>

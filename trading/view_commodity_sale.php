<?php
/**
 * View Commodity Sale — read-only detail: full sale info, the compound
 * journal entry it posted, payment history, and (gated) Edit/Delete actions.
 * Linked from the Sale # in commodity_sale.php's Recent Sales table.
 */
require_once '../core/init.php';

restrict_access(['Superadmin', 'admin'], 'trading', 'commodity_sale');

global $db;
$currentUser = getCurrentUser();
$user_id     = $currentUser['id'] ?? null;
$is_admin    = in_array($currentUser['role'] ?? '', ['Superadmin', 'admin'], true);
$can_delete  = $is_admin || userCanPageAction('trading', 'commodity_sale', 'can_delete');
$can_edit    = $is_admin || userCanPageAction('trading', 'commodity_sale', 'can_edit');
$pageTitle   = 'View Commodity Sale';

ensureCommoditySaleEditsTable();

$sale_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$sale = $db->query(
    "SELECT cs.*, c.name AS customer_name, c.phone_number, c.business_name, c.business_partner_id,
            pc.name AS commodity_name, pc.unit, b.name AS branch_name,
            maker.display_name AS created_by_name, approver.display_name AS approved_by_name,
            po.po_number AS source_po_number, po.supplier_name AS source_po_supplier
     FROM commodity_sales cs
     JOIN customers c ON c.id = cs.customer_id
     JOIN purchase_commodities pc ON pc.id = cs.commodity_id
     JOIN branches b ON b.id = cs.branch_id
     LEFT JOIN purchase_orders_adnan po ON po.id = cs.source_purchase_order_id
     LEFT JOIN users maker ON maker.id = cs.created_by_user_id
     LEFT JOIN users approver ON approver.id = cs.approved_by_user_id
     WHERE cs.id = ?",
    [$sale_id]
)->first();

if (!$sale) {
    // This id may have been superseded by an edit — follow the chain forward
    // to the latest live sale rather than dead-ending an old bookmark/link.
    $cursor = $sale_id;
    $latest = null;
    for ($hop = 0; $hop < 20; $hop++) {
        $next = $db->query(
            "SELECT new_sale_id FROM commodity_sale_edits WHERE old_sale_id = ? AND status = 'approved' AND new_sale_id IS NOT NULL ORDER BY id DESC LIMIT 1",
            [$cursor]
        )->first();
        if (!$next || !$next->new_sale_id) break;
        $latest = (int)$next->new_sale_id;
        $cursor = $latest;
    }
    if ($latest) {
        $_SESSION['success_flash'] = "That sale was corrected — showing the latest version.";
        header('Location: view_commodity_sale.php?id=' . $latest);
        exit();
    }
    require_once '../templates/header.php';
    echo '<div class="max-w-screen-md mx-auto px-4 py-10 text-center"><p class="text-gray-500">Commodity sale not found.</p><a href="commodity_sale.php" class="text-rose-600 hover:underline">&larr; Back to Commodity Sale</a></div>';
    require_once '../templates/footer.php';
    exit();
}

// ── Edit lineage (walk backward to build the full timeline) ───────────────
$edit_chain = [];
$cursor = $sale_id;
for ($hop = 0; $hop < 20; $hop++) {
    $edit = $db->query(
        "SELECT cse.*, req.display_name AS requested_by_name, dec.display_name AS decided_by_name
         FROM commodity_sale_edits cse
         LEFT JOIN users req ON req.id = cse.requested_by_user_id
         LEFT JOIN users dec ON dec.id = cse.decided_by_user_id
         WHERE cse.new_sale_id = ? AND cse.status = 'approved' ORDER BY cse.id DESC LIMIT 1",
        [$cursor]
    )->first();
    if (!$edit) break;
    $edit_chain[] = $edit;
    $cursor = (int)$edit->old_sale_id;
}
$edit_chain = array_reverse($edit_chain); // oldest first, for a top-to-bottom timeline

// A correction currently awaiting approval against THIS live sale, if any.
$pending_edit = $db->query(
    "SELECT cse.*, req.display_name AS requested_by_name
     FROM commodity_sale_edits cse LEFT JOIN users req ON req.id = cse.requested_by_user_id
     WHERE cse.old_sale_id = ? AND cse.status = 'pending_approval' ORDER BY cse.id DESC LIMIT 1",
    [$sale_id]
)->first();
$pending_edit_req = null;
if ($pending_edit) {
    $pending_edit_req = $db->query(
        "SELECT id FROM cr_pending_requests WHERE status = 'pending' AND request_type = 'commodity_sale_edit' AND payload LIKE ?",
        ['%"edit_row_id":' . (int)$pending_edit->id . '%']
    )->first();
}

$margin = (float)$sale->total_amount - (float)$sale->cogs_amount;
$margin_pct = (float)$sale->total_amount > 0 ? ($margin / (float)$sale->total_amount) * 100 : 0;
$locked = (float)$sale->amount_paid > 0.01;

$journal_lines = [];
if (!empty($sale->journal_entry_id)) {
    $journal_lines = $db->query(
        "SELECT tl.*, coa.name AS account_name, coa.account_number
         FROM transaction_lines tl JOIN chart_of_accounts coa ON coa.id = tl.account_id
         WHERE tl.journal_entry_id = ? ORDER BY tl.id ASC",
        [$sale->journal_entry_id]
    )->results();
}

$payments = $db->query(
    "SELECT csp.*, u.display_name AS collected_by
     FROM commodity_sale_payments csp LEFT JOIN users u ON u.id = csp.created_by_user_id
     WHERE csp.sale_id = ? ORDER BY csp.created_at DESC",
    [$sale_id]
)->results();

$csrf = $_SESSION['csrf_token'] ?? '';
$flash = $_SESSION['success_flash'] ?? null; unset($_SESSION['success_flash']);

require_once '../templates/header.php';
?>
<div class="max-w-screen-lg mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
            <a href="commodity_sale.php" class="text-xs text-gray-500 hover:text-rose-600"><i class="fas fa-arrow-left mr-1"></i>Back to Commodity Sale</a>
            <h1 class="text-2xl font-bold text-gray-900 mt-1">
                <i class="fas fa-file-invoice text-rose-600 mr-2"></i><?php echo htmlspecialchars($sale->sale_number); ?>
                <?php if ($sale->stock_overridden): ?><span class="ml-2 align-middle text-[11px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-800"><i class="fas fa-triangle-exclamation mr-1"></i>Stock Override</span><?php endif; ?>
            </h1>
        </div>
        <div class="flex gap-2">
            <a href="commodity_invoice.php?id=<?php echo (int)$sale->id; ?>" target="_blank" class="px-3 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"><i class="fas fa-file-invoice mr-1"></i>Invoice</a>
            <a href="commodity_gate_pass.php?id=<?php echo (int)$sale->id; ?>" target="_blank" class="px-3 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"><i class="fas fa-qrcode mr-1"></i>Gate Pass</a>
            <?php if ((float)$sale->balance_due > 0.01): ?>
            <a href="collect_commodity_payment.php?sale_id=<?php echo (int)$sale->id; ?>" class="px-3 py-2 text-sm bg-rose-600 text-white rounded-lg hover:bg-rose-700"><i class="fas fa-hand-holding-dollar mr-1"></i>Collect Payment</a>
            <?php endif; ?>
            <?php if ($can_edit): ?>
                <?php if (!$locked && !$pending_edit): ?>
                <a href="edit_commodity_sale.php?id=<?php echo (int)$sale->id; ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"><i class="fas fa-pen mr-1"></i>Edit</a>
                <?php else: ?>
                <span class="px-3 py-2 text-sm border border-gray-200 rounded-lg text-gray-300" title="<?php echo $pending_edit ? 'An edit is already pending approval for this sale.' : 'Reverse the payment(s) first to edit this sale.'; ?>"><i class="fas fa-lock mr-1"></i>Edit</span>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($can_delete): ?>
                <?php if (!$locked && !$pending_edit): ?>
                <button type="button" onclick="vcsDeleteSale()" class="px-3 py-2 text-sm border-2 border-red-400 text-red-600 rounded-lg hover:bg-red-50"><i class="fas fa-trash mr-1"></i>Delete</button>
                <?php else: ?>
                <span class="px-3 py-2 text-sm border border-gray-200 rounded-lg text-gray-300" title="<?php echo $pending_edit ? 'An edit is already pending approval for this sale.' : 'Reverse the payment(s) first to delete this sale.'; ?>"><i class="fas fa-lock mr-1"></i>Delete</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash): ?><div class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-2.5 text-sm text-green-800"><i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <?php if ($pending_edit): ?>
    <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 flex items-center justify-between flex-wrap gap-2">
        <span><i class="fas fa-hourglass-half mr-1"></i>A correction to this sale is waiting for approval (requested by <?php echo htmlspecialchars($pending_edit->requested_by_name ?? 'staff'); ?>).</span>
        <?php if ($pending_edit_req && ($is_admin || userCanPageAction('trading', 'commodity_sale', 'can_edit'))): ?>
        <a href="edit_commodity_sale.php?id=<?php echo (int)$sale->id; ?>&pending_req=<?php echo (int)$pending_edit_req->id; ?>" class="text-xs font-semibold underline">Review it →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Sale info -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 p-5">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Sale Details</h2>
            <dl class="grid grid-cols-2 gap-y-3 text-sm">
                <div><dt class="text-gray-500 text-xs">Customer</dt><dd class="font-medium"><?php echo htmlspecialchars($sale->customer_name); ?><?php if ($sale->business_partner_id): ?> <span class="text-[10px] px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded-full">Business Partner</span><?php endif; ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Phone</dt><dd class="font-medium"><?php echo htmlspecialchars($sale->phone_number ?? '—'); ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Commodity</dt><dd class="font-medium"><?php echo htmlspecialchars($sale->commodity_name); ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Origin</dt><dd class="font-medium"><?php echo $sale->origin !== '' ? htmlspecialchars($sale->origin) : '<span class="text-gray-300">Not tracked / mixed</span>'; ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Branch / Warehouse / Dock</dt><dd class="font-medium"><?php echo htmlspecialchars($sale->branch_name); ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Sale Date</dt><dd class="font-medium"><?php echo date('d M Y', strtotime($sale->sale_date)); ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Quantity</dt><dd class="font-medium"><?php echo number_format((float)$sale->quantity, 3); ?> <?php echo htmlspecialchars($sale->unit); ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Unit Price</dt><dd class="font-medium">৳<?php echo number_format((float)$sale->unit_price, 4); ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Status</dt><dd class="font-medium capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $sale->status)); ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Source Purchase Order</dt><dd class="font-medium">
                    <?php if ($sale->source_po_number): ?>
                    <a href="../purchase/purchase_adnan_view_po.php?id=<?php echo (int)$sale->source_purchase_order_id; ?>" class="text-rose-600 hover:underline"><?php echo htmlspecialchars($sale->source_po_number); ?></a>
                    <span class="text-gray-400 text-xs">(<?php echo htmlspecialchars($sale->source_po_supplier); ?>)</span>
                    <?php else: ?><span class="text-gray-300">Not linked</span><?php endif; ?>
                </dd></div>
                <div class="col-span-2"><dt class="text-gray-500 text-xs">Notes</dt><dd class="font-medium"><?php echo $sale->notes ? htmlspecialchars($sale->notes) : '<span class="text-gray-300">—</span>'; ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Created By</dt><dd class="font-medium"><?php echo htmlspecialchars($sale->created_by_name ?? '—'); ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Approved/Posted By</dt><dd class="font-medium"><?php echo htmlspecialchars($sale->approved_by_name ?? '—'); ?></dd></div>
                <div><dt class="text-gray-500 text-xs">Created At</dt><dd class="font-medium"><?php echo date('d M Y, g:i A', strtotime($sale->created_at)); ?></dd></div>
            </dl>
        </div>

        <!-- Financials -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Financials</h2>
            <dl class="space-y-2.5 text-sm">
                <div class="flex justify-between"><dt class="text-gray-500">Total Amount</dt><dd class="font-bold text-blue-700">৳<?php echo number_format((float)$sale->total_amount, 2); ?></dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">COGS</dt><dd class="font-semibold text-gray-700">৳<?php echo number_format((float)$sale->cogs_amount, 2); ?></dd></div>
                <div class="flex justify-between border-t pt-2"><dt class="text-gray-500">Margin</dt><dd class="font-bold <?php echo $margin >= 0 ? 'text-green-700' : 'text-red-700'; ?>">৳<?php echo number_format($margin, 2); ?> (<?php echo number_format($margin_pct, 1); ?>%)</dd></div>
                <div class="flex justify-between border-t pt-2"><dt class="text-gray-500">Advance Paid</dt><dd class="font-medium">৳<?php echo number_format((float)$sale->advance_paid, 2); ?></dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Amount Paid</dt><dd class="font-medium">৳<?php echo number_format((float)$sale->amount_paid, 2); ?></dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Balance Due</dt><dd class="font-bold <?php echo (float)$sale->balance_due > 0.01 ? 'text-amber-700' : 'text-gray-400'; ?>">৳<?php echo number_format((float)$sale->balance_due, 2); ?></dd></div>
            </dl>
        </div>
    </div>

    <!-- Journal entry -->
    <div class="mt-6 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-700">Journal Entry Posted</h2></div>
        <div class="overflow-x-auto">
        <?php if (!empty($journal_lines)): ?>
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b"><tr>
                <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Account</th>
                <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">Debit</th>
                <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-500">Credit</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($journal_lines as $jl): ?>
                <tr>
                    <td class="px-4 py-2"><?php echo htmlspecialchars($jl->account_name); ?> <span class="text-gray-400 text-xs">(<?php echo htmlspecialchars($jl->account_number ?? ''); ?>)</span></td>
                    <td class="px-4 py-2 text-right"><?php echo (float)$jl->debit_amount > 0 ? '৳' . number_format((float)$jl->debit_amount, 2) : '—'; ?></td>
                    <td class="px-4 py-2 text-right"><?php echo (float)$jl->credit_amount > 0 ? '৳' . number_format((float)$jl->credit_amount, 2) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-6 text-center text-gray-500 text-sm">No journal entry found for this sale.</div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Payment history -->
    <div class="mt-6 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-700">Payment History</h2></div>
        <div class="overflow-x-auto">
        <?php if (!empty($payments)): ?>
        <table class="min-w-full text-xs">
            <thead class="bg-gray-50 border-b"><tr>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Receipt #</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Date</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase text-gray-500">Amount</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Method</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase text-gray-500">Collected By</th>
                <?php if ($can_delete): ?><th class="px-3 py-2 text-center text-[10px] font-semibold uppercase text-gray-500">Action</th><?php endif; ?>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($payments as $ph): ?>
                <tr>
                    <td class="px-3 py-2 font-mono text-rose-600"><?php echo htmlspecialchars($ph->payment_number); ?></td>
                    <td class="px-3 py-2 text-gray-500"><?php echo date('d M Y', strtotime($ph->payment_date)); ?></td>
                    <td class="px-3 py-2 text-right font-semibold">৳<?php echo number_format((float)$ph->amount, 2); ?></td>
                    <td class="px-3 py-2"><?php echo htmlspecialchars($ph->payment_method); ?></td>
                    <td class="px-3 py-2 text-gray-500"><?php echo htmlspecialchars($ph->collected_by ?? '—'); ?></td>
                    <?php if ($can_delete): ?>
                    <td class="px-3 py-2 text-center">
                        <button type="button" onclick="vcsReversePayment(<?php echo (int)$ph->id; ?>, <?php echo htmlspecialchars(json_encode($ph->payment_number), ENT_QUOTES); ?>)"
                                class="px-2 py-1 border-2 border-red-400 text-red-600 rounded-md text-[11px] font-bold hover:bg-red-50">
                            <i class="fas fa-rotate-left mr-1"></i>Reverse
                        </button>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-6 text-center text-gray-500 text-xs">No payments recorded against this sale yet.</div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Timeline -->
    <div class="mt-6 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-700">Timeline</h2></div>
        <div class="p-5">
            <div class="relative pl-6 border-l-2 border-gray-100 space-y-6">

                <div class="relative">
                    <span class="absolute -left-[29px] top-0.5 w-3.5 h-3.5 rounded-full bg-green-500 border-2 border-white"></span>
                    <p class="text-sm font-semibold text-gray-800">
                        Created<?php if (!empty($edit_chain)): ?> as <span class="font-mono text-rose-600"><?php echo htmlspecialchars($edit_chain[0]->old_sale_number); ?></span><?php endif; ?>
                    </p>
                    <p class="text-xs text-gray-400 mt-0.5">
                        <?php echo htmlspecialchars($sale->created_by_name ?? 'staff'); ?>
                        <?php if (!empty($edit_chain)): ?> · <?php echo date('d M Y, g:i A', strtotime($edit_chain[0]->created_at)); ?><?php else: ?> · <?php echo date('d M Y, g:i A', strtotime($sale->created_at)); ?><?php endif; ?>
                    </p>
                </div>

                <?php foreach ($edit_chain as $ec): $diff = json_decode($ec->change_summary, true) ?: []; ?>
                <div class="relative">
                    <span class="absolute -left-[29px] top-0.5 w-3.5 h-3.5 rounded-full bg-amber-500 border-2 border-white"></span>
                    <p class="text-sm font-semibold text-gray-800">
                        Corrected: <span class="font-mono text-gray-500"><?php echo htmlspecialchars($ec->old_sale_number); ?></span>
                        <i class="fas fa-arrow-right text-gray-300 text-xs mx-1"></i>
                        <span class="font-mono text-rose-600"><?php echo htmlspecialchars($ec->new_sale_number); ?></span>
                    </p>
                    <?php if (!empty($diff)): ?>
                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        <?php foreach ($diff as $d): ?>
                        <span class="text-[11px] bg-gray-50 border border-gray-200 rounded-full px-2 py-0.5"><?php echo htmlspecialchars($d['label']); ?>: <span class="text-red-500"><?php echo htmlspecialchars((string)$d['old']); ?></span> → <span class="text-green-700"><?php echo htmlspecialchars((string)$d['new']); ?></span></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($ec->reason): ?><p class="text-xs text-gray-500 mt-1"><em><?php echo htmlspecialchars($ec->reason); ?></em></p><?php endif; ?>
                    <p class="text-xs text-gray-400 mt-1">
                        Requested by <?php echo htmlspecialchars($ec->requested_by_name ?? 'staff'); ?>
                        <?php if ($ec->decided_by_name && $ec->decided_by_user_id != $ec->requested_by_user_id): ?> · approved by <?php echo htmlspecialchars($ec->decided_by_name); ?><?php endif; ?>
                        · <?php echo date('d M Y, g:i A', strtotime($ec->decided_at ?? $ec->created_at)); ?>
                    </p>
                </div>
                <?php endforeach; ?>

                <?php if ($pending_edit): $pdiff = json_decode($pending_edit->change_summary, true) ?: []; ?>
                <div class="relative">
                    <span class="absolute -left-[29px] top-0.5 w-3.5 h-3.5 rounded-full bg-amber-300 border-2 border-white animate-pulse"></span>
                    <p class="text-sm font-semibold text-amber-800">Correction pending approval</p>
                    <?php if (!empty($pdiff)): ?>
                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        <?php foreach ($pdiff as $d): ?>
                        <span class="text-[11px] bg-amber-50 border border-amber-200 rounded-full px-2 py-0.5"><?php echo htmlspecialchars($d['label']); ?>: <span class="text-red-500"><?php echo htmlspecialchars((string)$d['old']); ?></span> → <span class="text-green-700"><?php echo htmlspecialchars((string)$d['new']); ?></span></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <p class="text-xs text-gray-400 mt-1">Requested by <?php echo htmlspecialchars($pending_edit->requested_by_name ?? 'staff'); ?> · <?php echo date('d M Y, g:i A', strtotime($pending_edit->created_at)); ?></p>
                </div>
                <?php endif; ?>

                <div class="relative">
                    <span class="absolute -left-[29px] top-0.5 w-3.5 h-3.5 rounded-full bg-blue-500 border-2 border-white"></span>
                    <p class="text-sm font-semibold text-gray-800">Current: <span class="font-mono text-rose-600"><?php echo htmlspecialchars($sale->sale_number); ?></span></p>
                    <p class="text-xs text-gray-400 mt-0.5">৳<?php echo number_format((float)$sale->total_amount, 2); ?> · <?php echo htmlspecialchars($sale->customer_name); ?></p>
                </div>

            </div>
        </div>
    </div>

</div>
<script>
function vcsDeleteSale() {
    const reason = prompt('Delete/reverse sale <?php echo htmlspecialchars($sale->sale_number, ENT_QUOTES); ?> — this moves it to the Recycle Bin, restores the stock, and reverses the ledger entry. Reason (required):');
    if (reason === null) return;
    if (!reason.trim()) { alert('A reason is required.'); return; }
    const fd = new FormData();
    fd.append('sale_id', <?php echo (int)$sale->id; ?>);
    fd.append('reason', reason.trim());
    fd.append('csrf_token', <?php echo json_encode($csrf); ?>);
    fetch('delete_commodity_sale.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': <?php echo json_encode($csrf); ?> } })
        .then(r => r.json())
        .then(data => {
            if (data.success) { alert(data.message); window.location.href = 'commodity_sale.php'; }
            else { alert('Could not delete: ' + data.message); }
        })
        .catch(() => alert('Network error — please try again.'));
}
function vcsReversePayment(paymentId, paymentNumber) {
    const reason = prompt('Reverse payment ' + paymentNumber + ' — this moves it to the Recycle Bin and puts the sale balance back. Reason (required):');
    if (reason === null) return;
    if (!reason.trim()) { alert('A reason is required.'); return; }
    const fd = new FormData();
    fd.append('payment_id', paymentId);
    fd.append('reason', reason.trim());
    fd.append('csrf_token', <?php echo json_encode($csrf); ?>);
    fetch('delete_commodity_payment.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': <?php echo json_encode($csrf); ?> } })
        .then(r => r.json())
        .then(data => {
            if (data.success) { alert(data.message); location.reload(); }
            else { alert('Could not reverse: ' + data.message); }
        })
        .catch(() => alert('Network error — please try again.'));
}
</script>
<?php require_once '../templates/footer.php'; ?>

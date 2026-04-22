<?php
/**
 * View, Approve, Post, Cancel — Purchase Adjustment Note
 */
require_once __DIR__ . '/../core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle   = "Adjustment Note";
$currentUser = getCurrentUser();
$user_id     = $currentUser['id']   ?? null;
$user_name   = $currentUser['display_name'] ?? 'System User';
$user_role   = $currentUser['role'] ?? '';
$is_admin    = in_array($user_role, ['Superadmin', 'admin']);

$po_manager = new Purchaseadnanmanager();

$note_id = (int)($_GET['id'] ?? 0);
if (!$note_id) {
    redirect('purchase/purchase_adnan_adjustments.php', 'Invalid note ID', 'error');
}

// ── POST: handle admin actions ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'approve':
            $r = $po_manager->approveAdjustmentNote($note_id, $user_id, $user_name);
            break;
        case 'post':
            $r = $po_manager->postAdjustmentNote($note_id, $user_id, $user_name);
            break;
        case 'cancel':
            $reason = trim($_POST['cancel_reason'] ?? '');
            if (!$reason) {
                $_SESSION['error'] = 'Cancellation reason is required.';
                redirect('purchase/purchase_adnan_view_adjustment.php?id=' . $note_id);
            }
            $r = $po_manager->cancelAdjustmentNote($note_id, $reason, $user_id, $user_name);
            break;
        default:
            $r = ['success' => false, 'message' => 'Unknown action.'];
    }

    if ($r['success']) {
        $_SESSION['success'] = $r['message'];
    } else {
        $_SESSION['error'] = $r['message'];
    }
    redirect('purchase/purchase_adnan_view_adjustment.php?id=' . $note_id);
}

// ── Load note ─────────────────────────────────────────────────────────────────
$note = $po_manager->getAdjustmentNote($note_id);
if (!$note) {
    redirect('purchase/purchase_adnan_adjustments.php', 'Adjustment note not found', 'error');
}

$po = $po_manager->getPurchaseOrder($note->purchase_order_id);

$pageTitle = "Adjustment Note " . $note->note_number;

$reason_labels = [
    'over_delivery'          => 'Over-Delivery',
    'under_delivery_closure' => 'Under-Delivery Closure',
    'quality_deduction'      => 'Quality / Weight Deduction',
    'price_dispute'          => 'Price Dispute',
    'return'                 => 'Goods Return',
    'other'                  => 'Other',
];

$status_colors = [
    'draft'     => 'bg-yellow-100 text-yellow-800 border border-yellow-300',
    'approved'  => 'bg-blue-100 text-blue-800 border border-blue-300',
    'posted'    => 'bg-green-100 text-green-800 border border-green-300',
    'cancelled' => 'bg-red-100 text-red-800 border border-red-300',
];

require_once '../templates/header.php';
?>

<div class="w-full px-4 py-6">

    <!-- Header -->
    <div class="flex flex-wrap justify-between items-center mb-6 gap-3">
        <div>
            <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-file-invoice-dollar text-indigo-600"></i>
                <?php echo htmlspecialchars($note->note_number); ?>
            </h2>
            <nav class="text-sm text-gray-600 mt-1">
                <a href="purchase_adnan_index.php" class="hover:text-primary-600">Purchase (Adnan)</a>
                <span class="mx-2">›</span>
                <a href="purchase_adnan_adjustments.php" class="hover:text-primary-600">Adjustment Notes</a>
                <span class="mx-2">›</span>
                <span><?php echo htmlspecialchars($note->note_number); ?></span>
            </nav>
        </div>
        <div class="flex gap-2">
            <a href="purchase_adnan_adjustments.php"
               class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
                <i class="fas fa-arrow-left"></i> All Notes
            </a>
            <?php if ($po): ?>
            <a href="purchase_adnan_view_po.php?id=<?php echo $po->id; ?>"
               class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 flex items-center gap-2">
                <i class="fas fa-file-invoice"></i> View PO #<?php echo $po->po_number; ?>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
    <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg mb-5 flex justify-between">
        <span><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
        <button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
    </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-5 flex justify-between">
        <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
        <button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Left: Note Details -->
        <div class="lg:col-span-2 space-y-6">

            <!-- Type & Status Banner -->
            <div class="flex flex-wrap items-center gap-4 p-4 rounded-lg
                <?php echo $note->note_type === 'debit'
                    ? 'bg-orange-50 border-2 border-orange-300'
                    : 'bg-blue-50 border-2 border-blue-300'; ?>">
                <div class="flex-1">
                    <?php if ($note->note_type === 'debit'): ?>
                        <span class="inline-flex items-center gap-1 text-lg font-bold text-orange-700">
                            <i class="fas fa-arrow-up"></i> Debit Adjustment Note (DAN)
                        </span>
                        <p class="text-sm text-orange-600 mt-1">We owe the supplier more — increases payable amount when posted.</p>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-1 text-lg font-bold text-blue-700">
                            <i class="fas fa-arrow-down"></i> Credit Adjustment Note (CAN)
                        </span>
                        <p class="text-sm text-blue-600 mt-1">Supplier owes us a reduction — decreases payable and adds to supplier credit balance when posted.</p>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="px-4 py-2 rounded-lg text-base font-bold <?php echo $status_colors[$note->status] ?? ''; ?>">
                        <?php echo strtoupper($note->status); ?>
                    </span>
                </div>
            </div>

            <!-- Note Details Card -->
            <div class="bg-white rounded-lg shadow">
                <div class="bg-indigo-700 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold">Note Details</h5>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="space-y-3">
                            <div class="flex">
                                <span class="font-semibold w-44 text-gray-600">Note Number:</span>
                                <span class="font-bold"><?php echo htmlspecialchars($note->note_number); ?></span>
                            </div>
                            <div class="flex">
                                <span class="font-semibold w-44 text-gray-600">Reason:</span>
                                <span><?php echo $reason_labels[$note->reason_type] ?? $note->reason_type; ?></span>
                            </div>
                            <div class="flex">
                                <span class="font-semibold w-44 text-gray-600">Purchase Order:</span>
                                <a href="purchase_adnan_view_po.php?id=<?php echo $note->purchase_order_id; ?>"
                                   class="text-blue-600 hover:underline font-semibold">
                                    PO #<?php echo htmlspecialchars($note->po_number); ?>
                                </a>
                            </div>
                            <div class="flex">
                                <span class="font-semibold w-44 text-gray-600">Supplier:</span>
                                <span><?php echo htmlspecialchars($note->supplier_name ?? 'N/A'); ?></span>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <div class="flex">
                                <span class="font-semibold w-44 text-gray-600">Quantity:</span>
                                <span><?php echo $note->quantity_kg ? number_format($note->quantity_kg, 2) . ' KG' : '—'; ?></span>
                            </div>
                            <div class="flex">
                                <span class="font-semibold w-44 text-gray-600">Unit Price:</span>
                                <span><?php echo $note->unit_price_per_kg ? '৳' . number_format($note->unit_price_per_kg, 4) : '—'; ?></span>
                            </div>
                            <div class="flex">
                                <span class="font-semibold w-44 text-gray-600">Amount:</span>
                                <span class="text-xl font-bold <?php echo $note->note_type === 'debit' ? 'text-orange-700' : 'text-blue-700'; ?>">
                                    ৳<?php echo number_format($note->amount, 2); ?>
                                </span>
                            </div>
                            <div class="flex">
                                <span class="font-semibold w-44 text-gray-600">Created:</span>
                                <span><?php echo date('d M Y H:i', strtotime($note->created_at)); ?>
                                    <span class="text-gray-500 text-sm">by <?php echo htmlspecialchars($note->created_by_name ?? 'N/A'); ?></span>
                                </span>
                            </div>
                            <?php if ($note->approved_at): ?>
                            <div class="flex">
                                <span class="font-semibold w-44 text-gray-600">Approved:</span>
                                <span><?php echo date('d M Y H:i', strtotime($note->approved_at)); ?>
                                    <span class="text-gray-500 text-sm">by <?php echo htmlspecialchars($note->approved_by_name ?? 'N/A'); ?></span>
                                </span>
                            </div>
                            <?php endif; ?>
                            <?php if ($note->posted_at): ?>
                            <div class="flex">
                                <span class="font-semibold w-44 text-gray-600">Posted:</span>
                                <span><?php echo date('d M Y H:i', strtotime($note->posted_at)); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($note->description): ?>
                    <div class="mt-5 pt-4 border-t">
                        <strong class="text-gray-600">Description:</strong>
                        <p class="mt-1 text-gray-700 whitespace-pre-wrap"><?php echo htmlspecialchars($note->description); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- PO Financial Context -->
            <?php if ($po): ?>
            <div class="bg-white rounded-lg shadow">
                <div class="bg-gray-600 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold">PO Financial Context</h5>
                </div>
                <div class="p-5 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <div class="text-gray-500 text-xs">Total Order Value</div>
                        <div class="font-bold text-lg">৳<?php echo number_format($po->total_order_value, 2); ?></div>
                    </div>
                    <div>
                        <div class="text-gray-500 text-xs">Received Value</div>
                        <div class="font-bold text-lg text-green-700">৳<?php echo number_format($po->total_received_value ?? 0, 2); ?></div>
                    </div>
                    <div>
                        <div class="text-gray-500 text-xs">Total Paid</div>
                        <div class="font-bold text-lg text-blue-700">৳<?php echo number_format($po->total_paid ?? 0, 2); ?></div>
                    </div>
                    <div>
                        <div class="text-gray-500 text-xs">Balance Payable</div>
                        <div class="font-bold text-lg text-red-700">৳<?php echo number_format($po->balance_payable ?? 0, 2); ?></div>
                    </div>
                </div>
                <?php if ($note->status === 'approved' && !in_array($note->status, ['posted','cancelled'])): ?>
                <div class="px-5 pb-4">
                    <div class="bg-gray-50 border border-gray-200 rounded p-3 text-xs text-gray-600">
                        <strong>After posting this <?php echo strtoupper($note->note_type); ?>:</strong>
                        <?php if ($note->note_type === 'debit'): ?>
                            Balance Payable will become
                            <strong class="text-orange-700">৳<?php echo number_format(($po->balance_payable ?? 0) + $note->amount, 2); ?></strong>
                            (+৳<?php echo number_format($note->amount, 2); ?>)
                        <?php else: ?>
                            Balance Payable will become
                            <strong class="text-green-700">৳<?php echo number_format(max(0, ($po->balance_payable ?? 0) - $note->amount), 2); ?></strong>
                            (−৳<?php echo number_format($note->amount, 2); ?>)
                            + Supplier credit of ৳<?php echo number_format($note->amount, 2); ?> will be available.
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>

        <!-- Right: Actions -->
        <div class="space-y-4">

            <!-- Workflow Status Tracker -->
            <div class="bg-white rounded-lg shadow p-5">
                <h6 class="font-semibold text-gray-700 mb-4">Workflow Status</h6>
                <?php
                $steps = ['draft' => 1, 'approved' => 2, 'posted' => 3, 'cancelled' => 0];
                $current_step = $steps[$note->status] ?? 0;
                $workflow = [
                    ['label' => 'Draft', 'step' => 1, 'icon' => 'fa-pencil-alt'],
                    ['label' => 'Approved', 'step' => 2, 'icon' => 'fa-check'],
                    ['label' => 'Posted', 'step' => 3, 'icon' => 'fa-check-double'],
                ];
                foreach ($workflow as $w): ?>
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold
                        <?php if ($note->status === 'cancelled'): ?>
                            bg-red-100 text-red-500
                        <?php elseif ($current_step >= $w['step']): ?>
                            bg-green-100 text-green-700 border border-green-400
                        <?php else: ?>
                            bg-gray-100 text-gray-400
                        <?php endif; ?>">
                        <i class="fas <?php echo $w['icon']; ?>"></i>
                    </div>
                    <span class="text-sm font-medium <?php echo $current_step >= $w['step'] && $note->status !== 'cancelled' ? 'text-gray-900' : 'text-gray-400'; ?>">
                        <?php echo $w['label']; ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php if ($note->status === 'cancelled'): ?>
                <div class="flex items-center gap-3 mt-1">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center bg-red-100 text-red-600">
                        <i class="fas fa-times"></i>
                    </div>
                    <span class="text-sm font-medium text-red-600">Cancelled</span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Admin Actions -->
            <?php if ($is_admin && !in_array($note->status, ['posted', 'cancelled'])): ?>
            <div class="bg-white rounded-lg shadow p-5">
                <h6 class="font-semibold text-gray-700 mb-4">Admin Actions</h6>

                <?php if ($note->status === 'draft'): ?>
                <form method="POST" class="mb-3">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit"
                            class="w-full bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700 flex items-center justify-center gap-2"
                            onclick="return confirm('Approve adjustment note <?php echo $note->note_number; ?>?')">
                        <i class="fas fa-check"></i> Approve Note
                    </button>
                    <p class="text-xs text-gray-500 mt-1 text-center">No financial effect yet.</p>
                </form>
                <?php endif; ?>

                <?php if ($note->status === 'approved'): ?>
                <form method="POST" class="mb-3">
                    <input type="hidden" name="action" value="post">
                    <button type="submit"
                            class="w-full bg-green-600 text-white py-2 px-4 rounded-lg hover:bg-green-700 flex items-center justify-center gap-2"
                            onclick="return confirm('Post this note? This will update PO balances and cannot be reversed.')">
                        <i class="fas fa-check-double"></i> Post Note
                    </button>
                    <p class="text-xs text-gray-500 mt-1 text-center">Financial effect: PO balance will be updated.</p>
                </form>
                <?php endif; ?>

                <!-- Cancel -->
                <div>
                    <button type="button" onclick="document.getElementById('cancelForm').classList.toggle('hidden')"
                            class="w-full border border-red-300 text-red-700 py-2 px-4 rounded-lg hover:bg-red-50 flex items-center justify-center gap-2">
                        <i class="fas fa-times-circle"></i> Cancel Note
                    </button>
                    <form method="POST" id="cancelForm" class="mt-3 hidden">
                        <input type="hidden" name="action" value="cancel">
                        <textarea name="cancel_reason" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-red-400"
                                  placeholder="Reason for cancellation (required)..." required></textarea>
                        <button type="submit"
                                class="mt-2 w-full bg-red-600 text-white py-2 px-4 rounded-lg hover:bg-red-700 text-sm"
                                onclick="return confirm('Cancel this adjustment note? This cannot be undone.')">
                            Confirm Cancellation
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if (in_array($note->status, ['posted', 'cancelled'])): ?>
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm text-gray-600 text-center">
                <i class="fas fa-lock text-gray-400 text-2xl mb-2"></i>
                <p class="font-medium">
                    <?php echo $note->status === 'posted' ? 'This note is posted and locked.' : 'This note has been cancelled.'; ?>
                </p>
                <p class="text-xs mt-1">No further actions available.</p>
            </div>
            <?php endif; ?>

            <!-- Quick Links -->
            <div class="bg-white rounded-lg shadow p-4">
                <h6 class="font-semibold text-gray-700 mb-3">Quick Links</h6>
                <ul class="space-y-2 text-sm">
                    <li>
                        <a href="purchase_adnan_view_po.php?id=<?php echo $note->purchase_order_id; ?>"
                           class="text-blue-600 hover:underline flex items-center gap-2">
                            <i class="fas fa-file-invoice"></i> View Purchase Order
                        </a>
                    </li>
                    <li>
                        <a href="purchase_adnan_adjustments.php?purchase_order_id=<?php echo $note->purchase_order_id; ?>"
                           class="text-blue-600 hover:underline flex items-center gap-2">
                            <i class="fas fa-list"></i> All Notes for this PO
                        </a>
                    </li>
                    <li>
                        <a href="purchase_adnan_record_adjustment.php?po_id=<?php echo $note->purchase_order_id; ?>"
                           class="text-blue-600 hover:underline flex items-center gap-2">
                            <i class="fas fa-plus"></i> Create Another Note
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>

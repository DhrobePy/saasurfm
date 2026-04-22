<?php
/**
 * Purchase Adjustment Notes — List & Overview
 * DAN = Debit Adjustment Note (we owe supplier more)
 * CAN = Credit Adjustment Note (supplier owes us a reduction)
 */
require_once __DIR__ . '/../core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle    = "Purchase Adjustment Notes";
$currentUser  = getCurrentUser();
$user_role    = $currentUser['role'] ?? '';
$is_admin     = in_array($user_role, ['Superadmin', 'admin']);

$po_manager = new Purchaseadnanmanager();

// ── Filters from GET ────────────────────────────────────────────────────────
$filter_type      = $_GET['note_type']  ?? '';
$filter_status    = $_GET['status']     ?? '';
$filter_date_from = $_GET['date_from']  ?? '';
$filter_date_to   = $_GET['date_to']    ?? '';
$filter_search    = trim($_GET['search'] ?? '');

$filters = [];
if ($filter_type)      $filters['note_type']  = $filter_type;
if ($filter_status)    $filters['status']     = $filter_status;
if ($filter_date_from) $filters['date_from']  = $filter_date_from;
if ($filter_date_to)   $filters['date_to']    = $filter_date_to;

$notes = $po_manager->listAdjustmentNotes($filters);

// Client-side search filter (po_number / supplier_name / note_number)
if ($filter_search) {
    $s = strtolower($filter_search);
    $notes = array_filter($notes, function($n) use ($s) {
        return str_contains(strtolower($n->note_number  ?? ''), $s)
            || str_contains(strtolower($n->po_number    ?? ''), $s)
            || str_contains(strtolower($n->supplier_name ?? ''), $s);
    });
}

// Stats
$total_dan = $total_can = $pending_approval = 0;
$total_dan_amount = $total_can_amount = 0;
foreach ($notes as $n) {
    if ($n->note_type === 'debit')  { $total_dan++; $total_dan_amount += $n->amount; }
    if ($n->note_type === 'credit') { $total_can++; $total_can_amount += $n->amount; }
    if (in_array($n->status, ['draft','approved'])) $pending_approval++;
}

require_once '../templates/header.php';
?>

<div class="w-full px-4 py-6">

    <!-- Header -->
    <div class="flex flex-wrap justify-between items-center mb-6 gap-3">
        <div>
            <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-file-invoice-dollar text-indigo-600"></i>
                Purchase Adjustment Notes
            </h2>
            <nav class="text-sm text-gray-600 mt-1">
                <a href="purchase_adnan_index.php" class="hover:text-primary-600">Purchase (Adnan)</a>
                <span class="mx-2">›</span>
                <span>Adjustment Notes</span>
            </nav>
        </div>
        <div class="flex gap-2">
            <a href="purchase_adnan_index.php"
               class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <?php if ($is_admin): ?>
            <a href="purchase_adnan_record_adjustment.php"
               class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 flex items-center gap-2">
                <i class="fas fa-plus"></i> New Adjustment Note
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
    <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg mb-4 flex justify-between">
        <span><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
        <button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
    </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4 flex justify-between">
        <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
        <button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-orange-600"><?php echo $total_dan; ?></div>
            <div class="text-sm text-gray-600">Debit Notes (DAN)</div>
            <div class="text-xs text-gray-500">৳<?php echo number_format($total_dan_amount, 0); ?></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-blue-600"><?php echo $total_can; ?></div>
            <div class="text-sm text-gray-600">Credit Notes (CAN)</div>
            <div class="text-xs text-gray-500">৳<?php echo number_format($total_can_amount, 0); ?></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-yellow-600"><?php echo $pending_approval; ?></div>
            <div class="text-sm text-gray-600">Awaiting Action</div>
            <div class="text-xs text-gray-500">Draft / Approved</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-gray-700"><?php echo count($notes); ?></div>
            <div class="text-sm text-gray-600">Total (filtered)</div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" class="grid grid-cols-2 md:grid-cols-5 gap-3 items-end">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Type</label>
                <select name="note_type" class="w-full px-2 py-2 border border-gray-300 rounded text-sm">
                    <option value="">All Types</option>
                    <option value="debit"  <?php echo $filter_type === 'debit'  ? 'selected' : ''; ?>>Debit (DAN)</option>
                    <option value="credit" <?php echo $filter_type === 'credit' ? 'selected' : ''; ?>>Credit (CAN)</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                <select name="status" class="w-full px-2 py-2 border border-gray-300 rounded text-sm">
                    <option value="">All Status</option>
                    <option value="draft"     <?php echo $filter_status === 'draft'     ? 'selected' : ''; ?>>Draft</option>
                    <option value="approved"  <?php echo $filter_status === 'approved'  ? 'selected' : ''; ?>>Approved</option>
                    <option value="posted"    <?php echo $filter_status === 'posted'    ? 'selected' : ''; ?>>Posted</option>
                    <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>"
                       class="w-full px-2 py-2 border border-gray-300 rounded text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>"
                       class="w-full px-2 py-2 border border-gray-300 rounded text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Search</label>
                <div class="flex gap-1">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($filter_search); ?>"
                           placeholder="Note#, PO#, Supplier"
                           class="w-full px-2 py-2 border border-gray-300 rounded text-sm">
                    <button type="submit" class="bg-gray-700 text-white px-3 py-2 rounded text-sm hover:bg-gray-800">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Notes Table -->
    <div class="bg-white rounded-lg shadow">
        <div class="bg-indigo-700 text-white px-6 py-4 rounded-t-lg">
            <h5 class="font-semibold">Adjustment Notes (<?php echo count($notes); ?>)</h5>
        </div>
        <div class="p-0 overflow-x-auto">
            <?php if (count($notes) > 0): ?>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Note #</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">PO #</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Supplier</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty (KG)</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php
                    $reason_labels = [
                        'over_delivery'          => 'Over-Delivery',
                        'under_delivery_closure' => 'Under-Delivery Closure',
                        'quality_deduction'      => 'Quality Deduction',
                        'price_dispute'          => 'Price Dispute',
                        'return'                 => 'Return',
                        'other'                  => 'Other',
                    ];
                    $status_colors = [
                        'draft'     => 'bg-yellow-100 text-yellow-800',
                        'approved'  => 'bg-blue-100 text-blue-800',
                        'posted'    => 'bg-green-100 text-green-800',
                        'cancelled' => 'bg-red-100 text-red-800',
                    ];
                    foreach ($notes as $note): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">
                            <a href="purchase_adnan_view_adjustment.php?id=<?php echo $note->id; ?>"
                               class="text-indigo-700 hover:underline">
                                <?php echo htmlspecialchars($note->note_number); ?>
                            </a>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($note->note_type === 'debit'): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-semibold bg-orange-100 text-orange-800">
                                    <i class="fas fa-arrow-up text-xs"></i> DAN
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-semibold bg-blue-100 text-blue-800">
                                    <i class="fas fa-arrow-down text-xs"></i> CAN
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-gray-600">
                            <?php echo $reason_labels[$note->reason_type] ?? $note->reason_type; ?>
                        </td>
                        <td class="px-4 py-3">
                            <a href="purchase_adnan_view_po.php?id=<?php echo $note->purchase_order_id; ?>"
                               class="text-blue-600 hover:underline">
                                #<?php echo htmlspecialchars($note->po_number); ?>
                            </a>
                        </td>
                        <td class="px-4 py-3 text-gray-700">
                            <?php echo htmlspecialchars($note->supplier_name ?? 'N/A'); ?>
                        </td>
                        <td class="px-4 py-3 text-right text-gray-700">
                            <?php echo $note->quantity_kg ? number_format($note->quantity_kg, 2) : '—'; ?>
                        </td>
                        <td class="px-4 py-3 text-right font-bold <?php echo $note->note_type === 'debit' ? 'text-orange-700' : 'text-blue-700'; ?>">
                            ৳<?php echo number_format($note->amount, 2); ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 rounded text-xs font-medium <?php echo $status_colors[$note->status] ?? 'bg-gray-100 text-gray-800'; ?>">
                                <?php echo ucfirst($note->status); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap">
                            <?php echo date('d M Y', strtotime($note->created_at)); ?>
                            <div class="text-xs text-gray-400"><?php echo htmlspecialchars($note->created_by_name ?? ''); ?></div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="purchase_adnan_view_adjustment.php?id=<?php echo $note->id; ?>"
                               class="text-indigo-600 hover:text-indigo-800 mr-2" title="View / Action">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if (in_array($note->status, ['draft','approved']) && $is_admin): ?>
                            <a href="purchase_adnan_view_adjustment.php?id=<?php echo $note->id; ?>"
                               class="text-green-600 hover:text-green-800" title="Approve / Post">
                                <i class="fas fa-check-circle"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="text-center py-12 text-gray-500">
                    <i class="fas fa-file-invoice text-4xl text-gray-300 mb-3"></i>
                    <p>No adjustment notes found.</p>
                    <?php if ($is_admin): ?>
                    <a href="purchase_adnan_record_adjustment.php"
                       class="mt-3 inline-block bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700 text-sm">
                        Create First Adjustment Note
                    </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Legend -->
    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
        <div class="bg-orange-50 border border-orange-200 rounded-lg p-4">
            <h6 class="font-semibold text-orange-800 mb-1"><i class="fas fa-arrow-up mr-1"></i> DAN — Debit Adjustment Note</h6>
            <p class="text-orange-700 text-xs">We owe the supplier <strong>more</strong> than the original PO amount.
            Used for: over-delivery, price disputes (upward).</p>
        </div>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h6 class="font-semibold text-blue-800 mb-1"><i class="fas fa-arrow-down mr-1"></i> CAN — Credit Adjustment Note</h6>
            <p class="text-blue-700 text-xs">The supplier owes us a <strong>reduction</strong> in the payable amount.
            Used for: under-delivery closure, quality deductions, returns. Creates supplier credit balance.</p>
        </div>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>

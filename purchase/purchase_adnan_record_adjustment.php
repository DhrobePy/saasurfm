<?php
/**
 * Create Purchase Adjustment Note (DAN or CAN)
 * DAN = Debit Adjustment Note  — we owe supplier more
 * CAN = Credit Adjustment Note — supplier owes us a reduction
 */
require_once __DIR__ . '/../core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle   = "Record Adjustment Note";
$currentUser = getCurrentUser();
$user_role   = $currentUser['role'] ?? '';
$is_admin    = in_array($user_role, ['Superadmin', 'admin']);

$po_manager = new Purchaseadnanmanager();

// Pre-select PO from URL
$pre_po_id = (int)($_GET['po_id'] ?? 0);

// All non-cancelled POs for the dropdown (we may adjust any PO)
$all_pos = $po_manager->listPurchaseOrders(['order_status_filter' => 'all_active']);

$selected_po = null;
if ($pre_po_id) {
    $selected_po = $po_manager->getPurchaseOrder($pre_po_id);
}

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $po_id = (int)($_POST['purchase_order_id'] ?? 0);
        if (!$po_id) throw new Exception('Please select a Purchase Order.');

        $note_type   = $_POST['note_type']   ?? '';
        $reason_type = $_POST['reason_type'] ?? '';
        $amount      = floatval($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');

        if (!in_array($note_type, ['debit','credit']))   throw new Exception('Invalid note type.');
        if (empty($reason_type))                          throw new Exception('Please select a reason.');
        if ($amount <= 0)                                 throw new Exception('Amount must be greater than zero.');
        if (empty($description))                          throw new Exception('Description / reason detail is required.');

        $result = $po_manager->createAdjustmentNote([
            'note_type'         => $note_type,
            'reason_type'       => $reason_type,
            'purchase_order_id' => $po_id,
            'quantity_kg'       => $_POST['quantity_kg']       ?? null,
            'unit_price_per_kg' => $_POST['unit_price_per_kg'] ?? null,
            'amount'            => $amount,
            'description'       => $description,
        ]);

        if ($result['success']) {
            $_SESSION['success'] = "Adjustment Note {$result['note_number']} created as draft. "
                                   . "Admin must approve and post it to take financial effect.";
            redirect('purchase/purchase_adnan_view_adjustment.php?id=' . $result['note_id']);
        } else {
            $_SESSION['error'] = $result['message'];
        }

    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

require_once '../templates/header.php';
?>

<div class="w-full px-4 py-6">

    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-file-invoice-dollar text-indigo-600"></i>
                Record Adjustment Note
            </h2>
            <nav class="text-sm text-gray-600 mt-1">
                <a href="purchase_adnan_index.php" class="hover:text-primary-600">Purchase (Adnan)</a>
                <span class="mx-2">›</span>
                <a href="purchase_adnan_adjustments.php" class="hover:text-primary-600">Adjustment Notes</a>
                <span class="mx-2">›</span>
                <span>New</span>
            </nav>
        </div>
        <a href="purchase_adnan_adjustments.php"
           class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-5 flex justify-between">
        <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
        <button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Form -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow">
                <div class="bg-indigo-700 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold">Adjustment Note Details</h5>
                </div>
                <div class="p-6">
                    <form method="POST" id="adjForm">

                        <!-- Note Type selector (large radio cards) -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-3">
                                Note Type <span class="text-red-500">*</span>
                            </label>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <label id="card_debit"
                                       class="cursor-pointer border-2 border-gray-200 rounded-lg p-4 flex items-start gap-3 hover:border-orange-400 transition-colors">
                                    <input type="radio" name="note_type" value="debit" id="type_debit"
                                           class="mt-1" onchange="onNoteTypeChange()">
                                    <div>
                                        <p class="font-semibold text-orange-700">
                                            <i class="fas fa-arrow-up mr-1"></i> Debit Note (DAN)
                                        </p>
                                        <p class="text-xs text-gray-600 mt-1">
                                            We owe the supplier <strong>more</strong> than the original PO.<br>
                                            Use for: over-delivery, price adjustment (upward).
                                        </p>
                                    </div>
                                </label>
                                <label id="card_credit"
                                       class="cursor-pointer border-2 border-gray-200 rounded-lg p-4 flex items-start gap-3 hover:border-blue-400 transition-colors">
                                    <input type="radio" name="note_type" value="credit" id="type_credit"
                                           class="mt-1" onchange="onNoteTypeChange()">
                                    <div>
                                        <p class="font-semibold text-blue-700">
                                            <i class="fas fa-arrow-down mr-1"></i> Credit Note (CAN)
                                        </p>
                                        <p class="text-xs text-gray-600 mt-1">
                                            Supplier owes us a <strong>reduction</strong> in payable amount.<br>
                                            Use for: short delivery closure, quality deduction, return.
                                        </p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Reason Type -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Reason <span class="text-red-500">*</span>
                            </label>
                            <select name="reason_type" id="reason_type"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    required>
                                <option value="">-- Select Note Type first, then Reason --</option>
                                <!-- DAN reasons -->
                                <option value="over_delivery"          class="dan-reason hidden">Over-Delivery (extra goods received)</option>
                                <option value="price_dispute"          class="dan-reason hidden">Price Dispute / Upward Price Adjustment</option>
                                <option value="other"                  class="dan-reason hidden">Other (Debit)</option>
                                <!-- CAN reasons -->
                                <option value="under_delivery_closure" class="can-reason hidden">Under-Delivery Closure (PO closed short)</option>
                                <option value="quality_deduction"      class="can-reason hidden">Quality / Weight Deduction</option>
                                <option value="return"                 class="can-reason hidden">Goods Return</option>
                                <option value="other"                  class="can-reason hidden">Other (Credit)</option>
                            </select>
                        </div>

                        <!-- Purchase Order -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Purchase Order <span class="text-red-500">*</span>
                            </label>
                            <select name="purchase_order_id" id="purchase_order_id"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    required>
                                <option value="">-- Select Purchase Order --</option>
                                <?php foreach ($all_pos as $po): ?>
                                <option value="<?php echo $po->id; ?>"
                                        data-supplier="<?php echo htmlspecialchars($po->supplier_name); ?>"
                                        data-supplier-id="<?php echo $po->supplier_id; ?>"
                                        data-ordered="<?php echo $po->quantity_kg; ?>"
                                        data-received="<?php echo $po->total_received_qty ?? 0; ?>"
                                        data-unit-price="<?php echo $po->unit_price_per_kg; ?>"
                                        data-balance="<?php echo $po->balance_payable ?? 0; ?>"
                                        <?php echo ($selected_po && $selected_po->id == $po->id) ? 'selected' : ''; ?>>
                                    PO #<?php echo $po->po_number; ?> — <?php echo htmlspecialchars($po->supplier_name); ?>
                                    (Bal: ৳<?php echo number_format($po->balance_payable ?? 0, 0); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- PO summary pill (hidden until PO selected) -->
                        <div id="poSummary" class="mb-4 hidden">
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm grid grid-cols-2 gap-2">
                                <div><strong>Supplier:</strong> <span id="sumSupplier"></span></div>
                                <div><strong>Ordered:</strong> <span id="sumOrdered"></span> KG</div>
                                <div><strong>Received:</strong> <span id="sumReceived"></span> KG</div>
                                <div><strong>Balance Due:</strong> ৳<span id="sumBalance"></span></div>
                            </div>
                        </div>

                        <!-- Qty & Unit Price (optional, for qty-based adjustments) -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Quantity (KG) <span class="text-gray-400 text-xs">optional</span>
                                </label>
                                <input type="number" name="quantity_kg" id="quantity_kg"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                       step="0.01" min="0" placeholder="e.g. 500.00">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Unit Price / KG <span class="text-gray-400 text-xs">optional</span>
                                </label>
                                <input type="number" name="unit_price_per_kg" id="unit_price_per_kg"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                       step="0.0001" min="0" placeholder="Auto-filled from PO">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Total Amount (৳) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="amount" id="amount"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                       step="0.01" min="0.01" required placeholder="0.00">
                                <p class="text-xs text-gray-500 mt-1">Auto-calculated if Qty & Price filled.</p>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Description / Details <span class="text-red-500">*</span>
                            </label>
                            <textarea name="description" id="description" rows="4"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                      placeholder="Explain the reason for this adjustment in detail..." required></textarea>
                        </div>

                        <!-- Calculated summary banner -->
                        <div id="calcBanner" class="mb-6 hidden">
                            <div class="rounded-lg p-4 text-center" id="calcBannerInner">
                                <div class="text-sm text-gray-600">Adjustment Amount</div>
                                <div class="text-3xl font-bold" id="calcAmount">৳0.00</div>
                                <div class="text-xs mt-1" id="calcNote"></div>
                            </div>
                        </div>

                        <!-- Buttons -->
                        <div class="flex justify-between">
                            <a href="purchase_adnan_adjustments.php"
                               class="border border-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-50">
                                Cancel
                            </a>
                            <button type="submit"
                                    class="bg-indigo-600 text-white px-6 py-2 rounded-lg hover:bg-indigo-700 flex items-center gap-2">
                                <i class="fas fa-save"></i> Create as Draft
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-4">
            <div class="bg-white rounded-lg shadow">
                <div class="bg-blue-600 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold"><i class="fas fa-info-circle mr-2"></i>Workflow</h5>
                </div>
                <div class="p-5 text-sm text-gray-700 space-y-3">
                    <div class="flex items-start gap-2">
                        <span class="bg-yellow-100 text-yellow-800 px-2 py-0.5 rounded text-xs font-bold mt-0.5">1</span>
                        <div><strong>Create (Draft)</strong> — Anyone with access can create. No financial effect yet.</div>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded text-xs font-bold mt-0.5">2</span>
                        <div><strong>Approve</strong> — Admin reviews and approves the note.</div>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="bg-green-100 text-green-800 px-2 py-0.5 rounded text-xs font-bold mt-0.5">3</span>
                        <div><strong>Post</strong> — Admin posts it. Financial effect kicks in:
                            <ul class="list-disc ml-4 mt-1 text-xs">
                                <li>DAN: increases PO balance payable</li>
                                <li>CAN: creates supplier credit balance + reduces payable</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-orange-50 border border-orange-200 rounded-lg p-4 text-sm">
                <p class="font-semibold text-orange-800 mb-1">
                    <i class="fas fa-exclamation-circle mr-1"></i> Important
                </p>
                <p class="text-orange-700">Creating a note does NOT affect payments or balances until it is approved and posted by an Admin.</p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const poSelect     = document.getElementById('purchase_order_id');
    const qtyInput     = document.getElementById('quantity_kg');
    const priceInput   = document.getElementById('unit_price_per_kg');
    const amountInput  = document.getElementById('amount');
    const reasonSelect = document.getElementById('reason_type');
    const calcBanner   = document.getElementById('calcBanner');

    // ─── Note type change ─────────────────────────────────────────
    window.onNoteTypeChange = function() {
        const type = document.querySelector('input[name="note_type"]:checked')?.value;
        // Style cards
        document.getElementById('card_debit').classList.toggle('border-orange-500', type === 'debit');
        document.getElementById('card_debit').classList.toggle('bg-orange-50', type === 'debit');
        document.getElementById('card_credit').classList.toggle('border-blue-500', type === 'credit');
        document.getElementById('card_credit').classList.toggle('bg-blue-50', type === 'credit');

        // Show/hide reason options
        document.querySelectorAll('.dan-reason').forEach(el => {
            el.classList.toggle('hidden', type !== 'debit');
            el.disabled = type !== 'debit';
        });
        document.querySelectorAll('.can-reason').forEach(el => {
            el.classList.toggle('hidden', type !== 'credit');
            el.disabled = type !== 'credit';
        });
        reasonSelect.value = '';
        updateCalcBanner();
    };

    // ─── PO selection ─────────────────────────────────────────────
    poSelect.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (this.value) {
            document.getElementById('sumSupplier').textContent = opt.dataset.supplier;
            document.getElementById('sumOrdered').textContent  = parseFloat(opt.dataset.ordered  || 0).toFixed(2);
            document.getElementById('sumReceived').textContent = parseFloat(opt.dataset.received || 0).toFixed(2);
            document.getElementById('sumBalance').textContent  = parseFloat(opt.dataset.balance  || 0).toFixed(2);
            priceInput.value = parseFloat(opt.dataset.unitPrice || 0).toFixed(4);
            document.getElementById('poSummary').classList.remove('hidden');
        } else {
            document.getElementById('poSummary').classList.add('hidden');
            priceInput.value = '';
        }
        calcAmount();
    });

    // ─── Auto-calculate amount ────────────────────────────────────
    function calcAmount() {
        const qty   = parseFloat(qtyInput.value)   || 0;
        const price = parseFloat(priceInput.value)  || 0;
        if (qty > 0 && price > 0) {
            amountInput.value = (qty * price).toFixed(2);
        }
        updateCalcBanner();
    }

    function updateCalcBanner() {
        const amt  = parseFloat(amountInput.value) || 0;
        const type = document.querySelector('input[name="note_type"]:checked')?.value;
        if (amt > 0 && type) {
            calcBanner.classList.remove('hidden');
            const inner = document.getElementById('calcBannerInner');
            const note  = document.getElementById('calcNote');
            if (type === 'debit') {
                inner.className = 'rounded-lg p-4 text-center bg-orange-50 border border-orange-200';
                document.getElementById('calcAmount').className = 'text-3xl font-bold text-orange-700';
                note.className  = 'text-xs text-orange-600 mt-1';
                note.textContent = 'DAN — This amount will be ADDED to the PO balance payable when posted.';
            } else {
                inner.className = 'rounded-lg p-4 text-center bg-blue-50 border border-blue-200';
                document.getElementById('calcAmount').className = 'text-3xl font-bold text-blue-700';
                note.className  = 'text-xs text-blue-600 mt-1';
                note.textContent = 'CAN — This amount will be DEDUCTED from PO balance payable and added to supplier credit.';
            }
            document.getElementById('calcAmount').textContent = '৳' + amt.toLocaleString('en-BD', {minimumFractionDigits:2});
        } else {
            calcBanner.classList.add('hidden');
        }
    }

    qtyInput.addEventListener('input',    calcAmount);
    priceInput.addEventListener('input',  calcAmount);
    amountInput.addEventListener('input', updateCalcBanner);

    // Trigger on load if PO pre-selected
    if (poSelect.value) poSelect.dispatchEvent(new Event('change'));
});
</script>

<?php require_once '../templates/footer.php'; ?>

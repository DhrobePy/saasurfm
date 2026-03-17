<?php
/**
 * bank/create_transaction.php
 * Create / Edit a Bank Transaction
 */

require_once dirname(__DIR__) . '/core/init.php';
require_once dirname(__DIR__) . '/bank/BankManager.php';

restrict_access();

$currentUser = getCurrentUser();
$userId      = $currentUser['id'];
$userRole    = $currentUser['role'];
$userName    = $currentUser['display_name'];
$ipAddress   = $_SERVER['REMOTE_ADDR'] ?? null;

$bankManager = new BankManager();

$adminRoles  = ['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg'];
$isAdmin     = in_array($userRole, $adminRoles);

// Edit mode
$editMode = false;
$editTx   = null;
if (!empty($_GET['edit'])) {
    $editTx = $bankManager->getTransactionById((int)$_GET['edit']);
    if ($editTx) {
        $editMode = true;
        // Non-admin can only edit their own
        if (!$isAdmin && $editTx->created_by_user_id != $userId) {
            $_SESSION['error_flash'] = 'Access denied.';
            header('Location: ' . url('bank/index.php'));
            exit;
        }
    }
}

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($editMode && !empty($_POST['edit_id'])) {
            $bankManager->updateTransaction((int)$_POST['edit_id'], $_POST, $userId, $userName, $ipAddress);
            $_SESSION['success_flash'] = 'Transaction updated successfully.';
        } else {
            $result = $bankManager->createTransaction($_POST, $userId, $userName, $ipAddress);
            $_SESSION['success_flash'] = 'Transaction ' . $result['transaction_number'] . ' created. Pending approval.';
            header('Location: ' . url('bank/receipt.php?id=' . $result['id']));
            exit;
        }
        header('Location: ' . url('bank/index.php'));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$bankAccounts = $bankManager->getBankAccounts(true);
$txTypes      = $bankManager->getTransactionTypes(true);
$branches     = [];
try {
    $db = Database::getInstance();
    $branches = $db->query("SELECT id, name FROM branches WHERE status='active' ORDER BY name")->results();
} catch (Exception $e) {}

$pageTitle = $editMode ? 'Edit Transaction' : 'New Bank Transaction';
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="w-full max-w-2xl mx-auto px-4 sm:px-6 py-6">


<?php echo display_message(); ?>
<?php if (!empty($error)): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-5 rounded-r-lg">
    <p class="font-bold">Error</p>
    <p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<div class="flex items-center gap-3 mb-6">
    <a href="<?php echo url('bank/index.php'); ?>" class="text-gray-400 hover:text-gray-600">
        <i class="fas fa-arrow-left"></i>
    </a>
    <div>
        <h1 class="text-xl font-bold text-gray-900">
            <?php echo $editMode ? 'Edit Transaction' : 'New Bank Transaction'; ?>
        </h1>
        <p class="text-sm text-gray-500">
            <?php echo $editMode ? 'Editing: ' . htmlspecialchars($editTx->transaction_number) : 'Entry will be submitted for approval'; ?>
        </p>
    </div>
</div>

<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
    <form method="POST" id="txForm">
        <?php if ($editMode): ?>
        <input type="hidden" name="edit_id" value="<?php echo $editTx->id; ?>">
        <?php endif; ?>

        <!-- Entry Type -->
        <div class="mb-5">
            <label class="block text-sm font-semibold text-gray-700 mb-2">
                Entry Type <span class="text-red-500">*</span>
            </label>
            <div class="grid grid-cols-2 gap-3">
                <label class="entryTypeBtn cursor-pointer">
                    <input type="radio" name="entry_type" value="credit" class="sr-only"
                           <?php echo (!$editMode || $editTx->entry_type === 'credit') ? 'checked' : ''; ?>>
                    <div class="entry-credit border-2 rounded-xl p-4 text-center transition-all border-gray-200 hover:border-green-400">
                        <i class="fas fa-arrow-down text-2xl text-green-500 mb-1 block"></i>
                        <p class="font-semibold text-gray-800">Credit</p>
                        <p class="text-xs text-gray-500">Money Coming In</p>
                    </div>
                </label>
                <label class="entryTypeBtn cursor-pointer">
                    <input type="radio" name="entry_type" value="debit" class="sr-only"
                           <?php echo ($editMode && $editTx->entry_type === 'debit') ? 'checked' : ''; ?>>
                    <div class="entry-debit border-2 rounded-xl p-4 text-center transition-all border-gray-200 hover:border-red-400">
                        <i class="fas fa-arrow-up text-2xl text-red-500 mb-1 block"></i>
                        <p class="font-semibold text-gray-800">Debit</p>
                        <p class="text-xs text-gray-500">Money Going Out</p>
                    </div>
                </label>
            </div>
        </div>

        <!-- Bank Account -->
        <div class="mb-4">
            <label class="block text-sm font-semibold text-gray-700 mb-1">
                Bank Account <span class="text-red-500">*</span>
            </label>
            <select name="bank_tx_account_id" required
                    class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                <option value="">— Select Bank Account —</option>
                <?php foreach ($bankAccounts as $ba): ?>
                <option value="<?php echo $ba->id; ?>"
                        <?php echo ($editMode && $editTx->bank_tx_account_id == $ba->id) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($ba->bank_name . ' | ' . $ba->account_name . ' | ' . $ba->account_number); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Date & Amount -->
        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">
                    Transaction Date <span class="text-red-500">*</span>
                </label>
                <input type="date" name="transaction_date" required
                       value="<?php echo $editMode ? $editTx->transaction_date : date('Y-m-d'); ?>"
                       class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">
                    Amount (৳) <span class="text-red-500">*</span>
                </label>
                <input type="number" name="amount" required min="0.01" step="0.01"
                       value="<?php echo $editMode ? $editTx->amount : ''; ?>"
                       placeholder="0.00"
                       class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
            </div>
        </div>

        <!-- Transaction Category (filtered by entry type) -->
        <div class="mb-4">
            <label class="block text-sm font-semibold text-gray-700 mb-1">
                Transaction Category
                <span id="categoryHint" class="ml-1 text-xs font-normal text-gray-400"></span>
            </label>
            <select name="transaction_type_id" id="txTypeSelect"
                    class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                <option value="">— Select Category (Optional) —</option>
            </select>
        </div>

        <!-- Reference & Cheque -->
        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Reference Number</label>
                <input type="text" name="reference_number"
                       value="<?php echo $editMode ? htmlspecialchars($editTx->reference_number ?? '') : ''; ?>"
                       placeholder="TRN / RTGS / NPSB Ref..."
                       class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Cheque Number</label>
                <input type="text" name="cheque_number"
                       value="<?php echo $editMode ? htmlspecialchars($editTx->cheque_number ?? '') : ''; ?>"
                       placeholder="Cheque no. if applicable"
                       class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
            </div>
        </div>

        <!-- Payee / Payer -->
        <div class="mb-4">
            <label class="block text-sm font-semibold text-gray-700 mb-1">
                <span id="payeeLabel">Payee / Payer Name</span>
            </label>
            <input type="text" name="payee_payer_name"
                   value="<?php echo $editMode ? htmlspecialchars($editTx->payee_payer_name ?? '') : ''; ?>"
                   placeholder="Who paid / who was paid"
                   class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
        </div>

        <!-- Description -->
        <div class="mb-4">
            <label class="block text-sm font-semibold text-gray-700 mb-1">Description / Narration</label>
            <textarea name="description" rows="2"
                      placeholder="Brief description of the transaction purpose..."
                      class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-primary-500 outline-none resize-none"><?php echo $editMode ? htmlspecialchars($editTx->description ?? '') : ''; ?></textarea>
        </div>

        <!-- Special Note -->
        <div class="mb-4">
            <label class="block text-sm font-semibold text-gray-700 mb-1">Special Note</label>
            <textarea name="special_note" rows="2"
                      placeholder="Any additional note or remark..."
                      class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-primary-500 outline-none resize-none"><?php echo $editMode ? htmlspecialchars($editTx->special_note ?? '') : ''; ?></textarea>
        </div>

        <!-- Branch (admin only optional) -->
        <?php if ($isAdmin && !empty($branches)): ?>
        <div class="mb-4">
            <label class="block text-sm font-semibold text-gray-700 mb-1">Branch (Optional)</label>
            <select name="branch_id"
                    class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                <option value="">— All / No Branch —</option>
                <?php foreach ($branches as $br): ?>
                <option value="<?php echo $br->id; ?>"
                        <?php echo ($editMode && $editTx->branch_id == $br->id) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($br->name); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <!-- Preview Card -->
        <div id="previewCard" class="hidden mb-5 p-4 rounded-xl border-2 border-dashed">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Transaction Preview</p>
            <div class="flex justify-between">
                <div>
                    <p id="previewType" class="text-lg font-bold"></p>
                    <p id="previewAccount" class="text-sm text-gray-500"></p>
                </div>
                <p id="previewAmount" class="text-2xl font-bold"></p>
            </div>
        </div>

        <!-- Submit -->
        <div class="flex gap-3">
            <button type="submit"
                    class="flex-1 bg-primary-600 text-white py-3 rounded-xl font-semibold hover:bg-primary-700 transition-colors flex items-center justify-center gap-2">
                <i class="fas fa-<?php echo $editMode ? 'save' : 'paper-plane'; ?>"></i>
                <?php echo $editMode ? 'Save Changes' : 'Submit Transaction'; ?>
            </button>
            <a href="<?php echo url('bank/index.php'); ?>"
               class="px-6 py-3 border border-gray-200 text-gray-600 rounded-xl hover:bg-gray-50 transition-colors">
                Cancel
            </a>
        </div>

    </form>
</div>


</div>

<script>
// ── Transaction types data (from PHP) ─────────────────────
const allTxTypes = <?php echo json_encode(array_map(fn($t) => [
    'id'     => $t->id,
    'name'   => $t->name,
    'nature' => $t->nature,
], $txTypes)); ?>;

const editSelectedTypeId = <?php echo ($editMode && $editTx->transaction_type_id) ? (int)$editTx->transaction_type_id : 'null'; ?>;

// nature → which entry types it belongs to
// credit (income) → show: income, transfer, other
// debit  (expense) → show: expense, transfer, other
const natureMap = {
    credit: ['income', 'transfer', 'other'],
    debit:  ['expense', 'transfer', 'other'],
};

const hintMap = {
    credit: '(showing income categories)',
    debit:  '(showing expense categories)',
};

const groupLabels = {
    income:   '💰 Income',
    expense:  '💸 Expense',
    transfer: '🔄 Transfer',
    other:    '📋 Other',
};

function filterCategories() {
    const entryType  = document.querySelector('input[name="entry_type"]:checked')?.value || 'credit';
    const allowed    = natureMap[entryType] || ['income','expense','transfer','other'];
    const sel        = document.getElementById('txTypeSelect');
    const prevVal    = sel.value;

    // Clear and rebuild
    sel.innerHTML = '<option value="">— Select Category (Optional) —</option>';

    // Group allowed types
    const groups = {};
    allTxTypes.forEach(t => {
        if (allowed.includes(t.nature)) {
            if (!groups[t.nature]) groups[t.nature] = [];
            groups[t.nature].push(t);
        }
    });

    // Render optgroups in order
    const order = allowed;
    order.forEach(nature => {
        if (!groups[nature] || !groups[nature].length) return;
        const og = document.createElement('optgroup');
        og.label = groupLabels[nature] || nature;
        groups[nature].forEach(t => {
            const opt = document.createElement('option');
            opt.value = t.id;
            opt.textContent = t.name;
            // Restore previously selected if still valid
            if (String(t.id) === String(prevVal) || (editSelectedTypeId && t.id === editSelectedTypeId && prevVal === '')) {
                opt.selected = true;
            }
            og.appendChild(opt);
        });
        sel.appendChild(og);
    });

    // Update hint text
    document.getElementById('categoryHint').textContent = hintMap[entryType] || '';
}

// ── Entry type visual toggle ───────────────────────────────
const radios = document.querySelectorAll('input[name="entry_type"]');
function updateEntryTypeUI() {
    const selected = document.querySelector('input[name="entry_type"]:checked')?.value;
    document.querySelector('.entry-credit').classList.toggle('border-green-500', selected === 'credit');
    document.querySelector('.entry-credit').classList.toggle('bg-green-50',     selected === 'credit');
    document.querySelector('.entry-credit').classList.toggle('border-gray-200', selected !== 'credit');
    document.querySelector('.entry-debit').classList.toggle('border-red-500',  selected === 'debit');
    document.querySelector('.entry-debit').classList.toggle('bg-red-50',       selected === 'debit');
    document.querySelector('.entry-debit').classList.toggle('border-gray-200', selected !== 'debit');

    // Update payee label
    const lbl = document.getElementById('payeeLabel');
    if (selected === 'credit') lbl.textContent = 'Payer Name (who sent money)';
    else lbl.textContent = 'Payee Name (who received money)';

    filterCategories();
    updatePreview();
}

radios.forEach(r => r.addEventListener('change', updateEntryTypeUI));
updateEntryTypeUI();
filterCategories(); // populate on load with correct initial entry type

// ── Live preview ───────────────────────────────────────────
function updatePreview() {
    const type   = document.querySelector('input[name="entry_type"]:checked')?.value;
    const amount = parseFloat(document.querySelector('[name="amount"]').value || 0);
    const acct   = document.querySelector('[name="bank_tx_account_id"]').selectedOptions[0]?.text || '';

    const card = document.getElementById('previewCard');
    if (amount > 0) {
        card.classList.remove('hidden');
        card.className = card.className.replace(/border-green-\d+|border-red-\d+|border-dashed/g, '');
        if (type === 'credit') {
            card.classList.add('border-green-400', 'bg-green-50');
            document.getElementById('previewType').className = 'text-lg font-bold text-green-700';
            document.getElementById('previewType').textContent = '⬇ Credit (Money In)';
            document.getElementById('previewAmount').className = 'text-2xl font-bold text-green-700';
            document.getElementById('previewAmount').textContent = '+৳' + amount.toLocaleString('en-BD', {minimumFractionDigits:2});
        } else {
            card.classList.add('border-red-400', 'bg-red-50');
            document.getElementById('previewType').className = 'text-lg font-bold text-red-700';
            document.getElementById('previewType').textContent = '⬆ Debit (Money Out)';
            document.getElementById('previewAmount').className = 'text-2xl font-bold text-red-700';
            document.getElementById('previewAmount').textContent = '-৳' + amount.toLocaleString('en-BD', {minimumFractionDigits:2});
        }
        document.getElementById('previewAccount').textContent = acct;
    } else {
        card.classList.add('hidden');
    }
}

document.querySelector('[name="amount"]').addEventListener('input', updatePreview);
document.querySelector('[name="bank_tx_account_id"]').addEventListener('change', updatePreview);
updatePreview();
</script>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
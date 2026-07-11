<?php
/**
 * Edit Payment Form - Superadmin Only
 * File: /purchase/purchase_adnan_edit_payment.php
 * * Fajracct Style - Tailwind CSS Version
 */

require_once '../core/init.php';
require_once '../templates/header.php';

// Superadmin only
restrict_access(['Superadmin']);

$payment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($payment_id === 0) {
    $_SESSION['error_flash'] = "Invalid Payment ID";
    header('Location: purchase_adnan_index.php');
    exit;
}

$db = Database::getInstance()->getPdo();

// Get Payment details
$stmt = $db->prepare("
    SELECT 
        p.*,
        po.po_number,
        po.supplier_name,
        po.balance_payable
    FROM purchase_payments_adnan p
    JOIN purchase_orders_adnan po ON p.purchase_order_id = po.id
    WHERE p.id = ?
");
$stmt->execute([$payment_id]);
$payment = $stmt->fetch(PDO::FETCH_OBJ);

if (!$payment) {
    $_SESSION['error_flash'] = "Payment not found";
    header('Location: purchase_adnan_index.php');
    exit;
}

// Get bank accounts for dropdown
$bank_accounts = $db->query("SELECT id, account_name FROM bank_accounts WHERE status = 1 ORDER BY account_name")->fetchAll(PDO::FETCH_OBJ);

$pageTitle = "Edit Payment: " . $payment->payment_voucher_number;
?>

<div class="w-full">
    <!-- Header Section -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
        <div>
            <h1 class="text-3xl font-black text-gray-900 uppercase tracking-tight">
                Edit Payment <span class="text-primary-600">#<?= htmlspecialchars($payment->payment_voucher_number) ?></span>
            </h1>
            <p class="text-gray-500 text-sm mt-1 flex items-center gap-2">
                <i class="fas fa-file-invoice"></i> Associated PO: <span class="font-bold text-gray-700">#<?= htmlspecialchars($payment->po_number) ?></span>
                <span class="mx-2 text-gray-300">|</span>
                <i class="fas fa-user-tie"></i> Supplier: <span class="font-bold text-gray-700"><?= htmlspecialchars($payment->supplier_name) ?></span>
            </p>
        </div>
        <div class="flex gap-3">
            <a href="purchase_adnan_view_po.php?id=<?= $payment->purchase_order_id ?>" class="px-4 py-2 bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200 transition font-bold text-sm">
                <i class="fas fa-times mr-2"></i>Cancel
            </a>
        </div>
    </div>

    <div class="max-w-4xl">
        <!-- Status & Balance Info -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded-r-xl shadow-sm">
                <div class="flex items-center">
                    <i class="fas fa-wallet text-blue-500 text-xl mr-3"></i>
                    <div>
                        <p class="text-[10px] font-black text-blue-400 uppercase tracking-widest">Current Balance Due</p>
                        <p class="text-xl font-black text-blue-700">৳<?= number_format($payment->balance_payable, 2) ?></p>
                    </div>
                </div>
            </div>
            <div class="<?= $payment->is_posted ? 'bg-green-50 border-green-500' : 'bg-amber-50 border-amber-500' ?> border-l-4 p-4 rounded-r-xl shadow-sm">
                <div class="flex items-center">
                    <i class="fas <?= $payment->is_posted ? 'fa-check-circle text-green-500' : 'fa-clock text-amber-500' ?> text-xl mr-3"></i>
                    <div>
                        <p class="text-[10px] font-black <?= $payment->is_posted ? 'text-green-400' : 'text-amber-400' ?> uppercase tracking-widest">Posting Status</p>
                        <p class="text-xl font-black <?= $payment->is_posted ? 'text-green-700' : 'text-amber-700' ?> uppercase"><?= $payment->is_posted ? 'Posted' : 'Draft' ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Warning Alert for Posted Payments -->
        <?php if ($payment->is_posted && $payment->journal_entry_id): ?>
            <div class="bg-amber-50 border-l-4 border-amber-500 p-4 mb-6 rounded-r-xl shadow-sm">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-amber-500 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-amber-800 font-medium">
                            <strong>Note:</strong> This payment is already posted. 
                            <span class="font-normal">Editing will automatically reverse the old journal entry (#<?= $payment->journal_entry_id ?>) and generate a new one upon saving.</span>
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <form id="editPaymentForm" method="POST" action="purchase_adnan_update_payment.php" class="space-y-6">
            <input type="hidden" name="payment_id" value="<?= $payment->id ?>">
            <input type="hidden" name="purchase_order_id" value="<?= $payment->purchase_order_id ?>">
            
            <div class="bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden">
                <div class="bg-gray-900 px-8 py-4 text-white flex justify-between items-center">
                    <h3 class="font-bold text-sm uppercase tracking-widest flex items-center gap-2">
                        <i class="fas fa-file-invoice-dollar text-primary-400"></i> Payment Details
                    </h3>
                </div>

                <div class="p-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-10 gap-y-6">
                        
                        <!-- Left Column -->
                        <div class="space-y-6">
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Payment Date <span class="text-red-500">*</span></label>
                                <input type="date" name="payment_date" value="<?= $payment->payment_date ?>" required
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-500 focus:bg-white outline-none transition">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Amount Paid (৳) <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="number" name="amount_paid" value="<?= $payment->amount_paid ?>" 
                                           step="0.01" min="0.01" required
                                           class="w-full px-4 py-3 bg-white border-2 border-primary-100 rounded-xl focus:ring-4 focus:ring-primary-500/10 focus:border-primary-500 outline-none transition font-black text-xl text-gray-900">
                                    <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none text-gray-400 font-bold">
                                        BDT
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Payment Type <span class="text-red-500">*</span></label>
                                <select name="payment_type" required
                                        class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-500 focus:bg-white outline-none transition cursor-pointer">
                                    <option value="advance" <?= $payment->payment_type === 'advance' ? 'selected' : '' ?>>Advance</option>
                                    <option value="regular" <?= $payment->payment_type === 'regular' ? 'selected' : '' ?>>Regular</option>
                                    <option value="final" <?= $payment->payment_type === 'final' ? 'selected' : '' ?>>Final</option>
                                </select>
                            </div>
                        </div>

                        <!-- Right Column -->
                        <div class="space-y-6">
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Payment Method <span class="text-red-500">*</span></label>
                                <select name="payment_method" id="payment_method" required
                                        class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-500 focus:bg-white outline-none transition cursor-pointer font-bold text-primary-700">
                                    <option value="bank" <?= $payment->payment_method === 'bank' ? 'selected' : '' ?>>Bank Transfer</option>
                                    <option value="cash" <?= $payment->payment_method === 'cash' ? 'selected' : '' ?>>Cash</option>
                                    <option value="cheque" <?= $payment->payment_method === 'cheque' ? 'selected' : '' ?>>Cheque</option>
                                </select>
                            </div>

                            <!-- Bank Account (shown for bank/cheque) -->
                            <div id="bank_field">
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Bank Account <span class="text-red-500">*</span></label>
                                <select name="bank_account_id" id="bank_account_id"
                                        class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-500 focus:bg-white outline-none transition cursor-pointer">
                                    <option value="">Select Bank Account</option>
                                    <?php foreach ($bank_accounts as $account): ?>
                                        <option value="<?= $account->id ?>" <?= $payment->bank_account_id == $account->id ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($account->account_name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Employee (shown for cash) -->
                            <div id="employee_field">
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Handled By Employee</label>
                                <input type="text" name="handled_by_employee" value="<?= htmlspecialchars($payment->handled_by_employee) ?>"
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-500 focus:bg-white outline-none transition">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Reference Number</label>
                                <input type="text" name="reference_number" value="<?= htmlspecialchars($payment->reference_number) ?>" 
                                       maxlength="100" placeholder="Cheque # or Trx ID"
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-500 focus:bg-white outline-none transition">
                            </div>
                        </div>
                    </div>

                    <div class="mt-6">
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Remarks</label>
                        <textarea name="remarks" rows="2"
                                  class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-500 focus:bg-white outline-none transition"><?= htmlspecialchars($payment->remarks) ?></textarea>
                    </div>

                    <div class="mt-10 pt-6 border-t border-gray-100 flex items-center justify-between">
                        <div class="flex items-center gap-2 text-gray-400">
                            <i class="fas fa-user-shield text-xs"></i>
                            <span class="text-[10px] font-bold uppercase tracking-tighter">Superadmin Access Required</span>
                        </div>
                        <button type="submit" class="px-8 py-4 bg-primary-600 text-white rounded-xl hover:bg-primary-700 transition shadow-xl shadow-primary-500/20 font-black uppercase tracking-widest text-sm flex items-center gap-3">
                            <i class="fas fa-save"></i> Update Payment Record
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Show/hide fields based on payment method
function togglePaymentFields() {
    const method = document.getElementById('payment_method').value;
    const bankField = document.getElementById('bank_field');
    const employeeField = document.getElementById('employee_field');
    const bankSelect = document.getElementById('bank_account_id');
    
    if (method === 'bank' || method === 'cheque') {
        bankField.style.display = 'block';
        employeeField.style.display = 'none';
        bankSelect.required = true;
    } else {
        bankField.style.display = 'none';
        employeeField.style.display = 'block';
        bankSelect.required = false;
    }
}

document.getElementById('payment_method').addEventListener('change', togglePaymentFields);
togglePaymentFields(); // Initialize on page load

// Form validation
document.getElementById('editPaymentForm').addEventListener('submit', function(e) {
    const amount = parseFloat(document.querySelector('[name="amount_paid"]').value);
    
    if (amount <= 0) {
        e.preventDefault();
        alert('Amount must be greater than zero.');
        return false;
    }
    
    if (!confirm('CRITICAL ACTION:\nAre you sure you want to modify this payment?\nThis will impact bank balances and financial ledgers.')) {
        e.preventDefault();
        return false;
    }
});
</script>

<?php require_once '../templates/footer.php'; ?>
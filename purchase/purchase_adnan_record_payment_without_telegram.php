<?php
require_once __DIR__ . '/../core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle = "Record Payment";

// Get PO ID
$po_id = $_GET['po_id'] ?? null;

// Initialize managers
$po_manager = new Purchaseadnanmanager();
$payment_manager = new Purchasepaymentadnanmanager();

// Get list of POs with outstanding balance
$outstanding_pos = $po_manager->listPurchaseOrders(['payment_status' => ['unpaid', 'partial']]);

// Get bank accounts
$bank_accounts = $payment_manager->getAllBankAccounts();

// Get employees
$employees = $payment_manager->getAllEmployees();

// If PO ID provided, get PO details
$selected_po = null;
if ($po_id) {
    $selected_po = $po_manager->getPurchaseOrder($po_id);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = [
            'purchase_order_id' => $_POST['purchase_order_id'],
            'payment_date' => $_POST['payment_date'],
            'amount_paid' => $_POST['amount_paid'],
            'payment_method' => $_POST['payment_method'],
            'bank_account_id' => $_POST['bank_account_id'] ?? null,
            'reference_number' => $_POST['reference_number'] ?? null,
            'payment_type' => $_POST['payment_type'] ?? 'regular',
            'handled_by_employee' => $_POST['handled_by_employee'] ?? null,
            'remarks' => $_POST['remarks'] ?? null
        ];

        $result = $payment_manager->recordPayment($data);
        
        if ($result['success']) {
            $_SESSION['success'] = $result['message'] . " Voucher: {$result['voucher_number']}";
            redirect('purchase/purchase_adnan_view_po.php?id=' . $data['purchase_order_id']);
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
                <i class="fas fa-money-bill-wave text-yellow-600"></i> Record Payment
            </h2>
            <nav class="text-sm text-gray-600 mt-1">
                <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="hover:text-primary-600">Purchase (Adnan)</a>
                <span class="mx-2">›</span>
                <span>Record Payment</span>
            </nav>
        </div>
        <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

```
<?php if (isset($_SESSION['error'])): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-6 flex items-center justify-between">
        <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
        <button onclick="this.parentElement.remove()" class="text-red-600 hover:text-red-800">
            <i class="fas fa-times"></i>
        </button>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
        <div class="bg-white rounded-lg shadow">
            <div class="bg-yellow-500 text-white px-6 py-4 rounded-t-lg">
                <h5 class="font-semibold">Payment Details</h5>
            </div>
            <div class="p-6">
                <form method="POST" id="paymentForm">
                    <!-- Purchase Order Selection -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Purchase Order <span class="text-red-500">*</span>
                        </label>
                        <select name="purchase_order_id" id="purchase_order_id" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500" 
                                required>
                            <option value="">-- Select Purchase Order --</option>
                            <?php foreach ($outstanding_pos as $po): ?>
                            <option value="<?php echo $po->id; ?>" 
                                    data-supplier="<?php echo htmlspecialchars($po->supplier_name); ?>"
                                    data-balance="<?php echo $po->balance_payable; ?>"
                                    data-received-value="<?php echo $po->total_received_value; ?>"
                                    data-paid="<?php echo $po->total_paid; ?>"
                                    <?php echo $selected_po && $selected_po->id == $po->id ? 'selected' : ''; ?>>
                                PO #<?php echo $po->po_number; ?> - <?php echo htmlspecialchars($po->supplier_name); ?> 
                                (Balance: ৳<?php echo number_format($po->balance_payable, 2); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- PO Summary -->
                    <div id="poSummary" class="mb-4 hidden">
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div class="space-y-1">
                                    <div><strong>Supplier:</strong> <span id="poSupplier"></span></div>
                                </div>
                                <div class="space-y-1 text-right">
                                    <div><strong>Received Value:</strong> ৳<span id="poReceivedValue"></span></div>
                                    <div><strong>Already Paid:</strong> ৳<span id="poPaid"></span></div>
                                    <div class="text-red-600"><strong>Balance Due:</strong> ৳<span id="poBalance"></span></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <!-- Payment Date -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Payment Date <span class="text-red-500">*</span>
                            </label>
                            <input type="date" name="payment_date" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                   value="<?php echo date('Y-m-d'); ?>" required max="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <!-- Amount -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Amount Paid (৳) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="amount_paid" id="amount_paid" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                   step="0.01" min="0.01" required>
                            <button type="button" class="text-sm text-primary-600 hover:underline mt-1" id="payFullBalance">
                                Pay Full Balance
                            </button>
                        </div>
                    </div>

                    <!-- Advance Payment Alert -->
                    <div id="advanceAlert" class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg mb-4 hidden">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Advance Payment:</strong> Amount exceeds received value. This will be recorded as an advance payment.
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <!-- Payment Method -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Payment Method <span class="text-red-500">*</span>
                            </label>
                            <select name="payment_method" id="payment_method" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500" 
                                    required>
                                <option value="">-- Select Method --</option>
                                <option value="bank">Bank Transfer/Deposit</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>

                        <!-- Payment Type -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Payment Type
                            </label>
                            <select name="payment_type" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                                <option value="regular">Regular Payment</option>
                                <option value="advance">Advance Payment</option>
                                <option value="final">Final Payment</option>
                            </select>
                        </div>
                    </div>

                    <!-- Bank Account (for bank payment) -->
                    <div id="bankAccountDiv" class="mb-4 hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Bank Account <span class="text-red-500">*</span>
                        </label>
                        <select name="bank_account_id" id="bank_account_id" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                            <option value="">-- Select Bank Account --</option>
                            <?php 
                            $current_user = getCurrentUser();
                            $can_see_balance = in_array($current_user['role'], ['Superadmin', 'Accounts']);
                            foreach ($bank_accounts as $bank): 
                            ?>
                            <option value="<?php echo $bank->id; ?>">
                                <?php echo htmlspecialchars($bank->bank_name); ?> - <?php echo htmlspecialchars($bank->account_name); ?> 
                                (<?php echo htmlspecialchars($bank->account_number); ?>)
                                <?php if ($can_see_balance): ?>
                                    - Bal: ৳<?php echo number_format($bank->current_balance, 2); ?>
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Employee (for cash payment) -->
                    <div id="employeeDiv" class="mb-4 hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Handled By (Employee) <span class="text-red-500">*</span>
                        </label>
                        <select name="handled_by_employee" id="handled_by_employee" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                            <option value="">-- Select Employee --</option>
                            <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo htmlspecialchars($emp->name); ?>">
                                <?php echo htmlspecialchars($emp->name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Reference Number -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Reference / Cheque / Transaction Number
                        </label>
                        <input type="text" name="reference_number" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                               placeholder="Transaction ID, Cheque Number, etc.">
                    </div>

                    <!-- Remarks -->
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Remarks
                        </label>
                        <textarea name="remarks" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                  placeholder="Any notes about this payment..."></textarea>
                    </div>

                    <!-- Submit Buttons -->
                    <div class="flex justify-between">
                        <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" 
                           class="border border-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-50">
                            Cancel
                        </a>
                        <button type="submit" 
                                class="bg-yellow-500 text-white px-6 py-2 rounded-lg hover:bg-yellow-600 flex items-center gap-2">
                            <i class="fas fa-save"></i> Record Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div>
        <!-- Help Card -->
        <div class="bg-white rounded-lg shadow mb-4">
            <div class="bg-blue-600 text-white px-6 py-4 rounded-t-lg">
                <h5 class="font-semibold flex items-center gap-2">
                    <i class="fas fa-info-circle"></i> Instructions
                </h5>
            </div>
            <div class="p-6">
                <h6 class="font-semibold mb-2">Payment Methods:</h6>
                <ul class="space-y-2 text-sm text-gray-700">
                    <li><strong>Bank:</strong> Transfer/deposit through bank account</li>
                    <li><strong>Cash:</strong> Direct cash payment (requires employee)</li>
                    <li><strong>Cheque:</strong> Payment by cheque</li>
                </ul>

                <hr class="my-4">

                <h6 class="font-semibold mb-2">Payment Types:</h6>
                <ul class="space-y-2 text-sm text-gray-700">
                    <li><strong>Regular:</strong> Normal payment against delivered goods</li>
                    <li><strong>Advance:</strong> Payment before goods received</li>
                    <li><strong>Final:</strong> Last payment settling balance</li>
                </ul>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="bg-white rounded-lg shadow">
            <div class="bg-gray-600 text-white px-6 py-4 rounded-t-lg">
                <h5 class="font-semibold flex items-center gap-2">
                    <i class="fas fa-chart-pie"></i> Payment Summary
                </h5>
            </div>
            <div class="p-6">
                <div class="mb-3">
                    <small class="text-gray-600">Total Outstanding POs:</small>
                    <h4 class="text-2xl font-bold"><?php echo count($outstanding_pos); ?></h4>
                </div>
                <div>
                    <small class="text-gray-600">Total Balance Due:</small>
                    <h4 class="text-2xl font-bold text-red-600">
                        ৳<?php echo number_format(array_sum(array_column($outstanding_pos, 'balance_payable')), 2); ?>
                    </h4>
                </div>
            </div>
        </div>
    </div>
</div>
```

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const poSelect = document.getElementById('purchase_order_id');
    const amountInput = document.getElementById('amount_paid');
    const paymentMethodSelect = document.getElementById('payment_method');
    const bankAccountDiv = document.getElementById('bankAccountDiv');
    const bankAccountSelect = document.getElementById('bank_account_id');
    const employeeDiv = document.getElementById('employeeDiv');
    const employeeSelect = document.getElementById('handled_by_employee');
    const poSummary = document.getElementById('poSummary');
    const advanceAlert = document.getElementById('advanceAlert');
    const payFullBalanceBtn = document.getElementById('payFullBalance');

    // Update PO summary
    poSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (this.value) {
            document.getElementById('poSupplier').textContent = option.dataset.supplier;
            document.getElementById('poReceivedValue').textContent = parseFloat(option.dataset.receivedValue).toFixed(2);
            document.getElementById('poPaid').textContent = parseFloat(option.dataset.paid).toFixed(2);
            document.getElementById('poBalance').textContent = parseFloat(option.dataset.balance).toFixed(2);
            
            poSummary.classList.remove('hidden');
        } else {
            poSummary.classList.add('hidden');
        }
        checkAdvancePayment();
    });

    // Pay full balance
    payFullBalanceBtn.addEventListener('click', function() {
        const option = poSelect.options[poSelect.selectedIndex];
        if (poSelect.value) {
            amountInput.value = parseFloat(option.dataset.balance).toFixed(2);
            checkAdvancePayment();
        }
    });

    // Show/hide fields based on payment method
    paymentMethodSelect.addEventListener('change', function() {
        // Reset
        bankAccountDiv.classList.add('hidden');
        employeeDiv.classList.add('hidden');
        bankAccountSelect.required = false;
        employeeSelect.required = false;
        
        if (this.value === 'bank') {
            bankAccountDiv.classList.remove('hidden');
            bankAccountSelect.required = true;
        } else if (this.value === 'cash') {
            employeeDiv.classList.remove('hidden');
            employeeSelect.required = true;
        }
    });

    // Check for advance payment
    function checkAdvancePayment() {
        const option = poSelect.options[poSelect.selectedIndex];
        if (poSelect.value && amountInput.value) {
            const receivedValue = parseFloat(option.dataset.receivedValue) || 0;
            const paid = parseFloat(option.dataset.paid) || 0;
            const paymentAmount = parseFloat(amountInput.value) || 0;
            
            if ((paid + paymentAmount) > receivedValue) {
                advanceAlert.classList.remove('hidden');
            } else {
                advanceAlert.classList.add('hidden');
            }
        }
    }

    amountInput.addEventListener('input', checkAdvancePayment);

    // Trigger on page load if PO already selected
    if (poSelect.value) {
        poSelect.dispatchEvent(new Event('change'));
    }
});
</script>

<?php require_once '../templates/footer.php'; ?>
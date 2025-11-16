<?php
require_once '../../core/init.php';

// Access control
$allowed_roles = ['Superadmin', 'admin', 'Transport Manager', 'dispatch-srg', 'dispatch-demra'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Log Vehicle Maintenance';
$error = null;

// --- Get Cash Accounts ---
$cash_accounts_query = "
    SELECT id, account_number, name 
    FROM chart_of_accounts 
    WHERE account_type IN ('Cash', 'Petty Cash') 
    AND status = 'active'
    ORDER BY account_number
";
$cash_accounts = $db->query($cash_accounts_query)->results();

// --- Get Bank Accounts ---
$bank_accounts_query = "
    SELECT id, account_number, name 
    FROM chart_of_accounts 
    WHERE account_type = 'Bank' 
    AND status = 'active'
    ORDER BY account_number
";
$bank_accounts = $db->query($bank_accounts_query)->results();

// --- Get Employees ---
$employees_query = "
    SELECT 
        e.id, 
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name, 
        b.name as branch_name
    FROM employees e
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE e.status = 'active'
    ORDER BY e.first_name, e.last_name
";
$employees = $db->query($employees_query)->results();

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = $db->getPdo();
    try {
        $pdo->beginTransaction();
        
        // --- Form Data ---
        $vehicle_id = (int)$_POST['vehicle_id'];
        $maintenance_date = $_POST['maintenance_date'];
        $maintenance_type = $_POST['maintenance_type'];
        $cost = floatval($_POST['cost']);
        $odometer_reading = !empty($_POST['odometer_reading']) ? floatval($_POST['odometer_reading']) : null;
        
        $payment_method = $_POST['payment_method'];
        $account_id = (int)$_POST['account_id'];
        $handled_by_employee_id = !empty($_POST['handled_by_employee_id']) ? (int)$_POST['handled_by_employee_id'] : null;

        // --- Validation ---
        if ($vehicle_id <= 0) throw new Exception("Please select a vehicle");
        if ($cost <= 0) throw new Exception("Total cost must be greater than 0");
        if ($account_id <= 0) throw new Exception("Please select a payment account");
        if (empty($maintenance_type)) throw new Exception("Maintenance type is required");

        // --- Get Supporting Data ---
        $vehicle = $db->query("SELECT * FROM vehicles WHERE id = ?", [$vehicle_id])->first();
        if (!$vehicle) throw new Exception("Vehicle not found");
        
        $selected_account = $db->query("SELECT * FROM chart_of_accounts WHERE id = ?", [$account_id])->first();
        if (!$selected_account) throw new Exception("Payment account not found");
        
        // --- Use 'name' for reliability ---
        $expense_account = $db->query(
            "SELECT id FROM chart_of_accounts WHERE name = 'Vehicle Maintenance Expense' AND status = 'active' LIMIT 1"
        )->first();
        
        if (!$expense_account) {
            throw new Exception("Account 'Vehicle Maintenance Expense' not found or inactive. Please create this account in the Chart of Accounts.");
        }
        
        $employee_name = 'N/A';
        if ($handled_by_employee_id) {
            $emp = $db->query("SELECT CONCAT(first_name, ' ', last_name) AS employee_name FROM employees WHERE id = ?", [$handled_by_employee_id])->first();
            $employee_name = $emp ? $emp->employee_name : 'N/A';
        }

        // --- 'maintenance_logs' INSERT (This part is correct) ---
        $log_data = [
            'vehicle_id' => $vehicle_id,
            'maintenance_date' => $maintenance_date,
            'maintenance_type' => $maintenance_type,
            'description' => trim($_POST['description']) ?: null,
            'cost' => $cost,
            'service_provider' => trim($_POST['service_provider']) ?: null,
            'odometer_reading' => $odometer_reading,
            'next_service_date' => !empty($_POST['next_service_date']) ? $_POST['next_service_date'] : null,
            'next_service_km' => !empty($_POST['next_service_km']) ? (int)$_POST['next_service_km'] : null,
            'invoice_number' => trim($_POST['invoice_number']) ?: null,
            'notes' => trim($_POST['notes']) ?: null,
            'created_by_user_id' => $user_id
        ];
        
        $log_id = $db->insert('maintenance_logs', $log_data);
        if (!$log_id) throw new Exception("Failed to create maintenance log");
        
        // Update vehicle mileage if provided
        if ($odometer_reading && $odometer_reading > ($vehicle->current_mileage ?? 0)) {
            $db->query(
                "UPDATE vehicles SET current_mileage = ? WHERE id = ?",
                [$odometer_reading, $vehicle_id]
            );
        }
        
        // ==========================================
        // DOUBLE-ENTRY ACCOUNTING
        // ==========================================
        
        $journal_description = "Vehicle Maintenance for " . $vehicle->vehicle_number . 
                               " - " . $maintenance_type . " (Cost: ৳" . number_format($cost, 2) . ")" .
                               " via " . $selected_account->name;
        
        $journal_entry_id = $db->insert('journal_entries', [
            'transaction_date' => $maintenance_date,
            'description' => $journal_description,
            'related_document_type' => 'maintenance_logs',
            'related_document_id' => $log_id,
            'responsible_employee_id' => $handled_by_employee_id,
            'created_by_user_id' => $user_id
        ]);
        
        if (!$journal_entry_id) throw new Exception("Failed to create journal entry");
        
        // --- *** THE FIX IS HERE *** ---
        // We now use 'entry_type' and 'amount' to match your ujjalfmc_saas.sql schema
        
        // DEBIT: Maintenance Expense
        $db->insert('transaction_lines', [
            'journal_entry_id' => $journal_entry_id,
            'account_id' => $expense_account->id,
            'entry_type' => 'debit',
            'amount' => $cost,
            'description' => "Maintenance expense - " . $vehicle->vehicle_number
        ]);
        
        // CREDIT: Cash/Bank Account
        $db->insert('transaction_lines', [
            'journal_entry_id' => $journal_entry_id,
            'account_id' => $account_id,
            'entry_type' => 'credit',
            'amount' => $cost,
            'description' => "Payment via " . $selected_account->name
        ]);
        
        $pdo->commit();
        
        $_SESSION['success_flash'] = "Maintenance log added! Cost: ৳" . number_format($cost, 2) . 
                                     " paid from " . $selected_account->name;
        header('Location: index.php');
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        error_log("Maintenance log error: [User: $user_id] " . $e->getMessage());
    }
}

// Get active vehicles
$vehicles = $db->query(
    "SELECT id, vehicle_number, current_mileage 
     FROM vehicles 
     WHERE status IN ('active', 'Active', 'Maintenance')
     ORDER BY vehicle_number"
)->results();

require_once '../../templates/header.php';
?>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="bg-white rounded-lg shadow-md p-6" style="background-image: url('data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' viewBox=\'0 0 60 60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'none\' fill-rule=\'evenodd\'%3E%3Cg fill=\'\%23f0f9ff\' fill-opacity=\'0.4\'%3E%3Cpath d=\'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E');">
    <div class="flex justify-between items-center mb-6 border-b pb-4">
        <h1 class="text-2xl font-bold text-gray-900">🔧 Log Vehicle Maintenance</h1>
        <a href="index.php" class="px-4 py-2 border rounded-lg hover:bg-gray-50 bg-white shadow-sm">
            <i class="fas fa-arrow-left mr-2"></i>Back
        </a>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg shadow">
        <p class="font-bold">Error</p>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6" id="maintenanceForm">
        
        <!-- Service Details -->
        <div class="bg-white/90 p-4 rounded-lg shadow-md border">
            <h3 class="text-lg font-medium text-gray-900 mb-4 border-b pb-2">Service Details</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle *</label>
                    <select name="vehicle_id" id="vehicle_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">-- Select Vehicle --</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                        <option value="<?php echo $vehicle->id; ?>" data-mileage="<?php echo $vehicle->current_mileage; ?>">
                            <?php echo htmlspecialchars($vehicle->vehicle_number); ?> 
                            (<?php echo number_format($vehicle->current_mileage, 0); ?> km)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Maintenance Date *</label>
                    <input type="date" name="maintenance_date" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           value="<?php echo date('Y-m-d'); ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Maintenance Type *</label>
                    <input type="text" name="maintenance_type" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="e.g., Oil Change, Brake Repair">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Service Provider</label>
                    <input type="text" name="service_provider"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="e.g., Local Garage">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" rows="2" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                              placeholder="Describe the service performed..."></textarea>
                </div>
            </div>
        </div>

        <!-- Cost & Odometer -->
        <div class="bg-white/90 p-4 rounded-lg shadow-md border">
            <h3 class="text-lg font-medium text-gray-900 mb-4 border-b pb-2">Cost & Odometer</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Total Cost (৳) *</label>
                    <input type="number" step="0.01" name="cost" id="cost" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="5000.00" min="0.01" oninput="updateAccountingPreview()">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Odometer Reading (km)</label>
                    <input type="number" step="1" name="odometer_reading" id="odometer_reading"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="15000">
                    <p class="text-xs text-gray-500 mt-1" id="current_mileage_info"></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Next Service Date</label>
                    <input type="date" name="next_service_date"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Next Service (km)</label>
                    <input type="number" step="1" name="next_service_km"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="20000">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Invoice Number</label>
                    <input type="text" name="invoice_number"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="INV-12345">
                </div>
            </div>
        </div>
        
        <!-- Payment Information -->
        <div class="bg-white/90 p-4 rounded-lg shadow-md border">
            <h3 class="text-lg font-medium text-gray-900 mb-4 border-b pb-2">Payment Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method *</label>
                    <select name="payment_method" id="payment_method" required 
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                            onchange="updateAccountDropdown()">
                        <option value="">-- Select Payment Method --</option>
                        <option value="Cash">Cash (Petty Cash)</option>
                        <option value="Bank">Bank Account</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Account *</label>
                    <select name="account_id" id="account_id" required 
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                            onchange="updateAccountName()">
                        <option value="">-- Select payment method first --</option>
                    </select>
                </div>
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Handled By (Employee) *</label>
                    <select name="handled_by_employee_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($employees as $employee): ?>
                        <option value="<?php echo $employee->id; ?>">
                            <?php echo htmlspecialchars($employee->employee_name); ?>
                            <?php if ($employee->branch_name): ?>
                                - <?php echo htmlspecialchars($employee->branch_name); ?>
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Select the employee who handled this transaction</p>
                </div>
            </div>
        </div>
        
        <!-- Notes -->
        <div class="bg-white/90 p-4 rounded-lg shadow-md border">
            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
            <textarea name="notes" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                      placeholder="Any additional information about this service..."></textarea>
        </div>
        
        <!-- Accounting Preview -->
        <div class="bg-yellow-50 border-2 border-yellow-300 rounded-lg p-6">
            <h4 class="font-bold text-gray-800 mb-4 text-lg">
                <i class="fas fa-calculator mr-2"></i>Accounting Preview (Double-Entry)
            </h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-red-50 border-2 border-red-300 rounded-lg p-4">
                    <p class="text-sm text-gray-600 font-semibold mb-2">DEBIT (Expense Increase)</p>
                    <p class="text-gray-900 font-medium">Vehicle Maintenance Expense</p>
                    <p class="text-2xl font-bold text-red-600 mt-2" id="debit_amount">৳0.00</p>
                </div>
                <div class="bg-green-50 border-2 border-green-300 rounded-lg p-4">
                    <p class="text-sm text-gray-600 font-semibold mb-2">CREDIT (Asset Decrease)</p>
                    <p class="text-gray-900 font-medium" id="credit_account_name">Select payment account</p>
                    <p class="text-2xl font-bold text-green-600 mt-2" id="credit_amount">৳0.00</p>
                </div>
            </div>
        </div>
        
        <!-- Submit -->
        <div class="flex justify-end gap-3 pt-6 border-t mt-6">
            <a href="index.php" class="px-6 py-2 border rounded-lg hover:bg-gray-50 bg-white shadow-sm">
                Cancel
            </a>
            <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium shadow-sm">
                <i class="fas fa-save mr-2"></i>Log Maintenance
            </button>
        </div>
    </form>
</div>

</div>

<script>
// Store account data
const cashAccounts = <?php echo json_encode($cash_accounts); ?>;
const bankAccounts = <?php echo json_encode($bank_accounts); ?>;

// Auto-set odometer based on vehicle
document.getElementById('vehicle_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    if (this.value) {
        const mileage = selectedOption.dataset.mileage;
        document.getElementById('odometer_reading').value = mileage;
        document.getElementById('current_mileage_info').textContent = `Current recorded mileage: ${parseFloat(mileage).toLocaleString()} km`;
    }
});

// Update account dropdown based on payment method
function updateAccountDropdown() {
    const method = document.getElementById('payment_method').value;
    const accountSelect = document.getElementById('account_id');
    
    accountSelect.innerHTML = '<option value="">-- Select Account --</option>';
    
    let accounts = [];
    if (method === 'Cash') {
        accounts = cashAccounts;
    } else if (method === 'Bank') {
        accounts = bankAccounts;
    }
    
    accounts.forEach(account => {
        const option = document.createElement('option');
        option.value = account.id;
        option.textContent = `${account.account_number} - ${account.name}`;
        option.dataset.name = account.name;
        accountSelect.appendChild(option);
    });
    
    updateAccountName();
}

// Update account name in preview
function updateAccountName() {
    const accountSelect = document.getElementById('account_id');
    const selectedOption = accountSelect.options[accountSelect.selectedIndex];
    const creditAccountName = document.getElementById('credit_account_name');
    
    if (accountSelect.value) {
        creditAccountName.textContent = selectedOption.dataset.name;
    } else {
        creditAccountName.textContent = 'Select payment account';
    }
}

// Calculate total cost
function updateAccountingPreview() {
    const cost = parseFloat(document.getElementById('cost').value) || 0;
    const formatted = '৳' + cost.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    
    document.getElementById('debit_amount').textContent = formatted;
    document.getElementById('credit_amount').textContent = formatted;
}

// Initialize
updateAccountingPreview();
</script>

<?php require_once '../../templates/footer.php'; ?>
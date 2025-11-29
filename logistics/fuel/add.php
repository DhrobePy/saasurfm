<?php
require_once '../../core/init.php';

// Security: Restrict access
$allowed_roles = ['Superadmin', 'admin', 'Transport Manager', 'dispatch-srg', 'dispatch-demra'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Log Fuel';
$error = null;

// --- FIXED QUERY ---
// Get cash accounts (Petty Cash or Cash). Removed 'current_balance' as it does not exist.
$cash_accounts_query = "
    SELECT id, account_number, name 
    FROM chart_of_accounts 
    WHERE account_type IN ('Cash', 'Petty Cash') 
    AND status = 'active'
    ORDER BY account_number
";
$cash_accounts = $db->query($cash_accounts_query)->results();

// --- FIXED QUERY ---
// Get bank accounts. Removed 'current_balance'.
$bank_accounts_query = "
    SELECT id, account_number, name 
    FROM chart_of_accounts 
    WHERE account_type = 'Bank' 
    AND status = 'active'
    ORDER BY account_number
";
$bank_accounts = $db->query($bank_accounts_query)->results();

// --- FIXED QUERY ---
// Get employees. Matched columns to your new 'employees' table schema.
$employees_query = "
    SELECT 
        e.id, 
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name, 
        b.name as branch_name
    FROM employees e
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE e.status = 'active' -- Changed from 'Active' to 'active'
    ORDER BY e.first_name, e.last_name
";
$employees = $db->query($employees_query)->results();


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = $db->getPdo();
    try {
        $pdo->beginTransaction();
        
        $vehicle_id = (int)$_POST['vehicle_id'];
        $trip_id = !empty($_POST['trip_id']) ? (int)$_POST['trip_id'] : null;
        $quantity = floatval($_POST['quantity_liters']);
        $price_per_liter = floatval($_POST['price_per_liter']);
        $total_cost = $quantity * $price_per_liter; // We need this for the journal
        $account_id = (int)$_POST['account_id'];
        $handled_by_employee_id = !empty($_POST['handled_by_employee_id']) ? (int)$_POST['handled_by_employee_id'] : null;
        $fuel_date = $_POST['fuel_date']; 
        
        // Validate inputs
        if ($vehicle_id <= 0) throw new Exception("Please select a vehicle");
        if ($quantity <= 0) throw new Exception("Quantity must be greater than 0");
        if ($price_per_liter <= 0) throw new Exception("Price must be greater than 0");
        if ($account_id <= 0) throw new Exception("Please select a payment account");
        
        // Get vehicle details
        $vehicle = $db->query("SELECT * FROM vehicles WHERE id = ?", [$vehicle_id])->first();
        if (!$vehicle) throw new Exception("Vehicle not found");
        
        // Get selected account details
        $selected_account = $db->query("SELECT * FROM chart_of_accounts WHERE id = ?", [$account_id])->first();
        if (!$selected_account) throw new Exception("Payment account not found");
        
        // Get Fuel Expense account
        $fuel_expense_account = $db->query(
            "SELECT id FROM chart_of_accounts WHERE name = 'Fuel Expense' AND status = 'active' LIMIT 1"
        )->first();
        
        if (!$fuel_expense_account) {
            // Note: Your schema doesn't have a default 'Fuel Expense' account, it must be created manually.
            // Using '5010' from original code is unsafe, checking by name is better.
            throw new Exception("Fuel Expense account not found. Please create an 'Expense' account named 'Fuel Expense'.");
        }
        
        // Get employee name for 'filled_by'
        $filled_by_name = 'N/A';
        if ($handled_by_employee_id) {
            // --- FIX 1: Use CONCAT(first_name, ' ', last_name) ---
            $emp = $db->query("SELECT CONCAT(first_name, ' ', last_name) AS employee_name FROM employees WHERE id = ?", [$handled_by_employee_id])->first();
            $filled_by_name = $emp ? $emp->employee_name : 'N/A';
        }

        // --- FIXED FUEL LOG INSERT ---
        // Matches your 'fuel_logs' schema from ujjalfmc_saas-11.sql
        $fuel_data = [
            'vehicle_id' => $vehicle_id,
            'trip_id' => $trip_id,
            'fuel_date' => $fuel_date, // Changed from fill_date
            'fuel_type' => $_POST['fuel_type'],
            'quantity_liters' => $quantity,
            'price_per_liter' => $price_per_liter,
            // 'total_cost' is a GENERATED column and MUST NOT be in the insert
            'odometer_reading' => floatval($_POST['odometer_reading'] ?? 0),
            'filled_by' => $filled_by_name, // Use the name for the varchar column
            'station_name' => trim($_POST['fuel_station']) ?: null, // Changed from fuel_station
            'receipt_number' => trim($_POST['receipt_number']) ?: null,
            'notes' => trim($_POST['notes']) ?: null,
            'created_by_user_id' => $user_id
        ];
        
        $fuel_log_id = $db->insert('fuel_logs', $fuel_data);
        if (!$fuel_log_id) throw new Exception("Failed to create fuel log");
        
        // Update vehicle mileage
        if ($fuel_data['odometer_reading'] > 0) {
            $db->query(
                "UPDATE vehicles SET current_mileage = ? WHERE id = ?",
                [$fuel_data['odometer_reading'], $vehicle_id]
            );
        }
        
        // ==========================================
        // DOUBLE-ENTRY ACCOUNTING
        // ==========================================
        
        // Create Journal Entry
        $journal_description = "Fuel purchase for vehicle " . $vehicle->vehicle_number . 
                               " - " . number_format($quantity, 2) . "L @ ৳" . number_format($price_per_liter, 2) . "/L" .
                               " via " . $selected_account->name;
        
        $journal_entry_id = $db->insert('journal_entries', [
            'transaction_date' => $fuel_date,
            'description' => $journal_description,
            'related_document_type' => 'fuel_logs',
            'related_document_id' => $fuel_log_id,
            'responsible_employee_id' => $handled_by_employee_id,
            'created_by_user_id' => $user_id
        ]);
        
        if (!$journal_entry_id) throw new Exception("Failed to create journal entry");
        
        // --- FIXED TRANSACTION LINES INSERT ---
        // Uses debit_amount and credit_amount, as per your schema
        
        // DEBIT: Fuel Expense (increase expense)
        $db->insert('transaction_lines', [
            'journal_entry_id' => $journal_entry_id,
            'account_id' => $fuel_expense_account->id,
            'debit_amount' => $total_cost,
            'credit_amount' => 0.00,
            'description' => "Fuel expense - " . $vehicle->vehicle_number
        ]);
        
        // CREDIT: Cash/Bank Account (decrease asset)
        $db->insert('transaction_lines', [
            'journal_entry_id' => $journal_entry_id,
            'account_id' => $account_id,
            'debit_amount' => 0.00,
            'credit_amount' => $total_cost,
            'description' => "Payment via " . $selected_account->name
        ]);
        
        // ==========================================
        // REMOVED 'UPDATE chart_of_accounts SET current_balance = ...'
        // 'current_balance' does not exist in your schema.
        // ==========================================
        
        // ==========================================
        // REMOVED 'cash_ledger' and 'bank_ledger' blocks
        // as those tables do not exist in your schema.
        // ==========================================
        
        $pdo->commit();
        
        // ============================================
        // TELEGRAM NOTIFICATION - FUEL PURCHASE
        // ============================================
        try {
            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                require_once '../../core/classes/TelegramNotifier.php';
                $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                
                // Get user name
                $user_name = 'System User';
                if ($user_id) {
                    $user_info = $db->query("SELECT display_name FROM users WHERE id = ?", [$user_id])->first();
                    $user_name = $user_info ? $user_info->display_name : 'System User';
                }
                
                // Get trip info if available
                $trip_info = '';
                if ($trip_id) {
                    $trip = $db->query("SELECT id FROM trip_assignments WHERE id = ?", [$trip_id])->first();
                    $trip_info = $trip ? "Trip #" . $trip->id : '';
                }
                
                // Prepare fuel data
                $fuelData = [
                    'vehicle_number' => $vehicle->vehicle_number,
                    'fuel_type' => $_POST['fuel_type'],
                    'quantity' => floatval($quantity),
                    'price_per_liter' => floatval($price_per_liter),
                    'total_cost' => floatval($total_cost),
                    'fuel_date' => date('d M Y', strtotime($fuel_date)),
                    'odometer_reading' => floatval($_POST['odometer_reading'] ?? 0),
                    'fuel_station' => trim($_POST['fuel_station']) ?: 'N/A',
                    'receipt_number' => trim($_POST['receipt_number']) ?: '',
                    'payment_method' => $selected_account->name,
                    'handled_by' => $filled_by_name,
                    'trip_info' => $trip_info,
                    'notes' => trim($_POST['notes']) ?: '',
                    'logged_by' => $user_name
                ];
                
                // Send notification
                $result = $telegram->sendFuelPurchaseNotification($fuelData);
                
                if ($result['success']) {
                    error_log("✓ Telegram fuel purchase notification sent for vehicle: " . $vehicle->vehicle_number);
                } else {
                    error_log("✗ Telegram fuel notification failed: " . json_encode($result['response']));
                }
            }
        } catch (Exception $e) {
            error_log("✗ Telegram fuel notification error: " . $e->getMessage());
        }
        // END TELEGRAM NOTIFICATION
        
        $_SESSION['success_flash'] = "Fuel log added successfully! Cost: ৳" . number_format($total_cost, 2) . 
                                     " paid from " . $selected_account->name;
        header('Location: index.php'); // Redirect to a listing page
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        error_log("Fuel log error: " . $e->getMessage());
    }
}

// Get active vehicles
$vehicles = $db->query(
    "SELECT id, vehicle_number, fuel_type, current_mileage 
     FROM vehicles 
     WHERE status IN ('active', 'Active', 'Maintenance')
     ORDER BY vehicle_number"
)->results();

// Get active drivers
$drivers = $db->query(
    "SELECT id, driver_name 
     FROM drivers 
     WHERE status = 'active' OR status = 'Active'
     ORDER BY driver_name"
)->results();

// Get recent trips (last 30 days)
$recent_trips = $db->query(
    "SELECT ta.id, ta.trip_date, v.vehicle_number, ta.status
     FROM trip_assignments ta
     JOIN vehicles v ON ta.vehicle_id = v.id
     WHERE ta.trip_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     ORDER BY ta.trip_date DESC
     LIMIT 50"
)->results();

require_once '../../templates/header.php';
?>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="bg-white rounded-lg shadow-md p-6">
    <div class="flex justify-between items-center mb-6 border-b pb-4">
        <h1 class="text-2xl font-bold text-gray-900">⛽ Log Fuel Purchase</h1>
        <a href="index.php" class="px-4 py-2 border rounded-lg hover:bg-gray-50">
            <i class="fas fa-arrow-left mr-2"></i>Back
        </a>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg">
        <p class="font-bold">Error</p>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6" id="fuelForm">
        
        <!-- Vehicle Selection -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 mb-4">Vehicle Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle *</label>
                    <select name="vehicle_id" id="vehicle_id" required class="w-full px-4 py-2 border rounded-lg">
                        <option value="">-- Select Vehicle --</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                        <option value="<?php echo $vehicle->id; ?>" 
                                data-fuel-type="<?php echo $vehicle->fuel_type; ?>"
                                data-mileage="<?php echo $vehicle->current_mileage; ?>">
                            <?php echo htmlspecialchars($vehicle->vehicle_number); ?> 
                            (<?php echo $vehicle->fuel_type; ?>, <?php echo number_format($vehicle->current_mileage, 0); ?> km)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Driver</label>
                    <select name="driver_id" class="w-full px-4 py-2 border rounded-lg">
                        <option value="">-- Select Driver (optional) --</option>
                        <?php foreach ($drivers as $driver): ?>
                        <option value="<?php echo $driver->id; ?>">
                            <?php echo htmlspecialchars($driver->driver_name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Related Trip (Optional)</label>
                    <select name="trip_id" class="w-full px-4 py-2 border rounded-lg">
                        <option value="">-- Select Trip (optional) --</option>
                        <?php foreach ($recent_trips as $trip): ?>
                        <option value="<?php echo $trip->id; ?>">
                            Trip #<?php echo $trip->id; ?> - <?php echo htmlspecialchars($trip->vehicle_number); ?> 
                            (<?php echo date('M j, Y', strtotime($trip->trip_date)); ?>) - <?php echo $trip->status; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Fuel Details -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 mb-4">Fuel Details</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fill Date *</label>
                    <input type="date" name="fuel_date" required
                           class="w-full px-4 py-2 border rounded-lg"
                           value="<?php echo date('Y-m-d'); ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fill Time</label>
                    <input type="time" name="fill_time"
                           class="w-full px-4 py-2 border rounded-lg"
                           value="<?php echo date('H:i'); ?>">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fuel Type *</label>
                    <select name="fuel_type" id="fuel_type" required class="w-full px-4 py-2 border rounded-lg">
                        <option value="">-- Select --</option>
                        <option value="Diesel">Diesel</option>
                        <option value="Petrol">Petrol</option>
                        <option value="CNG">CNG</option>
                        <option value="Electric">Electric</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fuel Station</label>
                    <input type="text" name="fuel_station"
                           class="w-full px-4 py-2 border rounded-lg"
                           placeholder="e.g., Padma Oil Station, Bashundhara">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Quantity (Liters) *</label>
                    <input type="number" step="0.01" name="quantity_liters" id="quantity_liters" required
                           class="w-full px-4 py-2 border rounded-lg"
                           placeholder="50.00"
                           min="0.01"
                           oninput="calculateTotal()">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Price per Liter (৳) *</label>
                    <input type="number" step="0.01" name="price_per_liter" id="price_per_liter" required
                           class="w-full px-4 py-2 border rounded-lg"
                           placeholder="110.00"
                           min="0.01"
                           oninput="calculateTotal()">
                </div>
                
                <div class="md:col-span-2">
                    <div class="bg-gradient-to-r from-blue-50 to-green-50 border-2 border-blue-300 rounded-lg p-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Total Cost</Tlabel>
                        <p class="text-4xl font-bold text-blue-600" id="total_cost_display">৳0.00</p>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Odometer Reading (km)</label>
                    <input type="number" step="0.01" name="odometer_reading" id="odometer_reading"
                           class="w-full px-4 py-2 border rounded-lg"
                           placeholder="15000">
                    <p class="text-xs text-gray-500 mt-1" id="current_mileage_info"></p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Receipt Number</label>
                    <input type="text" name="receipt_number"
                           class="w-full px-4 py-2 border rounded-lg"
                           placeholder="REC-12345">
                </div>
            </div>
        </div>
        
        <!-- Payment Information -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 mb-4">Payment Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method *</label>
                    <select name="payment_method" id="payment_method" required 
                            class="w-full px-4 py-2 border rounded-lg"
                            onchange="updateAccountDropdown()">
                        <option value="">-- Select Payment Method --</option>
                        <option value="Cash">Cash (Petty Cash)</option>
                        <option value="Bank">Bank Account</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Account *</label>
                    <select name="account_id" id="account_id" required 
                            class="w-full px-4 py-2 border rounded-lg"
                            onchange="updateAccountBalance()">
                        <option value="">-- Select account first --</option>
                    </select>
                    <!-- Removed balance info paragraph, as current_balance doesn't exist -->
                    <p class="text-xs text-gray-500 mt-1" id="account_balance_info"></p> 
                </div>
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Handled By (Employee) *</label>
                    <select name="handled_by_employee_id" required class="w-full px-4 py-2 border rounded-lg">
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($employees as $employee): ?>
                        <option value="<?php echo $employee->id; ?>">
                            <?php echo htmlspecialchars($employee->employee_name); ?>
                            <?php if (!empty($employee->emp_code)): // This field no longer exists, so this will be skipped (which is fine) ?>
                                (<?php echo htmlspecialchars($employee->emp_code); ?>)
                            <?php endif; ?>
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
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
            <textarea name="notes" rows="3" class="w-full px-4 py-2 border rounded-lg"
                      placeholder="Any additional information about this fuel purchase..."></textarea>
        </div>
        
        <!-- Accounting Preview -->
        <div class="bg-yellow-50 border-2 border-yellow-300 rounded-lg p-6">
            <h4 class="font-bold text-gray-800 mb-4 text-lg">
                <i class="fas fa-calculator mr-2"></i>Accounting Preview (Double-Entry)
            </h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-red-50 border-2 border-red-300 rounded-lg p-4">
                    <p class="text-sm text-gray-600 font-semibold mb-2">DEBIT (Expense Increase)</p>
                    <p class="text-gray-900 font-medium">Fuel Expense</p>
                    <p class="text-2xl font-bold text-red-600 mt-2" id="debit_amount">৳0.00</p>
                </div>
                <div class="bg-green-50 border-2 border-green-300 rounded-lg p-4">
                    <p class="text-sm text-gray-600 font-semibold mb-2">CREDIT (Asset Decrease)</p>
                    <p class="text-gray-900 font-medium" id="credit_account_name">Select payment account</p>
                    <p class="text-2xl font-bold text-green-600 mt-2" id="credit_amount">৳0.00</p>
                </div>
            </div>
            <div class="mt-4 p-3 bg-blue-50 border border-blue-300 rounded text-sm">
                <p class="font-semibold text-blue-900">Ledger Impact:</p>
                <p class="text-blue-800" id="ledger_impact">This transaction will be recorded in the general journal.</p>
            </div>
        </div>
        
        <!-- Submit -->
        <div class="flex justify-end gap-3 pt-6 border-t">
            <a href="index.php" class="px-6 py-2 border rounded-lg hover:bg-gray-50">
                Cancel
            </a>
            <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium">
                <i class="fas fa-save mr-2"></i>Log Fuel Purchase
            </button>
        </div>
    </form>
</div>

</div>

<script>
// Store account data
const cashAccounts = <?php echo json_encode($cash_accounts); ?>;
const bankAccounts = <?php echo json_encode($bank_accounts); ?>;

// Auto-set fuel type based on vehicle
document.getElementById('vehicle_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    if (this.value) {
        const fuelType = selectedOption.dataset.fuelType;
        const mileage = selectedOption.dataset.mileage;
        
        document.getElementById('fuel_type').value = fuelType;
        document.getElementById('odometer_reading').value = mileage;
        document.getElementById('current_mileage_info').textContent = `Current recorded mileage: ${parseFloat(mileage).toLocaleString()} km`;
    }
});

// Update account dropdown based on payment method
function updateAccountDropdown() {
    const method = document.getElementById('payment_method').value;
    const accountSelect = document.getElementById('account_id');
    const ledgerImpact = document.getElementById('ledger_impact');
    
    // Clear existing options
    accountSelect.innerHTML = '<option value="">-- Select Account --</option>';
    
    let accounts = [];
    let ledgerType = 'This transaction will be recorded in the general journal.';
    
    if (method === 'Cash') {
        accounts = cashAccounts;
        ledgerType = 'This transaction will debit Fuel Expense and credit the selected Cash account.';
    } else if (method === 'Bank') {
        accounts = bankAccounts;
        ledgerType = 'This transaction will debit Fuel Expense and credit the selected Bank account.';
    }
    
    ledgerImpact.textContent = ledgerType;
    
    // Populate dropdown
    accounts.forEach(account => {
        const option = document.createElement('option');
        option.value = account.id;
        // Use 'name' and 'account_number' from the corrected query
        // REMOVED balance from text content
        option.textContent = `${account.account_number} - ${account.name}`;
        option.dataset.name = account.name;
        accountSelect.appendChild(option);
    });
    
    // Clear credit account name
    document.getElementById('credit_account_name').textContent = 'Select payment account';
}

// Update account balance info
function updateAccountBalance() {
    const accountSelect = document.getElementById('account_id');
    const selectedOption = accountSelect.options[accountSelect.selectedIndex];
    const balanceInfo = document.getElementById('account_balance_info');
    const creditAccountName = document.getElementById('credit_account_name');
    
    if (accountSelect.value) {
        const accountName = selectedOption.dataset.name;
        creditAccountName.textContent = accountName;
        
        // Clear any previous balance info
        balanceInfo.textContent = ''; 
        balanceInfo.classList.remove('text-red-600', 'font-bold');
    }
}

// Calculate total cost
function calculateTotal() {
    const quantity = parseFloat(document.getElementById('quantity_liters').value) || 0;
    const price = parseFloat(document.getElementById('price_per_liter').value) || 0;
    const total = quantity * price;
    
    const formatted = '৳' + total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    document.getElementById('total_cost_display').textContent = formatted;
    document.getElementById('debit_amount').textContent = formatted;
    document.getElementById('credit_amount').textContent = formatted;
    
    // Update balance warning (which is now just updating the name)
    updateAccountBalance();
    
    return total;
}

// Initialize
calculateTotal();
</script>

<?php require_once '../../templates/footer.php'; ?>
<?php
require_once '../../core/init.php';

// Access control
$allowed_roles = ['Superadmin', 'admin', 'Transport Manager', 'Accounts'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Add Vehicle Rental';
$error = null;
$edit_mode = false;
$rental = null;

// Check for Edit Mode
if (isset($_GET['id'])) {
    $edit_mode = true;
    $rental_id = (int)$_GET['id'];
    $rental = $db->query("SELECT * FROM vehicle_rentals WHERE id = ?", [$rental_id])->first();
    if ($rental) {
        $pageTitle = 'Edit Rental Contract';
    } else {
        $_SESSION['error_flash'] = 'Rental record not found.';
        header('Location: index.php');
        exit();
    }
}

// --- Get Form Data ---
$vehicles = $db->query("SELECT id, vehicle_number FROM vehicles WHERE status = 'Active' ORDER BY vehicle_number")->results();
$customers = $db->query("SELECT id, name, business_name FROM customers WHERE status = 'active' ORDER BY name")->results();
$cash_accounts = $db->query("SELECT id, account_number, name FROM chart_of_accounts WHERE account_type IN ('Cash', 'Petty Cash') AND status = 'active'")->results();
$bank_accounts = $db->query("SELECT id, account_number, name FROM chart_of_accounts WHERE account_type = 'Bank' AND status = 'active'")->results();


// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = $db->getPdo();
    try {
        $pdo->beginTransaction();
        
        // --- Form Data ---
        $vehicle_id = (int)$_POST['vehicle_id'];
        $customer_id = (int)$_POST['customer_id'];
        $rental_type = $_POST['rental_type'];
        $start_datetime = $_POST['start_date'] . ' ' . $_POST['start_time'];
        $end_datetime = $_POST['end_date'] . ' ' . $_POST['end_time'];
        $rate = (float)$_POST['rate'];
        $total_amount = (float)$_POST['total_amount'];
        $status = $_POST['status'];
        $notes = trim($_POST['notes']) ?: null;

        // --- Validation ---
        if ($vehicle_id <= 0) throw new Exception("Please select a vehicle.");
        if ($customer_id <= 0) throw new Exception("Please select a customer.");
        if ($total_amount <= 0) throw new Exception("Total amount must be greater than 0.");
        if (strtotime($end_datetime) < strtotime($start_datetime)) {
            throw new Exception("End date cannot be before the start date.");
        }
        
        // --- Get Supporting Data for Accounting ---
        $vehicle = $db->query("SELECT vehicle_number FROM vehicles WHERE id = ?", [$vehicle_id])->first();
        $customer = $db->query("SELECT name FROM customers WHERE id = ?", [$customer_id])->first();

        // --- Get Accounting Accounts (from ujjalfmc_saas.sql schema) ---
        $ar_account = $db->query("SELECT id FROM chart_of_accounts WHERE account_number = '1120' AND status = 'active' LIMIT 1")->first();
        $rental_income_account = $db->query("SELECT id FROM chart_of_accounts WHERE name = 'Vehicle Rental Income' AND status = 'active' LIMIT 1")->first();
        
        if (!$ar_account) throw new Exception("Critical Error: 'Accounts Receivable (1120)' account not found.");
        if (!$rental_income_account) throw new Exception("Critical Error: 'Vehicle Rental Income (4020)' account not found. Please run the setup SQL.");

        // =============================================
        //  START ACCOUNTING & DATABASE TRANSACTIONS
        // =============================================
        
        $journal_entry_id = null;
        
        // Only create an invoice (Journal Entry) if it's a new rental.
        // Editing a rental shouldn't create a new invoice, to prevent duplicates.
        // (A more complex system would handle edits, but this is safest)
        if (!$edit_mode) {
            
            // 1. Create Journal Entry (The "Invoice" Header)
            $journal_desc = "Vehicle Rental for " . $customer->name . 
                            " (Vehicle: " . $vehicle->vehicle_number . ")";
            
            $journal_entry_id = $db->insert('journal_entries', [
                'transaction_date' => date('Y-m-d', strtotime($start_datetime)),
                'description' => $journal_desc,
                'related_document_type' => 'vehicle_rentals',
                // 'related_document_id' will be updated after we get the $rental_id
                'created_by_user_id' => $user_id
            ]);
            if (!$journal_entry_id) throw new Exception("Failed to create journal entry.");

            // 2. Create Transaction Lines (The "Invoice" Details)
            // DEBIT: Accounts Receivable (Asset Increase)
            $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_entry_id,
                'account_id' => $ar_account->id,
                'entry_type' => 'debit',
                'amount' => $total_amount,
                'description' => "Invoice for " . $vehicle->vehicle_number . " rental"
            ]);
            
            // CREDIT: Vehicle Rental Income (Income Increase)
            $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_entry_id,
                'account_id' => $rental_income_account->id,
                'entry_type' => 'credit',
                'amount' => $total_amount,
                'description' => "Rental income from " . $customer->name
            ]);
            
            // 3. Update Customer's Balance
            $db->query(
                "UPDATE customers SET current_balance = current_balance + ? WHERE id = ?",
                [$total_amount, $customer_id]
            );
        }

        // 4. Save the Rental Record
        $rental_data = [
            'vehicle_id' => $vehicle_id,
            'customer_id' => $customer_id,
            'rental_type' => $rental_type,
            'start_datetime' => $start_datetime,
            'end_datetime' => $end_datetime,
            'rate' => $rate,
            'total_amount' => $total_amount,
            'status' => $status,
            'notes' => $notes,
            'created_by_user_id' => $user_id
        ];

        if ($edit_mode) {
            $db->update('vehicle_rentals', $rental_id, $rental_data);
        } else {
            $rental_data['journal_entry_id'] = $journal_entry_id;
            $rental_id = $db->insert('vehicle_rentals', $rental_data);
            if (!$rental_id) throw new Exception("Failed to create rental record.");
            
            // Now, link the journal entry back to the rental
            $db->query("UPDATE journal_entries SET related_document_id = ? WHERE id = ?", [$rental_id, $journal_entry_id]);
        }
        
        $pdo->commit();
        
        $_SESSION['success_flash'] = "Vehicle rental record saved successfully!";
        header('Location: index.php');
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        error_log("Rental manage error: [User: $user_id] " . $e->getMessage());
    }
}


require_once '../../templates/header.php';
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="bg-white rounded-lg shadow-md p-6">
    <div class="flex justify-between items-center mb-6 border-b pb-4">
        <h1 class="text-2xl font-bold text-gray-900">🚙 <?php echo $pageTitle; ?></h1>
        <a href="index.php" class="px-4 py-2 border rounded-lg hover:bg-gray-50">
            <i class="fas fa-arrow-left mr-2"></i>Back to Rental List
        </a>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg shadow">
        <p class="font-bold">Error</p>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6" x-data="{ 
        rentalType: '<?php echo $rental->rental_type ?? 'Daily'; ?>',
        rate: <?php echo $rental->rate ?? 0; ?>,
        startDate: '<?php echo $rental ? date('Y-m-d', strtotime($rental->start_datetime)) : date('Y-m-d'); ?>',
        startTime: '<?php echo $rental ? date('H:i', strtotime($rental->start_datetime)) : date('H:i'); ?>',
        endDate: '<?php echo $rental ? date('Y-m-d', strtotime($rental->end_datetime)) : date('Y-m-d', strtotime('+1 day')); ?>',
        endTime: '<?php echo $rental ? date('H:i', strtotime($rental->end_datetime)) : date('H:i'); ?>',
        totalAmount: <?php echo $rental->total_amount ?? 0; ?>,

        calculateTotal() {
            if (this.rentalType === 'Trip' || this.rentalType === 'Fixed') {
                this.totalAmount = this.rate; // For Trip or Fixed, rate IS the total
            } else {
                try {
                    const start = new Date(this.startDate + 'T' + this.startTime);
                    const end = new Date(this.endDate + 'T' + this.endTime);
                    let diff = end.getTime() - start.getTime();
                    if (diff < 0) diff = 0;
                    
                    let units = 0;
                    if (this.rentalType === 'Daily') {
                        units = diff / (1000 * 60 * 60 * 24); // Days
                    } else if (this.rentalType === 'Monthly') {
                        units = diff / (1000 * 60 * 60 * 24 * 30.44); // Avg days in month
                    }
                    this.totalAmount = (this.rate * units).toFixed(2);
                } catch(e) {
                    this.totalAmount = 0;
                }
            }
        }
    }" x-init="calculateTotal()">
        
        <!-- Main Details -->
        <div class="bg-gray-50 p-4 rounded-lg border">
            <h3 class="text-lg font-medium text-gray-900 mb-4 border-b pb-2">Rental Details</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle *</label>
                    <select name="vehicle_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">-- Select Vehicle --</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                        <option value="<?php echo $vehicle->id; ?>" <?php echo ($rental && $rental->vehicle_id == $vehicle->id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($vehicle->vehicle_number); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Customer *</label>
                    <select name="customer_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">-- Select Customer --</option>
                        <?php foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer->id; ?>" <?php echo ($rental && $rental->customer_id == $customer->id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer->name); ?> (<?php echo htmlspecialchars($customer->business_name ?? 'N/A'); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rental Type *</label>
                    <select name="rental_type" x-model="rentalType" @change="calculateTotal" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="Trip">Per Trip</option>
                        <option value="Daily">Per Day</option>
                        <option value="Monthly">Per Month</option>
                        <option value="Fixed">Fixed Period</option>
                    </select>
                </div>

                <!-- Start Date/Time -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Start Date *</label>
                    <input type="date" name="start_date" x-model="startDate" @change="calculateTotal" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Start Time *</label>
                    <input type="time" name="start_time" x-model="startTime" @change="calculateTotal" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                
                <!-- End Date/Time -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">End Date *</label>
                    <input type="date" name="end_date" x-model="endDate" @change="calculateTotal" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">End Time *</label>
                    <input type="time" name="end_time" x-model="endTime" @change="calculateTotal" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
        </div>

        <!-- Cost & Status -->
        <div class="bg-gray-50 p-4 rounded-lg border">
            <h3 class="text-lg font-medium text-gray-900 mb-4 border-b pb-2">Cost & Status</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <span x-show="rentalType === 'Trip' || rentalType === 'Fixed'">Total Cost (৳) *</span>
                        <span x-show="rentalType === 'Daily'">Rate per Day (৳) *</span>
                        <span x-show="rentalType === 'Monthly'">Rate per Month (৳) *</span>
                    </label>
                    <input type="number" step="0.01" name="rate" x-model.number="rate" @input="calculateTotal" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="0.00">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status *</label>
                    <select name="status" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="Scheduled" <?php echo ($rental && $rental->status == 'Scheduled') ? 'selected' : ''; ?>>Scheduled</option>
                        <option value="In Progress" <?php echo ($rental && $rental->status == 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                        <option value="Completed" <?php echo ($rental && $rental->status == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                        <option value="Cancelled" <?php echo ($rental && $rental->status == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>

                <div class="md:col-span-2">
                    <div class="bg-blue-100 border-2 border-blue-400 rounded-lg p-4">
                        <label class="block text-sm font-medium text-blue-800 mb-1">Total Calculated Amount (Invoice Total)</label>
                        <p class="text-4xl font-bold text-blue-600" x-text="'৳' + parseFloat(totalAmount).toLocaleString('en-BD', {minimumFractionDigits: 2})">৳0.00</p>
                        <input type="hidden" name="total_amount" x-model="totalAmount">
                    </div>
                    <?php if ($edit_mode): ?>
                    <p class="text-xs text-yellow-700 mt-1"><i class="fas fa-exclamation-triangle mr-1"></i>Note: Editing this record will not create a new invoice or update the original invoice amount.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="bg-gray-50 p-4 rounded-lg border">
            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
            <textarea name="notes" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                      placeholder="Rental terms, special instructions, destination (for trip), etc..."><?php echo htmlspecialchars($rental->notes ?? ''); ?></textarea>
        </div>
        
        <!-- Submit -->
        <div class="flex justify-end gap-3 pt-6 border-t mt-6">
            <a href="index.php" class="px-6 py-2 border rounded-lg hover:bg-gray-50 bg-white shadow-sm">
                Cancel
            </a>
            <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium shadow-sm">
                <i class="fas fa-save mr-2"></i><?php echo $edit_mode ? 'Save Changes' : 'Create Rental & Invoice'; ?>
            </button>
        </div>
    </form>
</div>

</div>

<!-- Alpine.js for calculations -->
<script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.x.x/dist/alpine.min.js" defer></script>

<?php require_once '../../templates/footer.php'; ?>
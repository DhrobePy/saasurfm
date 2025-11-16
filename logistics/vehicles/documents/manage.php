<?php
require_once '../../../core/init.php';

// Access control
$allowed_roles = ['Superadmin', 'admin', 'Transport Manager'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Add Document';
$error = null;
$edit_mode = false;
$doc = null;

// BRTA "Must-Have" List + other common docs
$brta_doc_types = [
    'Registration Certificate',
    'Tax Token',
    'Fitness Certificate',
    'Route Permit',
    'Insurance Certificate',
    'CNG Certificate',
    'Other'
];

// Check for Edit Mode
if (isset($_GET['id'])) {
    $edit_mode = true;
    $doc_id = (int)$_GET['id'];
    $doc = $db->query("SELECT * FROM vehicle_documents WHERE id = ?", [$doc_id])->first();
    if ($doc) {
        $pageTitle = 'Edit Document: ' . htmlspecialchars($doc->document_type);
    } else {
        $_SESSION['error_flash'] = 'Document not found.';
        header('Location: index.php');
        exit();
    }
}

// --- Get Form Data ---
$vehicles = $db->query("SELECT id, vehicle_number FROM vehicles WHERE status = 'Active' ORDER BY vehicle_number")->results();
$cash_accounts = $db->query("SELECT id, account_number, name FROM chart_of_accounts WHERE account_type IN ('Cash', 'Petty Cash') AND status = 'active'")->results();
$bank_accounts = $db->query("SELECT id, account_number, name FROM chart_of_accounts WHERE account_type = 'Bank' AND status = 'active'")->results();
$employees = $db->query("SELECT id, CONCAT(first_name, ' ', last_name) AS employee_name FROM employees WHERE status = 'active' ORDER BY first_name")->results();


// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = $db->getPdo();
    try {
        $pdo->beginTransaction();
        
        // --- Form Data ---
        $vehicle_id = (int)$_POST['vehicle_id'];
        $document_type = $_POST['document_type'];
        $document_number = trim($_POST['document_number']) ?: null;
        $issue_date = !empty($_POST['issue_date']) ? $_POST['issue_date'] : null;
        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        $notes = trim($_POST['notes']) ?: null;
        
        $renewal_cost = (float)($_POST['renewal_cost'] ?? 0);
        $account_id = (int)($_POST['account_id'] ?? 0);
        $handled_by_employee_id = (int)($_POST['handled_by_employee_id'] ?? 0);

        // --- Validation ---
        if ($vehicle_id <= 0) throw new Exception("Please select a vehicle");
        if (empty($document_type)) throw new Exception("Document type is required");
        if ($renewal_cost > 0 && $account_id <= 0) throw new Exception("Please select a payment account for the renewal cost.");
        if ($renewal_cost > 0 && $handled_by_employee_id <= 0) throw new Exception("Please select the employee who paid for the renewal.");

        // --- Get Supporting Data (for accounting) ---
        $vehicle = $db->query("SELECT vehicle_number FROM vehicles WHERE id = ?", [$vehicle_id])->first();
        if (!$vehicle) throw new Exception("Vehicle not found");

        // --- Handle File Upload (Simplified) ---
        // A robust file upload would be a separate, AJAX-based handler.
        // For this page, we'll just save the path if one is uploaded.
        // We'll assume the file path is saved here, but the actual upload
        // would need its own handler (e.g., 'ajax/upload_document.php')
        $file_path = $doc->file_path ?? null; // Keep old file path if not changed
        // TODO: Add file upload logic here if needed.
        // Example: $file_path = handle_file_upload($_FILES['document_file'], 'uploads/documents/');

        // --- Save to vehicle_documents table ---
        $doc_data = [
            'vehicle_id' => $vehicle_id,
            'document_type' => $document_type,
            'document_number' => $document_number,
            'issue_date' => $issue_date,
            'expiry_date' => $expiry_date,
            'file_path' => $file_path, // This would be the new path
            'notes' => $notes
        ];

        if ($edit_mode) {
            $db->update('vehicle_documents', $doc_id, $doc_data);
            $log_id = $doc_id;
        } else {
            $log_id = $db->insert('vehicle_documents', $doc_data);
            if (!$log_id) throw new Exception("Failed to create document record.");
        }

        // --- Handle Accounting for Renewal Cost ---
        if ($renewal_cost > 0) {
            $selected_account = $db->query("SELECT name FROM chart_of_accounts WHERE id = ?", [$account_id])->first();
            
            // Get Expense Account (The one we created with SQL)
            $expense_account = $db->query("SELECT id FROM chart_of_accounts WHERE name = 'Vehicle Document Expense' AND status = 'active' LIMIT 1")->first();
            if (!$expense_account) throw new Exception("Account 'Vehicle Document Expense' (5030) not found or inactive.");

            // 1. Insert into transport_expenses
            $expense_id = $db->insert('transport_expenses', [
                'vehicle_id' => $vehicle_id,
                'expense_date' => $issue_date ?? date('Y-m-d'),
                'expense_type' => 'Document Renewal',
                'amount' => $renewal_cost,
                'description' => "Renewal cost for $document_type - " . $vehicle->vehicle_number,
                'receipt_number' => $document_number,
                'created_by_user_id' => $user_id
            ]);

            // 2. Create Journal Entry
            $journal_desc = "Document Renewal: $document_type for " . $vehicle->vehicle_number . " via " . $selected_account->name;
            $journal_entry_id = $db->insert('journal_entries', [
                'transaction_date' => $issue_date ?? date('Y-m-d'),
                'description' => $journal_desc,
                'related_document_type' => 'transport_expenses',
                'related_document_id' => $expense_id,
                'responsible_employee_id' => $handled_by_employee_id,
                'created_by_user_id' => $user_id
            ]);

            // 3. Create Transaction Lines (based on ujjalfmc_saas.sql)
            // DEBIT: Expense
            $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_entry_id,
                'account_id' => $expense_account->id,
                'entry_type' => 'debit',
                'amount' => $renewal_cost,
                'description' => $journal_desc
            ]);
            // CREDIT: Asset (Cash/Bank)
            $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_entry_id,
                'account_id' => $account_id,
                'entry_type' => 'credit',
                'amount' => $renewal_cost,
                'description' => $journal_desc
            ]);
        }
        
        $pdo->commit();
        
        $_SESSION['success_flash'] = "Vehicle document saved successfully!";
        header('Location: index.php');
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        error_log("Document manage error: [User: $user_id] " . $e->getMessage());
    }
}


require_once '../../../templates/header.php';
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="bg-white rounded-lg shadow-md p-6">
    <div class="flex justify-between items-center mb-6 border-b pb-4">
        <h1 class="text-2xl font-bold text-gray-900">📄 <?php echo $pageTitle; ?></h1>
        <a href="index.php" class="px-4 py-2 border rounded-lg hover:bg-gray-50">
            <i class="fas fa-arrow-left mr-2"></i>Back to List
        </a>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg shadow">
        <p class="font-bold">Error</p>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6" enctype="multipart/form-data">
        
        <!-- Document Details -->
        <div class="bg-gray-50 p-4 rounded-lg border">
            <h3 class="text-lg font-medium text-gray-900 mb-4 border-b pb-2">Document Details</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle *</label>
                    <select name="vehicle_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">-- Select Vehicle --</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                        <option value="<?php echo $vehicle->id; ?>" <?php echo ($doc && $doc->vehicle_id == $vehicle->id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($vehicle->vehicle_number); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Document Type *</label>
                    <select name="document_type" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">-- Select Type --</option>
                        <?php foreach ($brta_doc_types as $type): ?>
                        <option value="<?php echo $type; ?>" <?php echo ($doc && $doc->document_type == $type) ? 'selected' : ''; ?>>
                            <?php echo $type; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Document Number</label>
                    <input type="text" name="document_number" value="<?php echo htmlspecialchars($doc->document_number ?? ''); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="e.g., DM-GHA-123456">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Issue Date</label>
                    <input type="date" name="issue_date" value="<?php echo htmlspecialchars($doc->issue_date ?? ''); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Expiry Date</label>
                    <input type="date" name="expiry_date" value="<?php echo htmlspecialchars($doc->expiry_date ?? ''); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Upload File</label>
                    <input type="file" name="document_file" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    <?php if ($edit_mode && $doc->file_path): ?>
                        <p class="text-xs text-gray-500 mt-1">Current file: <a href="<?php echo url($doc->file_path); ?>" target="_blank" class="text-blue-600">View File</a></p>
                    <?php endif; ?>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                              placeholder="Any notes about this document..."><?php echo htmlspecialchars($doc->notes ?? ''); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Payment Information -->
        <div class="bg-gray-50 p-4 rounded-lg border" x-data="{ cost: <?php echo ($doc->cost ?? 0); ?> }">
            <h3 class="text-lg font-medium text-gray-900 mb-4 border-b pb-2">Renewal Cost (Optional)</h3>
            <p class="text-sm text-gray-600 mb-4">If you are adding this document as part of a new renewal, log the cost here. This will create an expense and a journal entry.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Renewal Cost (৳)</label>
                    <input type="number" step="0.01" name="renewal_cost" x-model.number="cost"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="0.00">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                    <select name="payment_method" id="payment_method" x-bind:disabled="cost <= 0"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
                            onchange="updateAccountDropdown()">
                        <option value="">-- Select Payment Method --</option>
                        <option value="Cash">Cash (Petty Cash)</option>
                        <option value="Bank">Bank Account</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Account</label>
                    <select name="account_id" id="account_id" x-bind:disabled="cost <= 0"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100">
                        <option value="">-- Select payment method first --</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Handled By (Employee)</label>
                    <select name="handled_by_employee_id" x-bind:disabled="cost <= 0"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100">
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($employees as $employee): ?>
                        <option value="<?php echo $employee->id; ?>">
                            <?php echo htmlspecialchars($employee->employee_name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Submit -->
        <div class="flex justify-end gap-3 pt-6 border-t mt-6">
            <a href="index.php" class="px-6 py-2 border rounded-lg hover:bg-gray-50 bg-white shadow-sm">
                Cancel
            </a>
            <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium shadow-sm">
                <i class="fas fa-save mr-2"></i><?php echo $edit_mode ? 'Save Changes' : 'Save Document'; ?>
            </button>
        </div>
    </form>
</div>

</div>

<script>
// Store account data
const cashAccounts = <?php echo json_encode($cash_accounts); ?>;
const bankAccounts = <?php echo json_encode($bank_accounts); ?>;

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
        accountSelect.appendChild(option);
    });
}
</script>

<?php require_once '../../../templates/footer.php'; ?>
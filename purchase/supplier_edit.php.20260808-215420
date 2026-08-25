<?php
/**
 * Edit Supplier
 * Update existing supplier information
 * 
 * @package Ujjal Flour Mills
 * @subpackage Purchase Module
 */

require_once '../core/init.php';

global $db;

// Restrict access
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$currentUser = getCurrentUser();
$user_id = $currentUser['id'];
$user_role = $currentUser['role'] ?? '';

$pageTitle = "Edit Supplier";
$errors = [];
$success = '';

// Get supplier ID
$supplier_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($supplier_id === 0) {
    redirect('purchase/suppliers.php', 'Invalid supplier ID', 'error');
}

// Get supplier details
$db->query("SELECT * FROM suppliers WHERE id = :id", ['id' => $supplier_id]);
$supplier = $db->first();

if (!$supplier) {
    redirect('purchase/suppliers.php', 'Supplier not found', 'error');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validate input
    $company_name = trim($_POST['company_name'] ?? '');
    $supplier_code = trim($_POST['supplier_code'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $tax_id = trim($_POST['tax_id'] ?? '');
    $payment_terms = trim($_POST['payment_terms'] ?? '');
    $credit_limit = floatval($_POST['credit_limit'] ?? 0);
    $supplier_type = $_POST['supplier_type'] ?? 'local';
    $status = $_POST['status'] ?? 'active';
    $notes = trim($_POST['notes'] ?? '');
    
    // Validation
    if (empty($company_name)) {
        $errors[] = "Company name is required";
    }
    
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    // Check for duplicate supplier code
    if (!empty($supplier_code)) {
        $check_sql = "SELECT id FROM suppliers WHERE supplier_code = :code AND id != :id";
        $db->query($check_sql, ['code' => $supplier_code, 'id' => $supplier_id]);
        
        if ($db->first()) {
            $errors[] = "Supplier code already exists for another supplier";
        }
    }
    
    if (empty($errors)) {
        
        // Get old values for audit log
        $old_data = [
            'company_name' => $supplier->company_name,
            'supplier_code' => $supplier->supplier_code,
            'contact_person' => $supplier->contact_person,
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'mobile' => $supplier->mobile,
            'status' => $supplier->status,
            'supplier_type' => $supplier->supplier_type,
            'credit_limit' => $supplier->credit_limit
        ];
        
        $data = [
            'company_name' => $company_name,
            'supplier_code' => $supplier_code ?: null,
            'contact_person' => $contact_person ?: null,
            'email' => $email ?: null,
            'phone' => $phone ?: null,
            'mobile' => $mobile ?: null,
            'address' => $address ?: null,
            'city' => $city ?: null,
            'country' => $country ?: null,
            'tax_id' => $tax_id ?: null,
            'payment_terms' => $payment_terms ?: null,
            'credit_limit' => $credit_limit,
            'supplier_type' => $supplier_type,
            'status' => $status,
            'notes' => $notes ?: null
        ];
        
        try {
            // Update supplier
            $update_sql = "UPDATE suppliers SET 
                company_name = :company_name,
                supplier_code = :supplier_code,
                contact_person = :contact_person,
                email = :email,
                phone = :phone,
                mobile = :mobile,
                address = :address,
                city = :city,
                country = :country,
                tax_id = :tax_id,
                payment_terms = :payment_terms,
                credit_limit = :credit_limit,
                supplier_type = :supplier_type,
                status = :status,
                notes = :notes,
                updated_at = NOW()
            WHERE id = :id";
            
            $data['id'] = $supplier_id;
            $db->query($update_sql, $data);
            
            // Audit log - track changes
            $changes = [];
            if ($old_data['company_name'] != $company_name) {
                $changes[] = "Company: {$old_data['company_name']} → {$company_name}";
            }
            if ($old_data['supplier_code'] != $supplier_code) {
                $changes[] = "Code: {$old_data['supplier_code']} → {$supplier_code}";
            }
            if ($old_data['status'] != $status) {
                $changes[] = "Status: {$old_data['status']} → {$status}";
            }
            if ($old_data['supplier_type'] != $supplier_type) {
                $changes[] = "Type: {$old_data['supplier_type']} → {$supplier_type}";
            }
            if ($old_data['credit_limit'] != $credit_limit) {
                $changes[] = "Credit Limit: ৳{$old_data['credit_limit']} → ৳{$credit_limit}";
            }
            
            if (!empty($changes) && function_exists('auditLog')) {
                auditLog(
                    'Suppliers',
                    'supplier_updated',
                    "Supplier {$company_name} ({$supplier_code}) updated: " . implode(', ', $changes),
                    [
                        'supplier_id' => $supplier_id,
                        'supplier_code' => $supplier_code,
                        'changes' => $changes
                    ]
                );
            }
            
            redirect('purchase/suppliers.php', 'Supplier updated successfully', 'success');
            
        } catch (Exception $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

require_once '../templates/header.php';
?>

<div class="w-full px-4 py-6">
    
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Edit Supplier</h1>
            <p class="mt-2 text-gray-600">
                Update information for <strong><?php echo htmlspecialchars($supplier->company_name); ?></strong>
            </p>
        </div>
        <a href="purchase/suppliers.php" class="border border-gray-300 text-gray-700 px-6 py-3 rounded-lg hover:bg-gray-50 flex items-center gap-2 transition">
            <i class="fas fa-arrow-left"></i>
            <span>Back to List</span>
        </a>
    </div>

    <!-- Display Errors -->
    <?php if (!empty($errors)): ?>
    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
        <div class="flex items-center mb-2">
            <i class="fas fa-exclamation-circle text-red-500 mr-2"></i>
            <h3 class="text-red-800 font-semibold">Please fix the following errors:</h3>
        </div>
        <ul class="list-disc list-inside text-red-700">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Supplier Info Card -->
    <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
            <div>
                <p class="text-gray-600">Supplier Code</p>
                <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($supplier->supplier_code ?? 'N/A'); ?></p>
            </div>
            <div>
                <p class="text-gray-600">Current Balance</p>
                <p class="font-semibold <?php echo $supplier->current_balance > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                    ৳<?php echo number_format($supplier->current_balance, 2); ?>
                </p>
            </div>
            <div>
                <p class="text-gray-600">Created On</p>
                <p class="font-semibold text-gray-900"><?php echo date('d M Y', strtotime($supplier->created_at)); ?></p>
            </div>
            <div>
                <p class="text-gray-600">Last Updated</p>
                <p class="font-semibold text-gray-900">
                    <?php echo $supplier->updated_at ? date('d M Y', strtotime($supplier->updated_at)) : 'Never'; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Edit Form -->
    <form method="POST" action="" class="bg-white rounded-lg shadow-md p-8">

        <!-- Basic Information -->
        <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-900 mb-4 pb-2 border-b">Basic Information</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <!-- Company Name -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Company Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text" 
                           name="company_name" 
                           value="<?php echo htmlspecialchars($supplier->company_name ?? $_POST['company_name'] ?? ''); ?>"
                           required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                </div>

                <!-- Supplier Code -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Supplier Code
                    </label>
                    <input type="text" 
                           name="supplier_code" 
                           value="<?php echo htmlspecialchars($supplier->supplier_code ?? $_POST['supplier_code'] ?? ''); ?>"
                           placeholder="e.g., SUP-0001"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                    <p class="mt-1 text-xs text-gray-500">Leave blank to keep existing code</p>
                </div>

                <!-- Supplier Type -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Supplier Type <span class="text-red-500">*</span>
                    </label>
                    <select name="supplier_type" 
                            required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                        <option value="local" <?php echo ($supplier->supplier_type ?? '') === 'local' ? 'selected' : ''; ?>>
                            Local (Bangladesh)
                        </option>
                        <option value="international" <?php echo ($supplier->supplier_type ?? '') === 'international' ? 'selected' : ''; ?>>
                            International
                        </option>
                    </select>
                </div>

                <!-- Status -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Status <span class="text-red-500">*</span>
                    </label>
                    <select name="status" 
                            required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                        <option value="active" <?php echo ($supplier->status ?? '') === 'active' ? 'selected' : ''; ?>>
                            Active
                        </option>
                        <option value="inactive" <?php echo ($supplier->status ?? '') === 'inactive' ? 'selected' : ''; ?>>
                            Inactive
                        </option>
                        <option value="blocked" <?php echo ($supplier->status ?? '') === 'blocked' ? 'selected' : ''; ?>>
                            Blocked
                        </option>
                    </select>
                </div>

                <!-- Tax ID -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Tax ID / VAT / TIN
                    </label>
                    <input type="text" 
                           name="tax_id" 
                           value="<?php echo htmlspecialchars($supplier->tax_id ?? $_POST['tax_id'] ?? ''); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                </div>

            </div>
        </div>

        <!-- Contact Information -->
        <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-900 mb-4 pb-2 border-b">Contact Information</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <!-- Contact Person -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Contact Person
                    </label>
                    <input type="text" 
                           name="contact_person" 
                           value="<?php echo htmlspecialchars($supplier->contact_person ?? $_POST['contact_person'] ?? ''); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                </div>

                <!-- Email -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Email Address
                    </label>
                    <input type="email" 
                           name="email" 
                           value="<?php echo htmlspecialchars($supplier->email ?? $_POST['email'] ?? ''); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                </div>

                <!-- Phone -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Phone Number
                    </label>
                    <input type="text" 
                           name="phone" 
                           value="<?php echo htmlspecialchars($supplier->phone ?? $_POST['phone'] ?? ''); ?>"
                           placeholder="+880 XXX XXXXXX"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                </div>

                <!-- Mobile -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Mobile Number
                    </label>
                    <input type="text" 
                           name="mobile" 
                           value="<?php echo htmlspecialchars($supplier->mobile ?? $_POST['mobile'] ?? ''); ?>"
                           placeholder="+880 1XXX XXXXXX"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                </div>

            </div>
        </div>

        <!-- Address Information -->
        <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-900 mb-4 pb-2 border-b">Address Information</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <!-- Address -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Street Address
                    </label>
                    <textarea name="address" 
                              rows="3"
                              placeholder="Enter complete address..."
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"><?php echo htmlspecialchars($supplier->address ?? $_POST['address'] ?? ''); ?></textarea>
                </div>

                <!-- City -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        City
                    </label>
                    <input type="text" 
                           name="city" 
                           value="<?php echo htmlspecialchars($supplier->city ?? $_POST['city'] ?? ''); ?>"
                           placeholder="e.g., Dhaka"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                </div>

                <!-- Country -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Country
                    </label>
                    <input type="text" 
                           name="country" 
                           value="<?php echo htmlspecialchars($supplier->country ?? $_POST['country'] ?? 'Bangladesh'); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                </div>

            </div>
        </div>

        <!-- Financial Information -->
        <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-900 mb-4 pb-2 border-b">Financial Information</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <!-- Payment Terms -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Payment Terms
                    </label>
                    <input type="text" 
                           name="payment_terms" 
                           value="<?php echo htmlspecialchars($supplier->payment_terms ?? $_POST['payment_terms'] ?? ''); ?>"
                           placeholder="e.g., Net 30, Net 60, COD, LC"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                </div>

                <!-- Credit Limit -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Credit Limit (৳)
                    </label>
                    <input type="number" 
                           name="credit_limit" 
                           value="<?php echo $supplier->credit_limit ?? $_POST['credit_limit'] ?? '0'; ?>"
                           step="0.01"
                           min="0"
                           placeholder="0.00"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                    <p class="mt-1 text-xs text-gray-500">Maximum credit allowed</p>
                </div>

                <!-- Current Balance (Read Only) -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Current Balance (৳)
                    </label>
                    <input type="text" 
                           value="<?php echo number_format($supplier->current_balance, 2); ?>"
                           readonly
                           class="w-full px-4 py-2 bg-gray-100 border border-gray-300 rounded-lg text-gray-700 cursor-not-allowed">
                    <p class="mt-1 text-xs text-gray-500">
                        <?php if ($supplier->current_balance > 0): ?>
                            <span class="text-red-600">Amount owed to supplier</span>
                        <?php elseif ($supplier->current_balance < 0): ?>
                            <span class="text-green-600">Advance paid to supplier</span>
                        <?php else: ?>
                            <span class="text-gray-600">No outstanding balance</span>
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Opening Balance (Read Only) -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Opening Balance (৳)
                    </label>
                    <input type="text" 
                           value="<?php echo number_format($supplier->opening_balance, 2); ?>"
                           readonly
                           class="w-full px-4 py-2 bg-gray-100 border border-gray-300 rounded-lg text-gray-700 cursor-not-allowed">
                    <p class="mt-1 text-xs text-gray-500">Initial balance when created</p>
                </div>

            </div>
        </div>

        <!-- Notes -->
        <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-900 mb-4 pb-2 border-b">Additional Notes</h2>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Internal Notes
                </label>
                <textarea name="notes" 
                          rows="4"
                          placeholder="Add any internal notes about this supplier (not visible to supplier)..."
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"><?php echo htmlspecialchars($supplier->notes ?? $_POST['notes'] ?? ''); ?></textarea>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="flex items-center justify-between pt-6 border-t">
            <div class="text-sm text-gray-600">
                <i class="fas fa-info-circle mr-1"></i>
                Fields marked with <span class="text-red-500">*</span> are required
            </div>
            <div class="flex items-center gap-4">
                <a href="purchase/suppliers.php" class="px-6 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                    <i class="fas fa-times mr-2"></i>Cancel
                </a>
                <button type="submit" class="px-6 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg transition">
                    <i class="fas fa-save mr-2"></i>Update Supplier
                </button>
            </div>
        </div>

    </form>

    <!-- Quick Links -->
    <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
        <a href="view_supplier.php?id=<?php echo $supplier_id; ?>" 
           class="bg-white border-2 border-blue-200 rounded-lg p-4 hover:border-blue-400 transition flex items-center gap-3">
            <div class="bg-blue-100 rounded-full p-3">
                <i class="fas fa-eye text-blue-600"></i>
            </div>
            <div>
                <h3 class="font-semibold text-gray-900">View Details</h3>
                <p class="text-sm text-gray-600">See complete supplier profile</p>
            </div>
        </a>
        
        <a href="supplier_ledger.php?id=<?php echo $supplier_id; ?>" 
           class="bg-white border-2 border-green-200 rounded-lg p-4 hover:border-green-400 transition flex items-center gap-3">
            <div class="bg-green-100 rounded-full p-3">
                <i class="fas fa-book text-green-600"></i>
            </div>
            <div>
                <h3 class="font-semibold text-gray-900">Ledger</h3>
                <p class="text-sm text-gray-600">View transaction history</p>
            </div>
        </a>
        
        <a href="purchase_adnan_create_po.php?supplier_id=<?php echo $supplier_id; ?>" 
           class="bg-white border-2 border-purple-200 rounded-lg p-4 hover:border-purple-400 transition flex items-center gap-3">
            <div class="bg-purple-100 rounded-full p-3">
                <i class="fas fa-plus-circle text-purple-600"></i>
            </div>
            <div>
                <h3 class="font-semibold text-gray-900">New PO</h3>
                <p class="text-sm text-gray-600">Create purchase order</p>
            </div>
        </a>
    </div>

</div>

<?php require_once '../templates/footer.php'; ?>
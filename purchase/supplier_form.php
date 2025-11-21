<?php
require_once '../core/init.php';

global $db;

restrict_access();

$currentUser = getCurrentUser();
$user_id = $currentUser['id'];

$pageTitle = "Add Supplier";
$supplier = null;
$errors = [];
$success = '';

// Check if editing
$supplier_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($supplier_id > 0) {
    $pageTitle = "Edit Supplier";
    $db->query("SELECT * FROM suppliers WHERE id = :id", ['id' => $supplier_id]);
    $supplier = $db->first();
    
    if (!$supplier) {
        redirect('suppliers.php', 'Supplier not found', 'error');
    }
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
    $opening_balance = floatval($_POST['opening_balance'] ?? 0);
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
        $check_sql = "SELECT id FROM suppliers WHERE supplier_code = :code";
        $check_params = ['code' => $supplier_code];
        
        if ($supplier_id > 0) {
            $check_sql .= " AND id != :id";
            $check_params['id'] = $supplier_id;
        }
        
        $db->query($check_sql, $check_params);
        if ($db->first()) {
            $errors[] = "Supplier code already exists";
        }
    }
    
    if (empty($errors)) {
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
            if ($supplier_id > 0) {
                // Update existing supplier
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
                
                redirect('view_supplier.php?id=' . $supplier_id, 'Supplier updated successfully', 'success');
                
            } else {
                // Insert new supplier
                $data['created_by_user_id'] = $user_id;
                
                // Generate supplier code if not provided
                if (empty($data['supplier_code'])) {
                    $db->query("SELECT MAX(id) as max_id FROM suppliers");
                    $result = $db->first();
                    $next_id = ($result->max_id ?? 0) + 1;
                    $data['supplier_code'] = 'SUP-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);
                }
                
                $data['opening_balance'] = $opening_balance;
                $data['current_balance'] = $opening_balance;
                
                $insert_sql = "INSERT INTO suppliers (
                    company_name, supplier_code, contact_person, email, phone, mobile,
                    address, city, country, tax_id, payment_terms, credit_limit,
                    opening_balance, current_balance, supplier_type, status, notes,
                    created_by_user_id, created_at
                ) VALUES (
                    :company_name, :supplier_code, :contact_person, :email, :phone, :mobile,
                    :address, :city, :country, :tax_id, :payment_terms, :credit_limit,
                    :opening_balance, :current_balance, :supplier_type, :status, :notes,
                    :created_by_user_id, NOW()
                )";
                
                $db->query($insert_sql, $data);
                $new_id = $db->getPdo()->lastInsertId();
                
                // Create opening balance ledger entry if > 0
                if ($opening_balance > 0) {
                    $ledger_sql = "INSERT INTO supplier_ledger (
                        supplier_id, transaction_date, transaction_type, 
                        credit_amount, balance, description, created_by_user_id
                    ) VALUES (
                        :supplier_id, CURDATE(), 'opening_balance',
                        :amount, :amount, 'Opening Balance', :user_id
                    )";
                    
                    $db->query($ledger_sql, [
                        'supplier_id' => $new_id,
                        'amount' => $opening_balance,
                        'user_id' => $user_id
                    ]);
                }
                
                redirect('view_supplier.php?id=' . $new_id, 'Supplier created successfully', 'success');
            }
            
        } catch (Exception $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

require_once '../templates/header.php';
?>

<div class="container mx-auto max-w-4xl">
    
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
            <p class="mt-2 text-gray-600">
                <?php echo $supplier_id > 0 ? 'Update supplier information' : 'Add a new supplier to your system'; ?>
            </p>
        </div>
        <a href="suppliers.php" class="text-gray-600 hover:text-gray-800">
            <i class="fas fa-arrow-left mr-2"></i>Back to Suppliers
        </a>
    </div>

    <!-- Error Messages -->
    <?php if (!empty($errors)): ?>
    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
        <div class="flex">
            <i class="fas fa-exclamation-circle text-red-500 mt-1 mr-3"></i>
            <div>
                <h3 class="text-red-800 font-medium">Please fix the following errors:</h3>
                <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" action="" class="bg-white rounded-lg shadow-md p-6">
        
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
                           placeholder="Auto-generated if empty"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                    <p class="mt-1 text-xs text-gray-500">Leave empty to auto-generate</p>
                </div>

                <!-- Supplier Type -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Supplier Type <span class="text-red-500">*</span>
                    </label>
                    <select name="supplier_type" 
                            required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                        <option value="local" <?php echo ($supplier->supplier_type ?? $_POST['supplier_type'] ?? '') === 'local' ? 'selected' : ''; ?>>Local</option>
                        <option value="international" <?php echo ($supplier->supplier_type ?? $_POST['supplier_type'] ?? '') === 'international' ? 'selected' : ''; ?>>International</option>
                        <option value="both" <?php echo ($supplier->supplier_type ?? $_POST['supplier_type'] ?? '') === 'both' ? 'selected' : ''; ?>>Both</option>
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
                        <option value="active" <?php echo ($supplier->status ?? $_POST['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($supplier->status ?? $_POST['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="blocked" <?php echo ($supplier->status ?? $_POST['status'] ?? '') === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
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
                           placeholder="e.g., Net 30, Net 60, COD"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                </div>

                <!-- Credit Limit -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Credit Limit (BDT)
                    </label>
                    <input type="number" 
                           name="credit_limit" 
                           value="<?php echo $supplier->credit_limit ?? $_POST['credit_limit'] ?? '0'; ?>"
                           step="0.01"
                           min="0"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                </div>

                <!-- Opening Balance (only for new suppliers) -->
                <?php if ($supplier_id === 0): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Opening Balance (BDT)
                    </label>
                    <input type="number" 
                           name="opening_balance" 
                           value="<?php echo $_POST['opening_balance'] ?? '0'; ?>"
                           step="0.01"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                    <p class="mt-1 text-xs text-gray-500">Amount owed to supplier at start</p>
                </div>
                <?php endif; ?>

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
                          placeholder="Add any internal notes about this supplier..."
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"><?php echo htmlspecialchars($supplier->notes ?? $_POST['notes'] ?? ''); ?></textarea>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="flex items-center justify-end gap-4 pt-6 border-t">
            <a href="suppliers.php" class="px-6 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                Cancel
            </a>
            <button type="submit" class="px-6 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg transition">
                <i class="fas fa-save mr-2"></i>
                <?php echo $supplier_id > 0 ? 'Update Supplier' : 'Create Supplier'; ?>
            </button>
        </div>

    </form>

</div>

<?php require_once '../templates/footer.php'; ?>
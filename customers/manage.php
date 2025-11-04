<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Add Customer';
$edit_mode = false;
$customer = null;

// Get the current user ID for logging/stamping
$currentUser = getCurrentUser();
$currentUserId = $currentUser['id'] ?? 0; // Use 0 or a system user ID as a fallback if not logged in

if (isset($_GET['id'])) {
    $edit_mode = true;
    $customer_id = (int)$_GET['id'];
    $customer = $db->query("SELECT * FROM customers WHERE id = ?", [$customer_id])->first();
    if ($customer) {
        $pageTitle = 'Edit Customer: ' . htmlspecialchars($customer->name);
    } else {
        // Use session flash message for redirection
        $_SESSION['error_flash'] = 'Customer not found.';
        header('Location: index.php');
        exit();
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Sanitize and retrieve POST data
        $name = $_POST['name'];
        $business_name = $_POST['business_name'] ?: null;
        $phone_number = $_POST['phone_number'];
        $email = $_POST['email'] ?: null;
        $business_address = $_POST['business_address'] ?: null;
        $customer_type = $_POST['customer_type'];
        $status = $_POST['status'];
        $initial_due = (float)($_POST['initial_due'] ?? 0.00);
        
        // Credit limit is 0 if customer type is POS
        $credit_limit = ($customer_type === 'POS') ? 0.00 : (float)($_POST['credit_limit'] ?? 0.00);

        // Handle delete photo request
        if (isset($_POST['delete_photo']) && $edit_mode && $customer->photo_url) {
            if (file_exists('../' . $customer->photo_url)) {
                unlink('../' . $customer->photo_url);
            }
            $db->query("UPDATE customers SET photo_url = NULL WHERE id = ?", [$customer_id]);
        }

        if ($edit_mode) {
            // --- EDIT MODE ---
            $db->query(
                "UPDATE customers SET name = ?, business_name = ?, phone_number = ?, email = ?, business_address = ?, customer_type = ?, credit_limit = ?, status = ? WHERE id = ?",
                [$name, $business_name, $phone_number, $email, $business_address, $customer_type, $credit_limit, $status, $customer_id]
            );
            
            // Return customer ID for photo upload
            echo json_encode(['success' => true, 'customer_id' => $customer_id, 'message' => 'Customer successfully updated.']);
            exit();
            
        } else {
            // --- ADD NEW CUSTOMER MODE ---
            $db->query(
                "INSERT INTO customers (name, business_name, phone_number, email, business_address, customer_type, credit_limit, initial_due, current_balance, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$name, $business_name, $phone_number, $email, $business_address, $customer_type, $credit_limit, $initial_due, $initial_due, $status]
            );
            
            $new_customer_id = $db->getPdo()->lastInsertId();
            
            // Create invoice for initial due
            if ($customer_type === 'Credit' && $initial_due > 0) {
                $order_number = 'INV-INITIAL-' . $new_customer_id . '-' . time();
                
                // --- THIS IS THE CORRECTED INSERT QUERY ---
                $db->query(
                    "INSERT INTO credit_orders (
                        customer_id, order_number, order_date, required_date, order_type, 
                        status, priority, 
                        subtotal, discount_amount, tax_amount, total_amount, 
                        advance_paid, amount_paid, balance_due, 
                        internal_notes, created_by_user_id
                    ) VALUES (
                        ?, ?, CURDATE(), CURDATE(), 'credit',
                        'delivered', 'normal',
                        ?, 0.00, 0.00, ?,
                        0.00, 0.00, ?,
                        ?, ?
                    )",
                    [
                        $new_customer_id, $order_number,
                        $initial_due, // subtotal
                        $initial_due, // total_amount
                        $initial_due, // balance_due
                        'Opening balance - Previous due carried forward',
                        $currentUserId
                    ]
                );
                // --- END OF CORRECTION ---
                
                $message = 'Customer added. Invoice ' . $order_number . ' created for initial due of ৳' . number_format($initial_due, 2);
            } else {
                $message = 'Customer successfully added.';
            }
            
            // Return customer ID for photo upload
            echo json_encode(['success' => true, 'customer_id' => $new_customer_id, 'message' => $message]);
            exit();
        }
    }
} catch (PDOException $e) {
    if ($e->getCode() == '23000') {
        echo json_encode(['success' => false, 'message' => 'A customer with this phone number or email already exists.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
    }
    exit();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit();
}

require_once '../templates/header.php'; 
?>

<div class="bg-white rounded-lg shadow-md p-6" x-data="{ customerType: '<?php echo $customer->customer_type ?? 'POS'; ?>' }">
    
    <div class="flex justify-between items-center mb-6 border-b pb-4">
        <h1 class="text-2xl font-bold text-gray-900">
            <?php echo $edit_mode ? 'Edit Customer' : 'Add New Customer'; ?>
        </h1>
        <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
            <i class="fas fa-arrow-left mr-2"></i>Back to Customer List
        </a>
    </div>

    <form id="customerForm" method="POST" enctype="multipart/form-data">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

            <div class="md:col-span-2 space-y-6">
                <h3 class="text-lg font-medium text-gray-900">Customer Details</h3>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Customer Type <span class="text-red-500">*</span></label>
                    <div class="flex space-x-4">
                        <label class="flex items-center">
                            <input type="radio" name="customer_type" value="POS" x-model="customerType" 
                                   <?php echo ($customer->customer_type ?? 'POS') === 'POS' ? 'checked' : ''; ?>
                                   class="text-primary-600 focus:ring-primary-500">
                            <span class="ml-2 text-sm text-gray-700">POS Customer (Walk-in)</span>
                        </label>
                        <label class="flex items-center">
                            <input type="radio" name="customer_type" value="Credit" x-model="customerType"
                                   <?php echo ($customer->customer_type ?? '') === 'Credit' ? 'checked' : ''; ?>
                                   class="text-primary-600 focus:ring-primary-500">
                            <span class="ml-2 text-sm text-gray-700">Credit Customer</span>
                        </label>
                    </div>
                </div>

                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Contact Name <span class="text-red-500">*</span></label>
                    <input type="text" id="name" name="name" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           value="<?php echo htmlspecialchars($customer->name ?? ''); ?>"
                           placeholder="e.g., John Doe">
                </div>

                <div>
                    <label for="business_name" class="block text-sm font-medium text-gray-700 mb-1">Business Name</label>
                    <input type="text" id="business_name" name="business_name"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           value="<?php echo htmlspecialchars($customer->business_name ?? ''); ?>"
                           placeholder="e.g., Acme Hardware">
                </div>

                <div>
                    <label for="phone_number" class="block text-sm font-medium text-gray-700 mb-1">Phone Number <span class="text-red-500">*</span></label>
                    <input type="tel" id="phone_number" name="phone_number" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           value="<?php echo htmlspecialchars($customer->phone_number ?? ''); ?>"
                           placeholder="e.g., 01700000000">
                </div>
                
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                    <input type="email" id="email" name="email"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           value="<?php echo htmlspecialchars($customer->email ?? ''); ?>"
                           placeholder="e.g., john.doe@example.com">
                </div>
                
                <div>
                    <label for="business_address" class="block text-sm font-medium text-gray-700 mb-1">Business Address</label>
                    <textarea id="business_address" name="business_address" rows="3"
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                              placeholder="123 Main St, Dhaka"><?php echo htmlspecialchars($customer->business_address ?? ''); ?></textarea>
                </div>
            </div>

            <div class="md:col-span-1 space-y-6">
                <div>
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Financials & Status</h3>
                    <div class="space-y-4">
                        
                        <div x-show="customerType === 'Credit'" x-transition>
                            <label for="credit_limit" class="block text-sm font-medium text-gray-700 mb-1">Credit Limit (BDT) <span class="text-red-500">*</span></label>
                            <input type="number" step="0.01" id="credit_limit" name="credit_limit"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                   value="<?php echo htmlspecialchars($customer->credit_limit ?? '0.00'); ?>"
                                   placeholder="e.g., 50000.00">
                        </div>
                        
                        <?php if (!$edit_mode): ?>
                        <div x-show="customerType === 'Credit'" x-transition>
                            <label for="initial_due" class="block text-sm font-medium text-gray-700 mb-1">
                                Initial Due / Opening Balance
                                <span class="text-xs text-gray-500">(if any)</span>
                            </label>
                            <input type="number" step="0.01" id="initial_due" name="initial_due"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                   value="0.00"
                                   placeholder="e.g., 5000.00">
                            <p class="text-xs text-gray-500 mt-1">
                                <i class="fas fa-info-circle mr-1"></i>
                                An invoice will be created automatically for this amount.
                            </p>
                        </div>
                        <?php endif; ?>

                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Customer Status</label>
                            <select id="status" name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                                <option value="active" <?php echo ($customer->status ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($customer->status ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="blacklisted" <?php echo ($customer->status ?? '') === 'blacklisted' ? 'selected' : ''; ?>>Blacklisted</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Customer Photo -->
                <div>
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Customer Photo</h3>
                    
                    <?php if ($edit_mode && $customer->photo_url): ?>
                        <div class="mb-4" id="currentPhotoPreview">
                            <img src="<?php echo url($customer->photo_url); ?>" alt="Current Photo" class="w-32 h-32 rounded-lg object-cover border-2 border-gray-200">
                            <label class="mt-2 flex items-center text-sm text-gray-700">
                                <input type="checkbox" name="delete_photo" value="1" class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded">
                                <span class="ml-2">Delete current photo</span>
                            </label>
                        </div>
                    <?php endif; ?>
                    
                    <div>
                        <label for="photo" class="block text-sm font-medium text-gray-700 mb-1">
                            <?php echo ($edit_mode && $customer->photo_url) ? 'Upload new photo' : 'Upload photo'; ?>
                        </label>
                        <input type="file" id="photo" name="photo" accept="image/*"
                               class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4
                                      file:rounded-lg file:border-0 file:text-sm file:font-semibold
                                      file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100">
                        <p class="text-xs text-gray-500 mt-1">Accepted: JPG, PNG, GIF (Max 2MB)</p>
                    </div>
                    
                    <!-- Photo Preview -->
                    <div id="photoPreview" class="mt-4 hidden">
                        <img id="photoPreviewImg" src="" alt="Preview" class="w-32 h-32 rounded-lg object-cover border-2 border-primary-200">
                    </div>
                </div>
            </div>

            <div class="md:col-span-3">
                <div class="flex justify-end pt-6 border-t border-gray-200">
                    <a href="index.php" class="px-5 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 mr-3">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </a>
                    <button type="submit" id="submitBtn" class="px-5 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        <i class="fas fa-save mr-2"></i>
                        <span id="btnText"><?php echo $edit_mode ? 'Save Changes' : 'Create Customer'; ?></span>
                    </button>
                </div>
            </div>

        </div>
    </form>
</div>

<script>
// Photo preview
document.getElementById('photo').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('photoPreviewImg').src = e.target.result;
            document.getElementById('photoPreview').classList.remove('hidden');
        }
        reader.readAsDataURL(file);
    }
});

// Form submission with AJAX
document.getElementById('customerForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const submitBtn = document.getElementById('submitBtn');
    const btnText = document.getElementById('btnText');
    const originalText = btnText.innerHTML;
    
    // Disable button
    submitBtn.disabled = true;
    btnText.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
    
    try {
        // Step 1: Submit form data (customer + invoice creation)
        const formData = new FormData(this);
        formData.delete('photo'); // Don't send photo in main request
        
        const response = await fetch('manage.php<?php echo $edit_mode ? "?id=" . $customer_id : ""; ?>', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (!result.success) {
            alert(result.message);
            submitBtn.disabled = false;
            btnText.innerHTML = originalText;
            return;
        }
        
        // Step 2: Upload photo if selected (separate AJAX call)
        const photoInput = document.getElementById('photo');
        if (photoInput.files.length > 0) {
            btnText.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Uploading photo...';
            
            const photoFormData = new FormData();
            photoFormData.append('photo', photoInput.files[0]);
            photoFormData.append('customer_id', result.customer_id);
            
            // This PHP file needs to exist and handle the upload
            const photoResponse = await fetch('ajax/upload_customer_photo.php', {
                method: 'POST',
                body: photoFormData
            });
            
            const photoResult = await photoResponse.json();
            
            if (!photoResult.success) {
                // Photo upload failed but customer created
                alert(result.message + '\n\nNote: Photo upload failed: ' + photoResult.message);
            }
        }
        
        // Step 3: Success - redirect
        alert(result.message);
        window.location.href = 'index.php';
        
    } catch (error) {
        alert('An error occurred: ' + error.message);
        submitBtn.disabled = false;
        btnText.innerHTML = originalText;
    }
});
</script>

<?php
require_once '../templates/footer.php'; 
?>


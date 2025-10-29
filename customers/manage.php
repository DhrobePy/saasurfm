<?php
require_once '../core/init.php';

// --- SECURITY ---
// Set which roles can access this page.
$allowed_roles = [
    'Superadmin', 
    'admin', 
    'Accounts',
    'accounts-rampura',
    'accounts-srg',
    'accounts-demra'
];
restrict_access($allowed_roles);

// Get the $db instance
global $db; 
$pageTitle = 'Add Customer';
$edit_mode = false;
$customer = null;

// --- LOGIC: CHECK FOR EDIT MODE ---
if (isset($_GET['id'])) {
    $edit_mode = true;
    $customer_id = (int)$_GET['id'];
    $customer = $db->query("SELECT * FROM customers WHERE id = ?", [$customer_id])->first();
    if ($customer) {
        $pageTitle = 'Edit Customer: ' . htmlspecialchars($customer->name);
    } else {
        $_SESSION['error_flash'] = 'Customer not found.';
        header('Location: index.php');
        exit();
    }
}

// --- LOGIC: HANDLE FORM SUBMISSION (ADD & UPDATE) ---
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // --- 1. Get Data ---
        $name = $_POST['name'];
        $business_name = $_POST['business_name'] ?: null;
        $phone_number = $_POST['phone_number'];
        $email = $_POST['email'] ?: null;
        $business_address = $_POST['business_address'] ?: null;
        $customer_type = $_POST['customer_type'];
        $status = $_POST['status'];
        $initial_due = (float)($_POST['initial_due'] ?? 0.00);
        
        // --- 2. Business Logic ---
        // If customer type is POS, force credit limit to 0
        $credit_limit = ($customer_type === 'POS') ? 0.00 : (float)($_POST['credit_limit'] ?? 0.00);

        // --- 3. Handle File Upload ---
        $photo_path = $customer->photo_url ?? null; // Keep old photo by default
        
        // Check for "delete photo"
        if (isset($_POST['delete_photo']) && $photo_path && file_exists('../' . $photo_path)) {
            unlink('../' . $photo_path);
            $photo_path = null;
        }

        // Check for new photo upload
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
            // Delete old photo if it exists
            if ($photo_path && file_exists('../' . $photo_path)) {
                unlink('../' . $photo_path);
            }
            
            $upload_dir = '../uploads/profiles/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $filename = 'customer_' . ($customer_id ?? time()) . '_' . basename($_FILES['photo']['name']);
            $photo_path = 'uploads/profiles/' . $filename;
            
            if (!move_uploaded_file($_FILES['photo']['tmp_name'], '../' . $photo_path)) {
                throw new Exception('Failed to upload photo.');
            }
        }

        // --- 4. Database Operation ---
        if ($edit_mode) {
            // --- UPDATE ---
            $db->query(
                "UPDATE customers SET name = ?, business_name = ?, phone_number = ?, email = ?, business_address = ?, 
                 customer_type = ?, credit_limit = ?, status = ?, photo_url = ?
                 WHERE id = ?",
                [
                    $name, $business_name, $phone_number, $email, $business_address, 
                    $customer_type, $credit_limit, $status, $photo_path,
                    $customer_id
                ]
            );
            // Note: We don't update initial_due or current_balance on edit.
            $_SESSION['success_flash'] = 'Customer successfully updated.';
            
        } else {
            // --- ADD NEW ---
            $db->query(
                "INSERT INTO customers (name, business_name, phone_number, email, business_address, customer_type, 
                 credit_limit, initial_due, current_balance, status, photo_url) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $name, $business_name, $phone_number, $email, $business_address, $customer_type, 
                    $credit_limit, $initial_due, $initial_due, // Set current_balance = initial_due
                    $status, $photo_path
                ]
            );
            $_SESSION['success_flash'] = 'Customer successfully added.';
        }
        
        header('Location: index.php');
        exit();
    }
} catch (PDOException $e) {
    if ($e->getCode() == '23000') { // Unique constraint violation
        $_SESSION['error_flash'] = 'Database Error: A customer with this phone number or email already exists.';
    } else {
        $_SESSION['error_flash'] = 'Database Error: ' . $e->getMessage();
    }
} catch (Exception $e) {
    $_SESSION['error_flash'] = 'An unexpected error occurred: ' . $e->getMessage();
}


// --- Include Header ---
require_once '../templates/header.php'; 
?>

<!-- 
This form uses Alpine.js (loaded in header.php) for the dynamic show/hide.
x-data="{ customerType: '<?php echo $customer->customer_type ?? 'POS'; ?>' }" 
... 
x-model="customerType" 
...
x-show="customerType === 'Credit'"
-->
<div class="bg-white rounded-lg shadow-md p-6" x-data="{ customerType: '<?php echo $customer->customer_type ?? 'POS'; ?>' }">
    
    <div class="flex justify-between items-center mb-6 border-b pb-4">
        <h1 class="text-2xl font-bold text-gray-900">
            <?php echo $edit_mode ? 'Edit Customer' : 'Add New Customer'; ?>
        </h1>
        <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
            <i class="fas fa-arrow-left mr-2"></i>Back to Customer List
        </a>
    </div>

    <form action="manage.php<?php echo $edit_mode ? '?id=' . $customer_id : ''; ?>" method="POST" enctype="multipart/form-data">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

            <!-- === Col 1: Core Details === -->
            <div class="md:col-span-2 space-y-6">
                <h3 class="text-lg font-medium text-gray-900">Customer Details</h3>
                <!-- Customer Type -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Customer Type <span class="text-red-500">*</span></label>
                    <div class="flex space-x-4">
                        <label class="flex items-center">
                            <input type="radio" name="customer_type" value="POS" x-model="customerType" class="text-primary-600 focus:ring-primary-500">
                            <span class="ml-2 text-sm text-gray-700">POS Customer (Walk-in)</span>
                        </label>
                        <label class="flex items-center">
                            <input type="radio" name="customer_type" value="Credit" x-model="customerType" class="text-primary-600 focus:ring-primary-500">
                            <span class="ml-2 text-sm text-gray-700">Credit Customer</span>
                        </label>
                    </div>
                </div>

                <!-- Contact Name -->
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Contact Name <span class="text-red-500">*</span></label>
                    <input type="text" id="name" name="name" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           value="<?php echo htmlspecialchars($customer->name ?? ''); ?>"
                           placeholder="e.g., John Doe">
                </div>

                <!-- Business Name -->
                <div>
                    <label for="business_name" class="block text-sm font-medium text-gray-700 mb-1">Business Name</label>
                    <input type="text" id="business_name" name="business_name"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           value="<?php echo htmlspecialchars($customer->business_name ?? ''); ?>"
                           placeholder="e.g., Acme Hardware">
                </div>

                <!-- Phone Number -->
                <div>
                    <label for="phone_number" class="block text-sm font-medium text-gray-700 mb-1">Phone Number <span class="text-red-500">*</span></label>
                    <input type="tel" id="phone_number" name="phone_number" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           value="<?php echo htmlspecialchars($customer->phone_number ?? ''); ?>"
                           placeholder="e.g., 01700000000">
                </div>
                
                <!-- Email -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                    <input type="email" id="email" name="email"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           value="<?php echo htmlspecialchars($customer->email ?? ''); ?>"
                           placeholder="e.g., john.doe@example.com">
                </div>
                
                <!-- Business Address -->
                <div>
                    <label for="business_address" class="block text-sm font-medium text-gray-700 mb-1">Business Address</label>
                    <textarea id="business_address" name="business_address" rows="3"
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                              placeholder="123 Main St, Dhaka"><?php echo htmlspecialchars($customer->business_address ?? ''); ?></textarea>
                </div>
            </div>

            <!-- === Col 2: Financials & Photo === -->
            <div class="md:col-span-1 space-y-6">
                <!-- Financials -->
                <div>
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Financials & Status</h3>
                    <div class="space-y-4">
                        <!-- Credit Limit (Dynamic) -->
                        <div x-show="customerType === 'Credit'" x-transition>
                            <label for="credit_limit" class="block text-sm font-medium text-gray-700 mb-1">Credit Limit (BDT) <span class="text-red-500">*</span></label>
                            <input type="number" step="0.01" id="credit_limit" name="credit_limit"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                   value="<?php echo htmlspecialchars($customer->credit_limit ?? '0.00'); ?>"
                                   placeholder="e.g., 50000.00">
                        </div>
                        
                        <!-- Initial Due (Add Mode Only) -->
                        <?php if (!$edit_mode): ?>
                        <div>
                            <label for="initial_due" class="block text-sm font-medium text-gray-700 mb-1">Initial Due (if any)</label>
                            <input type="number" step="0.01" id="initial_due" name="initial_due"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                   value="0.00"
                                   placeholder="e.g., 5000.00">
                            <p class="text-xs text-gray-500 mt-1">For migrating existing balances. Leave at 0 for new customers.</p>
                        </div>
                        <?php endif; ?>

                        <!-- Status -->
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
                    <!-- Current Photo -->
                    <?php if ($edit_mode && $customer->photo_url): ?>
                        <div class="mb-4">
                            <img src="<?php echo url($customer->photo_url); ?>" alt="Current Photo" class="w-32 h-32 rounded-lg object-cover">
                            <label class="mt-2 flex items-center text-sm text-gray-700">
                                <input type="checkbox" name="delete_photo" value="1" class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded">
                                <span class="ml-2">Delete current photo</span>
                            </label>
                        </div>
                    <?php endif; ?>
                    
                    <!-- File Upload -->
                    <div>
                        <label for="photo" class="block text-sm font-medium text-gray-700 mb-1"><?php echo ($edit_mode && $customer->photo_url) ? 'Upload new photo' : 'Upload photo'; ?></label>
                        <input type="file" id="photo" name="photo"
                               class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4
                                     file:rounded-lg file:border-0 file:text-sm file:font-semibold
                                     file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100">
                    </div>
                </div>
            </div>

            <!-- === Submit Button === -->
            <div classs="md:col-span-3">
                <div class="flex justify-end pt-6 border-t border-gray-200">
                    <a href="index.php" class="px-5 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 mr-3">
                        Cancel
                    </a>
                    <button type="submit" class="px-5 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        <i class="fas fa-save mr-2"></i>
                        <?php echo $edit_mode ? 'Save Changes' : 'Create Customer'; ?>
                    </button>
                </div>
            </div>

        </div>
    </form>
</div>

<?php
// --- Include Footer ---
require_once '../templates/footer.php'; 
?>

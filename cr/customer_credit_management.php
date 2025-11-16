<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Customer Credit Management';
$error = null;
$success = null;

// Handle credit limit update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_credit') {
    try {
        $customer_id = (int)$_POST['customer_id'];
        $credit_limit = floatval($_POST['credit_limit']);
        $credit_status = $_POST['credit_status'];
        // Note: 'payment_terms' and 'notes' removed as they don't exist in the customers table schema
        
        if ($credit_limit < 0) {
            throw new Exception("Credit limit cannot be negative");
        }
        
        // Update the customers table directly
        // Using 'status' column for credit status (active/inactive/blacklisted)
        $db->query(
            "UPDATE customers SET credit_limit = ?, status = ? WHERE id = ?",
            [$credit_limit, $credit_status, $customer_id]
        );
        
        $_SESSION['success_flash'] = "Credit limit and status updated successfully";
        header('Location: customer_credit_management.php');
        exit();
        
    } catch (Exception $e) {
        $error = "Failed to update credit limit: " . $e->getMessage();
    }
}

// Get all Credit customers
// FIX 1: Removed join to non-existent `customer_credit_limits` table
// FIX 2: Changed `c.phone` to `c.phone_number`
// FIX 3: Filtered by customer_type = 'Credit' as per schema comment
$customers = $db->query(
    "SELECT c.id, c.name, c.phone_number as phone, c.current_balance, c.credit_limit,
            (c.credit_limit - c.current_balance) as available_credit,
            c.current_balance as used_credit,
            c.status as credit_status,
            -- Defaulting payment terms to 30 since table is missing
            30 as payment_terms_days, 
            '' as credit_notes,
            c.updated_at as last_updated,
            COALESCE(
                (SELECT balance_after FROM customer_ledger WHERE customer_id = c.id ORDER BY id DESC LIMIT 1),
                0
            ) as outstanding_balance,
            (SELECT COUNT(*) FROM credit_orders WHERE customer_id = c.id AND status IN ('pending_approval', 'approved', 'in_production', 'shipped')) as pending_orders
     FROM customers c
     WHERE c.customer_type = 'Credit' AND c.status != 'inactive'
     ORDER BY c.name ASC"
)->results();

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
        <p class="text-lg text-gray-600 mt-1">Manage customer credit limits and status</p>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg">
        <p class="font-bold">Error</p>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <?php
        $total_customers = count($customers);
        $total_credit_issued = array_sum(array_map(fn($c) => $c->credit_limit ?? 0, $customers));
        $total_credit_used = array_sum(array_map(fn($c) => $c->used_credit ?? 0, $customers));
        $total_available = array_sum(array_map(fn($c) => $c->available_credit ?? 0, $customers));
        ?>
        <div class="bg-blue-600 rounded-lg shadow-lg p-6 text-white">
            <p class="text-sm opacity-90">Total Credit Customers</p>
            <p class="text-3xl font-bold mt-2"><?php echo $total_customers; ?></p>
        </div>
        <div class="bg-purple-600 rounded-lg shadow-lg p-6 text-white">
            <p class="text-sm opacity-90">Credit Issued</p>
            <p class="text-3xl font-bold mt-2">৳<?php echo number_format($total_credit_issued / 1000, 0); ?>K</p>
        </div>
        <div class="bg-orange-600 rounded-lg shadow-lg p-6 text-white">
            <p class="text-sm opacity-90">Credit Used</p>
            <p class="text-3xl font-bold mt-2">৳<?php echo number_format($total_credit_used / 1000, 0); ?>K</p>
        </div>
        <div class="bg-green-600 rounded-lg shadow-lg p-6 text-white">
            <p class="text-sm opacity-90">Available Credit</p>
            <p class="text-3xl font-bold mt-2">৳<?php echo number_format($total_available / 1000, 0); ?>K</p>
        </div>
    </div>

    <!-- Customers List -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="p-6 border-b border-gray-200 bg-gray-50">
            <h2 class="text-xl font-bold text-gray-800">Customer Credit Limits</h2>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit Limit</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Used</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Available</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Usage %</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Balance</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-4 text-center text-gray-500">No credit customers found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $customer): 
                            $credit_limit = $customer->credit_limit ?? 0;
                            $used_credit = $customer->used_credit ?? 0;
                            $available_credit = $customer->available_credit ?? 0;
                            $usage_percent = $credit_limit > 0 ? ($used_credit / $credit_limit) * 100 : 0;
                            
                            $usage_color = $usage_percent < 50 ? 'green' : ($usage_percent < 80 ? 'yellow' : 'red');
                            $status_color = ($customer->credit_status ?? 'active') === 'active' ? 'green' : 'red';
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($customer->name); ?></div>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($customer->phone); ?></div>
                                <?php if ($customer->pending_orders > 0): ?>
                                <div class="text-xs text-blue-600 mt-1">
                                    <i class="fas fa-clock"></i> <?php echo $customer->pending_orders; ?> pending order(s)
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium">
                                ৳<?php echo number_format($credit_limit, 0); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-orange-600 font-medium">
                                ৳<?php echo number_format($used_credit, 0); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-green-600 font-medium">
                                ৳<?php echo number_format($available_credit, 0); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                <span class="px-2 py-1 text-xs font-bold rounded bg-<?php echo $usage_color; ?>-100 text-<?php echo $usage_color; ?>-800">
                                    <?php echo number_format($usage_percent, 1); ?>%
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                <?php if ($customer->outstanding_balance > 0): ?>
                                <span class="text-red-600 font-bold">৳<?php echo number_format($customer->outstanding_balance, 0); ?></span>
                                <?php else: ?>
                                <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-<?php echo $status_color; ?>-100 text-<?php echo $status_color; ?>-800">
                                    <?php echo ucfirst($customer->credit_status ?? 'active'); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                <button onclick="editCredit(<?php echo $customer->id; ?>)" 
                                        class="text-blue-600 hover:text-blue-900 mr-3">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <a href="customer_ledger.php?customer_id=<?php echo $customer->id; ?>" 
                                class="text-purple-600 hover:text-purple-900">
                                    <i class="fas fa-book"></i> Ledger
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Edit Credit Modal -->
<div id="editCreditModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-screen overflow-y-auto">
        <div class="p-6 border-b border-gray-200">
            <div class="flex justify-between items-center">
                <h3 class="text-xl font-bold text-gray-900">Edit Credit Limit</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-2xl"></i>
                </button>
            </div>
        </div>
        
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="update_credit">
            <input type="hidden" name="customer_id" id="edit_customer_id">
            
            <div class="space-y-4">
                <div>
                    <p class="text-lg font-medium text-gray-900 mb-4" id="edit_customer_name"></p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Credit Limit (৳) *</label>
                    <input type="number" name="credit_limit" id="edit_credit_limit" step="0.01" required 
                           class="w-full px-4 py-2 border rounded-lg" placeholder="0.00">
                </div>
                
                <!-- Payment Terms Removed from Form as per Schema -->
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Status *</label>
                    <select name="credit_status" id="edit_credit_status" required class="w-full px-4 py-2 border rounded-lg">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="blacklisted">Blacklisted</option>
                    </select>
                </div>
                
                <!-- Notes Removed from Form as per Schema -->
                
                <div id="edit_current_info" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <p class="text-sm font-medium text-blue-900 mb-2">Current Status:</p>
                    <div class="grid grid-cols-3 gap-4 text-xs text-blue-800">
                        <div>
                            <p>Current Limit:</p>
                            <p class="font-bold" id="current_limit">-</p>
                        </div>
                        <div>
                            <p>Used:</p>
                            <p class="font-bold" id="current_used">-</p>
                        </div>
                        <div>
                            <p>Available:</p>
                            <p class="font-bold" id="current_available">-</p>
                        </div>
                    </div>
                </div>
                
                <div class="flex gap-3 pt-4 border-t">
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-save mr-2"></i>Save Changes
                    </button>
                    <button type="button" onclick="closeModal()" 
                            class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const customers = <?php echo json_encode($customers); ?>;

function editCredit(customerId) {
    const customer = customers.find(c => c.id == customerId);
    if (!customer) return;
    
    document.getElementById('edit_customer_id').value = customer.id;
    document.getElementById('edit_customer_name').textContent = customer.name;
    document.getElementById('edit_credit_limit').value = customer.credit_limit || 0;
    document.getElementById('edit_credit_status').value = customer.credit_status || 'active';
    
    document.getElementById('current_limit').textContent = '৳' + (parseFloat(customer.credit_limit) || 0).toFixed(0);
    document.getElementById('current_used').textContent = '৳' + (parseFloat(customer.used_credit) || 0).toFixed(0);
    document.getElementById('current_available').textContent = '৳' + (parseFloat(customer.available_credit) || 0).toFixed(0);
    
    document.getElementById('editCreditModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('editCreditModal').classList.add('hidden');
}

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});
</script>

<?php require_once '../templates/footer.php'; ?>
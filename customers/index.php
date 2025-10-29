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
    'accounts-demra',
    'accountspos-demra',
    'accountspos-srg',
    'sales-srg',
    'sales-demra',
    'sales-other',
    'collector'
];
restrict_access($allowed_roles);

// Get the $db instance
global $db; 
$pageTitle = 'Customers';

// --- LOGIC: HANDLE DELETE REQUEST ---
try {
    if (isset($_GET['delete'])) {
        $delete_id = (int)$_GET['delete'];
        
        // First, get the customer to delete their photo
        $customer = $db->query("SELECT photo_url FROM customers WHERE id = ?", [$delete_id])->first();
        if ($customer && $customer->photo_url && file_exists('../' . $customer->photo_url)) {
            unlink('../' . $customer->photo_url); // Delete the photo file
        }

        // Then, delete the customer record
        $db->query("DELETE FROM customers WHERE id = ?", [$delete_id]);
        
        $_SESSION['success_flash'] = 'Customer successfully deleted.';
        header('Location: index.php'); // Redirect to remove query string
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error_flash'] = 'Database Error: Could not delete customer. ' . $e->getMessage();
} catch (Exception $e) {
    $_SESSION['error_flash'] = 'An unexpected error occurred: ' . $e->getMessage();
}

// --- DATA: GET ALL CUSTOMERS WITH SEARCH ---
$search_term = $_GET['search'] ?? '';
$query_params = [];
$sql = "SELECT * FROM customers";

if (!empty($search_term)) {
    // Search across multiple relevant columns
    $sql .= " WHERE name LIKE ? OR business_name LIKE ? OR phone_number LIKE ? OR email LIKE ?";
    $like_term = '%' . $search_term . '%';
    // Add the search term for each placeholder
    array_push($query_params, $like_term, $like_term, $like_term, $like_term);
}

$sql .= " ORDER BY name ASC";
$customers = $db->query($sql, $query_params)->results();

/**
 * Helper function to generate initials from a name.
 * @param string $name
 * @return string The initials (e.g., "Dhrobe Islam" -> "DI")
 */
function get_initials($name) {
    $words = explode(' ', $name);
    $initials = '';
    if (count($words) >= 2) {
        $initials .= strtoupper(substr($words[0], 0, 1));
        $initials .= strtoupper(substr(end($words), 0, 1));
    } else if (count($words) == 1) {
        $initials .= strtoupper(substr($words[0], 0, 2));
    }
    return $initials;
}

// --- Include Header ---
require_once '../templates/header.php'; 
?>

<!-- ======================================== -->
<!-- 1. PAGE HEADER & ACTIONS -->
<!-- ======================================== -->
<div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
    <!-- Page Title -->
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Customer Management</h1>
        <p class="text-lg text-gray-600">Add, edit, and manage your customer database.</p>
    </div>
    
    <!-- Actions: Search and Add Button -->
    <div class="flex items-center gap-4 w-full md:w-auto">
        <!-- Search Form -->
        <form action="index.php" method="GET" class="relative flex-grow md:flex-grow-0">
            <input type="text" name="search"
                   class="w-full md:w-64 pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                   placeholder="Search customers..."
                   value="<?php echo htmlspecialchars($search_term); ?>">
            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
        </form>
        
        <!-- Add Customer Button -->
        <a href="manage.php" class="flex-shrink-0 px-5 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <i class="fas fa-plus mr-2"></i>Add New
        </a>
    </div>
</div>

<!-- ======================================== -->
<!-- 2. CUSTOMERS LIST TABLE -->
<!-- ======================================== -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type / Status</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Credit Limit</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Balance (Due)</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                            <?php if (!empty($search_term)): ?>
                                No customers found matching "<?php echo htmlspecialchars($search_term); ?>".
                            <?php else: ?>
                                No customers found. Start by adding one.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customers as $customer): ?>
                        <tr>
                            <!-- Customer Column -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-10 w-10">
                                        <?php if ($customer->photo_url): ?>
                                            <img class="h-10 w-10 rounded-full object-cover" src="<?php echo url($customer->photo_url); ?>" alt="">
                                        <?php else: ?>
                                            <span class="h-10 w-10 rounded-full bg-primary-100 text-primary-700 font-medium flex items-center justify-center">
                                                <?php echo get_initials($customer->name); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($customer->name); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($customer->business_name ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                            </td>
                            <!-- Contact Column -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900"><?php echo htmlspecialchars($customer->phone_number); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($customer->email ?? 'No email'); ?></div>
                            </td>
                            <!-- Type / Status Column -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <!-- Customer Type Pill -->
                                <?php if ($customer->customer_type == 'Credit'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                        Credit
                                    </span>
                                <?php else: ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                        POS
                                    </span>
                                <?php endif; ?>
                                
                                <!-- Status Pill -->
                                <?php if ($customer->status == 'active'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                        Active
                                    </span>
                                <?php elseif ($customer->status == 'inactive'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                        Inactive
                                    </span>
                                <?php else: // blacklisted ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                        Blacklisted
                                    </span>
                                <?php endif; ?>
                            </td>
                            <!-- Credit Limit -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                <?php if ($customer->customer_type == 'Credit'): ?>
                                    <?php echo number_format($customer->credit_limit, 2); ?>
                                <?php else: ?>
                                    <span class="text-gray-400">N/A</span>
                                <?php endif; ?>
                            </td>
                            <!-- Current Balance (Due) -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium <?php echo ($customer->current_balance > 0) ? 'text-red-600' : 'text-green-600'; ?>">
                                <?php echo number_format($customer->current_balance, 2); ?>
                            </td>
                            <!-- Actions -->
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-3">
                                <!-- NEW: View Button -->
                                <a href="view.php?id=<?php echo $customer->id; ?>" class="text-gray-500 hover:text-primary-600" title="View Profile">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="manage.php?id=<?php echo $customer->id; ?>" class="text-gray-500 hover:text-primary-600" title="Edit Customer">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="index.php?delete=<?php echo $customer->id; ?>" class="text-gray-500 hover:text-red-600" 
                                   title="Delete Customer"
                                   onclick="return confirm('Are you sure you want to delete this customer? This action cannot be undone.');">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
// --- Include Footer ---
require_once '../templates/footer.php'; 
?>


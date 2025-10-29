<?php
require_once '../core/init.php';

// --- SECURITY ---
// Set which roles can access this page (same as the list page)
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

// --- LOGIC: GET CUSTOMER ID ---
$customer_id = null;
if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    $_SESSION['error_flash'] = 'Invalid customer ID provided.';
    header('Location: index.php');
    exit();
}
$customer_id = (int)$_GET['id'];

// --- DATA: FETCH CUSTOMER ---
$customer = $db->query("SELECT * FROM customers WHERE id = ?", [$customer_id])->first();

// Handle customer not found
if (!$customer) {
    $_SESSION['error_flash'] = 'No customer found with that ID.';
    header('Location: index.php');
    exit();
}

// Set the page title
$pageTitle = 'View: ' . htmlspecialchars($customer->name);


/**
 * Helper function to generate initials from a name.
 * (This should ideally be in helpers.php, but placing here for simplicity)
 * @param string $name
 * @return string The initials (e.g., "Dhrobe Islam" -> "DI")
 */
function get_initials_for_view($name) {
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
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900"><?php echo htmlspecialchars($customer->name); ?></h1>
        <p class="text-lg text-gray-600">Customer Profile & Financial Overview</p>
    </div>
    
    <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
        <i class="fas fa-arrow-left mr-2"></i>Back to Customer List
    </a>
</div>

<!-- ======================================== -->
<!-- 2. MAIN PROFILE GRID -->
<!-- ======================================== -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- LEFT COLUMN: Profile Card -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-lg shadow-md p-6">
            <!-- Avatar / Photo -->
            <div class="flex justify-center mb-4">
                <?php if ($customer->photo_url): ?>
                    <img class="h-32 w-32 rounded-full object-cover shadow-lg" src="<?php echo url($customer->photo_url); ?>" alt="Profile Photo">
                <?php else: ?>
                    <span class="h-32 w-32 rounded-full bg-primary-100 text-primary-700 font-bold text-5xl flex items-center justify-center shadow-lg">
                        <?php echo get_initials_for_view($customer->name); ?>
                    </span>
                <?php endif; ?>
            </div>
            
            <!-- Name & Business -->
            <h2 class="text-2xl font-bold text-gray-900 text-center"><?php echo htmlspecialchars($customer->name); ?></h2>
            <p class="text-md text-gray-500 text-center mb-4"><?php echo htmlspecialchars($customer->business_name ?? 'N/A'); ?></p>

            <!-- Status Pills -->
            <div class="flex flex-wrap justify-center gap-2 mb-6">
                <!-- Customer Type Pill -->
                <?php if ($customer->customer_type == 'Credit'): ?>
                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                        <i class="fas fa-credit-card mr-2"></i>Credit Customer
                    </span>
                <?php else: ?>
                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                        <i class="fas fa-cash-register mr-2"></i>POS Customer
                    </span>
                <?php endif; ?>
                
                <!-- Status Pill -->
                <?php if ($customer->status == 'active'): ?>
                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                        <i class="fas fa-check-circle mr-2"></i>Active
                    </span>
                <?php elseif ($customer->status == 'inactive'): ?>
                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                        <i class="fas fa-exclamation-circle mr-2"></i>Inactive
                    </span>
                <?php else: // blacklisted ?>
                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                        <i class="fas fa-ban mr-2"></i>Blacklisted
                    </span>
                <?php endif; ?>
            </div>

            <!-- Edit Button -->
            <a href="manage.php?id=<?php echo $customer->id; ?>" class="w-full flex justify-center items-center px-5 py-3 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <i class="fas fa-edit mr-2"></i>Edit This Customer
            </a>
        </div>
    </div>

    <!-- RIGHT COLUMN: Details Cards -->
    <div class="lg:col-span-2">
        <!-- Card 1: Contact & Address -->
        <div class="bg-white rounded-lg shadow-md mb-6">
            <h3 class="text-xl font-bold text-gray-800 p-4 border-b border-gray-200">
                Contact Information
            </h3>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-y-4 gap-x-6">
                <!-- Phone -->
                <div>
                    <label class="block text-sm font-medium text-gray-500">Phone Number</label>
                    <p class="text-lg text-gray-900 font-semibold"><?php echo htmlspecialchars($customer->phone_number); ?></p>
                </div>
                <!-- Email -->
                <div>
                    <label class="block text-sm font-medium text-gray-500">Email Address</label>
                    <p class="text-lg text-gray-900 font-semibold"><?php echo htmlspecialchars($customer->email ?? 'N/A'); ?></p>
                </div>
                <!-- Address -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-500">Business Address</label>
                    <p class="text-lg text-gray-900 font-semibold"><?php echo nl2br(htmlspecialchars($customer->business_address ?? 'N/A')); ?></p>
                </div>
            </div>
        </div>

        <!-- Card 2: Financial Overview -->
        <div class="bg-white rounded-lg shadow-md">
            <h3 class="text-xl font-bold text-gray-800 p-4 border-b border-gray-200">
                Financial Overview
            </h3>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-y-4 gap-x-6">
                <!-- Credit Limit -->
                <?php if ($customer->customer_type == 'Credit'): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-500">Credit Limit</label>
                        <p class="text-lg text-gray-900 font-semibold"><?php echo number_format($customer->credit_limit, 2); ?> BDT</p>
                    </div>
                <?php endif; ?>
                
                <!-- Initial Due -->
                <div>
                    <label class="block text-sm font-medium text-gray-500">Opening Balance / Initial Due</label>
                    <p class="text-lg text-gray-900 font-semibold"><?php echo number_format($customer->initial_due, 2); ?> BDT</p>
                </div>

                <!-- Current Balance -->
                <div class="md:col-span-2 border-t pt-4 mt-4">
                    <label class="block text-sm font-medium text-gray-500">Current Live Balance (Due)</label>
                    <p class="text-4xl font-bold <?php echo ($customer->current_balance > 0) ? 'text-red-600' : 'text-green-600'; ?>">
                        <?php echo number_format($customer->current_balance, 2); ?> BDT
                    </p>
                    <p class="text-sm text-gray-500">
                        <?php echo ($customer->current_balance > 0) ? 'This amount is due from the customer.' : 'This customer is fully paid up.'; ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// --- Include Footer ---
require_once '../templates/footer.php'; 
?>

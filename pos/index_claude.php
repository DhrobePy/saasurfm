<?php
/**
 * FIXED POS System - Complete Version
 * Fixed Issues:
 * 1. Correct database column names (phone_number not phone)
 * 2. Proper session variable handling
 * 3. Fixed SQL queries for actual database structure
 * 4. Added proper error logging and debugging
 * 5. Fixed user display name variable
 */

require_once '../core/init.php';

// --- SECURITY & CONTEXT ---
// CSRF Token Generation
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Define roles allowed to access the POS
$allowed_roles = [
    'Superadmin',
    'admin', 
    'accountspos-demra',
    'accountspos-srg',
    'dispatchpos-demra',
    'dispatchpos-srg',
];
restrict_access($allowed_roles);

// Set timezone
date_default_timezone_set('Asia/Dhaka');

// Get the $db instance
global $db;
$pageTitle = 'Point of Sale';
$error = null;
$success = null;
$branch_id = null;
$branch_name = 'Unknown Branch';
$products = [];
$pos_customers = [];
$user_id = $_SESSION['user_id'] ?? null;
$user_display_name = $_SESSION['user_display_name'] ?? 'Unknown User';

// Debug logging
error_log("POS Access - User ID: " . ($user_id ?? 'NULL'));
error_log("POS Access - User Role: " . ($_SESSION['user_role'] ?? 'NULL'));

// --- GET USER'S BRANCH ---
if ($user_id) {
    try {
        // Debug: Check if user exists in employees table
        $check_employee = $db->query(
            "SELECT COUNT(*) as count FROM employees WHERE user_id = ?",
            [$user_id]
        )->first();
        
        error_log("Employee check for user_id $user_id: " . ($check_employee->count ?? '0'));
        
        // Find the logged-in user's employee record to get their branch_id
        $employee_info = $db->query(
            "SELECT e.branch_id, b.name as branch_name, b.code as branch_code
             FROM employees e
             JOIN branches b ON e.branch_id = b.id
             WHERE e.user_id = ?",
            [$user_id]
        )->first();

        if ($employee_info && $employee_info->branch_id) {
            $branch_id = $employee_info->branch_id;
            $branch_name = $employee_info->branch_name;
            $pageTitle .= ' - ' . htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8');
            error_log("Branch identified: ID=$branch_id, Name=$branch_name");
        } else {
            // For superadmin/admin without employee record, default to first active branch
            if (in_array($_SESSION['user_role'], ['Superadmin', 'admin'])) {
                $default_branch = $db->query(
                    "SELECT id, name FROM branches WHERE status = 'active' ORDER BY id LIMIT 1"
                )->first();
                
                if ($default_branch) {
                    $branch_id = $default_branch->id;
                    $branch_name = $default_branch->name;
                    $error = "Admin Mode: Using default branch '$branch_name'. Create an employee record for specific branch assignment.";
                    error_log("Admin defaulting to branch: ID=$branch_id, Name=$branch_name");
                }
            } else {
                throw new Exception("Your user account is not linked to an employee with an assigned branch. POS access denied.");
            }
        }
    } catch (Exception $e) {
        error_log("POS Branch Error: " . $e->getMessage());
        $error = "Error identifying branch: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
}

// --- LOAD PRODUCTS AND CUSTOMERS ---
if (!$error || (in_array($_SESSION['user_role'], ['Superadmin', 'admin']) && $branch_id)) {
    try {
        // Build product query with proper parameter binding
        $queryParams = [];
        $inventoryJoinClause = "inv.variant_id = pv.id";
        
        if ($branch_id !== null) {
            $inventoryJoinClause .= " AND inv.branch_id = :branch_id_inv";
            $queryParams['branch_id_inv'] = $branch_id;
        }

        // Main product query
        $sql = "SELECT
                pv.id as variant_id,
                pv.sku,
                pv.weight_variant,
                pv.grade,
                pv.unit_of_measure,
                p.base_name,
                -- Get the latest active price for this variant (from any branch for flexibility)
                (SELECT pp.unit_price
                 FROM product_prices pp
                 WHERE pp.variant_id = pv.id
                   AND pp.is_active = 1
                 ORDER BY pp.effective_date DESC, pp.created_at DESC
                 LIMIT 1) as unit_price,
                -- Get current inventory quantity for THIS specific branch
                COALESCE(inv.quantity, 0) as stock_quantity
            FROM
                product_variants pv
            JOIN
                products p ON pv.product_id = p.id
            LEFT JOIN
                inventory inv ON {$inventoryJoinClause}
            WHERE
                p.status = 'active'
                AND pv.status = 'active'
            ORDER BY
                p.base_name, pv.sku";

        error_log("Executing product query with branch_id: " . ($branch_id ?? 'NULL'));
        $products = $db->query($sql, $queryParams)->results();
        
        error_log("Raw products found: " . count($products));

        // Filter out products with no price and sanitize
        $valid_products = [];
        foreach ($products as $product) {
            if ($product->unit_price !== null && $product->unit_price > 0) {
                // Sanitize all string fields
                $product->base_name = htmlspecialchars($product->base_name ?? '', ENT_QUOTES, 'UTF-8');
                $product->sku = htmlspecialchars($product->sku ?? '', ENT_QUOTES, 'UTF-8');
                $product->weight_variant = htmlspecialchars($product->weight_variant ?? '', ENT_QUOTES, 'UTF-8');
                $product->grade = htmlspecialchars($product->grade ?? '', ENT_QUOTES, 'UTF-8');
                $product->unit_of_measure = htmlspecialchars($product->unit_of_measure ?? '', ENT_QUOTES, 'UTF-8');
                $product->unit_price = (float) $product->unit_price;
                $product->stock_quantity = (int) $product->stock_quantity;
                $valid_products[] = $product;
            }
        }
        $products = $valid_products;
        
        error_log("Products with valid pricing: " . count($products));
        
        // Debug: Check what's in product_prices table
        $price_check = $db->query(
            "SELECT COUNT(*) as count FROM product_prices WHERE is_active = 1"
        )->first();
        error_log("Active prices in database: " . ($price_check->count ?? '0'));

        // Fetch POS/Cash type customers - FIXED column name
        $pos_customers = $db->query(
            "SELECT 
                id, 
                name, 
                business_name, 
                phone_number,  -- Fixed: correct column name
                email,
                customer_type
             FROM customers
             WHERE customer_type = 'POS' AND status = 'active'
             ORDER BY name ASC"
        )->results();

        error_log("POS Customers found: " . count($pos_customers));

        // Sanitize customer data
        foreach ($pos_customers as &$customer) {
            $customer->name = htmlspecialchars($customer->name ?? '', ENT_QUOTES, 'UTF-8');
            $customer->business_name = htmlspecialchars($customer->business_name ?? '', ENT_QUOTES, 'UTF-8');
            $customer->phone_number = htmlspecialchars($customer->phone_number ?? '', ENT_QUOTES, 'UTF-8');
            $customer->email = htmlspecialchars($customer->email ?? '', ENT_QUOTES, 'UTF-8');
        }

    } catch (Exception $e) {
        error_log("POS Data Loading Error: " . $e->getMessage() . "\nStack: " . $e->getTraceAsString());
        $error = "Error loading POS data: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $products = [];
        $pos_customers = [];
    }
}

// Get user display name from session or database
if ($user_id && empty($user_display_name)) {
    try {
        $user_info = $db->query(
            "SELECT display_name FROM users WHERE id = ?",
            [$user_id]
        )->first();
        if ($user_info) {
            $user_display_name = $user_info->display_name;
            $_SESSION['user_display_name'] = $user_display_name;
        }
    } catch (Exception $e) {
        error_log("Error fetching user display name: " . $e->getMessage());
    }
}

// Include Header
$is_pos_interface = true;
require_once '../templates/header.php';
?>

<!-- POS Interface -->
<div class="h-[calc(100vh-theme(space.16))] flex flex-col" x-data="posApp()">
    <!-- CSRF Token -->
    <input type="hidden" id="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    
    <!-- Header/Error Area -->
    <div class="flex-shrink-0 mb-4">
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-r-lg" role="alert">
                <p class="font-bold">Error/Warning</p>
                <p><?php echo $error; ?></p>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-r-lg" role="alert">
                <p><?php echo $success; ?></p>
            </div>
        <?php endif; ?>

        <div class="flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-900">
                POS Terminal - <?php echo htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8'); ?>
            </h1>
            <div class="text-sm text-gray-500">
                <span id="current-time"><?php echo date('D, d M Y H:i'); ?></span> | 
                User: <?php echo htmlspecialchars($user_display_name, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
        
        <!-- Debug Info (remove in production) -->
        <div class="mt-2 text-xs text-gray-400">
            Branch ID: <?php echo $branch_id ?? 'NULL'; ?> | 
            Products: <?php echo count($products); ?> | 
            Customers: <?php echo count($pos_customers); ?>
        </div>
    </div>

    <!-- Main POS Layout -->
    <div class="flex-grow flex flex-col md:flex-row gap-6 overflow-hidden">
        
        <!-- Left Column: Product Selection -->
        <div class="w-full md:w-3/5 lg:w-2/3 flex flex-col bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
            <!-- Search Bar -->
            <div class="p-4 border-b border-gray-200">
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-search text-gray-400"></i>
                    </div>
                    <input type="text" 
                           x-model="searchTerm"
                           @keyup.debounce.300ms="filterProducts()"
                           placeholder="Search by Product Name or SKU..."
                           class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                           maxlength="100">
                </div>
            </div>

            <!-- Product Grid -->
            <div class="flex-grow overflow-y-auto p-4">
                <?php if (empty($products)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-box-open text-4xl text-gray-300 mb-3"></i>
                        <p class="text-gray-500">No products available</p>
                        <p class="text-sm text-gray-400 mt-2">
                            Please add products with active pricing in the system.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                        <template x-for="product in displayedProducts" :key="product.variant_id">
                            <button @click="addToCart(product)" 
                                    :disabled="product.stock_quantity <= 0 || isUpdating"
                                    class="relative block border border-gray-200 rounded-lg p-3 text-left hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-primary-500 transition-all duration-150 disabled:opacity-50 disabled:cursor-not-allowed"
                                    :class="{ 'bg-gray-50': product.stock_quantity <= 0 }">
                                
                                <p class="text-sm font-semibold text-gray-800 truncate" x-text="product.base_name"></p>
                                <p class="text-xs text-gray-500 mt-0.5" x-text="`${product.weight_variant || 'N/A'} / ${product.grade || 'N/A'}`"></p>
                                <p class="text-xs text-gray-400 mt-0.5" x-text="`SKU: ${product.sku}`"></p>
                                <p class="text-sm font-bold text-primary-600 mt-1.5">
                                    ৳<span x-text="formatCurrency(product.unit_price)"></span>
                                </p>

                                <!-- Stock Indicator -->
                                <div class="absolute bottom-1 right-1 text-xs px-1.5 py-0.5 rounded"
                                     :class="getStockClass(product.stock_quantity)">
                                    <span x-text="getStockText(product.stock_quantity)"></span>
                                </div>
                            </button>
                        </template>
                    </div>
                    
                    <div x-show="displayedProducts.length === 0 && searchTerm" class="text-center py-8">
                        <p class="text-gray-500">No products match your search.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right Column: Cart & Checkout -->
        <div class="w-full md:w-2/5 lg:w-1/3 flex flex-col bg-white rounded-lg shadow-md border border-gray-200">
            
            <!-- Customer Section -->
            <div class="p-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800 mb-3">Customer</h2>
                <div class="flex items-center gap-3">
                    <div class="flex-grow">
                        <select x-model="selectedCustomerId" 
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 text-sm">
                            <option value="">-- Walk-in Customer --</option>
                            <?php if (empty($pos_customers)): ?>
                                <option value="" disabled>No POS customers found</option>
                            <?php else: ?>
                                <?php foreach ($pos_customers as $customer): ?>
                                    <option value="<?php echo $customer->id; ?>">
                                        <?php echo $customer->name; ?>
                                        <?php if ($customer->business_name): ?>
                                            (<?php echo $customer->business_name; ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <button @click="showAddCustomerModal = true"
                            class="flex-shrink-0 inline-flex items-center px-3 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        <i class="fas fa-user-plus mr-1.5"></i> Add
                    </button>
                </div>
            </div>

            <!-- Cart Items -->
            <div class="flex-grow overflow-y-auto p-4 space-y-3">
                <h2 class="text-lg font-semibold text-gray-800 mb-1">Cart</h2>
                
                <div x-show="cart.length === 0" class="text-center text-gray-400 py-6">
                    <i class="fas fa-shopping-cart text-3xl mb-2"></i>
                    <p>Your cart is empty</p>
                </div>
                
                <template x-for="(item, index) in cart" :key="`cart-${item.variant_id}`">
                    <div class="flex items-center justify-between border border-gray-100 rounded-lg p-3 bg-gray-50">
                        <div class="flex-grow mr-3">
                            <p class="text-sm font-medium text-gray-800" x-text="item.base_name"></p>
                            <p class="text-xs text-gray-500" x-text="`${item.weight_variant || 'N/A'} / ${item.grade || 'N/A'}`"></p>
                            <p class="text-sm font-semibold text-primary-600 mt-1">
                                ৳<span x-text="formatCurrency(item.unit_price)"></span> × 
                                <span x-text="item.quantity"></span> = 
                                ৳<span x-text="formatCurrency(item.unit_price * item.quantity)"></span>
                            </p>
                        </div>
                        <div class="flex items-center">
                            <!-- Quantity Controls -->
                            <button @click="updateQuantity(item.variant_id, -1)" 
                                    :disabled="item.quantity <= 1 || isUpdating"
                                    class="px-2 py-1 border border-gray-300 rounded-l-md text-gray-600 hover:bg-gray-100 disabled:opacity-50">
                                <i class="fas fa-minus text-xs"></i>
                            </button>
                            <input type="number" 
                                   :value="item.quantity"
                                   @change="setQuantity(item.variant_id, $event.target.value, item.max_quantity)"
                                   min="1" 
                                   :max="item.max_quantity"
                                   class="w-12 text-center border-t border-b border-gray-300 py-1 text-sm">
                            <button @click="updateQuantity(item.variant_id, 1)" 
                                    :disabled="item.quantity >= item.max_quantity || isUpdating"
                                    class="px-2 py-1 border border-gray-300 rounded-r-md text-gray-600 hover:bg-gray-100 disabled:opacity-50">
                                <i class="fas fa-plus text-xs"></i>
                            </button>
                            
                            <!-- Remove Button -->
                            <button @click="removeFromCart(item.variant_id)" 
                                    class="ml-3 text-red-500 hover:text-red-700">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Checkout Section -->
            <div class="flex-shrink-0 p-4 border-t border-gray-200 bg-gray-50">
                <!-- Totals -->
                <div class="space-y-1 text-sm mb-4">
                    <div class="flex justify-between">
                        <span>Subtotal:</span>
                        <span>৳<span x-text="formatCurrency(subtotal)"></span></span>
                    </div>
                </div>
                <div class="flex justify-between items-center border-t pt-3">
                    <span class="text-lg font-bold">Total:</span>
                    <span class="text-2xl font-bold text-primary-700">
                        ৳<span x-text="formatCurrency(total)"></span>
                    </span>
                </div>

                <!-- Payment Method -->
                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                    <select x-model="paymentMethod"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="mobile">Mobile Payment</option>
                    </select>
                </div>

                <!-- Place Order Button -->
                <button @click="placeOrder" 
                        :disabled="cart.length === 0 || processingOrder || !canPlaceOrder()"
                        class="mt-4 w-full px-6 py-3 border border-transparent rounded-lg shadow-sm text-base font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50 disabled:cursor-not-allowed">
                    <span x-show="!processingOrder">
                        <i class="fas fa-check-circle mr-2"></i> Place Order
                    </span>
                    <span x-show="processingOrder">
                        <i class="fas fa-spinner fa-spin mr-2"></i> Processing...
                    </span>
                </button>

                <p x-show="!canPlaceOrder()" class="text-xs text-red-600 mt-2 text-center">
                    <span x-show="!branch_id">Order placement disabled: Branch not identified.</span>
                </p>
            </div>
        </div>
    </div>

    <!-- Add Customer Modal -->
    <div x-show="showAddCustomerModal" x-cloak 
         class="fixed inset-0 z-50 overflow-y-auto"
         x-transition:enter="ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <!-- Background overlay -->
            <div class="fixed inset-0 transition-opacity" aria-hidden="true" @click="showAddCustomerModal = false">
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>

            <!-- Modal panel -->
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-indigo-100 sm:mx-0 sm:h-10 sm:w-10">
                            <i class="fas fa-user-plus text-indigo-600"></i>
                        </div>
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                            <h3 class="text-lg leading-6 font-medium text-gray-900">
                                Add Walk-in Customer
                            </h3>
                            <div class="mt-4 space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">
                                        Customer Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" 
                                           x-model="newCustomer.name"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                           placeholder="e.g., Walk-in Customer">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">
                                        Phone Number
                                    </label>
                                    <input type="tel" 
                                           x-model="newCustomer.phone"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                           placeholder="Optional">
                                </div>
                                <p class="text-xs text-gray-500">
                                    Walk-in customers are automatically set as 'POS' type with no credit limit.
                                </p>
                                <div x-show="addCustomerError" class="text-sm text-red-600 bg-red-50 p-3 rounded">
                                    <span x-text="addCustomerError"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button @click="saveNewCustomer" 
                            :disabled="addingCustomer"
                            class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:ml-3 sm:w-auto sm:text-sm disabled:opacity-50">
                        <span x-text="addingCustomer ? 'Saving...' : 'Save Customer'"></span>
                    </button>
                    <button @click="showAddCustomerModal = false; resetCustomerForm()" 
                            class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Alpine.js POS App -->
<script>
function posApp() {
    return {
        // Data
        searchTerm: '',
        allProducts: <?php echo json_encode($products); ?>,
        displayedProducts: [],
        cart: [],
        selectedCustomerId: '',
        paymentMethod: 'cash',
        processingOrder: false,
        isUpdating: false,
        showAddCustomerModal: false,
        newCustomer: { name: '', phone: '' },
        addingCustomer: false,
        addCustomerError: '',
        customers: <?php echo json_encode($pos_customers); ?>,
        branch_id: <?php echo json_encode($branch_id); ?>,
        csrfToken: document.getElementById('csrf_token').value,
        updateMutex: new Map(),
        
        // Computed Properties
        get subtotal() {
            return this.cart.reduce((sum, item) => {
                return sum + (parseFloat(item.unit_price) * parseInt(item.quantity));
            }, 0);
        },
        
        get total() {
            return this.subtotal;
        },
        
        // Initialization
        init() {
            console.log('POS System Initialized');
            console.log('Branch:', this.branch_id);
            console.log('Products:', this.allProducts.length);
            console.log('Customers:', this.customers.length);
            
            // Validate data
            if (!Array.isArray(this.allProducts)) {
                console.error('Products data invalid');
                this.allProducts = [];
            }
            
            if (!Array.isArray(this.customers)) {
                console.error('Customers data invalid');
                this.customers = [];
            }
            
            // Initialize displayed products
            this.displayedProducts = [...this.allProducts];
            
            // Update clock every minute
            setInterval(() => {
                const now = new Date();
                const timeStr = now.toLocaleString('en-US', {
                    weekday: 'short',
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                const timeEl = document.getElementById('current-time');
                if (timeEl) timeEl.textContent = timeStr;
            }, 60000);
        },
        
        // Filter products based on search
        filterProducts() {
            if (!this.searchTerm || this.searchTerm.trim() === '') {
                this.displayedProducts = [...this.allProducts];
                return;
            }
            
            const search = this.searchTerm.toLowerCase().trim();
            this.displayedProducts = this.allProducts.filter(p => {
                return (p.base_name && p.base_name.toLowerCase().includes(search)) ||
                       (p.sku && p.sku.toLowerCase().includes(search)) ||
                       (p.weight_variant && p.weight_variant.toLowerCase().includes(search)) ||
                       (p.grade && p.grade.toLowerCase().includes(search));
            });
        },
        
        // Add product to cart
        async addToCart(product) {
            if (this.updateMutex.get(product.variant_id)) return;
            this.updateMutex.set(product.variant_id, true);
            
            try {
                if (product.stock_quantity <= 0) {
                    alert('This product is out of stock');
                    return;
                }
                
                const existingItem = this.cart.find(item => item.variant_id === product.variant_id);
                
                if (existingItem) {
                    if (existingItem.quantity < product.stock_quantity) {
                        existingItem.quantity++;
                    } else {
                        alert(`Maximum stock (${product.stock_quantity}) reached`);
                    }
                } else {
                    this.cart.push({
                        ...product,
                        quantity: 1,
                        max_quantity: product.stock_quantity
                    });
                }
            } finally {
                this.updateMutex.delete(product.variant_id);
            }
        },
        
        // Update quantity
        async updateQuantity(variantId, change) {
            if (this.updateMutex.get(variantId)) return;
            this.updateMutex.set(variantId, true);
            
            try {
                const item = this.cart.find(i => i.variant_id === variantId);
                if (!item) return;
                
                const newQuantity = item.quantity + change;
                
                if (newQuantity >= 1 && newQuantity <= item.max_quantity) {
                    item.quantity = newQuantity;
                }
            } finally {
                this.updateMutex.delete(variantId);
            }
        },
        
        // Set quantity directly
        setQuantity(variantId, value, maxStock) {
            const item = this.cart.find(i => i.variant_id === variantId);
            if (!item) return;
            
            let quantity = parseInt(value, 10);
            
            if (isNaN(quantity) || quantity < 1) {
                quantity = 1;
            } else if (quantity > maxStock) {
                quantity = maxStock;
                alert(`Maximum available: ${maxStock}`);
            }
            
            item.quantity = quantity;
        },
        
        // Remove from cart
        removeFromCart(variantId) {
            this.cart = this.cart.filter(item => item.variant_id !== variantId);
        },
        
        // Save new customer - FIXED with correct column name
        async saveNewCustomer() {
            this.addingCustomer = true;
            this.addCustomerError = '';
            
            const customerName = this.newCustomer.name.trim();
            const customerPhone = this.newCustomer.phone.trim();
            
            if (!customerName) {
                this.addCustomerError = 'Customer name is required';
                this.addingCustomer = false;
                return;
            }
            
            // Basic phone validation if provided
            if (customerPhone && !/^[\d\s\-\+\(\)]+$/.test(customerPhone)) {
                this.addCustomerError = 'Invalid phone number format';
                this.addingCustomer = false;
                return;
            }
            
            try {
                const response = await fetch('../customers/ajax_handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'add_pos_customer',
                        name: customerName,
                        phone_number: customerPhone,  // Fixed: correct column name
                        customer_type: 'POS'
                    })
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const result = await response.json();
                
                if (result.success) {
                    // Add to customers list
                    const newCust = {
                        id: result.id,
                        name: customerName,
                        business_name: '',
                        phone_number: customerPhone
                    };
                    this.customers.push(newCust);
                    this.selectedCustomerId = result.id.toString();
                    
                    // Reset and close modal
                    this.showAddCustomerModal = false;
                    this.resetCustomerForm();
                } else {
                    throw new Error(result.error || 'Failed to add customer');
                }
            } catch (error) {
                console.error('Add customer error:', error);
                this.addCustomerError = error.message;
            } finally {
                this.addingCustomer = false;
            }
        },
        
        // Reset customer form
        resetCustomerForm() {
            this.newCustomer = { name: '', phone: '' };
            this.addCustomerError = '';
        },
        
        // Place order
        async placeOrder() {
            if (this.cart.length === 0) {
                alert('Cart is empty');
                return;
            }
            
            if (!this.branch_id) {
                alert('Cannot place order: Branch not identified');
                return;
            }
            
            this.processingOrder = true;
            
            try {
                const orderData = {
                    action: 'place_order',
                    branch_id: this.branch_id,
                    customer_id: this.selectedCustomerId || null,
                    cart: this.cart.map(item => ({
                        variant_id: item.variant_id,
                        quantity: item.quantity,
                        unit_price: parseFloat(item.unit_price)
                    })),
                    subtotal: this.subtotal,
                    total: this.total,
                    payment_method: this.paymentMethod
                };
                
                console.log('Placing order:', orderData);
                
                const response = await fetch('ajax_handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(orderData)
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const result = await response.json();
                console.log('Order result:', result);
                
                if (result.success) {
                    alert(`Order #${result.order_number || result.order_id} placed successfully!`);
                    
                    // Clear cart
                    this.cart = [];
                    this.selectedCustomerId = '';
                    this.searchTerm = '';
                    this.filterProducts();
                    
                    // Refresh products to update stock
                    await this.refreshProducts();
                } else {
                    throw new Error(result.error || 'Order placement failed');
                }
            } catch (error) {
                console.error('Order placement error:', error);
                alert('Error placing order: ' + error.message);
            } finally {
                this.processingOrder = false;
            }
        },
        
        // Refresh products
        async refreshProducts() {
            try {
                const response = await fetch(`ajax_handler.php?action=get_products&branch_id=${this.branch_id}`, {
                    headers: {
                        'X-CSRF-Token': this.csrfToken,
                        'Accept': 'application/json'
                    }
                });
                
                if (response.ok) {
                    const data = await response.json();
                    if (data.success && data.products) {
                        this.allProducts = data.products;
                        this.filterProducts();
                    }
                }
            } catch (error) {
                console.error('Product refresh error:', error);
            }
        },
        
        // Helper methods
        formatCurrency(amount) {
            return parseFloat(amount).toFixed(2);
        },
        
        getStockClass(quantity) {
            if (quantity > 10) return 'bg-green-100 text-green-800';
            if (quantity > 0) return 'bg-yellow-100 text-yellow-800';
            return 'bg-red-100 text-red-800';
        },
        
        getStockText(quantity) {
            if (quantity <= 0) return 'Out of Stock';
            if (quantity === 1) return '1 in stock';
            return `${quantity} in stock`;
        },
        
        canPlaceOrder() {
            return this.branch_id !== null && this.branch_id !== undefined;
        }
    };
}
</script>

<?php
require_once '../templates/footer.php';
?>
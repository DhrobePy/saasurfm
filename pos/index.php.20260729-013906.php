<?php
/**
 * COMPLETE ENHANCED POS SYSTEM - FINAL VERSION
 * Features:
 * - Beautiful UI with gradient headers
 * - Item-level discounts (% or fixed)
 * - Cart-level discounts (% or fixed)
 * - Multiple payment methods with references
 * - Thermal receipt printing (3 copies)
 * - Full discount tracking
 */

require_once '../core/init.php';

// --- SECURITY & CONTEXT ---
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$allowed_roles = [
    'Superadmin',
    'admin', 
    'accountspos-demra',
    'accountspos-srg',
    'dispatchpos-demra',
    'dispatchpos-srg',
];
restrict_access($allowed_roles);

date_default_timezone_set('Asia/Dhaka');

global $db;
$pageTitle = 'Point of Sale';
$error = null;
$branch_id = null;
$branch_name = 'Unknown Branch';
$products = [];
$pos_customers = [];

// Get current user
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_display_name = $currentUser['display_name'] ?? 'Unknown User';
$user_role = $currentUser['role'] ?? '';

// Get user's branch
if ($user_id) {
    try {
        $employee_info = $db->query(
            "SELECT e.branch_id, b.name as branch_name, b.code as branch_code
             FROM employees e
             JOIN branches b ON e.branch_id = b.id
             WHERE e.user_id = ?",
            [$user_id]
        )->first();

        if ($employee_info && $employee_info->branch_id) {
            $branch_id = $employee_info->branch_id;
            $branch_name = $employee_info->branch_name ?? 'Unknown Branch';
            $pageTitle .= ' - ' . htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8');
        } else {
            if (in_array($user_role, ['Superadmin', 'admin'])) {
                $default_branch = $db->query(
                    "SELECT id, name FROM branches WHERE status = 'active' ORDER BY id LIMIT 1"
                )->first();
                
                if ($default_branch) {
                    $branch_id = $default_branch->id;
                    $branch_name = $default_branch->name;
                }
            }
        }
    } catch (Exception $e) {
        error_log("POS Branch Error: " . $e->getMessage());
        $error = "Error identifying branch: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
}

// Load products and customers
if (!$error || (in_array($user_role, ['Superadmin', 'admin']) && $branch_id)) {
    try {
        ensurePosLedgerTable();

        // Stock tracking removed from POS (Jul 2026, explicit decision) — no
        // inventory join, no stock gating. Every active priced product is sellable.
        $sql = "SELECT
                pv.id as variant_id,
                pv.sku,
                pv.weight_variant,
                pv.grade,
                pv.unit_of_measure,
                p.base_name,
                (SELECT pp.unit_price
                 FROM product_prices pp
                 WHERE pp.variant_id = pv.id
                   AND pp.is_active = 1
                 ORDER BY pp.effective_date DESC, pp.created_at DESC
                 LIMIT 1) as unit_price
            FROM product_variants pv
            JOIN products p ON pv.product_id = p.id
            WHERE p.status = 'active' AND pv.status = 'active'
            ORDER BY p.base_name, pv.sku";

        $products = $db->query($sql)->results();

        $valid_products = [];
        foreach ($products as $product) {
            $price = floatval($product->unit_price ?? 0);
            if ($price > 0) {
                $product->base_name = htmlspecialchars($product->base_name ?? '', ENT_QUOTES, 'UTF-8');
                $product->sku = htmlspecialchars($product->sku ?? '', ENT_QUOTES, 'UTF-8');
                $product->weight_variant = htmlspecialchars($product->weight_variant ?? '', ENT_QUOTES, 'UTF-8');
                $product->grade = htmlspecialchars($product->grade ?? '', ENT_QUOTES, 'UTF-8');
                $product->unit_of_measure = htmlspecialchars($product->unit_of_measure ?? '', ENT_QUOTES, 'UTF-8');
                $product->unit_price = (float) $product->unit_price;
                $valid_products[] = $product;
            }
        }
        $products = $valid_products;

        $pos_customers = $db->query(
            "SELECT c.id, c.name, c.business_name, c.phone_number, c.email, c.customer_type, c.credit_limit,
                    COALESCE((SELECT pl.balance_after FROM pos_customer_ledger pl WHERE pl.customer_id = c.id ORDER BY pl.id DESC LIMIT 1), 0) AS pos_outstanding
             FROM customers c
             WHERE c.customer_type = 'POS' AND c.status = 'active'
             ORDER BY c.name ASC"
        )->results();

        foreach ($pos_customers as &$customer) {
            $customer->name = htmlspecialchars($customer->name ?? '', ENT_QUOTES, 'UTF-8');
            $customer->business_name = htmlspecialchars($customer->business_name ?? '', ENT_QUOTES, 'UTF-8');
            $customer->phone_number = htmlspecialchars($customer->phone_number ?? '', ENT_QUOTES, 'UTF-8');
            $customer->email = htmlspecialchars($customer->email ?? '', ENT_QUOTES, 'UTF-8');
            $customer->credit_limit = (float)($customer->credit_limit ?? 0);
            $customer->pos_outstanding = (float)($customer->pos_outstanding ?? 0);
        }

    } catch (Exception $e) {
        error_log("POS Data Loading Error: " . $e->getMessage());
        $error = "Error loading POS data: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $products = [];
        $pos_customers = [];
    }
}

require_once '../templates/header.php';
?>

<!-- Alpine.js Function Definition - BEFORE HTML -->
<script>
function posApp() {
    return {
        // Data
        branch_id: <?php echo json_encode($branch_id); ?>,
        csrfToken: <?php echo json_encode($_SESSION['csrf_token']); ?>,
        currentTime: '',
        allProducts: <?php echo json_encode($products); ?>,
        filteredProducts: [],
        customers: <?php echo json_encode($pos_customers); ?>,
        cart: [],
        selectedCustomerId: '',
        searchTerm: '',
        paymentMethod: 'Cash',
        paymentReference: '',
        bankName: '',
        mobileProvider: 'bKash',
        chargeToCredit: false,
        creditAmount: 0,
        processingOrder: false,
        queuedMessage: '',
        showQueuedModal: false,
        showAddCustomerModal: false,
        addingCustomer: false,
        addCustomerError: '',
        newCustomer: { name: '', phone: '' },
        
        // Discount properties
        cartDiscountType: 'none',
        cartDiscountValue: 0,
        
        // Print modal
        showPrintModal: false,
        lastOrderNumber: '',
        lastOrderTotal: 0,
        lastQrUrl: '',
        printCopies: {
            office: true,
            customer: true,
            delivery: true
        },
        
        init() {
            console.log('POS App Initialized');
            console.log('Branch ID:', this.branch_id);
            console.log('Products loaded:', this.allProducts.length);
            console.log('Customers loaded:', this.customers.length);
            
            this.filterProducts();
            this.updateTime();
            setInterval(() => this.updateTime(), 1000);
        },
        
        updateTime() {
            const now = new Date();
            this.currentTime = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', minute: '2-digit', second: '2-digit' 
            });
        },
        
        filterProducts() {
            if (!this.searchTerm.trim()) {
                this.filteredProducts = this.allProducts;
                return;
            }
            const search = this.searchTerm.toLowerCase();
            this.filteredProducts = this.allProducts.filter(p => 
                p.base_name.toLowerCase().includes(search) ||
                p.sku.toLowerCase().includes(search) ||
                (p.weight_variant && p.weight_variant.toLowerCase().includes(search))
            );
        },
        
        addToCart(product) {
            const existingItem = this.cart.find(item => item.variant_id === product.variant_id);

            if (existingItem) {
                existingItem.quantity++;
            } else {
                this.cart.push({
                    variant_id: product.variant_id,
                    base_name: product.base_name,
                    sku: product.sku,
                    unit_price: product.unit_price,
                    quantity: 1,
                    item_discount_type: 'none',
                    item_discount_value: 0
                });
            }
        },

        updateQuantity(variantId, quantity) {
            const item = this.cart.find(i => i.variant_id === variantId);
            if (!item) return;

            if (quantity < 1) quantity = 1;
            item.quantity = quantity;
        },
        
        removeFromCart(variantId) {
            this.cart = this.cart.filter(item => item.variant_id !== variantId);
        },
        
        getItemDiscount(item) {
            if (item.item_discount_type === 'percentage') {
                return (item.unit_price * item.quantity * item.item_discount_value) / 100;
            } else if (item.item_discount_type === 'fixed') {
                return parseFloat(item.item_discount_value) || 0;
            }
            return 0;
        },
        
        getItemTotal(item) {
            const subtotal = item.unit_price * item.quantity;
            const discount = this.getItemDiscount(item);
            return subtotal - discount;
        },
        
        get subtotal() {
            return this.cart.reduce((sum, item) => sum + this.getItemTotal(item), 0);
        },
        
        get cartDiscount() {
            if (this.cartDiscountType === 'percentage') {
                return (this.subtotal * parseFloat(this.cartDiscountValue || 0)) / 100;
            } else if (this.cartDiscountType === 'fixed') {
                return parseFloat(this.cartDiscountValue) || 0;
            }
            return 0;
        },
        
        get total() {
            return Math.max(0, this.subtotal - this.cartDiscount);
        },

        get selectedCustomer() {
            if (!this.selectedCustomerId) return null;
            return this.customers.find(c => c.id.toString() === this.selectedCustomerId.toString()) || null;
        },

        get creditAmountEffective() {
            if (!this.chargeToCredit || !this.selectedCustomerId) return 0;
            const amt = parseFloat(this.creditAmount) || 0;
            return Math.min(Math.max(0, amt), this.total);
        },

        get cashAmountEffective() {
            return Math.max(0, this.total - this.creditAmountEffective);
        },

        get projectedCustomerBalance() {
            const c = this.selectedCustomer;
            if (!c) return null;
            return (parseFloat(c.pos_outstanding) || 0) + this.creditAmountEffective;
        },

        get overCreditLimit() {
            const c = this.selectedCustomer;
            if (!c || !this.chargeToCredit) return false;
            return this.projectedCustomerBalance > (parseFloat(c.credit_limit) || 0);
        },

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
            
            try {
                const response = await fetch('../customers/ajax_handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'add_pos_customer',
                        name: customerName,
                        phone_number: customerPhone,
                        customer_type: 'POS'
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    this.customers.push({
                        id: result.id,
                        name: customerName,
                        phone_number: customerPhone
                    });
                    this.selectedCustomerId = result.id.toString();
                    this.showAddCustomerModal = false;
                    this.resetCustomerForm();
                    alert('Customer added successfully!');
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
        
        resetCustomerForm() {
            this.newCustomer = { name: '', phone: '' };
            this.addCustomerError = '';
        },
        
        async placeOrder() {
            if (this.cart.length === 0) {
                alert('Cart is empty');
                return;
            }

            if (!this.branch_id) {
                alert('Cannot place order: Branch not identified');
                return;
            }

            const cashPortion = this.cashAmountEffective;
            const creditPortion = this.creditAmountEffective;
            const cashMethod = (cashPortion > 0.009) ? this.paymentMethod : null;

            if (this.chargeToCredit && creditPortion > 0.009 && !this.selectedCustomerId) {
                alert('Select a customer before charging any amount to credit');
                return;
            }

            // Validate payment reference for bank/mobile-banking transactions covering the cash portion
            if (cashMethod && ['Bank Deposit', 'Bank Transfer', 'bKash', 'Nagad'].includes(cashMethod) && !this.paymentReference.trim()) {
                alert('Please enter a payment reference / transaction ID');
                return;
            }
            if (cashMethod === 'Bank Deposit' || cashMethod === 'Bank Transfer') {
                if (!this.bankName.trim()) { alert('Please enter bank name'); return; }
            }

            if (this.overCreditLimit) {
                const c = this.selectedCustomer;
                const proceed = confirm(
                    `${c.name} already owes ৳${this.formatCurrency(c.pos_outstanding)} on POS credit. ` +
                    `Adding ৳${this.formatCurrency(creditPortion)} would push it to ৳${this.formatCurrency(this.projectedCustomerBalance)}, ` +
                    `over their ৳${this.formatCurrency(c.credit_limit)} limit.\n\n` +
                    `This will be sent to an admin for approval instead of completing immediately — goods should NOT be handed over until approved. Continue?`
                );
                if (!proceed) return;
            }

            this.processingOrder = true;

            try {
                // bKash/Nagad map to the 'Mobile Banking' payment_method column,
                // with the specific provider folded into the reference text.
                let serverPaymentMethod = cashMethod || 'Credit';
                let serverReference = this.paymentReference.trim() || null;
                if (cashMethod === 'bKash' || cashMethod === 'Nagad') {
                    serverPaymentMethod = 'Mobile Banking';
                    serverReference = `${cashMethod}${serverReference ? ' - ' + serverReference : ''}`;
                }

                const orderData = {
                    action: 'place_order',
                    branch_id: this.branch_id,
                    customer_id: this.selectedCustomerId || null,
                    cart: this.cart.map(item => ({
                        variant_id: item.variant_id,
                        quantity: item.quantity,
                        unit_price: parseFloat(item.unit_price),
                        item_discount_type: item.item_discount_type,
                        item_discount_value: parseFloat(item.item_discount_value) || 0
                    })),
                    subtotal: this.subtotal,
                    cart_discount_type: this.cartDiscountType,
                    cart_discount_value: parseFloat(this.cartDiscountValue) || 0,
                    total: this.total,
                    cash_paid: cashPortion,
                    credit_amount: creditPortion,
                    payment_method: serverPaymentMethod,
                    payment_reference: serverReference,
                    bank_name: this.bankName.trim() || null
                };

                const response = await fetch('ajax_handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(orderData)
                });

                const result = await response.json();

                if (result.success && result.queued) {
                    this.queuedMessage = result.message || 'This sale needs admin approval before it can complete.';
                    this.showQueuedModal = true;
                    this.cart = [];
                    this.selectedCustomerId = '';
                    this.searchTerm = '';
                    this.cartDiscountType = 'none';
                    this.cartDiscountValue = 0;
                    this.chargeToCredit = false;
                    this.creditAmount = 0;
                    this.paymentReference = '';
                    this.bankName = '';
                    this.filterProducts();
                } else if (result.success) {
                    this.lastOrderNumber = result.order_number;
                    this.lastOrderTotal = this.total;
                    this.lastQrUrl = result.qr_url || '';

                    // Clear cart
                    this.cart = [];
                    this.selectedCustomerId = '';
                    this.searchTerm = '';
                    this.cartDiscountType = 'none';
                    this.cartDiscountValue = 0;
                    this.chargeToCredit = false;
                    this.creditAmount = 0;
                    this.paymentReference = '';
                    this.bankName = '';
                    this.filterProducts();

                    // Show print modal
                    this.showPrintModal = true;
                    this.$nextTick(() => this.renderExitQr());

                    // Refresh products
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

        renderExitQr() {
            const el = document.getElementById('posExitQr');
            if (!el || typeof QRious === 'undefined' || !this.lastQrUrl) return;
            try { new QRious({ element: el, value: this.lastQrUrl, size: 120, level: 'M' }); } catch (e) {}
        },
        
        printReceipt(copyType) {
            // Mark this copy as printed
            this.printCopies[copyType] = true;
            
            // Open print window
            window.open(
                `print_receipt.php?order_number=${this.lastOrderNumber}&copy_type=${copyType}`,
                '_blank',
                'width=800,height=600'
            );
        },
        
        printAllCopies() {
            ['office', 'customer', 'delivery'].forEach(copyType => {
                if (this.printCopies[copyType]) {
                    setTimeout(() => this.printReceipt(copyType), 500);
                }
            });
            this.showPrintModal = false;
        },
        
        async refreshProducts() {
            try {
                const response = await fetch(`ajax_handler.php?action=get_products&branch_id=${this.branch_id}`, {
                    headers: { 'X-CSRF-Token': this.csrfToken }
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
        
        formatCurrency(amount) {
            return parseFloat(amount || 0).toFixed(2);
        },
        
        canPlaceOrder() {
            return this.branch_id !== null;
        }
    };
}
console.log('posApp function defined');
</script>

<style>
[x-cloak] { display: none !important; }

</style>

<div id="pos-app" x-data="posApp()" x-init="init()" class="max-w-screen-2xl mx-auto p-4">
    
    <?php if ($error): ?>
    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-red-700"><?php echo $error; ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Header with Gradient -->
    <div class="bg-gradient-to-r from-blue-500 to-blue-600 shadow-lg rounded-xl p-6 mb-4 text-white">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold">🏪 Point of Sale</h1>
                <p class="text-sm opacity-90 mt-1">
                    📍 <?php echo htmlspecialchars($branch_name); ?> | 
                    👤 <?php echo htmlspecialchars($user_display_name); ?>
                </p>
            </div>
            <div class="text-right">
                <div class="text-sm opacity-90"><?php echo date('l, F j, Y'); ?></div>
                <div class="text-xl font-bold" x-text="currentTime"></div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        
        <!-- Products Section (Left) -->
        <div class="lg:col-span-2 space-y-4">
            
            <!-- Search -->
            <div class="bg-white shadow-sm rounded-lg p-4">
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
                        <input 
                            type="text" 
                            x-model="searchTerm" 
                            @input="filterProducts()"
                            placeholder="Search products by name, SKU..." 
                            class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                    </div>
                    <button 
                        @click="searchTerm = ''; filterProducts();" 
                        class="px-6 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium rounded-lg transition-colors"
                    >
                        Clear
                    </button>
                </div>
                <div class="mt-2 text-sm text-gray-600">
                    Showing <span class="font-semibold" x-text="filteredProducts.length"></span> of <span class="font-semibold" x-text="allProducts.length"></span> products
                </div>
            </div>

            <!-- Products Grid -->
            <div class="bg-white shadow-sm rounded-lg p-4">
                <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                    Products
                </h2>
                
                <template x-if="filteredProducts.length === 0 && allProducts.length > 0">
                    <div class="text-center py-12 text-gray-500">
                        <svg class="mx-auto h-16 w-16 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <p class="mt-4 font-medium">No products match your search</p>
                        <p class="text-sm mt-1">Try different keywords</p>
                    </div>
                </template>
                
                <template x-if="allProducts.length === 0">
                    <div class="text-center py-12 text-gray-500">
                        <svg class="mx-auto h-16 w-16 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                        </svg>
                        <p class="mt-4 font-medium">No products available</p>
                        <p class="text-sm mt-1">Check inventory and pricing</p>
                    </div>
                </template>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3 max-h-96 overflow-y-auto">
                    <template x-for="product in filteredProducts" :key="product.variant_id">
                        <div
                            class="border-2 border-gray-200 rounded-xl p-4 transition-all cursor-pointer hover:shadow-lg hover:border-blue-400 bg-white"
                            @click="addToCart(product)"
                        >
                            <div class="flex justify-between items-start mb-3">
                                <h3 class="font-bold text-gray-800 text-sm line-clamp-2 flex-1" x-text="product.base_name"></h3>
                            </div>
                            <p class="text-xs text-gray-600 mb-1">
                                SKU: <span class="font-semibold" x-text="product.sku"></span>
                            </p>
                            <p class="text-xs text-gray-600 mb-3">
                                <span x-text="product.weight_variant"></span>
                                <span x-text="product.unit_of_measure"></span>
                                <template x-if="product.grade">
                                    <span class="ml-1">• Grade: <span class="font-medium" x-text="product.grade"></span></span>
                                </template>
                            </p>
                            <div class="flex justify-between items-center pt-3 border-t border-gray-200">
                                <span class="text-xl font-bold text-blue-600">
                                    ৳<span x-text="formatCurrency(product.unit_price)"></span>
                                </span>
                                <button
                                    @click.stop="addToCart(product)"
                                    class="px-4 py-2 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white text-sm font-semibold rounded-lg transition-all shadow-sm"
                                >
                                    <span>+ Add</span>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Cart Section (Right) - CONTINUES IN NEXT PART -->
        
<?php
// File is getting too long - I'll create it in parts
// Continuing in next response with Cart section, modals, and print functionality
?>
<!-- CART SECTION - Continue from Part 1 -->

        <!-- Cart Section (Right) -->
        <div class="lg:col-span-1">
            <div class="bg-white shadow-xl rounded-xl sticky top-4">
                
                <!-- Cart Header -->
                <div class="bg-gradient-to-r from-green-500 to-green-600 px-6 py-4 rounded-t-xl">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        Shopping Cart
                    </h2>
                </div>

                <div class="p-4">
                    <!-- Customer Selection -->
                    <div class="mb-4">
                        <label class="block text-sm font-bold text-gray-700 mb-2">Customer (Optional)</label>
                        <div class="flex gap-2">
                            <select 
                                x-model="selectedCustomerId" 
                                class="flex-1 px-3 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm font-medium"
                            >
                                <option value="">Walk-in Customer</option>
                                <template x-for="customer in customers" :key="customer.id">
                                    <option :value="customer.id.toString()" x-text="customer.name + (customer.phone_number ? ' - ' + customer.phone_number : '')"></option>
                                </template>
                            </select>
                            <button 
                                @click="showAddCustomerModal = true"
                                class="px-3 py-2 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white rounded-lg transition-all flex-shrink-0 shadow-sm"
                                title="Add New Customer"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Cart Items -->
                    <div class="border-t-2 border-b-2 border-gray-200 py-3 mb-4 max-h-64 overflow-y-auto">
                        <template x-if="cart.length === 0">
                            <div class="text-center py-8 text-gray-500">
                                <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                                <p class="mt-2 text-sm font-medium">Cart is empty</p>
                                <p class="text-xs text-gray-400 mt-1">Add products to begin</p>
                            </div>
                        </template>
                        
                        <template x-for="(item, index) in cart" :key="item.variant_id">
                            <div class="mb-3 pb-3 border-b border-gray-100 last:border-0">
                                <!-- Item Header -->
                                <div class="flex items-start justify-between mb-2">
                                    <div class="flex-1 min-w-0">
                                        <h4 class="font-bold text-sm text-gray-800 truncate" x-text="item.base_name"></h4>
                                        <p class="text-xs text-gray-600" x-text="item.sku"></p>
                                    </div>
                                    <button 
                                        @click="removeFromCart(item.variant_id)"
                                        class="text-red-500 hover:text-red-700 p-1 transition-colors ml-2"
                                        title="Remove"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </div>

                                <!-- Quantity Controls -->
                                <div class="flex items-center gap-2 mb-2">
                                    <button 
                                        @click="updateQuantity(item.variant_id, item.quantity - 1)"
                                        class="w-8 h-8 bg-gray-100 hover:bg-gray-200 rounded-lg flex items-center justify-center transition-colors font-bold"
                                    >
                                        −
                                    </button>
                                    <input
                                        type="number"
                                        :value="item.quantity"
                                        @change="updateQuantity(item.variant_id, parseInt($event.target.value) || 1)"
                                        min="1"
                                        class="w-16 text-center border-2 border-gray-300 rounded-lg py-1 text-sm font-bold"
                                    >
                                    <button 
                                        @click="updateQuantity(item.variant_id, item.quantity + 1)"
                                        class="w-8 h-8 bg-gray-100 hover:bg-gray-200 rounded-lg flex items-center justify-center transition-colors font-bold"
                                    >
                                        +
                                    </button>
                                    <span class="text-sm text-gray-600 ml-1">×</span>
                                    <span class="text-sm font-bold text-gray-700">৳<span x-text="formatCurrency(item.unit_price)"></span></span>
                                </div>

                                <!-- Item Discount -->
                                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-2 mb-2">
                                    <label class="text-xs font-semibold text-gray-700 block mb-1">Item Discount</label>
                                    <div class="flex items-center gap-2">
                                        <select 
                                            x-model="item.item_discount_type"
                                            class="text-xs border border-gray-300 rounded px-2 py-1 flex-1 font-medium"
                                        >
                                            <option value="none">No Discount</option>
                                            <option value="percentage">% Off</option>
                                            <option value="fixed">৳ Off</option>
                                        </select>
                                        <input 
                                            type="number" 
                                            x-model="item.item_discount_value"
                                            x-show="item.item_discount_type !== 'none'"
                                            min="0"
                                            step="0.01"
                                            class="w-20 text-xs border border-gray-300 rounded px-2 py-1 font-medium"
                                            placeholder="0"
                                        >
                                    </div>
                                </div>

                                <!-- Item Total -->
                                <div class="text-right">
                                    <template x-if="getItemDiscount(item) > 0">
                                        <div class="text-xs text-gray-500 line-through">
                                            ৳<span x-text="formatCurrency(item.unit_price * item.quantity)"></span>
                                        </div>
                                    </template>
                                    <div class="text-sm font-bold text-blue-600">
                                        = ৳<span x-text="formatCurrency(getItemTotal(item))"></span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Cart-Level Discount -->
                    <div class="mb-4 p-3 bg-gradient-to-r from-yellow-50 to-yellow-100 border-2 border-yellow-300 rounded-lg" x-show="cart.length > 0">
                        <label class="block text-sm font-bold text-gray-800 mb-2 flex items-center">
                            <svg class="w-4 h-4 mr-1 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Cart Discount
                        </label>
                        <div class="flex gap-2">
                            <select 
                                x-model="cartDiscountType"
                                class="flex-1 text-sm border-2 border-gray-300 rounded-lg px-3 py-2 font-medium"
                            >
                                <option value="none">No Discount</option>
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount (৳)</option>
                            </select>
                            <input 
                                type="number" 
                                x-model="cartDiscountValue"
                                x-show="cartDiscountType !== 'none'"
                                min="0"
                                step="0.01"
                                class="w-24 text-sm border-2 border-gray-300 rounded-lg px-3 py-2 font-bold"
                                placeholder="0"
                            >
                        </div>
                    </div>

                    <!-- Totals -->
                    <div class="space-y-2 mb-4 p-3 bg-gray-50 rounded-lg">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600 font-medium">Items:</span>
                            <span class="font-bold" x-text="cart.length"></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600 font-medium">Subtotal:</span>
                            <span class="font-bold">৳<span x-text="formatCurrency(subtotal)"></span></span>
                        </div>
                        <div class="flex justify-between text-sm text-red-600" x-show="cartDiscount > 0">
                            <span class="font-medium">Cart Discount:</span>
                            <span class="font-bold">-৳<span x-text="formatCurrency(cartDiscount)"></span></span>
                        </div>
                        <div class="flex justify-between text-xl font-bold pt-2 border-t-2 border-gray-300">
                            <span>Total:</span>
                            <span class="text-green-600">৳<span x-text="formatCurrency(total)"></span></span>
                        </div>
                    </div>

                    <!-- Charge part (or all) to Customer Credit -->
                    <div class="mb-4 p-3 bg-purple-50 border-2 border-purple-200 rounded-lg" x-show="cart.length > 0">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" x-model="chargeToCredit" :disabled="!selectedCustomerId" class="w-5 h-5">
                            <span class="text-sm font-bold text-gray-800">Charge part of this sale to customer credit</span>
                        </label>
                        <template x-if="!selectedCustomerId">
                            <p class="text-xs text-gray-500 mt-1">Select a customer above to enable this.</p>
                        </template>
                        <template x-if="chargeToCredit && selectedCustomerId">
                            <div class="mt-2">
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Amount to charge to credit (rest is paid now)</label>
                                <input type="number" x-model="creditAmount" min="0" :max="total" step="0.01"
                                       class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg text-sm font-bold">
                                <div class="flex justify-between text-xs mt-2 text-gray-700">
                                    <span>Pay now: <strong x-text="'৳' + formatCurrency(cashAmountEffective)"></strong></span>
                                    <span>On credit: <strong x-text="'৳' + formatCurrency(creditAmountEffective)"></strong></span>
                                </div>
                                <template x-if="selectedCustomer">
                                    <p class="text-xs mt-2" :class="overCreditLimit ? 'text-red-600 font-bold' : 'text-gray-500'">
                                        Current POS balance: ৳<span x-text="formatCurrency(selectedCustomer.pos_outstanding)"></span>
                                        of ৳<span x-text="formatCurrency(selectedCustomer.credit_limit)"></span> limit
                                        <template x-if="overCreditLimit"> — exceeds limit, will need admin approval</template>
                                    </p>
                                </template>
                            </div>
                        </template>
                    </div>

                    <!-- Payment Method (covers the "pay now" portion) -->
                    <div class="mb-4" x-show="cashAmountEffective > 0.009">
                        <label class="block text-sm font-bold text-gray-700 mb-2">Payment Method (for the amount paid now)</label>
                        <select
                            x-model="paymentMethod"
                            class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 font-medium"
                        >
                            <option value="Cash">💵 Cash</option>
                            <option value="Bank Deposit">🏦 Bank Deposit</option>
                            <option value="Bank Transfer">💳 Bank Transfer</option>
                            <option value="Card">💳 Card</option>
                            <option value="bKash">📱 bKash</option>
                            <option value="Nagad">📱 Nagad</option>
                        </select>
                    </div>

                    <!-- Payment Reference (for Bank/mobile-banking transactions) -->
                    <div class="mb-4" x-show="cashAmountEffective > 0.009 && ['Bank Deposit', 'Bank Transfer', 'bKash', 'Nagad'].includes(paymentMethod)">
                        <template x-if="paymentMethod === 'Bank Deposit' || paymentMethod === 'Bank Transfer'">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">Bank Name *</label>
                                <input
                                    type="text"
                                    x-model="bankName"
                                    class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                    placeholder="e.g., Brac Bank, Dutch Bangla Bank"
                                >
                            </div>
                        </template>
                        <label class="block text-sm font-bold text-gray-700 mb-2 mt-3">Reference / Transaction ID *</label>
                        <input
                            type="text"
                            x-model="paymentReference"
                            class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                            :placeholder="(paymentMethod === 'bKash' || paymentMethod === 'Nagad') ? 'Transaction ID' : 'e.g., DEP123456789 or TRN987654321'"
                        >
                    </div>

                    <template x-if="chargeToCredit && cashAmountEffective <= 0.009">
                        <div class="mb-4 text-xs text-purple-700 bg-purple-50 border border-purple-200 rounded-lg p-3">
                            Entire amount is going on credit — no payment method needed for this sale.
                        </div>
                    </template>

                    <!-- Action Buttons -->
                    <div class="space-y-2">
                        <button 
                            @click="placeOrder()"
                            :disabled="cart.length === 0 || processingOrder || !canPlaceOrder()"
                            class="w-full px-4 py-4 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white font-bold text-lg rounded-lg transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2 shadow-lg"
                        >
                            <template x-if="processingOrder">
                                <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </template>
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="!processingOrder">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span x-text="processingOrder ? 'Processing...' : 'Complete Sale'"></span>
                        </button>
                        <button 
                            @click="cart = []; selectedCustomerId = ''; cartDiscountType = 'none'; cartDiscountValue = 0; chargeToCredit = false; creditAmount = 0;"
                            :disabled="cart.length === 0"
                            class="w-full px-4 py-3 bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            🗑️ Clear Cart
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODALS CONTINUE IN PART 3 -->
<!-- MODALS - Part 3 -->

    <!-- Add Customer Modal -->
    <div 
        x-show="showAddCustomerModal" 
        x-cloak
        class="fixed inset-0 z-50 overflow-y-auto"
        @keydown.escape.window="showAddCustomerModal = false"
    >
        <!-- Backdrop -->
        <div 
            class="fixed inset-0 bg-black bg-opacity-50 transition-opacity"
            @click="showAddCustomerModal = false"
        ></div>
        
        <!-- Modal Container -->
        <div class="flex items-center justify-center min-h-screen p-4">
            <div 
                class="relative bg-white rounded-xl shadow-2xl max-w-md w-full transform transition-all"
                @click.stop
            >
                <!-- Modal Header -->
                <div class="bg-gradient-to-r from-blue-500 to-blue-600 px-6 py-4 rounded-t-xl">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xl font-bold text-white flex items-center">
                            <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                            </svg>
                            Add New Customer
                        </h3>
                        <button 
                            @click="showAddCustomerModal = false; resetCustomerForm();"
                            class="text-white hover:text-gray-200 transition-colors"
                        >
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Modal Body -->
                <div class="px-6 py-5">
                    <!-- Error Message -->
                    <template x-if="addCustomerError">
                        <div class="mb-4 bg-red-50 border-l-4 border-red-500 p-4 rounded">
                            <div class="flex">
                                <svg class="w-5 h-5 text-red-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                </svg>
                                <p class="text-sm text-red-700" x-text="addCustomerError"></p>
                            </div>
                        </div>
                    </template>

                    <!-- Form -->
                    <div class="space-y-4">
                        <!-- Name Field -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                Customer Name <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                </div>
                                <input 
                                    type="text" 
                                    x-model="newCustomer.name"
                                    class="w-full pl-10 pr-3 py-3 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                    placeholder="Enter customer name"
                                    @keydown.enter="saveNewCustomer()"
                                    :disabled="addingCustomer"
                                >
                            </div>
                        </div>

                        <!-- Phone Field -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                Phone Number <span class="text-gray-400 text-xs">(Optional)</span>
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                                    </svg>
                                </div>
                                <input 
                                    type="tel" 
                                    x-model="newCustomer.phone"
                                    class="w-full pl-10 pr-3 py-3 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                    placeholder="01XXXXXXXXX"
                                    @keydown.enter="saveNewCustomer()"
                                    :disabled="addingCustomer"
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="bg-gray-50 px-6 py-4 rounded-b-xl flex gap-3">
                    <button 
                        @click="showAddCustomerModal = false; resetCustomerForm();"
                        :disabled="addingCustomer"
                        class="flex-1 px-4 py-3 bg-white border-2 border-gray-300 text-gray-700 font-semibold rounded-lg hover:bg-gray-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Cancel
                    </button>
                    <button 
                        @click="saveNewCustomer()"
                        :disabled="addingCustomer || !newCustomer.name.trim()"
                        class="flex-1 px-4 py-3 bg-gradient-to-r from-blue-500 to-blue-600 text-white font-semibold rounded-lg hover:from-blue-600 hover:to-blue-700 transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center"
                    >
                        <template x-if="addingCustomer">
                            <svg class="animate-spin h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </template>
                        <span x-text="addingCustomer ? 'Saving...' : 'Save Customer'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Print Receipt Modal -->
    <div 
        x-show="showPrintModal" 
        x-cloak
        class="fixed inset-0 z-50 overflow-y-auto"
        @keydown.escape.window="showPrintModal = false"
    >
        <!-- Backdrop -->
        <div 
            class="fixed inset-0 bg-black bg-opacity-50 transition-opacity"
        ></div>
        
        <!-- Modal Container -->
        <div class="flex items-center justify-center min-h-screen p-4">
            <div 
                class="relative bg-white rounded-xl shadow-2xl max-w-lg w-full transform transition-all"
                @click.stop
            >
                <!-- Success Header -->
                <div class="bg-gradient-to-r from-green-500 to-green-600 px-6 py-6 rounded-t-xl text-center">
                    <div class="mx-auto w-16 h-16 bg-white rounded-full flex items-center justify-center mb-4">
                        <svg class="w-10 h-10 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-white">Order Completed!</h3>
                    <p class="text-white text-opacity-90 mt-2">
                        Order #<span class="font-mono font-bold" x-text="lastOrderNumber"></span>
                    </p>
                </div>

                <!-- Modal Body -->
                <div class="px-6 py-6">
                    <div class="text-center mb-4" x-show="lastQrUrl">
                        <canvas id="posExitQr" width="120" height="120" class="mx-auto"></canvas>
                        <p class="text-xs text-gray-500 mt-2">Exit QR — have security/gate scan this before the customer leaves with the goods</p>
                    </div>
                    <div class="text-center mb-6">
                        <p class="text-gray-600 text-sm mb-2">Total Amount</p>
                        <p class="text-4xl font-bold text-green-600">
                            ৳<span x-text="formatCurrency(lastOrderTotal)"></span>
                        </p>
                    </div>

                    <div class="bg-blue-50 border-2 border-blue-200 rounded-lg p-4 mb-6">
                        <h4 class="font-bold text-gray-800 mb-3 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                            </svg>
                            Select Receipt Copies to Print
                        </h4>
                        
                        <div class="space-y-2">
                            <label class="flex items-center p-3 bg-white border-2 border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors">
                                <input 
                                    type="checkbox" 
                                    x-model="printCopies.office"
                                    class="w-5 h-5 text-blue-600 mr-3"
                                >
                                <div class="flex-1">
                                    <span class="font-semibold text-gray-800">📋 Office Copy</span>
                                    <p class="text-xs text-gray-600">For internal records</p>
                                </div>
                            </label>

                            <label class="flex items-center p-3 bg-white border-2 border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors">
                                <input 
                                    type="checkbox" 
                                    x-model="printCopies.customer"
                                    class="w-5 h-5 text-blue-600 mr-3"
                                >
                                <div class="flex-1">
                                    <span class="font-semibold text-gray-800">🧾 Customer Copy</span>
                                    <p class="text-xs text-gray-600">For customer receipt</p>
                                </div>
                            </label>

                            <label class="flex items-center p-3 bg-white border-2 border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors">
                                <input 
                                    type="checkbox" 
                                    x-model="printCopies.delivery"
                                    class="w-5 h-5 text-blue-600 mr-3"
                                >
                                <div class="flex-1">
                                    <span class="font-semibold text-gray-800">🚚 Delivery Copy</span>
                                    <p class="text-xs text-gray-600">For delivery/dispatch</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex gap-3">
                        <button 
                            @click="showPrintModal = false"
                            class="flex-1 px-4 py-3 bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold rounded-lg transition-colors"
                        >
                            Skip Printing
                        </button>
                        <button 
                            @click="printAllCopies()"
                            class="flex-1 px-4 py-3 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white font-semibold rounded-lg transition-all flex items-center justify-center"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                            </svg>
                            Print Selected
                        </button>
                    </div>

                    <p class="text-center text-xs text-gray-500 mt-4">
                        💡 You can reprint receipts from the Orders page
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Queued for Approval Modal -->
    <div
        x-show="showQueuedModal"
        x-cloak
        class="fixed inset-0 z-50 overflow-y-auto"
        @keydown.escape.window="showQueuedModal = false"
    >
        <div class="fixed inset-0 bg-black bg-opacity-50 transition-opacity"></div>
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="relative bg-white rounded-xl shadow-2xl max-w-md w-full transform transition-all" @click.stop>
                <div class="bg-gradient-to-r from-amber-500 to-amber-600 px-6 py-6 rounded-t-xl text-center">
                    <div class="mx-auto w-16 h-16 bg-white rounded-full flex items-center justify-center mb-4">
                        <svg class="w-10 h-10 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-white">Awaiting Approval</h3>
                </div>
                <div class="px-6 py-6">
                    <p class="text-sm text-gray-700" x-text="queuedMessage"></p>
                    <button
                        @click="showQueuedModal = false"
                        class="w-full mt-6 px-4 py-3 bg-gray-800 hover:bg-gray-900 text-white font-semibold rounded-lg transition-colors"
                    >
                        OK
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Alpine.js is already loaded once by templates/header.php (unpkg CDN) —
     loading it again here (this page's old standalone-header leftover)
     makes Alpine throw on the second init and can leave x-cloak'd elements,
     including the header's own nav, stuck hidden. QRious only, no Alpine. -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"></script>

<?php require_once '../templates/footer.php'; ?>
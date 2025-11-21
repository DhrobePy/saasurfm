<?php
require_once '../core/init.php';
//requireLogin();

$pageTitle = "Daily Activities - Accounts";

// Get selected date (default to today)
$selected_date = $_GET['date'] ?? date('Y-m-d');
$date_obj = new DateTime($selected_date);

// Get all branches for filter
$branches_query = "SELECT id, name FROM branches ORDER BY name";
$branches_result = $conn->query($branches_query);
$branches = $branches_result->fetch_all(MYSQLI_ASSOC);

// Get selected branch (default to all)
$selected_branch = $_GET['branch'] ?? 'all';

// Build branch filter for queries
$branch_filter = ($selected_branch !== 'all') ? " AND branch_id = " . intval($selected_branch) : "";

// 1. Get Journal Entries (All Accounting Transactions)
$journal_query = "
    SELECT 
        je.id,
        je.entry_number,
        je.entry_date,
        je.reference_type,
        je.reference_id,
        je.description,
        je.posted_by_user_id,
        u.display_name as posted_by,
        b.name as branch_name,
        je.created_at,
        (SELECT SUM(debit) FROM transaction_lines WHERE journal_entry_id = je.id) as total_debit,
        (SELECT SUM(credit) FROM transaction_lines WHERE journal_entry_id = je.id) as total_credit
    FROM journal_entries je
    LEFT JOIN users u ON je.posted_by_user_id = u.id
    LEFT JOIN branches b ON je.branch_id = b.id
    WHERE DATE(je.entry_date) = ?
    " . str_replace('branch_id', 'je.branch_id', $branch_filter) . "
    ORDER BY je.created_at DESC
";
$stmt = $conn->prepare($journal_query);
$stmt->bind_param("s", $selected_date);
$stmt->execute();
$journal_entries = $stmt->fetch_all(MYSQLI_ASSOC);

// 2. Get Credit Orders
$orders_query = "
    SELECT 
        co.id,
        co.order_number,
        co.order_date,
        co.required_date,
        co.status,
        co.total_amount,
        co.amount_paid,
        co.balance_due,
        co.total_weight_kg,
        c.name as customer_name,
        c.phone_number as customer_phone,
        b.name as branch_name,
        u.display_name as created_by,
        co.created_at
    FROM credit_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN branches b ON co.assigned_branch_id = b.id
    LEFT JOIN users u ON co.created_by_user_id = u.id
    WHERE DATE(co.order_date) = ?
    " . str_replace('branch_id', 'co.assigned_branch_id', $branch_filter) . "
    ORDER BY co.created_at DESC
";
$stmt = $conn->prepare($orders_query);
$stmt->bind_param("s", $selected_date);
$stmt->execute();
$credit_orders = $stmt->fetch_all(MYSQLI_ASSOC);

// 3. Get POS Sales
$pos_query = "
    SELECT 
        ps.id,
        ps.sale_number,
        ps.sale_date,
        ps.sale_time,
        ps.total_amount,
        ps.payment_method,
        ps.customer_name,
        ps.customer_phone,
        b.name as branch_name,
        u.display_name as cashier,
        ps.created_at
    FROM pos_sales ps
    LEFT JOIN branches b ON ps.branch_id = b.id
    LEFT JOIN users u ON ps.user_id = u.id
    WHERE DATE(ps.sale_date) = ?
    " . str_replace('branch_id', 'ps.branch_id', $branch_filter) . "
    ORDER BY ps.sale_time DESC
";
$stmt = $conn->prepare($pos_query);
$stmt->bind_param("s", $selected_date);
$stmt->execute();
$pos_sales = $stmt->fetch_all(MYSQLI_ASSOC);

// 4. Get Purchase Invoices
$purchase_query = "
    SELECT 
        pi.id,
        pi.invoice_number,
        pi.invoice_date,
        pi.total_amount,
        pi.status,
        s.name as supplier_name,
        s.phone as supplier_phone,
        b.name as branch_name,
        u.display_name as created_by,
        pi.created_at
    FROM purchase_invoices pi
    LEFT JOIN suppliers s ON pi.supplier_id = s.id
    LEFT JOIN branches b ON pi.branch_id = b.id
    LEFT JOIN users u ON pi.created_by_user_id = u.id
    WHERE DATE(pi.invoice_date) = ?
    " . str_replace('branch_id', 'pi.branch_id', $branch_filter) . "
    ORDER BY pi.created_at DESC
";
$stmt = $conn->prepare($purchase_query);
$stmt->bind_param("s", $selected_date);
$stmt->execute();
$purchase_invoices = $stmt->fetch_all(MYSQLI_ASSOC);

// 5. Get Customer Payments
$customer_payments_query = "
    SELECT 
        cp.id,
        cp.payment_number,
        cp.payment_date,
        cp.amount,
        cp.payment_method,
        cp.allocation_status,
        c.name as customer_name,
        c.phone_number as customer_phone,
        b.name as branch_name,
        coa.name as payment_account,
        u.display_name as received_by,
        cp.created_at
    FROM customer_payments cp
    LEFT JOIN customers c ON cp.customer_id = c.id
    LEFT JOIN branches b ON cp.branch_id = b.id
    LEFT JOIN chart_of_accounts coa ON cp.payment_account_id = coa.id
    LEFT JOIN users u ON cp.received_by_user_id = u.id
    WHERE DATE(cp.payment_date) = ?
    " . str_replace('branch_id', 'cp.branch_id', $branch_filter) . "
    ORDER BY cp.created_at DESC
";
$stmt = $conn->prepare($customer_payments_query);
$stmt->bind_param("s", $selected_date);
$stmt->execute();
$customer_payments = $stmt->fetch_all(MYSQLI_ASSOC);

// 6. Get Supplier Payments
$supplier_payments_query = "
    SELECT 
        sp.id,
        sp.payment_number,
        sp.payment_date,
        sp.amount,
        sp.payment_method,
        s.name as supplier_name,
        s.phone as supplier_phone,
        b.name as branch_name,
        coa.name as payment_account,
        u.display_name as paid_by,
        sp.created_at
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON sp.supplier_id = s.id
    LEFT JOIN branches b ON sp.branch_id = b.id
    LEFT JOIN chart_of_accounts coa ON sp.payment_account_id = coa.id
    LEFT JOIN users u ON sp.created_by_user_id = u.id
    WHERE DATE(sp.payment_date) = ?
    " . str_replace('branch_id', 'sp.branch_id', $branch_filter) . "
    ORDER BY sp.created_at DESC
";
$stmt = $conn->prepare($supplier_payments_query);
$stmt->bind_param("s", $selected_date);
$stmt->execute();
$supplier_payments = $stmt->fetch_all(MYSQLI_ASSOC);

// 7. Get Transport Expenses
$transport_expenses_query = "
    SELECT 
        te.id,
        te.expense_date,
        te.expense_type,
        te.amount,
        te.description,
        v.registration_number as vehicle,
        d.name as driver_name,
        ta.trip_number,
        u.display_name as created_by,
        te.created_at
    FROM transport_expenses te
    LEFT JOIN vehicles v ON te.vehicle_id = v.id
    LEFT JOIN drivers d ON te.driver_id = d.id
    LEFT JOIN trip_assignments ta ON te.trip_id = ta.id
    LEFT JOIN users u ON te.created_by_user_id = u.id
    WHERE DATE(te.expense_date) = ?
    ORDER BY te.created_at DESC
";
$stmt = $conn->prepare($transport_expenses_query);
$stmt->bind_param("s", $selected_date);
$stmt->execute();
$transport_expenses = $stmt->fetch_all(MYSQLI_ASSOC);

// Calculate Summary Statistics
$summary_stats = [
    'total_journal_entries' => count($journal_entries),
    'total_debit' => array_sum(array_column($journal_entries, 'total_debit')),
    'total_credit' => array_sum(array_column($journal_entries, 'total_credit')),
    'credit_orders_count' => count($credit_orders),
    'credit_orders_amount' => array_sum(array_column($credit_orders, 'total_amount')),
    'pos_sales_count' => count($pos_sales),
    'pos_sales_amount' => array_sum(array_column($pos_sales, 'total_amount')),
    'purchase_invoices_count' => count($purchase_invoices),
    'purchase_invoices_amount' => array_sum(array_column($purchase_invoices, 'total_amount')),
    'customer_payments_count' => count($customer_payments),
    'customer_payments_amount' => array_sum(array_column($customer_payments, 'amount')),
    'supplier_payments_count' => count($supplier_payments),
    'supplier_payments_amount' => array_sum(array_column($supplier_payments, 'amount')),
    'transport_expenses_count' => count($transport_expenses),
    'transport_expenses_amount' => array_sum(array_column($transport_expenses, 'amount')),
];

include __DIR__ . '/header.php';
?>

<style>
    .activity-card {
        transition: all 0.3s ease;
    }
    .activity-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    }
    .stat-card {
        transition: all 0.3s ease;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    }
    .tab-button {
        transition: all 0.2s ease;
    }
    .tab-button.active {
        border-bottom: 3px solid #0ea5e9;
        color: #0ea5e9;
    }
    @media print {
        .no-print {
            display: none;
        }
        .activity-card {
            break-inside: avoid;
        }
    }
</style>

<div class="container mx-auto px-4 py-8">
    <!-- Page Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">
            <i class="fas fa-calendar-day text-primary-600 mr-3"></i>
            Daily Activities Dashboard
        </h1>
        <p class="text-gray-600">Comprehensive view of all business activities for <?php echo $date_obj->format('l, F j, Y'); ?></p>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6 no-print">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-calendar mr-2"></i>Select Date
                </label>
                <input type="date" 
                       name="date" 
                       value="<?php echo htmlspecialchars($selected_date); ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-building mr-2"></i>Branch
                </label>
                <select name="branch" 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    <option value="all" <?php echo $selected_branch === 'all' ? 'selected' : ''; ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo $branch['id']; ?>" 
                                <?php echo $selected_branch == $branch['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($branch['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="flex items-end">
                <button type="submit" 
                        class="w-full bg-primary-600 text-white px-6 py-2 rounded-lg hover:bg-primary-700 transition-colors">
                    <i class="fas fa-filter mr-2"></i>Apply Filter
                </button>
            </div>
        </form>
    </div>

    <!-- Summary Statistics -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Journal Entries -->
        <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-white bg-opacity-20 rounded-lg">
                    <i class="fas fa-book-open text-2xl"></i>
                </div>
                <span class="text-3xl font-bold"><?php echo $summary_stats['total_journal_entries']; ?></span>
            </div>
            <h3 class="text-lg font-semibold mb-1">Journal Entries</h3>
            <p class="text-sm opacity-90">Dr: ৳<?php echo number_format($summary_stats['total_debit'], 2); ?></p>
            <p class="text-sm opacity-90">Cr: ৳<?php echo number_format($summary_stats['total_credit'], 2); ?></p>
        </div>

        <!-- Sales Revenue -->
        <div class="stat-card bg-gradient-to-br from-green-500 to-green-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-white bg-opacity-20 rounded-lg">
                    <i class="fas fa-shopping-cart text-2xl"></i>
                </div>
                <span class="text-3xl font-bold"><?php echo $summary_stats['credit_orders_count'] + $summary_stats['pos_sales_count']; ?></span>
            </div>
            <h3 class="text-lg font-semibold mb-1">Total Sales</h3>
            <p class="text-sm opacity-90">Credit: ৳<?php echo number_format($summary_stats['credit_orders_amount'], 2); ?></p>
            <p class="text-sm opacity-90">POS: ৳<?php echo number_format($summary_stats['pos_sales_amount'], 2); ?></p>
        </div>

        <!-- Payments -->
        <div class="stat-card bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-white bg-opacity-20 rounded-lg">
                    <i class="fas fa-money-bill-wave text-2xl"></i>
                </div>
                <span class="text-3xl font-bold"><?php echo $summary_stats['customer_payments_count'] + $summary_stats['supplier_payments_count']; ?></span>
            </div>
            <h3 class="text-lg font-semibold mb-1">Payments</h3>
            <p class="text-sm opacity-90">Received: ৳<?php echo number_format($summary_stats['customer_payments_amount'], 2); ?></p>
            <p class="text-sm opacity-90">Paid: ৳<?php echo number_format($summary_stats['supplier_payments_amount'], 2); ?></p>
        </div>

        <!-- Expenses -->
        <div class="stat-card bg-gradient-to-br from-red-500 to-red-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 bg-white bg-opacity-20 rounded-lg">
                    <i class="fas fa-receipt text-2xl"></i>
                </div>
                <span class="text-3xl font-bold"><?php echo $summary_stats['purchase_invoices_count'] + $summary_stats['transport_expenses_count']; ?></span>
            </div>
            <h3 class="text-lg font-semibold mb-1">Expenses</h3>
            <p class="text-sm opacity-90">Purchases: ৳<?php echo number_format($summary_stats['purchase_invoices_amount'], 2); ?></p>
            <p class="text-sm opacity-90">Transport: ৳<?php echo number_format($summary_stats['transport_expenses_amount'], 2); ?></p>
        </div>
    </div>

    <!-- Tabbed Activity Sections -->
    <div class="bg-white rounded-lg shadow-lg overflow-hidden" x-data="{ activeTab: 'journal' }">
        <!-- Tab Navigation -->
        <div class="border-b border-gray-200 overflow-x-auto no-print">
            <nav class="flex space-x-4 px-6 min-w-max">
                <button @click="activeTab = 'journal'" 
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'journal' }">
                    <i class="fas fa-book-open mr-2"></i>Journal Entries (<?php echo count($journal_entries); ?>)
                </button>
                <button @click="activeTab = 'credit'" 
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'credit' }">
                    <i class="fas fa-file-invoice mr-2"></i>Credit Orders (<?php echo count($credit_orders); ?>)
                </button>
                <button @click="activeTab = 'pos'" 
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'pos' }">
                    <i class="fas fa-cash-register mr-2"></i>POS Sales (<?php echo count($pos_sales); ?>)
                </button>
                <button @click="activeTab = 'purchases'" 
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'purchases' }">
                    <i class="fas fa-shopping-bag mr-2"></i>Purchases (<?php echo count($purchase_invoices); ?>)
                </button>
                <button @click="activeTab = 'customer_payments'" 
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'customer_payments' }">
                    <i class="fas fa-hand-holding-usd mr-2"></i>Customer Payments (<?php echo count($customer_payments); ?>)
                </button>
                <button @click="activeTab = 'supplier_payments'" 
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'supplier_payments' }">
                    <i class="fas fa-money-check mr-2"></i>Supplier Payments (<?php echo count($supplier_payments); ?>)
                </button>
                <button @click="activeTab = 'expenses'" 
                        class="tab-button py-4 px-4 text-sm font-medium whitespace-nowrap"
                        :class="{ 'active': activeTab === 'expenses' }">
                    <i class="fas fa-truck mr-2"></i>Transport Expenses (<?php echo count($transport_expenses); ?>)
                </button>
            </nav>
        </div>

        <!-- Tab Content -->
        <div class="p-6">
            <!-- Journal Entries Tab -->
            <div x-show="activeTab === 'journal'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-book-open text-primary-600 mr-2"></i>Journal Entries
                </h2>
                <?php if (empty($journal_entries)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No journal entries found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($journal_entries as $entry): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($entry['entry_number']); ?>
                                            <span class="ml-2 text-sm text-gray-500">
                                                <?php echo htmlspecialchars($entry['reference_type']); ?>
                                            </span>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <?php echo htmlspecialchars($entry['description']); ?>
                                        </p>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-lg font-semibold text-green-600">
                                            Dr: ৳<?php echo number_format($entry['total_debit'], 2); ?>
                                        </div>
                                        <div class="text-lg font-semibold text-blue-600">
                                            Cr: ৳<?php echo number_format($entry['total_credit'], 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <?php if ($entry['branch_name']): ?>
                                        <span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($entry['branch_name']); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($entry['posted_by']); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($entry['created_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Credit Orders Tab -->
            <div x-show="activeTab === 'credit'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-file-invoice text-primary-600 mr-2"></i>Credit Orders
                </h2>
                <?php if (empty($credit_orders)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No credit orders found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($credit_orders as $order): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($order['order_number']); ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php 
                                                echo match($order['status']) {
                                                    'approved' => 'bg-green-100 text-green-800',
                                                    'pending' => 'bg-yellow-100 text-yellow-800',
                                                    'ready_to_ship' => 'bg-blue-100 text-blue-800',
                                                    'shipped' => 'bg-purple-100 text-purple-800',
                                                    default => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
                                            </span>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($order['customer_name']); ?>
                                            <?php if ($order['customer_phone']): ?>
                                                <span class="ml-2"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($order['customer_phone']); ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-gray-900">
                                            ৳<?php echo number_format($order['total_amount'], 2); ?>
                                        </div>
                                        <?php if ($order['balance_due'] > 0): ?>
                                            <div class="text-sm text-red-600">
                                                Due: ৳<?php echo number_format($order['balance_due'], 2); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <?php if ($order['branch_name']): ?>
                                        <span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($order['branch_name']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($order['total_weight_kg']): ?>
                                        <span><i class="fas fa-weight mr-1"></i><?php echo number_format($order['total_weight_kg'], 2); ?> kg</span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($order['created_by']); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($order['created_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- POS Sales Tab -->
            <div x-show="activeTab === 'pos'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-cash-register text-primary-600 mr-2"></i>POS Sales
                </h2>
                <?php if (empty($pos_sales)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No POS sales found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($pos_sales as $sale): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($sale['sale_number']); ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php 
                                                echo match($sale['payment_method']) {
                                                    'cash' => 'bg-green-100 text-green-800',
                                                    'card' => 'bg-blue-100 text-blue-800',
                                                    'mobile' => 'bg-purple-100 text-purple-800',
                                                    default => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst($sale['payment_method']); ?>
                                            </span>
                                        </h3>
                                        <?php if ($sale['customer_name']): ?>
                                            <p class="text-sm text-gray-600 mt-1">
                                                <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($sale['customer_name']); ?>
                                                <?php if ($sale['customer_phone']): ?>
                                                    <span class="ml-2"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($sale['customer_phone']); ?></span>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-green-600">
                                            ৳<?php echo number_format($sale['total_amount'], 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <?php if ($sale['branch_name']): ?>
                                        <span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($sale['branch_name']); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($sale['cashier']); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($sale['sale_time'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Purchase Invoices Tab -->
            <div x-show="activeTab === 'purchases'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-shopping-bag text-primary-600 mr-2"></i>Purchase Invoices
                </h2>
                <?php if (empty($purchase_invoices)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No purchase invoices found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($purchase_invoices as $invoice): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php 
                                                echo match($invoice['status']) {
                                                    'posted' => 'bg-green-100 text-green-800',
                                                    'draft' => 'bg-yellow-100 text-yellow-800',
                                                    default => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst($invoice['status']); ?>
                                            </span>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <i class="fas fa-truck mr-1"></i><?php echo htmlspecialchars($invoice['supplier_name']); ?>
                                            <?php if ($invoice['supplier_phone']): ?>
                                                <span class="ml-2"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($invoice['supplier_phone']); ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-red-600">
                                            ৳<?php echo number_format($invoice['total_amount'], 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <?php if ($invoice['branch_name']): ?>
                                        <span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($invoice['branch_name']); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($invoice['created_by']); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($invoice['created_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Customer Payments Tab -->
            <div x-show="activeTab === 'customer_payments'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-hand-holding-usd text-primary-600 mr-2"></i>Customer Payments
                </h2>
                <?php if (empty($customer_payments)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No customer payments found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($customer_payments as $payment): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($payment['payment_number']); ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php 
                                                echo match($payment['payment_method']) {
                                                    'cash' => 'bg-green-100 text-green-800',
                                                    'cheque' => 'bg-blue-100 text-blue-800',
                                                    'bank_transfer' => 'bg-purple-100 text-purple-800',
                                                    default => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                                            </span>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php 
                                                echo match($payment['allocation_status']) {
                                                    'allocated' => 'bg-green-100 text-green-800',
                                                    'partial' => 'bg-yellow-100 text-yellow-800',
                                                    'unallocated' => 'bg-red-100 text-red-800',
                                                    default => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst($payment['allocation_status']); ?>
                                            </span>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($payment['customer_name']); ?>
                                            <?php if ($payment['customer_phone']): ?>
                                                <span class="ml-2"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($payment['customer_phone']); ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($payment['payment_account']): ?>
                                            <p class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-university mr-1"></i><?php echo htmlspecialchars($payment['payment_account']); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-green-600">
                                            ৳<?php echo number_format($payment['amount'], 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <?php if ($payment['branch_name']): ?>
                                        <span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($payment['branch_name']); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($payment['received_by']); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($payment['created_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Supplier Payments Tab -->
            <div x-show="activeTab === 'supplier_payments'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-money-check text-primary-600 mr-2"></i>Supplier Payments
                </h2>
                <?php if (empty($supplier_payments)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No supplier payments found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($supplier_payments as $payment): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo htmlspecialchars($payment['payment_number']); ?>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php 
                                                echo match($payment['payment_method']) {
                                                    'cash' => 'bg-green-100 text-green-800',
                                                    'cheque' => 'bg-blue-100 text-blue-800',
                                                    'bank_transfer' => 'bg-purple-100 text-purple-800',
                                                    default => 'bg-gray-100 text-gray-800'
                                                };
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                                            </span>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <i class="fas fa-truck mr-1"></i><?php echo htmlspecialchars($payment['supplier_name']); ?>
                                            <?php if ($payment['supplier_phone']): ?>
                                                <span class="ml-2"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($payment['supplier_phone']); ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($payment['payment_account']): ?>
                                            <p class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-university mr-1"></i><?php echo htmlspecialchars($payment['payment_account']); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-red-600">
                                            ৳<?php echo number_format($payment['amount'], 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <?php if ($payment['branch_name']): ?>
                                        <span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($payment['branch_name']); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($payment['paid_by']); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($payment['created_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Transport Expenses Tab -->
            <div x-show="activeTab === 'expenses'" x-cloak>
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <i class="fas fa-truck text-primary-600 mr-2"></i>Transport Expenses
                </h2>
                <?php if (empty($transport_expenses)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">No transport expenses found for this date</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($transport_expenses as $expense): ?>
                            <div class="activity-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">
                                            <?php echo ucfirst(str_replace('_', ' ', $expense['expense_type'])); ?>
                                            <?php if ($expense['trip_number']): ?>
                                                <span class="ml-2 text-sm text-gray-500">
                                                    Trip: <?php echo htmlspecialchars($expense['trip_number']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <?php echo htmlspecialchars($expense['description']); ?>
                                        </p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            <?php if ($expense['vehicle']): ?>
                                                <span><i class="fas fa-truck mr-1"></i><?php echo htmlspecialchars($expense['vehicle']); ?></span>
                                            <?php endif; ?>
                                            <?php if ($expense['driver_name']): ?>
                                                <span class="ml-3"><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($expense['driver_name']); ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="text-right mt-2 md:mt-0">
                                        <div class="text-xl font-bold text-red-600">
                                            ৳<?php echo number_format($expense['amount'], 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-gray-600 mt-3 pt-3 border-t border-gray-300">
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($expense['created_by']); ?></span>
                                    <span><i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($expense['created_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="mt-8 flex justify-center space-x-4 no-print">
        <button onclick="window.print()" 
                class="bg-gray-600 text-white px-6 py-3 rounded-lg hover:bg-gray-700 transition-colors">
            <i class="fas fa-print mr-2"></i>Print Report
        </button>
        <a href="<?php echo url('accounts/index.php'); ?>" 
           class="bg-primary-600 text-white px-6 py-3 rounded-lg hover:bg-primary-700 transition-colors inline-block">
            <i class="fas fa-arrow-left mr-2"></i>Back to Accounts
        </a>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
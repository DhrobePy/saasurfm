<?php
require_once '../core/init.php';

global $db;

// Only Superadmin, admin, and Accounts can view expense history
restrict_access(['Superadmin', 'admin', 'Accounts']);

$pageTitle = "Expense Voucher History";

require_once '../core/classes/ExpenseManager.php';

$currentUser = getCurrentUser();
$expenseManager = new ExpenseManager($db, $currentUser['id']);

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'get_vouchers') {
        $filters = [
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'category_id' => $_GET['category_id'] ?? '',
            'subcategory_id' => $_GET['subcategory_id'] ?? '',
            'payment_method' => $_GET['payment_method'] ?? '',
            'bank_account_id' => $_GET['bank_account_id'] ?? '',
            'cash_account_id' => $_GET['cash_account_id'] ?? '',
            'branch_id' => $_GET['branch_id'] ?? '',
            'search' => $_GET['search'] ?? ''
        ];
        $vouchers = $expenseManager->getExpenseVouchers($filters);
        echo json_encode(['success' => true, 'vouchers' => $vouchers]);
        exit;
    }
    
    if ($_GET['ajax'] === 'get_summary') {
        $filters = [
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'category_id' => $_GET['category_id'] ?? ''
        ];
        $summary = $expenseManager->getExpenseSummary($filters);
        echo json_encode(['success' => true, 'summary' => $summary]);
        exit;
    }
    
    if ($_GET['ajax'] === 'get_subcategories') {
        $category_id = $_GET['category_id'] ?? 0;
        $subcategories = $expenseManager->getSubcategoriesByCategory($category_id);
        echo json_encode(['success' => true, 'subcategories' => $subcategories]);
        exit;
    }
    
    if ($_GET['ajax'] === 'export_excel') {
        // Export to Excel functionality
        $filters = [
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'category_id' => $_GET['category_id'] ?? '',
            'subcategory_id' => $_GET['subcategory_id'] ?? '',
            'payment_method' => $_GET['payment_method'] ?? '',
            'branch_id' => $_GET['branch_id'] ?? '',
            'search' => $_GET['search'] ?? ''
        ];
        $vouchers = $expenseManager->getExpenseVouchers($filters);
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="expense_vouchers_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Voucher Number', 'Date', 'Category', 'Subcategory', 'Amount', 'Payment Method', 'Account', 'Handled By', 'Branch', 'Remarks']);
        
        foreach ($vouchers as $voucher) {
            fputcsv($output, [
                $voucher->voucher_number,
                $voucher->expense_date,
                $voucher->category_name,
                $voucher->subcategory_name,
                $voucher->total_amount,
                ucfirst($voucher->payment_method),
                $voucher->payment_account_name,
                $voucher->handled_by_person ?? '-',
                $voucher->branch_name ?? '-',
                $voucher->remarks ?? '-'
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// Load dropdown data
$categories = $expenseManager->getAllCategories();
$bank_accounts = $expenseManager->getAllBankAccounts();
$cash_accounts = $expenseManager->getAllCashAccounts();
$branches = $expenseManager->getAllBranches();

// Set default date range (current month)
$default_date_from = date('Y-m-01');
$default_date_to = date('Y-m-d');

require_once '../templates/header.php';
?>

<div class="container mx-auto px-4 py-6">
    <!-- Page Header -->
    <div class="mb-6">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Expense Voucher History</h1>
                <p class="text-gray-600 mt-1">View and manage all expense transactions</p>
            </div>
            <div class="flex space-x-3">
                <button onclick="exportToExcel()" 
                        class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-file-excel mr-2"></i>
                    Export Excel
                </button>
                <a href="<?php echo url('expense/expense_voucher_create.php'); ?>" 
                   class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-plus mr-2"></i>
                    Create Voucher
                </a>
            </div>
        </div>
    </div>

    <?php echo display_message(); ?>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6" id="summary_cards">
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Total Vouchers</p>
                    <p class="text-2xl font-bold text-gray-900" id="total_vouchers">-</p>
                </div>
                <div class="bg-blue-100 p-3 rounded-full">
                    <i class="fas fa-receipt text-blue-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Total Expenses</p>
                    <p class="text-2xl font-bold text-gray-900" id="total_expense">৳0.00</p>
                </div>
                <div class="bg-red-100 p-3 rounded-full">
                    <i class="fas fa-money-bill-wave text-red-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Bank Payments</p>
                    <p class="text-2xl font-bold text-gray-900" id="bank_expenses">৳0.00</p>
                </div>
                <div class="bg-purple-100 p-3 rounded-full">
                    <i class="fas fa-university text-purple-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Cash Payments</p>
                    <p class="text-2xl font-bold text-gray-900" id="cash_expenses">৳0.00</p>
                </div>
                <div class="bg-green-100 p-3 rounded-full">
                    <i class="fas fa-money-bill-wave text-green-600 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Filters</h3>
            <button onclick="resetFilters()" class="text-sm text-primary-600 hover:text-primary-800">
                <i class="fas fa-redo mr-1"></i> Reset Filters
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <!-- Date From -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                <input type="date" id="filter_date_from" value="<?php echo $default_date_from; ?>"
                       onchange="loadVouchers()"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
            </div>

            <!-- Date To -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                <input type="date" id="filter_date_to" value="<?php echo $default_date_to; ?>"
                       onchange="loadVouchers()"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
            </div>

            <!-- Category -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                <select id="filter_category" onchange="loadFilterSubcategories(); loadVouchers();"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category->id; ?>">
                            <?php echo htmlspecialchars($category->category_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Subcategory -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Subcategory</label>
                <select id="filter_subcategory" onchange="loadVouchers()"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500"
                        disabled>
                    <option value="">Select Category First</option>
                </select>
            </div>

            <!-- Payment Method -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                <select id="filter_payment_method" onchange="toggleAccountFilter(); loadVouchers();"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                    <option value="">All Methods</option>
                    <option value="bank">Bank</option>
                    <option value="cash">Cash</option>
                </select>
            </div>

            <!-- Bank Account -->
            <div id="bank_filter_section" class="hidden">
                <label class="block text-sm font-medium text-gray-700 mb-1">Bank Account</label>
                <select id="filter_bank_account" onchange="loadVouchers()"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                    <option value="">All Bank Accounts</option>
                    <?php foreach ($bank_accounts as $account): ?>
                        <option value="<?php echo $account->id; ?>">
                            <?php echo htmlspecialchars($account->bank_name . ' - ' . $account->account_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Cash Account -->
            <div id="cash_filter_section" class="hidden">
                <label class="block text-sm font-medium text-gray-700 mb-1">Cash Account</label>
                <select id="filter_cash_account" onchange="loadVouchers()"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                    <option value="">All Cash Accounts</option>
                    <?php foreach ($cash_accounts as $account): ?>
                        <option value="<?php echo $account->id; ?>">
                            <?php echo htmlspecialchars($account->account_name); ?>
                            <?php if ($account->branch_name): ?>
                                - <?php echo htmlspecialchars($account->branch_name); ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Branch -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Branch</label>
                <select id="filter_branch" onchange="loadVouchers()"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo $branch->id; ?>">
                            <?php echo htmlspecialchars($branch->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Search -->
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                <input type="text" id="filter_search" placeholder="Search by voucher number, person, remarks..."
                       onkeyup="debounceSearch()"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
            </div>
        </div>
    </div>

    <!-- Vouchers Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="p-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-lg font-semibold text-gray-900">Expense Vouchers</h3>
            <div class="text-sm text-gray-600" id="record_count">Loading...</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Voucher #</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subcategory</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Handled By</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200" id="vouchers_tbody">
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                            <i class="fas fa-spinner fa-spin text-3xl mb-2"></i>
                            <p>Loading vouchers...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
let searchTimeout;
let currentFilters = {};

// Load vouchers on page load
document.addEventListener('DOMContentLoaded', function() {
    loadVouchers();
    loadSummary();
});

// Load vouchers with filters
async function loadVouchers() {
    currentFilters = {
        date_from: document.getElementById('filter_date_from').value,
        date_to: document.getElementById('filter_date_to').value,
        category_id: document.getElementById('filter_category').value,
        subcategory_id: document.getElementById('filter_subcategory').value,
        payment_method: document.getElementById('filter_payment_method').value,
        bank_account_id: document.getElementById('filter_bank_account')?.value || '',
        cash_account_id: document.getElementById('filter_cash_account')?.value || '',
        branch_id: document.getElementById('filter_branch').value,
        search: document.getElementById('filter_search').value
    };
    
    const params = new URLSearchParams(currentFilters);
    params.append('ajax', 'get_vouchers');
    
    try {
        const response = await fetch(`?${params.toString()}`);
        const result = await response.json();
        
        if (result.success) {
            renderVouchers(result.vouchers);
            loadSummary(); // Refresh summary
        }
    } catch (error) {
        console.error('Error loading vouchers:', error);
        document.getElementById('vouchers_tbody').innerHTML = `
            <tr>
                <td colspan="8" class="px-6 py-12 text-center text-red-500">
                    <i class="fas fa-exclamation-triangle text-3xl mb-2"></i>
                    <p>Error loading vouchers</p>
                </td>
            </tr>
        `;
    }
}

// Render vouchers in table
function renderVouchers(vouchers) {
    const tbody = document.getElementById('vouchers_tbody');
    
    if (vouchers.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                    <i class="fas fa-inbox text-6xl mb-4 text-gray-300"></i>
                    <p class="text-lg">No expense vouchers found</p>
                    <p class="text-sm">Try adjusting your filters</p>
                </td>
            </tr>
        `;
        document.getElementById('record_count').textContent = '0 records';
        return;
    }
    
    tbody.innerHTML = vouchers.map(voucher => `
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="text-sm font-medium text-primary-600">${voucher.voucher_number}</span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                ${formatDate(voucher.expense_date)}
            </td>
            <td class="px-6 py-4 text-sm text-gray-900">
                ${voucher.category_name}
            </td>
            <td class="px-6 py-4 text-sm text-gray-900">
                ${voucher.subcategory_name}
                ${voucher.unit_of_measurement ? `<span class="text-xs text-gray-500">(${voucher.unit_of_measurement})</span>` : ''}
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="text-sm font-semibold text-gray-900">৳${formatNumber(voucher.total_amount)}</span>
                ${voucher.unit_quantity && voucher.per_unit_cost ? 
                    `<br><span class="text-xs text-gray-500">${voucher.unit_quantity} × ৳${formatNumber(voucher.per_unit_cost)}</span>` 
                    : ''}
            </td>
            <td class="px-6 py-4 text-sm">
                <div class="flex items-center">
                    <i class="fas fa-${voucher.payment_method === 'bank' ? 'university' : 'money-bill-wave'} text-gray-400 mr-2"></i>
                    <div>
                        <p class="font-medium text-gray-900">${voucher.payment_method === 'bank' ? 'Bank' : 'Cash'}</p>
                        <p class="text-xs text-gray-500">${voucher.payment_account_name || '-'}</p>
                    </div>
                </div>
            </td>
            <td class="px-6 py-4 text-sm text-gray-900">
                ${voucher.handled_by_person || '-'}
                ${voucher.employee_name ? `<br><span class="text-xs text-gray-500">${voucher.employee_name}</span>` : ''}
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm">
                <a href="print_expense_voucher.php?id=${voucher.id}" target="_blank" 
                   class="text-primary-600 hover:text-primary-900 mr-3" title="Print Voucher">
                    <i class="fas fa-print"></i>
                </a>
                <a href="view_expense_voucher.php?id=${voucher.id}" target="_blank"
                   class="text-gray-600 hover:text-gray-900" title="View Details">
                    <i class="fas fa-eye"></i>
                </a>
            </td>
        </tr>
    `).join('');
    
    document.getElementById('record_count').textContent = `${vouchers.length} record${vouchers.length !== 1 ? 's' : ''}`;
}

// Load summary statistics
async function loadSummary() {
    const filters = {
        date_from: document.getElementById('filter_date_from').value,
        date_to: document.getElementById('filter_date_to').value,
        category_id: document.getElementById('filter_category').value
    };
    
    const params = new URLSearchParams(filters);
    params.append('ajax', 'get_summary');
    
    try {
        const response = await fetch(`?${params.toString()}`);
        const result = await response.json();
        
        if (result.success && result.summary) {
            const summary = result.summary;
            document.getElementById('total_vouchers').textContent = summary.total_vouchers || 0;
            document.getElementById('total_expense').textContent = '৳' + formatNumber(summary.total_expense || 0);
            document.getElementById('bank_expenses').textContent = '৳' + formatNumber(summary.bank_expenses || 0);
            document.getElementById('cash_expenses').textContent = '৳' + formatNumber(summary.cash_expenses || 0);
        }
    } catch (error) {
        console.error('Error loading summary:', error);
    }
}

// Load subcategories for filter
async function loadFilterSubcategories() {
    const categoryId = document.getElementById('filter_category').value;
    const subcategorySelect = document.getElementById('filter_subcategory');
    
    subcategorySelect.innerHTML = '<option value="">Loading...</option>';
    subcategorySelect.disabled = true;
    
    if (!categoryId) {
        subcategorySelect.innerHTML = '<option value="">All Subcategories</option>';
        return;
    }
    
    try {
        const response = await fetch(`?ajax=get_subcategories&category_id=${categoryId}`);
        const result = await response.json();
        
        if (result.success && result.subcategories.length > 0) {
            subcategorySelect.innerHTML = '<option value="">All Subcategories</option>';
            result.subcategories.forEach(sub => {
                const option = document.createElement('option');
                option.value = sub.id;
                option.textContent = sub.subcategory_name;
                subcategorySelect.appendChild(option);
            });
            subcategorySelect.disabled = false;
        } else {
            subcategorySelect.innerHTML = '<option value="">No Subcategories</option>';
        }
    } catch (error) {
        console.error('Error loading subcategories:', error);
        subcategorySelect.innerHTML = '<option value="">Error Loading</option>';
    }
}

// Toggle account filter visibility
function toggleAccountFilter() {
    const method = document.getElementById('filter_payment_method').value;
    const bankSection = document.getElementById('bank_filter_section');
    const cashSection = document.getElementById('cash_filter_section');
    
    if (method === 'bank') {
        bankSection.classList.remove('hidden');
        cashSection.classList.add('hidden');
    } else if (method === 'cash') {
        cashSection.classList.remove('hidden');
        bankSection.classList.add('hidden');
    } else {
        bankSection.classList.add('hidden');
        cashSection.classList.add('hidden');
    }
}

// Debounce search input
function debounceSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        loadVouchers();
    }, 500);
}

// Reset all filters
function resetFilters() {
    document.getElementById('filter_date_from').value = '<?php echo $default_date_from; ?>';
    document.getElementById('filter_date_to').value = '<?php echo $default_date_to; ?>';
    document.getElementById('filter_category').value = '';
    document.getElementById('filter_subcategory').value = '';
    document.getElementById('filter_subcategory').disabled = true;
    document.getElementById('filter_payment_method').value = '';
    document.getElementById('filter_branch').value = '';
    document.getElementById('filter_search').value = '';
    
    toggleAccountFilter();
    loadVouchers();
}

// Export to Excel
function exportToExcel() {
    const params = new URLSearchParams(currentFilters);
    params.append('ajax', 'export_excel');
    window.location.href = `?${params.toString()}`;
}

// Utility functions
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatNumber(number) {
    return parseFloat(number).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<?php require_once '../templates/footer.php'; ?>
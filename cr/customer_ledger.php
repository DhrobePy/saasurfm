<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$pageTitle = 'Customer Ledger';

// Get all customers
// FIX: Schema has 'phone_number', not 'phone'. Aliasing it to 'phone' to keep variable consistent.
$customers = $db->query(
    "SELECT id, name, phone_number as phone 
     FROM customers 
     WHERE status = 'active' 
     ORDER BY name ASC"
)->results();

// Get selected customer
$selected_customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01'); // First day of month
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d'); // Today

$customer_info = null;
$ledger_entries = [];
$summary = null;

if ($selected_customer_id) {
    // Get customer info with credit details
    $customer_info = $db->query(
    "SELECT id, name, phone_number, email, credit_limit, current_balance
     FROM customers
     WHERE id = ?",
        [$selected_customer_id]
    )->first();
    
    if ($customer_info) {
        // Get ledger entries
        $ledger_entries = $db->query(
            "SELECT * FROM customer_ledger 
             WHERE customer_id = ? 
             AND transaction_date BETWEEN ? AND ?
             ORDER BY transaction_date ASC, id ASC",
            [$selected_customer_id, $date_from, $date_to]
        )->results();
        
        // Calculate summary
        $summary = [
            'total_debits' => 0,
            'total_credits' => 0,
            'opening_balance' => 0,
            'closing_balance' => 0
        ];
        
        // Get opening balance (balance before date_from)
        $opening = $db->query(
            "SELECT COALESCE(MAX(balance_after), 0) as balance
             FROM customer_ledger
             WHERE customer_id = ? AND transaction_date < ?",
            [$selected_customer_id, $date_from]
        )->first();
        
        $summary['opening_balance'] = $opening ? $opening->balance : 0;
        
        // Calculate totals
        foreach ($ledger_entries as $entry) {
            $summary['total_debits'] += $entry->debit_amount;
            $summary['total_credits'] += $entry->credit_amount;
        }
        
        // Get closing balance (latest balance within date range)
        if (!empty($ledger_entries)) {
            $summary['closing_balance'] = end($ledger_entries)->balance_after;
        } else {
            $summary['closing_balance'] = $summary['opening_balance'];
        }
    }
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">View customer transaction history and balances</p>
</div>

<!-- Filters -->
<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <form method="GET" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-2">Customer *</label>
                <select name="customer_id" required class="w-full px-4 py-2 border rounded-lg" onchange="this.form.submit()">
                    <option value="">-- Select Customer --</option>
                    <?php foreach ($customers as $customer): ?>
                    <option value="<?php echo $customer->id; ?>" <?php echo $selected_customer_id == $customer->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($customer->name); ?> (<?php echo htmlspecialchars($customer->phone); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
                <input type="date" name="date_from" value="<?php echo $date_from; ?>" class="w-full px-4 py-2 border rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
                <input type="date" name="date_to" value="<?php echo $date_to; ?>" class="w-full px-4 py-2 border rounded-lg">
            </div>
        </div>
        
        <?php if ($selected_customer_id): ?>
        <div class="flex gap-3">
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fas fa-filter mr-2"></i>Apply Filter
            </button>
            <button type="button" onclick="window.print()" class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                <i class="fas fa-print mr-2"></i>Print Ledger
            </button>
            <!-- Updated Link to CSV Export -->
            <a href="customer_ledger_export.php?customer_id=<?php echo $selected_customer_id; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
               class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                <i class="fas fa-file-csv mr-2"></i>Export CSV
            </a>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php if ($customer_info): ?>

<!-- Customer Info & Summary -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
    <!-- Customer Details -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Customer Information</h2>
        <div class="space-y-2 text-sm">
            <div class="flex justify-between">
                <span class="text-gray-600">Name:</span>
                <span class="font-medium"><?php echo htmlspecialchars($customer_info->name); ?></span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-600">Phone:</span>
                <!-- FIX: Mapped variable name for display -->
                <span class="font-medium"><?php echo htmlspecialchars($customer_info->phone_number); ?></span>
            </div>
            <?php if ($customer_info->email): ?>
            <div class="flex justify-between">
                <span class="text-gray-600">Email:</span>
                <span class="font-medium"><?php echo htmlspecialchars($customer_info->email); ?></span>
            </div>
            <?php endif; ?>
            <div class="flex justify-between pt-2 border-t">
                <span class="text-gray-600">Credit Limit:</span>
                <span class="font-bold">৳<?php echo number_format($customer_info->credit_limit ?? 0, 2); ?></span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-600">Available Credit:</span>
                <!-- Calculated on the fly -->
                <span class="font-bold text-green-600">৳<?php echo number_format(($customer_info->credit_limit ?? 0) - ($customer_info->current_balance ?? 0), 2); ?></span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-600">Used Credit:</span>
                <span class="font-bold text-orange-600">৳<?php echo number_format($customer_info->current_balance ?? 0, 2); ?></span>
            </div>
        </div>
    </div>
    
    <!-- Summary -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Period Summary</h2>
        <div class="space-y-3">
            <div class="flex justify-between items-center p-3 bg-blue-50 rounded">
                <span class="text-gray-700">Opening Balance:</span>
                <span class="text-xl font-bold text-blue-600">৳<?php echo number_format($summary['opening_balance'], 2); ?></span>
            </div>
            <div class="flex justify-between items-center p-3 bg-red-50 rounded">
                <span class="text-gray-700">Total Debits (Invoices):</span>
                <span class="text-xl font-bold text-red-600">৳<?php echo number_format($summary['total_debits'], 2); ?></span>
            </div>
            <div class="flex justify-between items-center p-3 bg-green-50 rounded">
                <span class="text-gray-700">Total Credits (Payments):</span>
                <span class="text-xl font-bold text-green-600">৳<?php echo number_format($summary['total_credits'], 2); ?></span>
            </div>
            <div class="flex justify-between items-center p-3 bg-purple-50 rounded border-2 border-purple-300">
                <span class="text-gray-900 font-semibold">Closing Balance:</span>
                <span class="text-2xl font-bold text-purple-600">৳<?php echo number_format($summary['closing_balance'], 2); ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Ledger Entries -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <div class="p-6 border-b border-gray-200 bg-gray-50">
        <h2 class="text-xl font-bold text-gray-800">Transaction History</h2>
        <p class="text-sm text-gray-600 mt-1">
            Period: <?php echo date('M j, Y', strtotime($date_from)); ?> to <?php echo date('M j, Y', strtotime($date_to)); ?>
        </p>
    </div>
    
    <?php if (!empty($ledger_entries)): ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice/Ref</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Debit</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <!-- Opening Balance Row -->
                <?php if ($summary['opening_balance'] != 0): ?>
                <tr class="bg-blue-50">
                    <td class="px-4 py-3 text-sm font-medium"><?php echo date('M j, Y', strtotime($date_from)); ?></td>
                    <td class="px-4 py-3 text-sm" colspan="3">
                        <span class="font-semibold text-blue-700">Opening Balance</span>
                    </td>
                    <td class="px-4 py-3 text-sm text-right">-</td>
                    <td class="px-4 py-3 text-sm text-right">-</td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-blue-600">
                        ৳<?php echo number_format($summary['opening_balance'], 2); ?>
                    </td>
                </tr>
                <?php endif; ?>
                
                <!-- Transaction Rows -->
                <?php foreach ($ledger_entries as $entry): 
                    $type_colors = [
                        'invoice' => 'red',
                        'payment' => 'green',
                        'advance_payment' => 'blue',
                        'adjustment' => 'orange',
                        'credit_note' => 'purple',
                        'debit_note' => 'pink'
                    ];
                    $color = $type_colors[$entry->transaction_type] ?? 'gray';
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-sm whitespace-nowrap">
                        <?php echo date('M j, Y', strtotime($entry->transaction_date)); ?>
                    </td>
                    <td class="px-4 py-3 text-sm whitespace-nowrap">
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-800">
                            <?php echo ucwords(str_replace('_', ' ', $entry->transaction_type)); ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <?php echo htmlspecialchars($entry->description); ?>
                    </td>
                    <td class="px-4 py-3 text-sm whitespace-nowrap font-medium text-blue-600">
                        <?php echo htmlspecialchars($entry->invoice_number ?? '-'); ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-right font-medium text-red-600">
                        <?php echo $entry->debit_amount > 0 ? '৳' . number_format($entry->debit_amount, 2) : '-'; ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-right font-medium text-green-600">
                        <?php echo $entry->credit_amount > 0 ? '৳' . number_format($entry->credit_amount, 2) : '-'; ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">
                        ৳<?php echo number_format($entry->balance_after, 2); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <!-- Totals Row -->
                <tr class="bg-gray-100 font-semibold">
                    <td colspan="4" class="px-4 py-3 text-sm text-right">TOTALS:</td>
                    <td class="px-4 py-3 text-sm text-right text-red-600">
                        ৳<?php echo number_format($summary['total_debits'], 2); ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-right text-green-600">
                        ৳<?php echo number_format($summary['total_credits'], 2); ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-purple-600">
                        ৳<?php echo number_format($summary['closing_balance'], 2); ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="p-12 text-center text-gray-500">
        <i class="fas fa-inbox text-6xl mb-4"></i>
        <p class="text-lg">No transactions found for the selected period</p>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($selected_customer_id): ?>
<div class="bg-yellow-50 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded-r-lg">
    <p>Customer not found</p>
</div>

<?php else: ?>
<div class="bg-white rounded-lg shadow-md p-12 text-center">
    <i class="fas fa-user-circle text-6xl text-gray-400 mb-4"></i>
    <h3 class="text-xl font-semibold text-gray-700 mb-2">Select a Customer</h3>
    <p class="text-gray-600">Choose a customer from the dropdown above to view their ledger</p>
</div>
<?php endif; ?>

</div>

<style media="print">
@media print {
    .no-print { display: none !important; }
    body { font-size: 12px; }
    .shadow-md { box-shadow: none !important; }
}
</style>

<?php require_once '../templates/footer.php'; ?>
<?php
require_once '../core/init.php';

// --- SECURITY ---
$allowed_roles = [
    'Superadmin', 'admin', 'Accounts',
    'accounts-rampura', 'accounts-srg', 'accounts-demra',
    'accountspos-demra', 'accountspos-srg',
    'sales-srg', 'sales-demra', 'sales-other', 'collector'
];
restrict_access($allowed_roles);

// Get the $db instance
global $db;

// --- LOGIC: GET CUSTOMER ID ---
if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    $_SESSION['error_flash'] = 'Invalid customer ID provided.';
    header('Location: index.php');
    exit();
}
$customer_id = (int)$_GET['id'];

// --- DATA: FETCH CUSTOMER ---
$customer = $db->query("SELECT * FROM customers WHERE id = ?", [$customer_id])->first();

if (!$customer) {
    $_SESSION['error_flash'] = 'No customer found with that ID.';
    header('Location: index.php');
    exit();
}

// --- DATA: CALCULATE FINANCIALS ---
$available_credit = 0;
$utilization_percent = 0;
if ($customer->customer_type == 'Credit') {
    $available_credit = $customer->credit_limit - $customer->current_balance;
    if ($customer->credit_limit > 0) {
        $utilization_percent = ($customer->current_balance / $customer->credit_limit) * 100;
    } elseif ($customer->current_balance > 0) {
        $utilization_percent = 100; // Over limit even if limit is 0
    }
}

// --- DATA: FETCH LEDGER (Last 50 for this page) ---
$ledger_entries = $db->query(
    "SELECT *, 
    CASE 
        WHEN reference_type = 'credit_orders' THEN (SELECT order_number FROM credit_orders WHERE id = reference_id)
        WHEN reference_type = 'customer_payments' THEN (SELECT payment_number FROM customer_payments WHERE id = reference_id)
        ELSE reference_id 
    END AS reference_doc_number
    FROM customer_ledger 
    WHERE customer_id = ? 
    ORDER BY transaction_date DESC, id DESC 
    LIMIT 50",
    [$customer_id]
)->results();

// --- DATA: ANALYSIS & ADVICE ---
$ninety_days_ago = date('Y-m-d', strtotime('-90 days'));
$six_months_ago = date('Y-m-01', strtotime('-5 months')); // Start of 6-month period

// 1. Key stats for last 90 days
$stats = $db->query(
    "SELECT 
        COUNT(id) AS total_invoices,
        AVG(debit_amount) AS avg_invoice_value,
        MAX(debit_amount) AS max_invoice_value,
        (SELECT SUM(credit_amount) FROM customer_ledger WHERE customer_id = ? AND transaction_type = 'payment' AND transaction_date >= ?) AS total_paid_last_90_days
    FROM customer_ledger
    WHERE customer_id = ? AND transaction_type = 'invoice' AND transaction_date >= ?",
    [$customer_id, $ninety_days_ago, $customer_id, $ninety_days_ago]
)->first();

// 2. Sales trend for Chart.js
$sales_data = $db->query(
    "SELECT 
        DATE_FORMAT(transaction_date, '%Y-%m') AS month,
        SUM(debit_amount) AS total_invoice_amount
    FROM customer_ledger
    WHERE customer_id = ? 
    AND transaction_type = 'invoice'
    AND transaction_date >= ?
    GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
    ORDER BY month ASC",
    [$customer_id, $six_months_ago]
)->results();

// 3. Generate Advice
$advice = [];
$risk = 'low'; // Default risk
$risk_title = 'Customer Status: Good';
$risk_bg = 'bg-green-50';
$risk_icon = 'fa-check-circle';
$risk_icon_color = 'text-green-500';


if ($customer->credit_limit > 0) {
    if ($utilization_percent > 90) {
        $advice[] = "High Credit Utilization: Customer is using over 90% of their limit. Monitor closely.";
        $risk = 'high';
    } elseif ($utilization_percent > 75) {
        $advice[] = "Moderate Credit Utilization: Customer is using >75% of their limit. Be cautious with new large orders.";
        $risk = 'medium';
    }
}

if ($stats->total_invoices > 0 && $stats->total_paid_last_90_days < $stats->avg_invoice_value) {
    $advice[] = "Slow Payment Trend: Recent payments (last 90d) appear lower than new invoice values. Recommend reviewing A/R aging report.";
    $risk = ($risk == 'high') ? 'high' : 'medium';
}

if ($stats->total_invoices > 5 && $risk == 'low') {
    $advice[] = "Good Payer: Consistent order volume with low financial risk. This is a valuable customer.";
}

if ($customer->current_balance < 0) {
    $advice[] = "Customer in Advance: This customer has a positive balance (BDT " . number_format(abs($customer->current_balance), 2) . "). Ensure new orders are applied against this advance.";
}

if ($customer->status == 'blacklisted') {
    $advice[] = "Blacklisted: Do not issue any new credit or sales to this customer.";
    $risk = 'high';
}

if ($customer->status == 'inactive') {
    $advice[] = "Inactive: Customer account is marked inactive. Please verify status before placing new orders.";
    $risk = 'medium';
}

if (empty($advice)) {
    $advice[] = "Standard Customer: No immediate risks or opportunities detected. Continue standard monitoring.";
}

// Set visual risk parameters based on final risk level
if ($risk == 'high') {
    $risk_title = 'Action Required: High Risk';
    $risk_bg = 'bg-red-50';
    $risk_icon = 'fa-exclamation-triangle';
    $risk_icon_color = 'text-red-500';
} elseif ($risk == 'medium') {
    $risk_title = 'Caution Advised: Medium Risk';
    $risk_bg = 'bg-yellow-50';
    $risk_icon = 'fa-exclamation-circle';
    $risk_icon_color = 'text-yellow-500';
}


// --- PAGE ---
$pageTitle = 'View: ' . htmlspecialchars($customer->name);
require_once '../templates/header.php'; 
?>

<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900"><?php echo htmlspecialchars($customer->name); ?></h1>
        <p class="text-lg text-gray-600">Customer Profile & Financial Overview</p>
    </div>
    
    <div class="flex gap-2">
        <a href="manage.php?id=<?php echo $customer->id; ?>" class="px-4 py-2 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 shadow-sm">
            <i class="fas fa-edit mr-2"></i>Edit Customer
        </a>
        <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
            Back to List
        </a>
    </div>
</div>

<div class="space-y-8">

<div class="bg-white rounded-lg shadow-lg overflow-hidden">
    <div class="flex flex-col md:flex-row">
        <div class="md:w-1/3 p-6 flex flex-col items-center justify-center bg-gray-50 border-b md:border-b-0 md:border-r border-gray-200">
            <?php if ($customer->photo_url) : ?>
                <img class="h-40 w-40 rounded-full object-cover shadow-xl" src="<?php echo url($customer->photo_url); ?>" alt="Profile Photo">
            <?php else : ?>
                <span class="h-40 w-40 rounded-full bg-primary-100 text-primary-700 font-bold text-6xl flex items-center justify-center shadow-lg">
                    <?php echo get_initials($customer->name); // Assumes get_initials is in helpers.php ?>
                </span>
            <?php endif; ?>
            
            <div class="flex flex-wrap justify-center gap-2 mt-6">
                <?php if ($customer->customer_type == 'Credit') : ?>
                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                        <i class="fas fa-credit-card mr-2 opacity-75"></i>Credit Customer
                    </span>
                <?php else : ?>
                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                        <i class="fas fa-cash-register mr-2 opacity-75"></i>POS Customer
                    </span>
                <?php endif; ?>
                
                <?php if ($customer->status == 'active') : ?>
                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                        <i class="fas fa-check-circle mr-2 opacity-75"></i>Active
                    </span>
                <?php elseif ($customer->status == 'inactive') : ?>
                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                        <i class="fas fa-exclamation-circle mr-2 opacity-75"></i>Inactive
                    </span>
                <?php else : // blacklisted ?>
                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                        <i class="fas fa-ban mr-2 opacity-75"></i>Blacklisted
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="md:w-2/3 p-6">
            <h2 class="text-3xl font-bold text-gray-900"><?php echo htmlspecialchars($customer->name); ?></h2>
            <p class="text-xl text-gray-500 mb-6"><?php echo htmlspecialchars($customer->business_name ?? 'N/A'); ?></p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-500">Phone Number</label>
                    <p class="text-lg text-gray-900 font-semibold"><?php echo htmlspecialchars($customer->phone_number); ?></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-500">Email Address</label>
                    <p class="text-lg text-gray-900 font-semibold"><?php echo htmlspecialchars($customer->email ?? 'N/A'); ?></p>
                </div>
                <div class="col-span-1 sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-500">Business Address</label>
                    <p class="text-lg text-gray-800"><?php echo nl2br(htmlspecialchars($customer->business_address ?? 'N/A')); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>


<?php if ($customer->customer_type == 'Credit') : ?>
<div class="bg-white rounded-lg shadow-lg overflow-hidden">
    <h3 class="text-xl font-bold text-gray-800 p-4 border-b border-gray-200">
        Credit & Financial Status
    </h3>
    <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-center">
            <div class="p-4 bg-gray-50 rounded-lg shadow-inner">
                <label class="block text-sm font-medium text-gray-500">Total Credit Limit</label>
                <p class="text-4xl font-extrabold text-primary-700"><?php echo number_format($customer->credit_limit, 2); ?></p>
                <span class="text-sm text-gray-500">BDT</span>
            </div>
            <div class="p-4 <?php echo ($customer->current_balance > 0) ? 'bg-red-50' : 'bg-green-50'; ?> rounded-lg shadow-inner">
                <label class="block text-sm font-medium <?php echo ($customer->current_balance > 0) ? 'text-red-700' : 'text-green-700'; ?>">
                    <?php echo ($customer->current_balance >= 0) ? 'Current Due' : 'Advance'; ?>
                </label>
                <p class="text-4xl font-extrabold <?php echo ($customer->current_balance > 0) ? 'text-red-600' : 'text-green-600'; ?>">
                    <?php echo number_format(abs($customer->current_balance), 2); ?>
                </p>
                <span class="text-sm <?php echo ($customer->current_balance > 0) ? 'text-red-600' : 'text-green-600'; ?>">BDT</span>
            </div>
            <div class="p-4 <?php echo ($available_credit > 0) ? 'bg-green-50' : 'bg-red-50'; ?> rounded-lg shadow-inner">
                <label class="block text-sm font-medium <?php echo ($available_credit > 0) ? 'text-green-700' : 'text-green-700'; ?>">Available Credit</label>
                <p class="text-4xl font-extrabold <?php echo ($available_credit > 0) ? 'text-green-600' : 'text-red-600'; ?>">
                    <?php echo number_format($available_credit, 2); ?>
                </p>
                <span class="text-sm <?php echo ($available_credit > 0) ? 'text-green-600' : 'text-red-600'; ?>">BDT</span>
            </div>
        </div>
        
        <div class="mt-8">
            <div class="flex justify-between items-center mb-2">
                <span class="text-sm font-medium text-gray-600">Credit Utilization</span>
                <span class="text-sm font-bold <?php echo ($utilization_percent > 80) ? 'text-red-600' : 'text-gray-800'; ?>">
                    <?php echo number_format($utilization_percent, 1); ?>%
                </span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-4 shadow-inner">
                <?php
                    // Set bar color based on utilization
                    $bar_color = 'bg-primary-600';
                    if ($utilization_percent > 100) {
                        $bar_color = 'bg-red-600';
                        $utilization_percent = 100; // Cap bar at 100%
                    } elseif ($utilization_percent > 85) {
                        $bar_color = 'bg-red-500';
                    } elseif ($utilization_percent > 60) {
                        $bar_color = 'bg-yellow-500';
                    }
                ?>
                <div class="<?php echo $bar_color; ?> h-4 rounded-full transition-all duration-500 ease-out" 
                     style="width: <?php echo $utilization_percent; ?>%;">
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?> <div class="rounded-lg shadow-lg overflow-hidden <?php echo $risk_bg; ?>">
    <div class="p-5 flex items-start">
        <div class="flex-shrink-0">
            <i class="fas <?php echo $risk_icon; ?> <?php echo $risk_icon_color; ?> text-4xl"></i>
        </div>
        <div class="ml-4 w-full">
            <h3 class="text-xl font-bold text-gray-900"><?php echo $risk_title; ?></h3>
            <div class="mt-2">
                <ul class="space-y-2 list-disc list-inside text-gray-700">
                    <?php foreach ($advice as $item) : ?>
                        <li class="text-sm font-medium"><?php echo $item; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <div class="border-t border-gray-200 bg-white p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <h4 class="text-sm font-medium text-gray-500 uppercase mb-3">Key Metrics (Last 90 Days)</h4>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-gray-500">Total Invoices</label>
                    <p class="text-3xl font-bold text-gray-900"><?php echo $stats->total_invoices; ?></p>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Total Paid</label>
                    <p class="text-3xl font-bold text-green-600"><?php echo number_format($stats->total_paid_last_90_days, 0); ?></p>
                </div>
                <div class="col-span-2">
                    <label class="block text-xs text-gray-500">Avg. Invoice Value</label>
                    <p class="text-3xl font-bold text-gray-900"><?php echo number_format($stats->avg_invoice_value, 2); ?></p>
                </div>
            </div>
        </div>
        
        <div>
            <h4 class="text-sm font-medium text-gray-500 uppercase mb-3">Sales Trend (Last 6 Months)</h4>
            <div class="h-48">
                <canvas id="salesTrendChart"></canvas>
            </div>
        </div>
    </div>
</div>


<div class="bg-white rounded-lg shadow-lg overflow-hidden">
    <h3 class="text-xl font-bold text-gray-800 p-4 border-b border-gray-200">
        Customer Ledger (Last 50 Transactions)
    </h3>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Debit (Due)</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit (Paid)</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($ledger_entries)) : ?>
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-gray-500">No transactions found.</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($ledger_entries as $entry) : ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600"><?php echo date('d-M-Y', strtotime($entry->transaction_date)); ?></td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm">
                                <?php if ($entry->reference_type == 'credit_orders' || $entry->reference_type == 'customer_payments') : ?>
                                    <a href="#" class="text-primary-600 hover:underline font-medium open-transaction-modal" 
                                       data-ref-id="<?php echo $entry->reference_id; ?>" 
                                       data-ref-type="<?php echo $entry->reference_type; ?>"
                                       data-ref-number="<?php echo htmlspecialchars($entry->reference_doc_number); ?>">
                                        <?php echo htmlspecialchars($entry->reference_doc_number); ?>
                                    </a>
                                <?php else : ?>
                                    <span class="text-gray-700"><?php echo htmlspecialchars($entry->reference_doc_number ?? $entry->reference_id); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600"><?php echo ucwords(str_replace('_', ' ', $entry->transaction_type)); ?></td>
                            <td class="px-4 py-4 text-sm text-gray-800 min-w-[200px]"><?php echo htmlspecialchars($entry->description); ?></td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-800 text-right font-medium"><?php echo ($entry->debit_amount > 0) ? number_format($entry->debit_amount, 2) : '-'; ?></td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-green-600 text-right font-medium"><?php echo ($entry->credit_amount > 0) ? number_format($entry->credit_amount, 2) : '-'; ?></td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-right font-bold"><?php echo number_format($entry->balance_after, 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="p-4 border-t bg-gray-50">
        <a href="<?php echo url('cr/customer_ledger.php?customer_id=' . $customer->id); ?>" class="text-sm text-primary-600 hover:underline font-medium">
            View Full Statement & Date Range <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>
</div>

</div> <div id="transactionModal" class="fixed z-50 inset-0 overflow-y-auto hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div id="modalOverlay" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>

        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-3xl sm:w-full">
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="sm:flex sm:items-start">
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                        <h3 class="text-2xl leading-6 font-bold text-gray-900 mb-2" id="modalTitle">
                            Loading...
                        </h3>
                        <div class="mt-4" id="modalContent">
                            <div class="flex justify-center items-center h-48">
                                <i class="fas fa-spinner fa-spin text-4xl text-primary-600"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button type="button" id="closeModalButton" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- Chart.js Sales Trend ---
    const salesLabels = <?php echo json_encode(array_map(fn ($d) => date("M 'y", strtotime($d->month . "-01")), $sales_data)); ?>;
    const salesValues = <?php echo json_encode(array_column($sales_data, 'total_invoice_amount')); ?>;
    
    if (document.getElementById('salesTrendChart')) {
        const ctx = document.getElementById('salesTrendChart').getContext('2d');
        const salesTrendChart = new Chart(ctx, {
            type: 'bar', // Changed to bar for a different feel
            data: {
                labels: salesLabels,
                datasets: [{
                    label: 'Total Sales',
                    data: salesValues,
                    backgroundColor: 'rgba(2, 132, 199, 0.6)', // 'bg-primary-600' with opacity
                    borderColor: 'rgba(2, 132, 199, 1)',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // Allows chart to fill container height
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                if (value >= 1000) return value / 1000 + 'k';
                                return value;
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false // Cleaner look
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += new Intl.NumberFormat('en-US', { style: 'currency', currency: 'BDT' }).format(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }

    // --- Modal Logic ---
    const modal = document.getElementById('transactionModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    const closeModalButton = document.getElementById('closeModalButton');
    const modalOverlay = document.getElementById('modalOverlay');

    // Get CSRF token from the meta tag in header.php
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    function showModal() {
        modal.classList.remove('hidden');
    }

    function hideModal() {
        modal.classList.add('hidden');
        modalTitle.innerText = 'Loading...';
        modalContent.innerHTML = '<div class="flex justify-center items-center h-48"><i class="fas fa-spinner fa-spin text-4xl text-primary-600"></i></div>';
    }

    document.querySelectorAll('.open-transaction-modal').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            const refId = this.getAttribute('data-ref-id');
            const refType = this.getAttribute('data-ref-type');
            const refNumber = this.getAttribute('data-ref-number');
            
            modalTitle.innerText = `Details for ${refNumber}`;
            showModal();

            // AJAX call to fetch details
            $.ajax({
                url: '../cr/ajax_handler.php', // Your existing handler
                type: 'POST',
                // We send JSON, as your handler expects it
                contentType: 'application/json',
                data: JSON.stringify({
                    action: 'get_transaction_details',
                    ref_id: refId,
                    ref_type: refType,
                    csrf_token: csrfToken // Send token in the JSON body
                }),
                // We also send the X-CSRF-TOKEN header, as your handler checks both
                headers: {
                    'X-CSRF-TOKEN': csrfToken
                },
                // We expect a JSON response
                dataType: 'json', 
                success: function(response) {
                    // Check for success and the 'html' property
                    if (response.success && response.html) {
                        modalContent.innerHTML = response.html;
                    } else {
                        // Handle JSON error from server
                        const errorMsg = response.error || 'Failed to load details. Invalid response.';
                        modalContent.innerHTML = `<p class="text-red-500 text-center p-4">Error: ${errorMsg}</p>`;
                    }
                },
                error: function(jqXHR) {
                    // Handle AJAX-level errors (403, 401, 500, etc.)
                    let errorMsg = 'Could not load transaction details. Please try again.';
                    if (jqXHR.responseJSON && jqXHR.responseJSON.error) {
                        errorMsg = jqXHR.responseJSON.error;
                    } else if (jqXHR.statusText) {
                        errorMsg = `${jqXHR.status}: ${jqXHR.statusText}`;
                    }
                    modalContent.innerHTML = `<p class="text-red-500 text-center p-4">Error: ${errorMsg}</p>`;
                }
            });
        });
    });

    closeModalButton.addEventListener('click', hideModal);
    modalOverlay.addEventListener('click', hideModal);
});
</script>

<?php
// --- Include Footer ---
require_once '../templates/footer.php';
?>
<?php
/**
 * Smart Supplier Ledger - AI-Powered CRM Analytics
 * Advanced relationship management with predictive insights
 * 
 * @package Ujjal Flour Mills
 * @subpackage Purchase Module
 */

require_once '../core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$currentUser = getCurrentUser();

$supplier_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($supplier_id === 0) {
    redirect('suppliers.php', 'Invalid supplier', 'error');
}

// Database connection
$db = Database::getInstance()->getPdo();

// Get supplier details
$supplier_sql = "SELECT * FROM suppliers WHERE id = ?";
$stmt = $db->prepare($supplier_sql);
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch(PDO::FETCH_OBJ);

if (!$supplier) {
    redirect('suppliers.php', 'Supplier not found', 'error');
}

$pageTitle = "Smart Supplier Ledger - " . $supplier->company_name;

// Date filters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$transaction_type = $_GET['transaction_type'] ?? 'all';

// ===============================================
// AI ANALYTICS - PAYMENT BEHAVIOR ANALYSIS
// ===============================================

// Get payment history for last 12 months
$payment_history_sql = "
SELECT 
    DATE_FORMAT(pmt.payment_date, '%Y-%m') as month,
    COUNT(*) as payment_count,
    SUM(pmt.amount_paid) as total_paid,
    AVG(DATEDIFF(pmt.payment_date, po.po_date)) as avg_days_to_pay,
    MIN(DATEDIFF(pmt.payment_date, po.po_date)) as min_days_to_pay,
    MAX(DATEDIFF(pmt.payment_date, po.po_date)) as max_days_to_pay
FROM purchase_payments_adnan pmt
INNER JOIN purchase_orders_adnan po ON pmt.purchase_order_id = po.id
WHERE pmt.supplier_id = ?
AND pmt.payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
GROUP BY DATE_FORMAT(pmt.payment_date, '%Y-%m')
ORDER BY month DESC
";

$stmt = $db->prepare($payment_history_sql);
$stmt->execute([$supplier_id]);
$payment_history = $stmt->fetchAll(PDO::FETCH_OBJ);

// ===============================================
// SUPPLIER PERFORMANCE METRICS
// ===============================================

$performance_sql = "
SELECT 
    COUNT(DISTINCT po.id) as total_orders,
    COUNT(DISTINCT grn.id) as total_deliveries,
    AVG(po.unit_price_per_kg) as avg_price_per_kg,
    AVG(DATEDIFF(grn.grn_date, po.po_date)) as avg_delivery_days,
    AVG(ABS(grn.quantity_received_kg - grn.expected_quantity) / NULLIF(grn.expected_quantity, 0) * 100) as avg_variance_percent,
    SUM(CASE WHEN grn.quantity_received_kg < grn.expected_quantity THEN 1 ELSE 0 END) as shortage_count,
    SUM(CASE WHEN grn.quantity_received_kg > grn.expected_quantity THEN 1 ELSE 0 END) as excess_count,
    SUM(CASE WHEN po.payment_status = 'paid' THEN 1 ELSE 0 END) as fully_paid_orders,
    AVG(DATEDIFF(pmt.payment_date, po.po_date)) as avg_payment_delay
FROM purchase_orders_adnan po
LEFT JOIN goods_received_adnan grn ON po.id = grn.purchase_order_id AND grn.grn_status != 'cancelled'
LEFT JOIN purchase_payments_adnan pmt ON po.id = pmt.purchase_order_id
WHERE po.supplier_id = ?
AND po.po_status != 'cancelled'
AND po.po_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
";

$stmt = $db->prepare($performance_sql);
$stmt->execute([$supplier_id]);
$performance = $stmt->fetch(PDO::FETCH_OBJ);

// ===============================================
// AGING ANALYSIS
// ===============================================

$aging_sql = "
SELECT 
    SUM(CASE WHEN DATEDIFF(CURDATE(), po.po_date) <= 30 THEN po.balance_payable ELSE 0 END) as current_0_30,
    SUM(CASE WHEN DATEDIFF(CURDATE(), po.po_date) BETWEEN 31 AND 60 THEN po.balance_payable ELSE 0 END) as aging_31_60,
    SUM(CASE WHEN DATEDIFF(CURDATE(), po.po_date) BETWEEN 61 AND 90 THEN po.balance_payable ELSE 0 END) as aging_61_90,
    SUM(CASE WHEN DATEDIFF(CURDATE(), po.po_date) > 90 THEN po.balance_payable ELSE 0 END) as aging_over_90
FROM purchase_orders_adnan po
WHERE po.supplier_id = ?
AND po.po_status != 'cancelled'
AND po.balance_payable > 0
";

$stmt = $db->prepare($aging_sql);
$stmt->execute([$supplier_id]);
$aging = $stmt->fetch(PDO::FETCH_OBJ);

// ===============================================
// CALCULATE SUPPLIER HEALTH SCORE
// ===============================================

$health_score = 100;
$score_breakdown = [];

// Delivery Performance (0-30 points)
$delivery_score = 30;
if (($performance->avg_delivery_days ?? 0) > 10) {
    $delivery_score -= min(15, ($performance->avg_delivery_days - 10) * 1.5);
}
$health_score -= (30 - $delivery_score);
$score_breakdown['delivery'] = $delivery_score;

// Quality/Variance (0-25 points)
$quality_score = 25;
$variance_penalty = min(25, ($performance->avg_variance_percent ?? 0) * 5);
$quality_score -= $variance_penalty;
$health_score -= (25 - $quality_score);
$score_breakdown['quality'] = $quality_score;

// Payment Promptness (0-25 points)
$payment_score = 25;
if (($performance->avg_payment_delay ?? 0) > 7) {
    $payment_score -= min(20, (($performance->avg_payment_delay ?? 0) - 7) * 2);
}
$health_score -= (25 - $payment_score);
$score_breakdown['payment'] = $payment_score;

// Financial Stability (0-20 points)
$financial_score = 20;
if ($supplier->current_balance > 0) {
    $overdue_ratio = $supplier->current_balance / max(1, $supplier->credit_limit);
    if ($overdue_ratio > 0.8) {
        $financial_score -= 15;
    } elseif ($overdue_ratio > 0.5) {
        $financial_score -= 10;
    } elseif ($overdue_ratio > 0.3) {
        $financial_score -= 5;
    }
}
$health_score -= (20 - $financial_score);
$score_breakdown['financial'] = $financial_score;

$health_score = max(0, min(100, $health_score));

// Determine health status
if ($health_score >= 80) {
    $health_status = 'Excellent';
    $health_color = 'green';
} elseif ($health_score >= 60) {
    $health_status = 'Good';
    $health_color = 'blue';
} elseif ($health_score >= 40) {
    $health_status = 'Fair';
    $health_color = 'yellow';
} else {
    $health_status = 'Poor';
    $health_color = 'red';
}

// ===============================================
// AI INSIGHTS & RECOMMENDATIONS
// ===============================================

$insights = [];
$warnings = [];
$recommendations = [];

// Payment pattern analysis
if (!empty($payment_history)) {
    $recent_payments = array_slice($payment_history, 0, 3);
    $avg_recent_payment = array_sum(array_column($recent_payments, 'total_paid')) / count($recent_payments);
    
    if ($avg_recent_payment > 0) {
        $monthly_trend = (end($recent_payments)->total_paid - $recent_payments[0]->total_paid) / $recent_payments[0]->total_paid * 100;
        
        if ($monthly_trend > 20) {
            $insights[] = [
                'icon' => 'fa-chart-line',
                'color' => 'green',
                'title' => 'Increasing Payment Volume',
                'message' => "Payments increased by " . number_format(abs($monthly_trend), 1) . "% over last 3 months. Strong relationship growth.",
                'type' => 'positive'
            ];
        } elseif ($monthly_trend < -20) {
            $warnings[] = [
                'icon' => 'fa-arrow-down',
                'color' => 'orange',
                'title' => 'Declining Payment Activity',
                'message' => "Payment volume decreased by " . number_format(abs($monthly_trend), 1) . "%. Consider engagement review.",
                'severity' => 'medium'
            ];
        }
    }
}

// Delivery variance check
if (($performance->avg_variance_percent ?? 0) > 5) {
    $warnings[] = [
        'icon' => 'fa-balance-scale',
        'color' => 'orange',
        'title' => 'High Delivery Variance',
        'message' => "Average variance of " . number_format($performance->avg_variance_percent, 1) . "% exceeds acceptable threshold. Quality control needed.",
        'severity' => 'medium'
    ];
}

// Outstanding balance check
if ($supplier->current_balance > ($supplier->credit_limit * 0.8)) {
    $warnings[] = [
        'icon' => 'fa-exclamation-triangle',
        'color' => 'red',
        'title' => 'Credit Limit Alert',
        'message' => "Outstanding balance at " . number_format(($supplier->current_balance / $supplier->credit_limit * 100), 0) . "% of credit limit. Payment collection urgently needed.",
        'severity' => 'high'
    ];
}

// Aging analysis warnings
if (($aging->aging_over_90 ?? 0) > 0) {
    $warnings[] = [
        'icon' => 'fa-clock',
        'color' => 'red',
        'title' => 'Aged Receivables',
        'message' => "৳" . number_format($aging->aging_over_90, 0) . " outstanding for over 90 days. Immediate action required.",
        'severity' => 'high'
    ];
}

// Positive insights
if (($performance->avg_delivery_days ?? 0) <= 7) {
    $insights[] = [
        'icon' => 'fa-truck-fast',
        'color' => 'green',
        'title' => 'Excellent Delivery Time',
        'message' => "Average delivery in " . number_format($performance->avg_delivery_days, 1) . " days. Reliable supplier performance.",
        'type' => 'positive'
    ];
}

if (($performance->avg_variance_percent ?? 0) < 2) {
    $insights[] = [
        'icon' => 'fa-check-circle',
        'color' => 'green',
        'title' => 'High Quality Consistency',
        'message' => "Delivery variance under 2%. Excellent quality control.",
        'type' => 'positive'
    ];
}

// AI Recommendations
if ($supplier->current_balance > 0 && $supplier->payment_terms) {
    $recommendations[] = [
        'icon' => 'fa-lightbulb',
        'title' => 'Payment Terms Optimization',
        'message' => "Current terms: {$supplier->payment_terms}. Consider renegotiating for better cash flow management.",
        'action' => 'Review payment terms'
    ];
}

if (($performance->total_orders ?? 0) >= 10 && $supplier->credit_limit > 0) {
    $avg_order_value = ($stats->total_orders_value ?? 0) / $performance->total_orders;
    $recommended_credit = $avg_order_value * 2;
    
    if ($recommended_credit > $supplier->credit_limit * 1.5) {
        $recommendations[] = [
            'icon' => 'fa-chart-line',
            'title' => 'Credit Limit Increase Opportunity',
            'message' => "Based on order history, consider increasing credit limit to ৳" . number_format($recommended_credit, 0) . " to facilitate larger orders.",
            'action' => 'Increase credit limit'
        ];
    }
}

// Price trend analysis
if (!empty($payment_history) && count($payment_history) >= 6) {
    $old_avg = array_sum(array_slice(array_column($payment_history, 'total_paid'), -6, 3)) / 3;
    $new_avg = array_sum(array_slice(array_column($payment_history, 'total_paid'), 0, 3)) / 3;
    
    if ($new_avg > $old_avg * 1.2) {
        $insights[] = [
            'icon' => 'fa-trending-up',
            'color' => 'blue',
            'title' => 'Growing Partnership',
            'message' => "Transaction volume increased " . number_format((($new_avg - $old_avg) / $old_avg * 100), 0) . "% in recent months. Strategic supplier.",
            'type' => 'positive'
        ];
    }
}

// Build comprehensive transaction query
$transactions_sql = "
SELECT * FROM (
    -- Purchase Orders
    SELECT 
        'PO' as transaction_type,
        po.id as transaction_id,
        po.po_number as reference_number,
        po.po_date as transaction_date,
        po.total_order_value as debit,
        0 as credit,
        CONCAT('Purchase Order: ', po.wheat_origin, ' - ', po.quantity_kg, ' KG @ ৳', FORMAT(po.unit_price_per_kg, 2)) as description,
        po.delivery_status as status,
        po.created_at
    FROM purchase_orders_adnan po
    WHERE po.supplier_id = ?
    AND po.po_status != 'cancelled'
    AND po.po_date BETWEEN ? AND ?
    
    UNION ALL
    
    -- Goods Received Notes (GRNs)
    SELECT 
        'GRN' as transaction_type,
        grn.id as transaction_id,
        grn.grn_number as reference_number,
        grn.grn_date as transaction_date,
        grn.total_value as debit,
        0 as credit,
        CONCAT('Goods Received: ', grn.po_number, ' - ', grn.quantity_received_kg, ' KG (Truck: ', COALESCE(grn.truck_number, 'N/A'), ')') as description,
        grn.grn_status as status,
        grn.created_at
    FROM goods_received_adnan grn
    WHERE grn.supplier_id = ?
    AND grn.grn_status != 'cancelled'
    AND grn.grn_date BETWEEN ? AND ?
    
    UNION ALL
    
    -- Payments
    SELECT 
        'PAYMENT' as transaction_type,
        pmt.id as transaction_id,
        pmt.payment_voucher_number as reference_number,
        pmt.payment_date as transaction_date,
        0 as debit,
        pmt.amount_paid as credit,
        CONCAT('Payment: ', pmt.po_number, ' - ', UPPER(pmt.payment_method), ' (', COALESCE(pmt.bank_name, 'N/A'), ')') as description,
        IF(pmt.is_posted = 1, 'posted', 'pending') as status,
        pmt.created_at
    FROM purchase_payments_adnan pmt
    WHERE pmt.supplier_id = ?
    AND pmt.payment_date BETWEEN ? AND ?
) AS all_transactions
ORDER BY transaction_date DESC, created_at DESC
";

$params = [
    $supplier_id, $date_from, $date_to,
    $supplier_id, $date_from, $date_to,
    $supplier_id, $date_from, $date_to
];

$stmt = $db->prepare($transactions_sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_OBJ);

// Filter by transaction type if specified
if ($transaction_type !== 'all') {
    $transactions = array_filter($transactions, function($t) use ($transaction_type) {
        return strtolower($t->transaction_type) === strtolower($transaction_type);
    });
}

// Calculate opening balance
$opening_balance_sql = "
SELECT 
    COALESCE(SUM(po.total_order_value), 0) as total_orders,
    COALESCE(SUM(pmt.amount_paid), 0) as total_paid
FROM suppliers s
LEFT JOIN purchase_orders_adnan po ON s.id = po.supplier_id 
    AND po.po_status != 'cancelled' 
    AND po.po_date < ?
LEFT JOIN purchase_payments_adnan pmt ON s.id = pmt.supplier_id 
    AND pmt.payment_date < ?
WHERE s.id = ?
";

$stmt = $db->prepare($opening_balance_sql);
$stmt->execute([$date_from, $date_from, $supplier_id]);
$opening = $stmt->fetch(PDO::FETCH_OBJ);
$opening_balance = ($opening->total_orders ?? 0) - ($opening->total_paid ?? 0);

// Calculate totals
$period_debit = array_sum(array_column($transactions, 'debit'));
$period_credit = array_sum(array_column($transactions, 'credit'));
$closing_balance = $opening_balance + $period_debit - $period_credit;

// Get summary statistics
$stats_sql = "
SELECT 
    COUNT(DISTINCT po.id) as total_pos,
    COUNT(DISTINCT grn.id) as total_grns,
    COUNT(DISTINCT pmt.id) as total_payments,
    COALESCE(SUM(po.total_order_value), 0) as total_orders_value,
    COALESCE(SUM(pmt.amount_paid), 0) as total_paid_value,
    COALESCE(SUM(po.balance_payable), 0) as current_balance
FROM suppliers s
LEFT JOIN purchase_orders_adnan po ON s.id = po.supplier_id AND po.po_status != 'cancelled'
LEFT JOIN goods_received_adnan grn ON s.id = grn.supplier_id AND grn.grn_status != 'cancelled'
LEFT JOIN purchase_payments_adnan pmt ON s.id = pmt.supplier_id
WHERE s.id = ?
";

$stmt = $db->prepare($stats_sql);
$stmt->execute([$supplier_id]);
$stats = $stmt->fetch(PDO::FETCH_OBJ);

require_once '../templates/header.php';
?>

<style>
.health-gauge {
    background: conic-gradient(
        #10B981 0deg <?php echo $health_score * 3.6; ?>deg,
        #E5E7EB <?php echo $health_score * 3.6; ?>deg 360deg
    );
}
@media print {
    .print\:hidden { display: none !important; }
    .no-print { display: none !important; }
}
</style>

<div class="w-full px-4 py-6">

    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6 no-print">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-brain text-purple-600"></i> Smart Supplier Analytics
            </h1>
            <p class="text-gray-600 mt-1">
                AI-Powered Insights for <strong><?php echo htmlspecialchars($supplier->company_name); ?></strong> 
                (<?php echo htmlspecialchars($supplier->supplier_code); ?>)
            </p>
        </div>
        <div class="flex items-center gap-3">
            <button onclick="window.print()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-print mr-2"></i>Print
            </button>
            <a href="supplier_edit.php?id=<?php echo $supplier_id; ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-edit mr-2"></i>Edit
            </a>
            <a href="suppliers.php" class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition">
                <i class="fas fa-arrow-left mr-2"></i>Back
            </a>
        </div>
    </div>

    <!-- AI Warnings & Alerts -->
    <?php if (!empty($warnings)): ?>
    <div class="mb-6 space-y-3 no-print">
        <?php foreach ($warnings as $warning): ?>
        <div class="bg-<?php echo $warning['color']; ?>-50 border-l-4 border-<?php echo $warning['color']; ?>-500 rounded-lg p-4">
            <div class="flex items-start gap-3">
                <div class="bg-<?php echo $warning['color']; ?>-100 rounded-full p-3">
                    <i class="fas <?php echo $warning['icon']; ?> text-<?php echo $warning['color']; ?>-600 text-xl"></i>
                </div>
                <div class="flex-1">
                    <h3 class="font-bold text-<?php echo $warning['color']; ?>-900 mb-1"><?php echo $warning['title']; ?></h3>
                    <p class="text-<?php echo $warning['color']; ?>-700 text-sm"><?php echo $warning['message']; ?></p>
                </div>
                <span class="px-3 py-1 bg-<?php echo $warning['color']; ?>-200 text-<?php echo $warning['color']; ?>-800 rounded-full text-xs font-semibold uppercase">
                    <?php echo $warning['severity']; ?> Priority
                </span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Supplier Health Dashboard -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        
        <!-- Health Score Gauge -->
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">
                <i class="fas fa-heartbeat text-red-600"></i> Supplier Health Score
            </h3>
            <div class="flex items-center justify-center mb-4">
                <div class="relative w-40 h-40">
                    <div class="health-gauge w-full h-full rounded-full"></div>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <div class="bg-white rounded-full w-28 h-28 flex items-center justify-center">
                            <div class="text-center">
                                <div class="text-4xl font-bold text-<?php echo $health_color; ?>-600">
                                    <?php echo number_format($health_score, 0); ?>
                                </div>
                                <div class="text-xs text-gray-600 mt-1">out of 100</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-center">
                <span class="px-4 py-2 bg-<?php echo $health_color; ?>-100 text-<?php echo $health_color; ?>-800 rounded-full text-sm font-semibold">
                    <?php echo $health_status; ?> Supplier
                </span>
            </div>
            
            <!-- Score Breakdown -->
            <div class="mt-6 space-y-2">
                <?php foreach ($score_breakdown as $metric => $score): 
                    $metric_labels = [
                        'delivery' => 'Delivery Performance',
                        'quality' => 'Quality & Variance',
                        'payment' => 'Payment Terms',
                        'financial' => 'Financial Stability'
                    ];
                    $max_scores = ['delivery' => 30, 'quality' => 25, 'payment' => 25, 'financial' => 20];
                ?>
                <div>
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-gray-700"><?php echo $metric_labels[$metric]; ?></span>
                        <span class="font-semibold"><?php echo number_format($score, 0); ?>/<?php echo $max_scores[$metric]; ?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-<?php echo $health_color; ?>-600 h-2 rounded-full" style="width: <?php echo ($score / $max_scores[$metric] * 100); ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- AI Insights -->
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">
                <i class="fas fa-lightbulb text-yellow-500"></i> AI Insights
            </h3>
            <div class="space-y-3">
                <?php if (empty($insights)): ?>
                <p class="text-gray-500 text-center py-4 text-sm">
                    <i class="fas fa-info-circle text-gray-300 text-2xl mb-2"></i><br>
                    No insights available yet
                </p>
                <?php else: ?>
                    <?php foreach ($insights as $insight): ?>
                    <div class="flex items-start gap-3 p-3 bg-<?php echo $insight['color']; ?>-50 rounded-lg border border-<?php echo $insight['color']; ?>-200">
                        <i class="fas <?php echo $insight['icon']; ?> text-<?php echo $insight['color']; ?>-600 mt-1"></i>
                        <div>
                            <h4 class="font-semibold text-gray-900 text-sm"><?php echo $insight['title']; ?></h4>
                            <p class="text-xs text-gray-600 mt-1"><?php echo $insight['message']; ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- AI Recommendations -->
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">
                <i class="fas fa-robot text-purple-600"></i> AI Recommendations
            </h3>
            <div class="space-y-3">
                <?php if (empty($recommendations)): ?>
                <p class="text-gray-500 text-center py-4 text-sm">
                    <i class="fas fa-check-circle text-gray-300 text-2xl mb-2"></i><br>
                    All operations optimal
                </p>
                <?php else: ?>
                    <?php foreach ($recommendations as $rec): ?>
                    <div class="p-3 bg-blue-50 rounded-lg border border-blue-200">
                        <div class="flex items-start gap-3">
                            <i class="fas <?php echo $rec['icon']; ?> text-blue-600 mt-1"></i>
                            <div class="flex-1">
                                <h4 class="font-semibold text-gray-900 text-sm"><?php echo $rec['title']; ?></h4>
                                <p class="text-xs text-gray-600 mt-1"><?php echo $rec['message']; ?></p>
                                <button class="mt-2 text-xs text-blue-600 hover:text-blue-800 font-semibold">
                                    → <?php echo $rec['action']; ?>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Performance Metrics -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Avg Delivery Time</p>
                    <p class="text-3xl font-bold text-blue-600 mt-1">
                        <?php echo number_format($performance->avg_delivery_days ?? 0, 0); ?>
                    </p>
                    <p class="text-xs text-gray-500">days</p>
                </div>
                <div class="bg-blue-100 rounded-full p-3">
                    <i class="fas fa-truck text-blue-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Quality Variance</p>
                    <p class="text-3xl font-bold text-green-600 mt-1">
                        <?php echo number_format($performance->avg_variance_percent ?? 0, 1); ?>%
                    </p>
                    <p class="text-xs text-gray-500">avg deviation</p>
                </div>
                <div class="bg-green-100 rounded-full p-3">
                    <i class="fas fa-balance-scale text-green-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Avg Payment Delay</p>
                    <p class="text-3xl font-bold text-purple-600 mt-1">
                        <?php echo number_format($performance->avg_payment_delay ?? 0, 0); ?>
                    </p>
                    <p class="text-xs text-gray-500">days from order</p>
                </div>
                <div class="bg-purple-100 rounded-full p-3">
                    <i class="fas fa-clock text-purple-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Orders Completed</p>
                    <p class="text-3xl font-bold text-orange-600 mt-1">
                        <?php echo number_format((($performance->fully_paid_orders ?? 0) / max(1, ($performance->total_orders ?? 1)) * 100), 0); ?>%
                    </p>
                    <p class="text-xs text-gray-500">fully paid</p>
                </div>
                <div class="bg-orange-100 rounded-full p-3">
                    <i class="fas fa-check-circle text-orange-600 text-2xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Aging Analysis -->
    <?php if (($aging->current_0_30 ?? 0) + ($aging->aging_31_60 ?? 0) + ($aging->aging_61_90 ?? 0) + ($aging->aging_over_90 ?? 0) > 0): ?>
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="bg-indigo-600 text-white px-6 py-4 rounded-t-lg">
            <h3 class="font-semibold flex items-center gap-2">
                <i class="fas fa-hourglass-half"></i> Receivables Aging Analysis
            </h3>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="text-center p-4 bg-green-50 rounded-lg">
                    <p class="text-sm text-gray-600 mb-2">Current (0-30 days)</p>
                    <p class="text-2xl font-bold text-green-600">৳<?php echo number_format($aging->current_0_30 ?? 0, 0); ?></p>
                </div>
                <div class="text-center p-4 bg-yellow-50 rounded-lg">
                    <p class="text-sm text-gray-600 mb-2">31-60 days</p>
                    <p class="text-2xl font-bold text-yellow-600">৳<?php echo number_format($aging->aging_31_60 ?? 0, 0); ?></p>
                </div>
                <div class="text-center p-4 bg-orange-50 rounded-lg">
                    <p class="text-sm text-gray-600 mb-2">61-90 days</p>
                    <p class="text-2xl font-bold text-orange-600">৳<?php echo number_format($aging->aging_61_90 ?? 0, 0); ?></p>
                </div>
                <div class="text-center p-4 bg-red-50 rounded-lg">
                    <p class="text-sm text-gray-600 mb-2">Over 90 days</p>
                    <p class="text-2xl font-bold text-red-600">৳<?php echo number_format($aging->aging_over_90 ?? 0, 0); ?></p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6 print:grid-cols-5">
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm font-medium text-gray-600">Purchase Orders</p>
            <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($stats->total_pos); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm font-medium text-gray-600">Deliveries (GRNs)</p>
            <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($stats->total_grns); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm font-medium text-gray-600">Total Payments</p>
            <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($stats->total_payments); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm font-medium text-gray-600">Total Value</p>
            <p class="text-2xl font-bold text-blue-600 mt-2">৳<?php echo number_format($stats->total_orders_value / 1000000, 1); ?>M</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm font-medium text-gray-600">Outstanding</p>
            <p class="text-2xl font-bold text-red-600 mt-2">৳<?php echo number_format($stats->current_balance / 1000000, 1); ?>M</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-6 mb-6 no-print">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <input type="hidden" name="id" value="<?php echo $supplier_id; ?>">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                <input type="date" name="date_from" value="<?php echo $date_from; ?>" class="w-full px-3 py-2 border rounded-lg">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                <input type="date" name="date_to" value="<?php echo $date_to; ?>" class="w-full px-3 py-2 border rounded-lg">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Transaction Type</label>
                <select name="transaction_type" class="w-full px-3 py-2 border rounded-lg">
                    <option value="all" <?php echo $transaction_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                    <option value="po" <?php echo $transaction_type === 'po' ? 'selected' : ''; ?>>Purchase Orders</option>
                    <option value="grn" <?php echo $transaction_type === 'grn' ? 'selected' : ''; ?>>Goods Received</option>
                    <option value="payment" <?php echo $transaction_type === 'payment' ? 'selected' : ''; ?>>Payments</option>
                </select>
            </div>
            
            <div class="flex items-end">
                <button type="submit" class="w-full bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-filter mr-2"></i>Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- Ledger Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="bg-gray-700 text-white px-6 py-4">
            <h3 class="font-semibold">Transaction Ledger</h3>
        </div>

        <?php if (!empty($transactions)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Debit (৳)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Credit (৳)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance (৳)</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider print:hidden">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <!-- Opening Balance -->
                    <tr class="bg-blue-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo date('d M Y', strtotime($date_from)); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap" colspan="3">
                            <span class="text-sm font-semibold text-gray-900">Opening Balance</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500">-</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500">-</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold <?php echo $opening_balance > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                            <?php echo number_format($opening_balance, 2); ?>
                        </td>
                        <td class="print:hidden"></td>
                    </tr>

                    <?php 
                    $running_balance = $opening_balance;
                    foreach ($transactions as $txn): 
                        $running_balance += $txn->debit - $txn->credit;
                        
                        $type_colors = [
                            'PO' => 'bg-blue-100 text-blue-800',
                            'GRN' => 'bg-green-100 text-green-800',
                            'PAYMENT' => 'bg-purple-100 text-purple-800'
                        ];
                        $type_color = $type_colors[$txn->transaction_type] ?? 'bg-gray-100 text-gray-800';
                        
                        $status_colors = [
                            'pending' => 'bg-gray-100 text-gray-800',
                            'partial' => 'bg-yellow-100 text-yellow-800',
                            'completed' => 'bg-green-100 text-green-800',
                            'posted' => 'bg-green-100 text-green-800',
                            'verified' => 'bg-blue-100 text-blue-800'
                        ];
                        $status_color = $status_colors[$txn->status] ?? 'bg-gray-100 text-gray-800';
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo date('d M Y', strtotime($txn->transaction_date)); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $type_color; ?>">
                                <?php echo $txn->transaction_type; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($txn->reference_number); ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700">
                            <?php echo htmlspecialchars($txn->description); ?>
                            <div class="mt-1">
                                <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $status_color; ?>">
                                    <?php echo ucfirst($txn->status); ?>
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold text-red-600">
                            <?php echo $txn->debit > 0 ? number_format($txn->debit, 2) : '-'; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold text-green-600">
                            <?php echo $txn->credit > 0 ? number_format($txn->credit, 2) : '-'; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold <?php echo $running_balance > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                            <?php echo number_format($running_balance, 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm print:hidden">
                            <?php if ($txn->transaction_type === 'PO'): ?>
                                <a href="purchase_adnan_view_po.php?id=<?php echo $txn->transaction_id; ?>" class="text-primary-600 hover:text-primary-800" title="View PO">
                                    <i class="fas fa-eye"></i>
                                </a>
                            <?php elseif ($txn->transaction_type === 'GRN'): ?>
                                <a href="purchase_adnan_grn_receipt.php?id=<?php echo $txn->transaction_id; ?>" class="text-primary-600 hover:text-primary-800" title="View GRN">
                                    <i class="fas fa-receipt"></i>
                                </a>
                            <?php elseif ($txn->transaction_type === 'PAYMENT'): ?>
                                <a href="purchase_adnan_payment_receipt.php?id=<?php echo $txn->transaction_id; ?>" class="text-primary-600 hover:text-primary-800" title="View Payment">
                                    <i class="fas fa-receipt"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- Period Totals -->
                    <tr class="bg-gray-100 font-semibold">
                        <td colspan="4" class="px-6 py-4 text-sm text-gray-900">
                            Period Totals (<?php echo count($transactions); ?> transactions)
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-red-600">
                            <?php echo number_format($period_debit, 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-green-600">
                            <?php echo number_format($period_credit, 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">-</td>
                        <td class="print:hidden"></td>
                    </tr>

                    <!-- Closing Balance -->
                    <tr class="bg-blue-100 font-bold">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo date('d M Y', strtotime($date_to)); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap" colspan="3">
                            <span class="text-sm text-gray-900">Closing Balance</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500">-</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500">-</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm <?php echo $closing_balance > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                            <?php echo number_format($closing_balance, 2); ?>
                        </td>
                        <td class="print:hidden"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php else: ?>
        <div class="text-center py-12">
            <i class="fas fa-file-invoice text-gray-300 text-6xl mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-900 mb-2">No transactions found</h3>
            <p class="text-gray-600 mb-6">No transactions for this supplier in the selected date range.</p>
            <a href="supplier_ledger.php?id=<?php echo $supplier_id; ?>" class="inline-block text-primary-600 hover:text-primary-700 font-medium">
                <i class="fas fa-redo mr-2"></i>Reset Filters
            </a>
        </div>
        <?php endif; ?>

    </div>

</div>

<!-- Chart.js for future enhancements -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php require_once '../templates/footer.php'; ?>
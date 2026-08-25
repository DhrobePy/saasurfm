<?php
// This widget is loaded by admin/index.php
// All variables ($db, $widget_title, $date_range, $widget_icon, $widget_color) are inherited
// $date_range is not used here, as receivables are a snapshot, not date-bound.

$top_debtors = [];
$total_receivables = 0;

try {
    // First, get the SUM of all receivables for the header
    // Use current_balance and filter for Credit customers
    $total_result = $db->query(
        "SELECT SUM(current_balance) as total_due 
         FROM customers 
         WHERE current_balance > 0.01 AND customer_type = 'Credit'"
    )->first();
    
    $total_receivables = $total_result ? $total_result->total_due : 0;
    
    // Now, get the top 10 credit customers with the highest due amounts
    if ($total_receivables > 0) {
        $top_debtors = $db->query(
            "SELECT id, name, business_name, phone_number, current_balance, credit_limit, updated_at
             FROM customers
             WHERE current_balance > 0.01 AND customer_type = 'Credit'
             ORDER BY current_balance DESC
             LIMIT 10"
        )->results();
    }

} catch (Exception $e) {
    // In case of a database error
    error_log("Error in total_receivables widget: " . $e->getMessage());
    $top_debtors = [];
    $total_receivables = 0;
}

?>

<!-- Table Widget HTML (Fajracct style) -->
<div class="bg-white rounded-lg shadow-md overflow-hidden h-full flex flex-col">
    <!-- Header -->
    <div class="p-4 flex justify-between items-center border-b border-gray-200">
        <div class="flex items-center">
            <div class="p-2 rounded-full text-<?php echo $widget_color; ?>-600 bg-<?php echo $widget_color; ?>-100 mr-3">
                <i class="fas <?php echo $widget_icon; ?>"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($widget_title); ?></h3>
        </div>
        <!-- Total Amount Badge -->
        <span class="bg-<?php echo $widget_color; ?>-600 text-white text-xs font-bold px-3 py-1 rounded-full">
            ৳<?php echo number_format($total_receivables, 0); ?>
        </span>
    </div>
    
    <!-- List of Customers -->
    <div class="flex-grow p-4 space-y-3 overflow-y-auto" style="max-height: 400px;">
        <?php if (empty($top_debtors)): ?>
            <!-- Empty State -->
            <div class="flex flex-col items-center justify-center h-full text-center text-gray-500 py-8">
                <i class="fas fa-glass-cheers text-4xl text-green-500"></i>
                <p class="mt-3 font-medium text-lg">All Settled Up!</p>
                <p class="text-sm">You have no outstanding credit receivables.</p>
            </div>
        <?php else: ?>
            <!-- Customer Items -->
            <?php foreach ($top_debtors as $customer): ?>
                <div class="flex items-center p-3 bg-gray-50 rounded-lg border border-gray-200 hover:border-red-300 transition shadow-sm">
                    <div class="flex-1">
                        <!-- Top Row: Name and Due Amount -->
                        <div class="flex justify-between items-center">
                            <span class="text-sm font-semibold text-gray-800">
                                <?php echo htmlspecialchars($customer->name); ?>
                                <?php if (!empty($customer->business_name)): ?>
                                    <span class="text-xs text-gray-500">(<?php echo htmlspecialchars($customer->business_name); ?>)</span>
                                <?php endif; ?>
                            </span>
                            <span class="text-sm font-bold text-red-600">
                                ৳<?php echo number_format($customer->current_balance, 2); ?>
                            </span>
                        </div>
                        <!-- Bottom Row: Details -->
                        <div class="flex justify-between items-center text-xs text-gray-500 mt-2">
                            <span>
                                <i class="fas fa-phone-alt mr-1"></i> <?php echo htmlspecialchars($customer->phone_number); ?>
                            </span>
                            <span class="text-gray-600">
                                Limit: ৳<?php echo number_format($customer->credit_limit, 0); ?>
                            </span>
                            <span class="italic">
                                Last Activity: <?php echo date('M j, Y', strtotime($customer->updated_at)); ?>
                            </span>
                        </div>
                    </div>
                    <!-- Eye Icon Link -->
                    <a href="../customers/view.php?id=<?php echo $customer->id; ?>" 
                       class="ml-3 p-2 text-blue-600 hover:bg-blue-100 rounded-full transition"
                       title="View Customer Profile">
                        <i class="fas fa-eye"></i>
                    </a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Footer Link -->
    <?php if ($total_receivables > 0): ?>
        <div class="bg-gray-50 px-4 py-3 text-sm text-center border-t border-gray-200">
            <a href="../customers/index.php" class="font-medium text-blue-600 hover:text-blue-800 transition">
                View All Customers &rarr;
            </a>
        </div>
    <?php endif; ?>
</div>


<?php
/**
 * Credit Orders Status Page - SUPER INTELLIGENT VERSION
 * 
 * Advanced Features:
 * - Smart filters with saved views
 * - Real-time statistics dashboard
 * - Bulk actions (multi-select)
 * - Intelligent search with autocomplete
 * - Auto-refresh capability
 * - Column customization
 * - Keyboard shortcuts
 * - Performance metrics
 * - Smart recommendations
 * - Inline quick actions
 * - Order timeline visualization
 * - Export templates
 * - Activity feed
 * - And much more...
 * 
 * @version 2.0.0 - Super Intelligent Edition
 * @date 2025-11-01
 */

require_once '../core/init.php';

// Allow multiple roles to access
$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 
                  'accounts-demra', 'production manager-srg', 'production manager-demra',
                  'sales-srg', 'sales-demra', 'sales-other', 'dispatch-srg', 'dispatch-demra'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Order Status & Management';

// Check if user has admin privileges
$is_admin = in_array($user_role, ['Superadmin', 'admin']);
$is_accounts = in_array($user_role, ['Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra']);
$is_production = in_array($user_role, ['production manager-srg', 'production manager-demra']);
$is_sales = in_array($user_role, ['sales-srg', 'sales-demra', 'sales-other']);
$is_dispatch = in_array($user_role, ['dispatch-srg', 'dispatch-demra']);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            
            case 'update_priority':
                if (!$is_admin) {
                    throw new Exception('Unauthorized action');
                }
                
                $order_ids = json_decode($_POST['order_ids'], true);
                if (!is_array($order_ids)) {
                    throw new Exception('Invalid order list');
                }
                
                // Use stored procedure for efficient reordering
                $stmt = $db->getPdo()->prepare("CALL sp_reorder_credit_orders(?, ?)");
                $stmt->execute([
                    json_encode($order_ids),
                    $user_id
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Order priority updated successfully']);
                break;
                
            case 'update_status':
                if (!$is_admin) {
                    throw new Exception('Unauthorized action');
                }
                
                $order_id = (int)$_POST['order_id'];
                $new_status = $_POST['status'];
                
                $allowed_statuses = ['draft','pending_approval','approved','in_production',
                                     'produced','ready_to_ship','shipped','delivered','cancelled','hold'];
                
                if (!in_array($new_status, $allowed_statuses)) {
                    throw new Exception('Invalid status');
                }
                
                $db->update('credit_orders', 
                    ['status' => $new_status],
                    ['id' => $order_id]
                );
                
                echo json_encode(['success' => true, 'message' => 'Order status updated', 'new_status' => $new_status]);
                break;
                
            case 'bulk_update_status':
                if (!$is_admin) {
                    throw new Exception('Unauthorized action');
                }
                
                $order_ids = json_decode($_POST['order_ids'], true);
                $new_status = $_POST['status'];
                
                if (!is_array($order_ids) || empty($order_ids)) {
                    throw new Exception('No orders selected');
                }
                
                $db->getPdo()->beginTransaction();
                
                foreach ($order_ids as $order_id) {
                    $db->update('credit_orders', 
                        ['status' => $new_status],
                        ['id' => (int)$order_id]
                    );
                }
                
                $db->getPdo()->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => count($order_ids) . ' orders updated',
                    'count' => count($order_ids)
                ]);
                break;
                
            case 'collect_payment':
                if (!$is_accounts) {
                    throw new Exception('Unauthorized action');
                }
                
                $order_id = (int)$_POST['order_id'];
                $amount = (float)$_POST['amount'];
                $payment_method = $_POST['payment_method'];
                
                // Validate amount
                $order = $db->query(
                    "SELECT total_amount, amount_paid FROM credit_orders WHERE id = ?",
                    [$order_id]
                )->first();
                
                if (!$order) {
                    throw new Exception('Order not found');
                }
                
                $outstanding = $order->total_amount - $order->amount_paid;
                if ($amount > $outstanding) {
                    throw new Exception('Payment amount exceeds outstanding balance');
                }
                
                $db->getPdo()->beginTransaction();
                
                // Update order amount_paid
                $db->query(
                    "UPDATE credit_orders 
                     SET amount_paid = amount_paid + ?,
                         balance_due = total_amount - (amount_paid + ?)
                     WHERE id = ?",
                    [$amount, $amount, $order_id]
                );
                
                $db->getPdo()->commit();
                echo json_encode([
                    'success' => true, 
                    'message' => 'Payment collected successfully',
                    'new_paid' => $order->amount_paid + $amount,
                    'new_balance' => $outstanding - $amount
                ]);
                break;
                
            case 'save_filter_preset':
                $preset_name = $_POST['preset_name'];
                $filters = $_POST['filters'];
                
                // Save to user preferences or separate table
                // For now, we'll use session
                if (!isset($_SESSION['filter_presets'])) {
                    $_SESSION['filter_presets'] = [];
                }
                
                $_SESSION['filter_presets'][$preset_name] = json_decode($filters, true);
                
                echo json_encode(['success' => true, 'message' => 'Filter preset saved']);
                break;
                
            case 'get_order_timeline':
                $order_id = (int)$_GET['order_id'];
                
                $timeline = $db->query(
                    "SELECT 
                        action_type,
                        field_name,
                        old_value,
                        new_value,
                        notes,
                        created_at,
                        u.display_name as user_name
                    FROM credit_order_audit coa
                    LEFT JOIN users u ON coa.user_id = u.id
                    WHERE coa.order_id = ?
                    ORDER BY coa.created_at ASC",
                    [$order_id]
                )->results();
                
                echo json_encode(['success' => true, 'timeline' => $timeline]);
                break;
                
            default:
                throw new Exception('Invalid action');
        }
        
    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle Export requests
if (isset($_GET['export'])) {
    $export_format = $_GET['export'];
    $date_from = $_GET['date_from'] ?? date('Y-m-d');
    $date_to = $_GET['date_to'] ?? date('Y-m-d');
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    
    // Build query
    $where_conditions = ["co.order_date BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];
    
    if (!empty($search)) {
        $where_conditions[] = "(co.order_number LIKE ? OR c.name LIKE ? OR c.phone_number LIKE ?)";
        $search_term = "%{$search}%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    if (!empty($status_filter)) {
        $where_conditions[] = "co.status = ?";
        $params[] = $status_filter;
    }
    
    $where_sql = implode(' AND ', $where_conditions);
    
    $orders = $db->query(
        "SELECT 
            co.id,
            co.order_number,
            co.order_date,
            co.required_date,
            c.name as customer_name,
            c.phone_number,
            co.total_amount,
            co.amount_paid,
            (co.total_amount - co.amount_paid) as balance_due,
            co.status,
            co.priority,
            co.sort_order,
            b.name as branch_name
        FROM credit_orders co
        LEFT JOIN customers c ON co.customer_id = c.id
        LEFT JOIN branches b ON co.assigned_branch_id = b.id
        WHERE {$where_sql}
        ORDER BY co.sort_order ASC, co.order_date DESC",
        $params
    )->results();
    
    if ($export_format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="orders_' . date('Y-m-d_His') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        fputcsv($output, ['Order #', 'Date', 'Required Date', 'Customer', 'Phone', 'Total Amount', 
                          'Amount Paid', 'Balance Due', 'Status', 'Priority', 'Branch', 'Sort Order']);
        
        foreach ($orders as $order) {
            fputcsv($output, [
                $order->order_number,
                $order->order_date,
                $order->required_date,
                $order->customer_name,
                $order->phone_number,
                number_format($order->total_amount, 2),
                number_format($order->amount_paid, 2),
                number_format($order->balance_due, 2),
                ucwords(str_replace('_', ' ', $order->status)),
                ucfirst($order->priority),
                $order->branch_name,
                $order->sort_order
            ]);
        }
        
        fclose($output);
        exit;
        
    } elseif ($export_format === 'pdf') {
        header('Content-Type: text/html');
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Orders Report</title>
            <style>
                body { font-family: Arial, sans-serif; font-size: 11px; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
                th { background-color: #4CAF50; color: white; font-weight: bold; }
                h1 { text-align: center; color: #333; }
                .header-info { text-align: center; margin-bottom: 20px; }
                @media print {
                    button { display: none; }
                    @page { margin: 0.5cm; }
                }
            </style>
        </head>
        <body>
            <h1>Orders Report</h1>
            <div class="header-info">
                <p><strong>Date Range:</strong> <?php echo date('M j, Y', strtotime($date_from)); ?> to <?php echo date('M j, Y', strtotime($date_to)); ?></p>
                <p><strong>Generated:</strong> <?php echo date('M j, Y g:i A'); ?> by <?php echo htmlspecialchars($currentUser['display_name']); ?></p>
            </div>
            <button onclick="window.print()" style="padding: 10px 20px; background: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer; margin-bottom: 10px;">Print to PDF</button>
            
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Order #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Priority</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $idx => $order): ?>
                    <tr>
                        <td><?php echo $idx + 1; ?></td>
                        <td><?php echo htmlspecialchars($order->order_number); ?></td>
                        <td><?php echo date('M j, Y', strtotime($order->order_date)); ?></td>
                        <td><?php echo htmlspecialchars($order->customer_name); ?></td>
                        <td>৳<?php echo number_format($order->total_amount, 2); ?></td>
                        <td style="color: green;">৳<?php echo number_format($order->amount_paid, 2); ?></td>
                        <td style="color: red;">৳<?php echo number_format($order->balance_due, 2); ?></td>
                        <td><?php echo ucwords(str_replace('_', ' ', $order->status)); ?></td>
                        <td><?php echo ucfirst($order->priority); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <p style="margin-top: 30px; text-align: center; color: #666; font-size: 10px;">
                Generated by SaaSurFM Order Management System
            </p>
        </body>
        </html>
        <?php
        exit;
    }
}

// Get filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$branch_filter = $_GET['branch'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
$offset = ($page - 1) * $per_page;

// Auto-refresh setting
$auto_refresh = isset($_GET['auto_refresh']) ? (int)$_GET['auto_refresh'] : 0;

// Build WHERE clause
$where_conditions = ["co.order_date BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if (!empty($search)) {
    $where_conditions[] = "(co.order_number LIKE ? OR c.name LIKE ? OR c.phone_number LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($status_filter)) {
    $where_conditions[] = "co.status = ?";
    $params[] = $status_filter;
}

if (!empty($priority_filter)) {
    $where_conditions[] = "co.priority = ?";
    $params[] = $priority_filter;
}

if (!empty($branch_filter)) {
    $where_conditions[] = "co.assigned_branch_id = ?";
    $params[] = $branch_filter;
}

$where_sql = implode(' AND ', $where_conditions);

// Get total count for pagination
$total_count = $db->query(
    "SELECT COUNT(*) as total
    FROM credit_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    WHERE {$where_sql}",
    $params
)->first()->total ?? 0;

$total_pages = ceil($total_count / $per_page);

// Fetch orders with customer and branch details
$orders = $db->query(
    "SELECT 
        co.*,
        c.name as customer_name,
        c.phone_number as customer_phone,
        c.current_balance as customer_balance,
        b.name as branch_name,
        u.display_name as created_by_name,
        (SELECT COUNT(*) FROM credit_order_items WHERE order_id = co.id) as item_count,
        DATEDIFF(co.required_date, CURDATE()) as days_until_due
    FROM credit_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN branches b ON co.assigned_branch_id = b.id
    LEFT JOIN users u ON co.created_by_user_id = u.id
    WHERE {$where_sql}
    ORDER BY 
        CASE 
            WHEN co.sort_order IS NULL OR co.sort_order = 0 THEN 99999
            ELSE co.sort_order 
        END ASC,
        FIELD(co.priority, 'urgent', 'high', 'normal', 'low'),
        co.order_date DESC,
        co.created_at DESC
    LIMIT {$per_page} OFFSET {$offset}",
    $params
)->results();

// Get order statistics
$stats_query = "SELECT 
    COUNT(*) as total_orders,
    SUM(co.total_amount) as total_value,
    SUM(co.amount_paid) as total_paid,
    SUM(co.total_amount - co.amount_paid) as total_outstanding,
    SUM(CASE WHEN co.status = 'pending_approval' THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN co.status = 'in_production' THEN 1 ELSE 0 END) as production_count,
    SUM(CASE WHEN co.status = 'ready_to_ship' THEN 1 ELSE 0 END) as ready_count,
    SUM(CASE WHEN co.status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
    SUM(CASE WHEN co.priority = 'urgent' THEN 1 ELSE 0 END) as urgent_count,
    SUM(CASE WHEN DATEDIFF(co.required_date, CURDATE()) < 0 AND co.status NOT IN ('delivered', 'cancelled') THEN 1 ELSE 0 END) as overdue_count
FROM credit_orders co";

// Add JOIN if search is active (because where_sql includes c.name)
if (!empty($search)) {
    $stats_query .= " LEFT JOIN customers c ON co.customer_id = c.id";
}

$stats_query .= " WHERE {$where_sql}";

$stats = $db->query($stats_query, $params)->first();

// Safety check - if stats query failed, create empty stats object
if (!$stats) {
    $stats = (object)[
        'total_orders' => 0,
        'total_value' => 0,
        'total_paid' => 0,
        'total_outstanding' => 0,
        'pending_count' => 0,
        'production_count' => 0,
        'ready_count' => 0,
        'delivered_count' => 0,
        'urgent_count' => 0,
        'overdue_count' => 0
    ];
}

// Get branches for filter dropdown
$branches = $db->query("SELECT id, name FROM branches ORDER BY name")->results();

require_once '../templates/header.php';
?>

<!-- Add SortableJS for drag and drop -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<style>
/* Status color badges */
.status-draft { background-color: #6B7280; color: white; }
.status-pending_approval { background-color: #F59E0B; color: white; }
.status-approved { background-color: #10B981; color: white; }
.status-escalated { background-color: #EF4444; color: white; }
.status-rejected { background-color: #DC2626; color: white; }
.status-in_production { background-color: #3B82F6; color: white; }
.status-produced { background-color: #8B5CF6; color: white; }
.status-ready_to_ship { background-color: #EC4899; color: white; }
.status-shipped { background-color: #06B6D4; color: white; }
.status-delivered { background-color: #059669; color: white; }
.status-cancelled { background-color: #991B1B; color: white; }
.status-hold { background-color: #F97316; color: white; }

/* Priority badges */
.priority-urgent { 
    background-color: #DC2626; 
    color: white; 
    animation: pulse-urgent 2s infinite;
    font-weight: bold;
}
.priority-high { background-color: #F59E0B; color: white; font-weight: 600; }
.priority-normal { background-color: #10B981; color: white; }
.priority-low { background-color: #6B7280; color: white; }

@keyframes pulse-urgent {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.8; transform: scale(1.05); }
}

/* Enhanced drag and drop styles */
.sortable-ghost {
    opacity: 0.3;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.sortable-drag {
    opacity: 1;
    cursor: grabbing !important;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    transform: rotate(2deg);
}

.order-row {
    transition: all 0.2s ease;
}

.order-row:hover {
    background-color: #f9fafb;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.order-row.selected {
    background-color: #eff6ff;
    border-left: 4px solid #3b82f6;
}

.drag-handle {
    cursor: grab;
    color: #6b7280;
    font-size: 1.25rem;
    padding: 0.5rem;
    transition: all 0.2s;
    background: #f9fafb;
    border-radius: 0.375rem;
    border: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
}

.drag-handle:hover {
    color: #3b82f6;
    background: #eff6ff;
    border-color: #3b82f6;
    transform: scale(1.1);
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.drag-handle:active {
    cursor: grabbing;
    background: #dbeafe;
    transform: scale(0.95);
}

/* Expandable row details */
.order-details {
    display: none;
    background: linear-gradient(to bottom, #f8fafc 0%, #ffffff 100%);
    border-top: 2px solid #e5e7eb;
}

.order-details.expanded {
    display: table-row;
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Action buttons */
.action-btn {
    padding: 6px 10px;
    font-size: 12px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.action-btn:active {
    transform: translateY(0);
}

/* Bulk action bar */
#bulkActionBar {
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1rem 2rem;
    border-radius: 50px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    z-index: 1000;
    display: none;
    align-items: center;
    gap: 1rem;
    animation: slideUp 0.3s ease-out;
}

#bulkActionBar.visible {
    display: flex;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateX(-50%) translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }
}

/* Smart badge for overdue orders */
.badge-overdue {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: bold;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

/* Loading overlay */
.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 9999;
}

.loading-overlay.active {
    display: flex;
}

.spinner {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #3b82f6;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Keyboard shortcuts help */
.kbd {
    background: #f3f4f6;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    padding: 2px 6px;
    font-family: monospace;
    font-size: 11px;
    color: #374151;
}

/* Smart recommendations */
.smart-recommendation {
    background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
    border-left: 4px solid #f97316;
    padding: 0.75rem 1rem;
    border-radius: 4px;
    margin-bottom: 1rem;
}

/* Column visibility controls */
.column-toggle {
    display: inline-block;
    margin: 0 0.5rem;
    cursor: pointer;
    user-select: none;
}

.column-toggle input[type="checkbox"] {
    margin-right: 4px;
}

/* Auto-refresh indicator */
.auto-refresh-indicator {
    position: fixed;
    top: 20px;
    right: 20px;
    background: #10b981;
    color: white;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 12px;
    display: none;
    align-items: center;
    gap: 8px;
    z-index: 1000;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.auto-refresh-indicator.active {
    display: flex;
}

.refresh-dot {
    width: 8px;
    height: 8px;
    background: white;
    border-radius: 50%;
    animation: pulse 1.5s infinite;
}

/* Performance metrics */
.perf-metric {
    font-size: 11px;
    color: #6b7280;
    padding: 4px 8px;
    background: #f3f4f6;
    border-radius: 4px;
    display: inline-block;
}

/* Timeline visualization */
.timeline-step {
    position: relative;
    padding-left: 30px;
    padding-bottom: 20px;
}

.timeline-step::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 8px;
    bottom: -10px;
    width: 2px;
    background: #e5e7eb;
}

.timeline-step::after {
    content: '';
    position: absolute;
    left: 4px;
    top: 4px;
    width: 10px;
    height: 10px;
    background: #3b82f6;
    border-radius: 50%;
    border: 2px solid white;
    box-shadow: 0 0 0 2px #3b82f6;
}

.timeline-step:last-child::before {
    display: none;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .hide-mobile {
        display: none;
    }
    
    #bulkActionBar {
        bottom: 10px;
        padding: 0.75rem 1rem;
        font-size: 14px;
    }
}
</style>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Auto-refresh indicator -->
<div id="autoRefreshIndicator" class="auto-refresh-indicator">
    <div class="refresh-dot"></div>
    <span>Auto-refreshing...</span>
</div>

<!-- Page Header with Smart Insights -->
<div class="mb-6">
    <div class="flex justify-between items-center flex-wrap gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-2">
                <?php echo $pageTitle; ?>
                <span class="text-sm font-normal text-gray-500">
                    <span class="perf-metric">
                        <i class="fas fa-database"></i> <?php echo number_format($total_count); ?> orders
                    </span>
                </span>
            </h1>
            <p class="text-sm text-gray-600 mt-1 flex items-center gap-2">
                <span>Role: <span class="font-semibold"><?php echo htmlspecialchars($user_role); ?></span></span>
                <span class="text-gray-400">•</span>
                <span>Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                <?php if ($stats->urgent_count > 0): ?>
                <span class="text-gray-400">•</span>
                <span class="text-red-600 font-semibold animate-pulse">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $stats->urgent_count; ?> urgent
                </span>
                <?php endif; ?>
            </p>
        </div>
        <div class="flex gap-2 flex-wrap">
            <button onclick="toggleShortcutsHelp()" class="px-3 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition text-sm" title="Keyboard shortcuts">
                <i class="fas fa-keyboard"></i>
            </button>
            <a href="../index.php" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition">
                <i class="fas fa-arrow-left mr-2"></i>Dashboard
            </a>
            <?php if ($is_admin || $is_sales): ?>
            <a href="create_order.php" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                <i class="fas fa-plus mr-2"></i>New Order
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Smart Recommendations -->
<?php if ($stats->overdue_count > 0): ?>
<div class="smart-recommendation">
    <div class="flex items-start gap-3">
        <i class="fas fa-lightbulb text-orange-600 text-2xl"></i>
        <div>
            <p class="font-bold text-orange-900">Smart Insight</p>
            <p class="text-sm text-orange-800">
                You have <strong><?php echo $stats->overdue_count; ?> overdue order(s)</strong>. 
                Consider prioritizing these orders or contacting customers to update delivery dates.
                <button onclick="filterOverdue()" class="underline font-semibold ml-2">View Overdue →</button>
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Enhanced Statistics Cards with Trends -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow-lg p-4 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90">Total Orders</p>
                <p class="text-3xl font-bold"><?php echo number_format($stats->total_orders ?? 0); ?></p>
                <p class="text-xs opacity-75 mt-1">In current view</p>
            </div>
            <i class="fas fa-shopping-cart text-4xl opacity-30"></i>
        </div>
    </div>
    
    <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg shadow-lg p-4 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90">Total Value</p>
                <p class="text-3xl font-bold">৳<?php echo number_format($stats->total_value ?? 0, 0); ?></p>
                <p class="text-xs opacity-75 mt-1">Revenue potential</p>
            </div>
            <i class="fas fa-dollar-sign text-4xl opacity-30"></i>
        </div>
    </div>
    
    <?php if ($is_admin || $is_accounts): ?>
    <div class="bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-lg shadow-lg p-4 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90">Total Paid</p>
                <p class="text-3xl font-bold">৳<?php echo number_format($stats->total_paid ?? 0, 0); ?></p>
                <?php 
                $collection_rate = $stats->total_value > 0 ? ($stats->total_paid / $stats->total_value) * 100 : 0;
                ?>
                <p class="text-xs opacity-75 mt-1"><?php echo number_format($collection_rate, 1); ?>% collected</p>
            </div>
            <i class="fas fa-check-circle text-4xl opacity-30"></i>
        </div>
    </div>
    
    <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-lg shadow-lg p-4 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90">Outstanding</p>
                <p class="text-3xl font-bold">৳<?php echo number_format($stats->total_outstanding ?? 0, 0); ?></p>
                <p class="text-xs opacity-75 mt-1">To be collected</p>
            </div>
            <i class="fas fa-exclamation-triangle text-4xl opacity-30"></i>
        </div>
    </div>
    <?php else: ?>
    <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow-lg p-4 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90">In Production</p>
                <p class="text-3xl font-bold"><?php echo number_format($stats->production_count ?? 0); ?></p>
                <p class="text-xs opacity-75 mt-1">Active orders</p>
            </div>
            <i class="fas fa-cogs text-4xl opacity-30"></i>
        </div>
    </div>
    
    <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg shadow-lg p-4 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90">Ready to Ship</p>
                <p class="text-3xl font-bold"><?php echo number_format($stats->ready_count ?? 0); ?></p>
                <p class="text-xs opacity-75 mt-1">Awaiting dispatch</p>
            </div>
            <i class="fas fa-box text-4xl opacity-30"></i>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg shadow-lg p-4 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90">Delivered</p>
                <p class="text-3xl font-bold"><?php echo number_format($stats->delivered_count ?? 0); ?></p>
                <?php 
                $completion_rate = $stats->total_orders > 0 ? ($stats->delivered_count / $stats->total_orders) * 100 : 0;
                ?>
                <p class="text-xs opacity-75 mt-1"><?php echo number_format($completion_rate, 1); ?>% complete</p>
            </div>
            <i class="fas fa-check-double text-4xl opacity-30"></i>
        </div>
    </div>
</div>

<!-- Advanced Filters and Search -->
<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-bold text-gray-900">
            <i class="fas fa-filter text-blue-600 mr-2"></i>
            Smart Filters
        </h3>
        <div class="flex gap-2">
            <button onclick="saveFilterPreset()" class="text-sm px-3 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200 transition">
                <i class="fas fa-save mr-1"></i>Save View
            </button>
            <button onclick="resetFilters()" class="text-sm px-3 py-1 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 transition">
                <i class="fas fa-redo mr-1"></i>Reset
            </button>
        </div>
    </div>
    
    <form method="GET" id="filterForm" class="space-y-4">
        
        <!-- Row 1: Dates and Status -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-3">
            <!-- Date From -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">From Date</label>
                <input type="date" 
                       name="date_from" 
                       value="<?php echo htmlspecialchars($date_from); ?>"
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <!-- Date To -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">To Date</label>
                <input type="date" 
                       name="date_to" 
                       value="<?php echo htmlspecialchars($date_to); ?>"
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <!-- Status Filter -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Status</label>
                <select name="status" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Statuses</option>
                    <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="pending_approval" <?php echo $status_filter === 'pending_approval' ? 'selected' : ''; ?>>Pending Approval</option>
                    <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="in_production" <?php echo $status_filter === 'in_production' ? 'selected' : ''; ?>>In Production</option>
                    <option value="produced" <?php echo $status_filter === 'produced' ? 'selected' : ''; ?>>Produced</option>
                    <option value="ready_to_ship" <?php echo $status_filter === 'ready_to_ship' ? 'selected' : ''; ?>>Ready to Ship</option>
                    <option value="shipped" <?php echo $status_filter === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                    <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            
            <!-- Priority Filter -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Priority</label>
                <select name="priority" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Priorities</option>
                    <option value="urgent" <?php echo $priority_filter === 'urgent' ? 'selected' : ''; ?>>🔴 Urgent</option>
                    <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>🟠 High</option>
                    <option value="normal" <?php echo $priority_filter === 'normal' ? 'selected' : ''; ?>>🟢 Normal</option>
                    <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>⚫ Low</option>
                </select>
            </div>
            
            <!-- Branch Filter -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Branch</label>
                <select name="branch" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                    <option value="<?php echo $branch->id; ?>" <?php echo $branch_filter == $branch->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($branch->name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Per Page -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Show</label>
                <select name="per_page" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25</option>
                    <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                    <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100</option>
                    <option value="200" <?php echo $per_page == 200 ? 'selected' : ''; ?>>200</option>
                </select>
            </div>
        </div>
        
        <!-- Row 2: Search and Actions -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <!-- Search -->
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">
                    <i class="fas fa-search mr-1"></i>Search (Order#, Customer, Phone)
                </label>
                <input type="text" 
                       name="search" 
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="Quick search..."
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <!-- Auto Refresh -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Auto Refresh</label>
                <select name="auto_refresh" id="autoRefreshSelect" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="0" <?php echo $auto_refresh == 0 ? 'selected' : ''; ?>>Off</option>
                    <option value="30" <?php echo $auto_refresh == 30 ? 'selected' : ''; ?>>30 seconds</option>
                    <option value="60" <?php echo $auto_refresh == 60 ? 'selected' : ''; ?>>1 minute</option>
                    <option value="300" <?php echo $auto_refresh == 300 ? 'selected' : ''; ?>>5 minutes</option>
                </select>
            </div>
            
            <!-- Actions -->
            <div class="flex items-end gap-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition text-sm font-semibold">
                    <i class="fas fa-filter mr-2"></i>Apply
                </button>
            </div>
        </div>
        
        <!-- Quick Filters -->
        <div class="flex flex-wrap items-center justify-between gap-2 pt-3 border-t">
            <div class="flex flex-wrap gap-2">
                <button type="button" onclick="quickFilter('today')" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                    Today
                </button>
                <button type="button" onclick="quickFilter('yesterday')" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                    Yesterday
                </button>
                <button type="button" onclick="quickFilter('this_week')" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                    This Week
                </button>
                <button type="button" onclick="quickFilter('this_month')" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                    This Month
                </button>
                <button type="button" onclick="quickFilter('last_month')" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                    Last Month
                </button>
            </div>
            
            <div class="flex gap-2">
                <button type="button" onclick="exportData('csv')" class="px-3 py-1 text-xs bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                    <i class="fas fa-file-csv mr-1"></i>CSV
                </button>
                <button type="button" onclick="exportData('pdf')" class="px-3 py-1 text-xs bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                    <i class="fas fa-file-pdf mr-1"></i>PDF
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Orders Table -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <div class="p-4 bg-gradient-to-r from-blue-50 to-purple-50 border-b flex justify-between items-center">
        <h2 class="text-lg font-bold text-gray-900 flex items-center gap-2">
            <i class="fas fa-list-alt text-blue-600"></i>
            Orders List
            <span class="text-sm font-normal text-gray-600">
                (<?php echo count($orders); ?> of <?php echo number_format($total_count); ?>)
            </span>
        </h2>
        
        <div class="flex items-center gap-3">
            <?php if ($is_admin): ?>
            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                <input type="checkbox" id="bulkSelectAll" class="rounded" onchange="toggleBulkSelectAll()">
                <span>Select All</span>
            </label>
            <div class="text-xs text-gray-600 flex items-center gap-2">
                <i class="fas fa-arrows-alt text-blue-600"></i>
                <span>Drag to reorder</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <?php if ($is_admin): ?>
                    <th class="px-2 py-3 text-center w-12">
                        <i class="fas fa-check-square text-gray-400"></i>
                    </th>
                    <th class="px-2 py-3 text-center w-12">
                        <i class="fas fa-grip-vertical text-gray-400"></i>
                    </th>
                    <?php endif; ?>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Order Details
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Customer
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Amount
                    </th>
                    <?php if ($is_admin || $is_accounts): ?>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Payment
                    </th>
                    <?php endif; ?>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Status
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Priority
                    </th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody id="ordersTableBody" class="bg-white divide-y divide-gray-200">
                <?php if (empty($orders)): ?>
                <tr>
                    <td colspan="<?php echo $is_admin ? '9' : ($is_accounts ? '8' : '7'); ?>" class="px-6 py-12 text-center text-gray-500">
                        <i class="fas fa-inbox text-5xl mb-3 text-gray-300"></i>
                        <p class="text-lg font-semibold">No orders found</p>
                        <p class="text-sm mt-2">Try adjusting your filters or date range</p>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($orders as $order): 
                    $outstanding = $order->total_amount - $order->amount_paid;
                    $payment_percentage = $order->total_amount > 0 ? ($order->amount_paid / $order->total_amount) * 100 : 0;
                    $can_edit = $is_admin && !in_array($order->status, ['delivered', 'cancelled']);
                    $is_overdue = $order->days_until_due < 0 && !in_array($order->status, ['delivered', 'cancelled']);
                ?>
                <tr class="order-row hover:bg-gray-50 transition" data-order-id="<?php echo $order->id; ?>">
                    <?php if ($is_admin): ?>
                    <td class="px-2 py-4 text-center">
                        <input type="checkbox" class="bulk-select-checkbox rounded" value="<?php echo $order->id; ?>" onchange="updateBulkSelection()">
                    </td>
                    <td class="px-2 py-4 w-12">
                        <div class="drag-handle" title="Drag to reorder">
                            <i class="fas fa-grip-vertical"></i>
                        </div>
                    </td>
                    <?php endif; ?>
                    
                    <!-- Order Details -->
                    <td class="px-6 py-4">
                        <div class="flex items-start gap-2">
                            <button onclick="toggleDetails(<?php echo $order->id; ?>)" 
                                    class="text-blue-600 hover:text-blue-800 transition">
                                <i class="fas fa-chevron-right expand-icon" id="expand-icon-<?php echo $order->id; ?>"></i>
                            </button>
                            <div class="flex-1">
                                <p class="text-sm font-bold text-gray-900"><?php echo htmlspecialchars($order->order_number); ?></p>
                                <p class="text-xs text-gray-500 flex items-center gap-1 mt-1">
                                    <i class="fas fa-calendar"></i>
                                    <?php echo date('M j, Y', strtotime($order->order_date)); ?>
                                </p>
                                <?php if ($order->required_date): ?>
                                <p class="text-xs flex items-center gap-1 mt-1 <?php echo $is_overdue ? 'text-red-600 font-semibold' : 'text-orange-600'; ?>">
                                    <i class="fas fa-clock"></i>
                                    Due: <?php echo date('M j', strtotime($order->required_date)); ?>
                                    <?php if ($is_overdue): ?>
                                    <span class="badge-overdue ml-1">OVERDUE</span>
                                    <?php elseif ($order->days_until_due <= 2): ?>
                                    <span class="text-red-600 font-semibold">(<?php echo $order->days_until_due; ?> days)</span>
                                    <?php endif; ?>
                                </p>
                                <?php endif; ?>
                                <p class="text-xs text-gray-500 mt-1">
                                    <i class="fas fa-boxes"></i>
                                    <?php echo $order->item_count; ?> item(s)
                                </p>
                            </div>
                        </div>
                    </td>
                    
                    <!-- Customer -->
                    <td class="px-6 py-4">
                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($order->customer_name); ?></p>
                        <p class="text-xs text-gray-500 flex items-center gap-1">
                            <i class="fas fa-phone"></i>
                            <?php echo htmlspecialchars($order->customer_phone); ?>
                        </p>
                        <?php if ($order->branch_name): ?>
                        <p class="text-xs text-gray-500 flex items-center gap-1 mt-1">
                            <i class="fas fa-map-marker-alt"></i>
                            <?php echo htmlspecialchars($order->branch_name); ?>
                        </p>
                        <?php endif; ?>
                    </td>
                    
                    <!-- Amount -->
                    <td class="px-6 py-4">
                        <p class="text-sm font-bold text-gray-900">৳<?php echo number_format($order->total_amount, 2); ?></p>
                        <p class="text-xs text-gray-500"><?php echo $order->order_type === 'advance_payment' ? 'Advance Payment' : 'Credit'; ?></p>
                    </td>
                    
                    <!-- Payment (Admin/Accounts only) -->
                    <?php if ($is_admin || $is_accounts): ?>
                    <td class="px-6 py-4">
                        <div class="space-y-1">
                            <p class="text-xs text-gray-600">Paid: <span class="font-semibold text-green-700">৳<?php echo number_format($order->amount_paid, 2); ?></span></p>
                            <p class="text-xs text-gray-600">Due: <span class="font-semibold text-red-700">৳<?php echo number_format($outstanding, 2); ?></span></p>
                            <div class="w-full bg-gray-200 rounded-full h-1.5">
                                <div class="bg-gradient-to-r from-green-400 to-green-600 h-1.5 rounded-full transition-all" style="width: <?php echo min($payment_percentage, 100); ?>%"></div>
                            </div>
                            <p class="text-xs text-gray-500"><?php echo number_format($payment_percentage, 0); ?>%</p>
                        </div>
                    </td>
                    <?php endif; ?>
                    
                    <!-- Status -->
                    <td class="px-6 py-4">
                        <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full status-<?php echo $order->status; ?>">
                            <?php echo ucwords(str_replace('_', ' ', $order->status)); ?>
                        </span>
                    </td>
                    
                    <!-- Priority -->
                    <td class="px-6 py-4">
                        <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full priority-<?php echo $order->priority; ?>">
                            <?php echo ucfirst($order->priority); ?>
                        </span>
                        <?php if ($order->sort_order !== null): ?>
                        <p class="text-xs text-gray-500 mt-1">#<?php echo $order->sort_order + 1; ?></p>
                        <?php endif; ?>
                    </td>
                    
                    <!-- Actions -->
                    <td class="px-6 py-4 text-right">
                        <div class="flex justify-end gap-1 flex-wrap">
                            <!-- View Details -->
                            <button onclick="toggleDetails(<?php echo $order->id; ?>)" 
                                    class="action-btn bg-blue-600 text-white hover:bg-blue-700"
                                    title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            
                            <!-- Edit (Admin only, if order not delivered/cancelled) -->
                            <?php if ($can_edit): ?>
                            <a href="admin_edit.php?id=<?php echo $order->id; ?>" 
                               class="action-btn bg-yellow-600 text-white hover:bg-yellow-700"
                               title="Edit Order">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php endif; ?>
                            
                            <!-- Collect Payment (Accounts only) -->
                            <?php if ($is_accounts && $outstanding > 0): ?>
                            <button onclick="collectPayment(<?php echo $order->id; ?>, <?php echo $outstanding; ?>)" 
                                    class="action-btn bg-green-600 text-white hover:bg-green-700"
                                    title="Collect Payment">
                                <i class="fas fa-money-bill-wave"></i>
                            </button>
                            <?php endif; ?>
                            
                            <!-- Change Status (Admin only) -->
                            <?php if ($can_edit): ?>
                            <button onclick="changeStatus(<?php echo $order->id; ?>, '<?php echo $order->status; ?>')" 
                                    class="action-btn bg-purple-600 text-white hover:bg-purple-700"
                                    title="Change Status">
                                <i class="fas fa-tasks"></i>
                            </button>
                            <?php endif; ?>
                            
                            <!-- Timeline -->
                            <button onclick="showTimeline(<?php echo $order->id; ?>)" 
                                    class="action-btn bg-indigo-600 text-white hover:bg-indigo-700"
                                    title="View Timeline">
                                <i class="fas fa-history"></i>
                            </button>
                            
                            <!-- Cancel (Admin only, if not delivered) -->
                            <?php if ($is_admin && $order->status !== 'delivered' && $order->status !== 'cancelled'): ?>
                            <button onclick="cancelOrder(<?php echo $order->id; ?>)" 
                                    class="action-btn bg-red-600 text-white hover:bg-red-700"
                                    title="Cancel Order">
                                <i class="fas fa-ban"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                
                <!-- Expandable Details Row -->
                <tr class="order-details" id="details-<?php echo $order->id; ?>">
                    <td colspan="<?php echo ($is_admin ? 9 : ($is_accounts ? 8 : 7)); ?>" class="px-6 py-6">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <!-- Order Information -->
                            <div class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                                <h4 class="font-bold text-gray-900 mb-3 flex items-center">
                                    <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                                    Order Information
                                </h4>
                                <dl class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <dt class="text-gray-600">Order Type:</dt>
                                        <dd class="font-semibold"><?php echo ucwords(str_replace('_', ' ', $order->order_type)); ?></dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-600">Subtotal:</dt>
                                        <dd class="font-semibold">৳<?php echo number_format($order->subtotal, 2); ?></dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-600">Discount:</dt>
                                        <dd class="font-semibold text-red-600">৳<?php echo number_format($order->discount_amount, 2); ?></dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-600">Tax:</dt>
                                        <dd class="font-semibold">৳<?php echo number_format($order->tax_amount, 2); ?></dd>
                                    </div>
                                    <div class="flex justify-between border-t pt-2">
                                        <dt class="text-gray-900 font-bold">Total:</dt>
                                        <dd class="font-bold text-lg">৳<?php echo number_format($order->total_amount, 2); ?></dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-600">Created By:</dt>
                                        <dd class="font-semibold"><?php echo htmlspecialchars($order->created_by_name); ?></dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-600">Created:</dt>
                                        <dd class="text-gray-600 text-xs"><?php echo date('M j, Y g:i A', strtotime($order->created_at)); ?></dd>
                                    </div>
                                </dl>
                            </div>
                            
                            <!-- Shipping & Notes -->
                            <div class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                                <h4 class="font-bold text-gray-900 mb-3 flex items-center">
                                    <i class="fas fa-shipping-fast text-green-600 mr-2"></i>
                                    Shipping & Notes
                                </h4>
                                <div class="space-y-3 text-sm">
                                    <?php if ($order->shipping_address): ?>
                                    <div>
                                        <p class="text-gray-600 font-semibold mb-1">Shipping Address:</p>
                                        <p class="text-gray-900 text-xs"><?php echo nl2br(htmlspecialchars($order->shipping_address)); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($order->special_instructions): ?>
                                    <div>
                                        <p class="text-gray-600 font-semibold mb-1">Special Instructions:</p>
                                        <p class="text-gray-900 text-xs"><?php echo nl2br(htmlspecialchars($order->special_instructions)); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($order->internal_notes && ($is_admin || $is_production)): ?>
                                    <div>
                                        <p class="text-gray-600 font-semibold mb-1">Internal Notes:</p>
                                        <p class="text-gray-900 text-xs"><?php echo nl2br(htmlspecialchars($order->internal_notes)); ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Order Items -->
                            <div class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                                <h4 class="font-bold text-gray-900 mb-3 flex items-center">
                                    <i class="fas fa-boxes text-purple-600 mr-2"></i>
                                    Order Items
                                </h4>
                                <div class="order-items-loading text-center py-4">
                                    <i class="fas fa-spinner fa-spin text-gray-400 text-2xl"></i>
                                    <p class="text-sm text-gray-500 mt-2">Loading items...</p>
                                </div>
                                <div class="order-items-container hidden text-xs"></div>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="px-6 py-4 bg-gray-50 border-t flex items-center justify-between">
        <div class="text-sm text-gray-700">
            Showing <?php echo (($page - 1) * $per_page) + 1; ?> to <?php echo min($page * $per_page, $total_count); ?> of <?php echo number_format($total_count); ?> orders
        </div>
        <div class="flex gap-2">
            <?php if ($page > 1): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
               class="px-3 py-1 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 transition text-sm">
                <i class="fas fa-chevron-left"></i> Previous
            </a>
            <?php endif; ?>
            
            <?php 
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            for ($i = $start_page; $i <= $end_page; $i++): 
            ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
               class="px-3 py-1 <?php echo $i == $page ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?> rounded transition text-sm">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
            
            <?php if ($page < $total_pages): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
               class="px-3 py-1 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 transition text-sm">
                Next <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

</div>

<!-- Bulk Action Bar -->
<div id="bulkActionBar">
    <span id="bulkCount">0</span> selected
    <button onclick="bulkUpdateStatus()" class="px-4 py-2 bg-white text-purple-700 rounded-full hover:bg-purple-50 transition font-semibold">
        <i class="fas fa-tasks mr-2"></i>Change Status
    </button>
    <button onclick="clearBulkSelection()" class="px-4 py-2 bg-white bg-opacity-20 text-white rounded-full hover:bg-opacity-30 transition">
        <i class="fas fa-times mr-2"></i>Clear
    </button>
</div>

<!-- Payment Collection Modal -->
<div id="paymentModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-2xl max-w-md w-full p-6 animate-fade-in">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-money-bill-wave text-green-600"></i>
                Collect Payment
            </h3>
            <button onclick="closePaymentModal()" class="text-gray-400 hover:text-gray-600 transition">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <form id="paymentForm" onsubmit="submitPayment(event)">
            <input type="hidden" id="payment_order_id" name="order_id">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Outstanding Amount
                    </label>
                    <p class="text-3xl font-bold text-red-600" id="payment_outstanding">৳0.00</p>
                </div>
                
                <div>
                    <label for="payment_amount" class="block text-sm font-medium text-gray-700 mb-1">
                        Payment Amount <span class="text-red-500">*</span>
                    </label>
                    <input type="number" 
                           id="payment_amount" 
                           name="amount"
                           step="0.01"
                           min="0.01"
                           required
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 text-lg">
                </div>
                
                <div>
                    <label for="payment_method" class="block text-sm font-medium text-gray-700 mb-1">
                        Payment Method <span class="text-red-500">*</span>
                    </label>
                    <select id="payment_method" 
                            name="payment_method"
                            required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        <option value="">Select method...</option>
                        <option value="cash">💵 Cash</option>
                        <option value="bank_transfer">🏦 Bank Transfer</option>
                        <option value="check">📄 Check</option>
                        <option value="mobile_banking">📱 Mobile Banking</option>
                        <option value="card">💳 Card</option>
                    </select>
                </div>
                
                <div>
                    <label for="payment_notes" class="block text-sm font-medium text-gray-700 mb-1">
                        Notes (Optional)
                    </label>
                    <textarea id="payment_notes" 
                              name="notes"
                              rows="2"
                              class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"></textarea>
                </div>
            </div>
            
            <div class="flex gap-3 mt-6">
                <button type="button" 
                        onclick="closePaymentModal()"
                        class="flex-1 px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition font-semibold">
                    Cancel
                </button>
                <button type="submit" 
                        class="flex-1 px-6 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg hover:from-green-600 hover:to-green-700 transition font-semibold shadow-lg">
                    <i class="fas fa-check mr-2"></i>Collect Payment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Status Change Modal -->
<div id="statusModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-2xl max-w-md w-full p-6 animate-fade-in">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-tasks text-purple-600"></i>
                Change Order Status
            </h3>
            <button onclick="closeStatusModal()" class="text-gray-400 hover:text-gray-600 transition">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <form id="statusForm" onsubmit="submitStatusChange(event)">
            <input type="hidden" id="status_order_id" name="order_id">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Current Status
                    </label>
                    <p class="text-lg font-semibold text-gray-900" id="current_status_display"></p>
                </div>
                
                <div>
                    <label for="new_status" class="block text-sm font-medium text-gray-700 mb-1">
                        New Status <span class="text-red-500">*</span>
                    </label>
                    <select id="new_status" 
                            name="status"
                            required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                        <option value="">Select status...</option>
                        <option value="pending_approval">⏳ Pending Approval</option>
                        <option value="approved">✅ Approved</option>
                        <option value="in_production">🔧 In Production</option>
                        <option value="produced">✨ Produced</option>
                        <option value="ready_to_ship">📦 Ready to Ship</option>
                        <option value="shipped">🚚 Shipped</option>
                        <option value="delivered">🎉 Delivered</option>
                        <option value="hold">⏸️ On Hold</option>
                        <option value="cancelled">🚫 Cancelled</option>
                    </select>
                </div>
            </div>
            
            <div class="flex gap-3 mt-6">
                <button type="button" 
                        onclick="closeStatusModal()"
                        class="flex-1 px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition font-semibold">
                    Cancel
                </button>
                <button type="submit" 
                        class="flex-1 px-6 py-3 bg-gradient-to-r from-purple-500 to-purple-600 text-white rounded-lg hover:from-purple-600 hover:to-purple-700 transition font-semibold shadow-lg">
                    <i class="fas fa-check mr-2"></i>Update Status
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Timeline Modal -->
<div id="timelineModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-2xl max-w-2xl w-full p-6 animate-fade-in max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4 sticky top-0 bg-white pb-4 border-b">
            <h3 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-history text-indigo-600"></i>
                Order Timeline
            </h3>
            <button onclick="closeTimelineModal()" class="text-gray-400 hover:text-gray-600 transition">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <div id="timelineContent" class="mt-4">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-4xl text-gray-400"></i>
                <p class="text-gray-500 mt-3">Loading timeline...</p>
            </div>
        </div>
    </div>
</div>

<!-- Keyboard Shortcuts Help -->
<div id="shortcutsModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-2xl max-w-lg w-full p-6 animate-fade-in">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-keyboard text-blue-600"></i>
                Keyboard Shortcuts
            </h3>
            <button onclick="toggleShortcutsHelp()" class="text-gray-400 hover:text-gray-600 transition">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <div class="space-y-3 text-sm">
            <div class="flex justify-between items-center py-2 border-b">
                <span>Search orders</span>
                <span class="kbd">Ctrl + F</span>
            </div>
            <div class="flex justify-between items-center py-2 border-b">
                <span>Refresh page</span>
                <span class="kbd">Ctrl + R</span>
            </div>
            <div class="flex justify-between items-center py-2 border-b">
                <span>Select all (bulk)</span>
                <span class="kbd">Ctrl + A</span>
            </div>
            <div class="flex justify-between items-center py-2 border-b">
                <span>Export CSV</span>
                <span class="kbd">Ctrl + E</span>
            </div>
            <div class="flex justify-between items-center py-2 border-b">
                <span>Export PDF</span>
                <span class="kbd">Ctrl + P</span>
            </div>
            <div class="flex justify-between items-center py-2 border-b">
                <span>New order</span>
                <span class="kbd">Ctrl + N</span>
            </div>
            <div class="flex justify-between items-center py-2 border-b">
                <span>Close modal</span>
                <span class="kbd">Esc</span>
            </div>
            <div class="flex justify-between items-center py-2">
                <span>Show shortcuts</span>
                <span class="kbd">?</span>
            </div>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay">
    <div>
        <div class="spinner"></div>
        <p class="text-white mt-4 font-semibold">Processing...</p>
    </div>
</div>

<script>
// Configuration
const AUTO_REFRESH_INTERVAL = <?php echo $auto_refresh * 1000; ?>;
let autoRefreshTimer = null;

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initializePage();
});

function initializePage() {
    <?php if ($is_admin): ?>
    initializeSortable();
    <?php endif; ?>
    
    // Start auto-refresh if enabled
    if (AUTO_REFRESH_INTERVAL > 0) {
        startAutoRefresh();
    }
    
    // Performance metrics
    if (window.performance) {
        const perfData = window.performance.timing;
        const pageLoadTime = perfData.loadEventEnd - perfData.navigationStart;
        console.log('Page load time:', pageLoadTime + 'ms');
    }
}

// Initialize drag and drop for admins
<?php if ($is_admin): ?>
function initializeSortable() {
    const tbody = document.getElementById('ordersTableBody');
    
    if (tbody && tbody.children.length > 0) {
        new Sortable(tbody, {
            animation: 200,
            handle: '.drag-handle',
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            filter: '.order-details',
            preventOnFilter: false,
            onStart: function() {
                document.body.style.cursor = 'grabbing';
            },
            onEnd: function(evt) {
                document.body.style.cursor = '';
                updateOrderPriority();
            }
        });
    }
}

function updateOrderPriority() {
    const rows = document.querySelectorAll('.order-row');
    const orderIds = [];
    
    rows.forEach(row => {
        const orderId = row.dataset.orderId;
        if (orderId) {
            orderIds.push(orderId);
        }
    });
    
    if (orderIds.length === 0) return;
    
    showLoading();
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=update_priority&order_ids=${JSON.stringify(orderIds)}`
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification('Order priority updated successfully', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification(data.message || 'Failed to update priority', 'error');
        }
    })
    .catch(error => {
        hideLoading();
        showNotification('Network error occurred', 'error');
        console.error('Error:', error);
    });
}
<?php endif; ?>

// Toggle order details
function toggleDetails(orderId) {
    const detailsRow = document.getElementById(`details-${orderId}`);
    const icon = document.getElementById(`expand-icon-${orderId}`);
    
    if (detailsRow.classList.contains('expanded')) {
        detailsRow.classList.remove('expanded');
        icon.classList.remove('fa-chevron-down');
        icon.classList.add('fa-chevron-right');
    } else {
        detailsRow.classList.add('expanded');
        icon.classList.remove('fa-chevron-right');
        icon.classList.add('fa-chevron-down');
        
        // Load order items if not already loaded
        loadOrderItems(orderId);
    }
}

// Load order items via AJAX
function loadOrderItems(orderId) {
    const container = document.querySelector(`#details-${orderId} .order-items-container`);
    const loading = document.querySelector(`#details-${orderId} .order-items-loading`);
    
    // Only load if not already loaded
    if (container.children.length > 0 && !container.classList.contains('hidden')) return;
    
    // Show loading
    loading.classList.remove('hidden');
    container.classList.add('hidden');
    
    // Fetch order items
    fetch(`ajax/load_order_items.php?order_id=${orderId}`)
        .then(response => response.json())
        .then(data => {
            loading.classList.add('hidden');
            container.classList.remove('hidden');
            
            if (data.success && data.items.length > 0) {
                let html = '<div class="space-y-2 max-h-64 overflow-y-auto">';
                
                data.items.forEach(item => {
                    const displayName = item.variant_name ? 
                        `${item.product_name} - ${item.variant_name}` : 
                        item.product_name;
                    
                    html += `
                        <div class="flex justify-between items-center py-2 border-b border-gray-200 hover:bg-gray-50 px-2 rounded transition">
                            <div class="flex-1">
                                <p class="font-semibold text-gray-900">${escapeHtml(displayName)}</p>
                                <p class="text-gray-500 text-xs">
                                    SKU: ${escapeHtml(item.variant_sku || item.product_sku || 'N/A')}
                                </p>
                            </div>
                            <div class="text-right ml-3">
                                <p class="font-semibold text-gray-900">
                                    ${formatNumber(item.quantity)} × ৳${formatNumber(item.unit_price)}
                                </p>
                                <p class="text-gray-600">
                                    = ৳${formatNumber(item.line_total)}
                                </p>
                            </div>
                        </div>
                    `;
                });
                
                html += `
                    </div>
                    <div class="mt-4 pt-4 border-t-2 border-gray-300 bg-blue-50 p-3 rounded">
                        <div class="flex justify-between">
                            <span class="text-gray-700 font-semibold">Subtotal:</span>
                            <span class="font-bold">৳${formatNumber(data.summary.subtotal)}</span>
                        </div>
                        ${data.summary.total_discount > 0 ? `
                        <div class="flex justify-between mt-1">
                            <span class="text-gray-700">Discount:</span>
                            <span class="font-semibold text-red-600">- ৳${formatNumber(data.summary.total_discount)}</span>
                        </div>
                        ` : ''}
                        ${data.summary.total_tax > 0 ? `
                        <div class="flex justify-between mt-1">
                            <span class="text-gray-700">Tax:</span>
                            <span class="font-semibold">৳${formatNumber(data.summary.total_tax)}</span>
                        </div>
                        ` : ''}
                        <div class="flex justify-between font-bold text-lg mt-2 pt-2 border-t border-gray-300">
                            <span class="text-gray-900">Grand Total:</span>
                            <span class="text-blue-600">৳${formatNumber(data.summary.grand_total)}</span>
                        </div>
                        <p class="text-center text-gray-500 mt-2 text-xs">${data.summary.item_count} item(s)</p>
                    </div>
                `;
                
                container.innerHTML = html;
            } else {
                container.innerHTML = `
                    <p class="text-gray-500 text-center py-4">
                        <i class="fas fa-inbox text-2xl mb-2 text-gray-300 block"></i>
                        No items found for this order
                    </p>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading items:', error);
            loading.classList.add('hidden');
            container.classList.remove('hidden');
            container.innerHTML = `
                <p class="text-red-500 text-center py-4">
                    <i class="fas fa-exclamation-circle mb-2 text-2xl block"></i>
                    Failed to load items. Please try again.
                </p>
            `;
        });
}

// Helper functions
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatNumber(num) {
    return parseFloat(num).toFixed(2);
}

// Payment collection
function collectPayment(orderId, outstanding) {
    document.getElementById('payment_order_id').value = orderId;
    document.getElementById('payment_outstanding').textContent = `৳${outstanding.toFixed(2)}`;
    document.getElementById('payment_amount').max = outstanding;
    document.getElementById('payment_amount').value = outstanding;
    document.getElementById('paymentModal').classList.remove('hidden');
    document.getElementById('payment_amount').focus();
}

function closePaymentModal() {
    document.getElementById('paymentModal').classList.add('hidden');
    document.getElementById('paymentForm').reset();
}

function submitPayment(event) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    formData.append('action', 'collect_payment');
    
    showLoading();
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification('Payment collected successfully', 'success');
            closePaymentModal();
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification(data.message || 'Failed to collect payment', 'error');
        }
    })
    .catch(error => {
        hideLoading();
        showNotification('Network error occurred', 'error');
        console.error('Error:', error);
    });
}

// Status change
function changeStatus(orderId, currentStatus) {
    document.getElementById('status_order_id').value = orderId;
    document.getElementById('current_status_display').textContent = currentStatus.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    document.getElementById('statusModal').classList.remove('hidden');
}

function closeStatusModal() {
    document.getElementById('statusModal').classList.add('hidden');
    document.getElementById('statusForm').reset();
}

function submitStatusChange(event) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    formData.append('action', 'update_status');
    
    showLoading();
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification('Order status updated successfully', 'success');
            closeStatusModal();
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification(data.message || 'Failed to update status', 'error');
        }
    })
    .catch(error => {
        hideLoading();
        showNotification('Network error occurred', 'error');
        console.error('Error:', error);
    });
}

// Cancel order
function cancelOrder(orderId) {
    if (!confirm('Are you sure you want to cancel this order? This action cannot be undone.')) {
        return;
    }
    
    showLoading();
    
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('order_id', orderId);
    formData.append('status', 'cancelled');
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification('Order cancelled successfully', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification(data.message || 'Failed to cancel order', 'error');
        }
    })
    .catch(error => {
        hideLoading();
        showNotification('Network error occurred', 'error');
        console.error('Error:', error);
    });
}

// Show timeline
function showTimeline(orderId) {
    document.getElementById('timelineModal').classList.remove('hidden');
    document.getElementById('timelineContent').innerHTML = `
        <div class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-4xl text-gray-400"></i>
            <p class="text-gray-500 mt-3">Loading timeline...</p>
        </div>
    `;
    
    fetch(window.location.href + '?action=get_order_timeline&order_id=' + orderId, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.timeline.length > 0) {
            let html = '<div class="space-y-4">';
            
            data.timeline.forEach((event, index) => {
                const actionIcons = {
                    'created': 'fa-plus-circle text-blue-600',
                    'updated': 'fa-edit text-yellow-600',
                    'status_changed': 'fa-exchange-alt text-purple-600',
                    'priority_changed': 'fa-flag text-orange-600',
                    'payment_collected': 'fa-money-bill-wave text-green-600'
                };
                
                const icon = actionIcons[event.action_type] || 'fa-circle text-gray-600';
                
                html += `
                    <div class="timeline-step">
                        <div class="flex items-start gap-3">
                            <i class="fas ${icon} text-xl mt-1"></i>
                            <div class="flex-1">
                                <p class="font-semibold text-gray-900">
                                    ${event.action_type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                </p>
                                ${event.notes ? `<p class="text-sm text-gray-600 mt-1">${escapeHtml(event.notes)}</p>` : ''}
                                <p class="text-xs text-gray-500 mt-2">
                                    by ${escapeHtml(event.user_name)} • ${new Date(event.created_at).toLocaleString()}
                                </p>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            document.getElementById('timelineContent').innerHTML = html;
        } else {
            document.getElementById('timelineContent').innerHTML = `
                <p class="text-center text-gray-500 py-8">
                    <i class="fas fa-inbox text-4xl mb-3 text-gray-300 block"></i>
                    No timeline events found
                </p>
            `;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        document.getElementById('timelineContent').innerHTML = `
            <p class="text-center text-red-500 py-8">
                Failed to load timeline
            </p>
        `;
    });
}

function closeTimelineModal() {
    document.getElementById('timelineModal').classList.add('hidden');
}

// Bulk actions
<?php if ($is_admin): ?>
function toggleBulkSelectAll() {
    const checked = document.getElementById('bulkSelectAll').checked;
    document.querySelectorAll('.bulk-select-checkbox').forEach(cb => {
        cb.checked = checked;
    });
    updateBulkSelection();
}

function updateBulkSelection() {
    const selected = document.querySelectorAll('.bulk-select-checkbox:checked');
    const bulkBar = document.getElementById('bulkActionBar');
    const bulkCount = document.getElementById('bulkCount');
    
    if (selected.length > 0) {
        bulkBar.classList.add('visible');
        bulkCount.textContent = selected.length;
        
        // Update row styling
        document.querySelectorAll('.order-row').forEach(row => {
            const checkbox = row.querySelector('.bulk-select-checkbox');
            if (checkbox && checkbox.checked) {
                row.classList.add('selected');
            } else {
                row.classList.remove('selected');
            }
        });
    } else {
        bulkBar.classList.remove('visible');
        document.querySelectorAll('.order-row').forEach(row => {
            row.classList.remove('selected');
        });
    }
}

function clearBulkSelection() {
    document.querySelectorAll('.bulk-select-checkbox').forEach(cb => {
        cb.checked = false;
    });
    document.getElementById('bulkSelectAll').checked = false;
    updateBulkSelection();
}

function bulkUpdateStatus() {
    const selected = Array.from(document.querySelectorAll('.bulk-select-checkbox:checked')).map(cb => cb.value);
    
    if (selected.length === 0) {
        showNotification('No orders selected', 'warning');
        return;
    }
    
    const status = prompt('Enter new status (approved, in_production, produced, ready_to_ship, shipped, delivered):');
    
    if (!status) return;
    
    showLoading();
    
    const formData = new FormData();
    formData.append('action', 'bulk_update_status');
    formData.append('order_ids', JSON.stringify(selected));
    formData.append('status', status);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification(data.message || 'Failed to update orders', 'error');
        }
    })
    .catch(error => {
        hideLoading();
        showNotification('Network error occurred', 'error');
        console.error('Error:', error);
    });
}
<?php endif; ?>

// Quick date filters
function quickFilter(period) {
    const today = new Date();
    let fromDate, toDate;
    
    switch(period) {
        case 'today':
            fromDate = toDate = formatDate(today);
            break;
        case 'yesterday':
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            fromDate = toDate = formatDate(yesterday);
            break;
        case 'this_week':
            const weekStart = new Date(today);
            weekStart.setDate(today.getDate() - today.getDay() + 1);
            fromDate = formatDate(weekStart);
            toDate = formatDate(today);
            break;
        case 'this_month':
            fromDate = formatDate(new Date(today.getFullYear(), today.getMonth(), 1));
            toDate = formatDate(today);
            break;
        case 'last_month':
            const lastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            fromDate = formatDate(lastMonth);
            const lastMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0);
            toDate = formatDate(lastMonthEnd);
            break;
    }
    
    document.querySelector('[name="date_from"]').value = fromDate;
    document.querySelector('[name="date_to"]').value = toDate;
    document.getElementById('filterForm').submit();
}

function filterOverdue() {
    const form = document.getElementById('filterForm');
    const today = formatDate(new Date());
    document.querySelector('[name="date_from"]').value = '2020-01-01';
    document.querySelector('[name="date_to"]').value = today;
    document.querySelector('[name="status"]').value = '';
    form.submit();
}

function formatDate(date) {
    return date.toISOString().split('T')[0];
}

// Reset filters
function resetFilters() {
    window.location.href = window.location.pathname;
}

// Export data
function exportData(format) {
    const form = document.getElementById('filterForm');
    const url = new URL(window.location.href);
    
    const formData = new FormData(form);
    formData.forEach((value, key) => {
        if (value) url.searchParams.set(key, value);
    });
    
    url.searchParams.set('export', format);
    
    window.open(url.toString(), '_blank');
}

// Save filter preset
function saveFilterPreset() {
    const name = prompt('Enter a name for this filter preset:');
    if (!name) return;
    
    const formData = new FormData(document.getElementById('filterForm'));
    const filters = {};
    formData.forEach((value, key) => {
        if (value && key !== 'page') filters[key] = value;
    });
    
    const data = new FormData();
    data.append('action', 'save_filter_preset');
    data.append('preset_name', name);
    data.append('filters', JSON.stringify(filters));
    
    fetch(window.location.href, {
        method: 'POST',
        body: data
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Filter preset saved', 'success');
        }
    });
}

// Auto refresh
function startAutoRefresh() {
    if (AUTO_REFRESH_INTERVAL <= 0) return;
    
    document.getElementById('autoRefreshIndicator').classList.add('active');
    
    autoRefreshTimer = setInterval(() => {
        // Silent refresh without reloading the page
        location.reload();
    }, AUTO_REFRESH_INTERVAL);
}

function stopAutoRefresh() {
    if (autoRefreshTimer) {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
        document.getElementById('autoRefreshIndicator').classList.remove('active');
    }
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl/Cmd + F for search
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        document.querySelector('[name="search"]').focus();
    }
    
    // Ctrl/Cmd + E for CSV export
    if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
        e.preventDefault();
        exportData('csv');
    }
    
    // Ctrl/Cmd + P for PDF export
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        e.preventDefault();
        exportData('pdf');
    }
    
    // Ctrl/Cmd + N for new order
    if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
        e.preventDefault();
        window.location.href = 'create_order.php';
    }
    
    // Ctrl/Cmd + A for select all (bulk)
    <?php if ($is_admin): ?>
    if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
        e.preventDefault();
        document.getElementById('bulkSelectAll').checked = true;
        toggleBulkSelectAll();
    }
    <?php endif; ?>
    
    // ? for shortcuts help
    if (e.key === '?' && !e.ctrlKey && !e.metaKey) {
        e.preventDefault();
        toggleShortcutsHelp();
    }
    
    // Escape to close modals
    if (e.key === 'Escape') {
        closePaymentModal();
        closeStatusModal();
        closeTimelineModal();
        toggleShortcutsHelp(true);
    }
});

// Shortcuts help
function toggleShortcutsHelp(forceClose = false) {
    const modal = document.getElementById('shortcutsModal');
    if (forceClose) {
        modal.classList.add('hidden');
    } else {
        modal.classList.toggle('hidden');
    }
}

// Utility functions
function showLoading() {
    document.getElementById('loadingOverlay').classList.add('active');
}

function hideLoading() {
    document.getElementById('loadingOverlay').classList.remove('active');
}

function showNotification(message, type = 'success') {
    const colors = {
        success: 'from-green-500 to-green-600',
        error: 'from-red-500 to-red-600',
        info: 'from-blue-500 to-blue-600',
        warning: 'from-yellow-500 to-yellow-600'
    };
    
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        info: 'fa-info-circle',
        warning: 'fa-exclamation-triangle'
    };
    
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 bg-gradient-to-r ${colors[type]} text-white px-6 py-4 rounded-lg shadow-2xl z-50 animate-fade-in max-w-md`;
    notification.innerHTML = `
        <div class="flex items-center gap-3">
            <i class="fas ${icons[type]} text-2xl"></i>
            <span class="font-semibold">${message}</span>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.transition = 'all 0.3s ease-out';
        notification.style.opacity = '0';
        notification.style.transform = 'translateX(100px)';
        setTimeout(() => notification.remove(), 300);
    }, 4000);
}

// Stop auto-refresh when user leaves page
window.addEventListener('beforeunload', () => {
    stopAutoRefresh();
});

// Page visibility API - pause refresh when tab is hidden
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        stopAutoRefresh();
    } else if (AUTO_REFRESH_INTERVAL > 0) {
        startAutoRefresh();
    }
});
</script>

<style>
@keyframes fade-in {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.animate-fade-in {
    animation: fade-in 0.3s ease-out;
}
</style>

<?php require_once '../templates/footer.php'; ?>
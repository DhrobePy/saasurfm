<?php
require_once '../../core/init.php';

// Access control
$allowed_roles = ['Superadmin', 'admin', 'Transport Manager', 'Accounts'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Vehicle Rentals';

// --- Get Filters ---
$vehicle_filter = $_GET['vehicle'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

$where_conditions = [];
$params = [];

if ($vehicle_filter !== 'all') {
    $where_conditions[] = "vr.vehicle_id = ?";
    $params[] = (int)$vehicle_filter;
}
if ($status_filter !== 'all') {
    $where_conditions[] = "vr.status = ?";
    $params[] = $status_filter;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// --- Get Main Rental List ---
$rentals = $db->query(
    "SELECT 
        vr.*, 
        v.vehicle_number, 
        c.name as customer_name
     FROM vehicle_rentals vr
     JOIN vehicles v ON vr.vehicle_id = v.id
     JOIN customers c ON vr.customer_id = c.id
     $where_clause
     ORDER BY vr.start_datetime DESC
     LIMIT 100",
    $params
)->results();

// --- Get Stats Cards Data ---
$stats = $db->query(
    "SELECT 
        SUM(total_amount) as total_revenue,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as active_rentals,
        SUM(CASE WHEN status = 'Scheduled' THEN 1 ELSE 0 END) as scheduled_rentals
     FROM vehicle_rentals
     WHERE status != 'Cancelled'"
)->first();

// Get vehicles for filter dropdown
$vehicles = $db->query("SELECT id, vehicle_number FROM vehicles WHERE status = 'Active' ORDER BY vehicle_number")->results();

require_once '../../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">🚙 Vehicle Rentals</h1>
            <p class="text-gray-600 mt-1">Track vehicle rental contracts, status, and revenue.</p>
        </div>
        <a href="manage.php" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 shadow-sm">
            <i class="fas fa-plus mr-2"></i>New Rental
        </a>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow-md p-6">
            <p class="text-sm text-gray-600">Total Rental Revenue</p>
            <p class="text-3xl font-bold text-green-600">৳<?php echo number_format($stats->total_revenue ?? 0, 0); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow-md p-6">
            <p class="text-sm text-gray-600">Active Rentals</p>
            <p class="text-3xl font-bold text-blue-600"><?php echo $stats->active_rentals ?? 0; ?></p>
        </div>
        <div class="bg-white rounded-lg shadow-md p-6">
            <p class="text-sm text-gray-600">Scheduled Rentals</p>
            <p class="text-3xl font-bold text-yellow-600"><?php echo $stats->scheduled_rentals ?? 0; ?></p>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-md p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-4 items-center">
            <div>
                <label for="vehicle_filter" class="block text-sm font-medium text-gray-700 mb-1">Vehicle</label>
                <select id="vehicle_filter" name="vehicle" class="px-4 py-2 border border-gray-300 rounded-lg" onchange="this.form.submit()">
                    <option value="all">All Vehicles</option>
                    <?php foreach ($vehicles as $vehicle): ?>
                    <option value="<?php echo $vehicle->id; ?>" <?php echo $vehicle_filter == $vehicle->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($vehicle->vehicle_number); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="status_filter" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select id="status_filter" name="status" class="px-4 py-2 border border-gray-300 rounded-lg" onchange="this.form.submit()">
                    <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="Scheduled" <?php echo $status_filter == 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                    <option value="In Progress" <?php echo $status_filter == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="Completed" <?php echo $status_filter == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="Cancelled" <?php echo $status_filter == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div class="pt-5">
                <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Clear Filters</a>
            </div>
        </form>
    </div>

    <!-- Rental List Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer / Vehicle</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Period</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($rentals)): ?>
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-gray-500">No rental records found.</td>
                    </tr>
                    <?php else: ?>
                        <?php 
                        foreach ($rentals as $rental): 
                            // Status Colors
                            $status_color = 'gray';
                            if ($rental->status == 'Scheduled') $status_color = 'yellow';
                            if ($rental->status == 'In Progress') $status_color = 'blue';
                            if ($rental->status == 'Completed') $status_color = 'green';
                            
                            // Payment Status Colors
                            $payment_color = 'red';
                            if ($rental->payment_status == 'Partially Paid') $payment_color = 'yellow';
                            if ($rental->payment_status == 'Paid') $payment_color = 'green';
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($rental->customer_name); ?></div>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($rental->vehicle_number); ?></div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm text-gray-900"><?php echo date('d M Y, h:i A', strtotime($rental->start_datetime)); ?></div>
                                <div class="text-xs text-gray-500">to <?php echo date('d M Y, h:i A', strtotime($rental->end_datetime)); ?></div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500"><?php echo htmlspecialchars($rental->rental_type); ?></td>
                            <td class="px-4 py-3 text-sm">
                                <span class="px-2 py-0.5 text-xs font-bold rounded-full bg-<?php echo $status_color; ?>-100 text-<?php echo $status_color; ?>-800">
                                    <?php echo htmlspecialchars($rental->status); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <span class="px-2 py-0.5 text-xs font-bold rounded-full bg-<?php echo $payment_color; ?>-100 text-<?php echo $payment_color; ?>-800">
                                    <?php echo htmlspecialchars($rental->payment_status); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">
                                ৳<?php echo number_format($rental->total_amount, 2); ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <a href="manage.php?id=<?php echo $rental->id; ?>" class="text-blue-600 hover:text-blue-800" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <!-- You would link to a payment page here, passing the rental_id -->
                                <a href="../accounts/receive_payment.php?rental_id=<?php echo $rental->id; ?>" class="text-green-600 hover:text-green-800 ml-3" title="Receive Payment">
                                    <i class="fas fa-hand-holding-usd"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Alpine.js for tabs -->
<script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.x.x/dist/alpine.min.js" defer></script>

<?php require_once '../../templates/footer.php'; ?>
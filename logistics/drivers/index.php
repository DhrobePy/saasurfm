<?php
require_once '../../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Transport Manager'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Drivers';

// Get filter
$filter_type = $_GET['type'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';

$where_conditions = [];
$params = [];

if ($filter_type !== 'all') {
    $where_conditions[] = "driver_type = ?";
    $params[] = $filter_type;
}

if ($filter_status !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $filter_status;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$drivers = $db->query(
    "SELECT d.*, v.vehicle_number, b.name as branch_name
     FROM drivers d
     LEFT JOIN vehicles v ON d.assigned_vehicle_id = v.id
     LEFT JOIN branches b ON d.assigned_branch_id = b.id
     $where_clause
     ORDER BY d.driver_type, d.driver_name",
    $params
)->results();

require_once '../../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Header -->
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">👤 Drivers</h1>
        <p class="text-gray-600 mt-1">Manage your driver fleet</p>
    </div>
    <a href="manage.php" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
        <i class="fas fa-plus mr-2"></i>Add Driver
    </a>
</div>

<!-- Filters -->
<div class="bg-white rounded-lg shadow-md p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Driver Type</label>
            <select name="type" class="px-4 py-2 border rounded-lg" onchange="this.form.submit()">
                <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                <option value="Permanent" <?php echo $filter_type === 'Permanent' ? 'selected' : ''; ?>>Permanent</option>
                <option value="Temporary" <?php echo $filter_type === 'Temporary' ? 'selected' : ''; ?>>Temporary</option>
                <option value="Rental" <?php echo $filter_type === 'Rental' ? 'selected' : ''; ?>>Rental</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select name="status" class="px-4 py-2 border rounded-lg" onchange="this.form.submit()">
                <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                <option value="Active" <?php echo $filter_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                <option value="Inactive" <?php echo $filter_status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                <option value="On Leave" <?php echo $filter_status === 'On Leave' ? 'selected' : ''; ?>>On Leave</option>
            </select>
        </div>
        <?php if ($filter_type !== 'all' || $filter_status !== 'all'): ?>
        <div class="flex items-end">
            <a href="index.php" class="px-4 py-2 border rounded-lg hover:bg-gray-50">Clear Filters</a>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- Driver Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php if (empty($drivers)): ?>
    <div class="col-span-full bg-white rounded-lg shadow-md p-12 text-center">
        <i class="fas fa-user-tie text-6xl text-gray-300 mb-4"></i>
        <p class="text-xl text-gray-500">No drivers found</p>
        <a href="manage.php" class="inline-block mt-4 px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
            Add Your First Driver
        </a>
    </div>
    <?php else: ?>
        <?php foreach ($drivers as $driver): ?>
        <div class="bg-white rounded-lg shadow-md hover:shadow-lg transition-shadow">
            <div class="p-6">
                <!-- Photo & Header -->
                <div class="flex items-start gap-4 mb-4">
                    <?php if ($driver->photo_path): ?>
                    <img src="<?php echo url($driver->photo_path); ?>" alt="Driver Photo" 
                         class="w-16 h-16 rounded-full object-cover border-2 border-gray-200">
                    <?php else: ?>
                    <div class="w-16 h-16 rounded-full bg-gray-200 flex items-center justify-center">
                        <i class="fas fa-user text-2xl text-gray-400"></i>
                    </div>
                    <?php endif; ?>
                    
                    <div class="flex-1">
                        <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($driver->driver_name); ?></h3>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($driver->phone_number); ?></p>
                        <div class="mt-1">
                            <span class="px-2 py-1 rounded-full text-xs font-semibold
                                <?php 
                                $type_colors = [
                                    'Permanent' => 'bg-blue-100 text-blue-800',
                                    'Temporary' => 'bg-purple-100 text-purple-800',
                                    'Rental' => 'bg-orange-100 text-orange-800'
                                ];
                                echo $type_colors[$driver->driver_type] ?? 'bg-gray-100 text-gray-800';
                                ?>">
                                <?php echo $driver->driver_type; ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Status Badge -->
                <div class="mb-4">
                    <?php
                    $status_colors = [
                        'Active' => 'bg-green-100 text-green-800',
                        'Inactive' => 'bg-gray-100 text-gray-800',
                        'On Leave' => 'bg-yellow-100 text-yellow-800',
                        'Terminated' => 'bg-red-100 text-red-800'
                    ];
                    $color = $status_colors[$driver->status] ?? 'bg-gray-100 text-gray-800';
                    ?>
                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full <?php echo $color; ?>">
                        <?php echo $driver->status; ?>
                    </span>
                </div>
                
                <!-- Details -->
                <div class="space-y-2 mb-4 text-sm">
                    <p class="text-gray-600">
                        <i class="fas fa-id-card text-gray-400 w-5"></i>
                        License: <span class="font-medium"><?php echo htmlspecialchars($driver->license_number); ?></span>
                    </p>
                    
                    <?php if ($driver->rating): ?>
                    <p class="text-gray-600">
                        <i class="fas fa-star text-yellow-400 w-5"></i>
                        Rating: <span class="font-medium"><?php echo number_format($driver->rating, 1); ?>/5.0</span>
                    </p>
                    <?php endif; ?>
                    
                    <p class="text-gray-600">
                        <i class="fas fa-route text-gray-400 w-5"></i>
                        Total Trips: <span class="font-medium"><?php echo $driver->total_trips; ?></span>
                    </p>
                    
                    <?php if ($driver->vehicle_number): ?>
                    <p class="text-gray-600">
                        <i class="fas fa-truck text-gray-400 w-5"></i>
                        Assigned: <span class="font-medium"><?php echo htmlspecialchars($driver->vehicle_number); ?></span>
                    </p>
                    <?php else: ?>
                    <p class="text-gray-600">
                        <i class="fas fa-truck text-gray-400 w-5"></i>
                        <span class="text-green-600 font-medium">Available</span>
                    </p>
                    <?php endif; ?>
                    
                    <?php if ($driver->branch_name): ?>
                    <p class="text-gray-600">
                        <i class="fas fa-building text-gray-400 w-5"></i>
                        Branch: <span class="font-medium"><?php echo htmlspecialchars($driver->branch_name); ?></span>
                    </p>
                    <?php endif; ?>
                    
                    <?php if ($driver->driver_type === 'Permanent' && $driver->salary): ?>
                    <p class="text-gray-600">
                        <i class="fas fa-money-bill-wave text-gray-400 w-5"></i>
                        Salary: <span class="font-medium">৳<?php echo number_format($driver->salary, 0); ?></span>
                    </p>
                    <?php elseif ($driver->daily_rate): ?>
                    <p class="text-gray-600">
                        <i class="fas fa-money-bill-wave text-gray-400 w-5"></i>
                        Daily Rate: <span class="font-medium">৳<?php echo number_format($driver->daily_rate, 0); ?></span>
                    </p>
                    <?php endif; ?>
                </div>
                
                <!-- Actions -->
                <div class="flex gap-2 pt-4 border-t">
                    <a href="view.php?id=<?php echo $driver->id; ?>" 
                       class="flex-1 text-center px-3 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700">
                        <i class="fas fa-eye mr-1"></i>View
                    </a>
                    <a href="manage.php?id=<?php echo $driver->id; ?>" 
                       class="flex-1 text-center px-3 py-2 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">
                        <i class="fas fa-edit mr-1"></i>Edit
                    </a>
                    <a href="documents.php?driver_id=<?php echo $driver->id; ?>" 
                       class="flex-1 text-center px-3 py-2 border border-gray-300 rounded-lg text-sm hover:bg-gray-50"
                       title="Manage Documents">
                        <i class="fas fa-file-alt"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</div>

<?php require_once '../../templates/footer.php'; ?>
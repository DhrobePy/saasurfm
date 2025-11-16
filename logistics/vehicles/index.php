<?php
require_once '../../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Transport Manager'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Vehicles';

// Get filter parameters
$filter_type = $_GET['type'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';

// Build query
$where_conditions = [];
$params = [];

if ($filter_type !== 'all') {
    $where_conditions[] = "vehicle_type = ?";
    $params[] = $filter_type;
}

if ($filter_status !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $filter_status;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$vehicles = $db->query(
    "SELECT v.*, b.name as branch_name, d.driver_name
     FROM vehicles v
     LEFT JOIN branches b ON v.assigned_branch_id = b.id
     LEFT JOIN drivers d ON d.assigned_vehicle_id = v.id
     $where_clause
     ORDER BY v.vehicle_type, v.vehicle_number",
    $params
)->results();

require_once '../../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Header -->
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">🚚 Vehicles</h1>
        <p class="text-gray-600 mt-1">Manage your fleet - Own & Rented vehicles</p>
    </div>
    <a href="manage.php" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
        <i class="fas fa-plus mr-2"></i>Add Vehicle
    </a>
</div>

<!-- Filters -->
<div class="bg-white rounded-lg shadow-md p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle Type</label>
            <select name="type" class="px-4 py-2 border rounded-lg" onchange="this.form.submit()">
                <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                <option value="Own" <?php echo $filter_type === 'Own' ? 'selected' : ''; ?>>Own</option>
                <option value="Rented" <?php echo $filter_type === 'Rented' ? 'selected' : ''; ?>>Rented</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select name="status" class="px-4 py-2 border rounded-lg" onchange="this.form.submit()">
                <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                <option value="Active" <?php echo $filter_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                <option value="Inactive" <?php echo $filter_status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                <option value="Maintenance" <?php echo $filter_status === 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
            </select>
        </div>
        <?php if ($filter_type !== 'all' || $filter_status !== 'all'): ?>
        <div class="flex items-end">
            <a href="index.php" class="px-4 py-2 border rounded-lg hover:bg-gray-50">Clear Filters</a>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- Vehicle Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php if (empty($vehicles)): ?>
    <div class="col-span-full bg-white rounded-lg shadow-md p-12 text-center">
        <i class="fas fa-truck text-6xl text-gray-300 mb-4"></i>
        <p class="text-xl text-gray-500">No vehicles found</p>
        <a href="manage.php" class="inline-block mt-4 px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            Add Your First Vehicle
        </a>
    </div>
    <?php else: ?>
        <?php foreach ($vehicles as $vehicle): ?>
        <div class="bg-white rounded-lg shadow-md hover:shadow-lg transition-shadow">
            <div class="p-6">
                <!-- Header -->
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($vehicle->vehicle_number); ?></h3>
                    <span class="px-3 py-1 rounded-full text-xs font-semibold
                        <?php echo $vehicle->vehicle_type === 'Own' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800'; ?>">
                        <?php echo $vehicle->vehicle_type; ?>
                    </span>
                </div>
                
                <!-- Status Badge -->
                <div class="mb-4">
                    <?php
                    $status_colors = [
                        'Active' => 'bg-green-100 text-green-800',
                        'Inactive' => 'bg-gray-100 text-gray-800',
                        'Maintenance' => 'bg-yellow-100 text-yellow-800',
                        'Rented Out' => 'bg-orange-100 text-orange-800'
                    ];
                    $color = $status_colors[$vehicle->status] ?? 'bg-gray-100 text-gray-800';
                    ?>
                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full <?php echo $color; ?>">
                        <?php echo $vehicle->status; ?>
                    </span>
                </div>
                
                <!-- Details -->
                <div class="space-y-2 mb-4 text-sm">
                    <p class="text-gray-600">
                        <i class="fas fa-car text-gray-400 w-5"></i>
                        <span class="font-medium"><?php echo htmlspecialchars($vehicle->category); ?></span>
                        <?php if ($vehicle->model): ?>
                            - <?php echo htmlspecialchars($vehicle->model); ?>
                        <?php endif; ?>
                    </p>
                    
                    <p class="text-gray-600">
                        <i class="fas fa-weight text-gray-400 w-5"></i>
                        Capacity: <span class="font-medium"><?php echo number_format($vehicle->capacity_kg, 0); ?> kg</span>
                    </p>
                    
                    <p class="text-gray-600">
                        <i class="fas fa-tachometer-alt text-gray-400 w-5"></i>
                        Mileage: <span class="font-medium"><?php echo number_format($vehicle->current_mileage, 0); ?> km</span>
                    </p>
                    
                    <p class="text-gray-600">
                        <i class="fas fa-gas-pump text-gray-400 w-5"></i>
                        Fuel: <span class="font-medium"><?php echo $vehicle->fuel_type; ?></span>
                    </p>
                    
                    <?php if ($vehicle->driver_name): ?>
                    <p class="text-gray-600">
                        <i class="fas fa-user-tie text-gray-400 w-5"></i>
                        Driver: <span class="font-medium"><?php echo htmlspecialchars($vehicle->driver_name); ?></span>
                    </p>
                    <?php endif; ?>
                    
                    <?php if ($vehicle->branch_name): ?>
                    <p class="text-gray-600">
                        <i class="fas fa-building text-gray-400 w-5"></i>
                        Branch: <span class="font-medium"><?php echo htmlspecialchars($vehicle->branch_name); ?></span>
                    </p>
                    <?php endif; ?>
                </div>
                
                <!-- Actions -->
                <div class="flex gap-2 pt-4 border-t">
                    <a href="view.php?id=<?php echo $vehicle->id; ?>" 
                       class="flex-1 text-center px-3 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">
                        <i class="fas fa-eye mr-1"></i>View
                    </a>
                    <a href="manage.php?id=<?php echo $vehicle->id; ?>" 
                       class="flex-1 text-center px-3 py-2 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">
                        <i class="fas fa-edit mr-1"></i>Edit
                    </a>
                    <a href="documents/index.php?vehicle_id=<?php echo $vehicle->id; ?>" 
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
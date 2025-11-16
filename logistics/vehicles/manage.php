<?php
require_once '../../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Transport Manager'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Add Vehicle';
$edit_mode = false;
$vehicle = null;

// Check for edit mode
if (isset($_GET['id'])) {
    $edit_mode = true;
    $vehicle_id = (int)$_GET['id'];
    $vehicle = $db->query("SELECT * FROM vehicles WHERE id = ?", [$vehicle_id])->first();
    
    if ($vehicle) {
        $pageTitle = 'Edit Vehicle: ' . htmlspecialchars($vehicle->vehicle_number);
    } else {
        $_SESSION['error_flash'] = 'Vehicle not found.';
        header('Location: index.php');
        exit();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = [
            'vehicle_number' => trim($_POST['vehicle_number']),
            'vehicle_type' => $_POST['vehicle_type'],
            'category' => $_POST['category'],
            'model' => trim($_POST['model']) ?: null,
            'make' => trim($_POST['make']) ?: null,
            'year' => $_POST['year'] ?: null,
            'capacity_kg' => floatval($_POST['capacity_kg']),
            'fuel_type' => $_POST['fuel_type'],
            'status' => $_POST['status'],
            'ownership_status' => $_POST['ownership_status'],
            'assigned_branch_id' => $_POST['assigned_branch_id'] ?: null,
            'notes' => trim($_POST['notes']) ?: null
        ];
        
        // Rental-specific fields
        if ($data['vehicle_type'] === 'Rented') {
            $data['rental_rate_per_day'] = floatval($_POST['rental_rate_per_day'] ?? 0);
            $data['rental_start_date'] = $_POST['rental_start_date'] ?: null;
            $data['rental_end_date'] = $_POST['rental_end_date'] ?: null;
            $data['rental_vendor_name'] = trim($_POST['rental_vendor_name']) ?: null;
            $data['rental_vendor_phone'] = trim($_POST['rental_vendor_phone']) ?: null;
        }
        
        // Own vehicle fields
        if ($data['vehicle_type'] === 'Own') {
            $data['purchase_date'] = $_POST['purchase_date'] ?: null;
            $data['purchase_price'] = floatval($_POST['purchase_price'] ?? 0);
        }
        
        // Mileage and service
        $data['current_mileage'] = floatval($_POST['current_mileage'] ?? 0);
        $data['next_service_due_date'] = $_POST['next_service_due_date'] ?: null;
        $data['next_service_due_mileage'] = floatval($_POST['next_service_due_mileage'] ?? 0);
        
        if ($edit_mode) {
            $db->update('vehicles', $data, ['id' => $vehicle_id]);
            $_SESSION['success_flash'] = 'Vehicle updated successfully.';
        } else {
            $db->insert('vehicles', $data);
            $_SESSION['success_flash'] = 'Vehicle added successfully.';
        }
        
        header('Location: index.php');
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_flash'] = 'Error: ' . $e->getMessage();
    }
}

// Get branches for dropdown
$branches = $db->query("SELECT id, name FROM branches ORDER BY name")->results();

require_once '../../templates/header.php';
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="bg-white rounded-lg shadow-md p-6">
    <div class="flex justify-between items-center mb-6 border-b pb-4">
        <h1 class="text-2xl font-bold text-gray-900">
            <?php echo $edit_mode ? 'Edit Vehicle' : 'Add New Vehicle'; ?>
        </h1>
        <a href="index.php" class="px-4 py-2 border rounded-lg hover:bg-gray-50">
            <i class="fas fa-arrow-left mr-2"></i>Back
        </a>
    </div>

```
<form method="POST" class="space-y-6" x-data="{ vehicleType: '<?php echo $vehicle->vehicle_type ?? 'Own'; ?>' }">
    
    <!-- Basic Information -->
    <div>
        <h3 class="text-lg font-medium text-gray-900 mb-4">Basic Information</h3>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Vehicle Number * <span class="text-xs text-gray-500">(e.g., DHA-GA-1234)</span>
                </label>
                <input type="text" name="vehicle_number" required
                       class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo htmlspecialchars($vehicle->vehicle_number ?? ''); ?>"
                       placeholder="DHA-GA-1234">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle Type *</label>
                <select name="vehicle_type" required class="w-full px-4 py-2 border rounded-lg" x-model="vehicleType">
                    <option value="Own" <?php echo ($vehicle->vehicle_type ?? 'Own') === 'Own' ? 'selected' : ''; ?>>Own</option>
                    <option value="Rented" <?php echo ($vehicle->vehicle_type ?? '') === 'Rented' ? 'selected' : ''; ?>>Rented</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Category *</label>
                <select name="category" required class="w-full px-4 py-2 border rounded-lg">
                    <option value="Truck" <?php echo ($vehicle->category ?? 'Truck') === 'Truck' ? 'selected' : ''; ?>>Truck</option>
                    <option value="Van" <?php echo ($vehicle->category ?? '') === 'Van' ? 'selected' : ''; ?>>Van</option>
                    <option value="Pickup" <?php echo ($vehicle->category ?? '') === 'Pickup' ? 'selected' : ''; ?>>Pickup</option>
                    <option value="Motorcycle" <?php echo ($vehicle->category ?? '') === 'Motorcycle' ? 'selected' : ''; ?>>Motorcycle</option>
                    <option value="Other" <?php echo ($vehicle->category ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status *</label>
                <select name="status" required class="w-full px-4 py-2 border rounded-lg">
                    <option value="Active" <?php echo ($vehicle->status ?? 'Active') === 'Active' ? 'selected' : ''; ?>>Active</option>
                    <option value="Inactive" <?php echo ($vehicle->status ?? '') === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    <option value="Maintenance" <?php echo ($vehicle->status ?? '') === 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Make</label>
                <input type="text" name="make" class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo htmlspecialchars($vehicle->make ?? ''); ?>"
                       placeholder="e.g., Tata, Ashok Leyland">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Model</label>
                <input type="text" name="model" class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo htmlspecialchars($vehicle->model ?? ''); ?>"
                       placeholder="e.g., LPT 1109">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Year</label>
                <input type="number" name="year" min="1990" max="<?php echo date('Y') + 1; ?>"
                       class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo $vehicle->year ?? ''; ?>"
                       placeholder="<?php echo date('Y'); ?>">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Capacity (kg) *</label>
                <input type="number" step="0.01" name="capacity_kg" required
                       class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo $vehicle->capacity_kg ?? ''; ?>"
                       placeholder="5000">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Fuel Type *</label>
                <select name="fuel_type" required class="w-full px-4 py-2 border rounded-lg">
                    <option value="Diesel" <?php echo ($vehicle->fuel_type ?? 'Diesel') === 'Diesel' ? 'selected' : ''; ?>>Diesel</option>
                    <option value="Petrol" <?php echo ($vehicle->fuel_type ?? '') === 'Petrol' ? 'selected' : ''; ?>>Petrol</option>
                    <option value="CNG" <?php echo ($vehicle->fuel_type ?? '') === 'CNG' ? 'selected' : ''; ?>>CNG</option>
                    <option value="Electric" <?php echo ($vehicle->fuel_type ?? '') === 'Electric' ? 'selected' : ''; ?>>Electric</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Ownership Status *</label>
                <select name="ownership_status" required class="w-full px-4 py-2 border rounded-lg">
                    <option value="Owned" <?php echo ($vehicle->ownership_status ?? 'Owned') === 'Owned' ? 'selected' : ''; ?>>Owned</option>
                    <option value="Leased" <?php echo ($vehicle->ownership_status ?? '') === 'Leased' ? 'selected' : ''; ?>>Leased</option>
                    <option value="Rented" <?php echo ($vehicle->ownership_status ?? '') === 'Rented' ? 'selected' : ''; ?>>Rented</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Assigned Branch</label>
                <select name="assigned_branch_id" class="w-full px-4 py-2 border rounded-lg">
                    <option value="">-- Not Assigned --</option>
                    <?php foreach ($branches as $branch): ?>
                    <option value="<?php echo $branch->id; ?>" 
                        <?php echo ($vehicle->assigned_branch_id ?? '') == $branch->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($branch->name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    
    <!-- Own Vehicle Fields -->
    <div x-show="vehicleType === 'Own'" x-transition>
        <h3 class="text-lg font-medium text-gray-900 mb-4">Purchase Information</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Purchase Date</label>
                <input type="date" name="purchase_date" class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo $vehicle->purchase_date ?? ''; ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Purchase Price (BDT)</label>
                <input type="number" step="0.01" name="purchase_price" class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo $vehicle->purchase_price ?? ''; ?>"
                       placeholder="0.00">
            </div>
        </div>
    </div>
    
    <!-- Rented Vehicle Fields -->
    <div x-show="vehicleType === 'Rented'" x-transition>
        <h3 class="text-lg font-medium text-gray-900 mb-4">Rental Information</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Rental Rate (per day)</label>
                <input type="number" step="0.01" name="rental_rate_per_day" class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo $vehicle->rental_rate_per_day ?? ''; ?>"
                       placeholder="2000.00">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Rental Start Date</label>
                <input type="date" name="rental_start_date" class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo $vehicle->rental_start_date ?? ''; ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Rental End Date</label>
                <input type="date" name="rental_end_date" class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo $vehicle->rental_end_date ?? ''; ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Vendor Name</label>
                <input type="text" name="rental_vendor_name" class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo htmlspecialchars($vehicle->rental_vendor_name ?? ''); ?>"
                       placeholder="Transport Company XYZ">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Vendor Phone</label>
                <input type="tel" name="rental_vendor_phone" class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo htmlspecialchars($vehicle->rental_vendor_phone ?? ''); ?>"
                       placeholder="01700000000">
            </div>
        </div>
    </div>
    
    <!-- Mileage & Service -->
    <div>
        <h3 class="text-lg font-medium text-gray-900 mb-4">Mileage & Service</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Current Mileage (km)</label>
                <input type="number" step="0.01" name="current_mileage" class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo $vehicle->current_mileage ?? 0; ?>"
                       placeholder="0">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Next Service Due Date</label>
                <input type="date" name="next_service_due_date" class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo $vehicle->next_service_due_date ?? ''; ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Next Service Due Mileage (km)</label>
                <input type="number" step="0.01" name="next_service_due_mileage" class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo $vehicle->next_service_due_mileage ?? ''; ?>"
                       placeholder="10000">
            </div>
        </div>
    </div>
    
    <!-- Notes -->
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
        <textarea name="notes" rows="3" class="w-full px-4 py-2 border rounded-lg"
                  placeholder="Any additional information about this vehicle..."><?php echo htmlspecialchars($vehicle->notes ?? ''); ?></textarea>
    </div>
    
    <!-- Submit -->
    <div class="flex justify-end gap-3 pt-6 border-t">
        <a href="index.php" class="px-6 py-2 border rounded-lg hover:bg-gray-50">
            Cancel
        </a>
        <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            <i class="fas fa-save mr-2"></i>
            <?php echo $edit_mode ? 'Update Vehicle' : 'Add Vehicle'; ?>
        </button>
    </div>
</form>
```

</div>

</div>

<?php require_once '../../templates/footer.php'; ?>
<?php
require_once '../../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Transport Manager'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Add Driver';
$edit_mode = false;
$driver = null;

if (isset($_GET['id'])) {
    $edit_mode = true;
    $driver_id = (int)$_GET['id'];
    $driver = $db->query("SELECT * FROM drivers WHERE id = ?", [$driver_id])->first();
    
    if ($driver) {
        $pageTitle = 'Edit Driver: ' . htmlspecialchars($driver->driver_name);
    } else {
        $_SESSION['error_flash'] = 'Driver not found.';
        header('Location: index.php');
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = [
            'driver_name' => trim($_POST['driver_name']),
            'phone_number' => trim($_POST['phone_number']),
            'email' => trim($_POST['email']) ?: null,
            'driver_type' => $_POST['driver_type'],
            'license_number' => trim($_POST['license_number']),
            'license_type' => trim($_POST['license_type']) ?: null,
            'license_issue_date' => $_POST['license_issue_date'] ?: null,
            'license_expiry_date' => $_POST['license_expiry_date'] ?: null,
            'nid_number' => trim($_POST['nid_number']) ?: null,
            'address' => trim($_POST['address']) ?: null,
            'emergency_contact_name' => trim($_POST['emergency_contact_name']) ?: null,
            'emergency_contact_phone' => trim($_POST['emergency_contact_phone']) ?: null,
            'date_of_birth' => $_POST['date_of_birth'] ?: null,
            'join_date' => $_POST['join_date'] ?: null,
            'status' => $_POST['status'],
            'assigned_branch_id' => $_POST['assigned_branch_id'] ?: null,
            'notes' => trim($_POST['notes']) ?: null
        ];
        
        if ($data['driver_type'] === 'Permanent') {
            $data['salary'] = floatval($_POST['salary'] ?? 0);
        } else {
            $data['daily_rate'] = floatval($_POST['daily_rate'] ?? 0);
        }
        
        if ($edit_mode) {
            $db->update('drivers', $data, ['id' => $driver_id]);
            $_SESSION['success_flash'] = 'Driver updated successfully.';
        } else {
            $db->insert('drivers', $data);
            $_SESSION['success_flash'] = 'Driver added successfully.';
        }
        
        header('Location: index.php');
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_flash'] = 'Error: ' . $e->getMessage();
    }
}

$branches = $db->query("SELECT id, name FROM branches ORDER BY name")->results();

require_once '../../templates/header.php';
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="bg-white rounded-lg shadow-md p-6">
    <div class="flex justify-between items-center mb-6 border-b pb-4">
        <h1 class="text-2xl font-bold text-gray-900">
            <?php echo $edit_mode ? 'Edit Driver' : 'Add New Driver'; ?>
        </h1>
        <a href="index.php" class="px-4 py-2 border rounded-lg hover:bg-gray-50">
            <i class="fas fa-arrow-left mr-2"></i>Back
        </a>
    </div>

    <form method="POST" class="space-y-6" x-data="{ driverType: '<?php echo $driver->driver_type ?? 'Permanent'; ?>' }">
        
        <!-- Basic Information -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 mb-4">Basic Information</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                    <input type="text" name="driver_name" required
                           class="w-full px-4 py-2 border rounded-lg"
                           value="<?php echo htmlspecialchars($driver->driver_name ?? ''); ?>"
                           placeholder="e.g., Karim Ahmed">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone Number *</label>
                    <input type="tel" name="phone_number" required
                           class="w-full px-4 py-2 border rounded-lg"
                           value="<?php echo htmlspecialchars($driver->phone_number ?? ''); ?>"
                           placeholder="01700000000">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email"
                           class="w-full px-4 py-2 border rounded-lg"
                           value="<?php echo htmlspecialchars($driver->email ?? ''); ?>"
                           placeholder="driver@example.com">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Driver Type *</label>
                    <select name="driver_type" required class="w-full px-4 py-2 border rounded-lg" x-model="driverType">
                        <option value="Permanent" <?php echo ($driver->driver_type ?? 'Permanent') === 'Permanent' ? 'selected' : ''; ?>>Permanent</option>
                        <option value="Temporary" <?php echo ($driver->driver_type ?? '') === 'Temporary' ? 'selected' : ''; ?>>Temporary</option>
                        <option value="Rental" <?php echo ($driver->driver_type ?? '') === 'Rental' ? 'selected' : ''; ?>>Rental (with vehicle)</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status *</label>
                    <select name="status" required class="w-full px-4 py-2 border rounded-lg">
                        <option value="Active" <?php echo ($driver->status ?? 'Active') === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo ($driver->status ?? '') === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="On Leave" <?php echo ($driver->status ?? '') === 'On Leave' ? 'selected' : ''; ?>>On Leave</option>
                        <option value="Terminated" <?php echo ($driver->status ?? '') === 'Terminated' ? 'selected' : ''; ?>>Terminated</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date of Birth</label>
                    <input type="date" name="date_of_birth"
                           class="w-full px-4 py-2 border rounded-lg"
                           value="<?php echo $driver->date_of_birth ?? ''; ?>">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Join Date</label>
                    <input type="date" name="join_date"
                           class="w-full px-4 py-2 border rounded-lg"
                           value="<?php echo $driver->join_date ?? date('Y-m-d'); ?>">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">NID Number</label>
                    <input type="text" name="nid_number"
                           class="w-full px-4 py-2 border rounded-lg"
                           value="<?php echo htmlspecialchars($driver->nid_number ?? ''); ?>"
                           placeholder="1234567890123">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Assigned Branch</label>
                    <select name="assigned_branch_id" class="w-full px-4 py-2 border rounded-lg">
                        <option value="">-- Not Assigned --</option>
                        <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo $branch->id; ?>" 
                            <?php echo ($driver->assigned_branch_id ?? '') == $branch->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($branch->name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- License Information -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 mb-4">License Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">License Number *</label>
                    <input type="text" name="license_number" required
                           class="w-full px-4 py-2 border rounded-lg"
                           value="<?php echo htmlspecialchars($driver->license_number ?? ''); ?>"
                           placeholder="DL-12345">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">License Type</label>
                    <input type="text" name="license_type"
                           class="w-full px-4 py-2 border rounded-lg"
                           value="<?php echo htmlspecialchars($driver->license_type ?? ''); ?>"
                           placeholder="Professional, Heavy, Light">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">License Issue Date</label>
                    <input type="date" name="license_issue_date"
                           class="w-full px-4 py-2 border rounded-lg"
                           value="<?php echo $driver->license_issue_date ?? ''; ?>">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">License Expiry Date</label>
                    <input type="date" name="license_expiry_date"
                           class="w-full px-4 py-2 border rounded-lg"
                           value="<?php echo $driver->license_expiry_date ?? ''; ?>">
                </div>
            </div>
        </div>
        
        <!-- Compensation -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 mb-4">Compensation</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div x-show="driverType === 'Permanent'">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Monthly Salary (BDT)</label>
                    <input type="number" step="0.01" name="salary"
                           class="w-full px-4 py-2 border rounded-lg"
                           value="<?php echo $driver->salary ?? ''; ?>"
                           placeholder="25000">
                </div>
                
                <div x-show="driverType !== 'Permanent'">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Daily Rate (BDT)</label>
                    <input type="number" step="0.01" name="daily_rate"
                           class="w-full px-4 py-2 border rounded-lg"
                           value="<?php echo $driver->daily_rate ?? ''; ?>"
                           placeholder="1000">
                </div>
            </div>
        </div>
        
        <!-- Emergency Contact -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 mb-4">Emergency Contact</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contact Name</label>
                    <input type="text" name="emergency_contact_name"
                           class="w-full px-4 py-2 border rounded-lg"
                           value="<?php echo htmlspecialchars($driver->emergency_contact_name ?? ''); ?>"
                           placeholder="e.g., Wife, Brother">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contact Phone</label>
                    <input type="tel" name="emergency_contact_phone"
                           class="w-full px-4 py-2 border rounded-lg"
                           value="<?php echo htmlspecialchars($driver->emergency_contact_phone ?? ''); ?>"
                           placeholder="01700000000">
                </div>
            </div>
        </div>
        
        <!-- Address & Notes -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
            <textarea name="address" rows="2" class="w-full px-4 py-2 border rounded-lg"
                      placeholder="Full address..."><?php echo htmlspecialchars($driver->address ?? ''); ?></textarea>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
            <textarea name="notes" rows="3" class="w-full px-4 py-2 border rounded-lg"
                      placeholder="Any additional information..."><?php echo htmlspecialchars($driver->notes ?? ''); ?></textarea>
        </div>
        
        <!-- Submit -->
        <div class="flex justify-end gap-3 pt-6 border-t">
            <a href="index.php" class="px-6 py-2 border rounded-lg hover:bg-gray-50">
                Cancel
            </a>
            <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                <i class="fas fa-save mr-2"></i>
                <?php echo $edit_mode ? 'Update Driver' : 'Add Driver'; ?>
            </button>
        </div>
    </form>
</div>

</div>

<?php require_once '../../templates/footer.php'; ?>
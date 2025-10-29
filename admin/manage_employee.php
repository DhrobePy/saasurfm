<?php
require_once '../core/init.php';

// --- SECURITY ---
// Only Superadmin and admin can manage employee records
$allowed_roles = ['Superadmin', 'admin'];
restrict_access($allowed_roles);

// Get the $db instance
global $db;
$pageTitle = 'Manage Employee';

// --- VARIABLE INITIALIZATION ---
$edit_mode = false;
$employee_to_edit = null;
$form_action = 'add_employee';
$error = null;
$departments = [];
$positions = [];
$branches = [];

// --- DATA: GET DEPARTMENTS, POSITIONS, BRANCHES FOR DROPDOWNS ---
try {
    $departments = $db->query("SELECT id, name FROM departments ORDER BY name ASC")->results();
    $positions = $db->query("SELECT id, title, department_id FROM positions ORDER BY title ASC")->results();
    $branches = $db->query("SELECT id, name FROM branches ORDER BY name ASC")->results();
} catch (Exception $e) {
    $error = "Could not load required data: " . $e->getMessage();
}

// --- LOGIC: HANDLE GET REQUEST (EDIT) ---
if (isset($_GET['edit']) && !$error) {
    $edit_id = (int)$_GET['edit']; // Use ID now
    $employee_to_edit = $db->query("SELECT * FROM employees WHERE id = ?", [$edit_id])->first(); // Use ID now

    if ($employee_to_edit) {
        $edit_mode = true;
        $form_action = 'update_employee';
        $pageTitle = 'Edit Employee';
    } else {
         $_SESSION['error_flash'] = 'Employee not found.';
         header('Location: employees.php');
         exit();
    }
}

// --- LOGIC: HANDLE POST REQUESTS (ADD & UPDATE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $pdo = $db->getPdo(); // Assuming getPdo() method exists
    try {
        $pdo->beginTransaction();

        // *** RECTIFIED: Removed department_id from fields array ***
        $fields = [
            'first_name' => trim($_POST['first_name']),
            'last_name' => trim($_POST['last_name']),
            'email' => trim($_POST['email']),
            'phone' => trim($_POST['phone']),
            'address' => trim($_POST['address']),
            // 'department_id' => !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null, // REMOVED THIS LINE
            'position_id' => !empty($_POST['position_id']) ? (int)$_POST['position_id'] : null,
            'branch_id' => !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null,
            'hire_date' => !empty($_POST['hire_date']) ? $_POST['hire_date'] : null,
            'base_salary' => !empty($_POST['base_salary']) ? (float)$_POST['base_salary'] : 0.00,
            'status' => $_POST['status']
        ];

        // Basic validation
        if (empty($fields['first_name']) || empty($fields['last_name']) || empty($fields['email'])) {
             throw new Exception("First Name, Last Name, and Email are required.");
        }
        if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format.");
        }
        // Add more validation as needed (e.g., phone format, salary range)


        // --- ADD NEW EMPLOYEE ---
        if (isset($_POST['add_employee'])) {
             // Check if email already exists
            $existing = $db->query("SELECT id FROM employees WHERE email = ?", [$fields['email']])->first();
            if ($existing) {
                throw new Exception("An employee with this email already exists.");
            }

            $employee_id = $db->insert('employees', $fields);
            if (!$employee_id) {
                 throw new Exception("Failed to create employee record.");
            }
            $_SESSION['success_flash'] = 'Employee successfully created.';
        }

        // --- UPDATE EXISTING EMPLOYEE ---
        elseif (isset($_POST['update_employee'])) {
            $employee_id = (int)$_POST['employee_id']; // Use ID now
            $current_employee = $db->query("SELECT id, email FROM employees WHERE id = ?", [$employee_id])->first(); // Use ID now
            if (!$current_employee) {
                throw new Exception("Employee not found for update.");
            }
            // ID remains the same $employee_id

            // Check if email already exists (for another employee)
            if ($fields['email'] !== $current_employee->email) {
                $existing = $db->query("SELECT id FROM employees WHERE email = ? AND id != ?", [$fields['email'], $employee_id])->first();
                if ($existing) {
                    throw new Exception("Another employee with this email already exists.");
                }
            }

            $updated = $db->update('employees', $employee_id, $fields);
             if (!$updated && $db->error()) { // Check for actual DB error on update
                 throw new Exception("Failed to update employee record. DB Error: " . implode(' ', $db->errorInfo()));
             }
             $_SESSION['success_flash'] = 'Employee successfully updated.';
        }

        $pdo->commit();
        header('Location: employees.php'); // Redirect to list page on success
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage(); // Set error message to display on the form
    }
}


// --- LOGIC: HANDLE GET REQUEST (DELETE) ---
if (isset($_GET['delete']) && !$error) {
     if ($_SESSION['user_role'] !== 'Superadmin' && $_SESSION['user_role'] !== 'admin') {
         $_SESSION['error_flash'] = 'You do not have permission to delete employees.';
         header('Location: employees.php');
         exit();
     }
    $delete_id = (int)$_GET['delete']; // Use ID now
    $employee_to_delete = $db->query("SELECT id FROM employees WHERE id = ?", [$delete_id])->first(); // Use ID now

    if ($employee_to_delete) {
        $pdo = $db->getPdo();
        try {
            $pdo->beginTransaction();

            $deleted = $db->delete('employees', ['id', '=', $employee_to_delete->id]);
            if (!$deleted || $db->error()) {
                throw new Exception("Failed to delete employee." . ($db->error() ? ' DB Error: ' . implode(' ', $db->errorInfo()) : ''));
            }

            $pdo->commit();
            $_SESSION['success_flash'] = 'Employee successfully deleted.';
            header('Location: employees.php');
            exit();

        } catch (Exception $e) {
             if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error_flash'] = $e->getMessage();
             header('Location: employees.php'); // Redirect back to list on error
             exit();
        }
    } else {
        $_SESSION['error_flash'] = 'Employee not found for deletion.';
        header('Location: employees.php');
        exit();
    }
}


// --- Include Header ---
require_once '../templates/header.php';
?>

<!-- ======================================== -->
<!-- 1. PAGE HEADER -->
<!-- ======================================== -->
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900"><?php echo htmlspecialchars($pageTitle); ?></h1>
        <p class="text-lg text-gray-600 mt-1">
            <?php echo $edit_mode ? 'Update the details for this employee.' : 'Enter the details for the new employee.'; ?>
        </p>
    </div>
    <a href="employees.php"
       class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
        <i class="fas fa-arrow-left mr-2"></i>Back to Employee List
    </a>
</div>

<!-- ======================================== -->
<!-- 2. ERROR DISPLAY -->
<!-- ======================================== -->
<?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg" role="alert">
        <p class="font-bold">Error</p>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>
<?php endif; ?>

<!-- ======================================== -->
<!-- 3. ADD / EDIT EMPLOYEE FORM -->
<!-- ======================================== -->
<div class="bg-white rounded-lg shadow-md p-6">

    <form action="manage_employee.php<?php echo $edit_mode ? '?edit=' . $employee_to_edit->id : ''; ?>" method="POST" class="space-y-6">

        <!-- Hidden fields -->
        <input type="hidden" name="<?php echo $form_action; ?>" value="1">
        <?php if ($edit_mode): ?>
            <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars($employee_to_edit->id); ?>">
        <?php endif; ?>

        <!-- Section 1: Basic Information -->
        <div>
            <h2 class="text-lg font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-200">
                <i class="fas fa-user text-primary-600 mr-2"></i>Basic Information
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- First Name -->
                <div>
                    <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1">First Name <span class="text-red-500">*</span></label>
                    <input type="text" id="first_name" name="first_name" required maxlength="100"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                           value="<?php echo htmlspecialchars($employee_to_edit->first_name ?? ''); ?>">
                </div>
                <!-- Last Name -->
                <div>
                    <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1">Last Name <span class="text-red-500">*</span></label>
                    <input type="text" id="last_name" name="last_name" required maxlength="100"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                           value="<?php echo htmlspecialchars($employee_to_edit->last_name ?? ''); ?>">
                </div>
            </div>
        </div>

         <!-- Section 2: Contact Information -->
        <div>
            <h2 class="text-lg font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-200">
                <i class="fas fa-address-book text-primary-600 mr-2"></i>Contact Information
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Email -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address <span class="text-red-500">*</span></label>
                    <input type="email" id="email" name="email" required maxlength="100"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                           value="<?php echo htmlspecialchars($employee_to_edit->email ?? ''); ?>">
                </div>
                <!-- Phone -->
                <div>
                    <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone Number <span class="text-gray-400">(Optional)</span></label>
                    <input type="tel" id="phone" name="phone" maxlength="20"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                           value="<?php echo htmlspecialchars($employee_to_edit->phone ?? ''); ?>">
                </div>
            </div>
             <!-- Address -->
            <div class="mt-4">
                <label for="address" class="block text-sm font-medium text-gray-700 mb-1">Address <span class="text-gray-400">(Optional)</span></label>
                <textarea id="address" name="address" rows="3"
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                          placeholder="Street, City, Postal Code"><?php echo htmlspecialchars($employee_to_edit->address ?? ''); ?></textarea>
            </div>
        </div>

        <!-- Section 3: Job Information -->
        <div>
            <h2 class="text-lg font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-200">
                <i class="fas fa-briefcase text-primary-600 mr-2"></i>Job Information
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                 <!-- Department (Readonly, determined by Position) -->
                 <div>
                    <label for="department_id" class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                    <!-- *** RECTIFIED: Removed name attribute, added x-data for dynamic update *** -->
                    <select id="department_id" x-data="{ selectedDept: '<?php echo $employee_to_edit->department_id ?? ''; ?>' }" x-init="$watch('selectedPos', value => {
                        const posEl = document.querySelector(`#position_id option[value='${value}']`);
                        selectedDept = posEl ? posEl.dataset.dept : '';
                      })"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors cursor-not-allowed"
                       x-model="selectedDept" disabled>
                        <option value="">-- Determined by Position --</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept->id; ?>">
                                <?php echo htmlspecialchars($dept->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <!-- Hidden input to still submit department_id if needed, or remove if not needed -->
                     <input type="hidden" name="department_id" x-bind:value="selectedDept">
                </div>
                 <!-- Position -->
                 <div x-data="{ selectedPos: '<?php echo $employee_to_edit->position_id ?? ''; ?>' }">
                    <label for="position_id" class="block text-sm font-medium text-gray-700 mb-1">Position / Title</label>
                    <select id="position_id" name="position_id" x-model="selectedPos"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors">
                        <option value="">-- Select Position --</option>
                         <?php foreach ($positions as $pos): ?>
                            <option value="<?php echo $pos->id; ?>" data-dept="<?php echo $pos->department_id; ?>">
                                <?php echo htmlspecialchars($pos->title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                 </div>
                <!-- Branch -->
                <div>
                    <label for="branch_id" class="block text-sm font-medium text-gray-700 mb-1">Branch / Location</label>
                    <select id="branch_id" name="branch_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors">
                        <option value="">-- Select Branch --</option>
                         <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo $branch->id; ?>" <?php echo (isset($employee_to_edit->branch_id) && $employee_to_edit->branch_id == $branch->id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($branch->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-4">
                 <!-- Hire Date -->
                 <div>
                    <label for="hire_date" class="block text-sm font-medium text-gray-700 mb-1">Hire Date</label>
                    <input type="date" id="hire_date" name="hire_date"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                           value="<?php echo htmlspecialchars($employee_to_edit->hire_date ?? ''); ?>">
                </div>
                 <!-- Base Salary -->
                 <div>
                    <label for="base_salary" class="block text-sm font-medium text-gray-700 mb-1">Base Salary (Monthly, BDT)</label>
                    <input type="number" step="0.01" id="base_salary" name="base_salary" min="0"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                           value="<?php echo htmlspecialchars($employee_to_edit->base_salary ?? '0.00'); ?>">
                </div>
                 <!-- Status -->
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Employment Status</label>
                    <select id="status" name="status" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors">
                        <option value="active" <?php echo ($employee_to_edit->status ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="on_leave" <?php echo ($employee_to_edit->status ?? '') === 'on_leave' ? 'selected' : ''; ?>>On Leave</option>
                        <option value="terminated" <?php echo ($employee_to_edit->status ?? '') === 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                    </select>
                </div>
            </div>
        </div>


        <!-- Submit Button -->
        <div class="pt-6 border-t border-gray-200 flex justify-end space-x-3">
            <a href="employees.php"
               class="px-5 py-2.5 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                Cancel
            </a>
            <button type="submit"
                    class="px-5 py-2.5 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <?php if ($edit_mode): ?>
                    <i class="fas fa-save mr-2"></i>Update Employee
                <?php else: ?>
                    <i class="fas fa-user-plus mr-2"></i>Add Employee
                <?php endif; ?>
            </button>
        </div>
    </form>
</div>

<?php
// --- Include Footer ---
require_once '../templates/footer.php';
?>

<script>
// Simple Alpine.js integration to update department when position changes
document.addEventListener('alpine:init', () => {
    // You might need more complex logic if Alpine isn't already globally available
    // This assumes the x-data on the position div correctly sets up `selectedPos`
    // and the x-data on the department div correctly sets up `selectedDept`
});
</script>


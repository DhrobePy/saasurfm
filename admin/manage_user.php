<?php
require_once '../core/init.php';

// --- SECURITY ---
// Only Superadmin and admin can manage users
$allowed_roles = ['Superadmin', 'admin'];
restrict_access($allowed_roles);

// Get the $db instance
global $db;
$pageTitle = 'Manage User';

// --- VARIABLE INITIALIZATION ---
$edit_mode = false;
$user_to_edit = null;
$form_action = 'add_user';
$error = null;
$success = null;
$employees_without_user = []; // For dropdown
$all_roles = []; // For roles dropdown

// --- DATA: GET ROLES ---
// Fetch roles from the ENUM definition in the 'users' table
try {
    // This is a common way to get ENUM values, might need adjustment based on SQL version
    $stmt = $db->getPdo()->query("SHOW COLUMNS FROM users LIKE 'role'");
    $enum_def = $stmt->fetch(PDO::FETCH_ASSOC)['Type'];
    preg_match_all("/'([^']+)'/", $enum_def, $matches);
    $all_roles = $matches[1];
} catch (Exception $e) {
    $error = "Could not load user roles: " . $e->getMessage();
    // Use a default list if fetching fails (ensure this matches your table)
    $all_roles = [
        'Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg',
        'accounts-demra', 'accountspos-demra', 'accountspos-srg',
        'production manager-srg', 'production manager-demra',
        'dispatch-srg', 'dispatch-demra', 'dispatchpos-demra', 'dispatchpos-srg',
        'sales-srg', 'sales-demra', 'sales-other', 'collector'
    ];
}

// --- DATA: GET USER TO EDIT (needed before fetching employees) ---
if (isset($_GET['edit'])) {
    $edit_uuid = $_GET['edit'];
    $user_to_edit = $db->query(
        "SELECT u.*, e.id as linked_employee_id
         FROM users u
         LEFT JOIN employees e ON u.id = e.user_id
         WHERE u.uuid = ?",
         [$edit_uuid]
    )->first();

    if ($user_to_edit) {
        $edit_mode = true;
        $form_action = 'update_user';
        $pageTitle = 'Edit User';
    } else {
         $_SESSION['error_flash'] = 'User not found.';
         header('Location: users.php');
         exit();
    }
}

// --- DATA: GET EMPLOYEES WITHOUT USER ACCOUNTS ---
try {
    // Fetch employees who don't already have a user_id linked,
    // OR the employee linked to the current user being edited
    $edit_user_id = $edit_mode ? $user_to_edit->id : null;

    $employee_query = "SELECT id, CONCAT(first_name, ' ', last_name, ' (ID: ', id, ')') as full_name
                       FROM employees
                       WHERE user_id IS NULL";
    $params = [];
    if ($edit_mode && isset($user_to_edit->linked_employee_id)) {
         // Include the currently linked employee in the list for editing
         $employee_query .= " OR id = ?";
         $params[] = $user_to_edit->linked_employee_id;
    }
     $employee_query .= " ORDER BY first_name, last_name";

    $employees_without_user = $db->query($employee_query, $params)->results();

} catch (Exception $e) {
    $error = "Could not load employee list: " . $e->getMessage();
}


// --- LOGIC: HANDLE POST REQUESTS (ADD & UPDATE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $pdo = $db->getPdo();
    try {
        $pdo->beginTransaction();

        // --- ADD NEW USER ---
        if (isset($_POST['add_user'])) {
            $display_name = trim($_POST['display_name']);
            $email = trim($_POST['email']);
            $password = $_POST['password'];
            $role = $_POST['role'];
            $status = $_POST['status'];
            $linked_employee_id = !empty($_POST['linked_employee_id']) ? (int)$_POST['linked_employee_id'] : null;

            // Basic validation
            if (empty($display_name) || empty($email) || empty($password) || empty($role) || empty($status)) {
                throw new Exception("All fields except Linked Employee are required.");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format.");
            }
            if (!in_array($role, $all_roles)) {
                 throw new Exception("Invalid role selected.");
            }
            if (strlen($password) < 8) {
                throw new Exception("Password must be at least 8 characters long.");
            }

            // Check if email already exists
            $existing = $db->query("SELECT id FROM users WHERE email = ?", [$email])->first();
            if ($existing) {
                throw new Exception("A user with this email already exists.");
            }
             // Check if selected employee is already linked
            if ($linked_employee_id) {
                $emp_check = $db->query("SELECT user_id FROM employees WHERE id = ?", [$linked_employee_id])->first();
                if ($emp_check && $emp_check->user_id !== null) {
                    throw new Exception("This employee is already linked to another user account.");
                }
            }


            // Hash the password securely
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            if ($password_hash === false) {
                 throw new Exception("Failed to hash password.");
            }

            // Insert user
            $user_id = $db->insert('users', [
                'display_name' => $display_name,
                'email' => $email,
                'password_hash' => $password_hash,
                'role' => $role,
                'status' => $status
            ]);

            if (!$user_id) {
                throw new Exception("Failed to create user account.");
            }

            // Link employee if selected
            if ($linked_employee_id) {
                $db->update('employees', $linked_employee_id, ['user_id' => $user_id]);
            }

            $pdo->commit();
            $_SESSION['success_flash'] = 'User successfully created.';
            header('Location: users.php');
            exit();
        }

        // --- UPDATE EXISTING USER ---
        if (isset($_POST['update_user'])) {
            $user_uuid = $_POST['user_uuid'];
            $display_name = trim($_POST['display_name']);
            $email = trim($_POST['email']);
            $password = $_POST['password']; // Password change is optional
            $role = $_POST['role'];
            $status = $_POST['status'];
            $linked_employee_id = !empty($_POST['linked_employee_id']) ? (int)$_POST['linked_employee_id'] : null;

            // Fetch current user data (we already fetched it for GET, re-fetch inside transaction just in case)
            $current_user = $db->query("SELECT * FROM users WHERE uuid = ?", [$user_uuid])->first();
            if (!$current_user) {
                throw new Exception("User not found.");
            }
            $user_id = $current_user->id;

             // Basic validation
            if (empty($display_name) || empty($email) || empty($role) || empty($status)) {
                throw new Exception("Display Name, Email, Role, and Status are required.");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format.");
            }
             if (!in_array($role, $all_roles)) {
                 throw new Exception("Invalid role selected.");
            }
            // Cannot change Superadmin role if it's the only one
             if ($current_user->role == 'Superadmin' && $role != 'Superadmin') {
                $superadmin_count = $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'Superadmin'")->first()->count;
                if ($superadmin_count <= 1) {
                     throw new Exception("Cannot change the role of the only Superadmin.");
                }
            }


            // Check if email already exists (for another user)
            $existing = $db->query("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $user_id])->first();
            if ($existing) {
                throw new Exception("Another user with this email already exists.");
            }

            // Prepare fields for update
            $update_fields = [
                'display_name' => $display_name,
                'email' => $email,
                'role' => $role,
                'status' => $status
            ];

            // Update password only if a new one is provided
            if (!empty($password)) {
                 if (strlen($password) < 8) {
                    throw new Exception("New password must be at least 8 characters long.");
                }
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                if ($password_hash === false) {
                     throw new Exception("Failed to hash new password.");
                }
                $update_fields['password_hash'] = $password_hash;
            }

            // Update user
            $updated = $db->update('users', $user_id, $update_fields);

            // --- Handle Employee Linking ---
            // Find current linked employee for this user (using the $user_to_edit from GET)
            $current_linked_employee_id = $user_to_edit->linked_employee_id ?? null;

            if ($linked_employee_id != $current_linked_employee_id) {
                // Linkage has changed

                // Unlink the OLD employee (if one was linked)
                if ($current_linked_employee_id) {
                    $db->update('employees', $current_linked_employee_id, ['user_id' => null]);
                }

                 // Link the NEW employee (if one was selected)
                 if ($linked_employee_id) {
                    // Double check the new employee isn't already linked to someone else
                    $emp_check = $db->query("SELECT user_id FROM employees WHERE id = ?", [$linked_employee_id])->first();
                    if ($emp_check && $emp_check->user_id !== null && $emp_check->user_id != $user_id) {
                         throw new Exception("Cannot link: This employee is already linked to a different user.");
                    }
                    $db->update('employees', $linked_employee_id, ['user_id' => $user_id]);
                 }
            }
            // If linked_employee_id is the same as current_linked_employee_id, do nothing.


            $pdo->commit();
            $_SESSION['success_flash'] = 'User successfully updated.';
            header('Location: users.php');
            exit();
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// --- LOGIC: HANDLE GET REQUESTS (DELETE ONLY - Edit handled above) ---
try {
     // --- DELETE USER ---
     if (isset($_GET['delete'])) {
        if ($_SESSION['user_role'] !== 'Superadmin') { // Only Superadmin can delete
             throw new Exception("You do not have permission to delete users.");
        }
        $delete_uuid = $_GET['delete'];
        $user_to_delete = $db->query("SELECT id, role FROM users WHERE uuid = ?", [$delete_uuid])->first();

        if (!$user_to_delete) {
            throw new Exception("User not found.");
        }
        if ($user_to_delete->role == 'Superadmin') {
            // Prevent deleting Superadmin unless there are others
            $superadmin_count = $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'Superadmin'")->first()->count;
            if ($superadmin_count <= 1) {
                 throw new Exception("Cannot delete the only Superadmin.");
            }
        }

        $pdo = $db->getPdo();
        $pdo->beginTransaction();

        // Unlink employee first
        $db->query("UPDATE employees SET user_id = NULL WHERE user_id = ?", [$user_to_delete->id]);

        // Delete user
        $deleted = $db->delete('users', ['id', '=', $user_to_delete->id]); // Assumes delete method exists in Database.php

        if (!$deleted || $db->error()) { // Check for errors after delete
             throw new Exception("Failed to delete user." . ($db->error() ? ' DB Error: ' . implode(' ', $db->errorInfo()) : ''));
        }

        $pdo->commit();
        $_SESSION['success_flash'] = 'User successfully deleted.';
        header('Location: users.php');
        exit();
    }

} catch (Exception $e) {
     if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Set error flash for GET requests as well
    $_SESSION['error_flash'] = $e->getMessage();
     header('Location: users.php'); // Redirect back to list on error
     exit();
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
            <?php echo $edit_mode ? 'Update user details, role, and linked employee.' : 'Create a new user account.'; ?>
        </p>
    </div>
    <a href="users.php"
       class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
        <i class="fas fa-arrow-left mr-2"></i>Back to User List
    </a>
</div>

<!-- ======================================== -->
<!-- 2. ERROR / SUCCESS DISPLAY -->
<!-- ======================================== -->
<?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg" role="alert">
        <p class="font-bold">Error</p>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>
<?php endif; ?>
<?php // Session flash messages are handled by header/display_message() ?>


<!-- ======================================== -->
<!-- 3. ADD / EDIT USER FORM -->
<!-- ======================================== -->
<div class="bg-white rounded-lg shadow-md p-6">

    <form action="manage_user.php<?php echo $edit_mode ? '?edit=' . urlencode($user_to_edit->uuid) : ''; ?>" method="POST" class="space-y-6">

        <!-- Hidden fields -->
        <input type="hidden" name="<?php echo $form_action; ?>" value="1">
        <?php if ($edit_mode): ?>
            <input type="hidden" name="user_uuid" value="<?php echo htmlspecialchars($user_to_edit->uuid); ?>">
        <?php endif; ?>

        <!-- Display Name -->
        <div>
            <label for="display_name" class="block text-sm font-medium text-gray-700 mb-1">Display Name <span class="text-red-500">*</span></label>
            <input type="text" id="display_name" name="display_name" required maxlength="100"
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                   value="<?php echo htmlspecialchars($user_to_edit->display_name ?? ''); ?>"
                   placeholder="e.g., John Doe">
        </div>

        <!-- Email -->
        <div>
            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email (Login ID) <span class="text-red-500">*</span></label>
            <input type="email" id="email" name="email" required maxlength="100"
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                   value="<?php echo htmlspecialchars($user_to_edit->email ?? ''); ?>"
                   placeholder="e.g., user@example.com">
        </div>

        <!-- Password -->
        <div>
            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                Password <?php echo !$edit_mode ? '<span class="text-red-500">*</span>' : '<span class="text-gray-400">(Leave blank to keep current)</span>'; ?>
            </label>
            <input type="password" id="password" name="password" <?php echo !$edit_mode ? 'required' : ''; ?>
                   minlength="8"
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                   placeholder="<?php echo $edit_mode ? 'Enter new password to change' : 'Minimum 8 characters'; ?>">
             <?php if ($edit_mode): ?>
                <p class="mt-1 text-xs text-amber-600">Changing the password here will require the user to log in again.</p>
             <?php else: ?>
                 <p class="mt-1 text-xs text-gray-500">Must be at least 8 characters long.</p>
             <?php endif; ?>
        </div>

         <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Role -->
            <div>
                <label for="role" class="block text-sm font-medium text-gray-700 mb-1">Role <span class="text-red-500">*</span></label>
                <select id="role" name="role" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors">
                    <option value="" disabled <?php echo !$edit_mode ? 'selected' : ''; ?>>Select a role...</option>
                    <?php foreach ($all_roles as $role_option): ?>
                        <option value="<?php echo htmlspecialchars($role_option); ?>"
                            <?php if ($edit_mode && isset($user_to_edit->role) && $user_to_edit->role === $role_option) echo 'selected'; ?>
                            <?php // Disable changing away from Superadmin if it's the only one
                                $is_only_superadmin = ($edit_mode && isset($user_to_edit->role) && $user_to_edit->role === 'Superadmin' && $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'Superadmin'")->first()->count <= 1);
                                if ($is_only_superadmin && $role_option !== 'Superadmin') {
                                     echo ' disabled title="Cannot change role of the only Superadmin"';
                                }
                                // Prevent non-Superadmins from assigning Superadmin role
                                if ($_SESSION['user_role'] !== 'Superadmin' && $role_option === 'Superadmin') {
                                    echo ' disabled title="Only Superadmins can assign the Superadmin role"';
                                }
                            ?>
                        >
                            <?php echo htmlspecialchars($role_option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Status -->
            <div>
                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status <span class="text-red-500">*</span></label>
                <select id="status" name="status" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors">
                    <option value="active" <?php echo ($user_to_edit->status ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo ($user_to_edit->status ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    <option value="disabled" <?php echo ($user_to_edit->status ?? '') === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                </select>
            </div>
        </div>

        <!-- Link Employee -->
        <div>
            <label for="linked_employee_id" class="block text-sm font-medium text-gray-700 mb-1">Link to Employee <span class="text-gray-400">(Optional)</span></label>
            <select id="linked_employee_id" name="linked_employee_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors">
                <option value="">-- None --</option>
                <?php foreach ($employees_without_user as $employee): ?>
                    <option value="<?php echo $employee->id; ?>"
                        <?php if ($edit_mode && isset($user_to_edit->linked_employee_id) && $user_to_edit->linked_employee_id == $employee->id) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($employee->full_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="mt-1 text-xs text-gray-500">Link this login account to an employee record. Only employees without an existing user account are shown (plus the currently linked one, if editing).</p>
        </div>


        <!-- Submit Button -->
        <div class="pt-6 border-t border-gray-200 flex justify-end space-x-3">
            <a href="users.php"
               class="px-5 py-2.5 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                Cancel
            </a>
            <button type="submit"
                    class="px-5 py-2.5 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <?php if ($edit_mode): ?>
                    <i class="fas fa-save mr-2"></i>Update User
                <?php else: ?>
                    <i class="fas fa-user-plus mr-2"></i>Create User
                <?php endif; ?>
            </button>
        </div>
    </form>
</div>

<?php
// --- Include Footer ---
require_once '../templates/footer.php';
?>


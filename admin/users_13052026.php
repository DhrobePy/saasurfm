<?php
require_once '../core/init.php';

// --- SECURITY ---
// Only Superadmin and admin can manage users
$allowed_roles = ['Superadmin', 'admin'];
restrict_access($allowed_roles);

// Get the $db instance
global $db;
$pageTitle = 'User Management';
$users = [];
$error = null;

// --- DATA: GET ALL USERS WITH EMPLOYEE INFO ---
try {
    $users = $db->query(
        "SELECT
            u.id,
            u.uuid,
            u.display_name,
            u.email,
            u.role,
            u.status,
            u.last_login,
            e.id as employee_id,
            CONCAT(e.first_name, ' ', e.last_name) as employee_name
        FROM
            users u
        LEFT JOIN
            employees e ON u.id = e.user_id -- Link users to employees via user_id
        ORDER BY
            u.display_name ASC"
    )->results();

} catch (Exception $e) {
    $error = "Could not load users: " . $e->getMessage();
}

// --- Include Header ---
require_once '../templates/header.php';
?>

<!-- ======================================== -->
<!-- 1. PAGE HEADER & ADD BUTTON -->
<!-- ======================================== -->
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">User Management</h1>
        <p class="text-lg text-gray-600 mt-1">
            Manage system login access and roles.
        </p>
    </div>
    <a href="manage_user.php"
       class="inline-flex items-center px-5 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors">
        <i class="fas fa-user-plus mr-2"></i>Add New User
    </a>
</div>

<!-- ======================================== -->
<!-- 2. ERROR DISPLAY (If any) -->
<!-- ======================================== -->
<?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg" role="alert">
        <p class="font-bold">Error</p>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>
<?php endif; ?>

<!-- ======================================== -->
<!-- 3. USERS LIST TABLE -->
<!-- ======================================== -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Display Name</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Linked Employee</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Login</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($users) && !$error): ?>
                    <tr>
                        <td colspan="7" class="px-6 py-10 text-center text-sm text-gray-500">
                            <i class="fas fa-users-slash fa-2x text-gray-300 mb-3"></i>
                            <p>No users found. Click "Add New User" to get started.</p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($users as $user): ?>
                    <?php
                        // Status badge styling
                        $statusClasses = [
                            'active' => 'bg-green-100 text-green-800',
                            'inactive' => 'bg-yellow-100 text-yellow-800',
                            'disabled' => 'bg-red-100 text-red-800' // Assuming disabled might be a status
                        ];
                        $statusClass = $statusClasses[$user->status] ?? 'bg-gray-100 text-gray-800';

                        // Role styling (example - adjust colors as needed)
                        $roleClasses = [
                            'Superadmin' => 'bg-red-100 text-red-800 font-bold',
                            'admin' => 'bg-purple-100 text-purple-800',
                            'Accounts' => 'bg-blue-100 text-blue-800',
                            // Add other roles here if you want specific styling
                        ];
                        $roleClass = $roleClasses[$user->role] ?? 'bg-gray-100 text-gray-800';
                    ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user->display_name); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-600"><?php echo htmlspecialchars($user->email); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2.5 py-0.5 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $roleClass; ?>">
                                <?php echo htmlspecialchars($user->role); ?>
                            </span>
                        </td>
                         <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?php if ($user->employee_id): ?>
                                <!-- Link to employee profile page if needed -->
                                <a href="../admin/employee_profile.php?id=<?php echo $user->employee_id; ?>" class="text-primary-600 hover:underline">
                                    <?php echo htmlspecialchars($user->employee_name); ?> (ID: <?php echo $user->employee_id; ?>)
                                </a>
                            <?php else: ?>
                                <span class="text-gray-400 italic">None</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2.5 py-0.5 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                                <?php echo ucfirst($user->status); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo $user->last_login ? date('d M Y, H:i', strtotime($user->last_login)) : 'Never'; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-3">
                            <a href="manage_user.php?edit=<?php echo urlencode($user->uuid); ?>" class="text-primary-600 hover:text-primary-900" title="Edit User">
                                <i class="fas fa-edit"></i>
                            </a>
                            <!-- Optional: Add delete functionality here if needed, with confirmation -->
                             <?php if ($user->role !== 'Superadmin'): // Prevent deleting Superadmin ?>
                                <a href="manage_user.php?delete=<?php echo urlencode($user->uuid); ?>"
                                   class="text-red-600 hover:text-red-900"
                                   title="Delete User"
                                   onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                    <i class="fas fa-trash"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
// --- Include Footer ---
require_once '../templates/footer.php';
?>


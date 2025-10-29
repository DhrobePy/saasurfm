<?php
require_once '../core/init.php';

// --- SECURITY ---
// Define roles allowed to view the employee list
$allowed_roles = [
    'Superadmin',
    'admin',
    'Accounts',
    'accounts-rampura', 'accounts-srg', 'accounts-demra',
    'production manager-srg', 'production manager-demra',
    // Add other roles who need to view the list if necessary
];
restrict_access($allowed_roles);

// Get the $db instance
global $db;
$pageTitle = 'Employee Management';
$employees = [];
$error = null;

// --- DATA: GET ALL EMPLOYEES WITH RELATED INFO ---
try {
    // *** RECTIFIED QUERY ***
    // Join sequence changed: e -> p -> d
    $employees = $db->query(
        "SELECT
            e.id,
            e.first_name,
            e.last_name,
            e.email,
            e.phone,
            e.hire_date,
            e.status,
            p.title as position_title,
            d.name as department_name, -- Still selected, but joined via positions
            b.name as branch_name,
            u.id as user_id,
            u.display_name as user_display_name,
            u.uuid as user_uuid -- Fetch user UUID for linking
        FROM
            employees e
        LEFT JOIN
            positions p ON e.position_id = p.id
        LEFT JOIN
            departments d ON p.department_id = d.id -- *** JOIN positions to departments ***
        LEFT JOIN
            branches b ON e.branch_id = b.id
        LEFT JOIN
            users u ON e.user_id = u.id
        ORDER BY
            e.first_name ASC, e.last_name ASC"
    )->results();

} catch (Exception $e) {
    $error = "Could not load employees: " . $e->getMessage();
}

// --- Include Header ---
require_once '../templates/header.php';
?>

<!-- ======================================== -->
<!-- 1. PAGE HEADER & ADD BUTTON -->
<!-- ======================================== -->
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Employee Management</h1>
        <p class="text-lg text-gray-600 mt-1">
            Manage employee records and information.
        </p>
    </div>
    <?php if (in_array($_SESSION['user_role'], ['Superadmin', 'admin'])): // Only admins can add employees ?>
        <a href="manage_employee.php"
           class="inline-flex items-center px-5 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors">
            <i class="fas fa-user-plus mr-2"></i>Add New Employee
        </a>
    <?php endif; ?>
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
<!-- 3. EMPLOYEES LIST TABLE -->
<!-- ======================================== -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Position / Dept.</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Branch</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hire Date</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Linked User</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($employees) && !$error): ?>
                    <tr>
                        <td colspan="8" class="px-6 py-10 text-center text-sm text-gray-500">
                            <i class="fas fa-users-slash fa-2x text-gray-300 mb-3"></i>
                            <p>No employees found. Click "Add New Employee" to get started.</p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($employees as $employee): ?>
                    <?php
                        // Status badge styling
                        $statusClasses = [
                            'active' => 'bg-green-100 text-green-800',
                            'on_leave' => 'bg-yellow-100 text-yellow-800',
                            'terminated' => 'bg-red-100 text-red-800'
                        ];
                        $statusClass = $statusClasses[$employee->status] ?? 'bg-gray-100 text-gray-800';
                    ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($employee->first_name . ' ' . $employee->last_name); ?>
                            </div>
                             <div class="text-xs text-gray-500">ID: <?php echo $employee->id; ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-600"><?php echo htmlspecialchars($employee->email); ?></div>
                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($employee->phone ?? '--'); ?></div>
                        </td>
                         <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($employee->position_title ?? 'N/A'); ?></div>
                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($employee->department_name ?? 'N/A'); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?php echo htmlspecialchars($employee->branch_name ?? 'N/A'); ?>
                        </td>
                         <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo $employee->hire_date ? date('d M Y', strtotime($employee->hire_date)) : 'N/A'; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2.5 py-0.5 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $employee->status)); ?>
                            </span>
                        </td>
                         <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?php if ($employee->user_id): ?>
                                <!-- Link uses user_uuid now -->
                                <a href="manage_user.php?edit=<?php echo urlencode($employee->user_uuid); ?>" class="text-primary-600 hover:underline" title="Edit Linked User">
                                     <i class="fas fa-user-check mr-1 text-green-600"></i> <?php echo htmlspecialchars($employee->user_display_name ?? 'User #'.$employee->user_id); ?>
                                </a>
                            <?php else: ?>
                                <span class="text-gray-400 italic">None</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-3">
                           <!-- *** RECTIFIED: Use employee->id for links *** -->
                            <a href="employee_profile.php?id=<?php echo $employee->id; ?>" class="text-blue-600 hover:text-blue-900" title="View Profile">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if (in_array($_SESSION['user_role'], ['Superadmin', 'admin'])): // Only admins can edit/delete ?>
                                <a href="manage_employee.php?edit=<?php echo $employee->id; ?>" class="text-primary-600 hover:text-primary-900" title="Edit Employee">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="manage_employee.php?delete=<?php echo $employee->id; ?>"
                                   class="text-red-600 hover:text-red-900"
                                   title="Delete Employee"
                                   onclick="return confirm('Are you sure you want to delete this employee record? This action cannot be undone.');">
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


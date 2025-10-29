<?php
// new_ufmhrm/admin/employee_profile.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../core/init.php';

if (!is_admin_logged_in()) {
    header('Location: ../auth/login.php');
    exit();
}

// Get employee ID from URL
$employeeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$employeeId) {
    header('Location: employees.php');
    exit();
}

// Fetch employee details with all related information
$sql = "
    SELECT 
        e.*, 
        p.name as position_name, 
        d.name as department_name,
        d.id as department_id
    FROM employees e
    LEFT JOIN positions p ON e.position_id = p.id
    LEFT JOIN departments d ON p.department_id = d.id
    WHERE e.id = ?
";

$result = $db->query($sql, [$employeeId]);
$employee = $result ? $result->first() : null;

if (!$employee) {
    header('Location: employees.php');
    exit();
}

// Fetch attendance statistics
$attendanceStats = $db->query("
    SELECT 
        COUNT(*) as total_days,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days
    FROM attendance 
    WHERE employee_id = ? AND MONTH(clock_in) = MONTH(CURRENT_DATE()) AND YEAR(clock_in) = YEAR(CURRENT_DATE())
", [$employeeId])->first();

// Fetch recent attendance records
$recentAttendance = $db->query("
    SELECT * FROM attendance 
    WHERE employee_id = ? 
    ORDER BY clock_in DESC 
    LIMIT 10
", [$employeeId])->results();

// Fetch full month attendance for calendar
$currentMonth = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$currentYear = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

$attendanceCalendar = $db->query("
    SELECT 
        DATE(clock_in) as date,
        status
    FROM attendance 
    WHERE employee_id = ? 
    AND MONTH(clock_in) = ? 
    AND YEAR(clock_in) = ?
    ORDER BY clock_in
", [$employeeId, $currentMonth, $currentYear])->results();

// Convert to date-keyed array for easy lookup
$attendanceMap = [];
foreach ($attendanceCalendar as $record) {
    $attendanceMap[$record->date] = $record->status;
}

// Fetch leave requests
$leaveRequests = $db->query("
    SELECT * FROM leave_requests 
    WHERE employee_id = ? 
    ORDER BY start_date DESC 
    LIMIT 5
", [$employeeId])->results();

// Fetch salary advances
$salaryAdvances = $db->query("
    SELECT * FROM salary_advances 
    WHERE employee_id = ? 
    ORDER BY advance_date DESC 
    LIMIT 5
", [$employeeId])->results();

// Fetch loans
$loans = $db->query("
    SELECT * FROM loans 
    WHERE employee_id = ? 
    ORDER BY loan_date DESC 
    LIMIT 5
", [$employeeId])->results();

// Calculate tenure
$hireDate = new DateTime($employee->hire_date);
$today = new DateTime();
$tenure = $hireDate->diff($today);

$pageTitle = $employee->first_name . ' ' . $employee->last_name . ' - Profile';
include_once '../templates/header.php';
?>

<div class="space-y-6">
    <!-- Back Button -->
    <div>
        <a href="employees.php" class="inline-flex items-center text-sm text-gray-600 hover:text-gray-900">
            <i class="fas fa-arrow-left mr-2"></i>
            Back to Employees
        </a>
    </div>

    <!-- Profile Header Card -->
    <div class="bg-gradient-to-r from-primary-600 to-primary-800 rounded-xl shadow-lg overflow-hidden">
        <div class="px-6 py-8 sm:p-10">
            <div class="flex flex-col sm:flex-row items-center sm:items-start gap-6">
                <!-- Avatar -->
                <div class="relative">
                    <div class="relative flex-shrink-0">
                        <div class="h-32 w-32 rounded-full bg-white flex items-center justify-center shadow-xl ring-4 ring-white ring-opacity-50 overflow-hidden">
                            <?php if (!empty($employee->profile_picture) && file_exists('../' . $employee->profile_picture)): ?>
                                <img class="h-full w-full object-cover" src="../<?php echo htmlspecialchars($employee->profile_picture); ?>" alt="Profile Picture">
                            <?php else: ?>
                                <span class="text-5xl font-bold text-primary-600">
                                    <?php echo strtoupper(substr($employee->first_name, 0, 1) . substr($employee->last_name, 0, 1)); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="absolute bottom-0 right-0 h-8 w-8 bg-<?php echo $employee->status === 'active' ? 'green' : 'gray'; ?>-500 rounded-full border-4 border-white"></div>
                    </div>
                    <div class="absolute bottom-0 right-0 h-8 w-8 bg-<?php echo $employee->status === 'active' ? 'green' : 'gray'; ?>-500 rounded-full border-4 border-white"></div>
                </div>

                <!-- Employee Info -->
                <div class="flex-1 text-center sm:text-left">
                    <h1 class="text-3xl font-bold text-white mb-2">
                        <?php echo htmlspecialchars($employee->first_name . ' ' . $employee->last_name); ?>
                    </h1>
                    <p class="text-xl text-primary-100 mb-4"><?php echo htmlspecialchars($employee->position_name ?? 'N/A'); ?></p>
                    
                    <div class="flex flex-wrap gap-3 justify-center sm:justify-start">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-white bg-opacity-20 text-white">
                            <i class="fas fa-building mr-2"></i>
                            <?php echo htmlspecialchars($employee->department_name ?? 'N/A'); ?>
                        </span>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-white bg-opacity-20 text-white">
                            <i class="fas fa-id-badge mr-2"></i>
                            EMP-<?php echo str_pad($employee->id, 4, '0', STR_PAD_LEFT); ?>
                        </span>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-<?php echo $employee->status === 'active' ? 'green' : 'red'; ?>-500 text-white">
                            <i class="fas fa-circle mr-2 text-xs"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $employee->status)); ?>
                        </span>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-wrap gap-2">
                    <a href="edit_employee.php?id=<?php echo $employee->id; ?>" 
                       class="inline-flex items-center px-4 py-2 bg-white text-primary-600 rounded-lg hover:bg-primary-50 transition-colors shadow-md">
                        <i class="fas fa-edit mr-2"></i>
                        Edit Profile
                    </a>
                    
                    <!-- Dropdown Menu Button -->
                    <div class="relative inline-block text-left" id="actionDropdown">
                        <button type="button" 
                                onclick="toggleDropdown()"
                                class="inline-flex items-center px-4 py-2 bg-white bg-opacity-10 text-white rounded-lg hover:bg-opacity-20 transition-colors">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                        
                        <!-- Dropdown Menu -->
                        <div id="dropdownMenu" 
                             class="hidden absolute right-0 mt-2 w-56 rounded-lg shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50">
                            <div class="py-1" role="menu">
                                <!-- Generate Payslip -->
                                <a href="payslip.php?id=<?php echo $employee->id; ?>" 
                                   target="_blank"
                                   class="group flex items-center px-4 py-3 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-600 transition-colors">
                                    <div class="flex-shrink-0 h-8 w-8 bg-indigo-100 rounded-lg flex items-center justify-center mr-3 group-hover:bg-indigo-200">
                                        <i class="fas fa-file-invoice text-indigo-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium">Generate Payslip</p>
                                        <p class="text-xs text-gray-500">Download salary slip</p>
                                    </div>
                                </a>
                                
                                <!-- Generate Salary Certificate -->
                                <a href="generate_salary_certificate.php?id=<?php echo $employee->id; ?>" 
                                   target="_blank"
                                   class="group flex items-center px-4 py-3 text-sm text-gray-700 hover:bg-green-50 hover:text-green-600 transition-colors">
                                    <div class="flex-shrink-0 h-8 w-8 bg-green-100 rounded-lg flex items-center justify-center mr-3 group-hover:bg-green-200">
                                        <i class="fas fa-certificate text-green-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium">Salary Certificate</p>
                                        <p class="text-xs text-gray-500">Download certificate</p>
                                    </div>
                                </a>
                                
                                <div class="border-t border-gray-100"></div>
                                
                                <!-- Print Profile -->
                                <a href="javascript:window.print()" 
                                   class="group flex items-center px-4 py-3 text-sm text-gray-700 hover:bg-purple-50 hover:text-purple-600 transition-colors">
                                    <div class="flex-shrink-0 h-8 w-8 bg-purple-100 rounded-lg flex items-center justify-center mr-3 group-hover:bg-purple-200">
                                        <i class="fas fa-print text-purple-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium">Print Profile</p>
                                        <p class="text-xs text-gray-500">Print this page</p>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Present Days</p>
                    <p class="text-3xl font-bold text-green-600 mt-2"><?php echo $attendanceStats->present_days ?? 0; ?></p>
                    <p class="text-xs text-gray-500 mt-1">This month</p>
                </div>
                <div class="h-12 w-12 bg-green-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-calendar-check text-2xl text-green-600"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Absent Days</p>
                    <p class="text-3xl font-bold text-red-600 mt-2"><?php echo $attendanceStats->absent_days ?? 0; ?></p>
                    <p class="text-xs text-gray-500 mt-1">This month</p>
                </div>
                <div class="h-12 w-12 bg-red-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-calendar-times text-2xl text-red-600"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Late Arrivals</p>
                    <p class="text-3xl font-bold text-amber-600 mt-2"><?php echo $attendanceStats->late_days ?? 0; ?></p>
                    <p class="text-xs text-gray-500 mt-1">This month</p>
                </div>
                <div class="h-12 w-12 bg-amber-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-clock text-2xl text-amber-600"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Tenure</p>
                    <p class="text-3xl font-bold text-blue-600 mt-2"><?php echo $tenure->y; ?><span class="text-lg">y</span> <?php echo $tenure->m; ?><span class="text-lg">m</span></p>
                    <p class="text-xs text-gray-500 mt-1">Since <?php echo date('M Y', strtotime($employee->hire_date)); ?></p>
                </div>
                <div class="h-12 w-12 bg-blue-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-briefcase text-2xl text-blue-600"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column - Personal Information -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Personal Details Card -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-gray-50 to-gray-100 px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-user-circle text-primary-600 mr-2"></i>
                        Personal Information
                    </h2>
                </div>
                <div class="p-6 space-y-4">
                    <div class="flex items-start">
                        <div class="flex-shrink-0 h-10 w-10 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-envelope text-blue-600"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-xs font-medium text-gray-500 uppercase">Email</p>
                            <p class="text-sm text-gray-900 mt-1"><?php echo htmlspecialchars($employee->email); ?></p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <div class="flex-shrink-0 h-10 w-10 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-phone text-green-600"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-xs font-medium text-gray-500 uppercase">Phone</p>
                            <p class="text-sm text-gray-900 mt-1"><?php echo htmlspecialchars($employee->phone ?? 'N/A'); ?></p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <div class="flex-shrink-0 h-10 w-10 bg-purple-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-map-marker-alt text-purple-600"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-xs font-medium text-gray-500 uppercase">Address</p>
                            <p class="text-sm text-gray-900 mt-1"><?php echo htmlspecialchars($employee->address ?? 'N/A'); ?></p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <div class="flex-shrink-0 h-10 w-10 bg-indigo-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-venus-mars text-indigo-600"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-xs font-medium text-gray-500 uppercase">Gender</p>
                            <p class="text-sm text-gray-900 mt-1"><?php echo ucfirst($employee->gender ?? 'N/A'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Employment Details Card -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-gray-50 to-gray-100 px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-briefcase text-primary-600 mr-2"></i>
                        Employment Details
                    </h2>
                </div>
                <div class="p-6 space-y-4">
                    <div class="flex justify-between items-center pb-3 border-b border-gray-200">
                        <span class="text-sm text-gray-600">Hire Date</span>
                        <span class="text-sm font-semibold text-gray-900"><?php echo date('M d, Y', strtotime($employee->hire_date)); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-3 border-b border-gray-200">
                        <span class="text-sm text-gray-600">Base Salary</span>
                        <span class="text-sm font-semibold text-green-600">৳<?php echo number_format($employee->base_salary, 2); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-3 border-b border-gray-200">
                        <span class="text-sm text-gray-600">Employment Type</span>
                        <span class="text-sm font-semibold text-gray-900"><?php echo ucfirst($employee->employment_type ?? 'Full-time'); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Work Schedule</span>
                        <span class="text-sm font-semibold text-gray-900">9:00 AM - 5:00 PM</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column - Activity & History -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Attendance Calendar -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-gray-50 to-gray-100 px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-calendar text-primary-600 mr-2"></i>
                        Attendance Calendar
                    </h2>
                    <div class="flex gap-2">
                        <a href="?id=<?php echo $employeeId; ?>&month=<?php echo ($currentMonth == 1 ? 12 : $currentMonth - 1); ?>&year=<?php echo ($currentMonth == 1 ? $currentYear - 1 : $currentYear); ?>" 
                           class="px-3 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded transition-colors">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <span class="px-4 py-1 text-sm font-semibold text-gray-900 bg-gray-100 rounded">
                            <?php echo date('F Y', mktime(0, 0, 0, $currentMonth, 1, $currentYear)); ?>
                        </span>
                        <a href="?id=<?php echo $employeeId; ?>&month=<?php echo ($currentMonth == 12 ? 1 : $currentMonth + 1); ?>&year=<?php echo ($currentMonth == 12 ? $currentYear + 1 : $currentYear); ?>" 
                           class="px-3 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded transition-colors">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
                
                <div class="p-6">
                    <div class="grid grid-cols-7 gap-2">
                        <!-- Day headers -->
                        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day): ?>
                            <div class="text-center font-semibold text-sm text-gray-600 py-2">
                                <?php echo $day; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- Calendar days -->
                        <?php
                        $firstDay = date('w', mktime(0, 0, 0, $currentMonth, 1, $currentYear));
                        $daysInMonth = date('t', mktime(0, 0, 0, $currentMonth, 1, $currentYear));
                        
                        // Empty cells for days before month starts
                        for ($i = 0; $i < $firstDay; $i++) {
                            echo '<div></div>';
                        }
                        
                        // Days of month
                        for ($day = 1; $day <= $daysInMonth; $day++) {
                            $date = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $day);
                            $status = $attendanceMap[$date] ?? null;
                            $today = date('Y-m-d');
                            $isToday = $date === $today;
                            
                            $statusClass = '';
                            $statusLabel = '';
                            $bgColor = 'bg-gray-50';
                            
                            if ($status === 'present') {
                                $statusClass = 'bg-green-100 text-green-800 border-green-300';
                                $statusLabel = '✓';
                            } elseif ($status === 'absent') {
                                $statusClass = 'bg-red-100 text-red-800 border-red-300';
                                $statusLabel = '✕';
                            } elseif ($status === 'late') {
                                $statusClass = 'bg-amber-100 text-amber-800 border-amber-300';
                                $statusLabel = '⚠';
                            } else {
                                $statusClass = 'bg-gray-100 text-gray-400';
                                $statusLabel = '';
                            }
                            
                            echo '<div class="relative">';
                            echo '<div class="h-16 rounded-lg ' . $statusClass . ' border-2 flex items-center justify-center cursor-pointer hover:shadow-md transition-shadow group" title="' . ($status ? ucfirst($status) : 'No record') . '">';
                            echo '<div class="text-center">';
                            echo '<div class="text-xs font-bold">' . $day . '</div>';
                            if ($statusLabel) {
                                echo '<div class="text-lg">' . $statusLabel . '</div>';
                            }
                            echo '</div>';
                            if ($isToday) {
                                echo '<div class="absolute top-1 right-1 w-2 h-2 bg-blue-500 rounded-full"></div>';
                            }
                            echo '</div>';
                            echo '</div>';
                        }
                        ?>
                    </div>
                    
                    <!-- Legend -->
                    <div class="mt-6 pt-6 border-t border-gray-200 flex flex-wrap gap-4 text-sm">
                        <div class="flex items-center gap-2">
                            <div class="w-4 h-4 bg-green-100 border-2 border-green-300 rounded"></div>
                            <span class="text-gray-600">Present</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-4 h-4 bg-red-100 border-2 border-red-300 rounded"></div>
                            <span class="text-gray-600">Absent</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-4 h-4 bg-amber-100 border-2 border-amber-300 rounded"></div>
                            <span class="text-gray-600">Late</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-4 h-4 bg-gray-100 border-2 border-gray-300 rounded"></div>
                            <span class="text-gray-600">No Record</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Attendance -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-gray-50 to-gray-100 px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-history text-primary-600 mr-2"></i>
                        Recent Attendance
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Clock In</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Clock Out</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if ($recentAttendance): ?>
                                <?php foreach ($recentAttendance as $att): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo date('M d, Y', strtotime($att->clock_in)); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?php echo date('h:i A', strtotime($att->clock_in)); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?php echo $att->clock_out ? date('h:i A', strtotime($att->clock_out)) : '-'; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                            <?php 
                                                if ($att->status === 'present') echo 'bg-green-100 text-green-800';
                                                elseif ($att->status === 'absent') echo 'bg-red-100 text-red-800';
                                                elseif ($att->status === 'late') echo 'bg-amber-100 text-amber-800';
                                                else echo 'bg-gray-100 text-gray-800';
                                            ?>
                                        ">
                                            <?php echo ucfirst($att->status); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="px-6 py-8 text-center text-gray-500">No attendance records found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Leave Requests -->
            <!-- Leave Requests -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-gray-50 to-gray-100 px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-calendar-alt text-primary-600 mr-2"></i>
                        Leave Requests
                    </h2>
                </div>
                <div class="p-6">
                    <?php if ($leaveRequests): ?>
                        <div class="space-y-4">
                            <?php foreach ($leaveRequests as $leave): ?>
                            <div class="flex items-start p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                                <div class="flex-shrink-0 h-10 w-10 bg-purple-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-umbrella-beach text-purple-600"></i>
                                </div>
                                <div class="ml-4 flex-1">
                                    <div class="flex items-center justify-between">
                                        <h3 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($leave->leave_type ?? 'Leave'); ?></h3>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                            <?php 
                                                if ($leave->status === 'approved') echo 'bg-green-100 text-green-800';
                                                elseif ($leave->status === 'rejected') echo 'bg-red-100 text-red-800';
                                                else echo 'bg-yellow-100 text-yellow-800';
                                            ?>
                                        ">
                                            <?php echo ucfirst($leave->status); ?>
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">
                                        <?php echo date('M d', strtotime($leave->start_date)); ?> - <?php echo date('M d, Y', strtotime($leave->end_date)); ?>
                                        (<?php 
                                            $start = new DateTime($leave->start_date);
                                            $end = new DateTime($leave->end_date);
                                            $days = $start->diff($end)->days + 1;
                                            echo $days . ' day' . ($days > 1 ? 's' : '');
                                        ?>)
                                    </p>
                                    <?php if ($leave->reason): ?>
                                        <p class="text-sm text-gray-600 mt-2"><?php echo htmlspecialchars($leave->reason); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-gray-500 py-8">No leave requests found</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Financial Overview -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Salary Advances -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="bg-gradient-to-r from-orange-50 to-orange-100 px-6 py-4 border-b border-orange-200">
                        <h2 class="text-lg font-semibold text-gray-900">
                            <i class="fas fa-hand-holding-usd text-orange-600 mr-2"></i>
                            Salary Advances
                        </h2>
                    </div>
                    <div class="p-6">
                        <?php if ($salaryAdvances): ?>
                            <div class="space-y-3">
                                <?php foreach (array_slice($salaryAdvances, 0, 3) as $advance): ?>
                                <div class="flex justify-between items-center pb-3 border-b border-gray-200">
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900">৳<?php echo number_format($advance->amount, 2); ?></p>
                                        <p class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($advance->advance_date)); ?></p>
                                    </div>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                        <?php echo $advance->status === 'repaid' ? 'bg-green-100 text-green-800' : 'bg-orange-100 text-orange-800'; ?>
                                    ">
                                        <?php echo ucfirst($advance->status); ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-center text-gray-500 py-4 text-sm">No advances</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Loans -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="bg-gradient-to-r from-blue-50 to-blue-100 px-6 py-4 border-b border-blue-200">
                        <h2 class="text-lg font-semibold text-gray-900">
                            <i class="fas fa-file-invoice-dollar text-blue-600 mr-2"></i>
                            Loans
                        </h2>
                    </div>
                    <div class="p-6">
                        <?php if ($loans): ?>
                            <div class="space-y-3">
                                <?php foreach (array_slice($loans, 0, 3) as $loan): ?>
                                <div class="flex justify-between items-center pb-3 border-b border-gray-200">
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900">৳<?php echo number_format($loan->amount, 2); ?></p>
                                        <p class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($loan->loan_date)); ?></p>
                                    </div>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                        <?php echo $loan->status === 'repaid' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>
                                    ">
                                        <?php echo ucfirst($loan->status); ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-center text-gray-500 py-4 text-sm">No loans</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        .no-print {
            display: none !important;
        }
    }
</style>

<script>
// Toggle dropdown menu
function toggleDropdown() {
    const dropdown = document.getElementById('dropdownMenu');
    dropdown.classList.toggle('hidden');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('actionDropdown');
    const menu = document.getElementById('dropdownMenu');
    
    if (dropdown && !dropdown.contains(event.target)) {
        menu.classList.add('hidden');
    }
});
</script>

<?php include_once '../templates/footer.php'; ?>
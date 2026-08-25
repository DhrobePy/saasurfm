<?php
// new_ufmhrm/admin/add_employee.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../core/init.php';

if (!is_admin_logged_in()) {
    header('Location: ../auth/login.php');
    exit();
}

// Fetch all departments for dropdown
$departments = $db->query("SELECT * FROM departments ORDER BY name")->results();

// Fetch all positions
$positions = $db->query("SELECT * FROM positions ORDER BY name")->results();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    // Validate required fields
    if (empty($_POST['first_name'])) {
        $errors[] = 'First name is required';
    }
    if (empty($_POST['last_name'])) {
        $errors[] = 'Last name is required';
    }
    if (empty($_POST['email'])) {
        $errors[] = 'Email is required';
    } else {
        // Check if email already exists
        $existingEmail = $db->query("SELECT id FROM employees WHERE email = ?", [$_POST['email']])->first();
        if ($existingEmail) {
            $errors[] = 'Email address already exists';
        }
    }
    if (empty($_POST['position_id'])) {
        $errors[] = 'Position is required';
    }
    if (empty($_POST['hire_date'])) {
        $errors[] = 'Hire date is required';
    }
    
    $profilePicture = null;
    
    if (empty($errors)) {
        // Insert employee information
        $insertSql = "
            INSERT INTO employees (
                first_name, last_name, email, phone, address, 
                position_id, hire_date, base_salary, status, profile_picture
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $params = [
            $_POST['first_name'],
            $_POST['last_name'],
            $_POST['email'],
            $_POST['phone'] ?? null,
            $_POST['address'] ?? null,
            $_POST['position_id'],
            $_POST['hire_date'],
            $_POST['base_salary'] ?? 0,
            $_POST['status'] ?? 'active',
            $profilePicture
        ];
        
        if ($db->query($insertSql, $params)) {
            // Get the newly created employee ID
            $newEmployeeId = $db->query("SELECT LAST_INSERT_ID() as id")->first()->id;
            
            // Handle profile picture upload after getting employee ID
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/profiles/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $fileExtension = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                
                if (in_array($fileExtension, $allowedExtensions)) {
                    $newFileName = 'profile_' . $newEmployeeId . '_' . time() . '.' . $fileExtension;
                    $targetPath = $uploadDir . $newFileName;
                    
                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $targetPath)) {
                        $profilePicture = 'uploads/profiles/' . $newFileName;
                        
                        // Update employee with profile picture
                        $db->query("UPDATE employees SET profile_picture = ? WHERE id = ?", [$profilePicture, $newEmployeeId]);
                    }
                }
            }
            
            // Insert salary structure if checkbox is checked
            if (isset($_POST['add_salary']) && $_POST['add_salary'] === '1') {
                $basicSalary = floatval($_POST['basic_salary'] ?? 0);
                $houseAllowance = floatval($_POST['house_allowance'] ?? 0);
                $transportAllowance = floatval($_POST['transport_allowance'] ?? 0);
                $medicalAllowance = floatval($_POST['medical_allowance'] ?? 0);
                $otherAllowances = floatval($_POST['other_allowances'] ?? 0);
                $providentFund = floatval($_POST['provident_fund'] ?? 0);
                $taxDeduction = floatval($_POST['tax_deduction'] ?? 0);
                $otherDeductions = floatval($_POST['other_deductions'] ?? 0);
                
                $grossSalary = $basicSalary + $houseAllowance + $transportAllowance + $medicalAllowance + $otherAllowances;
                $netSalary = $grossSalary - $providentFund - $taxDeduction - $otherDeductions;
                
                $salarySql = "
                    INSERT INTO salary_structures (
                        employee_id, basic_salary, house_allowance, transport_allowance,
                        medical_allowance, other_allowances, provident_fund, tax_deduction,
                        other_deductions, gross_salary, net_salary, created_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
                ";
                
                $salaryParams = [
                    $newEmployeeId, $basicSalary, $houseAllowance, $transportAllowance,
                    $medicalAllowance, $otherAllowances, $providentFund, $taxDeduction,
                    $otherDeductions, $grossSalary, $netSalary
                ];
                
                $db->query($salarySql, $salaryParams);
            }
            
            $_SESSION['success_message'] = 'Employee added successfully!';
            header('Location: employee_profile.php?id=' . $newEmployeeId);
            exit();
        } else {
            $errors[] = 'Failed to add employee';
        }
    }
}

$pageTitle = 'Add New Employee';
include_once '../templates/header.php';
?>

<style>
.tab-content {
    display: none;
}
.tab-content.active {
    display: block;
    animation: fadeIn 0.3s ease-in;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
.preview-image {
    transition: all 0.3s ease;
}
.preview-image:hover {
    transform: scale(1.05);
}
.salary-input:focus {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.15);
}
.summary-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    transition: all 0.3s ease;
}
.summary-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(102, 126, 234, 0.3);
}
</style>

<div class="min-h-screen bg-gradient-to-br from-blue-50 via-indigo-50 to-purple-50 py-8">
    <div class="max-w-6xl mx-auto px-4">
        <!-- Back Button -->
        <div class="mb-6">
            <a href="employees.php" 
               class="inline-flex items-center text-gray-600 hover:text-gray-900 transition-colors">
                <i class="fas fa-arrow-left mr-2"></i>
                <span class="font-medium">Back to Employees</span>
            </a>
        </div>

        <!-- Error Messages -->
        <?php if (isset($errors) && !empty($errors)): ?>
        <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-lg">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle text-red-500 text-xl mr-3"></i>
                <div>
                    <h3 class="text-red-800 font-semibold">Please fix the following errors:</h3>
                    <ul class="list-disc list-inside text-red-700 mt-2">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="addEmployeeForm">
            <!-- Header Card with Profile Picture -->
            <div class="bg-white rounded-2xl shadow-xl overflow-hidden mb-6">
                <div class="bg-gradient-to-r from-indigo-600 via-purple-600 to-pink-600 h-32"></div>
                <div class="px-8 pb-8">
                    <div class="flex flex-col md:flex-row items-center md:items-end -mt-16 gap-6">
                        <!-- Profile Picture Upload -->
                        <div class="relative group">
                            <div class="w-32 h-32 rounded-full border-4 border-white shadow-lg overflow-hidden bg-gradient-to-br from-indigo-100 to-purple-100">
                                <div id="profilePreview" class="w-full h-full flex items-center justify-center preview-image">
                                    <i class="fas fa-user text-6xl text-indigo-400"></i>
                                </div>
                            </div>
                            <label for="profilePictureInput" 
                                   class="absolute bottom-0 right-0 bg-indigo-600 text-white p-2.5 rounded-full shadow-lg hover:bg-indigo-700 transition-all transform hover:scale-110 cursor-pointer">
                                <i class="fas fa-camera text-lg"></i>
                            </label>
                            <input type="file" 
                                   id="profilePictureInput" 
                                   name="profile_picture" 
                                   accept="image/*" 
                                   class="hidden">
                        </div>
                        
                        <!-- Employee Info -->
                        <div class="flex-1 text-center md:text-left">
                            <h1 class="text-3xl font-bold text-gray-900">
                                Add New Employee
                            </h1>
                            <p class="text-lg text-gray-600 mt-1">
                                Fill in the details to create a new employee profile
                            </p>
                            <div class="mt-2">
                                <span class="inline-block px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                                    <i class="fas fa-plus-circle mr-1"></i>
                                    New Employee
                                </span>
                            </div>
                        </div>

                        <!-- Save Button -->
                        <button type="submit" 
                                class="bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-8 py-3 rounded-xl font-semibold shadow-lg hover:shadow-xl transform hover:scale-105 transition-all flex items-center gap-2">
                            <i class="fas fa-user-plus"></i>
                            Add Employee
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
                <div class="flex border-b border-gray-200 overflow-x-auto">
                    <button type="button" 
                            onclick="switchTab('personal')" 
                            id="tab-personal"
                            class="tab-button flex-1 min-w-fit px-6 py-4 font-semibold transition-all text-indigo-600 border-b-2 border-indigo-600 bg-indigo-50">
                        <div class="flex items-center justify-center gap-2">
                            <i class="fas fa-user"></i>
                            <span>Personal Info</span>
                        </div>
                    </button>
                    <button type="button" 
                            onclick="switchTab('employment')" 
                            id="tab-employment"
                            class="tab-button flex-1 min-w-fit px-6 py-4 font-semibold transition-all text-gray-600 hover:text-indigo-600 hover:bg-gray-50">
                        <div class="flex items-center justify-center gap-2">
                            <i class="fas fa-briefcase"></i>
                            <span>Employment</span>
                        </div>
                    </button>
                    <button type="button" 
                            onclick="switchTab('salary')" 
                            id="tab-salary"
                            class="tab-button flex-1 min-w-fit px-6 py-4 font-semibold transition-all text-gray-600 hover:text-indigo-600 hover:bg-gray-50">
                        <div class="flex items-center justify-center gap-2">
                            <i class="fas fa-dollar-sign"></i>
                            <span>Salary Structure</span>
                        </div>
                    </button>
                </div>

                <div class="p-8">
                    <!-- Personal Information Tab -->
                    <div id="content-personal" class="tab-content active">
                        <div class="mb-6">
                            <h2 class="text-2xl font-bold text-gray-900 mb-2">Personal Information</h2>
                            <p class="text-gray-600">Enter employee's basic personal details</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    First Name <span class="text-red-500">*</span>
                                </label>
                                <input type="text" 
                                       name="first_name" 
                                       value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>"
                                       required
                                       class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition-all outline-none"
                                       placeholder="Enter first name">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Last Name <span class="text-red-500">*</span>
                                </label>
                                <input type="text" 
                                       name="last_name" 
                                       value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>"
                                       required
                                       class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition-all outline-none"
                                       placeholder="Enter last name">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Email Address <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <i class="fas fa-envelope text-gray-400"></i>
                                    </div>
                                    <input type="email" 
                                           name="email" 
                                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                           required
                                           class="w-full pl-12 pr-4 py-3 rounded-xl border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition-all outline-none"
                                           placeholder="employee@example.com">
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Phone Number
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <i class="fas fa-phone text-gray-400"></i>
                                    </div>
                                    <input type="tel" 
                                           name="phone" 
                                           value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                                           class="w-full pl-12 pr-4 py-3 rounded-xl border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition-all outline-none"
                                           placeholder="+880 1XXX-XXXXXX">
                                </div>
                            </div>

                            <div class="md:col-span-2">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Address
                                </label>
                                <div class="relative">
                                    <div class="absolute top-3 left-0 pl-4 flex items-center pointer-events-none">
                                        <i class="fas fa-map-marker-alt text-gray-400"></i>
                                    </div>
                                    <textarea name="address" 
                                              rows="3"
                                              class="w-full pl-12 pr-4 py-3 rounded-xl border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition-all outline-none resize-none"
                                              placeholder="Enter full address"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Employment Tab -->
                    <div id="content-employment" class="tab-content">
                        <div class="mb-6">
                            <h2 class="text-2xl font-bold text-gray-900 mb-2">Employment Information</h2>
                            <p class="text-gray-600">Set employee's position, department, and work details</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Department <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <select name="department_id" 
                                            id="departmentSelect"
                                            class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition-all outline-none appearance-none bg-white"
                                            onchange="loadPositions(this.value)">
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo $dept->id; ?>" <?php echo (isset($_POST['department_id']) && $_POST['department_id'] == $dept->id) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
                                        <i class="fas fa-chevron-down text-gray-400"></i>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Position <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <select name="position_id" 
                                            id="positionSelect"
                                            required
                                            class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition-all outline-none appearance-none bg-white">
                                        <option value="">Select Position</option>
                                        <?php foreach ($positions as $pos): ?>
                                            <option value="<?php echo $pos->id; ?>" 
                                                    data-department="<?php echo $pos->department_id; ?>"
                                                    <?php echo (isset($_POST['position_id']) && $_POST['position_id'] == $pos->id) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($pos->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
                                        <i class="fas fa-chevron-down text-gray-400"></i>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Hire Date <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <i class="fas fa-calendar text-gray-400"></i>
                                    </div>
                                    <input type="date" 
                                           name="hire_date" 
                                           value="<?php echo isset($_POST['hire_date']) ? $_POST['hire_date'] : date('Y-m-d'); ?>"
                                           required
                                           class="w-full pl-12 pr-4 py-3 rounded-xl border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition-all outline-none">
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Base Salary (৳)
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <span class="text-gray-400 font-semibold">৳</span>
                                    </div>
                                    <input type="number" 
                                           name="base_salary" 
                                           value="<?php echo isset($_POST['base_salary']) ? $_POST['base_salary'] : '0'; ?>"
                                           step="0.01"
                                           class="w-full pl-12 pr-4 py-3 rounded-xl border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition-all outline-none"
                                           placeholder="0.00">
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Employment Status <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <select name="status" 
                                            class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition-all outline-none appearance-none bg-white">
                                        <option value="active" <?php echo (isset($_POST['status']) && $_POST['status'] == 'active') ? 'selected' : 'selected'; ?>>Active</option>
                                        <option value="on_leave" <?php echo (isset($_POST['status']) && $_POST['status'] == 'on_leave') ? 'selected' : ''; ?>>On Leave</option>
                                        <option value="terminated" <?php echo (isset($_POST['status']) && $_POST['status'] == 'terminated') ? 'selected' : ''; ?>>Terminated</option>
                                    </select>
                                    <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
                                        <i class="fas fa-chevron-down text-gray-400"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Info Cards -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-8">
                            <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl p-6 border border-blue-200">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-blue-600 mb-1">Quick Tip</p>
                                        <p class="text-sm text-blue-900">Select department first</p>
                                    </div>
                                    <div class="h-12 w-12 bg-blue-500 bg-opacity-20 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-lightbulb text-2xl text-blue-600"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-xl p-6 border border-green-200">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-green-600 mb-1">Required Fields</p>
                                        <p class="text-sm text-green-900">Marked with *</p>
                                    </div>
                                    <div class="h-12 w-12 bg-green-500 bg-opacity-20 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-check-circle text-2xl text-green-600"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-xl p-6 border border-purple-200">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-purple-600 mb-1">Next Step</p>
                                        <p class="text-sm text-purple-900">Add salary details</p>
                                    </div>
                                    <div class="h-12 w-12 bg-purple-500 bg-opacity-20 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-arrow-right text-2xl text-purple-600"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Salary Structure Tab -->
                    <div id="content-salary" class="tab-content">
                        <div class="mb-6">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-900 mb-2">Salary Structure</h2>
                                    <p class="text-gray-600">Configure detailed salary breakdown and deductions (Optional)</p>
                                </div>
                                <label class="flex items-center space-x-3 cursor-pointer bg-indigo-50 px-4 py-2 rounded-xl border-2 border-indigo-200 hover:bg-indigo-100 transition-all">
                                    <input type="checkbox" 
                                           name="add_salary" 
                                           value="1" 
                                           id="addSalaryCheckbox"
                                           class="w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                                    <span class="text-sm font-semibold text-indigo-700">Add Salary Structure</span>
                                </label>
                            </div>
                        </div>

                        <div class="bg-amber-50 border-l-4 border-amber-500 p-4 mb-6 rounded-lg">
                            <div class="flex items-center">
                                <i class="fas fa-info-circle text-amber-500 text-xl mr-3"></i>
                                <div>
                                    <p class="text-amber-800 font-semibold">Optional Section</p>
                                    <p class="text-amber-700 text-sm mt-1">
                                        Check the box above to add salary structure now, or you can add it later from the employee profile.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Earnings Section -->
                        <div class="bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl p-6 mb-6 border border-green-200">
                            <div class="flex items-center mb-4">
                                <div class="h-10 w-10 bg-green-500 rounded-lg flex items-center justify-center mr-3">
                                    <i class="fas fa-plus-circle text-white text-xl"></i>
                                </div>
                                <h3 class="text-xl font-bold text-green-900">Earnings</h3>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-green-800 mb-2">
                                        Basic Salary (৳)
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-500 font-semibold">৳</span>
                                        </div>
                                        <input type="number" 
                                               name="basic_salary" 
                                               id="basicSalary"
                                               value="0"
                                               step="0.01"
                                               oninput="calculateSalary()"
                                               class="salary-input w-full pl-12 pr-4 py-3 rounded-xl border-2 border-green-300 focus:border-green-500 focus:ring-2 focus:ring-green-200 transition-all outline-none bg-white"
                                               placeholder="0.00">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-green-800 mb-2">
                                        House Allowance (৳)
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-500 font-semibold">৳</span>
                                        </div>
                                        <input type="number" 
                                               name="house_allowance" 
                                               id="houseAllowance"
                                               value="0"
                                               step="0.01"
                                               oninput="calculateSalary()"
                                               class="salary-input w-full pl-12 pr-4 py-3 rounded-xl border-2 border-green-300 focus:border-green-500 focus:ring-2 focus:ring-green-200 transition-all outline-none bg-white"
                                               placeholder="0.00">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-green-800 mb-2">
                                        Transport Allowance (৳)
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-500 font-semibold">৳</span>
                                        </div>
                                        <input type="number" 
                                               name="transport_allowance" 
                                               id="transportAllowance"
                                               value="0"
                                               step="0.01"
                                               oninput="calculateSalary()"
                                               class="salary-input w-full pl-12 pr-4 py-3 rounded-xl border-2 border-green-300 focus:border-green-500 focus:ring-2 focus:ring-green-200 transition-all outline-none bg-white"
                                               placeholder="0.00">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-green-800 mb-2">
                                        Medical Allowance (৳)
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-500 font-semibold">৳</span>
                                        </div>
                                        <input type="number" 
                                               name="medical_allowance" 
                                               id="medicalAllowance"
                                               value="0"
                                               step="0.01"
                                               oninput="calculateSalary()"
                                               class="salary-input w-full pl-12 pr-4 py-3 rounded-xl border-2 border-green-300 focus:border-green-500 focus:ring-2 focus:ring-green-200 transition-all outline-none bg-white"
                                               placeholder="0.00">
                                    </div>
                                </div>

                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-green-800 mb-2">
                                        Other Allowances (৳)
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-500 font-semibold">৳</span>
                                        </div>
                                        <input type="number" 
                                               name="other_allowances" 
                                               id="otherAllowances"
                                               value="0"
                                               step="0.01"
                                               oninput="calculateSalary()"
                                               class="salary-input w-full pl-12 pr-4 py-3 rounded-xl border-2 border-green-300 focus:border-green-500 focus:ring-2 focus:ring-green-200 transition-all outline-none bg-white"
                                               placeholder="0.00">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Deductions Section -->
                        <div class="bg-gradient-to-r from-red-50 to-rose-50 rounded-xl p-6 mb-6 border border-red-200">
                            <div class="flex items-center mb-4">
                                <div class="h-10 w-10 bg-red-500 rounded-lg flex items-center justify-center mr-3">
                                    <i class="fas fa-minus-circle text-white text-xl"></i>
                                </div>
                                <h3 class="text-xl font-bold text-red-900">Deductions</h3>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-red-800 mb-2">
                                        Provident Fund (৳)
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-500 font-semibold">৳</span>
                                        </div>
                                        <input type="number" 
                                               name="provident_fund" 
                                               id="providentFund"
                                               value="0"
                                               step="0.01"
                                               oninput="calculateSalary()"
                                               class="salary-input w-full pl-12 pr-4 py-3 rounded-xl border-2 border-red-300 focus:border-red-500 focus:ring-2 focus:ring-red-200 transition-all outline-none bg-white"
                                               placeholder="0.00">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-red-800 mb-2">
                                        Tax Deduction (৳)
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-500 font-semibold">৳</span>
                                        </div>
                                        <input type="number" 
                                               name="tax_deduction" 
                                               id="taxDeduction"
                                               value="0"
                                               step="0.01"
                                               oninput="calculateSalary()"
                                               class="salary-input w-full pl-12 pr-4 py-3 rounded-xl border-2 border-red-300 focus:border-red-500 focus:ring-2 focus:ring-red-200 transition-all outline-none bg-white"
                                               placeholder="0.00">
                                    </div>
                                </div>

                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-red-800 mb-2">
                                        Other Deductions (৳)
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-500 font-semibold">৳</span>
                                        </div>
                                        <input type="number" 
                                               name="other_deductions" 
                                               id="otherDeductions"
                                               value="0"
                                               step="0.01"
                                               oninput="calculateSalary()"
                                               class="salary-input w-full pl-12 pr-4 py-3 rounded-xl border-2 border-red-300 focus:border-red-500 focus:ring-2 focus:ring-red-200 transition-all outline-none bg-white"
                                               placeholder="0.00">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Salary Summary Cards -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="summary-card rounded-2xl p-6 text-white shadow-lg">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-white text-opacity-80 mb-2">Gross Salary</p>
                                        <p class="text-4xl font-bold" id="grossSalaryDisplay">৳0.00</p>
                                        <p class="text-xs text-white text-opacity-70 mt-2">Total Earnings</p>
                                    </div>
                                    <div class="h-16 w-16 bg-white bg-opacity-20 rounded-2xl flex items-center justify-center">
                                        <i class="fas fa-coins text-4xl text-white"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-gradient-to-r from-emerald-500 to-teal-600 rounded-2xl p-6 text-white shadow-lg hover:shadow-xl transition-all">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-white text-opacity-80 mb-2">Net Salary</p>
                                        <p class="text-4xl font-bold" id="netSalaryDisplay">৳0.00</p>
                                        <p class="text-xs text-white text-opacity-70 mt-2">After Deductions</p>
                                    </div>
                                    <div class="h-16 w-16 bg-white bg-opacity-20 rounded-2xl flex items-center justify-center">
                                        <i class="fas fa-wallet text-4xl text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Salary Breakdown Chart -->
                        <div class="mt-6 bg-white rounded-xl p-6 border-2 border-gray-200">
                            <h3 class="text-lg font-bold text-gray-900 mb-4">
                                <i class="fas fa-chart-pie text-indigo-600 mr-2"></i>
                                Salary Breakdown
                            </h3>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div class="text-center p-4 bg-green-50 rounded-lg border border-green-200">
                                    <p class="text-xs font-medium text-green-600 mb-1">Basic</p>
                                    <p class="text-lg font-bold text-green-900" id="basicDisplay">৳0.00</p>
                                </div>
                                <div class="text-center p-4 bg-blue-50 rounded-lg border border-blue-200">
                                    <p class="text-xs font-medium text-blue-600 mb-1">Allowances</p>
                                    <p class="text-lg font-bold text-blue-900" id="allowancesDisplay">৳0.00</p>
                                </div>
                                <div class="text-center p-4 bg-red-50 rounded-lg border border-red-200">
                                    <p class="text-xs font-medium text-red-600 mb-1">Deductions</p>
                                    <p class="text-lg font-bold text-red-900" id="deductionsDisplay">৳0.00</p>
                                </div>
                                <div class="text-center p-4 bg-purple-50 rounded-lg border border-purple-200">
                                    <p class="text-xs font-medium text-purple-600 mb-1">Net Pay</p>
                                    <p class="text-lg font-bold text-purple-900" id="netPayDisplay">৳0.00</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Tab Switching
function switchTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Remove active state from all tab buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('text-indigo-600', 'border-b-2', 'border-indigo-600', 'bg-indigo-50');
        button.classList.add('text-gray-600', 'hover:text-indigo-600', 'hover:bg-gray-50');
    });
    
    // Show selected tab content
    document.getElementById('content-' + tabName).classList.add('active');
    
    // Add active state to selected tab button
    const activeButton = document.getElementById('tab-' + tabName);
    activeButton.classList.remove('text-gray-600', 'hover:text-indigo-600', 'hover:bg-gray-50');
    activeButton.classList.add('text-indigo-600', 'border-b-2', 'border-indigo-600', 'bg-indigo-50');
}

// Profile Picture Preview
document.getElementById('profilePictureInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('profilePreview');
            preview.innerHTML = '<img src="' + e.target.result + '" alt="Profile" class="w-full h-full object-cover preview-image">';
        };
        reader.readAsDataURL(file);
    }
});

// Load Positions based on Department
function loadPositions(departmentId) {
    const positionSelect = document.getElementById('positionSelect');
    const options = positionSelect.querySelectorAll('option');
    
    // Reset position select
    positionSelect.value = '';
    
    // Show/hide positions based on department
    options.forEach(option => {
        if (option.value === '') {
            option.style.display = 'block';
        } else {
            const optionDept = option.getAttribute('data-department');
            if (departmentId === '' || optionDept === departmentId) {
                option.style.display = 'block';
            } else {
                option.style.display = 'none';
            }
        }
    });
}

// Real-time Salary Calculation
function calculateSalary() {
    // Get all input values
    const basicSalary = parseFloat(document.getElementById('basicSalary').value) || 0;
    const houseAllowance = parseFloat(document.getElementById('houseAllowance').value) || 0;
    const transportAllowance = parseFloat(document.getElementById('transportAllowance').value) || 0;
    const medicalAllowance = parseFloat(document.getElementById('medicalAllowance').value) || 0;
    const otherAllowances = parseFloat(document.getElementById('otherAllowances').value) || 0;
    
    const providentFund = parseFloat(document.getElementById('providentFund').value) || 0;
    const taxDeduction = parseFloat(document.getElementById('taxDeduction').value) || 0;
    const otherDeductions = parseFloat(document.getElementById('otherDeductions').value) || 0;
    
    // Calculate totals
    const totalAllowances = houseAllowance + transportAllowance + medicalAllowance + otherAllowances;
    const grossSalary = basicSalary + totalAllowances;
    const totalDeductions = providentFund + taxDeduction + otherDeductions;
    const netSalary = grossSalary - totalDeductions;
    
    // Update displays with animation
    updateDisplay('grossSalaryDisplay', grossSalary);
    updateDisplay('netSalaryDisplay', netSalary);
    updateDisplay('basicDisplay', basicSalary);
    updateDisplay('allowancesDisplay', totalAllowances);
    updateDisplay('deductionsDisplay', totalDeductions);
    updateDisplay('netPayDisplay', netSalary);
}

function updateDisplay(elementId, value) {
    const element = document.getElementById(elementId);
    const formatted = '৳' + value.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    element.textContent = formatted;
    
    // Add pulse animation
    element.style.transform = 'scale(1.05)';
    setTimeout(() => {
        element.style.transform = 'scale(1)';
    }, 200);
}

// Initialize salary calculation on page load
document.addEventListener('DOMContentLoaded', function() {
    calculateSalary();
    
    // Add transition to all displays
    const displays = ['grossSalaryDisplay', 'netSalaryDisplay', 'basicDisplay', 'allowancesDisplay', 'deductionsDisplay', 'netPayDisplay'];
    displays.forEach(id => {
        document.getElementById(id).style.transition = 'transform 0.2s ease';
    });
});

// Form validation
document.getElementById('addEmployeeForm').addEventListener('submit', function(e) {
    const addSalary = document.getElementById('addSalaryCheckbox').checked;
    
    if (addSalary) {
        const basicSalary = parseFloat(document.getElementById('basicSalary').value) || 0;
        
        if (basicSalary <= 0) {
            e.preventDefault();
            alert('Please enter a valid basic salary amount if you want to add salary structure.');
            switchTab('salary');
            document.getElementById('basicSalary').focus();
            return false;
        }
    }
});
</script>

<?php include_once '../templates/footer.php'; ?>


<?php
require_once '../../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Transport Manager'];
restrict_access($allowed_roles);

global $db;

// Get driver ID from URL
if (!isset($_GET['id'])) {
    $_SESSION['error_flash'] = 'Driver ID is required.';
    header('Location: index.php');
    exit();
}

$driver_id = (int)$_GET['id'];

// Get driver details with vehicle and branch info
$driver = $db->query("
    SELECT d.*, 
           v.vehicle_number, v.vehicle_type, v.category as vehicle_category,
           b.name as branch_name
    FROM drivers d
    LEFT JOIN vehicles v ON d.assigned_vehicle_id = v.id
    LEFT JOIN branches b ON d.assigned_branch_id = b.id
    WHERE d.id = ?
", [$driver_id])->first();

if (!$driver) {
    $_SESSION['error_flash'] = 'Driver not found.';
    header('Location: index.php');
    exit();
}

$pageTitle = 'Driver Profile: ' . htmlspecialchars($driver->driver_name);

// Get driver's documents
$documents = $db->query("
    SELECT * FROM driver_documents 
    WHERE driver_id = ? 
    ORDER BY expiry_date ASC
", [$driver_id])->results();

// Count documents by status
$today = date('Y-m-d');
$warning_date = date('Y-m-d', strtotime('+30 days'));
$expired_docs = 0;
$expiring_soon_docs = 0;

foreach ($documents as $doc) {
    if ($doc->expiry_date) {
        if ($doc->expiry_date < $today) {
            $expired_docs++;
        } elseif ($doc->expiry_date <= $warning_date) {
            $expiring_soon_docs++;
        }
    }
}

// Get recent attendance (last 30 days)
$attendance_records = $db->query("
    SELECT * FROM driver_attendance 
    WHERE driver_id = ? 
    AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ORDER BY attendance_date DESC
    LIMIT 10
", [$driver_id])->results();

// Calculate age if date_of_birth exists
$age = null;
if ($driver->date_of_birth) {
    $dob = new DateTime($driver->date_of_birth);
    $now = new DateTime();
    $age = $now->diff($dob)->y;
}

// Calculate years of service if join_date exists
$years_of_service = null;
if ($driver->join_date) {
    $join = new DateTime($driver->join_date);
    $now = new DateTime();
    $years_of_service = $now->diff($join)->y;
}

require_once '../../templates/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    
    <!-- Header -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <div class="flex justify-between items-start">
            <div class="flex items-start gap-4">
                <div class="w-20 h-20 bg-blue-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-user text-blue-600 text-3xl"></i>
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">
                        <?php echo htmlspecialchars($driver->driver_name); ?>
                    </h1>
                    <div class="flex items-center gap-3 mt-2">
                        <span class="px-3 py-1 rounded-full text-sm font-medium
                            <?php echo $driver->driver_type === 'Permanent' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'; ?>">
                            <i class="fas fa-user-tag"></i> <?php echo htmlspecialchars($driver->driver_type); ?>
                        </span>
                        <span class="px-3 py-1 rounded-full text-sm font-medium
                            <?php 
                                echo $driver->status === 'Active' ? 'bg-green-100 text-green-700' : 
                                    ($driver->status === 'On Leave' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-700');
                            ?>">
                            <i class="fas fa-circle text-xs"></i> <?php echo htmlspecialchars($driver->status); ?>
                        </span>
                        <?php if ($driver->rating): ?>
                        <span class="px-3 py-1 bg-yellow-100 text-yellow-700 rounded-full text-sm font-medium">
                            <i class="fas fa-star"></i> <?php echo number_format($driver->rating, 1); ?>/5.0
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="manage.php?id=<?php echo $driver_id; ?>" 
                   class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-edit mr-2"></i>Edit
                </a>
                <a href="index.php" class="px-4 py-2 border rounded-lg hover:bg-gray-50">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </a>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm">Total Trips</p>
                    <p class="text-3xl font-bold text-gray-900"><?php echo number_format($driver->total_trips); ?></p>
                </div>
                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-route text-blue-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm">Documents</p>
                    <p class="text-3xl font-bold text-gray-900"><?php echo count($documents); ?></p>
                    <?php if ($expired_docs > 0): ?>
                        <p class="text-xs text-red-600 mt-1"><?php echo $expired_docs; ?> expired</p>
                    <?php elseif ($expiring_soon_docs > 0): ?>
                        <p class="text-xs text-yellow-600 mt-1"><?php echo $expiring_soon_docs; ?> expiring soon</p>
                    <?php endif; ?>
                </div>
                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-file-alt text-green-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm">Monthly Salary</p>
                    <p class="text-3xl font-bold text-gray-900">
                        <?php echo $driver->salary ? '৳' . number_format($driver->salary) : 'N/A'; ?>
                    </p>
                    <?php if ($driver->daily_rate): ?>
                        <p class="text-xs text-gray-600 mt-1">Daily: ৳<?php echo number_format($driver->daily_rate); ?></p>
                    <?php endif; ?>
                </div>
                <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-money-bill-wave text-purple-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm">Experience</p>
                    <p class="text-3xl font-bold text-gray-900">
                        <?php echo $years_of_service !== null ? $years_of_service . 'y' : 'N/A'; ?>
                    </p>
                    <?php if ($driver->join_date): ?>
                        <p class="text-xs text-gray-600 mt-1">Since <?php echo date('M Y', strtotime($driver->join_date)); ?></p>
                    <?php endif; ?>
                </div>
                <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-calendar-check text-yellow-600 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Left Column: Personal & Contact Information -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- Personal Information -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-user-circle mr-2 text-blue-600"></i>
                    Personal Information
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-medium text-gray-500">Phone Number</label>
                        <p class="text-gray-900">
                            <i class="fas fa-phone text-gray-400 mr-2"></i>
                            <?php echo htmlspecialchars($driver->phone_number); ?>
                        </p>
                    </div>
                    <?php if ($driver->email): ?>
                    <div>
                        <label class="text-sm font-medium text-gray-500">Email</label>
                        <p class="text-gray-900">
                            <i class="fas fa-envelope text-gray-400 mr-2"></i>
                            <?php echo htmlspecialchars($driver->email); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    <?php if ($driver->nid_number): ?>
                    <div>
                        <label class="text-sm font-medium text-gray-500">NID Number</label>
                        <p class="text-gray-900">
                            <i class="fas fa-id-card text-gray-400 mr-2"></i>
                            <?php echo htmlspecialchars($driver->nid_number); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    <?php if ($driver->date_of_birth): ?>
                    <div>
                        <label class="text-sm font-medium text-gray-500">Date of Birth</label>
                        <p class="text-gray-900">
                            <i class="fas fa-birthday-cake text-gray-400 mr-2"></i>
                            <?php echo date('d M Y', strtotime($driver->date_of_birth)); ?>
                            <?php if ($age): ?>
                                <span class="text-sm text-gray-500">(<?php echo $age; ?> years)</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    <?php if ($driver->address): ?>
                    <div class="md:col-span-2">
                        <label class="text-sm font-medium text-gray-500">Address</label>
                        <p class="text-gray-900">
                            <i class="fas fa-map-marker-alt text-gray-400 mr-2"></i>
                            <?php echo htmlspecialchars($driver->address); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- License Information -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-id-card-alt mr-2 text-blue-600"></i>
                    License Information
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php if ($driver->license_number): ?>
                    <div>
                        <label class="text-sm font-medium text-gray-500">License Number</label>
                        <p class="text-gray-900 font-mono">
                            <?php echo htmlspecialchars($driver->license_number); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    <?php if ($driver->license_type): ?>
                    <div>
                        <label class="text-sm font-medium text-gray-500">License Type</label>
                        <p class="text-gray-900">
                            <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-sm">
                                <?php echo htmlspecialchars($driver->license_type); ?>
                            </span>
                        </p>
                    </div>
                    <?php endif; ?>
                    <?php if ($driver->license_issue_date): ?>
                    <div>
                        <label class="text-sm font-medium text-gray-500">Issue Date</label>
                        <p class="text-gray-900">
                            <?php echo date('d M Y', strtotime($driver->license_issue_date)); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    <?php if ($driver->license_expiry_date): ?>
                    <div>
                        <label class="text-sm font-medium text-gray-500">Expiry Date</label>
                        <p class="text-gray-900">
                            <?php 
                            $expiry = strtotime($driver->license_expiry_date);
                            $is_expired = $expiry < strtotime($today);
                            $is_expiring_soon = $expiry >= strtotime($today) && $expiry <= strtotime($warning_date);
                            ?>
                            <span class="<?php echo $is_expired ? 'text-red-600 font-semibold' : ($is_expiring_soon ? 'text-yellow-600 font-semibold' : ''); ?>">
                                <?php echo date('d M Y', $expiry); ?>
                                <?php if ($is_expired): ?>
                                    <i class="fas fa-exclamation-circle ml-1"></i> Expired
                                <?php elseif ($is_expiring_soon): ?>
                                    <i class="fas fa-exclamation-triangle ml-1"></i> Expiring Soon
                                <?php endif; ?>
                            </span>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Emergency Contact -->
            <?php if ($driver->emergency_contact_name || $driver->emergency_contact_phone): ?>
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-phone-square-alt mr-2 text-red-600"></i>
                    Emergency Contact
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php if ($driver->emergency_contact_name): ?>
                    <div>
                        <label class="text-sm font-medium text-gray-500">Contact Name</label>
                        <p class="text-gray-900">
                            <?php echo htmlspecialchars($driver->emergency_contact_name); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    <?php if ($driver->emergency_contact_phone): ?>
                    <div>
                        <label class="text-sm font-medium text-gray-500">Contact Phone</label>
                        <p class="text-gray-900">
                            <i class="fas fa-phone text-gray-400 mr-2"></i>
                            <?php echo htmlspecialchars($driver->emergency_contact_phone); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Notes -->
            <?php if ($driver->notes): ?>
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-sticky-note mr-2 text-yellow-600"></i>
                    Notes
                </h2>
                <p class="text-gray-700"><?php echo nl2br(htmlspecialchars($driver->notes)); ?></p>
            </div>
            <?php endif; ?>

        </div>

        <!-- Right Column: Assignment & Documents -->
        <div class="space-y-6">
            
            <!-- Vehicle Assignment -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-truck mr-2 text-blue-600"></i>
                    Assignment
                </h2>
                
                <?php if ($driver->vehicle_number): ?>
                    <div class="border-l-4 border-blue-500 pl-4 mb-4">
                        <label class="text-sm font-medium text-gray-500">Assigned Vehicle</label>
                        <p class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($driver->vehicle_number); ?></p>
                        <p class="text-sm text-gray-600">
                            <?php echo htmlspecialchars($driver->vehicle_category); ?> 
                            <span class="mx-1">•</span>
                            <?php echo htmlspecialchars($driver->vehicle_type); ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-truck-moving text-gray-300 text-4xl mb-2"></i>
                        <p class="text-gray-500">No vehicle assigned</p>
                    </div>
                <?php endif; ?>

                <?php if ($driver->branch_name): ?>
                    <div class="border-l-4 border-green-500 pl-4">
                        <label class="text-sm font-medium text-gray-500">Assigned Branch</label>
                        <p class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($driver->branch_name); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Documents Summary -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-file-alt mr-2 text-blue-600"></i>
                        Documents
                    </h2>
                    <a href="documents.php?driver_id=<?php echo $driver_id; ?>" 
                       class="text-blue-600 hover:text-blue-700 text-sm font-medium">
                        Manage <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>

                <?php if (empty($documents)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-folder-open text-gray-300 text-4xl mb-2"></i>
                        <p class="text-gray-500">No documents added</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach (array_slice($documents, 0, 5) as $doc): 
                            $is_expired = $doc->expiry_date && $doc->expiry_date < $today;
                            $is_expiring_soon = $doc->expiry_date && $doc->expiry_date >= $today && $doc->expiry_date <= $warning_date;
                        ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                <div class="flex-1">
                                    <p class="font-medium text-gray-900 text-sm">
                                        <?php echo htmlspecialchars($doc->document_type); ?>
                                    </p>
                                    <?php if ($doc->expiry_date): ?>
                                        <p class="text-xs <?php echo $is_expired ? 'text-red-600' : ($is_expiring_soon ? 'text-yellow-600' : 'text-gray-500'); ?>">
                                            Expires: <?php echo date('d M Y', strtotime($doc->expiry_date)); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <?php if ($is_expired): ?>
                                    <span class="px-2 py-1 bg-red-100 text-red-700 text-xs rounded-full">
                                        Expired
                                    </span>
                                <?php elseif ($is_expiring_soon): ?>
                                    <span class="px-2 py-1 bg-yellow-100 text-yellow-700 text-xs rounded-full">
                                        Soon
                                    </span>
                                <?php else: ?>
                                    <span class="text-green-600">
                                        <i class="fas fa-check-circle"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if (count($documents) > 5): ?>
                            <a href="documents.php?driver_id=<?php echo $driver_id; ?>" 
                               class="block text-center text-blue-600 hover:text-blue-700 text-sm font-medium mt-2">
                                View all <?php echo count($documents); ?> documents
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Quick Actions -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-bolt mr-2 text-yellow-600"></i>
                    Quick Actions
                </h2>
                <div class="space-y-2">
                    <a href="documents.php?driver_id=<?php echo $driver_id; ?>" 
                       class="block w-full px-4 py-2 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 text-center">
                        <i class="fas fa-file-alt mr-2"></i>Manage Documents
                    </a>
                    <a href="manage.php?id=<?php echo $driver_id; ?>" 
                       class="block w-full px-4 py-2 bg-green-50 text-green-700 rounded-lg hover:bg-green-100 text-center">
                        <i class="fas fa-edit mr-2"></i>Edit Information
                    </a>
                </div>
            </div>

        </div>
    </div>

</div>

<?php require_once '../../templates/footer.php'; ?>
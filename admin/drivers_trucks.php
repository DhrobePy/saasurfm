<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id     = $currentUser['id'] ?? null;
$csrfToken   = $_SESSION['csrf_token'] ?? '';
$success = null;
$error   = null;

// ── Migrate existing `drivers` table: add our new columns if missing ──────────
// Convert driver_type to VARCHAR so we can store 'Salaried'/'Contractual'/'Helper'
// alongside legacy 'Permanent'/'Contractual' values. Safe op on VARCHAR too.
try {
    $db->getPdo()->exec("ALTER TABLE `drivers` MODIFY COLUMN `driver_type` VARCHAR(100) NULL DEFAULT 'Salaried'");
} catch (\Throwable $e) { /* column may already be VARCHAR – ignore */ }

$missingCols = [
    "ALTER TABLE `drivers` ADD COLUMN `license_number`          VARCHAR(100) NULL",
    "ALTER TABLE `drivers` ADD COLUMN `emergency_contact_name`  VARCHAR(200) NULL",
    "ALTER TABLE `drivers` ADD COLUMN `emergency_contact_phone` VARCHAR(50)  NULL",
    "ALTER TABLE `drivers` ADD COLUMN `notes`                   TEXT         NULL",
    "ALTER TABLE `drivers` ADD COLUMN `created_by_user_id`      INT          NULL",
];
foreach ($missingCols as $sql) {
    try { $db->getPdo()->exec($sql); } catch (\Throwable $e) { /* column exists */ }
}

// ── Create fleet_vehicles if not already there ────────────────────────────────
$db->getPdo()->exec("
    CREATE TABLE IF NOT EXISTS `fleet_vehicles` (
        `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `registration_number` VARCHAR(100) NOT NULL,
        `vehicle_type`        ENUM('mini_truck','boro_truck','car') NOT NULL DEFAULT 'mini_truck',
        `ownership`           ENUM('own','rented') NOT NULL DEFAULT 'own',
        `status`              ENUM('active','inactive') NOT NULL DEFAULT 'active',
        `notes`               TEXT NULL,
        `created_by_user_id`  INT NULL,
        `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed. Please refresh and try again.";
    } else {
        $action = $_POST['action'] ?? '';

        // ── Save driver ──────────────────────────────────────────────────────
        if ($action === 'save_driver') {
            $driver_id  = (int)($_POST['driver_id'] ?? 0);
            $dname      = trim($_POST['driver_name'] ?? '');
            $phone      = trim($_POST['phone_number'] ?? '');
            $license    = trim($_POST['license_number'] ?? '');
            $em_name    = trim($_POST['emergency_contact_name'] ?? '');
            $em_phone   = trim($_POST['emergency_contact_phone'] ?? '');
            $dtype      = trim($_POST['driver_type'] ?? 'Salaried');
            $status     = $_POST['status'] ?? 'Active';
            $notes      = trim($_POST['notes'] ?? '');

            if ($dname === '') {
                $error = "Driver name is required.";
            } else {
                $allowed_types   = ['Salaried','Contractual','Helper'];
                $allowed_status  = ['Active','Inactive'];
                if (!in_array($dtype,  $allowed_types))  $dtype  = 'Salaried';
                if (!in_array($status, $allowed_status)) $status = 'Active';

                if ($driver_id > 0) {
                    $db->query(
                        "UPDATE drivers SET driver_name=?, phone_number=?, license_number=?,
                                emergency_contact_name=?, emergency_contact_phone=?,
                                driver_type=?, status=?, notes=? WHERE id=?",
                        [$dname, $phone ?: null, $license ?: null, $em_name ?: null,
                         $em_phone ?: null, $dtype, $status, $notes ?: null, $driver_id]
                    );
                    $success = "Driver updated successfully.";
                } else {
                    $db->insert('drivers', [
                        'driver_name'             => $dname,
                        'phone_number'            => $phone ?: null,
                        'license_number'          => $license ?: null,
                        'emergency_contact_name'  => $em_name ?: null,
                        'emergency_contact_phone' => $em_phone ?: null,
                        'driver_type'             => $dtype,
                        'status'                  => $status,
                        'notes'                   => $notes ?: null,
                        'created_by_user_id'      => $user_id,
                    ]);
                    $success = "Driver added successfully.";
                }
            }

        // ── Save vehicle ─────────────────────────────────────────────────────
        } elseif ($action === 'save_vehicle') {
            $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
            $reg        = trim($_POST['registration_number'] ?? '');
            $vtype      = $_POST['vehicle_type'] ?? 'mini_truck';
            $ownership  = $_POST['ownership'] ?? 'own';
            $status     = $_POST['status'] ?? 'active';
            $notes      = trim($_POST['notes'] ?? '');

            if ($reg === '') {
                $error = "Registration number is required.";
            } else {
                if (!in_array($vtype,     ['mini_truck','boro_truck','car'])) $vtype     = 'mini_truck';
                if (!in_array($ownership, ['own','rented']))                   $ownership = 'own';
                if (!in_array($status,    ['active','inactive']))              $status    = 'active';

                if ($vehicle_id > 0) {
                    $db->query(
                        "UPDATE fleet_vehicles SET registration_number=?, vehicle_type=?, ownership=?, status=?, notes=? WHERE id=?",
                        [$reg, $vtype, $ownership, $status, $notes ?: null, $vehicle_id]
                    );
                    $success = "Vehicle updated successfully.";
                } else {
                    $db->insert('fleet_vehicles', [
                        'registration_number' => $reg,
                        'vehicle_type'        => $vtype,
                        'ownership'           => $ownership,
                        'status'              => $status,
                        'notes'               => $notes ?: null,
                        'created_by_user_id'  => $user_id,
                    ]);
                    $success = "Vehicle added successfully.";
                }
            }

        // ── Toggle driver ────────────────────────────────────────────────────
        } elseif ($action === 'toggle_driver') {
            $driver_id  = (int)($_POST['driver_id'] ?? 0);
            $new_status = $_POST['new_status'] ?? 'Inactive';
            if ($driver_id > 0 && in_array($new_status, ['Active','Inactive'])) {
                $db->query("UPDATE drivers SET status=? WHERE id=?", [$new_status, $driver_id]);
                $success = "Driver " . ($new_status === 'Active' ? 'activated' : 'deactivated') . ".";
            }

        // ── Toggle vehicle ───────────────────────────────────────────────────
        } elseif ($action === 'toggle_vehicle') {
            $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
            $new_status = $_POST['new_status'] ?? 'inactive';
            if ($vehicle_id > 0 && in_array($new_status, ['active','inactive'])) {
                $db->query("UPDATE fleet_vehicles SET status=? WHERE id=?", [$new_status, $vehicle_id]);
                $success = "Vehicle " . ($new_status === 'active' ? 'activated' : 'deactivated') . ".";
            }
        }
    }
}

// ── View mode ─────────────────────────────────────────────────────────────────
$driver_id_view = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;
$active_tab     = $_GET['tab'] ?? 'drivers';

// ── Fetch data ────────────────────────────────────────────────────────────────
$drivers = $db->query(
    "SELECT d.*, u.display_name AS added_by
     FROM drivers d
     LEFT JOIN users u ON u.id = d.created_by_user_id
     ORDER BY (d.status = 'Inactive') ASC, d.driver_name ASC"
)->results();

$vehicles = $db->query(
    "SELECT fv.*, u.display_name AS added_by
     FROM fleet_vehicles fv
     LEFT JOIN users u ON u.id = fv.created_by_user_id
     ORDER BY fv.status = 'inactive' ASC, fv.registration_number ASC"
)->results();

// ── Driver profile + trips ────────────────────────────────────────────────────
$driver_profile = null;
$driver_trips   = [];
if ($driver_id_view > 0) {
    $driver_profile = $db->query("SELECT * FROM drivers WHERE id = ?", [$driver_id_view])->first();
    if ($driver_profile) {
        $driver_trips = $db->query(
            "SELECT co.id AS order_id, co.order_number, co.order_date, co.total_amount,
                    co.balance_due, co.status AS order_status,
                    c.name AS customer_name, c.phone_number AS customer_phone,
                    cos.shipped_date, cos.truck_number, cos.driver_contact,
                    cos.delivered_date
             FROM credit_order_shipping cos
             JOIN credit_orders co ON co.id = cos.order_id
             JOIN customers c ON c.id = co.customer_id
             WHERE cos.driver_name = ?
             ORDER BY cos.shipped_date DESC, co.id DESC",
            [$driver_profile->driver_name]
        )->results();
    }
}

// ── Display helpers ───────────────────────────────────────────────────────────
$driverTypeColor = [
    'Salaried'    => 'bg-green-100 text-green-800',
    'Permanent'   => 'bg-green-100 text-green-800',
    'Contractual' => 'bg-blue-100 text-blue-800',
    'Helper'      => 'bg-yellow-100 text-yellow-800',
];
$vehicleTypeLabel = ['mini_truck' => 'Mini Truck', 'boro_truck' => 'Boro Truck', 'car' => 'Car / Own Vehicle'];
$vehicleTypeColor = [
    'mini_truck' => 'bg-orange-100 text-orange-800',
    'boro_truck' => 'bg-blue-100 text-blue-800',
    'car'        => 'bg-gray-100 text-gray-700',
];
$ownershipColor = ['own' => 'bg-emerald-100 text-emerald-800', 'rented' => 'bg-purple-100 text-purple-800'];

$pageTitle = $driver_profile ? 'Driver: ' . $driver_profile->driver_name : 'Drivers & Trucks';
require_once '../templates/header.php';
?>

<?php if ($driver_profile): ?>
<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- DRIVER PROFILE VIEW                                                 -->
<!-- ═══════════════════════════════════════════════════════════════════ -->

<div class="flex items-center gap-3 mb-6">
    <a href="drivers_trucks.php?tab=drivers"
       class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white hover:bg-gray-50">
        <i class="fas fa-arrow-left mr-2"></i>Back to Fleet
    </a>
    <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
        <i class="fas fa-id-card text-indigo-600"></i>
        <?php echo htmlspecialchars($driver_profile->driver_name); ?>
        <?php $dtype = $driver_profile->driver_type ?? ''; ?>
        <span class="text-sm font-medium px-2.5 py-0.5 rounded-full <?php echo $driverTypeColor[$dtype] ?? 'bg-gray-100 text-gray-700'; ?>">
            <?php echo htmlspecialchars($dtype ?: 'Driver'); ?>
        </span>
        <?php if (($driver_profile->status ?? '') === 'Inactive'): ?>
        <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-red-100 text-red-700">Inactive</span>
        <?php endif; ?>
    </h1>
</div>

<!-- Driver info cards -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
        <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-4">Driver Details</h3>
        <dl class="space-y-3">
            <div class="flex gap-3">
                <dt class="w-36 flex-shrink-0 text-sm text-gray-500 flex items-center gap-2"><i class="fas fa-phone w-4 text-gray-300"></i>Phone</dt>
                <dd class="text-sm font-semibold text-gray-800"><?php echo $driver_profile->phone_number ? htmlspecialchars($driver_profile->phone_number) : '<span class="text-gray-400 font-normal">—</span>'; ?></dd>
            </div>
            <div class="flex gap-3">
                <dt class="w-36 flex-shrink-0 text-sm text-gray-500 flex items-center gap-2"><i class="fas fa-id-card w-4 text-gray-300"></i>License No.</dt>
                <dd class="text-sm font-semibold text-gray-800"><?php echo $driver_profile->license_number ? htmlspecialchars($driver_profile->license_number) : '<span class="text-gray-400 font-normal">—</span>'; ?></dd>
            </div>
            <div class="flex gap-3">
                <dt class="w-36 flex-shrink-0 text-sm text-gray-500 flex items-center gap-2"><i class="fas fa-tag w-4 text-gray-300"></i>Type</dt>
                <dd class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($driver_profile->driver_type ?? '—'); ?></dd>
            </div>
            <?php if (!empty($driver_profile->notes)): ?>
            <div class="flex gap-3">
                <dt class="w-36 flex-shrink-0 text-sm text-gray-500 flex items-center gap-2"><i class="fas fa-sticky-note w-4 text-gray-300"></i>Notes</dt>
                <dd class="text-sm text-gray-700"><?php echo nl2br(htmlspecialchars($driver_profile->notes)); ?></dd>
            </div>
            <?php endif; ?>
        </dl>
    </div>

    <div class="bg-red-50 rounded-xl border border-red-200 p-5">
        <h3 class="text-xs font-bold text-red-600 uppercase tracking-wider mb-4 flex items-center gap-2">
            <i class="fas fa-phone-alt"></i> Emergency Contact (In Case of Accident)
        </h3>
        <?php if (!empty($driver_profile->emergency_contact_name) || !empty($driver_profile->emergency_contact_phone)): ?>
        <dl class="space-y-3">
            <div class="flex gap-3">
                <dt class="w-28 flex-shrink-0 text-sm text-red-500">Name</dt>
                <dd class="text-sm font-bold text-red-800"><?php echo htmlspecialchars($driver_profile->emergency_contact_name ?? '—'); ?></dd>
            </div>
            <div class="flex gap-3">
                <dt class="w-28 flex-shrink-0 text-sm text-red-500">Phone</dt>
                <dd class="text-sm font-bold text-red-800"><?php echo htmlspecialchars($driver_profile->emergency_contact_phone ?? '—'); ?></dd>
            </div>
        </dl>
        <?php else: ?>
        <p class="text-sm text-red-400 italic">No emergency contact recorded.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Trip history -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-200 flex items-center gap-3">
        <h3 class="text-base font-bold text-gray-800 flex items-center gap-2">
            <i class="fas fa-route text-indigo-500"></i> Trip History
        </h3>
        <span class="text-sm font-semibold text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full"><?php echo count($driver_trips); ?></span>
    </div>

    <?php if (empty($driver_trips)): ?>
    <div class="px-5 py-12 text-center text-gray-400">
        <i class="fas fa-route text-4xl mb-3 opacity-30"></i>
        <p class="font-medium">No trips recorded yet.</p>
        <p class="text-sm mt-1">Trips appear here based on driver name matching in dispatch records.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 text-left">#</th>
                    <th class="px-4 py-3 text-left">Order</th>
                    <th class="px-4 py-3 text-left">Customer</th>
                    <th class="px-4 py-3 text-left">Truck</th>
                    <th class="px-4 py-3 text-left">Shipped</th>
                    <th class="px-4 py-3 text-left">Delivered</th>
                    <th class="px-4 py-3 text-right">Amount (৳)</th>
                    <th class="px-4 py-3 text-right">Due (৳)</th>
                    <th class="px-4 py-3 text-center">Status</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
                <?php $sn = 0; foreach ($driver_trips as $trip): $sn++; ?>
                <?php
                $status_color = match($trip->order_status) {
                    'delivered' => 'bg-green-100 text-green-700',
                    'shipped'   => 'bg-blue-100 text-blue-700',
                    'cancelled' => 'bg-red-100 text-red-700',
                    default     => 'bg-gray-100 text-gray-600',
                };
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-400"><?php echo $sn; ?></td>
                    <td class="px-4 py-3">
                        <a href="<?= url('cr/credit_invoice_print.php?id=' . $trip->order_id) ?>"
                           target="_blank"
                           class="font-semibold text-indigo-600 hover:text-indigo-800 hover:underline">
                            <?php echo htmlspecialchars($trip->order_number); ?>
                        </a>
                        <div class="text-xs text-gray-400"><?php echo date('d M Y', strtotime($trip->order_date)); ?></div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-800"><?php echo htmlspecialchars($trip->customer_name); ?></div>
                        <?php if ($trip->customer_phone): ?>
                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($trip->customer_phone); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-gray-600 font-mono text-xs"><?php echo htmlspecialchars($trip->truck_number ?? '—'); ?></td>
                    <td class="px-4 py-3 text-gray-600 text-xs"><?php echo $trip->shipped_date ? date('d M Y', strtotime($trip->shipped_date)) : '—'; ?></td>
                    <td class="px-4 py-3 text-gray-600 text-xs"><?php echo $trip->delivered_date ? date('d M Y', strtotime($trip->delivered_date)) : '—'; ?></td>
                    <td class="px-4 py-3 text-right font-mono text-gray-800"><?php echo number_format((float)$trip->total_amount, 2); ?></td>
                    <td class="px-4 py-3 text-right font-mono <?php echo (float)$trip->balance_due > 0 ? 'text-red-600 font-bold' : 'text-green-600'; ?>">
                        <?php echo number_format((float)$trip->balance_due, 2); ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold <?php echo $status_color; ?>">
                            <?php echo ucfirst($trip->order_status); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-gray-50 font-semibold text-sm">
                <tr>
                    <td colspan="6" class="px-4 py-3 text-right text-gray-600">Total — <?php echo count($driver_trips); ?> trips:</td>
                    <td class="px-4 py-3 text-right font-mono text-gray-800">৳<?php echo number_format(array_sum(array_column($driver_trips, 'total_amount')), 2); ?></td>
                    <td class="px-4 py-3 text-right font-mono text-red-600">৳<?php echo number_format(array_sum(array_column($driver_trips, 'balance_due')), 2); ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- MAIN LIST VIEW                                                       -->
<!-- ═══════════════════════════════════════════════════════════════════ -->

<?php if ($success): ?>
<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg mb-5 flex items-center gap-3">
    <i class="fas fa-check-circle text-green-500"></i> <?php echo htmlspecialchars($success); ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-5 flex items-center gap-3">
    <i class="fas fa-exclamation-circle text-red-500"></i> <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<!-- Page header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
            <i class="fas fa-truck text-indigo-600"></i> Drivers & Trucks
        </h1>
        <p class="text-sm text-gray-500 mt-0.5">Manage your fleet drivers and vehicles</p>
    </div>
    <div class="flex gap-2">
        <button onclick="openDriverModal()"
                class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 transition">
            <i class="fas fa-user-plus mr-2"></i>Add Driver
        </button>
        <button onclick="openVehicleModal()"
                class="inline-flex items-center px-4 py-2 bg-orange-600 text-white text-sm font-semibold rounded-lg hover:bg-orange-700 transition">
            <i class="fas fa-plus mr-2"></i>Add Vehicle
        </button>
    </div>
</div>

<!-- Tabs -->
<div class="border-b border-gray-200 mb-5">
    <nav class="flex gap-6">
        <button onclick="switchTab('drivers')" id="tab-drivers"
                class="tab-btn pb-3 text-sm font-semibold border-b-2 transition <?php echo $active_tab !== 'trucks' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
            <i class="fas fa-id-badge mr-1.5"></i>Drivers
            <span class="ml-1.5 bg-gray-100 text-gray-600 text-xs font-bold px-1.5 py-0.5 rounded-full"><?php echo count($drivers); ?></span>
        </button>
        <button onclick="switchTab('trucks')" id="tab-trucks"
                class="tab-btn pb-3 text-sm font-semibold border-b-2 transition <?php echo $active_tab === 'trucks' ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
            <i class="fas fa-truck mr-1.5"></i>Trucks & Vehicles
            <span class="ml-1.5 bg-gray-100 text-gray-600 text-xs font-bold px-1.5 py-0.5 rounded-full"><?php echo count($vehicles); ?></span>
        </button>
    </nav>
</div>

<!-- ── Drivers panel ────────────────────────────────────────────────── -->
<div id="panel-drivers" class="<?php echo $active_tab === 'trucks' ? 'hidden' : ''; ?>">
    <?php if (empty($drivers)): ?>
    <div class="bg-white rounded-xl border border-gray-200 py-16 text-center">
        <i class="fas fa-id-badge text-5xl text-gray-200 mb-4"></i>
        <p class="font-semibold text-gray-500">No drivers added yet.</p>
        <button onclick="openDriverModal()" class="mt-4 px-5 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700">
            <i class="fas fa-user-plus mr-2"></i>Add First Driver
        </button>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php foreach ($drivers as $d): ?>
        <?php $inactive = ($d->status ?? '') === 'Inactive'; ?>
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition overflow-hidden <?php echo $inactive ? 'opacity-60' : ''; ?>">
            <!-- Header -->
            <div class="px-4 pt-4 pb-3 border-b border-gray-100 flex items-start gap-3">
                <div class="flex-shrink-0 w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center">
                    <i class="fas fa-user text-indigo-600"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <a href="drivers_trucks.php?driver_id=<?php echo $d->id; ?>"
                           class="font-bold text-gray-900 hover:text-indigo-700 truncate">
                            <?php echo htmlspecialchars($d->driver_name); ?>
                        </a>
                        <?php $dt = $d->driver_type ?? ''; ?>
                        <span class="text-xs px-2 py-0.5 rounded-full font-semibold <?php echo $driverTypeColor[$dt] ?? 'bg-gray-100 text-gray-600'; ?>">
                            <?php echo htmlspecialchars($dt ?: 'Driver'); ?>
                        </span>
                        <?php if ($inactive): ?>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-600 font-semibold">Inactive</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($d->phone_number): ?>
                    <p class="text-sm text-gray-500 mt-0.5 flex items-center gap-1.5">
                        <i class="fas fa-phone text-gray-300"></i><?php echo htmlspecialchars($d->phone_number); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Body -->
            <div class="px-4 py-3 space-y-1.5">
                <?php if ($d->license_number): ?>
                <div class="flex items-center gap-2 text-sm">
                    <i class="fas fa-id-card text-gray-300 w-4"></i>
                    <span class="text-gray-500">License:</span>
                    <span class="font-medium text-gray-800"><?php echo htmlspecialchars($d->license_number); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($d->emergency_contact_name) || !empty($d->emergency_contact_phone)): ?>
                <div class="flex items-start gap-2 text-sm bg-red-50 rounded-lg px-2.5 py-1.5">
                    <i class="fas fa-phone-alt text-red-400 w-4 mt-0.5 flex-shrink-0"></i>
                    <div class="min-w-0">
                        <span class="text-red-500 text-xs font-semibold">Emergency:</span>
                        <?php if ($d->emergency_contact_name): ?>
                        <span class="text-red-700 ml-1"><?php echo htmlspecialchars($d->emergency_contact_name); ?></span>
                        <?php endif; ?>
                        <?php if ($d->emergency_contact_phone): ?>
                        <span class="text-red-600 ml-1 font-medium"><?php echo htmlspecialchars($d->emergency_contact_phone); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Actions -->
            <div class="px-4 pb-3 pt-1 flex items-center gap-2 border-t border-gray-100">
                <a href="drivers_trucks.php?driver_id=<?php echo $d->id; ?>"
                   class="flex-1 text-center text-xs font-semibold px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition">
                    <i class="fas fa-route mr-1"></i>Trips
                </a>
                <button onclick="editDriver(<?php echo htmlspecialchars(json_encode([
                    'id'                      => $d->id,
                    'driver_name'             => $d->driver_name,
                    'phone_number'            => $d->phone_number,
                    'license_number'          => $d->license_number,
                    'emergency_contact_name'  => $d->emergency_contact_name,
                    'emergency_contact_phone' => $d->emergency_contact_phone,
                    'driver_type'             => $d->driver_type,
                    'status'                  => $d->status,
                    'notes'                   => $d->notes,
                ])); ?>)"
                        class="flex-1 text-center text-xs font-semibold px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 transition">
                    <i class="fas fa-edit mr-1"></i>Edit
                </button>
                <form method="POST" class="flex-shrink-0" onsubmit="return confirm('<?php echo $inactive ? 'Activate' : 'Deactivate'; ?> this driver?')">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="toggle_driver">
                    <input type="hidden" name="driver_id" value="<?php echo $d->id; ?>">
                    <input type="hidden" name="new_status" value="<?php echo $inactive ? 'Active' : 'Inactive'; ?>">
                    <button type="submit" class="text-xs px-3 py-1.5 rounded-lg <?php echo $inactive ? 'bg-green-50 text-green-700 hover:bg-green-100' : 'bg-red-50 text-red-600 hover:bg-red-100'; ?> font-semibold transition">
                        <?php echo $inactive ? 'Activate' : 'Deactivate'; ?>
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Trucks panel ─────────────────────────────────────────────────── -->
<div id="panel-trucks" class="<?php echo $active_tab === 'trucks' ? '' : 'hidden'; ?>">
    <?php if (empty($vehicles)): ?>
    <div class="bg-white rounded-xl border border-gray-200 py-16 text-center">
        <i class="fas fa-truck text-5xl text-gray-200 mb-4"></i>
        <p class="font-semibold text-gray-500">No vehicles added yet.</p>
        <button onclick="openVehicleModal()" class="mt-4 px-5 py-2 bg-orange-600 text-white rounded-lg text-sm font-semibold hover:bg-orange-700">
            <i class="fas fa-plus mr-2"></i>Add First Vehicle
        </button>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 text-left">#</th>
                    <th class="px-4 py-3 text-left">Registration</th>
                    <th class="px-4 py-3 text-left">Type</th>
                    <th class="px-4 py-3 text-left">Ownership</th>
                    <th class="px-4 py-3 text-left">Notes</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
                <?php $sn = 0; foreach ($vehicles as $v): $sn++; ?>
                <tr class="hover:bg-gray-50 <?php echo $v->status === 'inactive' ? 'opacity-55' : ''; ?>">
                    <td class="px-4 py-3 text-gray-400"><?php echo $sn; ?></td>
                    <td class="px-4 py-3">
                        <span class="font-bold text-gray-900 font-mono tracking-wide"><?php echo htmlspecialchars($v->registration_number); ?></span>
                        <?php if ($v->added_by): ?>
                        <div class="text-xs text-gray-400 mt-0.5">Added by <?php echo htmlspecialchars($v->added_by); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $vehicleTypeColor[$v->vehicle_type] ?? 'bg-gray-100 text-gray-600'; ?>">
                            <?php echo $vehicleTypeLabel[$v->vehicle_type] ?? $v->vehicle_type; ?>
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $ownershipColor[$v->ownership] ?? 'bg-gray-100 text-gray-600'; ?>">
                            <?php echo ucfirst($v->ownership); ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-500 text-xs max-w-[200px] truncate"><?php echo $v->notes ? htmlspecialchars($v->notes) : '—'; ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold <?php echo $v->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo ucfirst($v->status); ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <button onclick="editVehicle(<?php echo htmlspecialchars(json_encode([
                                'id'                  => $v->id,
                                'registration_number' => $v->registration_number,
                                'vehicle_type'        => $v->vehicle_type,
                                'ownership'           => $v->ownership,
                                'status'              => $v->status,
                                'notes'               => $v->notes,
                            ])); ?>)"
                                    class="text-xs px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 font-semibold transition">
                                <i class="fas fa-edit mr-1"></i>Edit
                            </button>
                            <form method="POST" onsubmit="return confirm('<?php echo $v->status === 'active' ? 'Deactivate' : 'Activate'; ?> this vehicle?')">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="toggle_vehicle">
                                <input type="hidden" name="vehicle_id" value="<?php echo $v->id; ?>">
                                <input type="hidden" name="new_status" value="<?php echo $v->status === 'active' ? 'inactive' : 'active'; ?>">
                                <button type="submit" class="text-xs px-3 py-1.5 rounded-lg <?php echo $v->status === 'active' ? 'bg-red-50 text-red-600 hover:bg-red-100' : 'bg-green-50 text-green-700 hover:bg-green-100'; ?> font-semibold transition">
                                    <?php echo $v->status === 'active' ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ Driver Modal ═══════════════════════════════════════════════════════════ -->
<div id="driverModal" class="fixed inset-0 z-50 hidden overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-black/40 backdrop-blur-sm" onclick="closeDriverModal()"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-user-edit text-indigo-600"></i>
                    <span id="driverModalTitle">Add Driver</span>
                </h3>
                <button onclick="closeDriverModal()" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>
            <form method="POST" class="px-6 py-5 space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="save_driver">
                <input type="hidden" name="driver_id" id="driverIdField" value="0">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Full Name <span class="text-red-500">*</span></label>
                        <input type="text" name="driver_name" id="d_name" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                               placeholder="Driver's full name">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                        <input type="text" name="phone_number" id="d_phone"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                               placeholder="+880-1XXX-XXXXXX">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Driving License No.</label>
                        <input type="text" name="license_number" id="d_license"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                               placeholder="DL-XXXX-XXXXXXXX">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Driver Type</label>
                        <select name="driver_type" id="d_type"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="Salaried">Salaried</option>
                            <option value="Contractual">Contractual</option>
                            <option value="Helper">Helper</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status" id="d_status"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="border-t border-red-100 pt-4">
                    <p class="text-xs font-bold text-red-600 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                        <i class="fas fa-phone-alt"></i> Emergency Contact — In Case of Accident
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Contact Name</label>
                            <input type="text" name="emergency_contact_name" id="d_em_name"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-400 focus:border-red-400"
                                   placeholder="e.g. Spouse / Parent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Contact Phone</label>
                            <input type="text" name="emergency_contact_phone" id="d_em_phone"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-400 focus:border-red-400"
                                   placeholder="+880-1XXX-XXXXXX">
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" id="d_notes" rows="2"
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                              placeholder="Any additional info about this driver..."></textarea>
                </div>

                <div class="flex gap-3 pt-1">
                    <button type="submit"
                            class="flex-1 px-5 py-2.5 bg-indigo-600 text-white rounded-lg font-semibold text-sm hover:bg-indigo-700 transition">
                        <i class="fas fa-save mr-2"></i>Save Driver
                    </button>
                    <button type="button" onclick="closeDriverModal()"
                            class="px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg font-semibold text-sm hover:bg-gray-50 transition">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ Vehicle Modal ══════════════════════════════════════════════════════════ -->
<div id="vehicleModal" class="fixed inset-0 z-50 hidden overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-black/40 backdrop-blur-sm" onclick="closeVehicleModal()"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-truck text-orange-600"></i>
                    <span id="vehicleModalTitle">Add Vehicle</span>
                </h3>
                <button onclick="closeVehicleModal()" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>
            <form method="POST" class="px-6 py-5 space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="save_vehicle">
                <input type="hidden" name="vehicle_id" id="vehicleIdField" value="0">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Registration Number <span class="text-red-500">*</span></label>
                    <input type="text" name="registration_number" id="v_reg" required
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                           placeholder="e.g. ঢাকা মেট্রো চ ১১-০০০০">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle Type</label>
                        <select name="vehicle_type" id="v_type"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                            <option value="mini_truck">Mini Truck</option>
                            <option value="boro_truck">Boro Truck</option>
                            <option value="car">Car / Own Vehicle</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Ownership</label>
                        <select name="ownership" id="v_ownership"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                            <option value="own">Own</option>
                            <option value="rented">Rented</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" id="v_status"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" id="v_notes" rows="2"
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                              placeholder="e.g. capacity, colour, owner contact..."></textarea>
                </div>

                <div class="flex gap-3 pt-1">
                    <button type="submit"
                            class="flex-1 px-5 py-2.5 bg-orange-600 text-white rounded-lg font-semibold text-sm hover:bg-orange-700 transition">
                        <i class="fas fa-save mr-2"></i>Save Vehicle
                    </button>
                    <button type="button" onclick="closeVehicleModal()"
                            class="px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg font-semibold text-sm hover:bg-gray-50 transition">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
function switchTab(tab) {
    document.getElementById('panel-drivers').classList.toggle('hidden', tab !== 'drivers');
    document.getElementById('panel-trucks').classList.toggle('hidden',  tab !== 'trucks');
    document.getElementById('tab-drivers').className = 'tab-btn pb-3 text-sm font-semibold border-b-2 transition ' +
        (tab === 'drivers' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700');
    document.getElementById('tab-trucks').className  = 'tab-btn pb-3 text-sm font-semibold border-b-2 transition ' +
        (tab === 'trucks'  ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-700');
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    history.replaceState(null, '', url);
}

function openDriverModal() {
    document.getElementById('driverModalTitle').textContent = 'Add Driver';
    document.getElementById('driverIdField').value = '0';
    ['d_name','d_phone','d_license','d_em_name','d_em_phone','d_notes'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('d_type').value   = 'Salaried';
    document.getElementById('d_status').value = 'Active';
    document.getElementById('driverModal').classList.remove('hidden');
    setTimeout(() => document.getElementById('d_name').focus(), 50);
}
function closeDriverModal() { document.getElementById('driverModal').classList.add('hidden'); }

function editDriver(d) {
    document.getElementById('driverModalTitle').textContent    = 'Edit Driver';
    document.getElementById('driverIdField').value             = d.id;
    document.getElementById('d_name').value                    = d.driver_name             || '';
    document.getElementById('d_phone').value                   = d.phone_number            || '';
    document.getElementById('d_license').value                 = d.license_number          || '';
    document.getElementById('d_em_name').value                 = d.emergency_contact_name  || '';
    document.getElementById('d_em_phone').value                = d.emergency_contact_phone || '';
    document.getElementById('d_notes').value                   = d.notes                   || '';
    // Map legacy type values to our select options
    const typeMap = { 'Permanent': 'Salaried', 'Salaried': 'Salaried', 'Contractual': 'Contractual', 'Helper': 'Helper' };
    document.getElementById('d_type').value   = typeMap[d.driver_type]  || 'Salaried';
    document.getElementById('d_status').value = (d.status === 'Active' || d.status === 'Inactive') ? d.status : 'Active';
    document.getElementById('driverModal').classList.remove('hidden');
}

function openVehicleModal() {
    document.getElementById('vehicleModalTitle').textContent = 'Add Vehicle';
    document.getElementById('vehicleIdField').value = '0';
    document.getElementById('v_reg').value       = '';
    document.getElementById('v_type').value      = 'mini_truck';
    document.getElementById('v_ownership').value = 'own';
    document.getElementById('v_status').value    = 'active';
    document.getElementById('v_notes').value     = '';
    document.getElementById('vehicleModal').classList.remove('hidden');
    setTimeout(() => document.getElementById('v_reg').focus(), 50);
}
function closeVehicleModal() { document.getElementById('vehicleModal').classList.add('hidden'); }

function editVehicle(v) {
    document.getElementById('vehicleModalTitle').textContent   = 'Edit Vehicle';
    document.getElementById('vehicleIdField').value            = v.id;
    document.getElementById('v_reg').value                     = v.registration_number || '';
    document.getElementById('v_type').value                    = v.vehicle_type        || 'mini_truck';
    document.getElementById('v_ownership').value               = v.ownership           || 'own';
    document.getElementById('v_status').value                  = v.status              || 'active';
    document.getElementById('v_notes').value                   = v.notes               || '';
    document.getElementById('vehicleModal').classList.remove('hidden');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeDriverModal(); closeVehicleModal(); }
});
</script>

<?php require_once '../templates/footer.php'; ?>
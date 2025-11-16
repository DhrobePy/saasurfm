<?php
require_once '../../../core/init.php';

// Access control
$allowed_roles = ['Superadmin', 'admin', 'Transport Manager', 'Accounts'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Vehicle Documents';

// --- Get Filters ---
$vehicle_filter = $_GET['vehicle'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

$where_conditions = [];
$params = [];

if ($vehicle_filter !== 'all') {
    $where_conditions[] = "vd.vehicle_id = ?";
    $params[] = (int)$vehicle_filter;
}

$today = date('Y-m-d');
$soon_date = date('Y-m-d', strtotime('+30 days'));

if ($status_filter === 'expired') {
    $where_conditions[] = "vd.expiry_date < ?";
    $params[] = $today;
} elseif ($status_filter === 'expiring_soon') {
    $where_conditions[] = "vd.expiry_date BETWEEN ? AND ?";
    $params[] = $today;
    $params[] = $soon_date;
} elseif ($status_filter === 'ok') {
    $where_conditions[] = "vd.expiry_date > ?";
    $params[] = $soon_date;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// --- Get Main Document List ---
$documents = $db->query(
    "SELECT vd.*, v.vehicle_number, v.model 
     FROM vehicle_documents vd
     JOIN vehicles v ON vd.vehicle_id = v.id
     $where_clause
     ORDER BY vd.expiry_date ASC",
    $params
)->results();

// --- Get Stats Cards Data ---
$stats = $db->query(
    "SELECT 
        COUNT(*) as total_docs,
        SUM(CASE WHEN expiry_date < ? THEN 1 ELSE 0 END) as total_expired,
        SUM(CASE WHEN expiry_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as total_expiring_soon
     FROM vehicle_documents",
    [$today, $today, $soon_date]
)->first();

// --- Get Cost History ---
$cost_history = $db->query(
    "SELECT te.*, v.vehicle_number 
     FROM transport_expenses te
     LEFT JOIN vehicles v ON te.vehicle_id = v.id
     WHERE te.expense_type = 'Document Renewal'
     ORDER BY te.expense_date DESC
     LIMIT 50"
)->results();

// Get vehicles for filter dropdown
$vehicles = $db->query("SELECT id, vehicle_number FROM vehicles WHERE status = 'Active' ORDER BY vehicle_number")->results();

require_once '../../../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6" x-data="{ activeTab: 'documents' }">

    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">📄 Vehicle Documents</h1>
            <p class="text-gray-600 mt-1">Track vehicle document status, expiry, and renewal costs.</p>
        </div>
        <a href="manage.php" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 shadow-sm">
            <i class="fas fa-plus mr-2"></i>Add Document
        </a>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow-md p-6">
            <p class="text-sm text-gray-600">Total Documents</p>
            <p class="text-3xl font-bold text-gray-900"><?php echo $stats->total_docs ?? 0; ?></p>
        </div>
        <div class="bg-red-50 rounded-lg shadow-md p-6 border-l-4 border-red-500">
            <p class="text-sm text-red-700">Expired</p>
            <p class="text-3xl font-bold text-red-600"><?php echo $stats->total_expired ?? 0; ?></p>
        </div>
        <div class="bg-yellow-50 rounded-lg shadow-md p-6 border-l-4 border-yellow-500">
            <p class="text-sm text-yellow-700">Expiring Soon (30 Days)</p>
            <p class="text-3xl font-bold text-yellow-600"><?php echo $stats->total_expiring_soon ?? 0; ?></p>
        </div>
    </div>

    <!-- Filters & Tabs -->
    <div class="bg-white rounded-lg shadow-md p-4 mb-6">
        <div class="flex justify-between items-center">
            <!-- Tabs -->
            <nav class="flex space-x-2">
                <button @click="activeTab = 'documents'"
                        :class="activeTab === 'documents' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                        class="px-4 py-2 font-medium text-sm rounded-lg transition-all">
                    <i class="fas fa-file-alt mr-1"></i> All Documents
                </button>
                <button @click="activeTab = 'cost_history'"
                        :class="activeTab === 'cost_history' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                        class="px-4 py-2 font-medium text-sm rounded-lg transition-all">
                    <i class="fas fa-history mr-1"></i> Cost History
                </button>
            </nav>
            
            <!-- Filters -->
            <form method="GET" class="flex flex-wrap gap-4 items-center">
                <div>
                    <label for="vehicle_filter" class="block text-sm font-medium text-gray-700 mb-1">Vehicle</label>
                    <select id="vehicle_filter" name="vehicle" class="px-4 py-2 border border-gray-300 rounded-lg" onchange="this.form.submit()">
                        <option value="all">All Vehicles</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                        <option value="<?php echo $vehicle->id; ?>" <?php echo $vehicle_filter == $vehicle->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($vehicle->vehicle_number); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="status_filter" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select id="status_filter" name="status" class="px-4 py-2 border border-gray-300 rounded-lg" onchange="this.form.submit()">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="ok" <?php echo $status_filter == 'ok' ? 'selected' : ''; ?>>OK</option>
                        <option value="expiring_soon" <?php echo $status_filter == 'expiring_soon' ? 'selected' : ''; ?>>Expiring Soon</option>
                        <option value="expired" <?php echo $status_filter == 'expired' ? 'selected' : ''; ?>>Expired</option>
                    </select>
                </div>
                <div class="pt-5">
                    <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Clear Filters</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Tab Content -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        
        <!-- Documents Tab -->
        <div x-show="activeTab === 'documents'" class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vehicle</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Document</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Number</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Issue Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Expiry Date</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Days Left</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($documents)): ?>
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-gray-500">No documents found for this filter.</td>
                    </tr>
                    <?php else: ?>
                        <?php 
                        foreach ($documents as $doc): 
                            $status_color = 'green';
                            $status_text = 'OK';
                            $days_left_num = null;
                            $days_left_text = '-';

                            if ($doc->expiry_date) {
                                try {
                                    $expiry = new DateTime($doc->expiry_date);
                                    $diff = (new DateTime())->diff($expiry);
                                    $days_left_num = (int)$diff->format('%r%a');
                                    $days_left_text = $days_left_num;

                                    if ($days_left_num <= 0) {
                                        $status_color = 'red';
                                        $status_text = 'Expired';
                                    } elseif ($days_left_num <= 30) {
                                        $status_color = 'yellow';
                                        $status_text = 'Expiring Soon';
                                    }
                                } catch (Exception $e) {}
                            }
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($doc->vehicle_number); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($doc->document_type); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-500"><?php echo htmlspecialchars($doc->document_number ?? '-'); ?></td>
                            <td class="px-4 py-3 text-sm">
                                <span class="px-2 py-0.5 text-xs font-bold rounded-full bg-<?php echo $status_color; ?>-100 text-<?php echo $status_color; ?>-800">
                                    <?php echo $status_text; ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500"><?php echo $doc->issue_date ? date('d M Y', strtotime($doc->issue_date)) : '-'; ?></td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo $doc->expiry_date ? date('d M Y', strtotime($doc->expiry_date)) : '-'; ?></td>
                            <td class="px-4 py-3 text-sm text-right font-medium text-<?php echo $status_color; ?>-600"><?php echo $days_left_text; ?></td>
                            <td class="px-4 py-3 text-center">
                                <a href="manage.php?id=<?php echo $doc->id; ?>" class="text-blue-600 hover:text-blue-800" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <!-- Add file view link if exists -->
                                <?php if ($doc->file_path): ?>
                                    <a href="<?php echo url($doc->file_path); ?>" target="_blank" class="text-green-600 hover:text-green-800 ml-3" title="View File">
                                        <i class="fas fa-file-alt"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Cost History Tab -->
        <div x-show="activeTab === 'cost_history'" class="overflow-x-auto" style="display: none;">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vehicle</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($cost_history)): ?>
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-gray-500">No document renewal costs found in transport expenses.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($cost_history as $log): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-900"><?php echo date('d M Y', strtotime($log->expense_date)); ?></td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($log->vehicle_number ?? 'N/A'); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($log->description); ?></td>
                            <td class="px-4 py-3 text-sm text-right font-bold text-red-600">৳<?php echo number_format($log->amount, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<!-- Alpine.js for tabs -->
<script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.x.x/dist/alpine.min.js" defer></script>

<?php require_once '../../../templates/footer.php'; ?>
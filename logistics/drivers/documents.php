<?php
require_once '../../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Transport Manager'];
restrict_access($allowed_roles);

global $db;

// Get driver ID from URL
if (!isset($_GET['driver_id'])) {
    $_SESSION['error_flash'] = 'Driver ID is required.';
    header('Location: index.php');
    exit();
}

$driver_id = (int)$_GET['driver_id'];

// Get driver details
$driver = $db->query("SELECT * FROM drivers WHERE id = ?", [$driver_id])->first();

if (!$driver) {
    $_SESSION['error_flash'] = 'Driver not found.';
    header('Location: index.php');
    exit();
}

$pageTitle = 'Driver Documents: ' . htmlspecialchars($driver->driver_name);

// Handle document add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    try {
        $data = [
            'driver_id' => $driver_id,
            'document_type' => trim($_POST['document_type']),
            'document_number' => trim($_POST['document_number']) ?: null,
            'issue_date' => $_POST['issue_date'] ?: null,
            'expiry_date' => $_POST['expiry_date'] ?: null,
            'notes' => trim($_POST['notes']) ?: null
        ];
        
        $db->insert('driver_documents', $data);
        $_SESSION['success_flash'] = 'Document added successfully.';
        header('Location: documents.php?driver_id=' . $driver_id);
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_flash'] = 'Error: ' . $e->getMessage();
    }
}

// Handle document update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    try {
        $doc_id = (int)$_POST['document_id'];
        
        $data = [
            'document_type' => trim($_POST['document_type']),
            'document_number' => trim($_POST['document_number']) ?: null,
            'issue_date' => $_POST['issue_date'] ?: null,
            'expiry_date' => $_POST['expiry_date'] ?: null,
            'notes' => trim($_POST['notes']) ?: null
        ];
        
        $db->update('driver_documents', $data, ['id' => $doc_id, 'driver_id' => $driver_id]);
        $_SESSION['success_flash'] = 'Document updated successfully.';
        header('Location: documents.php?driver_id=' . $driver_id);
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_flash'] = 'Error: ' . $e->getMessage();
    }
}

// Handle document delete
if (isset($_GET['delete_id'])) {
    try {
        $doc_id = (int)$_GET['delete_id'];
        
        $db->delete('driver_documents', ['id' => $doc_id, 'driver_id' => $driver_id]);
        $_SESSION['success_flash'] = 'Document deleted successfully.';
        
        header('Location: documents.php?driver_id=' . $driver_id);
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_flash'] = 'Error: ' . $e->getMessage();
    }
}

// Get all documents for this driver
$documents = $db->query("
    SELECT * FROM driver_documents 
    WHERE driver_id = ? 
    ORDER BY document_type, expiry_date DESC
", [$driver_id])->results();

// Get document for editing
$edit_document = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $edit_document = $db->query("SELECT * FROM driver_documents WHERE id = ? AND driver_id = ?", [$edit_id, $driver_id])->first();
}

require_once '../../templates/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    
    <!-- Driver Info Header -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">
                    <i class="fas fa-file-alt mr-2 text-blue-600"></i>
                    Driver Documents
                </h1>
                <p class="text-gray-600 mt-1">
                    <strong><?php echo htmlspecialchars($driver->driver_name); ?></strong>
                    <span class="mx-2">•</span>
                    <?php echo htmlspecialchars($driver->phone_number); ?>
                    <span class="mx-2">•</span>
                    License: <?php echo htmlspecialchars($driver->license_number ?? 'N/A'); ?>
                </p>
            </div>
            <a href="index.php" class="px-4 py-2 border rounded-lg hover:bg-gray-50">
                <i class="fas fa-arrow-left mr-2"></i>Back to Drivers
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Add/Edit Document Form -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-lg shadow-md p-6 sticky top-6">
                <h2 class="text-lg font-bold text-gray-900 mb-4">
                    <?php echo $edit_document ? 'Edit Document' : 'Add New Document'; ?>
                </h2>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="<?php echo $edit_document ? 'update' : 'add'; ?>">
                    <?php if ($edit_document): ?>
                        <input type="hidden" name="document_id" value="<?php echo $edit_document->id; ?>">
                    <?php endif; ?>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Document Type *
                        </label>
                        <select name="document_type" required class="w-full px-4 py-2 border rounded-lg">
                            <option value="">-- Select Type --</option>
                            <option value="Driving License" <?php echo ($edit_document && $edit_document->document_type === 'Driving License') ? 'selected' : ''; ?>>Driving License</option>
                            <option value="NID" <?php echo ($edit_document && $edit_document->document_type === 'NID') ? 'selected' : ''; ?>>National ID (NID)</option>
                            <option value="Medical Certificate" <?php echo ($edit_document && $edit_document->document_type === 'Medical Certificate') ? 'selected' : ''; ?>>Medical Certificate</option>
                            <option value="Police Clearance" <?php echo ($edit_document && $edit_document->document_type === 'Police Clearance') ? 'selected' : ''; ?>>Police Clearance</option>
                            <option value="Contract" <?php echo ($edit_document && $edit_document->document_type === 'Contract') ? 'selected' : ''; ?>>Employment Contract</option>
                            <option value="Insurance" <?php echo ($edit_document && $edit_document->document_type === 'Insurance') ? 'selected' : ''; ?>>Insurance</option>
                            <option value="Training Certificate" <?php echo ($edit_document && $edit_document->document_type === 'Training Certificate') ? 'selected' : ''; ?>>Training Certificate</option>
                            <option value="Other" <?php echo ($edit_document && $edit_document->document_type === 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Document Number *
                        </label>
                        <input type="text" name="document_number" required
                               class="w-full px-4 py-2 border rounded-lg"
                               value="<?php echo htmlspecialchars($edit_document->document_number ?? ''); ?>"
                               placeholder="e.g., DL-123456">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Issue Date
                        </label>
                        <input type="date" name="issue_date" 
                               class="w-full px-4 py-2 border rounded-lg"
                               value="<?php echo $edit_document->issue_date ?? ''; ?>">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Expiry Date
                        </label>
                        <input type="date" name="expiry_date" 
                               class="w-full px-4 py-2 border rounded-lg"
                               value="<?php echo $edit_document->expiry_date ?? ''; ?>">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Notes
                        </label>
                        <textarea name="notes" rows="3" 
                                  class="w-full px-4 py-2 border rounded-lg"
                                  placeholder="Additional information..."><?php echo htmlspecialchars($edit_document->notes ?? ''); ?></textarea>
                    </div>
                    
                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            <i class="fas fa-save mr-2"></i>
                            <?php echo $edit_document ? 'Update' : 'Add'; ?>
                        </button>
                        <?php if ($edit_document): ?>
                            <a href="documents.php?driver_id=<?php echo $driver_id; ?>" 
                               class="px-4 py-2 border rounded-lg hover:bg-gray-50">
                                Cancel
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Documents List -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow-md">
                <div class="p-6 border-b">
                    <h2 class="text-lg font-bold text-gray-900">
                        All Documents (<?php echo count($documents); ?>)
                    </h2>
                </div>
                
                <?php if (empty($documents)): ?>
                    <div class="p-12 text-center">
                        <i class="fas fa-folder-open text-6xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500 text-lg">No documents added yet</p>
                        <p class="text-gray-400 text-sm">Add your first document using the form on the left</p>
                    </div>
                <?php else: ?>
                    <div class="divide-y">
                        <?php 
                        $today = date('Y-m-d');
                        $warning_date = date('Y-m-d', strtotime('+30 days'));
                        
                        foreach ($documents as $doc): 
                            $is_expired = $doc->expiry_date && $doc->expiry_date < $today;
                            $is_expiring_soon = $doc->expiry_date && $doc->expiry_date >= $today && $doc->expiry_date <= $warning_date;
                        ?>
                            <div class="p-6 hover:bg-gray-50 <?php echo $is_expired ? 'bg-red-50' : ($is_expiring_soon ? 'bg-yellow-50' : ''); ?>">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3">
                                            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                                <i class="fas fa-file-alt text-blue-600 text-xl"></i>
                                            </div>
                                            <div>
                                                <h3 class="font-semibold text-gray-900">
                                                    <?php echo htmlspecialchars($doc->document_type); ?>
                                                    <?php if ($is_expired): ?>
                                                        <span class="ml-2 px-2 py-1 bg-red-100 text-red-700 text-xs rounded-full">
                                                            <i class="fas fa-exclamation-circle"></i> Expired
                                                        </span>
                                                    <?php elseif ($is_expiring_soon): ?>
                                                        <span class="ml-2 px-2 py-1 bg-yellow-100 text-yellow-700 text-xs rounded-full">
                                                            <i class="fas fa-exclamation-triangle"></i> Expiring Soon
                                                        </span>
                                                    <?php endif; ?>
                                                </h3>
                                                <?php if ($doc->document_number): ?>
                                                    <p class="text-sm text-gray-600 mt-1">
                                                        <i class="fas fa-hashtag"></i>
                                                        <?php echo htmlspecialchars($doc->document_number); ?>
                                                    </p>
                                                <?php endif; ?>
                                                <div class="flex gap-4 mt-2 text-xs text-gray-500">
                                                    <?php if ($doc->issue_date): ?>
                                                        <span>
                                                            <i class="fas fa-calendar-plus"></i>
                                                            Issued: <?php echo date('d M Y', strtotime($doc->issue_date)); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($doc->expiry_date): ?>
                                                        <span>
                                                            <i class="fas fa-calendar-times"></i>
                                                            Expires: <?php echo date('d M Y', strtotime($doc->expiry_date)); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($doc->notes): ?>
                                                    <p class="text-sm text-gray-600 mt-2">
                                                        <i class="fas fa-sticky-note text-gray-400"></i>
                                                        <?php echo htmlspecialchars($doc->notes); ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center gap-2">
                                        <a href="documents.php?driver_id=<?php echo $driver_id; ?>&edit_id=<?php echo $doc->id; ?>" 
                                           class="px-3 py-2 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 text-sm"
                                           title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="documents.php?driver_id=<?php echo $driver_id; ?>&delete_id=<?php echo $doc->id; ?>" 
                                           onclick="return confirm('Are you sure you want to delete this document?');"
                                           class="px-3 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200 text-sm"
                                           title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
    </div>
    
</div>

<?php require_once '../../templates/footer.php'; ?>
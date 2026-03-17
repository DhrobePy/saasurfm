<?php
/**
 * bank/manage_types.php
 * Manage Bank Transaction Types (income/expense categories)
 * Superadmin / Accounts only
 */

require_once dirname(__DIR__) . '/core/init.php';
require_once dirname(__DIR__) . '/bank/BankManager.php';

$adminRoles = ['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg'];
restrict_access($adminRoles);

$currentUser = getCurrentUser();
$userId      = $currentUser['id'];
$userRole    = $currentUser['role'];
$bankManager = new BankManager();

// POST – save type
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = $_POST;
        $data['user_id'] = $userId;
        $bankManager->saveTransactionType($data);
        $_SESSION['success_flash'] = 'Transaction type saved successfully.';
    } catch (Exception $e) {
        $_SESSION['error_flash'] = $e->getMessage();
    }
    header('Location: ' . url('bank/manage_types.php'));
    exit;
}

$types     = $bankManager->getTransactionTypes(false);
$editType  = null;
if (!empty($_GET['edit'])) {
    foreach ($types as $t) {
        if ($t->id == (int)$_GET['edit']) { $editType = $t; break; }
    }
}

// Group by nature
$grouped = [];
foreach ($types as $t) $grouped[$t->nature][] = $t;

$pageTitle = 'Transaction Types';
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="w-full px-4 sm:px-6 lg:px-8 py-6">

    <?php echo display_message(); ?>

    <div class="flex items-center gap-3 mb-6">
        <a href="<?php echo url('bank/index.php'); ?>" class="text-gray-400 hover:text-gray-600">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
            <i class="fas fa-tags text-primary-600"></i> Transaction Types
        </h1>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Form -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sticky top-4">
                <h2 class="font-semibold text-gray-800 mb-4">
                    <?php echo $editType ? 'Edit Type' : 'Add New Type'; ?>
                </h2>
                <form method="POST">
                    <?php if ($editType): ?>
                    <input type="hidden" name="id" value="<?php echo $editType->id; ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Name *</label>
                        <input type="text" name="name" required
                               value="<?php echo $editType ? htmlspecialchars($editType->name) : ''; ?>"
                               placeholder="e.g. Salary Payment"
                               class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                    </div>

                    <div class="mb-3">
                        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Nature *</label>
                        <select name="nature" required
                                class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                            <?php foreach (['income','expense','transfer','other'] as $n): ?>
                            <option value="<?php echo $n; ?>" <?php echo ($editType && $editType->nature === $n) ? 'selected' : ''; ?>>
                                <?php echo ucfirst($n); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Description</label>
                        <textarea name="description" rows="2"
                                  class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none resize-none"><?php echo $editType ? htmlspecialchars($editType->description ?? '') : ''; ?></textarea>
                    </div>

                    <?php if ($editType): ?>
                    <div class="mb-3 flex items-center gap-2">
                        <input type="checkbox" name="is_active" id="is_active" value="1"
                               <?php echo $editType->is_active ? 'checked' : ''; ?>
                               class="rounded border-gray-300">
                        <label for="is_active" class="text-sm text-gray-700">Active</label>
                    </div>
                    <?php endif; ?>

                    <div class="flex gap-2">
                        <button type="submit"
                                class="flex-1 bg-primary-600 text-white py-2 rounded-lg text-sm font-semibold hover:bg-primary-700 transition-colors">
                            <?php echo $editType ? 'Save Changes' : 'Add Type'; ?>
                        </button>
                        <?php if ($editType): ?>
                        <a href="<?php echo url('bank/manage_types.php'); ?>"
                           class="px-3 py-2 border border-gray-200 text-gray-600 rounded-lg text-sm hover:bg-gray-50">
                            Cancel
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- List -->
        <div class="lg:col-span-2 space-y-5">
            <?php
            $natureColors = [
                'income'   => 'green',
                'expense'  => 'red',
                'transfer' => 'blue',
                'other'    => 'gray',
            ];
            foreach ($grouped as $nature => $items):
                $col = $natureColors[$nature] ?? 'gray';
            ?>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-5 py-3 bg-<?php echo $col; ?>-50 border-b border-<?php echo $col; ?>-100">
                    <h3 class="font-semibold text-<?php echo $col; ?>-800 text-sm uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-circle text-<?php echo $col; ?>-400 text-xs"></i>
                        <?php echo ucfirst($nature); ?>
                        <span class="ml-auto text-<?php echo $col; ?>-600 font-normal"><?php echo count($items); ?> types</span>
                    </h3>
                </div>
                <div class="divide-y divide-gray-50">
                    <?php foreach ($items as $t): ?>
                    <div class="flex items-center px-5 py-3 hover:bg-gray-50 gap-3">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-800 <?php echo !$t->is_active ? 'line-through text-gray-400' : ''; ?>">
                                <?php echo htmlspecialchars($t->name); ?>
                            </p>
                            <?php if ($t->description): ?>
                            <p class="text-xs text-gray-400 truncate"><?php echo htmlspecialchars($t->description); ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="px-2 py-0.5 text-xs rounded-full <?php echo $t->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $t->is_active ? 'Active' : 'Inactive'; ?>
                        </span>
                        <a href="?edit=<?php echo $t->id; ?>"
                           class="p-1.5 text-gray-400 hover:text-primary-600 hover:bg-primary-50 rounded transition-colors">
                            <i class="fas fa-pencil-alt text-xs"></i>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </div>

</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
<?php
require_once '../core/init.php';

global $db;

// Only Superadmin, admin, and Accounts can access categories
restrict_access(['Superadmin', 'admin', 'Accounts']);

$pageTitle = "Expense Categories";

require_once '../core/classes/ExpenseManager.php';

$currentUser = getCurrentUser();
$expenseManager = new ExpenseManager($db, $currentUser['id']);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_category') {
        $result = $expenseManager->createCategory($_POST);
        echo json_encode($result);
        exit;
    }
    
    if ($action === 'update_category') {
        $category_id = $_POST['category_id'] ?? 0;
        $result = $expenseManager->updateCategory($category_id, $_POST);
        echo json_encode($result);
        exit;
    }
    
    if ($action === 'toggle_category_status') {
        $category_id = $_POST['category_id'] ?? 0;
        $result = $expenseManager->toggleCategoryStatus($category_id);
        echo json_encode($result);
        exit;
    }
    
    if ($action === 'create_subcategory') {
        $result = $expenseManager->createSubcategory($_POST);
        echo json_encode($result);
        exit;
    }
    
    if ($action === 'update_subcategory') {
        $subcategory_id = $_POST['subcategory_id'] ?? 0;
        $result = $expenseManager->updateSubcategory($subcategory_id, $_POST);
        echo json_encode($result);
        exit;
    }
    
    if ($action === 'toggle_subcategory_status') {
        $subcategory_id = $_POST['subcategory_id'] ?? 0;
        $result = $expenseManager->toggleSubcategoryStatus($subcategory_id);
        echo json_encode($result);
        exit;
    }
    
    if ($action === 'get_subcategories') {
        $category_id = $_POST['category_id'] ?? 0;
        $subcategories = $expenseManager->getSubcategoriesByCategory($category_id, false);
        echo json_encode(['success' => true, 'subcategories' => $subcategories]);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// Get all categories
$categories = $expenseManager->getAllCategories(false);

// Get expense accounts for dropdown
$expense_accounts = $expenseManager->getExpenseAccounts();

require_once '../templates/header.php';
?>

<div class="container mx-auto px-4">
    <!-- Page Header -->
    <div class="mb-6">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Expense Categories</h1>
                <p class="text-gray-600 mt-1">Manage expense categories and subcategories</p>
            </div>
            <button onclick="window.categoryApp.showAddCategory()" 
                    class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-plus mr-2"></i>
                Add Category
            </button>
        </div>
    </div>

    <?php echo display_message(); ?>

    <!-- Categories List -->
    <div class="bg-white rounded-lg shadow-md">
        <div class="p-6">
            <div class="space-y-4" id="categories-container">
                <?php if (empty($categories)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-folder-open text-6xl mb-4"></i>
                        <p class="text-lg">No expense categories found</p>
                        <p class="text-sm">Click "Add Category" to create your first category</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($categories as $category): ?>
                        <div class="border border-gray-200 rounded-lg" id="category-<?php echo $category->id; ?>">
                            <!-- Category Header -->
                            <div class="bg-gray-50 p-4 flex justify-between items-center">
                                <div class="flex items-center space-x-4">
                                    <div class="bg-primary-100 text-primary-700 w-10 h-10 rounded-full flex items-center justify-center">
                                        <i class="fas fa-folder"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-gray-900"><?php echo htmlspecialchars($category->category_name); ?></h3>
                                        <?php if ($category->category_code): ?>
                                            <span class="text-xs text-gray-500">Code: <?php echo htmlspecialchars($category->category_code); ?></span>
                                        <?php endif; ?>
                                        <?php if ($category->description): ?>
                                            <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($category->description); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="text-sm text-gray-600">
                                        <?php echo $category->subcategory_count; ?> subcategories
                                    </span>
                                    <span class="px-2 py-1 rounded-full text-xs <?php echo $category->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo $category->is_active ? 'Active' : 'Inactive'; ?>
                                    </span>
                                    <button onclick="window.categoryApp.editCategory(<?php echo htmlspecialchars(json_encode($category)); ?>)" 
                                            class="text-blue-600 hover:text-blue-800 p-2">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="window.categoryApp.toggleStatus(<?php echo $category->id; ?>, 'category')" 
                                            class="text-gray-600 hover:text-gray-800 p-2">
                                        <i class="fas fa-<?php echo $category->is_active ? 'eye-slash' : 'eye'; ?>"></i>
                                    </button>
                                    <button onclick="window.categoryApp.toggleExpand(<?php echo $category->id; ?>)" 
                                            class="text-primary-600 hover:text-primary-800 p-2">
                                        <i class="fas fa-chevron-down" id="chevron-<?php echo $category->id; ?>"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Subcategories -->
                            <div id="subcategories-<?php echo $category->id; ?>" class="hidden p-4 bg-white">
                                <div class="flex justify-between items-center mb-4">
                                    <h4 class="font-medium text-gray-700">Subcategories</h4>
                                    <button onclick="window.categoryApp.showAddSubcategory(<?php echo $category->id; ?>, '<?php echo htmlspecialchars($category->category_name); ?>')" 
                                            class="bg-primary-50 text-primary-700 hover:bg-primary-100 px-3 py-1 rounded text-sm">
                                        <i class="fas fa-plus mr-1"></i>
                                        Add Subcategory
                                    </button>
                                </div>

                                <div class="space-y-2" id="subcategory-list-<?php echo $category->id; ?>">
                                    <p class="text-gray-500 text-sm text-center py-4">Loading...</p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Category Modal -->
<div id="categoryModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-lg bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900" id="categoryModalTitle">Add New Category</h3>
            <button onclick="window.categoryApp.closeModal('category')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="categoryForm" onsubmit="window.categoryApp.saveCategory(event)">
            <input type="hidden" id="category_id" name="category_id">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category Name <span class="text-red-500">*</span></label>
                    <input type="text" id="category_name" name="category_name" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category Code</label>
                    <input type="text" id="category_code" name="category_code"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea id="category_description" name="description" rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500"></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Chart of Account (Optional)</label>
                    <select id="category_chart_id" name="chart_of_account_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                        <option value="">-- Select Account --</option>
                        <?php foreach ($expense_accounts as $account): ?>
                            <option value="<?php echo $account->id; ?>"><?php echo htmlspecialchars($account->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="window.categoryApp.closeModal('category')"
                        class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">
                    Cancel
                </button>
                <button type="submit" 
                        class="px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Subcategory Modal -->
<div id="subcategoryModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-lg bg-white">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-900" id="subcategoryModalTitle">Add New Subcategory</h3>
                <p class="text-sm text-gray-600" id="subcategoryCategory"></p>
            </div>
            <button onclick="window.categoryApp.closeModal('subcategory')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="subcategoryForm" onsubmit="window.categoryApp.saveSubcategory(event)">
            <input type="hidden" id="subcategory_id" name="subcategory_id">
            <input type="hidden" id="subcategory_category_id" name="category_id">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subcategory Name <span class="text-red-500">*</span></label>
                    <input type="text" id="subcategory_name" name="subcategory_name" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subcategory Code</label>
                    <input type="text" id="subcategory_code" name="subcategory_code"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Unit of Measurement</label>
                    <input type="text" id="unit_of_measurement" name="unit_of_measurement" 
                           placeholder="e.g., Liters, KG, Hours"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                    <p class="text-xs text-gray-500 mt-1">Optional: For unit-based expenses</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea id="subcategory_description" name="description" rows="2"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500"></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Chart of Account <span class="text-red-500">*</span></label>
                    <select id="subcategory_chart_id" name="chart_of_account_id" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                        <option value="">-- Select Account --</option>
                        <?php foreach ($expense_accounts as $account): ?>
                            <option value="<?php echo $account->id; ?>"><?php echo htmlspecialchars($account->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Required for journal entries</p>
                </div>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="window.categoryApp.closeModal('subcategory')"
                        class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">
                    Cancel
                </button>
                <button type="submit" 
                        class="px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

<script>
window.categoryApp = {
    expandedCategories: {},
    
    showAddCategory() {
        document.getElementById('categoryModalTitle').textContent = 'Add New Category';
        document.getElementById('categoryForm').reset();
        document.getElementById('category_id').value = '';
        document.getElementById('categoryModal').classList.remove('hidden');
    },
    
    editCategory(category) {
        document.getElementById('categoryModalTitle').textContent = 'Edit Category';
        document.getElementById('category_id').value = category.id;
        document.getElementById('category_name').value = category.category_name;
        document.getElementById('category_code').value = category.category_code || '';
        document.getElementById('category_description').value = category.description || '';
        document.getElementById('category_chart_id').value = category.chart_of_account_id || '';
        document.getElementById('categoryModal').classList.remove('hidden');
    },
    
    showAddSubcategory(categoryId, categoryName) {
        document.getElementById('subcategoryModalTitle').textContent = 'Add New Subcategory';
        document.getElementById('subcategoryCategory').textContent = 'Category: ' + categoryName;
        document.getElementById('subcategoryForm').reset();
        document.getElementById('subcategory_id').value = '';
        document.getElementById('subcategory_category_id').value = categoryId;
        document.getElementById('subcategoryModal').classList.remove('hidden');
    },
    
    editSubcategory(sub) {
        document.getElementById('subcategoryModalTitle').textContent = 'Edit Subcategory';
        document.getElementById('subcategory_id').value = sub.id;
        document.getElementById('subcategory_category_id').value = sub.category_id;
        document.getElementById('subcategory_name').value = sub.subcategory_name;
        document.getElementById('subcategory_code').value = sub.subcategory_code || '';
        document.getElementById('subcategory_description').value = sub.description || '';
        document.getElementById('subcategory_chart_id').value = sub.chart_of_account_id || '';
        document.getElementById('unit_of_measurement').value = sub.unit_of_measurement || '';
        document.getElementById('subcategoryModal').classList.remove('hidden');
    },
    
    closeModal(type) {
        if (type === 'category') {
            document.getElementById('categoryModal').classList.add('hidden');
        } else {
            document.getElementById('subcategoryModal').classList.add('hidden');
        }
    },
    
    async saveCategory(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        const isEdit = formData.get('category_id');
        formData.append('action', isEdit ? 'update_category' : 'create_category');
        
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                alert(result.message);
                window.location.reload();
            } else {
                alert(result.message);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('An error occurred');
        }
    },
    
    async saveSubcategory(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        const isEdit = formData.get('subcategory_id');
        formData.append('action', isEdit ? 'update_subcategory' : 'create_subcategory');
        
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                alert(result.message);
                window.location.reload();
            } else {
                alert(result.message);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('An error occurred');
        }
    },
    
    async toggleStatus(id, type) {
        if (!confirm(`Are you sure you want to toggle this ${type} status?`)) return;
        
        const formData = new FormData();
        formData.append('action', `toggle_${type}_status`);
        formData.append(`${type}_id`, id);
        
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                window.location.reload();
            } else {
                alert(result.message);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('An error occurred');
        }
    },
    
    toggleExpand(categoryId) {
        const container = document.getElementById(`subcategories-${categoryId}`);
        const chevron = document.getElementById(`chevron-${categoryId}`);
        
        if (container.classList.contains('hidden')) {
            container.classList.remove('hidden');
            chevron.style.transform = 'rotate(180deg)';
            
            if (!this.expandedCategories[categoryId]) {
                this.loadSubcategories(categoryId);
                this.expandedCategories[categoryId] = true;
            }
        } else {
            container.classList.add('hidden');
            chevron.style.transform = 'rotate(0deg)';
        }
    },
    
    async loadSubcategories(categoryId) {
        const formData = new FormData();
        formData.append('action', 'get_subcategories');
        formData.append('category_id', categoryId);
        
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.renderSubcategories(categoryId, result.subcategories);
            }
        } catch (error) {
            console.error('Error loading subcategories:', error);
        }
    },
    
    renderSubcategories(categoryId, subcategories) {
        const container = document.getElementById(`subcategory-list-${categoryId}`);
        
        if (subcategories.length === 0) {
            container.innerHTML = '<p class="text-gray-500 text-sm text-center py-4">No subcategories yet</p>';
            return;
        }
        
        container.innerHTML = subcategories.map(sub => `
            <div class="flex justify-between items-center p-3 bg-gray-50 rounded border border-gray-200">
                <div class="flex-1">
                    <div class="flex items-center space-x-2">
                        <span class="font-medium text-gray-900">${sub.subcategory_name}</span>
                        ${sub.subcategory_code ? `<span class="text-xs text-gray-500">(${sub.subcategory_code})</span>` : ''}
                        ${sub.unit_of_measurement ? `<span class="text-xs bg-blue-100 text-blue-800 px-2 py-0.5 rounded">${sub.unit_of_measurement}</span>` : ''}
                    </div>
                    ${sub.description ? `<p class="text-sm text-gray-600 mt-1">${sub.description}</p>` : ''}
                    ${sub.chart_account_name ? `<p class="text-xs text-gray-500 mt-1">Chart Account: ${sub.chart_account_name}</p>` : ''}
                </div>
                <div class="flex items-center space-x-2">
                    <span class="px-2 py-1 rounded-full text-xs ${sub.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">
                        ${sub.is_active ? 'Active' : 'Inactive'}
                    </span>
                    <button onclick="window.categoryApp.editSubcategory(${JSON.stringify(sub).replace(/"/g, '&quot;')})" class="text-blue-600 hover:text-blue-800 p-2">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button onclick="window.categoryApp.toggleStatus(${sub.id}, 'subcategory')" class="text-gray-600 hover:text-gray-800 p-2">
                        <i class="fas fa-${sub.is_active ? 'eye-slash' : 'eye'}"></i>
                    </button>
                </div>
            </div>
        `).join('');
    }
};
</script>

<?php require_once '../templates/footer.php'; ?>
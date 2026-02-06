<?php
/**
 * Edit GRN Form - Superadmin Only
 * File: /purchase/purchase_adnan_edit_grn.php
 * * Fajracct Style - Tailwind CSS Version
 */

require_once '../core/init.php';
require_once '../templates/header.php';

// Superadmin only
restrict_access(['Superadmin']);

$grn_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($grn_id === 0) {
    $_SESSION['error_flash'] = "Invalid GRN ID";
    header('Location: purchase_adnan_index.php');
    exit;
}

$db = Database::getInstance()->getPdo();

// Get GRN details
$stmt = $db->prepare("
    SELECT 
        g.*,
        po.po_number,
        po.supplier_name,
        po.wheat_origin
    FROM goods_received_adnan g
    JOIN purchase_orders_adnan po ON g.purchase_order_id = po.id
    WHERE g.id = ?
");
$stmt->execute([$grn_id]);
$grn = $stmt->fetch(PDO::FETCH_OBJ);

if (!$grn) {
    $_SESSION['error_flash'] = "GRN not found";
    header('Location: purchase_adnan_index.php');
    exit;
}

// Check if can edit
if ($grn->grn_status === 'cancelled') {
    $_SESSION['error_flash'] = "Cannot edit cancelled GRN";
    header("Location: purchase_adnan_view_po.php?id={$grn->purchase_order_id}");
    exit;
}

// Get branches for unload point dropdown
$branches = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_OBJ);

$pageTitle = "Edit GRN: " . $grn->grn_number;
?>

<div class="w-full">
    <!-- Header Section -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
        <div>
            <h1 class="text-3xl font-black text-gray-900 uppercase tracking-tight">
                Edit GRN <span class="text-primary-600">#<?= htmlspecialchars($grn->grn_number) ?></span>
            </h1>
            <p class="text-gray-500 text-sm mt-1 flex items-center gap-2">
                <i class="fas fa-file-invoice"></i> Associated PO: <span class="font-bold text-gray-700">#<?= htmlspecialchars($grn->po_number) ?></span>
                <span class="mx-2 text-gray-300">|</span>
                <i class="fas fa-user-tie"></i> Supplier: <span class="font-bold text-gray-700"><?= htmlspecialchars($grn->supplier_name) ?></span>
            </p>
        </div>
        <div class="flex gap-3">
            <a href="purchase_adnan_view_po.php?id=<?= $grn->purchase_order_id ?>" class="px-4 py-2 bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200 transition font-bold text-sm">
                <i class="fas fa-times mr-2"></i>Cancel
            </a>
        </div>
    </div>

    <div class="max-w-4xl">
        <!-- Alert for Posted GRNs -->
        <?php if ($grn->grn_status === 'posted' && $grn->journal_entry_id): ?>
            <div class="bg-amber-50 border-l-4 border-amber-500 p-4 mb-6 rounded-r-xl shadow-sm">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-amber-500 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-amber-800 font-medium">
                            <strong>Note:</strong> This GRN is already posted. 
                            <span class="font-normal">Editing will automatically reverse the old journal entry and generate a new one upon saving.</span>
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <form id="editGrnForm" method="POST" action="purchase_adnan_update_grn.php" class="space-y-6">
            <input type="hidden" name="grn_id" value="<?= $grn->id ?>">
            <input type="hidden" name="purchase_order_id" value="<?= $grn->purchase_order_id ?>">
            
            <div class="bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden">
                <div class="bg-gray-900 px-8 py-4 text-white flex justify-between items-center">
                    <h3 class="font-bold text-sm uppercase tracking-widest flex items-center gap-2">
                        <i class="fas fa-edit text-primary-400"></i> Modification Form
                    </h3>
                    <span class="px-3 py-1 bg-amber-500/20 text-amber-400 rounded-full text-[10px] font-black uppercase">
                        Status: <?= $grn->grn_status ?>
                    </span>
                </div>

                <div class="p-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-10 gap-y-6">
                        
                        <!-- Left Column -->
                        <div class="space-y-6">
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">GRN Date <span class="text-red-500">*</span></label>
                                <input type="date" name="grn_date" value="<?= $grn->grn_date ?>" required
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-500 focus:bg-white outline-none transition">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Truck Number <span class="text-red-500">*</span></label>
                                <input type="text" name="truck_number" value="<?= htmlspecialchars($grn->truck_number) ?>" maxlength="20" required
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-500 focus:bg-white outline-none transition font-bold text-gray-700">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Unload Point (Branch) <span class="text-red-500">*</span></label>
                                <select name="unload_point_branch_id" required
                                        class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-500 focus:bg-white outline-none transition cursor-pointer">
                                    <option value="">Select Branch</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?= $branch->id ?>" <?= $grn->unload_point_branch_id == $branch->id ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($branch->name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Variance Remarks</label>
                                <input type="text" name="variance_remarks" value="<?= htmlspecialchars($grn->variance_remarks) ?>" 
                                       placeholder="e.g., 35kg loss during transit"
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-500 focus:bg-white outline-none transition">
                            </div>
                        </div>

                        <!-- Right Column -->
                        <div class="space-y-6">
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Quantity Received (KG) <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="number" name="quantity_received_kg" id="quantity_received_kg" value="<?= $grn->quantity_received_kg ?>" 
                                           step="0.01" min="0.01" required
                                           class="w-full px-4 py-3 bg-white border-2 border-primary-100 rounded-xl focus:ring-4 focus:ring-primary-500/10 focus:border-primary-500 outline-none transition font-black text-xl text-gray-900">
                                    <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
                                        <span class="text-gray-400 font-bold">KG</span>
                                    </div>
                                </div>
                                <p class="mt-2 text-[10px] font-bold text-gray-400 uppercase">
                                    Unit Price: <span class="text-gray-700">৳<?= number_format($grn->unit_price_per_kg, 4) ?> / KG</span>
                                </p>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 text-primary-600">Calculated Total Value</label>
                                <div class="relative">
                                    <input type="text" id="total_value" value="৳<?= number_format($grn->total_value, 2) ?>" readonly
                                           class="w-full px-4 py-3 bg-blue-50 border border-blue-100 rounded-xl font-black text-xl text-blue-700 outline-none">
                                    <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
                                        <i class="fas fa-calculator text-blue-300"></i>
                                    </div>
                                </div>
                                <p class="mt-1 text-[10px] text-blue-400 italic">Quantity × Unit Price (Auto-updated)</p>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Expected Quantity (KG)</label>
                                <input type="number" name="expected_quantity" id="expected_quantity" value="<?= $grn->expected_quantity ?>" step="0.01" min="0"
                                       class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-500 focus:bg-white outline-none transition">
                                <p class="mt-1 text-[10px] text-gray-400 italic">As per truck challan</p>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Remarks</label>
                                <textarea name="remarks" rows="1"
                                          class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-500 focus:bg-white outline-none transition"><?= htmlspecialchars($grn->remarks) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="mt-10 pt-6 border-t border-gray-100 flex items-center justify-between">
                        <div class="flex items-center gap-2 text-gray-400">
                            <i class="fas fa-user-shield text-xs"></i>
                            <span class="text-[10px] font-bold uppercase tracking-tighter">Superadmin Access Required</span>
                        </div>
                        <button type="submit" class="px-8 py-4 bg-primary-600 text-white rounded-xl hover:bg-primary-700 transition shadow-xl shadow-primary-500/20 font-black uppercase tracking-widest text-sm flex items-center gap-3">
                            <i class="fas fa-save"></i> Update Goods Received Note
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Auto-calculate total value when quantity changes
const qtyInput = document.getElementById('quantity_received_kg');
const totalOutput = document.getElementById('total_value');
const unitPrice = <?= $grn->unit_price_per_kg ?>;

qtyInput.addEventListener('input', function() {
    const qty = parseFloat(this.value) || 0;
    const total = qty * unitPrice;
    totalOutput.value = '৳' + total.toLocaleString('en-US', {
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2
    });
});

// Form validation
document.getElementById('editGrnForm').addEventListener('submit', function(e) {
    const qty = parseFloat(qtyInput.value);
    if (qty <= 0) {
        e.preventDefault();
        alert('Received Quantity must be greater than zero.');
        return false;
    }
    
    if (!confirm('CRITICAL ACTION:\nAre you sure you want to modify this GRN?\nThis will impact inventory and ledger balances.')) {
        e.preventDefault();
        return false;
    }
});
</script>

<?php require_once '../templates/footer.php'; ?>
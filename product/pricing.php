<?php
require_once '../core/init.php';

// --- SECURITY ---
$allowed_roles = [
    'Superadmin', 'admin', 'Accounts',
    'accounts-srg', 'accounts-demra',
];
restrict_access($allowed_roles);

global $db;
$current_user_name = $_SESSION['user_display_name'] ?? 'System';

// Feature #1: price CHANGES are Superadmin/Admin only. Accounts roles keep
// read access (they need to see prices) but cannot set/update/archive them.
$can_edit_price = in_array($_SESSION['user_role'] ?? '', ['Superadmin', 'admin'], true);

// Self-migrate audit log table
try {
    $db->getPdo()->exec("CREATE TABLE IF NOT EXISTS `price_change_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `variant_id` INT NOT NULL,
        `branch_id` INT NOT NULL,
        `old_price` DECIMAL(10,2) DEFAULT NULL,
        `new_price` DECIMAL(10,2) DEFAULT NULL,
        `change_type` VARCHAR(20) NOT NULL DEFAULT 'set',
        `changed_by` VARCHAR(150) DEFAULT NULL,
        `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `note` VARCHAR(255) DEFAULT NULL,
        INDEX `idx_pcl_variant` (`variant_id`),
        INDEX `idx_pcl_at` (`changed_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

// --- VARIABLE INITIALIZATION ---
$pageTitle = 'Manage Product Pricing';
$edit_mode = false;
$price_to_edit = null;
$form_action = 'add_or_update_price';
$variant_id = null;
$variant = null;

// --- LOGIC: GET PRODUCT VARIANT ---
if (!isset($_GET['variant_id'])) {
    $_SESSION['error_flash'] = 'No product variant selected.';
    header('Location: base_products.php');
    exit();
}

$variant_id = (int)$_GET['variant_id'];
$variant = $db->query(
    "SELECT pv.*, p.base_name 
     FROM product_variants pv
     JOIN products p ON pv.product_id = p.id
     WHERE pv.id = ?", 
    [$variant_id]
)->first();

if (!$variant) {
    $_SESSION['error_flash'] = 'Invalid product variant ID.';
    header('Location: base_products.php');
    exit();
}

$pageTitle = 'Pricing for: ' . htmlspecialchars($variant->sku);

// --- LOGIC: HANDLE POST REQUESTS (ADD, UPDATE, DELETE) ---
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Hard server-side guard — a non-admin cannot write prices even by
        // crafting a direct POST (the UI controls are also hidden below).
        if (!$can_edit_price) {
            $_SESSION['error_flash'] = 'Only Superadmin and Admin can change prices.';
            header('Location: pricing.php?variant_id=' . $variant_id);
            exit();
        }

        // --- ADD OR UPDATE PRICE (NEW HISTORY LOGIC) ---
        if (isset($_POST['add_or_update_price'])) {
            $branch_id = (int)$_POST['branch_id'];
            $unit_price = $_POST['unit_price'];
            $effective_date = $_POST['effective_date'];
            $status = $_POST['status'];

            // Capture old price for audit
            $old_row = $db->query(
                "SELECT unit_price FROM product_prices WHERE variant_id = ? AND branch_id = ? AND is_active = 1 LIMIT 1",
                [$variant_id, $branch_id]
            )->first();
            $log_old = $old_row ? (float)$old_row->unit_price : null;

            // 1. Deactivate any existing *active* price for this variant/branch
            $db->query(
                "UPDATE product_prices SET is_active = 0
                 WHERE variant_id = ? AND branch_id = ? AND is_active = 1",
                [$variant_id, $branch_id]
            );

            // 2. Insert the new price as the currently active one
            $db->query(
                "INSERT INTO product_prices
                 (variant_id, branch_id, unit_price, effective_date, status, is_active)
                 VALUES (?, ?, ?, ?, ?, 1)",
                [$variant_id, $branch_id, $unit_price, $effective_date, $status]
            );

            // 3. Audit log
            try { $db->insert('price_change_log', [
                'variant_id'  => $variant_id,
                'branch_id'   => $branch_id,
                'old_price'   => $log_old,
                'new_price'   => (float)$unit_price,
                'change_type' => $log_old !== null ? 'update' : 'set',
                'changed_by'  => $current_user_name,
                'changed_at'  => date('Y-m-d H:i:s'),
            ]); } catch (\Throwable $e) {}

            $_SESSION['success_flash'] = 'Price successfully set. Old price (if any) has been archived.';
            header('Location: pricing.php?variant_id=' . $variant_id); 
            exit();
        }

        // --- DELETE PRICE (Now just deactivates the active price) ---
        // We no longer truly delete, we just deactivate to preserve history.
        if (isset($_POST['deactivate_price'])) {
            $price_id = (int)$_POST['price_id'];
            // Capture before deactivating
            $deact_row = $db->query(
                "SELECT unit_price, branch_id FROM product_prices WHERE id = ? AND variant_id = ?",
                [$price_id, $variant_id]
            )->first();
            $db->query(
                "UPDATE product_prices SET is_active = 0, status = 'inactive'
                 WHERE id = ? AND variant_id = ?",
                [$price_id, $variant_id]
            );
            // Audit log
            if ($deact_row) {
                try { $db->insert('price_change_log', [
                    'variant_id'  => $variant_id,
                    'branch_id'   => $deact_row->branch_id,
                    'old_price'   => (float)$deact_row->unit_price,
                    'new_price'   => null,
                    'change_type' => 'archive',
                    'changed_by'  => $current_user_name,
                    'changed_at'  => date('Y-m-d H:i:s'),
                ]); } catch (\Throwable $e) {}
            }
            $_SESSION['success_flash'] = 'Price successfully deactivated and archived.';
            header('Location: pricing.php?variant_id=' . $variant_id);
            exit();
        }
    }

    // --- LOGIC: GET PRICE TO EDIT ---
    // "Edit" now means "Add a new price for this branch"
    if (isset($_GET['edit'])) {
        $edit_id = (int)$_GET['edit'];
        // We load the *active* price for this branch to pre-fill the form
        $price_to_edit = $db->query(
            "SELECT * FROM product_prices WHERE id = ? AND variant_id = ? AND is_active = 1", 
            [$edit_id, $variant_id]
        )->first();
        if ($price_to_edit) {
            $edit_mode = true;
        }
    }

} catch (PDOException $e) {
    if ($e->getCode() == '23000') { 
        $_SESSION['error_flash'] = 'Database Error: A unique constraint failed. This may be a bug.';
    } else {
        $_SESSION['error_flash'] = 'Database Error: ' . $e->getMessage();
    }
    header('Location: pricing.php?variant_id=' . $variant_id);
    exit();
}

// --- DATA: GET ALL BRANCHES AND PRICE HISTORY ---
$branches = $db->query("SELECT * FROM branches WHERE status = 'active' ORDER BY name ASC")->results();

// Get all price history, with the active price on top
$price_history = $db->query(
    "SELECT pp.*, b.name as branch_name 
     FROM product_prices pp
     JOIN branches b ON pp.branch_id = b.id
     WHERE pp.variant_id = ?
     ORDER BY pp.branch_id, pp.is_active DESC, pp.effective_date DESC", 
    [$variant_id]
)->results();

// Get *only* the currently active prices
$active_prices = [];
foreach ($price_history as $price) {
    if ($price->is_active) {
        $active_prices[$price->branch_id] = $price;
    }
}

// Filter branches that do NOT have an active price
$available_branches = array_filter($branches, function($branch) use ($active_prices) {
    return !isset($active_prices[$branch->id]);
});

// Price change audit log for this variant
try {
    $change_log = $db->query(
        "SELECT pcl.*, b.name AS branch_name
         FROM price_change_log pcl
         JOIN branches b ON pcl.branch_id = b.id
         WHERE pcl.variant_id = ?
         ORDER BY pcl.changed_at DESC
         LIMIT 50",
        [$variant_id]
    )->results();
} catch (\Throwable $e) { $change_log = []; }

// --- Include Header ---
require_once '../templates/header.php'; 
?>

<!-- ── Page Header ─────────────────────────────────────────────────────────── -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-lg font-bold text-gray-900 flex items-center gap-2">
            <i class="fas fa-tag text-green-500 text-base"></i>
            <?php echo htmlspecialchars($variant->base_name); ?>
            <span class="text-gray-400 font-normal">·</span>
            <span class="font-mono text-primary-600"><?php echo htmlspecialchars($variant->sku); ?></span>
        </h1>
        <p class="text-xs text-gray-400 mt-0.5">
            <?php echo htmlspecialchars($variant->weight_variant . ' / ' . $variant->grade); ?>
            &nbsp;·&nbsp; Factory Pricing
        </p>
    </div>
    <div class="flex gap-2">
        <a href="products.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold border border-gray-200 text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">
            <i class="fas fa-th text-[10px]"></i> Overview
        </a>
        <a href="manage_variants.php?product_id=<?php echo $variant->product_id; ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold border border-gray-200 text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">
            <i class="fas fa-arrow-left text-[10px]"></i> Variants
        </a>
    </div>
</div>

<!-- ── Set / Update Price Form (Superadmin/Admin only) ────────────────────── -->
<?php if (!$can_edit_price): ?>
<div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-4 text-xs text-amber-800 flex items-center gap-2">
    <i class="fas fa-lock text-amber-500"></i>
    You can view prices, but only <strong>Superadmin</strong> and <strong>Admin</strong> can set or change them.
</div>
<?php else: ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-4">
    <h2 class="text-sm font-bold text-gray-800 mb-4 flex items-center gap-2">
        <i class="fas fa-<?php echo $edit_mode ? 'pencil-alt' : 'plus-circle'; ?> text-primary-500 text-xs"></i>
        <?php echo $edit_mode ? 'Update Price' : 'Set New Price'; ?>
    </h2>
    <form action="pricing.php?variant_id=<?php echo $variant_id; ?>" method="POST"
          class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <input type="hidden" name="<?php echo $form_action; ?>" value="1">

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Factory / Branch <span class="text-red-500">*</span></label>
            <select name="branch_id" required <?php echo $edit_mode ? 'disabled' : ''; ?>
                    class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-400 focus:border-primary-400 outline-none bg-white">
                <?php if ($edit_mode):
                    $eb_name = $db->query("SELECT name FROM branches WHERE id = ?", [$price_to_edit->branch_id])->first()->name ?? ''; ?>
                    <option value="<?php echo $price_to_edit->branch_id; ?>" selected><?php echo htmlspecialchars($eb_name); ?></option>
                    <input type="hidden" name="branch_id" value="<?php echo $price_to_edit->branch_id; ?>">
                <?php else: ?>
                    <option value="" disabled selected>Select branch…</option>
                    <?php foreach ($available_branches as $b): ?>
                    <option value="<?php echo $b->id; ?>"><?php echo htmlspecialchars($b->name); ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <?php if (!$edit_mode && empty($available_branches)): ?>
            <p class="text-[10px] text-amber-600 mt-1">All branches priced — use Update below.</p>
            <?php endif; ?>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Unit Price (৳) <span class="text-red-500">*</span></label>
            <input type="number" step="0.01" name="unit_price" required
                   value="<?php echo htmlspecialchars($price_to_edit->unit_price ?? ''); ?>"
                   placeholder="e.g. 4100"
                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-bold focus:ring-2 focus:ring-primary-400 focus:border-primary-400 outline-none">
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Effective Date <span class="text-red-500">*</span></label>
            <input type="date" name="effective_date" required value="<?php echo date('Y-m-d'); ?>"
                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-400 focus:border-primary-400 outline-none">
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Type</label>
            <select name="status" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-400 focus:border-primary-400 outline-none bg-white">
                <option value="active"       <?php echo ($price_to_edit->status ?? 'active') === 'active'       ? 'selected' : ''; ?>>Active</option>
                <option value="promotional"  <?php echo ($price_to_edit->status ?? '')        === 'promotional'  ? 'selected' : ''; ?>>Promotional</option>
            </select>
        </div>

        <div class="sm:col-span-2 lg:col-span-4 flex items-center justify-between pt-1">
            <p class="text-[10px] text-gray-400 flex items-center gap-1">
                <i class="fas fa-info-circle text-blue-400"></i>
                Saving archives the previous price — history is preserved.
            </p>
            <div class="flex gap-2">
                <?php if ($edit_mode): ?>
                <a href="pricing.php?variant_id=<?php echo $variant_id; ?>"
                   class="px-4 py-1.5 border border-gray-200 text-xs text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">Cancel</a>
                <?php endif; ?>
                <button type="submit" <?php echo !$edit_mode && empty($available_branches) ? 'disabled' : ''; ?>
                        class="px-5 py-1.5 bg-primary-600 text-white text-xs font-semibold rounded-lg hover:bg-primary-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                    <i class="fas fa-save mr-1"></i> <?php echo $edit_mode ? 'Update Price' : 'Set Price'; ?>
                </button>
            </div>
        </div>
    </form>
</div>
<?php endif; /* $can_edit_price */ ?>

<!-- ── Active Prices + Price History ─────────────────────────────────────── -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-4">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <h3 class="text-sm font-bold text-gray-800">Price History — <?php echo htmlspecialchars($variant->sku); ?></h3>
        <span class="text-[10px] text-gray-400"><?php echo count($price_history); ?> records</span>
    </div>
    <?php if (empty($price_history)): ?>
        <p class="text-xs text-gray-400 px-5 py-5 text-center">No prices set yet. Use the form above.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100">
                    <th class="px-4 py-2.5 text-left text-[10px] font-semibold text-gray-400 uppercase">Branch</th>
                    <th class="px-4 py-2.5 text-right text-[10px] font-semibold text-gray-400 uppercase">Price</th>
                    <th class="px-4 py-2.5 text-left text-[10px] font-semibold text-gray-400 uppercase">Effective</th>
                    <th class="px-4 py-2.5 text-left text-[10px] font-semibold text-gray-400 uppercase">Status</th>
                    <th class="px-4 py-2.5 text-right text-[10px] font-semibold text-gray-400 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
            <?php foreach ($price_history as $price): ?>
                <tr class="<?php echo $price->is_active ? 'bg-green-50/40' : 'opacity-50'; ?> hover:opacity-100 transition-opacity">
                    <td class="px-4 py-2.5 text-xs font-semibold text-gray-800 whitespace-nowrap">
                        <?php echo htmlspecialchars($price->branch_name); ?>
                    </td>
                    <td class="px-4 py-2.5 text-right whitespace-nowrap">
                        <span class="text-sm font-bold <?php echo $price->is_active ? 'text-gray-900' : 'text-gray-400 line-through'; ?>">
                            ৳<?php echo number_format($price->unit_price, 0); ?>
                        </span>
                    </td>
                    <td class="px-4 py-2.5 text-xs text-gray-500 whitespace-nowrap">
                        <?php echo $price->effective_date ? date('d M Y', strtotime($price->effective_date)) : '—'; ?>
                    </td>
                    <td class="px-4 py-2.5 whitespace-nowrap">
                        <?php if ($price->is_active): ?>
                            <span class="text-[10px] px-2 py-0.5 bg-green-100 text-green-800 rounded-full font-semibold">Active</span>
                        <?php else: ?>
                            <span class="text-[10px] px-2 py-0.5 bg-gray-100 text-gray-500 rounded-full">Archived</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-2.5 text-right whitespace-nowrap">
                        <?php if ($price->is_active && $can_edit_price): ?>
                        <div class="inline-flex gap-3">
                            <a href="pricing.php?variant_id=<?php echo $variant_id; ?>&edit=<?php echo $price->id; ?>"
                               class="text-xs text-primary-600 hover:text-primary-800 font-semibold">
                                <i class="fas fa-pencil-alt text-[10px] mr-0.5"></i> Update
                            </a>
                            <form method="POST" class="inline"
                                  onsubmit="return confirm('Archive this price?');">
                                <input type="hidden" name="deactivate_price" value="1">
                                <input type="hidden" name="price_id" value="<?php echo $price->id; ?>">
                                <button class="text-xs text-red-500 hover:text-red-700 font-semibold">
                                    <i class="fas fa-archive text-[10px] mr-0.5"></i> Archive
                                </button>
                            </form>
                        </div>
                        <?php else: ?>
                            <span class="text-[10px] text-gray-300">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── Price Change Audit Log ─────────────────────────────────────────────── -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <h3 class="text-sm font-bold text-gray-800 flex items-center gap-2">
            <i class="fas fa-history text-purple-500 text-xs"></i> Change Log
        </h3>
        <span class="text-[10px] text-gray-400"><?php echo count($change_log); ?> entries</span>
    </div>

    <?php if (empty($change_log)): ?>
        <p class="text-xs text-gray-400 px-5 py-4 text-center">No changes logged yet — changes appear here after the first price update.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100">
                    <th class="px-4 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase">When</th>
                    <th class="px-4 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase">Who</th>
                    <th class="px-4 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase">Branch</th>
                    <th class="px-4 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase">Old</th>
                    <th class="px-4 py-2 text-right text-[10px] font-semibold text-gray-400 uppercase">New</th>
                    <th class="px-4 py-2 text-left text-[10px] font-semibold text-gray-400 uppercase">Change</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
            <?php
            $type_map = [
                'set'     => ['bg-green-100 text-green-800',   'Set'],
                'update'  => ['bg-blue-100 text-blue-800',     'Updated'],
                'archive' => ['bg-gray-100 text-gray-600',     'Archived'],
                'engine'  => ['bg-purple-100 text-purple-800', 'Engine'],
            ];
            foreach ($change_log as $entry):
                [$tcls, $tlabel] = $type_map[$entry->change_type] ?? ['bg-gray-100 text-gray-600', $entry->change_type];
                $delta = ($entry->new_price !== null && $entry->old_price !== null)
                    ? (float)$entry->new_price - (float)$entry->old_price : null;
            ?>
            <tr class="hover:bg-gray-50/60 transition-colors">
                <td class="px-4 py-2.5 whitespace-nowrap">
                    <div class="text-xs font-semibold text-gray-700"><?php echo date('d M Y', strtotime($entry->changed_at)); ?></div>
                    <div class="text-[10px] text-gray-400"><?php echo date('H:i', strtotime($entry->changed_at)); ?></div>
                </td>
                <td class="px-4 py-2.5 whitespace-nowrap">
                    <span class="text-xs text-gray-700"><?php echo htmlspecialchars($entry->changed_by ?? '—'); ?></span>
                </td>
                <td class="px-4 py-2.5 whitespace-nowrap">
                    <span class="text-xs text-gray-600"><?php echo htmlspecialchars($entry->branch_name); ?></span>
                </td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                    <?php if ($entry->old_price !== null): ?>
                    <span class="text-xs text-gray-400 line-through">৳<?php echo number_format($entry->old_price, 0); ?></span>
                    <?php else: ?>
                    <span class="text-[10px] text-gray-300">—</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                    <?php if ($entry->new_price !== null): ?>
                    <span class="text-sm font-bold text-gray-900">৳<?php echo number_format($entry->new_price, 0); ?></span>
                    <?php else: ?>
                    <span class="text-[10px] text-gray-300">—</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-2.5 whitespace-nowrap">
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] px-1.5 py-0.5 rounded font-semibold <?php echo $tcls; ?>"><?php echo $tlabel; ?></span>
                        <?php if ($delta !== null): ?>
                        <span class="text-[10px] font-bold <?php echo $delta > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                            <?php echo ($delta > 0 ? '+' : '') . number_format($delta, 0); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once '../templates/footer.php'; ?>


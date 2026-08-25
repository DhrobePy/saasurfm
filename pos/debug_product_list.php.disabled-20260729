<?php
require_once '../core/init.php'; // Include the core setup

// --- Basic Security (optional but good practice) ---
$allowed_roles = ['Superadmin', 'admin']; // Or include POS roles if needed
restrict_access($allowed_roles); // Reuse your access control

// Get DB and basic variables
global $db;
$pageTitle = 'DEBUG - Product List';
$error = null;
$branch_id = null; // We still need the branch ID for the query
$products = [];
$user_id = $_SESSION['user_id'] ?? null;

// --- GET USER'S BRANCH (Copied from pos/index.php) ---
// Minimal version just to get branch_id
if ($user_id) {
    try {
        $employee_info = $db->query("SELECT e.branch_id FROM employees e WHERE e.user_id = ?", [$user_id])->first();
        if ($employee_info && $employee_info->branch_id) {
            $branch_id = $employee_info->branch_id;
        } else {
             if (!in_array($_SESSION['user_role'], ['Superadmin', 'admin'])) { throw new Exception("User not linked to branch."); }
             // Admin default logic (optional for debug)
             $default_branch = $db->query("SELECT id FROM branches WHERE status = 'active' ORDER BY id LIMIT 1")->first();
             if ($default_branch) $branch_id = $default_branch->id;
             else throw new Exception("No active branch found for default.");
        }
         error_log("[DEBUG PAGE] Branch ID determined: " . ($branch_id ?? 'NULL'));
    } catch (Exception $e) { $error = "Branch Error: " . $e->getMessage(); error_log("[DEBUG PAGE] Branch Error: " . $e->getMessage()); }
} else { $error = "No User ID"; }


// --- LOAD PRODUCTS (Copied EXACTLY from pos/index.php) ---
if (!$error) {
    try {
        $queryParams = [];
        $inventoryJoinClause = "inv.variant_id = pv.id";
        if ($branch_id !== null) {
            $inventoryJoinClause .= " AND inv.branch_id = :branch_id_inv";
            $queryParams['branch_id_inv'] = $branch_id;
        }

        $sql = "SELECT
                pv.id as variant_id, pv.sku, pv.weight_variant, pv.grade, pv.unit_of_measure, p.base_name,
                (SELECT pp.unit_price FROM product_prices pp WHERE pp.variant_id = pv.id AND pp.is_active = 1 ORDER BY pp.effective_date DESC, pp.created_at DESC LIMIT 1) as unit_price,
                COALESCE(inv.quantity, 0) as stock_quantity
            FROM product_variants pv
            JOIN products p ON pv.product_id = p.id
            " . ($branch_id !== null ? "LEFT JOIN inventory inv ON {$inventoryJoinClause}" : "") . "
            WHERE p.status = 'active' AND pv.status = 'active'
              AND EXISTS (SELECT 1 FROM product_prices pp_exists WHERE pp_exists.variant_id = pv.id AND pp_exists.is_active = 1)
            ORDER BY p.base_name, pv.sku";

        $products_raw = $db->query($sql, $queryParams)->results();
        error_log("[DEBUG PAGE] Raw products found: " . count($products_raw));

        // Filter and sanitize (same logic)
        $valid_products = [];
        foreach ($products_raw as $product) {
            if (isset($product->unit_price) && $product->unit_price !== null && $product->unit_price > 0) {
                // Basic sanitization for display
                foreach ($product as $key => $value) {
                    if (is_string($value)) $product->$key = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                }
                $valid_products[] = $product;
            }
        }
        $products = $valid_products;
        error_log("[DEBUG PAGE] Valid products count: " . count($products));

    } catch (Exception $e) {
        $error = "Product Query Error: " . $e->getMessage();
        error_log("[DEBUG PAGE] Product Query Error: " . $e->getMessage());
        $products = []; // Ensure empty on error
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <style>
        body { font-family: sans-serif; padding: 20px; }
        .error { color: red; border: 1px solid red; padding: 10px; margin-bottom: 15px; }
        .product { border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; background-color: #f9f9f9; }
        .product strong { display: inline-block; min-width: 120px; }
        pre { background-color: #eee; padding: 10px; border: 1px solid #ddd; overflow-x: auto; font-size: 12px;}
    </style>
</head>
<body>

    <h1><?php echo $pageTitle; ?></h1>
    <p>Branch ID Used: <?php echo htmlspecialchars($branch_id ?? 'None Determined', ENT_QUOTES, 'UTF-8'); ?></p>
    <hr>

    <?php if ($error): ?>
        <div class="error">
            <h2>Error Occurred:</h2>
            <p><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    <?php endif; ?>

    <h2>Products Fetched (<?php echo count($products); ?>):</h2>

    <?php if (empty($products) && !$error): ?>
        <p>No valid products found for this branch or criteria.</p>
    <?php else: ?>
        <?php foreach ($products as $p): ?>
            <div class="product">
                <p><strong>Variant ID:</strong> <?php echo $p->variant_id; ?></p>
                <p><strong>Base Name:</strong> <?php echo $p->base_name; ?></p>
                <p><strong>SKU:</strong> <?php echo $p->sku; ?></p>
                <p><strong>Variant:</strong> <?php echo $p->weight_variant; ?> / <?php echo $p->grade; ?></p>
                <p><strong>Unit Price:</strong> <?php echo number_format($p->unit_price, 2); ?></p>
                <p><strong>Stock Qty:</strong> <?php echo $p->stock_quantity; ?></p>
                <p><strong>Unit Measure:</strong> <?php echo $p->unit_of_measure; ?></p>
                <!-- Raw Data -->
                <!-- <p><strong>Raw Data:</strong> <pre><?php print_r($p); ?></pre></p> -->
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <hr>
    <h2>Full $products Array Dump:</h2>
    <pre><?php print_r($products); ?></pre>

</body>
</html>

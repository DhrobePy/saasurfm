<?php
require_once '../core/init.php';
global $db; // Make $db globally available

// ==============================================================================
// === NEW: EXPORT HANDLER
// ==============================================================================
// This logic runs *before* any HTML is sent to the browser.
// It checks for a ?export=... query in the URL.

/**
 * Fetches all product data for export.
 * This query joins products, variants, prices, and branches.
 * It respects the "no stock" rule by *never* joining the `inventory` table.
 *
 * @param object $db The database connection.
 * @return array The flattened data for export.
 */
function fetch_export_data($db) {
    // This query gets the *currently active* price for each variant at each branch.
    $sql = "
        SELECT 
            p.base_name,
            pv.sku,
            pv.weight_variant,
            pv.grade,
            pv.unit_of_measure,
            b.name as branch_name,
            pp.unit_price,
            pp.effective_date
        FROM product_prices pp
        JOIN product_variants pv ON pp.variant_id = pv.id
        JOIN products p ON pv.product_id = p.id
        JOIN branches b ON pp.branch_id = b.id
        WHERE pp.is_active = 1
        ORDER BY p.base_name, pv.sku, b.name
    ";
    return $db->query($sql)->results();
}

/**
 * Generates and streams a CSV file to the browser.
 *
 * @param array $data The data from fetch_export_data().
 */
function generate_csv($data) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="products_export_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV Headers
    fputcsv($output, [
        'Product Name', 
        'SKU', 
        'Variant (Weight/Size)', 
        'Grade', 
        'Unit',
        'Factory/Branch', 
        'Active Price (BDT)',
        'Price Effective Date'
    ]);
    
    // CSV Rows
    foreach ($data as $row) {
        fputcsv($output, [
            $row->base_name,
            $row->sku,
            $row->weight_variant,
            $row->grade,
            $row->unit_of_measure,
            $row->branch_name,
            $row->unit_price,
            $row->effective_date
        ]);
    }
    
    fclose($output);
    exit();
}

/**
 * === THIS FUNCTION IS NOW FIXED ===
 * Generates a printable HTML page using the existing core/classes/PDF.php.
 *
 * @param array $data The data from fetch_export_data().
 * @param object $db The database connection.
 */
function generate_pdf($data, $db) {
    // Check if the PDF class from core/classes/PDF.php is loaded
    if (!class_exists('PDF')) {
        $_SESSION['error_flash'] = 'Error: The PDF generation class (core/classes/PDF.php) could not be found.';
        header('Location: products.php');
        exit();
    }

    // 1. Instantiate your class *correctly* (it expects $pdo/$db)
    $pdf = new PDF($db); 

    // 2. Check if the new method exists (which we will add in Step 2)
    if (!method_exists($pdf, 'generate_product_report')) {
         $_SESSION['error_flash'] = 'Error: The PDF class is missing the required "generate_product_report" method. Please update core/classes/PDF.php';
        header('Location: products.php');
        exit();
    }
    
    // 3. Call the new method. Your class *returns* HTML, so we must echo it.
    // This will output the full HTML page with the window.print() script.
    echo $pdf->generate_product_report($data);
    exit();
}

// --- Check for export actions ---
if (isset($_GET['export'])) {
    $data = fetch_export_data($db);
    if ($_GET['export'] === 'csv') {
        generate_csv($data);
    }
    if ($_GET['export'] === 'pdf') {
        // Pass the $db object to the function
        generate_pdf($data, $db);
    }
}

// ==============================================================================
// === REGULAR PAGE LOGIC (IF NOT EXPORTING)
// ==============================================================================

// --- SECURITY ---
// Allow all logged-in users to view this page.
restrict_access([]); 

// --- PAGE DATA ---
$pageTitle = 'Products At a Glance';

// Get all base products
$products = $db->query("SELECT * FROM products WHERE status = 'active' ORDER BY base_name ASC")->results();

// --- Include Header ---
require_once '../templates/header.php'; 
?>

<!-- ======================================== -->
<!-- 1. PAGE HEADER & EXPORT BUTTONS -->
<!-- ======================================== -->
<div class="flex justify-between items-center mb-6">
    <h1 class="text-3xl font-bold text-gray-900">Products At a Glance</h1>
    
    <!-- NEW: Export Button Group -->
    <div class="flex space-x-2">
        <a href="products.php?export=csv" 
           class="flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
            <i class="fas fa-file-csv mr-2"></i>Export CSV
        </a>
        <a href="products.php?export=pdf" 
           target="_blank" 
           class="flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
            <i class="fas fa-file-pdf mr-2"></i>Export PDF
        </a>
    </div>
</div>

<p class="text-gray-600 mb-6">
    A complete overview of all products, their variants, active factory pricing, and current stock levels.
</p>

<!-- ======================================== -->
<!-- 2. PRODUCTS LIST (ACCORDION) -->
<!-- ======================================== -->
<div class="space-y-4" x-data="{ openProductId: null }">
    <?php if (empty($products)): ?>
        <div class="bg-white rounded-lg shadow-md p-6 text-center text-gray-500">
            No base products have been created yet. 
            <a href="base_products.php" class="text-primary-600 hover:underline font-medium">Start by adding a base product</a>.
        </div>
    <?php endif; ?>

    <?php 
    // Loop through each Base Product
    foreach ($products as $product): 
        // Get all variants for this product
        $variants = $db->query(
            "SELECT * FROM product_variants WHERE product_id = ? AND status = 'active' ORDER BY weight_variant, grade", 
            [$product->id]
        )->results();
    ?>
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <!-- Product Header (Clickable) -->
            <button @click="openProductId = (openProductId === <?php echo $product->id; ?>) ? null : <?php echo $product->id; ?>" 
                    class="w-full flex justify-between items-center p-6 text-left">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($product->base_name); ?></h2>
                    <p class="text-sm text-gray-500">
                        (<?php echo count($variants); ?> variants) - Base SKU: <?php echo htmlspecialchars($product->base_sku); ?>
                    </p>
                </div>
                <div class="text-primary-600">
                    <i class="fas fa-chevron-down transition-transform" :class="{ 'rotate-180': openProductId === <?php echo $product->id; ?> }"></i>
                </div>
            </button>

            <!-- Variants Table (Collapsible) -->
            <div x-show="openProductId === <?php echo $product->id; ?>" 
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 max-h-0"
                 x-transition:enter-end="opacity-100 max-h-screen"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 max-h-screen"
                 x-transition:leave-end="opacity-0 max-h-0"
                 class="border-t border-gray-200 overflow-hidden"
                 >
                
                <?php if (empty($variants)): ?>
                    <p class="p-6 text-gray-500">
                        No variants have been added for this product. 
                        <a href="manage_variants.php?product_id=<?php echo $product->id; ?>" class="text-primary-600 hover:underline font-medium">Add variants now</a>.
                    </p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">SKU / Variant</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Active Prices (by Factory)</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Available Stock</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php 
                                // Loop through each Variant
                                foreach ($variants as $variant): 
                                    
                                    // Get all *active* prices for this variant
                                    $prices = $db->query(
                                        "SELECT b.name as branch_name, pp.unit_price
                                         FROM product_prices pp
                                         JOIN branches b ON pp.branch_id = b.id
                                         WHERE pp.variant_id = ? AND pp.is_active = 1
                                         ORDER BY b.name",
                                        [$variant->id]
                                    )->results();

                                    // Get all stock for this variant
                                    $stocks = $db->query(
                                        "SELECT b.name as branch_name, i.quantity
                                         FROM inventory i
                                         JOIN branches b ON i.branch_id = b.id
                                         WHERE i.variant_id = ?
                                         ORDER BY b.name",
                                        [$variant->id]
                                    )->results();
                                ?>
                                    <tr>
                                        <!-- Variant Info -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($variant->sku); ?></div>
                                            <div class="text-sm text-gray-500">
                                                <?php echo htmlspecialchars($variant->weight_variant); ?> / <?php echo htmlspecialchars($variant->grade); ?>
                                            </div>
                                        </td>
                                        
                                        <!-- Prices Info -->
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php if (empty($prices)): ?>
                                                <span class="text-red-500">No active prices set.</span>
                                            <?php else: ?>
                                                <ul class="list-disc list-inside">
                                                    <?php foreach ($prices as $price): ?>
                                                        <li>
                                                            <span class="font-medium text-gray-900"><?php echo htmlspecialchars($price->branch_name); ?>:</span>
                                                            <?php echo number_format($price->unit_price, 2); ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Stock Info -->
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php if (empty($stocks)): ?>
                                                <span class="text-gray-500">No stock records.</span>
                                            <?php else: ?>
                                                <ul class="list-disc list-inside">
                                                    <?php foreach ($stocks as $stock): ?>
                                                        <li>
                                                            <span class="font-medium text-gray-900"><?php echo htmlspecialchars($stock->branch_name); ?>:</span>
                                                            <span class="font-bold <?php echo ($stock->quantity > 0) ? 'text-green-700' : 'text-red-600'; ?>">
                                                                <?php echo $stock->quantity; ?>
                                                            </span>
                                                            <span class="text-xs">(<?php echo $variant->unit_of_measure; ?>)</span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <!-- Actions -->
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-3">
                                            <!-- View (Cute Eye) -->
                                            <a href="#" class="text-gray-400 hover:text-blue-600" title="View Details (coming soon)">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <!-- Edit -->
                                            <a href="manage_variants.php?product_id=<?php echo $product->id; ?>&edit=<?php echo $variant->id; ?>" class="text-gray-400 hover:text-primary-600" title="Edit Variant">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <!-- Manage Prices -->
                                            <a href="pricing.php?variant_id=<?php echo $variant->id; ?>" class="text-gray-400 hover:text-green-600" title="Manage Prices">
                                                <i class="fas fa-dollar-sign"></i>
                                            </a>
                                            
                                            <!-- Manage Stock -->
                                            <a href="inventory.php?variant_id=<?php echo $variant->id; ?>" class="text-gray-400 hover:text-orange-600" title="Manage Stock">
                                                <i class="fas fa-boxes"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php
// --- Include Footer ---
require_once '../templates/footer.php'; 
?>


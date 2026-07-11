<?php
require_once '../core/init.php';
global $db;

// ── Pricing helpers (shared by export + page display) ─────────────────────────
function load_pricing_config($db): array {
    $file = __DIR__ . '/pricing_engine_config.json';
    $def  = [
        'weight_map'            => ['50'=>'50','74'=>'74','50KG'=>'50','74KG'=>'74','50 KG'=>'50','74 KG'=>'74','50kg'=>'50','74kg'=>'74'],
        'mini_truck_surcharges' => [],
        'branch_surcharges'     => [],
    ];
    $cfg = file_exists($file)
        ? array_replace_recursive($def, json_decode(file_get_contents($file), true) ?? [])
        : $def;
    try {
        $r = $db->query("SELECT setting_value FROM app_settings WHERE setting_key='mini_truck_surcharges'")->first();
        if ($r) { $a = json_decode($r->setting_value, true) ?? []; if (!empty($a)) $cfg['mini_truck_surcharges'] = array_replace_recursive($cfg['mini_truck_surcharges'], $a); }
        $r = $db->query("SELECT setting_value FROM app_settings WHERE setting_key='branch_surcharges'")->first();
        if ($r) { $a = json_decode($r->setting_value, true) ?? []; if (!empty($a)) $cfg['branch_surcharges'] = array_replace_recursive($cfg['branch_surcharges'], $a); }
    } catch (\Throwable $e) {}
    return $cfg;
}

function detect_wc(string $wv, array $wmap): ?string {
    return $wmap[$wv] ?? $wmap[strtoupper($wv)] ?? $wmap[strtolower($wv)] ?? null;
}

// ==============================================================================
// === EXPORT HANDLER
// ==============================================================================

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
            b.id AS branch_id,
            b.name AS branch_name,
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

function generate_csv($data, $db) {
    $cfg    = load_pricing_config($db);
    $wmap   = $cfg['weight_map'];
    $mt_sur = $cfg['mini_truck_surcharges'];

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="products_export_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Product Name', 'SKU', 'Variant (Weight/Size)', 'Grade / Type', 'Unit',
        'Factory/Branch', 'BT Price (BDT)', 'MT Surcharge (BDT)', 'MT Price (BDT)',
        'Price Effective Date',
    ]);

    foreach ($data as $row) {
        $wc    = detect_wc($row->weight_variant, $wmap);
        $bid   = (string)$row->branch_id;
        $surch = ($wc !== null && isset($mt_sur[$bid]["surcharge_{$wc}"]))
            ? (float)$mt_sur[$bid]["surcharge_{$wc}"] : null;
        $mt    = ($surch !== null) ? ((float)$row->unit_price + $surch) : null;

        fputcsv($out, [
            $row->base_name,
            $row->sku,
            $row->weight_variant,
            $row->grade ?: '—',
            $row->unit_of_measure,
            $row->branch_name,
            number_format((float)$row->unit_price, 2, '.', ''),
            $surch !== null ? number_format($surch, 2, '.', '') : '—',
            $mt    !== null ? number_format($mt,    2, '.', '') : '—',
            $row->effective_date,
        ]);
    }
    fclose($out);
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
        generate_csv($data, $db);
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
$pageTitle = 'Products';

// Ensure is_factory column exists (same migration as pricing_engine.php and settings.php)
try { $db->getPdo()->exec("ALTER TABLE `branches` ADD COLUMN `is_factory` TINYINT(1) NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

// Summary counts
$total_products  = (int)($db->query("SELECT COUNT(*) AS c FROM products WHERE status='active'")->first()->c ?? 0);
$total_variants  = (int)($db->query("SELECT COUNT(*) AS c FROM product_variants WHERE status='active'")->first()->c ?? 0);
$priced_variants = (int)($db->query("SELECT COUNT(DISTINCT variant_id) AS c FROM product_prices WHERE is_active=1")->first()->c ?? 0);

// Factory branches for price columns
$factories = $db->query("SELECT id, name, code FROM branches WHERE is_factory=1 AND status='active' ORDER BY name")->results();
if (empty($factories)) {
    $factories = $db->query("SELECT id, name, code FROM branches WHERE status='active' ORDER BY name LIMIT 4")->results();
}

// Load pricing config for MT price computation
$pricing_cfg   = load_pricing_config($db);
$weight_map    = $pricing_cfg['weight_map'];
$mt_surcharges = $pricing_cfg['mini_truck_surcharges'];

// All active variants with product info — single query
$all_variants_raw = $db->query(
    "SELECT pv.id, pv.sku, pv.weight_variant, pv.grade, pv.unit_of_measure, pv.product_id,
            p.base_name, p.base_sku
     FROM product_variants pv
     JOIN products p ON pv.product_id = p.id
     WHERE pv.status='active' AND p.status='active'
     ORDER BY pv.grade, p.base_name, pv.weight_variant"
)->results();

// All active prices in one query → [variant_id][branch_id] = price
$price_map = [];
foreach ($db->query(
    "SELECT pp.variant_id, pp.branch_id, pp.unit_price
     FROM product_prices pp
     JOIN product_variants pv ON pp.variant_id = pv.id
     JOIN products p ON pv.product_id = p.id
     WHERE pp.is_active=1 AND pv.status='active' AND p.status='active'"
)->results() as $pr) {
    $price_map[$pr->variant_id][$pr->branch_id] = (float)$pr->unit_price;
}

// Group variants: by grade when grade is set; by product name when grade is empty
$by_group = [];
foreach ($all_variants_raw as $v) {
    $g = trim($v->grade ?? '');
    if ($g !== '') {
        $key = 'g_' . $g;
        if (!isset($by_group[$key])) $by_group[$key] = ['type' => 'grade',   'label' => $g,           'items' => []];
    } else {
        $key = 'p_' . $v->product_id;
        if (!isset($by_group[$key])) $by_group[$key] = ['type' => 'product', 'label' => $v->base_name, 'items' => []];
    }
    $by_group[$key]['items'][] = $v;
}
// Grade groups first (alphabetically), then product groups (alphabetically)
uasort($by_group, function($a, $b) {
    if ($a['type'] !== $b['type']) return $a['type'] === 'grade' ? -1 : 1;
    return strcmp($a['label'], $b['label']);
});

// Distinct product list for "Add variant" links
$products = $db->query("SELECT id, base_name, base_sku FROM products WHERE status='active' ORDER BY base_name")->results();

function grade_palette(string $g, string $type = 'grade'): array {
    if ($type === 'product') return ['badge'=>'bg-rose-100 text-rose-800 border-rose-200',  'dot'=>'bg-rose-400'];
    $u = strtoupper($g);
    if (str_contains($u,'A')) return ['badge'=>'bg-green-100 text-green-800 border-green-200', 'dot'=>'bg-green-500'];
    if (str_contains($u,'B')) return ['badge'=>'bg-blue-100 text-blue-800 border-blue-200',   'dot'=>'bg-blue-500'];
    if (str_contains($u,'C')) return ['badge'=>'bg-amber-100 text-amber-800 border-amber-200','dot'=>'bg-amber-500'];
    return                           ['badge'=>'bg-gray-100 text-gray-700 border-gray-200',   'dot'=>'bg-gray-400'];
}

function branch_label(object $br): string {
    $c = strtoupper(trim($br->code ?? ''));
    return ($c && strlen($c) <= 8) ? $c : strtoupper(explode(' ', $br->name)[0]);
}

// --- Include Header ---
require_once '../templates/header.php';
?>

<!-- ── Page Header ─────────────────────────────────────────────────────────── -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <div>
        <h1 class="text-lg font-bold text-gray-900 flex items-center gap-2">
            <i class="fas fa-boxes text-primary-500 text-base"></i> Products
        </h1>
        <p class="text-xs text-gray-400 mt-0.5">Factory price overview — Big Truck &amp; Mini Truck per factory</p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        <a href="pricing_engine.php"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition-colors">
            <i class="fas fa-calculator text-[10px]"></i> Smart Pricing
        </a>
        <a href="base_products.php"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold border border-gray-200 text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">
            <i class="fas fa-plus text-[10px]"></i> Add Product
        </a>
        <a href="products.php?export=csv"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors">
            <i class="fas fa-file-csv text-[10px]"></i> CSV
        </a>
        <a href="products.php?export=pdf" target="_blank"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
            <i class="fas fa-file-pdf text-[10px]"></i> PDF
        </a>
    </div>
</div>

<!-- ── Stats + Factory Legend ──────────────────────────────────────────────── -->
<div class="flex flex-wrap items-center gap-3 mb-5">
    <div class="flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-100 rounded-lg shadow-sm">
        <span class="text-[10px] font-semibold text-gray-400 uppercase">Products</span>
        <span class="text-sm font-bold text-gray-800"><?php echo $total_products; ?></span>
    </div>
    <div class="flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-100 rounded-lg shadow-sm">
        <span class="text-[10px] font-semibold text-gray-400 uppercase">SKUs</span>
        <span class="text-sm font-bold text-gray-800"><?php echo $total_variants; ?></span>
    </div>
    <div class="flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-100 rounded-lg shadow-sm">
        <span class="text-[10px] font-semibold text-gray-400 uppercase">Priced</span>
        <span class="text-sm font-bold text-gray-800"><?php echo $priced_variants; ?></span>
        <span class="text-[10px] text-gray-400">/ <?php echo $total_variants; ?></span>
    </div>

    <!-- Factory column legend -->
    <?php if (!empty($factories)): ?>
    <div class="ml-auto flex items-center gap-2 flex-wrap">
        <span class="text-[10px] text-gray-400 font-semibold uppercase">Price columns:</span>
        <?php foreach ($factories as $fi => $f):
            $colors = ['bg-blue-50 text-blue-700 border-blue-200','bg-violet-50 text-violet-700 border-violet-200','bg-teal-50 text-teal-700 border-teal-200','bg-orange-50 text-orange-700 border-orange-200'];
            $cc = $colors[$fi % count($colors)];
        ?>
        <span class="text-[10px] font-bold px-2 py-0.5 rounded border <?php echo $cc; ?>">
            <?php echo htmlspecialchars($f->name); ?>
        </span>
        <?php endforeach; ?>
        <span class="text-[10px] text-gray-400 italic">BT = Big Truck&nbsp;&nbsp;|&nbsp;&nbsp;MT = Mini Truck</span>
    </div>
    <?php endif; ?>
</div>

<!-- ── Search / Filter ─────────────────────────────────────────────────────── -->
<div class="mb-4">
    <div class="relative max-w-xs">
        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-300 text-[11px] pointer-events-none"></i>
        <input id="sku-search" type="text" placeholder="Search product, SKU, or pack…"
               class="w-full pl-8 pr-3 py-1.5 text-xs border border-gray-200 rounded-lg focus:ring-2 focus:ring-primary-300 focus:border-primary-400 outline-none bg-white shadow-sm">
    </div>
</div>

<!-- ── Variant Group Filter Tabs ──────────────────────────────────────────── -->
<?php if (count($by_group) > 1): ?>
<div class="flex gap-1 mb-4 border-b border-gray-200 flex-wrap">
    <button class="grade-tab px-3 py-1.5 text-xs font-semibold text-white bg-gray-700 rounded-t-lg -mb-px border border-b-white border-gray-200 transition-colors" data-grade="all">
        All
    </button>
    <?php foreach ($by_group as $gkey => $gdata):
        $pal = grade_palette($gdata['label'], $gdata['type']);
    ?>
    <button class="grade-tab px-3 py-1.5 text-xs font-semibold text-gray-500 hover:text-gray-700 rounded-t-lg -mb-px border border-transparent hover:border-gray-200 transition-colors" data-grade="<?php echo htmlspecialchars($gkey); ?>">
        <span class="inline-block w-1.5 h-1.5 rounded-full <?php echo $pal['dot']; ?> mr-1 align-middle"></span>
        <?php echo htmlspecialchars($gdata['label']); ?>
        <span class="text-[10px] text-gray-400 ml-0.5">(<?php echo count($gdata['items']); ?>)</span>
    </button>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Variant Group Sections ─────────────────────────────────────────────── -->
<?php if (empty($all_variants_raw)): ?>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-10 text-center">
    <i class="fas fa-box-open text-gray-200 text-4xl mb-3 block"></i>
    <p class="text-sm text-gray-500 mb-2">No product variants yet.</p>
    <a href="base_products.php" class="text-xs text-primary-600 hover:underline font-semibold">Add a product →</a>
</div>
<?php endif; ?>

<div class="space-y-5">
<?php foreach ($by_group as $gkey => $gdata):
    $gvars       = $gdata['items'];
    $group_label = $gdata['label'];
    $group_type  = $gdata['type'];
    $pal         = grade_palette($group_label, $group_type);
?>
<div class="grade-section" data-grade="<?php echo htmlspecialchars($gkey); ?>">

    <!-- Group Header -->
    <div class="flex items-center gap-3 mb-2">
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border <?php echo $pal['badge']; ?>">
            <span class="w-1.5 h-1.5 rounded-full <?php echo $pal['dot']; ?>"></span>
            <?php if ($group_type === 'product'): ?><i class="fas fa-tag text-[9px] opacity-60 -mr-0.5"></i><?php endif; ?>
            <?php echo htmlspecialchars($group_label); ?>
        </span>
        <?php if ($group_type === 'product'): ?>
        <span class="text-[10px] text-rose-400 font-semibold uppercase tracking-wide">Type Variants</span>
        <?php endif; ?>
        <div class="flex-1 h-px bg-gray-200"></div>
        <span class="text-[10px] text-gray-400"><?php echo count($gvars); ?> SKU<?php echo count($gvars) !== 1 ? 's' : ''; ?></span>
    </div>

    <!-- Price Matrix Table -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100">
                    <th class="px-4 py-2.5 text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide w-48">Product</th>
                    <th class="px-3 py-2.5 text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide w-28">Pack / Type</th>
                    <?php foreach ($factories as $fi => $f):
                        $hcolors = ['text-blue-600','text-violet-600','text-teal-600','text-orange-600'];
                        $hc = $hcolors[$fi % count($hcolors)];
                    ?>
                    <th class="px-3 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wide <?php echo $hc; ?>">
                        <?php echo htmlspecialchars(branch_label($f)); ?>
                        <div class="text-[8px] text-gray-300 font-normal normal-case tracking-normal">BT / MT</div>
                    </th>
                    <?php endforeach; ?>
                    <th class="px-3 py-2.5 w-14"></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $prev_pid   = null;
            $row_stripe = false;
            foreach ($gvars as $v):
                if ($v->product_id !== $prev_pid) { $row_stripe = !$row_stripe; $prev_pid = $v->product_id; }
                $has_price = false;
                foreach ($factories as $f) { if (isset($price_map[$v->id][$f->id])) { $has_price = true; break; } }
                $v_wc = detect_wc($v->weight_variant, $weight_map);
            ?>
            <tr class="variant-row border-b border-gray-50 last:border-0 hover:bg-blue-50/30 transition-colors group
                       <?php echo $row_stripe ? 'bg-gray-50/40' : ''; ?>"
                data-search="<?php echo htmlspecialchars(strtolower($v->base_name . ' ' . $v->sku . ' ' . $v->weight_variant . ' ' . ($v->grade ?? ''))); ?>">

                <!-- Product name + SKU -->
                <td class="px-4 py-2.5 whitespace-nowrap">
                    <div class="text-xs font-bold text-gray-800 leading-tight"><?php echo htmlspecialchars($v->base_name); ?></div>
                    <div class="text-[10px] text-gray-400 font-mono mt-0.5"><?php echo htmlspecialchars($v->sku); ?></div>
                </td>

                <!-- Weight / type badge -->
                <td class="px-3 py-2.5 whitespace-nowrap">
                    <span class="text-[10px] px-2 py-0.5 bg-gray-100 text-gray-600 rounded font-semibold">
                        <?php echo htmlspecialchars($v->weight_variant); ?>
                    </span>
                </td>

                <!-- Price per factory: BT price + MT price -->
                <?php foreach ($factories as $fi => $f):
                    $bt  = $price_map[$v->id][$f->id] ?? null;
                    $pcolors = ['text-blue-700','text-violet-700','text-teal-700','text-orange-700'];
                    $pc  = $pcolors[$fi % count($pcolors)];
                    $mt  = null;
                    $bid = (string)$f->id;
                    if ($bt !== null && $v_wc !== null && isset($mt_surcharges[$bid]["surcharge_{$v_wc}"])) {
                        $sur = (float)$mt_surcharges[$bid]["surcharge_{$v_wc}"];
                        if ($sur > 0) $mt = $bt + $sur;
                    }
                ?>
                <td class="px-3 py-2.5 text-right whitespace-nowrap">
                    <?php if ($bt !== null): ?>
                    <div class="inline-flex flex-col items-end gap-0">
                        <span class="text-xs font-extrabold <?php echo $pc; ?>">৳<?php echo number_format($bt, 0); ?></span>
                        <span class="text-[8px] text-gray-400 uppercase leading-none tracking-wide">BT</span>
                        <?php if ($mt !== null): ?>
                        <span class="text-[11px] font-bold text-orange-500 mt-0.5 leading-tight">৳<?php echo number_format($mt, 0); ?></span>
                        <span class="text-[8px] text-orange-400 uppercase leading-none tracking-wide">MT</span>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <span class="text-gray-300 text-xs">—</span>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>

                <!-- Hover actions -->
                <td class="px-3 py-2.5 text-right whitespace-nowrap">
                    <div class="inline-flex items-center gap-2.5 opacity-0 group-hover:opacity-100 transition-opacity">
                        <?php if (!$has_price): ?>
                        <span class="text-[9px] text-red-400 font-semibold">No price</span>
                        <?php endif; ?>
                        <a href="manage_variants.php?product_id=<?php echo $v->product_id; ?>&edit=<?php echo $v->id; ?>"
                           class="text-gray-300 hover:text-primary-600 transition-colors" title="Edit">
                            <i class="fas fa-pencil-alt text-[10px]"></i>
                        </a>
                        <a href="pricing.php?variant_id=<?php echo $v->id; ?>"
                           class="text-gray-300 hover:text-green-600 transition-colors" title="Set prices">
                            <i class="fas fa-tag text-[10px]"></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Table footer: quick add links per product -->
        <div class="px-4 py-2 bg-gray-50 border-t border-gray-100 flex flex-wrap gap-x-4 gap-y-1">
            <?php
            $seen = [];
            foreach ($gvars as $v) {
                if (in_array($v->product_id, $seen)) continue;
                $seen[] = $v->product_id;
            ?>
            <a href="manage_variants.php?product_id=<?php echo $v->product_id; ?>"
               class="text-[10px] text-gray-400 hover:text-primary-600 transition-colors inline-flex items-center gap-1">
                <i class="fas fa-plus text-[9px]"></i> <?php echo htmlspecialchars($v->base_name); ?>
            </a>
            <?php } ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ── Grade Tab + Search JS ──────────────────────────────────────────────── -->
<script>
let activeGrade = 'all';

// Grade tab switching
document.querySelectorAll('.grade-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.grade-tab').forEach(b => {
            b.classList.remove('bg-gray-700','text-white','border-gray-200','border-b-white');
            b.classList.add('text-gray-500','border-transparent');
        });
        btn.classList.add('bg-gray-700','text-white','border-gray-200','border-b-white');
        btn.classList.remove('text-gray-500','border-transparent');
        activeGrade = btn.dataset.grade;
        applyFilters();
    });
});

// Search filtering
const searchInput = document.getElementById('sku-search');
if (searchInput) {
    searchInput.addEventListener('input', applyFilters);
}

function applyFilters() {
    const q = (searchInput ? searchInput.value.toLowerCase().trim() : '');

    document.querySelectorAll('.grade-section').forEach(section => {
        const sGrade = section.dataset.grade;
        const gradeVisible = (activeGrade === 'all' || sGrade === activeGrade);

        if (!gradeVisible) {
            section.style.display = 'none';
            return;
        }

        let visibleRows = 0;
        section.querySelectorAll('.variant-row').forEach(row => {
            const match = !q || row.dataset.search.includes(q);
            row.style.display = match ? '' : 'none';
            if (match) visibleRows++;
        });

        section.style.display = visibleRows > 0 ? '' : 'none';
    });
}
</script>

<?php require_once '../templates/footer.php'; ?>
<?php
require_once '../core/init.php';

// Read-only product overview. Accept the existing Overview grant so no one is locked out.
if (!userHasPageGrant('products', 'products_overview') && !userHasPageGrant('products', 'products')) {
    restrict_access([], 'products', 'products_overview');
}

global $db;
$currentUser = getCurrentUser();
$pageTitle   = 'Product Overview';

// Factory branches become the price columns (fallback: any active branch)
$factories = $db->query("SELECT id, name FROM branches WHERE is_factory = 1 AND status = 'active' ORDER BY name")->results();
if (empty($factories)) {
    $factories = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name LIMIT 4")->results();
}
$factory_ids = array_map(fn($b) => (int)$b->id, $factories);

// Products → variants → active price per branch
$search = trim($_GET['q'] ?? '');
$where  = ["p.status = 'active'"];
$params = [];
if ($search !== '') { $where[] = "(p.base_name LIKE ? OR pv.sku LIKE ? OR pv.grade LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
$where_sql = implode(' AND ', $where);

$rows = $db->query(
    "SELECT p.id AS pid, p.base_name,
            pv.id AS vid, pv.weight_variant, pv.grade, pv.sku,
            pp.branch_id, pp.unit_price
     FROM products p
     JOIN product_variants pv ON pv.product_id = p.id AND pv.status = 'active'
     LEFT JOIN product_prices pp ON pp.variant_id = pv.id AND pp.is_active = 1
     WHERE {$where_sql}
     ORDER BY p.base_name, pv.weight_variant, pv.grade",
    $params
)->results();

// Group: [pid] => ['name'=>, 'variants'=>[vid => ['label','grade','sku','prices'=>[branch_id=>price]]]]
$products = [];
foreach ($rows as $r) {
    if (!isset($products[$r->pid])) $products[$r->pid] = ['name' => $r->base_name, 'variants' => []];
    if (!isset($products[$r->pid]['variants'][$r->vid])) {
        $products[$r->pid]['variants'][$r->vid] = [
            'label'  => trim(($r->weight_variant ?: '') . ($r->grade ? ' · ' . $r->grade : '')),
            'grade'  => $r->grade ?: '—',
            'sku'    => $r->sku,
            'prices' => [],
        ];
    }
    if ($r->branch_id !== null) {
        $products[$r->pid]['variants'][$r->vid]['prices'][(int)$r->branch_id] = (float)$r->unit_price;
    }
}

$total_products  = count($products);
$total_variants  = (int)($db->query("SELECT COUNT(*) AS c FROM product_variants WHERE status='active'")->first()->c ?? 0);
$priced_variants = (int)($db->query("SELECT COUNT(DISTINCT variant_id) AS c FROM product_prices WHERE is_active=1")->first()->c ?? 0);

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 py-6">

<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-box-open text-indigo-600 mr-2"></i>Product Overview</h1>
        <p class="text-sm text-gray-500 mt-1">Grades, variants and current prices per factory — at a glance.</p>
    </div>
    <div class="flex gap-2">
        <a href="products.php" class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50"><i class="fas fa-table mr-1"></i>Detailed Price Matrix</a>
        <a href="pricing.php" class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50"><i class="fas fa-tags mr-1"></i>Edit Pricing</a>
    </div>
</div>

<div class="grid grid-cols-3 gap-4 mb-5">
    <?php foreach ([['Products',$total_products,'indigo'],['Active Variants',$total_variants,'blue'],['Priced Variants',$priced_variants,'green']] as $s): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-xs text-gray-500 uppercase"><?php echo $s[0]; ?></p>
        <p class="text-2xl font-bold text-<?php echo $s[2]; ?>-600 mt-1"><?php echo $s[1]; ?></p>
    </div>
    <?php endforeach; ?>
</div>

<form method="GET" class="mb-5">
    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search product, grade or SKU…"
           class="w-full sm:w-96 px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
</form>

<?php if (empty($products)): ?>
<div class="bg-white rounded-xl shadow-md p-12 text-center text-gray-400"><i class="fas fa-box-open text-4xl mb-3 opacity-30"></i><p>No products found.</p></div>
<?php else: ?>
<div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
    <?php foreach ($products as $pid => $p): ?>
    <div class="bg-white rounded-xl shadow-md overflow-hidden">
        <div class="px-5 py-3 bg-indigo-50 border-b border-indigo-100 flex items-center justify-between">
            <h2 class="font-bold text-gray-800"><i class="fas fa-cube text-indigo-500 mr-2"></i><?php echo htmlspecialchars($p['name']); ?></h2>
            <span class="text-xs text-gray-500"><?php echo count($p['variants']); ?> variant<?php echo count($p['variants']) === 1 ? '' : 's'; ?></span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm divide-y divide-gray-100">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Variant / Grade</th>
                        <?php foreach ($factories as $b): ?>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap"><?php echo htmlspecialchars($b->name); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($p['variants'] as $v): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2">
                            <span class="font-medium text-gray-800"><?php echo htmlspecialchars($v['label'] ?: $v['sku']); ?></span>
                            <span class="block text-[10px] text-gray-400 font-mono"><?php echo htmlspecialchars($v['sku']); ?></span>
                        </td>
                        <?php foreach ($factory_ids as $bid):
                            $price = $v['prices'][$bid] ?? null; ?>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <?php if ($price !== null): ?>
                            <span class="font-semibold text-gray-800">৳<?php echo number_format($price, 2); ?></span>
                            <?php else: ?>
                            <span class="text-[11px] text-amber-500" title="No active price set">— not priced —</span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<?php require_once '../templates/footer.php'; ?>

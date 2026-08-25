<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin'];
restrict_access($allowed_roles, 'credit_sales', 'product_sales_report');

global $db;
$currentUser = getCurrentUser();
$pageTitle   = 'Product Sales Report';

$all_branches = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name")->results();

// ── Filters (shared across all tabs) ────────────────────────────────────────
$date_from  = trim($_GET['date_from'] ?? date('Y-m-01'));
$date_to    = trim($_GET['date_to']   ?? date('Y-m-d'));
$branch_id  = (int)($_GET['branch_id'] ?? 0);
$fp_product = trim($_GET['fp_product'] ?? '');
$active_tab = in_array($_GET['tab'] ?? '', ['history', 'history_grouped'], true) ? $_GET['tab'] : 'sales';
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 50;          // rows/page — flat history tab
$per_page_grouped = 20;    // PRODUCTS/page — grouped history tab (each has several rows)

$sales_rows   = [];
$history_rows = [];
$history_total_rows = 0;
$history_total_pages = 1;
$grouped_history      = [];   // product_id => ['name' => ..., 'rows' => [...]]
$grouped_total_products = 0;
$grouped_total_pages    = 1;

if ($active_tab === 'sales') {
    // "Sold" = delivered orders only, grouped by product/variant/price so a
    // mid-period price change shows up as separate rows for the same product.
    $where  = ["co.status = 'delivered'", "cos.delivered_date BETWEEN ? AND ?"];
    $params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
    if ($branch_id > 0)   { $where[] = 'co.assigned_branch_id = ?'; $params[] = $branch_id; }
    if ($fp_product !== '') { $where[] = 'p.base_name LIKE ?';      $params[] = '%' . $fp_product . '%'; }

    try {
        $sales_rows = $db->query(
            "SELECT p.id AS product_id, p.base_name AS product_name,
                    pv.id AS variant_id, pv.grade, pv.weight_variant, pv.unit_of_measure, pv.sku,
                    coi.unit_price,
                    COUNT(DISTINCT co.id) AS order_count,
                    SUM(coi.quantity) AS qty_sold,
                    SUM(coi.quantity * coi.unit_price) AS gross_amount,
                    SUM(coi.discount_amount) AS discount_total,
                    SUM(coi.line_total) AS net_amount
             FROM credit_order_items coi
             JOIN credit_orders co ON coi.order_id = co.id
             JOIN credit_order_shipping cos ON co.id = cos.order_id
             JOIN products p ON coi.product_id = p.id
             LEFT JOIN product_variants pv ON coi.variant_id = pv.id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY p.id, pv.id, coi.unit_price
             ORDER BY p.base_name, pv.grade, pv.weight_variant, coi.unit_price",
            $params
        )->results();
    } catch (Exception $e) {
        error_log('product_sales_report sales query: ' . $e->getMessage());
    }
} elseif ($active_tab === 'history') {
    // Price change history — price_change_log is high-volume (bulk Pricing
    // Engine runs), so this tab is paginated and date-scoped, unlike sales.
    // Exclude no-op log rows — the Pricing Engine logs one row per branch on
    // every bulk run even when a branch's price didn't actually move, which
    // would otherwise drown out real changes (confirmed live: 4,531 rows/month,
    // nearly all old_price == new_price).
    $where  = ['pcl.changed_at BETWEEN ? AND ?', '(pcl.old_price IS NULL OR pcl.old_price != pcl.new_price)'];
    $params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
    if ($branch_id > 0)   { $where[] = 'pcl.branch_id = ?'; $params[] = $branch_id; }
    if ($fp_product !== '') { $where[] = 'p.base_name LIKE ?'; $params[] = '%' . $fp_product . '%'; }
    $where_sql = implode(' AND ', $where);

    try {
        $count_row = $db->query(
            "SELECT COUNT(*) AS total FROM price_change_log pcl
             JOIN product_variants pv ON pcl.variant_id = pv.id
             JOIN products p ON pv.product_id = p.id
             WHERE $where_sql",
            $params
        )->first();
        $history_total_rows  = (int)($count_row->total ?? 0);
        $history_total_pages = max(1, (int)ceil($history_total_rows / $per_page));

        $params_paged = array_merge($params, [$per_page, ($page - 1) * $per_page]);
        $history_rows = $db->query(
            "SELECT pcl.*, p.base_name AS product_name, pv.grade, pv.weight_variant, pv.sku, b.name AS branch_name
             FROM price_change_log pcl
             JOIN product_variants pv ON pcl.variant_id = pv.id
             JOIN products p ON pv.product_id = p.id
             LEFT JOIN branches b ON pcl.branch_id = b.id
             WHERE $where_sql
             ORDER BY pcl.changed_at DESC
             LIMIT ? OFFSET ?",
            $params_paged
        )->results();
    } catch (Exception $e) {
        error_log('product_sales_report history query: ' . $e->getMessage());
    }
} else {
    // Price history grouped by product — paginated by PRODUCT (not row), so a
    // product's full change trail never gets split across pages. Same no-op
    // filter as the flat history tab.
    $where  = ['pcl.changed_at BETWEEN ? AND ?', '(pcl.old_price IS NULL OR pcl.old_price != pcl.new_price)'];
    $params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
    if ($branch_id > 0)   { $where[] = 'pcl.branch_id = ?'; $params[] = $branch_id; }
    if ($fp_product !== '') { $where[] = 'p.base_name LIKE ?'; $params[] = '%' . $fp_product . '%'; }
    $where_sql = implode(' AND ', $where);

    try {
        $count_row = $db->query(
            "SELECT COUNT(DISTINCT p.id) AS total
             FROM price_change_log pcl
             JOIN product_variants pv ON pcl.variant_id = pv.id
             JOIN products p ON pv.product_id = p.id
             WHERE $where_sql",
            $params
        )->first();
        $grouped_total_products = (int)($count_row->total ?? 0);
        $grouped_total_pages    = max(1, (int)ceil($grouped_total_products / $per_page_grouped));

        $params_page_products = array_merge($params, [$per_page_grouped, ($page - 1) * $per_page_grouped]);
        $page_products = $db->query(
            "SELECT DISTINCT p.id, p.base_name
             FROM price_change_log pcl
             JOIN product_variants pv ON pcl.variant_id = pv.id
             JOIN products p ON pv.product_id = p.id
             WHERE $where_sql
             ORDER BY p.base_name
             LIMIT ? OFFSET ?",
            $params_page_products
        )->results();

        if (!empty($page_products)) {
            $product_ids = array_map(fn($pp) => (int)$pp->id, $page_products);
            $ph = implode(',', array_fill(0, count($product_ids), '?'));
            $rows_params = array_merge($params, $product_ids);
            $rows = $db->query(
                "SELECT pcl.*, p.id AS product_id, p.base_name AS product_name,
                        pv.grade, pv.weight_variant, pv.sku, b.name AS branch_name
                 FROM price_change_log pcl
                 JOIN product_variants pv ON pcl.variant_id = pv.id
                 JOIN products p ON pv.product_id = p.id
                 LEFT JOIN branches b ON pcl.branch_id = b.id
                 WHERE $where_sql AND p.id IN ($ph)
                 ORDER BY p.base_name, pv.grade, pv.weight_variant, pcl.changed_at DESC",
                $rows_params
            )->results();
            foreach ($rows as $r) {
                $grouped_history[$r->product_id]['name'] = $r->product_name;
                $grouped_history[$r->product_id]['rows'][] = $r;
            }
            // Preserve the paginated product order even if a product had zero
            // matching rows for some edge-case reason (keeps page counts honest).
            $ordered = [];
            foreach ($page_products as $pp) {
                $ordered[$pp->id] = $grouped_history[$pp->id] ?? ['name' => $pp->base_name, 'rows' => []];
            }
            $grouped_history = $ordered;
        }
    } catch (Exception $e) {
        error_log('product_sales_report grouped history query: ' . $e->getMessage());
    }
}

// ── Group sales rows by product, with per-product subtotals ────────────────
$sales_by_product = [];
foreach ($sales_rows as $r) {
    $sales_by_product[$r->product_id]['name'] = $r->product_name;
    $sales_by_product[$r->product_id]['rows'][] = $r;
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-4">

<div class="mb-4">
    <h1 class="text-xl font-bold text-gray-900"><i class="fas fa-tags text-blue-500 mr-2"></i><?php echo $pageTitle; ?></h1>
    <p class="text-xs text-gray-500 mt-0.5">Delivered sales by product/price, and the product pricing change log — Superadmin/Admin only.</p>
</div>

<!-- Filters -->
<form method="GET" class="bg-white rounded-lg border border-gray-200 shadow-sm px-4 py-3 mb-4">
    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Branch</label>
            <select name="branch_id" class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
                <option value="0">All Branches</option>
                <?php foreach ($all_branches as $br): ?>
                <option value="<?php echo $br->id; ?>" <?php echo $branch_id === (int)$br->id ? 'selected' : ''; ?>><?php echo htmlspecialchars($br->name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Product</label>
            <input type="text" name="fp_product" value="<?php echo htmlspecialchars($fp_product); ?>" placeholder="Product name..." class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
        </div>
    </div>
    <div class="mt-3 flex gap-2">
        <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded hover:bg-blue-700 transition-colors cursor-pointer">
            <i class="fas fa-filter mr-1"></i>Apply
        </button>
        <a href="product_sales_report.php?tab=<?php echo $active_tab; ?>" class="px-3 py-1.5 bg-gray-100 text-gray-600 text-xs rounded hover:bg-gray-200 transition-colors cursor-pointer">
            <i class="fas fa-times mr-1"></i>Reset
        </a>
    </div>
</form>

<?php
$qs = $_GET; unset($qs['tab'], $qs['page']);
$qs_str = http_build_query($qs);
$qs_str = $qs_str !== '' ? '&' . $qs_str : '';
?>
<div class="flex gap-1 mb-4 border-b border-gray-200">
    <a href="?tab=sales<?php echo $qs_str; ?>"
       class="flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium rounded-t-lg border border-b-0 -mb-px transition-colors cursor-pointer
              <?php echo $active_tab === 'sales' ? 'bg-white border-gray-200 text-blue-700 border-b-white' : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50 mb-0'; ?>">
        <i class="fas fa-chart-column text-xs"></i>Sales by Product
    </a>
    <a href="?tab=history<?php echo $qs_str; ?>"
       class="flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium rounded-t-lg border border-b-0 -mb-px transition-colors cursor-pointer
              <?php echo $active_tab === 'history' ? 'bg-white border-gray-200 text-blue-700 border-b-white' : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50 mb-0'; ?>">
        <i class="fas fa-clock-rotate-left text-xs"></i>Price History
    </a>
    <a href="?tab=history_grouped<?php echo $qs_str; ?>"
       class="flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium rounded-t-lg border border-b-0 -mb-px transition-colors cursor-pointer
              <?php echo $active_tab === 'history_grouped' ? 'bg-white border-gray-200 text-blue-700 border-b-white' : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50 mb-0'; ?>">
        <i class="fas fa-layer-group text-xs"></i>Price History by Product
    </a>
</div>

<?php if ($active_tab === 'sales'): ?>
<!-- ══════════════════════════════════════════════════════════
     TAB: SALES BY PRODUCT
══════════════════════════════════════════════════════════ -->
<?php if (!empty($sales_by_product)):
    $grand_qty = array_sum(array_map(fn($r) => (float)$r->qty_sold, $sales_rows));
    $grand_disc = array_sum(array_map(fn($r) => (float)$r->discount_total, $sales_rows));
    $grand_net = array_sum(array_map(fn($r) => (float)$r->net_amount, $sales_rows));
?>
<div class="flex flex-wrap gap-3 mb-4">
    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-blue-100 text-blue-800 text-xs font-semibold"><?php echo count($sales_by_product); ?> Products</span>
    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-gray-100 text-gray-700 text-xs font-semibold">Qty: <?php echo number_format($grand_qty, 2); ?></span>
    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-100 text-amber-800 text-xs font-semibold">Discount: ৳<?php echo number_format($grand_disc, 2); ?></span>
    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-green-100 text-green-800 text-xs font-semibold">Net: ৳<?php echo number_format($grand_net, 2); ?></span>
</div>

<div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200 text-left">
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide">Product / Variant</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide text-right">Unit Price</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide text-right">Qty Sold</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide text-center">Orders</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide text-right">Gross</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide text-right">Discount</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide text-right">Net</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
        <?php foreach ($sales_by_product as $pid => $grp):
            $p_qty  = array_sum(array_map(fn($r) => (float)$r->qty_sold, $grp['rows']));
            $p_disc = array_sum(array_map(fn($r) => (float)$r->discount_total, $grp['rows']));
            $p_net  = array_sum(array_map(fn($r) => (float)$r->net_amount, $grp['rows']));
            $p_ord  = array_sum(array_map(fn($r) => (int)$r->order_count, $grp['rows']));
            $multi_price = count($grp['rows']) > 1;
        ?>
            <tr class="bg-blue-50/40 font-bold text-gray-800">
                <td class="px-3 py-2" colspan="2"><?php echo htmlspecialchars($grp['name']); ?>
                    <?php if ($multi_price): ?><span class="ml-1 px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[10px] font-semibold">sold at <?php echo count($grp['rows']); ?> prices</span><?php endif; ?>
                </td>
                <td class="px-3 py-2 text-right"><?php echo number_format($p_qty, 2); ?></td>
                <td class="px-3 py-2 text-center"><?php echo $p_ord; ?></td>
                <td class="px-3 py-2 text-right">—</td>
                <td class="px-3 py-2 text-right text-amber-700">৳<?php echo number_format($p_disc, 2); ?></td>
                <td class="px-3 py-2 text-right text-green-700">৳<?php echo number_format($p_net, 2); ?></td>
            </tr>
            <?php foreach ($grp['rows'] as $r):
                $vd = [];
                if ($r->grade)          $vd[] = 'Grade ' . $r->grade;
                if ($r->weight_variant) $vd[] = $r->weight_variant . $r->unit_of_measure;
            ?>
            <tr class="hover:bg-gray-50">
                <td class="px-3 py-2 pl-8 text-gray-600">
                    <?php echo $vd ? htmlspecialchars(implode(' · ', $vd)) : '—'; ?>
                    <?php if ($r->sku): ?><span class="text-gray-300 font-mono text-[10px] ml-1"><?php echo htmlspecialchars($r->sku); ?></span><?php endif; ?>
                </td>
                <td class="px-3 py-2 text-right font-semibold text-gray-900">৳<?php echo number_format($r->unit_price, 2); ?></td>
                <td class="px-3 py-2 text-right"><?php echo number_format($r->qty_sold, 2); ?></td>
                <td class="px-3 py-2 text-center text-gray-500"><?php echo (int)$r->order_count; ?></td>
                <td class="px-3 py-2 text-right text-gray-700">৳<?php echo number_format($r->gross_amount, 2); ?></td>
                <td class="px-3 py-2 text-right text-amber-600">৳<?php echo number_format($r->discount_total, 2); ?></td>
                <td class="px-3 py-2 text-right font-semibold text-green-700">৳<?php echo number_format($r->net_amount, 2); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php else: ?>
<div class="bg-white rounded-lg border border-gray-200 shadow-sm p-12 text-center">
    <i class="fas fa-box-open text-5xl text-gray-300 mb-4"></i>
    <h3 class="text-base font-semibold text-gray-600 mb-1">No delivered sales in this range</h3>
    <p class="text-sm text-gray-400">Try widening the date range or clearing filters.</p>
</div>
<?php endif; ?>

<?php elseif ($active_tab === 'history'): ?>
<!-- ══════════════════════════════════════════════════════════
     TAB: PRICE HISTORY
══════════════════════════════════════════════════════════ -->
<div class="mb-3 text-xs text-gray-500">
    <strong><?php echo number_format($history_total_rows); ?></strong> price change(s) in this range
    <?php if ($history_total_pages > 1): ?> — Page <?php echo $page; ?> of <?php echo $history_total_pages; ?><?php endif; ?>
</div>
<?php if (!empty($history_rows)): ?>
<div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200 text-left">
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Changed At</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide">Product / Variant</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide">Branch</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide text-right">Old Price</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide text-right">New Price</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide text-center">Type</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide">Changed By</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide">Note</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
        <?php foreach ($history_rows as $h):
            $h_vd = [];
            if ($h->grade)          $h_vd[] = 'Grade ' . $h->grade;
            if ($h->weight_variant) $h_vd[] = $h->weight_variant;
            $h_delta = ($h->old_price !== null) ? (float)$h->new_price - (float)$h->old_price : null;
        ?>
        <tr class="hover:bg-gray-50">
            <td class="px-3 py-2 text-gray-600 whitespace-nowrap"><?php echo date('d-M-Y g:ia', strtotime($h->changed_at)); ?></td>
            <td class="px-3 py-2">
                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($h->product_name); ?></div>
                <div class="text-gray-400 text-[10px]"><?php echo htmlspecialchars(implode(' · ', $h_vd) ?: '—'); ?> <?php if ($h->sku): ?><span class="font-mono"><?php echo htmlspecialchars($h->sku); ?></span><?php endif; ?></div>
            </td>
            <td class="px-3 py-2 text-gray-600"><?php echo htmlspecialchars($h->branch_name ?? '—'); ?></td>
            <td class="px-3 py-2 text-right text-gray-500"><?php echo $h->old_price !== null ? '৳' . number_format($h->old_price, 2) : '—'; ?></td>
            <td class="px-3 py-2 text-right font-semibold <?php echo $h_delta !== null ? ($h_delta > 0 ? 'text-red-600' : ($h_delta < 0 ? 'text-green-600' : 'text-gray-900')) : 'text-gray-900'; ?>">
                ৳<?php echo number_format($h->new_price, 2); ?>
            </td>
            <td class="px-3 py-2 text-center">
                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold <?php echo $h->change_type === 'set' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'; ?>">
                    <?php echo ucfirst($h->change_type); ?>
                </span>
            </td>
            <td class="px-3 py-2 text-gray-500"><?php echo htmlspecialchars($h->changed_by ?? '—'); ?></td>
            <td class="px-3 py-2 text-gray-500 max-w-[160px] truncate" title="<?php echo htmlspecialchars($h->note ?? ''); ?>"><?php echo htmlspecialchars($h->note ?? '—'); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ($history_total_pages > 1): ?>
    <div class="p-4 border-t bg-gray-50 flex flex-wrap justify-center gap-1">
        <?php
        $base_params = http_build_query(array_filter(['tab' => 'history', 'date_from' => $date_from, 'date_to' => $date_to, 'branch_id' => $branch_id ?: null, 'fp_product' => $fp_product ?: null]));
        for ($pnum = 1; $pnum <= min($history_total_pages, 15); $pnum++):
        ?>
        <a href="?<?php echo $base_params; ?>&page=<?php echo $pnum; ?>"
           class="px-3 py-1 text-sm rounded <?php echo $pnum === $page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-100'; ?>"><?php echo $pnum; ?></a>
        <?php endfor; ?>
        <?php if ($history_total_pages > 15): ?><span class="px-3 py-1 text-sm text-gray-500">… <?php echo $history_total_pages; ?> pages total</span><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="bg-white rounded-lg border border-gray-200 shadow-sm p-12 text-center">
    <i class="fas fa-clock-rotate-left text-5xl text-gray-300 mb-4"></i>
    <h3 class="text-base font-semibold text-gray-600 mb-1">No price changes in this range</h3>
    <p class="text-sm text-gray-400">Try widening the date range or clearing filters.</p>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════
     TAB: PRICE HISTORY BY PRODUCT
══════════════════════════════════════════════════════════ -->
<div class="mb-3 text-xs text-gray-500">
    <strong><?php echo number_format($grouped_total_products); ?></strong> product(s) with price changes in this range
    <?php if ($grouped_total_pages > 1): ?> — Page <?php echo $page; ?> of <?php echo $grouped_total_pages; ?><?php endif; ?>
</div>
<?php if (!empty($grouped_history)): ?>
<div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200 text-left">
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide">Product / Variant</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide">Branch</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Changed At</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide text-right">Old Price</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide text-right">New Price</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide text-center">Type</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide">Changed By</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
        <?php foreach ($grouped_history as $pid => $grp): ?>
            <tr class="bg-blue-50/40 font-bold text-gray-800">
                <td class="px-3 py-2" colspan="7">
                    <?php echo htmlspecialchars($grp['name']); ?>
                    <span class="ml-1 px-1.5 py-0.5 rounded-full bg-gray-100 text-gray-600 text-[10px] font-semibold"><?php echo count($grp['rows']); ?> change(s)</span>
                </td>
            </tr>
            <?php if (empty($grp['rows'])): ?>
            <tr><td colspan="7" class="px-3 py-2 pl-8 text-gray-400 italic">No changes in this range.</td></tr>
            <?php endif; ?>
            <?php foreach ($grp['rows'] as $h):
                $gh_vd = [];
                if ($h->grade)          $gh_vd[] = 'Grade ' . $h->grade;
                if ($h->weight_variant) $gh_vd[] = $h->weight_variant;
                $gh_delta = ($h->old_price !== null) ? (float)$h->new_price - (float)$h->old_price : null;
            ?>
            <tr class="hover:bg-gray-50">
                <td class="px-3 py-2 pl-8 text-gray-600">
                    <?php echo $gh_vd ? htmlspecialchars(implode(' · ', $gh_vd)) : '—'; ?>
                    <?php if ($h->sku): ?><span class="text-gray-300 font-mono text-[10px] ml-1"><?php echo htmlspecialchars($h->sku); ?></span><?php endif; ?>
                </td>
                <td class="px-3 py-2 text-gray-600"><?php echo htmlspecialchars($h->branch_name ?? '—'); ?></td>
                <td class="px-3 py-2 text-gray-600 whitespace-nowrap"><?php echo date('d-M-Y g:ia', strtotime($h->changed_at)); ?></td>
                <td class="px-3 py-2 text-right text-gray-500"><?php echo $h->old_price !== null ? '৳' . number_format($h->old_price, 2) : '—'; ?></td>
                <td class="px-3 py-2 text-right font-semibold <?php echo $gh_delta !== null ? ($gh_delta > 0 ? 'text-red-600' : ($gh_delta < 0 ? 'text-green-600' : 'text-gray-900')) : 'text-gray-900'; ?>">
                    ৳<?php echo number_format($h->new_price, 2); ?>
                </td>
                <td class="px-3 py-2 text-center">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold <?php echo $h->change_type === 'set' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'; ?>">
                        <?php echo ucfirst($h->change_type); ?>
                    </span>
                </td>
                <td class="px-3 py-2 text-gray-500"><?php echo htmlspecialchars($h->changed_by ?? '—'); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ($grouped_total_pages > 1): ?>
    <div class="p-4 border-t bg-gray-50 flex flex-wrap justify-center gap-1">
        <?php
        $base_params = http_build_query(array_filter(['tab' => 'history_grouped', 'date_from' => $date_from, 'date_to' => $date_to, 'branch_id' => $branch_id ?: null, 'fp_product' => $fp_product ?: null]));
        for ($pnum = 1; $pnum <= min($grouped_total_pages, 15); $pnum++):
        ?>
        <a href="?<?php echo $base_params; ?>&page=<?php echo $pnum; ?>"
           class="px-3 py-1 text-sm rounded <?php echo $pnum === $page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-100'; ?>"><?php echo $pnum; ?></a>
        <?php endfor; ?>
        <?php if ($grouped_total_pages > 15): ?><span class="px-3 py-1 text-sm text-gray-500">… <?php echo $grouped_total_pages; ?> pages total</span><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="bg-white rounded-lg border border-gray-200 shadow-sm p-12 text-center">
    <i class="fas fa-layer-group text-5xl text-gray-300 mb-4"></i>
    <h3 class="text-base font-semibold text-gray-600 mb-1">No price changes in this range</h3>
    <p class="text-sm text-gray-400">Try widening the date range or clearing filters.</p>
</div>
<?php endif; ?>
<?php endif; ?>

</div>
<?php require_once '../templates/footer.php'; ?>

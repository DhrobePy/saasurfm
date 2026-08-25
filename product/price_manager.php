<?php
/**
 * Product Price Manager
 * Ujjal Flour Mills — SaaS Platform
 *
 * Single-page admin interface to update all product prices with minimum effort.
 * - Groups products by Excel grade tier (cross-matched from costing.xlsx)
 * - Admin edits only the Demra 50kg base price
 * - 74kg, Sirajgonj, and Mini Truck rates auto-calculate live
 * - Saves via AJAX (insert-and-deactivate pattern on product_prices)
 *
 * Place at: /product/price_manager.php
 * Access:   Superadmin and admin only
 */

session_start();
require_once '../core/init.php';

// ── Auth: Superadmin and admin only ──────────────────────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../auth/login.php');
    exit();
}
$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['Superadmin', 'admin'])) {
    header('Location: ../unauthorized.php');
    exit();
}
$userId = $_SESSION['user_id'] ?? 0;

// ── Set session user for DB triggers (price_history) ─────────────────────────
$db->query("SET @current_user_id = ?", [$userId]);

// ─────────────────────────────────────────────────────────────────────────────
// AJAX HANDLER — save prices
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_prices') {
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'), true);
    $prices = $input['prices'] ?? [];

    if (empty($prices)) {
        echo json_encode(['success' => false, 'message' => 'No price data received.']);
        exit();
    }

    $saved  = 0;
    $errors = [];

    foreach ($prices as $item) {
        $variantId = intval($item['variant_id'] ?? 0);
        $branchId  = intval($item['branch_id']  ?? 0);
        $unitPrice = floatval($item['unit_price'] ?? 0);

        if ($variantId <= 0 || $branchId <= 0 || $unitPrice <= 0) {
            $errors[] = "Invalid data for variant {$variantId} branch {$branchId}";
            continue;
        }

        // Deactivate existing active price for this variant + branch
        $db->query(
            "UPDATE product_prices
             SET is_active = 0, status = 'inactive', updated_at = NOW()
             WHERE variant_id = ? AND branch_id = ? AND is_active = 1",
            [$variantId, $branchId]
        );

        // Insert new active price row (trigger fires price_history if patch applied)
        $db->query(
            "INSERT INTO product_prices
                (variant_id, branch_id, unit_price, effective_date,
                 status, is_active, created_at, updated_at)
             VALUES (?, ?, ?, CURDATE(), 'active', 1, NOW(), NOW())",
            [$variantId, $branchId, $unitPrice]
        );

        $saved++;
    }

    // Log to system_audit_log
    if ($saved > 0) {
        $db->query(
            "INSERT INTO system_audit_log
                (user_id, module, action, record_type, description,
                 ip_address, user_agent, severity, created_at)
             VALUES (?, 'product', 'price_update', 'product_price',
                     ?, ?, ?, 'info', NOW())",
            [
                $userId,
                "Updated {$saved} product price(s) via Price Manager",
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]
        );
    }

    echo json_encode([
        'success' => count($errors) === 0,
        'saved'   => $saved,
        'errors'  => $errors,
        'message' => $saved > 0
            ? "{$saved} price" . ($saved > 1 ? 's' : '') . " updated successfully."
            : 'No prices were saved.'
    ]);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// LOAD DATA
// Products + variants + active prices for all branches
// ─────────────────────────────────────────────────────────────────────────────
$db->query(
    "SELECT
         p.id            AS product_id,
         p.base_name     AS product_name,
         p.status        AS product_status,
         pv.id           AS variant_id,
         pv.grade,
         pv.weight_variant,
         pv.unit_of_measure,
         pv.status       AS variant_status,
         pp.id           AS price_id,
         pp.branch_id,
         pp.unit_price,
         pp.effective_date,
         pp.updated_at   AS price_updated_at,
         b.name          AS branch_name
     FROM products p
     LEFT JOIN product_variants pv ON pv.product_id = p.id
                                   AND pv.status = 'active'
     LEFT JOIN product_prices pp   ON pp.variant_id = pv.id
                                   AND pp.is_active = 1
     LEFT JOIN branches b          ON b.id = pp.branch_id
     WHERE p.status = 'active'
     ORDER BY p.id, pv.grade, pv.weight_variant, pp.branch_id",
    []
);
$rows = $db->results();

// ── Build structured product map ──────────────────────────────────────────────
// Group by product, then variant, then branch
$productMap = [];
foreach ($rows as $row) {
    $pid = $row->product_id;
    if (!isset($productMap[$pid])) {
        $productMap[$pid] = [
            'product_id'   => $pid,
            'product_name' => $row->product_name,
            'variants'     => []
        ];
    }
    if ($row->variant_id) {
        $vid = $row->variant_id;
        if (!isset($productMap[$pid]['variants'][$vid])) {
            $productMap[$pid]['variants'][$vid] = [
                'variant_id'     => $vid,
                'grade'          => $row->grade,
                'weight_variant' => $row->weight_variant,
                'unit'           => $row->unit_of_measure,
                'prices'         => []  // branch_id => price data
            ];
        }
        if ($row->branch_id) {
            $productMap[$pid]['variants'][$vid]['prices'][$row->branch_id] = [
                'price_id'    => $row->price_id,
                'unit_price'  => $row->unit_price,
                'updated_at'  => $row->price_updated_at,
                'branch_name' => $row->branch_name
            ];
        }
    }
}

// ── Excel cross-match: map product base_name → Excel grade tier ───────────────
// Source: costing.xlsx analysis
// Grade | Excel Base Price (50kg) | Brand examples
$excelGradeMap = [
    'Ruti Moyda'      => ['excel_grade'=>'A', 'base_50'=>2500, 'label'=>'Grade A'],
    '2Hati Moida'     => ['excel_grade'=>'B', 'base_50'=>2440, 'label'=>'Grade B'],
    '1Hati Moida'     => ['excel_grade'=>'C', 'base_50'=>2500, 'label'=>'Grade C'],
    'Kobutor Moyda'   => ['excel_grade'=>'D', 'base_50'=>2170, 'label'=>'Grade D'],
    'Sunflower'       => ['excel_grade'=>'E', 'base_50'=>2030, 'label'=>'Grade E'],
    'Elders Atta'     => ['excel_grade'=>'F', 'base_50'=>1850, 'label'=>'Grade F'],
    'Aam Moyda'       => ['excel_grade'=>'G', 'base_50'=>1820, 'label'=>'Grade G'],
    'Mota Vushi'      => ['excel_grade'=>'—', 'base_50'=>1650, 'label'=>'Vushi'],
    'Chikon Vushi'    => ['excel_grade'=>'—', 'base_50'=>2150, 'label'=>'Vushi'],
    'Packet'          => ['excel_grade'=>'—', 'base_50'=>null,  'label'=>'Packet'],
];

// Match product name to Excel tier (partial match on base name)
function getExcelTier($productName, $excelGradeMap) {
    foreach ($excelGradeMap as $keyword => $data) {
        if (stripos($productName, $keyword) !== false) {
            return $data;
        }
    }
    return ['excel_grade'=>'—', 'base_50'=>null, 'label'=>'Other'];
}

// Grade badge colours matching Excel tier colours
$gradeColors = [
    'A' => ['bg'=>'#fff3cd', 'text'=>'#856404', 'border'=>'#ffc107'],
    'B' => ['bg'=>'#cfe2ff', 'text'=>'#0a4275', 'border'=>'#0d6efd'],
    'C' => ['bg'=>'#d1e7dd', 'text'=>'#0a3622', 'border'=>'#198754'],
    'D' => ['bg'=>'#f8d7da', 'text'=>'#842029', 'border'=>'#dc3545'],
    'E' => ['bg'=>'#e2d9f3', 'text'=>'#432874', 'border'=>'#6f42c1'],
    'F' => ['bg'=>'#fde8d4', 'text'=>'#7c3a0d', 'border'=>'#fd7e14'],
    'G' => ['bg'=>'#d2f4ea', 'text'=>'#0a5741', 'border'=>'#20c997'],
    '—' => ['bg'=>'#e2e3e5', 'text'=>'#41464b', 'border'=>'#adb5bd'],
];

// Branch config
$branches = [
    1 => ['name'=>'Sirajgonj', 'add_50'=>20, 'add_74'=>30,  'badge'=>'primary'],
    2 => ['name'=>'Demra',     'add_50'=>0,  'add_74'=>0,   'badge'=>'success'],
    3 => ['name'=>'Rampura',   'add_50'=>0,  'add_74'=>0,   'badge'=>'secondary'],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Price Manager — Ujjal Flour Mills</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --sidebar-w: 250px;
        }
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', system-ui, sans-serif;
            font-size: 14px;
        }

        /* ── Top Bar ── */
        .top-bar {
            background: #fff;
            border-bottom: 1px solid #dee2e6;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
        }
        .top-bar h1 {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
            color: #1a1a2e;
        }
        .top-bar .breadcrumb {
            margin: 0;
            font-size: 12px;
        }

        /* ── Formula Info Bar ── */
        .formula-bar {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #e8e4dc;
            padding: 10px 24px;
            font-size: 12px;
            display: flex;
            gap: 32px;
            align-items: center;
            flex-wrap: wrap;
        }
        .formula-bar .formula-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .formula-bar .formula-label {
            color: #adb5bd;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .formula-bar code {
            background: rgba(255,255,255,.1);
            color: #ffc107;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 12px;
        }

        /* ── Main Content ── */
        .page-content {
            padding: 20px 24px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* ── Grade Section ── */
        .grade-section {
            margin-bottom: 20px;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 1px 6px rgba(0,0,0,.08);
        }
        .grade-header {
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(0,0,0,.08);
        }
        .grade-badge {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 15px;
            flex-shrink: 0;
        }
        .grade-title { font-weight: 700; font-size: 15px; color: #1a1a2e; }
        .grade-subtitle { font-size: 11px; color: #6c757d; }
        .excel-ref {
            margin-left: auto;
            font-size: 11px;
            color: #6c757d;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 2px 8px;
        }

        /* ── Price Table ── */
        .price-table-wrap { background: #fff; overflow-x: auto; }
        .price-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .price-table thead th {
            background: #f8f9fa;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .4px;
            font-weight: 600;
            color: #6c757d;
            padding: 8px 14px;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
        }
        .price-table tbody tr {
            border-bottom: 1px solid #f0f2f5;
            transition: background .15s;
        }
        .price-table tbody tr:hover { background: #f8f9fa; }
        .price-table tbody tr.dirty { background: #fff9e6; }
        .price-table tbody tr.no-variant { opacity: .5; }
        .price-table td { padding: 10px 14px; vertical-align: middle; }

        /* ── Price Inputs ── */
        .price-input-wrap { position: relative; }
        .price-input-wrap .taka {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-weight: 600;
            pointer-events: none;
        }
        .price-input {
            border: 1.5px solid #dee2e6;
            border-radius: 6px;
            padding: 6px 10px 6px 24px;
            font-size: 13px;
            font-weight: 600;
            color: #1a1a2e;
            width: 120px;
            transition: border-color .2s, box-shadow .2s;
            background: #fff;
        }
        .price-input:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 3px rgba(13,110,253,.1);
            outline: none;
        }
        .price-input.changed {
            border-color: #ffc107;
            background: #fffdf0;
        }
        .price-input:disabled {
            background: #f8f9fa;
            color: #adb5bd;
            cursor: not-allowed;
        }

        /* ── Auto-calc cells ── */
        .auto-price {
            font-weight: 600;
            color: #495057;
            font-size: 13px;
        }
        .auto-price.srj { color: #0a4275; }
        .auto-price.mini { color: #856404; }
        .auto-label {
            font-size: 10px;
            color: #adb5bd;
            letter-spacing: .3px;
        }

        /* ── Dirty indicator ── */
        .dirty-dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: #ffc107;
            display: inline-block;
            margin-right: 6px;
            vertical-align: middle;
        }

        /* ── No variant row ── */
        .no-variant-badge {
            font-size: 11px;
            background: #f8d7da;
            color: #842029;
            border-radius: 4px;
            padding: 2px 8px;
        }

        /* ── Save Bar (sticky bottom) ── */
        .save-bar {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: #fff;
            border-top: 1px solid #dee2e6;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 999;
            box-shadow: 0 -2px 10px rgba(0,0,0,.08);
            transform: translateY(100%);
            transition: transform .3s ease;
        }
        .save-bar.visible { transform: translateY(0); }
        .change-count {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffc107;
            border-radius: 20px;
            padding: 4px 14px;
            font-size: 12px;
            font-weight: 600;
        }

        /* ── Toast ── */
        #toast-container {
            position: fixed;
            top: 70px;
            right: 20px;
            z-index: 2000;
        }
        .toast-msg {
            min-width: 280px;
            padding: 12px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 8px;
            box-shadow: 0 4px 16px rgba(0,0,0,.12);
            animation: slideIn .3s ease;
        }
        .toast-success { background:#d1e7dd; color:#0a3622; border:1px solid #badbcc; }
        .toast-error   { background:#f8d7da; color:#842029; border:1px solid #f5c2c7; }
        @keyframes slideIn {
            from { opacity:0; transform:translateX(30px); }
            to   { opacity:1; transform:translateX(0); }
        }

        /* ── Preview Tab ── */
        .preview-table th { background:#1a1a2e; color:#fff; font-size:11px; padding:10px 14px; }
        .preview-table td { padding:9px 14px; font-size:13px; }
        .preview-table tbody tr:nth-child(even) { background:#f8f9fa; }

        /* ── Bottom spacing for fixed save bar ── */
        body { padding-bottom: 80px; }
    </style>
</head>
<body>

<!-- ── Toast Container ──────────────────────────────────────────── -->
<div id="toast-container"></div>

<!-- ── Top Bar ──────────────────────────────────────────────────── -->
<div class="top-bar">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="../admin/">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="products.php">Products</a></li>
                <li class="breadcrumb-item active">Price Manager</li>
            </ol>
        </nav>
        <h1><i class="fas fa-tags me-2 text-primary"></i>Product Price Manager</h1>
    </div>
    <div class="d-flex align-items-center gap-3">
        <span class="text-muted small">
            <i class="fas fa-user-shield me-1"></i>
            <?= htmlspecialchars($_SESSION['user_display_name'] ?? 'Admin') ?>
        </span>
        <a href="pricing.php" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-list me-1"></i>Old Pricing
        </a>
    </div>
</div>

<!-- ── Formula Info Bar ─────────────────────────────────────────── -->
<div class="formula-bar">
    <div class="formula-item">
        <span class="formula-label">74kg Formula</span>
        <code>(50kg ÷ 50 × 74) + 150</code>
    </div>
    <div class="formula-item">
        <span class="formula-label">Sirajgonj</span>
        <code>+৳20 / bag (50kg) &nbsp;|&nbsp; +৳30 / bag (74kg)</code>
    </div>
    <div class="formula-item">
        <span class="formula-label">Mini Truck</span>
        <code>+৳10 / bag (50kg) &nbsp;|&nbsp; +৳15 / bag (74kg)</code>
    </div>
    <div class="ms-auto small text-secondary">
        <i class="fas fa-info-circle me-1"></i>Edit Demra 50kg only — all other rates calculate automatically
    </div>
</div>

<!-- ── Page Content ─────────────────────────────────────────────── -->
<div class="page-content">

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" id="priceTabs">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#tab-edit">
                <i class="fas fa-edit me-1"></i>Edit Prices
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tab-preview">
                <i class="fas fa-table me-1"></i>Rate Preview
            </a>
        </li>
        <li class="ms-auto d-flex align-items-center">
            <input type="text" id="searchInput" class="form-control form-control-sm"
                   placeholder="&#xf002; Search product…"
                   style="width:200px; font-family:inherit;">
        </li>
    </ul>

    <div class="tab-content">

        <!-- ═══ EDIT TAB ══════════════════════════════════════════ -->
        <div class="tab-pane fade show active" id="tab-edit">

        <?php
        // Group products by Excel grade tier
        $gradeGroups = [];
        foreach ($productMap as $pid => $product) {
            $tier = getExcelTier($product['product_name'], $excelGradeMap);
            $key  = $tier['excel_grade'];
            if (!isset($gradeGroups[$key])) {
                $gradeGroups[$key] = ['tier' => $tier, 'products' => []];
            }
            $gradeGroups[$key]['products'][] = $product;
        }
        // Sort: A-G first, then —
        uksort($gradeGroups, function($a,$b) {
            if ($a === '—') return 1;
            if ($b === '—') return -1;
            return strcmp($a, $b);
        });

        foreach ($gradeGroups as $gradeKey => $group):
            $tier   = $group['tier'];
            $colors = $gradeColors[$gradeKey] ?? $gradeColors['—'];
        ?>

        <div class="grade-section" data-grade="<?= htmlspecialchars($gradeKey) ?>">

            <!-- Grade Header -->
            <div class="grade-header" style="background:<?= $colors['bg'] ?>; border-left:4px solid <?= $colors['border'] ?>">
                <div class="grade-badge"
                     style="background:<?= $colors['border'] ?>; color:#fff;">
                    <?= htmlspecialchars($gradeKey) ?>
                </div>
                <div>
                    <div class="grade-title" style="color:<?= $colors['text'] ?>">
                        <?= htmlspecialchars($tier['label']) ?>
                    </div>
                    <div class="grade-subtitle">
                        <?= count($group['products']) ?> product<?= count($group['products'])>1?'s':'' ?>
                        <?php if ($tier['base_50']): ?>
                        · Excel base: ৳<?= number_format($tier['base_50']) ?>/50kg
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($tier['base_50']): ?>
                <div class="excel-ref">
                    <i class="fas fa-file-excel me-1 text-success"></i>
                    costing.xlsx · Grade <?= htmlspecialchars($gradeKey) ?>
                    · Ref: ৳<?= number_format($tier['base_50']) ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Price Table -->
            <div class="price-table-wrap">
            <table class="price-table">
                <thead>
                    <tr>
                        <th style="width:220px">Product</th>
                        <th>Variant</th>
                        <th style="color:#198754">
                            <i class="fas fa-map-marker-alt me-1"></i>Demra 50kg
                            <span class="fw-normal">(edit)</span>
                        </th>
                        <th style="color:#0d6efd">
                            <i class="fas fa-map-marker-alt me-1"></i>Sirajgonj 50kg
                            <span class="fw-normal">(+20, auto)</span>
                        </th>
                        <th style="color:#198754">
                            <i class="fas fa-weight me-1"></i>Demra 74kg
                            <span class="fw-normal">(formula, auto)</span>
                        </th>
                        <th style="color:#0d6efd">
                            <i class="fas fa-weight me-1"></i>Sirajgonj 74kg
                            <span class="fw-normal">(+30, auto)</span>
                        </th>
                        <th style="color:#856404">
                            <i class="fas fa-truck me-1"></i>Mini Truck
                            <span class="fw-normal">(+10/+15, info)</span>
                        </th>
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($group['products'] as $product):
                    $hasVariant = !empty($product['variants']);
                    $rowClass   = $hasVariant ? '' : 'no-variant';
                ?>
                    <?php if (!$hasVariant): ?>
                    <!-- No variant row -->
                    <tr class="<?= $rowClass ?> product-row"
                        data-name="<?= htmlspecialchars(strtolower($product['product_name'])) ?>">
                        <td>
                            <strong><?= htmlspecialchars($product['product_name']) ?></strong>
                        </td>
                        <td colspan="7">
                            <span class="no-variant-badge">
                                <i class="fas fa-exclamation-circle me-1"></i>
                                No variant — add a variant in Manage Variants first
                            </span>
                        </td>
                    </tr>
                    <?php else:
                    foreach ($product['variants'] as $variant):
                        $vid        = $variant['variant_id'];
                        $grade      = $variant['grade'];
                        $weight     = $variant['weight_variant'];
                        $unit       = $variant['unit'];

                        // Current active prices per branch
                        $priceDemra = $variant['prices'][2]['unit_price'] ?? null;
                        $priceSrj   = $variant['prices'][1]['unit_price'] ?? null;
                        $lastUpdate = $variant['prices'][2]['updated_at']
                                   ?? $variant['prices'][1]['updated_at']
                                   ?? null;

                        // Determine if this is a 50kg or 74kg product
                        $is50 = (floatval($weight) <= 50);
                        $is74 = (floatval($weight) >= 74);
                    ?>
                    <tr class="product-row"
                        data-name="<?= htmlspecialchars(strtolower($product['product_name'])) ?>"
                        data-variant-id="<?= $vid ?>"
                        data-weight="<?= htmlspecialchars($weight) ?>"
                        data-is50="<?= $is50 ? '1' : '0' ?>">

                        <!-- Product Name -->
                        <td>
                            <span class="dirty-dot d-none" id="dot-<?= $vid ?>"></span>
                            <strong><?= htmlspecialchars($product['product_name']) ?></strong>
                            <div class="text-muted" style="font-size:11px">
                                Grade: <?= htmlspecialchars($grade) ?>
                                &nbsp;·&nbsp;
                                <?= htmlspecialchars($weight) ?> <?= htmlspecialchars($unit) ?>
                            </div>
                        </td>

                        <!-- Variant info -->
                        <td>
                            <span class="badge"
                                  style="background:<?= $colors['border'] ?>;color:#fff;font-size:11px">
                                <?= htmlspecialchars($grade) ?>
                            </span>
                            <span class="badge bg-secondary ms-1" style="font-size:11px">
                                <?= htmlspecialchars($weight) ?><?= htmlspecialchars($unit) ?>
                            </span>
                        </td>

                        <!-- Demra 50kg (EDITABLE for all products) -->
                        <td>
                            <div class="price-input-wrap">
                                <span class="taka">৳</span>
                                <input type="number"
                                       class="price-input price-base"
                                       id="price-demra-<?= $vid ?>"
                                       data-variant-id="<?= $vid ?>"
                                       data-branch-id="2"
                                       data-weight="<?= htmlspecialchars($weight) ?>"
                                       data-original="<?= htmlspecialchars($priceDemra ?? '') ?>"
                                       value="<?= htmlspecialchars($priceDemra ?? '') ?>"
                                       placeholder="Enter price"
                                       min="0" step="0.01">
                            </div>
                        </td>

                        <!-- Sirajgonj 50kg (auto = demra + 20) -->
                        <td>
                            <div>
                                <div class="auto-price srj" id="srj50-<?= $vid ?>">
                                    <?php if ($priceDemra): ?>
                                    ৳<?= number_format($priceDemra + 20, 2) ?>
                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                </div>
                                <div class="auto-label">
                                    <?php if ($priceSrj): ?>
                                    DB: ৳<?= number_format($priceSrj, 2) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>

                        <!-- Demra 74kg (auto = formula) -->
                        <td>
                            <div class="auto-price" id="d74-<?= $vid ?>">
                                <?php
                                // 74kg formula: base/50*74+150
                                // For 50kg products: calculate from their price
                                // For 74kg products: the entered price IS the 74kg price
                                if ($is50 && $priceDemra):
                                    $calc74 = round($priceDemra / 50 * 74 + 150, 2);
                                ?>
                                ৳<?= number_format($calc74, 2) ?>
                                <?php elseif ($is74 && $priceDemra): ?>
                                ৳<?= number_format($priceDemra, 2) ?>
                                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                            </div>
                            <?php if ($is74): ?>
                            <div class="auto-label text-warning">↑ direct (74kg product)</div>
                            <?php else: ?>
                            <div class="auto-label">formula auto</div>
                            <?php endif; ?>
                        </td>

                        <!-- Sirajgonj 74kg (auto) -->
                        <td>
                            <div class="auto-price srj" id="srj74-<?= $vid ?>">
                                <?php
                                if ($is50 && $priceDemra):
                                    $calc74srj = round($priceDemra / 50 * 74 + 150 + 30, 2);
                                    echo '৳' . number_format($calc74srj, 2);
                                elseif ($is74 && $priceDemra):
                                    echo '৳' . number_format($priceDemra + 30, 2);
                                else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- Mini Truck (info only) -->
                        <td>
                            <div class="auto-price mini" id="mini50-<?= $vid ?>" style="font-size:12px">
                                <?php if ($priceDemra): ?>
                                50kg: ৳<?= number_format($priceDemra + 10, 2) ?>
                                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                            </div>
                            <div class="auto-price mini" id="mini74-<?= $vid ?>" style="font-size:12px">
                                <?php
                                if ($is50 && $priceDemra):
                                    echo '74kg: ৳' . number_format(round($priceDemra/50*74+150+15,2), 2);
                                elseif ($is74 && $priceDemra):
                                    echo '74kg: ৳' . number_format($priceDemra+15, 2);
                                endif; ?>
                            </div>
                        </td>

                        <!-- Last Updated -->
                        <td>
                            <?php if ($lastUpdate): ?>
                            <div style="font-size:12px;color:#6c757d">
                                <?= date('d M Y', strtotime($lastUpdate)) ?>
                            </div>
                            <div style="font-size:11px;color:#adb5bd">
                                <?= date('H:i', strtotime($lastUpdate)) ?>
                            </div>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>

                    </tr>
                    <?php endforeach; endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div><!-- /price-table-wrap -->
        </div><!-- /grade-section -->

        <?php endforeach; ?>
        </div><!-- /tab-edit -->


        <!-- ═══ PREVIEW TAB ═══════════════════════════════════════ -->
        <div class="tab-pane fade" id="tab-preview">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-dark text-white py-3">
                    <h6 class="mb-0">
                        <i class="fas fa-table me-2"></i>
                        Live Rate Sheet — All Branches · Updates as you type
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="price-table preview-table w-100">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Grade</th>
                                    <th>Wt.</th>
                                    <th class="text-success">Demra 50kg</th>
                                    <th class="text-primary">Sirajgonj 50kg</th>
                                    <th class="text-success">Demra 74kg</th>
                                    <th class="text-primary">Sirajgonj 74kg</th>
                                    <th style="color:#ffc107">Mini Truck 50kg</th>
                                    <th style="color:#ffc107">Mini Truck 74kg</th>
                                </tr>
                            </thead>
                            <tbody id="previewBody">
                            <?php foreach ($productMap as $product):
                                if (empty($product['variants'])) continue;
                                foreach ($product['variants'] as $variant):
                                    $vid   = $variant['variant_id'];
                                    $grade = $variant['grade'];
                                    $wt    = $variant['weight_variant'];
                                    $unit  = $variant['unit'];
                                    $is50  = (floatval($wt) <= 50);
                                    $pdm   = $variant['prices'][2]['unit_price'] ?? 0;
                            ?>
                            <tr data-preview-vid="<?= $vid ?>"
                                data-is50-preview="<?= $is50 ? '1' : '0' ?>">
                                <td><strong><?= htmlspecialchars($product['product_name']) ?></strong></td>
                                <td>
                                    <?php $c = $gradeColors[$grade] ?? $gradeColors['—']; ?>
                                    <span class="badge"
                                          style="background:<?= $c['border'] ?>;color:#fff">
                                        <?= htmlspecialchars($grade) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($wt) ?><?= htmlspecialchars($unit) ?></td>
                                <td class="text-success fw-semibold prev-d50-<?= $vid ?>">
                                    <?= $pdm ? '৳' . number_format($pdm, 2) : '—' ?>
                                </td>
                                <td class="text-primary fw-semibold prev-s50-<?= $vid ?>">
                                    <?= $pdm ? '৳' . number_format($pdm + 20, 2) : '—' ?>
                                </td>
                                <td class="fw-semibold prev-d74-<?= $vid ?>">
                                    <?php
                                    if ($pdm && $is50) echo '৳' . number_format(round($pdm/50*74+150, 2), 2);
                                    elseif ($pdm && !$is50) echo '৳' . number_format($pdm, 2);
                                    else echo '—';
                                    ?>
                                </td>
                                <td class="text-primary fw-semibold prev-s74-<?= $vid ?>">
                                    <?php
                                    if ($pdm && $is50) echo '৳' . number_format(round($pdm/50*74+150+30, 2), 2);
                                    elseif ($pdm && !$is50) echo '৳' . number_format($pdm + 30, 2);
                                    else echo '—';
                                    ?>
                                </td>
                                <td style="color:#856404" class="prev-m50-<?= $vid ?>">
                                    <?= $pdm ? '৳' . number_format($pdm + 10, 2) : '—' ?>
                                </td>
                                <td style="color:#856404" class="prev-m74-<?= $vid ?>">
                                    <?php
                                    if ($pdm && $is50) echo '৳' . number_format(round($pdm/50*74+150+15, 2), 2);
                                    elseif ($pdm && !$is50) echo '৳' . number_format($pdm + 15, 2);
                                    else echo '—';
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div><!-- /tab-preview -->

    </div><!-- /tab-content -->
</div><!-- /page-content -->

<!-- ── Save Bar (fixed bottom) ──────────────────────────────────── -->
<div class="save-bar" id="saveBar">
    <div class="d-flex align-items-center gap-3">
        <span class="change-count" id="changeCount">0 changes</span>
        <span class="text-muted small">
            <i class="fas fa-info-circle me-1"></i>
            Sirajgonj, 74kg, and Mini Truck rates will be saved automatically
        </span>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary" onclick="discardChanges()">
            <i class="fas fa-undo me-1"></i>Discard
        </button>
        <button class="btn btn-warning fw-semibold px-4" id="saveBtn" onclick="saveAllPrices()">
            <i class="fas fa-save me-1"></i>Save All Changes
        </button>
    </div>
</div>

<!-- ── Bootstrap JS ─────────────────────────────────────────────── -->
<script src="../assets/js/bootstrap.bundle.min.js"></script>
<script>

// ─────────────────────────────────────────────────────────────────────────────
// CONSTANTS
// ─────────────────────────────────────────────────────────────────────────────
const ADD_SRJ_50   = 20;
const ADD_SRJ_74   = 30;
const ADD_MINI_50  = 10;
const ADD_MINI_74  = 15;
const FORMULA_MULT = 74 / 50;  // 1.48
const FORMULA_ADD  = 150;

function calc74(p50) {
    return Math.round((p50 / 50 * 74 + FORMULA_ADD) * 100) / 100;
}

function fmt(n) {
    if (!n || isNaN(n)) return '—';
    return '৳' + parseFloat(n).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
}

// ─────────────────────────────────────────────────────────────────────────────
// LIVE AUTO-CALCULATION
// ─────────────────────────────────────────────────────────────────────────────
let changedInputs = new Map(); // variantId+branchId → {variant_id, branch_id, unit_price}

document.querySelectorAll('.price-base').forEach(input => {
    input.addEventListener('input', function() {
        const vid    = this.dataset.variantId;
        const is50   = this.closest('tr').dataset.is50 === '1';
        const p50    = parseFloat(this.value) || 0;
        const orig   = parseFloat(this.dataset.original) || 0;

        // Mark row dirty
        const isDirty = p50 !== orig && p50 > 0;
        this.classList.toggle('changed', isDirty);
        const dot = document.getElementById('dot-' + vid);
        if (dot) dot.classList.toggle('d-none', !isDirty);
        this.closest('tr').classList.toggle('dirty', isDirty);

        // Auto-calculate all derived prices
        const p74     = is50 ? calc74(p50) : p50;
        const srj50   = p50 + (is50 ? ADD_SRJ_50 : ADD_SRJ_50);
        const srj74   = p74 + ADD_SRJ_74;
        const mini50  = p50 + ADD_MINI_50;
        const mini74  = p74 + ADD_MINI_74;

        // Update edit-tab cells
        document.getElementById('srj50-' + vid).textContent  = p50 ? fmt(srj50) : '—';
        document.getElementById('d74-' + vid).textContent    = p50 ? fmt(p74)   : '—';
        document.getElementById('srj74-' + vid).textContent  = p50 ? fmt(srj74) : '—';
        document.getElementById('mini50-' + vid).textContent = p50 ? '50kg: ' + fmt(mini50) : '—';
        document.getElementById('mini74-' + vid).textContent = p50 ? '74kg: ' + fmt(mini74) : '—';

        // Update preview tab
        const updateEl = (sel, val) => { const el = document.querySelector('.' + sel); if (el) el.textContent = val; };
        updateEl('prev-d50-' + vid, p50 ? fmt(p50)    : '—');
        updateEl('prev-s50-' + vid, p50 ? fmt(srj50)  : '—');
        updateEl('prev-d74-' + vid, p50 ? fmt(p74)    : '—');
        updateEl('prev-s74-' + vid, p50 ? fmt(srj74)  : '—');
        updateEl('prev-m50-' + vid, p50 ? fmt(mini50) : '—');
        updateEl('prev-m74-' + vid, p50 ? fmt(mini74) : '—');

        // Track changes — Demra base (branch 2)
        const key = vid + '_2';
        if (isDirty) {
            changedInputs.set(key, {
                variant_id: parseInt(vid),
                branch_id:  2,
                unit_price: p50
            });
            // Also queue Sirajgonj (branch 1) — auto from formula
            changedInputs.set(vid + '_1', {
                variant_id: parseInt(vid),
                branch_id:  1,
                unit_price: srj50
            });
        } else {
            changedInputs.delete(key);
            changedInputs.delete(vid + '_1');
        }

        updateSaveBar();
    });
});

function updateSaveBar() {
    const count = changedInputs.size;
    const bar   = document.getElementById('saveBar');
    const lbl   = document.getElementById('changeCount');
    // Count unique products changed (not total branch rows)
    const uniqueProducts = new Set([...changedInputs.keys()].map(k => k.split('_')[0])).size;
    lbl.textContent = uniqueProducts + ' product' + (uniqueProducts !== 1 ? 's' : '') + ' changed (' + count + ' rows)';
    bar.classList.toggle('visible', count > 0);
}

// ─────────────────────────────────────────────────────────────────────────────
// SAVE ALL
// ─────────────────────────────────────────────────────────────────────────────
function saveAllPrices() {
    if (changedInputs.size === 0) return;

    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving…';

    const prices = Array.from(changedInputs.values());

    fetch(window.location.pathname, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'save_prices', prices: prices })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('success', '<i class="fas fa-check-circle me-2"></i>' + data.message);
            // Update originals so rows un-dirty
            changedInputs.forEach((item, key) => {
                if (key.endsWith('_2')) {
                    const input = document.getElementById('price-demra-' + item.variant_id);
                    if (input) {
                        input.dataset.original = item.unit_price;
                        input.classList.remove('changed');
                        input.closest('tr').classList.remove('dirty');
                        const dot = document.getElementById('dot-' + item.variant_id);
                        if (dot) dot.classList.add('d-none');
                    }
                }
            });
            changedInputs.clear();
            updateSaveBar();
        } else {
            showToast('error', '<i class="fas fa-exclamation-circle me-2"></i>' + (data.message || 'Save failed.'));
        }
    })
    .catch(() => showToast('error', 'Network error — please try again.'))
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Save All Changes';
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// DISCARD
// ─────────────────────────────────────────────────────────────────────────────
function discardChanges() {
    document.querySelectorAll('.price-base').forEach(input => {
        input.value = input.dataset.original;
        input.classList.remove('changed');
        input.closest('tr').classList.remove('dirty');
        const dot = document.getElementById('dot-' + input.dataset.variantId);
        if (dot) dot.classList.add('d-none');
    });
    changedInputs.clear();
    updateSaveBar();
    showToast('info', 'Changes discarded.');
}

// ─────────────────────────────────────────────────────────────────────────────
// SEARCH FILTER
// ─────────────────────────────────────────────────────────────────────────────
document.getElementById('searchInput').addEventListener('input', function() {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('.product-row').forEach(row => {
        const name = row.dataset.name || '';
        row.style.display = (!q || name.includes(q)) ? '' : 'none';
    });
    // Hide grade sections if all rows hidden
    document.querySelectorAll('.grade-section').forEach(section => {
        const visible = [...section.querySelectorAll('.product-row')]
                          .some(r => r.style.display !== 'none');
        section.style.display = visible ? '' : 'none';
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// TOAST
// ─────────────────────────────────────────────────────────────────────────────
function showToast(type, message) {
    const container = document.getElementById('toast-container');
    const div       = document.createElement('div');
    div.className   = 'toast-msg toast-' + type;
    div.innerHTML   = message;
    container.appendChild(div);
    setTimeout(() => div.remove(), 4000);
}

</script>
</body>
</html>

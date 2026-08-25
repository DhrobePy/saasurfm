<?php
/**
 * Smart Pricing Engine
 *
 * RULE SET (from costing.xlsx):
 *   1. Admin sets one Base 50 kg price per grade.
 *   2. 74 kg price = (base50 / BAG_50) × BAG_74 + PACKAGING_FEE
 *      — e.g. Grade A: (2500/50)×74+150 = 3850
 *   3. Every product/variant inside the same grade + weight shares the same price.
 *   4. Branch surcharges are added on top of the base price.
 *   5. Non-standard weight variants (37, 55, 100, 25, 30 kg …) get their own
 *      manual price inputs — the 50→74 formula does NOT apply to them.
 */

require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id     = $currentUser['id'] ?? null;
$pageTitle   = 'Smart Pricing Engine';

// ─── Config file ────────────────────────────────────────────────────────────
$config_file    = __DIR__ . '/pricing_engine_config.json';
$default_config = [
    'formula' => ['bag_50' => 50, 'bag_74' => 74, 'packaging_fee' => 150],
    'weight_map' => [
        '50' => '50', '74' => '74',
        '50KG' => '50', '74KG' => '74',
        '50 KG' => '50', '74 KG' => '74',
        '50kg' => '50', '74kg' => '74',
    ],
    'branch_surcharges'      => [],
    'mini_truck_surcharges'  => [],
];

// DDL: app_settings table for DB-backed config (no file-permission issues)
try {
    $db->getPdo()->exec("CREATE TABLE IF NOT EXISTS `app_settings` (
        `setting_key` VARCHAR(100) PRIMARY KEY,
        `setting_value` MEDIUMTEXT NOT NULL,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

$config = file_exists($config_file)
    ? array_replace_recursive($default_config, json_decode(file_get_contents($config_file), true) ?? [])
    : $default_config;

// Merge DB-stored surcharges (source of truth when JSON file is unwritable)
$db_mt = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'mini_truck_surcharges'")->first();
if ($db_mt) {
    $db_mt_arr = json_decode($db_mt->setting_value, true) ?? [];
    if (!empty($db_mt_arr)) {
        $config['mini_truck_surcharges'] = array_replace_recursive(
            $config['mini_truck_surcharges'], $db_mt_arr
        );
    }
}
$db_bs = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'branch_surcharges'")->first();
if ($db_bs) {
    $db_bs_arr = json_decode($db_bs->setting_value, true) ?? [];
    if (!empty($db_bs_arr)) {
        $config['branch_surcharges'] = array_replace_recursive(
            $config['branch_surcharges'], $db_bs_arr
        );
    }
}

// ─── Load branches ──────────────────────────────────────────────────────────
try { $db->getPdo()->exec("ALTER TABLE `branches` ADD COLUMN `is_factory` TINYINT(1) NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

// Feature #6: zone (branch) surcharges apply to ANY active branch — a branch does
// NOT have to be flagged as a factory to have its own zone pricing.
$branches = $db->query(
    "SELECT * FROM branches WHERE status = 'active' ORDER BY is_factory DESC, name ASC"
)->results();

foreach ($branches as $b) {
    if (!isset($config['branch_surcharges'][$b->id])) {
        $config['branch_surcharges'][$b->id] = ['surcharge_50' => 0, 'surcharge_74' => 0];
    }
    if (!isset($config['mini_truck_surcharges'][$b->id])) {
        $config['mini_truck_surcharges'][$b->id] = ['surcharge_50' => 0, 'surcharge_74' => 0];
    }
}

// ─── Load ALL active products + variants + current lowest price ──────────────
// This is the source of truth — the engine works on REAL products, not
// abstract grade letters.
$all_variants = $db->query(
    "SELECT
         p.id          AS product_id,
         p.base_name   AS product_name,
         p.category,
         pv.id         AS variant_id,
         pv.grade,
         pv.weight_variant,
         pv.sku,
         pv.unit_of_measure,
         MIN(pp.unit_price) AS current_price
     FROM products p
     JOIN product_variants pv ON pv.product_id = p.id AND pv.status = 'active'
     LEFT JOIN product_prices pp ON pp.variant_id = pv.id AND pp.is_active = 1
     WHERE p.status = 'active'
       AND pv.grade IS NOT NULL AND pv.grade != ''
     GROUP BY p.id, pv.id
     ORDER BY pv.grade ASC, pv.weight_variant ASC, p.base_name ASC"
)->results();

// ─── Organise variants into grade buckets ────────────────────────────────────
// $grade_data[grade]['50']      = [variants that map to 50 kg class]
// $grade_data[grade]['74']      = [variants that map to 74 kg class]
// $grade_data[grade]['custom']  = [variants with non-standard weights]
$grade_data = [];

foreach ($all_variants as $row) {
    $grade = $row->grade;
    $wv    = $row->weight_variant;

    // Map to weight class
    $wc = $config['weight_map'][$wv] ?? null;
    if (!$wc) {
        foreach ($config['weight_map'] as $pat => $cls) {
            if (stripos($wv, (string)(int)$pat) !== false) { $wc = $cls; break; }
        }
    }
    $wc = $wc ?? 'custom'; // unmapped → manual price

    $item = [
        'product_id'    => $row->product_id,
        'product_name'  => $row->product_name,
        'category'      => $row->category,
        'variant_id'    => $row->variant_id,
        'weight_variant' => $wv,
        'sku'           => $row->sku,
        'uom'           => $row->unit_of_measure,
        'current_price' => $row->current_price !== null ? (float)$row->current_price : null,
        'weight_class'  => $wc,
    ];

    $grade_data[$grade][$wc][] = $item;
}
ksort($grade_data);

$grades = array_keys($grade_data);

// ─── Current base 50 kg price per grade (for pre-filling inputs) ─────────────
// Use the minimum active price among all 50 kg variants of each grade.
$current_50 = [];
foreach ($grade_data as $grade => $wc_groups) {
    foreach ($wc_groups['50'] ?? [] as $item) {
        if ($item['current_price'] !== null) {
            if (!isset($current_50[$grade]) || $item['current_price'] < $current_50[$grade]) {
                $current_50[$grade] = $item['current_price'];
            }
        }
    }
}

// ─── Current prices for diff modal [grade][branch_id][wc] and
//     [variant_id][branch_id] for custom weights ───────────────────────────────
$all_current_prices = [];
$custom_current_prices = [];

$curr_rows = $db->query(
    "SELECT pv.grade, pp.branch_id, pv.weight_variant, pv.id AS variant_id,
            MIN(pp.unit_price) AS unit_price
     FROM product_prices pp
     JOIN product_variants pv ON pp.variant_id = pv.id
     WHERE pp.is_active = 1
       AND pv.grade IS NOT NULL AND pv.grade != ''
       AND pv.status = 'active'
     GROUP BY pv.grade, pp.branch_id, pv.weight_variant, pv.id
     ORDER BY pv.grade, pp.branch_id"
)->results();

foreach ($curr_rows as $cr) {
    $g  = $cr->grade;
    $br = (string)$cr->branch_id;
    $wv = $cr->weight_variant;
    $vid = (string)$cr->variant_id;

    $wc = $config['weight_map'][$wv] ?? null;
    if (!$wc) {
        foreach ($config['weight_map'] as $pat => $cls) {
            if (stripos($wv, (string)(int)$pat) !== false) { $wc = $cls; break; }
        }
    }

    if ($wc === '50' || $wc === '74') {
        if (!isset($all_current_prices[$g][$br][$wc])) {
            $all_current_prices[$g][$br][$wc] = (float)$cr->unit_price;
        }
    } else {
        // Custom weight — indexed by variant_id + branch_id
        $custom_current_prices[$vid][$br] = (float)$cr->unit_price;
    }
}

// ─── Rounding helper ─────────────────────────────────────────────────────────
function roundDown5(float $v): int { return (int)(floor($v / 5) * 5); }

// ─── POST handler ─────────────────────────────────────────────────────────────
$flash_success = null;
$flash_error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sess_tok = $_SESSION['csrf_token'] ?? '';
    $post_tok = $_POST['csrf_token']    ?? '';
    if (!$sess_tok || !$post_tok || !hash_equals($sess_tok, $post_tok)) {
        $flash_error = 'Invalid security token. Please refresh and try again.';
        goto render;
    }

    $action = $_POST['action'] ?? '';

    // ── Save config ──────────────────────────────────────────────────────────
    if ($action === 'save_config') {
        $config['formula']['bag_50']        = max(1, (int)($_POST['bag_50']       ?? 50));
        $config['formula']['bag_74']        = max(1, (int)($_POST['bag_74']       ?? 74));
        $config['formula']['packaging_fee'] = (float)($_POST['packaging_fee']     ?? 150);

        foreach ($branches as $b) {
            $config['branch_surcharges'][$b->id]['surcharge_50']      = (float)($_POST["surcharge_50_{$b->id}"]    ?? 0);
            $config['branch_surcharges'][$b->id]['surcharge_74']      = (float)($_POST["surcharge_74_{$b->id}"]    ?? 0);
            $config['mini_truck_surcharges'][$b->id]['surcharge_50']  = (float)($_POST["mt_surcharge_50_{$b->id}"] ?? 0);
            $config['mini_truck_surcharges'][$b->id]['surcharge_74']  = (float)($_POST["mt_surcharge_74_{$b->id}"] ?? 0);
        }

        // Write JSON file (best-effort — may fail if web server lacks write permission)
        file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));

        // Always persist surcharges to DB — no file-permission issues
        $pdo = $db->getPdo();
        $mt_json = json_encode($config['mini_truck_surcharges']);
        $bs_json = json_encode($config['branch_surcharges']);
        $pdo->exec("INSERT INTO app_settings (setting_key, setting_value) VALUES ('mini_truck_surcharges', " . $pdo->quote($mt_json) . ")
                    ON DUPLICATE KEY UPDATE setting_value = " . $pdo->quote($mt_json) . ", updated_at = NOW()");
        $pdo->exec("INSERT INTO app_settings (setting_key, setting_value) VALUES ('branch_surcharges', " . $pdo->quote($bs_json) . ")
                    ON DUPLICATE KEY UPDATE setting_value = " . $pdo->quote($bs_json) . ", updated_at = NOW()");

        $flash_success = 'Formula constants and branch surcharges saved.';
        goto render;
    }

    // ── Apply prices ─────────────────────────────────────────────────────────
    if ($action === 'apply_prices') {
        $bag_50      = (float)$config['formula']['bag_50'];
        $bag_74      = (float)$config['formula']['bag_74'];
        $pkg_fee     = (float)$config['formula']['packaging_fee'];
        $eff_date    = date('Y-m-d');
        $engine_user = $_SESSION['user_display_name'] ?? 'System';

        $pdo = $db->getPdo();

        // DDL must run BEFORE beginTransaction — MySQL DDL causes an implicit
        // commit which would silently destroy any open transaction.
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `price_change_log` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `variant_id` INT NOT NULL,
                `branch_id` INT NOT NULL,
                `old_price` DECIMAL(10,2) DEFAULT NULL,
                `new_price` DECIMAL(10,2) DEFAULT NULL,
                `change_type` VARCHAR(20) NOT NULL DEFAULT 'engine',
                `changed_by` VARCHAR(150) DEFAULT NULL,
                `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `note` VARCHAR(255) DEFAULT NULL,
                INDEX `idx_pcl_variant` (`variant_id`),
                INDEX `idx_pcl_at` (`changed_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) { /* already exists */ }

        $pdo->beginTransaction();

        try {
            $total_updated  = 0;
            $grades_changed = [];
            $custom_changed = [];

            // ── 1. Grade-based (50/74 formula) ───────────────────────────────
            foreach ($grades as $grade) {
                $key = "base50_{$grade}";
                if (!isset($_POST[$key]) || $_POST[$key] === '') continue;
                $base50 = (float)$_POST[$key];
                if ($base50 <= 0) continue;

                $base74 = roundDown5(($base50 / $bag_50) * $bag_74 + $pkg_fee);

                // All 50/74 variants for this grade
                foreach (['50', '74'] as $wc) {
                    $base_price = $wc === '50' ? $base50 : $base74;
                    foreach ($grade_data[$grade][$wc] ?? [] as $item) {
                        foreach ($branches as $b) {
                            $surcharge = $wc === '50'
                                ? (float)($config['branch_surcharges'][$b->id]['surcharge_50'] ?? 0)
                                : (float)($config['branch_surcharges'][$b->id]['surcharge_74'] ?? 0);
                            $final = roundDown5($base_price + $surcharge);

                            $old_row = $db->query(
                                "SELECT unit_price FROM product_prices WHERE variant_id = ? AND branch_id = ? AND is_active = 1 LIMIT 1",
                                [$item['variant_id'], $b->id]
                            )->first();
                            $old_price = $old_row ? (float)$old_row->unit_price : null;

                            $db->query(
                                "UPDATE product_prices SET is_active = 0
                                 WHERE variant_id = ? AND branch_id = ? AND is_active = 1",
                                [$item['variant_id'], $b->id]
                            );
                            $db->query(
                                "INSERT INTO product_prices
                                     (variant_id, branch_id, unit_price, effective_date, status, is_active)
                                 VALUES (?, ?, ?, ?, 'active', 1)",
                                [$item['variant_id'], $b->id, $final, $eff_date]
                            );
                            $db->insert('price_change_log', [
                                'variant_id'  => $item['variant_id'],
                                'branch_id'   => $b->id,
                                'old_price'   => $old_price,
                                'new_price'   => $final,
                                'change_type' => 'engine',
                                'changed_by'  => $engine_user,
                                'note'        => "Smart Pricing — Grade {$grade}",
                            ]);
                            $total_updated++;
                        }
                    }
                }

                $grades_changed[] = "Grade {$grade}";
            }

            // ── 2. Custom-weight variants (manual price per variant) ──────────
            foreach ($all_variants as $row) {
                $wv = $row->weight_variant;
                $wc = $config['weight_map'][$wv] ?? null;
                if (!$wc) {
                    foreach ($config['weight_map'] as $pat => $cls) {
                        if (stripos($wv, (string)(int)$pat) !== false) { $wc = $cls; break; }
                    }
                }
                if ($wc === '50' || $wc === '74') continue; // handled above

                $key = "custom_price_{$row->variant_id}";
                if (!isset($_POST[$key]) || $_POST[$key] === '') continue;
                $custom_price = roundDown5((float)$_POST[$key]);
                if ($custom_price <= 0) continue;

                foreach ($branches as $b) {
                    $old_row2 = $db->query(
                        "SELECT unit_price FROM product_prices WHERE variant_id = ? AND branch_id = ? AND is_active = 1 LIMIT 1",
                        [$row->variant_id, $b->id]
                    )->first();
                    $old_price2 = $old_row2 ? (float)$old_row2->unit_price : null;

                    $db->query(
                        "UPDATE product_prices SET is_active = 0
                         WHERE variant_id = ? AND branch_id = ? AND is_active = 1",
                        [$row->variant_id, $b->id]
                    );
                    $db->query(
                        "INSERT INTO product_prices
                             (variant_id, branch_id, unit_price, effective_date, status, is_active)
                         VALUES (?, ?, ?, ?, 'active', 1)",
                        [$row->variant_id, $b->id, $custom_price, $eff_date]
                    );
                    $db->insert('price_change_log', [
                        'variant_id'  => $row->variant_id,
                        'branch_id'   => $b->id,
                        'old_price'   => $old_price2,
                        'new_price'   => $custom_price,
                        'change_type' => 'engine',
                        'changed_by'  => $engine_user,
                        'note'        => 'Smart Pricing — custom weight',
                    ]);
                    $total_updated++;
                }
                $custom_changed[] = $row->product_name . ' (' . $row->weight_variant . 'kg)';
            }

            auditLog('settings', 'updated',
                "Smart Pricing Engine applied: {$total_updated} price records updated. " .
                implode(', ', array_merge($grades_changed, $custom_changed)),
                ['grades' => $grades_changed, 'custom' => $custom_changed, 'records' => $total_updated]
            );

            $pdo->commit();

            $parts = [];
            if ($grades_changed) $parts[] = implode(', ', $grades_changed);
            if ($custom_changed) $parts[] = count($custom_changed) . ' custom-weight product(s)';
            $flash_success = "✓ Pricing applied — {$total_updated} price records updated" .
                             ($parts ? " for: " . implode('; ', $parts) : '') . ".";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flash_error = 'Database error: ' . $e->getMessage();
        }
        goto render;
    }
}

render:
require_once '../templates/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════
     PAGE HEADER
══════════════════════════════════════════════════════════════ -->
<div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="flex items-start justify-between mb-6 gap-4">
    <div>
        <div class="flex items-center gap-3 mb-1">
            <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold
                         bg-red-100 text-red-700 border border-red-200">
                <i class="fas fa-lock text-xs"></i> Admin Only
            </span>
        </div>
        <p class="text-gray-500 text-sm mt-1">
            Set one base price per grade — all products, 74 kg variants, and branch prices update automatically.
            <span class="text-amber-600 font-medium">Changes require review before saving.</span>
        </p>
    </div>
    <a href="pricing.php?variant_id=1"
       class="flex-shrink-0 text-sm text-primary-600 hover:underline whitespace-nowrap">
        ← Manual pricing
    </a>
</div>

<?php if ($flash_success): ?>
<div class="mb-6 bg-green-50 border border-green-300 text-green-800 px-4 py-3 rounded-lg flex items-start gap-3">
    <i class="fas fa-check-circle mt-0.5 text-green-600 flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($flash_success); ?></span>
</div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div class="mb-6 bg-red-50 border border-red-300 text-red-800 px-4 py-3 rounded-lg flex items-start gap-3">
    <i class="fas fa-exclamation-circle mt-0.5 text-red-600 flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($flash_error); ?></span>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════
     SECTION 1 — FORMULA CONSTANTS & BRANCH SURCHARGES
══════════════════════════════════════════════════════════════ -->
<form method="POST" class="mb-6" id="configForm">
    <input type="hidden" name="action"     value="save_config">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <i class="fas fa-cog text-primary-600"></i>
                <h2 class="font-bold text-gray-800">Pricing Rules Configuration</h2>
            </div>
            <button type="submit"
                    class="px-4 py-1.5 text-sm bg-gray-700 text-white rounded-lg hover:bg-gray-800 font-medium">
                <i class="fas fa-save mr-1"></i>Save Config
            </button>
        </div>

        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-8">

            <!-- Formula -->
            <div>
                <h3 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
                    <span class="inline-block bg-blue-100 text-blue-700 text-xs font-bold px-2 py-0.5 rounded">Formula</span>
                    74 kg Auto-Price Formula
                </h3>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4 font-mono text-sm text-blue-900">
                    price_74 = (price_50 ÷ <span id="disp_bag50"><?php echo $config['formula']['bag_50']; ?></span>)
                    × <span id="disp_bag74"><?php echo $config['formula']['bag_74']; ?></span>
                    + <span id="disp_pkgfee"><?php echo $config['formula']['packaging_fee']; ?></span>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Bag size (50 kg)</label>
                        <input type="number" name="bag_50"
                               value="<?php echo $config['formula']['bag_50']; ?>"
                               min="1" step="1" onchange="updateFormulaDisplay()"
                               class="w-full px-3 py-1.5 border rounded-lg text-sm text-center focus:ring-2 focus:ring-primary-400">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Bag size (74 kg)</label>
                        <input type="number" name="bag_74"
                               value="<?php echo $config['formula']['bag_74']; ?>"
                               min="1" step="1" onchange="updateFormulaDisplay()"
                               class="w-full px-3 py-1.5 border rounded-lg text-sm text-center focus:ring-2 focus:ring-primary-400">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Packaging fee (BDT)</label>
                        <input type="number" name="packaging_fee"
                               value="<?php echo $config['formula']['packaging_fee']; ?>"
                               step="0.01" onchange="updateFormulaDisplay()"
                               class="w-full px-3 py-1.5 border rounded-lg text-sm text-center focus:ring-2 focus:ring-primary-400">
                    </div>
                </div>
                <p class="mt-3 text-xs text-gray-500">
                    <i class="fas fa-info-circle mr-1 text-blue-500"></i>
                    Example — Grade A (base 50 kg = ৳2,500):
                    (2500 ÷ 50) × 74 + 150 = <strong>৳3,850</strong>
                </p>
            </div>

            <!-- Branch surcharges -->
            <div>
                <h3 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
                    <span class="inline-block bg-green-100 text-green-700 text-xs font-bold px-2 py-0.5 rounded">Branches</span>
                    Delivery Surcharges (BDT added to base price)
                </h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm border-collapse">
                        <thead>
                            <tr class="text-xs text-gray-600 bg-gray-50">
                                <th class="px-3 py-2 text-left border border-gray-200" rowspan="2">Branch</th>
                                <th class="px-3 py-2 text-center bg-blue-50 border border-blue-200" colspan="2">
                                    <i class="fas fa-truck mr-1 text-blue-500"></i>Big Truck (25MT)
                                </th>
                                <th class="px-3 py-2 text-center bg-orange-50 border border-orange-200" colspan="2">
                                    <i class="fas fa-truck mr-1 text-orange-500 text-xs"></i>Mini Truck
                                    <span class="block text-[10px] font-normal normal-case text-orange-600">extra on top of Big Truck</span>
                                </th>
                            </tr>
                            <tr class="text-xs text-gray-500 uppercase bg-gray-50">
                                <th class="px-3 py-2 text-center bg-blue-50 border border-blue-200">+ 50 kg</th>
                                <th class="px-3 py-2 text-center bg-blue-50 border border-blue-200">+ 74 kg</th>
                                <th class="px-3 py-2 text-center bg-orange-50 border border-orange-200">+ 50 kg</th>
                                <th class="px-3 py-2 text-center bg-orange-50 border border-orange-200">+ 74 kg</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($branches as $b): ?>
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-800 border border-gray-200">
                                    <?php echo htmlspecialchars($b->name); ?>
                                    <span class="text-xs text-gray-400 ml-1">(<?php echo htmlspecialchars($b->code ?? ''); ?>)</span>
                                </td>
                                <td class="px-3 py-2 bg-blue-50">
                                    <input type="number"
                                           name="surcharge_50_<?php echo $b->id; ?>"
                                           value="<?php echo $config['branch_surcharges'][$b->id]['surcharge_50'] ?? 0; ?>"
                                           step="0.01" onchange="recalcPreview()"
                                           class="w-full px-2 py-1 border border-blue-200 rounded text-center text-sm focus:ring-2 focus:ring-blue-400">
                                </td>
                                <td class="px-3 py-2 bg-blue-50">
                                    <input type="number"
                                           name="surcharge_74_<?php echo $b->id; ?>"
                                           value="<?php echo $config['branch_surcharges'][$b->id]['surcharge_74'] ?? 0; ?>"
                                           step="0.01" onchange="recalcPreview()"
                                           class="w-full px-2 py-1 border border-blue-200 rounded text-center text-sm focus:ring-2 focus:ring-blue-400">
                                </td>
                                <td class="px-3 py-2 bg-orange-50">
                                    <input type="number"
                                           name="mt_surcharge_50_<?php echo $b->id; ?>"
                                           value="<?php echo $config['mini_truck_surcharges'][$b->id]['surcharge_50'] ?? 0; ?>"
                                           step="0.01" onchange="recalcPreview()"
                                           class="w-full px-2 py-1 border border-orange-200 rounded text-center text-sm focus:ring-2 focus:ring-orange-400">
                                </td>
                                <td class="px-3 py-2 bg-orange-50">
                                    <input type="number"
                                           name="mt_surcharge_74_<?php echo $b->id; ?>"
                                           value="<?php echo $config['mini_truck_surcharges'][$b->id]['surcharge_74'] ?? 0; ?>"
                                           step="0.01" onchange="recalcPreview()"
                                           class="w-full px-2 py-1 border border-orange-200 rounded text-center text-sm focus:ring-2 focus:ring-orange-400">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-gray-500">
                    <i class="fas fa-info-circle mr-1 text-blue-500"></i>
                    Demra Big Truck (25MT) = factory gate base price (৳0 surcharge).
                    Mini Truck surcharge is <strong>additional</strong> on top of the Big Truck price and is not stored separately — computed at order time.
                </p>
            </div>
        </div>
    </div>
</form>

<!-- ══════════════════════════════════════════════════════════════
     PRICING FORM (grade inputs + custom weight inputs)
══════════════════════════════════════════════════════════════ -->
<form method="POST" id="pricingForm">
    <input type="hidden" name="action"     value="apply_prices">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

    <!-- ── SECTION 2: Grade-based pricing ────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h2 class="font-bold text-gray-800 flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-primary-600 text-white text-xs font-bold">1</span>
                Set Base 50 kg Price per Grade
            </h2>
            <p class="text-xs text-gray-500 mt-1">
                Enter the factory-gate base price for each grade's 50 kg bag.
                All products in that grade at 50 kg and 74 kg will be updated automatically.
            </p>
        </div>

        <div class="p-6 space-y-6">
            <?php foreach ($grade_data as $grade => $wc_groups):
                $variants_50    = $wc_groups['50']     ?? [];
                $variants_74    = $wc_groups['74']     ?? [];
                $current        = $current_50[$grade]  ?? '';

                // Computed 74 kg preview
                $b50 = $config['formula']['bag_50'];
                $b74 = $config['formula']['bag_74'];
                $pf  = $config['formula']['packaging_fee'];
                $calc74 = ($current !== '') ? round(($current / $b50) * $b74 + $pf, 2) : null;
            ?>
            <div class="border border-gray-200 rounded-xl overflow-hidden hover:border-primary-300 transition-colors">

                <!-- Grade header -->
                <div class="flex items-center gap-3 px-5 py-3 bg-gray-50 border-b border-gray-200">
                    <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg
                                 bg-primary-100 text-primary-700 font-bold text-lg">
                        <?php echo htmlspecialchars($grade); ?>
                    </span>
                    <div>
                        <div class="font-semibold text-gray-800">
                            Grade <?php echo htmlspecialchars($grade); ?>
                        </div>
                        <div class="text-xs text-gray-500">
                            <?php echo count($variants_50); ?> × 50 kg product<?php echo count($variants_50) !== 1 ? 's' : ''; ?>
                            &nbsp;·&nbsp;
                            <?php echo count($variants_74); ?> × 74 kg product<?php echo count($variants_74) !== 1 ? 's' : ''; ?>
                        </div>
                    </div>
                </div>

                <div class="p-5 grid grid-cols-1 lg:grid-cols-3 gap-6">

                    <!-- Price input -->
                    <div class="flex flex-col justify-center">
                        <label class="block text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wide">
                            Base 50 kg Price (BDT)
                        </label>
                        <input type="number"
                               name="base50_<?php echo htmlspecialchars($grade); ?>"
                               id="base50_<?php echo htmlspecialchars($grade); ?>"
                               value="<?php echo $current !== '' ? number_format($current, 2, '.', '') : ''; ?>"
                               step="0.01" min="0"
                               placeholder="Enter price…"
                               oninput="recalcPreview()"
                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-center
                                      font-bold text-xl text-gray-900
                                      focus:ring-2 focus:ring-primary-500 focus:border-primary-500
                                      transition-colors">
                        <div class="mt-3 text-center text-sm text-gray-500">
                            74 kg auto price →
                            <span class="font-bold text-purple-700" id="calc74_<?php echo htmlspecialchars($grade); ?>">
                                <?php echo $calc74 !== null ? '৳' . number_format($calc74, 2) : '—'; ?>
                            </span>
                        </div>
                    </div>

                    <!-- 50 kg products -->
                    <div>
                        <div class="flex items-center gap-1.5 mb-2">
                            <span class="inline-block w-2.5 h-2.5 rounded-full bg-blue-400"></span>
                            <span class="text-xs font-semibold text-blue-700 uppercase tracking-wide">50 kg Products</span>
                        </div>
                        <?php if (empty($variants_50)): ?>
                            <p class="text-xs text-gray-400 italic">No 50 kg variants in database</p>
                        <?php else: ?>
                        <div class="space-y-1.5 max-h-40 overflow-y-auto pr-1">
                            <?php foreach ($variants_50 as $v): ?>
                            <div class="flex items-start gap-2 bg-blue-50 rounded-lg px-3 py-1.5">
                                <i class="fas fa-box text-blue-400 text-xs mt-0.5 flex-shrink-0"></i>
                                <div class="min-w-0">
                                    <div class="text-xs font-medium text-gray-800 truncate">
                                        <?php echo htmlspecialchars($v['product_name']); ?>
                                    </div>
                                    <div class="text-[10px] text-gray-500 truncate">
                                        SKU: <?php echo htmlspecialchars($v['sku']); ?>
                                        <?php if ($v['current_price'] !== null): ?>
                                        · Current: ৳<?php echo number_format($v['current_price'], 2); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- 74 kg products -->
                    <div>
                        <div class="flex items-center gap-1.5 mb-2">
                            <span class="inline-block w-2.5 h-2.5 rounded-full bg-purple-400"></span>
                            <span class="text-xs font-semibold text-purple-700 uppercase tracking-wide">74 kg Products (auto)</span>
                        </div>
                        <?php if (empty($variants_74)): ?>
                            <p class="text-xs text-gray-400 italic">No 74 kg variants in database</p>
                        <?php else: ?>
                        <div class="space-y-1.5 max-h-40 overflow-y-auto pr-1">
                            <?php foreach ($variants_74 as $v): ?>
                            <div class="flex items-start gap-2 bg-purple-50 rounded-lg px-3 py-1.5">
                                <i class="fas fa-box text-purple-400 text-xs mt-0.5 flex-shrink-0"></i>
                                <div class="min-w-0">
                                    <div class="text-xs font-medium text-gray-800 truncate">
                                        <?php echo htmlspecialchars($v['product_name']); ?>
                                    </div>
                                    <div class="text-[10px] text-gray-500 truncate">
                                        SKU: <?php echo htmlspecialchars($v['sku']); ?>
                                        <?php if ($v['current_price'] !== null): ?>
                                        · Current: ৳<?php echo number_format($v['current_price'], 2); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── SECTION 3: Custom-weight products ─────────────────────────────── -->
    <?php
    // Collect all custom-weight variants across all grades
    $custom_all = [];
    foreach ($grade_data as $grade => $wc_groups) {
        foreach ($wc_groups['custom'] ?? [] as $item) {
            $custom_all[] = array_merge($item, ['grade' => $grade]);
        }
    }
    ?>
    <?php if (!empty($custom_all)): ?>
    <div class="bg-white rounded-xl shadow-sm border border-amber-200 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-amber-200 bg-amber-50">
            <h2 class="font-bold text-amber-800 flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-amber-600 text-white text-xs font-bold">2</span>
                Custom-Weight Products — Manual Price
            </h2>
            <p class="text-xs text-amber-700 mt-1">
                These products have non-standard bag weights (not 50 kg or 74 kg).
                The auto-formula does not apply — enter their price directly.
                Leave blank to keep the current price unchanged.
            </p>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($custom_all as $item): ?>
                <div class="border border-gray-200 rounded-xl p-4 hover:border-amber-300 transition-colors">
                    <div class="flex items-start justify-between mb-3">
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold text-gray-800 truncate">
                                <?php echo htmlspecialchars($item['product_name']); ?>
                            </div>
                            <div class="text-xs text-gray-500 mt-0.5 flex items-center gap-2 flex-wrap">
                                <span class="inline-block px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded text-[10px] font-medium">
                                    <?php echo htmlspecialchars($item['weight_variant']); ?> <?php echo htmlspecialchars($item['uom']); ?>
                                </span>
                                <span class="inline-block px-1.5 py-0.5 bg-gray-100 text-gray-600 rounded text-[10px]">
                                    Grade <?php echo htmlspecialchars($item['grade']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Price per bag (BDT)</label>
                    <input type="number"
                           name="custom_price_<?php echo $item['variant_id']; ?>"
                           id="custom_price_<?php echo $item['variant_id']; ?>"
                           value="<?php echo $item['current_price'] !== null
                               ? number_format($item['current_price'], 2, '.', '')
                               : ''; ?>"
                           step="0.01" min="0"
                           placeholder="Enter price…"
                           oninput="recalcCustom(<?php echo $item['variant_id']; ?>)"
                           class="w-full px-3 py-2 border-2 border-gray-200 rounded-lg text-center
                                  font-bold text-base focus:ring-2 focus:ring-amber-400 focus:border-amber-400
                                  transition-colors">
                    <?php if ($item['current_price'] !== null): ?>
                    <p class="mt-1 text-[10px] text-gray-400 text-center">
                        Current: ৳<?php echo number_format($item['current_price'], 2); ?> (all branches)
                    </p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── SECTION 4: Live preview matrix ────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
            <h2 class="font-bold text-gray-800 flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-primary-600 text-white text-xs font-bold">
                    <?php echo !empty($custom_all) ? '3' : '2'; ?>
                </span>
                Live Price Preview
                <span class="text-xs font-normal text-gray-400 ml-1">— updates as you type</span>
            </h2>
            <div class="flex items-center gap-3 text-xs text-gray-500 flex-wrap">
                <span><span class="inline-block w-2.5 h-2.5 bg-blue-200 border border-blue-400 rounded mr-1"></span>BT 50 kg</span>
                <span><span class="inline-block w-2.5 h-2.5 bg-purple-200 border border-purple-400 rounded mr-1"></span>BT 74 kg</span>
                <span><span class="inline-block w-2.5 h-2.5 bg-orange-200 border border-orange-400 rounded mr-1"></span>MT 50 kg</span>
                <span><span class="inline-block w-2.5 h-2.5 bg-amber-200 border border-amber-400 rounded mr-1"></span>MT 74 kg</span>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm" id="previewTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase sticky left-0 bg-gray-50 min-w-[90px]">Grade</th>
                        <th class="px-3 py-3 text-center text-xs font-medium text-blue-600 uppercase bg-blue-50 min-w-[100px]">Base 50 kg</th>
                        <th class="px-3 py-3 text-center text-xs font-medium text-purple-600 uppercase bg-purple-50 min-w-[100px]">Base 74 kg</th>
                        <?php foreach ($branches as $b): ?>
                        <th class="px-2 py-2 text-center text-xs font-medium text-blue-700 bg-blue-50 border-l-2 border-blue-300 min-w-[80px]">
                            <?php echo htmlspecialchars($b->name); ?>
                            <span class="block text-[10px] font-normal normal-case text-blue-500 mt-0.5"><i class="fas fa-truck mr-0.5"></i>BT 50</span>
                        </th>
                        <th class="px-2 py-2 text-center text-xs font-medium text-purple-700 bg-purple-50 min-w-[80px]">
                            <?php echo htmlspecialchars($b->name); ?>
                            <span class="block text-[10px] font-normal normal-case text-purple-500 mt-0.5"><i class="fas fa-truck mr-0.5"></i>BT 74</span>
                        </th>
                        <th class="px-2 py-2 text-center text-xs font-medium text-orange-700 bg-orange-50 border-l border-orange-200 min-w-[80px]">
                            <?php echo htmlspecialchars($b->name); ?>
                            <span class="block text-[10px] font-normal normal-case text-orange-500 mt-0.5"><i class="fas fa-truck mr-0.5"></i>MT 50</span>
                        </th>
                        <th class="px-2 py-2 text-center text-xs font-medium text-amber-700 bg-amber-50 border-r-2 border-orange-300 min-w-[80px]">
                            <?php echo htmlspecialchars($b->name); ?>
                            <span class="block text-[10px] font-normal normal-case text-amber-500 mt-0.5"><i class="fas fa-truck mr-0.5"></i>MT 74</span>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody id="previewBody" class="divide-y divide-gray-100 bg-white">
                    <?php foreach ($grades as $grade): ?>
                    <tr data-grade="<?php echo htmlspecialchars($grade); ?>" class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-bold text-gray-800 sticky left-0 bg-white">Grade <?php echo htmlspecialchars($grade); ?></td>
                        <td class="px-3 py-3 text-center bg-blue-50 font-semibold text-blue-900"
                            id="prev_base50_<?php echo htmlspecialchars($grade); ?>">—</td>
                        <td class="px-3 py-3 text-center bg-purple-50 font-semibold text-purple-900"
                            id="prev_base74_<?php echo htmlspecialchars($grade); ?>">—</td>
                        <?php foreach ($branches as $b): ?>
                        <td class="px-2 py-3 text-center bg-blue-50 text-blue-800 text-sm border-l-2 border-blue-300"
                            id="prev_b<?php echo $b->id; ?>_bt50_<?php echo htmlspecialchars($grade); ?>">—</td>
                        <td class="px-2 py-3 text-center bg-purple-50 text-purple-800 text-sm"
                            id="prev_b<?php echo $b->id; ?>_bt74_<?php echo htmlspecialchars($grade); ?>">—</td>
                        <td class="px-2 py-3 text-center bg-orange-50 text-orange-800 text-sm border-l border-orange-200"
                            id="prev_b<?php echo $b->id; ?>_mt50_<?php echo htmlspecialchars($grade); ?>">—</td>
                        <td class="px-2 py-3 text-center bg-amber-50 text-amber-800 text-sm border-r-2 border-orange-300"
                            id="prev_b<?php echo $b->id; ?>_mt74_<?php echo htmlspecialchars($grade); ?>">—</td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="px-6 py-3 bg-gray-50 border-t border-gray-200 text-xs text-gray-500 flex flex-wrap gap-4">
            <span><i class="fas fa-calculator mr-1 text-blue-500"></i>74 kg = (50 kg ÷ <?php echo $config['formula']['bag_50']; ?>) × <?php echo $config['formula']['bag_74']; ?> + <?php echo $config['formula']['packaging_fee']; ?></span>
            <span><i class="fas fa-truck mr-1 text-blue-500"></i>BT = Big Truck (25MT) — price stored in DB</span>
            <span><i class="fas fa-truck mr-1 text-orange-500"></i>MT = Mini Truck — BT + mini truck surcharge (not stored separately)</span>
            <span><i class="fas fa-layer-group mr-1 text-purple-500"></i>All products in the same grade share the same grade price</span>
        </div>
    </div>

    <!-- ── SECTION 5: Review & Apply ─────────────────────────────────────── -->
    <div class="flex items-center justify-between bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div>
            <p class="font-semibold text-gray-800 flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-primary-600 text-white text-xs font-bold">
                    <?php echo !empty($custom_all) ? '4' : '3'; ?>
                </span>
                Review &amp; Apply
            </p>
            <p class="text-sm text-gray-500 mt-1">
                Click <strong>Review Changes</strong> to see a full before/after comparison before anything is saved.
            </p>
        </div>
        <div class="flex items-center gap-3">
            <button type="button" onclick="resetForm()"
                    class="px-5 py-2.5 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                <i class="fas fa-undo mr-2"></i>Reset
            </button>
            <button type="button" id="reviewBtn" onclick="openReviewModal()"
                    class="px-6 py-2.5 bg-primary-600 hover:bg-primary-700 text-white rounded-lg
                           text-sm font-bold shadow flex items-center gap-2 transition-colors">
                <i class="fas fa-search mr-2"></i>Review Changes
            </button>
        </div>
    </div>

</form>

</div><!-- end page wrapper -->

<!-- ══════════════════════════════════════════════════════════════
     REVIEW MODAL — before/after diff
══════════════════════════════════════════════════════════════ -->
<div id="reviewModal"
     class="hidden fixed inset-0 z-50 flex items-start justify-center bg-black/60 overflow-y-auto py-8"
     onclick="if(event.target===this) closeReviewModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl mx-4 flex flex-col"
         style="max-height:90vh;">

        <!-- Header -->
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between flex-shrink-0 rounded-t-2xl">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-primary-100 flex items-center justify-center">
                    <i class="fas fa-search text-primary-600"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Review Price Changes</h3>
                    <p class="text-sm text-gray-500">Confirm all changes before they are written to the database.</p>
                </div>
            </div>
            <button onclick="closeReviewModal()"
                    class="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <!-- Stats bar -->
        <div class="px-6 py-3 bg-gray-50 border-b border-gray-200 flex flex-wrap gap-5 text-sm flex-shrink-0">
            <span class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-full bg-gray-400 inline-block"></span>
                <strong id="reviewCount" class="text-gray-800">0</strong>
                <span class="text-gray-500">rows</span>
            </span>
            <span class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-full bg-green-500 inline-block"></span>
                <strong id="reviewIncreases" class="text-green-700">0</strong>
                <span class="text-gray-500">increases</span>
            </span>
            <span class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-full bg-red-500 inline-block"></span>
                <strong id="reviewDecreases" class="text-red-700">0</strong>
                <span class="text-gray-500">decreases</span>
            </span>
            <span class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-full bg-gray-300 inline-block"></span>
                <strong id="reviewUnchanged" class="text-gray-600">0</strong>
                <span class="text-gray-500">unchanged</span>
            </span>
            <span class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-full bg-blue-400 inline-block"></span>
                <strong id="reviewNew" class="text-blue-700">0</strong>
                <span class="text-gray-500">new (no prior price)</span>
            </span>
        </div>

        <!-- Scrollable table -->
        <div class="overflow-y-auto flex-1" style="min-height:0;">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 sticky top-0 z-10 shadow-sm">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Grade</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Branch</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Weight</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Current</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">New Price</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Δ Change</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">%</th>
                    </tr>
                </thead>
                <tbody id="reviewTableBody" class="divide-y divide-gray-100"></tbody>
            </table>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t border-gray-200 flex flex-col sm:flex-row items-start sm:items-center
                    justify-between gap-4 flex-shrink-0 bg-gray-50 rounded-b-2xl">
            <div class="space-y-2">
                <div class="flex items-center gap-2 text-sm text-amber-700 bg-amber-50 border border-amber-200
                            px-4 py-2.5 rounded-lg">
                    <i class="fas fa-exclamation-triangle text-amber-500 flex-shrink-0"></i>
                    <span>Existing prices will be <strong>archived</strong> and replaced with the new prices shown above.</span>
                </div>
                <div class="flex items-center gap-2 text-xs text-orange-700 bg-orange-50 border border-orange-200
                            px-3 py-1.5 rounded-lg">
                    <i class="fas fa-truck text-orange-400 flex-shrink-0"></i>
                    <span><strong>Big Truck (25MT)</strong> prices only are stored. Mini Truck price = above + mini truck surcharge, applied dynamically at order time.</span>
                </div>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0">
                <button onclick="closeReviewModal()"
                        class="px-5 py-2 border border-gray-300 rounded-lg text-sm font-medium
                               text-gray-700 hover:bg-gray-100 transition-colors">
                    <i class="fas fa-arrow-left mr-2"></i>Go Back
                </button>
                <button onclick="confirmAndApply()"
                        id="confirmApplyBtn"
                        class="px-6 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg
                               text-sm font-bold shadow flex items-center gap-2 transition-colors">
                    <i class="fas fa-check mr-2"></i>Confirm &amp; Apply
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════════ -->
<script>
// ── PHP → JS data ────────────────────────────────────────────────────────────
const GRADES   = <?php echo json_encode($grades); ?>;
const BRANCHES = <?php echo json_encode(
    array_map(fn($b) => ['id' => (string)$b->id, 'name' => $b->name], $branches)
); ?>;
const SURCHARGES            = <?php echo json_encode($config['branch_surcharges']); ?>;
const MINI_TRUCK_SURCHARGES = <?php echo json_encode($config['mini_truck_surcharges']); ?>;

// Current DB prices for grade-based (50/74) products
// CURRENT_PRICES[grade][branch_id][weight_class] = price
const CURRENT_PRICES = <?php echo json_encode($all_current_prices); ?>;

// Custom-weight variants for the review modal
// CUSTOM_VARIANTS = [{variant_id, product_name, weight_variant, grade}, ...]
const CUSTOM_VARIANTS = <?php echo json_encode(array_map(fn($v) => [
    'variant_id'    => (string)$v['variant_id'],
    'product_name'  => $v['product_name'],
    'weight_variant' => $v['weight_variant'],
    'grade'         => $v['grade'],
    'current_price' => $v['current_price'],
], $custom_all)); ?>;

// Current prices for custom-weight variants per branch
// CUSTOM_CURRENT[variant_id][branch_id] = price
const CUSTOM_CURRENT = <?php echo json_encode($custom_current_prices); ?>;

let formulaConfig = {
    bag_50:        <?php echo (float)$config['formula']['bag_50']; ?>,
    bag_74:        <?php echo (float)$config['formula']['bag_74']; ?>,
    packaging_fee: <?php echo (float)$config['formula']['packaging_fee']; ?>,
};

// ── Helpers ──────────────────────────────────────────────────────────────────
function roundDown5(v) { return Math.floor(v / 5) * 5; }

function fmt(val) {
    if (val === null || isNaN(val)) return '—';
    return '৳' + Math.abs(val).toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function getBranchSurcharge(branchId, wc, truck = 'big') {
    const prefix = truck === 'mini' ? 'mt_' : '';
    const el50 = document.querySelector(`[name="${prefix}surcharge_50_${branchId}"]`);
    const el74 = document.querySelector(`[name="${prefix}surcharge_74_${branchId}"]`);
    if (wc === '50') return parseFloat(el50?.value || 0);
    if (wc === '74') return parseFloat(el74?.value || 0);
    return 0;
}

function getFormulaBag50()  { return parseFloat(document.querySelector('[name="bag_50"]')?.value || formulaConfig.bag_50); }
function getFormulaBag74()  { return parseFloat(document.querySelector('[name="bag_74"]')?.value || formulaConfig.bag_74); }
function getFormulaPkgFee() { return parseFloat(document.querySelector('[name="packaging_fee"]')?.value || formulaConfig.packaging_fee); }

function calcPrice74(base50) {
    return (base50 / getFormulaBag50()) * getFormulaBag74() + getFormulaPkgFee();
}

// ── Live preview table ───────────────────────────────────────────────────────
function recalcPreview() {
    GRADES.forEach(grade => {
        const input  = document.getElementById(`base50_${grade}`);
        const base50 = parseFloat(input?.value);
        const valid  = !isNaN(base50) && base50 > 0;

        const b50Cell  = document.getElementById(`prev_base50_${grade}`);
        const b74Cell  = document.getElementById(`prev_base74_${grade}`);
        const calc74El = document.getElementById(`calc74_${grade}`);

        if (!valid) {
            [b50Cell, b74Cell].forEach(c => { if (c) c.textContent = '—'; });
            if (calc74El) calc74El.textContent = '—';
            BRANCHES.forEach(b => {
                ['bt50','bt74','mt50','mt74'].forEach(suffix => {
                    const c = document.getElementById(`prev_b${b.id}_${suffix}_${grade}`);
                    if (c) c.textContent = '—';
                });
            });
            return;
        }

        const base74 = roundDown5(calcPrice74(base50));
        if (b50Cell)  b50Cell.textContent  = fmt(base50);
        if (b74Cell)  b74Cell.textContent  = fmt(base74);
        if (calc74El) calc74El.textContent  = fmt(base74);

        BRANCHES.forEach(b => {
            const bt50 = roundDown5(base50 + getBranchSurcharge(b.id, '50', 'big'));
            const bt74 = roundDown5(base74 + getBranchSurcharge(b.id, '74', 'big'));
            const mt50 = roundDown5(bt50   + getBranchSurcharge(b.id, '50', 'mini'));
            const mt74 = roundDown5(bt74   + getBranchSurcharge(b.id, '74', 'mini'));

            const cBt50 = document.getElementById(`prev_b${b.id}_bt50_${grade}`);
            const cBt74 = document.getElementById(`prev_b${b.id}_bt74_${grade}`);
            const cMt50 = document.getElementById(`prev_b${b.id}_mt50_${grade}`);
            const cMt74 = document.getElementById(`prev_b${b.id}_mt74_${grade}`);
            if (cBt50) cBt50.textContent = fmt(bt50);
            if (cBt74) cBt74.textContent = fmt(bt74);
            if (cMt50) cMt50.textContent = fmt(mt50);
            if (cMt74) cMt74.textContent = fmt(mt74);
        });
    });
}

function recalcCustom(variantId) {
    // No live table for custom variants — just format validation
    const el = document.getElementById(`custom_price_${variantId}`);
    if (!el) return;
    const v = parseFloat(el.value);
    el.style.borderColor = (!isNaN(v) && v > 0) ? '#0284c7' : '';
}

function updateFormulaDisplay() {
    const b50 = getFormulaBag50(), b74 = getFormulaBag74(), pf = getFormulaPkgFee();
    const d = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    d('disp_bag50', b50); d('disp_bag74', b74); d('disp_pkgfee', pf);
    recalcPreview();
}

function resetForm() {
    if (!confirm('Reset all prices to their current database values?')) return;
    document.getElementById('pricingForm').reset();
    recalcPreview();
}

// ── Review modal ─────────────────────────────────────────────────────────────
function openReviewModal() {
    const bag50  = getFormulaBag50();
    const bag74  = getFormulaBag74();
    const pkgFee = getFormulaPkgFee();

    let rows = [];
    let increases = 0, decreases = 0, unchanged = 0, isNew = 0;

    // ── Grade-based rows (50 kg and 74 kg per branch) ────────────────────────
    GRADES.forEach(grade => {
        const base50 = parseFloat(document.getElementById(`base50_${grade}`)?.value);
        if (isNaN(base50) || base50 <= 0) return;

        const base74 = roundDown5((base50 / bag50) * bag74 + pkgFee);

        BRANCHES.forEach(branch => {
            ['50', '74'].forEach(wc => {
                const surcharge = getBranchSurcharge(branch.id, wc, 'big');
                const newP = roundDown5((wc === '50' ? base50 : base74) + surcharge);
                const curr  = (CURRENT_PRICES[grade] || {})[String(branch.id)] || {};
                const currP = curr[wc] !== undefined ? curr[wc] : null;
                const delta = currP !== null ? newP - currP : null;
                const pct   = (currP && delta !== null) ? (delta / currP) * 100 : null;

                rows.push({ label: `Grade ${grade}`, branch: branch.name,
                            weight: `${wc} kg`, curr: currP, newP, delta, pct,
                            isCustom: false });

                if (delta === null) isNew++;
                else if (delta >  0.005) increases++;
                else if (delta < -0.005) decreases++;
                else unchanged++;
            });
        });
    });

    // ── Custom-weight rows (flat price, all branches get same price) ─────────
    CUSTOM_VARIANTS.forEach(cv => {
        const el = document.getElementById(`custom_price_${cv.variant_id}`);
        const newP = roundDown5(parseFloat(el?.value));
        if (isNaN(newP) || newP <= 0) return;

        BRANCHES.forEach(branch => {
            const currPrices = CUSTOM_CURRENT[String(cv.variant_id)] || {};
            const currP = currPrices[String(branch.id)] !== undefined
                        ? currPrices[String(branch.id)] : null;
            const delta = currP !== null ? newP - currP : null;
            const pct   = (currP && delta !== null) ? (delta / currP) * 100 : null;

            rows.push({ label: cv.product_name,
                        branch: branch.name,
                        weight: `${cv.weight_variant} kg (custom)`,
                        curr: currP, newP, delta, pct, isCustom: true });

            if (delta === null) isNew++;
            else if (delta >  0.005) increases++;
            else if (delta < -0.005) decreases++;
            else unchanged++;
        });
    });

    if (rows.length === 0) {
        alert('Please enter at least one price before reviewing.');
        return;
    }

    // ── Render rows ──────────────────────────────────────────────────────────
    const html = rows.map(r => {
        let rowClass = 'bg-white', deltaHtml = '—', pctHtml = '—';
        const currHtml = r.curr !== null
            ? `<span class="font-medium text-gray-700">${fmt(r.curr)}</span>`
            : `<span class="text-blue-500 text-xs font-medium">New</span>`;

        if (r.delta === null) {
            rowClass  = 'bg-blue-50';
            deltaHtml = `<span class="text-blue-600 text-xs font-medium">No prior price</span>`;
        } else if (r.delta > 0.005) {
            rowClass  = 'bg-green-50';
            deltaHtml = `<span class="text-green-700 font-semibold">+${fmt(r.delta)}</span>`;
            pctHtml   = r.pct != null ? `<span class="text-green-700 font-medium">+${r.pct.toFixed(1)}%</span>` : '—';
        } else if (r.delta < -0.005) {
            rowClass  = 'bg-red-50';
            deltaHtml = `<span class="text-red-700 font-semibold">(${fmt(Math.abs(r.delta))})</span>`;
            pctHtml   = r.pct != null ? `<span class="text-red-700 font-medium">${r.pct.toFixed(1)}%</span>` : '—';
        } else {
            rowClass  = 'bg-gray-50';
            deltaHtml = `<span class="text-gray-400">No change</span>`;
            pctHtml   = `<span class="text-gray-400">0.0%</span>`;
        }

        const wtClass = r.weight.startsWith('50') ? 'bg-blue-100 text-blue-800'
                      : r.weight.startsWith('74') ? 'bg-purple-100 text-purple-800'
                      : 'bg-amber-100 text-amber-800';

        return `<tr class="${rowClass} border-b border-gray-100">
            <td class="px-4 py-2.5 font-semibold text-gray-800 text-sm">${r.label}</td>
            <td class="px-4 py-2.5 text-gray-700 text-sm">${r.branch}</td>
            <td class="px-4 py-2.5 text-center">
                <span class="px-2 py-0.5 rounded text-xs font-medium ${wtClass}">${r.weight}</span>
            </td>
            <td class="px-4 py-2.5 text-center">${currHtml}</td>
            <td class="px-4 py-2.5 text-center font-bold text-gray-900">${fmt(r.newP)}</td>
            <td class="px-4 py-2.5 text-center">${deltaHtml}</td>
            <td class="px-4 py-2.5 text-center">${pctHtml}</td>
        </tr>`;
    }).join('');

    document.getElementById('reviewTableBody').innerHTML = html;
    document.getElementById('reviewCount').textContent     = rows.length;
    document.getElementById('reviewIncreases').textContent = increases;
    document.getElementById('reviewDecreases').textContent = decreases;
    document.getElementById('reviewUnchanged').textContent = unchanged;
    document.getElementById('reviewNew').textContent       = isNew;

    document.getElementById('reviewModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeReviewModal() {
    document.getElementById('reviewModal').classList.add('hidden');
    document.body.style.overflow = '';
}

function confirmAndApply() {
    const btn = document.getElementById('confirmApplyBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Applying…'; }
    document.getElementById('reviewModal').classList.add('hidden');
    document.body.style.overflow = '';
    document.getElementById('pricingForm').submit();
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeReviewModal(); });
document.addEventListener('DOMContentLoaded', recalcPreview);
</script>

<?php require_once '../templates/footer.php'; ?>

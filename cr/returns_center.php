<?php
/**
 * Feature #2 — Returns, Adjustments & Over-Delivery on ONE page, ONE tab.
 * This hub renders the shared chrome + tab bar, then server-side includes the
 * ONE active content page per request (returns.php / stock_adjustment.php /
 * over_delivery.php) with CR_EMBED defined so each renders body-only (no header,
 * footer, own tab bar, or standalone-redirect). Including only one per request
 * keeps their variable scopes from colliding.
 */
require_once '../core/init.php';

// Union of the three pages' role lists, so anyone who could reach ANY of them
// can reach the hub. Page-grant holders for any of the three also pass.
$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra',
                  'sales-srg', 'sales-demra', 'sales-other',
                  'dispatch-srg', 'dispatch-demra',
                  'production manager-srg', 'production manager-demra'];
if (!userHasPageGrant('credit_sales', 'returns')
    && !userHasPageGrant('credit_sales', 'stock_adjustment')
    && !userHasPageGrant('credit_sales', 'over_delivery')) {
    restrict_access($allowed_roles, 'credit_sales', 'returns');
}

define('CR_EMBED', 1);

global $db;
$currentUser = getCurrentUser();
$pageTitle   = 'Returns, Adjustments & Over-Delivery';

// Active tab: explicit ?tab wins and is remembered; else fall back to the last
// tab (so a GET filter / POST inside a tab — which drops ?tab — stays put).
$tabs = [
    'returns' => ['file' => 'returns.php',          'label' => 'Goods Returns',     'icon' => 'fa-undo-alt'],
    'adjust'  => ['file' => 'stock_adjustment.php', 'label' => 'Stock Adjustments', 'icon' => 'fa-sliders-h'],
    'od'      => ['file' => 'over_delivery.php',     'label' => 'Over-Delivery',     'icon' => 'fa-truck-loading'],
];
if (isset($_GET['tab']) && isset($tabs[$_GET['tab']])) {
    $active_tab = $_GET['tab'];
    $_SESSION['rc_active_tab'] = $active_tab;
} else {
    $active_tab = $_SESSION['rc_active_tab'] ?? 'returns';
}

require_once '../templates/header.php';
?>
<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <div class="mb-4">
        <h1 class="text-3xl font-bold text-gray-900">Returns, Adjustments &amp; Over-Delivery</h1>
        <p class="text-gray-600 mt-1">Record goods returns, stock adjustments and over-deliveries — each needs approval by a different authorised user.</p>
    </div>

    <!-- Single tab bar (in-page tabs) -->
    <div class="flex flex-wrap gap-1 border-b border-gray-200 mb-6">
        <?php foreach ($tabs as $key => $t):
            $is = ($key === $active_tab); ?>
        <a href="returns_center.php?tab=<?php echo $key; ?>"
           class="px-5 py-2.5 text-sm font-semibold border-b-2 -mb-px transition <?php echo $is
               ? 'text-orange-600 border-orange-500'
               : 'text-gray-500 border-transparent hover:text-gray-800 hover:border-gray-300'; ?>">
            <i class="fas <?php echo $t['icon']; ?> mr-1.5"></i><?php echo $t['label']; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Active tab content (body-only include) -->
    <?php include __DIR__ . '/' . $tabs[$active_tab]['file']; ?>

</div>
<?php require_once '../templates/footer.php'; ?>
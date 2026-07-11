<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra',
                  'sales-srg', 'sales-demra', 'sales-other', 'collector',
                  'production manager-srg', 'production manager-demra',
                  'dispatch-srg', 'dispatch-demra', 'dispatchpos-srg', 'dispatchpos-demra'];
restrict_access($allowed_roles, 'credit_sales', 'sales_hub');

global $db;
$currentUser = getCurrentUser();
$user_role   = $currentUser['role'] ?? '';
$pageTitle   = 'Sales Hub';

$is_admin = in_array($user_role, ['Superadmin', 'admin']);

// ── Per-tool toggles (Privileges UI → Credit Sales → Sales Hub) ──────────────
// Admin sees every tool; other users see exactly what admin has toggled on.
function hub_can(string $action): bool {
    global $is_admin;
    return $is_admin || userCanPageAction('credit_sales', 'sales_hub', $action);
}

// ── Live pipeline numbers (Overview panel) ───────────────────────────────────
function _hub_count($db, string $sql, array $p = []): int {
    try { return (int)($db->query($sql, $p)->first()->c ?? 0); } catch (Exception $e) { return 0; }
}

$n_pending    = _hub_count($db, "SELECT COUNT(*) c FROM credit_orders WHERE status = 'pending_approval'");
$n_escalated  = _hub_count($db, "SELECT COUNT(*) c FROM credit_orders WHERE status = 'escalated'");
$n_approved   = _hub_count($db, "SELECT COUNT(*) c FROM credit_orders WHERE status = 'approved'");
$n_inprod     = _hub_count($db, "SELECT COUNT(*) c FROM credit_orders WHERE status = 'in_production'");
$n_produced   = _hub_count($db, "SELECT COUNT(*) c FROM credit_orders WHERE status = 'produced'");
$n_ready      = _hub_count($db, "SELECT COUNT(*) c FROM credit_orders WHERE status = 'ready_to_ship'");
$n_shipped    = _hub_count($db, "SELECT COUNT(*) c FROM credit_orders WHERE status = 'shipped'");

$n_holds = _hub_count($db,
    "SELECT COUNT(*) c FROM order_approval_conditions oac
     JOIN credit_orders co ON co.id = oac.order_id
     WHERE oac.dispatch_hold = 1 AND oac.dispatch_cleared = 0
       AND co.status NOT IN ('delivered','cancelled','rejected')");

$n_returns_pending = _hub_count($db, "SELECT COUNT(*) c FROM credit_order_returns WHERE status = 'pending'");
$n_od_pending      = _hub_count($db, "SELECT COUNT(*) c FROM credit_order_over_deliveries WHERE status = 'pending'");

$collected_today = 0.0;
try {
    $collected_today = (float)($db->query(
        "SELECT COALESCE(SUM(amount),0) s FROM customer_payments WHERE payment_date = CURDATE()"
    )->first()->s ?? 0);
} catch (Exception $e) {}

$total_outstanding = 0.0;
try {
    $total_outstanding = (float)($db->query(
        "SELECT COALESCE(SUM(
                    COALESCE(c.initial_due,0) + COALESCE(tb.d,0) - COALESCE(tb.cr,0)
                ),0) s
         FROM customers c
         LEFT JOIN (SELECT customer_id, SUM(debit_amount) d, SUM(credit_amount) cr
                    FROM customer_ledger WHERE reference_type != 'initial_due'
                    GROUP BY customer_id) tb ON tb.customer_id = c.id
         WHERE c.status = 'active' AND c.customer_type = 'Credit'"
    )->first()->s ?? 0);
} catch (Exception $e) {}

// ── SPA tool registry: every tool carries its own privilege action.
//    route => [file, label, icon, action]. A stage renders only if the user
//    has at least one of its tools. ─────────────────────────────────────────
$stage_defs = [
    ['key' => 'create', 'label' => '1 · Order Creation', 'icon' => 'fa-plus-circle', 'color' => 'blue',
     'tools' => [
        'create_order'    => ['create_order.php',               'Create New Order',       'fa-plus',             'can_create_order'],
        'advance_collect' => ['advance_payment_collection.php', 'Collect Advance',        'fa-hand-holding-usd', 'can_advance_collect'],
        'credit_limits'   => ['customer_credit_management.php', 'Customer Credit Limits', 'fa-sliders-h',        'can_credit_limits'],
     ]],
    ['key' => 'approve', 'label' => '2 · Approval', 'icon' => 'fa-check-circle', 'color' => 'yellow',
     'badge' => $n_pending + $n_escalated,
     'tools' => [
        'approve_orders' => ['credit_order_approval.php', 'Approve Orders', 'fa-check', 'can_approve_orders'],
        'payment_watch'  => ['payment_watch.php',         'Payment Watch',  'fa-eye',   'can_payment_watch'],
     ]],
    ['key' => 'production', 'label' => '3 · Production', 'icon' => 'fa-industry', 'color' => 'purple',
     'badge' => $n_approved + $n_inprod,
     'tools' => [
        'production_queue' => ['credit_production.php', 'Production Queue', 'fa-industry',      'can_production_queue'],
        'order_tracker'    => ['order_status.php',      'Track Orders',     'fa-map-marker-alt','can_order_tracker'],
     ]],
    ['key' => 'dispatch', 'label' => '4 · Dispatch & Delivery', 'icon' => 'fa-truck', 'color' => 'orange',
     'badge' => $n_ready,
     'tools' => [
        'dispatch_board'   => ['credit_dispatch.php',  'Dispatch Board',   'fa-shipping-fast', 'can_dispatch_board'],
        'partial_delivery' => ['partial_delivery.php', 'Partial Delivery', 'fa-dolly',         'can_partial_delivery'],
     ]],
    ['key' => 'payments', 'label' => '5 · Payments', 'icon' => 'fa-money-bill-wave', 'color' => 'green',
     'tools' => [
        'customer_payment' => ['customer_payment.php',       'Record Payment',  'fa-cash-register',    'can_customer_payment'],
        'field_collect'    => ['credit_payment_collect.php', 'Collect (Field)', 'fa-hand-holding-usd', 'can_field_collect'],
        'payment_history'  => ['payment_history.php',        'Payment History', 'fa-history',          'can_payment_history'],
        'bank_statement'   => ['bank_statement.php',         'Bank Statement',  'fa-university',       'can_bank_statement'],
     ]],
    ['key' => 'reports', 'label' => '6 · Reports & Adjust', 'icon' => 'fa-chart-bar', 'color' => 'teal',
     'badge' => ($n_returns_pending + $n_od_pending) ?: null,
     'tools' => [
        'all_sales'       => ['all_sales.php',       'All Sales',       'fa-list-alt',       'can_all_sales'],
        'sales_report'    => ['sales_report.php',    'Sales Report',    'fa-chart-line',     'can_sales_report'],
        'ageing_report'   => ['ageing_report.php',   'Ageing Report',   'fa-hourglass-half', 'can_ageing_report'],
        'customer_ledger' => ['customer_ledger.php', 'Customer Ledger', 'fa-book',           'can_customer_ledger'],
        'returns'         => ['returns.php',         'Returns',         'fa-undo',           'can_returns'],
        'over_delivery'   => ['over_delivery.php',   'Over-Delivery',   'fa-truck-loading',  'can_over_delivery'],
     ]],
];

// Filter each stage down to the tools this user is allowed to see
$stages = [];
foreach ($stage_defs as $st) {
    $allowed_tools = array_filter($st['tools'], fn($t) => hub_can($t[3]));
    if (!empty($allowed_tools)) {
        $st['tools'] = $allowed_tools;
        $stages[]    = $st;
    }
}

// Flat route map for the JS router
$route_map = [];
foreach ($stages as $st) {
    foreach ($st['tools'] as $rk => $t) $route_map[$rk] = $t[0];
}

$pipeline = [
    ['label' => 'Pending',       'route' => 'approve_orders',   'count' => $n_pending,   'cls' => 'bg-yellow-100 text-yellow-800 border-yellow-300'],
    ['label' => 'Escalated',     'route' => 'approve_orders',   'count' => $n_escalated, 'cls' => 'bg-red-100 text-red-800 border-red-300'],
    ['label' => 'Approved',      'route' => 'production_queue', 'count' => $n_approved,  'cls' => 'bg-blue-100 text-blue-800 border-blue-300'],
    ['label' => 'In Production', 'route' => 'production_queue', 'count' => $n_inprod,    'cls' => 'bg-purple-100 text-purple-800 border-purple-300'],
    ['label' => 'Produced',      'route' => 'production_queue', 'count' => $n_produced,  'cls' => 'bg-indigo-100 text-indigo-800 border-indigo-300'],
    ['label' => 'Ready',         'route' => 'dispatch_board',   'count' => $n_ready,     'cls' => 'bg-orange-100 text-orange-800 border-orange-300'],
    ['label' => 'Shipped',       'route' => 'dispatch_board',   'count' => $n_shipped,   'cls' => 'bg-teal-100 text-teal-800 border-teal-300'],
];

require_once '../templates/header.php';
?>

<style>
/* SPA shell fills the viewport under the site navbar */
#hubShell{display:flex;height:calc(100vh - 64px);min-height:560px;background:#f3f4f6;}
#hubSide{width:232px;flex-shrink:0;background:#fff;border-right:1px solid #e5e7eb;overflow-y:auto;display:flex;flex-direction:column;}
#hubMain{flex:1;position:relative;overflow:hidden;display:flex;flex-direction:column;}
#hubFrame{border:none;width:100%;flex:1;background:#fff;}
#hubOverview{overflow-y:auto;flex:1;padding:20px;}
.hub-nav-item{display:flex;align-items:center;gap:9px;padding:8px 14px;font-size:12.5px;font-weight:500;color:#4b5563;cursor:pointer;border-left:3px solid transparent;transition:all .15s;}
.hub-nav-item:hover{background:#f9fafb;color:#111827;}
.hub-nav-item.active{background:#eff6ff;color:#1d4ed8;border-left-color:#2563eb;font-weight:700;}
.hub-nav-item i{width:16px;text-align:center;font-size:11px;color:#9ca3af;}
.hub-nav-item.active i{color:#3b82f6;}
.hub-grp{padding:10px 14px 4px;font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#9ca3af;display:flex;align-items:center;justify-content:space-between;}
.hub-grp .b{background:#fee2e2;color:#b91c1c;border-radius:9px;padding:0 7px;font-size:9px;font-weight:800;}
#hubLoad{position:absolute;inset:0;background:rgba(255,255,255,.7);display:none;align-items:center;justify-content:center;z-index:10;}
#hubLoad.on{display:flex;}
#hubCrumb{display:flex;align-items:center;gap:8px;background:#fff;border-bottom:1px solid #e5e7eb;padding:7px 16px;font-size:12px;color:#6b7280;flex-shrink:0;}
</style>

<div id="hubShell">

    <!-- ── Sidebar ─────────────────────────────────────────── -->
    <aside id="hubSide">
        <div class="px-4 py-3 border-b border-gray-100">
            <h1 class="text-sm font-bold text-gray-900"><i class="fas fa-route mr-1.5 text-blue-500"></i>Sales Hub</h1>
            <p class="text-[10px] text-gray-400 mt-0.5">Entire process — one page</p>
        </div>

        <div class="hub-nav-item active" data-route="overview">
            <i class="fas fa-th-large"></i> Overview
        </div>

        <?php foreach ($stages as $st): ?>
        <div class="hub-grp">
            <span><i class="fas <?php echo $st['icon']; ?> mr-1"></i><?php echo $st['label']; ?></span>
            <?php if (!empty($st['badge'])): ?><span class="b"><?php echo $st['badge']; ?></span><?php endif; ?>
        </div>
        <?php foreach ($st['tools'] as $route => $t): ?>
        <div class="hub-nav-item" data-route="<?php echo $route; ?>">
            <i class="fas <?php echo $t[2]; ?>"></i> <?php echo $t[1]; ?>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="mt-auto px-4 py-3 border-t border-gray-100 text-[10px] text-gray-400">
            <i class="fas fa-shield-alt mr-1"></i>Sections controlled in User Privileges
        </div>
    </aside>

    <!-- ── Main viewport ───────────────────────────────────── -->
    <div id="hubMain">
        <div id="hubCrumb">
            <i class="fas fa-map-marker-alt text-gray-300"></i>
            <span id="crumbText">Overview</span>
            <button id="btnReload" title="Reload this tool"
                    class="ml-auto px-2 py-0.5 text-xs text-gray-500 hover:text-blue-600 cursor-pointer">
                <i class="fas fa-rotate-right"></i>
            </button>
            <a id="btnPopout" href="#" target="_blank" title="Open in full page" class="hidden px-2 py-0.5 text-xs text-gray-500 hover:text-blue-600">
                <i class="fas fa-external-link-alt"></i>
            </a>
        </div>

        <div id="hubLoad"><i class="fas fa-circle-notch fa-spin text-3xl text-blue-500"></i></div>

        <!-- Native overview panel -->
        <div id="hubOverview">
            <div class="flex flex-wrap gap-3 mb-5">
                <div class="bg-white rounded-lg border border-gray-200 shadow-sm px-5 py-3">
                    <p class="text-[10px] text-gray-400 uppercase tracking-wide font-semibold">Collected Today</p>
                    <p class="text-xl font-bold text-green-700">৳<?php echo number_format($collected_today, 0); ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 shadow-sm px-5 py-3">
                    <p class="text-[10px] text-gray-400 uppercase tracking-wide font-semibold">Total Receivables</p>
                    <p class="text-xl font-bold text-red-600">৳<?php echo number_format($total_outstanding, 0); ?></p>
                </div>
                <?php if ($n_holds > 0): ?>
                <div class="bg-red-50 rounded-lg border border-red-300 shadow-sm px-5 py-3 cursor-pointer" onclick="go('payment_watch')">
                    <p class="text-[10px] text-red-500 uppercase tracking-wide font-semibold"><i class="fas fa-lock mr-1"></i>Dispatch Held</p>
                    <p class="text-xl font-bold text-red-700"><?php echo $n_holds; ?> orders</p>
                </div>
                <?php endif; ?>
                <?php if (($n_returns_pending + $n_od_pending) > 0): ?>
                <div class="bg-amber-50 rounded-lg border border-amber-300 shadow-sm px-5 py-3 cursor-pointer" onclick="go('returns')">
                    <p class="text-[10px] text-amber-600 uppercase tracking-wide font-semibold">Pending Adjustments</p>
                    <p class="text-xl font-bold text-amber-700"><?php echo $n_returns_pending + $n_od_pending; ?></p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Pipeline strip -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 mb-5 overflow-x-auto">
                <p class="text-[10px] text-gray-400 uppercase tracking-wide font-semibold mb-3">Order Pipeline — click a stage to open its tool</p>
                <div class="flex items-center gap-1 min-w-max">
                    <?php foreach ($pipeline as $i => $st): ?>
                    <?php if ($i > 0): ?><i class="fas fa-chevron-right text-gray-300 text-xs mx-1"></i><?php endif; ?>
                    <div onclick="go('<?php echo $st['route']; ?>')"
                         class="flex flex-col items-center px-4 py-2 rounded-lg border <?php echo $st['cls']; ?> hover:opacity-75 transition-opacity cursor-pointer min-w-[90px]">
                        <span class="text-xl font-bold leading-none"><?php echo $st['count']; ?></span>
                        <span class="text-[10px] font-semibold uppercase tracking-wide mt-1"><?php echo $st['label']; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Stage launchpad -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ($stages as $st): ?>
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <span class="w-8 h-8 rounded-lg bg-<?php echo $st['color']; ?>-100 text-<?php echo $st['color']; ?>-600 flex items-center justify-center text-sm">
                                <i class="fas <?php echo $st['icon']; ?>"></i>
                            </span>
                            <h3 class="font-bold text-gray-900 text-xs"><?php echo $st['label']; ?></h3>
                        </div>
                        <?php if (!empty($st['badge'])): ?>
                        <span class="px-2 py-0.5 rounded-full bg-<?php echo $st['color']; ?>-100 text-<?php echo $st['color']; ?>-700 text-[10px] font-bold"><?php echo $st['badge']; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="p-2">
                        <?php foreach ($st['tools'] as $route => $t): ?>
                        <div onclick="go('<?php echo $route; ?>')"
                             class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs text-gray-700 hover:bg-blue-50 hover:text-blue-700 cursor-pointer group">
                            <i class="fas <?php echo $t[2]; ?> text-gray-300 w-4 group-hover:text-blue-400"></i>
                            <span class="font-medium"><?php echo $t[1]; ?></span>
                            <i class="fas fa-arrow-right text-[9px] text-gray-200 ml-auto group-hover:text-blue-400"></i>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($stages)): ?>
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-12 text-center">
                <i class="fas fa-lock text-5xl text-gray-300 mb-4"></i>
                <h3 class="text-base font-semibold text-gray-600 mb-1">No Sections Enabled</h3>
                <p class="text-sm text-gray-400">Ask an administrator to enable stages in User Privileges → Credit Sales → Sales Hub.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Embedded tool viewport -->
        <iframe id="hubFrame" class="hidden" title="Sales Hub tool"></iframe>
    </div>
</div>

<script>
(function () {
    // route => [file, label]
    const ROUTES = {
        <?php foreach ($stages as $st): foreach ($st['tools'] as $rk => $t): ?>
        '<?php echo $rk; ?>': ['<?php echo $t[0]; ?>', <?php echo json_encode($t[1]); ?>],
        <?php endforeach; endforeach; ?>
    };

    const frame    = document.getElementById('hubFrame');
    const overview = document.getElementById('hubOverview');
    const loader   = document.getElementById('hubLoad');
    const crumb    = document.getElementById('crumbText');
    const popout   = document.getElementById('btnPopout');
    let   current  = 'overview';

    // ── Router ────────────────────────────────────────────────
    window.go = function (route) { location.hash = '#/' + route; };

    function apply(route) {
        if (!ROUTES[route]) route = 'overview';
        current = route;

        document.querySelectorAll('.hub-nav-item').forEach(el =>
            el.classList.toggle('active', el.dataset.route === route));

        if (route === 'overview') {
            frame.classList.add('hidden');
            overview.classList.remove('hidden');
            loader.classList.remove('on');
            crumb.textContent = 'Overview';
            popout.classList.add('hidden');
            return;
        }

        const [file, label] = ROUTES[route];
        crumb.textContent = label;
        popout.href = file;
        popout.classList.remove('hidden');
        overview.classList.add('hidden');
        frame.classList.remove('hidden');

        // Only reload the frame if it isn't already on this tool
        const target = new URL(file, location.href).href;
        if (!frame.src || frame.src.indexOf(target.split('?')[0]) !== 0) {
            loader.classList.add('on');
            frame.src = target;
        }
    }

    window.addEventListener('hashchange', () =>
        apply(location.hash.replace(/^#\//, '') || 'overview'));

    document.querySelectorAll('.hub-nav-item').forEach(el =>
        el.addEventListener('click', () => go(el.dataset.route)));

    document.getElementById('btnReload').addEventListener('click', () => {
        if (current === 'overview') { location.reload(); return; }
        loader.classList.add('on');
        try { frame.contentWindow.location.reload(); } catch (e) { frame.src = frame.src; }
    });

    // ── Chrome-stripper: hide the site navbar/footer inside the embedded
    //    page so tools render natively in the SPA viewport. Same-origin,
    //    re-applied on every navigation inside the frame (form posts,
    //    redirects, in-tool links all stay inside the hub). ──────────────
    frame.addEventListener('load', function () {
        loader.classList.remove('on');
        try {
            const doc = frame.contentDocument;
            if (!doc) return;
            if (!doc.getElementById('hubEmbedCss')) {
                const s = doc.createElement('style');
                s.id = 'hubEmbedCss';
                s.textContent =
                    'nav{display:none !important}' +
                    'footer{display:none !important}' +
                    'body{padding-top:0 !important}';
                (doc.head || doc.documentElement).appendChild(s);
            }
            // Sync sidebar highlight when user navigates between tools inside the frame
            const path = (frame.contentWindow.location.pathname || '').split('/').pop();
            for (const [rk, def] of Object.entries(ROUTES)) {
                if (def[0].split('?')[0] === path && rk !== current) {
                    current = rk;
                    history.replaceState(null, '', '#/' + rk);
                    document.querySelectorAll('.hub-nav-item').forEach(el =>
                        el.classList.toggle('active', el.dataset.route === rk));
                    crumb.textContent = def[1];
                    popout.href = def[0];
                    break;
                }
            }
        } catch (e) { /* cross-origin or detached — ignore */ }
    });

    // Boot from URL hash (deep-linkable: sales_hub.php#/production_queue)
    apply(location.hash.replace(/^#\//, '') || 'overview');
})();
</script>

<?php require_once '../templates/footer.php'; ?>
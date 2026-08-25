<?php
/**
 * POS Pending Approvals (Jul 2026) — checker queue for the two POS maker/checker
 * flows: over-limit credit sales (admin-only, standalone customer-credit-limit
 * gate — see ajax_handler.php's docblock) and exit-release approvals for
 * unpaid/partial sales (delegated ৳ limit via pos_exit_release, same model as
 * commodity_sale/loan_disbursement). Deliberately its own page rather than
 * cr/approval_requests.php — that page's queries/authority checks are shaped
 * around credit_orders and Credit Sales' limit types, which don't fit POS.
 */
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'accountspos-demra', 'accountspos-srg', 'dispatchpos-demra', 'dispatchpos-srg', 'security', 'gate'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id     = (int)($currentUser['id'] ?? 0);
$is_admin    = in_array($currentUser['role'] ?? '', ['Superadmin', 'admin'], true);
$pageTitle   = 'POS Pending Approvals';

ensurePendingRequestsTable();

$my_exit_cap = $is_admin ? null : getUserActionLimit($user_id, 'pos_exit_release');

function pos_req_covers(object $r, bool $is_admin, ?float $exit_cap): bool {
    if ($is_admin) return true;
    if ($r->request_type === 'pos_credit_sale') return false; // admin-only, by design
    if ($r->request_type === 'pos_exit_release') return $exit_cap === null ? false : (float)$r->amount <= $exit_cap;
    return false;
}

/* ─── POST: approve / reject ────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['req_action'])) {
    $req_id = (int)($_POST['request_id'] ?? 0);
    try {
        $req = $db->query("SELECT * FROM cr_pending_requests WHERE id = ?", [$req_id])->first();
        if (!$req) throw new Exception('Request not found.');
        if ($req->status !== 'pending') throw new Exception('Request #' . $req_id . ' has already been decided.');
        if (!in_array($req->request_type, ['pos_credit_sale', 'pos_exit_release'], true)) throw new Exception('Not a POS request.');

        if ($_POST['req_action'] === 'reject') {
            $reason = trim($_POST['reject_reason'] ?? '');
            if ($reason === '') throw new Exception('A reason is required to reject a request.');
            if (!pos_req_covers($req, $is_admin, $my_exit_cap)) throw new Exception('You are not authorized to decide this request.');
            if (!decidePendingRequest($req_id, 'rejected', $reason)) {
                throw new Exception('Could not reject — the request may have just been decided by someone else.');
            }
            auditLog('pos', 'other', "POS request #{$req_id} ({$req->request_type} ৳" . number_format((float)$req->amount, 2) . ") REJECTED by "
                . ($currentUser['display_name'] ?? 'user') . " — {$reason}");
            $_SESSION['success_flash'] = "Request #{$req_id} rejected.";

        } elseif ($_POST['req_action'] === 'approve_exit_release') {
            if ($req->request_type !== 'pos_exit_release') throw new Exception('Not an exit-release request.');
            if (!pos_req_covers($req, $is_admin, $my_exit_cap)) throw new Exception('This amount is above your POS Exit Release limit.');
            if (!decidePendingRequest($req_id, 'approved', 'Approved by ' . ($currentUser['display_name'] ?? 'user'))) {
                throw new Exception('Could not approve — the request may have just been decided by someone else.');
            }
            $payload = json_decode($req->payload ?? '{}', true) ?: [];
            auditLog('pos', 'other', "POS exit release #{$req_id} for order " . ($payload['order_number'] ?? '')
                . " (৳" . number_format((float)$req->amount, 2) . ") APPROVED by " . ($currentUser['display_name'] ?? 'user'));
            $_SESSION['success_flash'] = "Request #{$req_id} approved. The gate can now clear it for exit.";

        } elseif ($_POST['req_action'] === 'cancel') {
            if ((int)$req->maker_user_id !== $user_id && !$is_admin) {
                throw new Exception('Only the person who submitted this request (or an admin) can cancel it.');
            }
            if (!decidePendingRequest($req_id, 'cancelled', 'Withdrawn by ' . ($currentUser['display_name'] ?? 'maker'))) {
                throw new Exception('Could not cancel — the request may have just been decided.');
            }
            $_SESSION['success_flash'] = "Request #{$req_id} cancelled.";
        }
    } catch (Exception $e) {
        $_SESSION['error_flash'] = $e->getMessage();
    }
    header('Location: pending_approvals.php');
    exit();
}

/* ─── Load requests ─────────────────────────────────────────── */
$pending = $db->query(
    "SELECT r.*, c.name AS customer_name, mu.display_name AS maker_name
     FROM cr_pending_requests r
     LEFT JOIN customers c ON c.id = r.customer_id
     LEFT JOIN users mu ON mu.id = r.maker_user_id
     WHERE r.status = 'pending' AND r.request_type IN ('pos_credit_sale', 'pos_exit_release')
     ORDER BY r.created_at ASC"
)->results();

$decided = $db->query(
    "SELECT r.*, c.name AS customer_name, mu.display_name AS maker_name, cu.display_name AS checker_name
     FROM cr_pending_requests r
     LEFT JOIN customers c ON c.id = r.customer_id
     LEFT JOIN users mu ON mu.id = r.maker_user_id
     LEFT JOIN users cu ON cu.id = r.checker_user_id
     WHERE r.status != 'pending' AND r.request_type IN ('pos_credit_sale', 'pos_exit_release')
       AND r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     ORDER BY r.decided_at DESC
     LIMIT 50"
)->results();

require_once '../templates/header.php';

$status_badge = [
    'approved'  => 'bg-green-100 text-green-700',
    'rejected'  => 'bg-red-100 text-red-700',
    'cancelled' => 'bg-gray-100 text-gray-500',
];
?>
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">POS Pending Approvals</h1>
            <p class="text-sm text-gray-500 mt-1">Over-limit credit sales and unpaid-goods exit releases.</p>
        </div>
        <a href="dashboard.php" class="text-sm text-blue-600 hover:text-blue-800"><i class="fas fa-arrow-left mr-1"></i>Back to Dashboard</a>
    </div>

    <?php if (!empty($_SESSION['success_flash'])): ?>
    <div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm"><?php echo htmlspecialchars($_SESSION['success_flash']); unset($_SESSION['success_flash']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error_flash'])): ?>
    <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm"><?php echo htmlspecialchars($_SESSION['error_flash']); unset($_SESSION['error_flash']); ?></div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-md overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="text-lg font-bold text-gray-800"><i class="fas fa-clock mr-2 text-amber-500"></i>Pending (<?php echo count($pending); ?>)</h2>
        </div>
        <?php if (empty($pending)): ?>
        <div class="p-8 text-center text-gray-400 text-sm">Nothing waiting on approval.</div>
        <?php else: ?>
        <div class="divide-y divide-gray-100">
            <?php foreach ($pending as $r):
                $is_exit = $r->request_type === 'pos_exit_release';
                $covers = pos_req_covers($r, $is_admin, $my_exit_cap);
                $is_mine = (int)$r->maker_user_id === $user_id;
                $payload = json_decode($r->payload ?? '{}', true) ?: [];
                $order_number = $payload['order_number'] ?? '';
                $type_label = $is_exit ? 'Exit Release' : 'POS Credit Sale';
                $type_color = $is_exit ? 'purple' : 'indigo';
                $age_hrs = max(0, (time() - strtotime($r->created_at)) / 3600);
            ?>
            <div class="px-6 py-4 flex flex-wrap items-start gap-4">
                <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0 bg-<?php echo $type_color; ?>-100">
                    <i class="fas <?php echo $is_exit ? 'fa-dolly' : 'fa-cash-register'; ?> text-<?php echo $type_color; ?>-600"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-gray-900 text-sm">
                        #<?php echo (int)$r->id; ?> — <?php echo $type_label; ?>
                        <span class="font-bold text-<?php echo $type_color; ?>-700">৳<?php echo number_format((float)$r->amount, 2); ?></span>
                        <?php if ($r->customer_name): ?>· <?php echo htmlspecialchars($r->customer_name); ?><?php endif; ?>
                        <?php if ($order_number): ?>· <span class="font-mono text-xs"><?php echo htmlspecialchars($order_number); ?></span><?php endif; ?>
                    </p>
                    <p class="text-xs text-gray-500 mt-0.5"><?php echo htmlspecialchars($r->summary ?? ''); ?></p>
                    <p class="text-[11px] text-gray-400 mt-1">
                        By <strong><?php echo htmlspecialchars($r->maker_name ?? 'staff'); ?></strong>
                        · <?php echo date('d M Y, g:i A', strtotime($r->created_at)); ?>
                        · <?php echo $age_hrs < 1 ? 'just now' : ($age_hrs < 24 ? floor($age_hrs) . 'h ago' : floor($age_hrs / 24) . 'd ago'); ?>
                    </p>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <?php if ($covers && !$is_mine && $is_exit): ?>
                    <form method="POST" onsubmit="return confirm('Approve release of ৳<?php echo number_format((float)$r->amount, 0); ?> for <?php echo htmlspecialchars($order_number); ?>?');">
                        <input type="hidden" name="req_action" value="approve_exit_release">
                        <input type="hidden" name="request_id" value="<?php echo (int)$r->id; ?>">
                        <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg text-xs font-bold hover:bg-purple-700 cursor-pointer">
                            <i class="fas fa-check mr-1"></i>Approve
                        </button>
                    </form>
                    <?php elseif ($covers && !$is_mine && !$is_exit): ?>
                    <span class="px-3 py-2 bg-gray-50 text-gray-400 rounded-lg text-[11px]">Post via POS Terminal → retry the sale</span>
                    <?php elseif ($is_mine): ?>
                    <span class="px-3 py-2 bg-gray-50 text-gray-400 rounded-lg text-[11px]">Your own request</span>
                    <?php else: ?>
                    <span class="px-3 py-2 bg-gray-50 text-gray-400 rounded-lg text-[11px]" title="<?php echo $is_exit ? 'Amount is above your POS Exit Release limit' : 'Admin-only'; ?>">
                        <?php echo $is_exit ? 'Above your limit' : 'Admin only'; ?>
                    </span>
                    <?php endif; ?>

                    <?php if (($covers && !$is_mine) || $is_admin): ?>
                    <details class="relative">
                        <summary class="list-none px-3 py-2 border-2 border-red-400 text-red-600 rounded-lg text-xs font-bold hover:bg-red-50 cursor-pointer">
                            <i class="fas fa-ban mr-1"></i>Reject
                        </summary>
                        <form method="POST" class="absolute right-0 mt-1 z-20 w-64 p-3 bg-white border border-gray-200 rounded-xl shadow-xl space-y-2">
                            <input type="hidden" name="req_action" value="reject">
                            <input type="hidden" name="request_id" value="<?php echo (int)$r->id; ?>">
                            <input type="text" name="reject_reason" required maxlength="500" placeholder="Reason (required)…"
                                   class="w-full px-2 py-1.5 border rounded-lg text-xs">
                            <button type="submit" class="w-full px-3 py-1.5 bg-red-600 text-white rounded-lg text-xs font-bold hover:bg-red-700 cursor-pointer">
                                Confirm Reject
                            </button>
                        </form>
                    </details>
                    <?php endif; ?>

                    <?php if ($is_mine): ?>
                    <form method="POST" onsubmit="return confirm('Withdraw request #<?php echo (int)$r->id; ?>?');">
                        <input type="hidden" name="req_action" value="cancel">
                        <input type="hidden" name="request_id" value="<?php echo (int)$r->id; ?>">
                        <button type="submit" class="px-3 py-2 border border-gray-300 text-gray-600 rounded-lg text-xs hover:bg-gray-50 cursor-pointer">
                            Withdraw
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$is_admin): ?>
    <div class="mb-6 text-xs bg-blue-50 border border-blue-200 rounded-lg p-3 text-blue-800">
        <i class="fas fa-info-circle mr-1"></i>Over-limit POS credit sales can only be approved by an admin — reusing the same rule as when the sale itself was placed.
        Your POS Exit Release limit: <strong><?php echo $my_exit_cap === null ? 'not configured (cannot approve releases)' : '৳' . number_format($my_exit_cap, 2); ?></strong>.
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-md overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="text-lg font-bold text-gray-800"><i class="fas fa-history mr-2 text-gray-400"></i>Recently Decided</h2>
        </div>
        <?php if (empty($decided)): ?>
        <div class="p-8 text-center text-gray-400 text-sm">No decided requests in the last 30 days.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Maker</th>
                        <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Decided By</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Note</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($decided as $r): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-gray-500"><?php echo (int)$r->id; ?></td>
                        <td class="px-4 py-2"><?php echo $r->request_type === 'pos_exit_release' ? 'Exit Release' : 'POS Credit Sale'; ?></td>
                        <td class="px-4 py-2 text-right font-semibold">৳<?php echo number_format((float)$r->amount, 2); ?></td>
                        <td class="px-4 py-2 text-xs"><?php echo htmlspecialchars($r->maker_name ?? ''); ?></td>
                        <td class="px-4 py-2 text-center">
                            <span class="px-2 py-1 rounded-full text-xs font-bold <?php echo $status_badge[$r->status] ?? ''; ?>"><?php echo ucfirst($r->status); ?></span>
                        </td>
                        <td class="px-4 py-2 text-xs"><?php echo htmlspecialchars($r->checker_name ?? ''); ?></td>
                        <td class="px-4 py-2 text-xs text-gray-500"><?php echo htmlspecialchars($r->checker_note ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once '../templates/footer.php'; ?>

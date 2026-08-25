<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra', 'collector',
                  'production manager-srg', 'production manager-demra',
                  'dispatch-srg', 'dispatch-demra', 'dispatchpos-srg', 'dispatchpos-demra'];
restrict_access($allowed_roles, 'credit_sales', 'approval_requests');

global $db;
$currentUser = getCurrentUser();
$user_id   = (int)($currentUser['id'] ?? 0);
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Approval Requests';

$is_admin = in_array($user_role, ['Superadmin', 'admin']);

ensurePendingRequestsTable();
ensureCommoditySaleEditsTable();

// Checker authority per request type: admin, or a per-action limit that covers
// the amount (null limit = no cap = full authority). Same delegation model as
// approve/amend/early-release everywhere else in the module.
$my_collect_cap        = $is_admin ? null : getUserActionLimit($user_id, 'collect_payment');
$my_delivery_cap       = $is_admin ? null : getUserActionLimit($user_id, 'partial_delivery');
$my_commodity_sale_cap = $is_admin ? null : getUserActionLimit($user_id, 'commodity_sale');
$my_loan_disbursement_cap = $is_admin ? null : getUserActionLimit($user_id, 'loan_disbursement');
$can_reject      = $is_admin || userCanPageAction('credit_sales', 'approval_requests', 'can_decide');

function req_checker_covers(object $r, bool $is_admin, ?float $collect_cap, ?float $delivery_cap, ?float $commodity_sale_cap = null, ?float $loan_disbursement_cap = null): bool {
    if ($is_admin) return true;
    // Advances, commodity-sale payments, and loan repayments are all receipts
    // too → gated by the same collect_payment cap as ordinary payments
    // (deliberately not a separate cap — see collect_commodity_payment.php's
    // docblock on why).
    if (in_array($r->request_type, ['payment', 'advance', 'commodity_payment', 'loan_repayment'])) {
        $cap = $collect_cap;
    } elseif (in_array($r->request_type, ['commodity_sale', 'commodity_sale_edit'])) {
        // Edits reuse the same commodity_sale cap — correcting a sale is the same
        // financial risk as posting one (see edit_commodity_sale.php's docblock).
        $cap = $commodity_sale_cap;
    } elseif ($r->request_type === 'loan_disbursement') {
        $cap = $loan_disbursement_cap;
    } else {
        $cap = $delivery_cap;
    }
    // A user with NO cap set has unlimited authority for that action only if they
    // can even reach the underlying page; the review link enforces that there.
    return $cap === null || (float)$r->amount <= $cap;
}

/* ─── POST: reject / cancel ─────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['req_action'])) {
    $req_id = (int)($_POST['request_id'] ?? 0);
    try {
        $req = $db->query("SELECT * FROM cr_pending_requests WHERE id = ?", [$req_id])->first();
        if (!$req) throw new Exception('Request not found.');
        if ($req->status !== 'pending') throw new Exception('Request #' . $req_id . ' has already been decided.');

        if ($_POST['req_action'] === 'reject') {
            $reason = trim($_POST['reject_reason'] ?? '');
            if ($reason === '') throw new Exception('A reason is required to reject a request.');
            if (!$can_reject && !req_checker_covers($req, $is_admin, $my_collect_cap, $my_delivery_cap, $my_commodity_sale_cap, $my_loan_disbursement_cap)) {
                throw new Exception('You are not authorized to decide this request.');
            }
            if (!decidePendingRequest($req_id, 'rejected', $reason)) {
                throw new Exception('Could not reject — the request may have just been decided by someone else.');
            }
            if ($req->request_type === 'commodity_sale_edit') {
                $payload = json_decode($req->payload ?? '{}', true) ?: [];
                $edit_row_id = (int)($payload['edit_row_id'] ?? 0);
                if ($edit_row_id) {
                    $db->query(
                        "UPDATE commodity_sale_edits SET status = 'rejected', decided_by_user_id = ?, decided_at = NOW() WHERE id = ?",
                        [$user_id, $edit_row_id]
                    );
                }
            }
            auditLog('cr_pending_requests', 'rejected',
                "Request #{$req_id} ({$req->request_type} ৳" . number_format((float)$req->amount, 2) . ") REJECTED by "
                . ($currentUser['display_name'] ?? 'user') . " — {$reason}",
                ['request_id' => $req_id]);
            $_SESSION['success_flash'] = "Request #{$req_id} rejected.";

        } elseif ($_POST['req_action'] === 'cancel') {
            // Makers may withdraw their own pending request
            if ((int)$req->maker_user_id !== $user_id && !$is_admin) {
                throw new Exception('Only the person who submitted this request (or an admin) can cancel it.');
            }
            if (!decidePendingRequest($req_id, 'cancelled', 'Withdrawn by ' . ($currentUser['display_name'] ?? 'maker'))) {
                throw new Exception('Could not cancel — the request may have just been decided.');
            }
            auditLog('cr_pending_requests', 'cancelled',
                "Request #{$req_id} ({$req->request_type} ৳" . number_format((float)$req->amount, 2) . ") withdrawn by "
                . ($currentUser['display_name'] ?? 'user'),
                ['request_id' => $req_id]);
            $_SESSION['success_flash'] = "Request #{$req_id} cancelled.";
        }
    } catch (Exception $e) {
        $_SESSION['error_flash'] = $e->getMessage();
    }
    header('Location: approval_requests.php');
    exit();
}

/* ─── Load requests ─────────────────────────────────────────── */
$pending = $db->query(
    "SELECT r.*, c.name AS customer_name, co.order_number,
            mu.display_name AS maker_name
     FROM cr_pending_requests r
     LEFT JOIN customers c  ON c.id  = r.customer_id
     LEFT JOIN credit_orders co ON co.id = r.order_id
     LEFT JOIN users mu ON mu.id = r.maker_user_id
     WHERE r.status = 'pending'
     ORDER BY r.created_at ASC"
)->results();

$decided = $db->query(
    "SELECT r.*, c.name AS customer_name, co.order_number,
            mu.display_name AS maker_name, cu.display_name AS checker_name
     FROM cr_pending_requests r
     LEFT JOIN customers c  ON c.id  = r.customer_id
     LEFT JOIN credit_orders co ON co.id = r.order_id
     LEFT JOIN users mu ON mu.id = r.maker_user_id
     LEFT JOIN users cu ON cu.id = r.checker_user_id
     WHERE r.status != 'pending' AND r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     ORDER BY r.decided_at DESC
     LIMIT 50"
)->results();

require_once '../templates/header.php';

$status_badge = [
    'approved'  => 'bg-green-100 text-green-700',
    'rejected'  => 'bg-red-100 text-red-700',
    'cancelled' => 'bg-gray-100 text-gray-600',
];
?>

<div class="max-w-screen-xl mx-auto px-4 sm:px-6 py-6">

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">
            <i class="fas fa-user-check text-purple-600 mr-2"></i>Approval Requests
        </h1>
        <p class="text-sm text-gray-500 mt-1">
            Payments and deliveries above a staff member's limit wait here. Nothing posts to the
            ledger until a senior officer reviews the prefilled form and posts it under their own authority.
        </p>
    </div>
    <span class="px-3 py-1.5 rounded-full text-sm font-bold <?php echo count($pending) ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-700'; ?>">
        <?php echo count($pending); ?> pending
    </span>
</div>

<?php if (!empty($_SESSION['error_flash'])): ?>
<div class="mb-4 p-4 bg-red-50 border border-red-300 rounded-lg text-red-800 text-sm">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['error_flash']); unset($_SESSION['error_flash']); ?>
</div>
<?php endif; ?>
<?php if (!empty($_SESSION['success_flash'])): ?>
<div class="mb-4 p-4 bg-green-50 border border-green-300 rounded-lg text-green-800 text-sm">
    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['success_flash']); unset($_SESSION['success_flash']); ?>
</div>
<?php endif; ?>

<!-- Pending -->
<div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
    <div class="px-6 py-4 border-b border-gray-100">
        <h2 class="text-lg font-bold text-gray-800"><i class="fas fa-hourglass-half mr-2 text-amber-500"></i>Waiting for Approval</h2>
    </div>

    <?php if (empty($pending)): ?>
    <div class="p-10 text-center text-gray-400">
        <i class="fas fa-check-double text-4xl mb-3 opacity-30"></i>
        <p class="text-sm">No pending requests — everything is settled.</p>
    </div>
    <?php else: ?>
    <div class="divide-y divide-gray-100">
        <?php foreach ($pending as $r):
            $covers = req_checker_covers($r, $is_admin, $my_collect_cap, $my_delivery_cap, $my_commodity_sale_cap, $my_loan_disbursement_cap);
            $is_mine = (int)$r->maker_user_id === $user_id;
            $pay_like = in_array($r->request_type, ['payment', 'advance']);
            $is_commodity = $r->request_type === 'commodity_sale';
            $is_commodity_payment = $r->request_type === 'commodity_payment';
            $is_commodity_edit = $r->request_type === 'commodity_sale_edit';
            $is_commodity_any = $is_commodity || $is_commodity_payment || $is_commodity_edit;
            $is_loan_disbursement = $r->request_type === 'loan_disbursement';
            $is_loan_repayment = $r->request_type === 'loan_repayment';
            $is_loan_any = $is_loan_disbursement || $is_loan_repayment;
            if ($r->request_type === 'advance') {
                $review_url = 'advance_payment_collection.php?pending_req=' . (int)$r->id;
            } elseif ($r->request_type === 'payment') {
                $review_url = 'customer_payment.php?pending_req=' . (int)$r->id;
            } elseif ($is_commodity) {
                $review_url = '../trading/commodity_sale.php?pending_req=' . (int)$r->id;
            } elseif ($is_commodity_payment) {
                $review_url = '../trading/collect_commodity_payment.php?pending_req=' . (int)$r->id;
            } elseif ($is_commodity_edit) {
                $edit_payload = json_decode($r->payload ?? '{}', true) ?: [];
                $edit_row = $db->query("SELECT old_sale_id FROM commodity_sale_edits WHERE id = ?", [(int)($edit_payload['edit_row_id'] ?? 0)])->first();
                $review_url = '../trading/edit_commodity_sale.php?id=' . (int)($edit_row->old_sale_id ?? 0) . '&pending_req=' . (int)$r->id;
            } elseif ($is_loan_disbursement) {
                $review_url = '../loans/loan.php?pending_req=' . (int)$r->id;
            } elseif ($is_loan_repayment) {
                $review_url = '../loans/repay_loan.php?pending_req=' . (int)$r->id;
            } else {
                $review_url = 'partial_delivery.php?order_id=' . (int)$r->order_id . '&pending_req=' . (int)$r->id;
            }
            $type_label = $r->request_type === 'advance' ? 'Advance' : ($r->request_type === 'payment' ? 'Payment' : ($is_commodity ? 'Commodity Sale' : ($is_commodity_payment ? 'Commodity Payment' : ($is_commodity_edit ? 'Commodity Sale Edit' : ($is_loan_disbursement ? 'Loan Disbursement' : ($is_loan_repayment ? 'Loan Repayment' : 'Delivery'))))));
            $type_color = $is_commodity_any ? 'rose' : ($is_loan_any ? 'amber' : ($pay_like ? 'green' : 'indigo'));
            $icon_bg    = $is_commodity_any ? 'bg-rose-100' : ($is_loan_any ? 'bg-amber-100' : ($pay_like ? 'bg-green-100' : 'bg-indigo-100'));
            $icon_cls   = $is_commodity ? 'fa-money-bill-transfer text-rose-600' : ($is_commodity_payment ? 'fa-hand-holding-dollar text-rose-600' : ($is_commodity_edit ? 'fa-pen text-rose-600' : ($is_loan_disbursement ? 'fa-money-bill-transfer text-amber-600' : ($is_loan_repayment ? 'fa-hand-holding-dollar text-amber-600' : ($pay_like ? 'fa-money-bill-wave text-green-600' : 'fa-dolly text-indigo-600')))));
            $age_hrs = max(0, (time() - strtotime($r->created_at)) / 3600);
        ?>
        <div class="px-6 py-4 flex flex-wrap items-start gap-4">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0 <?php echo $icon_bg; ?>">
                <i class="fas <?php echo $icon_cls; ?>"></i>
            </div>

            <div class="flex-1 min-w-0">
                <p class="font-semibold text-gray-900 text-sm">
                    #<?php echo (int)$r->id; ?> —
                    <?php echo $type_label; ?>
                    <span class="font-bold text-<?php echo $type_color; ?>-700">
                        ৳<?php echo number_format((float)$r->amount, 2); ?>
                    </span>
                    <?php if ($r->customer_name): ?>· <?php echo htmlspecialchars($r->customer_name); ?><?php endif; ?>
                    <?php if ($r->order_number): ?>· <span class="font-mono text-xs"><?php echo htmlspecialchars($r->order_number); ?></span><?php endif; ?>
                </p>
                <p class="text-xs text-gray-500 mt-0.5"><?php echo htmlspecialchars($r->summary ?? ''); ?></p>
                <p class="text-[11px] text-gray-400 mt-1">
                    By <strong><?php echo htmlspecialchars($r->maker_name ?? 'staff'); ?></strong>
                    (limit ৳<?php echo number_format((float)($r->maker_limit ?? 0), 0); ?>)
                    · <?php echo date('d M Y, g:i A', strtotime($r->created_at)); ?>
                    · <?php echo $age_hrs < 1 ? 'just now' : ($age_hrs < 24 ? floor($age_hrs) . 'h ago' : floor($age_hrs / 24) . 'd ago'); ?>
                </p>
            </div>

            <div class="flex items-center gap-2 flex-shrink-0">
                <?php if ($covers && !$is_mine): ?>
                <a href="<?php echo $review_url; ?>"
                   class="px-4 py-2 bg-purple-600 text-white rounded-lg text-xs font-bold hover:bg-purple-700 cursor-pointer">
                    <i class="fas fa-search-dollar mr-1"></i>Review &amp; Post
                </a>
                <?php elseif ($is_mine): ?>
                <span class="px-3 py-2 bg-gray-50 text-gray-400 rounded-lg text-[11px]">Your own request</span>
                <?php else: ?>
                <span class="px-3 py-2 bg-gray-50 text-gray-400 rounded-lg text-[11px]"
                      title="Amount is above your own limit for this action">Above your limit</span>
                <?php endif; ?>

                <?php if (($covers && !$is_mine) || $can_reject): ?>
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

<!-- Decided (last 30 days) -->
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
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer / Order</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Maker</th>
                    <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Decided By</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ref / Note</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($decided as $r): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 text-gray-500"><?php echo (int)$r->id; ?></td>
                    <td class="px-4 py-2"><?php echo $r->request_type === 'advance' ? 'Advance' : ($r->request_type === 'payment' ? 'Payment' : ($r->request_type === 'commodity_sale' ? 'Commodity Sale' : ($r->request_type === 'commodity_payment' ? 'Commodity Payment' : ($r->request_type === 'commodity_sale_edit' ? 'Commodity Sale Edit' : ($r->request_type === 'loan_disbursement' ? 'Loan Disbursement' : ($r->request_type === 'loan_repayment' ? 'Loan Repayment' : 'Delivery')))))); ?></td>
                    <td class="px-4 py-2 text-right font-semibold">৳<?php echo number_format((float)$r->amount, 2); ?></td>
                    <td class="px-4 py-2 text-xs">
                        <?php echo htmlspecialchars($r->customer_name ?? ''); ?>
                        <?php if ($r->order_number): ?><span class="font-mono text-gray-400"><?php echo htmlspecialchars($r->order_number); ?></span><?php endif; ?>
                    </td>
                    <td class="px-4 py-2 text-xs"><?php echo htmlspecialchars($r->maker_name ?? ''); ?></td>
                    <td class="px-4 py-2 text-center">
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-bold <?php echo $status_badge[$r->status] ?? 'bg-gray-100 text-gray-600'; ?>">
                            <?php echo strtoupper($r->status); ?>
                        </span>
                    </td>
                    <td class="px-4 py-2 text-xs"><?php echo htmlspecialchars($r->checker_name ?? ''); ?>
                        <?php if ($r->decided_at): ?><span class="text-gray-400"><?php echo date('d M, g:i A', strtotime($r->decided_at)); ?></span><?php endif; ?>
                    </td>
                    <td class="px-4 py-2 text-xs text-gray-500">
                        <?php if ($r->executed_ref): ?><span class="font-mono text-green-700"><?php echo htmlspecialchars($r->executed_ref); ?></span><?php endif; ?>
                        <?php if ($r->checker_note): ?><?php echo htmlspecialchars($r->checker_note); ?><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div>

<?php require_once '../templates/footer.php'; ?>

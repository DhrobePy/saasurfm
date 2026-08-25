<?php
/**
 * AI Query Access — manage who's allowed to use /ask in the dedicated
 * "AI Query" Telegram group (api/telegram_ai_webhook.php). The group being
 * invite-only is one layer; this allow-list (by Telegram numeric user id) is
 * the explicit second layer, per the "admin/superadmin/allowed persons only"
 * requirement — flat list, not tied to ERP roles, since Telegram identity and
 * ERP identity are separate namespaces with no existing mapping.
 *
 * Onboarding a new person: they send /whoami in the group to get their own
 * Telegram id, then an admin adds it here.
 */
require_once dirname(__DIR__) . '/core/init.php';

restrict_access(['Superadmin', 'admin'], 'admin', 'telegram_ai_users');

global $db;
$currentUser = getCurrentUser();
$pageTitle   = 'AI Query Access';

ensureTelegramAiAuthorizedUsersTable();
ensureTelegramAiQueryLogTable();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($csrf) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        $_SESSION['error_flash'] = 'Invalid CSRF token — please try again.';
        header('Location: telegram_ai_users.php'); exit;
    }

    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'add') {
        $telegram_user_id = (int)($_POST['telegram_user_id'] ?? 0);
        $telegram_username = trim($_POST['telegram_username'] ?? '');
        $label = trim($_POST['label'] ?? '');
        if ($telegram_user_id <= 0) {
            $_SESSION['error_flash'] = 'Enter a valid numeric Telegram ID (get it via /whoami in the group).';
        } else {
            $existing = $db->query("SELECT id FROM telegram_ai_authorized_users WHERE telegram_user_id = ?", [$telegram_user_id])->first();
            if ($existing) {
                $_SESSION['error_flash'] = 'That Telegram ID is already authorized.';
            } elseif (!$db->insert('telegram_ai_authorized_users', [
                'telegram_user_id' => $telegram_user_id,
                'telegram_username' => $telegram_username !== '' ? ltrim($telegram_username, '@') : null,
                'label' => $label !== '' ? $label : null,
                'added_by_user_id' => $currentUser['id'] ?? null,
            ])) {
                $_SESSION['error_flash'] = 'Failed to add this Telegram user.';
            } else {
                $_SESSION['success_flash'] = 'Telegram user authorized for /ask.';
            }
        }
    } elseif ($post_action === 'remove') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && $db->delete('telegram_ai_authorized_users', ['id' => $id])) {
            $_SESSION['success_flash'] = 'Access revoked.';
        } else {
            $_SESSION['error_flash'] = 'Failed to revoke access.';
        }
    }

    header('Location: telegram_ai_users.php');
    exit;
}

$authorized = $db->query(
    "SELECT tau.*, u.display_name AS added_by_name
     FROM telegram_ai_authorized_users tau
     LEFT JOIN users u ON tau.added_by_user_id = u.id
     ORDER BY tau.created_at DESC"
)->results();

$recent_queries = $db->query(
    "SELECT * FROM telegram_ai_query_log ORDER BY id DESC LIMIT 30"
)->results();

$configured = defined('TELEGRAM_CHAT_ID_AI_QUERY') && TELEGRAM_CHAT_ID_AI_QUERY !== '';

require_once '../templates/header.php';
?>
<div class="max-w-screen-xl mx-auto px-4 sm:px-6 py-6">

    <div class="mb-4">
        <h1 class="text-2xl font-bold text-gray-900"><i class="fab fa-telegram text-blue-500 mr-2"></i><?php echo htmlspecialchars($pageTitle); ?></h1>
        <p class="text-gray-600 mt-1 text-sm">Who can ask <code>/ask &lt;question&gt;</code> in the dedicated AI Query Telegram group and get answers straight from the database.</p>
    </div>

    <?php if (!empty($_SESSION['success_flash'])): ?>
    <div class="mb-4 px-4 py-2.5 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm"><i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars($_SESSION['success_flash']); unset($_SESSION['success_flash']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error_flash'])): ?>
    <div class="mb-4 px-4 py-2.5 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"><i class="fas fa-triangle-exclamation mr-1"></i><?php echo htmlspecialchars($_SESSION['error_flash']); unset($_SESSION['error_flash']); ?></div>
    <?php endif; ?>

    <?php if (!$configured): ?>
    <div class="mb-4 px-4 py-2.5 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-sm">
        <i class="fas fa-circle-info mr-1"></i><code>TELEGRAM_CHAT_ID_AI_QUERY</code> isn't set yet in config.php — the group exists but /ask won't respond until it's configured and the webhook is registered with Telegram.
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6">
        <h2 class="font-semibold text-gray-800 text-sm mb-3"><i class="fas fa-user-plus text-blue-500 mr-1"></i>Authorize a Telegram User</h2>
        <p class="text-xs text-gray-500 mb-3">Ask the person to send <code>/whoami</code> in the AI Query group — it replies with their Telegram ID, no access needed to run it.</p>
        <form method="POST" class="flex flex-wrap items-end gap-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" name="action" value="add">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Telegram ID *</label>
                <input type="number" name="telegram_user_id" required placeholder="e.g. 123456789" class="px-3 py-1.5 border rounded-lg text-sm w-44">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Username</label>
                <input type="text" name="telegram_username" placeholder="@username (optional)" class="px-3 py-1.5 border rounded-lg text-sm w-44">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Label</label>
                <input type="text" name="label" placeholder="e.g. Dhrobe — Superadmin" class="px-3 py-1.5 border rounded-lg text-sm w-56">
            </div>
            <button type="submit" class="px-4 py-1.5 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700"><i class="fas fa-plus mr-1"></i>Authorize</button>
        </form>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <h2 class="font-semibold text-gray-800 text-sm"><i class="fas fa-shield-halved text-blue-500 mr-1"></i>Authorized Users</h2>
            <span class="text-xs text-gray-400"><?php echo count($authorized); ?> authorized</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="text-left px-4 py-2">Telegram ID</th>
                        <th class="text-left px-4 py-2">Username</th>
                        <th class="text-left px-4 py-2">Label</th>
                        <th class="text-left px-4 py-2">Added By</th>
                        <th class="text-left px-4 py-2">Added</th>
                        <th class="text-center px-4 py-2">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($authorized)): ?>
                    <tr><td colspan="6" class="text-center py-10 text-gray-400">No one authorized yet — add the first Telegram ID above.</td></tr>
                    <?php else: foreach ($authorized as $a): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs"><?php echo (int)$a->telegram_user_id; ?></td>
                        <td class="px-4 py-2 text-gray-600"><?php echo $a->telegram_username ? '@' . htmlspecialchars($a->telegram_username) : '—'; ?></td>
                        <td class="px-4 py-2 text-gray-700"><?php echo htmlspecialchars($a->label ?? '—'); ?></td>
                        <td class="px-4 py-2 text-gray-500"><?php echo htmlspecialchars($a->added_by_name ?? '—'); ?></td>
                        <td class="px-4 py-2 text-gray-400"><?php echo date('d M Y', strtotime($a->created_at)); ?></td>
                        <td class="px-4 py-2 text-center">
                            <form method="POST" onsubmit="return confirm('Revoke /ask access for this Telegram user?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="id" value="<?php echo (int)$a->id; ?>">
                                <button type="submit" class="text-red-500 hover:text-red-700" title="Revoke access"><i class="fas fa-user-slash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($recent_queries)): ?>
    <details class="bg-white rounded-xl shadow-sm border border-gray-200">
        <summary class="px-4 py-3 cursor-pointer font-semibold text-gray-800 text-sm select-none"><i class="fas fa-clock-rotate-left text-blue-500 mr-1"></i>Recent /ask Activity (last 30)</summary>
        <div class="overflow-x-auto border-t border-gray-100">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="text-left px-4 py-2">When</th>
                        <th class="text-left px-4 py-2">Telegram User</th>
                        <th class="text-left px-4 py-2">Question</th>
                        <th class="text-center px-4 py-2">Status</th>
                        <th class="text-right px-4 py-2">Rows</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($recent_queries as $q): ?>
                    <tr>
                        <td class="px-4 py-2 text-gray-400"><?php echo date('d M h:i A', strtotime($q->created_at)); ?></td>
                        <td class="px-4 py-2 text-gray-600 font-mono text-xs"><?php echo (int)$q->telegram_user_id; ?><?php echo $q->telegram_username ? ' (@' . htmlspecialchars($q->telegram_username) . ')' : ''; ?></td>
                        <td class="px-4 py-2 text-gray-700"><?php echo htmlspecialchars(mb_strimwidth($q->question, 0, 80, '…')); ?></td>
                        <td class="px-4 py-2 text-center">
                            <?php if (!$q->authorized): ?>
                                <span class="inline-flex px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-xs font-semibold">Unauthorized</span>
                            <?php elseif ($q->success): ?>
                                <span class="inline-flex px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-semibold">OK</span>
                            <?php else: ?>
                                <span class="inline-flex px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-xs font-semibold" title="<?php echo htmlspecialchars($q->error_message ?? ''); ?>">Error</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-2 text-right text-gray-500"><?php echo $q->row_count !== null ? (int)$q->row_count : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php endif; ?>

</div>
<?php require_once '../templates/footer.php'; ?>

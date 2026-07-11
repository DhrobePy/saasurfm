<?php
require_once '../core/init.php';

// Superadmin only
restrict_access(['Superadmin']);

global $db;
$currentUser   = getCurrentUser();
$user_id_me    = $currentUser['id'] ?? null;
$pageTitle     = 'Roles & Privileges';

/* ─── Auto-create custom permissions table ─────────────────── */
$db->getPdo()->exec("
    CREATE TABLE IF NOT EXISTS `user_custom_permissions` (
      `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` bigint UNSIGNED NOT NULL,
      `module` varchar(50) NOT NULL,
      `is_module_enabled` tinyint(1) NOT NULL DEFAULT 1,
      `allowed_pages` text DEFAULT NULL COMMENT 'JSON array of page keys',
      `allowed_actions` text DEFAULT NULL COMMENT 'JSON object {page_key: {action_key: bool}}',
      `data_scope` enum('all','own','branch') NOT NULL DEFAULT 'all',
      `data_scope_branches` varchar(500) DEFAULT NULL COMMENT 'CSV of branch IDs',
      `notes` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_user_module` (`user_id`, `module`),
      KEY `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

/* ─── Migrate audit log module column from ENUM → VARCHAR ───
   The system_audit_log.module column was originally a narrow ENUM.
   Values like 'user_custom_permissions' exceed its allowed list.
   This ALTER is idempotent — safe to run on every page load.       */
try {
    $col = $db->getPdo()->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'system_audit_log'
            AND COLUMN_NAME  = 'module'"
    )->fetchColumn();
    if ($col && stripos($col, 'varchar') === false) {
        // Column is still ENUM — widen it to VARCHAR(100)
        $db->getPdo()->exec(
            "ALTER TABLE `system_audit_log`
             MODIFY COLUMN `module` varchar(100) NOT NULL
             COMMENT 'Module/feature where action occurred'"
        );
    }
} catch (\Throwable $e) {
    error_log('audit_log migration failed: ' . $e->getMessage());
}

$error   = null;
$success = null;

/* ─── Module / Page / Action hierarchy ─────────────────────── */
// Fully dynamic: module registry + filesystem scan
$modules_registry = getModuleRegistry();

/* ─── Load branches ─────────────────────────────────────────── */
$branches = $db->query("SELECT id, name, code FROM branches ORDER BY name")->results();

/* ─── Handle POST: save privileges ─────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_privileges') {
    try {
        $target_user_id = (int)$_POST['target_user_id'];
        if (!$target_user_id) throw new Exception("Invalid user selected.");

        $target_user = $db->query("SELECT id, display_name, role FROM users WHERE id = ?", [$target_user_id])->first();
        if (!$target_user) throw new Exception("User not found.");

        $privileges = $_POST['privileges'] ?? [];

        $pdo = $db->getPdo();
        $pdo->beginTransaction();

        foreach ($modules_registry as $module_key => $module_def) {
            $mod_data = $privileges[$module_key] ?? [];
            $is_enabled = isset($mod_data['enabled']) ? 1 : 0;

            if (!$is_enabled) {
                // Remove any existing entry — no access granted for unchecked modules
                $db->query(
                    "DELETE FROM user_custom_permissions WHERE user_id = ? AND module = ?",
                    [$target_user_id, $module_key]
                );
                continue;
            }

            // Pages — collect page_keys from submitted checkboxes
            $allowed_pages = [];
            foreach ($mod_data['pages'] ?? [] as $page_key => $v) {
                $allowed_pages[] = $page_key;
            }
            $allowed_pages_json = json_encode($allowed_pages);

            // Actions — page-keyed: {"page_key": {"action_key": true}}
            $allowed_actions = [];
            foreach ($mod_data['actions'] ?? [] as $page_key => $page_act) {
                foreach ($page_act as $act_key => $v) {
                    $allowed_actions[$page_key][$act_key] = true;
                }
            }
            $allowed_actions_json = json_encode($allowed_actions);

            // Data scope
            $data_scope          = in_array($mod_data['data_scope'] ?? 'all', ['all','own','branch']) ? ($mod_data['data_scope'] ?? 'all') : 'all';
            $data_scope_branches = null;
            if ($data_scope === 'branch') {
                $branch_ids = array_filter(array_map('intval', $mod_data['branches'] ?? []));
                $data_scope_branches = implode(',', $branch_ids);
            }

            $notes = trim($mod_data['notes'] ?? '');

            // Upsert
            $existing = $db->query(
                "SELECT id FROM user_custom_permissions WHERE user_id = ? AND module = ?",
                [$target_user_id, $module_key]
            )->first();

            if ($existing) {
                $db->query(
                    "UPDATE user_custom_permissions SET
                        is_module_enabled = ?, allowed_pages = ?, allowed_actions = ?,
                        data_scope = ?, data_scope_branches = ?, notes = ?, updated_at = NOW()
                     WHERE user_id = ? AND module = ?",
                    [$is_enabled, $allowed_pages_json, $allowed_actions_json,
                     $data_scope, $data_scope_branches, $notes ?: null,
                     $target_user_id, $module_key]
                );
            } else {
                $db->insert('user_custom_permissions', [
                    'user_id'              => $target_user_id,
                    'module'               => $module_key,
                    'is_module_enabled'    => $is_enabled,
                    'allowed_pages'        => $allowed_pages_json,
                    'allowed_actions'      => $allowed_actions_json,
                    'data_scope'           => $data_scope,
                    'data_scope_branches'  => $data_scope_branches,
                    'notes'                => $notes ?: null,
                ]);
            }
        }

        $pdo->commit();

        try {
            auditLog('user_custom_permissions', 'UPSERT',
                "Custom privileges updated for user '{$target_user->display_name}' (id:{$target_user_id}) by Superadmin",
                ['target_user_id' => $target_user_id, 'modules_configured' => array_keys($privileges)]
            );
        } catch (\Throwable $auditEx) {
            // Audit log failure must never block the privilege save
            error_log('auditLog skipped (likely ENUM column not yet migrated): ' . $auditEx->getMessage());
        }

        $success = "Privileges for <strong>" . htmlspecialchars($target_user->display_name) . "</strong> saved successfully. "
                 . "Changes take effect on the user's next page load (session cache refreshes within 5 minutes, or immediately after they re-login).";

        // If Superadmin changed their OWN permissions (unlikely but possible),
        // clear the local session cache immediately.
        if ($target_user_id === (int)($currentUser['id'] ?? 0)) {
            invalidateCustomPermsCache();
        }

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

/* ─── Handle POST: clear privileges ────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_privileges') {
    try {
        $target_user_id = (int)$_POST['target_user_id'];
        $db->query("DELETE FROM user_custom_permissions WHERE user_id = ?", [$target_user_id]);
        $tu = $db->query("SELECT display_name FROM users WHERE id = ?", [$target_user_id])->first();
        $success = "All custom privileges for <strong>" . htmlspecialchars($tu->display_name ?? 'user') . "</strong> cleared. "
                 . "Role defaults now apply. Changes take effect on their next page load.";
        if ($target_user_id === (int)($currentUser['id'] ?? 0)) {
            invalidateCustomPermsCache();
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/* ─── Handle POST: save action limits (per-action ৳ ceilings + legacy fallback) ── */
ensureApprovalLimitTable();
ensureActionLimitsTable();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['save_approval_limit', 'save_action_limits'])) {
    try {
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);
        if (!$target_user_id) throw new Exception("Invalid user selected.");
        $tu = $db->query("SELECT display_name FROM users WHERE id = ?", [$target_user_id])->first();
        if (!$tu) throw new Exception("User not found.");

        // 1) Legacy/default approval limit (fallback for approve/amend/early-release)
        $limit_raw = trim($_POST['approval_limit'] ?? '');
        if ($limit_raw === '' || (float)$limit_raw <= 0) {
            $db->query("DELETE FROM user_approval_limits WHERE user_id = ?", [$target_user_id]);
        } else {
            $limit = (float)$limit_raw;
            $exists = $db->query("SELECT id FROM user_approval_limits WHERE user_id = ?", [$target_user_id])->first();
            if ($exists) {
                $db->query("UPDATE user_approval_limits SET max_order_amount = ?, set_by_user_id = ? WHERE user_id = ?",
                           [$limit, $user_id_me, $target_user_id]);
            } else {
                $db->insert('user_approval_limits', [
                    'user_id'          => $target_user_id,
                    'max_order_amount' => $limit,
                    'set_by_user_id'   => $user_id_me,
                ]);
            }
        }

        // 2) Per-action ceilings — empty/zero removes the row (= no cap)
        $saved_bits = [];
        foreach (getLimitBearingActions() as $ak => $al) {
            $v = trim($_POST['action_limits'][$ak] ?? '');
            if ($v === '' || (float)$v <= 0) {
                $db->query("DELETE FROM user_action_limits WHERE user_id = ? AND action_key = ?",
                           [$target_user_id, $ak]);
            } else {
                $db->query(
                    "INSERT INTO user_action_limits (user_id, action_key, max_amount, set_by_user_id)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE max_amount = VALUES(max_amount), set_by_user_id = VALUES(set_by_user_id)",
                    [$target_user_id, $ak, (float)$v, $user_id_me]
                );
                $saved_bits[] = $ak . ' ৳' . number_format((float)$v, 0);
            }
        }

        auditLog('user_action_limits', 'updated',
            "Action limits for '{$tu->display_name}' (id:{$target_user_id}): "
            . ($saved_bits ? implode(', ', $saved_bits) : 'all cleared')
            . ($limit_raw !== '' ? " | default ৳" . number_format((float)$limit_raw, 0) : ' | default cleared'),
            ['target_user_id' => $target_user_id]);

        $success = "Action limits saved for <strong>" . htmlspecialchars($tu->display_name) . "</strong>. "
                 . "Empty fields mean no cap for that action.";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/* ─── Load selected user's current custom permissions ─────── */
$selected_user_id = (int)($_GET['user_id'] ?? $_POST['target_user_id'] ?? 0);
$selected_user    = null;
$current_perms    = [];  // module_key => stdClass

if ($selected_user_id) {
    $selected_user = $db->query(
        "SELECT id, display_name, email, role, status FROM users WHERE id = ?",
        [$selected_user_id]
    )->first();

    if ($selected_user) {
        $rows = $db->query(
            "SELECT * FROM user_custom_permissions WHERE user_id = ?",
            [$selected_user_id]
        )->results();
        foreach ($rows as $r) {
            $r->allowed_pages_arr   = json_decode($r->allowed_pages ?? '[]', true) ?? [];
            $r->allowed_actions_arr = json_decode($r->allowed_actions ?? '{}', true) ?? [];
            $r->branch_ids_arr      = $r->data_scope_branches
                ? array_map('intval', explode(',', $r->data_scope_branches))
                : [];
            $current_perms[$r->module] = $r;
        }
    }
}

/* ─── Load all users for search ────────────────────────────── */
$all_users = $db->query(
    "SELECT id, display_name, email, role FROM users ORDER BY display_name ASC"
)->results();
$users_json = json_encode(array_map(fn($u) => [
    'id'   => $u->id,
    'name' => $u->display_name,
    'email'=> $u->email,
    'role' => $u->role,
], $all_users));

require_once '../templates/header.php';

/* ─── Helper: colour for module ─────────────────────────────── */
$color_classes = [
    'blue'   => 'bg-blue-50 border-blue-200 text-blue-700',
    'green'  => 'bg-green-50 border-green-200 text-green-700',
    'orange' => 'bg-orange-50 border-orange-200 text-orange-700',
    'red'    => 'bg-red-50 border-red-200 text-red-700',
    'indigo' => 'bg-indigo-50 border-indigo-200 text-indigo-700',
    'teal'   => 'bg-teal-50 border-teal-200 text-teal-700',
    'yellow' => 'bg-yellow-50 border-yellow-200 text-yellow-700',
    'gray'   => 'bg-gray-50 border-gray-200 text-gray-700',
];
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6" x-data="privilegeManager()">

<!-- Page Header -->
<div class="mb-6 flex flex-wrap justify-between items-center gap-3">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">
            <i class="fas fa-shield-alt text-purple-600 mr-2"></i>Roles & Privileges
        </h1>
        <p class="text-gray-500 mt-1">Configure module, page, and action access per user. Only Superadmin and Admin have full access by default — all other users see only what you enable here.</p>
    </div>
    <a href="users.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-sm">
        <i class="fas fa-arrow-left mr-2"></i>Back to Users
    </a>
</div>

<!-- Flash messages -->
<?php if ($error): ?>
<div class="mb-4 p-4 bg-red-50 border border-red-300 rounded-lg text-red-800">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="mb-4 p-4 bg-green-50 border border-green-300 rounded-lg text-green-800">
    <i class="fas fa-check-circle mr-2"></i><?php echo $success; ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

    <!-- ═══ LEFT: USER SEARCH ═══ -->
    <div class="lg:col-span-1">

        <!-- User Search Box -->
        <div class="bg-white rounded-xl shadow-md p-5 sticky top-4">
            <h2 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                <i class="fas fa-search text-purple-500"></i>Find User
            </h2>
            <input type="text"
                   id="userSearchInput"
                   oninput="searchUsers(this.value)"
                   onfocus="showUserDropdown()"
                   placeholder="Name, email or role…"
                   autocomplete="off"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                   value="<?php echo $selected_user ? htmlspecialchars($selected_user->display_name) : ''; ?>">
            <div id="userDropdown" class="mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-64 overflow-y-auto hidden z-30 relative">
                <!-- populated by JS -->
            </div>

            <?php if ($selected_user): ?>
            <!-- Selected user card -->
            <div class="mt-4 p-3 bg-purple-50 border border-purple-200 rounded-lg">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-9 h-9 rounded-full bg-purple-600 flex items-center justify-center flex-shrink-0">
                        <span class="text-white font-bold text-sm">
                            <?php echo strtoupper(substr($selected_user->display_name ?? 'U', 0, 1)); ?>
                        </span>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900 text-sm"><?php echo htmlspecialchars($selected_user->display_name ?? ''); ?></p>
                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($selected_user->email ?? ''); ?></p>
                    </div>
                </div>
                <div class="flex items-center justify-between text-xs">
                    <span class="px-2 py-0.5 bg-purple-100 text-purple-700 rounded font-semibold">
                        <?php echo htmlspecialchars($selected_user->role); ?>
                    </span>
                    <span class="text-<?php echo $selected_user->status === 'active' ? 'green' : 'red'; ?>-600 font-medium">
                        <?php echo ucfirst($selected_user->status); ?>
                    </span>
                </div>
                <?php if (!empty($current_perms)): ?>
                <div class="mt-2 text-xs text-purple-700 font-medium">
                    <i class="fas fa-layer-group mr-1"></i><?php echo count($current_perms); ?> module(s) with custom settings
                </div>
                <?php else: ?>
                <div class="mt-2 text-xs text-red-500 italic"><i class="fas fa-ban mr-1"></i>No privileges configured — user cannot access any module</div>
                <?php endif; ?>
            </div>

            <!-- Clear All Privileges -->
            <?php if (!empty($current_perms)): ?>
            <form method="POST" onsubmit="return confirm('Clear ALL custom privileges for this user? They will lose access to all modules immediately.');">
                <input type="hidden" name="action" value="clear_privileges">
                <input type="hidden" name="target_user_id" value="<?php echo $selected_user->id; ?>">
                <button type="submit" class="mt-3 w-full px-3 py-1.5 text-xs text-red-600 border border-red-200 rounded-lg hover:bg-red-50 transition-colors">
                    <i class="fas fa-trash mr-1"></i>Clear All Custom Privileges
                </button>
            </form>
            <?php endif; ?>

            <!-- ── Action Limits (৳) — per-action ceilings ──────── -->
            <?php
            $current_limit = getUserApprovalLimit((int)$selected_user->id);
            $ual_current = [];
            try {
                foreach ($db->query("SELECT action_key, max_amount FROM user_action_limits WHERE user_id = ?",
                                    [(int)$selected_user->id])->results() as $ualr) {
                    $ual_current[$ualr->action_key] = (float)$ualr->max_amount;
                }
            } catch (Exception $e) {}
            $ual_short = [
                'approve_order'    => 'Approve Orders',
                'amend_order'      => 'Amend Orders',
                'early_release'    => 'Early Release (Watch)',
                'collect_payment'  => 'Collect Payment',
                'partial_delivery' => 'Partial Delivery',
            ];
            ?>
            <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-xl">
                <p class="text-xs font-bold text-amber-800 mb-1">
                    <i class="fas fa-gavel mr-1"></i>Action Limits (৳)
                </p>
                <p class="text-[11px] text-amber-700 mb-2 leading-snug">
                    Per-action ceilings for this user. Empty = <strong>no cap</strong> for that action.
                    The <em>Default</em> value backs up Approve / Amend / Early-Release when their
                    own field is empty — it never affects Collect Payment or Partial Delivery.
                </p>
                <form method="POST" class="space-y-1.5">
                    <input type="hidden" name="action" value="save_action_limits">
                    <input type="hidden" name="target_user_id" value="<?php echo $selected_user->id; ?>">

                    <div class="flex items-center gap-1.5">
                        <label class="text-[11px] text-amber-900 font-semibold w-36 shrink-0">Default (fallback)</label>
                        <input type="number" name="approval_limit" min="0" step="1000"
                               value="<?php echo $current_limit !== null ? htmlspecialchars($current_limit) : ''; ?>"
                               placeholder="no cap"
                               class="flex-1 min-w-0 px-2 py-1 border border-amber-300 rounded-lg text-xs focus:ring-1 focus:ring-amber-500">
                    </div>

                    <?php foreach ($ual_short as $ak => $al): ?>
                    <div class="flex items-center gap-1.5">
                        <label class="text-[11px] text-amber-800 w-36 shrink-0"><?php echo $al; ?></label>
                        <input type="number" name="action_limits[<?php echo $ak; ?>]" min="0" step="1000"
                               value="<?php echo isset($ual_current[$ak]) ? htmlspecialchars($ual_current[$ak]) : ''; ?>"
                               placeholder="<?php echo in_array($ak, ['approve_order', 'amend_order', 'early_release']) ? 'default' : 'no cap'; ?>"
                               class="flex-1 min-w-0 px-2 py-1 border border-amber-300 rounded-lg text-xs focus:ring-1 focus:ring-amber-500">
                    </div>
                    <?php endforeach; ?>

                    <button type="submit"
                            class="w-full mt-1 px-3 py-1.5 bg-amber-600 text-white text-xs font-semibold rounded-lg hover:bg-amber-700 transition-colors cursor-pointer">
                        <i class="fas fa-save mr-1"></i>Save All Limits
                    </button>
                </form>
            </div>
            <?php else: ?>
            <p class="mt-4 text-sm text-gray-400 text-center italic">Search and select a user to configure their privileges.</p>
            <?php endif; ?>

            <!-- Module quick-jump -->
            <?php if ($selected_user): ?>
            <div class="mt-5">
                <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Jump to Module</p>
                <div class="space-y-1">
                    <?php foreach ($modules_registry as $mk => $md):
                        if ($mk === 'admin') continue; ?>
                    <a href="#module_<?php echo $mk; ?>"
                       class="flex items-center gap-2 px-3 py-1.5 text-xs rounded-lg hover:bg-gray-100 text-gray-600 transition-colors">
                        <i class="fas <?php echo $md['icon']; ?> w-3 text-<?php echo $md['color']; ?>-500"></i>
                        <?php echo $md['label']; ?>
                        <?php if (isset($current_perms[$mk])): ?>
                        <span class="ml-auto w-2 h-2 rounded-full bg-purple-500"></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ RIGHT: PRIVILEGE CONFIGURATOR ═══ -->
    <div class="lg:col-span-3">

        <?php if (!$selected_user): ?>
        <div class="bg-white rounded-xl shadow-md p-12 text-center text-gray-400">
            <i class="fas fa-shield-alt text-6xl mb-4 opacity-20"></i>
            <h3 class="text-xl font-semibold mb-2">Select a User</h3>
            <p class="text-sm">Search for a user on the left to view and configure their custom privileges.</p>
        </div>
        <?php else: ?>

        <!-- Info banner -->
        <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-xl text-blue-800 text-sm flex gap-3">
            <i class="fas fa-info-circle mt-0.5 flex-shrink-0"></i>
            <div>
                <strong>How this works:</strong> Enable a module below to grant this user access to it.
                Modules that are <em>not</em> enabled here are completely inaccessible — there is no role-based fallback.
                When a module is enabled, the pages and actions you check are the <em>only</em> ones this user can access.
            </div>
        </div>

        <form method="POST" id="privilegeForm">
            <input type="hidden" name="action" value="save_privileges">
            <input type="hidden" name="target_user_id" value="<?php echo $selected_user->id; ?>">

            <!-- Module Cards — fully dynamic from registry + filesystem scan -->
            <?php foreach ($modules_registry as $module_key => $module_def):
                // Skip admin module from privilege configuration (admin-only always)
                if ($module_key === 'admin') continue;

                $perm       = $current_perms[$module_key] ?? null;
                $is_enabled = $perm ? (bool)$perm->is_module_enabled : false;
                $col        = $module_def['color'];
                $col_header = $color_classes[$col] ?? $color_classes['gray'];

                // Dynamically scan ALL pages for this module
                $all_pages = scanModulePages($module_key);

                // Parse saved allowed_pages and allowed_actions from DB
                $saved_pages   = $perm && !empty($perm->allowed_pages)   ? (array)json_decode($perm->allowed_pages, true)   : [];
                $saved_actions = $perm && !empty($perm->allowed_actions) ? (array)json_decode($perm->allowed_actions, true) : [];

                // Count total actions across all pages
                $total_actions = 0;
                foreach ($all_pages as $p) { $total_actions += count($p['actions']); }
            ?>
            <div class="bg-white rounded-xl shadow-md mb-4 overflow-hidden" id="module_<?php echo $module_key; ?>">

                <!-- Module Header -->
                <div class="flex items-center justify-between px-5 py-4 border-b <?php echo $is_enabled ? "bg-{$col}-50 border-{$col}-200" : 'bg-gray-50 border-gray-200'; ?>">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg bg-<?php echo $col; ?>-100 flex items-center justify-center">
                            <i class="fas <?php echo $module_def['icon']; ?> text-<?php echo $col; ?>-600"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-gray-900"><?php echo $module_def['label']; ?></h3>
                            <p class="text-xs text-gray-500">
                                <?php echo count($all_pages); ?> pages ·
                                <?php echo $total_actions; ?> actions
                                <?php if ($is_enabled): ?>
                                <span class="text-<?php echo $col; ?>-600 font-semibold">· Custom enabled</span>
                                <?php else: ?>
                                <span class="text-red-400">· No access (disabled)</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <!-- Module Enable Toggle -->
                    <label class="flex items-center gap-2 cursor-pointer select-none">
                        <span class="text-sm text-gray-500">Custom override</span>
                        <div class="relative">
                            <input type="checkbox"
                                   name="privileges[<?php echo $module_key; ?>][enabled]"
                                   value="1"
                                   <?php echo $is_enabled ? 'checked' : ''; ?>
                                   class="sr-only module-toggle"
                                   id="toggle_<?php echo $module_key; ?>"
                                   onchange="toggleModulePanel('<?php echo $module_key; ?>', this.checked)">
                            <div id="toggle_track_<?php echo $module_key; ?>"
                                 class="w-12 h-6 rounded-full transition-colors <?php echo $is_enabled ? "bg-{$col}-500" : 'bg-gray-300'; ?>"></div>
                            <div id="toggle_thumb_<?php echo $module_key; ?>"
                                 class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform <?php echo $is_enabled ? 'translate-x-6' : ''; ?>"></div>
                        </div>
                    </label>
                </div>

                <!-- Module Panel (collapsed if not enabled) -->
                <div id="panel_<?php echo $module_key; ?>"
                     class="<?php echo $is_enabled ? '' : 'hidden'; ?>">
                    <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-5">

                        <!-- ── PAGES & ACTIONS (combined, dynamic) ── -->
                        <div class="md:col-span-1">
                            <h4 class="text-xs font-semibold text-gray-500 uppercase mb-3 flex items-center gap-1">
                                <i class="fas fa-file-alt text-<?php echo $col; ?>-400"></i> Pages &amp; Actions
                                <button type="button" class="ml-auto text-xs text-<?php echo $col; ?>-600 hover:underline"
                                        onclick="checkAllPages('<?php echo $module_key; ?>')">All</button>
                                /
                                <button type="button" class="text-xs text-gray-400 hover:underline"
                                        onclick="uncheckAllPages('<?php echo $module_key; ?>')">None</button>
                            </h4>
                            <div class="space-y-3">
                                <?php foreach ($all_pages as $page_key => $page_meta):
                                    $page_checked = empty($saved_pages) ? true : in_array($page_key, $saved_pages);
                                    $page_actions = $page_meta['actions'];
                                ?>
                                <div class="border border-gray-100 rounded-lg p-3 bg-gray-50">
                                    <label class="flex items-center gap-2 cursor-pointer font-medium text-gray-700 text-sm">
                                        <input type="checkbox"
                                               name="privileges[<?php echo $module_key; ?>][pages][<?php echo $page_key; ?>]"
                                               value="1"
                                               <?php echo ($page_checked && $is_enabled) ? 'checked' : ''; ?>
                                               class="page-checkbox-<?php echo $module_key; ?> rounded text-blue-600">
                                        <span><?php echo htmlspecialchars($page_meta['label']); ?></span>
                                        <code class="text-xs text-gray-400 font-mono ml-1"><?php echo htmlspecialchars($page_key); ?>.php</code>
                                    </label>

                                    <?php if (!empty($page_actions)): ?>
                                    <div class="mt-2 ml-6 grid grid-cols-2 gap-1">
                                        <?php foreach ($page_actions as $action_key => $action_label):
                                            $action_checked = !empty($saved_actions[$page_key][$action_key]);
                                        ?>
                                        <label class="flex items-center gap-1 text-xs text-gray-600 cursor-pointer action-checkbox-<?php echo $module_key; ?>-wrap">
                                            <input type="checkbox"
                                                   name="privileges[<?php echo $module_key; ?>][actions][<?php echo $page_key; ?>][<?php echo $action_key; ?>]"
                                                   value="1"
                                                   <?php echo ($action_checked && $is_enabled) ? 'checked' : ''; ?>
                                                   class="action-checkbox-<?php echo $module_key; ?> rounded text-green-600 w-3 h-3">
                                            <span><?php echo htmlspecialchars($action_label); ?></span>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- ── DATA SCOPE & NOTES ── -->
                        <div class="md:col-span-1">
                            <h4 class="text-xs font-semibold text-gray-500 uppercase mb-3 flex items-center gap-1">
                                <i class="fas fa-database text-<?php echo $col; ?>-400"></i> Data Scope
                            </h4>

                            <?php
                                $cur_scope    = $perm->data_scope ?? 'all';
                                $cur_branches = $perm->branch_ids_arr ?? [];
                            ?>
                            <div class="space-y-2">
                                <?php foreach (['all' => 'All data (no restriction)', 'own' => 'Own records only', 'branch' => 'Specific branch(es)'] as $scope_val => $scope_label): ?>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio"
                                           name="privileges[<?php echo $module_key; ?>][data_scope]"
                                           value="<?php echo $scope_val; ?>"
                                           <?php echo $cur_scope === $scope_val ? 'checked' : ''; ?>
                                           class="scope-radio-<?php echo $module_key; ?> w-4 h-4 text-<?php echo $col; ?>-600"
                                           onchange="toggleBranchPicker('<?php echo $module_key; ?>', '<?php echo $scope_val; ?>')">
                                    <span class="text-sm text-gray-700"><?php echo htmlspecialchars($scope_label); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>

                            <!-- Branch picker (shown when scope=branch) -->
                            <div id="branch_picker_<?php echo $module_key; ?>"
                                 class="mt-3 <?php echo $cur_scope === 'branch' ? '' : 'hidden'; ?>">
                                <p class="text-xs text-gray-500 mb-2">Select allowed branches:</p>
                                <div class="space-y-1 max-h-32 overflow-y-auto">
                                    <?php foreach ($branches as $br): ?>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox"
                                               name="privileges[<?php echo $module_key; ?>][branches][]"
                                               value="<?php echo $br->id; ?>"
                                               <?php echo in_array($br->id, $cur_branches) ? 'checked' : ''; ?>
                                               class="w-4 h-4 text-<?php echo $col; ?>-600 rounded">
                                        <span class="text-xs text-gray-700">
                                            <?php echo htmlspecialchars($br->name); ?>
                                            <?php if ($br->code): ?><em class="text-gray-400">(<?php echo $br->code; ?>)</em><?php endif; ?>
                                        </span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Notes -->
                            <div class="mt-4">
                                <label class="text-xs font-medium text-gray-500 block mb-1">Internal note (optional)</label>
                                <textarea name="privileges[<?php echo $module_key; ?>][notes]"
                                          rows="2"
                                          placeholder="e.g., Restricted per manager request on 2026-01-01"
                                          class="w-full text-xs px-2 py-1.5 border border-gray-200 rounded focus:ring-1 focus:ring-purple-500"><?php echo htmlspecialchars($perm->notes ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Save Button -->
            <div class="sticky bottom-4 flex justify-end">
                <button type="submit"
                        class="px-8 py-3 bg-purple-600 text-white rounded-xl font-semibold hover:bg-purple-700 shadow-lg transition-colors">
                    <i class="fas fa-save mr-2"></i>Save Privileges for <?php echo htmlspecialchars($selected_user->display_name); ?>
                </button>
            </div>
        </form>

        <?php endif; /* selected_user */ ?>
    </div><!-- end right col -->
</div><!-- end grid -->

</div><!-- end max-w container -->

<script>
/* ─── User Search ─────────────────────────────────────────── */
const allUsers = <?php echo $users_json; ?>;
let selectedUserId = <?php echo $selected_user ? $selected_user->id : 0; ?>;

function searchUsers(q) {
    q = q.toLowerCase().trim();
    const dd = document.getElementById('userDropdown');
    if (!q) { dd.classList.add('hidden'); return; }

    const matches = allUsers.filter(u =>
        u.name.toLowerCase().includes(q) ||
        u.email.toLowerCase().includes(q) ||
        u.role.toLowerCase().includes(q)
    ).slice(0, 10);

    if (!matches.length) { dd.innerHTML = '<div class="px-4 py-3 text-sm text-gray-400">No users found</div>'; dd.classList.remove('hidden'); return; }

    dd.innerHTML = matches.map(u => `
        <div class="px-4 py-2 hover:bg-purple-50 cursor-pointer border-b border-gray-100 last:border-0"
             onclick="selectUser(${u.id}, '${u.name.replace(/'/g,"\\'")}')">
            <div class="font-medium text-sm text-gray-900">${u.name}</div>
            <div class="text-xs text-gray-500">${u.email} · <span class="text-purple-600">${u.role}</span></div>
        </div>`).join('');
    dd.classList.remove('hidden');
}

function showUserDropdown() {
    const val = document.getElementById('userSearchInput').value.trim();
    if (val) searchUsers(val);
}

function selectUser(id, name) {
    document.getElementById('userDropdown').classList.add('hidden');
    window.location.href = 'privileges.php?user_id=' + id;
}

document.addEventListener('click', (e) => {
    if (!e.target.closest('#userDropdown') && !e.target.closest('#userSearchInput')) {
        document.getElementById('userDropdown')?.classList.add('hidden');
    }
});

/* ─── Module panel toggle ────────────────────────────────── */
function toggleModulePanel(moduleKey, enabled) {
    const panel = document.getElementById('panel_' + moduleKey);
    const track = document.getElementById('toggle_track_' + moduleKey);
    const thumb = document.getElementById('toggle_thumb_' + moduleKey);
    if (enabled) {
        panel.classList.remove('hidden');
        track.style.backgroundColor = '';
        track.classList.remove('bg-gray-300');
        thumb.classList.add('translate-x-6');
    } else {
        panel.classList.add('hidden');
        track.classList.add('bg-gray-300');
        thumb.classList.remove('translate-x-6');
    }
}

/* ─── Branch picker toggle ───────────────────────────────── */
function toggleBranchPicker(moduleKey, scopeVal) {
    const picker = document.getElementById('branch_picker_' + moduleKey);
    if (!picker) return;
    picker.classList.toggle('hidden', scopeVal !== 'branch');
}

/* ─── Select all / none ──────────────────────────────────── */
function checkAllPages(mk) {
    document.querySelectorAll('.page-checkbox-' + mk).forEach(cb => cb.checked = true);
}
function uncheckAllPages(mk) {
    document.querySelectorAll('.page-checkbox-' + mk).forEach(cb => cb.checked = false);
}
function checkAllActions(mk) {
    document.querySelectorAll('.action-checkbox-' + mk).forEach(cb => cb.checked = true);
}
function uncheckAllActions(mk) {
    document.querySelectorAll('.action-checkbox-' + mk).forEach(cb => cb.checked = false);
}

/* ─── Alpine placeholder (page uses no x-data yet) ──────── */
function privilegeManager() { return {}; }
</script>

<?php require_once '../templates/footer.php'; ?>
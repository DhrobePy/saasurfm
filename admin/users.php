<?php
require_once '../core/init.php';

// Only Superadmin and admin can manage users
$allowed_roles = ['Superadmin', 'admin'];
restrict_access($allowed_roles);

global $db;
$currentUser   = getCurrentUser();
$user_role     = $currentUser['role'] ?? '';
$pageTitle     = 'User Management';
$users         = [];
$error         = null;
$is_superadmin = ($user_role === 'Superadmin');

/* ── Migration: add plain_password column if not yet present ── */
try {
    $cols = $db->getPdo()->query("SHOW COLUMNS FROM users LIKE 'plain_password'")->fetchAll();
    if (empty($cols)) {
        $db->getPdo()->exec("ALTER TABLE users ADD COLUMN `plain_password` VARCHAR(255) DEFAULT NULL COMMENT 'Superadmin visible plain-text credential' AFTER `password_hash`");
    }
} catch (Exception $me) { /* column already exists */ }

/* ── Flash messages ─────────────────────────────────────────── */
$success_flash = $_SESSION['success_flash'] ?? null;
$error_flash   = $_SESSION['error_flash']   ?? null;
unset($_SESSION['success_flash'], $_SESSION['error_flash']);

/* ── Load users ─────────────────────────────────────────────── */
try {
    $select_pw = $is_superadmin ? ', u.plain_password' : ", '' AS plain_password";
    $users = $db->query(
        "SELECT
            u.id, u.uuid, u.display_name, u.email, u.role, u.status,
            u.last_login {$select_pw},
            e.id AS employee_id,
            CONCAT(e.first_name, ' ', e.last_name) AS employee_name
         FROM users u
         LEFT JOIN employees e ON u.id = e.user_id
         ORDER BY u.display_name ASC"
    )->results();
} catch (Exception $e) {
    $error = "Could not load users: " . $e->getMessage();
}

/* ── Check if privileges table exists (badge indicator) ─────── */
$privileges_table_exists = false;
try {
    $chk = $db->getPdo()->query("SHOW TABLES LIKE 'user_custom_permissions'")->fetch();
    $privileges_table_exists = (bool)$chk;
} catch (Exception $e) {}

$users_with_custom_perms = [];
if ($privileges_table_exists) {
    $rows = $db->query("SELECT DISTINCT user_id FROM user_custom_permissions WHERE is_module_enabled = 1")->results();
    foreach ($rows as $r) $users_with_custom_perms[$r->user_id] = true;
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Page Header -->
<div class="flex flex-wrap justify-between items-center mb-6 gap-3">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">User Management</h1>
        <p class="text-gray-500 mt-1">Manage system login access and roles.</p>
    </div>
    <div class="flex gap-2">
        <?php if ($is_superadmin): ?>
        <a href="privileges.php"
           class="inline-flex items-center px-4 py-2 border border-purple-300 rounded-lg text-sm font-medium text-purple-700 bg-purple-50 hover:bg-purple-100 transition-colors">
            <i class="fas fa-shield-alt mr-2"></i>Roles & Privileges
        </a>
        <?php endif; ?>
        <a href="manage_user.php"
           class="inline-flex items-center px-5 py-2 rounded-lg text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 transition-colors">
            <i class="fas fa-user-plus mr-2"></i>Add New User
        </a>
    </div>
</div>

<!-- Flash messages -->
<?php if ($success_flash): ?>
<div class="mb-4 p-4 bg-green-50 border border-green-300 rounded-lg text-green-800">
    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_flash); ?>
</div>
<?php endif; ?>
<?php if ($error_flash || $error): ?>
<div class="mb-4 p-4 bg-red-50 border border-red-300 rounded-lg text-red-800">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_flash ?? $error); ?>
</div>
<?php endif; ?>

<?php if ($is_superadmin): ?>
<div class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-amber-800 text-sm flex items-start gap-2">
    <i class="fas fa-lock-open mt-0.5 flex-shrink-0"></i>
    <span><strong>Superadmin view:</strong> Password column is visible only to you. Passwords are stored alongside their hashed version for administration purposes.</span>
</div>
<?php endif; ?>

<!-- Users Table -->
<div class="bg-white rounded-xl shadow-md overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                    <?php if ($is_superadmin): ?>
                    <th class="px-5 py-3 text-left text-xs font-medium text-amber-600 uppercase tracking-wider">
                        <i class="fas fa-key mr-1"></i>Password
                    </th>
                    <?php endif; ?>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Login</th>
                    <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($users) && !$error): ?>
                <tr>
                    <td colspan="<?php echo $is_superadmin ? 8 : 7; ?>" class="px-6 py-10 text-center text-gray-400">
                        <i class="fas fa-users-slash text-3xl mb-3"></i>
                        <p>No users found. Click "Add New User" to get started.</p>
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($users as $user):
                    $statusClass = [
                        'active'    => 'bg-green-100 text-green-800',
                        'pending'   => 'bg-yellow-100 text-yellow-800',
                        'suspended' => 'bg-red-100 text-red-800',
                    ][$user->status] ?? 'bg-gray-100 text-gray-800';

                    $roleClass = [
                        'Superadmin' => 'bg-red-100 text-red-800 font-bold',
                        'admin'      => 'bg-purple-100 text-purple-800',
                        'Accounts'   => 'bg-blue-100 text-blue-800',
                    ][$user->role] ?? 'bg-gray-100 text-gray-800';

                    $has_custom = !empty($users_with_custom_perms[$user->id]);
                    $pw_uid = 'pw_' . $user->id;
                ?>
                <tr class="hover:bg-gray-50">
                    <!-- Name -->
                    <td class="px-5 py-4 whitespace-nowrap">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-primary-100 flex items-center justify-center flex-shrink-0">
                                <span class="text-primary-700 font-bold text-sm">
                                    <?php echo strtoupper(substr($user->display_name, 0, 1)); ?>
                                </span>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($user->display_name); ?></div>
                                <?php if ($has_custom): ?>
                                <span class="text-xs text-purple-600 font-medium"><i class="fas fa-shield-alt mr-0.5"></i>Custom privileges</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>

                    <!-- Email -->
                    <td class="px-5 py-4 whitespace-nowrap text-gray-600">
                        <?php echo htmlspecialchars($user->email); ?>
                    </td>

                    <!-- Role -->
                    <td class="px-5 py-4 whitespace-nowrap">
                        <span class="px-2.5 py-0.5 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $roleClass; ?>">
                            <?php echo htmlspecialchars($user->role); ?>
                        </span>
                    </td>

                    <?php if ($is_superadmin): ?>
                    <!-- Password (Superadmin only) -->
                    <td class="px-5 py-4 whitespace-nowrap">
                        <?php if ($user->plain_password): ?>
                        <div class="flex items-center gap-2" id="pw_container_<?php echo $user->id; ?>">
                            <span class="font-mono text-sm text-gray-400 tracking-widest" id="pw_mask_<?php echo $user->id; ?>">••••••••</span>
                            <span class="font-mono text-sm text-gray-900 hidden select-all" id="pw_plain_<?php echo $user->id; ?>">
                                <?php echo htmlspecialchars($user->plain_password); ?>
                            </span>
                            <button type="button"
                                    onclick="togglePassword(<?php echo $user->id; ?>)"
                                    id="pw_btn_<?php echo $user->id; ?>"
                                    class="text-gray-400 hover:text-amber-600 transition-colors"
                                    title="Show / Hide password">
                                <i class="fas fa-eye text-xs" id="pw_icon_<?php echo $user->id; ?>"></i>
                            </button>
                            <button type="button"
                                    onclick="copyPassword(<?php echo $user->id; ?>, this)"
                                    class="text-gray-400 hover:text-green-600 transition-colors"
                                    title="Copy password">
                                <i class="fas fa-copy text-xs"></i>
                            </button>
                        </div>
                        <?php else: ?>
                        <span class="text-xs text-gray-400 italic">Not recorded</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>

                    <!-- Employee -->
                    <td class="px-5 py-4 whitespace-nowrap text-gray-600">
                        <?php if ($user->employee_id): ?>
                        <a href="employee_profile.php?id=<?php echo $user->employee_id; ?>" class="text-primary-600 hover:underline text-xs">
                            <?php echo htmlspecialchars($user->employee_name); ?>
                        </a>
                        <?php else: ?>
                        <span class="text-gray-400 italic text-xs">None</span>
                        <?php endif; ?>
                    </td>

                    <!-- Status -->
                    <td class="px-5 py-4 whitespace-nowrap">
                        <span class="px-2.5 py-0.5 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                            <?php echo ucfirst($user->status); ?>
                        </span>
                    </td>

                    <!-- Last Login -->
                    <td class="px-5 py-4 whitespace-nowrap text-xs text-gray-500">
                        <?php echo $user->last_login ? date('d M Y, H:i', strtotime($user->last_login)) : '—'; ?>
                    </td>

                    <!-- Actions -->
                    <td class="px-5 py-4 whitespace-nowrap text-right space-x-3">
                        <?php if ($is_superadmin): ?>
                        <a href="privileges.php?user_id=<?php echo $user->id; ?>"
                           class="text-purple-500 hover:text-purple-700" title="Set Privileges">
                            <i class="fas fa-shield-alt text-sm"></i>
                        </a>
                        <?php endif; ?>
                        <a href="manage_user.php?edit=<?php echo urlencode($user->uuid); ?>"
                           class="text-primary-600 hover:text-primary-900" title="Edit User">
                            <i class="fas fa-edit"></i>
                        </a>
                        <?php if ($user->role !== 'Superadmin'): ?>
                        <a href="manage_user.php?delete=<?php echo urlencode($user->uuid); ?>"
                           class="text-red-500 hover:text-red-800" title="Delete User"
                           onclick="return confirm('Delete <?php echo htmlspecialchars(addslashes($user->display_name)); ?>? This cannot be undone.');">
                            <i class="fas fa-trash text-sm"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<?php if ($is_superadmin): ?>
<script>
function togglePassword(userId) {
    const mask  = document.getElementById('pw_mask_'  + userId);
    const plain = document.getElementById('pw_plain_' + userId);
    const icon  = document.getElementById('pw_icon_'  + userId);
    const showing = !plain.classList.contains('hidden');
    if (showing) {
        plain.classList.add('hidden');
        mask.classList.remove('hidden');
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    } else {
        plain.classList.remove('hidden');
        mask.classList.add('hidden');
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    }
}

function copyPassword(userId, btn) {
    const plain = document.getElementById('pw_plain_' + userId);
    const text  = plain.textContent.trim();
    navigator.clipboard.writeText(text).then(() => {
        btn.innerHTML = '<i class="fas fa-check text-xs text-green-600"></i>';
        setTimeout(() => { btn.innerHTML = '<i class="fas fa-copy text-xs"></i>'; }, 1500);
    });
}
</script>
<?php endif; ?>

<?php require_once '../templates/footer.php'; ?>

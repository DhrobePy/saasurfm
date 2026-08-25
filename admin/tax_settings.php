<?php
/**
 * Tax / Company Registration Settings (Jul 2026) — TIN, BIN, legal name,
 * registered address, and fiscal-year start month, used as the header info
 * for accounts/tax_statement.php's NBR tax-return draft. Stored in the
 * existing generic `settings` key/value table (same convention as every
 * other toggle in this codebase — no new table needed).
 */
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Tax / Company Registration Settings';
$csrfToken = $_SESSION['csrf_token'] ?? '';
$success = null;
$error = null;

$setting_keys = ['company_legal_name', 'company_tin', 'company_bin', 'company_registered_address', 'fiscal_year_start_month'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        $error = 'Security validation failed. Please refresh and try again.';
    } else {
        try {
            $values = [
                'company_legal_name'        => trim($_POST['company_legal_name'] ?? ''),
                'company_tin'                => trim($_POST['company_tin'] ?? ''),
                'company_bin'                => trim($_POST['company_bin'] ?? ''),
                'company_registered_address' => trim($_POST['company_registered_address'] ?? ''),
                'fiscal_year_start_month'    => (string)max(1, min(12, (int)($_POST['fiscal_year_start_month'] ?? 7))),
            ];
            foreach ($values as $name => $value) {
                $exists = $db->query("SELECT id FROM settings WHERE name = ?", [$name])->first();
                if ($exists) {
                    $db->query("UPDATE settings SET value = ? WHERE name = ?", [$value, $name]);
                } else {
                    $db->query("INSERT INTO settings (name, value) VALUES (?, ?)", [$name, $value]);
                }
            }
            $success = 'Saved.';
        } catch (Exception $e) {
            $error = 'Could not save: ' . $e->getMessage();
        }
    }
}

$current = [];
foreach ($setting_keys as $k) {
    $row = $db->query("SELECT value FROM settings WHERE name = ?", [$k])->first();
    $current[$k] = $row->value ?? '';
}
if ($current['fiscal_year_start_month'] === '') $current['fiscal_year_start_month'] = '7';

require_once '../templates/header.php';
?>
<div class="max-w-2xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Tax / Company Registration Settings</h1>
            <p class="text-sm text-gray-500 mt-1">Used as the header on the NBR tax-statement draft.</p>
        </div>
        <a href="../accounts/tax_statement.php" class="text-sm text-blue-600 hover:text-blue-800"><i class="fas fa-arrow-left mr-1"></i>Back to Tax Statement</a>
    </div>

    <?php if ($success): ?>
    <div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" class="bg-white rounded-xl shadow-sm p-6 space-y-4">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Company Legal Name</label>
            <input type="text" name="company_legal_name" value="<?php echo htmlspecialchars($current['company_legal_name']); ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="e.g. Ujjal Flour Mills Ltd.">
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">TIN (e-TIN)</label>
                <input type="text" name="company_tin" value="<?php echo htmlspecialchars($current['company_tin']); ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">BIN</label>
                <input type="text" name="company_bin" value="<?php echo htmlspecialchars($current['company_bin']); ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Registered Address</label>
            <textarea name="company_registered_address" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><?php echo htmlspecialchars($current['company_registered_address']); ?></textarea>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Fiscal / Income Year Starts</label>
            <select name="fiscal_year_start_month" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                <?php
                $months = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
                foreach ($months as $num => $name):
                ?>
                <option value="<?php echo $num; ?>" <?php echo (int)$current['fiscal_year_start_month'] === $num ? 'selected' : ''; ?>><?php echo $name; ?></option>
                <?php endforeach; ?>
            </select>
            <p class="text-xs text-gray-500 mt-1">Most Bangladeshi companies use July – June. Only change this if the company has formally elected a different income year with NBR.</p>
        </div>

        <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg">
            Save Settings
        </button>
    </form>
</div>
<?php require_once '../templates/footer.php'; ?>

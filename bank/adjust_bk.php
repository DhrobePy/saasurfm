<?php
/**
 * bank/migrate_add_btx_col.php
 *
 * Root cause fix: v_bank_statement has 12 columns — bank_tx_account_id is NOT one of them.
 * central_statement.php queries WHERE bank_tx_account_id=? which silently returns nothing
 * because that column does not exist.
 *
 * Fix: add bank_tx_account_id as the 13th column to all 4 UNION ALL branches:
 *   cr_payment, expense, purchase_payment → NULL AS bank_tx_account_id
 *   bank_tx                              → bt.bank_tx_account_id AS bank_tx_account_id
 *
 * Run once as Superadmin, then delete this file.
 */
require_once dirname(__DIR__) . '/core/init.php';
restrict_access(['Superadmin']);

global $db;
$pdo = $db->getPdo();

$error   = null;
$success = null;
$info    = [];
$preview = null;

// ── Find FROM keyword at depth-0 (outside any parentheses / quoted strings) ──
function findTopLevelFrom(string $sql): int {
    $len   = strlen($sql);
    $depth = 0;
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        // skip quoted identifiers and strings
        if ($ch === '`' || $ch === '\'' || $ch === '"') {
            $q = $ch;
            for ($i++; $i < $len; $i++) {
                if ($sql[$i] === $q) break;
                if ($sql[$i] === '\\') $i++;
            }
            continue;
        }
        if ($ch === '(') { $depth++; continue; }
        if ($ch === ')') { $depth--; continue; }
        if ($depth === 0 && ($i === 0 || !ctype_alnum($sql[$i - 1]))) {
            if (strtoupper(substr($sql, $i, 4)) === 'FROM') {
                $after = $i + 4 < $len ? $sql[$i + 4] : ' ';
                if (!ctype_alnum($after) && $after !== '_') {
                    return $i;
                }
            }
        }
    }
    return -1;
}

// ── Check column exists in VIEW ──────────────────────────────────────────────
function viewHasColumn(PDO $pdo, string $col): bool {
    $r = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'v_bank_statement'
         AND COLUMN_NAME = " . $pdo->quote($col)
    )->fetchAll(PDO::FETCH_COLUMN);
    return count($r) > 0;
}

// ── Read current VIEW ─────────────────────────────────────────────────────────
$branches  = [];
$selectSql = null;
try {
    $row = $pdo->query("SHOW CREATE VIEW `v_bank_statement`")->fetch(PDO::FETCH_NUM);
    if (!$row) throw new Exception("VIEW v_bank_statement not found.");
    $asPos = stripos($row[1], ' AS ');
    if ($asPos === false) throw new Exception("Cannot parse VIEW SQL (no AS clause).");
    $selectSql = trim(rtrim(substr($row[1], $asPos + 4), " \t\n\r;"));
    $branches  = preg_split('/\bunion\s+all\b/i', $selectSql);
    if (count($branches) < 4) {
        throw new Exception("Expected ≥4 UNION ALL branches, found " . count($branches) . ".");
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// ── Diagnostics ───────────────────────────────────────────────────────────────
$needsFix = false;
if (!$error) {
    // 1. Is bank_tx_account_id already a column?
    if (viewHasColumn($pdo, 'bank_tx_account_id')) {
        $info[] = ['ok', 'bank_tx_account_id column already exists in v_bank_statement — no migration needed.'];
    } else {
        $needsFix = true;
        $info[] = ['warn', 'bank_tx_account_id is NOT a column in v_bank_statement. This is why filtering by bank_tx account returns no results.'];
    }

    // 2. Transaction status overview
    try {
        $stats = $pdo->query(
            "SELECT bta.bank_name, bta.account_name, bt.status, COUNT(*) AS cnt
             FROM bank_transactions bt
             JOIN bank_tx_accounts bta ON bta.id = bt.bank_tx_account_id
             GROUP BY bta.id, bt.status ORDER BY bta.id, bt.status"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($stats) {
            $lines = ['bank_transactions by account + status:'];
            foreach ($stats as $r) {
                $lines[] = "  [{$r['status']}] {$r['bank_name']} — {$r['account_name']}: {$r['cnt']} tx";
            }
            $approved = array_sum(array_column(array_filter($stats, fn($r) => $r['status'] === 'approved'), 'cnt'));
            $pending  = array_sum(array_column(array_filter($stats, fn($r) => $r['status'] === 'pending'), 'cnt'));
            $lines[]  = "Total: $approved approved, $pending pending";
            $info[]   = ['info', implode("\n", $lines)];
            if ($approved === 0) {
                $info[] = ['warn', 'No approved transactions — VIEW only shows approved. Go to the Bank module and approve them.'];
            }
        } else {
            $info[] = ['info', 'No bank_transactions rows found.'];
        }
    } catch (Exception $e) {
        $info[] = ['info', 'Could not read bank_transactions: ' . $e->getMessage()];
    }

    // 3. Branch column count (informational; dump shows cr_payment truncated but server has full 12)
    $counts = [];
    foreach ($branches as $i => $b) {
        $from = findTopLevelFrom($b);
        $sel  = $from >= 0 ? substr($b, 0, $from) : $b;
        // Count top-level commas
        $depth = 0; $commas = 0;
        for ($j = 0; $j < strlen($sel); $j++) {
            $c = $sel[$j];
            if ($c === '(' || $c === '`' || $c === '\'' || $c === '"') {
                if ($c !== '(') { // skip quoted
                    $q = $c;
                    for ($j++; $j < strlen($sel); $j++) {
                        if ($sel[$j] === $q) break;
                    }
                    continue;
                }
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $commas++;
            }
        }
        $counts[] = $commas + 1;
    }
    $info[] = ['info', 'Branch column counts from SHOW CREATE VIEW: ' . implode(', ', $counts)
        . ' (branch 1 may appear truncated in phpMyAdmin dumps; the actual server value is what matters here)'];

    // Preview of bank_tx branch
    $last    = trim(end($branches));
    $preview = mb_substr($last, 0, 800);
}

// ── Apply fix ─────────────────────────────────────────────────────────────────
if ($needsFix && !$error && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fix') {
    try {
        $newBranches = [];
        foreach ($branches as $i => $branch) {
            $isBankTx  = (stripos($branch, '`bank_transactions`') !== false || stripos($branch, 'bank_transactions') !== false);
            $newColExpr = $isBankTx
                ? '`bt`.`bank_tx_account_id` AS `bank_tx_account_id`'
                : 'NULL AS `bank_tx_account_id`';

            // Skip if already present
            if (stripos($branch, 'bank_tx_account_id') !== false) {
                $newBranches[] = $branch;
                continue;
            }

            // Find the top-level FROM position
            $fromPos = findTopLevelFrom($branch);
            if ($fromPos < 0) {
                throw new Exception("Cannot find top-level FROM in branch " . ($i + 1) . ". Cannot safely modify.");
            }

            // Insert new column right before FROM (with a comma after the previous column)
            $newBranch = rtrim(substr($branch, 0, $fromPos)) . ',' . $newColExpr . ' ' . ltrim(substr($branch, $fromPos));
            $newBranches[] = $newBranch;
        }

        $newSelectSql = implode(' UNION ALL ', $newBranches);
        $pdo->exec("CREATE OR REPLACE VIEW `v_bank_statement` AS $newSelectSql");

        // Verify
        if (!viewHasColumn($pdo, 'bank_tx_account_id')) {
            throw new Exception("VIEW recreated but bank_tx_account_id column still missing. "
                . "Check if the SELECT injection worked by running SHOW CREATE VIEW v_bank_statement manually.");
        }

        $success = "✓ VIEW updated. bank_tx_account_id is now column 13 in v_bank_statement. "
                 . "Central Statement bank_tx account filter should now work.";

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8">
<title>Fix v_bank_statement — add bank_tx_account_id</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8 font-sans text-sm">
<div class="max-w-2xl mx-auto">

<div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-5">
    <h1 class="text-lg font-bold text-gray-900 mb-1">Fix v_bank_statement — add bank_tx_account_id</h1>
    <p class="text-xs text-gray-500">
        Adds <code class="bg-gray-100 px-1 rounded">bank_tx_account_id</code> as column 13 to all UNION ALL branches so
        <a href="<?= url('bank/central_statement.php') ?>" class="underline">Central Statement</a>
        can filter by bank_tx account.
        <strong class="text-red-600">Delete this file after running.</strong>
    </p>
</div>

<?php foreach ($info as [$type, $msg]): ?>
<div class="rounded-xl p-3 mb-3 text-xs whitespace-pre-wrap font-mono
    <?= $type === 'warn' ? 'bg-amber-50 border border-amber-200 text-amber-800'
      : ($type === 'ok'  ? 'bg-green-50 border border-green-200 text-green-800'
                         : 'bg-gray-50 border border-gray-200 text-gray-600') ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endforeach; ?>

<?php if ($error): ?>
<div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-5 text-xs text-red-800 font-mono whitespace-pre-wrap">
    ✗ Error: <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-5 text-sm text-green-800 font-semibold">
    <?= htmlspecialchars($success) ?>
</div>
<div class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-xs text-blue-800">
    <strong>Next steps:</strong><br>
    1. <a href="<?= url('bank/central_statement.php') ?>" class="underline">Open Central Statement</a> — pick a bank_tx account from the dropdown and set your date range.<br>
    2. Only <strong>approved</strong> bank_tx transactions appear. Approve pending ones in the Bank module first.<br>
    3. Delete this file.
</div>

<?php elseif (!$needsFix && !$error): ?>
<div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-5 text-sm text-green-800">
    <strong>✓ No migration needed.</strong> If filtering still shows no results, the cause is that the selected
    bank_tx account has no <em>approved</em> transactions in the date range. Go to the Bank module to approve them.
</div>

<?php elseif ($needsFix && !$error): ?>
<div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-5 text-sm text-amber-800">
    <strong>Fix required.</strong> Clicking below appends <code>bank_tx_account_id</code> as the last SELECT column
    in all 4 UNION ALL branches of v_bank_statement.
</div>
<form method="POST">
    <input type="hidden" name="action" value="fix">
    <button type="submit"
        class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-sm transition-colors"
        onclick="return confirm('Apply fix to v_bank_statement VIEW on production database?')">
        Apply Fix
    </button>
</form>
<?php endif; ?>

<?php if ($preview): ?>
<div class="mt-6 bg-gray-50 border border-gray-200 rounded-xl p-4">
    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-2">bank_tx branch (last UNION ALL) — first 800 chars</p>
    <pre class="text-[10px] text-gray-600 overflow-x-auto whitespace-pre-wrap break-all"><?= htmlspecialchars($preview) ?></pre>
</div>
<?php endif; ?>

</div></body></html>

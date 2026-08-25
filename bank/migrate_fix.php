<?php
/**
 * One-time migration: fix v_bank_statement VIEW
 * Changes bank_tx branch from INNER JOIN to LEFT JOIN on bank_account_map,
 * adds JOIN to bank_tx_accounts for fallback bank_name/account_number,
 * so bank_transactions on unmapped accounts still appear (with null bank_account_id).
 *
 * DELETE THIS FILE after running.
 */

require_once dirname(__DIR__) . '/core/init.php';
restrict_access(['Superadmin']);

global $db;
$pdo = $db->getPdo();

$error   = null;
$success = null;
$before  = null;
$after   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {

    try {
        // ── 1. Read the current VIEW definition exactly as stored ────────────
        $stmt = $pdo->query("SHOW CREATE VIEW `v_bank_statement`");
        $row  = $stmt->fetch(PDO::FETCH_NUM);
        if (!$row) throw new Exception("Could not read v_bank_statement. Does it exist?");

        $createSql = $row[1]; // "CREATE ALGORITHM=... VIEW `v_bank_statement` AS <SELECT ...>"

        // ── 2. Extract the SELECT part (everything after " AS ") ─────────────
        $asPos = stripos($createSql, ' AS ');
        if ($asPos === false) throw new Exception("Cannot find AS clause in CREATE VIEW SQL.");
        $selectSql = trim(substr($createSql, $asPos + 4));
        $selectSql = rtrim($selectSql, " \t\n\r;");

        $before = $selectSql;

        // ── 3. Split on UNION ALL (case-insensitive) ─────────────────────────
        $branches = preg_split('/\bunion all\b/i', $selectSql);
        if (count($branches) < 4) {
            throw new Exception("Expected 4 UNION ALL branches, found " . count($branches) . ". VIEW structure may have changed.");
        }

        // ── 4. Verify last branch is the bank_tx branch ──────────────────────
        $bankTxBranch = $branches[count($branches) - 1];
        if (stripos($bankTxBranch, 'bank_transactions') === false) {
            throw new Exception("Last UNION ALL branch does not reference bank_transactions. Aborting.");
        }
        if (stripos($bankTxBranch, 'bank_tx_accounts') !== false) {
            $success = "VIEW already references bank_tx_accounts — migration was already applied. No changes made.";
        } else {

            // ── 5. Replace bank_account_id selection (bam may now be null) ───
            $bankTxBranch = str_ireplace(
                '`bam`.`bank_account_id` AS `bank_account_id`',
                '`bam`.`bank_account_id` AS `bank_account_id`',  // keep as-is; NULL is fine for unmapped
                $bankTxBranch
            );

            // ── 6. Replace bank_name / account_number with COALESCE fallback ─
            $bankTxBranch = preg_replace(
                '/convert\(`ba`\.`bank_name`\s+using\s+utf8mb4\)\s+collate\s+utf8mb4_unicode_ci\s+AS\s+`Name_exp_5`,\s*convert\(`ba`\.`account_number`\s+using\s+utf8mb4\)\s+collate\s+utf8mb4_unicode_ci\s+AS\s+`Name_exp_6`/i',
                'convert(coalesce(`ba`.`bank_name`,`bta`.`bank_name`,\'\') using utf8mb4) collate utf8mb4_unicode_ci AS `Name_exp_5`,convert(coalesce(`ba`.`account_number`,`bta`.`account_number`,\'\') using utf8mb4) collate utf8mb4_unicode_ci AS `Name_exp_6`',
                $bankTxBranch
            );

            // ── 7. Replace FROM clause: add bank_tx_accounts, LEFT JOINs ─────
            // Old pattern (inner joins):
            //   from ((`bank_transactions` `bt`
            //     join `bank_account_map` `bam` on(...))
            //     join `bank_accounts` `ba` on(...))
            //   where `bt`.`status` = 'approved'
            $bankTxBranch = preg_replace(
                '/from\s*\(\s*\(\s*`bank_transactions`\s+`bt`\s+join\s+`bank_account_map`\s+`bam`\s+on\s*\([^)]+\)\s*\)\s*join\s+`bank_accounts`\s+`ba`\s+on\s*\([^)]+\)\s*\)\s*where\s+`bt`\.`status`\s*=\s*\'approved\'/i',
                'from `bank_transactions` `bt` '
                . 'join `bank_tx_accounts` `bta` on(`bta`.`id` = `bt`.`bank_tx_account_id`) '
                . 'left join `bank_account_map` `bam` on(`bam`.`bank_tx_account_id` = `bt`.`bank_tx_account_id` and `bam`.`confirmed` = 1) '
                . 'left join `bank_accounts` `ba` on(`ba`.`id` = `bam`.`bank_account_id`) '
                . "where `bt`.`status` = 'approved'",
                $bankTxBranch
            );

            // ── 8. Verify the regex actually changed something ────────────────
            if (stripos($bankTxBranch, 'bank_tx_accounts') === false) {
                throw new Exception(
                    "Regex did not match the FROM clause. The VIEW SQL format may differ slightly.\n\n"
                    . "bank_tx branch found:\n" . htmlspecialchars($bankTxBranch)
                );
            }

            // ── 9. Rebuild full SELECT ────────────────────────────────────────
            $branches[count($branches) - 1] = $bankTxBranch;
            $newSelectSql = implode(' UNION ALL ', $branches);

            $after = $newSelectSql;

            // ── 10. Apply ─────────────────────────────────────────────────────
            $pdo->exec("CREATE OR REPLACE VIEW `v_bank_statement` AS $newSelectSql");

            $success = "✓ VIEW updated successfully. bank_tx branch now uses LEFT JOIN on bank_account_map and JOIN on bank_tx_accounts.";
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ── Preview current bank_tx branch for diagnostics ───────────────────────────
$preview = null;
try {
    $stmt = $pdo->query("SHOW CREATE VIEW `v_bank_statement`");
    $row  = $stmt->fetch(PDO::FETCH_NUM);
    if ($row) {
        $sql    = $row[1];
        $asPos  = stripos($sql, ' AS ');
        $sel    = trim(substr($sql, $asPos + 4));
        $parts  = preg_split('/\bunion all\b/i', $sel);
        $last   = end($parts);
        $preview = trim($last);
    }
} catch (Exception $e) {
    $preview = "Could not read: " . $e->getMessage();
}

$alreadyFixed = ($preview && stripos($preview, 'bank_tx_accounts') !== false);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fix v_bank_statement VIEW</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8 font-sans">
<div class="max-w-3xl mx-auto">

    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-6">
        <h1 class="text-xl font-bold text-gray-900 mb-1 flex items-center gap-2">
            🔧 Fix v_bank_statement VIEW
        </h1>
        <p class="text-sm text-gray-500">
            Changes the bank_tx branch from INNER JOIN to LEFT JOIN on bank_account_map,
            and adds a JOIN on bank_tx_accounts for fallback bank name. <strong>Delete this file after running.</strong>
        </p>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-5 text-sm text-red-800 whitespace-pre-wrap font-mono">
        ✗ Error: <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-5 text-sm text-green-800 font-semibold">
        <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <!-- Current bank_tx branch diagnostic -->
    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 mb-5">
        <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">
            Current bank_tx branch (last UNION ALL):
        </p>
        <?php if ($alreadyFixed): ?>
        <div class="text-xs text-green-700 font-semibold mb-2">
            ✓ Already references bank_tx_accounts — migration appears already applied.
        </div>
        <?php else: ?>
        <div class="text-xs text-amber-700 font-semibold mb-2">
            ⚠ Uses INNER JOIN on bank_account_map — unmapped bank_tx accounts are excluded.
        </div>
        <?php endif; ?>
        <pre class="text-[10px] text-gray-600 overflow-x-auto whitespace-pre-wrap break-all"><?= htmlspecialchars($preview ?? 'N/A') ?></pre>
    </div>

    <?php if (!$alreadyFixed && !$success): ?>
    <form method="POST">
        <input type="hidden" name="action" value="run">
        <button type="submit"
                class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-sm transition-colors"
                onclick="return confirm('Apply VIEW fix to production database?')">
            Apply Fix
        </button>
    </form>
    <?php elseif ($success && stripos($success, 'already') === false): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-sm text-blue-800">
        <strong>Next steps:</strong><br>
        1. <a href="<?= url('bank/central_statement.php') ?>" class="underline">Open Central Statement</a> — approved bank_tx from all accounts should now appear.<br>
        2. Newly created transactions still need to be <strong>approved</strong> in the bank module before they show (status = 'pending' until approved — this is intentional).<br>
        3. <strong>Delete this file</strong>: <code>bank/migrate_fix_view.php</code>
    </div>
    <?php endif; ?>

</div>
</body>
</html>
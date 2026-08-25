<?php
/**
 * Delete/reverse a loan — Recycle-Bin backed, restorable. Simpler than the
 * commodity-sale equivalent since a loan never touches customer_ledger/
 * supplier_ledger (see ensureLoansTable()'s docblock) — just the journal
 * entry and the loan row itself. Blocked if any repayment has been collected
 * (amount_repaid > 0) — same precedent as delete_commodity_sale.php; reverse
 * the repayment(s) first via view_loan.php.
 */
require_once '../core/init.php';

$currentUser = getCurrentUser();
$__is_admin_dl = in_array(($currentUser['role'] ?? ''), ['Superadmin', 'admin'], true);
if (!$__is_admin_dl && !userCanPageAction('loans', 'loan', 'can_delete')) {
    restrict_access(['Superadmin', 'admin']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

header('Content-Type: application/json');

$session_token  = $_SESSION['csrf_token'] ?? '';
$received_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!$session_token || !$received_token || !hash_equals($session_token, $received_token)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'CSRF validation failed']));
}

$loan_id = isset($_POST['loan_id']) ? (int)$_POST['loan_id'] : 0;
$reason  = trim($_POST['reason'] ?? '');

if ($loan_id <= 0) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Invalid loan ID']));
}

global $db;
$pdo = $db->getPdo();

ensureRecycleBinTables();

try {
    $pdo->beginTransaction();

    $loan = $db->query(
        "SELECT l.*, c.name AS customer_name, s.company_name AS supplier_name
         FROM loans l LEFT JOIN customers c ON c.id = l.customer_id LEFT JOIN suppliers s ON s.id = l.supplier_id
         WHERE l.id = ? FOR UPDATE",
        [$loan_id]
    )->first();

    if (!$loan) {
        throw new Exception("Loan #{$loan_id} not found");
    }
    $party_name = $loan->customer_name ?? $loan->supplier_name ?? 'party';
    if ((float)$loan->amount_repaid > 0) {
        throw new Exception("Cannot delete — ৳" . number_format((float)$loan->amount_repaid, 2)
            . " has already been repaid against {$loan->loan_number}. Reverse the repayment(s) first, then delete the loan.");
    }

    $batch = recycleBegin(
        'loan',
        "Loan {$loan->loan_number} — {$party_name} · ৳" . number_format((float)$loan->principal_amount, 2),
        (int)($loan->customer_id ?: 0) ?: null
    );

    $journals = $db->query(
        "SELECT id FROM journal_entries WHERE related_document_type = 'loans' AND related_document_id = ?",
        [$loan_id]
    )->results();
    foreach ($journals as $je) {
        recycleArchiveDelete($batch, 'transaction_lines', 'journal_entry_id', (int)$je->id);
        recycleArchiveDelete($batch, 'journal_entries', 'id', (int)$je->id);
    }

    recycleArchiveDelete($batch, 'loans', 'id', $loan_id);

    recycleFinalize($batch);
    $pdo->commit();

    $deletedBy = $currentUser['display_name'] ?? ($currentUser['username'] ?? 'Unknown');
    $reasonNote = $reason ? " — Reason: {$reason}" : '';

    auditLog('other', 'deleted',
        "Loan {$loan->loan_number} (৳" . number_format((float)$loan->principal_amount, 2)
        . ") to {$party_name} moved to Recycle Bin (batch #{$batch}) by {$deletedBy}{$reasonNote}",
        [
            'severity' => 'critical', 'record_id' => $loan_id, 'loan_number' => $loan->loan_number,
            'amount' => (float)$loan->principal_amount, 'party_name' => $party_name,
            'deleted_by_id' => $currentUser['id'] ?? null, 'reason' => $reason ?: null,
        ]);

    if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED
        && defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID')) {
        try {
            require_once '../core/classes/TelegramNotifier.php';
            $reason_note = $reason ? "\n• Reason: <i>{$reason}</i>" : '';
            (new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('payment')))->sendMessage(
                "<b>🗑️ LOAN DELETED</b>\n───────────────────────────────\n\n"
                . "• Loan: <code>{$loan->loan_number}</code>\n• To: <b>" . htmlspecialchars($party_name) . "</b>\n"
                . "• ৳" . number_format((float)$loan->principal_amount, 2) . "\n"
                . "• Deleted by: <b>{$deletedBy}</b>" . $reason_note
                . "\n\n<i>Journal reversed. Restorable from the Recycle Bin.</i>"
            );
        } catch (\Throwable $te) { error_log('delete_loan Telegram: ' . $te->getMessage()); }
    }

    exit(json_encode([
        'success' => true,
        'message' => "Loan {$loan->loan_number} reversed and moved to Recycle Bin (batch #{$batch}) — restorable by Superadmin.",
    ]));

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    error_log('delete_loan.php error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => $e->getMessage()]));
}

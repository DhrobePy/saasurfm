<?php
/**
 * bank/ajax_handler.php
 * AJAX handler + Excel export for Bank Transaction Module
 */

require_once dirname(__DIR__) . '/core/init.php';
require_once dirname(__DIR__) . '/bank/BankManager.php';

if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$currentUser = getCurrentUser();
$userId      = $currentUser['id'];
$userRole    = $currentUser['role'];
$userName    = $currentUser['display_name'];
$ipAddress   = $_SERVER['REMOTE_ADDR'] ?? null;

$adminRoles    = ['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg'];
$isAdmin       = in_array($userRole, $adminRoles);
// Approver role can approve/reject transactions but not export or do admin operations
$approverRoles = array_merge($adminRoles, ['Bank Transaction Approver']);
$isApprover    = in_array($userRole, $approverRoles);

$action      = $_REQUEST['action'] ?? '';
$bankManager = new BankManager();

// ── Excel export (GET) ─────────────────────────────────────
if ($action === 'export_excel') {
    if (!$isAdmin) { die('Access denied.'); }

    $filters = [
        'keyword'             => $_GET['keyword']             ?? '',
        'bank_tx_account_id'     => $_GET['bank_tx_account_id']     ?? '',
        'entry_type'          => $_GET['entry_type']          ?? '',
        'status'              => $_GET['status']              ?? '',
        'date_from'           => $_GET['date_from']           ?? '',
        'date_to'             => $_GET['date_to']             ?? '',
        'transaction_type_id' => $_GET['transaction_type_id'] ?? '',
    ];

    $rows = $bankManager->getTransactionsForExport($filters, $userId, $userRole);

    $filename = 'bank_transactions_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel

    fputcsv($out, [
        'Transaction Number', 'Date', 'Entry Type', 'Bank', 'Account Name',
        'Account Number', 'Category', 'Amount', 'Reference', 'Cheque Number',
        'Payee/Payer', 'Description', 'Special Note', 'Branch',
        'Status', 'Created By', 'Approved By', 'Created At'
    ]);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r->transaction_number,
            $r->transaction_date,
            strtoupper($r->entry_type),
            $r->bank_name,
            $r->account_name,
            $r->account_number,
            $r->type_name ?? '',
            $r->amount,
            $r->reference_number ?? '',
            $r->cheque_number ?? '',
            $r->payee_payer_name ?? '',
            $r->description ?? '',
            $r->special_note ?? '',
            $r->branch_name ?? '',
            ucfirst($r->status),
            $r->created_by_name ?? '',
            $r->approved_by_name ?? '',
            $r->created_at,
        ]);
    }

    fclose($out);
    exit;
}

// ── JSON AJAX actions (POST) ───────────────────────────────
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

try {
    switch ($action) {

        // ── Approve ─────────────────────────────────────────
        case 'approve':
            if (!$isApprover) throw new Exception('Permission denied.');
            $txId = (int)($_POST['id'] ?? 0);
            $bankManager->approveTransaction($txId, $userId, $userName, $ipAddress);
            echo json_encode(['success' => true, 'message' => 'Transaction approved.']);
            break;

        // ── Reject ──────────────────────────────────────────
        case 'reject':
            if (!$isApprover) throw new Exception('Permission denied.');
            $txId   = (int)($_POST['id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            if (!$reason) throw new Exception('Rejection reason is required.');
            $bankManager->rejectTransaction($txId, $reason, $userId, $userName, $ipAddress);
            echo json_encode(['success' => true, 'message' => 'Transaction rejected.']);
            break;

        // ── Unpost (soft delete) ────────────────────────────
        case 'unpost':
            if (!$isApprover) throw new Exception('Permission denied.');
            $txId = (int)($_POST['id'] ?? 0);
            $bankManager->unpostTransaction($txId, $userId, $userName, $ipAddress, $userRole);
            echo json_encode(['success' => true, 'message' => 'Transaction marked as unposted.']);
            break;

        // ── Get balance for a bank account ──────────────────
        case 'get_balance':
            $baId = (int)($_POST['bank_tx_account_id'] ?? 0);
            $bal  = $bankManager->getAccountBalance($baId);
            $acct = $bankManager->getBankAccountById($baId);
            echo json_encode([
                'success' => true,
                'balance' => $bal,
                'initial' => $acct ? (float)$acct->opening_balance : 0,
                'formatted' => '৳' . number_format($bal, 2)
            ]);
            break;

        // ── Save transaction type (admin) ────────────────────
        case 'save_type':
            if (!$isAdmin) throw new Exception('Permission denied.');
            $data = $_POST;
            $data['user_id'] = $userId;
            $id = $bankManager->saveTransactionType($data);
            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Transaction type saved.']);
            break;

        // ── Approve Transfer ────────────────────────────────
        case 'approve_transfer':
            if (!in_array($userRole, ['Superadmin', 'admin', 'Bank Transaction Approver']))
                throw new Exception('Permission denied.');
            $trnId = (int)($_POST['id'] ?? 0);
            $bankManager->approveTransfer($trnId, $userId, $userName, $ipAddress);
            echo json_encode(['success' => true, 'message' => 'Transfer approved. Debit/credit entries created.']);
            break;

        // ── Reject Transfer ──────────────────────────────────
        case 'reject_transfer':
            if (!in_array($userRole, ['Superadmin', 'admin', 'Bank Transaction Approver']))
                throw new Exception('Permission denied.');
            $trnId  = (int)($_POST['id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            if (!$reason) throw new Exception('Rejection reason is required.');
            $bankManager->rejectTransfer($trnId, $reason, $userId, $userName, $ipAddress);
            echo json_encode(['success' => true, 'message' => 'Transfer rejected.']);
            break;

        // ── Bulk Unpost ──────────────────────────────────────
        case 'bulk_unpost':
            if (!in_array($userRole, ['Superadmin', 'admin']))
                throw new Exception('Permission denied.');
            $ids    = array_filter(array_map('intval', $_POST['ids'] ?? []));
            $reason = trim($_POST['reason'] ?? '');
            if (empty($ids))   throw new Exception('No transaction IDs provided.');
            if (!$reason)      throw new Exception('A reason is required for bulk unpost.');
            $result = $bankManager->bulkUnpost($ids, $userId, $userName, $reason, $ipAddress);
            echo json_encode([
                'success' => true,
                'message' => $result['success'] . ' transaction(s) unposted.'
                             . ($result['skipped'] > 0 ? ' ' . $result['skipped'] . ' skipped (already unposted).' : ''),
            ]);
            break;

        // ── Bulk Delete (Superadmin only) ─────────────────────
        case 'bulk_delete':
            if ($userRole !== 'Superadmin')
                throw new Exception('Permission denied. Only Superadmin can permanently delete transactions.');
            $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
            if (empty($ids)) throw new Exception('No transaction IDs provided.');
            $result = $bankManager->bulkDelete($ids, $userId, $userName, $ipAddress);
            echo json_encode([
                'success' => true,
                'message' => $result['success'] . ' transaction(s) permanently deleted.'
                             . ($result['skipped'] > 0 ? ' ' . $result['skipped'] . ' not found.' : ''),
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
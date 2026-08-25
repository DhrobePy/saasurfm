<?php
/**
 * BankManager.php
 * Core business logic for the Bank Transaction Module
 * ujjalfmc_saas - Ujjal Flour Mills ERP
 *
 * Fixed: Uses correct Database wrapper methods:
 *   ->query()->results()  for multiple rows  (was ->fetchAll())
 *   ->query()->first()    for single row     (was ->fetch())
 *   ->getPdo()->lastInsertId()               (was ->lastInsertId())
 */

require_once dirname(__DIR__) . '/core/classes/Database.php';
require_once dirname(__DIR__) . '/core/classes/TelegramNotifier.php';

class BankManager {

    private $db;
    private $telegram;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->telegram = $this->initTelegram();
    }

    private function initTelegram() {
        // Add to your config.php:
        //   define('TELEGRAM_BOT_TOKEN', 'your_bot_token_here');
        //   define('TELEGRAM_CHAT_ID',   'your_chat_id_here');
        try {
            if (defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID')
                && TELEGRAM_BOT_TOKEN && TELEGRAM_CHAT_ID) {
                return new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
            }
        } catch (Exception $e) {
            // Non-critical — module works without Telegram
        }
        return null;
    }

    // =========================================================
    // AUTO-NUMBER
    // =========================================================

    public function generateTransactionNumber($date = null) {
        if (!$date) $date = date('Y-m-d');
        $prefix = 'BTX-' . date('Ymd', strtotime($date)) . '-';
        $row = $this->db->query(
            "SELECT COUNT(*) as cnt FROM bank_transactions WHERE transaction_number LIKE ?",
            [$prefix . '%']
        )->first();
        $seq = ($row ? (int)$row->cnt : 0) + 1;
        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    // =========================================================
    // BANK ACCOUNTS
    // =========================================================

    public function getBankAccounts($activeOnly = true) {
        $where = $activeOnly ? "WHERE ba.status = 'active'" : '';
        return $this->db->query(
            "SELECT ba.*,
                COALESCE(
                    SUM(CASE WHEN bt.entry_type='credit' AND bt.status='approved' THEN bt.amount ELSE 0 END) -
                    SUM(CASE WHEN bt.entry_type='debit'  AND bt.status='approved' THEN bt.amount ELSE 0 END),
                0) AS module_balance
             FROM bank_tx_accounts ba
             LEFT JOIN bank_transactions bt ON bt.bank_tx_account_id = ba.id
             $where
             GROUP BY ba.id
             ORDER BY ba.bank_name, ba.account_name"
        )->results();
    }

    public function getBankAccountById($id) {
        return $this->db->query(
            "SELECT * FROM bank_tx_accounts WHERE id = ?",
            [(int)$id]
        )->first();
    }

    public function getAccountBalance($bankAccountId) {
        $row = $this->db->query(
            "SELECT
                COALESCE(SUM(CASE WHEN entry_type='credit' AND status='approved' THEN amount ELSE 0 END),0) -
                COALESCE(SUM(CASE WHEN entry_type='debit'  AND status='approved' THEN amount ELSE 0 END),0) AS balance
             FROM bank_transactions WHERE bank_tx_account_id = ?",
            [(int)$bankAccountId]
        )->first();
        return $row ? (float)$row->balance : 0.00;
    }

    // =========================================================
    // TRANSACTION TYPES
    // =========================================================

    public function getTransactionTypes($activeOnly = true) {
        $where = $activeOnly ? "WHERE is_active = 1" : '';
        return $this->db->query(
            "SELECT * FROM bank_tx_transaction_types $where ORDER BY nature, name"
        )->results();
    }

    public function saveTransactionType($data) {
        if (!empty($data['id'])) {
            $this->db->query(
                "UPDATE bank_tx_transaction_types SET name=?, nature=?, description=?, is_active=?, updated_at=NOW() WHERE id=?",
                [
                    trim($data['name']),
                    $data['nature'],
                    $data['description'] ?? null,
                    isset($data['is_active']) ? 1 : 0,
                    (int)$data['id']
                ]
            );
            return (int)$data['id'];
        } else {
            $this->db->query(
                "INSERT INTO bank_tx_transaction_types (name, nature, description, created_by_user_id) VALUES (?,?,?,?)",
                [
                    trim($data['name']),
                    $data['nature'],
                    $data['description'] ?? null,
                    (int)$data['user_id']
                ]
            );
            return $this->db->getPdo()->lastInsertId();
        }
    }

    // =========================================================
    // CREATE TRANSACTION
    // =========================================================

    public function createTransaction($data, $userId, $userName, $ipAddress = null) {
        $required = ['transaction_date', 'entry_type', 'bank_tx_account_id', 'amount'];
        foreach ($required as $field) {
            if (empty($data[$field])) throw new Exception("Field '$field' is required.");
        }
        if (!in_array($data['entry_type'], ['debit', 'credit'])) throw new Exception("Invalid entry type.");
        if ((float)$data['amount'] <= 0) throw new Exception("Amount must be greater than zero.");

        $txNumber = $this->generateTransactionNumber($data['transaction_date']);

        $this->db->query(
            "INSERT INTO bank_transactions
                (transaction_number, transaction_date, entry_type, bank_tx_account_id,
                 transaction_type_id, amount, reference_number, cheque_number,
                 payee_payer_name, description, special_note, branch_id,
                 status, created_by_user_id, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'pending',?,NOW(),NOW())",
            [
                $txNumber,
                $data['transaction_date'],
                $data['entry_type'],
                (int)$data['bank_tx_account_id'],
                !empty($data['transaction_type_id']) ? (int)$data['transaction_type_id'] : null,
                (float)$data['amount'],
                $data['reference_number'] ?? null,
                $data['cheque_number']    ?? null,
                $data['payee_payer_name'] ?? null,
                $data['description']      ?? null,
                $data['special_note']     ?? null,
                !empty($data['branch_id']) ? (int)$data['branch_id'] : null,
                (int)$userId
            ]
        );

        $txId = $this->db->getPdo()->lastInsertId();

        $this->writeAuditLog($txId, 'created', $userId, $userName, $ipAddress, null, [
            'transaction_number' => $txNumber,
            'amount'             => $data['amount'],
            'entry_type'         => $data['entry_type'],
            'bank_tx_account_id' => $data['bank_tx_account_id'],
        ]);

        $this->sendTelegramNotification('created', $txId, $txNumber, $data, $userName);

        return ['id' => $txId, 'transaction_number' => $txNumber];
    }

    // =========================================================
    // GET TRANSACTIONS (with filters)
    // =========================================================

    public function getTransactions($filters = [], $userId = null, $role = null) {
        $where  = ['1=1'];
        $params = [];

        $adminRoles = ['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg'];
        if (!in_array($role, $adminRoles)) {
            $where[]  = 'bt.created_by_user_id = ?';
            $params[] = (int)$userId;
        }

        if (!empty($filters["bank_tx_account_id"]))     { $where[] = 'bt.bank_tx_account_id = ?';      $params[] = (int)$filters["bank_tx_account_id"]; }
        if (!empty($filters['entry_type']))           { $where[] = 'bt.entry_type = ?';            $params[] = $filters['entry_type']; }
        if (!empty($filters['status'])) {
            $where[]  = 'bt.status = ?';
            $params[] = $filters['status'];
        } else {
            // Exclude unposted from default view — only visible when explicitly filtered
            $where[] = "bt.status != 'unposted'";
        }
        if (!empty($filters['date_from']))            { $where[] = 'bt.transaction_date >= ?';     $params[] = $filters['date_from']; }
        if (!empty($filters['date_to']))              { $where[] = 'bt.transaction_date <= ?';     $params[] = $filters['date_to']; }
        if (!empty($filters['transaction_type_id']))  { $where[] = 'bt.transaction_type_id = ?';  $params[] = (int)$filters['transaction_type_id']; }

        if (!empty($filters['keyword'])) {
            $where[]  = '(bt.reference_number LIKE ? OR bt.cheque_number LIKE ? OR bt.payee_payer_name LIKE ? OR bt.transaction_number LIKE ? OR bt.description LIKE ?)';
            $kw       = '%' . $filters['keyword'] . '%';
            $params   = array_merge($params, [$kw, $kw, $kw, $kw, $kw]);
        }

        $whereStr = implode(' AND ', $where);
        $limit    = (int)($filters['limit']  ?? 50);
        $offset   = (int)($filters['offset'] ?? 0);

        return $this->db->query(
            "SELECT bt.*,
                ba.bank_name, ba.account_name, ba.account_number,
                btt.name AS type_name, btt.nature AS type_nature,
                u.display_name  AS created_by_name,
                au.display_name AS approved_by_name,
                br.name AS branch_name
             FROM bank_transactions bt
             LEFT JOIN bank_tx_accounts ba  ON ba.id  = bt.bank_tx_account_id
             LEFT JOIN bank_tx_transaction_types btt ON btt.id = bt.transaction_type_id
             LEFT JOIN users u  ON u.id  = bt.created_by_user_id
             LEFT JOIN users au ON au.id = bt.approved_by_user_id
             LEFT JOIN branches br ON br.id = bt.branch_id
             WHERE $whereStr
             ORDER BY bt.transaction_date DESC, bt.id DESC
             LIMIT $limit OFFSET $offset",
            $params
        )->results();
    }

    public function countTransactions($filters = [], $userId = null, $role = null) {
        $where  = ['1=1'];
        $params = [];

        $adminRoles = ['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg'];
        if (!in_array($role, $adminRoles)) {
            $where[]  = 'bt.created_by_user_id = ?';
            $params[] = (int)$userId;
        }

        if (!empty($filters["bank_tx_account_id"]))     { $where[] = 'bt.bank_tx_account_id = ?';      $params[] = (int)$filters["bank_tx_account_id"]; }
        if (!empty($filters['entry_type']))           { $where[] = 'bt.entry_type = ?';            $params[] = $filters['entry_type']; }
        if (!empty($filters['status']))               { $where[] = 'bt.status = ?';                $params[] = $filters['status']; }
        if (!empty($filters['date_from']))            { $where[] = 'bt.transaction_date >= ?';     $params[] = $filters['date_from']; }
        if (!empty($filters['date_to']))              { $where[] = 'bt.transaction_date <= ?';     $params[] = $filters['date_to']; }
        if (!empty($filters['transaction_type_id']))  { $where[] = 'bt.transaction_type_id = ?';  $params[] = (int)$filters['transaction_type_id']; }

        if (!empty($filters['keyword'])) {
            $where[]  = '(bt.reference_number LIKE ? OR bt.cheque_number LIKE ? OR bt.payee_payer_name LIKE ? OR bt.transaction_number LIKE ? OR bt.description LIKE ?)';
            $kw       = '%' . $filters['keyword'] . '%';
            $params   = array_merge($params, [$kw, $kw, $kw, $kw, $kw]);
        }

        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM bank_transactions bt WHERE " . implode(' AND ', $where),
            $params
        )->first();

        return $row ? (int)$row->cnt : 0;
    }

    public function getTransactionById($id) {
        return $this->db->query(
            "SELECT bt.*,
                ba.bank_name, ba.account_name, ba.account_number, ba.branch_name AS bank_branch,
                btt.name AS type_name, btt.nature AS type_nature,
                u.display_name  AS created_by_name,
                au.display_name AS approved_by_name,
                br.name AS branch_name
             FROM bank_transactions bt
             LEFT JOIN bank_tx_accounts ba  ON ba.id  = bt.bank_tx_account_id
             LEFT JOIN bank_tx_transaction_types btt ON btt.id = bt.transaction_type_id
             LEFT JOIN users u  ON u.id  = bt.created_by_user_id
             LEFT JOIN users au ON au.id = bt.approved_by_user_id
             LEFT JOIN branches br ON br.id = bt.branch_id
             WHERE bt.id = ?",
            [(int)$id]
        )->first();
    }

    // =========================================================
    // APPROVE / REJECT
    // =========================================================

    public function approveTransaction($txId, $userId, $userName, $ipAddress = null) {
        $tx = $this->getTransactionById($txId);
        if (!$tx) throw new Exception("Transaction not found.");
        if ($tx->status !== 'pending') throw new Exception("Only pending transactions can be approved.");

        $this->db->query(
            "UPDATE bank_transactions SET status='approved', approved_by_user_id=?, approved_at=NOW(), updated_by_user_id=?, updated_at=NOW() WHERE id=?",
            [(int)$userId, (int)$userId, (int)$txId]
        );

        $this->writeAuditLog($txId, 'approved', $userId, $userName, $ipAddress,
            ['status' => $tx->status], ['status' => 'approved']
        );
        $this->sendTelegramNotification('approved', $txId, $tx->transaction_number,
            ['amount' => $tx->amount, 'entry_type' => $tx->entry_type, 'bank_tx_account_id' => $tx->bank_tx_account_id], $userName
        );
        return true;
    }

    public function rejectTransaction($txId, $reason, $userId, $userName, $ipAddress = null) {
        $tx = $this->getTransactionById($txId);
        if (!$tx) throw new Exception("Transaction not found.");

        $this->db->query(
            "UPDATE bank_transactions SET status='rejected', rejection_reason=?, approved_by_user_id=?, approved_at=NOW(), updated_by_user_id=?, updated_at=NOW() WHERE id=?",
            [$reason, (int)$userId, (int)$userId, (int)$txId]
        );

        $this->writeAuditLog($txId, 'rejected', $userId, $userName, $ipAddress,
            ['status' => $tx->status], ['status' => 'rejected', 'reason' => $reason]
        );
        return true;
    }

    // =========================================================
    // UPDATE TRANSACTION
    // =========================================================

    public function updateTransaction($txId, $data, $userId, $userName, $ipAddress = null) {
        $old = $this->getTransactionById($txId);
        if (!$old) throw new Exception("Transaction not found.");

        $this->db->query(
            "UPDATE bank_transactions SET
                transaction_date=?, entry_type=?, bank_tx_account_id=?,
                transaction_type_id=?, amount=?, reference_number=?,
                cheque_number=?, payee_payer_name=?, description=?,
                special_note=?, branch_id=?,
                status='pending', updated_by_user_id=?, updated_at=NOW()
             WHERE id=?",
            [
                $data['transaction_date'],
                $data['entry_type'],
                (int)$data['bank_tx_account_id'],
                !empty($data['transaction_type_id']) ? (int)$data['transaction_type_id'] : null,
                (float)$data['amount'],
                $data['reference_number'] ?? null,
                $data['cheque_number']    ?? null,
                $data['payee_payer_name'] ?? null,
                $data['description']      ?? null,
                $data['special_note']     ?? null,
                !empty($data['branch_id']) ? (int)$data['branch_id'] : null,
                (int)$userId,
                (int)$txId
            ]
        );

        $this->writeAuditLog($txId, 'updated', $userId, $userName, $ipAddress, (array)$old, $data);
        return true;
    }

    // =========================================================
    // UNPOST TRANSACTION (soft delete — status = 'unposted')
    // =========================================================

    public function unpostTransaction($txId, $userId, $userName, $ipAddress = null, $role = null) {
        $tx = $this->getTransactionById($txId);
        if (!$tx) throw new Exception("Transaction not found.");
        if ($tx->status === 'unposted') throw new Exception("Transaction is already unposted.");

        $isSuperadmin = ($role === 'Superadmin');

        // Only Superadmin can unpost approved transactions
        if ($tx->status === 'approved' && !$isSuperadmin) {
            throw new Exception("Only Superadmin can unpost an approved transaction.");
        }

        $prevStatus = $tx->status;
        $notes = $isSuperadmin && $prevStatus === 'approved'
            ? 'SUPERADMIN OVERRIDE: Approved transaction marked as unposted.'
            : 'Transaction marked as unposted by ' . $userName;

        // Update status to unposted
        $this->db->query(
            "UPDATE bank_transactions SET status='unposted', updated_by_user_id=?, updated_at=NOW() WHERE id=?",
            [(int)$userId, (int)$txId]
        );

        // Audit log
        $this->writeAuditLog(
            $txId, 'unposted', $userId, $userName, $ipAddress,
            ['status' => $prevStatus],
            ['status' => 'unposted'],
            $notes
        );

        // Telegram notification
        $this->sendTelegramNotification('unposted', $txId, $tx->transaction_number, [
            'entry_type' => $tx->entry_type,
            'amount'     => $tx->amount,
            'prev_status'=> $prevStatus,
        ], $userName);

        return true;
    }

    // =========================================================
    // DASHBOARD / KPI DATA
    // =========================================================

    public function getDashboardKPIs() {
        $monthly = $this->db->query(
            "SELECT
                COUNT(*) AS total_transactions,
                COUNT(CASE WHEN status='pending'  THEN 1 END) AS pending_count,
                COUNT(CASE WHEN status='approved' THEN 1 END) AS approved_count,
                SUM(CASE WHEN entry_type='credit' AND status='approved' THEN amount ELSE 0 END) AS total_inflow,
                SUM(CASE WHEN entry_type='debit'  AND status='approved' THEN amount ELSE 0 END) AS total_outflow,
                SUM(CASE WHEN entry_type='credit' AND status='approved' THEN  amount
                         WHEN entry_type='debit'  AND status='approved' THEN -amount
                         ELSE 0 END) AS net_flow
             FROM bank_transactions
             WHERE MONTH(transaction_date) = MONTH(CURDATE())
               AND YEAR(transaction_date)  = YEAR(CURDATE())"
        )->first();

        $alltime = $this->db->query(
            "SELECT
                SUM(CASE WHEN entry_type='credit' AND status='approved' THEN amount ELSE 0 END) AS total_inflow,
                SUM(CASE WHEN entry_type='debit'  AND status='approved' THEN amount ELSE 0 END) AS total_outflow
             FROM bank_transactions"
        )->first();

        $accounts     = $this->getBankAccounts(true);
        $recent       = $this->getTransactions(['limit' => 5], null, 'Superadmin');

        $trend = $this->db->query(
            "SELECT
                DATE_FORMAT(transaction_date,'%Y-%m') AS month_label,
                SUM(CASE WHEN entry_type='credit' AND status='approved' THEN amount ELSE 0 END) AS inflow,
                SUM(CASE WHEN entry_type='debit'  AND status='approved' THEN amount ELSE 0 END) AS outflow
             FROM bank_transactions
             WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
             GROUP BY month_label
             ORDER BY month_label ASC"
        )->results();

        $accountStats = $this->db->query(
            "SELECT ba.bank_name, ba.account_name, ba.account_number,
                SUM(CASE WHEN bt.entry_type='credit' AND bt.status='approved' THEN bt.amount ELSE 0 END) AS total_in,
                SUM(CASE WHEN bt.entry_type='debit'  AND bt.status='approved' THEN bt.amount ELSE 0 END) AS total_out,
                COUNT(bt.id) AS tx_count,
                MAX(bt.transaction_date) AS last_activity
             FROM bank_tx_accounts ba
             LEFT JOIN bank_transactions bt ON bt.bank_tx_account_id = ba.id
             WHERE ba.status = 'active'
             GROUP BY ba.id
             ORDER BY total_in DESC"
        )->results();

        return compact('monthly', 'alltime', 'accounts', 'recent', 'trend', 'accountStats');
    }

    // =========================================================
    // AI CONTEXT
    // =========================================================

    public function getAIContext() {
        $kpis = $this->getDashboardKPIs();

        $accountList = [];
        foreach ($kpis['accounts'] as $a) {
            $bal           = (float)$a->opening_balance + (float)$a->module_balance;
            $accountList[] = [
                'bank'    => $a->bank_name,
                'account' => $a->account_name . ' (' . $a->account_number . ')',
                'balance' => $bal,
                'type'    => $a->account_type,
            ];
        }

        $trendList = [];
        foreach ($kpis['trend'] as $t) {
            $trendList[] = [
                'month'   => $t->month_label,
                'inflow'  => (float)$t->inflow,
                'outflow' => (float)$t->outflow,
                'net'     => (float)$t->inflow - (float)$t->outflow,
            ];
        }

        return [
            'monthly_inflow'  => (float)($kpis['monthly']->total_inflow  ?? 0),
            'monthly_outflow' => (float)($kpis['monthly']->total_outflow ?? 0),
            'monthly_net'     => (float)($kpis['monthly']->net_flow      ?? 0),
            'pending_count'   => (int)($kpis['monthly']->pending_count   ?? 0),
            'accounts'        => $accountList,
            'trend_6m'        => $trendList,
        ];
    }

    // =========================================================
    // EXCEL EXPORT
    // =========================================================

    public function getTransactionsForExport($filters = [], $userId = null, $role = null) {
        $filters['limit']  = 10000;
        $filters['offset'] = 0;
        return $this->getTransactions($filters, $userId, $role);
    }

    // =========================================================
    // AUDIT LOG
    // =========================================================

    public function writeAuditLog($txId, $action, $userId, $userName, $ipAddress, $oldVals = null, $newVals = null, $notes = null) {
        $this->db->query(
            "INSERT INTO bank_tx_audit_log
                (transaction_id, action, action_by_user_id, action_by_username, ip_address, old_values, new_values, notes, created_at)
             VALUES (?,?,?,?,?,?,?,?,NOW())",
            [
                $txId    ? (int)$txId : null,
                $action,
                (int)$userId,
                $userName,
                $ipAddress,
                $oldVals ? json_encode($oldVals, JSON_UNESCAPED_UNICODE) : null,
                $newVals ? json_encode($newVals, JSON_UNESCAPED_UNICODE) : null,
                $notes,
            ]
        );
    }

    public function getAuditLog($txId) {
        return $this->db->query(
            "SELECT * FROM bank_tx_audit_log WHERE transaction_id = ? ORDER BY created_at DESC",
            [(int)$txId]
        )->results();
    }

    // =========================================================
    // TELEGRAM
    // =========================================================

    private function sendTelegramNotification($action, $txId, $txNumber, $data, $userName) {
        if (!$this->telegram) return;

        $entryEmoji  = ($data['entry_type'] ?? '') === 'credit' ? '🟢' : '🔴';
        $actionLabel = ucfirst($action);

        $msg = "🏦 <b>Bank Transaction {$actionLabel}</b>\n" .
               "━━━━━━━━━━━━━━━━━━\n" .
               "📋 Ref: <code>{$txNumber}</code>\n" .
               "{$entryEmoji} Type: " . strtoupper($data['entry_type'] ?? '') . "\n" .
               "💵 Amount: ৳" . number_format((float)($data['amount'] ?? 0), 2) . "\n" .
               "👤 By: {$userName}\n" .
               "⏰ " . date('d M Y H:i');

        try {
            $this->telegram->sendMessage($msg);
        } catch (Exception $e) {
            // Telegram errors are non-fatal
        }
    }

    // =========================================================
    // INTERNAL TRANSFERS
    // =========================================================

    // Role groups used across transfer methods
    private function transferApproverRoles() {
        return ['Superadmin', 'admin', 'bank-approver'];
    }
    private function transferInitiatorRoles() {
        return ['Superadmin', 'admin', 'bank-initiator', 'bank-approver',
                'Accounts', 'accounts-demra', 'accounts-srg'];
    }

    public function generateTransferNumber($date = null) {
        if (!$date) $date = date('Y-m-d');
        $prefix = 'TRF-' . date('Ymd', strtotime($date)) . '-';
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM bank_tx_transfers WHERE transfer_number LIKE ?",
            [$prefix . '%']
        )->first();
        $seq = ($row ? (int)$row->cnt : 0) + 1;
        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    public function createTransfer($data, $userId, $userName, $ipAddress = null) {
        $fromId = (int)($data['from_account_id'] ?? 0);
        $toId   = (int)($data['to_account_id']   ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        $date   = $data['transfer_date'] ?? date('Y-m-d');

        if (!$fromId || !$toId)       throw new Exception('Both source and destination accounts are required.');
        if ($fromId === $toId)        throw new Exception('Source and destination accounts must be different.');
        if ($amount <= 0)             throw new Exception('Amount must be greater than zero.');

        $trnNumber = $this->generateTransferNumber($date);

        $this->db->query(
            "INSERT INTO bank_tx_transfers
                (transfer_number, transfer_date, from_account_id, to_account_id,
                 amount, reference_number, description, notes,
                 status, initiated_by_user_id, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,'pending',?,NOW(),NOW())",
            [
                $trnNumber, $date, $fromId, $toId,
                $amount,
                $data['reference_number'] ?? null,
                $data['description']      ?? null,
                $data['notes']            ?? null,
                (int)$userId,
            ]
        );
        $trnId = $this->db->getPdo()->lastInsertId();

        $this->writeAuditLog(null, 'transfer_created', $userId, $userName, $ipAddress, null, [
            'transfer_id'     => $trnId,
            'transfer_number' => $trnNumber,
            'from_account_id' => $fromId,
            'to_account_id'   => $toId,
            'amount'          => $amount,
        ]);

        $this->sendTransferTelegram('created', $trnNumber, $fromId, $toId, $amount, $userName);

        return ['id' => $trnId, 'transfer_number' => $trnNumber];
    }

    public function approveTransfer($trnId, $userId, $userName, $ipAddress = null) {
        $trn = $this->getTransferById($trnId);
        if (!$trn)                       throw new Exception('Transfer not found.');
        if ($trn->status !== 'pending')  throw new Exception('Only pending transfers can be approved.');

        // Get type ID for Internal Transfer
        $typeRow = $this->db->query(
            "SELECT id FROM bank_tx_transaction_types WHERE name = 'Internal Transfer' LIMIT 1"
        )->first();
        $typeId = $typeRow ? (int)$typeRow->id : null;

        $desc = 'Internal Transfer ' . $trn->transfer_number;
        if ($trn->description) $desc .= ' — ' . $trn->description;

        // Create DEBIT on source account
        $this->db->query(
            "INSERT INTO bank_transactions
                (transaction_number, transaction_date, entry_type, bank_tx_account_id,
                 transaction_type_id, amount, reference_number, description,
                 status, approved_by_user_id, approved_at, created_by_user_id, created_at, updated_at)
             VALUES (?,?,'debit',?,?,?,?,?,'approved',?,NOW(),?,NOW(),NOW())",
            [
                $trn->transfer_number . '-DR',
                $trn->transfer_date,
                (int)$trn->from_account_id,
                $typeId,
                (float)$trn->amount,
                $trn->reference_number,
                $desc . ' [OUT → ' . $trn->to_account_name . ']',
                (int)$userId,
                (int)$userId,
            ]
        );
        $fromTxId = $this->db->getPdo()->lastInsertId();

        // Create CREDIT on destination account
        $this->db->query(
            "INSERT INTO bank_transactions
                (transaction_number, transaction_date, entry_type, bank_tx_account_id,
                 transaction_type_id, amount, reference_number, description,
                 status, approved_by_user_id, approved_at, created_by_user_id, created_at, updated_at)
             VALUES (?,?,'credit',?,?,?,?,?,'approved',?,NOW(),?,NOW(),NOW())",
            [
                $trn->transfer_number . '-CR',
                $trn->transfer_date,
                (int)$trn->to_account_id,
                $typeId,
                (float)$trn->amount,
                $trn->reference_number,
                $desc . ' [IN ← ' . $trn->from_account_name . ']',
                (int)$userId,
                (int)$userId,
            ]
        );
        $toTxId = $this->db->getPdo()->lastInsertId();

        // Mark transfer approved + link tx IDs
        $this->db->query(
            "UPDATE bank_tx_transfers SET status='approved', from_tx_id=?, to_tx_id=?,
             approved_by_user_id=?, approved_at=NOW(), updated_by_user_id=?, updated_at=NOW()
             WHERE id=?",
            [(int)$fromTxId, (int)$toTxId, (int)$userId, (int)$userId, (int)$trnId]
        );

        $this->writeAuditLog(null, 'transfer_approved', $userId, $userName, $ipAddress,
            ['status' => 'pending'],
            ['status' => 'approved', 'from_tx_id' => $fromTxId, 'to_tx_id' => $toTxId],
            'Transfer ' . $trn->transfer_number . ' approved — debit/credit entries created.'
        );

        $this->sendTransferTelegram('approved', $trn->transfer_number,
            $trn->from_account_id, $trn->to_account_id, $trn->amount, $userName);

        return true;
    }

    public function rejectTransfer($trnId, $reason, $userId, $userName, $ipAddress = null) {
        $trn = $this->getTransferById($trnId);
        if (!$trn)                      throw new Exception('Transfer not found.');
        if ($trn->status !== 'pending') throw new Exception('Only pending transfers can be rejected.');

        $this->db->query(
            "UPDATE bank_tx_transfers SET status='rejected', rejection_reason=?,
             approved_by_user_id=?, approved_at=NOW(), updated_by_user_id=?, updated_at=NOW()
             WHERE id=?",
            [$reason, (int)$userId, (int)$userId, (int)$trnId]
        );

        $this->writeAuditLog(null, 'transfer_rejected', $userId, $userName, $ipAddress,
            ['status' => 'pending'], ['status' => 'rejected', 'reason' => $reason]
        );

        $this->sendTransferTelegram('rejected', $trn->transfer_number,
            $trn->from_account_id, $trn->to_account_id, $trn->amount, $userName, $reason);

        return true;
    }

    public function getTransferById($id) {
        return $this->db->query(
            "SELECT t.*,
                fa.bank_name AS from_bank_name, fa.account_name AS from_account_name, fa.account_number AS from_account_number,
                ta.bank_name AS to_bank_name,   ta.account_name AS to_account_name,   ta.account_number AS to_account_number,
                u.display_name  AS initiated_by_name,
                au.display_name AS approved_by_name
             FROM bank_tx_transfers t
             LEFT JOIN bank_tx_accounts fa ON fa.id = t.from_account_id
             LEFT JOIN bank_tx_accounts ta ON ta.id = t.to_account_id
             LEFT JOIN users u  ON u.id  = t.initiated_by_user_id
             LEFT JOIN users au ON au.id = t.approved_by_user_id
             WHERE t.id = ?",
            [(int)$id]
        )->first();
    }

    public function getTransfers($filters = [], $userId = null, $role = null) {
        $where  = ['1=1'];
        $params = [];

        $approverRoles = $this->transferApproverRoles();
        $initiatorRoles = $this->transferInitiatorRoles();

        // Non-initiator-roles can only see own transfers
        if (!in_array($role, $initiatorRoles)) {
            $where[]  = 't.initiated_by_user_id = ?';
            $params[] = (int)$userId;
        }

        if (!empty($filters['from_account_id'])) { $where[] = 't.from_account_id = ?'; $params[] = (int)$filters['from_account_id']; }
        if (!empty($filters['to_account_id']))   { $where[] = 't.to_account_id = ?';   $params[] = (int)$filters['to_account_id']; }
        if (!empty($filters['status']))           { $where[] = 't.status = ?';           $params[] = $filters['status']; }
        if (!empty($filters['date_from']))        { $where[] = 't.transfer_date >= ?';   $params[] = $filters['date_from']; }
        if (!empty($filters['date_to']))          { $where[] = 't.transfer_date <= ?';   $params[] = $filters['date_to']; }
        if (!empty($filters['keyword'])) {
            $where[]  = '(t.transfer_number LIKE ? OR t.reference_number LIKE ? OR t.description LIKE ?)';
            $kw       = '%' . $filters['keyword'] . '%';
            $params   = array_merge($params, [$kw, $kw, $kw]);
        }

        $whereStr = implode(' AND ', $where);
        $limit    = (int)($filters['limit']  ?? 50);
        $offset   = (int)($filters['offset'] ?? 0);

        return $this->db->query(
            "SELECT t.*,
                fa.bank_name AS from_bank_name, fa.account_name AS from_account_name, fa.account_number AS from_account_number,
                ta.bank_name AS to_bank_name,   ta.account_name AS to_account_name,   ta.account_number AS to_account_number,
                u.display_name  AS initiated_by_name,
                au.display_name AS approved_by_name
             FROM bank_tx_transfers t
             LEFT JOIN bank_tx_accounts fa ON fa.id = t.from_account_id
             LEFT JOIN bank_tx_accounts ta ON ta.id = t.to_account_id
             LEFT JOIN users u  ON u.id  = t.initiated_by_user_id
             LEFT JOIN users au ON au.id = t.approved_by_user_id
             WHERE $whereStr
             ORDER BY t.transfer_date DESC, t.id DESC
             LIMIT $limit OFFSET $offset",
            $params
        )->results();
    }

    public function countTransfers($filters = [], $userId = null, $role = null) {
        $where  = ['1=1'];
        $params = [];

        $initiatorRoles = $this->transferInitiatorRoles();
        if (!in_array($role, $initiatorRoles)) {
            $where[]  = 't.initiated_by_user_id = ?';
            $params[] = (int)$userId;
        }

        if (!empty($filters['from_account_id'])) { $where[] = 't.from_account_id = ?'; $params[] = (int)$filters['from_account_id']; }
        if (!empty($filters['to_account_id']))   { $where[] = 't.to_account_id = ?';   $params[] = (int)$filters['to_account_id']; }
        if (!empty($filters['status']))           { $where[] = 't.status = ?';           $params[] = $filters['status']; }
        if (!empty($filters['date_from']))        { $where[] = 't.transfer_date >= ?';   $params[] = $filters['date_from']; }
        if (!empty($filters['date_to']))          { $where[] = 't.transfer_date <= ?';   $params[] = $filters['date_to']; }
        if (!empty($filters['keyword'])) {
            $where[]  = '(t.transfer_number LIKE ? OR t.reference_number LIKE ? OR t.description LIKE ?)';
            $kw       = '%' . $filters['keyword'] . '%';
            $params   = array_merge($params, [$kw, $kw, $kw]);
        }

        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM bank_tx_transfers t WHERE " . implode(' AND ', $where),
            $params
        )->first();
        return $row ? (int)$row->cnt : 0;
    }

    private function sendTransferTelegram($action, $trnNumber, $fromId, $toId, $amount, $userName, $reason = null) {
        if (!$this->telegram) return;

        $emoji = match($action) {
            'created'  => '🔄',
            'approved' => '✅',
            'rejected' => '❌',
            default    => '🏦',
        };

        $from = $this->getBankAccountById($fromId);
        $to   = $this->getBankAccountById($toId);

        $msg = "{$emoji} <b>Internal Transfer " . ucfirst($action) . "</b>
" .
               "━━━━━━━━━━━━━━━━━━━━━
" .
               "📋 Ref: <code>{$trnNumber}</code>
" .
               "💸 From: " . ($from ? $from->bank_name . ' — ' . $from->account_name : '#' . $fromId) . "
" .
               "🏦 To:   " . ($to   ? $to->bank_name   . ' — ' . $to->account_name   : '#' . $toId)   . "
" .
               "💵 Amount: ৳" . number_format($amount, 2) . "
";
        if ($reason) $msg .= "📝 Reason: " . $reason . "
";
        $msg .= "👤 By: {$userName}
" .
                "⏰ " . date('d M Y H:i');

        try { $this->telegram->sendMessage($msg); } catch (Exception $e) {}
    }

}
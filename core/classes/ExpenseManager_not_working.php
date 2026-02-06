<?php
/**
 * ExpenseManager Class
 * Handles expense voucher operations with approval workflow
 */

class ExpenseManager {
    private $db;
    private $userId;

    public function __construct($db, $userId) {
        $this->db = $db;
        $this->userId = $userId;
    }

    /**
     * Create expense voucher with pending status
     */
    public function createExpenseVoucher($data) {
        try {
            $this->db->beginTransaction();

            // Generate voucher number
            $voucherNumber = $this->generateVoucherNumber();

            $sql = "INSERT INTO expense_vouchers (
                voucher_number,
                expense_date,
                category_id,
                subcategory_id,
                handled_by_person,
                employee_id,
                unit_quantity,
                per_unit_cost,
                total_amount,
                remarks,
                payment_method,
                bank_account_id,
                cash_account_id,
                payment_account_name,
                payment_reference,
                expense_account_id,
                branch_id,
                status,
                created_by_user_id,
                telegram_notified
            ) VALUES (
                :voucher_number,
                :expense_date,
                :category_id,
                :subcategory_id,
                :handled_by_person,
                :employee_id,
                :unit_quantity,
                :per_unit_cost,
                :total_amount,
                :remarks,
                :payment_method,
                :bank_account_id,
                :cash_account_id,
                :payment_account_name,
                :payment_reference,
                :expense_account_id,
                :branch_id,
                'pending',
                :created_by_user_id,
                FALSE
            )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'voucher_number' => $voucherNumber,
                'expense_date' => $data['expense_date'],
                'category_id' => $data['category_id'],
                'subcategory_id' => $data['subcategory_id'],
                'handled_by_person' => $data['handled_by_person'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'unit_quantity' => $data['unit_quantity'] ?? 0,
                'per_unit_cost' => $data['per_unit_cost'] ?? 0,
                'total_amount' => $data['total_amount'],
                'remarks' => $data['remarks'] ?? null,
                'payment_method' => $data['payment_method'],
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'cash_account_id' => $data['cash_account_id'] ?? null,
                'payment_account_name' => $data['payment_account_name'] ?? null,
                'payment_reference' => $data['payment_reference'] ?? null,
                'expense_account_id' => $data['expense_account_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'created_by_user_id' => $this->userId
            ]);

            $voucherId = $this->db->lastInsertId();

            // Log action
            $this->logAction($voucherId, 'created', null, 'pending', 'Expense voucher created');

            $this->db->commit();

            // Send Telegram notification
            $this->sendTelegramNotification($voucherId, 'created');

            return [
                'success' => true,
                'voucher_id' => $voucherId,
                'voucher_number' => $voucherNumber,
                'message' => 'Expense voucher created successfully and awaiting approval'
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("ExpenseManager::createExpenseVoucher Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create expense voucher: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Approve expense voucher
     */
    public function approveExpense($voucherId, $remarks = null) {
        try {
            $this->db->beginTransaction();

            // Get voucher details
            $voucher = $this->getExpenseVoucherById($voucherId);
            if (!$voucher) {
                throw new Exception('Voucher not found');
            }

            if ($voucher->status !== 'pending') {
                throw new Exception('Only pending vouchers can be approved');
            }

            // Update voucher status
            $sql = "UPDATE expense_vouchers 
                    SET status = 'approved',
                        approved_by_user_id = :approved_by,
                        approved_at = NOW()
                    WHERE id = :id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'approved_by' => $this->userId,
                'id' => $voucherId
            ]);

            // Create accounting entries
            $this->createAccountingEntries($voucherId);

            // Log action
            $this->logAction($voucherId, 'approved', 'pending', 'approved', $remarks);

            $this->db->commit();

            // Send Telegram notification
            $this->sendTelegramNotification($voucherId, 'approved');

            return [
                'success' => true,
                'message' => 'Expense voucher approved successfully'
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("ExpenseManager::approveExpense Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to approve expense: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get pending expenses with filters
     */
    public function getPendingExpenses($filters = []) {
        $sql = "SELECT 
                    ev.*,
                    ec.category_name,
                    esc.subcategory_name,
                    esc.unit_of_measurement,
                    b.name as branch_name,
                    u.display_name as created_by_name
                FROM expense_vouchers ev
                LEFT JOIN expense_categories ec ON ev.category_id = ec.id
                LEFT JOIN expense_subcategories esc ON ev.subcategory_id = esc.id
                LEFT JOIN branches b ON ev.branch_id = b.id
                LEFT JOIN users u ON ev.created_by_user_id = u.id
                WHERE ev.status = 'pending'";

        $params = [];

        // Date filter
        if (!empty($filters['date_from'])) {
            $sql .= " AND ev.expense_date >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND ev.expense_date <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        // Branch filter
        if (!empty($filters['branch_id'])) {
            $sql .= " AND ev.branch_id = :branch_id";
            $params['branch_id'] = $filters['branch_id'];
        }

        // Category filter
        if (!empty($filters['category_id'])) {
            $sql .= " AND ev.category_id = :category_id";
            $params['category_id'] = $filters['category_id'];
        }

        // Subcategory filter
        if (!empty($filters['subcategory_id'])) {
            $sql .= " AND ev.subcategory_id = :subcategory_id";
            $params['subcategory_id'] = $filters['subcategory_id'];
        }

        // Keyword search
        if (!empty($filters['keyword'])) {
            $sql .= " AND (ev.voucher_number LIKE :keyword 
                      OR ev.remarks LIKE :keyword 
                      OR ev.payment_reference LIKE :keyword)";
            $params['keyword'] = '%' . $filters['keyword'] . '%';
        }

        // Created by filter
        if (!empty($filters['created_by'])) {
            $sql .= " AND ev.created_by_user_id = :created_by";
            $params['created_by'] = $filters['created_by'];
        }

        $sql .= " ORDER BY ev.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Get expense history (approved, rejected, cancelled)
     */
    public function getExpenseHistory($filters = []) {
        $sql = "SELECT 
                    ev.*,
                    ec.category_name,
                    esc.subcategory_name,
                    esc.unit_of_measurement,
                    b.name as branch_name,
                    u.display_name as created_by_name,
                    approver.display_name as approved_by_name
                FROM expense_vouchers ev
                LEFT JOIN expense_categories ec ON ev.category_id = ec.id
                LEFT JOIN expense_subcategories esc ON ev.subcategory_id = esc.id
                LEFT JOIN branches b ON ev.branch_id = b.id
                LEFT JOIN users u ON ev.created_by_user_id = u.id
                LEFT JOIN users approver ON ev.approved_by_user_id = approver.id
                WHERE ev.status IN ('approved', 'rejected', 'cancelled')";

        $params = [];

        // Date filter
        if (!empty($filters['date_from'])) {
            $sql .= " AND ev.expense_date >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND ev.expense_date <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        // Status filter
        if (!empty($filters['status'])) {
            $sql .= " AND ev.status = :status";
            $params['status'] = $filters['status'];
        }

        // Branch filter
        if (!empty($filters['branch_id'])) {
            $sql .= " AND ev.branch_id = :branch_id";
            $params['branch_id'] = $filters['branch_id'];
        }

        // Category filter
        if (!empty($filters['category_id'])) {
            $sql .= " AND ev.category_id = :category_id";
            $params['category_id'] = $filters['category_id'];
        }

        // Subcategory filter
        if (!empty($filters['subcategory_id'])) {
            $sql .= " AND ev.subcategory_id = :subcategory_id";
            $params['subcategory_id'] = $filters['subcategory_id'];
        }

        // Keyword search
        if (!empty($filters['keyword'])) {
            $sql .= " AND (ev.voucher_number LIKE :keyword 
                      OR ev.remarks LIKE :keyword 
                      OR ev.payment_reference LIKE :keyword)";
            $params['keyword'] = '%' . $filters['keyword'] . '%';
        }

        $sql .= " ORDER BY ev.expense_date DESC, ev.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Get expense voucher by ID
     */
    public function getExpenseVoucherById($id) {
        $sql = "SELECT 
                    ev.*,
                    ec.category_name,
                    esc.subcategory_name,
                    esc.unit_of_measurement,
                    b.name as branch_name,
                    u.display_name as created_by_name,
                    approver.display_name as approved_by_name,
                    emp.name as employee_name
                FROM expense_vouchers ev
                LEFT JOIN expense_categories ec ON ev.category_id = ec.id
                LEFT JOIN expense_subcategories esc ON ev.subcategory_id = esc.id
                LEFT JOIN branches b ON ev.branch_id = b.id
                LEFT JOIN users u ON ev.created_by_user_id = u.id
                LEFT JOIN users approver ON ev.approved_by_user_id = approver.id
                LEFT JOIN employees emp ON ev.employee_id = emp.id
                WHERE ev.id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        
        return $stmt->fetch(PDO::FETCH_OBJ);
    }

    /**
     * Delete expense voucher (only pending)
     */
    public function deleteExpense($voucherId) {
        try {
            $voucher = $this->getExpenseVoucherById($voucherId);
            if (!$voucher) {
                throw new Exception('Voucher not found');
            }

            if ($voucher->status !== 'pending') {
                throw new Exception('Only pending vouchers can be deleted');
            }

            $this->db->beginTransaction();

            // Log action before deletion
            $this->logAction($voucherId, 'deleted', $voucher->status, null, 'Voucher deleted');

            // Delete voucher (CASCADE will delete action logs)
            $sql = "DELETE FROM expense_vouchers WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $voucherId]);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Expense voucher deleted successfully'
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("ExpenseManager::deleteExpense Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to delete expense: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get expense statistics for dashboard
     */
    public function getExpenseStatistics($filters = []) {
        // Today's expenses
        $sql = "SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total
                FROM expense_vouchers
                WHERE status = 'approved'
                AND DATE(expense_date) = CURDATE()";
        $stmt = $this->db->query($sql);
        $today = $stmt->fetch(PDO::FETCH_OBJ);

        // This month's expenses
        $sql = "SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total
                FROM expense_vouchers
                WHERE status = 'approved'
                AND MONTH(expense_date) = MONTH(CURDATE())
                AND YEAR(expense_date) = YEAR(CURDATE())";
        $stmt = $this->db->query($sql);
        $thisMonth = $stmt->fetch(PDO::FETCH_OBJ);

        // Last month's total
        $sql = "SELECT COALESCE(SUM(total_amount), 0) as total
                FROM expense_vouchers
                WHERE status = 'approved'
                AND MONTH(expense_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
                AND YEAR(expense_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
        $stmt = $this->db->query($sql);
        $lastMonth = $stmt->fetch(PDO::FETCH_OBJ);

        // Category breakdown
        $sql = "SELECT 
                    ec.category_name,
                    COUNT(*) as count,
                    SUM(ev.total_amount) as total
                FROM expense_vouchers ev
                INNER JOIN expense_categories ec ON ev.category_id = ec.id
                WHERE ev.status = 'approved'
                AND MONTH(ev.expense_date) = MONTH(CURDATE())
                AND YEAR(ev.expense_date) = YEAR(CURDATE())
                GROUP BY ev.category_id, ec.category_name
                ORDER BY total DESC";
        $stmt = $this->db->query($sql);
        $categoryBreakdown = $stmt->fetchAll(PDO::FETCH_OBJ);

        // Monthly trend (last 6 months)
        $sql = "SELECT 
                    DATE_FORMAT(expense_date, '%Y-%m') as month,
                    SUM(total_amount) as total
                FROM expense_vouchers
                WHERE status = 'approved'
                AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
                ORDER BY month ASC";
        $stmt = $this->db->query($sql);
        $monthlyTrend = $stmt->fetchAll(PDO::FETCH_OBJ);

        // Calculate trend percentage
        $trendPercentage = 0;
        if ($lastMonth->total > 0) {
            $trendPercentage = (($thisMonth->total - $lastMonth->total) / $lastMonth->total) * 100;
        }

        // Top category
        $topCategory = $categoryBreakdown[0]->category_name ?? 'N/A';

        // Daily average
        $daysInMonth = date('j'); // Current day of month
        $dailyAverage = $daysInMonth > 0 ? ($thisMonth->total / $daysInMonth) : 0;

        return [
            'today' => $today,
            'this_month' => $thisMonth,
            'last_month' => $lastMonth,
            'trend_percentage' => round($trendPercentage, 1),
            'top_category' => $topCategory,
            'daily_average' => $dailyAverage,
            'category_breakdown' => $categoryBreakdown,
            'monthly_trend' => $monthlyTrend
        ];
    }

    /**
     * Create accounting entries (double-entry bookkeeping)
     */
    private function createAccountingEntries($voucherId) {
        $voucher = $this->getExpenseVoucherById($voucherId);
        if (!$voucher) {
            throw new Exception('Voucher not found');
        }

        // Get expense account (Debit)
        $expenseAccountId = $voucher->expense_account_id;
        
        // Get payment account (Credit)
        $paymentAccountId = $voucher->payment_method === 'bank' 
            ? $voucher->bank_account_id 
            : $voucher->cash_account_id;

        if (!$expenseAccountId || !$paymentAccountId) {
            // Skip accounting entry if accounts not set
            return;
        }

        // Create journal entry
        $sql = "INSERT INTO journal_entries (
                    entry_date,
                    description,
                    reference_type,
                    reference_id,
                    created_by
                ) VALUES (
                    :entry_date,
                    :description,
                    'expense_voucher',
                    :reference_id,
                    :created_by
                )";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'entry_date' => $voucher->expense_date,
            'description' => "Expense: {$voucher->category_name} - {$voucher->voucher_number}",
            'reference_id' => $voucherId,
            'created_by' => $this->userId
        ]);

        $journalEntryId = $this->db->lastInsertId();

        // Debit: Expense Account
        $sql = "INSERT INTO journal_entry_lines (
                    journal_entry_id,
                    account_id,
                    debit,
                    credit
                ) VALUES (
                    :journal_entry_id,
                    :account_id,
                    :debit,
                    0
                )";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'journal_entry_id' => $journalEntryId,
            'account_id' => $expenseAccountId,
            'debit' => $voucher->total_amount
        ]);

        // Credit: Payment Account (Bank/Cash)
        $sql = "INSERT INTO journal_entry_lines (
                    journal_entry_id,
                    account_id,
                    debit,
                    credit
                ) VALUES (
                    :journal_entry_id,
                    :account_id,
                    0,
                    :credit
                )";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'journal_entry_id' => $journalEntryId,
            'account_id' => $paymentAccountId,
            'credit' => $voucher->total_amount
        ]);

        // Update voucher with journal_entry_id
        $sql = "UPDATE expense_vouchers SET journal_entry_id = :journal_entry_id WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'journal_entry_id' => $journalEntryId,
            'id' => $voucherId
        ]);
    }

    /**
     * Log action to expense_action_log
     */
    private function logAction($voucherId, $action, $oldStatus, $newStatus, $remarks = null) {
        $sql = "INSERT INTO expense_action_log (
                    expense_voucher_id,
                    action,
                    action_by,
                    old_status,
                    new_status,
                    remarks,
                    ip_address,
                    user_agent
                ) VALUES (
                    :voucher_id,
                    :action,
                    :action_by,
                    :old_status,
                    :new_status,
                    :remarks,
                    :ip_address,
                    :user_agent
                )";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'voucher_id' => $voucherId,
            'action' => $action,
            'action_by' => $this->userId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'remarks' => $remarks,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }

    /**
     * Send Telegram notification
     */
    private function sendTelegramNotification($voucherId, $type) {
        try {
            // Check if TelegramNotifier class exists
            if (!class_exists('TelegramNotifier')) {
                return; // Skip if not available
            }

            $voucher = $this->getExpenseVoucherById($voucherId);
            if (!$voucher) {
                return;
            }

            $telegram = new TelegramNotifier();

            if ($type === 'created') {
                $message = "⚠️⚠️⚠️ *NEW EXPENSE AWAITING APPROVAL* ⚠️⚠️⚠️\n\n";
                $message .= "📋 *Voucher:* {$voucher->voucher_number}\n";
                $message .= "📅 *Date:* " . date('d M Y', strtotime($voucher->expense_date)) . "\n";
                $message .= "💰 *Amount:* ৳" . number_format($voucher->total_amount, 2) . "\n";
                $message .= "🏢 *Branch:* {$voucher->branch_name}\n";
                $message .= "📂 *Category:* {$voucher->category_name}\n";
                $message .= "👤 *Created By:* {$voucher->created_by_name}\n";
                $message .= "📝 *Remarks:* " . ($voucher->remarks ?: 'N/A');
                
            } else if ($type === 'approved') {
                $message = "✅ *EXPENSE APPROVED* ✅\n\n";
                $message .= "📋 *Voucher:* {$voucher->voucher_number}\n";
                $message .= "💰 *Amount:* ৳" . number_format($voucher->total_amount, 2) . "\n";
                $message .= "✅ *Approved By:* {$voucher->approved_by_name}\n";
                $message .= "🕐 *Approved At:* " . date('d M Y H:i', strtotime($voucher->approved_at));
            }

            $telegram->sendMessage($message);

            // Mark as notified
            $sql = "UPDATE expense_vouchers SET telegram_notified = TRUE WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $voucherId]);

        } catch (Exception $e) {
            error_log("Telegram notification error: " . $e->getMessage());
            // Don't throw exception, just log
        }
    }

    /**
     * Generate voucher number
     */
    private function generateVoucherNumber() {
        $date = date('Ymd');
        $prefix = "EXP{$date}";
        
        $sql = "SELECT voucher_number FROM expense_vouchers 
                WHERE voucher_number LIKE :prefix 
                ORDER BY voucher_number DESC LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['prefix' => $prefix . '%']);
        $lastVoucher = $stmt->fetch(PDO::FETCH_OBJ);
        
        if ($lastVoucher) {
            $lastNumber = intval(substr($lastVoucher->voucher_number, -4));
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}
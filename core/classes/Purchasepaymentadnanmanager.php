<?php
/**
 * Purchase Payment Adnan Manager Class
 * Handles payment operations with bank account integration
 * 
 * @package Ujjal Flour Mills
 * @subpackage Purchase (Adnan) Module
 * @version 1.0.0
 */

class PurchasePaymentAdnanManager {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getPdo();
    }
    
    /**
     * Record a payment
     * 
     * @param array $data Payment data
     * @return array Result with success status and payment ID
     */
    public function recordPayment($data) {
        try {
            // Validate required fields
            $required = ['purchase_order_id', 'payment_date', 'amount_paid', 'payment_method'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => "Field {$field} is required"];
                }
            }
            
            // Validate bank account for bank payments
            if ($data['payment_method'] === 'bank' && empty($data['bank_account_id'])) {
                return ['success' => false, 'message' => 'Bank account is required for bank payments'];
            }
            
            // Validate employee for cash payments
            if ($data['payment_method'] === 'cash' && empty($data['handled_by_employee'])) {
                return ['success' => false, 'message' => 'Employee who handled cash is required'];
            }
            
            // Get PO details
            $po = $this->getPO($data['purchase_order_id']);
            if (!$po) {
                return ['success' => false, 'message' => 'Invalid purchase order'];
            }
            
            // Determine payment type
            $payment_type = $data['payment_type'] ?? 'regular';
            
            // Auto-detect advance payment
            $new_total = $po->total_paid + $data['amount_paid'];
            if ($new_total > $po->total_received_value && $po->total_received_value > 0) {
                $payment_type = 'advance';
            }
            
            // Get bank name if bank payment
            $bank_name = null;
            if ($data['payment_method'] === 'bank' && !empty($data['bank_account_id'])) {
                $bank = $this->getBankAccount($data['bank_account_id']);
                if (!$bank) {
                    return ['success' => false, 'message' => 'Invalid bank account'];
                }
                
                // Check balance - only for non-advance payments
                if ($bank->current_balance < $data['amount_paid']) {
                    return [
                        'success' => false, 
                        'message' => 'Insufficient bank balance. Available: ৳' . number_format($bank->current_balance, 2) . 
                                   '. Required: ৳' . number_format($data['amount_paid'], 2)
                    ];
                }
                
                $bank_name = $bank->account_name;
            } elseif ($data['payment_method'] === 'cash') {
                $bank_name = 'Cash';
            } elseif ($data['payment_method'] === 'cheque') {
                $bank_name = 'Cheque';
            }
            
            // Generate voucher number
            $voucher_number = $this->generateVoucherNumber();
            
            // Get current user
            $current_user = getCurrentUser();
            
            // Insert payment
            $sql = "INSERT INTO purchase_payments_adnan (
                payment_voucher_number, payment_date, purchase_order_id, po_number,
                supplier_id, supplier_name, amount_paid, payment_method,
                bank_account_id, bank_name, reference_number, payment_type,
                remarks, handled_by_employee, created_by_user_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $voucher_number,
                $data['payment_date'],
                $data['purchase_order_id'],
                $po->po_number,
                $po->supplier_id,
                $po->supplier_name,
                $data['amount_paid'],
                $data['payment_method'],
                $data['bank_account_id'] ?? null,
                $bank_name,
                $data['reference_number'] ?? null,
                $payment_type,
                $data['remarks'] ?? null,
                $data['handled_by_employee'] ?? null,
                $current_user['id']
            ]);
            
            $payment_id = $this->db->lastInsertId();
            
            // Create journal entry
            $journal_id = $this->createPaymentJournalEntry($payment_id, $po, $data);
            
            // Update payment with journal entry ID and update bank balance
            if ($journal_id) {
                $this->updatePaymentJournalId($payment_id, $journal_id);
                
                // Update bank balance for bank payments
                if ($data['payment_method'] === 'bank' && !empty($data['bank_account_id'])) {
                    $this->updateBankBalance($data['bank_account_id'], -$data['amount_paid']);
                }
            }
            
            return [
                'success' => true,
                'message' => 'Payment recorded successfully',
                'payment_id' => $payment_id,
                'voucher_number' => $voucher_number,
                'is_advance' => ($payment_type === 'advance')
            ];
            
        } catch (Exception $e) {
            error_log("Error recording payment: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error recording payment: ' . $e->getMessage()];
        }
    }
    
    /**
     * Generate sequential voucher number
     * 
     * @return string Voucher number
     */
    private function generateVoucherNumber() {
        $prefix = 'PV-' . date('Ymd') . '-';
        
        $sql = "SELECT COALESCE(MAX(CAST(SUBSTRING(payment_voucher_number, -4) AS UNSIGNED)), 0) AS max_voucher 
                FROM purchase_payments_adnan 
                WHERE payment_voucher_number LIKE ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$prefix . '%']);
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        
        $next_number = str_pad($result->max_voucher + 1, 4, '0', STR_PAD_LEFT);
        return $prefix . $next_number;
    }
    
    /**
     * Create journal entry for payment
     * 
     * @param int $payment_id Payment ID
     * @param object $po PO object
     * @param array $data Payment data
     * @return int|null Journal entry ID
     */
    private function createPaymentJournalEntry($payment_id, $po, $data) {
        try {
            $amount = $data['amount_paid'];
            
            // Get accounts
            $grn_pending_account = $this->getAccountByCode('2110'); // GRN Pending
            
            // Determine debit account based on payment method
            if ($data['payment_method'] === 'bank' && !empty($data['bank_account_id'])) {
                $bank = $this->getBankAccount($data['bank_account_id']);
                $payment_account = $bank->chart_of_account_id ? 
                    $this->getAccountById($bank->chart_of_account_id) : 
                    null;
                    
                // If bank doesn't have a linked account, try to find it
                if (!$payment_account) {
                    // Try to find by bank name in chart of accounts
                    $payment_account = $this->getAccountByCode('1050'); // Fallback to a bank account
                }
            } else {
                // Cash payment - find petty cash or cash account
                $payment_account = $this->getAccountByCode(''); // Will search for Cash/Petty Cash type
                if (!$payment_account) {
                    // Find first active cash account
                    $sql = "SELECT * FROM chart_of_accounts WHERE account_type IN ('Cash', 'Petty Cash') AND status = 'active' LIMIT 1";
                    $stmt = $this->db->query($sql);
                    $payment_account = $stmt->fetch(PDO::FETCH_OBJ);
                }
            }
            
            if (!$grn_pending_account || !$payment_account) {
                error_log("Missing accounts for payment journal entry");
                return null;
            }
            
            // Create journal entry
            $current_user = getCurrentUser();
            $description = "Payment for PO {$po->po_number} - {$po->supplier_name} - ৳{$amount}";
            
            $journal_sql = "INSERT INTO journal_entries (
                uuid, transaction_date, description, related_document_type, related_document_id,
                created_by_user_id
            ) VALUES (UUID(), ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($journal_sql);
            $stmt->execute([
                $data['payment_date'],
                $description,
                'payment_adnan',
                $payment_id,
                $current_user['id']
            ]);
            
            $journal_id = $this->db->lastInsertId();
            
            // Create journal transaction lines
            $detail_sql = "INSERT INTO transaction_lines (
                journal_entry_id, account_id, debit_amount, credit_amount, description
            ) VALUES (?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($detail_sql);
            
            // Debit: GRN Pending / Accounts Payable
            $stmt->execute([
                $journal_id,
                $grn_pending_account->id,
                $amount,
                0,
                "Payment to {$po->supplier_name}"
            ]);
            
            // Credit: Bank / Cash
            $stmt->execute([
                $journal_id,
                $payment_account->id,
                0,
                $amount,
                "Payment via " . ($data['payment_method'] === 'bank' ? $this->getBankAccount($data['bank_account_id'])->account_name : 'Cash')
            ]);
            
            return $journal_id;
            
        } catch (Exception $e) {
            error_log("Error creating payment journal entry: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update payment with journal entry ID
     * 
     * @param int $payment_id Payment ID
     * @param int $journal_id Journal entry ID
     * @return void
     */
    private function updatePaymentJournalId($payment_id, $journal_id) {
        $sql = "UPDATE purchase_payments_adnan 
                SET journal_entry_id = ?, is_posted = 1 
                WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$journal_id, $payment_id]);
    }
    
    /**
     * Get payment by ID
     * 
     * @param int $payment_id Payment ID
     * @return object|null Payment object
     */
    public function getPayment($payment_id) {
        $sql = "SELECT * FROM purchase_payments_adnan WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$payment_id]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get all payments with filters
     * 
     * @param array $filters Filter criteria
     * @return array Payment list
     */
    public function listPayments($filters = []) {
        $sql = "SELECT * FROM purchase_payments_adnan WHERE 1=1";
        $params = [];
        
        if (!empty($filters['po_id'])) {
            $sql .= " AND purchase_order_id = ?";
            $params[] = $filters['po_id'];
        }
        
        if (!empty($filters['supplier_id'])) {
            $sql .= " AND supplier_id = ?";
            $params[] = $filters['supplier_id'];
        }
        
        if (!empty($filters['payment_method'])) {
            $sql .= " AND payment_method = ?";
            $params[] = $filters['payment_method'];
        }
        
        if (!empty($filters['bank_account_id'])) {
            $sql .= " AND bank_account_id = ?";
            $params[] = $filters['bank_account_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND payment_date >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND payment_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        $sql .= " ORDER BY payment_date DESC, id DESC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get payment statistics by bank account
     * 
     * @return array Bank-wise payment stats
     */
    public function getStatsByBankAccount() {
        $sql = "SELECT 
            bank_name,
            COUNT(*) as payment_count,
            SUM(amount_paid) as total_paid
        FROM purchase_payments_adnan
        WHERE payment_method = 'bank'
        GROUP BY bank_name
        ORDER BY total_paid DESC";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get advance payments
     * 
     * @param int|null $supplier_id Optional supplier filter
     * @return array Advance payment list
     */
    public function getAdvancePayments($supplier_id = null) {
        $sql = "SELECT 
            po.po_number,
            po.supplier_name,
            po.total_received_value,
            po.total_paid,
            (po.total_paid - po.total_received_value) as advance_amount
        FROM purchase_orders_adnan po
        WHERE po.total_paid > po.total_received_value
        AND po.po_status != 'cancelled'";
        
        $params = [];
        if ($supplier_id) {
            $sql .= " AND po.supplier_id = ?";
            $params[] = $supplier_id;
        }
        
        $sql .= " ORDER BY advance_amount DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get payment aging report
     * 
     * @return array Aging buckets
     */
    public function getPaymentAging() {
        $sql = "SELECT 
            po.po_number,
            po.supplier_name,
            po.po_date,
            po.balance_payable,
            DATEDIFF(CURDATE(), po.po_date) as days_outstanding,
            CASE
                WHEN DATEDIFF(CURDATE(), po.po_date) <= 7 THEN '0-7 days'
                WHEN DATEDIFF(CURDATE(), po.po_date) <= 15 THEN '8-15 days'
                WHEN DATEDIFF(CURDATE(), po.po_date) <= 30 THEN '16-30 days'
                ELSE '30+ days'
            END as aging_bucket
        FROM purchase_orders_adnan po
        WHERE po.balance_payable > 0
        AND po.po_status != 'cancelled'
        ORDER BY days_outstanding DESC";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get PO object
     * 
     * @param int $po_id PO ID
     * @return object|null PO object
     */
    private function getPO($po_id) {
        $sql = "SELECT * FROM purchase_orders_adnan WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$po_id]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get bank account
     * 
     * @param int $bank_account_id Bank account ID
     * @return object|null Bank account object
     */
    private function getBankAccount($bank_account_id) {
        $sql = "SELECT * FROM bank_accounts WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$bank_account_id]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get all active bank accounts
     * 
     * @return array Bank account list
     */
    public function getAllBankAccounts() {
        $sql = "SELECT * FROM bank_accounts WHERE status = 'active' ORDER BY account_name ASC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get all active employees
     * 
     * @return array Employee list
     */
    public function getAllEmployees() {
        $sql = "SELECT id, CONCAT(first_name, ' ', last_name) as name 
                FROM employees 
                WHERE status = 'active' 
                ORDER BY first_name ASC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get account by code
     * 
     * @param string $code Account code
     * @return object|null Account object
     */
    private function getAccountByCode($code) {
        $sql = "SELECT * FROM chart_of_accounts WHERE account_number = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$code]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get account by ID
     * 
     * @param int $id Account ID
     * @return object|null Account object
     */
    private function getAccountById($id) {
        $sql = "SELECT * FROM chart_of_accounts WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Update bank account balance
     * 
     * @param int $bank_account_id Bank account ID
     * @param float $amount Amount to add (negative to deduct)
     * @return void
     */
    private function updateBankBalance($bank_account_id, $amount) {
        $sql = "UPDATE bank_accounts 
                SET current_balance = current_balance + ?,
                    updated_at = NOW()
                WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$amount, $bank_account_id]);
    }
}
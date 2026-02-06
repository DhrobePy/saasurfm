<?php
/**
 * Purchase Payment Adnan Manager Class
 * Handles payment operations with bank account integration
 * 
 * IMPROVEMENTS:
 * - Removed balance check (allows payments even with insufficient funds)
 * - Added transaction management for data integrity
 * - Enhanced chart_of_accounts integration
 * - Keeps all reporting methods
 * - Better error handling
 * - SCHEMA-VERIFIED: All column names match actual database
 * 
 * @package Ujjal Flour Mills
 * @subpackage Purchase (Adnan) Module
 * @version 2.1.0 (Schema-Corrected)
 */

class Purchasepaymentadnanmanager {
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
            // Start transaction for data integrity
            $this->db->beginTransaction();
            
            // Validate required fields
            $required = ['purchase_order_id', 'payment_date', 'amount_paid', 'payment_method'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Field {$field} is required");
                }
            }
            
            // Validate bank account for bank payments
            if ($data['payment_method'] === 'bank' && empty($data['bank_account_id'])) {
                throw new Exception('Bank account is required for bank payments');
            }
            
            // Validate employee for cash payments
            if ($data['payment_method'] === 'cash' && empty($data['handled_by_employee'])) {
                throw new Exception('Employee who handled cash is required');
            }
            
            // Get PO details
            $po = $this->getPO($data['purchase_order_id']);
            if (!$po) {
                throw new Exception('Invalid purchase order');
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
            $bank_chart_account_id = null;
            
            if ($data['payment_method'] === 'bank' && !empty($data['bank_account_id'])) {
                $bank = $this->getBankAccount($data['bank_account_id']);
                if (!$bank) {
                    throw new Exception('Invalid bank account');
                }
                
                // ============================================================
                // BALANCE CHECK REMOVED AS PER USER REQUIREMENT
                // System now allows payments even with insufficient funds
                // Useful for overdraft facilities or pending reconciliation
                // ============================================================
                
                // Verify bank account has chart_of_accounts linkage
                if (empty($bank->chart_of_account_id)) {
                    error_log("WARNING: Bank account {$bank->id} ({$bank->bank_name}) not linked to chart of accounts");
                    // Don't fail, just log warning - journal entry will use fallback
                }
                
                $bank_name = $bank->bank_name . ' - ' . ($bank->branch_name ?: $bank->account_name);
                $bank_chart_account_id = $bank->chart_of_account_id;
                
            } elseif ($data['payment_method'] === 'cash') {
                // ✅ PROPER FIX: Get chart_of_account_id directly from selected cash account
                $bank_name = !empty($data['bank_name']) ? $data['bank_name'] : 'Cash';
                
                if (!empty($data['cash_account_id'])) {
                    $cash_account = $this->getCashAccountById($data['cash_account_id']);
                    if (!$cash_account) {
                        throw new Exception('Invalid cash account selected');
                    }
                    
                    // Get the linked chart account ID
                    if (empty($cash_account->chart_of_account_id)) {
                        error_log("WARNING: Cash account {$cash_account->id} ({$cash_account->account_name}) not linked to chart of accounts");
                        throw new Exception('Cash account is not linked to chart of accounts. Please contact admin.');
                    }
                    
                    $bank_chart_account_id = $cash_account->chart_of_account_id;
                    $bank_name = $cash_account->account_name . ' - ' . ($cash_account->branch_name ?? '');
                } else {
                    throw new Exception('Cash account selection is required for cash payments');
                }
            } elseif ($data['payment_method'] === 'cheque') {
                // For cheque, we still reference a bank account
                if (!empty($data['bank_account_id'])) {
                    $bank = $this->getBankAccount($data['bank_account_id']);
                    $bank_name = 'Cheque - ' . ($bank->bank_name ?? 'Bank');
                    $bank_chart_account_id = $bank->chart_of_account_id ?? null;
                } else {
                    $bank_name = 'Cheque';
                }
            }
            
            // Generate voucher number
            $voucher_number = $this->generateVoucherNumber();
            
            // Get current user
            $current_user = getCurrentUser();
            
            // Convert employee ID to name for varchar field (database schema uses varchar, not FK)
            $handled_by_employee_name = null;
            if (!empty($data['handled_by_employee'])) {
                $employee = $this->getEmployeeById($data['handled_by_employee']);
                if ($employee) {
                    $handled_by_employee_name = $employee->full_name;
                } else {
                    // If employee not found, store the raw value
                    $handled_by_employee_name = $data['handled_by_employee'];
                }
            }
            
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
                $handled_by_employee_name,  // ✅ Stores employee name, not ID
                $current_user['id']
            ]);
            
            $payment_id = $this->db->lastInsertId();
            
            // Update purchase order totals
            // Note: balance_payable is a GENERATED column (auto-calculates as total_received_value - total_paid)
            // We only update total_paid, and balance_payable will automatically recalculate
            $update_sql = "UPDATE purchase_orders_adnan 
                          SET total_paid = total_paid + ?
                          WHERE id = ?";
            $stmt = $this->db->prepare($update_sql);
            $stmt->execute([
                $data['amount_paid'],
                $data['purchase_order_id']
            ]);
            
            // Update payment status
            $status_sql = "UPDATE purchase_orders_adnan 
                          SET payment_status = CASE 
                              WHEN balance_payable <= 0.01 THEN 'paid'
                              WHEN total_paid > 0 THEN 'partial'
                              ELSE 'unpaid'
                          END
                          WHERE id = ?";
            $stmt = $this->db->prepare($status_sql);
            $stmt->execute([$data['purchase_order_id']]);
            
            // Create journal entry
            $journal_id = $this->createPaymentJournalEntry($payment_id, $po, $data, $bank_chart_account_id);
            
            // Update payment with journal entry ID
            if ($journal_id) {
                $this->updatePaymentJournalId($payment_id, $journal_id);
                
                // Mark payment as posted
                $post_sql = "UPDATE purchase_payments_adnan SET is_posted = 1 WHERE id = ?";
                $stmt = $this->db->prepare($post_sql);
                $stmt->execute([$payment_id]);
            }
            
            // Update bank/cash balance
            if ($data['payment_method'] === 'bank' && !empty($data['bank_account_id'])) {
                $this->updateBankBalance($data['bank_account_id'], -$data['amount_paid']);
            } elseif ($data['payment_method'] === 'cash' && !empty($data['cash_account_id'])) {
                $this->updateCashBalance($data['cash_account_id'], -$data['amount_paid']);
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Payment recorded successfully',
                'payment_id' => $payment_id,
                'voucher_number' => $voucher_number,
                'payment_type' => $payment_type
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
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
     * Integrates with chart_of_accounts
     * 
     * @param int $payment_id Payment ID
     * @param object $po PO object
     * @param array $data Payment data
     * @param int $bank_chart_account_id Chart of Account ID for bank/cash account
     * @return int|null Journal entry ID
     */
    private function createPaymentJournalEntry($payment_id, $po, $data, $bank_chart_account_id = null) {
        try {
            // Get Accounts Payable account (2100)
            $ap_account = $this->getAccountByCode('2100');
            if (!$ap_account) {
                error_log("Accounts Payable account (2100) not found - cannot create journal entry");
                return null;
            }
            
            // Determine credit account (Bank/Cash)
            $credit_account = null;
            
            if ($bank_chart_account_id) {
                // Use the linked chart_of_account from bank_accounts or branch_petty_cash_accounts
                $credit_account = $this->getAccountById($bank_chart_account_id);
            }
            
            if (!$credit_account) {
                // Fallback: Try to find by payment method
                if ($data['payment_method'] === 'bank') {
                    $credit_account = $this->getAccountByName('Bank Account');
                    if (!$credit_account) {
                        $credit_account = $this->getAccountByCode('1010'); // Default bank account
                    }
                } elseif ($data['payment_method'] === 'cash') {
                    $credit_account = $this->getAccountByName('Petty Cash');
                    if (!$credit_account) {
                        $credit_account = $this->getAccountByCode('1001'); // Default cash account
                    }
                } elseif ($data['payment_method'] === 'cheque') {
                    $credit_account = $this->getAccountByName('Bank Account');
                    if (!$credit_account) {
                        $credit_account = $this->getAccountByCode('1010');
                    }
                }
            }
            
            if (!$credit_account) {
                error_log("Could not determine credit account for payment - journal entry not created");
                return null;
            }
            
            // Create journal entry
            $current_user = getCurrentUser();
            $description = "Payment for PO {$po->po_number} - {$po->supplier_name} - ৳" . number_format($data['amount_paid'], 2);
            
            $journal_sql = "INSERT INTO journal_entries (
                uuid, transaction_date, description, related_document_type, related_document_id,
                created_by_user_id
            ) VALUES (UUID(), ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($journal_sql);
            $stmt->execute([
                $data['payment_date'],
                $description,
                'purchase_payment_adnan',
                $payment_id,
                $current_user['id']
            ]);
            
            $journal_id = $this->db->lastInsertId();
            
            // Create transaction lines
            $detail_sql = "INSERT INTO transaction_lines (
                journal_entry_id, account_id, debit_amount, credit_amount, description
            ) VALUES (?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($detail_sql);
            
            // Debit: Accounts Payable
            $stmt->execute([
                $journal_id,
                $ap_account->id,
                $data['amount_paid'],
                0,
                "Payment to {$po->supplier_name}"
            ]);
            
            // Credit: Bank/Cash
            $stmt->execute([
                $journal_id,
                $credit_account->id,
                0,
                $data['amount_paid'],
                ucfirst($data['payment_method']) . " payment - " . ($data['reference_number'] ?? 'No ref')
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
                SET journal_entry_id = ?
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
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND payment_date >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND payment_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        if (isset($filters['is_posted'])) {
            $sql .= " AND is_posted = ?";
            $params[] = $filters['is_posted'];
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
     * @return array Bank-wise stats
     */
    public function getStatsByBankAccount() {
        $sql = "SELECT 
            bank_name,
            payment_method,
            COUNT(*) as payment_count,
            SUM(amount_paid) as total_amount,
            AVG(amount_paid) as avg_payment
        FROM purchase_payments_adnan
        WHERE is_posted = 1
        GROUP BY bank_name, payment_method
        ORDER BY total_amount DESC";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get advance payments
     * 
     * @param int $supplier_id Optional supplier filter
     * @return array Advance payment list
     */
    public function getAdvancePayments($supplier_id = null) {
        $sql = "SELECT * FROM purchase_payments_adnan WHERE payment_type = 'advance'";
        $params = [];
        
        if ($supplier_id) {
            $sql .= " AND supplier_id = ?";
            $params[] = $supplier_id;
        }
        
        $sql .= " ORDER BY payment_date DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get payment aging report
     * 
     * @return array Aging data
     */
    public function getPaymentAging() {
        $sql = "SELECT 
            po.supplier_name,
            po.po_number,
            po.po_date,
            po.total_order_value,
            po.total_received_value,
            po.total_paid,
            po.balance_payable,
            DATEDIFF(CURDATE(), po.po_date) as days_outstanding,
            CASE 
                WHEN DATEDIFF(CURDATE(), po.po_date) <= 30 THEN '0-30 days'
                WHEN DATEDIFF(CURDATE(), po.po_date) <= 60 THEN '31-60 days'
                WHEN DATEDIFF(CURDATE(), po.po_date) <= 90 THEN '61-90 days'
                ELSE '90+ days'
            END as aging_bucket
        FROM purchase_orders_adnan po
        WHERE po.balance_payable > 0
        ORDER BY po.po_date";
        
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
        $sql = "SELECT * FROM bank_accounts WHERE id = ? AND status = 'active'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$bank_account_id]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get all bank accounts
     * 
     * @return array Bank account list
     */
    public function getAllBankAccounts() {
        $sql = "SELECT 
                    ba.*,
                    coa.name as chart_account_name,
                    coa.account_number as chart_account_number
                FROM bank_accounts ba
                LEFT JOIN chart_of_accounts coa ON ba.chart_of_account_id = coa.id
                WHERE ba.status = 'active'
                ORDER BY ba.bank_name, ba.account_name";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get all petty cash accounts from branch_petty_cash_accounts
     * 
     * @return array Cash account list with branch and chart linkage
     */
    public function getAllCashAccounts() {
        $sql = "SELECT 
                    pc.*,
                    b.name as branch_name,
                    b.id as branch_id,
                    coa.name as chart_account_name,
                    coa.account_number as chart_account_number
                FROM branch_petty_cash_accounts pc
                LEFT JOIN branches b ON pc.branch_id = b.id
                LEFT JOIN chart_of_accounts coa ON pc.chart_of_account_id = coa.id
                WHERE pc.status = 'active'
                ORDER BY b.name, pc.account_name";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get all employees
     * 
     * @return array Employee list
     */
    public function getAllEmployees() {
        $sql = "SELECT 
                    id,
                    CONCAT(first_name, ' ', last_name) as full_name,
                    first_name,
                    last_name,
                    email,
                    department
                FROM employees 
                WHERE status = 'active'
                ORDER BY first_name, last_name";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get employee by ID (helper for converting ID to name for varchar field)
     * 
     * @param int $employee_id Employee ID
     * @return object|null Employee object
     */
    private function getEmployeeById($employee_id) {
        if (empty($employee_id)) {
            return null;
        }
        $sql = "SELECT id, CONCAT(first_name, ' ', last_name) as full_name, 
                       first_name, last_name
                FROM employees 
                WHERE id = ? AND status = 'active'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$employee_id]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get account by code
     * 
     * @param string $code Account code
     * @return object|null Account object
     */
    private function getAccountByCode($code) {
        if (empty($code)) {
            return null;
        }
        $sql = "SELECT * FROM chart_of_accounts WHERE account_number = ? AND status = 'active'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$code]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get account by name
     * 
     * @param string $name Account name
     * @return object|null Account object
     */
    private function getAccountByName($name) {
        if (empty($name)) {
            return null;
        }
        $sql = "SELECT * FROM chart_of_accounts WHERE name = ? AND status = 'active' LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$name]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get account by ID
     * 
     * @param int $id Account ID
     * @return object|null Account object
     */
    private function getAccountById($id) {
        if (empty($id)) {
            return null;
        }
        $sql = "SELECT * FROM chart_of_accounts WHERE id = ? AND status = 'active'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Update bank account balance
     * NO BALANCE CHECK - allows overdraft
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
    
    /**
     * Update petty cash account balance in branch_petty_cash_accounts
     * NO BALANCE CHECK - allows negative balance
     * 
     * @param int $cash_account_id Cash account ID from branch_petty_cash_accounts
     * @param float $amount Amount to add (negative to deduct)
     * @return void
     */
    private function updateCashBalance($cash_account_id, $amount) {
        $sql = "UPDATE branch_petty_cash_accounts 
                SET current_balance = current_balance + ?,
                    updated_at = NOW()
                WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$amount, $cash_account_id]);
    }
    
    /**
     * Get payment summary for dashboard
     * 
     * @param array $filters Optional filters
     * @return array Summary statistics
     */
    public function getPaymentSummary($filters = []) {
        $where = "WHERE 1=1";
        $params = [];
        
        if (!empty($filters['date_from'])) {
            $where .= " AND payment_date >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where .= " AND payment_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        $sql = "SELECT 
                COUNT(*) as total_payments,
                SUM(amount_paid) as total_amount,
                SUM(CASE WHEN payment_method = 'bank' THEN amount_paid ELSE 0 END) as bank_payments,
                SUM(CASE WHEN payment_method = 'cash' THEN amount_paid ELSE 0 END) as cash_payments,
                SUM(CASE WHEN payment_method = 'cheque' THEN amount_paid ELSE 0 END) as cheque_payments,
                SUM(CASE WHEN payment_type = 'advance' THEN amount_paid ELSE 0 END) as advance_payments
            FROM purchase_payments_adnan
            {$where}";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get account by partial name match (LIKE)
     * Useful for matching cash account names
     * 
     * @param string $name_pattern Account name or pattern
     * @return object|null Account object
     */
    private function getAccountByNameLike($name_pattern) {
        if (empty($name_pattern)) {
            return null;
        }
        $sql = "SELECT * FROM chart_of_accounts 
                WHERE name LIKE ? 
                AND status = 'active' 
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['%' . $name_pattern . '%']);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get petty cash account by branch ID
     * 
     * @param int $branch_id Branch ID
     * @return object|null Account object
     */
    private function getCashAccountByBranchId($branch_id) {
        if (empty($branch_id)) {
            return null;
        }
        $sql = "SELECT * FROM chart_of_accounts 
                WHERE account_type = 'Petty Cash' 
                AND branch_id = ? 
                AND status = 'active' 
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$branch_id]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get petty cash account from branch_petty_cash_accounts by ID
     * 
     * @param int $id Cash account ID
     * @return object|null Cash account object with branch info and chart linkage
     */
    private function getCashAccountById($id) {
        if (empty($id)) {
            return null;
        }
        $sql = "SELECT pc.*, 
                       b.name as branch_name, 
                       b.id as branch_id,
                       coa.name as chart_account_name,
                       coa.id as chart_of_account_id
                FROM branch_petty_cash_accounts pc
                LEFT JOIN branches b ON pc.branch_id = b.id
                LEFT JOIN chart_of_accounts coa ON pc.chart_of_account_id = coa.id
                WHERE pc.id = ? 
                AND pc.status = 'active'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Update/Edit a payment
     * Only Superadmin can edit
     * 
     * @param int $payment_id Payment ID
     * @param array $data Updated payment data
     * @return array Result with success status
     */
    public function updatePayment($payment_id, $data) {
        try {
            // Check Superadmin permission
            $user = getCurrentUser();
            if (!$user || $user['role'] !== 'Superadmin') {
                return ['success' => false, 'message' => 'Only Superadmin can edit payments'];
            }
            
            $this->db->beginTransaction();
            
            // Get existing payment
            $existing_payment = $this->getPayment($payment_id);
            if (!$existing_payment) {
                throw new Exception('Payment not found');
            }
            
            // Get PO
            $po = $this->getPO($existing_payment->purchase_order_id);
            if (!$po) {
                throw new Exception('Purchase order not found');
            }
            
            // Store old values for audit
            $old_amount = $existing_payment->amount_paid;
            $new_amount = isset($data['amount_paid']) ? floatval($data['amount_paid']) : $old_amount;
            
            // Determine payment type
            $payment_type = $data['payment_type'] ?? $existing_payment->payment_type;
            
            // Update payment record
            $sql = "UPDATE purchase_payments_adnan SET 
                        payment_date = ?,
                        amount_paid = ?,
                        payment_method = ?,
                        reference_number = ?,
                        payment_type = ?,
                        remarks = ?,
                        updated_at = NOW()
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $data['payment_date'] ?? $existing_payment->payment_date,
                $new_amount,
                $data['payment_method'] ?? $existing_payment->payment_method,
                $data['reference_number'] ?? $existing_payment->reference_number,
                $payment_type,
                $data['remarks'] ?? $existing_payment->remarks,
                $payment_id
            ]);
            
            // Recalculate PO payment totals
            $this->recalculatePOPaymentTotals($existing_payment->purchase_order_id);
            
            $this->db->commit();
            
            // Log activity if auditLog function exists
            if (function_exists('auditLog')) {
                auditLog('Purchase (Adnan)', 'payment_edited', 
                    "Payment {$existing_payment->payment_voucher_number} edited - Amount: ৳{$old_amount} → ৳{$new_amount}",
                    ['payment_id' => $payment_id, 'old_amount' => $old_amount, 'new_amount' => $new_amount]
                );
            }
            
            return [
                'success' => true,
                'message' => 'Payment updated successfully',
                'payment_id' => $payment_id
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Error updating payment: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating payment: ' . $e->getMessage()];
        }
    }
    
    /**
     * Delete a payment (hard delete)
     * Only Superadmin can delete
     * 
     * @param int $payment_id Payment ID
     * @param string $reason Deletion reason
     * @return array Result with success status
     */
    public function deletePayment($payment_id, $reason = null) {
        try {
            // Check Superadmin permission
            $user = getCurrentUser();
            if (!$user || $user['role'] !== 'Superadmin') {
                return ['success' => false, 'message' => 'Only Superadmin can delete payments'];
            }
            
            $this->db->beginTransaction();
            
            // Get existing payment
            $payment = $this->getPayment($payment_id);
            if (!$payment) {
                throw new Exception('Payment not found');
            }
            
            // Store details for audit
            $voucher_number = $payment->payment_voucher_number;
            $amount = $payment->amount_paid;
            $po_id = $payment->purchase_order_id;
            
            // Reverse bank/cash balance if applicable
            // Only if the payment was posted (is_posted = 1)
            if ($payment->is_posted) {
                if ($payment->bank_account_id) {
                    // Add money back to bank account (reverse the deduction)
                    $this->updateBankBalance($payment->bank_account_id, $amount);
                }
                // Note: Cash balance reversal would go here if cash_account_id was stored
            }
            
            // Delete payment record (hard delete)
            $sql = "DELETE FROM purchase_payments_adnan WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$payment_id]);
            
            // Recalculate PO payment totals
            $this->recalculatePOPaymentTotals($po_id);
            
            $this->db->commit();
            
            // Log activity if auditLog function exists
            if (function_exists('auditLog')) {
                $delete_reason = $reason ?? 'No reason provided';
                auditLog('Purchase (Adnan)', 'payment_deleted', 
                    "Payment {$voucher_number} deleted - ৳{$amount} removed. Reason: {$delete_reason}",
                    ['payment_id' => $payment_id, 'reason' => $delete_reason, 'amount' => $amount]
                );
            }
            
            return [
                'success' => true,
                'message' => 'Payment deleted successfully'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Error deleting payment: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting payment: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get payment with PO details for editing
     * 
     * @param int $payment_id Payment ID
     * @return object|null Payment object with PO details
     */
    public function getPaymentForEdit($payment_id) {
        $sql = "SELECT 
                    p.*,
                    po.po_number,
                    po.supplier_name,
                    po.total_order_value,
                    po.total_received_value,
                    po.balance_payable,
                    ba.bank_name as linked_bank_name,
                    ba.account_number as linked_account_number
                FROM purchase_payments_adnan p
                LEFT JOIN purchase_orders_adnan po ON p.purchase_order_id = po.id
                LEFT JOIN bank_accounts ba ON p.bank_account_id = ba.id
                WHERE p.id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$payment_id]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Check if payment can be deleted
     * 
     * @param int $payment_id Payment ID
     * @return array Result with can_delete status and reason
     */
    public function canDeletePayment($payment_id) {
        $user = getCurrentUser();
        
        if (!$user || $user['role'] !== 'Superadmin') {
            return [
                'can_delete' => false,
                'reason' => 'Only Superadmin can delete payments'
            ];
        }
        
        $payment = $this->getPayment($payment_id);
        
        if (!$payment) {
            return [
                'can_delete' => false,
                'reason' => 'Payment not found'
            ];
        }
        
        return [
            'can_delete' => true,
            'reason' => 'OK to delete'
        ];
    }
    
    /**
     * Recalculate PO payment totals after payment changes
     * This ensures payment totals are accurate after edit/delete operations
     * 
     * @param int $po_id Purchase Order ID
     * @return void
     */
    private function recalculatePOPaymentTotals($po_id) {
        try {
            // Recalculate total paid (only posted payments where is_posted = 1)
            $sql = "SELECT COALESCE(SUM(amount_paid), 0) as total_paid
                    FROM purchase_payments_adnan 
                    WHERE purchase_order_id = ? 
                    AND is_posted = 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$po_id]);
            $totals = $stmt->fetch(PDO::FETCH_OBJ);
            
            // Update PO with new total
            $update_sql = "UPDATE purchase_orders_adnan 
                           SET total_paid = ?
                           WHERE id = ?";
            
            $stmt = $this->db->prepare($update_sql);
            $stmt->execute([$totals->total_paid, $po_id]);
            
            // Update payment status based on new totals
            $this->updatePOPaymentStatus($po_id);
            
        } catch (Exception $e) {
            error_log("Error recalculating payment totals: " . $e->getMessage());
        }
    }
    
    /**
     * Update PO payment status based on paid amounts
     * 
     * @param int $po_id Purchase Order ID
     * @return void
     */
    private function updatePOPaymentStatus($po_id) {
        try {
            $sql = "UPDATE purchase_orders_adnan 
                    SET payment_status = CASE
                        WHEN balance_payable <= 0.01 THEN 'paid'
                        WHEN total_paid > 0 THEN 'partial'
                        ELSE 'unpaid'
                    END
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$po_id]);
            
        } catch (Exception $e) {
            error_log("Error updating payment status: " . $e->getMessage());
        }
    }
}
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
                
                // Update bank balance for bank/cheque payments
                // NO BALANCE CHECK - allows overdraft as per requirement
                if (($data['payment_method'] === 'bank' || $data['payment_method'] === 'cheque') 
                    && !empty($data['bank_account_id'])) {
                    $this->updateBankBalance($data['bank_account_id'], -$data['amount_paid']);
                }
                
                // ✅ PROPER FIX: Update cash balance for cash payments
                if ($data['payment_method'] === 'cash' && !empty($data['cash_account_id'])) {
                    $this->updateCashBalance($data['cash_account_id'], -$data['amount_paid']);
                }
            }
            
            // Commit transaction
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Payment recorded successfully',
                'payment_id' => $payment_id,
                'voucher_number' => $voucher_number,
                'journal_entry_id' => $journal_id,
                'is_advance' => ($payment_type === 'advance')
            ];
            
        } catch (Exception $e) {
            // Rollback on error
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            error_log("Error recording payment: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error recording payment: ' . $e->getMessage()];
        }
    }
    
    /**
     * Generate sequential voucher number
     * Format: PV-YYYYMMDD-XXXX
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
     * Journal Entry Logic:
     * DR: GRN Pending / Accounts Payable (reduces liability - we owe less)
     * CR: Bank / Cash Account (reduces asset - money goes out)
     * 
     * @param int $payment_id Payment ID
     * @param object $po PO object
     * @param array $data Payment data
     * @param int|null $bank_chart_account_id Bank's chart of account ID
     * @return int|null Journal entry ID
     */
    private function createPaymentJournalEntry($payment_id, $po, $data, $bank_chart_account_id = null) {
        try {
            $amount = $data['amount_paid'];
            
            // Get voucher number for description
            $sql = "SELECT payment_voucher_number FROM purchase_payments_adnan WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$payment_id]);
            $payment = $stmt->fetch(PDO::FETCH_OBJ);
            $voucher_number = $payment ? $payment->payment_voucher_number : 'PAYMENT-' . $payment_id;
            
            // Get GRN Pending / Accounts Payable account
            $grn_pending_account = $this->getAccountByCode('2110'); // GRN Pending
            
            // If GRN Pending not found, try Accounts Payable
            if (!$grn_pending_account) {
                $grn_pending_account = $this->getAccountByName('Accounts Payable');
            }
            
            // Determine payment account based on payment method
            $payment_account = null;
            
            if ($data['payment_method'] === 'bank' && $bank_chart_account_id) {
                // Use the linked chart of accounts entry
                $payment_account = $this->getAccountById($bank_chart_account_id);
            }
            
            // Fallback for bank without chart linkage
            if (!$payment_account && $data['payment_method'] === 'bank') {
                // Try to find a bank account by code
                $payment_account = $this->getAccountByCode('1050'); // Fallback bank account
                
                // If still not found, try to find first active bank account
                if (!$payment_account) {
                    $sql = "SELECT * FROM chart_of_accounts 
                            WHERE account_type = 'Bank' 
                            AND status = 'active' 
                            LIMIT 1";
                    $stmt = $this->db->query($sql);
                    $payment_account = $stmt->fetch(PDO::FETCH_OBJ);
                }
            }
            
            // Cash payment - use the provided bank_chart_account_id
            if ($data['payment_method'] === 'cash') {
                // ✅ PROPER FIX: Use chart_of_account_id directly from cash account
                if ($bank_chart_account_id) {
                    $payment_account = $this->getAccountById($bank_chart_account_id);
                }
                
                // Fallback to generic cash account if chart link is missing
                if (!$payment_account) {
                    $payment_account = $this->getAccountByName('Cash - Purchase Payments');
                }
                
                if (!$payment_account) {
                    $payment_account = $this->getAccountByName('Cash');
                }
                
                // Last resort: find any active cash account
                if (!$payment_account) {
                    $sql = "SELECT * FROM chart_of_accounts 
                            WHERE account_type IN ('Cash', 'Petty Cash')
                            AND status = 'active' 
                            LIMIT 1";
                    $stmt = $this->db->query($sql);
                    $payment_account = $stmt->fetch(PDO::FETCH_OBJ);
                }
            }
            
            // Cheque payment
            if ($data['payment_method'] === 'cheque' && $bank_chart_account_id) {
                $payment_account = $this->getAccountById($bank_chart_account_id);
            }
            
            // Validate we have both accounts
            if (!$grn_pending_account || !$payment_account) {
                error_log("Missing accounts for payment journal entry. GRN/AP: " . 
                         ($grn_pending_account ? 'found' : 'missing') . 
                         ", Payment account: " . 
                         ($payment_account ? 'found' : 'missing'));
                return null;
            }
            
            // Create journal entry
            $current_user = getCurrentUser();
            $description = "Payment {$voucher_number} for PO {$po->po_number} - {$po->supplier_name} - ৳" . 
                          number_format($amount, 2);
            
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
            
            // Create journal transaction lines
            $detail_sql = "INSERT INTO transaction_lines (
                journal_entry_id, account_id, debit_amount, credit_amount, description
            ) VALUES (?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($detail_sql);
            
            // Debit: GRN Pending / Accounts Payable (reduces liability)
            $stmt->execute([
                $journal_id,
                $grn_pending_account->id,
                $amount,
                0,
                "Payment to {$po->supplier_name}"
            ]);
            
            // Credit: Bank / Cash (reduces asset)
            $payment_desc = "Payment via " . ucfirst($data['payment_method']);
            if ($data['payment_method'] === 'bank' && !empty($data['bank_account_id'])) {
                $bank = $this->getBankAccount($data['bank_account_id']);
                $payment_desc .= " - " . $bank->bank_name;
            }
            
            $stmt->execute([
                $journal_id,
                $payment_account->id,
                0,
                $amount,
                $payment_desc
            ]);
            
            return $journal_id;
            
        } catch (Exception $e) {
            error_log("Error creating payment journal entry: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
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
     * Get all active bank accounts with chart of accounts information
     * ✅ SCHEMA-VERIFIED: Column name fixed (name, not display_name)
     * 
     * @return array Bank account list
     */
    public function getAllBankAccounts() {
        $sql = "SELECT 
                    ba.*,
                    coa.name as chart_account_name,
                    coa.account_type as chart_account_type
                FROM bank_accounts ba
                LEFT JOIN chart_of_accounts coa ON ba.chart_of_account_id = coa.id
                WHERE ba.status = 'active' 
                ORDER BY ba.bank_name ASC, ba.branch_name ASC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get all active cash accounts
     * Fetches from branch_petty_cash_accounts table with chart_of_accounts linkage
     * 
     * @return array Cash account list with branch and chart account information
     */
    public function getAllCashAccounts() {
        $sql = "SELECT 
                    pc.id,
                    pc.account_name,
                    pc.current_balance,
                    pc.branch_id,
                    pc.chart_of_account_id,
                    b.name as branch_name,
                    coa.name as chart_account_name,
                    pc.status,
                    '' as account_number,
                    'Cash' as account_type
                FROM branch_petty_cash_accounts pc
                LEFT JOIN branches b ON pc.branch_id = b.id
                LEFT JOIN chart_of_accounts coa ON pc.chart_of_account_id = coa.id
                WHERE pc.status = 'active'
                ORDER BY b.name ASC, pc.account_name ASC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get all active employees
     * ✅ SCHEMA-VERIFIED: Uses position_id and branch_id (no department column exists)
     * 
     * @return array Employee list
     */
    public function getAllEmployees() {
        $sql = "SELECT 
                    id, 
                    CONCAT(first_name, ' ', last_name) as name, 
                    first_name, 
                    last_name, 
                    position_id,
                    branch_id
                FROM employees 
                WHERE status = 'active' 
                ORDER BY first_name ASC";
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
}
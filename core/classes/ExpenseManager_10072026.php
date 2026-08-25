<?php
/**
 * Expense Manager Class - RECTIFIED FOR TWO-STEP APPROVAL
 * Handles all expense-related operations with proper double-entry accounting
 * 
 * Ujjal Flour Mills - SaaS Platform
 * Follows established PurchaseManager pattern
 * 
 * CHANGES FROM ORIGINAL:
 * 1. createExpenseVoucher() now creates with status='pending' (line 332)
 * 2. Journal entry creation moved to approval (lines 359-380 commented)
 * 3. Two new methods added: approveExpenseVoucher() and rejectExpenseVoucher()
 */

class ExpenseManager {
    private $db;
    private $pdo;
    private $user_id;

    public function __construct($db, $user_id = null) {
        $this->db = $db;
        $this->pdo = $db->getPdo();
        $this->user_id = $user_id ?? $_SESSION['user_id'] ?? null;
    }

    // ========================================
    // CATEGORY MANAGEMENT
    // ========================================

    /**
     * Get all expense categories
     */
    public function getAllCategories($active_only = true) {
        $sql = "SELECT ec.*, coa.name as chart_account_name,
                (SELECT COUNT(*) FROM expense_subcategories WHERE category_id = ec.id AND is_active = 1) as subcategory_count
                FROM expense_categories ec
                LEFT JOIN chart_of_accounts coa ON ec.chart_of_account_id = coa.id
                WHERE 1=1";
        
        if ($active_only) {
            $sql .= " AND ec.is_active = 1";
        }
        
        $sql .= " ORDER BY ec.category_name ASC";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            error_log("Error fetching categories: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get category by ID
     */
    public function getCategoryById($id) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM expense_categories WHERE id = :id");
            $stmt->execute(['id' => $id]);
            return $stmt->fetch(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            error_log("Error fetching category: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create expense category
     */
    public function createCategory($data) {
        try {
            $this->pdo->beginTransaction();

            $sql = "INSERT INTO expense_categories (
                category_code, category_name, description, chart_of_account_id, created_by_user_id
            ) VALUES (:code, :name, :desc, :chart_id, :user_id)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'code' => $data['category_code'] ?? null,
                'name' => $data['category_name'],
                'desc' => $data['description'] ?? null,
                'chart_id' => !empty($data['chart_of_account_id']) ? $data['chart_of_account_id'] : null,
                'user_id' => $this->user_id
            ]);

            $category_id = $this->pdo->lastInsertId();
            $this->pdo->commit();

            return ['success' => true, 'message' => 'Category created successfully', 'category_id' => $category_id];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error creating expense category: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to create category: ' . $e->getMessage()];
        }
    }

    /**
     * Update expense category
     */
    public function updateCategory($id, $data) {
        try {
            $this->pdo->beginTransaction();

            $sql = "UPDATE expense_categories SET 
                category_code = :code, 
                category_name = :name, 
                description = :desc, 
                chart_of_account_id = :chart_id
                WHERE id = :id";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'code' => $data['category_code'] ?? null,
                'name' => $data['category_name'],
                'desc' => $data['description'] ?? null,
                'chart_id' => !empty($data['chart_of_account_id']) ? $data['chart_of_account_id'] : null,
                'id' => $id
            ]);

            $this->pdo->commit();
            return ['success' => true, 'message' => 'Category updated successfully'];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error updating expense category: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update category: ' . $e->getMessage()];
        }
    }

    /**
     * Toggle category status
     */
    public function toggleCategoryStatus($id) {
        try {
            $sql = "UPDATE expense_categories SET is_active = NOT is_active WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            return ['success' => true, 'message' => 'Category status updated'];
        } catch (Exception $e) {
            error_log("Error toggling category status: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update status'];
        }
    }

    // ========================================
    // SUBCATEGORY MANAGEMENT
    // ========================================

    /**
     * Get subcategories by category
     */
    public function getSubcategoriesByCategory($category_id, $active_only = true) {
        $sql = "SELECT es.*, coa.name as chart_account_name
                FROM expense_subcategories es
                LEFT JOIN chart_of_accounts coa ON es.chart_of_account_id = coa.id
                WHERE es.category_id = :cat_id";
        
        if ($active_only) {
            $sql .= " AND es.is_active = 1";
        }
        
        $sql .= " ORDER BY es.subcategory_name ASC";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['cat_id' => $category_id]);
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            error_log("Error fetching subcategories: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get subcategory by ID
     */
    public function getSubcategoryById($id) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM expense_subcategories WHERE id = :id");
            $stmt->execute(['id' => $id]);
            return $stmt->fetch(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            error_log("Error fetching subcategory: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create expense subcategory
     */
    public function createSubcategory($data) {
        try {
            $this->pdo->beginTransaction();

            // Validate that category exists
            $category = $this->getCategoryById($data['category_id']);
            if (!$category) {
                throw new Exception("Invalid category selected");
            }

            $sql = "INSERT INTO expense_subcategories (
                category_id, subcategory_code, subcategory_name, description,
                chart_of_account_id, unit_of_measurement, created_by_user_id
            ) VALUES (:cat_id, :code, :name, :desc, :chart_id, :unit, :user_id)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'cat_id' => $data['category_id'],
                'code' => $data['subcategory_code'] ?? null,
                'name' => $data['subcategory_name'],
                'desc' => $data['description'] ?? null,
                'chart_id' => !empty($data['chart_of_account_id']) ? $data['chart_of_account_id'] : null,
                'unit' => $data['unit_of_measurement'] ?? null,
                'user_id' => $this->user_id
            ]);

            $subcategory_id = $this->pdo->lastInsertId();
            $this->pdo->commit();

            return ['success' => true, 'message' => 'Subcategory created successfully', 'subcategory_id' => $subcategory_id];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error creating expense subcategory: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to create subcategory: ' . $e->getMessage()];
        }
    }

    /**
     * Update expense subcategory
     */
    public function updateSubcategory($id, $data) {
        try {
            $this->pdo->beginTransaction();

            $sql = "UPDATE expense_subcategories SET 
                subcategory_code = :code, 
                subcategory_name = :name, 
                description = :desc, 
                chart_of_account_id = :chart_id,
                unit_of_measurement = :unit
                WHERE id = :id";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'code' => $data['subcategory_code'] ?? null,
                'name' => $data['subcategory_name'],
                'desc' => $data['description'] ?? null,
                'chart_id' => !empty($data['chart_of_account_id']) ? $data['chart_of_account_id'] : null,
                'unit' => $data['unit_of_measurement'] ?? null,
                'id' => $id
            ]);

            $this->pdo->commit();
            return ['success' => true, 'message' => 'Subcategory updated successfully'];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error updating expense subcategory: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update subcategory: ' . $e->getMessage()];
        }
    }

    /**
     * Toggle subcategory status
     */
    public function toggleSubcategoryStatus($id) {
        try {
            $sql = "UPDATE expense_subcategories SET is_active = NOT is_active WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            return ['success' => true, 'message' => 'Subcategory status updated'];
        } catch (Exception $e) {
            error_log("Error toggling subcategory status: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update status'];
        }
    }

    // ========================================
    // EXPENSE VOUCHER MANAGEMENT
    // ========================================

    /**
     * Create expense voucher - CREATES WITH PENDING STATUS
     * Journal entries and balance updates happen on approval
     */
    public function createExpenseVoucher($data) {
        try {
            $this->pdo->beginTransaction();

            // Calculate total if using unit method
            if (!empty($data['unit_quantity']) && !empty($data['per_unit_cost'])) {
                $data['total_amount'] = $data['unit_quantity'] * $data['per_unit_cost'];
            }

            // Validate total amount
            if (empty($data['total_amount']) || $data['total_amount'] <= 0) {
                throw new Exception("Invalid total amount");
            }

            // Generate voucher number
            $voucher_number = $this->generateVoucherNumber();

            // Get subcategory and validate it belongs to category
            $subcategory = $this->getSubcategoryById($data['subcategory_id']);
            if (!$subcategory) {
                throw new Exception("Invalid subcategory selected");
            }

            // IMPORTANT: Validate subcategory belongs to selected category
            if ($subcategory->category_id != $data['category_id']) {
                throw new Exception("Selected subcategory does not belong to the selected category");
            }

            // Get payment account details
            $payment_data = $this->getPaymentAccountData($data);
            if (!$payment_data) {
                throw new Exception("Invalid payment account selected");
            }

            // Insert voucher with PENDING status (CHANGED from 'approved')
            $sql = "INSERT INTO expense_vouchers (
                voucher_number, expense_date, category_id, subcategory_id,
                handled_by_person, employee_id, unit_quantity, per_unit_cost, total_amount,
                remarks, payment_method, bank_account_id, cash_account_id,
                payment_account_name, payment_reference, expense_account_id,
                branch_id, status, created_by_user_id
            ) VALUES (
                :voucher_no, :exp_date, :cat_id, :subcat_id,
                :person, :emp_id, :unit_qty, :per_unit, :total,
                :remarks, :pay_method, :bank_id, :cash_id,
                :pay_name, :pay_ref, :exp_acct_id,
                :branch_id, 'pending', :user_id
            )";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'voucher_no' => $voucher_number,
                'exp_date' => $data['expense_date'],
                'cat_id' => $data['category_id'],
                'subcat_id' => $data['subcategory_id'],
                'person' => $data['handled_by_person'] ?? null,
                'emp_id' => !empty($data['employee_id']) ? $data['employee_id'] : null,
                'unit_qty' => !empty($data['unit_quantity']) ? $data['unit_quantity'] : null,
                'per_unit' => !empty($data['per_unit_cost']) ? $data['per_unit_cost'] : null,
                'total' => $data['total_amount'],
                'remarks' => $data['remarks'] ?? null,
                'pay_method' => $data['payment_method'],
                'bank_id' => !empty($data['bank_account_id']) ? $data['bank_account_id'] : null,
                'cash_id' => !empty($data['cash_account_id']) ? $data['cash_account_id'] : null,
                'pay_name' => $payment_data['name'],
                'pay_ref' => $data['payment_reference'] ?? null,
                'exp_acct_id' => $subcategory->chart_of_account_id ?? null,
                'branch_id' => !empty($data['branch_id']) ? $data['branch_id'] : null,
                'user_id' => $this->user_id
            ]);

            $voucher_id = $this->pdo->lastInsertId();

            // COMMENTED OUT - Journal entry and balance updates now happen on approval
            // $journal_id = $this->createExpenseJournalEntry(
            //     $voucher_id,
            //     $voucher_number,
            //     $data,
            //     $subcategory,
            //     $payment_data
            // );

            // if ($journal_id) {
            //     $update_sql = "UPDATE expense_vouchers SET journal_entry_id = :journal_id WHERE id = :voucher_id";
            //     $stmt = $this->pdo->prepare($update_sql);
            //     $stmt->execute(['journal_id' => $journal_id, 'voucher_id' => $voucher_id]);

            //     if ($data['payment_method'] === 'bank') {
            //         $this->updateBankBalance($data['bank_account_id'], -$data['total_amount']);
            //     } else {
            //         $this->updateCashBalance($data['cash_account_id'], -$data['total_amount']);
            //     }
            // }

            $this->pdo->commit();

            // Send Telegram notification - Created (Pending)
            $this->sendTelegramNotification($voucher_id, 'created');

            return [
                'success' => true,
                'message' => "Expense voucher {$voucher_number} created successfully and is pending approval",
                'voucher_id' => $voucher_id,
                'voucher_number' => $voucher_number
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error creating expense voucher: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to create expense: ' . $e->getMessage()];
        }
    }

    /**
     * Get expense vouchers with filters
     */
    public function getExpenseVouchers($filters = []) {
        $sql = "SELECT ev.*, 
                ec.category_name, 
                es.subcategory_name, 
                es.unit_of_measurement,
                b.name as branch_name,
                CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                u.display_name as created_by_name
                FROM expense_vouchers ev
                JOIN expense_categories ec ON ev.category_id = ec.id
                JOIN expense_subcategories es ON ev.subcategory_id = es.id
                LEFT JOIN branches b ON ev.branch_id = b.id
                LEFT JOIN employees e ON ev.employee_id = e.id
                LEFT JOIN users u ON ev.created_by_user_id = u.id
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND ev.expense_date >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND ev.expense_date <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }
        
        if (!empty($filters['category_id'])) {
            $sql .= " AND ev.category_id = :category_id";
            $params['category_id'] = $filters['category_id'];
        }

        if (!empty($filters['subcategory_id'])) {
            $sql .= " AND ev.subcategory_id = :subcategory_id";
            $params['subcategory_id'] = $filters['subcategory_id'];
        }
        
        if (!empty($filters['payment_method'])) {
            $sql .= " AND ev.payment_method = :payment_method";
            $params['payment_method'] = $filters['payment_method'];
        }

        if (!empty($filters['bank_account_id'])) {
            $sql .= " AND ev.bank_account_id = :bank_account_id";
            $params['bank_account_id'] = $filters['bank_account_id'];
        }

        if (!empty($filters['cash_account_id'])) {
            $sql .= " AND ev.cash_account_id = :cash_account_id";
            $params['cash_account_id'] = $filters['cash_account_id'];
        }

        if (!empty($filters['branch_id'])) {
            $sql .= " AND ev.branch_id = :branch_id";
            $params['branch_id'] = $filters['branch_id'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (ev.voucher_number LIKE :search 
                      OR ev.handled_by_person LIKE :search
                      OR es.subcategory_name LIKE :search 
                      OR ev.remarks LIKE :search)";
            $params['search'] = '%' . $filters['search'] . '%';
        }
        
        $sql .= " ORDER BY ev.expense_date DESC, ev.id DESC";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            error_log("Error fetching expense vouchers: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get expense voucher by ID
     */
    public function getExpenseVoucherById($id) {
        $sql = "SELECT ev.*, 
                ec.category_name, 
                es.subcategory_name, 
                es.unit_of_measurement,
                b.name as branch_name,
                CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                u.display_name as created_by_name
                FROM expense_vouchers ev
                JOIN expense_categories ec ON ev.category_id = ec.id
                JOIN expense_subcategories es ON ev.subcategory_id = es.id
                LEFT JOIN branches b ON ev.branch_id = b.id
                LEFT JOIN employees e ON ev.employee_id = e.id
                LEFT JOIN users u ON ev.created_by_user_id = u.id
                WHERE ev.id = :id";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            return $stmt->fetch(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            error_log("Error fetching expense voucher: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get expense summary with filters
     */
    public function getExpenseSummary($filters = []) {
        $sql = "SELECT 
                COUNT(*) as total_vouchers,
                COALESCE(SUM(total_amount), 0) as total_expense,
                COALESCE(SUM(CASE WHEN payment_method = 'bank' THEN total_amount ELSE 0 END), 0) as bank_expenses,
                COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END), 0) as cash_expenses,
                COALESCE(AVG(total_amount), 0) as average_expense
                FROM expense_vouchers ev
                WHERE ev.status = 'approved'";
        
        $params = [];
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND ev.expense_date >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND ev.expense_date <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }
        
        if (!empty($filters['category_id'])) {
            $sql .= " AND ev.category_id = :category_id";
            $params['category_id'] = $filters['category_id'];
        }
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            error_log("Error fetching expense summary: " . $e->getMessage());
            return null;
        }
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    /**
     * Generate unique voucher number
     */
    private function generateVoucherNumber() {
        $prefix = 'EXP-' . date('Ymd') . '-';
        
        $sql = "SELECT COALESCE(MAX(CAST(SUBSTRING(voucher_number, -4) AS UNSIGNED)), 0) AS max_number
                FROM expense_vouchers 
                WHERE voucher_number LIKE :prefix";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['prefix' => $prefix . '%']);
            $result = $stmt->fetch(PDO::FETCH_OBJ);
            
            $next_number = str_pad($result->max_number + 1, 4, '0', STR_PAD_LEFT);
            return $prefix . $next_number;
        } catch (Exception $e) {
            error_log("Error generating voucher number: " . $e->getMessage());
            return $prefix . '0001';
        }
    }

    /**
     * Get payment account data
     */
    private function getPaymentAccountData($data) {
        try {
            if ($data['payment_method'] === 'bank') {
                if (empty($data['bank_account_id'])) {
                    return null;
                }
                $stmt = $this->pdo->prepare("SELECT bank_name, account_name, chart_of_account_id FROM bank_accounts WHERE id = :id");
                $stmt->execute(['id' => $data['bank_account_id']]);
                $bank = $stmt->fetch(PDO::FETCH_OBJ);
                if (!$bank) return null;
                return [
                    'name' => $bank->bank_name . ' - ' . $bank->account_name,
                    'account_id' => $bank->chart_of_account_id
                ];
            } else {
                if (empty($data['cash_account_id'])) {
                    return null;
                }
                $stmt = $this->pdo->prepare("SELECT account_name, chart_of_account_id FROM branch_petty_cash_accounts WHERE id = :id");
                $stmt->execute(['id' => $data['cash_account_id']]);
                $cash = $stmt->fetch(PDO::FETCH_OBJ);
                if (!$cash) return null;
                return [
                    'name' => $cash->account_name,
                    'account_id' => $cash->chart_of_account_id
                ];
            }
        } catch (Exception $e) {
            error_log("Error fetching payment account data: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create journal entry for expense
     */
    private function createExpenseJournalEntry($voucher_id, $voucher_number, $data, $subcategory, $payment_data) {
        try {
            // Create journal entry header
            $sql = "INSERT INTO journal_entries (
                uuid, transaction_date, description, related_document_type, 
                related_document_id, created_by_user_id
            ) VALUES (UUID(), :date, :desc, 'expense_voucher', :doc_id, :user_id)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'date' => $data['expense_date'],
                'desc' => "Expense {$voucher_number} - {$subcategory->subcategory_name}",
                'doc_id' => $voucher_id,
                'user_id' => $this->user_id
            ]);
            
            $journal_id = $this->pdo->lastInsertId();
            
            // DR: Expense Account (if chart account is set)
            if ($subcategory->chart_of_account_id) {
                $sql = "INSERT INTO transaction_lines (journal_entry_id, account_id, debit_amount, credit_amount)
                        VALUES (:journal_id, :acct_id, :amount, 0)";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    'journal_id' => $journal_id,
                    'acct_id' => $subcategory->chart_of_account_id,
                    'amount' => $data['total_amount']
                ]);
            }
            
            // CR: Bank/Cash Account (if chart account is set)
            if ($payment_data['account_id']) {
                $sql = "INSERT INTO transaction_lines (journal_entry_id, account_id, debit_amount, credit_amount)
                        VALUES (:journal_id, :acct_id, 0, :amount)";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    'journal_id' => $journal_id,
                    'acct_id' => $payment_data['account_id'],
                    'amount' => $data['total_amount']
                ]);
            }
            
            return $journal_id;
            
        } catch (Exception $e) {
            error_log("Error creating journal entry: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update bank account balance
     */
    private function updateBankBalance($bank_account_id, $amount) {
        try {
            $sql = "UPDATE bank_accounts SET current_balance = current_balance + :amount WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['amount' => $amount, 'id' => $bank_account_id]);
        } catch (Exception $e) {
            error_log("Error updating bank balance: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update cash account balance
     */
    private function updateCashBalance($cash_account_id, $amount) {
        try {
            $sql = "UPDATE branch_petty_cash_accounts SET current_balance = current_balance + :amount WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['amount' => $amount, 'id' => $cash_account_id]);
        } catch (Exception $e) {
            error_log("Error updating cash balance: " . $e->getMessage());
            throw $e;
        }
    }

    // ========================================
    // DROPDOWN DATA METHODS
    // ========================================

    /**
     * Get expense accounts from chart of accounts (for dropdowns)
     */
    public function getExpenseAccounts() {
        try {
            $sql = "SELECT * FROM chart_of_accounts 
                    WHERE account_type = 'Expense' 
                    AND status = 'active'
                    ORDER BY name ASC";
            $stmt = $this->pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            error_log("Error fetching expense accounts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all bank accounts (for dropdowns)
     */
    public function getAllBankAccounts() {
        try {
            $stmt = $this->pdo->query("SELECT * FROM bank_accounts WHERE status = 'active' ORDER BY bank_name, account_name");
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            error_log("Error fetching bank accounts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all cash accounts (for dropdowns)
     */
    public function getAllCashAccounts() {
        try {
            $sql = "SELECT pc.*, b.name as branch_name 
                    FROM branch_petty_cash_accounts pc
                    LEFT JOIN branches b ON pc.branch_id = b.id
                    WHERE pc.status = 'active'
                    ORDER BY b.name, pc.account_name";
            $stmt = $this->pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            error_log("Error fetching cash accounts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all branches
     */
    public function getAllBranches() {
        try {
            $stmt = $this->pdo->query("SELECT * FROM branches WHERE status = 'active' ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            error_log("Error fetching branches: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all employees
     */
    public function getAllEmployees() {
        try {
            $stmt = $this->pdo->query("SELECT * FROM employees WHERE status = 'active' ORDER BY first_name, last_name");
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            error_log("Error fetching employees: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get expense voucher details for Telegram notification
     */
    public function getExpenseVoucherForNotification($voucher_id) {
        try {
            $sql = "SELECT ev.*, 
                           ec.category_name,
                           es.subcategory_name,
                           es.unit_of_measurement,
                           b.name as branch_name,
                           CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                           u.display_name as created_by_name
                    FROM expense_vouchers ev
                    LEFT JOIN expense_categories ec ON ev.category_id = ec.id
                    LEFT JOIN expense_subcategories es ON ev.subcategory_id = es.id
                    LEFT JOIN branches b ON ev.branch_id = b.id
                    LEFT JOIN employees e ON ev.employee_id = e.id
                    LEFT JOIN users u ON ev.created_by_user_id = u.id
                    WHERE ev.id = :id";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $voucher_id]);
            return $stmt->fetch(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            error_log("Error fetching expense voucher for notification: " . $e->getMessage());
            return null;
        }
    }

    // ========================================
    // NEW METHODS FOR APPROVAL WORKFLOW
    // ========================================

    /**
     * Approve expense voucher
     * This is where journal entries are created and balances are updated
     */
    public function approveExpenseVoucher($voucher_id, $approver_id = null) {
        try {
            $this->pdo->beginTransaction();

            $approver_id = $approver_id ?? $this->user_id;

            // Get voucher details with all required info
            $sql = "SELECT ev.*, 
                           es.chart_of_account_id, 
                           es.subcategory_name,
                           es.category_id
                    FROM expense_vouchers ev
                    JOIN expense_subcategories es ON ev.subcategory_id = es.id
                    WHERE ev.id = :id AND ev.status = 'pending'";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $voucher_id]);
            $voucher = $stmt->fetch(PDO::FETCH_OBJ);

            if (!$voucher) {
                throw new Exception("Voucher not found or already processed");
            }

            // Get payment account data
            $payment_data = $this->getPaymentAccountData([
                'payment_method' => $voucher->payment_method,
                'bank_account_id' => $voucher->bank_account_id,
                'cash_account_id' => $voucher->cash_account_id
            ]);

            if (!$payment_data) {
                throw new Exception("Invalid payment account");
            }

            // Update status to approved
            $sql = "UPDATE expense_vouchers 
                    SET status = 'approved',
                        approved_by_user_id = :approver_id,
                        approved_at = NOW()
                    WHERE id = :id";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['approver_id' => $approver_id, 'id' => $voucher_id]);

            // NOW create journal entry (this is where accounting happens)
            $journal_id = $this->createExpenseJournalEntry(
                $voucher_id,
                $voucher->voucher_number,
                (array)$voucher, // Convert object to array
                $voucher, // Pass as object for subcategory
                $payment_data
            );

            // Update voucher with journal entry ID
            if ($journal_id) {
                $update_sql = "UPDATE expense_vouchers SET journal_entry_id = :journal_id WHERE id = :voucher_id";
                $stmt = $this->pdo->prepare($update_sql);
                $stmt->execute(['journal_id' => $journal_id, 'voucher_id' => $voucher_id]);

                // NOW update balances
                if ($voucher->payment_method === 'bank' && $voucher->bank_account_id) {
                    $this->updateBankBalance($voucher->bank_account_id, -$voucher->total_amount);
                } elseif ($voucher->payment_method === 'cash' && $voucher->cash_account_id) {
                    $this->updateCashBalance($voucher->cash_account_id, -$voucher->total_amount);
                }
            }

            $this->pdo->commit();

            // Send Telegram notification - Approved
            $this->sendTelegramNotification($voucher_id, 'approved');

            return [
                'success' => true,
                'message' => "Expense voucher {$voucher->voucher_number} approved successfully",
                'voucher_id' => $voucher_id
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error approving expense: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to approve expense: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Reject expense voucher
     */
    public function rejectExpenseVoucher($voucher_id, $rejection_reason, $approver_id = null) {
        try {
            $approver_id = $approver_id ?? $this->user_id;

            $sql = "UPDATE expense_vouchers 
                    SET status = 'rejected',
                        approved_by_user_id = :approver_id,
                        approved_at = NOW(),
                        rejection_reason = :reason
                    WHERE id = :id AND status = 'pending'";
            
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                'approver_id' => $approver_id,
                'reason' => $rejection_reason,
                'id' => $voucher_id
            ]);

            if ($stmt->rowCount() === 0) {
                throw new Exception("Voucher not found or already processed");
            }

            // Send Telegram notification - Rejected
            $this->sendTelegramNotification($voucher_id, 'rejected');

            return [
                'success' => true,
                'message' => 'Expense voucher rejected',
                'voucher_id' => $voucher_id
            ];

        } catch (Exception $e) {
            error_log("Error rejecting expense: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to reject expense: ' . $e->getMessage()
            ];
        }
    }
    
    
/**
 * DELETE EXPENSE VOUCHER METHOD
 * Add this method to your ExpenseManager.php class
 * 
 * This method handles complete deletion of expense voucher including:
 * - Reversing journal entries
 * - Updating account balances
 * - Deleting the voucher record
 */

/**
 * Delete expense voucher (Superadmin only)
 * 
 * @param int $voucher_id The expense voucher ID to delete
 * @return array Result array with success status and message
 */
    public function deleteExpenseVoucher($voucher_id) {
    try {
        $this->pdo->beginTransaction();
        
        // Get voucher details
        $stmt = $this->pdo->prepare("
            SELECT * FROM expense_vouchers 
            WHERE id = :id
        ");
        $stmt->execute(['id' => $voucher_id]);
        $voucher = $stmt->fetch(PDO::FETCH_OBJ);
        
        if (!$voucher) {
            throw new Exception('Expense voucher not found');
        }
        
        // Only allow deletion of approved vouchers (pending can just be rejected)
        if ($voucher->status === 'pending') {
            throw new Exception('Cannot delete pending vouchers. Please reject instead.');
        }
        
        // If approved, need to reverse the accounting entries
        if ($voucher->status === 'approved') {
            // Get the expense account (debit account)
            $stmt = $this->pdo->prepare("
                SELECT chart_of_account_id FROM expense_subcategories WHERE id = :subcategory_id
            ");
            $stmt->execute(['subcategory_id' => $voucher->subcategory_id]);
            $expense_account_id = $stmt->fetchColumn();
            
            if (!$expense_account_id) {
                throw new Exception('Expense account not found');
            }
            
            // Get the payment account (credit account)
            $payment_account = $this->getPaymentAccountData([
                'payment_method' => $voucher->payment_method,
                'bank_account_id' => $voucher->bank_account_id,
                'cash_account_id' => $voucher->cash_account_id
            ]);
            
            if (!$payment_account) {
                throw new Exception('Payment account not found');
            }
            
            // Create REVERSING journal entry
            $reversal_description = "REVERSAL: {$voucher->remarks} (Deleted Voucher: {$voucher->voucher_number})";
            
            // Insert reversing journal header
            $stmt = $this->pdo->prepare("
                INSERT INTO journal_entries (
                    transaction_date, 
                    related_document_type, 
                    related_document_id, 
                    description,
                    created_by_user_id
                ) VALUES (
                    CURDATE(),
                    'expense_reversal',
                    :voucher_id,
                    :description,
                    :user_id
                )
            ");
            
            $stmt->execute([
                'voucher_id' => $voucher_id,
                'description' => $reversal_description,
                'user_id' => $_SESSION['user_id'] ?? null
            ]);
            
            $journal_entry_id = $this->pdo->lastInsertId();
            
            // REVERSE: Credit the expense account (opposite of original debit)
            $stmt = $this->pdo->prepare("
                INSERT INTO transaction_lines (
                    journal_entry_id,
                    account_id,
                    debit_amount,
                    credit_amount
                ) VALUES (
                    :journal_entry_id,
                    :account_id,
                    0,
                    :amount
                )
            ");
            
            $stmt->execute([
                'journal_entry_id' => $journal_entry_id,
                'account_id' => $expense_account_id,
                'amount' => $voucher->total_amount
            ]);
            
            // REVERSE: Debit the payment account (opposite of original credit)
            $stmt->execute([
                'journal_entry_id' => $journal_entry_id,
                'account_id' => $payment_account['account_id'],
                'amount' => $voucher->total_amount
            ]);
            
            // Update account balances
            // Note: chart_of_accounts table doesn't have current_balance column
            // Balance is calculated from transaction_lines, not stored
            
            // Reverse the payment account (restore cash/bank)
            if ($voucher->payment_method === 'bank') {
                $this->updateBankBalance($voucher->bank_account_id, $voucher->total_amount, 'add');
            } elseif ($voucher->payment_method === 'cash') {
                $this->updateCashBalance($voucher->cash_account_id, $voucher->total_amount, 'add');
            }
        }
        
        // Finally, delete the expense voucher
        $stmt = $this->pdo->prepare("DELETE FROM expense_vouchers WHERE id = :id");
        $stmt->execute(['id' => $voucher_id]);
        
        $this->pdo->commit();
        
        return [
            'success' => true,
            'message' => "Expense voucher {$voucher->voucher_number} deleted successfully"
        ];
        
    } catch (Exception $e) {
        $this->pdo->rollBack();
        
        return [
            'success' => false,
            'message' => 'Failed to delete expense: ' . $e->getMessage()
        ];
    }
}


    /**
     * Send Telegram notification for expense voucher
     */
    private function sendTelegramNotification($voucher_id, $action = 'created') {
        try {
            // Check if TelegramNotifier class exists
            if (!class_exists('TelegramNotifier')) {
                return; // Silently skip if not available
            }

            // Get Telegram credentials
            if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID')) {
                error_log("Telegram credentials not configured in config.php");
                return; // No credentials configured
            }

            // Get voucher details
            $voucher = $this->getExpenseVoucherForNotification($voucher_id);
            if (!$voucher) {
                return;
            }

            // Create TelegramNotifier with credentials
            $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
            
            // Build message based on action (using HTML format for TelegramNotifier)
            if ($action === 'created') {
                $message = "🧾 <b>EXPENSE VOUCHER CREATED</b>\n\n";
                
                $message .= "📋 <b>Voucher:</b> <code>{$voucher->voucher_number}</code>\n";
                $message .= "📅 <b>Date:</b> " . date('d M Y', strtotime($voucher->expense_date)) . "\n\n";
                
                $message .= "💰 <b>Amount:</b> ৳" . number_format($voucher->total_amount, 2) . "\n\n";
                
                $message .= "📂 <b>Category:</b> {$voucher->category_name}\n";
                $message .= "📌 <b>Subcategory:</b> {$voucher->subcategory_name}";
                if ($voucher->unit_of_measurement) {
                    $message .= " ({$voucher->unit_of_measurement})";
                }
                $message .= "\n\n";
                
                $message .= "💳 <b>Payment:</b> " . ucfirst($voucher->payment_method) . "\n";
                
                if ($voucher->branch_name) {
                    $message .= "🏢 <b>Branch:</b> {$voucher->branch_name}\n";
                }
                
                if ($voucher->handled_by_person) {
                    $message .= "👤 <b>Handled By:</b> {$voucher->handled_by_person}\n";
                }
                
                if ($voucher->remarks) {
                    $message .= "\n📝 <b>Remarks:</b> {$voucher->remarks}\n";
                }
                
                $message .= "\n✅ <b>Created By:</b> {$voucher->created_by_name}\n";
                $message .= "\n⚡ <b>Status:</b> <i>PENDING APPROVAL</i>";
                
            } elseif ($action === 'approved') {
                $approver_name = $_SESSION['user_display_name'] ?? 'Admin';
                
                $message = "✅ <b>EXPENSE VOUCHER APPROVED</b>\n\n";
                
                $message .= "📋 <b>Voucher:</b> <code>{$voucher->voucher_number}</code>\n";
                $message .= "📅 <b>Date:</b> " . date('d M Y', strtotime($voucher->expense_date)) . "\n\n";
                
                $message .= "💰 <b>Amount:</b> ৳" . number_format($voucher->total_amount, 2) . "\n\n";
                
                $message .= "📂 <b>Category:</b> {$voucher->category_name}\n";
                $message .= "📌 <b>Subcategory:</b> {$voucher->subcategory_name}\n\n";
                
                $message .= "💳 <b>Payment:</b> " . ucfirst($voucher->payment_method) . "\n";
                
                if ($voucher->branch_name) {
                    $message .= "🏢 <b>Branch:</b> {$voucher->branch_name}\n";
                }
                
                $message .= "\n✅ <b>Approved By:</b> {$approver_name}\n";
                $message .= "🕐 <b>Approved At:</b> " . date('d M Y, h:i A') . "\n";
                
            } elseif ($action === 'rejected') {
                $approver_name = $_SESSION['user_display_name'] ?? 'Admin';
                
                $message = "❌ <b>EXPENSE VOUCHER REJECTED</b>\n\n";
                
                $message .= "📋 <b>Voucher:</b> <code>{$voucher->voucher_number}</code>\n";
                $message .= "📅 <b>Date:</b> " . date('d M Y', strtotime($voucher->expense_date)) . "\n\n";
                
                $message .= "💰 <b>Amount:</b> ৳" . number_format($voucher->total_amount, 2) . "\n\n";
                
                $message .= "📂 <b>Category:</b> {$voucher->category_name}\n";
                $message .= "📌 <b>Subcategory:</b> {$voucher->subcategory_name}\n\n";
                
                if ($voucher->branch_name) {
                    $message .= "🏢 <b>Branch:</b> {$voucher->branch_name}\n\n";
                }
                
                $message .= "❌ <b>Rejected By:</b> {$approver_name}\n";
                $message .= "🕐 <b>Rejected At:</b> " . date('d M Y, h:i A') . "\n";
                
                // Get rejection reason
                $stmt = $this->pdo->prepare("SELECT rejection_reason FROM expense_vouchers WHERE id = :id");
                $stmt->execute(['id' => $voucher_id]);
                $result = $stmt->fetch(PDO::FETCH_OBJ);
                if ($result && $result->rejection_reason) {
                    $message .= "\n📝 <b>Rejection Reason:</b> {$result->rejection_reason}\n";
                }
            }
            
            // Send notification (no footer needed - user's format doesn't have one)
            if (isset($message)) {
                $telegram->sendMessage($message);
            }
            
        } catch (Exception $e) {
            // Don't throw - notifications are optional
            error_log("Telegram notification error: " . $e->getMessage());
        }
    }

    /**
     * Update an existing expense voucher (pending only)
     * @param int $voucher_id
     * @param array $data
     * @return array
     */
    public function updateExpenseVoucher($voucher_id, $data) {
        try {
            $this->pdo->beginTransaction();
            
            // Get current voucher
            $stmt = $this->pdo->prepare("SELECT * FROM expense_vouchers WHERE id = :id");
            $stmt->execute(['id' => $voucher_id]);
            $voucher = $stmt->fetch(PDO::FETCH_OBJ);
            
            if (!$voucher) {
                throw new Exception('Expense voucher not found');
            }
            
            // Only allow updating pending expenses
            if ($voucher->status !== 'pending') {
                throw new Exception('Only pending expenses can be edited');
            }
            
            // Update the voucher
            $stmt = $this->pdo->prepare("
                UPDATE expense_vouchers SET
                    expense_date = :expense_date,
                    category_id = :category_id,
                    subcategory_id = :subcategory_id,
                    branch_id = :branch_id,
                    total_amount = :total_amount,
                    handled_by_person = :handled_by_person,
                    remarks = :remarks,
                    payment_method = :payment_method,
                    bank_account_id = :bank_account_id,
                    cash_account_id = :cash_account_id,
                    payment_reference = :payment_reference,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $stmt->execute([
                'expense_date' => $data['expense_date'],
                'category_id' => $data['category_id'],
                'subcategory_id' => $data['subcategory_id'],
                'branch_id' => !empty($data['branch_id']) ? (int)$data['branch_id'] : null,
                'total_amount' => $data['total_amount'],
                'handled_by_person' => $data['handled_by_person'] ?? '',
                'remarks' => $data['remarks'] ?? '',
                'payment_method' => $data['payment_method'],
                'bank_account_id' => !empty($data['bank_account_id']) ? (int)$data['bank_account_id'] : null,
                'cash_account_id' => !empty($data['cash_account_id']) ? (int)$data['cash_account_id'] : null,
                'payment_reference' => $data['payment_reference'] ?? '',
                'id' => $voucher_id
            ]);
            
            // Log the update action
            $stmt = $this->pdo->prepare("
                INSERT INTO expense_action_log (
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
                    'edited',
                    :action_by,
                    'pending',
                    'pending',
                    'Expense voucher details updated',
                    :ip,
                    :user_agent
                )
            ");
            
            $stmt->execute([
                'voucher_id' => $voucher_id,
                'action_by' => $_SESSION['user_id'] ?? null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Expense voucher updated successfully'
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
<?php
class Payroll {
    private $pdo;
    private $employee_handler;
    private $loan_handler;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->employee_handler = new Employee($this->pdo);
        $this->loan_handler = new Loan($this->pdo);
    }

    // In core/classes/Payroll.php, replace the prepare_bulk_payroll method

    public function prepare_bulk_payroll($pay_period_start, $pay_period_end) {
    // --- NEW: DUPLICATE PAYMENT CHECK ---
    // Check if a payroll run for this exact period already exists and is not rejected.
    $check_stmt = $this->pdo->prepare("
        SELECT id FROM payrolls 
        WHERE pay_period_start = ? AND pay_period_end = ? AND status != 'rejected'
        LIMIT 1
    ");
    $check_stmt->execute([$pay_period_start, $pay_period_end]);
    if ($check_stmt->fetch()) {
        // If a record is found, it means payroll has already been run or is in progress.
        // Return an error instead of creating duplicates.
        return ['success' => false, 'message' => 'Payroll for this period has already been prepared or processed. Please check the Payroll History.'];
    }
    // --- END CHECK ---

    $active_employees = $this->employee_handler->get_all_active();
    $results = ['success' => true, 'success_count' => 0, 'fail_count' => 0, 'prepared_ids' => []];

    foreach ($active_employees as $employee) {
        $employee_id = $employee['id'];
        
        $gross_salary = $employee['base_salary'];
        
        $present_days = $this->get_attendance_days($employee_id, $pay_period_start, $pay_period_end);
        $absent_days = 30 - $present_days;
        $daily_rate = $gross_salary / 30;
        $absence_deduction = ($absent_days > 0) ? $daily_rate * $absent_days : 0;

        $stmt_adv = $this->pdo->prepare("SELECT SUM(amount) as amount FROM salary_advances WHERE employee_id = ? AND advance_month = ? AND advance_year = ? AND status = 'approved'");
        $stmt_adv->execute([$employee_id, date('m', strtotime($pay_period_start)), date('Y', strtotime($pay_period_start))]);
        $advance = $stmt_adv->fetch();
        $advance_deduction = $advance['amount'] ?? 0;
        
        $active_loan = $this->loan_handler->get_active_loan_by_employee($employee_id);
        $loan_deduction = ($active_loan && $active_loan['installment_type'] === 'fixed') ? $active_loan['monthly_payment'] : 0;
        
        $total_deductions = $absence_deduction + $advance_deduction + $loan_deduction;
        $net_salary = $gross_salary - $total_deductions;

        $stmt = $this->pdo->prepare(
            'INSERT INTO payrolls (employee_id, pay_period_start, pay_period_end, gross_salary, deductions, net_salary, status) '
            . 'VALUES (?, ?, ?, ?, ?, ?, \'pending_approval\')'
        );
        
        if ($stmt->execute([$employee_id, $pay_period_start, $pay_period_end, $gross_salary, $total_deductions, $net_salary])) {
            $results['success_count']++;
            $results['prepared_ids'][] = $this->pdo->lastInsertId();
        } else {
            $results['fail_count']++;
        }
    }
    return $results;
}

    public function get_pending_payroll_details() {
        // *** THE FINAL CORRECTED SQL QUERY ***
        $sql = "
            SELECT 
                p.id, p.pay_period_start, p.pay_period_end, p.gross_salary, p.net_salary,
                e.id as employee_id, e.first_name, e.last_name, e.address, e.base_salary,
                pos.name as position_name,
                -- Subquery to get the remaining balance of 'random' loans by subtracting installments
                (SELECT l.amount - COALESCE((SELECT SUM(li.amount) FROM loan_installments li WHERE li.loan_id = l.id), 0) 
                 FROM loans l 
                 WHERE l.employee_id = e.id AND l.installment_type = 'random' AND l.status = 'active' LIMIT 1) as other_loan_balance,
                -- Subquery to get the ID of that same 'random' loan
                (SELECT l.id 
                 FROM loans l 
                 WHERE l.employee_id = e.id AND l.installment_type = 'random' AND l.status = 'active' LIMIT 1) as random_loan_id
            FROM payrolls p
            JOIN employees e ON p.employee_id = e.id
            LEFT JOIN positions pos ON e.position_id = pos.id
            WHERE p.status = 'pending_approval'
            ORDER BY p.pay_period_start, e.last_name
        ";
        $stmt = $this->pdo->query($sql);
        $payrolls = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $detailed_payrolls = [];
        foreach ($payrolls as $payroll) {
            $start_date = $payroll['pay_period_start'];
            
            $present_days = $this->get_attendance_days($payroll['employee_id'], $start_date, $payroll['pay_period_end']);
            $payroll['absent_days'] = 30 - $present_days;
            $daily_rate = $payroll['base_salary'] / 30;
            $payroll['salary_deducted_for_absent'] = ($payroll['absent_days'] > 0) ? $daily_rate * $payroll['absent_days'] : 0;
            
            $stmt_adv = $this->pdo->prepare("SELECT SUM(amount) as amount FROM salary_advances WHERE employee_id = ? AND advance_month = ? AND advance_year = ? AND status = 'approved'");
            $stmt_adv->execute([$payroll['employee_id'], date('m', strtotime($start_date)), date('Y', strtotime($start_date))]);
            $advance = $stmt_adv->fetch();
            $payroll['advance_salary_deducted'] = $advance['amount'] ?? 0;
            
            $active_loan = $this->loan_handler->get_active_loan_by_employee($payroll['employee_id']);
            $payroll['loan_deduction'] = ($active_loan && $active_loan['installment_type'] === 'fixed') ? $active_loan['monthly_payment'] : 0;
            
            $detailed_payrolls[] = $payroll;
        }
        
        return $detailed_payrolls;
    }
    
    public function update_single_payroll_record($payroll_id, $data) {
        $gross_salary = (float)($data['gross_salary'] ?? 0);
        $total_deductions = ($data['absence_deduction'] ?? 0) + 
                              ($data['advance_deduction'] ?? 0) + 
                              ($data['loan_deduction'] ?? 0) +
                              ($data['other_loan_repayment'] ?? 0);
                              
        $net_salary = $gross_salary - $total_deductions;

        $sql = "UPDATE payrolls SET deductions = :deductions, net_salary = :net_salary WHERE id = :payroll_id AND status = 'pending_approval'";
        $stmt = $this->pdo->prepare($sql);
        
        $success = $stmt->execute([
            ':deductions' => $total_deductions,
            ':net_salary' => $net_salary,
            ':payroll_id' => $payroll_id
        ]);

        if ($success && isset($data['other_loan_repayment']) && $data['other_loan_repayment'] > 0 && isset($data['random_loan_id']) && $data['random_loan_id'] > 0) {
            $installment_data = [
                'loan_id' => $data['random_loan_id'],
                'payroll_id' => $payroll_id,
                'amount' => $data['other_loan_repayment']
            ];
            $this->loan_handler->record_installment($installment_data);
        }
        
        return $success;
    }

    public function update_payroll_status($payroll_ids, $new_status) {
        if (empty($payroll_ids) || !is_array($payroll_ids)) {
            return false;
        }
        
        $allowed_statuses = ['pending_approval', 'approved', 'rejected', 'disbursed', 'paid'];
        if (!in_array($new_status, $allowed_statuses)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($payroll_ids), '?'));
        $sql = "UPDATE payrolls SET status = ? WHERE id IN ($placeholders)";
        
        $params = array_merge([$new_status], $payroll_ids);
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }
    
    public function process_disbursement($payroll_ids) {
        if (empty($payroll_ids)) {
            return false;
        }
        
        foreach ($payroll_ids as $payroll_id) {
            $payroll_details = $this->get_payroll_details($payroll_id);
            if (!$payroll_details) continue;

            $active_loan = $this->loan_handler->get_active_loan_by_employee($payroll_details['employee_id']);
            
            if ($active_loan && $active_loan['installment_type'] === 'fixed' && $payroll_details['deductions'] > 0) {
                $installment_data = [
                    'loan_id' => $active_loan['id'],
                    'payroll_id' => $payroll_id,
                    'amount' => $active_loan['monthly_payment']
                ];
                $this->loan_handler->record_installment($installment_data);

                $total_paid = $this->get_total_loan_paid($active_loan['id']);
                if ($total_paid >= $active_loan['amount']) {
                    $this->loan_handler->update_loan_status($active_loan['id'], 'paid');
                }
            }
        }
        
        return $this->update_payroll_status($payroll_ids, 'disbursed');
    }
    
    public function get_payroll_history() {
        $stmt = $this->pdo->query(
            'SELECT p.*, e.first_name, e.last_name FROM payrolls p '
            . 'JOIN employees e ON p.employee_id = e.id ORDER BY p.pay_period_end DESC'
        );
        return $stmt->fetchAll();
    }

    public function get_payroll_details($payroll_id) {
        $stmt = $this->pdo->prepare('SELECT * FROM payrolls WHERE id = ?');
        $stmt->execute([$payroll_id]);
        return $stmt->fetch();
    }
    
    private function get_total_loan_paid($loan_id) {
        $stmt = $this->pdo->prepare('SELECT SUM(amount) as total FROM loan_installments WHERE loan_id = ?');
        $stmt->execute([$loan_id]);
        return $stmt->fetchColumn();
    }

    private function get_attendance_days($employee_id, $start_date, $end_date) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as present_days 
            FROM attendance 
            WHERE employee_id = ? 
            AND DATE(clock_in) BETWEEN ? AND ? 
            AND status = 'present'
        ");
        $stmt->execute([$employee_id, $start_date, $end_date]);
        $result = $stmt->fetch();
        return (int)$result['present_days'];
    }
}
?>
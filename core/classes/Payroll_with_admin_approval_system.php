<?php
class Payroll {
    private $pdo;
    private $employee_handler; // Add handlers as properties
    private $loan_handler;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        // It's good practice to instantiate handlers here
        $this->employee_handler = new Employee($this->pdo);
        $this->loan_handler = new Loan($this->pdo);
    }

    /**
     * Prepares the payroll for a given period and saves it with a 'pending_approval' status.
     * This is the first step, initiated by the Accounts team.
     */
    // In core/classes/Payroll.php, replace the get_pending_payroll_details method

public function get_pending_payroll_details() {
    $sql = "
        SELECT 
            p.id, p.pay_period_start, p.pay_period_end, p.gross_salary, p.net_salary,
            e.id as employee_id, e.first_name, e.last_name, e.address, e.base_salary,
            pos.name as position_name
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
        
        // Calculate absent days based on a 30-day month for consistency
        $present_days = $this->get_attendance_days($payroll['employee_id'], $start_date, $payroll['pay_period_end']);
        $payroll['absent_days'] = 30 - $present_days;
        
        // Calculate absence deduction for display
        $daily_rate = $payroll['base_salary'] / 30;
        $payroll['salary_deducted_for_absent'] = ($payroll['absent_days'] > 0) ? $daily_rate * $payroll['absent_days'] : 0;

        // Fetch advance deduction for display
        $stmt_adv = $this->pdo->prepare("SELECT SUM(amount) as amount FROM salary_advances WHERE employee_id = ? AND advance_month = ? AND advance_year = ? AND status = 'approved'");
        $stmt_adv->execute([$payroll['employee_id'], date('m', strtotime($start_date)), date('Y', strtotime($start_date))]);
        $advance = $stmt_adv->fetch();
        $payroll['advance_salary_deducted'] = $advance['amount'] ?? 0;
        
        // Fetch loan deduction for display
        $active_loan = $this->loan_handler->get_active_loan_by_employee($payroll['employee_id']);
        $payroll['loan_deduction'] = ($active_loan) ? $active_loan['monthly_payment'] : 0;
        
        // We fetch Gross and Net salary directly from the database now, as they were calculated correctly on preparation
        $detailed_payrolls[] = $payroll;
    }
    
    return $detailed_payrolls;
}
    
    // In core/classes/Payroll.php, replace the prepare_bulk_payroll method

public function prepare_bulk_payroll($pay_period_start, $pay_period_end) {
    $active_employees = $this->employee_handler->get_all_active();
    $results = ['success_count' => 0, 'fail_count' => 0];

    foreach ($active_employees as $employee) {
        $employee_id = $employee['id'];
        
        // 1. Fetch base_salary from employees table (This is now our Gross Salary)
        $gross_salary = $employee['base_salary'];
        
        // 2. Fetch number of days present
        $present_days = $this->get_attendance_days($employee_id, $pay_period_start, $pay_period_end);
        
        // 3. Calculate absence deduction based on a 30-day month
        $daily_rate = $gross_salary / 30;
        $absence_deduction = ($gross_salary) - ($daily_rate * $present_days);
        
        // 4. Fetch salary advance for the month
        $stmt_adv = $this->pdo->prepare("SELECT SUM(amount) as amount FROM salary_advances WHERE employee_id = ? AND advance_month = ? AND advance_year = ? AND status = 'approved'");
        $stmt_adv->execute([$employee_id, date('m', strtotime($pay_period_start)), date('Y', strtotime($pay_period_start))]);
        $advance = $stmt_adv->fetch();
        $advance_deduction = $advance['amount'] ?? 0;

        // 5. Fetch EMI for this month
        $active_loan = $this->loan_handler->get_active_loan_by_employee($employee_id);
        $loan_deduction = ($active_loan) ? $active_loan['monthly_payment'] : 0;
        
        // 6. Sum all deductions
        $total_deductions = $absence_deduction + $advance_deduction + $loan_deduction;
        
        // 7. Calculate Net Salary
        $net_salary = $gross_salary - $total_deductions;

        // Insert the calculated values into the database
        $stmt = $this->pdo->prepare(
            'INSERT INTO payrolls (employee_id, pay_period_start, pay_period_end, gross_salary, deductions, net_salary, status) '
            . 'VALUES (?, ?, ?, ?, ?, ?, \'pending_approval\')'
        );
        
        if ($stmt->execute([$employee_id, $pay_period_start, $pay_period_end, $gross_salary, $total_deductions, $net_salary])) {
            $results['success_count']++;
        } else {
            $results['fail_count']++;
        }
    }
    return $results;
}

    /**
     * A generic function to update the status of one or more payroll records.
     * This will be used by Admin for approval/rejection and Accounts for marking paid.
     */
    public function update_payroll_status($payroll_ids, $new_status) {
        if (empty($payroll_ids) || !is_array($payroll_ids)) {
            return false;
        }
        
        // Ensure status is a valid enum value to prevent SQL injection
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
    
    /**
     * Processes loan installments after a payroll has been approved and is ready for disbursement.
     */
    public function process_disbursement($payroll_ids) {
        if (empty($payroll_ids)) {
            return false;
        }
        
        foreach ($payroll_ids as $payroll_id) {
            $payroll_details = $this->get_payroll_details($payroll_id);
            if (!$payroll_details) continue;

            $active_loan = $this->loan_handler->get_active_loan_by_employee($payroll_details['employee_id']);
            
            if ($active_loan && $payroll_details['deductions'] > 0) {
                $installment_data = [
                    'loan_id' => $active_loan['id'],
                    'payroll_id' => $payroll_id, // Link to the specific payroll run
                    'amount' => $active_loan['monthly_payment']
                ];
                $this->loan_handler->record_installment($installment_data);

                // Check if loan is fully paid
                $total_paid = $this->get_total_loan_paid($active_loan['id']);
                if ($total_paid >= $active_loan['amount']) {
                    $this->loan_handler->update_loan_status($active_loan['id'], 'paid');
                }
            }
        }
        
        // Finally, update the status to 'disbursed'
        return $this->update_payroll_status($payroll_ids, 'disbursed');
    }

    // --- Keep your existing helper methods ---
    
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
        $payroll = $stmt->fetch();

        if ($payroll) {
            $stmt = $this->pdo->prepare('SELECT * FROM payroll_items WHERE payroll_id = ?');
            $stmt->execute([$payroll_id]);
            $payroll['items'] = $stmt->fetchAll();
        }

        return $payroll;
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
    
    private function run_simple_payroll($data) {
        // Extract only the required parameters for the SQL query
        $payroll_data = [
            'employee_id' => $data['employee_id'],
            'pay_period_start' => $data['pay_period_start'],
            'pay_period_end' => $data['pay_period_end'],
            'gross_salary' => $data['gross_salary'],
            'deductions' => $data['deductions'],
            'net_salary' => $data['net_salary']
        ];
        
        $stmt = $this->pdo->prepare(
            'INSERT INTO payrolls (employee_id, pay_period_start, pay_period_end, gross_salary, deductions, net_salary) '
            . 'VALUES (:employee_id, :pay_period_start, :pay_period_end, :gross_salary, :deductions, :net_salary)'
        );
        $success = $stmt->execute($payroll_data);
        if ($success) {
            return $this->pdo->lastInsertId();
        }
        return false;
    }

}
?>
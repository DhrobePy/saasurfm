<?php
class Payroll {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function run_payroll($employee_id, $start_date, $end_date, $allowances = [], $deductions = []) {
        $employee_handler = new Employee($this->pdo);
        $employee = $employee_handler->get_by_id($employee_id);

        if (!$employee) {
            return false;
        }

        $gross_salary = $employee['base_salary'];
        $total_allowances = 0;
        $total_deductions = 0;

        foreach ($allowances as $allowance) {
            $total_allowances += $allowance['amount'];
        }

        foreach ($deductions as $deduction) {
            $total_deductions += $deduction['amount'];
        }

        $net_salary = $gross_salary + $total_allowances - $total_deductions;

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO payrolls (employee_id, pay_period_start, pay_period_end, gross_salary, deductions, net_salary) '
                . 'VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$employee_id, $start_date, $end_date, $gross_salary, $total_deductions, $net_salary]);
            $payroll_id = $this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare('INSERT INTO payroll_items (payroll_id, type, name, amount) VALUES (?, ?, ?, ?)');

            foreach ($allowances as $allowance) {
                $stmt->execute([$payroll_id, 'allowance', $allowance['name'], $allowance['amount']]);
            }

            foreach ($deductions as $deduction) {
                $stmt->execute([$payroll_id, 'deduction', $deduction['name'], $deduction['amount']]);
            }

            $this->pdo->commit();
            return $payroll_id;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
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
        $payroll = $stmt->fetch();

        if ($payroll) {
            $stmt = $this->pdo->prepare('SELECT * FROM payroll_items WHERE payroll_id = ?');
            $stmt->execute([$payroll_id]);
            $payroll['items'] = $stmt->fetchAll();
        }

        return $payroll;
    }

    public function run_bulk_payroll($pay_period_start, $pay_period_end) {
        $employee_handler = new Employee($this->pdo);
        $loan_handler = new Loan($this->pdo);
        $active_employees = $employee_handler->get_all_active();

        $results = [
            'success_count' => 0,
            'fail_count' => 0,
            'success_details' => [],
            'fail_details' => []
        ];

        foreach ($active_employees as $employee) {
            $employee_id = $employee['id'];
            $monthly_salary = $employee['base_salary'];
            $daily_rate = $monthly_salary / 30; // Calculate daily rate
            
            // Calculate attendance-based salary
            $attendance_days = $this->get_attendance_days($employee_id, $pay_period_start, $pay_period_end);
            $gross_salary = $daily_rate * $attendance_days;
            
            $deductions = 0;

            // Check for active loan
            $active_loan = $loan_handler->get_active_loan_by_employee($employee_id);
            $loan_deduction = 0;
            if ($active_loan) {
                $loan_deduction = $active_loan['monthly_payment'];
                $deductions += $loan_deduction;
            }

            $net_salary = $gross_salary - $deductions;

            $payroll_data = [
                'employee_id' => $employee_id,
                'pay_period_start' => $pay_period_start,
                'pay_period_end' => $pay_period_end,
                'gross_salary' => $gross_salary,
                'deductions' => $deductions,
                'net_salary' => $net_salary,
                'attendance_days' => $attendance_days,
                'daily_rate' => $daily_rate
            ];

            $payroll_id = $this->run_simple_payroll($payroll_data);

            if ($payroll_id) {
                // If loan was deducted, record the installment
                if ($active_loan && $loan_deduction > 0) {
                    $installment_data = [
                        'loan_id' => $active_loan['id'],
                        'payroll_id' => $payroll_id,
                        'amount' => $loan_deduction
                    ];
                    $loan_handler->record_installment($installment_data);

                    // Check if loan is fully paid
                    $total_paid = $this->get_total_loan_paid($active_loan['id']);
                    if ($total_paid >= $active_loan['amount']) {
                        $loan_handler->update_loan_status($active_loan['id'], 'paid');
                    }
                }
                $results['success_count']++;
                $results['success_details'][] = "Successfully paid " . $employee['first_name'] . " " . $employee['last_name'] . " (Days: {$attendance_days}, Amount: $" . number_format($gross_salary, 2) . ")";
            } else {
                $results['fail_count']++;
                $results['fail_details'][] = "Failed to pay " . $employee['first_name'] . " " . $employee['last_name'];
            }
        }
        return $results;
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

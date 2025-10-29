<?php
class Report {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function get_employee_report() {
        $employee_handler = new Employee($this->pdo);
        return $employee_handler->get_all();
    }

    public function get_attendance_report($start_date, $end_date) {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, e.first_name, e.last_name FROM attendance a '
            . 'JOIN employees e ON a.employee_id = e.id '
            . 'WHERE DATE(a.clock_in) BETWEEN ? AND ? ORDER BY a.clock_in DESC'
        );
        $stmt->execute([$start_date, $end_date]);
        return $stmt->fetchAll();
    }

    public function get_payroll_report($start_date, $end_date) {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, e.first_name, e.last_name FROM payrolls p '
            . 'JOIN employees e ON p.employee_id = e.id '
            . 'WHERE p.pay_period_start >= ? AND p.pay_period_end <= ? ORDER BY p.pay_period_end DESC'
        );
        $stmt->execute([$start_date, $end_date]);
        return $stmt->fetchAll();
    }

    public function get_salary_certificate_data($employee_id) {
        $stmt = $this->pdo->prepare(
            'SELECT e.first_name, e.last_name, p.name as position_name, d.name as department_name, e.hire_date, e.base_salary '
            . 'FROM employees e '
            . 'LEFT JOIN positions p ON e.position_id = p.id '
            . 'LEFT JOIN departments d ON p.department_id = d.id '
            . 'WHERE e.id = ?'
        );
        $stmt->execute([$employee_id]);
        return $stmt->fetch();
    }
}
?>

<?php
class Loan {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function create_loan($data) {
        $stmt = $this->pdo->prepare(
            'INSERT INTO loans (employee_id, loan_date, amount, installments, monthly_payment, status) '
            . 'VALUES (:employee_id, CURDATE(), :amount, :installments, :monthly_payment, \'active\')'
        );
        return $stmt->execute($data);
    }

    public function get_all_loans() {
        $stmt = $this->pdo->query(
            'SELECT l.*, e.first_name, e.last_name FROM loans l '
            . 'JOIN employees e ON l.employee_id = e.id ORDER BY l.loan_date DESC'
        );
        return $stmt->fetchAll();
    }

    public function get_active_loan_by_employee($employee_id) {
        $stmt = $this->pdo->prepare('SELECT * FROM loans WHERE employee_id = ? AND status = \'active\'');
        $stmt->execute([$employee_id]);
        return $stmt->fetch();
    }

    public function record_installment($data) {
        $stmt = $this->pdo->prepare(
            'INSERT INTO loan_installments (loan_id, payroll_id, payment_date, amount) VALUES (:loan_id, :payroll_id, CURDATE(), :amount)'
        );
        return $stmt->execute($data);
    }

    public function update_loan_status($loan_id, $status) {
        $stmt = $this->pdo->prepare('UPDATE loans SET status = ? WHERE id = ?');
        return $stmt->execute([$status, $loan_id]);
    }
}
?>

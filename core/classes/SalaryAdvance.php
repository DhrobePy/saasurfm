<?php
class SalaryAdvance {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function request_advance($data) {
        $stmt = $this->pdo->prepare(
            'INSERT INTO salary_advances (employee_id, advance_date, amount) VALUES (:employee_id, CURDATE(), :amount)'
        );
        return $stmt->execute($data);
    }

    public function get_all_advances() {
        $stmt = $this->pdo->query(
            'SELECT sa.*, e.first_name, e.last_name FROM salary_advances sa '
            . 'JOIN employees e ON sa.employee_id = e.id ORDER BY sa.advance_date DESC'
        );
        return $stmt->fetchAll();
    }

    public function update_advance_status($id, $status) {
        $stmt = $this->pdo->prepare('UPDATE salary_advances SET status = ? WHERE id = ?');
        return $stmt->execute([$status, $id]);
    }
}
?>

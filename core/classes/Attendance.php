<?php
class Attendance {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function clock_in($employee_id) {
        $stmt = $this->pdo->prepare('INSERT INTO attendance (employee_id, clock_in, status) VALUES (?, NOW(), \'present\')');
        return $stmt->execute([$employee_id]);
    }

    public function clock_out($employee_id) {
        $stmt = $this->pdo->prepare('UPDATE attendance SET clock_out = NOW() WHERE employee_id = ? AND DATE(clock_in) = CURDATE() AND clock_out IS NULL');
        return $stmt->execute([$employee_id]);
    }

    public function get_today_attendance_by_employee($employee_id) {
        $stmt = $this->pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? AND DATE(clock_in) = CURDATE()');
        $stmt->execute([$employee_id]);
        return $stmt->fetch();
    }

    public function get_today_attendance() {
        $stmt = $this->pdo->query('SELECT a.*, e.first_name, e.last_name FROM attendance a JOIN employees e ON a.employee_id = e.id WHERE DATE(a.clock_in) = CURDATE()');
        return $stmt->fetchAll();
    }

    public function get_attendance_by_employee($employee_id) {
        $stmt = $this->pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? ORDER BY clock_in DESC');
        $stmt->execute([$employee_id]);
        return $stmt->fetchAll();
    }

    public function request_leave($data) {
        $stmt = $this->pdo->prepare('INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, reason) VALUES (:employee_id, :leave_type, :start_date, :end_date, :reason)');
        return $stmt->execute($data);
    }

    public function get_all_leave_requests() {
        $stmt = $this->pdo->query('SELECT lr.*, e.first_name, e.last_name FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id ORDER BY lr.start_date DESC');
        return $stmt->fetchAll();
    }

    public function update_leave_status($id, $status) {
        $stmt = $this->pdo->prepare('UPDATE leave_requests SET status = ? WHERE id = ?');
        return $stmt->execute([$status, $id]);
    }
}
?>

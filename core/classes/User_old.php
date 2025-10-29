<?php
class User {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function login($username, $password, $role_filter = null) {
        $stmt = $this->pdo->prepare('SELECT id, username, password, role, employee_id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Check role filter if specified
            if ($role_filter && $user['role'] !== $role_filter) {
                return false;
            }
            
            // Regenerate session ID to prevent session fixation
            session_regenerate_id();
            $_SESSION['loggedin'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['employee_id'] = $user['employee_id'];
            return true;
        }
        return false;
    }

    public function get_employee_id_from_session() {
        return $_SESSION['employee_id'] ?? null;
    }

    public function logout() {
        session_unset();
        session_destroy();
    }

    public function create($username, $password, $role = 'employee') {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->pdo->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)');
        return $stmt->execute([$username, $hashed_password, $role]);
    }
}
?>

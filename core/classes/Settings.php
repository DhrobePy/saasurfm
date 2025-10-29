<?php
class Settings {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function get_setting($name) {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE name = ?');
        $stmt->execute([$name]);
        $setting = $stmt->fetchColumn();
        return $setting !== false ? $setting : null;
    }

    public function get_all_settings() {
        $stmt = $this->pdo->query('SELECT name, value FROM settings');
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        return $settings;
    }

    public function update_setting($name, $value) {
        $stmt = $this->pdo->prepare('SELECT id FROM settings WHERE name = ?');
        $stmt->execute([$name]);
        $exists = $stmt->fetch();

        if ($exists) {
            $stmt = $this->pdo->prepare('UPDATE settings SET value = ? WHERE name = ?');
            return $stmt->execute([$value, $name]);
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?)');
            return $stmt->execute([$name, $value]);
        }
    }
}
?>

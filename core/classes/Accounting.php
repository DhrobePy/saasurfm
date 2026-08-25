<?php
class Accounting {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function create_journal_entry($account_id, $debit, $credit, $description, $payroll_id = null) {
        $stmt = $this->pdo->prepare(
            'INSERT INTO journal_entries (account_id, payroll_id, entry_date, debit, credit, description) '
            . 'VALUES (?, ?, CURDATE(), ?, ?, ?)'
        );
        return $stmt->execute([$account_id, $payroll_id, $debit, $credit, $description]);
    }

    public function get_chart_of_accounts() {
        $stmt = $this->pdo->query('SELECT * FROM chart_of_accounts ORDER BY account_name ASC');
        return $stmt->fetchAll();
    }

    public function get_journal_entries() {
        $stmt = $this->pdo->query(
            'SELECT j.*, c.account_name, c.account_type FROM journal_entries j '
            . 'JOIN chart_of_accounts c ON j.account_id = c.id ORDER BY j.entry_date DESC'
        );
        return $stmt->fetchAll();
    }
}
?>

<?php
require_once dirname(__DIR__) . '/core/init.php';
require_once dirname(__DIR__) . '/bank/BankManager.php';

// Force error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

$userId = 2; // Your user ID
$txId = 1;

echo "<h2>Testing direct approval</h2>";

try {
    $bankManager = new BankManager();
    
    // Get transaction first
    $tx = $bankManager->getTransactionById($txId);
    if (!$tx) {
        die("Transaction not found!");
    }
    
    echo "<p>Transaction: #{$tx->transaction_number} (Status: {$tx->status})</p>";
    
    // Try direct SQL update
    $db = Database::getInstance();
    $result = $db->query(
        "UPDATE bank_transactions SET status='approved', approved_at=NOW() WHERE id=?",
        [$txId]
    );
    
    $affected = $db->count();
    echo "<p>Direct SQL update affected: $affected rows</p>";
    
    // Check the result
    $updated = $db->query("SELECT * FROM bank_transactions WHERE id = ?", [$txId])->first();
    echo "<p>New status: " . $updated->status . "</p>";
    
} catch (Exception $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
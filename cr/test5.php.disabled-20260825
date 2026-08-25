<?php
require_once '../core/init.php';
global $db;

$payment_id = 5;

echo "<pre style='background:#000;color:#0f0;padding:20px;'>";
echo "=== PAYMENT #$payment_id DIAGNOSTIC ===\n\n";

// Check payment record
$payment = $db->query("SELECT * FROM customer_payments WHERE id = ?", [$payment_id])->first();
echo "1. Payment Record:\n";
if ($payment) {
    echo "   ✓ Payment exists\n";
    echo "   customer_id: {$payment->customer_id}\n";
    echo "   amount: {$payment->amount}\n";
    echo "   payment_date: {$payment->payment_date}\n";
    echo "   payment_method: {$payment->payment_method}\n\n";
} else {
    echo "   ✗ Payment NOT FOUND\n\n";
    exit;
}

// Check customer
$customer = $db->query("SELECT * FROM customers WHERE id = ?", [$payment->customer_id])->first();
echo "2. Customer Check (ID: {$payment->customer_id}):\n";
if ($customer) {
    echo "   ✓ Customer exists\n";
    echo "   name: {$customer->name}\n";
    echo "   phone: {$customer->phone_number}\n\n";
} else {
    echo "   ✗ Customer NOT FOUND in database\n";
    echo "   This is why receipt fails!\n\n";
    
    // Check all customers
    $all_customers = $db->query("SELECT id, name FROM customers ORDER BY id")->results();
    echo "   Available customers:\n";
    foreach ($all_customers as $c) {
        echo "   - ID {$c->id}: {$c->name}\n";
    }
    echo "\n";
}

// Try the full query
echo "3. Testing Full Receipt Query:\n";
$full = $db->query(
    "SELECT cp.*, 
            c.name as customer_name,
            c.phone_number as customer_phone
     FROM customer_payments cp
     LEFT JOIN customers c ON cp.customer_id = c.id
     WHERE cp.id = ?",
    [$payment_id]
)->first();

if ($full) {
    echo "   ✓ Query succeeded\n";
    echo "   customer_name: " . ($full->customer_name ?? 'NULL') . "\n";
    echo "   customer_phone: " . ($full->customer_phone ?? 'NULL') . "\n\n";
} else {
    echo "   ✗ Query returned nothing\n\n";
}

// Solution
if (!$customer) {
    echo "=== SOLUTION ===\n";
    echo "Customer ID {$payment->customer_id} doesn't exist.\n";
    echo "Options:\n";
    echo "1. Create customer ID {$payment->customer_id} in database\n";
    echo "2. Update payment customer_id to existing customer\n";
    echo "3. Delete this payment and recreate with valid customer\n";
}

echo "</pre>";
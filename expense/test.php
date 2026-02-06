<?php
/**
 * Expense Module Diagnostic Test
 * Upload to /expense/test_expense_setup.php
 * Visit: https://yoursite.com/expense/test_expense_setup.php
 */

require_once '../core/init.php';

$pdo = Database::getInstance()->getPdo();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Expense Module Diagnostic</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .test { background: white; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #ccc; }
        .success { border-left-color: #4CAF50; }
        .error { border-left-color: #f44336; }
        .warning { border-left-color: #ff9800; }
        h1 { color: #333; }
        h2 { color: #666; margin-top: 20px; }
        pre { background: #f0f0f0; padding: 10px; overflow-x: auto; }
        .status { font-weight: bold; }
        .ok { color: #4CAF50; }
        .fail { color: #f44336; }
    </style>
</head>
<body>

<h1>🔍 Expense Module Diagnostic Test</h1>

<?php

// Test 1: Check Tables
echo "<h2>1. Database Tables</h2>";

$tables = ['expense_categories', 'expense_subcategories', 'expense_vouchers'];
foreach ($tables as $table) {
    echo "<div class='test ";
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->fetch();
        if ($exists) {
            echo "success'><span class='status ok'>✅ PASS</span> Table <strong>$table</strong> exists</div>";
        } else {
            echo "error'><span class='status fail'>❌ FAIL</span> Table <strong>$table</strong> NOT FOUND</div>";
        }
    } catch (Exception $e) {
        echo "error'><span class='status fail'>❌ ERROR</span> " . $e->getMessage() . "</div>";
    }
}

// Test 2: Check expense_subcategories structure
echo "<h2>2. expense_subcategories Table Structure</h2>";
echo "<div class='test'>";
try {
    $stmt = $pdo->query("DESCRIBE expense_subcategories");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<span class='status ok'>✅ PASS</span> Table structure:<br>";
    echo "<pre>";
    foreach ($columns as $col) {
        echo sprintf("%-25s %-20s %s\n", 
            $col['Field'], 
            $col['Type'], 
            $col['Key'] ? "[{$col['Key']}]" : ""
        );
    }
    echo "</pre>";
    
    // Check for required columns
    $columnNames = array_column($columns, 'Field');
    $required = ['id', 'category_id'];
    $nameColumn = null;
    
    if (in_array('subcategory_name', $columnNames)) {
        $nameColumn = 'subcategory_name';
    } elseif (in_array('name', $columnNames)) {
        $nameColumn = 'name';
    }
    
    echo "<strong>Name Column:</strong> " . ($nameColumn ?: '<span class="fail">NOT FOUND</span>') . "<br>";
    
} catch (Exception $e) {
    echo "<span class='status fail'>❌ ERROR</span> " . $e->getMessage();
}
echo "</div>";

// Test 3: Check categories
echo "<h2>3. Expense Categories</h2>";
echo "<div class='test ";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM expense_categories");
    $result = $stmt->fetch(PDO::FETCH_OBJ);
    
    if ($result->total > 0) {
        echo "success'><span class='status ok'>✅ PASS</span> Found <strong>{$result->total}</strong> categories<br><br>";
        
        // Show categories
        $stmt = $pdo->query("SELECT id, category_name FROM expense_categories LIMIT 5");
        $categories = $stmt->fetchAll(PDO::FETCH_OBJ);
        
        echo "<strong>Sample Categories:</strong><br>";
        foreach ($categories as $cat) {
            echo "• ID: {$cat->id} - {$cat->category_name}<br>";
        }
    } else {
        echo "warning'><span class='status'>⚠️ WARNING</span> No categories found. Please add some categories first.";
    }
} catch (Exception $e) {
    echo "error'><span class='status fail'>❌ ERROR</span> " . $e->getMessage();
}
echo "</div>";

// Test 4: Check subcategories
echo "<h2>4. Expense Subcategories</h2>";
echo "<div class='test ";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM expense_subcategories");
    $result = $stmt->fetch(PDO::FETCH_OBJ);
    
    if ($result->total > 0) {
        echo "success'><span class='status ok'>✅ PASS</span> Found <strong>{$result->total}</strong> subcategories<br><br>";
        
        // Determine column name
        $stmt = $pdo->query("DESCRIBE expense_subcategories");
        $columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $nameCol = in_array('subcategory_name', $columns) ? 'subcategory_name' : 'name';
        
        // Show subcategories
        $stmt = $pdo->query("SELECT id, category_id, $nameCol as name FROM expense_subcategories LIMIT 5");
        $subcategories = $stmt->fetchAll(PDO::FETCH_OBJ);
        
        echo "<strong>Sample Subcategories:</strong><br>";
        foreach ($subcategories as $sub) {
            echo "• ID: {$sub->id} - Category: {$sub->category_id} - {$sub->name}<br>";
        }
    } else {
        echo "warning'><span class='status'>⚠️ WARNING</span> No subcategories found. Please add some subcategories first.";
    }
} catch (Exception $e) {
    echo "error'><span class='status fail'>❌ ERROR</span> " . $e->getMessage();
}
echo "</div>";

// Test 5: Check permission function
echo "<h2>5. Permission Functions</h2>";
echo "<div class='test ";
if (function_exists('canAccessExpense')) {
    echo "success'><span class='status ok'>✅ PASS</span> Function <strong>canAccessExpense()</strong> exists";
} else {
    echo "error'><span class='status fail'>❌ FAIL</span> Function <strong>canAccessExpense()</strong> NOT FOUND<br>";
    echo "<strong>Solution:</strong> Add permission functions from helpers_ADDITIONS.txt to core/functions/helpers.php";
}
echo "</div>";

// Test 6: Test AJAX endpoint
echo "<h2>6. AJAX Endpoint Test</h2>";
echo "<div class='test'>";
echo "<strong>Test the AJAX endpoint:</strong><br>";
$testUrl = url('expense/ajax_get_subcategories.php?category_id=1');
echo "Visit: <a href='$testUrl' target='_blank'>$testUrl</a><br>";
echo "<small>Should return JSON with subcategories or error details</small>";
echo "</div>";

// Test 7: Recommended fixes
echo "<h2>7. Recommended Action</h2>";
echo "<div class='test'>";

$issues = [];
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM expense_categories");
    if ($stmt->fetch(PDO::FETCH_OBJ)->total == 0) {
        $issues[] = "Add expense categories first";
    }
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM expense_subcategories");
    if ($stmt->fetch(PDO::FETCH_OBJ)->total == 0) {
        $issues[] = "Add expense subcategories";
    }
} catch (Exception $e) {}

if (!function_exists('canAccessExpense')) {
    $issues[] = "Add permission functions (cat helpers_ADDITIONS.txt >> core/functions/helpers.php)";
}

if (empty($issues)) {
    echo "<span class='status ok'>✅ ALL GOOD!</span> System appears to be configured correctly.";
} else {
    echo "<span class='status'>⚠️ Action Required:</span><br><ul>";
    foreach ($issues as $issue) {
        echo "<li>$issue</li>";
    }
    echo "</ul>";
}
echo "</div>";

?>

<h2>✅ Next Steps</h2>
<div class='test'>
    <ol>
        <li>Fix any errors shown above</li>
        <li>Use <strong>ajax_get_subcategories_SAFE.php</strong> (auto-detects column names)</li>
        <li>Or use <strong>ajax_get_subcategories_DEBUG.php</strong> to see exact errors</li>
        <li>Check browser console (F12) when selecting a category</li>
    </ol>
</div>

</body>
</html>
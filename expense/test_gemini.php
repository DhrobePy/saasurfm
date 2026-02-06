<?php
/**
 * AJAX Debugger for Expense Subcategories
 * Use this file to identify why subcategories are failing to load.
 */

// 1. Try to include the initialization file
echo "<h3>1. Environment Check</h3>";
if (file_exists('../core/init.php')) {
    require_once '../core/init.php';
    echo "<p style='color:green;'>[SUCCESS] core/init.php found and included.</p>";
} else {
    echo "<p style='color:red;'>[CRITICAL] core/init.php NOT FOUND at ../core/init.php. Check your file paths.</p>";
    exit;
}

// 2. Check Database Connection
echo "<h3>2. Database Check</h3>";
global $db;
if (isset($db)) {
    echo "<p style='color:green;'>[SUCCESS] Global \$db variable is active.</p>";
} else {
    echo "<p style='color:orange;'>[WARNING] Global \$db not found. Checking Database::getInstance()...</p>";
    try {
        $db = Database::getInstance();
        echo "<p style='color:green;'>[SUCCESS] Database::getInstance() successful.</p>";
    } catch (Exception $e) {
        echo "<p style='color:red;'>[CRITICAL] Database connection failed: " . $e->getMessage() . "</p>";
    }
}

// 3. Check AJAX file existence
echo "<h3>3. File System Check</h3>";
$ajax_path = 'expense/ajax_get_subcategories.php';
if (file_exists($ajax_path)) {
    echo "<p style='color:green;'>[SUCCESS] AJAX file found at: $ajax_path</p>";
} else {
    echo "<p style='color:red;'>[CRITICAL] AJAX file MISSING at: $ajax_path. This is why the error is happening.</p>";
}

// 4. Test Query Logic
echo "<h3>4. SQL Query Test</h3>";
try {
    // We try to find the first category to test against
    $test_cat = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Expense' LIMIT 1")->first();
    
    if ($test_cat) {
        $cid = $test_cat->id;
        echo "<p>Testing with Category ID: $cid</p>";
        
        // Testing your table name. Adjust 'expense_subcategories' if you suspect it's different
        $table_to_test = 'expense_subcategories';
        $results = $db->query("SELECT * FROM $table_to_test WHERE category_id = ? LIMIT 5", [$cid])->results();
        
        echo "<p style='color:green;'>[SUCCESS] Query to '$table_to_test' executed without crashing.</p>";
        echo "<p>Found " . count($results) . " subcategories.</p>";
    } else {
        echo "<p style='color:orange;'>[INFO] No categories found in chart_of_accounts to test with.</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>[ERROR] SQL Query failed: " . $e->getMessage() . "</p>";
    echo "<p><i>Check if the table name 'expense_subcategories' matches your database (it might be 'expense_sub_categories' or similar).</i></p>";
}

echo "<h3>5. Server Information</h3>";
echo "<ul>";
echo "<li>PHP Version: " . phpversion() . "</li>";
echo "<li>Current Script: " . $_SERVER['PHP_SELF'] . "</li>";
echo "<li>Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "</li>";
echo "</ul>";

echo "<hr><p><b>Next Step:</b> If everything above is green but the form still fails, check the <b>Browser Console (F12) -> Network tab</b> while changing the category. Look at the red request for 'get_expense_subcategories.php' and click 'Response' to see the exact PHP error output.</p>";
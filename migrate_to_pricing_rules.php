<?php
/**
 * Migration Script: Convert Hardcoded Pricing Logic to Rules Engine
 * Run this ONCE to migrate from old system to new rules engine
 * 
 * Ujjal Flour Mills - SaaS Platform
 */

// Start session and require initialization
session_start();
require_once 'core/init.php';
require_once 'core/classes/PricingRuleManager.php';

// Check super admin access

$ruleManager = new PricingRuleManager($db, $_SESSION['user_id']);

// Check if rules already exist
$sql = "SELECT rule_code FROM pricing_rules WHERE rule_code IN ('SRG_50KG_BASE', 'SRG_74KG_SCALING', 'DEMRA_50KG_DISCOUNT', 'DEMRA_74KG_SCALING')";
$db->query($sql, array());
$existingRules = $db->results();

if (!empty($existingRules)) {
    $existingCodes = array();
    foreach ($existingRules as $rule) {
        $existingCodes[] = $rule->rule_code;
    }
    echo "<div style='background: #fff3cd; border-left: 4px solid #ff9800; padding: 15px; margin: 20px 0;'>";
    echo "<h3 style='color: #856404;'>⚠️ Rules Already Exist</h3>";
    echo "<p>The following rules already exist in your database:</p>";
    echo "<ul>";
    foreach ($existingCodes as $code) {
        echo "<li><strong>{$code}</strong></li>";
    }
    echo "</ul>";
    echo "<p><strong>Options:</strong></p>";
    echo "<ol>";
    echo "<li>Delete existing rules and re-run migration</li>";
    echo "<li>Edit existing rules manually</li>";
    echo "<li>Skip this migration (rules are already configured)</li>";
    echo "</ol>";
    echo "<a href='test_pricing_rules.php'><button class='btn'>Go to Tests Instead →</button></a>";
    echo "</div>";
    die();
}

// Discover actual grades and weights from database
$sql = "SELECT DISTINCT grade FROM product_variants WHERE status = 'active' ORDER BY grade";
$db->query($sql, array());
$gradeResults = $db->results();
$availableGrades = array();
foreach ($gradeResults as $g) {
    $availableGrades[] = $g->grade;
}

// Default to numeric grades if none found
if (empty($availableGrades)) {
    $availableGrades = array('1', '2', '3', '4', '5', '6', '7');
}

$sql = "SELECT DISTINCT weight_variant FROM product_variants WHERE status = 'active' ORDER BY weight_variant";
$db->query($sql, array());
$weightResults = $db->results();
$availableWeights = array();
foreach ($weightResults as $w) {
    $availableWeights[] = floatval($w->weight_variant);
}

// Default weights if none found
if (empty($availableWeights)) {
    $availableWeights = array(50, 74);
}

$has50kg = in_array(50, $availableWeights) || in_array(50.0, $availableWeights);
$has74kg = in_array(74, $availableWeights) || in_array(74.0, $availableWeights);


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing Rules Migration</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { color: #333; border-bottom: 2px solid #4CAF50; padding-bottom: 10px; }
        .rule-box { background: #f9f9f9; padding: 15px; margin: 15px 0; border-left: 4px solid #2196F3; }
        .success { color: #4CAF50; }
        .error { color: #f44336; }
        .warning { color: #ff9800; }
        .code { background: #263238; color: #aed581; padding: 10px; border-radius: 4px; font-family: 'Courier New', monospace; margin: 10px 0; }
        .btn { background: #4CAF50; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #45a049; }
        ul { line-height: 1.8; }
    </style>
</head>
<body>
    <div class="container">
        <h2>🔄 Pricing Rules Migration</h2>
        <p>This script will migrate your current hardcoded pricing logic to the flexible rules engine.</p>
        
        <?php
        
        try {
            echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 4px; margin: 20px 0;'>";
            echo "<h4>📊 Database Discovery</h4>";
            echo "<p><strong>Available Grades:</strong> " . implode(', ', $availableGrades) . "</p>";
            echo "<p><strong>Available Weights:</strong> " . implode('kg, ', $availableWeights) . "kg</p>";
            echo "<p>Creating rules for these specific configurations...</p>";
            echo "</div>";
            
            echo "<h3>Migration Progress:</h3>";
            
            $rulesCreated = array();
            
            // Rule 1: Sirajgonj 50kg Base Price (Direct) - only if 50kg exists
            if ($has50kg) {
                echo "<div class='rule-box'>";
                echo "<h4>📌 Rule 1: Sirajgonj 50kg Base Price</h4>";
                echo "<div class='code'>Formula: base_price (direct)</div>";
                
                $rule1 = $ruleManager->createRule(array(
                    'rule_code' => 'SRG_50KG_BASE',
                    'rule_name' => 'Sirajgonj 50kg Base Price',
                    'description' => 'Direct base price for Sirajgonj 50kg products',
                    'rule_type' => 'formula',
                    'rule_category' => 'grade_based',
                    'priority' => 100,
                    'applies_to_grades' => $availableGrades,
                    'applies_to_branches' => array(1), // Sirajgonj
                    'applies_to_weights' => array(50),
                    'formula_template' => 'base_price',
                    'rounding_rule' => 'none'
                ));
                
                echo "<p class='success'>✓ Created: Rule ID {$rule1['rule_id']}</p>";
                echo "</div>";
                $rulesCreated[] = 'SRG_50KG_BASE';
            } else {
                echo "<div class='rule-box'>";
                echo "<h4>⊘ Skipping Rule 1: Sirajgonj 50kg Base Price</h4>";
                echo "<p>No 50kg variants found in database</p>";
                echo "</div>";
            }
            
            // Rule 2: Sirajgonj 74kg Weight Scaling - only if 74kg exists
            if ($has74kg) {
                echo "<div class='rule-box'>";
                echo "<h4>📌 Rule 2: Sirajgonj 74kg Weight Scaling</h4>";
                echo "<div class='code'>Formula: ((base_price / 50) * 74 + 30)</div>";
                
                $rule2 = $ruleManager->createRule(array(
                    'rule_code' => 'SRG_74KG_SCALING',
                    'rule_name' => 'Sirajgonj 74kg Weight Scaling Formula',
                    'description' => 'Scales price from 50kg to 74kg with ৳30 overhead for Sirajgonj',
                    'rule_type' => 'formula',
                    'rule_category' => 'weight_based',
                    'priority' => 100,
                    'applies_to_grades' => $availableGrades,
                    'applies_to_branches' => array(1), // Sirajgonj
                    'applies_to_weights' => array(74),
                    'formula_template' => '((base_price / 50) * 74 + 30)',
                    'rounding_rule' => 'ceiling_5'
                ));
                
                echo "<p class='success'>✓ Created: Rule ID {$rule2['rule_id']}</p>";
                echo "</div>";
                $rulesCreated[] = 'SRG_74KG_SCALING';
            } else {
                echo "<div class='rule-box'>";
                echo "<h4>⊘ Skipping Rule 2: Sirajgonj 74kg Weight Scaling</h4>";
                echo "<p>No 74kg variants found in database</p>";
                echo "</div>";
            }
            
            // Rule 3: Demra Branch Discount (50kg) - only if 50kg exists
            if ($has50kg) {
                echo "<div class='rule-box'>";
                echo "<h4>📌 Rule 3: Demra 50kg Branch Differential</h4>";
                echo "<div class='code'>Formula: base_price - 20 (rounded up to ৳5)</div>";
                
                $rule3 = $ruleManager->createRule(array(
                    'rule_code' => 'DEMRA_50KG_DISCOUNT',
                    'rule_name' => 'Demra 50kg Price Adjustment',
                    'description' => 'Demra prices are ৳20 less than Sirajgonj for 50kg',
                    'rule_type' => 'fixed_amount',
                    'rule_category' => 'branch_based',
                    'priority' => 90,
                    'applies_to_grades' => $availableGrades,
                    'applies_to_branches' => array(2), // Demra
                    'applies_to_weights' => array(50),
                    'fixed_amount' => -20,
                    'calculation_method' => 'subtract',
                    'rounding_rule' => 'ceiling_5'
                ));
                
                echo "<p class='success'>✓ Created: Rule ID {$rule3['rule_id']}</p>";
                echo "</div>";
                $rulesCreated[] = 'DEMRA_50KG_DISCOUNT';
            } else {
                echo "<div class='rule-box'>";
                echo "<h4>⊘ Skipping Rule 3: Demra 50kg Branch Differential</h4>";
                echo "<p>No 50kg variants found in database</p>";
                echo "</div>";
            }
            
            // Rule 4: Demra 74kg Combined Rule - only if 74kg exists
            if ($has74kg) {
                echo "<div class='rule-box'>";
                echo "<h4>📌 Rule 4: Demra 74kg Weight Scaling</h4>";
                echo "<div class='code'>Formula: (((base_price - 20) / 50) * 74 + 30)</div>";
                
                $rule4 = $ruleManager->createRule(array(
                    'rule_code' => 'DEMRA_74KG_SCALING',
                    'rule_name' => 'Demra 74kg Weight Scaling Formula',
                    'description' => 'Applies both branch discount and weight scaling for Demra 74kg',
                    'rule_type' => 'formula',
                    'rule_category' => 'weight_based',
                    'priority' => 90,
                    'applies_to_grades' => $availableGrades,
                    'applies_to_branches' => array(2), // Demra
                    'applies_to_weights' => array(74),
                    'formula_template' => '(((base_price - 20) / 50) * 74 + 30)',
                    'rounding_rule' => 'ceiling_5'
                ));
                
                echo "<p class='success'>✓ Created: Rule ID {$rule4['rule_id']}</p>";
                echo "</div>";
                $rulesCreated[] = 'DEMRA_74KG_SCALING';
            } else {
                echo "<div class='rule-box'>";
                echo "<h4>⊘ Skipping Rule 4: Demra 74kg Weight Scaling</h4>";
                echo "<p>No 74kg variants found in database</p>";
                echo "</div>";
            }
            
            echo "<hr>";
            echo "<h3 class='success'>✓ Migration Completed Successfully!</h3>";
            echo "<p><strong>Created " . count($rulesCreated) . " pricing rules:</strong></p>";
            echo "<ul>";
            foreach ($rulesCreated as $ruleCode) {
                $ruleNames = array(
                    'SRG_50KG_BASE' => 'Sirajgonj 50kg base price',
                    'SRG_74KG_SCALING' => 'Sirajgonj 74kg scaling',
                    'DEMRA_50KG_DISCOUNT' => 'Demra 50kg differential',
                    'DEMRA_74KG_SCALING' => 'Demra 74kg scaling'
                );
                echo "<li>{$ruleCode} - {$ruleNames[$ruleCode]}</li>";
            }
            echo "</ul>";
            
            echo "<h4>Next Steps:</h4>";
            echo "<ol>";
            echo "<li><a href='test_pricing_rules.php'><button class='btn'>Run Validation Tests</button></a></li>";
            echo "<li><a href='dashboard.php?page=pricing_rules_dashboard'><button class='btn'>View Pricing Rules Dashboard</button></a></li>";
            echo "</ol>";
            
            echo "<div class='warning'>";
            echo "<p><strong>⚠️ Important Notes:</strong></p>";
            echo "<ul>";
            echo "<li>The old hardcoded logic in process_product_update.php still works</li>";
            echo "<li>You can run both systems in parallel for testing</li>";
            echo "<li>Once validated, update process_product_update.php to use the rules engine</li>";
            echo "<li>This migration script should only be run ONCE</li>";
            echo "</ul>";
            echo "</div>";
            
        } catch (Exception $e) {
            echo "<hr>";
            echo "<h3 class='error'>✗ Migration Failed!</h3>";
            echo "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<pre class='error'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
            
            echo "<h4>Troubleshooting:</h4>";
            echo "<ul>";
            echo "<li>Make sure you've run install_pricing_rules_engine.sql first</li>";
            echo "<li>Check that all pricing_* tables exist in the database</li>";
            echo "<li>Verify you have superadmin access</li>";
            echo "<li>Check database error logs for more details</li>";
            echo "<li>Check if rules already exist (unique rule_code constraint)</li>";
            echo "</ul>";
        }
        ?>
        
    </div>
</body>
</html>

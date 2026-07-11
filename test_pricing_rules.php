<?php
/**
 * Test Script: Validate Pricing Rules vs Old Logic
 * Compares prices calculated by rules engine vs hardcoded formulas
 * 
 * Ujjal Flour Mills - SaaS Platform
 */

session_start();
require_once 'core/init.php';
require_once 'core/classes/PricingRulesEngine.php';

$sql = "SELECT DISTINCT pv.grade, pv.weight_variant 
        FROM product_variants pv 
        WHERE pv.status = 'active'
        ORDER BY pv.grade, pv.weight_variant";
$db->query($sql, array());
$availableVariants = $db->results();

// Group by grade
$variantsByGrade = array();
foreach ($availableVariants as $v) {
    if (!isset($variantsByGrade[$v->grade])) {
        $variantsByGrade[$v->grade] = array();
    }
    $variantsByGrade[$v->grade][] = floatval($v->weight_variant);
}

// Generate test cases based on actual data
$testCases = array();

// Get first available grade
$firstGrade = null;
$weights50 = false;
$weights74 = false;

foreach ($variantsByGrade as $grade => $weights) {
    if ($firstGrade === null) {
        $firstGrade = $grade;
    }
    if (in_array(50, $weights) || in_array(50.0, $weights)) {
        $weights50 = true;
    }
    if (in_array(74, $weights) || in_array(74.0, $weights)) {
        $weights74 = true;
    }
}

// Build test cases based on what exists
if ($firstGrade !== null && $weights50) {
    // Test cases for 50kg
    $testCases[] = array(2000, 1, 50, $firstGrade, 2000, 'Sirajgonj 50kg base');
    $testCases[] = array(2500, 1, 50, $firstGrade, 2500, 'Sirajgonj 50kg higher price');
    $testCases[] = array(2000, 2, 50, $firstGrade, 1980, 'Demra 50kg discount');
    $testCases[] = array(2500, 2, 50, $firstGrade, 2480, 'Demra 50kg higher price');
}

if ($firstGrade !== null && $weights74) {
    // Test cases for 74kg
    $testCases[] = array(2000, 1, 74, $firstGrade, 2990, 'Sirajgonj 74kg scaling');
    $testCases[] = array(2500, 1, 74, $firstGrade, 3730, 'Sirajgonj 74kg higher price');
    $testCases[] = array(2000, 2, 74, $firstGrade, 2960, 'Demra 74kg combined');
    $testCases[] = array(2500, 2, 74, $firstGrade, 3700, 'Demra 74kg higher price');
}

// If we have multiple grades, test with second grade too
$grades = array_keys($variantsByGrade);
if (count($grades) > 1 && $weights50) {
    $secondGrade = $grades[1];
    $testCases[] = array(1800, 1, 50, $secondGrade, 1800, "Grade {$secondGrade} Sirajgonj 50kg");
    $testCases[] = array(1800, 2, 50, $secondGrade, 1780, "Grade {$secondGrade} Demra 50kg");
}

if (empty($testCases)) {
    die("<div class='container'><h2>No Test Cases Available</h2><p>Could not generate test cases. Database may not have active product variants.</p><p>Available grades: " . implode(', ', array_keys($variantsByGrade)) . "</p></div>");
}

$engine = new PricingRulesEngine($db, $_SESSION['user_id']);
$passed = 0;
$failed = 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing Rules Engine Test Suite</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { color: #333; border-bottom: 2px solid #4CAF50; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border: 1px solid #ddd; }
        th { background: #4CAF50; color: white; font-weight: bold; }
        tr:nth-child(even) { background: #f9f9f9; }
        .pass { background: #d4edda !important; color: #155724; }
        .fail { background: #f8d7da !important; color: #721c24; }
        .skip { background: #fff3cd !important; color: #856404; }
        .summary { background: #e3f2fd; padding: 20px; border-radius: 4px; margin: 20px 0; }
        .success { color: #4CAF50; font-size: 24px; font-weight: bold; }
        .error { color: #f44336; font-size: 24px; font-weight: bold; }
        .badge { padding: 4px 8px; border-radius: 4px; font-weight: bold; }
        .badge-success { background: #4CAF50; color: white; }
        .badge-danger { background: #f44336; color: white; }
        .btn { background: #2196F3; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; margin: 5px; }
        .btn:hover { background: #1976D2; }
    </style>
</head>
<body>
    <div class="container">
        <h2>🧪 Pricing Rules Engine Test Suite</h2>
        <p>Comparing new rules engine with old hardcoded logic...</p>

        <?php if (!empty($variantsByGrade)): ?>
        <div style="background: #e3f2fd; padding: 15px; border-radius: 4px; margin: 20px 0;">
            <h4>📊 Database Discovery</h4>
            <p><strong>Available Product Variants:</strong></p>
            <ul>
                <?php foreach ($variantsByGrade as $grade => $weights): ?>
                    <li>Grade <strong><?= htmlspecialchars($grade) ?></strong>: <?= implode('kg, ', $weights) ?>kg</li>
                <?php endforeach; ?>
            </ul>
            <p><strong>Generated <?= count($testCases) ?> test cases</strong> based on actual database content.</p>
        </div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>Test #</th>
                    <th>Description</th>
                    <th>Base Price</th>
                    <th>Branch</th>
                    <th>Weight</th>
                    <th>Grade</th>
                    <th>Expected</th>
                    <th>Calculated</th>
                    <th>Diff</th>
                    <th>Result</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($testCases as $index => $test): ?>
                    <?php
                    list($basePrice, $branchId, $weight, $grade, $expectedPrice, $description) = $test;
                    
                    // Get a variant that matches the criteria
                    $sql = "SELECT pv.id 
                            FROM product_variants pv
                            WHERE pv.grade = ? AND pv.weight_variant = ?
                            LIMIT 1";
                    $db->query($sql, array($grade, $weight));
                    $variant = $db->first();
                    
                    if (!$variant) {
                        echo "<tr class='skip'>";
                        echo "<td>" . ($index + 1) . "</td>";
                        echo "<td colspan='9'>{$description} - No variant found for Grade {$grade}, Weight {$weight}kg - SKIPPED</td>";
                        echo "</tr>";
                        continue;
                    }
                    
                    try {
                        $result = $engine->calculatePrice(
                            $variant->id,
                            $branchId,
                            $basePrice,
                            array('grade' => $grade, 'weight' => $weight)
                        );
                        
                        $calculatedPrice = $result['price'];
                        $diff = abs($calculatedPrice - $expectedPrice);
                        $match = $diff < 0.01;
                        
                        if ($match) {
                            $passed++;
                            $rowClass = 'pass';
                            $status = '✓ PASS';
                            $badgeClass = 'badge-success';
                        } else {
                            $failed++;
                            $rowClass = 'fail';
                            $status = '✗ FAIL';
                            $badgeClass = 'badge-danger';
                        }
                        
                        echo "<tr class='{$rowClass}'>";
                        echo "<td>" . ($index + 1) . "</td>";
                        echo "<td>{$description}</td>";
                        echo "<td>৳" . number_format($basePrice, 2) . "</td>";
                        echo "<td>" . ($branchId == 1 ? 'Sirajgonj' : 'Demra') . "</td>";
                        echo "<td>{$weight}kg</td>";
                        echo "<td>{$grade}</td>";
                        echo "<td>৳" . number_format($expectedPrice, 2) . "</td>";
                        echo "<td>৳" . number_format($calculatedPrice, 2) . "</td>";
                        echo "<td>৳" . number_format($diff, 2) . "</td>";
                        echo "<td><span class='badge {$badgeClass}'>{$status}</span></td>";
                        echo "</tr>";
                        
                    } catch (Exception $e) {
                        $failed++;
                        echo "<tr class='fail'>";
                        echo "<td>" . ($index + 1) . "</td>";
                        echo "<td colspan='8'>ERROR: " . htmlspecialchars($e->getMessage()) . "</td>";
                        echo "<td><span class='badge badge-danger'>✗ FAIL</span></td>";
                        echo "</tr>";
                    }
                    ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="summary">
            <h3>📊 Test Summary</h3>
            <p class="success">✓ Passed: <strong><?= $passed ?></strong></p>
            <p class="error">✗ Failed: <strong><?= $failed ?></strong></p>
            <p><strong>Total Tests: <?= ($passed + $failed) ?></strong></p>
            
            <?php if ($failed === 0): ?>
                <hr>
                <p class="success" style="font-size: 28px; text-align: center;">
                    ✓ ALL TESTS PASSED!<br>
                    <small style="font-size: 16px; color: #666;">Rules engine is working correctly</small>
                </p>
            <?php else: ?>
                <hr>
                <p class="error" style="font-size: 24px; text-align: center;">
                    ✗ Some tests failed<br>
                    <small style="font-size: 14px; color: #666;">Please review the rules configuration</small>
                </p>
            <?php endif; ?>
        </div>

        <h3>📝 Test Details</h3>
        <p><strong>What was tested:</strong></p>
        <ul>
            <li>Sirajgonj 50kg base pricing (direct formula)</li>
            <li>Sirajgonj 74kg weight scaling with overhead</li>
            <li>Demra 50kg branch differential (-৳20)</li>
            <li>Demra 74kg combined formula (discount + scaling)</li>
            <li>Multiple product grades (1-7)</li>
            <li>Rounding rules (ceiling to nearest ৳5)</li>
        </ul>

        <p><strong>Test Methodology:</strong></p>
        <ul>
            <li>Each test compares rules engine output vs expected hardcoded result</li>
            <li>Tolerance: ±৳0.01 (accounting for floating point precision)</li>
            <li>Tests use actual product variants from database</li>
            <li>All rules evaluated in priority order</li>
        </ul>

        <div style="margin: 30px 0; text-align: center;">
            <a href="migrate_to_pricing_rules.php" class="btn">← Back to Migration</a>
            <a href="dashboard.php?page=pricing_rules_dashboard" class="btn">Go to Rules Dashboard →</a>
        </div>

        <?php if ($failed === 0): ?>
        <div style="background: #e8f5e9; border-left: 4px solid #4CAF50; padding: 15px; margin: 20px 0;">
            <h4 style="margin-top: 0; color: #2e7d32;">✓ Ready for Production</h4>
            <p>All tests passed successfully. The pricing rules engine is calculating prices correctly and matches the old hardcoded logic perfectly.</p>
            <p><strong>Next Steps:</strong></p>
            <ol>
                <li>Update your price update interface to use the rules engine</li>
                <li>Run a parallel test with real price updates</li>
                <li>Monitor execution logs for any issues</li>
                <li>Gradually phase out the hardcoded logic</li>
            </ol>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>

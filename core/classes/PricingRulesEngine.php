<?php
/**
 * Pricing Rules Engine
 * Evaluates and executes pricing rules dynamically
 * 
 * Ujjal Flour Mills - SaaS Platform
 */

class PricingRulesEngine {
    private $db;
    private $user_id;
    private $debugMode = false;
    private $executionTrace = array();
    
    public function __construct($db, $user_id = null) {
        $this->db = $db;
        $this->user_id = $user_id ?? $_SESSION['user_id'] ?? null;
    }
    
    /**
     * Calculate price based on rules
     * 
     * @param int $variantId Product variant ID
     * @param int $branchId Branch ID
     * @param float $basePrice Base price input
     * @param array $context Additional context (grade, weight, quantity, etc.)
     * @return array Result with calculated price and applied rules
     */
    public function calculatePrice($variantId, $branchId, $basePrice, $context = array()) {
        $batchId = $this->generateBatchId();
        $this->executionTrace = array();
        
        try {
            // Get applicable rules
            $rules = $this->getApplicableRules($variantId, $branchId, $context);
            
            if (empty($rules)) {
                throw new Exception("No applicable pricing rules found");
            }
            
            // Sort by priority (higher first)
            usort($rules, function($a, $b) {
                return $b->priority - $a->priority;
            });
            
            $currentPrice = $basePrice;
            $rulesApplied = array();
            
            // Execute rules in priority order
            foreach ($rules as $rule) {
                $this->trace("Evaluating rule: {$rule->rule_name} (Priority: {$rule->priority})");
                
                // Check conditions
                if (!$this->evaluateConditions($rule->id, $context)) {
                    $this->trace("Rule conditions not met, skipping");
                    continue;
                }
                
                // Execute rule
                $result = $this->executeRule($rule, $currentPrice, $variantId, $branchId, $context);
                
                if ($result['success']) {
                    $currentPrice = $result['price'];
                    $rulesApplied[] = array(
                        'rule_id' => $rule->id,
                        'rule_name' => $rule->rule_name,
                        'input_price' => $result['input_price'],
                        'output_price' => $result['price'],
                        'change' => $result['price'] - $result['input_price']
                    );
                    
                    $this->trace("Rule applied: " . $rule->rule_name . 
                                " | Input: ৳{$result['input_price']} | Output: ৳{$result['price']}");
                }
            }
            
            // Apply final rounding if needed
            $finalPrice = $this->applyRounding($currentPrice, 'ceiling_5');
            
            // Log execution
            $this->logExecution(array(
                'batch_id' => $batchId,
                'variant_id' => $variantId,
                'branch_id' => $branchId,
                'base_price' => $basePrice,
                'calculated_price' => $currentPrice,
                'final_price' => $finalPrice,
                'rules_applied' => $rulesApplied,
                'status' => 'success'
            ));
            
            return array(
                'success' => true,
                'price' => $finalPrice,
                'rules_applied' => $rulesApplied,
                'batch_id' => $batchId,
                'trace' => $this->executionTrace
            );
            
        } catch (Exception $e) {
            $this->logExecution(array(
                'batch_id' => $batchId,
                'variant_id' => $variantId,
                'branch_id' => $branchId,
                'base_price' => $basePrice,
                'status' => 'failed',
                'error' => $e->getMessage()
            ));
            
            throw $e;
        }
    }
    
    /**
     * Get applicable rules for variant and branch
     */
    private function getApplicableRules($variantId, $branchId, $context) {
        // Get variant details
        $variant = $this->getVariantDetails($variantId);
        
        $sql = "
            SELECT pr.*
            FROM pricing_rules pr
            WHERE pr.is_active = 1
              AND (pr.valid_from IS NULL OR pr.valid_from <= CURDATE())
              AND (pr.valid_to IS NULL OR pr.valid_to >= CURDATE())
              AND (pr.applies_to_grades IS NULL OR JSON_CONTAINS(pr.applies_to_grades, ?))
              AND (pr.applies_to_branches IS NULL OR JSON_CONTAINS(pr.applies_to_branches, ?))
              AND (pr.applies_to_weights IS NULL OR JSON_CONTAINS(pr.applies_to_weights, ?))
            ORDER BY pr.priority DESC
        ";
        
        // Prepare JSON-encoded parameters
        $gradeJson = json_encode($variant->grade);
        $branchJson = json_encode($branchId);
        $weightJson = json_encode(floatval($variant->weight_variant));
        
        $this->db->query($sql, array(
            $gradeJson,
            $branchJson,
            $weightJson
        ));
        
        return $this->db->results();
    }
    
    /**
     * Execute a single rule
     */
    private function executeRule($rule, $inputPrice, $variantId, $branchId, $context) {
        $this->trace("Executing rule type: {$rule->rule_type}");
        
        $outputPrice = $inputPrice;
        
        switch ($rule->rule_type) {
            case 'formula':
                $outputPrice = $this->executeFormulaRule($rule, $inputPrice, $variantId, $branchId, $context);
                break;
                
            case 'percentage':
                $outputPrice = $this->executePercentageRule($rule, $inputPrice);
                break;
                
            case 'fixed_amount':
                $outputPrice = $this->executeFixedAmountRule($rule, $inputPrice);
                break;
                
            case 'tiered':
                $outputPrice = $this->executeTieredRule($rule, $inputPrice, $context);
                break;
                
            case 'conditional':
                $outputPrice = $this->executeConditionalRule($rule, $inputPrice, $context);
                break;
        }
        
        // Apply rounding
        if (!empty($rule->rounding_rule) && $rule->rounding_rule !== 'none') {
            $outputPrice = $this->applyRounding($outputPrice, $rule->rounding_rule);
        }
        
        return array(
            'success' => true,
            'input_price' => $inputPrice,
            'price' => $outputPrice
        );
    }
    
    /**
     * Execute formula-based rule
     */
    private function executeFormulaRule($rule, $inputPrice, $variantId, $branchId, $context) {
        // Get variables
        $variables = $this->getRuleVariables($rule->id);
        
        // Build variable values
        $varValues = array(
            'base_price' => $inputPrice,
            'current_price' => $inputPrice
        );
        
        // Get variant details
        $variant = $this->getVariantDetails($variantId);
        $varValues['weight'] = floatval($variant->weight_variant);
        $varValues['variant_weight'] = floatval($variant->weight_variant);
        
        // Add custom variables
        foreach ($variables as $var) {
            $varValues[$var->variable_name] = $this->resolveVariable($var, $varValues, $context);
        }
        
        // Start with the formula template
        $formula = $rule->formula_template;
        
        // Check if formula is empty or null
        if (empty($formula)) {
            $this->trace("ERROR: Formula template is empty");
            throw new Exception("Formula template is empty for rule: " . $rule->rule_name);
        }
        
        $this->trace("Formula before evaluation: {$formula}");
        
        // Replace variables in formula - order matters, do longer names first
        $sortedVars = $varValues;
        uksort($sortedVars, function($a, $b) {
            return strlen($b) - strlen($a);
        });
        
        foreach ($sortedVars as $name => $value) {
            // Replace {variable} format first
            $formula = str_replace('{' . $name . '}', $value, $formula);
            
            // Replace $variable format
            $formula = str_replace('$' . $name, $value, $formula);
            
            // Replace plain variable name with word boundaries
            // This ensures we replace "base_price" but not "base_price_2"
            $formula = preg_replace('/\b' . preg_quote($name, '/') . '\b/', $value, $formula);
        }
        
        $this->trace("Formula after substitution: {$formula}");
        
        // Check if there are still unreplaced variables
        if (preg_match('/\{[^}]+\}/', $formula, $matches)) {
            $this->trace("WARNING: Unreplaced variables found: " . implode(', ', $matches));
            throw new Exception("Unreplaced variables in formula: " . implode(', ', $matches));
        }
        
        // Evaluate formula safely
        try {
            $result = $this->evaluateFormula($formula);
            $this->trace("Formula result: {$result}");
            return $result;
        } catch (Exception $e) {
            $this->trace("Formula evaluation error: " . $e->getMessage());
            throw new Exception("Error evaluating formula for rule '{$rule->rule_name}': " . $e->getMessage());
        }
    }
    
    /**
     * Execute percentage-based rule
     */
    private function executePercentageRule($rule, $inputPrice) {
        $percentage = floatval($rule->percentage_value);
        $adjustment = $inputPrice * ($percentage / 100);
        $result = $inputPrice + $adjustment;
        
        $this->trace("Percentage calculation: {$inputPrice} + ({$inputPrice} * {$percentage}/100) = {$result}");
        
        return $result;
    }
    
    /**
     * Execute fixed amount rule
     */
    private function executeFixedAmountRule($rule, $inputPrice) {
        $amount = floatval($rule->fixed_amount);
        
        switch ($rule->calculation_method) {
            case 'add':
                $result = $inputPrice + $amount;
                break;
            case 'subtract':
                $result = $inputPrice - $amount;
                break;
            case 'multiply':
                $result = $inputPrice * $amount;
                break;
            case 'divide':
                $result = $inputPrice / $amount;
                break;
            default:
                $result = $inputPrice + $amount;
        }
        
        $this->trace("Fixed amount calculation: {$inputPrice} {$rule->calculation_method} {$amount} = {$result}");
        
        return $result;
    }
    
    /**
     * Execute tiered rule
     */
    private function executeTieredRule($rule, $inputPrice, $context) {
        // Get actions for this rule
        $sql = "SELECT * FROM pricing_rule_actions 
                WHERE rule_id = ? 
                ORDER BY action_order ASC";
        $this->db->query($sql, array($rule->id));
        $actions = $this->db->results();
        
        $currentPrice = $inputPrice;
        
        foreach ($actions as $action) {
            // Check if action should execute
            if (!empty($action->execute_if)) {
                if (!$this->evaluateExpression($action->execute_if, $context)) {
                    continue;
                }
            }
            
            // Execute action
            switch ($action->action_type) {
                case 'calculate':
                case 'adjust':
                    $currentPrice = $this->evaluateFormula(
                        str_replace('base_price', $currentPrice, $action->action_expression)
                    );
                    break;
                    
                case 'set_minimum':
                    $minPrice = floatval($action->action_expression);
                    if ($currentPrice < $minPrice) {
                        $currentPrice = $minPrice;
                    }
                    break;
                    
                case 'set_maximum':
                    $maxPrice = floatval($action->action_expression);
                    if ($currentPrice > $maxPrice) {
                        $currentPrice = $maxPrice;
                    }
                    break;
            }
            
            $this->trace("Action {$action->action_order} executed: {$action->action_type} -> ৳{$currentPrice}");
        }
        
        return $currentPrice;
    }
    
    /**
     * Evaluate conditions for a rule
     */
    private function evaluateConditions($ruleId, $context) {
        $sql = "SELECT * FROM pricing_rule_conditions WHERE rule_id = ?";
        $this->db->query($sql, array($ruleId));
        $conditions = $this->db->results();
        
        if (empty($conditions)) {
            return true; // No conditions = always true
        }
        
        // Group conditions by condition_group
        $groups = array();
        foreach ($conditions as $cond) {
            $groups[$cond->condition_group][] = $cond;
        }
        
        // Evaluate each group
        $groupResults = array();
        foreach ($groups as $groupNum => $groupConditions) {
            $groupResult = true;
            
            foreach ($groupConditions as $cond) {
                $condResult = $this->evaluateSingleCondition($cond, $context);
                
                if ($cond->condition_operator === 'AND') {
                    $groupResult = $groupResult && $condResult;
                } else {
                    $groupResult = $groupResult || $condResult;
                }
            }
            
            $groupResults[] = $groupResult;
        }
        
        // Any group being true makes the overall condition true
        return in_array(true, $groupResults, true);
    }
    
    /**
     * Evaluate single condition
     */
    private function evaluateSingleCondition($condition, $context) {
        $fieldValue = isset($context[$condition->field_name]) ? $context[$condition->field_name] : null;
        $compareValue = $condition->comparison_value;
        
        // Try to parse JSON for IN/NOT_IN operations
        if (in_array($condition->comparison_operator, array('in', 'not_in', 'between'))) {
            $compareValue = json_decode($compareValue, true);
        }
        
        switch ($condition->comparison_operator) {
            case 'equals':
                return $fieldValue == $compareValue;
                
            case 'not_equals':
                return $fieldValue != $compareValue;
                
            case 'greater_than':
                return $fieldValue > $compareValue;
                
            case 'less_than':
                return $fieldValue < $compareValue;
                
            case 'greater_or_equal':
                return $fieldValue >= $compareValue;
                
            case 'less_or_equal':
                return $fieldValue <= $compareValue;
                
            case 'in':
                return in_array($fieldValue, $compareValue);
                
            case 'not_in':
                return !in_array($fieldValue, $compareValue);
                
            case 'between':
                return $fieldValue >= $compareValue[0] && $fieldValue <= $compareValue[1];
                
            case 'contains':
                return strpos($fieldValue, $compareValue) !== false;
                
            default:
                return false;
        }
    }
    
    /**
     * Apply rounding rules
     */
    private function applyRounding($price, $roundingRule) {
        switch ($roundingRule) {
            case 'nearest_5':
                return round($price / 5) * 5;
                
            case 'nearest_10':
                return round($price / 10) * 10;
                
            case 'nearest_100':
                return round($price / 100) * 100;
                
            case 'ceiling_5':
                return ceil($price / 5) * 5;
                
            case 'floor_5':
                return floor($price / 5) * 5;
                
            case 'none':
            default:
                return $price;
        }
    }
    
    /**
     * Safely evaluate mathematical formula
     */
    private function evaluateFormula($formula) {
        // Remove whitespace
        $formula = trim($formula);
        
        // Check if formula is empty
        if (empty($formula)) {
            throw new Exception("Formula is empty");
        }
        
        // If it's already a simple number, just return it
        if (is_numeric($formula)) {
            return floatval($formula);
        }
        
        // Remove any dangerous characters (keep only numbers, operators, parentheses, dots, and minus sign)
        $cleanFormula = preg_replace('/[^0-9+\-*\/().\s]/', '', $formula);
        
        // Remove extra spaces
        $cleanFormula = preg_replace('/\s+/', '', $cleanFormula);
        
        // Check if formula was completely stripped
        if (empty($cleanFormula)) {
            throw new Exception("Formula contains no valid mathematical expressions: " . $formula);
        }
        
        // If it's a simple number after cleaning, return it
        if (is_numeric($cleanFormula)) {
            return floatval($cleanFormula);
        }
        
        // Check for balanced parentheses
        $openCount = substr_count($cleanFormula, '(');
        $closeCount = substr_count($cleanFormula, ')');
        if ($openCount !== $closeCount) {
            throw new Exception("Formula has unbalanced parentheses: {$cleanFormula}");
        }
        
        // Check for empty parentheses
        if (strpos($cleanFormula, '()') !== false) {
            throw new Exception("Formula has empty parentheses: {$cleanFormula}");
        }
        
        // Check for operators at the start or end
        if (preg_match('/^[\+\*\/]/', $cleanFormula) || preg_match('/[\+\-\*\/]$/', $cleanFormula)) {
            throw new Exception("Formula has operator at start or end: {$cleanFormula}");
        }
        
        // Check for consecutive operators (except for negative numbers like +-5 or *-3)
        if (preg_match('/[\+\*\/]{2,}/', $cleanFormula)) {
            throw new Exception("Formula has consecutive operators: {$cleanFormula}");
        }
        
        // Log what we're about to evaluate
        error_log("Evaluating formula: {$cleanFormula}");
        
        // Evaluate using eval in controlled environment
        try {
            // Create a more controlled eval with error suppression
            set_error_handler(function($errno, $errstr) {
                throw new Exception("Eval error: {$errstr}");
            });
            
            $result = eval("return ({$cleanFormula});");
            
            restore_error_handler();
            
            // Check if result is numeric
            if (!is_numeric($result)) {
                throw new Exception("Formula did not produce a numeric result: " . var_export($result, true));
            }
            
            return floatval($result);
            
        } catch (ParseError $e) {
            restore_error_handler();
            throw new Exception("Formula parse error: " . $e->getMessage() . " | Formula: {$cleanFormula}");
        } catch (Exception $e) {
            restore_error_handler();
            throw new Exception("Formula evaluation error: " . $e->getMessage() . " | Formula: {$cleanFormula}");
        }
    }
    
    /**
     * Evaluate expression
     */
    private function evaluateExpression($expression, $context) {
        // Simple expression evaluation
        // This is a basic implementation, can be enhanced
        foreach ($context as $key => $value) {
            $expression = str_replace($key, $value, $expression);
        }
        
        try {
            $result = @eval("return ({$expression});");
            return (bool)$result;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get variant details
     */
    private function getVariantDetails($variantId) {
        $sql = "SELECT pv.*, p.base_name, p.category
                FROM product_variants pv
                JOIN products p ON pv.product_id = p.id
                WHERE pv.id = ?";
        $this->db->query($sql, array($variantId));
        return $this->db->first();
    }
    
    /**
     * Get rule variables
     */
    private function getRuleVariables($ruleId) {
        $sql = "SELECT * FROM pricing_rule_variables WHERE rule_id = ?";
        $this->db->query($sql, array($ruleId));
        return $this->db->results();
    }
    
    /**
     * Resolve variable value
     */
    private function resolveVariable($variable, $currentValues, $context) {
        switch ($variable->variable_type) {
            case 'static':
                return floatval($variable->static_value);
                
            case 'dynamic':
                return isset($currentValues[$variable->dynamic_source]) ? $currentValues[$variable->dynamic_source] : 0;
                
            case 'calculated':
                $expr = $variable->calculation_expression;
                foreach ($currentValues as $name => $value) {
                    $expr = str_replace('{' . $name . '}', $value, $expr);
                }
                return $this->evaluateFormula($expr);
                
            default:
                return 0;
        }
    }
    
    /**
     * Trace execution for debugging
     */
    private function trace($message) {
        $this->executionTrace[] = array(
            'timestamp' => microtime(true),
            'message' => $message
        );
        
        if ($this->debugMode) {
            error_log("[PricingEngine] " . $message);
        }
    }
    
    /**
     * Generate unique batch ID
     */
    private function generateBatchId() {
        return uniqid('BATCH_', true);
    }
    
    /**
     * Log execution to database
     */
    private function logExecution($data) {
        try {
            $pdo = $this->db->getPdo();
            $stmt = $pdo->prepare("
                INSERT INTO pricing_rule_execution_log (
                    execution_batch_id, variant_id, branch_id,
                    input_values, calculated_price, final_price,
                    execution_status, debug_trace, rules_chain,
                    executed_by_user_id, executed_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute(array(
                $data['batch_id'],
                $data['variant_id'],
                $data['branch_id'],
                json_encode(array(
                    'base_price' => isset($data['base_price']) ? $data['base_price'] : null,
                    'context' => isset($data['context']) ? $data['context'] : array()
                )),
                isset($data['calculated_price']) ? $data['calculated_price'] : null,
                isset($data['final_price']) ? $data['final_price'] : null,
                $data['status'],
                json_encode($this->executionTrace),
                json_encode(isset($data['rules_applied']) ? $data['rules_applied'] : array()),
                $this->user_id
            ));
        } catch (Exception $e) {
            error_log("Failed to log execution: " . $e->getMessage());
        }
    }
    
    /**
     * Enable debug mode
     */
    public function enableDebug() {
        $this->debugMode = true;
    }
}
?>
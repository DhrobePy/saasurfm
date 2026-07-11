<?php
/**
 * Pricing Rule Manager
 * Handles CRUD operations for pricing rules
 * 
 * Ujjal Flour Mills - SaaS Platform
 */

class PricingRuleManager {
    private $db;
    private $user_id;
    
    public function __construct($db, $user_id = null) {
        $this->db = $db;
        $this->user_id = $user_id ?? $_SESSION['user_id'] ?? null;
    }
    
    /**
     * Create new pricing rule
     */
    public function createRule($ruleData) {
        $pdo = $this->db->getPdo();
        
        try {
            $pdo->beginTransaction();
            
            // Insert main rule
            $stmt = $pdo->prepare("
                INSERT INTO pricing_rules (
                    rule_code, rule_name, description, rule_type, rule_category,
                    priority, is_active, valid_from, valid_to,
                    applies_to_grades, applies_to_branches, applies_to_weights,
                    formula_template, calculation_method, percentage_value,
                    fixed_amount, rounding_rule, created_by_user_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute(array(
                $ruleData['rule_code'],
                $ruleData['rule_name'],
                isset($ruleData['description']) ? $ruleData['description'] : null,
                $ruleData['rule_type'],
                $ruleData['rule_category'],
                isset($ruleData['priority']) ? $ruleData['priority'] : 100,
                isset($ruleData['is_active']) ? $ruleData['is_active'] : 1,
                isset($ruleData['valid_from']) ? $ruleData['valid_from'] : null,
                isset($ruleData['valid_to']) ? $ruleData['valid_to'] : null,
                isset($ruleData['applies_to_grades']) ? json_encode($ruleData['applies_to_grades']) : null,
                isset($ruleData['applies_to_branches']) ? json_encode($ruleData['applies_to_branches']) : null,
                isset($ruleData['applies_to_weights']) ? json_encode($ruleData['applies_to_weights']) : null,
                isset($ruleData['formula_template']) ? $ruleData['formula_template'] : null,
                isset($ruleData['calculation_method']) ? $ruleData['calculation_method'] : null,
                isset($ruleData['percentage_value']) ? $ruleData['percentage_value'] : null,
                isset($ruleData['fixed_amount']) ? $ruleData['fixed_amount'] : null,
                isset($ruleData['rounding_rule']) ? $ruleData['rounding_rule'] : 'ceiling_5',
                $this->user_id
            ));
            
            $ruleId = $pdo->lastInsertId();
            
            // Insert variables if provided
            if (!empty($ruleData['variables'])) {
                $this->addRuleVariables($ruleId, $ruleData['variables']);
            }
            
            // Insert conditions if provided
            if (!empty($ruleData['conditions'])) {
                $this->addRuleConditions($ruleId, $ruleData['conditions']);
            }
            
            // Insert actions if provided
            if (!empty($ruleData['actions'])) {
                $this->addRuleActions($ruleId, $ruleData['actions']);
            }
            
            $pdo->commit();
            
            return array(
                'success' => true,
                'rule_id' => $ruleId,
                'message' => 'Pricing rule created successfully'
            );
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Update existing rule
     */
    public function updateRule($ruleId, $ruleData) {
        $pdo = $this->db->getPdo();
        
        try {
            $pdo->beginTransaction();
            
            // Build dynamic UPDATE query
            $fields = array();
            $values = array();
            
            $allowedFields = array(
                'rule_name', 'description', 'rule_type', 'rule_category',
                'priority', 'is_active', 'valid_from', 'valid_to',
                'formula_template', 'calculation_method', 'percentage_value',
                'fixed_amount', 'rounding_rule'
            );
            
            foreach ($allowedFields as $field) {
                if (isset($ruleData[$field])) {
                    $fields[] = "$field = ?";
                    $values[] = $ruleData[$field];
                }
            }
            
            // JSON fields
            $jsonFields = array('applies_to_grades', 'applies_to_branches', 'applies_to_weights');
            foreach ($jsonFields as $field) {
                if (isset($ruleData[$field])) {
                    $fields[] = "$field = ?";
                    $values[] = json_encode($ruleData[$field]);
                }
            }
            
            if (empty($fields)) {
                throw new Exception("No fields to update");
            }
            
            $fields[] = "updated_by_user_id = ?";
            $values[] = $this->user_id;
            $values[] = $ruleId;
            
            $sql = "UPDATE pricing_rules SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            
            $pdo->commit();
            
            return array(
                'success' => true,
                'message' => 'Pricing rule updated successfully'
            );
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Delete rule (soft delete)
     */
    public function deleteRule($ruleId) {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            UPDATE pricing_rules 
            SET is_active = 0, updated_by_user_id = ?
            WHERE id = ?
        ");
        
        $stmt->execute(array($this->user_id, $ruleId));
        
        return array(
            'success' => true,
            'message' => 'Pricing rule deactivated successfully'
        );
    }
    
    /**
     * Get all rules
     */
    public function getAllRules($filters = array()) {
        $sql = "SELECT * FROM pricing_rules WHERE 1=1";
        $params = array();
        
        if (isset($filters['is_active'])) {
            $sql .= " AND is_active = ?";
            $params[] = $filters['is_active'];
        }
        
        if (isset($filters['rule_type'])) {
            $sql .= " AND rule_type = ?";
            $params[] = $filters['rule_type'];
        }
        
        if (isset($filters['rule_category'])) {
            $sql .= " AND rule_category = ?";
            $params[] = $filters['rule_category'];
        }
        
        $sql .= " ORDER BY priority DESC, created_at DESC";
        
        $this->db->query($sql, $params);
        return $this->db->results();
    }
    
    /**
     * Get single rule by ID
     */
    public function getRule($ruleId) {
        $sql = "SELECT * FROM pricing_rules WHERE id = ?";
        $this->db->query($sql, array($ruleId));
        $rule = $this->db->first();
        
        if ($rule) {
            // Get related data
            $rule->variables = $this->getRuleVariables($ruleId);
            $rule->conditions = $this->getRuleConditions($ruleId);
            $rule->actions = $this->getRuleActions($ruleId);
        }
        
        return $rule;
    }
    
    /**
     * Add variables to rule
     */
    private function addRuleVariables($ruleId, $variables) {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            INSERT INTO pricing_rule_variables (
                rule_id, variable_name, variable_type, static_value,
                dynamic_source, calculation_expression, description
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($variables as $var) {
            $stmt->execute(array(
                $ruleId,
                $var['variable_name'],
                $var['variable_type'],
                isset($var['static_value']) ? $var['static_value'] : null,
                isset($var['dynamic_source']) ? $var['dynamic_source'] : null,
                isset($var['calculation_expression']) ? $var['calculation_expression'] : null,
                isset($var['description']) ? $var['description'] : null
            ));
        }
    }
    
    /**
     * Add conditions to rule
     */
    private function addRuleConditions($ruleId, $conditions) {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            INSERT INTO pricing_rule_conditions (
                rule_id, condition_group, condition_operator,
                field_name, comparison_operator, comparison_value
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($conditions as $cond) {
            $stmt->execute(array(
                $ruleId,
                isset($cond['condition_group']) ? $cond['condition_group'] : 1,
                isset($cond['condition_operator']) ? $cond['condition_operator'] : 'AND',
                $cond['field_name'],
                $cond['comparison_operator'],
                is_array($cond['comparison_value']) ? 
                    json_encode($cond['comparison_value']) : 
                    $cond['comparison_value']
            ));
        }
    }
    
    /**
     * Add actions to rule
     */
    private function addRuleActions($ruleId, $actions) {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            INSERT INTO pricing_rule_actions (
                rule_id, action_order, action_type,
                action_expression, action_params, execute_if
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($actions as $action) {
            $stmt->execute(array(
                $ruleId,
                $action['action_order'],
                $action['action_type'],
                $action['action_expression'],
                json_encode(isset($action['action_params']) ? $action['action_params'] : null),
                isset($action['execute_if']) ? $action['execute_if'] : null
            ));
        }
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
     * Get rule conditions
     */
    private function getRuleConditions($ruleId) {
        $sql = "SELECT * FROM pricing_rule_conditions WHERE rule_id = ? ORDER BY condition_group";
        $this->db->query($sql, array($ruleId));
        return $this->db->results();
    }
    
    /**
     * Get rule actions
     */
    private function getRuleActions($ruleId) {
        $sql = "SELECT * FROM pricing_rule_actions WHERE rule_id = ? ORDER BY action_order";
        $this->db->query($sql, array($ruleId));
        return $this->db->results();
    }
    
    /**
     * Test rule without applying
     */
    public function testRule($ruleId, $testData) {
        $rule = $this->getRule($ruleId);
        if (!$rule) {
            throw new Exception("Rule not found");
        }
        
        $engine = new PricingRulesEngine($this->db, $this->user_id);
        $engine->enableDebug();
        
        $result = $engine->calculatePrice(
            $testData['variant_id'],
            $testData['branch_id'],
            $testData['base_price'],
            isset($testData['context']) ? $testData['context'] : array()
        );
        
        return $result;
    }
    
    /**
     * Clone rule
     */
    public function cloneRule($ruleId, $newRuleCode, $newRuleName) {
        $rule = $this->getRule($ruleId);
        if (!$rule) {
            throw new Exception("Rule not found");
        }
        
        $ruleData = (array) $rule;
        $ruleData['rule_code'] = $newRuleCode;
        $ruleData['rule_name'] = $newRuleName;
        unset($ruleData['id']);
        unset($ruleData['created_at']);
        unset($ruleData['updated_at']);
        
        return $this->createRule($ruleData);
    }
}
?>
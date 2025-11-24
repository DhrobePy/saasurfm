<?php
/**
 * Goods Received Adnan Manager Class
 * Handles goods receipt operations with truck tracking and weight variance
 * 
 * @package Ujjal Flour Mills
 * @subpackage Purchase (Adnan) Module
 * @version 1.0.0
 */

class GoodsReceivedAdnanManager {
    private $db;
    private $variance_threshold = 0.5; // 0.5% variance threshold
    
    public function __construct() {
        $this->db = Database::getInstance()->getPdo();
    }
    
    /**
     * Record goods received
     * 
     * @param array $data GRN data
     * @return array Result with success status and GRN ID
     */
    public function recordGoodsReceived($data) {
        try {
            // Validate required fields
            $required = ['purchase_order_id', 'grn_date', 'quantity_received_kg'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => "Field {$field} is required"];
                }
            }
            
            // Get PO details
            $po = $this->getPO($data['purchase_order_id']);
            if (!$po) {
                return ['success' => false, 'message' => 'Invalid purchase order'];
            }
            
            // Generate GRN number
            $grn_number = $this->generateGRNNumber();
            
            // Calculate total value
            $total_value = $data['quantity_received_kg'] * $po->unit_price_per_kg;
            
            // Calculate weight variance if expected quantity provided
            $weight_variance = null;
            $variance_percentage = null;
            if (!empty($data['expected_quantity'])) {
                $weight_variance = $data['quantity_received_kg'] - $data['expected_quantity'];
                $variance_percentage = ($weight_variance / $data['expected_quantity']) * 100;
            }
            
            // Get current user
            $current_user = getCurrentUser();
            
            // Insert GRN
            $sql = "INSERT INTO goods_received_adnan (
                grn_number, purchase_order_id, po_number, grn_date, truck_number,
                supplier_id, supplier_name, quantity_received_kg, unit_price_per_kg,
                total_value, expected_quantity, variance_percentage, variance_remarks,
                unload_point_branch_id, unload_point_name, grn_status, remarks,
                receiver_user_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $grn_number,
                $data['purchase_order_id'],
                $po->po_number,
                $data['grn_date'],
                $data['truck_number'] ?? null,
                $po->supplier_id,
                $po->supplier_name,
                $data['quantity_received_kg'],
                $po->unit_price_per_kg,
                $total_value,
                $data['expected_quantity'] ?? null,
                $variance_percentage,
                $data['variance_remarks'] ?? null,
                $data['unload_point_branch_id'] ?? null,
                $data['unload_point_name'] ?? null,
                'verified', // Auto-verify for now
                $data['remarks'] ?? null,
                $current_user['id']
            ]);
            
            $grn_id = $this->db->lastInsertId();
            
            // Record weight variance if significant
            if ($variance_percentage !== null && abs($variance_percentage) > $this->variance_threshold) {
                $this->recordWeightVariance($grn_id, $data['purchase_order_id'], $data);
            }
            
            // Create journal entry for inventory
            $this->createInventoryJournalEntry($grn_id, $po, $data['quantity_received_kg']);
            
            return [
                'success' => true,
                'message' => 'Goods received recorded successfully',
                'grn_id' => $grn_id,
                'grn_number' => $grn_number,
                'variance_alert' => ($variance_percentage !== null && abs($variance_percentage) > 1.0)
            ];
            
        } catch (Exception $e) {
            error_log("Error recording GRN: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error recording goods received: ' . $e->getMessage()];
        }
    }
    
    /**
     * Generate sequential GRN number
     * 
     * @return string GRN number
     */
    private function generateGRNNumber() {
        $prefix = 'GRN-' . date('Ymd') . '-';
        
        $sql = "SELECT COALESCE(MAX(CAST(SUBSTRING(grn_number, -4) AS UNSIGNED)), 0) AS max_grn 
                FROM goods_received_adnan 
                WHERE grn_number LIKE ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$prefix . '%']);
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        
        $next_number = str_pad($result->max_grn + 1, 4, '0', STR_PAD_LEFT);
        return $prefix . $next_number;
    }
    
    /**
     * Record weight variance
     * 
     * @param int $grn_id GRN ID
     * @param int $po_id PO ID
     * @param array $data Variance data
     * @return bool Success
     */
    private function recordWeightVariance($grn_id, $po_id, $data) {
        try {
            $po = $this->getPO($po_id);
            $variance = $data['quantity_received_kg'] - $data['expected_quantity'];
            $variance_percentage = ($variance / $data['expected_quantity']) * 100;
            $variance_value = abs($variance) * $po->unit_price_per_kg;
            
            $variance_type = 'normal';
            if ($variance < 0) {
                $variance_type = 'loss';
            } elseif ($variance > 0) {
                $variance_type = 'gain';
            }
            
            $sql = "INSERT INTO weight_variances_adnan (
                grn_id, purchase_order_id, truck_number, ordered_quantity,
                received_quantity, variance, variance_percentage, variance_type,
                variance_value, remarks
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                $grn_id,
                $po_id,
                $data['truck_number'] ?? null,
                $data['expected_quantity'],
                $data['quantity_received_kg'],
                $variance,
                $variance_percentage,
                $variance_type,
                $variance_value,
                $data['variance_remarks'] ?? null
            ]);
            
        } catch (Exception $e) {
            error_log("Error recording variance: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create journal entry for inventory receipt
     * 
     * @param int $grn_id GRN ID
     * @param object $po PO object
     * @param float $quantity Quantity received
     * @return void
     */
    private function createInventoryJournalEntry($grn_id, $po, $quantity) {
        try {
            $amount = $quantity * $po->unit_price_per_kg;
            
            // Determine inventory account based on wheat origin
            $inventory_account_code = ($po->wheat_origin === 'কানাডা') ? '1400' : '1401';
            
            // Get account IDs
            $inventory_account = $this->getAccountByCode($inventory_account_code);
            $grn_pending_account = $this->getAccountByCode('2110');
            
            if (!$inventory_account || !$grn_pending_account) {
                error_log("Missing accounts for GRN journal entry");
                return;
            }
            
            // Create journal entry
            $current_user = getCurrentUser();
            $description = "Goods received for PO {$po->po_number} - {$quantity}KG {$po->wheat_origin} wheat @ ৳{$po->unit_price_per_kg}/KG";
            
            $journal_sql = "INSERT INTO journal_entries (
                uuid, transaction_date, description, related_document_type, related_document_id,
                created_by_user_id
            ) VALUES (UUID(), CURDATE(), ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($journal_sql);
            $stmt->execute([
                $description,
                'grn_adnan',
                $grn_id,
                $current_user['id']
            ]);
            
            $journal_id = $this->db->lastInsertId();
            
            // Create journal transaction lines
            $detail_sql = "INSERT INTO transaction_lines (
                journal_entry_id, account_id, debit_amount, credit_amount, description
            ) VALUES (?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($detail_sql);
            
            // Debit: Inventory
            $stmt->execute([
                $journal_id,
                $inventory_account->id,
                $amount,
                0,
                "Inventory - {$po->wheat_origin}"
            ]);
            
            // Credit: GRN Pending
            $stmt->execute([
                $journal_id,
                $grn_pending_account->id,
                0,
                $amount,
                "GRN Pending - PO {$po->po_number}"
            ]);
            
        } catch (Exception $e) {
            error_log("Error creating inventory journal entry: " . $e->getMessage());
        }
    }
    
    /**
     * Get GRN by ID
     * 
     * @param int $grn_id GRN ID
     * @return object|null GRN object
     */
    public function getGRN($grn_id) {
        $sql = "SELECT * FROM goods_received_adnan WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$grn_id]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get all GRNs with filters
     * 
     * @param array $filters Filter criteria
     * @return array GRN list
     */
    public function listGRNs($filters = []) {
        $sql = "SELECT * FROM goods_received_adnan WHERE 1=1";
        $params = [];
        
        if (!empty($filters['po_id'])) {
            $sql .= " AND purchase_order_id = ?";
            $params[] = $filters['po_id'];
        }
        
        if (!empty($filters['supplier_id'])) {
            $sql .= " AND supplier_id = ?";
            $params[] = $filters['supplier_id'];
        }
        
        if (!empty($filters['branch_id'])) {
            $sql .= " AND unload_point_branch_id = ?";
            $params[] = $filters['branch_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND grn_date >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND grn_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        $sql .= " ORDER BY grn_date DESC, id DESC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get variance analysis
     * 
     * @param array $filters Filter criteria
     * @return array Variance records
     */
    public function getVarianceAnalysis($filters = []) {
        $sql = "SELECT * FROM v_purchase_adnan_variance_analysis WHERE 1=1";
        $params = [];
        
        if (!empty($filters['variance_type'])) {
            $sql .= " AND variance_type = ?";
            $params[] = $filters['variance_type'];
        }
        
        if (!empty($filters['min_percentage'])) {
            $sql .= " AND ABS(variance_percentage) >= ?";
            $params[] = $filters['min_percentage'];
        }
        
        $sql .= " ORDER BY ABS(variance_percentage) DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get PO object
     * 
     * @param int $po_id PO ID
     * @return object|null PO object
     */
    private function getPO($po_id) {
        $sql = "SELECT * FROM purchase_orders_adnan WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$po_id]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get account by code
     * 
     * @param string $code Account code
     * @return object|null Account object
     */
    private function getAccountByCode($code) {
        $sql = "SELECT * FROM chart_of_accounts WHERE account_number = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$code]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get GRN statistics by branch
     * 
     * @return array Branch-wise stats
     */
    public function getStatsByBranch() {
        $sql = "SELECT 
            unload_point_name,
            COUNT(*) as grn_count,
            SUM(quantity_received_kg) as total_quantity,
            SUM(total_value) as total_value,
            AVG(quantity_received_kg) as avg_quantity_per_truck
        FROM goods_received_adnan
        WHERE grn_status != 'cancelled'
        GROUP BY unload_point_name";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
}
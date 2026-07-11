<?php
/**
 * Purchase Adnan Manager Class
 * Handles Purchase Order operations for Adnan's wheat procurement workflow
 * 
 * @package Ujjal Flour Mills
 * @subpackage Purchase (Adnan) Module
 * @author SaaS Development Team
 * @version 1.0.0
 */

class PurchaseAdnanManager {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getPdo();
    }
    
    /**
     * Create a new purchase order
     * 
     * @param array $data PO data
     * @return array Result with success status and PO ID
     */
    public function createPurchaseOrder($data) {
        try {
            // Validate required fields
            $required = ['supplier_id', 'wheat_origin', 'quantity_kg', 'unit_price_per_kg', 'po_date'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => "Field {$field} is required"];
                }
            }
            
            // Get supplier name
            $supplier = $this->getSupplierById($data['supplier_id']);
            if (!$supplier) {
                return ['success' => false, 'message' => 'Invalid supplier'];
            }
            
            // Calculate total
            $total_order_value = $data['quantity_kg'] * $data['unit_price_per_kg'];
            
            // Generate PO number (sequential)
            $po_number = $this->generatePONumber();
            
            // Get current user
            $current_user = getCurrentUser();
            
            // Insert PO
            $sql = "INSERT INTO purchase_orders_adnan (
                po_number, po_date, supplier_id, supplier_name, branch_id,
                wheat_origin, quantity_kg, unit_price_per_kg, total_order_value,
                expected_delivery_date, remarks, created_by_user_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $po_number,
                $data['po_date'],
                $data['supplier_id'],
                $supplier->name,
                $data['branch_id'] ?? null,
                $data['wheat_origin'],
                $data['quantity_kg'],
                $data['unit_price_per_kg'],
                $total_order_value,
                $data['expected_delivery_date'] ?? null,
                $data['remarks'] ?? null,
                $current_user['id']
            ]);
            
            $po_id = $this->db->lastInsertId();
            
            return [
                'success' => true,
                'message' => 'Purchase Order created successfully',
                'po_id' => $po_id,
                'po_number' => $po_number
            ];
            
        } catch (Exception $e) {
            error_log("Error creating PO: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error creating purchase order: ' . $e->getMessage()];
        }
    }
    
    /**
     * Generate sequential PO number
     * 
     * @return string PO number
     */
    private function generatePONumber() {
        $sql = "SELECT COALESCE(MAX(CAST(po_number AS UNSIGNED)), 441) AS max_po FROM purchase_orders_adnan";
        $stmt = $this->db->query($sql);
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        return (string)($result->max_po + 1);
    }
    
    /**
     * Get purchase order by ID
     * 
     * @param int $po_id Purchase order ID
     * @return object|null PO object
     */
    public function getPurchaseOrder($po_id) {
        $sql = "SELECT * FROM purchase_orders_adnan WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$po_id]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get purchase order by PO number
     * 
     * @param string $po_number PO number
     * @return object|null PO object
     */
    public function getPurchaseOrderByNumber($po_number) {
        $sql = "SELECT * FROM purchase_orders_adnan WHERE po_number = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$po_number]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get all purchase orders with filters
     * 
     * @param array $filters Filter criteria
     * @return array List of POs
     */
    public function listPurchaseOrders($filters = []) {
        $sql = "SELECT * FROM v_purchase_adnan_dashboard WHERE 1=1";
        $params = [];
        
        // Apply filters
        if (!empty($filters['supplier_id'])) {
            $sql .= " AND supplier_id = ?";
            $params[] = $filters['supplier_id'];
        }
        
        if (!empty($filters['wheat_origin'])) {
            $sql .= " AND wheat_origin = ?";
            $params[] = $filters['wheat_origin'];
        }
        
        if (isset($filters['delivery_status']) && !empty($filters['delivery_status'])) {
            if (is_array($filters['delivery_status'])) {
                $placeholders = implode(',', array_fill(0, count($filters['delivery_status']), '?'));
                $sql .= " AND delivery_status IN ({$placeholders})";
                foreach ($filters['delivery_status'] as $status) {
                    $params[] = $status;
                }
            } else {
                $sql .= " AND delivery_status = ?";
                $params[] = $filters['delivery_status'];
            }
        }
        
        if (isset($filters['payment_status']) && !empty($filters['payment_status'])) {
            if (is_array($filters['payment_status'])) {
                $placeholders = implode(',', array_fill(0, count($filters['payment_status']), '?'));
                $sql .= " AND payment_status IN ({$placeholders})";
                foreach ($filters['payment_status'] as $status) {
                    $params[] = $status;
                }
            } else {
                $sql .= " AND payment_status = ?";
                $params[] = $filters['payment_status'];
            }
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND po_date >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND po_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        $sql .= " ORDER BY po_date DESC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get supplier summary (Topsheet)
     * 
     * @return array Supplier-wise summary
     */
    public function getSupplierSummary() {
        $sql = "SELECT * FROM v_purchase_adnan_supplier_summary ORDER BY balance_payable DESC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get dashboard statistics
     * 
     * @return array Dashboard KPIs
     */
    public function getDashboardStats() {
        $sql = "SELECT 
            COUNT(*) as total_orders,
            SUM(total_order_value) as total_order_value,
            SUM(total_received_value) as total_received_value,
            SUM(total_paid) as total_paid,
            SUM(balance_payable) as balance_payable,
            SUM(CASE WHEN delivery_status = 'completed' THEN 1 ELSE 0 END) as completed_deliveries,
            SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as completed_payments
        FROM purchase_orders_adnan
        WHERE po_status != 'cancelled'";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Update purchase order
     * 
     * @param int $po_id PO ID
     * @param array $data Update data
     * @return array Result
     */
    public function updatePurchaseOrder($po_id, $data) {
        try {
            $allowed_fields = ['expected_delivery_date', 'remarks', 'po_status'];
            $update_fields = [];
            $params = [];
            
            foreach ($allowed_fields as $field) {
                if (isset($data[$field])) {
                    $update_fields[] = "{$field} = ?";
                    $params[] = $data[$field];
                }
            }
            
            if (empty($update_fields)) {
                return ['success' => false, 'message' => 'No fields to update'];
            }
            
            $params[] = $po_id;
            $sql = "UPDATE purchase_orders_adnan SET " . implode(', ', $update_fields) . " WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return ['success' => true, 'message' => 'Purchase order updated successfully'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error updating PO: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get GRNs for a purchase order
     * 
     * @param int $po_id PO ID
     * @return array GRN list
     */
    public function getGRNsByPO($po_id) {
        $sql = "SELECT * FROM goods_received_adnan 
                WHERE purchase_order_id = ? 
                ORDER BY grn_date DESC, id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$po_id]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get payments for a purchase order
     * 
     * @param int $po_id PO ID
     * @return array Payment list
     */
    public function getPaymentsByPO($po_id) {
        $sql = "SELECT * FROM purchase_payments_adnan 
                WHERE purchase_order_id = ? 
                ORDER BY payment_date DESC, id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$po_id]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get supplier by ID
     * 
     * @param int $supplier_id Supplier ID
     * @return object|null Supplier object
     */
    private function getSupplierById($supplier_id) {
        $sql = "SELECT id, company_name as name FROM suppliers WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$supplier_id]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    /**
     * Get all suppliers
     * 
     * @return array Supplier list
     */
    public function getAllSuppliers() {
        $sql = "SELECT id, company_name as name FROM suppliers WHERE status = 'active' ORDER BY company_name ASC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * Get statistics by wheat origin
     * 
     * @return array Origin-wise stats
     */
    public function getStatsByOrigin() {
        $sql = "SELECT 
            wheat_origin,
            COUNT(*) as order_count,
            SUM(quantity_kg) as total_quantity,
            SUM(total_order_value) as total_value,
            AVG(unit_price_per_kg) as avg_price
        FROM purchase_orders_adnan
        WHERE po_status != 'cancelled'
        GROUP BY wheat_origin";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
}
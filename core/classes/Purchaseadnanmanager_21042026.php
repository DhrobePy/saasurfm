<?php
/**
 * Purchase Adnan Manager Class
 * Handles Purchase Order operations for Adnan's wheat procurement workflow
 * 
 * FULLY RECTIFIED VERSION 2.0
 * - Fixed cartesian product bug in listPurchaseOrders()
 * - Fixed getDashboardStats() to calculate from GRNs
 * - Only counts posted payments (is_posted = 1)
 * 
 * @package Ujjal Flour Mills
 * @subpackage Purchase (Adnan) Module
 * @author SaaS Development Team
 * @version 2.0.0
 * @date 2026-02-18
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
            $this->db->beginTransaction();
            
            // Get current user
            $current_user = getCurrentUser();
            $user_id = $current_user['id'] ?? null;
            
            // Validate required fields
            $required = ['po_date', 'supplier_id', 'wheat_origin', 'quantity_kg', 'unit_price_per_kg'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required'];
                }
            }
            
            // Handle PO number - manual or auto-generate
            $po_number = trim($data['po_number'] ?? '');
            
            if (!empty($po_number)) {
                // Manual PO number provided - check for duplicates
                $check_sql = "SELECT id FROM purchase_orders_adnan WHERE po_number = ?";
                $stmt = $this->db->prepare($check_sql);
                $stmt->execute([$po_number]);
                
                if ($stmt->fetch()) {
                    $this->db->rollBack();
                    return [
                        'success' => false, 
                        'message' => "PO Number '{$po_number}' already exists. Please use a different number or leave blank for auto-generation."
                    ];
                }
                
                error_log("Manual PO Number used: {$po_number} by user {$user_id}");
                
            } else {
                // Auto-generate PO number
                $po_number = $this->generatePONumber();
            }
            
            // Get supplier info
            $supplier = $this->getSupplierById($data['supplier_id']);
            if (!$supplier) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Invalid supplier selected'];
            }
            
            // Calculate totals
            $quantity_kg = floatval($data['quantity_kg']);
            $unit_price_per_kg = floatval($data['unit_price_per_kg']);
            $total_order_value = $quantity_kg * $unit_price_per_kg;
            
            // Insert purchase order
            $insert_sql = "
                INSERT INTO purchase_orders_adnan (
                    po_number, po_date, supplier_id, supplier_name, branch_id,
                    wheat_origin, quantity_kg, unit_price_per_kg, total_order_value,
                    expected_delivery_date, remarks, 
                    po_status, delivery_status, payment_status,
                    created_by_user_id, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?,
                    'approved', 'pending', 'unpaid',
                    ?, NOW()
                )
            ";
            
            $stmt = $this->db->prepare($insert_sql);
            $stmt->execute([
                $po_number,
                $data['po_date'],
                $data['supplier_id'],
                $supplier->name,
                $data['branch_id'] ?? null,
                $data['wheat_origin'],
                $quantity_kg,
                $unit_price_per_kg,
                $total_order_value,
                $data['expected_delivery_date'] ?? null,
                $data['remarks'] ?? null,
                $user_id
            ]);
            
            $po_id = $this->db->lastInsertId();
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Purchase Order created successfully',
                'po_id' => $po_id,
                'po_number' => $po_number,
                'total_value' => $total_order_value
            ];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("PO Creation Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
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
     * =====================================================
     * FIXED: Get all purchase orders with filters
     * Uses subqueries to prevent cartesian product
     * =====================================================
     * 
     * @param array $filters Filter criteria
     * @return array List of POs
     */
    public function listPurchaseOrders($filters = []) {
        $sql = "SELECT 
                    po.*,
                    s.company_name as supplier_name,
                    
                    -- GRN aggregates (from subquery to prevent cartesian product)
                    COALESCE(grn_data.total_expected_qty, 0) as total_expected_qty,
                    COALESCE(grn_data.total_received_qty, 0) as total_received_qty,
                    
                    -- Payment aggregates (from subquery to prevent cartesian product)
                    COALESCE(pmt_data.total_paid, 0) as total_paid
                    
                FROM purchase_orders_adnan po
                LEFT JOIN suppliers s ON po.supplier_id = s.id
                
                -- Subquery for GRN data (prevents cartesian product)
                LEFT JOIN (
                    SELECT 
                        purchase_order_id,
                        SUM(expected_quantity) as total_expected_qty,
                        SUM(quantity_received_kg) as total_received_qty
                    FROM goods_received_adnan
                    WHERE grn_status != 'cancelled'
                    GROUP BY purchase_order_id
                ) grn_data ON po.id = grn_data.purchase_order_id
                
                -- Subquery for Payment data (prevents cartesian product)
                LEFT JOIN (
                    SELECT 
                        purchase_order_id,
                        SUM(amount_paid) as total_paid
                    FROM purchase_payments_adnan
                    WHERE is_posted = 1
                    GROUP BY purchase_order_id
                ) pmt_data ON po.id = pmt_data.purchase_order_id
                
                WHERE po.po_status != 'cancelled'";
        
        $params = [];

        // ── Order status filter (primary view selector) ──────────────────────
        // Handles both 'order_status_filter' (from UI dropdown) and legacy flags.
        $osf = $filters['order_status_filter'] ?? null;
        $show_in_progress = isset($filters['show_in_progress']) && $filters['show_in_progress'] === true;

        if ($show_in_progress || $osf === 'in_progress') {
            // In Progress = needs delivery OR payment
            // Exclude: fully closed AND fully complete (delivered + paid)
            $sql .= " AND po.delivery_status != 'closed'"
                  . " AND NOT (po.delivery_status = 'completed' AND po.payment_status = 'paid')";

        } elseif ($osf === 'all_active') {
            // All active = exclude closed and cancelled only
            $sql .= " AND po.delivery_status != 'closed' AND po.po_status != 'cancelled'";

        } elseif ($osf === 'completed') {
            // Fully delivered AND fully paid
            $sql .= " AND po.delivery_status = 'completed' AND po.payment_status = 'paid'";

        } elseif ($osf === 'closed') {
            $sql .= " AND po.delivery_status = 'closed'";

        } elseif ($osf === 'cancelled') {
            $sql .= " AND po.po_status = 'cancelled'";

        } elseif ($osf === 'all') {
            // No additional WHERE — show everything including cancelled
            // (base query already excludes nothing extra)
            // Remove the base cancelled exclusion by rewriting base WHERE
            $sql = str_replace("WHERE po.po_status != 'cancelled'", "WHERE 1=1", $sql);

        } else {
            // Legacy flags — kept for backward compatibility
            if (isset($filters['exclude_closed']) && $filters['exclude_closed'] === true) {
                $sql .= " AND po.delivery_status != 'closed' AND po.po_status != 'cancelled'";
            }
            if (isset($filters['show_closed'])) {
                switch ($filters['show_closed']) {
                    case 'yes':         break;
                    case 'closed_only': $sql .= " AND po.delivery_status = 'closed'"; break;
                    case 'cancelled_only': $sql .= " AND po.po_status = 'cancelled'"; break;
                    default: $sql .= " AND po.delivery_status != 'closed' AND po.po_status != 'cancelled'";
                }
            }
        }
        
        // Apply filters
        if (!empty($filters['supplier_id'])) {
            $sql .= " AND po.supplier_id = ?";
            $params[] = $filters['supplier_id'];
        }
        
        if (!empty($filters['wheat_origin'])) {
            $sql .= " AND po.wheat_origin = ?";
            $params[] = $filters['wheat_origin'];
        }
        
        if (!empty($filters['po_status'])) {
            $sql .= " AND po.po_status = ?";
            $params[] = $filters['po_status'];
        }
        
        if (isset($filters['delivery_status']) && !empty($filters['delivery_status'])) {
            if (is_array($filters['delivery_status'])) {
                $placeholders = implode(',', array_fill(0, count($filters['delivery_status']), '?'));
                $sql .= " AND po.delivery_status IN ({$placeholders})";
                foreach ($filters['delivery_status'] as $status) {
                    $params[] = $status;
                }
            } else {
                $sql .= " AND po.delivery_status = ?";
                $params[] = $filters['delivery_status'];
            }
        }
        
        if (isset($filters['payment_status']) && !empty($filters['payment_status'])) {
            if (is_array($filters['payment_status'])) {
                $placeholders = implode(',', array_fill(0, count($filters['payment_status']), '?'));
                $sql .= " AND po.payment_status IN ({$placeholders})";
                foreach ($filters['payment_status'] as $status) {
                    $params[] = $status;
                }
            } else {
                $sql .= " AND po.payment_status = ?";
                $params[] = $filters['payment_status'];
            }
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND po.po_date >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND po.po_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (po.po_number LIKE ? OR s.company_name LIKE ?)";
            $search_term = '%' . $filters['search'] . '%';
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        // Order by
        $sql .= " ORDER BY po.po_date DESC, po.id DESC";
        
        // Limit
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
    public function OLDgetSupplierSummary() {
        $sql = "SELECT * FROM v_purchase_adnan_supplier_summary ORDER BY balance_payable DESC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    
    public function getSupplierSummary() {
        $sql = "SELECT 
                    s.id,
                    s.company_name,
                    COUNT(po.id) as order_count,
                    COALESCE(SUM(po.total_order_value), 0) as total_value,
                    COALESCE(SUM(po.balance_payable), 0) as balance_due
                FROM suppliers s
                LEFT JOIN purchase_orders_adnan po ON s.id = po.supplier_id
                    AND po.delivery_status != 'closed'   -- ✅ Exclude closed
                    AND po.po_status != 'cancelled'      -- ✅ Exclude cancelled
                WHERE s.status = 'active'
                GROUP BY s.id
                HAVING order_count > 0
                ORDER BY balance_due DESC
                LIMIT 10";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    /**
     * =====================================================
     * FIXED: Get dashboard statistics
     * Calculates expected payable from GRNs (not from DB column)
     * Only counts posted payments (is_posted = 1)
     * =====================================================
     * 
     * @return object Dashboard KPIs
     */
    public function getDashboardStats() {
        // Basic PO counts
        $sql = "SELECT 
            COUNT(*) as total_orders,
            SUM(total_order_value) as total_order_value,
            SUM(CASE WHEN delivery_status = 'completed' THEN 1 ELSE 0 END) as completed_deliveries,
            SUM(CASE WHEN delivery_status = 'closed' THEN 1 ELSE 0 END) as closed_deals,
            SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as completed_payments
        FROM purchase_orders_adnan
        WHERE delivery_status != 'closed' 
        AND po_status != 'cancelled'";
        
        $stmt = $this->db->query($sql);
        $stats = $stmt->fetch(PDO::FETCH_OBJ);
        
        // Get total paid (only posted payments)
        $paid_sql = "SELECT COALESCE(SUM(amount_paid), 0) as total_paid 
                     FROM purchase_payments_adnan
                     WHERE is_posted = 1";
        $paid_stmt = $this->db->query($paid_sql);
        $paid_result = $paid_stmt->fetch(PDO::FETCH_OBJ);
        $stats->total_paid = $paid_result->total_paid;
        
        // Get advance payments (only posted)
        $advance_sql = "SELECT COALESCE(SUM(amount_paid), 0) as total_advance 
                        FROM purchase_payments_adnan 
                        WHERE payment_type = 'advance'
                        AND is_posted = 1";
        $advance_stmt = $this->db->query($advance_sql);
        $advance_result = $advance_stmt->fetch(PDO::FETCH_OBJ);
        $stats->total_advance = $advance_result->total_advance;
        
        // Calculate actual expected payable (based on GRN expected quantities)
        // THIS IS THE CORRECT METHOD - payment based on expected, not received
        $expected_sql = "SELECT 
                            COALESCE(SUM(grn.expected_quantity * po.unit_price_per_kg), 0) as expected_payable
                         FROM goods_received_adnan grn
                         INNER JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
                         WHERE grn.grn_status != 'cancelled' AND po.po_status != 'cancelled'";
        $expected_stmt = $this->db->query($expected_sql);
        $expected_result = $expected_stmt->fetch(PDO::FETCH_OBJ);
        $stats->expected_payable = $expected_result->expected_payable;
        
        // Calculate actual balance due (expected payable - total paid)
        $stats->actual_balance_due = $stats->expected_payable - $stats->total_paid;
        
        // Ensure non-negative (if overpaid, balance due is 0)
        if ($stats->actual_balance_due < 0) {
            $stats->actual_balance_due = 0;
        }
        
        // Regular payments (non-advance)
        $stats->regular_payments = $stats->total_paid - $stats->total_advance;
        
        return $stats;
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
     * Get all suppliers with complete information
     * 
     * @return array Supplier list with all required fields
     */
    public function getAllSuppliers() {
        $sql = "SELECT 
                    id, 
                    company_name as name,
                    supplier_code,
                    contact_person,
                    phone,
                    mobile,
                    email,
                    city,
                    status,
                    current_balance,
                    credit_limit
                FROM suppliers 
                WHERE status = 'active' 
                ORDER BY company_name ASC";
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
        AND delivery_status != 'closed' 
        GROUP BY wheat_origin";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
}
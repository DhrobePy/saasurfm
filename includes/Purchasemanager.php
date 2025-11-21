<?php
/**
 * Purchase Manager Class
 * Handles all purchase-related operations with proper double-entry accounting
 * 
 * Ujjal Flour Mills - SaaS Platform
 */

class PurchaseManager {
    private $db;
    private $user_id;

    public function __construct($db, $user_id = null) {
        $this->db = $db;
        $this->user_id = $user_id ?? $_SESSION['user_id'] ?? null;
    }

    // ========================================
    // SUPPLIER MANAGEMENT
    // ========================================

    /**
     * Create a new supplier
     */
    public function createSupplier($data) {
        try {
            $this->db->beginTransaction();

            // Generate supplier code if not provided
            if (empty($data['supplier_code'])) {
                $data['supplier_code'] = $this->generateSupplierCode();
            }

            $sql = "INSERT INTO suppliers (
                supplier_code, company_name, contact_person, email, phone, mobile, 
                address, city, country, tax_id, payment_terms, credit_limit, 
                opening_balance, current_balance, supplier_type, status, notes, 
                created_by_user_id
            ) VALUES (
                :supplier_code, :company_name, :contact_person, :email, :phone, :mobile,
                :address, :city, :country, :tax_id, :payment_terms, :credit_limit,
                :opening_balance, :opening_balance, :supplier_type, :status, :notes,
                :created_by_user_id
            )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'supplier_code' => $data['supplier_code'],
                'company_name' => $data['company_name'],
                'contact_person' => $data['contact_person'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'mobile' => $data['mobile'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'country' => $data['country'] ?? 'Bangladesh',
                'tax_id' => $data['tax_id'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? 'Net 30',
                'credit_limit' => $data['credit_limit'] ?? 0,
                'opening_balance' => $data['opening_balance'] ?? 0,
                'supplier_type' => $data['supplier_type'] ?? 'local',
                'status' => $data['status'] ?? 'active',
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $this->user_id
            ]);

            $supplier_id = $this->db->lastInsertId();

            // Create opening balance journal entry if opening balance > 0
            if (!empty($data['opening_balance']) && $data['opening_balance'] > 0) {
                $this->createOpeningBalanceEntry($supplier_id, $data['opening_balance']);
            }

            $this->db->commit();
            return ['success' => true, 'supplier_id' => $supplier_id];

        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get supplier details
     */
    public function getSupplier($supplier_id) {
        $sql = "SELECT * FROM suppliers WHERE id = :supplier_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['supplier_id' => $supplier_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get all suppliers
     */
    public function getAllSuppliers($status = 'active') {
        $sql = "SELECT * FROM suppliers";
        if ($status) {
            $sql .= " WHERE status = :status";
        }
        $sql .= " ORDER BY company_name ASC";
        
        $stmt = $this->db->prepare($sql);
        if ($status) {
            $stmt->execute(['status' => $status]);
        } else {
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update supplier
     */
    public function updateSupplier($supplier_id, $data) {
        try {
            $sql = "UPDATE suppliers SET 
                company_name = :company_name,
                contact_person = :contact_person,
                email = :email,
                phone = :phone,
                mobile = :mobile,
                address = :address,
                city = :city,
                country = :country,
                tax_id = :tax_id,
                payment_terms = :payment_terms,
                credit_limit = :credit_limit,
                supplier_type = :supplier_type,
                status = :status,
                notes = :notes
            WHERE id = :supplier_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'supplier_id' => $supplier_id,
                'company_name' => $data['company_name'],
                'contact_person' => $data['contact_person'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'mobile' => $data['mobile'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'country' => $data['country'] ?? 'Bangladesh',
                'tax_id' => $data['tax_id'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? 'Net 30',
                'credit_limit' => $data['credit_limit'] ?? 0,
                'supplier_type' => $data['supplier_type'] ?? 'local',
                'status' => $data['status'] ?? 'active',
                'notes' => $data['notes'] ?? null
            ]);

            return ['success' => true];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ========================================
    // PURCHASE ORDER MANAGEMENT
    // ========================================

    /**
     * Create purchase order
     */
    public function createPurchaseOrder($data, $items) {
        try {
            $this->db->beginTransaction();

            // Generate PO number
            $po_number = $this->generatePONumber($data['po_date']);

            // Calculate totals
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += $item['line_total'];
            }

            $tax_amount = $data['tax_amount'] ?? 0;
            $discount_amount = $data['discount_amount'] ?? 0;
            $shipping_cost = $data['shipping_cost'] ?? 0;
            $other_charges = $data['other_charges'] ?? 0;
            
            $total_amount = $subtotal + $tax_amount + $shipping_cost + $other_charges - $discount_amount;

            // Insert purchase order
            $sql = "INSERT INTO purchase_orders (
                po_number, supplier_id, branch_id, po_date, expected_delivery_date,
                status, payment_terms, subtotal, tax_amount, discount_amount,
                shipping_cost, other_charges, total_amount, notes, terms_conditions,
                created_by_user_id
            ) VALUES (
                :po_number, :supplier_id, :branch_id, :po_date, :expected_delivery_date,
                :status, :payment_terms, :subtotal, :tax_amount, :discount_amount,
                :shipping_cost, :other_charges, :total_amount, :notes, :terms_conditions,
                :created_by_user_id
            )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'po_number' => $po_number,
                'supplier_id' => $data['supplier_id'],
                'branch_id' => $data['branch_id'],
                'po_date' => $data['po_date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'payment_terms' => $data['payment_terms'] ?? 'Net 30',
                'subtotal' => $subtotal,
                'tax_amount' => $tax_amount,
                'discount_amount' => $discount_amount,
                'shipping_cost' => $shipping_cost,
                'other_charges' => $other_charges,
                'total_amount' => $total_amount,
                'notes' => $data['notes'] ?? null,
                'terms_conditions' => $data['terms_conditions'] ?? null,
                'created_by_user_id' => $this->user_id
            ]);

            $po_id = $this->db->lastInsertId();

            // Insert items
            foreach ($items as $item) {
                $this->addPurchaseOrderItem($po_id, $item);
            }

            $this->db->commit();
            return ['success' => true, 'po_id' => $po_id, 'po_number' => $po_number];

        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Add item to purchase order
     */
    private function addPurchaseOrderItem($po_id, $item) {
        $sql = "INSERT INTO purchase_order_items (
            purchase_order_id, variant_id, item_type, item_name, item_code,
            unit_of_measure, quantity, unit_price, discount_percentage,
            discount_amount, tax_percentage, tax_amount, line_total, notes
        ) VALUES (
            :po_id, :variant_id, :item_type, :item_name, :item_code,
            :unit_of_measure, :quantity, :unit_price, :discount_percentage,
            :discount_amount, :tax_percentage, :tax_amount, :line_total, :notes
        )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'po_id' => $po_id,
            'variant_id' => $item['variant_id'] ?? null,
            'item_type' => $item['item_type'] ?? 'raw_material',
            'item_name' => $item['item_name'],
            'item_code' => $item['item_code'] ?? null,
            'unit_of_measure' => $item['unit_of_measure'] ?? 'kg',
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'discount_percentage' => $item['discount_percentage'] ?? 0,
            'discount_amount' => $item['discount_amount'] ?? 0,
            'tax_percentage' => $item['tax_percentage'] ?? 0,
            'tax_amount' => $item['tax_amount'] ?? 0,
            'line_total' => $item['line_total'],
            'notes' => $item['notes'] ?? null
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Get purchase order with items
     */
    public function getPurchaseOrder($po_id) {
        // Get PO header
        $sql = "SELECT po.*, s.company_name as supplier_name, b.name as branch_name
                FROM purchase_orders po
                LEFT JOIN suppliers s ON po.supplier_id = s.id
                LEFT JOIN branches b ON po.branch_id = b.id
                WHERE po.id = :po_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['po_id' => $po_id]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($po) {
            // Get items
            $sql = "SELECT * FROM purchase_order_items WHERE purchase_order_id = :po_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['po_id' => $po_id]);
            $po['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $po;
    }

    /**
     * Get all purchase orders
     */
    public function getAllPurchaseOrders($filters = []) {
        $sql = "SELECT po.*, s.company_name as supplier_name, b.name as branch_name
                FROM purchase_orders po
                LEFT JOIN suppliers s ON po.supplier_id = s.id
                LEFT JOIN branches b ON po.branch_id = b.id
                WHERE 1=1";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND po.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['branch_id'])) {
            $sql .= " AND po.branch_id = :branch_id";
            $params['branch_id'] = $filters['branch_id'];
        }

        if (!empty($filters['supplier_id'])) {
            $sql .= " AND po.supplier_id = :supplier_id";
            $params['supplier_id'] = $filters['supplier_id'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND po.po_date >= :from_date";
            $params['from_date'] = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND po.po_date <= :to_date";
            $params['to_date'] = $filters['to_date'];
        }

        $sql .= " ORDER BY po.po_date DESC, po.id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update purchase order status
     */
    public function updatePOStatus($po_id, $status, $approved_by = null) {
        $sql = "UPDATE purchase_orders SET status = :status";
        
        if ($approved_by && $status === 'approved') {
            $sql .= ", approved_by_user_id = :approved_by, approved_at = NOW()";
        }
        
        $sql .= " WHERE id = :po_id";

        $stmt = $this->db->prepare($sql);
        $params = ['po_id' => $po_id, 'status' => $status];
        
        if ($approved_by && $status === 'approved') {
            $params['approved_by'] = $approved_by;
        }

        $stmt->execute($params);
        return ['success' => true];
    }

    // ========================================
    // GOODS RECEIVED NOTE (GRN) MANAGEMENT
    // ========================================

    /**
     * Create GRN
     */
    public function createGRN($data, $items) {
        try {
            $this->db->beginTransaction();

            // Generate GRN number
            $grn_number = $this->generateGRNNumber($data['received_date']);

            // Insert GRN header
            $sql = "INSERT INTO goods_received_notes (
                grn_number, purchase_order_id, supplier_id, branch_id, 
                received_date, received_by_user_id, supplier_invoice_number,
                supplier_invoice_date, vehicle_number, driver_name, status,
                inspection_notes, quality_status, total_items, notes,
                created_by_user_id
            ) VALUES (
                :grn_number, :purchase_order_id, :supplier_id, :branch_id,
                :received_date, :received_by_user_id, :supplier_invoice_number,
                :supplier_invoice_date, :vehicle_number, :driver_name, :status,
                :inspection_notes, :quality_status, :total_items, :notes,
                :created_by_user_id
            )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'grn_number' => $grn_number,
                'purchase_order_id' => $data['purchase_order_id'],
                'supplier_id' => $data['supplier_id'],
                'branch_id' => $data['branch_id'],
                'received_date' => $data['received_date'],
                'received_by_user_id' => $data['received_by_user_id'] ?? $this->user_id,
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
                'supplier_invoice_date' => $data['supplier_invoice_date'] ?? null,
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'status' => $data['status'] ?? 'received',
                'inspection_notes' => $data['inspection_notes'] ?? null,
                'quality_status' => $data['quality_status'] ?? 'passed',
                'total_items' => count($items),
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $this->user_id
            ]);

            $grn_id = $this->db->lastInsertId();

            // Insert GRN items and update inventory
            foreach ($items as $item) {
                $this->addGRNItem($grn_id, $item);
                
                // Update PO item received quantity
                $this->updatePOItemReceivedQuantity($item['po_item_id'], $item['received_quantity']);

                // Update inventory if finished goods
                if (!empty($item['variant_id']) && $item['item_type'] === 'finished_goods') {
                    $this->updateInventory($item['variant_id'], $data['branch_id'], $item['accepted_quantity']);
                }
            }

            // Update PO status
            $this->checkAndUpdatePOReceiveStatus($data['purchase_order_id']);

            $this->db->commit();
            return ['success' => true, 'grn_id' => $grn_id, 'grn_number' => $grn_number];

        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Add GRN item
     */
    private function addGRNItem($grn_id, $item) {
        $sql = "INSERT INTO goods_received_items (
            grn_id, po_item_id, variant_id, item_name, item_type,
            ordered_quantity, received_quantity, accepted_quantity,
            rejected_quantity, unit_of_measure, unit_price, line_total,
            batch_number, expiry_date, storage_location, condition_status, notes
        ) VALUES (
            :grn_id, :po_item_id, :variant_id, :item_name, :item_type,
            :ordered_quantity, :received_quantity, :accepted_quantity,
            :rejected_quantity, :unit_of_measure, :unit_price, :line_total,
            :batch_number, :expiry_date, :storage_location, :condition_status, :notes
        )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'grn_id' => $grn_id,
            'po_item_id' => $item['po_item_id'],
            'variant_id' => $item['variant_id'] ?? null,
            'item_name' => $item['item_name'],
            'item_type' => $item['item_type'] ?? 'raw_material',
            'ordered_quantity' => $item['ordered_quantity'],
            'received_quantity' => $item['received_quantity'],
            'accepted_quantity' => $item['accepted_quantity'],
            'rejected_quantity' => $item['rejected_quantity'] ?? 0,
            'unit_of_measure' => $item['unit_of_measure'] ?? 'kg',
            'unit_price' => $item['unit_price'],
            'line_total' => $item['line_total'],
            'batch_number' => $item['batch_number'] ?? null,
            'expiry_date' => $item['expiry_date'] ?? null,
            'storage_location' => $item['storage_location'] ?? null,
            'condition_status' => $item['condition_status'] ?? 'good',
            'notes' => $item['notes'] ?? null
        ]);
    }

    /**
     * Update PO item received quantity
     */
    private function updatePOItemReceivedQuantity($po_item_id, $quantity) {
        $sql = "UPDATE purchase_order_items 
                SET received_quantity = received_quantity + :quantity
                WHERE id = :po_item_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'po_item_id' => $po_item_id,
            'quantity' => $quantity
        ]);
    }

    /**
     * Check and update PO receive status
     */
    private function checkAndUpdatePOReceiveStatus($po_id) {
        // Check if all items are received
        $sql = "SELECT 
                    SUM(quantity) as total_ordered,
                    SUM(received_quantity) as total_received
                FROM purchase_order_items
                WHERE purchase_order_id = :po_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['po_id' => $po_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['total_received'] >= $result['total_ordered']) {
            $status = 'received';
        } else if ($result['total_received'] > 0) {
            $status = 'partially_received';
        } else {
            $status = 'ordered';
        }

        $sql = "UPDATE purchase_orders SET status = :status WHERE id = :po_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['po_id' => $po_id, 'status' => $status]);
    }

    /**
     * Get GRN with items
     */
    public function getGRN($grn_id) {
        $sql = "SELECT g.*, s.company_name as supplier_name, b.name as branch_name,
                       po.po_number
                FROM goods_received_notes g
                LEFT JOIN suppliers s ON g.supplier_id = s.id
                LEFT JOIN branches b ON g.branch_id = b.id
                LEFT JOIN purchase_orders po ON g.purchase_order_id = po.id
                WHERE g.id = :grn_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['grn_id' => $grn_id]);
        $grn = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($grn) {
            $sql = "SELECT * FROM goods_received_items WHERE grn_id = :grn_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['grn_id' => $grn_id]);
            $grn['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $grn;
    }

    // ========================================
    // PURCHASE INVOICE MANAGEMENT WITH DOUBLE-ENTRY ACCOUNTING
    // ========================================

    /**
     * Create purchase invoice with journal entries
     */
    public function createPurchaseInvoice($data, $items) {
        try {
            $this->db->beginTransaction();

            // Generate invoice number
            $invoice_number = $this->generateInvoiceNumber($data['invoice_date']);

            // Calculate totals
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += $item['line_total'];
            }

            $tax_amount = $data['tax_amount'] ?? 0;
            $discount_amount = $data['discount_amount'] ?? 0;
            $shipping_cost = $data['shipping_cost'] ?? 0;
            $other_charges = $data['other_charges'] ?? 0;
            
            $total_amount = $subtotal + $tax_amount + $shipping_cost + $other_charges - $discount_amount;

            // Calculate due date
            $due_date = $data['due_date'] ?? null;
            if (!$due_date && !empty($data['payment_terms'])) {
                $days = (int) filter_var($data['payment_terms'], FILTER_SANITIZE_NUMBER_INT);
                $due_date = date('Y-m-d', strtotime($data['invoice_date'] . " +$days days"));
            }

            // Insert purchase invoice
            $sql = "INSERT INTO purchase_invoices (
                invoice_number, supplier_invoice_number, purchase_order_id, grn_id,
                supplier_id, branch_id, invoice_date, due_date, invoice_type,
                subtotal, tax_amount, discount_amount, shipping_cost, other_charges,
                total_amount, balance_due, payment_status, status, notes,
                created_by_user_id
            ) VALUES (
                :invoice_number, :supplier_invoice_number, :purchase_order_id, :grn_id,
                :supplier_id, :branch_id, :invoice_date, :due_date, :invoice_type,
                :subtotal, :tax_amount, :discount_amount, :shipping_cost, :other_charges,
                :total_amount, :total_amount, :payment_status, :status, :notes,
                :created_by_user_id
            )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'invoice_number' => $invoice_number,
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'grn_id' => $data['grn_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'branch_id' => $data['branch_id'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $due_date,
                'invoice_type' => $data['invoice_type'] ?? 'goods',
                'subtotal' => $subtotal,
                'tax_amount' => $tax_amount,
                'discount_amount' => $discount_amount,
                'shipping_cost' => $shipping_cost,
                'other_charges' => $other_charges,
                'total_amount' => $total_amount,
                'payment_status' => 'unpaid',
                'status' => 'posted',
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $this->user_id
            ]);

            $invoice_id = $this->db->lastInsertId();

            // Insert items
            foreach ($items as $item) {
                $this->addPurchaseInvoiceItem($invoice_id, $item);
            }

            // Create journal entry (Double-entry accounting)
            $journal_entry_id = $this->createPurchaseJournalEntry(
                $invoice_id,
                $data['invoice_date'],
                $data['supplier_id'],
                $items,
                $total_amount,
                $discount_amount,
                $shipping_cost
            );

            // Update invoice with journal entry ID
            $sql = "UPDATE purchase_invoices SET journal_entry_id = :journal_entry_id WHERE id = :invoice_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['journal_entry_id' => $journal_entry_id, 'invoice_id' => $invoice_id]);

            // Update supplier ledger
            $this->updateSupplierLedger(
                $data['supplier_id'],
                $data['invoice_date'],
                'purchase',
                'PurchaseInvoice',
                $invoice_id,
                $invoice_number,
                0, // debit
                $total_amount, // credit (increases liability)
                $data['branch_id']
            );

            // Update supplier current balance
            $this->updateSupplierBalance($data['supplier_id'], $total_amount, 'increase');

            $this->db->commit();
            return ['success' => true, 'invoice_id' => $invoice_id, 'invoice_number' => $invoice_number];

        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Add purchase invoice item
     */
    private function addPurchaseInvoiceItem($invoice_id, $item) {
        // Determine account based on item type
        $account_id = $this->getExpenseAccountForItemType($item['item_type']);

        $sql = "INSERT INTO purchase_invoice_items (
            purchase_invoice_id, po_item_id, grn_item_id, variant_id,
            item_type, item_name, item_code, quantity, unit_of_measure,
            unit_price, discount_percentage, discount_amount, tax_percentage,
            tax_amount, line_total, account_id, notes
        ) VALUES (
            :invoice_id, :po_item_id, :grn_item_id, :variant_id,
            :item_type, :item_name, :item_code, :quantity, :unit_of_measure,
            :unit_price, :discount_percentage, :discount_amount, :tax_percentage,
            :tax_amount, :line_total, :account_id, :notes
        )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'invoice_id' => $invoice_id,
            'po_item_id' => $item['po_item_id'] ?? null,
            'grn_item_id' => $item['grn_item_id'] ?? null,
            'variant_id' => $item['variant_id'] ?? null,
            'item_type' => $item['item_type'] ?? 'raw_material',
            'item_name' => $item['item_name'],
            'item_code' => $item['item_code'] ?? null,
            'quantity' => $item['quantity'],
            'unit_of_measure' => $item['unit_of_measure'] ?? 'kg',
            'unit_price' => $item['unit_price'],
            'discount_percentage' => $item['discount_percentage'] ?? 0,
            'discount_amount' => $item['discount_amount'] ?? 0,
            'tax_percentage' => $item['tax_percentage'] ?? 0,
            'tax_amount' => $item['tax_amount'] ?? 0,
            'line_total' => $item['line_total'],
            'account_id' => $account_id,
            'notes' => $item['notes'] ?? null
        ]);
    }

    /**
     * Create journal entry for purchase invoice (DOUBLE-ENTRY ACCOUNTING)
     * 
     * Standard entry:
     * Dr. Inventory/Expense Account (Asset/Expense increases)
     * Dr. Freight In (if applicable)
     *    Cr. Accounts Payable (Liability increases)
     *    Cr. Purchase Discounts (if applicable - reduces expense)
     */
    private function createPurchaseJournalEntry($invoice_id, $invoice_date, $supplier_id, $items, $total_amount, $discount_amount, $shipping_cost) {
        // Get supplier name
        $supplier = $this->getSupplier($supplier_id);
        $supplier_name = $supplier['company_name'] ?? 'Supplier';

        // Get invoice number
        $sql = "SELECT invoice_number FROM purchase_invoices WHERE id = :invoice_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['invoice_id' => $invoice_id]);
        $invoice_number = $stmt->fetchColumn();

        // Create journal entry
        $sql = "INSERT INTO journal_entries (
            transaction_date, description, related_document_id, related_document_type,
            created_by_user_id
        ) VALUES (
            :transaction_date, :description, :related_document_id, :related_document_type,
            :created_by_user_id
        )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'transaction_date' => $invoice_date,
            'description' => "Purchase Invoice #{$invoice_number} from {$supplier_name}",
            'related_document_id' => $invoice_id,
            'related_document_type' => 'PurchaseInvoice',
            'created_by_user_id' => $this->user_id
        ]);

        $journal_entry_id = $this->db->lastInsertId();

        // Get Accounts Payable account
        $ap_account_id = $this->getAccountByName('Accounts Payable');
        
        // Get Purchase Discounts account
        $discount_account_id = $this->getAccountByName('Purchase Discounts');
        
        // Get Freight In account
        $freight_account_id = $this->getAccountByName('Freight In');

        // Debit entries: Inventory/Expense accounts for each item
        foreach ($items as $item) {
            $account_id = $this->getExpenseAccountForItemType($item['item_type']);
            
            $this->createTransactionLine(
                $journal_entry_id,
                $account_id,
                $item['line_total'], // debit
                0, // credit
                "Purchase: {$item['item_name']}"
            );
        }

        // Debit: Freight In (if applicable)
        if ($shipping_cost > 0) {
            $this->createTransactionLine(
                $journal_entry_id,
                $freight_account_id,
                $shipping_cost,
                0,
                'Shipping/Freight charges'
            );
        }

        // Credit: Purchase Discounts (if applicable - reduces cost)
        if ($discount_amount > 0) {
            $this->createTransactionLine(
                $journal_entry_id,
                $discount_account_id,
                0,
                $discount_amount,
                'Purchase discount received'
            );
        }

        // Credit: Accounts Payable (total amount owed to supplier)
        $this->createTransactionLine(
            $journal_entry_id,
            $ap_account_id,
            0, // debit
            $total_amount, // credit (liability increases)
            "Amount owed to {$supplier_name}"
        );

        return $journal_entry_id;
    }

    /**
     * Get expense account based on item type
     */
    private function getExpenseAccountForItemType($item_type) {
        switch ($item_type) {
            case 'raw_material':
                return $this->getAccountByName('Raw Material Purchases');
            case 'packaging':
                return $this->getAccountByName('Packaging Materials');
            case 'finished_goods':
                return $this->getAccountByName('Inventory - Raw Materials');
            default:
                return $this->getAccountByName('Raw Material Purchases');
        }
    }

    /**
     * Get account by name
     */
    private function getAccountByName($name) {
        $sql = "SELECT id FROM chart_of_accounts WHERE name = :name LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['name' => $name]);
        return $stmt->fetchColumn();
    }

    /**
     * Create transaction line
     */
    private function createTransactionLine($journal_entry_id, $account_id, $debit, $credit, $description) {
        $sql = "INSERT INTO transaction_lines (
            journal_entry_id, account_id, debit_amount, credit_amount, description
        ) VALUES (
            :journal_entry_id, :account_id, :debit_amount, :credit_amount, :description
        )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'journal_entry_id' => $journal_entry_id,
            'account_id' => $account_id,
            'debit_amount' => $debit,
            'credit_amount' => $credit,
            'description' => $description
        ]);
    }

    // ========================================
    // SUPPLIER PAYMENT MANAGEMENT
    // ========================================

    /**
     * Create supplier payment with journal entries
     */
    public function createSupplierPayment($data, $invoice_allocations) {
        try {
            $this->db->beginTransaction();

            // Generate payment number
            $payment_number = $this->generatePaymentNumber($data['payment_date']);

            // Insert payment
            $sql = "INSERT INTO supplier_payments (
                payment_number, supplier_id, branch_id, payment_date, payment_method,
                payment_account_id, amount, reference_number, notes, status,
                created_by_user_id
            ) VALUES (
                :payment_number, :supplier_id, :branch_id, :payment_date, :payment_method,
                :payment_account_id, :amount, :reference_number, :notes, :status,
                :created_by_user_id
            )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'payment_number' => $payment_number,
                'supplier_id' => $data['supplier_id'],
                'branch_id' => $data['branch_id'],
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'],
                'payment_account_id' => $data['payment_account_id'],
                'amount' => $data['amount'],
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => $data['status'] ?? 'cleared',
                'created_by_user_id' => $this->user_id
            ]);

            $payment_id = $this->db->lastInsertId();

            // Create journal entry
            $journal_entry_id = $this->createPaymentJournalEntry(
                $payment_id,
                $data['payment_date'],
                $data['supplier_id'],
                $data['payment_account_id'],
                $data['amount']
            );

            // Update payment with journal entry ID
            $sql = "UPDATE supplier_payments SET journal_entry_id = :journal_entry_id WHERE id = :payment_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['journal_entry_id' => $journal_entry_id, 'payment_id' => $payment_id]);

            // Allocate payment to invoices
            foreach ($invoice_allocations as $allocation) {
                $this->allocatePaymentToInvoice($payment_id, $allocation['invoice_id'], $allocation['amount']);
            }

            // Update supplier ledger
            $this->updateSupplierLedger(
                $data['supplier_id'],
                $data['payment_date'],
                'payment',
                'SupplierPayment',
                $payment_id,
                $payment_number,
                $data['amount'], // debit (reduces liability)
                0, // credit
                $data['branch_id']
            );

            // Update supplier balance
            $this->updateSupplierBalance($data['supplier_id'], $data['amount'], 'decrease');

            $this->db->commit();
            return ['success' => true, 'payment_id' => $payment_id, 'payment_number' => $payment_number];

        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create journal entry for supplier payment
     * 
     * Dr. Accounts Payable (Liability decreases)
     *    Cr. Bank/Cash Account (Asset decreases)
     */
    private function createPaymentJournalEntry($payment_id, $payment_date, $supplier_id, $payment_account_id, $amount) {
        // Get supplier name
        $supplier = $this->getSupplier($supplier_id);
        $supplier_name = $supplier['company_name'] ?? 'Supplier';

        // Get payment number
        $sql = "SELECT payment_number FROM supplier_payments WHERE id = :payment_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['payment_id' => $payment_id]);
        $payment_number = $stmt->fetchColumn();

        // Create journal entry
        $sql = "INSERT INTO journal_entries (
            transaction_date, description, related_document_id, related_document_type,
            created_by_user_id
        ) VALUES (
            :transaction_date, :description, :related_document_id, :related_document_type,
            :created_by_user_id
        )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'transaction_date' => $payment_date,
            'description' => "Payment #{$payment_number} to {$supplier_name}",
            'related_document_id' => $payment_id,
            'related_document_type' => 'SupplierPayment',
            'created_by_user_id' => $this->user_id
        ]);

        $journal_entry_id = $this->db->lastInsertId();

        // Get Accounts Payable account
        $ap_account_id = $this->getAccountByName('Accounts Payable');

        // Debit: Accounts Payable
        $this->createTransactionLine(
            $journal_entry_id,
            $ap_account_id,
            $amount,
            0,
            "Payment to {$supplier_name}"
        );

        // Credit: Bank/Cash Account
        $this->createTransactionLine(
            $journal_entry_id,
            $payment_account_id,
            0,
            $amount,
            "Payment made to {$supplier_name}"
        );

        return $journal_entry_id;
    }

    /**
     * Allocate payment to invoice
     */
    private function allocatePaymentToInvoice($payment_id, $invoice_id, $amount) {
        // Insert allocation
        $sql = "INSERT INTO supplier_payment_allocations (
            supplier_payment_id, purchase_invoice_id, allocated_amount
        ) VALUES (
            :payment_id, :invoice_id, :amount
        )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'payment_id' => $payment_id,
            'invoice_id' => $invoice_id,
            'amount' => $amount
        ]);

        // Update invoice paid amount and status
        $sql = "UPDATE purchase_invoices 
                SET paid_amount = paid_amount + :amount,
                    balance_due = balance_due - :amount
                WHERE id = :invoice_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'invoice_id' => $invoice_id,
            'amount' => $amount
        ]);

        // Update payment status
        $sql = "UPDATE purchase_invoices 
                SET payment_status = CASE 
                    WHEN balance_due <= 0 THEN 'paid'
                    WHEN paid_amount > 0 THEN 'partially_paid'
                    ELSE 'unpaid'
                END
                WHERE id = :invoice_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['invoice_id' => $invoice_id]);
    }

    // ========================================
    // SUPPLIER LEDGER MANAGEMENT
    // ========================================

    /**
     * Update supplier ledger
     */
    private function updateSupplierLedger($supplier_id, $transaction_date, $transaction_type, $reference_type, $reference_id, $reference_number, $debit, $credit, $branch_id = null) {
        // Get current balance
        $sql = "SELECT balance FROM supplier_ledger 
                WHERE supplier_id = :supplier_id 
                ORDER BY id DESC LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['supplier_id' => $supplier_id]);
        $current_balance = $stmt->fetchColumn() ?: 0;

        // Calculate new balance (credit increases liability, debit decreases)
        $new_balance = $current_balance + $credit - $debit;

        // Insert ledger entry
        $sql = "INSERT INTO supplier_ledger (
            supplier_id, transaction_date, transaction_type, reference_type,
            reference_id, reference_number, debit_amount, credit_amount, balance,
            description, branch_id, created_by_user_id
        ) VALUES (
            :supplier_id, :transaction_date, :transaction_type, :reference_type,
            :reference_id, :reference_number, :debit_amount, :credit_amount, :balance,
            :description, :branch_id, :created_by_user_id
        )";

        $description = $this->getLedgerDescription($transaction_type, $reference_number);

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'supplier_id' => $supplier_id,
            'transaction_date' => $transaction_date,
            'transaction_type' => $transaction_type,
            'reference_type' => $reference_type,
            'reference_id' => $reference_id,
            'reference_number' => $reference_number,
            'debit_amount' => $debit,
            'credit_amount' => $credit,
            'balance' => $new_balance,
            'description' => $description,
            'branch_id' => $branch_id,
            'created_by_user_id' => $this->user_id
        ]);
    }

    /**
     * Get ledger description
     */
    private function getLedgerDescription($transaction_type, $reference_number) {
        switch ($transaction_type) {
            case 'opening_balance':
                return 'Opening Balance';
            case 'purchase':
                return "Purchase Invoice: {$reference_number}";
            case 'payment':
                return "Payment: {$reference_number}";
            case 'debit_note':
                return "Debit Note: {$reference_number}";
            case 'credit_note':
                return "Credit Note: {$reference_number}";
            case 'adjustment':
                return "Balance Adjustment: {$reference_number}";
            default:
                return $reference_number;
        }
    }

    /**
     * Update supplier balance
     */
    private function updateSupplierBalance($supplier_id, $amount, $operation = 'increase') {
        if ($operation === 'increase') {
            $sql = "UPDATE suppliers SET current_balance = current_balance + :amount WHERE id = :supplier_id";
        } else {
            $sql = "UPDATE suppliers SET current_balance = current_balance - :amount WHERE id = :supplier_id";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'supplier_id' => $supplier_id,
            'amount' => $amount
        ]);
    }

    /**
     * Get supplier ledger
     */
    public function getSupplierLedger($supplier_id, $from_date = null, $to_date = null) {
        $sql = "SELECT * FROM supplier_ledger WHERE supplier_id = :supplier_id";
        $params = ['supplier_id' => $supplier_id];

        if ($from_date) {
            $sql .= " AND transaction_date >= :from_date";
            $params['from_date'] = $from_date;
        }

        if ($to_date) {
            $sql .= " AND transaction_date <= :to_date";
            $params['to_date'] = $to_date;
        }

        $sql .= " ORDER BY transaction_date ASC, id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ========================================
    // HELPER FUNCTIONS
    // ========================================

    /**
     * Generate supplier code
     */
    private function generateSupplierCode() {
        $sql = "SELECT MAX(CAST(SUBSTRING(supplier_code, 5) AS UNSIGNED)) as max_num 
                FROM suppliers 
                WHERE supplier_code LIKE 'SUP-%'";
        $stmt = $this->db->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $next_num = ($result['max_num'] ?? 0) + 1;
        return 'SUP-' . str_pad($next_num, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Generate PO number
     */
    private function generatePONumber($date) {
        $date_str = date('Ymd', strtotime($date));
        $sql = "SELECT COUNT(*) as count FROM purchase_orders WHERE po_number LIKE :pattern";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['pattern' => "PO-{$date_str}-%"]);
        $count = $stmt->fetchColumn();
        return "PO-{$date_str}-" . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generate GRN number
     */
    private function generateGRNNumber($date) {
        $date_str = date('Ymd', strtotime($date));
        $sql = "SELECT COUNT(*) as count FROM goods_received_notes WHERE grn_number LIKE :pattern";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['pattern' => "GRN-{$date_str}-%"]);
        $count = $stmt->fetchColumn();
        return "GRN-{$date_str}-" . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generate invoice number
     */
    private function generateInvoiceNumber($date) {
        $date_str = date('Ymd', strtotime($date));
        $sql = "SELECT COUNT(*) as count FROM purchase_invoices WHERE invoice_number LIKE :pattern";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['pattern' => "PI-{$date_str}-%"]);
        $count = $stmt->fetchColumn();
        return "PI-{$date_str}-" . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generate payment number
     */
    private function generatePaymentNumber($date) {
        $date_str = date('Ymd', strtotime($date));
        $sql = "SELECT COUNT(*) as count FROM supplier_payments WHERE payment_number LIKE :pattern";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['pattern' => "SPAY-{$date_str}-%"]);
        $count = $stmt->fetchColumn();
        return "SPAY-{$date_str}-" . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Create opening balance journal entry for supplier
     */
    private function createOpeningBalanceEntry($supplier_id, $amount) {
        $supplier = $this->getSupplier($supplier_id);
        $supplier_name = $supplier['company_name'] ?? 'Supplier';

        // Create journal entry
        $sql = "INSERT INTO journal_entries (
            transaction_date, description, related_document_id, related_document_type,
            created_by_user_id
        ) VALUES (
            CURDATE(), :description, :related_document_id, :related_document_type,
            :created_by_user_id
        )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'description' => "Opening Balance: {$supplier_name}",
            'related_document_id' => $supplier_id,
            'related_document_type' => 'Supplier',
            'created_by_user_id' => $this->user_id
        ]);

        $journal_entry_id = $this->db->lastInsertId();

        // Get accounts
        $opening_balance_equity_id = $this->getAccountByName('Opening Balance Equity');
        $ap_account_id = $this->getAccountByName('Accounts Payable');

        // Debit: Opening Balance Equity
        $this->createTransactionLine(
            $journal_entry_id,
            $opening_balance_equity_id,
            $amount,
            0,
            "Opening balance offset"
        );

        // Credit: Accounts Payable
        $this->createTransactionLine(
            $journal_entry_id,
            $ap_account_id,
            0,
            $amount,
            "Opening balance - {$supplier_name}"
        );

        // Create ledger entry
        $this->updateSupplierLedger(
            $supplier_id,
            date('Y-m-d'),
            'opening_balance',
            'Supplier',
            $supplier_id,
            'Opening Balance',
            0,
            $amount,
            null
        );
    }

    /**
     * Update inventory
     */
    private function updateInventory($variant_id, $branch_id, $quantity) {
        $sql = "INSERT INTO inventory (variant_id, branch_id, quantity, updated_at)
                VALUES (:variant_id, :branch_id, :quantity, NOW())
                ON DUPLICATE KEY UPDATE 
                    quantity = quantity + VALUES(quantity),
                    updated_at = NOW()";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'variant_id' => $variant_id,
            'branch_id' => $branch_id,
            'quantity' => $quantity
        ]);
    }

    // ========================================
    // REPORTING FUNCTIONS
    // ========================================

    /**
     * Get purchase summary report
     */
    public function getPurchaseSummaryReport($from_date, $to_date, $branch_id = null) {
        $sql = "SELECT 
                    DATE(po.po_date) as date,
                    COUNT(DISTINCT po.id) as total_pos,
                    COUNT(DISTINCT pi.id) as total_invoices,
                    SUM(po.total_amount) as po_total,
                    SUM(pi.total_amount) as invoice_total,
                    SUM(pi.paid_amount) as paid_total,
                    SUM(pi.balance_due) as balance_due
                FROM purchase_orders po
                LEFT JOIN purchase_invoices pi ON po.id = pi.purchase_order_id
                WHERE po.po_date BETWEEN :from_date AND :to_date";
        
        $params = ['from_date' => $from_date, 'to_date' => $to_date];

        if ($branch_id) {
            $sql .= " AND po.branch_id = :branch_id";
            $params['branch_id'] = $branch_id;
        }

        $sql .= " GROUP BY DATE(po.po_date) ORDER BY date DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get supplier outstanding report
     */
    public function getSupplierOutstandingReport() {
        $sql = "SELECT 
                    s.id,
                    s.supplier_code,
                    s.company_name,
                    s.current_balance,
                    s.credit_limit,
                    COUNT(DISTINCT pi.id) as total_invoices,
                    SUM(pi.balance_due) as total_outstanding,
                    MIN(pi.due_date) as oldest_due_date
                FROM suppliers s
                LEFT JOIN purchase_invoices pi ON s.id = pi.supplier_id 
                    AND pi.payment_status IN ('unpaid', 'partially_paid')
                WHERE s.status = 'active'
                GROUP BY s.id
                HAVING total_outstanding > 0
                ORDER BY total_outstanding DESC";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get purchase by supplier report
     */
    public function getPurchaseBySupplierReport($from_date, $to_date) {
        $sql = "SELECT 
                    s.id,
                    s.supplier_code,
                    s.company_name,
                    COUNT(DISTINCT po.id) as total_pos,
                    COUNT(DISTINCT pi.id) as total_invoices,
                    SUM(pi.total_amount) as total_purchased,
                    SUM(pi.paid_amount) as total_paid,
                    SUM(pi.balance_due) as balance_outstanding
                FROM suppliers s
                LEFT JOIN purchase_orders po ON s.id = po.supplier_id 
                    AND po.po_date BETWEEN :from_date AND :to_date
                LEFT JOIN purchase_invoices pi ON s.id = pi.supplier_id 
                    AND pi.invoice_date BETWEEN :from_date AND :to_date
                WHERE s.status = 'active'
                GROUP BY s.id
                ORDER BY total_purchased DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['from_date' => $from_date, 'to_date' => $to_date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Export data to CSV
     */
    public function exportToCSV($data, $filename, $headers) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        
        // Write headers
        fputcsv($output, $headers);
        
        // Write data
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }
}
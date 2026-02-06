<?php
/**
 * JournalEntryHelper.php - FIXED for Generated Columns
 * Uses: transaction_lines table
 * Skips: Generated/calculated columns (qty_yet_to_receive, balance_payable)
 */

class JournalEntryHelper {
    
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getPdo();
    }
    
    /**
     * Reverse a journal entry (create reversing entry)
     */
    public function reverseJournalEntry($original_journal_entry_id, $document_type, $document_id, $reason) {
        try {
            // Get original journal entry
            $stmt = $this->db->prepare("
                SELECT * FROM journal_entries 
                WHERE id = ? AND is_reversed = 0
            ");
            $stmt->execute([$original_journal_entry_id]);
            $original_entry = $stmt->fetch(PDO::FETCH_OBJ);
            
            if (!$original_entry) {
                throw new Exception("Original journal entry not found or already reversed");
            }
            
            // Create reversing entry
            $stmt = $this->db->prepare("
                INSERT INTO journal_entries (
                    transaction_date, 
                    description,
                    related_document_type,
                    related_document_id,
                    is_reversed,
                    reverses_entry_id,
                    created_by_user_id,
                    created_at,
                    updated_at
                ) VALUES (
                    CURDATE(),
                    ?,
                    ?,
                    ?,
                    1,
                    ?,
                    ?,
                    NOW(),
                    NOW()
                )
            ");
            
            $stmt->execute([
                $reason,
                $document_type,
                $document_id,
                $original_journal_entry_id,
                getCurrentUser()['id'] ?? 1
            ]);
            
            $reversal_entry_id = $this->db->lastInsertId();
            
            // Get original transaction lines
            $stmt = $this->db->prepare("
                SELECT * FROM transaction_lines 
                WHERE journal_entry_id = ?
            ");
            $stmt->execute([$original_journal_entry_id]);
            $original_lines = $stmt->fetchAll(PDO::FETCH_OBJ);
            
            // Create reversing lines (swap Dr/Cr)
            $stmt = $this->db->prepare("
                INSERT INTO transaction_lines (
                    journal_entry_id,
                    account_id,
                    debit_amount,
                    credit_amount,
                    description
                ) VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($original_lines as $line) {
                $stmt->execute([
                    $reversal_entry_id,
                    $line->account_id,
                    $line->credit_amount,  // Swap: credit becomes debit
                    $line->debit_amount,   // Swap: debit becomes credit
                    "Reversal: " . $line->description
                ]);
            }
            
            // Mark original entry as reversed
            $stmt = $this->db->prepare("
                UPDATE journal_entries 
                SET is_reversed = 1, 
                    reversed_by_entry_id = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$reversal_entry_id, $original_journal_entry_id]);
            
            return $reversal_entry_id;
            
        } catch (Exception $e) {
            error_log("Journal Entry Reversal Failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Check if a journal entry can be reversed
     */
    public function canReverse($journal_entry_id) {
        if (empty($journal_entry_id)) {
            return false;
        }
        
        $stmt = $this->db->prepare("
            SELECT is_reversed FROM journal_entries 
            WHERE id = ?
        ");
        $stmt->execute([$journal_entry_id]);
        $entry = $stmt->fetch(PDO::FETCH_OBJ);
        
        return $entry && $entry->is_reversed == 0;
    }
    
    /**
     * Recalculate PO totals
     * ONLY updates non-generated columns!
     */
    public function recalculatePOTotals($po_id) {
        try {
            // Only update columns that are NOT generated
            // qty_yet_to_receive and balance_payable are auto-calculated
            $stmt = $this->db->prepare("
                UPDATE purchase_orders_adnan po
                SET 
                    total_received_value = (
                        SELECT COALESCE(SUM(total_value), 0) 
                        FROM goods_received_adnan 
                        WHERE purchase_order_id = po.id 
                        AND grn_status != 'cancelled'
                    ),
                    total_paid = (
                        SELECT COALESCE(SUM(amount_paid), 0) 
                        FROM purchase_payments_adnan 
                        WHERE purchase_order_id = po.id 
                        AND is_posted = 1
                    ),
                    updated_at = NOW()
                WHERE po.id = ?
            ");
            
            $stmt->execute([$po_id]);
            return true;
            
        } catch (Exception $e) {
            error_log("PO Totals Recalculation Failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update delivery status
     */
    public function updateDeliveryStatus($po_id) {
        try {
            // Get current values to determine status
            $stmt = $this->db->prepare("
                SELECT 
                    quantity_kg,
                    qty_yet_to_receive
                FROM purchase_orders_adnan 
                WHERE id = ?
            ");
            $stmt->execute([$po_id]);
            $po = $stmt->fetch(PDO::FETCH_OBJ);
            
            if ($po) {
                $status = 'pending';
                if ($po->qty_yet_to_receive <= 0) {
                    $status = 'completed';
                } elseif ($po->qty_yet_to_receive < $po->quantity_kg) {
                    $status = 'partial';
                }
                
                $stmt = $this->db->prepare("
                    UPDATE purchase_orders_adnan 
                    SET delivery_status = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$status, $po_id]);
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Delivery Status Update Failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update payment status
     */
    public function updatePaymentStatus($po_id) {
        try {
            // Get current values to determine status
            $stmt = $this->db->prepare("
                SELECT 
                    total_order_value,
                    balance_payable
                FROM purchase_orders_adnan 
                WHERE id = ?
            ");
            $stmt->execute([$po_id]);
            $po = $stmt->fetch(PDO::FETCH_OBJ);
            
            if ($po) {
                $status = 'unpaid';
                if ($po->balance_payable <= 0) {
                    $status = 'paid';
                } elseif ($po->balance_payable < $po->total_order_value) {
                    $status = 'partial';
                }
                
                $stmt = $this->db->prepare("
                    UPDATE purchase_orders_adnan 
                    SET payment_status = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$status, $po_id]);
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Payment Status Update Failed: " . $e->getMessage());
            throw $e;
        }
    }
}
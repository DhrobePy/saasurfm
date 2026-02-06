<?php
/**
 * Update Payment Handler - Superadmin Only
 * File: /purchase/purchase_adnan_update_payment.php
 * 
 * Table: purchase_payments_adnan
 * Handles payment updates with journal entry reversal
 */

require_once '../core/init.php';
require_once '../core/classes/JournalEntryHelper.php';

// Superadmin only
restrict_access(['Superadmin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = "Invalid request method";
    header('Location: purchase_adnan_list_po.php');
    exit;
}

$payment_id = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;
$purchase_order_id = isset($_POST['purchase_order_id']) ? (int)$_POST['purchase_order_id'] : 0;

if ($payment_id === 0 || $purchase_order_id === 0) {
    $_SESSION['error_message'] = "Invalid Payment or PO ID";
    header('Location: purchase_adnan_list_po.php');
    exit;
}

$db = Database::getInstance()->getPdo();
$journalHelper = new JournalEntryHelper();

try {
    $db->beginTransaction();
    
    // Get existing payment data for audit log
    $stmt = $db->prepare("SELECT * FROM purchase_payments_adnan WHERE id = ?");
    $stmt->execute([$payment_id]);
    $old_payment = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$old_payment) {
        throw new Exception("Payment not found");
    }
    
    // Get bank name if bank/cheque payment
    $payment_method = $_POST['payment_method'];
    $bank_account_id = null;
    $bank_name = null;
    
    if ($payment_method === 'bank' || $payment_method === 'cheque') {
        $bank_account_id = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
        
        if ($bank_account_id) {
            $stmt = $db->prepare("SELECT account_name FROM bank_accounts WHERE id = ?");
            $stmt->execute([$bank_account_id]);
            $account = $stmt->fetch(PDO::FETCH_OBJ);
            $bank_name = $account ? $account->account_name : null;
        }
    }
    
    // If payment is posted and has journal entry, reverse it
    if ($old_payment->is_posted == 1 && $old_payment->journal_entry_id) {
        if ($journalHelper->canReverse($old_payment->journal_entry_id)) {
            $reversal_id = $journalHelper->reverseJournalEntry(
                $old_payment->journal_entry_id,
                'payment_adnan_edit_reversal',
                $payment_id,
                "Payment Edit: {$old_payment->payment_voucher_number} - Updated by " . getCurrentUser()['name']
            );
            
            if (!$reversal_id) {
                throw new Exception("Failed to reverse old journal entry");
            }
        }
    }
    
    // Update payment record
    $stmt = $db->prepare("
        UPDATE purchase_payments_adnan 
        SET 
            payment_date = ?,
            amount_paid = ?,
            payment_method = ?,
            bank_account_id = ?,
            bank_name = ?,
            handled_by_employee = ?,
            reference_number = ?,
            payment_type = ?,
            remarks = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    
    $stmt->execute([
        $_POST['payment_date'],
        (float)$_POST['amount_paid'],
        $payment_method,
        $bank_account_id,
        $bank_name,
        $payment_method === 'cash' ? (!empty($_POST['handled_by_employee']) ? $_POST['handled_by_employee'] : null) : null,
        !empty($_POST['reference_number']) ? $_POST['reference_number'] : null,
        $_POST['payment_type'],
        !empty($_POST['remarks']) ? $_POST['remarks'] : null,
        $payment_id
    ]);
    
    // Recalculate PO totals (skips generated columns)
    $journalHelper->recalculatePOTotals($purchase_order_id);
    
    // Update payment status
    $journalHelper->updatePaymentStatus($purchase_order_id);
    
    // Log to audit_log if table exists
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_log (
                table_name, record_id, action,
                old_values, new_values, user_id, created_at
            ) VALUES (
                'purchase_payments_adnan', ?, 'update',
                ?, ?, ?, NOW()
            )
        ");
        
        $new_values = [
            'payment_date' => $_POST['payment_date'],
            'amount_paid' => (float)$_POST['amount_paid'],
            'payment_method' => $payment_method,
            'bank_account_id' => $bank_account_id,
            'payment_type' => $_POST['payment_type']
        ];
        
        $stmt->execute([
            $payment_id,
            json_encode($old_payment),
            json_encode($new_values),
            getCurrentUser()['id']
        ]);
    } catch (Exception $e) {
        // Audit log is optional - don't fail if table doesn't exist
        error_log("Audit log failed: " . $e->getMessage());
    }
    
    $db->commit();
    
    $_SESSION['success_message'] = "Payment {$old_payment->payment_voucher_number} updated successfully!";
    header("Location: purchase_adnan_view_po.php?id={$purchase_order_id}");
    exit;
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log("Payment Update Failed: " . $e->getMessage());
    
    $_SESSION['error_message'] = "Failed to update payment: " . $e->getMessage();
    header("Location: purchase_adnan_edit_payment.php?id={$payment_id}");
    exit;
}
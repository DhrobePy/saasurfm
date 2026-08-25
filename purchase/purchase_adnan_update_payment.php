<?php
/**
 * Update Payment Handler - Superadmin Only
 * With Integrated Audit Trail
 */

require_once '../core/init.php';
require_once '../core/classes/JournalEntryHelper.php';
require_once '../core/classes/Purchasepaymentadnanmanager.php';

restrict_access(['Superadmin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = "Invalid request method";
    header('Location: purchase_adnan_index.php');
    exit;
}

$payment_id = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;
$purchase_order_id = isset($_POST['purchase_order_id']) ? (int)$_POST['purchase_order_id'] : 0;

if ($payment_id === 0 || $purchase_order_id === 0) {
    $_SESSION['error_message'] = "Invalid Payment or PO ID";
    header('Location: purchase_adnan_index.php');
    exit;
}

$db = Database::getInstance()->getPdo();
$journalHelper = new JournalEntryHelper();

try {
    $db->beginTransaction();
    
    // Get existing payment data for audit log
    $stmt = $db->prepare("
        SELECT pmt.*, po.po_number, po.supplier_name
        FROM purchase_payments_adnan pmt
        LEFT JOIN purchase_orders_adnan po ON pmt.purchase_order_id = po.id
        WHERE pmt.id = ?
    ");
    $stmt->execute([$payment_id]);
    $old_payment = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$old_payment) {
        throw new Exception("Payment not found");
    }
    
    // Track changes for audit
    $changes = [];
    $old_values = [];
    $new_values = [];
    
    // Payment Date
    if ($_POST['payment_date'] != $old_payment->payment_date) {
        $changes[] = "Payment Date: {$old_payment->payment_date} → {$_POST['payment_date']}";
        $old_values['payment_date'] = $old_payment->payment_date;
        $new_values['payment_date'] = $_POST['payment_date'];
    }
    
    // Amount Paid
    if ($_POST['amount_paid'] != $old_payment->amount_paid) {
        $changes[] = "Amount: ৳" . number_format($old_payment->amount_paid, 2) . " → ৳" . number_format($_POST['amount_paid'], 2);
        $old_values['amount_paid'] = $old_payment->amount_paid;
        $new_values['amount_paid'] = $_POST['amount_paid'];
    }
    
    // Payment Method
    if ($_POST['payment_method'] != $old_payment->payment_method) {
        $changes[] = "Method: {$old_payment->payment_method} → {$_POST['payment_method']}";
        $old_values['payment_method'] = $old_payment->payment_method;
        $new_values['payment_method'] = $_POST['payment_method'];
    }
    
    // Payment Type
    if ($_POST['payment_type'] != $old_payment->payment_type) {
        $changes[] = "Type: {$old_payment->payment_type} → {$_POST['payment_type']}";
        $old_values['payment_type'] = $old_payment->payment_type;
        $new_values['payment_type'] = $_POST['payment_type'];
    }
    
    // If payment has journal entry, reverse it — and re-post a replacement journal
    // entry reflecting the corrected values. The old code stopped at reversal, never
    // recreating this, so the GL permanently showed the reversed (net-zero) effect
    // instead of the corrected amount after any edit to a posted payment.
    if ($old_payment->journal_entry_id) {
        if ($journalHelper->canReverse($old_payment->journal_entry_id)) {
            $reversal_id = $journalHelper->reverseJournalEntry(
                $old_payment->journal_entry_id,
                'payment_adnan_edit_reversal',
                $payment_id,
                "Payment Edit: {$old_payment->payment_voucher_number} - Updated by " . getCurrentUser()['display_name']
            );

            if (!$reversal_id) {
                throw new Exception("Failed to reverse old journal entry");
            }

            $changes[] = "journal entry reversed (ID: {$reversal_id})";

            $new_payment_method = $_POST['payment_method'];
            $paymentManager = new Purchasepaymentadnanmanager();

            if ($new_payment_method === 'cash') {
                // Cash payments resolve their GL account via cash_account_id, which is
                // never persisted on purchase_payments_adnan and isn't collected by this
                // edit form — there is no reusable data to safely re-post from. Fail
                // loudly rather than silently leaving the GL unposted (the prior bug)
                // or guessing at a cash account.
                throw new Exception("Cannot re-post journal entry for a CASH payment edit — the cash account reference is not stored on this payment and cannot be safely re-derived. Please delete this payment and record it again instead of editing it.");
            }

            $new_bank_account_id = !empty($_POST['bank_account_id']) ? $_POST['bank_account_id'] : $old_payment->bank_account_id;
            $bank_chart_account_id = null;
            if (($new_payment_method === 'bank' || $new_payment_method === 'cheque') && !empty($new_bank_account_id)) {
                $bank = $paymentManager->getBankAccount($new_bank_account_id);
                $bank_chart_account_id = $bank->chart_of_account_id ?? null;
            }

            $journal_data = [
                'payment_date' => $_POST['payment_date'],
                'amount_paid' => $_POST['amount_paid'],
                'payment_method' => $new_payment_method,
                'bank_account_id' => $new_bank_account_id,
            ];

            $new_journal_id = $paymentManager->createPaymentJournalEntry($payment_id, $old_payment, $journal_data, $bank_chart_account_id);
            if (!$new_journal_id) {
                throw new Exception("Failed to re-post journal entry for corrected payment");
            }
            $paymentManager->updatePaymentJournalId($payment_id, $new_journal_id);
            $changes[] = "journal entry re-posted for corrected payment (ID: {$new_journal_id})";
        }
    }

    // Update payment record
    $stmt = $db->prepare("
        UPDATE purchase_payments_adnan 
        SET 
            payment_date = ?,
            amount_paid = ?,
            payment_method = ?,
            payment_type = ?,
            bank_account_id = ?,
            reference_number = ?,
            handled_by_employee = ?,
            remarks = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    
    $stmt->execute([
        $_POST['payment_date'],
        $_POST['amount_paid'],
        $_POST['payment_method'],
        $_POST['payment_type'],
        !empty($_POST['bank_account_id']) ? $_POST['bank_account_id'] : null,
        !empty($_POST['reference_number']) ? $_POST['reference_number'] : null,
        !empty($_POST['handled_by_employee']) ? $_POST['handled_by_employee'] : null,
        !empty($_POST['remarks']) ? $_POST['remarks'] : null,
        $payment_id
    ]);
    
    // Recalculate PO totals
    $journalHelper->recalculatePOTotals($purchase_order_id);
    
    // Update payment status
    $journalHelper->updatePaymentStatus($purchase_order_id);
    
    $db->commit();
    
    // ============================================
    // AUDIT TRAIL - PAYMENT UPDATED
    // ============================================
    try {
        if (function_exists('auditLog') && !empty($changes)) {
            $currentUser = getCurrentUser();
            $user_name = $currentUser['display_name'] ?? 'System User';
            
            auditLog(
                'purchase',
                'updated',
                "Payment {$old_payment->payment_voucher_number} updated for PO #{$old_payment->po_number}. Changes: " . implode(', ', $changes),
                [
                    'record_type' => 'purchase_payment',
                    'record_id' => $payment_id,
                    'reference_number' => $old_payment->payment_voucher_number,
                    'po_number' => $old_payment->po_number,
                    'supplier_name' => $old_payment->supplier_name,
                    'changes' => $changes,
                    'old_values' => $old_values,
                    'new_values' => $new_values,
                    'updated_by' => $user_name,
                    'severity' => 'warning'
                ]
            );
        }
    } catch (Exception $e) {
        error_log("✗ Audit log error: " . $e->getMessage());
    }
    
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
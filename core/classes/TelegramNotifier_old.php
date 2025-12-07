<?php
/**
 * Telegram Notifier Class
 * Handles sending formatted notifications to Telegram group
 */
class TelegramNotifier {
    private $botToken;
    private $chatId;
    private $apiUrl;
    
    public function __construct($botToken, $chatId) {
        $this->botToken = $botToken;
        $this->chatId = $chatId;
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
    }
    
    /**
     * Send a formatted message to Telegram
     * @param string $message The message to send (supports HTML formatting)
     * @return array Response from Telegram API
     */
    public function sendMessage($message) {
        $data = [
            'chat_id' => $this->chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'success' => $httpCode == 200,
            'response' => json_decode($response, true)
        ];
    }
    
    /**
     * Format credit order notification
     */
    public function sendCreditOrderNotification($orderData) {
        $emoji = "🛒"; // Shopping cart
        $statusEmoji = "⏳"; // Hourglass for pending
        
        // Format header
        $message = "<b>$emoji NEW CREDIT ORDER CREATED</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        // Order details
        $message .= "<b>📋 Order Information</b>\n";
        $message .= "• Order ID: <code>#{$orderData['order_id']}</code>\n";
        $message .= "• Date: {$orderData['order_date']}\n";
        $message .= "• Status: $statusEmoji {$orderData['status']}\n\n";
        
        // Customer details
        $message .= "<b>👤 Customer Details</b>\n";
        $message .= "• Name: {$orderData['customer_name']}\n";
        $message .= "• Phone: {$orderData['customer_phone']}\n";
        if (!empty($orderData['customer_address'])) {
            $message .= "• Address: {$orderData['customer_address']}\n";
        }
        $message .= "\n";
        
        // Branch info
        $message .= "<b>🏢 Branch</b>\n";
        $message .= "• {$orderData['branch_name']}\n\n";
        
        // Order items
        $message .= "<b>📦 Order Items</b>\n";
        foreach ($orderData['items'] as $index => $item) {
            $itemNum = $index + 1;
            $message .= "{$itemNum}. {$item['product_name']}";
            if (!empty($item['variant_name'])) {
                $message .= " ({$item['variant_name']})";
            }
            $message .= "\n";
            $message .= "   • Qty: {$item['quantity']} {$item['unit']}\n";
            $message .= "   • Price: ৳{$item['unit_price']}\n";
            $message .= "   • Subtotal: ৳{$item['subtotal']}\n";
        }
        $message .= "\n";
        
        // Financial summary
        $message .= "<b>💰 Financial Summary</b>\n";
        $message .= "• Subtotal: ৳" . number_format($orderData['subtotal'], 2) . "\n";
        if ($orderData['discount_amount'] > 0) {
            $message .= "• Discount: -৳" . number_format($orderData['discount_amount'], 2) . "\n";
        }
        $message .= "• <b>Total Amount: ৳" . number_format($orderData['total_amount'], 2) . "</b>\n";
        $message .= "• Paid: ৳" . number_format($orderData['paid_amount'], 2) . "\n";
        $message .= "• <b>Due: ৳" . number_format($orderData['due_amount'], 2) . "</b>\n\n";
        
        // Created by
        $message .= "<b>👨‍💼 Created By</b>\n";
        $message .= "• {$orderData['created_by']}\n";
        
        // Footer
        $message .= "\n" . str_repeat("─", 35) . "\n";
        $message .= "<i>Ujjal Flour Mills ERP System</i>";
        
        return $this->sendMessage($message);
    }
    
    /**
     * Format payment collection notification
     */
    public function sendPaymentNotification($paymentData) {
        $emoji = "💳";
        
        $message = "<b>$emoji PAYMENT RECEIVED</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        $message .= "<b>📋 Payment Details</b>\n";
        $message .= "• Receipt No: <code>#{$paymentData['receipt_no']}</code>\n";
        $message .= "• Date: {$paymentData['payment_date']}\n";
        $message .= "• Amount: <b>৳" . number_format($paymentData['amount'], 2) . "</b>\n";
        $message .= "• Method: {$paymentData['payment_method']}\n";
        if (!empty($paymentData['reference_number'])) {
            $message .= "• Reference: {$paymentData['reference_number']}\n";
        }
        $message .= "\n";
        
        $message .= "<b>👤 Customer</b>\n";
        $message .= "• {$paymentData['customer_name']}\n";
        if (!empty($paymentData['customer_phone'])) {
            $message .= "• Phone: {$paymentData['customer_phone']}\n";
        }
        if (isset($paymentData['new_balance'])) {
            $message .= "• New Balance: ৳" . number_format($paymentData['new_balance'], 2) . "\n";
        }
        $message .= "\n";
        
        // Payment type and allocations
        if (isset($paymentData['payment_type'])) {
            $message .= "<b>💰 Payment Type</b>\n";
            $message .= "• {$paymentData['payment_type']}\n";
            
            if (!empty($paymentData['allocated_invoices'])) {
                $message .= "\n<b>📄 Allocated to Invoices</b>\n";
                foreach ($paymentData['allocated_invoices'] as $index => $allocation) {
                    $num = $index + 1;
                    $message .= "{$num}. {$allocation['order_number']}: ৳" . number_format($allocation['amount'], 2) . "\n";
                }
            }
            $message .= "\n";
        }
        
        if (!empty($paymentData['notes'])) {
            $message .= "<b>📝 Notes</b>\n";
            $message .= "• {$paymentData['notes']}\n\n";
        }
        
        $message .= "<b>🏢 Branch</b>\n";
        $message .= "• {$paymentData['branch_name']}\n\n";
        
        $message .= "<b>👨‍💼 Collected By</b>\n";
        $message .= "• {$paymentData['collected_by']}\n";
        
        $message .= "\n" . str_repeat("─", 35) . "\n";
        $message .= "<i>Ujjal Flour Mills ERP System</i>";
        
        return $this->sendMessage($message);
    }
    
    /**
     * Format advance payment notification (specific for advance payments on credit orders)
     */
    public function sendAdvancePaymentNotification($paymentData) {
        $emoji = "💵"; // Money with wings for advance
        
        $message = "<b>$emoji ADVANCE PAYMENT RECEIVED</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        $message .= "<b>📋 Payment Details</b>\n";
        $message .= "• Receipt No: <code>#{$paymentData['receipt_no']}</code>\n";
        $message .= "• Date: {$paymentData['payment_date']}\n";
        $message .= "• Amount: <b>৳" . number_format($paymentData['amount'], 2) . "</b>\n";
        $message .= "• Method: {$paymentData['payment_method']}\n";
        if (!empty($paymentData['reference_number'])) {
            $message .= "• Reference: {$paymentData['reference_number']}\n";
        }
        $message .= "\n";
        
        $message .= "<b>👤 Customer</b>\n";
        $message .= "• {$paymentData['customer_name']}\n";
        if (!empty($paymentData['customer_phone'])) {
            $message .= "• Phone: {$paymentData['customer_phone']}\n";
        }
        if (isset($paymentData['new_balance'])) {
            $message .= "• New Balance: ৳" . number_format($paymentData['new_balance'], 2) . "\n";
        }
        $message .= "\n";
        
        $message .= "<b>💰 Payment Type</b>\n";
        $message .= "• <b>Advance Payment on Credit Orders</b>\n\n";
        
        // Show allocated orders
        if (!empty($paymentData['allocated_invoices'])) {
            $message .= "<b>📦 Allocated to Orders</b>\n";
            $total_allocated = 0;
            foreach ($paymentData['allocated_invoices'] as $index => $allocation) {
                $num = $index + 1;
                $message .= "{$num}. {$allocation['order_number']}: ৳" . number_format($allocation['amount'], 2) . "\n";
                $total_allocated += $allocation['amount'];
            }
            $message .= "• <b>Total Allocated: ৳" . number_format($total_allocated, 2) . "</b>\n";
            
            // Show unallocated amount if any
            if (isset($paymentData['unallocated_amount']) && $paymentData['unallocated_amount'] > 0) {
                $message .= "• Unallocated/Credit: ৳" . number_format($paymentData['unallocated_amount'], 2) . "\n";
            }
            $message .= "\n";
        }
        
        if (!empty($paymentData['notes'])) {
            $message .= "<b>📝 Notes</b>\n";
            $message .= "• {$paymentData['notes']}\n\n";
        }
        
        $message .= "<b>🏢 Branch</b>\n";
        $message .= "• {$paymentData['branch_name']}\n\n";
        
        $message .= "<b>👨‍💼 Collected By</b>\n";
        $message .= "• {$paymentData['collected_by']}\n";
        
        $message .= "\n" . str_repeat("─", 35) . "\n";
        $message .= "<i>Ujjal Flour Mills ERP System</i>";
        
        return $this->sendMessage($message);
    }
    
    /**
     * Format order approval notification
     */
    public function sendOrderApprovalNotification($approvalData) {
        $emoji = "✅"; // Check mark
        $statusEmoji = "🟢"; // Green circle
        
        // Format header
        $message = "<b>$emoji ORDER APPROVED</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        // Order details
        $message .= "<b>📋 Order Information</b>\n";
        $message .= "• Order ID: <code>#{$approvalData['order_number']}</code>\n";
        $message .= "• Status: $statusEmoji Approved\n";
        $message .= "• Approval Date: {$approvalData['approval_date']}\n\n";
        
        // Customer details
        $message .= "<b>👤 Customer</b>\n";
        $message .= "• Name: {$approvalData['customer_name']}\n";
        $message .= "• Phone: {$approvalData['customer_phone']}\n\n";
        
        // Assigned branch
        $message .= "<b>🏭 Assigned for Production</b>\n";
        $message .= "• Branch: {$approvalData['assigned_branch']}\n";
        $message .= "• Required Date: {$approvalData['required_date']}\n\n";
        
        // Order items
        $message .= "<b>📦 Order Items</b>\n";
        foreach ($approvalData['items'] as $index => $item) {
            $itemNum = $index + 1;
            $message .= "{$itemNum}. {$item['product_name']}";
            if (!empty($item['variant_name'])) {
                $message .= " ({$item['variant_name']})";
            }
            $message .= "\n";
            $message .= "   • Qty: {$item['quantity']} {$item['unit']}\n";
            $message .= "   • Price: ৳{$item['unit_price']}\n";
            $message .= "   • Subtotal: ৳{$item['subtotal']}\n";
        }
        $message .= "\n";
        
        // Financial summary
        $message .= "<b>💰 Financial Summary</b>\n";
        $message .= "• Subtotal: ৳" . number_format($approvalData['subtotal'], 2) . "\n";
        if ($approvalData['discount_amount'] > 0) {
            $message .= "• Discount: -৳" . number_format($approvalData['discount_amount'], 2) . "\n";
        }
        $message .= "• <b>Total Amount: ৳" . number_format($approvalData['total_amount'], 2) . "</b>\n";
        $message .= "• Advance Paid: ৳" . number_format($approvalData['advance_paid'], 2) . "\n";
        $message .= "• <b>Balance Due: ৳" . number_format($approvalData['balance_due'], 2) . "</b>\n\n";
        
        // Comments if any
        if (!empty($approvalData['comments'])) {
            $message .= "<b>💬 Comments</b>\n";
            $message .= "• {$approvalData['comments']}\n\n";
        }
        
        // Approved by
        $message .= "<b>👨‍💼 Approved By</b>\n";
        $message .= "• {$approvalData['approved_by']}\n";
        
        // Footer
        $message .= "\n" . str_repeat("─", 35) . "\n";
        $message .= "<i>Ujjal Flour Mills ERP System</i>";
        
        return $this->sendMessage($message);
    }
    
    /**
     * Format order rejection notification
     */
    public function sendOrderRejectionNotification($rejectionData) {
        $emoji = "❌"; // Cross mark
        $statusEmoji = "🔴"; // Red circle
        
        // Format header
        $message = "<b>$emoji ORDER REJECTED</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        // Order details
        $message .= "<b>📋 Order Information</b>\n";
        $message .= "• Order ID: <code>#{$rejectionData['order_number']}</code>\n";
        $message .= "• Status: $statusEmoji Rejected\n";
        $message .= "• Rejection Date: {$rejectionData['rejection_date']}\n\n";
        
        // Customer details
        $message .= "<b>👤 Customer</b>\n";
        $message .= "• Name: {$rejectionData['customer_name']}\n";
        $message .= "• Phone: {$rejectionData['customer_phone']}\n\n";
        
        // Financial info
        $message .= "<b>💰 Order Amount</b>\n";
        $message .= "• Total: ৳" . number_format($rejectionData['total_amount'], 2) . "\n";
        $message .= "• Balance Due: ৳" . number_format($rejectionData['balance_due'], 2) . "\n\n";
        
        // Rejection reason
        $message .= "<b>📝 Rejection Reason</b>\n";
        $message .= "• {$rejectionData['rejection_reason']}\n\n";
        
        // Rejected by
        $message .= "<b>👨‍💼 Rejected By</b>\n";
        $message .= "• {$rejectionData['rejected_by']}\n";
        
        // Footer
        $message .= "\n" . str_repeat("─", 35) . "\n";
        $message .= "<i>Ujjal Flour Mills ERP System</i>";
        
        return $this->sendMessage($message);
    }
    
    /**
     * Format production started notification
     */
    public function sendProductionStartedNotification($productionData) {
        $emoji = "🏭"; // Factory
        $statusEmoji = "▶️"; // Play button
        
        $message = "<b>$emoji PRODUCTION STARTED</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        $message .= "<b>📋 Order Information</b>\n";
        $message .= "• Order ID: <code>#{$productionData['order_number']}</code>\n";
        $message .= "• Status: $statusEmoji In Production\n";
        $message .= "• Started: {$productionData['started_at']}\n\n";
        
        $message .= "<b>👤 Customer</b>\n";
        $message .= "• Name: {$productionData['customer_name']}\n";
        $message .= "• Phone: {$productionData['customer_phone']}\n\n";
        
        $message .= "<b>🏭 Production Branch</b>\n";
        $message .= "• Branch: {$productionData['branch_name']}\n";
        $message .= "• Required Date: {$productionData['required_date']}\n\n";
        
        // Order items
        $message .= "<b>📦 Items to Produce</b>\n";
        foreach ($productionData['items'] as $index => $item) {
            $itemNum = $index + 1;
            $message .= "{$itemNum}. {$item['product_name']}";
            if (!empty($item['variant_name'])) {
                $message .= " ({$item['variant_name']})";
            }
            $message .= "\n";
            $message .= "   • Qty: {$item['quantity']} {$item['unit']}\n";
        }
        $message .= "\n";
        
        $message .= "<b>💰 Order Value</b>\n";
        $message .= "• Total: ৳" . number_format($productionData['total_amount'], 2) . "\n\n";
        
        $message .= "<b>👨‍💼 Started By</b>\n";
        $message .= "• {$productionData['started_by']}\n";
        
        $message .= "\n" . str_repeat("─", 35) . "\n";
        $message .= "<i>Ujjal Flour Mills ERP System</i>";
        
        return $this->sendMessage($message);
    }
    
    /**
     * Format production completed notification
     */
    public function sendProductionCompletedNotification($productionData) {
        $emoji = "✅"; // Check mark
        $statusEmoji = "🟢"; // Green circle
        
        $message = "<b>$emoji PRODUCTION COMPLETED</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        $message .= "<b>📋 Order Information</b>\n";
        $message .= "• Order ID: <code>#{$productionData['order_number']}</code>\n";
        $message .= "• Status: $statusEmoji Produced\n";
        $message .= "• Completed: {$productionData['completed_at']}\n\n";
        
        $message .= "<b>👤 Customer</b>\n";
        $message .= "• Name: {$productionData['customer_name']}\n";
        $message .= "• Phone: {$productionData['customer_phone']}\n\n";
        
        $message .= "<b>🏭 Production Branch</b>\n";
        $message .= "• Branch: {$productionData['branch_name']}\n";
        $message .= "• Required Date: {$productionData['required_date']}\n\n";
        
        // Order items
        $message .= "<b>📦 Produced Items</b>\n";
        foreach ($productionData['items'] as $index => $item) {
            $itemNum = $index + 1;
            $message .= "{$itemNum}. {$item['product_name']}";
            if (!empty($item['variant_name'])) {
                $message .= " ({$item['variant_name']})";
            }
            $message .= "\n";
            $message .= "   • Qty: {$item['quantity']} {$item['unit']}\n";
        }
        $message .= "\n";
        
        $message .= "<b>💰 Order Value</b>\n";
        $message .= "• Total: ৳" . number_format($productionData['total_amount'], 2) . "\n\n";
        
        if (!empty($productionData['duration'])) {
            $message .= "<b>⏱️ Production Time</b>\n";
            $message .= "• Duration: {$productionData['duration']}\n\n";
        }
        
        $message .= "<b>👨‍💼 Completed By</b>\n";
        $message .= "• {$productionData['completed_by']}\n";
        
        $message .= "\n" . str_repeat("─", 35) . "\n";
        $message .= "<i>Ujjal Flour Mills ERP System</i>";
        
        return $this->sendMessage($message);
    }
    
    /**
     * Format ready to ship notification
     */
    public function sendReadyToShipNotification($shipmentData) {
        $emoji = "🚚"; // Truck
        $statusEmoji = "📦"; // Package
        
        $message = "<b>$emoji ORDER READY TO SHIP</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        $message .= "<b>📋 Order Information</b>\n";
        $message .= "• Order ID: <code>#{$shipmentData['order_number']}</code>\n";
        $message .= "• Status: $statusEmoji Ready to Ship\n";
        $message .= "• Ready Since: {$shipmentData['ready_at']}\n\n";
        
        $message .= "<b>👤 Customer</b>\n";
        $message .= "• Name: {$shipmentData['customer_name']}\n";
        $message .= "• Phone: {$shipmentData['customer_phone']}\n";
        if (!empty($shipmentData['shipping_address'])) {
            $message .= "• Address: {$shipmentData['shipping_address']}\n";
        }
        $message .= "\n";
        
        $message .= "<b>🏭 Branch</b>\n";
        $message .= "• {$shipmentData['branch_name']}\n\n";
        
        // Order items
        $message .= "<b>📦 Items Ready for Shipment</b>\n";
        foreach ($shipmentData['items'] as $index => $item) {
            $itemNum = $index + 1;
            $message .= "{$itemNum}. {$item['product_name']}";
            if (!empty($item['variant_name'])) {
                $message .= " ({$item['variant_name']})";
            }
            $message .= "\n";
            $message .= "   • Qty: {$item['quantity']} {$item['unit']}\n";
        }
        $message .= "\n";
        
        $message .= "<b>💰 Order Value</b>\n";
        $message .= "• Total: ৳" . number_format($shipmentData['total_amount'], 2) . "\n";
        $message .= "• Balance Due: ৳" . number_format($shipmentData['balance_due'], 2) . "\n\n";
        
        $message .= "<b>👨‍💼 Marked By</b>\n";
        $message .= "• {$shipmentData['marked_by']}\n";
        
        $message .= "\n" . str_repeat("─", 35) . "\n";
        $message .= "<i>Ujjal Flour Mills ERP System</i>";
        
        return $this->sendMessage($message);
    }
    
    /**
     * Format priority update notification
     */
    public function sendPriorityUpdateNotification($priorityData) {
        $emoji = "🔢"; // Numbers
        
        $message = "<b>$emoji PRODUCTION PRIORITY UPDATED</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        $message .= "<b>📋 Order Information</b>\n";
        $message .= "• Order ID: <code>#{$priorityData['order_number']}</code>\n";
        $message .= "• New Priority: <b>#{$priorityData['new_priority']}</b>\n";
        $message .= "• Updated: {$priorityData['updated_at']}\n\n";
        
        $message .= "<b>👤 Customer</b>\n";
        $message .= "• Name: {$priorityData['customer_name']}\n\n";
        
        $message .= "<b>🏭 Branch</b>\n";
        $message .= "• {$priorityData['branch_name']}\n\n";
        
        $message .= "<b>👨‍💼 Updated By</b>\n";
        $message .= "• {$priorityData['updated_by']}\n";
        
        $message .= "\n" . str_repeat("─", 35) . "\n";
        $message .= "<i>Ujjal Flour Mills ERP System</i>";
        
        return $this->sendMessage($message);
    }
    
    /**
     * Format order shipped notification
     */
    public function sendOrderShippedNotification($shipmentData) {
        $emoji = "🚛"; // Truck
        $statusEmoji = "📤"; // Outbox
        
        $message = "<b>$emoji ORDER SHIPPED</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        $message .= "<b>📋 Order Information</b>\n";
        $message .= "• Order ID: <code>#{$shipmentData['order_number']}</code>\n";
        $message .= "• Status: $statusEmoji Shipped\n";
        $message .= "• Shipped: {$shipmentData['shipped_at']}\n\n";
        
        $message .= "<b>👤 Customer</b>\n";
        $message .= "• Name: {$shipmentData['customer_name']}\n";
        $message .= "• Phone: {$shipmentData['customer_phone']}\n";
        if (!empty($shipmentData['shipping_address'])) {
            $message .= "• Address: {$shipmentData['shipping_address']}\n";
        }
        $message .= "\n";
        
        $message .= "<b>🚛 Vehicle & Driver</b>\n";
        $message .= "• Truck: {$shipmentData['truck_number']}\n";
        $message .= "• Driver: {$shipmentData['driver_name']}\n";
        $message .= "• Contact: {$shipmentData['driver_contact']}\n";
        $message .= "• Trip ID: #{$shipmentData['trip_id']}\n";
        if (!empty($shipmentData['trip_type'])) {
            $trip_type_display = $shipmentData['trip_type'] === 'consolidated' ? '🔗 Consolidated Trip' : '📦 Single Delivery';
            $message .= "• Type: {$trip_type_display}\n";
        }
        $message .= "\n";
        
        $message .= "<b>🏭 Branch</b>\n";
        $message .= "• {$shipmentData['branch_name']}\n\n";
        
        // Order items
        $message .= "<b>📦 Shipped Items</b>\n";
        foreach ($shipmentData['items'] as $index => $item) {
            $itemNum = $index + 1;
            $message .= "{$itemNum}. {$item['product_name']}";
            if (!empty($item['variant_name'])) {
                $message .= " ({$item['variant_name']})";
            }
            $message .= "\n";
            $message .= "   • Qty: {$item['quantity']} {$item['unit']}\n";
        }
        $message .= "\n";
        
        $message .= "<b>💰 Order Value</b>\n";
        $message .= "• Total: ৳" . number_format($shipmentData['total_amount'], 2) . "\n";
        $message .= "• Balance Due: ৳" . number_format($shipmentData['balance_due'], 2) . "\n\n";
        
        $message .= "<b>👨‍💼 Dispatched By</b>\n";
        $message .= "• {$shipmentData['dispatched_by']}\n";
        
        $message .= "\n" . str_repeat("─", 35) . "\n";
        $message .= "<i>Ujjal Flour Mills ERP System</i>";
        
        return $this->sendMessage($message);
    }
    
    /**
     * Format order delivered notification
     */
    public function sendOrderDeliveredNotification($deliveryData) {
        $emoji = "✅"; // Check mark
        $statusEmoji = "📥"; // Inbox
        
        $message = "<b>$emoji ORDER DELIVERED</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        $message .= "<b>📋 Order Information</b>\n";
        $message .= "• Order ID: <code>#{$deliveryData['order_number']}</code>\n";
        $message .= "• Status: $statusEmoji Delivered\n";
        $message .= "• Delivered: {$deliveryData['delivered_at']}\n\n";
        
        $message .= "<b>👤 Customer</b>\n";
        $message .= "• Name: {$deliveryData['customer_name']}\n";
        $message .= "• Phone: {$deliveryData['customer_phone']}\n";
        if (!empty($deliveryData['shipping_address'])) {
            $message .= "• Address: {$deliveryData['shipping_address']}\n";
        }
        $message .= "\n";
        
        $message .= "<b>🚛 Delivery Details</b>\n";
        $message .= "• Truck: {$deliveryData['truck_number']}\n";
        $message .= "• Driver: {$deliveryData['driver_name']}\n";
        $message .= "• Trip ID: #{$deliveryData['trip_id']}\n\n";
        
        $message .= "<b>🏭 Branch</b>\n";
        $message .= "• {$deliveryData['branch_name']}\n\n";
        
        // Order items
        $message .= "<b>📦 Delivered Items</b>\n";
        foreach ($deliveryData['items'] as $index => $item) {
            $itemNum = $index + 1;
            $message .= "{$itemNum}. {$item['product_name']}";
            if (!empty($item['variant_name'])) {
                $message .= " ({$item['variant_name']})";
            }
            $message .= "\n";
            $message .= "   • Qty: {$item['quantity']} {$item['unit']}\n";
        }
        $message .= "\n";
        
        $message .= "<b>💰 Order Value</b>\n";
        $message .= "• Total: ৳" . number_format($deliveryData['total_amount'], 2) . "\n";
        $message .= "• Balance Due: ৳" . number_format($deliveryData['balance_due'], 2) . "\n\n";
        
        if (!empty($deliveryData['delivery_notes'])) {
            $message .= "<b>📝 Delivery Notes</b>\n";
            $message .= "• {$deliveryData['delivery_notes']}\n\n";
        }
        
        $message .= "<b>👨‍💼 Confirmed By</b>\n";
        $message .= "• {$deliveryData['confirmed_by']}\n";
        
        $message .= "\n" . str_repeat("─", 35) . "\n";
        $message .= "<i>Ujjal Flour Mills ERP System</i>";
        
        return $this->sendMessage($message);
    }
    
    /**
     * Format fuel purchase notification
     */
    public function sendFuelPurchaseNotification($fuelData) {
        $emoji = "⛽"; // Fuel pump
        
        $message = "<b>$emoji FUEL PURCHASE LOGGED</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        $message .= "<b>🚛 Vehicle Details</b>\n";
        $message .= "• Vehicle: <b>{$fuelData['vehicle_number']}</b>\n";
        $message .= "• Fuel Type: {$fuelData['fuel_type']}\n";
        if ($fuelData['odometer_reading'] > 0) {
            $message .= "• Odometer: " . number_format($fuelData['odometer_reading'], 0) . " km\n";
        }
        if (!empty($fuelData['trip_info'])) {
            $message .= "• {$fuelData['trip_info']}\n";
        }
        $message .= "\n";
        
        $message .= "<b>⛽ Fuel Purchase</b>\n";
        $message .= "• Date: {$fuelData['fuel_date']}\n";
        $message .= "• Quantity: <b>{$fuelData['quantity']} liters</b>\n";
        $message .= "• Price/Liter: ৳" . number_format($fuelData['price_per_liter'], 2) . "\n";
        $message .= "• <b>Total Cost: ৳" . number_format($fuelData['total_cost'], 2) . "</b>\n";
        $message .= "\n";
        
        $message .= "<b>🏪 Station Details</b>\n";
        $message .= "• Station: {$fuelData['fuel_station']}\n";
        if (!empty($fuelData['receipt_number'])) {
            $message .= "• Receipt: {$fuelData['receipt_number']}\n";
        }
        $message .= "\n";
        
        $message .= "<b>💳 Payment</b>\n";
        $message .= "• Paid from: {$fuelData['payment_method']}\n";
        $message .= "• Handled by: {$fuelData['handled_by']}\n";
        $message .= "\n";
        
        if (!empty($fuelData['notes'])) {
            $message .= "<b>📝 Notes</b>\n";
            $message .= "• {$fuelData['notes']}\n\n";
        }
        
        $message .= "<b>👨‍💼 Logged By</b>\n";
        $message .= "• {$fuelData['logged_by']}\n";
        
        $message .= "\n" . str_repeat("─", 35) . "\n";
        $message .= "<i>Ujjal Flour Mills ERP System</i>";
        
        return $this->sendMessage($message);
    }
    
    /**
     * Format expense voucher notification
     */
    public function sendExpenseVoucherNotification($expenseData) {
        $emoji = "💸"; // Money with wings for expense
        
        $message = "<b>$emoji EXPENSE VOUCHER CREATED</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        $message .= "<b>📋 Voucher Details</b>\n";
        $message .= "• Voucher No: <code>#{$expenseData['voucher_number']}</code>\n";
        $message .= "• Date: {$expenseData['voucher_date']}\n";
        $message .= "• Amount: <b>৳" . number_format($expenseData['amount'], 2) . "</b>\n";
        $message .= "\n";
        
        $message .= "<b>👤 Paid To</b>\n";
        $message .= "• {$expenseData['paid_to']}\n";
        if (!empty($expenseData['employee_name'])) {
            $message .= "• Employee: {$expenseData['employee_name']}\n";
        }
        if (!empty($expenseData['reference_number'])) {
            $message .= "• Reference: {$expenseData['reference_number']}\n";
        }
        $message .= "\n";
        
        $message .= "<b>📝 Description</b>\n";
        $message .= "• {$expenseData['description']}\n\n";
        
        $message .= "<b>📊 Accounting Entry</b>\n";
        $message .= "• Debit: {$expenseData['expense_account']}\n";
        $message .= "• Credit: {$expenseData['payment_account']}\n\n";
        
        $message .= "<b>🏢 Branch</b>\n";
        $message .= "• {$expenseData['branch_name']}\n\n";
        
        $message .= "<b>👨‍💼 Created By</b>\n";
        $message .= "• {$expenseData['created_by']}\n";
        
        $message .= "\n" . str_repeat("─", 35) . "\n";
        $message .= "<i>Ujjal Flour Mills ERP System</i>";
        
        return $this->sendMessage($message);
    }
    
    /**
     * Format purchase order notification
     */
    public function sendPurchaseOrderNotification($poData) {
        $emoji = "📦"; // Package for purchase order
        
        $message = "<b>$emoji PURCHASE ORDER CREATED</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        $message .= "<b>📋 PO Details</b>\n";
        $message .= "• PO Number: <code>#{$poData['po_number']}</code>\n";
        $message .= "• Date: {$poData['po_date']}\n";
        $message .= "• Status: {$poData['status']}\n";
        $message .= "\n";
        
        $message .= "<b>🏢 Supplier</b>\n";
        $message .= "• {$poData['supplier_name']}\n";
        if (!empty($poData['supplier_phone'])) {
            $message .= "• Phone: {$poData['supplier_phone']}\n";
        }
        if (!empty($poData['supplier_email'])) {
            $message .= "• Email: {$poData['supplier_email']}\n";
        }
        $message .= "\n";
        
        $message .= "<b>🌾 Wheat Details</b>\n";
        $message .= "• Origin: {$poData['wheat_origin']}\n";
        $message .= "• Quantity: <b>" . number_format($poData['quantity_kg'], 2) . " KG</b>\n";
        $message .= "• Unit Price: ৳" . number_format($poData['unit_price_per_kg'], 2) . " per KG\n";
        if (!empty($poData['expected_delivery_date'])) {
            $message .= "• Expected Delivery: {$poData['expected_delivery_date']}\n";
        }
        $message .= "\n";
        
        $message .= "<b>💰 Order Value</b>\n";
        $message .= "• Total: <b>৳" . number_format($poData['total_amount'], 2) . "</b>\n";
        $message .= "\n";
        
        if (!empty($poData['remarks'])) {
            $message .= "<b>📝 Remarks</b>\n";
            $message .= "• " . substr($poData['remarks'], 0, 150) . (strlen($poData['remarks']) > 150 ? '...' : '') . "\n\n";
        }
        
        $message .= "<b>👨‍💼 Created By</b>\n";
        $message .= "• {$poData['created_by']}\n";
        
        $message .= "\n" . str_repeat("─", 35) . "\n";
        $message .= "<i>Ujjal Flour Mills ERP System</i>";
        
        return $this->sendMessage($message);
    }
    
    /**
     * Format GRN (Goods Received Note) notification
     */
    public function sendGRNNotification($grnData) {
        $emoji = "🚛"; // Truck for goods received
        
        $message = "<b>$emoji GOODS RECEIVED (GRN)</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        $message .= "<b>📋 GRN Details</b>\n";
        $message .= "• GRN Number: <code>#{$grnData['grn_number']}</code>\n";
        $message .= "• Date: {$grnData['grn_date']}\n";
        $message .= "• PO Reference: <code>#{$grnData['po_number']}</code>\n";
        $message .= "\n";
        
        $message .= "<b>🏢 Supplier</b>\n";
        $message .= "• {$grnData['supplier_name']}\n\n";
        
        $message .= "<b>🌾 Wheat Received</b>\n";
        $message .= "• Origin: {$grnData['wheat_origin']}\n";
        $message .= "• Quantity: <b>" . number_format($grnData['quantity_received_kg'], 2) . " KG</b>\n";
        $message .= "• Unit Price: ৳" . number_format($grnData['unit_price_per_kg'], 2) . " per KG\n";
        $message .= "• Value: <b>৳" . number_format($grnData['received_value'], 2) . "</b>\n";
        $message .= "\n";
        
        $message .= "<b>📦 Delivery Info</b>\n";
        $message .= "• Truck: {$grnData['truck_number']}\n";
        $message .= "• Unload Point: {$grnData['unload_point']}\n";
        $message .= "\n";
        
        $message .= "<b>📊 PO Progress</b>\n";
        $message .= "• Ordered: " . number_format($grnData['po_quantity'], 2) . " KG\n";
        $message .= "• Received: " . number_format($grnData['total_received'], 2) . " KG\n";
        $message .= "• Pending: " . number_format($grnData['pending'], 2) . " KG\n";
        $message .= "• Completion: <b>" . number_format($grnData['completion_percentage'], 1) . "%</b>\n";
        $message .= "\n";
        
        if (!empty($grnData['remarks'])) {
            $message .= "<b>📝 Remarks</b>\n";
            $message .= "• " . substr($grnData['remarks'], 0, 150) . (strlen($grnData['remarks']) > 150 ? '...' : '') . "\n\n";
        }
        
        $message .= "<b>👨‍💼 Recorded By</b>\n";
        $message .= "• {$grnData['recorded_by']}\n";
        
        $message .= "\n" . str_repeat("─", 35) . "\n";
        $message .= "<i>Ujjal Flour Mills ERP System</i>";
        
        return $this->sendMessage($message);
    }
    
    /**
     * Format purchase payment notification
     */
    public function sendPurchasePaymentNotification($paymentData) {
        $emoji = "💸"; // Money with wings for payment
        
        $message = "<b>$emoji PURCHASE PAYMENT MADE</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        $message .= "<b>📋 Payment Details</b>\n";
        $message .= "• Voucher: <code>#{$paymentData['voucher_number']}</code>\n";
        $message .= "• Date: {$paymentData['payment_date']}\n";
        $message .= "• PO Reference: <code>#{$paymentData['po_number']}</code>\n";
        $message .= "• Type: {$paymentData['payment_type']}\n";
        $message .= "\n";
        
        $message .= "<b>🏢 Supplier</b>\n";
        $message .= "• {$paymentData['supplier_name']}\n";
        $message .= "• Wheat: {$paymentData['wheat_origin']}\n";
        $message .= "\n";
        
        $message .= "<b>💰 Payment Info</b>\n";
        $message .= "• Amount Paid: <b>৳" . number_format($paymentData['amount_paid'], 2) . "</b>\n";
        $message .= "• Method: {$paymentData['payment_method']}\n";
        $message .= "• Account: {$paymentData['bank_account']}\n";
        if (!empty($paymentData['reference_number'])) {
            $message .= "• Reference: {$paymentData['reference_number']}\n";
        }
        if (!empty($paymentData['employee_name'])) {
            $message .= "• Handled By: {$paymentData['employee_name']}\n";
        }
        $message .= "\n";
        
        $message .= "<b>📊 Payment Status</b>\n";
        $message .= "• Order Value: ৳" . number_format($paymentData['total_order_value'], 2) . "\n";
        $message .= "• Total Paid: ৳" . number_format($paymentData['total_paid'], 2) . "\n";
        $message .= "• Balance Due: <b>৳" . number_format($paymentData['balance_payable'], 2) . "</b>\n";
        $message .= "• Paid: <b>" . number_format($paymentData['payment_percentage'], 1) . "%</b>\n";
        $message .= "\n";
        
        if (!empty($paymentData['remarks'])) {
            $message .= "<b>📝 Remarks</b>\n";
            $message .= "• " . substr($paymentData['remarks'], 0, 150) . (strlen($paymentData['remarks']) > 150 ? '...' : '') . "\n\n";
        }
        
        $message .= "<b>👨‍💼 Recorded By</b>\n";
        $message .= "• {$paymentData['recorded_by']}\n";
        
        $message .= "\n" . str_repeat("─", 35) . "\n";
        $message .= "<i>Ujjal Flour Mills ERP System</i>";
        
        return $this->sendMessage($message);
    }
}
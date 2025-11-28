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
        
        $message = "<b>$emoji PAYMENT COLLECTED</b>\n";
        $message .= str_repeat("─", 35) . "\n\n";
        
        $message .= "<b>📋 Payment Details</b>\n";
        $message .= "• Receipt No: <code>#{$paymentData['receipt_no']}</code>\n";
        $message .= "• Date: {$paymentData['payment_date']}\n";
        $message .= "• Amount: <b>৳" . number_format($paymentData['amount'], 2) . "</b>\n";
        $message .= "• Method: {$paymentData['payment_method']}\n\n";
        
        $message .= "<b>👤 Customer</b>\n";
        $message .= "• {$paymentData['customer_name']}\n";
        if (!empty($paymentData['order_id'])) {
            $message .= "• Order: <code>#{$paymentData['order_id']}</code>\n";
        }
        $message .= "\n";
        
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
}
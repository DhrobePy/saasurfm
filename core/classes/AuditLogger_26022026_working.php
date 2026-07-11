<?php
/**
 * AuditLogger Class
 * Centralized logging for all user activities across the system
 * 
 * Usage:
 * AuditLogger::log('expense', 'created', [
 *     'record_id' => 250,
 *     'reference' => 'EXP-20260117-0007',
 *     'description' => 'Created expense voucher',
 *     'data' => $expenseData
 * ]);
 */

class AuditLogger {
    
    /**
     * Log a user activity
     * 
     * @param string $module Module name (expense, credit_order, etc.)
     * @param string $action Action performed (created, updated, etc.)
     * @param array $options Additional logging options
     * @return bool Success status
     */
    /**
 * Log a user activity
 * 
 * @param string $module Module name (expense, credit_order, etc.)
 * @param string $action Action performed (created, updated, etc.)
 * @param array $options Additional logging options
 * @return bool Success status
 */
    public static function log($module, $action, $options = []) {
    global $db;
    
    // Get current user ID
    $userId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
    if (!$userId) {
        // If no user is logged in, log as system (user_id = 0 or skip)
        return false;
    }
    
    // Extract options
    $recordType = $options['record_type'] ?? null;
    $recordId = $options['record_id'] ?? null;
    $reference = $options['reference'] ?? $options['reference_number'] ?? null;
    $description = $options['description'] ?? self::generateDescription($module, $action, $options);
    $oldValue = $options['old_value'] ?? null;
    $newValue = $options['new_value'] ?? null;
    $changesJson = $options['changes'] ?? $options['data'] ?? null;
    $severity = $options['severity'] ?? self::determineSeverity($action);
    $status = $options['status'] ?? 'success';
    $metadata = $options['metadata'] ?? null;
    $errorMessage = $options['error'] ?? $options['error_message'] ?? null;
    
    // Get request information
    $ipAddress = self::getClientIP();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $requestUri = $_SERVER['REQUEST_URI'] ?? null;
    
    // Convert arrays/objects to JSON
    if (is_array($changesJson) || is_object($changesJson)) {
        $changesJson = json_encode($changesJson, JSON_UNESCAPED_UNICODE);
    }
    if (is_array($metadata) || is_object($metadata)) {
        $metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE);
    }
    if (is_array($oldValue) || is_object($oldValue)) {
        $oldValue = json_encode($oldValue, JSON_UNESCAPED_UNICODE);
    }
    if (is_array($newValue) || is_object($newValue)) {
        $newValue = json_encode($newValue, JSON_UNESCAPED_UNICODE);
    }
    
    // Insert log entry
    try {
        $sql = "INSERT INTO system_audit_log (
            user_id, module, action, record_type, record_id, reference_number,
            description, old_value, new_value, changes_json,
            ip_address, user_agent, request_uri,
            severity, status, metadata, error_message
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        // Get PDO instance - handle both custom Database wrapper and direct PDO
        if (method_exists($db, 'getPdo')) {
            $pdo = $db->getPdo();
        } elseif ($db instanceof PDO) {
            $pdo = $db;
        } else {
            throw new Exception('Invalid database connection');
        }
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            $userId,
            $module,
            $action,
            $recordType,
            $recordId,
            $reference,
            $description,
            $oldValue,
            $newValue,
            $changesJson,
            $ipAddress,
            $userAgent,
            $requestUri,
            $severity,
            $status,
            $metadata,
            $errorMessage
        ]);
        
        return $result;
    } catch (PDOException $e) {
        // Log error but don't break the application
        error_log("AuditLogger PDO Error: " . $e->getMessage());
        error_log("SQL: " . $sql);
        error_log("Params: " . json_encode([
            'user_id' => $userId,
            'module' => $module,
            'action' => $action
        ]));
        return false;
    } catch (Exception $e) {
        // Log error but don't break the application
        error_log("AuditLogger Error: " . $e->getMessage());
        return false;
    }
}
    
    /**
     * Log expense voucher actions
     */
    public static function logExpense($action, $voucherId, $voucherNumber, $options = []) {
        return self::log('expense', $action, array_merge([
            'record_type' => 'expense_voucher',
            'record_id' => $voucherId,
            'reference' => $voucherNumber
        ], $options));
    }
    
    /**
     * Log credit order actions
     */
    public static function logCreditOrder($action, $orderId, $orderNumber, $options = []) {
        return self::log('credit_order', $action, array_merge([
            'record_type' => 'credit_order',
            'record_id' => $orderId,
            'reference' => $orderNumber
        ], $options));
    }
    
    /**
     * Log customer payment actions
     */
    public static function logPayment($action, $paymentId, $reference, $options = []) {
        return self::log('customer_payment', $action, array_merge([
            'record_type' => 'customer_payment',
            'record_id' => $paymentId,
            'reference' => $reference
        ], $options));
    }
    
    /**
     * Log shipping/dispatch actions
     */
    public static function logShipping($action, $shippingId, $reference, $options = []) {
        return self::log('shipping', $action, array_merge([
            'record_type' => 'shipping',
            'record_id' => $shippingId,
            'reference' => $reference
        ], $options));
    }
    
    /**
     * Log user authentication actions
     */
    public static function logAuth($action, $userId, $options = []) {
        return self::log('user_management', $action, array_merge([
            'record_type' => 'user_session',
            'record_id' => $userId
        ], $options));
    }
    
    /**
     * Get user activities
     */
    public static function getUserActivity($userId, $startDate = null, $endDate = null, $limit = 100) {
        global $db;
        
        $sql = "SELECT 
                sal.*,
                u.display_name as user_name,
                u.role as user_role
            FROM system_audit_log sal
            LEFT JOIN users u ON sal.user_id = u.id
            WHERE sal.user_id = ?";
        
        $params = [$userId];
        
        if ($startDate) {
            $sql .= " AND DATE(sal.created_at) >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $sql .= " AND DATE(sal.created_at) <= ?";
            $params[] = $endDate;
        }
        
        $sql .= " ORDER BY sal.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        return $db->query($sql, $params)->results();
    }
    
    /**
     * Get module activities
     */
    public static function getModuleActivity($module, $startDate = null, $endDate = null, $limit = 100) {
        global $db;
        
        $sql = "SELECT 
                sal.*,
                u.display_name as user_name,
                u.role as user_role
            FROM system_audit_log sal
            LEFT JOIN users u ON sal.user_id = u.id
            WHERE sal.module = ?";
        
        $params = [$module];
        
        if ($startDate) {
            $sql .= " AND DATE(sal.created_at) >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $sql .= " AND DATE(sal.created_at) <= ?";
            $params[] = $endDate;
        }
        
        $sql .= " ORDER BY sal.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        return $db->query($sql, $params)->results();
    }
    
    /**
     * Get recent activities
     */
    public static function getRecentActivity($limit = 50) {
        global $db;
        
        $sql = "SELECT 
                sal.*,
                u.display_name as user_name,
                u.role as user_role
            FROM system_audit_log sal
            LEFT JOIN users u ON sal.user_id = u.id
            ORDER BY sal.created_at DESC
            LIMIT ?";
        
        return $db->query($sql, [$limit])->results();
    }
    
    /**
     * Get activity statistics
     */
    public static function getStatistics($userId = null, $startDate = null, $endDate = null) {
        global $db;
        
        $sql = "SELECT 
                module,
                action,
                COUNT(*) as count
            FROM system_audit_log
            WHERE 1=1";
        
        $params = [];
        
        if ($userId) {
            $sql .= " AND user_id = ?";
            $params[] = $userId;
        }
        
        if ($startDate) {
            $sql .= " AND DATE(created_at) >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $sql .= " AND DATE(created_at) <= ?";
            $params[] = $endDate;
        }
        
        $sql .= " GROUP BY module, action ORDER BY count DESC";
        
        return $db->query($sql, $params)->results();
    }
    
    /**
     * Generate description from module and action
     */
    private static function generateDescription($module, $action, $options) {
        $reference = $options['reference'] ?? $options['reference_number'] ?? '';
        
        $descriptions = [
            'expense' => [
                'created' => "Created expense voucher $reference",
                'updated' => "Updated expense voucher $reference",
                'deleted' => "Deleted expense voucher $reference",
                'approved' => "Approved expense voucher $reference",
                'rejected' => "Rejected expense voucher $reference",
                'viewed' => "Viewed expense voucher $reference",
                'printed' => "Printed expense voucher $reference",
            ],
            'credit_order' => [
                'created' => "Created credit order $reference",
                'updated' => "Updated credit order $reference",
                'deleted' => "Deleted credit order $reference",
                'approved' => "Approved credit order $reference",
                'shipped' => "Shipped credit order $reference",
                'status_changed' => "Changed status of credit order $reference",
            ],
            'customer_payment' => [
                'created' => "Recorded customer payment $reference",
                'allocated' => "Allocated payment to order $reference",
                'refunded' => "Refunded payment $reference",
            ],
            'user_management' => [
                'logged_in' => "User logged into system",
                'logged_out' => "User logged out of system",
                'created' => "Created new user account",
                'updated' => "Updated user account",
                'deleted' => "Deleted user account",
            ],
        ];
        
        return $descriptions[$module][$action] ?? ucfirst($action) . " in " . $module;
    }
    
    /**
     * Determine severity based on action
     */
    private static function determineSeverity($action) {
        $critical = ['deleted', 'cancelled', 'refunded'];
        $warning = ['approved', 'rejected', 'updated', 'status_changed'];
        
        if (in_array($action, $critical)) {
            return 'critical';
        } elseif (in_array($action, $warning)) {
            return 'warning';
        } else {
            return 'info';
        }
    }
    
    /**
     * Get client IP address
     */
    private static function getClientIP() {
        $ipAddress = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ipAddress = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        }
        
        return $ipAddress;
    }
}

/**
 * USAGE EXAMPLES:
 * 
 * // Example 1: Log expense creation
 * AuditLogger::logExpense('created', $expenseId, $voucherNumber, [
 *     'description' => 'Created expense voucher for salary payment',
 *     'data' => [
 *         'amount' => 35100,
 *         'category' => 'Salary',
 *         'payment_method' => 'bank'
 *     ]
 * ]);
 * 
 * // Example 2: Log expense approval
 * AuditLogger::logExpense('approved', $expenseId, $voucherNumber, [
 *     'old_value' => ['status' => 'pending'],
 *     'new_value' => ['status' => 'approved'],
 *     'severity' => 'warning'
 * ]);
 * 
 * // Example 3: Log expense deletion (critical)
 * AuditLogger::logExpense('deleted', $expenseId, $voucherNumber, [
 *     'description' => 'Deleted expense voucher',
 *     'old_value' => $oldExpenseData,
 *     'severity' => 'critical'
 * ]);
 * 
 * // Example 4: Log credit order
 * AuditLogger::logCreditOrder('created', $orderId, $orderNumber, [
 *     'data' => [
 *         'customer_id' => 45,
 *         'total_amount' => 125000,
 *         'items_count' => 5
 *     ]
 * ]);
 * 
 * // Example 5: Log payment allocation
 * AuditLogger::logPayment('allocated', $paymentId, $orderNumber, [
 *     'description' => "Allocated ৳50,000 to order $orderNumber",
 *     'data' => [
 *         'amount' => 50000,
 *         'order_id' => $orderId
 *     ]
 * ]);
 * 
 * // Example 6: Log user login
 * AuditLogger::logAuth('logged_in', $userId, [
 *     'description' => 'User logged into system'
 * ]);
 * 
 * // Example 7: Log with error
 * AuditLogger::log('expense', 'created', [
 *     'description' => 'Attempted to create expense voucher',
 *     'status' => 'failed',
 *     'error' => 'Insufficient petty cash balance',
 *     'severity' => 'warning'
 * ]);
 * 
 * // Example 8: Get user activity
 * $activities = AuditLogger::getUserActivity($userId, '2026-01-01', '2026-01-31');
 * 
 * // Example 9: Get module activity
 * $expenseActivities = AuditLogger::getModuleActivity('expense', '2026-01-01', '2026-01-31');
 * 
 * // Example 10: Get statistics
 * $stats = AuditLogger::getStatistics($userId, '2026-01-01', '2026-01-31');
 */
?>
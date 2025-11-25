-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Nov 25, 2025 at 09:40 PM
-- Server version: 11.4.9-MariaDB
-- PHP Version: 8.4.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ujjalfmc_saas`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`ujjalfmc`@`localhost` PROCEDURE `sp_allocate_payment` (IN `payment_id_param` BIGINT, IN `order_id_param` BIGINT, IN `amount_param` DECIMAL(12,2), IN `user_id_param` BIGINT)   BEGIN
    DECLARE payment_customer_id BIGINT;
    DECLARE order_customer_id BIGINT;
    DECLARE order_outstanding DECIMAL(12,2);
    DECLARE payment_unallocated DECIMAL(12,2);
    
    -- Get payment details
    SELECT customer_id, (amount - allocated_amount) INTO payment_customer_id, payment_unallocated
    FROM customer_payments WHERE id = payment_id_param;
    
    -- Get order details
    SELECT customer_id, (total_amount - amount_paid) INTO order_customer_id, order_outstanding
    FROM credit_orders WHERE id = order_id_param;
    
    -- Validate
    IF payment_customer_id != order_customer_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment and order must be for the same customer';
    END IF;
    
    IF amount_param > payment_unallocated THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Allocation amount exceeds unallocated payment balance';
    END IF;
    
    IF amount_param > order_outstanding THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Allocation amount exceeds order outstanding balance';
    END IF;
    
    -- Create allocation
    INSERT INTO payment_allocations (payment_id, order_id, allocated_amount, allocation_date, allocated_by_user_id)
    VALUES (payment_id_param, order_id_param, amount_param, CURDATE(), user_id_param);
    
    -- Update payment
    UPDATE customer_payments 
    SET allocated_amount = allocated_amount + amount_param,
        allocation_status = CASE 
            WHEN (allocated_amount + amount_param) >= amount THEN 'allocated'
            ELSE 'partial'
        END
    WHERE id = payment_id_param;
    
    -- Update order
    UPDATE credit_orders
    SET amount_paid = amount_paid + amount_param,
        balance_due = total_amount - (amount_paid + amount_param)
    WHERE id = order_id_param;
    
    SELECT 'Allocation successful' as message;
END$$

CREATE DEFINER=`ujjalfmc`@`localhost` PROCEDURE `sp_backfill_all_order_weights` ()   BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_order_id INT;
    DECLARE cur CURSOR FOR SELECT id FROM credit_orders;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN cur;
    
    read_loop: LOOP
        FETCH cur INTO v_order_id;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        -- Calculate weight for this order
        CALL sp_calculate_order_weight(v_order_id);
    END LOOP;
    
    CLOSE cur;
    
    SELECT 'All order weights calculated successfully!' as message;
END$$

CREATE DEFINER=`ujjalfmc`@`localhost` PROCEDURE `sp_calculate_order_weight` (IN `p_order_id` INT)   BEGIN
    DECLARE v_total_weight DECIMAL(10,2);
    
    -- Calculate total weight: SUM(quantity × weight_variant)
    SELECT COALESCE(SUM(coi.quantity * pv.weight_variant), 0)
    INTO v_total_weight
    FROM credit_order_items coi
    JOIN product_variants pv ON coi.variant_id = pv.id
    WHERE coi.order_id = p_order_id;
    
    -- Update the credit_orders table with calculated weight
    UPDATE credit_orders 
    SET total_weight_kg = v_total_weight
    WHERE id = p_order_id;
    
END$$

CREATE DEFINER=`ujjalfmc`@`localhost` PROCEDURE `sp_find_consolidation_opportunities` (IN `p_order_id` BIGINT(20) UNSIGNED)   BEGIN
    DECLARE v_branch_id BIGINT(20) UNSIGNED; -- Matched type with branches.id
    DECLARE v_required_date DATE;
    DECLARE v_weight DECIMAL(10,2);
    
    SELECT assigned_branch_id, required_date, total_weight_kg
    INTO v_branch_id, v_required_date, v_weight
    FROM credit_orders
    WHERE id = p_order_id;
    
    DELETE FROM trip_consolidation_suggestions
    WHERE suggestion_status = 'active' AND expires_at < NOW();
    
    INSERT INTO trip_consolidation_suggestions (
        base_order_id, suggested_order_id, compatibility_score,
        weight_fit, route_efficiency, suggested_vehicle_id,
        suggestion_status, expires_at
    )
    SELECT 
        p_order_id, co2.id,
        (
            (CASE 
                WHEN co2.required_date = v_required_date THEN 100
                WHEN ABS(DATEDIFF(co2.required_date, v_required_date)) = 1 THEN 70
                ELSE 50
            END * 0.4) +
            (CASE WHEN co2.assigned_branch_id = v_branch_id THEN 100 ELSE 0 END * 0.3) +
            (CASE WHEN v.id IS NOT NULL THEN 100 ELSE 0 END * 0.3)
        ) as compatibility_score,
        CASE WHEN v.id IS NOT NULL THEN 1 ELSE 0 END,
        100 - (ABS(DATEDIFF(co2.required_date, v_required_date)) * 10),
        v.id,
        'active',
        DATE_ADD(NOW(), INTERVAL 24 HOUR)
    FROM credit_orders co2
    LEFT JOIN vehicles v ON v.status = 'Active' 
        AND v.capacity_kg >= (v_weight + co2.total_weight_kg)
    WHERE co2.id != p_order_id
        AND co2.status = 'ready_to_ship'
        AND co2.assigned_branch_id = v_branch_id
        AND ABS(DATEDIFF(co2.required_date, v_required_date)) <= 1
        AND NOT EXISTS (
            SELECT 1 FROM trip_consolidation_suggestions tcs
            WHERE tcs.base_order_id = p_order_id
            AND tcs.suggested_order_id = co2.id
            AND tcs.suggestion_status = 'active'
        )
    HAVING compatibility_score >= 60
    ORDER BY compatibility_score DESC
    LIMIT 10;
END$$

CREATE DEFINER=`ujjalfmc`@`localhost` PROCEDURE `sp_get_customer_outstanding` (IN `customer_id_param` BIGINT)   BEGIN
    SELECT 
        c.id,
        c.name,
        c.phone_number,
        c.current_balance,
        c.credit_limit,
        c.credit_limit - c.current_balance as available_credit,
        COUNT(co.id) as unpaid_invoices,
        SUM(co.total_amount - co.amount_paid) as total_outstanding
    FROM customers c
    LEFT JOIN credit_orders co ON c.id = co.customer_id 
        AND co.status IN ('shipped', 'delivered')
        AND (co.total_amount - co.amount_paid) > 0
    WHERE c.id = customer_id_param
    GROUP BY c.id;
END$$

CREATE DEFINER=`ujjalfmc`@`localhost` PROCEDURE `sp_get_next_shipment_number` (OUT `next_number` VARCHAR(50))   BEGIN
  DECLARE last_num INT DEFAULT 0;
  DECLARE current_year CHAR(4);
  
  SET current_year = YEAR(CURDATE());
  
  SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(shipment_number, '-', -1) AS UNSIGNED)), 0)
  INTO last_num
  FROM wheat_shipments
  WHERE shipment_number LIKE CONCAT('WS-', current_year, '-%');
  
  SET next_number = CONCAT('WS-', current_year, '-', LPAD(last_num + 1, 3, '0'));
END$$

CREATE DEFINER=`ujjalfmc`@`localhost` PROCEDURE `sp_get_unallocated_payments` (IN `customer_id_param` BIGINT)   BEGIN
    SELECT 
        cp.id,
        cp.receipt_number,
        cp.payment_date,
        cp.amount,
        cp.allocated_amount,
        (cp.amount - cp.allocated_amount) as unallocated_amount,
        cp.payment_method
    FROM customer_payments cp
    WHERE cp.customer_id = customer_id_param
      AND cp.allocation_status IN ('unallocated', 'partial')
      AND (cp.amount - cp.allocated_amount) > 0
    ORDER BY cp.payment_date ASC;
END$$

CREATE DEFINER=`ujjalfmc`@`localhost` PROCEDURE `sp_reorder_credit_orders` (IN `order_ids_json` JSON, IN `user_id_param` BIGINT)   BEGIN
    DECLARE i INT DEFAULT 0;
    DECLARE order_count INT;
    DECLARE current_order_id BIGINT;
    DECLARE current_priority VARCHAR(20);
    
    SET order_count = JSON_LENGTH(order_ids_json);
    
    START TRANSACTION;
    
    WHILE i < order_count DO
        SET current_order_id = JSON_UNQUOTE(JSON_EXTRACT(order_ids_json, CONCAT('$[', i, ']')));
        
        -- Determine priority based on position
        SET current_priority = CASE 
            WHEN i = 0 OR i = 1 THEN 'urgent'
            WHEN i >= 2 AND i <= 4 THEN 'high'
            WHEN i >= 5 AND i <= 9 THEN 'normal'
            ELSE 'low'
        END;
        
        -- Update order
        UPDATE credit_orders
        SET 
            sort_order = i,
            priority = current_priority,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = current_order_id;
        
        -- Log the change
        INSERT INTO credit_order_audit (order_id, user_id, action_type, field_name, new_value, notes)
        VALUES (current_order_id, user_id_param, 'priority_changed', 'sort_order', i, 
                CONCAT('Reordered to position ', i, ' with priority ', current_priority));
        
        SET i = i + 1;
    END WHILE;
    
    COMMIT;
    
    SELECT 'Orders reordered successfully' as message, order_count as orders_updated;
END$$

--
-- Functions
--
CREATE DEFINER=`ujjalfmc`@`localhost` FUNCTION `fn_get_next_sort_order` () RETURNS INT(11) DETERMINISTIC BEGIN
    DECLARE next_order INT;
    
    SELECT COALESCE(MAX(sort_order), 0) + 1 INTO next_order
    FROM credit_orders
    WHERE status NOT IN ('delivered', 'cancelled');
    
    RETURN next_order;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `bank_accounts`
--

CREATE TABLE `bank_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `chart_of_account_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Links to chart_of_accounts.id',
  `uuid` varchar(36) NOT NULL DEFAULT uuid(),
  `bank_name` varchar(255) NOT NULL,
  `branch_name` varchar(255) DEFAULT NULL,
  `account_name` varchar(255) NOT NULL,
  `account_number` varchar(100) NOT NULL,
  `account_type` enum('Checking','Savings','Loan','Credit','Other') NOT NULL DEFAULT 'Checking',
  `address` text DEFAULT NULL,
  `initial_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `current_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','inactive','closed') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bank_accounts`
--

INSERT INTO `bank_accounts` (`id`, `chart_of_account_id`, `uuid`, `bank_name`, `branch_name`, `account_name`, `account_number`, `account_type`, `address`, `initial_balance`, `current_balance`, `status`, `created_at`, `updated_at`) VALUES
(1, 12, 'f2afa997-b013-11f0-9003-10ffe0a28e39', 'ucbl', 'kamarpara', 'ujjal flour mills', '1662301000000228', 'Checking', 'kamhgfhf', 1800000.00, 1630000.00, 'active', '2025-10-23 13:26:57', '2025-11-24 05:46:02'),
(2, 13, '91eef8a6-b016-11f0-9003-10ffe0a28e39', 'UCBL', 'Kafrul Branch', 'Ujjal Flour Mills', '2122101000000123', 'Checking', 'Kafrul, Dhaka', 0.00, 0.00, 'active', '2025-10-23 13:45:43', '2025-10-23 13:45:43'),
(3, NULL, '639f6c32-c844-11f0-93c1-10ffe0a28e39', 'Pubali Bank', 'Branch 1260', 'PUBALI 1260', '1260', 'Checking', NULL, 0.00, 0.00, 'active', '2025-11-23 08:14:10', '2025-11-23 08:14:10'),
(4, NULL, '639f8201-c844-11f0-93c1-10ffe0a28e39', 'Pubali Bank', 'Branch 1430', 'PUBALI 1430', '1430', 'Checking', NULL, 0.00, 0.00, 'active', '2025-11-23 08:14:10', '2025-11-23 08:14:10'),
(5, NULL, '639f8275-c844-11f0-93c1-10ffe0a28e39', 'Mercantile Bank', 'Branch 445', 'MERCANTILE 445', '445', 'Checking', NULL, 0.00, 0.00, 'active', '2025-11-23 08:14:10', '2025-11-23 08:14:10'),
(6, NULL, '639f82a5-c844-11f0-93c1-10ffe0a28e39', 'Jamuna Bank', 'Branch 178', 'JAMUNA 178', '178', 'Checking', NULL, 0.00, 0.00, 'active', '2025-11-23 08:14:10', '2025-11-23 08:14:10'),
(7, NULL, '639f82d4-c844-11f0-93c1-10ffe0a28e39', 'Pubali Bank', 'Branch 4250', 'Pubali 4250', '4250', 'Checking', NULL, 0.00, 0.00, 'active', '2025-11-23 08:14:10', '2025-11-23 08:14:10'),
(8, NULL, '639f82f9-c844-11f0-93c1-10ffe0a28e39', 'IFIC Bank', 'Main Branch', 'IFIC', 'IFIC', 'Checking', NULL, 0.00, 0.00, 'active', '2025-11-23 08:14:10', '2025-11-23 08:14:10'),
(9, NULL, '639f8320-c844-11f0-93c1-10ffe0a28e39', 'UCB Bank', 'MILI Branch', 'UCB MILI 824', '824', 'Checking', NULL, 0.00, 0.00, 'active', '2025-11-23 08:14:10', '2025-11-23 08:14:10'),
(10, NULL, '639f8345-c844-11f0-93c1-10ffe0a28e39', 'Premier Bank', 'Branch 030', 'PREMIER 030', '030', 'Checking', NULL, 0.00, 0.00, 'active', '2025-11-23 08:14:10', '2025-11-23 08:14:10');

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL COMMENT 'Full name of the branch (e.g., "Savar SRG Factory")',
  `code` varchar(50) DEFAULT NULL COMMENT 'Short code for the branch (e.g., "SRG", "DEMRA")',
  `address` text DEFAULT NULL COMMENT 'Physical address of the branch',
  `phone_number` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`id`, `name`, `code`, `address`, `phone_number`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Sirajgonj', 'SRG', NULL, NULL, 'active', '2025-10-22 08:00:48', '2025-10-22 08:00:48'),
(2, 'Demra', 'DEMRA', NULL, NULL, 'active', '2025-10-22 08:00:48', '2025-10-22 08:00:48'),
(3, 'Rampura', 'RAMPURA', NULL, NULL, 'active', '2025-10-22 08:00:48', '2025-10-22 08:00:48'),
(4, 'Head Office', 'HO', NULL, NULL, 'active', '2025-10-22 08:00:48', '2025-10-22 08:00:48');

-- --------------------------------------------------------

--
-- Table structure for table `branch_petty_cash_accounts`
--

CREATE TABLE `branch_petty_cash_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `account_name` varchar(255) NOT NULL DEFAULT 'Petty Cash - POS',
  `current_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `opening_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `opening_date` date NOT NULL,
  `status` enum('active','inactive','closed') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `branch_petty_cash_accounts`
--

INSERT INTO `branch_petty_cash_accounts` (`id`, `branch_id`, `account_name`, `current_balance`, `opening_balance`, `opening_date`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'Petty Cash - Sirajgonj', 47110.00, 0.00, '2025-10-27', 'active', '2025-10-27 06:18:35', '2025-11-03 19:40:15'),
(2, 2, 'Petty Cash - Demra', 0.00, 0.00, '2025-10-27', 'active', '2025-10-27 06:18:35', '2025-10-27 06:18:35'),
(3, 3, 'Petty Cash - Rampura', 0.00, 0.00, '2025-10-27', 'active', '2025-10-27 06:18:35', '2025-10-27 06:18:35'),
(4, 4, 'Petty Cash - Head Office', 5100.00, 0.00, '2025-10-27', 'active', '2025-10-27 06:18:35', '2025-11-25 14:32:04');

-- --------------------------------------------------------

--
-- Table structure for table `branch_petty_cash_transactions`
--

CREATE TABLE `branch_petty_cash_transactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL COMMENT 'References branch_petty_cash_accounts',
  `transaction_date` datetime NOT NULL,
  `transaction_type` enum('cash_in','cash_out','transfer_in','transfer_out','adjustment','opening_balance') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `balance_after` decimal(12,2) NOT NULL COMMENT 'Running balance after this transaction',
  `reference_type` varchar(50) DEFAULT NULL COMMENT 'orders, expenses, transfers, eod, etc',
  `reference_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'ID of related record',
  `description` text NOT NULL,
  `payment_method` varchar(50) DEFAULT 'Cash',
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `verified_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `branch_petty_cash_transactions`
--

INSERT INTO `branch_petty_cash_transactions` (`id`, `branch_id`, `account_id`, `transaction_date`, `transaction_type`, `amount`, `balance_after`, `reference_type`, `reference_id`, `description`, `payment_method`, `created_by_user_id`, `verified_by_user_id`, `verified_at`, `created_at`) VALUES
(1, 1, 1, '2025-10-27 12:45:52', 'cash_in', 3360.00, 3360.00, 'orders', 18, 'Cash sale - Order #ORD-20251027-0003', 'Cash', 3, NULL, NULL, '2025-10-27 06:45:52'),
(2, 1, 1, '2025-10-27 12:47:25', 'transfer_out', 3000.00, 360.00, 'internal_transfer', 11, 'Transfer out: Internal Transfer: POS Sales Sirajgonj to ucbl - ujjal flour mills (1662301000000228) - trial cash transfer', 'Cash', 3, NULL, NULL, '2025-10-27 06:47:25'),
(3, 1, 1, '2025-11-01 13:04:14', 'cash_in', 11100.00, 11460.00, 'orders', 19, 'Cash sale - Order #ORD-20251101-0001', 'Cash', 3, NULL, NULL, '2025-11-01 07:04:14'),
(4, 1, 1, '2025-11-01 13:10:45', 'transfer_out', 11400.00, 60.00, 'internal_transfer', 24, 'Transfer out: Internal Transfer: POS Sales Sirajgonj to UCBL - Ujjal Flour Mills (2122101000000123) - dedygf', 'Cash', 3, NULL, NULL, '2025-11-01 07:10:45'),
(5, 1, 1, '2025-11-04 01:39:02', 'cash_in', 22260.00, 22320.00, 'orders', 20, 'Cash sale - Order #ORD-20251104-0001', 'Cash', 3, NULL, NULL, '2025-11-03 19:39:02'),
(6, 1, 1, '2025-11-04 01:40:15', 'cash_in', 24790.00, 47110.00, 'orders', 21, 'Cash sale - Order #ORD-20251104-0002', 'Cash', 3, NULL, NULL, '2025-11-03 19:40:15'),
(7, 4, 4, '2025-11-25 20:32:04', 'cash_in', 5100.00, 5100.00, 'orders', 23, 'Cash sale - Order #ORD-20251125-0001', 'Cash', 1, NULL, NULL, '2025-11-25 14:32:04');

-- --------------------------------------------------------

--
-- Table structure for table `cash_verification_log`
--

CREATE TABLE `cash_verification_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `verification_date` datetime NOT NULL,
  `expected_cash` decimal(12,2) NOT NULL COMMENT 'System calculated',
  `actual_cash` decimal(12,2) NOT NULL COMMENT 'Physically counted',
  `variance` decimal(12,2) NOT NULL COMMENT 'Difference (actual - expected)',
  `variance_reason` text DEFAULT NULL,
  `verified_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `witness_user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Second person who witnessed count',
  `notes` text DEFAULT NULL,
  `status` enum('pending','approved','disputed') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chart_of_accounts`
--

CREATE TABLE `chart_of_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `account_number` varchar(100) DEFAULT NULL,
  `account_type` enum('Bank','Petty Cash','Cash','Accounts Receivable','Other Current Asset','Fixed Asset','Accounts Payable','Credit Card','Loan','Other Liability','Owner Equity','Revenue','Other Income','Expense','Cost of Goods Sold','Other Expense') NOT NULL,
  `branch_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Links petty cash accounts to specific branches for real-time tracking',
  `account_type_group` varchar(100) DEFAULT NULL COMMENT 'e.g., Asset, Liability, Expense',
  `normal_balance` enum('Debit','Credit') NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chart_of_accounts`
--

INSERT INTO `chart_of_accounts` (`id`, `account_number`, `account_type`, `branch_id`, `account_type_group`, `normal_balance`, `status`, `description`, `is_active`, `created_at`, `name`) VALUES
(1, NULL, 'Revenue', NULL, 'Revenue', 'Credit', 'active', 'Credit sales proceeds from Sirajgonj mill', 1, '2025-10-22 16:03:20', 'Credit sales Sirajgonh'),
(2, NULL, 'Owner Equity', NULL, 'Equity', 'Credit', 'active', 'System account for recording initial balances.', 1, '2025-10-23 13:03:32', 'Opening Balance Equity'),
(12, '1662301000000228', 'Bank', NULL, 'Asset', 'Debit', 'active', 'Bank Account: ucbl - kamarpara', 1, '2025-10-23 13:26:57', 'ucbl - ujjal flour mills (1662301000000228)'),
(13, '2122101000000123', 'Bank', NULL, 'Asset', 'Debit', 'active', 'Bank Account: UCBL - Kafrul Branch', 1, '2025-10-23 13:45:43', 'UCBL - Ujjal Flour Mills (2122101000000123)'),
(14, NULL, 'Petty Cash', 1, 'Asset', 'Debit', 'active', 'Sales collection through POS of SRG', 1, '2025-10-23 16:26:57', 'POS Sales Sirajgonj'),
(15, NULL, 'Petty Cash', 3, 'Asset', 'Debit', 'active', 'Sales collection through Rampura sales Point', 1, '2025-10-23 16:28:12', 'POS Rampura'),
(16, NULL, 'Expense', 1, 'Expense', 'Debit', 'active', 'Salary Expense for Sirajgonj Mills', 1, '2025-10-23 16:29:14', 'Salary Expense Sirajgonj'),
(17, NULL, 'Expense', 2, 'Expense', 'Debit', 'active', 'Salary Expense for Demra Mill', 1, '2025-10-23 16:30:03', 'Salary Expense Demra'),
(18, NULL, 'Revenue', 1, 'Revenue', 'Credit', 'active', 'Sales through Sirajgonj Office', 1, '2025-10-23 16:31:00', 'Credit Sales Sirajgonj'),
(19, NULL, 'Revenue', 1, 'Revenue', 'Credit', 'active', 'POS sales Collection account for EOD', 1, '2025-10-23 16:31:47', 'POS sales Collection Sirajgonj'),
(20, NULL, 'Revenue', 3, 'Revenue', 'Credit', 'active', 'POS sales collection from Rampura Outlet', 1, '2025-10-23 16:32:27', 'POS Sales Collection Rampura'),
(21, NULL, 'Petty Cash', 4, 'Asset', 'Debit', 'active', 'Petty Cash for Rampura Head Office', 1, '2025-10-23 16:33:30', 'Petty Cash HO'),
(22, NULL, 'Petty Cash', 2, 'Asset', 'Debit', 'active', 'Petty Cash for Demra Mills Operating Cost', 1, '2025-10-23 16:34:28', 'Petty Cash Demra Mill'),
(23, NULL, 'Accounts Receivable', NULL, 'Asset', 'Debit', 'active', 'Accounts Receivables ', 1, '2025-10-25 09:19:37', 'Accounts Receivables'),
(24, NULL, 'Expense', NULL, 'Expense', 'Debit', 'active', 'Discounts in POS SRG', 1, '2025-10-25 09:20:45', 'Sales Discounts'),
(25, NULL, 'Revenue', NULL, 'Revenue', 'Credit', 'active', 'POS Sales Revenue SRG', 1, '2025-10-25 09:21:53', 'POS Sales Revenue SRG'),
(26, NULL, 'Petty Cash', 2, 'Asset', 'Debit', 'active', 'POS Cash register for Demra branch', 1, '2025-10-25 15:28:20', 'POS Cash - Demra'),
(27, NULL, 'Revenue', 2, 'Revenue', 'Credit', 'active', 'Revenue from POS sales at Demra branch', 1, '2025-10-25 15:28:20', 'POS Sales Revenue - Demra'),
(28, NULL, 'Petty Cash', 4, 'Asset', 'Debit', 'active', 'POS Cash register for Head Office', 1, '2025-10-25 15:28:20', 'POS Cash - Head Office'),
(29, NULL, 'Revenue', 4, 'Revenue', 'Credit', 'active', 'Revenue from POS sales at Head Office', 1, '2025-10-25 15:28:20', 'POS Sales Revenue - Head Office'),
(30, NULL, 'Other Current Asset', NULL, 'Asset', 'Debit', 'active', 'Payments received via card, bank transfer, or mobile banking that have not yet been deposited to bank account', 1, '2025-10-25 15:28:20', 'Undeposited Funds'),
(31, NULL, 'Expense', NULL, 'Expense', 'Debit', 'active', '', 1, '2025-11-01 07:17:15', 'Repairs and Maintainance'),
(32, NULL, 'Expense', NULL, 'Expense', 'Debit', 'active', 'Spare parts etc  ', 1, '2025-11-11 10:44:22', 'Raw Material'),
(33, NULL, 'Expense', NULL, 'Expense', 'Debit', 'active', 'Fuel Expense ledger Demra', 1, '2025-11-13 20:11:39', 'Fuel Expense'),
(34, NULL, 'Expense', NULL, 'Expense', 'Debit', 'active', 'Vehicle Maintanance', 1, '2025-11-14 11:02:05', 'Vehicle Maintanance'),
(35, '5020', 'Expense', NULL, NULL, 'Debit', 'active', 'Expense account for all vehicle servicing and maintenance.', 1, '2025-11-14 11:21:51', 'Vehicle Maintenance Expense'),
(36, '5030', 'Expense', NULL, NULL, 'Debit', 'active', 'Expense account for all vehicle document renewals (Tax, Fitness, etc.).', 1, '2025-11-14 11:36:12', 'Vehicle Document Expense'),
(37, '4020', 'Revenue', NULL, NULL, 'Credit', 'active', 'Income generated from renting out company vehicles.', 1, '2025-11-14 13:53:22', 'Vehicle Rental Income'),
(38, '1120', 'Accounts Receivable', NULL, NULL, 'Debit', 'active', 'Tracks all money owed to the company by its customers.', 1, '2025-11-14 14:09:04', 'Accounts Receivable'),
(39, '2010', 'Accounts Payable', NULL, 'Liability', 'Credit', 'active', 'Tracks all money owed to suppliers and vendors', 1, '2025-11-20 16:10:00', 'Accounts Payable'),
(40, '1300', 'Other Current Asset', NULL, 'Asset', 'Debit', 'active', 'Raw materials, packaging, and supplies inventory', 1, '2025-11-20 16:10:22', 'Inventory - Raw Materials'),
(41, '5010', 'Cost of Goods Sold', NULL, 'Expense', 'Debit', 'active', 'Cost of raw materials purchased', 1, '2025-11-20 16:10:33', 'Raw Material Purchases'),
(42, '5011', 'Cost of Goods Sold', NULL, 'Expense', 'Debit', 'active', 'Cost of packaging materials purchased', 1, '2025-11-20 16:10:43', 'Packaging Materials'),
(43, '5012', 'Cost of Goods Sold', NULL, 'Expense', 'Debit', 'active', 'Shipping and freight costs on purchases', 1, '2025-11-20 16:10:54', 'Freight In'),
(44, '5013', 'Other Income', NULL, 'Revenue', 'Credit', 'active', 'Discounts received from suppliers', 1, '2025-11-20 16:11:08', 'Purchase Discounts'),
(45, '1400', 'Other Current Asset', NULL, 'Asset', 'Debit', 'active', 'Canadian wheat inventory', 1, '2025-11-23 08:14:24', 'Inventory - Wheat - Canadian'),
(46, '1401', 'Other Current Asset', NULL, 'Asset', 'Debit', 'active', 'Russian wheat inventory', 1, '2025-11-23 08:14:24', 'Inventory - Wheat - Russian'),
(47, '1410', 'Other Current Asset', NULL, 'Asset', 'Debit', 'active', 'Goods in transit not yet received', 1, '2025-11-23 08:14:24', 'Inventory in Transit'),
(48, '1420', 'Other Current Asset', NULL, 'Asset', 'Debit', 'active', 'Advance payments to wheat suppliers', 1, '2025-11-23 08:14:24', 'Advance to Suppliers - Wheat'),
(49, '2110', 'Accounts Payable', NULL, 'Liability', 'Credit', 'active', 'Goods received but not yet invoiced', 1, '2025-11-23 08:14:24', 'GRN Pending - Wheat'),
(50, '2120', 'Accounts Payable', NULL, 'Liability', 'Credit', 'active', 'Payables to wheat suppliers', 1, '2025-11-23 08:14:24', 'Accounts Payable - Wheat Suppliers'),
(51, '5100', 'Expense', NULL, 'Expense', 'Debit', 'active', 'Losses due to weight variance in wheat delivery', 1, '2025-11-23 08:14:24', 'Inventory Loss - Weight Variance'),
(52, '5200', 'Cost of Goods Sold', NULL, 'Expense', 'Debit', 'active', 'Purchase of Canadian wheat', 1, '2025-11-23 08:14:24', 'Wheat Procurement - Canadian'),
(53, '5201', 'Cost of Goods Sold', NULL, 'Expense', 'Debit', 'active', 'Purchase of Russian wheat', 1, '2025-11-23 08:14:24', 'Wheat Procurement - Russian'),
(54, '4100', 'Other Income', NULL, 'Revenue', 'Credit', 'active', 'Gains from weight variance in wheat delivery', 1, '2025-11-23 08:14:24', 'Inventory Gain - Weight Variance');

-- --------------------------------------------------------

--
-- Table structure for table `credit_orders`
--

CREATE TABLE `credit_orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_number` varchar(50) NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `order_date` date NOT NULL,
  `required_date` date DEFAULT NULL,
  `order_type` enum('credit','advance_payment') NOT NULL DEFAULT 'credit',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `advance_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_due` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','pending_approval','approved','escalated','rejected','in_production','produced','ready_to_ship','shipped','delivered','cancelled') NOT NULL DEFAULT 'draft',
  `assigned_branch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `sort_order` int(11) DEFAULT 0 COMMENT 'Order position for drag & drop priority (0 = highest priority)',
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `approved_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `shipping_address` text DEFAULT NULL,
  `special_instructions` text DEFAULT NULL,
  `internal_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `total_weight_kg` decimal(10,2) DEFAULT 0.00,
  `requires_vehicle_type` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `credit_orders`
--

INSERT INTO `credit_orders` (`id`, `order_number`, `customer_id`, `order_date`, `required_date`, `order_type`, `subtotal`, `discount_amount`, `tax_amount`, `total_amount`, `advance_paid`, `balance_due`, `amount_paid`, `status`, `assigned_branch_id`, `priority`, `sort_order`, `created_by_user_id`, `approved_by_user_id`, `approved_at`, `shipping_address`, `special_instructions`, `internal_notes`, `created_at`, `updated_at`, `total_weight_kg`, `requires_vehicle_type`) VALUES
(14, 'CR-20251104-7928', 1, '2025-11-04', '2025-11-05', 'credit', 341000.00, 50000.00, 0.00, 291000.00, 0.00, 291000.00, 0.00, 'delivered', 2, 'normal', 5, 1, NULL, NULL, 'fuyhh', 'ytgu', NULL, '2025-11-03 19:32:07', '2025-11-23 11:33:18', 3400.00, NULL),
(15, 'CR-20251104-7505', 1, '2025-11-04', '2025-11-05', 'credit', 221500.00, 0.00, 0.00, 221500.00, 0.00, 221500.00, 0.00, 'shipped', 1, 'high', 4, 1, NULL, NULL, 'sdfdgf', 'gaff', NULL, '2025-11-04 16:27:06', '2025-11-16 15:52:57', 5000.00, NULL),
(16, 'CR-20251109-5587', 1, '2025-11-09', '2025-11-10', 'credit', 341000.00, 1000.00, 0.00, 340000.00, 0.00, 340000.00, 0.00, 'delivered', 2, 'urgent', 0, 6, NULL, NULL, 'mirpur, Dhaka', '', NULL, '2025-11-09 16:53:24', '2025-11-23 11:33:23', 0.00, NULL),
(17, 'CR-20251113-5446', 10, '2025-11-13', '2025-11-14', 'credit', 682000.00, 0.00, 0.00, 682000.00, 0.00, 682000.00, 0.00, 'delivered', 2, 'normal', 6, 1, NULL, NULL, 'Mirpur 2', '', NULL, '2025-11-12 20:01:53', '2025-11-23 11:33:37', 6800.00, NULL),
(18, 'CR-20251113-7046', 9, '2025-11-13', '2025-11-14', 'credit', 682000.00, 0.00, 0.00, 682000.00, 0.00, 682000.00, 0.00, 'delivered', 2, 'high', 3, 1, NULL, NULL, 'mirpur 12', '', NULL, '2025-11-12 20:04:00', '2025-11-16 15:52:57', 6800.00, NULL),
(19, 'CR-20251114-9017', 11, '2025-11-14', '2025-11-20', 'credit', 3410.00, 0.00, 0.00, 3410.00, 0.00, 3410.00, 0.00, 'approved', 2, 'high', 2, 1, NULL, NULL, 'mirpur', '', NULL, '2025-11-14 10:36:36', '2025-11-17 10:03:57', 34.00, NULL),
(20, 'INV-INITIAL-13-1763305691', 13, '2025-11-16', '2025-11-16', 'credit', 540000.00, 0.00, 0.00, 540000.00, 0.00, 340000.00, 100000.00, 'delivered', NULL, 'urgent', 1, 1, NULL, NULL, NULL, NULL, 'Opening balance - Previous due carried forward', '2025-11-16 15:08:11', '2025-11-16 15:52:57', 0.00, NULL),
(21, 'CR-20251117-7007', 7, '2025-11-17', '2025-11-24', 'credit', 22150.00, 0.00, 0.00, 22150.00, 50000.00, 22150.00, 0.00, 'delivered', 2, 'normal', 0, 6, NULL, NULL, 'Malibagh, Dhaka -1219', '', NULL, '2025-11-17 10:09:59', '2025-11-23 11:33:42', 500.00, NULL),
(22, 'CR-20251117-7742', 12, '2025-11-17', '2025-11-21', 'advance_payment', 3410.00, 50.00, 0.00, 3360.00, 0.00, 3360.00, 0.00, 'in_production', 1, 'normal', 0, 6, NULL, NULL, 'Bansree, Dhaka -1219', '', NULL, '2025-11-17 10:13:52', '2025-11-23 11:23:34', 34.00, NULL),
(23, 'CR-20251117-8813', 16, '2025-11-17', '2025-11-20', 'credit', 221500.00, 1000.00, 0.00, 220500.00, 60000.00, -220500.00, 220500.00, 'delivered', 2, 'normal', 0, 6, NULL, NULL, 'Puran Dhaka', '', NULL, '2025-11-17 10:52:25', '2025-11-24 11:35:55', 5000.00, NULL),
(24, 'CR-20251117-0351', 14, '2025-11-17', '2025-11-22', 'credit', 341000.00, 500.00, 0.00, 340500.00, 0.00, 340500.00, 0.00, 'in_production', 1, 'normal', 0, 6, NULL, NULL, 'Abul hotel , Malibagh', '', NULL, '2025-11-17 11:03:11', '2025-11-23 11:23:46', 3400.00, NULL),
(25, 'CR-20251122-9755', 17, '2025-11-22', '2025-11-25', 'credit', 11075.00, 50.00, 0.00, 11025.00, 0.00, 11025.00, 0.00, 'approved', 1, 'normal', 0, 6, NULL, NULL, 'Hosenpur', '', NULL, '2025-11-22 08:46:46', '2025-11-22 08:59:34', 250.00, NULL),
(26, 'CR-20251123-3566', 7, '2025-11-23', '2025-11-24', 'advance_payment', 78400.00, 5410.00, 0.00, 72990.00, 150000.00, -77010.00, 0.00, 'rejected', NULL, 'normal', 0, 6, NULL, NULL, 'Sharghat, hosenpur, Sirajganj', '', NULL, '2025-11-23 06:01:12', '2025-11-23 11:22:46', 1340.00, NULL),
(27, 'CR-20251123-5014', 7, '2025-11-23', '2025-11-25', 'credit', 68200.00, 200.00, 0.00, 68000.00, 5000000.00, -4932000.00, 0.00, 'approved', 1, 'normal', 0, 6, NULL, NULL, 'XYZ', '', NULL, '2025-11-23 11:20:11', '2025-11-23 11:23:03', 680.00, NULL),
(28, 'CR-20251123-2708', 18, '2025-11-23', '2025-11-25', 'credit', 17950.00, 0.00, 0.00, 17950.00, 0.00, 17950.00, 0.00, 'delivered', 2, 'normal', 0, 6, NULL, NULL, 'Malibag', '', NULL, '2025-11-23 11:53:16', '2025-11-24 09:08:47', 500.00, NULL),
(29, 'CR-20251124-3350', 14, '2025-11-24', '2025-11-26', 'credit', 1252500.00, 6000.00, 0.00, 1246500.00, 0.00, -1246500.00, 1246500.00, 'delivered', 2, 'normal', 0, 6, NULL, NULL, 'Shahabag, Dhaka', '', NULL, '2025-11-24 08:51:31', '2025-11-24 09:12:58', 30000.00, NULL),
(30, 'CR-20251124-7015', 14, '2025-11-24', '2025-11-25', 'credit', 206000.00, 1000.00, 0.00, 205000.00, 0.00, 205000.00, 0.00, 'delivered', 2, 'normal', 0, 6, NULL, NULL, 'Malibagh', '', NULL, '2025-11-24 09:26:57', '2025-11-24 10:36:20', 5000.00, NULL),
(31, 'INV-INITIAL-22-1763981794', 22, '2025-11-24', '2025-11-24', 'credit', 1000000.00, 0.00, 0.00, 1000000.00, 0.00, 1000000.00, 0.00, 'delivered', NULL, 'normal', 0, 2, NULL, NULL, NULL, NULL, 'Opening balance - Previous due carried forward', '2025-11-24 10:56:34', '2025-11-24 10:56:34', 0.00, NULL),
(32, 'CR-20251124-7814', 22, '2025-11-24', '2025-11-25', 'credit', 20600.00, 0.00, 0.00, 20600.00, 0.00, -20600.00, 20600.00, 'approved', 3, 'normal', 0, 2, NULL, NULL, '221B Baker Street, London.', '', NULL, '2025-11-24 10:59:08', '2025-11-24 11:32:59', 500.00, NULL);

--
-- Triggers `credit_orders`
--
DELIMITER $$
CREATE TRIGGER `tr_credit_orders_after_update` AFTER UPDATE ON `credit_orders` FOR EACH ROW BEGIN
    DECLARE change_json TEXT;
    DECLARE current_user_id BIGINT;
    
    -- Try to get current user from session (this would need to be set by application)
    -- For now, we'll use the created_by_user_id as fallback
    SET current_user_id = NEW.created_by_user_id;
    
    -- Build changes JSON
    SET change_json = JSON_OBJECT(
        'order_number', NEW.order_number,
        'status_changed', IF(OLD.status != NEW.status, JSON_OBJECT('from', OLD.status, 'to', NEW.status), NULL),
        'priority_changed', IF(OLD.priority != NEW.priority, JSON_OBJECT('from', OLD.priority, 'to', NEW.priority), NULL),
        'sort_order_changed', IF(OLD.sort_order != NEW.sort_order, JSON_OBJECT('from', OLD.sort_order, 'to', NEW.sort_order), NULL),
        'amount_changed', IF(OLD.total_amount != NEW.total_amount, JSON_OBJECT('from', OLD.total_amount, 'to', NEW.total_amount), NULL),
        'payment_changed', IF(OLD.amount_paid != NEW.amount_paid, JSON_OBJECT('from', OLD.amount_paid, 'to', NEW.amount_paid), NULL)
    );
    
    -- Log status changes
    IF OLD.status != NEW.status THEN
        INSERT INTO credit_order_audit (order_id, user_id, action_type, field_name, old_value, new_value, changes_json)
        VALUES (NEW.id, current_user_id, 'status_changed', 'status', OLD.status, NEW.status, change_json);
    END IF;
    
    -- Log priority changes
    IF OLD.priority != NEW.priority OR OLD.sort_order != NEW.sort_order THEN
        INSERT INTO credit_order_audit (order_id, user_id, action_type, field_name, old_value, new_value, changes_json)
        VALUES (NEW.id, current_user_id, 'priority_changed', 'priority', 
                CONCAT(OLD.priority, ' (', OLD.sort_order, ')'), 
                CONCAT(NEW.priority, ' (', NEW.sort_order, ')'), 
                change_json);
    END IF;
    
    -- Log payment changes
    IF OLD.amount_paid != NEW.amount_paid THEN
        INSERT INTO credit_order_audit (order_id, user_id, action_type, field_name, old_value, new_value, changes_json)
        VALUES (NEW.id, current_user_id, 'payment_collected', 'amount_paid', OLD.amount_paid, NEW.amount_paid, change_json);
    END IF;
    
    -- Log general updates
    IF OLD.total_amount != NEW.total_amount OR OLD.subtotal != NEW.subtotal THEN
        INSERT INTO credit_order_audit (order_id, user_id, action_type, field_name, old_value, new_value, changes_json)
        VALUES (NEW.id, current_user_id, 'updated', 'totals', 
                CONCAT('subtotal:', OLD.subtotal, ' total:', OLD.total_amount), 
                CONCAT('subtotal:', NEW.subtotal, ' total:', NEW.total_amount), 
                change_json);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `credit_order_audit`
--

CREATE TABLE `credit_order_audit` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `action_type` enum('created','updated','status_changed','priority_changed','payment_collected','cancelled','deleted') NOT NULL,
  `field_name` varchar(100) DEFAULT NULL COMMENT 'Name of field that was changed',
  `old_value` text DEFAULT NULL COMMENT 'Previous value',
  `new_value` text DEFAULT NULL COMMENT 'New value',
  `changes_json` text DEFAULT NULL COMMENT 'JSON object of all changes',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for all credit order changes';

--
-- Dumping data for table `credit_order_audit`
--

INSERT INTO `credit_order_audit` (`id`, `order_id`, `user_id`, `action_type`, `field_name`, `old_value`, `new_value`, `changes_json`, `ip_address`, `user_agent`, `notes`, `created_at`) VALUES
(1, 5, 1, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251101-1900\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 05:05:13'),
(2, 5, 1, 'status_changed', 'status', 'approved', 'in_production', '{\"order_number\": \"CR-20251101-1900\", \"status_changed\": {\"from\": \"approved\", \"to\": \"in_production\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 05:06:08'),
(3, 5, 1, 'status_changed', 'status', 'in_production', 'produced', '{\"order_number\": \"CR-20251101-1900\", \"status_changed\": {\"from\": \"in_production\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 05:06:12'),
(4, 4, 6, 'payment_collected', 'amount_paid', '185404.00', '190404.00', '{\"order_number\": \"CR-20251030-2349\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": {\"from\": 185404.00, \"to\": 190404.00}}', NULL, NULL, NULL, '2025-11-01 07:23:54'),
(5, 6, 1, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251101-9073\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 08:33:05'),
(6, 7, 1, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251101-7172\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 08:33:13'),
(7, 8, 6, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251101-3242\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 08:33:20'),
(8, 9, 6, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251101-8979\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 08:33:26'),
(9, 10, 6, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251101-4477\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 08:33:37'),
(10, 6, 1, 'status_changed', 'status', 'approved', 'in_production', '{\"order_number\": \"CR-20251101-9073\", \"status_changed\": {\"from\": \"approved\", \"to\": \"in_production\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 08:38:23'),
(11, 6, 1, 'status_changed', 'status', 'in_production', 'produced', '{\"order_number\": \"CR-20251101-9073\", \"status_changed\": {\"from\": \"in_production\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 08:38:31'),
(12, 5, 1, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251101-1900\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 08:38:33'),
(13, 5, 1, 'status_changed', 'status', 'ready_to_ship', 'shipped', '{\"order_number\": \"CR-20251101-1900\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"shipped\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 08:40:25'),
(14, 9, 6, 'priority_changed', 'priority', 'normal (0)', 'urgent (0)', '{\"order_number\": \"CR-20251101-8979\", \"status_changed\": null, \"priority_changed\": {\"from\": \"normal\", \"to\": \"urgent\"}, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 09:37:23'),
(15, 9, 1, 'priority_changed', 'sort_order', NULL, '0', NULL, NULL, NULL, 'Reordered to position 0 with priority urgent', '2025-11-01 09:37:23'),
(16, 10, 6, 'priority_changed', 'priority', 'normal (0)', 'urgent (1)', '{\"order_number\": \"CR-20251101-4477\", \"status_changed\": null, \"priority_changed\": {\"from\": \"normal\", \"to\": \"urgent\"}, \"sort_order_changed\": {\"from\": 0, \"to\": 1}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 09:37:23'),
(17, 10, 1, 'priority_changed', 'sort_order', NULL, '1', NULL, NULL, NULL, 'Reordered to position 1 with priority urgent', '2025-11-01 09:37:23'),
(18, 8, 6, 'priority_changed', 'priority', 'normal (0)', 'high (2)', '{\"order_number\": \"CR-20251101-3242\", \"status_changed\": null, \"priority_changed\": {\"from\": \"normal\", \"to\": \"high\"}, \"sort_order_changed\": {\"from\": 0, \"to\": 2}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 09:37:23'),
(19, 8, 1, 'priority_changed', 'sort_order', NULL, '2', NULL, NULL, NULL, 'Reordered to position 2 with priority high', '2025-11-01 09:37:23'),
(20, 7, 1, 'priority_changed', 'priority', 'normal (0)', 'high (3)', '{\"order_number\": \"CR-20251101-7172\", \"status_changed\": null, \"priority_changed\": {\"from\": \"normal\", \"to\": \"high\"}, \"sort_order_changed\": {\"from\": 0, \"to\": 3}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 09:37:23'),
(21, 7, 1, 'priority_changed', 'sort_order', NULL, '3', NULL, NULL, NULL, 'Reordered to position 3 with priority high', '2025-11-01 09:37:23'),
(22, 6, 1, 'priority_changed', 'priority', 'normal (0)', 'high (4)', '{\"order_number\": \"CR-20251101-9073\", \"status_changed\": null, \"priority_changed\": {\"from\": \"normal\", \"to\": \"high\"}, \"sort_order_changed\": {\"from\": 0, \"to\": 4}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 09:37:23'),
(23, 6, 1, 'priority_changed', 'sort_order', NULL, '4', NULL, NULL, NULL, 'Reordered to position 4 with priority high', '2025-11-01 09:37:23'),
(24, 5, 1, 'priority_changed', 'priority', 'normal (0)', 'normal (5)', '{\"order_number\": \"CR-20251101-1900\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 0, \"to\": 5}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 09:37:23'),
(25, 5, 1, 'priority_changed', 'sort_order', NULL, '5', NULL, NULL, NULL, 'Reordered to position 5 with priority normal', '2025-11-01 09:37:23'),
(26, 10, 6, 'priority_changed', 'priority', 'urgent (1)', 'urgent (0)', '{\"order_number\": \"CR-20251101-4477\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 1, \"to\": 0}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 09:42:04'),
(27, 10, 1, 'priority_changed', 'sort_order', NULL, '0', NULL, NULL, NULL, 'Reordered to position 0 with priority urgent', '2025-11-01 09:42:04'),
(28, 8, 6, 'priority_changed', 'priority', 'high (2)', 'urgent (1)', '{\"order_number\": \"CR-20251101-3242\", \"status_changed\": null, \"priority_changed\": {\"from\": \"high\", \"to\": \"urgent\"}, \"sort_order_changed\": {\"from\": 2, \"to\": 1}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 09:42:04'),
(29, 8, 1, 'priority_changed', 'sort_order', NULL, '1', NULL, NULL, NULL, 'Reordered to position 1 with priority urgent', '2025-11-01 09:42:04'),
(30, 7, 1, 'priority_changed', 'priority', 'high (3)', 'high (2)', '{\"order_number\": \"CR-20251101-7172\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 3, \"to\": 2}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 09:42:04'),
(31, 7, 1, 'priority_changed', 'sort_order', NULL, '2', NULL, NULL, NULL, 'Reordered to position 2 with priority high', '2025-11-01 09:42:04'),
(32, 6, 1, 'priority_changed', 'priority', 'high (4)', 'high (3)', '{\"order_number\": \"CR-20251101-9073\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 4, \"to\": 3}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 09:42:04'),
(33, 6, 1, 'priority_changed', 'sort_order', NULL, '3', NULL, NULL, NULL, 'Reordered to position 3 with priority high', '2025-11-01 09:42:04'),
(34, 9, 6, 'priority_changed', 'priority', 'urgent (0)', 'high (4)', '{\"order_number\": \"CR-20251101-8979\", \"status_changed\": null, \"priority_changed\": {\"from\": \"urgent\", \"to\": \"high\"}, \"sort_order_changed\": {\"from\": 0, \"to\": 4}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 09:42:04'),
(35, 9, 1, 'priority_changed', 'sort_order', NULL, '4', NULL, NULL, NULL, 'Reordered to position 4 with priority high', '2025-11-01 09:42:04'),
(36, 5, 1, 'priority_changed', 'sort_order', NULL, '5', NULL, NULL, NULL, 'Reordered to position 5 with priority normal', '2025-11-01 09:42:04'),
(37, 8, 6, 'priority_changed', 'priority', 'urgent (1)', 'urgent (0)', '{\"order_number\": \"CR-20251101-3242\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 1, \"to\": 0}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 09:42:18'),
(38, 8, 1, 'priority_changed', 'sort_order', NULL, '0', NULL, NULL, NULL, 'Reordered to position 0 with priority urgent', '2025-11-01 09:42:18'),
(39, 10, 6, 'priority_changed', 'priority', 'urgent (0)', 'urgent (1)', '{\"order_number\": \"CR-20251101-4477\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 0, \"to\": 1}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 09:42:18'),
(40, 10, 1, 'priority_changed', 'sort_order', NULL, '1', NULL, NULL, NULL, 'Reordered to position 1 with priority urgent', '2025-11-01 09:42:18'),
(41, 7, 1, 'priority_changed', 'sort_order', NULL, '2', NULL, NULL, NULL, 'Reordered to position 2 with priority high', '2025-11-01 09:42:18'),
(42, 6, 1, 'priority_changed', 'sort_order', NULL, '3', NULL, NULL, NULL, 'Reordered to position 3 with priority high', '2025-11-01 09:42:18'),
(43, 9, 1, 'priority_changed', 'sort_order', NULL, '4', NULL, NULL, NULL, 'Reordered to position 4 with priority high', '2025-11-01 09:42:18'),
(44, 5, 1, 'priority_changed', 'sort_order', NULL, '5', NULL, NULL, NULL, 'Reordered to position 5 with priority normal', '2025-11-01 09:42:18'),
(45, 11, 1, 'payment_collected', 'amount_paid', '0.00', '2345566.00', '{\"order_number\": \"INV-INITIAL-11-1761995873\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": {\"from\": 0.00, \"to\": 2345566.00}}', NULL, NULL, NULL, '2025-11-01 11:18:58'),
(46, 11, 1, 'payment_collected', 'amount_paid', '2345566.00', '2910220.00', '{\"order_number\": \"INV-INITIAL-11-1761995873\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": {\"from\": 2345566.00, \"to\": 2910220.00}}', NULL, NULL, NULL, '2025-11-01 11:21:16'),
(47, 7, 1, 'priority_changed', 'priority', 'high (2)', 'urgent (0)', '{\"order_number\": \"CR-20251101-7172\", \"status_changed\": null, \"priority_changed\": {\"from\": \"high\", \"to\": \"urgent\"}, \"sort_order_changed\": {\"from\": 2, \"to\": 0}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 12:08:53'),
(48, 7, 1, 'priority_changed', 'sort_order', NULL, '0', NULL, NULL, NULL, 'Reordered to position 0 with priority urgent', '2025-11-01 12:08:53'),
(49, 10, 1, 'priority_changed', 'sort_order', NULL, '1', NULL, NULL, NULL, 'Reordered to position 1 with priority urgent', '2025-11-01 12:08:53'),
(50, 6, 1, 'priority_changed', 'priority', 'high (3)', 'high (2)', '{\"order_number\": \"CR-20251101-9073\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 3, \"to\": 2}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 12:08:53'),
(51, 6, 1, 'priority_changed', 'sort_order', NULL, '2', NULL, NULL, NULL, 'Reordered to position 2 with priority high', '2025-11-01 12:08:53'),
(52, 9, 6, 'priority_changed', 'priority', 'high (4)', 'high (3)', '{\"order_number\": \"CR-20251101-8979\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 4, \"to\": 3}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 12:08:53'),
(53, 9, 1, 'priority_changed', 'sort_order', NULL, '3', NULL, NULL, NULL, 'Reordered to position 3 with priority high', '2025-11-01 12:08:53'),
(54, 5, 1, 'priority_changed', 'priority', 'normal (5)', 'high (4)', '{\"order_number\": \"CR-20251101-1900\", \"status_changed\": null, \"priority_changed\": {\"from\": \"normal\", \"to\": \"high\"}, \"sort_order_changed\": {\"from\": 5, \"to\": 4}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 12:08:53'),
(55, 5, 1, 'priority_changed', 'sort_order', NULL, '4', NULL, NULL, NULL, 'Reordered to position 4 with priority high', '2025-11-01 12:08:53'),
(56, 8, 6, 'priority_changed', 'priority', 'urgent (0)', 'normal (5)', '{\"order_number\": \"CR-20251101-3242\", \"status_changed\": null, \"priority_changed\": {\"from\": \"urgent\", \"to\": \"normal\"}, \"sort_order_changed\": {\"from\": 0, \"to\": 5}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 12:08:53'),
(57, 8, 1, 'priority_changed', 'sort_order', NULL, '5', NULL, NULL, NULL, 'Reordered to position 5 with priority normal', '2025-11-01 12:08:53'),
(58, 11, 1, 'priority_changed', 'priority', 'normal (0)', 'normal (6)', '{\"order_number\": \"INV-INITIAL-11-1761995873\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 0, \"to\": 6}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-01 12:08:53'),
(59, 11, 1, 'priority_changed', 'sort_order', NULL, '6', NULL, NULL, NULL, 'Reordered to position 6 with priority normal', '2025-11-01 12:08:53'),
(60, 12, 1, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251104-7753\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-03 19:26:11'),
(61, 13, 1, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251104-2848\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-03 19:32:33'),
(62, 14, 1, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251104-7928\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-03 19:32:44'),
(63, 8, 6, 'status_changed', 'status', 'approved', 'in_production', '{\"order_number\": \"CR-20251101-3242\", \"status_changed\": {\"from\": \"approved\", \"to\": \"in_production\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-03 19:35:18'),
(64, 8, 6, 'status_changed', 'status', 'in_production', 'produced', '{\"order_number\": \"CR-20251101-3242\", \"status_changed\": {\"from\": \"in_production\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-03 19:35:21'),
(65, 6, 1, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251101-9073\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-03 19:35:29'),
(66, 6, 1, 'status_changed', 'status', 'ready_to_ship', 'shipped', '{\"order_number\": \"CR-20251101-9073\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"shipped\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-03 19:36:16'),
(67, 3, 6, 'status_changed', 'status', 'shipped', 'delivered', '{\"order_number\": \"CR-20251028-6227\", \"status_changed\": {\"from\": \"shipped\", \"to\": \"delivered\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-03 19:36:47'),
(68, 15, 1, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251104-7505\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-04 16:27:24'),
(69, 7, 1, 'status_changed', 'status', 'approved', 'in_production', '{\"order_number\": \"CR-20251101-7172\", \"status_changed\": {\"from\": \"approved\", \"to\": \"in_production\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-04 16:27:40'),
(70, 7, 1, 'status_changed', 'status', 'in_production', 'produced', '{\"order_number\": \"CR-20251101-7172\", \"status_changed\": {\"from\": \"in_production\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-04 16:27:44'),
(71, 7, 1, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251101-7172\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-04 16:27:47'),
(72, 8, 6, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251101-3242\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-04 16:27:51'),
(73, 15, 1, 'status_changed', 'status', 'approved', 'in_production', '{\"order_number\": \"CR-20251104-7505\", \"status_changed\": {\"from\": \"approved\", \"to\": \"in_production\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-04 16:55:01'),
(74, 15, 1, 'status_changed', 'status', 'in_production', 'produced', '{\"order_number\": \"CR-20251104-7505\", \"status_changed\": {\"from\": \"in_production\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-04 16:55:05'),
(75, 7, 1, 'status_changed', 'status', 'ready_to_ship', 'shipped', '{\"order_number\": \"CR-20251101-7172\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"shipped\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-04 17:17:58'),
(76, 8, 6, 'status_changed', 'status', 'ready_to_ship', 'shipped', '{\"order_number\": \"CR-20251101-3242\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"shipped\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-08 17:19:12'),
(79, 16, 6, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251109-5587\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-09 16:54:02'),
(80, 14, 1, 'status_changed', 'status', 'approved', 'in_production', '{\"order_number\": \"CR-20251104-7928\", \"status_changed\": {\"from\": \"approved\", \"to\": \"in_production\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-09 16:54:28'),
(81, 14, 1, 'status_changed', 'status', 'in_production', 'produced', '{\"order_number\": \"CR-20251104-7928\", \"status_changed\": {\"from\": \"in_production\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-09 16:54:32'),
(85, 16, 6, 'status_changed', 'status', 'approved', 'in_production', '{\"order_number\": \"CR-20251109-5587\", \"status_changed\": {\"from\": \"approved\", \"to\": \"in_production\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-09 16:54:47'),
(86, 16, 6, 'status_changed', 'status', 'in_production', 'produced', '{\"order_number\": \"CR-20251109-5587\", \"status_changed\": {\"from\": \"in_production\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-09 16:54:50'),
(91, 15, 1, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251104-7505\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-09 17:08:54'),
(92, 16, 6, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251109-5587\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-09 17:09:13'),
(93, 14, 1, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251104-7928\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-09 17:09:19'),
(94, 14, 1, 'status_changed', 'status', 'ready_to_ship', 'produced', '{\"order_number\": \"CR-20251104-7928\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-09 17:23:45'),
(95, 15, 1, 'status_changed', 'status', 'ready_to_ship', 'produced', '{\"order_number\": \"CR-20251104-7505\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-09 17:23:50'),
(96, 16, 6, 'status_changed', 'status', 'ready_to_ship', 'produced', '{\"order_number\": \"CR-20251109-5587\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-09 17:23:54'),
(97, 14, 1, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251104-7928\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-09 17:24:23'),
(98, 16, 6, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251109-5587\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-09 17:24:28'),
(99, 14, 1, 'status_changed', 'status', 'ready_to_ship', 'shipped', '{\"order_number\": \"CR-20251104-7928\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"shipped\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-09 17:53:21'),
(100, 16, 6, 'status_changed', 'status', 'ready_to_ship', 'shipped', '{\"order_number\": \"CR-20251109-5587\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"shipped\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-10 17:40:08'),
(101, 15, 1, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251104-7505\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-10 17:41:20'),
(102, 15, 1, 'status_changed', 'status', 'ready_to_ship', 'shipped', '{\"order_number\": \"CR-20251104-7505\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"shipped\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-10 17:43:55'),
(103, 15, 1, 'priority_changed', 'priority', 'normal (0)', 'urgent (0)', '{\"order_number\": \"CR-20251104-7505\", \"status_changed\": null, \"priority_changed\": {\"from\": \"normal\", \"to\": \"urgent\"}, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-12 19:49:57'),
(104, 15, 1, 'priority_changed', 'sort_order', NULL, '0', NULL, NULL, NULL, 'Reordered to position 0 with priority urgent', '2025-11-12 19:49:57'),
(105, 16, 6, 'priority_changed', 'priority', 'normal (0)', 'urgent (1)', '{\"order_number\": \"CR-20251109-5587\", \"status_changed\": null, \"priority_changed\": {\"from\": \"normal\", \"to\": \"urgent\"}, \"sort_order_changed\": {\"from\": 0, \"to\": 1}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-12 19:49:57'),
(106, 16, 1, 'priority_changed', 'sort_order', NULL, '1', NULL, NULL, NULL, 'Reordered to position 1 with priority urgent', '2025-11-12 19:49:57'),
(107, 14, 1, 'priority_changed', 'priority', 'normal (0)', 'high (2)', '{\"order_number\": \"CR-20251104-7928\", \"status_changed\": null, \"priority_changed\": {\"from\": \"normal\", \"to\": \"high\"}, \"sort_order_changed\": {\"from\": 0, \"to\": 2}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-12 19:49:57'),
(108, 14, 1, 'priority_changed', 'sort_order', NULL, '2', NULL, NULL, NULL, 'Reordered to position 2 with priority high', '2025-11-12 19:49:57'),
(109, 15, 1, 'priority_changed', 'sort_order', NULL, '0', NULL, NULL, NULL, 'Reordered to position 0 with priority urgent', '2025-11-12 19:50:11'),
(110, 16, 1, 'priority_changed', 'sort_order', NULL, '1', NULL, NULL, NULL, 'Reordered to position 1 with priority urgent', '2025-11-12 19:50:11'),
(111, 14, 1, 'priority_changed', 'sort_order', NULL, '2', NULL, NULL, NULL, 'Reordered to position 2 with priority high', '2025-11-12 19:50:11'),
(112, 14, 1, 'priority_changed', 'priority', 'high (2)', 'urgent (0)', '{\"order_number\": \"CR-20251104-7928\", \"status_changed\": null, \"priority_changed\": {\"from\": \"high\", \"to\": \"urgent\"}, \"sort_order_changed\": {\"from\": 2, \"to\": 0}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-12 19:50:23'),
(113, 14, 1, 'priority_changed', 'sort_order', NULL, '0', NULL, NULL, NULL, 'Reordered to position 0 with priority urgent', '2025-11-12 19:50:23'),
(114, 16, 1, 'priority_changed', 'sort_order', NULL, '1', NULL, NULL, NULL, 'Reordered to position 1 with priority urgent', '2025-11-12 19:50:23'),
(115, 15, 1, 'priority_changed', 'priority', 'urgent (0)', 'high (2)', '{\"order_number\": \"CR-20251104-7505\", \"status_changed\": null, \"priority_changed\": {\"from\": \"urgent\", \"to\": \"high\"}, \"sort_order_changed\": {\"from\": 0, \"to\": 2}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-12 19:50:23'),
(116, 15, 1, 'priority_changed', 'sort_order', NULL, '2', NULL, NULL, NULL, 'Reordered to position 2 with priority high', '2025-11-12 19:50:23'),
(117, 17, 1, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251113-5446\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-12 20:02:14'),
(118, 17, 1, 'status_changed', 'status', 'approved', 'in_production', '{\"order_number\": \"CR-20251113-5446\", \"status_changed\": {\"from\": \"approved\", \"to\": \"in_production\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-12 20:02:28'),
(119, 17, 1, 'status_changed', 'status', 'in_production', 'produced', '{\"order_number\": \"CR-20251113-5446\", \"status_changed\": {\"from\": \"in_production\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-12 20:02:32'),
(120, 17, 1, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251113-5446\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-12 20:02:36'),
(121, 17, 1, 'status_changed', 'status', 'ready_to_ship', 'shipped', '{\"order_number\": \"CR-20251113-5446\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"shipped\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-12 20:03:02'),
(122, 18, 1, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251113-7046\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-12 20:04:15'),
(123, 18, 1, 'status_changed', 'status', 'approved', 'in_production', '{\"order_number\": \"CR-20251113-7046\", \"status_changed\": {\"from\": \"approved\", \"to\": \"in_production\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-12 20:04:25'),
(124, 18, 1, 'status_changed', 'status', 'in_production', 'produced', '{\"order_number\": \"CR-20251113-7046\", \"status_changed\": {\"from\": \"in_production\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-12 20:04:29'),
(125, 18, 1, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251113-7046\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-12 20:04:37'),
(126, 18, 1, 'status_changed', 'status', 'ready_to_ship', 'shipped', '{\"order_number\": \"CR-20251113-7046\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"shipped\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-12 20:06:04'),
(127, 18, 1, 'status_changed', 'status', 'shipped', 'delivered', '{\"order_number\": \"CR-20251113-7046\", \"status_changed\": {\"from\": \"shipped\", \"to\": \"delivered\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 13:48:33'),
(128, 20, 1, 'payment_collected', 'amount_paid', '0.00', '100000.00', '{\"order_number\": \"INV-INITIAL-13-1763305691\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": {\"from\": 0.00, \"to\": 100000.00}}', NULL, NULL, NULL, '2025-11-16 15:08:59'),
(129, 15, 1, 'priority_changed', 'priority', 'high (2)', 'urgent (0)', '{\"order_number\": \"CR-20251104-7505\", \"status_changed\": null, \"priority_changed\": {\"from\": \"high\", \"to\": \"urgent\"}, \"sort_order_changed\": {\"from\": 2, \"to\": 0}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:51:46'),
(130, 15, 1, 'priority_changed', 'sort_order', NULL, '0', NULL, NULL, NULL, 'Reordered to position 0 with priority urgent', '2025-11-16 15:51:46'),
(131, 14, 1, 'priority_changed', 'priority', 'urgent (0)', 'urgent (1)', '{\"order_number\": \"CR-20251104-7928\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 0, \"to\": 1}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:51:46'),
(132, 14, 1, 'priority_changed', 'sort_order', NULL, '1', NULL, NULL, NULL, 'Reordered to position 1 with priority urgent', '2025-11-16 15:51:46'),
(133, 16, 6, 'priority_changed', 'priority', 'urgent (1)', 'high (2)', '{\"order_number\": \"CR-20251109-5587\", \"status_changed\": null, \"priority_changed\": {\"from\": \"urgent\", \"to\": \"high\"}, \"sort_order_changed\": {\"from\": 1, \"to\": 2}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:51:46'),
(134, 16, 1, 'priority_changed', 'sort_order', NULL, '2', NULL, NULL, NULL, 'Reordered to position 2 with priority high', '2025-11-16 15:51:46'),
(135, 20, 1, 'priority_changed', 'priority', 'normal (0)', 'high (3)', '{\"order_number\": \"INV-INITIAL-13-1763305691\", \"status_changed\": null, \"priority_changed\": {\"from\": \"normal\", \"to\": \"high\"}, \"sort_order_changed\": {\"from\": 0, \"to\": 3}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:51:46'),
(136, 20, 1, 'priority_changed', 'sort_order', NULL, '3', NULL, NULL, NULL, 'Reordered to position 3 with priority high', '2025-11-16 15:51:46'),
(137, 19, 1, 'priority_changed', 'priority', 'normal (0)', 'high (4)', '{\"order_number\": \"CR-20251114-9017\", \"status_changed\": null, \"priority_changed\": {\"from\": \"normal\", \"to\": \"high\"}, \"sort_order_changed\": {\"from\": 0, \"to\": 4}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:51:46'),
(138, 19, 1, 'priority_changed', 'sort_order', NULL, '4', NULL, NULL, NULL, 'Reordered to position 4 with priority high', '2025-11-16 15:51:46'),
(139, 18, 1, 'priority_changed', 'priority', 'normal (0)', 'normal (5)', '{\"order_number\": \"CR-20251113-7046\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 0, \"to\": 5}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:51:46'),
(140, 18, 1, 'priority_changed', 'sort_order', NULL, '5', NULL, NULL, NULL, 'Reordered to position 5 with priority normal', '2025-11-16 15:51:46'),
(141, 17, 1, 'priority_changed', 'priority', 'normal (0)', 'normal (6)', '{\"order_number\": \"CR-20251113-5446\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 0, \"to\": 6}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:51:46'),
(142, 17, 1, 'priority_changed', 'sort_order', NULL, '6', NULL, NULL, NULL, 'Reordered to position 6 with priority normal', '2025-11-16 15:51:46'),
(143, 14, 1, 'priority_changed', 'priority', 'urgent (1)', 'urgent (0)', '{\"order_number\": \"CR-20251104-7928\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 1, \"to\": 0}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:52:38'),
(144, 14, 1, 'priority_changed', 'sort_order', NULL, '0', NULL, NULL, NULL, 'Reordered to position 0 with priority urgent', '2025-11-16 15:52:38'),
(145, 16, 6, 'priority_changed', 'priority', 'high (2)', 'urgent (1)', '{\"order_number\": \"CR-20251109-5587\", \"status_changed\": null, \"priority_changed\": {\"from\": \"high\", \"to\": \"urgent\"}, \"sort_order_changed\": {\"from\": 2, \"to\": 1}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:52:38'),
(146, 16, 1, 'priority_changed', 'sort_order', NULL, '1', NULL, NULL, NULL, 'Reordered to position 1 with priority urgent', '2025-11-16 15:52:38'),
(147, 20, 1, 'priority_changed', 'priority', 'high (3)', 'high (2)', '{\"order_number\": \"INV-INITIAL-13-1763305691\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 3, \"to\": 2}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:52:38'),
(148, 20, 1, 'priority_changed', 'sort_order', NULL, '2', NULL, NULL, NULL, 'Reordered to position 2 with priority high', '2025-11-16 15:52:38'),
(149, 19, 1, 'priority_changed', 'priority', 'high (4)', 'high (3)', '{\"order_number\": \"CR-20251114-9017\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 4, \"to\": 3}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:52:38'),
(150, 19, 1, 'priority_changed', 'sort_order', NULL, '3', NULL, NULL, NULL, 'Reordered to position 3 with priority high', '2025-11-16 15:52:38'),
(151, 18, 1, 'priority_changed', 'priority', 'normal (5)', 'high (4)', '{\"order_number\": \"CR-20251113-7046\", \"status_changed\": null, \"priority_changed\": {\"from\": \"normal\", \"to\": \"high\"}, \"sort_order_changed\": {\"from\": 5, \"to\": 4}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:52:38'),
(152, 18, 1, 'priority_changed', 'sort_order', NULL, '4', NULL, NULL, NULL, 'Reordered to position 4 with priority high', '2025-11-16 15:52:38'),
(153, 15, 1, 'priority_changed', 'priority', 'urgent (0)', 'normal (5)', '{\"order_number\": \"CR-20251104-7505\", \"status_changed\": null, \"priority_changed\": {\"from\": \"urgent\", \"to\": \"normal\"}, \"sort_order_changed\": {\"from\": 0, \"to\": 5}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:52:38'),
(154, 15, 1, 'priority_changed', 'sort_order', NULL, '5', NULL, NULL, NULL, 'Reordered to position 5 with priority normal', '2025-11-16 15:52:38'),
(155, 17, 1, 'priority_changed', 'sort_order', NULL, '6', NULL, NULL, NULL, 'Reordered to position 6 with priority normal', '2025-11-16 15:52:38'),
(156, 16, 6, 'priority_changed', 'priority', 'urgent (1)', 'urgent (0)', '{\"order_number\": \"CR-20251109-5587\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 1, \"to\": 0}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:52:57'),
(157, 16, 1, 'priority_changed', 'sort_order', NULL, '0', NULL, NULL, NULL, 'Reordered to position 0 with priority urgent', '2025-11-16 15:52:57'),
(158, 20, 1, 'priority_changed', 'priority', 'high (2)', 'urgent (1)', '{\"order_number\": \"INV-INITIAL-13-1763305691\", \"status_changed\": null, \"priority_changed\": {\"from\": \"high\", \"to\": \"urgent\"}, \"sort_order_changed\": {\"from\": 2, \"to\": 1}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:52:57'),
(159, 20, 1, 'priority_changed', 'sort_order', NULL, '1', NULL, NULL, NULL, 'Reordered to position 1 with priority urgent', '2025-11-16 15:52:57'),
(160, 19, 1, 'priority_changed', 'priority', 'high (3)', 'high (2)', '{\"order_number\": \"CR-20251114-9017\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 3, \"to\": 2}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:52:57'),
(161, 19, 1, 'priority_changed', 'sort_order', NULL, '2', NULL, NULL, NULL, 'Reordered to position 2 with priority high', '2025-11-16 15:52:57'),
(162, 18, 1, 'priority_changed', 'priority', 'high (4)', 'high (3)', '{\"order_number\": \"CR-20251113-7046\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": {\"from\": 4, \"to\": 3}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:52:57'),
(163, 18, 1, 'priority_changed', 'sort_order', NULL, '3', NULL, NULL, NULL, 'Reordered to position 3 with priority high', '2025-11-16 15:52:57'),
(164, 15, 1, 'priority_changed', 'priority', 'normal (5)', 'high (4)', '{\"order_number\": \"CR-20251104-7505\", \"status_changed\": null, \"priority_changed\": {\"from\": \"normal\", \"to\": \"high\"}, \"sort_order_changed\": {\"from\": 5, \"to\": 4}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:52:57'),
(165, 15, 1, 'priority_changed', 'sort_order', NULL, '4', NULL, NULL, NULL, 'Reordered to position 4 with priority high', '2025-11-16 15:52:57'),
(166, 14, 1, 'priority_changed', 'priority', 'urgent (0)', 'normal (5)', '{\"order_number\": \"CR-20251104-7928\", \"status_changed\": null, \"priority_changed\": {\"from\": \"urgent\", \"to\": \"normal\"}, \"sort_order_changed\": {\"from\": 0, \"to\": 5}, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-16 15:52:57'),
(167, 14, 1, 'priority_changed', 'sort_order', NULL, '5', NULL, NULL, NULL, 'Reordered to position 5 with priority normal', '2025-11-16 15:52:57'),
(168, 17, 1, 'priority_changed', 'sort_order', NULL, '6', NULL, NULL, NULL, 'Reordered to position 6 with priority normal', '2025-11-16 15:52:57'),
(169, 19, 1, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251114-9017\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-17 10:03:57'),
(170, 22, 6, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251117-7742\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-17 10:30:53'),
(171, 23, 6, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251117-8813\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-17 11:10:08'),
(172, 24, 6, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251117-0351\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-17 11:10:22'),
(173, 23, 6, 'status_changed', 'status', 'approved', 'in_production', '{\"order_number\": \"CR-20251117-8813\", \"status_changed\": {\"from\": \"approved\", \"to\": \"in_production\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-17 11:12:46'),
(174, 25, 6, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251122-9755\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-22 08:59:34'),
(175, 23, 6, 'status_changed', 'status', 'in_production', 'produced', '{\"order_number\": \"CR-20251117-8813\", \"status_changed\": {\"from\": \"in_production\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 05:29:28'),
(176, 23, 6, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251117-8813\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 08:23:28'),
(177, 21, 6, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251117-7007\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 11:22:34'),
(178, 26, 6, 'status_changed', 'status', 'pending_approval', 'rejected', '{\"order_number\": \"CR-20251123-3566\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"rejected\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 11:22:46'),
(179, 27, 6, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251123-5014\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 11:23:03'),
(180, 22, 6, 'status_changed', 'status', 'approved', 'in_production', '{\"order_number\": \"CR-20251117-7742\", \"status_changed\": {\"from\": \"approved\", \"to\": \"in_production\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 11:23:34'),
(181, 24, 6, 'status_changed', 'status', 'approved', 'in_production', '{\"order_number\": \"CR-20251117-0351\", \"status_changed\": {\"from\": \"approved\", \"to\": \"in_production\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 11:23:46'),
(182, 21, 6, 'status_changed', 'status', 'approved', 'in_production', '{\"order_number\": \"CR-20251117-7007\", \"status_changed\": {\"from\": \"approved\", \"to\": \"in_production\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 11:25:00'),
(183, 21, 6, 'status_changed', 'status', 'in_production', 'produced', '{\"order_number\": \"CR-20251117-7007\", \"status_changed\": {\"from\": \"in_production\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 11:27:40'),
(184, 21, 6, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251117-7007\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 11:27:45'),
(185, 23, 6, 'status_changed', 'status', 'ready_to_ship', 'shipped', '{\"order_number\": \"CR-20251117-8813\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"shipped\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 11:29:43'),
(186, 21, 6, 'status_changed', 'status', 'ready_to_ship', 'shipped', '{\"order_number\": \"CR-20251117-7007\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"shipped\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 11:32:06'),
(187, 14, 1, 'status_changed', 'status', 'shipped', 'delivered', '{\"order_number\": \"CR-20251104-7928\", \"status_changed\": {\"from\": \"shipped\", \"to\": \"delivered\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 11:33:18'),
(188, 16, 6, 'status_changed', 'status', 'shipped', 'delivered', '{\"order_number\": \"CR-20251109-5587\", \"status_changed\": {\"from\": \"shipped\", \"to\": \"delivered\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 11:33:23');
INSERT INTO `credit_order_audit` (`id`, `order_id`, `user_id`, `action_type`, `field_name`, `old_value`, `new_value`, `changes_json`, `ip_address`, `user_agent`, `notes`, `created_at`) VALUES
(189, 23, 6, 'status_changed', 'status', 'shipped', 'delivered', '{\"order_number\": \"CR-20251117-8813\", \"status_changed\": {\"from\": \"shipped\", \"to\": \"delivered\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 11:33:30'),
(190, 17, 1, 'status_changed', 'status', 'shipped', 'delivered', '{\"order_number\": \"CR-20251113-5446\", \"status_changed\": {\"from\": \"shipped\", \"to\": \"delivered\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 11:33:37'),
(191, 21, 6, 'status_changed', 'status', 'shipped', 'delivered', '{\"order_number\": \"CR-20251117-7007\", \"status_changed\": {\"from\": \"shipped\", \"to\": \"delivered\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-23 11:33:42'),
(192, 28, 6, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251123-2708\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 06:52:13'),
(193, 29, 6, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251124-3350\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 08:53:37'),
(194, 28, 6, 'status_changed', 'status', 'approved', 'in_production', '{\"order_number\": \"CR-20251123-2708\", \"status_changed\": {\"from\": \"approved\", \"to\": \"in_production\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 08:54:29'),
(195, 29, 6, 'status_changed', 'status', 'approved', 'in_production', '{\"order_number\": \"CR-20251124-3350\", \"status_changed\": {\"from\": \"approved\", \"to\": \"in_production\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 08:54:31'),
(196, 28, 6, 'status_changed', 'status', 'in_production', 'produced', '{\"order_number\": \"CR-20251123-2708\", \"status_changed\": {\"from\": \"in_production\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 08:54:34'),
(197, 29, 6, 'status_changed', 'status', 'in_production', 'produced', '{\"order_number\": \"CR-20251124-3350\", \"status_changed\": {\"from\": \"in_production\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 08:54:36'),
(198, 28, 6, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251123-2708\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 08:54:57'),
(199, 29, 6, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251124-3350\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 08:54:59'),
(200, 28, 6, 'status_changed', 'status', 'ready_to_ship', 'shipped', '{\"order_number\": \"CR-20251123-2708\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"shipped\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 09:08:28'),
(201, 29, 6, 'status_changed', 'status', 'ready_to_ship', 'shipped', '{\"order_number\": \"CR-20251124-3350\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"shipped\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 09:08:43'),
(202, 28, 6, 'status_changed', 'status', 'shipped', 'delivered', '{\"order_number\": \"CR-20251123-2708\", \"status_changed\": {\"from\": \"shipped\", \"to\": \"delivered\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 09:08:47'),
(203, 29, 6, 'status_changed', 'status', 'shipped', 'delivered', '{\"order_number\": \"CR-20251124-3350\", \"status_changed\": {\"from\": \"shipped\", \"to\": \"delivered\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 09:08:51'),
(204, 29, 6, 'payment_collected', 'amount_paid', '0.00', '1246500.00', '{\"order_number\": \"CR-20251124-3350\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": {\"from\": 0.00, \"to\": 1246500.00}}', NULL, NULL, NULL, '2025-11-24 09:12:58'),
(205, 30, 6, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251124-7015\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 09:27:30'),
(206, 30, 6, 'status_changed', 'status', 'approved', 'in_production', '{\"order_number\": \"CR-20251124-7015\", \"status_changed\": {\"from\": \"approved\", \"to\": \"in_production\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 09:28:24'),
(207, 30, 6, 'status_changed', 'status', 'in_production', 'produced', '{\"order_number\": \"CR-20251124-7015\", \"status_changed\": {\"from\": \"in_production\", \"to\": \"produced\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 09:28:27'),
(208, 30, 6, 'status_changed', 'status', 'produced', 'ready_to_ship', '{\"order_number\": \"CR-20251124-7015\", \"status_changed\": {\"from\": \"produced\", \"to\": \"ready_to_ship\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 09:28:36'),
(209, 30, 6, 'status_changed', 'status', 'ready_to_ship', 'shipped', '{\"order_number\": \"CR-20251124-7015\", \"status_changed\": {\"from\": \"ready_to_ship\", \"to\": \"shipped\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 10:36:11'),
(210, 30, 6, 'status_changed', 'status', 'shipped', 'delivered', '{\"order_number\": \"CR-20251124-7015\", \"status_changed\": {\"from\": \"shipped\", \"to\": \"delivered\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 10:36:20'),
(211, 32, 2, 'status_changed', 'status', 'pending_approval', 'approved', '{\"order_number\": \"CR-20251124-7814\", \"status_changed\": {\"from\": \"pending_approval\", \"to\": \"approved\"}, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": null}', NULL, NULL, NULL, '2025-11-24 10:59:40'),
(212, 32, 2, 'payment_collected', 'amount_paid', '0.00', '20600.00', '{\"order_number\": \"CR-20251124-7814\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": {\"from\": 0.00, \"to\": 20600.00}}', NULL, NULL, NULL, '2025-11-24 11:32:59'),
(213, 23, 6, 'payment_collected', 'amount_paid', '0.00', '220500.00', '{\"order_number\": \"CR-20251117-8813\", \"status_changed\": null, \"priority_changed\": null, \"sort_order_changed\": null, \"amount_changed\": null, \"payment_changed\": {\"from\": 0.00, \"to\": 220500.00}}', NULL, NULL, NULL, '2025-11-24 11:35:55');

-- --------------------------------------------------------

--
-- Table structure for table `credit_order_items`
--

CREATE TABLE `credit_order_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `variant_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `total_weight_kg` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `credit_order_items`
--

INSERT INTO `credit_order_items` (`id`, `order_id`, `product_id`, `variant_id`, `quantity`, `unit_price`, `discount_amount`, `tax_amount`, `line_total`, `notes`, `created_at`, `total_weight_kg`) VALUES
(1, 1, 1, 2, 10.00, 3410.00, 85.25, 0.00, 34014.75, NULL, '2025-10-27 18:01:24', 0.00),
(2, 2, 1, 2, 10.00, 3410.00, 0.00, 0.00, 34100.00, NULL, '2025-10-28 11:31:35', 0.00),
(3, 3, 1, 2, 100.00, 3410.00, 0.00, 0.00, 341000.00, NULL, '2025-10-28 11:46:01', 0.00),
(4, 3, 1, 2, 500.00, 3410.00, 0.00, 0.00, 1705000.00, NULL, '2025-10-28 11:46:01', 0.00),
(5, 4, 1, 2, 188.00, 3410.00, 0.00, 0.00, 641080.00, NULL, '2025-10-30 17:10:13', 0.00),
(6, 5, 1, 2, 10.00, 3410.00, 0.00, 0.00, 34100.00, NULL, '2025-11-01 05:04:42', 0.00),
(7, 6, 1, 2, 1.00, 3410.00, 0.00, 0.00, 3410.00, NULL, '2025-11-01 06:19:52', 0.00),
(8, 6, 1, 2, 200.00, 3410.00, 0.00, 0.00, 682000.00, NULL, '2025-11-01 06:19:52', 0.00),
(9, 7, 1, 2, 1.00, 3410.00, 341.00, 0.00, 3069.00, NULL, '2025-11-01 06:21:04', 0.00),
(10, 8, 2, 3, 1.00, 2215.00, 1107.50, 0.00, 1107.50, NULL, '2025-11-01 07:41:33', 0.00),
(11, 9, 2, 3, 67.00, 2215.00, 7420.25, 0.00, 140984.75, NULL, '2025-11-01 08:03:34', 0.00),
(12, 10, 2, 3, 500.00, 2215.00, 110750.00, 0.00, 996750.00, NULL, '2025-11-01 08:26:20', 0.00),
(13, 12, 2, 3, 100.00, 2215.00, 10000.00, 0.00, 211500.00, NULL, '2025-11-03 19:20:27', 0.00),
(14, 13, 2, 3, 1000.00, 2215.00, 2000000.00, 0.00, 215000.00, NULL, '2025-11-03 19:28:06', 0.00),
(15, 14, 1, 2, 100.00, 3410.00, 50000.00, 0.00, 291000.00, NULL, '2025-11-03 19:32:07', 0.00),
(16, 15, 2, 3, 100.00, 2215.00, 0.00, 0.00, 221500.00, NULL, '2025-11-04 16:27:06', 0.00),
(18, 17, 1, 2, 200.00, 3410.00, 0.00, 0.00, 682000.00, NULL, '2025-11-12 20:01:53', 0.00),
(19, 18, 1, 2, 200.00, 3410.00, 0.00, 0.00, 682000.00, NULL, '2025-11-12 20:04:00', 0.00),
(20, 19, 1, 2, 1.00, 3410.00, 0.00, 0.00, 3410.00, NULL, '2025-11-14 10:36:36', 0.00),
(21, 21, 2, 3, 10.00, 2215.00, 0.00, 0.00, 22150.00, NULL, '2025-11-17 10:09:59', 0.00),
(22, 22, 1, 2, 1.00, 3410.00, 50.00, 0.00, 3360.00, NULL, '2025-11-17 10:13:52', 0.00),
(23, 23, 2, 3, 100.00, 2215.00, 1000.00, 0.00, 220500.00, NULL, '2025-11-17 10:52:25', 0.00),
(24, 24, 1, 2, 100.00, 3410.00, 500.00, 0.00, 340500.00, NULL, '2025-11-17 11:03:11', 0.00),
(25, 25, 2, 3, 5.00, 2215.00, 50.00, 0.00, 11025.00, NULL, '2025-11-22 08:46:46', 0.00),
(26, 26, 2, 3, 20.00, 2215.00, 2000.00, 0.00, 42300.00, NULL, '2025-11-23 06:01:12', 0.00),
(27, 26, 1, 2, 10.00, 3410.00, 3410.00, 0.00, 30690.00, NULL, '2025-11-23 06:01:12', 0.00),
(28, 27, 1, 2, 20.00, 3410.00, 200.00, 0.00, 68000.00, NULL, '2025-11-23 11:20:11', 0.00),
(29, 28, 3, 4, 10.00, 1795.00, 0.00, 0.00, 17950.00, NULL, '2025-11-23 11:53:16', 0.00),
(30, 29, 3, 4, 100.00, 1775.00, 1000.00, 0.00, 176500.00, NULL, '2025-11-24 08:51:31', 0.00),
(31, 29, 4, 5, 500.00, 2150.00, 5000.00, 0.00, 1070000.00, NULL, '2025-11-24 08:51:31', 0.00),
(32, 30, 2, 3, 100.00, 2060.00, 1000.00, 0.00, 205000.00, NULL, '2025-11-24 09:26:57', 0.00),
(33, 32, 2, 3, 10.00, 2060.00, 0.00, 0.00, 20600.00, NULL, '2025-11-24 10:59:08', 0.00);

--
-- Triggers `credit_order_items`
--
DELIMITER $$
CREATE TRIGGER `trg_calculate_item_weight_insert` AFTER INSERT ON `credit_order_items` FOR EACH ROW BEGIN
    CALL sp_calculate_order_weight(NEW.order_id);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_calculate_item_weight_update` AFTER UPDATE ON `credit_order_items` FOR EACH ROW BEGIN
    IF NEW.quantity != OLD.quantity THEN
        CALL sp_calculate_order_weight(NEW.order_id);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `credit_order_shipping`
--

CREATE TABLE `credit_order_shipping` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `truck_number` varchar(50) DEFAULT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `driver_contact` varchar(20) DEFAULT NULL,
  `shipped_date` datetime DEFAULT NULL,
  `delivered_date` datetime DEFAULT NULL,
  `shipped_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `delivered_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `delivery_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `trip_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `credit_order_shipping`
--

INSERT INTO `credit_order_shipping` (`id`, `order_id`, `truck_number`, `driver_name`, `driver_contact`, `shipped_date`, `delivered_date`, `shipped_by_user_id`, `delivered_by_user_id`, `delivery_notes`, `created_at`, `updated_at`, `trip_id`) VALUES
(7, 8, 'DHK-GA-1234', 'Rahim Uddin', '01823456789', '2025-11-08 23:19:12', NULL, 1, NULL, NULL, '2025-11-04 16:36:59', '2025-11-08 17:19:12', NULL),
(8, 7, 'DHA-GA-1234', 'Karim Ahmed', '01712345678', '2025-11-04 23:17:58', NULL, 1, NULL, NULL, '2025-11-04 17:09:29', '2025-11-04 17:17:58', NULL),
(9, 14, 'Ctg Metro-TA-13-5678', 'Md. Aminul Islam', '01711223344', '2025-11-09 23:53:21', '2025-11-23 17:33:18', 1, 5, '', '2025-11-09 17:10:18', '2025-11-23 11:33:18', 3),
(10, 16, 'Dhaka Metro-DA-14-3434', 'Priya Akter', '01551234567', '2025-11-10 23:40:08', '2025-11-23 17:33:23', 5, 5, '', '2025-11-10 17:40:08', '2025-11-23 11:33:23', 4),
(11, 15, 'Ctg Metro-TA-13-5678', 'Kamal Hossain', '01912889900', '2025-11-10 23:43:55', NULL, 1, NULL, NULL, '2025-11-10 17:43:55', '2025-11-10 17:43:55', 5),
(12, 17, 'Ctg Metro-TA-13-5678', 'Kamal Hossain', '01912889900', '2025-11-13 02:03:02', '2025-11-23 17:33:37', 1, 5, '', '2025-11-12 20:03:02', '2025-11-23 11:33:37', 6),
(13, 18, 'Ctg Metro-TA-13-5678', 'Abdus Sattar', '01811654321', '2025-11-13 02:06:04', '2025-11-16 19:48:33', 1, 1, '', '2025-11-12 20:06:04', '2025-11-16 13:48:33', 7),
(14, 23, 'DHK-GA-1234', 'Kamal Hossain', '01912889900', '2025-11-23 17:29:43', '2025-11-23 17:33:30', 5, 5, '', '2025-11-23 11:29:43', '2025-11-23 11:33:30', 8),
(15, 21, 'Dhaka Metro-TA-11-8080', 'Rahim Uddin', '01823456789', '2025-11-23 17:32:06', '2025-11-23 17:33:42', 5, 5, '', '2025-11-23 11:32:06', '2025-11-23 11:33:42', 9),
(16, 28, 'Ctg Metro-TA-13-5678', 'Jamal Mia', '01990506070', '2025-11-24 15:08:28', '2025-11-24 15:08:47', 5, 5, '', '2025-11-24 09:08:28', '2025-11-24 09:08:47', 10),
(17, 29, 'DHK-HA-5678', 'Rafiqul Alam', '01720304050', '2025-11-24 15:08:43', '2025-11-24 15:08:51', 5, 5, '', '2025-11-24 09:08:43', '2025-11-24 09:08:51', 11),
(18, 30, 'Dhaka Metro-DA-15-9901', 'Karim Mia', '01712345678', '2025-11-24 16:36:11', '2025-11-24 16:36:20', 5, 5, '', '2025-11-24 10:36:11', '2025-11-24 10:36:20', 12);

-- --------------------------------------------------------

--
-- Table structure for table `credit_order_workflow`
--

CREATE TABLE `credit_order_workflow` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `from_status` varchar(50) NOT NULL,
  `to_status` varchar(50) NOT NULL,
  `action` varchar(50) NOT NULL,
  `performed_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `comments` text DEFAULT NULL,
  `credit_check_amount` decimal(12,2) DEFAULT NULL,
  `credit_limit_used_percent` decimal(5,2) DEFAULT NULL,
  `performed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `credit_order_workflow`
--

INSERT INTO `credit_order_workflow` (`id`, `order_id`, `from_status`, `to_status`, `action`, `performed_by_user_id`, `comments`, `credit_check_amount`, `credit_limit_used_percent`, `performed_at`) VALUES
(1, 1, 'draft', 'pending_approval', 'submit', 2, 'Order created and submitted for approval', NULL, NULL, '2025-10-27 18:01:24'),
(2, 1, 'approved', 'in_production', 'start_production', 4, 'Production status updated', NULL, NULL, '2025-10-28 07:49:08'),
(3, 1, 'in_production', 'produced', 'complete_production', 4, 'Production status updated', NULL, NULL, '2025-10-28 08:42:52'),
(4, 1, 'produced', 'ready_to_ship', 'ship', 4, 'Production status updated', NULL, NULL, '2025-10-28 08:43:01'),
(5, 1, 'ready_to_ship', 'shipped', 'ship', 5, 'Shipped with truck Dhaka Metro- Ga -21-3776, driver: Rubel Driver', NULL, NULL, '2025-10-28 10:20:57'),
(6, 1, 'shipped', 'delivered', 'deliver', 5, 'Order delivered to customer: Delivered and received by Mr. Hashem, Son of Kashem', NULL, NULL, '2025-10-28 11:15:13'),
(7, 2, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-10-28 11:31:35'),
(8, 2, 'approved', 'in_production', 'start_production', 4, 'Production status updated', NULL, NULL, '2025-10-28 11:34:15'),
(9, 2, 'in_production', 'produced', 'complete_production', 4, 'Production status updated', NULL, NULL, '2025-10-28 11:34:17'),
(10, 2, 'produced', 'ready_to_ship', 'ship', 4, 'Production status updated', NULL, NULL, '2025-10-28 11:34:21'),
(11, 3, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-10-28 11:46:01'),
(12, 3, 'approved', 'in_production', 'start_production', 4, 'Production status updated', NULL, NULL, '2025-10-30 16:32:10'),
(13, 3, 'in_production', 'produced', 'complete_production', 4, 'Production status updated', NULL, NULL, '2025-10-30 16:32:13'),
(14, 3, 'produced', 'ready_to_ship', 'ship', 4, 'Production status updated', NULL, NULL, '2025-10-30 16:32:16'),
(15, 2, 'ready_to_ship', 'shipped', 'ship', 5, 'Shipped with truck pdf 65787, driver: hgh', NULL, NULL, '2025-10-30 16:32:54'),
(16, 3, 'ready_to_ship', 'shipped', 'ship', 5, 'Shipped with truck rgh34546, driver: dnfhjg', NULL, NULL, '2025-10-30 16:59:02'),
(17, 4, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-10-30 17:10:13'),
(18, 4, 'approved', 'in_production', 'start_production', 4, 'Production status updated', NULL, NULL, '2025-10-30 17:12:05'),
(19, 4, 'in_production', 'produced', 'complete_production', 4, 'Production status updated', NULL, NULL, '2025-10-30 17:12:07'),
(20, 4, 'produced', 'ready_to_ship', 'ship', 4, 'Production status updated', NULL, NULL, '2025-10-30 17:12:10'),
(21, 4, 'ready_to_ship', 'shipped', 'ship', 5, 'Shipped with truck dfh334545, driver: dfhdhj', NULL, NULL, '2025-10-30 17:12:47'),
(22, 2, 'shipped', 'delivered', 'deliver', 5, 'Order delivered to customer: delivered', NULL, NULL, '2025-10-30 17:34:44'),
(23, 4, 'shipped', 'delivered', 'deliver', 5, 'Order delivered to customer: ok', NULL, NULL, '2025-10-30 17:35:03'),
(24, 5, 'draft', 'pending_approval', 'submit', 1, 'Order created and submitted for approval', NULL, NULL, '2025-11-01 05:04:42'),
(25, 5, 'approved', 'in_production', 'start_production', 1, 'Production status updated', NULL, NULL, '2025-11-01 05:06:08'),
(26, 5, 'in_production', 'produced', 'complete_production', 1, 'Production status updated', NULL, NULL, '2025-11-01 05:06:12'),
(27, 6, 'draft', 'pending_approval', 'submit', 1, 'Order created and submitted for approval', NULL, NULL, '2025-11-01 06:19:52'),
(28, 7, 'draft', 'pending_approval', 'submit', 1, 'Order created and submitted for approval', NULL, NULL, '2025-11-01 06:21:04'),
(29, 8, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-11-01 07:41:33'),
(30, 9, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-11-01 08:03:34'),
(31, 10, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-11-01 08:26:20'),
(32, 6, 'approved', 'in_production', 'start_production', 4, 'Production status updated', NULL, NULL, '2025-11-01 08:38:23'),
(33, 6, 'in_production', 'produced', 'complete_production', 4, 'Production status updated', NULL, NULL, '2025-11-01 08:38:31'),
(34, 5, 'produced', 'ready_to_ship', 'ship', 4, 'Production status updated', NULL, NULL, '2025-11-01 08:38:33'),
(35, 5, 'ready_to_ship', 'shipped', 'ship', 5, 'Shipped with truck 123, driver: abuila', NULL, NULL, '2025-11-01 08:40:25'),
(36, 12, 'draft', 'pending_approval', 'submit', 1, 'Order created and submitted for approval', NULL, NULL, '2025-11-03 19:20:27'),
(37, 13, 'draft', 'pending_approval', 'submit', 1, 'Order created and submitted for approval', NULL, NULL, '2025-11-03 19:28:06'),
(38, 14, 'draft', 'pending_approval', 'submit', 1, 'Order created and submitted for approval', NULL, NULL, '2025-11-03 19:32:07'),
(39, 8, 'approved', 'in_production', 'start_production', 4, 'Production status updated', NULL, NULL, '2025-11-03 19:35:18'),
(40, 8, 'in_production', 'produced', 'complete_production', 4, 'Production status updated', NULL, NULL, '2025-11-03 19:35:21'),
(41, 6, 'produced', 'ready_to_ship', 'ship', 4, 'Production status updated', NULL, NULL, '2025-11-03 19:35:29'),
(42, 6, 'ready_to_ship', 'shipped', 'ship', 5, 'Shipped with truck kgjtj 33898, driver: jhkfgkj', NULL, NULL, '2025-11-03 19:36:16'),
(43, 3, 'shipped', 'delivered', 'deliver', 5, 'Order delivered to customer', NULL, NULL, '2025-11-03 19:36:47'),
(44, 15, 'draft', 'pending_approval', 'submit', 1, 'Order created and submitted for approval', NULL, NULL, '2025-11-04 16:27:06'),
(45, 7, 'approved', 'in_production', 'start_production', 1, 'Production status updated', NULL, NULL, '2025-11-04 16:27:40'),
(46, 7, 'in_production', 'produced', 'complete_production', 1, 'Production status updated', NULL, NULL, '2025-11-04 16:27:44'),
(47, 7, 'produced', 'ready_to_ship', 'ship', 1, 'Production status updated', NULL, NULL, '2025-11-04 16:27:47'),
(48, 8, 'produced', 'ready_to_ship', 'ship', 1, 'Production status updated', NULL, NULL, '2025-11-04 16:27:51'),
(49, 8, 'ready_to_ship', 'shipped', 'ship', 5, 'Shipped with truck DHA-GA-1234, driver: Karim Ahmed (Trip ID: 1)', NULL, NULL, '2025-11-04 16:36:59'),
(50, 15, 'approved', 'in_production', 'start_production', 1, 'Production status updated', NULL, NULL, '2025-11-04 16:55:01'),
(51, 15, 'in_production', 'produced', 'complete_production', 1, 'Production status updated', NULL, NULL, '2025-11-04 16:55:05'),
(52, 7, 'ready_to_ship', 'shipped', 'ship', 1, 'Shipped with truck DHA-GA-1234, driver: Karim Ahmed (Trip ID: 2)', NULL, NULL, '2025-11-04 17:09:29'),
(53, 7, 'ready_to_ship', 'shipped', 'ship', 1, 'Shipped with truck DHA-GA-1234, driver: Karim Ahmed (Trip ID: 3)', NULL, NULL, '2025-11-04 17:17:58'),
(54, 8, 'ready_to_ship', 'shipped', 'ship', 1, 'Shipped with truck DHK-GA-1234, driver: Rahim Uddin (Trip ID: )', NULL, NULL, '2025-11-08 17:19:12'),
(55, 16, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-11-09 16:53:24'),
(56, 14, 'approved', 'in_production', 'start_production', 4, 'Production status updated', NULL, NULL, '2025-11-09 16:54:28'),
(57, 14, 'in_production', 'produced', 'complete_production', 4, 'Production status updated', NULL, NULL, '2025-11-09 16:54:32'),
(58, 14, 'produced', 'ready_to_ship', 'ship', 4, 'Production status updated', NULL, NULL, '2025-11-09 16:54:34'),
(59, 14, 'produced', 'ready_to_ship', 'ship', 4, 'Production status updated', NULL, NULL, '2025-11-09 16:54:36'),
(60, 14, 'produced', 'ready_to_ship', 'ship', 4, 'Production status updated', NULL, NULL, '2025-11-09 16:54:43'),
(61, 16, 'approved', 'in_production', 'start_production', 4, 'Production status updated', NULL, NULL, '2025-11-09 16:54:47'),
(62, 16, 'in_production', 'produced', 'complete_production', 4, 'Production status updated', NULL, NULL, '2025-11-09 16:54:50'),
(63, 14, 'produced', 'ready_to_ship', 'ship', 4, 'Production status updated', NULL, NULL, '2025-11-09 16:54:51'),
(64, 14, 'produced', 'ready_to_ship', 'ship', 1, 'Production status updated', NULL, NULL, '2025-11-09 16:59:53'),
(65, 14, 'produced', 'ready_to_ship', 'ship', 1, 'Production status updated', NULL, NULL, '2025-11-09 17:00:24'),
(66, 14, 'produced', 'ready_to_ship', 'ship', 1, 'Production status updated', NULL, NULL, '2025-11-09 17:00:35'),
(67, 14, 'ready_to_ship', 'shipped', 'ship', 5, 'Shipped with truck DHK-GA-1234, driver: Karim Mia (Trip #1)', NULL, NULL, '2025-11-09 17:10:18'),
(68, 14, 'produced', 'ready_to_ship', 'mark_ready_to_ship', 4, 'Production status updated', NULL, NULL, '2025-11-09 17:24:23'),
(69, 16, 'produced', 'ready_to_ship', 'mark_ready_to_ship', 4, 'Production status updated', NULL, NULL, '2025-11-09 17:24:28'),
(70, 14, 'ready_to_ship', 'shipped', 'ship', 5, 'Shipped with truck DHK-GA-1234, driver: Rahim Uddin (Trip #2)', NULL, NULL, '2025-11-09 17:33:26'),
(71, 14, 'ready_to_ship', 'shipped', 'ship', 1, 'Shipped with truck Ctg Metro-TA-13-5678, driver: Md. Aminul Islam (Trip #3)', NULL, NULL, '2025-11-09 17:53:21'),
(72, 16, 'ready_to_ship', 'shipped', 'ship', 5, 'Shipped with truck Dhaka Metro-DA-14-3434, driver: Priya Akter (Trip #4)', NULL, NULL, '2025-11-10 17:40:08'),
(73, 15, 'ready_to_ship', 'shipped', 'ship', 1, 'Shipped with truck Ctg Metro-TA-13-5678, driver: Kamal Hossain (Trip #5)', NULL, NULL, '2025-11-10 17:43:55'),
(74, 17, 'draft', 'pending_approval', 'submit', 1, 'Order created and submitted for approval', NULL, NULL, '2025-11-12 20:01:53'),
(75, 17, 'pending_approval', 'approved', 'approved', 1, 'Order approved', NULL, NULL, '2025-11-12 20:02:14'),
(76, 17, 'approved', 'in_production', 'start_production', 1, 'Production status updated', NULL, NULL, '2025-11-12 20:02:28'),
(77, 17, 'in_production', 'produced', 'complete_production', 1, 'Production status updated', NULL, NULL, '2025-11-12 20:02:32'),
(78, 17, 'produced', 'ready_to_ship', 'mark_ready_to_ship', 1, 'Production status updated', NULL, NULL, '2025-11-12 20:02:36'),
(79, 17, 'ready_to_ship', 'shipped', 'ship', 1, 'Shipped with truck Ctg Metro-TA-13-5678, driver: Kamal Hossain (Trip #6)', NULL, NULL, '2025-11-12 20:03:02'),
(80, 18, 'draft', 'pending_approval', 'submit', 1, 'Order created and submitted for approval', NULL, NULL, '2025-11-12 20:04:00'),
(81, 18, 'pending_approval', 'approved', 'approved', 1, 'Order approved', NULL, NULL, '2025-11-12 20:04:15'),
(82, 18, 'approved', 'in_production', 'start_production', 1, 'Production status updated', NULL, NULL, '2025-11-12 20:04:25'),
(83, 18, 'in_production', 'produced', 'complete_production', 1, 'Production status updated', NULL, NULL, '2025-11-12 20:04:29'),
(84, 18, 'produced', 'ready_to_ship', 'mark_ready_to_ship', 1, 'Production status updated', NULL, NULL, '2025-11-12 20:04:37'),
(85, 18, 'ready_to_ship', 'shipped', 'ship', 1, 'Shipped with Trip #7', NULL, NULL, '2025-11-12 20:06:04'),
(86, 19, 'draft', 'pending_approval', 'submit', 1, 'Order created and submitted for approval', NULL, NULL, '2025-11-14 10:36:36'),
(87, 18, 'shipped', 'delivered', 'deliver', 1, 'Order delivered to customer', NULL, NULL, '2025-11-16 13:48:33'),
(88, 19, 'pending_approval', 'approved', 'approved', 2, 'Order approved', NULL, NULL, '2025-11-17 10:03:57'),
(89, 21, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-11-17 10:09:59'),
(90, 22, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-11-17 10:13:52'),
(91, 22, 'pending_approval', 'approved', 'approved', 2, 'Order approved', NULL, NULL, '2025-11-17 10:30:53'),
(92, 23, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-11-17 10:52:25'),
(93, 24, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-11-17 11:03:11'),
(94, 23, 'pending_approval', 'approved', 'approved', 2, 'Order approved', NULL, NULL, '2025-11-17 11:10:08'),
(95, 24, 'pending_approval', 'approved', 'approved', 2, 'Order approved', NULL, NULL, '2025-11-17 11:10:22'),
(96, 23, 'approved', 'in_production', 'start_production', 4, 'Production status updated', NULL, NULL, '2025-11-17 11:12:46'),
(97, 25, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-11-22 08:46:46'),
(98, 25, 'pending_approval', 'approved', 'approved', 2, 'Order approved', NULL, NULL, '2025-11-22 08:59:34'),
(99, 23, 'in_production', 'produced', 'complete_production', 4, 'Production status updated', NULL, NULL, '2025-11-23 05:29:28'),
(100, 26, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-11-23 06:01:12'),
(101, 23, 'produced', 'ready_to_ship', 'mark_ready_to_ship', 4, 'Production status updated', NULL, NULL, '2025-11-23 08:23:28'),
(102, 27, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-11-23 11:20:11'),
(103, 21, 'pending_approval', 'approved', 'approved', 1, 'Order approved', NULL, NULL, '2025-11-23 11:22:34'),
(104, 26, 'pending_approval', 'rejected', 'reject', 1, 'hudai', NULL, NULL, '2025-11-23 11:22:46'),
(105, 27, 'pending_approval', 'approved', 'approved', 1, 'Order approved', NULL, NULL, '2025-11-23 11:23:03'),
(106, 22, 'approved', 'in_production', 'start_production', 1, 'Production status updated', NULL, NULL, '2025-11-23 11:23:34'),
(107, 24, 'approved', 'in_production', 'start_production', 1, 'Production status updated', NULL, NULL, '2025-11-23 11:23:46'),
(108, 21, 'approved', 'in_production', 'start_production', 4, 'Production status updated', NULL, NULL, '2025-11-23 11:25:00'),
(109, 21, 'in_production', 'produced', 'complete_production', 4, 'Production status updated', NULL, NULL, '2025-11-23 11:27:40'),
(110, 21, 'produced', 'ready_to_ship', 'mark_ready_to_ship', 4, 'Production status updated', NULL, NULL, '2025-11-23 11:27:45'),
(111, 23, 'ready_to_ship', 'shipped', 'ship', 5, 'Shipped with truck DHK-GA-1234, driver: Kamal Hossain (Trip #8)', NULL, NULL, '2025-11-23 11:29:43'),
(112, 21, 'ready_to_ship', 'shipped', 'ship', 5, 'Shipped with truck Dhaka Metro-TA-11-8080, driver: Rahim Uddin (Trip #9)', NULL, NULL, '2025-11-23 11:32:06'),
(113, 14, 'shipped', 'delivered', 'deliver', 5, 'Order delivered to customer', NULL, NULL, '2025-11-23 11:33:18'),
(114, 16, 'shipped', 'delivered', 'deliver', 5, 'Order delivered to customer', NULL, NULL, '2025-11-23 11:33:23'),
(115, 23, 'shipped', 'delivered', 'deliver', 5, 'Order delivered to customer', NULL, NULL, '2025-11-23 11:33:30'),
(116, 17, 'shipped', 'delivered', 'deliver', 5, 'Order delivered to customer', NULL, NULL, '2025-11-23 11:33:37'),
(117, 21, 'shipped', 'delivered', 'deliver', 5, 'Order delivered to customer', NULL, NULL, '2025-11-23 11:33:42'),
(118, 28, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-11-23 11:53:17'),
(119, 28, 'pending_approval', 'approved', 'approved', 2, 'Order approved', NULL, NULL, '2025-11-24 06:52:13'),
(120, 29, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-11-24 08:51:31'),
(121, 29, 'pending_approval', 'approved', 'approved', 1, 'Order approved', NULL, NULL, '2025-11-24 08:53:37'),
(122, 28, 'approved', 'in_production', 'start_production', 4, 'Production status updated', NULL, NULL, '2025-11-24 08:54:29'),
(123, 29, 'approved', 'in_production', 'start_production', 4, 'Production status updated', NULL, NULL, '2025-11-24 08:54:31'),
(124, 28, 'in_production', 'produced', 'complete_production', 4, 'Production status updated', NULL, NULL, '2025-11-24 08:54:34'),
(125, 29, 'in_production', 'produced', 'complete_production', 4, 'Production status updated', NULL, NULL, '2025-11-24 08:54:36'),
(126, 28, 'produced', 'ready_to_ship', 'mark_ready_to_ship', 4, 'Production status updated', NULL, NULL, '2025-11-24 08:54:57'),
(127, 29, 'produced', 'ready_to_ship', 'mark_ready_to_ship', 4, 'Production status updated', NULL, NULL, '2025-11-24 08:54:59'),
(128, 28, 'ready_to_ship', 'shipped', 'ship', 5, 'Shipped with truck Ctg Metro-TA-13-5678, driver: Jamal Mia (Trip #10)', NULL, NULL, '2025-11-24 09:08:29'),
(129, 29, 'ready_to_ship', 'shipped', 'ship', 5, 'Shipped with truck DHK-HA-5678, driver: Rafiqul Alam (Trip #11)', NULL, NULL, '2025-11-24 09:08:43'),
(130, 28, 'shipped', 'delivered', 'deliver', 5, 'Order delivered to customer', NULL, NULL, '2025-11-24 09:08:47'),
(131, 29, 'shipped', 'delivered', 'deliver', 5, 'Order delivered to customer', NULL, NULL, '2025-11-24 09:08:51'),
(132, 30, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-11-24 09:26:57'),
(133, 30, 'pending_approval', 'approved', 'approved', 2, 'Order approved', NULL, NULL, '2025-11-24 09:27:30'),
(134, 30, 'approved', 'in_production', 'start_production', 4, 'Production status updated', NULL, NULL, '2025-11-24 09:28:24'),
(135, 30, 'in_production', 'produced', 'complete_production', 4, 'Production status updated', NULL, NULL, '2025-11-24 09:28:27'),
(136, 30, 'produced', 'ready_to_ship', 'mark_ready_to_ship', 4, 'Production status updated', NULL, NULL, '2025-11-24 09:28:36'),
(137, 30, 'ready_to_ship', 'shipped', 'ship', 5, 'Shipped with truck Dhaka Metro-DA-15-9901, driver: Karim Mia (Trip #12)', NULL, NULL, '2025-11-24 10:36:11'),
(138, 30, 'shipped', 'delivered', 'deliver', 5, 'Order delivered to customer', NULL, NULL, '2025-11-24 10:36:20'),
(139, 32, 'draft', 'pending_approval', 'submit', 2, 'Order created and submitted for approval', NULL, NULL, '2025-11-24 10:59:08'),
(140, 32, 'pending_approval', 'approved', 'approved', 2, 'Order approved', NULL, NULL, '2025-11-24 10:59:40');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(36) NOT NULL DEFAULT uuid() COMMENT 'Public-safe unique ID',
  `customer_type` enum('Credit','POS') NOT NULL DEFAULT 'POS' COMMENT 'Credit customers are eligible for a limit, POS are not.',
  `name` varchar(255) NOT NULL COMMENT 'Contact person''s name or a walk-in customer''s name',
  `business_name` varchar(255) DEFAULT NULL COMMENT 'The registered business name, if any',
  `phone_number` varchar(50) NOT NULL COMMENT 'Primary contact phone',
  `email` varchar(100) DEFAULT NULL COMMENT 'Optional but highly recommended for invoices',
  `business_address` text DEFAULT NULL,
  `photo_url` varchar(255) DEFAULT NULL COMMENT 'File path to an optional customer photo',
  `credit_limit` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'The max credit allowed. Application logic will enforce 0.00 for POS customers.',
  `initial_due` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'One-time starting balance (if migrating)',
  `current_balance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Live A/R balance. Will be updated by invoices and payments.',
  `status` enum('active','inactive','blacklisted') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `uuid`, `customer_type`, `name`, `business_name`, `phone_number`, `email`, `business_address`, `photo_url`, `credit_limit`, `initial_due`, `current_balance`, `status`, `created_at`, `updated_at`) VALUES
(1, '06721afd-af53-11f0-9003-10ffe0a28e39', 'Credit', 'Hazi Abul Kashem', 'Kashem & Sons', '01912071977', 'kashemandsons@gmail.com', 'Old Chaul Potti, Demra , Dhaka', 'uploads/profiles/customer_1761143157_IMG_5782.jpeg', 100000000.00, 220000.00, 0.00, 'active', '2025-10-22 14:25:57', '2025-11-24 11:37:57'),
(7, '49d31eac-b6f0-11f0-9003-10ffe0a28e39', 'POS', 'Babul Mia', 'Babul & Sons', '12345476', 'sddfg@gmail.com', 'Demra, Dhaka', 'uploads/profiles/customer_7_1749276282506.jfif', 0.00, 0.00, 0.00, 'active', '2025-11-01 06:59:19', '2025-11-24 06:55:06'),
(10, '6f970771-b712-11f0-9003-10ffe0a28e39', 'Credit', 'nibirrrrr', 'hjhgj', '7657687', 'hjgjhg@gmail.com', 'huuuy', 'uploads/profiles/customer_10_1761995026.jpg', 1234566600.00, 123457.00, 0.00, 'active', '2025-11-01 11:03:45', '2025-11-24 07:04:39'),
(12, 'd91eeffa-c2e3-11f0-93c1-10ffe0a28e39', 'POS', 'Sultan Sikder', NULL, '01202262262', NULL, NULL, NULL, 0.00, 0.00, 0.00, 'active', '2025-11-16 12:00:30', '2025-11-16 12:00:30'),
(14, 'bed9f7a3-c3a0-11f0-93c1-10ffe0a28e39', 'Credit', 'Ma Trading Corporation', 'Ma Trading', '01752462656', NULL, NULL, 'uploads/profiles/customer_14_1764016357.jpeg', 500000.00, 0.00, 0.00, 'active', '2025-11-17 10:32:41', '2025-11-24 20:32:37'),
(15, 'ecca3aab-c3a0-11f0-93c1-10ffe0a28e39', 'Credit', 'Binimoy Trading', 'Binimoy Trading', '01213621325', NULL, NULL, NULL, 0.00, 0.00, 0.00, 'active', '2025-11-17 10:33:58', '2025-11-17 10:33:58'),
(16, '0f6e60c3-c3a1-11f0-93c1-10ffe0a28e39', 'POS', 'Mokka Traders', 'Mokka Traders', '01752462656', NULL, NULL, NULL, 0.00, 0.00, 0.00, 'active', '2025-11-17 10:34:56', '2025-11-24 11:35:55'),
(17, '21273663-c3a1-11f0-93c1-10ffe0a28e39', 'Credit', 'Hazrat Ali', 'Hazrat Ali', '01752462656', NULL, NULL, NULL, 0.00, 0.00, 0.00, 'active', '2025-11-17 10:35:26', '2025-11-17 10:35:26'),
(18, '30bd3eb1-c3a1-11f0-93c1-10ffe0a28e39', 'POS', 'Salam Store', 'Salam Store', '01213621325', NULL, NULL, NULL, 0.00, 0.00, 0.00, 'active', '2025-11-17 10:35:52', '2025-11-24 11:37:10'),
(19, '42209e82-c3a1-11f0-93c1-10ffe0a28e39', 'Credit', 'Ibrahim Store', 'Ibrahim Store', '01752462656', NULL, NULL, NULL, 0.00, 0.00, 0.00, 'active', '2025-11-17 10:36:21', '2025-11-17 10:36:21'),
(20, '4e08829c-c3a1-11f0-93c1-10ffe0a28e39', 'Credit', 'Kader Store', 'Kader Store', '01752462656', NULL, NULL, NULL, 0.00, 0.00, 0.00, 'active', '2025-11-17 10:36:41', '2025-11-17 10:36:41'),
(21, '5f1b68b3-c3a1-11f0-93c1-10ffe0a28e39', 'POS', 'Nannu Miah', 'Nannu Store', '01213621325', NULL, NULL, NULL, 0.00, 0.00, 0.00, 'active', '2025-11-17 10:37:10', '2025-11-17 10:37:10'),
(22, '3de1b6e2-c924-11f0-93c1-10ffe0a28e39', 'Credit', 'Adnan', 'Adana Corp', '016', 'akkasalu@gmail.com', '221B Baker Street, London.', NULL, 500000.00, 1000000.00, 0.00, 'active', '2025-11-24 10:56:34', '2025-11-24 11:36:40');

-- --------------------------------------------------------

--
-- Table structure for table `customer_ledger`
--

CREATE TABLE `customer_ledger` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_type` enum('invoice','payment','advance_payment','adjustment','credit_note','debit_note','opening_balance') NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` bigint(20) UNSIGNED DEFAULT NULL,
  `invoice_number` varchar(50) DEFAULT NULL,
  `description` text NOT NULL,
  `debit_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `credit_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_after` decimal(12,2) NOT NULL,
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `journal_entry_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customer_ledger`
--

INSERT INTO `customer_ledger` (`id`, `customer_id`, `transaction_date`, `transaction_type`, `reference_type`, `reference_id`, `invoice_number`, `description`, `debit_amount`, `credit_amount`, `balance_after`, `created_by_user_id`, `journal_entry_id`, `created_at`) VALUES
(1, 1, '2025-10-28', 'invoice', 'credit_orders', 1, 'CR-20251028-6054', 'Credit sale - Invoice #CR-20251028-6054', 34014.75, 0.00, 254014.75, 5, NULL, '2025-10-28 10:20:57'),
(3, 1, '2025-10-30', 'payment', 'customer_payments', 2, 'PAY-20251030-6800', 'Payment received - Receipt #PAY-20251030-6800', 0.00, 100000.00, 154014.75, 1, 12, '2025-10-30 10:18:03'),
(4, 1, '2025-10-30', 'invoice', 'credit_orders', 2, 'CR-20251028-9123', 'Credit sale - Invoice #CR-20251028-9123', 34100.00, 0.00, 188114.75, 5, 13, '2025-10-30 16:32:54'),
(5, 1, '2025-10-30', 'invoice', 'credit_orders', 3, 'CR-20251028-6227', 'Credit sale - Invoice #CR-20251028-6227', 2046000.00, 0.00, 2234114.75, 5, 14, '2025-10-30 16:59:02'),
(6, 4, '2025-10-30', 'invoice', 'credit_orders', 4, 'CR-20251030-2349', 'Credit sale - Invoice #CR-20251030-2349', 641080.00, 0.00, 1186080.00, 5, 15, '2025-10-30 17:12:47'),
(7, 4, '2025-10-31', 'payment', 'customer_payments', 4, 'RCP-20251031-7113', 'Payment received - Receipt #RCP-20251031-7113', 0.00, 12000.00, 1174080.00, 1, NULL, '2025-10-31 10:48:43'),
(8, 4, '2025-10-31', 'payment', 'customer_payments', 5, 'RCP-20251031-6778', 'Payment received - Receipt #RCP-20251031-6778 (Bank Transfer)', 0.00, 5000.00, 1181080.00, 1, NULL, '2025-10-31 10:54:14'),
(9, 4, '2025-10-31', 'payment', 'customer_payments', 6, 'RCP-20251031-2830', 'Payment received - Receipt #RCP-20251031-2830', 0.00, 4400.00, 1181680.00, 1, NULL, '2025-10-31 11:12:09'),
(10, 4, '2025-10-31', 'payment', 'customer_payments', 7, 'RCP-20251031-9036', 'Payment received - Receipt #RCP-20251031-9036', 0.00, 1000.00, 1185080.00, 1, NULL, '2025-10-31 13:27:25'),
(11, 4, '2025-10-31', 'payment', 'customer_payments', 8, 'RCP-20251031-3437', 'Payment received - Receipt #RCP-20251031-3437', 0.00, 1001.00, 1185079.00, 1, NULL, '2025-10-31 13:35:14'),
(12, 1, '2025-10-31', 'payment', 'customer_payments', 9, 'RCP-20251031-7315', 'Payment received - Receipt #RCP-20251031-7315 (Bank Transfer)', 0.00, 1000000.00, 1234114.75, 1, NULL, '2025-10-31 13:48:18'),
(13, 4, '2025-11-01', 'payment', 'customer_payments', 10, 'RCP-20251101-6618', 'Payment received - Receipt #RCP-20251101-6618 (Bank Transfer)', 0.00, 5000.00, 1181080.00, 2, NULL, '2025-11-01 07:23:54'),
(14, 5, '2025-11-01', 'invoice', 'credit_orders', 5, 'CR-20251101-1900', 'Credit sale - Invoice #CR-20251101-1900', 34100.00, 0.00, 184100.00, 5, 28, '2025-11-01 08:40:25'),
(15, 11, '2025-11-01', 'payment', 'customer_payments', 11, 'RCP-20251101-4027', 'Payment received - Receipt #RCP-20251101-4027', 0.00, 2345566.00, -2345566.00, 1, NULL, '2025-11-01 11:18:58'),
(16, 11, '2025-11-01', 'payment', 'customer_payments', 12, 'RCP-20251101-5196', 'Payment received - Receipt #RCP-20251101-5196 (Bank Transfer)', 0.00, 564654.00, -2910220.00, 1, NULL, '2025-11-01 11:21:16'),
(17, 1, '2025-11-04', 'invoice', 'credit_orders', 6, 'CR-20251101-9073', 'Credit sale - Invoice #CR-20251101-9073', 685410.00, 0.00, 1919524.75, 5, 31, '2025-11-03 19:36:16'),
(18, 7, '2025-11-04', 'invoice', 'credit_orders', 8, 'CR-20251101-3242', 'Credit sale - Invoice #CR-20251101-3242', 107.50, 0.00, 107.50, 5, NULL, '2025-11-04 16:36:59'),
(19, 1, '2025-11-04', 'invoice', 'credit_orders', 7, 'CR-20251101-7172', 'Credit sale - Invoice #CR-20251101-7172', 3069.00, 0.00, 1922593.75, 1, NULL, '2025-11-04 17:09:29'),
(20, 1, '2025-11-04', 'invoice', 'credit_orders', 7, 'CR-20251101-7172', 'Credit sale - Invoice #CR-20251101-7172', 3069.00, 0.00, 1925662.75, 1, 36, '2025-11-04 17:17:58'),
(21, 7, '2025-11-08', 'invoice', 'credit_orders', 8, 'CR-20251101-3242', 'Credit sale - Invoice #CR-20251101-3242', 107.50, 0.00, 215.00, 1, 37, '2025-11-08 17:19:12'),
(22, 1, '2025-11-09', 'invoice', 'credit_orders', 14, 'CR-20251104-7928', 'Credit sale - Invoice #CR-20251104-7928', 291000.00, 0.00, 2216662.75, 5, NULL, '2025-11-09 17:10:18'),
(23, 1, '2025-11-09', 'invoice', 'credit_orders', 14, 'CR-20251104-7928', 'Credit sale - Invoice #CR-20251104-7928', 291000.00, 0.00, 2507662.75, 5, NULL, '2025-11-09 17:33:26'),
(24, 1, '2025-11-09', 'invoice', 'credit_orders', 14, 'CR-20251104-7928', 'Credit sale - Invoice #CR-20251104-7928', 291000.00, 0.00, 2798662.75, 1, 40, '2025-11-09 17:53:21'),
(25, 1, '2025-11-10', 'invoice', 'credit_orders', 16, 'CR-20251109-5587', 'Credit sale - Invoice #CR-20251109-5587', 340000.00, 0.00, 3138662.75, 5, 41, '2025-11-10 17:40:08'),
(26, 1, '2025-11-10', 'invoice', 'credit_orders', 15, 'CR-20251104-7505', 'Credit sale - Invoice #CR-20251104-7505', 221500.00, 0.00, 3360162.75, 1, 42, '2025-11-10 17:43:55'),
(27, 10, '2025-11-13', 'invoice', 'credit_orders', 17, 'CR-20251113-5446', 'Credit sale - Invoice #CR-20251113-5446', 682000.00, 0.00, 805457.00, 1, 43, '2025-11-12 20:03:02'),
(28, 9, '2025-11-13', 'invoice', 'credit_orders', 18, 'CR-20251113-7046', 'Credit sale - Invoice #CR-20251113-7046', 682000.00, 0.00, 805345.00, 1, 44, '2025-11-12 20:06:04'),
(29, 13, '2025-11-16', 'payment', 'customer_payments', 13, 'RCP-20251116-5147', 'Payment received - Receipt #RCP-20251116-5147', 0.00, 100000.00, -100000.00, 1, NULL, '2025-11-16 15:08:59'),
(30, 10, '2025-11-23', 'payment', 'customer_payments', 14, 'RCP-20251123-7589', 'Payment received - Receipt #RCP-20251123-7589', 0.00, 800000.00, 5457.00, 2, NULL, '2025-11-23 08:29:16'),
(31, 1, '2025-11-23', 'payment', 'customer_payments', 15, 'RCP-20251123-7367', 'Payment received - Receipt #RCP-20251123-7367', 0.00, 3300000.00, 60162.75, 2, NULL, '2025-11-23 08:30:09'),
(32, 16, '2025-11-23', 'invoice', 'credit_orders', 23, 'CR-20251117-8813', 'Credit sale - Invoice #CR-20251117-8813', 160500.00, 0.00, 160500.00, 5, 59, '2025-11-23 11:29:43'),
(33, 7, '2025-11-23', 'invoice', 'credit_orders', 21, 'CR-20251117-7007', 'Credit sale - Invoice #CR-20251117-7007', 22150.00, 0.00, 22365.00, 5, 60, '2025-11-23 11:32:06'),
(34, 16, '2025-11-23', 'payment', 'customer_payments', 16, 'RCP-20251123-1055', 'Payment received - Receipt #RCP-20251123-1055 (Bank Transfer)', 0.00, 50000.00, 110500.00, 2, NULL, '2025-11-23 11:39:22'),
(35, 7, '2025-11-24', 'payment', 'customer_payments', 17, 'RCP-20251124-0014', 'Payment received - Receipt #RCP-20251124-0014 (Bank Transfer)', 0.00, 22365.00, 0.00, 2, NULL, '2025-11-24 06:55:06'),
(36, 1, '2025-11-24', 'payment', 'customer_payments', 18, 'RCP-20251124-1451', 'Payment received - Receipt #RCP-20251124-1451 (Bank Transfer)', 0.00, 60162.00, 3300000.75, 2, NULL, '2025-11-24 07:01:15'),
(37, 10, '2025-11-24', 'payment', 'customer_payments', 19, 'RCP-20251124-6571', 'Payment received - Receipt #RCP-20251124-6571 (Bank Transfer)', 0.00, 15457.00, 790000.00, 2, NULL, '2025-11-24 07:04:39'),
(38, 18, '2025-11-24', 'invoice', 'credit_orders', 28, 'CR-20251123-2708', 'Credit sale - Invoice #CR-20251123-2708', 17950.00, 0.00, 17950.00, 5, 71, '2025-11-24 09:08:29'),
(39, 14, '2025-11-24', 'invoice', 'credit_orders', 29, 'CR-20251124-3350', 'Credit sale - Invoice #CR-20251124-3350', 1246500.00, 0.00, 1246500.00, 5, 72, '2025-11-24 09:08:43'),
(40, 14, '2025-11-24', 'payment', 'customer_payments', 20, 'RCP-20251124-4594', 'Payment received - Receipt #RCP-20251124-4594 (Bank Transfer)', 0.00, 1200000.00, 46500.00, 2, NULL, '2025-11-24 09:12:58'),
(41, 14, '2025-11-24', 'invoice', 'credit_orders', 30, 'CR-20251124-7015', 'Credit sale - Invoice #CR-20251124-7015', 205000.00, 0.00, 251500.00, 5, 74, '2025-11-24 10:36:11'),
(42, 14, '2025-11-24', 'payment', 'customer_payments', 21, 'RCP-20251124-5320', 'Payment received - Receipt #RCP-20251124-5320', 0.00, 250000.00, 996500.00, 2, NULL, '2025-11-24 11:13:50'),
(43, 16, '2025-11-24', 'payment', 'customer_payments', 22, 'RCP-20251124-9930', 'Payment received - Receipt #RCP-20251124-9930', 0.00, 110500.00, 50000.00, 2, NULL, '2025-11-24 11:35:55'),
(44, 22, '2025-11-24', 'payment', 'customer_payments', 23, 'RCP-20251124-8144', 'Payment received - Receipt #RCP-20251124-8144 (Cheque)', 0.00, 1000000.00, -1000000.00, 2, NULL, '2025-11-24 11:36:40'),
(45, 18, '2025-11-24', 'payment', 'customer_payments', 24, 'RCP-20251124-5514', 'Payment received - Receipt #RCP-20251124-5514 (Bank Transfer)', 0.00, 17950.00, 0.00, 2, NULL, '2025-11-24 11:37:10'),
(46, 14, '2025-11-24', 'payment', 'customer_payments', 25, 'RCP-20251124-1291', 'Payment received - Receipt #RCP-20251124-1291', 0.00, 1500.00, 1245000.00, 2, NULL, '2025-11-24 11:37:37'),
(47, 1, '2025-11-24', 'payment', 'customer_payments', 26, 'RCP-20251124-9246', 'Payment received - Receipt #RCP-20251124-9246 (Mobile Banking)', 0.00, 0.75, 3360162.00, 2, NULL, '2025-11-24 11:37:57');

-- --------------------------------------------------------

--
-- Stand-in structure for view `customer_outstanding_summary`
-- (See below for the actual view)
--
CREATE TABLE `customer_outstanding_summary` (
`id` bigint(20) unsigned
,`name` varchar(255)
,`phone_number` varchar(50)
,`current_balance` decimal(12,2)
,`credit_limit` decimal(12,2)
,`total_orders` bigint(21)
,`unpaid_invoices` bigint(21)
,`total_invoiced` decimal(34,2)
,`total_paid` decimal(32,2)
,`total_outstanding` decimal(35,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `customer_payments`
--

CREATE TABLE `customer_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `payment_number` varchar(50) NOT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` enum('Cash','Bank Transfer','Cheque','Mobile Banking','Card') NOT NULL,
  `payment_type` enum('advance','invoice_payment','partial_payment') NOT NULL DEFAULT 'invoice_payment',
  `bank_account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `cash_account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `cheque_number` varchar(50) DEFAULT NULL,
  `cheque_date` date DEFAULT NULL,
  `bank_transaction_type` enum('RTGS','BEFTN','NPSB','Online','Deposit') DEFAULT NULL,
  `allocation_status` enum('allocated','unallocated','partial') DEFAULT 'unallocated',
  `allocated_amount` decimal(12,2) DEFAULT 0.00,
  `reference_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `allocated_to_invoices` text DEFAULT NULL COMMENT 'JSON array',
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `collected_by_employee_id` bigint(20) UNSIGNED DEFAULT NULL,
  `branch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `journal_entry_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customer_payments`
--

INSERT INTO `customer_payments` (`id`, `payment_number`, `receipt_number`, `customer_id`, `payment_date`, `amount`, `payment_method`, `payment_type`, `bank_account_id`, `cash_account_id`, `cheque_number`, `cheque_date`, `bank_transaction_type`, `allocation_status`, `allocated_amount`, `reference_number`, `notes`, `allocated_to_invoices`, `created_by_user_id`, `collected_by_employee_id`, `branch_id`, `journal_entry_id`, `created_at`, `updated_at`) VALUES
(2, 'PAY-20251030-6800', 'RCP-20251030-0002', 1, '2025-10-30', 100000.00, 'Bank Transfer', 'invoice_payment', 1, NULL, NULL, NULL, NULL, 'unallocated', 0.00, 'Cheque Deposited', 'Payment collected in UCB Kamarpara Branch', NULL, 1, NULL, NULL, 12, '2025-10-30 10:18:03', '2025-10-31 04:20:02'),
(3, 'PAY-20251031-7235', 'RCP-20251031-0407', 1, '2025-10-31', 1000.00, 'Cash', 'invoice_payment', 12, 14, NULL, NULL, NULL, 'unallocated', 0.00, 'sedge', '', NULL, 1, NULL, 1, NULL, '2025-10-31 08:18:21', '2025-10-31 08:18:21'),
(4, 'PAY-20251031-1796', 'RCP-20251031-7113', 4, '2025-10-31', 12000.00, 'Cash', 'invoice_payment', NULL, 22, NULL, NULL, NULL, 'allocated', 12000.00, 'Jdhd', 'Jdhh', NULL, 1, 5, NULL, NULL, '2025-10-31 10:48:43', '2025-10-31 10:48:43'),
(5, 'PAY-20251031-7953', 'RCP-20251031-6778', 4, '2025-10-31', 5000.00, 'Bank Transfer', 'invoice_payment', 12, NULL, NULL, NULL, 'Deposit', 'allocated', 5000.00, 'Dgg', 'Cvh', NULL, 1, NULL, NULL, NULL, '2025-10-31 10:54:14', '2025-10-31 10:54:14'),
(6, 'PAY-20251031-3463', 'RCP-20251031-2830', 4, '2025-10-31', 4400.00, 'Cash', 'invoice_payment', NULL, 22, NULL, NULL, NULL, 'allocated', 4400.00, 'Mdnnf', 'Jdhd', NULL, 1, 2, NULL, NULL, '2025-10-31 11:12:09', '2025-10-31 11:12:09'),
(7, 'PAY-20251031-2342', 'RCP-20251031-9036', 4, '2025-10-31', 1000.00, 'Cash', 'invoice_payment', NULL, 22, NULL, NULL, NULL, 'allocated', 1000.00, 'wrggh', 'dgfg', NULL, 1, 1, NULL, NULL, '2025-10-31 13:27:25', '2025-10-31 13:27:25'),
(8, 'PAY-20251031-5528', 'RCP-20251031-3437', 4, '2025-10-31', 1001.00, 'Cash', 'invoice_payment', NULL, 22, NULL, NULL, NULL, 'allocated', 1001.00, 'fdg', 'big', NULL, 1, 6, NULL, NULL, '2025-10-31 13:35:14', '2025-10-31 13:35:14'),
(9, 'PAY-20251031-9836', 'RCP-20251031-7315', 1, '2025-10-31', 1000000.00, 'Bank Transfer', 'invoice_payment', 12, NULL, NULL, NULL, 'Deposit', 'allocated', 1000000.00, 'Slip voucher', 'Number sig', NULL, 1, NULL, NULL, NULL, '2025-10-31 13:48:18', '2025-10-31 13:48:18'),
(10, 'PAY-20251101-8954', 'RCP-20251101-6618', 4, '2025-11-01', 5000.00, 'Bank Transfer', 'invoice_payment', 13, NULL, NULL, NULL, 'Deposit', 'allocated', 5000.00, 'ddfhgf', NULL, NULL, 2, NULL, 4, NULL, '2025-11-01 07:23:54', '2025-11-01 07:23:54'),
(11, 'PAY-20251101-7859', 'RCP-20251101-4027', 11, '2025-11-01', 2345566.00, 'Cash', 'invoice_payment', NULL, 22, NULL, NULL, NULL, 'allocated', 2345566.00, 'ruygi', 'gfgiu', NULL, 1, 1, NULL, NULL, '2025-11-01 11:18:58', '2025-11-01 11:18:58'),
(12, 'PAY-20251101-0656', 'RCP-20251101-5196', 11, '2025-11-01', 564654.00, 'Bank Transfer', 'invoice_payment', 12, NULL, NULL, NULL, 'Deposit', 'allocated', 564654.00, '6457', '7687', NULL, 1, NULL, NULL, NULL, '2025-11-01 11:21:16', '2025-11-01 11:21:16'),
(13, 'PAY-20251116-7674', 'RCP-20251116-5147', 13, '2025-11-16', 100000.00, 'Cash', 'invoice_payment', NULL, 28, NULL, NULL, NULL, 'allocated', 100000.00, NULL, NULL, NULL, 1, 1, NULL, NULL, '2025-11-16 15:08:59', '2025-11-16 15:08:59'),
(14, 'PAY-20251123-3321', 'RCP-20251123-7589', 10, '2025-11-23', 800000.00, 'Cash', 'invoice_payment', NULL, 22, NULL, NULL, NULL, 'unallocated', 0.00, NULL, NULL, NULL, 2, 4, 4, NULL, '2025-11-23 08:29:16', '2025-11-23 08:29:16'),
(15, 'PAY-20251123-5854', 'RCP-20251123-7367', 1, '2025-11-23', 3300000.00, 'Cash', 'invoice_payment', NULL, 15, NULL, NULL, NULL, 'unallocated', 0.00, NULL, NULL, NULL, 2, 5, 4, NULL, '2025-11-23 08:30:09', '2025-11-23 08:30:09'),
(16, 'PAY-20251123-7710', 'RCP-20251123-1055', 16, '2025-11-23', 50000.00, 'Bank Transfer', 'invoice_payment', 12, NULL, NULL, NULL, 'NPSB', 'unallocated', 0.00, '122345', NULL, NULL, 2, 4, 4, NULL, '2025-11-23 11:39:22', '2025-11-23 11:39:22'),
(17, 'PAY-20251124-5017', 'RCP-20251124-0014', 7, '2025-11-24', 22365.00, 'Bank Transfer', 'invoice_payment', 12, NULL, NULL, NULL, 'NPSB', 'unallocated', 0.00, '1234566', NULL, NULL, 2, NULL, 4, NULL, '2025-11-24 06:55:06', '2025-11-24 06:55:06'),
(18, 'PAY-20251124-6883', 'RCP-20251124-1451', 1, '2025-11-24', 60162.00, 'Bank Transfer', 'invoice_payment', 13, NULL, NULL, NULL, 'Deposit', 'unallocated', 0.00, '23154', NULL, NULL, 2, NULL, 4, NULL, '2025-11-24 07:01:15', '2025-11-24 07:01:15'),
(19, 'PAY-20251124-2947', 'RCP-20251124-6571', 10, '2025-11-24', 15457.00, 'Bank Transfer', 'invoice_payment', 12, NULL, NULL, NULL, 'Deposit', 'unallocated', 0.00, '321456', NULL, NULL, 2, NULL, 4, NULL, '2025-11-24 07:04:39', '2025-11-24 07:04:39'),
(20, 'PAY-20251124-0756', 'RCP-20251124-4594', 14, '2025-11-24', 1200000.00, 'Bank Transfer', 'invoice_payment', 12, NULL, NULL, NULL, 'Deposit', 'allocated', 1246500.00, '9854663', NULL, NULL, 2, NULL, 4, NULL, '2025-11-24 09:12:58', '2025-11-24 09:12:58'),
(21, 'PAY-20251124-2289', 'RCP-20251124-5320', 14, '2025-11-24', 250000.00, 'Cash', 'invoice_payment', NULL, 21, NULL, NULL, NULL, 'unallocated', 0.00, '5665', NULL, NULL, 2, 5, 4, NULL, '2025-11-24 11:13:50', '2025-11-24 11:13:50'),
(22, 'PAY-20251124-6051', 'RCP-20251124-9930', 16, '2025-11-24', 110500.00, 'Cash', 'invoice_payment', NULL, 15, NULL, NULL, NULL, 'allocated', 220500.00, NULL, NULL, NULL, 2, 1, 4, NULL, '2025-11-24 11:35:55', '2025-11-24 11:35:55'),
(23, 'PAY-20251124-3778', 'RCP-20251124-8144', 22, '2025-11-24', 1000000.00, 'Cheque', 'invoice_payment', 12, NULL, '654614', '2025-11-26', NULL, 'unallocated', 0.00, '652652', NULL, NULL, 2, NULL, 4, NULL, '2025-11-24 11:36:40', '2025-11-24 11:36:40'),
(24, 'PAY-20251124-8940', 'RCP-20251124-5514', 18, '2025-11-24', 17950.00, 'Bank Transfer', 'invoice_payment', 13, NULL, NULL, NULL, 'Deposit', 'unallocated', 0.00, '6656', NULL, NULL, 2, NULL, 4, NULL, '2025-11-24 11:37:10', '2025-11-24 11:37:10'),
(25, 'PAY-20251124-4588', 'RCP-20251124-1291', 14, '2025-11-24', 1500.00, 'Cash', 'invoice_payment', NULL, 28, NULL, NULL, NULL, 'unallocated', 0.00, '6564', NULL, NULL, 2, 1, 4, NULL, '2025-11-24 11:37:37', '2025-11-24 11:37:37'),
(26, 'PAY-20251124-7978', 'RCP-20251124-9246', 1, '2025-11-24', 0.75, 'Mobile Banking', 'invoice_payment', NULL, NULL, NULL, NULL, NULL, 'unallocated', 0.00, '6325121', NULL, NULL, 2, NULL, 4, NULL, '2025-11-24 11:37:57', '2025-11-24 11:37:57');

-- --------------------------------------------------------

--
-- Table structure for table `dashboard_widgets`
--

CREATE TABLE `dashboard_widgets` (
  `id` int(11) UNSIGNED NOT NULL,
  `widget_key` varchar(50) NOT NULL COMMENT 'Unique identifier',
  `widget_name` varchar(255) NOT NULL,
  `widget_description` text DEFAULT NULL,
  `widget_category` enum('sales','finance','inventory','hr','reports','quick_links') NOT NULL,
  `widget_type` enum('stat_card','chart','table','link') NOT NULL DEFAULT 'stat_card',
  `icon` varchar(50) DEFAULT NULL COMMENT 'Font Awesome icon class',
  `color` varchar(20) DEFAULT NULL COMMENT 'Tailwind color class',
  `default_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `required_roles` varchar(255) DEFAULT NULL COMMENT 'JSON array of roles',
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `dashboard_widgets`
--

INSERT INTO `dashboard_widgets` (`id`, `widget_key`, `widget_name`, `widget_description`, `widget_category`, `widget_type`, `icon`, `color`, `default_enabled`, `sort_order`, `required_roles`, `is_active`) VALUES
(1, 'total_sales_today', 'Credit Sales Snap', 'Total sales amount for the date range', 'sales', 'table', 'fa-cash-register', 'blue', 1, 1, '[\"Superadmin\",\"admin\",\"Accounts\",\"accounts-rampura\",\"accounts-srg\",\"accounts-demra\",\"accountspos-demra\",\"accountspos-srg\",\"sales-srg\",\"sales-demra\",\"sales-other\"]', 1),
(2, 'total_orders_today', 'Today\'s Orders', 'Number of orders today', 'sales', 'stat_card', 'fa-shopping-cart', 'green', 1, 2, '[\"Superadmin\",\"admin\",\"Accounts\",\"accounts-rampura\",\"accounts-srg\",\"accounts-demra\",\"accountspos-demra\",\"accountspos-srg\",\"sales-srg\",\"sales-demra\",\"sales-other\"]', 1),
(3, 'pending_orders', 'Pending Orders', 'Orders awaiting processing', 'sales', 'stat_card', 'fa-clock', 'yellow', 1, 3, '[\"Superadmin\",\"admin\",\"Accounts\",\"accounts-rampura\",\"accounts-srg\",\"accounts-demra\",\"accountspos-demra\",\"accountspos-srg\",\"sales-srg\",\"sales-demra\",\"sales-other\"]', 1),
(4, 'monthly_sales_chart', 'Monthly Sales Trend', 'Sales chart for current month', 'sales', 'chart', 'fa-chart-line', 'purple', 1, 4, '[\"Superadmin\",\"admin\",\"Accounts\",\"accounts-rampura\",\"accounts-srg\",\"accounts-demra\",\"accountspos-demra\",\"accountspos-srg\",\"sales-srg\",\"sales-demra\",\"sales-other\"]', 1),
(5, 'total_receivables', 'Accounts Receivable', 'Total outstanding customer payments', 'finance', 'stat_card', 'fa-money-bill-wave', 'red', 1, 10, '[\"Superadmin\",\"admin\",\"Accounts\",\"accounts-rampura\",\"accounts-srg\",\"accounts-demra\",\"accountspos-demra\",\"accountspos-srg\"]', 1),
(6, 'cash_balance', 'Cash Balance', 'Current cash in hand', 'finance', 'stat_card', 'fa-wallet', 'green', 1, 11, '[\"Superadmin\",\"admin\",\"Accounts\",\"accounts-rampura\",\"accounts-srg\",\"accounts-demra\",\"accountspos-demra\",\"accountspos-srg\"]', 1),
(7, 'bank_balance', 'Bank Balance', 'Total bank account balance', 'finance', 'stat_card', 'fa-university', 'blue', 1, 12, '[\"Superadmin\",\"admin\",\"Accounts\",\"accounts-rampura\",\"accounts-srg\",\"accounts-demra\",\"accountspos-demra\",\"accountspos-srg\"]', 1),
(8, 'payments_today', 'Payments Collected Today', 'Customer payments received today', 'finance', 'stat_card', 'fa-hand-holding-usd', 'indigo', 1, 13, '[\"Superadmin\",\"admin\",\"Accounts\",\"accounts-rampura\",\"accounts-srg\",\"accounts-demra\",\"accountspos-demra\",\"accountspos-srg\"]', 1),
(9, 'low_stock_items', 'Low Stock Alert', 'Items below minimum stock level', 'inventory', 'stat_card', 'fa-exclamation-triangle', 'red', 1, 20, '[\"Superadmin\",\"admin\",\"production manager-srg\",\"production manager-demra\"]', 1),
(10, 'total_inventory_value', 'Inventory Value', 'Total value of current inventory', 'inventory', 'stat_card', 'fa-boxes', 'purple', 0, 21, '[\"Superadmin\",\"admin\",\"production manager-srg\",\"production manager-demra\"]', 1),
(11, 'total_employees', 'Total Employees', 'Active employee count', 'hr', 'stat_card', 'fa-users', 'green', 0, 30, '[\"Superadmin\",\"admin\"]', 1),
(12, 'link_create_invoice', 'Create Invoice', 'Quick link to create new invoice', 'quick_links', 'link', 'fa-file-invoice', 'blue', 1, 40, NULL, 1),
(13, 'link_collect_payment', 'Collect Payment', 'Quick link to payment collection', 'quick_links', 'link', 'fa-money-check-alt', 'green', 1, 41, NULL, 1),
(14, 'link_debit_voucher', 'Create Debit Voucher', 'Quick link to expense voucher', 'quick_links', 'link', 'fa-receipt', 'red', 1, 42, NULL, 1),
(15, 'link_customer_list', 'Customer List', 'View all customers', 'quick_links', 'link', 'fa-address-book', 'purple', 1, 43, NULL, 1),
(16, 'report_daily_sales', 'Daily Sales Report', 'View daily sales summary', 'reports', 'link', 'fa-file-alt', 'blue', 1, 50, '[\"Superadmin\",\"admin\",\"Accounts\",\"accounts-rampura\",\"accounts-srg\",\"accounts-demra\",\"sales-srg\",\"sales-demra\",\"sales-other\"]', 1),
(17, 'report_outstanding', 'Outstanding Report', 'Customer outstanding balances', 'reports', 'link', 'fa-clipboard-list', 'red', 1, 51, '[\"Superadmin\",\"admin\",\"Accounts\",\"accounts-rampura\",\"accounts-srg\",\"accounts-demra\",\"sales-srg\",\"sales-demra\",\"sales-other\"]', 1),
(18, 'total_sales_week', 'This Week Sales', 'Total sales for current week', 'sales', 'stat_card', 'fa-calendar-week', 'teal', 0, 5, NULL, 1),
(19, 'total_sales_month', 'This Month Sales', 'Total sales for current month', 'sales', 'stat_card', 'fa-calendar-alt', 'indigo', 0, 6, NULL, 1),
(20, 'pending_payments', 'Pending Payments', 'Customer payments pending collection', 'finance', 'stat_card', 'fa-hourglass-half', 'orange', 0, 14, NULL, 1),
(21, 'expenses_today', 'Expenses Today', 'Total expenses for today', 'finance', 'stat_card', 'fa-file-invoice-dollar', 'red', 0, 15, NULL, 1),
(22, 'top_selling_products', 'Top Selling Products', 'Best performing products', 'reports', 'table', 'fa-trophy', 'yellow', 0, 52, NULL, 1),
(23, 'customer_ledger', 'Customer Ledger', 'View customer account statements', 'quick_links', 'link', 'fa-book', 'indigo', 1, 44, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `debit_vouchers`
--

CREATE TABLE `debit_vouchers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `voucher_number` varchar(50) NOT NULL,
  `voucher_date` date NOT NULL,
  `expense_account_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Debit account - expense',
  `payment_account_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Credit account - cash/bank',
  `amount` decimal(12,2) NOT NULL,
  `paid_to` varchar(255) NOT NULL COMMENT 'Beneficiary name',
  `description` text NOT NULL COMMENT 'Purpose of payment',
  `reference_number` varchar(100) DEFAULT NULL COMMENT 'Bill/Invoice reference',
  `branch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL COMMENT 'Employee associated with expense',
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `approved_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `status` enum('draft','approved','cancelled') NOT NULL DEFAULT 'draft',
  `journal_entry_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `debit_vouchers`
--

INSERT INTO `debit_vouchers` (`id`, `voucher_number`, `voucher_date`, `expense_account_id`, `payment_account_id`, `amount`, `paid_to`, `description`, `reference_number`, `branch_id`, `employee_id`, `created_by_user_id`, `approved_by_user_id`, `approved_at`, `status`, `journal_entry_id`, `created_at`, `updated_at`) VALUES
(1, 'DV-20251031-6982', '2025-10-31', 17, 21, 2000.00, 'pos srg Mr.', 'Hudai', 'Hhh', 4, 2, 1, NULL, NULL, 'approved', 22, '2025-10-31 17:34:54', '2025-10-31 17:34:54'),
(2, 'DV-20251101-1214', '2025-11-01', 31, 12, 5000.00, 'Adnan Illius', 'Boga Lake', 'sfdf', 1, 1, 2, NULL, NULL, 'approved', 26, '2025-11-01 07:20:11', '2025-11-01 07:20:11'),
(3, 'DV-20251122-7606', '2025-11-22', 31, 21, 3000.00, 'Sales Trial', 'For Trail basis', NULL, 4, 6, 1, NULL, NULL, 'approved', 56, '2025-11-21 20:38:13', '2025-11-21 20:38:13');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `created_at`, `updated_at`) VALUES
(1, 'Administration', '2025-10-23 13:48:25', '2025-10-23 13:48:25'),
(2, 'Accounts', '2025-10-23 13:48:25', '2025-10-23 13:48:25'),
(3, 'Production', '2025-10-23 13:48:25', '2025-10-23 13:48:25'),
(4, 'Dispatch', '2025-10-23 13:48:25', '2025-10-23 13:48:25'),
(5, 'Sales', '2025-10-23 13:48:25', '2025-10-23 13:48:25'),
(6, 'Head Office', '2025-10-23 13:51:35', '2025-10-23 13:51:35'),
(7, 'Demra Office', '2025-10-23 13:51:35', '2025-10-23 13:51:35'),
(8, 'Sirajganj Office', '2025-10-23 13:51:35', '2025-10-23 13:51:35');

-- --------------------------------------------------------

--
-- Table structure for table `drivers`
--

CREATE TABLE `drivers` (
  `id` int(11) NOT NULL,
  `driver_name` varchar(255) NOT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `phone_number` varchar(20) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `nid_number` varchar(50) DEFAULT NULL,
  `license_number` varchar(50) DEFAULT NULL,
  `license_type` enum('Light','Medium','Heavy','Special') DEFAULT NULL,
  `license_issue_date` date DEFAULT NULL,
  `license_expiry_date` date DEFAULT NULL,
  `driver_type` enum('Permanent','Temporary') DEFAULT 'Permanent',
  `status` enum('Active','Inactive','On Leave') DEFAULT 'Active',
  `rating` decimal(3,2) DEFAULT 5.00,
  `total_trips` int(11) DEFAULT 0,
  `assigned_vehicle_id` int(11) DEFAULT NULL,
  `assigned_branch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `address` text DEFAULT NULL,
  `emergency_contact_name` varchar(255) DEFAULT NULL,
  `emergency_contact_phone` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `join_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `salary` decimal(10,2) DEFAULT NULL,
  `daily_rate` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `drivers`
--

INSERT INTO `drivers` (`id`, `driver_name`, `photo_path`, `phone_number`, `email`, `nid_number`, `license_number`, `license_type`, `license_issue_date`, `license_expiry_date`, `driver_type`, `status`, `rating`, `total_trips`, `assigned_vehicle_id`, `assigned_branch_id`, `address`, `emergency_contact_name`, `emergency_contact_phone`, `date_of_birth`, `join_date`, `notes`, `salary`, `daily_rate`, `created_at`, `updated_at`) VALUES
(1, 'Karim Mia', NULL, '01712345678', 'karim.driver@example.com', '1985123456789012', 'DL-DHK-123456', 'Heavy', '2015-03-10', '2030-03-10', 'Permanent', 'Active', 4.80, 152, 6, NULL, 'House 45, Road 12, Mirpur, Dhaka-1216', 'Fatema Begum', '01798765432', '1985-06-15', '2015-04-01', 'Experienced heavy vehicle driver, excellent safety record', 25000.00, NULL, '2025-11-08 16:37:58', '2025-11-24 10:36:11'),
(2, 'Rahim Uddin', NULL, '01823456789', 'rahim.temp@example.com', '1990987654321098', 'DL-DHK-789012', 'Medium', '2018-07-20', '2028-07-20', 'Temporary', 'Active', 4.50, 47, 12, NULL, 'House 78, Lane 5, Uttara, Dhaka-1230', 'Rashida Khatun', '01656789012', '1990-11-08', '2024-09-15', 'Temporary driver for peak season, available for van and pickup deliveries', 18000.00, 1200.00, '2025-11-08 16:37:58', '2025-11-23 11:32:06'),
(23, 'Md. Aminul Islam', '/uploads/drivers/aminul.jpg', '01711223344', 'aminul.driver@example.com', '1985261734', 'DK-00123456L', 'Light', '2020-03-15', '2025-03-14', 'Permanent', 'Active', 4.85, 1206, 5, 1, '12/A, Mirpur 10, Dhaka-1216', 'Most. Rahima Begum', '01711223345', '1985-06-01', '2018-01-10', 'Senior driver, assigned to executive sedan.', 22000.00, NULL, '2025-11-09 17:52:55', '2025-11-09 17:53:21'),
(24, 'Sumon Das', '/uploads/drivers/sumon.jpg', '01819556677', 'sumon.das@example.com', '1990150987654', 'CTG-00456789M', 'Medium', '2019-11-01', '2024-10-31', 'Permanent', 'Active', 4.70, 950, NULL, 2, 'Road 3, Nasirabad H/S, Chattogram', 'Bipul Das', '01819556678', '1990-01-15', '2019-12-01', 'Assigned to Chattogram branch for mini-truck delivery.', 19500.00, NULL, '2025-11-09 17:52:55', '2025-11-09 17:52:55'),
(25, 'Kamal Hossain', NULL, '01912889900', 'kamal.hossain@gmail.com', '1988301123', 'BD-L-5566778', 'Light', '2021-02-10', '2026-02-09', 'Temporary', 'Active', 4.90, 315, 1, 1, 'Holding 50, Mohammadpur, Dhaka', 'Fatima Akter', '01912889901', '1988-03-22', '2023-05-01', 'On-call driver, primarily for backup.', NULL, 1200.00, '2025-11-09 17:52:55', '2025-11-23 11:29:43'),
(26, 'Harun Rashid', '/uploads/drivers/harun.jpg', '01678102030', 'harun.rashid@example.com', '1979051456', 'DH-00987654H', 'Heavy', '2018-07-20', '2023-07-19', 'Permanent', 'On Leave', 4.60, 830, NULL, 1, 'Gabtoli, Dhaka', 'Md. Selim', '01678102031', '1979-05-14', '2015-03-01', 'Senior heavy vehicle (bus) driver. Currently on medical leave.', 25000.00, NULL, '2025-11-09 17:52:55', '2025-11-09 17:52:55'),
(27, 'Priya Akter', '/uploads/drivers/priya.jpg', '01551234567', 'priya.akter@example.com', '1995112233', 'DK-00223344L', 'Light', '2022-01-30', '2027-01-29', 'Permanent', 'Active', 4.95, 611, 11, 1, 'Apt 4B, House 20, Gulshan 1, Dhaka', 'Anwar Hossain', '01712345678', '1995-11-10', '2022-02-15', 'Assigned to corporate client pool. Excellent rating.', 20000.00, NULL, '2025-11-09 17:52:55', '2025-11-10 17:40:08'),
(28, 'Rafiqul Alam', '/uploads/drivers/rafiqul.jpg', '01720304050', 'rafiq.alam@gmail.com', '1982071512345', 'BD-M-1122334', 'Medium', '2017-06-10', '2027-06-09', 'Permanent', 'Active', 4.75, 1503, 2, 2, 'Chowkbazar, Chattogram', 'Sultana Begum', '01720304051', '1982-07-15', '2017-07-01', 'Handles logistics and goods transport.', 21000.00, NULL, '2025-11-09 17:52:55', '2025-11-24 09:08:43'),
(29, 'Jamal Mia', NULL, '01990506070', 'jamal.mia@yahoo.com', '1992120198', 'DK-00778899L', 'Light', '2023-01-05', '2028-01-04', 'Temporary', 'Active', 4.80, 151, 5, 1, 'Basabo, Dhaka', 'Korim Mia', '01990506071', '1992-12-01', '2024-01-10', 'Weekend and night shift temporary driver.', NULL, 1300.00, '2025-11-09 17:52:55', '2025-11-24 09:08:29'),
(30, 'Abdus Sattar', '/uploads/drivers/sattar.jpg', '01811654321', 'abdus.sattar@example.com', '197502031234567', 'SYL-00112233H', 'Heavy', '2015-10-10', '2025-10-09', 'Permanent', 'Active', 4.65, 1100, NULL, 3, 'Zindabazar, Sylhet', 'Abdus Salam', '01711654321', '1975-02-03', '2016-01-01', 'Inter-district truck driver, Sylhet branch.', 24500.00, NULL, '2025-11-09 17:52:55', '2025-11-09 17:52:55'),
(31, 'Rezaul Karim', '/uploads/drivers/rezaul.jpg', '01615403020', 'rezaul.karim@example.com', '1993041876', 'DK-00334455L', 'Light', '2021-08-25', '2026-08-24', 'Permanent', 'Active', 4.80, 720, NULL, 1, 'House 5, Road 12, Uttara, Dhaka', 'Farida Yasmin', '01615403021', '1993-04-18', '2021-09-01', 'Assigned to admin pool vehicle.', 18000.00, NULL, '2025-11-09 17:52:55', '2025-11-09 17:52:55'),
(32, 'Liton Kumer', NULL, '01777888999', 'liton.kumer@gmail.com', '1989101034', 'DK-00990011M', 'Medium', '2019-05-12', '2024-05-11', 'Permanent', 'Inactive', 4.50, 910, NULL, 1, 'Tongi, Gazipur', 'Shanti Rani', '01777888990', '1989-10-10', '2019-06-01', 'Driver for staff bus. Vehicle is under maintenance, so driver is Inactive.', 20500.00, NULL, '2025-11-09 17:52:55', '2025-11-09 17:52:55');

-- --------------------------------------------------------

--
-- Table structure for table `driver_attendance`
--

CREATE TABLE `driver_attendance` (
  `id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `status` enum('Present','Absent','Leave','Half Day') DEFAULT 'Present',
  `check_in_time` time DEFAULT NULL,
  `check_out_time` time DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `driver_documents`
--

CREATE TABLE `driver_documents` (
  `id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `document_type` varchar(100) NOT NULL,
  `document_number` varchar(100) DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Links to the `users` table',
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `position_id` int(11) DEFAULT NULL,
  `hire_date` date NOT NULL,
  `base_salary` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','on_leave','terminated') NOT NULL DEFAULT 'active',
  `profile_picture` varchar(255) DEFAULT NULL,
  `branch_id` int(11) UNSIGNED DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `user_id`, `first_name`, `last_name`, `email`, `phone`, `address`, `position_id`, `hire_date`, `base_salary`, `status`, `profile_picture`, `branch_id`) VALUES
(1, 2, 'Adnan', 'Illius', 'adnan@ujjalfm.com', '01911111111', 'Sirajgonj, Hoosenpur', 17, '2025-08-01', 30000.00, 'active', NULL, 4),
(2, 3, 'pos srg', 'Mr.', 'possrg@gmail.com', '', '', 18, '2025-07-01', 15000.00, 'active', NULL, 1),
(3, 1, 'Dhrobe', 'Islam', 'superadmin@ujjalfm.com', '', '', 14, '2025-01-01', 0.00, 'active', NULL, 4),
(4, 4, 'Sahosh', 'Ahmed', 'sahosh@gmail.com', '123434656', 'Demra', 20, '2025-04-01', 30000.00, 'active', NULL, 2),
(5, 5, 'Soron', 'Ahmed', 'soron@ujjalfm.com', '876787576', 'fuyi', 21, '2025-07-01', 22000.00, 'active', NULL, 2),
(6, 6, 'Sales', 'Trial', 'sales_demra@ujjalfm.com', '23435652', 'Demra', 23, '2025-06-01', 10000.00, 'active', NULL, 2),
(7, 7, 'expense', 'manager', 'expense_demra@ujjalfm.com', '23453564', 'ddgd', 16, '0001-10-01', 100000.00, 'active', NULL, 2),
(8, 8, 'Transport', 'Manager', 'transport_mng@ujjalfm.com', '12423534', 'adbd', 27, '2025-11-01', 20000.00, 'active', NULL, 2);

-- --------------------------------------------------------

--
-- Table structure for table `eod_audit_trail`
--

CREATE TABLE `eod_audit_trail` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `eod_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Original EOD ID',
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `eod_date` date NOT NULL COMMENT 'Date of the EOD that was modified',
  `action` enum('reopen','modify','delete') NOT NULL DEFAULT 'reopen',
  `reason` text NOT NULL COMMENT 'Reason provided by admin',
  `performed_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `performed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `old_data` longtext DEFAULT NULL COMMENT 'JSON snapshot of EOD before action'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for EOD modifications';

-- --------------------------------------------------------

--
-- Table structure for table `eod_summary`
--

CREATE TABLE `eod_summary` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `eod_date` date NOT NULL,
  `total_orders` int(11) NOT NULL DEFAULT 0,
  `total_items_sold` int(11) NOT NULL DEFAULT 0,
  `gross_sales` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_sales` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_methods_json` text DEFAULT NULL,
  `top_products_json` text DEFAULT NULL,
  `opening_cash` decimal(12,2) DEFAULT 0.00,
  `cash_sales` decimal(12,2) DEFAULT 0.00,
  `cash_withdrawals` decimal(12,2) DEFAULT 0.00,
  `expected_cash` decimal(12,2) DEFAULT 0.00,
  `actual_cash` decimal(12,2) DEFAULT 0.00,
  `variance_notes` text DEFAULT NULL,
  `peak_hour` varchar(20) DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `eod_summary`
--

INSERT INTO `eod_summary` (`id`, `branch_id`, `eod_date`, `total_orders`, `total_items_sold`, `gross_sales`, `total_discount`, `net_sales`, `payment_methods_json`, `top_products_json`, `opening_cash`, `cash_sales`, `cash_withdrawals`, `expected_cash`, `actual_cash`, `variance_notes`, `peak_hour`, `created_by_user_id`, `created_at`) VALUES
(1, 1, '2025-11-01', 1, 5, 11150.00, 50.00, 11100.00, '{\"Cash\":{\"count\":1,\"amount\":11100}}', '[{\"name\":\"1 Hati\",\"quantity\":5,\"revenue\":11100}]', 0.00, 11100.00, 0.00, 11460.00, 11460.00, NULL, '01:00 PM', 3, '2025-11-01 07:08:20'),
(2, 4, '2025-11-16', 1, 5, 11150.00, 100.00, 11050.00, '{\"Bank Deposit\":{\"count\":1,\"amount\":11050}}', '[{\"name\":\"1 Hati\",\"quantity\":5,\"revenue\":11050}]', 0.00, 0.00, 0.00, 0.00, 0.00, NULL, '06:00 PM', 1, '2025-11-16 13:47:22'),
(3, 4, '2025-11-25', 1, 3, 5250.00, 150.00, 5100.00, '{\"Cash\":{\"count\":1,\"amount\":5100}}', '[{\"name\":\"1 Hati\",\"quantity\":3,\"revenue\":5200}]', 0.00, 5100.00, 0.00, 5100.00, 5100.00, NULL, '08:00 PM', 1, '2025-11-25 14:34:14');

-- --------------------------------------------------------

--
-- Table structure for table `fuel_logs`
--

CREATE TABLE `fuel_logs` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `trip_id` int(11) DEFAULT NULL,
  `fuel_date` date NOT NULL,
  `fuel_type` varchar(50) DEFAULT NULL,
  `quantity_liters` decimal(8,2) NOT NULL,
  `price_per_liter` decimal(10,2) NOT NULL,
  `total_cost` decimal(10,2) GENERATED ALWAYS AS (`quantity_liters` * `price_per_liter`) STORED,
  `odometer_reading` int(11) DEFAULT NULL,
  `filled_by` varchar(255) DEFAULT NULL,
  `station_name` varchar(255) DEFAULT NULL,
  `receipt_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `payment_account_id` int(11) DEFAULT NULL COMMENT 'FK to chart_of_accounts - which cash/bank account was used',
  `handled_by_employee_id` int(11) DEFAULT NULL COMMENT 'FK to employees - who handled the transaction',
  `journal_entry_id` int(11) DEFAULT NULL COMMENT 'FK to journal_entries'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fuel_logs`
--

INSERT INTO `fuel_logs` (`id`, `vehicle_id`, `trip_id`, `fuel_date`, `fuel_type`, `quantity_liters`, `price_per_liter`, `odometer_reading`, `filled_by`, `station_name`, `receipt_number`, `notes`, `created_by_user_id`, `created_at`, `payment_account_id`, `handled_by_employee_id`, `journal_entry_id`) VALUES
(3, 10, 3, '2025-11-14', 'Diesel', 50.00, 110.00, 90000, 'Sales Trial', 'Padma', NULL, 'Rjd', 1, '2025-11-13 23:48:49', NULL, NULL, NULL),
(4, 10, 1, '2025-11-14', 'Diesel', 50.00, 110.00, 90000, 'Sales Trial', 'Padma', NULL, 'Ok', 1, '2025-11-14 02:37:09', NULL, NULL, NULL),
(5, 5, 2, '2025-11-14', 'Diesel', 80.00, 110.00, 95000, 'Soron Ahmed', 'Padma', NULL, 'Nai nai', 1, '2025-11-14 02:41:30', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `goods_received_adnan`
--

CREATE TABLE `goods_received_adnan` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(36) NOT NULL DEFAULT uuid(),
  `grn_number` varchar(50) NOT NULL COMMENT 'Auto-generated GRN number',
  `purchase_order_id` bigint(20) UNSIGNED NOT NULL,
  `po_number` varchar(50) NOT NULL COMMENT 'Cached for quick reference',
  `grn_date` date NOT NULL,
  `truck_number` varchar(20) DEFAULT NULL COMMENT '4-digit truck identification',
  `supplier_id` bigint(20) UNSIGNED NOT NULL,
  `supplier_name` varchar(255) NOT NULL COMMENT 'Cached supplier name',
  `quantity_received_kg` decimal(15,2) NOT NULL,
  `unit_price_per_kg` decimal(15,4) NOT NULL COMMENT 'From PO',
  `total_value` decimal(15,2) NOT NULL COMMENT 'quantity_received_kg × unit_price_per_kg',
  `expected_quantity` decimal(15,2) DEFAULT NULL COMMENT 'Expected weight',
  `weight_variance` decimal(10,2) GENERATED ALWAYS AS (`quantity_received_kg` - ifnull(`expected_quantity`,0)) STORED,
  `variance_percentage` decimal(5,2) DEFAULT NULL COMMENT 'Percentage variance',
  `variance_remarks` text DEFAULT NULL COMMENT 'e.g., 35kg loss, 95kg gain',
  `unload_point_branch_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Sirajganj or Demra',
  `unload_point_name` varchar(100) DEFAULT NULL COMMENT 'সিরাজগঞ্জ or ডেমরা',
  `grn_status` enum('draft','verified','posted','cancelled') DEFAULT 'verified',
  `remarks` text DEFAULT NULL,
  `receiver_user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Who recorded this GRN',
  `verified_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `journal_entry_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Links to journal_entries',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Goods Received Notes - Adnan Module with truck tracking';

--
-- Dumping data for table `goods_received_adnan`
--

INSERT INTO `goods_received_adnan` (`id`, `uuid`, `grn_number`, `purchase_order_id`, `po_number`, `grn_date`, `truck_number`, `supplier_id`, `supplier_name`, `quantity_received_kg`, `unit_price_per_kg`, `total_value`, `expected_quantity`, `variance_percentage`, `variance_remarks`, `unload_point_branch_id`, `unload_point_name`, `grn_status`, `remarks`, `receiver_user_id`, `verified_by_user_id`, `verified_at`, `journal_entry_id`, `created_at`, `updated_at`) VALUES
(1, '82ba8477-c8b6-11f0-93c1-10ffe0a28e39', 'GRN-20251124-0001', 1, '442', '2025-11-24', '2234', 9, 'আরোফা ট্রেডিং', 220000.00, 30.0000, 6600000.00, 250000.00, -12.00, NULL, NULL, 'ডেমরা', 'verified', 'partially received', 1, NULL, NULL, NULL, '2025-11-23 21:51:05', '2025-11-23 21:51:05'),
(2, '05d1b541-c8f7-11f0-93c1-10ffe0a28e39', 'GRN-20251124-0002', 3, '444', '2025-11-24', '3090', 10, 'আলম ট্রেডিং', 20000.00, 38.8900, 777800.00, 19975.00, 0.13, NULL, NULL, 'ডেমরা', 'verified', 'hudai', 2, NULL, NULL, NULL, '2025-11-24 05:32:52', '2025-11-24 05:32:52'),
(3, '53d08528-c8f7-11f0-93c1-10ffe0a28e39', 'GRN-20251124-0003', 3, '444', '2025-11-24', '7151', 10, 'আলম ট্রেডিং', 30000.00, 38.8900, 1166700.00, 30120.00, -0.40, NULL, NULL, 'সিরাজগঞ্জ', 'verified', '120kg gain', 2, NULL, NULL, NULL, '2025-11-24 05:35:03', '2025-11-24 05:35:03');

--
-- Triggers `goods_received_adnan`
--
DELIMITER $$
CREATE TRIGGER `after_grn_adnan_insert` AFTER INSERT ON `goods_received_adnan` FOR EACH ROW BEGIN
    -- Update purchase order totals
    UPDATE `purchase_orders_adnan` 
    SET 
        `total_received_qty` = (
            SELECT IFNULL(SUM(`quantity_received_kg`), 0)
            FROM `goods_received_adnan`
            WHERE `purchase_order_id` = NEW.`purchase_order_id`
            AND `grn_status` IN ('verified', 'posted')
        ),
        `total_received_value` = (
            SELECT IFNULL(SUM(`total_value`), 0)
            FROM `goods_received_adnan`
            WHERE `purchase_order_id` = NEW.`purchase_order_id`
            AND `grn_status` IN ('verified', 'posted')
        )
    WHERE `id` = NEW.`purchase_order_id`;
    
    -- Update delivery status
    UPDATE `purchase_orders_adnan`
    SET `delivery_status` = CASE
        WHEN `total_received_qty` >= `quantity_kg` THEN 'completed'
        WHEN `total_received_qty` > `quantity_kg` THEN 'over_received'
        WHEN `total_received_qty` > 0 THEN 'partial'
        ELSE 'pending'
    END
    WHERE `id` = NEW.`purchase_order_id`;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_grn_adnan_update` AFTER UPDATE ON `goods_received_adnan` FOR EACH ROW BEGIN
    -- Update purchase order totals
    UPDATE `purchase_orders_adnan` 
    SET 
        `total_received_qty` = (
            SELECT IFNULL(SUM(`quantity_received_kg`), 0)
            FROM `goods_received_adnan`
            WHERE `purchase_order_id` = NEW.`purchase_order_id`
            AND `grn_status` IN ('verified', 'posted')
        ),
        `total_received_value` = (
            SELECT IFNULL(SUM(`total_value`), 0)
            FROM `goods_received_adnan`
            WHERE `purchase_order_id` = NEW.`purchase_order_id`
            AND `grn_status` IN ('verified', 'posted')
        )
    WHERE `id` = NEW.`purchase_order_id`;
    
    -- Update delivery status
    UPDATE `purchase_orders_adnan`
    SET `delivery_status` = CASE
        WHEN `total_received_qty` >= `quantity_kg` THEN 'completed'
        WHEN `total_received_qty` > `quantity_kg` THEN 'over_received'
        WHEN `total_received_qty` > 0 THEN 'partial'
        ELSE 'pending'
    END
    WHERE `id` = NEW.`purchase_order_id`;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `goods_received_items`
--

CREATE TABLE `goods_received_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `grn_id` bigint(20) UNSIGNED NOT NULL,
  `po_item_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Links to purchase_order_items',
  `variant_id` bigint(20) UNSIGNED DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_type` enum('raw_material','finished_goods','packaging','supplies','other') NOT NULL DEFAULT 'raw_material',
  `ordered_quantity` decimal(12,3) NOT NULL,
  `received_quantity` decimal(12,3) NOT NULL,
  `accepted_quantity` decimal(12,3) NOT NULL DEFAULT 0.000,
  `rejected_quantity` decimal(12,3) NOT NULL DEFAULT 0.000,
  `unit_of_measure` varchar(50) DEFAULT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `storage_location` varchar(100) DEFAULT NULL,
  `condition_status` enum('good','damaged','expired','other') DEFAULT 'good',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `goods_received_notes`
--

CREATE TABLE `goods_received_notes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(36) NOT NULL DEFAULT uuid(),
  `grn_number` varchar(50) NOT NULL COMMENT 'e.g., GRN-20251120-0001',
  `purchase_order_id` bigint(20) UNSIGNED NOT NULL,
  `supplier_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL COMMENT 'Receiving branch',
  `received_date` date NOT NULL,
  `received_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `supplier_invoice_number` varchar(100) DEFAULT NULL,
  `supplier_invoice_date` date DEFAULT NULL,
  `vehicle_number` varchar(50) DEFAULT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `status` enum('draft','received','inspected','accepted','rejected','partial') NOT NULL DEFAULT 'draft',
  `inspection_notes` text DEFAULT NULL,
  `quality_status` enum('passed','failed','conditional') DEFAULT NULL,
  `total_items` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `truck_number` varchar(20) DEFAULT NULL,
  `quantity_received_kg` decimal(15,2) DEFAULT 0.00,
  `unit_price_per_kg` decimal(15,4) DEFAULT 0.0000,
  `total_value` decimal(15,2) DEFAULT 0.00,
  `unload_point_branch_id` int(11) DEFAULT NULL COMMENT 'FK to branches',
  `weight_variance` decimal(10,2) DEFAULT 0.00 COMMENT 'Positive for gain, negative for loss',
  `variance_remarks` text DEFAULT NULL,
  `receiver_user_id` int(11) DEFAULT NULL COMMENT 'Who recorded the receipt',
  `grn_date` date DEFAULT NULL,
  `grn_status` enum('draft','verified','posted') DEFAULT 'draft'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `variant_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Links to the `product_variants` table',
  `branch_id` int(11) UNSIGNED NOT NULL COMMENT 'Links to the `branches` table',
  `quantity` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`id`, `variant_id`, `branch_id`, `quantity`, `updated_at`) VALUES
(1, 2, 1, 2474, '2025-11-03 19:40:15'),
(2, 3, 2, 1000, '2025-11-01 06:55:58'),
(3, 3, 4, 92, '2025-11-25 14:32:04'),
(4, 3, 3, 100, '2025-11-01 06:56:25'),
(6, 3, 1, 986, '2025-11-03 19:40:15');

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(36) NOT NULL DEFAULT uuid(),
  `transaction_date` date NOT NULL,
  `description` varchar(255) NOT NULL COMMENT 'e.g., Sale Invoice #1002 to Customer X',
  `related_document_id` bigint(20) DEFAULT NULL COMMENT 'e.g., The order_id or customer_id',
  `related_document_type` varchar(100) DEFAULT NULL COMMENT 'e.g., Order, CustomerPayment, Bill',
  `responsible_employee_id` int(11) DEFAULT NULL COMMENT 'Links to employees.id for the person who handled the cash/asset',
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `journal_entries`
--

INSERT INTO `journal_entries` (`id`, `uuid`, `transaction_date`, `description`, `related_document_id`, `related_document_type`, `responsible_employee_id`, `created_by_user_id`, `created_at`, `updated_at`) VALUES
(1, 'f2b0a1fd-b013-11f0-9003-10ffe0a28e39', '2025-10-23', 'Opening Balance: ucbl - ujjal flour mills (1662301000000228)', 1, 'BankAccount', NULL, 1, '2025-10-23 13:26:57', '2025-10-23 13:26:57'),
(2, '91efa570-b016-11f0-9003-10ffe0a28e39', '2025-10-23', 'Opening Balance: UCBL - Ujjal Flour Mills (2122101000000123)', 2, 'BankAccount', NULL, 1, '2025-10-23 13:45:43', '2025-10-23 13:45:43'),
(3, '53a9c0db-b01e-11f0-9003-10ffe0a28e39', '2025-10-23', 'Internal Transfer: ucbl - ujjal flour mills (1662301000000228) to UCBL - Ujjal Flour Mills (2122101000000123) - EOD', NULL, 'InternalTransfer', 1, 1, '2025-10-23 14:41:14', '2025-10-23 14:41:14'),
(4, 'ec7e566d-b045-11f0-9003-10ffe0a28e39', '2025-10-24', 'Income: Credit Sales Sirajgonj - dfgfg', NULL, 'GeneralTransaction', NULL, 1, '2025-10-23 19:24:41', '2025-10-23 19:24:41'),
(5, '24921a60-b047-11f0-9003-10ffe0a28e39', '2025-10-24', 'Expense: Salary Expense Sirajgonj - Salary SRG', NULL, 'GeneralTransaction', NULL, 1, '2025-10-23 19:33:25', '2025-10-23 19:33:25'),
(9, '4650863f-b2a6-11f0-9003-10ffe0a28e39', '2025-10-27', 'POS Sale - Order #ORD-20251027-0002 - Cash', 17, 'Order', NULL, 3, '2025-10-26 19:59:26', '2025-10-26 19:59:26'),
(10, '94741664-b300-11f0-9003-10ffe0a28e39', '2025-10-27', 'POS Sale - Order #ORD-20251027-0003 - Cash', 18, 'Order', NULL, 3, '2025-10-27 06:45:52', '2025-10-27 06:45:52'),
(11, 'cc2221c3-b300-11f0-9003-10ffe0a28e39', '2025-10-27', 'Internal Transfer: POS Sales Sirajgonj to ucbl - ujjal flour mills (1662301000000228) - trial cash transfer', NULL, 'InternalTransfer', 2, 3, '2025-10-27 06:47:25', '2025-10-27 06:47:25'),
(12, 'b83b47e7-b579-11f0-9003-10ffe0a28e39', '2025-10-30', 'Customer payment PAY-20251030-6800 from Hazi Abul Kashem', 2, 'customer_payments', NULL, 1, '2025-10-30 10:18:03', '2025-10-30 10:18:03'),
(13, '15c46c2d-b5ae-11f0-9003-10ffe0a28e39', '2025-10-30', 'Credit Sale Invoice #CR-20251028-9123 to Hazi Abul Kashem', 2, 'credit_orders', NULL, 5, '2025-10-30 16:32:54', '2025-10-30 16:32:54'),
(14, 'bc3d4273-b5b1-11f0-9003-10ffe0a28e39', '2025-10-30', 'Credit Sale Invoice #CR-20251028-6227 to Hazi Abul Kashem', 3, 'credit_orders', NULL, 5, '2025-10-30 16:59:02', '2025-10-30 16:59:02'),
(15, 'a873b6f5-b5b3-11f0-9003-10ffe0a28e39', '2025-10-30', 'Credit Sale Invoice #CR-20251030-2349 to Trial Credit ', 4, 'credit_orders', NULL, 5, '2025-10-30 17:12:47', '2025-10-30 17:12:47'),
(16, '2b701e02-b647-11f0-9003-10ffe0a28e39', '2025-10-31', 'Payment received from Trial Credit  - Receipt #RCP-20251031-7113', 4, 'customer_payments', NULL, 1, '2025-10-31 10:48:43', '2025-10-31 10:48:43'),
(17, 'f07046cc-b647-11f0-9003-10ffe0a28e39', '2025-10-31', 'Payment received from Trial Credit  - Receipt #RCP-20251031-6778', 5, 'customer_payments', NULL, 1, '2025-10-31 10:54:14', '2025-10-31 10:54:14'),
(18, '716a104f-b64a-11f0-9003-10ffe0a28e39', '2025-10-31', 'Payment received from Trial Credit  - Receipt #RCP-20251031-2830', 6, 'customer_payments', NULL, 1, '2025-10-31 11:12:09', '2025-10-31 11:12:09'),
(19, '570a2240-b65d-11f0-9003-10ffe0a28e39', '2025-10-31', 'Payment received from Trial Credit  - Receipt #RCP-20251031-9036', 7, 'customer_payments', NULL, 1, '2025-10-31 13:27:25', '2025-10-31 13:27:25'),
(20, '6e5390c6-b65e-11f0-9003-10ffe0a28e39', '2025-10-31', 'Payment received from Trial Credit  - Receipt #RCP-20251031-3437', 8, 'customer_payments', NULL, 1, '2025-10-31 13:35:14', '2025-10-31 13:35:14'),
(21, '4202e516-b660-11f0-9003-10ffe0a28e39', '2025-10-31', 'Payment received from Hazi Abul Kashem - Receipt #RCP-20251031-7315', 9, 'customer_payments', NULL, 1, '2025-10-31 13:48:18', '2025-10-31 13:48:18'),
(22, 'e9725b6b-b67f-11f0-9003-10ffe0a28e39', '2025-10-31', 'Debit Voucher #DV-20251031-6982 - Hudai', 1, 'debit_vouchers', NULL, 1, '2025-10-31 17:34:54', '2025-10-31 17:34:54'),
(23, 'f9c381e3-b6f0-11f0-9003-10ffe0a28e39', '2025-11-01', 'POS Sale - Order #ORD-20251101-0001 - Cash', 19, 'Order', NULL, 3, '2025-11-01 07:04:14', '2025-11-01 07:04:14'),
(24, 'e267848c-b6f1-11f0-9003-10ffe0a28e39', '2025-11-01', 'Internal Transfer: POS Sales Sirajgonj to UCBL - Ujjal Flour Mills (2122101000000123) - dedygf', NULL, 'InternalTransfer', 2, 3, '2025-11-01 07:10:45', '2025-11-01 07:10:45'),
(25, '53da9e2f-b6f2-11f0-9003-10ffe0a28e39', '2025-11-01', 'Expense: Salary Expense Demra - ufg', NULL, 'GeneralTransaction', NULL, 3, '2025-11-01 07:13:55', '2025-11-01 07:13:55'),
(26, '33fc7a4a-b6f3-11f0-9003-10ffe0a28e39', '2025-11-01', 'Debit Voucher #DV-20251101-1214 - Boga Lake', 2, 'debit_vouchers', NULL, 2, '2025-11-01 07:20:11', '2025-11-01 07:20:11'),
(27, 'b8ac444e-b6f3-11f0-9003-10ffe0a28e39', '2025-11-01', 'Payment received from Trial Credit  - Receipt #RCP-20251101-6618', 10, 'customer_payments', NULL, 2, '2025-11-01 07:23:54', '2025-11-01 07:23:54'),
(28, '6950be3b-b6fe-11f0-9003-10ffe0a28e39', '2025-11-01', 'Credit Sale Invoice #CR-20251101-1900 to Test 2', 5, 'credit_orders', NULL, 5, '2025-11-01 08:40:25', '2025-11-01 08:40:25'),
(29, '8f9fd56d-b714-11f0-9003-10ffe0a28e39', '2025-11-01', 'Payment received from asdsdjsfk - Receipt #RCP-20251101-4027', 11, 'customer_payments', NULL, 1, '2025-11-01 11:18:58', '2025-11-01 11:18:58'),
(30, 'e1e58a4c-b714-11f0-9003-10ffe0a28e39', '2025-11-01', 'Payment received from asdsdjsfk - Receipt #RCP-20251101-5196', 12, 'customer_payments', NULL, 1, '2025-11-01 11:21:16', '2025-11-01 11:21:16'),
(31, '5d401b20-b8ec-11f0-a079-10ffe0a28e39', '2025-11-04', 'Credit Sale Invoice #CR-20251101-9073 to Hazi Abul Kashem', 6, 'credit_orders', NULL, 5, '2025-11-03 19:36:16', '2025-11-03 19:36:16'),
(32, 'c018ea2c-b8ec-11f0-a079-10ffe0a28e39', '2025-11-04', 'POS Sale - Order #ORD-20251104-0001 - Cash', 20, 'Order', NULL, 3, '2025-11-03 19:39:02', '2025-11-03 19:39:02'),
(33, 'ebe33faa-b8ec-11f0-a079-10ffe0a28e39', '2025-11-04', 'POS Sale - Order #ORD-20251104-0002 - Cash', 21, 'Order', NULL, 3, '2025-11-03 19:40:15', '2025-11-03 19:40:15'),
(34, '7be4f8a9-b99c-11f0-a079-10ffe0a28e39', '2025-11-04', 'Credit Sale Invoice #CR-20251101-3242 to Babul Mia', 8, 'credit_orders', NULL, 5, '2025-11-04 16:36:59', '2025-11-04 16:36:59'),
(35, '0607a50d-b9a1-11f0-a079-10ffe0a28e39', '2025-11-04', 'Credit Sale Invoice #CR-20251101-7172 to Hazi Abul Kashem', 7, 'credit_orders', NULL, 1, '2025-11-04 17:09:29', '2025-11-04 17:09:29'),
(36, '35cd7dd4-b9a2-11f0-a079-10ffe0a28e39', '2025-11-04', 'Credit Sale Invoice #CR-20251101-7172 to Hazi Abul Kashem', 7, 'credit_orders', NULL, 1, '2025-11-04 17:17:58', '2025-11-04 17:17:58'),
(37, '0b72e3c1-bcc7-11f0-93c1-10ffe0a28e39', '2025-11-08', 'Credit Sale Invoice #CR-20251101-3242 to Babul Mia', 8, 'credit_orders', NULL, 1, '2025-11-08 17:19:12', '2025-11-08 17:19:12'),
(38, 'f77b24ef-bd8e-11f0-93c1-10ffe0a28e39', '2025-11-09', 'Credit Sale Invoice #CR-20251104-7928 to Hazi Abul Kashem', 14, 'credit_orders', NULL, 5, '2025-11-09 17:10:18', '2025-11-09 17:10:18'),
(39, '3324b7b0-bd92-11f0-93c1-10ffe0a28e39', '2025-11-09', 'Credit Sale Invoice #CR-20251104-7928 to Hazi Abul Kashem', 14, 'credit_orders', NULL, 5, '2025-11-09 17:33:26', '2025-11-09 17:33:26'),
(40, 'fb42f6e9-bd94-11f0-93c1-10ffe0a28e39', '2025-11-09', 'Credit Sale Invoice #CR-20251104-7928 to Hazi Abul Kashem', 14, 'credit_orders', NULL, 1, '2025-11-09 17:53:21', '2025-11-09 17:53:21'),
(41, '4cba2e95-be5c-11f0-93c1-10ffe0a28e39', '2025-11-10', 'Credit Sale Invoice #CR-20251109-5587 to Hazi Abul Kashem', 16, 'credit_orders', NULL, 5, '2025-11-10 17:40:08', '2025-11-10 17:40:08'),
(42, 'd4488d9a-be5c-11f0-93c1-10ffe0a28e39', '2025-11-10', 'Credit Sale Invoice #CR-20251104-7505 to Hazi Abul Kashem', 15, 'credit_orders', NULL, 1, '2025-11-10 17:43:55', '2025-11-10 17:43:55'),
(43, '98394e62-c002-11f0-93c1-10ffe0a28e39', '2025-11-13', 'Credit Sale Invoice #CR-20251113-5446 to nibirrrrr', 17, 'credit_orders', NULL, 1, '2025-11-12 20:03:02', '2025-11-12 20:03:02'),
(44, '04bc9e35-c003-11f0-93c1-10ffe0a28e39', '2025-11-13', 'Credit Sale Invoice #CR-20251113-7046 to Test Nibir w', 18, 'credit_orders', NULL, 1, '2025-11-12 20:06:04', '2025-11-12 20:06:04'),
(45, '4f33c444-c0cd-11f0-93c1-10ffe0a28e39', '2025-11-14', 'Fuel purchase for vehicle Ctg Metro-NA-17-1199 - 50.00L @ ৳110.00/L via Petty Cash Demra Mill', 1, 'fuel_logs', 4, 1, '2025-11-13 20:14:07', '2025-11-13 20:14:07'),
(46, 'c4758da7-c0cd-11f0-93c1-10ffe0a28e39', '2025-11-14', 'Fuel purchase for vehicle Ctg Metro-NA-17-1199 - 50.00L @ ৳110.00/L via Petty Cash Demra Mill', 2, 'fuel_logs', 1, 1, '2025-11-13 20:17:24', '2025-11-13 20:17:24'),
(47, '4d287eab-c0eb-11f0-93c1-10ffe0a28e39', '2025-11-14', 'Fuel purchase for vehicle Ctg Metro-NA-17-1199 - 50.00L @ ৳110.00/L via Petty Cash Demra Mill', 3, 'fuel_logs', 6, 1, '2025-11-13 23:48:49', '2025-11-13 23:48:49'),
(48, 'd1933efa-c102-11f0-93c1-10ffe0a28e39', '2025-11-14', 'Fuel purchase for vehicle Ctg Metro-NA-17-1199 - 50.00L @ ৳110.00/L via Petty Cash Demra Mill', 4, 'fuel_logs', 6, 1, '2025-11-14 02:37:09', '2025-11-14 02:37:09'),
(49, '6d2c04dc-c103-11f0-93c1-10ffe0a28e39', '2025-11-14', 'Fuel purchase for vehicle Ctg Metro-TA-13-5678 - 80.00L @ ৳110.00/L via Petty Cash Demra Mill', 5, 'fuel_logs', 5, 1, '2025-11-14 02:41:30', '2025-11-14 02:41:30'),
(50, '3b23fdea-c14c-11f0-93c1-10ffe0a28e39', '2025-11-14', 'Vehicle Maintenance for Ctg Metro-NA-17-1199 - Oil Change (Cost: ৳234,345.00) via Petty Cash Demra Mill', 1, 'maintenance_logs', 4, 1, '2025-11-14 11:22:40', '2025-11-14 11:22:40'),
(51, '2b0a1f7d-c15f-11f0-93c1-10ffe0a28e39', '2024-10-01', 'Document Renewal: Tax Token for Ctg Metro-NA-17-1199 via Petty Cash Demra Mill', 1, 'transport_expenses', 7, 1, '2025-11-14 13:38:13', '2025-11-14 13:38:13'),
(52, '82932837-c163-11f0-93c1-10ffe0a28e39', '2025-11-14', 'Vehicle Rental for nibirrrrr (Vehicle: DHK-HA-5678)', 1, 'vehicle_rentals', NULL, 1, '2025-11-14 14:09:18', '2025-11-14 14:09:18'),
(53, '04ac103a-c2e4-11f0-93c1-10ffe0a28e39', '2025-11-16', 'POS Sale - Order #ORD-20251116-0001 - Bank Deposit', 22, 'Order', NULL, 1, '2025-11-16 12:01:43', '2025-11-16 12:01:43'),
(54, '2df995c9-c2fe-11f0-93c1-10ffe0a28e39', '2025-11-16', 'Payment received from Test for inital credit - Receipt #RCP-20251116-5147', 13, 'customer_payments', NULL, 1, '2025-11-16 15:08:59', '2025-11-16 15:08:59'),
(55, '0772b577-c392-11f0-93c1-10ffe0a28e39', '2025-11-17', 'Expense: Fuel Expense', NULL, 'GeneralTransaction', NULL, 3, '2025-11-17 08:47:20', '2025-11-17 08:47:20'),
(56, '003be0e4-c71a-11f0-93c1-10ffe0a28e39', '2025-11-22', 'Debit Voucher #DV-20251122-7606 - For Trail basis', 3, 'debit_vouchers', NULL, 1, '2025-11-21 20:38:13', '2025-11-21 20:38:13'),
(57, '7f898ec6-c846-11f0-93c1-10ffe0a28e39', '2025-11-23', 'Payment received from nibirrrrr - Receipt #RCP-20251123-7589', 14, 'customer_payments', NULL, 2, '2025-11-23 08:29:16', '2025-11-23 08:29:16'),
(58, '9f219185-c846-11f0-93c1-10ffe0a28e39', '2025-11-23', 'Payment received from Hazi Abul Kashem - Receipt #RCP-20251123-7367', 15, 'customer_payments', NULL, 2, '2025-11-23 08:30:09', '2025-11-23 08:30:09'),
(59, 'b50e7ad0-c85f-11f0-93c1-10ffe0a28e39', '2025-11-23', 'Credit Sale Invoice #CR-20251117-8813 to Mokka Traders', 23, 'credit_orders', NULL, 5, '2025-11-23 11:29:43', '2025-11-23 11:29:43'),
(60, '0a902700-c860-11f0-93c1-10ffe0a28e39', '2025-11-23', 'Credit Sale Invoice #CR-20251117-7007 to Babul Mia', 21, 'credit_orders', NULL, 5, '2025-11-23 11:32:06', '2025-11-23 11:32:06'),
(61, '0e0db57d-c861-11f0-93c1-10ffe0a28e39', '2025-11-23', 'Payment received from Mokka Traders - Receipt #RCP-20251123-1055', 16, 'customer_payments', NULL, 2, '2025-11-23 11:39:22', '2025-11-23 11:39:22'),
(62, '82bc74f8-c8b6-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Goods received for PO 442 - 220000KG Ukraine wheat @ ৳30.0000/KG', 1, 'grn_adnan', NULL, 1, '2025-11-23 21:51:05', '2025-11-23 21:51:05'),
(63, 'eac8b0a4-c8ed-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Payment for PO 442 - আরোফা ট্রেডিং - ৳100000', 2, 'payment_adnan', NULL, 1, '2025-11-24 04:27:42', '2025-11-24 04:27:42'),
(64, '05d5cc1b-c8f7-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Goods received for PO 444 - 20000KG কানাডা wheat @ ৳38.8900/KG', 2, 'grn_adnan', NULL, 2, '2025-11-24 05:32:52', '2025-11-24 05:32:52'),
(65, '53d136ab-c8f7-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Goods received for PO 444 - 30000KG কানাডা wheat @ ৳38.8900/KG', 3, 'grn_adnan', NULL, 2, '2025-11-24 05:35:03', '2025-11-24 05:35:03'),
(66, '9eac296a-c8f7-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Payment for PO 444 - আলম ট্রেডিং - ৳50000', 3, 'payment_adnan', NULL, 2, '2025-11-24 05:37:09', '2025-11-24 05:37:09'),
(67, 'dc2e5c58-c8f8-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Payment for PO 444 - আলম ট্রেডিং - ৳20000', 4, 'payment_adnan', NULL, 2, '2025-11-24 05:46:01', '2025-11-24 05:46:01'),
(68, '82760f28-c902-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Payment received from Babul Mia - Receipt #RCP-20251124-0014', 17, 'customer_payments', NULL, 2, '2025-11-24 06:55:06', '2025-11-24 06:55:06'),
(69, '5e315ce0-c903-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Payment received from Hazi Abul Kashem - Receipt #RCP-20251124-1451', 18, 'customer_payments', NULL, 2, '2025-11-24 07:01:15', '2025-11-24 07:01:15'),
(70, 'd7ebb42d-c903-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Payment received from nibirrrrr - Receipt #RCP-20251124-6571', 19, 'customer_payments', NULL, 2, '2025-11-24 07:04:39', '2025-11-24 07:04:39'),
(71, '245de2a9-c915-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Credit Sale Invoice #CR-20251123-2708 to Salam Store', 28, 'credit_orders', NULL, 5, '2025-11-24 09:08:29', '2025-11-24 09:08:29'),
(72, '2cb563a6-c915-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Credit Sale Invoice #CR-20251124-3350 to Ma Trading Corporation', 29, 'credit_orders', NULL, 5, '2025-11-24 09:08:43', '2025-11-24 09:08:43'),
(73, 'c4c9ca01-c915-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Payment received from Ma Trading Corporation - Receipt #RCP-20251124-4594', 20, 'customer_payments', NULL, 2, '2025-11-24 09:12:58', '2025-11-24 09:12:58'),
(74, '6504d8bb-c921-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Credit Sale Invoice #CR-20251124-7015 to Ma Trading Corporation', 30, 'credit_orders', NULL, 5, '2025-11-24 10:36:11', '2025-11-24 10:36:11'),
(75, 'a7ce0cff-c926-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Payment received from Ma Trading Corporation - Receipt #RCP-20251124-5320', 21, 'customer_payments', NULL, 2, '2025-11-24 11:13:50', '2025-11-24 11:13:50'),
(76, 'bd3c7b90-c929-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Payment received from Mokka Traders - Receipt #RCP-20251124-9930', 22, 'customer_payments', NULL, 2, '2025-11-24 11:35:55', '2025-11-24 11:35:55'),
(77, 'd7dff9e7-c929-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Payment received from Adnan - Receipt #RCP-20251124-8144', 23, 'customer_payments', NULL, 2, '2025-11-24 11:36:40', '2025-11-24 11:36:40'),
(78, 'e9e79b0a-c929-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Payment received from Salam Store - Receipt #RCP-20251124-5514', 24, 'customer_payments', NULL, 2, '2025-11-24 11:37:10', '2025-11-24 11:37:10'),
(79, 'fa582071-c929-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Payment received from Ma Trading Corporation - Receipt #RCP-20251124-1291', 25, 'customer_payments', NULL, 2, '2025-11-24 11:37:37', '2025-11-24 11:37:37'),
(80, '0638b12f-c92a-11f0-93c1-10ffe0a28e39', '2025-11-24', 'Payment received from Hazi Abul Kashem - Receipt #RCP-20251124-9246', 26, 'customer_payments', NULL, 2, '2025-11-24 11:37:57', '2025-11-24 11:37:57'),
(81, '831719d1-ca0b-11f0-93c1-10ffe0a28e39', '2025-11-25', 'POS Sale - Order #ORD-20251125-0001 - Cash', 23, 'Order', NULL, 1, '2025-11-25 14:32:04', '2025-11-25 14:32:04');

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_logs`
--

CREATE TABLE `maintenance_logs` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `maintenance_date` date NOT NULL,
  `maintenance_type` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `cost` decimal(10,2) DEFAULT 0.00,
  `service_provider` varchar(255) DEFAULT NULL,
  `odometer_reading` int(11) DEFAULT NULL,
  `next_service_date` date DEFAULT NULL,
  `next_service_km` int(11) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `maintenance_logs`
--

INSERT INTO `maintenance_logs` (`id`, `vehicle_id`, `maintenance_date`, `maintenance_type`, `description`, `cost`, `service_provider`, `odometer_reading`, `next_service_date`, `next_service_km`, `invoice_number`, `notes`, `created_by_user_id`, `created_at`) VALUES
(1, 10, '2025-11-14', 'Oil Change', 'ergjdlk', 234345.00, 'sazid vai', 90000, '2025-12-19', 95000, 'dfgdgjl', 'fgddt', 1, '2025-11-14 11:22:40');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(36) NOT NULL DEFAULT uuid(),
  `order_number` varchar(50) NOT NULL COMMENT 'Human-readable order number, e.g., ORD-2025-0001',
  `branch_id` int(10) UNSIGNED NOT NULL COMMENT 'Which branch this sale was made from',
  `customer_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Links to customers table, NULL for walk-in',
  `order_date` datetime NOT NULL DEFAULT current_timestamp(),
  `order_type` enum('POS','Credit','Delivery') NOT NULL DEFAULT 'POS',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cart_discount_type` enum('none','percentage','fixed') DEFAULT 'none',
  `cart_discount_value` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('Cash','Bank Deposit','Bank Transfer','Card','Mobile Banking','Credit') NOT NULL DEFAULT 'Cash',
  `payment_reference` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `payment_status` enum('Paid','Partial','Unpaid','Refunded') NOT NULL DEFAULT 'Paid',
  `order_status` enum('Completed','Pending','Cancelled','Refunded') NOT NULL DEFAULT 'Completed',
  `notes` text DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'User who created the order',
  `journal_entry_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Links to journal_entries for accounting',
  `print_count` int(11) DEFAULT 0,
  `last_printed_at` datetime DEFAULT NULL,
  `office_copy_printed` tinyint(1) DEFAULT 0,
  `customer_copy_printed` tinyint(1) DEFAULT 0,
  `delivery_copy_printed` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `uuid`, `order_number`, `branch_id`, `customer_id`, `order_date`, `order_type`, `subtotal`, `tax_amount`, `discount_amount`, `cart_discount_type`, `cart_discount_value`, `total_amount`, `payment_method`, `payment_reference`, `bank_name`, `payment_status`, `order_status`, `notes`, `created_by_user_id`, `journal_entry_id`, `print_count`, `last_printed_at`, `office_copy_printed`, `customer_copy_printed`, `delivery_copy_printed`, `created_at`, `updated_at`) VALUES
(17, '464e6da6-b2a6-11f0-9003-10ffe0a28e39', 'ORD-20251027-0002', 1, NULL, '2025-10-27 01:59:26', 'POS', 6790.00, 0.00, 0.00, 'none', 0.00, 6790.00, 'Cash', NULL, NULL, 'Paid', 'Completed', NULL, 3, 9, 3, '2025-10-27 01:59:29', 1, 1, 1, '2025-10-26 19:59:26', '2025-10-26 19:59:29'),
(18, '9473218e-b300-11f0-9003-10ffe0a28e39', 'ORD-20251027-0003', 1, NULL, '2025-10-27 12:45:52', 'POS', 3360.00, 0.00, 0.00, 'none', 0.00, 3360.00, 'Cash', NULL, NULL, 'Paid', 'Completed', NULL, 3, 10, 3, '2025-10-27 12:45:55', 1, 1, 1, '2025-10-27 06:45:52', '2025-10-27 06:45:55'),
(19, 'f9bfa512-b6f0-11f0-9003-10ffe0a28e39', 'ORD-20251101-0001', 1, NULL, '2025-11-01 13:04:14', 'POS', 11100.00, 0.00, 0.00, 'none', 0.00, 11100.00, 'Cash', NULL, NULL, 'Paid', 'Completed', NULL, 3, 23, 1, '2025-11-01 13:04:20', 1, 0, 0, '2025-11-01 07:04:14', '2025-11-01 07:04:20'),
(20, 'c0179bc1-b8ec-11f0-a079-10ffe0a28e39', 'ORD-20251104-0001', 1, NULL, '2025-11-04 01:39:02', 'POS', 22260.00, 0.00, 0.00, 'none', 0.00, 22260.00, 'Cash', NULL, NULL, 'Paid', 'Completed', NULL, 3, 32, 1, '2025-11-04 01:57:36', 0, 1, 0, '2025-11-03 19:39:02', '2025-11-03 19:57:36'),
(21, 'ebe1867e-b8ec-11f0-a079-10ffe0a28e39', 'ORD-20251104-0002', 1, NULL, '2025-11-04 01:40:15', 'POS', 24790.00, 0.00, 0.00, 'none', 0.00, 24790.00, 'Cash', NULL, NULL, 'Paid', 'Completed', NULL, 3, 33, 3, '2025-11-04 01:56:57', 1, 1, 0, '2025-11-03 19:40:15', '2025-11-03 19:56:57'),
(22, '04aafcd9-c2e4-11f0-93c1-10ffe0a28e39', 'ORD-20251116-0001', 4, 12, '2025-11-16 18:01:43', 'POS', 11050.00, 0.00, 0.00, 'none', 0.00, 11050.00, 'Bank Deposit', '34854525', 'Ucb Bank', 'Paid', 'Completed', NULL, 1, 53, 2, '2025-11-16 19:46:10', 1, 1, 0, '2025-11-16 12:01:43', '2025-11-16 13:46:10'),
(23, '83151db1-ca0b-11f0-93c1-10ffe0a28e39', 'ORD-20251125-0001', 4, 12, '2025-11-25 20:32:04', 'POS', 5200.00, 0.00, 100.00, 'fixed', 100.00, 5100.00, 'Cash', NULL, NULL, 'Paid', 'Completed', NULL, 1, 81, 0, NULL, 0, 0, 0, '2025-11-25 14:32:04', '2025-11-25 14:32:04');

--
-- Triggers `orders`
--
DELIMITER $$
CREATE TRIGGER `after_order_insert_petty_cash` AFTER INSERT ON `orders` FOR EACH ROW BEGIN
    DECLARE v_account_id BIGINT;
    DECLARE v_new_balance DECIMAL(12,2);
    
    IF NEW.order_type = 'POS' AND NEW.payment_method = 'Cash' THEN
        SELECT id, current_balance INTO v_account_id, v_new_balance
        FROM branch_petty_cash_accounts
        WHERE branch_id = NEW.branch_id AND status = 'active'
        LIMIT 1;
        
        IF v_account_id IS NOT NULL THEN
            SET v_new_balance = v_new_balance + NEW.total_amount;
            
            INSERT INTO branch_petty_cash_transactions (
                branch_id, account_id, transaction_date, transaction_type,
                amount, balance_after, reference_type, reference_id,
                description, payment_method, created_by_user_id
            ) VALUES (
                NEW.branch_id, v_account_id, NEW.order_date, 'cash_in',
                NEW.total_amount, v_new_balance, 'orders', NEW.id,
                CONCAT('Cash sale - Order #', NEW.order_number),
                'Cash', NEW.created_by_user_id
            );
            
            UPDATE branch_petty_cash_accounts
            SET current_balance = v_new_balance, updated_at = NOW()
            WHERE id = v_account_id;
        END IF;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Links to orders table',
  `variant_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Links to product_variants table',
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Price at time of sale',
  `item_discount_type` enum('none','percentage','fixed') DEFAULT 'none',
  `item_discount_value` decimal(10,2) DEFAULT 0.00,
  `item_discount_amount` decimal(10,2) DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'quantity * unit_price',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `variant_id`, `quantity`, `unit_price`, `item_discount_type`, `item_discount_value`, `item_discount_amount`, `subtotal`, `discount_amount`, `tax_amount`, `total_amount`, `created_at`) VALUES
(7, 17, 2, 2, 3410.00, 'fixed', 30.00, 30.00, 6820.00, 30.00, 0.00, 6790.00, '2025-10-26 19:59:26'),
(8, 18, 2, 1, 3410.00, 'fixed', 50.00, 50.00, 3410.00, 50.00, 0.00, 3360.00, '2025-10-27 06:45:52'),
(9, 19, 3, 5, 2230.00, 'fixed', 50.00, 50.00, 11150.00, 50.00, 0.00, 11100.00, '2025-11-01 07:04:14'),
(10, 20, 3, 4, 2230.00, 'fixed', 100.00, 100.00, 8920.00, 100.00, 0.00, 8820.00, '2025-11-03 19:39:02'),
(11, 20, 2, 4, 3410.00, 'fixed', 200.00, 200.00, 13640.00, 200.00, 0.00, 13440.00, '2025-11-03 19:39:02'),
(12, 21, 3, 5, 2230.00, 'none', 0.00, 0.00, 11150.00, 0.00, 0.00, 11150.00, '2025-11-03 19:40:15'),
(13, 21, 2, 4, 3410.00, 'none', 0.00, 0.00, 13640.00, 0.00, 0.00, 13640.00, '2025-11-03 19:40:15'),
(14, 22, 3, 5, 2230.00, 'fixed', 100.00, 100.00, 11150.00, 100.00, 0.00, 11050.00, '2025-11-16 12:01:43'),
(15, 23, 3, 3, 1750.00, 'fixed', 50.00, 50.00, 5250.00, 50.00, 0.00, 5200.00, '2025-11-25 14:32:04');

-- --------------------------------------------------------

--
-- Table structure for table `payment_allocations`
--

CREATE TABLE `payment_allocations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `payment_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Links to customer_payments.id',
  `order_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Links to credit_orders.id',
  `allocated_amount` decimal(12,2) NOT NULL,
  `allocation_date` date DEFAULT NULL,
  `allocated_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payment_allocations`
--

INSERT INTO `payment_allocations` (`id`, `payment_id`, `order_id`, `allocated_amount`, `allocation_date`, `allocated_by_user_id`, `created_at`) VALUES
(1, 4, 4, 12000.00, '2025-10-31', 1, '2025-10-31 10:48:43'),
(2, 5, 4, 5000.00, '2025-10-31', 1, '2025-10-31 10:54:14'),
(3, 6, 4, 4400.00, '2025-10-31', 1, '2025-10-31 11:12:09'),
(4, 7, 4, 1000.00, '2025-10-31', 1, '2025-10-31 13:27:25'),
(5, 8, 4, 1001.00, '2025-10-31', 1, '2025-10-31 13:35:14'),
(6, 9, 3, 1000000.00, '2025-10-31', 1, '2025-10-31 13:48:18'),
(7, 10, 4, 5000.00, '2025-11-01', 2, '2025-11-01 07:23:54'),
(8, 11, 11, 2345566.00, '2025-11-01', 1, '2025-11-01 11:18:58'),
(9, 12, 11, 564654.00, '2025-11-01', 1, '2025-11-01 11:21:16'),
(10, 13, 20, 100000.00, '2025-11-16', 1, '2025-11-16 15:08:59'),
(11, 20, 29, 1246500.00, '2025-11-24', 2, '2025-11-24 09:12:58'),
(12, 22, 23, 220500.00, '2025-11-24', 2, '2025-11-24 11:35:55');

-- --------------------------------------------------------

--
-- Table structure for table `petty_cash_accounts`
--

CREATE TABLE `petty_cash_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(11) UNSIGNED NOT NULL COMMENT 'Links to the `branches` table',
  `account_name` varchar(255) NOT NULL COMMENT 'e.g., Demra Mill Cash Box',
  `initial_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `current_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `positions`
--

CREATE TABLE `positions` (
  `id` int(11) UNSIGNED NOT NULL,
  `department_id` int(11) UNSIGNED NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `positions`
--

INSERT INTO `positions` (`id`, `department_id`, `title`, `description`, `created_at`, `updated_at`) VALUES
(14, 1, 'Superadmin', NULL, '2025-10-23 13:53:10', '2025-10-23 13:53:10'),
(15, 1, 'Admin', NULL, '2025-10-23 13:53:10', '2025-10-23 13:53:10'),
(16, 2, 'Accountant', NULL, '2025-10-23 13:53:10', '2025-10-23 13:53:10'),
(17, 2, 'Accounts Manager', NULL, '2025-10-23 13:53:10', '2025-10-23 13:53:10'),
(18, 2, 'POS Accountant', NULL, '2025-10-23 13:53:10', '2025-10-23 13:53:10'),
(19, 2, 'Collector', NULL, '2025-10-23 13:53:10', '2025-10-23 13:53:10'),
(20, 3, 'Production Manager', NULL, '2025-10-23 13:53:10', '2025-10-23 13:53:10'),
(21, 4, 'Dispatch Manager', NULL, '2025-10-23 13:53:10', '2025-10-23 13:53:10'),
(22, 4, 'POS Dispatch Staff', NULL, '2025-10-23 13:53:10', '2025-10-23 13:53:10'),
(23, 5, 'Sales Representative', NULL, '2025-10-23 13:53:10', '2025-10-23 13:53:10'),
(24, 6, 'Office Staff- HO', NULL, '2025-10-23 13:53:10', '2025-10-23 13:53:10'),
(25, 7, 'Office Staff- Demra', NULL, '2025-10-23 13:53:10', '2025-10-23 13:53:10'),
(26, 8, 'Office Staff- Sirajgonj', NULL, '2025-10-23 13:53:10', '2025-10-23 13:53:10'),
(27, 4, 'Transport', NULL, '2025-11-04 17:42:28', '2025-11-04 17:42:28');

-- --------------------------------------------------------

--
-- Table structure for table `production_schedule`
--

CREATE TABLE `production_schedule` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `scheduled_date` date NOT NULL,
  `production_started_at` datetime DEFAULT NULL,
  `production_completed_at` datetime DEFAULT NULL,
  `production_manager_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('pending','in_progress','completed','delayed') NOT NULL DEFAULT 'pending',
  `priority_order` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(36) NOT NULL DEFAULT uuid(),
  `base_name` varchar(255) NOT NULL,
  `base_sku` varchar(100) DEFAULT NULL COMMENT 'A general SKU for the product line, e.g., UPP',
  `description` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `uuid`, `base_name`, `base_sku`, `description`, `category`, `status`, `created_at`, `updated_at`) VALUES
(1, '2565d047-af3e-11f0-9003-10ffe0a28e39', 'Jora Kabutor', 'JORAKOBUTOR', 'Special', 'Moyda', 'active', '2025-10-22 11:56:29', '2025-10-22 12:02:41'),
(2, 'b815ecf3-b6ef-11f0-9003-10ffe0a28e39', '1 Hati', '1HATI', 'Moyda', 'Moyda', 'active', '2025-11-01 06:55:15', '2025-11-01 06:55:15'),
(3, 'dfda2c01-c861-11f0-93c1-10ffe0a28e39', 'Aam Moida 50kg', '7', '', 'Moyda', 'active', '2025-11-23 11:45:14', '2025-11-23 11:45:14'),
(4, 'e91f64dd-c8f4-11f0-93c1-10ffe0a28e39', 'Ruti Moida (50kg)', 'Ruti Moida (50kg)', '', 'Moida', 'active', '2025-11-24 05:17:45', '2025-11-24 05:17:45');

-- --------------------------------------------------------

--
-- Table structure for table `product_prices`
--

CREATE TABLE `product_prices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `variant_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(11) UNSIGNED NOT NULL,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `effective_date` date NOT NULL DEFAULT curdate(),
  `status` enum('active','promotional','inactive') NOT NULL DEFAULT 'active',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_prices`
--

INSERT INTO `product_prices` (`id`, `variant_id`, `branch_id`, `unit_price`, `effective_date`, `status`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 2, 1, 3400.00, '2025-10-22', 'active', 0, '2025-10-22 12:27:46', '2025-10-22 12:42:36'),
(2, 2, 1, 3410.00, '2025-10-22', 'active', 1, '2025-10-22 12:42:36', '2025-10-22 12:42:36'),
(3, 3, 1, 2200.00, '2025-11-01', 'active', 1, '2025-11-01 06:56:58', '2025-11-01 06:56:58'),
(4, 3, 2, 2230.00, '2025-11-01', 'active', 1, '2025-11-01 06:57:19', '2025-11-01 06:57:19'),
(5, 3, 4, 1750.00, '2025-11-24', 'active', 1, '2025-11-23 11:46:33', '2025-11-23 11:46:33'),
(6, 4, 1, 1750.00, '2025-11-24', 'active', 0, '2025-11-23 11:49:09', '2025-11-23 11:50:01'),
(7, 4, 2, 1800.00, '2025-11-24', 'active', 1, '2025-11-23 11:49:23', '2025-11-23 11:49:23'),
(8, 4, 1, 1780.00, '2025-11-30', 'active', 0, '2025-11-23 11:50:01', '2025-11-23 11:50:52'),
(9, 4, 1, 1790.00, '2025-11-23', 'active', 0, '2025-11-23 11:50:52', '2025-11-23 11:57:22'),
(10, 4, 1, 1750.00, '2025-11-23', 'active', 1, '2025-11-23 11:57:23', '2025-11-23 11:57:23'),
(11, 5, 1, 2160.00, '2025-11-24', 'active', 1, '2025-11-24 06:48:36', '2025-11-24 06:48:36'),
(12, 5, 2, 2140.00, '2025-11-24', 'active', 1, '2025-11-24 06:48:52', '2025-11-24 06:48:52');

-- --------------------------------------------------------

--
-- Table structure for table `product_variants`
--

CREATE TABLE `product_variants` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(36) NOT NULL DEFAULT uuid(),
  `product_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Links to the base product in `products` table',
  `grade` varchar(50) DEFAULT NULL COMMENT 'e.g., A Grade, B Grade',
  `weight_variant` varchar(50) DEFAULT NULL COMMENT 'e.g., 1 Litre, 5 Litre, 1 Piece',
  `unit_of_measure` enum('Piece','Litre','kg','Meter') NOT NULL DEFAULT 'Piece',
  `sku` varchar(100) NOT NULL COMMENT 'The unique SKU for THIS variant, e.g., UPP-A-1L',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `weight_kg` decimal(8,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_variants`
--

INSERT INTO `product_variants` (`id`, `uuid`, `product_id`, `grade`, `weight_variant`, `unit_of_measure`, `sku`, `status`, `created_at`, `updated_at`, `weight_kg`) VALUES
(2, '36a77a07-af42-11f0-9003-10ffe0a28e39', 1, '1', '34', 'kg', 'JORAKOBUTOR-34-1', 'active', '2025-10-22 12:25:36', '2025-10-22 12:25:36', NULL),
(3, 'c8541418-b6ef-11f0-9003-10ffe0a28e39', 2, '1', '50', 'kg', '1HATI-50-1', 'active', '2025-11-01 06:55:42', '2025-11-01 06:55:42', NULL),
(4, '5c929895-c862-11f0-93c1-10ffe0a28e39', 3, 'A', '50', 'kg', '7-50-A', 'active', '2025-11-23 11:48:43', '2025-11-23 11:48:43', NULL),
(5, '88a03136-c901-11f0-93c1-10ffe0a28e39', 4, 'B', '50', 'kg', 'RUTI MOIDA (50KG)-50-B', 'active', '2025-11-24 06:48:07', '2025-11-24 06:48:07', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_invoices`
--

CREATE TABLE `purchase_invoices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(36) NOT NULL DEFAULT uuid(),
  `invoice_number` varchar(50) NOT NULL COMMENT 'Internal invoice number',
  `supplier_invoice_number` varchar(100) DEFAULT NULL COMMENT 'Supplier''s invoice number',
  `purchase_order_id` bigint(20) UNSIGNED DEFAULT NULL,
  `grn_id` bigint(20) UNSIGNED DEFAULT NULL,
  `supplier_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `invoice_type` enum('goods','services','both') DEFAULT 'goods',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `shipping_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_charges` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_due` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('unpaid','partially_paid','paid','overdue') NOT NULL DEFAULT 'unpaid',
  `status` enum('draft','posted','paid','cancelled','void') NOT NULL DEFAULT 'draft',
  `journal_entry_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Links to journal_entries',
  `notes` text DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_invoice_items`
--

CREATE TABLE `purchase_invoice_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_invoice_id` bigint(20) UNSIGNED NOT NULL,
  `po_item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `grn_item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `variant_id` bigint(20) UNSIGNED DEFAULT NULL,
  `item_type` enum('raw_material','finished_goods','packaging','supplies','other') NOT NULL DEFAULT 'raw_material',
  `item_name` varchar(255) NOT NULL,
  `item_code` varchar(100) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_of_measure` varchar(50) DEFAULT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `discount_percentage` decimal(5,2) DEFAULT 0.00,
  `discount_amount` decimal(12,2) DEFAULT 0.00,
  `tax_percentage` decimal(5,2) DEFAULT 0.00,
  `tax_amount` decimal(12,2) DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL,
  `account_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Expense account for this item',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(36) NOT NULL DEFAULT uuid(),
  `po_number` varchar(50) NOT NULL COMMENT 'e.g., PO-20251120-0001',
  `supplier_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL COMMENT 'Purchasing branch',
  `po_date` date NOT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `status` enum('draft','pending_approval','approved','ordered','partially_received','received','cancelled','closed') NOT NULL DEFAULT 'draft',
  `payment_terms` varchar(100) DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `shipping_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_charges` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('unpaid','partially_paid','paid') NOT NULL DEFAULT 'unpaid',
  `notes` text DEFAULT NULL,
  `terms_conditions` text DEFAULT NULL,
  `approved_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `wheat_origin` varchar(50) DEFAULT NULL COMMENT 'Sources like কানাডা or রাশিয়া',
  `quantity_kg` decimal(15,2) DEFAULT 0.00 COMMENT 'Ordered quantity',
  `unit_price_per_kg` decimal(15,4) DEFAULT 0.0000 COMMENT 'Price per KG',
  `total_order_value` decimal(15,2) DEFAULT 0.00 COMMENT 'Calculated: qty * price',
  `total_received_qty` decimal(15,2) DEFAULT 0.00 COMMENT 'Auto-calculated from GRN',
  `total_received_value` decimal(15,2) DEFAULT 0.00 COMMENT 'Auto-calculated value of received goods',
  `total_paid` decimal(15,2) DEFAULT 0.00 COMMENT 'Auto-calculated from payments',
  `balance_payable` decimal(15,2) DEFAULT 0.00 COMMENT 'Auto-calculated balance',
  `po_status` enum('draft','approved','partial','completed','cancelled') DEFAULT 'draft',
  `delivery_status` enum('pending','partial','completed') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders_adnan`
--

CREATE TABLE `purchase_orders_adnan` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(36) NOT NULL DEFAULT uuid(),
  `po_number` varchar(50) NOT NULL COMMENT 'e.g., 442, 443, 444',
  `po_date` date NOT NULL,
  `supplier_id` bigint(20) UNSIGNED NOT NULL,
  `supplier_name` varchar(255) NOT NULL COMMENT 'Cached supplier name in Bengali',
  `branch_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Ordering branch',
  `wheat_origin` varchar(50) NOT NULL COMMENT 'Wheat origin: কানাডা, রাশিয়া, Australia, Ukraine, India, Local, Brazil, Other',
  `quantity_kg` decimal(15,2) NOT NULL COMMENT 'Ordered quantity in KG',
  `unit_price_per_kg` decimal(15,4) NOT NULL COMMENT 'Price per KG',
  `total_order_value` decimal(15,2) NOT NULL COMMENT 'Calculated: quantity × unit_price',
  `expected_delivery_date` date DEFAULT NULL,
  `total_received_qty` decimal(15,2) DEFAULT 0.00 COMMENT 'Auto-calculated from GRNs',
  `qty_yet_to_receive` decimal(15,2) GENERATED ALWAYS AS (`quantity_kg` - `total_received_qty`) STORED,
  `total_received_value` decimal(15,2) DEFAULT 0.00 COMMENT 'total_received_qty × unit_price_per_kg',
  `total_paid` decimal(15,2) DEFAULT 0.00 COMMENT 'Auto-calculated from payments',
  `balance_payable` decimal(15,2) GENERATED ALWAYS AS (`total_received_value` - `total_paid`) STORED,
  `po_status` enum('draft','approved','partial','completed','cancelled') DEFAULT 'approved',
  `delivery_status` enum('pending','partial','completed','over_received') DEFAULT 'pending',
  `payment_status` enum('unpaid','partial','paid','overpaid') DEFAULT 'unpaid',
  `remarks` text DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Purchase Orders - Adnan Module for Wheat Procurement';

--
-- Dumping data for table `purchase_orders_adnan`
--

INSERT INTO `purchase_orders_adnan` (`id`, `uuid`, `po_number`, `po_date`, `supplier_id`, `supplier_name`, `branch_id`, `wheat_origin`, `quantity_kg`, `unit_price_per_kg`, `total_order_value`, `expected_delivery_date`, `total_received_qty`, `total_received_value`, `total_paid`, `po_status`, `delivery_status`, `payment_status`, `remarks`, `created_by_user_id`, `created_at`, `updated_at`) VALUES
(1, '42d79600-c8ad-11f0-93c1-10ffe0a28e39', '442', '2025-11-24', 9, 'আরোফা ট্রেডিং', NULL, 'Ukraine', 250000.00, 30.0000, 7500000.00, '2025-11-25', 220000.00, 6600000.00, 100000.00, 'approved', 'partial', 'partial', 'Nothing to add into trial mode!!', 1, '2025-11-23 20:44:52', '2025-11-24 04:27:42'),
(2, '26e8b9a0-c8f5-11f0-93c1-10ffe0a28e39', '443', '2025-11-25', 11, 'এলিট এন্টারপ্রাইস', NULL, 'রাশিয়া', 100000.00, 20.0000, 2000000.00, '2025-11-26', 0.00, 0.00, 0.00, 'approved', 'pending', 'unpaid', 'hudai', 2, '2025-11-24 05:19:29', '2025-11-24 05:19:29'),
(3, 'af751880-c8f6-11f0-93c1-10ffe0a28e39', '444', '2025-11-24', 10, 'আলম ট্রেডিং', NULL, 'কানাডা', 100000.00, 38.8900, 3889000.00, '2025-11-25', 50000.00, 1944500.00, 70000.00, 'approved', 'partial', 'unpaid', 'Hudai', 2, '2025-11-24 05:30:27', '2025-11-24 05:46:02'),
(4, 'c0c3c342-c8f6-11f0-93c1-10ffe0a28e39', '445', '2025-11-24', 10, 'আলম ট্রেডিং', NULL, 'কানাডা', 100000.00, 38.8900, 3889000.00, '2025-11-25', 0.00, 0.00, 0.00, 'approved', 'pending', 'unpaid', 'Hudai', 2, '2025-11-24 05:30:57', '2025-11-24 05:30:57');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_items`
--

CREATE TABLE `purchase_order_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_order_id` bigint(20) UNSIGNED NOT NULL,
  `variant_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Links to product_variants for finished goods',
  `item_type` enum('raw_material','finished_goods','packaging','supplies','other') NOT NULL DEFAULT 'raw_material',
  `item_name` varchar(255) NOT NULL COMMENT 'Item description/name',
  `item_code` varchar(100) DEFAULT NULL,
  `unit_of_measure` varchar(50) DEFAULT NULL COMMENT 'kg, bag, ton, piece, etc.',
  `quantity` decimal(12,3) NOT NULL,
  `received_quantity` decimal(12,3) NOT NULL DEFAULT 0.000,
  `unit_price` decimal(12,2) NOT NULL,
  `discount_percentage` decimal(5,2) DEFAULT 0.00,
  `discount_amount` decimal(12,2) DEFAULT 0.00,
  `tax_percentage` decimal(5,2) DEFAULT 0.00,
  `tax_amount` decimal(12,2) DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_payments_adnan`
--

CREATE TABLE `purchase_payments_adnan` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(36) NOT NULL DEFAULT uuid(),
  `payment_voucher_number` varchar(50) NOT NULL COMMENT 'Auto-generated voucher number',
  `payment_date` date NOT NULL,
  `purchase_order_id` bigint(20) UNSIGNED NOT NULL,
  `po_number` varchar(50) NOT NULL COMMENT 'Cached for reference',
  `supplier_id` bigint(20) UNSIGNED NOT NULL,
  `supplier_name` varchar(255) NOT NULL COMMENT 'Cached supplier name',
  `amount_paid` decimal(15,2) NOT NULL,
  `payment_method` enum('bank','cash','cheque') NOT NULL,
  `bank_account_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK to bank_accounts if bank payment',
  `bank_name` varchar(100) DEFAULT NULL COMMENT 'Cached bank name',
  `handled_by_employee` varchar(255) DEFAULT NULL COMMENT 'Employee who handled cash payment',
  `reference_number` varchar(100) DEFAULT NULL COMMENT 'Cheque/transaction reference',
  `payment_type` enum('advance','regular','final') DEFAULT 'regular',
  `remarks` text DEFAULT NULL,
  `journal_entry_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK to journal_entries',
  `is_posted` tinyint(1) DEFAULT 0 COMMENT 'Has journal entry been created',
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `approved_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Purchase Payments - Adnan Module';

--
-- Dumping data for table `purchase_payments_adnan`
--

INSERT INTO `purchase_payments_adnan` (`id`, `uuid`, `payment_voucher_number`, `payment_date`, `purchase_order_id`, `po_number`, `supplier_id`, `supplier_name`, `amount_paid`, `payment_method`, `bank_account_id`, `bank_name`, `handled_by_employee`, `reference_number`, `payment_type`, `remarks`, `journal_entry_id`, `is_posted`, `created_by_user_id`, `approved_by_user_id`, `approved_at`, `created_at`, `updated_at`) VALUES
(1, 'ca42b995-c8e8-11f0-93c1-10ffe0a28e39', 'PV-20251124-0001', '2025-11-24', 1, '442', 9, 'আরোফা ট্রেডিং', 5000000.00, 'bank', 5, 'MERCANTILE 445', '', '235445', 'regular', 'Partially paid from Marcentile bank', NULL, 0, 1, NULL, NULL, '2025-11-24 03:50:59', '2025-11-24 03:50:59'),
(2, 'eac7b75e-c8ed-11f0-93c1-10ffe0a28e39', 'PV-20251124-0002', '2025-11-24', 1, '442', 9, 'আরোফা ট্রেডিং', 100000.00, 'bank', 1, 'ujjal flour mills', '', '124354', 'regular', 'First trial of payment', 63, 1, 1, NULL, NULL, '2025-11-24 04:27:42', '2025-11-24 04:27:42'),
(3, '9eaa5ce0-c8f7-11f0-93c1-10ffe0a28e39', 'PV-20251124-0003', '2025-11-24', 3, '444', 10, 'আলম ট্রেডিং', 50000.00, 'bank', 1, 'ujjal flour mills', '', 'ddfhgf', 'regular', 'wheat payment', 66, 1, 2, NULL, NULL, '2025-11-24 05:37:09', '2025-11-24 05:37:09'),
(4, 'dc2b9f8b-c8f8-11f0-93c1-10ffe0a28e39', 'PV-20251124-0004', '2025-11-24', 3, '444', 10, 'আলম ট্রেডিং', 20000.00, 'bank', 1, 'ujjal flour mills', '', '', 'final', '', 67, 1, 2, NULL, NULL, '2025-11-24 05:46:01', '2025-11-24 05:46:02');

--
-- Triggers `purchase_payments_adnan`
--
DELIMITER $$
CREATE TRIGGER `after_payment_adnan_insert` AFTER INSERT ON `purchase_payments_adnan` FOR EACH ROW BEGIN
    -- Only update the PO's total_paid if the newly inserted payment is posted (is_posted = 1)
    IF NEW.is_posted = 1 THEN
        UPDATE purchase_orders_adnan
        SET total_paid = (
            -- Recalculate total_paid by summing all POSTED payments for this purchase order
            SELECT COALESCE(SUM(amount_paid), 0)
            FROM purchase_payments_adnan
            WHERE purchase_order_id = NEW.purchase_order_id
            AND is_posted = 1 -- KEY CHANGE: Only sum payments where is_posted is 1
        )
        WHERE id = NEW.purchase_order_id;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_payment_adnan_update` AFTER UPDATE ON `purchase_payments_adnan` FOR EACH ROW BEGIN
    -- Update the PO if either the 'is_posted' status changes OR the 'amount_paid' changes
    IF OLD.is_posted != NEW.is_posted OR OLD.amount_paid != NEW.amount_paid THEN
        -- The update applies to the PO linked to the current payment record (using NEW.purchase_order_id)
        UPDATE purchase_orders_adnan
        SET total_paid = (
            -- Recalculate total_paid by summing all POSTED payments for this purchase order
            SELECT COALESCE(SUM(amount_paid), 0)
            FROM purchase_payments_adnan
            WHERE purchase_order_id = NEW.purchase_order_id
            AND is_posted = 1 -- KEY CHANGE: Only sum payments where is_posted is 1
        )
        WHERE id = NEW.purchase_order_id;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_returns`
--

CREATE TABLE `purchase_returns` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(36) NOT NULL DEFAULT uuid(),
  `return_number` varchar(50) NOT NULL COMMENT 'e.g., PRET-20251120-0001',
  `purchase_invoice_id` bigint(20) UNSIGNED NOT NULL,
  `grn_id` bigint(20) UNSIGNED DEFAULT NULL,
  `supplier_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `return_date` date NOT NULL,
  `return_reason` enum('damaged','defective','wrong_item','excess','expired','other') NOT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refund_status` enum('pending','refunded','credit_note_issued','replacement') NOT NULL DEFAULT 'pending',
  `status` enum('draft','approved','processed','completed','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `journal_entry_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_return_items`
--

CREATE TABLE `purchase_return_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_return_id` bigint(20) UNSIGNED NOT NULL,
  `purchase_invoice_item_id` bigint(20) UNSIGNED NOT NULL,
  `variant_id` bigint(20) UNSIGNED DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_type` enum('raw_material','finished_goods','packaging','supplies','other') NOT NULL DEFAULT 'raw_material',
  `return_quantity` decimal(12,3) NOT NULL,
  `unit_of_measure` varchar(50) DEFAULT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(36) NOT NULL DEFAULT uuid(),
  `supplier_code` varchar(50) DEFAULT NULL,
  `company_name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `tax_id` varchar(100) DEFAULT NULL COMMENT 'VAT/TIN Number',
  `payment_terms` varchar(100) DEFAULT NULL COMMENT 'e.g., Net 30, Net 60, COD',
  `credit_limit` decimal(12,2) DEFAULT 0.00,
  `opening_balance` decimal(12,2) DEFAULT 0.00,
  `current_balance` decimal(12,2) DEFAULT 0.00 COMMENT 'Amount we owe to supplier',
  `supplier_type` enum('local','international','both') DEFAULT 'local',
  `status` enum('active','inactive','blocked') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `uuid`, `supplier_code`, `company_name`, `contact_person`, `email`, `phone`, `mobile`, `address`, `city`, `country`, `tax_id`, `payment_terms`, `credit_limit`, `opening_balance`, `current_balance`, `supplier_type`, `status`, `notes`, `created_by_user_id`, `created_at`, `updated_at`) VALUES
(1, 'da37811e-c62b-11f0-93c1-10ffe0a28e39', 'SUP-001', 'Dhaka Wheat Traders Ltd.', 'Mr. Rahman', 'rahman@dhakawheat.com', '01712345678', NULL, 'Dhaka', 'Dhaka', 'Bangladesh', NULL, 'Net 30', 0.00, 0.00, 0.00, 'local', 'active', NULL, 1, '2025-11-20 16:13:29', '2025-11-20 16:13:29'),
(2, 'da37a398-c62b-11f0-93c1-10ffe0a28e39', 'SUP-002', 'Bangladesh Packaging Industries', 'Mrs. Sultana', 'sultana@bdpack.com', '01898765432', NULL, 'Narayanganj', 'Narayanganj', 'Bangladesh', NULL, 'Net 15', 0.00, 0.00, 0.00, 'local', 'active', NULL, 1, '2025-11-20 16:13:29', '2025-11-20 16:13:29'),
(3, 'da37a441-c62b-11f0-93c1-10ffe0a28e39', 'SUP-003', 'Global Grain Imports', 'Mr. Khan', 'khan@globalgrain.com', '01556789012', NULL, 'Chittagong', 'Chittagong', 'Bangladesh', NULL, 'COD', 0.00, 0.00, 0.00, 'international', 'active', NULL, 1, '2025-11-20 16:13:29', '2025-11-20 16:13:29'),
(4, 'ea82cbbc-c6a9-11f0-93c1-10ffe0a28e39', 'SUP-0004', 'Trial Supplier', 'Trial1', 'trail1@gmail.com', '01912071978', '01912071978', 'Narayangonj', 'Dhaka', 'Bangladesh', NULL, 'COD', 0.00, 0.00, 0.00, 'local', 'active', NULL, 1, '2025-11-21 07:15:53', '2025-11-21 07:15:53'),
(5, '0c23ec55-c6aa-11f0-93c1-10ffe0a28e39', 'SUP-0005', 'Trial Supplier', 'Trial1', 'trail1@gmail.com', '01912071978', '01912071978', 'Narayangonj', 'Dhaka', 'Bangladesh', NULL, 'COD', 0.00, 0.00, 0.00, 'local', 'active', NULL, 1, '2025-11-21 07:16:49', '2025-11-21 07:16:49'),
(6, '8fa62c13-c6aa-11f0-93c1-10ffe0a28e39', 'SUP-0006', 'Trial Supplier', 'Trial1', 'trail1@gmail.com', '01912071978', '01912071978', 'Narayangonj', 'Dhaka', 'Bangladesh', NULL, 'COD', 0.00, 0.00, 0.00, 'local', 'active', NULL, 1, '2025-11-21 07:20:30', '2025-11-21 07:20:30'),
(7, '22b9d0ff-c6ac-11f0-93c1-10ffe0a28e39', 'SUP-0007', 'Trial Supplier', 'Trial1', 'trail1@gmail.com', '01912071978', '01912071978', 'Narayangonj', 'Dhaka', 'Bangladesh', NULL, 'COD', 0.00, 0.00, 0.00, 'local', 'active', NULL, 1, '2025-11-21 07:31:46', '2025-11-21 07:31:46'),
(8, '7b508eaf-c8ac-11f0-93c1-10ffe0a28e39', NULL, 'খাদ্দ্যগুদাম -Dhaka', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 'local', 'active', NULL, NULL, '2025-11-23 20:39:17', '2025-11-23 20:39:17'),
(9, '7b55a94c-c8ac-11f0-93c1-10ffe0a28e39', NULL, 'আরোফা ট্রেডিং', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 'local', 'active', NULL, NULL, '2025-11-23 20:39:17', '2025-11-23 20:39:17'),
(10, '7b5776bc-c8ac-11f0-93c1-10ffe0a28e39', NULL, 'আলম ট্রেডিং', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 'local', 'active', NULL, NULL, '2025-11-23 20:39:17', '2025-11-23 20:39:17'),
(11, '7b590706-c8ac-11f0-93c1-10ffe0a28e39', NULL, 'এলিট এন্টারপ্রাইস', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 'local', 'active', NULL, NULL, '2025-11-23 20:39:17', '2025-11-23 20:39:17'),
(12, '7b59ec7d-c8ac-11f0-93c1-10ffe0a28e39', NULL, 'খাদ্দ্যগুদাম', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 'local', 'active', NULL, NULL, '2025-11-23 20:39:17', '2025-11-23 20:39:17'),
(13, '7b5ad837-c8ac-11f0-93c1-10ffe0a28e39', NULL, 'মুনাল ট্রেডিং', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 'local', 'active', NULL, NULL, '2025-11-23 20:39:17', '2025-11-23 20:39:17'),
(14, '7b656c1b-c8ac-11f0-93c1-10ffe0a28e39', NULL, 'র‍্যাব ৩', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 'local', 'active', NULL, NULL, '2025-11-23 20:39:17', '2025-11-23 20:39:17'),
(15, '7b66b5e7-c8ac-11f0-93c1-10ffe0a28e39', NULL, 'শুকরিয়া ট্রেডারস', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 'local', 'active', NULL, NULL, '2025-11-23 20:39:17', '2025-11-23 20:39:17'),
(16, '7b680f65-c8ac-11f0-93c1-10ffe0a28e39', NULL, 'সেনা জাগরণ', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 'local', 'active', NULL, NULL, '2025-11-23 20:39:17', '2025-11-23 20:39:17'),
(17, '7b69d4b8-c8ac-11f0-93c1-10ffe0a28e39', NULL, 'সয়া ্রোটিন', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 'local', 'active', NULL, NULL, '2025-11-23 20:39:17', '2025-11-23 20:39:17');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_ledger`
--

CREATE TABLE `supplier_ledger` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `supplier_id` bigint(20) UNSIGNED NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_type` enum('opening_balance','purchase','payment','debit_note','credit_note','adjustment') NOT NULL,
  `reference_type` varchar(100) DEFAULT NULL COMMENT 'e.g., PurchaseInvoice, SupplierPayment',
  `reference_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL COMMENT 'Invoice number, payment number, etc.',
  `debit_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Payments reduce liability',
  `credit_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Purchases increase liability',
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Running balance',
  `description` varchar(255) DEFAULT NULL,
  `branch_id` int(10) UNSIGNED DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_payments`
--

CREATE TABLE `supplier_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(36) NOT NULL DEFAULT uuid(),
  `payment_number` varchar(50) NOT NULL COMMENT 'e.g., SPAY-20251120-0001',
  `supplier_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` enum('cash','bank_transfer','cheque','card','mobile_banking','other') NOT NULL,
  `payment_account_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Links to chart_of_accounts (Bank/Cash account)',
  `amount` decimal(12,2) NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL COMMENT 'Cheque number, transaction ID, etc.',
  `notes` text DEFAULT NULL,
  `status` enum('pending','cleared','bounced','cancelled') NOT NULL DEFAULT 'pending',
  `journal_entry_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Links to journal_entries',
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_payment_allocations`
--

CREATE TABLE `supplier_payment_allocations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `supplier_payment_id` bigint(20) UNSIGNED NOT NULL,
  `purchase_invoice_id` bigint(20) UNSIGNED NOT NULL,
  `allocated_amount` decimal(12,2) NOT NULL,
  `allocation_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transaction_lines`
--

CREATE TABLE `transaction_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `journal_entry_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Links to journal_entries',
  `account_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Links to chart_of_accounts',
  `debit_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `credit_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `description` varchar(255) DEFAULT NULL
) ;

--
-- Dumping data for table `transaction_lines`
--

INSERT INTO `transaction_lines` (`id`, `journal_entry_id`, `account_id`, `debit_amount`, `credit_amount`, `description`) VALUES
(1, 1, 12, 1800000.00, 0.00, 'Opening balance - Bank Account'),
(2, 1, 2, 0.00, 1800000.00, 'Opening balance - Equity offset'),
(3, 2, 13, 1239000.00, 0.00, 'Opening balance - Bank Account'),
(4, 2, 2, 0.00, 1239000.00, 'Opening balance - Equity offset'),
(5, 3, 13, 1000000.00, 0.00, 'EOD'),
(6, 3, 12, 0.00, 1000000.00, 'EOD'),
(7, 4, 13, 750000.00, 0.00, 'dfgfg'),
(8, 4, 18, 0.00, 750000.00, 'dfgfg'),
(9, 5, 16, 750000.00, 0.00, 'Salary SRG'),
(10, 5, 13, 0.00, 750000.00, 'Salary SRG'),
(18, 9, 26, 6790.00, 0.00, 'POS Cash - Order #ORD-20251027-0002'),
(19, 9, 25, 0.00, 6790.00, 'Sales Revenue - Order #ORD-20251027-0002'),
(20, 10, 26, 3360.00, 0.00, 'POS Cash - Order #ORD-20251027-0003'),
(21, 10, 25, 0.00, 3360.00, 'Sales Revenue - Order #ORD-20251027-0003'),
(22, 11, 12, 3000.00, 0.00, 'trial cash transfer'),
(23, 11, 14, 0.00, 3000.00, 'trial cash transfer'),
(24, 12, 12, 100000.00, 0.00, 'Received payment PAY-20251030-6800'),
(25, 12, 23, 0.00, 100000.00, 'Payment from customer Hazi Abul Kashem'),
(26, 13, 23, 34100.00, 0.00, 'Credit Sale Invoice #CR-20251028-9123 to Hazi Abul Kashem'),
(27, 13, 27, 0.00, 34100.00, 'Credit Sale Invoice #CR-20251028-9123 to Hazi Abul Kashem'),
(28, 14, 23, 2046000.00, 0.00, 'Credit Sale Invoice #CR-20251028-6227 to Hazi Abul Kashem'),
(29, 14, 27, 0.00, 2046000.00, 'Credit Sale Invoice #CR-20251028-6227 to Hazi Abul Kashem'),
(30, 15, 23, 641080.00, 0.00, 'Credit Sale Invoice #CR-20251030-2349 to Trial Credit '),
(31, 15, 27, 0.00, 641080.00, 'Credit Sale Invoice #CR-20251030-2349 to Trial Credit '),
(32, 16, 22, 12000.00, 0.00, 'Payment received - Receipt #RCP-20251031-7113'),
(33, 16, 23, 0.00, 12000.00, 'Payment from Trial Credit  - Receipt #RCP-20251031-7113'),
(34, 17, 12, 5000.00, 0.00, 'Payment received via Bank Transfer - Receipt #RCP-20251031-6778'),
(35, 17, 23, 0.00, 5000.00, 'Payment from Trial Credit  - Receipt #RCP-20251031-6778'),
(36, 18, 22, 4400.00, 0.00, 'Payment received - Receipt #RCP-20251031-2830'),
(37, 18, 23, 0.00, 4400.00, 'Payment from Trial Credit  - Receipt #RCP-20251031-2830'),
(38, 19, 22, 1000.00, 0.00, 'Payment received - Receipt #RCP-20251031-9036'),
(39, 19, 23, 0.00, 1000.00, 'Payment from Trial Credit  - Receipt #RCP-20251031-9036'),
(40, 20, 22, 1001.00, 0.00, 'Payment received - Receipt #RCP-20251031-3437'),
(41, 20, 23, 0.00, 1001.00, 'Payment from Trial Credit  - Receipt #RCP-20251031-3437'),
(42, 21, 12, 1000000.00, 0.00, 'Payment received via Bank Transfer - Receipt #RCP-20251031-7315'),
(43, 21, 23, 0.00, 1000000.00, 'Payment from Hazi Abul Kashem - Receipt #RCP-20251031-7315'),
(44, 22, 17, 2000.00, 0.00, 'Payment to pos srg Mr. - Hudai'),
(45, 22, 21, 0.00, 2000.00, 'Payment via debit voucher DV-20251031-6982'),
(46, 23, 26, 11100.00, 0.00, 'POS Cash - Order #ORD-20251101-0001'),
(47, 23, 25, 0.00, 11100.00, 'Sales Revenue - Order #ORD-20251101-0001'),
(48, 24, 13, 11400.00, 0.00, 'dedygf'),
(49, 24, 14, 0.00, 11400.00, 'dedygf'),
(50, 25, 17, 5000.00, 0.00, 'ufg'),
(51, 25, 22, 0.00, 5000.00, 'ufg'),
(52, 26, 31, 5000.00, 0.00, 'Payment to Adnan Illius - Boga Lake'),
(53, 26, 12, 0.00, 5000.00, 'Payment via debit voucher DV-20251101-1214'),
(54, 27, 13, 5000.00, 0.00, 'Payment received via Bank Transfer - Receipt #RCP-20251101-6618'),
(55, 27, 23, 0.00, 5000.00, 'Payment from Trial Credit  - Receipt #RCP-20251101-6618'),
(56, 28, 23, 34100.00, 0.00, 'Credit Sale Invoice #CR-20251101-1900 to Test 2'),
(57, 28, 27, 0.00, 34100.00, 'Credit Sale Invoice #CR-20251101-1900 to Test 2'),
(58, 29, 22, 2345566.00, 0.00, 'Payment received - Receipt #RCP-20251101-4027'),
(59, 29, 23, 0.00, 2345566.00, 'Payment from asdsdjsfk - Receipt #RCP-20251101-4027'),
(60, 30, 12, 564654.00, 0.00, 'Payment received via Bank Transfer - Receipt #RCP-20251101-5196'),
(61, 30, 23, 0.00, 564654.00, 'Payment from asdsdjsfk - Receipt #RCP-20251101-5196'),
(62, 31, 23, 685410.00, 0.00, 'Credit Sale Invoice #CR-20251101-9073 to Hazi Abul Kashem'),
(63, 31, 27, 0.00, 685410.00, 'Credit Sale Invoice #CR-20251101-9073 to Hazi Abul Kashem'),
(64, 32, 26, 22260.00, 0.00, 'POS Cash - Order #ORD-20251104-0001'),
(65, 32, 25, 0.00, 22260.00, 'Sales Revenue - Order #ORD-20251104-0001'),
(66, 33, 26, 24790.00, 0.00, 'POS Cash - Order #ORD-20251104-0002'),
(67, 33, 25, 0.00, 24790.00, 'Sales Revenue - Order #ORD-20251104-0002'),
(68, 34, 23, 107.50, 0.00, 'Credit Sale Invoice #CR-20251101-3242 to Babul Mia'),
(69, 34, 27, 0.00, 107.50, 'Credit Sale Invoice #CR-20251101-3242 to Babul Mia'),
(70, 35, 23, 3069.00, 0.00, 'Credit Sale Invoice #CR-20251101-7172 to Hazi Abul Kashem'),
(71, 35, 18, 0.00, 3069.00, 'Credit Sale Invoice #CR-20251101-7172 to Hazi Abul Kashem'),
(72, 36, 23, 3069.00, 0.00, 'Credit Sale Invoice #CR-20251101-7172 to Hazi Abul Kashem'),
(73, 36, 18, 0.00, 3069.00, 'Credit Sale Invoice #CR-20251101-7172 to Hazi Abul Kashem'),
(74, 37, 23, 107.50, 0.00, 'Credit Sale Invoice #CR-20251101-3242 to Babul Mia'),
(75, 37, 27, 0.00, 107.50, 'Credit Sale Invoice #CR-20251101-3242 to Babul Mia'),
(76, 38, 23, 291000.00, 0.00, 'Credit Sale Invoice #CR-20251104-7928 to Hazi Abul Kashem'),
(77, 38, 27, 0.00, 291000.00, 'Credit Sale Invoice #CR-20251104-7928 to Hazi Abul Kashem'),
(78, 39, 23, 291000.00, 0.00, 'Credit Sale Invoice #CR-20251104-7928 to Hazi Abul Kashem'),
(79, 39, 27, 0.00, 291000.00, 'Credit Sale Invoice #CR-20251104-7928 to Hazi Abul Kashem'),
(80, 40, 23, 291000.00, 0.00, 'Credit Sale Invoice #CR-20251104-7928 to Hazi Abul Kashem'),
(81, 40, 27, 0.00, 291000.00, 'Credit Sale Invoice #CR-20251104-7928 to Hazi Abul Kashem'),
(82, 41, 23, 340000.00, 0.00, 'Credit Sale Invoice #CR-20251109-5587 to Hazi Abul Kashem'),
(83, 41, 27, 0.00, 340000.00, 'Credit Sale Invoice #CR-20251109-5587 to Hazi Abul Kashem'),
(84, 42, 23, 221500.00, 0.00, 'Credit Sale Invoice #CR-20251104-7505 to Hazi Abul Kashem'),
(85, 42, 18, 0.00, 221500.00, 'Credit Sale Invoice #CR-20251104-7505 to Hazi Abul Kashem'),
(86, 43, 23, 682000.00, 0.00, 'Credit Sale Invoice #CR-20251113-5446 to nibirrrrr'),
(87, 43, 27, 0.00, 682000.00, 'Credit Sale Invoice #CR-20251113-5446 to nibirrrrr'),
(88, 44, 23, 682000.00, 0.00, 'Credit Sale Invoice #CR-20251113-7046 to Test Nibir w'),
(89, 44, 27, 0.00, 682000.00, 'Credit Sale Invoice #CR-20251113-7046 to Test Nibir w'),
(90, 46, 33, 5500.00, 0.00, 'Fuel expense - Ctg Metro-NA-17-1199'),
(91, 46, 22, 0.00, 5500.00, 'Payment via Petty Cash Demra Mill'),
(92, 47, 33, 5500.00, 0.00, 'Fuel expense - Ctg Metro-NA-17-1199'),
(93, 47, 22, 0.00, 5500.00, 'Payment via Petty Cash Demra Mill'),
(94, 48, 33, 5500.00, 0.00, 'Fuel expense - Ctg Metro-NA-17-1199'),
(95, 48, 22, 0.00, 5500.00, 'Payment via Petty Cash Demra Mill'),
(96, 49, 33, 8800.00, 0.00, 'Fuel expense - Ctg Metro-TA-13-5678'),
(97, 49, 22, 0.00, 8800.00, 'Payment via Petty Cash Demra Mill'),
(98, 53, 30, 11050.00, 0.00, 'Undeposited Funds - Order #ORD-20251116-0001'),
(99, 53, 25, 0.00, 11050.00, 'Sales Revenue - Order #ORD-20251116-0001'),
(100, 54, 28, 100000.00, 0.00, 'Payment received - Receipt #RCP-20251116-5147'),
(101, 54, 23, 0.00, 100000.00, 'Payment from Test for inital credit - Receipt #RCP-20251116-5147'),
(102, 55, 33, 500000.00, 0.00, 'Expense for Fuel Expense'),
(103, 55, 21, 0.00, 500000.00, 'Payment from Petty Cash HO'),
(104, 56, 31, 3000.00, 0.00, 'Payment to Sales Trial - For Trail basis'),
(105, 56, 21, 0.00, 3000.00, 'Payment via debit voucher DV-20251122-7606'),
(106, 57, 22, 800000.00, 0.00, 'Payment received - Receipt #RCP-20251123-7589'),
(107, 57, 23, 0.00, 800000.00, 'Payment from nibirrrrr - Receipt #RCP-20251123-7589'),
(108, 58, 15, 3300000.00, 0.00, 'Payment received - Receipt #RCP-20251123-7367'),
(109, 58, 23, 0.00, 3300000.00, 'Payment from Hazi Abul Kashem - Receipt #RCP-20251123-7367'),
(110, 59, 23, 160500.00, 0.00, 'Credit Sale Invoice #CR-20251117-8813 to Mokka Traders'),
(111, 59, 27, 0.00, 160500.00, 'Credit Sale Invoice #CR-20251117-8813 to Mokka Traders'),
(112, 60, 23, 22150.00, 0.00, 'Credit Sale Invoice #CR-20251117-7007 to Babul Mia'),
(113, 60, 27, 0.00, 22150.00, 'Credit Sale Invoice #CR-20251117-7007 to Babul Mia'),
(114, 61, 12, 50000.00, 0.00, 'Payment received via Bank Transfer - Receipt #RCP-20251123-1055'),
(115, 61, 23, 0.00, 50000.00, 'Payment from Mokka Traders - Receipt #RCP-20251123-1055'),
(116, 62, 46, 6600000.00, 0.00, 'Inventory - Ukraine'),
(117, 62, 49, 0.00, 6600000.00, 'GRN Pending - PO 442'),
(118, 63, 49, 100000.00, 0.00, 'Payment to আরোফা ট্রেডিং'),
(119, 63, 12, 0.00, 100000.00, 'Payment via ujjal flour mills'),
(120, 64, 45, 777800.00, 0.00, 'Inventory - কানাডা'),
(121, 64, 49, 0.00, 777800.00, 'GRN Pending - PO 444'),
(122, 65, 45, 1166700.00, 0.00, 'Inventory - কানাডা'),
(123, 65, 49, 0.00, 1166700.00, 'GRN Pending - PO 444'),
(124, 66, 49, 50000.00, 0.00, 'Payment to আলম ট্রেডিং'),
(125, 66, 12, 0.00, 50000.00, 'Payment via ujjal flour mills'),
(126, 67, 49, 20000.00, 0.00, 'Payment to আলম ট্রেডিং'),
(127, 67, 12, 0.00, 20000.00, 'Payment via ujjal flour mills'),
(128, 68, 12, 22365.00, 0.00, 'Payment received via Bank Transfer - Receipt #RCP-20251124-0014'),
(129, 68, 23, 0.00, 22365.00, 'Payment from Babul Mia - Receipt #RCP-20251124-0014'),
(130, 69, 13, 60162.00, 0.00, 'Payment received via Bank Transfer - Receipt #RCP-20251124-1451'),
(131, 69, 23, 0.00, 60162.00, 'Payment from Hazi Abul Kashem - Receipt #RCP-20251124-1451'),
(132, 70, 12, 15457.00, 0.00, 'Payment received via Bank Transfer - Receipt #RCP-20251124-6571'),
(133, 70, 23, 0.00, 15457.00, 'Payment from nibirrrrr - Receipt #RCP-20251124-6571'),
(134, 71, 23, 17950.00, 0.00, 'Credit Sale Invoice #CR-20251123-2708 to Salam Store'),
(135, 71, 27, 0.00, 17950.00, 'Credit Sale Invoice #CR-20251123-2708 to Salam Store'),
(136, 72, 23, 1246500.00, 0.00, 'Credit Sale Invoice #CR-20251124-3350 to Ma Trading Corporation'),
(137, 72, 27, 0.00, 1246500.00, 'Credit Sale Invoice #CR-20251124-3350 to Ma Trading Corporation'),
(138, 73, 12, 1200000.00, 0.00, 'Payment received via Bank Transfer - Receipt #RCP-20251124-4594'),
(139, 73, 23, 0.00, 1200000.00, 'Payment from Ma Trading Corporation - Receipt #RCP-20251124-4594'),
(140, 74, 23, 205000.00, 0.00, 'Credit Sale Invoice #CR-20251124-7015 to Ma Trading Corporation'),
(141, 74, 27, 0.00, 205000.00, 'Credit Sale Invoice #CR-20251124-7015 to Ma Trading Corporation'),
(142, 75, 21, 250000.00, 0.00, 'Payment received - Receipt #RCP-20251124-5320'),
(143, 75, 23, 0.00, 250000.00, 'Payment from Ma Trading Corporation - Receipt #RCP-20251124-5320'),
(144, 76, 15, 110500.00, 0.00, 'Payment received - Receipt #RCP-20251124-9930'),
(145, 76, 23, 0.00, 110500.00, 'Payment from Mokka Traders - Receipt #RCP-20251124-9930'),
(146, 77, 12, 1000000.00, 0.00, 'Payment received via Cheque - Receipt #RCP-20251124-8144 - Cheque: 654614'),
(147, 77, 23, 0.00, 1000000.00, 'Payment from Adnan - Receipt #RCP-20251124-8144'),
(148, 78, 13, 17950.00, 0.00, 'Payment received via Bank Transfer - Receipt #RCP-20251124-5514'),
(149, 78, 23, 0.00, 17950.00, 'Payment from Salam Store - Receipt #RCP-20251124-5514'),
(150, 79, 28, 1500.00, 0.00, 'Payment received - Receipt #RCP-20251124-1291'),
(151, 79, 23, 0.00, 1500.00, 'Payment from Ma Trading Corporation - Receipt #RCP-20251124-1291'),
(152, 80, 30, 0.75, 0.00, 'Payment received via Mobile Banking - Receipt #RCP-20251124-9246'),
(153, 80, 23, 0.00, 0.75, 'Payment from Hazi Abul Kashem - Receipt #RCP-20251124-9246'),
(154, 81, 26, 5100.00, 0.00, 'POS Cash - Order #ORD-20251125-0001'),
(155, 81, 24, 100.00, 0.00, 'Discount given - Order #ORD-20251125-0001'),
(156, 81, 25, 0.00, 5200.00, 'Sales Revenue - Order #ORD-20251125-0001');

-- --------------------------------------------------------

--
-- Table structure for table `transport_expenses`
--

CREATE TABLE `transport_expenses` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) DEFAULT NULL,
  `trip_id` int(11) DEFAULT NULL,
  `expense_date` date NOT NULL,
  `expense_type` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `receipt_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transport_expenses`
--

INSERT INTO `transport_expenses` (`id`, `vehicle_id`, `trip_id`, `expense_date`, `expense_type`, `amount`, `description`, `receipt_number`, `notes`, `created_by_user_id`, `created_at`) VALUES
(1, 10, NULL, '2024-10-01', 'Document Renewal', 25000.00, 'Renewal cost for Tax Token - Ctg Metro-NA-17-1199', 'DM-NA-17-1199', NULL, 1, '2025-11-14 13:38:13');

-- --------------------------------------------------------

--
-- Table structure for table `trip_assignments`
--

CREATE TABLE `trip_assignments` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `trip_date` date NOT NULL,
  `scheduled_time` time DEFAULT NULL,
  `actual_start_time` datetime DEFAULT NULL,
  `actual_end_time` datetime DEFAULT NULL,
  `trip_type` enum('single','consolidated') DEFAULT 'single',
  `total_orders` int(11) DEFAULT 1,
  `total_weight_kg` decimal(10,2) DEFAULT 0.00,
  `remaining_capacity_kg` decimal(10,2) DEFAULT 0.00,
  `route_summary` text DEFAULT NULL,
  `status` enum('Scheduled','In Progress','Completed','Cancelled') DEFAULT 'Scheduled',
  `notes` text DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `trip_assignments`
--

INSERT INTO `trip_assignments` (`id`, `vehicle_id`, `driver_id`, `trip_date`, `scheduled_time`, `actual_start_time`, `actual_end_time`, `trip_type`, `total_orders`, `total_weight_kg`, `remaining_capacity_kg`, `route_summary`, `status`, `notes`, `created_by_user_id`, `created_at`, `updated_at`) VALUES
(1, 1, 1, '2025-11-09', '23:09:00', '2025-11-09 23:10:18', NULL, 'single', 1, 3400.00, 1600.00, 'fuyhh', 'In Progress', NULL, 5, '2025-11-09 17:10:18', '2025-11-12 19:43:15'),
(2, 1, 2, '2025-11-09', '23:33:00', '2025-11-09 23:33:26', NULL, 'single', 1, 3400.00, 1600.00, 'fuyhh', 'In Progress', NULL, 5, '2025-11-09 17:33:26', '2025-11-12 19:43:15'),
(3, 5, 23, '2025-11-09', '23:52:00', '2025-11-09 23:53:21', '2025-11-23 17:33:18', 'single', 1, 3400.00, 3600.00, 'fuyhh', 'Completed', NULL, 1, '2025-11-09 17:53:21', '2025-11-23 11:33:18'),
(4, 11, 27, '2025-11-10', '23:39:00', '2025-11-10 23:40:08', '2025-11-23 17:33:23', 'single', 1, 0.00, 7500.00, 'mirpur, Dhaka', 'Completed', NULL, 5, '2025-11-10 17:40:08', '2025-11-23 11:33:23'),
(5, 5, 25, '2025-11-10', '23:43:00', '2025-11-10 23:43:55', NULL, 'single', 1, 5000.00, 2000.00, 'sdfdgf', 'In Progress', NULL, 1, '2025-11-10 17:43:55', '2025-11-12 19:43:15'),
(6, 5, 25, '2025-11-13', '02:02:00', '2025-11-13 02:03:02', '2025-11-23 17:33:37', 'single', 1, 6800.00, 200.00, 'Mirpur 2', 'Completed', NULL, 1, '2025-11-12 20:03:02', '2025-11-23 11:33:37'),
(7, 5, 30, '2025-11-13', '02:05:00', '2025-11-13 02:13:06', '2025-11-16 19:48:33', 'single', 1, 6800.00, 200.00, NULL, 'Completed', NULL, 1, '2025-11-12 20:06:04', '2025-11-16 13:48:33'),
(8, 1, 25, '2025-11-23', '17:29:00', '2025-11-23 17:29:43', '2025-11-23 17:33:30', 'single', 1, 5000.00, 0.00, 'Puran Dhaka', 'Completed', NULL, 5, '2025-11-23 11:29:43', '2025-11-23 11:33:30'),
(9, 12, 2, '2025-11-23', '17:29:00', '2025-11-23 17:32:06', '2025-11-23 17:33:42', 'single', 1, 500.00, 12000.00, 'Malibagh, Dhaka -1219', 'Completed', NULL, 5, '2025-11-23 11:32:06', '2025-11-23 11:33:42'),
(10, 5, 29, '2025-11-24', '15:08:00', '2025-11-24 15:08:28', '2025-11-24 15:08:47', 'single', 1, 500.00, 6500.00, 'Malibag', 'Completed', NULL, 5, '2025-11-24 09:08:28', '2025-11-24 09:08:47'),
(11, 2, 28, '2025-11-24', '15:08:00', '2025-11-24 15:08:43', '2025-11-24 15:08:51', 'single', 1, 30000.00, -29000.00, 'Shahabag, Dhaka', 'Completed', NULL, 5, '2025-11-24 09:08:43', '2025-11-24 09:08:51'),
(12, 6, 1, '2025-11-24', '16:33:00', '2025-11-24 16:36:11', '2025-11-24 16:36:20', 'single', 1, 5000.00, 0.00, 'Malibagh', 'Completed', NULL, 5, '2025-11-24 10:36:11', '2025-11-24 10:36:20');

-- --------------------------------------------------------

--
-- Table structure for table `trip_consolidation_suggestions`
--

CREATE TABLE `trip_consolidation_suggestions` (
  `id` int(11) NOT NULL,
  `base_order_id` bigint(20) UNSIGNED NOT NULL,
  `suggested_order_id` bigint(20) UNSIGNED NOT NULL,
  `compatibility_score` decimal(5,2) DEFAULT 0.00,
  `distance_km` decimal(8,2) DEFAULT NULL,
  `weight_fit` tinyint(1) DEFAULT 0,
  `route_efficiency` decimal(5,2) DEFAULT NULL,
  `suggested_vehicle_id` int(11) DEFAULT NULL,
  `potential_savings` decimal(10,2) DEFAULT NULL,
  `suggestion_status` enum('active','applied','rejected','expired') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `trip_consolidation_suggestions`
--

INSERT INTO `trip_consolidation_suggestions` (`id`, `base_order_id`, `suggested_order_id`, `compatibility_score`, `distance_km`, `weight_fit`, `route_efficiency`, `suggested_vehicle_id`, `potential_savings`, `suggestion_status`, `created_at`, `expires_at`) VALUES
(1, 29, 28, 88.00, NULL, 1, 90.00, 3, NULL, 'active', '2025-11-24 08:54:59', '2025-11-25 08:54:59'),
(2, 28, 29, 88.00, NULL, 1, 90.00, 3, NULL, 'active', '2025-11-24 09:08:29', '2025-11-25 09:08:29');

-- --------------------------------------------------------

--
-- Table structure for table `trip_order_assignments`
--

CREATE TABLE `trip_order_assignments` (
  `id` int(11) NOT NULL,
  `trip_id` int(11) NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `sequence_number` int(11) DEFAULT 1,
  `destination_address` text DEFAULT NULL,
  `estimated_arrival` datetime DEFAULT NULL,
  `actual_arrival` datetime DEFAULT NULL,
  `delivery_status` enum('pending','in_transit','delivered','failed') DEFAULT 'pending',
  `delivery_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `trip_order_assignments`
--

INSERT INTO `trip_order_assignments` (`id`, `trip_id`, `order_id`, `sequence_number`, `destination_address`, `estimated_arrival`, `actual_arrival`, `delivery_status`, `delivery_notes`, `created_at`) VALUES
(1, 1, 14, 1, 'fuyhh', NULL, NULL, 'in_transit', NULL, '2025-11-09 17:10:18'),
(2, 2, 14, 1, 'fuyhh', NULL, NULL, 'in_transit', NULL, '2025-11-09 17:33:26'),
(3, 3, 14, 1, 'fuyhh', NULL, '2025-11-23 17:33:18', 'delivered', '', '2025-11-09 17:53:21'),
(4, 4, 16, 1, 'mirpur, Dhaka', NULL, '2025-11-23 17:33:23', 'delivered', '', '2025-11-10 17:40:08'),
(5, 5, 15, 1, 'sdfdgf', NULL, NULL, 'in_transit', NULL, '2025-11-10 17:43:55'),
(6, 6, 17, 1, 'Mirpur 2', NULL, '2025-11-23 17:33:37', 'delivered', '', '2025-11-12 20:03:02'),
(7, 7, 18, 1, 'mirpur 12', NULL, '2025-11-16 19:48:33', 'delivered', '', '2025-11-12 20:06:04'),
(8, 8, 23, 1, 'Puran Dhaka', NULL, '2025-11-23 17:33:30', 'delivered', '', '2025-11-23 11:29:43'),
(9, 9, 21, 1, 'Malibagh, Dhaka -1219', NULL, '2025-11-23 17:33:42', 'delivered', '', '2025-11-23 11:32:06'),
(10, 10, 28, 1, 'Malibag', NULL, '2025-11-24 15:08:47', 'delivered', '', '2025-11-24 09:08:28'),
(11, 11, 29, 1, 'Shahabag, Dhaka', NULL, '2025-11-24 15:08:51', 'delivered', '', '2025-11-24 09:08:43'),
(12, 12, 30, 1, 'Malibagh', NULL, '2025-11-24 16:36:20', 'delivered', '', '2025-11-24 10:36:11');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(36) NOT NULL DEFAULT uuid(),
  `display_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('Superadmin','admin','Accounts','accounts-demra','accounts-srg','accountspos-demra','accountspos-srg','dispatch-demra','dispatch-srg','dispatchpos-demra','dispatchpos-srg','production manager-srg','production manager-demra','sales-srg','sales-demra','sales-other','collector','Transport Manager') NOT NULL DEFAULT 'sales-other',
  `status` enum('active','pending','suspended') NOT NULL DEFAULT 'pending',
  `last_login` datetime DEFAULT NULL,
  `dashboard_preferences` text DEFAULT NULL COMMENT 'JSON array of selected dashboard widget keys',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `uuid`, `display_name`, `email`, `password_hash`, `role`, `status`, `last_login`, `dashboard_preferences`, `created_at`, `updated_at`) VALUES
(1, '5d3f2f48-af1c-11f0-9003-10ffe0a28e39', 'Dhrobe Islam', 'superadmin@ujjalfm.com', '$2y$12$mhMx6Rfy4ubKiw8q9.o/iOhgPxQRzsh/TwtNmkKME3cUjOjgWjZPW', 'Superadmin', 'active', NULL, NULL, '2025-10-22 07:54:40', '2025-10-22 08:56:48'),
(2, 'b24834c2-b019-11f0-9003-10ffe0a28e39', 'Adnan Illius Siddique', 'adnan@ujjalfm.com', '$2y$12$3Xy/UkUlznHrI0d2GUp.Ou6oliAAewhbIPywG3IT18qvUEQcEXRMC', 'Accounts', 'active', NULL, NULL, '2025-10-23 14:08:06', '2025-10-23 14:08:06'),
(3, '5c5d9e3a-b05d-11f0-9003-10ffe0a28e39', 'POS Srg', 'possrg@ujjalfm.com', '$2y$12$cXWz38Jm6wdSkdMk9XdqJ.m2M3wh2LV7jD2Ndg5H1c7KJPgw04dkK', 'accountspos-srg', 'active', NULL, NULL, '2025-10-23 22:12:27', '2025-10-23 22:12:27'),
(4, '3fea19b1-b39f-11f0-9003-10ffe0a28e39', 'Production Manager', 'pro_demra@ujjalfm.com', '$2y$12$hgUnr2qoSyqJAsArU/79ZuMJNG8ApwjR2i2bTWDkg00NuXMphmwTm', 'production manager-demra', 'active', NULL, NULL, '2025-10-28 01:41:40', '2025-10-28 01:41:40'),
(5, '9815e329-b3da-11f0-9003-10ffe0a28e39', 'Dispatch Demra', 'dispatch_demra@ujjalfm.com', '$2y$12$2gazb3F28A2f3KZQRfy8/eC1eWz6WgY5RnJwOFu0bwMllW0ZNKHB6', 'dispatch-demra', 'active', NULL, NULL, '2025-10-28 08:46:28', '2025-10-28 08:46:28'),
(6, '8467ed8a-b3f1-11f0-9003-10ffe0a28e39', 'Sales Officer Demra', 'sales_demra@ujjalfm.com', '$2y$12$P9zYUS6FZI2BxuiqZre7b.xi/4w7MQ7Q2TOrJtdR02RuPW45AYJMa', 'sales-demra', 'active', NULL, NULL, '2025-10-28 11:30:33', '2025-10-28 11:30:33'),
(7, '8661415e-b6ef-11f0-9003-10ffe0a28e39', 'Expense Demra', 'expense_demra@ujjalfm.com', '$2y$12$Fce/vasSgzAfwzSQTF9f7ezq6W4WqJS4M6tCHUhpFxKjTTN6bLFBi', 'accounts-demra', 'active', NULL, NULL, '2025-11-01 06:53:51', '2025-11-01 06:53:51'),
(8, 'de94fe3b-b9a5-11f0-a079-10ffe0a28e39', 'Trasnport Manager', 'trans_demra@ujjalfm.com', '$2y$12$i.uhN82mkGp2lSNYY4oAlOHvtBkyxn.HuLjcQMWhtejFeeCrqRRu6', 'Transport Manager', 'active', NULL, NULL, '2025-11-04 17:44:10', '2025-11-04 17:44:10');

-- --------------------------------------------------------

--
-- Table structure for table `user_dashboard_preferences`
--

CREATE TABLE `user_dashboard_preferences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `widget_id` int(11) UNSIGNED NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(11) NOT NULL DEFAULT 0,
  `size` enum('small','medium','large','full') NOT NULL DEFAULT 'medium',
  `date_range` varchar(50) DEFAULT 'today' COMMENT 'Date range for reports: today, yesterday, this_week, last_week, this_month, last_month, this_quarter, this_year, last_30_days, last_90_days',
  `refresh_interval` int(11) DEFAULT 0 COMMENT 'Auto-refresh interval in seconds (0 = no auto-refresh)',
  `custom_config` text DEFAULT NULL COMMENT 'JSON field for widget-specific custom configurations',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_dashboard_preferences`
--

INSERT INTO `user_dashboard_preferences` (`id`, `user_id`, `widget_id`, `is_enabled`, `position`, `size`, `date_range`, `refresh_interval`, `custom_config`, `created_at`, `updated_at`) VALUES
(112, 1, 1, 1, 0, 'medium', 'this_year', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(113, 1, 2, 1, 1, 'medium', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(114, 1, 3, 1, 2, 'full', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(115, 1, 4, 1, 3, 'small', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(116, 1, 5, 1, 4, 'full', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(117, 1, 6, 1, 5, 'medium', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(118, 1, 7, 1, 6, 'medium', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(119, 1, 8, 1, 7, 'medium', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(120, 1, 20, 1, 8, 'medium', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(121, 1, 21, 1, 9, 'medium', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(122, 1, 10, 1, 10, 'medium', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(123, 1, 11, 1, 11, 'medium', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(124, 1, 15, 1, 12, 'medium', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(125, 1, 12, 1, 13, 'medium', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(126, 1, 13, 1, 14, 'medium', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(127, 1, 14, 1, 15, 'medium', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(128, 1, 23, 1, 16, 'medium', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(129, 1, 16, 1, 17, 'medium', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(130, 1, 17, 1, 18, 'medium', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02'),
(131, 1, 22, 1, 19, 'medium', 'today', 0, NULL, '2025-10-31 21:44:02', '2025-10-31 21:44:02');

-- --------------------------------------------------------

--
-- Table structure for table `user_preference_audit`
--

CREATE TABLE `user_preference_audit` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `changed_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `action_type` enum('save','reset','import') NOT NULL,
  `widgets_count` int(11) NOT NULL DEFAULT 0,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `changes_summary` text DEFAULT NULL COMMENT 'Summary of what changed',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for dashboard preference changes';

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL,
  `vehicle_number` varchar(50) NOT NULL,
  `vehicle_type` enum('Own','Rented') NOT NULL DEFAULT 'Own',
  `category` enum('Truck','Van','Pickup','Motorcycle','Other') DEFAULT 'Truck',
  `capacity_kg` decimal(10,2) DEFAULT 0.00,
  `fuel_type` enum('Diesel','Petrol','CNG','Electric') DEFAULT 'Diesel',
  `make` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `year` int(11) DEFAULT NULL,
  `status` enum('Active','Maintenance','Inactive') DEFAULT 'Active',
  `ownership_status` enum('Owned','Leased','Rented') DEFAULT 'Owned',
  `assigned_branch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `rental_rate_per_day` decimal(10,2) DEFAULT NULL,
  `rental_start_date` date DEFAULT NULL,
  `rental_end_date` date DEFAULT NULL,
  `rental_vendor_name` varchar(255) DEFAULT NULL,
  `rental_vendor_phone` varchar(20) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_price` decimal(12,2) DEFAULT NULL,
  `current_mileage` decimal(10,2) DEFAULT 0.00,
  `next_service_due_date` date DEFAULT NULL,
  `next_service_due_mileage` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`id`, `vehicle_number`, `vehicle_type`, `category`, `capacity_kg`, `fuel_type`, `make`, `model`, `year`, `status`, `ownership_status`, `assigned_branch_id`, `rental_rate_per_day`, `rental_start_date`, `rental_end_date`, `rental_vendor_name`, `rental_vendor_phone`, `purchase_date`, `purchase_price`, `current_mileage`, `next_service_due_date`, `next_service_due_mileage`, `notes`, `created_at`, `updated_at`) VALUES
(1, 'DHK-GA-1234', 'Own', 'Truck', 5000.00, 'Diesel', 'Tata', 'LPT 1109', 2020, 'Active', 'Owned', NULL, NULL, NULL, NULL, NULL, NULL, '2020-03-15', 2500000.00, 45000.00, '2025-12-01', 50000.00, 'Main delivery truck - good condition, regular maintenance schedule', '2025-11-08 15:13:40', '2025-11-08 15:13:40'),
(2, 'DHK-HA-5678', 'Rented', 'Van', 1000.00, 'Diesel', 'Mahindra', 'Supro Cargo', 2022, 'Active', 'Rented', NULL, 2500.00, '2025-10-01', '2026-03-31', 'Express Transport Services', '01712345678', NULL, NULL, 28000.00, '2025-11-20', 30000.00, 'Rented for peak season delivery - 6 month contract with Express Transport', '2025-11-08 15:13:40', '2025-11-08 15:13:40'),
(3, 'Hdhdhjd', 'Own', 'Truck', 448484.00, 'Diesel', 'Hdhd', 'Jdhd', 2011, 'Active', 'Owned', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 0.00, NULL, 0.00, NULL, '2025-11-08 15:15:02', '2025-11-08 15:15:02'),
(4, 'Dhaka Metro-TA-11-1234', 'Own', 'Truck', 12000.00, 'Diesel', 'Tata', 'LPT 1615', 2019, 'Active', 'Owned', 1, NULL, NULL, NULL, NULL, NULL, '2019-03-01', 4500000.00, 185000.00, '2025-12-15', 200000.00, 'Core fleet vehicle for long haul.', '2025-11-09 17:52:41', '2025-11-09 17:52:41'),
(5, 'Ctg Metro-TA-13-5678', 'Own', 'Truck', 7000.00, 'Diesel', 'Isuzu', 'NQR', 2021, 'Active', 'Owned', 2, NULL, NULL, NULL, NULL, NULL, '2021-01-20', 3800000.00, 95000.00, '2026-01-10', 105000.00, 'Assigned to Chattogram branch.', '2025-11-09 17:52:41', '2025-11-09 17:52:41'),
(6, 'Dhaka Metro-DA-15-9901', 'Own', 'Van', 5000.00, 'Diesel', 'Mitsubishi Fuso', 'Canter', 2020, 'Active', 'Owned', 1, NULL, NULL, NULL, NULL, NULL, '2020-02-10', 3200000.00, 110000.00, '2026-02-01', 120000.00, 'Covered van for secure electronics transport.', '2025-11-09 17:52:41', '2025-11-09 17:52:41'),
(7, 'Dhaka Metro-NA-18-2233', 'Own', 'Pickup', 2500.00, 'CNG', 'Tata', 'Ace EX2', 2022, 'Active', 'Owned', 1, NULL, NULL, NULL, NULL, NULL, '2022-05-15', 1800000.00, 75000.00, '2026-03-01', 85000.00, 'City-wide small package delivery.', '2025-11-09 17:52:41', '2025-11-09 17:52:41'),
(8, 'Sylhet Metro-TA-11-4545', 'Rented', 'Truck', 15000.00, 'Diesel', 'Hino', '500 Series', 2018, 'Active', 'Rented', 3, 12000.00, '2025-01-01', '2026-12-31', 'Sylhet Trucking Co.', '01712345678', NULL, NULL, 230000.00, NULL, NULL, 'Rented for peak season demand in Sylhet region.', '2025-11-09 17:52:41', '2025-11-09 17:52:41'),
(9, 'Dhaka Metro-TA-12-8877', 'Own', 'Truck', 8000.00, 'Diesel', 'Eicher', 'Pro 3008', 2017, 'Maintenance', 'Owned', 1, NULL, NULL, NULL, NULL, NULL, '2017-10-01', 3500000.00, 255000.00, '2025-11-01', 255000.00, 'Engine overhaul in progress.', '2025-11-09 17:52:41', '2025-11-09 17:52:41'),
(10, 'Ctg Metro-NA-17-1199', 'Rented', 'Pickup', 2000.00, 'CNG', 'JAC', 'N-Series', 2020, 'Active', 'Rented', 2, 4500.00, '2025-06-01', '2026-05-31', 'Karnaphuli Rentals', '01812345678', NULL, NULL, 90000.00, NULL, NULL, 'Local delivery vehicle for Chattogram port operations.', '2025-11-09 17:52:41', '2025-11-09 17:52:41'),
(11, 'Dhaka Metro-DA-14-3434', 'Own', 'Van', 7500.00, 'Diesel', 'Isuzu', 'FVR', 2018, 'Active', 'Owned', 1, NULL, NULL, NULL, NULL, NULL, '2018-11-01', 4200000.00, 215000.00, '2026-01-20', 225000.00, 'Large covered van for FMCG distribution.', '2025-11-09 17:52:41', '2025-11-09 17:52:41'),
(12, 'Dhaka Metro-TA-11-8080', 'Own', 'Truck', 12500.00, 'Diesel', 'Tata', 'Prima 2528', 2022, 'Active', 'Leased', 1, NULL, NULL, NULL, NULL, NULL, '2022-07-01', 5500000.00, 150000.00, '2026-04-01', 160000.00, 'Leased from IDLC Finance. 5-year term.', '2025-11-09 17:52:41', '2025-11-09 17:52:41'),
(13, 'Dhaka Metro-NA-19-1212', 'Own', 'Pickup', 1500.00, 'Petrol', 'Suzuki', 'Carry', 2016, 'Inactive', 'Owned', 1, NULL, NULL, NULL, NULL, NULL, '2016-04-01', 1200000.00, 310000.00, '2025-01-01', 310000.00, 'Scheduled for disposal/auction. High mileage.', '2025-11-09 17:52:41', '2025-11-09 17:52:41');

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_documents`
--

CREATE TABLE `vehicle_documents` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `document_type` varchar(100) NOT NULL,
  `document_number` varchar(100) DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicle_documents`
--

INSERT INTO `vehicle_documents` (`id`, `vehicle_id`, `document_type`, `document_number`, `issue_date`, `expiry_date`, `file_path`, `notes`, `created_at`) VALUES
(1, 10, 'Tax Token', 'DM-NA-17-1199', '2024-10-01', '2025-10-01', NULL, 'Expired', '2025-11-14 13:38:13');

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_logbook`
--

CREATE TABLE `vehicle_logbook` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `log_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `start_odometer` int(11) DEFAULT NULL,
  `end_odometer` int(11) DEFAULT NULL,
  `distance_km` int(11) GENERATED ALWAYS AS (`end_odometer` - `start_odometer`) STORED,
  `purpose` varchar(255) DEFAULT NULL,
  `destination` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_rentals`
--

CREATE TABLE `vehicle_rentals` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `rental_type` enum('Trip','Daily','Monthly','Fixed') NOT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('Scheduled','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Scheduled',
  `payment_status` enum('Pending','Partially Paid','Paid') NOT NULL DEFAULT 'Pending',
  `notes` text DEFAULT NULL,
  `journal_entry_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicle_rentals`
--

INSERT INTO `vehicle_rentals` (`id`, `vehicle_id`, `customer_id`, `rental_type`, `start_datetime`, `end_datetime`, `rate`, `total_amount`, `status`, `payment_status`, `notes`, `journal_entry_id`, `created_by_user_id`, `created_at`, `updated_at`) VALUES
(1, 2, 10, 'Trip', '2025-11-14 20:04:00', '2025-11-15 20:04:00', 10000.00, 10000.00, 'Scheduled', 'Pending', 'Trial rent', 52, 1, '2025-11-14 14:09:18', '2025-11-14 14:09:18');

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_wheat_shipment_summary`
-- (See below for the actual view)
--
CREATE TABLE `vw_wheat_shipment_summary` (
`status` enum('Scheduled','In Transit','Arrived','Unloading','Completed','Cancelled')
,`shipment_count` bigint(21)
,`total_tons` decimal(34,2)
,`total_cost` decimal(37,2)
,`next_arrival` date
,`latest_departure` date
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_customer_outstanding`
-- (See below for the actual view)
--
CREATE TABLE `v_customer_outstanding` (
`id` bigint(20) unsigned
,`name` varchar(255)
,`phone_number` varchar(50)
,`current_balance` decimal(12,2)
,`credit_limit` decimal(12,2)
,`total_orders` bigint(21)
,`unpaid_invoices` bigint(21)
,`total_invoiced` decimal(34,2)
,`total_paid` decimal(32,2)
,`total_outstanding` decimal(35,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_order_audit_history`
-- (See below for the actual view)
--
CREATE TABLE `v_order_audit_history` (
`id` bigint(20) unsigned
,`order_id` bigint(20) unsigned
,`order_number` varchar(50)
,`action_type` enum('created','updated','status_changed','priority_changed','payment_collected','cancelled','deleted')
,`field_name` varchar(100)
,`old_value` text
,`new_value` text
,`changed_by` varchar(255)
,`user_role` enum('Superadmin','admin','Accounts','accounts-demra','accounts-srg','accountspos-demra','accountspos-srg','dispatch-demra','dispatch-srg','dispatchpos-demra','dispatchpos-srg','production manager-srg','production manager-demra','sales-srg','sales-demra','sales-other','collector','Transport Manager')
,`changed_at` timestamp
,`notes` text
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_payment_allocations_detail`
-- (See below for the actual view)
--
CREATE TABLE `v_payment_allocations_detail` (
`id` bigint(20) unsigned
,`payment_id` bigint(20) unsigned
,`receipt_number` varchar(50)
,`payment_date` date
,`payment_amount` decimal(12,2)
,`payment_method` enum('Cash','Bank Transfer','Cheque','Mobile Banking','Card')
,`customer_name` varchar(255)
,`order_id` bigint(20) unsigned
,`order_number` varchar(50)
,`invoice_amount` decimal(12,2)
,`allocated_amount` decimal(12,2)
,`allocated_by` varchar(255)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_payment_summary_by_date`
-- (See below for the actual view)
--
CREATE TABLE `v_payment_summary_by_date` (
`payment_date` date
,`payment_method` enum('Cash','Bank Transfer','Cheque','Mobile Banking','Card')
,`num_payments` bigint(21)
,`total_collected` decimal(34,2)
,`avg_payment` decimal(16,6)
,`allocated_amount` decimal(34,2)
,`unallocated_amount` decimal(34,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_petty_cash_accounts`
-- (See below for the actual view)
--
CREATE TABLE `v_petty_cash_accounts` (
`coa_id` bigint(20) unsigned
,`account_name` varchar(255)
,`branch_id` bigint(20) unsigned
,`branch_name` varchar(255)
,`petty_cash_account_id` bigint(20) unsigned
,`current_balance` decimal(12,2)
,`opening_balance` decimal(12,2)
,`status` enum('active','inactive','closed')
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_purchase_adnan_dashboard`
-- (See below for the actual view)
--
CREATE TABLE `v_purchase_adnan_dashboard` (
`id` bigint(20) unsigned
,`po_number` varchar(50)
,`po_date` date
,`supplier_name` varchar(255)
,`wheat_origin` varchar(50)
,`quantity_kg` decimal(15,2)
,`unit_price_per_kg` decimal(15,4)
,`total_order_value` decimal(15,2)
,`total_received_qty` decimal(15,2)
,`qty_yet_to_receive` decimal(15,2)
,`total_received_value` decimal(15,2)
,`total_paid` decimal(15,2)
,`balance_payable` decimal(15,2)
,`delivery_status` enum('pending','partial','completed','over_received')
,`payment_status` enum('unpaid','partial','paid','overpaid')
,`grn_count` bigint(21)
,`payment_count` bigint(21)
,`days_since_order` int(8)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_purchase_adnan_supplier_summary`
-- (See below for the actual view)
--
CREATE TABLE `v_purchase_adnan_supplier_summary` (
`supplier_id` bigint(20) unsigned
,`supplier_name` varchar(255)
,`total_orders` bigint(21)
,`total_ordered_value` decimal(37,2)
,`total_received_value` decimal(37,2)
,`total_paid` decimal(37,2)
,`balance_payable` decimal(37,2)
,`last_order_date` date
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_purchase_adnan_variance_analysis`
-- (See below for the actual view)
--
CREATE TABLE `v_purchase_adnan_variance_analysis` (
`id` bigint(20) unsigned
,`po_number` varchar(50)
,`supplier_name` varchar(255)
,`grn_number` varchar(50)
,`truck_number` varchar(20)
,`grn_date` date
,`ordered_quantity` decimal(15,2)
,`received_quantity` decimal(15,2)
,`variance` decimal(10,2)
,`variance_percentage` decimal(5,2)
,`variance_type` enum('loss','gain','normal')
,`variance_value` decimal(15,2)
,`remarks` text
);

-- --------------------------------------------------------

--
-- Table structure for table `weight_variances_adnan`
--

CREATE TABLE `weight_variances_adnan` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `grn_id` bigint(20) UNSIGNED NOT NULL,
  `purchase_order_id` bigint(20) UNSIGNED NOT NULL,
  `truck_number` varchar(20) DEFAULT NULL,
  `ordered_quantity` decimal(15,2) DEFAULT NULL,
  `received_quantity` decimal(15,2) NOT NULL,
  `variance` decimal(10,2) NOT NULL COMMENT 'Positive = gain, Negative = loss',
  `variance_percentage` decimal(5,2) NOT NULL,
  `variance_type` enum('loss','gain','normal') NOT NULL COMMENT 'Based on threshold',
  `variance_value` decimal(15,2) NOT NULL COMMENT 'Financial impact',
  `remarks` text DEFAULT NULL,
  `recorded_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Weight variance tracking for analysis';

--
-- Dumping data for table `weight_variances_adnan`
--

INSERT INTO `weight_variances_adnan` (`id`, `grn_id`, `purchase_order_id`, `truck_number`, `ordered_quantity`, `received_quantity`, `variance`, `variance_percentage`, `variance_type`, `variance_value`, `remarks`, `recorded_at`) VALUES
(1, 1, 1, '2234', 250000.00, 220000.00, -30000.00, -12.00, 'loss', 900000.00, NULL, '2025-11-23 21:51:05');

-- --------------------------------------------------------

--
-- Table structure for table `wheat_alerts`
--

CREATE TABLE `wheat_alerts` (
  `id` int(11) NOT NULL,
  `shipment_id` int(11) DEFAULT NULL,
  `alert_type` enum('Delay','Arrival','Price','Weather','Custom') NOT NULL,
  `severity` enum('Info','Warning','Critical') DEFAULT 'Info',
  `message` varchar(500) NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wheat_api_cache`
--

CREATE TABLE `wheat_api_cache` (
  `id` int(11) NOT NULL,
  `cache_key` varchar(255) NOT NULL,
  `cache_data` mediumtext DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wheat_market_data`
--

CREATE TABLE `wheat_market_data` (
  `id` int(11) NOT NULL,
  `data_source` varchar(100) NOT NULL,
  `country_code` varchar(10) DEFAULT NULL,
  `country_name` varchar(100) DEFAULT NULL,
  `year` int(4) NOT NULL,
  `month` int(2) DEFAULT NULL,
  `export_quantity_tons` decimal(15,2) DEFAULT NULL,
  `export_value_usd` decimal(18,2) DEFAULT NULL,
  `import_quantity_tons` decimal(15,2) DEFAULT NULL,
  `import_value_usd` decimal(18,2) DEFAULT NULL,
  `production_tons` decimal(15,2) DEFAULT NULL,
  `consumption_tons` decimal(15,2) DEFAULT NULL,
  `price_per_ton_usd` decimal(10,2) DEFAULT NULL,
  `data_period` varchar(50) DEFAULT NULL,
  `raw_data` text DEFAULT NULL,
  `fetched_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wheat_shipments`
--

CREATE TABLE `wheat_shipments` (
  `id` int(11) NOT NULL,
  `shipment_number` varchar(50) NOT NULL,
  `vessel_name` varchar(200) NOT NULL,
  `vessel_mmsi` varchar(20) DEFAULT NULL,
  `vessel_imo` varchar(20) DEFAULT NULL,
  `origin_port` varchar(200) NOT NULL,
  `origin_country` varchar(100) DEFAULT NULL,
  `destination_port` varchar(200) NOT NULL,
  `destination_country` varchar(100) DEFAULT NULL,
  `quantity_tons` decimal(12,2) NOT NULL DEFAULT 0.00,
  `wheat_type` varchar(100) DEFAULT NULL,
  `supplier_name` varchar(200) DEFAULT NULL,
  `departure_date` date DEFAULT NULL,
  `expected_arrival` date DEFAULT NULL,
  `actual_arrival` date DEFAULT NULL,
  `status` enum('Scheduled','In Transit','Arrived','Unloading','Completed','Cancelled') DEFAULT 'Scheduled',
  `current_position_lat` decimal(10,8) DEFAULT NULL,
  `current_position_lon` decimal(11,8) DEFAULT NULL,
  `last_position_update` datetime DEFAULT NULL,
  `total_cost` decimal(15,2) DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'USD',
  `payment_status` enum('Pending','Partial','Paid') DEFAULT 'Pending',
  `branch_id` int(11) DEFAULT NULL,
  `assigned_to` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `documents` text DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `wheat_shipments`
--

INSERT INTO `wheat_shipments` (`id`, `shipment_number`, `vessel_name`, `vessel_mmsi`, `vessel_imo`, `origin_port`, `origin_country`, `destination_port`, `destination_country`, `quantity_tons`, `wheat_type`, `supplier_name`, `departure_date`, `expected_arrival`, `actual_arrival`, `status`, `current_position_lat`, `current_position_lon`, `last_position_update`, `total_cost`, `currency`, `payment_status`, `branch_id`, `assigned_to`, `notes`, `documents`, `created_by`, `created_at`, `updated_by`, `updated_at`) VALUES
(1, 'WS-2024-001', 'MV Grain Explorer', NULL, NULL, 'Novorossiysk', 'Russia', 'Chittagong', 'Bangladesh', 50000.00, 'Hard Red Winter', NULL, '2024-11-01', '2024-11-25', NULL, 'In Transit', NULL, NULL, NULL, 0.00, 'USD', 'Pending', NULL, NULL, NULL, NULL, 'System', '2025-11-16 17:38:21', NULL, '2025-11-16 17:38:21'),
(2, 'WS-2024-002', 'MV Wheat Carrier', NULL, NULL, 'Odessa', 'Ukraine', 'Chittagong', 'Bangladesh', 35000.00, 'Soft Red Winter', NULL, '2024-11-10', '2024-12-05', NULL, 'In Transit', NULL, NULL, NULL, 0.00, 'USD', 'Pending', NULL, NULL, NULL, NULL, 'System', '2025-11-16 17:38:21', NULL, '2025-11-16 17:38:21'),
(3, 'WS-2024-003', 'MV Grain Star', NULL, NULL, 'Adelaide', 'Australia', 'Chittagong', 'Bangladesh', 45000.00, 'Australian Prime Hard', NULL, '2024-11-15', '2024-12-10', NULL, 'Scheduled', NULL, NULL, NULL, 0.00, 'USD', 'Pending', NULL, NULL, NULL, NULL, 'System', '2025-11-16 17:38:21', NULL, '2025-11-16 17:38:21');

-- --------------------------------------------------------

--
-- Table structure for table `wheat_shipment_positions`
--

CREATE TABLE `wheat_shipment_positions` (
  `id` int(11) NOT NULL,
  `shipment_id` int(11) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `speed_knots` decimal(5,2) DEFAULT NULL,
  `course` decimal(5,2) DEFAULT NULL,
  `position_source` enum('Manual','API','AIS') DEFAULT 'Manual',
  `recorded_at` datetime NOT NULL,
  `notes` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `wheat_shipment_positions`
--
DELIMITER $$
CREATE TRIGGER `tr_update_shipment_position` AFTER INSERT ON `wheat_shipment_positions` FOR EACH ROW BEGIN
  UPDATE wheat_shipments
  SET 
    current_position_lat = NEW.latitude,
    current_position_lon = NEW.longitude,
    last_position_update = NEW.recorded_at
  WHERE id = NEW.shipment_id;
END
$$
DELIMITER ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bank_accounts`
--
ALTER TABLE `bank_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uuid` (`uuid`),
  ADD UNIQUE KEY `account_number` (`account_number`),
  ADD KEY `idx_chart_of_account_id` (`chart_of_account_id`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `name_index` (`name`),
  ADD KEY `code_index` (`code`);

--
-- Indexes for table `branch_petty_cash_accounts`
--
ALTER TABLE `branch_petty_cash_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_branch` (`branch_id`),
  ADD KEY `idx_branch_status` (`branch_id`,`status`);

--
-- Indexes for table `branch_petty_cash_transactions`
--
ALTER TABLE `branch_petty_cash_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_branch_date` (`branch_id`,`transaction_date`),
  ADD KEY `idx_account` (`account_id`),
  ADD KEY `idx_reference` (`reference_type`,`reference_id`),
  ADD KEY `idx_type` (`transaction_type`);

--
-- Indexes for table `cash_verification_log`
--
ALTER TABLE `cash_verification_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_branch_date` (`branch_id`,`verification_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `account_number` (`account_number`),
  ADD KEY `idx_branch_id` (`branch_id`);

--
-- Indexes for table `credit_orders`
--
ALTER TABLE `credit_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_order_number` (`order_number`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_branch` (`assigned_branch_id`),
  ADD KEY `idx_amount_paid` (`amount_paid`),
  ADD KEY `idx_balance_due` (`balance_due`),
  ADD KEY `idx_sort_order` (`sort_order`,`status`),
  ADD KEY `idx_status_weight` (`status`),
  ADD KEY `idx_total_weight` (`total_weight_kg`);

--
-- Indexes for table `credit_order_audit`
--
ALTER TABLE `credit_order_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_audit` (`order_id`,`created_at`),
  ADD KEY `idx_user_audit` (`user_id`,`created_at`),
  ADD KEY `idx_action_type` (`action_type`,`created_at`);

--
-- Indexes for table `credit_order_items`
--
ALTER TABLE `credit_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order` (`order_id`),
  ADD KEY `idx_item_weight` (`total_weight_kg`);

--
-- Indexes for table `credit_order_shipping`
--
ALTER TABLE `credit_order_shipping`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_order_shipping` (`order_id`),
  ADD KEY `idx_trip_id` (`trip_id`);

--
-- Indexes for table `credit_order_workflow`
--
ALTER TABLE `credit_order_workflow`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order` (`order_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uuid` (`uuid`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `phone_number` (`phone_number`),
  ADD KEY `customer_type` (`customer_type`),
  ADD KEY `business_name` (`business_name`);

--
-- Indexes for table `customer_ledger`
--
ALTER TABLE `customer_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_date` (`transaction_date`);

--
-- Indexes for table `customer_payments`
--
ALTER TABLE `customer_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_payment_number` (`payment_number`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_payment_date_method` (`payment_date`,`payment_method`),
  ADD KEY `idx_customer_date` (`customer_id`,`payment_date`),
  ADD KEY `idx_allocation_status` (`allocation_status`);

--
-- Indexes for table `dashboard_widgets`
--
ALTER TABLE `dashboard_widgets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `widget_key` (`widget_key`),
  ADD KEY `idx_widget_category_active` (`widget_category`,`is_active`,`sort_order`);

--
-- Indexes for table `debit_vouchers`
--
ALTER TABLE `debit_vouchers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `voucher_number` (`voucher_number`),
  ADD KEY `idx_voucher_date` (`voucher_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_expense_account` (`expense_account_id`),
  ADD KEY `idx_payment_account` (`payment_account_id`),
  ADD KEY `idx_employee` (`employee_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `drivers`
--
ALTER TABLE `drivers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assigned_vehicle_id` (`assigned_vehicle_id`),
  ADD KEY `idx_driver_name` (`driver_name`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_assigned_branch` (`assigned_branch_id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_license_expiry_date` (`license_expiry_date`),
  ADD KEY `idx_join_date` (`join_date`),
  ADD KEY `idx_photo_path` (`photo_path`);

--
-- Indexes for table `driver_attendance`
--
ALTER TABLE `driver_attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_driver_date` (`driver_id`,`attendance_date`),
  ADD KEY `idx_attendance_date` (`attendance_date`);

--
-- Indexes for table `driver_documents`
--
ALTER TABLE `driver_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_driver_id` (`driver_id`),
  ADD KEY `idx_expiry_date` (`expiry_date`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `position_id` (`position_id`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `eod_audit_trail`
--
ALTER TABLE `eod_audit_trail`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_eod_id` (`eod_id`),
  ADD KEY `idx_branch_id` (`branch_id`),
  ADD KEY `idx_eod_date` (`eod_date`),
  ADD KEY `idx_performed_by` (`performed_by_user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_branch_date_action` (`branch_id`,`eod_date`,`action`);

--
-- Indexes for table `eod_summary`
--
ALTER TABLE `eod_summary`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_branch_date` (`branch_id`,`eod_date`),
  ADD KEY `idx_branch_id` (`branch_id`),
  ADD KEY `idx_eod_date` (`eod_date`),
  ADD KEY `idx_created_by` (`created_by_user_id`),
  ADD KEY `idx_branch_date` (`branch_id`,`eod_date` DESC);

--
-- Indexes for table `fuel_logs`
--
ALTER TABLE `fuel_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `trip_id` (`trip_id`),
  ADD KEY `idx_vehicle_id` (`vehicle_id`),
  ADD KEY `idx_fuel_date` (`fuel_date`);

--
-- Indexes for table `goods_received_adnan`
--
ALTER TABLE `goods_received_adnan`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `grn_number` (`grn_number`),
  ADD KEY `idx_grn_number` (`grn_number`),
  ADD KEY `idx_po_id` (`purchase_order_id`),
  ADD KEY `idx_grn_date` (`grn_date`),
  ADD KEY `idx_truck_number` (`truck_number`),
  ADD KEY `idx_unload_point` (`unload_point_branch_id`),
  ADD KEY `idx_grn_status` (`grn_status`),
  ADD KEY `fk_grn_adnan_supplier` (`supplier_id`),
  ADD KEY `fk_grn_adnan_receiver` (`receiver_user_id`),
  ADD KEY `fk_grn_adnan_journal` (`journal_entry_id`);

--
-- Indexes for table `goods_received_items`
--
ALTER TABLE `goods_received_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_grn` (`grn_id`),
  ADD KEY `idx_po_item` (`po_item_id`),
  ADD KEY `idx_variant` (`variant_id`);

--
-- Indexes for table `goods_received_notes`
--
ALTER TABLE `goods_received_notes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `grn_number` (`grn_number`),
  ADD UNIQUE KEY `idx_grn_number` (`grn_number`),
  ADD KEY `idx_purchase_order` (`purchase_order_id`),
  ADD KEY `idx_supplier` (`supplier_id`),
  ADD KEY `idx_branch` (`branch_id`),
  ADD KEY `idx_received_date` (`received_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `variant_branch` (`variant_id`,`branch_id`) COMMENT 'Ensures only one stock entry per variant per branch',
  ADD KEY `variant_id` (`variant_id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by_user_id` (`created_by_user_id`),
  ADD KEY `fk_journal_to_employee` (`responsible_employee_id`);

--
-- Indexes for table `maintenance_logs`
--
ALTER TABLE `maintenance_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vehicle_id` (`vehicle_id`),
  ADD KEY `idx_maintenance_date` (`maintenance_date`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uuid` (`uuid`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `fk_order_branch` (`branch_id`),
  ADD KEY `fk_order_customer` (`customer_id`),
  ADD KEY `fk_order_user` (`created_by_user_id`),
  ADD KEY `fk_order_journal` (`journal_entry_id`),
  ADD KEY `idx_order_date` (`order_date`),
  ADD KEY `idx_order_status` (`order_status`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_orderitem_order` (`order_id`),
  ADD KEY `fk_orderitem_variant` (`variant_id`),
  ADD KEY `idx_order_items_order` (`order_id`),
  ADD KEY `idx_order_items_variant` (`variant_id`);

--
-- Indexes for table `payment_allocations`
--
ALTER TABLE `payment_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payment_id` (`payment_id`),
  ADD KEY `idx_order_id` (`order_id`);

--
-- Indexes for table `petty_cash_accounts`
--
ALTER TABLE `petty_cash_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `positions`
--
ALTER TABLE `positions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `dept_title_unique` (`department_id`,`title`);

--
-- Indexes for table `production_schedule`
--
ALTER TABLE `production_schedule`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order` (`order_id`),
  ADD KEY `idx_branch` (`branch_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uuid` (`uuid`),
  ADD UNIQUE KEY `base_sku` (`base_sku`);

--
-- Indexes for table `product_prices`
--
ALTER TABLE `product_prices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_prices_variant` (`variant_id`),
  ADD KEY `fk_prices_branch` (`branch_id`);

--
-- Indexes for table `product_variants`
--
ALTER TABLE `product_variants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uuid` (`uuid`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `purchase_invoices`
--
ALTER TABLE `purchase_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD UNIQUE KEY `idx_invoice_number` (`invoice_number`),
  ADD KEY `idx_supplier_invoice` (`supplier_invoice_number`),
  ADD KEY `idx_purchase_order` (`purchase_order_id`),
  ADD KEY `idx_grn` (`grn_id`),
  ADD KEY `idx_supplier` (`supplier_id`),
  ADD KEY `idx_branch` (`branch_id`),
  ADD KEY `idx_invoice_date` (`invoice_date`),
  ADD KEY `idx_payment_status` (`payment_status`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_journal_entry` (`journal_entry_id`);

--
-- Indexes for table `purchase_invoice_items`
--
ALTER TABLE `purchase_invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_invoice` (`purchase_invoice_id`),
  ADD KEY `idx_po_item` (`po_item_id`),
  ADD KEY `idx_grn_item` (`grn_item_id`),
  ADD KEY `idx_variant` (`variant_id`),
  ADD KEY `idx_account` (`account_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `po_number` (`po_number`),
  ADD UNIQUE KEY `idx_po_number` (`po_number`),
  ADD KEY `idx_supplier` (`supplier_id`),
  ADD KEY `idx_branch` (`branch_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_po_date` (`po_date`),
  ADD KEY `idx_payment_status` (`payment_status`);

--
-- Indexes for table `purchase_orders_adnan`
--
ALTER TABLE `purchase_orders_adnan`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `po_number` (`po_number`),
  ADD KEY `idx_po_number` (`po_number`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_po_date` (`po_date`),
  ADD KEY `idx_delivery_status` (`delivery_status`),
  ADD KEY `idx_payment_status` (`payment_status`),
  ADD KEY `idx_wheat_origin` (`wheat_origin`),
  ADD KEY `fk_poa_created_by` (`created_by_user_id`);

--
-- Indexes for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_order` (`purchase_order_id`),
  ADD KEY `idx_variant` (`variant_id`),
  ADD KEY `idx_item_type` (`item_type`);

--
-- Indexes for table `purchase_payments_adnan`
--
ALTER TABLE `purchase_payments_adnan`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_voucher_number` (`payment_voucher_number`),
  ADD KEY `idx_voucher_number` (`payment_voucher_number`),
  ADD KEY `idx_po_id` (`purchase_order_id`),
  ADD KEY `idx_payment_date` (`payment_date`),
  ADD KEY `idx_payment_method` (`payment_method`),
  ADD KEY `idx_bank_account` (`bank_account_id`),
  ADD KEY `idx_is_posted` (`is_posted`),
  ADD KEY `fk_payment_adnan_supplier` (`supplier_id`),
  ADD KEY `fk_payment_adnan_journal` (`journal_entry_id`),
  ADD KEY `fk_payment_adnan_created_by` (`created_by_user_id`);

--
-- Indexes for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `return_number` (`return_number`),
  ADD UNIQUE KEY `idx_return_number` (`return_number`),
  ADD KEY `idx_purchase_invoice` (`purchase_invoice_id`),
  ADD KEY `idx_grn` (`grn_id`),
  ADD KEY `idx_supplier` (`supplier_id`),
  ADD KEY `idx_branch` (`branch_id`),
  ADD KEY `idx_return_date` (`return_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `fk_pr_journal_entry` (`journal_entry_id`);

--
-- Indexes for table `purchase_return_items`
--
ALTER TABLE `purchase_return_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_return` (`purchase_return_id`),
  ADD KEY `idx_invoice_item` (`purchase_invoice_item_id`),
  ADD KEY `idx_variant` (`variant_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplier_code` (`supplier_code`),
  ADD KEY `idx_supplier_code` (`supplier_code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_supplier_type` (`supplier_type`);

--
-- Indexes for table `supplier_ledger`
--
ALTER TABLE `supplier_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_supplier` (`supplier_id`),
  ADD KEY `idx_transaction_date` (`transaction_date`),
  ADD KEY `idx_transaction_type` (`transaction_type`),
  ADD KEY `idx_reference` (`reference_type`,`reference_id`);

--
-- Indexes for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_number` (`payment_number`),
  ADD UNIQUE KEY `idx_payment_number` (`payment_number`),
  ADD KEY `idx_supplier` (`supplier_id`),
  ADD KEY `idx_branch` (`branch_id`),
  ADD KEY `idx_payment_date` (`payment_date`),
  ADD KEY `idx_payment_account` (`payment_account_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_journal_entry` (`journal_entry_id`);

--
-- Indexes for table `supplier_payment_allocations`
--
ALTER TABLE `supplier_payment_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payment` (`supplier_payment_id`),
  ADD KEY `idx_invoice` (`purchase_invoice_id`);

--
-- Indexes for table `transaction_lines`
--
ALTER TABLE `transaction_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `journal_entry_id` (`journal_entry_id`),
  ADD KEY `account_id` (`account_id`);

--
-- Indexes for table `transport_expenses`
--
ALTER TABLE `transport_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vehicle_id` (`vehicle_id`),
  ADD KEY `idx_trip_id` (`trip_id`),
  ADD KEY `idx_expense_date` (`expense_date`);

--
-- Indexes for table `trip_assignments`
--
ALTER TABLE `trip_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_trip_date` (`trip_date`),
  ADD KEY `idx_vehicle_id` (`vehicle_id`),
  ADD KEY `idx_driver_id` (`driver_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_trip_type` (`trip_type`);

--
-- Indexes for table `trip_consolidation_suggestions`
--
ALTER TABLE `trip_consolidation_suggestions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `suggested_vehicle_id` (`suggested_vehicle_id`),
  ADD KEY `idx_base_order` (`base_order_id`),
  ADD KEY `idx_suggested_order` (`suggested_order_id`),
  ADD KEY `idx_status` (`suggestion_status`);

--
-- Indexes for table `trip_order_assignments`
--
ALTER TABLE `trip_order_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_trip_order` (`trip_id`,`order_id`),
  ADD KEY `idx_trip_id` (`trip_id`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_delivery_status` (`delivery_status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uuid` (`uuid`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `email_index` (`email`);

--
-- Indexes for table `user_dashboard_preferences`
--
ALTER TABLE `user_dashboard_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_widget` (`user_id`,`widget_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `fk_user_preferences_widget` (`widget_id`),
  ADD KEY `idx_user_widget_lookup` (`user_id`,`is_enabled`,`position`);

--
-- Indexes for table `user_preference_audit`
--
ALTER TABLE `user_preference_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_audit` (`user_id`,`created_at`),
  ADD KEY `idx_changed_by` (`changed_by_user_id`,`created_at`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vehicle_number` (`vehicle_number`),
  ADD KEY `idx_vehicle_number` (`vehicle_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_capacity` (`capacity_kg`),
  ADD KEY `idx_assigned_branch` (`assigned_branch_id`);

--
-- Indexes for table `vehicle_documents`
--
ALTER TABLE `vehicle_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vehicle_id` (`vehicle_id`),
  ADD KEY `idx_expiry_date` (`expiry_date`);

--
-- Indexes for table `vehicle_logbook`
--
ALTER TABLE `vehicle_logbook`
  ADD PRIMARY KEY (`id`),
  ADD KEY `driver_id` (`driver_id`),
  ADD KEY `idx_vehicle_id` (`vehicle_id`),
  ADD KEY `idx_log_date` (`log_date`);

--
-- Indexes for table `vehicle_rentals`
--
ALTER TABLE `vehicle_rentals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `journal_entry_id` (`journal_entry_id`),
  ADD KEY `created_by_user_id` (`created_by_user_id`),
  ADD KEY `idx_vehicle_id` (`vehicle_id`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_start_datetime` (`start_datetime`),
  ADD KEY `idx_end_datetime` (`end_datetime`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `weight_variances_adnan`
--
ALTER TABLE `weight_variances_adnan`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_grn_id` (`grn_id`),
  ADD KEY `idx_po_id` (`purchase_order_id`),
  ADD KEY `idx_variance_type` (`variance_type`);

--
-- Indexes for table `wheat_alerts`
--
ALTER TABLE `wheat_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shipment` (`shipment_id`),
  ADD KEY `idx_is_read` (`is_read`);

--
-- Indexes for table `wheat_api_cache`
--
ALTER TABLE `wheat_api_cache`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cache_key` (`cache_key`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `wheat_market_data`
--
ALTER TABLE `wheat_market_data`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_source_country_year` (`data_source`,`country_code`,`year`),
  ADD KEY `idx_year_month` (`year`,`month`);

--
-- Indexes for table `wheat_shipments`
--
ALTER TABLE `wheat_shipments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `shipment_number` (`shipment_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_expected_arrival` (`expected_arrival`),
  ADD KEY `idx_branch` (`branch_id`),
  ADD KEY `idx_departure_date` (`departure_date`);

--
-- Indexes for table `wheat_shipment_positions`
--
ALTER TABLE `wheat_shipment_positions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shipment` (`shipment_id`),
  ADD KEY `idx_recorded_at` (`recorded_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bank_accounts`
--
ALTER TABLE `bank_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `branch_petty_cash_accounts`
--
ALTER TABLE `branch_petty_cash_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `branch_petty_cash_transactions`
--
ALTER TABLE `branch_petty_cash_transactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `cash_verification_log`
--
ALTER TABLE `cash_verification_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT for table `credit_orders`
--
ALTER TABLE `credit_orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `credit_order_audit`
--
ALTER TABLE `credit_order_audit`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=214;

--
-- AUTO_INCREMENT for table `credit_order_items`
--
ALTER TABLE `credit_order_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `credit_order_shipping`
--
ALTER TABLE `credit_order_shipping`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `credit_order_workflow`
--
ALTER TABLE `credit_order_workflow`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=141;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `customer_ledger`
--
ALTER TABLE `customer_ledger`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `customer_payments`
--
ALTER TABLE `customer_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `dashboard_widgets`
--
ALTER TABLE `dashboard_widgets`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `debit_vouchers`
--
ALTER TABLE `debit_vouchers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `drivers`
--
ALTER TABLE `drivers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `driver_attendance`
--
ALTER TABLE `driver_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `driver_documents`
--
ALTER TABLE `driver_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `eod_audit_trail`
--
ALTER TABLE `eod_audit_trail`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `eod_summary`
--
ALTER TABLE `eod_summary`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `fuel_logs`
--
ALTER TABLE `fuel_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `goods_received_adnan`
--
ALTER TABLE `goods_received_adnan`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `goods_received_items`
--
ALTER TABLE `goods_received_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `goods_received_notes`
--
ALTER TABLE `goods_received_notes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=82;

--
-- AUTO_INCREMENT for table `maintenance_logs`
--
ALTER TABLE `maintenance_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `payment_allocations`
--
ALTER TABLE `payment_allocations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `petty_cash_accounts`
--
ALTER TABLE `petty_cash_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `positions`
--
ALTER TABLE `positions`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `production_schedule`
--
ALTER TABLE `production_schedule`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `product_prices`
--
ALTER TABLE `product_prices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `product_variants`
--
ALTER TABLE `product_variants`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `purchase_invoices`
--
ALTER TABLE `purchase_invoices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_invoice_items`
--
ALTER TABLE `purchase_invoice_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_orders_adnan`
--
ALTER TABLE `purchase_orders_adnan`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_payments_adnan`
--
ALTER TABLE `purchase_payments_adnan`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_return_items`
--
ALTER TABLE `purchase_return_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `supplier_ledger`
--
ALTER TABLE `supplier_ledger`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_payment_allocations`
--
ALTER TABLE `supplier_payment_allocations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transaction_lines`
--
ALTER TABLE `transaction_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transport_expenses`
--
ALTER TABLE `transport_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `trip_assignments`
--
ALTER TABLE `trip_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `trip_consolidation_suggestions`
--
ALTER TABLE `trip_consolidation_suggestions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `trip_order_assignments`
--
ALTER TABLE `trip_order_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `user_dashboard_preferences`
--
ALTER TABLE `user_dashboard_preferences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=132;

--
-- AUTO_INCREMENT for table `user_preference_audit`
--
ALTER TABLE `user_preference_audit`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `vehicle_documents`
--
ALTER TABLE `vehicle_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vehicle_logbook`
--
ALTER TABLE `vehicle_logbook`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vehicle_rentals`
--
ALTER TABLE `vehicle_rentals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `weight_variances_adnan`
--
ALTER TABLE `weight_variances_adnan`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `wheat_alerts`
--
ALTER TABLE `wheat_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wheat_api_cache`
--
ALTER TABLE `wheat_api_cache`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wheat_market_data`
--
ALTER TABLE `wheat_market_data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wheat_shipments`
--
ALTER TABLE `wheat_shipments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `wheat_shipment_positions`
--
ALTER TABLE `wheat_shipment_positions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Structure for view `customer_outstanding_summary`
--
DROP TABLE IF EXISTS `customer_outstanding_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`ujjalfmc`@`localhost` SQL SECURITY DEFINER VIEW `customer_outstanding_summary`  AS SELECT `c`.`id` AS `id`, `c`.`name` AS `name`, `c`.`phone_number` AS `phone_number`, `c`.`current_balance` AS `current_balance`, `c`.`credit_limit` AS `credit_limit`, count(distinct `co`.`id`) AS `total_orders`, count(distinct case when `co`.`balance_due` > `co`.`amount_paid` then `co`.`id` end) AS `unpaid_invoices`, sum(case when `co`.`status` in ('shipped','delivered') then `co`.`balance_due` else 0 end) AS `total_invoiced`, sum(case when `co`.`status` in ('shipped','delivered') then `co`.`amount_paid` else 0 end) AS `total_paid`, sum(case when `co`.`status` in ('shipped','delivered') then `co`.`balance_due` - `co`.`amount_paid` else 0 end) AS `total_outstanding` FROM (`customers` `c` left join `credit_orders` `co` on(`c`.`id` = `co`.`customer_id`)) GROUP BY `c`.`id` ;

-- --------------------------------------------------------

--
-- Structure for view `vw_wheat_shipment_summary`
--
DROP TABLE IF EXISTS `vw_wheat_shipment_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`ujjalfmc`@`localhost` SQL SECURITY DEFINER VIEW `vw_wheat_shipment_summary`  AS SELECT `wheat_shipments`.`status` AS `status`, count(0) AS `shipment_count`, sum(`wheat_shipments`.`quantity_tons`) AS `total_tons`, sum(`wheat_shipments`.`total_cost`) AS `total_cost`, min(`wheat_shipments`.`expected_arrival`) AS `next_arrival`, max(`wheat_shipments`.`departure_date`) AS `latest_departure` FROM `wheat_shipments` WHERE `wheat_shipments`.`status` in ('Scheduled','In Transit','Arrived','Unloading') GROUP BY `wheat_shipments`.`status` ;

-- --------------------------------------------------------

--
-- Structure for view `v_customer_outstanding`
--
DROP TABLE IF EXISTS `v_customer_outstanding`;

CREATE ALGORITHM=UNDEFINED DEFINER=`ujjalfmc`@`localhost` SQL SECURITY DEFINER VIEW `v_customer_outstanding`  AS SELECT `c`.`id` AS `id`, `c`.`name` AS `name`, `c`.`phone_number` AS `phone_number`, `c`.`current_balance` AS `current_balance`, `c`.`credit_limit` AS `credit_limit`, count(distinct `co`.`id`) AS `total_orders`, count(distinct case when `co`.`total_amount` - `co`.`amount_paid` > 0 then `co`.`id` end) AS `unpaid_invoices`, sum(case when `co`.`status` in ('shipped','delivered') then `co`.`total_amount` else 0 end) AS `total_invoiced`, sum(case when `co`.`status` in ('shipped','delivered') then `co`.`amount_paid` else 0 end) AS `total_paid`, sum(case when `co`.`status` in ('shipped','delivered') then `co`.`total_amount` - `co`.`amount_paid` else 0 end) AS `total_outstanding` FROM (`customers` `c` left join `credit_orders` `co` on(`c`.`id` = `co`.`customer_id`)) GROUP BY `c`.`id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_order_audit_history`
--
DROP TABLE IF EXISTS `v_order_audit_history`;

CREATE ALGORITHM=UNDEFINED DEFINER=`ujjalfmc`@`localhost` SQL SECURITY DEFINER VIEW `v_order_audit_history`  AS SELECT `coa`.`id` AS `id`, `coa`.`order_id` AS `order_id`, `co`.`order_number` AS `order_number`, `coa`.`action_type` AS `action_type`, `coa`.`field_name` AS `field_name`, `coa`.`old_value` AS `old_value`, `coa`.`new_value` AS `new_value`, `u`.`display_name` AS `changed_by`, `u`.`role` AS `user_role`, `coa`.`created_at` AS `changed_at`, `coa`.`notes` AS `notes` FROM ((`credit_order_audit` `coa` join `credit_orders` `co` on(`coa`.`order_id` = `co`.`id`)) join `users` `u` on(`coa`.`user_id` = `u`.`id`)) ORDER BY `coa`.`created_at` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `v_payment_allocations_detail`
--
DROP TABLE IF EXISTS `v_payment_allocations_detail`;

CREATE ALGORITHM=UNDEFINED DEFINER=`ujjalfmc`@`localhost` SQL SECURITY DEFINER VIEW `v_payment_allocations_detail`  AS SELECT `pa`.`id` AS `id`, `pa`.`payment_id` AS `payment_id`, `cp`.`receipt_number` AS `receipt_number`, `cp`.`payment_date` AS `payment_date`, `cp`.`amount` AS `payment_amount`, `cp`.`payment_method` AS `payment_method`, `c`.`name` AS `customer_name`, `pa`.`order_id` AS `order_id`, `co`.`order_number` AS `order_number`, `co`.`total_amount` AS `invoice_amount`, `pa`.`allocated_amount` AS `allocated_amount`, `u`.`display_name` AS `allocated_by` FROM ((((`payment_allocations` `pa` join `customer_payments` `cp` on(`pa`.`payment_id` = `cp`.`id`)) join `customers` `c` on(`cp`.`customer_id` = `c`.`id`)) join `credit_orders` `co` on(`pa`.`order_id` = `co`.`id`)) left join `users` `u` on(`pa`.`allocated_by_user_id` = `u`.`id`)) ORDER BY `pa`.`created_at` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `v_payment_summary_by_date`
--
DROP TABLE IF EXISTS `v_payment_summary_by_date`;

CREATE ALGORITHM=UNDEFINED DEFINER=`ujjalfmc`@`localhost` SQL SECURITY DEFINER VIEW `v_payment_summary_by_date`  AS SELECT `customer_payments`.`payment_date` AS `payment_date`, `customer_payments`.`payment_method` AS `payment_method`, count(0) AS `num_payments`, sum(`customer_payments`.`amount`) AS `total_collected`, avg(`customer_payments`.`amount`) AS `avg_payment`, sum(case when `customer_payments`.`allocation_status` = 'allocated' then `customer_payments`.`amount` else 0 end) AS `allocated_amount`, sum(case when `customer_payments`.`allocation_status` = 'unallocated' then `customer_payments`.`amount` else 0 end) AS `unallocated_amount` FROM `customer_payments` GROUP BY `customer_payments`.`payment_date`, `customer_payments`.`payment_method` ORDER BY `customer_payments`.`payment_date` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `v_petty_cash_accounts`
--
DROP TABLE IF EXISTS `v_petty_cash_accounts`;

CREATE ALGORITHM=UNDEFINED DEFINER=`ujjalfmc`@`localhost` SQL SECURITY DEFINER VIEW `v_petty_cash_accounts`  AS SELECT `coa`.`id` AS `coa_id`, `coa`.`name` AS `account_name`, `coa`.`branch_id` AS `branch_id`, `b`.`name` AS `branch_name`, `pc`.`id` AS `petty_cash_account_id`, `pc`.`current_balance` AS `current_balance`, `pc`.`opening_balance` AS `opening_balance`, `pc`.`status` AS `status` FROM ((`chart_of_accounts` `coa` left join `branches` `b` on(`coa`.`branch_id` = `b`.`id`)) left join `branch_petty_cash_accounts` `pc` on(`coa`.`branch_id` = `pc`.`branch_id`)) WHERE `coa`.`account_type` in ('Petty Cash','Cash') AND `coa`.`status` = 'active' ;

-- --------------------------------------------------------

--
-- Structure for view `v_purchase_adnan_dashboard`
--
DROP TABLE IF EXISTS `v_purchase_adnan_dashboard`;

CREATE ALGORITHM=UNDEFINED DEFINER=`ujjalfmc`@`localhost` SQL SECURITY DEFINER VIEW `v_purchase_adnan_dashboard`  AS SELECT `po`.`id` AS `id`, `po`.`po_number` AS `po_number`, `po`.`po_date` AS `po_date`, `po`.`supplier_name` AS `supplier_name`, `po`.`wheat_origin` AS `wheat_origin`, `po`.`quantity_kg` AS `quantity_kg`, `po`.`unit_price_per_kg` AS `unit_price_per_kg`, `po`.`total_order_value` AS `total_order_value`, `po`.`total_received_qty` AS `total_received_qty`, `po`.`qty_yet_to_receive` AS `qty_yet_to_receive`, `po`.`total_received_value` AS `total_received_value`, `po`.`total_paid` AS `total_paid`, `po`.`balance_payable` AS `balance_payable`, `po`.`delivery_status` AS `delivery_status`, `po`.`payment_status` AS `payment_status`, count(distinct `grn`.`id`) AS `grn_count`, count(distinct `pmt`.`id`) AS `payment_count`, to_days(curdate()) - to_days(`po`.`po_date`) AS `days_since_order` FROM ((`purchase_orders_adnan` `po` left join `goods_received_adnan` `grn` on(`po`.`id` = `grn`.`purchase_order_id` and `grn`.`grn_status` <> 'cancelled')) left join `purchase_payments_adnan` `pmt` on(`po`.`id` = `pmt`.`purchase_order_id`)) WHERE `po`.`po_status` <> 'cancelled' GROUP BY `po`.`id` ORDER BY `po`.`po_date` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `v_purchase_adnan_supplier_summary`
--
DROP TABLE IF EXISTS `v_purchase_adnan_supplier_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`ujjalfmc`@`localhost` SQL SECURITY DEFINER VIEW `v_purchase_adnan_supplier_summary`  AS SELECT `s`.`id` AS `supplier_id`, `s`.`company_name` AS `supplier_name`, count(distinct `po`.`id`) AS `total_orders`, ifnull(sum(`po`.`total_order_value`),0) AS `total_ordered_value`, ifnull(sum(`po`.`total_received_value`),0) AS `total_received_value`, ifnull(sum(`po`.`total_paid`),0) AS `total_paid`, ifnull(sum(`po`.`balance_payable`),0) AS `balance_payable`, max(`po`.`po_date`) AS `last_order_date` FROM (`suppliers` `s` left join `purchase_orders_adnan` `po` on(`s`.`id` = `po`.`supplier_id` and `po`.`po_status` <> 'cancelled')) GROUP BY `s`.`id`, `s`.`company_name` HAVING `total_orders` > 0 ORDER BY ifnull(sum(`po`.`balance_payable`),0) DESC ;

-- --------------------------------------------------------

--
-- Structure for view `v_purchase_adnan_variance_analysis`
--
DROP TABLE IF EXISTS `v_purchase_adnan_variance_analysis`;

CREATE ALGORITHM=UNDEFINED DEFINER=`ujjalfmc`@`localhost` SQL SECURITY DEFINER VIEW `v_purchase_adnan_variance_analysis`  AS SELECT `wv`.`id` AS `id`, `po`.`po_number` AS `po_number`, `po`.`supplier_name` AS `supplier_name`, `grn`.`grn_number` AS `grn_number`, `grn`.`truck_number` AS `truck_number`, `grn`.`grn_date` AS `grn_date`, `wv`.`ordered_quantity` AS `ordered_quantity`, `wv`.`received_quantity` AS `received_quantity`, `wv`.`variance` AS `variance`, `wv`.`variance_percentage` AS `variance_percentage`, `wv`.`variance_type` AS `variance_type`, `wv`.`variance_value` AS `variance_value`, `wv`.`remarks` AS `remarks` FROM ((`weight_variances_adnan` `wv` join `purchase_orders_adnan` `po` on(`wv`.`purchase_order_id` = `po`.`id`)) join `goods_received_adnan` `grn` on(`wv`.`grn_id` = `grn`.`id`)) ORDER BY abs(`wv`.`variance_percentage`) DESC ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bank_accounts`
--
ALTER TABLE `bank_accounts`
  ADD CONSTRAINT `fk_bank_to_chart` FOREIGN KEY (`chart_of_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `credit_order_audit`
--
ALTER TABLE `credit_order_audit`
  ADD CONSTRAINT `credit_order_audit_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `credit_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `credit_order_audit_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `credit_order_items`
--
ALTER TABLE `credit_order_items`
  ADD CONSTRAINT `fk_credit_order_items` FOREIGN KEY (`order_id`) REFERENCES `credit_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `credit_order_shipping`
--
ALTER TABLE `credit_order_shipping`
  ADD CONSTRAINT `fk_shipping` FOREIGN KEY (`order_id`) REFERENCES `credit_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `credit_order_workflow`
--
ALTER TABLE `credit_order_workflow`
  ADD CONSTRAINT `fk_workflow` FOREIGN KEY (`order_id`) REFERENCES `credit_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `drivers`
--
ALTER TABLE `drivers`
  ADD CONSTRAINT `drivers_ibfk_1` FOREIGN KEY (`assigned_vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `driver_attendance`
--
ALTER TABLE `driver_attendance`
  ADD CONSTRAINT `driver_attendance_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `driver_documents`
--
ALTER TABLE `driver_documents`
  ADD CONSTRAINT `driver_documents_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`);

--
-- Constraints for table `fuel_logs`
--
ALTER TABLE `fuel_logs`
  ADD CONSTRAINT `fuel_logs_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fuel_logs_ibfk_2` FOREIGN KEY (`trip_id`) REFERENCES `trip_assignments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `goods_received_adnan`
--
ALTER TABLE `goods_received_adnan`
  ADD CONSTRAINT `fk_grn_adnan_journal` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_grn_adnan_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders_adnan` (`id`),
  ADD CONSTRAINT `fk_grn_adnan_receiver` FOREIGN KEY (`receiver_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_grn_adnan_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `goods_received_items`
--
ALTER TABLE `goods_received_items`
  ADD CONSTRAINT `fk_gri_grn` FOREIGN KEY (`grn_id`) REFERENCES `goods_received_notes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_gri_po_item` FOREIGN KEY (`po_item_id`) REFERENCES `purchase_order_items` (`id`),
  ADD CONSTRAINT `fk_gri_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`);

--
-- Constraints for table `goods_received_notes`
--
ALTER TABLE `goods_received_notes`
  ADD CONSTRAINT `fk_grn_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_grn_purchase_order` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`),
  ADD CONSTRAINT `fk_grn_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `fk_inventory_to_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_inventory_to_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD CONSTRAINT `fk_journal_to_employee` FOREIGN KEY (`responsible_employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_journal_to_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION;

--
-- Constraints for table `maintenance_logs`
--
ALTER TABLE `maintenance_logs`
  ADD CONSTRAINT `maintenance_logs_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_order_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_order_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_order_journal` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_order_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_orderitem_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_orderitem_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`);

--
-- Constraints for table `petty_cash_accounts`
--
ALTER TABLE `petty_cash_accounts`
  ADD CONSTRAINT `fk_petty_cash_to_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `positions`
--
ALTER TABLE `positions`
  ADD CONSTRAINT `fk_position_to_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `production_schedule`
--
ALTER TABLE `production_schedule`
  ADD CONSTRAINT `fk_prod_order` FOREIGN KEY (`order_id`) REFERENCES `credit_orders` (`id`);

--
-- Constraints for table `product_prices`
--
ALTER TABLE `product_prices`
  ADD CONSTRAINT `fk_prices_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_prices_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_variants`
--
ALTER TABLE `product_variants`
  ADD CONSTRAINT `fk_variant_to_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_invoices`
--
ALTER TABLE `purchase_invoices`
  ADD CONSTRAINT `fk_pi_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_pi_grn` FOREIGN KEY (`grn_id`) REFERENCES `goods_received_notes` (`id`),
  ADD CONSTRAINT `fk_pi_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`),
  ADD CONSTRAINT `fk_pi_purchase_order` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`),
  ADD CONSTRAINT `fk_pi_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `purchase_invoice_items`
--
ALTER TABLE `purchase_invoice_items`
  ADD CONSTRAINT `fk_pii_account` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`),
  ADD CONSTRAINT `fk_pii_grn_item` FOREIGN KEY (`grn_item_id`) REFERENCES `goods_received_items` (`id`),
  ADD CONSTRAINT `fk_pii_po_item` FOREIGN KEY (`po_item_id`) REFERENCES `purchase_order_items` (`id`),
  ADD CONSTRAINT `fk_pii_purchase_invoice` FOREIGN KEY (`purchase_invoice_id`) REFERENCES `purchase_invoices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pii_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`);

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `fk_po_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `purchase_orders_adnan`
--
ALTER TABLE `purchase_orders_adnan`
  ADD CONSTRAINT `fk_poa_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_poa_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD CONSTRAINT `fk_poi_purchase_order` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_poi_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`);

--
-- Constraints for table `purchase_payments_adnan`
--
ALTER TABLE `purchase_payments_adnan`
  ADD CONSTRAINT `fk_payment_adnan_bank` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`),
  ADD CONSTRAINT `fk_payment_adnan_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_payment_adnan_journal` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_payment_adnan_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders_adnan` (`id`),
  ADD CONSTRAINT `fk_payment_adnan_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
  ADD CONSTRAINT `fk_pr_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_pr_grn` FOREIGN KEY (`grn_id`) REFERENCES `goods_received_notes` (`id`),
  ADD CONSTRAINT `fk_pr_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`),
  ADD CONSTRAINT `fk_pr_purchase_invoice` FOREIGN KEY (`purchase_invoice_id`) REFERENCES `purchase_invoices` (`id`),
  ADD CONSTRAINT `fk_pr_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `purchase_return_items`
--
ALTER TABLE `purchase_return_items`
  ADD CONSTRAINT `fk_pri_invoice_item` FOREIGN KEY (`purchase_invoice_item_id`) REFERENCES `purchase_invoice_items` (`id`),
  ADD CONSTRAINT `fk_pri_purchase_return` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pri_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`);

--
-- Constraints for table `supplier_ledger`
--
ALTER TABLE `supplier_ledger`
  ADD CONSTRAINT `fk_sl_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD CONSTRAINT `fk_sp_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_sp_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`),
  ADD CONSTRAINT `fk_sp_payment_account` FOREIGN KEY (`payment_account_id`) REFERENCES `chart_of_accounts` (`id`),
  ADD CONSTRAINT `fk_sp_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `supplier_payment_allocations`
--
ALTER TABLE `supplier_payment_allocations`
  ADD CONSTRAINT `fk_spa_invoice` FOREIGN KEY (`purchase_invoice_id`) REFERENCES `purchase_invoices` (`id`),
  ADD CONSTRAINT `fk_spa_payment` FOREIGN KEY (`supplier_payment_id`) REFERENCES `supplier_payments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `transaction_lines`
--
ALTER TABLE `transaction_lines`
  ADD CONSTRAINT `fk_lines_to_account` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`),
  ADD CONSTRAINT `fk_lines_to_journal` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `transport_expenses`
--
ALTER TABLE `transport_expenses`
  ADD CONSTRAINT `transport_expenses_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transport_expenses_ibfk_2` FOREIGN KEY (`trip_id`) REFERENCES `trip_assignments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `trip_assignments`
--
ALTER TABLE `trip_assignments`
  ADD CONSTRAINT `trip_assignments_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  ADD CONSTRAINT `trip_assignments_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`);

--
-- Constraints for table `trip_consolidation_suggestions`
--
ALTER TABLE `trip_consolidation_suggestions`
  ADD CONSTRAINT `trip_consolidation_suggestions_ibfk_1` FOREIGN KEY (`base_order_id`) REFERENCES `credit_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `trip_consolidation_suggestions_ibfk_2` FOREIGN KEY (`suggested_order_id`) REFERENCES `credit_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `trip_consolidation_suggestions_ibfk_3` FOREIGN KEY (`suggested_vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `trip_order_assignments`
--
ALTER TABLE `trip_order_assignments`
  ADD CONSTRAINT `trip_order_assignments_ibfk_1` FOREIGN KEY (`trip_id`) REFERENCES `trip_assignments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `trip_order_assignments_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `credit_orders` (`id`);

--
-- Constraints for table `user_dashboard_preferences`
--
ALTER TABLE `user_dashboard_preferences`
  ADD CONSTRAINT `fk_user_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_preferences_widget` FOREIGN KEY (`widget_id`) REFERENCES `dashboard_widgets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_preference_audit`
--
ALTER TABLE `user_preference_audit`
  ADD CONSTRAINT `user_preference_audit_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_preference_audit_ibfk_2` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicle_documents`
--
ALTER TABLE `vehicle_documents`
  ADD CONSTRAINT `vehicle_documents_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`);

--
-- Constraints for table `vehicle_logbook`
--
ALTER TABLE `vehicle_logbook`
  ADD CONSTRAINT `vehicle_logbook_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `vehicle_logbook_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `vehicle_rentals`
--
ALTER TABLE `vehicle_rentals`
  ADD CONSTRAINT `vehicle_rentals_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  ADD CONSTRAINT `vehicle_rentals_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `vehicle_rentals_ibfk_3` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `vehicle_rentals_ibfk_4` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `weight_variances_adnan`
--
ALTER TABLE `weight_variances_adnan`
  ADD CONSTRAINT `fk_variance_grn` FOREIGN KEY (`grn_id`) REFERENCES `goods_received_adnan` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_variance_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders_adnan` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `wheat_alerts`
--
ALTER TABLE `wheat_alerts`
  ADD CONSTRAINT `fk_alert_shipment` FOREIGN KEY (`shipment_id`) REFERENCES `wheat_shipments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `wheat_shipment_positions`
--
ALTER TABLE `wheat_shipment_positions`
  ADD CONSTRAINT `fk_position_shipment` FOREIGN KEY (`shipment_id`) REFERENCES `wheat_shipments` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

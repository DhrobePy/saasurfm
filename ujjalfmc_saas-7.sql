-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Oct 29, 2025 at 10:22 PM
-- Server version: 11.4.8-MariaDB
-- PHP Version: 8.4.13

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
(1, 12, 'f2afa997-b013-11f0-9003-10ffe0a28e39', 'ucbl', 'kamarpara', 'ujjal flour mills', '1662301000000228', 'Checking', 'kamhgfhf', 1800000.00, 1800000.00, 'active', '2025-10-23 13:26:57', '2025-10-23 13:26:57'),
(2, 13, '91eef8a6-b016-11f0-9003-10ffe0a28e39', 'UCBL', 'Kafrul Branch', 'Ujjal Flour Mills', '2122101000000123', 'Checking', 'Kafrul, Dhaka', 0.00, 0.00, 'active', '2025-10-23 13:45:43', '2025-10-23 13:45:43');

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
(1, 1, 'Petty Cash - Sirajgonj', 360.00, 0.00, '2025-10-27', 'active', '2025-10-27 06:18:35', '2025-10-27 06:47:25'),
(2, 2, 'Petty Cash - Demra', 0.00, 0.00, '2025-10-27', 'active', '2025-10-27 06:18:35', '2025-10-27 06:18:35'),
(3, 3, 'Petty Cash - Rampura', 0.00, 0.00, '2025-10-27', 'active', '2025-10-27 06:18:35', '2025-10-27 06:18:35'),
(4, 4, 'Petty Cash - Head Office', 0.00, 0.00, '2025-10-27', 'active', '2025-10-27 06:18:35', '2025-10-27 06:18:35');

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
(2, 1, 1, '2025-10-27 12:47:25', 'transfer_out', 3000.00, 360.00, 'internal_transfer', 11, 'Transfer out: Internal Transfer: POS Sales Sirajgonj to ucbl - ujjal flour mills (1662301000000228) - trial cash transfer', 'Cash', 3, NULL, NULL, '2025-10-27 06:47:25');

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
(30, NULL, 'Other Current Asset', NULL, 'Asset', 'Debit', 'active', 'Payments received via card, bank transfer, or mobile banking that have not yet been deposited to bank account', 1, '2025-10-25 15:28:20', 'Undeposited Funds');

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
  `status` enum('draft','pending_approval','approved','escalated','rejected','in_production','produced','ready_to_ship','shipped','delivered','cancelled') NOT NULL DEFAULT 'draft',
  `assigned_branch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `approved_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `shipping_address` text DEFAULT NULL,
  `special_instructions` text DEFAULT NULL,
  `internal_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `credit_orders`
--

INSERT INTO `credit_orders` (`id`, `order_number`, `customer_id`, `order_date`, `required_date`, `order_type`, `subtotal`, `discount_amount`, `tax_amount`, `total_amount`, `advance_paid`, `balance_due`, `status`, `assigned_branch_id`, `priority`, `created_by_user_id`, `approved_by_user_id`, `approved_at`, `shipping_address`, `special_instructions`, `internal_notes`, `created_at`, `updated_at`) VALUES
(1, 'CR-20251028-6054', 1, '2025-10-28', '2025-11-04', 'credit', 34014.75, 0.00, 0.00, 34014.75, 0.00, 34014.75, 'delivered', 2, 'normal', 2, NULL, NULL, 'fgchgfg', 'hfjhg', NULL, '2025-10-27 18:01:24', '2025-10-28 11:15:13'),
(2, 'CR-20251028-9123', 1, '2025-10-28', '2025-10-29', 'credit', 34100.00, 0.00, 0.00, 34100.00, 0.00, 34100.00, 'ready_to_ship', 2, 'normal', 6, NULL, NULL, 'Puran Dhaka', '', NULL, '2025-10-28 11:31:35', '2025-10-28 11:34:21'),
(3, 'CR-20251028-6227', 1, '2025-10-28', '2025-10-30', 'credit', 2046000.00, 0.00, 0.00, 2046000.00, 0.00, 2046000.00, 'approved', 2, 'normal', 6, NULL, NULL, 'Old Dhaka', '', NULL, '2025-10-28 11:46:01', '2025-10-28 11:47:41');

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `credit_order_items`
--

INSERT INTO `credit_order_items` (`id`, `order_id`, `product_id`, `variant_id`, `quantity`, `unit_price`, `discount_amount`, `tax_amount`, `line_total`, `notes`, `created_at`) VALUES
(1, 1, 1, 2, 10.00, 3410.00, 85.25, 0.00, 34014.75, NULL, '2025-10-27 18:01:24'),
(2, 2, 1, 2, 10.00, 3410.00, 0.00, 0.00, 34100.00, NULL, '2025-10-28 11:31:35'),
(3, 3, 1, 2, 100.00, 3410.00, 0.00, 0.00, 341000.00, NULL, '2025-10-28 11:46:01'),
(4, 3, 1, 2, 500.00, 3410.00, 0.00, 0.00, 1705000.00, NULL, '2025-10-28 11:46:01');

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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `credit_order_shipping`
--

INSERT INTO `credit_order_shipping` (`id`, `order_id`, `truck_number`, `driver_name`, `driver_contact`, `shipped_date`, `delivered_date`, `shipped_by_user_id`, `delivered_by_user_id`, `delivery_notes`, `created_at`, `updated_at`) VALUES
(1, 1, 'Dhaka Metro- Ga -21-3776', 'Rubel Driver', '01912071977', '2025-10-28 16:20:57', '2025-10-28 17:15:13', 5, 5, 'Delivered and received by Mr. Hashem, Son of Kashem', '2025-10-28 10:20:57', '2025-10-28 11:15:13');

-- --------------------------------------------------------

--
-- Table structure for table `credit_order_workflow`
--

CREATE TABLE `credit_order_workflow` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `from_status` varchar(50) NOT NULL,
  `to_status` varchar(50) NOT NULL,
  `action` enum('submit','approve','reject','escalate','assign','start_production','complete_production','ship','deliver','cancel','modify','hold') NOT NULL,
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
(11, 3, 'draft', 'pending_approval', 'submit', 6, 'Order created and submitted for approval', NULL, NULL, '2025-10-28 11:46:01');

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
(1, '06721afd-af53-11f0-9003-10ffe0a28e39', 'Credit', 'Hazi Abul Kashem', 'Kashem & Sons', '01912071977', 'kashemandsons@gmail.com', 'Old Chaul Potti, Demra , Dhaka', 'uploads/profiles/customer_1761143157_IMG_5782.jpeg', 1000000.00, 220000.00, 254014.75, 'active', '2025-10-22 14:25:57', '2025-10-28 10:20:57'),
(2, 'e0edc339-b05c-11f0-9003-10ffe0a28e39', 'POS', 'Pos Trial', 'postrial ent', '87786865769', 'hgjjh@gmail.com', 'ghffyu', NULL, 0.00, 0.00, 0.00, 'active', '2025-10-23 22:09:00', '2025-10-23 22:09:00'),
(3, '9c9e1e17-b05d-11f0-9003-10ffe0a28e39', 'POS', 'Walk-in Customer', NULL, '01700000001', NULL, NULL, NULL, 0.00, 0.00, 0.00, 'active', '2025-10-23 22:14:15', '2025-10-23 22:14:15');

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
(1, 1, '2025-10-28', 'invoice', 'credit_orders', 1, 'CR-20251028-6054', 'Credit sale - Invoice #CR-20251028-6054', 34014.75, 0.00, 34014.75, 5, NULL, '2025-10-28 10:20:57');

-- --------------------------------------------------------

--
-- Table structure for table `customer_payments`
--

CREATE TABLE `customer_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `payment_number` varchar(50) NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `payment_amount` decimal(12,2) NOT NULL,
  `payment_method` enum('Cash','Bank Transfer','Cheque','Mobile Banking','Card') NOT NULL,
  `payment_type` enum('advance','invoice_payment','partial_payment') NOT NULL DEFAULT 'invoice_payment',
  `bank_account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `allocated_to_invoices` text DEFAULT NULL COMMENT 'JSON array',
  `created_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `journal_entry_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
(6, 6, 'Sales', 'Trial', 'sales_demra@ujjalfm.com', '23435652', 'Demra', 23, '2025-06-01', 10000.00, 'active', NULL, 2);

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
(1, 2, 1, 2482, '2025-10-27 06:45:52');

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
(11, 'cc2221c3-b300-11f0-9003-10ffe0a28e39', '2025-10-27', 'Internal Transfer: POS Sales Sirajgonj to ucbl - ujjal flour mills (1662301000000228) - trial cash transfer', NULL, 'InternalTransfer', 2, 3, '2025-10-27 06:47:25', '2025-10-27 06:47:25');

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
(18, '9473218e-b300-11f0-9003-10ffe0a28e39', 'ORD-20251027-0003', 1, NULL, '2025-10-27 12:45:52', 'POS', 3360.00, 0.00, 0.00, 'none', 0.00, 3360.00, 'Cash', NULL, NULL, 'Paid', 'Completed', NULL, 3, 10, 3, '2025-10-27 12:45:55', 1, 1, 1, '2025-10-27 06:45:52', '2025-10-27 06:45:55');

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
(8, 18, 2, 1, 3410.00, 'fixed', 50.00, 50.00, 3410.00, 50.00, 0.00, 3360.00, '2025-10-27 06:45:52');

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
(26, 8, 'Office Staff- Sirajgonj', NULL, '2025-10-23 13:53:10', '2025-10-23 13:53:10');

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
(1, '2565d047-af3e-11f0-9003-10ffe0a28e39', 'Jora Kabutor', 'JORAKOBUTOR', 'Special', 'Moyda', 'active', '2025-10-22 11:56:29', '2025-10-22 12:02:41');

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
(2, 2, 1, 3410.00, '2025-10-22', 'active', 1, '2025-10-22 12:42:36', '2025-10-22 12:42:36');

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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_variants`
--

INSERT INTO `product_variants` (`id`, `uuid`, `product_id`, `grade`, `weight_variant`, `unit_of_measure`, `sku`, `status`, `created_at`, `updated_at`) VALUES
(2, '36a77a07-af42-11f0-9003-10ffe0a28e39', 1, '1', '34', 'kg', 'JORAKOBUTOR-34-1', 'active', '2025-10-22 12:25:36', '2025-10-22 12:25:36');

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
(23, 11, 14, 0.00, 3000.00, 'trial cash transfer');

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
  `role` enum('Superadmin','admin','Accounts','accounts-rampura','accounts-srg','accounts-demra','accountspos-demra','accountspos-srg','production manager-srg','production manager-demra','dispatch-srg','dispatch-demra','dispatchpos-demra','dispatchpos-srg','sales-srg','sales-demra','sales-other','collector') NOT NULL,
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
(6, '8467ed8a-b3f1-11f0-9003-10ffe0a28e39', 'Sales Officer Demra', 'sales_demra@ujjalfm.com', '$2y$12$P9zYUS6FZI2BxuiqZre7b.xi/4w7MQ7Q2TOrJtdR02RuPW45AYJMa', 'sales-demra', 'active', NULL, NULL, '2025-10-28 11:30:33', '2025-10-28 11:30:33');

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
  ADD KEY `idx_branch` (`assigned_branch_id`);

--
-- Indexes for table `credit_order_items`
--
ALTER TABLE `credit_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order` (`order_id`);

--
-- Indexes for table `credit_order_shipping`
--
ALTER TABLE `credit_order_shipping`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_order_shipping` (`order_id`);

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
  ADD KEY `idx_customer` (`customer_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

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
-- Indexes for table `transaction_lines`
--
ALTER TABLE `transaction_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `journal_entry_id` (`journal_entry_id`),
  ADD KEY `account_id` (`account_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uuid` (`uuid`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `email_index` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bank_accounts`
--
ALTER TABLE `bank_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

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
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `cash_verification_log`
--
ALTER TABLE `cash_verification_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `credit_orders`
--
ALTER TABLE `credit_orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `credit_order_items`
--
ALTER TABLE `credit_order_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `credit_order_shipping`
--
ALTER TABLE `credit_order_shipping`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `credit_order_workflow`
--
ALTER TABLE `credit_order_workflow`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `customer_ledger`
--
ALTER TABLE `customer_ledger`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customer_payments`
--
ALTER TABLE `customer_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `eod_audit_trail`
--
ALTER TABLE `eod_audit_trail`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `eod_summary`
--
ALTER TABLE `eod_summary`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `petty_cash_accounts`
--
ALTER TABLE `petty_cash_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `positions`
--
ALTER TABLE `positions`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `production_schedule`
--
ALTER TABLE `production_schedule`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `product_prices`
--
ALTER TABLE `product_prices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `product_variants`
--
ALTER TABLE `product_variants`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `transaction_lines`
--
ALTER TABLE `transaction_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

-- --------------------------------------------------------

--
-- Structure for view `v_petty_cash_accounts`
--
DROP TABLE IF EXISTS `v_petty_cash_accounts`;

CREATE ALGORITHM=UNDEFINED DEFINER=`ujjalfmc`@`localhost` SQL SECURITY DEFINER VIEW `v_petty_cash_accounts`  AS SELECT `coa`.`id` AS `coa_id`, `coa`.`name` AS `account_name`, `coa`.`branch_id` AS `branch_id`, `b`.`name` AS `branch_name`, `pc`.`id` AS `petty_cash_account_id`, `pc`.`current_balance` AS `current_balance`, `pc`.`opening_balance` AS `opening_balance`, `pc`.`status` AS `status` FROM ((`chart_of_accounts` `coa` left join `branches` `b` on(`coa`.`branch_id` = `b`.`id`)) left join `branch_petty_cash_accounts` `pc` on(`coa`.`branch_id` = `pc`.`branch_id`)) WHERE `coa`.`account_type` in ('Petty Cash','Cash') AND `coa`.`status` = 'active' ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bank_accounts`
--
ALTER TABLE `bank_accounts`
  ADD CONSTRAINT `fk_bank_to_chart` FOREIGN KEY (`chart_of_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL;

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
-- Constraints for table `transaction_lines`
--
ALTER TABLE `transaction_lines`
  ADD CONSTRAINT `fk_lines_to_account` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`),
  ADD CONSTRAINT `fk_lines_to_journal` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

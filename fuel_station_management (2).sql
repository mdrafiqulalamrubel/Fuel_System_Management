-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 27, 2026 at 04:03 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `fuel_station_management`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `action`, `description`, `ip_address`, `created_at`) VALUES
(1, 1, 'login', 'User logged in', '::1', '2026-06-17 07:39:30'),
(2, 1, 'login', 'User logged in', '::1', '2026-06-17 09:09:16'),
(3, 1, 'login', 'User logged in', '::1', '2026-06-17 11:56:54'),
(4, 1, 'login', 'User logged in', '::1', '2026-06-17 12:01:51'),
(5, 1, 'login', 'User logged in', '::1', '2026-06-17 12:06:28'),
(6, 1, 'login', 'User logged in', '::1', '2026-06-17 14:12:11'),
(7, 1, 'login', 'User logged in', '::1', '2026-06-18 04:45:50'),
(8, 1, 'login', 'User logged in', '::1', '2026-06-20 06:40:30'),
(9, 1, 'login', 'User logged in', '::1', '2026-06-20 07:10:21'),
(10, 1, 'login', 'User logged in', '::1', '2026-06-20 07:11:31'),
(11, 1, 'login', 'User logged in', '::1', '2026-06-20 12:43:10'),
(12, 1, 'login', 'User logged in', '::1', '2026-06-21 04:25:31'),
(13, 1, 'login', 'User logged in', '::1', '2026-06-21 09:48:31'),
(14, 1, 'login', 'User logged in', '::1', '2026-06-21 09:48:50'),
(15, 1, 'login', 'User logged in', '::1', '2026-06-21 09:57:54'),
(16, 1, 'login', 'User logged in', '::1', '2026-06-21 13:47:51'),
(17, 1, 'login', 'User logged in', '::1', '2026-06-22 04:23:59'),
(18, 1, 'login', 'User logged in', '::1', '2026-06-22 09:36:13'),
(19, 1, 'login', 'User logged in', '::1', '2026-06-23 07:06:27'),
(20, 1, 'login', 'User logged in', '::1', '2026-06-23 09:29:10'),
(21, 1, 'login', 'User logged in', '::1', '2026-06-24 05:21:22'),
(22, 1, 'login', 'User logged in', '::1', '2026-06-24 07:10:50'),
(23, 1, 'login', 'User logged in', '::1', '2026-06-24 09:51:58'),
(24, 1, 'login', 'User logged in', '::1', '2026-06-24 11:37:26'),
(25, 1, 'login', 'User logged in', '::1', '2026-06-25 05:40:42'),
(26, 1, 'login', 'User logged in', '::1', '2026-06-27 05:18:37');

-- --------------------------------------------------------

--
-- Table structure for table `advance_adjustments`
--

CREATE TABLE `advance_adjustments` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `adjustment_date` date DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `adjustment_type` enum('debit','credit') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `advance_payments_customer`
--

CREATE TABLE `advance_payments_customer` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `advance_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` enum('cash','bank','cheque','mobile_banking') DEFAULT 'cash',
  `reference_no` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `used_amount` decimal(15,2) DEFAULT 0.00,
  `balance_amount` decimal(15,2) DEFAULT 0.00,
  `status` enum('active','fully_used','cancelled') DEFAULT 'active',
  `voucher_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `journal_voucher_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `advance_payments_customer`
--

INSERT INTO `advance_payments_customer` (`id`, `customer_id`, `advance_date`, `amount`, `payment_method`, `reference_no`, `notes`, `used_amount`, `balance_amount`, `status`, `voucher_id`, `created_by`, `created_at`, `journal_voucher_id`) VALUES
(1, 2, '2026-06-18', 10000.00, 'cash', 'PAY-20260618114052587', '', 0.00, 10000.00, 'active', 40, 1, '2026-06-18 09:40:52', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `advance_payments_supplier`
--

CREATE TABLE `advance_payments_supplier` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `advance_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` enum('cash','bank','cheque','mobile_banking') DEFAULT 'cash',
  `reference_no` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `used_amount` decimal(15,2) DEFAULT 0.00,
  `balance_amount` decimal(15,2) DEFAULT 0.00,
  `status` enum('active','fully_used','cancelled') DEFAULT 'active',
  `voucher_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `journal_voucher_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `advance_usage`
--

CREATE TABLE `advance_usage` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `usage_date` date DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `invoice_no` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assets`
--

CREATE TABLE `assets` (
  `id` int(11) NOT NULL,
  `asset_code` varchar(50) NOT NULL,
  `asset_name` varchar(200) NOT NULL,
  `asset_type` varchar(50) NOT NULL,
  `purchase_date` date NOT NULL,
  `purchase_cost` decimal(15,2) NOT NULL,
  `salvage_value` decimal(15,2) DEFAULT 0.00,
  `useful_life_years` int(11) DEFAULT 5,
  `depreciation_method` enum('straight_line','declining_balance','units_of_production') DEFAULT 'straight_line',
  `current_value` decimal(15,2) DEFAULT 0.00,
  `accumulated_depreciation` decimal(15,2) DEFAULT 0.00,
  `supplier_id` int(11) DEFAULT NULL,
  `warranty_expiry` date DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `status` enum('active','depreciated','disposed','sold') DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `asset_depreciation`
--

CREATE TABLE `asset_depreciation` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `depreciation_date` date NOT NULL,
  `depreciation_amount` decimal(15,2) NOT NULL,
  `accumulated_depreciation` decimal(15,2) NOT NULL,
  `book_value` decimal(15,2) NOT NULL,
  `voucher_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `check_in_time` time DEFAULT NULL,
  `check_out_time` time DEFAULT NULL,
  `status` enum('present','absent','late','half_day') DEFAULT 'absent',
  `overtime_hours` decimal(5,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `employee_id`, `attendance_date`, `check_in_time`, `check_out_time`, `status`, `overtime_hours`) VALUES
(1, 1, '2026-06-17', '09:00:00', '17:00:00', 'present', 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `audit_trail`
--

CREATE TABLE `audit_trail` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_data`)),
  `new_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_data`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chart_of_accounts`
--

CREATE TABLE `chart_of_accounts` (
  `id` int(11) NOT NULL,
  `account_code` varchar(20) NOT NULL,
  `account_name` varchar(100) NOT NULL,
  `account_type` enum('asset','liability','equity','income','expense') NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `balance_type` enum('debit','credit') DEFAULT 'debit',
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `chart_of_accounts`
--

INSERT INTO `chart_of_accounts` (`id`, `account_code`, `account_name`, `account_type`, `parent_id`, `opening_balance`, `balance_type`, `is_active`) VALUES
(1, '1000', 'Cash Account', 'asset', NULL, 500000.00, 'debit', 1),
(2, '1100', 'Bank Account', 'asset', NULL, 1000000.00, 'debit', 1),
(3, '1200', 'Fuel Inventory', 'asset', NULL, 2217699.50, 'debit', 1),
(4, '1300', 'Accounts Receivable', 'asset', NULL, 0.00, 'debit', 1),
(5, '2000', 'Accounts Payable', 'liability', NULL, 0.00, 'credit', 1),
(6, '2100', 'Loan Payable', 'liability', 70, 0.00, 'credit', 1),
(7, '3000', 'Owner\'s Equity', 'equity', NULL, 2717699.50, 'credit', 1),
(8, '4000', 'Fuel Sales', 'income', NULL, 0.00, 'credit', 1),
(9, '4100', 'Rental Income', 'income', NULL, 0.00, 'credit', 1),
(10, '5000', 'Fuel Purchase', 'expense', NULL, 0.00, 'debit', 1),
(11, '5100', 'Stock Loss Expense', 'expense', NULL, 0.00, 'debit', 1),
(12, '5200', 'Utility Expense', 'expense', NULL, 0.00, 'debit', 1),
(30, '5110', 'Salary Expense', 'expense', NULL, 0.00, 'debit', 1),
(39, '3200', 'Retained Earnings', 'equity', NULL, 0.00, 'credit', 1),
(41, '3100', 'Retained Earnings', 'equity', NULL, 0.00, 'credit', 1),
(57, '4200', 'Service Income', 'income', NULL, 0.00, 'credit', 1),
(60, '5300', 'Rent Expense', 'expense', NULL, 0.00, 'debit', 1),
(61, '5400', 'Maintenance Expense', 'expense', NULL, 0.00, 'debit', 1),
(62, '5500', 'Fuel Purchase Expense', 'expense', NULL, 0.00, 'debit', 1),
(68, '1201', 'Fuel Inventory Adjustment', 'asset', NULL, 0.00, 'debit', 1),
(69, '5001', 'General Purchase', 'expense', NULL, 0.00, 'debit', 1),
(70, '2101', 'Loan Account', 'liability', NULL, 0.00, 'debit', 1),
(71, '3300', 'Customer Advance', 'liability', NULL, 0.00, 'credit', 1),
(72, '5120', 'Bonus Expense', 'expense', NULL, 0.00, 'debit', 1),
(73, '1400', 'Inventory - Items', 'asset', NULL, 0.00, 'debit', 1),
(74, '4001', 'Sales Revenue - Services', 'income', NULL, 0.00, 'credit', 1),
(81, '5130', 'Employee Advance', 'asset', NULL, 0.00, 'debit', 1);

-- --------------------------------------------------------

--
-- Table structure for table `cng_daily_summary`
--

CREATE TABLE `cng_daily_summary` (
  `id` int(11) NOT NULL,
  `summary_date` date NOT NULL,
  `total_sales_count` int(11) DEFAULT 0,
  `total_units_sold` decimal(12,2) DEFAULT 0.00,
  `total_amount` decimal(12,2) DEFAULT 0.00,
  `cash_amount` decimal(12,2) DEFAULT 0.00,
  `credit_amount` decimal(12,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cng_meter_readings`
--

CREATE TABLE `cng_meter_readings` (
  `id` int(11) NOT NULL,
  `nozzle_id` int(11) NOT NULL,
  `reading_date` date NOT NULL,
  `opening_meter` decimal(12,2) NOT NULL,
  `closing_meter` decimal(12,2) NOT NULL,
  `units_sold` decimal(12,2) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cng_shift_closing`
--

CREATE TABLE `cng_shift_closing` (
  `id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `closing_date` date NOT NULL,
  `opening_meter_total` decimal(12,2) DEFAULT 0.00,
  `closing_meter_total` decimal(12,2) DEFAULT 0.00,
  `total_units_sold` decimal(12,2) DEFAULT 0.00,
  `total_amount` decimal(12,2) DEFAULT 0.00,
  `cash_amount` decimal(12,2) DEFAULT 0.00,
  `credit_amount` decimal(12,2) DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('open','closed') DEFAULT 'open'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `credit_payments`
--

CREATE TABLE `credit_payments` (
  `id` int(11) NOT NULL,
  `credit_sale_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT 'cash',
  `receipt_no` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `voucher_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `credit_payments`
--

INSERT INTO `credit_payments` (`id`, `credit_sale_id`, `payment_date`, `amount`, `payment_method`, `receipt_no`, `notes`, `voucher_id`) VALUES
(1, 1, '2026-06-16', 12000.00, 'cash', 'PAY-20260616095302276', '', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `credit_sales`
--

CREATE TABLE `credit_sales` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `invoice_no` varchar(50) NOT NULL,
  `sale_date` date NOT NULL,
  `due_date` date NOT NULL,
  `total_amount` decimal(15,2) NOT NULL,
  `advance_adjusted` decimal(12,2) DEFAULT 0.00,
  `paid_amount` decimal(15,2) DEFAULT 0.00,
  `balance_due` decimal(15,2) NOT NULL,
  `status` enum('pending','partial','paid','overdue') DEFAULT 'pending',
  `voucher_id` int(11) DEFAULT NULL,
  `journal_voucher_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `credit_sales`
--

INSERT INTO `credit_sales` (`id`, `sale_id`, `customer_id`, `invoice_no`, `sale_date`, `due_date`, `total_amount`, `advance_adjusted`, `paid_amount`, `balance_due`, `status`, `voucher_id`, `journal_voucher_id`) VALUES
(1, 2, 1, 'INV-20260616095155', '2026-06-16', '2026-07-16', 12075.00, 0.00, 12000.00, 75.00, 'partial', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `customer_code` varchar(50) NOT NULL,
  `customer_name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `credit_limit` decimal(15,2) DEFAULT 0.00,
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `advance_balance` decimal(15,2) DEFAULT 0.00,
  `last_purchase_date` datetime DEFAULT NULL,
  `total_purchases` decimal(15,2) DEFAULT 0.00,
  `total_credit_sales` decimal(15,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `total_cash_sales` decimal(15,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `customer_code`, `customer_name`, `phone`, `email`, `address`, `credit_limit`, `opening_balance`, `current_balance`, `advance_balance`, `last_purchase_date`, `total_purchases`, `total_credit_sales`, `is_active`, `created_at`, `total_cash_sales`) VALUES
(1, 'CUST-20260616778', 'MUHAMMAD RAFIQUL ALAM ALAM', '01782382140', NULL, NULL, 50000.00, 0.00, 75.00, 0.00, NULL, 12075.00, 12075.00, 1, '2026-06-16 07:51:55', 0.00),
(2, 'CUST-20260617650', 'Mr. Forhad', '01789562323', NULL, NULL, 50000.00, 0.00, 1500.00, 10000.00, '2026-06-27 12:05:06', 8225.00, 0.00, 1, '2026-06-17 12:14:57', 4725.00);

-- --------------------------------------------------------

--
-- Table structure for table `customer_transactions`
--

CREATE TABLE `customer_transactions` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `transaction_date` datetime NOT NULL,
  `transaction_type` enum('sale','payment','advance','adjustment') NOT NULL,
  `reference_no` varchar(50) NOT NULL,
  `sale_type` enum('cash','credit','advance') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `debit` decimal(15,2) DEFAULT 0.00,
  `credit` decimal(15,2) DEFAULT 0.00,
  `balance` decimal(15,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customer_transactions`
--

INSERT INTO `customer_transactions` (`id`, `customer_id`, `transaction_date`, `transaction_type`, `reference_no`, `sale_type`, `amount`, `debit`, `credit`, `balance`, `notes`, `created_at`) VALUES
(1, 2, '2026-06-27 11:21:10', 'sale', 'INV-20260627072110', 'cash', 2000.00, 0.00, 2000.00, 0.00, 'White Car', '2026-06-27 05:21:10'),
(2, 2, '2026-06-27 12:05:06', 'sale', 'INV-20260627080506', 'cash', 1500.00, 0.00, 1500.00, 0.00, 'Red Car', '2026-06-27 06:05:06');

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `designation` varchar(50) DEFAULT NULL,
  `department` varchar(50) DEFAULT NULL,
  `joining_date` date DEFAULT NULL,
  `basic_salary` decimal(10,2) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `bank_account_no` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `employee_id`, `full_name`, `designation`, `department`, `joining_date`, `basic_salary`, `phone`, `address`, `bank_account_no`, `is_active`) VALUES
(1, 'EMP-001', 'MUHAMMAD RAFIQUL ALAM', 'ASSISTANT MANAGER', 'Operations', '2026-06-01', 25000.00, '01782382140', '102, Shukrabad, Dhanmondi\r\nDhaka', '256325', 1);

-- --------------------------------------------------------

--
-- Table structure for table `employee_payments`
--

CREATE TABLE `employee_payments` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_type` enum('salary','bonus','overtime','advance','others') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` enum('cash','bank','cheque') DEFAULT 'cash',
  `reference_no` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `voucher_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `employee_payments`
--

INSERT INTO `employee_payments` (`id`, `employee_id`, `payment_date`, `payment_type`, `amount`, `payment_method`, `reference_no`, `notes`, `voucher_id`, `created_by`, `created_at`) VALUES
(1, 1, '2026-06-18', 'bonus', 1500.00, 'cash', NULL, '', 36, 1, '2026-06-18 07:34:05');

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `expense_date` date NOT NULL,
  `expense_type` enum('electricity','generator_fuel','maintenance','repair','other') NOT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `vendor_name` varchar(100) DEFAULT NULL,
  `voucher_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fuel_products`
--

CREATE TABLE `fuel_products` (
  `id` int(11) NOT NULL,
  `product_name` enum('Diesel','Petrol','Octane','CNG','LPG','Natural Gas') NOT NULL,
  `product_code` varchar(50) DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `purchase_rate` decimal(10,2) NOT NULL,
  `vat_percentage` decimal(5,2) DEFAULT 0.00,
  `tax_percentage` decimal(5,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `unit_type` enum('liters','cubic_meters','kilograms') DEFAULT 'liters',
  `conversion_rate` decimal(10,4) DEFAULT 1.0000
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `fuel_products`
--

INSERT INTO `fuel_products` (`id`, `product_name`, `product_code`, `unit_price`, `purchase_rate`, `vat_percentage`, `tax_percentage`, `is_active`, `unit_type`, `conversion_rate`) VALUES
(1, 'Diesel', 'D01', 105.00, 100.00, 0.00, 0.00, 1, 'liters', 1.0000),
(2, 'Petrol', 'P01', 120.00, 105.00, 0.00, 0.00, 1, 'liters', 1.0000),
(3, 'Octane', 'O01', 130.00, 115.00, 0.00, 0.00, 1, 'liters', 1.0000),
(5, 'LPG', 'LPG01', 110.00, 85.00, 0.00, 0.00, 1, 'kilograms', 1.0000),
(6, 'CNG', 'CNG01', 43.00, 42.00, 0.00, 0.00, 1, 'cubic_meters', 1.0000);

-- --------------------------------------------------------

--
-- Table structure for table `fuel_receivings`
--

CREATE TABLE `fuel_receivings` (
  `id` int(11) NOT NULL,
  `receipt_no` varchar(50) NOT NULL,
  `receipt_date` date NOT NULL,
  `supplier_name` varchar(100) NOT NULL,
  `tanker_no` varchar(50) DEFAULT NULL,
  `challan_no` varchar(50) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `tank_id` int(11) NOT NULL,
  `expected_quantity` decimal(10,2) NOT NULL,
  `actual_quantity` decimal(10,2) NOT NULL,
  `shortage` decimal(10,2) DEFAULT 0.00,
  `freight_cost` decimal(10,2) DEFAULT 0.00,
  `freight_deduction` decimal(10,2) DEFAULT 0.00,
  `unit_price` decimal(10,2) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `payment_status` enum('pending','partial','paid') DEFAULT 'pending',
  `paid_amount` decimal(15,2) DEFAULT 0.00,
  `due_amount` decimal(15,2) DEFAULT 0.00,
  `is_gas_receiving` tinyint(1) DEFAULT 0,
  `meter_reading_start` decimal(10,2) DEFAULT NULL,
  `meter_reading_end` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `fuel_receivings`
--

INSERT INTO `fuel_receivings` (`id`, `receipt_no`, `receipt_date`, `supplier_name`, `tanker_no`, `challan_no`, `product_id`, `tank_id`, `expected_quantity`, `actual_quantity`, `shortage`, `freight_cost`, `freight_deduction`, `unit_price`, `total_amount`, `status`, `approved_by`, `supplier_id`, `payment_status`, `paid_amount`, `due_amount`, `is_gas_receiving`, `meter_reading_start`, `meter_reading_end`) VALUES
(1, 'RCV-20260616095606', '2026-06-16', 'Jamuna Fuel Supply', 'Dhaka-Ga-0144', '5252', 1, 1, 5000.00, 4950.00, 50.00, 0.00, 0.00, 69.00, 341550.00, 'approved', 1, 2, 'paid', 341550.00, 0.00, 0, NULL, NULL),
(3, 'RCV-20260617143947', '2026-06-17', 'Padma Oil Company', 'Dhaka-Ga-0144', '5265', 1, 1, 5000.00, 4950.00, 50.00, 0.00, 0.00, 120.00, 594000.00, 'approved', 1, 1, 'paid', 594000.00, 0.00, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `gas_sales`
--

CREATE TABLE `gas_sales` (
  `id` int(11) NOT NULL,
  `invoice_no` varchar(50) NOT NULL,
  `sale_date` datetime DEFAULT current_timestamp(),
  `shift_id` int(11) NOT NULL,
  `nozzle_id` int(11) NOT NULL,
  `operator_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `vehicle_number` varchar(50) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `sale_type` enum('cash','credit','advance') NOT NULL,
  `payment_method` enum('cash','card','bkash','nagad','credit') DEFAULT 'cash',
  `card_number` varchar(20) DEFAULT NULL,
  `card_holder_name` varchar(100) DEFAULT NULL,
  `transaction_id` varchar(50) DEFAULT NULL,
  `opening_meter` decimal(10,2) NOT NULL,
  `closing_meter` decimal(10,2) NOT NULL,
  `quantity_liters` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `received_amount` decimal(10,2) DEFAULT NULL,
  `advance_used` decimal(12,2) DEFAULT 0.00,
  `advance_payment_id` int(11) DEFAULT NULL,
  `change_amount` decimal(10,2) DEFAULT NULL,
  `status` enum('completed','cancelled') DEFAULT 'completed',
  `is_printed` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `gas_sales`
--

INSERT INTO `gas_sales` (`id`, `invoice_no`, `sale_date`, `shift_id`, `nozzle_id`, `operator_id`, `customer_id`, `customer_name`, `customer_phone`, `vehicle_number`, `remarks`, `sale_type`, `payment_method`, `card_number`, `card_holder_name`, `transaction_id`, `opening_meter`, `closing_meter`, `quantity_liters`, `unit_price`, `total_amount`, `received_amount`, `advance_used`, `advance_payment_id`, `change_amount`, `status`, `is_printed`) VALUES
(1, 'CNG-20260618081941', '2026-06-18 12:19:41', 1, 6, 1, NULL, '', '', NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 0.00, 5.56, 5.56, 90.00, 500.00, 0.00, 0.00, NULL, -500.00, 'completed', 0),
(2, 'CNG-20260618082006', '2026-06-18 12:20:06', 1, 6, 1, NULL, '', '', NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 0.00, 5.56, 5.56, 90.00, 500.00, 0.00, 0.00, NULL, -500.00, 'completed', 0),
(3, 'CNG-20260618082038', '2026-06-18 12:20:38', 1, 6, 1, NULL, '', '', NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 5.56, 11.12, 5.56, 90.00, 500.00, 0.00, 0.00, NULL, -500.00, 'completed', 0),
(4, 'CNG-20260618082848', '2026-06-18 12:28:48', 1, 6, 1, NULL, '', '', NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 11.12, 16.68, 5.56, 90.00, 500.00, 0.00, 0.00, NULL, -500.00, 'completed', 0),
(5, 'CNG-20260618084947', '2026-06-18 12:49:47', 1, 6, 1, NULL, '', '', NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 16.68, 22.24, 5.56, 90.00, 500.00, 0.00, 0.00, NULL, -500.00, 'completed', 0),
(6, 'CNG-20260618085403', '2026-06-18 12:54:03', 1, 6, 1, NULL, '', '', NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 22.24, 27.80, 5.56, 90.00, 500.00, 0.00, 0.00, NULL, -500.00, 'completed', 0),
(7, 'CNG-20260618085501', '2026-06-18 12:55:01', 1, 6, 1, NULL, '', '', NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 27.80, 33.36, 5.56, 90.00, 500.00, 0.00, 0.00, NULL, -500.00, 'completed', 0),
(8, 'CNG-20260618093509579', '2026-06-18 13:35:09', 1, 7, 1, NULL, '', '', NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 0.00, 4.44, 4.44, 90.00, 400.00, 500.00, 0.00, NULL, 100.00, 'completed', 0),
(9, 'CNG-20260618093542713', '2026-06-18 13:35:42', 1, 8, 1, NULL, '', '', NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 0.00, 5.56, 5.56, 90.00, 500.00, 0.00, 0.00, NULL, -500.00, 'completed', 0),
(10, 'CNG-20260618131836964', '2026-06-18 17:18:36', 2, 7, 1, NULL, '', '', NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 4.44, 8.88, 4.44, 90.00, 400.00, 0.00, 0.00, NULL, -400.00, 'completed', 0),
(11, 'CNG-20260618131921709', '2026-06-18 17:19:21', 2, 8, 1, NULL, '', '', NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 5.56, 10.00, 4.44, 90.00, 400.00, 0.00, 0.00, NULL, -400.00, 'completed', 0),
(12, 'CNG-20260618132520915', '2026-06-18 17:25:20', 2, 7, 1, NULL, '', '', NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 8.88, 12.21, 3.33, 90.00, 300.00, 0.00, 0.00, NULL, -300.00, 'completed', 0),
(13, 'CNG-20260620150014877', '2026-06-20 19:00:14', 2, 8, 1, NULL, '', '', 'Dhaka-Metro-Ka-1234', '', 'cash', 'cash', NULL, NULL, NULL, 10.00, 12.78, 2.78, 90.00, 250.00, 0.00, 0.00, NULL, -250.00, 'completed', 0),
(14, 'CNG-20260620150629162', '2026-06-20 19:06:29', 2, 7, 1, NULL, '', '', 'Dhaka-Metro-Ka-1234', '', 'cash', 'cash', NULL, NULL, NULL, 25.00, 26.11, 1.11, 90.00, 100.00, 0.00, 0.00, NULL, -100.00, 'completed', 0),
(15, 'CNG-20260620152556822', '2026-06-20 19:25:56', 2, 7, 1, NULL, '', '', 'Dhaka-Metro-Ka-5550', 'ABC', 'cash', 'cash', NULL, NULL, NULL, 26.11, 27.72, 1.61, 90.00, 145.00, 0.00, 0.00, NULL, -145.00, 'completed', 0),
(16, 'CNG-20260620153217247', '2026-06-20 19:32:17', 2, 7, 1, NULL, '', '', 'Dhaka-Metro-Ka-5556', 'sdfdsf', 'cash', 'cash', NULL, NULL, NULL, 27.72, 32.72, 5.00, 90.00, 450.00, 0.00, 0.00, NULL, -450.00, 'completed', 0),
(17, 'CNG-20260620154301392', '2026-06-20 19:43:01', 2, 9, 1, NULL, '', '', 'Dhaka-Metro-Ka-0001', 'sdfdsf', 'cash', 'cash', NULL, NULL, NULL, 0.00, 8.89, 8.89, 90.00, 800.00, 0.00, 0.00, NULL, -800.00, 'completed', 0),
(18, 'CNG-20260620154821960', '2026-06-20 19:48:21', 2, 9, 1, NULL, '', '', 'Dhaka-Metro-Ka-5556', '', 'cash', 'cash', NULL, NULL, NULL, 8.89, 11.67, 2.78, 90.00, 250.00, 0.00, 0.00, NULL, -250.00, 'completed', 0),
(19, 'CNG-20260621093316352', '2026-06-21 13:33:16', 4, 7, 1, NULL, '', '', 'Dhaka-Metro-Ka-5556', '', 'cash', 'cash', NULL, NULL, NULL, 33.00, 35.22, 2.22, 90.00, 200.00, 0.00, 0.00, NULL, -200.00, 'completed', 0),
(20, 'CNG-20260621110359915', '2026-06-21 15:03:59', 4, 7, 1, NULL, '', '', 'Dhaka-Metro-Ka-5550', '', 'cash', 'cash', NULL, NULL, NULL, 35.22, 63.00, 27.78, 90.00, 2500.00, 3000.00, 0.00, NULL, 500.00, 'completed', 0),
(21, 'CNG-20260621112640678', '2026-06-21 15:26:40', 4, 9, 1, NULL, '', '', 'Dhaka-Metro-Ka-00034', '', 'cash', 'cash', NULL, NULL, NULL, 12.00, 15.89, 3.89, 90.00, 350.00, 500.00, 0.00, NULL, 150.00, 'completed', 0),
(22, 'CNG-20260622074636821', '2026-06-22 11:46:36', 4, 7, 1, NULL, '', '', 'Dhaka-Metro-Ka-00034', '', 'cash', 'card', '5460084203523978', 'MD NURUR RAHMAN', '001', 63.00, 67.44, 4.44, 90.00, 400.00, 500.00, 0.00, NULL, 100.00, 'completed', 0),
(23, 'CNG-20260622074712158', '2026-06-22 11:47:12', 4, 8, 1, NULL, '', '', 'Dhaka-Metro-Ka-5556', '', 'cash', 'card', '5460084203523978', 'MD NURUR RAHMAN', '4566', 13.00, 16.89, 3.89, 90.00, 350.00, 500.00, 0.00, NULL, 150.00, 'completed', 0),
(24, 'CNG-20260627081135449', '2026-06-27 12:11:35', 7, 9, 1, 2, 'Mr. Forhad', '01789562323', 'Dhaka-Metro-Ka-00034', 'Black Car', 'cash', 'cash', '', '', '', 100.00, 109.44, 9.44, 90.00, 850.00, 1000.00, 0.00, NULL, 150.00, 'completed', 0),
(25, 'CNG-20260627081408981', '2026-06-27 12:14:08', 7, 9, 1, 2, 'Mr. Forhad', '01789562323', 'Dhaka-Metro-Ka-00034', 'Black Car', 'cash', 'cash', '', '', '', 109.44, 111.66, 2.22, 90.00, 200.00, 500.00, 0.00, NULL, 300.00, 'completed', 0),
(26, 'CNG-20260627082415512', '2026-06-27 12:24:15', 7, 9, 1, NULL, '', '', 'Dhaka-Metro-Ka-00034', '', 'cash', 'cash', '', '', '', 111.66, 124.99, 13.33, 90.00, 1200.00, 1500.00, 0.00, NULL, 300.00, 'completed', 0);

-- --------------------------------------------------------

--
-- Table structure for table `items`
--

CREATE TABLE `items` (
  `id` int(11) NOT NULL,
  `item_code` varchar(50) DEFAULT NULL,
  `item_name` varchar(200) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `item_type` enum('product','service') DEFAULT 'product',
  `unit` varchar(50) DEFAULT 'pcs',
  `purchase_price` decimal(12,2) DEFAULT 0.00,
  `selling_price` decimal(12,2) NOT NULL,
  `current_stock` decimal(12,2) DEFAULT 0.00,
  `min_stock` decimal(12,2) DEFAULT 0.00,
  `tax_rate` decimal(5,2) DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `items`
--

INSERT INTO `items` (`id`, `item_code`, `item_name`, `category_id`, `item_type`, `unit`, `purchase_price`, `selling_price`, `current_stock`, `min_stock`, `tax_rate`, `description`, `is_active`, `created_at`) VALUES
(1, 'LUB-001', 'Engine Oil 10W-40 (1L)', 1, 'product', 'L', 350.00, 450.00, 98.00, 0.00, 0.00, 'Premium engine oil 10W-40', 1, '2026-06-18 11:44:28'),
(2, 'LUB-002', 'Gear Oil 80W-90 (1L)', 1, 'product', 'L', 0.00, 350.00, 0.00, 0.00, 0.00, 'Gear oil 80W-90', 1, '2026-06-18 11:44:28'),
(3, 'WASH-001', 'Exterior Car Wash', 2, 'service', 'pcs', 0.00, 300.00, 0.00, 0.00, 0.00, 'Complete exterior car wash', 1, '2026-06-18 11:44:28'),
(4, 'WASH-002', 'Interior Cleaning', 2, 'service', 'pcs', 0.00, 500.00, 0.00, 0.00, 0.00, 'Full interior vacuum and cleaning', 1, '2026-06-18 11:44:28'),
(5, 'SRV-001', 'Oil Change Service', 3, 'service', 'pcs', 0.00, 200.00, 0.00, 0.00, 0.00, 'Engine oil change service', 1, '2026-06-18 11:44:28'),
(6, 'SRV-002', 'Tire Check & Rotation', 3, 'service', 'pcs', 0.00, 300.00, 0.00, 0.00, 0.00, 'Tire pressure check and rotation', 1, '2026-06-18 11:44:28'),
(7, 'ACC-001', 'Car Air Freshener', 5, 'product', 'pcs', 150.00, 150.00, 6.00, 0.00, 0.00, 'Premium car air freshener', 1, '2026-06-18 11:44:28'),
(8, 'ACC-002', 'Phone Holder', 5, 'product', 'pcs', 0.00, 250.00, 0.00, 0.00, 0.00, 'Car phone holder mount', 1, '2026-06-18 11:44:28');

-- --------------------------------------------------------

--
-- Table structure for table `item_categories`
--

CREATE TABLE `item_categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `category_code` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `item_categories`
--

INSERT INTO `item_categories` (`id`, `category_name`, `category_code`, `description`, `is_active`, `created_at`) VALUES
(1, 'Lubricants', 'LUB', 'Engine oils, greases, and lubricants', 1, '2026-06-18 11:44:28'),
(2, 'Car Wash', 'WASH', 'Car wash services', 1, '2026-06-18 11:44:28'),
(3, 'Servicing', 'SRV', 'Vehicle servicing and maintenance', 1, '2026-06-18 11:44:28'),
(4, 'Spare Parts', 'PARTS', 'Vehicle spare parts', 1, '2026-06-18 11:44:28'),
(5, 'Accessories', 'ACC', 'Car accessories and add-ons', 1, '2026-06-18 11:44:28'),
(6, 'Other', 'OTHER', 'Other items and services', 1, '2026-06-18 11:44:28');

-- --------------------------------------------------------

--
-- Table structure for table `item_purchases`
--

CREATE TABLE `item_purchases` (
  `id` int(11) NOT NULL,
  `purchase_no` varchar(50) NOT NULL,
  `purchase_date` date NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `supplier_name` varchar(100) DEFAULT NULL,
  `supplier_phone` varchar(20) DEFAULT NULL,
  `subtotal` decimal(12,2) DEFAULT 0.00,
  `discount` decimal(12,2) DEFAULT 0.00,
  `tax` decimal(12,2) DEFAULT 0.00,
  `shipping` decimal(12,2) DEFAULT 0.00,
  `total_amount` decimal(12,2) DEFAULT 0.00,
  `payment_method` varchar(20) DEFAULT 'cash',
  `payment_status` varchar(20) DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `status` enum('completed','cancelled') DEFAULT 'completed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `item_purchases`
--

INSERT INTO `item_purchases` (`id`, `purchase_no`, `purchase_date`, `supplier_id`, `supplier_name`, `supplier_phone`, `subtotal`, `discount`, `tax`, `shipping`, `total_amount`, `payment_method`, `payment_status`, `notes`, `created_by`, `status`, `created_at`) VALUES
(2, '12562', '2026-06-18', 2, 'Jamuna Fuel Supply', '01700000002', 35800.00, 0.00, 0.00, 0.00, 35800.00, 'cash', 'paid', '', 1, 'completed', '2026-06-18 12:24:58'),
(3, '100010', '2026-06-18', 7, 'MS T-MOBIL CO ', '01700000002', 750.00, 0.00, 0.00, 0.00, 750.00, 'cash', 'paid', '', 1, 'completed', '2026-06-18 12:38:25');

-- --------------------------------------------------------

--
-- Table structure for table `item_purchase_items`
--

CREATE TABLE `item_purchase_items` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `purchase_price` decimal(12,2) NOT NULL,
  `selling_price` decimal(12,2) NOT NULL,
  `total` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `item_purchase_items`
--

INSERT INTO `item_purchase_items` (`id`, `purchase_id`, `item_id`, `quantity`, `purchase_price`, `selling_price`, `total`) VALUES
(3, 2, 7, 3.00, 150.00, 200.00, 450.00),
(4, 2, 1, 101.00, 350.00, 450.00, 35350.00),
(5, 3, 7, 5.00, 150.00, 150.00, 750.00);

-- --------------------------------------------------------

--
-- Table structure for table `item_sales`
--

CREATE TABLE `item_sales` (
  `id` int(11) NOT NULL,
  `invoice_no` varchar(50) NOT NULL,
  `sale_date` datetime NOT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `sale_type` enum('cash','credit','advance') DEFAULT 'cash',
  `payment_method` enum('cash','card','bkash','nagad','credit') DEFAULT 'cash',
  `card_number` varchar(20) DEFAULT NULL,
  `card_holder_name` varchar(100) DEFAULT NULL,
  `transaction_id` varchar(50) DEFAULT NULL,
  `subtotal` decimal(12,2) DEFAULT 0.00,
  `discount_amount` decimal(12,2) DEFAULT 0.00,
  `discount_percent` decimal(5,2) DEFAULT 0.00,
  `tax_amount` decimal(12,2) DEFAULT 0.00,
  `total_amount` decimal(12,2) DEFAULT 0.00,
  `received_amount` decimal(12,2) DEFAULT 0.00,
  `change_amount` decimal(12,2) DEFAULT 0.00,
  `advance_used` decimal(12,2) DEFAULT 0.00,
  `advance_payment_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `status` enum('completed','cancelled') DEFAULT 'completed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `item_sales`
--

INSERT INTO `item_sales` (`id`, `invoice_no`, `sale_date`, `shift_id`, `customer_id`, `customer_name`, `customer_phone`, `sale_type`, `payment_method`, `card_number`, `card_holder_name`, `transaction_id`, `subtotal`, `discount_amount`, `discount_percent`, `tax_amount`, `total_amount`, `received_amount`, `change_amount`, `advance_used`, `advance_payment_id`, `notes`, `created_by`, `status`, `created_at`) VALUES
(1, 'ITEM-20260618143100197', '2026-06-18 18:31:00', 2, NULL, '', '', 'cash', 'cash', NULL, NULL, NULL, 300.00, 0.00, 0.00, 0.00, 300.00, 0.00, -300.00, 0.00, NULL, '', 1, 'completed', '2026-06-18 12:31:00'),
(2, 'ITEM-20260621111746748', '2026-06-21 15:17:46', 4, NULL, '', '', 'cash', 'cash', NULL, NULL, NULL, 450.00, 0.00, 0.00, 0.00, 450.00, 500.00, 50.00, 0.00, NULL, '', 1, 'completed', '2026-06-21 09:17:46'),
(3, 'ITEM-20260622075701812', '2026-06-22 11:57:01', 4, NULL, '', '', 'cash', 'card', '5460084203523978', 'MD NURUR RAHMAN', '12312', 1050.00, 0.00, 0.00, 0.00, 1050.00, 1100.00, 50.00, 0.00, NULL, '', 1, 'completed', '2026-06-22 05:57:01'),
(4, 'ITEM-20260627082442645', '2026-06-27 12:24:42', 7, NULL, '', '', 'cash', 'cash', '', '', '', 450.00, 0.00, 0.00, 0.00, 450.00, 0.00, -450.00, 0.00, NULL, '', 1, 'completed', '2026-06-27 06:24:42');

-- --------------------------------------------------------

--
-- Table structure for table `item_sale_items`
--

CREATE TABLE `item_sale_items` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `discount_amount` decimal(12,2) DEFAULT 0.00,
  `tax_amount` decimal(12,2) DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `item_sale_items`
--

INSERT INTO `item_sale_items` (`id`, `sale_id`, `item_id`, `quantity`, `unit_price`, `discount_amount`, `tax_amount`, `total_amount`) VALUES
(1, 1, 7, 2.00, 150.00, 0.00, 0.00, 300.00),
(2, 2, 1, 1.00, 450.00, 0.00, 0.00, 450.00),
(3, 3, 3, 2.00, 300.00, 0.00, 0.00, 600.00),
(4, 3, 1, 1.00, 450.00, 0.00, 0.00, 450.00),
(5, 4, 1, 1.00, 450.00, 0.00, 0.00, 450.00);

-- --------------------------------------------------------

--
-- Table structure for table `item_stock_ledger`
--

CREATE TABLE `item_stock_ledger` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `transaction_type` enum('purchase','sale','adjustment','return') NOT NULL,
  `reference_no` varchar(50) NOT NULL,
  `in_quantity` decimal(12,2) DEFAULT 0.00,
  `out_quantity` decimal(12,2) DEFAULT 0.00,
  `unit_cost` decimal(12,2) DEFAULT 0.00,
  `balance_quantity` decimal(12,2) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `item_stock_ledger`
--

INSERT INTO `item_stock_ledger` (`id`, `item_id`, `transaction_type`, `reference_no`, `in_quantity`, `out_quantity`, `unit_cost`, `balance_quantity`, `created_by`, `created_at`) VALUES
(3, 7, 'purchase', '12562', 3.00, 0.00, 150.00, 3.00, 1, '2026-06-18 12:24:58'),
(4, 1, 'purchase', '12562', 101.00, 0.00, 350.00, 101.00, 1, '2026-06-18 12:24:58'),
(5, 7, 'purchase', '100010', 5.00, 0.00, 150.00, 6.00, 1, '2026-06-18 12:38:26');

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `reference_no` varchar(50) NOT NULL,
  `entry_type` enum('debit','credit') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `status` enum('draft','approved','posted','cancelled') DEFAULT 'draft'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_vouchers`
--

CREATE TABLE `journal_vouchers` (
  `id` int(11) NOT NULL,
  `voucher_no` varchar(50) NOT NULL,
  `voucher_date` date NOT NULL,
  `voucher_type` enum('journal','payment','receipt','contra','sales','purchase') NOT NULL,
  `narration` text DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `status` enum('draft','approved','posted','cancelled') DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_voucher_items`
--

CREATE TABLE `journal_voucher_items` (
  `id` int(11) NOT NULL,
  `voucher_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `account_type` enum('debit','credit') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leakage_adjustments`
--

CREATE TABLE `leakage_adjustments` (
  `id` int(11) NOT NULL,
  `adjustment_date` date NOT NULL,
  `tank_id` int(11) NOT NULL,
  `system_stock` decimal(10,2) NOT NULL,
  `physical_stock` decimal(10,2) NOT NULL,
  `variance` decimal(10,2) NOT NULL,
  `dip_stick_reading` decimal(10,2) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `adjustment_type` enum('leakage','wastage','theft','error') NOT NULL,
  `loss_amount` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `leakage_adjustments`
--

INSERT INTO `leakage_adjustments` (`id`, `adjustment_date`, `tank_id`, `system_stock`, `physical_stock`, `variance`, `dip_stick_reading`, `reason`, `adjustment_type`, `loss_amount`, `status`, `approved_by`, `created_by`) VALUES
(1, '2026-06-17', 1, 18365.00, 18364.00, 1.00, 0.00, 'Wastage dkfjsdfkjsf lkj', 'wastage', 100.00, 'approved', NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `loans`
--

CREATE TABLE `loans` (
  `id` int(11) NOT NULL,
  `loan_type` enum('given','received') NOT NULL,
  `party_name` varchar(100) NOT NULL,
  `principal_amount` decimal(12,2) NOT NULL,
  `interest_rate` decimal(5,2) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','closed','defaulted') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_installments`
--

CREATE TABLE `loan_installments` (
  `id` int(11) NOT NULL,
  `loan_id` int(11) NOT NULL,
  `due_date` date NOT NULL,
  `installment_amount` decimal(12,2) NOT NULL,
  `paid_amount` decimal(12,2) DEFAULT 0.00,
  `payment_date` date DEFAULT NULL,
  `status` enum('pending','paid','partial') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meter_readings`
--

CREATE TABLE `meter_readings` (
  `id` int(11) NOT NULL,
  `nozzle_id` int(11) NOT NULL,
  `reading_date` datetime DEFAULT current_timestamp(),
  `shift_id` int(11) NOT NULL,
  `opening_meter` decimal(10,2) NOT NULL,
  `closing_meter` decimal(10,2) NOT NULL,
  `sale_quantity` decimal(10,2) DEFAULT 0.00,
  `recorded_by` int(11) DEFAULT NULL,
  `shift_closed` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `meter_readings`
--

INSERT INTO `meter_readings` (`id`, `nozzle_id`, `reading_date`, `shift_id`, `opening_meter`, `closing_meter`, `sale_quantity`, `recorded_by`, `shift_closed`) VALUES
(1, 1, '2026-06-18 10:46:18', 1, 275.00, 275.00, 0.00, 1, 1),
(2, 4, '2026-06-18 10:46:18', 1, 0.00, 0.00, 0.00, 1, 1),
(3, 5, '2026-06-18 10:46:18', 1, 0.00, 0.00, 0.00, 1, 1),
(4, 1, '2026-06-18 15:36:45', 2, 275.00, 275.00, 0.00, 1, 1),
(5, 4, '2026-06-18 15:36:45', 2, 0.00, 0.00, 0.00, 1, 1),
(6, 5, '2026-06-18 15:36:45', 2, 0.00, 0.00, 0.00, 1, 1),
(7, 7, '2026-06-18 18:41:32', 2, 12.21, 25.00, 0.00, 1, 0),
(8, 7, '2026-06-21 10:36:09', 3, 32.72, 33.00, 0.00, 1, 1),
(9, 8, '2026-06-21 10:36:09', 3, 12.78, 13.00, 0.00, 1, 1),
(10, 9, '2026-06-21 10:36:09', 3, 11.67, 12.00, 0.00, 1, 1),
(11, 1, '2026-06-21 10:36:09', 3, 275.00, 285.00, 0.00, 1, 1),
(12, 4, '2026-06-21 10:36:09', 3, 0.00, 0.00, 0.00, 1, 1),
(13, 5, '2026-06-21 10:36:09', 3, 0.00, 0.00, 0.00, 1, 1),
(14, 7, '2026-06-21 13:32:24', 4, 33.00, 33.00, 0.00, 1, 1),
(15, 8, '2026-06-21 13:32:24', 4, 13.00, 14.00, 0.00, 1, 1),
(16, 9, '2026-06-21 13:32:24', 4, 12.00, 12.50, 0.00, 1, 1),
(17, 1, '2026-06-21 13:32:24', 4, 285.00, 285.50, 0.00, 1, 1),
(18, 4, '2026-06-21 13:32:24', 4, 0.00, 0.00, 0.00, 1, 1),
(19, 5, '2026-06-21 13:32:24', 4, 0.00, 0.00, 0.00, 1, 1),
(20, 7, '2026-06-24 15:54:44', 5, 33.00, 35.00, 0.00, 1, 1),
(21, 8, '2026-06-24 15:54:44', 5, 14.00, 15.00, 0.00, 1, 1),
(22, 9, '2026-06-24 15:54:44', 5, 12.50, 15.00, 0.00, 1, 1),
(23, 1, '2026-06-24 15:54:44', 5, 285.50, 290.00, 0.00, 1, 1),
(24, 4, '2026-06-24 15:54:44', 5, 0.00, 0.00, 0.00, 1, 1),
(25, 5, '2026-06-24 15:54:44', 5, 0.00, 0.00, 0.00, 1, 1),
(26, 7, '2026-06-24 17:39:23', 6, 35.00, 105.00, 0.00, 1, 1),
(27, 8, '2026-06-24 17:39:23', 6, 15.00, 180.00, 0.00, 1, 1),
(28, 9, '2026-06-24 17:39:23', 6, 15.00, 100.00, 0.00, 1, 1),
(29, 1, '2026-06-24 17:39:23', 6, 290.00, 500.00, 0.00, 1, 1),
(30, 4, '2026-06-24 17:39:23', 6, 0.00, 0.00, 0.00, 1, 1),
(31, 5, '2026-06-24 17:39:23', 6, 0.00, 0.00, 0.00, 1, 1),
(32, 7, '2026-06-27 11:20:24', 7, 105.00, 115.00, 0.00, 1, 1),
(33, 8, '2026-06-27 11:20:24', 7, 180.00, 250.00, 0.00, 1, 1),
(34, 9, '2026-06-27 11:20:24', 7, 100.00, 300.00, 0.00, 1, 1),
(35, 1, '2026-06-27 11:20:24', 7, 500.00, 1050.00, 0.00, 1, 1),
(36, 4, '2026-06-27 11:20:24', 7, 0.00, 0.00, 0.00, 1, 1),
(37, 5, '2026-06-27 11:20:24', 7, 0.00, 0.00, 0.00, 1, 1),
(38, 7, '2026-06-27 18:54:00', 8, 105.00, 255.00, 0.00, 1, 1),
(39, 8, '2026-06-27 18:54:00', 8, 180.00, 255.00, 0.00, 1, 1),
(40, 9, '2026-06-27 18:54:00', 8, 124.99, 255.00, 0.00, 1, 1),
(41, 1, '2026-06-27 18:54:00', 8, 500.00, 1500.00, 0.00, 1, 1),
(42, 4, '2026-06-27 18:54:00', 8, 0.00, 0.00, 0.00, 1, 1),
(43, 5, '2026-06-27 18:54:00', 8, 0.00, 0.00, 0.00, 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `nozzles`
--

CREATE TABLE `nozzles` (
  `id` int(11) NOT NULL,
  `nozzle_name` varchar(50) NOT NULL,
  `tank_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `opening_meter` decimal(10,2) DEFAULT 0.00,
  `closing_meter` decimal(10,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `is_pipeline` tinyint(1) DEFAULT 0,
  `pipeline_source` varchar(100) DEFAULT 'Titas Gas',
  `unit_type` enum('liters','cubic_meters','kilograms') DEFAULT 'liters',
  `meter_type` enum('liters','units') DEFAULT 'liters',
  `last_meter_reading` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `nozzles`
--

INSERT INTO `nozzles` (`id`, `nozzle_name`, `tank_id`, `product_id`, `opening_meter`, `closing_meter`, `is_active`, `is_pipeline`, `pipeline_source`, `unit_type`, `meter_type`, `last_meter_reading`) VALUES
(1, 'Nozzle Diesel 01', 1, 1, 0.00, 1500.00, 1, 0, 'Titas Gas', 'liters', 'liters', 0.00),
(4, 'Nozzle Diesel 02', 1, 1, 0.00, 0.00, 1, 0, 'Titas Gas', 'liters', 'liters', 0.00),
(5, 'Nozzle Diesel 03', 1, 1, 0.00, 0.00, 1, 0, 'Titas Gas', 'liters', 'liters', 0.00),
(7, 'CNG Nozzle 01', NULL, 6, 0.00, 255.00, 1, 1, 'Titas Gas', 'cubic_meters', 'liters', 0.00),
(8, 'CNG Nozzle 02', NULL, 6, 0.00, 255.00, 1, 1, 'Titas Gas', 'cubic_meters', 'liters', 0.00),
(9, 'CNG Nozzle 03', NULL, 6, 0.00, 255.00, 1, 1, 'Titas Gas', 'cubic_meters', 'liters', 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `opening_balances`
--

CREATE TABLE `opening_balances` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `balance_date` date NOT NULL,
  `debit_amount` decimal(15,2) DEFAULT 0.00,
  `credit_amount` decimal(15,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `other_income`
--

CREATE TABLE `other_income` (
  `id` int(11) NOT NULL,
  `income_date` date NOT NULL,
  `income_type` enum('advertisement','space_rental','service_charge','miscellaneous') NOT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `received_from` varchar(100) DEFAULT NULL,
  `voucher_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll`
--

CREATE TABLE `payroll` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `month_year` varchar(7) NOT NULL,
  `basic_salary` decimal(10,2) DEFAULT NULL,
  `allowances` decimal(10,2) DEFAULT 0.00,
  `overtime_amount` decimal(10,2) DEFAULT 0.00,
  `bonus` decimal(10,2) DEFAULT 0.00,
  `deductions` decimal(10,2) DEFAULT 0.00,
  `net_salary` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','paid') DEFAULT 'pending',
  `payment_date` date DEFAULT NULL,
  `voucher_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `payroll`
--

INSERT INTO `payroll` (`id`, `employee_id`, `month_year`, `basic_salary`, `allowances`, `overtime_amount`, `bonus`, `deductions`, `net_salary`, `status`, `payment_date`, `voucher_id`) VALUES
(1, 1, '2026-06', 25000.00, 5000.00, 0.00, 0.00, 2500.00, 27500.00, 'paid', '2026-06-17', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `rent_payments`
--

CREATE TABLE `rent_payments` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `month` varchar(7) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `late_fee` decimal(10,2) DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT 'cash',
  `notes` text DEFAULT NULL,
  `receipt_no` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `rent_payments`
--

INSERT INTO `rent_payments` (`id`, `tenant_id`, `payment_date`, `month`, `amount`, `late_fee`, `payment_method`, `notes`, `receipt_no`, `created_at`) VALUES
(2, 1, '2026-06-16', '2026-01', 10000.00, 0.00, 'cash', '', 'RENT-20260616-431', '2026-06-16 08:27:14'),
(3, 1, '2026-06-16', '2026-02', 10000.00, 0.00, 'cash', '', 'RENT-20260616-665', '2026-06-16 08:27:40'),
(4, 1, '2026-06-16', '2026-03', 10000.00, 0.00, 'cash', '', 'RENT-20260616-895', '2026-06-16 08:27:50'),
(5, 1, '2026-06-16', '2026-04', 10000.00, 0.00, 'cash', '', 'RENT-20260616-044', '2026-06-16 08:28:05'),
(6, 1, '2026-06-16', '2026-05', 10000.00, 0.00, 'cash', '', 'RENT-20260616-119', '2026-06-16 08:28:12'),
(7, 1, '2026-06-16', '2026-06', 10000.00, 0.00, 'cash', '', 'RENT-20260616-021', '2026-06-16 08:29:52'),
(8, 2, '2026-06-17', '2026-04', 6000.00, 0.00, 'cash', '', 'RENT-20260617-525', '2026-06-17 13:14:18'),
(9, 2, '2026-06-17', '2026-05', 6000.00, 0.00, 'cash', '', 'RENT-20260617-822', '2026-06-17 13:14:51');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `invoice_no` varchar(50) NOT NULL,
  `sale_date` datetime DEFAULT current_timestamp(),
  `shift_id` int(11) DEFAULT 1,
  `nozzle_id` int(11) NOT NULL,
  `operator_id` int(11) NOT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `vehicle_number` varchar(50) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `sale_type` enum('cash','credit','advance') NOT NULL,
  `payment_method` enum('cash','card','bkash','nagad','credit') DEFAULT 'cash',
  `card_number` varchar(20) DEFAULT NULL,
  `card_holder_name` varchar(100) DEFAULT NULL,
  `transaction_id` varchar(50) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `quantity_liters` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `vat_amount` decimal(10,2) DEFAULT 0.00,
  `tax_amount` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  `received_amount` decimal(10,2) DEFAULT NULL,
  `advance_used` decimal(12,2) DEFAULT 0.00,
  `advance_payment_id` int(11) DEFAULT NULL,
  `change_amount` decimal(10,2) DEFAULT NULL,
  `credit_due_date` date DEFAULT NULL,
  `is_printed` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `invoice_no`, `sale_date`, `shift_id`, `nozzle_id`, `operator_id`, `customer_name`, `customer_phone`, `vehicle_number`, `remarks`, `customer_id`, `sale_type`, `payment_method`, `card_number`, `card_holder_name`, `transaction_id`, `product_id`, `quantity_liters`, `unit_price`, `subtotal`, `vat_amount`, `tax_amount`, `total_amount`, `received_amount`, `advance_used`, `advance_payment_id`, `change_amount`, `credit_due_date`, `is_printed`) VALUES
(1, 'INV-20260616093336', '2026-06-16 13:33:36', 1, 1, 1, '', '', NULL, NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 1, 150.00, 105.00, 15750.00, 0.00, 0.00, 15750.00, 0.00, 0.00, NULL, -15750.00, NULL, 0),
(2, 'INV-20260616095155', '2026-06-16 13:51:55', 1, 1, 1, 'MUHAMMAD RAFIQUL ALAM ALAM', '01782382140', NULL, NULL, NULL, 'credit', 'cash', NULL, NULL, NULL, 1, 115.00, 105.00, 12075.00, 0.00, 0.00, 12075.00, 0.00, 0.00, NULL, -12075.00, NULL, 0),
(3, 'INV-20260617141310', '2026-06-17 18:13:10', 1, 1, 1, '', '', NULL, NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 1, 10.00, 105.00, 1050.00, 0.00, 0.00, 1050.00, 1100.00, 0.00, NULL, 50.00, NULL, 0),
(4, 'INV-20260618113852', '2026-06-18 15:38:52', 1, 1, 1, '', '', NULL, NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 1, 4761.90, 105.00, 500000.00, 0.00, 0.00, 500000.00, 0.00, 0.00, NULL, -500000.00, NULL, 0),
(5, 'INV-20260618131811', '2026-06-18 17:18:11', 1, 4, 1, '', '', NULL, NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 1, 19.05, 105.00, 2000.00, 0.00, 0.00, 2000.00, 0.00, 0.00, NULL, -2000.00, NULL, 0),
(6, 'INV-20260618132719', '2026-06-18 17:27:19', 1, 1, 1, '', '', NULL, NULL, NULL, 'cash', 'cash', NULL, NULL, NULL, 1, 19.05, 105.00, 2000.00, 0.00, 0.00, 2000.00, 0.00, 0.00, NULL, -2000.00, NULL, 0),
(7, 'INV-20260620144402', '2026-06-20 18:44:02', 1, 1, 1, '', '', 'Dhaka-Metro-Ka-1234', 'ABC', NULL, 'cash', 'cash', NULL, NULL, NULL, 1, 10.00, 105.00, 1050.00, 0.00, 0.00, 1050.00, 0.00, 0.00, NULL, -1050.00, NULL, 0),
(8, 'INV-20260620144450', '2026-06-20 18:44:50', 1, 1, 1, '', '', 'Dhaka-Metro-Ka-1234', 'ABC', NULL, 'cash', 'cash', NULL, NULL, NULL, 1, 10.00, 105.00, 1050.00, 0.00, 0.00, 1050.00, 0.00, 0.00, NULL, -1050.00, NULL, 0),
(9, 'INV-20260620145854', '2026-06-20 18:58:54', 1, 1, 1, '', '', 'Dhaka-Metro-Ka-1234', 'ABCFFFF', NULL, 'cash', 'cash', NULL, NULL, NULL, 1, 10.00, 105.00, 1050.00, 0.00, 0.00, 1050.00, 0.00, 0.00, NULL, -1050.00, NULL, 0),
(10, 'INV-20260620155201', '2026-06-20 19:52:01', 1, 1, 1, '', '', 'Dhaka-Metro-Ka-1234', 'ABCFFFF', NULL, 'cash', 'cash', NULL, NULL, NULL, 1, 10.00, 105.00, 1050.00, 0.00, 0.00, 1050.00, 0.00, 0.00, NULL, -1050.00, NULL, 0),
(15, 'INV-20260621160928', '2026-06-21 20:09:28', 4, 1, 1, '', '', 'Dhaka-Metro-Ka-1234', '', NULL, 'cash', 'cash', NULL, NULL, NULL, 1, 10.00, 105.00, 1050.00, 0.00, 0.00, 1050.00, 0.00, 0.00, NULL, -1050.00, NULL, 0),
(16, 'INV-20260621160953', '2026-06-21 20:09:53', 4, 1, 1, '', '', 'Dhaka-Metro-Ka-1234', '', NULL, 'cash', 'cash', NULL, NULL, NULL, 1, 10.00, 105.00, 1050.00, 0.00, 0.00, 1050.00, 0.00, 0.00, NULL, -1050.00, NULL, 0),
(17, 'INV-20260622062414', '2026-06-22 10:24:14', 4, 1, 1, '', '', 'Dhaka-Metro-Ka-1234', '', NULL, 'cash', 'cash', NULL, NULL, NULL, 1, 10.00, 105.00, 1050.00, 0.00, 0.00, 1050.00, 1500.00, 0.00, NULL, 450.00, NULL, 0),
(18, 'INV-20260622075124', '2026-06-22 11:51:24', 4, 1, 1, '', '', 'Dhaka-Metro-Ka-5550', '', NULL, 'cash', 'card', '5460084203523978', 'MD NURUR RAHMAN', '555', 1, 5.00, 105.00, 525.00, 0.00, 0.00, 525.00, 600.00, 0.00, NULL, 75.00, NULL, 0),
(22, 'INV-20260624134411', '2026-06-24 17:44:11', 6, 1, 1, '', '', 'Dhaka-Metro-Ka-1234', '', NULL, 'cash', 'cash', '', '', '', 1, 10.00, 105.00, 1050.00, 0.00, 0.00, 1050.00, 1100.00, 0.00, NULL, 50.00, NULL, 0),
(23, 'INV-20260625074125', '2026-06-25 11:41:25', 6, 1, 1, '', '', 'Dhaka-Metro-Ka-00034', 'Black Car', NULL, 'cash', 'card', '4134010000520205', 'Rafiqul Alam', '1235', 1, 10.00, 105.00, 1050.00, 0.00, 0.00, 1050.00, 1200.00, 0.00, NULL, 150.00, NULL, 0),
(24, 'INV-20260625104553', '2026-06-25 14:45:53', 6, 1, 1, 'Mr. Forhad', '01789562323', 'Dhaka-Metro-Ka-1234', '', 2, 'cash', 'cash', '', '', '', 1, 10.00, 105.00, 1050.00, 0.00, 0.00, 1050.00, 1100.00, 0.00, NULL, 50.00, NULL, 0),
(25, 'INV-20260625104757', '2026-06-25 14:47:57', 6, 1, 1, 'Mr. Forhad', '01789562323', 'Dhaka-Metro-Ka-00034', 'Black Car', 2, 'cash', 'cash', '', '', '', 1, 10.00, 105.00, 1050.00, 0.00, 0.00, 1050.00, 0.00, 0.00, NULL, -1050.00, NULL, 0),
(27, 'INV-20260625110242', '2026-06-25 15:02:42', 6, 5, 1, 'Mr. Forhad', '01789562323', 'Dhaka-Metro-Ka-00034', 'Black Car', 2, 'cash', 'cash', '', '', '', 1, 10.00, 105.00, 1050.00, 0.00, 0.00, 1050.00, 1100.00, 0.00, NULL, 50.00, NULL, 0),
(28, 'INV-20260625110628', '2026-06-25 15:06:28', 6, 5, 1, '', '', 'Dhaka-Metro-Ka-00034', '', NULL, 'cash', 'cash', '', '', '', 1, 2.00, 105.00, 210.00, 0.00, 0.00, 210.00, 300.00, 0.00, NULL, 90.00, NULL, 0),
(29, 'INV-20260625111143', '2026-06-25 15:11:43', 6, 5, 1, 'Mr. Forhad', '01789562323', 'Dhaka-Metro-Ka-5556', '', 2, 'cash', 'cash', '', '', '', 1, 15.00, 105.00, 1575.00, 0.00, 0.00, 1575.00, 0.00, 0.00, NULL, -1575.00, NULL, 0),
(30, 'INV-20260627072110', '2026-06-27 11:21:10', 7, 5, 1, 'Mr. Forhad', '01789562323', 'Dhaka-Metro Gha-120987', 'White Car', 2, 'cash', 'cash', '', '', '', 1, 19.05, 105.00, 2000.00, 0.00, 0.00, 2000.00, 2500.00, 0.00, NULL, 500.00, NULL, 0),
(31, 'INV-20260627080506', '2026-06-27 12:05:06', 7, 5, 1, 'Mr. Forhad', '01789562323', 'Dhaka-Metro Gha-1200', 'Red Car', 2, 'cash', 'cash', '', '', '', 1, 14.29, 105.00, 1500.00, 0.00, 0.00, 1500.00, 2000.00, 0.00, NULL, 500.00, NULL, 0),
(32, 'INV-20260627081104', '2026-06-27 12:11:04', 7, 5, 1, '', '', 'Dhaka-Metro-Ka-1234', '', NULL, 'cash', 'cash', '', '', '', 1, 8.10, 105.00, 850.00, 0.00, 0.00, 850.00, 1000.00, 0.00, NULL, 150.00, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `shifts`
--

CREATE TABLE `shifts` (
  `id` int(11) NOT NULL,
  `shift_name` enum('Morning','Evening','Night') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `shifts`
--

INSERT INTO `shifts` (`id`, `shift_name`, `start_time`, `end_time`, `is_active`) VALUES
(1, 'Morning', '06:00:00', '14:00:00', 1),
(2, 'Evening', '14:00:00', '22:00:00', 1),
(3, 'Night', '22:00:00', '06:00:00', 1),
(4, 'Morning', '06:00:00', '14:00:00', 1);

-- --------------------------------------------------------

--
-- Table structure for table `shift_closing`
--

CREATE TABLE `shift_closing` (
  `id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `shift_date` date NOT NULL,
  `opening_time` datetime DEFAULT NULL,
  `closing_time` datetime DEFAULT NULL,
  `opened_by` int(11) DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `total_cash_sales` decimal(15,2) DEFAULT 0.00,
  `total_credit_sales` decimal(15,2) DEFAULT 0.00,
  `total_advance_sales` decimal(15,2) DEFAULT 0.00,
  `total_gas_sales` decimal(15,2) DEFAULT 0.00,
  `total_liquid_sales` decimal(12,2) DEFAULT 0.00,
  `total_cng_sales` decimal(12,2) DEFAULT 0.00,
  `total_all_sales` decimal(12,2) DEFAULT 0.00,
  `total_receipts` decimal(15,2) DEFAULT 0.00,
  `total_payments` decimal(15,2) DEFAULT 0.00,
  `net_cash` decimal(15,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `closing_notes` text DEFAULT NULL,
  `status` enum('open','closed','verified') DEFAULT 'open',
  `closed_at` timestamp NULL DEFAULT NULL,
  `opening_cash` decimal(15,2) DEFAULT 0.00,
  `opening_plc_count` decimal(15,2) DEFAULT 0.00,
  `closing_plc_count` decimal(15,2) DEFAULT 0.00,
  `plc_difference` decimal(15,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `shift_closing`
--

INSERT INTO `shift_closing` (`id`, `shift_id`, `shift_date`, `opening_time`, `closing_time`, `opened_by`, `closed_by`, `total_cash_sales`, `total_credit_sales`, `total_advance_sales`, `total_gas_sales`, `total_liquid_sales`, `total_cng_sales`, `total_all_sales`, `total_receipts`, `total_payments`, `net_cash`, `notes`, `closing_notes`, `status`, `closed_at`, `opening_cash`, `opening_plc_count`, `closing_plc_count`, `plc_difference`) VALUES
(1, 1, '2026-06-18', '2026-06-18 10:46:18', '2026-06-18 14:18:07', 1, 1, 0.00, 0.00, 0.00, 4400.00, 0.00, 0.00, 0.00, 1500.00, 0.00, 1500.00, '\nShift closed. Cash in drawer: BDT 1,500.00', NULL, 'closed', '2026-06-18 08:18:07', 0.00, 0.00, 0.00, 0.00),
(2, 2, '2026-06-18', '2026-06-18 15:36:45', '2026-06-21 10:26:01', 1, 1, 505100.00, 0.00, 0.00, 1100.00, 504000.00, 1100.00, 505100.00, 1500.00, 0.00, 1500.00, '', '', 'closed', '2026-06-21 04:26:01', 0.00, 0.00, 0.00, 0.00),
(3, 1, '2026-06-21', '2026-06-21 10:36:09', '2026-06-21 10:38:07', 1, 1, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1500.00, 0.00, 1500.00, '', '', 'closed', '2026-06-21 04:38:07', 0.00, 0.00, 0.00, 0.00),
(4, 1, '2026-06-21', '2026-06-21 13:32:24', '2026-06-22 11:58:57', 1, 1, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 4000.00, 0.00, 4000.00, '', '', 'closed', '2026-06-22 05:58:57', 1500.00, 0.00, 0.00, 0.00),
(5, 2, '2026-06-24', '2026-06-24 15:54:44', '2026-06-24 17:39:03', 1, 1, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1500.00, 0.00, 1500.00, 'dsf sf ', '', 'closed', '2026-06-24 11:39:03', 2500.00, 0.00, 0.00, 0.00),
(6, 3, '2026-06-24', '2026-06-24 17:39:23', '2026-06-27 11:19:42', 1, 1, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 2500.00, 0.00, 2500.00, '', '', 'closed', '2026-06-27 05:19:42', 1500.00, 0.00, 0.00, 0.00),
(7, 1, '2026-06-27', '2026-06-27 11:20:24', '2026-06-27 18:53:01', 1, 1, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1500.00, 0.00, 1500.00, '', 'Thanks', 'closed', '2026-06-27 12:53:01', 2500.00, 0.00, 200001.00, 0.00),
(8, 3, '2026-06-27', '2026-06-27 18:54:00', '2026-06-27 19:32:35', 1, 1, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 2500.00, 0.00, 2500.00, 'open', 'Thanks', 'closed', '2026-06-27 13:32:35', 1500.00, 200001.00, 200900.00, 899.00);

-- --------------------------------------------------------

--
-- Table structure for table `shift_schedule`
--

CREATE TABLE `shift_schedule` (
  `id` int(11) NOT NULL,
  `shift_name` varchar(50) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `shift_schedule`
--

INSERT INTO `shift_schedule` (`id`, `shift_name`, `start_time`, `end_time`, `is_active`, `created_at`) VALUES
(1, 'Morning', '08:01:00', '16:00:00', 1, '2026-06-13 08:22:26'),
(2, 'Evening', '16:01:00', '23:59:59', 1, '2026-06-13 08:22:26'),
(3, 'Night', '00:00:00', '08:00:00', 1, '2026-06-13 08:22:26');

-- --------------------------------------------------------

--
-- Table structure for table `shift_stock`
--

CREATE TABLE `shift_stock` (
  `id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `tank_id` int(11) NOT NULL,
  `opening_stock` decimal(10,2) NOT NULL,
  `closing_stock` decimal(10,2) NOT NULL,
  `receiving_quantity` decimal(10,2) DEFAULT 0.00,
  `sale_quantity` decimal(10,2) DEFAULT 0.00,
  `loss_quantity` decimal(10,2) DEFAULT 0.00,
  `actual_dip_reading` decimal(10,2) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `shift_stock`
--

INSERT INTO `shift_stock` (`id`, `shift_id`, `tank_id`, `opening_stock`, `closing_stock`, `receiving_quantity`, `sale_quantity`, `loss_quantity`, `actual_dip_reading`, `recorded_by`) VALUES
(1, 1, 1, 18364.00, 18364.00, 0.00, 0.00, 0.00, NULL, 1),
(2, 2, 1, 18364.00, 13524.00, 0.00, 0.00, 0.00, NULL, 1),
(3, 2, 2, 0.00, 0.00, 0.00, 0.00, 0.00, NULL, 1),
(4, 3, 1, 13524.00, 13524.00, 0.00, 0.00, 0.00, NULL, 1),
(5, 3, 2, 0.00, 0.00, 0.00, 0.00, 0.00, NULL, 1),
(6, 4, 1, 13524.00, 13489.00, 0.00, 0.00, 0.00, NULL, 1),
(7, 4, 2, 0.00, 0.00, 0.00, 0.00, 0.00, NULL, 1),
(8, 5, 1, 13489.00, 13489.00, 0.00, 0.00, 0.00, NULL, 1),
(9, 5, 2, 0.00, 0.00, 0.00, 0.00, 0.00, NULL, 1),
(10, 6, 1, 13489.00, 13422.00, 0.00, 0.00, 0.00, NULL, 1),
(11, 6, 2, 0.00, 0.00, 0.00, 0.00, 0.00, NULL, 1),
(12, 7, 1, 13422.00, 13380.56, 0.00, 0.00, 0.00, NULL, 1),
(13, 7, 2, 0.00, 0.00, 0.00, 0.00, 0.00, NULL, 1),
(14, 8, 1, 13380.56, 13380.56, 0.00, 0.00, 0.00, NULL, 1),
(15, 8, 2, 0.00, 0.00, 0.00, 0.00, 0.00, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `stock_ledger`
--

CREATE TABLE `stock_ledger` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `tank_id` int(11) NOT NULL,
  `transaction_date` datetime DEFAULT current_timestamp(),
  `transaction_type` enum('opening','receiving','sale','adjustment','transfer_in','transfer_out') NOT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `in_quantity` decimal(10,2) DEFAULT 0.00,
  `out_quantity` decimal(10,2) DEFAULT 0.00,
  `balance_quantity` decimal(10,2) NOT NULL,
  `unit_cost` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `stock_ledger`
--

INSERT INTO `stock_ledger` (`id`, `product_id`, `tank_id`, `transaction_date`, `transaction_type`, `reference_no`, `in_quantity`, `out_quantity`, `balance_quantity`, `unit_cost`) VALUES
(1, 1, 1, '2026-06-16 13:33:36', 'sale', 'INV-20260616093336', 0.00, 150.00, 8590.00, NULL),
(2, 1, 1, '2026-06-16 13:51:55', 'sale', 'INV-20260616095155', 0.00, 115.00, 8475.00, NULL),
(3, 1, 1, '2026-06-16 13:56:06', 'receiving', 'RCV-20260616095606', 4950.00, 0.00, 13425.00, 69.00),
(5, 1, 1, '2026-06-17 18:13:10', 'sale', 'INV-20260617141310', 0.00, 10.00, 13415.00, NULL),
(7, 1, 1, '2026-06-17 18:39:47', 'receiving', 'RCV-20260617143947', 4950.00, 0.00, 18365.00, 120.00),
(8, 1, 1, '2026-06-17 18:53:27', 'adjustment', 'LEAK-1', 0.00, 1.00, 18364.00, 100.00),
(9, 1, 1, '2026-06-18 15:38:52', 'sale', 'INV-20260618113852', 0.00, 4761.90, 13602.10, NULL),
(10, 1, 1, '2026-06-18 17:18:11', 'sale', 'INV-20260618131811', 0.00, 19.05, 13583.05, NULL),
(11, 1, 1, '2026-06-18 17:27:19', 'sale', 'INV-20260618132719', 0.00, 19.05, 13564.00, NULL),
(12, 1, 1, '2026-06-20 18:44:02', 'sale', 'INV-20260620144402', 0.00, 10.00, 13554.00, NULL),
(13, 1, 1, '2026-06-20 18:44:51', 'sale', 'INV-20260620144450', 0.00, 10.00, 13544.00, NULL),
(14, 1, 1, '2026-06-20 18:58:54', 'sale', 'INV-20260620145854', 0.00, 10.00, 13534.00, NULL),
(15, 1, 1, '2026-06-20 19:52:01', 'sale', 'INV-20260620155201', 0.00, 10.00, 13524.00, NULL),
(16, 1, 1, '2026-06-21 20:09:28', 'sale', 'INV-20260621160928', 0.00, 10.00, 13514.00, NULL),
(17, 1, 1, '2026-06-21 20:09:53', 'sale', 'INV-20260621160953', 0.00, 10.00, 13504.00, NULL),
(18, 1, 1, '2026-06-22 10:24:14', 'sale', 'INV-20260622062414', 0.00, 10.00, 13494.00, NULL),
(19, 1, 1, '2026-06-22 11:51:24', 'sale', 'INV-20260622075124', 0.00, 5.00, 13489.00, NULL),
(20, 1, 1, '2026-06-24 17:44:11', 'sale', 'INV-20260624134411', 0.00, 10.00, 13479.00, NULL),
(21, 1, 1, '2026-06-25 11:41:25', 'sale', 'INV-20260625074125', 0.00, 10.00, 13469.00, NULL),
(22, 1, 1, '2026-06-25 14:45:53', 'sale', 'INV-20260625104553', 0.00, 10.00, 13459.00, NULL),
(23, 1, 1, '2026-06-25 14:47:57', 'sale', 'INV-20260625104757', 0.00, 10.00, 13449.00, NULL),
(25, 1, 1, '2026-06-25 15:02:43', 'sale', 'INV-20260625110242', 0.00, 10.00, 13439.00, NULL),
(26, 1, 1, '2026-06-25 15:06:28', 'sale', 'INV-20260625110628', 0.00, 2.00, 13437.00, NULL),
(27, 1, 1, '2026-06-25 15:11:43', 'sale', 'INV-20260625111143', 0.00, 15.00, 13422.00, NULL),
(28, 1, 1, '2026-06-27 11:21:10', 'sale', 'INV-20260627072110', 0.00, 19.05, 13402.95, NULL),
(29, 1, 1, '2026-06-27 12:05:06', 'sale', 'INV-20260627080506', 0.00, 14.29, 13388.66, NULL),
(30, 1, 1, '2026-06-27 12:11:04', 'sale', 'INV-20260627081104', 0.00, 8.10, 13380.56, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `supplier_code` varchar(50) NOT NULL,
  `supplier_name` varchar(100) NOT NULL,
  `company_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `advance_balance` decimal(15,2) DEFAULT 0.00,
  `credit_limit` decimal(15,2) DEFAULT 0.00,
  `payment_terms` int(11) DEFAULT 30,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `supplier_code`, `supplier_name`, `company_name`, `phone`, `email`, `address`, `contact_person`, `opening_balance`, `current_balance`, `advance_balance`, `credit_limit`, `payment_terms`, `is_active`, `created_at`) VALUES
(1, 'SUP-001', 'Padma Oil Company', 'Padma Oil Ltd', '01700000001', NULL, 'Dhaka, Bangladesh', NULL, 0.00, 0.00, 0.00, 500000.00, 30, 1, '2026-06-14 08:10:24'),
(2, 'SUP-002', 'Jamuna Fuel Supply', 'Jamuna Group', '01700000002', NULL, 'Chattogram, Bangladesh', NULL, 0.00, 0.00, 0.00, 300000.00, 15, 1, '2026-06-14 08:10:24'),
(3, 'SUP-003', 'Meghna Petroleum', 'Meghna Group', '01700000003', NULL, 'Khulna, Bangladesh', NULL, 0.00, 0.00, 0.00, 400000.00, 45, 1, '2026-06-14 08:10:24'),
(4, 'SUP-004', 'Titas GAS', 'Titas GAS Company Ltd', '01782382140', 'rafiqulalam2@gmail.com', '102, Shukrabad, Dhanmondi\r\nDhaka', 'MUHAMMAD RAFIQUL ALAM', 0.00, 0.00, 0.00, 200000.00, 30, 1, '2026-06-15 09:12:39'),
(7, 'SUP-005', 'MS T-MOBIL CO ', 'MS T-MOBIL CO ', '', '', '', '', 0.00, 0.00, 0.00, 0.00, 30, 1, '2026-06-18 12:37:39');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_payments`
--

CREATE TABLE `supplier_payments` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` enum('cash','bank','cheque') DEFAULT 'cash',
  `reference_no` varchar(50) DEFAULT NULL,
  `receiving_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `voucher_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `supplier_payments`
--

INSERT INTO `supplier_payments` (`id`, `supplier_id`, `payment_date`, `amount`, `payment_method`, `reference_no`, `receiving_id`, `notes`, `voucher_id`, `created_by`, `created_at`) VALUES
(1, 4, '2026-06-16', 103500.00, 'cash', '', NULL, '', NULL, 1, '2026-06-16 08:08:28');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `updated_at`) VALUES
(11, 'company_name', 'Daffodil Enterprise', '2026-06-11 11:28:15'),
(12, 'company_phone', '+880 1234 567890', '2026-06-11 05:42:34'),
(13, 'company_email', 'info@ffenterprise.com', '2026-06-11 05:42:34'),
(14, 'company_address', 'Dhaka, Bangladesh', '2026-06-11 05:42:34'),
(15, 'vat_reg_no', '123456789', '2026-06-11 05:42:34'),
(16, 'tax_percentage', '2', '2026-06-11 05:42:34'),
(17, 'vat_percentage', '5', '2026-06-11 05:42:34'),
(18, 'currency_symbol', 'TK', '2026-06-11 05:42:34'),
(19, 'invoice_footer', '*** THANK YOU ***', '2026-06-11 05:42:34'),
(20, 'low_stock_alert', '500', '2026-06-11 05:42:34'),
(21, 'last_plc_reading', '200900', '2026-06-27 13:32:35');

-- --------------------------------------------------------

--
-- Table structure for table `tanks`
--

CREATE TABLE `tanks` (
  `id` int(11) NOT NULL,
  `tank_name` varchar(50) NOT NULL,
  `product_id` int(11) NOT NULL,
  `capacity_liters` decimal(10,2) NOT NULL,
  `current_stock_liters` decimal(10,2) DEFAULT 0.00,
  `calibration_factor` decimal(10,4) DEFAULT 1.0000,
  `is_pipeline` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `tanks`
--

INSERT INTO `tanks` (`id`, `tank_name`, `product_id`, `capacity_liters`, `current_stock_liters`, `calibration_factor`, `is_pipeline`, `is_active`) VALUES
(1, 'Diesel Tank-01', 1, 20000.00, 13380.56, 5.2345, 0, 1),
(2, 'CNG Pipeline 01', 6, 0.00, 0.00, 1.0000, 0, 1);

-- --------------------------------------------------------

--
-- Table structure for table `tank_stock_readings`
--

CREATE TABLE `tank_stock_readings` (
  `id` int(11) NOT NULL,
  `tank_id` int(11) NOT NULL,
  `reading_date` datetime NOT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `dip_reading` decimal(12,2) NOT NULL,
  `physical_stock` decimal(12,2) NOT NULL,
  `system_stock` decimal(12,2) NOT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tenants`
--

CREATE TABLE `tenants` (
  `id` int(11) NOT NULL,
  `tenant_name` varchar(100) NOT NULL,
  `shop_no` varchar(50) DEFAULT NULL,
  `monthly_rent` decimal(10,2) NOT NULL,
  `agreement_start` date DEFAULT NULL,
  `agreement_end` date DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `security_deposit` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `tenants`
--

INSERT INTO `tenants` (`id`, `tenant_name`, `shop_no`, `monthly_rent`, `agreement_start`, `agreement_end`, `phone`, `is_active`, `email`, `address`, `security_deposit`) VALUES
(1, 'Rafiqul Alam', 'Shop-01', 10000.00, '2026-01-01', '2026-12-31', '', 1, '', '', 0.00),
(2, 'Razib', 'Shop-02', 6000.00, '2026-04-01', '2026-07-31', '01782382140', 1, '', '102, Shukrabad, Dhanmondi\r\nDhaka', 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('super_admin','owner','accountant','station_manager','cashier','nozzle_operator','hr_officer','store_keeper','auditor') NOT NULL DEFAULT 'cashier',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `phone`, `role`, `is_active`, `created_at`) VALUES
(1, 'admin', '0192023a7bbd73250516f069df18b500', 'Super Admin', '', '', 'super_admin', 1, '2026-06-11 05:04:37');

-- --------------------------------------------------------

--
-- Table structure for table `vouchers`
--

CREATE TABLE `vouchers` (
  `id` int(11) NOT NULL,
  `voucher_no` varchar(50) NOT NULL,
  `voucher_type` varchar(50) DEFAULT 'journal',
  `date` date NOT NULL,
  `narration` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `status` enum('draft','approved','rejected') DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `vouchers`
--

INSERT INTO `vouchers` (`id`, `voucher_no`, `voucher_type`, `date`, `narration`, `created_by`, `approved_by`, `status`, `created_at`) VALUES
(1, 'CASH-20260616093336789', 'receipt', '2026-06-16', 'Cash sale - Invoice: INV-20260616093336 - Amount: 15750', 1, NULL, 'approved', '2026-06-16 07:33:36'),
(2, 'CREDIT-20260616095155835', 'journal', '2026-06-16', 'Credit sale to MUHAMMAD RAFIQUL ALAM ALAM - Invoice: INV-20260616095155 - Amount: 12075', 1, NULL, 'approved', '2026-06-16 07:51:55'),
(3, 'RECV-20260616095302791', 'receipt', '2026-06-16', 'Payment received from MUHAMMAD RAFIQUL ALAM ALAM - Receipt: PAY-20260616095302276 - Amount: BDT 12,000.00', 1, NULL, 'approved', '2026-06-16 07:53:02'),
(4, 'PURCH-20260616095606', 'payment', '2026-06-16', 'Fuel purchase from Jamuna Fuel Supply - RCV-20260616095606 (Cash)', 1, NULL, 'approved', '2026-06-16 07:56:06'),
(5, 'PURCH-20260616095655', 'journal', '2026-06-16', 'Credit purchase from Titas GAS - RCV-20260616095655', 1, NULL, 'approved', '2026-06-16 07:56:55'),
(6, 'SUPPAY-20260616100828', 'payment', '2026-06-16', 'Payment to supplier: Titas GAS - Amount: 103500', 1, NULL, 'approved', '2026-06-16 08:08:28'),
(7, 'RENT-20260616101825', 'receipt', '2026-06-16', 'Rent collection from tenant ID: 1 - Month: June 2026', 1, NULL, 'approved', '2026-06-16 08:18:25'),
(8, 'RENT-20260616102714948', 'receipt', '2026-06-16', 'Rent collection from  - Month: January 2026 - Receipt: RENT-20260616-431', 1, NULL, 'approved', '2026-06-16 08:27:14'),
(9, 'RENT-20260616102740513', 'receipt', '2026-06-16', 'Rent collection from  - Month: February 2026 - Receipt: RENT-20260616-665', 1, NULL, 'approved', '2026-06-16 08:27:40'),
(10, 'RENT-20260616102750172', 'receipt', '2026-06-16', 'Rent collection from  - Month: March 2026 - Receipt: RENT-20260616-895', 1, NULL, 'approved', '2026-06-16 08:27:50'),
(11, 'RENT-20260616102805521', 'receipt', '2026-06-16', 'Rent collection from  - Month: April 2026 - Receipt: RENT-20260616-044', 1, NULL, 'approved', '2026-06-16 08:28:05'),
(12, 'RENT-20260616102812363', 'receipt', '2026-06-16', 'Rent collection from  - Month: May 2026 - Receipt: RENT-20260616-119', 1, NULL, 'approved', '2026-06-16 08:28:12'),
(13, 'RENT-20260616102952794', 'receipt', '2026-06-16', 'Rent collection from  - Month: June 2026 - Receipt: RENT-20260616-021', 1, NULL, 'approved', '2026-06-16 08:29:52'),
(14, 'TEST-001', 'journal', '2026-06-17', 'Test voucher entry', 1, NULL, 'approved', '2026-06-17 08:29:01'),
(16, 'VCH-20260617111343922', 'payment', '2026-06-17', 'Purchase Items for Maintenance', 1, NULL, 'approved', '2026-06-17 09:13:43'),
(17, 'VCH-20260617111821825', 'payment', '2026-06-17', 'Purchase Items for Maintenance', 1, NULL, 'approved', '2026-06-17 09:18:21'),
(18, 'VCH-20260617112539', 'journal', '2026-06-17', 'test', 1, NULL, 'approved', '2026-06-17 09:25:39'),
(19, 'VCH-20260617115210', 'payment', '2026-06-17', 'test2', 1, NULL, 'approved', '2026-06-17 09:52:10'),
(20, 'VCH-20260617121929', 'journal', '2026-06-17', 'Loan from DBBL Bank', 1, NULL, 'approved', '2026-06-17 10:19:29'),
(21, 'VCH-20260617122147', 'journal', '2026-06-17', 'Loan Adjustment and wrong entry correction', 1, NULL, 'approved', '2026-06-17 10:21:47'),
(22, 'CASH-20260617141310895', 'receipt', '2026-06-17', 'Cash sale - Invoice: INV-20260617141310 - Amount: 1050', 1, NULL, 'approved', '2026-06-17 12:13:10'),
(23, 'CREDIT-20260617141457973', 'journal', '2026-06-17', 'Credit sale to Mr. Forhad - Invoice: INV-20260617141457 - Amount: 6500', 1, NULL, 'approved', '2026-06-17 12:14:57'),
(24, 'PURCH-20260617143947', 'payment', '2026-06-17', 'Fuel purchase from Padma Oil Company - RCV-20260617143947 (Cash)', 1, NULL, 'approved', '2026-06-17 12:39:47'),
(25, 'STKADJ-20260617145327197', 'journal', '2026-06-17', 'Stock Loss adjustment - Tank: Diesel Tank-01 - Variance: 1.00 Liters @ 100.00/L', 1, NULL, 'approved', '2026-06-17 12:53:27'),
(26, 'RECV-20260617150449706', 'receipt', '2026-06-17', 'Payment received from Mr. Forhad - Receipt: PAY-20260617150449150 - Amount: BDT 5,000.00', 1, NULL, 'approved', '2026-06-17 13:04:49'),
(27, 'RENT-20260617151418498', 'receipt', '2026-06-17', 'Rent collection from Razib - Month: April 2026 - Receipt: RENT-20260617-525', 1, NULL, 'approved', '2026-06-17 13:14:18'),
(28, 'RENT-20260617151451553', 'receipt', '2026-06-17', 'Rent collection from Razib - Month: May 2026 - Receipt: RENT-20260617-822', 1, NULL, 'approved', '2026-06-17 13:14:51'),
(29, 'CNG-CASH-20260618081941989', 'receipt', '2026-06-18', 'CNG sale - CNG-20260618081941 - Quantity: 5.56 m³ - Amount: BDT 500.00', 1, NULL, 'approved', '2026-06-18 06:19:41'),
(30, 'CNG-CASH-20260618082006581', 'receipt', '2026-06-18', 'CNG sale - CNG-20260618082006 - Quantity: 5.56 m³ - Amount: BDT 500.00', 1, NULL, 'approved', '2026-06-18 06:20:06'),
(31, 'CNG-CASH-20260618082038386', 'receipt', '2026-06-18', 'CNG sale - CNG-20260618082038 - Quantity: 5.56 m³ - Amount: BDT 500.00', 1, NULL, 'approved', '2026-06-18 06:20:38'),
(32, 'CNG-CASH-20260618082848161', 'receipt', '2026-06-18', 'CNG sale - CNG-20260618082848 - Quantity: 5.56 m³ - Amount: BDT 500.00', 1, NULL, 'approved', '2026-06-18 06:28:48'),
(33, 'CNG-CASH-20260618084947854', 'receipt', '2026-06-18', 'CNG sale - CNG-20260618084947 - Quantity: 5.56 m³ - Amount: BDT 500.00', 1, NULL, 'approved', '2026-06-18 06:49:47'),
(34, 'CNG-CASH-20260618085403838', 'receipt', '2026-06-18', 'CNG sale - CNG-20260618085403 - Quantity: 5.56 m³ - Amount: BDT 500.00', 1, NULL, 'approved', '2026-06-18 06:54:03'),
(35, 'CNG-CASH-20260618085501430', 'receipt', '2026-06-18', 'CNG sale - CNG-20260618085501 - Quantity: 5.56 m³ - Amount: BDT 500.00', 1, NULL, 'approved', '2026-06-18 06:55:01'),
(36, 'BONUS-20260618093405338', 'payment', '2026-06-18', 'Bonus payment to MUHAMMAD RAFIQUL ALAM - Amount: BDT 1,500.00', 1, NULL, 'approved', '2026-06-18 07:34:05'),
(37, 'CNG-CASH-20260618093509502', 'receipt', '2026-06-18', 'CNG sale - CNG-20260618093509579 - Quantity: 4.44 m³ - Amount: BDT 400.00', 1, NULL, 'approved', '2026-06-18 07:35:09'),
(38, 'CNG-CASH-20260618093542873', 'receipt', '2026-06-18', 'CNG sale - CNG-20260618093542713 - Quantity: 5.56 m³ - Amount: BDT 500.00', 1, NULL, 'approved', '2026-06-18 07:35:42'),
(39, 'CASH-20260618113852496', 'receipt', '2026-06-18', 'Cash sale - Invoice: INV-20260618113852 - Amount: 500000', 1, NULL, 'approved', '2026-06-18 09:38:52'),
(40, 'ADV-C-20260618114052275', 'receipt', '2026-06-18', 'Advance received from Mr. Forhad - Amount: BDT 10,000.00', 1, NULL, 'approved', '2026-06-18 09:40:52'),
(41, 'CASH-20260618131811256', 'receipt', '2026-06-18', 'Cash sale - Invoice: INV-20260618131811 - Amount: 2000', 1, NULL, 'approved', '2026-06-18 11:18:11'),
(42, 'CNG-CASH-20260618131836277', 'receipt', '2026-06-18', 'CNG sale - CNG-20260618131836964 - Quantity: 4.44 m³ - Amount: BDT 400.00', 1, NULL, 'approved', '2026-06-18 11:18:36'),
(43, 'CNG-CASH-20260618131921341', 'receipt', '2026-06-18', 'CNG sale - CNG-20260618131921709 - Quantity: 4.44 m³ - Amount: BDT 400.00', 1, NULL, 'approved', '2026-06-18 11:19:21'),
(44, 'CNG-CASH-20260618132521659', 'receipt', '2026-06-18', 'CNG sale - CNG-20260618132520915 - Quantity: 3.33 m³ - Amount: BDT 300.00', 1, NULL, 'approved', '2026-06-18 11:25:21'),
(45, 'CASH-20260618132719329', 'receipt', '2026-06-18', 'Cash sale - Invoice: INV-20260618132719 - Amount: 2000', 1, NULL, 'approved', '2026-06-18 11:27:19'),
(46, 'PURCHASE-20260618142458585', 'journal', '2026-06-18', 'Purchase from Jamuna Fuel Supply - Invoice: 12562 - Amount: BDT 35,800.00', 1, NULL, 'approved', '2026-06-18 12:24:58'),
(47, 'ITEM-CASH-20260618143100350', 'receipt', '2026-06-18', 'Item sale - ITEM-20260618143100197 - Amount: BDT 300.00', 1, NULL, 'approved', '2026-06-18 12:31:00'),
(48, 'PURCHASE-20260618143826989', 'journal', '2026-06-18', 'Purchase from MS T-MOBIL CO  - Invoice: 100010 - Amount: BDT 750.00', 1, NULL, 'approved', '2026-06-18 12:38:26'),
(49, 'CASH-20260620144402171', 'receipt', '2026-06-20', 'Cash sale - Invoice: INV-20260620144402 - Amount: 1050', 1, NULL, 'approved', '2026-06-20 12:44:02'),
(50, 'CASH-20260620144451740', 'receipt', '2026-06-20', 'Cash sale - Invoice: INV-20260620144450 - Amount: 1050', 1, NULL, 'approved', '2026-06-20 12:44:51'),
(51, 'CASH-20260620145854306', 'receipt', '2026-06-20', 'Cash sale - Invoice: INV-20260620145854 - Amount: 1050', 1, NULL, 'approved', '2026-06-20 12:58:54'),
(52, 'CNG-CASH-20260620150014724', 'receipt', '2026-06-20', 'CNG sale - CNG-20260620150014877 - Quantity: 2.78 m³ - Amount: BDT 250.00', 1, NULL, 'approved', '2026-06-20 13:00:14'),
(53, 'CNG-CASH-20260620150629296', 'receipt', '2026-06-20', 'CNG sale - CNG-20260620150629162 - Quantity: 1.11 m³ - Amount: BDT 100.00', 1, NULL, 'approved', '2026-06-20 13:06:29'),
(54, 'CNG-CASH-20260620152556978', 'receipt', '2026-06-20', 'CNG sale - CNG-20260620152556822 - Quantity: 1.61 m³ - Amount: BDT 145.00', 1, NULL, 'approved', '2026-06-20 13:25:56'),
(55, 'CNG-CASH-20260620153217550', 'receipt', '2026-06-20', 'CNG sale - CNG-20260620153217247 - Quantity: 5.00 m³ - Amount: BDT 450.00', 1, NULL, 'approved', '2026-06-20 13:32:17'),
(56, 'CNG-CASH-20260620154301428', 'receipt', '2026-06-20', 'CNG sale - CNG-20260620154301392 - Quantity: 8.89 m³ - Amount: BDT 800.00', 1, NULL, 'approved', '2026-06-20 13:43:01'),
(57, 'CNG-CASH-20260620154821458', 'receipt', '2026-06-20', 'CNG sale - CNG-20260620154821960 - Quantity: 2.78 m³ - Amount: BDT 250.00', 1, NULL, 'approved', '2026-06-20 13:48:21'),
(58, 'CASH-20260620155201299', 'receipt', '2026-06-20', 'Cash sale - Invoice: INV-20260620155201 - Amount: 1050', 1, NULL, 'approved', '2026-06-20 13:52:01'),
(59, 'CNG-CASH-20260621093316944', 'receipt', '2026-06-21', 'CNG sale - CNG-20260621093316352 - Quantity: 2.22 m³ - Amount: BDT 200.00', 1, NULL, 'approved', '2026-06-21 07:33:16'),
(60, 'CNG-CASH-20260621110359151', 'receipt', '2026-06-21', 'CNG sale - CNG-20260621110359915 - Quantity: 27.78 m³ - Amount: BDT 2,500.00', 1, NULL, 'approved', '2026-06-21 09:03:59'),
(61, 'ITEM-CASH-20260621111746722', 'receipt', '2026-06-21', 'Item sale - ITEM-20260621111746748 - Amount: BDT 450.00', 1, NULL, 'approved', '2026-06-21 09:17:46'),
(62, 'CNG-CASH-20260621112640789', 'receipt', '2026-06-21', 'CNG sale - CNG-20260621112640678 - Quantity: 3.89 m³ - Amount: BDT 350.00', 1, NULL, 'approved', '2026-06-21 09:26:40'),
(63, 'CASH-20260621160928522', 'receipt', '2026-06-21', 'Cash sale - Invoice: INV-20260621160928 - Amount: 1050', 1, NULL, 'approved', '2026-06-21 14:09:28'),
(64, 'CASH-20260621160953575', 'receipt', '2026-06-21', 'Cash sale - Invoice: INV-20260621160953 - Amount: 1050', 1, NULL, 'approved', '2026-06-21 14:09:53'),
(65, 'CASH-20260622062414641', 'receipt', '2026-06-22', 'Cash sale - Invoice: INV-20260622062414 - Amount: 1050', 1, NULL, 'approved', '2026-06-22 04:24:14'),
(66, 'CNG-CASH-20260622074636636', 'receipt', '2026-06-22', 'CNG sale - CNG-20260622074636821 - Quantity: 4.44 m³ - Amount: BDT 400.00', 1, NULL, 'approved', '2026-06-22 05:46:36'),
(67, 'CNG-CASH-20260622074712369', 'receipt', '2026-06-22', 'CNG sale - CNG-20260622074712158 - Quantity: 3.89 m³ - Amount: BDT 350.00', 1, NULL, 'approved', '2026-06-22 05:47:12'),
(68, 'CASH-20260622075124957', 'receipt', '2026-06-22', 'Cash sale - Invoice: INV-20260622075124 - Amount: 525 - Payment: card', 1, NULL, 'approved', '2026-06-22 05:51:24'),
(69, 'ITEM-CASH-20260622075701546', 'receipt', '2026-06-22', 'Item sale - ITEM-20260622075701812 - Amount: BDT 1,050.00', 1, NULL, 'approved', '2026-06-22 05:57:01'),
(70, 'CASH-20260624134411956', 'receipt', '2026-06-24', 'Cash sale - Invoice: INV-20260624134411 - Amount: 1050 - Payment: cash', 1, NULL, 'approved', '2026-06-24 11:44:11'),
(71, 'CASH-20260625074125246', 'receipt', '2026-06-25', 'Cash sale - Invoice: INV-20260625074125 - Amount: 1050 - Payment: card', 1, NULL, 'approved', '2026-06-25 05:41:25'),
(72, 'CASH-20260625104553451', 'receipt', '2026-06-25', 'Cash sale - Invoice: INV-20260625104553 - Amount: 1050 - Payment: cash', 1, NULL, 'approved', '2026-06-25 08:45:53'),
(73, 'CASH-20260625104757229', 'receipt', '2026-06-25', 'Cash sale - Invoice: INV-20260625104757 - Amount: 1050 - Payment: cash', 1, NULL, 'approved', '2026-06-25 08:47:57'),
(74, 'CASH-20260625110243415', 'receipt', '2026-06-25', 'Cash sale - Customer: Mr. Forhad - Invoice: INV-20260625110242 - Amount: 1050 - Payment: cash', 1, NULL, 'approved', '2026-06-25 09:02:43'),
(75, 'CASH-20260625110628780', 'receipt', '2026-06-25', 'Cash sale - Invoice: INV-20260625110628 - Amount: 210 - Payment: cash', 1, NULL, 'approved', '2026-06-25 09:06:28'),
(76, 'CASH-20260625111143442', 'receipt', '2026-06-25', 'Cash sale - Customer: Mr. Forhad - Invoice: INV-20260625111143 - Amount: 1575 - Payment: cash', 1, NULL, 'approved', '2026-06-25 09:11:43'),
(77, 'CASH-20260627072110367', 'receipt', '2026-06-27', 'Cash sale - Customer: Mr. Forhad - Invoice: INV-20260627072110 - Amount: 2000 - Payment: cash', 1, NULL, 'approved', '2026-06-27 05:21:10'),
(78, 'CASH-20260627080506274', 'receipt', '2026-06-27', 'Cash sale - Customer: Mr. Forhad - Invoice: INV-20260627080506 - Amount: 1500 - Payment: cash', 1, NULL, 'approved', '2026-06-27 06:05:06'),
(79, 'CASH-20260627081105149', 'receipt', '2026-06-27', 'Cash sale - Invoice: INV-20260627081104 - Amount: 850 - Payment: cash', 1, NULL, 'approved', '2026-06-27 06:11:05'),
(80, 'CNG-CASH-20260627081135939', 'receipt', '2026-06-27', 'CNG sale - Customer: Mr. Forhad - CNG-20260627081135449 - Quantity: 9.44 m³ - Amount: BDT 850.00', 1, NULL, 'approved', '2026-06-27 06:11:35'),
(81, 'CNG-CASH-20260627081408155', 'receipt', '2026-06-27', 'CNG sale - Customer: Mr. Forhad - CNG-20260627081408981 - Quantity: 2.22 m³ - Amount: BDT 200.00', 1, NULL, 'approved', '2026-06-27 06:14:08'),
(82, 'CNG-CASH-20260627082415109', 'receipt', '2026-06-27', 'CNG sale - CNG-20260627082415512 - Quantity: 13.33 m³ - Amount: BDT 1,200.00', 1, NULL, 'approved', '2026-06-27 06:24:15'),
(83, 'ITEM-CASH-20260627082442750', 'receipt', '2026-06-27', 'Item sale - ITEM-20260627082442645 - Amount: BDT 450.00', 1, NULL, 'approved', '2026-06-27 06:24:42');

-- --------------------------------------------------------

--
-- Table structure for table `voucher_items`
--

CREATE TABLE `voucher_items` (
  `id` int(11) NOT NULL,
  `voucher_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `debit_amount` decimal(15,2) DEFAULT 0.00,
  `credit_amount` decimal(15,2) DEFAULT 0.00,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `voucher_items`
--

INSERT INTO `voucher_items` (`id`, `voucher_id`, `account_id`, `debit_amount`, `credit_amount`, `description`) VALUES
(1, 1, 1, 15750.00, 0.00, 'Cash sale - Invoice: INV-20260616093336'),
(2, 1, 8, 0.00, 15750.00, 'Fuel sale revenue - Invoice: INV-20260616093336'),
(3, 2, 4, 12075.00, 0.00, 'Credit sale to MUHAMMAD RAFIQUL ALAM ALAM - Invoice: INV-20260616095155'),
(4, 2, 8, 0.00, 12075.00, 'Fuel sale revenue - Invoice: INV-20260616095155'),
(5, 3, 1, 12000.00, 0.00, 'Payment received from MUHAMMAD RAFIQUL ALAM ALAM - Receipt: PAY-20260616095302276'),
(6, 3, 4, 0.00, 12000.00, 'Customer payment applied to credit sales - Invoice: INV-20260616095155'),
(7, 4, 10, 341550.00, 0.00, 'Fuel purchase - RCV-20260616095606'),
(8, 4, 1, 0.00, 341550.00, 'Cash payment to Jamuna Fuel Supply'),
(9, 5, 10, 103500.00, 0.00, 'Fuel purchase - RCV-20260616095655'),
(10, 5, 5, 0.00, 103500.00, 'Accounts Payable to Titas GAS'),
(11, 6, 5, 103500.00, 0.00, 'Payment to Titas GAS'),
(12, 6, 1, 0.00, 103500.00, 'Cash payment to supplier'),
(13, 8, 1, 10000.00, 0.00, 'Rent received from tenant - RENT-20260616-431'),
(14, 8, 9, 0.00, 10000.00, 'Rental income for January 2026'),
(15, 9, 1, 10000.00, 0.00, 'Rent received from tenant - RENT-20260616-665'),
(16, 9, 9, 0.00, 10000.00, 'Rental income for February 2026'),
(17, 10, 1, 10000.00, 0.00, 'Rent received from tenant - RENT-20260616-895'),
(18, 10, 9, 0.00, 10000.00, 'Rental income for March 2026'),
(19, 11, 1, 10000.00, 0.00, 'Rent received from tenant - RENT-20260616-044'),
(20, 11, 9, 0.00, 10000.00, 'Rental income for April 2026'),
(21, 12, 1, 10000.00, 0.00, 'Rent received from tenant - RENT-20260616-119'),
(22, 12, 9, 0.00, 10000.00, 'Rental income for May 2026'),
(23, 13, 1, 10000.00, 0.00, 'Rent received from tenant - RENT-20260616-021'),
(24, 13, 9, 0.00, 10000.00, 'Rental income for June 2026'),
(25, 14, 1, 1000.00, 0.00, 'Test debit entry'),
(26, 14, 2, 0.00, 1000.00, 'Test credit entry'),
(27, 16, 1, 0.00, 2500.00, 'Purchase Items for Maintenance'),
(28, 16, 69, 2500.00, 0.00, 'Purchase Items for Maintenance'),
(29, 17, 1, 0.00, 200.00, 'Purchase Items for Maintenance'),
(30, 17, 69, 200.00, 0.00, 'Purchase Items for Maintenance'),
(31, 18, 1, 0.00, 75.00, 'test'),
(32, 18, 69, 75.00, 0.00, 'test'),
(33, 19, 1, 0.00, 150.00, 'test2'),
(34, 19, 69, 150.00, 0.00, 'test2'),
(35, 20, 2, 500000.00, 0.00, 'Loan from DBBL Bank'),
(36, 20, 70, 0.00, 500000.00, 'Loan from DBBL Bank'),
(37, 21, 70, 1000000.00, 0.00, 'Loan Adjustment and wrong entry correction'),
(38, 21, 2, 0.00, 1000000.00, 'Loan Adjustment and wrong entry correction'),
(39, 22, 1, 1050.00, 0.00, 'Cash sale - Invoice: INV-20260617141310'),
(40, 22, 8, 0.00, 1050.00, 'Fuel sale revenue - Invoice: INV-20260617141310'),
(41, 23, 4, 6500.00, 0.00, 'Credit sale to Mr. Forhad - Invoice: INV-20260617141457'),
(42, 23, 8, 0.00, 6500.00, 'Fuel sale revenue - Invoice: INV-20260617141457'),
(43, 24, 10, 594000.00, 0.00, 'Fuel purchase - RCV-20260617143947'),
(44, 24, 1, 0.00, 594000.00, 'Cash payment to Padma Oil Company'),
(45, 25, 11, 100.00, 0.00, 'Stock loss from Diesel Tank-01 - 1.00 Liters'),
(46, 25, 3, 0.00, 100.00, 'Inventory reduction due to loss'),
(47, 26, 1, 5000.00, 0.00, 'Payment received from Mr. Forhad - Receipt: PAY-20260617150449150'),
(48, 26, 4, 0.00, 5000.00, 'Customer payment applied to credit sales - Invoice: INV-20260617141457'),
(49, 27, 1, 6000.00, 0.00, 'Rent received from tenant - RENT-20260617-525'),
(50, 27, 9, 0.00, 6000.00, 'Rental income for April 2026'),
(51, 28, 1, 6000.00, 0.00, 'Rent received from tenant - RENT-20260617-822'),
(52, 28, 9, 0.00, 6000.00, 'Rental income for May 2026'),
(53, 29, 1, 500.00, 0.00, 'CNG cash sale - CNG-20260618081941'),
(54, 29, 8, 0.00, 500.00, 'CNG sales revenue - CNG-20260618081941'),
(55, 30, 1, 500.00, 0.00, 'CNG cash sale - CNG-20260618082006'),
(56, 30, 8, 0.00, 500.00, 'CNG sales revenue - CNG-20260618082006'),
(57, 31, 1, 500.00, 0.00, 'CNG cash sale - CNG-20260618082038'),
(58, 31, 8, 0.00, 500.00, 'CNG sales revenue - CNG-20260618082038'),
(59, 32, 1, 500.00, 0.00, 'CNG cash sale - CNG-20260618082848'),
(60, 32, 8, 0.00, 500.00, 'CNG sales revenue - CNG-20260618082848'),
(61, 33, 1, 500.00, 0.00, 'CNG cash sale - CNG-20260618084947'),
(62, 33, 8, 0.00, 500.00, 'CNG sales revenue - CNG-20260618084947'),
(63, 34, 1, 500.00, 0.00, 'CNG cash sale - CNG-20260618085403'),
(64, 34, 8, 0.00, 500.00, 'CNG sales revenue - CNG-20260618085403'),
(65, 35, 1, 500.00, 0.00, 'CNG cash sale - CNG-20260618085501'),
(66, 35, 8, 0.00, 500.00, 'CNG sales revenue - CNG-20260618085501'),
(67, 36, 72, 1500.00, 0.00, 'Bonus - MUHAMMAD RAFIQUL ALAM'),
(68, 36, 1, 0.00, 1500.00, 'Bonus payment - MUHAMMAD RAFIQUL ALAM'),
(69, 37, 1, 400.00, 0.00, 'CNG cash sale - CNG-20260618093509579'),
(70, 37, 8, 0.00, 400.00, 'CNG sales revenue - CNG-20260618093509579'),
(71, 38, 1, 500.00, 0.00, 'CNG cash sale - CNG-20260618093542713'),
(72, 38, 8, 0.00, 500.00, 'CNG sales revenue - CNG-20260618093542713'),
(73, 39, 1, 500000.00, 0.00, 'Cash sale - Invoice: INV-20260618113852'),
(74, 39, 8, 0.00, 500000.00, 'Fuel sale revenue - Invoice: INV-20260618113852'),
(75, 40, 1, 10000.00, 0.00, 'Advance received from Mr. Forhad'),
(76, 40, 71, 0.00, 10000.00, 'Customer advance liability'),
(77, 41, 1, 2000.00, 0.00, 'Cash sale - Invoice: INV-20260618131811'),
(78, 41, 8, 0.00, 2000.00, 'Fuel sale revenue - Invoice: INV-20260618131811'),
(79, 42, 1, 400.00, 0.00, 'CNG cash sale - CNG-20260618131836964'),
(80, 42, 8, 0.00, 400.00, 'CNG sales revenue - CNG-20260618131836964'),
(81, 43, 1, 400.00, 0.00, 'CNG cash sale - CNG-20260618131921709'),
(82, 43, 8, 0.00, 400.00, 'CNG sales revenue - CNG-20260618131921709'),
(83, 44, 1, 300.00, 0.00, 'CNG cash sale - CNG-20260618132520915'),
(84, 44, 8, 0.00, 300.00, 'CNG sales revenue - CNG-20260618132520915'),
(85, 45, 1, 2000.00, 0.00, 'Cash sale - Invoice: INV-20260618132719'),
(86, 45, 8, 0.00, 2000.00, 'Fuel sale revenue - Invoice: INV-20260618132719'),
(87, 46, 73, 35800.00, 0.00, 'Inventory purchase - 12562'),
(88, 46, 1, 0.00, 35800.00, 'Cash payment for purchase - 12562'),
(89, 47, 1, 300.00, 0.00, 'Item sale - ITEM-20260618143100197'),
(90, 47, 8, 0.00, 300.00, 'Item sales revenue - ITEM-20260618143100197'),
(91, 48, 73, 750.00, 0.00, 'Inventory purchase - 100010'),
(92, 48, 1, 0.00, 750.00, 'Cash payment for purchase - 100010'),
(93, 49, 1, 1050.00, 0.00, 'Cash sale - Invoice: INV-20260620144402'),
(94, 49, 8, 0.00, 1050.00, 'Fuel sale revenue - Invoice: INV-20260620144402'),
(95, 50, 1, 1050.00, 0.00, 'Cash sale - Invoice: INV-20260620144450'),
(96, 50, 8, 0.00, 1050.00, 'Fuel sale revenue - Invoice: INV-20260620144450'),
(97, 51, 1, 1050.00, 0.00, 'Cash sale - Invoice: INV-20260620145854'),
(98, 51, 8, 0.00, 1050.00, 'Fuel sale revenue - Invoice: INV-20260620145854'),
(99, 52, 1, 250.00, 0.00, 'CNG cash sale - CNG-20260620150014877'),
(100, 52, 8, 0.00, 250.00, 'CNG sales revenue - CNG-20260620150014877'),
(101, 53, 1, 100.00, 0.00, 'CNG cash sale - CNG-20260620150629162'),
(102, 53, 8, 0.00, 100.00, 'CNG sales revenue - CNG-20260620150629162'),
(103, 54, 1, 145.00, 0.00, 'CNG cash sale - CNG-20260620152556822'),
(104, 54, 8, 0.00, 145.00, 'CNG sales revenue - CNG-20260620152556822'),
(105, 55, 1, 450.00, 0.00, 'CNG cash sale - CNG-20260620153217247'),
(106, 55, 8, 0.00, 450.00, 'CNG sales revenue - CNG-20260620153217247'),
(107, 56, 1, 800.00, 0.00, 'CNG cash sale - CNG-20260620154301392'),
(108, 56, 8, 0.00, 800.00, 'CNG sales revenue - CNG-20260620154301392'),
(109, 57, 1, 250.00, 0.00, 'CNG cash sale - CNG-20260620154821960'),
(110, 57, 8, 0.00, 250.00, 'CNG sales revenue - CNG-20260620154821960'),
(111, 58, 1, 1050.00, 0.00, 'Cash sale - Invoice: INV-20260620155201'),
(112, 58, 8, 0.00, 1050.00, 'Fuel sale revenue - Invoice: INV-20260620155201'),
(113, 59, 1, 200.00, 0.00, 'CNG cash sale - CNG-20260621093316352'),
(114, 59, 8, 0.00, 200.00, 'CNG sales revenue - CNG-20260621093316352'),
(115, 60, 1, 2500.00, 0.00, 'CNG cash sale - CNG-20260621110359915'),
(116, 60, 8, 0.00, 2500.00, 'CNG sales revenue - CNG-20260621110359915'),
(117, 61, 1, 450.00, 0.00, 'Item sale - ITEM-20260621111746748'),
(118, 61, 8, 0.00, 450.00, 'Item sales revenue - ITEM-20260621111746748'),
(119, 62, 1, 350.00, 0.00, 'CNG cash sale - CNG-20260621112640678'),
(120, 62, 8, 0.00, 350.00, 'CNG sales revenue - CNG-20260621112640678'),
(121, 63, 1, 1050.00, 0.00, 'Cash sale - Invoice: INV-20260621160928'),
(122, 63, 8, 0.00, 1050.00, 'Fuel sale revenue - Invoice: INV-20260621160928'),
(123, 64, 1, 1050.00, 0.00, 'Cash sale - Invoice: INV-20260621160953'),
(124, 64, 8, 0.00, 1050.00, 'Fuel sale revenue - Invoice: INV-20260621160953'),
(125, 65, 1, 1050.00, 0.00, 'Cash sale - Invoice: INV-20260622062414'),
(126, 65, 8, 0.00, 1050.00, 'Fuel sale revenue - Invoice: INV-20260622062414'),
(127, 66, 1, 400.00, 0.00, 'CNG cash sale - CNG-20260622074636821'),
(128, 66, 8, 0.00, 400.00, 'CNG sales revenue - CNG-20260622074636821'),
(129, 67, 1, 350.00, 0.00, 'CNG cash sale - CNG-20260622074712158'),
(130, 67, 8, 0.00, 350.00, 'CNG sales revenue - CNG-20260622074712158'),
(131, 68, 1, 525.00, 0.00, 'Cash sale - Invoice: INV-20260622075124'),
(132, 68, 8, 0.00, 525.00, 'Fuel sale revenue - Invoice: INV-20260622075124'),
(133, 69, 1, 1050.00, 0.00, 'Item sale - ITEM-20260622075701812'),
(134, 69, 8, 0.00, 1050.00, 'Item sales revenue - ITEM-20260622075701812'),
(135, 70, 1, 1050.00, 0.00, 'Cash sale - Invoice: INV-20260624134411'),
(136, 70, 8, 0.00, 1050.00, 'Fuel sale revenue - Invoice: INV-20260624134411'),
(137, 71, 1, 1050.00, 0.00, 'Cash sale - Invoice: INV-20260625074125'),
(138, 71, 8, 0.00, 1050.00, 'Fuel sale revenue - Invoice: INV-20260625074125'),
(139, 72, 1, 1050.00, 0.00, 'Cash sale - Invoice: INV-20260625104553'),
(140, 72, 8, 0.00, 1050.00, 'Fuel sale revenue - Invoice: INV-20260625104553'),
(141, 73, 1, 1050.00, 0.00, 'Cash sale - Invoice: INV-20260625104757'),
(142, 73, 8, 0.00, 1050.00, 'Fuel sale revenue - Invoice: INV-20260625104757'),
(143, 74, 1, 1050.00, 0.00, 'Cash sale - Invoice: INV-20260625110242'),
(144, 74, 8, 0.00, 1050.00, 'Fuel sale revenue - Invoice: INV-20260625110242'),
(145, 75, 1, 210.00, 0.00, 'Cash sale - Invoice: INV-20260625110628'),
(146, 75, 8, 0.00, 210.00, 'Fuel sale revenue - Invoice: INV-20260625110628'),
(147, 76, 1, 1575.00, 0.00, 'Cash sale - Invoice: INV-20260625111143'),
(148, 76, 8, 0.00, 1575.00, 'Fuel sale revenue - Invoice: INV-20260625111143'),
(149, 77, 1, 2000.00, 0.00, 'Cash sale - Invoice: INV-20260627072110'),
(150, 77, 8, 0.00, 2000.00, 'Fuel sale revenue - Invoice: INV-20260627072110'),
(151, 78, 1, 1500.00, 0.00, 'Cash sale - Invoice: INV-20260627080506'),
(152, 78, 8, 0.00, 1500.00, 'Fuel sale revenue - Invoice: INV-20260627080506'),
(153, 79, 1, 850.00, 0.00, 'Cash sale - Invoice: INV-20260627081104'),
(154, 79, 8, 0.00, 850.00, 'Fuel sale revenue - Invoice: INV-20260627081104'),
(155, 80, 1, 850.00, 0.00, 'CNG cash sale - CNG-20260627081135449'),
(156, 80, 8, 0.00, 850.00, 'CNG sales revenue - CNG-20260627081135449'),
(157, 81, 1, 200.00, 0.00, 'CNG cash sale - CNG-20260627081408981'),
(158, 81, 8, 0.00, 200.00, 'CNG sales revenue - CNG-20260627081408981'),
(159, 82, 1, 1200.00, 0.00, 'CNG cash sale - CNG-20260627082415512'),
(160, 82, 8, 0.00, 1200.00, 'CNG sales revenue - CNG-20260627082415512'),
(161, 83, 1, 450.00, 0.00, 'Item sale - ITEM-20260627082442645'),
(162, 83, 8, 0.00, 450.00, 'Item sales revenue - ITEM-20260627082442645');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `advance_adjustments`
--
ALTER TABLE `advance_adjustments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `advance_payments_customer`
--
ALTER TABLE `advance_payments_customer`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `fk_advance_customer_voucher` (`voucher_id`);

--
-- Indexes for table `advance_payments_supplier`
--
ALTER TABLE `advance_payments_supplier`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `fk_advance_supplier_voucher` (`voucher_id`);

--
-- Indexes for table `advance_usage`
--
ALTER TABLE `advance_usage`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `assets`
--
ALTER TABLE `assets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `asset_code` (`asset_code`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `idx_code` (`asset_code`),
  ADD KEY `idx_type` (`asset_type`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `asset_depreciation`
--
ALTER TABLE `asset_depreciation`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_asset` (`asset_id`),
  ADD KEY `idx_date` (`depreciation_date`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`employee_id`,`attendance_date`);

--
-- Indexes for table `audit_trail`
--
ALTER TABLE `audit_trail`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_module` (`module`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `account_code` (`account_code`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Indexes for table `cng_daily_summary`
--
ALTER TABLE `cng_daily_summary`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_date` (`summary_date`);

--
-- Indexes for table `cng_meter_readings`
--
ALTER TABLE `cng_meter_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_nozzle_date` (`nozzle_id`,`reading_date`);

--
-- Indexes for table `cng_shift_closing`
--
ALTER TABLE `cng_shift_closing`
  ADD PRIMARY KEY (`id`),
  ADD KEY `shift_id` (`shift_id`);

--
-- Indexes for table `credit_payments`
--
ALTER TABLE `credit_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `credit_sale_id` (`credit_sale_id`),
  ADD KEY `fk_credit_payments_voucher` (`voucher_id`);

--
-- Indexes for table `credit_sales`
--
ALTER TABLE `credit_sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `fk_credit_sales_voucher` (`voucher_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `customer_code` (`customer_code`);

--
-- Indexes for table `customer_transactions`
--
ALTER TABLE `customer_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_date` (`transaction_date`),
  ADD KEY `idx_reference` (`reference_no`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_id` (`employee_id`);

--
-- Indexes for table `employee_payments`
--
ALTER TABLE `employee_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `voucher_id` (`voucher_id`);

--
-- Indexes for table `fuel_products`
--
ALTER TABLE `fuel_products`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `fuel_receivings`
--
ALTER TABLE `fuel_receivings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_no` (`receipt_no`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `tank_id` (`tank_id`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_payment_status` (`payment_status`);

--
-- Indexes for table `gas_sales`
--
ALTER TABLE `gas_sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_no` (`invoice_no`),
  ADD KEY `shift_id` (`shift_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_vehicle_number` (`vehicle_number`),
  ADD KEY `customer_id` (`customer_id`);

--
-- Indexes for table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `item_code` (`item_code`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `idx_item_code` (`item_code`),
  ADD KEY `idx_item_name` (`item_name`);

--
-- Indexes for table `item_categories`
--
ALTER TABLE `item_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `item_purchases`
--
ALTER TABLE `item_purchases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `purchase_no` (`purchase_no`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `item_purchase_items`
--
ALTER TABLE `item_purchase_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_id` (`purchase_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `item_sales`
--
ALTER TABLE `item_sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_no` (`invoice_no`),
  ADD KEY `shift_id` (`shift_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `item_sale_items`
--
ALTER TABLE `item_sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `idx_sale_id` (`sale_id`);

--
-- Indexes for table `item_stock_ledger`
--
ALTER TABLE `item_stock_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_date` (`entry_date`),
  ADD KEY `idx_reference` (`reference_no`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_supplier` (`supplier_id`),
  ADD KEY `idx_account` (`account_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `journal_vouchers`
--
ALTER TABLE `journal_vouchers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `voucher_no` (`voucher_no`),
  ADD KEY `idx_voucher_no` (`voucher_no`),
  ADD KEY `idx_date` (`voucher_date`),
  ADD KEY `idx_type` (`voucher_type`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_supplier` (`supplier_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `journal_voucher_items`
--
ALTER TABLE `journal_voucher_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_voucher` (`voucher_id`),
  ADD KEY `idx_account` (`account_id`),
  ADD KEY `idx_type` (`account_type`);

--
-- Indexes for table `leakage_adjustments`
--
ALTER TABLE `leakage_adjustments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tank_id` (`tank_id`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `loans`
--
ALTER TABLE `loans`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `loan_installments`
--
ALTER TABLE `loan_installments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_id` (`loan_id`);

--
-- Indexes for table `meter_readings`
--
ALTER TABLE `meter_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `nozzle_id` (`nozzle_id`);

--
-- Indexes for table `nozzles`
--
ALTER TABLE `nozzles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tank_id` (`tank_id`);

--
-- Indexes for table `opening_balances`
--
ALTER TABLE `opening_balances`
  ADD PRIMARY KEY (`id`),
  ADD KEY `account_id` (`account_id`);

--
-- Indexes for table `other_income`
--
ALTER TABLE `other_income`
  ADD PRIMARY KEY (`id`),
  ADD KEY `voucher_id` (`voucher_id`);

--
-- Indexes for table `payroll`
--
ALTER TABLE `payroll`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`),
  ADD KEY `fk_payroll_voucher` (`voucher_id`);

--
-- Indexes for table `rent_payments`
--
ALTER TABLE `rent_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_no` (`invoice_no`),
  ADD KEY `fk_sales_nozzle_id` (`nozzle_id`),
  ADD KEY `fk_sales_operator_id` (`operator_id`),
  ADD KEY `fk_sales_product_id` (`product_id`),
  ADD KEY `fk_sales_shift_id` (`shift_id`),
  ADD KEY `customer_id` (`customer_id`);

--
-- Indexes for table `shifts`
--
ALTER TABLE `shifts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `shift_closing`
--
ALTER TABLE `shift_closing`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `shift_schedule`
--
ALTER TABLE `shift_schedule`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `shift_stock`
--
ALTER TABLE `shift_stock`
  ADD PRIMARY KEY (`id`),
  ADD KEY `shift_id` (`shift_id`),
  ADD KEY `tank_id` (`tank_id`);

--
-- Indexes for table `stock_ledger`
--
ALTER TABLE `stock_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `tank_id` (`tank_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplier_code` (`supplier_code`);

--
-- Indexes for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `receiving_id` (`receiving_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `tanks`
--
ALTER TABLE `tanks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `tank_stock_readings`
--
ALTER TABLE `tank_stock_readings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tank_id` (`tank_id`),
  ADD KEY `shift_id` (`shift_id`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `tenants`
--
ALTER TABLE `tenants`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `vouchers`
--
ALTER TABLE `vouchers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `voucher_no` (`voucher_no`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `voucher_items`
--
ALTER TABLE `voucher_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `voucher_id` (`voucher_id`),
  ADD KEY `account_id` (`account_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `advance_adjustments`
--
ALTER TABLE `advance_adjustments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `advance_payments_customer`
--
ALTER TABLE `advance_payments_customer`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `advance_payments_supplier`
--
ALTER TABLE `advance_payments_supplier`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `advance_usage`
--
ALTER TABLE `advance_usage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assets`
--
ALTER TABLE `assets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `asset_depreciation`
--
ALTER TABLE `asset_depreciation`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `audit_trail`
--
ALTER TABLE `audit_trail`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=82;

--
-- AUTO_INCREMENT for table `cng_daily_summary`
--
ALTER TABLE `cng_daily_summary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cng_meter_readings`
--
ALTER TABLE `cng_meter_readings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cng_shift_closing`
--
ALTER TABLE `cng_shift_closing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `credit_payments`
--
ALTER TABLE `credit_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `credit_sales`
--
ALTER TABLE `credit_sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `customer_transactions`
--
ALTER TABLE `customer_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `employee_payments`
--
ALTER TABLE `employee_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fuel_products`
--
ALTER TABLE `fuel_products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `fuel_receivings`
--
ALTER TABLE `fuel_receivings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `gas_sales`
--
ALTER TABLE `gas_sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `items`
--
ALTER TABLE `items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `item_categories`
--
ALTER TABLE `item_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `item_purchases`
--
ALTER TABLE `item_purchases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `item_purchase_items`
--
ALTER TABLE `item_purchase_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `item_sales`
--
ALTER TABLE `item_sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `item_sale_items`
--
ALTER TABLE `item_sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `item_stock_ledger`
--
ALTER TABLE `item_stock_ledger`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_vouchers`
--
ALTER TABLE `journal_vouchers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_voucher_items`
--
ALTER TABLE `journal_voucher_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leakage_adjustments`
--
ALTER TABLE `leakage_adjustments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `loans`
--
ALTER TABLE `loans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_installments`
--
ALTER TABLE `loan_installments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `meter_readings`
--
ALTER TABLE `meter_readings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `nozzles`
--
ALTER TABLE `nozzles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `opening_balances`
--
ALTER TABLE `opening_balances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `other_income`
--
ALTER TABLE `other_income`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll`
--
ALTER TABLE `payroll`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `rent_payments`
--
ALTER TABLE `rent_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `shifts`
--
ALTER TABLE `shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `shift_closing`
--
ALTER TABLE `shift_closing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `shift_schedule`
--
ALTER TABLE `shift_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `shift_stock`
--
ALTER TABLE `shift_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `stock_ledger`
--
ALTER TABLE `stock_ledger`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `tanks`
--
ALTER TABLE `tanks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tank_stock_readings`
--
ALTER TABLE `tank_stock_readings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tenants`
--
ALTER TABLE `tenants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vouchers`
--
ALTER TABLE `vouchers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=84;

--
-- AUTO_INCREMENT for table `voucher_items`
--
ALTER TABLE `voucher_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=163;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `advance_payments_customer`
--
ALTER TABLE `advance_payments_customer`
  ADD CONSTRAINT `advance_payments_customer_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_advance_customer_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `advance_payments_supplier`
--
ALTER TABLE `advance_payments_supplier`
  ADD CONSTRAINT `advance_payments_supplier_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_advance_supplier_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `assets`
--
ALTER TABLE `assets`
  ADD CONSTRAINT `assets_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `asset_depreciation`
--
ALTER TABLE `asset_depreciation`
  ADD CONSTRAINT `asset_depreciation_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `audit_trail`
--
ALTER TABLE `audit_trail`
  ADD CONSTRAINT `audit_trail_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  ADD CONSTRAINT `chart_of_accounts_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `chart_of_accounts` (`id`);

--
-- Constraints for table `cng_meter_readings`
--
ALTER TABLE `cng_meter_readings`
  ADD CONSTRAINT `cng_meter_readings_ibfk_1` FOREIGN KEY (`nozzle_id`) REFERENCES `nozzles` (`id`),
  ADD CONSTRAINT `cng_meter_readings_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `cng_shift_closing`
--
ALTER TABLE `cng_shift_closing`
  ADD CONSTRAINT `cng_shift_closing_ibfk_1` FOREIGN KEY (`shift_id`) REFERENCES `shift_closing` (`id`);

--
-- Constraints for table `credit_payments`
--
ALTER TABLE `credit_payments`
  ADD CONSTRAINT `credit_payments_ibfk_1` FOREIGN KEY (`credit_sale_id`) REFERENCES `credit_sales` (`id`),
  ADD CONSTRAINT `fk_credit_payments_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `credit_sales`
--
ALTER TABLE `credit_sales`
  ADD CONSTRAINT `credit_sales_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`),
  ADD CONSTRAINT `credit_sales_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fk_credit_sales_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `customer_transactions`
--
ALTER TABLE `customer_transactions`
  ADD CONSTRAINT `customer_transactions_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `employee_payments`
--
ALTER TABLE `employee_payments`
  ADD CONSTRAINT `employee_payments_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`);

--
-- Constraints for table `fuel_receivings`
--
ALTER TABLE `fuel_receivings`
  ADD CONSTRAINT `fuel_receivings_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `fuel_products` (`id`),
  ADD CONSTRAINT `fuel_receivings_ibfk_2` FOREIGN KEY (`tank_id`) REFERENCES `tanks` (`id`),
  ADD CONSTRAINT `fuel_receivings_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fuel_receivings_ibfk_4` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `gas_sales`
--
ALTER TABLE `gas_sales`
  ADD CONSTRAINT `gas_sales_ibfk_1` FOREIGN KEY (`shift_id`) REFERENCES `shift_closing` (`id`),
  ADD CONSTRAINT `gas_sales_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `items`
--
ALTER TABLE `items`
  ADD CONSTRAINT `items_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `item_categories` (`id`);

--
-- Constraints for table `item_purchases`
--
ALTER TABLE `item_purchases`
  ADD CONSTRAINT `item_purchases_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `item_purchases_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `item_purchase_items`
--
ALTER TABLE `item_purchase_items`
  ADD CONSTRAINT `item_purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `item_purchases` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `item_purchase_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`);

--
-- Constraints for table `item_sales`
--
ALTER TABLE `item_sales`
  ADD CONSTRAINT `item_sales_ibfk_1` FOREIGN KEY (`shift_id`) REFERENCES `shift_closing` (`id`),
  ADD CONSTRAINT `item_sales_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `item_sales_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `item_sale_items`
--
ALTER TABLE `item_sale_items`
  ADD CONSTRAINT `item_sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `item_sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `item_sale_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`);

--
-- Constraints for table `item_stock_ledger`
--
ALTER TABLE `item_stock_ledger`
  ADD CONSTRAINT `item_stock_ledger_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  ADD CONSTRAINT `item_stock_ledger_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD CONSTRAINT `journal_entries_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `journal_entries_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `journal_entries_ibfk_3` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `journal_vouchers`
--
ALTER TABLE `journal_vouchers`
  ADD CONSTRAINT `journal_vouchers_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `journal_vouchers_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `journal_voucher_items`
--
ALTER TABLE `journal_voucher_items`
  ADD CONSTRAINT `journal_voucher_items_ibfk_1` FOREIGN KEY (`voucher_id`) REFERENCES `journal_vouchers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `journal_voucher_items_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leakage_adjustments`
--
ALTER TABLE `leakage_adjustments`
  ADD CONSTRAINT `leakage_adjustments_ibfk_1` FOREIGN KEY (`tank_id`) REFERENCES `tanks` (`id`),
  ADD CONSTRAINT `leakage_adjustments_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `leakage_adjustments_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `loan_installments`
--
ALTER TABLE `loan_installments`
  ADD CONSTRAINT `loan_installments_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `meter_readings`
--
ALTER TABLE `meter_readings`
  ADD CONSTRAINT `meter_readings_ibfk_1` FOREIGN KEY (`nozzle_id`) REFERENCES `nozzles` (`id`);

--
-- Constraints for table `nozzles`
--
ALTER TABLE `nozzles`
  ADD CONSTRAINT `nozzles_ibfk_1` FOREIGN KEY (`tank_id`) REFERENCES `tanks` (`id`);

--
-- Constraints for table `opening_balances`
--
ALTER TABLE `opening_balances`
  ADD CONSTRAINT `opening_balances_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`);

--
-- Constraints for table `other_income`
--
ALTER TABLE `other_income`
  ADD CONSTRAINT `other_income_ibfk_1` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`);

--
-- Constraints for table `payroll`
--
ALTER TABLE `payroll`
  ADD CONSTRAINT `fk_payroll_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payroll_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `rent_payments`
--
ALTER TABLE `rent_payments`
  ADD CONSTRAINT `rent_payments_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `fk_sales_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fk_sales_nozzle_id` FOREIGN KEY (`nozzle_id`) REFERENCES `nozzles` (`id`),
  ADD CONSTRAINT `fk_sales_operator_id` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_sales_product_id` FOREIGN KEY (`product_id`) REFERENCES `fuel_products` (`id`),
  ADD CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `shift_stock`
--
ALTER TABLE `shift_stock`
  ADD CONSTRAINT `shift_stock_ibfk_1` FOREIGN KEY (`shift_id`) REFERENCES `shift_closing` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shift_stock_ibfk_2` FOREIGN KEY (`tank_id`) REFERENCES `tanks` (`id`);

--
-- Constraints for table `stock_ledger`
--
ALTER TABLE `stock_ledger`
  ADD CONSTRAINT `stock_ledger_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `fuel_products` (`id`),
  ADD CONSTRAINT `stock_ledger_ibfk_2` FOREIGN KEY (`tank_id`) REFERENCES `tanks` (`id`);

--
-- Constraints for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD CONSTRAINT `supplier_payments_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tanks`
--
ALTER TABLE `tanks`
  ADD CONSTRAINT `tanks_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `fuel_products` (`id`);

--
-- Constraints for table `tank_stock_readings`
--
ALTER TABLE `tank_stock_readings`
  ADD CONSTRAINT `tank_stock_readings_ibfk_1` FOREIGN KEY (`tank_id`) REFERENCES `tanks` (`id`),
  ADD CONSTRAINT `tank_stock_readings_ibfk_2` FOREIGN KEY (`shift_id`) REFERENCES `shift_schedule` (`id`),
  ADD CONSTRAINT `tank_stock_readings_ibfk_3` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `vouchers`
--
ALTER TABLE `vouchers`
  ADD CONSTRAINT `vouchers_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `vouchers_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `voucher_items`
--
ALTER TABLE `voucher_items`
  ADD CONSTRAINT `voucher_items_ibfk_1` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `voucher_items_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

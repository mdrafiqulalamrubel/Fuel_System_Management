-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 13, 2026 at 09:25 AM
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
(1, 1, 'login', 'User logged in', '::1', '2026-06-11 05:15:39'),
(2, 1, 'login', 'User logged in', '::1', '2026-06-11 07:40:03'),
(3, 1, 'login', 'User logged in', '::1', '2026-06-11 08:47:34'),
(4, 1, 'login', 'User logged in', '::1', '2026-06-11 08:47:46'),
(5, 1, 'login', 'User logged in', '::1', '2026-06-11 09:18:01'),
(6, 1, 'login', 'User logged in', '::1', '2026-06-11 11:20:20'),
(7, 1, 'login', 'User logged in', '::1', '2026-06-11 12:05:50'),
(8, 1, 'login', 'User logged in', '::1', '2026-06-11 12:07:20'),
(9, 1, 'login', 'User logged in', '::1', '2026-06-13 05:26:34');

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
(1, 1, '2026-06-11', '09:00:00', '17:00:00', 'present', 0.00);

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
(1, '1000', 'Cash Account', 'asset', NULL, 150000.00, 'debit', 1),
(2, '1100', 'Bank Account', 'asset', NULL, 2200000.00, 'debit', 1),
(3, '1200', 'Fuel Inventory', 'asset', NULL, 150000.00, 'debit', 1),
(4, '1300', 'Accounts Receivable', 'asset', NULL, 25000.00, 'debit', 1),
(5, '2000', 'Accounts Payable', 'liability', NULL, 75000.00, 'credit', 1),
(6, '2100', 'Loan Payable', 'liability', NULL, 200000.00, 'credit', 1),
(7, '3000', 'Owner\'s Equity', 'equity', NULL, 500000.00, 'credit', 1),
(8, '4000', 'Fuel Sales', 'income', NULL, 0.00, 'debit', 1),
(9, '4100', 'Rental Income', 'income', NULL, 0.00, 'debit', 1),
(10, '5000', 'Fuel Purchase', 'expense', NULL, 0.00, 'debit', 1),
(11, '5100', 'Stock Loss Expense', 'expense', NULL, 0.00, 'debit', 1),
(12, '5200', 'Utility Expense', 'expense', NULL, 0.00, 'debit', 1),
(30, '5110', 'Salary Expense', 'expense', NULL, 0.00, 'debit', 1);

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
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `credit_payments`
--

INSERT INTO `credit_payments` (`id`, `credit_sale_id`, `payment_date`, `amount`, `payment_method`, `receipt_no`, `notes`) VALUES
(1, 1, '2026-06-11', 650.00, 'cash', 'PAY-20260611123703956', '');

-- --------------------------------------------------------

--
-- Table structure for table `credit_sales`
--

CREATE TABLE `credit_sales` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `invoice_no` varchar(50) NOT NULL,
  `sale_date` date NOT NULL,
  `due_date` date NOT NULL,
  `total_amount` decimal(15,2) NOT NULL,
  `paid_amount` decimal(15,2) DEFAULT 0.00,
  `balance_due` decimal(15,2) NOT NULL,
  `status` enum('pending','partial','paid','overdue') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `credit_sales`
--

INSERT INTO `credit_sales` (`id`, `sale_id`, `customer_id`, `invoice_no`, `sale_date`, `due_date`, `total_amount`, `paid_amount`, `balance_due`, `status`) VALUES
(1, 10, 1, 'INV-20260611121716', '2026-06-11', '2026-07-11', 650.00, 650.00, 0.00, 'paid');

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
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `customer_code`, `customer_name`, `phone`, `email`, `address`, `credit_limit`, `opening_balance`, `current_balance`, `is_active`, `created_at`) VALUES
(1, 'CUST-20260611781', 'Rafiqul Alam Rubel', '01782382140', NULL, NULL, 50000.00, 0.00, 0.00, 1, '2026-06-11 10:17:16');

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
(1, 'EMP-001', 'Rafiqul Alam Rubel', 'sdfsdfs', 'Management', '2026-06-11', 4500.00, '01811458888', 'sdsdf', 'sfsdfs 52112121', 1),
(2, 'EMP-002', 'BILLAL HOSSAIN', 'ASSISTANT MANAGER', 'Management', '2026-04-01', 15000.00, '', '', '', 1);

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
  `product_name` enum('Diesel','Petrol','Octane','CNG','LPG') NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `purchase_rate` decimal(10,2) NOT NULL,
  `vat_percentage` decimal(5,2) DEFAULT 0.00,
  `tax_percentage` decimal(5,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `fuel_products`
--

INSERT INTO `fuel_products` (`id`, `product_name`, `unit_price`, `purchase_rate`, `vat_percentage`, `tax_percentage`, `is_active`) VALUES
(1, 'Diesel', 85.00, 75.00, 5.00, 2.00, 1),
(2, 'Petrol', 120.00, 105.00, 5.00, 2.00, 1),
(3, 'Octane', 130.00, 115.00, 5.00, 2.00, 1),
(4, 'CNG', 65.00, 55.00, 0.00, 0.00, 1),
(5, 'LPG', 95.00, 85.00, 5.00, 2.00, 1);

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
  `approved_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `fuel_receivings`
--

INSERT INTO `fuel_receivings` (`id`, `receipt_no`, `receipt_date`, `supplier_name`, `tanker_no`, `challan_no`, `product_id`, `tank_id`, `expected_quantity`, `actual_quantity`, `shortage`, `freight_cost`, `freight_deduction`, `unit_price`, `total_amount`, `status`, `approved_by`) VALUES
(1, 'RCV-20260611093408', '2026-06-11', 'Jamuna', 'Tank01', '1', 1, 1, 5000.00, 4500.00, 500.00, 0.00, 0.00, 84.00, 378000.00, 'approved', 1),
(2, 'RCV-20260613073918', '2026-06-13', 'Padma Oil', 'Dhaka-Ga-0145', '012546', 1, 1, 9000.00, 8750.00, 250.00, 0.00, 0.00, 62.50, 546875.00, 'approved', 1);

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
(9, '2026-06-11', 1, 9500.00, 8900.00, 600.00, 5.00, 'regular', 'wastage', 45000.00, 'approved', NULL, 1),
(10, '2026-06-11', 1, 8900.00, 8898.65, 1.35, 1700.00, 'hhh', 'wastage', 101.25, 'approved', NULL, 1);

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
-- Table structure for table `nozzles`
--

CREATE TABLE `nozzles` (
  `id` int(11) NOT NULL,
  `nozzle_name` varchar(50) NOT NULL,
  `tank_id` int(11) NOT NULL,
  `opening_meter` decimal(10,2) DEFAULT 0.00,
  `closing_meter` decimal(10,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `nozzles`
--

INSERT INTO `nozzles` (`id`, `nozzle_name`, `tank_id`, `opening_meter`, `closing_meter`, `is_active`) VALUES
(1, 'Nozzle Disel 01', 2, 0.00, 20.00, 1),
(2, 'Nozzle CNG 01', 6, 0.00, 200.00, 1),
(3, 'Nozzle CNG 02', 6, 0.00, 0.00, 1);

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
  `payment_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `payroll`
--

INSERT INTO `payroll` (`id`, `employee_id`, `month_year`, `basic_salary`, `allowances`, `overtime_amount`, `bonus`, `deductions`, `net_salary`, `status`, `payment_date`) VALUES
(1, 1, '2026-06', 4500.00, 1800.00, 0.00, 0.00, 450.00, 5850.00, 'pending', NULL);

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
(1, 1, '2026-06-13', '2026-06', 1500.00, 0.00, 'cash', '', 'RENT-20260613-700', '2026-06-13 07:06:23'),
(2, 2, '2026-06-13', '2026-06', 1500.00, 0.00, 'cash', '', 'RENT-20260613-970', '2026-06-13 07:08:56');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `invoice_no` varchar(50) NOT NULL,
  `sale_date` datetime DEFAULT current_timestamp(),
  `shift_id` int(11) NOT NULL,
  `nozzle_id` int(11) NOT NULL,
  `operator_id` int(11) NOT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `sale_type` enum('cash','credit','advance') NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity_liters` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `vat_amount` decimal(10,2) DEFAULT 0.00,
  `tax_amount` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  `received_amount` decimal(10,2) DEFAULT NULL,
  `change_amount` decimal(10,2) DEFAULT NULL,
  `credit_due_date` date DEFAULT NULL,
  `is_printed` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `invoice_no`, `sale_date`, `shift_id`, `nozzle_id`, `operator_id`, `customer_name`, `customer_phone`, `sale_type`, `product_id`, `quantity_liters`, `unit_price`, `subtotal`, `vat_amount`, `tax_amount`, `total_amount`, `received_amount`, `change_amount`, `credit_due_date`, `is_printed`) VALUES
(1, 'INV-20260611084254', '2026-06-11 12:42:54', 1, 1, 1, '', '', 'cash', 1, 10.00, 85.00, 850.00, 42.50, 17.00, 909.50, 1000.00, 90.50, NULL, 0),
(2, 'INV-20260611084915', '2026-06-11 12:49:15', 1, 2, 1, '', '', 'cash', 4, 10.00, 65.00, 650.00, 0.00, 0.00, 650.00, 700.00, 50.00, NULL, 0),
(3, 'INV-20260611085254', '2026-06-11 12:52:54', 1, 2, 1, '', '', 'cash', 4, 10.00, 65.00, 650.00, 0.00, 0.00, 650.00, 1000.00, 350.00, NULL, 0),
(4, 'INV-20260611090133', '2026-06-11 13:01:33', 1, 2, 1, '', '', 'cash', 4, 10.00, 65.00, 650.00, 0.00, 0.00, 650.00, 1000.00, 350.00, NULL, 0),
(5, 'INV-20260611090337', '2026-06-11 13:03:37', 2, 2, 1, '', '', 'cash', 4, 15.00, 65.00, 975.00, 0.00, 0.00, 975.00, 1200.00, 225.00, NULL, 0),
(6, 'INV-20260611091138', '2026-06-11 13:11:38', 1, 2, 1, '', '', 'cash', 4, 10.00, 65.00, 650.00, 0.00, 0.00, 650.00, 1000.00, 350.00, NULL, 0),
(7, 'INV-20260611093118', '2026-06-11 13:31:18', 1, 2, 1, '', '', 'cash', 4, 10.00, 65.00, 650.00, 0.00, 0.00, 650.00, 1000.00, 350.00, NULL, 0),
(8, 'INV-20260611120749', '2026-06-11 16:07:49', 1, 2, 1, '', '', 'cash', 4, 10.00, 65.00, 650.00, 0.00, 0.00, 650.00, 1000.00, 350.00, NULL, 0),
(9, 'INV-20260611120918', '2026-06-11 16:09:18', 1, 1, 1, 'Rafiqul Alam Rubel', '01782382140', 'credit', 1, 10.00, 85.00, 850.00, 42.50, 17.00, 909.50, 909.50, 0.00, NULL, 0),
(10, 'INV-20260611121716', '2026-06-11 16:17:16', 1, 2, 1, 'Rafiqul Alam Rubel', '01782382140', 'credit', 4, 10.00, 65.00, 650.00, 0.00, 0.00, 650.00, 0.00, -650.00, NULL, 0),
(11, 'INV-20260613085132', '2026-06-13 12:51:32', 1, 2, 1, '', '', 'cash', 4, 100.00, 65.00, 6500.00, 0.00, 0.00, 6500.00, 6500.00, 0.00, NULL, 0),
(12, 'INV-20260613085326', '2026-06-13 12:53:26', 1, 2, 1, '', '', 'cash', 4, 15.00, 65.00, 975.00, 0.00, 0.00, 975.00, 1000.00, 25.00, NULL, 0);

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
(3, 'Night', '22:00:00', '06:00:00', 1);

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
(1, 1, 2, '2026-06-11 12:42:54', 'sale', 'INV-20260611084254', 0.00, 10.00, 4490.00, NULL),
(2, 4, 6, '2026-06-11 12:49:15', 'sale', 'INV-20260611084915', 0.00, 10.00, 1990.00, NULL),
(3, 4, 6, '2026-06-11 12:52:54', 'sale', 'INV-20260611085254', 0.00, 10.00, 1980.00, NULL),
(4, 4, 6, '2026-06-11 13:01:33', 'sale', 'INV-20260611090133', 0.00, 10.00, 1970.00, NULL),
(5, 4, 6, '2026-06-11 13:03:37', 'sale', 'INV-20260611090337', 0.00, 15.00, 1955.00, NULL),
(6, 4, 6, '2026-06-11 13:11:39', 'sale', 'INV-20260611091138', 0.00, 10.00, 1945.00, NULL),
(7, 4, 6, '2026-06-11 13:31:18', 'sale', 'INV-20260611093118', 0.00, 10.00, 1935.00, NULL),
(8, 1, 1, '2026-06-11 13:34:08', 'receiving', 'RCV-20260611093408', 4500.00, 0.00, 9500.00, 84.00),
(17, 1, 1, '2026-06-11 15:41:55', 'adjustment', 'LEAK-9', 0.00, 600.00, 8900.00, 75.00),
(18, 1, 1, '2026-06-11 15:53:13', 'adjustment', 'LEAK-10', 0.00, 1.35, 8898.65, 75.00),
(19, 4, 6, '2026-06-11 16:07:49', 'sale', 'INV-20260611120749', 0.00, 10.00, 1925.00, NULL),
(20, 1, 2, '2026-06-11 16:09:18', 'sale', 'INV-20260611120918', 0.00, 10.00, 4480.00, NULL),
(21, 4, 6, '2026-06-11 16:17:16', 'sale', 'INV-20260611121716', 0.00, 10.00, 1915.00, NULL),
(22, 1, 1, '2026-06-13 11:39:18', 'receiving', 'RCV-20260613073918', 8750.00, 0.00, 17648.65, 62.50),
(23, 4, 6, '2026-06-13 12:51:32', 'sale', 'INV-20260613085132', 0.00, 100.00, 1815.00, NULL),
(24, 4, 6, '2026-06-13 12:53:26', 'sale', 'INV-20260613085326', 0.00, 15.00, 1800.00, NULL);

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
(20, 'low_stock_alert', '500', '2026-06-11 05:42:34');

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
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `tanks`
--

INSERT INTO `tanks` (`id`, `tank_name`, `product_id`, `capacity_liters`, `current_stock_liters`, `calibration_factor`, `is_active`) VALUES
(1, 'Diesel Tank-01', 1, 10000.00, 17648.65, 5.2345, 1),
(2, 'Diesel Tank-02', 1, 10000.00, 4480.00, 5.2345, 1),
(3, 'Petrol Tank-01', 2, 8000.00, 4000.00, 4.1234, 1),
(4, 'Petrol Tank-02', 2, 8000.00, 3500.00, 4.1234, 1),
(5, 'Octane Tank-01', 3, 5000.00, 2500.00, 3.4567, 1),
(6, 'CNG Tank-01', 4, 3000.00, 1800.00, 2.3456, 1),
(7, 'LPG Tank-01', 5, 2000.00, 1500.00, 1.2345, 1);

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
(1, 'Rafiqul Alam', 'Shop-01', 100.00, '2026-05-01', '2026-08-31', '01782382140', 0, '', '', 0.00),
(2, 'Rafiqul Alam', 'Shop-01', 1500.00, '2026-06-01', '2027-05-31', '01782382140', 1, 'admin@demo.com', '', 5000.00);

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
  `role` enum('super_admin','owner','accountant','station_manager','cashier','nozzle_operator','hr_officer','store_keeper','auditor') NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `phone`, `role`, `is_active`, `created_at`) VALUES
(1, 'admin', '0192023a7bbd73250516f069df18b500', 'Super Admin', NULL, NULL, 'super_admin', 1, '2026-06-11 05:04:37'),
(2, 'sparsha', '0192023a7bbd73250516f069df18b500', 'Sparsha', '', '', 'cashier', 1, '2026-06-11 07:39:29');

-- --------------------------------------------------------

--
-- Table structure for table `vouchers`
--

CREATE TABLE `vouchers` (
  `id` int(11) NOT NULL,
  `voucher_no` varchar(50) NOT NULL,
  `voucher_type` enum('journal','payment','receipt','contra') NOT NULL,
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
(1, 'RENT-20260611091902', 'receipt', '2026-06-11', 'Rent collection from tenant ID: 1 - Month: 2026-06', 1, NULL, 'approved', '2026-06-11 07:19:02'),
(2, 'PURCH-20260611093408', 'payment', '2026-06-11', 'Fuel purchase from Jamuna - RCV-20260611093408', 1, NULL, 'approved', '2026-06-11 07:34:08'),
(11, 'LOSS-20260611114155445', 'journal', '2026-06-11', 'Stock loss adjustment - Tank ID: 1 - Variance: 600.00 Liters', 1, NULL, 'approved', '2026-06-11 09:41:55'),
(12, 'LOSS-20260611115313523', 'journal', '2026-06-11', 'Stock loss adjustment - Tank: Diesel Tank-01 - Variance: 1.35 Liters - Rate: 75.00/L', 1, NULL, 'approved', '2026-06-11 09:53:13'),
(13, 'PURCH-20260613073918', 'payment', '2026-06-13', 'Fuel purchase from Padma Oil - RCV-20260613073918', 1, NULL, 'approved', '2026-06-13 05:39:18'),
(14, 'CASH-20260613085132775', 'receipt', '2026-06-13', 'Cash sale - Invoice: INV-20260613085132 - Amount: BDT 6500', 1, NULL, 'approved', '2026-06-13 06:51:32'),
(15, 'CASH-20260613085326926', 'receipt', '2026-06-13', 'Cash sale - Invoice: INV-20260613085326 - Amount: BDT 975', 1, NULL, 'approved', '2026-06-13 06:53:26'),
(16, 'RENT-20260613090623', 'receipt', '2026-06-13', 'Rent collection from tenant ID: 1 - Month: June 2026', 1, NULL, 'approved', '2026-06-13 07:06:23'),
(17, 'RENT-20260613090856', 'receipt', '2026-06-13', 'Rent collection from tenant ID: 2 - Month: June 2026', 1, NULL, 'approved', '2026-06-13 07:08:56');

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
(17, 11, 11, 45000.00, 0.00, 'Stock loss from tank adjustment'),
(18, 11, 3, 0.00, 45000.00, 'Inventory reduction due to loss'),
(19, 12, 11, 101.25, 0.00, 'Stock loss from Diesel Tank-01 - 1.3500000000004 Liters'),
(20, 12, 3, 0.00, 101.25, 'Inventory reduction due to loss'),
(21, 14, 1, 6500.00, 0.00, 'Cash sale - Invoice: INV-20260613085132'),
(22, 14, 8, 0.00, 6500.00, 'Fuel sale revenue - Invoice: INV-20260613085132'),
(23, 15, 1, 975.00, 0.00, 'Cash sale - Invoice: INV-20260613085326'),
(24, 15, 8, 0.00, 975.00, 'Fuel sale revenue - Invoice: INV-20260613085326');

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
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`employee_id`,`attendance_date`);

--
-- Indexes for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `account_code` (`account_code`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Indexes for table `credit_payments`
--
ALTER TABLE `credit_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `credit_sale_id` (`credit_sale_id`);

--
-- Indexes for table `credit_sales`
--
ALTER TABLE `credit_sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `customer_id` (`customer_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `customer_code` (`customer_code`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_id` (`employee_id`);

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
  ADD KEY `approved_by` (`approved_by`);

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
  ADD KEY `employee_id` (`employee_id`);

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
  ADD KEY `shift_id` (`shift_id`),
  ADD KEY `nozzle_id` (`nozzle_id`),
  ADD KEY `operator_id` (`operator_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `shifts`
--
ALTER TABLE `shifts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `stock_ledger`
--
ALTER TABLE `stock_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `tank_id` (`tank_id`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fuel_products`
--
ALTER TABLE `fuel_products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `fuel_receivings`
--
ALTER TABLE `fuel_receivings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `leakage_adjustments`
--
ALTER TABLE `leakage_adjustments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

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
-- AUTO_INCREMENT for table `nozzles`
--
ALTER TABLE `nozzles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `shifts`
--
ALTER TABLE `shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `stock_ledger`
--
ALTER TABLE `stock_ledger`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `tanks`
--
ALTER TABLE `tanks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `tenants`
--
ALTER TABLE `tenants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `vouchers`
--
ALTER TABLE `vouchers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `voucher_items`
--
ALTER TABLE `voucher_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  ADD CONSTRAINT `chart_of_accounts_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `chart_of_accounts` (`id`);

--
-- Constraints for table `credit_payments`
--
ALTER TABLE `credit_payments`
  ADD CONSTRAINT `credit_payments_ibfk_1` FOREIGN KEY (`credit_sale_id`) REFERENCES `credit_sales` (`id`);

--
-- Constraints for table `credit_sales`
--
ALTER TABLE `credit_sales`
  ADD CONSTRAINT `credit_sales_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`),
  ADD CONSTRAINT `credit_sales_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);

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
  ADD CONSTRAINT `fuel_receivings_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`);

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
  ADD CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`),
  ADD CONSTRAINT `sales_ibfk_2` FOREIGN KEY (`nozzle_id`) REFERENCES `nozzles` (`id`),
  ADD CONSTRAINT `sales_ibfk_3` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `sales_ibfk_4` FOREIGN KEY (`product_id`) REFERENCES `fuel_products` (`id`);

--
-- Constraints for table `stock_ledger`
--
ALTER TABLE `stock_ledger`
  ADD CONSTRAINT `stock_ledger_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `fuel_products` (`id`),
  ADD CONSTRAINT `stock_ledger_ibfk_2` FOREIGN KEY (`tank_id`) REFERENCES `tanks` (`id`);

--
-- Constraints for table `tanks`
--
ALTER TABLE `tanks`
  ADD CONSTRAINT `tanks_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `fuel_products` (`id`);

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

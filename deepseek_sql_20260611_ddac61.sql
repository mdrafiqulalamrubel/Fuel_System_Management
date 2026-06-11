-- Database: fuel_station_management

CREATE DATABASE IF NOT EXISTS fuel_station_management;
USE fuel_station_management;

-- Users & Roles
CREATE TABLE `users` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100),
  `phone` VARCHAR(20),
  `role` ENUM('super_admin','owner','accountant','station_manager','cashier','nozzle_operator','hr_officer','store_keeper','auditor') NOT NULL,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Chart of Accounts
CREATE TABLE `chart_of_accounts` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `account_code` VARCHAR(20) UNIQUE NOT NULL,
  `account_name` VARCHAR(100) NOT NULL,
  `account_type` ENUM('asset','liability','equity','income','expense') NOT NULL,
  `parent_id` INT DEFAULT NULL,
  `opening_balance` DECIMAL(15,2) DEFAULT 0,
  `balance_type` ENUM('debit','credit') DEFAULT 'debit',
  `is_active` BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (`parent_id`) REFERENCES `chart_of_accounts`(`id`)
);

-- Opening Balances
CREATE TABLE `opening_balances` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `account_id` INT NOT NULL,
  `balance_date` DATE NOT NULL,
  `debit_amount` DECIMAL(15,2) DEFAULT 0,
  `credit_amount` DECIMAL(15,2) DEFAULT 0,
  `notes` TEXT,
  FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts`(`id`)
);

-- Vouchers
CREATE TABLE `vouchers` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `voucher_no` VARCHAR(50) UNIQUE NOT NULL,
  `voucher_type` ENUM('journal','payment','receipt','contra') NOT NULL,
  `date` DATE NOT NULL,
  `narration` TEXT,
  `created_by` INT NOT NULL,
  `approved_by` INT,
  `status` ENUM('draft','approved','rejected') DEFAULT 'draft',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`),
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`)
);

CREATE TABLE `voucher_items` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `voucher_id` INT NOT NULL,
  `account_id` INT NOT NULL,
  `debit_amount` DECIMAL(15,2) DEFAULT 0,
  `credit_amount` DECIMAL(15,2) DEFAULT 0,
  `description` TEXT,
  FOREIGN KEY (`voucher_id`) REFERENCES `vouchers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts`(`id`)
);

-- Fuel Products
CREATE TABLE `fuel_products` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `product_name` ENUM('Diesel','Petrol','Octane','CNG','LPG') NOT NULL,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `purchase_rate` DECIMAL(10,2) NOT NULL,
  `vat_percentage` DECIMAL(5,2) DEFAULT 0,
  `tax_percentage` DECIMAL(5,2) DEFAULT 0,
  `is_active` BOOLEAN DEFAULT TRUE
);

-- Tanks
CREATE TABLE `tanks` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `tank_name` VARCHAR(50) NOT NULL,
  `product_id` INT NOT NULL,
  `capacity_liters` DECIMAL(10,2) NOT NULL,
  `current_stock_liters` DECIMAL(10,2) DEFAULT 0,
  `calibration_factor` DECIMAL(10,4) DEFAULT 1,
  FOREIGN KEY (`product_id`) REFERENCES `fuel_products`(`id`)
);

-- Nozzles
CREATE TABLE `nozzles` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `nozzle_name` VARCHAR(50) NOT NULL,
  `tank_id` INT NOT NULL,
  `opening_meter` DECIMAL(10,2) DEFAULT 0,
  `closing_meter` DECIMAL(10,2) DEFAULT 0,
  `is_active` BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (`tank_id`) REFERENCES `tanks`(`id`)
);

-- Shifts
CREATE TABLE `shifts` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `shift_name` ENUM('Morning','Evening','Night') NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `is_active` BOOLEAN DEFAULT TRUE
);

-- Sales
CREATE TABLE `sales` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `invoice_no` VARCHAR(50) UNIQUE NOT NULL,
  `sale_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `shift_id` INT NOT NULL,
  `nozzle_id` INT NOT NULL,
  `operator_id` INT NOT NULL,
  `customer_name` VARCHAR(100),
  `customer_phone` VARCHAR(20),
  `sale_type` ENUM('cash','credit','advance') NOT NULL,
  `product_id` INT NOT NULL,
  `quantity_liters` DECIMAL(10,2) NOT NULL,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL,
  `vat_amount` DECIMAL(10,2) DEFAULT 0,
  `tax_amount` DECIMAL(10,2) DEFAULT 0,
  `total_amount` DECIMAL(10,2) NOT NULL,
  `received_amount` DECIMAL(10,2),
  `change_amount` DECIMAL(10,2),
  `credit_due_date` DATE,
  `is_printed` BOOLEAN DEFAULT FALSE,
  FOREIGN KEY (`shift_id`) REFERENCES `shifts`(`id`),
  FOREIGN KEY (`nozzle_id`) REFERENCES `nozzles`(`id`),
  FOREIGN KEY (`operator_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`product_id`) REFERENCES `fuel_products`(`id`)
);

-- Fuel Receiving (Lorry/Tanker)
CREATE TABLE `fuel_receivings` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `receipt_no` VARCHAR(50) UNIQUE NOT NULL,
  `receipt_date` DATE NOT NULL,
  `supplier_name` VARCHAR(100) NOT NULL,
  `tanker_no` VARCHAR(50),
  `challan_no` VARCHAR(50),
  `product_id` INT NOT NULL,
  `tank_id` INT NOT NULL,
  `expected_quantity` DECIMAL(10,2) NOT NULL,
  `actual_quantity` DECIMAL(10,2) NOT NULL,
  `shortage` DECIMAL(10,2) DEFAULT 0,
  `freight_cost` DECIMAL(10,2) DEFAULT 0,
  `freight_deduction` DECIMAL(10,2) DEFAULT 0,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `total_amount` DECIMAL(10,2) NOT NULL,
  `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` INT,
  FOREIGN KEY (`product_id`) REFERENCES `fuel_products`(`id`),
  FOREIGN KEY (`tank_id`) REFERENCES `tanks`(`id`),
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`)
);

-- Stock Ledger
CREATE TABLE `stock_ledger` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `product_id` INT NOT NULL,
  `tank_id` INT NOT NULL,
  `transaction_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `transaction_type` ENUM('opening','receiving','sale','adjustment','transfer_in','transfer_out') NOT NULL,
  `reference_no` VARCHAR(50),
  `in_quantity` DECIMAL(10,2) DEFAULT 0,
  `out_quantity` DECIMAL(10,2) DEFAULT 0,
  `balance_quantity` DECIMAL(10,2) NOT NULL,
  `unit_cost` DECIMAL(10,2),
  FOREIGN KEY (`product_id`) REFERENCES `fuel_products`(`id`),
  FOREIGN KEY (`tank_id`) REFERENCES `tanks`(`id`)
);

-- Leakage/Wastage Adjustments
CREATE TABLE `leakage_adjustments` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `adjustment_date` DATE NOT NULL,
  `tank_id` INT NOT NULL,
  `system_stock` DECIMAL(10,2) NOT NULL,
  `physical_stock` DECIMAL(10,2) NOT NULL,
  `variance` DECIMAL(10,2) NOT NULL,
  `dip_stick_reading` DECIMAL(10,2),
  `reason` TEXT,
  `adjustment_type` ENUM('leakage','wastage','theft','error') NOT NULL,
  `loss_amount` DECIMAL(10,2),
  `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` INT,
  `created_by` INT,
  FOREIGN KEY (`tank_id`) REFERENCES `tanks`(`id`),
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
);

-- Employees
CREATE TABLE `employees` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `employee_id` VARCHAR(50) UNIQUE NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `designation` VARCHAR(50),
  `department` VARCHAR(50),
  `joining_date` DATE,
  `basic_salary` DECIMAL(10,2),
  `phone` VARCHAR(20),
  `address` TEXT,
  `bank_account_no` VARCHAR(50),
  `is_active` BOOLEAN DEFAULT TRUE
);

-- Attendance
CREATE TABLE `attendance` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `employee_id` INT NOT NULL,
  `attendance_date` DATE NOT NULL,
  `check_in_time` TIME,
  `check_out_time` TIME,
  `status` ENUM('present','absent','late','half_day') DEFAULT 'absent',
  `overtime_hours` DECIMAL(5,2) DEFAULT 0,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`),
  UNIQUE KEY `unique_attendance` (`employee_id`, `attendance_date`)
);

-- Payroll
CREATE TABLE `payroll` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `employee_id` INT NOT NULL,
  `month_year` DATE NOT NULL,
  `basic_salary` DECIMAL(10,2),
  `allowances` DECIMAL(10,2) DEFAULT 0,
  `overtime_amount` DECIMAL(10,2) DEFAULT 0,
  `bonus` DECIMAL(10,2) DEFAULT 0,
  `deductions` DECIMAL(10,2) DEFAULT 0,
  `net_salary` DECIMAL(10,2),
  `status` ENUM('pending','paid') DEFAULT 'pending',
  `payment_date` DATE,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`)
);

-- Tenants/Rental
CREATE TABLE `tenants` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `tenant_name` VARCHAR(100) NOT NULL,
  `shop_no` VARCHAR(50),
  `monthly_rent` DECIMAL(10,2) NOT NULL,
  `agreement_start` DATE,
  `agreement_end` DATE,
  `phone` VARCHAR(20),
  `is_active` BOOLEAN DEFAULT TRUE
);

CREATE TABLE `rent_payments` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,
  `payment_date` DATE NOT NULL,
  `month` DATE NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `late_fee` DECIMAL(10,2) DEFAULT 0,
  `receipt_no` VARCHAR(50),
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`)
);

-- Loans
CREATE TABLE `loans` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `loan_type` ENUM('given','received') NOT NULL,
  `party_name` VARCHAR(100) NOT NULL,
  `principal_amount` DECIMAL(12,2) NOT NULL,
  `interest_rate` DECIMAL(5,2),
  `start_date` DATE NOT NULL,
  `end_date` DATE,
  `status` ENUM('active','closed','defaulted') DEFAULT 'active'
);

CREATE TABLE `loan_installments` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `loan_id` INT NOT NULL,
  `due_date` DATE NOT NULL,
  `installment_amount` DECIMAL(12,2) NOT NULL,
  `paid_amount` DECIMAL(12,2) DEFAULT 0,
  `payment_date` DATE,
  `status` ENUM('pending','paid','partial') DEFAULT 'pending',
  FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`) ON DELETE CASCADE
);

-- Other Income
CREATE TABLE `other_income` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `income_date` DATE NOT NULL,
  `income_type` ENUM('advertisement','space_rental','service_charge','miscellaneous') NOT NULL,
  `description` TEXT,
  `amount` DECIMAL(10,2) NOT NULL,
  `received_from` VARCHAR(100),
  `voucher_id` INT,
  FOREIGN KEY (`voucher_id`) REFERENCES `vouchers`(`id`)
);

-- Expenses
CREATE TABLE `expenses` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `expense_date` DATE NOT NULL,
  `expense_type` ENUM('electricity','generator_fuel','maintenance','repair','other') NOT NULL,
  `description` TEXT,
  `amount` DECIMAL(10,2) NOT NULL,
  `vendor_name` VARCHAR(100),
  `voucher_id` INT,
  FOREIGN KEY (`voucher_id`) REFERENCES `vouchers`(`id`)
);

-- Activity Logs
CREATE TABLE `activity_logs` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `action` VARCHAR(100),
  `description` TEXT,
  `ip_address` VARCHAR(45),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
);

-- Insert Default Data
INSERT INTO `users` (`username`, `password`, `full_name`, `role`) VALUES 
('admin', MD5('admin123'), 'Super Admin', 'super_admin');

INSERT INTO `shifts` (`shift_name`, `start_time`, `end_time`) VALUES
('Morning', '06:00:00', '14:00:00'),
('Evening', '14:00:00', '22:00:00'),
('Night', '22:00:00', '06:00:00');

INSERT INTO `fuel_products` (`product_name`, `unit_price`, `purchase_rate`, `vat_percentage`, `tax_percentage`) VALUES
('Diesel', 85.00, 75.00, 5.00, 2.00),
('Petrol', 120.00, 105.00, 5.00, 2.00),
('Octane', 130.00, 115.00, 5.00, 2.00),
('CNG', 65.00, 55.00, 0, 0),
('LPG', 95.00, 85.00, 5.00, 2.00);

INSERT INTO `chart_of_accounts` (`account_code`, `account_name`, `account_type`) VALUES
('1000', 'Cash Account', 'asset'),
('1100', 'Bank Account', 'asset'),
('1200', 'Fuel Inventory', 'asset'),
('1300', 'Accounts Receivable', 'asset'),
('2000', 'Accounts Payable', 'liability'),
('2100', 'Loan Payable', 'liability'),
('3000', 'Owner\'s Equity', 'equity'),
('4000', 'Fuel Sales', 'income'),
('4100', 'Rental Income', 'income'),
('5000', 'Fuel Purchase', 'expense'),
('5100', 'Salary Expense', 'expense'),
('5200', 'Utility Expense', 'expense');
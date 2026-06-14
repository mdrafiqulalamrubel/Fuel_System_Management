-- Disable foreign key checks
SET FOREIGN_KEY_CHECKS = 0;

-- Truncate all transaction tables
TRUNCATE TABLE activity_logs;
TRUNCATE TABLE attendance;
TRUNCATE TABLE credit_payments;
TRUNCATE TABLE credit_sales;
TRUNCATE TABLE customers;
TRUNCATE TABLE employees;
TRUNCATE TABLE expenses;
TRUNCATE TABLE fuel_receivings;
TRUNCATE TABLE leakage_adjustments;
TRUNCATE TABLE loans;
TRUNCATE TABLE loan_installments;
TRUNCATE TABLE opening_balances;
TRUNCATE TABLE other_income;
TRUNCATE TABLE payroll;
TRUNCATE TABLE rent_payments;
TRUNCATE TABLE sales;
TRUNCATE TABLE stock_ledger;
TRUNCATE TABLE tenants;
TRUNCATE TABLE vouchers;
TRUNCATE TABLE voucher_items;

-- DON'T truncate users - just delete non-admin users
DELETE FROM users WHERE id > 1;

-- Reset auto-increments
ALTER TABLE activity_logs AUTO_INCREMENT = 1;
ALTER TABLE attendance AUTO_INCREMENT = 1;
ALTER TABLE credit_payments AUTO_INCREMENT = 1;
ALTER TABLE credit_sales AUTO_INCREMENT = 1;
ALTER TABLE customers AUTO_INCREMENT = 1;
ALTER TABLE employees AUTO_INCREMENT = 1;
ALTER TABLE expenses AUTO_INCREMENT = 1;
ALTER TABLE fuel_receivings AUTO_INCREMENT = 1;
ALTER TABLE leakage_adjustments AUTO_INCREMENT = 1;
ALTER TABLE loans AUTO_INCREMENT = 1;
ALTER TABLE loan_installments AUTO_INCREMENT = 1;
ALTER TABLE opening_balances AUTO_INCREMENT = 1;
ALTER TABLE other_income AUTO_INCREMENT = 1;
ALTER TABLE payroll AUTO_INCREMENT = 1;
ALTER TABLE rent_payments AUTO_INCREMENT = 1;
ALTER TABLE sales AUTO_INCREMENT = 1;
ALTER TABLE stock_ledger AUTO_INCREMENT = 1;
ALTER TABLE tenants AUTO_INCREMENT = 1;
ALTER TABLE users AUTO_INCREMENT = 2;  -- Start from 2 (admin is id=1)
ALTER TABLE vouchers AUTO_INCREMENT = 1;
ALTER TABLE voucher_items AUTO_INCREMENT = 1;

-- Reset master data values
UPDATE tanks SET current_stock_liters = 0.00;
UPDATE chart_of_accounts SET opening_balance = 0.00;
UPDATE nozzles SET opening_meter = 0.00, closing_meter = 0.00;
UPDATE customers SET opening_balance = 0.00, current_balance = 0.00;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Verify
SELECT 
    (SELECT COUNT(*) FROM sales) AS sales_count,
    (SELECT COUNT(*) FROM stock_ledger) AS stock_count,
    (SELECT COUNT(*) FROM users) AS users_count,
    (SELECT SUM(current_stock_liters) FROM tanks) AS total_stock;

SELECT 'Database refresh completed successfully!' AS status;
-- =====================================================
-- COMPLETE DATABASE RESET - START FRESH
-- All transactions, stocks, balances will be ZERO
-- =====================================================

START TRANSACTION;

-- Disable foreign key checks
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- 1. DELETE ALL TRANSACTIONAL DATA
-- =====================================================

-- Delete all sales records
TRUNCATE TABLE sales;

-- Delete all credit sales and payments
TRUNCATE TABLE credit_sales;
TRUNCATE TABLE credit_payments;

-- Delete all fuel receiving records
TRUNCATE TABLE fuel_receivings;

-- Delete all stock ledger entries
TRUNCATE TABLE stock_ledger;

-- Delete all leakage adjustments
TRUNCATE TABLE leakage_adjustments;

-- Delete all rental payments
TRUNCATE TABLE rent_payments;

-- Delete all tenants
TRUNCATE TABLE tenants;

-- Delete all customers
TRUNCATE TABLE customers;

-- Delete all employees
TRUNCATE TABLE employees;

-- Delete all attendance records
TRUNCATE TABLE attendance;

-- Delete all payroll records
TRUNCATE TABLE payroll;

-- Delete all expenses
TRUNCATE TABLE expenses;

-- Delete all other income
TRUNCATE TABLE other_income;

-- Delete all opening balances
TRUNCATE TABLE opening_balances;

-- Delete all loans
TRUNCATE TABLE loans;

-- Delete all loan installments
TRUNCATE TABLE loan_installments;

-- Delete all voucher items (accounting entries)
TRUNCATE TABLE voucher_items;

-- Delete all vouchers
TRUNCATE TABLE vouchers;

-- Delete all activity logs
TRUNCATE TABLE activity_logs;

-- =====================================================
-- 2. RESET ALL STOCKS TO ZERO
-- =====================================================

UPDATE tanks SET current_stock_liters = 0.00;

-- =====================================================
-- 3. RESET NOZZLE METERS TO ZERO
-- =====================================================

UPDATE nozzles SET opening_meter = 0.00, closing_meter = 0.00;

-- =====================================================
-- 4. RESET ALL CHART OF ACCOUNTS BALANCES TO ZERO
-- =====================================================

UPDATE chart_of_accounts SET opening_balance = 0.00;

-- =====================================================
-- 5. RESET ALL AUTO_INCREMENT COUNTERS
-- =====================================================

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
ALTER TABLE vouchers AUTO_INCREMENT = 1;
ALTER TABLE voucher_items AUTO_INCREMENT = 1;

-- =====================================================
-- 6. VERIFY EVERYTHING IS EMPTY
-- =====================================================

SELECT 
    (SELECT COUNT(*) FROM sales) AS sales_count,
    (SELECT COUNT(*) FROM stock_ledger) AS stock_ledger_count,
    (SELECT COUNT(*) FROM fuel_receivings) AS fuel_receivings_count,
    (SELECT COUNT(*) FROM vouchers) AS vouchers_count,
    (SELECT COUNT(*) FROM voucher_items) AS voucher_items_count,
    (SELECT COUNT(*) FROM leakage_adjustments) AS leakage_count,
    (SELECT SUM(current_stock_liters) FROM tanks) AS total_stock;

-- =====================================================
-- 7. RE-ENABLE FOREIGN KEY CHECKS
-- =====================================================

SET FOREIGN_KEY_CHECKS = 1;

COMMIT;

SELECT '✅ DATABASE COMPLETELY RESET! All transactions, stocks, and balances are ZERO.' AS status;
SELECT 'You can now start fresh with clean data.' AS message;


-- Set Owner's Equity opening balance (replace 100000 with your amount)
UPDATE chart_of_accounts 
SET opening_balance = 150000.00 
WHERE account_code = '3000' OR account_name = "Owner's Equity";


-- Set Cash Account opening balance (replace 50000 with your amount)
UPDATE chart_of_accounts 
SET opening_balance = 50000.00 
WHERE account_code = '1000' OR account_name = 'Cash Account';

-- Set Bank Account opening balance (replace 100000 with your amount)
UPDATE chart_of_accounts 
SET opening_balance = 100000.00 
WHERE account_code = '1100' OR account_name = 'Bank Account';

-- Set initial stock for tanks (replace with your actual stock)
UPDATE tanks SET current_stock_liters = 
    CASE 
        WHEN tank_name = 'Diesel Tank-01' THEN 8740.00
        WHEN tank_name = 'CNG Tank-01' THEN 24430.90
        -- Add other tanks as needed
        ELSE 0
    END;

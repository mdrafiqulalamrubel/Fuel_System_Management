-- Add to your database
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `setting_key` VARCHAR(100) UNIQUE NOT NULL,
    `setting_value` TEXT,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('company_name', 'FF Enterprise'),
('company_phone', '+880 1234 567890'),
('company_email', 'info@ffenterprise.com'),
('company_address', 'Dhaka, Bangladesh'),
('vat_reg_no', '123456789'),
('tax_percentage', '2'),
('vat_percentage', '5'),
('currency_symbol', '৳'),
('invoice_footer', '*** THANK YOU ***'),
('low_stock_alert', '500');
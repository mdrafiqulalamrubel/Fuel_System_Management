<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$customer_id = isset($_GET['customer_id']) ? $_GET['customer_id'] : '';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-t');

// Get all active customers
$customers = $pdo->query("SELECT * FROM customers WHERE is_active = 1 ORDER BY customer_name")->fetchAll();

$customer = null;
$transactions = [];
$opening_balance = 0;

// =============================================
// CHECK IF TABLES AND COLUMNS EXIST
// =============================================

// Check if credit_sales table has advance_adjusted column
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM credit_sales LIKE 'advance_adjusted'");
    $has_advance_adjusted_credit = $stmt->rowCount() > 0;
} catch(PDOException $e) {
    $has_advance_adjusted_credit = false;
}

// Check if gas_sales table has advance_adjusted column
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM gas_sales LIKE 'advance_adjusted'");
    $has_advance_adjusted_gas = $stmt->rowCount() > 0;
} catch(PDOException $e) {
    $has_advance_adjusted_gas = false;
}

// Check if item_credit_sales table exists
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'item_credit_sales'");
    $has_item_credit_sales = $stmt->rowCount() > 0;
} catch(PDOException $e) {
    $has_item_credit_sales = false;
}

// Check if item_credit_sales table has advance_adjusted column
$has_advance_adjusted_item = false;
if($has_item_credit_sales) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM item_credit_sales LIKE 'advance_adjusted'");
        $has_advance_adjusted_item = $stmt->rowCount() > 0;
    } catch(PDOException $e) {
        $has_advance_adjusted_item = false;
    }
}

// Check if item_sales table exists
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'item_sales'");
    $has_item_sales = $stmt->rowCount() > 0;
} catch(PDOException $e) {
    $has_item_sales = false;
}

// Check if item_credit_payments table exists
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'item_credit_payments'");
    $has_item_credit_payments = $stmt->rowCount() > 0;
} catch(PDOException $e) {
    $has_item_credit_payments = false;
}

if($customer_id) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    
    if($customer) {
        // =============================================
        // Calculate OPENING BALANCE (before from_date)
        // =============================================
        
        // 1. Get all fuel credit sales before from_date (Debit - increases balance/DUE)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as total_sales
            FROM credit_sales 
            WHERE customer_id = ? AND sale_date < ?
        ");
        $stmt->execute([$customer_id, $from_date]);
        $fuel_credit_sales_before = $stmt->fetch()['total_sales'];
        
        // 2. Get all CNG credit sales before from_date (Debit - increases balance/DUE)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as total_sales
            FROM gas_sales 
            WHERE customer_id = ? AND sale_type = 'credit' AND status = 'completed' AND sale_date < ?
        ");
        $stmt->execute([$customer_id, $from_date]);
        $cng_credit_sales_before = $stmt->fetch()['total_sales'];
        
        // 3. Get all item credit sales before from_date (Debit - increases balance/DUE)
        $item_credit_sales_before = 0;
        if($has_item_credit_sales) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(total_amount), 0) as total_sales
                FROM item_credit_sales 
                WHERE customer_id = ? AND sale_date < ?
            ");
            $stmt->execute([$customer_id, $from_date]);
            $item_credit_sales_before = $stmt->fetch()['total_sales'];
        }
        
        $total_credit_sales_before = $fuel_credit_sales_before + $cng_credit_sales_before + $item_credit_sales_before;
        
        // 4. Get all advance used before from_date (Credit - decreases balance/DUE)
        $advance_used_before = 0;
        
        // From fuel credit_sales
        if($has_advance_adjusted_credit) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(advance_adjusted), 0) as total_advance_used
                FROM credit_sales 
                WHERE customer_id = ? AND sale_date < ?
                AND advance_adjusted > 0
            ");
            $stmt->execute([$customer_id, $from_date]);
            $advance_used_before += $stmt->fetch()['total_advance_used'];
        }
        
        // From CNG gas_sales
        if($has_advance_adjusted_gas) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(advance_adjusted), 0) as total_advance_used
                FROM gas_sales 
                WHERE customer_id = ? AND sale_type = 'credit' AND status = 'completed' AND sale_date < ?
                AND advance_adjusted > 0
            ");
            $stmt->execute([$customer_id, $from_date]);
            $advance_used_before += $stmt->fetch()['total_advance_used'];
        }
        
        // From item_credit_sales
        if($has_advance_adjusted_item && $has_item_credit_sales) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(advance_adjusted), 0) as total_advance_used
                FROM item_credit_sales 
                WHERE customer_id = ? AND sale_date < ?
                AND advance_adjusted > 0
            ");
            $stmt->execute([$customer_id, $from_date]);
            $advance_used_before += $stmt->fetch()['total_advance_used'];
        }
        
        // 5. Get all payments received before from_date (Credit - decreases balance/DUE)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(cp.amount), 0) as total_payments
            FROM credit_payments cp
            JOIN credit_sales cs ON cp.credit_sale_id = cs.id
            WHERE cs.customer_id = ? AND cp.payment_date < ?
        ");
        $stmt->execute([$customer_id, $from_date]);
        $payments_before = $stmt->fetch()['total_payments'];
        
        // 6. Get all item credit payments before from_date
        $item_payments_before = 0;
        if($has_item_credit_payments && $has_item_credit_sales) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(cp.amount), 0) as total_payments
                FROM item_credit_payments cp
                JOIN item_credit_sales cs ON cp.item_credit_sale_id = cs.id
                WHERE cs.customer_id = ? AND cp.payment_date < ?
            ");
            $stmt->execute([$customer_id, $from_date]);
            $item_payments_before = $stmt->fetch()['total_payments'];
        }
        $payments_before += $item_payments_before;
        
        // 7. Get all advance received before from_date (Credit - decreases balance/DUE)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_advance
            FROM advance_payments_customer 
            WHERE customer_id = ? AND advance_date < ?
            AND status != 'cancelled'
        ");
        $stmt->execute([$customer_id, $from_date]);
        $advance_received_before = $stmt->fetch()['total_advance'];
        
        // 8. Get all voucher payments before from_date (Credit - decreases balance/DUE)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(vi.credit_amount), 0) as total_voucher_payments
            FROM vouchers v
            JOIN voucher_items vi ON v.id = vi.voucher_id
            JOIN chart_of_accounts ca ON vi.account_id = ca.id
            WHERE v.customer_id = ? 
            AND v.date < ?
            AND (ca.account_code LIKE '1300' OR ca.account_name LIKE '%Accounts Receivable%')
            AND v.status = 'approved'
        ");
        $stmt->execute([$customer_id, $from_date]);
        $voucher_payments_before = $stmt->fetch()['total_voucher_payments'];
        
        // OPENING BALANCE = (Credit Sales) - (Advance Used + Payments + Advance Received + Voucher Payments)
        $opening_balance = $total_credit_sales_before - ($advance_used_before + $payments_before + $advance_received_before + $voucher_payments_before);
        
        // =============================================
        // Get all transactions for the period
        // =============================================
        
        // 1. FUEL CASH SALES - FOR DISPLAY ONLY (DO NOT AFFECT BALANCE)
        $stmt = $pdo->prepare("
            SELECT 
                sale_date as trans_date,
                invoice_no as ref_no,
                total_amount as amount,
                NULL as due_date,
                'cash_sale' as trans_type,
                payment_method,
                NULL as receipt_no,
                total_amount as debit,
                0 as credit,
                id as sale_id,
                CONCAT('Fuel Cash Sale - ', invoice_no) as description,
                'sales' as source_table,
                id as source_id
            FROM sales 
            WHERE customer_id = ? AND sale_date BETWEEN ? AND ?
            AND sale_type = 'cash'
            ORDER BY sale_date ASC
        ");
        $stmt->execute([$customer_id, $from_date, $to_date]);
        $cash_sales = $stmt->fetchAll();
        
        // 2. ITEM CASH SALES - FOR DISPLAY ONLY
        $item_cash_sales = [];
        if($has_item_sales) {
            $stmt = $pdo->prepare("
                SELECT 
                    sale_date as trans_date,
                    invoice_no as ref_no,
                    total_amount as amount,
                    NULL as due_date,
                    'cash_sale' as trans_type,
                    payment_method,
                    NULL as receipt_no,
                    total_amount as debit,
                    0 as credit,
                    id as sale_id,
                    CONCAT('Item Cash Sale - ', invoice_no) as description,
                    'item_sales' as source_table,
                    id as source_id
                FROM item_sales 
                WHERE customer_id = ? AND sale_date BETWEEN ? AND ?
                AND sale_type = 'cash'
                ORDER BY sale_date ASC
            ");
            $stmt->execute([$customer_id, $from_date, $to_date]);
            $item_cash_sales = $stmt->fetchAll();
        }
        
        // Merge all cash sales
        $cash_sales = array_merge($cash_sales, $item_cash_sales);
        usort($cash_sales, function($a, $b) {
            return strtotime($a['trans_date']) - strtotime($b['trans_date']);
        });
        
        // 3. FUEL CREDIT SALES - FULL AMOUNT in DEBIT
        // Build the query based on whether advance_adjusted exists
        $fuel_advance_field = $has_advance_adjusted_credit ? 'COALESCE(cs.advance_adjusted, 0) as advance_adjusted' : '0 as advance_adjusted';
        $stmt = $pdo->prepare("
            SELECT 
                cs.sale_date as trans_date,
                cs.invoice_no as ref_no,
                COALESCE(cs.total_amount, 0) as amount,
                cs.due_date,
                'credit_sale' as trans_type,
                NULL as payment_method,
                NULL as receipt_no,
                COALESCE(cs.total_amount, 0) as debit,
                0 as credit,
                $fuel_advance_field,
                cs.id as sale_id,
                CONCAT('Fuel Credit Sale - ', cs.invoice_no) as description,
                'credit_sales' as source_table,
                cs.id as source_id
            FROM credit_sales cs
            WHERE cs.customer_id = ? AND cs.sale_date BETWEEN ? AND ?
            ORDER BY cs.sale_date ASC
        ");
        $stmt->execute([$customer_id, $from_date, $to_date]);
        $credit_sales_fuel = $stmt->fetchAll();
        
        // 4. CNG CREDIT SALES - FULL AMOUNT in DEBIT
        // Build the query based on whether advance_adjusted exists in gas_sales
        $cng_advance_field = $has_advance_adjusted_gas ? 'COALESCE(advance_adjusted, 0) as advance_adjusted' : '0 as advance_adjusted';
        $stmt = $pdo->prepare("
            SELECT 
                sale_date as trans_date,
                invoice_no as ref_no,
                COALESCE(total_amount, 0) as amount,
                DATE_ADD(sale_date, INTERVAL 30 DAY) as due_date,
                'credit_sale' as trans_type,
                NULL as payment_method,
                NULL as receipt_no,
                COALESCE(total_amount, 0) as debit,
                0 as credit,
                $cng_advance_field,
                id as sale_id,
                CONCAT('CNG Credit Sale - ', invoice_no) as description,
                'gas_sales' as source_table,
                id as source_id
            FROM gas_sales 
            WHERE customer_id = ? AND sale_type = 'credit' AND status = 'completed'
            AND sale_date BETWEEN ? AND ?
            ORDER BY sale_date ASC
        ");
        $stmt->execute([$customer_id, $from_date, $to_date]);
        $credit_sales_cng = $stmt->fetchAll();
        
        // 5. ITEM CREDIT SALES - FULL AMOUNT in DEBIT
        $credit_sales_item = [];
        if($has_item_credit_sales) {
            // Build the query based on whether advance_adjusted exists in item_credit_sales
            $item_advance_field = $has_advance_adjusted_item ? 'COALESCE(advance_adjusted, 0) as advance_adjusted' : '0 as advance_adjusted';
            
            $stmt = $pdo->prepare("
                SELECT 
                    sale_date as trans_date,
                    invoice_no as ref_no,
                    COALESCE(total_amount, 0) as amount,
                    due_date,
                    'credit_sale' as trans_type,
                    NULL as payment_method,
                    NULL as receipt_no,
                    COALESCE(total_amount, 0) as debit,
                    0 as credit,
                    $item_advance_field,
                    id as sale_id,
                    CONCAT('Item Credit Sale - ', invoice_no) as description,
                    'item_credit_sales' as source_table,
                    id as source_id
                FROM item_credit_sales 
                WHERE customer_id = ? AND sale_date BETWEEN ? AND ?
                ORDER BY sale_date ASC
            ");
            $stmt->execute([$customer_id, $from_date, $to_date]);
            $credit_sales_item = $stmt->fetchAll();
        }
        
        // Merge all credit sales
        $credit_sales = array_merge($credit_sales_fuel, $credit_sales_cng, $credit_sales_item);
        usort($credit_sales, function($a, $b) {
            return strtotime($a['trans_date']) - strtotime($b['trans_date']);
        });
        
        // 6. ADVANCE USED - CREDIT entry (decreases balance)
        $advance_used = [];
        
        // From fuel credit_sales
        if($has_advance_adjusted_credit) {
            $stmt = $pdo->prepare("
                SELECT 
                    sale_date as trans_date,
                    invoice_no as ref_no,
                    COALESCE(advance_adjusted, 0) as amount,
                    NULL as due_date,
                    'advance_used' as trans_type,
                    NULL as payment_method,
                    NULL as receipt_no,
                    0 as debit,
                    COALESCE(advance_adjusted, 0) as credit,
                    id as sale_id,
                    CONCAT('Fuel Advance Used - ', invoice_no) as description,
                    'credit_sales' as source_table,
                    id as source_id
                FROM credit_sales 
                WHERE customer_id = ? AND advance_adjusted > 0
                AND sale_date BETWEEN ? AND ?
                ORDER BY sale_date ASC
            ");
            $stmt->execute([$customer_id, $from_date, $to_date]);
            $advance_used = array_merge($advance_used, $stmt->fetchAll());
        }
        
        // From CNG gas_sales
        if($has_advance_adjusted_gas) {
            $stmt = $pdo->prepare("
                SELECT 
                    sale_date as trans_date,
                    invoice_no as ref_no,
                    COALESCE(advance_adjusted, 0) as amount,
                    NULL as due_date,
                    'advance_used' as trans_type,
                    NULL as payment_method,
                    NULL as receipt_no,
                    0 as debit,
                    COALESCE(advance_adjusted, 0) as credit,
                    id as sale_id,
                    CONCAT('CNG Advance Used - ', invoice_no) as description,
                    'gas_sales' as source_table,
                    id as source_id
                FROM gas_sales 
                WHERE customer_id = ? AND advance_adjusted > 0
                AND sale_date BETWEEN ? AND ?
                ORDER BY sale_date ASC
            ");
            $stmt->execute([$customer_id, $from_date, $to_date]);
            $advance_used = array_merge($advance_used, $stmt->fetchAll());
        }
        
        // From item_credit_sales
        if($has_advance_adjusted_item && $has_item_credit_sales) {
            $stmt = $pdo->prepare("
                SELECT 
                    sale_date as trans_date,
                    invoice_no as ref_no,
                    COALESCE(advance_adjusted, 0) as amount,
                    NULL as due_date,
                    'advance_used' as trans_type,
                    NULL as payment_method,
                    NULL as receipt_no,
                    0 as debit,
                    COALESCE(advance_adjusted, 0) as credit,
                    id as sale_id,
                    CONCAT('Item Advance Used - ', invoice_no) as description,
                    'item_credit_sales' as source_table,
                    id as source_id
                FROM item_credit_sales 
                WHERE customer_id = ? AND advance_adjusted > 0
                AND sale_date BETWEEN ? AND ?
                ORDER BY sale_date ASC
            ");
            $stmt->execute([$customer_id, $from_date, $to_date]);
            $advance_used = array_merge($advance_used, $stmt->fetchAll());
        }
        
        // Sort advance_used by date
        usort($advance_used, function($a, $b) {
            return strtotime($a['trans_date']) - strtotime($b['trans_date']);
        });
        
        // 7. PAYMENTS RECEIVED - CREDIT entry (decreases balance)
        $payments = [];
        
        // From fuel credit_payments
        $stmt = $pdo->prepare("
            SELECT 
                cp.payment_date as trans_date,
                cp.receipt_no as ref_no,
                COALESCE(cp.amount, 0) as amount,
                NULL as due_date,
                'payment' as trans_type,
                cp.payment_method,
                cp.receipt_no,
                0 as debit,
                COALESCE(cp.amount, 0) as credit,
                cp.id as payment_id,
                CONCAT('Fuel Payment Received - ', cp.receipt_no) as description,
                'credit_payments' as source_table,
                cp.id as source_id
            FROM credit_payments cp
            JOIN credit_sales cs ON cp.credit_sale_id = cs.id
            WHERE cs.customer_id = ? AND cp.payment_date BETWEEN ? AND ?
            ORDER BY cp.payment_date ASC
        ");
        $stmt->execute([$customer_id, $from_date, $to_date]);
        $payments = $stmt->fetchAll();
        
        // From item_credit_payments
        if($has_item_credit_payments && $has_item_credit_sales) {
            $stmt = $pdo->prepare("
                SELECT 
                    cp.payment_date as trans_date,
                    cp.receipt_no as ref_no,
                    COALESCE(cp.amount, 0) as amount,
                    NULL as due_date,
                    'payment' as trans_type,
                    cp.payment_method,
                    cp.receipt_no,
                    0 as debit,
                    COALESCE(cp.amount, 0) as credit,
                    cp.id as payment_id,
                    CONCAT('Item Payment Received - ', cp.receipt_no) as description,
                    'item_credit_payments' as source_table,
                    cp.id as source_id
                FROM item_credit_payments cp
                JOIN item_credit_sales cs ON cp.item_credit_sale_id = cs.id
                WHERE cs.customer_id = ? AND cp.payment_date BETWEEN ? AND ?
                ORDER BY cp.payment_date ASC
            ");
            $stmt->execute([$customer_id, $from_date, $to_date]);
            $payments = array_merge($payments, $stmt->fetchAll());
        }
        
        // Sort payments by date
        usort($payments, function($a, $b) {
            return strtotime($a['trans_date']) - strtotime($b['trans_date']);
        });
        
        // 8. VOUCHER PAYMENTS - CREDIT entry (decreases balance)
        $stmt = $pdo->prepare("
            SELECT 
                v.date as trans_date,
                v.voucher_no as ref_no,
                COALESCE(vi.credit_amount, 0) as amount,
                NULL as due_date,
                'voucher_payment' as trans_type,
                v.voucher_type as payment_method,
                v.voucher_no as receipt_no,
                0 as debit,
                COALESCE(vi.credit_amount, 0) as credit,
                v.id as voucher_id,
                CONCAT('Payment via Voucher - ', v.voucher_no) as description,
                v.narration as notes,
                'vouchers' as source_table,
                v.id as source_id
            FROM vouchers v
            JOIN voucher_items vi ON v.id = vi.voucher_id
            JOIN chart_of_accounts ca ON vi.account_id = ca.id
            WHERE v.customer_id = ? 
            AND v.date BETWEEN ? AND ?
            AND (ca.account_code LIKE '1300' OR ca.account_name LIKE '%Accounts Receivable%')
            AND v.status = 'approved'
            ORDER BY v.date ASC
        ");
        $stmt->execute([$customer_id, $from_date, $to_date]);
        $voucher_payments = $stmt->fetchAll();
        
        // 9. ADVANCE RECEIVED - CREDIT entry (decreases balance)
        $stmt = $pdo->prepare("
            SELECT 
                advance_date as trans_date,
                CONCAT('ADV-', id) as ref_no,
                COALESCE(amount, 0) as amount,
                NULL as due_date,
                'advance_received' as trans_type,
                payment_method,
                reference_no as receipt_no,
                0 as debit,
                COALESCE(amount, 0) as credit,
                id as advance_id,
                CONCAT('Advance Received - ', reference_no) as description,
                'advance_payments_customer' as source_table,
                id as source_id
            FROM advance_payments_customer 
            WHERE customer_id = ? AND advance_date BETWEEN ? AND ?
            AND status != 'cancelled'
            ORDER BY advance_date ASC
        ");
        $stmt->execute([$customer_id, $from_date, $to_date]);
        $advance_received = $stmt->fetchAll();
        
        // Initialize all variables as empty arrays
        if(!isset($cash_sales) || $cash_sales === null) $cash_sales = [];
        if(!isset($credit_sales) || $credit_sales === null) $credit_sales = [];
        if(!isset($advance_used) || $advance_used === null) $advance_used = [];
        if(!isset($payments) || $payments === null) $payments = [];
        if(!isset($voucher_payments) || $voucher_payments === null) $voucher_payments = [];
        if(!isset($advance_received) || $advance_received === null) $advance_received = [];
        
        // Merge all transactions (cash sales included for display only)
        $transactions = array_merge($cash_sales, $credit_sales, $advance_used, $payments, $voucher_payments, $advance_received);
        
        // Sort by date
        usort($transactions, function($a, $b) {
            return strtotime($a['trans_date']) - strtotime($b['trans_date']);
        });
        
        // Calculate running balance
        $running_balance = $opening_balance;
        $total_debit = 0;
        $total_credit = 0;
        
        foreach($transactions as &$t) {
            // Force numeric conversion and ensure values are set
            $t['debit'] = isset($t['debit']) ? floatval($t['debit']) : 0;
            $t['credit'] = isset($t['credit']) ? floatval($t['credit']) : 0;
            $t['amount'] = isset($t['amount']) ? floatval($t['amount']) : 0;
            
            // For credit sales, ensure debit is set from amount if not already
            if(($t['trans_type'] == 'credit_sale' || $t['trans_type'] == 'cash_sale') && $t['debit'] == 0 && $t['amount'] > 0) {
                $t['debit'] = $t['amount'];
            }
            
            // For advance used, ensure credit is set from amount if not already
            if($t['trans_type'] == 'advance_used' && $t['credit'] == 0 && $t['amount'] > 0) {
                $t['credit'] = $t['amount'];
            }
            
            $running_balance += $t['debit'] - $t['credit'];
            $t['running_balance'] = $running_balance;
            $total_debit += $t['debit'];
            $total_credit += $t['credit'];
        }
        unset($t);
        
        // Get updated customer balance
        $stmt = $pdo->prepare("SELECT current_balance, advance_balance FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer_data = $stmt->fetch();
        $customer['current_balance'] = $customer_data['current_balance'] ?? 0;
        $customer['advance_balance'] = $customer_data['advance_balance'] ?? 0;
    }
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Ledger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .clickable-row { cursor: pointer; transition: background-color 0.2s; }
        .clickable-row:hover { background-color: #e8f0fe !important; }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s;
        }
        .stats-card:hover { transform: translateY(-5px); }
        .stats-card i { font-size: 40px; opacity: 0.5; float: right; }
        .badge-cash-sale { background: #28a745; color: white; }
        .badge-credit-sale { background: #dc3545; color: white; }
        .badge-payment { background: #17a2b8; color: white; }
        .badge-voucher-payment { background: #0d6efd; color: white; }
        .badge-advance-received { background: #6f42c1; color: white; }
        .badge-advance-used { background: #ffc107; color: #856404; }
        .net-balance-card {
            border-left: 5px solid;
            border-radius: 10px;
        }
        .net-balance-card.due { border-left-color: #dc3545; }
        .net-balance-card.advance { border-left-color: #ffc107; }
        .net-balance-card.zero { border-left-color: #28a745; }
        .transaction-details { font-size: 11px; color: #666; }
        .print-header { display: none; }
        .view-btn { font-size: 11px; padding: 2px 6px; }
        .debit-amount { color: #dc3545; font-weight: bold; }
        .credit-amount { color: #28a745; font-weight: bold; }
        
        @media print {
            .sidebar, .no-print, .btn, .dataTables_length, .dataTables_filter, 
            .dataTables_paginate, form, .card-header .btn, .stats-card,
            .badge, .view-btn, .action-column, .no-print {
                display: none !important;
            }
            .bg-primary, .bg-success, .bg-info, .bg-warning, .bg-danger, .bg-dark,
            .bg-secondary, .bg-light, .bg-white, .card-header, .table-dark,
            .table-info, .table-secondary, .table-striped tbody tr:nth-of-type(odd) {
                background: #fff !important;
                color: #000 !important;
            }
            .text-white, .text-white-50, .text-white-50 * { color: #000 !important; }
            .text-success, .text-danger, .text-warning, .text-info, .text-primary {
                color: #000 !important;
            }
            .main-content {
                margin: 0 !important;
                padding: 5px !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            .container-fluid { padding: 0 !important; max-width: 100% !important; }
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 15px;
                border-bottom: 2px solid #000;
                padding-bottom: 10px;
            }
            .print-header h2 { font-size: 18px; font-weight: bold; margin-bottom: 2px; }
            .print-header h4 { font-size: 14px; margin-bottom: 2px; }
            .print-header p { font-size: 11px; margin-bottom: 2px; color: #000 !important; }
            .card {
                border: 1px solid #000 !important;
                margin-bottom: 8px !important;
                border-radius: 0 !important;
                box-shadow: none !important;
            }
            .card-header {
                border-bottom: 1px solid #000 !important;
                padding: 5px 8px !important;
                font-weight: bold;
            }
            .card-header h5, .card-header h6 { font-size: 12px !important; margin: 0 !important; color: #000 !important; }
            .card-body { padding: 5px 8px !important; }
            .table {
                border-collapse: collapse !important;
                width: 100% !important;
                font-size: 9px !important;
                margin: 0 !important;
            }
            .table th, .table td {
                border: 1px solid #000 !important;
                padding: 2px 4px !important;
                background: #fff !important;
                color: #000 !important;
            }
            .table th {
                background: #f8f9fa !important;
                font-weight: bold !important;
                border-bottom: 2px solid #000 !important;
            }
            .table thead th {
                background: #f8f9fa !important;
                border-bottom: 2px solid #000 !important;
            }
            .table tfoot th, .table tfoot td {
                background: #f8f9fa !important;
                border-top: 2px solid #000 !important;
                font-weight: bold !important;
            }
            .table-striped tbody tr:nth-of-type(odd) { background: #fff !important; }
            .table-striped tbody tr:nth-of-type(even) { background: #f9f9f9 !important; }
            .table-dark th {
                background: #f8f9fa !important;
                color: #000 !important;
                border-bottom: 2px solid #000 !important;
            }
            .table-info, .table-secondary { background: #fff !important; }
            .table-info td, .table-secondary td { background: #fff !important; color: #000 !important; }
            .alert {
                border: 1px solid #000 !important;
                background: #fff !important;
                color: #000 !important;
                padding: 5px 8px !important;
            }
            .alert-success, .alert-info, .alert-warning, .alert-danger, .alert-secondary {
                background: #fff !important;
                border: 1px solid #000 !important;
                color: #000 !important;
            }
            .badge {
                border: 1px solid #000 !important;
                background: #fff !important;
                color: #000 !important;
                padding: 1px 4px !important;
                font-size: 8px !important;
                border-radius: 2px !important;
            }
            .row { margin: 0 !important; }
            .col-md-3, .col-md-4, .col-md-6, .col-md-12, .col-md-2 {
                padding: 0 2px !important;
                width: 100% !important;
                max-width: 100% !important;
                flex: 0 0 100% !important;
            }
            ::-webkit-scrollbar { display: none; }
            .table-responsive {
                overflow: visible !important;
                -webkit-overflow-scrolling: touch !important;
            }
            .footer-note {
                border-top: 1px solid #000 !important;
                margin-top: 8px !important;
                padding-top: 4px !important;
                font-size: 8px !important;
                text-align: center !important;
                color: #000 !important;
            }
            .net-balance-card {
                border: 1px solid #000 !important;
                background: #fff !important;
                padding: 5px 10px !important;
            }
            .net-balance-card.due { border-left: 3px solid #000 !important; }
            .net-balance-card.advance { border-left: 3px solid #000 !important; }
            .net-balance-card.zero { border-left: 3px solid #000 !important; }
            .stats-card {
                background: #fff !important;
                border: 1px solid #000 !important;
                color: #000 !important;
            }
            .stats-card i { opacity: 0.5 !important; }
            .card.text-white {
                background: #fff !important;
                border: 1px solid #000 !important;
                color: #000 !important;
            }
            .card.text-white .card-body { color: #000 !important; }
            .card.text-white h6, .card.text-white h4 { color: #000 !important; }
            * {
                text-shadow: none !important;
                box-shadow: none !important;
            }
            @page {
                size: landscape;
                margin: 8mm 6mm;
            }
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="print-header">
                <h2><?php echo htmlspecialchars($settings['company_name'] ?? 'FF Enterprise'); ?></h2>
                <h4>Customer Ledger</h4>
                <p>Period: <?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                <?php if($customer): ?>
                <p><strong>Customer:</strong> <?php echo htmlspecialchars($customer['customer_name']); ?> (<?php echo $customer['customer_code']; ?>)</p>
                <?php endif; ?>
                <p>Generated: <?php echo date('d/m/Y h:i:s A'); ?></p>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4 no-print">
                <h2><i class="fas fa-users"></i> Customer Ledger</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Ledger
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">Back</a>
                </div>
            </div>
            
            <div class="card mb-4 no-print">
                <div class="card-header bg-primary text-white"><h5>Select Customer</h5></div>
                <div class="card-body">
                    <form method="GET" class="row">
                        <div class="col-md-5">
                            <select name="customer_id" class="form-control" required>
                                <option value="">-- Select Customer --</option>
                                <?php foreach($customers as $c): 
                                    $net = $c['current_balance'] - $c['advance_balance'];
                                    $status = $net > 0 ? 'Due' : ($net < 0 ? 'Advance' : 'Settled');
                                    $status_color = $net > 0 ? 'text-danger' : ($net < 0 ? 'text-warning' : 'text-success');
                                ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $customer_id == $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo $c['customer_name']; ?> (<?php echo $c['customer_code']; ?>) - 
                                        <span class="<?php echo $status_color; ?>">
                                            <?php echo $status; ?>: <?php echo $currency; ?> <?php echo number_format(abs($net), 2); ?>
                                        </span>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>">
                        </div>
                        <div class="col-md-2">
                            <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">View Ledger</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php if($customer): 
                $net_balance = $customer['current_balance'] - $customer['advance_balance'];
                $is_due = $net_balance > 0;
                $is_advance = $net_balance < 0;
                $is_zero = $net_balance == 0;
                $display_balance = abs($net_balance);
                $balance_label = $is_due ? 'Due' : ($is_advance ? 'Advance' : 'Settled');
                $balance_class = $is_due ? 'danger' : ($is_advance ? 'warning' : 'success');
            ?>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-white bg-info">
                            <div class="card-body">
                                <h6>Customer Name</h6>
                                <h4><?php echo htmlspecialchars($customer['customer_name']); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-secondary">
                            <div class="card-body">
                                <h6>Customer Code</h6>
                                <h4><?php echo $customer['customer_code']; ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-primary">
                            <div class="card-body">
                                <h6>Credit Limit</h6>
                                <h4><?php echo $currency; ?> <?php echo number_format($customer['credit_limit'], 2); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-<?php echo $balance_class; ?>">
                            <div class="card-body">
                                <h6>Net Balance</h6>
                                <h4>
                                    <?php if($is_zero): ?>
                                        <?php echo $currency; ?> 0.00 (Settled)
                                    <?php else: ?>
                                        <?php echo $currency; ?> <?php echo number_format($display_balance, 2); ?> 
                                        (<?php echo $balance_label; ?>)
                                    <?php endif; ?>
                                </h4>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <strong>Phone:</strong> <?php echo $customer['phone'] ?: 'N/A'; ?><br>
                                <strong>Email:</strong> <?php echo $customer['email'] ?: 'N/A'; ?><br>
                                <strong>Address:</strong> <?php echo $customer['address'] ?: 'N/A'; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <strong>Opening Balance (as on <?php echo date('d M Y', strtotime($from_date)); ?>):</strong><br>
                                <span class="h5 <?php echo $opening_balance > 0 ? 'text-danger' : ($opening_balance < 0 ? 'text-warning' : 'text-success'); ?>">
                                    <?php echo $currency; ?> <?php echo number_format(abs($opening_balance), 2); ?> 
                                    <?php echo $opening_balance > 0 ? '(Due)' : ($opening_balance < 0 ? '(Advance)' : ''); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5>Ledger Statement (<?php echo date('d M Y', strtotime($from_date)); ?> to <?php echo date('d M Y', strtotime($to_date)); ?>)</h5>
                        <small class="d-block text-light no-print">Click on any transaction to view details</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="ledgerTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th style="width:10%;">Date</th>
                                        <th style="width:12%;">Reference</th>
                                        <th style="width:33%;">Particulars</th>
                                        <th class="text-end" style="width:12%;">Debit (<?php echo $currency; ?>)</th>
                                        <th class="text-end" style="width:12%;">Credit (<?php echo $currency; ?>)</th>
                                        <th class="text-end" style="width:13%;">Balance (<?php echo $currency; ?>)</th>
                                        <th class="text-center no-print" style="width:8%;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="table-secondary fw-bold">
                                        <td colspan="3"><strong>Opening Balance</strong></td>
                                        <td class="text-end">
                                            <?php if($opening_balance > 0): ?>
                                                <strong><?php echo number_format($opening_balance, 2); ?></strong>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if($opening_balance < 0): ?>
                                                <strong><?php echo number_format(abs($opening_balance), 2); ?></strong>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <strong class="<?php echo $opening_balance > 0 ? 'text-danger' : ($opening_balance < 0 ? 'text-warning' : 'text-success'); ?>">
                                                <?php echo number_format(abs($opening_balance), 2); ?> 
                                                <?php echo $opening_balance > 0 ? 'Due' : ($opening_balance < 0 ? 'Advance' : ''); ?>
                                            </strong>
                                        </td>
                                        <td class="no-print"></td>
                                    </tr>
                                    
                                    <?php if(empty($transactions)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">
                                                <i class="fas fa-info-circle"></i> No transactions found for this period
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($transactions as $t): 
                                            $bal = $t['running_balance'];
                                            $bal_class = $bal > 0 ? 'text-danger' : ($bal < 0 ? 'text-warning' : 'text-success');
                                            
                                            $debit_amount = isset($t['debit']) ? floatval($t['debit']) : 0;
                                            $credit_amount = isset($t['credit']) ? floatval($t['credit']) : 0;
                                            
                                            // For credit sales, ensure debit is set from amount if not already
                                            if(($t['trans_type'] == 'credit_sale' || $t['trans_type'] == 'cash_sale') && $debit_amount == 0 && isset($t['amount']) && $t['amount'] > 0) {
                                                $debit_amount = floatval($t['amount']);
                                            }
                                            
                                            // For advance used, ensure credit is set from amount if not already
                                            if($t['trans_type'] == 'advance_used' && $credit_amount == 0 && isset($t['amount']) && $t['amount'] > 0) {
                                                $credit_amount = floatval($t['amount']);
                                            }
                                            
                                            $type_label = ''; 
                                            $type_badge = ''; 
                                            $type_icon = '';
                                            
                                            switch($t['trans_type']) {
                                                case 'cash_sale': 
                                                    $type_label = 'Cash Sale'; 
                                                    $type_badge = 'badge-cash-sale'; 
                                                    $type_icon = 'fa-money-bill-wave';
                                                    break;
                                                case 'credit_sale': 
                                                    $type_label = 'Credit Sale'; 
                                                    $type_badge = 'badge-credit-sale'; 
                                                    $type_icon = 'fa-shopping-cart';
                                                    break;
                                                case 'payment': 
                                                    $type_label = 'Payment Received'; 
                                                    $type_badge = 'badge-payment'; 
                                                    $type_icon = 'fa-hand-holding-usd';
                                                    break;
                                                case 'voucher_payment': 
                                                    $type_label = 'Voucher Payment'; 
                                                    $type_badge = 'badge-voucher-payment'; 
                                                    $type_icon = 'fa-file-invoice';
                                                    break;
                                                case 'advance_received': 
                                                    $type_label = 'Advance Received'; 
                                                    $type_badge = 'badge-advance-received'; 
                                                    $type_icon = 'fa-hand-holding-heart';
                                                    break;
                                                case 'advance_used': 
                                                    $type_label = 'Advance Used'; 
                                                    $type_badge = 'badge-advance-used'; 
                                                    $type_icon = 'fa-check-circle';
                                                    break;
                                                default: 
                                                    $type_label = ucfirst(str_replace('_', ' ', $t['trans_type'])); 
                                                    $type_badge = 'badge-secondary'; 
                                                    $type_icon = 'fa-file';
                                            }
                                        ?>
                                        <tr class="clickable-row" onclick="viewTransaction('<?php echo $t['trans_type']; ?>', '<?php echo $t['ref_no']; ?>', '<?php echo $t['source_table'] ?? ''; ?>', '<?php echo $t['source_id'] ?? 0; ?>')" title="Click to view details">
                                            <td><?php echo date('d-m-Y', strtotime($t['trans_date'])); ?></td>
                                            <td><strong><?php echo $t['ref_no']; ?></strong></td>
                                            <td>
                                                <span class="badge <?php echo $type_badge; ?> no-print">
                                                    <i class="fas <?php echo $type_icon; ?>"></i> <?php echo $type_label; ?>
                                                </span>
                                                <span class="print-only" style="display:none;"><?php echo $type_label; ?></span>
                                                <br>
                                                <small class="transaction-details">
                                                    <?php echo $t['description'] ?? ''; ?>
                                                </small>
                                                <?php if($t['trans_type'] == 'credit_sale' && isset($t['due_date']) && $t['due_date']): ?>
                                                    <br><small class="text-muted">Due: <?php echo date('d-m-Y', strtotime($t['due_date'])); ?></small>
                                                <?php endif; ?>
                                                <?php if($t['trans_type'] == 'credit_sale' && isset($t['advance_adjusted']) && $t['advance_adjusted'] > 0): ?>
                                                    <br><small class="text-success">Advance Used: <?php echo $currency; ?> <?php echo number_format($t['advance_adjusted'], 2); ?></small>
                                                <?php endif; ?>
                                                <?php if($t['trans_type'] == 'payment' || $t['trans_type'] == 'voucher_payment' || $t['trans_type'] == 'advance_received'): ?>
                                                    <br><small class="text-muted">Method: <?php echo ucfirst($t['payment_method'] ?? 'N/A'); ?></small>
                                                <?php endif; ?>
                                                <?php if(!empty($t['notes'])): ?>
                                                    <br><small class="text-muted">Notes: <?php echo htmlspecialchars(substr($t['notes'], 0, 50)); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end <?php echo $debit_amount > 0 ? 'debit-amount' : ''; ?>">
                                                <?php echo $debit_amount > 0 ? number_format($debit_amount, 2) : '-'; ?>
                                            </td>
                                            <td class="text-end <?php echo $credit_amount > 0 ? 'credit-amount' : ''; ?>">
                                                <?php echo $credit_amount > 0 ? number_format($credit_amount, 2) : '-'; ?>
                                            </td>
                                            <td class="text-end fw-bold <?php echo $bal_class; ?>">
                                                <?php echo number_format(abs($bal), 2); ?> 
                                                <?php echo $bal > 0 ? 'Due' : ($bal < 0 ? 'Advance' : ''); ?>
                                            </td>
                                            <td class="text-center no-print">
                                                <button onclick="viewTransaction('<?php echo $t['trans_type']; ?>', '<?php echo $t['ref_no']; ?>', '<?php echo $t['source_table'] ?? ''; ?>', '<?php echo $t['source_id'] ?? 0; ?>')" class="btn btn-sm btn-info view-btn" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    
                                    <tr class="table-info fw-bold">
                                        <td colspan="3"><strong>Closing Balance</strong></td>
                                        <td class="text-end"><strong><?php echo number_format($total_debit, 2); ?></strong></td>
                                        <td class="text-end"><strong><?php echo number_format($total_credit, 2); ?></strong></td>
                                        <td class="text-end">
                                            <strong class="<?php echo $net_balance > 0 ? 'text-danger' : ($net_balance < 0 ? 'text-warning' : 'text-success'); ?>">
                                                <?php echo number_format($display_balance, 2); ?> 
                                                <?php echo $balance_label; ?>
                                            </strong>
                                        </td>
                                        <td class="no-print"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-4 no-print">
                    <div class="col-md-3">
                        <div class="card text-white bg-danger">
                            <div class="card-body">
                                <h6>Total Debit</h6>
                                <h3><?php echo $currency; ?> <?php echo number_format($total_debit, 2); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <h6>Total Credit</h6>
                                <h3><?php echo $currency; ?> <?php echo number_format($total_credit, 2); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-warning">
                            <div class="card-body">
                                <h6>Net Movement</h6>
                                <h3><?php echo $currency; ?> <?php echo number_format(abs($total_debit - $total_credit), 2); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-info">
                            <div class="card-body">
                                <h6>Total Transactions</h6>
                                <h3><?php echo count($transactions); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php elseif($customer_id): ?>
                <div class="alert alert-danger">Customer not found!</div>
            <?php else: ?>
                <div class="alert alert-info text-center py-5 no-print">
                    <i class="fas fa-info-circle fa-2x d-block mb-3"></i>
                    <h5>Please select a customer to view ledger</h5>
                </div>
            <?php endif; ?>
            
            <div class="footer-note no-print" style="margin-top:15px; padding-top:10px; border-top:1px solid #ddd; text-align:center; color:#6c757d; font-size:12px;">
                <i class="fas fa-info-circle"></i>
                <strong>Report Summary:</strong> 
                <?php if($customer): ?>
                    <?php echo htmlspecialchars($customer['customer_name']); ?> | 
                    Balance: <?php echo $currency; ?> <?php echo number_format($display_balance, 2); ?> (<?php echo $balance_label; ?>) |
                    Transactions: <?php echo count($transactions); ?>
                <?php else: ?>
                    No customer selected
                <?php endif; ?>
                <br><small>Click <strong>Print Ledger</strong> for plain paper print.</small>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="transactionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5><i class="fas fa-file-invoice"></i> Transaction Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="transactionDetails">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading transaction details...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#ledgerTable').DataTable({ 
                order: [[0, 'asc']], 
                pageLength: 25, 
                language: { 
                    search: "Search:", 
                    lengthMenu: "Show _MENU_ entries", 
                    info: "Showing _START_ to _END_ of _TOTAL_ entries" 
                },
                columnDefs: [
                    { orderable: false, targets: [6] }
                ]
            });
        });
        
        function viewTransaction(type, ref_no, source_table, source_id) {
            $('#transactionModal').modal('show');
            $('#transactionDetails').html(`
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading transaction details...</p>
                </div>
            `);
            
            if(source_table && source_id > 0) {
                var viewUrl = '';
                switch(source_table) {
                    case 'sales':
                        viewUrl = 'view_sale.php?id=' + source_id;
                        break;
                    case 'credit_sales':
                        viewUrl = 'view_credit_sale.php?id=' + source_id;
                        break;
                    case 'credit_payments':
                        viewUrl = 'view_payment.php?id=' + source_id;
                        break;
                    case 'vouchers':
                        viewUrl = 'view_voucher.php?id=' + source_id;
                        break;
                    case 'advance_payments_customer':
                        viewUrl = 'view_advance.php?id=' + source_id;
                        break;
                    case 'gas_sales':
                        viewUrl = 'view_gas_sale.php?id=' + source_id;
                        break;
                    case 'item_sales':
                        viewUrl = 'view_item_sale.php?id=' + source_id;
                        break;
                    case 'item_credit_sales':
                        viewUrl = 'view_item_credit_sale.php?id=' + source_id;
                        break;
                    case 'item_credit_payments':
                        viewUrl = 'view_item_payment.php?id=' + source_id;
                        break;
                    default:
                        viewUrl = 'get_transaction_details.php?type=' + type + '&ref_no=' + ref_no;
                }
                
                $.ajax({
                    url: viewUrl,
                    method: 'GET',
                    success: function(response) {
                        if(response.trim().startsWith('<!DOCTYPE') || response.trim().startsWith('<html')) {
                            var temp = document.createElement('div');
                            temp.innerHTML = response;
                            var bodyContent = temp.querySelector('.container-fluid, .main-content, .invoice-container');
                            if(bodyContent) {
                                $('#transactionDetails').html(bodyContent.innerHTML);
                            } else {
                                $('#transactionDetails').html(response);
                            }
                        } else {
                            $('#transactionDetails').html(response);
                        }
                    },
                    error: function() {
                        $('#transactionDetails').html(`
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Transaction Details</strong><br>
                                Reference: ${ref_no}<br>
                                Type: ${type}<br>
                                Source: ${source_table || 'N/A'}<br>
                                ID: ${source_id || 'N/A'}
                            </div>
                        `);
                    }
                });
            } else {
                $.ajax({
                    url: 'get_transaction_details.php',
                    method: 'GET',
                    data: { type: type, ref_no: ref_no },
                    success: function(response) {
                        $('#transactionDetails').html(response);
                    },
                    error: function() {
                        $('#transactionDetails').html(`
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Transaction Details</strong><br>
                                Reference: ${ref_no}<br>
                                Type: ${type}
                            </div>
                        `);
                    }
                });
            }
        }
    </script>
</body>
</html>
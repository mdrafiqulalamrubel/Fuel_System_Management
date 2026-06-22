<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Get active shift
$stmt = $pdo->query("
    SELECT sc.*, sh.shift_name 
    FROM shift_closing sc 
    JOIN shifts sh ON sc.shift_id = sh.id 
    WHERE sc.status = 'open' 
    ORDER BY sc.id DESC 
    LIMIT 1
");
$active_shift = $stmt->fetch();

if(!$active_shift) {
    $error = "⚠️ No active shift! Please <a href='shift_closing.php' class='alert-link'>start a shift</a> first.";
}

// Auto select shift based on current time
function getCurrentShift($pdo) {
    $currentHour = date('H');
    $currentMinute = date('i');
    $currentTimeValue = $currentHour * 60 + $currentMinute;
    
    $stmt = $pdo->prepare("SELECT * FROM shift_schedule WHERE is_active = 1");
    $stmt->execute();
    $shifts = $stmt->fetchAll();
    
    foreach($shifts as $shift) {
        $startParts = explode(':', $shift['start_time']);
        $endParts = explode(':', $shift['end_time']);
        
        $startMinutes = intval($startParts[0]) * 60 + intval($startParts[1]);
        $endMinutes = intval($endParts[0]) * 60 + intval($endParts[1]);
        
        if($endMinutes < $startMinutes) {
            if($currentTimeValue >= $startMinutes || $currentTimeValue <= $endMinutes) {
                return $shift['id'];
            }
        } else {
            if($currentTimeValue >= $startMinutes && $currentTimeValue <= $endMinutes) {
                return $shift['id'];
            }
        }
    }
    return null;
}

// Get all nozzles for LIQUID fuels only (exclude CNG)
$nozzles = $pdo->query("
    SELECT 
        n.*, 
        t.id as tank_id,
        t.tank_name,
        t.product_id, 
        t.current_stock_liters as tank_stock,
        t.capacity_liters as tank_capacity,
        p.product_name, 
        p.unit_price,
        n.unit_type,
        n.meter_type
    FROM nozzles n 
    JOIN tanks t ON n.tank_id = t.id 
    JOIN fuel_products p ON t.product_id = p.id 
    WHERE n.is_active = 1 AND t.is_active = 1
    AND p.product_name NOT IN ('CNG', 'Natural Gas')
    ORDER BY t.tank_name, n.nozzle_name
")->fetchAll();

// Group nozzles by tank for display
$tanks_with_nozzles = [];
foreach($nozzles as $nozzle) {
    $tank_id = $nozzle['tank_id'];
    if(!isset($tanks_with_nozzles[$tank_id])) {
        $tanks_with_nozzles[$tank_id] = [
            'tank_name' => $nozzle['tank_name'],
            'tank_stock' => $nozzle['tank_stock'],
            'tank_capacity' => $nozzle['tank_capacity'],
            'product_name' => $nozzle['product_name'],
            'unit_price' => $nozzle['unit_price'],
            'unit_type' => $nozzle['unit_type'] ?? 'liters',
            'nozzles' => []
        ];
    }
    $tanks_with_nozzles[$tank_id]['nozzles'][] = $nozzle;
}

// Get products with total stock (exclude CNG)
$products = $pdo->query("
    SELECT p.*, COALESCE(SUM(t.current_stock_liters), 0) as total_stock
    FROM fuel_products p 
    LEFT JOIN tanks t ON p.id = t.product_id
    WHERE p.is_active = 1
    AND p.product_name NOT IN ('CNG', 'Natural Gas')
    GROUP BY p.id
")->fetchAll();

// Get shifts
$shifts = $pdo->query("SELECT * FROM shift_schedule WHERE is_active = 1 ORDER BY start_time")->fetchAll();
$auto_shift_id = $active_shift ? $active_shift['shift_id'] : getCurrentShift($pdo);

// Define default unit type for display
$default_unit_type = 'liters';

// Process sale
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['make_sale'])) {
    $invoice_no = 'INV-' . date('YmdHis');
    $shift_id = $active_shift['id'] ?? 0;
    $nozzle_id = $_POST['nozzle_id'];
    $product_id = $_POST['product_id'];
    $tank_id = $_POST['tank_id'];
    $unit_type = $_POST['unit_type'] ?? 'liters';
    $unit_price = floatval($_POST['unit_price']);
    $sale_type = $_POST['sale_type'];
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $card_number = isset($_POST['card_number']) ? trim($_POST['card_number']) : '';
    $card_holder_name = isset($_POST['card_holder_name']) ? trim($_POST['card_holder_name']) : '';
    $transaction_id = isset($_POST['transaction_id']) ? trim($_POST['transaction_id']) : '';
    $customer_name = isset($_POST['customer_name']) ? $_POST['customer_name'] : '';
    $customer_phone = isset($_POST['customer_phone']) ? $_POST['customer_phone'] : '';
    $vehicle_number = isset($_POST['vehicle_number']) ? trim($_POST['vehicle_number']) : '';
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
    $input_type = $_POST['input_type'] ?? 'liters'; // 'liters' or 'amount'
    
    // Calculate quantity based on input type
    if($input_type == 'amount') {
        $amount_input = floatval($_POST['amount_input'] ?? 0);
        $quantity = $amount_input / $unit_price;
        $total_amount = $amount_input;
    } else {
        $quantity = floatval($_POST['quantity']);
        $total_amount = $quantity * $unit_price;
    }

    $subtotal = $total_amount;
    $vat_amount = 0;
    $tax_amount = 0;
    $received = isset($_POST['received']) ? floatval($_POST['received']) : 0;
    $change = $received - $total_amount;

    // For credit sales, payment method is 'credit'
    if($sale_type == 'credit') {
        $payment_method = 'credit';
    }

    // =============================================
    // CHECK FOR CUSTOMER ADVANCE BALANCE
    // =============================================

    $advance_used = 0;
    $advance_payment_id = null;

    if($sale_type == 'credit' && !empty($customer_name)) {
        // First get or create customer ID if not set
        if(empty($customer_id)) {
            if(!empty($customer_phone)) {
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ?");
                $stmt->execute([$customer_phone]);
                $existing = $stmt->fetch();
                if($existing) {
                    $customer_id = $existing['id'];
                }
            }
            if(empty($customer_id) && !empty($customer_name)) {
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE customer_name = ?");
                $stmt->execute([$customer_name]);
                $existing = $stmt->fetch();
                if($existing) {
                    $customer_id = $existing['id'];
                }
            }
        }
        
        if($customer_id) {
            $stmt = $pdo->prepare("SELECT advance_balance FROM customers WHERE id = ?");
            $stmt->execute([$customer_id]);
            $advance_balance = $stmt->fetch()['advance_balance'] ?? 0;
            
            if($advance_balance > 0) {
                $advance_used = min($advance_balance, $total_amount);
                
                if($advance_used > 0) {
                    $stmt = $pdo->prepare("UPDATE customers SET advance_balance = advance_balance - ? WHERE id = ?");
                    $stmt->execute([$advance_used, $customer_id]);
                    
                    $stmt = $pdo->prepare("SELECT id FROM advance_payments_customer WHERE customer_id = ? AND balance_amount > 0 ORDER BY advance_date LIMIT 1");
                    $stmt->execute([$customer_id]);
                    $advance_record = $stmt->fetch();
                    if($advance_record) {
                        $advance_payment_id = $advance_record['id'];
                        $stmt = $pdo->prepare("UPDATE advance_payments_customer SET used_amount = used_amount + ?, balance_amount = balance_amount - ? WHERE id = ?");
                        $stmt->execute([$advance_used, $advance_used, $advance_payment_id]);
                        
                        $stmt = $pdo->prepare("SELECT balance_amount FROM advance_payments_customer WHERE id = ?");
                        $stmt->execute([$advance_payment_id]);
                        $new_balance = $stmt->fetch()['balance_amount'];
                        if($new_balance <= 0) {
                            $stmt = $pdo->prepare("UPDATE advance_payments_customer SET status = 'fully_used' WHERE id = ?");
                            $stmt->execute([$advance_payment_id]);
                        }
                    }
                    
                    $total_amount = $total_amount - $advance_used;
                    $subtotal = $total_amount;
                    $received = isset($_POST['received']) ? floatval($_POST['received']) : $total_amount;
                    $change = $received - $total_amount;
                }
            }
        }
    }

    // For LPG, handle kg conversion if needed
    if($unit_type == 'kilograms') {
        $stock_reduction = $quantity * 1.5;
    } else {
        $stock_reduction = $quantity;
    }
    
    try {
        if(!$active_shift) {
            throw new Exception("No active shift! Please start a shift first.");
        }
        
        if($quantity <= 0) {
            throw new Exception("Invalid quantity! Please check your input.");
        }
        
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT current_stock_liters FROM tanks WHERE id = ?");
        $stmt->execute([$tank_id]);
        $current_stock = $stmt->fetch()['current_stock_liters'] ?? 0;
        
        if($current_stock < $stock_reduction) {
            throw new Exception("Insufficient stock! Available: " . number_format($current_stock, 2) . " Liters");
        }
        
        // Insert sale with payment method fields
        $stmt = $pdo->prepare("
        INSERT INTO sales (
            invoice_no, 
            shift_id, 
            nozzle_id, 
            operator_id,
            customer_name, 
            customer_phone, 
            sale_type,
            payment_method, 
            card_number, 
            card_holder_name, 
            transaction_id,
            product_id, 
            quantity_liters, 
            unit_price,
            subtotal, 
            vat_amount, 
            tax_amount,
            total_amount, 
            received_amount, 
            change_amount,
            advance_used, 
            advance_payment_id,
            vehicle_number, 
            remarks
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?,
            ?, ?
        )
    ");

    $stmt->execute([
        $invoice_no, 
        $shift_id, 
        $nozzle_id, 
        $user['id'],
        $customer_name, 
        $customer_phone, 
        $sale_type,
        $payment_method, 
        $card_number, 
        $card_holder_name, 
        $transaction_id,
        $product_id, 
        $quantity, 
        $unit_price,
        $subtotal, 
        $vat_amount, 
        $tax_amount,
        $total_amount, 
        $received, 
        $change,
        $advance_used, 
        $advance_payment_id,
        $vehicle_number, 
        $remarks
    ]);
        
        $sale_id = $pdo->lastInsertId();
        
        // Update tank stock
        $stmt = $pdo->prepare("UPDATE tanks SET current_stock_liters = current_stock_liters - ? WHERE id = ?");
        $stmt->execute([$stock_reduction, $tank_id]);
        
        $stmt = $pdo->prepare("SELECT current_stock_liters FROM tanks WHERE id = ?");
        $stmt->execute([$tank_id]);
        $new_balance = $stmt->fetch()['current_stock_liters'];
        
        $stmt = $pdo->prepare("
            INSERT INTO stock_ledger (product_id, tank_id, transaction_type, reference_no, out_quantity, balance_quantity) 
            VALUES (?, ?, 'sale', ?, ?, ?)
        ");
        $stmt->execute([$product_id, $tank_id, $invoice_no, $stock_reduction, $new_balance]);
        
        // Accounting entries
        $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1300' LIMIT 1");
        $ar_account = $stmt->fetch();
        $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '4000' LIMIT 1");
        $sales_account = $stmt->fetch();
        $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1000' LIMIT 1");
        $cash_account = $stmt->fetch();
        
        $customer_id = null;
        
        // Handle Credit Sale
        if($sale_type == 'credit') {
            if(!empty($customer_phone)) {
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ?");
                $stmt->execute([$customer_phone]);
                $existing = $stmt->fetch();
                if($existing) {
                    $customer_id = $existing['id'];
                    $stmt = $pdo->prepare("UPDATE customers SET customer_name = ? WHERE id = ?");
                    $stmt->execute([$customer_name, $customer_id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO customers (customer_code, customer_name, phone, credit_limit) VALUES (?, ?, ?, ?)");
                    $customer_code = 'CUST-' . date('Ymd') . rand(100, 999);
                    $stmt->execute([$customer_code, $customer_name, $customer_phone, 50000]);
                    $customer_id = $pdo->lastInsertId();
                }
            } else {
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE customer_name = ?");
                $stmt->execute([$customer_name]);
                $existing = $stmt->fetch();
                if($existing) {
                    $customer_id = $existing['id'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO customers (customer_code, customer_name, credit_limit) VALUES (?, ?, ?)");
                    $customer_code = 'CUST-' . date('Ymd') . rand(100, 999);
                    $stmt->execute([$customer_code, $customer_name, 50000]);
                    $customer_id = $pdo->lastInsertId();
                }
            }
            
            $due_date = date('Y-m-d', strtotime('+30 days'));
            $stmt = $pdo->prepare("
                INSERT INTO credit_sales (sale_id, customer_id, invoice_no, sale_date, due_date, total_amount, paid_amount, balance_due, status) 
                VALUES (?, ?, ?, CURDATE(), ?, ?, 0, ?, 'pending')
            ");
            $stmt->execute([$sale_id, $customer_id, $invoice_no, $due_date, $total_amount, $total_amount]);
            
            $stmt = $pdo->prepare("UPDATE customers SET current_balance = current_balance + ? WHERE id = ?");
            $stmt->execute([$total_amount, $customer_id]);
            
            if($ar_account && $sales_account) {
                $voucher_no = 'CREDIT-' . date('YmdHis') . rand(100, 999);
                $narration = "Credit sale to $customer_name - Invoice: $invoice_no - Amount: $total_amount";
                
                $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'journal', CURDATE(), ?, ?, 'approved')");
                $stmt->execute([$voucher_no, $narration, $user['id']]);
                $voucher_id = $pdo->lastInsertId();
                
                $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                    (?, ?, ?, ?, ?),
                    (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $voucher_id, $ar_account['id'], $total_amount, 0, "Credit sale to $customer_name - Invoice: $invoice_no",
                    $voucher_id, $sales_account['id'], 0, $total_amount, "Fuel sale revenue - Invoice: $invoice_no"
                ]);
            }
        } 
        // Handle Cash Sale
        else if($sale_type == 'cash') {
            if($cash_account && $sales_account) {
                $voucher_no = 'CASH-' . date('YmdHis') . rand(100, 999);
                $narration = "Cash sale - Invoice: $invoice_no - Amount: $total_amount - Payment: $payment_method";
                
                $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'receipt', CURDATE(), ?, ?, 'approved')");
                $stmt->execute([$voucher_no, $narration, $user['id']]);
                $voucher_id = $pdo->lastInsertId();
                
                $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                    (?, ?, ?, ?, ?),
                    (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $voucher_id, $cash_account['id'], $total_amount, 0, "Cash sale - Invoice: $invoice_no",
                    $voucher_id, $sales_account['id'], 0, $total_amount, "Fuel sale revenue - Invoice: $invoice_no"
                ]);
            }
        }
        
        $pdo->commit();
        
        $stmt = $pdo->prepare("SELECT product_name FROM fuel_products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product_data = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT tank_name FROM tanks WHERE id = ?");
        $stmt->execute([$tank_id]);
        $tank_data = $stmt->fetch();
        
        $_SESSION['last_invoice'] = [
            'invoice_no' => $invoice_no,
            'customer_name' => $customer_name,
            'customer_phone' => $customer_phone,
            'vehicle_number' => $vehicle_number,
            'remarks' => $remarks,
            'date' => date('Y-m-d H:i:s'),
            'product_id' => $product_id,
            'product' => $product_data['product_name'],
            'tank_name' => $tank_data['tank_name'],
            'quantity' => $quantity,
            'unit_type' => $unit_type,
            'unit_price' => $unit_price,
            'subtotal' => $subtotal,
            'vat' => 0,
            'tax' => 0,
            'total' => $total_amount,
            'received' => $received,
            'change' => $change,
            'sale_type' => $sale_type,
            'payment_method' => $payment_method,
            'card_number' => $card_number,
            'card_holder_name' => $card_holder_name,
            'transaction_id' => $transaction_id,
            'customer_id' => $customer_id,
            'input_type' => $input_type,
            'amount_input' => ($input_type == 'amount') ? $total_amount : 0
        ];
        
        $success = "Sale completed! Invoice: $invoice_no";
        header("Location: print_invoice.php");
        exit();
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS - Fuel Sales</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .pos-card { background: white; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; overflow: hidden; }
        .pos-card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 20px; font-weight: 600; }
        .pos-card-body { padding: 20px; }
        .tank-card { background: white; border-radius: 15px; margin-bottom: 15px; border: 2px solid #e0e0e0; overflow: hidden; }
        .tank-header { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white; padding: 12px 15px; font-weight: bold; }
        .tank-header i { margin-right: 10px; }
        .tank-stock { font-size: 14px; background: rgba(255,255,255,0.2); padding: 3px 10px; border-radius: 20px; }
        .nozzle-btn { 
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); 
            color: white; border: none; border-radius: 10px; padding: 12px; margin: 5px; width: 100%;
            transition: all 0.2s;
        }
        .nozzle-btn:hover, .nozzle-btn.active { transform: scale(1.02); }
        .nozzle-btn.disabled { opacity: 0.5; pointer-events: none; }
        .amount-display { font-size: 28px; font-weight: bold; text-align: right; background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 10px; }
        .low-stock { background: #dc3545 !important; }
        .total-amount { font-size: 32px; color: #28a745; }
        .shift-note { font-size: 11px; color: #28a745; margin-top: 3px; display: block; }
        .current-time-badge {
            background: rgba(0,0,0,0.25);
            backdrop-filter: blur(5px);
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: white;
            font-family: 'Courier New', monospace;
        }
        .tank-info { background: #f8f9fa; padding: 10px; border-radius: 8px; margin-top: 10px; font-size: 13px; }
        .nozzles-container { padding: 15px; background: #fafafa; }
        .badge-liquid { background: #28a745; color: white; }
        .badge-lpg { background: #ffc107; color: #856404; }
        .shift-warning { background: #fff3cd; border: 1px solid #ffc107; border-radius: 10px; padding: 10px 15px; margin-bottom: 15px; }
        .input-toggle {
            display: flex;
            gap: 10px;
            margin: 10px 0;
        }
        .input-btn {
            flex: 1;
            padding: 10px;
            text-align: center;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.3s;
            border: 2px solid #dee2e6;
        }
        .input-btn.active {
            background: #28a745;
            color: white;
            border-color: #28a745;
        }
        .input-btn.inactive {
            background: #e9ecef;
            color: #6c757d;
            border-color: #dee2e6;
        }
        .input-btn.inactive:hover {
            background: #d4edda;
            border-color: #28a745;
        }
        .input-section {
            display: none;
        }
        .input-section.active {
            display: block;
        }
        .unit-label { font-weight: bold; color: #17a2b8; }
        .amount-input-group { background: #e8f4f8; padding: 10px; border-radius: 8px; margin: 10px 0; }
        .calculated-info { background: #d4edda; padding: 8px 15px; border-radius: 6px; margin-top: 5px; }
        .lpg-badge { background: #ffc107; color: #856404; padding: 2px 10px; border-radius: 15px; font-size: 12px; }
        
        /* Payment Method Styles */
        .payment-section {
            background: #e8f4f8;
            padding: 12px;
            border-radius: 8px;
            margin: 10px 0;
            border-left: 4px solid #17a2b8;
        }
        .payment-section .payment-methods {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .payment-method-btn {
            padding: 6px 15px;
            border: 2px solid #dee2e6;
            border-radius: 20px;
            background: white;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.3s;
        }
        .payment-method-btn:hover {
            border-color: #667eea;
        }
        .payment-method-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .payment-method-btn i { margin-right: 5px; }
        .payment-details-fields {
            display: none;
            margin-top: 10px;
            padding: 10px;
            background: white;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        .payment-details-fields.show {
            display: block;
        }
        .payment-icon-cash { color: #28a745; }
        .payment-icon-card { color: #0d6efd; }
        .payment-icon-bkash { color: #e2136e; }
        .payment-icon-nagad { color: #ff6b00; }
        .payment-icon-credit { color: #ffc107; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if(!$active_shift): ?>
            <div class="shift-warning">
                <i class="fas fa-exclamation-triangle text-warning"></i>
                <strong>No Active Shift!</strong> Please <a href="shift_closing.php" class="alert-link">start a shift</a> before making sales.
            </div>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-7">
                    <div class="pos-card">
                        <div class="pos-card-header" style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <i class="fas fa-shopping-cart"></i> New Fuel Sale
                            </div>
                            <span class="current-time-badge" id="currentTimeDisplay">
                                <i class="fas fa-clock"></i> <span id="liveTime">--:--:--</span>
                            </span>
                        </div>
                        <div class="pos-card-body">
                            <form method="POST" id="saleForm">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-clock"></i> Shift</label>
                                            <?php if($active_shift): ?>
                                                <input type="text" class="form-control" value="<?php echo $active_shift['shift_name']; ?> (Started: <?php echo date('h:i A', strtotime($active_shift['opening_time'])); ?>)" readonly style="background:#e9ecef; font-weight:bold;">
                                                <input type="hidden" name="shift_id" value="<?php echo $active_shift['shift_id']; ?>">
                                                <small class="text-muted"><i class="fas fa-lock text-warning"></i> Shift locked - cannot be changed</small>
                                            <?php else: ?>
                                                <select name="shift_id" id="shift_id" class="form-control" required>
                                                    <option value="">Select Shift</option>
                                                    <?php foreach($shifts as $shift): ?>
                                                        <option value="<?php echo $shift['id']; ?>" <?php echo ($auto_shift_id == $shift['id']) ? 'selected' : ''; ?>>
                                                            <?php echo $shift['shift_name']; ?> (<?php echo date('h:i A', strtotime($shift['start_time'])); ?> - <?php echo date('h:i A', strtotime($shift['end_time'])); ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <span class="shift-note">
                                                    <i class="fas fa-info-circle"></i> Select shift to start
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-oil-can"></i> Select Nozzle</label>
                                            <select name="nozzle_id" id="nozzle_id" class="form-control" required>
                                                <option value="">-- Select Nozzle --</option>
                                                <?php foreach($nozzles as $nozzle): ?>
                                                    <option value="<?php echo $nozzle['id']; ?>" 
                                                            data-tank-id="<?php echo $nozzle['tank_id']; ?>"
                                                            data-tank-name="<?php echo $nozzle['tank_name']; ?>"
                                                            data-product="<?php echo $nozzle['product_id']; ?>"
                                                            data-product-name="<?php echo $nozzle['product_name']; ?>"
                                                            data-price="<?php echo $nozzle['unit_price']; ?>"
                                                            data-stock="<?php echo $nozzle['tank_stock']; ?>"
                                                            data-unit-type="<?php echo $nozzle['unit_type']; ?>">
                                                        <?php echo $nozzle['nozzle_name']; ?> - <?php echo $nozzle['product_name']; ?>
                                                        <?php if($nozzle['unit_type'] == 'kilograms'): ?>
                                                            <span class="badge lpg-badge">LPG (kg)</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-liquid">Liquid (L)</span>
                                                        <?php endif; ?>
                                                        (Stock: <?php echo number_format($nozzle['tank_stock'], 0); ?> <?php echo $nozzle['unit_type'] == 'kilograms' ? 'kg' : 'L'; ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <input type="hidden" name="tank_id" id="tank_id">
                                <input type="hidden" name="unit_type" id="unit_type" value="liters">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-tag"></i> Product</label>
                                            <input type="text" id="product_name" class="form-control" readonly>
                                            <input type="hidden" name="product_id" id="product_id">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-money-bill"></i> Unit Price (<?php echo $currency; ?>/<span id="unit_price_label">L</span>)</label>
                                            <input type="number" name="unit_price" id="unit_price" class="form-control" step="0.01" readonly required>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Tank Info -->
                                <div id="tank_info" class="tank-info" style="display:none;">
                                    <i class="fas fa-warehouse"></i> Tank: <span id="display_tank_name"></span> | 
                                    Available Stock: <span id="display_tank_stock"></span> <span id="display_stock_unit">L</span>
                                </div>
                                
                                <!-- Input Method Toggle -->
                                <div class="input-toggle">
                                    <div class="input-btn active" data-method="liters" onclick="toggleInputMethod('liters')">
                                        <i class="fas fa-tachometer-alt"></i> Liters
                                    </div>
                                    <div class="input-btn inactive" data-method="amount" onclick="toggleInputMethod('amount')">
                                        <i class="fas fa-money-bill"></i> Amount (TK)
                                    </div>
                                </div>
                                
                                <input type="hidden" name="input_type" id="input_type" value="liters">
                                
                                <!-- Liters Input Section -->
                                <div id="liters_section" class="input-section active">
                                    <div class="mb-3">
                                        <label><i class="fas fa-tachometer-alt"></i> Quantity (<span id="liters_unit_label">Liters</span>)</label>
                                        <input type="number" name="quantity" id="quantity" class="form-control" step="0.01" placeholder="Enter quantity in liters">
                                    </div>
                                </div>
                                
                                <!-- Amount Input Section -->
                                <div id="amount_section" class="input-section">
                                    <div class="amount-input-group">
                                        <div class="mb-3">
                                            <label><i class="fas fa-money-bill"></i> Amount (<?php echo $currency; ?>)</label>
                                            <input type="number" name="amount_input" id="amount_input" class="form-control" step="0.01" placeholder="Enter amount in Taka">
                                            <small class="text-muted">System will calculate liters based on unit price</small>
                                        </div>
                                        <div class="calculated-info">
                                            <strong>Calculated:</strong> <span id="calculated_liters">0.00</span> <span id="calc_unit_label">Liters</span> @ <?php echo $currency; ?> <span id="display_unit_price">0.00</span>/<span id="calc_price_unit">L</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- ============================================= -->
                                <!-- SALE TYPE & PAYMENT METHOD SECTION -->
                                <!-- ============================================= -->
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-credit-card"></i> Sale Type</label>
                                            <select name="sale_type" id="sale_type" class="form-control" required onchange="toggleSaleType(this)">
                                                <option value="cash">Cash Sale</option>
                                                <option value="credit">Credit Sale</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-calculator"></i> Total Amount (<?php echo $currency; ?>)</label>
                                            <input type="text" id="total_amount_display" class="form-control" readonly value="0.00">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Payment Method Section (Visible only for Cash Sales) -->
                                <div id="payment_section" class="payment-section">
                                    <label><i class="fas fa-wallet"></i> Payment Method</label>
                                    <div class="payment-methods">
                                        <button type="button" class="payment-method-btn active" data-method="cash" onclick="selectPaymentMethod('cash')">
                                            <i class="fas fa-money-bill-wave payment-icon-cash"></i> Cash
                                        </button>
                                        <button type="button" class="payment-method-btn" data-method="card" onclick="selectPaymentMethod('card')">
                                            <i class="fas fa-credit-card payment-icon-card"></i> Card
                                        </button>
                                        <button type="button" class="payment-method-btn" data-method="bkash" onclick="selectPaymentMethod('bkash')">
                                            <i class="fas fa-mobile-alt payment-icon-bkash"></i> Bkash
                                        </button>
                                        <button type="button" class="payment-method-btn" data-method="nagad" onclick="selectPaymentMethod('nagad')">
                                            <i class="fas fa-mobile-alt payment-icon-nagad"></i> Nagad
                                        </button>
                                    </div>
                                    <input type="hidden" name="payment_method" id="payment_method" value="cash">
                                    
                                    <!-- Card Details -->
                                    <div id="card_details" class="payment-details-fields">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-2">
                                                    <label><i class="fas fa-credit-card"></i> Card Number</label>
                                                    <input type="text" name="card_number" id="card_number" class="form-control form-control-sm" placeholder="XXXX-XXXX-XXXX-XXXX">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-2">
                                                    <label><i class="fas fa-user"></i> Card Holder Name</label>
                                                    <input type="text" name="card_holder_name" id="card_holder_name" class="form-control form-control-sm" placeholder="Name on card">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-12">
                                            <div class="mb-2">
                                                <label><i class="fas fa-hashtag"></i> Transaction ID</label>
                                                <input type="text" name="transaction_id" id="transaction_id" class="form-control form-control-sm" placeholder="Enter transaction ID">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Vehicle Number & Remarks -->
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-car"></i> Vehicle Number</label>
                                            <input type="text" name="vehicle_number" id="vehicle_number" class="form-control" placeholder="e.g., Dhaka-Metro-1234">
                                            <small class="text-muted">Enter vehicle registration number</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-comment"></i> Remarks</label>
                                            <input type="text" name="remarks" id="remarks" class="form-control" placeholder="Any additional notes">
                                            <small class="text-muted">Optional remarks or notes</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div id="customer_fields" style="display:none;">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label>Customer Name *</label>
                                                <input type="text" name="customer_name" id="customer_name" class="form-control">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label>Customer Phone</label>
                                                <input type="text" name="customer_phone" id="customer_phone" class="form-control">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="amount-display">
                                    <table width="100%">
                                        <tr>
                                            <td><strong>TOTAL AMOUNT:</strong></td>
                                            <td class="text-end total-amount"><?php echo $currency; ?> <span id="total_amount">0.00</span></td>
                                        </tr>
                                    </table>
                                </div>
                                
                                <div id="cash_fields">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label>Received Amount (<?php echo $currency; ?>)</label>
                                                <input type="number" name="received" id="received" class="form-control" step="0.01" value="0">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label>Change (<?php echo $currency; ?>)</label>
                                                <input type="text" id="change_amount" class="form-control" readonly>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="submit" name="make_sale" class="btn btn-success w-100" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); border: none; padding: 12px;" <?php echo !$active_shift ? 'disabled' : ''; ?>>
                                    <i class="fas fa-print"></i> Process Sale & Print Receipt
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-5">
                    <!-- Tanks with Nozzles -->
                    <div class="pos-card">
                        <div class="pos-card-header">
                            <i class="fas fa-warehouse"></i> Fuel Tanks & Nozzles
                            <small class="float-end">Diesel / Petrol / Octane / LPG</small>
                        </div>
                        <div class="pos-card-body">
                            <?php if(empty($tanks_with_nozzles)): ?>
                                <div class="alert alert-info text-center">
                                    <i class="fas fa-info-circle"></i> No liquid fuel tanks configured.<br>
                                    <small>CNG is handled in the CNG Sales module.</small>
                                </div>
                            <?php endif; ?>
                            
                            <?php foreach($tanks_with_nozzles as $tank_id => $tank): 
                                $stock = $tank['tank_stock'];
                                $capacity = $tank['tank_capacity'];
                                $fill_percent = $capacity > 0 ? ($stock / $capacity) * 100 : 0;
                                $stock_class = $stock < 500 ? 'low-stock' : '';
                                $stock_text_class = $stock < 500 ? 'text-danger' : ($stock < 1000 ? 'text-warning' : 'text-success');
                                $unit_display = ($tank['unit_type'] ?? 'liters') == 'kilograms' ? 'kg' : 'L';
                            ?>
                                <div class="tank-card">
                                    <div class="tank-header">
                                        <i class="fas fa-oil-can"></i> 
                                        <?php echo htmlspecialchars($tank['tank_name']); ?>
                                        <?php if($unit_display == 'kg'): ?>
                                            <span class="badge bg-warning ms-2">LPG</span>
                                        <?php endif; ?>
                                        <span class="float-end">
                                            <span class="tank-stock">
                                                <i class="fas fa-gas-pump"></i> 
                                                <?php echo $tank['product_name']; ?> | 
                                                Stock: <span class="<?php echo $stock_text_class; ?>"><?php echo number_format($stock, 0); ?></span> <?php echo $unit_display; ?>
                                                (<?php echo round($fill_percent, 1); ?>%)
                                            </span>
                                        </span>
                                    </div>
                                    <div class="nozzles-container">
                                        <div class="progress mb-2" style="height: 8px;">
                                            <div class="progress-bar <?php echo $stock < 500 ? 'bg-danger' : ($stock < 1000 ? 'bg-warning' : 'bg-success'); ?>" 
                                                 style="width: <?php echo min($fill_percent, 100); ?>%"></div>
                                        </div>
                                        
                                        <div class="row">
                                            <?php foreach($tank['nozzles'] as $nozzle): 
                                                $nozzle_unit = $nozzle['unit_type'] ?? 'liters';
                                                $unit_label = ($nozzle_unit == 'kilograms') ? 'kg' : 'L';
                                            ?>
                                                <div class="col-6 mb-2">
                                                    <button type="button" class="nozzle-btn quick-nozzle <?php echo $stock_class; ?>" 
                                                            data-id="<?php echo $nozzle['id']; ?>"
                                                            data-tank-id="<?php echo $tank_id; ?>"
                                                            data-tank-name="<?php echo $tank['tank_name']; ?>"
                                                            data-product="<?php echo $nozzle['product_id']; ?>"
                                                            data-product-name="<?php echo $tank['product_name']; ?>"
                                                            data-price="<?php echo $tank['unit_price']; ?>"
                                                            data-stock="<?php echo $stock; ?>"
                                                            data-unit-type="<?php echo $nozzle['unit_type']; ?>"
                                                            <?php echo $stock <= 0 ? 'disabled' : ''; ?>>
                                                        <i class="fas fa-oil-can"></i><br>
                                                        <strong><?php echo htmlspecialchars($nozzle['nozzle_name']); ?></strong><br>
                                                        <small><?php echo $tank['product_name']; ?> @ <?php echo $currency; ?> <?php echo number_format($tank['unit_price'], 2); ?>/<?php echo $unit_label; ?></small>
                                                        <span class="nozzle-stock d-block mt-1" style="font-size:10px; background:rgba(255,255,255,0.2); padding:2px 6px; border-radius:12px;">
                                                            Stock: <?php echo number_format($stock, 0); ?> <?php echo $unit_label; ?>
                                                        </span>
                                                    </button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if($stock <= 0): ?>
                                            <div class="alert alert-danger text-center mb-0 mt-2" style="padding: 5px; font-size: 12px;">
                                                <i class="fas fa-exclamation-triangle"></i> OUT OF STOCK
                                            </div>
                                        <?php elseif($stock < 500): ?>
                                            <div class="alert alert-warning text-center mb-0 mt-2" style="padding: 5px; font-size: 12px;">
                                                <i class="fas fa-exclamation-triangle"></i> LOW STOCK: <?php echo number_format($stock, 0); ?> <?php echo $unit_label; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateCurrentTime() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const timeDisplay = document.getElementById('currentTimeDisplay');
            if (timeDisplay) {
                timeDisplay.innerHTML = '<i class="fas fa-clock"></i> ' + hours + ':' + minutes + ':' + seconds;
            }
        }
        updateCurrentTime();
        setInterval(updateCurrentTime, 1000);

        // =============================================
        // PAYMENT METHOD FUNCTIONS
        // =============================================
        function selectPaymentMethod(method) {
            document.getElementById('payment_method').value = method;
            
            // Update button styles
            document.querySelectorAll('.payment-method-btn').forEach(btn => {
                btn.classList.remove('active');
                if(btn.dataset.method == method) {
                    btn.classList.add('active');
                }
            });
            
            // Show/hide card details
            if(method === 'card') {
                document.getElementById('card_details').classList.add('show');
            } else {
                document.getElementById('card_details').classList.remove('show');
            }
            
            // For Bkash and Nagad, show transaction ID field
            if(method === 'bkash' || method === 'nagad') {
                document.getElementById('transaction_id').placeholder = 'Enter ' + method.charAt(0).toUpperCase() + method.slice(1) + ' transaction ID';
            } else {
                document.getElementById('transaction_id').placeholder = 'Enter transaction ID';
            }
        }
        
        function toggleSaleType(select) {
            const paymentSection = document.getElementById('payment_section');
            const customerFields = document.getElementById('customer_fields');
            const cashFields = document.getElementById('cash_fields');
            
            if(select.value === 'credit') {
                paymentSection.style.display = 'none';
                customerFields.style.display = 'block';
                cashFields.style.display = 'none';
                document.getElementById('customer_name').setAttribute('required', 'required');
                document.getElementById('received').value = 0;
                // Set payment method to credit
                document.getElementById('payment_method').value = 'credit';
            } else {
                paymentSection.style.display = 'block';
                customerFields.style.display = 'none';
                cashFields.style.display = 'block';
                document.getElementById('customer_name').removeAttribute('required');
                document.getElementById('received').value = 0;
                // Reset payment method to cash
                document.getElementById('payment_method').value = 'cash';
                // Reset card details
                document.getElementById('card_details').classList.remove('show');
                document.querySelectorAll('.payment-method-btn').forEach(btn => {
                    btn.classList.remove('active');
                    if(btn.dataset.method === 'cash') {
                        btn.classList.add('active');
                    }
                });
            }
            calculateChange();
        }

        // =============================================
        // Toggle input method
        // =============================================
        function toggleInputMethod(method) {
            document.getElementById('input_type').value = method;
            
            document.querySelectorAll('.input-btn').forEach(btn => {
                btn.classList.remove('active');
                btn.classList.add('inactive');
            });
            document.querySelector(`.input-btn[data-method="${method}"]`).classList.add('active');
            document.querySelector(`.input-btn[data-method="${method}"]`).classList.remove('inactive');
            
            if(method === 'liters') {
                document.getElementById('liters_section').classList.add('active');
                document.getElementById('amount_section').classList.remove('active');
            } else {
                document.getElementById('liters_section').classList.remove('active');
                document.getElementById('amount_section').classList.add('active');
            }
            
            calculateTotal();
        }

        // =============================================
        // Nozzle selection
        // =============================================
        document.getElementById('nozzle_id').addEventListener('change', function() {
            let option = this.options[this.selectedIndex];
            if(!option.value) return;
            
            document.getElementById('product_name').value = option.getAttribute('data-product-name');
            document.getElementById('product_id').value = option.getAttribute('data-product');
            document.getElementById('unit_price').value = option.getAttribute('data-price');
            document.getElementById('tank_id').value = option.getAttribute('data-tank-id');
            
            let unitType = option.getAttribute('data-unit-type') || 'liters';
            document.getElementById('unit_type').value = unitType;
            
            let tankStock = option.getAttribute('data-stock');
            document.getElementById('display_tank_name').innerText = option.getAttribute('data-tank-name');
            document.getElementById('display_tank_stock').innerText = parseFloat(tankStock).toFixed(2);
            document.getElementById('tank_info').style.display = 'block';
            
            // Set unit display
            let unitDisplay = unitType == 'kilograms' ? 'kg' : 'L';
            let labelDisplay = unitType == 'kilograms' ? 'Kilograms' : 'Liters';
            document.getElementById('display_stock_unit').innerText = unitDisplay;
            document.getElementById('liters_unit_label').innerText = labelDisplay;
            document.getElementById('calc_unit_label').innerText = unitDisplay;
            document.getElementById('calc_price_unit').innerText = unitDisplay;
            document.getElementById('unit_price_label').innerText = unitDisplay;
            
            let price = parseFloat(option.getAttribute('data-price')) || 0;
            document.getElementById('display_unit_price').innerText = price.toFixed(2);
            
            calculateTotal();
        });

        // Quick nozzle selection
        document.querySelectorAll('.quick-nozzle').forEach(btn => {
            btn.addEventListener('click', function() {
                if(this.hasAttribute('disabled')) {
                    alert('This nozzle is out of stock!');
                    return;
                }
                
                document.getElementById('nozzle_id').value = this.getAttribute('data-id');
                document.getElementById('product_name').value = this.getAttribute('data-product-name');
                document.getElementById('product_id').value = this.getAttribute('data-product');
                document.getElementById('unit_price').value = this.getAttribute('data-price');
                document.getElementById('tank_id').value = this.getAttribute('data-tank-id');
                
                let unitType = this.getAttribute('data-unit-type') || 'liters';
                document.getElementById('unit_type').value = unitType;
                
                let tankStock = this.getAttribute('data-stock');
                document.getElementById('display_tank_name').innerText = this.getAttribute('data-tank-name');
                document.getElementById('display_tank_stock').innerText = parseFloat(tankStock).toFixed(2);
                document.getElementById('tank_info').style.display = 'block';
                
                let unitDisplay = unitType == 'kilograms' ? 'kg' : 'L';
                let labelDisplay = unitType == 'kilograms' ? 'Kilograms' : 'Liters';
                document.getElementById('display_stock_unit').innerText = unitDisplay;
                document.getElementById('liters_unit_label').innerText = labelDisplay;
                document.getElementById('calc_unit_label').innerText = unitDisplay;
                document.getElementById('calc_price_unit').innerText = unitDisplay;
                document.getElementById('unit_price_label').innerText = unitDisplay;
                
                let price = parseFloat(this.getAttribute('data-price')) || 0;
                document.getElementById('display_unit_price').innerText = price.toFixed(2);
                
                document.querySelectorAll('.quick-nozzle').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                calculateTotal();
                document.getElementById('quantity').focus();
            });
        });

        // Liters input
        document.getElementById('quantity').addEventListener('input', function() {
            if(document.getElementById('input_type').value === 'liters') {
                calculateTotal();
            }
        });

        // Amount input
        document.getElementById('amount_input').addEventListener('input', function() {
            if(document.getElementById('input_type').value === 'amount') {
                calculateTotal();
            }
        });

        // Received amount
        document.getElementById('received').addEventListener('input', calculateChange);

        // Sale type change
        document.getElementById('sale_type').addEventListener('change', function() {
            toggleSaleType(this);
        });

        function calculateTotal() {
            let price = parseFloat(document.getElementById('unit_price').value) || 0;
            let method = document.getElementById('input_type').value;
            let quantity = 0;
            let total = 0;
            
            if(method === 'liters') {
                quantity = parseFloat(document.getElementById('quantity').value) || 0;
                total = quantity * price;
                document.getElementById('calculated_liters').innerText = quantity.toFixed(2);
            } else {
                let amount = parseFloat(document.getElementById('amount_input').value) || 0;
                if(price > 0 && amount > 0) {
                    quantity = amount / price;
                    total = amount;
                }
                document.getElementById('calculated_liters').innerText = quantity.toFixed(2);
                document.getElementById('quantity').value = quantity.toFixed(2);
            }
            
            document.getElementById('total_amount_display').value = total.toFixed(2);
            document.getElementById('total_amount').innerText = total.toFixed(2);
            calculateChange();
            
            let stock = parseFloat(document.getElementById('display_tank_stock').innerText) || 0;
            if(quantity > stock && stock > 0) {
                document.getElementById('total_amount_display').style.color = 'red';
                document.getElementById('total_amount').style.color = 'red';
            } else {
                document.getElementById('total_amount_display').style.color = '';
                document.getElementById('total_amount').style.color = '';
            }
        }

        function calculateChange() {
            let total = parseFloat(document.getElementById('total_amount').innerText) || 0;
            let received = parseFloat(document.getElementById('received').value) || 0;
            let change = received - total;
            document.getElementById('change_amount').value = change.toFixed(2);
        }

        // Form validation
        document.getElementById('saleForm').addEventListener('submit', function(e) {
            let nozzle = document.getElementById('nozzle_id').value;
            let method = document.getElementById('input_type').value;
            let quantity = parseFloat(document.getElementById('quantity').value) || 0;
            let amount = parseFloat(document.getElementById('amount_input').value) || 0;
            let tankStock = parseFloat(document.getElementById('display_tank_stock').innerText) || 0;
            
            if(!nozzle) {
                e.preventDefault();
                alert('Please select a nozzle');
                return false;
            }
            
            if(method === 'liters' && quantity <= 0) {
                e.preventDefault();
                alert('Please enter valid quantity in liters');
                return false;
            }
            
            if(method === 'amount' && amount <= 0) {
                e.preventDefault();
                alert('Please enter the amount in Taka');
                return false;
            }
            
            if(quantity > tankStock) {
                e.preventDefault();
                alert('⚠️ Insufficient stock!\n\nAvailable: ' + tankStock.toFixed(2) + ' liters\nRequested: ' + quantity.toFixed(2) + ' liters\n\nPlease reduce quantity.');
                return false;
            }
            
            let saleType = document.getElementById('sale_type').value;
            if(saleType == 'credit') {
                let customerName = document.getElementById('customer_name').value.trim();
                if(!customerName) {
                    e.preventDefault();
                    alert('Please enter customer name for credit sale');
                    return false;
                }
            }
            
            // Validate payment method fields for card
            let paymentMethod = document.getElementById('payment_method').value;
            if(saleType == 'cash' && paymentMethod == 'card') {
                let cardNumber = document.getElementById('card_number').value.trim();
                let cardHolderName = document.getElementById('card_holder_name').value.trim();
                if(!cardNumber) {
                    e.preventDefault();
                    alert('Please enter card number');
                    return false;
                }
                if(!cardHolderName) {
                    e.preventDefault();
                    alert('Please enter card holder name');
                    return false;
                }
            }
            
            // Confirm the sale
            let total = parseFloat(document.getElementById('total_amount').innerText) || 0;
            let unitLabel = document.getElementById('liters_unit_label').innerText || 'L';
            let paymentLabel = document.querySelector('.payment-method-btn.active')?.innerText?.trim() || 'Cash';
            let confirmMsg = `⚠️ CONFIRM SALE ⚠️\n\n`;
            confirmMsg += `📊 Quantity: ${quantity.toFixed(2)} ${unitLabel}\n`;
            confirmMsg += `💰 Total: ${document.getElementById('total_amount_display').value} ${currency}\n`;
            confirmMsg += `📌 Type: ${document.querySelector('#sale_type option:checked').text}\n`;
            confirmMsg += `💳 Payment: ${paymentLabel}\n`;
            let vehicle = document.getElementById('vehicle_number').value.trim();
            if(vehicle) {
                confirmMsg += `🚗 Vehicle: ${vehicle}\n`;
            }
            confirmMsg += `\nAre you sure you want to proceed?`;
            
            if(!confirm(confirmMsg)) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });
        
        // Trigger initial calculation
        setTimeout(calculateTotal, 100);
        
        // Set initial payment method to cash
        selectPaymentMethod('cash');
    </script>
</body>
</html>
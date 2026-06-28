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
    JOIN shift_schedule sh ON sc.shift_id = sh.id 
    WHERE sc.status = 'open' 
    ORDER BY sc.id DESC 
    LIMIT 1
");
$active_shift = $stmt->fetch();

if(!$active_shift) {
    $error = "⚠️ No active shift! Please <a href='shift_closing.php' class='alert-link'>start a shift</a> first.";
}

// =============================================
// Get CNG nozzles - PIPELINE nozzles only (no tank association)
// =============================================
$cng_nozzles = $pdo->query("
    SELECT 
        n.*, 
        p.product_name,
        p.unit_price
    FROM nozzles n 
    JOIN fuel_products p ON n.product_id = p.id 
    WHERE p.product_name IN ('CNG', 'Natural Gas') 
    AND n.is_active = 1 
    AND n.is_pipeline = 1
    ORDER BY n.nozzle_name
")->fetchAll();

// Get CNG products
$cng_products = $pdo->query("
    SELECT * FROM fuel_products 
    WHERE product_name IN ('CNG', 'Natural Gas') 
    AND is_active = 1
")->fetchAll();

// Process CNG Sale
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['make_cng_sale'])) {
    $invoice_no = 'CNG-' . date('YmdHis') . rand(100, 999);
    $shift_id = $active_shift['id'] ?? 0;
    $nozzle_id = $_POST['nozzle_id'];
    $product_id = $_POST['product_id'];
    $opening_meter = floatval($_POST['opening_meter']);
    $closing_meter = floatval($_POST['closing_meter']);
    $unit_price = floatval($_POST['unit_price']);
    $sale_type = $_POST['sale_type'];
    
    // =============================================
    // CUSTOMER DATA HANDLING - SINGLE SOURCE
    // =============================================
    $customer_id = !empty($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
    $customer_name = isset($_POST['customer_name']) ? trim($_POST['customer_name']) : '';
    $customer_phone = isset($_POST['customer_phone']) ? trim($_POST['customer_phone']) : '';

    // If customer ID is provided but name is empty, fetch from database
    if($customer_id && empty($customer_name)) {
        $stmt = $pdo->prepare("SELECT customer_name, phone FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $cust = $stmt->fetch();
        if($cust) {
            $customer_name = $cust['customer_name'];
            $customer_phone = $cust['phone'] ?? '';
        }
    }

    // If no customer ID but name is provided, try to find existing customer
    if(empty($customer_id) && !empty($customer_name)) {
        if(!empty($customer_phone)) {
            $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ?");
            $stmt->execute([$customer_phone]);
            $existing = $stmt->fetch();
            if($existing) {
                $customer_id = $existing['id'];
            }
        }
        if(empty($customer_id)) {
            $stmt = $pdo->prepare("SELECT id FROM customers WHERE customer_name = ?");
            $stmt->execute([$customer_name]);
            $existing = $stmt->fetch();
            if($existing) {
                $customer_id = $existing['id'];
            }
        }
    }

    // If still no customer_id and credit sale, create new customer
    if($sale_type == 'credit' && empty($customer_id) && !empty($customer_name)) {
        $stmt = $pdo->prepare("INSERT INTO customers (customer_code, customer_name, phone, credit_limit) VALUES (?, ?, ?, ?)");
        $customer_code = 'CUST-' . date('Ymd') . rand(100, 999);
        $stmt->execute([$customer_code, $customer_name, $customer_phone, 50000]);
        $customer_id = $pdo->lastInsertId();
    }
    // =============================================
    
    // =============================================
    // PAYMENT METHOD VARIABLES
    // =============================================
    $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'cash';
    $card_number = isset($_POST['card_number']) ? trim($_POST['card_number']) : '';
    $card_holder_name = isset($_POST['card_holder_name']) ? trim($_POST['card_holder_name']) : '';
    $transaction_id = isset($_POST['transaction_id']) ? trim($_POST['transaction_id']) : '';
    
    // For credit sales, payment method is 'credit'
    if($sale_type == 'credit') {
        $payment_method = 'credit';
    }
    // =============================================
    
    $input_type = $_POST['input_type'] ?? 'amount';
    $amount_input = isset($_POST['amount_input']) ? floatval($_POST['amount_input']) : 0;
    $unit_input = isset($_POST['unit_input']) ? floatval($_POST['unit_input']) : 0;
    
    // Calculate based on input type
    if($input_type == 'amount' && $amount_input > 0) {
        $total_amount = $amount_input;
        $quantity = $total_amount / $unit_price;
        $closing_meter = $opening_meter + $quantity;
    } else if($input_type == 'unit' && $unit_input > 0) {
        $quantity = $unit_input;
        $total_amount = $quantity * $unit_price;
        $closing_meter = $opening_meter + $quantity;
    } else {
        $quantity = $closing_meter - $opening_meter;
        $total_amount = $quantity * $unit_price;
    }

    // =============================================
    // CHECK FOR CUSTOMER ADVANCE BALANCE
    // =============================================
    $advance_used = 0;
    $advance_payment_id = null;

    // Store the FULL original amount (never modify this)
    $original_total_amount = $total_amount;

    if($sale_type == 'credit' && $customer_id) {
        $stmt = $pdo->prepare("SELECT advance_balance FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $advance_balance = $stmt->fetch()['advance_balance'] ?? 0;
        
        if($advance_balance > 0) {
            $advance_used = min($advance_balance, $original_total_amount);
            
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
            }
        }
    }

    // Net amount after advance adjustment (for sales table and accounting)
    $net_total_amount = $original_total_amount - $advance_used;
    $received = isset($_POST['received']) ? floatval($_POST['received']) : $net_total_amount;
    $change = $received - $net_total_amount;
    
    $vehicle_number = isset($_POST['vehicle_number']) ? trim($_POST['vehicle_number']) : '';
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

    if($quantity <= 0) {
        $error = "Invalid quantity! Please check your input.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // =============================================
            // Insert into gas_sales table with NET amount
            // =============================================
            $stmt = $pdo->prepare("
            INSERT INTO gas_sales (
                invoice_no, sale_date, shift_id, nozzle_id, operator_id,
                customer_id, customer_name, customer_phone, sale_type,
                payment_method, card_number, card_holder_name, transaction_id,
                opening_meter, closing_meter, quantity_liters,
                unit_price, total_amount, received_amount, change_amount,
                status, advance_used, advance_payment_id,
                vehicle_number, remarks
            ) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?, ?, ?)
            ");

            $stmt->execute([
                $invoice_no, $shift_id, $nozzle_id, $user['id'],
                $customer_id, $customer_name, $customer_phone, $sale_type,
                $payment_method, $card_number, $card_holder_name, $transaction_id,
                $opening_meter, $closing_meter, $quantity,
                $unit_price, $net_total_amount, $received, $change,
                $advance_used, $advance_payment_id,
                $vehicle_number, $remarks
            ]);
            
            $sale_id = $pdo->lastInsertId();
            
            // Update nozzle closing meter (NO STOCK UPDATE for CNG)
            $stmt = $pdo->prepare("UPDATE nozzles SET closing_meter = ? WHERE id = ?");
            $stmt->execute([$closing_meter, $nozzle_id]);
            
            // =============================================
            // UPDATE CUSTOMER BALANCE FOR CREDIT SALES (NET amount only)
            // =============================================
            if($customer_id && $sale_type == 'credit') {
                $net_owed = $original_total_amount - $advance_used;
                if($net_owed > 0) {
                    $stmt = $pdo->prepare("UPDATE customers SET current_balance = current_balance + ? WHERE id = ?");
                    $stmt->execute([$net_owed, $customer_id]);
                }
            }
            
            // =============================================
            // FOR CREDIT SALES - PROPER ACCOUNTING
            // Debit = Full amount (what customer owes)
            // Credit = Advance used (what was paid from advance)
            // Balance = Debit - Credit = Net amount owed
            // =============================================
            if($sale_type == 'credit' && $customer_id) {
                $due_date = date('Y-m-d', strtotime('+30 days'));
                $net_balance_due = $original_total_amount - $advance_used;
                
                try {
                    // Check if sale exists in sales table
                    $stmt = $pdo->prepare("SELECT id FROM sales WHERE invoice_no = ?");
                    $stmt->execute([$invoice_no]);
                    $existing_sale = $stmt->fetch();
                    
                    if(!$existing_sale) {
                        // Insert into sales table with NET amount
                        $stmt = $pdo->prepare("
                            INSERT INTO sales (
                                invoice_no, shift_id, nozzle_id, operator_id,
                                customer_id, customer_name, customer_phone, sale_type,
                                payment_method, product_id, quantity_liters, unit_price,
                                subtotal, vat_amount, tax_amount, total_amount, received_amount, change_amount,
                                vehicle_number, remarks
                            ) VALUES (
                                ?, ?, ?, ?,
                                ?, ?, ?, 'credit',
                                'credit', ?, ?, ?,
                                ?, 0, 0, ?, 0, 0,
                                ?, ?
                            )
                        ");
                        $stmt->execute([
                            $invoice_no, $shift_id, $nozzle_id, $user['id'],
                            $customer_id, $customer_name, $customer_phone,
                            $product_id, $quantity, $unit_price,
                            $net_total_amount, $net_total_amount,
                            $vehicle_number, $remarks
                        ]);
                        $sales_id = $pdo->lastInsertId();
                    } else {
                        $sales_id = $existing_sale['id'];
                    }
                    
                    // Insert into credit_sales with FULL amount and advance_adjusted
                    $stmt = $pdo->prepare("
                        INSERT INTO credit_sales (
                            sale_id, customer_id, invoice_no, sale_date, due_date, 
                            total_amount, advance_adjusted, balance_due, status
                        ) VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, 'pending')
                    ");
                    $stmt->execute([
                        $sales_id, 
                        $customer_id, 
                        $invoice_no, 
                        $due_date, 
                        $original_total_amount,    // FULL original amount (DEBIT)
                        $advance_used,              // Advance used (CREDIT)
                        $net_balance_due            // Net amount owed (DEBIT - CREDIT)
                    ]);
                    
                } catch(Exception $e) {
                    error_log("Credit sales insert error: " . $e->getMessage());
                }
            }
            
            // =============================================
            // Accounting entries - CNG Sales Revenue (using NET amount)
            // =============================================
            $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1000' LIMIT 1");
            $cash_account = $stmt->fetch();
            
            $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '4000' OR account_name LIKE '%CNG Sales%' LIMIT 1");
            $sales_account = $stmt->fetch();
            
            if(!$sales_account) {
                $stmt = $pdo->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, balance_type, is_active) VALUES ('4001', 'CNG Sales', 'income', 'credit', 1)");
                $stmt->execute();
                $sales_id = $pdo->lastInsertId();
            } else {
                $sales_id = $sales_account['id'];
            }
            
            if($sale_type == 'cash' && $cash_account && $sales_account) {
                $voucher_no = 'CNG-CASH-' . date('YmdHis') . rand(100, 999);
                $customer_info = $customer_id ? " - Customer: $customer_name" : "";
                $narration = "CNG sale$customer_info - $invoice_no - Quantity: " . number_format($quantity, 2) . " m³ - Amount: BDT " . number_format($net_total_amount, 2);
                
                $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'receipt', CURDATE(), ?, ?, 'approved')");
                $stmt->execute([$voucher_no, $narration, $user['id']]);
                $voucher_id = $pdo->lastInsertId();
                
                $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                    (?, ?, ?, ?, ?),
                    (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $voucher_id, $cash_account['id'], $net_total_amount, 0, "CNG cash sale - $invoice_no",
                    $voucher_id, $sales_id, 0, $net_total_amount, "CNG sales revenue - $invoice_no"
                ]);
            } elseif($sale_type == 'credit' && $sales_account) {
                $voucher_no = 'CNG-CREDIT-' . date('YmdHis') . rand(100, 999);
                $narration = "CNG credit sale to $customer_name - Invoice: $invoice_no - Amount: BDT " . number_format($net_total_amount, 2);
                
                $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1300' OR account_name LIKE '%Accounts Receivable%' LIMIT 1");
                $ar_account = $stmt->fetch();
                
                if($ar_account) {
                    $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'journal', CURDATE(), ?, ?, 'approved')");
                    $stmt->execute([$voucher_no, $narration, $user['id']]);
                    $voucher_id = $pdo->lastInsertId();
                    
                    $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                        (?, ?, ?, ?, ?),
                        (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $voucher_id, $ar_account['id'], $net_total_amount, 0, "CNG credit sale to $customer_name - $invoice_no",
                        $voucher_id, $sales_id, 0, $net_total_amount, "CNG sales revenue - $invoice_no"
                    ]);
                }
            }
            
            $pdo->commit();
            
            $_SESSION['last_cng_invoice'] = [
                'invoice_no' => $invoice_no,
                'customer_id' => $customer_id,
                'customer_name' => $customer_name,
                'customer_phone' => $customer_phone,
                'vehicle_number' => $vehicle_number,
                'remarks' => $remarks,
                'date' => date('Y-m-d H:i:s'),
                'opening_meter' => $opening_meter,
                'closing_meter' => $closing_meter,
                'quantity' => $quantity,
                'unit_type' => 'cubic_meters',
                'unit_price' => $unit_price,
                'total' => $net_total_amount,
                'original_total' => $original_total_amount,
                'advance_used' => $advance_used,
                'received' => $received,
                'change' => $change,
                'input_type' => $input_type,
                'sale_type' => $sale_type,
                'payment_method' => $payment_method,
                'card_number' => $card_number,
                'card_holder_name' => $card_holder_name,
                'transaction_id' => $transaction_id
            ];
            
            header("Location: print_cng_invoice.php?invoice=" . $invoice_no);
            exit();
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    }
}

// (All your existing HTML, CSS, and JavaScript remains exactly as you had it)

// ... rest of the HTML and JavaScript remains the same ...
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CNG Sales (Cubic Meter Based)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .cng-card { background: white; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; overflow: hidden; }
        .cng-card-header { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white; padding: 15px 20px; font-weight: 600; }
        .cng-card-header.cng { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .meter-display { font-family: 'Courier New', monospace; font-size: 18px; font-weight: bold; }
        .meter-box { background: #e8f4f8; padding: 15px; border-radius: 10px; margin: 10px 0; }
        .meter-box .meter-value { font-size: 24px; font-weight: bold; color: #17a2b8; }
        .nozzle-btn {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white; border: none; border-radius: 10px; padding: 12px; margin: 5px; width: 100%;
            transition: all 0.2s;
        }
        .nozzle-btn:hover, .nozzle-btn.active { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); transform: scale(1.02); }
        .nozzle-btn.disabled { opacity: 0.5; pointer-events: none; }
        .amount-display { font-size: 28px; font-weight: bold; text-align: right; background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 10px; }
        .total-amount { font-size: 32px; color: #28a745; }
        .shift-info { background: #fff3cd; padding: 10px; border-radius: 8px; margin-bottom: 15px; }
        .unit-badge { background: #17a2b8; color: white; padding: 2px 10px; border-radius: 15px; font-size: 12px; }
        .input-toggle {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
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
        .cng-info {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .cng-info i { color: #28a745; }
        .credit-badge { background: #ffc107; color: #856404; }
        .cash-badge { background: #28a745; color: white; }
        .pipeline-badge {
            background: #0d6efd;
            color: white;
            padding: 2px 10px;
            border-radius: 15px;
            font-size: 11px;
        }
        .vehicle-field { 
            background: #f8f9fa; 
            padding: 8px 12px; 
            border-radius: 8px; 
            margin: 5px 0; 
        }
        .vehicle-field i { color: #667eea; }

        /* Payment Method Styles */
        .payment-section {
            background: #e8f4f8;
            padding: 12px 15px;
            border-radius: 8px;
            margin: 10px 0 15px 0;
            border-left: 4px solid #17a2b8;
        }
        .payment-section .payment-methods {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 5px;
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
        
        /* New Customer Modal */
        .modal-header .close { background: none; border: none; font-size: 1.5rem; color: white; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-gas-pump"></i> CNG Sales (Cubic Meter)</h2>
                <div>
                    <a href="cng_sales_report.php" class="btn btn-info">
                        <i class="fas fa-chart-bar"></i> View Reports
                    </a>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if($active_shift): ?>
            <div class="shift-info">
                <i class="fas fa-clock"></i> 
                <strong>Active Shift:</strong> <?php echo $active_shift['shift_name']; ?> | 
                <strong>Started:</strong> <?php echo date('d-m-Y h:i A', strtotime($active_shift['opening_time'])); ?>
                <span class="badge bg-warning text-dark ms-2"><i class="fas fa-lock"></i> Locked</span>
            </div>
            <input type="hidden" name="shift_id" value="<?php echo $active_shift['shift_id']; ?>">
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> 
                No active shift! Please <a href="shift_closing.php">start a shift</a> first.
            </div>
            <?php endif; ?>
            
            <!-- CNG Info -->
            <div class="cng-info">
                <i class="fas fa-info-circle"></i>
                <strong>CNG Sales:</strong> Supplied by Titas Gas pipeline. No stock tracking required. 
                Monthly bill will be generated separately based on meter readings.
                <span class="badge pipeline-badge ms-2"><i class="fas fa-pipe"></i> Pipeline Nozzles</span>
            </div>
            
            <div class="row">
                <div class="col-md-7">
                    <div class="cng-card">
                        <div class="cng-card-header cng">
                            <i class="fas fa-gas-pump"></i> CNG Meter Reading Sale
                            <span class="float-end"><span class="unit-badge">Cubic Meters (m³)</span></span>
                        </div>
                        <div class="card-body p-3">
                            <form method="POST" id="cngSaleForm">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-oil-can"></i> Select CNG Nozzle</label>
                                            <select name="nozzle_id" id="nozzle_id" class="form-control" required>
                                                <option value="">-- Select Nozzle --</option>
                                                <?php foreach($cng_nozzles as $nozzle): ?>
                                                    <option value="<?php echo $nozzle['id']; ?>" 
                                                            data-product-id="<?php echo $nozzle['product_id']; ?>"
                                                            data-price="<?php echo $nozzle['unit_price']; ?>"
                                                            data-current-meter="<?php echo $nozzle['closing_meter'] ?? 0; ?>"
                                                            data-nozzle-name="<?php echo $nozzle['nozzle_name']; ?>">
                                                        <?php echo $nozzle['nozzle_name']; ?> - <?php echo $nozzle['product_name']; ?>
                                                        <span class="badge pipeline-badge"><i class="fas fa-pipe"></i> Pipeline</span>
                                                        (Current: <?php echo number_format($nozzle['closing_meter'] ?? 0, 2); ?> m³)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-tag"></i> Sale Type</label>
                                            <select name="sale_type" id="sale_type" class="form-control" required onchange="toggleSaleType(this)">
                                                <option value="cash">Cash Sale</option>
                                                <option value="credit">Credit Sale</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- ============================================= -->
                                <!-- CUSTOMER SELECTION - SINGLE SOURCE -->
                                <!-- ============================================= -->
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="card mb-3" style="background: #f8f9fa; border: 1px solid #dee2e6;">
                                            <div class="card-body p-2">
                                                <div class="row">
                                                    <div class="col-md-5">
                                                        <div class="mb-2">
                                                            <label><i class="fas fa-user"></i> Customer</label>
                                                            <div class="d-flex gap-1">
                                                             <select name="customer_id" id="customer_id" class="form-control form-control-sm" onchange="loadCustomerData(this)">
                                                                <option value="">-- Walk-in Customer --</option>
                                                                <?php 
                                                                $customers = $pdo->query("SELECT * FROM customers WHERE is_active = 1 ORDER BY customer_name")->fetchAll();
                                                                foreach($customers as $c): 
                                                                    $net = ($c['current_balance'] ?? 0) - ($c['advance_balance'] ?? 0);
                                                                    $status = $net > 0 ? 'Due: ' . number_format($net, 2) : ($net < 0 ? 'Adv: ' . number_format(abs($net), 2) : 'Settled');
                                                                ?>
                                                                    <option value="<?php echo $c['id']; ?>" 
                                                                            data-name="<?php echo $c['customer_name']; ?>"
                                                                            data-phone="<?php echo $c['phone']; ?>"
                                                                            data-address="<?php echo $c['address']; ?>"
                                                                            data-balance="<?php echo $c['current_balance'] ?? 0; ?>"
                                                                            data-advance="<?php echo $c['advance_balance'] ?? 0; ?>">
                                                                        <?php echo $c['customer_name']; ?> (<?php echo $c['customer_code']; ?>) - <?php echo $status; ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>

                                                                <button type="button" class="btn btn-sm btn-success" onclick="openNewCustomerModal()" title="Add New Customer">
                                                                    <i class="fas fa-plus"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="mb-2">
                                                            <label><i class="fas fa-user-tag"></i> Customer Name</label>
                                                            <input type="text" name="customer_name" id="customer_name" class="form-control form-control-sm" placeholder="Enter name or select above">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="mb-2">
                                                            <label><i class="fas fa-phone"></i> Phone</label>
                                                            <input type="text" name="customer_phone" id="customer_phone" class="form-control form-control-sm" placeholder="Enter phone">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div id="customerInfo" style="display:none; background: #e8f4f8; padding: 8px 12px; border-radius: 6px; margin-top: 5px;">
                                                    <small>
                                                        <i class="fas fa-info-circle"></i> 
                                                        Balance: <span id="custBalance">0.00</span> | 
                                                        Advance: <span id="custAdvance">0.00</span> | 
                                                        <span id="custAddress"></span>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <input type="hidden" name="product_id" id="product_id">
                                <input type="hidden" name="nozzle_name" id="nozzle_name">
                                
                                <!-- Meter Readings -->
                                <div class="meter-box">
                                    <h6><i class="fas fa-tachometer-alt"></i> Meter Readings (m³)</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-2">
                                                <label>Opening Meter</label>
                                                <input type="number" name="opening_meter" id="opening_meter" class="form-control meter-display" step="0.01" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-2">
                                                <label>Closing Meter</label>
                                                <input type="number" name="closing_meter" id="closing_meter" class="form-control meter-display" step="0.01" placeholder="Enter closing meter reading">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Input Method Toggle -->
                                <div class="input-toggle">
                                    <div class="input-btn active" data-method="amount" onclick="toggleInputMethod('amount')">
                                        <i class="fas fa-money-bill"></i> Amount (TK)
                                    </div>
                                    <div class="input-btn inactive" data-method="unit" onclick="toggleInputMethod('unit')">
                                        <i class="fas fa-ruler"></i> Cubic Meter (m³)
                                    </div>
                                </div>
                                
                                <input type="hidden" name="input_type" id="input_type" value="amount">
                                
                                <!-- Amount Input Section -->
                                <div id="amount_section" class="input-section active">
                                    <div class="mb-3">
                                        <label><i class="fas fa-money-bill"></i> Amount (<?php echo $currency; ?>)</label>
                                        <input type="number" name="amount_input" id="amount_input" class="form-control" step="0.01" placeholder="Enter amount in Taka">
                                        <small class="text-muted">System will calculate cubic meters based on unit price</small>
                                    </div>
                                    <div class="alert alert-info">
                                        <strong>Calculated:</strong> <span id="calculated_units">0.00</span> m³ @ <?php echo $currency; ?> <span id="display_unit_price">0.00</span>/m³
                                    </div>
                                </div>
                                
                                <!-- Unit Input Section -->
                                <div id="unit_section" class="input-section">
                                    <div class="mb-3">
                                        <label><i class="fas fa-ruler"></i> Cubic Meters (m³)</label>
                                        <input type="number" name="unit_input" id="unit_input" class="form-control" step="0.01" placeholder="Enter cubic meters">
                                        <small class="text-muted">System will calculate amount based on unit price</small>
                                    </div>
                                    <div class="alert alert-info">
                                        <strong>Calculated:</strong> <?php echo $currency; ?> <span id="calculated_amount">0.00</span>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-money-bill"></i> Unit Price (<?php echo $currency; ?>/m³)</label>
                                            <input type="number" name="unit_price" id="unit_price" class="form-control" step="0.01" readonly required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-calculator"></i> Total Amount (<?php echo $currency; ?>)</label>
                                            <input type="text" id="total_amount_display" class="form-control" readonly>
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
                                
                                <!-- ============================================= -->
                                <!-- PAYMENT METHOD SECTION -->
                                <!-- ============================================= -->
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
                                
                                <button type="submit" name="make_cng_sale" class="btn btn-success w-100" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); border: none; padding: 12px;" <?php echo !$active_shift ? 'disabled' : ''; ?>>
                                    <i class="fas fa-print"></i> Process CNG Sale & Print Receipt
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-5">
                    <!-- Available CNG Nozzles -->
                    <div class="cng-card">
                        <div class="cng-card-header cng">
                            <i class="fas fa-oil-can"></i> Available CNG Nozzles
                            <span class="float-end"><span class="badge pipeline-badge"><i class="fas fa-pipe"></i> Pipeline</span></span>
                        </div>
                        <div class="card-body p-3">
                            <div class="row">
                                <?php if(empty($cng_nozzles)): ?>
                                    <div class="col-12">
                                        <div class="alert alert-info text-center">
                                            <i class="fas fa-info-circle"></i> No CNG pipeline nozzles configured.<br>
                                            <small>Please add CNG pipeline nozzles in <strong>Settings → Nozzles & Pipelines</strong>.<br>
                                            Make sure to check the <strong>"Pipeline Nozzle (CNG)"</strong> option.</small>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php foreach($cng_nozzles as $nozzle): ?>
                                        <div class="col-6 mb-2">
                                            <button type="button" class="nozzle-btn quick-nozzle" 
                                                    data-id="<?php echo $nozzle['id']; ?>"
                                                    data-product-id="<?php echo $nozzle['product_id']; ?>"
                                                    data-price="<?php echo $nozzle['unit_price']; ?>"
                                                    data-current-meter="<?php echo $nozzle['closing_meter'] ?? 0; ?>"
                                                    data-nozzle-name="<?php echo $nozzle['nozzle_name']; ?>">
                                                <i class="fas fa-gas-pump"></i><br>
                                                <strong><?php echo htmlspecialchars($nozzle['nozzle_name']); ?></strong><br>
                                                <small><?php echo $nozzle['product_name']; ?></small>
                                                <span class="badge pipeline-badge mt-1"><i class="fas fa-pipe"></i> Pipeline</span>
                                                <span class="d-block mt-1" style="font-size:10px; background:rgba(255,255,255,0.2); padding:2px 6px; border-radius:12px;">
                                                    Meter: <?php echo number_format($nozzle['closing_meter'] ?? 0, 2); ?> m³
                                                </span>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Info Card -->
                    <div class="cng-card">
                        <div class="cng-card-header" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                            <i class="fas fa-info-circle"></i> Quick Guide
                        </div>
                        <div class="card-body p-3">
                            <div class="alert alert-info">
                                <strong>Two Ways to Sell:</strong><br>
                                1. <strong>Amount Based:</strong> Enter TK amount → Auto-calculates m³<br>
                                2. <strong>Unit Based:</strong> Enter m³ → Auto-calculates amount
                            </div>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Note:</strong> No stock tracking for CNG. Supplied by Titas Gas pipeline.
                            </div>
                            <div class="alert alert-secondary">
                                <i class="fas fa-credit-card"></i>
                                <strong>Credit Sales:</strong> Customer is required for credit sales.
                            </div>
                            <div class="alert alert-primary">
                                <i class="fas fa-pipe"></i>
                                <strong>Pipeline Nozzles:</strong> These nozzles are directly connected to the government gas pipeline.
                            </div>
                            <div class="alert alert-info">
                                <i class="fas fa-car"></i>
                                <strong>Vehicle Number:</strong> Optional field for recording vehicle registration number.
                            </div>
                        </div>
                    </div>
                    
                    <!-- Today's CNG Sales Summary -->
                    <div class="cng-card">
                        <div class="cng-card-header" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                            <i class="fas fa-chart-line"></i> Today's CNG Summary
                        </div>
                        <div class="card-body p-3">
                            <?php
                            $today = date('Y-m-d');
                            $stmt = $pdo->prepare("
                                SELECT 
                                    COUNT(*) as total_sales,
                                    SUM(quantity_liters) as total_units,
                                    SUM(total_amount) as total_amount,
                                    SUM(CASE WHEN sale_type = 'cash' THEN total_amount ELSE 0 END) as cash_amount,
                                    SUM(CASE WHEN sale_type = 'credit' THEN total_amount ELSE 0 END) as credit_amount
                                FROM gas_sales 
                                WHERE DATE(sale_date) = ?
                                AND status = 'completed'
                            ");
                            $stmt->execute([$today]);
                            $today_summary = $stmt->fetch();
                            
                            if(!$today_summary) {
                                $today_summary = [
                                    'total_sales' => 0,
                                    'total_units' => 0,
                                    'total_amount' => 0,
                                    'cash_amount' => 0,
                                    'credit_amount' => 0
                                ];
                            }
                            ?>
                            <div class="row text-center">
                                <div class="col-6 border-end">
                                    <h4><?php echo number_format($today_summary['total_units'] ?? 0, 2); ?> m³</h4>
                                    <small class="text-muted">Total CNG Sold</small>
                                </div>
                                <div class="col-6">
                                    <h4 class="text-success"><?php echo $currency; ?> <?php echo number_format($today_summary['total_amount'] ?? 0, 2); ?></h4>
                                    <small class="text-muted">Total Revenue</small>
                                </div>
                            </div>
                            <hr>
                            <div class="row text-center">
                                <div class="col-6 border-end">
                                    <span class="badge cash-badge">Cash</span>
                                    <h6><?php echo $currency; ?> <?php echo number_format($today_summary['cash_amount'] ?? 0, 2); ?></h6>
                                </div>
                                <div class="col-6">
                                    <span class="badge credit-badge">Credit</span>
                                    <h6><?php echo $currency; ?> <?php echo number_format($today_summary['credit_amount'] ?? 0, 2); ?></h6>
                                </div>
                            </div>
                            <div class="mt-2 text-center">
                                <small class="text-muted">
                                    <i class="fas fa-shopping-cart"></i> <?php echo $today_summary['total_sales'] ?? 0; ?> transactions today
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- New Customer Modal -->
    <div class="modal fade" id="newCustomerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5><i class="fas fa-user-plus"></i> Add New Customer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="newCustomerForm">
                        <div class="mb-3">
                            <label>Customer Name *</label>
                            <input type="text" id="new_customer_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Phone</label>
                            <input type="text" id="new_customer_phone" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label>Email</label>
                            <input type="email" id="new_customer_email" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label>Address</label>
                            <textarea id="new_customer_address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label>Credit Limit (<?php echo $currency; ?>)</label>
                            <input type="number" id="new_customer_credit_limit" class="form-control" step="0.01" value="50000">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="saveNewCustomer()">
                        <i class="fas fa-save"></i> Save Customer
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // =============================================
    // FIX: Ensure Walk-in Customer is selected by default
    // =============================================
    function setDefaultCustomer() {
        var customerSelect = document.getElementById('customer_id');
        if(customerSelect) {
            // Reset to Walk-in Customer (first option with value="")
            customerSelect.value = '';
            
            // Clear all fields
            var nameField = document.getElementById('customer_name');
            var phoneField = document.getElementById('customer_phone');
            var infoDiv = document.getElementById('customerInfo');
            var balanceEl = document.getElementById('custBalance');
            var advanceEl = document.getElementById('custAdvance');
            var addressEl = document.getElementById('custAddress');
            
            if(nameField) nameField.value = '';
            if(phoneField) phoneField.value = '';
            if(infoDiv) infoDiv.style.display = 'none';
            if(balanceEl) balanceEl.innerText = '0.00';
            if(advanceEl) advanceEl.innerText = '0.00';
            if(addressEl) addressEl.innerHTML = '';
        }
    }

    // Run immediately
    setDefaultCustomer();

    // Run on DOM ready
    document.addEventListener('DOMContentLoaded', setDefaultCustomer);

    // Run on page show (back/forward)
    window.addEventListener('pageshow', setDefaultCustomer);

    // Run multiple times to override browser autofill
    setTimeout(setDefaultCustomer, 100);
    setTimeout(setDefaultCustomer, 300);
    setTimeout(setDefaultCustomer, 500);
    setTimeout(setDefaultCustomer, 800);

    // =============================================
    // CUSTOMER SELECTION FUNCTIONS
    // =============================================
    function loadCustomerData(select) {
        var option = select.options[select.selectedIndex];
        if(option && option.value) {
            document.getElementById('customer_name').value = option.dataset.name || '';
            document.getElementById('customer_phone').value = option.dataset.phone || '';
            document.getElementById('custBalance').innerText = parseFloat(option.dataset.balance || 0).toFixed(2);
            document.getElementById('custAdvance').innerText = parseFloat(option.dataset.advance || 0).toFixed(2);
            document.getElementById('custAddress').innerHTML = option.dataset.address ? '| <i class="fas fa-map-marker-alt"></i> ' + option.dataset.address : '';
            document.getElementById('customerInfo').style.display = 'block';
        } else {
            // Walk-in Customer selected - clear fields
            document.getElementById('customer_name').value = '';
            document.getElementById('customer_phone').value = '';
            document.getElementById('customerInfo').style.display = 'none';
            document.getElementById('custBalance').innerText = '0.00';
            document.getElementById('custAdvance').innerText = '0.00';
            document.getElementById('custAddress').innerHTML = '';
        }
    }

    // Auto-fill customer name when typing
    document.addEventListener('DOMContentLoaded', function() {
        var nameField = document.getElementById('customer_name');
        if(nameField) {
            nameField.addEventListener('input', function() {
                if(this.value.trim()) {
                    document.getElementById('customer_id').value = '';
                    document.getElementById('customerInfo').style.display = 'none';
                }
            });
        }
    });

        // =============================================
        // NEW CUSTOMER FUNCTIONS
        // =============================================
        function openNewCustomerModal() {
            document.getElementById('new_customer_name').value = '';
            document.getElementById('new_customer_phone').value = '';
            document.getElementById('new_customer_email').value = '';
            document.getElementById('new_customer_address').value = '';
            document.getElementById('new_customer_credit_limit').value = '50000';
            new bootstrap.Modal(document.getElementById('newCustomerModal')).show();
        }

        function saveNewCustomer() {
            var name = document.getElementById('new_customer_name').value.trim();
            var phone = document.getElementById('new_customer_phone').value.trim();
            var email = document.getElementById('new_customer_email').value.trim();
            var address = document.getElementById('new_customer_address').value.trim();
            var creditLimit = parseFloat(document.getElementById('new_customer_credit_limit').value) || 50000;

            if(!name) {
                alert('Please enter customer name!');
                return;
            }

            // Check if customer already exists in dropdown
            var existingCustomer = document.querySelector('#customer_id option[data-name="' + name + '"]');
            if(existingCustomer) {
                alert('Customer "' + name + '" already exists! Please select from the list.');
                // Close modal
                var modalEl = document.getElementById('newCustomerModal');
                var modal = bootstrap.Modal.getInstance(modalEl);
                if(modal) {
                    modal.hide();
                }
                document.getElementById('customer_id').value = existingCustomer.value;
                loadCustomerData(document.getElementById('customer_id'));
                return;
            }

            // Show loading
            var saveBtn = document.querySelector('#newCustomerModal .btn-success');
            var originalText = saveBtn.innerHTML;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            saveBtn.disabled = true;

            // Prepare data
            var formData = new FormData();
            formData.append('customer_name', name);
            formData.append('phone', phone);
            formData.append('email', email);
            formData.append('address', address);
            formData.append('credit_limit', creditLimit);

            // AJAX call to save customer
            $.ajax({
                url: 'ajax/save_customer.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(result) {
                    saveBtn.innerHTML = originalText;
                    saveBtn.disabled = false;
                    
                    console.log('Success response:', result);
                    
                    if(result.success) {
                        // Add new customer to dropdown
                        var select = document.getElementById('customer_id');
                        var option = document.createElement('option');
                        option.value = result.customer_id;
                        option.dataset.name = name;
                        option.dataset.phone = phone;
                        option.dataset.address = address;
                        option.dataset.balance = '0';
                        option.dataset.advance = '0';
                        option.text = name + ' (' + (result.customer_code || 'New') + ') - Settled';
                        select.appendChild(option);
                        select.value = result.customer_id;
                        loadCustomerData(select);
                        
                        // Close modal
                        var modalEl = document.getElementById('newCustomerModal');
                        var modal = bootstrap.Modal.getInstance(modalEl);
                        if(modal) {
                            modal.hide();
                        }
                        
                        // Show success
                        alert('✅ Customer "' + name + '" added successfully!');
                    } else {
                        if(result.customer_id) {
                            // Customer already exists, select it
                            var select = document.getElementById('customer_id');
                            select.value = result.customer_id;
                            loadCustomerData(select);
                            alert('Customer "' + name + '" already exists! Selected from list.');
                            var modalEl = document.getElementById('newCustomerModal');
                            var modal = bootstrap.Modal.getInstance(modalEl);
                            if(modal) {
                                modal.hide();
                            }
                        } else {
                            alert('Error: ' + result.message);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    saveBtn.innerHTML = originalText;
                    saveBtn.disabled = false;
                    
                    console.log('AJAX Error Details:');
                    console.log('Status:', status);
                    console.log('Error:', error);
                    console.log('Response Text:', xhr.responseText);
                    console.log('Status Code:', xhr.status);
                    
                    // Try to parse error response
                    try {
                        var response = JSON.parse(xhr.responseText);
                        alert('Error: ' + (response.message || 'Server error occurred'));
                    } catch(e) {
                        // If response is not JSON, show raw response
                        if(xhr.responseText) {
                            alert('Server Error:\n' + xhr.responseText);
                        } else {
                            alert('Error saving customer. Please check the server logs.\n\nStatus: ' + xhr.status + ' ' + status);
                        }
                    }
                }
            });
        }

        // =============================================
        // PAYMENT METHOD FUNCTIONS
        // =============================================
        function selectPaymentMethod(method) {
            document.getElementById('payment_method').value = method;
            
            document.querySelectorAll('.payment-method-btn').forEach(btn => {
                btn.classList.remove('active');
                if(btn.dataset.method == method) {
                    btn.classList.add('active');
                }
            });
            
            if(method === 'card') {
                document.getElementById('card_details').classList.add('show');
            } else {
                document.getElementById('card_details').classList.remove('show');
            }
            
            if(method === 'bkash' || method === 'nagad') {
                document.getElementById('transaction_id').placeholder = 'Enter ' + method.charAt(0).toUpperCase() + method.slice(1) + ' transaction ID';
            } else {
                document.getElementById('transaction_id').placeholder = 'Enter transaction ID';
            }
        }

        function toggleSaleType(select) {
            const paymentSection = document.getElementById('payment_section');
            const cashFields = document.getElementById('cash_fields');
            const customerSelect = document.getElementById('customer_id');
            const customerName = document.getElementById('customer_name');
            
            if(select.value === 'credit') {
                paymentSection.style.display = 'none';
                cashFields.style.display = 'none';
                document.getElementById('received').value = 0;
                document.getElementById('payment_method').value = 'credit';
                
                // For credit sales, customer is required
                if(!customerSelect.value && !customerName.value.trim()) {
                    customerName.focus();
                }
            } else {
                paymentSection.style.display = 'block';
                cashFields.style.display = 'block';
                document.getElementById('received').value = 0;
                document.getElementById('payment_method').value = 'cash';
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
            
            if(method === 'amount') {
                document.getElementById('amount_section').classList.add('active');
                document.getElementById('unit_section').classList.remove('active');
            } else {
                document.getElementById('amount_section').classList.remove('active');
                document.getElementById('unit_section').classList.add('active');
            }
            
            calculateTotal();
        }

        // =============================================
        // Nozzle selection
        // =============================================
        document.getElementById('nozzle_id').addEventListener('change', function() {
            let option = this.options[this.selectedIndex];
            if(!option.value) return;
            
            document.getElementById('product_id').value = option.getAttribute('data-product-id');
            document.getElementById('nozzle_name').value = option.getAttribute('data-nozzle-name') || option.text;
            let price = parseFloat(option.getAttribute('data-price')) || 0;
            document.getElementById('unit_price').value = price.toFixed(2);
            document.getElementById('display_unit_price').innerText = price.toFixed(2);
            
            let currentMeter = parseFloat(option.getAttribute('data-current-meter')) || 0;
            document.getElementById('opening_meter').value = currentMeter.toFixed(2);
            document.getElementById('closing_meter').value = '';
            
            calculateTotal();
        });

        // =============================================
        // Quick nozzle selection
        // =============================================
        document.querySelectorAll('.quick-nozzle').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('nozzle_id').value = this.getAttribute('data-id');
                document.getElementById('product_id').value = this.getAttribute('data-product-id');
                document.getElementById('nozzle_name').value = this.getAttribute('data-nozzle-name') || this.textContent.trim();
                let price = parseFloat(this.getAttribute('data-price')) || 0;
                document.getElementById('unit_price').value = price.toFixed(2);
                document.getElementById('display_unit_price').innerText = price.toFixed(2);
                
                let currentMeter = parseFloat(this.getAttribute('data-current-meter')) || 0;
                document.getElementById('opening_meter').value = currentMeter.toFixed(2);
                document.getElementById('closing_meter').value = '';
                
                document.querySelectorAll('.quick-nozzle').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                calculateTotal();
                document.getElementById('closing_meter').focus();
            });
        });

        // =============================================
        // Input events
        // =============================================
        document.getElementById('amount_input').addEventListener('input', function() {
            calculateTotal();
        });

        document.getElementById('unit_input').addEventListener('input', function() {
            calculateTotal();
        });

        document.getElementById('closing_meter').addEventListener('input', function() {
            let opening = parseFloat(document.getElementById('opening_meter').value) || 0;
            let closing = parseFloat(this.value) || 0;
            let quantity = closing - opening;
            if(quantity > 0) {
                let price = parseFloat(document.getElementById('unit_price').value) || 0;
                let total = quantity * price;
                document.getElementById('total_amount_display').value = total.toFixed(2);
                document.getElementById('total_amount').innerText = total.toFixed(2);
                calculateChange();
            }
        });

        document.getElementById('received').addEventListener('input', calculateChange);

        // =============================================
        // Sale type change
        // =============================================
        document.getElementById('sale_type').addEventListener('change', function() {
            toggleSaleType(this);
        });

        // =============================================
        // Calculate functions
        // =============================================
        function calculateTotal() {
            let price = parseFloat(document.getElementById('unit_price').value) || 0;
            let method = document.getElementById('input_type').value;
            let quantity = 0;
            let total = 0;
            let closingMeter = parseFloat(document.getElementById('opening_meter').value) || 0;
            
            if(method === 'amount') {
                let amount = parseFloat(document.getElementById('amount_input').value) || 0;
                if(price > 0 && amount > 0) {
                    quantity = amount / price;
                    total = amount;
                }
                document.getElementById('calculated_units').innerText = quantity.toFixed(2);
            } else {
                quantity = parseFloat(document.getElementById('unit_input').value) || 0;
                if(price > 0 && quantity > 0) {
                    total = quantity * price;
                }
                document.getElementById('calculated_amount').innerText = total.toFixed(2);
            }
            
            if(quantity > 0) {
                closingMeter = parseFloat(document.getElementById('opening_meter').value) || 0;
                document.getElementById('closing_meter').value = (closingMeter + quantity).toFixed(2);
            }
            
            document.getElementById('total_amount_display').value = total.toFixed(2);
            document.getElementById('total_amount').innerText = total.toFixed(2);
            calculateChange();
        }

        function calculateChange() {
            let total = parseFloat(document.getElementById('total_amount').innerText) || 0;
            let received = parseFloat(document.getElementById('received').value) || 0;
            let change = received - total;
            document.getElementById('change_amount').value = change.toFixed(2);
        }

        // =============================================
        // Form validation
        // =============================================
        document.getElementById('cngSaleForm').addEventListener('submit', function(e) {
            let nozzle = document.getElementById('nozzle_id').value;
            let method = document.getElementById('input_type').value;
            let amount = parseFloat(document.getElementById('amount_input').value) || 0;
            let units = parseFloat(document.getElementById('unit_input').value) || 0;
            
            if(!nozzle) {
                e.preventDefault();
                alert('❌ Please select a CNG nozzle');
                return false;
            }
            
            if(method === 'amount' && amount <= 0) {
                e.preventDefault();
                alert('❌ Please enter the amount in Taka');
                return false;
            }
            
            if(method === 'unit' && units <= 0) {
                e.preventDefault();
                alert('❌ Please enter the cubic meters');
                return false;
            }
            
            let opening = parseFloat(document.getElementById('opening_meter').value) || 0;
            let closing = parseFloat(document.getElementById('closing_meter').value) || 0;
            
            if(closing <= opening) {
                e.preventDefault();
                alert('❌ Closing meter must be greater than opening meter!');
                return false;
            }
            
            let saleType = document.getElementById('sale_type').value;
            if(saleType == 'credit') {
                let customerName = document.getElementById('customer_name').value.trim();
                if(!customerName) {
                    e.preventDefault();
                    alert('❌ Please enter customer name for credit sale');
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
                    alert('❌ Please enter card number');
                    return false;
                }
                if(!cardHolderName) {
                    e.preventDefault();
                    alert('❌ Please enter card holder name');
                    return false;
                }
            }
            
            // Confirm the sale
            let total = parseFloat(document.getElementById('total_amount').innerText) || 0;
            let quantity = method === 'amount' ? parseFloat(document.getElementById('calculated_units').innerText) : units;
            let paymentLabel = document.querySelector('.payment-method-btn.active')?.innerText?.trim() || 'Cash';
            
            let confirmMsg = `⚠️ CONFIRM CNG SALE ⚠️\n\n`;
            confirmMsg += `📊 Quantity: ${quantity.toFixed(2)} m³\n`;
            confirmMsg += `💰 Amount: BDT ${total.toFixed(2)}\n`;
            confirmMsg += `📌 Type: ${document.querySelector('#sale_type option:checked').text}\n`;
            confirmMsg += `💳 Payment: ${paymentLabel}\n`;
            if(saleType == 'credit') {
                confirmMsg += `👤 Customer: ${document.getElementById('customer_name').value.trim()}\n`;
            }
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
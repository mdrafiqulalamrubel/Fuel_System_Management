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
// FIXED: Get CNG nozzles - PIPELINE nozzles only (no tank association)
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
    $customer_name = isset($_POST['customer_name']) ? $_POST['customer_name'] : '';
    $customer_phone = isset($_POST['customer_phone']) ? $_POST['customer_phone'] : '';
    $vehicle_number = isset($_POST['vehicle_number']) ? trim($_POST['vehicle_number']) : '';
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
    $input_type = $_POST['input_type'] ?? 'amount'; // 'amount' or 'unit'
    $amount_input = isset($_POST['amount_input']) ? floatval($_POST['amount_input']) : 0;
    $unit_input = isset($_POST['unit_input']) ? floatval($_POST['unit_input']) : 0;
    
    // Calculate based on input type
    if($input_type == 'amount' && $amount_input > 0) {
        // Customer gave amount (TK) - calculate units
        $total_amount = $amount_input;
        $quantity = $total_amount / $unit_price;
        $closing_meter = $opening_meter + $quantity;
    } else if($input_type == 'unit' && $unit_input > 0) {
        // Customer gave units (m³) - calculate amount
        $quantity = $unit_input;
        $total_amount = $quantity * $unit_price;
        $closing_meter = $opening_meter + $quantity;
    } else {
        // Fallback: use meter difference
        $quantity = $closing_meter - $opening_meter;
        $total_amount = $quantity * $unit_price;
    }

    $received = isset($_POST['received']) ? floatval($_POST['received']) : $total_amount;
    $change = $received - $total_amount;

    // =============================================
    // CHECK FOR CUSTOMER ADVANCE BALANCE (CNG)
    // =============================================

    $advance_used = 0;
    $advance_payment_id = null;

    if($sale_type == 'credit' && !empty($customer_name)) {
        // Get or create customer ID if not set
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
            // Check if customer has advance balance
            $stmt = $pdo->prepare("SELECT advance_balance FROM customers WHERE id = ?");
            $stmt->execute([$customer_id]);
            $advance_balance = $stmt->fetch()['advance_balance'] ?? 0;
            
            if($advance_balance > 0) {
                $advance_used = min($advance_balance, $total_amount);
                
                if($advance_used > 0) {
                    // Update customer advance balance
                    $stmt = $pdo->prepare("UPDATE customers SET advance_balance = advance_balance - ? WHERE id = ?");
                    $stmt->execute([$advance_used, $customer_id]);
                    
                    // Get the advance payment record to use
                    $stmt = $pdo->prepare("SELECT id FROM advance_payments_customer WHERE customer_id = ? AND balance_amount > 0 ORDER BY advance_date LIMIT 1");
                    $stmt->execute([$customer_id]);
                    $advance_record = $stmt->fetch();
                    if($advance_record) {
                        $advance_payment_id = $advance_record['id'];
                        $stmt = $pdo->prepare("UPDATE advance_payments_customer SET used_amount = used_amount + ?, balance_amount = balance_amount - ? WHERE id = ?");
                        $stmt->execute([$advance_used, $advance_used, $advance_payment_id]);
                        
                        // Check if fully used
                        $stmt = $pdo->prepare("SELECT balance_amount FROM advance_payments_customer WHERE id = ?");
                        $stmt->execute([$advance_payment_id]);
                        $new_balance = $stmt->fetch()['balance_amount'];
                        if($new_balance <= 0) {
                            $stmt = $pdo->prepare("UPDATE advance_payments_customer SET status = 'fully_used' WHERE id = ?");
                            $stmt->execute([$advance_payment_id]);
                        }
                    }
                    
                    $total_amount = $total_amount - $advance_used;
                    $received = isset($_POST['received']) ? floatval($_POST['received']) : $total_amount;
                    $change = $received - $total_amount;
                }
            }
        }
    }

    if($quantity <= 0) {
        $error = "Invalid quantity! Please check your input.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Insert into gas_sales table - ADDED vehicle_number and remarks
            $stmt = $pdo->prepare("
            INSERT INTO gas_sales (
                invoice_no, sale_date, shift_id, nozzle_id, operator_id,
                customer_name, customer_phone, sale_type,
                opening_meter, closing_meter, quantity_liters,
                unit_price, total_amount, received_amount, change_amount,
                status, advance_used, advance_payment_id,
                vehicle_number, remarks
            ) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?, ?, ?)
        ");
            $stmt->execute([
                $invoice_no, $shift_id, $nozzle_id, $user['id'],
                $customer_name, $customer_phone, $sale_type,
                $opening_meter, $closing_meter, $quantity,
                $unit_price, $total_amount, $received, $change,
                $advance_used, $advance_payment_id,
                $vehicle_number, $remarks
            ]);
            
            $sale_id = $pdo->lastInsertId();
            
            // Update nozzle closing meter only (NO STOCK UPDATE for CNG)
            $stmt = $pdo->prepare("UPDATE nozzles SET closing_meter = ? WHERE id = ?");
            $stmt->execute([$closing_meter, $nozzle_id]);
            
            // Accounting entries - CNG Sales Revenue
            $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1000' LIMIT 1");
            $cash_account = $stmt->fetch();
            
            $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '4000' OR account_name LIKE '%CNG Sales%' LIMIT 1");
            $sales_account = $stmt->fetch();
            
            // If CNG Sales account doesn't exist, create it
            if(!$sales_account) {
                $stmt = $pdo->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, balance_type, is_active) VALUES ('4001', 'CNG Sales', 'income', 'credit', 1)");
                $stmt->execute();
                $sales_id = $pdo->lastInsertId();
            } else {
                $sales_id = $sales_account['id'];
            }
            
            if($sale_type == 'cash' && $cash_account && $sales_account) {
                $voucher_no = 'CNG-CASH-' . date('YmdHis') . rand(100, 999);
                $narration = "CNG sale - $invoice_no - Quantity: " . number_format($quantity, 2) . " m³ - Amount: BDT " . number_format($total_amount, 2);
                
                $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'receipt', CURDATE(), ?, ?, 'approved')");
                $stmt->execute([$voucher_no, $narration, $user['id']]);
                $voucher_id = $pdo->lastInsertId();
                
                $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                    (?, ?, ?, ?, ?),
                    (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $voucher_id, $cash_account['id'], $total_amount, 0, "CNG cash sale - $invoice_no",
                    $voucher_id, $sales_id, 0, $total_amount, "CNG sales revenue - $invoice_no"
                ]);
            }
            
            // Handle Credit Sale for CNG
            if($sale_type == 'credit') {
                $customer_id = null;
                
                // Get or create customer
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
                
                if($customer_id) {
                    $due_date = date('Y-m-d', strtotime('+30 days'));
                    $stmt = $pdo->prepare("
                        INSERT INTO credit_sales (sale_id, customer_id, invoice_no, sale_date, due_date, total_amount, paid_amount, balance_due, status) 
                        VALUES (?, ?, ?, CURDATE(), ?, ?, 0, ?, 'pending')
                    ");
                    $stmt->execute([$sale_id, $customer_id, $invoice_no, $due_date, $total_amount, $total_amount]);
                    
                    $stmt = $pdo->prepare("UPDATE customers SET current_balance = current_balance + ? WHERE id = ?");
                    $stmt->execute([$total_amount, $customer_id]);
                }
            }
            
            $pdo->commit();
            
            // =============================================
            // STORE SESSION DATA
            // =============================================
            $_SESSION['last_cng_invoice'] = [
                'invoice_no' => $invoice_no,
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
                'total' => $total_amount,
                'received' => $received,
                'change' => $change,
                'input_type' => $input_type,
                'sale_type' => $sale_type
            ];
            
            // =============================================
            // REDIRECT TO CNG INVOICE (NOT THERMAL)
            // =============================================
            header("Location: print_cng_invoice.php?invoice=" . $invoice_no);
            exit();
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
        }
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
                                            <select name="sale_type" id="sale_type" class="form-control" required>
                                                <option value="cash">Cash</option>
                                                <option value="credit">Credit</option>
                                            </select>
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
                                
                                <!-- ============================================= -->
                                <!-- VEHICLE NUMBER & REMARKS - ADDED -->
                                <!-- ============================================= -->
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
                                
                                <button type="submit" name="make_cng_sale" class="btn btn-success w-100" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); border: none; padding: 12px;" <?php echo !$active_shift ? 'disabled' : ''; ?>>
                                    <i class="fas fa-print"></i> Process CNG Sale & Print Receipt
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-5">
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
                                <strong>Credit Sales:</strong> Customer name is required for credit sales.
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
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle input method
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

        // Nozzle selection
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

        // Quick nozzle selection
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

        // Amount input
        document.getElementById('amount_input').addEventListener('input', function() {
            calculateTotal();
        });

        // Unit input
        document.getElementById('unit_input').addEventListener('input', function() {
            calculateTotal();
        });

        // Closing meter manual entry
        document.getElementById('closing_meter').addEventListener('input', function() {
            let opening = parseFloat(document.getElementById('opening_meter').value) || 0;
            let closing = parseFloat(this.value) || 0;
            let quantity = closing - opening;
            if(quantity > 0) {
                let price = parseFloat(document.getElementById('unit_price').value) || 0;
                let total = quantity * price;
                document.getElementById('quantity').value = quantity.toFixed(2);
                document.getElementById('total_amount_display').value = total.toFixed(2);
                document.getElementById('total_amount').innerText = total.toFixed(2);
                calculateChange();
            }
        });

        // Received amount
        document.getElementById('received').addEventListener('input', calculateChange);

        // Sale type change
        document.getElementById('sale_type').addEventListener('change', function() {
            if(this.value == 'credit') {
                document.getElementById('customer_fields').style.display = 'block';
                document.getElementById('cash_fields').style.display = 'none';
                document.getElementById('customer_name').setAttribute('required', 'required');
                document.getElementById('received').value = 0;
            } else {
                document.getElementById('customer_fields').style.display = 'none';
                document.getElementById('cash_fields').style.display = 'block';
                document.getElementById('customer_name').removeAttribute('required');
                document.getElementById('received').value = 0;
            }
            calculateChange();
        });

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
            
            // Set closing meter
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

        // Form validation
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
            
            // Confirm the sale
            let total = parseFloat(document.getElementById('total_amount').innerText) || 0;
            let quantity = method === 'amount' ? parseFloat(document.getElementById('calculated_units').innerText) : units;
            
            let confirmMsg = `⚠️ CONFIRM CNG SALE ⚠️\n\n`;
            confirmMsg += `📊 Quantity: ${quantity.toFixed(2)} m³\n`;
            confirmMsg += `💰 Amount: BDT ${total.toFixed(2)}\n`;
            confirmMsg += `📌 Type: ${document.querySelector('#sale_type option:checked').text}\n`;
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
    </script>
</body>
</html>
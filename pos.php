<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

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

// REVISED: Get nozzles with TANK stock info (not nozzle stock)
// One tank can have multiple nozzles - all show the SAME tank stock
$nozzles = $pdo->query("
    SELECT 
        n.*, 
        t.id as tank_id,
        t.tank_name,
        t.product_id, 
        t.current_stock_liters as tank_stock,
        t.capacity_liters as tank_capacity,
        p.product_name, 
        p.unit_price 
    FROM nozzles n 
    JOIN tanks t ON n.tank_id = t.id 
    JOIN fuel_products p ON t.product_id = p.id 
    WHERE n.is_active = 1 AND t.is_active = 1
    ORDER BY t.tank_name, n.nozzle_name
")->fetchAll();

// Group nozzles by tank for better display
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
            'nozzles' => []
        ];
    }
    $tanks_with_nozzles[$tank_id]['nozzles'][] = $nozzle;
}

// Get products with total stock info (from all tanks combined)
$products = $pdo->query("
    SELECT p.*, COALESCE(SUM(t.current_stock_liters), 0) as total_stock
    FROM fuel_products p 
    LEFT JOIN tanks t ON p.id = t.product_id
    WHERE p.is_active = 1
    GROUP BY p.id
")->fetchAll();

// Get shifts from shift_schedule table
$shifts = $pdo->query("SELECT * FROM shift_schedule WHERE is_active = 1 ORDER BY start_time")->fetchAll();
$auto_shift_id = getCurrentShift($pdo);

// Process sale
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['make_sale'])) {
    $invoice_no = 'INV-' . date('YmdHis');
    $shift_id = $_POST['shift_id'];
    $nozzle_id = $_POST['nozzle_id'];
    $product_id = $_POST['product_id'];
    $tank_id = $_POST['tank_id'];  // Get tank_id from form
    $quantity = floatval($_POST['quantity']);
    $unit_price = floatval($_POST['unit_price']);
    $sale_type = $_POST['sale_type'];
    $customer_name = isset($_POST['customer_name']) ? $_POST['customer_name'] : '';
    $customer_phone = isset($_POST['customer_phone']) ? $_POST['customer_phone'] : '';
    
    $total_amount = $quantity * $unit_price;
    $subtotal = $total_amount;
    $vat_amount = 0;
    $tax_amount = 0;
    
    $received = isset($_POST['received']) ? floatval($_POST['received']) : 0;
    $change = $received - $total_amount;
    
    try {
        $pdo->beginTransaction();
        
        // REVISED: Check stock from TANK (not nozzle)
        $stmt = $pdo->prepare("SELECT current_stock_liters FROM tanks WHERE id = ?");
        $stmt->execute([$tank_id]);
        $current_stock = $stmt->fetch()['current_stock_liters'] ?? 0;
        
        if($current_stock < $quantity) {
            throw new Exception("Insufficient stock in {$tank_name}! Available: " . number_format($current_stock, 2) . " Liters");
        }
        
        // Insert sale
        $stmt = $pdo->prepare("INSERT INTO sales (invoice_no, shift_id, nozzle_id, operator_id, customer_name, customer_phone, sale_type, product_id, quantity_liters, unit_price, subtotal, vat_amount, tax_amount, total_amount, received_amount, change_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$invoice_no, $shift_id, $nozzle_id, $user['id'], $customer_name, $customer_phone, $sale_type, $product_id, $quantity, $unit_price, $subtotal, $vat_amount, $tax_amount, $total_amount, $received, $change]);
        $sale_id = $pdo->lastInsertId();
        
        // REVISED: Update stock on the TANK (not per nozzle)
        $stmt = $pdo->prepare("UPDATE tanks SET current_stock_liters = current_stock_liters - ? WHERE id = ?");
        $stmt->execute([$quantity, $tank_id]);
        
        // Update nozzle meter reading
        $stmt = $pdo->prepare("UPDATE nozzles SET closing_meter = closing_meter + ? WHERE id = ?");
        $stmt->execute([$quantity, $nozzle_id]);
        
        // Get updated tank stock for ledger
        $stmt = $pdo->prepare("SELECT current_stock_liters FROM tanks WHERE id = ?");
        $stmt->execute([$tank_id]);
        $new_balance = $stmt->fetch()['current_stock_liters'];
        
        // Insert into stock ledger
        $stmt = $pdo->prepare("INSERT INTO stock_ledger (product_id, tank_id, transaction_type, reference_no, out_quantity, balance_quantity) VALUES (?, ?, 'sale', ?, ?, ?)");
        $stmt->execute([$product_id, $tank_id, $invoice_no, $quantity, $new_balance]);
        
        // Get account IDs
        $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '1300'");
        $stmt->execute();
        $ar_account = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '4000'");
        $stmt->execute();
        $sales_account = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '1000'");
        $stmt->execute();
        $cash_account = $stmt->fetch();
        
        $customer_id = null;
        
        // Handle Credit Sale
        if($sale_type == 'credit') {
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
            
            // Insert into credit_sales
            $due_date = date('Y-m-d', strtotime('+30 days'));
            $stmt = $pdo->prepare("INSERT INTO credit_sales (sale_id, customer_id, invoice_no, sale_date, due_date, total_amount, paid_amount, balance_due, status) VALUES (?, ?, ?, CURDATE(), ?, ?, 0, ?, 'pending')");
            $stmt->execute([$sale_id, $customer_id, $invoice_no, $due_date, $total_amount, $total_amount]);
            
            // Update customer current balance
            $stmt = $pdo->prepare("UPDATE customers SET current_balance = current_balance + ? WHERE id = ?");
            $stmt->execute([$total_amount, $customer_id]);
            
            // Create accounting entry for credit sale
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
                $narration = "Cash sale - Invoice: $invoice_no - Amount: $total_amount";
                
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
        
        // Get product name
        $stmt = $pdo->prepare("SELECT product_name FROM fuel_products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product_data = $stmt->fetch();
        
        // Get tank name
        $stmt = $pdo->prepare("SELECT tank_name FROM tanks WHERE id = ?");
        $stmt->execute([$tank_id]);
        $tank_data = $stmt->fetch();
        
        $_SESSION['last_invoice'] = [
            'invoice_no' => $invoice_no,
            'customer_name' => $customer_name,
            'customer_phone' => $customer_phone,
            'date' => date('Y-m-d H:i:s'),
            'product_id' => $product_id,
            'product' => $product_data['product_name'],
            'tank_name' => $tank_data['tank_name'],
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'subtotal' => $subtotal,
            'vat' => 0,
            'tax' => 0,
            'total' => $total_amount,
            'received' => $received,
            'change' => $change,
            'sale_type' => $sale_type,
            'customer_id' => $customer_id
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
        .tank-card { 
            background: white; 
            border-radius: 15px; 
            margin-bottom: 15px;
            border: 2px solid #e0e0e0;
            overflow: hidden;
        }
        .tank-header { 
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); 
            color: white; 
            padding: 12px 15px;
            font-weight: bold;
            cursor: pointer;
        }
        .tank-header i { margin-right: 10px; }
        .tank-stock { font-size: 14px; background: rgba(255,255,255,0.2); padding: 3px 10px; border-radius: 20px; }
        .nozzle-btn { 
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); 
            color: white; 
            border: none; 
            border-radius: 10px; 
            padding: 12px; 
            margin: 5px; 
            width: 100%;
            transition: all 0.2s;
        }
        .nozzle-btn:hover, .nozzle-btn.active { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); transform: scale(1.02); }
        .nozzle-btn.disabled { opacity: 0.5; pointer-events: none; }
        .product-stock { 
            font-size: 11px; 
            margin-top: 5px; 
            display: block;
            background: rgba(255,255,255,0.2);
            padding: 3px 8px;
            border-radius: 15px;
        }
        .amount-display { font-size: 28px; font-weight: bold; text-align: right; background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 10px; }
        .low-stock { background: #dc3545 !important; }
        .stock-warning { background: #ffc107; color: #856404; }
        .total-amount { font-size: 32px; color: #28a745; }
        .shift-note { font-size: 11px; color: #28a745; margin-top: 3px; display: block; }
        .current-time-badge {
            background: rgba(0, 0, 0, 0.25);
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
        .tank-info {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 13px;
        }
        .tank-info .label { font-weight: bold; color: #666; }
        .nozzles-container {
            padding: 15px;
            background: #fafafa;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
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
                                            <select name="shift_id" id="shift_id" class="form-control" required>
                                                <option value="">Select Shift</option>
                                                <?php foreach($shifts as $shift): ?>
                                                    <option value="<?php echo $shift['id']; ?>" <?php echo ($auto_shift_id == $shift['id']) ? 'selected' : ''; ?>>
                                                        <?php echo $shift['shift_name']; ?> (<?php echo date('h:i A', strtotime($shift['start_time'])); ?> - <?php echo date('h:i A', strtotime($shift['end_time'])); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <span class="shift-note">
                                                <i class="fas fa-info-circle"></i> Shift auto-selected based on current time
                                            </span>
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
                                                            data-stock="<?php echo $nozzle['tank_stock']; ?>">
                                                        <?php echo $nozzle['nozzle_name']; ?> - <?php echo $nozzle['product_name']; ?>
                                                        (Tank: <?php echo $nozzle['tank_name']; ?> | Stock: <?php echo number_format($nozzle['tank_stock'], 0); ?> L)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Hidden field for tank_id -->
                                <input type="hidden" name="tank_id" id="tank_id">
                                
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
                                            <label><i class="fas fa-money-bill"></i> Unit Price (<?php echo $currency; ?>/L)</label>
                                            <input type="number" name="unit_price" id="unit_price" class="form-control" step="0.01" readonly required>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Display tank info -->
                                <div id="tank_info" class="tank-info" style="display:none;">
                                    <i class="fas fa-warehouse"></i> Tank: <span id="display_tank_name"></span> | 
                                    Available Stock: <span id="display_tank_stock"></span> Liters
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-tachometer-alt"></i> Quantity (Liters)</label>
                                            <input type="number" name="quantity" id="quantity" class="form-control" step="0.01" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-credit-card"></i> Sale Type</label>
                                            <select name="sale_type" id="sale_type" class="form-control" required>
                                                <option value="cash">Cash</option>
                                                <option value="credit">Credit</option>
                                            </select>
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
                                
                                <button type="submit" name="make_sale" class="btn btn-success w-100" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); border: none; padding: 12px;">
                                    <i class="fas fa-print"></i> Process Sale & Print Receipt
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-5">
                    <!-- Tanks with their Nozzles - Grouped View -->
                    <div class="pos-card">
                        <div class="pos-card-header">
                            <i class="fas fa-warehouse"></i> Fuel Tanks & Nozzles
                            <small class="float-end">One Tank → Multiple Nozzles</small>
                        </div>
                        <div class="pos-card-body">
                            <?php foreach($tanks_with_nozzles as $tank_id => $tank): 
                                $stock = $tank['tank_stock'];
                                $capacity = $tank['tank_capacity'];
                                $fill_percent = $capacity > 0 ? ($stock / $capacity) * 100 : 0;
                                $stock_class = $stock < 500 ? 'low-stock' : '';
                                $stock_text_class = $stock < 500 ? 'text-danger' : ($stock < 1000 ? 'text-warning' : 'text-success');
                            ?>
                                <div class="tank-card">
                                    <div class="tank-header">
                                        <i class="fas fa-oil-can"></i> 
                                        <?php echo htmlspecialchars($tank['tank_name']); ?>
                                        <span class="float-end">
                                            <span class="tank-stock">
                                                <i class="fas fa-gas-pump"></i> 
                                                <?php echo $tank['product_name']; ?> | 
                                                Stock: <span class="<?php echo $stock_text_class; ?>"><?php echo number_format($stock, 0); ?> L</span>
                                                (<?php echo round($fill_percent, 1); ?>% of <?php echo number_format($capacity, 0); ?> L)
                                            </span>
                                        </span>
                                    </div>
                                    <div class="nozzles-container">
                                        <!-- Progress bar for stock level -->
                                        <div class="progress mb-2" style="height: 8px;">
                                            <div class="progress-bar <?php echo $stock < 500 ? 'bg-danger' : ($stock < 1000 ? 'bg-warning' : 'bg-success'); ?>" 
                                                 style="width: <?php echo min($fill_percent, 100); ?>%"></div>
                                        </div>
                                        
                                        <div class="row">
                                            <?php foreach($tank['nozzles'] as $nozzle): ?>
                                                <div class="col-6 mb-2">
                                                    <button type="button" class="nozzle-btn quick-nozzle <?php echo $stock_class; ?>" 
                                                            data-id="<?php echo $nozzle['id']; ?>"
                                                            data-tank-id="<?php echo $tank_id; ?>"
                                                            data-tank-name="<?php echo $tank['tank_name']; ?>"
                                                            data-product="<?php echo $nozzle['product_id']; ?>"
                                                            data-product-name="<?php echo $tank['product_name']; ?>"
                                                            data-price="<?php echo $tank['unit_price']; ?>"
                                                            data-stock="<?php echo $stock; ?>"
                                                            <?php echo $stock <= 0 ? 'disabled' : ''; ?>>
                                                        <i class="fas fa-oil-can"></i><br>
                                                        <strong><?php echo htmlspecialchars($nozzle['nozzle_name']); ?></strong><br>
                                                        <small><?php echo $tank['product_name']; ?> @ <?php echo $currency; ?> <?php echo number_format($tank['unit_price'], 2); ?>/L</small>
                                                        <span class="nozzle-stock">
                                                            <i class="fas fa-warehouse"></i> Tank Stock: <?php echo number_format($stock, 0); ?> L
                                                        </span>
                                                    </button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if($stock <= 0): ?>
                                            <div class="alert alert-danger text-center mb-0 mt-2" style="padding: 5px; font-size: 12px;">
                                                <i class="fas fa-exclamation-triangle"></i> OUT OF STOCK - Please receive fuel
                                            </div>
                                        <?php elseif($stock < 500): ?>
                                            <div class="alert alert-warning text-center mb-0 mt-2" style="padding: 5px; font-size: 12px;">
                                                <i class="fas fa-exclamation-triangle"></i> LOW STOCK ALERT: Only <?php echo number_format($stock, 0); ?> Liters remaining
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if(empty($tanks_with_nozzles)): ?>
                                <div class="alert alert-info text-center">
                                    <i class="fas fa-info-circle"></i> No tanks or nozzles configured. Please add tanks and connect nozzles in settings.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update current time display
        function updateCurrentTime() {
            const now = new Date();
            const hours = now.getHours().toString().padStart(2, '0');
            const minutes = now.getMinutes().toString().padStart(2, '0');
            const seconds = now.getSeconds().toString().padStart(2, '0');
            const timeString = hours + ':' + minutes + ':' + seconds;
            const timeDisplay = document.getElementById('currentTimeDisplay');
            if (timeDisplay) {
                timeDisplay.innerHTML = '<i class="fas fa-clock"></i> ' + timeString;
            }
        }
        updateCurrentTime();
        setInterval(updateCurrentTime, 1000);

        // Nozzle selection
        document.getElementById('nozzle_id').addEventListener('change', function() {
            let option = this.options[this.selectedIndex];
            document.getElementById('product_name').value = option.getAttribute('data-product-name');
            document.getElementById('product_id').value = option.getAttribute('data-product');
            document.getElementById('unit_price').value = option.getAttribute('data-price');
            document.getElementById('tank_id').value = option.getAttribute('data-tank-id');
            
            // Show tank info
            let tankName = option.getAttribute('data-tank-name');
            let tankStock = option.getAttribute('data-stock');
            document.getElementById('display_tank_name').innerText = tankName;
            document.getElementById('display_tank_stock').innerText = parseFloat(tankStock).toFixed(2);
            document.getElementById('tank_info').style.display = 'block';
            
            calculateTotal();
        });

        // Quick nozzle selection (from tank cards)
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
                
                // Show tank info
                document.getElementById('display_tank_name').innerText = this.getAttribute('data-tank-name');
                document.getElementById('display_tank_stock').innerText = parseFloat(this.getAttribute('data-stock')).toFixed(2);
                document.getElementById('tank_info').style.display = 'block';
                
                document.querySelectorAll('.quick-nozzle').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                document.getElementById('quantity').focus();
                calculateTotal();
            });
        });

        document.getElementById('quantity').addEventListener('input', calculateTotal);
        document.getElementById('unit_price').addEventListener('input', calculateTotal);
        document.getElementById('received').addEventListener('input', calculateChange);

        document.getElementById('sale_type').addEventListener('change', function() {
            if(this.value == 'credit') {
                document.getElementById('customer_fields').style.display = 'block';
                document.getElementById('cash_fields').style.display = 'none';
                document.getElementById('customer_name').setAttribute('required', 'required');
            } else {
                document.getElementById('customer_fields').style.display = 'none';
                document.getElementById('cash_fields').style.display = 'block';
                document.getElementById('customer_name').removeAttribute('required');
                document.getElementById('received').value = 0;
            }
        });

        function calculateTotal() {
            let qty = parseFloat(document.getElementById('quantity').value) || 0;
            let price = parseFloat(document.getElementById('unit_price').value) || 0;
            let total = qty * price;
            document.getElementById('total_amount').innerText = total.toFixed(2);
            calculateChange();
        }

        function calculateChange() {
            let total = parseFloat(document.getElementById('total_amount').innerText) || 0;
            let received = parseFloat(document.getElementById('received').value) || 0;
            let change = received - total;
            document.getElementById('change_amount').value = change.toFixed(2);
        }

        document.getElementById('saleForm').addEventListener('submit', function(e) {
            let nozzle = document.getElementById('nozzle_id').value;
            let quantity = document.getElementById('quantity').value;
            let saleType = document.getElementById('sale_type').value;
            let tankStock = parseFloat(document.getElementById('display_tank_stock').innerText) || 0;
            
            if(!nozzle) { 
                e.preventDefault(); 
                alert('Please select a nozzle'); 
                return false; 
            }
            if(!quantity || quantity <= 0) { 
                e.preventDefault(); 
                alert('Please enter valid quantity'); 
                return false; 
            }
            
            if(parseFloat(quantity) > tankStock) {
                e.preventDefault();
                alert('Insufficient stock in tank! Available: ' + tankStock.toFixed(2) + ' Liters');
                return false;
            }
            
            if(saleType == 'credit') {
                let customerName = document.getElementById('customer_name').value;
                if(!customerName) { 
                    e.preventDefault(); 
                    alert('Please enter customer name for credit sale'); 
                    return false; 
                }
            }
        });
    </script>
</body>
</html>
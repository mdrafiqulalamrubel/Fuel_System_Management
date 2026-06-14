<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'receiving';

// Date range filters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

$products = $pdo->query("SELECT * FROM fuel_products WHERE is_active = 1")->fetchAll();
$tanks = $pdo->query("SELECT t.*, p.product_name FROM tanks t JOIN fuel_products p ON t.product_id = p.id")->fetchAll();
$suppliers = $pdo->query("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY supplier_name")->fetchAll();

// Get Chart of Accounts IDs
$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '2000' LIMIT 1");
$ap_account = $stmt->fetch(); // Accounts Payable

$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1000' LIMIT 1");
$cash_account = $stmt->fetch();

$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '5000' LIMIT 1");
$purchase_account = $stmt->fetch();

// Process Add/Update Supplier
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_supplier'])) {
    $supplier_id = isset($_POST['supplier_id']) ? $_POST['supplier_id'] : null;
    $supplier_code = $_POST['supplier_code'];
    $supplier_name = $_POST['supplier_name'];
    $company_name = $_POST['company_name'];
    $phone = $_POST['phone'];
    $email = $_POST['email'];
    $address = $_POST['address'];
    $contact_person = $_POST['contact_person'];
    $opening_balance = $_POST['opening_balance'];
    $credit_limit = $_POST['credit_limit'];
    $payment_terms = $_POST['payment_terms'];
    
    try {
        if($supplier_id) {
            $stmt = $pdo->prepare("UPDATE suppliers SET supplier_code = ?, supplier_name = ?, company_name = ?, phone = ?, email = ?, address = ?, contact_person = ?, credit_limit = ?, payment_terms = ? WHERE id = ?");
            $stmt->execute([$supplier_code, $supplier_name, $company_name, $phone, $email, $address, $contact_person, $credit_limit, $payment_terms, $supplier_id]);
            $success = "Supplier updated successfully!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO suppliers (supplier_code, supplier_name, company_name, phone, email, address, contact_person, opening_balance, current_balance, credit_limit, payment_terms, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$supplier_code, $supplier_name, $company_name, $phone, $email, $address, $contact_person, $opening_balance, $opening_balance, $credit_limit, $payment_terms]);
            $success = "Supplier added successfully!";
        }
    } catch(Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Process Fuel Receiving with Credit
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['receive_fuel'])) {
    $receipt_no = 'RCV-' . date('YmdHis');
    $receipt_date = $_POST['receipt_date'];
    $supplier_id = $_POST['supplier_id'];
    $tanker_no = $_POST['tanker_no'];
    $challan_no = $_POST['challan_no'];
    $product_id = $_POST['product_id'];
    $tank_id = $_POST['tank_id'];
    $expected_quantity = $_POST['expected_quantity'];
    $actual_quantity = $_POST['actual_quantity'];
    $freight_cost = $_POST['freight_cost'];
    $freight_deduction = $_POST['freight_deduction'];
    $unit_price = $_POST['unit_price'];
    $payment_type = $_POST['payment_type']; // cash or credit
    
    $shortage = $expected_quantity - $actual_quantity;
    $total_amount = $actual_quantity * $unit_price;
    $paid_amount = ($payment_type == 'cash') ? $total_amount : 0;
    $due_amount = ($payment_type == 'credit') ? $total_amount : 0;
    $payment_status = ($payment_type == 'cash') ? 'paid' : 'pending';
    
    // Get supplier info
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([$supplier_id]);
    $supplier = $stmt->fetch();
    
    try {
        $pdo->beginTransaction();
        
        // Insert fuel receiving
        $stmt = $pdo->prepare("INSERT INTO fuel_receivings (receipt_no, receipt_date, supplier_id, supplier_name, tanker_no, challan_no, product_id, tank_id, expected_quantity, actual_quantity, shortage, freight_cost, freight_deduction, unit_price, total_amount, payment_status, paid_amount, due_amount, status, approved_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?)");
        $stmt->execute([$receipt_no, $receipt_date, $supplier_id, $supplier['supplier_name'], $tanker_no, $challan_no, $product_id, $tank_id, $expected_quantity, $actual_quantity, $shortage, $freight_cost, $freight_deduction, $unit_price, $total_amount, $payment_status, $paid_amount, $due_amount, $user['id']]);
        $receiving_id = $pdo->lastInsertId();
        
        // Update tank stock
        $stmt = $pdo->prepare("UPDATE tanks SET current_stock_liters = current_stock_liters + ? WHERE id = ?");
        $stmt->execute([$actual_quantity, $tank_id]);
        
        // Update supplier balance if credit
        if($payment_type == 'credit') {
            $stmt = $pdo->prepare("UPDATE suppliers SET current_balance = current_balance + ? WHERE id = ?");
            $stmt->execute([$total_amount, $supplier_id]);
        }
        
        // Get current stock for ledger
        $stmt = $pdo->prepare("SELECT current_stock_liters FROM tanks WHERE id = ?");
        $stmt->execute([$tank_id]);
        $current_stock = $stmt->fetch()['current_stock_liters'];
        
        // Stock ledger entry
        $stmt = $pdo->prepare("INSERT INTO stock_ledger (product_id, tank_id, transaction_type, reference_no, in_quantity, balance_quantity, unit_cost) VALUES (?, ?, 'receiving', ?, ?, ?, ?)");
        $stmt->execute([$product_id, $tank_id, $receipt_no, $actual_quantity, $current_stock, $unit_price]);
        
        // Accounting entries
        $voucher_no = 'PURCH-' . date('YmdHis');
        
        if($payment_type == 'cash') {
            // Cash purchase - Debit Purchase, Credit Cash
            $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'payment', ?, ?, ?, 'approved')");
            $stmt->execute([$voucher_no, $receipt_date, "Fuel purchase from {$supplier['supplier_name']} - $receipt_no (Cash)", $user['id']]);
            $voucher_id = $pdo->lastInsertId();
            
            if($purchase_account && $cash_account) {
                $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                    (?, ?, ?, ?, ?),
                    (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $voucher_id, $purchase_account['id'], $total_amount, 0, "Fuel purchase - $receipt_no",
                    $voucher_id, $cash_account['id'], 0, $total_amount, "Cash payment to {$supplier['supplier_name']}"
                ]);
            }
        } else {
            // Credit purchase - Debit Purchase, Credit Accounts Payable
            $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'journal', ?, ?, ?, 'approved')");
            $stmt->execute([$voucher_no, $receipt_date, "Credit purchase from {$supplier['supplier_name']} - $receipt_no", $user['id']]);
            $voucher_id = $pdo->lastInsertId();
            
            if($purchase_account && $ap_account) {
                $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                    (?, ?, ?, ?, ?),
                    (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $voucher_id, $purchase_account['id'], $total_amount, 0, "Fuel purchase - $receipt_no",
                    $voucher_id, $ap_account['id'], 0, $total_amount, "Accounts Payable to {$supplier['supplier_name']}"
                ]);
            }
        }
        
        $pdo->commit();
        
        $_SESSION['last_receiving'] = [
            'receipt_no' => $receipt_no,
            'receipt_date' => $receipt_date,
            'supplier_name' => $supplier['supplier_name'],
            'tanker_no' => $tanker_no,
            'challan_no' => $challan_no,
            'product_name' => $products[array_search($product_id, array_column($products, 'id'))]['product_name'] ?? '',
            'tank_name' => $tanks[array_search($tank_id, array_column($tanks, 'id'))]['tank_name'] ?? '',
            'expected_quantity' => $expected_quantity,
            'actual_quantity' => $actual_quantity,
            'shortage' => $shortage,
            'freight_cost' => $freight_cost,
            'freight_deduction' => $freight_deduction,
            'unit_price' => $unit_price,
            'total_amount' => $total_amount,
            'payment_type' => $payment_type,
            'due_amount' => $due_amount
        ];
        
        $success = "Fuel received successfully! Receipt: $receipt_no";
        echo "<script>window.open('print_receiving.php', '_blank'); setTimeout(function(){ window.location.href='fuel_receiving.php?tab=receiving'; }, 1000);</script>";
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Process Supplier Payment
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['make_payment'])) {
    $supplier_id = $_POST['supplier_id'];
    $payment_date = $_POST['payment_date'];
    $amount = $_POST['amount'];
    $payment_method = $_POST['payment_method'];
    $reference_no = $_POST['reference_no'];
    $notes = $_POST['notes'];
    $receiving_id = $_POST['receiving_id'] ?? null;
    
    try {
        $pdo->beginTransaction();
        
        // Get supplier info
        $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
        $stmt->execute([$supplier_id]);
        $supplier = $stmt->fetch();
        
        // Insert payment
        $stmt = $pdo->prepare("INSERT INTO supplier_payments (supplier_id, payment_date, amount, payment_method, reference_no, receiving_id, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$supplier_id, $payment_date, $amount, $payment_method, $reference_no, $receiving_id, $notes, $user['id']]);
        
        // Update supplier balance
        $stmt = $pdo->prepare("UPDATE suppliers SET current_balance = current_balance - ? WHERE id = ?");
        $stmt->execute([$amount, $supplier_id]);
        
        // Update receiving payment status if linked
        if($receiving_id) {
            $stmt = $pdo->prepare("SELECT paid_amount, total_amount FROM fuel_receivings WHERE id = ?");
            $stmt->execute([$receiving_id]);
            $receiving = $stmt->fetch();
            $new_paid = $receiving['paid_amount'] + $amount;
            $new_status = ($new_paid >= $receiving['total_amount']) ? 'paid' : 'partial';
            
            $stmt = $pdo->prepare("UPDATE fuel_receivings SET paid_amount = ?, due_amount = total_amount - ?, payment_status = ? WHERE id = ?");
            $stmt->execute([$new_paid, $new_paid, $new_status, $receiving_id]);
        }
        
        // Accounting entry
        $voucher_no = 'SUPPAY-' . date('YmdHis');
        $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'payment', ?, ?, ?, 'approved')");
        $stmt->execute([$voucher_no, $payment_date, "Payment to supplier: {$supplier['supplier_name']} - Amount: $amount", $user['id']]);
        $voucher_id = $pdo->lastInsertId();
        
        if($ap_account && $cash_account) {
            $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                (?, ?, ?, ?, ?),
                (?, ?, ?, ?, ?)");
            $stmt->execute([
                $voucher_id, $ap_account['id'], $amount, 0, "Payment to {$supplier['supplier_name']}",
                $voucher_id, $cash_account['id'], 0, $amount, "Cash payment to supplier"
            ]);
        }
        
        $pdo->commit();
        $success = "Payment of $currency " . number_format($amount, 2) . " made to {$supplier['supplier_name']} successfully!";
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Get receivings with date filter
$stmt = $pdo->prepare("
    SELECT fr.*, p.product_name, t.tank_name, s.supplier_name as supplier_name 
    FROM fuel_receivings fr 
    JOIN fuel_products p ON fr.product_id = p.id 
    JOIN tanks t ON fr.tank_id = t.id 
    LEFT JOIN suppliers s ON fr.supplier_id = s.id
    WHERE DATE(fr.receipt_date) BETWEEN ? AND ?
    ORDER BY fr.receipt_date DESC
");
$stmt->execute([$from_date, $to_date]);
$receivings = $stmt->fetchAll();

// Get summary
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(actual_quantity), 0) as total_liters,
        COALESCE(SUM(total_amount), 0) as total_amount,
        COUNT(*) as total_receipts,
        COALESCE(SUM(shortage), 0) as total_shortage,
        COALESCE(SUM(CASE WHEN payment_status = 'pending' THEN due_amount ELSE 0 END), 0) as total_due
    FROM fuel_receivings 
    WHERE DATE(receipt_date) BETWEEN ? AND ?
");
$stmt->execute([$from_date, $to_date]);
$summary = $stmt->fetch();

// Get supplier summary
$supplier_summary = $pdo->query("
    SELECT 
        COUNT(*) as total_suppliers,
        COALESCE(SUM(current_balance), 0) as total_due,
        COALESCE(SUM(credit_limit), 0) as total_credit_limit
    FROM suppliers WHERE is_active = 1
")->fetch();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Receiving & Supplier Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
        .btn-excel { background: #28a745; color: white; }
        .btn-excel:hover { background: #1e7e34; color: white; }
        .nav-tabs { border-bottom: 2px solid #e0e0e0; margin-bottom: 20px; }
        .nav-tabs .nav-link { color: #6c757d; font-weight: 500; padding: 12px 20px; border: none; border-bottom: 2px solid transparent; }
        .nav-tabs .nav-link:hover { color: #667eea; border-bottom-color: #667eea; }
        .nav-tabs .nav-link.active { color: #667eea; border-bottom: 2px solid #667eea; font-weight: 600; }
        .badge-pending { background: #ffc107; color: #856404; }
        .badge-paid { background: #28a745; color: white; }
        .badge-partial { background: #17a2b8; color: white; }
        @media print {
            .sidebar, .no-print, .stats-card, .card-header .btn, 
            .dataTables_length, .dataTables_filter, .dataTables_paginate,
            form, .col-md-5, .btn, .nav-tabs { display: none !important; }
            .col-md-7, .col-md-12 { width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .main-content { margin: 0 !important; padding: 10px !important; }
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
                <h2><i class="fas fa-truck"></i> Fuel Receiving & Supplier Management</h2>
                <div class="no-print">
                    <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Print</button>
                    <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
                </div>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <!-- Tabs -->
            <ul class="nav nav-tabs no-print">
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'receiving' ? 'active' : ''; ?>" href="?tab=receiving">
                        <i class="fas fa-oil-can"></i> Receive Fuel
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'suppliers' ? 'active' : ''; ?>" href="?tab=suppliers">
                        <i class="fas fa-building"></i> Suppliers
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'payments' ? 'active' : ''; ?>" href="?tab=payments">
                        <i class="fas fa-money-bill-wave"></i> Supplier Payments
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'reports' ? 'active' : ''; ?>" href="?tab=reports">
                        <i class="fas fa-chart-bar"></i> Reports
                    </a>
                </li>
            </ul>
            
            <!-- Receive Fuel Tab -->
            <?php if($active_tab == 'receiving'): ?>
            <div class="row mt-3">
                <div class="col-md-5 no-print">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-plus-circle"></i> Receive Fuel</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="row">
                                    <div class="col-md-6">
                                        <label>Date *</label>
                                        <input type="date" name="receipt_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label>Supplier *</label>
                                        <select name="supplier_id" class="form-control" required>
                                            <option value="">-- Select Supplier --</option>
                                            <?php foreach($suppliers as $sup): ?>
                                                <option value="<?php echo $sup['id']; ?>">
                                                    <?php echo htmlspecialchars($sup['supplier_name']); ?> 
                                                    (Due: <?php echo $currency; ?> <?php echo number_format($sup['current_balance'], 2); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-md-6">
                                        <label>Tanker No</label>
                                        <input type="text" name="tanker_no" class="form-control">
                                    </div>
                                    <div class="col-md-6">
                                        <label>Challan No</label>
                                        <input type="text" name="challan_no" class="form-control">
                                    </div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-md-6">
                                        <label>Product *</label>
                                        <select name="product_id" id="product_id" class="form-control" required>
                                            <option value="">Select</option>
                                            <?php foreach($products as $p): ?>
                                                <option value="<?php echo $p['id']; ?>"><?php echo $p['product_name']; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label>Tank *</label>
                                        <select name="tank_id" id="tank_id" class="form-control" required>
                                            <option value="">Select</option>
                                            <?php foreach($tanks as $t): ?>
                                                <option value="<?php echo $t['id']; ?>"><?php echo $t['tank_name']; ?> (<?php echo $t['product_name']; ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-md-6">
                                        <label>Expected (L) *</label>
                                        <input type="number" name="expected_quantity" id="exp_qty" class="form-control" step="0.01" required oninput="calcShortage()">
                                    </div>
                                    <div class="col-md-6">
                                        <label>Actual (L) *</label>
                                        <input type="number" name="actual_quantity" id="act_qty" class="form-control" step="0.01" required oninput="calcShortage()">
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <label>Shortage (L)</label>
                                    <input type="text" id="shortage" class="form-control" readonly>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-md-6">
                                        <label>Freight Cost</label>
                                        <input type="number" name="freight_cost" class="form-control" step="0.01" value="0">
                                    </div>
                                    <div class="col-md-6">
                                        <label>Freight Deduction</label>
                                        <input type="number" name="freight_deduction" class="form-control" step="0.01" value="0">
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <label>Unit Price (<?php echo $currency; ?>/L) *</label>
                                    <input type="number" name="unit_price" id="unit_price" class="form-control" step="0.01" required oninput="calcTotal()">
                                </div>
                                <div class="mt-2">
                                    <label>Total Amount (<?php echo $currency; ?>)</label>
                                    <input type="text" id="total_amt" class="form-control" readonly>
                                </div>
                                <div class="mt-2">
                                    <label>Payment Type *</label>
                                    <select name="payment_type" id="payment_type" class="form-control" required>
                                        <option value="cash">Cash Purchase</option>
                                        <option value="credit">Credit Purchase</option>
                                    </select>
                                </div>
                                <button type="submit" name="receive_fuel" class="btn btn-primary w-100 mt-3">
                                    <i class="fas fa-save"></i> Receive Fuel
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-7">
                    <div class="card mb-3 no-print">
                        <div class="card-header bg-info text-white">
                            <h5><i class="fas fa-calendar-alt"></i> Filter by Date</h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <input type="hidden" name="tab" value="receiving">
                                <div class="col-md-5">
                                    <label>From Date</label>
                                    <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>">
                                </div>
                                <div class="col-md-5">
                                    <label>To Date</label>
                                    <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>">
                                </div>
                                <div class="col-md-2">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-info w-100"><i class="fas fa-search"></i> Filter</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="fas fa-history"></i> Receiving History</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="historyTable">
                                    <thead class="table-dark">
                                        <tr><th>Date</th><th>Receipt No</th><th>Supplier</th><th>Product</th>
                                        <th class="text-end">Actual (L)</th><th class="text-end">Shortage</th>
                                        <th class="text-end">Amount</th><th>Status</th><th class="no-print">Actions</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($receivings as $r): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($r['receipt_date'])); ?></td>
                                            <td><?php echo $r['receipt_no']; ?></td>
                                            <td><?php echo htmlspecialchars($r['supplier_name']); ?></td>
                                            <td><?php echo $r['product_name']; ?></td>
                                            <td class="text-end"><?php echo number_format($r['actual_quantity'], 2); ?> L</td>
                                            <td class="text-end text-danger"><?php echo number_format($r['shortage'], 2); ?> L</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($r['total_amount'], 2); ?></td>
                                            <td class="text-center">
                                                <?php if($r['payment_status'] == 'paid'): ?>
                                                    <span class="badge bg-success">Paid</span>
                                                <?php elseif($r['payment_status'] == 'partial'): ?>
                                                    <span class="badge bg-info">Partial</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="no-print">
                                                <button class="btn btn-sm btn-info print-single" data-receipt="<?php echo $r['receipt_no']; ?>">
                                                    <i class="fas fa-print"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Suppliers Tab -->
            <?php if($active_tab == 'suppliers'): ?>
            <div class="row mt-3">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-plus"></i> Add/Edit Supplier</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-2">
                                    <label>Supplier Code *</label>
                                    <input type="text" name="supplier_code" class="form-control" placeholder="SUP-001" required>
                                </div>
                                <div class="mb-2">
                                    <label>Supplier Name *</label>
                                    <input type="text" name="supplier_name" class="form-control" required>
                                </div>
                                <div class="mb-2">
                                    <label>Company Name</label>
                                    <input type="text" name="company_name" class="form-control">
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <label>Phone</label>
                                        <input type="text" name="phone" class="form-control">
                                    </div>
                                    <div class="col-md-6">
                                        <label>Email</label>
                                        <input type="email" name="email" class="form-control">
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label>Address</label>
                                    <textarea name="address" class="form-control" rows="2"></textarea>
                                </div>
                                <div class="mb-2">
                                    <label>Contact Person</label>
                                    <input type="text" name="contact_person" class="form-control">
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <label>Opening Balance</label>
                                        <input type="number" name="opening_balance" class="form-control" step="0.01" value="0">
                                    </div>
                                    <div class="col-md-6">
                                        <label>Credit Limit</label>
                                        <input type="number" name="credit_limit" class="form-control" step="0.01" value="0">
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label>Payment Terms (Days)</label>
                                    <input type="number" name="payment_terms" class="form-control" value="30">
                                </div>
                                <button type="submit" name="save_supplier" class="btn btn-primary w-100 mt-2">
                                    <i class="fas fa-save"></i> Save Supplier
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="fas fa-building"></i> Supplier List</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="suppliersTable">
                                    <thead class="table-dark">
                                        <tr><th>Code</th><th>Name</th><th>Phone</th><th>Credit Limit</th>
                                        <th class="text-end">Current Due</th><th>Status</th><th>Actions</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $supplier_list = $pdo->query("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY supplier_name")->fetchAll();
                                        foreach($supplier_list as $sup): 
                                        ?>
                                        <tr>
                                            <td><?php echo $sup['supplier_code']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($sup['supplier_name']); ?></strong><br>
                                                <small><?php echo htmlspecialchars($sup['company_name']); ?></small></td>
                                            <td><?php echo $sup['phone']; ?></td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sup['credit_limit'], 2); ?></td>
                                            <td class="text-end fw-bold <?php echo $sup['current_balance'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                                <?php echo $currency; ?> <?php echo number_format($sup['current_balance'], 2); ?>
                                            </div>
                                            <td>
                                                <?php if($sup['is_active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </div>
                                            <td class="no-print">
                                                <button class="btn btn-sm btn-warning" onclick="editSupplier(<?php echo $sup['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                             </div>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="4" class="text-end">TOTAL DUE:   </div>
                                            <td class="text-end text-danger">
                                                <?php 
                                                $total_due = array_sum(array_column($supplier_list, 'current_balance'));
                                                echo $currency . ' ' . number_format($total_due, 2); 
                                                ?>
                                             </div>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Supplier Payments Tab -->
            <?php if($active_tab == 'payments'): ?>
            <div class="row mt-3">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-money-bill-wave"></i> Make Payment</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-2">
                                    <label>Supplier *</label>
                                    <select name="supplier_id" class="form-control" required>
                                        <option value="">Select Supplier</option>
                                        <?php foreach($supplier_list as $sup): ?>
                                            <option value="<?php echo $sup['id']; ?>">
                                                <?php echo htmlspecialchars($sup['supplier_name']); ?> 
                                                (Due: <?php echo $currency; ?> <?php echo number_format($sup['current_balance'], 2); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label>Payment Date</label>
                                    <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="mb-2">
                                    <label>Amount *</label>
                                    <input type="number" name="amount" class="form-control" step="0.01" required>
                                </div>
                                <div class="mb-2">
                                    <label>Payment Method</label>
                                    <select name="payment_method" class="form-control">
                                        <option value="cash">Cash</option>
                                        <option value="bank">Bank Transfer</option>
                                        <option value="cheque">Cheque</option>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label>Reference No</label>
                                    <input type="text" name="reference_no" class="form-control" placeholder="Cheque No / Transaction ID">
                                </div>
                                <div class="mb-2">
                                    <label>Notes</label>
                                    <textarea name="notes" class="form-control" rows="2"></textarea>
                                </div>
                                <button type="submit" name="make_payment" class="btn btn-primary w-100">
                                    <i class="fas fa-save"></i> Make Payment
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5><i class="fas fa-history"></i> Payment History</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="paymentsTable">
                                    <thead class="table-dark">
                                        <tr><th>Date</th><th>Supplier</th><th>Amount</th><th>Method</th><th>Reference</th><th>Notes</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $payments = $pdo->query("
                                            SELECT sp.*, s.supplier_name 
                                            FROM supplier_payments sp 
                                            JOIN suppliers s ON sp.supplier_id = s.id 
                                            ORDER BY sp.payment_date DESC LIMIT 100
                                        ")->fetchAll();
                                        foreach($payments as $pay): 
                                        ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($pay['payment_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($pay['supplier_name']); ?></td>
                                            <td class="text-end text-danger"><?php echo $currency; ?> <?php echo number_format($pay['amount'], 2); ?></td>
                                            <td><?php echo ucfirst($pay['payment_method']); ?></div>
                                            <td><?php echo $pay['reference_no']; ?></td>
                                            <td><?php echo htmlspecialchars(substr($pay['notes'], 0, 30)); ?>...</div>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="2" class="text-end">TOTAL PAYMENTS:   </div>
                                            <td class="text-end text-danger">
                                                <?php 
                                                $total_payments = array_sum(array_column($payments, 'amount'));
                                                echo $currency . ' ' . number_format($total_payments, 2); 
                                                ?>
                                             </div>
                                            <td colspan="3"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
    <!-- Reports Tab -->
    <?php if($active_tab == 'reports'): ?>

    <!-- Supplier Summary Section -->
    <div class="card mt-3">
        <div class="card-header bg-primary text-white">
            <h5><i class="fas fa-chart-line"></i> Supplier Summary</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 text-center mb-3">
                    <div class="border rounded p-3 bg-light">
                        <i class="fas fa-building fa-2x text-primary mb-2"></i>
                        <h3 class="mb-0"><?php echo $supplier_summary['total_suppliers']; ?></h3>
                        <small class="text-muted">Total Suppliers</small>
                    </div>
                </div>
                <div class="col-md-3 text-center mb-3">
                    <div class="border rounded p-3 bg-light">
                        <i class="fas fa-money-bill-wave fa-2x text-danger mb-2"></i>
                        <h3 class="mb-0 text-danger"><?php echo $currency; ?> <?php echo number_format($supplier_summary['total_due'], 2); ?></h3>
                        <small class="text-muted">Total Due</small>
                    </div>
                </div>
                <div class="col-md-3 text-center mb-3">
                    <div class="border rounded p-3 bg-light">
                        <i class="fas fa-chart-line fa-2x text-warning mb-2"></i>
                        <h3 class="mb-0"><?php echo number_format($summary['total_shortage'], 2); ?> L</h3>
                        <small class="text-muted">Total Shortage</small>
                    </div>
                </div>
                <div class="col-md-3 text-center mb-3">
                    <div class="border rounded p-3 bg-light">
                        <i class="fas fa-credit-card fa-2x text-success mb-2"></i>
                        <h3 class="mb-0 text-success"><?php echo $currency; ?> <?php echo number_format($supplier_summary['total_credit_limit'], 2); ?></h3>
                        <small class="text-muted">Total Credit Limit</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Receiving Summary Section -->
    <div class="card mt-3">
        <div class="card-header bg-success text-white">
            <h5><i class="fas fa-oil-can"></i> Receiving Summary (<?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?>)</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 text-center mb-3">
                    <div class="border rounded p-3 bg-info text-white">
                        <i class="fas fa-tachometer-alt fa-2x mb-2"></i>
                                        <h3 class="mb-0"><?php echo number_format($summary['total_liters'], 2); ?> L</h3>
                        <small>Total Liters Received</small>
                    </div>
                </div>
                <div class="col-md-3 text-center mb-3">
                    <div class="border rounded p-3 bg-warning">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                        <h3 class="mb-0"><?php echo number_format($summary['total_shortage'], 2); ?> L</h3>
                        <small>Total Shortage</small>
                    </div>
                </div>
                <div class="col-md-3 text-center mb-3">
                    <div class="border rounded p-3 bg-primary text-white">
                        <i class="fas fa-dollar-sign fa-2x mb-2"></i>
                        <h3 class="mb-0"><?php echo $currency; ?> <?php echo number_format($summary['total_amount'], 2); ?></h3>
                        <small>Total Amount</small>
                    </div>
                </div>
                <div class="col-md-3 text-center mb-3">
                    <div class="border rounded p-3 bg-danger text-white">
                        <i class="fas fa-clock fa-2x mb-2"></i>
                        <h3 class="mb-0"><?php echo $currency; ?> <?php echo number_format($summary['total_due'], 2); ?></h3>
                        <small>Outstanding Due</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Supplier-wise Due Report -->
    <div class="card mt-3">
        <div class="card-header bg-dark text-white">
            <h5><i class="fas fa-list"></i> Supplier-wise Due Report</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="supplierDueTable">
                    <thead class="table-dark">
                        <tr>
                            <th>SL</th>
                            <th>Supplier Code</th>
                            <th>Supplier Name</th>
                            <th>Company</th>
                            <th>Phone</th>
                            <th class="text-end">Credit Limit</th>
                            <th class="text-end">Current Due</th>
                            <th>Payment Terms</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $supplier_due_list = $pdo->query("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY current_balance DESC")->fetchAll();
                        $sl = 1;
                        foreach($supplier_due_list as $sup): 
                        ?>
                        <tr>
                            <td class="text-center"><?php echo $sl++; ?></td>
                            <td><?php echo $sup['supplier_code']; ?></td>
                            <td><strong><?php echo htmlspecialchars($sup['supplier_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($sup['company_name']); ?></td>
                            <td><?php echo $sup['phone']; ?></td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sup['credit_limit'], 2); ?></td>
                            <td class="text-end fw-bold <?php echo $sup['current_balance'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                <?php echo $currency; ?> <?php echo number_format($sup['current_balance'], 2); ?>
                            </td>
                            <td class="text-center"><?php echo $sup['payment_terms']; ?> days</td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($supplier_due_list)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">No suppliers found</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold">
                            <td colspan="6" class="text-end">TOTAL DUE:</td>
                            <td class="text-end text-danger">
                                <?php 
                                $total_due_sum = array_sum(array_column($supplier_due_list, 'current_balance'));
                                echo $currency . ' ' . number_format($total_due_sum, 2); 
                                ?>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Receivings Summary -->
    <div class="card mt-3">
        <div class="card-header bg-info text-white">
            <h5><i class="fas fa-history"></i> Recent Receiving History</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="recentReceivingsTable">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th>
                            <th>Receipt No</th>
                            <th>Supplier</th>
                            <th>Product</th>
                            <th class="text-end">Quantity (L)</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Total Amount</th>
                            <th>Payment Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $recent_receivings = $pdo->query("
                            SELECT fr.*, p.product_name, s.supplier_name 
                            FROM fuel_receivings fr 
                            JOIN fuel_products p ON fr.product_id = p.id 
                            LEFT JOIN suppliers s ON fr.supplier_id = s.id 
                            ORDER BY fr.receipt_date DESC 
                            LIMIT 20
                        ")->fetchAll();
                        foreach($recent_receivings as $r): 
                        ?>
                        <tr>
                            <td><?php echo date('d-m-Y', strtotime($r['receipt_date'])); ?></td>
                            <td><?php echo $r['receipt_no']; ?></td>
                            <td><?php echo htmlspecialchars($r['supplier_name']); ?></td>
                            <td><?php echo $r['product_name']; ?></td>
                            <td class="text-end"><?php echo number_format($r['actual_quantity'], 2); ?> L</td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($r['unit_price'], 2); ?></td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($r['total_amount'], 2); ?></td>
                            <td class="text-center">
                                <?php if($r['payment_status'] == 'paid'): ?>
                                    <span class="badge bg-success">Paid</span>
                                <?php elseif($r['payment_status'] == 'partial'): ?>
                                    <span class="badge bg-info">Partial</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Pending</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Monthly Summary Chart -->
    <div class="card mt-3">
        <div class="card-header bg-secondary text-white">
            <h5><i class="fas fa-chart-bar"></i> Monthly Receiving Summary</h5>
        </div>
        <div class="card-body">
            <canvas id="monthlyChart" style="height: 300px;"></canvas>
        </div>
    </div>

    <?php endif; ?>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        $(document).ready(function() {
            $('#historyTable, #suppliersTable, #paymentsTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: { search: "Search:", lengthMenu: "Show _MENU_ entries" }
            });
            
            <?php if($active_tab == 'reports' && !empty($supplier_list)): ?>
            // Supplier Chart
            var ctx = document.getElementById('supplierChart').getContext('2d');
            var supplierNames = <?php 
                $names = array_column($supplier_list, 'supplier_name');
                echo json_encode(array_slice($names, 0, 5));
            ?>;
            var supplierDues = <?php 
                $dues = array_column($supplier_list, 'current_balance');
                echo json_encode(array_slice($dues, 0, 5));
            ?>;
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: supplierNames,
                    datasets: [{
                        label: 'Due Amount (<?php echo $currency; ?>)',
                        data: supplierDues,
                        backgroundColor: 'rgba(220, 53, 69, 0.7)',
                        borderColor: '#dc3545',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: { y: { beginAtZero: true } }
                }
            });
            <?php endif; ?>
        });
        
        function calcShortage() { 
            let exp = parseFloat($('#exp_qty').val()) || 0; 
            let act = parseFloat($('#act_qty').val()) || 0; 
            $('#shortage').val((exp - act).toFixed(2)); 
            calcTotal(); 
        }
        
        function calcTotal() { 
            let act = parseFloat($('#act_qty').val()) || 0; 
            let price = parseFloat($('#unit_price').val()) || 0; 
            $('#total_amt').val((act * price).toFixed(2)); 
        }
        
        $('.print-single').click(function() {
            window.open('print_receiving.php?receipt_no=' + $(this).data('receipt'), '_blank', 'width=600,height=700');
        });
        
        function editSupplier(id) {
            window.location.href = '?tab=suppliers&edit_id=' + id;
        }

        // Initialize additional DataTables
        $('#supplierDueTable, #recentReceivingsTable').DataTable({
            order: [[0, 'asc']],
            pageLength: 10,
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries"
            }
        });

        // Monthly Chart for Receivings
        <?php
        // Get monthly data for chart
        $monthly_data = $pdo->query("
            SELECT 
                DATE_FORMAT(receipt_date, '%Y-%m') as month,
                SUM(actual_quantity) as total_liters,
                SUM(total_amount) as total_amount
            FROM fuel_receivings 
            WHERE receipt_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(receipt_date, '%Y-%m')
            ORDER BY month ASC
        ")->fetchAll();

        $months = [];
        $liters = [];
        $amounts = [];

        foreach($monthly_data as $m) {
            $months[] = date('M Y', strtotime($m['month'] . '-01'));
            $liters[] = floatval($m['total_liters']);
            $amounts[] = floatval($m['total_amount']);
        }
        ?>

        <?php if(!empty($monthly_data)): ?>
        var monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($months); ?>,
                datasets: [
                    {
                        label: 'Liters Received',
                        data: <?php echo json_encode($liters); ?>,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        fill: true,
                        tension: 0.4,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Amount (<?php echo $currency; ?>)',
                        data: <?php echo json_encode($amounts); ?>,
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        fill: true,
                        tension: 0.4,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Last 6 Months Fuel Receiving Trend'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                let value = context.raw;
                                if (context.dataset.label.includes('Liters')) {
                                    return label + ': ' + value.toFixed(2) + ' L';
                                } else {
                                    return label + ': <?php echo $currency; ?> ' + value.toFixed(2);
                                }
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        title: {
                            display: true,
                            text: 'Liters'
                        },
                        beginAtZero: true
                    },
                    y1: {
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Amount (<?php echo $currency; ?>)'
                        },
                        beginAtZero: true,
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
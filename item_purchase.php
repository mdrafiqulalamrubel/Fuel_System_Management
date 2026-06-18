<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'purchase';

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

// =============================================
// PROCESS PURCHASE ORDER
// =============================================
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_purchase'])) {
    $supplier_id = $_POST['supplier_id'] ?? null;
    $supplier_name = $_POST['supplier_name'] ?? '';
    $supplier_phone = $_POST['supplier_phone'] ?? '';
    $purchase_date = $_POST['purchase_date'];
    $invoice_no = $_POST['invoice_no'] ?? '';
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $payment_status = $_POST['payment_status'] ?? 'pending';
    $notes = $_POST['notes'] ?? '';
    $items = $_SESSION['purchase_cart'] ?? [];
    
    if(empty($invoice_no)) {
        $error = "Please enter purchase invoice number!";
    } elseif(empty($items)) {
        $error = "Please add at least one item!";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get or create supplier
            if(!$supplier_id && !empty($supplier_name)) {
                $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE supplier_name = ? OR phone = ?");
                $stmt->execute([$supplier_name, $supplier_phone]);
                $existing = $stmt->fetch();
                if($existing) {
                    $supplier_id = $existing['id'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO suppliers (supplier_code, supplier_name, phone) VALUES (?, ?, ?)");
                    $supplier_code = 'SUP-' . date('Ymd') . rand(100, 999);
                    $stmt->execute([$supplier_code, $supplier_name, $supplier_phone]);
                    $supplier_id = $pdo->lastInsertId();
                }
            }
            
            // Calculate totals
            $subtotal = 0;
            foreach($items as $item) {
                $subtotal += $item['quantity'] * $item['purchase_price'];
            }
            
            $discount = floatval($_POST['discount'] ?? 0);
            $tax = floatval($_POST['tax'] ?? 0);
            $shipping = floatval($_POST['shipping'] ?? 0);
            $total_amount = $subtotal - $discount + $tax + $shipping;
            
            // Insert purchase
            $stmt = $pdo->prepare("
                INSERT INTO item_purchases (
                    purchase_no, purchase_date, supplier_id, supplier_name, supplier_phone,
                    subtotal, discount, tax, shipping, total_amount,
                    payment_method, payment_status, notes, created_by, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')
            ");
            $stmt->execute([
                $invoice_no, $purchase_date, $supplier_id, $supplier_name, $supplier_phone,
                $subtotal, $discount, $tax, $shipping, $total_amount,
                $payment_method, $payment_status, $notes, $user['id']
            ]);
            $purchase_id = $pdo->lastInsertId();
            
            // Insert purchase items and update stock
            foreach($items as $item) {
                $item_id = $item['item_id'];
                $quantity = floatval($item['quantity']);
                $purchase_price = floatval($item['purchase_price']);
                $selling_price = floatval($item['selling_price']);
                $total = $quantity * $purchase_price;
                
                // Insert purchase item
                $stmt = $pdo->prepare("
                    INSERT INTO item_purchase_items (purchase_id, item_id, quantity, purchase_price, selling_price, total)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$purchase_id, $item_id, $quantity, $purchase_price, $selling_price, $total]);
                
                // Update item stock
                $stmt = $pdo->prepare("
                    UPDATE items 
                    SET current_stock = current_stock + ?,
                        purchase_price = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$quantity, $purchase_price, $item_id]);
                
                // Insert into stock ledger
                $stmt = $pdo->prepare("
                    INSERT INTO item_stock_ledger (
                        item_id, transaction_type, reference_no, in_quantity, 
                        unit_cost, balance_quantity, created_by
                    ) VALUES (?, 'purchase', ?, ?, ?, ?, ?)
                ");
                
                // Get new balance
                $stmt2 = $pdo->prepare("SELECT current_stock FROM items WHERE id = ?");
                $stmt2->execute([$item_id]);
                $new_balance = $stmt2->fetch()['current_stock'];
                
                $stmt->execute([
                    $item_id, $invoice_no, $quantity, $purchase_price, $new_balance, $user['id']
                ]);
            }
            
            // =============================================
            // ACCOUNTING ENTRIES - FIXED VOUCHER TYPE
            // =============================================
            
            // Get accounts
            $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1000' LIMIT 1");
            $cash_account = $stmt->fetch();
            
            $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1400' OR account_name LIKE '%Inventory - Items%' LIMIT 1");
            $inventory_account = $stmt->fetch();
            
            if(!$inventory_account) {
                $stmt = $pdo->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, balance_type, is_active) VALUES ('1400', 'Inventory - Items', 'asset', 'debit', 1)");
                $stmt->execute();
                $inventory_id = $pdo->lastInsertId();
            } else {
                $inventory_id = $inventory_account['id'];
            }
            
            $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '2000' OR account_name LIKE '%Accounts Payable%' LIMIT 1");
            $ap_account = $stmt->fetch();
            
            if(!$ap_account) {
                $stmt = $pdo->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, balance_type, is_active) VALUES ('2000', 'Accounts Payable', 'liability', 'credit', 1)");
                $stmt->execute();
                $ap_id = $pdo->lastInsertId();
            } else {
                $ap_id = $ap_account['id'];
            }
            
            // Create voucher - FIXED: Use 'journal' as voucher_type
            $voucher_no = 'PURCHASE-' . date('YmdHis') . rand(100, 999);
            $narration = "Purchase from " . ($supplier_name ?: 'Supplier') . " - Invoice: $invoice_no - Amount: BDT " . number_format($total_amount, 2);
            
            $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'journal', ?, ?, ?, 'approved')");
            $stmt->execute([$voucher_no, $purchase_date, $narration, $user['id']]);
            $voucher_id = $pdo->lastInsertId();
            
            if($payment_method == 'cash' && $payment_status == 'paid') {
                // Dr. Inventory, Cr. Cash
                $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                    (?, ?, ?, ?, ?),
                    (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $voucher_id, $inventory_id, $total_amount, 0, "Inventory purchase - $invoice_no",
                    $voucher_id, $cash_account['id'], 0, $total_amount, "Cash payment for purchase - $invoice_no"
                ]);
            } else {
                // Dr. Inventory, Cr. Accounts Payable
                $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                    (?, ?, ?, ?, ?),
                    (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $voucher_id, $inventory_id, $total_amount, 0, "Inventory purchase - $invoice_no",
                    $voucher_id, $ap_id, 0, $total_amount, "Payable to supplier - $invoice_no"
                ]);
                
                // Update supplier balance
                if($supplier_id) {
                    $stmt = $pdo->prepare("UPDATE suppliers SET current_balance = current_balance + ? WHERE id = ?");
                    $stmt->execute([$total_amount, $supplier_id]);
                }
            }
            
            $pdo->commit();
            
            // Clear session cart
            unset($_SESSION['purchase_cart']);
            
            $success = "✅ Purchase completed successfully!<br>
                        <strong>Invoice:</strong> $invoice_no<br>
                        <strong>Total:</strong> BDT " . number_format($total_amount, 2) . "<br>
                        <strong>Items:</strong> " . count($items);
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    }
}

// =============================================
// ADD TO PURCHASE CART
// =============================================
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_to_cart'])) {
    $item_id = $_POST['item_id'];
    $quantity = floatval($_POST['quantity']);
    $purchase_price = floatval($_POST['purchase_price']);
    $selling_price = floatval($_POST['selling_price']);
    
    if($quantity <= 0) {
        $error = "Please enter valid quantity!";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ? AND is_active = 1");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch();
        
        if($item) {
            $cart_item = [
                'item_id' => $item['id'],
                'item_code' => $item['item_code'],
                'item_name' => $item['item_name'],
                'unit' => $item['unit'],
                'quantity' => $quantity,
                'purchase_price' => $purchase_price,
                'selling_price' => $selling_price,
                'total' => $quantity * $purchase_price
            ];
            
            // Check if item already in cart
            if(!isset($_SESSION['purchase_cart'])) {
                $_SESSION['purchase_cart'] = [];
            }
            
            $found = false;
            foreach($_SESSION['purchase_cart'] as $key => $ci) {
                if($ci['item_id'] == $item_id) {
                    $_SESSION['purchase_cart'][$key]['quantity'] += $quantity;
                    $_SESSION['purchase_cart'][$key]['total'] = $_SESSION['purchase_cart'][$key]['quantity'] * $_SESSION['purchase_cart'][$key]['purchase_price'];
                    $found = true;
                    break;
                }
            }
            
            if(!$found) {
                $_SESSION['purchase_cart'][] = $cart_item;
            }
            
            $success = "Item added to purchase cart!";
        } else {
            $error = "Item not found!";
        }
    }
}

// =============================================
// REMOVE FROM CART
// =============================================
if(isset($_GET['remove_cart'])) {
    $index = intval($_GET['remove_cart']);
    if(isset($_SESSION['purchase_cart'][$index])) {
        unset($_SESSION['purchase_cart'][$index]);
        $_SESSION['purchase_cart'] = array_values($_SESSION['purchase_cart']);
        $success = "Item removed from cart!";
    }
    header("Location: item_purchase.php");
    exit();
}

// =============================================
// CLEAR CART
// =============================================
if(isset($_GET['clear_cart'])) {
    unset($_SESSION['purchase_cart']);
    header("Location: item_purchase.php");
    exit();
}

// =============================================
// GET DATA
// =============================================
$items = $pdo->query("SELECT * FROM items WHERE is_active = 1 ORDER BY item_name")->fetchAll();
$suppliers = $pdo->query("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY supplier_name")->fetchAll();

$purchase_cart = isset($_SESSION['purchase_cart']) ? $_SESSION['purchase_cart'] : [];
$cart_subtotal = array_sum(array_column($purchase_cart, 'total'));
$cart_count = count($purchase_cart);

// Get purchase history
$purchases = $pdo->query("
    SELECT ip.*, s.supplier_name 
    FROM item_purchases ip
    LEFT JOIN suppliers s ON ip.supplier_id = s.id
    ORDER BY ip.created_at DESC
    LIMIT 50
")->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Purchase Management</title>
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
        .stats-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stats-card.orange { background: linear-gradient(135deg, #f6d365 0%, #fda085 100%); }
        .stats-card.blue { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        
        .nav-tabs-custom {
            border-bottom: 2px solid #dee2e6;
            padding: 0;
            margin-bottom: 20px;
            background: #f8f9fa;
            border-radius: 8px 8px 0 0;
        }
        .nav-tabs-custom .nav-link {
            color: #000 !important;
            font-weight: 600;
            padding: 12px 25px;
            border: none;
            border-radius: 8px 8px 0 0;
            background: transparent;
        }
        .nav-tabs-custom .nav-link.active {
            color: #0d6efd !important;
            background: #ffffff;
            border-bottom: 3px solid #0d6efd;
        }
        .tab-content-custom {
            background: white;
            padding: 20px;
            border-radius: 0 0 8px 8px;
            border: 1px solid #dee2e6;
            border-top: none;
            min-height: 500px;
        }
        .cart-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 8px;
        }
        .cart-total {
            background: #e9ecef;
            padding: 15px;
            border-radius: 10px;
            margin-top: 10px;
        }
        .badge-paid { background: #28a745; color: white; }
        .badge-pending { background: #ffc107; color: #856404; }
        .badge-cancelled { background: #dc3545; color: white; }
        .purchase-item-card {
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 8px;
        }
        .purchase-item-card:hover {
            background: #f8f9fa;
            border-color: #667eea;
        }
        
        /* ============================================= */
        /* SPLIT SCREEN - DRAGABLE */
        /* ============================================= */
        .split-container {
            display: flex;
            gap: 0;
            min-height: 600px;
            position: relative;
        }
        .split-left {
            flex: 1;
            min-width: 200px;
            overflow: hidden;
            padding-right: 10px;
        }
        .split-right {
            flex: 1;
            min-width: 200px;
            overflow: hidden;
            padding-left: 10px;
        }
        .gutter {
            flex: 0 0 8px;
            background: #e9ecef;
            cursor: col-resize;
            position: relative;
            transition: background 0.2s;
            border-radius: 4px;
            margin: 0 4px;
        }
        .gutter:hover, .gutter.dragging {
            background: #667eea;
        }
        .gutter::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 4px;
            height: 40px;
            background: #adb5bd;
            border-radius: 2px;
        }
        .gutter:hover::before, .gutter.dragging::before {
            background: white;
        }
        .scroll-items {
            max-height: 550px;
            overflow-y: auto;
            padding-right: 5px;
        }
        .cart-scroll {
            max-height: 350px;
            overflow-y: auto;
            padding-right: 5px;
        }
        .card-body {
            padding: 15px;
        }
        .card-header {
            padding: 10px 15px;
        }
        
        @media (max-width: 768px) {
            .split-container {
                flex-direction: column;
            }
            .gutter {
                flex: 0 0 8px;
                height: 8px;
                cursor: row-resize;
                margin: 4px 0;
                width: 100%;
            }
            .gutter::before {
                width: 40px;
                height: 4px;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
            }
            .split-left, .split-right {
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-truck"></i> Item Purchase Management</h2>
                <div>
                    <a href="item_pos.php" class="btn btn-success">
                        <i class="fas fa-shopping-cart"></i> POS
                    </a>
                    <a href="item_management.php" class="btn btn-info">
                        <i class="fas fa-cog"></i> Manage Items
                    </a>
                    <a href="item_purchase_report.php" class="btn btn-primary">
                        <i class="fas fa-chart-bar"></i> Reports
                    </a>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Statistics -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-truck"></i>
                        <h3><?php echo count($purchases); ?></h3>
                        <p>Total Purchases</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card green">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php 
                            $total_purchase = array_sum(array_column($purchases, 'total_amount'));
                            echo number_format($total_purchase, 2);
                        ?></h3>
                        <p>Total Purchase Value</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card orange">
                        <i class="fas fa-box"></i>
                        <h3><?php echo count($items); ?></h3>
                        <p>Total Items</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card blue">
                        <i class="fas fa-users"></i>
                        <h3><?php echo count($suppliers); ?></h3>
                        <p>Suppliers</p>
                    </div>
                </div>
            </div>
            
            <!-- Tabs -->
            <ul class="nav nav-tabs-custom">
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'purchase' ? 'active' : ''; ?>" href="?tab=purchase">
                        <i class="fas fa-plus-circle"></i> New Purchase
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'history' ? 'active' : ''; ?>" href="?tab=history">
                        <i class="fas fa-history"></i> Purchase History
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'stock' ? 'active' : ''; ?>" href="?tab=stock">
                        <i class="fas fa-warehouse"></i> Stock Overview
                    </a>
                </li>
            </ul>
            
            <div class="tab-content-custom">
                <!-- New Purchase Tab - with dragable split screen -->
                <?php if($active_tab == 'purchase'): ?>
                <div class="split-container" id="splitContainer">
                    <!-- LEFT PANEL - Items -->
                    <div class="split-left" id="splitLeft">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5><i class="fas fa-list"></i> Select Items</h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-2 mb-3">
                                    <div class="col-md-12">
                                        <input type="text" class="form-control" id="itemSearch" placeholder="Search items..." onkeyup="filterItems()">
                                    </div>
                                </div>
                                <div class="scroll-items" id="itemsList">
                                    <div class="row">
                                        <?php foreach($items as $item): ?>
                                        <div class="col-md-6 col-lg-4 item-card" data-name="<?php echo strtolower($item['item_name']); ?>">
                                            <div class="purchase-item-card" onclick="openAddModal(<?php echo $item['id']; ?>, '<?php echo $item['item_name']; ?>', <?php echo $item['purchase_price']; ?>, <?php echo $item['selling_price']; ?>, '<?php echo $item['unit']; ?>')">
                                                <div class="d-flex justify-content-between">
                                                    <strong><?php echo $item['item_name']; ?></strong>
                                                    <small class="text-muted"><?php echo $item['item_code']; ?></small>
                                                </div>
                                                <div class="d-flex justify-content-between mt-1">
                                                    <span class="text-success"><?php echo $currency; ?> <?php echo number_format($item['selling_price'], 2); ?></span>
                                                    <span class="text-muted">Stock: <?php echo number_format($item['current_stock'], 2); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- GUTTER - Drag handle -->
                    <div class="gutter" id="gutter"></div>
                    
                    <!-- RIGHT PANEL - Cart -->
                    <div class="split-right" id="splitRight">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h5><i class="fas fa-shopping-cart"></i> Purchase Cart <span class="badge bg-light text-dark float-end"><?php echo $cart_count; ?> items</span></h5>
                            </div>
                            <div class="card-body">
                                <!-- Supplier Info -->
                                <form method="POST" id="purchaseForm">
                                    <div class="row g-2">
                                        <div class="col-12">
                                            <label>Supplier</label>
                                            <select name="supplier_id" class="form-control form-control-sm" onchange="updateSupplierFields(this)">
                                                <option value="">-- Select Supplier --</option>
                                                <?php foreach($suppliers as $s): ?>
                                                    <option value="<?php echo $s['id']; ?>" data-name="<?php echo $s['supplier_name']; ?>" data-phone="<?php echo $s['phone']; ?>">
                                                        <?php echo $s['supplier_name']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label>Supplier Name</label>
                                            <input type="text" name="supplier_name" id="supplier_name" class="form-control form-control-sm">
                                        </div>
                                        <div class="col-6">
                                            <label>Supplier Phone</label>
                                            <input type="text" name="supplier_phone" id="supplier_phone" class="form-control form-control-sm">
                                        </div>
                                        <div class="col-6">
                                            <label>Purchase Date</label>
                                            <input type="date" name="purchase_date" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="col-6">
                                            <label>Invoice No *</label>
                                            <input type="text" name="invoice_no" class="form-control form-control-sm" placeholder="Enter Invoice No" required>
                                        </div>
                                    </div>
                                    
                                    <hr>
                                    
                                    <!-- Cart Items -->
                                    <div class="cart-scroll" id="purchaseCart">
                                        <?php if(empty($purchase_cart)): ?>
                                            <div class="text-center text-muted py-3">
                                                <i class="fas fa-shopping-cart fa-2x mb-2"></i>
                                                <p>Cart is empty. Add items from the left panel.</p>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach($purchase_cart as $index => $item): ?>
                                            <div class="cart-item">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <strong><?php echo $item['item_name']; ?></strong>
                                                        <br>
                                                        <small><?php echo $item['quantity']; ?> x <?php echo $currency; ?> <?php echo number_format($item['purchase_price'], 2); ?></small>
                                                    </div>
                                                    <div class="text-end">
                                                        <span class="fw-bold"><?php echo $currency; ?> <?php echo number_format($item['total'], 2); ?></span>
                                                        <br>
                                                        <a href="?remove_cart=<?php echo $index; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Remove item?')">
                                                            <i class="fas fa-times"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Totals -->
                                    <div class="cart-total">
                                        <div class="d-flex justify-content-between">
                                            <span>Subtotal:</span>
                                            <span><?php echo $currency; ?> <span id="cartSubtotal"><?php echo number_format($cart_subtotal, 2); ?></span></span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span>Discount:</span>
                                            <span><input type="number" name="discount" class="form-control form-control-sm" style="width:100px;display:inline;" step="0.01" value="0" oninput="updateTotals()"></span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span>Tax:</span>
                                            <span><input type="number" name="tax" class="form-control form-control-sm" style="width:100px;display:inline;" step="0.01" value="0" oninput="updateTotals()"></span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span>Shipping:</span>
                                            <span><input type="number" name="shipping" class="form-control form-control-sm" style="width:100px;display:inline;" step="0.01" value="0" oninput="updateTotals()"></span>
                                        </div>
                                        <hr>
                                        <div class="d-flex justify-content-between fw-bold">
                                            <span>Total:</span>
                                            <span id="cartTotal"><?php echo $currency; ?> <?php echo number_format($cart_subtotal, 2); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="row g-2 mt-2">
                                        <div class="col-6">
                                            <label>Payment Method</label>
                                            <select name="payment_method" class="form-control form-control-sm">
                                                <option value="cash">Cash</option>
                                                <option value="bank">Bank Transfer</option>
                                                <option value="cheque">Cheque</option>
                                                <option value="credit">Credit</option>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label>Payment Status</label>
                                            <select name="payment_status" class="form-control form-control-sm">
                                                <option value="paid">Paid</option>
                                                <option value="pending">Pending</option>
                                                <option value="partial">Partial</option>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label>Notes</label>
                                            <textarea name="notes" class="form-control form-control-sm" rows="1"></textarea>
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" name="save_purchase" class="btn btn-success w-100" <?php echo empty($purchase_cart) ? 'disabled' : ''; ?>>
                                                <i class="fas fa-save"></i> Complete Purchase
                                            </button>
                                            <a href="?clear_cart=1" class="btn btn-danger w-100 mt-1" onclick="return confirm('Clear cart?')">
                                                <i class="fas fa-trash"></i> Clear Cart
                                            </a>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Purchase History Tab -->
                <?php if($active_tab == 'history'): ?>
                <div class="table-responsive">
                    <table class="table table-bordered" id="purchaseTable">
                        <thead class="table-dark">
                            <tr>
                                <th>Purchase No</th>
                                <th>Date</th>
                                <th>Supplier</th>
                                <th>Items</th>
                                <th class="text-end">Subtotal</th>
                                <th class="text-end">Discount</th>
                                <th class="text-end">Tax</th>
                                <th class="text-end">Total</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($purchases as $p): 
                                // Get item count
                                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM item_purchase_items WHERE purchase_id = ?");
                                $stmt->execute([$p['id']]);
                                $item_count = $stmt->fetch()['count'];
                            ?>
                            <tr>
                                <td><strong><?php echo $p['purchase_no']; ?></strong></td>
                                <td><?php echo date('d-m-Y', strtotime($p['purchase_date'])); ?></td>
                                <td><?php echo $p['supplier_name'] ?: 'N/A'; ?></td>
                                <td class="text-center"><?php echo $item_count; ?></td>
                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($p['subtotal'], 2); ?></td>
                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($p['discount'], 2); ?></td>
                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($p['tax'], 2); ?></td>
                                <td class="text-end fw-bold text-success"><?php echo $currency; ?> <?php echo number_format($p['total_amount'], 2); ?></td>
                                <td>
                                    <?php if($p['payment_status'] == 'paid'): ?>
                                        <span class="badge badge-paid">Paid</span>
                                    <?php elseif($p['payment_status'] == 'pending'): ?>
                                        <span class="badge badge-pending">Pending</span>
                                    <?php else: ?>
                                        <span class="badge badge-cancelled"><?php echo ucfirst($p['payment_status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($p['status'] == 'completed'): ?>
                                        <span class="badge bg-success">Completed</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Cancelled</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="viewPurchase(<?php echo $p['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <a href="print_purchase_invoice.php?purchase_id=<?php echo $p['id']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                        <i class="fas fa-print"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <!-- Stock Overview Tab -->
                <?php if($active_tab == 'stock'): ?>
                <div class="table-responsive">
                    <table class="table table-bordered" id="stockTable">
                        <thead class="table-dark">
                            <tr>
                                <th>Code</th>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Type</th>
                                <th>Unit</th>
                                <th class="text-end">Purchase Price</th>
                                <th class="text-end">Selling Price</th>
                                <th class="text-end">Current Stock</th>
                                <th class="text-end">Min Stock</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($items as $item): 
                                $stock_status = $item['current_stock'] <= $item['min_stock'] ? 'Low' : 'OK';
                                $status_class = $item['current_stock'] <= $item['min_stock'] ? 'danger' : 'success';
                            ?>
                            <tr>
                                <td><?php echo $item['item_code']; ?></td>
                                <td><strong><?php echo $item['item_name']; ?></strong></td>
                                <td><?php 
                                    $stmt = $pdo->prepare("SELECT category_name FROM item_categories WHERE id = ?");
                                    $stmt->execute([$item['category_id']]);
                                    $cat = $stmt->fetch();
                                    echo $cat['category_name'] ?? '-';
                                ?></td>
                                <td>
                                    <span class="badge <?php echo $item['item_type'] == 'product' ? 'badge-product' : 'badge-service'; ?>">
                                        <?php echo ucfirst($item['item_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo $item['unit']; ?></td>
                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($item['purchase_price'], 2); ?></td>
                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($item['selling_price'], 2); ?></td>
                                <td class="text-end fw-bold <?php echo $item['current_stock'] <= $item['min_stock'] ? 'text-danger' : ''; ?>">
                                    <?php echo number_format($item['current_stock'], 2); ?>
                                </td>
                                <td class="text-end"><?php echo number_format($item['min_stock'], 2); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $status_class; ?>">
                                        <?php echo $stock_status; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Add to Cart Modal -->
    <div class="modal fade" id="addItemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5><i class="fas fa-plus-circle"></i> Add to Purchase Cart</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="item_id" id="modal_item_id">
                        <div class="mb-3">
                            <label>Item Name</label>
                            <input type="text" id="modal_item_name" class="form-control" readonly>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Quantity</label>
                                    <input type="number" name="quantity" class="form-control" step="0.01" value="1" min="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Unit</label>
                                    <input type="text" id="modal_unit" class="form-control" readonly>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Purchase Price (<?php echo $currency; ?>)</label>
                                    <input type="number" name="purchase_price" id="modal_purchase_price" class="form-control" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Selling Price (<?php echo $currency; ?>)</label>
                                    <input type="number" name="selling_price" id="modal_selling_price" class="form-control" step="0.01" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_to_cart" class="btn btn-primary">Add to Cart</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#purchaseTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
            
            $('#stockTable').DataTable({
                order: [[1, 'asc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
            
            // =============================================
            // DRAGABLE SPLIT SCREEN
            // =============================================
            const splitContainer = document.getElementById('splitContainer');
            const leftPanel = document.getElementById('splitLeft');
            const rightPanel = document.getElementById('splitRight');
            const gutter = document.getElementById('gutter');
            
            let isDragging = false;
            
            // Set initial widths (50/50)
            if (window.innerWidth > 768) {
                leftPanel.style.flex = '1';
                rightPanel.style.flex = '1';
            }
            
            gutter.addEventListener('mousedown', function(e) {
                isDragging = true;
                this.classList.add('dragging');
                document.body.style.cursor = 'col-resize';
                document.body.style.userSelect = 'none';
            });
            
            document.addEventListener('mousemove', function(e) {
                if (!isDragging) return;
                
                const containerRect = splitContainer.getBoundingClientRect();
                const containerWidth = containerRect.width;
                const mouseX = e.clientX - containerRect.left;
                
                // Calculate percentage (10% to 90% limits)
                const percent = Math.min(Math.max((mouseX / containerWidth) * 100, 15), 85);
                
                leftPanel.style.flex = 'none';
                rightPanel.style.flex = 'none';
                leftPanel.style.width = percent + '%';
                rightPanel.style.width = (100 - percent) + '%';
            });
            
            document.addEventListener('mouseup', function() {
                if (isDragging) {
                    isDragging = false;
                    gutter.classList.remove('dragging');
                    document.body.style.cursor = '';
                    document.body.style.userSelect = '';
                }
            });
        });
        
        function filterItems() {
            let search = document.getElementById('itemSearch').value.toLowerCase();
            document.querySelectorAll('.item-card').forEach(card => {
                let name = card.dataset.name;
                if(name.includes(search)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }
        
        function openAddModal(id, name, purchasePrice, sellingPrice, unit) {
            document.getElementById('modal_item_id').value = id;
            document.getElementById('modal_item_name').value = name;
            document.getElementById('modal_unit').value = unit;
            document.getElementById('modal_purchase_price').value = purchasePrice;
            document.getElementById('modal_selling_price').value = sellingPrice;
            
            new bootstrap.Modal(document.getElementById('addItemModal')).show();
        }
        
        function updateSupplierFields(select) {
            let option = select.options[select.selectedIndex];
            if(option.value) {
                document.getElementById('supplier_name').value = option.dataset.name || '';
                document.getElementById('supplier_phone').value = option.dataset.phone || '';
            }
        }
        
        function updateTotals() {
            let subtotal = parseFloat(document.getElementById('cartSubtotal').innerText.replace(/,/g, '')) || 0;
            let discount = parseFloat(document.querySelector('input[name="discount"]').value) || 0;
            let tax = parseFloat(document.querySelector('input[name="tax"]').value) || 0;
            let shipping = parseFloat(document.querySelector('input[name="shipping"]').value) || 0;
            
            let total = subtotal - discount + tax + shipping;
            document.getElementById('cartTotal').innerHTML = '<?php echo $currency; ?> ' + total.toFixed(2);
        }
        
        function viewPurchase(id) {
            window.open('view_purchase.php?id=' + id, '_blank', 'width=800,height=600');
        }
    </script>
</body>
</html>
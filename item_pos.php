<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';
$cart = isset($_SESSION['item_cart']) ? $_SESSION['item_cart'] : [];

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

// Get item categories
$categories = $pdo->query("SELECT * FROM item_categories WHERE is_active = 1 ORDER BY category_name")->fetchAll();

// Get items
$items = $pdo->query("
    SELECT i.*, c.category_name 
    FROM items i 
    LEFT JOIN item_categories c ON i.category_id = c.id 
    WHERE i.is_active = 1 
    ORDER BY i.item_name
")->fetchAll();

// Get customers
$customers = $pdo->query("SELECT * FROM customers WHERE is_active = 1 ORDER BY customer_name")->fetchAll();

// Add to cart
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_to_cart'])) {
    $item_id = $_POST['item_id'];
    $quantity = floatval($_POST['quantity']);
    
    if($quantity <= 0) {
        $error = "Please enter valid quantity!";
    } else {
        // Get item details
        $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ? AND is_active = 1");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch();
        
        if($item) {
            // Check stock for products
            if($item['item_type'] == 'product' && $item['current_stock'] < $quantity) {
                $error = "Insufficient stock! Available: " . $item['current_stock'] . " " . $item['unit'];
            } else {
                // Add to cart
                $cart_item = [
                    'item_id' => $item['id'],
                    'item_code' => $item['item_code'],
                    'item_name' => $item['item_name'],
                    'item_type' => $item['item_type'],
                    'unit' => $item['unit'],
                    'quantity' => $quantity,
                    'unit_price' => $item['selling_price'],
                    'total' => $quantity * $item['selling_price']
                ];
                
                // Check if item already in cart
                $found = false;
                foreach($cart as $key => $ci) {
                    if($ci['item_id'] == $item_id) {
                        $cart[$key]['quantity'] += $quantity;
                        $cart[$key]['total'] = $cart[$key]['quantity'] * $cart[$key]['unit_price'];
                        $found = true;
                        break;
                    }
                }
                
                if(!$found) {
                    $cart[] = $cart_item;
                }
                
                $_SESSION['item_cart'] = $cart;
                $success = "Item added to cart!";
            }
        } else {
            $error = "Item not found!";
        }
    }
}

// Update cart quantity
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_cart'])) {
    foreach($_POST['quantity'] as $key => $qty) {
        if(isset($cart[$key])) {
            $qty = floatval($qty);
            if($qty <= 0) {
                unset($cart[$key]);
            } else {
                $cart[$key]['quantity'] = $qty;
                $cart[$key]['total'] = $qty * $cart[$key]['unit_price'];
            }
        }
    }
    $_SESSION['item_cart'] = array_values($cart);
    $success = "Cart updated!";
}

// Remove from cart
if(isset($_GET['remove'])) {
    $index = intval($_GET['remove']);
    if(isset($cart[$index])) {
        unset($cart[$index]);
        $_SESSION['item_cart'] = array_values($cart);
        $success = "Item removed from cart!";
    }
    header("Location: item_pos.php");
    exit();
}

// Clear cart
if(isset($_GET['clear_cart'])) {
    $_SESSION['item_cart'] = [];
    header("Location: item_pos.php");
    exit();
}

// Process sale
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['process_sale'])) {
    if(empty($cart)) {
        $error = "Cart is empty! Please add items first.";
    } else {
        $customer_id = !empty($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
        $customer_name = $_POST['customer_name'] ?? '';
        $customer_phone = $_POST['customer_phone'] ?? '';
        $sale_type = $_POST['sale_type'] ?? 'cash';
        $discount_percent = floatval($_POST['discount_percent'] ?? 0);
        $discount_amount = floatval($_POST['discount_amount'] ?? 0);
        $received = floatval($_POST['received'] ?? 0);
        $notes = $_POST['notes'] ?? '';
        $invoice_no = 'ITEM-' . date('YmdHis') . rand(100, 999);
        $shift_id = $active_shift['id'] ?? 0;
        
        // Calculate totals
        $subtotal = array_sum(array_column($cart, 'total'));
        $tax_amount = 0; // Simplified - could add tax per item later
        
        // Apply discount
        if($discount_percent > 0) {
            $discount_amount = ($subtotal * $discount_percent) / 100;
        }
        
        $total_amount = $subtotal - $discount_amount + $tax_amount;
        $change = $received - $total_amount;
        
        if($sale_type == 'credit' && empty($customer_name)) {
            $error = "Customer name is required for credit sales!";
        } else {
            try {
                $pdo->beginTransaction();
                
                // Create customer if needed
                if($customer_id) {
                    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
                    $stmt->execute([$customer_id]);
                    $customer = $stmt->fetch();
                    if($customer) {
                        $customer_name = $customer['customer_name'];
                        $customer_phone = $customer['phone'];
                    }
                } elseif(!empty($customer_name) && $sale_type == 'credit') {
                    // Check if customer exists
                    $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ? OR customer_name = ?");
                    $stmt->execute([$customer_phone, $customer_name]);
                    $existing = $stmt->fetch();
                    if($existing) {
                        $customer_id = $existing['id'];
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO customers (customer_code, customer_name, phone, credit_limit) VALUES (?, ?, ?, ?)");
                        $customer_code = 'CUST-' . date('Ymd') . rand(100, 999);
                        $stmt->execute([$customer_code, $customer_name, $customer_phone, 50000]);
                        $customer_id = $pdo->lastInsertId();
                    }
                }
                
                // Insert sale
                $stmt = $pdo->prepare("
                    INSERT INTO item_sales (
                        invoice_no, sale_date, shift_id, customer_id, customer_name, customer_phone,
                        sale_type, subtotal, discount_amount, discount_percent, tax_amount,
                        total_amount, received_amount, change_amount, notes, created_by, status
                    ) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')
                ");
                $stmt->execute([
                    $invoice_no, $shift_id, $customer_id, $customer_name, $customer_phone,
                    $sale_type, $subtotal, $discount_amount, $discount_percent, $tax_amount,
                    $total_amount, $received, $change, $notes, $user['id']
                ]);
                $sale_id = $pdo->lastInsertId();
                
                // Insert sale items
                foreach($cart as $item) {
                    $stmt = $pdo->prepare("
                        INSERT INTO item_sale_items (sale_id, item_id, quantity, unit_price, total_amount)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$sale_id, $item['item_id'], $item['quantity'], $item['unit_price'], $item['total']]);
                    
                    // Update stock for products
                    if($item['item_type'] == 'product') {
                        $stmt = $pdo->prepare("UPDATE items SET current_stock = current_stock - ? WHERE id = ?");
                        $stmt->execute([$item['quantity'], $item['item_id']]);
                    }
                }
                
                // Accounting entries
                // Get accounts
                $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1000' LIMIT 1");
                $cash_account = $stmt->fetch();
                
                $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1300' LIMIT 1");
                $ar_account = $stmt->fetch();
                
                $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '4000' OR account_name LIKE '%Sales Revenue - Items%' LIMIT 1");
                $sales_account = $stmt->fetch();
                
                if($sale_type == 'cash' && $cash_account && $sales_account) {
                    $voucher_no = 'ITEM-CASH-' . date('YmdHis') . rand(100, 999);
                    $narration = "Item sale - $invoice_no - Amount: BDT " . number_format($total_amount, 2);
                    
                    $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'receipt', CURDATE(), ?, ?, 'approved')");
                    $stmt->execute([$voucher_no, $narration, $user['id']]);
                    $voucher_id = $pdo->lastInsertId();
                    
                    $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                        (?, ?, ?, ?, ?),
                        (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $voucher_id, $cash_account['id'], $total_amount, 0, "Item sale - $invoice_no",
                        $voucher_id, $sales_account['id'], 0, $total_amount, "Item sales revenue - $invoice_no"
                    ]);
                } elseif($sale_type == 'credit' && $ar_account && $sales_account) {
                    $voucher_no = 'ITEM-CREDIT-' . date('YmdHis') . rand(100, 999);
                    $narration = "Credit item sale to $customer_name - Invoice: $invoice_no - Amount: BDT " . number_format($total_amount, 2);
                    
                    $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'journal', CURDATE(), ?, ?, 'approved')");
                    $stmt->execute([$voucher_no, $narration, $user['id']]);
                    $voucher_id = $pdo->lastInsertId();
                    
                    $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                        (?, ?, ?, ?, ?),
                        (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $voucher_id, $ar_account['id'], $total_amount, 0, "Credit item sale to $customer_name - $invoice_no",
                        $voucher_id, $sales_account['id'], 0, $total_amount, "Item sales revenue - $invoice_no"
                    ]);
                    
                    // Update customer balance
                    if($customer_id) {
                        $stmt = $pdo->prepare("UPDATE customers SET current_balance = current_balance + ? WHERE id = ?");
                        $stmt->execute([$total_amount, $customer_id]);
                    }
                }
                
                $pdo->commit();
                
                // Clear cart
                $_SESSION['item_cart'] = [];
                
                // Store invoice for printing
                $_SESSION['last_item_invoice'] = [
                    'invoice_no' => $invoice_no,
                    'customer_name' => $customer_name,
                    'customer_phone' => $customer_phone,
                    'date' => date('Y-m-d H:i:s'),
                    'items' => $cart,
                    'subtotal' => $subtotal,
                    'discount' => $discount_amount,
                    'tax' => $tax_amount,
                    'total' => $total_amount,
                    'received' => $received,
                    'change' => $change,
                    'sale_type' => $sale_type
                ];
                
                // Redirect to invoice
                header("Location: print_item_invoice.php?invoice=" . $invoice_no);
                exit();
                
            } catch(Exception $e) {
                $pdo->rollBack();
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

// Get cart totals
$cart_subtotal = array_sum(array_column($cart, 'total'));
$cart_count = count($cart);

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item & Services POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
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
        
        .item-card {
            background: white;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: all 0.3s;
            cursor: pointer;
            border: 2px solid transparent;
        }
        .item-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-color: #667eea;
        }
        .item-card .price {
            font-weight: bold;
            color: #28a745;
        }
        .item-card .stock {
            font-size: 12px;
            color: #6c757d;
        }
        .badge-product { background: #28a745; color: white; }
        .badge-service { background: #17a2b8; color: white; }
        .cart-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .cart-item .qty-input {
            width: 60px;
            text-align: center;
        }
        .cart-total {
            background: #e9ecef;
            padding: 15px;
            border-radius: 10px;
            margin-top: 10px;
        }
        .category-filter .btn {
            margin: 3px;
            border-radius: 20px;
        }
        .category-filter .btn.active {
            background: #667eea;
            color: white;
        }
        .scroll-items {
            max-height: 450px;
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
                <h2><i class="fas fa-boxes"></i> Item & Services POS</h2>
                <div>
                    <a href="item_management.php" class="btn btn-info">
                        <i class="fas fa-cog"></i> Manage Items
                    </a>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Dashboard
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
            
            <!-- Add this in the checkout form -->
            <?php if($active_shift): ?>
            <input type="hidden" name="shift_id" value="<?php echo $active_shift['shift_id']; ?>">
            <div class="alert alert-info py-1 mb-2">
                <small><i class="fas fa-clock"></i> Shift: <?php echo $active_shift['shift_name']; ?> (Started: <?php echo date('h:i A', strtotime($active_shift['opening_time'])); ?>)</small>
            </div>
            <?php endif; ?>
            
            <!-- Split Screen -->
            <div class="split-container" id="splitContainer">
                <!-- LEFT PANEL - Items -->
                <div class="split-left" id="splitLeft">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-list"></i> Items & Services</h5>
                        </div>
                        <div class="card-body">
                            <!-- Category Filter -->
                            <div class="category-filter mb-3">
                                <button class="btn btn-sm btn-outline-primary active" data-category="all" onclick="filterCategory('all', this)">All</button>
                                <?php foreach($categories as $cat): ?>
                                    <button class="btn btn-sm btn-outline-primary" data-category="<?php echo $cat['id']; ?>" onclick="filterCategory('<?php echo $cat['id']; ?>', this)">
                                        <?php echo $cat['category_name']; ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Items Grid -->
                            <div class="scroll-items" id="itemsGrid">
                                <div class="row">
                                    <?php foreach($items as $item): 
                                        $is_product = $item['item_type'] == 'product';
                                        $stock_class = $is_product && $item['current_stock'] <= $item['min_stock'] ? 'text-danger' : '';
                                    ?>
                                    <div class="col-md-6 col-lg-4 item-card-wrapper" data-category="<?php echo $item['category_id']; ?>">
                                        <div class="item-card" onclick="addToCart(<?php echo $item['id']; ?>, '<?php echo $item['item_name']; ?>', <?php echo $item['selling_price']; ?>, '<?php echo $item['item_type']; ?>', '<?php echo $item['unit']; ?>', <?php echo $item['current_stock']; ?>)">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <span class="badge <?php echo $is_product ? 'badge-product' : 'badge-service'; ?>">
                                                    <?php echo $is_product ? 'Product' : 'Service'; ?>
                                                </span>
                                                <small class="text-muted"><?php echo $item['category_name']; ?></small>
                                            </div>
                                            <h6 class="mt-2 mb-1"><?php echo $item['item_name']; ?></h6>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="price"><?php echo $currency; ?> <?php echo number_format($item['selling_price'], 2); ?></span>
                                                <?php if($is_product): ?>
                                                    <span class="stock <?php echo $stock_class; ?>">
                                                        <i class="fas fa-box"></i> <?php echo $item['current_stock']; ?> <?php echo $item['unit']; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted"><?php echo $item['item_code']; ?></small>
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
                            <h5><i class="fas fa-shopping-cart"></i> Cart <span class="badge bg-light text-dark float-end"><?php echo $cart_count; ?> items</span></h5>
                        </div>
                        <div class="card-body">
                            <!-- Quick Add Modal Trigger -->
                            <button class="btn btn-primary w-100 mb-3" data-bs-toggle="modal" data-bs-target="#quickAddModal">
                                <i class="fas fa-plus-circle"></i> Quick Add Item
                            </button>
                            
                            <!-- Cart Items -->
                            <div class="cart-scroll" id="cartItems">
                                <?php if(empty($cart)): ?>
                                    <div class="text-center text-muted py-4">
                                        <i class="fas fa-shopping-cart fa-3x mb-2"></i>
                                        <p>Cart is empty. Add items to start selling.</p>
                                    </div>
                                <?php else: ?>
                                    <form method="POST" id="cartForm">
                                        <?php foreach($cart as $index => $item): ?>
                                        <div class="cart-item">
                                            <div class="flex-grow-1">
                                                <strong><?php echo $item['item_name']; ?></strong>
                                                <br>
                                                <small><?php echo $item['item_type'] == 'product' ? 'Product' : 'Service'; ?></small>
                                                <span class="text-muted">| <?php echo $currency; ?> <?php echo number_format($item['unit_price'], 2); ?></span>
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <input type="number" name="quantity[<?php echo $index; ?>]" value="<?php echo $item['quantity']; ?>" class="form-control form-control-sm qty-input" min="0.01" step="0.01">
                                                <span class="fw-bold text-success"><?php echo $currency; ?> <?php echo number_format($item['total'], 2); ?></span>
                                                <a href="?remove=<?php echo $index; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Remove item?')">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <div class="mt-2">
                                            <button type="submit" name="update_cart" class="btn btn-sm btn-warning w-100">
                                                <i class="fas fa-sync"></i> Update Cart
                                            </button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Cart Totals -->
                            <div class="cart-total">
                                <div class="d-flex justify-content-between">
                                    <span>Subtotal:</span>
                                    <span><?php echo $currency; ?> <span id="cartSubtotal"><?php echo number_format($cart_subtotal, 2); ?></span></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Items:</span>
                                    <span id="cartCount"><?php echo $cart_count; ?></span>
                                </div>
                            </div>
                            
                            <!-- Checkout Form -->
                            <form method="POST" id="checkoutForm" class="mt-3">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label>Customer</label>
                                        <select name="customer_id" class="form-control form-control-sm" onchange="updateCustomerFields(this)">
                                            <option value="">Walk-in</option>
                                            <?php foreach($customers as $c): ?>
                                                <option value="<?php echo $c['id']; ?>" data-name="<?php echo $c['customer_name']; ?>" data-phone="<?php echo $c['phone']; ?>">
                                                    <?php echo $c['customer_name']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label>Sale Type</label>
                                        <select name="sale_type" class="form-control form-control-sm" onchange="toggleSaleType(this)">
                                            <option value="cash">Cash</option>
                                            <option value="credit">Credit</option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label>Customer Name</label>
                                        <input type="text" name="customer_name" id="customer_name" class="form-control form-control-sm" placeholder="Name">
                                    </div>
                                    <div class="col-6">
                                        <label>Customer Phone</label>
                                        <input type="text" name="customer_phone" id="customer_phone" class="form-control form-control-sm" placeholder="Phone">
                                    </div>
                                    <div class="col-6">
                                        <label>Discount %</label>
                                        <input type="number" name="discount_percent" class="form-control form-control-sm" step="0.01" value="0" oninput="updateDiscount(this)">
                                    </div>
                                    <div class="col-6">
                                        <label>Discount Amount</label>
                                        <input type="number" name="discount_amount" class="form-control form-control-sm" step="0.01" value="0" readonly>
                                    </div>
                                    <div class="col-6">
                                        <label>Received (<?php echo $currency; ?>)</label>
                                        <input type="number" name="received" class="form-control form-control-sm" step="0.01" value="0" oninput="calculateChange()">
                                    </div>
                                    <div class="col-6">
                                        <label>Change</label>
                                        <input type="text" id="change_display" class="form-control form-control-sm" readonly value="<?php echo $currency; ?> 0.00">
                                    </div>
                                    <div class="col-12">
                                        <label>Notes</label>
                                        <textarea name="notes" class="form-control form-control-sm" rows="1" placeholder="Optional notes"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" name="process_sale" class="btn btn-success w-100" <?php echo empty($cart) || !$active_shift ? 'disabled' : ''; ?>>
                                            <i class="fas fa-check-circle"></i> Complete Sale
                                        </button>
                                        <?php if(!empty($cart)): ?>
                                            <a href="?clear_cart=1" class="btn btn-danger w-100 mt-1" onclick="return confirm('Clear cart?')">
                                                <i class="fas fa-trash"></i> Clear Cart
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Add Modal -->
    <div class="modal fade" id="quickAddModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5><i class="fas fa-plus-circle"></i> Quick Add Item</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Select Item</label>
                            <select name="item_id" class="form-control" required>
                                <option value="">-- Select Item --</option>
                                <?php foreach($items as $item): ?>
                                    <option value="<?php echo $item['id']; ?>" data-type="<?php echo $item['item_type']; ?>" data-stock="<?php echo $item['current_stock']; ?>">
                                        <?php echo $item['item_name']; ?> (<?php echo $currency; ?> <?php echo number_format($item['selling_price'], 2); ?>) 
                                        <?php if($item['item_type'] == 'product'): ?>
                                            - Stock: <?php echo $item['current_stock']; ?> <?php echo $item['unit']; ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Quantity</label>
                            <input type="number" name="quantity" class="form-control" step="0.01" value="1" min="0.01" required>
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
    <script>
        let currency = '<?php echo $currency; ?>';
        let currentSubtotal = <?php echo $cart_subtotal; ?>;
        
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
        
        // =============================================
        // POS FUNCTIONS
        // =============================================
        function addToCart(itemId, itemName, price, type, unit, stock) {
            if(type == 'product' && stock <= 0) {
                alert('Out of stock!');
                return;
            }
            
            let qty = prompt('Enter quantity for ' + itemName + ':', '1');
            if(qty && parseFloat(qty) > 0) {
                // Create a hidden form and submit
                let form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                let input1 = document.createElement('input');
                input1.type = 'hidden';
                input1.name = 'item_id';
                input1.value = itemId;
                form.appendChild(input1);
                
                let input2 = document.createElement('input');
                input2.type = 'hidden';
                input2.name = 'quantity';
                input2.value = qty;
                form.appendChild(input2);
                
                let input3 = document.createElement('input');
                input3.type = 'hidden';
                input3.name = 'add_to_cart';
                input3.value = '1';
                form.appendChild(input3);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function filterCategory(categoryId, btn) {
            document.querySelectorAll('.category-filter .btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            document.querySelectorAll('.item-card-wrapper').forEach(el => {
                if(categoryId == 'all' || el.dataset.category == categoryId) {
                    el.style.display = '';
                } else {
                    el.style.display = 'none';
                }
            });
        }
        
        function updateCustomerFields(select) {
            let option = select.options[select.selectedIndex];
            if(option.value) {
                document.getElementById('customer_name').value = option.dataset.name || '';
                document.getElementById('customer_phone').value = option.dataset.phone || '';
            }
        }
        
        function toggleSaleType(select) {
            if(select.value == 'credit') {
                document.getElementById('customer_name').setAttribute('required', 'required');
            } else {
                document.getElementById('customer_name').removeAttribute('required');
            }
        }
        
        function calculateChange() {
            let subtotal = parseFloat(document.getElementById('cartSubtotal').innerText.replace(/,/g, '')) || 0;
            let discountPercent = parseFloat(document.querySelector('input[name="discount_percent"]').value) || 0;
            let discountAmount = (subtotal * discountPercent) / 100;
            document.querySelector('input[name="discount_amount"]').value = discountAmount.toFixed(2);
            
            let total = subtotal - discountAmount;
            let received = parseFloat(document.querySelector('input[name="received"]').value) || 0;
            let change = received - total;
            document.getElementById('change_display').value = currency + ' ' + change.toFixed(2);
        }
        
        function updateDiscount(input) {
            calculateChange();
        }
        
        // Auto calculate change on load
        setTimeout(calculateChange, 100);
        
        // Form validation
        document.getElementById('checkoutForm').addEventListener('submit', function(e) {
            let saleType = document.querySelector('select[name="sale_type"]').value;
            let customerName = document.getElementById('customer_name').value.trim();
            
            if(saleType == 'credit' && !customerName) {
                e.preventDefault();
                alert('❌ Please enter customer name for credit sale');
                return false;
            }
            
            let cartCount = parseInt(document.getElementById('cartCount').innerText) || 0;
            if(cartCount == 0) {
                e.preventDefault();
                alert('❌ Cart is empty! Please add items first.');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>
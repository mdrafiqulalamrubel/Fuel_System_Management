<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'customer';

// Get accounts for accounting
$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1000' OR account_name LIKE '%Cash%' LIMIT 1");
$cash_account = $stmt->fetch();

$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1300' OR account_name LIKE '%Accounts Receivable%' LIMIT 1");
$ar_account = $stmt->fetch();

$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '2000' OR account_name LIKE '%Accounts Payable%' LIMIT 1");
$ap_account = $stmt->fetch();

// Process Customer Advance
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_customer_advance'])) {
    $customer_id = $_POST['customer_id'];
    $advance_date = $_POST['advance_date'];
    $amount = floatval($_POST['amount']);
    $payment_method = $_POST['payment_method'];
    $reference_no = $_POST['reference_no'];
    $notes = $_POST['notes'];
    
    try {
        $pdo->beginTransaction();
        
        // Get customer details
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();
        
        if(!$customer) {
            throw new Exception("Customer not found!");
        }
        
        // Insert advance payment
        $stmt = $pdo->prepare("INSERT INTO advance_payments_customer (customer_id, advance_date, amount, payment_method, reference_no, notes, balance_amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$customer_id, $advance_date, $amount, $payment_method, $reference_no, $notes, $amount, $user['id']]);
        $advance_id = $pdo->lastInsertId();
        
        // Update customer advance balance
        $stmt = $pdo->prepare("UPDATE customers SET advance_balance = advance_balance + ? WHERE id = ?");
        $stmt->execute([$amount, $customer_id]);
        
        // Create accounting entry
        $voucher_no = 'ADV-C-' . date('YmdHis') . rand(100, 999);
        $narration = "Advance received from {$customer['customer_name']} - Amount: BDT " . number_format($amount, 2);
        
        $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'receipt', ?, ?, ?, 'approved')");
        $stmt->execute([$voucher_no, $advance_date, $narration, $user['id']]);
        $voucher_id = $pdo->lastInsertId();
        
        // Update voucher with advance ID
        $stmt = $pdo->prepare("UPDATE advance_payments_customer SET voucher_id = ? WHERE id = ?");
        $stmt->execute([$voucher_id, $advance_id]);
        
        // Dr. Cash, Cr. Customer Advance (Liability)
        $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
            (?, ?, ?, ?, ?),
            (?, ?, ?, ?, ?)");
        $stmt->execute([
            $voucher_id, $cash_account['id'], $amount, 0, "Advance received from {$customer['customer_name']}",
            $voucher_id, 3300, 0, $amount, "Customer advance liability - {$customer['customer_name']}"
        ]);
        
        $pdo->commit();
        $success = "✅ Advance payment recorded for {$customer['customer_name']}!<br>
                    <strong>Amount:</strong> BDT " . number_format($amount, 2) . "<br>
                    <strong>New Advance Balance:</strong> BDT " . number_format($customer['advance_balance'] + $amount, 2);
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Process Supplier Advance
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_supplier_advance'])) {
    $supplier_id = $_POST['supplier_id'];
    $advance_date = $_POST['advance_date'];
    $amount = floatval($_POST['amount']);
    $payment_method = $_POST['payment_method'];
    $reference_no = $_POST['reference_no'];
    $notes = $_POST['notes'];
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
        $stmt->execute([$supplier_id]);
        $supplier = $stmt->fetch();
        
        if(!$supplier) {
            throw new Exception("Supplier not found!");
        }
        
        $stmt = $pdo->prepare("INSERT INTO advance_payments_supplier (supplier_id, advance_date, amount, payment_method, reference_no, notes, balance_amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$supplier_id, $advance_date, $amount, $payment_method, $reference_no, $notes, $amount, $user['id']]);
        $advance_id = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("UPDATE suppliers SET advance_balance = advance_balance + ? WHERE id = ?");
        $stmt->execute([$amount, $supplier_id]);
        
        $voucher_no = 'ADV-S-' . date('YmdHis') . rand(100, 999);
        $narration = "Advance paid to {$supplier['supplier_name']} - Amount: BDT " . number_format($amount, 2);
        
        $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'payment', ?, ?, ?, 'approved')");
        $stmt->execute([$voucher_no, $advance_date, $narration, $user['id']]);
        $voucher_id = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("UPDATE advance_payments_supplier SET voucher_id = ? WHERE id = ?");
        $stmt->execute([$voucher_id, $advance_id]);
        
        // Dr. Supplier Advance (Asset), Cr. Cash
        $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
            (?, ?, ?, ?, ?),
            (?, ?, ?, ?, ?)");
        $stmt->execute([
            $voucher_id, 2100, $amount, 0, "Advance paid to {$supplier['supplier_name']}",
            $voucher_id, $cash_account['id'], 0, $amount, "Cash paid as advance to supplier"
        ]);
        
        $pdo->commit();
        $success = "✅ Advance payment to {$supplier['supplier_name']} recorded!<br>
                    <strong>Amount:</strong> BDT " . number_format($amount, 2) . "<br>
                    <strong>New Advance Balance:</strong> BDT " . number_format($supplier['advance_balance'] + $amount, 2);
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Get customers and suppliers
$customers = $pdo->query("SELECT * FROM customers WHERE is_active = 1 ORDER BY customer_name")->fetchAll();
$suppliers = $pdo->query("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY supplier_name")->fetchAll();

// Get advance records
$customer_advances = $pdo->query("
    SELECT ap.*, c.customer_name, c.customer_code 
    FROM advance_payments_customer ap
    JOIN customers c ON ap.customer_id = c.id
    WHERE ap.status = 'active'
    ORDER BY ap.advance_date DESC
    LIMIT 50
")->fetchAll();

$supplier_advances = $pdo->query("
    SELECT ap.*, s.supplier_name, s.supplier_code 
    FROM advance_payments_supplier ap
    JOIN suppliers s ON ap.supplier_id = s.id
    WHERE ap.status = 'active'
    ORDER BY ap.advance_date DESC
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
    <title>Advance Payment Management</title>
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
        .nav-tabs { border-bottom: 2px solid #dee2e6; margin-bottom: 20px; }
        .nav-tabs .nav-link {
            color: #495057 !important;
            background: #e9ecef !important;
            border-radius: 8px 8px 0 0;
            margin-right: 5px;
            font-weight: 500;
            padding: 10px 20px;
            border: none;
        }
        .nav-tabs .nav-link:hover {
            background: #dee2e6 !important;
            color: #000 !important;
        }
        .nav-tabs .nav-link.active {
            color: white !important;
            font-weight: 600;
        }
        .nav-tabs .nav-link:first-child.active { background: #007bff !important; }
        .nav-tabs .nav-link:nth-child(2).active { background: #28a745 !important; }
        .balance-positive { color: #28a745; font-weight: bold; }
        .balance-negative { color: #dc3545; font-weight: bold; }
        .badge-active { background: #28a745; color: white; }
        .badge-used { background: #ffc107; color: #856404; }
        .badge-cancelled { background: #dc3545; color: white; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-hand-holding-usd"></i> Advance Payment Management</h2>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
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
                        <i class="fas fa-users"></i>
                        <h3><?php echo count($customer_advances); ?></h3>
                        <p>Customer Advances</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php 
                            $total_customer_advance = array_sum(array_column($customer_advances, 'balance_amount'));
                            echo number_format($total_customer_advance, 2);
                        ?></h3>
                        <p>Total Customer Advance</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-truck"></i>
                        <h3><?php echo count($supplier_advances); ?></h3>
                        <p>Supplier Advances</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php 
                            $total_supplier_advance = array_sum(array_column($supplier_advances, 'balance_amount'));
                            echo number_format($total_supplier_advance, 2);
                        ?></h3>
                        <p>Total Supplier Advance</p>
                    </div>
                </div>
            </div>
            
            <!-- Tabs -->
            <ul class="nav nav-tabs">
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'customer' ? 'active' : ''; ?>" href="?tab=customer">
                        <i class="fas fa-users"></i> Customer Advances
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'supplier' ? 'active' : ''; ?>" href="?tab=supplier">
                        <i class="fas fa-truck"></i> Supplier Advances
                    </a>
                </li>
            </ul>
            
            <!-- Customer Advances Tab -->
            <?php if($active_tab == 'customer'): ?>
            <div class="row mt-3">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-plus-circle"></i> Record Customer Advance</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label>Customer *</label>
                                    <select name="customer_id" class="form-control" required>
                                        <option value="">-- Select Customer --</option>
                                        <?php foreach($customers as $c): ?>
                                            <option value="<?php echo $c['id']; ?>">
                                                <?php echo $c['customer_name']; ?> (Due: <?php echo $currency; ?> <?php echo number_format($c['current_balance'], 2); ?> | Advance: <?php echo $currency; ?> <?php echo number_format($c['advance_balance'], 2); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Date *</label>
                                    <input type="date" name="advance_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label>Amount (<?php echo $currency; ?>) *</label>
                                    <input type="number" name="amount" class="form-control" step="0.01" required>
                                </div>
                                <div class="mb-3">
                                    <label>Payment Method</label>
                                    <select name="payment_method" class="form-control">
                                        <option value="cash">Cash</option>
                                        <option value="bank">Bank Transfer</option>
                                        <option value="cheque">Cheque</option>
                                        <option value="mobile_banking">Mobile Banking</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Reference No</label>
                                    <input type="text" name="reference_no" class="form-control" placeholder="Cheque/Transaction ID">
                                </div>
                                <div class="mb-3">
                                    <label>Notes</label>
                                    <textarea name="notes" class="form-control" rows="2"></textarea>
                                </div>
                                <button type="submit" name="save_customer_advance" class="btn btn-primary w-100">
                                    <i class="fas fa-save"></i> Record Advance
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="fas fa-history"></i> Customer Advance History</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="customerAdvanceTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Date</th>
                                            <th>Customer</th>
                                            <th>Amount</th>
                                            <th>Used</th>
                                            <th>Balance</th>
                                            <th>Method</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($customer_advances as $adv): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($adv['advance_date'])); ?></td>
                                            <td><?php echo $adv['customer_name']; ?></td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($adv['amount'], 2); ?></td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($adv['used_amount'], 2); ?></td>
                                            <td class="text-end fw-bold <?php echo $adv['balance_amount'] > 0 ? 'balance-positive' : ''; ?>">
                                                <?php echo $currency; ?> <?php echo number_format($adv['balance_amount'], 2); ?>
                                            </td>
                                            <td><?php echo ucfirst($adv['payment_method']); ?></td>
                                            <td>
                                                <?php if($adv['status'] == 'active'): ?>
                                                    <span class="badge badge-active">Active</span>
                                                <?php elseif($adv['status'] == 'fully_used'): ?>
                                                    <span class="badge badge-used">Used</span>
                                                <?php else: ?>
                                                    <span class="badge badge-cancelled">Cancelled</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="2" class="text-end">TOTAL:</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($customer_advances, 'amount')), 2); ?></td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($customer_advances, 'used_amount')), 2); ?></td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($customer_advances, 'balance_amount')), 2); ?></td>
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
            
            <!-- Supplier Advances Tab -->
            <?php if($active_tab == 'supplier'): ?>
            <div class="row mt-3">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-plus-circle"></i> Record Supplier Advance</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label>Supplier *</label>
                                    <select name="supplier_id" class="form-control" required>
                                        <option value="">-- Select Supplier --</option>
                                        <?php foreach($suppliers as $s): ?>
                                            <option value="<?php echo $s['id']; ?>">
                                                <?php echo $s['supplier_name']; ?> (Due: <?php echo $currency; ?> <?php echo number_format($s['current_balance'], 2); ?> | Advance: <?php echo $currency; ?> <?php echo number_format($s['advance_balance'], 2); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Date *</label>
                                    <input type="date" name="advance_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label>Amount (<?php echo $currency; ?>) *</label>
                                    <input type="number" name="amount" class="form-control" step="0.01" required>
                                </div>
                                <div class="mb-3">
                                    <label>Payment Method</label>
                                    <select name="payment_method" class="form-control">
                                        <option value="cash">Cash</option>
                                        <option value="bank">Bank Transfer</option>
                                        <option value="cheque">Cheque</option>
                                        <option value="mobile_banking">Mobile Banking</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Reference No</label>
                                    <input type="text" name="reference_no" class="form-control" placeholder="Cheque/Transaction ID">
                                </div>
                                <div class="mb-3">
                                    <label>Notes</label>
                                    <textarea name="notes" class="form-control" rows="2"></textarea>
                                </div>
                                <button type="submit" name="save_supplier_advance" class="btn btn-primary w-100">
                                    <i class="fas fa-save"></i> Record Advance
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="fas fa-history"></i> Supplier Advance History</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="supplierAdvanceTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Date</th>
                                            <th>Supplier</th>
                                            <th>Amount</th>
                                            <th>Used</th>
                                            <th>Balance</th>
                                            <th>Method</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($supplier_advances as $adv): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($adv['advance_date'])); ?></td>
                                            <td><?php echo $adv['supplier_name']; ?></td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($adv['amount'], 2); ?></td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($adv['used_amount'], 2); ?></td>
                                            <td class="text-end fw-bold <?php echo $adv['balance_amount'] > 0 ? 'balance-positive' : ''; ?>">
                                                <?php echo $currency; ?> <?php echo number_format($adv['balance_amount'], 2); ?>
                                            </td>
                                            <td><?php echo ucfirst($adv['payment_method']); ?></td>
                                            <td>
                                                <?php if($adv['status'] == 'active'): ?>
                                                    <span class="badge badge-active">Active</span>
                                                <?php elseif($adv['status'] == 'fully_used'): ?>
                                                    <span class="badge badge-used">Used</span>
                                                <?php else: ?>
                                                    <span class="badge badge-cancelled">Cancelled</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="2" class="text-end">TOTAL:</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($supplier_advances, 'amount')), 2); ?></td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($supplier_advances, 'used_amount')), 2); ?></td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($supplier_advances, 'balance_amount')), 2); ?></td>
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
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#customerAdvanceTable, #supplierAdvanceTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
    </script>
</body>
</html>
<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Get GAS products
$gas_products = $pdo->query("SELECT * FROM fuel_products WHERE product_name IN ('CNG', 'LPG', 'Natural Gas') AND is_active = 1")->fetchAll();

// Get GAS tanks
$gas_tanks = $pdo->query("
    SELECT t.*, p.product_name 
    FROM tanks t 
    JOIN fuel_products p ON t.product_id = p.id 
    WHERE p.product_name IN ('CNG', 'LPG', 'Natural Gas') 
    AND t.is_active = 1
")->fetchAll();

// Get suppliers for GAS
$suppliers = $pdo->query("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY supplier_name")->fetchAll();

// Get Chart of Accounts
$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1000' OR account_name LIKE '%Cash%' LIMIT 1");
$cash_account = $stmt->fetch();

$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '5000' OR account_name LIKE '%Fuel Purchase%' LIMIT 1");
$purchase_account = $stmt->fetch();

// Process GAS Receiving
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['receive_gas'])) {
    $receipt_no = 'GAS-RCV-' . date('YmdHis');
    $receipt_date = $_POST['receipt_date'];
    $supplier_id = $_POST['supplier_id'];
    $product_id = $_POST['product_id'];
    $tank_id = $_POST['tank_id'];
    $meter_reading_start = floatval($_POST['meter_reading_start']);
    $meter_reading_end = floatval($_POST['meter_reading_end']);
    $quantity = $meter_reading_end - $meter_reading_start;
    $unit_price = floatval($_POST['unit_price']);
    $payment_type = $_POST['payment_type'];
    
    $total_amount = $quantity * $unit_price;
    
    if($quantity <= 0) {
        $error = "Invalid quantity! Meter reading must show increase.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get supplier info
            $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
            $stmt->execute([$supplier_id]);
            $supplier = $stmt->fetch();
            
            // Insert into fuel_receivings (using same table with GAS specific fields)
            $stmt = $pdo->prepare("
                INSERT INTO fuel_receivings (
                    receipt_no, receipt_date, supplier_id, supplier_name, 
                    product_id, tank_id, 
                    expected_quantity, actual_quantity, shortage, 
                    unit_price, total_amount, 
                    payment_status, paid_amount, due_amount, 
                    status, approved_by,
                    meter_reading_start, meter_reading_end, is_gas_receiving
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, ?, ?, 1)
            ");
            
            $shortage = 0; // No shortage for GAS, actual = expected
            $paid_amount = ($payment_type == 'cash') ? $total_amount : 0;
            $due_amount = ($payment_type == 'credit') ? $total_amount : 0;
            $payment_status = ($payment_type == 'cash') ? 'paid' : 'pending';
            
            $stmt->execute([
                $receipt_no, $receipt_date, $supplier_id, $supplier['supplier_name'],
                $product_id, $tank_id,
                $quantity, $quantity, $shortage,
                $unit_price, $total_amount,
                $payment_status, $paid_amount, $due_amount,
                $user['id'],
                $meter_reading_start, $meter_reading_end
            ]);
            $receiving_id = $pdo->lastInsertId();
            
            // Update tank stock
            $stmt = $pdo->prepare("UPDATE tanks SET current_stock_liters = current_stock_liters + ? WHERE id = ?");
            $stmt->execute([$quantity, $tank_id]);
            
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
            $stmt = $pdo->prepare("
                INSERT INTO stock_ledger (product_id, tank_id, transaction_type, reference_no, in_quantity, balance_quantity, unit_cost) 
                VALUES (?, ?, 'receiving', ?, ?, ?, ?)
            ");
            $stmt->execute([$product_id, $tank_id, $receipt_no, $quantity, $current_stock, $unit_price]);
            
            // Accounting entry
            $voucher_no = 'GASPURCH-' . date('YmdHis');
            
            if($payment_type == 'cash') {
                $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'payment', ?, ?, ?, 'approved')");
                $stmt->execute([$voucher_no, $receipt_date, "GAS purchase from {$supplier['supplier_name']} - $receipt_no (Cash)", $user['id']]);
                $voucher_id = $pdo->lastInsertId();
                
                if($purchase_account && $cash_account) {
                    $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                        (?, ?, ?, ?, ?),
                        (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $voucher_id, $purchase_account['id'], $total_amount, 0, "GAS purchase - $receipt_no",
                        $voucher_id, $cash_account['id'], 0, $total_amount, "Cash payment to {$supplier['supplier_name']}"
                    ]);
                }
            } else {
                // Credit purchase
                $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '2000' OR account_name LIKE '%Accounts Payable%' LIMIT 1");
                $stmt->execute();
                $ap_account = $stmt->fetch();
                
                $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'journal', ?, ?, ?, 'approved')");
                $stmt->execute([$voucher_no, $receipt_date, "Credit GAS purchase from {$supplier['supplier_name']} - $receipt_no", $user['id']]);
                $voucher_id = $pdo->lastInsertId();
                
                if($purchase_account && $ap_account) {
                    $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                        (?, ?, ?, ?, ?),
                        (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $voucher_id, $purchase_account['id'], $total_amount, 0, "GAS purchase - $receipt_no",
                        $voucher_id, $ap_account['id'], 0, $total_amount, "Accounts Payable to {$supplier['supplier_name']}"
                    ]);
                }
            }
            
            $pdo->commit();
            
            $_SESSION['last_gas_receiving'] = [
                'receipt_no' => $receipt_no,
                'receipt_date' => $receipt_date,
                'supplier_name' => $supplier['supplier_name'],
                'meter_reading_start' => $meter_reading_start,
                'meter_reading_end' => $meter_reading_end,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'total_amount' => $total_amount,
                'payment_type' => $payment_type
            ];
            
            $success = "✅ GAS received successfully! Receipt: $receipt_no";
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Get receiving history
$receivings = $pdo->query("
    SELECT fr.*, p.product_name, t.tank_name, s.supplier_name 
    FROM fuel_receivings fr 
    JOIN fuel_products p ON fr.product_id = p.id 
    JOIN tanks t ON fr.tank_id = t.id 
    LEFT JOIN suppliers s ON fr.supplier_id = s.id 
    WHERE fr.is_gas_receiving = 1 
    ORDER BY fr.receipt_date DESC 
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
    <title>GAS Receiving Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .stats-card {
            background: linear-gradient(135deg, #17a2b8 0%, #117a8b 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s;
        }
        .stats-card:hover { transform: translateY(-5px); }
        .stats-card i { font-size: 40px; opacity: 0.5; float: right; }
        .meter-box {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            margin: 10px 0;
        }
        .badge-gas { background: #17a2b8; color: white; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-gas-pump"></i> GAS Receiving Management</h2>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <!-- Statistics -->
            <div class="row">
                <div class="col-md-4">
                    <div class="stats-card">
                        <i class="fas fa-file-invoice"></i>
                        <h3><?php echo count($receivings); ?></h3>
                        <p>Total GAS Receipts</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-tachometer-alt"></i>
                        <h3><?php 
                            $total_quantity = array_sum(array_column($receivings, 'actual_quantity'));
                            echo number_format($total_quantity, 2) . ' L';
                        ?></h3>
                        <p>Total GAS Received</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php 
                            $total_amount = array_sum(array_column($receivings, 'total_amount'));
                            echo number_format($total_amount, 2);
                        ?></h3>
                        <p>Total Amount</p>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-5">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-plus-circle"></i> Receive GAS</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Date *</label>
                                            <input type="date" name="receipt_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Supplier *</label>
                                            <select name="supplier_id" class="form-control" required>
                                                <option value="">-- Select --</option>
                                                <?php foreach($suppliers as $sup): ?>
                                                    <option value="<?php echo $sup['id']; ?>">
                                                        <?php echo $sup['supplier_name']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>GAS Type *</label>
                                            <select name="product_id" class="form-control" required>
                                                <option value="">-- Select --</option>
                                                <?php foreach($gas_products as $p): ?>
                                                    <option value="<?php echo $p['id']; ?>"><?php echo $p['product_name']; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Tank *</label>
                                            <select name="tank_id" class="form-control" required>
                                                <option value="">-- Select --</option>
                                                <?php foreach($gas_tanks as $t): ?>
                                                    <option value="<?php echo $t['id']; ?>">
                                                        <?php echo $t['tank_name']; ?> (Current: <?php echo number_format($t['current_stock_liters'], 0); ?> L)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Meter Readings -->
                                <div class="meter-box">
                                    <h6><i class="fas fa-tachometer-alt"></i> Meter Readings</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label>Starting Meter</label>
                                                <input type="number" name="meter_reading_start" id="meter_start" class="form-control" step="0.01" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label>Ending Meter</label>
                                                <input type="number" name="meter_reading_end" id="meter_end" class="form-control" step="0.01" required oninput="calculateQuantity()">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="alert alert-info">
                                        <strong>Quantity:</strong> <span id="quantity_display">0.00</span> Liters
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label>Unit Price (<?php echo $currency; ?>/L) *</label>
                                    <input type="number" name="unit_price" id="unit_price" class="form-control" step="0.01" required oninput="calculateTotal()">
                                </div>
                                <div class="mb-3">
                                    <label>Total Amount (<?php echo $currency; ?>)</label>
                                    <input type="text" id="total_amount_display" class="form-control" readonly>
                                </div>
                                <div class="mb-3">
                                    <label>Payment Type</label>
                                    <select name="payment_type" class="form-control" required>
                                        <option value="cash">Cash Payment</option>
                                        <option value="credit">Credit Purchase</option>
                                    </select>
                                </div>
                                <button type="submit" name="receive_gas" class="btn btn-primary w-100">
                                    <i class="fas fa-save"></i> Receive GAS
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-7">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="fas fa-history"></i> GAS Receiving History</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="receivingsTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Date</th>
                                            <th>Receipt No</th>
                                            <th>Supplier</th>
                                            <th>Product</th>
                                            <th class="text-end">Quantity</th>
                                            <th class="text-end">Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($receivings as $r): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($r['receipt_date'])); ?></td>
                                            <td><?php echo $r['receipt_no']; ?></td>
                                            <td><?php echo $r['supplier_name']; ?></td>
                                            <td><span class="badge badge-gas"><?php echo $r['product_name']; ?></span></td>
                                            <td class="text-end"><?php echo number_format($r['actual_quantity'], 2); ?> L</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($r['total_amount'], 2); ?></td>
                                            <td>
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
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#receivingsTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
        
        function calculateQuantity() {
            let start = parseFloat(document.getElementById('meter_start').value) || 0;
            let end = parseFloat(document.getElementById('meter_end').value) || 0;
            let quantity = end - start;
            if(quantity < 0) quantity = 0;
            document.getElementById('quantity_display').innerText = quantity.toFixed(2);
            calculateTotal();
        }
        
        function calculateTotal() {
            let start = parseFloat(document.getElementById('meter_start').value) || 0;
            let end = parseFloat(document.getElementById('meter_end').value) || 0;
            let quantity = end - start;
            if(quantity < 0) quantity = 0;
            let price = parseFloat(document.getElementById('unit_price').value) || 0;
            let total = quantity * price;
            document.getElementById('total_amount_display').value = total.toFixed(2);
        }
    </script>
</body>
</html>
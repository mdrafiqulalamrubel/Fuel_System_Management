<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Date range filters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

$products = $pdo->query("SELECT * FROM fuel_products WHERE is_active = 1")->fetchAll();
$tanks = $pdo->query("SELECT t.*, p.product_name FROM tanks t JOIN fuel_products p ON t.product_id = p.id")->fetchAll();

// Process fuel receiving
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['receive_fuel'])) {
    $receipt_no = 'RCV-' . date('YmdHis');
    $receipt_date = $_POST['receipt_date'];
    $supplier_name = $_POST['supplier_name'];
    $tanker_no = $_POST['tanker_no'];
    $challan_no = $_POST['challan_no'];
    $product_id = $_POST['product_id'];
    $tank_id = $_POST['tank_id'];
    $expected_quantity = $_POST['expected_quantity'];
    $actual_quantity = $_POST['actual_quantity'];
    $freight_cost = $_POST['freight_cost'];
    $freight_deduction = $_POST['freight_deduction'];
    $unit_price = $_POST['unit_price'];
    
    $shortage = $expected_quantity - $actual_quantity;
    $total_amount = $actual_quantity * $unit_price;
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO fuel_receivings (receipt_no, receipt_date, supplier_name, tanker_no, challan_no, product_id, tank_id, expected_quantity, actual_quantity, shortage, freight_cost, freight_deduction, unit_price, total_amount, status, approved_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?)");
        $stmt->execute([$receipt_no, $receipt_date, $supplier_name, $tanker_no, $challan_no, $product_id, $tank_id, $expected_quantity, $actual_quantity, $shortage, $freight_cost, $freight_deduction, $unit_price, $total_amount, $user['id']]);
        
        $stmt = $pdo->prepare("UPDATE tanks SET current_stock_liters = current_stock_liters + ? WHERE id = ?");
        $stmt->execute([$actual_quantity, $tank_id]);
        
        $stmt = $pdo->prepare("SELECT current_stock_liters FROM tanks WHERE id = ?");
        $stmt->execute([$tank_id]);
        $current_stock = $stmt->fetch()['current_stock_liters'];
        
        $stmt = $pdo->prepare("INSERT INTO stock_ledger (product_id, tank_id, transaction_type, reference_no, in_quantity, balance_quantity, unit_cost) VALUES (?, ?, 'receiving', ?, ?, ?, ?)");
        $stmt->execute([$product_id, $tank_id, $receipt_no, $actual_quantity, $current_stock, $unit_price]);
        
        $voucher_no = 'PURCH-' . date('YmdHis');
        $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'payment', ?, ?, ?, 'approved')");
        $stmt->execute([$voucher_no, $receipt_date, "Fuel purchase from $supplier_name - $receipt_no", $user['id']]);
        
        $pdo->commit();
        
        $_SESSION['last_receiving'] = [
            'receipt_no' => $receipt_no,
            'receipt_date' => $receipt_date,
            'supplier_name' => $supplier_name,
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
            'total_amount' => $total_amount
        ];
        
        $success = "Fuel received successfully! Receipt: $receipt_no";
        echo "<script>window.open('print_receiving.php', '_blank'); setTimeout(function(){ window.location.href='fuel_receiving.php'; }, 1000);</script>";
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Get receivings with date filter
$stmt = $pdo->prepare("
    SELECT fr.*, p.product_name, t.tank_name 
    FROM fuel_receivings fr 
    JOIN fuel_products p ON fr.product_id = p.id 
    JOIN tanks t ON fr.tank_id = t.id 
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
        COALESCE(SUM(shortage), 0) as total_shortage
    FROM fuel_receivings 
    WHERE DATE(receipt_date) BETWEEN ? AND ?
");
$stmt->execute([$from_date, $to_date]);
$summary = $stmt->fetch();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Receiving Management</title>
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
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .stats-card i {
            font-size: 40px;
            opacity: 0.5;
            float: right;
        }
        .btn-excel {
            background: #28a745;
            color: white;
        }
        .btn-excel:hover {
            background: #1e7e34;
            color: white;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .text-end {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        
        /* Print Styles - Clean Report */
        @media print {
            .sidebar, .no-print, .stats-card, .card-header .btn, 
            .dataTables_length, .dataTables_filter, .dataTables_paginate,
            form, .col-md-5, .btn, .card-header .d-flex, .print-single {
                display: none !important;
            }
            .col-md-7, .col-md-12 {
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .main-content {
                margin: 0 !important;
                padding: 10px !important;
            }
            .card {
                border: none !important;
                box-shadow: none !important;
            }
            .card-header {
                background: none !important;
                color: black !important;
                border-bottom: 1px solid #000 !important;
            }
            .table th, .table td {
                border: 1px solid #000 !important;
            }
            .table th {
                background: none !important;
                color: black !important;
            }
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 20px;
            }
        }
        .print-header {
            display: none;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- Print Header (visible only when printing) -->
            <div class="print-header">
                <h2><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h2>
                <h4>Fuel Receiving Report</h4>
                <p>Period: <?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                <p>Generated on: <?php echo date('d/m/Y h:i:s A'); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap no-print">
                <h2><i class="fas fa-truck"></i> Fuel Receiving Management</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <button onclick="exportToExcel()" class="btn btn-excel">
                        <i class="fas fa-file-excel"></i> Export to Excel
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger no-print"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success no-print"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="row no-print">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-receipt"></i>
                        <h3><?php echo $summary['total_receipts']; ?></h3>
                        <p>Total Receipts</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-oil-can"></i>
                        <h3><?php echo number_format($summary['total_liters'], 2); ?> L</h3>
                        <p>Total Liters Received</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($summary['total_amount'], 2); ?></h3>
                        <p>Total Amount</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3><?php echo number_format($summary['total_shortage'], 2); ?> L</h3>
                        <p>Total Shortage</p>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-5 no-print">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-plus-circle"></i> Receive Fuel</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="row">
                                    <div class="col-md-6">
                                        <label>Date</label>
                                        <input type="date" name="receipt_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label>Supplier</label>
                                        <input type="text" name="supplier_name" class="form-control" required>
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
                                        <label>Product</label>
                                        <select name="product_id" id="product_id" class="form-control" required>
                                            <option value="">Select</option>
                                            <?php foreach($products as $p){ echo "<option value='{$p['id']}'>{$p['product_name']}</option>"; } ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label>Tank</label>
                                        <select name="tank_id" id="tank_id" class="form-control" required>
                                            <option value="">Select</option>
                                            <?php foreach($tanks as $t){ echo "<option value='{$t['id']}'>{$t['tank_name']} ({$t['product_name']})</option>"; } ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-md-6">
                                        <label>Expected (L)</label>
                                        <input type="number" name="expected_quantity" id="exp_qty" class="form-control" step="0.01" required oninput="calcShortage()">
                                    </div>
                                    <div class="col-md-6">
                                        <label>Actual (L)</label>
                                        <input type="number" name="actual_quantity" id="act_qty" class="form-control" step="0.01" required oninput="calcShortage()">
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <label>Shortage (L)</label>
                                    <input type="text" id="shortage" class="form-control" readonly>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-md-6">
                                        <label>Freight Cost (<?php echo $currency; ?>)</label>
                                        <input type="number" name="freight_cost" class="form-control" step="0.01" value="0">
                                    </div>
                                    <div class="col-md-6">
                                        <label>Freight Deduction (<?php echo $currency; ?>)</label>
                                        <input type="number" name="freight_deduction" class="form-control" step="0.01" value="0">
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <label>Unit Price (<?php echo $currency; ?>/L)</label>
                                    <input type="number" name="unit_price" id="unit_price" class="form-control" step="0.01" required oninput="calcTotal()">
                                </div>
                                <div class="mt-2">
                                    <label>Total Amount (<?php echo $currency; ?>)</label>
                                    <input type="text" id="total_amt" class="form-control" readonly>
                                </div>
                                <button type="submit" name="receive_fuel" class="btn btn-primary w-100 mt-3">
                                    <i class="fas fa-save"></i> Receive Fuel
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-7">
                    <!-- Date Range Filter -->
                    <div class="card mb-3 no-print">
                        <div class="card-header bg-info text-white">
                            <h5><i class="fas fa-calendar-alt"></i> Filter by Date Range</h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3">
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
                                    <button type="submit" class="btn btn-info w-100">
                                        <i class="fas fa-search"></i> Filter
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Receiving History Table -->
                    <div class="card">
                        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                            <h5><i class="fas fa-history"></i> Receiving History</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="historyTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Date</th>
                                            <th>Receipt No</th>
                                            <th>Supplier</th>
                                            <th>Product</th>
                                            <th class="text-end">Actual (L)</th>
                                            <th class="text-end">Shortage (L)</th>
                                            <th class="text-end">Amount (<?php echo $currency; ?>)</th>
                                            <th class="text-center no-print">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(!empty($receivings)): ?>
                                            <?php foreach($receivings as $r): ?>
                                            <tr>
                                                <td><?php echo date('d-m-Y', strtotime($r['receipt_date'])); ?></td>
                                                <td><?php echo $r['receipt_no']; ?></td>
                                                <td><?php echo htmlspecialchars($r['supplier_name']); ?></td>
                                                <td><?php echo $r['product_name']; ?></td>
                                                <td class="text-end"><?php echo number_format($r['actual_quantity'], 2); ?> L</td>
                                                <td class="text-end text-danger"><?php echo number_format($r['shortage'], 2); ?> L</td>
                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($r['total_amount'], 2); ?></td>
                                                <td class="text-center no-print">
                                                    <button class="btn btn-sm btn-info print-single" data-receipt="<?php echo $r['receipt_no']; ?>">
                                                        <i class="fas fa-print"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="text-center text-muted">No records found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="4" class="text-end">TOTAL:</td>
                                            <td class="text-end"><?php echo number_format($summary['total_liters'], 2); ?> L</td>
                                            <td class="text-end"><?php echo number_format($summary['total_shortage'], 2); ?> L</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($summary['total_amount'], 2); ?></td>
                                            <td class="no-print"></td>
                                        </tr>
                                    </tfoot>
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
            $('#historyTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
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
        
        // Print single receipt
        $('.print-single').click(function() {
            let receiptNo = $(this).data('receipt');
            window.open('print_receiving.php?receipt_no=' + receiptNo, '_blank', 'width=600,height=700');
        });
        
        // Export to Excel
        function exportToExcel() {
            let from_date = '<?php echo $from_date; ?>';
            let to_date = '<?php echo $to_date; ?>';
            window.location.href = 'export_receiving.php?from_date=' + from_date + '&to_date=' + to_date;
        }
    </script>
</body>
</html>
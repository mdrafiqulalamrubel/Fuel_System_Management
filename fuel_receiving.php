<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

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
        $success = "Fuel received successfully! Receipt: $receipt_no";
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

$receivings = $pdo->query("SELECT fr.*, p.product_name, t.tank_name FROM fuel_receivings fr JOIN fuel_products p ON fr.product_id = p.id JOIN tanks t ON fr.tank_id = t.id ORDER BY fr.receipt_date DESC LIMIT 50")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Receiving</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
</head>
<body>
    <?php include 'left_menu.php'; ?>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand">Fuel Receiving (Tanker)</span>
            <a href="dashboard.php" class="btn btn-light">Back</a>
        </div>
    </nav>
    
    <div class="container mt-3">
        <?php if($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
        <?php if($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
        
        <div class="row">
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header bg-primary text-white"><h5>Receive Fuel</h5></div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6"><label>Date</label><input type="date" name="receipt_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                                <div class="col-md-6"><label>Supplier</label><input type="text" name="supplier_name" class="form-control" required></div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-6"><label>Tanker No</label><input type="text" name="tanker_no" class="form-control"></div>
                                <div class="col-md-6"><label>Challan No</label><input type="text" name="challan_no" class="form-control"></div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-6"><label>Product</label><select name="product_id" id="product_id" class="form-control" required><option value="">Select</option><?php foreach($products as $p){ echo "<option value='{$p['id']}'>{$p['product_name']}</option>"; } ?></select></div>
                                <div class="col-md-6"><label>Tank</label><select name="tank_id" id="tank_id" class="form-control" required><option value="">Select</option><?php foreach($tanks as $t){ echo "<option value='{$t['id']}'>{$t['tank_name']} ({$t['product_name']})</option>"; } ?></select></div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-6"><label>Expected (L)</label><input type="number" name="expected_quantity" id="exp_qty" class="form-control" step="0.01" required oninput="calcShortage()"></div>
                                <div class="col-md-6"><label>Actual (L)</label><input type="number" name="actual_quantity" id="act_qty" class="form-control" step="0.01" required oninput="calcShortage()"></div>
                            </div>
                            <div class="mt-2"><label>Shortage (L)</label><input type="text" id="shortage" class="form-control" readonly></div>
                            <div class="row mt-2">
                                <div class="col-md-6"><label>Freight Cost</label><input type="number" name="freight_cost" class="form-control" step="0.01" value="0"></div>
                                <div class="col-md-6"><label>Freight Deduction</label><input type="number" name="freight_deduction" class="form-control" step="0.01" value="0"></div>
                            </div>
                            <div class="mt-2"><label>Unit Price (৳/L)</label><input type="number" name="unit_price" id="unit_price" class="form-control" step="0.01" required oninput="calcTotal()"></div>
                            <div class="mt-2"><label>Total Amount</label><input type="text" id="total_amt" class="form-control" readonly></div>
                            <button type="submit" name="receive_fuel" class="btn btn-primary w-100 mt-3">Receive Fuel</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header bg-info text-white"><h5>Receiving History</h5></div>
                    <div class="card-body">
                        <table class="table table-bordered" id="historyTable">
                            <thead><tr><th>Date</th><th>Receipt No</th><th>Supplier</th><th>Product</th><th>Actual</th><th>Shortage</th><th>Amount</th></tr></thead>
                            <tbody><?php foreach($receivings as $r){ echo "<tr><td>{$r['receipt_date']}</td><td>{$r['receipt_no']}</td><td>{$r['supplier_name']}</td><td>{$r['product_name']}</td><td>{$r['actual_quantity']}L</td><td>{$r['shortage']}L</td><td>৳".number_format($r['total_amount'],2)."</td></tr>"; } ?></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function(){$('#historyTable').DataTable();});
        function calcShortage(){ let exp=parseFloat($('#exp_qty').val())||0, act=parseFloat($('#act_qty').val())||0; $('#shortage').val((exp-act).toFixed(2)); calcTotal(); }
        function calcTotal(){ let act=parseFloat($('#act_qty').val())||0, price=parseFloat($('#unit_price').val())||0; $('#total_amt').val((act*price).toFixed(2)); }
    </script>
</body>
</html>
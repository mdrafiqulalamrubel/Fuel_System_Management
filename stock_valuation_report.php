<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$as_on = $_GET['as_on'] ?? date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT t.*, p.product_name, p.purchase_rate 
    FROM tanks t 
    JOIN fuel_products p ON t.product_id = p.id
    ORDER BY t.tank_name
");
$stmt->execute();
$tanks = $stmt->fetchAll();

$total_value = 0;
foreach($tanks as $t) {
    $total_value += $t['current_stock_liters'] * $t['purchase_rate'];
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stock Valuation Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>@media print{.no-print{display:none;}}</style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    <div class="container mt-3">
        <div class="no-print mb-3">
            <a href="reports.php" class="btn btn-secondary">Back</a>
            <button onclick="window.print()" class="btn btn-primary">Print</button>
            <form class="d-inline" method="GET"><input type="date" name="as_on" value="<?php echo $as_on; ?>"><button type="submit" class="btn btn-info">View</button></form>
        </div>
        
        <div class="text-center"><h3><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h3><h4>STOCK VALUATION REPORT</h4><h5>As on <?php echo date('d F Y', strtotime($as_on)); ?></h5></div>
        
        <div class="row mt-4">
            <div class="col-md-8"><div class="card"><div class="card-header bg-primary text-white">Stock Summary</div><div class="card-body"><table class="table table-bordered"><thead><tr><th>Tank</th><th>Product</th><th>Current Stock (L)</th><th>Unit Cost (BDT/L)</th><th>Total Value (BDT)</th></tr></thead><tbody><?php foreach($tanks as $t): ?><tr><td><?php echo $t['tank_name']; ?></td><td><?php echo $t['product_name']; ?></td><td><?php echo number_format($t['current_stock_liters'], 2); ?></td><td><?php echo number_format($t['purchase_rate'], 2); ?></td><td><?php echo number_format($t['current_stock_liters'] * $t['purchase_rate'], 2); ?></td></tr><?php endforeach; ?></tbody><tfoot><tr class="fw-bold"><td colspan="4">TOTAL</td><td><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($total_value, 2); ?></td></tr></tfoot></table></div></div></div>
            <div class="col-md-4"><div class="card"><div class="card-header bg-success text-white">Stock Value Chart</div><div class="card-body"><canvas id="stockChart"></canvas></div></div></div>
        </div>
    </div>
    <script>
        const ctx = document.getElementById('stockChart').getContext('2d');
        new Chart(ctx, { type: 'pie', data: { labels: <?php echo json_encode(array_column($tanks, 'product_name')); ?>, datasets: [{ data: <?php echo json_encode(array_map(fn($t)=>$t['current_stock_liters']*$t['purchase_rate'], $tanks)); ?> }] } });
    </script>
</body>
</html>
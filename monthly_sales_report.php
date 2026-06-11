<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$month = $_GET['month'] ?? date('Y-m');
$year = date('Y', strtotime($month));
$month_num = date('m', strtotime($month));

$stmt = $pdo->prepare("
    SELECT DATE(sale_date) as sale_date, 
           SUM(total_amount) as daily_total,
           SUM(quantity_liters) as daily_liters,
           COUNT(*) as daily_transactions
    FROM sales 
    WHERE YEAR(sale_date) = ? AND MONTH(sale_date) = ?
    GROUP BY DATE(sale_date)
    ORDER BY sale_date
");
$stmt->execute([$year, $month_num]);
$daily_sales = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT p.product_name, SUM(s.quantity_liters) as total_liters, SUM(s.total_amount) as total_amount
    FROM sales s JOIN fuel_products p ON s.product_id = p.id
    WHERE YEAR(s.sale_date) = ? AND MONTH(s.sale_date) = ?
    GROUP BY p.product_name
");
$stmt->execute([$year, $month_num]);
$product_sales = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT SUM(total_amount) as total_sales, SUM(quantity_liters) as total_liters, 
           SUM(vat_amount) as total_vat, SUM(tax_amount) as total_tax,
           SUM(CASE WHEN sale_type='cash' THEN total_amount ELSE 0 END) as cash_sales,
           SUM(CASE WHEN sale_type='credit' THEN total_amount ELSE 0 END) as credit_sales
    FROM sales WHERE YEAR(sale_date) = ? AND MONTH(sale_date) = ?
");
$stmt->execute([$year, $month_num]);
$summary = $stmt->fetch();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Monthly Sales Report</title>
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
            <form class="d-inline" method="GET"><input type="month" name="month" value="<?php echo $month; ?>"><button type="submit" class="btn btn-info">View</button></form>
        </div>
        
        <div class="report-header text-center">
            <h3><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h3>
            <h4>MONTHLY SALES REPORT</h4>
            <h5><?php echo date('F Y', strtotime($month)); ?></h5>
        </div>
        
        <div class="row mt-4">
            <div class="col-md-3"><div class="card bg-success text-white"><div class="card-body"><h6>Total Sales</h6><h4><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($summary['total_sales'] ?? 0, 2); ?></h4></div></div></div>
            <div class="col-md-3"><div class="card bg-info text-white"><div class="card-body"><h6>Total Liters</h6><h4><?php echo number_format($summary['total_liters'] ?? 0, 2); ?> L</h4></div></div></div>
            <div class="col-md-3"><div class="card bg-primary text-white"><div class="card-body"><h6>Average Daily</h6><h4><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format(($summary['total_sales'] ?? 0) / max(1, count($daily_sales)), 2); ?></h4></div></div></div>
            <div class="col-md-3"><div class="card bg-warning text-white"><div class="card-body"><h6>VAT Collected</h6><h4><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($summary['total_vat'] ?? 0, 2); ?></h4></div></div></div>
        </div>
        
        <div class="row mt-4">
            <div class="col-md-6"><canvas id="salesChart"></canvas></div>
            <div class="col-md-6"><canvas id="productChart"></canvas></div>
        </div>
        
        <div class="card mt-4"><div class="card-header bg-dark text-white">Daily Breakdown</div><div class="card-body"><table class="table table-bordered" id="dailyTable"><thead><tr><th>Date</th><th>Liters</th><th>Transactions</th><th>Amount</th></tr></thead><tbody><?php foreach($daily_sales as $day): ?><tr><td><?php echo date('d M Y', strtotime($day['sale_date'])); ?></td><td><?php echo number_format($day['daily_liters'], 2); ?> L</td><td><?php echo $day['daily_transactions']; ?></td><td><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($day['daily_total'], 2); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    </div>
    <script>
        const ctx1 = document.getElementById('salesChart').getContext('2d');
        new Chart(ctx1, { type: 'line', data: { labels: <?php echo json_encode(array_map(fn($d)=>date('d M', strtotime($d['sale_date'])), $daily_sales)); ?>, datasets: [{ label: 'Daily Sales (BDT)', data: <?php echo json_encode(array_column($daily_sales, 'daily_total')); ?>, borderColor: 'green' }] } });
        const ctx2 = document.getElementById('productChart').getContext('2d');
        new Chart(ctx2, { type: 'pie', data: { labels: <?php echo json_encode(array_column($product_sales, 'product_name')); ?>, datasets: [{ data: <?php echo json_encode(array_column($product_sales, 'total_amount')); ?> }] } });
    </script>
</body>
</html>
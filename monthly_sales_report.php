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
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Sales Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s;
        }
        .stats-card i {
            font-size: 40px;
            opacity: 0.5;
            float: right;
        }
        @media print {
            .sidebar, .no-print, .stats-card, .btn, canvas {
                display: none !important;
            }
            .main-content {
                margin: 0 !important;
                padding: 10px !important;
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
            <!-- Print Header -->
            <div class="print-header">
                <h2><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h2>
                <h4>Monthly Sales Report</h4>
                <p>Month: <?php echo date('F Y', strtotime($month)); ?></p>
                <p>Generated on: <?php echo date('d/m/Y h:i:s A'); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap no-print">
                <h2><i class="fas fa-chart-line"></i> Monthly Sales Report</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="reports.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- Filter -->
            <div class="card mb-4 no-print">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-calendar-alt"></i> Select Month</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label>Month</label>
                            <input type="month" name="month" class="form-control" value="<?php echo $month; ?>">
                        </div>
                        <div class="col-md-2">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-info w-100">
                                <i class="fas fa-search"></i> View
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($summary['total_sales'] ?? 0, 2); ?></h3>
                        <p>Total Sales</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-oil-can"></i>
                        <h3><?php echo number_format($summary['total_liters'] ?? 0, 2); ?> L</h3>
                        <p>Total Liters</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format(($summary['total_sales'] ?? 0) / max(1, count($daily_sales)), 2); ?></h3>
                        <p>Average Daily</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-receipt"></i>
                        <h3><?php echo $summary['total_transactions'] ?? 0; ?></h3>
                        <p>Transactions</p>
                    </div>
                </div>
            </div>
            
            <!-- Charts -->
            <div class="row mb-4 no-print">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-chart-line"></i> Daily Sales Trend</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="salesChart" height="250"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="fas fa-chart-pie"></i> Product-wise Sales</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="productChart" height="250"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Daily Breakdown Table -->
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-calendar-day"></i> Daily Breakdown</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="dailyTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Date</th>
                                    <th class="text-end">Liters</th>
                                    <th class="text-end">Transactions</th>
                                    <th class="text-end">Amount (<?php echo $currency; ?>)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($daily_sales as $day): ?>
                                <tr>
                                    <td><?php echo date('d M Y', strtotime($day['sale_date'])); ?></td>
                                    <td class="text-end"><?php echo number_format($day['daily_liters'], 2); ?> L</td
                                    <td class="text-end"><?php echo $day['daily_transactions']; ?></td
                                    <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($day['daily_total'], 2); ?></td
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td class="text-end">TOTAL:</td
                                    <td class="text-end"><?php echo number_format($summary['total_liters'] ?? 0, 2); ?> L</td
                                    <td class="text-end"><?php echo $summary['total_transactions'] ?? 0; ?></td
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($summary['total_sales'] ?? 0, 2); ?></td
                                </tr>
                            </tfoot>
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
            $('#dailyTable').DataTable({
                order: [[0, 'asc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
        
        // Sales Chart
        const ctx1 = document.getElementById('salesChart').getContext('2d');
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(fn($d)=>date('d M', strtotime($d['sale_date'])), $daily_sales)); ?>,
                datasets: [{
                    label: 'Daily Sales (<?php echo $currency; ?>)',
                    data: <?php echo json_encode(array_column($daily_sales, 'daily_total')); ?>,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: { responsive: true, maintainAspectRatio: true }
        });
        
        // Product Chart
        const ctx2 = document.getElementById('productChart').getContext('2d');
        new Chart(ctx2, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_column($product_sales, 'product_name')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($product_sales, 'total_amount')); ?>,
                    backgroundColor: ['#667eea', '#11998e', '#f093fb', '#4facfe', '#f5576c']
                }]
            },
            options: { responsive: true, maintainAspectRatio: true }
        });
    </script>
</body>
</html>
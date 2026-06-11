<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();

// Get dashboard statistics
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total_sales FROM sales WHERE DATE(sale_date) = ?");
$stmt->execute([$today]);
$todaySales = $stmt->fetch()['total_sales'] ?? 0;

$stmt = $pdo->prepare("SELECT SUM(current_stock_liters) as total_stock FROM tanks");
$stmt->execute();
$totalStock = $stmt->fetch()['total_stock'] ?? 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as pending_leakage FROM leakage_adjustments WHERE status = 'pending'");
$stmt->execute();
$pendingLeakage = $stmt->fetch()['pending_leakage'];

$stmt = $pdo->prepare("SELECT SUM(amount) as total_rent_collected FROM rent_payments WHERE MONTH(payment_date) = MONTH(CURDATE())");
$stmt->execute();
$monthlyRent = $stmt->fetch()['total_rent_collected'] ?? 0;

// Get monthly sales for chart
$stmt = $pdo->prepare("SELECT DATE(sale_date) as date, SUM(total_amount) as total FROM sales WHERE MONTH(sale_date) = MONTH(CURDATE()) GROUP BY DATE(sale_date) ORDER BY sale_date");
$stmt->execute();
$monthlySales = $stmt->fetchAll();

// Get product sales
$stmt = $pdo->prepare("SELECT p.product_name, SUM(s.quantity_liters) as liters FROM sales s JOIN fuel_products p ON s.product_id = p.id WHERE MONTH(s.sale_date) = MONTH(CURDATE()) GROUP BY p.product_name");
$stmt->execute();
$productSales = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Fuel Station Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .stat-icon {
            font-size: 40px;
            float: right;
            opacity: 0.3;
        }
        
        .stat-title {
            font-size: 14px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: #333;
            margin-top: 10px;
        }
        
        .card-custom {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .card-custom h5 {
            margin-bottom: 20px;
            color: #333;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- Welcome Banner -->
            <div class="card-custom mb-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h3>Welcome back, <?php echo $user['full_name']; ?>!</h3>
                        <p>Here's what's happening with your fuel station today.</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <i class="fas fa-gas-pump" style="font-size: 60px; opacity: 0.5;"></i>
                    </div>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stat-card" onclick="window.location.href='daily_sales_report.php'">
                        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="stat-title">Today's Sales</div>
                        <div class="stat-value">৳ <?php echo number_format($todaySales, 2); ?></div>
                        <small class="text-muted">Updated just now</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" onclick="window.location.href='inventory.php'">
                        <div class="stat-icon"><i class="fas fa-oil-can"></i></div>
                        <div class="stat-title">Total Stock</div>
                        <div class="stat-value"><?php echo number_format($totalStock, 2); ?> L</div>
                        <small class="text-muted">Across all tanks</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" onclick="window.location.href='leakage.php'">
                        <div class="stat-icon"><i class="fas fa-tint"></i></div>
                        <div class="stat-title">Pending Leakage</div>
                        <div class="stat-value"><?php echo $pendingLeakage; ?></div>
                        <small class="text-danger">Requires attention</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" onclick="window.location.href='rental.php'">
                        <div class="stat-icon"><i class="fas fa-building"></i></div>
                        <div class="stat-title">Monthly Rent</div>
                        <div class="stat-value">৳ <?php echo number_format($monthlyRent, 2); ?></div>
                        <small class="text-muted">Collected this month</small>
                    </div>
                </div>
            </div>
            
            <!-- Charts Row -->
            <div class="row">
                <div class="col-md-8">
                    <div class="card-custom">
                        <h5><i class="fas fa-chart-line"></i> Sales Trend (This Month)</h5>
                        <canvas id="salesChart" height="300"></canvas>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card-custom">
                        <h5><i class="fas fa-chart-pie"></i> Product Sales</h5>
                        <canvas id="productChart" height="250"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions Row -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card-custom">
                        <h5><i class="fas fa-bolt"></i> Quick Actions</h5>
                        <div class="row">
                            <div class="col-md-2 col-6 mb-2">
                                <a href="pos.php" class="btn btn-primary w-100">
                                    <i class="fas fa-shopping-cart"></i><br>New Sale
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-2">
                                <a href="fuel_receiving.php" class="btn btn-success w-100">
                                    <i class="fas fa-truck"></i><br>Receive Fuel
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-2">
                                <a href="leakage.php" class="btn btn-warning w-100">
                                    <i class="fas fa-tint"></i><br>Stock Check
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-2">
                                <a href="payroll.php?tab=attendance" class="btn btn-info w-100">
                                    <i class="fas fa-clock"></i><br>Attendance
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-2">
                                <a href="rental.php" class="btn btn-danger w-100">
                                    <i class="fas fa-building"></i><br>Rent Collect
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-2">
                                <a href="reports.php" class="btn btn-secondary w-100">
                                    <i class="fas fa-chart-bar"></i><br>Reports
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sales Chart
        const salesCtx = document.getElementById('salesChart').getContext('2d');
        const salesDates = <?php echo json_encode(array_map(fn($s) => date('d M', strtotime($s['date'])), $monthlySales)); ?>;
        const salesAmounts = <?php echo json_encode(array_column($monthlySales, 'total')); ?>;
        
        new Chart(salesCtx, {
            type: 'line',
            data: {
                labels: salesDates,
                datasets: [{
                    label: 'Daily Sales (BDT)',
                    data: salesAmounts,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                }
            }
        });
        
        // Product Chart
        const productCtx = document.getElementById('productChart').getContext('2d');
        const productNames = <?php echo json_encode(array_column($productSales, 'product_name')); ?>;
        const productLiters = <?php echo json_encode(array_column($productSales, 'liters')); ?>;
        
        new Chart(productCtx, {
            type: 'doughnut',
            data: {
                labels: productNames,
                datasets: [{
                    data: productLiters,
                    backgroundColor: ['#667eea', '#764ba2', '#f093fb', '#4facfe', '#43e97b']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true
            }
        });
    </script>
</body>
</html>
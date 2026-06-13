<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();

// Get dashboard statistics
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total_sales FROM sales WHERE DATE(sale_date) = ?");
$stmt->execute([$today]);
$todaySales = $stmt->fetch()['total_sales'] ?? 0;

$stmt = $pdo->prepare("SELECT SUM(total_amount) as total_sales FROM sales WHERE MONTH(sale_date) = MONTH(CURDATE())");
$stmt->execute();
$monthlySalesTotal = $stmt->fetch()['total_sales'] ?? 0;

$stmt = $pdo->prepare("SELECT SUM(current_stock_liters) as total_stock FROM tanks");
$stmt->execute();
$totalStock = $stmt->fetch()['total_stock'] ?? 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as pending_leakage FROM leakage_adjustments WHERE status = 'pending'");
$stmt->execute();
$pendingLeakage = $stmt->fetch()['pending_leakage'];

$stmt = $pdo->prepare("SELECT SUM(amount) as total_rent_collected FROM rent_payments WHERE MONTH(payment_date) = MONTH(CURDATE())");
$stmt->execute();
$monthlyRent = $stmt->fetch()['total_rent_collected'] ?? 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as total_employees FROM employees WHERE is_active = 1");
$stmt->execute();
$totalEmployees = $stmt->fetch()['total_employees'];

$stmt = $pdo->prepare("SELECT COUNT(*) as total_customers FROM customers");
$stmt->execute();
$totalCustomers = $stmt->fetch()['total_customers'];

// Get monthly sales for chart (last 7 days for better view)
$stmt = $pdo->prepare("SELECT DATE(sale_date) as date, SUM(total_amount) as total FROM sales WHERE DATE(sale_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(sale_date) ORDER BY sale_date");
$stmt->execute();
$weeklySales = $stmt->fetchAll();

// Get product sales for chart
$stmt = $pdo->prepare("SELECT p.product_name, SUM(s.quantity_liters) as liters, SUM(s.total_amount) as amount FROM sales s JOIN fuel_products p ON s.product_id = p.id WHERE MONTH(s.sale_date) = MONTH(CURDATE()) GROUP BY p.product_name ORDER BY amount DESC LIMIT 5");
$stmt->execute();
$productSales = $stmt->fetchAll();

// Get recent sales for activity feed
$stmt = $pdo->prepare("SELECT s.*, p.product_name, u.full_name as operator FROM sales s JOIN fuel_products p ON s.product_id = p.id JOIN users u ON s.operator_id = u.id ORDER BY s.sale_date DESC LIMIT 5");
$stmt->execute();
$recentSales = $stmt->fetchAll();

// Get low stock alerts
$stmt = $pdo->prepare("SELECT t.tank_name, p.product_name, t.current_stock_liters FROM tanks t JOIN fuel_products p ON t.product_id = p.id WHERE t.current_stock_liters < 500 ORDER BY t.current_stock_liters ASC LIMIT 5");
$stmt->execute();
$lowStockAlerts = $stmt->fetchAll();

// Get today's sale count
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM sales WHERE DATE(sale_date) = CURDATE()");
$stmt->execute();
$todayTransactions = $stmt->fetch()['count'];

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Fuel Station Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }
        
        .stat-card.primary::before { background: #667eea; }
        .stat-card.success::before { background: #28a745; }
        .stat-card.warning::before { background: #ffc107; }
        .stat-card.info::before { background: #17a2b8; }
        .stat-card.danger::before { background: #dc3545; }
        
        .stat-icon {
            font-size: 40px;
            float: right;
            opacity: 0.3;
        }
        
        .stat-title {
            font-size: 13px;
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
            border-left: 4px solid #667eea;
            padding-left: 15px;
        }
        
        .activity-item {
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
        }
        
        .activity-item:hover {
            background: #f8f9fa;
            padding-left: 10px;
        }
        
        .activity-icon {
            width: 35px;
            height: 35px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
        }
        
        .badge-custom {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .chart-container {
            position: relative;
            height: 280px;
            width: 100%;
        }
        
        .quick-action-btn {
            transition: all 0.3s;
            padding: 12px;
            border-radius: 12px;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-3px);
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            padding: 25px 30px;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }
        
        @media (max-width: 768px) {
            .stat-value {
                font-size: 22px;
            }
            .chart-container {
                height: 220px;
            }
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- Welcome Banner -->
            <div class="welcome-banner">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h3 style="color: white; margin-bottom: 10px;">Welcome back, <?php echo $user['full_name']; ?>!</h3>
                        <p style="color: rgba(255,255,255,0.9); margin-bottom: 0;">
                            <i class="fas fa-calendar-alt"></i> <?php echo date('l, d F Y'); ?> | 
                            <i class="fas fa-clock"></i> <span id="currentTime"></span>
                        </p>
                    </div>
                    <div class="col-md-4 text-end">
                        <i class="fas fa-gas-pump" style="font-size: 70px; opacity: 0.3; color: white;"></i>
                    </div>
                </div>
            </div>
            
            <!-- Statistics Cards Row 1 -->
            <div class="row">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card primary" onclick="window.location.href='daily_sales_report.php'">
                        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="stat-title">Today's Sales</div>
                        <div class="stat-value"><?php echo $currency; ?> <?php echo number_format($todaySales, 2); ?></div>
                        <small class="text-muted"><i class="fas fa-receipt"></i> <?php echo $todayTransactions; ?> transactions</small>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card success" onclick="window.location.href='inventory.php'">
                        <div class="stat-icon"><i class="fas fa-oil-can"></i></div>
                        <div class="stat-title">Total Stock</div>
                        <div class="stat-value"><?php echo number_format($totalStock, 2); ?> L</div>
                        <small class="text-muted"><i class="fas fa-warehouse"></i> Across all tanks</small>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card warning" onclick="window.location.href='leakage.php'">
                        <div class="stat-icon"><i class="fas fa-tint"></i></div>
                        <div class="stat-title">Pending Leakage</div>
                        <div class="stat-value"><?php echo $pendingLeakage; ?></div>
                        <small class="text-danger"><i class="fas fa-exclamation-triangle"></i> Requires attention</small>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card info" onclick="window.location.href='rental.php'">
                        <div class="stat-icon"><i class="fas fa-building"></i></div>
                        <div class="stat-title">Monthly Rent</div>
                        <div class="stat-value"><?php echo $currency; ?> <?php echo number_format($monthlyRent, 2); ?></div>
                        <small class="text-muted"><i class="fas fa-calendar-check"></i> Collected this month</small>
                    </div>
                </div>
            </div>
            
            <!-- Statistics Cards Row 2 -->
            <div class="row">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;" onclick="window.location.href='daily_sales_report.php?date=<?php echo date('Y-m-d'); ?>'">
                        <div class="stat-icon"><i class="fas fa-chart-bar"></i></div>
                        <div class="stat-title" style="color: rgba(255,255,255,0.8);">Monthly Sales</div>
                        <div class="stat-value" style="color: white;"><?php echo $currency; ?> <?php echo number_format($monthlySalesTotal, 2); ?></div>
                        <small style="color: rgba(255,255,255,0.7);"><i class="fas fa-chart-line"></i> This month total</small>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white;" onclick="window.location.href='payroll.php?tab=employees'">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                        <div class="stat-title" style="color: rgba(255,255,255,0.8);">Employees</div>
                        <div class="stat-value" style="color: white;"><?php echo $totalEmployees; ?></div>
                        <small style="color: rgba(255,255,255,0.7);"><i class="fas fa-user-plus"></i> Active staff members</small>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;" onclick="window.location.href='customer_ledger.php'">
                        <div class="stat-icon"><i class="fas fa-user-friends"></i></div>
                        <div class="stat-title" style="color: rgba(255,255,255,0.8);">Customers</div>
                        <div class="stat-value" style="color: white;"><?php echo $totalCustomers; ?></div>
                        <small style="color: rgba(255,255,255,0.7);"><i class="fas fa-handshake"></i> Registered customers</small>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;" onclick="window.location.href='profit_loss.php'">
                        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="stat-title" style="color: rgba(255,255,255,0.8);">Net Profit</div>
                        <div class="stat-value" style="color: white;">Calculating</div>
                        <small style="color: rgba(255,255,255,0.7);"><i class="fas fa-chart-pie"></i> View P&L statement</small>
                    </div>
                </div>
            </div>
            
            <!-- Charts Row -->
            <div class="row">
                <div class="col-lg-8">
                    <div class="card-custom">
                        <h5><i class="fas fa-chart-line"></i> Sales Trend (Last 7 Days)</h5>
                        <div class="chart-container">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card-custom">
                        <h5><i class="fas fa-chart-pie"></i> Product Sales Distribution</h5>
                        <div class="chart-container" style="height: 230px;">
                            <canvas id="productChart"></canvas>
                        </div>
                        <div class="mt-3">
                            <?php foreach($productSales as $ps): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span><i class="fas fa-circle" style="color: <?php echo rand_color(); ?>; font-size: 10px;"></i> <?php echo $ps['product_name']; ?></span>
                                <span><strong><?php echo $currency; ?> <?php echo number_format($ps['amount'], 2); ?></strong></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Activity & Alerts Row -->
            <div class="row">
                <div class="col-lg-6">
                    <div class="card-custom">
                        <h5><i class="fas fa-history"></i> Recent Sales Activity</h5>
                        <div class="activity-feed">
                            <?php foreach($recentSales as $sale): ?>
                            <div class="activity-item d-flex align-items-center">
                                <div class="activity-icon bg-success bg-opacity-10 text-success">
                                    <i class="fas fa-shopping-cart"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <strong><?php echo $sale['product_name']; ?></strong> - <?php echo number_format($sale['quantity_liters'], 2); ?> L
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-user"></i> <?php echo $sale['operator']; ?> | 
                                        <i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($sale['sale_date'])); ?>
                                    </small>
                                </div>
                                <div class="text-end">
                                    <span class="fw-bold text-success"><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></span>
                                    <br>
                                    <small class="badge bg-<?php echo $sale['sale_type'] == 'cash' ? 'success' : 'warning'; ?> badge-custom">
                                        <?php echo ucfirst($sale['sale_type']); ?>
                                    </small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if(empty($recentSales)): ?>
                            <div class="text-center text-muted py-4">No recent sales found</div>
                            <?php endif; ?>
                        </div>
                        <div class="mt-3 text-center">
                            <a href="daily_sales_report.php" class="btn btn-sm btn-outline-primary">View All Sales</a>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card-custom">
                        <h5><i class="fas fa-exclamation-triangle"></i> Low Stock Alerts</h5>
                        <div class="activity-feed">
                            <?php foreach($lowStockAlerts as $alert): ?>
                            <div class="activity-item d-flex align-items-center">
                                <div class="activity-icon bg-danger bg-opacity-10 text-danger">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <strong><?php echo $alert['tank_name']; ?></strong> - <?php echo $alert['product_name']; ?>
                                    <br>
                                    <small class="text-muted">Current stock: <?php echo number_format($alert['current_stock_liters'], 2); ?> L</small>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-danger badge-custom">Critical</span>
                                    <br>
                                    <a href="fuel_receiving.php" class="btn btn-sm btn-link p-0">Receive Stock</a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if(empty($lowStockAlerts)): ?>
                            <div class="text-center text-success py-4">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <p>All tanks have sufficient stock!</p>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="mt-3 text-center">
                            <a href="inventory.php" class="btn btn-sm btn-outline-danger">View Inventory</a>
                        </div>
                    </div>
                    
                    <!-- Quick Stats Mini Cards -->
                    <div class="row mt-3">
                        <div class="col-6">
                            <div class="card-custom text-center">
                                <i class="fas fa-truck fa-2x text-primary mb-2"></i>
                                <h6>Fuel Receiving</h6>
                                <small class="text-muted">Track incoming fuel</small>
                                <a href="fuel_receiving.php" class="btn btn-sm btn-outline-primary mt-2 w-100">Go</a>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="card-custom text-center">
                                <i class="fas fa-hand-holding-usd fa-2x text-success mb-2"></i>
                                <h6>Customer Payment</h6>
                                <small class="text-muted">Record payments</small>
                                <a href="customer_payment.php" class="btn btn-sm btn-outline-success mt-2 w-100">Go</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions Row -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card-custom">
                        <h5><i class="fas fa-bolt"></i> Quick Actions</h5>
                        <div class="row g-2">
                            <div class="col-md-2 col-4">
                                <a href="pos.php" class="btn btn-primary quick-action-btn w-100">
                                    <i class="fas fa-shopping-cart fa-lg"></i><br>
                                    <small>New Sale</small>
                                </a>
                            </div>
                            <div class="col-md-2 col-4">
                                <a href="fuel_receiving.php" class="btn btn-success quick-action-btn w-100">
                                    <i class="fas fa-truck fa-lg"></i><br>
                                    <small>Receive Fuel</small>
                                </a>
                            </div>
                            <div class="col-md-2 col-4">
                                <a href="leakage.php" class="btn btn-warning quick-action-btn w-100">
                                    <i class="fas fa-tint fa-lg"></i><br>
                                    <small>Stock Check</small>
                                </a>
                            </div>
                            <div class="col-md-2 col-4">
                                <a href="payroll.php?tab=attendance" class="btn btn-info quick-action-btn w-100">
                                    <i class="fas fa-clock fa-lg"></i><br>
                                    <small>Attendance</small>
                                </a>
                            </div>
                            <div class="col-md-2 col-4">
                                <a href="rental.php" class="btn btn-danger quick-action-btn w-100">
                                    <i class="fas fa-building fa-lg"></i><br>
                                    <small>Rent Collect</small>
                                </a>
                            </div>
                            <div class="col-md-2 col-4">
                                <a href="reports.php" class="btn btn-secondary quick-action-btn w-100">
                                    <i class="fas fa-chart-bar fa-lg"></i><br>
                                    <small>Reports</small>
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
        // Update current time
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            document.getElementById('currentTime').innerHTML = timeString;
        }
        updateTime();
        setInterval(updateTime, 1000);
        
        // Sales Chart - Weekly
        const salesCtx = document.getElementById('salesChart').getContext('2d');
        const salesDates = <?php echo json_encode(array_map(fn($s) => date('d M', strtotime($s['date'])), $weeklySales)); ?>;
        const salesAmounts = <?php echo json_encode(array_column($weeklySales, 'total')); ?>;
        
        new Chart(salesCtx, {
            type: 'line',
            data: {
                labels: salesDates.length ? salesDates : ['No Data'],
                datasets: [{
                    label: 'Sales (<?php echo $currency; ?>)',
                    data: salesAmounts.length ? salesAmounts : [0],
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#667eea',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Sales: <?php echo $currency; ?> ' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '<?php echo $currency; ?> ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        
        // Product Chart
        const productCtx = document.getElementById('productChart').getContext('2d');
        const productNames = <?php echo json_encode(array_column($productSales, 'product_name')); ?>;
        const productAmounts = <?php echo json_encode(array_column($productSales, 'amount')); ?>;
        
        new Chart(productCtx, {
            type: 'doughnut',
            data: {
                labels: productNames.length ? productNames : ['No Data'],
                datasets: [{
                    data: productAmounts.length ? productAmounts : [1],
                    backgroundColor: ['#667eea', '#764ba2', '#f093fb', '#4facfe', '#43e97b', '#fa709a'],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            font: { size: 11 }
                        }
                    }
                }
            }
        });
    </script>
    <?php
    // Helper function for random colors
    function rand_color() {
        $colors = ['#667eea', '#764ba2', '#f093fb', '#4facfe', '#43e97b', '#fa709a'];
        return $colors[array_rand($colors)];
    }
    ?>
</body>
</html>
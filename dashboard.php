<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();

// Get dashboard statistics
$today = date('Y-m-d');

// =============================================
// COMBINED SALES - Liquid + CNG
// =============================================

// Today's Liquid Sales
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total_sales FROM sales WHERE DATE(sale_date) = ?");
$stmt->execute([$today]);
$todayLiquidSales = $stmt->fetch()['total_sales'] ?? 0;

// Today's CNG Sales
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total_sales FROM gas_sales WHERE DATE(sale_date) = ? AND status = 'completed'");
$stmt->execute([$today]);
$todayCngSales = $stmt->fetch()['total_sales'] ?? 0;

// Today's Total Sales (Liquid + CNG)
$todayTotalSales = $todayLiquidSales + $todayCngSales;

// Monthly Liquid Sales
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total_sales FROM sales WHERE MONTH(sale_date) = MONTH(CURDATE())");
$stmt->execute();
$monthlyLiquidSales = $stmt->fetch()['total_sales'] ?? 0;

// Monthly CNG Sales
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total_sales FROM gas_sales WHERE MONTH(sale_date) = MONTH(CURDATE()) AND status = 'completed'");
$stmt->execute();
$monthlyCngSales = $stmt->fetch()['total_sales'] ?? 0;

// Monthly Total Sales (Liquid + CNG)
$monthlyTotalSales = $monthlyLiquidSales + $monthlyCngSales;

// Today's transaction counts
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM sales WHERE DATE(sale_date) = CURDATE()");
$stmt->execute();
$todayLiquidTransactions = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM gas_sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed'");
$stmt->execute();
$todayCngTransactions = $stmt->fetch()['count'];

$todayTotalTransactions = $todayLiquidTransactions + $todayCngTransactions;

// Stock
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

// Get CNG sales today for widget
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_sales,
        SUM(quantity_liters) as total_units,
        SUM(total_amount) as total_amount,
        SUM(CASE WHEN sale_type = 'cash' THEN total_amount ELSE 0 END) as cash_amount,
        SUM(CASE WHEN sale_type = 'credit' THEN total_amount ELSE 0 END) as credit_amount
    FROM gas_sales 
    WHERE DATE(sale_date) = ?
    AND status = 'completed'
");
$stmt->execute([$today]);
$cng_today = $stmt->fetch();

if(!$cng_today) {
    $cng_today = [
        'total_sales' => 0,
        'total_units' => 0,
        'total_amount' => 0,
        'cash_amount' => 0,
        'credit_amount' => 0
    ];
}

// Get monthly sales for chart (last 7 days) - COMBINED
$stmt = $pdo->prepare("
    SELECT 
        DATE(sale_date) as date, 
        SUM(total_amount) as total 
    FROM sales 
    WHERE DATE(sale_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
    GROUP BY DATE(sale_date) 
    ORDER BY sale_date
");
$stmt->execute();
$weeklyLiquidSales = $stmt->fetchAll();

// CNG weekly sales
$stmt = $pdo->prepare("
    SELECT 
        DATE(sale_date) as date, 
        SUM(total_amount) as total 
    FROM gas_sales 
    WHERE DATE(sale_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
    AND status = 'completed'
    GROUP BY DATE(sale_date) 
    ORDER BY sale_date
");
$stmt->execute();
$weeklyCngSales = $stmt->fetchAll();

// Combine weekly sales
$weeklySales = [];
$all_dates = array_unique(array_merge(
    array_column($weeklyLiquidSales, 'date'),
    array_column($weeklyCngSales, 'date')
));
sort($all_dates);

foreach($all_dates as $date) {
    $liquid_amount = 0;
    $cng_amount = 0;
    
    foreach($weeklyLiquidSales as $ls) {
        if($ls['date'] == $date) {
            $liquid_amount = $ls['total'];
            break;
        }
    }
    foreach($weeklyCngSales as $cs) {
        if($cs['date'] == $date) {
            $cng_amount = $cs['total'];
            break;
        }
    }
    
    $weeklySales[] = [
        'date' => $date,
        'liquid' => $liquid_amount,
        'cng' => $cng_amount,
        'total' => $liquid_amount + $cng_amount
    ];
}

// Get product sales for chart
$stmt = $pdo->prepare("
    SELECT p.product_name, SUM(s.quantity_liters) as liters, SUM(s.total_amount) as amount 
    FROM sales s 
    JOIN fuel_products p ON s.product_id = p.id 
    WHERE MONTH(s.sale_date) = MONTH(CURDATE()) 
    GROUP BY p.product_name 
    ORDER BY amount DESC 
    LIMIT 5
");
$stmt->execute();
$productSales = $stmt->fetchAll();

// Add CNG to product sales
$stmt = $pdo->prepare("
    SELECT 
        'CNG' as product_name,
        SUM(quantity_liters) as liters,
        SUM(total_amount) as amount
    FROM gas_sales 
    WHERE MONTH(sale_date) = MONTH(CURDATE()) 
    AND status = 'completed'
");
$stmt->execute();
$cngProduct = $stmt->fetch();

if($cngProduct && $cngProduct['amount'] > 0) {
    $productSales[] = $cngProduct;
}
usort($productSales, function($a, $b) {
    return $b['amount'] - $a['amount'];
});

// Get recent sales for activity feed - COMBINED
$stmt = $pdo->prepare("
    SELECT 
        s.*, 
        p.product_name, 
        u.full_name as operator,
        'liquid' as sale_type_label
    FROM sales s 
    JOIN fuel_products p ON s.product_id = p.id 
    JOIN users u ON s.operator_id = u.id 
    ORDER BY s.sale_date DESC 
    LIMIT 5
");
$stmt->execute();
$recentLiquidSales = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT 
        gs.*, 
        p.product_name, 
        u.full_name as operator,
        'cng' as sale_type_label
    FROM gas_sales gs 
    JOIN nozzles n ON gs.nozzle_id = n.id 
    JOIN fuel_products p ON n.product_id = p.id 
    JOIN users u ON gs.operator_id = u.id 
    WHERE gs.status = 'completed'
    ORDER BY gs.sale_date DESC 
    LIMIT 3
");
$stmt->execute();
$recentCngSales = $stmt->fetchAll();

$recentSales = array_merge($recentLiquidSales, $recentCngSales);
usort($recentSales, function($a, $b) {
    return strtotime($b['sale_date']) - strtotime($a['sale_date']);
});
$recentSales = array_slice($recentSales, 0, 5);

// Get low stock alerts
$stmt = $pdo->prepare("
    SELECT t.tank_name, p.product_name, t.current_stock_liters 
    FROM tanks t 
    JOIN fuel_products p ON t.product_id = p.id 
    WHERE t.current_stock_liters < 500 
    ORDER BY t.current_stock_liters ASC 
    LIMIT 5
");
$stmt->execute();
$lowStockAlerts = $stmt->fetchAll();

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
        
        /* Welcome Banner - Matching left menu gradient */
        .welcome-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            padding: 25px 30px;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.3);
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
        
        /* Stat Cards */
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
            border-left: 4px solid transparent;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .stat-card.primary { border-left-color: #667eea; }
        .stat-card.success { border-left-color: #28a745; }
        .stat-card.warning { border-left-color: #ffc107; }
        .stat-card.info { border-left-color: #17a2b8; }
        .stat-card.danger { border-left-color: #dc3545; }
        .stat-card.purple { border-left-color: #764ba2; }
        
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
        
        .stat-card.gradient-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-left: none;
        }
        
        .stat-card.gradient-primary .stat-title,
        .stat-card.gradient-primary .stat-value,
        .stat-card.gradient-primary small {
            color: white !important;
        }
        
        .stat-card.gradient-primary .stat-icon {
            opacity: 0.3;
            color: white;
        }
        
        .stat-card.gradient-success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            border-left: none;
        }
        
        .stat-card.gradient-success .stat-title,
        .stat-card.gradient-success .stat-value,
        .stat-card.gradient-success small {
            color: white !important;
        }
        
        .stat-card.gradient-success .stat-icon {
            opacity: 0.3;
            color: white;
        }
        
        .stat-card.gradient-pink {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border-left: none;
        }
        
        .stat-card.gradient-pink .stat-title,
        .stat-card.gradient-pink .stat-value,
        .stat-card.gradient-pink small {
            color: white !important;
        }
        
        .stat-card.gradient-pink .stat-icon {
            opacity: 0.3;
            color: white;
        }
        
        .stat-card.gradient-blue {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            border-left: none;
        }
        
        .stat-card.gradient-blue .stat-title,
        .stat-card.gradient-blue .stat-value,
        .stat-card.gradient-blue small {
            color: white !important;
        }
        
        .stat-card.gradient-blue .stat-icon {
            opacity: 0.3;
            color: white;
        }
        
        .stat-card .breakdown {
            font-size: 12px;
            margin-top: 5px;
            opacity: 0.8;
        }
        
        .stat-card .breakdown .liquid { color: #28a745; }
        .stat-card .breakdown .cng { color: #17a2b8; }
        
        .stat-card.gradient-primary .breakdown .liquid,
        .stat-card.gradient-primary .breakdown .cng {
            color: rgba(255,255,255,0.9);
        }
        
        /* Card Custom */
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
        
        /* CNG Widget */
        .cng-widget {
            background: white;
            border-radius: 15px;
            padding: 0;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s, box-shadow 0.3s;
            border: 1px solid rgba(102, 126, 234, 0.1);
        }
        
        .cng-widget:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.15);
        }
        
        .cng-widget-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            border-bottom: none;
        }
        
        .cng-widget-header h5 {
            margin: 0;
            font-weight: 600;
        }
        
        .cng-widget-header .btn-light {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 5px 12px;
            font-size: 12px;
            border-radius: 20px;
            transition: all 0.3s;
        }
        
        .cng-widget-header .btn-light:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
        
        .cng-widget-body {
            padding: 20px;
        }
        
        .cng-stat-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            transition: all 0.3s;
        }
        
        .cng-stat-box:hover {
            background: #e9ecef;
        }
        
        .cng-stat-box .number {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }
        
        .cng-stat-box .label {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .cng-stat-box .number.green { color: #28a745; }
        .cng-stat-box .number.blue { color: #667eea; }
        .cng-stat-box .number.pink { color: #f5576c; }
        .cng-stat-box .number.orange { color: #ffc107; }
        
        .cng-divider {
            border-color: rgba(102, 126, 234, 0.1);
            margin: 15px 0;
        }
        
        .cng-badge-cash {
            background: #28a745;
            color: white;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
        }
        
        .cng-badge-credit {
            background: #ffc107;
            color: #856404;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
        }
        
        .cng-badge-pipeline {
            background: #667eea;
            color: white;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 10px;
        }
        
        /* Activity Feed */
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white !important;
            border: none;
            text-decoration: none;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
            color: white !important;
        }
        
        .quick-action-btn i {
            display: block;
            margin-bottom: 5px;
        }
        
        .quick-action-btn.success { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .quick-action-btn.warning { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .quick-action-btn.info { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .quick-action-btn.danger { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .quick-action-btn.secondary { background: linear-gradient(135deg, #434343 0%, #000000 100%); }
        
        .badge-cng-small {
            background: #17a2b8;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
        }
        
        .badge-liquid-small {
            background: #28a745;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stat-value {
                font-size: 22px;
            }
            .chart-container {
                height: 220px;
            }
            .cng-stat-box .number {
                font-size: 20px;
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
            
            <!-- Statistics Cards Row 1 - Combined Sales -->
            <div class="row">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card gradient-primary" onclick="window.location.href='daily_sales_report.php'">
                        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="stat-title">Today's Total Sales</div>
                        <div class="stat-value"><?php echo $currency; ?> <?php echo number_format($todayTotalSales, 2); ?></div>
                        <small><i class="fas fa-receipt"></i> <?php echo $todayTotalTransactions; ?> transactions</small>
                        <div class="breakdown">
                            <span class="liquid">Liquid: <?php echo $currency; ?> <?php echo number_format($todayLiquidSales, 2); ?></span> | 
                            <span class="cng">CNG: <?php echo $currency; ?> <?php echo number_format($todayCngSales, 2); ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card gradient-success" onclick="window.location.href='inventory.php'">
                        <div class="stat-icon"><i class="fas fa-oil-can"></i></div>
                        <div class="stat-title">Total Stock</div>
                        <div class="stat-value"><?php echo number_format($totalStock, 2); ?> L</div>
                        <small><i class="fas fa-warehouse"></i> Across all tanks</small>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card gradient-pink" onclick="window.location.href='leakage.php'">
                        <div class="stat-icon"><i class="fas fa-tint"></i></div>
                        <div class="stat-title">Pending Leakage</div>
                        <div class="stat-value"><?php echo $pendingLeakage; ?></div>
                        <small><i class="fas fa-exclamation-triangle"></i> Requires attention</small>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card gradient-blue" onclick="window.location.href='rental.php'">
                        <div class="stat-icon"><i class="fas fa-building"></i></div>
                        <div class="stat-title">Monthly Rent</div>
                        <div class="stat-value"><?php echo $currency; ?> <?php echo number_format($monthlyRent, 2); ?></div>
                        <small><i class="fas fa-calendar-check"></i> Collected this month</small>
                    </div>
                </div>
            </div>
            
            <!-- Statistics Cards Row 2 -->
            <div class="row">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card primary" onclick="window.location.href='daily_sales_report.php?date=<?php echo date('Y-m-d'); ?>'">
                        <div class="stat-icon"><i class="fas fa-chart-bar"></i></div>
                        <div class="stat-title">Monthly Total Sales</div>
                        <div class="stat-value"><?php echo $currency; ?> <?php echo number_format($monthlyTotalSales, 2); ?></div>
                        <small><i class="fas fa-chart-line"></i> This month total</small>
                        <div class="breakdown">
                            <span class="liquid">Liquid: <?php echo $currency; ?> <?php echo number_format($monthlyLiquidSales, 2); ?></span> | 
                            <span class="cng">CNG: <?php echo $currency; ?> <?php echo number_format($monthlyCngSales, 2); ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card success" onclick="window.location.href='payroll.php?tab=employees'">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                        <div class="stat-title">Employees</div>
                        <div class="stat-value"><?php echo $totalEmployees; ?></div>
                        <small><i class="fas fa-user-plus"></i> Active staff members</small>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card purple" onclick="window.location.href='customer_ledger.php'">
                        <div class="stat-icon"><i class="fas fa-user-friends"></i></div>
                        <div class="stat-title">Customers</div>
                        <div class="stat-value"><?php echo $totalCustomers; ?></div>
                        <small><i class="fas fa-handshake"></i> Registered customers</small>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card info" onclick="window.location.href='profit_loss.php'">
                        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="stat-title">Net Profit</div>
                        <div class="stat-value" style="font-size: 20px;">View P&L</div>
                        <small><i class="fas fa-chart-pie"></i> View profit & loss</small>
                    </div>
                </div>
            </div>
            
            <!-- Charts Row -->
            <div class="row">
                <div class="col-lg-8">
                    <div class="card-custom">
                        <h5><i class="fas fa-chart-line"></i> Sales Trend (Last 7 Days) - Combined</h5>
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
                                <span>
                                    <i class="fas fa-circle" style="color: <?php echo rand_color(); ?>; font-size: 10px;"></i> 
                                    <?php echo $ps['product_name']; ?>
                                    <?php if($ps['product_name'] == 'CNG'): ?>
                                        <span class="badge-cng-small">Pipeline</span>
                                    <?php endif; ?>
                                </span>
                                <span><strong><?php echo $currency; ?> <?php echo number_format($ps['amount'], 2); ?></strong></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- CNG Sales Widget -->
            <div class="row">
                <div class="col-md-12">
                    <div class="cng-widget">
                        <div class="cng-widget-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5><i class="fas fa-gas-pump"></i> CNG Sales Today</h5>
                                <a href="cng_sales_report.php" class="btn btn-light btn-sm">
                                    <i class="fas fa-arrow-right"></i> View All
                                </a>
                            </div>
                        </div>
                        <div class="cng-widget-body">
                            <div class="row">
                                <div class="col-md-3 col-6">
                                    <div class="cng-stat-box">
                                        <div class="number blue"><?php echo number_format($cng_today['total_units'] ?? 0, 2); ?></div>
                                        <div class="label"><i class="fas fa-ruler"></i> Total CNG (m³)</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6">
                                    <div class="cng-stat-box">
                                        <div class="number green"><?php echo $currency; ?> <?php echo number_format($cng_today['total_amount'] ?? 0, 2); ?></div>
                                        <div class="label"><i class="fas fa-money-bill-wave"></i> Total Revenue</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6">
                                    <div class="cng-stat-box">
                                        <div class="number orange"><?php echo $currency; ?> <?php echo number_format($cng_today['cash_amount'] ?? 0, 2); ?></div>
                                        <div class="label"><span class="cng-badge-cash">Cash</span> Received</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-6">
                                    <div class="cng-stat-box">
                                        <div class="number pink"><?php echo $currency; ?> <?php echo number_format($cng_today['credit_amount'] ?? 0, 2); ?></div>
                                        <div class="label"><span class="cng-badge-credit">Credit</span> Sales</div>
                                    </div>
                                </div>
                            </div>
                            
                            <hr class="cng-divider">
                            
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-pipe fa-2x" style="color: #667eea; margin-right: 15px;"></i>
                                        <div>
                                            <span class="cng-badge-pipeline"><i class="fas fa-pipe"></i> Pipeline Supply</span>
                                            <div class="mt-1">
                                                <small class="text-muted">
                                                    <i class="fas fa-shopping-cart"></i> <?php echo $cng_today['total_sales'] ?? 0; ?> transactions today
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 text-md-end">
                                    <div class="d-flex justify-content-md-end gap-2">
                                        <a href="gas_sales.php" class="btn btn-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 20px;">
                                            <i class="fas fa-plus-circle"></i> New CNG Sale
                                        </a>
                                        <a href="cng_sales_report.php" class="btn btn-sm btn-outline-secondary" style="border-radius: 20px;">
                                            <i class="fas fa-chart-bar"></i> Report
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Activity & Alerts Row -->
            <div class="row mt-3">
                <div class="col-lg-6">
                    <div class="card-custom">
                        <h5><i class="fas fa-history"></i> Recent Sales Activity</h5>
                        <div class="activity-feed">
                            <?php foreach($recentSales as $sale): 
                                $is_cng = isset($sale['sale_type_label']) && $sale['sale_type_label'] == 'cng';
                                $badge_class = $is_cng ? 'info' : 'success';
                                $icon_class = $is_cng ? 'fa-gas-pump' : 'fa-shopping-cart';
                                $type_label = $is_cng ? 'CNG' : 'Liquid';
                            ?>
                            <div class="activity-item d-flex align-items-center">
                                <div class="activity-icon bg-<?php echo $badge_class; ?> bg-opacity-10 text-<?php echo $badge_class; ?>">
                                    <i class="fas <?php echo $icon_class; ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <strong><?php echo $sale['product_name']; ?></strong> 
                                    - <?php echo number_format($sale['quantity_liters'], 2); ?> <?php echo $is_cng ? 'm³' : 'L'; ?>
                                    <span class="badge <?php echo $is_cng ? 'badge-cng-small' : 'badge-liquid-small'; ?>"><?php echo $type_label; ?></span>
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
                                <a href="pos.php" class="quick-action-btn w-100 d-block text-center">
                                    <i class="fas fa-shopping-cart fa-lg"></i>
                                    <small>Fuel Sale</small>
                                </a>
                            </div>
                            <div class="col-md-2 col-4">
                                <a href="fuel_receiving.php" class="quick-action-btn success w-100 d-block text-center">
                                    <i class="fas fa-truck fa-lg"></i>
                                    <small>Receive Fuel</small>
                                </a>
                            </div>
                            <div class="col-md-2 col-4">
                                <a href="gas_sales.php" class="quick-action-btn warning w-100 d-block text-center">
                                    <i class="fas fa-tint fa-lg"></i>
                                    <small>CNG Sale</small>
                                </a>
                            </div>
                            <div class="col-md-2 col-4">
                                <a href="payroll.php?tab=attendance" class="quick-action-btn info w-100 d-block text-center">
                                    <i class="fas fa-clock fa-lg"></i>
                                    <small>Attendance</small>
                                </a>
                            </div>
                            <div class="col-md-2 col-4">
                                <a href="rental.php" class="quick-action-btn danger w-100 d-block text-center">
                                    <i class="fas fa-building fa-lg"></i>
                                    <small>Rent Collect</small>
                                </a>
                            </div>
                            <div class="col-md-2 col-4">
                                <a href="reports.php" class="quick-action-btn secondary w-100 d-block text-center">
                                    <i class="fas fa-chart-bar fa-lg"></i>
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
        
        // Sales Chart - Combined (Liquid + CNG)
        const salesCtx = document.getElementById('salesChart').getContext('2d');
        const salesDates = <?php echo json_encode(array_map(fn($s) => date('d M', strtotime($s['date'])), $weeklySales)); ?>;
        const liquidAmounts = <?php echo json_encode(array_column($weeklySales, 'liquid')); ?>;
        const cngAmounts = <?php echo json_encode(array_column($weeklySales, 'cng')); ?>;
        const totalAmounts = <?php echo json_encode(array_column($weeklySales, 'total')); ?>;
        
        new Chart(salesCtx, {
            type: 'bar',
            data: {
                labels: salesDates.length ? salesDates : ['No Data'],
                datasets: [
                    {
                        label: 'Liquid Sales (<?php echo $currency; ?>)',
                        data: liquidAmounts.length ? liquidAmounts : [0],
                        backgroundColor: 'rgba(40, 167, 69, 0.6)',
                        borderColor: 'rgba(40, 167, 69, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'CNG Sales (<?php echo $currency; ?>)',
                        data: cngAmounts.length ? cngAmounts : [0],
                        backgroundColor: 'rgba(23, 162, 184, 0.6)',
                        borderColor: 'rgba(23, 162, 184, 1)',
                        borderWidth: 1
                    }
                ]
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
                                return context.dataset.label + ': <?php echo $currency; ?> ' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: true
                    },
                    y: {
                        stacked: true,
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
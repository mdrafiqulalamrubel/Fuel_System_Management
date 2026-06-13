<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$active_tab = $_GET['tab'] ?? 'sales';

// Get data for various reports
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT SUM(total_amount) as today_sales FROM sales WHERE DATE(sale_date) = ?");
$stmt->execute([$today]);
$today_sales = $stmt->fetch()['today_sales'] ?? 0;

$stmt = $pdo->prepare("SELECT p.product_name, SUM(s.quantity_liters) as total_qty, SUM(s.total_amount) as total_amount FROM sales s JOIN fuel_products p ON s.product_id = p.id WHERE MONTH(s.sale_date) = MONTH(CURDATE()) GROUP BY p.product_name");
$stmt->execute();
$monthly_product_sales = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT t.tank_name, p.product_name, t.current_stock_liters, p.purchase_rate FROM tanks t JOIN fuel_products p ON t.product_id = p.id ORDER BY t.tank_name");
$stmt->execute();
$current_stock = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT SUM(amount) as total_rent FROM rent_payments WHERE MONTH(payment_date) = MONTH(CURDATE())");
$stmt->execute();
$monthly_rent = $stmt->fetch()['total_rent'] ?? 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as total_employees FROM employees WHERE is_active = 1");
$stmt->execute();
$total_employees = $stmt->fetch()['total_employees'];

$stmt = $pdo->prepare("SELECT SUM(net_salary) as total_salary FROM payroll WHERE MONTH(month_year) = MONTH(CURDATE()) AND status = 'paid'");
$stmt->execute();
$salary_paid = $stmt->fetch()['total_salary'] ?? 0;

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';

// Calculate total stock value
$total_stock_value = 0;
$total_stock_liters = 0;
foreach($current_stock as $cs) {
    $total_stock_value += $cs['current_stock_liters'] * $cs['purchase_rate'];
    $total_stock_liters += $cs['current_stock_liters'];
}

// Get leakages for table
$leakages = $pdo->query("SELECT l.*, t.tank_name FROM leakage_adjustments l JOIN tanks t ON l.tank_id = t.id ORDER BY l.adjustment_date DESC LIMIT 20")->fetchAll();
$total_variance = array_sum(array_column($leakages, 'variance'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Dashboard</title>
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
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .stats-card i {
            font-size: 40px;
            opacity: 0.5;
            float: right;
        }
        
        .nav-tabs {
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 20px;
            background: transparent;
        }
        
        .nav-tabs .nav-item {
            margin-bottom: -2px;
        }
        
        .nav-tabs .nav-link {
            color: #6c757d;
            font-weight: 500;
            padding: 12px 20px;
            border: none;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
            background: transparent;
        }
        
        .nav-tabs .nav-link:hover {
            color: #667eea;
            border-bottom-color: #667eea;
            background: transparent;
        }
        
        .nav-tabs .nav-link.active {
            color: #667eea;
            background: transparent;
            border-bottom: 2px solid #667eea;
            font-weight: 600;
        }
        
        .nav-tabs .nav-link i {
            margin-right: 8px;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            transition: margin-left 0.3s;
        }
        
        .report-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s;
            text-align: center;
            cursor: pointer;
        }
        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .text-end {
            text-align: right;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table.dataTable {
            width: 100% !important;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        @media print {
            .sidebar, .no-print, .stats-card, .card-header .btn, 
            .dataTables_length, .dataTables_filter, .dataTables_paginate,
            .nav-tabs, .btn, form {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-chart-bar"></i> Reports Dashboard</h2>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
            
            <!-- Statistics Cards -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($today_sales, 2); ?></h3>
                        <p>Today's Sales</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-oil-can"></i>
                        <h3><?php echo number_format($total_stock_liters, 2); ?> L</h3>
                        <p>Total Stock</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-building"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($monthly_rent, 2); ?></h3>
                        <p>Monthly Rent</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-users"></i>
                        <h3><?php echo $total_employees; ?></h3>
                        <p>Total Employees</p>
                    </div>
                </div>
            </div>
            
            <!-- Tabs -->
            <ul class="nav nav-tabs">
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'sales' ? 'active' : ''; ?>" href="?tab=sales">
                        <i class="fas fa-chart-line"></i> Sales Reports
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'inventory' ? 'active' : ''; ?>" href="?tab=inventory">
                        <i class="fas fa-warehouse"></i> Inventory Reports
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'financial' ? 'active' : ''; ?>" href="?tab=financial">
                        <i class="fas fa-chart-pie"></i> Financial Reports
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'hr' ? 'active' : ''; ?>" href="?tab=hr">
                        <i class="fas fa-users"></i> HR Reports
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'leakage' ? 'active' : ''; ?>" href="?tab=leakage">
                        <i class="fas fa-tint"></i> Leakage Reports
                    </a>
                </li>
            </ul>
            
            <!-- Sales Reports Tab -->
            <?php if($active_tab == 'sales'): ?>
            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-calendar-day"></i> Daily Sales Report</h5>
                        </div>
                        <div class="card-body">
                            <form target="_blank" action="daily_sales_report.php" method="GET">
                                <div class="mb-3">
                                    <label>Select Date</label>
                                    <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search"></i> Generate Report
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5><i class="fas fa-calendar-alt"></i> Monthly Sales Summary</h5>
                        </div>
                        <div class="card-body">
                            <form target="_blank" action="monthly_sales_report.php" method="GET">
                                <div class="mb-3">
                                    <label>Select Month</label>
                                    <input type="month" name="month" class="form-control" value="<?php echo date('Y-m'); ?>" required>
                                </div>
                                <button type="submit" class="btn btn-info w-100">
                                    <i class="fas fa-search"></i> Generate Report
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="fas fa-chart-bar"></i> Monthly Product-wise Sales</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="productSalesChart" height="100"></canvas>
                            <div class="table-responsive mt-3">
                                <table class="table table-bordered">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Product</th>
                                            <th class="text-end">Quantity (Liters)</th>
                                            <th class="text-end">Amount (<?php echo $currency; ?>)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($monthly_product_sales as $ps): ?>
                                        <tr>
                                            <td><?php echo $ps['product_name']; ?></td>
                                            <td class="text-end"><?php echo number_format($ps['total_qty'], 2); ?> L</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($ps['total_amount'], 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Inventory Reports Tab - FIXED -->
            <?php if($active_tab == 'inventory'): ?>
            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-warning text-dark">
                            <h5><i class="fas fa-chart-line"></i> Current Stock Position</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="stockChart" height="100"></canvas>
                            <div class="table-responsive mt-3">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Tank Name</th>
                                            <th>Product</th>
                                            <th class="text-end">Current Stock (L)</th>
                                            <th class="text-end">Value (<?php echo $currency; ?>)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($current_stock as $cs): ?>
                                        <tr>
                                            <td><strong><?php echo $cs['tank_name']; ?></strong></td>
                                            <td><?php echo $cs['product_name']; ?></td>
                                            <td class="text-end"><?php echo number_format($cs['current_stock_liters'], 2); ?> L</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($cs['current_stock_liters'] * $cs['purchase_rate'], 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="2" class="text-end">TOTAL:</td>
                                            <td class="text-end"><?php echo number_format($total_stock_liters, 2); ?> L</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_stock_value, 2); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <hr>
                            <form target="_blank" action="stock_valuation_report.php" method="GET">
                                <button type="submit" class="btn btn-warning w-100">
                                    <i class="fas fa-download"></i> Download Stock Valuation Report
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Financial Reports Tab -->
            <?php if($active_tab == 'financial'): ?>
            <div class="row mt-3">
                <div class="col-md-4">
                    <div class="report-card" onclick="window.open('balance_sheet.php', '_blank')">
                        <i class="fas fa-balance-scale fa-3x text-primary"></i>
                        <h4 class="mt-2">Balance Sheet</h4>
                        <p class="text-muted">Statement of assets, liabilities and equity</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="report-card" onclick="window.open('profit_loss.php', '_blank')">
                        <i class="fas fa-chart-line fa-3x text-success"></i>
                        <h4 class="mt-2">Profit & Loss</h4>
                        <p class="text-muted">Income and expense statement</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="report-card" onclick="window.open('trial_balance.php', '_blank')">
                        <i class="fas fa-list-ul fa-3x text-info"></i>
                        <h4 class="mt-2">Trial Balance</h4>
                        <p class="text-muted">Summary of all ledger balances</p>
                    </div>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="report-card" onclick="window.open('cash_flow.php', '_blank')">
                        <i class="fas fa-money-bill-wave fa-3x text-danger"></i>
                        <h4 class="mt-2">Cash Flow Statement</h4>
                        <p class="text-muted">Cash inflow and outflow statement</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="report-card" onclick="window.open('general_ledger.php', '_blank')">
                        <i class="fas fa-scroll fa-3x text-secondary"></i>
                        <h4 class="mt-2">General Ledger</h4>
                        <p class="text-muted">Account-wise transaction details</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- HR Reports Tab -->
            <?php if($active_tab == 'hr'): ?>
            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-file-invoice-dollar"></i> Salary Sheet</h5>
                        </div>
                        <div class="card-body">
                            <form target="_blank" action="salary_sheet_report.php" method="GET">
                                <div class="mb-3">
                                    <label>Select Month</label>
                                    <input type="month" name="month_year" class="form-control" required>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search"></i> Generate Salary Sheet
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5><i class="fas fa-calendar-check"></i> Attendance Report</h5>
                        </div>
                        <div class="card-body">
                            <form target="_blank" action="attendance_report.php" method="GET">
                                <div class="mb-3">
                                    <label>Select Month</label>
                                    <input type="month" name="month" class="form-control" required>
                                </div>
                                <button type="submit" class="btn btn-info w-100">
                                    <i class="fas fa-search"></i> Generate Attendance Report
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="fas fa-chart-pie"></i> Employee Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="alert alert-info text-center">
                                        <h3><?php echo $total_employees; ?></h3>
                                        <p>Total Active Employees</p>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="alert alert-success text-center">
                                        <h3><?php echo $currency; ?> <?php echo number_format($salary_paid, 2); ?></h3>
                                        <p>Salary Paid This Month</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Leakage Reports Tab - FIXED -->
            <?php if($active_tab == 'leakage'): ?>
            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-danger text-white">
                            <h5><i class="fas fa-chart-line"></i> Leakage & Wastage Analysis</h5>
                        </div>
                        <div class="card-body">
                            <form target="_blank" action="leakage_report.php" method="GET" class="mb-4">
                                <div class="row">
                                    <div class="col-md-3">
                                        <label>From Date</label>
                                        <input type="date" name="from_date" class="form-control" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label>To Date</label>
                                        <input type="date" name="to_date" class="form-control" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label>Select Tank</label>
                                        <select name="tank_id" class="form-control">
                                            <option value="">All Tanks</option>
                                            <?php 
                                            $tanks = $pdo->query("SELECT * FROM tanks")->fetchAll(); 
                                            foreach($tanks as $t){ 
                                                echo "<option value='{$t['id']}'>{$t['tank_name']}</option>"; 
                                            } 
                                            ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label>&nbsp;</label>
                                        <button type="submit" class="btn btn-danger w-100">
                                            <i class="fas fa-search"></i> Generate Report
                                        </button>
                                    </div>
                                </div>
                            </form>
                            <hr>
                            <h5><i class="fas fa-history"></i> Recent Leakage Adjustments</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="leakageTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Date</th>
                                            <th>Tank</th>
                                            <th class="text-end">System Stock (L)</th>
                                            <th class="text-end">Physical Stock (L)</th>
                                            <th class="text-end">Variance (L)</th>
                                            <th>Type</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($leakages)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">No leakage records found</td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach($leakages as $lk): ?>
                                            <tr>
                                                <td><?php echo date('d-m-Y', strtotime($lk['adjustment_date'])); ?></td>
                                                <td><?php echo $lk['tank_name']; ?></td>
                                                <td class="text-end"><?php echo number_format($lk['system_stock'],2); ?> L</td>
                                                <td class="text-end"><?php echo number_format($lk['physical_stock'],2); ?> L</td>
                                                <td class="text-end text-danger fw-bold"><?php echo number_format($lk['variance'],2); ?> L</td>
                                                <td><?php echo ucfirst($lk['adjustment_type']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="4" class="text-end">TOTAL VARIANCE:</td>
                                            <td class="text-end text-danger"><?php echo number_format($total_variance, 2); ?> L</td>
                                            <td></td>
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
            $('#leakageTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
        
        // Product sales chart
        const prodCtx = document.getElementById('productSalesChart').getContext('2d');
        const prodNames = <?php echo json_encode(array_column($monthly_product_sales, 'product_name')); ?>;
        const prodAmounts = <?php echo json_encode(array_column($monthly_product_sales, 'total_amount')); ?>;
        new Chart(prodCtx, {
            type: 'bar',
            data: {
                labels: prodNames,
                datasets: [{
                    label: 'Sales Amount (<?php echo $currency; ?>)',
                    data: prodAmounts,
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: { responsive: true, maintainAspectRatio: true }
        });
        
        // Stock chart
        const stockCtx = document.getElementById('stockChart').getContext('2d');
        const tankNames = <?php echo json_encode(array_column($current_stock, 'tank_name')); ?>;
        const stockLevels = <?php echo json_encode(array_column($current_stock, 'current_stock_liters')); ?>;
        new Chart(stockCtx, {
            type: 'bar',
            data: {
                labels: tankNames,
                datasets: [{
                    label: 'Stock (Liters)',
                    data: stockLevels,
                    backgroundColor: 'rgba(255, 193, 7, 0.5)',
                    borderColor: 'rgba(255, 193, 7, 1)',
                    borderWidth: 1
                }]
            },
            options: { responsive: true, maintainAspectRatio: true }
        });
    </script>
</body>
</html>
<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

// Get data for various reports
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT SUM(total_amount) as today_sales FROM sales WHERE DATE(sale_date) = ?");
$stmt->execute([$today]);
$today_sales = $stmt->fetch()['today_sales'] ?? 0;

$stmt = $pdo->prepare("SELECT p.product_name, SUM(s.quantity_liters) as total_qty, SUM(s.total_amount) as total_amount FROM sales s JOIN fuel_products p ON s.product_id = p.id WHERE MONTH(s.sale_date) = MONTH(CURDATE()) GROUP BY p.product_name");
$stmt->execute();
$monthly_product_sales = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT t.tank_name, p.product_name, t.current_stock_liters FROM tanks t JOIN fuel_products p ON t.product_id = p.id");
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand">Reports Dashboard</span>
            <a href="dashboard.php" class="btn btn-light">Back to Dashboard</a>
        </div>
    </nav>
    
    <div class="container mt-3">
        <ul class="nav nav-tabs" id="reportTabs">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#sales">Sales Reports</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#inventory">Inventory Reports</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#financial">Financial Reports</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#hr">HR Reports</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#leakage">Leakage Reports</a></li>
        </ul>
        
        <div class="tab-content mt-3">
            <!-- Sales Reports Tab -->
            <div class="tab-pane fade show active" id="sales">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-primary text-white">Daily Sales Report</div>
                            <div class="card-body">
                                <form target="_blank" action="daily_sales_report.php" method="GET">
                                    <div class="mb-3"><label>Select Date</label><input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                                    <button type="submit" class="btn btn-primary">Generate Report</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-info text-white">Monthly Sales Summary</div>
                            <div class="card-body">
                                <form target="_blank" action="monthly_sales_report.php" method="GET">
                                    <div class="mb-3"><label>Select Month</label><input type="month" name="month" class="form-control" value="<?php echo date('Y-m'); ?>" required></div>
                                    <button type="submit" class="btn btn-info">Generate Report</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header bg-success text-white">Monthly Product-wise Sales</div>
                            <div class="card-body">
                                <canvas id="productSalesChart" height="100"></canvas>
                                <table class="table table-bordered mt-3">
                                    <thead><tr><th>Product</th><th>Quantity (Liters)</th><th>Amount (৳)</th></tr></thead>
                                    <tbody><?php foreach($monthly_product_sales as $ps): ?><tr><td><?php echo $ps['product_name']; ?></td><td><?php echo number_format($ps['total_qty'], 2); ?></td><td>৳<?php echo number_format($ps['total_amount'], 2); ?></td></tr><?php endforeach; ?></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Inventory Reports Tab -->
            <div class="tab-pane fade" id="inventory">
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header bg-warning">Current Stock Position</div>
                            <div class="card-body">
                                <canvas id="stockChart" height="100"></canvas>
                                <table class="table table-bordered mt-3">
                                    <thead><tr><th>Tank</th><th>Product</th><th>Current Stock (L)</th><th>Value (৳)</th></tr></thead>
                                    <tbody><?php foreach($current_stock as $cs): ?><tr><td><?php echo $cs['tank_name']; ?></td><td><?php echo $cs['product_name']; ?></td><td><?php echo number_format($cs['current_stock_liters'], 2); ?></td><td>৳<?php echo number_format($cs['current_stock_liters'] * 85, 2); ?></td></tr><?php endforeach; ?></tbody>
                                </table>
                                <hr>
                                <form target="_blank" action="stock_valuation_report.php" method="GET">
                                    <button type="submit" class="btn btn-warning">Download Stock Valuation Report</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Financial Reports Tab -->
            <div class="tab-pane fade" id="financial">
                <div class="row">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header bg-danger text-white">Balance Sheet</div>
                            <div class="card-body"><a href="balance_sheet.php" class="btn btn-danger w-100">View Balance Sheet</a></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header bg-success text-white">Profit & Loss</div>
                            <div class="card-body"><a href="profit_loss.php" class="btn btn-success w-100">View P&L Statement</a></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header bg-info text-white">Trial Balance</div>
                            <div class="card-body"><a href="trial_balance.php" class="btn btn-info w-100">View Trial Balance</a></div>
                        </div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-primary text-white">Cash Flow Statement</div>
                            <div class="card-body"><a href="cash_flow.php" class="btn btn-primary w-100">Generate Cash Flow</a></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-secondary text-white">General Ledger</div>
                            <div class="card-body"><a href="general_ledger.php" class="btn btn-secondary w-100">View General Ledger</a></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- HR Reports Tab -->
            <div class="tab-pane fade" id="hr">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-primary text-white">Salary Sheet</div>
                            <div class="card-body">
                                <form target="_blank" action="salary_sheet_report.php" method="GET">
                                    <div class="mb-3"><label>Select Month</label><input type="month" name="month_year" class="form-control" required></div>
                                    <button type="submit" class="btn btn-primary">Generate Salary Sheet</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-info text-white">Attendance Report</div>
                            <div class="card-body">
                                <form target="_blank" action="attendance_report.php" method="GET">
                                    <div class="mb-3"><label>Select Month</label><input type="month" name="month" class="form-control" required></div>
                                    <button type="submit" class="btn btn-info">Generate Attendance Report</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header bg-success text-white">Employee Summary</div>
                            <div class="card-body">
                                <p>Total Active Employees: <strong><?php echo $total_employees; ?></strong></p>
                                <p>Salary Paid This Month: <strong>৳<?php echo number_format($salary_paid, 2); ?></strong></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Leakage Reports Tab -->
            <div class="tab-pane fade" id="leakage">
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header bg-danger text-white">Leakage & Wastage Analysis</div>
                            <div class="card-body">
                                <form target="_blank" action="leakage_report.php" method="GET">
                                    <div class="row">
                                        <div class="col-md-3"><label>From Date</label><input type="date" name="from_date" class="form-control" required></div>
                                        <div class="col-md-3"><label>To Date</label><input type="date" name="to_date" class="form-control" required></div>
                                        <div class="col-md-3"><label>Tank</label><select name="tank_id" class="form-control"><option value="">All Tanks</option><?php $tanks=$pdo->query("SELECT * FROM tanks")->fetchAll(); foreach($tanks as $t){ echo "<option value='{$t['id']}'>{$t['tank_name']}</option>"; } ?></select></div>
                                        <div class="col-md-3"><label>&nbsp;</label><button type="submit" class="btn btn-danger form-control">Generate Report</button></div>
                                    </div>
                                </form>
                                <hr>
                                <h5>Recent Leakage Adjustments</h5>
                                <?php $leakages = $pdo->query("SELECT l.*, t.tank_name FROM leakage_adjustments l JOIN tanks t ON l.tank_id = t.id ORDER BY l.adjustment_date DESC LIMIT 20")->fetchAll(); ?>
                                <table class="table table-bordered">
                                    <thead><tr><th>Date</th><th>Tank</th><th>System Stock</th><th>Physical Stock</th><th>Variance</th><th>Type</th></tr></thead>
                                    <tbody><?php foreach($leakages as $lk): ?><tr><td><?php echo $lk['adjustment_date']; ?></td><td><?php echo $lk['tank_name']; ?></td><td><?php echo number_format($lk['system_stock'],2); ?>L</td><td><?php echo number_format($lk['physical_stock'],2); ?>L</td><td class="text-danger"><?php echo number_format($lk['variance'],2); ?>L</td><td><?php echo ucfirst($lk['adjustment_type']); ?></td></tr><?php endforeach; ?></tbody>
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
    <script>
        // Product sales chart
        const prodCtx = document.getElementById('productSalesChart').getContext('2d');
        const prodNames = <?php echo json_encode(array_column($monthly_product_sales, 'product_name')); ?>;
        const prodAmounts = <?php echo json_encode(array_column($monthly_product_sales, 'total_amount')); ?>;
        new Chart(prodCtx, { type: 'bar', data: { labels: prodNames, datasets: [{ label: 'Sales Amount (৳)', data: prodAmounts, backgroundColor: 'rgba(54, 162, 235, 0.5)' }] } });
        
        // Stock chart
        const stockCtx = document.getElementById('stockChart').getContext('2d');
        const tankNames = <?php echo json_encode(array_column($current_stock, 'tank_name')); ?>;
        const stockLevels = <?php echo json_encode(array_column($current_stock, 'current_stock_liters')); ?>;
        new Chart(stockCtx, { type: 'bar', data: { labels: tankNames, datasets: [{ label: 'Stock (Liters)', data: stockLevels, backgroundColor: 'rgba(255, 193, 7, 0.5)' }] } });
    </script>
</body>
</html>
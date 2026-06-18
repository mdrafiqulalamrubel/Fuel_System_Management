<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Get date range filters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d', strtotime('-30 days'));
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$shift_id = isset($_GET['shift_id']) ? $_GET['shift_id'] : '';
$nozzle_id = isset($_GET['nozzle_id']) ? $_GET['nozzle_id'] : '';

// Build query conditions
$conditions = ["DATE(gs.sale_date) BETWEEN ? AND ?"];
$params = [$from_date, $to_date];

if($shift_id) {
    $conditions[] = "gs.shift_id = ?";
    $params[] = $shift_id;
}

if($nozzle_id) {
    $conditions[] = "gs.nozzle_id = ?";
    $params[] = $nozzle_id;
}

// Only show completed sales (if status column exists, otherwise remove status filter)
// Check if status column exists
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM gas_sales LIKE 'status'");
    $has_status = $stmt->rowCount() > 0;
} catch(Exception $e) {
    $has_status = false;
}

if($has_status) {
    $conditions[] = "gs.status = 'completed'";
}

$where_clause = implode(" AND ", $conditions);

// Get CNG sales data
$stmt = $pdo->prepare("
    SELECT 
        gs.*,
        u.username as operator_name,
        n.nozzle_name,
        sh.shift_name
    FROM gas_sales gs
    JOIN users u ON gs.operator_id = u.id
    JOIN nozzles n ON gs.nozzle_id = n.id
    LEFT JOIN shift_schedule sh ON gs.shift_id = sh.id
    WHERE $where_clause
    ORDER BY gs.sale_date DESC
");
$stmt->execute($params);
$sales = $stmt->fetchAll();

// Calculate totals
$total_sales = count($sales);
$total_units = array_sum(array_column($sales, 'quantity_liters'));
$total_amount = array_sum(array_column($sales, 'total_amount'));

// Calculate cash and credit totals
$total_cash = 0;
$total_credit = 0;
foreach($sales as $sale) {
    if($sale['sale_type'] == 'cash') {
        $total_cash += $sale['total_amount'];
    } else {
        $total_credit += $sale['total_amount'];
    }
}

// Get CNG nozzles
$cng_nozzles = $pdo->query("
    SELECT n.*, p.product_name 
    FROM nozzles n 
    JOIN tanks t ON n.tank_id = t.id 
    JOIN fuel_products p ON t.product_id = p.id 
    WHERE p.product_name IN ('CNG', 'Natural Gas') 
    AND n.is_active = 1
")->fetchAll();

// Get shifts
$shifts = $pdo->query("SELECT * FROM shift_schedule WHERE is_active = 1")->fetchAll();

// Get today's CNG summary
$today = date('Y-m-d');
$today_conditions = ["DATE(sale_date) = ?"];
$today_params = [$today];

if($has_status) {
    $today_conditions[] = "status = 'completed'";
}

$today_where = implode(" AND ", $today_conditions);

$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_sales,
        SUM(quantity_liters) as total_units,
        SUM(total_amount) as total_amount,
        SUM(CASE WHEN sale_type = 'cash' THEN total_amount ELSE 0 END) as cash_amount,
        SUM(CASE WHEN sale_type = 'credit' THEN total_amount ELSE 0 END) as credit_amount
    FROM gas_sales 
    WHERE $today_where
");
$stmt->execute($today_params);
$today_summary = $stmt->fetch();

// If no data, set defaults
if(!$today_summary) {
    $today_summary = [
        'total_sales' => 0,
        'total_units' => 0,
        'total_amount' => 0,
        'cash_amount' => 0,
        'credit_amount' => 0
    ];
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CNG Sales Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        .stats-card {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
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
        .stats-card h3 {
            font-size: 28px;
            margin-bottom: 5px;
        }
        .stats-card p {
            margin: 0;
            opacity: 0.8;
        }
        .stats-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stats-card.purple { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stats-card.orange { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stats-card.blue { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .table-responsive {
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-gas-pump"></i> CNG Sales Report</h2>
                <div>
                    <a href="gas_sales.php" class="btn btn-success">
                        <i class="fas fa-plus"></i> New CNG Sale
                    </a>
                    <button onclick="window.print()" class="btn btn-secondary">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <button onclick="exportCSV()" class="btn btn-info">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                </div>
            </div>
            
            <!-- Today's Summary -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card blue">
                        <i class="fas fa-shopping-cart"></i>
                        <h3><?php echo $today_summary['total_sales'] ?? 0; ?></h3>
                        <p>Today's Sales (Count)</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card green">
                        <i class="fas fa-ruler"></i>
                        <h3><?php echo number_format($today_summary['total_units'] ?? 0, 2); ?> m³</h3>
                        <p>Today's CNG Sold</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card purple">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($today_summary['total_amount'] ?? 0, 2); ?></h3>
                        <p>Today's Revenue</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card orange">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($today_summary['cash_amount'] ?? 0, 2); ?></h3>
                        <p>Cash / Credit</p>
                        <small>Credit: <?php echo $currency; ?> <?php echo number_format($today_summary['credit_amount'] ?? 0, 2); ?></small>
                    </div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <i class="fas fa-filter"></i> Filter Sales
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label>From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label>To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label>Shift</label>
                            <select name="shift_id" class="form-control">
                                <option value="">All Shifts</option>
                                <?php foreach($shifts as $shift): ?>
                                    <option value="<?php echo $shift['id']; ?>" <?php echo $shift_id == $shift['id'] ? 'selected' : ''; ?>>
                                        <?php echo $shift['shift_name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>CNG Nozzle</label>
                            <select name="nozzle_id" class="form-control">
                                <option value="">All Nozzles</option>
                                <?php foreach($cng_nozzles as $nozzle): ?>
                                    <option value="<?php echo $nozzle['id']; ?>" <?php echo $nozzle_id == $nozzle['id'] ? 'selected' : ''; ?>>
                                        <?php echo $nozzle['nozzle_name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Filter
                            </button>
                            <a href="cng_sales_report.php" class="btn btn-secondary">
                                <i class="fas fa-undo"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Summary Stats -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>Total Sales</h5>
                            <h3><?php echo $total_sales; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>Total Units (m³)</h5>
                            <h3><?php echo number_format($total_units, 2); ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>Total Revenue</h5>
                            <h3><?php echo $currency; ?> <?php echo number_format($total_amount, 2); ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>Avg. Price/m³</h5>
                            <h3><?php echo $total_units > 0 ? $currency . ' ' . number_format($total_amount / $total_units, 2) : 'N/A'; ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sales Table -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-list"></i> CNG Sales List
                    <span class="badge bg-light text-dark float-end">Total: <?php echo $total_sales; ?> entries</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="salesTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Invoice</th>
                                    <th>Date/Time</th>
                                    <th>Shift</th>
                                    <th>Nozzle</th>
                                    <th>Operator</th>
                                    <th>Customer</th>
                                    <th class="text-end">Opening (m³)</th>
                                    <th class="text-end">Closing (m³)</th>
                                    <th class="text-end">Quantity (m³)</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end">Total</th>
                                    <th>Type</th>
                                    <?php if($has_status): ?>
                                    <th>Status</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($sales as $sale): ?>
                                    <tr>
                                        <td>
                                            <a href="print_cng_invoice.php?id=<?php echo $sale['id']; ?>" target="_blank">
                                                <?php echo $sale['invoice_no']; ?>
                                            </a>
                                        </td>
                                        <td><?php echo date('d-m-Y h:i A', strtotime($sale['sale_date'])); ?></td>
                                        <td><?php echo $sale['shift_name'] ?? 'N/A'; ?></td>
                                        <td><?php echo $sale['nozzle_name']; ?></td>
                                        <td><?php echo $sale['operator_name']; ?></td>
                                        <td>
                                            <?php if($sale['customer_name']): ?>
                                                <?php echo htmlspecialchars($sale['customer_name']); ?>
                                                <?php if($sale['customer_phone']): ?>
                                                    <br><small><?php echo htmlspecialchars($sale['customer_phone']); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Walk-in</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?php echo number_format($sale['opening_meter'], 2); ?></td>
                                        <td class="text-end"><?php echo number_format($sale['closing_meter'], 2); ?></td>
                                        <td class="text-end fw-bold"><?php echo number_format($sale['quantity_liters'], 2); ?></td>
                                        <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['unit_price'], 2); ?></td>
                                        <td class="text-end fw-bold text-success"><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></td>
                                        <td>
                                            <?php if($sale['sale_type'] == 'cash'): ?>
                                                <span class="badge bg-success">Cash</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Credit</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if($has_status): ?>
                                        <td>
                                            <?php if($sale['status'] == 'completed'): ?>
                                                <span class="badge bg-success">Completed</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Cancelled</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if(empty($sales)): ?>
                                    <tr>
                                        <td colspan="<?php echo $has_status ? 13 : 12; ?>" class="text-center text-muted">
                                            <i class="fas fa-info-circle"></i> No CNG sales found for the selected period
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="8" class="text-end">TOTALS:</td>
                                    <td class="text-end"><?php echo number_format($total_units, 2); ?> m³</td>
                                    <td></td>
                                    <td class="text-end text-success"><?php echo $currency; ?> <?php echo number_format($total_amount, 2); ?></td>
                                    <td colspan="<?php echo $has_status ? 2 : 1; ?>"></td>
                                </tr>
                            </tfoot>
                        </table>
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
            $('#salesTable').DataTable({
                order: [[1, 'desc']],
                pageLength: 50,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
        
        function exportCSV() {
            var params = new URLSearchParams(window.location.search);
            window.location.href = 'export_cng_sales.php?' + params.toString();
        }
    </script>
</body>
</html>
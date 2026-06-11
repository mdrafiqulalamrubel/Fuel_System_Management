<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

// Get sales data within date range
$stmt = $pdo->prepare("
    SELECT s.*, p.product_name, n.nozzle_name, u.full_name as operator_name, sh.shift_name 
    FROM sales s 
    JOIN fuel_products p ON s.product_id = p.id 
    JOIN nozzles n ON s.nozzle_id = n.id 
    JOIN users u ON s.operator_id = u.id 
    JOIN shifts sh ON s.shift_id = sh.id 
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    ORDER BY s.sale_date DESC
");
$stmt->execute([$from_date, $to_date]);
$sales = $stmt->fetchAll();

// Get summary
$stmt = $pdo->prepare("
    SELECT 
        SUM(total_amount) as total_sales,
        SUM(quantity_liters) as total_liters,
        COUNT(*) as total_transactions,
        SUM(CASE WHEN sale_type = 'cash' THEN total_amount ELSE 0 END) as cash_sales,
        SUM(CASE WHEN sale_type = 'credit' THEN total_amount ELSE 0 END) as credit_sales,
        SUM(vat_amount) as total_vat,
        SUM(tax_amount) as total_tax
    FROM sales 
    WHERE DATE(sale_date) BETWEEN ? AND ?
");
$stmt->execute([$from_date, $to_date]);
$summary = $stmt->fetch();

// Get product-wise sales
$stmt = $pdo->prepare("
    SELECT p.product_name, SUM(s.quantity_liters) as liters, SUM(s.total_amount) as amount
    FROM sales s 
    JOIN fuel_products p ON s.product_id = p.id 
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY p.product_name
");
$stmt->execute([$from_date, $to_date]);
$product_sales = $stmt->fetchAll();

// Get shift-wise sales
$stmt = $pdo->prepare("
    SELECT sh.shift_name, SUM(s.total_amount) as amount, SUM(s.quantity_liters) as liters
    FROM sales s 
    JOIN shifts sh ON s.shift_id = sh.id 
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY sh.shift_name
");
$stmt->execute([$from_date, $to_date]);
$shift_sales = $stmt->fetchAll();

// Get daily breakdown
$stmt = $pdo->prepare("
    SELECT DATE(sale_date) as sale_date, 
           SUM(total_amount) as daily_total,
           SUM(quantity_liters) as daily_liters,
           COUNT(*) as daily_transactions
    FROM sales 
    WHERE DATE(sale_date) BETWEEN ? AND ?
    GROUP BY DATE(sale_date)
    ORDER BY sale_date
");
$stmt->execute([$from_date, $to_date]);
$daily_breakdown = $stmt->fetchAll();

// Get nozzle-wise sales
$stmt = $pdo->prepare("
    SELECT n.nozzle_name, SUM(s.total_amount) as amount, SUM(s.quantity_liters) as liters
    FROM sales s 
    JOIN nozzles n ON s.nozzle_id = n.id 
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY n.nozzle_name
");
$stmt->execute([$from_date, $to_date]);
$nozzle_sales = $stmt->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';

// Calculate number of days in range
$start = new DateTime($from_date);
$end = new DateTime($to_date);
$days_count = $start->diff($end)->days + 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
        }
        
        @media print {
            .no-print { display: none; }
            body { margin: 0; padding: 20px; }
            .report-header { text-align: center; margin-bottom: 20px; }
            .card { border: none; box-shadow: none; }
            .card-header { background: #f0f0f0 !important; }
            .table { border-collapse: collapse; width: 100%; }
            .table th, .table td { border: 1px solid #000; padding: 6px; }
        }
        
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            transition: margin-left 0.3s;
        }
        
        .stats-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .summary-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .report-header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: white;
            border-radius: 10px;
        }
        
        .table thead th {
            background-color: #343a40;
            color: white;
            border-color: #454d55;
        }
        
        .table tfoot td {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- Header with Buttons -->
            <div class="d-flex justify-content-between align-items-center flex-wrap mb-4 no-print">
                <h2><i class="fas fa-chart-line"></i> Sales Report</h2>
                <div>                    
                    <a href="print_sales_report.php?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" class="btn btn-info" target="_blank">
                        <i class="fas fa-print"></i> Print Report
                    </a>
                    <a href="export_sales.php?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" class="btn btn-success">
                        <i class="fas fa-file-excel"></i> Export to Excel
                    </a>
                    <a href="reports.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- Date Range Filter -->
            <div class="card no-print mb-4">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-calendar-alt"></i> Filter by Date Range</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary form-control">
                                <i class="fas fa-search"></i> Generate Report
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Report Header (for print) -->
            <div class="report-header">
                <h2><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h2>
                <h3>SALES REPORT</h3>
                <p>Period: <?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                <p>Generated on: <?php echo date('d/m/Y h:i:s A'); ?></p>
            </div>
            
            <!-- Statistics Row 1 -->
            <div class="row mb-4">
                <div class="col-md-3 col-sm-6">
                    <div class="stats-box">
                        <i class="fas fa-chart-line fa-2x mb-2"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($summary['total_sales'] ?? 0, 2); ?></h3>
                        <p class="mb-0">Total Sales</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stats-box" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-oil-can fa-2x mb-2"></i>
                        <h3><?php echo number_format($summary['total_liters'] ?? 0, 2); ?> L</h3>
                        <p class="mb-0">Total Liters Sold</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stats-box" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-receipt fa-2x mb-2"></i>
                        <h3><?php echo $summary['total_transactions'] ?? 0; ?></h3>
                        <p class="mb-0">Total Transactions</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stats-box" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-calendar-day fa-2x mb-2"></i>
                        <h3><?php echo $days_count; ?> Days</h3>
                        <p class="mb-0">Report Period</p>
                    </div>
                </div>
            </div>
            
            <!-- Statistics Row 2 -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="summary-card">
                        <h6><i class="fas fa-money-bill-wave"></i> Payment Breakdown</h6>
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td>Cash Sales:</td>
                                <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($summary['cash_sales'] ?? 0, 2); ?></td>
                            </tr>
                            <tr>
                                <td>Credit Sales:</td>
                                <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($summary['credit_sales'] ?? 0, 2); ?></td>
                            </tr>
                            <tr>
                                <td>VAT Collected:</td>
                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($summary['total_vat'] ?? 0, 2); ?></td>
                            </tr>
                            <tr>
                                <td>Tax Collected:</td>
                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($summary['total_tax'] ?? 0, 2); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="summary-card">
                        <h6><i class="fas fa-chart-pie"></i> Shift-wise Sales</h6>
                        <table class="table table-sm table-borderless">
                            <?php foreach($shift_sales as $shift): ?>
                            <tr>
                                <td><?php echo $shift['shift_name']; ?>:</td>
                                <td class="text-end"><?php echo number_format($shift['liters'], 2); ?> L</td>
                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($shift['amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="summary-card">
                        <h6><i class="fas fa-tachometer-alt"></i> Averages</h6>
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td>Daily Average:</td>
                                <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format(($summary['total_sales'] ?? 0) / max(1, $days_count), 2); ?></td>
                            </tr>
                            <tr>
                                <td>Per Transaction:</td>
                                <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format(($summary['total_sales'] ?? 0) / max(1, $summary['total_transactions'] ?? 1), 2); ?></td>
                            </tr>
                            <tr>
                                <td>Avg Liters/Day:</td>
                                <td class="text-end fw-bold"><?php echo number_format(($summary['total_liters'] ?? 0) / max(1, $days_count), 2); ?> L</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Product-wise Sales -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5><i class="fas fa-box"></i> Product-wise Sales</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Quantity (Liters)</th>
                                    <th class="text-end">Amount (<?php echo $currency; ?>)</th>
                                    <th class="text-end">Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $total_amount = array_sum(array_column($product_sales, 'amount')); ?>
                                <?php foreach($product_sales as $prod): ?>
                                <?php $percent = $total_amount > 0 ? ($prod['amount'] / $total_amount) * 100 : 0; ?>
                                <tr>
                                    <td><strong><?php echo $prod['product_name']; ?></strong></td>
                                    <td class="text-end"><?php echo number_format($prod['liters'], 2); ?> L</td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($prod['amount'], 2); ?></td>
                                    <td class="text-end"><?php echo number_format($percent, 1); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td class="text-end">TOTAL:</td>
                                    <td class="text-end"><?php echo number_format($summary['total_liters'] ?? 0, 2); ?> L</td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($summary['total_sales'] ?? 0, 2); ?></td>
                                    <td class="text-end">100%</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Daily Breakdown -->
            <div class="card mb-4">
                <div class="card-header bg-warning text-dark">
                    <h5><i class="fas fa-calendar-day"></i> Daily Breakdown</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="dailyTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th class="text-end">Day</th>
                                    <th class="text-end">Liters</th>
                                    <th class="text-end">Transactions</th>
                                    <th class="text-end">Amount (<?php echo $currency; ?>)</th>
                                    <th class="text-end">Average</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($daily_breakdown as $day): 
                                    $day_name = date('l', strtotime($day['sale_date']));
                                    $avg_per_trans = $day['daily_transactions'] > 0 ? $day['daily_total'] / $day['daily_transactions'] : 0;
                                ?>
                                <tr>
                                    <td><?php echo date('d-m-Y', strtotime($day['sale_date'])); ?></td>
                                    <td class="text-end"><?php echo $day_name; ?></td>
                                    <td class="text-end"><?php echo number_format($day['daily_liters'], 2); ?> L</td>
                                    <td class="text-end"><?php echo $day['daily_transactions']; ?></td>
                                    <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($day['daily_total'], 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($avg_per_trans, 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td class="text-end" colspan="2">TOTAL:</td>
                                    <td class="text-end"><?php echo number_format($summary['total_liters'] ?? 0, 2); ?> L</td>
                                    <td class="text-end"><?php echo $summary['total_transactions'] ?? 0; ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($summary['total_sales'] ?? 0, 2); ?></td>
                                    <td class="text-end">-</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Sales Details Table -->
            <div class="card">
                <div class="card-header bg-dark text-white">
                    <h5><i class="fas fa-list"></i> Sales Details</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="salesTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Invoice No</th>
                                    <th>Product</th>
                                    <th class="text-end">Liters</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end">Total</th>
                                    <th>Type</th>
                                    <th>Operator</th>
                                    <th>Customer</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($sales as $sale): ?>
                                <tr>
                                    <td><?php echo date('d-m-Y', strtotime($sale['sale_date'])); ?></td>
                                    <td><?php echo date('H:i:s', strtotime($sale['sale_date'])); ?></td>
                                    <td><?php echo $sale['invoice_no']; ?></td>
                                    <td><?php echo $sale['product_name']; ?></td>
                                    <td class="text-end"><?php echo number_format($sale['quantity_liters'], 2); ?> L</td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['unit_price'], 2); ?></td>
                                    <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $sale['sale_type'] == 'cash' ? 'success' : 'warning'; ?>">
                                            <?php echo ucfirst($sale['sale_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $sale['operator_name']; ?></td>
                                    <td><?php echo $sale['customer_name'] ?: '-'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($sales)): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted">No sales found for the selected period</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Report Footer -->
            <div class="text-center mt-4 text-muted">
                <hr>
                <p>This is a computer generated report. Valid with authorized signature.</p>
                <p>*** End of Report ***</p>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#salesTable, #dailyTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
    </script>
</body>
</html>
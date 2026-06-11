<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$export_type = isset($_GET['export_type']) ? $_GET['export_type'] : 'excel';

// Get sales data
$stmt = $pdo->prepare("
    SELECT 
        DATE(s.sale_date) as sale_date,
        TIME(s.sale_date) as sale_time,
        s.invoice_no,
        p.product_name,
        s.quantity_liters,
        s.unit_price,
        s.subtotal,
        s.vat_amount,
        s.tax_amount,
        s.total_amount,
        s.sale_type,
        s.customer_name,
        s.customer_phone,
        u.full_name as operator_name,
        sh.shift_name
    FROM sales s 
    JOIN fuel_products p ON s.product_id = p.id 
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
        SUM(CASE WHEN sale_type = 'credit' THEN total_amount ELSE 0 END) as credit_sales
    FROM sales 
    WHERE DATE(sale_date) BETWEEN ? AND ?
");
$stmt->execute([$from_date, $to_date]);
$summary = $stmt->fetch();

// Handle Export
if(isset($_GET['export']) && $_GET['export'] == 1) {
    $export_type = isset($_GET['export_type']) ? $_GET['export_type'] : 'excel';
    
    if($export_type == 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="sales_report_'.date('Y-m-d').'.xls"');
        
        echo "SALES REPORT\n";
        echo "Period: " . date('d-m-Y', strtotime($from_date)) . " to " . date('d-m-Y', strtotime($to_date)) . "\n";
        echo "Generated on: " . date('d-m-Y H:i:s') . "\n\n";
        
        echo "Date\tTime\tInvoice No\tProduct\tLiters\tUnit Price\tSubtotal\tVAT\tTax\tTotal\tType\tCustomer\tOperator\tShift\n";
        foreach($sales as $s) {
            echo date('d-m-Y', strtotime($s['sale_date'])) . "\t";
            echo $s['sale_time'] . "\t";
            echo $s['invoice_no'] . "\t";
            echo $s['product_name'] . "\t";
            echo $s['quantity_liters'] . "\t";
            echo $s['unit_price'] . "\t";
            echo $s['subtotal'] . "\t";
            echo $s['vat_amount'] . "\t";
            echo $s['tax_amount'] . "\t";
            echo $s['total_amount'] . "\t";
            echo $s['sale_type'] . "\t";
            echo ($s['customer_name'] ?: '-') . "\t";
            echo $s['operator_name'] . "\t";
            echo $s['shift_name'] . "\n";
        }
        
        echo "\n\nSUMMARY\n";
        echo "Total Sales:\t" . ($summary['total_sales'] ?? 0) . "\n";
        echo "Total Liters:\t" . ($summary['total_liters'] ?? 0) . "\n";
        echo "Total Transactions:\t" . ($summary['total_transactions'] ?? 0) . "\n";
        echo "Cash Sales:\t" . ($summary['cash_sales'] ?? 0) . "\n";
        echo "Credit Sales:\t" . ($summary['credit_sales'] ?? 0) . "\n";
        exit;
    }
    
    elseif($export_type == 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="sales_report_'.date('Y-m-d').'.csv"');
        
        $output = fopen('php://output', 'w');
        
        fputcsv($output, ['SALES REPORT']);
        fputcsv($output, ['Period:', date('d-m-Y', strtotime($from_date)) . ' to ' . date('d-m-Y', strtotime($to_date))]);
        fputcsv($output, ['Generated on:', date('d-m-Y H:i:s')]);
        fputcsv($output, []);
        
        fputcsv($output, ['Date', 'Time', 'Invoice No', 'Product', 'Liters', 'Unit Price', 'Subtotal', 'VAT', 'Tax', 'Total', 'Type', 'Customer', 'Operator', 'Shift']);
        
        foreach($sales as $s) {
            fputcsv($output, [
                date('d-m-Y', strtotime($s['sale_date'])),
                $s['sale_time'],
                $s['invoice_no'],
                $s['product_name'],
                $s['quantity_liters'],
                $s['unit_price'],
                $s['subtotal'],
                $s['vat_amount'],
                $s['tax_amount'],
                $s['total_amount'],
                $s['sale_type'],
                $s['customer_name'] ?: '-',
                $s['operator_name'],
                $s['shift_name']
            ]);
        }
        
        fputcsv($output, []);
        fputcsv($output, ['SUMMARY']);
        fputcsv($output, ['Total Sales', $summary['total_sales'] ?? 0]);
        fputcsv($output, ['Total Liters', $summary['total_liters'] ?? 0]);
        fputcsv($output, ['Total Transactions', $summary['total_transactions'] ?? 0]);
        fputcsv($output, ['Cash Sales', $summary['cash_sales'] ?? 0]);
        fputcsv($output, ['Credit Sales', $summary['credit_sales'] ?? 0]);
        
        fclose($output);
        exit;
    }
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Sales Data</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            transition: margin-left 0.3s;
        }
        
        .export-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.3s;
            margin-bottom: 20px;
        }
        
        .export-card:hover {
            transform: translateY(-5px);
        }
        
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            text-align: center;
        }
        
        .stats-card h3 {
            margin: 10px 0 0;
            font-size: 24px;
        }
        
        .preview-table th {
            background-color: #343a40;
            color: white;
            text-align: center;
        }
        
        .preview-table td {
            vertical-align: middle;
        }
        
        .text-end {
            text-align: right;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
                <h2><i class="fas fa-file-export"></i> Export Sales Data</h2>
                <a href="daily_sales_report.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Report
                </a>
            </div>
            
            <!-- Date Range Selection -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-calendar-alt"></i> Select Date Range</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3" id="exportForm">
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
                            <button type="button" class="btn btn-primary form-control" onclick="loadData()">
                                <i class="fas fa-search"></i> Load Data
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="row mb-4" id="statsSection">
                <div class="col-md-3 col-sm-6">
                    <div class="stats-card">
                        <i class="fas fa-chart-line fa-2x text-primary"></i>
                        <h6 class="mt-2">Total Sales</h6>
                        <h3><?php echo $currency; ?> <?php echo number_format($summary['total_sales'] ?? 0, 2); ?></h3>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stats-card">
                        <i class="fas fa-oil-can fa-2x text-success"></i>
                        <h6 class="mt-2">Total Liters</h6>
                        <h3><?php echo number_format($summary['total_liters'] ?? 0, 2); ?> L</h3>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stats-card">
                        <i class="fas fa-receipt fa-2x text-info"></i>
                        <h6 class="mt-2">Total Transactions</h6>
                        <h3><?php echo $summary['total_transactions'] ?? 0; ?></h3>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stats-card">
                        <i class="fas fa-database fa-2x text-warning"></i>
                        <h6 class="mt-2">Records Found</h6>
                        <h3><?php echo count($sales); ?></h3>
                    </div>
                </div>
            </div>
            
            <!-- Export Options -->
            <h4 class="mb-3">Choose Export Format</h4>
            <div class="row">
                <div class="col-md-4">
                    <div class="export-card" onclick="exportData('excel')">
                        <i class="fas fa-file-excel fa-3x mb-3"></i>
                        <h4>Excel Format (.xls)</h4>
                        <p>Export to Microsoft Excel compatible format</p>
                        <span class="badge bg-light text-dark">Best for analysis</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="export-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);" onclick="exportData('csv')">
                        <i class="fas fa-file-csv fa-3x mb-3"></i>
                        <h4>CSV Format (.csv)</h4>
                        <p>Comma separated values - universal format</p>
                        <span class="badge bg-light text-dark">Best for databases</span>
                    </div>
                </div>
            </div>
            
            <!-- Data Preview -->
            <div class="card mt-4">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-eye"></i> Data Preview (Last 10 Records)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover preview-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Invoice No</th>
                                    <th>Product</th>
                                    <th class="text-end">Liters</th>
                                    <th class="text-end">Total (<?php echo $currency; ?>)</th>
                                    <th>Type</th>
                                    <th>Customer</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $preview = array_slice($sales, 0, 10);
                                if(!empty($preview)):
                                    foreach($preview as $s): 
                                ?>
                                <tr>
                                    <td><?php echo date('d-m-Y', strtotime($s['sale_date'])); ?></td>
                                    <td><?php echo $s['invoice_no']; ?></td>
                                    <td><?php echo $s['product_name']; ?></td>
                                    <td class="text-end"><?php echo number_format($s['quantity_liters'], 2); ?> L</td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($s['total_amount'], 2); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $s['sale_type'] == 'cash' ? 'success' : 'warning'; ?>">
                                            <?php echo ucfirst($s['sale_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $s['customer_name'] ?: '-'; ?></td>
                                <tr>
                                <?php 
                                    endforeach;
                                else:
                                ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">No data found for selected period</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function loadData() {
            let from_date = document.querySelector('input[name="from_date"]').value;
            let to_date = document.querySelector('input[name="to_date"]').value;
            
            if(!from_date || !to_date) {
                alert('Please select both from and to dates');
                return;
            }
            
            window.location.href = 'export_sales.php?from_date=' + from_date + '&to_date=' + to_date;
        }
        
        function exportData(format) {
            let from_date = document.querySelector('input[name="from_date"]').value;
            let to_date = document.querySelector('input[name="to_date"]').value;
            
            if(!from_date || !to_date) {
                alert('Please select date range first');
                return;
            }
            
            window.location.href = 'export_sales.php?export=1&export_type=' + format + '&from_date=' + from_date + '&to_date=' + to_date;
        }
    </script>
</body>
</html>
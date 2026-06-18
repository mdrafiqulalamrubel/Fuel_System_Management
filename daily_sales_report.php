<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

// =============================================
// 1. GET LIQUID SALES
// =============================================
$stmt = $pdo->prepare("
    SELECT 
        s.*, 
        p.product_name, 
        n.nozzle_name, 
        u.full_name as operator_name, 
        sh.shift_name,
        'Liquid' as sale_type_label,
        'L' as unit_label
    FROM sales s 
    JOIN fuel_products p ON s.product_id = p.id 
    JOIN nozzles n ON s.nozzle_id = n.id 
    JOIN users u ON s.operator_id = u.id 
    LEFT JOIN shift_schedule sh ON s.shift_id = sh.id 
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    ORDER BY s.sale_date DESC
");
$stmt->execute([$from_date, $to_date]);
$liquid_sales = $stmt->fetchAll();

// =============================================
// 2. GET CNG SALES
// =============================================
$stmt = $pdo->prepare("
    SELECT 
        gs.*, 
        p.product_name, 
        n.nozzle_name, 
        u.full_name as operator_name, 
        sh.shift_name,
        'CNG' as sale_type_label,
        'm³' as unit_label
    FROM gas_sales gs 
    JOIN nozzles n ON gs.nozzle_id = n.id 
    JOIN fuel_products p ON n.product_id = p.id 
    JOIN users u ON gs.operator_id = u.id 
    LEFT JOIN shift_schedule sh ON gs.shift_id = sh.id 
    WHERE DATE(gs.sale_date) BETWEEN ? AND ? 
    AND gs.status = 'completed'
    ORDER BY gs.sale_date DESC
");
$stmt->execute([$from_date, $to_date]);
$cng_sales = $stmt->fetchAll();

// =============================================
// 3. COMBINE ALL SALES
// =============================================
$all_sales = array_merge($liquid_sales, $cng_sales);
usort($all_sales, function($a, $b) {
    return strtotime($b['sale_date']) - strtotime($a['sale_date']);
});

// =============================================
// 4. SUMMARY - LIQUID
// =============================================
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
$liquid_summary = $stmt->fetch();

// =============================================
// 5. SUMMARY - CNG
// =============================================
$stmt = $pdo->prepare("
    SELECT 
        SUM(total_amount) as total_sales,
        SUM(quantity_liters) as total_liters,
        COUNT(*) as total_transactions,
        SUM(CASE WHEN sale_type = 'cash' THEN total_amount ELSE 0 END) as cash_sales,
        SUM(CASE WHEN sale_type = 'credit' THEN total_amount ELSE 0 END) as credit_sales,
        0 as total_vat,
        0 as total_tax
    FROM gas_sales 
    WHERE DATE(sale_date) BETWEEN ? AND ? 
    AND status = 'completed'
");
$stmt->execute([$from_date, $to_date]);
$cng_summary = $stmt->fetch();

// =============================================
// 6. COMBINED SUMMARY
// =============================================
$summary = [
    'total_sales' => ($liquid_summary['total_sales'] ?? 0) + ($cng_summary['total_sales'] ?? 0),
    'total_liters' => ($liquid_summary['total_liters'] ?? 0) + ($cng_summary['total_liters'] ?? 0),
    'total_transactions' => ($liquid_summary['total_transactions'] ?? 0) + ($cng_summary['total_transactions'] ?? 0),
    'cash_sales' => ($liquid_summary['cash_sales'] ?? 0) + ($cng_summary['cash_sales'] ?? 0),
    'credit_sales' => ($liquid_summary['credit_sales'] ?? 0) + ($cng_summary['credit_sales'] ?? 0),
    'total_vat' => $liquid_summary['total_vat'] ?? 0,
    'total_tax' => $liquid_summary['total_tax'] ?? 0,
    'liquid_sales' => $liquid_summary['total_sales'] ?? 0,
    'cng_sales' => $cng_summary['total_sales'] ?? 0,
    'liquid_liters' => $liquid_summary['total_liters'] ?? 0,
    'cng_liters' => $cng_summary['total_liters'] ?? 0,
    'liquid_transactions' => $liquid_summary['total_transactions'] ?? 0,
    'cng_transactions' => $cng_summary['total_transactions'] ?? 0,
];

// =============================================
// 7. PRODUCT-WISE SALES (LIQUID)
// =============================================
$stmt = $pdo->prepare("
    SELECT p.product_name, SUM(s.quantity_liters) as liters, SUM(s.total_amount) as amount, 'Liquid' as type
    FROM sales s 
    JOIN fuel_products p ON s.product_id = p.id 
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY p.product_name
");
$stmt->execute([$from_date, $to_date]);
$product_sales = $stmt->fetchAll();

// 8. PRODUCT-WISE SALES (CNG)
$stmt = $pdo->prepare("
    SELECT 'CNG' as product_name, SUM(quantity_liters) as liters, SUM(total_amount) as amount, 'CNG' as type
    FROM gas_sales 
    WHERE DATE(sale_date) BETWEEN ? AND ? 
    AND status = 'completed'
");
$stmt->execute([$from_date, $to_date]);
$cng_product = $stmt->fetch();

if($cng_product && $cng_product['amount'] > 0) {
    $product_sales[] = $cng_product;
}

// =============================================
// 9. SHIFT-WISE SALES (LIQUID)
// =============================================
$stmt = $pdo->prepare("
    SELECT sh.shift_name, SUM(s.total_amount) as amount, SUM(s.quantity_liters) as liters, 'Liquid' as type
    FROM sales s 
    LEFT JOIN shift_schedule sh ON s.shift_id = sh.id 
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY sh.shift_name
");
$stmt->execute([$from_date, $to_date]);
$shift_sales = $stmt->fetchAll();

// 10. SHIFT-WISE SALES (CNG)
$stmt = $pdo->prepare("
    SELECT sh.shift_name, SUM(gs.total_amount) as amount, SUM(gs.quantity_liters) as liters, 'CNG' as type
    FROM gas_sales gs
    LEFT JOIN shift_schedule sh ON gs.shift_id = sh.id 
    WHERE DATE(gs.sale_date) BETWEEN ? AND ? 
    AND gs.status = 'completed'
    GROUP BY sh.shift_name
");
$stmt->execute([$from_date, $to_date]);
$cng_shift_sales = $stmt->fetchAll();

$shift_sales = array_merge($shift_sales, $cng_shift_sales);

// =============================================
// 11. DAILY BREAKDOWN - COMBINED
// =============================================
$stmt = $pdo->prepare("
    SELECT DATE(sale_date) as sale_date, 
           SUM(total_amount) as daily_total,
           SUM(quantity_liters) as daily_liters,
           COUNT(*) as daily_transactions,
           'Liquid' as type
    FROM sales 
    WHERE DATE(sale_date) BETWEEN ? AND ?
    GROUP BY DATE(sale_date)
");
$stmt->execute([$from_date, $to_date]);
$daily_liquid = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT DATE(sale_date) as sale_date, 
           SUM(total_amount) as daily_total,
           SUM(quantity_liters) as daily_liters,
           COUNT(*) as daily_transactions,
           'CNG' as type
    FROM gas_sales 
    WHERE DATE(sale_date) BETWEEN ? AND ? 
    AND status = 'completed'
    GROUP BY DATE(sale_date)
");
$stmt->execute([$from_date, $to_date]);
$daily_cng = $stmt->fetchAll();

// Combine daily breakdown
$daily_combined = [];
$all_dates = array_unique(array_merge(
    array_column($daily_liquid, 'sale_date'),
    array_column($daily_cng, 'sale_date')
));
sort($all_dates);

foreach($all_dates as $date) {
    $liquid_total = 0;
    $cng_total = 0;
    $liquid_liters = 0;
    $cng_liters = 0;
    $liquid_trans = 0;
    $cng_trans = 0;
    
    foreach($daily_liquid as $dl) {
        if($dl['sale_date'] == $date) {
            $liquid_total = $dl['daily_total'];
            $liquid_liters = $dl['daily_liters'];
            $liquid_trans = $dl['daily_transactions'];
            break;
        }
    }
    foreach($daily_cng as $dc) {
        if($dc['sale_date'] == $date) {
            $cng_total = $dc['daily_total'];
            $cng_liters = $dc['daily_liters'];
            $cng_trans = $dc['daily_transactions'];
            break;
        }
    }
    
    $daily_combined[] = [
        'sale_date' => $date,
        'liquid_total' => $liquid_total,
        'cng_total' => $cng_total,
        'daily_total' => $liquid_total + $cng_total,
        'liquid_liters' => $liquid_liters,
        'cng_liters' => $cng_liters,
        'daily_liters' => $liquid_liters + $cng_liters,
        'liquid_trans' => $liquid_trans,
        'cng_trans' => $cng_trans,
        'daily_transactions' => $liquid_trans + $cng_trans
    ];
}

// =============================================
// 12. NOZZLE-WISE SALES (LIQUID)
// =============================================
$stmt = $pdo->prepare("
    SELECT n.nozzle_name, SUM(s.total_amount) as amount, SUM(s.quantity_liters) as liters, 'Liquid' as type
    FROM sales s 
    JOIN nozzles n ON s.nozzle_id = n.id 
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY n.nozzle_name
");
$stmt->execute([$from_date, $to_date]);
$nozzle_sales = $stmt->fetchAll();

// 13. NOZZLE-WISE SALES (CNG)
$stmt = $pdo->prepare("
    SELECT n.nozzle_name, SUM(gs.total_amount) as amount, SUM(gs.quantity_liters) as liters, 'CNG' as type
    FROM gas_sales gs
    JOIN nozzles n ON gs.nozzle_id = n.id 
    WHERE DATE(gs.sale_date) BETWEEN ? AND ? 
    AND gs.status = 'completed'
    GROUP BY n.nozzle_name
");
$stmt->execute([$from_date, $to_date]);
$cng_nozzle_sales = $stmt->fetchAll();

$nozzle_sales = array_merge($nozzle_sales, $cng_nozzle_sales);

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
    <title>Sales Report - All Sales (Liquid + CNG)</title>
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
        
        .stats-box-cng {
            background: linear-gradient(135deg, #17a2b8 0%, #0d6efd 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .stats-box-liquid {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
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
        
        .badge-cng { background: #17a2b8; color: white; }
        .badge-liquid { background: #28a745; color: white; }
        
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
                <h2><i class="fas fa-chart-line"></i> Sales Report (All Sales)</h2>
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
                <h3>COMPLETE SALES REPORT</h3>
                <p>Period: <?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                <p>Generated on: <?php echo date('d/m/Y h:i:s A'); ?></p>
                <div class="mt-2">
                    <span class="badge badge-liquid">Liquid Sales</span>
                    <span class="badge badge-cng">CNG Sales</span>
                </div>
            </div>
            
            <!-- Statistics Row 1 - Combined Summary -->
            <div class="row mb-4">
                <div class="col-md-3 col-sm-6">
                    <div class="stats-box">
                        <i class="fas fa-chart-line fa-2x mb-2"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($summary['total_sales'] ?? 0, 2); ?></h3>
                        <p class="mb-0">Total Sales (All)</p>
                        <small>Liquid: <?php echo $currency; ?> <?php echo number_format($summary['liquid_sales'] ?? 0, 2); ?> | CNG: <?php echo $currency; ?> <?php echo number_format($summary['cng_sales'] ?? 0, 2); ?></small>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stats-box" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-oil-can fa-2x mb-2"></i>
                        <h3><?php echo number_format($summary['total_liters'] ?? 0, 2); ?> <?php echo $summary['total_liters'] > 0 ? 'L/m³' : ''; ?></h3>
                        <p class="mb-0">Total Quantity Sold</p>
                        <small>Liquid: <?php echo number_format($summary['liquid_liters'] ?? 0, 2); ?> L | CNG: <?php echo number_format($summary['cng_liters'] ?? 0, 2); ?> m³</small>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stats-box" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-receipt fa-2x mb-2"></i>
                        <h3><?php echo $summary['total_transactions'] ?? 0; ?></h3>
                        <p class="mb-0">Total Transactions</p>
                        <small>Liquid: <?php echo $summary['liquid_transactions'] ?? 0; ?> | CNG: <?php echo $summary['cng_transactions'] ?? 0; ?></small>
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
            
            <!-- Statistics Row 2 - Liquid vs CNG Comparison -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="stats-box-liquid">
                        <i class="fas fa-oil-can fa-2x mb-2"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($summary['liquid_sales'] ?? 0, 2); ?></h3>
                        <p class="mb-0">Liquid Fuel Sales</p>
                        <small><?php echo number_format($summary['liquid_liters'] ?? 0, 2); ?> Liters | <?php echo $summary['liquid_transactions'] ?? 0; ?> Transactions</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stats-box-cng">
                        <i class="fas fa-gas-pump fa-2x mb-2"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($summary['cng_sales'] ?? 0, 2); ?></h3>
                        <p class="mb-0">CNG Sales</p>
                        <small><?php echo number_format($summary['cng_liters'] ?? 0, 2); ?> m³ | <?php echo $summary['cng_transactions'] ?? 0; ?> Transactions</small>
                    </div>
                </div>
            </div>
            
            <!-- Payment Breakdown -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="summary-card">
                        <h6><i class="fas fa-money-bill-wave"></i> Payment Breakdown (All Sales)</h6>
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
                            <?php 
                            $shift_totals = [];
                            foreach($shift_sales as $shift) {
                                $key = $shift['shift_name'] ?? 'Unknown';
                                if(!isset($shift_totals[$key])) {
                                    $shift_totals[$key] = ['amount' => 0, 'liters' => 0];
                                }
                                $shift_totals[$key]['amount'] += $shift['amount'];
                                $shift_totals[$key]['liters'] += $shift['liters'];
                            }
                            foreach($shift_totals as $name => $data): 
                            ?>
                            <tr>
                                <td><?php echo $name; ?>:</td>
                                <td class="text-end"><?php echo number_format($data['liters'], 2); ?> L</td>
                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($data['amount'], 2); ?></td>
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
                                <td>Avg Quantity/Day:</td>
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
                                    <th>Type</th>
                                    <th class="text-end">Quantity</th>
                                    <th class="text-end">Amount (<?php echo $currency; ?>)</th>
                                    <th class="text-end">Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $total_amount = array_sum(array_column($product_sales, 'amount')); ?>
                                <?php foreach($product_sales as $prod): 
                                    $percent = $total_amount > 0 ? ($prod['amount'] / $total_amount) * 100 : 0;
                                    $is_cng = $prod['type'] == 'CNG';
                                    $unit_label = $is_cng ? 'm³' : 'L';
                                    $badge_class = $is_cng ? 'badge-cng' : 'badge-liquid';
                                ?>
                                <tr>
                                    <td><strong><?php echo $prod['product_name']; ?></strong></td>
                                    <td><span class="badge <?php echo $badge_class; ?>"><?php echo $prod['type']; ?></span></td>
                                    <td class="text-end"><?php echo number_format($prod['liters'], 2); ?> <?php echo $unit_label; ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($prod['amount'], 2); ?></td>
                                    <td class="text-end"><?php echo number_format($percent, 1); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td class="text-end" colspan="2">TOTAL:</td>
                                    <td class="text-end"><?php echo number_format($summary['total_liters'] ?? 0, 2); ?> L/m³</td>
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
                    <h5><i class="fas fa-calendar-day"></i> Daily Breakdown (Liquid + CNG)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="dailyTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th class="text-end">Day</th>
                                    <th class="text-end">Liquid (L)</th>
                                    <th class="text-end">CNG (m³)</th>
                                    <th class="text-end">Total Qty</th>
                                    <th class="text-end">Liquid Sales</th>
                                    <th class="text-end">CNG Sales</th>
                                    <th class="text-end">Total Sales</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($daily_combined as $day): 
                                    $day_name = date('l', strtotime($day['sale_date']));
                                ?>
                                <tr>
                                    <td><?php echo date('d-m-Y', strtotime($day['sale_date'])); ?></td>
                                    <td class="text-end"><?php echo $day_name; ?></td>
                                    <td class="text-end"><?php echo number_format($day['liquid_liters'], 2); ?></td>
                                    <td class="text-end"><?php echo number_format($day['cng_liters'], 2); ?></td>
                                    <td class="text-end fw-bold"><?php echo number_format($day['daily_liters'], 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($day['liquid_total'], 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($day['cng_total'], 2); ?></td>
                                    <td class="text-end fw-bold text-success"><?php echo $currency; ?> <?php echo number_format($day['daily_total'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td class="text-end" colspan="2">TOTAL:</td>
                                    <td class="text-end"><?php echo number_format($summary['liquid_liters'] ?? 0, 2); ?></td>
                                    <td class="text-end"><?php echo number_format($summary['cng_liters'] ?? 0, 2); ?></td>
                                    <td class="text-end"><?php echo number_format($summary['total_liters'] ?? 0, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($summary['liquid_sales'] ?? 0, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($summary['cng_sales'] ?? 0, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($summary['total_sales'] ?? 0, 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Sales Details Table - ALL SALES -->
            <div class="card">
                <div class="card-header bg-dark text-white">
                    <h5><i class="fas fa-list"></i> All Sales Details (Liquid + CNG)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="salesTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Invoice No</th>
                                    <th>Type</th>
                                    <th>Product</th>
                                    <th class="text-end">Quantity</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end">Total</th>
                                    <th>Sale Type</th>
                                    <th>Operator</th>
                                    <th>Customer</th>
                                    <th class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($all_sales as $sale): 
                                    $is_cng = isset($sale['sale_type_label']) && $sale['sale_type_label'] == 'CNG';
                                    $badge_class = $is_cng ? 'badge-cng' : 'badge-liquid';
                                    $unit_label = $is_cng ? 'm³' : 'L';
                                ?>
                                <tr>
                                    <td><?php echo date('d-m-Y', strtotime($sale['sale_date'])); ?></td>
                                    <td><?php echo date('H:i:s', strtotime($sale['sale_date'])); ?></td>
                                    <td><?php echo $sale['invoice_no']; ?></td>
                                    <td><span class="badge <?php echo $badge_class; ?>"><?php echo $is_cng ? 'CNG' : 'Liquid'; ?></span></td>
                                    <td><?php echo $sale['product_name']; ?></td>
                                    <td class="text-end"><?php echo number_format($sale['quantity_liters'], 2); ?> <?php echo $unit_label; ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['unit_price'], 2); ?></td>
                                    <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $sale['sale_type'] == 'cash' ? 'success' : 'warning'; ?>">
                                            <?php echo ucfirst($sale['sale_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $sale['operator_name']; ?></td>
                                    <td><?php echo $sale['customer_name'] ?: '-'; ?></td>
                                    <td class="no-print">
                                        <a href="<?php echo $is_cng ? 'print_cng_invoice.php' : 'print_invoice.php'; ?>?invoice=<?php echo urlencode($sale['invoice_no']); ?>&from_report=1" 
                                        class="btn btn-sm btn-warning" 
                                        target="_blank"
                                        title="Reprint Invoice">
                                            <i class="fas fa-print"></i> Reprint
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($all_sales)): ?>
                                <tr>
                                    <td colspan="12" class="text-center text-muted">No sales found for the selected period</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="5" class="text-end">TOTAL:</td>
                                    <td class="text-end"><?php echo number_format($summary['total_liters'] ?? 0, 2); ?> L/m³</td>
                                    <td></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($summary['total_sales'] ?? 0, 2); ?></td>
                                    <td colspan="4"></td>
                                </tr>
                            </tfoot>
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
            $('#salesTable').DataTable({
                order: [[0, 'desc'], [1, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
            
            $('#dailyTable').DataTable({
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
<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-t');

// =============================================
// GET ALL SALES DATA DIRECTLY FROM TABLES
// =============================================

// 1. Liquid Fuel Sales (from sales table)
$stmt = $pdo->prepare("
    SELECT 
        DATE(sale_date) as sale_date,
        'Liquid Fuel' as product_type,
        COALESCE(SUM(CASE WHEN sale_type = 'cash' THEN total_amount ELSE 0 END), 0) as cash_sales,
        COALESCE(SUM(CASE WHEN sale_type = 'credit' THEN total_amount ELSE 0 END), 0) as credit_sales,
        COALESCE(SUM(total_amount), 0) as total_sales
    FROM sales 
    WHERE DATE(sale_date) BETWEEN ? AND ?
");
$stmt->execute([$from_date, $to_date]);
$liquid_sales = $stmt->fetch();

// 2. CNG Sales (from gas_sales table)
$stmt = $pdo->prepare("
    SELECT 
        DATE(sale_date) as sale_date,
        'CNG' as product_type,
        COALESCE(SUM(CASE WHEN sale_type = 'cash' THEN total_amount ELSE 0 END), 0) as cash_sales,
        COALESCE(SUM(CASE WHEN sale_type = 'credit' THEN total_amount ELSE 0 END), 0) as credit_sales,
        COALESCE(SUM(total_amount), 0) as total_sales
    FROM gas_sales 
    WHERE DATE(sale_date) BETWEEN ? AND ?
    AND status = 'completed'
");
$stmt->execute([$from_date, $to_date]);
$cng_sales = $stmt->fetch();

// 3. Item Sales (from item_sales table)
$stmt = $pdo->prepare("
    SELECT 
        DATE(sale_date) as sale_date,
        'Item Sales' as product_type,
        COALESCE(SUM(CASE WHEN sale_type = 'cash' THEN total_amount ELSE 0 END), 0) as cash_sales,
        COALESCE(SUM(CASE WHEN sale_type = 'credit' THEN total_amount ELSE 0 END), 0) as credit_sales,
        COALESCE(SUM(total_amount), 0) as total_sales
    FROM item_sales 
    WHERE DATE(sale_date) BETWEEN ? AND ?
");
$stmt->execute([$from_date, $to_date]);
$item_sales = $stmt->fetch();

// Calculate totals from all sources
$total_cash_sales = ($liquid_sales['cash_sales'] ?? 0) + ($cng_sales['cash_sales'] ?? 0) + ($item_sales['cash_sales'] ?? 0);
$total_credit_sales = ($liquid_sales['credit_sales'] ?? 0) + ($cng_sales['credit_sales'] ?? 0) + ($item_sales['credit_sales'] ?? 0);
$total_liquid_sales = $liquid_sales['total_sales'] ?? 0;
$total_cng_sales = $cng_sales['total_sales'] ?? 0;
$total_item_sales = $item_sales['total_sales'] ?? 0;
$total_all_sales = $total_cash_sales + $total_credit_sales;

// Get shift closing data (for shift details)
$stmt = $pdo->prepare("
    SELECT sc.*, sh.shift_name, u1.full_name as opened_by, u2.full_name as closed_by
    FROM shift_closing sc
    JOIN shift_schedule sh ON sc.shift_id = sh.id
    LEFT JOIN users u1 ON sc.opened_by = u1.id
    LEFT JOIN users u2 ON sc.closed_by = u2.id
    WHERE sc.shift_date BETWEEN ? AND ?
    ORDER BY sc.shift_date DESC, sc.opening_time DESC
");
$stmt->execute([$from_date, $to_date]);
$shifts = $stmt->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s;
        }
        .stats-card:hover { transform: translateY(-5px); }
        .stats-card i { font-size: 40px; opacity: 0.5; float: right; }
        
        /* ============================================= */
        /* PRINT STYLES - PLAIN PAPER, LANDSCAPE, 12px, 0.5in MARGIN, NO INNER BORDERS */
        /* ============================================= */
        @media print {
            .sidebar, .no-print, .btn, .dataTables_length, .dataTables_filter, 
            .dataTables_paginate, .dataTables_info, form, .card-header .btn,
            .stats-card {
                display: none !important;
            }
            
            body {
                margin: 0.5in !important;
                padding: 0 !important;
            }
            
            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            
            .container-fluid {
                padding: 0 !important;
                max-width: 100% !important;
            }
            
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 15px;
                border-bottom: 2px solid #000;
                padding-bottom: 10px;
            }
            
            .print-header h2 {
                font-size: 18px;
                font-weight: bold;
                margin-bottom: 2px;
                color: #000 !important;
            }
            
            .print-header h4 {
                font-size: 14px;
                margin-bottom: 2px;
                color: #000 !important;
            }
            
            .print-header p {
                font-size: 11px;
                margin-bottom: 2px;
                color: #000 !important;
            }
            
            .card {
                border: none !important;
                margin-bottom: 8px !important;
                border-radius: 0 !important;
                box-shadow: none !important;
            }
            
            .card-header {
                border: none !important;
                padding: 5px 8px !important;
                font-weight: bold;
                background: #fff !important;
                color: #000 !important;
                border-bottom: 2px solid #000 !important;
            }
            
            .card-header h5 {
                font-size: 14px !important;
                margin: 0 !important;
                color: #000 !important;
            }
            
            .card-body {
                padding: 5px 0 !important;
            }
            
            .table {
                border-collapse: collapse !important;
                width: 100% !important;
                font-size: 12px !important;
                margin: 0 !important;
                border: 2px solid #000 !important;
            }
            
            .table th, .table td {
                border: none !important;
                padding: 4px 6px !important;
                background: #fff !important;
                color: #000 !important;
                font-size: 12px !important;
            }
            
            .table th {
                background: #f8f9fa !important;
                font-weight: bold !important;
                border-bottom: 1px solid #000 !important;
            }
            
            .table thead th {
                background: #f8f9fa !important;
                border-bottom: 1px solid #000 !important;
                font-size: 12px !important;
            }
            
            .table tfoot th, .table tfoot td {
                background: #f8f9fa !important;
                border-top: 1px solid #000 !important;
                font-weight: bold !important;
                font-size: 12px !important;
            }
            
            .table-responsive {
                overflow: visible !important;
            }
            
            .bg-primary, .bg-success, .bg-info, .bg-warning, .bg-danger,
            .bg-secondary, .bg-light, .bg-white, .table-dark {
                background: #fff !important;
                color: #000 !important;
                border-color: #000 !important;
            }
            
            .text-white, .text-white-50 { color: #000 !important; }
            .text-success, .text-danger, .text-warning, .text-info, .text-primary {
                color: #000 !important;
            }
            
            .badge {
                border: none !important;
                background: #fff !important;
                color: #000 !important;
                padding: 1px 6px !important;
                font-size: 11px !important;
            }
            
            .alert {
                border: none !important;
                background: #fff !important;
                color: #000 !important;
            }
            
            .summary-section {
                background: #fff !important;
                border: 1px solid #000 !important;
            }
            
            .summary-section .value {
                color: #000 !important;
            }
            
            .value-cash, .value-credit, .value-cng, .value-liquid, .value-total {
                color: #000 !important;
            }
            
            @page {
                size: landscape;
                margin: 0.5in !important;
            }
            
            ::-webkit-scrollbar { display: none; }
            
            .col-md-3, .col-md-4, .col-md-12 {
                flex: 0 0 100% !important;
                max-width: 100% !important;
                width: 100% !important;
            }
            
            .row {
                margin: 0 !important;
            }
        }
        .print-header { display: none; }
        .badge-open { background: #28a745; color: white; }
        .badge-closed { background: #6c757d; color: white; }
        .badge-verified { background: #17a2b8; color: white; }
        .summary-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .summary-section h6 {
            color: #6c757d;
            font-weight: 600;
        }
        .summary-section .value {
            font-size: 20px;
            font-weight: bold;
        }
        .value-cash { color: #28a745; }
        .value-credit { color: #ffc107; }
        .value-cng { color: #17a2b8; }
        .value-liquid { color: #007bff; }
        .value-total { color: #dc3545; }
        .value-item { color: #6f42c1; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="print-header">
                <h2><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h2>
                <h4>Shift Report</h4>
                <p>Period: <?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-clock"></i> Shift Report</h2>
                <div class="no-print">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="shift_closing.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- Filter -->
            <div class="card mb-4 no-print">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-calendar-alt"></i> Select Period</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label>From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>">
                        </div>
                        <div class="col-md-4">
                            <label>To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>">
                        </div>
                        <div class="col-md-4">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-info w-100">
                                <i class="fas fa-search"></i> Generate Report
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-clock"></i>
                        <h3><?php echo count($shifts); ?></h3>
                        <p>Total Shifts</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_cash_sales, 2); ?></h3>
                        <p>Total Cash Sales</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-file-invoice"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_credit_sales, 2); ?></h3>
                        <p>Total Credit Sales</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-gas-pump"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_all_sales, 2); ?></h3>
                        <p>Total All Sales</p>
                    </div>
                </div>
            </div>
            
            <!-- Sales Breakdown Summary -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-dark text-white">
                            <h5><i class="fas fa-chart-pie"></i> Sales Breakdown Summary (All 3 POS Types)</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="summary-section">
                                        <h6><i class="fas fa-money-bill-wave"></i> Cash Sales</h6>
                                        <div class="value value-cash"><?php echo $currency; ?> <?php echo number_format($total_cash_sales, 2); ?></div>
                                        <small><?php echo count($shifts) > 0 ? number_format($total_cash_sales / count($shifts), 2) : 0; ?> avg per shift</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-section">
                                        <h6><i class="fas fa-file-invoice"></i> Credit Sales</h6>
                                        <div class="value value-credit"><?php echo $currency; ?> <?php echo number_format($total_credit_sales, 2); ?></div>
                                        <small><?php echo count($shifts) > 0 ? number_format($total_credit_sales / count($shifts), 2) : 0; ?> avg per shift</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-section">
                                        <h6><i class="fas fa-gas-pump"></i> CNG Sales</h6>
                                        <div class="value value-cng"><?php echo $currency; ?> <?php echo number_format($total_cng_sales, 2); ?></div>
                                        <small><?php echo count($shifts) > 0 ? number_format($total_cng_sales / count($shifts), 2) : 0; ?> avg per shift</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-section">
                                        <h6><i class="fas fa-oil-can"></i> Liquid Sales</h6>
                                        <div class="value value-liquid"><?php echo $currency; ?> <?php echo number_format($total_liquid_sales, 2); ?></div>
                                        <small><?php echo count($shifts) > 0 ? number_format($total_liquid_sales / count($shifts), 2) : 0; ?> avg per shift</small>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-6">
                                    <div class="summary-section" style="border: 2px solid #dc3545;">
                                        <h6><i class="fas fa-chart-line"></i> GRAND TOTAL (All Sales)</h6>
                                        <div class="value value-total" style="font-size: 28px;"><?php echo $currency; ?> <?php echo number_format($total_all_sales, 2); ?></div>
                                        <small>Total Cash + Credit Sales from all 3 POS types</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="summary-section" style="border: 2px solid #6f42c1;">
                                        <h6><i class="fas fa-box"></i> Sales by Type</h6>
                                        <div class="row">
                                            <div class="col-6">Liquid Fuel: <strong><?php echo $currency; ?> <?php echo number_format($total_liquid_sales, 2); ?></strong></div>
                                            <div class="col-6">CNG: <strong><?php echo $currency; ?> <?php echo number_format($total_cng_sales, 2); ?></strong></div>
                                            <div class="col-6">Item Sales: <strong class="value-item"><?php echo $currency; ?> <?php echo number_format($total_item_sales, 2); ?></strong></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Shift Table -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-list"></i> Shift Details</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="shiftTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Date</th>
                                    <th>Shift</th>
                                    <th>Opened By</th>
                                    <th>Opened At</th>
                                    <th>Closed By</th>
                                    <th class="text-end">Cash Sales</th>
                                    <th class="text-end">Credit Sales</th>
                                    <th class="text-end">CNG Sales</th>
                                    <th class="text-end">Liquid Sales</th>
                                    <th class="text-end">Total Sales</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($shifts as $shift): ?>
                                <tr>
                                    <td><?php echo date('d-m-Y', strtotime($shift['shift_date'])); ?></td>
                                    <td><strong><?php echo $shift['shift_name']; ?></strong></td>
                                    <td><?php echo $shift['opened_by']; ?></td>
                                    <td><?php echo date('h:i A', strtotime($shift['opening_time'])); ?></td>
                                    <td><?php echo $shift['closed_by'] ?: '-'; ?></td>
                                    <td class="text-end text-success"><?php echo $currency; ?> <?php echo number_format($shift['total_cash_sales'], 2); ?></td>
                                    <td class="text-end text-warning"><?php echo $currency; ?> <?php echo number_format($shift['total_credit_sales'], 2); ?></td>
                                    <td class="text-end text-info"><?php echo $currency; ?> <?php echo number_format($shift['total_cng_sales'], 2); ?></td>
                                    <td class="text-end text-primary"><?php echo $currency; ?> <?php echo number_format($shift['total_liquid_sales'], 2); ?></td>
                                    <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($shift['total_all_sales'], 2); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $shift['status']; ?>">
                                            <?php echo ucfirst($shift['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="5" class="text-end">TOTAL:</td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_cash_sales, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_credit_sales, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_cng_sales, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_liquid_sales, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_all_sales, 2); ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Shift Summary -->
            <?php if(!empty($shifts)): ?>
            <div class="card mt-4">
                <div class="card-header bg-dark text-white">
                    <h5><i class="fas fa-chart-bar"></i> Shift Summary</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="alert alert-success">
                                <strong>Best Performing Shift</strong><br>
                                <?php 
                                $best_shift = $shifts[0];
                                foreach($shifts as $s) {
                                    if($s['total_all_sales'] > $best_shift['total_all_sales']) {
                                        $best_shift = $s;
                                    }
                                }
                                ?>
                                <span class="h4"><?php echo $best_shift['shift_name']; ?></span><br>
                                <small>Total Sales: <?php echo $currency; ?> <?php echo number_format($best_shift['total_all_sales'], 2); ?></small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="alert alert-info">
                                <strong>Average Sales per Shift</strong><br>
                                <span class="h4"><?php echo $currency; ?> <?php echo number_format(count($shifts) > 0 ? $total_all_sales / count($shifts) : 0, 2); ?></span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="alert alert-warning">
                                <strong>Total Shifts</strong><br>
                                <span class="h4"><?php echo count($shifts); ?></span><br>
                                <small>From <?php echo date('d M Y', strtotime($from_date)); ?> to <?php echo date('d M Y', strtotime($to_date)); ?></small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Product-wise breakdown per shift -->
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <h6><i class="fas fa-th-list"></i> Sales Composition (All 3 POS Types)</h6>
                            <table class="table table-sm table-bordered">
                                <thead>
                                    <tr>
                                        <th>Sales Type</th>
                                        <th class="text-end">Amount</th>
                                        <th class="text-end">Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Cash Sales</td>
                                        <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_cash_sales, 2); ?></td>
                                        <td class="text-end"><?php echo $total_all_sales > 0 ? number_format(($total_cash_sales / $total_all_sales) * 100, 1) : 0; ?>%</td>
                                    </tr>
                                    <tr>
                                        <td>Credit Sales</td>
                                        <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_credit_sales, 2); ?></td>
                                        <td class="text-end"><?php echo $total_all_sales > 0 ? number_format(($total_credit_sales / $total_all_sales) * 100, 1) : 0; ?>%</td>
                                    </tr>
                                    <tr>
                                        <td>CNG Sales</td>
                                        <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_cng_sales, 2); ?></td>
                                        <td class="text-end"><?php echo $total_all_sales > 0 ? number_format(($total_cng_sales / $total_all_sales) * 100, 1) : 0; ?>%</td>
                                    </tr>
                                    <tr>
                                        <td>Liquid Sales</td>
                                        <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_liquid_sales, 2); ?></td>
                                        <td class="text-end"><?php echo $total_all_sales > 0 ? number_format(($total_liquid_sales / $total_all_sales) * 100, 1) : 0; ?>%</td>
                                    </tr>
                                    <tr>
                                        <td>Item Sales</td>
                                        <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_item_sales, 2); ?></td>
                                        <td class="text-end"><?php echo $total_all_sales > 0 ? number_format(($total_item_sales / $total_all_sales) * 100, 1) : 0; ?>%</td>
                                    </tr>
                                    <tr class="table-primary fw-bold">
                                        <td>GRAND TOTAL</td>
                                        <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_all_sales, 2); ?></td>
                                        <td class="text-end">100%</td>
                                    </tr>
                                </tbody>
                            </table>
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
            $('#shiftTable').DataTable({
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
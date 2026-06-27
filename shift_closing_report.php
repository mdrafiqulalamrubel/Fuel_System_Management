<?php
// shift_closing_report.php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-t');
$shift_id = isset($_GET['shift_id']) ? intval($_GET['shift_id']) : 0;

// Get all shifts for dropdown
$all_shifts = $pdo->query("
    SELECT sc.*, sh.shift_name 
    FROM shift_closing sc 
    JOIN shift_schedule sh ON sc.shift_id = sh.id 
    WHERE sc.status = 'closed'
    ORDER BY sc.shift_date DESC, sc.opening_time DESC
")->fetchAll();

// Get shift closing data with details
$sql = "
    SELECT 
        sc.*, 
        sh.shift_name, 
        u1.full_name as opened_by, 
        u2.full_name as closed_by
    FROM shift_closing sc
    JOIN shift_schedule sh ON sc.shift_id = sh.id
    LEFT JOIN users u1 ON sc.opened_by = u1.id
    LEFT JOIN users u2 ON sc.closed_by = u2.id
    WHERE sc.shift_date BETWEEN ? AND ?
";

$params = [$from_date, $to_date];

if($shift_id > 0) {
    $sql .= " AND sc.id = ?";
    $params[] = $shift_id;
}

$sql .= " ORDER BY sc.shift_date DESC, sc.opening_time DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$shifts = $stmt->fetchAll();

// Get selected shift details
$selected_shift = null;
$meter_readings = [];
$shift_sales = [];

if($shift_id > 0) {
    // Get shift details
    $stmt = $pdo->prepare("
        SELECT sc.*, sh.shift_name, u1.full_name as opened_by, u2.full_name as closed_by
        FROM shift_closing sc
        JOIN shift_schedule sh ON sc.shift_id = sh.id
        LEFT JOIN users u1 ON sc.opened_by = u1.id
        LEFT JOIN users u2 ON sc.closed_by = u2.id
        WHERE sc.id = ?
    ");
    $stmt->execute([$shift_id]);
    $selected_shift = $stmt->fetch();
    
    // Get meter readings for this shift
    $stmt = $pdo->prepare("
        SELECT 
            mr.*,
            n.nozzle_name,
            p.product_name,
            n.is_pipeline,
            CASE WHEN n.is_pipeline = 1 THEN 'Pipeline' ELSE 'Tank' END as source_type
        FROM meter_readings mr
        JOIN nozzles n ON mr.nozzle_id = n.id
        JOIN fuel_products p ON n.product_id = p.id
        WHERE mr.shift_id = ?
        ORDER BY n.is_pipeline DESC, n.nozzle_name
    ");
    $stmt->execute([$shift_id]);
    $meter_readings = $stmt->fetchAll();
    
    // Get sales breakdown for this shift
    // Liquid Fuel Sales
    $stmt = $pdo->prepare("
        SELECT 
            p.product_name,
            COALESCE(SUM(CASE WHEN s.sale_type = 'cash' THEN s.total_amount ELSE 0 END), 0) as cash_sales,
            COALESCE(SUM(CASE WHEN s.sale_type = 'credit' THEN s.total_amount ELSE 0 END), 0) as credit_sales,
            COALESCE(SUM(s.total_amount), 0) as total_sales,
            COUNT(*) as transaction_count
        FROM sales s
        JOIN fuel_products p ON s.product_id = p.id
        WHERE s.shift_id = ? AND DATE(s.sale_date) = ?
        GROUP BY p.product_name
    ");
    $stmt->execute([$selected_shift['shift_id'], $selected_shift['shift_date']]);
    $liquid_sales = $stmt->fetchAll();
    
    // CNG Sales
    $stmt = $pdo->prepare("
        SELECT 
            'CNG' as product_name,
            COALESCE(SUM(CASE WHEN gs.sale_type = 'cash' THEN gs.total_amount ELSE 0 END), 0) as cash_sales,
            COALESCE(SUM(CASE WHEN gs.sale_type = 'credit' THEN gs.total_amount ELSE 0 END), 0) as credit_sales,
            COALESCE(SUM(gs.total_amount), 0) as total_sales,
            COUNT(*) as transaction_count
        FROM gas_sales gs
        WHERE gs.shift_id = ? AND DATE(gs.sale_date) = ? AND gs.status = 'completed'
    ");
    $stmt->execute([$selected_shift['shift_id'], $selected_shift['shift_date']]);
    $cng_sales = $stmt->fetch();
    
    $shift_sales = [
        'liquid' => $liquid_sales,
        'cng' => $cng_sales
    ];
}

// Calculate totals
$total_cash_sales = array_sum(array_column($shifts, 'total_cash_sales'));
$total_credit_sales = array_sum(array_column($shifts, 'total_credit_sales'));
$total_cng_sales = array_sum(array_column($shifts, 'total_cng_sales'));
$total_liquid_sales = array_sum(array_column($shifts, 'total_liquid_sales'));
$total_all_sales = array_sum(array_column($shifts, 'total_all_sales'));
$total_receipts = array_sum(array_column($shifts, 'net_cash'));

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
$company_name = $settings['company_name'] ?? 'FF Enterprise';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Closing Report - Full Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================= */
        /* GENERAL STYLES */
        /* ============================================= */
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
        .badge-open { background: #28a745; color: white; }
        .badge-closed { background: #6c757d; color: white; }
        .badge-pipeline { background: #fd7e14; color: white; }
        .badge-tank { background: #17a2b8; color: white; }
        .detail-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #007bff;
        }
        .section-title {
            background: #e9ecef;
            padding: 10px 15px;
            border-radius: 8px;
            margin: 15px 0 10px 0;
            font-weight: bold;
        }
        .section-title i { margin-right: 8px; }
        .value-cash { color: #28a745; }
        .value-credit { color: #ffc107; }
        .value-cng { color: #17a2b8; }
        .value-liquid { color: #007bff; }
        .value-total { color: #dc3545; }
        .meter-reading {
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        
        /* PLC Card Style */
        .plc-card {
            background: #d4edda;
            border-radius: 10px;
            padding: 15px;
            border-left: 4px solid #28a745;
        }
        .plc-value {
            font-size: 24px;
            font-weight: bold;
        }
        .plc-diff {
            font-size: 20px;
            font-weight: bold;
        }
        .plc-positive { color: #28a745; }
        .plc-negative { color: #dc3545; }
        .plc-zero { color: #6c757d; }
        
        /* ============================================= */
        /* PRINT STYLES - PLAIN PAPER, NO BACKGROUND */
        /* ============================================= */
        @media print {
            /* Hide sidebar and buttons */
            .sidebar, .no-print, .btn, .dataTables_length, .dataTables_filter, 
            .dataTables_paginate, form, .card-header .btn, .stats-card,
            .badge, .badge-pipeline, .badge-tank, .badge-open, .badge-closed {
                display: none !important;
            }
            
            /* Landscape for wide reports */
            @page {
                size: landscape;
                margin: 10mm 8mm;
            }
            
            /* Main content full width */
            .main-content {
                margin: 0 !important;
                padding: 5px !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            
            .container-fluid {
                padding: 0 !important;
                max-width: 100% !important;
            }
            
            /* Show print header */
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 15px;
                border-bottom: 2px solid #000;
                padding-bottom: 10px;
            }
            
            .print-header h2 {
                font-size: 20px;
                font-weight: bold;
                margin-bottom: 2px;
            }
            
            .print-header h4 {
                font-size: 16px;
                margin-bottom: 2px;
            }
            
            .print-header p {
                font-size: 12px;
                margin-bottom: 2px;
            }
            
            /* Card styles - plain border */
            .card {
                border: 1px solid #000 !important;
                margin-bottom: 10px !important;
                border-radius: 0 !important;
                box-shadow: none !important;
            }
            
            .card-header {
                background: #f8f9fa !important;
                color: #000 !important;
                border-bottom: 1px solid #000 !important;
                padding: 5px 10px !important;
                font-weight: bold;
            }
            
            .card-header h5, .card-header h6 {
                font-size: 12px !important;
                margin: 0 !important;
                color: #000 !important;
            }
            
            .card-body {
                padding: 8px 10px !important;
            }
            
            /* Table styles - plain black and white */
            .table {
                border-collapse: collapse !important;
                width: 100% !important;
                font-size: 9px !important;
            }
            
            .table th, .table td {
                border: 1px solid #000 !important;
                padding: 3px 5px !important;
                background: #fff !important;
                color: #000 !important;
            }
            
            .table th {
                background: #f8f9fa !important;
                font-weight: bold !important;
            }
            
            .table thead th {
                background: #f8f9fa !important;
                border-bottom: 2px solid #000 !important;
            }
            
            .table tfoot th, .table tfoot td {
                background: #f8f9fa !important;
                border-top: 2px solid #000 !important;
                font-weight: bold !important;
            }
            
            .table-striped tbody tr:nth-of-type(odd) {
                background: #fff !important;
            }
            
            .table-striped tbody tr:nth-of-type(even) {
                background: #f9f9f9 !important;
            }
            
            /* Remove all background colors and gradients */
            .bg-primary, .bg-success, .bg-info, .bg-warning, .bg-danger, .bg-dark,
            .bg-secondary, .bg-light, .bg-white {
                background: #fff !important;
                color: #000 !important;
            }
            
            .text-white, .text-white-50, .text-white-50 * {
                color: #000 !important;
            }
            
            .text-success { color: #000 !important; }
            .text-warning { color: #000 !important; }
            .text-info { color: #000 !important; }
            .text-danger { color: #000 !important; }
            .text-primary { color: #000 !important; }
            
            /* Remove alerts background */
            .alert {
                border: 1px solid #000 !important;
                background: #fff !important;
                color: #000 !important;
                padding: 5px 10px !important;
            }
            
            .alert-success, .alert-info, .alert-warning, .alert-danger, .alert-secondary {
                background: #fff !important;
                border: 1px solid #000 !important;
                color: #000 !important;
            }
            
            /* Remove section title background */
            .section-title {
                background: #f8f9fa !important;
                border: 1px solid #000 !important;
                color: #000 !important;
                padding: 5px 10px !important;
            }
            
            /* Detail cards - plain border */
            .detail-card {
                background: #fff !important;
                border: 1px solid #000 !important;
                border-left: 3px solid #000 !important;
            }
            
            /* Stats cards - plain */
            .stats-card {
                background: #fff !important;
                border: 1px solid #000 !important;
                color: #000 !important;
            }
            
            .stats-card i {
                opacity: 0.5 !important;
            }
            
            /* Remove badges colors */
            .badge {
                border: 1px solid #000 !important;
                background: #fff !important;
                color: #000 !important;
                padding: 1px 5px !important;
                font-size: 8px !important;
            }
            
            /* Row and column adjustments */
            .row {
                margin: 0 !important;
            }
            
            .col-md-3, .col-md-4, .col-md-6, .col-md-12, .col-md-2 {
                padding: 0 3px !important;
            }
            
            /* Hide scrollbars */
            ::-webkit-scrollbar {
                display: none;
            }
            
            /* Table responsive - allow horizontal scroll in print */
            .table-responsive {
                overflow: visible !important;
                -webkit-overflow-scrolling: touch !important;
            }
            
            /* Footer note */
            .footer-note {
                border-top: 1px solid #000 !important;
                margin-top: 10px !important;
                padding-top: 5px !important;
                font-size: 8px !important;
                text-align: center !important;
            }
            
            /* PLC Card in print */
            .plc-card {
                border: 1px solid #000 !important;
                background: #fff !important;
                border-left: 3px solid #000 !important;
            }
        }
        
        /* Print header hidden by default */
        .print-header {
            display: none;
        }
        
        /* Ensure tables don't overflow in print */
        .table-responsive {
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- ============================================= -->
            <!-- PRINT HEADER (visible only in print) -->
            <!-- ============================================= -->
            <div class="print-header">
                <h2><?php echo htmlspecialchars($company_name); ?></h2>
                <h4>Shift Closing Report - Full Details</h4>
                <p>Period: <?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                <p>Generated: <?php echo date('d/m/Y h:i:s A'); ?></p>
                <?php if($shift_id > 0 && $selected_shift): ?>
                <p><strong>Shift:</strong> <?php echo $selected_shift['shift_name']; ?> | <strong>Date:</strong> <?php echo date('d-m-Y', strtotime($selected_shift['shift_date'])); ?></p>
                <?php endif; ?>
            </div>
            
            <!-- ============================================= -->
            <!-- HEADER WITH BUTTONS (visible only on screen) -->
            <!-- ============================================= -->
            <div class="d-flex justify-content-between align-items-center mb-4 no-print">
                <h2><i class="fas fa-clipboard-list"></i> Shift Closing Report</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report (Landscape)
                    </button>
                    <a href="shift_closing.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- ============================================= -->
            <!-- FILTER (visible only on screen) -->
            <!-- ============================================= -->
            <div class="card mb-4 no-print">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-filter"></i> Filter Report</h5>
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
                            <label>Select Shift</label>
                            <select name="shift_id" class="form-control">
                                <option value="0">All Shifts</option>
                                <?php foreach($all_shifts as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" <?php echo $shift_id == $s['id'] ? 'selected' : ''; ?>>
                                        <?php echo date('d-m-Y', strtotime($s['shift_date'])); ?> - <?php echo $s['shift_name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-info w-100">
                                <i class="fas fa-search"></i> Generate Report
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- ============================================= -->
            <!-- STATISTICS CARDS -->
            <!-- ============================================= -->
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
            
            <?php if($selected_shift && $shift_id > 0): ?>
            <!-- ============================================= -->
            <!-- DETAILED SHIFT VIEW -->
            <!-- ============================================= -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-file-invoice"></i> Shift Details - <?php echo $selected_shift['shift_name']; ?></h5>
                    <small><?php echo date('d-m-Y', strtotime($selected_shift['shift_date'])); ?></small>
                </div>
                <div class="card-body">
                    
                    <!-- Shift Header Info -->
                    <div class="row mb-3">
                        <div class="col-md-2">
                            <strong>Date:</strong><br>
                            <?php echo date('d-m-Y', strtotime($selected_shift['shift_date'])); ?>
                        </div>
                        <div class="col-md-2">
                            <strong>Opened:</strong><br>
                            <?php echo date('h:i A', strtotime($selected_shift['opening_time'])); ?>
                        </div>
                        <div class="col-md-2">
                            <strong>Closed:</strong><br>
                            <?php echo $selected_shift['closing_time'] ? date('h:i A', strtotime($selected_shift['closing_time'])) : 'N/A'; ?>
                        </div>
                        <div class="col-md-2">
                            <strong>Operator:</strong><br>
                            <?php echo $selected_shift['opened_by']; ?>
                        </div>
                        <div class="col-md-2">
                            <strong>Closed By:</strong><br>
                            <?php echo $selected_shift['closed_by'] ?: 'N/A'; ?>
                        </div>
                        <div class="col-md-2">
                            <strong>Cash Drawer:</strong><br>
                            <?php echo $currency; ?> <?php echo number_format($selected_shift['net_cash'], 2); ?>
                        </div>
                        <?php if($selected_shift['closing_notes']): ?>
                        <div class="col-md-12 mt-2">
                            <strong><i class="fas fa-comment"></i> Remarks:</strong><br>
                            <?php echo nl2br(htmlspecialchars($selected_shift['closing_notes'])); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <hr>
                    
                    <!-- ============================================= -->
                    <!-- PLC INFORMATION - Shows Opening, Closing & Difference -->
                    <!-- ============================================= -->
                    <?php 
                    $opening_plc = $selected_shift['opening_plc_count'] ?? 0;
                    $closing_plc = $selected_shift['closing_plc_count'] ?? 0;
                    $diff = $closing_plc - $opening_plc;
                    ?>
                    <div class="plc-card mb-3">
                        <div class="row">
                            <div class="col-md-3">
                                <strong><i class="fas fa-play-circle"></i> Opening PLC:</strong><br>
                                <span class="plc-value"><?php echo number_format($opening_plc, 2); ?></span>
                            </div>
                            <div class="col-md-3">
                                <strong><i class="fas fa-stop-circle"></i> Closing PLC:</strong><br>
                                <span class="plc-value"><?php echo number_format($closing_plc, 2); ?></span>
                            </div>
                            <div class="col-md-3">
                                <strong><i class="fas fa-arrow-right"></i> PLC Difference:</strong><br>
                                <span class="plc-diff <?php echo $diff > 0 ? 'plc-positive' : ($diff < 0 ? 'plc-negative' : 'plc-zero'); ?>">
                                    <?php echo number_format($diff, 2); ?>
                                </span>
                            </div>
                            <div class="col-md-3 text-end">
                                <span class="badge bg-success"><i class="fas fa-microchip"></i> PLC Count</span>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <!-- ============================================= -->
                    <!-- NOZZLE METER READINGS -->
                    <!-- ============================================= -->
                    <h6 class="section-title"><i class="fas fa-tachometer-alt"></i> Nozzle Meter Readings</h6>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>Nozzle</th>
                                    <th>Product</th>
                                    <th>Type</th>
                                    <th class="text-end">Opening Meter</th>
                                    <th class="text-end">Closing Meter</th>
                                    <th class="text-end">Difference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($meter_readings)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No meter readings found for this shift</td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    $total_meter_diff = 0;
                                    foreach($meter_readings as $reading): 
                                        $meter_diff = $reading['closing_meter'] - $reading['opening_meter'];
                                        $total_meter_diff += $meter_diff;
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($reading['nozzle_name']); ?></strong></td>
                                        <td><?php echo $reading['product_name']; ?></td>
                                        <td><?php echo $reading['is_pipeline'] ? 'Pipeline' : 'Tank'; ?></td>
                                        <td class="text-end meter-reading"><?php echo number_format($reading['opening_meter'], 2); ?></td>
                                        <td class="text-end meter-reading"><?php echo number_format($reading['closing_meter'], 2); ?></td>
                                        <td class="text-end fw-bold"><?php echo number_format($meter_diff, 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <?php if(!empty($meter_readings)): ?>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="3" class="text-end">TOTAL:</td>
                                    <td colspan="2"></td>
                                    <td class="text-end"><?php echo number_format($total_meter_diff, 2); ?></td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                    
                    <hr>
                    
                    <!-- ============================================= -->
                    <!-- SALES BREAKDOWN -->
                    <!-- ============================================= -->
                    <h6 class="section-title"><i class="fas fa-chart-pie"></i> Sales Breakdown</h6>
                    
                    <div class="row">
                        <!-- Liquid Fuel Sales -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-oil-can"></i> Liquid Fuel Sales</h6>
                                </div>
                                <div class="card-body p-2">
                                    <table class="table table-sm table-bordered mb-0 table-striped">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th class="text-end">Cash</th>
                                                <th class="text-end">Credit</th>
                                                <th class="text-end">Total</th>
                                                <th class="text-end">Qty</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $total_liquid_cash = 0;
                                            $total_liquid_credit = 0;
                                            $total_liquid_all = 0;
                                            foreach($shift_sales['liquid'] as $sale): 
                                                $total_liquid_cash += $sale['cash_sales'];
                                                $total_liquid_credit += $sale['credit_sales'];
                                                $total_liquid_all += $sale['total_sales'];
                                            ?>
                                            <tr>
                                                <td><?php echo $sale['product_name']; ?></td>
                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['cash_sales'], 2); ?></td>
                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['credit_sales'], 2); ?></td>
                                                <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($sale['total_sales'], 2); ?></td>
                                                <td class="text-end"><?php echo $sale['transaction_count']; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if(empty($shift_sales['liquid'])): ?>
                                            <tr>
                                                <td colspan="5" class="text-center">No liquid fuel sales</td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="fw-bold">
                                                <td>TOTAL LIQUID</td>
                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_liquid_cash, 2); ?></td>
                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_liquid_credit, 2); ?></td>
                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_liquid_all, 2); ?></td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- CNG Sales -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-gas-pump"></i> CNG Sales</h6>
                                </div>
                                <div class="card-body p-2">
                                    <table class="table table-sm table-bordered mb-0 table-striped">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th class="text-end">Cash</th>
                                                <th class="text-end">Credit</th>
                                                <th class="text-end">Total</th>
                                                <th class="text-end">Qty</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if($shift_sales['cng'] && $shift_sales['cng']['total_sales'] > 0): ?>
                                            <tr>
                                                <td>CNG</td>
                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($shift_sales['cng']['cash_sales'], 2); ?></td>
                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($shift_sales['cng']['credit_sales'], 2); ?></td>
                                                <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($shift_sales['cng']['total_sales'], 2); ?></td>
                                                <td class="text-end"><?php echo $shift_sales['cng']['transaction_count']; ?></td>
                                            </tr>
                                            <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center">No CNG sales</td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="fw-bold">
                                                <td>TOTAL CNG</td>
                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($shift_sales['cng']['cash_sales'] ?? 0, 2); ?></td>
                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($shift_sales['cng']['credit_sales'] ?? 0, 2); ?></td>
                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($shift_sales['cng']['total_sales'] ?? 0, 2); ?></td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- GRAND TOTAL -->
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-chart-line"></i> GRAND TOTAL</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-md-3">
                                            <strong>Cash Sales</strong><br>
                                            <span class="h4"><?php echo $currency; ?> <?php echo number_format($selected_shift['total_cash_sales'], 2); ?></span>
                                        </div>
                                        <div class="col-md-3">
                                            <strong>Credit Sales</strong><br>
                                            <span class="h4"><?php echo $currency; ?> <?php echo number_format($selected_shift['total_credit_sales'], 2); ?></span>
                                        </div>
                                        <div class="col-md-3">
                                            <strong>CNG Sales</strong><br>
                                            <span class="h4"><?php echo $currency; ?> <?php echo number_format($selected_shift['total_cng_sales'], 2); ?></span>
                                        </div>
                                        <div class="col-md-3">
                                            <strong>Liquid Sales</strong><br>
                                            <span class="h4"><?php echo $currency; ?> <?php echo number_format($selected_shift['total_liquid_sales'], 2); ?></span>
                                        </div>
                                    </div>
                                    <div class="text-center mt-3">
                                        <div class="alert">
                                            <h4><strong>TOTAL ALL SALES: <?php echo $currency; ?> <?php echo number_format($selected_shift['total_all_sales'], 2); ?></strong></h4>
                                            <small>Cash in Drawer: <?php echo $currency; ?> <?php echo number_format($selected_shift['net_cash'], 2); ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
            <?php endif; ?>
            
            <!-- ============================================= -->
            <!-- SHIFT LIST - Shows Opening PLC, Closing PLC & Difference -->
            <!-- ============================================= -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5><i class="fas fa-list"></i> All Shifts</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="shiftTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Shift</th>
                                    <th>Operator</th>
                                    <th>Opened</th>
                                    <th>Closed</th>
                                    <th class="text-end">Cash Sales</th>
                                    <th class="text-end">Credit Sales</th>
                                    <th class="text-end">CNG</th>
                                    <th class="text-end">Liquid</th>
                                    <th class="text-end">Total</th>
                                    <th class="text-end">Cash Drawer</th>
                                    <th class="text-end">Opening PLC</th>
                                    <th class="text-end">Closing PLC</th>
                                    <th class="text-end">PLC Diff</th>
                                    <th>Status</th>
                                    <th class="no-print">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($shifts as $shift): 
                                    $opening_plc = $shift['opening_plc_count'] ?? 0;
                                    $closing_plc = $shift['closing_plc_count'] ?? 0;
                                    $plc_diff = $closing_plc - $opening_plc;
                                ?>
                                <tr>
                                    <td><?php echo date('d-m-Y', strtotime($shift['shift_date'])); ?></td>
                                    <td><strong><?php echo $shift['shift_name']; ?></strong></td>
                                    <td><?php echo $shift['opened_by']; ?></td>
                                    <td><?php echo date('h:i A', strtotime($shift['opening_time'])); ?></td>
                                    <td><?php echo $shift['closing_time'] ? date('h:i A', strtotime($shift['closing_time'])) : '-'; ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($shift['total_cash_sales'], 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($shift['total_credit_sales'], 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($shift['total_cng_sales'], 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($shift['total_liquid_sales'], 2); ?></td>
                                    <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($shift['total_all_sales'], 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($shift['net_cash'], 2); ?></td>
                                    <!-- ============================================= -->
                                    <!-- PLC Columns - Opening, Closing & Difference -->
                                    <!-- ============================================= -->
                                    <td class="text-end text-primary"><?php echo number_format($opening_plc, 2); ?></td>
                                    <td class="text-end text-info"><?php echo number_format($closing_plc, 2); ?></td>
                                    <td class="text-end <?php echo $plc_diff > 0 ? 'text-success' : ($plc_diff < 0 ? 'text-danger' : 'text-muted'); ?>">
                                        <?php echo number_format($plc_diff, 2); ?>
                                    </td>
                                    <!-- ============================================= -->
                                    <td><?php echo ucfirst($shift['status']); ?></td>
                                    <td class="no-print">
                                        <a href="?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&shift_id=<?php echo $shift['id']; ?>" class="btn btn-sm btn-info">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="5" class="text-end">TOTAL:</td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_cash_sales, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_credit_sales, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_cng_sales, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_liquid_sales, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_all_sales, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_receipts, 2); ?></td>
                                    <!-- ============================================= -->
                                    <!-- PLC Totals -->
                                    <td class="text-end"><?php 
                                        $total_opening_plc = 0;
                                        foreach($shifts as $shift) {
                                            $total_opening_plc += ($shift['opening_plc_count'] ?? 0);
                                        }
                                        echo number_format($total_opening_plc, 2);
                                    ?></td>
                                    <td class="text-end"><?php 
                                        $total_closing_plc = 0;
                                        foreach($shifts as $shift) {
                                            $total_closing_plc += ($shift['closing_plc_count'] ?? 0);
                                        }
                                        echo number_format($total_closing_plc, 2);
                                    ?></td>
                                    <td class="text-end"><?php 
                                        $total_plc_diff = 0;
                                        foreach($shifts as $shift) {
                                            $total_plc_diff += ($shift['closing_plc_count'] ?? 0) - ($shift['opening_plc_count'] ?? 0);
                                        }
                                        echo number_format($total_plc_diff, 2);
                                    ?></td>
                                    <!-- ============================================= -->
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Footer Note -->
            <div class="footer-note no-print" style="margin-top:15px; padding-top:10px; border-top:1px solid #ddd; text-align:center; color:#6c757d; font-size:12px;">
                <i class="fas fa-info-circle"></i>
                <strong>Report Summary:</strong> 
                <?php echo count($shifts); ?> shifts from <?php echo date('d M Y', strtotime($from_date)); ?> to <?php echo date('d M Y', strtotime($to_date)); ?>.
                Total Sales: <?php echo $currency; ?> <?php echo number_format($total_all_sales, 2); ?>
                | Total PLC Difference: <?php echo number_format($total_plc_diff ?? 0, 2); ?>
                <br><small>Click <strong>Print Report</strong> button for landscape print with plain paper format.</small>
            </div>
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
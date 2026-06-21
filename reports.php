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

// Get CNG sales today
$stmt = $pdo->prepare("SELECT SUM(total_amount) as today_cng FROM gas_sales WHERE DATE(sale_date) = ? AND status = 'completed'");
$stmt->execute([$today]);
$today_cng = $stmt->fetch()['today_cng'] ?? 0;

// Get all products for filter
$products = $pdo->query("SELECT id, product_name FROM fuel_products WHERE is_active = 1 ORDER BY product_name")->fetchAll();

// Get all nozzles for filter
$nozzles = $pdo->query("SELECT id, nozzle_name FROM nozzles WHERE is_active = 1 ORDER BY nozzle_name")->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
$company_name = $settings['company_name'] ?? 'FF Enterprise';

// Calculate total stock
$total_stock = $pdo->query("SELECT SUM(current_stock_liters) as total FROM tanks")->fetch()['total'] ?? 0;
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
        .stats-card h3 { font-size: 28px; margin-bottom: 5px; }
        .stats-card p { margin: 0; opacity: 0.8; }
        .stats-card small { opacity: 0.7; font-size: 12px; }
        
        .nav-tabs {
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 20px;
            background: transparent;
        }
        .nav-tabs .nav-item { margin-bottom: -2px; }
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
        .nav-tabs .nav-link i { margin-right: 8px; }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            transition: margin-left 0.3s;
        }
        
        .clickable-row { cursor: pointer; transition: background-color 0.2s; }
        .clickable-row:hover { background-color: #e8f0fe !important; }
        
        .badge-cng { background: #17a2b8; color: white; }
        .badge-liquid { background: #28a745; color: white; }
        .badge-lpg { background: #ffc107; color: #856404; }
        
        .summary-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 4px solid #007bff;
        }
        .summary-card.total-sales { border-left-color: #28a745; }
        .summary-card.cash-sales { border-left-color: #17a2b8; }
        .summary-card.credit-sales { border-left-color: #ffc107; }
        .summary-card.cng-sales { border-left-color: #6f42c1; }
        .summary-card h6 { font-size: 12px; color: #666; margin-bottom: 2px; }
        .summary-card h4 { font-size: 18px; font-weight: bold; margin: 0; }
        .summary-card small { font-size: 10px; color: #888; }
        
        .invoice-link {
            color: #007bff;
            text-decoration: none;
            cursor: pointer;
        }
        .invoice-link:hover { text-decoration: underline; }
        
        .btn-reprint {
            padding: 2px 8px;
            font-size: 12px;
        }
        
        .text-purple { color: #6f42c1; }
        
        /* ============================================= */
        /* PLAIN PAPER PRINT STYLES */
        /* ============================================= */
        @media print {
            /* Hide sidebar and buttons */
            .sidebar, .no-print, .btn, .dataTables_length, .dataTables_filter, 
            .dataTables_paginate, form, .card-header .btn, .nav-tabs,
            .stats-card i, .clickable-row:hover {
                display: none !important;
            }
            
            /* Landscape for wide reports */
            @page {
                size: landscape;
                margin: 8mm 6mm;
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
                font-size: 18px;
                font-weight: bold;
                margin-bottom: 2px;
            }
            
            .print-header h4 {
                font-size: 14px;
                margin-bottom: 2px;
            }
            
            .print-header p {
                font-size: 11px;
                margin-bottom: 2px;
            }
            
            /* Card styles - plain border */
            .card {
                border: 1px solid #000 !important;
                margin-bottom: 10px !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                background: #fff !important;
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
                background: #fff !important;
            }
            
            /* Table styles - plain black and white */
            .table {
                border-collapse: collapse !important;
                width: 100% !important;
                font-size: 9px !important;
                color: #000 !important;
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
            
            .text-success, .text-warning, .text-info, .text-danger, .text-primary, .text-purple {
                color: #000 !important;
            }
            
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
            
            /* Stats cards - plain */
            .stats-card {
                background: #fff !important;
                border: 1px solid #000 !important;
                color: #000 !important;
                box-shadow: none !important;
            }
            
            .stats-card i {
                display: none !important;
            }
            
            /* Summary cards - plain */
            .summary-card {
                background: #fff !important;
                border: 1px solid #000 !important;
                border-left: 3px solid #000 !important;
            }
            
            .summary-card.total-sales { border-left-color: #000 !important; }
            .summary-card.cash-sales { border-left-color: #000 !important; }
            .summary-card.credit-sales { border-left-color: #000 !important; }
            .summary-card.cng-sales { border-left-color: #000 !important; }
            
            /* Badges - plain */
            .badge {
                border: 1px solid #000 !important;
                background: #fff !important;
                color: #000 !important;
                padding: 1px 5px !important;
                font-size: 8px !important;
            }
            .badge-cng, .badge-liquid, .badge-lpg {
                background: #fff !important;
                color: #000 !important;
                border: 1px solid #000 !important;
            }
            
            /* Remove hover effects */
            .clickable-row:hover {
                background-color: #fff !important;
            }
            
            /* Table responsive - allow horizontal scroll in print */
            .table-responsive {
                overflow: visible !important;
                -webkit-overflow-scrolling: touch !important;
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
            
            /* Footer note */
            .footer-note {
                border-top: 1px solid #000 !important;
                margin-top: 10px !important;
                padding-top: 5px !important;
                font-size: 8px !important;
                text-align: center !important;
            }
        }
        
        /* Print header hidden by default */
        .print-header {
            display: none;
        }
        
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
        }
        
        /* Plain tab specific styles */
        .plain-table th, .plain-table td {
            border: 1px solid #000 !important;
            padding: 5px 8px !important;
            font-size: 11px !important;
        }
        .plain-table thead th {
            background: #f8f9fa !important;
            border: 1px solid #000 !important;
            font-weight: bold !important;
        }
        .plain-table tbody td {
            border: 1px solid #000 !important;
        }
        .plain-table tfoot td {
            background: #f8f9fa !important;
            border: 1px solid #000 !important;
            font-weight: bold !important;
        }
        .plain-summary {
            border: 1px solid #000 !important;
            border-left: 3px solid #000 !important;
            padding: 12px 15px !important;
            background: #fff !important;
        }
        .plain-summary h6 {
            font-size: 12px !important;
            color: #555 !important;
            margin-bottom: 2px !important;
        }
        .plain-summary h4 {
            font-size: 18px !important;
            font-weight: bold !important;
            margin: 0 !important;
        }
        .plain-summary small {
            font-size: 10px !important;
            color: #666 !important;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- ============================================= -->
            <!-- PRINT HEADER -->
            <!-- ============================================= -->
            <div class="print-header">
                <h2><?php echo htmlspecialchars($company_name); ?></h2>
                <h4>Reports Dashboard</h4>
                <p>Generated on: <?php echo date('d/m/Y h:i:s A'); ?></p>
                <hr>
            </div>
            
            <!-- ============================================= -->
            <!-- HEADER -->
            <!-- ============================================= -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-chart-bar"></i> Reports Dashboard</h2>
                <div class="no-print">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report (Landscape)
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
            
            <!-- ============================================= -->
            <!-- STATISTICS CARDS -->
            <!-- ============================================= -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($today_sales + $today_cng, 2); ?></h3>
                        <p>Today's Total Sales</p>
                        <small>Liquid: <?php echo $currency; ?> <?php echo number_format($today_sales, 2); ?> | CNG: <?php echo $currency; ?> <?php echo number_format($today_cng, 2); ?></small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-oil-can"></i>
                        <h3><?php echo number_format($total_stock, 2); ?> L</h3>
                        <p>Total Stock</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-box"></i>
                        <h3><?php echo count($products); ?></h3>
                        <p>Active Products</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-oil-can"></i>
                        <h3><?php echo count($nozzles); ?></h3>
                        <p>Active Nozzles</p>
                    </div>
                </div>
            </div>
            
            <!-- ============================================= -->
            <!-- TABS -->
            <!-- ============================================= -->
            <ul class="nav nav-tabs">
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'sales' ? 'active' : ''; ?>" href="?tab=sales">
                        <i class="fas fa-chart-line"></i> Date Range Sales
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'daily' ? 'active' : ''; ?>" href="?tab=daily">
                        <i class="fas fa-calendar-day"></i> Daily Sales
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'nozzle' ? 'active' : ''; ?>" href="?tab=nozzle">
                        <i class="fas fa-oil-can"></i> Nozzle-wise
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'item' ? 'active' : ''; ?>" href="?tab=item">
                        <i class="fas fa-tag"></i> Item-wise
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'inventory' ? 'active' : ''; ?>" href="?tab=inventory">
                        <i class="fas fa-warehouse"></i> Inventory
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'financial' ? 'active' : ''; ?>" href="?tab=financial">
                        <i class="fas fa-chart-pie"></i> Financial
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'plain' ? 'active' : ''; ?>" href="?tab=plain">
                        <i class="fas fa-print"></i> Plain Print
                    </a>
                </li>
            </ul>
            
            <!-- ============================================= -->
            <!-- DATE RANGE SALES REPORT TAB -->
            <!-- ============================================= -->
            <?php if($active_tab == 'sales'): ?>
            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-calendar-alt"></i> Date Range Sales Report</h5>
                            <small class="d-block text-light">Select any date range to generate report. Click on any invoice to view details.</small>
                        </div>
                        <div class="card-body">
                            <!-- Date Range Filter -->
                            <form method="GET" class="row g-3 mb-4">
                                <input type="hidden" name="tab" value="sales">
                                <div class="col-md-2">
                                    <label>From Date</label>
                                    <input type="date" name="from_date" class="form-control" value="<?php echo isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01'); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label>To Date</label>
                                    <input type="date" name="to_date" class="form-control" value="<?php echo isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label>Product</label>
                                    <select name="product_id" class="form-control">
                                        <option value="">All Products</option>
                                        <?php foreach($products as $p): ?>
                                            <option value="<?php echo $p['id']; ?>" <?php echo (isset($_GET['product_id']) && $_GET['product_id'] == $p['id']) ? 'selected' : ''; ?>>
                                                <?php echo $p['product_name']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label>Nozzle</label>
                                    <select name="nozzle_id" class="form-control">
                                        <option value="">All Nozzles</option>
                                        <?php foreach($nozzles as $n): ?>
                                            <option value="<?php echo $n['id']; ?>" <?php echo (isset($_GET['nozzle_id']) && $_GET['nozzle_id'] == $n['id']) ? 'selected' : ''; ?>>
                                                <?php echo $n['nozzle_name']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label>Sale Type</label>
                                    <select name="sale_type" class="form-control">
                                        <option value="">All Types</option>
                                        <option value="cash" <?php echo (isset($_GET['sale_type']) && $_GET['sale_type'] == 'cash') ? 'selected' : ''; ?>>Cash</option>
                                        <option value="credit" <?php echo (isset($_GET['sale_type']) && $_GET['sale_type'] == 'credit') ? 'selected' : ''; ?>>Credit</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-search"></i> Generate
                                    </button>
                                </div>
                            </form>
                            
                            <?php
                            // Build query based on filters
                            $from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
                            $to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
                            $product_id = isset($_GET['product_id']) ? $_GET['product_id'] : '';
                            $nozzle_id = isset($_GET['nozzle_id']) ? $_GET['nozzle_id'] : '';
                            $sale_type = isset($_GET['sale_type']) ? $_GET['sale_type'] : '';
                            
                            // Get Liquid Sales
                            $sql_liquid = "SELECT s.*, p.product_name, n.nozzle_name, 'liquid' as sale_type_label, 'L' as unit_label 
                                           FROM sales s 
                                           JOIN fuel_products p ON s.product_id = p.id 
                                           JOIN nozzles n ON s.nozzle_id = n.id 
                                           WHERE DATE(s.sale_date) BETWEEN ? AND ?";
                            $params = [$from_date, $to_date];
                            
                            if($product_id) {
                                $sql_liquid .= " AND s.product_id = ?";
                                $params[] = $product_id;
                            }
                            if($nozzle_id) {
                                $sql_liquid .= " AND s.nozzle_id = ?";
                                $params[] = $nozzle_id;
                            }
                            if($sale_type) {
                                $sql_liquid .= " AND s.sale_type = ?";
                                $params[] = $sale_type;
                            }
                            
                            // Get CNG Sales
                            $sql_cng = "SELECT gs.*, p.product_name, n.nozzle_name, 'cng' as sale_type_label, 'm³' as unit_label 
                                        FROM gas_sales gs 
                                        JOIN nozzles n ON gs.nozzle_id = n.id 
                                        JOIN fuel_products p ON n.product_id = p.id 
                                        WHERE DATE(gs.sale_date) BETWEEN ? AND ? AND gs.status = 'completed'";
                            $params_cng = [$from_date, $to_date];
                            
                            if($product_id) {
                                $sql_cng .= " AND n.product_id = ?";
                                $params_cng[] = $product_id;
                            }
                            if($nozzle_id) {
                                $sql_cng .= " AND gs.nozzle_id = ?";
                                $params_cng[] = $nozzle_id;
                            }
                            if($sale_type) {
                                $sql_cng .= " AND gs.sale_type = ?";
                                $params_cng[] = $sale_type;
                            }
                            
                            $stmt = $pdo->prepare($sql_liquid);
                            $stmt->execute($params);
                            $liquid_sales = $stmt->fetchAll();
                            
                            $stmt = $pdo->prepare($sql_cng);
                            $stmt->execute($params_cng);
                            $cng_sales = $stmt->fetchAll();
                            
                            $all_sales_data = array_merge($liquid_sales, $cng_sales);
                            usort($all_sales_data, function($a, $b) {
                                return strtotime($a['sale_date']) - strtotime($b['sale_date']);
                            });
                            
                            // Calculate totals
                            $total_transactions = count($all_sales_data);
                            $total_amount = array_sum(array_column($all_sales_data, 'total_amount'));
                            $total_cash = 0;
                            $total_credit = 0;
                            $total_cng = 0;
                            
                            foreach($all_sales_data as $sale) {
                                if($sale['sale_type'] == 'cash') {
                                    $total_cash += $sale['total_amount'];
                                } else {
                                    $total_credit += $sale['total_amount'];
                                }
                                if($sale['sale_type_label'] == 'cng') {
                                    $total_cng += $sale['total_amount'];
                                }
                            }
                            $total_liquid = $total_amount - $total_cng;
                            ?>
                            
                            <!-- Summary Cards -->
                            <div class="row mb-3">
                                <div class="col-md-3">
                                    <div class="summary-card total-sales">
                                        <h6>Total Sales</h6>
                                        <h4><?php echo $currency; ?> <?php echo number_format($total_amount, 2); ?></h4>
                                        <small><?php echo $total_transactions; ?> Transactions</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-card cash-sales">
                                        <h6>Cash Sales</h6>
                                        <h4><?php echo $currency; ?> <?php echo number_format($total_cash, 2); ?></h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-card credit-sales">
                                        <h6>Credit Sales</h6>
                                        <h4><?php echo $currency; ?> <?php echo number_format($total_credit, 2); ?></h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-card cng-sales">
                                        <h6>CNG Sales</h6>
                                        <h4><?php echo $currency; ?> <?php echo number_format($total_cng, 2); ?></h4>
                                        <small>Liquid: <?php echo $currency; ?> <?php echo number_format($total_liquid, 2); ?></small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Sales Table -->
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="salesReportTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Date</th>
                                            <th>Invoice</th>
                                            <th>Type</th>
                                            <th>Product</th>
                                            <th>Nozzle</th>
                                            <th>Customer</th>
                                            <th class="text-end">Quantity</th>
                                            <th class="text-end">Unit Price</th>
                                            <th class="text-end">Total</th>
                                            <th>Sale Type</th>
                                            <th class="no-print">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($all_sales_data)): ?>
                                        <tr>
                                            <td colspan="11" class="text-center text-muted">No sales found for the selected period</td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach($all_sales_data as $sale): 
                                                $is_cng = ($sale['sale_type_label'] == 'cng');
                                                $badge_class = $is_cng ? 'badge-cng' : 'badge-liquid';
                                                $invoice_no = $sale['invoice_no'];
                                            ?>
                                            <tr class="clickable-row" data-invoice="<?php echo $invoice_no; ?>" data-type="<?php echo $is_cng ? 'cng' : 'liquid'; ?>">
                                                <td><?php echo date('d-m-Y h:i A', strtotime($sale['sale_date'])); ?></td>
                                                <td>
                                                    <a href="javascript:void(0)" class="invoice-link" onclick="showInvoiceDetails('<?php echo $invoice_no; ?>', '<?php echo $is_cng ? 'cng' : 'liquid'; ?>')">
                                                        <?php echo $invoice_no; ?>
                                                    </a>
                                                </td>
                                                <td><span class="badge <?php echo $badge_class; ?>"><?php echo $is_cng ? 'CNG' : 'Liquid'; ?></span></td>
                                                <td><?php echo $sale['product_name']; ?></td>
                                                <td><?php echo $sale['nozzle_name']; ?></td>
                                                <td><?php echo $sale['customer_name'] ?: 'Walk-in'; ?></td>
                                                <td class="text-end"><?php echo number_format($sale['quantity_liters'], 2); ?> <?php echo $is_cng ? 'm³' : 'L'; ?></td>
                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['unit_price'], 2); ?></td>
                                                <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></td>
                                                <td>
                                                    <?php if($sale['sale_type'] == 'cash'): ?>
                                                        <span class="badge bg-success">Cash</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">Credit</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="no-print">
                                                    <button class="btn btn-sm btn-info btn-reprint" onclick="reprintInvoice('<?php echo $invoice_no; ?>', '<?php echo $is_cng ? 'cng' : 'liquid'; ?>')">
                                                        <i class="fas fa-print"></i> Reprint
                                                    </button>
                                                    <button class="btn btn-sm btn-primary btn-reprint" onclick="showInvoiceDetails('<?php echo $invoice_no; ?>', '<?php echo $is_cng ? 'cng' : 'liquid'; ?>')">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="8" class="text-end">TOTAL:</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_amount, 2); ?></td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            
                            <div class="mt-3 no-print">
                                <button onclick="window.print()" class="btn btn-primary">
                                    <i class="fas fa-print"></i> Print Report
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- ============================================= -->
            <!-- DAILY SALES REPORT TAB -->
            <!-- ============================================= -->
            <?php if($active_tab == 'daily'): ?>
            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5><i class="fas fa-calendar-day"></i> Daily Sales Report</h5>
                            <small class="d-block text-light">Select a date to view detailed daily sales.</small>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3 mb-4">
                                <input type="hidden" name="tab" value="daily">
                                <div class="col-md-4">
                                    <label>Select Date</label>
                                    <input type="date" name="report_date" class="form-control" value="<?php echo isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label>Sale Type</label>
                                    <select name="daily_sale_type" class="form-control">
                                        <option value="">All Types</option>
                                        <option value="cash" <?php echo (isset($_GET['daily_sale_type']) && $_GET['daily_sale_type'] == 'cash') ? 'selected' : ''; ?>>Cash</option>
                                        <option value="credit" <?php echo (isset($_GET['daily_sale_type']) && $_GET['daily_sale_type'] == 'credit') ? 'selected' : ''; ?>>Credit</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-info w-100">
                                        <i class="fas fa-search"></i> Generate Daily Report
                                    </button>
                                </div>
                            </form>
                            
                            <?php
                            $report_date = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');
                            $daily_sale_type = isset($_GET['daily_sale_type']) ? $_GET['daily_sale_type'] : '';
                            
                            // Get daily liquid sales
                            $sql_daily_liquid = "SELECT s.*, p.product_name, n.nozzle_name, 'liquid' as sale_type_label 
                                                  FROM sales s 
                                                  JOIN fuel_products p ON s.product_id = p.id 
                                                  JOIN nozzles n ON s.nozzle_id = n.id 
                                                  WHERE DATE(s.sale_date) = ?";
                            $params_daily = [$report_date];
                            if($daily_sale_type) {
                                $sql_daily_liquid .= " AND s.sale_type = ?";
                                $params_daily[] = $daily_sale_type;
                            }
                            
                            // Get daily CNG sales
                            $sql_daily_cng = "SELECT gs.*, p.product_name, n.nozzle_name, 'cng' as sale_type_label 
                                               FROM gas_sales gs 
                                               JOIN nozzles n ON gs.nozzle_id = n.id 
                                               JOIN fuel_products p ON n.product_id = p.id 
                                               WHERE DATE(gs.sale_date) = ? AND gs.status = 'completed'";
                            $params_daily_cng = [$report_date];
                            if($daily_sale_type) {
                                $sql_daily_cng .= " AND gs.sale_type = ?";
                                $params_daily_cng[] = $daily_sale_type;
                            }
                            
                            $stmt = $pdo->prepare($sql_daily_liquid);
                            $stmt->execute($params_daily);
                            $daily_liquid = $stmt->fetchAll();
                            
                            $stmt = $pdo->prepare($sql_daily_cng);
                            $stmt->execute($params_daily_cng);
                            $daily_cng = $stmt->fetchAll();
                            
                            $daily_sales = array_merge($daily_liquid, $daily_cng);
                            usort($daily_sales, function($a, $b) {
                                return strtotime($a['sale_date']) - strtotime($b['sale_date']);
                            });
                            
                            $daily_total = array_sum(array_column($daily_sales, 'total_amount'));
                            ?>
                            
                            <!-- Daily Summary -->
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="summary-card total-sales">
                                        <h6>Total Sales for <?php echo date('d-m-Y', strtotime($report_date)); ?></h6>
                                        <h4><?php echo $currency; ?> <?php echo number_format($daily_total, 2); ?></h4>
                                        <small><?php echo count($daily_sales); ?> Transactions</small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Daily Sales Table -->
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="dailySalesTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Time</th>
                                            <th>Invoice</th>
                                            <th>Type</th>
                                            <th>Product</th>
                                            <th>Nozzle</th>
                                            <th>Customer</th>
                                            <th class="text-end">Quantity</th>
                                            <th class="text-end">Total</th>
                                            <th>Sale Type</th>
                                            <th class="no-print">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($daily_sales)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted">No sales found for <?php echo date('d-m-Y', strtotime($report_date)); ?></td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach($daily_sales as $sale): 
                                                $is_cng = ($sale['sale_type_label'] == 'cng');
                                                $badge_class = $is_cng ? 'badge-cng' : 'badge-liquid';
                                            ?>
                                            <tr>
                                                <td><?php echo date('h:i A', strtotime($sale['sale_date'])); ?></td>
                                                <td>
                                                    <a href="javascript:void(0)" class="invoice-link" onclick="showInvoiceDetails('<?php echo $sale['invoice_no']; ?>', '<?php echo $is_cng ? 'cng' : 'liquid'; ?>')">
                                                        <?php echo $sale['invoice_no']; ?>
                                                    </a>
                                                </td>
                                                <td><span class="badge <?php echo $badge_class; ?>"><?php echo $is_cng ? 'CNG' : 'Liquid'; ?></span></td>
                                                <td><?php echo $sale['product_name']; ?></td>
                                                <td><?php echo $sale['nozzle_name']; ?></td>
                                                <td><?php echo $sale['customer_name'] ?: 'Walk-in'; ?></td>
                                                <td class="text-end"><?php echo number_format($sale['quantity_liters'], 2); ?> <?php echo $is_cng ? 'm³' : 'L'; ?></td>
                                                <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></td>
                                                <td>
                                                    <?php if($sale['sale_type'] == 'cash'): ?>
                                                        <span class="badge bg-success">Cash</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">Credit</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="no-print">
                                                    <button class="btn btn-sm btn-info btn-reprint" onclick="reprintInvoice('<?php echo $sale['invoice_no']; ?>', '<?php echo $is_cng ? 'cng' : 'liquid'; ?>')">
                                                        <i class="fas fa-print"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-primary btn-reprint" onclick="showInvoiceDetails('<?php echo $sale['invoice_no']; ?>', '<?php echo $is_cng ? 'cng' : 'liquid'; ?>')">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="7" class="text-end">TOTAL:</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($daily_total, 2); ?></td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- ============================================= -->
            <!-- NOZZLE-WISE REPORT TAB -->
            <!-- ============================================= -->
            <?php if($active_tab == 'nozzle'): ?>
            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-oil-can"></i> Nozzle-wise Sales Report</h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3 mb-4">
                                <input type="hidden" name="tab" value="nozzle">
                                <div class="col-md-3">
                                    <label>From Date</label>
                                    <input type="date" name="n_from_date" class="form-control" value="<?php echo isset($_GET['n_from_date']) ? $_GET['n_from_date'] : date('Y-m-01'); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label>To Date</label>
                                    <input type="date" name="n_to_date" class="form-control" value="<?php echo isset($_GET['n_to_date']) ? $_GET['n_to_date'] : date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label>Select Nozzle</label>
                                    <select name="n_nozzle_id" class="form-control">
                                        <option value="">All Nozzles</option>
                                        <?php foreach($nozzles as $n): ?>
                                            <option value="<?php echo $n['id']; ?>" <?php echo (isset($_GET['n_nozzle_id']) && $_GET['n_nozzle_id'] == $n['id']) ? 'selected' : ''; ?>>
                                                <?php echo $n['nozzle_name']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-search"></i> Generate
                                    </button>
                                </div>
                            </form>
                            
                            <?php
                            $n_from_date = isset($_GET['n_from_date']) ? $_GET['n_from_date'] : date('Y-m-01');
                            $n_to_date = isset($_GET['n_to_date']) ? $_GET['n_to_date'] : date('Y-m-d');
                            $n_nozzle_id = isset($_GET['n_nozzle_id']) ? $_GET['n_nozzle_id'] : '';
                            
                            $sql_nozzle = "
                                SELECT 
                                    n.nozzle_name,
                                    p.product_name,
                                    COUNT(s.id) as trans_count,
                                    SUM(s.quantity_liters) as total_qty,
                                    SUM(s.total_amount) as total_amount,
                                    'liquid' as type
                                FROM sales s
                                JOIN nozzles n ON s.nozzle_id = n.id
                                JOIN fuel_products p ON s.product_id = p.id
                                WHERE DATE(s.sale_date) BETWEEN ? AND ?
                            ";
                            $params_nozzle = [$n_from_date, $n_to_date];
                            if($n_nozzle_id) {
                                $sql_nozzle .= " AND n.id = ?";
                                $params_nozzle[] = $n_nozzle_id;
                            }
                            $sql_nozzle .= " GROUP BY n.nozzle_name, p.product_name";
                            
                            $sql_nozzle_cng = "
                                SELECT 
                                    n.nozzle_name,
                                    p.product_name,
                                    COUNT(gs.id) as trans_count,
                                    SUM(gs.quantity_liters) as total_qty,
                                    SUM(gs.total_amount) as total_amount,
                                    'cng' as type
                                FROM gas_sales gs
                                JOIN nozzles n ON gs.nozzle_id = n.id
                                JOIN fuel_products p ON n.product_id = p.id
                                WHERE DATE(gs.sale_date) BETWEEN ? AND ? AND gs.status = 'completed'
                            ";
                            $params_nozzle_cng = [$n_from_date, $n_to_date];
                            if($n_nozzle_id) {
                                $sql_nozzle_cng .= " AND n.id = ?";
                                $params_nozzle_cng[] = $n_nozzle_id;
                            }
                            $sql_nozzle_cng .= " GROUP BY n.nozzle_name, p.product_name";
                            
                            $stmt = $pdo->prepare($sql_nozzle);
                            $stmt->execute($params_nozzle);
                            $nozzle_data = $stmt->fetchAll();
                            
                            $stmt = $pdo->prepare($sql_nozzle_cng);
                            $stmt->execute($params_nozzle_cng);
                            $nozzle_cng_data = $stmt->fetchAll();
                            
                            $nozzle_data = array_merge($nozzle_data, $nozzle_cng_data);
                            $nozzle_total_amt = array_sum(array_column($nozzle_data, 'total_amount'));
                            ?>
                            
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="nozzleReportTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Nozzle Name</th>
                                            <th>Product</th>
                                            <th>Type</th>
                                            <th class="text-end">Transactions</th>
                                            <th class="text-end">Total Quantity</th>
                                            <th class="text-end">Amount (<?php echo $currency; ?>)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($nozzle_data)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">No data found</td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach($nozzle_data as $nd): 
                                                $is_cng = ($nd['type'] == 'cng');
                                                $badge_class = $is_cng ? 'badge-cng' : 'badge-liquid';
                                                $unit_label = $is_cng ? 'm³' : 'L';
                                            ?>
                                            <tr>
                                                <td><strong><?php echo $nd['nozzle_name']; ?></strong></td>
                                                <td><?php echo $nd['product_name']; ?></td>
                                                <td><span class="badge <?php echo $badge_class; ?>"><?php echo $is_cng ? 'CNG' : 'Liquid'; ?></span></td>
                                                <td class="text-end"><?php echo number_format($nd['trans_count']); ?></td>
                                                <td class="text-end"><?php echo number_format($nd['total_qty'], 2); ?> <?php echo $unit_label; ?></td>
                                                <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($nd['total_amount'], 2); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="5" class="text-end">TOTAL:</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($nozzle_total_amt, 2); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- ============================================= -->
            <!-- ITEM-WISE REPORT TAB -->
            <!-- ============================================= -->
            <?php if($active_tab == 'item'): ?>
            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="fas fa-tag"></i> Item-wise Sales Report</h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3 mb-4">
                                <input type="hidden" name="tab" value="item">
                                <div class="col-md-3">
                                    <label>From Date</label>
                                    <input type="date" name="i_from_date" class="form-control" value="<?php echo isset($_GET['i_from_date']) ? $_GET['i_from_date'] : date('Y-m-01'); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label>To Date</label>
                                    <input type="date" name="i_to_date" class="form-control" value="<?php echo isset($_GET['i_to_date']) ? $_GET['i_to_date'] : date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label>Product</label>
                                    <select name="i_product_id" class="form-control">
                                        <option value="">All Products</option>
                                        <?php foreach($products as $p): ?>
                                            <option value="<?php echo $p['id']; ?>" <?php echo (isset($_GET['i_product_id']) && $_GET['i_product_id'] == $p['id']) ? 'selected' : ''; ?>>
                                                <?php echo $p['product_name']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="fas fa-search"></i> Generate
                                    </button>
                                </div>
                            </form>
                            
                            <?php
                            $i_from_date = isset($_GET['i_from_date']) ? $_GET['i_from_date'] : date('Y-m-01');
                            $i_to_date = isset($_GET['i_to_date']) ? $_GET['i_to_date'] : date('Y-m-d');
                            $i_product_id = isset($_GET['i_product_id']) ? $_GET['i_product_id'] : '';
                            
                            $sql_item = "
                                SELECT 
                                    p.product_name,
                                    p.unit_type,
                                    COUNT(s.id) as trans_count,
                                    SUM(s.quantity_liters) as total_qty,
                                    SUM(s.total_amount) as total_amount,
                                    'liquid' as type
                                FROM sales s
                                JOIN fuel_products p ON s.product_id = p.id
                                WHERE DATE(s.sale_date) BETWEEN ? AND ?
                            ";
                            $params_item = [$i_from_date, $i_to_date];
                            if($i_product_id) {
                                $sql_item .= " AND p.id = ?";
                                $params_item[] = $i_product_id;
                            }
                            $sql_item .= " GROUP BY p.product_name, p.unit_type";
                            
                            $sql_item_cng = "
                                SELECT 
                                    p.product_name,
                                    p.unit_type,
                                    COUNT(gs.id) as trans_count,
                                    SUM(gs.quantity_liters) as total_qty,
                                    SUM(gs.total_amount) as total_amount,
                                    'cng' as type
                                FROM gas_sales gs
                                JOIN nozzles n ON gs.nozzle_id = n.id
                                JOIN fuel_products p ON n.product_id = p.id
                                WHERE DATE(gs.sale_date) BETWEEN ? AND ? AND gs.status = 'completed'
                            ";
                            $params_item_cng = [$i_from_date, $i_to_date];
                            if($i_product_id) {
                                $sql_item_cng .= " AND p.id = ?";
                                $params_item_cng[] = $i_product_id;
                            }
                            $sql_item_cng .= " GROUP BY p.product_name, p.unit_type";
                            
                            $stmt = $pdo->prepare($sql_item);
                            $stmt->execute($params_item);
                            $item_data = $stmt->fetchAll();
                            
                            $stmt = $pdo->prepare($sql_item_cng);
                            $stmt->execute($params_item_cng);
                            $item_cng_data = $stmt->fetchAll();
                            
                            $item_data = array_merge($item_data, $item_cng_data);
                            $item_total_amt = array_sum(array_column($item_data, 'total_amount'));
                            ?>
                            
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="itemReportTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Product Name</th>
                                            <th>Unit Type</th>
                                            <th>Type</th>
                                            <th class="text-end">Transactions</th>
                                            <th class="text-end">Total Quantity</th>
                                            <th class="text-end">Amount (<?php echo $currency; ?>)</th>
                                            <th class="text-end">Avg. Price</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($item_data)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted">No data found</td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach($item_data as $id): 
                                                $is_cng = ($id['type'] == 'cng');
                                                $badge_class = $is_cng ? 'badge-cng' : 'badge-liquid';
                                                $unit_label = $is_cng ? 'm³' : ($id['unit_type'] == 'kilograms' ? 'kg' : 'L');
                                                $avg_price = $id['total_qty'] > 0 ? $id['total_amount'] / $id['total_qty'] : 0;
                                            ?>
                                            <tr>
                                                <td><strong><?php echo $id['product_name']; ?></strong></td>
                                                <td><?php echo $id['unit_type'] ?? 'liters'; ?></td>
                                                <td><span class="badge <?php echo $badge_class; ?>"><?php echo $is_cng ? 'CNG' : 'Liquid'; ?></span></td>
                                                <td class="text-end"><?php echo number_format($id['trans_count']); ?></td>
                                                <td class="text-end"><?php echo number_format($id['total_qty'], 2); ?> <?php echo $unit_label; ?></td>
                                                <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($id['total_amount'], 2); ?></td>
                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($avg_price, 2); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="5" class="text-end">TOTAL:</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($item_total_amt, 2); ?></td>
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
            
            <!-- ============================================= -->
            <!-- INVENTORY TAB -->
            <!-- ============================================= -->
            <?php if($active_tab == 'inventory'): ?>
            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-warning text-dark">
                            <h5><i class="fas fa-warehouse"></i> Current Stock Position</h5>
                        </div>
                        <div class="card-body">
                            <?php
                            $current_stock = $pdo->query("
                                SELECT t.tank_name, p.product_name, t.current_stock_liters, p.purchase_rate 
                                FROM tanks t 
                                JOIN fuel_products p ON t.product_id = p.id 
                                ORDER BY t.tank_name
                            ")->fetchAll();
                            
                            $total_stock_liters = array_sum(array_column($current_stock, 'current_stock_liters'));
                            $total_stock_value = 0;
                            foreach($current_stock as $cs) {
                                $total_stock_value += $cs['current_stock_liters'] * $cs['purchase_rate'];
                            }
                            ?>
                            <div class="table-responsive">
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
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- ============================================= -->
            <!-- FINANCIAL TAB -->
            <!-- ============================================= -->
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
            <?php endif; ?>
            
            <!-- ============================================= -->
            <!-- PLAIN PRINT TAB -->
            <!-- ============================================= -->
            <?php if($active_tab == 'plain'): ?>
            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header" style="background:#f8f9fa; color:#000; border-bottom:2px solid #000;">
                            <h5><i class="fas fa-print"></i> Plain Paper Report</h5>
                            <small style="color:#555;">Clean, black & white report for printing on plain paper</small>
                        </div>
                        <div class="card-body">
                            <!-- Date Range Filter -->
                            <form method="GET" class="row g-3 mb-4 no-print">
                                <input type="hidden" name="tab" value="plain">
                                <div class="col-md-2">
                                    <label>From Date</label>
                                    <input type="date" name="p_from_date" class="form-control" value="<?php echo isset($_GET['p_from_date']) ? $_GET['p_from_date'] : date('Y-m-01'); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label>To Date</label>
                                    <input type="date" name="p_to_date" class="form-control" value="<?php echo isset($_GET['p_to_date']) ? $_GET['p_to_date'] : date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label>Product</label>
                                    <select name="p_product_id" class="form-control">
                                        <option value="">All Products</option>
                                        <?php foreach($products as $p): ?>
                                            <option value="<?php echo $p['id']; ?>" <?php echo (isset($_GET['p_product_id']) && $_GET['p_product_id'] == $p['id']) ? 'selected' : ''; ?>>
                                                <?php echo $p['product_name']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label>Nozzle</label>
                                    <select name="p_nozzle_id" class="form-control">
                                        <option value="">All Nozzles</option>
                                        <?php foreach($nozzles as $n): ?>
                                            <option value="<?php echo $n['id']; ?>" <?php echo (isset($_GET['p_nozzle_id']) && $_GET['p_nozzle_id'] == $n['id']) ? 'selected' : ''; ?>>
                                                <?php echo $n['nozzle_name']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label>Sale Type</label>
                                    <select name="p_sale_type" class="form-control">
                                        <option value="">All Types</option>
                                        <option value="cash" <?php echo (isset($_GET['p_sale_type']) && $_GET['p_sale_type'] == 'cash') ? 'selected' : ''; ?>>Cash</option>
                                        <option value="credit" <?php echo (isset($_GET['p_sale_type']) && $_GET['p_sale_type'] == 'credit') ? 'selected' : ''; ?>>Credit</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-primary w-100" style="border:1px solid #000; background:#f8f9fa; color:#000;">
                                        <i class="fas fa-search"></i> Generate
                                    </button>
                                </div>
                            </form>

                            <?php
                            // Get data for plain report
                            $p_from_date = isset($_GET['p_from_date']) ? $_GET['p_from_date'] : date('Y-m-01');
                            $p_to_date = isset($_GET['p_to_date']) ? $_GET['p_to_date'] : date('Y-m-d');
                            $p_product_id = isset($_GET['p_product_id']) ? $_GET['p_product_id'] : '';
                            $p_nozzle_id = isset($_GET['p_nozzle_id']) ? $_GET['p_nozzle_id'] : '';
                            $p_sale_type = isset($_GET['p_sale_type']) ? $_GET['p_sale_type'] : '';

                            // Get Liquid Sales
                            $sql_liquid = "SELECT s.*, p.product_name, n.nozzle_name, 'liquid' as sale_type_label, 'L' as unit_label 
                                           FROM sales s 
                                           JOIN fuel_products p ON s.product_id = p.id 
                                           JOIN nozzles n ON s.nozzle_id = n.id 
                                           WHERE DATE(s.sale_date) BETWEEN ? AND ?";
                            $params = [$p_from_date, $p_to_date];
                            
                            if($p_product_id) { $sql_liquid .= " AND s.product_id = ?"; $params[] = $p_product_id; }
                            if($p_nozzle_id) { $sql_liquid .= " AND s.nozzle_id = ?"; $params[] = $p_nozzle_id; }
                            if($p_sale_type) { $sql_liquid .= " AND s.sale_type = ?"; $params[] = $p_sale_type; }
                            
                            // Get CNG Sales
                            $sql_cng = "SELECT gs.*, p.product_name, n.nozzle_name, 'cng' as sale_type_label, 'm³' as unit_label 
                                        FROM gas_sales gs 
                                        JOIN nozzles n ON gs.nozzle_id = n.id 
                                        JOIN fuel_products p ON n.product_id = p.id 
                                        WHERE DATE(gs.sale_date) BETWEEN ? AND ? AND gs.status = 'completed'";
                            $params_cng = [$p_from_date, $p_to_date];
                            
                            if($p_product_id) { $sql_cng .= " AND n.product_id = ?"; $params_cng[] = $p_product_id; }
                            if($p_nozzle_id) { $sql_cng .= " AND gs.nozzle_id = ?"; $params_cng[] = $p_nozzle_id; }
                            if($p_sale_type) { $sql_cng .= " AND gs.sale_type = ?"; $params_cng[] = $p_sale_type; }
                            
                            $stmt = $pdo->prepare($sql_liquid);
                            $stmt->execute($params);
                            $liquid_sales = $stmt->fetchAll();
                            
                            $stmt = $pdo->prepare($sql_cng);
                            $stmt->execute($params_cng);
                            $cng_sales = $stmt->fetchAll();
                            
                            $all_sales_data = array_merge($liquid_sales, $cng_sales);
                            usort($all_sales_data, function($a, $b) {
                                return strtotime($a['sale_date']) - strtotime($b['sale_date']);
                            });
                            
                            // Calculate totals
                            $total_transactions = count($all_sales_data);
                            $total_amount = array_sum(array_column($all_sales_data, 'total_amount'));
                            $total_cash = 0;
                            $total_credit = 0;
                            $total_cng = 0;
                            
                            foreach($all_sales_data as $sale) {
                                if($sale['sale_type'] == 'cash') { $total_cash += $sale['total_amount']; } 
                                else { $total_credit += $sale['total_amount']; }
                                if($sale['sale_type_label'] == 'cng') { $total_cng += $sale['total_amount']; }
                            }
                            $total_liquid = $total_amount - $total_cng;
                            ?>

                            <!-- Plain Summary -->
                            <div class="row mb-3">
                                <div class="col-md-3">
                                    <div class="plain-summary">
                                        <h6>Total Sales</h6>
                                        <h4><?php echo $currency; ?> <?php echo number_format($total_amount, 2); ?></h4>
                                        <small><?php echo $total_transactions; ?> Transactions</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="plain-summary">
                                        <h6>Cash Sales</h6>
                                        <h4><?php echo $currency; ?> <?php echo number_format($total_cash, 2); ?></h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="plain-summary">
                                        <h6>Credit Sales</h6>
                                        <h4><?php echo $currency; ?> <?php echo number_format($total_credit, 2); ?></h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="plain-summary">
                                        <h6>CNG Sales</h6>
                                        <h4><?php echo $currency; ?> <?php echo number_format($total_cng, 2); ?></h4>
                                        <small>Liquid: <?php echo $currency; ?> <?php echo number_format($total_liquid, 2); ?></small>
                                    </div>
                                </div>
                            </div>

                            <!-- Plain Table -->
                            <div class="table-responsive">
                                <table class="table plain-table" id="plainReportTable">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Invoice</th>
                                            <th>Type</th>
                                            <th>Product</th>
                                            <th>Nozzle</th>
                                            <th>Customer</th>
                                            <th class="text-end">Quantity</th>
                                            <th class="text-end">Unit Price</th>
                                            <th class="text-end">Total</th>
                                            <th>Sale Type</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($all_sales_data)): ?>
                                        <tr>
                                            <td colspan="10" style="text-align:center;">No sales found</td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach($all_sales_data as $sale): 
                                                $is_cng = ($sale['sale_type_label'] == 'cng');
                                                $badge_text = $is_cng ? 'CNG' : 'Liquid';
                                                $unit_label = $is_cng ? 'm³' : 'L';
                                            ?>
                                            <tr>
                                                <td><?php echo date('d-m-Y h:i A', strtotime($sale['sale_date'])); ?></td>
                                                <td><?php echo $sale['invoice_no']; ?></td>
                                                <td><?php echo $badge_text; ?></td>
                                                <td><?php echo $sale['product_name']; ?></td>
                                                <td><?php echo $sale['nozzle_name']; ?></td>
                                                <td><?php echo $sale['customer_name'] ?: 'Walk-in'; ?></td>
                                                <td class="text-end"><?php echo number_format($sale['quantity_liters'], 2); ?> <?php echo $unit_label; ?></td>
                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['unit_price'], 2); ?></td>
                                                <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></td>
                                                <td><?php echo ucfirst($sale['sale_type']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="fw-bold">
                                            <td colspan="8" class="text-end">TOTAL:</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_amount, 2); ?></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <div class="mt-3 no-print">
                                <button onclick="window.print()" class="btn btn-primary" style="border:1px solid #000; background:#f8f9fa; color:#000;">
                                    <i class="fas fa-print"></i> Print Report (Plain Paper)
                                </button>
                                <button onclick="window.location.href='?tab=plain&p_from_date=<?php echo $p_from_date; ?>&p_to_date=<?php echo $p_to_date; ?>'" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> Refresh
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Footer -->
            <div class="no-print" style="margin-top:20px; padding-top:10px; border-top:1px solid #ddd; text-align:center; font-size:11px; color:#666;">
                <i class="fas fa-info-circle"></i>
                <strong>Tip:</strong> Click <strong>Print Report</strong> button for plain paper format in landscape orientation.
            </div>
        </div>
    </div>
    
    <!-- Invoice Details Modal -->
    <div class="modal fade" id="invoiceDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5><i class="fas fa-file-invoice"></i> Invoice Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="invoiceDetailsBody">
                    <div class="text-center text-muted">
                        <i class="fas fa-spinner fa-spin fa-2x"></i>
                        <p>Loading invoice details...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-info" id="modalReprintBtn" onclick="modalReprint()">
                        <i class="fas fa-print"></i> Reprint
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            if ($.fn.DataTable) {
                $('#salesReportTable').DataTable({
                    order: [[0, 'desc']],
                    pageLength: 25,
                    language: {
                        search: "Search:",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ entries"
                    }
                });
                
                $('#dailySalesTable').DataTable({
                    order: [[0, 'desc']],
                    pageLength: 25,
                    language: {
                        search: "Search:",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ entries"
                    }
                });
                
                $('#nozzleReportTable').DataTable({
                    order: [[0, 'asc']],
                    pageLength: 25,
                    language: {
                        search: "Search:",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ entries"
                    }
                });
                
                $('#itemReportTable').DataTable({
                    order: [[0, 'asc']],
                    pageLength: 25,
                    language: {
                        search: "Search:",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ entries"
                    }
                });
                
                $('#plainReportTable').DataTable({
                    order: [[0, 'desc']],
                    pageLength: 25,
                    language: {
                        search: "Search:",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ entries"
                    }
                });
            }
        });
        
        function showInvoiceDetails(invoiceNo, type) {
            var modal = new bootstrap.Modal(document.getElementById('invoiceDetailsModal'));
            modal.show();
            
            document.getElementById('invoiceDetailsBody').innerHTML = `
                <div class="text-center text-muted">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p>Loading invoice details for ${invoiceNo}...</p>
                </div>
            `;
            
            $.ajax({
                url: 'get_invoice_details.php',
                type: 'GET',
                data: { invoice: invoiceNo, type: type },
                dataType: 'json',
                success: function(data) {
                    if(data.success) {
                        displayInvoiceDetails(data.invoice);
                        window._currentInvoice = { invoice: invoiceNo, type: type };
                    } else {
                        document.getElementById('invoiceDetailsBody').innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle"></i> ${data.message || 'Error loading invoice details'}
                            </div>
                        `;
                    }
                },
                error: function() {
                    document.getElementById('invoiceDetailsBody').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> Failed to load invoice details. Please try again.
                        </div>
                    `;
                }
            });
        }
        
        function displayInvoiceDetails(invoice) {
            var html = `
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Invoice No:</strong> ${invoice.invoice_no}</p>
                        <p><strong>Date:</strong> ${invoice.sale_date}</p>
                        <p><strong>Type:</strong> <span class="badge ${invoice.sale_type_display == 'cng' ? 'badge-cng' : 'badge-liquid'}">${invoice.sale_type_display == 'cng' ? 'CNG' : 'Liquid'}</span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Customer:</strong> ${invoice.customer_name || 'Walk-in'}</p>
                        <p><strong>Sale Type:</strong> <span class="badge ${invoice.sale_type == 'cash' ? 'bg-success' : 'bg-warning'}">${invoice.sale_type || 'Cash'}</span></p>
                        <p><strong>Operator:</strong> ${invoice.operator_name || 'N/A'}</p>
                    </div>
                </div>
                <hr>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Product</th>
                                <th>Nozzle</th>
                                <th class="text-end">Quantity</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>${invoice.product_name}</td>
                                <td>${invoice.nozzle_name}</td>
                                <td class="text-end">${parseFloat(invoice.quantity_liters).toFixed(2)} ${invoice.sale_type_display == 'cng' ? 'm³' : 'L'}</td>
                                <td class="text-end">${invoice.currency} ${parseFloat(invoice.unit_price).toFixed(2)}</td>
                                <td class="text-end fw-bold">${invoice.currency} ${parseFloat(invoice.total_amount).toFixed(2)}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            ${invoice.advance_used > 0 ? `
                            <tr>
                                <td colspan="4" class="text-end"><strong>Advance Used:</strong></td>
                                <td class="text-end text-success">${invoice.currency} ${parseFloat(invoice.advance_used).toFixed(2)}</td>
                            </tr>
                            ` : ''}
                            ${invoice.sale_type_display != 'cng' ? `
                            <tr>
                                <td colspan="4" class="text-end"><strong>Subtotal:</strong></td>
                                <td class="text-end">${invoice.currency} ${parseFloat(invoice.subtotal).toFixed(2)}</td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end"><strong>VAT:</strong></td>
                                <td class="text-end">${invoice.currency} ${parseFloat(invoice.vat_amount).toFixed(2)}</td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end"><strong>Tax:</strong></td>
                                <td class="text-end">${invoice.currency} ${parseFloat(invoice.tax_amount).toFixed(2)}</td>
                            </tr>
                            ` : ''}
                            <tr class="fw-bold">
                                <td colspan="4" class="text-end"><strong>Total Amount:</strong></td>
                                <td class="text-end">${invoice.currency} ${parseFloat(invoice.total_amount).toFixed(2)}</td>
                            </tr>
                            ${invoice.received_amount > 0 ? `
                            <tr>
                                <td colspan="4" class="text-end"><strong>Received:</strong></td>
                                <td class="text-end text-success">${invoice.currency} ${parseFloat(invoice.received_amount).toFixed(2)}</td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end"><strong>Change:</strong></td>
                                <td class="text-end">${invoice.currency} ${parseFloat(invoice.change_amount).toFixed(2)}</td>
                            </tr>
                            ` : ''}
                        </tfoot>
                    </table>
                </div>
                ${invoice.meter_readings ? `
                <div class="alert alert-info">
                    <strong>Meter Readings:</strong><br>
                    Opening: ${invoice.opening_meter} m³ | Closing: ${invoice.closing_meter} m³ | Dispensed: ${invoice.quantity_liters} m³
                </div>
                ` : ''}
            `;
            document.getElementById('invoiceDetailsBody').innerHTML = html;
        }
        
        function reprintInvoice(invoiceNo, type) {
            var url = type == 'cng' ? 'print_cng_invoice.php' : 'print_invoice.php';
            window.open(url + '?invoice=' + invoiceNo, '_blank');
        }
        
        function modalReprint() {
            if(window._currentInvoice) {
                reprintInvoice(window._currentInvoice.invoice, window._currentInvoice.type);
            }
        }
    </script>
</body>
</html>
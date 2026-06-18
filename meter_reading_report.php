<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Get filters
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'daily';
$report_date = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$nozzle_id = isset($_GET['nozzle_id']) ? $_GET['nozzle_id'] : '';
$shift_id = isset($_GET['shift_id']) ? $_GET['shift_id'] : '';

// Get all nozzles for filter
$nozzles = $pdo->query("
    SELECT n.*, p.product_name 
    FROM nozzles n 
    JOIN fuel_products p ON n.product_id = p.id 
    WHERE n.is_active = 1 
    ORDER BY n.is_pipeline DESC, n.nozzle_name
")->fetchAll();

// Get shifts for filter
$shifts = $pdo->query("SELECT * FROM shift_schedule WHERE is_active = 1 ORDER BY shift_name")->fetchAll();

// =============================================
// DAILY METER READING REPORT
// =============================================
if($report_type == 'daily') {
    // Get daily meter readings for selected date
    $sql = "
        SELECT 
            mr.*,
            n.nozzle_name,
            n.is_pipeline,
            p.product_name,
            u.full_name as recorder_name,
            sh.shift_name
        FROM meter_readings mr
        JOIN nozzles n ON mr.nozzle_id = n.id
        JOIN fuel_products p ON n.product_id = p.id
        LEFT JOIN users u ON mr.recorded_by = u.id
        LEFT JOIN shift_schedule sh ON mr.shift_id = sh.id
        WHERE DATE(mr.reading_date) = ?
    ";
    $params = [$report_date];
    
    if($nozzle_id) {
        $sql .= " AND mr.nozzle_id = ?";
        $params[] = $nozzle_id;
    }
    if($shift_id) {
        $sql .= " AND mr.shift_id = ?";
        $params[] = $shift_id;
    }
    
    $sql .= " ORDER BY n.nozzle_name, mr.reading_date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $daily_readings = $stmt->fetchAll();
    
    // Get previous day readings for comparison
    $prev_date = date('Y-m-d', strtotime('-1 day', strtotime($report_date)));
    $stmt = $pdo->prepare("
        SELECT mr.*, n.nozzle_name 
        FROM meter_readings mr
        JOIN nozzles n ON mr.nozzle_id = n.id
        WHERE DATE(mr.reading_date) = ?
        ORDER BY n.nozzle_name
    ");
    $stmt->execute([$prev_date]);
    $prev_readings = $stmt->fetchAll();
    
    // Group previous readings by nozzle
    $prev_by_nozzle = [];
    foreach($prev_readings as $pr) {
        $prev_by_nozzle[$pr['nozzle_id']] = $pr['closing_meter'];
    }
    
    // Calculate totals
    $total_daily_dispensed = 0;
    foreach($daily_readings as $dr) {
        $total_daily_dispensed += ($dr['closing_meter'] - $dr['opening_meter']);
    }
}

// =============================================
// MONTHLY METER READING REPORT
// =============================================
if($report_type == 'monthly') {
    // Get monthly meter readings
    $sql = "
        SELECT 
            DATE(mr.reading_date) as reading_date,
            mr.nozzle_id,
            n.nozzle_name,
            n.is_pipeline,
            p.product_name,
            MIN(mr.opening_meter) as opening_meter,
            MAX(mr.closing_meter) as closing_meter,
            (MAX(mr.closing_meter) - MIN(mr.opening_meter)) as total_dispensed,
            COUNT(DISTINCT DATE(mr.reading_date)) as reading_days
        FROM meter_readings mr
        JOIN nozzles n ON mr.nozzle_id = n.id
        JOIN fuel_products p ON n.product_id = p.id
        WHERE DATE(mr.reading_date) BETWEEN ? AND ?
    ";
    $params = [$from_date, $to_date];
    
    if($nozzle_id) {
        $sql .= " AND mr.nozzle_id = ?";
        $params[] = $nozzle_id;
    }
    
    $sql .= " GROUP BY DATE(mr.reading_date), mr.nozzle_id, n.nozzle_name, n.is_pipeline, p.product_name
              ORDER BY n.nozzle_name, DATE(mr.reading_date) DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $monthly_readings = $stmt->fetchAll();
    
    // Get monthly summary by nozzle
    $sql_summary = "
        SELECT 
            mr.nozzle_id,
            n.nozzle_name,
            n.is_pipeline,
            p.product_name,
            MIN(mr.opening_meter) as month_start,
            MAX(mr.closing_meter) as month_end,
            (MAX(mr.closing_meter) - MIN(mr.opening_meter)) as total_monthly_dispensed,
            COUNT(DISTINCT DATE(mr.reading_date)) as days_with_readings
        FROM meter_readings mr
        JOIN nozzles n ON mr.nozzle_id = n.id
        JOIN fuel_products p ON n.product_id = p.id
        WHERE DATE(mr.reading_date) BETWEEN ? AND ?
    ";
    $params_summary = [$from_date, $to_date];
    
    if($nozzle_id) {
        $sql_summary .= " AND mr.nozzle_id = ?";
        $params_summary[] = $nozzle_id;
    }
    
    $sql_summary .= " GROUP BY mr.nozzle_id, n.nozzle_name, n.is_pipeline, p.product_name
                      ORDER BY n.nozzle_name";
    
    $stmt = $pdo->prepare($sql_summary);
    $stmt->execute($params_summary);
    $monthly_summary = $stmt->fetchAll();
    
    // Get first and last readings of month
    $first_day = date('Y-m-01', strtotime($from_date));
    $last_day = date('Y-m-t', strtotime($to_date));
}

// Get current meter readings (latest)
$current_readings = $pdo->query("
    SELECT 
        n.*,
        p.product_name,
        n.closing_meter as current_meter
    FROM nozzles n
    JOIN fuel_products p ON n.product_id = p.id
    WHERE n.is_active = 1
    ORDER BY n.is_pipeline DESC, n.nozzle_name
")->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meter Reading Report</title>
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
        .stats-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stats-card.blue { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stats-card.orange { background: linear-gradient(135deg, #f6d365 0%, #fda085 100%); }
        
        .badge-cng { background: #17a2b8; color: white; }
        .badge-liquid { background: #28a745; color: white; }
        .badge-lpg { background: #ffc107; color: #856404; }
        
        .nav-tabs-custom {
            border-bottom: 2px solid #dee2e6;
            padding: 0;
            margin-bottom: 20px;
            background: #f8f9fa;
            border-radius: 8px 8px 0 0;
        }
        .nav-tabs-custom .nav-link {
            color: #000 !important;
            font-weight: 600;
            padding: 12px 25px;
            border: none;
            border-radius: 8px 8px 0 0;
            background: transparent;
        }
        .nav-tabs-custom .nav-link.active {
            color: #0d6efd !important;
            background: #ffffff;
            border-bottom: 3px solid #0d6efd;
        }
        .tab-content-custom {
            background: white;
            padding: 20px;
            border-radius: 0 0 8px 8px;
            border: 1px solid #dee2e6;
            border-top: none;
            min-height: 500px;
        }
        .meter-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px 15px;
            margin: 5px 0;
        }
        .meter-box .number {
            font-size: 20px;
            font-weight: bold;
            font-family: 'Courier New', monospace;
        }
        .meter-box .number.green { color: #28a745; }
        .meter-box .number.blue { color: #17a2b8; }
        .meter-box .number.red { color: #dc3545; }
        .meter-box .number.orange { color: #ffc107; }
        
        .comparison-up { color: #28a745; }
        .comparison-down { color: #dc3545; }
        .comparison-same { color: #6c757d; }
        
        @media print {
            .sidebar, .no-print, .btn, .dataTables_length, .dataTables_filter, 
            .dataTables_paginate, .nav-tabs-custom, form { display: none !important; }
            .main-content { margin: 0 !important; padding: 10px !important; }
            .print-header { display: block !important; text-align: center; margin-bottom: 20px; }
        }
        .print-header { display: none; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="print-header">
                <h2><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h2>
                <h4>Meter Reading Report</h4>
                <p><?php echo $report_type == 'daily' ? 'Date: ' . date('d F Y', strtotime($report_date)) : 'Period: ' . date('d F Y', strtotime($from_date)) . ' to ' . date('d F Y', strtotime($to_date)); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-tachometer-alt"></i> Meter Reading Report</h2>
                <div class="no-print">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="meter_reading.php" class="btn btn-info">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </div>
            </div>
            
            <!-- Current Meter Status -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-chart-simple"></i> Current Meter Status (All Nozzles)</h5>
                    <small>As of <?php echo date('d-m-Y h:i:s A'); ?></small>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="table-dark">
                                <tr>
                                    <th>Nozzle</th>
                                    <th>Product</th>
                                    <th>Type</th>
                                    <th class="text-end">Current Meter</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($current_readings as $cr): 
                                    $is_cng = $cr['is_pipeline'] == 1;
                                    $badge_class = $is_cng ? 'badge-cng' : 'badge-liquid';
                                ?>
                                <tr>
                                    <td><strong><?php echo $cr['nozzle_name']; ?></strong></td>
                                    <td><?php echo $cr['product_name']; ?></td>
                                    <td><span class="badge <?php echo $badge_class; ?>"><?php echo $is_cng ? 'CNG' : 'Liquid'; ?></span></td>
                                    <td class="text-end meter-box">
                                        <span class="number <?php echo $is_cng ? 'blue' : 'green'; ?>">
                                            <?php echo number_format($cr['current_meter'] ?? 0, 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($is_cng): ?>
                                            <span class="badge bg-info">Pipeline</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Tank</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Tabs -->
            <ul class="nav nav-tabs-custom">
                <li class="nav-item">
                    <a class="nav-link <?php echo $report_type == 'daily' ? 'active' : ''; ?>" href="?report_type=daily">
                        <i class="fas fa-calendar-day"></i> Daily Report
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $report_type == 'monthly' ? 'active' : ''; ?>" href="?report_type=monthly">
                        <i class="fas fa-calendar-alt"></i> Monthly Report
                    </a>
                </li>
            </ul>
            
            <div class="tab-content-custom">
                <!-- ============================================= -->
                <!-- DAILY REPORT -->
                <!-- ============================================= -->
                <?php if($report_type == 'daily'): ?>
                
                <!-- Filter -->
                <form method="GET" class="row g-3 mb-4 no-print">
                    <input type="hidden" name="report_type" value="daily">
                    <div class="col-md-3">
                        <label>Select Date</label>
                        <input type="date" name="report_date" class="form-control" value="<?php echo $report_date; ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label>Select Nozzle</label>
                        <select name="nozzle_id" class="form-control">
                            <option value="">All Nozzles</option>
                            <?php foreach($nozzles as $n): ?>
                                <option value="<?php echo $n['id']; ?>" <?php echo $nozzle_id == $n['id'] ? 'selected' : ''; ?>>
                                    <?php echo $n['nozzle_name']; ?> (<?php echo $n['product_name']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>Select Shift</label>
                        <select name="shift_id" class="form-control">
                            <option value="">All Shifts</option>
                            <?php foreach($shifts as $s): ?>
                                <option value="<?php echo $s['id']; ?>" <?php echo $shift_id == $s['id'] ? 'selected' : ''; ?>>
                                    <?php echo $s['shift_name']; ?>
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
                
                <!-- Daily Statistics -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="stats-card">
                            <i class="fas fa-gas-pump"></i>
                            <h3><?php echo count($daily_readings); ?></h3>
                            <p>Readings Recorded</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card green">
                            <i class="fas fa-tachometer-alt"></i>
                            <h3><?php echo number_format($total_daily_dispensed, 2); ?> Units</h3>
                            <p>Total Dispensed Today</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card blue">
                            <i class="fas fa-clock"></i>
                            <h3><?php echo date('d-m-Y', strtotime($report_date)); ?></h3>
                            <p>Report Date</p>
                        </div>
                    </div>
                </div>
                
                <!-- Daily Readings Table -->
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="dailyTable">
                        <thead class="table-dark">
                            <tr>
                                <th>Nozzle</th>
                                <th>Product</th>
                                <th>Type</th>
                                <th>Shift</th>
                                <th class="text-end">Opening Meter</th>
                                <th class="text-end">Closing Meter</th>
                                <th class="text-end">Dispensed</th>
                                <th class="text-end">Previous Day</th>
                                <th class="text-end">Difference</th>
                                <th>Recorded By</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($daily_readings as $dr): 
                                $is_cng = $dr['is_pipeline'] == 1;
                                $badge_class = $is_cng ? 'badge-cng' : 'badge-liquid';
                                $dispensed = $dr['closing_meter'] - $dr['opening_meter'];
                                $prev_reading = $prev_by_nozzle[$dr['nozzle_id']] ?? 0;
                                $difference = $dr['closing_meter'] - $prev_reading;
                                $diff_class = $difference > 0 ? 'comparison-up' : ($difference < 0 ? 'comparison-down' : 'comparison-same');
                                $diff_sign = $difference > 0 ? '+' : '';
                            ?>
                            <tr>
                                <td><strong><?php echo $dr['nozzle_name']; ?></strong></td>
                                <td><?php echo $dr['product_name']; ?></td>
                                <td><span class="badge <?php echo $badge_class; ?>"><?php echo $is_cng ? 'CNG' : 'Liquid'; ?></span></td>
                                <td><?php echo $dr['shift_name'] ?? 'N/A'; ?></td>
                                <td class="text-end meter-box">
                                    <span class="number"><?php echo number_format($dr['opening_meter'], 2); ?></span>
                                </td>
                                <td class="text-end meter-box">
                                    <span class="number <?php echo $is_cng ? 'blue' : 'green'; ?>">
                                        <?php echo number_format($dr['closing_meter'], 2); ?>
                                    </span>
                                </td>
                                <td class="text-end meter-box">
                                    <span class="number orange fw-bold"><?php echo number_format($dispensed, 2); ?></span>
                                </td>
                                <td class="text-end"><?php echo number_format($prev_reading, 2); ?></td>
                                <td class="text-end <?php echo $diff_class; ?>">
                                    <?php echo $diff_sign . number_format($difference, 2); ?>
                                </td>
                                <td><?php echo $dr['recorder_name'] ?? 'N/A'; ?></td>
                                <td><?php echo date('h:i A', strtotime($dr['reading_date'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($daily_readings)): ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted">No readings found for <?php echo date('d-m-Y', strtotime($report_date)); ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="6" class="text-end">TOTAL:</td>
                                <td class="text-end text-success"><?php echo number_format($total_daily_dispensed, 2); ?></td>
                                <td colspan="4"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
                
                <!-- ============================================= -->
                <!-- MONTHLY REPORT -->
                <!-- ============================================= -->
                <?php if($report_type == 'monthly'): ?>
                
                <!-- Filter -->
                <form method="GET" class="row g-3 mb-4 no-print">
                    <input type="hidden" name="report_type" value="monthly">
                    <div class="col-md-3">
                        <label>From Date</label>
                        <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label>To Date</label>
                        <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label>Select Nozzle</label>
                        <select name="nozzle_id" class="form-control">
                            <option value="">All Nozzles</option>
                            <?php foreach($nozzles as $n): ?>
                                <option value="<?php echo $n['id']; ?>" <?php echo $nozzle_id == $n['id'] ? 'selected' : ''; ?>>
                                    <?php echo $n['nozzle_name']; ?> (<?php echo $n['product_name']; ?>)
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
                
                <!-- Monthly Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card">
                            <i class="fas fa-calendar-alt"></i>
                            <h3><?php echo count($monthly_summary); ?></h3>
                            <p>Nozzles with Readings</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card green">
                            <i class="fas fa-tachometer-alt"></i>
                            <h3><?php 
                                $total_monthly = array_sum(array_column($monthly_summary, 'total_monthly_dispensed'));
                                echo number_format($total_monthly, 2); 
                            ?> Units</h3>
                            <p>Total Monthly Dispensed</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card blue">
                            <i class="fas fa-calendar-day"></i>
                            <h3><?php echo date('d M Y', strtotime($from_date)); ?></h3>
                            <p>From Date</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card orange">
                            <i class="fas fa-calendar-day"></i>
                            <h3><?php echo date('d M Y', strtotime($to_date)); ?></h3>
                            <p>To Date</p>
                        </div>
                    </div>
                </div>
                
                <!-- Monthly Summary by Nozzle -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5><i class="fas fa-chart-bar"></i> Monthly Summary by Nozzle</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Nozzle</th>
                                        <th>Product</th>
                                        <th>Type</th>
                                        <th class="text-end">Month Start</th>
                                        <th class="text-end">Month End</th>
                                        <th class="text-end">Total Dispensed</th>
                                        <th>Days with Readings</th>
                                        <th class="text-end">Daily Avg</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($monthly_summary as $ms): 
                                        $is_cng = $ms['is_pipeline'] == 1;
                                        $badge_class = $is_cng ? 'badge-cng' : 'badge-liquid';
                                        $daily_avg = $ms['days_with_readings'] > 0 ? $ms['total_monthly_dispensed'] / $ms['days_with_readings'] : 0;
                                    ?>
                                    <tr>
                                        <td><strong><?php echo $ms['nozzle_name']; ?></strong></td>
                                        <td><?php echo $ms['product_name']; ?></td>
                                        <td><span class="badge <?php echo $badge_class; ?>"><?php echo $is_cng ? 'CNG' : 'Liquid'; ?></span></td>
                                        <td class="text-end"><?php echo number_format($ms['month_start'], 2); ?></td>
                                        <td class="text-end"><?php echo number_format($ms['month_end'], 2); ?></td>
                                        <td class="text-end fw-bold text-success"><?php echo number_format($ms['total_monthly_dispensed'], 2); ?></td>
                                        <td class="text-center"><?php echo $ms['days_with_readings']; ?></td>
                                        <td class="text-end"><?php echo number_format($daily_avg, 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($monthly_summary)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted">No readings found for this period</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr class="fw-bold">
                                        <td colspan="5" class="text-end">TOTAL:</td>
                                        <td class="text-end text-success"><?php echo number_format($total_monthly, 2); ?></td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Daily Breakdown for Monthly Report -->
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="fas fa-list"></i> Daily Breakdown</h5>
                        <small class="d-block text-light">Daily meter readings for the selected period</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" id="monthlyTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Date</th>
                                        <th>Nozzle</th>
                                        <th>Product</th>
                                        <th>Type</th>
                                        <th class="text-end">Opening</th>
                                        <th class="text-end">Closing</th>
                                        <th class="text-end">Dispensed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($monthly_readings as $mr): 
                                        $is_cng = $mr['is_pipeline'] == 1;
                                        $badge_class = $is_cng ? 'badge-cng' : 'badge-liquid';
                                        $dispensed = $mr['closing_meter'] - $mr['opening_meter'];
                                    ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($mr['reading_date'])); ?></td>
                                        <td><strong><?php echo $mr['nozzle_name']; ?></strong></td>
                                        <td><?php echo $mr['product_name']; ?></td>
                                        <td><span class="badge <?php echo $badge_class; ?>"><?php echo $is_cng ? 'CNG' : 'Liquid'; ?></span></td>
                                        <td class="text-end"><?php echo number_format($mr['opening_meter'], 2); ?></td>
                                        <td class="text-end"><?php echo number_format($mr['closing_meter'], 2); ?></td>
                                        <td class="text-end fw-bold text-success"><?php echo number_format($mr['total_dispensed'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($monthly_readings)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted">No readings found for this period</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#dailyTable').DataTable({
                order: [[0, 'asc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
            
            $('#monthlyTable').DataTable({
                order: [[0, 'desc'], [1, 'asc']],
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
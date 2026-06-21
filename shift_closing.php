<?php
// shift_closing.php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'active';

// Get active shift
$stmt = $pdo->query("
    SELECT sc.*, sh.shift_name 
    FROM shift_closing sc 
    JOIN shift_schedule sh ON sc.shift_id = sh.id 
    WHERE sc.status = 'open' 
    ORDER BY sc.id DESC 
    LIMIT 1
");
$active_shift = $stmt->fetch();

// Get all shifts
$shifts = $pdo->query("SELECT * FROM shift_schedule WHERE is_active = 1")->fetchAll();

// Get tanks and nozzles for stock
$tanks = $pdo->query("SELECT t.*, p.product_name FROM tanks t JOIN fuel_products p ON t.product_id = p.id WHERE t.is_active = 1")->fetchAll();

// =============================================
// FIXED: Get ALL nozzles including CNG pipeline nozzles
// =============================================
$nozzles = $pdo->query("
    SELECT 
        n.*, 
        t.tank_name, 
        p.product_name,
        CASE WHEN n.is_pipeline = 1 THEN 'Pipeline' ELSE 'Tank' END as source_type
    FROM nozzles n 
    LEFT JOIN tanks t ON n.tank_id = t.id 
    JOIN fuel_products p ON n.product_id = p.id 
    WHERE n.is_active = 1 
    ORDER BY n.is_pipeline DESC, n.nozzle_name
")->fetchAll();

// Group nozzles by type for display
$pipeline_nozzles = array_filter($nozzles, function($n) { return $n['is_pipeline'] == 1; });
$tank_nozzles = array_filter($nozzles, function($n) { return $n['is_pipeline'] == 0; });

// Process Start Shift
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['start_shift'])) {
    $shift_id = $_POST['shift_id'];
    $shift_date = date('Y-m-d');
    $notes = $_POST['notes'];
    
    try {
        $pdo->beginTransaction();
        
        // Check if shift already open
        $stmt = $pdo->query("SELECT id FROM shift_closing WHERE status = 'open'");
        if($stmt->rowCount() > 0) {
            throw new Exception("A shift is already open! Please close it first.");
        }
        
        // Insert shift closing record
        $stmt = $pdo->prepare("INSERT INTO shift_closing (shift_id, shift_date, opening_time, opened_by, notes, status) VALUES (?, ?, NOW(), ?, ?, 'open')");
        $stmt->execute([$shift_id, $shift_date, $user['id'], $notes]);
        $shift_closing_id = $pdo->lastInsertId();
        
        // Record opening stock for each tank
        foreach($tanks as $tank) {
            $stmt = $pdo->prepare("INSERT INTO shift_stock (shift_id, tank_id, opening_stock, closing_stock, recorded_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$shift_closing_id, $tank['id'], $tank['current_stock_liters'], 0, $user['id']]);
        }
        
        // =============================================
        // FIXED: Record opening meter readings for EACH nozzle
        // =============================================
        foreach($nozzles as $nozzle) {
            $opening_meter = isset($_POST['opening_meter_' . $nozzle['id']]) ? floatval($_POST['opening_meter_' . $nozzle['id']]) : $nozzle['closing_meter'];
            $plc_count = isset($_POST['plc_count_' . $nozzle['id']]) ? floatval($_POST['plc_count_' . $nozzle['id']]) : 0;
            
            $stmt = $pdo->prepare("
                INSERT INTO meter_readings (
                    nozzle_id, reading_date, shift_id, 
                    opening_meter, closing_meter, 
                    plc_count, opening_plc_count, closing_plc_count,
                    recorded_by, shift_closed
                ) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, 0)
            ");
            $stmt->execute([
                $nozzle['id'], 
                $shift_closing_id, 
                $opening_meter, 
                0,
                $plc_count,
                $plc_count,
                0,
                $user['id']
            ]);
        }
        
        $pdo->commit();
        $success = "✅ Shift started successfully with " . count($nozzles) . " nozzle meter readings!";
        
        // Refresh active shift
        $stmt = $pdo->query("
            SELECT sc.*, sh.shift_name 
            FROM shift_closing sc 
            JOIN shift_schedule sh ON sc.shift_id = sh.id 
            WHERE sc.status = 'open' 
            ORDER BY sc.id DESC 
            LIMIT 1
        ");
        $active_shift = $stmt->fetch();
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Process Close Shift
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['close_shift'])) {
    $shift_id = $_POST['shift_id'];
    $closing_cash = floatval($_POST['closing_cash']);
    $notes = $_POST['notes'];
    
    try {
        $pdo->beginTransaction();
        
        // Get shift details
        $stmt = $pdo->prepare("SELECT * FROM shift_closing WHERE id = ? AND status = 'open'");
        $stmt->execute([$shift_id]);
        $shift = $stmt->fetch();
        
        if(!$shift) {
            throw new Exception("Shift not found or already closed!");
        }
        
        // =============================================
        // FIXED: Update closing meter readings for EACH nozzle
        // =============================================
        foreach($nozzles as $nozzle) {
            $closing_meter = isset($_POST['closing_meter_' . $nozzle['id']]) ? floatval($_POST['closing_meter_' . $nozzle['id']]) : 0;
            $closing_plc = isset($_POST['closing_plc_' . $nozzle['id']]) ? floatval($_POST['closing_plc_' . $nozzle['id']]) : 0;
            
            // Update meter readings with closing values
            $stmt = $pdo->prepare("
                UPDATE meter_readings 
                SET 
                    closing_meter = ?,
                    closing_plc_count = ?,
                    shift_closed = 1
                WHERE shift_id = ? AND nozzle_id = ? AND shift_closed = 0
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$closing_meter, $closing_plc, $shift_id, $nozzle['id']]);
            
            // Update nozzle's closing meter for next shift
            $stmt = $pdo->prepare("UPDATE nozzles SET closing_meter = ? WHERE id = ?");
            $stmt->execute([$closing_meter, $nozzle['id']]);
        }
        
        // =============================================
        // Calculate ALL sales types separately
        // =============================================
        
        // 1. Liquid Fuel Sales (Diesel, Petrol, Octane, LPG)
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
        $stmt->execute([$shift['shift_id'], $shift['shift_date']]);
        $liquid_sales_by_product = $stmt->fetchAll();
        
        // 2. CNG Sales
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
        $stmt->execute([$shift['shift_id'], $shift['shift_date']]);
        $cng_sales = $stmt->fetch();
        
        // 3. Total Summary
        $total_cash_sales = 0;
        $total_credit_sales = 0;
        $total_liquid_sales = 0;
        $total_cng_sales = 0;
        $total_all_sales = 0;
        
        foreach($liquid_sales_by_product as $liquid) {
            $total_cash_sales += $liquid['cash_sales'];
            $total_credit_sales += $liquid['credit_sales'];
            $total_liquid_sales += $liquid['total_sales'];
        }
        
        if($cng_sales) {
            $total_cash_sales += $cng_sales['cash_sales'];
            $total_credit_sales += $cng_sales['credit_sales'];
            $total_cng_sales = $cng_sales['total_sales'];
        }
        
        $total_all_sales = $total_liquid_sales + $total_cng_sales;
        
        // Update shift closing with all sales data
        $stmt = $pdo->prepare("
            UPDATE shift_closing 
            SET closing_time = NOW(),
                closed_by = ?,
                total_cash_sales = ?,
                total_credit_sales = ?,
                total_gas_sales = ?,
                total_liquid_sales = ?,
                total_cng_sales = ?,
                total_all_sales = ?,
                total_receipts = ?,
                net_cash = ?,
                closing_notes = ?,
                status = 'closed',
                closed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $user['id'],
            $total_cash_sales,
            $total_credit_sales,
            $cng_sales ? $cng_sales['total_sales'] : 0,
            $total_liquid_sales,
            $total_cng_sales,
            $total_all_sales,
            $closing_cash,
            $closing_cash,
            $notes,
            $shift_id
        ]);
        
        // Update closing stock for each tank
        foreach($tanks as $tank) {
            $stmt = $pdo->prepare("
                UPDATE shift_stock 
                SET closing_stock = ? 
                WHERE shift_id = ? AND tank_id = ?
            ");
            $stmt->execute([$tank['current_stock_liters'], $shift_id, $tank['id']]);
        }
        
        $pdo->commit();
        
        // Build success message with breakdown
        $success = "✅ Shift closed successfully!<br><br>";
        $success .= "<strong>📊 SALES BREAKDOWN:</strong><br>";
        $success .= "<div class='table-responsive'><table class='table table-sm table-bordered'>";
        $success .= "<thead><tr><th>Product</th><th class='text-end'>Cash</th><th class='text-end'>Credit</th><th class='text-end'>Total</th></tr></thead><tbody>";
        
        foreach($liquid_sales_by_product as $liquid) {
            $success .= "<tr>";
            $success .= "<td>{$liquid['product_name']}</td>";
            $success .= "<td class='text-end'>" . number_format($liquid['cash_sales'], 2) . "</td>";
            $success .= "<td class='text-end'>" . number_format($liquid['credit_sales'], 2) . "</td>";
            $success .= "<td class='text-end fw-bold'>" . number_format($liquid['total_sales'], 2) . "</td>";
            $success .= "</tr>";
        }
        
        if($cng_sales && $cng_sales['total_sales'] > 0) {
            $success .= "<tr>";
            $success .= "<td>CNG</td>";
            $success .= "<td class='text-end'>" . number_format($cng_sales['cash_sales'], 2) . "</td>";
            $success .= "<td class='text-end'>" . number_format($cng_sales['credit_sales'], 2) . "</td>";
            $success .= "<td class='text-end fw-bold'>" . number_format($cng_sales['total_sales'], 2) . "</td>";
            $success .= "</tr>";
        }
        
        $success .= "<tr class='table-primary fw-bold'>";
        $success .= "<td>TOTAL</td>";
        $success .= "<td class='text-end'>" . number_format($total_cash_sales, 2) . "</td>";
        $success .= "<td class='text-end'>" . number_format($total_credit_sales, 2) . "</td>";
        $success .= "<td class='text-end'>" . number_format($total_all_sales, 2) . "</td>";
        $success .= "</tr>";
        $success .= "</tbody></table></div>";
        
        $success .= "<strong>💰 Cash in Drawer:</strong> " . number_format($closing_cash, 2);
        
        $active_shift = null;
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Get shift history with meter reading details
$shift_history = $pdo->query("
    SELECT 
        sc.*, 
        sh.shift_name, 
        u1.full_name as opened_by_name, 
        u2.full_name as closed_by_name,
        (SELECT COUNT(*) FROM meter_readings mr WHERE mr.shift_id = sc.id) as total_nozzles
    FROM shift_closing sc
    JOIN shift_schedule sh ON sc.shift_id = sh.id
    LEFT JOIN users u1 ON sc.opened_by = u1.id
    LEFT JOIN users u2 ON sc.closed_by = u2.id
    WHERE sc.status = 'closed'
    ORDER BY sc.id DESC
    LIMIT 50
")->fetchAll();

// Get meter readings for a specific shift (for viewing)
$view_shift_id = isset($_GET['view_shift']) ? intval($_GET['view_shift']) : 0;
$view_meter_readings = [];
if($view_shift_id) {
    $stmt = $pdo->prepare("
        SELECT 
            mr.*,
            n.nozzle_name,
            p.product_name,
            n.is_pipeline
        FROM meter_readings mr
        JOIN nozzles n ON mr.nozzle_id = n.id
        JOIN fuel_products p ON n.product_id = p.id
        WHERE mr.shift_id = ?
        ORDER BY n.is_pipeline DESC, n.nozzle_name
    ");
    $stmt->execute([$view_shift_id]);
    $view_meter_readings = $stmt->fetchAll();
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Closing Management</title>
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
        .shift-active {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            border-radius: 8px;
        }
        .shift-closed {
            background: #f8f9fa;
            border-left: 4px solid #6c757d;
            padding: 15px;
            border-radius: 8px;
        }
        .badge-open { background: #28a745; color: white; }
        .badge-closed { background: #6c757d; color: white; }
        .badge-verified { background: #17a2b8; color: white; }
        .product-breakdown {
            font-size: 13px;
        }
        .product-breakdown td {
            padding: 3px 8px;
        }
        .nozzle-meter-row {
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 6px;
            margin-bottom: 5px;
            border-left: 3px solid #17a2b8;
        }
        .nozzle-meter-row.pipeline {
            border-left-color: #fd7e14;
        }
        .nozzle-meter-row .nozzle-name {
            font-weight: bold;
        }
        .nozzle-meter-row .badge-pipeline {
            background: #fd7e14;
            color: white;
            font-size: 9px;
            padding: 1px 6px;
        }
        .badge-tank {
            background: #17a2b8;
            color: white;
            font-size: 9px;
            padding: 1px 6px;
        }
        .meter-input {
            width: 120px;
            display: inline-block;
        }
        .plc-input {
            width: 120px;
            display: inline-block;
        }
        .section-title {
            background: #e9ecef;
            padding: 8px 12px;
            border-radius: 6px;
            margin: 10px 0 5px 0;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-clock"></i> Shift Closing Management</h2>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Active Shift Status -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <?php if($active_shift): ?>
                        <div class="shift-active">
                            <div class="row">
                                <div class="col-md-6">
                                    <h4><i class="fas fa-play-circle text-success"></i> Shift Active</h4>
                                    <p><strong>Shift:</strong> <?php echo $active_shift['shift_name']; ?></p>
                                    <p><strong>Started:</strong> <?php echo date('d-m-Y h:i A', strtotime($active_shift['opening_time'])); ?></p>
                                    <p><strong>Opened By:</strong> <?php echo $user['full_name']; ?></p>
                                    <p><strong>Nozzles:</strong> <?php echo count($nozzles); ?> (<?php echo count($pipeline_nozzles); ?> Pipeline, <?php echo count($tank_nozzles); ?> Tank)</p>
                                </div>
                                <div class="col-md-6 text-end">
                                    <button class="btn btn-danger btn-lg" data-bs-toggle="modal" data-bs-target="#closeShiftModal">
                                        <i class="fas fa-stop-circle"></i> Close Shift
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="shift-closed">
                            <div class="row">
                                <div class="col-md-8">
                                    <h4><i class="fas fa-stop-circle text-secondary"></i> No Active Shift</h4>
                                    <p>Start a new shift to begin sales tracking. Enter nozzle meter readings and PLC counts.</p>
                                </div>
                                <div class="col-md-4 text-end">
                                    <button class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#startShiftModal">
                                        <i class="fas fa-play-circle"></i> Start Shift
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-clock"></i>
                        <h3><?php echo count($shift_history); ?></h3>
                        <p>Total Shifts</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php 
                            $total_cash = array_sum(array_column($shift_history, 'total_cash_sales'));
                            echo number_format($total_cash, 2);
                        ?></h3>
                        <p>Total Cash Sales</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-file-invoice"></i>
                        <h3><?php echo $currency; ?> <?php 
                            $total_credit = array_sum(array_column($shift_history, 'total_credit_sales'));
                            echo number_format($total_credit, 2);
                        ?></h3>
                        <p>Total Credit Sales</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-gas-pump"></i>
                        <h3><?php echo $currency; ?> <?php 
                            $total_all = array_sum(array_column($shift_history, 'total_all_sales'));
                            echo number_format($total_all, 2);
                        ?></h3>
                        <p>Total All Sales</p>
                    </div>
                </div>
            </div>
            
            <!-- Shift History -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-history"></i> Shift History</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="shiftHistoryTable">
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
                                    <th>Nozzles</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($shift_history as $shift): ?>
                                <tr>
                                    <td><?php echo date('d-m-Y', strtotime($shift['shift_date'])); ?></td>
                                    <td><?php echo $shift['shift_name']; ?></td>
                                    <td><?php echo $shift['opened_by_name']; ?></td>
                                    <td><?php echo date('h:i A', strtotime($shift['opening_time'])); ?></td>
                                    <td><?php echo $shift['closed_by_name']; ?></td>
                                    <td class="text-end text-success"><?php echo $currency; ?> <?php echo number_format($shift['total_cash_sales'], 2); ?></td>
                                    <td class="text-end text-warning"><?php echo $currency; ?> <?php echo number_format($shift['total_credit_sales'], 2); ?></td>
                                    <td class="text-end text-info"><?php echo $currency; ?> <?php echo number_format($shift['total_cng_sales'], 2); ?></td>
                                    <td class="text-end text-primary"><?php echo $currency; ?> <?php echo number_format($shift['total_liquid_sales'], 2); ?></td>
                                    <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($shift['total_all_sales'], 2); ?></td>
                                    <td><?php echo $shift['total_nozzles']; ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $shift['status']; ?>">
                                            <?php echo ucfirst($shift['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewShiftModal<?php echo $shift['id']; ?>">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="5" class="text-end">TOTAL:</td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($shift_history, 'total_cash_sales')), 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($shift_history, 'total_credit_sales')), 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($shift_history, 'total_cng_sales')), 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($shift_history, 'total_liquid_sales')), 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($shift_history, 'total_all_sales')), 2); ?></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ============================================= -->
    <!-- START SHIFT MODAL WITH NOZZLE READINGS -->
    <!-- ============================================= -->
    <div class="modal fade" id="startShiftModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5><i class="fas fa-play-circle"></i> Start New Shift</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="startShiftForm">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label><i class="fas fa-calendar"></i> Select Shift</label>
                                    <select name="shift_id" class="form-control" required>
                                        <option value="">-- Select Shift --</option>
                                        <?php foreach($shifts as $shift): ?>
                                            <option value="<?php echo $shift['id']; ?>">
                                                <?php echo $shift['shift_name']; ?> (<?php echo date('h:i A', strtotime($shift['start_time'])); ?> - <?php echo date('h:i A', strtotime($shift['end_time'])); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label><i class="fas fa-user"></i> Opened By</label>
                                    <input type="text" class="form-control" value="<?php echo $user['full_name']; ?>" disabled>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ============================================= -->
                        <!-- NOZZLE METER READINGS - OPENING -->
                        <!-- ============================================= -->
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Enter Opening Meter Readings & PLC Counts for all nozzles</strong>
                        </div>
                        
                        <!-- Pipeline Nozzles -->
                        <?php if(!empty($pipeline_nozzles)): ?>
                        <div class="section-title">
                            <i class="fas fa-pipe text-warning"></i> Pipeline Nozzles (CNG)
                            <span class="badge badge-pipeline ms-2">Pipeline</span>
                        </div>
                        <?php foreach($pipeline_nozzles as $nozzle): ?>
                        <div class="nozzle-meter-row pipeline">
                            <div class="row align-items-center">
                                <div class="col-md-3">
                                    <span class="nozzle-name"><?php echo htmlspecialchars($nozzle['nozzle_name']); ?></span>
                                    <br><small class="text-muted"><?php echo $nozzle['product_name']; ?></small>
                                </div>
                                <div class="col-md-3">
                                    <label class="small">Opening Meter (m³)</label>
                                    <input type="number" name="opening_meter_<?php echo $nozzle['id']; ?>" 
                                           class="form-control form-control-sm meter-input" 
                                           step="0.01" 
                                           value="<?php echo $nozzle['closing_meter']; ?>"
                                           required>
                                </div>
                                <div class="col-md-3">
                                    <label class="small">PLC Count</label>
                                    <input type="number" name="plc_count_<?php echo $nozzle['id']; ?>" 
                                           class="form-control form-control-sm plc-input" 
                                           step="0.01" 
                                           value="0"
                                           required>
                                </div>
                                <div class="col-md-3">
                                    <span class="badge badge-pipeline">Pipeline</span>
                                    <span class="badge bg-secondary">Current: <?php echo number_format($nozzle['closing_meter'], 2); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <!-- Tank Nozzles -->
                        <?php if(!empty($tank_nozzles)): ?>
                        <div class="section-title">
                            <i class="fas fa-oil-can text-info"></i> Tank Nozzles (Liquid Fuel)
                            <span class="badge badge-tank ms-2">Tank</span>
                        </div>
                        <?php foreach($tank_nozzles as $nozzle): ?>
                        <div class="nozzle-meter-row">
                            <div class="row align-items-center">
                                <div class="col-md-3">
                                    <span class="nozzle-name"><?php echo htmlspecialchars($nozzle['nozzle_name']); ?></span>
                                    <br><small class="text-muted"><?php echo $nozzle['product_name']; ?> (<?php echo $nozzle['tank_name']; ?>)</small>
                                </div>
                                <div class="col-md-3">
                                    <label class="small">Opening Meter (L)</label>
                                    <input type="number" name="opening_meter_<?php echo $nozzle['id']; ?>" 
                                           class="form-control form-control-sm meter-input" 
                                           step="0.01" 
                                           value="<?php echo $nozzle['closing_meter']; ?>"
                                           required>
                                </div>
                                <div class="col-md-3">
                                    <label class="small">PLC Count</label>
                                    <input type="number" name="plc_count_<?php echo $nozzle['id']; ?>" 
                                           class="form-control form-control-sm plc-input" 
                                           step="0.01" 
                                           value="0"
                                           required>
                                </div>
                                <div class="col-md-3">
                                    <span class="badge badge-tank">Tank</span>
                                    <span class="badge bg-secondary">Current: <?php echo number_format($nozzle['closing_meter'], 2); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <?php if(empty($nozzles)): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            No active nozzles found. Please add nozzles first.
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3 mt-3">
                            <label><i class="fas fa-comment"></i> Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes for shift start"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="start_shift" class="btn btn-success" <?php echo empty($nozzles) ? 'disabled' : ''; ?>>
                            <i class="fas fa-play-circle"></i> Start Shift
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ============================================= -->
    <!-- CLOSE SHIFT MODAL WITH NOZZLE READINGS -->
    <!-- ============================================= -->
    <div class="modal fade" id="closeShiftModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5><i class="fas fa-stop-circle"></i> Close Shift</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="closeShiftForm">
                    <input type="hidden" name="shift_id" value="<?php echo $active_shift['id'] ?? ''; ?>">
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Before closing shift:</strong><br>
                            1. Count cash in drawer<br>
                            2. Record closing meter readings for each nozzle<br>
                            3. Record closing PLC counts<br>
                            4. Verify all sales are recorded
                        </div>
                        
                        <!-- ============================================= -->
                        <!-- NOZZLE METER READINGS - CLOSING -->
                        <!-- ============================================= -->
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Enter Closing Meter Readings & PLC Counts for all nozzles</strong>
                            <br><small>The system will calculate the difference automatically</small>
                        </div>
                        
                        <!-- Pipeline Nozzles -->
                        <?php if(!empty($pipeline_nozzles)): ?>
                        <div class="section-title">
                            <i class="fas fa-pipe text-warning"></i> Pipeline Nozzles (CNG)
                            <span class="badge badge-pipeline ms-2">Pipeline</span>
                        </div>
                        <?php foreach($pipeline_nozzles as $nozzle): 
                            // Get opening reading for this nozzle
                            $stmt = $pdo->prepare("
                                SELECT opening_meter, plc_count as opening_plc 
                                FROM meter_readings 
                                WHERE shift_id = ? AND nozzle_id = ? AND shift_closed = 0 
                                ORDER BY id DESC LIMIT 1
                            ");
                            $stmt->execute([$active_shift['id'], $nozzle['id']]);
                            $opening = $stmt->fetch();
                            $opening_meter = $opening['opening_meter'] ?? $nozzle['closing_meter'];
                            $opening_plc = $opening['opening_plc'] ?? 0;
                        ?>
                        <div class="nozzle-meter-row pipeline">
                            <div class="row align-items-center">
                                <div class="col-md-2">
                                    <span class="nozzle-name"><?php echo htmlspecialchars($nozzle['nozzle_name']); ?></span>
                                    <br><small class="text-muted"><?php echo $nozzle['product_name']; ?></small>
                                </div>
                                <div class="col-md-2">
                                    <label class="small">Opening (m³)</label>
                                    <input type="text" class="form-control form-control-sm" 
                                           value="<?php echo number_format($opening_meter, 2); ?>" disabled>
                                </div>
                                <div class="col-md-3">
                                    <label class="small">Closing Meter (m³) *</label>
                                    <input type="number" name="closing_meter_<?php echo $nozzle['id']; ?>" 
                                           class="form-control form-control-sm meter-input closing-meter" 
                                           step="0.01" 
                                           data-opening="<?php echo $opening_meter; ?>"
                                           data-nozzle="<?php echo $nozzle['id']; ?>"
                                           required>
                                </div>
                                <div class="col-md-3">
                                    <label class="small">Closing PLC Count *</label>
                                    <input type="number" name="closing_plc_<?php echo $nozzle['id']; ?>" 
                                           class="form-control form-control-sm plc-input closing-plc" 
                                           step="0.01" 
                                           data-opening-plc="<?php echo $opening_plc; ?>"
                                           required>
                                </div>
                                <div class="col-md-2">
                                    <span class="badge bg-success" id="diff_display_<?php echo $nozzle['id']; ?>">Diff: 0.00</span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <!-- Tank Nozzles -->
                        <?php if(!empty($tank_nozzles)): ?>
                        <div class="section-title">
                            <i class="fas fa-oil-can text-info"></i> Tank Nozzles (Liquid Fuel)
                            <span class="badge badge-tank ms-2">Tank</span>
                        </div>
                        <?php foreach($tank_nozzles as $nozzle):
                            $stmt = $pdo->prepare("
                                SELECT opening_meter, plc_count as opening_plc 
                                FROM meter_readings 
                                WHERE shift_id = ? AND nozzle_id = ? AND shift_closed = 0 
                                ORDER BY id DESC LIMIT 1
                            ");
                            $stmt->execute([$active_shift['id'], $nozzle['id']]);
                            $opening = $stmt->fetch();
                            $opening_meter = $opening['opening_meter'] ?? $nozzle['closing_meter'];
                            $opening_plc = $opening['opening_plc'] ?? 0;
                        ?>
                        <div class="nozzle-meter-row">
                            <div class="row align-items-center">
                                <div class="col-md-2">
                                    <span class="nozzle-name"><?php echo htmlspecialchars($nozzle['nozzle_name']); ?></span>
                                    <br><small class="text-muted"><?php echo $nozzle['product_name']; ?></small>
                                </div>
                                <div class="col-md-2">
                                    <label class="small">Opening (L)</label>
                                    <input type="text" class="form-control form-control-sm" 
                                           value="<?php echo number_format($opening_meter, 2); ?>" disabled>
                                </div>
                                <div class="col-md-3">
                                    <label class="small">Closing Meter (L) *</label>
                                    <input type="number" name="closing_meter_<?php echo $nozzle['id']; ?>" 
                                           class="form-control form-control-sm meter-input closing-meter" 
                                           step="0.01" 
                                           data-opening="<?php echo $opening_meter; ?>"
                                           data-nozzle="<?php echo $nozzle['id']; ?>"
                                           required>
                                </div>
                                <div class="col-md-3">
                                    <label class="small">Closing PLC Count *</label>
                                    <input type="number" name="closing_plc_<?php echo $nozzle['id']; ?>" 
                                           class="form-control form-control-sm plc-input closing-plc" 
                                           step="0.01" 
                                           data-opening-plc="<?php echo $opening_plc; ?>"
                                           required>
                                </div>
                                <div class="col-md-2">
                                    <span class="badge bg-success" id="diff_display_<?php echo $nozzle['id']; ?>">Diff: 0.00</span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <hr>
                        
                        <!-- Cash Drawer -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label><i class="fas fa-money-bill"></i> Cash in Drawer (<?php echo $currency; ?>) *</label>
                                    <input type="number" name="closing_cash" class="form-control" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label><i class="fas fa-comment"></i> Closing Notes</label>
                                    <textarea name="notes" class="form-control" rows="2" placeholder="Optional closing notes"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="close_shift" class="btn btn-danger">
                            <i class="fas fa-stop-circle"></i> Close Shift
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ============================================= -->
    <!-- VIEW SHIFT METER READINGS MODAL -->
    <!-- ============================================= -->
    <?php foreach($shift_history as $shift): ?>
    <div class="modal fade" id="viewShiftModal<?php echo $shift['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5><i class="fas fa-eye"></i> Shift Details - <?php echo $shift['shift_name']; ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>Date:</strong> <?php echo date('d-m-Y', strtotime($shift['shift_date'])); ?>
                        </div>
                        <div class="col-md-6">
                            <strong>Opened:</strong> <?php echo date('h:i A', strtotime($shift['opening_time'])); ?>
                        </div>
                        <div class="col-md-6">
                            <strong>Closed:</strong> <?php echo date('h:i A', strtotime($shift['closing_time'])); ?>
                        </div>
                        <div class="col-md-6">
                            <strong>Operator:</strong> <?php echo $shift['opened_by_name']; ?>
                        </div>
                    </div>
                    
                    <?php
                    // Get meter readings for this shift
                    $stmt = $pdo->prepare("
                        SELECT 
                            mr.*,
                            n.nozzle_name,
                            p.product_name,
                            n.is_pipeline
                        FROM meter_readings mr
                        JOIN nozzles n ON mr.nozzle_id = n.id
                        JOIN fuel_products p ON n.product_id = p.id
                        WHERE mr.shift_id = ?
                        ORDER BY n.is_pipeline DESC, n.nozzle_name
                    ");
                    $stmt->execute([$shift['id']]);
                    $readings = $stmt->fetchAll();
                    ?>
                    
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead class="table-dark">
                                <tr>
                                    <th>Nozzle</th>
                                    <th>Product</th>
                                    <th class="text-end">Opening</th>
                                    <th class="text-end">Closing</th>
                                    <th class="text-end">Diff</th>
                                    <th class="text-end">Opening PLC</th>
                                    <th class="text-end">Closing PLC</th>
                                    <th class="text-end">PLC Diff</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($readings as $reading): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($reading['nozzle_name']); ?>
                                        <?php if($reading['is_pipeline']): ?>
                                            <span class="badge badge-pipeline">Pipeline</span>
                                        <?php else: ?>
                                            <span class="badge badge-tank">Tank</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $reading['product_name']; ?></td>
                                    <td class="text-end"><?php echo number_format($reading['opening_meter'], 2); ?></td>
                                    <td class="text-end"><?php echo number_format($reading['closing_meter'], 2); ?></td>
                                    <td class="text-end fw-bold"><?php echo number_format($reading['closing_meter'] - $reading['opening_meter'], 2); ?></td>
                                    <td class="text-end"><?php echo number_format($reading['opening_plc_count'], 2); ?></td>
                                    <td class="text-end"><?php echo number_format($reading['closing_plc_count'], 2); ?></td>
                                    <td class="text-end fw-bold"><?php echo number_format($reading['closing_plc_count'] - $reading['opening_plc_count'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if($shift['closing_notes']): ?>
                    <div class="alert alert-secondary">
                        <strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($shift['closing_notes'])); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#shiftHistoryTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
            
            // =============================================
            // Calculate and display difference for closing meters
            // =============================================
            $('.closing-meter').on('input', function() {
                var opening = parseFloat($(this).data('opening')) || 0;
                var closing = parseFloat($(this).val()) || 0;
                var diff = closing - opening;
                var nozzleId = $(this).data('nozzle');
                $('#diff_display_' + nozzleId).text('Diff: ' + diff.toFixed(2));
                
                // Change color based on diff
                if(diff < 0) {
                    $('#diff_display_' + nozzleId).removeClass('bg-success').addClass('bg-danger');
                } else if(diff == 0) {
                    $('#diff_display_' + nozzleId).removeClass('bg-success bg-danger').addClass('bg-warning');
                } else {
                    $('#diff_display_' + nozzleId).removeClass('bg-danger bg-warning').addClass('bg-success');
                }
            });
            
            // =============================================
            // Form validation for start shift
            // =============================================
            $('#startShiftForm').on('submit', function(e) {
                var valid = true;
                var message = '';
                
                // Check if shift is selected
                if($('select[name="shift_id"]').val() == '') {
                    valid = false;
                    message += 'Please select a shift.\n';
                }
                
                // Check if all opening meters are filled
                $('.meter-input').each(function() {
                    if($(this).val() == '') {
                        valid = false;
                        message += 'Please enter opening meter for all nozzles.\n';
                    }
                });
                
                // Check if all PLC counts are filled
                $('.plc-input').each(function() {
                    if($(this).val() == '') {
                        valid = false;
                        message += 'Please enter PLC count for all nozzles.\n';
                    }
                });
                
                if(!valid) {
                    e.preventDefault();
                    alert('⚠️ ' + message);
                    return false;
                }
                
                // Confirm start shift
                var nozzleCount = <?php echo count($nozzles); ?>;
                var confirmMsg = '⚠️ CONFIRM SHIFT START ⚠️\n\n';
                confirmMsg += '📊 Nozzles: ' + nozzleCount + '\n';
                confirmMsg += '📌 All nozzles will be recorded.\n\n';
                confirmMsg += 'Are you sure you want to start this shift?';
                
                if(!confirm(confirmMsg)) {
                    e.preventDefault();
                    return false;
                }
                
                return true;
            });
            
            // =============================================
            // Form validation for close shift
            // =============================================
            $('#closeShiftForm').on('submit', function(e) {
                var valid = true;
                var message = '';
                
                // Check if all closing meters are filled
                $('.closing-meter').each(function() {
                    if($(this).val() == '') {
                        valid = false;
                        message += 'Please enter closing meter for all nozzles.\n';
                    }
                });
                
                // Check if all closing PLC counts are filled
                $('.closing-plc').each(function() {
                    if($(this).val() == '') {
                        valid = false;
                        message += 'Please enter closing PLC count for all nozzles.\n';
                    }
                });
                
                // Check if cash is entered
                if($('input[name="closing_cash"]').val() == '') {
                    valid = false;
                    message += 'Please enter cash in drawer.\n';
                }
                
                if(!valid) {
                    e.preventDefault();
                    alert('⚠️ ' + message);
                    return false;
                }
                
                // Confirm close shift
                var confirmMsg = '⚠️ CONFIRM SHIFT CLOSE ⚠️\n\n';
                confirmMsg += '📊 Make sure all sales are recorded.\n';
                confirmMsg += '💰 Cash will be recorded.\n\n';
                confirmMsg += 'Are you sure you want to close this shift?';
                confirmMsg += '\n\nThis action cannot be undone!';
                
                if(!confirm(confirmMsg)) {
                    e.preventDefault();
                    return false;
                }
                
                return true;
            });
        });
    </script>
</body>
</html>
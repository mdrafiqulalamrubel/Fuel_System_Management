<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'meter';

// =============================================
// PROCESS METER READING (CNG)
// =============================================
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_meter_reading'])) {
    $nozzle_id = $_POST['nozzle_id'];
    $reading_date = $_POST['reading_date'];
    $opening_meter = floatval($_POST['opening_meter']);
    $closing_meter = floatval($_POST['closing_meter']);
    $shift_id = $_POST['shift_id'] ?? 0;
    $notes = $_POST['notes'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        // Check if reading already exists for this date
        $stmt = $pdo->prepare("SELECT id FROM meter_readings WHERE nozzle_id = ? AND DATE(reading_date) = ? AND shift_closed = 0");
        $stmt->execute([$nozzle_id, $reading_date]);
        if($stmt->fetch()) {
            // Update existing
            $stmt = $pdo->prepare("UPDATE meter_readings SET closing_meter = ?, reading_date = NOW(), recorded_by = ? WHERE nozzle_id = ? AND DATE(reading_date) = ? AND shift_closed = 0");
            $stmt->execute([$closing_meter, $user['id'], $nozzle_id, $reading_date]);
            $success = "✅ Meter reading updated successfully!";
        } else {
            // Insert new
            $stmt = $pdo->prepare("INSERT INTO meter_readings (nozzle_id, reading_date, shift_id, opening_meter, closing_meter, recorded_by, shift_closed) VALUES (?, NOW(), ?, ?, ?, ?, 0)");
            $stmt->execute([$nozzle_id, $shift_id, $opening_meter, $closing_meter, $user['id']]);
            $success = "✅ Meter reading recorded successfully!";
        }
        
        // Update nozzle closing meter
        $stmt = $pdo->prepare("UPDATE nozzles SET closing_meter = ? WHERE id = ?");
        $stmt->execute([$closing_meter, $nozzle_id]);
        
        $pdo->commit();
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// =============================================
// PROCESS TANK STOCK READING (LIQUID FUELS)
// =============================================
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_tank_reading'])) {
    $tank_id = $_POST['tank_id'];
    $reading_date = $_POST['reading_date'];
    $dip_reading = floatval($_POST['dip_reading']);
    $physical_stock = floatval($_POST['physical_stock']);
    $shift_id = $_POST['shift_id'] ?? 0;
    $notes = $_POST['notes'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        // Get tank details
        $stmt = $pdo->prepare("SELECT * FROM tanks WHERE id = ?");
        $stmt->execute([$tank_id]);
        $tank = $stmt->fetch();
        
        if(!$tank) {
            throw new Exception("Tank not found!");
        }
        
        // Check if reading already exists for this date
        $stmt = $pdo->prepare("SELECT id FROM tank_stock_readings WHERE tank_id = ? AND DATE(reading_date) = ?");
        $stmt->execute([$tank_id, $reading_date]);
        if($stmt->fetch()) {
            // Update existing
            $stmt = $pdo->prepare("UPDATE tank_stock_readings SET dip_reading = ?, physical_stock = ?, reading_date = NOW(), recorded_by = ? WHERE tank_id = ? AND DATE(reading_date) = ?");
            $stmt->execute([$dip_reading, $physical_stock, $user['id'], $tank_id, $reading_date]);
            $success = "✅ Tank stock reading updated successfully!";
        } else {
            // Insert new
            $stmt = $pdo->prepare("INSERT INTO tank_stock_readings (tank_id, reading_date, shift_id, dip_reading, physical_stock, system_stock, recorded_by) VALUES (?, NOW(), ?, ?, ?, ?, ?)");
            $stmt->execute([$tank_id, $shift_id, $dip_reading, $physical_stock, $tank['current_stock_liters'], $user['id']]);
            $success = "✅ Tank stock reading recorded successfully!";
        }
        
        // Update tank current stock
        $stmt = $pdo->prepare("UPDATE tanks SET current_stock_liters = ? WHERE id = ?");
        $stmt->execute([$physical_stock, $tank_id]);
        
        $pdo->commit();
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// =============================================
// GET CNG NOZZLES (PIPELINE NOZZLES)
// =============================================
$cng_nozzles = $pdo->query("
    SELECT n.*, p.product_name 
    FROM nozzles n 
    JOIN fuel_products p ON n.product_id = p.id 
    WHERE n.is_active = 1 
    AND n.is_pipeline = 1
    AND p.product_name IN ('CNG', 'Natural Gas')
    ORDER BY n.nozzle_name
")->fetchAll();

// =============================================
// GET LIQUID TANKS
// =============================================
$liquid_tanks = $pdo->query("
    SELECT t.*, p.product_name 
    FROM tanks t 
    JOIN fuel_products p ON t.product_id = p.id 
    WHERE t.is_active = 1 
    AND p.product_name NOT IN ('CNG', 'Natural Gas')
    ORDER BY t.tank_name
")->fetchAll();

// =============================================
// GET METER READINGS HISTORY (CNG)
// =============================================
$readings = $pdo->query("
    SELECT mr.*, n.nozzle_name, p.product_name, u.full_name as recorder
    FROM meter_readings mr
    JOIN nozzles n ON mr.nozzle_id = n.id
    JOIN fuel_products p ON n.product_id = p.id
    LEFT JOIN users u ON mr.recorded_by = u.id
    WHERE n.is_pipeline = 1
    ORDER BY mr.reading_date DESC
    LIMIT 50
")->fetchAll();

// =============================================
// GET TANK STOCK READINGS HISTORY (LIQUID)
// =============================================
$tank_readings = $pdo->query("
    SELECT tsr.*, t.tank_name, p.product_name, u.full_name as recorder
    FROM tank_stock_readings tsr
    JOIN tanks t ON tsr.tank_id = t.id
    JOIN fuel_products p ON t.product_id = p.id
    LEFT JOIN users u ON tsr.recorded_by = u.id
    ORDER BY tsr.reading_date DESC
    LIMIT 50
")->fetchAll();

// =============================================
// GET SHIFTS
// =============================================
$shifts = $pdo->query("SELECT * FROM shift_schedule WHERE is_active = 1")->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';

// Calculate statistics
$total_readings = count($readings);
$total_tank_readings = count($tank_readings);
$total_cng_nozzles = count($cng_nozzles);
$total_tanks = count($liquid_tanks);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meter & Tank Reading Management</title>
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
        .meter-display {
            font-size: 20px;
            font-weight: bold;
            font-family: 'Courier New', monospace;
        }
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
        .nav-tabs-custom .nav-item {
            margin-bottom: -2px;
        }
        .nav-tabs-custom .nav-link {
            color: #000000 !important;
            font-weight: 600;
            padding: 12px 25px;
            border: none;
            border-radius: 8px 8px 0 0;
            background: transparent;
            font-size: 15px;
        }
        .nav-tabs-custom .nav-link i {
            margin-right: 8px;
            color: #495057;
        }
        .nav-tabs-custom .nav-link:hover {
            color: #0d6efd !important;
            background: rgba(13, 110, 253, 0.1);
        }
        .nav-tabs-custom .nav-link.active {
            color: #0d6efd !important;
            background: #ffffff;
            border-bottom: 3px solid #0d6efd;
            font-weight: 700;
        }
        
        /* ============================================= */
        /* FIX: Tab content - fixed height to prevent jumping */
        /* ============================================= */
        .tab-content-custom {
            background: white;
            padding: 20px;
            border-radius: 0 0 8px 8px;
            border: 1px solid #dee2e6;
            border-top: none;
            min-height: 650px;
            position: relative;
        }
        
        .tab-content-custom .tab-pane {
            position: relative;
            height: auto;
            min-height: 580px;
        }
        
        .tab-content-custom .tab-pane.fade {
            opacity: 0;
            transition: opacity 0.15s linear;
            display: none;
        }
        
        .tab-content-custom .tab-pane.fade.show {
            opacity: 1;
            display: block;
        }
        
        .tab-content-custom .tab-pane .row {
            height: auto;
        }
        
        .tab-content-custom .card {
            height: auto;
            min-height: 200px;
        }
        
        .tab-content-custom .table-responsive {
            min-height: 150px;
        }
        
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #0d6efd;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .info-box i { color: #0d6efd; }
        .tank-info-box {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .tank-info-box i { color: #28a745; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-tachometer-alt"></i> Meter & Tank Reading Management</h2>
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
            
            <!-- Statistics -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-gas-pump"></i>
                        <h3><?php echo $total_cng_nozzles; ?></h3>
                        <p>CNG Nozzles</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-tachometer-alt"></i>
                        <h3><?php echo $total_readings; ?></h3>
                        <p>CNG Meter Readings</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-warehouse"></i>
                        <h3><?php echo $total_tanks; ?></h3>
                        <p>Liquid Fuel Tanks</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-clipboard-list"></i>
                        <h3><?php echo $total_tank_readings; ?></h3>
                        <p>Tank Stock Readings</p>
                    </div>
                </div>
            </div>
            
            <!-- Tabs -->
            <ul class="nav nav-tabs-custom" id="settingsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $active_tab == 'meter' ? 'active' : ''; ?>" 
                            data-bs-toggle="tab" data-bs-target="#meterTab" type="button" role="tab">
                        <i class="fas fa-gas-pump"></i> CNG Meter Reading
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $active_tab == 'tank' ? 'active' : ''; ?>" 
                            data-bs-toggle="tab" data-bs-target="#tankTab" type="button" role="tab">
                        <i class="fas fa-warehouse"></i> Tank Stock Reading
                    </button>
                </li>
            </ul>
            
            <!-- ============================================= -->
            <!-- FIXED: Tab Content with min-height to prevent jumping -->
            <!-- ============================================= -->
            <div class="tab-content-custom">
                <!-- ==================== CNG METER READING TAB ==================== -->
                <div class="tab-pane fade <?php echo $active_tab == 'meter' ? 'show active' : ''; ?>" id="meterTab" role="tabpanel">
                    
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>CNG Meter Reading:</strong> Record daily meter readings for CNG pipeline nozzles. 
                        This helps track CNG consumption and sales.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-5">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h5><i class="fas fa-plus-circle"></i> Record CNG Meter Reading</h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="reading_type" value="meter">
                                        <div class="mb-3">
                                            <label><i class="fas fa-oil-can"></i> Select CNG Nozzle</label>
                                            <select name="nozzle_id" id="nozzle_id" class="form-control" required>
                                                <option value="">-- Select Nozzle --</option>
                                                <?php foreach($cng_nozzles as $nozzle): ?>
                                                    <option value="<?php echo $nozzle['id']; ?>" 
                                                            data-closing="<?php echo $nozzle['closing_meter']; ?>"
                                                            data-nozzle-name="<?php echo $nozzle['nozzle_name']; ?>">
                                                        <?php echo $nozzle['nozzle_name']; ?> - <?php echo $nozzle['product_name']; ?>
                                                        (Current: <?php echo number_format($nozzle['closing_meter'], 2); ?> m³)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label><i class="fas fa-calendar-alt"></i> Reading Date</label>
                                            <input type="date" name="reading_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label>Opening Meter (m³)</label>
                                                    <input type="number" name="opening_meter" id="opening_meter" class="form-control meter-display" step="0.01" readonly>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label>Closing Meter (m³)</label>
                                                    <input type="number" name="closing_meter" id="closing_meter" class="form-control meter-display" step="0.01" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label><i class="fas fa-clock"></i> Shift</label>
                                            <select name="shift_id" class="form-control">
                                                <option value="">-- Select Shift --</option>
                                                <?php foreach($shifts as $shift): ?>
                                                    <option value="<?php echo $shift['id']; ?>">
                                                        <?php echo $shift['shift_name']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label><i class="fas fa-comment"></i> Notes</label>
                                            <textarea name="notes" class="form-control" rows="2" placeholder="Any notes about this reading..."></textarea>
                                        </div>
                                        <button type="submit" name="save_meter_reading" class="btn btn-primary w-100">
                                            <i class="fas fa-save"></i> Record Reading
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-7">
                            <div class="card">
                                <div class="card-header bg-success text-white">
                                    <h5><i class="fas fa-history"></i> CNG Meter Reading History</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="readingsTable">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Nozzle</th>
                                                    <th>Shift</th>
                                                    <th class="text-end">Opening</th>
                                                    <th class="text-end">Closing</th>
                                                    <th class="text-end">Dispensed</th>
                                                    <th>Recorded By</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($readings as $reading): 
                                                    $dispensed = $reading['closing_meter'] - $reading['opening_meter'];
                                                ?>
                                                <tr>
                                                    <td><?php echo date('d-m-Y H:i', strtotime($reading['reading_date'])); ?></td>
                                                    <td><strong><?php echo $reading['nozzle_name']; ?></strong></td>
                                                    <td>
                                                        <?php 
                                                        $shift_name = '';
                                                        foreach($shifts as $s) {
                                                            if($s['id'] == $reading['shift_id']) {
                                                                $shift_name = $s['shift_name'];
                                                                break;
                                                            }
                                                        }
                                                        echo $shift_name ?: '-';
                                                        ?>
                                                    </td>
                                                    <td class="text-end"><?php echo number_format($reading['opening_meter'], 2); ?> m³</td>
                                                    <td class="text-end"><?php echo number_format($reading['closing_meter'], 2); ?> m³</td>
                                                    <td class="text-end fw-bold text-primary"><?php echo number_format($dispensed, 2); ?> m³</td>
                                                    <td><?php echo $reading['recorder']; ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <?php if(empty($readings)): ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted">No meter readings found</td>
                                                </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ==================== TANK STOCK READING TAB ==================== -->
                <div class="tab-pane fade <?php echo $active_tab == 'tank' ? 'show active' : ''; ?>" id="tankTab" role="tabpanel">
                    
                    <div class="tank-info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>Liquid Fuel Tank Stock Reading:</strong> Record daily dip stick readings and physical stock 
                        for Diesel, Petrol, Octane, and LPG tanks. This helps track inventory and identify leakages.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-5">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h5><i class="fas fa-plus-circle"></i> Record Tank Stock Reading</h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="reading_type" value="tank">
                                        <div class="mb-3">
                                            <label><i class="fas fa-warehouse"></i> Select Tank</label>
                                            <select name="tank_id" id="tank_id" class="form-control" required>
                                                <option value="">-- Select Tank --</option>
                                                <?php foreach($liquid_tanks as $tank): ?>
                                                    <option value="<?php echo $tank['id']; ?>" 
                                                            data-stock="<?php echo $tank['current_stock_liters']; ?>"
                                                            data-calibration="<?php echo $tank['calibration_factor']; ?>"
                                                            data-capacity="<?php echo $tank['capacity_liters']; ?>"
                                                            data-tank-name="<?php echo $tank['tank_name']; ?>">
                                                        <?php echo $tank['tank_name']; ?> - <?php echo $tank['product_name']; ?>
                                                        (Current: <?php echo number_format($tank['current_stock_liters'], 2); ?> L)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Tank Info Display -->
                                        <div id="tankInfo" class="alert alert-info" style="display:none;">
                                            <div class="row">
                                                <div class="col-6"><strong>System Stock:</strong> <span id="display_system_stock">0</span> L</div>
                                                <div class="col-6"><strong>Calibration:</strong> <span id="display_calibration">0</span> L/cm</div>
                                                <div class="col-6 mt-1"><strong>Capacity:</strong> <span id="display_capacity">0</span> L</div>
                                                <div class="col-6 mt-1"><strong>Fill Level:</strong> <span id="display_fill_level">0</span>%</div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label><i class="fas fa-calendar-alt"></i> Reading Date</label>
                                            <input type="date" name="reading_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label>Dip Stick Reading (cm)</label>
                                                    <input type="number" name="dip_reading" id="dip_reading" class="form-control" step="0.01" required>
                                                    <small class="text-muted">Measured dip stick reading</small>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label>Physical Stock (Liters)</label>
                                                    <input type="number" name="physical_stock" id="physical_stock" class="form-control meter-display" step="0.01" readonly>
                                                    <small class="text-muted">Auto-calculated from dip reading</small>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label><i class="fas fa-clock"></i> Shift</label>
                                            <select name="shift_id" class="form-control">
                                                <option value="">-- Select Shift --</option>
                                                <?php foreach($shifts as $shift): ?>
                                                    <option value="<?php echo $shift['id']; ?>">
                                                        <?php echo $shift['shift_name']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label><i class="fas fa-comment"></i> Notes</label>
                                            <textarea name="notes" class="form-control" rows="2" placeholder="Any notes about this reading..."></textarea>
                                        </div>
                                        <button type="submit" name="save_tank_reading" class="btn btn-primary w-100">
                                            <i class="fas fa-save"></i> Record Reading
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-7">
                            <div class="card">
                                <div class="card-header bg-success text-white">
                                    <h5><i class="fas fa-history"></i> Tank Stock Reading History</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="tankReadingsTable">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Tank</th>
                                                    <th>Product</th>
                                                    <th>Shift</th>
                                                    <th class="text-end">Dip (cm)</th>
                                                    <th class="text-end">Physical (L)</th>
                                                    <th class="text-end">System (L)</th>
                                                    <th class="text-end">Variance</th>
                                                    <th>Recorded By</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($tank_readings as $reading): 
                                                    $variance = $reading['system_stock'] - $reading['physical_stock'];
                                                    $variance_class = $variance > 0 ? 'text-danger' : ($variance < 0 ? 'text-success' : 'text-muted');
                                                ?>
                                                <tr>
                                                    <td><?php echo date('d-m-Y H:i', strtotime($reading['reading_date'])); ?></td>
                                                    <td><strong><?php echo $reading['tank_name']; ?></strong></td>
                                                    <td>
                                                        <span class="badge <?php echo $reading['product_name'] == 'LPG' ? 'badge-lpg' : 'badge-liquid'; ?>">
                                                            <?php echo $reading['product_name']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $shift_name = '';
                                                        foreach($shifts as $s) {
                                                            if($s['id'] == $reading['shift_id']) {
                                                                $shift_name = $s['shift_name'];
                                                                break;
                                                            }
                                                        }
                                                        echo $shift_name ?: '-';
                                                        ?>
                                                    </td>
                                                    <td class="text-end"><?php echo number_format($reading['dip_reading'], 2); ?></td>
                                                    <td class="text-end fw-bold"><?php echo number_format($reading['physical_stock'], 2); ?></td>
                                                    <td class="text-end"><?php echo number_format($reading['system_stock'], 2); ?></td>
                                                    <td class="text-end <?php echo $variance_class; ?>">
                                                        <?php echo ($variance > 0 ? '-' : ($variance < 0 ? '+' : '')) . number_format(abs($variance), 2); ?>
                                                    </td>
                                                    <td><?php echo $reading['recorder']; ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <?php if(empty($tank_readings)): ?>
                                                <tr>
                                                    <td colspan="9" class="text-center text-muted">No tank readings found</td>
                                                </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
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
            $('#readingsTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
            
            $('#tankReadingsTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
        
        // =============================================
        // CNG METER READING - Auto fill opening meter
        // =============================================
        document.getElementById('nozzle_id').addEventListener('change', function() {
            let option = this.options[this.selectedIndex];
            let closing = parseFloat(option.getAttribute('data-closing')) || 0;
            document.getElementById('opening_meter').value = closing.toFixed(2);
        });
        
        document.getElementById('closing_meter').addEventListener('input', function() {
            let opening = parseFloat(document.getElementById('opening_meter').value) || 0;
            let closing = parseFloat(this.value) || 0;
            let dispensed = closing - opening;
            if(dispensed < 0) {
                this.style.borderColor = 'red';
            } else {
                this.style.borderColor = '';
            }
        });
        
        // =============================================
        // TANK STOCK READING - Auto calculate from dip
        // =============================================
        document.getElementById('tank_id').addEventListener('change', function() {
            let option = this.options[this.selectedIndex];
            if(!option.value) {
                document.getElementById('tankInfo').style.display = 'none';
                return;
            }
            
            let stock = parseFloat(option.getAttribute('data-stock')) || 0;
            let calibration = parseFloat(option.getAttribute('data-calibration')) || 1;
            let capacity = parseFloat(option.getAttribute('data-capacity')) || 0;
            let fillLevel = capacity > 0 ? (stock / capacity) * 100 : 0;
            
            document.getElementById('tankInfo').style.display = 'block';
            document.getElementById('display_system_stock').innerHTML = stock.toFixed(2);
            document.getElementById('display_calibration').innerHTML = calibration.toFixed(4);
            document.getElementById('display_capacity').innerHTML = capacity.toFixed(0);
            document.getElementById('display_fill_level').innerHTML = fillLevel.toFixed(1);
            
            // Calculate expected dip reading
            let expectedDip = calibration > 0 ? stock / calibration : 0;
            document.getElementById('dip_reading').placeholder = 'Expected: ' + expectedDip.toFixed(2) + ' cm';
        });
        
        document.getElementById('dip_reading').addEventListener('input', function() {
            let tankSelect = document.getElementById('tank_id');
            if(tankSelect.selectedIndex > 0) {
                let option = tankSelect.options[tankSelect.selectedIndex];
                let calibration = parseFloat(option.getAttribute('data-calibration')) || 1;
                let dipReading = parseFloat(this.value) || 0;
                let physicalStock = dipReading * calibration;
                document.getElementById('physical_stock').value = physicalStock.toFixed(2);
            }
        });
    </script>
</body>
</html>
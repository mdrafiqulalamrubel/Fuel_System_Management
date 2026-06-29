<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// =============================================
// PROCESS METER READING (CNG & LIQUID FUEL)
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
        
        $stmt = $pdo->prepare("SELECT id FROM meter_readings WHERE nozzle_id = ? AND DATE(reading_date) = ? AND shift_closed = 0");
        $stmt->execute([$nozzle_id, $reading_date]);
        if($stmt->fetch()) {
            $stmt = $pdo->prepare("UPDATE meter_readings SET closing_meter = ?, reading_date = NOW(), recorded_by = ? WHERE nozzle_id = ? AND DATE(reading_date) = ? AND shift_closed = 0");
            $stmt->execute([$closing_meter, $user['id'], $nozzle_id, $reading_date]);
            $success = "✅ Meter reading updated successfully!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO meter_readings (nozzle_id, reading_date, shift_id, opening_meter, closing_meter, recorded_by, shift_closed) VALUES (?, NOW(), ?, ?, ?, ?, 0)");
            $stmt->execute([$nozzle_id, $shift_id, $opening_meter, $closing_meter, $user['id']]);
            $success = "✅ Meter reading recorded successfully!";
        }
        
        $stmt = $pdo->prepare("UPDATE nozzles SET closing_meter = ? WHERE id = ?");
        $stmt->execute([$closing_meter, $nozzle_id]);
        
        $pdo->commit();
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// =============================================
// GET ALL NOZZLES
// =============================================
$all_nozzles = $pdo->query("
    SELECT 
        n.*, 
        t.tank_name,
        p.product_name,
        CASE WHEN n.is_pipeline = 1 THEN 'Pipeline' ELSE 'Tank' END as source_type
    FROM nozzles n 
    JOIN fuel_products p ON n.product_id = p.id 
    LEFT JOIN tanks t ON n.tank_id = t.id
    WHERE n.is_active = 1 
    ORDER BY n.is_pipeline DESC, n.nozzle_name
")->fetchAll();

$cng_nozzles = array_filter($all_nozzles, function($n) { 
    return $n['is_pipeline'] == 1 && in_array($n['product_name'], ['CNG', 'Natural Gas']);
});

$liquid_nozzles = array_filter($all_nozzles, function($n) { 
    return $n['is_pipeline'] == 0;
});

$readings = $pdo->query("
    SELECT mr.*, n.nozzle_name, p.product_name, u.full_name as recorder, n.is_pipeline
    FROM meter_readings mr
    JOIN nozzles n ON mr.nozzle_id = n.id
    JOIN fuel_products p ON n.product_id = p.id
    LEFT JOIN users u ON mr.recorded_by = u.id
    ORDER BY mr.reading_date DESC
    LIMIT 50
")->fetchAll();

$shifts = $pdo->query("SELECT * FROM shift_schedule WHERE is_active = 1")->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';

$total_readings = count($readings);
$total_cng_nozzles = count($cng_nozzles);
$total_liquid_nozzles = count($liquid_nozzles);
$total_nozzles = count($all_nozzles);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meter Reading Management</title>
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
        .badge-pipeline { background: #fd7e14; color: white; }
        .badge-tank { background: #0d6efd; color: white; }
        
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #0d6efd;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .info-box i { color: #0d6efd; }
        
        /* ============================================= */
        /* PRINT STYLES - PLAIN PAPER, NO BACKGROUND */
        /* ============================================= */
        .print-header { display: none; }
        .print-table-container { display: none; }
        
        @media print {
            /* Hide non-print elements */
            .no-print, .sidebar, .btn, .stats-card, .card-header .btn,
            .dataTables_length, .dataTables_filter, .dataTables_paginate,
            .dataTables_info, form, .info-box, .card-body .alert,
            .nav-tabs, .nav-tabs-custom, .tab-content-custom .no-print,
            #readingsTable_wrapper, .dataTables_wrapper {
                display: none !important;
            }
            
            /* Show print elements */
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
            
            /* Show print table */
            .print-table-container {
                display: block !important;
                margin-top: 15px;
            }
            
            .print-table-container table {
                border-collapse: collapse !important;
                width: 100% !important;
                font-size: 10px !important;
            }
            
            .print-table-container table th,
            .print-table-container table td {
                border: 1px solid #000 !important;
                padding: 3px 5px !important;
                text-align: left !important;
                background: #fff !important;
                color: #000 !important;
            }
            
            .print-table-container table th {
                background: #f8f9fa !important;
                font-weight: bold !important;
                border-bottom: 2px solid #000 !important;
            }
            
            .print-table-container table td.text-end {
                text-align: right !important;
            }
            
            .print-table-container table .text-center {
                text-align: center !important;
            }
            
            /* Remove all backgrounds */
            .bg-primary, .bg-success, .bg-info, .bg-warning, .bg-danger,
            .bg-secondary, .bg-light, .bg-white, .card-header, .table-dark,
            .table-info, .table-secondary, .stats-card, .card, .badge, .alert {
                background: #fff !important;
                color: #000 !important;
                border-color: #000 !important;
            }
            
            .text-white, .text-white-50 { color: #000 !important; }
            .text-success, .text-danger, .text-warning, .text-info, .text-primary {
                color: #000 !important;
            }
            
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
            
            .badge {
                border: 1px solid #000 !important;
                background: #fff !important;
                color: #000 !important;
                padding: 1px 4px !important;
                font-size: 8px !important;
                border-radius: 2px !important;
            }
            
            .footer-note {
                border-top: 1px solid #000 !important;
                margin-top: 8px !important;
                padding-top: 4px !important;
                font-size: 8px !important;
                text-align: center !important;
                color: #000 !important;
                display: block !important;
            }
            
            @page {
                size: landscape;
                margin: 8mm 6mm;
            }
            
            ::-webkit-scrollbar { display: none; }
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
                <h2><?php echo htmlspecialchars($settings['company_name'] ?? 'FF Enterprise'); ?></h2>
                <h4>Meter Reading Report</h4>
                <p>All Nozzle Meter Readings</p>
                <p class="print-date">Generated: <?php echo date('d/m/Y h:i:s A'); ?></p>
                <p class="print-date">Total Readings: <?php echo $total_readings; ?> | Total Nozzles: <?php echo $total_nozzles; ?></p>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4 no-print">
                <h2><i class="fas fa-tachometer-alt"></i> Meter Reading Management</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger alert-dismissible fade show no-print">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success alert-dismissible fade show no-print">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Statistics -->
            <div class="row no-print">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-gas-pump"></i>
                        <h3><?php echo $total_nozzles; ?></h3>
                        <p>Total Nozzles</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-tachometer-alt"></i>
                        <h3><?php echo $total_readings; ?></h3>
                        <p>Total Meter Readings</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #fd7e14 0%, #ffc107 100%);">
                        <i class="fas fa-pipe"></i>
                        <h3><?php echo $total_cng_nozzles; ?></h3>
                        <p>CNG Nozzles (Pipeline)</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #0d6efd 0%, #4facfe 100%);">
                        <i class="fas fa-oil-can"></i>
                        <h3><?php echo $total_liquid_nozzles; ?></h3>
                        <p>Liquid Nozzles (Tank)</p>
                    </div>
                </div>
            </div>
            
            <!-- Meter Reading Form -->
            <div class="row no-print">
                <div class="col-md-5">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-plus-circle"></i> Record Meter Reading</h5>
                        </div>
                        <div class="card-body">
                            <div class="info-box">
                                <i class="fas fa-info-circle"></i>
                                <strong>Meter Readings:</strong> Record daily meter readings for all nozzles.
                            </div>
                            <form method="POST">
                                <input type="hidden" name="reading_type" value="meter">
                                <div class="mb-3">
                                    <label><i class="fas fa-oil-can"></i> Select Nozzle</label>
                                    <select name="nozzle_id" id="nozzle_id" class="form-control" required>
                                        <option value="">-- Select Nozzle --</option>
                                        <?php if(!empty($cng_nozzles)): ?>
                                        <optgroup label="CNG Pipeline Nozzles">
                                            <?php foreach($cng_nozzles as $nozzle): ?>
                                                <option value="<?php echo $nozzle['id']; ?>" 
                                                        data-closing="<?php echo $nozzle['closing_meter']; ?>"
                                                        data-unit="m³"
                                                        data-nozzle-name="<?php echo $nozzle['nozzle_name']; ?>"
                                                        data-type="pipeline">
                                                    <?php echo $nozzle['nozzle_name']; ?> - <?php echo $nozzle['product_name']; ?>
                                                    (Current: <?php echo number_format($nozzle['closing_meter'], 2); ?> m³)
                                                    <span class="badge badge-pipeline">Pipeline</span>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                        <?php endif; ?>
                                        <?php if(!empty($liquid_nozzles)): ?>
                                        <optgroup label="Liquid Fuel Nozzles (Tank)">
                                            <?php foreach($liquid_nozzles as $nozzle): ?>
                                                <option value="<?php echo $nozzle['id']; ?>" 
                                                        data-closing="<?php echo $nozzle['closing_meter']; ?>"
                                                        data-unit="L"
                                                        data-nozzle-name="<?php echo $nozzle['nozzle_name']; ?>"
                                                        data-type="tank">
                                                    <?php echo $nozzle['nozzle_name']; ?> - <?php echo $nozzle['product_name']; ?>
                                                    (Current: <?php echo number_format($nozzle['closing_meter'], 2); ?> L)
                                                    <span class="badge badge-tank">Tank</span>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label><i class="fas fa-calendar-alt"></i> Reading Date</label>
                                    <input type="date" name="reading_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Opening Meter (<span id="unit_label">m³</span>)</label>
                                            <input type="number" name="opening_meter" id="opening_meter" class="form-control meter-display" step="0.01" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Closing Meter (<span id="unit_label_close">m³</span>)</label>
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
                                    <textarea name="notes" class="form-control" rows="2" placeholder="Any notes..."></textarea>
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
                            <h5><i class="fas fa-history"></i> Meter Reading History</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="readingsTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Date</th>
                                            <th>Nozzle</th>
                                            <th>Product</th>
                                            <th>Type</th>
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
                                            $unit = $reading['is_pipeline'] ? 'm³' : 'L';
                                        ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y H:i', strtotime($reading['reading_date'])); ?></td>
                                            <td><strong><?php echo $reading['nozzle_name']; ?></strong></td>
                                            <td><?php echo $reading['product_name']; ?></td>
                                            <td>
                                                <?php if($reading['is_pipeline']): ?>
                                                    <span class="badge badge-pipeline">Pipeline</span>
                                                <?php else: ?>
                                                    <span class="badge badge-tank">Tank</span>
                                                <?php endif; ?>
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
                                            <td class="text-end"><?php echo number_format($reading['opening_meter'], 2); ?> <?php echo $unit; ?></td>
                                            <td class="text-end"><?php echo number_format($reading['closing_meter'], 2); ?> <?php echo $unit; ?></td>
                                            <td class="text-end fw-bold text-primary"><?php echo number_format($dispensed, 2); ?> <?php echo $unit; ?></td>
                                            <td><?php echo $reading['recorder']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if(empty($readings)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted">No meter readings found</td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ============================================= -->
            <!-- PRINT TABLE (hidden on screen, visible in print) -->
            <!-- ============================================= -->
            <div class="print-table-container">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Nozzle</th>
                            <th>Product</th>
                            <th>Type</th>
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
                            $unit = $reading['is_pipeline'] ? 'm³' : 'L';
                            $type_label = $reading['is_pipeline'] ? 'Pipeline' : 'Tank';
                            $shift_name = '';
                            foreach($shifts as $s) {
                                if($s['id'] == $reading['shift_id']) {
                                    $shift_name = $s['shift_name'];
                                    break;
                                }
                            }
                            $shift_name = $shift_name ?: '-';
                        ?>
                        <tr>
                            <td><?php echo date('d-m-Y H:i', strtotime($reading['reading_date'])); ?></td>
                            <td><?php echo $reading['nozzle_name']; ?></td>
                            <td><?php echo $reading['product_name']; ?></td>
                            <td><?php echo $type_label; ?></td>
                            <td><?php echo $shift_name; ?></td>
                            <td class="text-end"><?php echo number_format($reading['opening_meter'], 2); ?> <?php echo $unit; ?></td>
                            <td class="text-end"><?php echo number_format($reading['closing_meter'], 2); ?> <?php echo $unit; ?></td>
                            <td class="text-end"><?php echo number_format($dispensed, 2); ?> <?php echo $unit; ?></td>
                            <td><?php echo $reading['recorder']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($readings)): ?>
                        <tr>
                            <td colspan="9" class="text-center">No meter readings found</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- ============================================= -->
            <!-- FOOTER -->
            <!-- ============================================= -->
            <div class="footer-note" style="margin-top:15px; padding-top:10px; border-top:1px solid #ddd; text-align:center; color:#6c757d; font-size:12px;">
                <i class="fas fa-info-circle"></i>
                <strong>Report Summary:</strong> 
                Total Readings: <?php echo $total_readings; ?> | 
                Total Nozzles: <?php echo $total_nozzles; ?> | 
                CNG: <?php echo $total_cng_nozzles; ?> | 
                Liquid: <?php echo $total_liquid_nozzles; ?>
                <br><small class="no-print">Click <strong>Print Report</strong> for plain paper print.</small>
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
        });
        
        document.getElementById('nozzle_id').addEventListener('change', function() {
            let option = this.options[this.selectedIndex];
            let closing = parseFloat(option.getAttribute('data-closing')) || 0;
            let unit = option.getAttribute('data-unit') || 'm³';
            document.getElementById('opening_meter').value = closing.toFixed(2);
            document.getElementById('unit_label').innerText = unit;
            document.getElementById('unit_label_close').innerText = unit;
        });
        
        document.getElementById('closing_meter').addEventListener('input', function() {
            let opening = parseFloat(document.getElementById('opening_meter').value) || 0;
            let closing = parseFloat(this.value) || 0;
            if((closing - opening) < 0) {
                this.style.borderColor = 'red';
            } else {
                this.style.borderColor = '';
            }
        });
    </script>
</body>
</html>
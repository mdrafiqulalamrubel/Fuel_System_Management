<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Process meter reading
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

// Get nozzles
$nozzles = $pdo->query("
    SELECT n.*, t.tank_name, p.product_name 
    FROM nozzles n 
    JOIN tanks t ON n.tank_id = t.id 
    JOIN fuel_products p ON t.product_id = p.id 
    WHERE n.is_active = 1 
    ORDER BY n.nozzle_name
")->fetchAll();

// Get meter readings history
$readings = $pdo->query("
    SELECT mr.*, n.nozzle_name, t.tank_name, p.product_name, u.full_name as recorder
    FROM meter_readings mr
    JOIN nozzles n ON mr.nozzle_id = n.id
    JOIN tanks t ON n.tank_id = t.id
    JOIN fuel_products p ON t.product_id = p.id
    LEFT JOIN users u ON mr.recorded_by = u.id
    ORDER BY mr.reading_date DESC
    LIMIT 50
")->fetchAll();

// Get shifts
$shifts = $pdo->query("SELECT * FROM shift_schedule WHERE is_active = 1")->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
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
        .badge-gas { background: #17a2b8; color: white; }
        .badge-liquid { background: #28a745; color: white; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-tachometer-alt"></i> Meter Reading Management</h2>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <!-- Statistics -->
            <div class="row">
                <div class="col-md-4">
                    <div class="stats-card">
                        <i class="fas fa-oil-can"></i>
                        <h3><?php echo count($nozzles); ?></h3>
                        <p>Total Nozzles</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-tachometer-alt"></i>
                        <h3><?php echo count($readings); ?></h3>
                        <p>Total Readings Recorded</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php 
                            $total_quantity = array_sum(array_column($readings, 'sale_quantity'));
                            echo number_format($total_quantity, 2) . ' L';
                        ?></h3>
                        <p>Total Fuel Dispensed</p>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-5">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-plus-circle"></i> Record Meter Reading</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label>Select Nozzle</label>
                                    <select name="nozzle_id" id="nozzle_id" class="form-control" required>
                                        <option value="">-- Select Nozzle --</option>
                                        <?php foreach($nozzles as $nozzle): ?>
                                            <option value="<?php echo $nozzle['id']; ?>" 
                                                    data-closing="<?php echo $nozzle['closing_meter']; ?>">
                                                <?php echo $nozzle['nozzle_name']; ?> - <?php echo $nozzle['product_name']; ?>
                                                (Current: <?php echo number_format($nozzle['closing_meter'], 2); ?> <?php echo $nozzle['product_name'] == 'CNG' ? 'Units' : 'L'; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Reading Date</label>
                                    <input type="date" name="reading_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Opening Meter</label>
                                            <input type="number" name="opening_meter" id="opening_meter" class="form-control meter-display" step="0.01" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Closing Meter</label>
                                            <input type="number" name="closing_meter" id="closing_meter" class="form-control meter-display" step="0.01" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label>Shift</label>
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
                                    <label>Notes</label>
                                    <textarea name="notes" class="form-control" rows="2"></textarea>
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
                                            <th>Opening</th>
                                            <th>Closing</th>
                                            <th>Dispensed</th>
                                            <th>Recorded By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($readings as $reading): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y H:i', strtotime($reading['reading_date'])); ?></td>
                                            <td><strong><?php echo $reading['nozzle_name']; ?></strong></td>
                                            <td>
                                                <span class="badge <?php echo in_array($reading['product_name'], ['CNG', 'LPG', 'Natural Gas']) ? 'badge-gas' : 'badge-liquid'; ?>">
                                                    <?php echo $reading['product_name']; ?>
                                                </span>
                                            </td>
                                            <td class="text-end"><?php echo number_format($reading['opening_meter'], 2); ?></td>
                                            <td class="text-end"><?php echo number_format($reading['closing_meter'], 2); ?></td>
                                            <td class="text-end fw-bold text-primary"><?php echo number_format($reading['sale_quantity'], 2); ?></td>
                                            <td><?php echo $reading['recorder']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
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
        });
        
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
    </script>
</body>
</html>
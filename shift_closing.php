<?php
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
    JOIN shifts sh ON sc.shift_id = sh.id 
    WHERE sc.status = 'open' 
    ORDER BY sc.id DESC 
    LIMIT 1
");
$active_shift = $stmt->fetch();

// Get all shifts
$shifts = $pdo->query("SELECT * FROM shifts WHERE is_active = 1")->fetchAll();

// Get tanks and nozzles for stock
$tanks = $pdo->query("SELECT t.*, p.product_name FROM tanks t JOIN fuel_products p ON t.product_id = p.id WHERE t.is_active = 1")->fetchAll();
$nozzles = $pdo->query("SELECT n.*, t.tank_name, p.product_name FROM nozzles n JOIN tanks t ON n.tank_id = t.id JOIN fuel_products p ON t.product_id = p.id WHERE n.is_active = 1")->fetchAll();

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
        
        // Record opening meter readings for each nozzle
        foreach($nozzles as $nozzle) {
            $stmt = $pdo->prepare("INSERT INTO meter_readings (nozzle_id, reading_date, shift_id, opening_meter, closing_meter, recorded_by, shift_closed) VALUES (?, NOW(), ?, ?, ?, ?, 0)");
            $stmt->execute([$nozzle['id'], $shift_closing_id, $nozzle['closing_meter'], 0, $user['id']]);
        }
        
        $pdo->commit();
        $success = "✅ Shift started successfully!";
        
        // Refresh active shift
        $stmt = $pdo->query("
            SELECT sc.*, sh.shift_name 
            FROM shift_closing sc 
            JOIN shifts sh ON sc.shift_id = sh.id 
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
        
        // Calculate sales totals
        // Get sales for this shift
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN sale_type = 'cash' THEN total_amount ELSE 0 END), 0) as cash_sales,
                COALESCE(SUM(CASE WHEN sale_type = 'credit' THEN total_amount ELSE 0 END), 0) as credit_sales,
                COALESCE(SUM(CASE WHEN sale_type = 'advance' THEN total_amount ELSE 0 END), 0) as advance_sales,
                COUNT(*) as total_sales
            FROM sales 
            WHERE shift_id = ? AND DATE(sale_date) = ?
        ");
        $stmt->execute([$shift['shift_id'], $shift['shift_date']]);
        $sales = $stmt->fetch();
        
        // Get gas sales
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as total_gas_sales
            FROM gas_sales 
            WHERE shift_id = ? AND DATE(sale_date) = ?
        ");
        $stmt->execute([$shift['shift_id'], $shift['shift_date']]);
        $gas_sales = $stmt->fetch()['total_gas_sales'];
        
        // Update shift closing
        $stmt = $pdo->prepare("
            UPDATE shift_closing 
            SET closing_time = NOW(),
                closed_by = ?,
                total_cash_sales = ?,
                total_credit_sales = ?,
                total_advance_sales = ?,
                total_gas_sales = ?,
                total_receipts = ?,
                net_cash = ?,
                notes = CONCAT(notes, '\n', ?),
                status = 'closed',
                closed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $user['id'],
            $sales['cash_sales'],
            $sales['credit_sales'],
            $sales['advance_sales'],
            $gas_sales,
            $closing_cash,
            $closing_cash,
            "Shift closed. Cash in drawer: BDT " . number_format($closing_cash, 2),
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
        
        // Update closing meter readings for each nozzle
        foreach($nozzles as $nozzle) {
            $stmt = $pdo->prepare("
                UPDATE meter_readings 
                SET closing_meter = ?, shift_closed = 1 
                WHERE shift_id = ? AND nozzle_id = ? AND shift_closed = 0
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$nozzle['closing_meter'], $shift_id, $nozzle['id']]);
        }
        
        $pdo->commit();
        $success = "✅ Shift closed successfully!<br>
                    <strong>Cash Sales:</strong> BDT " . number_format($sales['cash_sales'], 2) . "<br>
                    <strong>Credit Sales:</strong> BDT " . number_format($sales['credit_sales'], 2) . "<br>
                    <strong>Gas Sales:</strong> BDT " . number_format($gas_sales, 2) . "<br>
                    <strong>Cash in Drawer:</strong> BDT " . number_format($closing_cash, 2);
        
        $active_shift = null;
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Get shift history
$shift_history = $pdo->query("
    SELECT sc.*, sh.shift_name, u1.full_name as opened_by_name, u2.full_name as closed_by_name
    FROM shift_closing sc
    JOIN shifts sh ON sc.shift_id = sh.id
    LEFT JOIN users u1 ON sc.opened_by = u1.id
    LEFT JOIN users u2 ON sc.closed_by = u2.id
    WHERE sc.status = 'closed'
    ORDER BY sc.id DESC
    LIMIT 50
")->fetchAll();

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
                                    <p>Start a new shift to begin sales tracking.</p>
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
                            $total_sales = array_sum(array_column($shift_history, 'total_cash_sales'));
                            echo number_format($total_sales, 2);
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
                            $total_gas = array_sum(array_column($shift_history, 'total_gas_sales'));
                            echo number_format($total_gas, 2);
                        ?></h3>
                        <p>Total Gas Sales</p>
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
                                    <th>Cash Sales</th>
                                    <th>Credit Sales</th>
                                    <th>Gas Sales</th>
                                    <th>Net Cash</th>
                                    <th>Status</th>
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
                                    <td class="text-end text-info"><?php echo $currency; ?> <?php echo number_format($shift['total_gas_sales'], 2); ?></td>
                                    <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($shift['net_cash'], 2); ?></td>
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
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($shift_history, 'total_cash_sales')), 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($shift_history, 'total_credit_sales')), 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($shift_history, 'total_gas_sales')), 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($shift_history, 'net_cash')), 2); ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Start Shift Modal -->
    <div class="modal fade" id="startShiftModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5><i class="fas fa-play-circle"></i> Start New Shift</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Select Shift</label>
                            <select name="shift_id" class="form-control" required>
                                <option value="">-- Select Shift --</option>
                                <?php foreach($shifts as $shift): ?>
                                    <option value="<?php echo $shift['id']; ?>">
                                        <?php echo $shift['shift_name']; ?> (<?php echo date('h:i A', strtotime($shift['start_time'])); ?> - <?php echo date('h:i A', strtotime($shift['end_time'])); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Notes</label>
                            <textarea name="notes" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Shift will record:</strong><br>
                            - Opening tank stocks<br>
                            - Opening nozzle meter readings<br>
                            - All sales during the shift
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="start_shift" class="btn btn-success">Start Shift</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Close Shift Modal -->
    <div class="modal fade" id="closeShiftModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5><i class="fas fa-stop-circle"></i> Close Shift</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="shift_id" value="<?php echo $active_shift['id'] ?? ''; ?>">
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Before closing shift:</strong><br>
                            1. Count cash in drawer<br>
                            2. Record final dip readings<br>
                            3. Verify all sales are recorded
                        </div>
                        <div class="mb-3">
                            <label>Cash in Drawer (<?php echo $currency; ?>)</label>
                            <input type="number" name="closing_cash" class="form-control" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label>Closing Notes</label>
                            <textarea name="notes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="close_shift" class="btn btn-danger">Close Shift</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
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
        });
    </script>
</body>
</html>
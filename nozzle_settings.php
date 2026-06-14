<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Add new nozzle
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_nozzle'])) {
    $nozzle_name = $_POST['nozzle_name'];
    $tank_id = $_POST['tank_id'];
    $opening_meter = $_POST['opening_meter'] ?? 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    $stmt = $pdo->prepare("INSERT INTO nozzles (nozzle_name, tank_id, opening_meter, closing_meter, is_active) VALUES (?, ?, ?, 0, ?)");
    if($stmt->execute([$nozzle_name, $tank_id, $opening_meter, $is_active])) {
        $success = "Nozzle added successfully!";
    } else {
        $error = "Failed to add nozzle";
    }
}

// Update nozzle
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_nozzle'])) {
    $nozzle_id = $_POST['nozzle_id'];
    $nozzle_name = $_POST['nozzle_name'];
    $tank_id = $_POST['tank_id'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    $stmt = $pdo->prepare("UPDATE nozzles SET nozzle_name = ?, tank_id = ?, is_active = ? WHERE id = ?");
    if($stmt->execute([$nozzle_name, $tank_id, $is_active, $nozzle_id])) {
        $success = "Nozzle updated successfully!";
    } else {
        $error = "Failed to update nozzle";
    }
}

// Delete nozzle - FIXED with check for existing sales
if(isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    
    try {
        // First check if nozzle has any sales records
        $stmt = $pdo->prepare("SELECT COUNT(*) as sale_count FROM sales WHERE nozzle_id = ?");
        $stmt->execute([$delete_id]);
        $sale_count = $stmt->fetch()['sale_count'];
        
        if($sale_count > 0) {
            // Option 1: Soft delete (just deactivate instead of deleting)
            $stmt = $pdo->prepare("UPDATE nozzles SET is_active = 0 WHERE id = ?");
            $stmt->execute([$delete_id]);
            $success = "Nozzle has $sale_count sales record(s). It has been deactivated instead of deleted to maintain data integrity.";
        } else {
            // No sales records, safe to delete
            $stmt = $pdo->prepare("DELETE FROM nozzles WHERE id = ?");
            $stmt->execute([$delete_id]);
            $success = "Nozzle deleted successfully!";
        }
    } catch(Exception $e) {
        $error = "Cannot delete nozzle: " . $e->getMessage();
    }
}

// Get all nozzles with tank and product info
$nozzles = $pdo->query("
    SELECT n.*, 
           t.tank_name, 
           t.product_id,
           p.product_name,
           (SELECT COUNT(*) FROM sales WHERE nozzle_id = n.id) as sale_count
    FROM nozzles n 
    LEFT JOIN tanks t ON n.tank_id = t.id 
    LEFT JOIN fuel_products p ON t.product_id = p.id 
    ORDER BY n.nozzle_name
")->fetchAll();

// Get all active tanks for dropdown
$tanks = $pdo->query("
    SELECT t.*, p.product_name 
    FROM tanks t 
    JOIN fuel_products p ON t.product_id = p.id 
    WHERE t.is_active = 1 
    ORDER BY t.tank_name
")->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nozzle Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        .stats-card {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stats-card i {
            font-size: 40px;
            opacity: 0.5;
            float: right;
        }
        .tank-group {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .tank-title {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .nozzle-badge {
            background: #e9ecef;
            border-radius: 8px;
            padding: 10px;
            margin: 5px;
            display: inline-block;
            width: 100%;
        }
        .has-sales {
            border-left: 4px solid #ffc107;
        }
        .text-end {
            text-align: right;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-oil-can"></i> Nozzle Settings</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addNozzleModal">
                    <i class="fas fa-plus"></i> Add New Nozzle
                </button>
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
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-oil-can"></i>
                        <h3><?php echo count($nozzles); ?></h3>
                        <p>Total Nozzles</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-check-circle"></i>
                        <h3><?php echo count(array_filter($nozzles, function($n) { return $n['is_active']; })); ?></h3>
                        <p>Active Nozzles</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-warehouse"></i>
                        <h3><?php echo count($tanks); ?></h3>
                        <p>Connected Tanks</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php 
                            $total_sales = 0;
                            foreach($nozzles as $n) {
                                $total_sales += $n['sale_count'];
                            }
                            echo $total_sales;
                        ?></h3>
                        <p>Total Sales Records</p>
                    </div>
                </div>
            </div>
            
            <!-- Nozzles List Grouped by Tank -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-list"></i> Nozzle Configuration</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Note:</strong> Nozzles that have existing sales records cannot be deleted. 
                        They will be deactivated instead to maintain data integrity.
                    </div>
                    
                    <?php 
                    // Group nozzles by tank
                    $grouped = [];
                    foreach($nozzles as $nozzle) {
                        $tank_key = $nozzle['tank_id'] ?? 'unassigned';
                        if(!isset($grouped[$tank_key])) {
                            $grouped[$tank_key] = [
                                'tank_name' => $nozzle['tank_name'] ?? 'Unassigned',
                                'product_name' => $nozzle['product_name'] ?? 'N/A',
                                'nozzles' => []
                            ];
                        }
                        $grouped[$tank_key]['nozzles'][] = $nozzle;
                    }
                    ?>
                    
                    <?php foreach($grouped as $tank_id => $group): ?>
                        <div class="tank-group">
                            <div class="tank-title">
                                <i class="fas fa-warehouse"></i> 
                                <?php echo htmlspecialchars($group['tank_name']); ?>
                                <span class="float-end">
                                    <i class="fas fa-gas-pump"></i> <?php echo $group['product_name']; ?>
                                    <span class="badge bg-light text-dark ms-2">
                                        <?php echo count($group['nozzles']); ?> Nozzle(s)
                                    </span>
                                </span>
                            </div>
                            <div class="row">
                                <?php foreach($group['nozzles'] as $nozzle): ?>
                                    <div class="col-md-4 col-lg-3 mb-3">
                                        <div class="nozzle-badge <?php echo $nozzle['sale_count'] > 0 ? 'has-sales' : ''; ?>">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <strong><?php echo htmlspecialchars($nozzle['nozzle_name']); ?></strong>
                                                <?php if($nozzle['is_active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small text-muted mt-2">
                                                <div><i class="fas fa-tachometer-alt"></i> Opening: <?php echo number_format($nozzle['opening_meter'], 2); ?> L</div>
                                                <div><i class="fas fa-tachometer-alt"></i> Closing: <?php echo number_format($nozzle['closing_meter'], 2); ?> L</div>
                                                <?php if($nozzle['sale_count'] > 0): ?>
                                                    <div class="text-warning mt-1">
                                                        <i class="fas fa-history"></i> <?php echo $nozzle['sale_count']; ?> sale(s)
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="mt-2 text-end">
                                                <button class="btn btn-sm btn-warning" onclick="editNozzle(<?php echo $nozzle['id']; ?>, '<?php echo addslashes($nozzle['nozzle_name']); ?>', <?php echo $nozzle['tank_id'] ?? 'null'; ?>, <?php echo $nozzle['is_active']; ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <?php if($nozzle['sale_count'] == 0): ?>
                                                    <a href="?delete_id=<?php echo $nozzle['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this nozzle? This action cannot be undone.')">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </a>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-secondary" disabled title="Cannot delete - has <?php echo $nozzle['sale_count']; ?> sales record(s)">
                                                        <i class="fas fa-trash"></i> Locked
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if(empty($grouped)): ?>
                        <div class="alert alert-info text-center">
                            <i class="fas fa-info-circle"></i> No nozzles found. Click "Add New Nozzle" to create one.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Nozzle Modal -->
    <div class="modal fade" id="addNozzleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5><i class="fas fa-plus"></i> Add New Nozzle</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Nozzle Name</label>
                            <input type="text" name="nozzle_name" class="form-control" placeholder="e.g., Diesel Pump Left, Petrol Pump Right" required>
                        </div>
                        <div class="mb-3">
                            <label>Connect to Tank</label>
                            <select name="tank_id" class="form-control" required>
                                <option value="">-- Select Tank --</option>
                                <?php foreach($tanks as $tank): ?>
                                    <option value="<?php echo $tank['id']; ?>">
                                        <?php echo $tank['tank_name']; ?> (<?php echo $tank['product_name']; ?> - <?php echo number_format($tank['current_stock_liters'], 0); ?> L)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">This nozzle will dispense fuel from the selected tank</small>
                        </div>
                        <div class="mb-3">
                            <label>Opening Meter Reading (Liters)</label>
                            <input type="number" name="opening_meter" class="form-control" step="0.01" value="0">
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input type="checkbox" name="is_active" class="form-check-input" checked>
                                <label class="form-check-label">Active (visible in POS)</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_nozzle" class="btn btn-primary">Add Nozzle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Nozzle Modal -->
    <div class="modal fade" id="editNozzleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5><i class="fas fa-edit"></i> Edit Nozzle</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="nozzle_id" id="edit_nozzle_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Nozzle Name</label>
                            <input type="text" name="nozzle_name" id="edit_nozzle_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Connect to Tank</label>
                            <select name="tank_id" id="edit_tank_id" class="form-control" required>
                                <option value="">-- Select Tank --</option>
                                <?php foreach($tanks as $tank): ?>
                                    <option value="<?php echo $tank['id']; ?>">
                                        <?php echo $tank['tank_name']; ?> (<?php echo $tank['product_name']; ?> - <?php echo number_format($tank['current_stock_liters'], 0); ?> L)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input type="checkbox" name="is_active" id="edit_is_active" class="form-check-input">
                                <label class="form-check-label">Active (visible in POS)</label>
                            </div>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Note:</strong> Changing tank connection will affect future sales only. Past sales remain with original tank.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_nozzle" class="btn btn-warning">Update Nozzle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editNozzle(id, name, tankId, isActive) {
            document.getElementById('edit_nozzle_id').value = id;
            document.getElementById('edit_nozzle_name').value = name;
            document.getElementById('edit_tank_id').value = tankId;
            document.getElementById('edit_is_active').checked = isActive == 1;
            new bootstrap.Modal(document.getElementById('editNozzleModal')).show();
        }
    </script>
</body>
</html>
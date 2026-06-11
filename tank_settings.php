<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Add new tank
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_tank'])) {
    $tank_name = $_POST['tank_name'];
    $product_id = $_POST['product_id'];
    $capacity_liters = $_POST['capacity_liters'];
    $current_stock = $_POST['current_stock'];
    $calibration_factor = $_POST['calibration_factor'];
    
    $stmt = $pdo->prepare("INSERT INTO tanks (tank_name, product_id, capacity_liters, current_stock_liters, calibration_factor, is_active) VALUES (?, ?, ?, ?, ?, 1)");
    if($stmt->execute([$tank_name, $product_id, $capacity_liters, $current_stock, $calibration_factor])) {
        $success = "Tank added successfully!";
    } else {
        $error = "Failed to add tank";
    }
}

// Update tank stock
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_stock'])) {
    $tank_id = $_POST['tank_id'];
    $current_stock = $_POST['current_stock'];
    
    $stmt = $pdo->prepare("UPDATE tanks SET current_stock_liters = ? WHERE id = ?");
    if($stmt->execute([$current_stock, $tank_id])) {
        $success = "Stock updated successfully!";
    } else {
        $error = "Failed to update stock";
    }
}

// Update tank
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_tank'])) {
    $tank_id = $_POST['tank_id'];
    $tank_name = $_POST['tank_name'];
    $product_id = $_POST['product_id'];
    $capacity_liters = $_POST['capacity_liters'];
    $calibration_factor = $_POST['calibration_factor'];
    
    $stmt = $pdo->prepare("UPDATE tanks SET tank_name = ?, product_id = ?, capacity_liters = ?, calibration_factor = ? WHERE id = ?");
    if($stmt->execute([$tank_name, $product_id, $capacity_liters, $calibration_factor, $tank_id])) {
        $success = "Tank updated successfully!";
    } else {
        $error = "Failed to update tank";
    }
}

// Delete tank
if(isset($_GET['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM tanks WHERE id = ?");
    if($stmt->execute([$_GET['delete_id']])) {
        $success = "Tank deleted successfully!";
    }
}

// Get all tanks
$tanks = $pdo->query("
    SELECT t.*, p.product_name, p.purchase_rate 
    FROM tanks t 
    JOIN fuel_products p ON t.product_id = p.id 
    ORDER BY t.tank_name
")->fetchAll();

$products = $pdo->query("SELECT * FROM fuel_products WHERE is_active = 1")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tank Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-warehouse"></i> Tank Settings</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTankModal">
                    <i class="fas fa-plus"></i> Add New Tank
                </button>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-white bg-primary">
                        <div class="card-body">
                            <h5>Total Tanks</h5>
                            <h2><?php echo count($tanks); ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-success">
                        <div class="card-body">
                            <h5>Total Stock</h5>
                            <h2><?php echo number_format(array_sum(array_column($tanks, 'current_stock_liters')), 0); ?> L</h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-info">
                        <div class="card-body">
                            <h5>Total Capacity</h5>
                            <h2><?php echo number_format(array_sum(array_column($tanks, 'capacity_liters')), 0); ?> L</h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-warning">
                        <div class="card-body">
                            <h5>Fill Percentage</h5>
                            <h2><?php 
                                $total_stock = array_sum(array_column($tanks, 'current_stock_liters'));
                                $total_capacity = array_sum(array_column($tanks, 'capacity_liters'));
                                $fill_percent = $total_capacity > 0 ? ($total_stock / $total_capacity) * 100 : 0;
                                echo round($fill_percent, 1); ?>%
                            </h2>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tanks List -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-list"></i> Tank List</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="tanksTable">
                            <thead>
                                <tr>
                                    <th>SL</th>
                                    <th>Tank Name</th>
                                    <th>Product</th>
                                    <th>Capacity (L)</th>
                                    <th>Current Stock (L)</th>
                                    <th>Fill %</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $sl=1; foreach($tanks as $t): 
                                    $fill_percent = ($t['current_stock_liters'] / $t['capacity_liters']) * 100;
                                    $bar_color = $fill_percent < 20 ? 'bg-danger' : ($fill_percent > 80 ? 'bg-success' : 'bg-warning');
                                ?>
                                <tr>
                                    <td><?php echo $sl++; ?></td>
                                    <td><strong><?php echo $t['tank_name']; ?></strong></td>
                                    <td><?php echo $t['product_name']; ?></td>
                                    <td><?php echo number_format($t['capacity_liters'], 0); ?></td>
                                    <td><?php echo number_format($t['current_stock_liters'], 2); ?></td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar <?php echo $bar_color; ?>" style="width: <?php echo $fill_percent; ?>%">
                                                <?php echo round($fill_percent, 1); ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if($t['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick="updateStock(<?php echo $t['id']; ?>, '<?php echo $t['tank_name']; ?>', <?php echo $t['current_stock_liters']; ?>)">
                                            <i class="fas fa-edit"></i> Stock
                                        </button>
                                        <button class="btn btn-sm btn-warning" onclick="editTank(<?php echo $t['id']; ?>, '<?php echo $t['tank_name']; ?>', <?php echo $t['product_id']; ?>, <?php echo $t['capacity_liters']; ?>, <?php echo $t['calibration_factor']; ?>)">
                                            <i class="fas fa-pen"></i> Edit
                                        </button>
                                        <a href="?delete_id=<?php echo $t['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this tank?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Tank Modal -->
    <div class="modal fade" id="addTankModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5><i class="fas fa-plus"></i> Add New Tank</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Tank Name</label>
                            <input type="text" name="tank_name" class="form-control" placeholder="e.g., Diesel Tank-01, Petrol Tank-01" required>
                        </div>
                        <div class="mb-3">
                            <label>Product Type</label>
                            <select name="product_id" class="form-control" required>
                                <option value="">-- Select Product --</option>
                                <?php foreach($products as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo $p['product_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Capacity (Liters)</label>
                            <input type="number" name="capacity_liters" class="form-control" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label>Current Stock (Liters)</label>
                            <input type="number" name="current_stock" class="form-control" step="0.01" value="0">
                        </div>
                        <div class="mb-3">
                            <label>Calibration Factor (L/cm)</label>
                            <input type="number" name="calibration_factor" class="form-control" step="0.0001" value="1.0000">
                            <small class="text-muted">Used for dip stick measurement conversion</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_tank" class="btn btn-primary">Add Tank</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Update Stock Modal -->
    <div class="modal fade" id="stockModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5><i class="fas fa-edit"></i> Update Tank Stock</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="tank_id" id="stock_tank_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Tank Name</label>
                            <input type="text" id="stock_tank_name" class="form-control" readonly>
                        </div>
                        <div class="mb-3">
                            <label>Current Stock (Liters)</label>
                            <input type="number" name="current_stock" id="stock_current_stock" class="form-control" step="0.01" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_stock" class="btn btn-info">Update Stock</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Tank Modal -->
    <div class="modal fade" id="editTankModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5><i class="fas fa-edit"></i> Edit Tank</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="tank_id" id="edit_tank_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Tank Name</label>
                            <input type="text" name="tank_name" id="edit_tank_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Product Type</label>
                            <select name="product_id" id="edit_product_id" class="form-control" required>
                                <option value="">-- Select Product --</option>
                                <?php foreach($products as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo $p['product_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Capacity (Liters)</label>
                            <input type="number" name="capacity_liters" id="edit_capacity" class="form-control" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label>Calibration Factor (L/cm)</label>
                            <input type="number" name="calibration_factor" id="edit_calibration" class="form-control" step="0.0001" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_tank" class="btn btn-warning">Update Tank</button>
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
            $('#tanksTable').DataTable({
                order: [[0, 'asc']],
                pageLength: 25
            });
        });
        
        function updateStock(id, name, stock) {
            document.getElementById('stock_tank_id').value = id;
            document.getElementById('stock_tank_name').value = name;
            document.getElementById('stock_current_stock').value = stock;
            new bootstrap.Modal(document.getElementById('stockModal')).show();
        }
        
        function editTank(id, name, productId, capacity, calibration) {
            document.getElementById('edit_tank_id').value = id;
            document.getElementById('edit_tank_name').value = name;
            document.getElementById('edit_product_id').value = productId;
            document.getElementById('edit_capacity').value = capacity;
            document.getElementById('edit_calibration').value = calibration;
            new bootstrap.Modal(document.getElementById('editTankModal')).show();
        }
    </script>
</body>
</html>
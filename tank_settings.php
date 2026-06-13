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

// Calculate statistics
$total_tanks = count($tanks);
$total_stock = array_sum(array_column($tanks, 'current_stock_liters'));
$total_capacity = array_sum(array_column($tanks, 'capacity_liters'));
$fill_percent = $total_capacity > 0 ? ($total_stock / $total_capacity) * 100 : 0;

// Calculate total inventory value
$total_value = 0;
foreach($tanks as $t) {
    $total_value += $t['current_stock_liters'] * $t['purchase_rate'];
}

// Low stock count (less than 500L)
$low_stock_count = 0;
foreach($tanks as $t) {
    if($t['current_stock_liters'] < 500) {
        $low_stock_count++;
    }
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
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
    <style>
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s;
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .stats-card i {
            font-size: 40px;
            opacity: 0.5;
            float: right;
        }
        .stats-card h3 {
            font-size: 28px;
            margin-bottom: 5px;
        }
        .stats-card p {
            margin: 0;
            opacity: 0.8;
        }
        .progress-bar {
            transition: width 0.5s ease;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .text-end {
            text-align: right;
        }
        .btn-action {
            margin: 2px;
        }
        .low-stock-row {
            background-color: #f8d7da !important;
        }
    </style>
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
            
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-oil-can"></i>
                        <h3><?php echo $total_tanks; ?></h3>
                        <p>Total Tanks</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo number_format($total_stock, 2); ?> L</h3>
                        <p>Total Stock</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_value, 2); ?></h3>
                        <p>Inventory Value</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3><?php echo $low_stock_count; ?></h3>
                        <p>Low Stock Alerts</p>
                    </div>
                </div>
            </div>
            
            <!-- Tanks List -->
            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5><i class="fas fa-list"></i> Tank List</h5>
                    <button onclick="window.print()" class="btn btn-light btn-sm">
                        <i class="fas fa-print"></i> Print List
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="tanksTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>SL</th>
                                    <th>Tank Name</th>
                                    <th>Product</th>
                                    <th class="text-end">Capacity (L)</th>
                                    <th class="text-end">Current Stock (L)</th>
                                    <th>Fill %</th>
                                    <th class="text-end">Stock Value</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $sl=1; foreach($tanks as $t): 
                                    $fill_percent = ($t['capacity_liters'] > 0) ? ($t['current_stock_liters'] / $t['capacity_liters']) * 100 : 0;
                                    $bar_color = $fill_percent < 20 ? 'bg-danger' : ($fill_percent > 80 ? 'bg-success' : 'bg-warning');
                                    $stock_value = $t['current_stock_liters'] * $t['purchase_rate'];
                                    $row_class = ($t['current_stock_liters'] < 500) ? 'low-stock-row' : '';
                                ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td class="text-center"><?php echo $sl++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($t['tank_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($t['product_name']); ?></td>
                                    <td class="text-end"><?php echo number_format($t['capacity_liters'], 0); ?> L</td>
                                    <td class="text-end"><?php echo number_format($t['current_stock_liters'], 2); ?> L</td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar <?php echo $bar_color; ?>" style="width: <?php echo min($fill_percent, 100); ?>%">
                                                <?php echo round($fill_percent, 1); ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($stock_value, 2); ?></td>
                                    <td class="text-center">
                                        <?php if($t['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-info btn-action" onclick="updateStock(<?php echo $t['id']; ?>, '<?php echo addslashes($t['tank_name']); ?>', <?php echo $t['current_stock_liters']; ?>)">
                                            <i class="fas fa-edit"></i> Stock
                                        </button>
                                        <button class="btn btn-sm btn-warning btn-action" onclick="editTank(<?php echo $t['id']; ?>, '<?php echo addslashes($t['tank_name']); ?>', <?php echo $t['product_id']; ?>, <?php echo $t['capacity_liters']; ?>, <?php echo $t['calibration_factor']; ?>)">
                                            <i class="fas fa-pen"></i> Edit
                                        </button>
                                        <a href="?delete_id=<?php echo $t['id']; ?>" class="btn btn-sm btn-danger btn-action" onclick="return confirm('Delete this tank?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="3" class="text-end">TOTAL:</td>
                                    <td class="text-end"><?php echo number_format($total_capacity, 0); ?> L</td>
                                    <td class="text-end"><?php echo number_format($total_stock, 2); ?> L</td>
                                    <td></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_value, 2); ?></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
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
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
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
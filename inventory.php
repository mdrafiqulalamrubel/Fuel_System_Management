<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Get inventory data
$products = $pdo->query("SELECT * FROM fuel_products WHERE is_active = 1")->fetchAll();
$tanks = $pdo->query("SELECT t.*, p.product_name, p.purchase_rate FROM tanks t JOIN fuel_products p ON t.product_id = p.id ORDER BY t.tank_name")->fetchAll();
$stock_ledger = $pdo->query("SELECT sl.*, p.product_name, t.tank_name FROM stock_ledger sl JOIN fuel_products p ON sl.product_id = p.id JOIN tanks t ON sl.tank_id = t.id ORDER BY sl.transaction_date DESC LIMIT 100")->fetchAll();

// Update tank calibration
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_calibration'])) {
    $tank_id = $_POST['tank_id'];
    $calibration_factor = $_POST['calibration_factor'];
    
    $stmt = $pdo->prepare("UPDATE tanks SET calibration_factor = ? WHERE id = ?");
    if($stmt->execute([$calibration_factor, $tank_id])) {
        $success = "Calibration updated successfully!";
    } else {
        $error = "Failed to update calibration";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand">Inventory Management</span>
            <a href="dashboard.php" class="btn btn-light">Back to Dashboard</a>
        </div>
    </nav>
    
    <div class="container mt-3">
        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5>Current Stock Position</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Tank</th>
                                        <th>Product</th>
                                        <th>Current Stock (L)</th>
                                        <th>Capacity (L)</th>
                                        <th>Fill %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($tanks as $tank): ?>
                                    <?php $fill_percent = ($tank['current_stock_liters'] / $tank['capacity_liters']) * 100; ?>
                                    <tr>
                                        <td><?php echo $tank['tank_name']; ?></td>
                                        <td><?php echo $tank['product_name']; ?></td>
                                        <td><?php echo number_format($tank['current_stock_liters'], 2); ?></td>
                                        <td><?php echo number_format($tank['capacity_liters'], 2); ?></td>
                                        <td>
                                            <div class="progress">
                                                <div class="progress-bar <?php echo $fill_percent < 20 ? 'bg-danger' : ($fill_percent > 80 ? 'bg-success' : 'bg-info'); ?>" 
                                                     style="width: <?php echo $fill_percent; ?>%">
                                                    <?php echo round($fill_percent, 1); ?>%
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                             </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5>Stock Value Summary</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="stockValueChart" height="250"></canvas>
                        <hr>
                        <?php
                        $total_value = 0;
                        foreach($tanks as $tank) {
                            $value = $tank['current_stock_liters'] * $tank['purchase_rate'];
                            $total_value += $value;
                        }
                        ?>
                        <h4 class="text-center">Total Inventory Value: ৳<?php echo number_format($total_value, 2); ?></h4>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mt-3">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5>Tank Calibration Settings</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label>Select Tank</label>
                                <select name="tank_id" class="form-control" required>
                                    <option value="">Select Tank</option>
                                    <?php foreach($tanks as $tank): ?>
                                        <option value="<?php echo $tank['id']; ?>">
                                            <?php echo $tank['tank_name']; ?> - <?php echo $tank['product_name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label>Calibration Factor (Liters per cm)</label>
                                <input type="number" name="calibration_factor" class="form-control" step="0.0001" placeholder="e.g., 5.2345" required>
                                <small class="text-muted">Used to convert dip stick reading to liters</small>
                            </div>
                            <button type="submit" name="update_calibration" class="btn btn-warning w-100">Update Calibration</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5>Stock Ledger (Last 100 Transactions)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="stockLedgerTable">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Product</th>
                                        <th>Tank</th>
                                        <th>Type</th>
                                        <th>In (L)</th>
                                        <th>Out (L)</th>
                                        <th>Balance (L)</th>
                                        <th>Reference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($stock_ledger as $entry): ?>
                                    <tr>
                                        <td><?php echo $entry['transaction_date']; ?></td>
                                        <td><?php echo $entry['product_name']; ?></td>
                                        <td><?php echo $entry['tank_name']; ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $entry['transaction_type'] == 'sale' ? 'danger' : 'success'; ?>">
                                                <?php echo ucfirst($entry['transaction_type']); ?>
                                            </span>
                                         </td>
                                        <td><?php echo number_format($entry['in_quantity'], 2); ?></td>
                                        <td><?php echo number_format($entry['out_quantity'], 2); ?></td>
                                        <td><strong><?php echo number_format($entry['balance_quantity'], 2); ?></strong></td>
                                        <td><?php echo $entry['reference_no']; ?></td>
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
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#stockLedgerTable').DataTable({
                order: [[0, 'desc']]
            });
        });
        
        // Stock value chart
        const ctx = document.getElementById('stockValueChart').getContext('2d');
        const productNames = <?php echo json_encode(array_column($tanks, 'product_name')); ?>;
        const stockValues = <?php 
            $values = [];
            foreach($tanks as $tank) {
                $values[] = $tank['current_stock_liters'] * $tank['purchase_rate'];
            }
            echo json_encode($values);
        ?>;
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: productNames,
                datasets: [{
                    label: 'Stock Value (৳)',
                    data: stockValues,
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '৳' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
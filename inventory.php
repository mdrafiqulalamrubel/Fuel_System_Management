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

// Calculate total stock value
$total_value = 0;
$total_stock = 0;
$total_capacity = 0;
foreach($tanks as $tank) {
    $total_value += $tank['current_stock_liters'] * $tank['purchase_rate'];
    $total_stock += $tank['current_stock_liters'];
    $total_capacity += $tank['capacity_liters'];
}

// Get low stock alerts
$low_stock_threshold = 500;
$low_stock_tanks = array_filter($tanks, function($tank) use ($low_stock_threshold) {
    return $tank['current_stock_liters'] < $low_stock_threshold;
});

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

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .table-responsive {
            overflow-x: auto;
        }
        .text-end {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .low-stock {
            background-color: #f8d7da !important;
        }
        .progress {
            height: 20px;
            min-width: 120px;
        }
        .badge-success {
            background-color: #28a745;
        }
        .badge-danger {
            background-color: #dc3545;
        }
        .badge-warning {
            background-color: #ffc107;
            color: #000;
        }
        
        @media print {
            .sidebar, .no-print, .stats-card, .card-header .btn, 
            .dataTables_length, .dataTables_filter, .dataTables_paginate,
            form, .col-md-4, .btn {
                display: none !important;
            }
            .col-md-8, .col-md-12 {
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .main-content {
                margin: 0 !important;
                padding: 10px !important;
            }
            .card {
                border: none !important;
                box-shadow: none !important;
            }
            .card-header {
                background: none !important;
                color: black !important;
                border-bottom: 1px solid #000 !important;
            }
            .table th, .table td {
                border: 1px solid #000 !important;
            }
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 20px;
            }
        }
        .print-header {
            display: none;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- Print Header -->
            <div class="print-header">
                <h2><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h2>
                <h4>Inventory Stock Report</h4>
                <p>Generated on: <?php echo date('d/m/Y h:i:s A'); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap no-print">
                <h2><i class="fas fa-warehouse"></i> Inventory Management</h2>
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
                <div class="alert alert-danger no-print"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success no-print"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="row no-print">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-oil-can"></i>
                        <h3><?php echo count($tanks); ?></h3>
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
                        <h3><?php echo count($low_stock_tanks); ?></h3>
                        <p>Low Stock Alerts</p>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-4 no-print">
                    <div class="card">
                        <div class="card-header bg-warning text-dark">
                            <h5><i class="fas fa-sliders-h"></i> Tank Calibration Settings</h5>
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
                                <button type="submit" name="update_calibration" class="btn btn-warning w-100">
                                    <i class="fas fa-save"></i> Update Calibration
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Stock Value Chart -->
                    <div class="card mt-3">
                        <div class="card-header bg-info text-white">
                            <h5><i class="fas fa-chart-pie"></i> Stock Value Distribution</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="stockValueChart" height="250"></canvas>
                            <hr>
                            <h4 class="text-center">Total Value: <?php echo $currency; ?> <?php echo number_format($total_value, 2); ?></h4>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <!-- Current Stock Position -->
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-list"></i> Current Stock Position</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="stockTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Tank Name</th>
                                            <th>Product</th>
                                            <th class="text-end">Current Stock (L)</th>
                                            <th class="text-end">Capacity (L)</th>
                                            <th class="text-center">Fill %</th>
                                            <th class="text-end">Stock Value (<?php echo $currency; ?>)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($tanks as $tank): ?>
                                        <?php 
                                        $fill_percent = ($tank['capacity_liters'] > 0) ? ($tank['current_stock_liters'] / $tank['capacity_liters']) * 100 : 0;
                                        $stock_value = $tank['current_stock_liters'] * $tank['purchase_rate'];
                                        $row_class = ($fill_percent < 20) ? 'low-stock' : '';
                                        ?>
                                        <tr class="<?php echo $row_class; ?>">
                                            <td><strong><?php echo $tank['tank_name']; ?></strong></td>
                                            <td><?php echo $tank['product_name']; ?></td>
                                            <td class="text-end"><?php echo number_format($tank['current_stock_liters'], 2); ?> L</td>
                                            <td class="text-end"><?php echo number_format($tank['capacity_liters'], 2); ?> L</td>
                                            <td class="text-center">
                                                <div class="progress">
                                                    <div class="progress-bar <?php echo $fill_percent < 20 ? 'bg-danger' : ($fill_percent > 80 ? 'bg-success' : 'bg-info'); ?>" 
                                                         style="width: <?php echo min($fill_percent, 100); ?>%">
                                                        <?php echo round($fill_percent, 1); ?>%
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($stock_value, 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="2" class="text-end">TOTAL:</td>
                                            <td class="text-end"><?php echo number_format($total_stock, 2); ?> L</td>
                                            <td class="text-end"><?php echo number_format($total_capacity, 2); ?> L</td>
                                            <td class="text-center">-</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_value, 2); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Stock Ledger -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="fas fa-history"></i> Stock Ledger (Last 100 Transactions)</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="stockLedgerTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Date</th>
                                            <th>Product</th>
                                            <th>Tank</th>
                                            <th>Type</th>
                                            <th class="text-end">In (L)</th>
                                            <th class="text-end">Out (L)</th>
                                            <th class="text-end">Balance (L)</th>
                                            <th>Reference</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($stock_ledger)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted">No transactions found</td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach($stock_ledger as $entry): ?>
                                            <tr>
                                                <td><?php echo date('d-m-Y H:i:s', strtotime($entry['transaction_date'])); ?></td>
                                                <td><?php echo $entry['product_name']; ?></td>
                                                <td><?php echo $entry['tank_name']; ?></td>
                                                <td>
                                                    <span class="badge <?php echo $entry['transaction_type'] == 'sale' ? 'bg-danger' : ($entry['transaction_type'] == 'receiving' ? 'bg-success' : 'bg-warning'); ?>">
                                                        <?php echo ucfirst($entry['transaction_type']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end"><?php echo number_format($entry['in_quantity'], 2); ?> L</td>
                                                <td class="text-end"><?php echo number_format($entry['out_quantity'], 2); ?> L</td>
                                                <td class="text-end fw-bold"><?php echo number_format($entry['balance_quantity'], 2); ?> L</td>
                                                <td><?php echo $entry['reference_no']; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
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
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#stockLedgerTable, #stockTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
        
        // Stock value chart
        const ctx = document.getElementById('stockValueChart').getContext('2d');
        const tankNames = <?php echo json_encode(array_column($tanks, 'tank_name')); ?>;
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
                labels: tankNames,
                datasets: [{
                    label: 'Stock Value (<?php echo $currency; ?>)',
                    data: stockValues,
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '<?php echo $currency; ?>' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$as_on = isset($_GET['as_on']) ? $_GET['as_on'] : date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT t.*, p.product_name, p.purchase_rate 
    FROM tanks t 
    JOIN fuel_products p ON t.product_id = p.id
    WHERE t.is_active = 1
    ORDER BY t.tank_name
");
$stmt->execute();
$tanks = $stmt->fetchAll();

$total_value = 0;
$total_stock = 0;
foreach($tanks as $t) {
    $total_value += $t['current_stock_liters'] * $t['purchase_rate'];
    $total_stock += $t['current_stock_liters'];
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Valuation Report</title>
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
        @media print {
            .sidebar, .no-print, .stats-card, .card-header .btn, 
            .dataTables_length, .dataTables_filter, .dataTables_paginate,
            form, .btn, .col-md-4 {
                display: none !important;
            }
            .col-md-8 {
                width: 100% !important;
            }
            .main-content {
                margin: 0 !important;
                padding: 10px !important;
            }
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 20px;
            }
            .table th, .table td {
                border: 1px solid #000 !important;
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
                <h4>Stock Valuation Report</h4>
                <p>As on: <?php echo date('d F Y', strtotime($as_on)); ?></p>
                <p>Generated on: <?php echo date('d/m/Y h:i:s A'); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap no-print">
                <h2><i class="fas fa-chart-pie"></i> Stock Valuation Report</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="reports.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- Date Filter -->
            <div class="card mb-4 no-print">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-calendar-alt"></i> Select Date</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label>As on Date</label>
                            <input type="date" name="as_on" class="form-control" value="<?php echo $as_on; ?>">
                        </div>
                        <div class="col-md-2">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-info w-100">
                                <i class="fas fa-search"></i> View
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="row mb-4 no-print">
                <div class="col-md-4">
                    <div class="stats-card">
                        <i class="fas fa-oil-can"></i>
                        <h3><?php echo count($tanks); ?></h3>
                        <p>Total Tanks</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo number_format($total_stock, 2); ?> L</h3>
                        <p>Total Stock</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_value, 2); ?></h3>
                        <p>Total Value</p>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-list"></i> Stock Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="stockTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Tank Name</th>
                                            <th>Product</th>
                                            <th class="text-end">Current Stock (L)</th>
                                            <th class="text-end">Unit Cost (<?php echo $currency; ?>/L)</th>
                                            <th class="text-end">Total Value (<?php echo $currency; ?>)</th>
                                            <th class="text-end">% of Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($tanks as $t): 
                                            $value = $t['current_stock_liters'] * $t['purchase_rate'];
                                            $percentage = ($total_value > 0) ? ($value / $total_value) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td><strong><?php echo $t['tank_name']; ?></strong></td>
                                            <td><?php echo $t['product_name']; ?></td>
                                            <td class="text-end"><?php echo number_format($t['current_stock_liters'], 2); ?> L</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($t['purchase_rate'], 2); ?></td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($value, 2); ?></td>
                                            <td class="text-end"><?php echo number_format($percentage, 1); ?>%</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="2" class="text-end">TOTAL:</td>
                                            <td class="text-end"><?php echo number_format($total_stock, 2); ?> L</td>
                                            <td class="text-end">-</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_value, 2); ?></td>
                                            <td class="text-end">100%</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="fas fa-chart-pie"></i> Stock Value Distribution</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="stockChart" height="250"></canvas>
                        </div>
                    </div>
                    
                    <div class="card mt-3">
                        <div class="card-header bg-info text-white">
                            <h5><i class="fas fa-info-circle"></i> Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-6">
                                    <strong>Total Tanks:</strong>
                                    <h3><?php echo count($tanks); ?></h3>
                                </div>
                                <div class="col-6">
                                    <strong>Total Stock:</strong>
                                    <h3><?php echo number_format($total_stock, 2); ?> L</h3>
                                </div>
                            </div>
                            <hr>
                            <div class="text-center">
                                <strong>Total Inventory Value</strong>
                                <h2 class="text-success"><?php echo $currency; ?> <?php echo number_format($total_value, 2); ?></h2>
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
            $('#stockTable').DataTable({
                order: [[0, 'asc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
        
        // Stock value chart
        const ctx = document.getElementById('stockChart').getContext('2d');
        const productNames = <?php echo json_encode(array_column($tanks, 'product_name')); ?>;
        const stockValues = <?php echo json_encode(array_map(fn($t)=>$t['current_stock_liters']*$t['purchase_rate'], $tanks)); ?>;
        
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: productNames,
                datasets: [{
                    data: stockValues,
                    backgroundColor: ['#667eea', '#11998e', '#f093fb', '#4facfe', '#f5576c', '#43e97b', '#fa709a'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    </script>
</body>
</html>
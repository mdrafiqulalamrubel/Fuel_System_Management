<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-t');
$tank_id = isset($_GET['tank_id']) ? $_GET['tank_id'] : '';

$sql = "SELECT l.*, t.tank_name, p.product_name, u.full_name as approved_by_name 
        FROM leakage_adjustments l 
        JOIN tanks t ON l.tank_id = t.id 
        JOIN fuel_products p ON t.product_id = p.id 
        LEFT JOIN users u ON l.approved_by = u.id 
        WHERE l.adjustment_date BETWEEN ? AND ?";
$params = [$from_date, $to_date];
if($tank_id) {
    $sql .= " AND l.tank_id = ?";
    $params[] = $tank_id;
}
$sql .= " ORDER BY l.adjustment_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leakages = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT SUM(variance) as total_loss_liters, SUM(loss_amount) as total_loss_amount, COUNT(*) as total_incidents FROM leakage_adjustments WHERE adjustment_date BETWEEN ? AND ? AND status='approved'");
$stmt->execute([$from_date, $to_date]);
$summary = $stmt->fetch();

$tanks = $pdo->query("SELECT * FROM tanks WHERE is_active = 1")->fetchAll();
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leakage & Wastage Report</title>
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
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .stats-card i {
            font-size: 40px;
            opacity: 0.5;
            float: right;
        }
        .stats-card-danger {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .stats-card-warning {
            background: linear-gradient(135deg, #f6d365 0%, #fda085 100%);
        }
        .stats-card-dark {
            background: linear-gradient(135deg, #434343 0%, #000000 100%);
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
            form, .btn {
                display: none !important;
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
                <h4>Leakage & Wastage Report</h4>
                <p>Period: <?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                <p>Generated on: <?php echo date('d/m/Y h:i:s A'); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap no-print">
                <h2><i class="fas fa-tint"></i> Leakage & Wastage Report</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="reports.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- Date Range Filter -->
            <div class="card mb-4 no-print">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-calendar-alt"></i> Filter by Date Range & Tank</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label>From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label>To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label>Select Tank</label>
                            <select name="tank_id" class="form-control">
                                <option value="">All Tanks</option>
                                <?php foreach($tanks as $t): ?>
                                    <option value="<?php echo $t['id']; ?>" <?php echo $tank_id == $t['id'] ? 'selected' : ''; ?>>
                                        <?php echo $t['tank_name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-info w-100">
                                <i class="fas fa-search"></i> Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stats-card stats-card-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3><?php echo $summary['total_incidents'] ?? 0; ?></h3>
                        <p>Total Incidents</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card stats-card-warning">
                        <i class="fas fa-oil-can"></i>
                        <h3><?php echo number_format($summary['total_loss_liters'] ?? 0, 2); ?> L</h3>
                        <p>Total Loss (Liters)</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card stats-card-dark">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($summary['total_loss_amount'] ?? 0, 2); ?></h3>
                        <p>Total Financial Loss</p>
                    </div>
                </div>
            </div>
            
            <!-- Incident Details Table -->
            <div class="card">
                <div class="card-header bg-danger text-white">
                    <h5><i class="fas fa-list"></i> Incident Details</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="leakageTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Date</th>
                                    <th>Tank</th>
                                    <th>Product</th>
                                    <th class="text-end">System Stock (L)</th>
                                    <th class="text-end">Physical Stock (L)</th>
                                    <th class="text-end">Variance (L)</th>
                                    <th>Type</th>
                                    <th class="text-end">Loss Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($leakages)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted">No leakage records found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($leakages as $l): ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($l['adjustment_date'])); ?></td>
                                        <td><?php echo $l['tank_name']; ?></td>
                                        <td><?php echo $l['product_name']; ?></td>
                                        <td class="text-end"><?php echo number_format($l['system_stock'], 2); ?> L</td>
                                        <td class="text-end"><?php echo number_format($l['physical_stock'], 2); ?> L</td>
                                        <td class="text-end text-danger fw-bold"><?php echo number_format($l['variance'], 2); ?> L</td>
                                        <td>
                                            <span class="badge <?php echo $l['adjustment_type'] == 'leakage' ? 'bg-danger' : ($l['adjustment_type'] == 'wastage' ? 'bg-warning' : 'bg-secondary'); ?>">
                                                <?php echo ucfirst($l['adjustment_type']); ?>
                                            </span>
                                        </td>
                                        <td class="text-end"><?php echo $currency; ?> <?php echo number_format($l['loss_amount'] ?? 0, 2); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $l['status'] == 'approved' ? 'success' : 'warning'; ?>">
                                                <?php echo ucfirst($l['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="5" class="text-end">TOTAL:</td>
                                    <td class="text-end text-danger"><?php echo number_format($summary['total_loss_liters'] ?? 0, 2); ?> L</td>
                                    <td colspan="2" class="text-end"><?php echo $currency; ?> <?php echo number_format($summary['total_loss_amount'] ?? 0, 2); ?></td>
                                    <td></td>
                                </table>
                            </tfoot>
                        </table>
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
            $('#leakageTable').DataTable({
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
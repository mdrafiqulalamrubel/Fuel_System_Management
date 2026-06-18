<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-t');

// Get shift closing data
$stmt = $pdo->prepare("
    SELECT sc.*, sh.shift_name, u1.full_name as opened_by, u2.full_name as closed_by
    FROM shift_closing sc
    JOIN shift_schedule sh ON sc.shift_id = sh.id
    LEFT JOIN users u1 ON sc.opened_by = u1.id
    LEFT JOIN users u2 ON sc.closed_by = u2.id
    WHERE sc.shift_date BETWEEN ? AND ?
    ORDER BY sc.shift_date DESC, sc.opening_time DESC
");
$stmt->execute([$from_date, $to_date]);
$shifts = $stmt->fetchAll();

// Calculate totals
$total_cash_sales = array_sum(array_column($shifts, 'total_cash_sales'));
$total_credit_sales = array_sum(array_column($shifts, 'total_credit_sales'));
$total_cng_sales = array_sum(array_column($shifts, 'total_cng_sales'));
$total_liquid_sales = array_sum(array_column($shifts, 'total_liquid_sales'));
$total_all_sales = array_sum(array_column($shifts, 'total_all_sales'));
$total_receipts = array_sum(array_column($shifts, 'net_cash'));

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Reports</title>
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
        @media print {
            .sidebar, .no-print, .btn, .dataTables_length, .dataTables_filter, .dataTables_paginate {
                display: none !important;
            }
            .main-content { margin: 0 !important; padding: 10px !important; }
            .print-header { display: block !important; text-align: center; margin-bottom: 20px; }
        }
        .print-header { display: none; }
        .badge-open { background: #28a745; color: white; }
        .badge-closed { background: #6c757d; color: white; }
        .badge-verified { background: #17a2b8; color: white; }
        .summary-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .summary-section h6 {
            color: #6c757d;
            font-weight: 600;
        }
        .summary-section .value {
            font-size: 20px;
            font-weight: bold;
        }
        .value-cash { color: #28a745; }
        .value-credit { color: #ffc107; }
        .value-cng { color: #17a2b8; }
        .value-liquid { color: #007bff; }
        .value-total { color: #dc3545; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="print-header">
                <h2><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h2>
                <h4>Shift Report</h4>
                <p>Period: <?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-clock"></i> Shift Report</h2>
                <div class="no-print">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="shift_closing.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- Filter -->
            <div class="card mb-4 no-print">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-calendar-alt"></i> Select Period</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label>From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>">
                        </div>
                        <div class="col-md-4">
                            <label>To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>">
                        </div>
                        <div class="col-md-4">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-info w-100">
                                <i class="fas fa-search"></i> Generate Report
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-clock"></i>
                        <h3><?php echo count($shifts); ?></h3>
                        <p>Total Shifts</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_cash_sales, 2); ?></h3>
                        <p>Total Cash Sales</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-file-invoice"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_credit_sales, 2); ?></h3>
                        <p>Total Credit Sales</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-gas-pump"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_all_sales, 2); ?></h3>
                        <p>Total All Sales</p>
                    </div>
                </div>
            </div>
            
            <!-- Sales Breakdown Summary -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-dark text-white">
                            <h5><i class="fas fa-chart-pie"></i> Sales Breakdown Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="summary-section">
                                        <h6><i class="fas fa-money-bill-wave"></i> Cash Sales</h6>
                                        <div class="value value-cash"><?php echo $currency; ?> <?php echo number_format($total_cash_sales, 2); ?></div>
                                        <small><?php echo count($shifts) > 0 ? number_format($total_cash_sales / count($shifts), 2) : 0; ?> avg per shift</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-section">
                                        <h6><i class="fas fa-file-invoice"></i> Credit Sales</h6>
                                        <div class="value value-credit"><?php echo $currency; ?> <?php echo number_format($total_credit_sales, 2); ?></div>
                                        <small><?php echo count($shifts) > 0 ? number_format($total_credit_sales / count($shifts), 2) : 0; ?> avg per shift</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-section">
                                        <h6><i class="fas fa-gas-pump"></i> CNG Sales</h6>
                                        <div class="value value-cng"><?php echo $currency; ?> <?php echo number_format($total_cng_sales, 2); ?></div>
                                        <small><?php echo count($shifts) > 0 ? number_format($total_cng_sales / count($shifts), 2) : 0; ?> avg per shift</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-section">
                                        <h6><i class="fas fa-oil-can"></i> Liquid Sales</h6>
                                        <div class="value value-liquid"><?php echo $currency; ?> <?php echo number_format($total_liquid_sales, 2); ?></div>
                                        <small><?php echo count($shifts) > 0 ? number_format($total_liquid_sales / count($shifts), 2) : 0; ?> avg per shift</small>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-12">
                                    <div class="summary-section" style="border: 2px solid #dc3545;">
                                        <h6><i class="fas fa-chart-line"></i> GRAND TOTAL</h6>
                                        <div class="value value-total" style="font-size: 28px;"><?php echo $currency; ?> <?php echo number_format($total_all_sales, 2); ?></div>
                                        <small>Total Cash + Credit + CNG + Liquid Sales</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Shift Table -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-list"></i> Shift Details</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="shiftTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Date</th>
                                    <th>Shift</th>
                                    <th>Opened By</th>
                                    <th>Opened At</th>
                                    <th>Closed By</th>
                                    <th class="text-end">Cash Sales</th>
                                    <th class="text-end">Credit Sales</th>
                                    <th class="text-end">CNG Sales</th>
                                    <th class="text-end">Liquid Sales</th>
                                    <th class="text-end">Total Sales</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($shifts as $shift): ?>
                                <tr>
                                    <td><?php echo date('d-m-Y', strtotime($shift['shift_date'])); ?></td>
                                    <td><strong><?php echo $shift['shift_name']; ?></strong></td>
                                    <td><?php echo $shift['opened_by']; ?></td>
                                    <td><?php echo date('h:i A', strtotime($shift['opening_time'])); ?></td>
                                    <td><?php echo $shift['closed_by'] ?: '-'; ?></td>
                                    <td class="text-end text-success"><?php echo $currency; ?> <?php echo number_format($shift['total_cash_sales'], 2); ?></td>
                                    <td class="text-end text-warning"><?php echo $currency; ?> <?php echo number_format($shift['total_credit_sales'], 2); ?></td>
                                    <td class="text-end text-info"><?php echo $currency; ?> <?php echo number_format($shift['total_cng_sales'], 2); ?></td>
                                    <td class="text-end text-primary"><?php echo $currency; ?> <?php echo number_format($shift['total_liquid_sales'], 2); ?></td>
                                    <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($shift['total_all_sales'], 2); ?></td>
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
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_cash_sales, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_credit_sales, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_cng_sales, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_liquid_sales, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_all_sales, 2); ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Shift Summary -->
            <?php if(!empty($shifts)): ?>
            <div class="card mt-4">
                <div class="card-header bg-dark text-white">
                    <h5><i class="fas fa-chart-bar"></i> Shift Summary</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="alert alert-success">
                                <strong>Best Performing Shift</strong><br>
                                <?php 
                                $best_shift = $shifts[0];
                                foreach($shifts as $s) {
                                    if($s['total_all_sales'] > $best_shift['total_all_sales']) {
                                        $best_shift = $s;
                                    }
                                }
                                ?>
                                <span class="h4"><?php echo $best_shift['shift_name']; ?></span><br>
                                <small>Total Sales: <?php echo $currency; ?> <?php echo number_format($best_shift['total_all_sales'], 2); ?></small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="alert alert-info">
                                <strong>Average Sales per Shift</strong><br>
                                <span class="h4"><?php echo $currency; ?> <?php echo number_format(count($shifts) > 0 ? $total_all_sales / count($shifts) : 0, 2); ?></span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="alert alert-warning">
                                <strong>Total Shifts</strong><br>
                                <span class="h4"><?php echo count($shifts); ?></span><br>
                                <small>From <?php echo date('d M Y', strtotime($from_date)); ?> to <?php echo date('d M Y', strtotime($to_date)); ?></small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Product-wise breakdown per shift -->
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <h6><i class="fas fa-th-list"></i> Sales Composition</h6>
                            <table class="table table-sm table-bordered">
                                <thead>
                                    <tr>
                                        <th>Sales Type</th>
                                        <th class="text-end">Amount</th>
                                        <th class="text-end">Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Cash Sales</td>
                                        <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_cash_sales, 2); ?></td>
                                        <td class="text-end"><?php echo $total_all_sales > 0 ? number_format(($total_cash_sales / $total_all_sales) * 100, 1) : 0; ?>%</td>
                                    </tr>
                                    <tr>
                                        <td>Credit Sales</td>
                                        <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_credit_sales, 2); ?></td>
                                        <td class="text-end"><?php echo $total_all_sales > 0 ? number_format(($total_credit_sales / $total_all_sales) * 100, 1) : 0; ?>%</td>
                                    </tr>
                                    <tr>
                                        <td>CNG Sales</td>
                                        <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_cng_sales, 2); ?></td>
                                        <td class="text-end"><?php echo $total_all_sales > 0 ? number_format(($total_cng_sales / $total_all_sales) * 100, 1) : 0; ?>%</td>
                                    </tr>
                                    <tr>
                                        <td>Liquid Sales</td>
                                        <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_liquid_sales, 2); ?></td>
                                        <td class="text-end"><?php echo $total_all_sales > 0 ? number_format(($total_liquid_sales / $total_all_sales) * 100, 1) : 0; ?>%</td>
                                    </tr>
                                    <tr class="table-primary fw-bold">
                                        <td>GRAND TOTAL</td>
                                        <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_all_sales, 2); ?></td>
                                        <td class="text-end">100%</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#shiftTable').DataTable({
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
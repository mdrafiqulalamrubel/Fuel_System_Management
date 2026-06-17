<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$report_type = $_GET['type'] ?? 'customer';
$status = $_GET['status'] ?? 'active';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-t');

// Customer advances
if($report_type == 'customer') {
    $stmt = $pdo->prepare("
        SELECT ap.*, c.customer_name, c.customer_code, c.phone, c.address
        FROM advance_payments_customer ap
        JOIN customers c ON ap.customer_id = c.id
        WHERE ap.status = ? AND ap.advance_date BETWEEN ? AND ?
        ORDER BY ap.advance_date DESC
    ");
    $stmt->execute([$status, $from_date, $to_date]);
    $advances = $stmt->fetchAll();
    $title = "Customer Advance Report";
    $total_amount = array_sum(array_column($advances, 'amount'));
    $total_used = array_sum(array_column($advances, 'used_amount'));
    $total_balance = array_sum(array_column($advances, 'balance_amount'));
} else {
    // Supplier advances
    $stmt = $pdo->prepare("
        SELECT ap.*, s.supplier_name, s.supplier_code, s.phone, s.address
        FROM advance_payments_supplier ap
        JOIN suppliers s ON ap.supplier_id = s.id
        WHERE ap.status = ? AND ap.advance_date BETWEEN ? AND ?
        ORDER BY ap.advance_date DESC
    ");
    $stmt->execute([$status, $from_date, $to_date]);
    $advances = $stmt->fetchAll();
    $title = "Supplier Advance Report";
    $total_amount = array_sum(array_column($advances, 'amount'));
    $total_used = array_sum(array_column($advances, 'used_amount'));
    $total_balance = array_sum(array_column($advances, 'balance_amount'));
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advance Payment Report</title>
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
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="print-header">
                <h2><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h2>
                <h4><?php echo $title; ?></h4>
                <p>Period: <?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                <p>Status: <?php echo ucfirst($status); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-file-invoice"></i> <?php echo $title; ?></h2>
                <div class="no-print">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="advance_management.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- Filter -->
            <div class="card mb-4 no-print">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-filter"></i> Filter Report</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label>Report Type</label>
                            <select name="type" class="form-control">
                                <option value="customer" <?php echo $report_type == 'customer' ? 'selected' : ''; ?>>Customer Advances</option>
                                <option value="supplier" <?php echo $report_type == 'supplier' ? 'selected' : ''; ?>>Supplier Advances</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="fully_used" <?php echo $status == 'fully_used' ? 'selected' : ''; ?>>Fully Used</option>
                                <option value="cancelled" <?php echo $status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>">
                        </div>
                        <div class="col-md-2">
                            <label>To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>">
                        </div>
                        <div class="col-md-2">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-info w-100">
                                <i class="fas fa-search"></i> Generate
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="row">
                <div class="col-md-4">
                    <div class="stats-card">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_amount, 2); ?></h3>
                        <p>Total Advance Amount</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-check-circle"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_used, 2); ?></h3>
                        <p>Total Used</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-clock"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_balance, 2); ?></h3>
                        <p>Total Balance</p>
                    </div>
                </div>
            </div>
            
            <!-- Table -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-list"></i> Advance Details</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="advanceTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Date</th>
                                    <th><?php echo $report_type == 'customer' ? 'Customer' : 'Supplier'; ?></th>
                                    <th>Amount</th>
                                    <th>Used</th>
                                    <th>Balance</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($advances as $adv): ?>
                                <tr>
                                    <td><?php echo date('d-m-Y', strtotime($adv['advance_date'])); ?></td>
                                    <td>
                                        <strong><?php echo $report_type == 'customer' ? $adv['customer_name'] : $adv['supplier_name']; ?></strong>
                                        <br><small><?php echo $report_type == 'customer' ? $adv['customer_code'] : $adv['supplier_code']; ?></small>
                                    </td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($adv['amount'], 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($adv['used_amount'], 2); ?></td>
                                    <td class="text-end fw-bold <?php echo $adv['balance_amount'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                        <?php echo $currency; ?> <?php echo number_format($adv['balance_amount'], 2); ?>
                                    </td>
                                    <td><?php echo ucfirst($adv['payment_method']); ?></td>
                                    <td>
                                        <?php if($adv['status'] == 'active'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php elseif($adv['status'] == 'fully_used'): ?>
                                            <span class="badge bg-warning">Used</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Cancelled</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="2" class="text-end">TOTAL:</td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_amount, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_used, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_balance, 2); ?></td>
                                    <td colspan="2"></td>
                                </tr>
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
            $('#advanceTable').DataTable({
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
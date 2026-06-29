<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$as_on = $_GET['as_on'] ?? date('Y-m-d');

$accounts = $pdo->query("SELECT * FROM chart_of_accounts WHERE is_active = 1 ORDER BY account_type, account_code")->fetchAll();

$trial_balance = [];
foreach($accounts as $acc) {
    $stmt = $pdo->prepare("SELECT SUM(debit_amount) as total_debit, SUM(credit_amount) as total_credit FROM voucher_items vi JOIN vouchers v ON vi.voucher_id = v.id WHERE vi.account_id = ? AND v.date <= ? AND v.status = 'approved'");
    $stmt->execute([$acc['id'], $as_on]);
    $row = $stmt->fetch();
    $total_debit = $row['total_debit'] ?? 0;
    $total_credit = $row['total_credit'] ?? 0;
    
    $opening = $acc['opening_balance'];
    if($acc['balance_type'] == 'debit') {
        $debit_balance = $opening + ($total_debit - $total_credit);
        $credit_balance = 0;
    } else {
        $credit_balance = $opening + ($total_credit - $total_debit);
        $debit_balance = 0;
    }
    
    if($debit_balance != 0 || $credit_balance != 0) {
        $trial_balance[] = [
            'id' => $acc['id'],
            'code' => $acc['account_code'],
            'name' => $acc['account_name'],
            'type' => $acc['account_type'],
            'debit' => $debit_balance,
            'credit' => $credit_balance
        ];
    }
}

$total_debit = array_sum(array_column($trial_balance, 'debit'));
$total_credit = array_sum(array_column($trial_balance, 'credit'));
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trial Balance</title>
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
        .clickable-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .clickable-row:hover {
            background-color: #e8f0fe !important;
        }
        .clickable-row td:first-child {
            color: #007bff;
            font-weight: 500;
        }
        .clickable-row td:first-child:hover {
            text-decoration: underline;
        }
        @media print {
            .sidebar, .no-print, .stats-card, .btn, .dataTables_length, .dataTables_filter, .dataTables_paginate {
                display: none !important;
            }
            .main-content { margin: 0 !important; padding: 10px !important; }
            .print-header { display: block !important; text-align: center; margin-bottom: 20px; }
        }
        .print-header { display: none; }

        /* ============================================= */
        /* PRINT STYLES - PLAIN PAPER, LANDSCAPE, 12px */
        /* ============================================= */
        @media print {
            .sidebar, .no-print, .stats-card, .btn, .card-header .btn, 
            form, .dataTables_length, .dataTables_filter, .dataTables_paginate,
            .dataTables_info {
                display: none !important;
            }
            
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 15px;
                border-bottom: 2px solid #000;
                padding-bottom: 10px;
            }
            
            .print-header h2 {
                font-size: 18px;
                font-weight: bold;
                margin-bottom: 2px;
                color: #000 !important;
            }
            
            .print-header h4 {
                font-size: 14px;
                margin-bottom: 2px;
                color: #000 !important;
            }
            
            .print-header p {
                font-size: 11px;
                margin-bottom: 2px;
                color: #000 !important;
            }
            
            .print-header .print-date {
                font-size: 10px;
                color: #000 !important;
            }
            
            .main-content {
                margin: 0 !important;
                padding: 5px !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            
            .container-fluid {
                padding: 0 !important;
                max-width: 100% !important;
            }
            
            .card {
                border: 1px solid #000 !important;
                margin-bottom: 8px !important;
                border-radius: 0 !important;
                box-shadow: none !important;
            }
            
            .card-header {
                border-bottom: 1px solid #000 !important;
                padding: 5px 8px !important;
                font-weight: bold;
                background: #fff !important;
                color: #000 !important;
            }
            
            .card-header h4, .card-header h5 {
                font-size: 14px !important;
                margin: 0 !important;
                color: #000 !important;
            }
            
            .card-body {
                padding: 5px 8px !important;
            }
            
            .bg-primary, .bg-success, .bg-info, .bg-warning, .bg-danger,
            .bg-secondary, .bg-light, .bg-white, .table-dark {
                background: #fff !important;
                color: #000 !important;
                border-color: #000 !important;
            }
            
            .text-white, .text-white-50 { color: #000 !important; }
            .text-success, .text-danger, .text-warning, .text-info, .text-primary {
                color: #000 !important;
            }
            
            .table {
                border-collapse: collapse !important;
                width: 100% !important;
                font-size: 12px !important;
                margin: 0 !important;
            }
            
            .table th, .table td {
                border: 1px solid #000 !important;
                padding: 4px 6px !important;
                background: #fff !important;
                color: #000 !important;
                font-size: 12px !important;
            }
            
            .table th {
                background: #f8f9fa !important;
                font-weight: bold !important;
                border-bottom: 2px solid #000 !important;
            }
            
            .table thead th {
                background: #f8f9fa !important;
                border-bottom: 2px solid #000 !important;
                font-size: 12px !important;
            }
            
            .table tfoot th, .table tfoot td {
                background: #f8f9fa !important;
                border-top: 2px solid #000 !important;
                font-weight: bold !important;
                font-size: 12px !important;
            }
            
            .table-responsive {
                overflow: visible !important;
            }
            
            .alert {
                border: 1px solid #000 !important;
                background: #fff !important;
                color: #000 !important;
            }
            
            .clickable-row td:first-child {
                color: #000 !important;
                text-decoration: none !important;
            }
            
            .badge {
                border: 1px solid #000 !important;
                background: #fff !important;
                color: #000 !important;
                padding: 1px 6px !important;
                font-size: 11px !important;
                border-radius: 2px !important;
            }
            
            .stats-card {
                background: #fff !important;
                border: 1px solid #000 !important;
                color: #000 !important;
            }
            
            .stats-card i {
                opacity: 0.5 !important;
            }
            
            .footer-note, .text-center small {
                border-top: 1px solid #000 !important;
                margin-top: 8px !important;
                padding-top: 4px !important;
                font-size: 10px !important;
                text-align: center !important;
                color: #000 !important;
            }
            
            @page {
                size: landscape;
                margin: 6mm 4mm;
            }
            
            ::-webkit-scrollbar { display: none; }
            
            .col-md-6, .col-md-3 {
                flex: 0 0 100% !important;
                max-width: 100% !important;
                width: 100% !important;
            }
            
            .row {
                margin: 0 !important;
            }
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="print-header">
                <h2><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h2>
                <h4>Trial Balance</h4>
                <p>As on: <?php echo date('d F Y', strtotime($as_on)); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap no-print">
                <h2><i class="fas fa-list-ul"></i> Trial Balance</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="accounting.php?tab=reports" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- Filter -->
            <div class="card mb-4 no-print">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-calendar-alt"></i> Select Date</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
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
                <div class="col-md-6">
                    <div class="stats-card">
                        <i class="fas fa-arrow-right"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_debit, 2); ?></h3>
                        <p>Total Debit Balance</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-arrow-left"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_credit, 2); ?></h3>
                        <p>Total Credit Balance</p>
                    </div>
                </div>
            </div>
            
            <!-- Trial Balance Table -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-list"></i> Trial Balance as on <?php echo date('d F Y', strtotime($as_on)); ?></h5>
                    <small class="d-block text-light">Click on any account to view detailed ledger</small>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="tbTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Account Code</th>
                                    <th>Account Name</th>
                                    <th>Account Type</th>
                                    <th class="text-end">Debit (<?php echo $currency; ?>)</th>
                                    <th class="text-end">Credit (<?php echo $currency; ?>)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($trial_balance as $tb): ?>
                                <tr class="clickable-row" 
                                    onclick="window.open('general_ledger.php?account_id=<?php echo $tb['id']; ?>&from_date=<?php echo date('Y-01-01'); ?>&to_date=<?php echo $as_on; ?>', '_blank')"
                                    title="Click to view ledger for <?php echo $tb['name']; ?>">
                                    <td><?php echo $tb['code']; ?></td>
                                    <td><strong><?php echo $tb['name']; ?></strong></td>
                                    <td><?php echo ucfirst($tb['type']); ?></td>
                                    <td class="text-end"><?php echo $tb['debit'] > 0 ? number_format($tb['debit'], 2) : '-'; ?></td>
                                    <td class="text-end"><?php echo $tb['credit'] > 0 ? number_format($tb['credit'], 2) : '-'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="3" class="text-end">TOTAL:</td>
                                    <td class="text-end"><?php echo number_format($total_debit, 2); ?></td>
                                    <td class="text-end"><?php echo number_format($total_credit, 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Verification Message -->
            <?php if(abs($total_debit - $total_credit) < 0.01): ?>
                <div class="alert alert-success mt-3 text-center no-print">
                    <i class="fas fa-check-circle"></i> ✅ Trial Balance is balanced! Debit = Credit
                </div>
            <?php else: ?>
                <div class="alert alert-danger mt-3 text-center no-print">
                    <i class="fas fa-exclamation-triangle"></i> ⚠️ Trial Balance is NOT balanced! Difference: <?php echo $currency; ?> <?php echo number_format(abs($total_debit - $total_credit), 2); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#tbTable').DataTable({
                order: [[0, 'asc']],
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
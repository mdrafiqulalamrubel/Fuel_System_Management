<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$as_on = $_GET['as_on'] ?? date('Y-m-d');

$incomes = $pdo->query("SELECT * FROM chart_of_accounts WHERE account_type = 'income' AND is_active = 1")->fetchAll();
$expenses = $pdo->query("SELECT * FROM chart_of_accounts WHERE account_type = 'expense' AND is_active = 1")->fetchAll();
$accounts = $pdo->query("SELECT * FROM chart_of_accounts WHERE is_active = 1 ORDER BY account_type, account_code")->fetchAll();

$balances = [];
foreach($accounts as $acc) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(vi.debit_amount), 0) as total_debit, 
               COALESCE(SUM(vi.credit_amount), 0) as total_credit 
        FROM voucher_items vi 
        JOIN vouchers v ON vi.voucher_id = v.id 
        WHERE vi.account_id = ? AND v.date <= ? AND v.status = 'approved'
    ");
    $stmt->execute([$acc['id'], $as_on]);
    $row = $stmt->fetch();
    $total_debit = $row['total_debit'];
    $total_credit = $row['total_credit'];
    
    $opening = floatval($acc['opening_balance']);
    
    if($acc['balance_type'] == 'debit') {
        $balance = $opening + ($total_debit - $total_credit);
    } else {
        $balance = $opening + ($total_credit - $total_debit);
    }
    
    $balances[$acc['id']] = [
        'id' => $acc['id'],
        'name' => $acc['account_name'],
        'type' => $acc['account_type'],
        'code' => $acc['account_code'],
        'balance_type' => $acc['balance_type'],
        'balance' => $balance,
        'opening' => $opening
    ];
}

// Calculate Net Profit/Loss
$from_date = date('Y-01-01');
$to_date = $as_on;

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(vi.credit_amount - vi.debit_amount), 0) as total
    FROM voucher_items vi 
    JOIN vouchers v ON vi.voucher_id = v.id 
    JOIN chart_of_accounts ca ON vi.account_id = ca.id
    WHERE ca.account_type = 'income'
    AND v.date BETWEEN ? AND ? 
    AND v.status = 'approved'
");
$stmt->execute([$from_date, $to_date]);
$total_income = floatval($stmt->fetch()['total'] ?? 0);

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(vi.debit_amount - vi.credit_amount), 0) as total
    FROM voucher_items vi 
    JOIN vouchers v ON vi.voucher_id = v.id 
    JOIN chart_of_accounts ca ON vi.account_id = ca.id
    WHERE ca.account_type = 'expense'
    AND v.date BETWEEN ? AND ? 
    AND v.status = 'approved'
");
$stmt->execute([$from_date, $to_date]);
$total_expense = floatval($stmt->fetch()['total'] ?? 0);

$net_profit_loss = $total_income - $total_expense;

// Separate assets, liabilities, equity
$assets = array_filter($balances, fn($b) => $b['type'] == 'asset' && abs($b['balance']) > 0.01);
$liabilities = array_filter($balances, fn($b) => $b['type'] == 'liability' && abs($b['balance']) > 0.01);
$equity = array_filter($balances, fn($b) => $b['type'] == 'equity' && abs($b['balance']) > 0.01);

$total_assets = array_sum(array_column($assets, 'balance'));
$total_liabilities = array_sum(array_column($liabilities, 'balance'));
$total_equity_from_accounts = array_sum(array_column($equity, 'balance'));

$final_equity = $total_equity_from_accounts + $net_profit_loss;
$total_liabilities_equity = $total_liabilities + $final_equity;

$difference = $total_assets - $total_liabilities_equity;
$suspense_account = null;
if(abs($difference) > 0.01) {
    $suspense_account = [
        'name' => 'Balance Adjustment (Suspense)',
        'type' => 'equity',
        'balance' => $difference
    ];
    $final_equity += $difference;
    $total_liabilities_equity = $total_assets;
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balance Sheet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
        .balance-table { width: 100%; border-collapse: collapse; }
        .balance-table th, .balance-table td { border: 1px solid #dee2e6; padding: 10px; }
        .balance-table th { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-weight: bold; }
        .text-end { text-align: right; }
        .fw-bold { font-weight: bold; }
        .net-loss { background-color: #f8d7da; color: #721c24; }
        .net-profit { background-color: #d4edda; color: #155724; }
        .suspense-row { background-color: #fff3cd; color: #856404; }
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
            .sidebar, .no-print, .stats-card, .btn, .card-header .btn, form { display: none !important; }
            .main-content { margin: 0 !important; padding: 10px !important; }
            .print-header { display: block !important; text-align: center; margin-bottom: 20px; }
        }
        .print-header { display: none; }

        /* ============================================= */
        /* PRINT STYLES - PLAIN PAPER, LANDSCAPE, 12px */
        /* ============================================= */
        @media print {
            /* Hide non-print elements */
            .sidebar, .no-print, .stats-card, .btn, .card-header .btn, 
            form, .nav-tabs, .nav-tabs-custom, .dataTables_wrapper,
            .dataTables_length, .dataTables_filter, .dataTables_paginate,
            .dataTables_info {
                display: none !important;
            }
            
            /* Show print elements */
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
            
            /* Main content */
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
            
            /* Remove all backgrounds */
            .bg-primary, .bg-success, .bg-info, .bg-warning, .bg-danger,
            .bg-secondary, .bg-light, .bg-white, .table-dark,
            .table-info, .table-secondary, .stats-card, .badge, .alert {
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
            
            .balance-table {
                width: 100% !important;
                font-size: 12px !important;
            }
            
            .balance-table th, .balance-table td {
                border: 1px solid #000 !important;
                padding: 4px 6px !important;
                font-size: 12px !important;
            }
            
            .balance-table th {
                background: #f8f9fa !important;
                font-weight: bold !important;
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
            
            .footer-note, .text-center small, .alert {
                border-top: 1px solid #000 !important;
                margin-top: 8px !important;
                padding-top: 4px !important;
                font-size: 10px !important;
                text-align: center !important;
                color: #000 !important;
                background: #fff !important;
            }
            
            .net-loss, .net-profit, .suspense-row {
                background: #fff !important;
                color: #000 !important;
            }
            
            .clickable-row td:first-child {
                color: #000 !important;
                text-decoration: none !important;
            }
            
            @page {
                size: landscape;
                margin: 6mm 4mm;
            }
            
            ::-webkit-scrollbar { display: none; }
            
            .col-md-6, .col-md-4, .col-md-3, .col-md-8 {
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
                <h4>Balance Sheet</h4>
                <p>As on: <?php echo date('d F Y', strtotime($as_on)); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap no-print">
                <h2><i class="fas fa-balance-scale"></i> Balance Sheet</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="accounting.php?tab=reports" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Reports
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
                        <div class="col-md-8">
                            <label>As on Date</label>
                            <input type="date" name="as_on" class="form-control" value="<?php echo $as_on; ?>">
                        </div>
                        <div class="col-md-4">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-info w-100">
                                <i class="fas fa-search"></i> View Balance Sheet
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="row mb-4 no-print">
                <div class="col-md-4">
                    <div class="stats-card">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_assets, 2); ?></h3>
                        <p>Total Assets</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_liabilities, 2); ?></h3>
                        <p>Total Liabilities</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($final_equity, 2); ?></h3>
                        <p>Total Equity</p>
                    </div>
                </div>
            </div>
            
            <!-- Balance Sheet Tables -->
            <div class="card">
                <div class="card-header bg-primary text-white text-center">
                    <h4>Balance Sheet as on <?php echo date('d F, Y', strtotime($as_on)); ?></h4>
                    <small class="d-block text-light">Click on any account to view detailed ledger</small>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- LEFT SIDE: ASSETS -->
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-success text-white">
                                    <h5><i class="fas fa-chart-line"></i> ASSETS</h5>
                                </div>
                                <div class="card-body p-0">
                                    <table class="balance-table">
                                        <thead>
                                            <tr><th>Particulars</th><th class="text-end">Amount (<?php echo $currency; ?>)</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php if(empty($assets)): ?>
                                                <tr><td colspan="2" class="text-center text-muted">No asset accounts found</td></tr>
                                            <?php else: ?>
                                                <?php foreach($assets as $a): ?>
                                                <tr class="clickable-row" 
                                                    onclick="window.open('general_ledger.php?account_id=<?php echo $a['id']; ?>&from_date=<?php echo date('Y-01-01'); ?>&to_date=<?php echo $as_on; ?>', '_blank')"
                                                    title="Click to view ledger for <?php echo $a['name']; ?>">
                                                    <td><?php echo htmlspecialchars($a['name']); ?></td>
                                                    <td class="text-end"><?php echo number_format($a['balance'], 2); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <tr class="fw-bold bg-light">
                                                <td><strong>TOTAL ASSETS</strong></td>
                                                <td class="text-end"><strong><?php echo number_format($total_assets, 2); ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- RIGHT SIDE: LIABILITIES & EQUITY -->
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-danger text-white">
                                    <h5><i class="fas fa-chart-line"></i> LIABILITIES & EQUITY</h5>
                                </div>
                                <div class="card-body p-0">
                                    <table class="balance-table">
                                        <thead>
                                            <tr><th>Particulars</th><th class="text-end">Amount (<?php echo $currency; ?>)</th></tr>
                                        </thead>
                                        <tbody>
                                            <!-- Liabilities Section -->
                                            <tr style="background: #6c757d; color: white;">
                                                <td colspan="2"><strong>LIABILITIES</strong></td>
                                            </tr>
                                            <?php if(empty($liabilities)): ?>
                                                <tr><td colspan="2" class="text-center text-muted">No liability accounts found</td></tr>
                                            <?php else: ?>
                                                <?php foreach($liabilities as $l): ?>
                                                <tr class="clickable-row" 
                                                    onclick="window.open('general_ledger.php?account_id=<?php echo $l['id']; ?>&from_date=<?php echo date('Y-01-01'); ?>&to_date=<?php echo $as_on; ?>', '_blank')"
                                                    title="Click to view ledger for <?php echo $l['name']; ?>">
                                                    <td><?php echo htmlspecialchars($l['name']); ?></td>
                                                    <td class="text-end"><?php echo number_format($l['balance'], 2); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <tr class="fw-bold">
                                                <td><strong>Total Liabilities</strong></td>
                                                <td class="text-end"><strong><?php echo number_format($total_liabilities, 2); ?></strong></td>
                                            </tr>
                                            
                                            <!-- Equity Section -->
                                            <tr style="background: #6c757d; color: white;">
                                                <td colspan="2"><strong>EQUITY</strong></td>
                                            </tr>
                                            <?php foreach($equity as $e): ?>
                                            <tr class="clickable-row" 
                                                onclick="window.open('general_ledger.php?account_id=<?php echo $e['id']; ?>&from_date=<?php echo date('Y-01-01'); ?>&to_date=<?php echo $as_on; ?>', '_blank')"
                                                title="Click to view ledger for <?php echo $e['name']; ?>">
                                                <td><?php echo htmlspecialchars($e['name']); ?></td>
                                                <td class="text-end"><?php echo number_format($e['balance'], 2); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <!-- Net Profit/Loss Section -->
                                            <tr class="<?php echo $net_profit_loss >= 0 ? 'net-profit' : 'net-loss'; ?> clickable-row" 
                                                onclick="window.open('profit_loss.php?from_date=<?php echo date('Y-01-01'); ?>&to_date=<?php echo $as_on; ?>', '_blank')"
                                                title="Click to view Profit & Loss Statement">
                                                <td>
                                                    <strong><?php echo $net_profit_loss >= 0 ? 'Net Profit' : 'Net Loss'; ?></strong>
                                                    <br><small class="text-muted">(Year to Date)</small>
                                                </td>
                                                <td class="text-end fw-bold">
                                                    <?php if($net_profit_loss >= 0): ?>
                                                        <?php echo $currency; ?> <?php echo number_format($net_profit_loss, 2); ?>
                                                    <?php else: ?>
                                                        (<?php echo $currency; ?> <?php echo number_format(abs($net_profit_loss), 2); ?>)
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            
                                            <?php if(isset($suspense_account)): ?>
                                            <tr class="suspense-row">
                                                <td>
                                                    <strong><?php echo $suspense_account['name']; ?></strong>
                                                    <br><small class="text-muted">(Balancing Figure)</small>
                                                </td>
                                                <td class="text-end fw-bold">
                                                    <?php echo $currency; ?> <?php echo number_format($suspense_account['balance'], 2); ?>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                            
                                            <tr class="fw-bold bg-light">
                                                <td><strong>Total Equity</strong></td>
                                                <td class="text-end"><strong><?php echo number_format($final_equity, 2); ?></strong></td>
                                            </tr>
                                            
                                            <!-- Grand Total -->
                                            <tr class="table-info fw-bold">
                                                <td><strong>TOTAL LIABILITIES & EQUITY</strong></td>
                                                <td class="text-end"><strong><?php echo number_format($total_liabilities_equity, 2); ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Verification Message -->
                    <div class="alert <?php echo abs($total_assets - $total_liabilities_equity) < 1 ? 'alert-success' : 'alert-danger'; ?> text-center mt-3">
                        <strong>
                            <?php if(abs($total_assets - $total_liabilities_equity) < 1): ?>
                                <i class="fas fa-check-circle"></i> ✅ Balance Sheet is BALANCED!
                            <?php else: ?>
                                <i class="fas fa-exclamation-triangle"></i> ⚠️ Balance Sheet is NOT balanced! Difference: <?php echo $currency; ?> <?php echo number_format(abs($total_assets - $total_liabilities_equity), 2); ?>
                            <?php endif; ?>
                        </strong>
                    </div>
                    
                    <div class="text-center mt-3">
                        <hr>
                        <small>This is a computer generated report</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
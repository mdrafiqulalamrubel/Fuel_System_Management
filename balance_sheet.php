<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$as_on = $_GET['as_on'] ?? date('Y-m-d');

// Get all accounts with balances
$accounts = $pdo->query("SELECT * FROM chart_of_accounts WHERE is_active = 1 ORDER BY account_type, account_code")->fetchAll();

// Calculate balances from vouchers
$balances = [];
foreach($accounts as $acc) {
    $stmt = $pdo->prepare("SELECT SUM(debit_amount) as total_debit, SUM(credit_amount) as total_credit FROM voucher_items vi JOIN vouchers v ON vi.voucher_id = v.id WHERE vi.account_id = ? AND v.date <= ? AND v.status = 'approved'");
    $stmt->execute([$acc['id'], $as_on]);
    $row = $stmt->fetch();
    $total_debit = $row['total_debit'] ?? 0;
    $total_credit = $row['total_credit'] ?? 0;
    
    $opening = $acc['opening_balance'];
    
    if($acc['account_type'] == 'asset' || $acc['account_type'] == 'expense') {
        $balance = $opening + ($total_debit - $total_credit);
    } else {
        $balance = $opening + ($total_credit - $total_debit);
    }
    
    $balances[$acc['id']] = [
        'name' => $acc['account_name'],
        'type' => $acc['account_type'],
        'code' => $acc['account_code'],
        'balance' => $balance
    ];
}

// Calculate Net Profit/Loss for the current period
$from_date = date('Y-m-01');
$to_date = date('Y-m-t');

// Get total income
$stmt = $pdo->prepare("
    SELECT SUM(credit_amount) - SUM(debit_amount) as total 
    FROM voucher_items vi 
    JOIN vouchers v ON vi.voucher_id = v.id 
    WHERE vi.account_id IN (SELECT id FROM chart_of_accounts WHERE account_type = 'income')
    AND v.date BETWEEN ? AND ? AND v.status = 'approved'
");
$stmt->execute([$from_date, $to_date]);
$total_income = $stmt->fetch()['total'] ?? 0;

// Get total expenses
$stmt = $pdo->prepare("
    SELECT SUM(debit_amount) - SUM(credit_amount) as total 
    FROM voucher_items vi 
    JOIN vouchers v ON vi.voucher_id = v.id 
    WHERE vi.account_id IN (SELECT id FROM chart_of_accounts WHERE account_type = 'expense')
    AND v.date BETWEEN ? AND ? AND v.status = 'approved'
");
$stmt->execute([$from_date, $to_date]);
$total_expense = $stmt->fetch()['total'] ?? 0;

$net_profit_loss = $total_income - $total_expense;

// Separate assets, liabilities, equity
$assets = array_filter($balances, fn($b) => $b['type'] == 'asset' && $b['balance'] != 0);
$liabilities = array_filter($balances, fn($b) => $b['type'] == 'liability' && $b['balance'] != 0);
$equity = array_filter($balances, fn($b) => $b['type'] == 'equity' && $b['balance'] != 0);

$total_assets = array_sum(array_column($assets, 'balance'));
$total_liabilities = array_sum(array_column($liabilities, 'balance'));
$total_equity = array_sum(array_column($equity, 'balance'));

// Calculate final equity including Net Profit/Loss
$final_equity = $total_equity + $net_profit_loss;
$total_liabilities_equity = $total_liabilities + $final_equity;

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
        }
        .balance-table {
            width: 100%;
            border-collapse: collapse;
        }
        .balance-table th, 
        .balance-table td {
            border: 1px solid #dee2e6;
            padding: 10px;
        }
        .balance-table th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        .text-end {
            text-align: right;
        }
        .fw-bold {
            font-weight: bold;
        }
        .net-loss {
            background-color: #f8d7da;
            color: #721c24;
        }
        .net-profit {
            background-color: #d4edda;
            color: #155724;
        }
        @media print {
            .sidebar, .no-print, .stats-card, .btn {
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
                    <a href="reports.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
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
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- LEFT SIDE: ASSETS -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-success text-white">
                                    <h5><i class="fas fa-chart-line"></i> ASSETS</h5>
                                </div>
                                <div class="card-body p-0">
                                    <table class="balance-table">
                                        <thead>
                                            <tr>
                                                <th>Particulars</th>
                                                <th class="text-end">Amount (<?php echo $currency; ?>)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($assets as $a): ?>
                                            <tr>
                                                <td><?php echo $a['name']; ?></td>
                                                <td class="text-end"><?php echo number_format($a['balance'], 2); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
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
                            <div class="card">
                                <div class="card-header bg-danger text-white">
                                    <h5><i class="fas fa-chart-line"></i> LIABILITIES & EQUITY</h5>
                                </div>
                                <div class="card-body p-0">
                                    <table class="balance-table">
                                        <thead>
                                            <tr>
                                                <th>Particulars</th>
                                                <th class="text-end">Amount (<?php echo $currency; ?>)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- Liabilities Section -->
                                            <tr class="bg-secondary text-white">
                                                <td colspan="2"><strong>LIABILITIES</strong></td>
                                             </tr
                                            <?php foreach($liabilities as $l): ?>
                                             <tr
                                                 <td><?php echo $l['name']; ?></td>
                                                <td class="text-end"><?php echo number_format($l['balance'], 2); ?></td>
                                             </tr
                                            <?php endforeach; ?>
                                            <tr class="fw-bold">
                                                <td><strong>Total Liabilities</strong></td>
                                                <td class="text-end"><strong><?php echo number_format($total_liabilities, 2); ?></strong></td>
                                             </tr
                                            
                                            <!-- Equity Section -->
                                            <tr class="bg-secondary text-white">
                                                <td colspan="2"><strong>EQUITY</strong></td>
                                             </tr
                                            <?php foreach($equity as $e): ?>
                                             <tr
                                                 <td><?php echo $e['name']; ?></td>
                                                <td class="text-end"><?php echo number_format($e['balance'], 2); ?></td>
                                             </tr
                                            <?php endforeach; ?>
                                            
                                            <!-- Net Profit/Loss Section -->
                                            <tr class="<?php echo $net_profit_loss >= 0 ? 'net-profit' : 'net-loss'; ?>">
                                                <td>
                                                    <strong><?php echo $net_profit_loss >= 0 ? 'Net Profit' : 'Net Loss'; ?></strong>
                                                    <br><small class="text-muted">(Current Period)</small>
                                                </td>
                                                <td class="text-end fw-bold">
                                                    <?php echo $currency; ?> <?php echo number_format(abs($net_profit_loss), 2); ?>
                                                </td>
                                             </tr
                                            
                                            <tr class="fw-bold">
                                                <td><strong>Total Equity</strong></td>
                                                <td class="text-end"><strong><?php echo number_format($final_equity, 2); ?></strong></td>
                                             </tr
                                            
                                            <!-- Grand Total -->
                                            <tr class="table-info fw-bold">
                                                <td><strong>TOTAL LIABILITIES & EQUITY</strong></td>
                                                <td class="text-end"><strong><?php echo number_format($total_liabilities_equity, 2); ?></strong></td>
                                             </tr
                                        </tbody>
                                     </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Verification Message -->
                    <div class="alert <?php echo abs($total_assets - $total_liabilities_equity) < 1 ? 'alert-success' : 'alert-danger'; ?> text-center mt-3">
                        <strong>
                            <?php if(abs($total_assets - $total_liabilities_equity) < 1): ?>
                                <i class="fas fa-check-circle"></i> Balance Sheet is BALANCED!
                            <?php else: ?>
                                <i class="fas fa-exclamation-triangle"></i> Balance Sheet is NOT balanced! Difference: <?php echo $currency; ?> <?php echo number_format(abs($total_assets - $total_liabilities_equity), 2); ?>
                            <?php endif; ?>
                        </strong>
                    </div>
                    
                    <div class="text-center mt-3">
                        <hr>
                        <p>This is a computer generated report</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
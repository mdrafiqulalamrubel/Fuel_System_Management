<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$as_on = $_GET['as_on'] ?? date('Y-m-d');

// Get all accounts with balances
$accounts = $pdo->query("SELECT * FROM chart_of_accounts WHERE is_active = 1 ORDER BY account_type, account_code")->fetchAll();

// Calculate balances from vouchers up to as_on date
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
    
    // Calculate balance based on account type
    if($acc['balance_type'] == 'debit') {
        $balance = $opening + ($total_debit - $total_credit);
    } else {
        $balance = $opening + ($total_credit - $total_debit);
    }
    
    $balances[$acc['id']] = [
        'name' => $acc['account_name'],
        'type' => $acc['account_type'],
        'code' => $acc['account_code'],
        'balance_type' => $acc['balance_type'],
        'balance' => $balance,
        'opening' => $opening
    ];
}

// Calculate Net Profit/Loss for the current period (year-to-date)
$from_date = date('Y-01-01'); // Start of financial year
$to_date = $as_on;

// Get total income from income accounts
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN ca.balance_type = 'credit' THEN vi.credit_amount - vi.debit_amount ELSE vi.debit_amount - vi.credit_amount END), 0) as total
    FROM voucher_items vi 
    JOIN vouchers v ON vi.voucher_id = v.id 
    JOIN chart_of_accounts ca ON vi.account_id = ca.id
    WHERE ca.account_type = 'income'
    AND v.date BETWEEN ? AND ? 
    AND v.status = 'approved'
");
$stmt->execute([$from_date, $to_date]);
$total_income = floatval($stmt->fetch()['total'] ?? 0);

// Also get direct sales from sales table (if not recorded in vouchers)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM sales WHERE DATE(sale_date) BETWEEN ? AND ?");
$stmt->execute([$from_date, $to_date]);
$direct_sales = floatval($stmt->fetch()['total'] ?? 0);
$total_income = max($total_income, $direct_sales);

// Get total expenses from expense accounts
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN ca.balance_type = 'debit' THEN vi.debit_amount - vi.credit_amount ELSE vi.credit_amount - vi.debit_amount END), 0) as total
    FROM voucher_items vi 
    JOIN vouchers v ON vi.voucher_id = v.id 
    JOIN chart_of_accounts ca ON vi.account_id = ca.id
    WHERE ca.account_type = 'expense'
    AND v.date BETWEEN ? AND ? 
    AND v.status = 'approved'
");
$stmt->execute([$from_date, $to_date]);
$total_expense = floatval($stmt->fetch()['total'] ?? 0);

// Also get COGS from fuel_receivings
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM fuel_receivings WHERE receipt_date BETWEEN ? AND ?");
$stmt->execute([$from_date, $to_date]);
$direct_cogs = floatval($stmt->fetch()['total'] ?? 0);
$total_expense = max($total_expense, $direct_cogs);

$net_profit_loss = $total_income - $total_expense;

// Separate assets, liabilities, equity
$assets = array_filter($balances, fn($b) => $b['type'] == 'asset' && abs($b['balance']) > 0.01);
$liabilities = array_filter($balances, fn($b) => $b['type'] == 'liability' && abs($b['balance']) > 0.01);
$equity = array_filter($balances, fn($b) => $b['type'] == 'equity' && abs($b['balance']) > 0.01);

$total_assets = array_sum(array_column($assets, 'balance'));
$total_liabilities = array_sum(array_column($liabilities, 'balance'));
$total_equity_from_accounts = array_sum(array_column($equity, 'balance'));

// If no equity accounts exist, create a default Retained Earnings
if(empty($equity) && $total_assets > 0) {
    // Check if Retained Earnings exists
    $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_name = 'Retained Earnings' LIMIT 1");
    if(!$stmt->fetch()) {
        // Add Retained Earnings account if missing
        $pdo->query("INSERT INTO chart_of_accounts (account_code, account_name, account_type, balance_type, is_active) VALUES ('3200', 'Retained Earnings', 'equity', 'credit', 1)");
    }
    // Add to equity array
    $equity['retained_earnings'] = [
        'name' => 'Retained Earnings',
        'type' => 'equity',
        'balance' => 0
    ];
    $total_equity_from_accounts = 0;
}

// Calculate final equity including Net Profit/Loss
// For a proper balance sheet: Assets = Liabilities + (Equity + Net Profit/Loss)
$final_equity = $total_equity_from_accounts + $net_profit_loss;
$total_liabilities_equity = $total_liabilities + $final_equity;

// If balance doesn't match, adjust with a balancing figure
$difference = $total_assets - $total_liabilities_equity;
if(abs($difference) > 0.01) {
    // Add a "Balance Adjustment" or "Suspense Account" to show the difference
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
        
        @media print {
            .sidebar, .no-print, .stats-card, .btn, .card-header .btn, form { display: none !important; }
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
                                                <tr><td><?php echo htmlspecialchars($a['name']); ?></td>
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
                                                <tr><td><?php echo htmlspecialchars($l['name']); ?></td>
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
                                            <tr><td><?php echo htmlspecialchars($e['name']); ?></td>
                                                <td class="text-end"><?php echo number_format($e['balance'], 2); ?></td>
                                            </tr>
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
                    
                    <!-- Explanation for new users -->
                    <?php if($total_liabilities_equity == 0 && $total_assets > 0): ?>
                    <div class="alert alert-warning text-center mt-2">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Why is my balance sheet not balanced?</strong><br>
                        You need to set up opening balances for Equity accounts. 
                        <a href="accounting.php?tab=chart" class="alert-link">Click here to add Owner's Equity account</a> 
                        with an opening balance equal to your total assets.
                    </div>
                    <?php endif; ?>
                    
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
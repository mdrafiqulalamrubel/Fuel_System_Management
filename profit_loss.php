<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-t');

// Get income accounts (excluding duplicate from sales table)
$incomes = $pdo->query("SELECT * FROM chart_of_accounts WHERE account_type = 'income' AND is_active = 1")->fetchAll();

// Get expense accounts
$expenses = $pdo->query("SELECT * FROM chart_of_accounts WHERE account_type = 'expense' AND is_active = 1")->fetchAll();

// Calculate income from voucher entries only (not double-counting)
$income_data = [];
foreach($incomes as $inc) {
    // For income accounts: Credit - Debit
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(vi.credit_amount), 0) - COALESCE(SUM(vi.debit_amount), 0) as total 
        FROM voucher_items vi 
        JOIN vouchers v ON vi.voucher_id = v.id 
        WHERE vi.account_id = ? 
        AND v.date BETWEEN ? AND ? 
        AND v.status = 'approved'
    ");
    $stmt->execute([$inc['id'], $from_date, $to_date]);
    $total = $stmt->fetch()['total'] ?? 0;
    
    // Only show non-zero amounts
    if(abs($total) > 0.01) {
        $income_data[] = [
            'name' => $inc['account_name'], 
            'amount' => abs($total)
        ];
    }
}

// Calculate expenses from voucher entries
$expense_data = [];
foreach($expenses as $exp) {
    // For expense accounts: Debit - Credit
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(vi.debit_amount), 0) - COALESCE(SUM(vi.credit_amount), 0) as total 
        FROM voucher_items vi 
        JOIN vouchers v ON vi.voucher_id = v.id 
        WHERE vi.account_id = ? 
        AND v.date BETWEEN ? AND ? 
        AND v.status = 'approved'
    ");
    $stmt->execute([$exp['id'], $from_date, $to_date]);
    $total = $stmt->fetch()['total'] ?? 0;
    
    // Only show non-zero amounts
    if(abs($total) > 0.01) {
        $expense_data[] = [
            'name' => $exp['account_name'], 
            'amount' => abs($total)
        ];
    }
}

// Remove duplicate: Fuel Sales from chart_of_accounts already includes all sales via vouchers
// So we DON'T need to add separately from sales table!

// Optional: If you want to verify, you can check if Fuel Sales is missing and add it
$has_fuel_sales = false;
foreach($income_data as $inc) {
    if(stripos($inc['name'], 'Fuel Sales') !== false || stripos($inc['name'], 'Fuel Sale') !== false) {
        $has_fuel_sales = true;
        break;
    }
}

// If no fuel sales found in voucher entries, then add from sales table as fallback
if(!$has_fuel_sales) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) as total_sales FROM sales WHERE DATE(sale_date) BETWEEN ? AND ?");
    $stmt->execute([$from_date, $to_date]);
    $fuel_sales = $stmt->fetch()['total_sales'] ?? 0;
    if($fuel_sales > 0) {
        $income_data[] = ['name' => 'Fuel Sales (Direct)', 'amount' => $fuel_sales];
    }
}

// Calculate COGS from fuel_receivings (if not already in expenses)
$has_cogs = false;
foreach($expense_data as $exp) {
    if(stripos($exp['name'], 'COGS') !== false || stripos($exp['name'], 'Cost of Goods') !== false || stripos($exp['name'], 'Fuel Purchase') !== false) {
        $has_cogs = true;
        break;
    }
}

if(!$has_cogs) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) as total_purchase FROM fuel_receivings WHERE receipt_date BETWEEN ? AND ?");
    $stmt->execute([$from_date, $to_date]);
    $cogs = $stmt->fetch()['total_purchase'] ?? 0;
    if($cogs > 0) {
        $expense_data[] = ['name' => 'Cost of Goods Sold (Fuel Purchase)', 'amount' => $cogs];
    }
}

$total_income = array_sum(array_column($income_data, 'amount'));
$total_expense = array_sum(array_column($expense_data, 'amount'));
$net_profit = $total_income - $total_expense;

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit & Loss Statement</title>
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
        .stats-card i {
            font-size: 40px;
            opacity: 0.5;
            float: right;
        }
        .pl-table {
            width: 100%;
            border-collapse: collapse;
        }
        .pl-table th, .pl-table td {
            border: 1px solid #dee2e6;
            padding: 12px;
        }
        .pl-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: bold;
        }
        .text-end { text-align: right; }
        .fw-bold { font-weight: bold; }
        
        @media print {
            .sidebar, .no-print, .stats-card, .btn, .card-header .btn, form {
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
            .pl-table th, .pl-table td {
                border: 1px solid #000 !important;
            }
            .pl-table th {
                background: #ddd !important;
                color: black !important;
            }
        }
        .print-header { display: none; }
        
        .income-row { background-color: #e8f5e9; }
        .expense-row { background-color: #ffebee; }
        .total-row { background-color: #e3f2fd; font-weight: bold; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- Print Header -->
            <div class="print-header">
                <h2><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h2>
                <h4>Profit & Loss Statement</h4>
                <p>Period: <?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                <p>Generated on: <?php echo date('d/m/Y h:i:s A'); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap no-print">
                <h2><i class="fas fa-chart-line"></i> Profit & Loss Statement</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="accounting.php?tab=reports" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Reports
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
                                <i class="fas fa-search"></i> Generate Statement
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="row mb-4 no-print">
                <div class="col-md-4">
                    <div class="stats-card">
                        <i class="fas fa-arrow-up"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_income, 2); ?></h3>
                        <p>Total Income</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-arrow-down"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_expense, 2); ?></h3>
                        <p>Total Expenses</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format(abs($net_profit), 2); ?></h3>
                        <p>Net <?php echo $net_profit >= 0 ? 'Profit' : 'Loss'; ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Profit & Loss Statement - Side by Side -->
            <div class="card">
                <div class="card-header bg-success text-white text-center">
                    <h4>Profit & Loss Statement</h4>
                    <p class="mb-0"><?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- LEFT SIDE: INCOME -->
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-success text-white">
                                    <h5><i class="fas fa-arrow-up"></i> INCOME (Credit)</h5>
                                </div>
                                <div class="card-body p-0">
                                    <table class="pl-table">
                                        <thead>
                                            <tr style="background: #28a745; color: white;">
                                                <th>Particulars</th>
                                                <th class="text-end">Amount (<?php echo $currency; ?>)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(empty($income_data)): ?>
                                                <tr>
                                                    <td colspan="2" class="text-center text-muted">No income records found</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach($income_data as $inc): ?>
                                                <tr class="income-row">
                                                    <td><?php echo htmlspecialchars($inc['name']); ?></td>
                                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($inc['amount'], 2); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <tr class="total-row" style="background: #d4edda; font-weight: bold;">
                                                <td><strong>TOTAL INCOME</strong></td>
                                                <td class="text-end"><strong><?php echo $currency; ?> <?php echo number_format($total_income, 2); ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- RIGHT SIDE: EXPENSES -->
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-danger text-white">
                                    <h5><i class="fas fa-arrow-down"></i> EXPENSES (Debit)</h5>
                                </div>
                                <div class="card-body p-0">
                                    <table class="pl-table">
                                        <thead>
                                            <tr style="background: #dc3545; color: white;">
                                                <th>Particulars</th>
                                                <th class="text-end">Amount (<?php echo $currency; ?>)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(empty($expense_data)): ?>
                                                <tr>
                                                    <td colspan="2" class="text-center text-muted">No expense records found</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach($expense_data as $exp): ?>
                                                <tr class="expense-row">
                                                    <td><?php echo htmlspecialchars($exp['name']); ?></td>
                                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($exp['amount'], 2); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <tr class="total-row" style="background: #f8d7da; font-weight: bold;">
                                                <td><strong>TOTAL EXPENSES</strong></td>
                                                <td class="text-end"><strong><?php echo $currency; ?> <?php echo number_format($total_expense, 2); ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Net Profit/Loss -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="alert <?php echo $net_profit >= 0 ? 'alert-success' : 'alert-danger'; ?> text-center">
                                <h4>
                                    <i class="fas <?php echo $net_profit >= 0 ? 'fa-chart-line' : 'fa-exclamation-triangle'; ?>"></i>
                                    NET <?php echo $net_profit >= 0 ? 'PROFIT' : 'LOSS'; ?>: 
                                    <?php echo $currency; ?> <?php echo number_format(abs($net_profit), 2); ?>
                                </h4>
                                <p class="mb-0">
                                    <?php if($net_profit >= 0): ?>
                                        Congratulations! Your business made a profit during this period.
                                    <?php else: ?>
                                        Your expenses exceeded income during this period. Consider reviewing costs.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
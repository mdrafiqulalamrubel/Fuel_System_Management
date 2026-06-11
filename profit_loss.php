<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-t');

// Get income accounts
$incomes = $pdo->query("SELECT * FROM chart_of_accounts WHERE account_type = 'income' AND is_active = 1")->fetchAll();
// Get expense accounts
$expenses = $pdo->query("SELECT * FROM chart_of_accounts WHERE account_type = 'expense' AND is_active = 1")->fetchAll();

$income_data = [];
foreach($incomes as $inc) {
    $stmt = $pdo->prepare("SELECT SUM(credit_amount) - SUM(debit_amount) as total FROM voucher_items vi JOIN vouchers v ON vi.voucher_id = v.id WHERE vi.account_id = ? AND v.date BETWEEN ? AND ? AND v.status = 'approved'");
    $stmt->execute([$inc['id'], $from_date, $to_date]);
    $total = $stmt->fetch()['total'] ?? 0;
    if($total != 0) $income_data[] = ['name'=>$inc['account_name'], 'amount'=>$total];
}

$expense_data = [];
foreach($expenses as $exp) {
    $stmt = $pdo->prepare("SELECT SUM(debit_amount) - SUM(credit_amount) as total FROM voucher_items vi JOIN vouchers v ON vi.voucher_id = v.id WHERE vi.account_id = ? AND v.date BETWEEN ? AND ? AND v.status = 'approved'");
    $stmt->execute([$exp['id'], $from_date, $to_date]);
    $total = $stmt->fetch()['total'] ?? 0;
    if($total != 0) $expense_data[] = ['name'=>$exp['account_name'], 'amount'=>$total];
}

// Add fuel sales directly
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total_sales FROM sales WHERE DATE(sale_date) BETWEEN ? AND ?");
$stmt->execute([$from_date, $to_date]);
$fuel_sales = $stmt->fetch()['total_sales'] ?? 0;
$income_data[] = ['name'=>'Fuel Sales', 'amount'=>$fuel_sales];

// Add cost of goods sold (fuel purchases)
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total_purchase FROM fuel_receivings WHERE receipt_date BETWEEN ? AND ?");
$stmt->execute([$from_date, $to_date]);
$cogs = $stmt->fetch()['total_purchase'] ?? 0;
$expense_data[] = ['name'=>'Cost of Goods Sold (Fuel Purchase)', 'amount'=>$cogs];

$total_income = array_sum(array_column($income_data, 'amount'));
$total_expense = array_sum(array_column($expense_data, 'amount'));
$net_profit = $total_income - $total_expense;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profit & Loss Statement</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>@media print{ .no-print{display:none;} }</style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    <div class="container mt-3">
        <div class="no-print mb-3">
            <a href="dashboard.php" class="btn btn-secondary">Back</a>
            <button onclick="window.print()" class="btn btn-primary">Print</button>
            <form class="d-inline" method="GET">
                <input type="date" name="from_date" value="<?php echo $from_date; ?>"> to
                <input type="date" name="to_date" value="<?php echo $to_date; ?>">
                <button type="submit" class="btn btn-info">View</button>
            </form>
        </div>
        
        <div class="card">
            <div class="card-header text-center bg-success text-white">
                <h3>FF Enterprise - Profit & Loss Statement</h3>
                <h5>For period <?php echo date('d M Y', strtotime($from_date)); ?> to <?php echo date('d M Y', strtotime($to_date)); ?></h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h4 class="text-success">INCOME</h4>
                        <table class="table table-bordered">
                            <?php foreach($income_data as $inc): ?>
                            <tr><td><?php echo $inc['name']; ?></td><td class="text-end">৳<?php echo number_format($inc['amount'], 2); ?></td></tr>
                            <?php endforeach; ?>
                            <tr class="fw-bold bg-light"><td>TOTAL INCOME</td><td class="text-end">৳<?php echo number_format($total_income, 2); ?></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h4 class="text-danger">EXPENSES</h4>
                        <table class="table table-bordered">
                            <?php foreach($expense_data as $exp): ?>
                            <tr><td><?php echo $exp['name']; ?></td><td class="text-end">৳<?php echo number_format($exp['amount'], 2); ?></td></tr>
                            <?php endforeach; ?>
                            <tr class="fw-bold bg-light"><td>TOTAL EXPENSES</td><td class="text-end">৳<?php echo number_format($total_expense, 2); ?></td></tr>
                        </table>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-12">
                        <div class="alert <?php echo $net_profit >=0 ? 'alert-success' : 'alert-danger'; ?> text-center">
                            <h4>NET <?php echo $net_profit >=0 ? 'PROFIT' : 'LOSS'; ?>: ৳<?php echo number_format(abs($net_profit), 2); ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
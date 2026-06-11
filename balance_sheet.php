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
    $opening_type = $acc['balance_type'];
    
    if($acc['account_type'] == 'asset' || $acc['account_type'] == 'expense') {
        $balance = $opening + ($total_debit - $total_credit);
    } else {
        $balance = $opening + ($total_credit - $total_debit);
    }
    
    $balances[$acc['id']] = ['name'=>$acc['account_name'], 'type'=>$acc['account_type'], 'code'=>$acc['account_code'], 'balance'=>$balance];
}

// Separate assets, liabilities, equity
$assets = array_filter($balances, fn($b)=>$b['type']=='asset' && $b['balance']!=0);
$liabilities = array_filter($balances, fn($b)=>$b['type']=='liability' && $b['balance']!=0);
$equity = array_filter($balances, fn($b)=>$b['type']=='equity' && $b['balance']!=0);

$total_assets = array_sum(array_column($assets, 'balance'));
$total_liabilities = array_sum(array_column($liabilities, 'balance'));
$total_equity = array_sum(array_column($equity, 'balance'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Balance Sheet</title>
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
                <input type="date" name="as_on" value="<?php echo $as_on; ?>">
                <button type="submit" class="btn btn-info">View</button>
            </form>
        </div>
        
        <div class="card">
            <div class="card-header text-center bg-primary text-white">
                <h3>FF Enterprise - Balance Sheet</h3>
                <h5>As on <?php echo date('d F, Y', strtotime($as_on)); ?></h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h4 class="text-success">ASSETS</h4>
                        <table class="table table-bordered">
                            <thead><tr><th>Account</th><th class="text-end">Amount (৳)</th></tr></thead>
                            <tbody>
                                <?php foreach($assets as $a): ?>
                                <tr><td><?php echo $a['name']; ?></td><td class="text-end"><?php echo number_format($a['balance'], 2); ?></td></tr>
                                <?php endforeach; ?>
                                <tr class="fw-bold"><td>TOTAL ASSETS</td><td class="text-end"><?php echo number_format($total_assets, 2); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h4 class="text-danger">LIABILITIES & EQUITY</h4>
                        <table class="table table-bordered">
                            <thead><tr><th>Account</th><th class="text-end">Amount (৳)</th></tr></thead>
                            <tbody>
                                <tr class="bg-light"><td colspan="2"><strong>LIABILITIES</strong></td></tr>
                                <?php foreach($liabilities as $l): ?>
                                <tr><td><?php echo $l['name']; ?></td><td class="text-end"><?php echo number_format($l['balance'], 2); ?></td></tr>
                                <?php endforeach; ?>
                                <tr><td class="fw-bold">Total Liabilities</td><td class="text-end fw-bold"><?php echo number_format($total_liabilities, 2); ?></td></tr>
                                <tr class="bg-light"><td colspan="2"><strong>EQUITY</strong></td></tr>
                                <?php foreach($equity as $e): ?>
                                <tr><td><?php echo $e['name']; ?></td><td class="text-end"><?php echo number_format($e['balance'], 2); ?></td></tr>
                                <?php endforeach; ?>
                                <tr class="fw-bold"><td>TOTAL EQUITY</td><td class="text-end"><?php echo number_format($total_equity, 2); ?></td></tr>
                                <tr class="table-info fw-bold"><td>TOTAL LIABILITIES & EQUITY</td><td class="text-end"><?php echo number_format($total_liabilities + $total_equity, 2); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="text-center mt-3">
                    <hr>
                    <p>This is a computer generated report</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trial Balance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>@media print{.no-print{display:none;}}</style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="container mt-3">
        <div class="no-print mb-3">
            <a href="reports.php" class="btn btn-secondary">Back</a>
            <button onclick="window.print()" class="btn btn-primary">Print</button>
            <form class="d-inline" method="GET"><input type="date" name="as_on" value="<?php echo $as_on; ?>"><button type="submit" class="btn btn-info">View</button></form>
        </div>
        
        <div class="text-center"><h3><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h3><h4>TRIAL BALANCE</h4><h5>As on <?php echo date('d F Y', strtotime($as_on)); ?></h5></div>
        
        <div class="card mt-3"><div class="card-body"><table class="table table-bordered" id="tbTable"><thead><tr><th>Account Code</th><th>Account Name</th><th>Account Type</th><th class="text-end">Debit (BDT)</th><th class="text-end">Credit (BDT)</th></tr></thead><tbody><?php foreach($trial_balance as $tb): ?><tr><td><?php echo $tb['code']; ?></td><td><?php echo $tb['name']; ?></td><td><?php echo ucfirst($tb['type']); ?></td><td class="text-end"><?php echo $tb['debit'] > 0 ? number_format($tb['debit'], 2) : '-'; ?></td><td class="text-end"><?php echo $tb['credit'] > 0 ? number_format($tb['credit'], 2) : '-'; ?></td></tr><?php endforeach; ?></tbody><tfoot><tr class="fw-bold"><td colspan="3" class="text-end">TOTAL</td><td class="text-end"><?php echo number_format($total_debit, 2); ?></td><td class="text-end"><?php echo number_format($total_credit, 2); ?></td></tr></tfoot></table></div></div>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>$(document).ready(function(){$('#tbTable').DataTable({order:[[0,'asc']]});});</script>
</body>
</html>
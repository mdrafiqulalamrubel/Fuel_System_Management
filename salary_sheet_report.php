<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$month_year = $_GET['month_year'] ?? date('Y-m');
$stmt = $pdo->prepare("
    SELECT p.*, e.full_name, e.designation, e.employee_id, e.bank_account_no
    FROM payroll p 
    JOIN employees e ON p.employee_id = e.id 
    WHERE p.month_year = ?
    ORDER BY e.full_name
");
$stmt->execute([$month_year . '-01']);
$payrolls = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT SUM(net_salary) as total, SUM(basic_salary) as total_basic, SUM(allowances) as total_allowances, SUM(overtime_amount) as total_overtime, SUM(bonus) as total_bonus, SUM(deductions) as total_deductions FROM payroll WHERE month_year = ?");
$stmt->execute([$month_year . '-01']);
$totals = $stmt->fetch();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Salary Sheet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>@media print{.no-print{display:none;} .page-break{page-break-before:always;}}</style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    <div class="container mt-3">
        <div class="no-print mb-3">
            <a href="reports.php" class="btn btn-secondary">Back</a>
            <button onclick="window.print()" class="btn btn-primary">Print</button>
            <form class="d-inline" method="GET"><input type="month" name="month_year" value="<?php echo $month_year; ?>"><button type="submit" class="btn btn-info">View</button></form>
        </div>
        
        <div class="text-center"><h3><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h3><h4>SALARY SHEET</h4><h5>Month: <?php echo date('F Y', strtotime($month_year . '-01')); ?></h5></div>
        
        <div class="row mt-3">
            <div class="col-md-12"><div class="card"><div class="card-body"><table class="table table-bordered" id="salaryTable"><thead><tr><th>ID</th><th>Employee Name</th><th>Designation</th><th>Basic</th><th>Allowances</th><th>Overtime</th><th>Bonus</th><th>Deductions</th><th>Net Salary</th><th>Bank Account</th></tr></thead><tbody><?php foreach($payrolls as $p): ?><tr><td><?php echo $p['employee_id']; ?></td><td><?php echo $p['full_name']; ?></td><td><?php echo $p['designation']; ?></td><td><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($p['basic_salary'], 2); ?></td><td><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($p['allowances'], 2); ?></td><td><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($p['overtime_amount'], 2); ?></td><td><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($p['bonus'], 2); ?></td><td><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($p['deductions'], 2); ?></td><td><strong><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($p['net_salary'], 2); ?></strong></td><td><?php echo $p['bank_account_no']; ?></td></tr><?php endforeach; ?></tbody><tfoot><tr class="fw-bold"><td colspan="3">TOTAL</td><td><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($totals['total_basic'] ?? 0, 2); ?></td><td><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($totals['total_allowances'] ?? 0, 2); ?></td><td><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($totals['total_overtime'] ?? 0, 2); ?></td><td><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($totals['total_bonus'] ?? 0, 2); ?></td><td><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($totals['total_deductions'] ?? 0, 2); ?></td><td><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($totals['total'] ?? 0, 2); ?></td><td></td></tr></tfoot></table></div></div></div>
        </div>
        <div class="text-center mt-3"><p>Generated on: <?php echo date('d F Y H:i:s'); ?></p></div>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>$(document).ready(function(){$('#salaryTable').DataTable({order:[[1,'asc']]});});</script>
</body>
</html>
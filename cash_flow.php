<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-t');

// Cash inflows (receipts)
$stmt = $pdo->prepare("SELECT SUM(credit_amount) as total FROM voucher_items vi JOIN vouchers v ON vi.voucher_id = v.id WHERE v.date BETWEEN ? AND ? AND v.voucher_type = 'receipt' AND v.status = 'approved'");
$stmt->execute([$from_date, $to_date]);
$cash_inflows = $stmt->fetch()['total'] ?? 0;

// Cash outflows (payments)
$stmt = $pdo->prepare("SELECT SUM(debit_amount) as total FROM voucher_items vi JOIN vouchers v ON vi.voucher_id = v.id WHERE v.date BETWEEN ? AND ? AND v.voucher_type = 'payment' AND v.status = 'approved'");
$stmt->execute([$from_date, $to_date]);
$cash_outflows = $stmt->fetch()['total'] ?? 0;

// Operating activities - Sales
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM sales WHERE DATE(sale_date) BETWEEN ? AND ? AND sale_type = 'cash'");
$stmt->execute([$from_date, $to_date]);
$cash_sales = $stmt->fetch()['total'] ?? 0;

// Operating activities - Rent
$stmt = $pdo->prepare("SELECT SUM(amount) as total FROM rent_payments WHERE payment_date BETWEEN ? AND ?");
$stmt->execute([$from_date, $to_date]);
$rent_income = $stmt->fetch()['total'] ?? 0;

// Operating activities - Expenses
$stmt = $pdo->prepare("SELECT SUM(amount) as total FROM expenses WHERE expense_date BETWEEN ? AND ?");
$stmt->execute([$from_date, $to_date]);
$operating_expenses = $stmt->fetch()['total'] ?? 0;

// Salary paid
$stmt = $pdo->prepare("SELECT SUM(net_salary) as total FROM payroll WHERE payment_date BETWEEN ? AND ? AND status = 'paid'");
$stmt->execute([$from_date, $to_date]);
$salary_paid = $stmt->fetch()['total'] ?? 0;

$net_cash_flow = $cash_inflows - $cash_outflows;
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cash Flow Statement</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>@media print{.no-print{display:none;}}</style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    <div class="container mt-3">
        <div class="no-print mb-3">
            <a href="reports.php" class="btn btn-secondary">Back</a>
            <button onclick="window.print()" class="btn btn-primary">Print</button>
            <form class="d-inline" method="GET"><input type="date" name="from_date" value="<?php echo $from_date; ?>"> to <input type="date" name="to_date" value="<?php echo $to_date; ?>"><button type="submit" class="btn btn-info">View</button></form>
        </div>
        
        <div class="text-center"><h3><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h3><h4>CASH FLOW STATEMENT</h4><h5>Period: <?php echo date('d M Y', strtotime($from_date)); ?> to <?php echo date('d M Y', strtotime($to_date)); ?></h5></div>
        
        <div class="row mt-4">
            <div class="col-md-6 offset-md-3"><div class="card"><div class="card-body">
                <table class="table table-bordered">
                    <tr class="bg-light"><td colspan="2"><strong>A. CASH FLOW FROM OPERATING ACTIVITIES</strong></td></tr>
                    <tr><td>Cash Sales</td><td class="text-end"><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($cash_sales, 2); ?></td></tr>
                    <tr><td>Rent Income Received</td><td class="text-end"><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($rent_income, 2); ?></td></tr>
                    <tr><td>Less: Operating Expenses</td><td class="text-end">(<?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($operating_expenses, 2); ?>)</td></tr>
                    <tr><td>Less: Salary Paid</td><td class="text-end">(<?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($salary_paid, 2); ?>)</td></tr>
                    <tr class="fw-bold"><td>Net Cash from Operations</td><td class="text-end"><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($cash_sales + $rent_income - $operating_expenses - $salary_paid, 2); ?></td></tr>
                    
                    <tr class="bg-light"><td colspan="2"><strong>B. CASH FLOW FROM INVESTING ACTIVITIES</strong></td></tr>
                    <tr><td>Asset Purchases</td><td class="text-end">0.00</td></tr>
                    <tr class="fw-bold"><td>Net Cash from Investing</td><td class="text-end">0.00</td></tr>
                    
                    <tr class="bg-light"><td colspan="2"><strong>C. CASH FLOW FROM FINANCING ACTIVITIES</strong></td></tr>
                    <tr><td>Owner's Investment</td><td class="text-end">0.00</td></tr>
                    <tr><td>Loan Received</td><td class="text-end">0.00</td></tr>
                    <tr class="fw-bold"><td>Net Cash from Financing</td><td class="text-end">0.00</td></tr>
                    
                    <tr class="bg-success text-white fw-bold"><td>NET CASH FLOW</td><td class="text-end"><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($cash_sales + $rent_income - $operating_expenses - $salary_paid, 2); ?></td></tr>
                </table>
            </div></div></div>
        </div>
    </div>
</body>
</html>
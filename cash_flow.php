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

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Flow Statement</title>
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
            <!-- Print Header -->
            <div class="print-header">
                <h2><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h2>
                <h4>Cash Flow Statement</h4>
                <p>Period: <?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                <p>Generated on: <?php echo date('d/m/Y h:i:s A'); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap no-print">
                <h2><i class="fas fa-money-bill-wave"></i> Cash Flow Statement</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="reports.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
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
                        <div class="col-md-3">
                            <label>From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label>To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>">
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
                <div class="col-md-4">
                    <div class="stats-card">
                        <i class="fas fa-arrow-down"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($cash_inflows, 2); ?></h3>
                        <p>Cash Inflows</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-arrow-up"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($cash_outflows, 2); ?></h3>
                        <p>Cash Outflows</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($cash_inflows - $cash_outflows, 2); ?></h3>
                        <p>Net Cash Flow</p>
                    </div>
                </div>
            </div>
            
            <!-- Cash Flow Statement -->
            <div class="card">
                <div class="card-header bg-primary text-white text-center">
                    <h4>Cash Flow Statement</h4>
                    <h5><?php echo date('d M Y', strtotime($from_date)); ?> to <?php echo date('d M Y', strtotime($to_date)); ?></h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8 offset-md-2">
                            <table class="table table-bordered">
                                <tr class="bg-light">
                                    <td colspan="2"><strong>A. CASH FLOW FROM OPERATING ACTIVITIES</strong></td>
                                 </tr
                                 <tr>
                                    <td>Cash Sales</td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($cash_sales, 2); ?></td>
                                 </tr
                                 <tr>
                                    <td>Rent Income Received</td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($rent_income, 2); ?></td>
                                 </tr
                                 <tr>
                                    <td>Less: Operating Expenses</td>
                                    <td class="text-end">(<?php echo $currency; ?> <?php echo number_format($operating_expenses, 2); ?>)</td>
                                 </tr
                                 <tr>
                                    <td>Less: Salary Paid</td>
                                    <td class="text-end">(<?php echo $currency; ?> <?php echo number_format($salary_paid, 2); ?>)</td>
                                 </tr
                                 <tr class="fw-bold">
                                    <td>Net Cash from Operations</td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($cash_sales + $rent_income - $operating_expenses - $salary_paid, 2); ?></td>
                                 </tr
                                 
                                 <tr class="bg-light">
                                    <td colspan="2"><strong>B. CASH FLOW FROM INVESTING ACTIVITIES</strong></td>
                                 </tr
                                 <tr>
                                    <td>Asset Purchases</td>
                                    <td class="text-end">0.00</td>
                                 </tr
                                 <tr class="fw-bold">
                                    <td>Net Cash from Investing</td>
                                    <td class="text-end">0.00</td>
                                 </tr
                                 
                                 <tr class="bg-light">
                                    <td colspan="2"><strong>C. CASH FLOW FROM FINANCING ACTIVITIES</strong></td>
                                 </tr
                                 <tr>
                                    <td>Owner's Investment</td>
                                    <td class="text-end">0.00</td>
                                 </tr
                                 <tr>
                                    <td>Loan Received</td>
                                    <td class="text-end">0.00</td>
                                 </tr
                                 <tr class="fw-bold">
                                    <td>Net Cash from Financing</td>
                                    <td class="text-end">0.00</td>
                                 </tr
                                 
                                 <tr class="bg-success text-white fw-bold">
                                    <td>NET CASH FLOW</td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($cash_sales + $rent_income - $operating_expenses - $salary_paid, 2); ?></td>
                                 </tr
                             </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$from_date = isset($_GET['from_date']) && !empty($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) && !empty($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-t');

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

// Operating activities - Credit Sales (Accounts Receivable)
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM sales WHERE DATE(sale_date) BETWEEN ? AND ? AND sale_type = 'credit'");
$stmt->execute([$from_date, $to_date]);
$credit_sales = $stmt->fetch()['total'] ?? 0;

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

// Fuel Purchase (COGS)
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM fuel_receivings WHERE receipt_date BETWEEN ? AND ?");
$stmt->execute([$from_date, $to_date]);
$fuel_purchases = $stmt->fetch()['total'] ?? 0;

$net_operating = $cash_sales + $rent_income - $operating_expenses - $salary_paid - $fuel_purchases;

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
            transition: transform 0.3s;
        }
        .stats-card:hover { transform: translateY(-5px); }
        .stats-card i { font-size: 40px; opacity: 0.5; float: right; }
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
        .cf-table { width: 100%; border-collapse: collapse; }
        .cf-table td { padding: 10px 15px; border: 1px solid #dee2e6; }
        .cf-table .section-header { background-color: #f8f9fa; font-weight: bold; }
        .cf-table .sub-total { background-color: #e9ecef; font-weight: bold; }
        .cf-table .grand-total { background-color: #28a745; color: white; font-weight: bold; }
        .cf-table .negative { color: #dc3545; }
        .cf-table .positive { color: #28a745; }
        
        @media print {
            .sidebar, .no-print, .stats-card, .btn, .card-header .btn, form {
                display: none !important;
            }
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
                <h4>Cash Flow Statement</h4>
                <p>Period: <?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap no-print">
                <h2><i class="fas fa-money-bill-wave"></i> Cash Flow Statement</h2>
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
                        <p>Cash Inflows (Receipts)</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-arrow-up"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($cash_outflows, 2); ?></h3>
                        <p>Cash Outflows (Payments)</p>
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
                    <p class="mb-0"><?php echo date('d M Y', strtotime($from_date)); ?> to <?php echo date('d M Y', strtotime($to_date)); ?></p>
                    <small class="d-block text-light">Click on any amount to view detailed transactions</small>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-10 offset-md-1">
                            <table class="cf-table">
                                <!-- A. OPERATING ACTIVITIES -->
                                <tr class="section-header">
                                    <td colspan="2"><strong>A. CASH FLOW FROM OPERATING ACTIVITIES</strong></td>
                                </tr>
                                <tr class="clickable-row" 
                                    onclick="window.open('sales_report.php?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>', '_blank')"
                                    title="Click to view sales details">
                                    <td>Cash Sales</td>
                                    <td class="text-end positive"><?php echo $currency; ?> <?php echo number_format($cash_sales, 2); ?></td>
                                </tr>
                                <tr class="clickable-row" 
                                    onclick="window.open('customer_ledger.php?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>', '_blank')"
                                    title="Click to view credit sales details">
                                    <td>Credit Sales (Collection)</td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($credit_sales, 2); ?></td>
                                </tr>
                                <tr class="clickable-row" 
                                    onclick="window.open('rental.php?tab=payments', '_blank')"
                                    title="Click to view rent income details">
                                    <td>Rent Income Received</td>
                                    <td class="text-end positive"><?php echo $currency; ?> <?php echo number_format($rent_income, 2); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding-left: 30px;"><strong>Less: Operating Expenses</strong></td>
                                    <td class="text-end negative">(<?php echo $currency; ?> <?php echo number_format($operating_expenses, 2); ?>)</td>
                                </tr>
                                <tr class="clickable-row" 
                                    onclick="window.open('payroll.php?tab=payroll', '_blank')"
                                    title="Click to view salary details">
                                    <td style="padding-left: 30px;">Less: Salary Paid</td>
                                    <td class="text-end negative">(<?php echo $currency; ?> <?php echo number_format($salary_paid, 2); ?>)</td>
                                </tr>
                                <tr class="clickable-row" 
                                    onclick="window.open('fuel_receiving.php?tab=receiving&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>', '_blank')"
                                    title="Click to view fuel purchase details">
                                    <td style="padding-left: 30px;">Less: Fuel Purchase (COGS)</td>
                                    <td class="text-end negative">(<?php echo $currency; ?> <?php echo number_format($fuel_purchases, 2); ?>)</td>
                                </tr>
                                <tr class="sub-total">
                                    <td><strong>Net Cash from Operating Activities</strong></td>
                                    <td class="text-end <?php echo $net_operating >= 0 ? 'positive' : 'negative'; ?>">
                                        <strong><?php echo $currency; ?> <?php echo number_format($net_operating, 2); ?></strong>
                                    </td>
                                </tr>
                                
                                <!-- B. INVESTING ACTIVITIES -->
                                <tr class="section-header" style="border-top: 2px solid #000;">
                                    <td colspan="2"><strong>B. CASH FLOW FROM INVESTING ACTIVITIES</strong></td>
                                </tr>
                                <tr>
                                    <td>Asset Purchases</td>
                                    <td class="text-end"><?php echo $currency; ?> 0.00</td>
                                </tr>
                                <tr class="sub-total">
                                    <td><strong>Net Cash from Investing Activities</strong></td>
                                    <td class="text-end"><strong><?php echo $currency; ?> 0.00</strong></td>
                                </tr>
                                
                                <!-- C. FINANCING ACTIVITIES -->
                                <tr class="section-header">
                                    <td colspan="2"><strong>C. CASH FLOW FROM FINANCING ACTIVITIES</strong></td>
                                </tr>
                                <tr>
                                    <td>Owner's Investment</td>
                                    <td class="text-end"><?php echo $currency; ?> 0.00</td>
                                </tr>
                                <tr>
                                    <td>Loan Received</td>
                                    <td class="text-end"><?php echo $currency; ?> 0.00</td>
                                </tr>
                                <tr class="sub-total">
                                    <td><strong>Net Cash from Financing Activities</strong></td>
                                    <td class="text-end"><strong><?php echo $currency; ?> 0.00</strong></td>
                                </tr>
                                
                                <!-- GRAND TOTAL -->
                                <tr class="grand-total">
                                    <td style="font-size: 16px;"><strong>NET CASH FLOW</strong></td>
                                    <td class="text-end" style="font-size: 16px;">
                                        <strong><?php echo $currency; ?> <?php echo number_format($net_operating, 2); ?></strong>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="row mt-4">
                        <div class="col-md-3">
                            <div class="alert alert-success text-center">
                                <strong>Cash Inflows</strong><br>
                                <span class="h4"><?php echo $currency; ?> <?php echo number_format($cash_inflows, 2); ?></span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="alert alert-danger text-center">
                                <strong>Cash Outflows</strong><br>
                                <span class="h4"><?php echo $currency; ?> <?php echo number_format($cash_outflows, 2); ?></span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="alert alert-info text-center">
                                <strong>Operating Cash Flow</strong><br>
                                <span class="h4"><?php echo $currency; ?> <?php echo number_format($net_operating, 2); ?></span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="alert alert-warning text-center">
                                <strong>Period</strong><br>
                                <span class="h6"><?php echo date('d M Y', strtotime($from_date)); ?><br>to<br><?php echo date('d M Y', strtotime($to_date)); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
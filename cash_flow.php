<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$from_date = isset($_GET['from_date']) && !empty($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) && !empty($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-t');

// =============================================
// CASH INFLOWS (Receipts from vouchers)
// =============================================
$stmt = $pdo->prepare("SELECT SUM(credit_amount) as total FROM voucher_items vi JOIN vouchers v ON vi.voucher_id = v.id WHERE v.date BETWEEN ? AND ? AND v.voucher_type = 'receipt' AND v.status = 'approved'");
$stmt->execute([$from_date, $to_date]);
$cash_inflows = $stmt->fetch()['total'] ?? 0;

// =============================================
// CASH OUTFLOWS (Payments from vouchers)
// =============================================
$stmt = $pdo->prepare("SELECT SUM(debit_amount) as total FROM voucher_items vi JOIN vouchers v ON vi.voucher_id = v.id WHERE v.date BETWEEN ? AND ? AND v.voucher_type = 'payment' AND v.status = 'approved'");
$stmt->execute([$from_date, $to_date]);
$cash_outflows = $stmt->fetch()['total'] ?? 0;

// =============================================
// OPERATING ACTIVITIES - Cash Sales (Liquid Fuel)
// =============================================
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM sales WHERE DATE(sale_date) BETWEEN ? AND ? AND sale_type = 'cash'");
$stmt->execute([$from_date, $to_date]);
$cash_sales = $stmt->fetch()['total'] ?? 0;

// =============================================
// OPERATING ACTIVITIES - Cash Sales (CNG)
// =============================================
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM gas_sales WHERE DATE(sale_date) BETWEEN ? AND ? AND sale_type = 'cash' AND status = 'completed'");
$stmt->execute([$from_date, $to_date]);
$cng_cash_sales = $stmt->fetch()['total'] ?? 0;

// =============================================
// Total Cash Sales (Liquid + CNG)
// =============================================
$total_cash_sales = $cash_sales + $cng_cash_sales;

// =============================================
// OPERATING ACTIVITIES - Credit Sales
// =============================================
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM sales WHERE DATE(sale_date) BETWEEN ? AND ? AND sale_type = 'credit'");
$stmt->execute([$from_date, $to_date]);
$credit_sales_liquid = $stmt->fetch()['total'] ?? 0;

$stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM gas_sales WHERE DATE(sale_date) BETWEEN ? AND ? AND sale_type = 'credit' AND status = 'completed'");
$stmt->execute([$from_date, $to_date]);
$credit_sales_cng = $stmt->fetch()['total'] ?? 0;

$total_credit_sales = $credit_sales_liquid + $credit_sales_cng;

// =============================================
// OPERATING ACTIVITIES - Item Sales
// =============================================
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM item_sales WHERE DATE(sale_date) BETWEEN ? AND ? AND sale_type = 'cash'");
$stmt->execute([$from_date, $to_date]);
$item_sales = $stmt->fetch()['total'] ?? 0;

// =============================================
// OPERATING ACTIVITIES - Rent Income
// =============================================
$stmt = $pdo->prepare("SELECT SUM(amount) as total FROM rent_payments WHERE payment_date BETWEEN ? AND ?");
$stmt->execute([$from_date, $to_date]);
$rent_income = $stmt->fetch()['total'] ?? 0;

// =============================================
// OPERATING ACTIVITIES - Expenses
// =============================================
$stmt = $pdo->prepare("SELECT SUM(amount) as total FROM expenses WHERE expense_date BETWEEN ? AND ?");
$stmt->execute([$from_date, $to_date]);
$operating_expenses = $stmt->fetch()['total'] ?? 0;

// =============================================
// OPERATING ACTIVITIES - Salary Paid
// =============================================
$stmt = $pdo->prepare("SELECT SUM(net_salary) as total FROM payroll WHERE payment_date BETWEEN ? AND ? AND status = 'paid'");
$stmt->execute([$from_date, $to_date]);
$salary_paid = $stmt->fetch()['total'] ?? 0;

// =============================================
// OPERATING ACTIVITIES - Fuel Purchase (COGS)
// =============================================
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM fuel_receivings WHERE receipt_date BETWEEN ? AND ?");
$stmt->execute([$from_date, $to_date]);
$fuel_purchases = $stmt->fetch()['total'] ?? 0;

// =============================================
// OPERATING ACTIVITIES - Item Purchases
// =============================================
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM item_purchases WHERE purchase_date BETWEEN ? AND ?");
$stmt->execute([$from_date, $to_date]);
$item_purchases = $stmt->fetch()['total'] ?? 0;

// =============================================
// OPERATING ACTIVITIES - Supplier Payments
// =============================================
$stmt = $pdo->prepare("SELECT SUM(amount) as total FROM supplier_payments WHERE payment_date BETWEEN ? AND ?");
$stmt->execute([$from_date, $to_date]);
$supplier_payments = $stmt->fetch()['total'] ?? 0;

// =============================================
// OPERATING ACTIVITIES - Employee Payments
// =============================================
$stmt = $pdo->prepare("SELECT SUM(amount) as total FROM employee_payments WHERE payment_date BETWEEN ? AND ?");
$stmt->execute([$from_date, $to_date]);
$employee_payments = $stmt->fetch()['total'] ?? 0;

// =============================================
// TOTAL CASH INFLOWS (All Receipts)
// =============================================
$total_cash_inflows = $cash_inflows + $total_cash_sales + $rent_income + $item_sales;

// =============================================
// TOTAL CASH OUTFLOWS (All Payments)
// =============================================
$total_cash_outflows = $cash_outflows + $operating_expenses + $salary_paid + $fuel_purchases + $item_purchases + $supplier_payments + $employee_payments;

// =============================================
// NET CASH FLOW
// =============================================
$net_operating = $total_cash_inflows - $total_cash_outflows;

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
                        <h3><?php echo $currency; ?> <?php echo number_format($total_cash_inflows, 2); ?></h3>
                        <p>Total Cash Inflows</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-arrow-up"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_cash_outflows, 2); ?></h3>
                        <p>Total Cash Outflows</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($net_operating, 2); ?></h3>
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
                                
                                <!-- Cash Sales - Liquid Fuel -->
                                <tr class="clickable-row" 
                                    onclick="window.open('reports.php?tab=sales&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>', '_blank')"
                                    title="Click to view sales details">
                                    <td>Cash Sales - Liquid Fuel</td>
                                    <td class="text-end positive"><?php echo $currency; ?> <?php echo number_format($cash_sales, 2); ?></td>
                                </tr>
                                
                                <!-- Cash Sales - CNG -->
                                <tr class="clickable-row" 
                                    onclick="window.open('cng_sales_report.php?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>', '_blank')"
                                    title="Click to view CNG sales details">
                                    <td>Cash Sales - CNG</td>
                                    <td class="text-end positive"><?php echo $currency; ?> <?php echo number_format($cng_cash_sales, 2); ?></td>
                                </tr>
                                
                                <!-- Credit Sales -->
                                <tr class="clickable-row" 
                                    onclick="window.open('reports.php?tab=credit&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>', '_blank')"
                                    title="Click to view credit sales details">
                                    <td>Credit Sales</td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_credit_sales, 2); ?></td>
                                </tr>
                                
                                <!-- Item Sales -->
                                <tr class="clickable-row" 
                                    onclick="window.open('item_sales_report.php?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>', '_blank')"
                                    title="Click to view item sales details">
                                    <td>Item & Services Sales</td>
                                    <td class="text-end positive"><?php echo $currency; ?> <?php echo number_format($item_sales, 2); ?></td>
                                </tr>
                                
                                <!-- Rent Income -->
                                <tr class="clickable-row" 
                                    onclick="window.open('rental.php?tab=payments&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>', '_blank')"
                                    title="Click to view rent income details">
                                    <td>Rent Income Received</td>
                                    <td class="text-end positive"><?php echo $currency; ?> <?php echo number_format($rent_income, 2); ?></td>
                                </tr>
                                
                                <!-- Total Cash Inflows from Operations -->
                                <tr class="sub-total">
                                    <td><strong>Total Cash Inflows from Operations</strong></td>
                                    <td class="text-end positive">
                                        <strong><?php echo $currency; ?> <?php echo number_format($total_cash_inflows, 2); ?></strong>
                                    </td>
                                </tr>
                                
                                <!-- Operating Expenses -->
                                <tr class="clickable-row" 
                                    onclick="window.open('expenses.php?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>', '_blank')"
                                    title="Click to view expense details">
                                    <td style="padding-left: 30px;">Operating Expenses</td>
                                    <td class="text-end negative">(<?php echo $currency; ?> <?php echo number_format($operating_expenses, 2); ?>)</td>
                                </tr>
                                
                                <!-- Salary Paid -->
                                <tr class="clickable-row" 
                                    onclick="window.open('payroll.php?tab=payroll&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>', '_blank')"
                                    title="Click to view salary details">
                                    <td style="padding-left: 30px;">Salary Paid</td>
                                    <td class="text-end negative">(<?php echo $currency; ?> <?php echo number_format($salary_paid, 2); ?>)</td>
                                </tr>
                                
                                <!-- Fuel Purchase -->
                                <tr class="clickable-row" 
                                    onclick="window.open('fuel_receiving.php?tab=receiving&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>', '_blank')"
                                    title="Click to view fuel purchase details">
                                    <td style="padding-left: 30px;">Fuel Purchase (COGS)</td>
                                    <td class="text-end negative">(<?php echo $currency; ?> <?php echo number_format($fuel_purchases, 2); ?>)</td>
                                </tr>
                                
                                <!-- Item Purchases -->
                                <tr class="clickable-row" 
                                    onclick="window.open('item_purchase.php?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>', '_blank')"
                                    title="Click to view item purchase details">
                                    <td style="padding-left: 30px;">Item Purchases</td>
                                    <td class="text-end negative">(<?php echo $currency; ?> <?php echo number_format($item_purchases, 2); ?>)</td>
                                </tr>
                                
                                <!-- Supplier Payments -->
                                <tr class="clickable-row" 
                                    onclick="window.open('supplier_payments.php?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>', '_blank')"
                                    title="Click to view supplier payment details">
                                    <td style="padding-left: 30px;">Supplier Payments</td>
                                    <td class="text-end negative">(<?php echo $currency; ?> <?php echo number_format($supplier_payments, 2); ?>)</td>
                                </tr>
                                
                                <!-- Employee Payments -->
                                <tr class="clickable-row" 
                                    onclick="window.open('employee_payment.php?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>', '_blank')"
                                    title="Click to view employee payment details">
                                    <td style="padding-left: 30px;">Employee Payments</td>
                                    <td class="text-end negative">(<?php echo $currency; ?> <?php echo number_format($employee_payments, 2); ?>)</td>
                                </tr>
                                
                                <!-- Total Cash Outflows from Operations -->
                                <tr class="sub-total">
                                    <td><strong>Total Cash Outflows from Operations</strong></td>
                                    <td class="text-end negative">
                                        <strong>(<?php echo $currency; ?> <?php echo number_format($total_cash_outflows, 2); ?>)</strong>
                                    </td>
                                </tr>
                                
                                <!-- Net Cash from Operating Activities -->
                                <tr class="sub-total" style="background-color: #d4edda;">
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
                                <strong>Total Inflows</strong><br>
                                <span class="h4"><?php echo $currency; ?> <?php echo number_format($total_cash_inflows, 2); ?></span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="alert alert-danger text-center">
                                <strong>Total Outflows</strong><br>
                                <span class="h4"><?php echo $currency; ?> <?php echo number_format($total_cash_outflows, 2); ?></span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="alert alert-info text-center">
                                <strong>Net Cash Flow</strong><br>
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
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
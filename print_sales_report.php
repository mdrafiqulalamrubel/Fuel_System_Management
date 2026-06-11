<?php
require_once 'config/database.php';

$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

// Get sales data
$stmt = $pdo->prepare("
    SELECT s.*, p.product_name, u.full_name as operator_name 
    FROM sales s 
    JOIN fuel_products p ON s.product_id = p.id 
    JOIN users u ON s.operator_id = u.id 
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    ORDER BY s.sale_date DESC
");
$stmt->execute([$from_date, $to_date]);
$sales = $stmt->fetchAll();

// Get summary
$stmt = $pdo->prepare("
    SELECT 
        SUM(total_amount) as total_sales,
        SUM(quantity_liters) as total_liters,
        COUNT(*) as total_transactions
    FROM sales 
    WHERE DATE(sale_date) BETWEEN ? AND ?
");
$stmt->execute([$from_date, $to_date]);
$summary = $stmt->fetch();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'TK';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print Sales Report</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            padding: 10px;
        }
        
        @media print {
            body {
                padding: 0;
                margin: 0;
            }
            @page {
                size: A4;
                margin: 10mm;
            }
        }
        
        .report-header {
            text-align: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #000;
        }
        
        .company-name {
            font-size: 18px;
            font-weight: bold;
        }
        
        .report-title {
            font-size: 14px;
            font-weight: bold;
            margin-top: 5px;
        }
        
        .report-period {
            font-size: 11px;
            margin-top: 3px;
        }
        
        .summary-section {
            margin-bottom: 15px;
            padding: 8px;
            border: 1px solid #000;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        th, td {
            border: 1px solid #000;
            padding: 5px;
            text-align: left;
            font-size: 10px;
        }
        
        th {
            background-color: #f0f0f0;
            text-align: center;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .report-footer {
            text-align: center;
            margin-top: 15px;
            padding-top: 8px;
            border-top: 1px solid #000;
            font-size: 9px;
        }
    </style>
</head>
<body>
    <div class="report-header">
        <div class="company-name"><?php echo $settings['company_name'] ?? 'FF ENTERPRISE'; ?></div>
        <div><?php echo $settings['company_address'] ?? 'Dhaka, Bangladesh'; ?></div>
        <div class="report-title">DAILY SALES REPORT</div>
        <div class="report-period"><?php echo date('d/m/Y', strtotime($from_date)); ?> to <?php echo date('d/m/Y', strtotime($to_date)); ?></div>
    </div>
    
    <div class="summary-section">
        <div class="summary-row">
            <span><strong>Total Sales:</strong></span>
            <span><?php echo $currency; ?> <?php echo number_format($summary['total_sales'] ?? 0, 2); ?></span>
        </div>
        <div class="summary-row">
            <span><strong>Total Liters:</strong></span>
            <span><?php echo number_format($summary['total_liters'] ?? 0, 2); ?> L</span>
        </div>
        <div class="summary-row">
            <span><strong>Transactions:</strong></span>
            <span><?php echo $summary['total_transactions'] ?? 0; ?></span>
        </div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Time</th>
                <th>Invoice No</th>
                <th>Product</th>
                <th class="text-right">Liters</th>
                <th class="text-right">Price</th>
                <th class="text-right">Total</th>
                <th>Type</th>
                <th>Operator</th>
                <th>Customer</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($sales as $sale): ?>
            <tr>
                <td class="text-center"><?php echo date('d-m-Y', strtotime($sale['sale_date'])); ?></td>
                <td class="text-center"><?php echo date('H:i', strtotime($sale['sale_date'])); ?></td>
                <td><?php echo $sale['invoice_no']; ?></td>
                <td><?php echo $sale['product_name']; ?></td>
                <td class="text-right"><?php echo number_format($sale['quantity_liters'], 2); ?></td>
                <td class="text-right"><?php echo number_format($sale['unit_price'], 2); ?></td>
                <td class="text-right"><?php echo number_format($sale['total_amount'], 2); ?></td>
                <td class="text-center"><?php echo ucfirst($sale['sale_type']); ?></td>
                <td><?php echo $sale['operator_name']; ?></td>
                <td><?php echo $sale['customer_name'] ?: '-'; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight: bold;">
                <td colspan="4" class="text-right">TOTAL:</td>
                <td class="text-right"><?php echo number_format($summary['total_liters'] ?? 0, 2); ?></td>
                <td class="text-right">-</td>
                <td class="text-right"><?php echo number_format($summary['total_sales'] ?? 0, 2); ?></td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>
    
    <div class="report-footer">
        <p>This is a computer generated report. Valid with authorized signature.</p>
        <p>*** End of Report ***</p>
    </div>
    
    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>
<?php
session_start();
require_once 'config/database.php';

$receipt_no = isset($_GET['receipt_no']) ? $_GET['receipt_no'] : (isset($_SESSION['last_receiving']) ? $_SESSION['last_receiving']['receipt_no'] : '');

if($receipt_no) {
    // Get receiving data from database
    $stmt = $pdo->prepare("
        SELECT fr.*, p.product_name, t.tank_name 
        FROM fuel_receivings fr 
        JOIN fuel_products p ON fr.product_id = p.id 
        JOIN tanks t ON fr.tank_id = t.id 
        WHERE fr.receipt_no = ?
    ");
    $stmt->execute([$receipt_no]);
    $receiving = $stmt->fetch();
} elseif(isset($_SESSION['last_receiving'])) {
    $receiving = $_SESSION['last_receiving'];
} else {
    die('No receiving record found to print');
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Fuel Receiving</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none;
            }
            @page {
                size: A4;
                margin: 10mm;
            }
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            padding: 20px;
            background: #fff;
        }
        
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border: 1px solid #ddd;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        .company-name {
            font-size: 24px;
            font-weight: bold;
        }
        
        .receipt-title {
            font-size: 18px;
            font-weight: bold;
            margin-top: 5px;
        }
        
        .info-section {
            margin-bottom: 20px;
            padding: 10px;
            background: #f8f9fa;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .info-label {
            font-weight: bold;
            width: 150px;
        }
        
        .info-value {
            flex: 1;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        th, td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
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
        
        .total-section {
            margin-top: 20px;
            padding: 10px;
            background: #f8f9fa;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .grand-total {
            font-weight: bold;
            font-size: 16px;
            border-top: 1px solid #000;
            padding-top: 5px;
            margin-top: 5px;
        }
        
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #666;
        }
        
        .signature {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        
        .signature-box {
            text-align: center;
            width: 200px;
        }
        
        .signature-line {
            border-top: 1px solid #000;
            margin-top: 40px;
            padding-top: 5px;
        }
        
        .btn {
            padding: 10px 20px;
            margin: 10px;
            cursor: pointer;
            border: none;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .btn-print {
            background: #4CAF50;
            color: white;
        }
        
        .btn-close {
            background: #f44336;
            color: white;
        }
        
        .button-bar {
            text-align: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="button-bar no-print">
        <button class="btn btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> Print Receipt
        </button>
        <button class="btn btn-close" onclick="window.close()">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
    
    <div class="receipt-container">
        <div class="header">
            <div class="company-name"><?php echo $settings['company_name'] ?? 'FF ENTERPRISE'; ?></div>
            <div><?php echo $settings['company_address'] ?? 'Dhaka, Bangladesh'; ?></div>
            <div>Phone: <?php echo $settings['company_phone'] ?? '+880 1234 567890'; ?></div>
            <div class="receipt-title">FUEL RECEIVING VOUCHER</div>
        </div>
        
        <div class="info-section">
            <div class="info-row">
                <span class="info-label">Receipt No:</span>
                <span class="info-value"><strong><?php echo $receiving['receipt_no']; ?></strong></span>
            </div>
            <div class="info-row">
                <span class="info-label">Date:</span>
                <span class="info-value"><?php echo date('d/m/Y', strtotime($receiving['receipt_date'])); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Supplier:</span>
                <span class="info-value"><?php echo $receiving['supplier_name']; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Tanker No:</span>
                <span class="info-value"><?php echo $receiving['tanker_no'] ?: '-'; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Challan No:</span>
                <span class="info-value"><?php echo $receiving['challan_no'] ?: '-'; ?></span>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Particulars</th>
                    <th class="text-center">Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Product</strong></td>
                    <td><?php echo $receiving['product_name']; ?></td>
                </tr>
                <tr>
                    <td><strong>Tank</strong></td>
                    <td><?php echo $receiving['tank_name']; ?></td>
                </tr>
                <tr>
                    <td><strong>Expected Quantity</strong></td>
                    <td class="text-right"><?php echo number_format($receiving['expected_quantity'], 2); ?> Liters</td>
                </tr>
                <tr>
                    <td><strong>Actual Quantity Received</strong></td>
                    <td class="text-right"><?php echo number_format($receiving['actual_quantity'], 2); ?> Liters</td>
                </tr>
                <tr>
                    <td><strong>Shortage</strong></td>
                    <td class="text-right text-danger"><?php echo number_format($receiving['shortage'], 2); ?> Liters</td>
                </tr>
                <tr>
                    <td><strong>Unit Price</strong></td>
                    <td class="text-right"><?php echo $currency; ?> <?php echo number_format($receiving['unit_price'], 2); ?> / Liter</td>
                </tr>
                <tr>
                    <td><strong>Freight Cost</strong></td>
                    <td class="text-right"><?php echo $currency; ?> <?php echo number_format($receiving['freight_cost'], 2); ?></td>
                </tr>
                <tr>
                    <td><strong>Freight Deduction</strong></td>
                    <td class="text-right"><?php echo $currency; ?> <?php echo number_format($receiving['freight_deduction'], 2); ?></td>
                </tr>
            </tbody>
        </table>
        
        <div class="total-section">
            <div class="total-row">
                <span><strong>Total Amount:</strong></span>
                <span><strong><?php echo $currency; ?> <?php echo number_format($receiving['total_amount'], 2); ?></strong></span>
            </div>
            <?php if($receiving['freight_deduction'] > 0): ?>
            <div class="total-row">
                <span>Less: Freight Deduction</span>
                <span><?php echo $currency; ?> <?php echo number_format($receiving['freight_deduction'], 2); ?></span>
            </div>
            <div class="grand-total">
                <span><strong>Net Payable:</strong></span>
                <span><strong><?php echo $currency; ?> <?php echo number_format($receiving['total_amount'] - $receiving['freight_deduction'], 2); ?></strong></span>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="signature">
            <div class="signature-box">
                <div class="signature-line">Receiver Signature</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">Supplier Signature</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">Authorized By</div>
            </div>
        </div>
        
        <div class="footer">
            <p>This is a computer generated voucher. Valid with authorized signature.</p>
            <p>*** Thank you for your business ***</p>
            <p>Generated on: <?php echo date('d/m/Y h:i:s A'); ?></p>
        </div>
    </div>
    
    <script>
        // Auto print when loaded from session
        <?php if(isset($_SESSION['last_receiving'])): ?>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
        <?php endif; ?>
    </script>
</body>
</html>
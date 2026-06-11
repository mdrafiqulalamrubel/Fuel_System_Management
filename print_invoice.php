<?php
session_start();
if(!isset($_SESSION['last_invoice'])) {
    echo "<h3>No invoice to print</h3>";
    echo "<p>Please complete a sale first.</p>";
    echo '<button onclick="window.close()">Close Window</button>';
    exit;
}

$invoice = $_SESSION['last_invoice'];

// Get product name from database
require_once 'config/database.php';
$product_name = 'Fuel';
if(isset($invoice['product_id']) && $invoice['product_id']) {
    $stmt = $pdo->prepare("SELECT product_name FROM fuel_products WHERE id = ?");
    $stmt->execute([$invoice['product_id']]);
    $product = $stmt->fetch();
    $product_name = $product['product_name'] ?? $invoice['product'] ?? 'Fuel';
} else {
    $product_name = $invoice['product'] ?? 'Fuel';
}

// Get company settings
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print Invoice</title>
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
                size: auto;
                margin: 0mm;
            }
        }
        
        body {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            width: 280px;
            margin: 0 auto;
            padding: 10px;
            background: white;
        }
        
        .invoice {
            width: 100%;
        }
        
        .header {
            text-align: center;
            border-bottom: 1px dashed #000;
            margin-bottom: 8px;
            padding-bottom: 8px;
        }
        
        .company-name {
            font-size: 16px;
            font-weight: bold;
        }
        
        .company-details {
            font-size: 9px;
        }
        
        .info-section {
            margin-bottom: 8px;
            padding-bottom: 5px;
            border-bottom: 1px dotted #000;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
            font-size: 10px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        
        th, td {
            padding: 4px 2px;
            text-align: left;
            font-size: 10px;
        }
        
        th {
            border-bottom: 1px dashed #000;
        }
        
        .text-right {
            text-align: right;
        }
        
        .total-section {
            margin-top: 8px;
            padding-top: 5px;
            border-top: 1px dashed #000;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
            font-size: 10px;
        }
        
        .grand-total {
            font-weight: bold;
            font-size: 12px;
            margin-top: 5px;
            padding-top: 5px;
            border-top: 1px solid #000;
        }
        
        .footer {
            text-align: center;
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1px dashed #000;
            font-size: 9px;
        }
        
        .thankyou {
            font-size: 11px;
            font-weight: bold;
        }
        
        .no-print {
            text-align: center;
            margin-bottom: 15px;
            padding: 10px;
            background: #f0f0f0;
        }
        
        .btn {
            padding: 8px 16px;
            margin: 0 5px;
            font-size: 12px;
            cursor: pointer;
            border: none;
            border-radius: 4px;
        }
        
        .btn-print {
            background: #4CAF50;
            color: white;
        }
        
        .btn-close {
            background: #f44336;
            color: white;
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn btn-print" onclick="printInvoice()">🖨️ Print</button>
        <button class="btn btn-close" onclick="closeWindow()">❌ Close</button>
    </div>
    
    <div class="invoice" id="invoiceContent">
        <div class="header">
            <div class="company-name"><?php echo $settings['company_name'] ?? 'FF ENTERPRISE'; ?></div>
            <div class="company-details"><?php echo $settings['company_address'] ?? 'Gas Station, Dhaka'; ?></div>
            <div class="company-details">Tel: <?php echo $settings['company_phone'] ?? '017xxxxxxxx'; ?></div>
            <div class="company-details">VAT: <?php echo $settings['vat_reg_no'] ?? '123456789'; ?></div>
            <div style="margin-top: 5px;"><strong>FUEL SALE INVOICE</strong></div>
        </div>
        
        <div class="info-section">
            <div class="info-row">
                <span>Invoice:</span>
                <span><strong><?php echo $invoice['invoice_no']; ?></strong></span>
            </div>
            <div class="info-row">
                <span>Date:</span>
                <span><?php echo date('d/m/Y h:i A', strtotime($invoice['date'])); ?></span>
            </div>
            <div class="info-row">
                <span>Customer:</span>
                <span><?php echo $invoice['customer_name'] ?: 'Walk-in Customer'; ?></span>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Price</th>
                    <th class="text-right">Amt</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo $product_name; ?></td>
                    <td class="text-right"><?php echo number_format($invoice['quantity'], 2); ?>L</td>
                    <td class="text-right"><?php echo number_format($invoice['unit_price'], 2); ?></td>
                    <td class="text-right"><?php echo number_format($invoice['subtotal'], 2); ?></td>
                </tr>
            </tbody>
        </table>
        
        <div class="total-section">
            <div class="total-row">
                <span>Subtotal:</span>
                <span><?php echo number_format($invoice['subtotal'], 2); ?></span>
            </div>
            <div class="total-row">
                <span>VAT (5%):</span>
                <span><?php echo number_format($invoice['vat'], 2); ?></span>
            </div>
            <div class="total-row">
                <span>Tax (2%):</span>
                <span><?php echo number_format($invoice['tax'], 2); ?></span>
            </div>
            <div class="grand-total">
                <div class="total-row">
                    <span><strong>TOTAL:</strong></span>
                    <span><strong>BDT <?php echo number_format($invoice['total'], 2); ?></strong></span>
                </div>
            </div>
        </div>
        
        <?php if(isset($invoice['received']) && $invoice['received'] > 0): ?>
        <div style="margin-top: 5px;">
            <div class="total-row">
                <span>Received:</span>
                <span>BDT <?php echo number_format($invoice['received'], 2); ?></span>
            </div>
            <div class="total-row">
                <span>Change:</span>
                <span>BDT <?php echo number_format($invoice['change'], 2); ?></span>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="footer">
            <div class="thankyou">*** THANK YOU ***</div>
            <div>Fuel not returnable</div>
            <div><?php echo $invoice['invoice_no']; ?></div>
            <div><?php echo date('d/m/Y H:i:s'); ?></div>
        </div>
    </div>
    
    <script>
        function printInvoice() {
            // Hide buttons
            document.querySelector('.no-print').style.display = 'none';
            // Print
            window.print();
            // Show buttons after print (for possible reprint)
            setTimeout(function() {
                document.querySelector('.no-print').style.display = 'block';
            }, 1000);
        }
        
        function closeWindow() {
            window.close();
        }
        
        // Auto print on load
        window.onload = function() {
            // Small delay to ensure everything is rendered
            setTimeout(function() {
                printInvoice();
            }, 300);
        };
        
        // Auto close after printing
        window.onafterprint = function() {
            // Close after 2 seconds
            setTimeout(function() {
                window.close();
            }, 1500);
        };
    </script>
</body>
</html>
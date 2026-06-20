<?php
// No need for session_start() here - it's already in database.php
require_once 'config/database.php';

// If invoice_no is passed via GET, fetch that invoice instead of session
if(isset($_GET['invoice_no'])) {
    $invoice_no = $_GET['invoice_no'];
    $stmt = $pdo->prepare("
        SELECT s.*, p.product_name 
        FROM sales s 
        JOIN fuel_products p ON s.product_id = p.id 
        WHERE s.invoice_no = ?
    ");
    $stmt->execute([$invoice_no]);
    $sale = $stmt->fetch();
    
    if($sale) {
        $invoice = [
            'invoice_no' => $sale['invoice_no'],
            'customer_name' => $sale['customer_name'],
            'customer_phone' => $sale['customer_phone'],
            'vehicle_number' => $sale['vehicle_number'] ?? '',
            'remarks' => $sale['remarks'] ?? '',
            'date' => $sale['sale_date'],
            'product_id' => $sale['product_id'],
            'product' => $sale['product_name'],
            'quantity' => $sale['quantity_liters'],
            'unit_price' => $sale['unit_price'],
            'subtotal' => $sale['subtotal'],
            'vat' => $sale['vat_amount'],
            'tax' => $sale['tax_amount'],
            'total' => $sale['total_amount'],
            'received' => $sale['received_amount'],
            'change' => $sale['change_amount'],
            'sale_type' => $sale['sale_type']
        ];
        $_SESSION['last_invoice'] = $invoice;
    } else {
        die("Invoice not found!");
    }
} elseif(!isset($_SESSION['last_invoice'])) {
    echo "<h3>No invoice to print</h3>";
    echo "<p>Please complete a sale first.</p>";
    echo '<button onclick="window.location.href=\'pos.php\'" class="btn btn-primary">Go to POS</button>';
    exit;
} else {
    $invoice = $_SESSION['last_invoice'];
}

// Get product name from database if needed
$product_name = $invoice['product'] ?? 'Fuel';
if(isset($invoice['product_id']) && $invoice['product_id']) {
    $stmt = $pdo->prepare("SELECT product_name FROM fuel_products WHERE id = ?");
    $stmt->execute([$invoice['product_id']]);
    $product = $stmt->fetch();
    $product_name = $product['product_name'] ?? $invoice['product'] ?? 'Fuel';
}

// Get company settings
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            border-radius: 5px;
        }
        
        .btn {
            padding: 10px 20px;
            margin: 0 5px;
            font-size: 14px;
            cursor: pointer;
            border: none;
            border-radius: 5px;
        }
        
        .btn-print {
            background: #4CAF50;
            color: white;
        }
        
        .btn-back {
            background: #007bff;
            color: white;
        }
        
        .btn-reprint {
            background: #ff9800;
            color: white;
        }
        
        .button-bar {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            background: white;
            padding: 10px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            display: flex;
            gap: 10px;
        }
        
        @media print {
            .button-bar {
                display: none;
            }
        }
        
        .reprint-info {
            text-align: center;
            margin-top: 10px;
            font-size: 8px;
            color: #999;
        }
        
        .vehicle-number {
            background: #f8f9fa;
            padding: 2px 8px;
            border-radius: 3px;
            font-weight: bold;
            color: #333;
        }
    </style>
</head>
<body>
    <!-- Floating Button Bar -->
    <div class="button-bar no-print">
        <button class="btn btn-print" onclick="printInvoice()">
            🖨️ Print Invoice
        </button>
        <button class="btn btn-reprint" onclick="reprintInvoice()">
            🔄 Re-print
        </button>
        <button class="btn btn-back" onclick="goBackToPOS()">
            ◀ Back to POS
        </button>
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
                <span>Invoice No:</span>
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
            <!-- ============================================= -->
            <!-- VEHICLE NUMBER - ADDED -->
            <!-- ============================================= -->
            <?php if(!empty($invoice['vehicle_number'])): ?>
            <div class="info-row">
                <span>Vehicle No:</span>
                <span><strong class="vehicle-number"><?php echo strtoupper(htmlspecialchars($invoice['vehicle_number'])); ?></strong></span>
            </div>
            <?php endif; ?>
            <!-- ============================================= -->
            <!-- REMARKS - ADDED -->
            <!-- ============================================= -->
            <?php if(!empty($invoice['remarks'])): ?>
            <div class="info-row">
                <span>Remarks:</span>
                <span style="font-size:9px; max-width:140px; word-wrap:break-word;"><?php echo htmlspecialchars($invoice['remarks']); ?></span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span>Operator:</span>
                <span><?php echo $_SESSION['user_name'] ?? 'Cashier'; ?></span>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="text-right">Qty (L)</th>
                    <th class="text-right">Price (<?php echo $currency; ?>)</th>
                    <th class="text-right">Amount (<?php echo $currency; ?>)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo $product_name; ?></td>
                    <td class="text-right"><?php echo number_format($invoice['quantity'], 2); ?></td>
                    <td class="text-right"><?php echo number_format($invoice['unit_price'], 2); ?></td>
                    <td class="text-right"><?php echo number_format($invoice['total'], 2); ?></td>
                </tr>
            </tbody>
        </table>
        
        <div class="total-section">
            <div class="grand-total">
                <div class="total-row">
                    <span><strong>TOTAL AMOUNT:</strong></span>
                    <span><strong><?php echo $currency; ?> <?php echo number_format($invoice['total'], 2); ?></strong></span>
                </div>
            </div>
        </div>
        
        <?php if(isset($invoice['received']) && $invoice['received'] > 0): ?>
        <div style="margin-top: 5px;">
            <div class="total-row">
                <span>Cash Received:</span>
                <span><?php echo $currency; ?> <?php echo number_format($invoice['received'], 2); ?></span>
            </div>
            <div class="total-row">
                <span>Change Return:</span>
                <span><?php echo $currency; ?> <?php echo number_format($invoice['change'], 2); ?></span>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="footer">
            <div class="thankyou">*** THANK YOU ***</div>
            <div>Fuel once sold is not returnable</div>
            <div>This is a computer generated invoice</div>
            <div style="margin-top: 5px;"><?php echo $invoice['invoice_no']; ?></div>
            <div><?php echo date('d/m/Y H:i:s'); ?></div>
        </div>
    </div>
    
    <div class="reprint-info no-print">
        <i class="fas fa-info-circle"></i> Original printed on: <?php echo date('d/m/Y H:i:s'); ?>
    </div>
    
    <script>
        let printAttempted = false;
        
        function printInvoice() {
            if(printAttempted) return;
            printAttempted = true;
            
            setTimeout(function() {
                window.print();
                
                window.onafterprint = function() {
                    if(confirm('Print completed! Do you want to go back to POS screen?')) {
                        goBackToPOS();
                    }
                };
            }, 500);
        }
        
        function reprintInvoice() {
            printAttempted = false;
            printInvoice();
        }
        
        function goBackToPOS() {
            window.location.href = 'pos.php';
        }
        
        <?php if(!isset($_GET['from_report'])): ?>
        window.onload = function() {
            setTimeout(function() {
                printInvoice();
            }, 1000);
        };
        <?php endif; ?>
        
        window.onbeforeunload = function() {
            return false;
        };
        
        window.onafterprint = function() {
            window.onbeforeunload = null;
        };
    </script>
</body>
</html>
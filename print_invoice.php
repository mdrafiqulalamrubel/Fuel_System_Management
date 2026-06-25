<?php
// No need for session_start() here - it's already in database.php
require_once 'config/database.php';

// =============================================
// FIX: Check for 'invoice' parameter
// =============================================
if(isset($_GET['invoice'])) {
    $invoice_no = $_GET['invoice'];
    
    // =============================================
    // FIX: Use 'invoice_no' column instead of 'invoice_number'
    // =============================================
    $stmt = $pdo->prepare("
        SELECT s.*, fp.product_name 
        FROM sales s 
        JOIN fuel_products fp ON s.product_id = fp.id 
        WHERE s.invoice_no = ?
    ");
    $stmt->execute([$invoice_no]);
    $sale = $stmt->fetch();
    
    if($sale) {
        $invoice = [
            'invoice_no' => $sale['invoice_no'],
            'customer_name' => $sale['customer_name'] ?? 'Walk-in Customer',
            'customer_phone' => $sale['customer_phone'] ?? '',
            'vehicle_number' => $sale['vehicle_number'] ?? '',
            'remarks' => $sale['remarks'] ?? '',
            'date' => $sale['sale_date'],
            'product_id' => $sale['product_id'],
            'product' => $sale['product_name'],
            'quantity' => $sale['quantity_liters'] ?? $sale['quantity'] ?? 0,
            'unit_price' => $sale['unit_price'],
            'subtotal' => $sale['subtotal'] ?? $sale['total_amount'],
            'vat' => $sale['vat_amount'] ?? 0,
            'tax' => $sale['tax_amount'] ?? 0,
            'total' => $sale['total_amount'],
            'received' => $sale['received_amount'] ?? 0,
            'change' => $sale['change_amount'] ?? 0,
            'sale_type' => $sale['sale_type'] ?? 'cash'
        ];
        $_SESSION['last_invoice'] = $invoice;
    } else {
        die("<div style='text-align:center;padding:50px;font-family:Arial;'>
            <h2>❌ Invoice not found!</h2>
            <p>Invoice number: " . htmlspecialchars($invoice_no) . "</p>
            <a href='pos.php' style='padding:10px 20px;background:#007bff;color:white;text-decoration:none;border-radius:5px;'>Back to POS</a>
        </div>");
    }
} elseif(!isset($_SESSION['last_invoice'])) {
    echo "<div style='text-align:center;padding:50px;font-family:Arial;'>
        <h3>No invoice to print</h3>
        <p>Please complete a sale first.</p>
        <a href='pos.php' style='padding:10px 20px;background:#007bff;color:white;text-decoration:none;border-radius:5px;'>Go to POS</a>
    </div>";
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
$company_name = $settings['company_name'] ?? 'Daffodil Fuel Station';
$company_address = $settings['company_address'] ?? 'Dhaka, Bangladesh';
$company_phone = $settings['company_phone'] ?? '01782382140';
$vat_reg_no = $settings['vat_reg_no'] ?? '1234567890';

// Get cashier name
$cashier_name = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'Cashier';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Invoice - <?php echo $invoice['invoice_no']; ?></title>
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
                display: none !important;
            }
            @page {
                size: auto;
                margin: 5mm;
            }
            .invoice {
                width: 100% !important;
                padding: 5px !important;
            }
        }
        
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            max-width: 300px;
            margin: 0 auto;
            padding: 10px;
            background: #f5f5f5;
        }
        
        .invoice {
            background: white;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            width: 100%;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px dashed #333;
            margin-bottom: 10px;
            padding-bottom: 10px;
        }
        
        .company-name {
            font-size: 18px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        
        .company-details {
            font-size: 10px;
            color: #555;
            margin: 2px 0;
        }
        
        .invoice-title {
            font-size: 14px;
            font-weight: bold;
            margin-top: 5px;
            background: #333;
            color: white;
            padding: 3px 0;
            letter-spacing: 2px;
        }
        
        .info-section {
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px dotted #999;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
            font-size: 11px;
            padding: 2px 0;
        }
        
        .info-row .label {
            color: #555;
        }
        
        .info-row .value {
            font-weight: bold;
        }
        
        .vehicle-number {
            background: #f0f0f0;
            padding: 2px 8px;
            border-radius: 3px;
            font-weight: bold;
            color: #d63031;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        
        th {
            border-bottom: 2px solid #333;
            padding: 5px 3px;
            text-align: left;
            font-size: 11px;
            background: #f8f9fa;
        }
        
        td {
            padding: 5px 3px;
            border-bottom: 1px dotted #ddd;
            font-size: 11px;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .total-section {
            margin-top: 10px;
            padding-top: 8px;
            border-top: 2px solid #333;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
            font-size: 12px;
            padding: 2px 0;
        }
        
        .grand-total {
            font-weight: bold;
            font-size: 14px;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 2px solid #333;
            background: #f8f9fa;
            padding: 8px 5px;
        }
        
        .grand-total .total-row {
            font-size: 14px;
        }
        
        .footer {
            text-align: center;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 2px dashed #333;
            font-size: 10px;
            color: #555;
        }
        
        .thankyou {
            font-size: 14px;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        
        .no-print {
            text-align: center;
            margin-bottom: 15px;
            padding: 10px;
            background: #f0f0f0;
            border-radius: 5px;
        }
        
        .btn {
            padding: 12px 24px;
            margin: 0 5px;
            font-size: 14px;
            cursor: pointer;
            border: none;
            border-radius: 5px;
            font-weight: bold;
            transition: all 0.3s;
        }
        
        .btn-print {
            background: #4CAF50;
            color: white;
        }
        .btn-print:hover {
            background: #45a049;
        }
        
        .btn-back {
            background: #007bff;
            color: white;
        }
        .btn-back:hover {
            background: #0056b3;
        }
        
        .btn-reprint {
            background: #ff9800;
            color: white;
        }
        .btn-reprint:hover {
            background: #e68900;
        }
        
        .button-bar {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            background: white;
            padding: 12px 20px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        @media print {
            .button-bar {
                display: none !important;
            }
        }
        
        .reprint-info {
            text-align: center;
            margin-top: 15px;
            font-size: 9px;
            color: #999;
            padding: 5px;
            border-top: 1px dotted #ddd;
        }
        
        .sale-type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
        }
        
        .sale-type-cash { background: #4CAF50; color: white; }
        .sale-type-credit { background: #ff9800; color: white; }
        .sale-type-advance { background: #2196F3; color: white; }
        
        .error-container {
            text-align: center;
            padding: 50px;
            font-family: Arial, sans-serif;
        }
        .error-container h2 { color: #d63031; font-size: 24px; }
        .error-container p { color: #555; margin: 10px 0; }
        .error-container .btn-back-home {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            display: inline-block;
            margin-top: 10px;
        }
        .error-container .btn-back-home:hover {
            background: #0056b3;
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
            <div class="company-name"><?php echo $company_name; ?></div>
            <div class="company-details"><?php echo $company_address; ?></div>
            <div class="company-details">📞 <?php echo $company_phone; ?></div>
            <?php if(!empty($vat_reg_no)): ?>
            <div class="company-details">VAT Reg: <?php echo $vat_reg_no; ?></div>
            <?php endif; ?>
            <div class="invoice-title">FUEL SALE INVOICE</div>
        </div>
        
        <div class="info-section">
            <div class="info-row">
                <span class="label">Invoice No:</span>
                <span class="value">#<?php echo $invoice['invoice_no']; ?></span>
            </div>
            <div class="info-row">
                <span class="label">Date & Time:</span>
                <span class="value"><?php echo date('d/m/Y h:i A', strtotime($invoice['date'])); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Customer:</span>
                <span class="value"><?php echo htmlspecialchars($invoice['customer_name'] ?: 'Walk-in Customer'); ?></span>
            </div>
            
            <!-- ============================================= -->
            <!-- VEHICLE NUMBER - DISPLAY -->
            <!-- ============================================= -->
            <?php if(!empty($invoice['vehicle_number'])): ?>
            <div class="info-row">
                <span class="label">Vehicle No:</span>
                <span class="value"><span class="vehicle-number"><?php echo strtoupper(htmlspecialchars($invoice['vehicle_number'])); ?></span></span>
            </div>
            <?php endif; ?>
            
            <!-- ============================================= -->
            <!-- REMARKS - DISPLAY -->
            <!-- ============================================= -->
            <?php if(!empty($invoice['remarks'])): ?>
            <div class="info-row">
                <span class="label">Remarks:</span>
                <span class="value" style="font-size:10px;"><?php echo htmlspecialchars($invoice['remarks']); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="info-row">
                <span class="label">Operator:</span>
                <span class="value"><?php echo $cashier_name; ?></span>
            </div>
            <div class="info-row">
                <span class="label">Sale Type:</span>
                <span class="value">
                    <span class="sale-type-badge sale-type-<?php echo $invoice['sale_type']; ?>">
                        <?php echo ucfirst($invoice['sale_type']); ?>
                    </span>
                </span>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th style="width:40%;">Description</th>
                    <th class="text-right" style="width:20%;">Qty</th>
                    <th class="text-right" style="width:20%;">Rate</th>
                    <th class="text-right" style="width:20%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong><?php echo htmlspecialchars($product_name); ?></strong></td>
                    <td class="text-right"><?php echo number_format($invoice['quantity'], 2); ?> L</td>
                    <td class="text-right"><?php echo $currency; ?> <?php echo number_format($invoice['unit_price'], 2); ?></td>
                    <td class="text-right"><strong><?php echo $currency; ?> <?php echo number_format($invoice['total'], 2); ?></strong></td>
                </tr>
            </tbody>
        </table>
        
        <div class="total-section">
            <?php if(isset($invoice['vat']) && $invoice['vat'] > 0): ?>
            <div class="total-row">
                <span>VAT (5%):</span>
                <span><?php echo $currency; ?> <?php echo number_format($invoice['vat'], 2); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if(isset($invoice['tax']) && $invoice['tax'] > 0): ?>
            <div class="total-row">
                <span>Tax:</span>
                <span><?php echo $currency; ?> <?php echo number_format($invoice['tax'], 2); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="grand-total">
                <div class="total-row">
                    <span><strong>TOTAL AMOUNT</strong></span>
                    <span><strong><?php echo $currency; ?> <?php echo number_format($invoice['total'], 2); ?></strong></span>
                </div>
            </div>
        </div>
        
        <?php if(isset($invoice['received']) && $invoice['received'] > 0): ?>
        <div style="margin-top: 8px; padding-top: 8px; border-top: 1px dotted #999;">
            <div class="total-row">
                <span>Cash Received:</span>
                <span><strong><?php echo $currency; ?> <?php echo number_format($invoice['received'], 2); ?></strong></span>
            </div>
            <div class="total-row">
                <span>Change Return:</span>
                <span><strong><?php echo $currency; ?> <?php echo number_format($invoice['change'], 2); ?></strong></span>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="footer">
            <div class="thankyou">✦ THANK YOU ✦</div>
            <div style="margin: 3px 0;">Fuel once sold is not returnable</div>
            <div style="margin: 3px 0;">This is a computer generated invoice</div>
            <div style="margin-top: 5px; font-size: 9px; color: #999;">
                <?php echo $invoice['invoice_no']; ?>
            </div>
            <div style="font-size: 9px; color: #999;">
                Printed: <?php echo date('d/m/Y H:i:s'); ?>
            </div>
        </div>
    </div>
    
    <div class="reprint-info no-print">
        💡 Original printed on: <?php echo date('d/m/Y H:i:s'); ?>
    </div>
    
    <script>
        let printAttempted = false;
        
        function printInvoice() {
            if(printAttempted) return;
            printAttempted = true;
            
            setTimeout(function() {
                window.print();
                
                window.onafterprint = function() {
                    printAttempted = false;
                };
            }, 500);
        }
        
        function reprintInvoice() {
            printAttempted = false;
            setTimeout(function() {
                window.print();
            }, 300);
        }
        
        function goBackToPOS() {
            window.location.href = 'pos.php';
        }
        
        <?php if(!isset($_GET['from_report'])): ?>
        window.onload = function() {
            setTimeout(function() {
                printInvoice();
            }, 800);
        };
        <?php endif; ?>
        
        window.onbeforeunload = function(e) {
            if(!printAttempted) {
                e.preventDefault();
                e.returnValue = 'Are you sure you want to leave? The invoice is being printed.';
                return e.returnValue;
            }
        };
        
        window.onafterprint = function() {
            window.onbeforeunload = null;
        };
    </script>
</body>
</html>
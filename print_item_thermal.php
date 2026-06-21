<?php
// print_item_thermal.php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$invoice_no = isset($_GET['invoice']) ? $_GET['invoice'] : '';
if(empty($invoice_no)) {
    die("No invoice number provided");
}

// Get sale details from item_sales
$stmt = $pdo->prepare("
    SELECT 
        isales.*,
        u.full_name as operator_name,
        sh.shift_name
    FROM item_sales isales
    LEFT JOIN users u ON isales.created_by = u.id
    LEFT JOIN shift_closing sc ON isales.shift_id = sc.id
    LEFT JOIN shift_schedule sh ON sc.shift_id = sh.id
    WHERE isales.invoice_no = ?
");
$stmt->execute([$invoice_no]);
$sale = $stmt->fetch();

if(!$sale) {
    die("Sale not found");
}

// Get sale items
$stmt = $pdo->prepare("
    SELECT 
        si.*,
        i.item_name,
        i.item_code,
        i.unit,
        i.item_type
    FROM item_sale_items si
    JOIN items i ON si.item_id = i.id
    WHERE si.sale_id = ?
");
$stmt->execute([$sale['id']]);
$items = $stmt->fetchAll();

// Get company settings
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$company_name = $settings['company_name'] ?? 'Fuel Station';
$company_address = $settings['company_address'] ?? '';
$company_phone = $settings['company_phone'] ?? '';
$company_email = $settings['company_email'] ?? '';
$currency = $settings['currency_symbol'] ?? 'BDT';
$tax_id = $settings['tax_id'] ?? '';

$subtotal = $sale['subtotal'];
$discount = $sale['discount_amount'];
$tax = $sale['tax_amount'];
$total = $sale['total_amount'];
$received = $sale['received_amount'];
$change = $sale['change_amount'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thermal Invoice - <?php echo $invoice_no; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        @page { size: 80mm auto; margin: 0; }
        body {
            font-family: 'Courier New', monospace;
            font-size: 11px;
            line-height: 1.3;
            background: #fff;
            color: #000;
            width: 80mm;
            margin: 0 auto;
            padding: 5px 10px;
        }
        .invoice-wrapper { max-width: 80mm; margin: 0 auto; padding: 5px 0; }
        .header {
            text-align: center;
            border-bottom: 1px dashed #000;
            padding-bottom: 5px;
            margin-bottom: 8px;
        }
        .company-name {
            font-size: 16px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .company-details {
            font-size: 9px;
            line-height: 1.2;
            margin: 3px 0;
        }
        .invoice-title {
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            margin: 5px 0;
            letter-spacing: 2px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 1px 0;
            font-size: 10px;
        }
        .info-row .label { font-weight: bold; }
        .info-row .value { text-align: right; }
        .divider { border-top: 1px dashed #000; margin: 5px 0; }
        .divider-double { border-top: 2px solid #000; margin: 5px 0; }
        .divider-thin { border-top: 1px dotted #000; margin: 4px 0; }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            margin: 5px 0;
        }
        .items-table th {
            border-bottom: 1px solid #000;
            padding: 3px 2px;
            text-align: left;
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
        }
        .items-table td {
            padding: 2px 2px;
            border-bottom: 1px dotted #ccc;
        }
        .items-table .text-right { text-align: right; }
        .items-table .text-center { text-align: center; }
        .totals { margin: 5px 0; }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
            font-size: 10px;
        }
        .total-row .label { font-weight: bold; }
        .total-row .amount { font-weight: bold; }
        .grand-total {
            font-size: 14px;
            font-weight: bold;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            padding: 5px 0;
            margin: 5px 0;
        }
        .grand-total .label { font-size: 12px; }
        .grand-total .amount { font-size: 16px; }
        .footer {
            text-align: center;
            font-size: 8px;
            border-top: 1px dashed #000;
            padding-top: 5px;
            margin-top: 8px;
        }
        .footer .thank-you { font-size: 12px; font-weight: bold; margin: 5px 0; }
        .badge {
            display: inline-block;
            padding: 1px 6px;
            font-size: 8px;
            font-weight: bold;
            border: 1px solid #000;
            background: #fff;
        }
        .barcode {
            text-align: center;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            letter-spacing: 1px;
            margin: 5px 0;
        }
        .print-btn-container {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        .print-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 30px;
            font-size: 16px;
            border-radius: 5px;
            cursor: pointer;
        }
        .print-btn:hover { background: #218838; }
        .back-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 30px;
            font-size: 16px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .no-print { display: block; }
        @media print {
            .no-print { display: none !important; }
            body { padding: 2px 5px !important; margin: 0 !important; width: 80mm !important; }
            .invoice-wrapper { padding: 0 !important; }
            * { font-family: 'Courier New', monospace !important; }
        }
    </style>
</head>
<body>
    <!-- Print Button -->
    <div class="print-btn-container no-print">
        <button onclick="window.print()" class="print-btn">
            <i class="fas fa-print"></i> Print Invoice
        </button>
        <a href="item_pos.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to POS
        </a>
        <br><br>
        <small style="color: #6c757d;">
            <i class="fas fa-info-circle"></i> 
            Ensure thermal printer is set as default. Paper size: 80mm.
        </small>
    </div>

    <!-- THERMAL INVOICE CONTENT -->
    <div class="invoice-wrapper">
        <!-- HEADER -->
        <div class="header">
            <div class="company-name"><?php echo htmlspecialchars($company_name); ?></div>
            <div class="company-details">
                <?php if($company_address): ?>
                    <div><?php echo htmlspecialchars($company_address); ?></div>
                <?php endif; ?>
                <?php if($company_phone): ?>
                    <div>📞 <?php echo htmlspecialchars($company_phone); ?></div>
                <?php endif; ?>
                <?php if($company_email): ?>
                    <div>✉ <?php echo htmlspecialchars($company_email); ?></div>
                <?php endif; ?>
                <?php if($tax_id): ?>
                    <div>TAX: <?php echo htmlspecialchars($tax_id); ?></div>
                <?php endif; ?>
            </div>
            <div class="divider"></div>
            <div class="invoice-title">ITEM SALE RECEIPT</div>
        </div>

        <!-- INVOICE INFO -->
        <div class="info-row">
            <span class="label">Invoice No:</span>
            <span class="value"><?php echo htmlspecialchars($invoice_no); ?></span>
        </div>
        <div class="info-row">
            <span class="label">Date:</span>
            <span class="value"><?php echo date('d-m-Y h:i A', strtotime($sale['sale_date'])); ?></span>
        </div>
        <?php if($sale['shift_name']): ?>
        <div class="info-row">
            <span class="label">Shift:</span>
            <span class="value"><?php echo htmlspecialchars($sale['shift_name']); ?></span>
        </div>
        <?php endif; ?>
        <?php if($sale['operator_name']): ?>
        <div class="info-row">
            <span class="label">Operator:</span>
            <span class="value"><?php echo htmlspecialchars($sale['operator_name']); ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
            <span class="label">Payment:</span>
            <span class="value">
                <?php if($sale['sale_type'] == 'cash'): ?>
                    <span class="badge">CASH</span>
                <?php else: ?>
                    <span class="badge">CREDIT</span>
                <?php endif; ?>
            </span>
        </div>
        
        <!-- Customer Info -->
        <?php if($sale['customer_name'] || $sale['customer_phone']): ?>
        <div class="divider-thin"></div>
        <div class="info-row">
            <span class="label">Customer:</span>
            <span class="value"><?php echo htmlspecialchars($sale['customer_name'] ?? 'N/A'); ?></span>
        </div>
        <?php if($sale['customer_phone']): ?>
        <div class="info-row">
            <span class="label">Phone:</span>
            <span class="value"><?php echo htmlspecialchars($sale['customer_phone']); ?></span>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        
        <!-- Notes -->
        <?php if(!empty($sale['notes'])): ?>
        <div class="info-row">
            <span class="label">Notes:</span>
            <span class="value" style="max-width:50%; word-wrap:break-word;"><?php echo htmlspecialchars($sale['notes']); ?></span>
        </div>
        <?php endif; ?>
        
        <div class="divider"></div>

        <!-- SALE ITEMS TABLE -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width:45%;">Item</th>
                    <th style="width:20%;" class="text-right">Qty</th>
                    <th style="width:35%;" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($items as $item): ?>
                <tr>
                    <td>
                        <?php echo htmlspecialchars($item['item_name']); ?>
                        <?php if($item['item_type'] == 'product'): ?>
                            <br><small style="font-size:7px;"><?php echo $item['item_code']; ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <?php echo number_format($item['quantity'], 2); ?> <?php echo $item['unit']; ?>
                        <br><small style="font-size:7px;">@ <?php echo $currency; ?> <?php echo number_format($item['unit_price'], 2); ?></small>
                    </td>
                    <td class="text-right">
                        <?php echo $currency; ?> <?php echo number_format($item['total_amount'], 2); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="divider"></div>

        <!-- TOTALS -->
        <div class="totals">
            <div class="total-row">
                <span class="label">Subtotal:</span>
                <span class="amount"><?php echo $currency; ?> <?php echo number_format($subtotal, 2); ?></span>
            </div>
            <?php if($discount > 0): ?>
            <div class="total-row" style="color:#dc3545;">
                <span class="label">Discount:</span>
                <span class="amount">- <?php echo $currency; ?> <?php echo number_format($discount, 2); ?></span>
            </div>
            <?php endif; ?>
            <?php if($tax > 0): ?>
            <div class="total-row">
                <span class="label">Tax:</span>
                <span class="amount"><?php echo $currency; ?> <?php echo number_format($tax, 2); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- GRAND TOTAL -->
        <div class="grand-total">
            <div class="total-row">
                <span class="label">TOTAL:</span>
                <span class="amount"><?php echo $currency; ?> <?php echo number_format($total, 2); ?></span>
            </div>
            <?php if($sale['sale_type'] == 'cash'): ?>
            <div class="total-row" style="font-size:10px; font-weight:normal; border-top:1px dotted #000; padding-top:3px;">
                <span>Received:</span>
                <span><?php echo $currency; ?> <?php echo number_format($received, 2); ?></span>
            </div>
            <div class="total-row" style="font-size:10px; font-weight:normal;">
                <span>Change:</span>
                <span><?php echo $currency; ?> <?php echo number_format($change, 2); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- BARCODE -->
        <div class="divider"></div>
        <div class="barcode">
            <?php echo substr($invoice_no, -8); ?>
        </div>

        <!-- FOOTER -->
        <div class="footer">
            <div class="thank-you">Thank You!</div>
            <div>Visit Again</div>
            <div class="divider-thin"></div>
            <div style="font-size:7px;">
                Invoice: <?php echo htmlspecialchars($invoice_no); ?> | 
                <?php echo date('d-m-Y h:i A'); ?>
            </div>
            <div style="font-size:6px; margin-top:3px; color:#999;">
                Please keep this receipt for your records
            </div>
        </div>
    </div>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        // Auto-print on load (uncomment to enable)
        // window.onload = function() { window.print(); };
    </script>
</body>
</html>
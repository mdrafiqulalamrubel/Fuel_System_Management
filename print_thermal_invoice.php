<?php
// print_thermal_invoice.php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$invoice_no = isset($_GET['invoice']) ? $_GET['invoice'] : '';
if(empty($invoice_no)) {
    die("No invoice number provided");
}

// Get sale details
$stmt = $pdo->prepare("
    SELECT 
        gs.*,
        n.nozzle_name,
        n.is_pipeline,
        u.username as operator_name,
        u.full_name as operator_full_name,
        sh.shift_name
    FROM gas_sales gs
    LEFT JOIN nozzles n ON gs.nozzle_id = n.id
    LEFT JOIN users u ON gs.operator_id = u.id
    LEFT JOIN shift_closing sc ON gs.shift_id = sc.id
    LEFT JOIN shift_schedule sh ON sc.shift_id = sh.id
    WHERE gs.invoice_no = ?
");
$stmt->execute([$invoice_no]);
$sale = $stmt->fetch();

if(!$sale) {
    die("Sale not found");
}

// Get company settings
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$company_name = $settings['company_name'] ?? 'Fuel Station';
$company_address = $settings['company_address'] ?? '';
$company_phone = $settings['company_phone'] ?? '';
$company_email = $settings['company_email'] ?? '';
$currency = $settings['currency_symbol'] ?? 'BDT';
$vat_rate = $settings['vat_rate'] ?? 0;
$tax_id = $settings['tax_id'] ?? '';

// Check if sale is completed
$is_cng = strpos($sale['invoice_no'], 'CNG-') === 0;
$unit_label = $is_cng ? 'm³' : 'Liters';

// Calculate VAT if applicable
$vat_amount = 0;
$total_with_vat = $sale['total_amount'];
if($vat_rate > 0) {
    $vat_amount = $sale['total_amount'] * ($vat_rate / 100);
    $total_with_vat = $sale['total_amount'] + $vat_amount;
}

// Get credit sale details if any
$credit_info = null;
if($sale['sale_type'] == 'credit') {
    $stmt = $pdo->prepare("
        SELECT * FROM credit_sales 
        WHERE sale_id = ? AND invoice_no = ?
    ");
    $stmt->execute([$sale['id'], $invoice_no]);
    $credit_info = $stmt->fetch();
}

// Advance used info
$advance_used = $sale['advance_used'] ?? 0;
$total_payable = $sale['total_amount'];

// Determine payment status
$payment_status = 'Paid';
$payment_text = 'Cash';
if($sale['sale_type'] == 'credit') {
    $payment_status = 'Credit';
    $payment_text = 'Credit Sale';
    if($credit_info) {
        $payment_status = $credit_info['balance_due'] > 0 ? 'Due: ' . number_format($credit_info['balance_due'], 2) : 'Paid';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thermal Invoice - <?php echo $invoice_no; ?></title>
    <style>
        /* THERMAL PRINTER OPTIMIZED STYLES */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        @page {
            size: 80mm auto;
            margin: 0;
        }
        
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
        
        /* Print container */
        .invoice-wrapper {
            max-width: 80mm;
            margin: 0 auto;
            padding: 5px 0;
        }
        
        /* Header section */
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
        
        /* Info rows */
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 1px 0;
            font-size: 10px;
        }
        
        .info-row .label {
            font-weight: bold;
        }
        
        .info-row .value {
            text-align: right;
        }
        
        /* Divider */
        .divider {
            border-top: 1px dashed #000;
            margin: 5px 0;
        }
        
        .divider-double {
            border-top: 2px solid #000;
            margin: 5px 0;
        }
        
        .divider-thin {
            border-top: 1px dotted #000;
            margin: 4px 0;
        }
        
        /* Table */
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
        
        .items-table .text-right {
            text-align: right;
        }
        
        .items-table .text-center {
            text-align: center;
        }
        
        /* Totals */
        .totals {
            margin: 5px 0;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
            font-size: 10px;
        }
        
        .total-row .label {
            font-weight: bold;
        }
        
        .total-row .amount {
            font-weight: bold;
        }
        
        .grand-total {
            font-size: 14px;
            font-weight: bold;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            padding: 5px 0;
            margin: 5px 0;
        }
        
        .grand-total .label {
            font-size: 12px;
        }
        
        .grand-total .amount {
            font-size: 16px;
        }
        
        /* Footer */
        .footer {
            text-align: center;
            font-size: 8px;
            border-top: 1px dashed #000;
            padding-top: 5px;
            margin-top: 8px;
        }
        
        .footer .thank-you {
            font-size: 12px;
            font-weight: bold;
            margin: 5px 0;
        }
        
        /* Status badges */
        .badge {
            display: inline-block;
            padding: 1px 6px;
            font-size: 8px;
            font-weight: bold;
            border: 1px solid #000;
        }
        
        .badge-cash {
            border: 1px solid #000;
            background: #fff;
        }
        
        .badge-credit {
            border: 1px solid #000;
            background: #fff;
        }
        
        /* Utility */
        .text-center {
            text-align: center;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-bold {
            font-weight: bold;
        }
        
        .mt-1 { margin-top: 4px; }
        .mb-1 { margin-bottom: 4px; }
        .mt-2 { margin-top: 8px; }
        .mb-2 { margin-bottom: 8px; }
        
        /* Nozzle type */
        .nozzle-type {
            font-size: 8px;
            background: #f0f0f0;
            padding: 1px 4px;
            border: 1px solid #ccc;
        }
        
        /* Barcode placeholder */
        .barcode {
            text-align: center;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            letter-spacing: 1px;
            margin: 5px 0;
        }
        
        /* Print button */
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
        
        .print-btn:hover {
            background: #218838;
        }
        
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
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            body {
                padding: 2px 5px !important;
                margin: 0 !important;
                width: 80mm !important;
            }
            
            .invoice-wrapper {
                padding: 0 !important;
            }
            
            .badge {
                border: 1px solid #000 !important;
            }
            
            .nozzle-type {
                background: #f0f0f0 !important;
                border: 1px solid #ccc !important;
            }
            
            /* Force monospace for thermal printers */
            * {
                font-family: 'Courier New', monospace !important;
            }
        }
        
        /* Ensure monospace for thermal */
        .thermal-font {
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <!-- Print Button (Not visible in print) -->
    <div class="print-btn-container no-print">
        <button onclick="window.print()" class="print-btn">
            <i class="fas fa-print"></i> Print Invoice
        </button>
        <a href="<?php echo $is_cng ? 'gas_sales.php' : 'sales.php'; ?>" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <br><br>
        <small style="color: #6c757d;">
            <i class="fas fa-info-circle"></i> 
            Ensure thermal printer is set as default. Paper size: 80mm.
        </small>
    </div>

    <!-- ============================================= -->
    <!-- THERMAL INVOICE CONTENT (80mm) -->
    <!-- ============================================= -->
    <div class="invoice-wrapper" id="invoiceContent">
        
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
            <div class="invoice-title">
                <?php echo $is_cng ? 'CNG SALE RECEIPT' : 'FUEL SALE RECEIPT'; ?>
            </div>
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
        <?php if($sale['operator_full_name']): ?>
        <div class="info-row">
            <span class="label">Operator:</span>
            <span class="value"><?php echo htmlspecialchars($sale['operator_full_name']); ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
            <span class="label">Payment:</span>
            <span class="value">
                <?php if($sale['sale_type'] == 'cash'): ?>
                    <span class="badge badge-cash">CASH</span>
                <?php else: ?>
                    <span class="badge badge-credit">CREDIT</span>
                <?php endif; ?>
            </span>
        </div>
        
        <!-- Customer Info (if credit sale) -->
        <?php if($sale['sale_type'] == 'credit' && ($sale['customer_name'] || $sale['customer_phone'])): ?>
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
        <?php if($credit_info && $credit_info['due_date']): ?>
        <div class="info-row">
            <span class="label">Due Date:</span>
            <span class="value"><?php echo date('d-m-Y', strtotime($credit_info['due_date'])); ?></span>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        
        <!-- Vehicle Number (if exists) -->
        <?php if(!empty($sale['vehicle_number'])): ?>
        <div class="info-row">
            <span class="label">Vehicle:</span>
            <span class="value"><?php echo htmlspecialchars($sale['vehicle_number']); ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Remarks (if exists) -->
        <?php if(!empty($sale['remarks'])): ?>
        <div class="info-row">
            <span class="label">Remarks:</span>
            <span class="value" style="max-width:50%; word-wrap:break-word;"><?php echo htmlspecialchars($sale['remarks']); ?></span>
        </div>
        <?php endif; ?>
        
        <div class="divider"></div>

        <!-- SALE DETAILS TABLE -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width:35%;">Description</th>
                    <th style="width:20%;" class="text-right">Qty</th>
                    <th style="width:20%;" class="text-right">Rate</th>
                    <th style="width:25%;" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <?php 
                        $product_name = $is_cng ? 'CNG' : ($sale['nozzle_name'] ?? 'Fuel');
                        echo htmlspecialchars($product_name);
                        if($sale['is_pipeline'] ?? false) {
                            echo ' <span class="nozzle-type">Pipeline</span>';
                        }
                        ?>
                    </td>
                    <td class="text-right">
                        <?php echo number_format($sale['quantity_liters'], 2); ?> <?php echo $unit_label; ?>
                    </td>
                    <td class="text-right">
                        <?php echo $currency; ?> <?php echo number_format($sale['unit_price'], 2); ?>
                    </td>
                    <td class="text-right">
                        <?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?>
                    </td>
                </tr>
                <?php if($advance_used > 0): ?>
                <tr>
                    <td colspan="3" class="text-right"><em>Advance Used</em></td>
                    <td class="text-right">- <?php echo $currency; ?> <?php echo number_format($advance_used, 2); ?></td>
                </tr>
                <?php endif; ?>
                <?php if($vat_rate > 0 && $vat_amount > 0): ?>
                <tr>
                    <td colspan="3" class="text-right">VAT (<?php echo number_format($vat_rate, 0); ?>%)</td>
                    <td class="text-right"><?php echo $currency; ?> <?php echo number_format($vat_amount, 2); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="divider"></div>

        <!-- TOTALS -->
        <div class="totals">
            <div class="total-row">
                <span class="label">Subtotal:</span>
                <span class="amount"><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></span>
            </div>
            <?php if($advance_used > 0): ?>
            <div class="total-row" style="color:#dc3545;">
                <span class="label">Advance Deducted:</span>
                <span class="amount">- <?php echo $currency; ?> <?php echo number_format($advance_used, 2); ?></span>
            </div>
            <?php endif; ?>
            <?php if($vat_rate > 0 && $vat_amount > 0): ?>
            <div class="total-row">
                <span class="label">VAT (<?php echo number_format($vat_rate, 0); ?>%):</span>
                <span class="amount"><?php echo $currency; ?> <?php echo number_format($vat_amount, 2); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- GRAND TOTAL -->
        <div class="grand-total">
            <div class="total-row">
                <span class="label">TOTAL:</span>
                <span class="amount"><?php echo $currency; ?> <?php echo number_format($total_payable, 2); ?></span>
            </div>
            <?php if($sale['sale_type'] == 'cash'): ?>
            <div class="total-row" style="font-size:10px; font-weight:normal; border-top:1px dotted #000; padding-top:3px;">
                <span>Received:</span>
                <span><?php echo $currency; ?> <?php echo number_format($sale['received_amount'], 2); ?></span>
            </div>
            <div class="total-row" style="font-size:10px; font-weight:normal;">
                <span>Change:</span>
                <span><?php echo $currency; ?> <?php echo number_format($sale['change_amount'], 2); ?></span>
            </div>
            <?php elseif($sale['sale_type'] == 'credit'): ?>
            <div class="total-row" style="font-size:10px; font-weight:normal; border-top:1px dotted #000; padding-top:3px;">
                <span>Payment Status:</span>
                <span><?php echo $payment_status; ?></span>
            </div>
            <?php if($credit_info && $credit_info['balance_due'] > 0): ?>
            <div class="total-row" style="font-size:10px; font-weight:normal; color:#dc3545;">
                <span>Balance Due:</span>
                <span><?php echo $currency; ?> <?php echo number_format($credit_info['balance_due'], 2); ?></span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- METER READING -->
        <?php if($sale['opening_meter'] > 0 || $sale['closing_meter'] > 0): ?>
        <div class="divider-thin"></div>
        <div class="info-row">
            <span class="label">Opening Meter:</span>
            <span class="value"><?php echo number_format($sale['opening_meter'], 2); ?> <?php echo $unit_label; ?></span>
        </div>
        <div class="info-row">
            <span class="label">Closing Meter:</span>
            <span class="value"><?php echo number_format($sale['closing_meter'], 2); ?> <?php echo $unit_label; ?></span>
        </div>
        <div class="info-row">
            <span class="label">Qty Sold:</span>
            <span class="value"><?php echo number_format($sale['quantity_liters'], 2); ?> <?php echo $unit_label; ?></span>
        </div>
        <?php endif; ?>

        <!-- BARCODE / REFERENCE -->
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
            <?php if($is_cng): ?>
            <div style="font-size:7px; margin-top:2px;">
                CNG supplied by Titas Gas pipeline
            </div>
            <?php endif; ?>
            <?php if($sale['is_pipeline'] ?? false): ?>
            <div style="font-size:7px; margin-top:2px;">
                Pipeline Nozzle - No Stock Deduction
            </div>
            <?php endif; ?>
            <div style="font-size:6px; margin-top:3px; color:#999;">
                Please keep this receipt for your records
            </div>
        </div>
    </div>

    <!-- Font Awesome for print button (not visible in print) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        // Auto-print when page loads (optional)
        window.onload = function() {
            // Uncomment to auto-print:
            // window.print();
        };
        
        // Keyboard shortcut: Ctrl+P to print
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                // Allow normal print dialog
            }
        });
    </script>
</body>
</html>
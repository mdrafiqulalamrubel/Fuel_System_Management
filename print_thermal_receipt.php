<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

// Get invoice from URL or session
$invoice_no = isset($_GET['invoice']) ? $_GET['invoice'] : (isset($_SESSION['last_invoice']['invoice_no']) ? $_SESSION['last_invoice']['invoice_no'] : '');
$type = isset($_GET['type']) ? $_GET['type'] : 'liquid'; // 'liquid' or 'cng'

if(empty($invoice_no)) {
    die('No invoice found to print');
}

// Get sale details based on type
if($type == 'cng') {
    $stmt = $pdo->prepare("
        SELECT gs.*, 
               n.nozzle_name, 
               sh.shift_name,
               u.full_name as operator_name
        FROM gas_sales gs
        JOIN nozzles n ON gs.nozzle_id = n.id
        LEFT JOIN shift_schedule sh ON gs.shift_id = sh.id
        LEFT JOIN users u ON gs.operator_id = u.id
        WHERE gs.invoice_no = ?
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT s.*, 
               n.nozzle_name, 
               t.tank_name,
               p.product_name,
               sh.shift_name,
               u.full_name as operator_name
        FROM sales s
        JOIN nozzles n ON s.nozzle_id = n.id
        JOIN tanks t ON n.tank_id = t.id
        JOIN fuel_products p ON s.product_id = p.id
        LEFT JOIN shift_schedule sh ON s.shift_id = sh.id
        LEFT JOIN users u ON s.operator_id = u.id
        WHERE s.invoice_no = ?
    ");
}
$stmt->execute([$invoice_no]);
$sale = $stmt->fetch();

if(!$sale) {
    die('Invoice not found');
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
$company_name = $settings['company_name'] ?? 'Fuel Station';
$company_address = $settings['company_address'] ?? '';
$company_phone = $settings['company_phone'] ?? '';
$invoice_footer = $settings['invoice_footer'] ?? '*** THANK YOU ***';

// Get product name for liquid sales
if($type != 'cng') {
    $product_name = $sale['product_name'] ?? 'Fuel';
    $unit_label = 'Liters';
    $quantity_label = 'L';
} else {
    $product_name = 'CNG';
    $unit_label = 'Cubic Meters';
    $quantity_label = 'm³';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thermal Receipt</title>
    <style>
        /* Thermal Printer Styles - 80mm width */
        @page {
            size: 80mm auto;
            margin: 0;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
                background: white;
            }
            .receipt {
                margin: 0 auto;
                width: 80mm;
                padding: 5px 8px;
                font-size: 12px;
                font-family: 'Courier New', monospace;
            }
            .no-print {
                display: none !important;
            }
            .receipt-header {
                text-align: center;
                border-bottom: 1px dashed #000;
                padding-bottom: 5px;
                margin-bottom: 5px;
            }
            .receipt-title {
                font-size: 16px;
                font-weight: bold;
                margin-bottom: 2px;
            }
            .receipt-sub {
                font-size: 10px;
                margin: 0;
            }
            .receipt-divider {
                border-top: 1px dashed #000;
                margin: 5px 0;
            }
            .receipt-row {
                display: flex;
                justify-content: space-between;
                padding: 2px 0;
            }
            .receipt-row-total {
                display: flex;
                justify-content: space-between;
                padding: 4px 0;
                font-weight: bold;
                font-size: 14px;
                border-top: 1px solid #000;
            }
            .receipt-footer {
                text-align: center;
                margin-top: 8px;
                padding-top: 5px;
                border-top: 1px dashed #000;
                font-size: 10px;
            }
            .receipt-meters {
                background: #f5f5f5;
                padding: 4px 8px;
                margin: 4px 0;
                border-radius: 3px;
                font-size: 11px;
            }
            .receipt-meters span {
                font-weight: bold;
                font-family: 'Courier New', monospace;
            }
            .text-center {
                text-align: center;
            }
            .text-right {
                text-align: right;
            }
            .text-left {
                text-align: left;
            }
            .fw-bold {
                font-weight: bold;
            }
            .mb-1 {
                margin-bottom: 3px;
            }
            .mt-1 {
                margin-top: 3px;
            }
            .py-1 {
                padding-top: 3px;
                padding-bottom: 3px;
            }
            .badge-cng {
                background: #0d6efd;
                color: white;
                padding: 1px 8px;
                border-radius: 10px;
                font-size: 9px;
            }
            .badge-credit {
                background: #ffc107;
                color: #000;
                padding: 1px 8px;
                border-radius: 10px;
                font-size: 9px;
            }
            .badge-cash {
                background: #28a745;
                color: white;
                padding: 1px 8px;
                border-radius: 10px;
                font-size: 9px;
            }
        }
        
        /* Screen preview */
        body {
            background: #e9ecef;
            font-family: 'Courier New', monospace;
            display: flex;
            justify-content: center;
            padding: 20px;
        }
        .receipt {
            background: white;
            width: 80mm;
            padding: 5px 8px;
            font-size: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            border-radius: 5px;
        }
        .no-print {
            text-align: center;
            margin-bottom: 15px;
        }
        .no-print button {
            padding: 8px 20px;
            margin: 0 5px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-print {
            background: #28a745;
            color: white;
        }
        .btn-back {
            background: #6c757d;
            color: white;
        }
        .btn-thermal {
            background: #17a2b8;
            color: white;
        }
        .receipt-header {
            text-align: center;
            border-bottom: 1px dashed #000;
            padding-bottom: 5px;
            margin-bottom: 5px;
        }
        .receipt-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 2px;
        }
        .receipt-sub {
            font-size: 10px;
            margin: 0;
        }
        .receipt-divider {
            border-top: 1px dashed #000;
            margin: 5px 0;
        }
        .receipt-row {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
        }
        .receipt-row-total {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-weight: bold;
            font-size: 14px;
            border-top: 1px solid #000;
        }
        .receipt-footer {
            text-align: center;
            margin-top: 8px;
            padding-top: 5px;
            border-top: 1px dashed #000;
            font-size: 10px;
        }
        .receipt-meters {
            background: #f5f5f5;
            padding: 4px 8px;
            margin: 4px 0;
            border-radius: 3px;
            font-size: 11px;
        }
        .receipt-meters span {
            font-weight: bold;
            font-family: 'Courier New', monospace;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .text-left {
            text-align: left;
        }
        .fw-bold {
            font-weight: bold;
        }
        .mb-1 {
            margin-bottom: 3px;
        }
        .mt-1 {
            margin-top: 3px;
        }
        .py-1 {
            padding-top: 3px;
            padding-bottom: 3px;
        }
        .badge-cng {
            background: #0d6efd;
            color: white;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 9px;
        }
        .badge-credit {
            background: #ffc107;
            color: #000;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 9px;
        }
        .badge-cash {
            background: #28a745;
            color: white;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 9px;
        }
        .receipt-qr {
            text-align: center;
            margin: 5px 0;
        }
        .receipt-qr img {
            width: 50px;
            height: 50px;
        }
        .item-qty {
            text-align: right;
            padding-right: 10px;
        }
        .item-price {
            text-align: right;
            padding-right: 10px;
        }
        .item-total {
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn-print" onclick="printReceipt()">
            <i class="fas fa-print"></i> Print Receipt
        </button>
        <button class="btn-thermal" onclick="printThermal()">
            <i class="fas fa-receipt"></i> Thermal Print
        </button>
        <button class="btn-back" onclick="window.location.href='<?php echo $type == 'cng' ? 'gas_sales.php' : 'pos.php'; ?>'">
            <i class="fas fa-arrow-left"></i> Back
        </button>
    </div>
    
    <div class="receipt" id="receiptArea">
        <!-- Header -->
        <div class="receipt-header">
            <div class="receipt-title"><?php echo strtoupper(htmlspecialchars($company_name)); ?></div>
            <div class="receipt-sub"><?php echo htmlspecialchars($company_address); ?></div>
            <div class="receipt-sub">Tel: <?php echo htmlspecialchars($company_phone); ?></div>
            <div class="receipt-divider"></div>
            <div class="receipt-row">
                <span><strong><?php echo $type == 'cng' ? 'CNG' : 'FUEL'; ?> RECEIPT</strong></span>
                <span>#<?php echo $invoice_no; ?></span>
            </div>
            <div class="receipt-row">
                <span>Date: <?php echo date('d/m/Y h:i A', strtotime($sale['sale_date'])); ?></span>
                <span>Shift: <?php echo $sale['shift_name'] ?? 'N/A'; ?></span>
            </div>
            <?php if($type == 'cng'): ?>
            <div class="receipt-row">
                <span colspan="2" class="text-center">
                    <span class="badge-cng">PIPELINE SUPPLY</span>
                </span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Customer Info -->
        <?php if(!empty($sale['customer_name'])): ?>
        <div class="receipt-divider"></div>
        <div class="receipt-row">
            <span><strong>Customer:</strong> <?php echo htmlspecialchars($sale['customer_name']); ?></span>
        </div>
        <?php if(!empty($sale['customer_phone'])): ?>
        <div class="receipt-row">
            <span><strong>Phone:</strong> <?php echo htmlspecialchars($sale['customer_phone']); ?></span>
        </div>
        <?php endif; ?>
        <div class="receipt-row">
            <span>
                <strong>Type:</strong> 
                <?php if($sale['sale_type'] == 'credit'): ?>
                    <span class="badge-credit">CREDIT</span>
                <?php else: ?>
                    <span class="badge-cash">CASH</span>
                <?php endif; ?>
            </span>
            <span><strong>Nozzle:</strong> <?php echo $sale['nozzle_name']; ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Meter Readings (for CNG) -->
        <?php if($type == 'cng'): ?>
        <div class="receipt-divider"></div>
        <div class="receipt-meters">
            <div class="receipt-row">
                <span>Opening Meter:</span>
                <span><?php echo number_format($sale['opening_meter'], 2); ?> m³</span>
            </div>
            <div class="receipt-row">
                <span>Closing Meter:</span>
                <span><?php echo number_format($sale['closing_meter'], 2); ?> m³</span>
            </div>
            <div class="receipt-row" style="font-weight:bold; border-top:1px dashed #ccc; padding-top:3px;">
                <span>Dispensed:</span>
                <span><?php echo number_format($sale['quantity_liters'], 2); ?> m³</span>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Items -->
        <div class="receipt-divider"></div>
        <div class="receipt-row" style="font-weight:bold; border-bottom:1px solid #000; padding-bottom:3px;">
            <span>Item</span>
            <span>Qty</span>
            <span>Price</span>
            <span>Total</span>
        </div>
        
        <?php if($type == 'cng'): ?>
        <div class="receipt-row">
            <span>CNG Gas</span>
            <span class="item-qty"><?php echo number_format($sale['quantity_liters'], 2); ?></span>
            <span class="item-price"><?php echo number_format($sale['unit_price'], 2); ?></span>
            <span class="item-total"><?php echo number_format($sale['total_amount'], 2); ?></span>
        </div>
        <?php else: ?>
        <div class="receipt-row">
            <span><?php echo $product_name; ?></span>
            <span class="item-qty"><?php echo number_format($sale['quantity_liters'], 2); ?></span>
            <span class="item-price"><?php echo number_format($sale['unit_price'], 2); ?></span>
            <span class="item-total"><?php echo number_format($sale['total_amount'], 2); ?></span>
        </div>
        <?php if(!empty($sale['tank_name'])): ?>
        <div class="receipt-row" style="font-size:10px; color:#666;">
            <span>Tank: <?php echo $sale['tank_name']; ?></span>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        
        <!-- Totals -->
        <div class="receipt-divider"></div>
        <div class="receipt-row-total">
            <span>TOTAL</span>
            <span><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></span>
        </div>
        
        <?php if($sale['received_amount'] > 0 && $sale['sale_type'] != 'credit'): ?>
        <div class="receipt-row">
            <span>Received</span>
            <span><?php echo $currency; ?> <?php echo number_format($sale['received_amount'], 2); ?></span>
        </div>
        <div class="receipt-row">
            <span>Change</span>
            <span><?php echo $currency; ?> <?php echo number_format($sale['change_amount'], 2); ?></span>
        </div>
        <?php endif; ?>
        
        <?php if($sale['sale_type'] == 'credit'): ?>
        <div class="receipt-row" style="font-weight:bold; color:#dc3545;">
            <span>DUE AMOUNT</span>
            <span><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Operator -->
        <div class="receipt-divider"></div>
        <div class="receipt-row" style="font-size:10px;">
            <span>Operator: <?php echo $sale['operator_name'] ?? 'N/A'; ?></span>
            <span><?php echo date('h:i A'); ?></span>
        </div>
        
        <!-- Footer -->
        <div class="receipt-footer">
            <div><?php echo $invoice_footer; ?></div>
            <div style="font-size:8px; margin-top:3px; color:#666;">
                <?php echo $type == 'cng' ? 'CNG Pipeline Supply' : 'Fuel Station'; ?> 
                | <?php echo date('d/m/Y H:i:s'); ?>
            </div>
            <div style="font-size:8px; color:#999; margin-top:2px;">
                * Thermal Receipt *
            </div>
        </div>
        
        <!-- Cut mark for thermal printer -->
        <div style="text-align: center; font-size: 8px; color: #ccc; margin-top: 5px; letter-spacing: 2px;">
            - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        </div>
    </div>
    
    <script>
        function printReceipt() {
            window.print();
        }
        
        function printThermal() {
            // Open print dialog with thermal settings
            var printWindow = window.open('', '_blank', 'width=400,height=600');
            printWindow.document.write('<html><head><title>Thermal Receipt</title>');
            printWindow.document.write('<style>');
            printWindow.document.write(`
                @page { size: 80mm auto; margin: 0; }
                body { margin: 0; padding: 8px; font-family: 'Courier New', monospace; font-size: 12px; }
                .receipt { width: 100%; }
                .receipt-header { text-align: center; border-bottom: 1px dashed #000; padding-bottom: 5px; margin-bottom: 5px; }
                .receipt-title { font-size: 16px; font-weight: bold; }
                .receipt-sub { font-size: 10px; margin: 0; }
                .receipt-divider { border-top: 1px dashed #000; margin: 5px 0; }
                .receipt-row { display: flex; justify-content: space-between; padding: 2px 0; }
                .receipt-row-total { display: flex; justify-content: space-between; padding: 4px 0; font-weight: bold; font-size: 14px; border-top: 1px solid #000; }
                .receipt-footer { text-align: center; margin-top: 8px; padding-top: 5px; border-top: 1px dashed #000; font-size: 10px; }
                .receipt-meters { background: #f5f5f5; padding: 4px 8px; margin: 4px 0; border-radius: 3px; font-size: 11px; }
                .text-center { text-align: center; }
                .text-right { text-align: right; }
                .fw-bold { font-weight: bold; }
                .item-qty { text-align: right; padding-right: 10px; }
                .item-price { text-align: right; padding-right: 10px; }
                .item-total { text-align: right; }
                .badge-cng { background: #0d6efd; color: white; padding: 1px 8px; border-radius: 10px; font-size: 9px; }
                .badge-credit { background: #ffc107; color: #000; padding: 1px 8px; border-radius: 10px; font-size: 9px; }
                .badge-cash { background: #28a745; color: white; padding: 1px 8px; border-radius: 10px; font-size: 9px; }
                @media print { .no-print { display: none; } }
            `);
            printWindow.document.write('</style></head><body>');
            printWindow.document.write(document.getElementById('receiptArea').innerHTML);
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            
            setTimeout(function() {
                printWindow.focus();
                printWindow.print();
                setTimeout(function() {
                    printWindow.close();
                }, 1000);
            }, 500);
        }
        
        // Auto-print on load (if thermal print requested)
        <?php if(isset($_GET['thermal']) && $_GET['thermal'] == '1'): ?>
        window.onload = function() {
            setTimeout(printThermal, 1000);
        };
        <?php endif; ?>
    </script>
</body>
</html>
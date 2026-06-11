<?php
session_start();
// Set a test invoice
$_SESSION['last_invoice'] = [
    'invoice_no' => 'TEST-001',
    'customer_name' => 'Test Customer',
    'date' => date('Y-m-d H:i:s'),
    'product_id' => 1,
    'product' => 'Diesel',
    'quantity' => 10,
    'unit_price' => 90,
    'subtotal' => 900,
    'vat' => 45,
    'tax' => 18,
    'total' => 963,
    'received' => 1000,
    'change' => 37
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Print</title>
    <style>
        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none; }
        }
        body {
            font-family: monospace;
            width: 80mm;
            margin: 0 auto;
            padding: 10px;
        }
        .invoice { border: 1px solid #000; padding: 10px; }
        .header { text-align: center; border-bottom: 1px dashed #000; }
        table { width: 100%; }
        .text-right { text-align: right; }
        .total { border-top: 1px dashed #000; font-weight: bold; }
        .footer { text-align: center; margin-top: 10px; }
        button { padding: 10px 20px; margin: 10px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: center;">
        <button onclick="window.print()">Print Invoice</button>
        <button onclick="window.close()">Close</button>
    </div>
    
    <div class="invoice">
        <div class="header">
            <h3>FF ENTERPRISE</h3>
            <p>Gas Station, Dhaka<br>Phone: 017xxxxxxxx</p>
            <h4>FUEL SALE INVOICE</h4>
        </div>
        
        <div style="margin: 10px 0;">
            <div>Invoice No: TEST-001</div>
            <div>Date: <?php echo date('d/m/Y H:i:s'); ?></div>
            <div>Customer: Test Customer</div>
        </div>
        
        <table border="0" cellpadding="5">
            <tr style="border-bottom: 1px dashed #000;">
                <th>Item</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Price</th>
                <th class="text-right">Amount</th>
            </tr>
            <tr>
                <td>Diesel</td>
                <td class="text-right">10.00 L</td>
                <td class="text-right">90.00</td>
                <td class="text-right">900.00</td>
            </tr>
        </table>
        
        <div style="margin-top: 10px;">
            <div>Subtotal: 900.00</div>
            <div>VAT (5%): 45.00</div>
            <div>Tax (2%): 18.00</div>
            <div class="total">TOTAL: 963.00</div>
            <div>Received: 1000.00</div>
            <div>Change: 37.00</div>
        </div>
        
        <div class="footer">
            <p>*** THANK YOU ***<br>Fuel delivered is not returnable</p>
        </div>
    </div>
    
    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>
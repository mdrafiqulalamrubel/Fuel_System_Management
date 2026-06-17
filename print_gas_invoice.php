<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

// Get invoice from session or URL
$invoice_no = isset($_GET['invoice']) ? $_GET['invoice'] : ($_SESSION['last_gas_invoice']['invoice_no'] ?? '');

if(empty($invoice_no)) {
    die('No invoice found to print');
}

// Get sale details
$stmt = $pdo->prepare("
    SELECT gs.*, 
           n.nozzle_name, 
           t.tank_name,
           sh.shift_name,
           u.full_name as operator_name
    FROM gas_sales gs
    JOIN nozzles n ON gs.nozzle_id = n.id
    JOIN tanks t ON n.tank_id = t.id
    LEFT JOIN shifts sh ON gs.shift_id = sh.id
    LEFT JOIN users u ON gs.operator_id = u.id
    WHERE gs.invoice_no = ?
");
$stmt->execute([$invoice_no]);
$sale = $stmt->fetch();

if(!$sale) {
    die('Invoice not found');
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
$company_name = $settings['company_name'] ?? 'Fuel Station Management';
$company_address = $settings['company_address'] ?? 'Dhaka, Bangladesh';
$company_phone = $settings['company_phone'] ?? '';
$vat_reg_no = $settings['vat_reg_no'] ?? '';
$invoice_footer = $settings['invoice_footer'] ?? '*** THANK YOU ***';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GAS Invoice - <?php echo $invoice_no; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white; padding: 0; margin: 0; }
            .invoice-box { margin: 0; padding: 20px; box-shadow: none; }
        }
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .invoice-box {
            max-width: 800px;
            margin: 30px auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
        }
        .invoice-header {
            border-bottom: 2px solid #17a2b8;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #17a2b8;
        }
        .invoice-title {
            font-size: 22px;
            font-weight: bold;
            color: #17a2b8;
            text-align: right;
        }
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
        }
        .invoice-table th {
            background: #17a2b8;
            color: white;
            padding: 10px;
            border: 1px solid #17a2b8;
        }
        .invoice-table td {
            padding: 10px;
            border: 1px solid #dee2e6;
        }
        .total-row {
            background: #e9ecef;
            font-weight: bold;
        }
        .gas-badge {
            background: #17a2b8;
            color: white;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 12px;
        }
        .meter-box {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            margin: 10px 0;
        }
        .meter-reading {
            font-family: 'Courier New', monospace;
            font-size: 18px;
            font-weight: bold;
        }
        .print-btn {
            margin-bottom: 20px;
        }
        .customer-info {
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="no-print text-center mb-3">
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print Invoice
                </button>
                <a href="gas_sales.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to GAS Sales
                </a>
                <a href="dashboard.php" class="btn btn-info">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </div>
            
            <div class="invoice-box" id="printArea">
                <!-- Header -->
                <div class="invoice-header">
                    <div class="row">
                        <div class="col-md-7">
                            <div class="company-name"><?php echo htmlspecialchars($company_name); ?></div>
                            <div><?php echo htmlspecialchars($company_address); ?></div>
                            <div>Phone: <?php echo htmlspecialchars($company_phone); ?></div>
                            <?php if($vat_reg_no): ?>
                                <div>VAT Reg No: <?php echo htmlspecialchars($vat_reg_no); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-5">
                            <div class="invoice-title">
                                <i class="fas fa-gas-pump"></i> GAS INVOICE
                            </div>
                            <div class="text-end">
                                <strong>Invoice No:</strong> <?php echo $invoice_no; ?><br>
                                <strong>Date:</strong> <?php echo date('d/m/Y h:i A', strtotime($sale['sale_date'])); ?><br>
                                <strong>Shift:</strong> <?php echo $sale['shift_name'] ?? 'N/A'; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Customer Info -->
                <?php if(!empty($sale['customer_name'])): ?>
                <div class="customer-info">
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Customer Name:</strong> <?php echo htmlspecialchars($sale['customer_name']); ?>
                        </div>
                        <div class="col-md-6">
                            <strong>Phone:</strong> <?php echo htmlspecialchars($sale['customer_phone']) ?: 'N/A'; ?>
                        </div>
                    </div>
                    <div class="mt-1">
                        <strong>Sale Type:</strong> 
                        <span class="badge <?php echo $sale['sale_type'] == 'credit' ? 'bg-warning' : 'bg-success'; ?>">
                            <?php echo ucfirst($sale['sale_type'] ?? 'Cash'); ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Meter Readings -->
                <div class="meter-box">
                    <div class="row text-center">
                        <div class="col-md-4">
                            <small class="text-muted">Nozzle</small><br>
                            <strong><?php echo $sale['nozzle_name']; ?></strong>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted">Tank</small><br>
                            <strong><?php echo $sale['tank_name']; ?></strong>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted">Operator</small><br>
                            <strong><?php echo $sale['operator_name']; ?></strong>
                        </div>
                    </div>
                </div>
                
                <!-- Meter Readings -->
                <div class="meter-box bg-info text-white">
                    <div class="row text-center">
                        <div class="col-md-4">
                            <small>Opening Meter</small><br>
                            <span class="meter-reading"><?php echo number_format($sale['opening_meter'], 2); ?></span>
                        </div>
                        <div class="col-md-4">
                            <small>Closing Meter</small><br>
                            <span class="meter-reading"><?php echo number_format($sale['closing_meter'], 2); ?></span>
                        </div>
                        <div class="col-md-4">
                            <small>Quantity Dispensed</small><br>
                            <span class="meter-reading" style="font-size: 24px;"><?php echo number_format($sale['quantity_liters'], 2); ?> L</span>
                        </div>
                    </div>
                </div>
                
                <!-- Invoice Items -->
                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Description</th>
                            <th class="text-end">Quantity (L)</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>1</td>
                            <td>
                                <strong>Natural Gas / CNG</strong>
                                <br><small>Meter: <?php echo number_format($sale['opening_meter'], 2); ?> → <?php echo number_format($sale['closing_meter'], 2); ?></small>
                            </td>
                            <td class="text-end"><?php echo number_format($sale['quantity_liters'], 2); ?></td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['unit_price'], 2); ?></td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td colspan="4" class="text-end">Total Amount:</td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></td>
                        </tr>
                        <?php if($sale['received_amount'] > 0): ?>
                        <tr>
                            <td colspan="4" class="text-end">Amount Received:</td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['received_amount'], 2); ?></td>
                        </tr>
                        <tr>
                            <td colspan="4" class="text-end">Change:</td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['change_amount'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if($sale['sale_type'] == 'credit'): ?>
                        <tr class="text-warning">
                            <td colspan="4" class="text-end"><strong>Due Amount:</strong></td>
                            <td class="text-end"><strong><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></strong></td>
                        </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
                
                <!-- Footer -->
                <div class="text-center mt-4">
                    <hr>
                    <p class="text-muted"><?php echo $invoice_footer; ?></p>
                    <p class="text-muted small">Printed on: <?php echo date('d/m/Y h:i:s A'); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</body>
</html>
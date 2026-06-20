<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

// Get invoice from URL or session
$invoice_no = isset($_GET['invoice']) ? $_GET['invoice'] : (isset($_SESSION['last_cng_invoice']['invoice_no']) ? $_SESSION['last_cng_invoice']['invoice_no'] : '');

if(empty($invoice_no)) {
    die('No invoice found to print');
}

// Get sale details - FIXED: Added vehicle_number and remarks to SELECT
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
$stmt->execute([$invoice_no]);
$sale = $stmt->fetch();

// If not found in database, try session
if(!$sale && isset($_SESSION['last_cng_invoice'])) {
    $sale = $_SESSION['last_cng_invoice'];
    $sale['nozzle_name'] = $sale['nozzle_name'] ?? 'CNG Nozzle';
    $sale['operator_name'] = $sale['operator_name'] ?? 'N/A';
    $sale['sale_date'] = $sale['date'] ?? date('Y-m-d H:i:s');
}

// If sale is from session but vehicle_number is missing, check if it's in the session directly
if($sale && empty($sale['vehicle_number']) && isset($_SESSION['last_cng_invoice']['vehicle_number'])) {
    $sale['vehicle_number'] = $_SESSION['last_cng_invoice']['vehicle_number'];
}
if($sale && empty($sale['remarks']) && isset($_SESSION['last_cng_invoice']['remarks'])) {
    $sale['remarks'] = $_SESSION['last_cng_invoice']['remarks'];
}

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

$vehicle_number = trim($sale['vehicle_number'] ?? '');
$remarks = trim($sale['remarks'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CNG Invoice - <?php echo $invoice_no; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================= */
        /* FIXED: Print styles with proper page break */
        /* ============================================= */
        @media print {
            .no-print { display: none !important; }
            body { 
                background: white; 
                padding: 0; 
                margin: 0; 
                font-size: 12px;
            }
            .invoice-box { 
                margin: 0; 
                padding: 15px 20px; 
                box-shadow: none;
                border-radius: 0;
                max-width: 100%;
            }
            .invoice-table td, .invoice-table th {
                padding: 6px 8px;
                font-size: 11px;
            }
            .meter-box {
                padding: 8px;
                margin: 8px 0;
            }
            .customer-info {
                padding: 8px 12px;
                margin-bottom: 10px;
            }
            .alert {
                padding: 5px 10px;
                margin: 5px 0;
            }
            .invoice-header {
                padding-bottom: 10px;
                margin-bottom: 12px;
            }
            .company-name {
                font-size: 20px;
            }
            .invoice-title {
                font-size: 18px;
            }
            .meter-reading {
                font-size: 16px;
            }
            .container-fluid {
                padding: 0 !important;
            }
            .main-content {
                margin: 0 !important;
                padding: 0 !important;
            }
            /* Fix for page break */
            .invoice-box {
                page-break-after: avoid;
            }
            table {
                page-break-inside: auto;
            }
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
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
        .pipeline-badge {
            background: #0d6efd;
            color: white;
            padding: 3px 12px;
            border-radius: 15px;
            font-size: 12px;
        }
        .btn-thermal {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            border: none;
        }
        .btn-thermal:hover {
            color: white;
            opacity: 0.9;
        }
        .vehicle-number {
            background: #17a2b8;
            color: white;
            padding: 2px 10px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 13px;
        }
        .remarks-text {
            color: #666;
            font-style: italic;
        }
        /* Fix for print button bar */
        .no-print {
            margin-bottom: 15px;
        }
        .no-print .btn {
            margin: 3px;
        }
        /* Fix for table responsive */
        .table-responsive {
            overflow-x: visible !important;
        }
        @media (max-width: 768px) {
            .invoice-box {
                padding: 15px;
                margin: 10px;
            }
            .invoice-table td, .invoice-table th {
                padding: 5px;
                font-size: 11px;
            }
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
                <a href="print_thermal_invoice.php?invoice=<?php echo $invoice_no; ?>&type=cng" class="btn btn-thermal" target="_blank">
                    <i class="fas fa-receipt"></i> Thermal Receipt
                </a>
                <a href="gas_sales.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to CNG Sales
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
                                <i class="fas fa-gas-pump"></i> CNG INVOICE
                            </div>
                            <div class="text-end">
                                <strong>Invoice No:</strong> <?php echo $invoice_no; ?><br>
                                <strong>Date:</strong> <?php echo date('d/m/Y h:i A', strtotime($sale['sale_date'])); ?><br>
                                <strong>Shift:</strong> <?php echo $sale['shift_name'] ?? 'N/A'; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Pipeline Info -->
                <div class="alert alert-info py-1">
                    <i class="fas fa-pipe"></i> 
                    <strong>Source:</strong> Government Pipeline (Titas Gas) 
                    <span class="badge pipeline-badge float-end">Pipeline Nozzle</span>
                </div>
                
                <!-- Customer Info - FIXED: Better layout for print -->
                <div class="customer-info">
                    <div class="row">
                        <div class="col-md-4">
                            <strong>Customer:</strong> 
                            <?php echo !empty($sale['customer_name']) ? htmlspecialchars($sale['customer_name']) : 'Walk-in Customer'; ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Phone:</strong> 
                            <?php echo !empty($sale['customer_phone']) ? htmlspecialchars($sale['customer_phone']) : 'N/A'; ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Type:</strong> 
                            <span class="badge <?php echo $sale['sale_type'] == 'credit' ? 'bg-warning' : 'bg-success'; ?>">
                                <?php echo ucfirst($sale['sale_type'] ?? 'Cash'); ?>
                            </span>
                        </div>
                        <div class="col-md-2">
                            <strong>Operator:</strong> 
                            <?php echo $sale['operator_name'] ?? 'N/A'; ?>
                        </div>
                    </div>
                    
                    <!-- ============================================= -->
                    <!-- VEHICLE NUMBER - FIXED -->
                    <!-- ============================================= -->
                    <?php if(!empty($vehicle_number)): ?>
                    <div class="row mt-2">
                        <div class="col-md-6">
                            <strong>Vehicle No:</strong> 
                            <span class="vehicle-number"><?php echo strtoupper(htmlspecialchars($vehicle_number)); ?></span>
                        </div>
                        <div class="col-md-6">
                            <strong>Nozzle:</strong> <?php echo $sale['nozzle_name']; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="row mt-1">
                        <div class="col-md-6">
                            <strong>Nozzle:</strong> <?php echo $sale['nozzle_name']; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- ============================================= -->
                    <!-- REMARKS - FIXED -->
                    <!-- ============================================= -->
                    <?php if(!empty($remarks)): ?>
                    <div class="row mt-1">
                        <div class="col-md-12">
                            <strong>Remarks:</strong> 
                            <span class="remarks-text"><?php echo htmlspecialchars($remarks); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Meter Readings - FIXED: Better layout -->
                <div class="meter-box bg-info text-white">
                    <div class="row text-center">
                        <div class="col-md-4">
                            <small>Opening Meter</small><br>
                            <span class="meter-reading"><?php echo number_format($sale['opening_meter'], 2); ?> m³</span>
                        </div>
                        <div class="col-md-4">
                            <small>Closing Meter</small><br>
                            <span class="meter-reading"><?php echo number_format($sale['closing_meter'], 2); ?> m³</span>
                        </div>
                        <div class="col-md-4">
                            <small>Dispensed</small><br>
                            <span class="meter-reading" style="font-size: 24px;"><?php echo number_format($sale['quantity_liters'], 2); ?> m³</span>
                        </div>
                    </div>
                </div>
                
                <!-- Invoice Items - FIXED: Better table -->
                <div class="table-responsive">
                    <table class="invoice-table">
                        <thead>
                            <tr>
                                <th style="width:5%;">#</th>
                                <th style="width:45%;">Description</th>
                                <th style="width:15%;" class="text-end">Qty (m³)</th>
                                <th style="width:15%;" class="text-end">Unit Price</th>
                                <th style="width:20%;" class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td>
                                    <strong>Compressed Natural Gas (CNG)</strong>
                                    <br><small>Meter: <?php echo number_format($sale['opening_meter'], 2); ?> → <?php echo number_format($sale['closing_meter'], 2); ?> m³</small>
                                    <br><small class="text-muted"><i class="fas fa-pipe"></i> Pipeline Supply</small>
                                    <?php if(!empty($vehicle_number)): ?>
                                        <br><small><i class="fas fa-car"></i> Vehicle: <?php echo strtoupper(htmlspecialchars($vehicle_number)); ?></small>
                                    <?php endif; ?>
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
                </div>
                
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
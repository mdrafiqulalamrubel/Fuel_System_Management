<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$invoice_no = isset($_GET['invoice']) ? $_GET['invoice'] : (isset($_SESSION['last_item_invoice']['invoice_no']) ? $_SESSION['last_item_invoice']['invoice_no'] : '');

if(empty($invoice_no)) {
    die('No invoice found to print');
}

// =============================================
// FIXED: Use a different alias instead of 'is'
// =============================================
$stmt = $pdo->prepare("
    SELECT 
        item_sales.*, 
        u.full_name as operator_name 
    FROM item_sales 
    LEFT JOIN users u ON item_sales.created_by = u.id 
    WHERE item_sales.invoice_no = ?
");
$stmt->execute([$invoice_no]);
$sale = $stmt->fetch();

if(!$sale) {
    die('Invoice not found');
}

// Get sale items
$stmt = $pdo->prepare("
    SELECT 
        item_sale_items.*, 
        items.item_name, 
        items.item_type, 
        items.unit
    FROM item_sale_items 
    JOIN items ON item_sale_items.item_id = items.id 
    WHERE item_sale_items.sale_id = ?
");
$stmt->execute([$sale['id']]);
$items = $stmt->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
$company_name = $settings['company_name'] ?? 'Fuel Station Management';
$company_address = $settings['company_address'] ?? 'Dhaka, Bangladesh';
$company_phone = $settings['company_phone'] ?? '';
$invoice_footer = $settings['invoice_footer'] ?? '*** THANK YOU ***';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Invoice - <?php echo $invoice_no; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white; padding: 0; margin: 0; }
            .invoice-box { margin: 0; padding: 20px; box-shadow: none; }
        }
        body { background: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .invoice-box {
            max-width: 800px;
            margin: 30px auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
        }
        .invoice-header {
            border-bottom: 2px solid #667eea;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .company-name { font-size: 24px; font-weight: bold; color: #667eea; }
        .invoice-title { font-size: 22px; font-weight: bold; color: #667eea; text-align: right; }
        .invoice-table { width: 100%; border-collapse: collapse; }
        .invoice-table th { background: #667eea; color: white; padding: 10px; border: 1px solid #667eea; }
        .invoice-table td { padding: 10px; border: 1px solid #dee2e6; }
        .total-row { background: #e9ecef; font-weight: bold; }
        .badge-product { background: #28a745; color: white; padding: 2px 8px; border-radius: 10px; font-size: 10px; }
        .badge-service { background: #17a2b8; color: white; padding: 2px 8px; border-radius: 10px; font-size: 10px; }
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
                <a href="item_pos.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to POS
                </a>
                <a href="print_item_thermal.php?invoice=<?php echo $invoice_no; ?>&type=item" class="btn btn-info" target="_blank">
                    <i class="fas fa-receipt"></i> Thermal Receipt
                </a>
            </div>
            
            <div class="invoice-box">
                <!-- Header -->
                <div class="invoice-header">
                    <div class="row">
                        <div class="col-md-7">
                            <div class="company-name"><?php echo htmlspecialchars($company_name); ?></div>
                            <div><?php echo htmlspecialchars($company_address); ?></div>
                            <div>Phone: <?php echo htmlspecialchars($company_phone); ?></div>
                        </div>
                        <div class="col-md-5">
                            <div class="invoice-title">
                                <i class="fas fa-box"></i> ITEM INVOICE
                            </div>
                            <div class="text-end">
                                <strong>Invoice No:</strong> <?php echo $invoice_no; ?><br>
                                <strong>Date:</strong> <?php echo date('d/m/Y h:i A', strtotime($sale['sale_date'])); ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Customer Info -->
                <?php if(!empty($sale['customer_name'])): ?>
                <div class="alert alert-info">
                    <strong>Customer:</strong> <?php echo htmlspecialchars($sale['customer_name']); ?>
                    <?php if(!empty($sale['customer_phone'])): ?>
                        | <strong>Phone:</strong> <?php echo htmlspecialchars($sale['customer_phone']); ?>
                    <?php endif; ?>
                    <br>
                    <strong>Sale Type:</strong> 
                    <span class="badge <?php echo $sale['sale_type'] == 'credit' ? 'bg-warning' : 'bg-success'; ?>">
                        <?php echo ucfirst($sale['sale_type']); ?>
                    </span>
                </div>
                <?php endif; ?>
                
                <!-- Items Table -->
                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Item</th>
                            <th>Type</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sl=1; foreach($items as $item): ?>
                        <tr>
                            <td><?php echo $sl++; ?></td>
                            <td>
                                <strong><?php echo $item['item_name']; ?></strong>
                                <br><small class="text-muted"><?php echo $item['unit']; ?></small>
                            </td>
                            <td>
                                <span class="badge <?php echo $item['item_type'] == 'product' ? 'badge-product' : 'badge-service'; ?>">
                                    <?php echo ucfirst($item['item_type']); ?>
                                </span>
                            </td>
                            <td class="text-end"><?php echo number_format($item['quantity'], 2); ?></td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($item['unit_price'], 2); ?></td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($item['total_amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" class="text-end">Subtotal:</td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['subtotal'], 2); ?></td>
                        </tr>
                        <?php if($sale['discount_amount'] > 0): ?>
                        <tr>
                            <td colspan="5" class="text-end">Discount:</td>
                            <td class="text-end text-danger">- <?php echo $currency; ?> <?php echo number_format($sale['discount_amount'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if($sale['tax_amount'] > 0): ?>
                        <tr>
                            <td colspan="5" class="text-end">Tax:</td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['tax_amount'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="total-row">
                            <td colspan="5" class="text-end">Total Amount:</td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></td>
                        </tr>
                        <?php if($sale['received_amount'] > 0 && $sale['sale_type'] == 'cash'): ?>
                        <tr>
                            <td colspan="5" class="text-end">Received:</td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['received_amount'], 2); ?></td>
                        </tr>
                        <tr>
                            <td colspan="5" class="text-end">Change:</td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['change_amount'], 2); ?></td>
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
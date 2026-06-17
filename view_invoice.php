<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

// Get invoice number from URL
$invoice_no = isset($_GET['invoice']) ? $_GET['invoice'] : '';

if(empty($invoice_no)) {
    header('Location: customer_ledger.php');
    exit();
}

// Get sale details
$stmt = $pdo->prepare("
    SELECT s.*, 
           p.product_name, 
           u.full_name as operator_name,
           sh.shift_name
    FROM sales s
    JOIN fuel_products p ON s.product_id = p.id
    JOIN users u ON s.operator_id = u.id
    JOIN shifts sh ON s.shift_id = sh.id
    WHERE s.invoice_no = ?
");
$stmt->execute([$invoice_no]);
$sale = $stmt->fetch();

if(!$sale) {
    // Try to find in credit_sales table if not in sales
    $stmt = $pdo->prepare("
        SELECT cs.*, c.customer_name, c.customer_code, c.phone, c.address
        FROM credit_sales cs
        JOIN customers c ON cs.customer_id = c.id
        WHERE cs.invoice_no = ?
    ");
    $stmt->execute([$invoice_no]);
    $credit_sale = $stmt->fetch();
    
    if($credit_sale) {
        // Get the original sale details
        $stmt = $pdo->prepare("
            SELECT s.*, p.product_name, u.full_name as operator_name, sh.shift_name
            FROM sales s
            JOIN fuel_products p ON s.product_id = p.id
            JOIN users u ON s.operator_id = u.id
            JOIN shifts sh ON s.shift_id = sh.id
            WHERE s.id = ?
        ");
        $stmt->execute([$credit_sale['sale_id']]);
        $sale = $stmt->fetch();
        
        if($sale) {
            $sale['customer_name'] = $credit_sale['customer_name'];
            $sale['customer_code'] = $credit_sale['customer_code'];
            $sale['customer_phone'] = $credit_sale['customer_phone'] ?? $credit_sale['phone'] ?? '';
            $sale['customer_address'] = $credit_sale['address'] ?? '';
            $sale['sale_type'] = 'credit';
            $sale['due_date'] = $credit_sale['due_date'];
            $sale['paid_amount'] = $credit_sale['paid_amount'];
            $sale['balance_due'] = $credit_sale['balance_due'];
        }
    }
}

if(!$sale) {
    header('Location: customer_ledger.php');
    exit();
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
$company_name = $settings['company_name'] ?? 'Fuel Station Management';
$company_address = $settings['company_address'] ?? 'Dhaka, Bangladesh';
$company_phone = $settings['company_phone'] ?? '';
$vat_reg_no = $settings['vat_reg_no'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?php echo $invoice_no; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .invoice-box {
            max-width: 900px;
            margin: 30px auto;
            padding: 30px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
        }
        .invoice-header {
            border-bottom: 2px solid #28a745;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        .company-name {
            font-size: 28px;
            font-weight: bold;
            color: #28a745;
        }
        .invoice-title {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            text-align: right;
        }
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
        }
        .invoice-table th {
            background: #f8f9fa;
            padding: 10px;
            border: 1px solid #dee2e6;
        }
        .invoice-table td {
            padding: 10px;
            border: 1px solid #dee2e6;
        }
        .total-row {
            background: #e9ecef;
            font-weight: bold;
        }
        .print-btn {
            margin-bottom: 20px;
        }
        @media print {
            .no-print { display: none; }
            .invoice-box {
                box-shadow: none;
                margin: 0;
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="no-print">
                <a href="customer_ledger.php" class="btn btn-secondary mb-3">
                    <i class="fas fa-arrow-left"></i> Back to Ledger
                </a>
                <button onclick="window.print()" class="btn btn-primary mb-3">
                    <i class="fas fa-print"></i> Print Invoice
                </button>
            </div>
            
            <div class="invoice-box">
                <!-- Invoice Header -->
                <div class="invoice-header">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="company-name"><?php echo htmlspecialchars($company_name); ?></div>
                            <div><?php echo htmlspecialchars($company_address); ?></div>
                            <div>Phone: <?php echo htmlspecialchars($company_phone); ?></div>
                            <?php if($vat_reg_no): ?>
                                <div>VAT Reg No: <?php echo htmlspecialchars($vat_reg_no); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <div class="invoice-title">TAX INVOICE</div>
                            <div class="text-end">
                                <strong>Invoice No:</strong> <?php echo $invoice_no; ?><br>
                                <strong>Date:</strong> <?php echo date('d/m/Y h:i A', strtotime($sale['sale_date'])); ?><br>
                                <strong>Shift:</strong> <?php echo $sale['shift_name'] ?? 'N/A'; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Customer Info -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Customer:</strong>
                        <?php if($sale['sale_type'] == 'credit' || !empty($sale['customer_name'])): ?>
                            <?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in Customer'); ?>
                            <?php if(!empty($sale['customer_code'])): ?>
                                (<?php echo htmlspecialchars($sale['customer_code']); ?>)
                            <?php endif; ?>
                            <?php if(!empty($sale['customer_phone'])): ?>
                                <br><strong>Phone:</strong> <?php echo htmlspecialchars($sale['customer_phone']); ?>
                            <?php endif; ?>
                            <?php if(!empty($sale['customer_address'])): ?>
                                <br><strong>Address:</strong> <?php echo htmlspecialchars($sale['customer_address']); ?>
                            <?php endif; ?>
                        <?php else: ?>
                            Walk-in Customer
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 text-end">
                        <strong>Sale Type:</strong> 
                        <span class="badge <?php echo $sale['sale_type'] == 'credit' ? 'bg-warning' : 'bg-success'; ?>">
                            <?php echo ucfirst($sale['sale_type'] ?? 'Cash'); ?>
                        </span>
                        <?php if($sale['sale_type'] == 'credit'): ?>
                            <br><strong>Due Date:</strong> <?php echo date('d/m/Y', strtotime($sale['due_date'])); ?>
                            <br><strong>Balance Due:</strong> <?php echo $currency; ?> <?php echo number_format($sale['balance_due'] ?? 0, 2); ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Invoice Items -->
                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th>Quantity (L)</th>
                            <th>Unit Price</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>1</td>
                            <td><?php echo htmlspecialchars($sale['product_name']); ?></td>
                            <td><?php echo number_format($sale['quantity_liters'], 2); ?></td>
                            <td><?php echo $currency; ?> <?php echo number_format($sale['unit_price'], 2); ?></td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td colspan="4" class="text-end">Subtotal:</td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['subtotal'], 2); ?></td>
                        </tr>
                        <?php if($sale['vat_amount'] > 0): ?>
                        <tr>
                            <td colspan="4" class="text-end">VAT (<?php echo number_format(($sale['vat_amount'] / $sale['subtotal']) * 100, 2); ?>%):</td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['vat_amount'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if($sale['tax_amount'] > 0): ?>
                        <tr>
                            <td colspan="4" class="text-end">Tax:</td>
                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['tax_amount'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
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
                            <td class="text-end"><strong><?php echo $currency; ?> <?php echo number_format($sale['balance_due'] ?? $sale['total_amount'], 2); ?></strong></td>
                        </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
                
                <!-- Footer -->
                <div class="text-center mt-4">
                    <hr>
                    <p class="text-muted"><?php echo $settings['invoice_footer'] ?? '*** THANK YOU ***'; ?></p>
                    <p class="text-muted small">Generated on: <?php echo date('d/m/Y h:i:s A'); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
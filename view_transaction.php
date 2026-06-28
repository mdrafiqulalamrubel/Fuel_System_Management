<?php
// view_transaction.php - Fixed version
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$ref_no = isset($_GET['ref_no']) ? $_GET['ref_no'] : '';
$type = isset($_GET['type']) ? $_GET['type'] : '';
$source_table = isset($_GET['source_table']) ? $_GET['source_table'] : '';
$source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;

if(!$ref_no && !$source_id) {
    die('Transaction reference not provided');
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';

$transaction = null;
$items = [];
$customer = null;

// Function to safely get transaction based on source table and ID
if($source_table && $source_id > 0) {
    switch($source_table) {
        case 'sales':
            $stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
            $stmt->execute([$source_id]);
            $transaction = $stmt->fetch();
            if($transaction) {
                $stmt = $pdo->prepare("SELECT si.*, p.name as product_name, p.unit_type 
                                       FROM sale_items si 
                                       LEFT JOIN fuel_products p ON si.product_id = p.id 
                                       WHERE si.sale_id = ?");
                $stmt->execute([$source_id]);
                $items = $stmt->fetchAll();
            }
            break;
            
        case 'credit_sales':
            $stmt = $pdo->prepare("SELECT * FROM credit_sales WHERE id = ?");
            $stmt->execute([$source_id]);
            $transaction = $stmt->fetch();
            if($transaction) {
                // Try credit_sale_items
                $stmt = $pdo->prepare("SELECT csi.*, p.name as product_name, p.unit_type 
                                       FROM credit_sale_items csi 
                                       LEFT JOIN fuel_products p ON csi.product_id = p.id 
                                       WHERE csi.credit_sale_id = ?");
                $stmt->execute([$source_id]);
                $items = $stmt->fetchAll();
                // If no items, check for gas_sale_items
                if(empty($items)) {
                    $stmt = $pdo->prepare("SELECT gsi.*, p.name as product_name, p.unit_type 
                                           FROM gas_sale_items gsi 
                                           LEFT JOIN fuel_products p ON gsi.product_id = p.id 
                                           WHERE gsi.gas_sale_id = ?");
                    $stmt->execute([$source_id]);
                    $items = $stmt->fetchAll();
                }
            }
            break;
            
        case 'credit_payments':
            $stmt = $pdo->prepare("SELECT cp.*, cs.invoice_no as sale_invoice, c.customer_name 
                                   FROM credit_payments cp 
                                   JOIN credit_sales cs ON cp.credit_sale_id = cs.id 
                                   JOIN customers c ON cs.customer_id = c.id 
                                   WHERE cp.id = ?");
            $stmt->execute([$source_id]);
            $transaction = $stmt->fetch();
            break;
            
        case 'vouchers':
            $stmt = $pdo->prepare("SELECT v.*, c.customer_name 
                                   FROM vouchers v 
                                   LEFT JOIN customers c ON v.customer_id = c.id 
                                   WHERE v.id = ?");
            $stmt->execute([$source_id]);
            $transaction = $stmt->fetch();
            break;
            
        case 'advance_payments_customer':
            $stmt = $pdo->prepare("SELECT ap.*, c.customer_name 
                                   FROM advance_payments_customer ap 
                                   JOIN customers c ON ap.customer_id = c.id 
                                   WHERE ap.id = ?");
            $stmt->execute([$source_id]);
            $transaction = $stmt->fetch();
            break;
            
        case 'gas_sales':
            $stmt = $pdo->prepare("SELECT * FROM gas_sales WHERE id = ?");
            $stmt->execute([$source_id]);
            $transaction = $stmt->fetch();
            if($transaction) {
                $stmt = $pdo->prepare("SELECT gsi.*, p.name as product_name, p.unit_type 
                                       FROM gas_sale_items gsi 
                                       LEFT JOIN fuel_products p ON gsi.product_id = p.id 
                                       WHERE gsi.gas_sale_id = ?");
                $stmt->execute([$source_id]);
                $items = $stmt->fetchAll();
            }
            break;
            
        default:
            // Try generic lookup by ref_no
            $transaction = findTransactionByRefNo($pdo, $ref_no, $type);
    }
} else {
    // Fallback: try to find by reference number
    $transaction = findTransactionByRefNo($pdo, $ref_no, $type);
}

// Helper function to find transaction by reference number
function findTransactionByRefNo($pdo, $ref_no, $type) {
    $transaction = null;
    
    if($type == 'credit_sale' || $type == 'advance_used') {
        // Check credit_sales table
        $stmt = $pdo->prepare("SELECT * FROM credit_sales WHERE invoice_no = ?");
        $stmt->execute([$ref_no]);
        $transaction = $stmt->fetch();
        if(!$transaction) {
            // Check gas_sales table
            $stmt = $pdo->prepare("SELECT * FROM gas_sales WHERE invoice_no = ?");
            $stmt->execute([$ref_no]);
            $transaction = $stmt->fetch();
        }
    } elseif($type == 'payment') {
        $stmt = $pdo->prepare("SELECT * FROM credit_payments WHERE receipt_no = ?");
        $stmt->execute([$ref_no]);
        $transaction = $stmt->fetch();
    } elseif($type == 'voucher_payment') {
        $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE voucher_no = ?");
        $stmt->execute([$ref_no]);
        $transaction = $stmt->fetch();
    } elseif($type == 'advance_received') {
        $stmt = $pdo->prepare("SELECT * FROM advance_payments_customer WHERE reference_no = ? OR CONCAT('ADV-', id) = ?");
        $stmt->execute([$ref_no, $ref_no]);
        $transaction = $stmt->fetch();
    } elseif($type == 'cash_sale') {
        $stmt = $pdo->prepare("SELECT * FROM sales WHERE invoice_no = ?");
        $stmt->execute([$ref_no]);
        $transaction = $stmt->fetch();
    }
    
    return $transaction;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Transaction Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f8f9fa; }
        .detail-row { padding: 8px 0; border-bottom: 1px solid #eee; }
        .label { font-weight: bold; color: #555; min-width: 140px; display: inline-block; }
        .transaction-card { 
            max-width: 900px; 
            margin: 20px auto; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .status-badge { padding: 5px 12px; border-radius: 20px; }
        .amount-large { font-size: 24px; font-weight: bold; }
        @media print {
            .no-print { display: none !important; }
            .transaction-card { box-shadow: none !important; margin: 0 !important; max-width: 100% !important; }
            body { background: #fff !important; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card transaction-card">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-file-invoice"></i> Transaction Details</h5>
                <div class="no-print">
                    <button onclick="window.print()" class="btn btn-sm btn-light">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <button onclick="window.close()" class="btn btn-sm btn-light">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if($transaction): ?>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="detail-row">
                                <span class="label"><i class="fas fa-hashtag"></i> Reference:</span> 
                                <strong><?php echo htmlspecialchars($ref_no ?: 'N/A'); ?></strong>
                            </div>
                            <div class="detail-row">
                                <span class="label"><i class="fas fa-tag"></i> Type:</span> 
                                <span class="badge bg-info"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $type))); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="label"><i class="fas fa-calendar"></i> Date:</span> 
                                <?php 
                                    $date = isset($transaction['sale_date']) ? $transaction['sale_date'] : 
                                           (isset($transaction['payment_date']) ? $transaction['payment_date'] : 
                                           (isset($transaction['date']) ? $transaction['date'] : 
                                           (isset($transaction['advance_date']) ? $transaction['advance_date'] : '')));
                                    echo $date ? date('d-m-Y h:i A', strtotime($date)) : 'N/A';
                                ?>
                            </div>
                            <?php if(isset($transaction['customer_name'])): ?>
                            <div class="detail-row">
                                <span class="label"><i class="fas fa-user"></i> Customer:</span> 
                                <?php echo htmlspecialchars($transaction['customer_name']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <?php if(isset($transaction['total_amount']) || isset($transaction['amount'])): 
                                $amount = $transaction['total_amount'] ?? $transaction['amount'] ?? 0;
                            ?>
                            <div class="detail-row">
                                <span class="label"><i class="fas fa-money-bill-wave"></i> Amount:</span> 
                                <span class="amount-large <?php echo $amount < 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo $currency; ?> <?php echo number_format($amount, 2); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <?php if(isset($transaction['status'])): ?>
                            <div class="detail-row">
                                <span class="label"><i class="fas fa-info-circle"></i> Status:</span> 
                                <span class="status-badge bg-<?php echo $transaction['status'] == 'completed' ? 'success' : ($transaction['status'] == 'cancelled' ? 'danger' : 'warning'); ?> text-white">
                                    <?php echo htmlspecialchars(ucfirst($transaction['status'])); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <?php if(isset($transaction['payment_method'])): ?>
                            <div class="detail-row">
                                <span class="label"><i class="fas fa-credit-card"></i> Payment Method:</span> 
                                <?php echo htmlspecialchars(ucfirst($transaction['payment_method'])); ?>
                            </div>
                            <?php endif; ?>
                            <?php if(isset($transaction['due_date']) && $transaction['due_date']): ?>
                            <div class="detail-row">
                                <span class="label"><i class="fas fa-clock"></i> Due Date:</span> 
                                <?php echo date('d-m-Y', strtotime($transaction['due_date'])); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if(isset($transaction['advance_adjusted']) && $transaction['advance_adjusted'] > 0): ?>
                    <div class="alert alert-warning mt-2">
                        <i class="fas fa-hand-holding-usd"></i> 
                        <strong>Advance Used:</strong> <?php echo $currency; ?> <?php echo number_format($transaction['advance_adjusted'], 2); ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if(!empty($items)): ?>
                    <hr>
                    <h6><i class="fas fa-list"></i> Items</h6>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm table-bordered table-striped">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>Product</th>
                                    <th class="text-end">Quantity</th>
                                    <th class="text-end">Unit</th>
                                    <th class="text-end">Price</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($items as $index => $item): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo isset($item['product_name']) ? htmlspecialchars($item['product_name']) : 'Product #' . ($item['product_id'] ?? 'N/A'); ?></td>
                                    <td class="text-end"><?php echo isset($item['quantity']) ? number_format($item['quantity'], 2) : '0.00'; ?></td>
                                    <td class="text-end"><?php echo isset($item['unit_type']) ? htmlspecialchars($item['unit_type']) : (isset($item['unit']) ? htmlspecialchars($item['unit']) : '-'); ?></td>
                                    <td class="text-end"><?php echo isset($item['price']) ? number_format($item['price'], 2) : (isset($item['unit_price']) ? number_format($item['unit_price'], 2) : '0.00'); ?></td>
                                    <td class="text-end"><strong><?php 
                                        $total = isset($item['total']) ? $item['total'] : 
                                                (isset($item['quantity']) && isset($item['price']) ? $item['quantity'] * $item['price'] : 
                                                (isset($item['quantity']) && isset($item['unit_price']) ? $item['quantity'] * $item['unit_price'] : 0));
                                        echo number_format($total, 2); 
                                    ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-secondary fw-bold">
                                <tr>
                                    <td colspan="5" class="text-end">Total:</td>
                                    <td class="text-end">
                                        <?php 
                                            $grand_total = array_sum(array_map(function($item) {
                                                return isset($item['total']) ? $item['total'] : 
                                                       (isset($item['quantity']) && isset($item['price']) ? $item['quantity'] * $item['price'] : 
                                                       (isset($item['quantity']) && isset($item['unit_price']) ? $item['quantity'] * $item['unit_price'] : 0));
                                            }, $items));
                                            echo number_format($grand_total, 2);
                                        ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <?php if(isset($transaction['notes']) && !empty($transaction['notes'])): ?>
                    <div class="mt-3 p-2 bg-light rounded">
                        <strong><i class="fas fa-sticky-note"></i> Notes:</strong>
                        <?php echo htmlspecialchars($transaction['notes']); ?>
                    </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="alert alert-warning text-center py-4">
                        <i class="fas fa-exclamation-triangle fa-2x d-block mb-2"></i>
                        <h5>Transaction Not Found</h5>
                        <p class="mb-0">Reference: <?php echo htmlspecialchars($ref_no); ?><br>
                        Type: <?php echo htmlspecialchars($type); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer text-muted text-center no-print">
                <small><i class="fas fa-info-circle"></i> Generated on <?php echo date('d-m-Y h:i:s A'); ?></small>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
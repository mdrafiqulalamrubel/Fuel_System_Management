<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$customer_id = isset($_GET['customer_id']) ? $_GET['customer_id'] : '';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-t');

// Get all active customers
$customers = $pdo->query("SELECT * FROM customers WHERE is_active = 1 ORDER BY customer_name")->fetchAll();

$customer = null;
$transactions = [];
$opening_balance = 0;

if($customer_id) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    
    if($customer) {
        // =============================================
        // Calculate OPENING BALANCE (before from_date)
        // =============================================
        
        // 1. Get all credit sales before from_date
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as total_sales 
            FROM credit_sales 
            WHERE customer_id = ? AND sale_date < ?
        ");
        $stmt->execute([$customer_id, $from_date]);
        $sales_before = $stmt->fetch()['total_sales'];
        
        // 2. Get all cash sales before from_date
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as total_cash_sales 
            FROM sales 
            WHERE customer_id = ? AND sale_date < ? AND sale_type = 'cash'
        ");
        $stmt->execute([$customer_id, $from_date]);
        $cash_sales_before = $stmt->fetch()['total_cash_sales'];
        
        // 3. Get all payments received before from_date
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(cp.amount), 0) as total_payments
            FROM credit_payments cp
            JOIN credit_sales cs ON cp.credit_sale_id = cs.id
            WHERE cs.customer_id = ? AND cp.payment_date < ?
        ");
        $stmt->execute([$customer_id, $from_date]);
        $payments_before = $stmt->fetch()['total_payments'];
        
        // 4. Get all advance received before from_date
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_advance
            FROM advance_payments_customer 
            WHERE customer_id = ? AND advance_date < ?
            AND status = 'active'
        ");
        $stmt->execute([$customer_id, $from_date]);
        $advance_received_before = $stmt->fetch()['total_advance'];
        
        // 5. Get all advance used before from_date
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(advance_adjusted), 0) as total_advance_used
            FROM credit_sales 
            WHERE customer_id = ? AND sale_date < ?
            AND advance_adjusted > 0
        ");
        $stmt->execute([$customer_id, $from_date]);
        $advance_used_before = $stmt->fetch()['total_advance_used'];
        
        // Opening Balance = Sales - Payments - Advance Used + Advance Received
        $opening_balance = ($sales_before + $cash_sales_before) - $payments_before - $advance_used_before + $advance_received_before;
        
        // =============================================
        // Get all transactions for the period
        // =============================================
        
        // 1. CASH SALES (Credit - reduces balance)
        $stmt = $pdo->prepare("
            SELECT 
                sale_date as trans_date,
                invoice_no as ref_no,
                total_amount as amount,
                NULL as due_date,
                'cash_sale' as trans_type,
                payment_method,
                NULL as receipt_no,
                0 as debit,
                total_amount as credit,
                id as sale_id,
                CONCAT('Cash Sale - ', invoice_no) as description
            FROM sales 
            WHERE customer_id = ? AND sale_date BETWEEN ? AND ?
            AND sale_type = 'cash'
            ORDER BY sale_date ASC
        ");
        $stmt->execute([$customer_id, $from_date, $to_date]);
        $cash_sales = $stmt->fetchAll();
        
        // 2. CREDIT SALES (Debit)
        $stmt = $pdo->prepare("
            SELECT 
                sale_date as trans_date,
                invoice_no as ref_no,
                total_amount as amount,
                due_date,
                'sale' as trans_type,
                NULL as payment_method,
                NULL as receipt_no,
                total_amount as debit,
                0 as credit,
                advance_adjusted,
                id as sale_id,
                CONCAT('Credit Sale - ', invoice_no) as description
            FROM credit_sales 
            WHERE customer_id = ? AND sale_date BETWEEN ? AND ?
            ORDER BY sale_date ASC
        ");
        $stmt->execute([$customer_id, $from_date, $to_date]);
        $credit_sales = $stmt->fetchAll();
        
        // 3. PAYMENTS RECEIVED (Credit)
        $stmt = $pdo->prepare("
            SELECT 
                cp.payment_date as trans_date,
                cp.receipt_no as ref_no,
                cp.amount as amount,
                NULL as due_date,
                'payment' as trans_type,
                cp.payment_method,
                cp.receipt_no,
                0 as debit,
                cp.amount as credit,
                cp.id as payment_id,
                CONCAT('Payment Received - ', cp.receipt_no) as description
            FROM credit_payments cp
            JOIN credit_sales cs ON cp.credit_sale_id = cs.id
            WHERE cs.customer_id = ? AND cp.payment_date BETWEEN ? AND ?
            ORDER BY cp.payment_date ASC
        ");
        $stmt->execute([$customer_id, $from_date, $to_date]);
        $payments = $stmt->fetchAll();
        
        // 4. ADVANCE RECEIVED (Credit)
        $stmt = $pdo->prepare("
            SELECT 
                advance_date as trans_date,
                CONCAT('ADV-', id) as ref_no,
                amount as amount,
                NULL as due_date,
                'advance_received' as trans_type,
                payment_method,
                reference_no as receipt_no,
                0 as debit,
                amount as credit,
                id as advance_id,
                CONCAT('Advance Received - ', reference_no) as description
            FROM advance_payments_customer 
            WHERE customer_id = ? AND advance_date BETWEEN ? AND ?
            AND status = 'active'
            ORDER BY advance_date ASC
        ");
        $stmt->execute([$customer_id, $from_date, $to_date]);
        $advance_received = $stmt->fetchAll();
        
        // 5. ADVANCE USED (Debit - reduces advance balance)
        $stmt = $pdo->prepare("
            SELECT 
                sale_date as trans_date,
                invoice_no as ref_no,
                advance_adjusted as amount,
                NULL as due_date,
                'advance_used' as trans_type,
                NULL as payment_method,
                NULL as receipt_no,
                advance_adjusted as debit,
                0 as credit,
                id as sale_id,
                CONCAT('Advance Used - ', invoice_no) as description
            FROM credit_sales 
            WHERE customer_id = ? AND advance_adjusted > 0
            AND sale_date BETWEEN ? AND ?
            ORDER BY sale_date ASC
        ");
        $stmt->execute([$customer_id, $from_date, $to_date]);
        $advance_used = $stmt->fetchAll();
        
        // =============================================
        // FIX: Initialize all variables as empty arrays
        // =============================================
        if(!isset($cash_sales) || $cash_sales === null) $cash_sales = [];
        if(!isset($credit_sales) || $credit_sales === null) $credit_sales = [];
        if(!isset($payments) || $payments === null) $payments = [];
        if(!isset($advance_received) || $advance_received === null) $advance_received = [];
        if(!isset($advance_used) || $advance_used === null) $advance_used = [];
        
        // Merge all transactions
        $transactions = array_merge($cash_sales, $credit_sales, $payments, $advance_received, $advance_used);
        
        // Sort by date
        usort($transactions, function($a, $b) {
            return strtotime($a['trans_date']) - strtotime($b['trans_date']);
        });
        
        // =============================================
        // Calculate running balance
        // =============================================
        $running_balance = $opening_balance;
        $total_debit = 0;
        $total_credit = 0;
        
        foreach($transactions as &$t) {
            $running_balance += ($t['debit'] ?? 0) - ($t['credit'] ?? 0);
            $t['running_balance'] = $running_balance;
            $total_debit += ($t['debit'] ?? 0);
            $total_credit += ($t['credit'] ?? 0);
        }
        unset($t);
        
        // Get updated customer balance
        $stmt = $pdo->prepare("SELECT current_balance, advance_balance FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer_data = $stmt->fetch();
        $customer['current_balance'] = $customer_data['current_balance'] ?? 0;
        $customer['advance_balance'] = $customer_data['advance_balance'] ?? 0;
    }
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Ledger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .clickable-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .clickable-row:hover {
            background-color: #e8f0fe !important;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s;
        }
        .stats-card:hover { transform: translateY(-5px); }
        .stats-card i { font-size: 40px; opacity: 0.5; float: right; }
        
        .badge-cash-sale { background: #28a745; color: white; }
        .badge-credit-sale { background: #dc3545; color: white; }
        .badge-payment { background: #17a2b8; color: white; }
        .badge-advance-received { background: #6f42c1; color: white; }
        .badge-advance-used { background: #ffc107; color: #856404; }
        
        .net-balance-card {
            border-left: 5px solid;
            border-radius: 10px;
        }
        .net-balance-card.due { border-left-color: #dc3545; }
        .net-balance-card.advance { border-left-color: #ffc107; }
        .net-balance-card.zero { border-left-color: #28a745; }
        
        @media print {
            .sidebar, .no-print, .btn { display: none !important; }
            .main-content { margin: 0 !important; padding: 10px !important; }
            .print-header { display: block !important; text-align: center; margin-bottom: 20px; }
        }
        .print-header { display: none; }
        
        .transaction-details {
            font-size: 11px;
            color: #666;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="print-header">
                <h2><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h2>
                <h4>Customer Ledger</h4>
                <p>Period: <?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-users"></i> Customer Ledger</h2>
                <div class="no-print">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">Back</a>
                </div>
            </div>
            
            <!-- Customer Selection -->
            <div class="card mb-4 no-print">
                <div class="card-header bg-primary text-white">
                    <h5>Select Customer</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row">
                        <div class="col-md-5">
                            <select name="customer_id" class="form-control" required>
                                <option value="">-- Select Customer --</option>
                                <?php foreach($customers as $c): 
                                    $net = $c['current_balance'] - $c['advance_balance'];
                                    $status = $net > 0 ? 'Due' : ($net < 0 ? 'Advance' : 'Settled');
                                    $status_color = $net > 0 ? 'text-danger' : ($net < 0 ? 'text-warning' : 'text-success');
                                ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $customer_id == $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo $c['customer_name']; ?> (<?php echo $c['customer_code']; ?>) - 
                                        <span class="<?php echo $status_color; ?>">
                                            <?php echo $status; ?>: <?php echo $currency; ?> <?php echo number_format(abs($net), 2); ?>
                                        </span>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>">
                        </div>
                        <div class="col-md-2">
                            <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">View Ledger</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php if($customer): 
                $net_balance = $customer['current_balance'] - $customer['advance_balance'];
                $is_due = $net_balance > 0;
                $is_advance = $net_balance < 0;
                $is_zero = $net_balance == 0;
                $display_balance = abs($net_balance);
                $balance_label = $is_due ? 'Due' : ($is_advance ? 'Advance' : 'Settled');
                $balance_class = $is_due ? 'danger' : ($is_advance ? 'warning' : 'success');
            ?>
                <!-- Customer Info Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-white bg-info">
                            <div class="card-body">
                                <h6>Customer Name</h6>
                                <h4><?php echo htmlspecialchars($customer['customer_name']); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-secondary">
                            <div class="card-body">
                                <h6>Customer Code</h6>
                                <h4><?php echo $customer['customer_code']; ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-primary">
                            <div class="card-body">
                                <h6>Credit Limit</h6>
                                <h4><?php echo $currency; ?> <?php echo number_format($customer['credit_limit'], 2); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-<?php echo $balance_class; ?>">
                            <div class="card-body">
                                <h6>Net Balance</h6>
                                <h4>
                                    <?php if($is_zero): ?>
                                        <?php echo $currency; ?> 0.00 (Settled)
                                    <?php else: ?>
                                        <?php echo $currency; ?> <?php echo number_format($display_balance, 2); ?> 
                                        (<?php echo $balance_label; ?>)
                                    <?php endif; ?>
                                </h4>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Contact Info -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <strong>Phone:</strong> <?php echo $customer['phone'] ?: 'N/A'; ?><br>
                                <strong>Email:</strong> <?php echo $customer['email'] ?: 'N/A'; ?><br>
                                <strong>Address:</strong> <?php echo $customer['address'] ?: 'N/A'; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <strong>Opening Balance (as on <?php echo date('d M Y', strtotime($from_date)); ?>):</strong><br>
                                <span class="h5 <?php echo $opening_balance > 0 ? 'text-danger' : ($opening_balance < 0 ? 'text-warning' : 'text-success'); ?>">
                                    <?php echo $currency; ?> <?php echo number_format(abs($opening_balance), 2); ?> 
                                    <?php echo $opening_balance > 0 ? '(Due)' : ($opening_balance < 0 ? '(Advance)' : ''); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Ledger Table -->
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5>Ledger Statement (<?php echo date('d M Y', strtotime($from_date)); ?> to <?php echo date('d M Y', strtotime($to_date)); ?>)</h5>
                        <small class="d-block text-light">Click on any transaction to view details</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="ledgerTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Date</th>
                                        <th>Reference</th>
                                        <th>Particulars</th>
                                        <th class="text-end">Debit (<?php echo $currency; ?>)</th>
                                        <th class="text-end">Credit (<?php echo $currency; ?>)</th>
                                        <th class="text-end">Balance (<?php echo $currency; ?>)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Opening Balance Row -->
                                    <tr class="table-secondary fw-bold">
                                        <td colspan="3"><strong>Opening Balance</strong></td>
                                        <td class="text-end">
                                            <?php if($opening_balance > 0): ?>
                                                <strong><?php echo number_format($opening_balance, 2); ?></strong>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if($opening_balance < 0): ?>
                                                <strong><?php echo number_format(abs($opening_balance), 2); ?></strong>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <strong class="<?php echo $opening_balance > 0 ? 'text-danger' : ($opening_balance < 0 ? 'text-warning' : 'text-success'); ?>">
                                                <?php echo number_format(abs($opening_balance), 2); ?> 
                                                <?php echo $opening_balance > 0 ? 'Due' : ($opening_balance < 0 ? 'Advance' : ''); ?>
                                            </strong>
                                        </td>
                                    </tr>
                                    
                                    <?php if(empty($transactions)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">
                                                <i class="fas fa-info-circle"></i> No transactions found for this period
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($transactions as $t): 
                                            $bal = $t['running_balance'];
                                            $bal_class = $bal > 0 ? 'text-danger' : ($bal < 0 ? 'text-warning' : 'text-success');
                                            
                                            $type_label = '';
                                            $type_badge = '';
                                            $type_icon = '';
                                            
                                            switch($t['trans_type']) {
                                                case 'cash_sale':
                                                    $type_label = 'Cash Sale';
                                                    $type_badge = 'badge-cash-sale';
                                                    $type_icon = 'fa-money-bill-wave';
                                                    break;
                                                case 'sale':
                                                    $type_label = 'Credit Sale';
                                                    $type_badge = 'badge-credit-sale';
                                                    $type_icon = 'fa-shopping-cart';
                                                    break;
                                                case 'payment':
                                                    $type_label = 'Payment Received';
                                                    $type_badge = 'badge-payment';
                                                    $type_icon = 'fa-hand-holding-usd';
                                                    break;
                                                case 'advance_received':
                                                    $type_label = 'Advance Received';
                                                    $type_badge = 'badge-advance-received';
                                                    $type_icon = 'fa-hand-holding-heart';
                                                    break;
                                                case 'advance_used':
                                                    $type_label = 'Advance Used';
                                                    $type_badge = 'badge-advance-used';
                                                    $type_icon = 'fa-check-circle';
                                                    break;
                                                default:
                                                    $type_label = ucfirst(str_replace('_', ' ', $t['trans_type']));
                                                    $type_badge = 'badge-secondary';
                                                    $type_icon = 'fa-file';
                                            }
                                        ?>
                                        <tr class="clickable-row" 
                                            onclick="viewTransaction('<?php echo $t['trans_type']; ?>', '<?php echo $t['ref_no']; ?>')"
                                            title="Click to view details">
                                            <td><?php echo date('d-m-Y', strtotime($t['trans_date'])); ?></td>
                                            <td><strong><?php echo $t['ref_no']; ?></strong></td>
                                            <td>
                                                <span class="badge <?php echo $type_badge; ?>">
                                                    <i class="fas <?php echo $type_icon; ?>"></i> <?php echo $type_label; ?>
                                                </span>
                                                <br>
                                                <small class="transaction-details">
                                                    <?php echo $t['description'] ?? ''; ?>
                                                </small>
                                                <?php if($t['trans_type'] == 'sale' && isset($t['due_date']) && $t['due_date']): ?>
                                                    <br><small class="text-muted">Due: <?php echo date('d-m-Y', strtotime($t['due_date'])); ?></small>
                                                <?php endif; ?>
                                                <?php if($t['trans_type'] == 'sale' && isset($t['advance_adjusted']) && $t['advance_adjusted'] > 0): ?>
                                                    <br><small class="text-success">Advance Used: <?php echo $currency; ?> <?php echo number_format($t['advance_adjusted'], 2); ?></small>
                                                <?php endif; ?>
                                                <?php if($t['trans_type'] == 'payment' || $t['trans_type'] == 'advance_received'): ?>
                                                    <br><small class="text-muted">Method: <?php echo ucfirst($t['payment_method'] ?? 'N/A'); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end <?php echo ($t['debit'] ?? 0) > 0 ? 'text-danger fw-bold' : ''; ?>">
                                                <?php echo ($t['debit'] ?? 0) > 0 ? number_format($t['debit'], 2) : '-'; ?>
                                            </td>
                                            <td class="text-end <?php echo ($t['credit'] ?? 0) > 0 ? 'text-success fw-bold' : ''; ?>">
                                                <?php echo ($t['credit'] ?? 0) > 0 ? number_format($t['credit'], 2) : '-'; ?>
                                            </td>
                                            <td class="text-end fw-bold <?php echo $bal_class; ?>">
                                                <?php echo number_format(abs($bal), 2); ?> 
                                                <?php echo $bal > 0 ? 'Due' : ($bal < 0 ? 'Advance' : ''); ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    
                                    <!-- Closing Balance Row -->
                                    <tr class="table-info fw-bold">
                                        <td colspan="3"><strong>Closing Balance</strong></td>
                                        <td class="text-end"><strong><?php echo number_format($total_debit, 2); ?></strong></td>
                                        <td class="text-end"><strong><?php echo number_format($total_credit, 2); ?></strong></td>
                                        <td class="text-end">
                                            <strong class="<?php echo $net_balance > 0 ? 'text-danger' : ($net_balance < 0 ? 'text-warning' : 'text-success'); ?>">
                                                <?php echo number_format($display_balance, 2); ?> 
                                                <?php echo $balance_label; ?>
                                            </strong>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Summary Cards -->
                <div class="row mt-4">
                    <div class="col-md-3">
                        <div class="card text-white bg-danger">
                            <div class="card-body">
                                <h6>Total Debit (Credit Sales + Advance Used)</h6>
                                <h3><?php echo $currency; ?> <?php echo number_format($total_debit, 2); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <h6>Total Credit (Payments + Advance Received + Cash Sales)</h6>
                                <h3><?php echo $currency; ?> <?php echo number_format($total_credit, 2); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-warning">
                            <div class="card-body">
                                <h6>Net Movement</h6>
                                <h3><?php echo $currency; ?> <?php echo number_format(abs($total_debit - $total_credit), 2); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-info">
                            <div class="card-body">
                                <h6>Total Transactions</h6>
                                <h3><?php echo count($transactions); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php elseif($customer_id): ?>
                <div class="alert alert-danger">Customer not found!</div>
            <?php else: ?>
                <div class="alert alert-info text-center py-5">
                    <i class="fas fa-info-circle fa-2x d-block mb-3"></i>
                    <h5>Please select a customer to view ledger</h5>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- View Transaction Modal -->
    <div class="modal fade" id="transactionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5><i class="fas fa-file-invoice"></i> Transaction Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="transactionDetails">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading transaction details...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#ledgerTable').DataTable({
                order: [[0, 'asc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
        
        function viewTransaction(type, ref_no) {
            $('#transactionModal').modal('show');
            $('#transactionDetails').html(`
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading transaction details...</p>
                </div>
            `);
            
            // Load transaction details via AJAX
            $.ajax({
                url: 'get_transaction_details.php',
                method: 'GET',
                data: {
                    type: type,
                    ref_no: ref_no
                },
                success: function(response) {
                    $('#transactionDetails').html(response);
                },
                error: function() {
                    $('#transactionDetails').html(`
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Transaction Details</strong><br>
                            Reference: ${ref_no}<br>
                            Type: ${type}
                        </div>
                    `);
                }
            });
        }
    </script>
</body>
</html>
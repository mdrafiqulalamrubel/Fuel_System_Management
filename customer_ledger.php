<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$customer_id = isset($_GET['customer_id']) ? $_GET['customer_id'] : '';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-t');

$customers = $pdo->query("SELECT * FROM customers WHERE is_active = 1 ORDER BY customer_name")->fetchAll();

$customer = null;
$transactions = [];
$opening_balance = 0;

if($customer_id) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    
    if($customer) {
        // Get opening balance (transactions before from_date)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as total_sales, COALESCE(SUM(paid_amount), 0) as total_payments 
            FROM credit_sales 
            WHERE customer_id = ? AND sale_date < ?
        ");
        $stmt->execute([$customer_id, $from_date]);
        $opening = $stmt->fetch();
        $opening_balance = ($opening['total_sales'] ?? 0) - ($opening['total_payments'] ?? 0);
        
        // Get sales transactions
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
                id as sale_id
            FROM credit_sales 
            WHERE customer_id = ? AND sale_date BETWEEN ? AND ?
        ");
        $stmt->execute([$customer_id, $from_date, $to_date]);
        $sales = $stmt->fetchAll();
        
        // Get payment transactions
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
                cp.id as payment_id
            FROM credit_payments cp
            JOIN credit_sales cs ON cp.credit_sale_id = cs.id
            WHERE cs.customer_id = ? AND cp.payment_date BETWEEN ? AND ?
        ");
        $stmt->execute([$customer_id, $from_date, $to_date]);
        $payments = $stmt->fetchAll();
        
        // Merge and sort transactions
        $transactions = array_merge($sales, $payments);
        usort($transactions, function($a, $b) {
            return strtotime($a['trans_date']) - strtotime($b['trans_date']);
        });
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
        .clickable-row td:nth-child(2) {
            color: #007bff;
            font-weight: 500;
        }
        .clickable-row td:nth-child(2):hover {
            text-decoration: underline;
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
        
        @media print {
            .sidebar, .no-print, .btn { display: none !important; }
            .main-content { margin: 0 !important; padding: 10px !important; }
            .print-header { display: block !important; text-align: center; margin-bottom: 20px; }
        }
        .print-header { display: none; }
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
                <h2><i class="fas fa-users"></i> Customer Ledger (Party Ledger)</h2>
                <div class="no-print">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">Back</a>
                </div>
            </div>
            
            <div class="card mb-4 no-print">
                <div class="card-header bg-primary text-white">
                    <h5>Select Customer</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row">
                        <div class="col-md-5">
                            <select name="customer_id" class="form-control" required>
                                <option value="">-- Select Customer --</option>
                                <?php foreach($customers as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $customer_id == $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo $c['customer_name']; ?> (<?php echo $c['customer_code']; ?>) - Due: <?php echo $currency; ?> <?php echo number_format($c['current_balance'], 2); ?>
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
            
            <?php if($customer): ?>
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
                        <div class="card text-white bg-warning">
                            <div class="card-body">
                                <h6>Credit Limit</h6>
                                <h4><?php echo $currency; ?> <?php echo number_format($customer['credit_limit'], 2); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-danger">
                            <div class="card-body">
                                <h6>Due Balance</h6>
                                <h4><?php echo $currency; ?> <?php echo number_format($customer['current_balance'], 2); ?></h4>
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
                                <span class="h4 <?php echo $opening_balance > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo $currency; ?> <?php echo number_format(abs($opening_balance), 2); ?> <?php echo $opening_balance > 0 ? '(Dr)' : ($opening_balance < 0 ? '(Cr)' : ''); ?>
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
                                    <?php
                                    $running_balance = $opening_balance;
                                    $total_debit = 0;
                                    $total_credit = 0;
                                    ?>
                                    
                                    <!-- Opening Balance Row -->
                                    <tr class="table-secondary">
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
                                        <td class="text-end"><strong><?php echo number_format(abs($running_balance), 2); ?> <?php echo $running_balance > 0 ? 'Dr' : ($running_balance < 0 ? 'Cr' : ''); ?></strong></td>
                                    </tr>
                                    
                                    <?php if(empty($transactions)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">No transactions found for this period</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($transactions as $t): 
                                            if($t['trans_type'] == 'sale') {
                                                $running_balance += $t['amount'];
                                                $total_debit += $t['amount'];
                                            } else {
                                                $running_balance -= $t['amount'];
                                                $total_credit += $t['amount'];
                                            }
                                        ?>
                                        <tr class="clickable-row" 
                                            onclick="window.open('view_invoice.php?invoice=<?php echo $t['ref_no']; ?>', '_blank')"
                                            title="Click to view details for <?php echo $t['ref_no']; ?>">
                                            <td><?php echo date('d-m-Y', strtotime($t['trans_date'])); ?></td>
                                            <td>
                                                <?php if($t['trans_type'] == 'sale'): ?>
                                                    <?php echo $t['ref_no']; ?>
                                                <?php else: ?>
                                                    <?php echo $t['ref_no']; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($t['trans_type'] == 'sale'): ?>
                                                    <span class="text-primary">Fuel Purchase (Credit)</span>
                                                    <?php if(isset($t['due_date']) && $t['due_date']): ?>
                                                        <br><small class="text-muted">Due: <?php echo date('d-m-Y', strtotime($t['due_date'])); ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-success">Payment Received</span>
                                                    <br><small class="text-muted">Method: <?php echo ucfirst($t['payment_method']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end text-danger fw-bold">
                                                <?php echo $t['trans_type'] == 'sale' ? number_format($t['amount'], 2) : '-'; ?>
                                            </td>
                                            <td class="text-end text-success fw-bold">
                                                <?php echo $t['trans_type'] == 'payment' ? number_format($t['amount'], 2) : '-'; ?>
                                            </td>
                                            <td class="text-end fw-bold">
                                                <?php echo number_format(abs($running_balance), 2); ?> 
                                                <?php echo $running_balance > 0 ? 'Dr' : ($running_balance < 0 ? 'Cr' : ''); ?>
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
                                            <strong class="<?php echo $customer['current_balance'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                                <?php echo number_format(abs($customer['current_balance']), 2); ?> 
                                                <?php echo $customer['current_balance'] > 0 ? 'Dr' : ($customer['current_balance'] < 0 ? 'Cr' : ''); ?>
                                            </strong>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Summary Card -->
                <div class="card mt-3">
                    <div class="card-header bg-dark text-white">
                        <h5>Account Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="alert alert-info">
                                    <strong>Total Sales (This Period)</strong><br>
                                    <span class="h4"><?php echo $currency; ?> <?php echo number_format($total_debit, 2); ?></span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="alert alert-success">
                                    <strong>Total Payments (This Period)</strong><br>
                                    <span class="h4"><?php echo $currency; ?> <?php echo number_format($total_credit, 2); ?></span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="alert alert-danger">
                                    <strong>Outstanding Balance</strong><br>
                                    <span class="h4"><?php echo $currency; ?> <?php echo number_format($customer['current_balance'], 2); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php elseif($customer_id): ?>
                <div class="alert alert-danger">Customer not found!</div>
            <?php else: ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle"></i> Please select a customer to view ledger.
                </div>
            <?php endif; ?>
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
    </script>
</body>
</html>
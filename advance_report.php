<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$report_type = $_GET['type'] ?? 'customer';
$party_id = isset($_GET['party_id']) ? $_GET['party_id'] : '';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-t');

// Get all customers or suppliers for dropdown
if($report_type == 'customer') {
    $parties = $pdo->query("SELECT id, customer_name as name, customer_code as code FROM customers WHERE is_active = 1 ORDER BY customer_name")->fetchAll();
    $party_type = 'customer';
    $party_label = 'Customer';
    $party_table = 'customers';
    $advance_table = 'advance_payments_customer';
    $advance_id_field = 'customer_id';
    $party_id_field = 'customer_id';
    $party_name_field = 'customer_name';
    $party_code_field = 'customer_code';
} else {
    $parties = $pdo->query("SELECT id, supplier_name as name, supplier_code as code FROM suppliers WHERE is_active = 1 ORDER BY supplier_name")->fetchAll();
    $party_type = 'supplier';
    $party_label = 'Supplier';
    $party_table = 'suppliers';
    $advance_table = 'advance_payments_supplier';
    $advance_id_field = 'supplier_id';
    $party_id_field = 'supplier_id';
    $party_name_field = 'supplier_name';
    $party_code_field = 'supplier_code';
}

$party = null;
$transactions = [];
$opening_balance = 0;
$total_debit = 0;
$total_credit = 0;

if($party_id) {
    // Get party details
    $stmt = $pdo->prepare("SELECT * FROM $party_table WHERE id = ?");
    $stmt->execute([$party_id]);
    $party = $stmt->fetch();
    
    if($party) {
        // =============================================
        // Calculate OPENING BALANCE (before from_date)
        // =============================================
        
        // Get all advance payments before from_date
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_advance,
                   COALESCE(SUM(used_amount), 0) as total_used
            FROM $advance_table 
            WHERE $advance_id_field = ? AND advance_date < ?
            AND status != 'cancelled'
        ");
        $stmt->execute([$party_id, $from_date]);
        $advance_before = $stmt->fetch();
        $opening_balance = ($advance_before['total_advance'] ?? 0) - ($advance_before['total_used'] ?? 0);
        
        // =============================================
        // Get ALL transactions for the period
        // =============================================
        
        // 1. ADVANCE RECEIVED (Credit - increases advance balance)
        $stmt = $pdo->prepare("
            SELECT 
                advance_date as trans_date,
                CONCAT('ADV-', id) as ref_no,
                amount as amount,
                'advance_received' as trans_type,
                payment_method,
                reference_no as receipt_no,
                0 as debit,
                amount as credit,
                id as advance_id,
                CONCAT('Advance Received - ', reference_no) as description,
                notes
            FROM $advance_table 
            WHERE $advance_id_field = ? AND advance_date BETWEEN ? AND ?
            AND status != 'cancelled'
            ORDER BY advance_date ASC
        ");
        $stmt->execute([$party_id, $from_date, $to_date]);
        $advance_received = $stmt->fetchAll();
        
        // 2. ADVANCE USED (Debit - reduces advance balance)
        // Get used amounts from advance payments (if used_amount is tracked)
        $stmt = $pdo->prepare("
            SELECT 
                advance_date as trans_date,
                CONCAT('ADV-', id) as ref_no,
                used_amount as amount,
                'advance_used' as trans_type,
                NULL as payment_method,
                reference_no as receipt_no,
                used_amount as debit,
                0 as credit,
                id as usage_id,
                CONCAT('Advance Used - ', reference_no) as description,
                notes
            FROM $advance_table 
            WHERE $advance_id_field = ? AND used_amount > 0
            AND advance_date BETWEEN ? AND ?
            AND status != 'cancelled'
            ORDER BY advance_date ASC
        ");
        $stmt->execute([$party_id, $from_date, $to_date]);
        $advance_used = $stmt->fetchAll();

        // 3. ADVANCE ADJUSTMENT (from journal entries that affect advance)
        $stmt = $pdo->prepare("
            SELECT 
                entry_date as trans_date,
                reference_no as ref_no,
                amount as amount,
                'advance_adjustment' as trans_type,
                NULL as payment_method,
                NULL as receipt_no,
                CASE WHEN entry_type = 'debit' THEN amount ELSE 0 END as debit,
                CASE WHEN entry_type = 'credit' THEN amount ELSE 0 END as credit,
                id as adjustment_id,
                description,
                notes
            FROM journal_entries 
            WHERE $advance_id_field = ? 
            AND entry_date BETWEEN ? AND ?
            AND (description LIKE '%advance%' OR description LIKE '%Advance%')
            AND status IN ('approved', 'posted')
            ORDER BY entry_date ASC
        ");
        $stmt->execute([$party_id, $from_date, $to_date]);
        $advance_adjustments = $stmt->fetchAll();

        // Merge all transactions
        $transactions = array_merge($advance_received, $advance_used, $advance_adjustments);

                
        // Sort by date
        usort($transactions, function($a, $b) {
            return strtotime($a['trans_date']) - strtotime($b['trans_date']);
        });
        
        // Calculate running balance
        $running_balance = $opening_balance;
        
        foreach($transactions as &$t) {
            $running_balance += ($t['credit'] ?? 0) - ($t['debit'] ?? 0);
            $t['running_balance'] = $running_balance;
            $total_debit += ($t['debit'] ?? 0);
            $total_credit += ($t['credit'] ?? 0);
        }
        unset($t);
        
        // Get updated party balance
        $stmt = $pdo->prepare("SELECT advance_balance FROM $party_table WHERE id = ?");
        $stmt->execute([$party_id]);
        $party_balance = $stmt->fetch();
        $party['advance_balance'] = $party_balance['advance_balance'] ?? 0;
    }
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advance Payment Ledger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
        
        .badge-advance-received { background: #17a2b8; color: white; }
        .badge-advance-used { background: #ffc107; color: #856404; }
        
        .balance-positive { color: #17a2b8; font-weight: bold; }
        .balance-negative { color: #dc3545; font-weight: bold; }
        .balance-zero { color: #6c757d; }
        
        @media print {
            .sidebar, .no-print, .btn, .dataTables_length, .dataTables_filter, .dataTables_paginate {
                display: none !important;
            }
            .main-content { margin: 0 !important; padding: 10px !important; }
            .print-header { display: block !important; text-align: center; margin-bottom: 20px; }
        }
        .print-header { display: none; }
        
        .transaction-details {
            font-size: 11px;
            color: #666;
        }
        
        .net-balance-card {
            border-left: 5px solid;
            border-radius: 10px;
        }
        .net-balance-card.positive { border-left-color: #17a2b8; }
        .net-balance-card.negative { border-left-color: #dc3545; }
        .net-balance-card.zero { border-left-color: #28a745; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="print-header">
                <h2><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h2>
                <h4>Advance Payment Ledger - <?php echo $party_label; ?></h4>
                <p>Period: <?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-file-invoice"></i> Advance Payment Ledger</h2>
                <div class="no-print">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <a href="advance_management.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- Filter -->
            <div class="card mb-4 no-print">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-filter"></i> Filter Report</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label>Report Type</label>
                            <select name="type" class="form-control" onchange="this.form.submit()">
                                <option value="customer" <?php echo $report_type == 'customer' ? 'selected' : ''; ?>>Customer Advances</option>
                                <option value="supplier" <?php echo $report_type == 'supplier' ? 'selected' : ''; ?>>Supplier Advances</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>Select <?php echo $party_label; ?></label>
                            <select name="party_id" class="form-control">
                                <option value="">-- All <?php echo $party_label; ?>s --</option>
                                <?php foreach($parties as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" <?php echo $party_id == $p['id'] ? 'selected' : ''; ?>>
                                        <?php echo $p['name']; ?> (<?php echo $p['code']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>">
                        </div>
                        <div class="col-md-2">
                            <label>To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>">
                        </div>
                        <div class="col-md-2">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-info w-100">
                                <i class="fas fa-search"></i> Generate
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php if($party): 
                $net_balance = $party['advance_balance'] ?? 0;
                $is_positive = $net_balance > 0;
                $is_zero = $net_balance == 0;
                $display_balance = abs($net_balance);
                $balance_label = $is_positive ? 'Advance Balance' : ($is_zero ? 'Settled' : 'Overdrawn');
                $balance_class = $is_positive ? 'positive' : ($is_zero ? 'zero' : 'negative');
            ?>
                <!-- Party Info Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card text-white bg-info">
                            <div class="card-body">
                                <h6><?php echo $party_label; ?> Name</h6>
                                <h4><?php echo htmlspecialchars($party[$party_name_field]); ?></h4>
                                <small>Code: <?php echo $party[$party_code_field]; ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-white bg-secondary">
                            <div class="card-body">
                                <h6>Contact</h6>
                                <h4><?php echo $party['phone'] ?: 'N/A'; ?></h4>
                                <small><?php echo $party['address'] ?: 'No address'; ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-white bg-<?php echo $is_positive ? 'info' : ($is_zero ? 'success' : 'danger'); ?>">
                            <div class="card-body">
                                <h6>Current Balance</h6>
                                <h4>
                                    <?php if($is_zero): ?>
                                        <?php echo $currency; ?> 0.00
                                    <?php else: ?>
                                        <?php echo $currency; ?> <?php echo number_format($display_balance, 2); ?>
                                        (<?php echo $is_positive ? 'Advance' : 'Overdrawn'; ?>)
                                    <?php endif; ?>
                                </h4>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Opening Balance -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card net-balance-card <?php echo $balance_class; ?>">
                            <div class="card-body">
                                <strong>Opening Balance (as on <?php echo date('d M Y', strtotime($from_date)); ?>):</strong>
                                <span class="h4 <?php echo $opening_balance > 0 ? 'balance-positive' : ($opening_balance < 0 ? 'balance-negative' : 'balance-zero'); ?>">
                                    <?php echo $currency; ?> <?php echo number_format(abs($opening_balance), 2); ?>
                                    <?php echo $opening_balance > 0 ? '(Advance)' : ($opening_balance < 0 ? '(Overdrawn)' : ''); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Ledger Table -->
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5>Advance Ledger Statement (<?php echo date('d M Y', strtotime($from_date)); ?> to <?php echo date('d M Y', strtotime($to_date)); ?>)</h5>
                        <small class="d-block text-light">Shows all advance transactions with running balance</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="advanceLedgerTable">
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
                                            <?php if($opening_balance < 0): ?>
                                                <strong><?php echo number_format(abs($opening_balance), 2); ?></strong>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if($opening_balance > 0): ?>
                                                <strong><?php echo number_format($opening_balance, 2); ?></strong>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <strong class="<?php echo $opening_balance > 0 ? 'balance-positive' : ($opening_balance < 0 ? 'balance-negative' : 'balance-zero'); ?>">
                                                <?php echo number_format(abs($opening_balance), 2); ?> 
                                                <?php echo $opening_balance > 0 ? 'Advance' : ($opening_balance < 0 ? 'Overdrawn' : ''); ?>
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
                                            $bal_class = $bal > 0 ? 'balance-positive' : ($bal < 0 ? 'balance-negative' : 'balance-zero');
                                            
                                            $type_label = '';
                                            $type_badge = '';
                                            $type_icon = '';
                                            
                                            switch($t['trans_type']) {
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
                                        <tr>
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
                                                <?php if($t['trans_type'] == 'advance_received'): ?>
                                                    <br><small class="text-muted">Method: <?php echo ucfirst($t['payment_method'] ?? 'N/A'); ?></small>
                                                <?php endif; ?>
                                                <?php if(!empty($t['notes'])): ?>
                                                    <br><small class="text-muted">Notes: <?php echo htmlspecialchars($t['notes']); ?></small>
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
                                                <?php echo $bal > 0 ? 'Advance' : ($bal < 0 ? 'Overdrawn' : ''); ?>
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
                                            <strong class="<?php echo $net_balance > 0 ? 'balance-positive' : ($net_balance < 0 ? 'balance-negative' : 'balance-zero'); ?>">
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
                                <h6>Total Debit (Used)</h6>
                                <h3><?php echo $currency; ?> <?php echo number_format($total_debit, 2); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <h6>Total Credit (Received)</h6>
                                <h3><?php echo $currency; ?> <?php echo number_format($total_credit, 2); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-warning">
                            <div class="card-body">
                                <h6>Net Movement</h6>
                                <h3><?php echo $currency; ?> <?php echo number_format(abs($total_credit - $total_debit), 2); ?></h3>
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
                
            <?php elseif($party_id): ?>
                <div class="alert alert-danger"><?php echo $party_label; ?> not found!</div>
            <?php else: ?>
                <div class="alert alert-info text-center py-5">
                    <i class="fas fa-info-circle fa-2x d-block mb-3"></i>
                    <h5>Please select a <?php echo strtolower($party_label); ?> to view advance ledger</h5>
                    <p class="text-muted">Select a <?php echo strtolower($party_label); ?> from the dropdown above and click Generate</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#advanceLedgerTable').DataTable({
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
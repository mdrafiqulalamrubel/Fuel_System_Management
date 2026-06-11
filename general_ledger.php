<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$account_id = isset($_GET['account_id']) ? $_GET['account_id'] : '';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-t');

// Get all accounts for dropdown
$accounts = $pdo->query("SELECT * FROM chart_of_accounts WHERE is_active = 1 ORDER BY account_type, account_code")->fetchAll();

$account = null;
$transactions = [];
$opening_balance = 0;
$closing_balance = 0;

if($account_id) {
    // Get account details
    $stmt = $pdo->prepare("SELECT * FROM chart_of_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch();
    
    if($account) {
        // Calculate opening balance (transactions before from_date)
        $stmt = $pdo->prepare("
            SELECT SUM(vi.debit_amount) as total_debit, SUM(vi.credit_amount) as total_credit 
            FROM voucher_items vi 
            JOIN vouchers v ON vi.voucher_id = v.id 
            WHERE vi.account_id = ? AND v.date < ? AND v.status = 'approved'
        ");
        $stmt->execute([$account_id, $from_date]);
        $opening = $stmt->fetch();
        $opening_debit = $opening['total_debit'] ?? 0;
        $opening_credit = $opening['total_credit'] ?? 0;
        
        // Calculate opening balance based on account type
        if($account['balance_type'] == 'debit') {
            $opening_balance = $account['opening_balance'] + ($opening_debit - $opening_credit);
        } else {
            $opening_balance = $account['opening_balance'] + ($opening_credit - $opening_debit);
        }
        
        // Get transactions for the selected period
        $stmt = $pdo->prepare("
            SELECT 
                v.date,
                v.voucher_no,
                v.voucher_type,
                v.narration,
                vi.debit_amount,
                vi.credit_amount,
                a.account_name as contra_account,
                v.created_at
            FROM voucher_items vi 
            JOIN vouchers v ON vi.voucher_id = v.id 
            LEFT JOIN voucher_items vi2 ON vi2.voucher_id = v.id AND vi2.account_id != vi.account_id
            LEFT JOIN chart_of_accounts a ON vi2.account_id = a.id
            WHERE vi.account_id = ? AND v.date BETWEEN ? AND ? AND v.status = 'approved'
            GROUP BY vi.id
            ORDER BY v.date ASC, v.created_at ASC
        ");
        $stmt->execute([$account_id, $from_date, $to_date]);
        $transactions = $stmt->fetchAll();
        
        // Calculate closing balance
        $period_debit = 0;
        $period_credit = 0;
        foreach($transactions as $t) {
            $period_debit += $t['debit_amount'];
            $period_credit += $t['credit_amount'];
        }
        
        if($account['balance_type'] == 'debit') {
            $closing_balance = $opening_balance + ($period_debit - $period_credit);
        } else {
            $closing_balance = $opening_balance + ($period_credit - $period_debit);
        }
    }
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>General Ledger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            .no-print { display: none; }
            body { margin: 0; padding: 20px; }
            .ledger-header { text-align: center; margin-bottom: 20px; }
        }
        .balance-positive { color: #28a745; font-weight: bold; }
        .balance-negative { color: #dc3545; font-weight: bold; }
        .balance-zero { color: #6c757d; }
        .ledger-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 20px;
            margin-bottom: 20px;
        }
        .account-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-scroll"></i> General Ledger</h2>
                <div class="no-print">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <a href="accounting.php?tab=reports" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- Filter Form -->
            <div class="card no-print mb-4">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-filter"></i> Filter Ledger</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row">
                        <div class="col-md-5">
                            <label>Select Account</label>
                            <select name="account_id" class="form-control" required>
                                <option value="">-- Select Account --</option>
                                <?php foreach($accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>" <?php echo $account_id == $acc['id'] ? 'selected' : ''; ?>>
                                        <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name']; ?> (<?php echo ucfirst($acc['account_type']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label>To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>" required>
                        </div>
                        <div class="col-md-1">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search"></i> View
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php if($account && $account_id): ?>
                <!-- Account Information -->
                <div class="account-info">
                    <div class="row">
                        <div class="col-md-4">
                            <small>Account Code</small>
                            <h4><?php echo $account['account_code']; ?></h4>
                        </div>
                        <div class="col-md-4">
                            <small>Account Name</small>
                            <h4><?php echo $account['account_name']; ?></h4>
                        </div>
                        <div class="col-md-4">
                            <small>Account Type</small>
                            <h4><?php echo ucfirst($account['account_type']); ?> (<?php echo ucfirst($account['balance_type']); ?> balance)</h4>
                        </div>
                    </div>
                </div>
                
                <!-- Ledger Report -->
                <div class="ledger-card">
                    <div class="text-center mb-4">
                        <h3><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h3>
                        <h4>General Ledger</h4>
                        <h5><?php echo $account['account_name']; ?> (<?php echo $account['account_code']; ?>)</h5>
                        <p>Period: <?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered" id="ledgerTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Date</th>
                                    <th>Voucher No</th>
                                    <th>Particulars</th>
                                    <th class="text-end">Debit (BDT)</th>
                                    <th class="text-end">Credit (BDT)</th>
                                    <th class="text-end">Balance (BDT)</th>
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
                                        <?php if($account['balance_type'] == 'debit' && $opening_balance > 0): ?>
                                            <strong><?php echo number_format($opening_balance, 2); ?></strong>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if($account['balance_type'] == 'credit' && $opening_balance > 0): ?>
                                            <strong><?php echo number_format($opening_balance, 2); ?></strong>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><strong><?php echo number_format($opening_balance, 2); ?></strong></td>
                                </tr>
                                
                                <?php if(empty($transactions)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">No transactions found for this period</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($transactions as $t): 
                                        if($account['balance_type'] == 'debit') {
                                            $running_balance += ($t['debit_amount'] - $t['credit_amount']);
                                        } else {
                                            $running_balance += ($t['credit_amount'] - $t['debit_amount']);
                                        }
                                        $total_debit += $t['debit_amount'];
                                        $total_credit += $t['credit_amount'];
                                    ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($t['date'])); ?></td>
                                        <td>
                                            <a href="view_voucher.php?voucher_no=<?php echo $t['voucher_no']; ?>" target="_blank">
                                                <?php echo $t['voucher_no']; ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php echo $t['narration']; ?>
                                            <?php if($t['contra_account']): ?>
                                                <br><small class="text-muted">Contra: <?php echo $t['contra_account']; ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end <?php echo $t['debit_amount'] > 0 ? 'text-danger fw-bold' : ''; ?>">
                                            <?php echo $t['debit_amount'] > 0 ? number_format($t['debit_amount'], 2) : '-'; ?>
                                        </td>
                                        <td class="text-end <?php echo $t['credit_amount'] > 0 ? 'text-success fw-bold' : ''; ?>">
                                            <?php echo $t['credit_amount'] > 0 ? number_format($t['credit_amount'], 2) : '-'; ?>
                                        </td>
                                        <td class="text-end fw-bold">
                                            <?php echo number_format($running_balance, 2); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                
                                <!-- Closing Balance Row -->
                                <tr class="table-info fw-bold">
                                    <td colspan="2"><strong>Closing Balance</strong></td>
                                    <td class="text-end"><strong>Total</strong></td>
                                    <td class="text-end"><strong><?php echo number_format($total_debit, 2); ?></strong></td>
                                    <td class="text-end"><strong><?php echo number_format($total_credit, 2); ?></strong></td>
                                    <td class="text-end">
                                        <strong class="<?php echo $closing_balance > 0 ? 'balance-positive' : ($closing_balance < 0 ? 'balance-negative' : 'balance-zero'); ?>">
                                            <?php echo number_format($closing_balance, 2); ?>
                                        </strong>
                                    </td>
                                </tr>
                            </tbody>
                         </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="alert alert-info">
                                <strong><i class="fas fa-info-circle"></i> Account Summary</strong><br>
                                Opening Balance: <?php echo number_format($opening_balance, 2); ?> (<?php echo ucfirst($account['balance_type']); ?>)<br>
                                Total Debit: <?php echo number_format($total_debit, 2); ?><br>
                                Total Credit: <?php echo number_format($total_credit, 2); ?><br>
                                Closing Balance: <?php echo number_format($closing_balance, 2); ?> (<?php echo ucfirst($account['balance_type']); ?>)
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="alert alert-warning">
                                <strong><i class="fas fa-chart-line"></i> Balance Interpretation</strong><br>
                                <?php if($account['account_type'] == 'asset' || $account['account_type'] == 'expense'): ?>
                                    Debit balance indicates normal position. Credit balance indicates overpayment or error.
                                <?php elseif($account['account_type'] == 'liability' || $account['account_type'] == 'equity' || $account['account_type'] == 'income'): ?>
                                    Credit balance indicates normal position. Debit balance indicates overpayment or error.
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif($account_id && !$account): ?>
                <div class="alert alert-danger">Account not found!</div>
            <?php else: ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle"></i> Please select an account to view the ledger.
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
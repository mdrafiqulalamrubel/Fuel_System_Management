<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'chart';

// Process Add/Update Account
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_account'])) {
    $account_id = isset($_POST['account_id']) ? $_POST['account_id'] : null;
    $account_code = $_POST['account_code'];
    $account_name = $_POST['account_name'];
    $account_type = $_POST['account_type'];
    $parent_id = $_POST['parent_id'] ?: NULL;
    $opening_balance = $_POST['opening_balance'];
    $balance_type = $_POST['balance_type'];
    
    try {
        if($account_id) {
            $stmt = $pdo->prepare("UPDATE chart_of_accounts SET account_code = ?, account_name = ?, account_type = ?, parent_id = ?, opening_balance = ?, balance_type = ? WHERE id = ?");
            $stmt->execute([$account_code, $account_name, $account_type, $parent_id, $opening_balance, $balance_type, $account_id]);
            $success = "Account updated successfully!";
        } else {
            $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ?");
            $stmt->execute([$account_code]);
            if($stmt->fetch()) {
                $error = "Account code already exists!";
            } else {
                $stmt = $pdo->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, parent_id, opening_balance, balance_type, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([$account_code, $account_name, $account_type, $parent_id, $opening_balance, $balance_type]);
                $success = "Account created successfully!";
            }
        }
    } catch(Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Delete Account
if(isset($_GET['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM chart_of_accounts WHERE id = ?");
    if($stmt->execute([$_GET['delete_id']])) {
        $success = "Account deleted successfully!";
    } else {
        $error = "Cannot delete account with existing transactions!";
    }
}

// Process Voucher - FIXED with proper account validation
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_voucher'])) {
    $voucher_type = $_POST['voucher_type'];
    $date = $_POST['date'];
    $narration = $_POST['narration'];
    $accounts = $_POST['account_id'];
    $debits = $_POST['debit'];
    $credits = $_POST['credit'];
    
    try {
        $pdo->beginTransaction();
        
        // Validate all accounts exist before inserting
        foreach($accounts as $acc_id) {
            if(!empty($acc_id)) {
                $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE id = ? AND is_active = 1");
                $stmt->execute([$acc_id]);
                if(!$stmt->fetch()) {
                    throw new Exception("Invalid account ID: $acc_id. Account does not exist.");
                }
            }
        }
        
        $voucher_no = 'VCH-' . date('YmdHis') . rand(100,999);
        $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, ?, ?, ?, ?, 'approved')");
        $stmt->execute([$voucher_no, $voucher_type, $date, $narration, $user['id']]);
        $voucher_id = $pdo->lastInsertId();
        
        for($i = 0; $i < count($accounts); $i++) {
            if($debits[$i] > 0 || $credits[$i] > 0) {
                $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$voucher_id, $accounts[$i], $debits[$i], $credits[$i], $narration]);
            }
        }
        
        $pdo->commit();
        $success = "Voucher saved successfully! Voucher No: $voucher_no";
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Get accounts for dropdown
$accounts = $pdo->query("SELECT * FROM chart_of_accounts WHERE is_active = 1 ORDER BY account_code")->fetchAll();
$vouchers = $pdo->query("SELECT v.*, u.full_name as creator FROM vouchers v JOIN users u ON v.created_by = u.id ORDER BY v.created_at DESC LIMIT 50")->fetchAll();

// Get account for editing
$edit_account = null;
if(isset($_GET['edit_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM chart_of_accounts WHERE id = ?");
    $stmt->execute([$_GET['edit_id']]);
    $edit_account = $stmt->fetch();
}

// Get company settings
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

// Calculate current balances
$account_balances = [];
foreach($accounts as $acc) {
    $stmt = $pdo->prepare("SELECT SUM(debit_amount) as total_debit, SUM(credit_amount) as total_credit FROM voucher_items vi JOIN vouchers v ON vi.voucher_id = v.id WHERE vi.account_id = ? AND v.status = 'approved'");
    $stmt->execute([$acc['id']]);
    $row = $stmt->fetch();
    $total_debit = $row['total_debit'] ?? 0;
    $total_credit = $row['total_credit'] ?? 0;
    
    if($acc['balance_type'] == 'debit') {
        $current_balance = $acc['opening_balance'] + ($total_debit - $total_credit);
    } else {
        $current_balance = $acc['opening_balance'] + ($total_credit - $total_debit);
    }
    $account_balances[$acc['id']] = $current_balance;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .edit-row { cursor: pointer; }
        .edit-row:hover { background-color: #f0f0f0; }
        .balance-positive { color: green; }
        .balance-negative { color: red; }
        .form-card { position: sticky; top: 20px; }
        
        @media print {
            .sidebar, .main-content .container-fluid .d-flex, .nav-tabs, .btn, .alert,
            .card-header .btn, .form-card, .col-md-4, .dataTables_length, .dataTables_filter,
            .dataTables_paginate, .dataTables_info, .no-print { display: none !important; }
            .col-md-8 { width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .main-content { margin: 0 !important; padding: 0 !important; }
            .table th, .table td { border: 1px solid #000 !important; }
            .print-report-header { display: block !important; text-align: center; margin-bottom: 20px; }
        }
        .print-report-header { display: none; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-book"></i> Accounting Management</h2>
                <div class="no-print">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Chart of Accounts
                    </button>
                </div>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <ul class="nav nav-tabs">
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'chart' ? 'active' : ''; ?>" href="?tab=chart">
                        <i class="fas fa-list"></i> Chart of Accounts
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'voucher' ? 'active' : ''; ?>" href="?tab=voucher">
                        <i class="fas fa-file-invoice"></i> Voucher Entry
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'list' ? 'active' : ''; ?>" href="?tab=list">
                        <i class="fas fa-history"></i> Voucher List
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'reports' ? 'active' : ''; ?>" href="?tab=reports">
                        <i class="fas fa-chart-bar"></i> Reports
                    </a>
                </li>
            </ul>
            
            <!-- Chart of Accounts Tab -->
            <?php if($active_tab == 'chart'): ?>
            <div class="row mt-3">
                <div class="col-md-4">
                    <div class="card form-card">
                        <div class="card-header <?php echo $edit_account ? 'bg-warning' : 'bg-primary'; ?> text-white">
                            <h5 class="mb-0">
                                <i class="fas <?php echo $edit_account ? 'fa-edit' : 'fa-plus'; ?>"></i> 
                                <?php echo $edit_account ? 'Edit Account' : 'Add New Account'; ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <?php if($edit_account): ?>
                                    <input type="hidden" name="account_id" value="<?php echo $edit_account['id']; ?>">
                                <?php endif; ?>
                                
                                <div class="mb-3">
                                    <label><i class="fas fa-code"></i> Account Code *</label>
                                    <input type="text" name="account_code" class="form-control" 
                                           value="<?php echo $edit_account['account_code'] ?? ''; ?>" 
                                           placeholder="e.g., 1001" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label><i class="fas fa-tag"></i> Account Name *</label>
                                    <input type="text" name="account_name" class="form-control" 
                                           value="<?php echo $edit_account['account_name'] ?? ''; ?>" 
                                           placeholder="e.g., Cash Account" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label><i class="fas fa-folder"></i> Account Type</label>
                                    <select name="account_type" class="form-control" required>
                                        <option value="asset" <?php echo ($edit_account && $edit_account['account_type'] == 'asset') ? 'selected' : ''; ?>>Asset</option>
                                        <option value="liability" <?php echo ($edit_account && $edit_account['account_type'] == 'liability') ? 'selected' : ''; ?>>Liability</option>
                                        <option value="equity" <?php echo ($edit_account && $edit_account['account_type'] == 'equity') ? 'selected' : ''; ?>>Equity</option>
                                        <option value="income" <?php echo ($edit_account && $edit_account['account_type'] == 'income') ? 'selected' : ''; ?>>Income</option>
                                        <option value="expense" <?php echo ($edit_account && $edit_account['account_type'] == 'expense') ? 'selected' : ''; ?>>Expense</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label><i class="fas fa-sitemap"></i> Parent Account</label>
                                    <select name="parent_id" class="form-control">
                                        <option value="">None (Main Account)</option>
                                        <?php foreach($accounts as $acc): ?>
                                            <?php if(!$edit_account || $edit_account['id'] != $acc['id']): ?>
                                                <option value="<?php echo $acc['id']; ?>" 
                                                    <?php echo ($edit_account && $edit_account['parent_id'] == $acc['id']) ? 'selected' : ''; ?>>
                                                    <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name']; ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-dollar-sign"></i> Opening Balance</label>
                                            <input type="number" name="opening_balance" class="form-control" 
                                                   value="<?php echo $edit_account['opening_balance'] ?? 0; ?>" 
                                                   step="0.01">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-balance-scale"></i> Balance Type</label>
                                            <select name="balance_type" class="form-control">
                                                <option value="debit" <?php echo ($edit_account && $edit_account['balance_type'] == 'debit') ? 'selected' : ''; ?>>Debit (Dr)</option>
                                                <option value="credit" <?php echo ($edit_account && $edit_account['balance_type'] == 'credit') ? 'selected' : ''; ?>>Credit (Cr)</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="submit" name="save_account" class="btn btn-success w-100 mb-2">
                                    <i class="fas fa-save"></i> <?php echo $edit_account ? 'Update Account' : 'Save Account'; ?>
                                </button>
                                
                                <?php if($edit_account): ?>
                                    <a href="?tab=chart" class="btn btn-secondary w-100">
                                        <i class="fas fa-plus"></i> Add New Account
                                    </a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                    
                    <div class="card mt-3">
                        <div class="card-header bg-info text-white">
                            <h6><i class="fas fa-info-circle"></i> Balance Type Guide</h6>
                        </div>
                        <div class="card-body">
                            <ul class="mb-0">
                                <li><span class="text-success">Debit (Dr)</span> - Assets, Expenses</li>
                                <li><span class="text-danger">Credit (Cr)</span> - Liabilities, Equity, Income</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-list"></i> Chart of Accounts</h5>
                        </div>
                        <div class="card-body">
                            <div class="print-report-header">
                                <h2><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h2>
                                <h4>Chart of Accounts</h4>
                                <p><?php echo $settings['company_address'] ?? 'Dhaka, Bangladesh'; ?></p>
                                <p>Generated on: <?php echo date('d/m/Y h:i:s A'); ?></p>
                                <hr>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-bordered" id="accountsTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Code</th>
                                            <th>Account Name</th>
                                            <th>Type</th>
                                            <th class="text-end">Opening Balance</th>
                                            <th class="text-end">Current Balance</th>
                                            <th class="text-center">Dr/Cr</th>
                                            <th class="no-print">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $total_opening = 0;
                                        $total_current = 0;
                                        foreach($accounts as $acc): 
                                        $current_bal = $account_balances[$acc['id']] ?? $acc['opening_balance'];
                                        $total_opening += $acc['opening_balance'];
                                        $total_current += $current_bal;
                                        ?>
                                        <tr>
                                            <td><?php echo $acc['account_code']; ?></td>
                                            <td><strong><?php echo $acc['account_name']; ?></strong></td>
                                            <td>
                                                <span class="badge <?php 
                                                    echo $acc['account_type'] == 'asset' ? 'bg-primary' : 
                                                        ($acc['account_type'] == 'liability' ? 'bg-danger' : 
                                                        ($acc['account_type'] == 'income' ? 'bg-success' : 'bg-secondary')); 
                                                ?>">
                                                    <?php echo ucfirst($acc['account_type']); ?>
                                                </span>
                                              </td>
                                            <td class="text-end"><?php echo number_format($acc['opening_balance'], 2); ?></td>
                                            <td class="text-end fw-bold <?php echo $current_bal > 0 ? 'balance-positive' : ($current_bal < 0 ? 'balance-negative' : ''); ?>">
                                                <?php echo number_format($current_bal, 2); ?>
                                              </td>
                                            <td class="text-center">
                                                <span class="badge <?php echo $acc['balance_type'] == 'debit' ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo strtoupper(substr($acc['balance_type'], 0, 1)); ?>
                                                </span>
                                              </td>
                                            <td class="no-print">
                                                <a href="?tab=chart&edit_id=<?php echo $acc['id']; ?>" class="btn btn-sm btn-warning">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <a href="?delete_id=<?php echo $acc['id']; ?>&tab=chart" class="btn btn-sm btn-danger" 
                                                   onclick="return confirm('Delete this account?')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                              </td>
                                          </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="3" class="text-end">TOTAL:  </td>
                                            <td class="text-end"><?php echo number_format($total_opening, 2); ?>  </td>
                                            <td class="text-end"><?php echo number_format($total_current, 2); ?>  </td>
                                            <td colspan="2" class="no-print"> </td>
                                        </tr>
                                    </tfoot>
                                  </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Voucher Entry Tab -->
            <?php if($active_tab == 'voucher'): ?>
            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="fas fa-file-invoice"></i> Voucher Entry</h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Note:</strong> Debit and Credit totals must be equal. Select accounts from the list below.
                            </div>
                            
                            <form method="POST" id="voucherForm">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label>Voucher Type</label>
                                            <select name="voucher_type" class="form-control" required>
                                                <option value="journal">Journal Voucher</option>
                                                <option value="payment">Payment Voucher</option>
                                                <option value="receipt">Receipt Voucher</option>
                                                <option value="contra">Contra Voucher</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label>Date</label>
                                            <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label>Narration</label>
                                            <input type="text" name="narration" class="form-control" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-bordered" id="voucherTable">
                                        <thead class="table-dark">
                                            <tr>
                                                <th width="40%">Account</th>
                                                <th width="25%">Debit (BDT)</th>
                                                <th width="25%">Credit (BDT)</th>
                                                <th width="10%">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>
                                                    <select name="account_id[]" class="form-control account-select" required>
                                                        <option value="">-- Select Account --</option>
                                                        <?php foreach($accounts as $acc): ?>
                                                            <option value="<?php echo $acc['id']; ?>">
                                                                <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name']; ?> (<?php echo ucfirst($acc['account_type']); ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                  </td>
                                                <td><input type="number" name="debit[]" class="form-control debit" step="0.01" value="0"> </td>
                                                <td><input type="number" name="credit[]" class="form-control credit" step="0.01" value="0"> </td>
                                                <td><button type="button" class="btn btn-danger btn-sm remove-row">×</button> </td>
                                              </tr>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th class="text-end">Total:</th>
                                                <th><span id="totalDebit" class="fw-bold">0.00</span></th>
                                                <th><span id="totalCredit" class="fw-bold">0.00</span></th>
                                                <th>
                                                    <button type="button" class="btn btn-primary btn-sm" id="addRow">
                                                        <i class="fas fa-plus"></i> Add Row
                                                    </button>
                                                </th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                
                                <div class="mt-3">
                                    <button type="submit" name="save_voucher" class="btn btn-success">
                                        <i class="fas fa-save"></i> Save Voucher
                                    </button>
                                    <button type="reset" class="btn btn-secondary">
                                        <i class="fas fa-undo"></i> Reset
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Voucher List Tab -->
            <?php if($active_tab == 'list'): ?>
            <div class="mt-3">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5><i class="fas fa-history"></i> Recent Vouchers</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="vouchersTable">
                                <thead>
                                    <tr>
                                        <th>Voucher No</th>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Narration</th>
                                        <th>Created By</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($vouchers as $v): ?>
                                    <tr>
                                        <td><?php echo $v['voucher_no']; ?></td>
                                        <td><?php echo $v['date']; ?></td>
                                        <td><?php echo ucfirst($v['voucher_type']); ?></td>
                                        <td><?php echo substr($v['narration'], 0, 50); ?></td>
                                        <td><?php echo $v['creator']; ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $v['status'] == 'approved' ? 'success' : 'warning'; ?>">
                                                <?php echo ucfirst($v['status']); ?>
                                            </span>
                                          </td>
                                        <td>
                                            <a href="view_voucher.php?id=<?php echo $v['id']; ?>" class="btn btn-sm btn-info">View</a>
                                          </td>
                                      </tr>
                                    <?php endforeach; ?>
                                </tbody>
                             </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Reports Tab -->
            <?php if($active_tab == 'reports'): ?>
            <div class="row mt-3">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-balance-scale"></i> Trial Balance</h5>
                        </div>
                        <div class="card-body">
                            <form target="_blank" action="trial_balance.php" method="GET">
                                <div class="mb-3">
                                    <label>As on Date</label>
                                    <input type="date" name="as_on" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Generate Trial Balance</button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="fas fa-scroll"></i> General Ledger</h5>
                        </div>
                        <div class="card-body">
                            <form target="_blank" action="general_ledger.php" method="GET">
                                <div class="mb-3">
                                    <label>Select Account</label>
                                    <select name="account_id" class="form-control" required>
                                        <option value="">Select Account</option>
                                        <?php foreach($accounts as $acc): ?>
                                            <option value="<?php echo $acc['id']; ?>"><?php echo $acc['account_code']; ?> - <?php echo $acc['account_name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <label>From Date</label>
                                        <input type="date" name="from_date" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label>To Date</label>
                                        <input type="date" name="to_date" class="form-control" required>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-success w-100 mt-2">Generate Ledger</button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-warning text-white">
                            <h5><i class="fas fa-money-bill"></i> Cash/Bank Book</h5>
                        </div>
                        <div class="card-body">
                            <form target="_blank" action="cash_book.php" method="GET">
                                <div class="mb-3">
                                    <label>Book Type</label>
                                    <select name="book_type" class="form-control" required>
                                        <option value="cash">Cash Book</option>
                                        <option value="bank">Bank Book</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Date</label>
                                    <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <button type="submit" class="btn btn-warning w-100">Generate Report</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#accountsTable, #vouchersTable').DataTable({
                order: [[0, 'asc']],
                pageLength: 25
            });
            
            // Add row to voucher table
            $('#addRow').click(function() {
                let newRow = `
                    <tr>
                        <td>
                            <select name="account_id[]" class="form-control account-select" required>
                                <option value="">-- Select Account --</option>
                                <?php foreach($accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>">
                                        <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name']; ?> (<?php echo ucfirst($acc['account_type']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="number" name="debit[]" class="form-control debit" step="0.01" value="0"></td>
                        <td><input type="number" name="credit[]" class="form-control credit" step="0.01" value="0"></td>
                        <td><button type="button" class="btn btn-danger btn-sm remove-row">×</button></td>
                    </tr>
                `;
                $('#voucherTable tbody').append(newRow);
            });
            
            // Remove row
            $(document).on('click', '.remove-row', function() {
                if($('#voucherTable tbody tr').length > 1) {
                    $(this).closest('tr').remove();
                    calculateTotals();
                }
            });
            
            // Calculate totals
            $(document).on('input', '.debit, .credit', function() {
                calculateTotals();
            });
            
            function calculateTotals() {
                let totalDebit = 0;
                let totalCredit = 0;
                
                $('.debit').each(function() {
                    totalDebit += parseFloat($(this).val()) || 0;
                });
                
                $('.credit').each(function() {
                    totalCredit += parseFloat($(this).val()) || 0;
                });
                
                $('#totalDebit').text(totalDebit.toFixed(2));
                $('#totalCredit').text(totalCredit.toFixed(2));
                
                if(Math.abs(totalDebit - totalCredit) > 0.01) {
                    $('#voucherForm button[type="submit"]').prop('disabled', true);
                    $('#totalDebit, #totalCredit').css('color', 'red');
                } else {
                    $('#voucherForm button[type="submit"]').prop('disabled', false);
                    $('#totalDebit, #totalCredit').css('color', 'green');
                }
            }
        });
    </script>
</body>
</html>
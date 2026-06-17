<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'payments';

// Get Chart of Accounts
$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1000' OR account_name LIKE '%Cash%' LIMIT 1");
$cash_account = $stmt->fetch();

$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1100' OR account_name LIKE '%Bank%' LIMIT 1");
$bank_account = $stmt->fetch();

$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '5110' OR account_name LIKE '%Salary Expense%' LIMIT 1");
$salary_expense = $stmt->fetch();

if(!$salary_expense) {
    $stmt = $pdo->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, balance_type, is_active) VALUES ('5110', 'Salary Expense', 'expense', 'debit', 1)");
    $stmt->execute();
    $salary_expense_id = $pdo->lastInsertId();
} else {
    $salary_expense_id = $salary_expense['id'];
}

$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '5120' OR account_name LIKE '%Bonus Expense%' LIMIT 1");
$bonus_expense = $stmt->fetch();

if(!$bonus_expense) {
    $stmt = $pdo->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, balance_type, is_active) VALUES ('5120', 'Bonus Expense', 'expense', 'debit', 1)");
    $stmt->execute();
    $bonus_expense_id = $pdo->lastInsertId();
} else {
    $bonus_expense_id = $bonus_expense['id'];
}

$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '5130' OR account_name LIKE '%Employee Advance%' LIMIT 1");
$employee_advance = $stmt->fetch();

if(!$employee_advance) {
    $stmt = $pdo->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, balance_type, is_active) VALUES ('5130', 'Employee Advance', 'asset', 'debit', 1)");
    $stmt->execute();
    $employee_advance_id = $pdo->lastInsertId();
} else {
    $employee_advance_id = $employee_advance['id'];
}

// Process Employee Payment
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_employee_payment'])) {
    $employee_id = $_POST['employee_id'];
    $payment_date = $_POST['payment_date'];
    $payment_type = $_POST['payment_type'];
    $amount = floatval($_POST['amount']);
    $payment_method = $_POST['payment_method'];
    $reference_no = $_POST['reference_no'];
    $notes = $_POST['notes'];
    
    try {
        $pdo->beginTransaction();
        
        // Get employee details
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->execute([$employee_id]);
        $employee = $stmt->fetch();
        
        if(!$employee) {
            throw new Exception("Employee not found!");
        }
        
        // Insert payment
        $stmt = $pdo->prepare("INSERT INTO employee_payments (employee_id, payment_date, payment_type, amount, payment_method, reference_no, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$employee_id, $payment_date, $payment_type, $amount, $payment_method, $reference_no, $notes, $user['id']]);
        $payment_id = $pdo->lastInsertId();
        
        // Create accounting entry
        $voucher_no = 'EMP-PAY-' . date('YmdHis') . rand(100, 999);
        $narration = "{$payment_type} payment to {$employee['full_name']} - Amount: BDT " . number_format($amount, 2);
        
        $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'payment', ?, ?, ?, 'approved')");
        $stmt->execute([$voucher_no, $payment_date, $narration, $user['id']]);
        $voucher_id = $pdo->lastInsertId();
        
        // Update employee_payments with voucher_id
        $stmt = $pdo->prepare("UPDATE employee_payments SET voucher_id = ? WHERE id = ?");
        $stmt->execute([$voucher_id, $payment_id]);
        
        // Determine expense account based on payment type
        if($payment_type == 'salary') {
            $expense_account_id = $salary_expense_id;
        } elseif($payment_type == 'bonus') {
            $expense_account_id = $bonus_expense_id;
        } elseif($payment_type == 'advance') {
            $expense_account_id = $employee_advance_id;
        } else {
            // Others - use salary expense as default
            $expense_account_id = $salary_expense_id;
        }
        
        // Determine asset account based on payment method
        if($payment_method == 'cash') {
            $asset_account_id = $cash_account['id'];
        } else {
            $asset_account_id = $bank_account['id'];
        }
        
        // Dr. Expense/Advance, Cr. Cash/Bank
        $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
            (?, ?, ?, ?, ?),
            (?, ?, ?, ?, ?)");
        $stmt->execute([
            $voucher_id, $expense_account_id, $amount, 0, "{$payment_type} payment to {$employee['full_name']}",
            $voucher_id, $asset_account_id, 0, $amount, "Cash paid for {$payment_type}"
        ]);
        
        $pdo->commit();
        $success = "✅ {$payment_type} payment recorded for {$employee['full_name']}!<br>
                    <strong>Amount:</strong> BDT " . number_format($amount, 2) . "<br>
                    <strong>Voucher:</strong> $voucher_no";
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Get employees
$employees = $pdo->query("SELECT * FROM employees WHERE is_active = 1 ORDER BY full_name")->fetchAll();

// Get payment history
$payments = $pdo->query("
    SELECT ep.*, e.full_name, e.employee_id 
    FROM employee_payments ep
    JOIN employees e ON ep.employee_id = e.id
    ORDER BY ep.payment_date DESC
    LIMIT 100
")->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';

// Calculate statistics
$total_salary = array_sum(array_filter(array_column($payments, 'amount'), function($k) use ($payments) {
    static $i = 0;
    return $payments[$i++]['payment_type'] == 'salary';
}));
$total_bonus = array_sum(array_filter(array_column($payments, 'amount'), function($k) use ($payments) {
    static $i = 0;
    return $payments[$i++]['payment_type'] == 'bonus';
}));
$total_advance = array_sum(array_filter(array_column($payments, 'amount'), function($k) use ($payments) {
    static $i = 0;
    return $payments[$i++]['payment_type'] == 'advance';
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Payment Management</title>
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
        .badge-salary { background: #007bff; color: white; }
        .badge-bonus { background: #28a745; color: white; }
        .badge-advance { background: #ffc107; color: #856404; }
        .badge-others { background: #6c757d; color: white; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-user-tie"></i> Employee Payment Management</h2>
                <a href="payroll.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Payroll
                </a>
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
            
            <!-- Statistics -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-users"></i>
                        <h3><?php echo count($employees); ?></h3>
                        <p>Total Employees</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_salary, 2); ?></h3>
                        <p>Total Salary Paid</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);">
                        <i class="fas fa-gift"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_bonus, 2); ?></h3>
                        <p>Total Bonus Paid</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);">
                        <i class="fas fa-hand-holding-usd"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_advance, 2); ?></h3>
                        <p>Total Advance</p>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5><i class="fas fa-plus-circle"></i> Record Employee Payment</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label>Employee *</label>
                                    <select name="employee_id" class="form-control" required>
                                        <option value="">-- Select Employee --</option>
                                        <?php foreach($employees as $emp): ?>
                                            <option value="<?php echo $emp['id']; ?>">
                                                <?php echo $emp['employee_id']; ?> - <?php echo $emp['full_name']; ?> (<?php echo $emp['designation']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Payment Date *</label>
                                    <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label>Payment Type *</label>
                                    <select name="payment_type" class="form-control" required>
                                        <option value="salary">Salary</option>
                                        <option value="bonus">Bonus</option>
                                        <option value="advance">Advance</option>
                                        <option value="others">Others</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Amount (<?php echo $currency; ?>) *</label>
                                    <input type="number" name="amount" class="form-control" step="0.01" required>
                                </div>
                                <div class="mb-3">
                                    <label>Payment Method</label>
                                    <select name="payment_method" class="form-control">
                                        <option value="cash">Cash</option>
                                        <option value="bank">Bank Transfer</option>
                                        <option value="cheque">Cheque</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Reference No</label>
                                    <input type="text" name="reference_no" class="form-control" placeholder="Cheque/Transaction ID">
                                </div>
                                <div class="mb-3">
                                    <label>Notes</label>
                                    <textarea name="notes" class="form-control" rows="2"></textarea>
                                </div>
                                <button type="submit" name="save_employee_payment" class="btn btn-primary w-100">
                                    <i class="fas fa-save"></i> Record Payment
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="fas fa-history"></i> Payment History</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="paymentsTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Date</th>
                                            <th>Employee</th>
                                            <th>Type</th>
                                            <th class="text-end">Amount</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                            <th>Voucher</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($payments as $payment): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></td>
                                            <td><?php echo $payment['full_name']; ?><br><small><?php echo $payment['employee_id']; ?></small></td>
                                            <td>
                                                <span class="badge badge-<?php echo $payment['payment_type']; ?>">
                                                    <?php echo ucfirst($payment['payment_type']); ?>
                                                </span>
                                            </td>
                                            <td class="text-end text-danger"><?php echo $currency; ?> <?php echo number_format($payment['amount'], 2); ?></td>
                                            <td><?php echo ucfirst($payment['payment_method']); ?></td>
                                            <td><?php echo $payment['reference_no'] ?: '-'; ?></td>
                                            <td>
                                                <?php if($payment['voucher_id']): ?>
                                                    <a href="view_voucher.php?id=<?php echo $payment['voucher_id']; ?>" target="_blank" class="btn btn-sm btn-info">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="3" class="text-end">TOTAL:</td>
                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($payments, 'amount')), 2); ?></td>
                                            <td colspan="3"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#paymentsTable').DataTable({
                order: [[0, 'desc']],
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
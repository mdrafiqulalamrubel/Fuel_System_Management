<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

$month_year = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// Get employees with payroll for the month
$stmt = $pdo->prepare("
    SELECT p.*, e.full_name, e.employee_id, e.designation, e.basic_salary
    FROM payroll p
    JOIN employees e ON p.employee_id = e.id
    WHERE p.month_year = ? AND p.status = 'pending'
");
$stmt->execute([$month_year]);
$pending_payroll = $stmt->fetchAll();

// Get processed payroll
$stmt = $pdo->prepare("
    SELECT p.*, e.full_name, e.employee_id, e.designation, e.basic_salary
    FROM payroll p
    JOIN employees e ON p.employee_id = e.id
    WHERE p.month_year = ? AND p.status = 'paid'
");
$stmt->execute([$month_year]);
$paid_payroll = $stmt->fetchAll();

// Process salary payment voucher
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_voucher'])) {
    $payroll_ids = $_POST['payroll_id'];
    $payment_date = $_POST['payment_date'];
    $payment_method = $_POST['payment_method'];
    
    try {
        $pdo->beginTransaction();
        
        $total_amount = 0;
        $employee_names = [];
        
        // Process each selected payroll
        foreach($payroll_ids as $payroll_id) {
            $stmt = $pdo->prepare("SELECT * FROM payroll p JOIN employees e ON p.employee_id = e.id WHERE p.id = ?");
            $stmt->execute([$payroll_id]);
            $payroll = $stmt->fetch();
            
            if($payroll && $payroll['status'] == 'pending') {
                $total_amount += $payroll['net_salary'];
                $employee_names[] = $payroll['full_name'];
                
                // Update payroll status
                $stmt = $pdo->prepare("UPDATE payroll SET status = 'paid', payment_date = ? WHERE id = ?");
                $stmt->execute([$payment_date, $payroll_id]);
            }
        }
        
        if($total_amount > 0 && !empty($employee_names)) {
            // Get account IDs
            $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '5110' OR account_name LIKE '%Salary Expense%' LIMIT 1");
            $salary_expense = $stmt->fetch();
            
            $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1000' OR account_name LIKE '%Cash%' LIMIT 1");
            $cash_account = $stmt->fetch();
            
            $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1100' OR account_name LIKE '%Bank%' LIMIT 1");
            $bank_account = $stmt->fetch();
            
            // Determine asset account
            $asset_account = ($payment_method == 'cash') ? $cash_account : $bank_account;
            
            // Create voucher
            $voucher_no = 'SAL-' . date('YmdHis') . rand(100, 999);
            $narration = "Salary payment for " . date('F Y', strtotime($month_year . '-01')) . " - Employees: " . implode(', ', $employee_names);
            
            $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'payment', ?, ?, ?, 'approved')");
            $stmt->execute([$voucher_no, $payment_date, $narration, $user['id']]);
            $voucher_id = $pdo->lastInsertId();
            
            // Accounting entry
            $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                (?, ?, ?, ?, ?),
                (?, ?, ?, ?, ?)");
            $stmt->execute([
                $voucher_id, $salary_expense['id'], $total_amount, 0, "Salary expense - " . date('F Y', strtotime($month_year . '-01')),
                $voucher_id, $asset_account['id'], 0, $total_amount, "Salary payment - " . date('F Y', strtotime($month_year . '-01'))
            ]);
            
            $pdo->commit();
            
            $currency = $settings['currency_symbol'] ?? 'BDT';
            $success = "✅ Salary voucher generated successfully!<br>
                        <strong>Voucher No:</strong> $voucher_no<br>
                        <strong>Total Salary:</strong> " . $currency . " " . number_format($total_amount, 2) . "<br>
                        <strong>Employees:</strong> " . count($employee_names) . " employees<br>
                        <a href='view_voucher.php?id=$voucher_id' target='_blank' class='btn btn-sm btn-info'>View Voucher</a>
                        <a href='print_voucher.php?id=$voucher_id' target='_blank' class='btn btn-sm btn-primary'>Print Voucher</a>";
            
        } else {
            throw new Exception("No pending payroll found or all already paid!");
        }
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
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
    <title>Salary Voucher Generation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
        .section-card {
            border-left: 4px solid #28a745;
            padding: 15px;
            margin-bottom: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .section-card.pending {
            border-left-color: #ffc107;
        }
        .section-card.paid {
            border-left-color: #28a745;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-file-invoice"></i> Salary Voucher Generation</h2>
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
            
            <!-- Filter -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-calendar-alt"></i> Select Month</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label>Month & Year</label>
                            <input type="month" name="month" class="form-control" value="<?php echo $month_year; ?>">
                        </div>
                        <div class="col-md-2">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-info w-100">
                                <i class="fas fa-search"></i> View
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="row">
                <div class="col-md-4">
                    <div class="stats-card">
                        <i class="fas fa-clock"></i>
                        <h3><?php echo count($pending_payroll); ?></h3>
                        <p>Pending Payroll</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-check-circle"></i>
                        <h3><?php echo count($paid_payroll); ?></h3>
                        <p>Paid Payroll</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php 
                            $total_pending = array_sum(array_column($pending_payroll, 'net_salary'));
                            echo number_format($total_pending, 2);
                        ?></h3>
                        <p>Total Pending Salary</p>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-warning text-dark">
                            <h5><i class="fas fa-clock"></i> Pending Payroll - <?php echo date('F Y', strtotime($month_year . '-01')); ?></h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <?php if(empty($pending_payroll)): ?>
                                    <div class="alert alert-success text-center">
                                        <i class="fas fa-check-circle"></i> No pending payroll for this month!
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th><input type="checkbox" id="selectAll"></th>
                                                    <th>Employee</th>
                                                    <th class="text-end">Basic</th>
                                                    <th class="text-end">Allowances</th>
                                                    <th class="text-end">OT</th>
                                                    <th class="text-end">Deductions</th>
                                                    <th class="text-end">Net</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($pending_payroll as $p): ?>
                                                <tr>
                                                    <td><input type="checkbox" name="payroll_id[]" value="<?php echo $p['id']; ?>" checked></td>
                                                    <td>
                                                        <strong><?php echo $p['full_name']; ?></strong>
                                                        <br><small><?php echo $p['employee_id']; ?></small>
                                                    </td>
                                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($p['basic_salary'], 2); ?></td>
                                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($p['allowances'], 2); ?></td>
                                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($p['overtime_amount'], 2); ?></td>
                                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($p['deductions'], 2); ?></td>
                                                    <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format($p['net_salary'], 2); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot class="table-light">
                                                <tr class="fw-bold">
                                                    <td colspan="6" class="text-end">TOTAL:</td>
                                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($pending_payroll, 'net_salary')), 2); ?></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    
                                    <div class="row mt-3">
                                        <div class="col-md-6">
                                            <label>Payment Date</label>
                                            <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label>Payment Method</label>
                                            <select name="payment_method" class="form-control">
                                                <option value="cash">Cash</option>
                                                <option value="bank">Bank Transfer</option>
                                                <option value="cheque">Cheque</option>
                                            </select>
                                        </div>
                                    </div>
                                    <button type="submit" name="generate_voucher" class="btn btn-success w-100 mt-3">
                                        <i class="fas fa-file-invoice"></i> Generate Salary Voucher
                                    </button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="fas fa-check-circle"></i> Paid Payroll - <?php echo date('F Y', strtotime($month_year . '-01')); ?></h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($paid_payroll)): ?>
                                <div class="alert alert-info text-center">
                                    <i class="fas fa-info-circle"></i> No paid payroll for this month yet.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Employee</th>
                                                <th class="text-end">Net Salary</th>
                                                <th>Payment Date</th>
                                                <th>Voucher</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($paid_payroll as $p): 
                                                // Find voucher for this payroll
                                                $stmt = $pdo->prepare("SELECT voucher_id FROM employee_payments WHERE employee_id = ? AND payment_date = ? LIMIT 1");
                                                $stmt->execute([$p['employee_id'], $p['payment_date']]);
                                                $voucher = $stmt->fetch();
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo $p['full_name']; ?></strong>
                                                    <br><small><?php echo $p['employee_id']; ?></small>
                                                </td>
                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($p['net_salary'], 2); ?></td>
                                                <td><?php echo date('d-m-Y', strtotime($p['payment_date'])); ?></td>
                                                <td>
                                                    <?php if($voucher): ?>
                                                        <a href="view_voucher.php?id=<?php echo $voucher['voucher_id']; ?>" target="_blank" class="btn btn-sm btn-info">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr class="fw-bold">
                                                <td class="text-end">TOTAL:</td>
                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($paid_payroll, 'net_salary')), 2); ?></td>
                                                <td colspan="2"></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mt-3">
                <div class="card-header bg-dark text-white">
                    <h5><i class="fas fa-info-circle"></i> How Salary Voucher Works</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="text-center">
                                <div class="display-6">1️⃣</div>
                                <h6>Select Employees</h6>
                                <p class="text-muted">Check the employees whose salary you want to process</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center">
                                <div class="display-6">2️⃣</div>
                                <h6>Generate Voucher</h6>
                                <p class="text-muted">System creates accounting entry: Dr. Salary Expense, Cr. Cash/Bank</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center">
                                <div class="display-6">3️⃣</div>
                                <h6>View & Print</h6>
                                <p class="text-muted">View the voucher and print for records</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Select all checkboxes
        document.getElementById('selectAll').addEventListener('change', function() {
            let checkboxes = document.querySelectorAll('input[name="payroll_id[]"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = document.getElementById('selectAll').checked;
            });
        });
    </script>
</body>
</html>
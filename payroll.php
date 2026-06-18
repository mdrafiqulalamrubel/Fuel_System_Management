<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'employees';

// Get Chart of Accounts for accounting integration
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

// Add Employee
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_employee'])) {
    $employee_id = $_POST['employee_id'];
    $full_name = $_POST['full_name'];
    $designation = $_POST['designation'];
    $department = $_POST['department'];
    $joining_date = $_POST['joining_date'];
    $basic_salary = $_POST['basic_salary'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];
    $bank_account_no = $_POST['bank_account_no'];
    
    $stmt = $pdo->prepare("INSERT INTO employees (employee_id, full_name, designation, department, joining_date, basic_salary, phone, address, bank_account_no, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
    if($stmt->execute([$employee_id, $full_name, $designation, $department, $joining_date, $basic_salary, $phone, $address, $bank_account_no])) {
        $success = "Employee added successfully!";
    } else {
        $error = "Failed to add employee";
    }
}

// Update Employee
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_employee'])) {
    $id = $_POST['employee_id'];
    $employee_id = $_POST['emp_id'];
    $full_name = $_POST['full_name'];
    $designation = $_POST['designation'];
    $department = $_POST['department'];
    $joining_date = $_POST['joining_date'];
    $basic_salary = $_POST['basic_salary'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];
    $bank_account_no = $_POST['bank_account_no'];
    
    $stmt = $pdo->prepare("UPDATE employees SET employee_id = ?, full_name = ?, designation = ?, department = ?, joining_date = ?, basic_salary = ?, phone = ?, address = ?, bank_account_no = ? WHERE id = ?");
    if($stmt->execute([$employee_id, $full_name, $designation, $department, $joining_date, $basic_salary, $phone, $address, $bank_account_no, $id])) {
        $success = "Employee updated successfully!";
    } else {
        $error = "Failed to update employee";
    }
}

// Delete Employee
if(isset($_GET['delete_id'])) {
    $stmt = $pdo->prepare("UPDATE employees SET is_active = 0 WHERE id = ?");
    if($stmt->execute([$_GET['delete_id']])) {
        $success = "Employee deactivated successfully!";
    }
}

// Record Attendance
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_attendance'])) {
    $employee_id = $_POST['employee_id'];
    $attendance_date = $_POST['attendance_date'];
    $check_in_time = $_POST['check_in_time'];
    $check_out_time = $_POST['check_out_time'];
    $status = $_POST['status'];
    $overtime_hours = $_POST['overtime_hours'];
    
    $stmt = $pdo->prepare("SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ?");
    $stmt->execute([$employee_id, $attendance_date]);
    if($stmt->fetch()) {
        $stmt = $pdo->prepare("UPDATE attendance SET check_in_time = ?, check_out_time = ?, status = ?, overtime_hours = ? WHERE employee_id = ? AND attendance_date = ?");
        if($stmt->execute([$check_in_time, $check_out_time, $status, $overtime_hours, $employee_id, $attendance_date])) {
            $success = "Attendance updated successfully!";
        }
    } else {
        $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, check_in_time, check_out_time, status, overtime_hours) VALUES (?, ?, ?, ?, ?, ?)");
        if($stmt->execute([$employee_id, $attendance_date, $check_in_time, $check_out_time, $status, $overtime_hours])) {
            $success = "Attendance recorded successfully!";
        }
    }
}

// Generate Payroll with Bonus
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_payroll'])) {
    $month_year = $_POST['month_year'];
    $employee_id = $_POST['employee_id'];
    $bonus_amount = isset($_POST['bonus_amount']) ? floatval($_POST['bonus_amount']) : 0;
    $allowance_override = isset($_POST['allowance_override']) ? floatval($_POST['allowance_override']) : 0;
    $deduction_override = isset($_POST['deduction_override']) ? floatval($_POST['deduction_override']) : 0;
    
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch();
    
    if(!$employee) {
        $error = "Employee not found!";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM payroll WHERE employee_id = ? AND month_year = ?");
        $stmt->execute([$employee_id, $month_year]);
        if($stmt->fetch()) {
            $error = "Payroll for this month already generated!";
        } else {
            $stmt = $pdo->prepare("SELECT SUM(overtime_hours) as total_overtime FROM attendance WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?");
            $stmt->execute([$employee_id, $month_year]);
            $total_overtime = $stmt->fetch()['total_overtime'] ?? 0;
            
            $overtime_rate = ($employee['basic_salary'] / 30 / 8) * 1.5;
            $overtime_amount = $total_overtime * $overtime_rate;
            
            $allowances = $allowance_override > 0 ? $allowance_override : ($employee['basic_salary'] * 0.2);
            $deductions = $deduction_override > 0 ? $deduction_override : ($employee['basic_salary'] * 0.1);
            $net_salary = $employee['basic_salary'] + $allowances + $overtime_amount + $bonus_amount - $deductions;
            
            $stmt = $pdo->prepare("INSERT INTO payroll (employee_id, month_year, basic_salary, allowances, overtime_amount, bonus, deductions, net_salary, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
            if($stmt->execute([$employee_id, $month_year, $employee['basic_salary'], $allowances, $overtime_amount, $bonus_amount, $deductions, $net_salary])) {
                $success = "Payroll generated successfully! Bonus: BDT " . number_format($bonus_amount, 2);
            } else {
                $error = "Failed to generate payroll";
            }
        }
    }
}

// Process Payroll Payment with Accounting Integration
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['pay_salary'])) {
    $payroll_id = $_POST['payroll_id'];
    $payment_date = $_POST['payment_date'];
    $payment_method = $_POST['payment_method'] ?? 'cash';
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT p.*, e.full_name, e.employee_id FROM payroll p JOIN employees e ON p.employee_id = e.id WHERE p.id = ?");
        $stmt->execute([$payroll_id]);
        $payroll = $stmt->fetch();
        
        if(!$payroll) {
            throw new Exception("Payroll not found!");
        }
        
        // Update payroll status
        $stmt = $pdo->prepare("UPDATE payroll SET status = 'paid', payment_date = ? WHERE id = ?");
        $stmt->execute([$payment_date, $payroll_id]);
        
        // Get account IDs
        $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1000' OR account_name LIKE '%Cash%' LIMIT 1");
        $cash_account = $stmt->fetch();
        
        $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1100' OR account_name LIKE '%Bank%' LIMIT 1");
        $bank_account = $stmt->fetch();
        
        $asset_account = ($payment_method == 'cash') ? $cash_account : $bank_account;
        
        // Create voucher
        $voucher_no = 'SAL-' . date('YmdHis') . rand(100, 999);
        $narration = "Salary payment to {$payroll['full_name']} ({$payroll['employee_id']}) - Month: " . date('F Y', strtotime($payroll['month_year'] . '-01'));
        
        $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'payment', ?, ?, ?, 'approved')");
        $stmt->execute([$voucher_no, $payment_date, $narration, $user['id']]);
        $voucher_id = $pdo->lastInsertId();
        
        // Accounting entry: Dr. Salary Expense, Cr. Cash/Bank
        $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
            (?, ?, ?, ?, ?),
            (?, ?, ?, ?, ?)");
        $stmt->execute([
            $voucher_id, $salary_expense_id, $payroll['net_salary'], 0, "Salary - {$payroll['full_name']}",
            $voucher_id, $asset_account['id'], 0, $payroll['net_salary'], "Salary payment - {$payroll['full_name']}"
        ]);
        
        // Record in employee_payments for tracking
        $stmt = $pdo->prepare("INSERT INTO employee_payments (employee_id, payment_date, payment_type, amount, payment_method, voucher_id, created_by) VALUES (?, ?, 'salary', ?, ?, ?, ?)");
        $stmt->execute([$payroll['employee_id'], $payment_date, $payroll['net_salary'], $payment_method, $voucher_id, $user['id']]);
        
        $pdo->commit();
        
        $success = "✅ Salary paid to {$payroll['full_name']}!<br>
                    <strong>Amount:</strong> BDT " . number_format($payroll['net_salary'], 2) . "<br>
                    <strong>Voucher:</strong> $voucher_no";
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Process Bonus Payment
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['pay_bonus'])) {
    $employee_id = $_POST['employee_id'];
    $payment_date = $_POST['payment_date'];
    $amount = floatval($_POST['amount']);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $notes = $_POST['notes'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->execute([$employee_id]);
        $employee = $stmt->fetch();
        
        if(!$employee) {
            throw new Exception("Employee not found!");
        }
        
        // Get account IDs
        $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1000' OR account_name LIKE '%Cash%' LIMIT 1");
        $cash_account = $stmt->fetch();
        
        $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1100' OR account_name LIKE '%Bank%' LIMIT 1");
        $bank_account = $stmt->fetch();
        
        $asset_account = ($payment_method == 'cash') ? $cash_account : $bank_account;
        
        // Create voucher
        $voucher_no = 'BONUS-' . date('YmdHis') . rand(100, 999);
        $narration = "Bonus payment to {$employee['full_name']} - Amount: BDT " . number_format($amount, 2);
        
        $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'payment', ?, ?, ?, 'approved')");
        $stmt->execute([$voucher_no, $payment_date, $narration, $user['id']]);
        $voucher_id = $pdo->lastInsertId();
        
        // Accounting entry: Dr. Bonus Expense, Cr. Cash/Bank
        $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
            (?, ?, ?, ?, ?),
            (?, ?, ?, ?, ?)");
        $stmt->execute([
            $voucher_id, $bonus_expense_id, $amount, 0, "Bonus - {$employee['full_name']}",
            $voucher_id, $asset_account['id'], 0, $amount, "Bonus payment - {$employee['full_name']}"
        ]);
        
        // Record in employee_payments
        $stmt = $pdo->prepare("INSERT INTO employee_payments (employee_id, payment_date, payment_type, amount, payment_method, voucher_id, notes, created_by) VALUES (?, ?, 'bonus', ?, ?, ?, ?, ?)");
        $stmt->execute([$employee_id, $payment_date, $amount, $payment_method, $voucher_id, $notes, $user['id']]);
        
        $pdo->commit();
        
        $success = "✅ Bonus paid to {$employee['full_name']}!<br>
                    <strong>Amount:</strong> BDT " . number_format($amount, 2) . "<br>
                    <strong>Voucher:</strong> $voucher_no";
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Get data
$employees = $pdo->query("SELECT * FROM employees WHERE is_active = 1 ORDER BY full_name")->fetchAll();
$attendance = $pdo->query("
    SELECT a.*, e.full_name, e.designation 
    FROM attendance a 
    JOIN employees e ON a.employee_id = e.id 
    ORDER BY a.attendance_date DESC 
    LIMIT 200
")->fetchAll();

$payroll = $pdo->query("
    SELECT p.*, e.full_name, e.designation, e.employee_id 
    FROM payroll p 
    JOIN employees e ON p.employee_id = e.id 
    ORDER BY p.month_year DESC, p.id DESC
")->fetchAll();

// Get bonus payments
$bonus_payments = $pdo->query("
    SELECT ep.*, e.full_name, e.employee_id 
    FROM employee_payments ep
    JOIN employees e ON ep.employee_id = e.id
    WHERE ep.payment_type = 'bonus'
    ORDER BY ep.payment_date DESC
    LIMIT 50
")->fetchAll();

// Calculate statistics
$total_employees = count($employees);
$total_salary = array_sum(array_column($payroll, 'net_salary'));
$pending_payroll = count(array_filter($payroll, fn($p) => $p['status'] == 'pending'));
$total_attendance = count($attendance);
$total_bonus = array_sum(array_column($bonus_payments, 'amount'));

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR & Payroll Management</title>
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
        
        .nav-tabs {
            border-bottom: 2px solid #dee2e6;
            margin-bottom: 20px;
        }
        .nav-tabs .nav-link {
            color: #495057 !important;
            background: #e9ecef !important;
            border-radius: 8px 8px 0 0;
            margin-right: 5px;
            font-weight: 500;
            padding: 10px 20px;
            border: none;
            transition: all 0.3s ease;
        }
        .nav-tabs .nav-link:hover {
            background: #dee2e6 !important;
            color: #000 !important;
        }
        .nav-tabs .nav-link.active {
            color: white !important;
            font-weight: 600;
        }
        .nav-tabs .nav-link.active i { color: white !important; }
        .nav-tabs .nav-link i { margin-right: 8px; }
        .nav-tabs .nav-link:nth-child(1).active { background: #007bff !important; }
        .nav-tabs .nav-link:nth-child(2).active { background: #17a2b8 !important; }
        .nav-tabs .nav-link:nth-child(3).active { background: #ffc107 !important; color: #333 !important; }
        .nav-tabs .nav-link:nth-child(4).active { background: #28a745 !important; }
        .nav-tabs .nav-link:nth-child(5).active { background: #fd7e14 !important; }
        
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
                <h2><i class="fas fa-users"></i> HR & Payroll Management</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                    <i class="fas fa-plus"></i> Add New Employee
                </button>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-users"></i>
                        <h3><?php echo $total_employees; ?></h3>
                        <p>Total Employees</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_salary, 2); ?></h3>
                        <p>Total Salary Paid</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-gift"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_bonus, 2); ?></h3>
                        <p>Total Bonus Paid</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-clock"></i>
                        <h3><?php echo $pending_payroll; ?></h3>
                        <p>Pending Payments</p>
                    </div>
                </div>
            </div>
            
            <!-- Tabs -->
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item"><a class="nav-link <?php echo $active_tab == 'employees' ? 'active' : ''; ?>" href="?tab=employees"><i class="fas fa-users"></i> Employees</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $active_tab == 'attendance' ? 'active' : ''; ?>" href="?tab=attendance"><i class="fas fa-calendar-alt"></i> Attendance</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $active_tab == 'payroll' ? 'active' : ''; ?>" href="?tab=payroll"><i class="fas fa-file-invoice"></i> Payroll</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $active_tab == 'bonus' ? 'active' : ''; ?>" href="?tab=bonus"><i class="fas fa-gift"></i> Bonus</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $active_tab == 'salary_sheet' ? 'active' : ''; ?>" href="?tab=salary_sheet"><i class="fas fa-chart-line"></i> Salary Sheet</a></li>
            </ul>
            
            <!-- Employees Tab -->
            <?php if($active_tab == 'employees'): ?>
            <div class="card">
                <div class="card-header bg-primary text-white"><h5><i class="fas fa-list"></i> Employee List</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="employeesTable">
                            <thead class="table-dark">
                                <tr><th>ID</th><th>Employee Code</th><th>Full Name</th><th>Designation</th><th>Department</th><th>Basic Salary</th><th>Phone</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($employees as $emp): ?>
                                <tr>
                                    <td><?php echo $emp['id']; ?></td>
                                    <td><?php echo $emp['employee_id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($emp['full_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($emp['designation']); ?></td>
                                    <td><?php echo htmlspecialchars($emp['department']); ?></td>
                                    <td><?php echo $currency; ?> <?php echo number_format($emp['basic_salary'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($emp['phone']); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-warning" onclick="editEmployee(<?php echo $emp['id']; ?>, '<?php echo addslashes($emp['employee_id']); ?>', '<?php echo addslashes($emp['full_name']); ?>', '<?php echo addslashes($emp['designation']); ?>', '<?php echo addslashes($emp['department']); ?>', '<?php echo $emp['joining_date']; ?>', <?php echo $emp['basic_salary']; ?>, '<?php echo addslashes($emp['phone']); ?>', '<?php echo addslashes($emp['address']); ?>', '<?php echo addslashes($emp['bank_account_no']); ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="?delete_id=<?php echo $emp['id']; ?>&tab=employees" class="btn btn-sm btn-danger" onclick="return confirm('Deactivate this employee?')"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Attendance Tab -->
            <?php if($active_tab == 'attendance'): ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-info text-white"><h5><i class="fas fa-clock"></i> Mark Attendance</h5></div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label>Select Employee</label>
                                    <select name="employee_id" class="form-control" required>
                                        <option value="">-- Select Employee --</option>
                                        <?php foreach($employees as $emp): ?>
                                            <option value="<?php echo $emp['id']; ?>"><?php echo $emp['employee_id']; ?> - <?php echo $emp['full_name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Date</label>
                                    <input type="date" name="attendance_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="row">
                                    <div class="col-md-6"><label>Check In</label><input type="time" name="check_in_time" class="form-control" value="09:00"></div>
                                    <div class="col-md-6"><label>Check Out</label><input type="time" name="check_out_time" class="form-control" value="17:00"></div>
                                </div>
                                <div class="mt-3">
                                    <label>Status</label>
                                    <select name="status" class="form-control" required>
                                        <option value="present">Present</option>
                                        <option value="absent">Absent</option>
                                        <option value="late">Late</option>
                                        <option value="half_day">Half Day</option>
                                    </select>
                                </div>
                                <div class="mt-3">
                                    <label>Overtime Hours</label>
                                    <input type="number" name="overtime_hours" class="form-control" step="0.5" value="0">
                                </div>
                                <button type="submit" name="save_attendance" class="btn btn-info w-100 mt-3"><i class="fas fa-save"></i> Save Attendance</button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-success text-white"><h5><i class="fas fa-history"></i> Attendance History</h5></div>
                        <div class="card-body">
                            <table class="table table-bordered" id="attendanceTable">
                                <thead class="table-dark">
                                    <tr><th>Date</th><th>Employee</th><th>Designation</th><th>Check In</th><th>Check Out</th><th>Status</th><th>OT Hours</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach($attendance as $att): ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($att['attendance_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($att['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($att['designation']); ?></td>
                                        <td><?php echo $att['check_in_time']; ?></td>
                                        <td><?php echo $att['check_out_time']; ?></td>
                                        <td><span class="badge bg-<?php echo $att['status'] == 'present' ? 'success' : ($att['status'] == 'absent' ? 'danger' : 'warning'); ?>"><?php echo ucfirst($att['status']); ?></span></td>
                                        <td><?php echo $att['overtime_hours']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Payroll Tab -->
            <?php if($active_tab == 'payroll'): ?>
            <div class="row">
                <div class="col-md-5">
                    <div class="card">
                        <div class="card-header bg-warning text-dark"><h5><i class="fas fa-calculator"></i> Generate Payroll</h5></div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label>Select Employee</label>
                                    <select name="employee_id" class="form-control" required>
                                        <option value="">-- Select Employee --</option>
                                        <?php foreach($employees as $emp): ?>
                                            <option value="<?php echo $emp['id']; ?>"><?php echo $emp['employee_id']; ?> - <?php echo $emp['full_name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Month & Year</label>
                                    <input type="month" name="month_year" class="form-control" value="<?php echo date('Y-m'); ?>" required>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <label>Bonus (<?php echo $currency; ?>)</label>
                                        <input type="number" name="bonus_amount" class="form-control" step="0.01" value="0">
                                    </div>
                                    <div class="col-md-6">
                                        <label>Allowances (<?php echo $currency; ?>)</label>
                                        <input type="number" name="allowance_override" class="form-control" step="0.01" value="0" placeholder="Override default">
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <label>Deductions (<?php echo $currency; ?>)</label>
                                    <input type="number" name="deduction_override" class="form-control" step="0.01" value="0" placeholder="Override default">
                                </div>
                                <button type="submit" name="generate_payroll" class="btn btn-warning w-100 mt-3"><i class="fas fa-calculator"></i> Generate Payroll</button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-7">
                    <div class="card">
                        <div class="card-header bg-primary text-white"><h5><i class="fas fa-list"></i> Payroll List</h5></div>
                        <div class="card-body">
                            <table class="table table-bordered" id="payrollTable">
                                <thead class="table-dark">
                                    <tr><th>Month</th><th>Employee</th><th>Basic</th><th>Allowances</th><th>OT</th><th>Bonus</th><th>Deductions</th><th>Net</th><th>Status</th><th>Actions</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach($payroll as $p): ?>
                                    <tr>
                                        <td><?php echo date('F Y', strtotime($p['month_year'] . '-01')); ?></td>
                                        <td><?php echo htmlspecialchars($p['full_name']); ?><br><small><?php echo $p['employee_id']; ?></small></td>
                                        <td><?php echo $currency; ?> <?php echo number_format($p['basic_salary'], 2); ?></td>
                                        <td><?php echo $currency; ?> <?php echo number_format($p['allowances'], 2); ?></td>
                                        <td><?php echo $currency; ?> <?php echo number_format($p['overtime_amount'], 2); ?></td>
                                        <td><?php echo $currency; ?> <?php echo number_format($p['bonus'], 2); ?></td>
                                        <td><?php echo $currency; ?> <?php echo number_format($p['deductions'], 2); ?></td>
                                        <td class="fw-bold"><?php echo $currency; ?> <?php echo number_format($p['net_salary'], 2); ?></td>
                                        <td><?php echo $p['status'] == 'paid' ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-warning">Pending</span>'; ?></td>
                                        <td><?php if($p['status'] == 'pending'): ?><button class="btn btn-sm btn-success" onclick="paySalary(<?php echo $p['id']; ?>, '<?php echo $p['full_name']; ?>', <?php echo $p['net_salary']; ?>)"><i class="fas fa-money-bill"></i></button><?php endif; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Bonus Tab -->
            <?php if($active_tab == 'bonus'): ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-success text-white"><h5><i class="fas fa-gift"></i> Pay Bonus</h5></div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label>Select Employee</label>
                                    <select name="employee_id" class="form-control" required>
                                        <option value="">-- Select Employee --</option>
                                        <?php foreach($employees as $emp): ?>
                                            <option value="<?php echo $emp['id']; ?>"><?php echo $emp['employee_id']; ?> - <?php echo $emp['full_name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Payment Date</label>
                                    <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label>Bonus Amount (<?php echo $currency; ?>)</label>
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
                                    <label>Notes</label>
                                    <textarea name="notes" class="form-control" rows="2"></textarea>
                                </div>
                                <button type="submit" name="pay_bonus" class="btn btn-success w-100"><i class="fas fa-save"></i> Pay Bonus</button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-info text-white"><h5><i class="fas fa-history"></i> Bonus History</h5></div>
                        <div class="card-body">
                            <table class="table table-bordered" id="bonusTable">
                                <thead class="table-dark">
                                    <tr><th>Date</th><th>Employee</th><th>Amount</th><th>Method</th><th>Voucher</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach($bonus_payments as $bp): ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($bp['payment_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($bp['full_name']); ?><br><small><?php echo $bp['employee_id']; ?></small></td>
                                        <td class="text-danger fw-bold"><?php echo $currency; ?> <?php echo number_format($bp['amount'], 2); ?></td>
                                        <td><?php echo ucfirst($bp['payment_method']); ?></td>
                                        <td><?php if($bp['voucher_id']): ?><a href="view_voucher.php?id=<?php echo $bp['voucher_id']; ?>" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-eye"></i></a><?php else: ?>-<?php endif; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Salary Sheet Tab -->
            <?php if($active_tab == 'salary_sheet'): ?>
            <div class="card">
                <div class="card-header bg-success text-white"><h5><i class="fas fa-chart-line"></i> Salary Sheet Summary</h5></div>
                <div class="card-body">
                    <table class="table table-bordered" id="salarySheetTable">
                        <thead class="table-dark">
                            <tr><th>Employee Code</th><th>Employee Name</th><th>Designation</th><th>Basic</th><th>Allowances (20%)</th><th>OT Amount</th><th>Bonus</th><th>Deductions (10%)</th><th>Net Salary</th><th>Last Payment</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($employees as $emp):
                                $stmt = $pdo->prepare("SELECT * FROM payroll WHERE employee_id = ? ORDER BY month_year DESC LIMIT 1");
                                $stmt->execute([$emp['id']]);
                                $latest = $stmt->fetch();
                            ?>
                            <tr>
                                <td><?php echo $emp['employee_id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($emp['full_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($emp['designation']); ?></td>
                                <td><?php echo $currency; ?> <?php echo number_format($emp['basic_salary'], 2); ?></td>
                                <td><?php echo $currency; ?> <?php echo number_format($emp['basic_salary'] * 0.2, 2); ?></td>
                                <td><?php echo $currency; ?> <?php echo number_format($latest['overtime_amount'] ?? 0, 2); ?></td>
                                <td><?php echo $currency; ?> <?php echo number_format($latest['bonus'] ?? 0, 2); ?></td>
                                <td><?php echo $currency; ?> <?php echo number_format($emp['basic_salary'] * 0.1, 2); ?></td>
                                <td class="fw-bold"><?php echo $currency; ?> <?php echo number_format($latest['net_salary'] ?? $emp['basic_salary'] * 1.1, 2); ?></td>
                                <td><?php echo $latest && $latest['payment_date'] ? date('d-m-Y', strtotime($latest['payment_date'])) : 'Not paid yet'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="8" class="text-end">TOTAL MONTHLY SALARY:</td>
                                <td colspan="2"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($employees, 'basic_salary')) * 1.1, 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Modals (Same as existing) -->
    <!-- Add Employee Modal -->
    <div class="modal fade" id="addEmployeeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white"><h5><i class="fas fa-user-plus"></i> Add New Employee</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6"><label>Employee ID *</label><input type="text" name="employee_id" class="form-control" placeholder="EMP-001" required></div>
                            <div class="col-md-6"><label>Full Name *</label><input type="text" name="full_name" class="form-control" required></div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6"><label>Designation</label><input type="text" name="designation" class="form-control" placeholder="Manager, Cashier, etc."></div>
                            <div class="col-md-6"><label>Department</label><select name="department" class="form-control"><option value="">Select</option><option value="Management">Management</option><option value="Operations">Operations</option><option value="Sales">Sales</option><option value="Accounts">Accounts</option><option value="HR">HR</option></select></div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6"><label>Joining Date</label><input type="date" name="joining_date" class="form-control"></div>
                            <div class="col-md-6"><label>Basic Salary (<?php echo $currency; ?>) *</label><input type="number" name="basic_salary" class="form-control" step="0.01" required></div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6"><label>Phone</label><input type="text" name="phone" class="form-control"></div>
                            <div class="col-md-6"><label>Bank Account No</label><input type="text" name="bank_account_no" class="form-control"></div>
                        </div>
                        <div class="mt-2"><label>Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="save_employee" class="btn btn-primary">Save Employee</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Employee Modal -->
    <div class="modal fade" id="editEmployeeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white"><h5><i class="fas fa-edit"></i> Edit Employee</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <form method="POST">
                    <input type="hidden" name="employee_id" id="edit_id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6"><label>Employee ID *</label><input type="text" name="emp_id" id="edit_emp_id" class="form-control" required></div>
                            <div class="col-md-6"><label>Full Name *</label><input type="text" name="full_name" id="edit_full_name" class="form-control" required></div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6"><label>Designation</label><input type="text" name="designation" id="edit_designation" class="form-control"></div>
                            <div class="col-md-6"><label>Department</label><input type="text" name="department" id="edit_department" class="form-control"></div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6"><label>Joining Date</label><input type="date" name="joining_date" id="edit_joining_date" class="form-control"></div>
                            <div class="col-md-6"><label>Basic Salary (<?php echo $currency; ?>) *</label><input type="number" name="basic_salary" id="edit_basic_salary" class="form-control" step="0.01" required></div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6"><label>Phone</label><input type="text" name="phone" id="edit_phone" class="form-control"></div>
                            <div class="col-md-6"><label>Bank Account No</label><input type="text" name="bank_account_no" id="edit_bank_account" class="form-control"></div>
                        </div>
                        <div class="mt-2"><label>Address</label><textarea name="address" id="edit_address" class="form-control" rows="2"></textarea></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_employee" class="btn btn-warning">Update Employee</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Pay Salary Modal -->
    <div class="modal fade" id="paySalaryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white"><h5><i class="fas fa-money-bill"></i> Pay Salary</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <form method="POST">
                    <input type="hidden" name="payroll_id" id="payroll_id">
                    <div class="modal-body">
                        <div class="mb-3"><label>Employee</label><input type="text" id="pay_employee_name" class="form-control" readonly></div>
                        <div class="mb-3"><label>Salary Amount (<?php echo $currency; ?>)</label><input type="text" id="pay_amount" class="form-control" readonly></div>
                        <div class="mb-3"><label>Payment Date</label><input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                        <div class="mb-3"><label>Payment Method</label><select name="payment_method" class="form-control"><option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="cheque">Cheque</option></select></div>
                        <div class="alert alert-info"><i class="fas fa-info-circle"></i> This will mark salary as paid and create accounting entry (Dr. Salary Expense, Cr. Cash/Bank)</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="pay_salary" class="btn btn-success">Confirm Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#employeesTable, #attendanceTable, #payrollTable, #bonusTable, #salarySheetTable').DataTable({
                order: [[0, 'asc']],
                pageLength: 25,
                language: { search: "Search:", lengthMenu: "Show _MENU_ entries", info: "Showing _START_ to _END_ of _TOTAL_ entries" }
            });
        });
        
        function editEmployee(id, empId, name, designation, department, joiningDate, salary, phone, address, bankAccount) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_emp_id').value = empId;
            document.getElementById('edit_full_name').value = name;
            document.getElementById('edit_designation').value = designation;
            document.getElementById('edit_department').value = department;
            document.getElementById('edit_joining_date').value = joiningDate;
            document.getElementById('edit_basic_salary').value = salary;
            document.getElementById('edit_phone').value = phone;
            document.getElementById('edit_address').value = address;
            document.getElementById('edit_bank_account').value = bankAccount;
            new bootstrap.Modal(document.getElementById('editEmployeeModal')).show();
        }
        
        function paySalary(id, name, amount) {
            document.getElementById('payroll_id').value = id;
            document.getElementById('pay_employee_name').value = name;
            document.getElementById('pay_amount').value = amount.toFixed(2);
            new bootstrap.Modal(document.getElementById('paySalaryModal')).show();
        }
    </script>
</body>
</html>
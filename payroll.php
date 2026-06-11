<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'employees';

// Add/Edit Employee
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
    
    $stmt = $pdo->prepare("INSERT INTO employees (employee_id, full_name, designation, department, joining_date, basic_salary, phone, address, bank_account_no) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if($stmt->execute([$employee_id, $full_name, $designation, $department, $joining_date, $basic_salary, $phone, $address, $bank_account_no])) {
        $success = "Employee added successfully!";
    } else {
        $error = "Failed to add employee";
    }
}

// Update Employee
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_employee'])) {
    $emp_id = $_POST['emp_id'];
    $employee_id = $_POST['employee_id'];
    $full_name = $_POST['full_name'];
    $designation = $_POST['designation'];
    $department = $_POST['department'];
    $joining_date = $_POST['joining_date'];
    $basic_salary = $_POST['basic_salary'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];
    $bank_account_no = $_POST['bank_account_no'];
    
    $stmt = $pdo->prepare("UPDATE employees SET employee_id = ?, full_name = ?, designation = ?, department = ?, joining_date = ?, basic_salary = ?, phone = ?, address = ?, bank_account_no = ? WHERE id = ?");
    if($stmt->execute([$employee_id, $full_name, $designation, $department, $joining_date, $basic_salary, $phone, $address, $bank_account_no, $emp_id])) {
        $success = "Employee updated successfully!";
    } else {
        $error = "Failed to update employee";
    }
}

// Delete Employee
if(isset($_GET['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
    if($stmt->execute([$_GET['delete_id']])) {
        $success = "Employee deleted successfully!";
    }
}

// Process Attendance
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_attendance'])) {
    $attendance_data = $_POST['attendance'];
    $attendance_date = $_POST['attendance_date'];
    
    try {
        foreach($attendance_data as $emp_id => $data) {
            $status = $data['status'];
            $check_in = $data['check_in'];
            $check_out = $data['check_out'];
            $overtime = $data['overtime'];
            
            $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, check_in_time, check_out_time, status, overtime_hours) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE check_in_time = ?, check_out_time = ?, status = ?, overtime_hours = ?");
            $stmt->execute([$emp_id, $attendance_date, $check_in, $check_out, $status, $overtime, $check_in, $check_out, $status, $overtime]);
        }
        $success = "Attendance saved successfully!";
    } catch(Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Process Payroll - FIXED
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_payroll'])) {
    $month_year = $_POST['month_year']; // Format: YYYY-MM
    $month = date('m', strtotime($month_year . '-01'));
    $year = date('Y', strtotime($month_year . '-01'));
    
    try {
        $employees = $pdo->query("SELECT * FROM employees WHERE is_active = 1")->fetchAll();
        
        foreach($employees as $emp) {
            // Calculate attendance for the month
            $stmt = $pdo->prepare("SELECT COUNT(*) as present_days, SUM(overtime_hours) as total_overtime FROM attendance WHERE employee_id = ? AND MONTH(attendance_date) = ? AND YEAR(attendance_date) = ? AND status = 'present'");
            $stmt->execute([$emp['id'], $month, $year]);
            $attendance = $stmt->fetch();
            
            $present_days = $attendance['present_days'] ?? 0;
            $total_overtime = $attendance['total_overtime'] ?? 0;
            
            // Calculate allowances (40% of basic)
            $allowances = $emp['basic_salary'] * 0.40;
            
            // Calculate overtime (1.5x hourly rate)
            $hourly_rate = $emp['basic_salary'] / 30 / 8;
            $overtime_amount = $total_overtime * $hourly_rate * 1.5;
            
            // Calculate bonus (5% if present >= 26 days)
            $bonus = ($present_days >= 26) ? $emp['basic_salary'] * 0.05 : 0;
            
            // Calculate deductions (10% for loan/advance if any)
            $deductions = $emp['basic_salary'] * 0.10;
            
            $net_salary = $emp['basic_salary'] + $allowances + $overtime_amount + $bonus - $deductions;
            
            // Check if payroll already exists for this month
            $stmt = $pdo->prepare("SELECT id FROM payroll WHERE employee_id = ? AND month_year = ?");
            $stmt->execute([$emp['id'], $month_year]);
            
            if($stmt->fetch()) {
                // Update existing payroll
                $stmt = $pdo->prepare("UPDATE payroll SET basic_salary = ?, allowances = ?, overtime_amount = ?, bonus = ?, deductions = ?, net_salary = ? WHERE employee_id = ? AND month_year = ?");
                $stmt->execute([$emp['basic_salary'], $allowances, $overtime_amount, $bonus, $deductions, $net_salary, $emp['id'], $month_year]);
            } else {
                // Insert new payroll
                $stmt = $pdo->prepare("INSERT INTO payroll (employee_id, month_year, basic_salary, allowances, overtime_amount, bonus, deductions, net_salary) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$emp['id'], $month_year, $emp['basic_salary'], $allowances, $overtime_amount, $bonus, $deductions, $net_salary]);
            }
        }
        $success = "Payroll generated successfully for " . date('F Y', strtotime($month_year . '-01'));
    } catch(Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Mark Payroll as Paid
if(isset($_GET['mark_paid'])) {
    $payroll_id = $_GET['mark_paid'];
    $stmt = $pdo->prepare("UPDATE payroll SET status = 'paid', payment_date = CURDATE() WHERE id = ?");
    if($stmt->execute([$payroll_id])) {
        $success = "Payroll marked as paid!";
    } else {
        $error = "Failed to update status";
    }
}

// Get data for display
$employees = $pdo->query("SELECT * FROM employees WHERE is_active = 1 ORDER BY full_name")->fetchAll();
$today = date('Y-m-d');
$attendance_date = $_GET['attendance_date'] ?? $today;
$current_attendance = $pdo->prepare("SELECT * FROM attendance WHERE attendance_date = ?");
$current_attendance->execute([$attendance_date]);
$attendance_map = [];
while($row = $current_attendance->fetch()) {
    $attendance_map[$row['employee_id']] = $row;
}

$payroll_list = $pdo->query("SELECT p.*, e.full_name, e.designation FROM payroll p JOIN employees e ON p.employee_id = e.id ORDER BY p.month_year DESC, e.full_name")->fetchAll();

// Get employee for editing
$edit_employee = null;
if(isset($_GET['edit_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$_GET['edit_id']]);
    $edit_employee = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Management</title>
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
        }
        .form-sticky {
            position: sticky;
            top: 20px;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-users"></i> Payroll Management System</h2>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
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
                        <i class="fas fa-users fa-2x"></i>
                        <h3><?php echo count($employees); ?></h3>
                        <p>Total Employees</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-money-bill-wave fa-2x"></i>
                        <h3>৳ <?php echo number_format(array_sum(array_column($employees, 'basic_salary')), 0); ?></h3>
                        <p>Monthly Salary Budget</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-clock fa-2x"></i>
                        <h3><?php echo count($payroll_list); ?></h3>
                        <p>Payroll Generated</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-calendar-check fa-2x"></i>
                        <h3><?php echo date('F Y'); ?></h3>
                        <p>Current Month</p>
                    </div>
                </div>
            </div>
            
            <ul class="nav nav-tabs">
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'employees' ? 'active' : ''; ?>" href="?tab=employees">
                        <i class="fas fa-users"></i> Employees
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'attendance' ? 'active' : ''; ?>" href="?tab=attendance">
                        <i class="fas fa-clock"></i> Attendance
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'payroll' ? 'active' : ''; ?>" href="?tab=payroll">
                        <i class="fas fa-money-check"></i> Payroll
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'salary_sheet' ? 'active' : ''; ?>" href="?tab=salary_sheet">
                        <i class="fas fa-file-invoice"></i> Salary Sheet
                    </a>
                </li>
            </ul>
            
            <!-- Employees Tab -->
            <?php if($active_tab == 'employees'): ?>
            <div class="row mt-3">
                <div class="col-md-4">
                    <div class="card form-sticky">
                        <div class="card-header <?php echo $edit_employee ? 'bg-warning' : 'bg-primary'; ?> text-white">
                            <h5>
                                <i class="fas <?php echo $edit_employee ? 'fa-edit' : 'fa-user-plus'; ?>"></i> 
                                <?php echo $edit_employee ? 'Edit Employee' : 'Add New Employee'; ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <?php if($edit_employee): ?>
                                    <input type="hidden" name="emp_id" value="<?php echo $edit_employee['id']; ?>">
                                <?php endif; ?>
                                <div class="mb-3">
                                    <label>Employee ID</label>
                                    <input type="text" name="employee_id" class="form-control" 
                                           value="<?php echo $edit_employee['employee_id'] ?? ''; ?>" 
                                           placeholder="EMP-001" required>
                                </div>
                                <div class="mb-3">
                                    <label>Full Name</label>
                                    <input type="text" name="full_name" class="form-control" 
                                           value="<?php echo $edit_employee['full_name'] ?? ''; ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label>Designation</label>
                                    <input type="text" name="designation" class="form-control" 
                                           value="<?php echo $edit_employee['designation'] ?? ''; ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label>Department</label>
                                    <select name="department" class="form-control" required>
                                        <option value="Management" <?php echo ($edit_employee && $edit_employee['department'] == 'Management') ? 'selected' : ''; ?>>Management</option>
                                        <option value="Operations" <?php echo ($edit_employee && $edit_employee['department'] == 'Operations') ? 'selected' : ''; ?>>Operations</option>
                                        <option value="Sales" <?php echo ($edit_employee && $edit_employee['department'] == 'Sales') ? 'selected' : ''; ?>>Sales</option>
                                        <option value="Maintenance" <?php echo ($edit_employee && $edit_employee['department'] == 'Maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                                        <option value="Security" <?php echo ($edit_employee && $edit_employee['department'] == 'Security') ? 'selected' : ''; ?>>Security</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Joining Date</label>
                                    <input type="date" name="joining_date" class="form-control" 
                                           value="<?php echo $edit_employee['joining_date'] ?? ''; ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label>Basic Salary (BDT)</label>
                                    <input type="number" name="basic_salary" class="form-control" step="0.01" 
                                           value="<?php echo $edit_employee['basic_salary'] ?? ''; ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label>Phone</label>
                                    <input type="text" name="phone" class="form-control" 
                                           value="<?php echo $edit_employee['phone'] ?? ''; ?>">
                                </div>
                                <div class="mb-3">
                                    <label>Address</label>
                                    <textarea name="address" class="form-control" rows="2"><?php echo $edit_employee['address'] ?? ''; ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label>Bank Account No</label>
                                    <input type="text" name="bank_account_no" class="form-control" 
                                           value="<?php echo $edit_employee['bank_account_no'] ?? ''; ?>">
                                </div>
                                <button type="submit" name="<?php echo $edit_employee ? 'update_employee' : 'save_employee'; ?>" class="btn btn-primary w-100">
                                    <i class="fas fa-save"></i> <?php echo $edit_employee ? 'Update Employee' : 'Save Employee'; ?>
                                </button>
                                <?php if($edit_employee): ?>
                                    <a href="?tab=employees" class="btn btn-secondary w-100 mt-2">
                                        <i class="fas fa-plus"></i> Add New Employee
                                    </a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5><i class="fas fa-list"></i> Employee List</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="employeesTable">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Name</th>
                                            <th>Designation</th>
                                            <th>Department</th>
                                            <th>Basic Salary</th>
                                            <th>Phone</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($employees as $emp): ?>
                                        <tr>
                                            <td><?php echo $emp['employee_id']; ?></td>
                                            <td><strong><?php echo $emp['full_name']; ?></strong></td>
                                            <td><?php echo $emp['designation']; ?></td>
                                            <td><?php echo $emp['department']; ?></td>
                                            <td>BDT <?php echo number_format($emp['basic_salary'], 2); ?></td>
                                            <td><?php echo $emp['phone']; ?></td>
                                            <td>
                                                <a href="?tab=employees&edit_id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-warning">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <a href="?delete_id=<?php echo $emp['id']; ?>&tab=employees" class="btn btn-sm btn-danger" 
                                                   onclick="return confirm('Delete this employee?')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                              </td>
                                           </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Attendance Tab -->
            <?php if($active_tab == 'attendance'): ?>
            <div class="mt-3">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5><i class="fas fa-calendar-check"></i> Daily Attendance</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="mb-3">
                            <div class="row">
                                <div class="col-md-3">
                                    <label>Attendance Date</label>
                                    <input type="date" name="attendance_date" class="form-control" value="<?php echo $attendance_date; ?>">
                                </div>
                                <div class="col-md-2">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-primary form-control">Load Date</button>
                                </div>
                            </div>
                        </form>
                        
                        <form method="POST">
                            <input type="hidden" name="attendance_date" value="<?php echo $attendance_date; ?>">
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Employee</th>
                                            <th>Check In</th>
                                            <th>Check Out</th>
                                            <th>Status</th>
                                            <th>Overtime (Hours)</th>
                                         </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($employees as $emp): ?>
                                        <?php $att = $attendance_map[$emp['id']] ?? null; ?>
                                         <tr>
                                             <td><strong><?php echo $emp['full_name']; ?></strong><br><small><?php echo $emp['designation']; ?></small></td>
                                             <td>
                                                <input type="time" name="attendance[<?php echo $emp['id']; ?>][check_in]" class="form-control" value="<?php echo $att['check_in_time'] ?? '09:00'; ?>">
                                             </td>
                                             <td>
                                                <input type="time" name="attendance[<?php echo $emp['id']; ?>][check_out]" class="form-control" value="<?php echo $att['check_out_time'] ?? '17:00'; ?>">
                                             </td>
                                             <td>
                                                <select name="attendance[<?php echo $emp['id']; ?>][status]" class="form-control">
                                                    <option value="present" <?php echo ($att['status'] ?? '') == 'present' ? 'selected' : ''; ?>>Present</option>
                                                    <option value="absent" <?php echo ($att['status'] ?? '') == 'absent' ? 'selected' : ''; ?>>Absent</option>
                                                    <option value="late" <?php echo ($att['status'] ?? '') == 'late' ? 'selected' : ''; ?>>Late</option>
                                                    <option value="half_day" <?php echo ($att['status'] ?? '') == 'half_day' ? 'selected' : ''; ?>>Half Day</option>
                                                </select>
                                             </td>
                                             <td>
                                                <input type="number" name="attendance[<?php echo $emp['id']; ?>][overtime]" class="form-control" step="0.5" value="<?php echo $att['overtime_hours'] ?? 0; ?>">
                                             </td>
                                          </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button type="submit" name="save_attendance" class="btn btn-success">
                                <i class="fas fa-save"></i> Save Attendance
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Payroll Tab -->
            <?php if($active_tab == 'payroll'): ?>
            <div class="mt-3">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h5><i class="fas fa-calculator"></i> Generate Monthly Payroll</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="mb-4">
                            <div class="row">
                                <div class="col-md-3">
                                    <label>Select Month</label>
                                    <input type="month" name="month_year" class="form-control" value="<?php echo date('Y-m'); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label>&nbsp;</label>
                                    <button type="submit" name="generate_payroll" class="btn btn-warning form-control">
                                        <i class="fas fa-sync-alt"></i> Generate Payroll
                                    </button>
                                </div>
                            </div>
                        </form>
                        
                        <hr>
                        
                        <div class="table-responsive">
                            <table class="table table-bordered" id="payrollTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Month</th>
                                        <th>Employee</th>
                                        <th>Basic</th>
                                        <th>Allowances</th>
                                        <th>Overtime</th>
                                        <th>Bonus</th>
                                        <th>Deductions</th>
                                        <th>Net Salary</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                     </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($payroll_list as $pay): ?>
                                     <tr>
                                         <td><?php echo date('F Y', strtotime($pay['month_year'] . '-01')); ?></td>
                                         <td><strong><?php echo $pay['full_name']; ?></strong><br><small><?php echo $pay['designation']; ?></small></td>
                                         <td>BDT <?php echo number_format($pay['basic_salary'], 2); ?></td>
                                         <td>BDT <?php echo number_format($pay['allowances'], 2); ?></td>
                                         <td>BDT <?php echo number_format($pay['overtime_amount'], 2); ?></td>
                                         <td>BDT <?php echo number_format($pay['bonus'], 2); ?></td>
                                         <td>BDT <?php echo number_format($pay['deductions'], 2); ?></td>
                                         <td><strong>BDT <?php echo number_format($pay['net_salary'], 2); ?></strong></td>
                                         <td>
                                            <span class="badge bg-<?php echo $pay['status'] == 'paid' ? 'success' : 'warning'; ?>">
                                                <?php echo ucfirst($pay['status']); ?>
                                            </span>
                                         </td>
                                         <td>
                                            <a href="view_payslip.php?id=<?php echo $pay['id']; ?>" class="btn btn-sm btn-info">View</a>
                                            <?php if($pay['status'] == 'pending'): ?>
                                                <a href="?mark_paid=<?php echo $pay['id']; ?>&tab=payroll" class="btn btn-sm btn-success">Mark Paid</a>
                                            <?php endif; ?>
                                         </td>
                                      </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($payroll_list)): ?>
                                     <tr>
                                         <td colspan="10" class="text-center">No payroll records found. Generate payroll for a month.</td>
                                      </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Salary Sheet Tab -->
            <?php if($active_tab == 'salary_sheet'): ?>
            <div class="mt-3">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h5><i class="fas fa-file-invoice-dollar"></i> Salary Sheet Report</h5>
                    </div>
                    <div class="card-body">
                        <form target="_blank" action="salary_sheet_report.php" method="GET">
                            <div class="row">
                                <div class="col-md-3">
                                    <label>Select Month</label>
                                    <input type="month" name="month_year" class="form-control" value="<?php echo date('Y-m'); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-danger form-control">
                                        <i class="fas fa-print"></i> Generate Report
                                    </button>
                                </div>
                            </div>
                        </form>
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
            $('#employeesTable, #payrollTable').DataTable({
                order: [[0, 'asc']],
                pageLength: 25
            });
        });
    </script>
</body>
</html>
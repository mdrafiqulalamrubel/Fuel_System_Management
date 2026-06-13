<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$month_year = $_GET['month_year'] ?? date('Y-m');
$stmt = $pdo->prepare("
    SELECT p.*, e.full_name, e.designation, e.employee_id, e.bank_account_no
    FROM payroll p 
    JOIN employees e ON p.employee_id = e.id 
    WHERE p.month_year = ?
    ORDER BY e.full_name
");
$stmt->execute([$month_year]);
$payrolls = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT SUM(net_salary) as total, SUM(basic_salary) as total_basic, SUM(allowances) as total_allowances, SUM(overtime_amount) as total_overtime, SUM(bonus) as total_bonus, SUM(deductions) as total_deductions FROM payroll WHERE month_year = ?");
$stmt->execute([$month_year]);
$totals = $stmt->fetch();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Sheet</title>
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
        @media print {
            .sidebar, .no-print, .stats-card, .btn {
                display: none !important;
            }
            .main-content {
                margin: 0 !important;
                padding: 10px !important;
            }
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 20px;
            }
        }
        .print-header {
            display: none;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- Print Header -->
            <div class="print-header">
                <h2><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h2>
                <h4>Salary Sheet</h4>
                <p>Month: <?php echo date('F Y', strtotime($month_year . '-01')); ?></p>
                <p>Generated on: <?php echo date('d/m/Y h:i:s A'); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap no-print">
                <h2><i class="fas fa-file-invoice-dollar"></i> Salary Sheet</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="reports.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- Filter -->
            <div class="card mb-4 no-print">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-calendar-alt"></i> Select Month</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label>Month</label>
                            <input type="month" name="month_year" class="form-control" value="<?php echo $month_year; ?>">
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
            
            <!-- Statistics Cards -->
            <div class="row mb-4 no-print">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-users"></i>
                        <h3><?php echo count($payrolls); ?></h3>
                        <p>Employees</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($totals['total_basic'] ?? 0, 2); ?></h3>
                        <p>Total Basic</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($totals['total_overtime'] ?? 0, 2); ?></h3>
                        <p>Total Overtime</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-file-invoice"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($totals['total'] ?? 0, 2); ?></h3>
                        <p>Net Payable</p>
                    </div>
                </div>
            </div>
            
            <!-- Salary Table -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-list"></i> Salary Details - <?php echo date('F Y', strtotime($month_year . '-01')); ?></h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="salaryTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID</th>
                                    <th>Employee Name</th>
                                    <th>Designation</th>
                                    <th class="text-end">Basic</th>
                                    <th class="text-end">Allowances</th>
                                    <th class="text-end">Overtime</th>
                                    <th class="text-end">Bonus</th>
                                    <th class="text-end">Deductions</th>
                                    <th class="text-end">Net Salary</th>
                                    <th>Bank Account</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($payrolls as $p): ?>
                                <tr>
                                    <td><?php echo $p['employee_id']; ?></td>
                                    <td><strong><?php echo $p['full_name']; ?></strong></td>
                                    <td><?php echo $p['designation']; ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($p['basic_salary'], 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($p['allowances'], 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($p['overtime_amount'], 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($p['bonus'], 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($p['deductions'], 2); ?></td>
                                    <td class="text-end fw-bold text-success"><?php echo $currency; ?> <?php echo number_format($p['net_salary'], 2); ?></td>
                                    <td><?php echo $p['bank_account_no']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="3" class="text-end">TOTAL:</td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($totals['total_basic'] ?? 0, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($totals['total_allowances'] ?? 0, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($totals['total_overtime'] ?? 0, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($totals['total_bonus'] ?? 0, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($totals['total_deductions'] ?? 0, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($totals['total'] ?? 0, 2); ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
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
            $('#salaryTable').DataTable({
                order: [[1, 'asc']],
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
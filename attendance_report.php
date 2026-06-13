<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$month = $_GET['month'] ?? date('Y-m');
$year = date('Y', strtotime($month));
$month_num = date('m', strtotime($month));

$employees = $pdo->query("SELECT * FROM employees WHERE is_active = 1 ORDER BY full_name")->fetchAll();
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);

$attendance_data = [];
foreach($employees as $emp) {
    $stmt = $pdo->prepare("SELECT attendance_date, status FROM attendance WHERE employee_id = ? AND YEAR(attendance_date) = ? AND MONTH(attendance_date) = ?");
    $stmt->execute([$emp['id'], $year, $month_num]);
    $att = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $attendance_data[$emp['id']] = $att;
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Report</title>
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
        .stats-card i {
            font-size: 40px;
            opacity: 0.5;
            float: right;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .attendance-table {
            min-width: 800px;
        }
        .attendance-table th, .attendance-table td {
            white-space: nowrap;
            padding: 6px 8px;
        }
        @media print {
            .sidebar, .no-print, .stats-card, .btn, .dataTables_length, .dataTables_filter, .dataTables_paginate {
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
            .attendance-table th, .attendance-table td {
                border: 1px solid #000 !important;
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
                <h4>Attendance Report</h4>
                <p>Month: <?php echo date('F Y', strtotime($month)); ?></p>
                <p>Generated on: <?php echo date('d/m/Y h:i:s A'); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap no-print">
                <h2><i class="fas fa-calendar-check"></i> Attendance Report</h2>
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
                            <input type="month" name="month" class="form-control" value="<?php echo $month; ?>">
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
                        <h3><?php echo count($employees); ?></h3>
                        <p>Total Employees</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-calendar-day"></i>
                        <h3><?php echo $days_in_month; ?></h3>
                        <p>Days in Month</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-clock"></i>
                        <h3><?php echo date('F Y', strtotime($month)); ?></h3>
                        <p>Report Month</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-chart-line"></i>
                        <h3>Daily</h3>
                        <p>Attendance Tracker</p>
                    </div>
                </div>
            </div>
            
            <!-- Attendance Table -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-list"></i> Attendance Details - <?php echo date('F Y', strtotime($month)); ?></h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover attendance-table" id="attendanceTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Employee</th>
                                    <th>Designation</th>
                                    <?php for($d=1; $d<=$days_in_month; $d++): ?>
                                        <th class="text-center"><?php echo $d; ?></th>
                                    <?php endfor; ?>
                                    <th class="text-center">P</th>
                                    <th class="text-center">A</th>
                                    <th class="text-center">L</th>
                                    <th class="text-center">HD</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($employees as $emp): ?>
                                <?php
                                    $present=0; $absent=0; $late=0; $half=0;
                                    $att_map = $attendance_data[$emp['id']] ?? [];
                                ?>
                                <tr>
                                    <td><strong><?php echo $emp['full_name']; ?></strong></td>
                                    <td><?php echo $emp['designation']; ?></td>
                                    <?php for($d=1; $d<=$days_in_month; $d++): ?>
                                        <?php 
                                            $date = sprintf('%04d-%02d-%02d', $year, $month_num, $d);
                                            $status = $att_map[$date] ?? 'absent';
                                            $badge = match($status) {
                                                'present' => 'bg-success',
                                                'late' => 'bg-warning',
                                                'half_day' => 'bg-info',
                                                default => 'bg-danger'
                                            };
                                            if($status=='present') $present++;
                                            elseif($status=='absent') $absent++;
                                            elseif($status=='late') $late++;
                                            elseif($status=='half_day') $half++;
                                        ?>
                                        <td class="text-center">
                                            <span class="badge <?php echo $badge; ?>" style="font-size: 10px;">
                                                <?php echo substr($status,0,1); ?>
                                            </span>
                                        </td>
                                    <?php endfor; ?>
                                    <td class="text-center fw-bold bg-success text-white"><?php echo $present; ?></td>
                                    <td class="text-center bg-danger text-white"><?php echo $absent; ?></td>
                                    <td class="text-center bg-warning"><?php echo $late; ?></td>
                                    <td class="text-center bg-info"><?php echo $half; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
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
            $('#attendanceTable').DataTable({
                pageLength: 25,
                scrollX: true,
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
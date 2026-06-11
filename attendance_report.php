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
    <title>Attendance Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>@media print{.no-print{display:none;} body{font-size:12px;}}</style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    <div class="container-fluid mt-3">
        <div class="no-print mb-3">
            <a href="reports.php" class="btn btn-secondary">Back</a>
            <button onclick="window.print()" class="btn btn-primary">Print</button>
            <form class="d-inline" method="GET"><input type="month" name="month" value="<?php echo $month; ?>"><button type="submit" class="btn btn-info">View</button></form>
        </div>
        
        <div class="text-center"><h3><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h3><h4>ATTENDANCE REPORT</h4><h5>Month: <?php echo date('F Y', strtotime($month)); ?></h5></div>
        
        <div class="table-responsive mt-3">
            <table class="table table-bordered table-sm">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Designation</th>
                        <?php for($d=1; $d<=$days_in_month; $d++): ?>
                            <th><?php echo $d; ?></th>
                        <?php endfor; ?>
                        <th>Present</th>
                        <th>Absent</th>
                        <th>Late</th>
                        <th>HD</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($employees as $emp): ?>
                    <?php
                        $present=0; $absent=0; $late=0; $half=0;
                        $att_map = $attendance_data[$emp['id']] ?? [];
                    ?>
                    <tr>
                        <td><?php echo $emp['full_name']; ?></td>
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
                            <td class="text-center"><span class="badge <?php echo $badge; ?>" style="font-size:10px;"><?php echo substr($status,0,1); ?></span></td>
                        <?php endfor; ?>
                        <td class="text-center fw-bold"><?php echo $present; ?></td>
                        <td class="text-center"><?php echo $absent; ?></td>
                        <td class="text-center"><?php echo $late; ?></td>
                        <td class="text-center"><?php echo $half; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
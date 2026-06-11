<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-t');
$tank_id = $_GET['tank_id'] ?? '';

$sql = "SELECT l.*, t.tank_name, p.product_name, u.full_name as approved_by_name 
        FROM leakage_adjustments l 
        JOIN tanks t ON l.tank_id = t.id 
        JOIN fuel_products p ON t.product_id = p.id 
        LEFT JOIN users u ON l.approved_by = u.id 
        WHERE l.adjustment_date BETWEEN ? AND ?";
$params = [$from_date, $to_date];
if($tank_id) {
    $sql .= " AND l.tank_id = ?";
    $params[] = $tank_id;
}
$sql .= " ORDER BY l.adjustment_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leakages = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT SUM(variance) as total_loss_liters, SUM(loss_amount) as total_loss_amount, COUNT(*) as total_incidents FROM leakage_adjustments WHERE adjustment_date BETWEEN ? AND ? AND status='approved'");
$stmt->execute([$from_date, $to_date]);
$summary = $stmt->fetch();

$tanks = $pdo->query("SELECT * FROM tanks")->fetchAll();
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leakage & Wastage Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>@media print{.no-print{display:none;}}</style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    <div class="container mt-3">
        <div class="no-print mb-3">
            <a href="reports.php" class="btn btn-secondary">Back</a>
            <button onclick="window.print()" class="btn btn-primary">Print</button>
            <form class="d-inline" method="GET">
                <input type="date" name="from_date" value="<?php echo $from_date; ?>"> to
                <input type="date" name="to_date" value="<?php echo $to_date; ?>">
                <select name="tank_id"><option value="">All Tanks</option><?php foreach($tanks as $t){ echo "<option value='{$t['id']}' ".($tank_id==$t['id']?'selected':'').">{$t['tank_name']}</option>"; } ?></select>
                <button type="submit" class="btn btn-info">View</button>
            </form>
        </div>
        
        <div class="text-center"><h3><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h3><h4>LEAKAGE & WASTAGE REPORT</h4><h5>Period: <?php echo date('d M Y', strtotime($from_date)); ?> to <?php echo date('d M Y', strtotime($to_date)); ?></h5></div>
        
        <div class="row mt-4">
            <div class="col-md-4"><div class="card bg-danger text-white"><div class="card-body"><h6>Total Incidents</h6><h4><?php echo $summary['total_incidents'] ?? 0; ?></h4></div></div></div>
            <div class="col-md-4"><div class="card bg-warning text-white"><div class="card-body"><h6>Total Loss (Liters)</h6><h4><?php echo number_format($summary['total_loss_liters'] ?? 0, 2); ?> L</h4></div></div></div>
            <div class="col-md-4"><div class="card bg-dark text-white"><div class="card-body"><h6>Total Financial Loss</h6><h4><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($summary['total_loss_amount'] ?? 0, 2); ?></h4></div></div></div>
        </div>
        
        <div class="card mt-4"><div class="card-header bg-danger text-white">Incident Details</div><div class="card-body"><table class="table table-bordered" id="leakageTable"><thead><tr><th>Date</th><th>Tank</th><th>Product</th><th>System Stock</th><th>Physical Stock</th><th>Variance</th><th>Type</th><th>Loss Amount</th><th>Status</th></tr></thead><tbody><?php foreach($leakages as $l): ?><tr><td><?php echo $l['adjustment_date']; ?></td><td><?php echo $l['tank_name']; ?></td><td><?php echo $l['product_name']; ?></td><td><?php echo number_format($l['system_stock'],2); ?>L</td><td><?php echo number_format($l['physical_stock'],2); ?>L</td><td class="text-danger fw-bold"><?php echo number_format($l['variance'],2); ?>L</td><td><?php echo ucfirst($l['adjustment_type']); ?></td><td><?php echo $settings['currency_symbol'] ?? 'BDT'; ?> <?php echo number_format($l['loss_amount'] ?? 0, 2); ?></td><td><span class="badge bg-<?php echo $l['status']=='approved'?'success':'warning'; ?>"><?php echo ucfirst($l['status']); ?></span></td></tr><?php endforeach; ?></tbody></table></div></div>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>$(document).ready(function(){$('#leakageTable').DataTable({order:[[0,'desc']]});});</script>
</body>
</html>
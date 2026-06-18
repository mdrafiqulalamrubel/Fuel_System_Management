<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d', strtotime('-30 days'));
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$shift_id = isset($_GET['shift_id']) ? $_GET['shift_id'] : '';
$nozzle_id = isset($_GET['nozzle_id']) ? $_GET['nozzle_id'] : '';

// Build query
$conditions = ["DATE(sale_date) BETWEEN ? AND ?"];
$params = [$from_date, $to_date];

if($shift_id) {
    $conditions[] = "shift_id = ?";
    $params[] = $shift_id;
}

if($nozzle_id) {
    $conditions[] = "nozzle_id = ?";
    $params[] = $nozzle_id;
}

$where_clause = implode(" AND ", $conditions);

$stmt = $pdo->prepare("
    SELECT 
        gs.*,
        u.username as operator_name,
        n.nozzle_name,
        sh.shift_name
    FROM gas_sales gs
    JOIN users u ON gs.operator_id = u.id
    JOIN nozzles n ON gs.nozzle_id = n.id
    LEFT JOIN shifts sh ON gs.shift_id = sh.id
    WHERE $where_clause
    ORDER BY gs.sale_date DESC
");
$stmt->execute($params);
$sales = $stmt->fetchAll();

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="cng_sales_report_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, [
    'Invoice No',
    'Date/Time',
    'Shift',
    'Nozzle',
    'Operator',
    'Customer Name',
    'Customer Phone',
    'Opening Meter (m³)',
    'Closing Meter (m³)',
    'Quantity (m³)',
    'Unit Price',
    'Total Amount',
    'Sale Type',
    'Status'
]);

foreach($sales as $sale) {
    fputcsv($output, [
        $sale['invoice_no'],
        $sale['sale_date'],
        $sale['shift_name'] ?? 'N/A',
        $sale['nozzle_name'],
        $sale['operator_name'],
        $sale['customer_name'] ?? 'Walk-in',
        $sale['customer_phone'] ?? '',
        $sale['opening_meter'],
        $sale['closing_meter'],
        $sale['quantity_liters'],
        $sale['unit_price'],
        $sale['total_amount'],
        $sale['sale_type'],
        $sale['status']
    ]);
}

fclose($output);
exit();
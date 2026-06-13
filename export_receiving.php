<?php
require_once 'config/database.php';

$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT fr.*, p.product_name, t.tank_name 
    FROM fuel_receivings fr 
    JOIN fuel_products p ON fr.product_id = p.id 
    JOIN tanks t ON fr.tank_id = t.id 
    WHERE DATE(fr.receipt_date) BETWEEN ? AND ?
    ORDER BY fr.receipt_date DESC
");
$stmt->execute([$from_date, $to_date]);
$receivings = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT 
        SUM(actual_quantity) as total_liters,
        SUM(total_amount) as total_amount,
        COUNT(*) as total_receipts,
        SUM(shortage) as total_shortage
    FROM fuel_receivings 
    WHERE DATE(receipt_date) BETWEEN ? AND ?
");
$stmt->execute([$from_date, $to_date]);
$summary = $stmt->fetch();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="fuel_receiving_report_'.date('Y-m-d').'.xls"');

echo "FUEL RECEIVING REPORT\n";
echo "Period: " . date('d-m-Y', strtotime($from_date)) . " to " . date('d-m-Y', strtotime($to_date)) . "\n";
echo "Generated on: " . date('d-m-Y H:i:s') . "\n\n";

echo "Date\tReceipt No\tSupplier\tTanker No\tChallan No\tProduct\tTank\tExpected\tActual\tShortage\tFreight Cost\tFreight Deduction\tUnit Price\tTotal Amount\n";
foreach($receivings as $r) {
    echo date('d-m-Y', strtotime($r['receipt_date'])) . "\t";
    echo $r['receipt_no'] . "\t";
    echo $r['supplier_name'] . "\t";
    echo $r['tanker_no'] . "\t";
    echo $r['challan_no'] . "\t";
    echo $r['product_name'] . "\t";
    echo $r['tank_name'] . "\t";
    echo $r['expected_quantity'] . "\t";
    echo $r['actual_quantity'] . "\t";
    echo $r['shortage'] . "\t";
    echo $r['freight_cost'] . "\t";
    echo $r['freight_deduction'] . "\t";
    echo $r['unit_price'] . "\t";
    echo $r['total_amount'] . "\n";
}

echo "\n\nSUMMARY\n";
echo "Total Receipts:\t" . ($summary['total_receipts'] ?? 0) . "\n";
echo "Total Liters:\t" . number_format($summary['total_liters'] ?? 0, 2) . "\n";
echo "Total Amount:\t" . $currency . " " . number_format($summary['total_amount'] ?? 0, 2) . "\n";
echo "Total Shortage:\t" . number_format($summary['total_shortage'] ?? 0, 2) . " L\n";
?>
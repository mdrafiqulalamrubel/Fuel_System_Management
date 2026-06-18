<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$invoice_no = isset($_GET['invoice']) ? $_GET['invoice'] : '';
$type = isset($_GET['type']) ? $_GET['type'] : 'liquid';

if(empty($invoice_no)) {
    echo json_encode(['success' => false, 'message' => 'Invoice number required']);
    exit;
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';

if($type == 'cng') {
    // Get CNG sale details
    $stmt = $pdo->prepare("
        SELECT gs.*, n.nozzle_name, u.full_name as operator_name, p.product_name
        FROM gas_sales gs
        JOIN nozzles n ON gs.nozzle_id = n.id
        JOIN fuel_products p ON n.product_id = p.id
        LEFT JOIN users u ON gs.operator_id = u.id
        WHERE gs.invoice_no = ?
    ");
    $stmt->execute([$invoice_no]);
    $invoice = $stmt->fetch();
    
    if($invoice) {
        echo json_encode([
            'success' => true,
            'invoice' => [
                'invoice_no' => $invoice['invoice_no'],
                'sale_date' => date('d-m-Y h:i A', strtotime($invoice['sale_date'])),
                'customer_name' => $invoice['customer_name'],
                'sale_type' => $invoice['sale_type'],
                'operator_name' => $invoice['operator_name'],
                'product_name' => $invoice['product_name'],
                'nozzle_name' => $invoice['nozzle_name'],
                'quantity_liters' => $invoice['quantity_liters'],
                'unit_price' => $invoice['unit_price'],
                'total_amount' => $invoice['total_amount'],
                'received_amount' => $invoice['received_amount'],
                'change_amount' => $invoice['change_amount'],
                'opening_meter' => $invoice['opening_meter'],
                'closing_meter' => $invoice['closing_meter'],
                'meter_readings' => true,
                'sale_type_display' => 'cng',
                'currency' => $currency
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invoice not found']);
    }
} else {
    // Get liquid sale details
    $stmt = $pdo->prepare("
        SELECT s.*, n.nozzle_name, u.full_name as operator_name, p.product_name
        FROM sales s
        JOIN nozzles n ON s.nozzle_id = n.id
        JOIN fuel_products p ON s.product_id = p.id
        LEFT JOIN users u ON s.operator_id = u.id
        WHERE s.invoice_no = ?
    ");
    $stmt->execute([$invoice_no]);
    $invoice = $stmt->fetch();
    
    if($invoice) {
        echo json_encode([
            'success' => true,
            'invoice' => [
                'invoice_no' => $invoice['invoice_no'],
                'sale_date' => date('d-m-Y h:i A', strtotime($invoice['sale_date'])),
                'customer_name' => $invoice['customer_name'],
                'sale_type' => $invoice['sale_type'],
                'operator_name' => $invoice['operator_name'],
                'product_name' => $invoice['product_name'],
                'nozzle_name' => $invoice['nozzle_name'],
                'quantity_liters' => $invoice['quantity_liters'],
                'unit_price' => $invoice['unit_price'],
                'total_amount' => $invoice['total_amount'],
                'received_amount' => $invoice['received_amount'],
                'change_amount' => $invoice['change_amount'],
                'subtotal' => $invoice['subtotal'],
                'vat_amount' => $invoice['vat_amount'],
                'tax_amount' => $invoice['tax_amount'],
                'advance_used' => $invoice['advance_used'] ?? 0,
                'sale_type_display' => 'liquid',
                'currency' => $currency
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invoice not found']);
    }
}
?>
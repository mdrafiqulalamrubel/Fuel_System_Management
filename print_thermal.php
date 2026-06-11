<?php
session_start();
if(!isset($_SESSION['last_invoice'])) {
    die('No invoice to print');
}

$invoice = $_SESSION['last_invoice'];

// Get product name
require_once 'config/database.php';
$stmt = $pdo->prepare("SELECT product_name FROM fuel_products WHERE id = ?");
$stmt->execute([$invoice['product']]);
$product = $stmt->fetch();
$product_name = $product['product_name'] ?? 'Fuel';

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

// ESC/POS Commands for thermal printer
$esc = chr(27); // ESC character
$gs = chr(29);  // GS character

// Initialize printer
$print_data = $esc . "@"; // Initialize printer

// Set character encoding
$print_data .= $esc . "R" . chr(0); // Select character code table

// Print company name (bold, center, double size)
$print_data .= $esc . "!" . chr(8); // Bold on
$print_data .= $esc . "a" . chr(1); // Center align
$print_data .= $settings['company_name'] ?? 'FF ENTERPRISE' . "\n";
$print_data .= $esc . "!" . chr(0); // Bold off
$print_data .= $esc . "a" . chr(0); // Left align

// Print company details
$print_data .= ($settings['company_address'] ?? 'Dhaka, Bangladesh') . "\n";
$print_data .= "Tel: " . ($settings['company_phone'] ?? '+880 1234 567890') . "\n";
$print_data .= "VAT: " . ($settings['vat_reg_no'] ?? '123456789') . "\n";

// Print separator
$print_data .= str_repeat("-", 32) . "\n";

// Print invoice header
$print_data .= $esc . "!" . chr(1); // Emphasized on
$print_data .= "FUEL SALE INVOICE\n";
$print_data .= $esc . "!" . chr(0); // Emphasized off
$print_data .= str_repeat("-", 32) . "\n";

// Print invoice details
$print_data .= "Invoice: " . $invoice['invoice_no'] . "\n";
$print_data .= "Date: " . date('d/m/Y H:i:s', strtotime($invoice['date'])) . "\n";
$print_data .= "Customer: " . ($invoice['customer_name'] ?: 'Walk-in Customer') . "\n";
$print_data .= "Operator: " . ($_SESSION['user_name'] ?? 'Cashier') . "\n";
$print_data .= str_repeat("-", 32) . "\n";

// Print items
$print_data .= sprintf("%-15s %8s %10s\n", "Product", "Qty(L)", "Amount");
$print_data .= sprintf("%-15s %8s %10s\n", 
    substr($product_name, 0, 15), 
    number_format($invoice['quantity'], 3),
    $settings['currency_symbol'] ?? 'BDT' . number_format($invoice['subtotal'], 2)
);
$print_data .= str_repeat("-", 32) . "\n";

// Print totals
$print_data .= sprintf("%-23s %10s\n", "Subtotal:", $settings['currency_symbol'] ?? 'BDT' . number_format($invoice['subtotal'], 2));
$print_data .= sprintf("%-23s %10s\n", "VAT (5%):", $settings['currency_symbol'] ?? 'BDT' . number_format($invoice['vat'], 2));
$print_data .= sprintf("%-23s %10s\n", "Tax (2%):", $settings['currency_symbol'] ?? 'BDT' . number_format($invoice['tax'], 2));
$print_data .= str_repeat("-", 32) . "\n";

// Print total (bold, double size)
$print_data .= $esc . "!" . chr(8); // Bold
$print_data .= sprintf("%-23s %10s\n", "TOTAL:", $settings['currency_symbol'] ?? 'BDT' . number_format($invoice['total'], 2));
$print_data .= $esc . "!" . chr(0); // Bold off

// Print payment details if cash
if(isset($invoice['received']) && $invoice['received'] > 0) {
    $print_data .= str_repeat("-", 32) . "\n";
    $print_data .= sprintf("%-23s %10s\n", "Received:", $settings['currency_symbol'] ?? 'BDT' . number_format($invoice['received'], 2));
    $print_data .= sprintf("%-23s %10s\n", "Change:", $settings['currency_symbol'] ?? 'BDT' . number_format($invoice['change'], 2));
}

// Print footer
$print_data .= str_repeat("-", 32) . "\n";
$print_data .= $esc . "a" . chr(1); // Center align
$print_data .= "*** THANK YOU ***\n";
$print_data .= "Fuel once sold is not returnable\n";
$print_data .= "This is a computer generated invoice\n";
$print_data .= $invoice['invoice_no'] . "\n";
$print_data .= date('d/m/Y H:i:s') . "\n";
$print_data .= $esc . "a" . chr(0); // Left align

// Cut paper (if supported)
$print_data .= $gs . "V" . chr(1); // Partial cut
// $print_data .= $gs . "V" . chr(66); // Full cut

// Output for thermal printer
header('Content-Type: text/plain');
header('Content-Disposition: inline; filename="print.txt"');
echo $print_data;
?>
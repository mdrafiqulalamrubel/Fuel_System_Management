<?php
require_once 'config/database.php';
header('Content-Type: application/json');

if(isset($_POST['customer_id'])) {
    $customer_id = $_POST['customer_id'];
    
    $stmt = $pdo->prepare("
        SELECT invoice_no, sale_date, due_date, total_amount, paid_amount, balance_due, status
        FROM credit_sales 
        WHERE customer_id = ? AND status IN ('pending', 'partial')
        ORDER BY sale_date ASC
    ");
    $stmt->execute([$customer_id]);
    $credits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($credits);
} else {
    echo json_encode([]);
}
?>
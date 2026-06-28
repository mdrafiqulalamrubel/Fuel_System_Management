<?php
// ajax/get_customer.php
require_once '../config/database.php';
header('Content-Type: application/json');

if(!isLoggedIn()) {
    echo json_encode(null);
    exit;
}

$id = intval($_GET['id'] ?? 0);
if($id <= 0) {
    echo json_encode(null);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();

if($customer) {
    echo json_encode($customer);
} else {
    echo json_encode(null);
}
?>
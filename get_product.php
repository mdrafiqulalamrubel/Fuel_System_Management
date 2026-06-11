<?php
require_once 'config/database.php';
header('Content-Type: application/json');

if(isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM fuel_products WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
}
?>
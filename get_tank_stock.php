<?php
require_once 'config/database.php';
header('Content-Type: application/json');

if(isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT current_stock_liters as stock FROM tanks WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
}
?>
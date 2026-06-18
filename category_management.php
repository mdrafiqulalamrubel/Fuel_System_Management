<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Add category
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_category'])) {
    $category_name = $_POST['category_name'];
    $category_code = $_POST['category_code'] ?? '';
    $description = $_POST['description'] ?? '';
    
    try {
        $stmt = $pdo->prepare("INSERT INTO item_categories (category_name, category_code, description) VALUES (?, ?, ?)");
        $stmt->execute([$category_name, $category_code, $description]);
        $success = "✅ Category added successfully!";
    } catch(Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

header("Location: item_management.php?tab=categories");
exit();
?>
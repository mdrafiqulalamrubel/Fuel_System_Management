<?php
// ajax/save_customer.php
require_once '../config/database.php';
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if(!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Get POST data
$customer_name = trim($_POST['customer_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$credit_limit = floatval($_POST['credit_limit'] ?? 50000);

// Validate
if(empty($customer_name)) {
    echo json_encode(['success' => false, 'message' => 'Customer name is required']);
    exit;
}

try {
    // Check if customer already exists by name or phone
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE customer_name = ? OR phone = ?");
    $stmt->execute([$customer_name, $phone]);
    if($stmt->rowCount() > 0) {
        $existing = $stmt->fetch();
        echo json_encode([
            'success' => false, 
            'message' => 'Customer already exists!', 
            'customer_id' => $existing['id']
        ]);
        exit;
    }
    
    // Generate customer code
    $customer_code = 'CUST-' . date('Ymd') . rand(100, 999);
    
    // Insert new customer
    $stmt = $pdo->prepare("
        INSERT INTO customers (
            customer_code, 
            customer_name, 
            phone, 
            email, 
            address, 
            credit_limit, 
            current_balance,
            advance_balance,
            is_active
        ) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 1)
    ");
    $stmt->execute([
        $customer_code, 
        $customer_name, 
        $phone, 
        $email, 
        $address, 
        $credit_limit
    ]);
    $customer_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'customer_id' => $customer_id,
        'customer_code' => $customer_code,
        'message' => 'Customer added successfully'
    ]);
    
} catch(PDOException $e) {
    // Check for duplicate entry error
    if($e->getCode() == 23000) {
        echo json_encode(['success' => false, 'message' => 'Customer already exists with this name or phone']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
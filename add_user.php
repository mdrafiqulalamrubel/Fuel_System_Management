<?php
// No need for session_start() here - it's already in database.php
require_once 'config/database.php';

// Check if user is logged in
if(!isLoggedIn()) {
    redirect('index.php');
}

$user = getCurrentUser();

// Only Super Admin can add users
if($user['role'] != 'super_admin') {
    redirect('dashboard.php');
}

$error = '';
$success = '';

// Get existing roles from database
$stmt = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'role'");
$column = $stmt->fetch();
preg_match("/^enum\((.*)\)$/", $column['Type'], $matches);
$enum_values = [];
if(isset($matches[1])) {
    foreach(explode(',', $matches[1]) as $value) {
        $enum_values[] = trim($value, "'");
    }
}

// Default roles if enum not found
if(empty($enum_values)) {
    $enum_values = ['super_admin', 'owner', 'accountant', 'station_manager', 'cashier', 'nozzle_operator', 'hr_officer', 'store_keeper', 'auditor'];
}

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $full_name = trim($_POST['full_name']);
    $password = md5($_POST['password']);
    $role = $_POST['role'];
    $email = isset($_POST['email']) ? $_POST['email'] : '';
    $phone = isset($_POST['phone']) ? $_POST['phone'] : '';
    
    // Validate role
    if(!in_array($role, $enum_values)) {
        $error = "Invalid role selected!";
    } else {
        // Check if username exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if($stmt->fetch()) {
            $error = "Username already exists!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, phone, role, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
            if($stmt->execute([$username, $password, $full_name, $email, $phone, $role])) {
                $success = "User created successfully!";
                // Redirect back to settings after 2 seconds
                echo "<script>setTimeout(function(){ window.location.href='settings.php?tab=users'; }, 1500);</script>";
            } else {
                $error = "Failed to create user!";
            }
        }
    }
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New User</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .add-user-card {
            max-width: 600px;
            margin: 50px auto;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            border: none;
            border-radius: 15px;
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            text-align: center;
        }
        .btn-back {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        @media (max-width: 768px) {
            .add-user-card {
                margin: 20px;
            }
        }
    </style>
</head>
<body>
    <a href="settings.php?tab=users" class="btn btn-secondary btn-back">
        <i class="fas fa-arrow-left"></i> Back to Users
    </a>
    
    <div class="container">
        <div class="add-user-card card">
            <div class="card-header text-white">
                <i class="fas fa-user-plus fa-3x mb-2"></i>
                <h4>Create New User Account</h4>
                <p class="mb-0">Add a new user to the system</p>
            </div>
            <div class="card-body p-4">
                <?php if($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if($success): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label><i class="fas fa-user"></i> Username *</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label><i class="fas fa-user-circle"></i> Full Name *</label>
                            <input type="text" name="full_name" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label><i class="fas fa-phone"></i> Phone</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label><i class="fas fa-lock"></i> Password *</label>
                            <input type="password" name="password" class="form-control" required>
                            <small class="text-muted">Minimum 4 characters</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label><i class="fas fa-tag"></i> Role *</label>
                            <select name="role" class="form-control" required>
                                <option value="super_admin">Super Admin (Full Access)</option>
                                <option value="owner">Owner/Director</option>
                                <option value="accountant">Accountant</option>
                                <option value="station_manager">Station Manager</option>
                                <option value="cashier">Cashier</option>
                                <option value="nozzle_operator">Nozzle Operator</option>
                                <option value="hr_officer">HR Officer</option>
                                <option value="store_keeper">Store Keeper</option>
                                <option value="auditor">Auditor</option>
                            </select>
                        </div>
                    </div>
                    
                    <hr>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save"></i> Create User
                    </button>
                </form>
                
                <div class="alert alert-info mt-4">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Role Information:</strong>
                    <ul class="mb-0 mt-2">
                        <li><strong>Super Admin</strong> - Full system access including user management</li>
                        <li><strong>Owner</strong> - Can view all reports and manage business</li>
                        <li><strong>Accountant</strong> - Access to accounting and financial reports</li>
                        <li><strong>Station Manager</strong> - Manage daily operations</li>
                        <li><strong>Cashier</strong> - Can only use POS system</li>
                        <li><strong>Nozzle Operator</strong> - Can record fuel sales only</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
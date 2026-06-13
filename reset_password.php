<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
if($user['role'] != 'super_admin') {
    redirect('dashboard.php');
}

$error = '';
$success = '';
$user_id = isset($_GET['id']) ? $_GET['id'] : 0;

// Get user details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$reset_user = $stmt->fetch();

if(!$reset_user) {
    redirect('settings.php?tab=users');
}

// Process password reset
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if(empty($new_password)) {
        $error = "Please enter a password";
    } elseif(strlen($new_password) < 4) {
        $error = "Password must be at least 4 characters";
    } elseif($new_password != $confirm_password) {
        $error = "Passwords do not match";
    } else {
        $hashed_password = md5($new_password);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        if($stmt->execute([$hashed_password, $user_id])) {
            $success = "Password reset successfully for user: " . $reset_user['username'];
            
            // Log activity
            $log = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
            $log->execute([$user['id'], 'password_reset', "Reset password for user: {$reset_user['username']}", $_SERVER['REMOTE_ADDR']]);
            
            // Redirect after 2 seconds
            echo "<script>setTimeout(function(){ window.location.href='settings.php?tab=users'; }, 2000);</script>";
        } else {
            $error = "Failed to reset password";
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
    <title>Reset Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .main-content {
            margin-left: 280px;
            padding: 20px;
            transition: margin-left 0.3s;
        }
        .reset-card {
            max-width: 500px;
            margin: 0 auto;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            border: none;
            border-radius: 15px;
        }
        .reset-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 20px;
            text-align: center;
        }
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="reset-card">
                <div class="reset-header">
                    <i class="fas fa-key fa-3x mb-3"></i>
                    <h3>Reset User Password</h3>
                    <p class="mb-0">Set a new password for the user account</p>
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
                    
                    <div class="info-box">
                        <i class="fas fa-user-circle"></i>
                        <strong>User Information:</strong><br>
                        <strong>Username:</strong> <?php echo htmlspecialchars($reset_user['username']); ?><br>
                        <strong>Full Name:</strong> <?php echo htmlspecialchars($reset_user['full_name']); ?><br>
                        <strong>Role:</strong> <?php echo ucfirst(str_replace('_', ' ', $reset_user['role'])); ?><br>
                        <strong>Email:</strong> <?php echo htmlspecialchars($reset_user['email'] ?: 'Not provided'); ?>
                    </div>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label><i class="fas fa-lock"></i> New Password</label>
                            <input type="password" name="new_password" class="form-control" required>
                            <small class="text-muted">Minimum 4 characters</small>
                        </div>
                        <div class="mb-3">
                            <label><i class="fas fa-check-circle"></i> Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Note:</strong> The user will need to use this new password to log in. 
                            Please share the password securely with the user.
                        </div>
                        <button type="submit" name="reset_password" class="btn btn-primary w-100">
                            <i class="fas fa-save"></i> Reset Password
                        </button>
                        <a href="settings.php?tab=users" class="btn btn-secondary w-100 mt-2">
                            <i class="fas fa-arrow-left"></i> Back to Users
                        </a>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
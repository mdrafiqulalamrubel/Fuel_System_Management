<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
if($user['role'] != 'super_admin') {
    redirect('dashboard.php');
}

$error = '';
$success = '';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $full_name = $_POST['full_name'];
    $password = md5($_POST['password']);
    $role = $_POST['role'];
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    
    // Check if username exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if($stmt->fetch()) {
        $error = "Username already exists!";
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, phone, role, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
        if($stmt->execute([$username, $password, $full_name, $email, $phone, $role])) {
            $success = "User created successfully!";
        } else {
            $error = "Failed to create user!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add New User</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'left_menu.php'; ?>
    <nav class="navbar navbar-dark bg-dark"><div class="container-fluid"><span class="navbar-brand">Add New User</span><a href="settings.php?tab=users" class="btn btn-light">Back to Users</a></div></nav>
    
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white"><h5>Create User Account</h5></div>
                    <div class="card-body">
                        <?php if($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
                        <?php if($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
                        
                        <form method="POST">
                            <div class="mb-3"><label>Username</label><input type="text" name="username" class="form-control" required></div>
                            <div class="mb-3"><label>Full Name</label><input type="text" name="full_name" class="form-control" required></div>
                            <div class="mb-3"><label>Email</label><input type="email" name="email" class="form-control"></div>
                            <div class="mb-3"><label>Phone</label><input type="text" name="phone" class="form-control"></div>
                            <div class="mb-3"><label>Password</label><input type="password" name="password" class="form-control" required></div>
                            <div class="mb-3"><label>Role</label>
                                <select name="role" class="form-control" required>
                                    <option value="super_admin">Super Admin</option>
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
                            <button type="submit" class="btn btn-primary w-100">Create User</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
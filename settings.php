<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
if($user['role'] != 'super_admin') { redirect('dashboard.php'); }

$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'general';

// Update general settings
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_general'])) {
    $company_name = $_POST['company_name'];
    $company_phone = $_POST['company_phone'];
    $company_email = $_POST['company_email'];
    $company_address = $_POST['company_address'];
    $vat_reg_no = $_POST['vat_reg_no'];
    
    $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
    $stmt->execute([$company_name, 'company_name']);
    $stmt->execute([$company_phone, 'company_phone']);
    $stmt->execute([$company_email, 'company_email']);
    $stmt->execute([$company_address, 'company_address']);
    $stmt->execute([$vat_reg_no, 'vat_reg_no']);
    $success = "General settings updated!";
}

// Add/Edit Nozzle
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_nozzle'])) {
    $nozzle_name = $_POST['nozzle_name'];
    $tank_id = $_POST['tank_id'];
    
    $stmt = $pdo->prepare("INSERT INTO nozzles (nozzle_name, tank_id, is_active) VALUES (?, ?, 1)");
    if($stmt->execute([$nozzle_name, $tank_id])) {
        $success = "Nozzle added successfully!";
    } else {
        $error = "Failed to add nozzle";
    }
}

// Add/Edit Tank
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_tank'])) {
    $tank_name = $_POST['tank_name'];
    $product_id = $_POST['product_id'];
    $capacity_liters = $_POST['capacity_liters'];
    $current_stock = $_POST['current_stock'];
    $calibration_factor = $_POST['calibration_factor'];
    
    $stmt = $pdo->prepare("INSERT INTO tanks (tank_name, product_id, capacity_liters, current_stock_liters, calibration_factor) VALUES (?, ?, ?, ?, ?)");
    if($stmt->execute([$tank_name, $product_id, $capacity_liters, $current_stock, $calibration_factor])) {
        $success = "Tank added successfully!";
    } else {
        $error = "Failed to add tank";
    }
}

// Update fuel price
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_price'])) {
    $product_id = $_POST['product_id'];
    $unit_price = $_POST['unit_price'];
    $purchase_rate = $_POST['purchase_rate'];
    
    $stmt = $pdo->prepare("UPDATE fuel_products SET unit_price = ?, purchase_rate = ? WHERE id = ?");
    if($stmt->execute([$unit_price, $purchase_rate, $product_id])) {
        $success = "Price updated successfully!";
    }
}

// Get data
$tanks = $pdo->query("SELECT t.*, p.product_name FROM tanks t JOIN fuel_products p ON t.product_id = p.id")->fetchAll();
$nozzles = $pdo->query("SELECT n.*, t.tank_name, p.product_name FROM nozzles n JOIN tanks t ON n.tank_id = t.id JOIN fuel_products p ON t.product_id = p.id")->fetchAll();
$products = $pdo->query("SELECT * FROM fuel_products")->fetchAll();
$shifts = $pdo->query("SELECT * FROM shifts")->fetchAll();

// Get current settings
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
while($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
</head>
<body>
    <?php include 'left_menu.php'; ?>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand">System Settings</span>
            <a href="dashboard.php" class="btn btn-light">Back to Dashboard</a>
        </div>
    </nav>
    
    <div class="container mt-3">
        <?php if($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
        <?php if($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
        
        <ul class="nav nav-tabs">
            <li class="nav-item"><a class="nav-link <?php echo $active_tab=='general'?'active':''; ?>" href="?tab=general">General Settings</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $active_tab=='tanks'?'active':''; ?>" href="?tab=tanks">Tanks</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $active_tab=='nozzles'?'active':''; ?>" href="?tab=nozzles">Nozzles</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $active_tab=='prices'?'active':''; ?>" href="?tab=prices">Fuel Prices</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $active_tab=='shifts'?'active':''; ?>" href="?tab=shifts">Shifts</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $active_tab=='users'?'active':''; ?>" href="?tab=users">Users</a></li>
        </ul>
        
        <!-- General Settings -->
        <?php if($active_tab == 'general'): ?>
        <div class="card mt-3">
            <div class="card-header bg-primary text-white"><h5>Company Information</h5></div>
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6"><label>Company Name</label><input type="text" name="company_name" class="form-control" value="<?php echo $settings['company_name'] ?? 'FF Enterprise'; ?>" required></div>
                        <div class="col-md-6"><label>Phone</label><input type="text" name="company_phone" class="form-control" value="<?php echo $settings['company_phone'] ?? '+8801234567890'; ?>"></div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-6"><label>Email</label><input type="email" name="company_email" class="form-control" value="<?php echo $settings['company_email'] ?? 'info@ffenterprise.com'; ?>"></div>
                        <div class="col-md-6"><label>VAT Registration No</label><input type="text" name="vat_reg_no" class="form-control" value="<?php echo $settings['vat_reg_no'] ?? '123456789'; ?>"></div>
                    </div>
                    <div class="mt-2"><label>Address</label><textarea name="company_address" class="form-control" rows="2"><?php echo $settings['company_address'] ?? 'Dhaka, Bangladesh'; ?></textarea></div>
                    <button type="submit" name="save_general" class="btn btn-primary mt-3">Save Settings</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Tanks Management -->
        <?php if($active_tab == 'tanks'): ?>
        <div class="row mt-3">
            <div class="col-md-5">
                <div class="card"><div class="card-header bg-success text-white"><h5>Add New Tank</h5></div><div class="card-body">
                    <form method="POST">
                        <div class="mb-2"><label>Tank Name</label><input type="text" name="tank_name" class="form-control" required></div>
                        <div class="mb-2"><label>Product</label><select name="product_id" class="form-control" required><?php foreach($products as $p){ echo "<option value='{$p['id']}'>{$p['product_name']}</option>"; } ?></select></div>
                        <div class="mb-2"><label>Capacity (Liters)</label><input type="number" name="capacity_liters" class="form-control" step="0.01" required></div>
                        <div class="mb-2"><label>Current Stock (Liters)</label><input type="number" name="current_stock" class="form-control" step="0.01" value="0"></div>
                        <div class="mb-2"><label>Calibration Factor (L/cm)</label><input type="number" name="calibration_factor" class="form-control" step="0.0001" value="1"></div>
                        <button type="submit" name="save_tank" class="btn btn-success w-100">Add Tank</button>
                    </form>
                </div></div>
            </div>
            <div class="col-md-7">
                <div class="card"><div class="card-header bg-info text-white"><h5>Existing Tanks</h5></div><div class="card-body">
                    <table class="table table-bordered" id="tanksTable"><thead><tr><th>Name</th><th>Product</th><th>Capacity</th><th>Current Stock</th><th>Calibration</th></tr></thead>
                    <tbody><?php foreach($tanks as $t){ echo "<tr><td>{$t['tank_name']}</td><td>{$t['product_name']}</td><td>{$t['capacity_liters']}L</td><td>{$t['current_stock_liters']}L</td><td>{$t['calibration_factor']}</td></tr>"; } ?></tbody>
                    </table>
                </div></div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Nozzles Management -->
        <?php if($active_tab == 'nozzles'): ?>
        <div class="row mt-3">
            <div class="col-md-5">
                <div class="card"><div class="card-header bg-warning"><h5>Add New Nozzle</h5></div><div class="card-body">
                    <form method="POST">
                        <div class="mb-2"><label>Nozzle Name</label><input type="text" name="nozzle_name" class="form-control" required placeholder="Nozzle-01"></div>
                        <div class="mb-2"><label>Select Tank</label><select name="tank_id" class="form-control" required><?php foreach($tanks as $t){ echo "<option value='{$t['id']}'>{$t['tank_name']} ({$t['product_name']})</option>"; } ?></select></div>
                        <button type="submit" name="save_nozzle" class="btn btn-warning w-100">Add Nozzle</button>
                    </form>
                </div></div>
            </div>
            <div class="col-md-7">
                <div class="card"><div class="card-header bg-info text-white"><h5>Existing Nozzles</h5></div><div class="card-body">
                    <table class="table table-bordered" id="nozzlesTable"><thead><tr><th>Nozzle</th><th>Tank</th><th>Product</th><th>Status</th></tr></thead>
                    <tbody><?php foreach($nozzles as $n){ echo "<tr><td>{$n['nozzle_name']}</td><td>{$n['tank_name']}</td><td>{$n['product_name']}</td><td><span class='badge bg-success'>Active</span></td></tr>"; } ?></tbody>
                    </table>
                </div></div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Fuel Prices -->
        <?php if($active_tab == 'prices'): ?>
        <div class="card mt-3">
            <div class="card-header bg-danger text-white"><h5>Fuel Price Configuration</h5></div>
            <div class="card-body">
                <form method="POST">
                    <table class="table table-bordered">
                        <thead><tr><th>Product</th><th>Selling Price (৳/L)</th><th>Purchase Rate (৳/L)</th><th>VAT %</th><th>Tax %</th></tr></thead>
                        <tbody>
                            <?php foreach($products as $p): ?>
                            <tr>
                                <td><?php echo $p['product_name']; ?><input type="hidden" name="product_id[]" value="<?php echo $p['id']; ?>"></td>
                                <td><input type="number" name="unit_price[]" class="form-control" step="0.01" value="<?php echo $p['unit_price']; ?>" required></td>
                                <td><input type="number" name="purchase_rate[]" class="form-control" step="0.01" value="<?php echo $p['purchase_rate']; ?>" required></td>
                                <td><input type="number" name="vat[]" class="form-control" step="0.01" value="<?php echo $p['vat_percentage']; ?>" readonly></td>
                                <td><input type="number" name="tax[]" class="form-control" step="0.01" value="<?php echo $p['tax_percentage']; ?>" readonly></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="submit" name="update_price" class="btn btn-danger">Update Prices</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Shifts Management -->
        <?php if($active_tab == 'shifts'): ?>
        <div class="card mt-3">
            <div class="card-header bg-secondary text-white"><h5>Shift Configuration</h5></div>
            <div class="card-body">
                <table class="table table-bordered">
                    <thead><tr><th>Shift Name</th><th>Start Time</th><th>End Time</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach($shifts as $s): ?>
                        <tr>
                            <td><?php echo $s['shift_name']; ?></td>
                            <td><?php echo $s['start_time']; ?></td>
                            <td><?php echo $s['end_time']; ?></td>
                            <td><span class="badge bg-success">Active</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Users Management -->
        <?php if($active_tab == 'users'): ?>
        <div class="card mt-3">
            <div class="card-header bg-dark text-white"><h5>System Users</h5></div>
            <div class="card-body">
                <?php $users = $pdo->query("SELECT * FROM users ORDER BY role")->fetchAll(); ?>
                <table class="table table-bordered" id="usersTable">
                    <thead><tr><th>Username</th><th>Full Name</th><th>Role</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach($users as $u): ?>
                        <tr>
                            <td><?php echo $u['username']; ?></td>
                            <td><?php echo $u['full_name']; ?></td>
                            <td><?php echo ucfirst(str_replace('_',' ',$u['role'])); ?></td>
                            <td><span class="badge bg-<?php echo $u['is_active']?'success':'danger'; ?>"><?php echo $u['is_active']?'Active':'Inactive'; ?></span></td>
                            <td><a href="reset_password.php?id=<?php echo $u['id']; ?>" class="btn btn-sm btn-warning">Reset Password</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#addUserModal">Add New User</button>
            </div>
        </div>
        
        <!-- Add User Modal -->
        <div class="modal fade" id="addUserModal"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-dark text-white"><h5>Add New User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><form method="POST" action="add_user.php"><div class="mb-2"><label>Username</label><input type="text" name="username" class="form-control" required></div><div class="mb-2"><label>Full Name</label><input type="text" name="full_name" class="form-control" required></div><div class="mb-2"><label>Password</label><input type="password" name="password" class="form-control" required></div><div class="mb-2"><label>Role</label><select name="role" class="form-control"><option value="super_admin">Super Admin</option><option value="owner">Owner</option><option value="accountant">Accountant</option><option value="station_manager">Station Manager</option><option value="cashier">Cashier</option><option value="nozzle_operator">Nozzle Operator</option><option value="hr_officer">HR Officer</option><option value="store_keeper">Store Keeper</option><option value="auditor">Auditor</option></select></div><button type="submit" class="btn btn-dark w-100">Create User</button></form></div></div></div></div>
        <?php endif; ?>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>$(document).ready(function(){$('#tanksTable, #nozzlesTable, #usersTable').DataTable();});</script>
</body>
</html>
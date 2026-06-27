<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();

// Only Super Admin can access user management
if($user['role'] != 'super_admin') { 
    redirect('dashboard.php'); 
}

$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'general';

// Get currency symbol
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
while($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$currency = $settings['currency_symbol'] ?? 'BDT';

// Update general settings
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_general'])) {
    $company_name = $_POST['company_name'];
    $company_phone = $_POST['company_phone'];
    $company_email = $_POST['company_email'];
    $company_address = $_POST['company_address'];
    $vat_reg_no = $_POST['vat_reg_no'];
    $currency_symbol = $_POST['currency_symbol'] ?? 'BDT';
    
    $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
    $stmt->execute([$company_name, 'company_name']);
    $stmt->execute([$company_phone, 'company_phone']);
    $stmt->execute([$company_email, 'company_email']);
    $stmt->execute([$company_address, 'company_address']);
    $stmt->execute([$vat_reg_no, 'vat_reg_no']);
    $stmt->execute([$currency_symbol, 'currency_symbol']);
    $success = "✅ General settings updated!";
}

// =============================================
// FIXED: Update fuel prices
// =============================================
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_prices'])) {
    $product_ids = $_POST['product_id'] ?? [];
    $unit_prices = $_POST['unit_price'] ?? [];
    $purchase_rates = $_POST['purchase_rate'] ?? [];
    
    try {
        $pdo->beginTransaction();
        
        for($i = 0; $i < count($product_ids); $i++) {
            $product_id = $product_ids[$i];
            $unit_price = floatval($unit_prices[$i] ?? 0);
            $purchase_rate = floatval($purchase_rates[$i] ?? 0);
            
            $stmt = $pdo->prepare("UPDATE fuel_products SET unit_price = ?, purchase_rate = ? WHERE id = ?");
            $stmt->execute([$unit_price, $purchase_rate, $product_id]);
        }
        
        $pdo->commit();
        $success = "✅ All prices updated successfully!";
        
        // Refresh products data
        $products = $pdo->query("SELECT * FROM fuel_products")->fetchAll();
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Add/Edit Nozzle
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_nozzle'])) {
    $nozzle_name = $_POST['nozzle_name'];
    $tank_id = $_POST['tank_id'];
    $is_pipeline = isset($_POST['is_pipeline']) ? 1 : 0;
    $unit_type = $_POST['unit_type'] ?? 'liters';
    
    $stmt = $pdo->prepare("INSERT INTO nozzles (nozzle_name, tank_id, is_active, is_pipeline, unit_type) VALUES (?, ?, 1, ?, ?)");
    if($stmt->execute([$nozzle_name, $tank_id, $is_pipeline, $unit_type])) {
        $success = "✅ Nozzle added successfully!";
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
        $success = "✅ Tank added successfully!";
    } else {
        $error = "Failed to add tank";
    }
}

// Update User
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_user'])) {
    $user_id = $_POST['user_id'];
    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $role = $_POST['role'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, role = ?, is_active = ? WHERE id = ?");
    if($stmt->execute([$full_name, $email, $phone, $role, $is_active, $user_id])) {
        $success = "✅ User updated successfully!";
    } else {
        $error = "Failed to update user";
    }
}

// Delete User
if(isset($_GET['delete_user'])) {
    $delete_id = $_GET['delete_user'];
    
    // Prevent deleting own account
    if($delete_id == $user['id']) {
        $error = "You cannot delete your own account!";
    } else {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        if($stmt->execute([$delete_id])) {
            $success = "✅ User deleted successfully!";
        } else {
            $error = "Failed to delete user";
        }
    }
}

// Toggle User Status (Activate/Deactivate)
if(isset($_GET['toggle_status'])) {
    $toggle_id = $_GET['toggle_status'];
    
    // Prevent toggling own account status
    if($toggle_id == $user['id']) {
        $error = "You cannot change your own status!";
    } else {
        $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
        if($stmt->execute([$toggle_id])) {
            $success = "✅ User status updated successfully!";
        } else {
            $error = "Failed to update user status";
        }
    }
}

// Get data
$tanks = $pdo->query("SELECT t.*, p.product_name FROM tanks t JOIN fuel_products p ON t.product_id = p.id")->fetchAll();
$nozzles = $pdo->query("SELECT n.*, t.tank_name, p.product_name FROM nozzles n JOIN tanks t ON n.tank_id = t.id JOIN fuel_products p ON t.product_id = p.id")->fetchAll();
$products = $pdo->query("SELECT * FROM fuel_products")->fetchAll();
$shifts = $pdo->query("SELECT * FROM shift_schedule")->fetchAll();

// Get current settings
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
while($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get user for editing
$edit_user = null;
if(isset($_GET['edit_user'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_GET['edit_user']]);
    $edit_user = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .main-content {
            margin-left: 280px;
            padding: 20px;
            transition: margin-left 0.3s;
        }
        
        .nav-tabs {
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 20px;
            background: transparent;
        }
        
        .nav-tabs .nav-item {
            margin-bottom: -2px;
        }
        
        .nav-tabs .nav-link {
            color: #6c757d;
            font-weight: 500;
            padding: 12px 20px;
            border: none;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
            background: transparent;
        }
        
        .nav-tabs .nav-link:hover {
            color: #667eea;
            border-bottom-color: #667eea;
            background: transparent;
        }
        
        .nav-tabs .nav-link.active {
            color: #667eea;
            background: transparent;
            border-bottom: 2px solid #667eea;
            font-weight: 600;
        }
        
        .nav-tabs .nav-link i {
            margin-right: 8px;
        }
        
        .user-status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        .status-active { background: #28a745; color: white; }
        .status-inactive { background: #dc3545; color: white; }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-cogs"></i> System Settings</h2>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
            
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
            
            <!-- Tabs -->
            <ul class="nav nav-tabs">
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'general' ? 'active' : ''; ?>" href="?tab=general">
                        <i class="fas fa-building"></i> General Settings
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'tanks' ? 'active' : ''; ?>" href="?tab=tanks">
                        <i class="fas fa-warehouse"></i> Tanks
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'nozzles' ? 'active' : ''; ?>" href="?tab=nozzles">
                        <i class="fas fa-oil-can"></i> Nozzles
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'prices' ? 'active' : ''; ?>" href="?tab=prices">
                        <i class="fas fa-tags"></i> Fuel Prices
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'shifts' ? 'active' : ''; ?>" href="?tab=shifts">
                        <i class="fas fa-clock"></i> Shifts
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'users' ? 'active' : ''; ?>" href="?tab=users">
                        <i class="fas fa-users"></i> Users
                    </a>
                </li>
            </ul>
            
            <!-- General Settings Tab -->
            <?php if($active_tab == 'general'): ?>
            <div class="card mt-3">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-building"></i> Company Information</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <label>Company Name</label>
                                <input type="text" name="company_name" class="form-control" value="<?php echo $settings['company_name'] ?? 'FF Enterprise'; ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label>Phone</label>
                                <input type="text" name="company_phone" class="form-control" value="<?php echo $settings['company_phone'] ?? '+8801234567890'; ?>">
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label>Email</label>
                                <input type="email" name="company_email" class="form-control" value="<?php echo $settings['company_email'] ?? 'info@ffenterprise.com'; ?>">
                            </div>
                            <div class="col-md-6">
                                <label>VAT Registration No</label>
                                <input type="text" name="vat_reg_no" class="form-control" value="<?php echo $settings['vat_reg_no'] ?? '123456789'; ?>">
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label>Currency Symbol</label>
                                <input type="text" name="currency_symbol" class="form-control" value="<?php echo $settings['currency_symbol'] ?? 'BDT'; ?>" required>
                            </div>
                        </div>
                        <div class="mt-2">
                            <label>Address</label>
                            <textarea name="company_address" class="form-control" rows="2"><?php echo $settings['company_address'] ?? 'Dhaka, Bangladesh'; ?></textarea>
                        </div>
                        <button type="submit" name="save_general" class="btn btn-primary mt-3">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Tanks Management Tab -->
            <?php if($active_tab == 'tanks'): ?>
            <div class="row mt-3">
                <div class="col-md-5">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="fas fa-plus"></i> Add New Tank</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-2"><label>Tank Name</label><input type="text" name="tank_name" class="form-control" required></div>
                                <div class="mb-2"><label>Product</label><select name="product_id" class="form-control" required><option value="">Select Product</option><?php foreach($products as $p){ echo "<option value='{$p['id']}'>{$p['product_name']}</option>"; } ?></select></div>
                                <div class="mb-2"><label>Capacity (Liters)</label><input type="number" name="capacity_liters" class="form-control" step="0.01" required></div>
                                <div class="mb-2"><label>Current Stock (Liters)</label><input type="number" name="current_stock" class="form-control" step="0.01" value="0"></div>
                                <div class="mb-2"><label>Calibration Factor (L/cm)</label><input type="number" name="calibration_factor" class="form-control" step="0.0001" value="1"></div>
                                <button type="submit" name="save_tank" class="btn btn-success w-100">Add Tank</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5><i class="fas fa-list"></i> Existing Tanks</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="tanksTable">
                                    <thead class="table-dark"><tr><th>Name</th><th>Product</th><th>Capacity</th><th>Current Stock</th><th>Calibration</th></tr></thead>
                                    <tbody><?php foreach($tanks as $t){ echo "<tr><td>{$t['tank_name']}</td><td>{$t['product_name']}</td><td>{$t['capacity_liters']}L</td><td>{$t['current_stock_liters']}L</td><td>{$t['calibration_factor']}</td></tr>"; } ?></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Nozzles Management Tab -->
            <?php if($active_tab == 'nozzles'): ?>
            <div class="row mt-3">
                <div class="col-md-5">
                    <div class="card">
                        <div class="card-header bg-warning text-dark">
                            <h5><i class="fas fa-plus"></i> Add New Nozzle</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-2"><label>Nozzle Name</label><input type="text" name="nozzle_name" class="form-control" required placeholder="Nozzle-01"></div>
                                <div class="mb-2"><label>Select Tank</label><select name="tank_id" class="form-control" required><option value="">Select Tank</option><?php foreach($tanks as $t){ echo "<option value='{$t['id']}'>{$t['tank_name']} ({$t['product_name']})</option>"; } ?></select></div>
                                <div class="mb-2">
                                    <label>Unit Type</label>
                                    <select name="unit_type" class="form-control">
                                        <option value="liters">Liters (L)</option>
                                        <option value="kilograms">Kilograms (kg)</option>
                                    </select>
                                </div>
                                <div class="mb-2 form-check">
                                    <input type="checkbox" name="is_pipeline" class="form-check-input" value="1">
                                    <label class="form-check-label">Pipeline Nozzle (CNG)</label>
                                </div>
                                <button type="submit" name="save_nozzle" class="btn btn-warning w-100">Add Nozzle</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5><i class="fas fa-list"></i> Existing Nozzles</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="nozzlesTable">
                                    <thead class="table-dark"><tr><th>Nozzle</th><th>Tank</th><th>Product</th><th>Type</th><th>Status</th></tr></thead>
                                    <tbody><?php foreach($nozzles as $n){ echo "<tr><td>{$n['nozzle_name']}</td><td>{$n['tank_name']}</td><td>{$n['product_name']}</td><td>".($n['is_pipeline'] ? 'Pipeline' : 'Tank')."</td><td><span class='badge bg-success'>Active</span></td></tr>"; } ?></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- ============================================= -->
            <!-- FUEL PRICES TAB - FIXED -->
            <!-- ============================================= -->
            <?php if($active_tab == 'prices'): ?>
            <div class="card mt-3">
                <div class="card-header bg-danger text-white">
                    <h5><i class="fas fa-tags"></i> Fuel Price Configuration</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Note:</strong> Update selling prices and purchase rates for all products. 
                        VAT and Tax are configured separately and cannot be changed here.
                    </div>
                    <form method="POST">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Product</th>
                                        <th>Selling Price (<?php echo $currency; ?>/L)</th>
                                        <th>Purchase Rate (<?php echo $currency; ?>/L)</th>
                                        <th>VAT %</th>
                                        <th>Tax %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($products as $p): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($p['product_name']); ?></strong>
                                            <input type="hidden" name="product_id[]" value="<?php echo $p['id']; ?>">
                                        </td>
                                        <td>
                                            <input type="number" name="unit_price[]" class="form-control" step="0.01" 
                                                   value="<?php echo $p['unit_price']; ?>" required>
                                        </td>
                                        <td>
                                            <input type="number" name="purchase_rate[]" class="form-control" step="0.01" 
                                                   value="<?php echo $p['purchase_rate']; ?>" required>
                                        </td>
                                        <td>
                                            <input type="number" name="vat[]" class="form-control" step="0.01" 
                                                   value="<?php echo $p['vat_percentage']; ?>" readonly>
                                        </td>
                                        <td>
                                            <input type="number" name="tax[]" class="form-control" step="0.01" 
                                                   value="<?php echo $p['tax_percentage']; ?>" readonly>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" name="update_prices" class="btn btn-danger mt-3">
                            <i class="fas fa-save"></i> Update Prices
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Shifts Management Tab -->
            <?php if($active_tab == 'shifts'): ?>
            <div class="card mt-3">
                <div class="card-header bg-secondary text-white">
                    <h5><i class="fas fa-clock"></i> Shift Configuration</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="table-dark"><tr><th>Shift Name</th><th>Start Time</th><th>End Time</th><th>Status</th></tr></thead>
                            <tbody><?php foreach($shifts as $s): ?>
                            <tr><td><?php echo $s['shift_name']; ?></td><td><?php echo date('h:i A', strtotime($s['start_time'])); ?></td><td><?php echo date('h:i A', strtotime($s['end_time'])); ?></td><td><span class="badge bg-success">Active</span></td></tr>
                            <?php endforeach; ?></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Users Management Tab (Super Admin Only) -->
            <?php if($active_tab == 'users'): ?>
            <div class="card mt-3">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h5><i class="fas fa-users"></i> System Users</h5>
                    <a href="add_user.php" class="btn btn-light btn-sm">
                        <i class="fas fa-user-plus"></i> Add New User
                    </a>
                </div>
                <div class="card-body">
                    <?php $users = $pdo->query("SELECT * FROM users ORDER BY role")->fetchAll(); ?>
                    <div class="table-responsive">
                        <table class="table table-bordered" id="usersTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($users as $u): ?>
                                <tr>
                                    <td><?php echo $u['id']; ?></td>
                                    <td><strong><?php echo $u['username']; ?></strong></td>
                                    <td><?php echo $u['full_name']; ?></td>
                                    <td><?php echo $u['email'] ?: '-'; ?></td>
                                    <td><?php echo $u['phone'] ?: '-'; ?></td>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $u['role'])); ?></td>
                                    <td>
                                        <span class="user-status-badge <?php echo $u['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="?tab=users&edit_user=<?php echo $u['id']; ?>" class="btn btn-sm btn-warning" title="Edit User">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="?tab=users&toggle_status=<?php echo $u['id']; ?>" class="btn btn-sm <?php echo $u['is_active'] ? 'btn-secondary' : 'btn-success'; ?>" title="<?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?>" onclick="return confirm('<?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?> this user?')">
                                            <i class="fas <?php echo $u['is_active'] ? 'fa-ban' : 'fa-check-circle'; ?>"></i>
                                        </a>
                                        <?php if($u['id'] != $user['id']): ?>
                                        <a href="?tab=users&delete_user=<?php echo $u['id']; ?>" class="btn btn-sm btn-danger" title="Delete User" onclick="return confirm('Delete this user permanently? This action cannot be undone!')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="reset_password.php?id=<?php echo $u['id']; ?>" class="btn btn-sm btn-info" title="Reset Password">
                                            <i class="fas fa-key"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Edit User Modal -->
            <?php if($edit_user): ?>
            <div class="modal fade show" id="editUserModal" tabindex="-1" style="display: block; background: rgba(0,0,0,0.5);" aria-modal="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-warning text-white">
                            <h5><i class="fas fa-edit"></i> Edit User</h5>
                            <a href="?tab=users" class="btn-close btn-close-white"></a>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label>Username</label>
                                    <input type="text" class="form-control" value="<?php echo $edit_user['username']; ?>" disabled>
                                    <small class="text-muted">Username cannot be changed</small>
                                </div>
                                <div class="mb-3">
                                    <label>Full Name</label>
                                    <input type="text" name="full_name" class="form-control" value="<?php echo $edit_user['full_name']; ?>" required>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Email</label>
                                            <input type="email" name="email" class="form-control" value="<?php echo $edit_user['email']; ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Phone</label>
                                            <input type="text" name="phone" class="form-control" value="<?php echo $edit_user['phone']; ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label>Role</label>
                                    <select name="role" class="form-control" required>
                                        <option value="super_admin" <?php echo $edit_user['role'] == 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                                        <option value="owner" <?php echo $edit_user['role'] == 'owner' ? 'selected' : ''; ?>>Owner/Director</option>
                                        <option value="accountant" <?php echo $edit_user['role'] == 'accountant' ? 'selected' : ''; ?>>Accountant</option>
                                        <option value="station_manager" <?php echo $edit_user['role'] == 'station_manager' ? 'selected' : ''; ?>>Station Manager</option>
                                        <option value="cashier" <?php echo $edit_user['role'] == 'cashier' ? 'selected' : ''; ?>>Cashier</option>
                                        <option value="nozzle_operator" <?php echo $edit_user['role'] == 'nozzle_operator' ? 'selected' : ''; ?>>Nozzle Operator</option>
                                        <option value="hr_officer" <?php echo $edit_user['role'] == 'hr_officer' ? 'selected' : ''; ?>>HR Officer</option>
                                        <option value="store_keeper" <?php echo $edit_user['role'] == 'store_keeper' ? 'selected' : ''; ?>>Store Keeper</option>
                                        <option value="auditor" <?php echo $edit_user['role'] == 'auditor' ? 'selected' : ''; ?>>Auditor</option>
                                    </select>
                                </div>
                                <div class="mb-3 form-check">
                                    <input type="checkbox" name="is_active" class="form-check-input" value="1" <?php echo $edit_user['is_active'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label">Active (User can log in)</label>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <a href="?tab=users" class="btn btn-secondary">Cancel</a>
                                <button type="submit" name="update_user" class="btn btn-warning">Update User</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#tanksTable, #nozzlesTable, #usersTable').DataTable({
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
    </script>
</body>
</html>
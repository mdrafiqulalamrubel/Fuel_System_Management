<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$error = '';
$success = '';

// Handle nozzle updates
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_nozzle')) {
    $nozzle_id = $_POST['nozzle_id'];
    $tank_id = $_POST['tank_id'];
    $nozzle_name = $_POST['nozzle_name'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    try {
        $stmt = $pdo->prepare("UPDATE nozzles SET tank_id = ?, nozzle_name = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$tank_id, $nozzle_name, $is_active, $nozzle_id]);
        $success = "Nozzle mapping updated successfully!";
    } catch(Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get all tanks for dropdown
$tanks = $pdo->query("
    SELECT t.*, p.product_name 
    FROM tanks t
    JOIN fuel_products p ON t.product_id = p.id
    WHERE t.is_active = 1
    ORDER BY t.tank_name
")->fetchAll();

// Get all nozzles with current tank mapping
$nozzles = $pdo->query("
    SELECT n.*, 
           t.tank_name, 
           t.product_id,
           p.product_name as tank_product
    FROM nozzles n
    LEFT JOIN tanks t ON n.tank_id = t.id
    LEFT JOIN fuel_products p ON t.product_id = p.id
    ORDER BY n.nozzle_name
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Nozzle-Tank Mapping Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <h2><i class="fas fa-plug"></i> Nozzle to Tank Mapping</h2>
            <p class="text-muted">Configure which tank each nozzle draws fuel from</p>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-oil-can"></i> Nozzle Configuration
                </div>
                <div class="card-body">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Nozzle Name</th>
                                <th>Connected Tank</th>
                                <th>Tank Product</th>
                                <th>Tank Stock (Liters)</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($nozzles as $nozzle): ?>
                            <form method="POST">
                                <input type="hidden" name="nozzle_id" value="<?php echo $nozzle['id']; ?>">
                                <tr>
                                    <td>
                                        <input type="text" name="nozzle_name" class="form-control" 
                                               value="<?php echo htmlspecialchars($nozzle['nozzle_name']); ?>" required>
                                    </td>
                                    <td>
                                        <select name="tank_id" class="form-control" required>
                                            <option value="">-- Select Tank --</option>
                                            <?php foreach($tanks as $tank): ?>
                                                <option value="<?php echo $tank['id']; ?>" 
                                                    <?php echo ($nozzle['tank_id'] == $tank['id']) ? 'selected' : ''; ?>>
                                                    <?php echo $tank['tank_name']; ?> 
                                                    (<?php echo $tank['product_name']; ?> - 
                                                     <?php echo number_format($tank['current_stock_liters'], 0); ?> L)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <?php echo $nozzle['tank_product'] ?? 'Not assigned'; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        // Get stock from the selected tank
                                        $stock = 0;
                                        foreach($tanks as $tank) {
                                            if($tank['id'] == $nozzle['tank_id']) {
                                                $stock = $tank['current_stock_liters'];
                                                break;
                                            }
                                        }
                                        echo number_format($stock, 0) . ' L';
                                        ?>
                                    </td>
                                    <td>
                                        <div class="form-check form-switch">
                                            <input type="checkbox" name="is_active" class="form-check-input" 
                                                   value="1" <?php echo $nozzle['is_active'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label">Active</label>
                                        </div>
                                    </td>
                                    <td>
                                        <button type="submit" name="update_nozzle" class="btn btn-primary btn-sm">
                                            <i class="fas fa-save"></i> Update
                                        </button>
                                    </td>
                                </tr>
                            </form>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Summary Cards -->
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">Tanks Summary</div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <?php foreach($tanks as $tank): ?>
                                <tr>
                                    <td><?php echo $tank['tank_name']; ?></td>
                                    <td><?php echo $tank['product_name']; ?></td>
                                    <td class="text-end">
                                        <span class="badge bg-primary">
                                            <?php echo number_format($tank['current_stock_liters'], 0); ?> L
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">Mapping Rules</div>
                        <div class="card-body">
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item">
                                    <i class="fas fa-check-circle text-success"></i> 
                                    One nozzle can only connect to ONE tank
                                </li>
                                <li class="list-group-item">
                                    <i class="fas fa-check-circle text-success"></i> 
                                    Multiple nozzles can connect to the SAME tank
                                </li>
                                <li class="list-group-item">
                                    <i class="fas fa-check-circle text-success"></i> 
                                    Tank product determines what fuel the nozzle dispenses
                                </li>
                                <li class="list-group-item">
                                    <i class="fas fa-check-circle text-success"></i> 
                                    Inactive nozzles won't appear in POS
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
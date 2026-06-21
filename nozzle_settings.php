<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// ==================== PRODUCT MANAGEMENT ====================

// Add new product
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    $product_name = $_POST['product_name'];
    $product_code = isset($_POST['product_code']) ? $_POST['product_code'] : '';
    $unit_price = $_POST['unit_price'];
    $purchase_rate = isset($_POST['purchase_rate']) ? $_POST['purchase_rate'] : 0;
    $unit_type = isset($_POST['unit_type']) ? $_POST['unit_type'] : 'liters';
    $is_active = isset($_POST['is_active']) ? 1 : 1;
    
    $stmt = $pdo->prepare("
        INSERT INTO fuel_products (product_name, product_code, unit_price, purchase_rate, unit_type, is_active) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    if($stmt->execute([$product_name, $product_code, $unit_price, $purchase_rate, $unit_type, $is_active])) {
        $success = "Product added successfully!";
    } else {
        $error = "Failed to add product";
    }
}

// Update product
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_product'])) {
    $product_id = $_POST['product_id'];
    $product_name = $_POST['product_name'];
    $product_code = isset($_POST['product_code']) ? $_POST['product_code'] : '';
    $unit_price = $_POST['unit_price'];
    $purchase_rate = isset($_POST['purchase_rate']) ? $_POST['purchase_rate'] : 0;
    $unit_type = isset($_POST['unit_type']) ? $_POST['unit_type'] : 'liters';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    $stmt = $pdo->prepare("
        UPDATE fuel_products 
        SET product_name = ?, product_code = ?, unit_price = ?, purchase_rate = ?, unit_type = ?, is_active = ?
        WHERE id = ?
    ");
    
    if($stmt->execute([$product_name, $product_code, $unit_price, $purchase_rate, $unit_type, $is_active, $product_id])) {
        $success = "Product updated successfully!";
    } else {
        $error = "Failed to update product";
    }
}

// Delete product
if(isset($_GET['delete_product_id'])) {
    // Check if product is used in tanks
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tanks WHERE product_id = ?");
    $stmt->execute([$_GET['delete_product_id']]);
    $tank_count = $stmt->fetchColumn();
    
    if($tank_count > 0) {
        $error = "Cannot delete product. It is used in " . $tank_count . " tank(s).";
    } else {
        $stmt = $pdo->prepare("DELETE FROM fuel_products WHERE id = ?");
        if($stmt->execute([$_GET['delete_product_id']])) {
            $success = "Product deleted successfully!";
        } else {
            $error = "Failed to delete product";
        }
    }
}

// ==================== TANK MANAGEMENT ====================

// Add new tank
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_tank'])) {
    $tank_name = $_POST['tank_name'];
    $product_id = $_POST['product_id'];
    $capacity_liters = $_POST['capacity_liters'];
    $current_stock = isset($_POST['current_stock']) ? $_POST['current_stock'] : 0;
    $calibration_factor = isset($_POST['calibration_factor']) ? $_POST['calibration_factor'] : 1.0000;
    $is_active = isset($_POST['is_active']) ? 1 : 1;
    
    $stmt = $pdo->prepare("
        INSERT INTO tanks (tank_name, product_id, capacity_liters, current_stock_liters, calibration_factor, is_active) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    if($stmt->execute([$tank_name, $product_id, $capacity_liters, $current_stock, $calibration_factor, $is_active])) {
        $success = "Tank added successfully!";
    } else {
        $error = "Failed to add tank";
    }
}

// Update tank
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_tank'])) {
    $tank_id = $_POST['tank_id'];
    $tank_name = $_POST['tank_name'];
    $product_id = $_POST['product_id'];
    $capacity_liters = $_POST['capacity_liters'];
    $calibration_factor = isset($_POST['calibration_factor']) ? $_POST['calibration_factor'] : 1.0000;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    $stmt = $pdo->prepare("
        UPDATE tanks 
        SET tank_name = ?, product_id = ?, capacity_liters = ?, calibration_factor = ?, is_active = ?
        WHERE id = ?
    ");
    
    if($stmt->execute([$tank_name, $product_id, $capacity_liters, $calibration_factor, $is_active, $tank_id])) {
        $success = "Tank updated successfully!";
    } else {
        $error = "Failed to update tank";
    }
}

// Delete tank
if(isset($_GET['delete_tank_id'])) {
    // Check if tank has nozzles
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM nozzles WHERE tank_id = ?");
    $stmt->execute([$_GET['delete_tank_id']]);
    $nozzle_count = $stmt->fetchColumn();
    
    if($nozzle_count > 0) {
        $error = "Cannot delete tank. It has " . $nozzle_count . " nozzle(s) connected.";
    } else {
        $stmt = $pdo->prepare("DELETE FROM tanks WHERE id = ?");
        if($stmt->execute([$_GET['delete_tank_id']])) {
            $success = "Tank deleted successfully!";
        } else {
            $error = "Failed to delete tank";
        }
    }
}

// Update tank stock
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_stock'])) {
    $tank_id = $_POST['tank_id'];
    $current_stock = $_POST['current_stock'];
    
    $stmt = $pdo->prepare("UPDATE tanks SET current_stock_liters = ? WHERE id = ?");
    if($stmt->execute([$current_stock, $tank_id])) {
        $success = "Stock updated successfully!";
    } else {
        $error = "Failed to update stock";
    }
}

// ==================== NOZZLE MANAGEMENT ====================

// Add new nozzle (including pipeline CNG nozzles)
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_nozzle'])) {
    $nozzle_name = $_POST['nozzle_name'];
    $tank_id = isset($_POST['tank_id']) && $_POST['tank_id'] != '' ? $_POST['tank_id'] : null;
    $product_id = isset($_POST['product_id']) && $_POST['product_id'] != '' ? $_POST['product_id'] : null;
    $unit_type = isset($_POST['unit_type']) ? $_POST['unit_type'] : 'liters';
    $is_pipeline = isset($_POST['is_pipeline']) ? 1 : 0;
    $pipeline_source = isset($_POST['pipeline_source']) ? $_POST['pipeline_source'] : 'Titas Gas';
    $opening_meter = isset($_POST['opening_meter']) ? floatval($_POST['opening_meter']) : 0;
    $closing_meter = isset($_POST['closing_meter']) ? floatval($_POST['closing_meter']) : 0;
    
    // If pipeline, no tank_id needed, but product_id is optional
    if($is_pipeline) {
        $tank_id = null;
        // If no product_id set for pipeline, use null
        if($product_id === null || $product_id === '') {
            // Try to find CNG product
            $stmt = $pdo->query("SELECT id FROM fuel_products WHERE product_name LIKE '%CNG%' OR product_name LIKE '%Natural Gas%' LIMIT 1");
            $cng_product = $stmt->fetch();
            if($cng_product) {
                $product_id = $cng_product['id'];
            }
        }
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO nozzles (
            nozzle_name, tank_id, product_id, unit_type, 
            is_pipeline, pipeline_source, opening_meter, closing_meter, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    
    if($stmt->execute([$nozzle_name, $tank_id, $product_id, $unit_type, $is_pipeline, $pipeline_source, $opening_meter, $closing_meter])) {
        $success = "Nozzle added successfully!";
    } else {
        $error = "Failed to add nozzle";
    }
}

// Update nozzle
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_nozzle'])) {
    $nozzle_id = $_POST['nozzle_id'];
    $nozzle_name = $_POST['nozzle_name'];
    $tank_id = isset($_POST['tank_id']) && $_POST['tank_id'] != '' ? $_POST['tank_id'] : null;
    $product_id = isset($_POST['product_id']) && $_POST['product_id'] != '' ? $_POST['product_id'] : null;
    $unit_type = isset($_POST['unit_type']) ? $_POST['unit_type'] : 'liters';
    $is_pipeline = isset($_POST['is_pipeline']) ? 1 : 0;
    $pipeline_source = isset($_POST['pipeline_source']) ? $_POST['pipeline_source'] : 'Titas Gas';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if($is_pipeline) {
        $tank_id = null;
    }
    
    $stmt = $pdo->prepare("
        UPDATE nozzles 
        SET nozzle_name = ?, tank_id = ?, product_id = ?, unit_type = ?, 
            is_pipeline = ?, pipeline_source = ?, is_active = ?
        WHERE id = ?
    ");
    
    if($stmt->execute([$nozzle_name, $tank_id, $product_id, $unit_type, $is_pipeline, $pipeline_source, $is_active, $nozzle_id])) {
        $success = "Nozzle updated successfully!";
    } else {
        $error = "Failed to update nozzle";
    }
}

// Update CNG meter reading
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_meter'])) {
    $nozzle_id = $_POST['nozzle_id'];
    $opening_meter = floatval($_POST['opening_meter']);
    $closing_meter = floatval($_POST['closing_meter']);
    
    $stmt = $pdo->prepare("UPDATE nozzles SET opening_meter = ?, closing_meter = ? WHERE id = ?");
    if($stmt->execute([$opening_meter, $closing_meter, $nozzle_id])) {
        // Log meter reading history
        try {
            $units_sold = $closing_meter - $opening_meter;
            $stmt = $pdo->prepare("
                INSERT INTO cng_meter_readings (nozzle_id, reading_date, opening_meter, closing_meter, units_sold, created_by) 
                VALUES (?, CURDATE(), ?, ?, ?, ?)
            ");
            $stmt->execute([$nozzle_id, $opening_meter, $closing_meter, $units_sold, $user['id']]);
        } catch(Exception $e) {
            // Table might not exist, just continue
        }
        
        $success = "Meter reading updated successfully! Units sold: " . number_format($units_sold, 2) . " m³";
    } else {
        $error = "Failed to update meter reading";
    }
}

// Delete nozzle
if(isset($_GET['delete_nozzle_id'])) {
    $stmt = $pdo->prepare("DELETE FROM nozzles WHERE id = ?");
    if($stmt->execute([$_GET['delete_nozzle_id']])) {
        $success = "Nozzle deleted successfully!";
    }
}

// ==================== FETCH DATA ====================

// Get all products
$products = $pdo->query("SELECT * FROM fuel_products ORDER BY product_name")->fetchAll();

// Get all tanks
$tanks = $pdo->query("
    SELECT t.*, p.product_name 
    FROM tanks t 
    JOIN fuel_products p ON t.product_id = p.id 
    ORDER BY t.tank_name
")->fetchAll();

// Get all nozzles with product info
$nozzles = $pdo->query("
    SELECT 
        n.*,
        p.product_name,
        p.unit_price,
        t.tank_name
    FROM nozzles n
    LEFT JOIN fuel_products p ON n.product_id = p.id
    LEFT JOIN tanks t ON n.tank_id = t.id
    ORDER BY n.is_pipeline DESC, n.nozzle_name
")->fetchAll();

// Separate stats
$pipeline_nozzles = array_filter($nozzles, function($n) { return isset($n['is_pipeline']) && $n['is_pipeline'] == 1; });
$tank_nozzles = array_filter($nozzles, function($n) { return !isset($n['is_pipeline']) || $n['is_pipeline'] == 0; });

// Tank statistics
$total_tanks = count($tanks);
$total_stock = array_sum(array_column($tanks, 'current_stock_liters'));
$total_capacity = array_sum(array_column($tanks, 'capacity_liters'));
$fill_percent = $total_capacity > 0 ? ($total_stock / $total_capacity) * 100 : 0;

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';

// Get active tab from URL
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'tanks';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tank & Nozzle Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s;
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .stats-card i {
            font-size: 40px;
            opacity: 0.5;
            float: right;
        }
        .stats-card h3 {
            font-size: 28px;
            margin-bottom: 5px;
        }
        .stats-card p {
            margin: 0;
            opacity: 0.8;
        }
        .stats-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stats-card.blue { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stats-card.orange { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stats-card.purple { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .pipeline-row {
            background-color: #cce5ff !important;
        }
        .pipeline-row td:first-child {
            border-left: 4px solid #0d6efd;
        }
        .badge-pipeline {
            background: #0d6efd;
            color: white;
            padding: 5px 12px;
        }
        .badge-tank {
            background: #28a745;
            color: white;
            padding: 5px 12px;
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #0d6efd;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .info-box i { color: #0d6efd; }
        .meter-display {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            font-size: 16px;
        }
        .nav-tabs .nav-link.active {
            font-weight: bold;
            border-bottom: 3px solid #0d6efd;
        }
        .tab-content {
            padding-top: 20px;
        }

        /* Fix Tab Visibility */
        .nav-tabs .nav-link {
            color: #495057 !important;
            font-weight: 600;
        }

        .nav-tabs .nav-link.active {
            color: #0d6efd !important;
            font-weight: 700;
            background: #fff !important;
            border-bottom: 3px solid #0d6efd;
        }

        .nav-tabs .nav-link:not(.active) {
            color: #6c757d !important;
            background: #e9ecef;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <h2><i class="fas fa-cogs"></i> Settings</h2>
            
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
            <ul class="nav nav-tabs" id="settingsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $active_tab == 'products' ? 'active' : ''; ?>" 
                            data-bs-toggle="tab" data-bs-target="#productsTab" type="button" role="tab">
                        <i class="fas fa-box"></i> Products
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $active_tab == 'tanks' ? 'active' : ''; ?>" 
                            data-bs-toggle="tab" data-bs-target="#tanksTab" type="button" role="tab">
                        <i class="fas fa-warehouse"></i> Tanks
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $active_tab == 'nozzles' ? 'active' : ''; ?>" 
                            data-bs-toggle="tab" data-bs-target="#nozzlesTab" type="button" role="tab">
                        <i class="fas fa-oil-can"></i> Nozzles & Pipelines
                    </button>
                </li>
            </ul>
            
            <div class="tab-content">
                <!-- ==================== PRODUCTS TAB ==================== -->
                <div class="tab-pane fade <?php echo $active_tab == 'products' ? 'show active' : ''; ?>" id="productsTab" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mt-3 mb-3">
                        <h4><i class="fas fa-box"></i> Fuel Products</h4>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                            <i class="fas fa-plus"></i> Add Product
                        </button>
                    </div>
                    
                    <div class="card">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="productsTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>SL</th>
                                            <th>Product Code</th>
                                            <th>Product Name</th>
                                            <th>Unit Type</th>
                                            <th class="text-end">Unit Price (<?php echo $currency; ?>)</th>
                                            <th class="text-end">Purchase Rate (<?php echo $currency; ?>)</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $sl=1; foreach($products as $p): ?>
                                        <tr>
                                            <td class="text-center"><?php echo $sl++; ?></td>
                                            <td><?php echo htmlspecialchars($p['product_code'] ?? 'N/A'); ?></td>
                                            <td><strong><?php echo htmlspecialchars($p['product_name']); ?></strong></td>
                                            <td><?php echo $p['unit_type'] ?? 'liters'; ?></td>
                                            <td class="text-end"><?php echo number_format($p['unit_price'], 2); ?></td>
                                            <td class="text-end"><?php echo number_format($p['purchase_rate'] ?? 0, 2); ?></td>
                                            <td class="text-center">
                                                <?php if($p['is_active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <button class="btn btn-sm btn-warning" onclick="editProduct(<?php echo $p['id']; ?>, '<?php echo addslashes($p['product_name']); ?>', <?php echo $p['unit_price']; ?>, <?php echo $p['purchase_rate'] ?? 0; ?>, '<?php echo $p['product_code'] ?? ''; ?>', '<?php echo $p['unit_type'] ?? 'liters'; ?>', <?php echo $p['is_active']; ?>)">
                                                    <i class="fas fa-pen"></i>
                                                </button>
                                                <a href="?tab=products&delete_product_id=<?php echo $p['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this product?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ==================== TANKS TAB ==================== -->
                <div class="tab-pane fade <?php echo $active_tab == 'tanks' ? 'show active' : ''; ?>" id="tanksTab" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mt-3 mb-3">
                        <h4><i class="fas fa-warehouse"></i> Fuel Tanks</h4>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTankModal">
                            <i class="fas fa-plus"></i> Add Tank
                        </button>
                    </div>
                    
                    <!-- Tank Statistics -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="stats-card">
                                <i class="fas fa-warehouse"></i>
                                <h3><?php echo $total_tanks; ?></h3>
                                <p>Total Tanks</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card green">
                                <i class="fas fa-chart-line"></i>
                                <h3><?php echo number_format($total_stock, 2); ?> L</h3>
                                <p>Total Stock</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card orange">
                                <i class="fas fa-percent"></i>
                                <h3><?php echo round($fill_percent, 1); ?>%</h3>
                                <p>Average Fill Level</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card blue">
                                <i class="fas fa-expand"></i>
                                <h3><?php echo number_format($total_capacity, 0); ?> L</h3>
                                <p>Total Capacity</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="tanksTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>SL</th>
                                            <th>Tank Name</th>
                                            <th>Product</th>
                                            <th class="text-end">Capacity (L)</th>
                                            <th class="text-end">Current Stock (L)</th>
                                            <th>Fill %</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $sl=1; foreach($tanks as $t): 
                                            $fill = $t['capacity_liters'] > 0 ? ($t['current_stock_liters'] / $t['capacity_liters']) * 100 : 0;
                                            $bar_color = $fill < 20 ? 'bg-danger' : ($fill > 80 ? 'bg-success' : 'bg-warning');
                                        ?>
                                        <tr>
                                            <td class="text-center"><?php echo $sl++; ?></td>
                                            <td><strong><?php echo htmlspecialchars($t['tank_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($t['product_name']); ?></td>
                                            <td class="text-end"><?php echo number_format($t['capacity_liters'], 0); ?></td>
                                            <td class="text-end"><?php echo number_format($t['current_stock_liters'], 2); ?></td>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar <?php echo $bar_color; ?>" style="width: <?php echo min($fill, 100); ?>%">
                                                        <?php echo round($fill, 1); ?>%
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <?php if($t['is_active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <button class="btn btn-sm btn-info" onclick="updateStock(<?php echo $t['id']; ?>, '<?php echo addslashes($t['tank_name']); ?>', <?php echo $t['current_stock_liters']; ?>)">
                                                    <i class="fas fa-edit"></i> Stock
                                                </button>
                                                <button class="btn btn-sm btn-warning" onclick="editTank(<?php echo $t['id']; ?>, '<?php echo addslashes($t['tank_name']); ?>', <?php echo $t['product_id']; ?>, <?php echo $t['capacity_liters']; ?>, <?php echo $t['calibration_factor']; ?>, <?php echo $t['is_active']; ?>)">
                                                    <i class="fas fa-pen"></i>
                                                </button>
                                                <a href="?tab=tanks&delete_tank_id=<?php echo $t['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this tank?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ==================== NOZZLES TAB ==================== -->
                <div class="tab-pane fade <?php echo $active_tab == 'nozzles' ? 'show active' : ''; ?>" id="nozzlesTab" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mt-3 mb-3">
                        <h4><i class="fas fa-oil-can"></i> Nozzles & Pipelines</h4>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addNozzleModal">
                            <i class="fas fa-plus"></i> Add Nozzle / Pipeline
                        </button>
                    </div>
                    
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>CNG Pipeline Nozzles:</strong> CNG comes from government pipeline (Titas Gas). 
                        Pipeline nozzles are not connected to any tank. Sales are tracked by meter readings.
                        <br>
                        <small class="text-muted">Total Pipeline Nozzles: <?php echo count($pipeline_nozzles); ?> | Total Tank Nozzles: <?php echo count($tank_nozzles); ?></small>
                    </div>
                    
                    <div class="card">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="nozzlesTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>SL</th>
                                            <th>Nozzle Name</th>
                                            <th>Type</th>
                                            <th>Product</th>
                                            <th>Connected To</th>
                                            <th>Unit</th>
                                            <th class="text-end">Opening Meter</th>
                                            <th class="text-end">Closing Meter</th>
                                            <th class="text-end">Total Sold</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $sl=1; foreach($nozzles as $n): 
                                            $is_pipeline = isset($n['is_pipeline']) && $n['is_pipeline'] == 1;
                                            $total_sold = (isset($n['closing_meter']) ? $n['closing_meter'] : 0) - (isset($n['opening_meter']) ? $n['opening_meter'] : 0);
                                        ?>
                                        <tr class="<?php echo $is_pipeline ? 'pipeline-row' : ''; ?>">
                                            <td class="text-center"><?php echo $sl++; ?></td>
                                            <td><strong><?php echo htmlspecialchars($n['nozzle_name']); ?></strong></td>
                                            <td>
                                                <?php if($is_pipeline): ?>
                                                    <span class="badge badge-pipeline"><i class="fas fa-pipe"></i> Pipeline</span>
                                                <?php else: ?>
                                                    <span class="badge badge-tank"><i class="fas fa-oil-can"></i> Tank Nozzle</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($n['product_name'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php if($is_pipeline): ?>
                                                    <span class="text-primary"><i class="fas fa-pipe"></i> <?php echo $n['pipeline_source'] ?? 'Titas Gas'; ?></span>
                                                <?php else: ?>
                                                    <span class="text-success"><i class="fas fa-warehouse"></i> <?php echo $n['tank_name'] ?? 'N/A'; ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $n['unit_type'] ?? 'liters'; ?></td>
                                            <td class="text-end meter-display"><?php echo number_format($n['opening_meter'] ?? 0, 2); ?></td>
                                            <td class="text-end meter-display"><?php echo number_format($n['closing_meter'] ?? 0, 2); ?></td>
                                            <td class="text-end fw-bold">
                                                <?php if($is_pipeline): ?>
                                                    <?php echo number_format($total_sold, 2); ?> m³
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if($n['is_active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if($is_pipeline): ?>
                                                    <button class="btn btn-sm btn-info" onclick="updateMeter(<?php echo $n['id']; ?>, '<?php echo addslashes($n['nozzle_name']); ?>', <?php echo $n['opening_meter'] ?? 0; ?>, <?php echo $n['closing_meter'] ?? 0; ?>)">
                                                        <i class="fas fa-tachometer-alt"></i> Meter
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-warning" onclick="editNozzle(<?php echo $n['id']; ?>, '<?php echo addslashes($n['nozzle_name']); ?>', <?php echo $n['product_id'] ?? 'null'; ?>, '<?php echo $n['unit_type']; ?>', <?php echo $is_pipeline ? 1 : 0; ?>, '<?php echo $n['pipeline_source'] ?? ''; ?>', <?php echo $n['tank_id'] ?? 'null'; ?>, <?php echo $n['is_active']; ?>)">
                                                    <i class="fas fa-pen"></i>
                                                </button>
                                                <a href="?tab=nozzles&delete_nozzle_id=<?php echo $n['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this nozzle?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ==================== MODALS ==================== -->
    
    <!-- Add Product Modal -->
    <div class="modal fade" id="addProductModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5><i class="fas fa-plus"></i> Add New Product</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Product Name <span class="text-danger">*</span></label>
                            <input type="text" name="product_name" class="form-control" placeholder="e.g., Diesel, Petrol, CNG" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Product Code</label>
                                    <input type="text" name="product_code" class="form-control" placeholder="e.g., DIESEL-01">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Unit Type</label>
                                    <select name="unit_type" class="form-control">
                                        <option value="liters">Liters</option>
                                        <option value="cubic_meters">Cubic Meters (m³)</option>
                                        <option value="kilograms">Kilograms</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Unit Price (Selling) <span class="text-danger">*</span></label>
                                    <input type="number" name="unit_price" class="form-control" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Purchase Rate</label>
                                    <input type="number" name="purchase_rate" class="form-control" step="0.01" value="0">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="is_active" class="form-check-input" checked>
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_product" class="btn btn-primary">Add Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Product Modal -->
    <div class="modal fade" id="editProductModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5><i class="fas fa-edit"></i> Edit Product</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="product_id" id="edit_product_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Product Name <span class="text-danger">*</span></label>
                            <input type="text" name="product_name" id="edit_product_name" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Product Code</label>
                                    <input type="text" name="product_code" id="edit_product_code" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Unit Type</label>
                                    <select name="unit_type" id="edit_product_unit_type" class="form-control">
                                        <option value="liters">Liters</option>
                                        <option value="cubic_meters">Cubic Meters (m³)</option>
                                        <option value="kilograms">Kilograms</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Unit Price (Selling)</label>
                                    <input type="number" name="unit_price" id="edit_product_price" class="form-control" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Purchase Rate</label>
                                    <input type="number" name="purchase_rate" id="edit_purchase_rate" class="form-control" step="0.01">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="is_active" id="edit_product_active" class="form-check-input">
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_product" class="btn btn-warning">Update Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Add Tank Modal -->
    <div class="modal fade" id="addTankModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5><i class="fas fa-plus"></i> Add New Tank</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Tank Name <span class="text-danger">*</span></label>
                            <input type="text" name="tank_name" class="form-control" placeholder="e.g., Diesel Tank-01" required>
                        </div>
                        <div class="mb-3">
                            <label>Product <span class="text-danger">*</span></label>
                            <select name="product_id" class="form-control" required>
                                <option value="">-- Select Product --</option>
                                <?php foreach($products as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo $p['product_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Capacity (Liters) <span class="text-danger">*</span></label>
                                    <input type="number" name="capacity_liters" class="form-control" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Current Stock (Liters)</label>
                                    <input type="number" name="current_stock" class="form-control" step="0.01" value="0">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Calibration Factor (L/cm)</label>
                            <input type="number" name="calibration_factor" class="form-control" step="0.0001" value="1.0000">
                            <small class="text-muted">Used for dip stick measurement conversion</small>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="is_active" class="form-check-input" checked>
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_tank" class="btn btn-primary">Add Tank</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Tank Modal -->
    <div class="modal fade" id="editTankModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5><i class="fas fa-edit"></i> Edit Tank</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="tank_id" id="edit_tank_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Tank Name <span class="text-danger">*</span></label>
                            <input type="text" name="tank_name" id="edit_tank_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Product <span class="text-danger">*</span></label>
                            <select name="product_id" id="edit_tank_product" class="form-control" required>
                                <option value="">-- Select Product --</option>
                                <?php foreach($products as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo $p['product_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Capacity (Liters) <span class="text-danger">*</span></label>
                                    <input type="number" name="capacity_liters" id="edit_tank_capacity" class="form-control" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Calibration Factor (L/cm)</label>
                                    <input type="number" name="calibration_factor" id="edit_tank_calibration" class="form-control" step="0.0001">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="is_active" id="edit_tank_active" class="form-check-input">
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_tank" class="btn btn-warning">Update Tank</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Update Stock Modal -->
    <div class="modal fade" id="stockModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5><i class="fas fa-edit"></i> Update Tank Stock</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="tank_id" id="stock_tank_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Tank Name</label>
                            <input type="text" id="stock_tank_name" class="form-control" readonly>
                        </div>
                        <div class="mb-3">
                            <label>Current Stock (Liters) <span class="text-danger">*</span></label>
                            <input type="number" name="current_stock" id="stock_current_stock" class="form-control" step="0.01" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_stock" class="btn btn-info">Update Stock</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Add Nozzle Modal -->
    <div class="modal fade" id="addNozzleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5><i class="fas fa-plus"></i> Add Nozzle / Pipeline</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Nozzle Name <span class="text-danger">*</span></label>
                                    <input type="text" name="nozzle_name" class="form-control" placeholder="e.g., CNG Nozzle-01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Product</label>
                                    <select name="product_id" class="form-control">
                                        <option value="">-- Select Product (Optional for Pipeline) --</option>
                                        <?php foreach($products as $p): ?>
                                            <option value="<?php echo $p['id']; ?>"><?php echo $p['product_name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Unit Type</label>
                                    <select name="unit_type" class="form-control">
                                        <option value="liters">Liters</option>
                                        <option value="cubic_meters" selected>Cubic Meters (m³)</option>
                                        <option value="kilograms">Kilograms</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <div class="form-check mt-4">
                                        <input type="checkbox" name="is_pipeline" id="is_pipeline_add" class="form-check-input" value="1" onchange="togglePipelineFields('add')">
                                        <label class="form-check-label" for="is_pipeline_add">
                                            <i class="fas fa-pipe"></i> Pipeline Nozzle (CNG)
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div id="add_pipeline_fields" style="display:none;">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label>Pipeline Source</label>
                                        <input type="text" name="pipeline_source" class="form-control" value="Titas Gas">
                                    </div>
                                </div>
                            </div>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <strong>Pipeline Nozzle:</strong> Connected to government pipeline. No tank association required.
                            </div>
                        </div>
                        
                        <div id="add_tank_fields">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label>Connected Tank</label>
                                        <select name="tank_id" class="form-control">
                                            <option value="">-- Select Tank --</option>
                                            <?php foreach($tanks as $t): ?>
                                                <option value="<?php echo $t['id']; ?>"><?php echo $t['tank_name']; ?> (<?php echo $t['product_name']; ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div id="add_meter_fields" style="display:none;">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Opening Meter (m³)</label>
                                        <input type="number" name="opening_meter" class="form-control meter-display" step="0.01" value="0">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Closing Meter (m³)</label>
                                        <input type="number" name="closing_meter" class="form-control meter-display" step="0.01" value="0">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_nozzle" class="btn btn-primary">Add Nozzle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Update Meter Modal -->
    <div class="modal fade" id="meterModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5><i class="fas fa-tachometer-alt"></i> Update CNG Meter Reading</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="nozzle_id" id="meter_nozzle_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Nozzle Name</label>
                            <input type="text" id="meter_nozzle_name" class="form-control" readonly>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Opening Meter (m³)</label>
                                    <input type="number" name="opening_meter" id="meter_opening" class="form-control meter-display" step="0.01" readonly>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Closing Meter (m³)</label>
                                    <input type="number" name="closing_meter" id="meter_closing" class="form-control meter-display" step="0.01" required>
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-success" id="meter_calculation">
                            <strong>Units Sold:</strong> <span id="meter_units_sold">0.00</span> m³
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_meter" class="btn btn-info">Update Meter</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Nozzle Modal -->
    <div class="modal fade" id="editNozzleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5><i class="fas fa-edit"></i> Edit Nozzle</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="nozzle_id" id="edit_nozzle_id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Nozzle Name</label>
                                    <input type="text" name="nozzle_name" id="edit_nozzle_name" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Product</label>
                                    <select name="product_id" id="edit_product_id" class="form-control">
                                        <option value="">-- Select Product --</option>
                                        <?php foreach($products as $p): ?>
                                            <option value="<?php echo $p['id']; ?>"><?php echo $p['product_name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Unit Type</label>
                                    <select name="unit_type" id="edit_unit_type" class="form-control">
                                        <option value="liters">Liters</option>
                                        <option value="cubic_meters">Cubic Meters (m³)</option>
                                        <option value="kilograms">Kilograms</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <div class="form-check mt-4">
                                        <input type="checkbox" name="is_pipeline" id="is_pipeline_edit" class="form-check-input" value="1" onchange="togglePipelineFields('edit')">
                                        <label class="form-check-label" for="is_pipeline_edit">
                                            <i class="fas fa-pipe"></i> Pipeline Nozzle (CNG)
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div id="edit_pipeline_fields" style="display:none;">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label>Pipeline Source</label>
                                        <input type="text" name="pipeline_source" id="edit_pipeline_source" class="form-control" value="Titas Gas">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div id="edit_tank_fields">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label>Connected Tank</label>
                                        <select name="tank_id" id="edit_tank_id" class="form-control">
                                            <option value="">-- Select Tank --</option>
                                            <?php foreach($tanks as $t): ?>
                                                <option value="<?php echo $t['id']; ?>"><?php echo $t['tank_name']; ?> (<?php echo $t['product_name']; ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="is_active" id="edit_nozzle_active" class="form-check-input">
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_nozzle" class="btn btn-warning">Update Nozzle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            if ($.fn.DataTable) {
                $('#productsTable').DataTable({
                    order: [[0, 'asc']],
                    pageLength: 25,
                    language: { search: "Search:", lengthMenu: "Show _MENU_ entries" }
                });
                $('#tanksTable').DataTable({
                    order: [[0, 'asc']],
                    pageLength: 25,
                    language: { search: "Search:", lengthMenu: "Show _MENU_ entries" }
                });
                $('#nozzlesTable').DataTable({
                    order: [[0, 'asc']],
                    pageLength: 25,
                    language: { search: "Search:", lengthMenu: "Show _MENU_ entries" }
                });
            }
        });
        
        function togglePipelineFields(type) {
            var isChecked = document.getElementById('is_pipeline_' + type).checked;
            var pipelineId = type + '_pipeline_fields';
            var tankId = type + '_tank_fields';
            var meterId = type + '_meter_fields';
            
            if(isChecked) {
                document.getElementById(pipelineId).style.display = 'block';
                document.getElementById(tankId).style.display = 'none';
                if(document.getElementById(meterId)) {
                    document.getElementById(meterId).style.display = 'block';
                }
            } else {
                document.getElementById(pipelineId).style.display = 'none';
                document.getElementById(tankId).style.display = 'block';
                if(document.getElementById(meterId)) {
                    document.getElementById(meterId).style.display = 'none';
                }
            }
        }
        
        function updateMeter(id, name, opening, closing) {
            document.getElementById('meter_nozzle_id').value = id;
            document.getElementById('meter_nozzle_name').value = name;
            document.getElementById('meter_opening').value = opening.toFixed(2);
            document.getElementById('meter_closing').value = closing.toFixed(2);
            document.getElementById('meter_units_sold').innerText = (closing - opening).toFixed(2);
            
            new bootstrap.Modal(document.getElementById('meterModal')).show();
        }
        
        document.getElementById('meter_closing').addEventListener('input', function() {
            let opening = parseFloat(document.getElementById('meter_opening').value) || 0;
            let closing = parseFloat(this.value) || 0;
            let units = closing - opening;
            document.getElementById('meter_units_sold').innerText = units.toFixed(2);
        });
        
        function updateStock(id, name, stock) {
            document.getElementById('stock_tank_id').value = id;
            document.getElementById('stock_tank_name').value = name;
            document.getElementById('stock_current_stock').value = stock;
            new bootstrap.Modal(document.getElementById('stockModal')).show();
        }
        
        function editTank(id, name, productId, capacity, calibration, isActive) {
            document.getElementById('edit_tank_id').value = id;
            document.getElementById('edit_tank_name').value = name;
            document.getElementById('edit_tank_product').value = productId;
            document.getElementById('edit_tank_capacity').value = capacity;
            document.getElementById('edit_tank_calibration').value = calibration;
            document.getElementById('edit_tank_active').checked = isActive == 1;
            
            new bootstrap.Modal(document.getElementById('editTankModal')).show();
        }
        
        function editProduct(id, name, price, purchaseRate, code, unitType, isActive) {
            document.getElementById('edit_product_id').value = id;
            document.getElementById('edit_product_name').value = name;
            document.getElementById('edit_product_price').value = price;
            document.getElementById('edit_purchase_rate').value = purchaseRate;
            document.getElementById('edit_product_code').value = code;
            document.getElementById('edit_product_unit_type').value = unitType || 'liters';
            document.getElementById('edit_product_active').checked = isActive == 1;
            
            new bootstrap.Modal(document.getElementById('editProductModal')).show();
        }
        
        function editNozzle(id, name, productId, unitType, isPipeline, pipelineSource, tankId, isActive) {
            document.getElementById('edit_nozzle_id').value = id;
            document.getElementById('edit_nozzle_name').value = name;
            if(productId != 'null' && productId) {
                document.getElementById('edit_product_id').value = productId;
            }
            document.getElementById('edit_unit_type').value = unitType || 'liters';
            document.getElementById('edit_nozzle_active').checked = isActive == 1;
            
            document.getElementById('is_pipeline_edit').checked = isPipeline == 1;
            togglePipelineFields('edit');
            
            if(isPipeline == 1) {
                document.getElementById('edit_pipeline_source').value = pipelineSource || 'Titas Gas';
            } else {
                if(tankId && tankId != 'null') {
                    document.getElementById('edit_tank_id').value = tankId;
                }
            }
            
            new bootstrap.Modal(document.getElementById('editNozzleModal')).show();
        }
    </script>
</body>
</html>
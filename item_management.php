<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'items';

// =============================================
// ADD / UPDATE ITEM
// =============================================
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    if(isset($_POST['add_item']) || isset($_POST['update_item'])) {
        $item_code = $_POST['item_code'];
        $item_name = $_POST['item_name'];
        $category_id = $_POST['category_id'] ?: null;
        $item_type = $_POST['item_type'];
        $unit = $_POST['unit'];
        $purchase_price = floatval($_POST['purchase_price'] ?? 0);
        $selling_price = floatval($_POST['selling_price']);
        $current_stock = floatval($_POST['current_stock'] ?? 0);
        $min_stock = floatval($_POST['min_stock'] ?? 0);
        $tax_rate = floatval($_POST['tax_rate'] ?? 0);
        $description = $_POST['description'] ?? '';
        $is_active = isset($_POST['is_active']) ? 1 : 1;
        
        try {
            if(isset($_POST['update_item'])) {
                $item_id = $_POST['item_id'];
                $stmt = $pdo->prepare("
                    UPDATE items SET 
                        item_code = ?, item_name = ?, category_id = ?, item_type = ?,
                        unit = ?, purchase_price = ?, selling_price = ?, current_stock = ?,
                        min_stock = ?, tax_rate = ?, description = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$item_code, $item_name, $category_id, $item_type, $unit, 
                               $purchase_price, $selling_price, $current_stock, $min_stock, 
                               $tax_rate, $description, $is_active, $item_id]);
                $success = "✅ Item updated successfully!";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO items (item_code, item_name, category_id, item_type, unit, 
                                      purchase_price, selling_price, current_stock, min_stock, 
                                      tax_rate, description, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$item_code, $item_name, $category_id, $item_type, $unit,
                               $purchase_price, $selling_price, $current_stock, $min_stock,
                               $tax_rate, $description, $is_active]);
                $success = "✅ Item added successfully!";
            }
        } catch(Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
    
    // Delete item
    if(isset($_POST['delete_item'])) {
        $item_id = $_POST['item_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM items WHERE id = ?");
            $stmt->execute([$item_id]);
            $success = "✅ Item deleted successfully!";
        } catch(Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

// =============================================
// GET DATA
// =============================================
$items = $pdo->query("
    SELECT i.*, c.category_name 
    FROM items i 
    LEFT JOIN item_categories c ON i.category_id = c.id 
    ORDER BY i.item_name
")->fetchAll();

$categories = $pdo->query("SELECT * FROM item_categories WHERE is_active = 1 ORDER BY category_name")->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item & Services Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s;
        }
        .stats-card:hover { transform: translateY(-5px); }
        .stats-card i { font-size: 40px; opacity: 0.5; float: right; }
        .badge-product { background: #28a745; color: white; }
        .badge-service { background: #17a2b8; color: white; }
        .nav-tabs-custom {
            border-bottom: 2px solid #dee2e6;
            padding: 0;
            margin-bottom: 20px;
            background: #f8f9fa;
            border-radius: 8px 8px 0 0;
        }
        .nav-tabs-custom .nav-link {
            color: #000 !important;
            font-weight: 600;
            padding: 12px 25px;
            border: none;
            border-radius: 8px 8px 0 0;
            background: transparent;
        }
        .nav-tabs-custom .nav-link.active {
            color: #0d6efd !important;
            background: #ffffff;
            border-bottom: 3px solid #0d6efd;
        }
        .tab-content-custom {
            background: white;
            padding: 20px;
            border-radius: 0 0 8px 8px;
            border: 1px solid #dee2e6;
            border-top: none;
            min-height: 500px;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-boxes"></i> Item & Services Management</h2>
                <div>
                    <a href="item_pos.php" class="btn btn-success">
                        <i class="fas fa-shopping-cart"></i> POS
                    </a>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <!-- Statistics -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-box"></i>
                        <h3><?php echo count($items); ?></h3>
                        <p>Total Items</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-cube"></i>
                        <h3><?php 
                            $products = array_filter($items, fn($i) => $i['item_type'] == 'product');
                            echo count($products);
                        ?></h3>
                        <p>Products</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-cogs"></i>
                        <h3><?php 
                            $services = array_filter($items, fn($i) => $i['item_type'] == 'service');
                            echo count($services);
                        ?></h3>
                        <p>Services</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-tags"></i>
                        <h3><?php echo count($categories); ?></h3>
                        <p>Categories</p>
                    </div>
                </div>
            </div>
            
            <!-- Tabs -->
            <ul class="nav nav-tabs-custom">
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'items' ? 'active' : ''; ?>" href="?tab=items">
                        <i class="fas fa-list"></i> Items & Services
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'add' ? 'active' : ''; ?>" href="?tab=add">
                        <i class="fas fa-plus"></i> Add New
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'categories' ? 'active' : ''; ?>" href="?tab=categories">
                        <i class="fas fa-tags"></i> Categories
                    </a>
                </li>
            </ul>
            
            <div class="tab-content-custom">
                <!-- Items List -->
                <?php if($active_tab == 'items'): ?>
                <div class="table-responsive">
                    <table class="table table-bordered" id="itemsTable">
                        <thead class="table-dark">
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Type</th>
                                <th>Unit</th>
                                <th class="text-end">Selling Price</th>
                                <th class="text-end">Stock</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($items as $item): ?>
                            <tr>
                                <td><?php echo $item['item_code']; ?></td>
                                <td><strong><?php echo $item['item_name']; ?></strong></td>
                                <td><?php echo $item['category_name'] ?? '-'; ?></td>
                                <td>
                                    <span class="badge <?php echo $item['item_type'] == 'product' ? 'badge-product' : 'badge-service'; ?>">
                                        <?php echo ucfirst($item['item_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo $item['unit']; ?></td>
                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($item['selling_price'], 2); ?></td>
                                <td class="text-end">
                                    <?php if($item['item_type'] == 'product'): ?>
                                        <?php echo number_format($item['current_stock'], 2); ?>
                                        <?php if($item['current_stock'] <= $item['min_stock']): ?>
                                            <span class="badge bg-danger">Low</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($item['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-warning" onclick="editItem(<?php echo $item['id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this item?')">
                                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" name="delete_item" class="btn btn-sm btn-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <!-- Add/Edit Item -->
                <?php if($active_tab == 'add' || $active_tab == 'edit'): 
                    $edit_item = null;
                    if($active_tab == 'edit' && isset($_GET['id'])) {
                        $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
                        $stmt->execute([$_GET['id']]);
                        $edit_item = $stmt->fetch();
                    }
                ?>
                <div class="row">
                    <div class="col-md-8 mx-auto">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5><?php echo $edit_item ? 'Edit' : 'Add New'; ?> Item / Service</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <?php if($edit_item): ?>
                                        <input type="hidden" name="item_id" value="<?php echo $edit_item['id']; ?>">
                                        <input type="hidden" name="update_item" value="1">
                                    <?php else: ?>
                                        <input type="hidden" name="add_item" value="1">
                                    <?php endif; ?>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label>Item Code *</label>
                                                <input type="text" name="item_code" class="form-control" value="<?php echo $edit_item['item_code'] ?? ''; ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label>Item Name *</label>
                                                <input type="text" name="item_name" class="form-control" value="<?php echo $edit_item['item_name'] ?? ''; ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label>Category</label>
                                                <select name="category_id" class="form-control">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach($categories as $cat): ?>
                                                        <option value="<?php echo $cat['id']; ?>" <?php echo ($edit_item && $edit_item['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                                            <?php echo $cat['category_name']; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label>Item Type *</label>
                                                <select name="item_type" class="form-control" required>
                                                    <option value="product" <?php echo ($edit_item && $edit_item['item_type'] == 'product') ? 'selected' : ''; ?>>Product</option>
                                                    <option value="service" <?php echo ($edit_item && $edit_item['item_type'] == 'service') ? 'selected' : ''; ?>>Service</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label>Unit</label>
                                                <input type="text" name="unit" class="form-control" value="<?php echo $edit_item['unit'] ?? 'pcs'; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label>Purchase Price (<?php echo $currency; ?>)</label>
                                                <input type="number" name="purchase_price" class="form-control" step="0.01" value="<?php echo $edit_item['purchase_price'] ?? 0; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label>Selling Price (<?php echo $currency; ?>) *</label>
                                                <input type="number" name="selling_price" class="form-control" step="0.01" value="<?php echo $edit_item['selling_price'] ?? 0; ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label>Current Stock</label>
                                                <input type="number" name="current_stock" class="form-control" step="0.01" value="<?php echo $edit_item['current_stock'] ?? 0; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label>Min Stock Alert</label>
                                                <input type="number" name="min_stock" class="form-control" step="0.01" value="<?php echo $edit_item['min_stock'] ?? 0; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label>Tax Rate (%)</label>
                                                <input type="number" name="tax_rate" class="form-control" step="0.01" value="<?php echo $edit_item['tax_rate'] ?? 0; ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label>Description</label>
                                        <textarea name="description" class="form-control" rows="2"><?php echo $edit_item['description'] ?? ''; ?></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input type="checkbox" name="is_active" class="form-check-input" <?php echo (!$edit_item || $edit_item['is_active']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label">Active</label>
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-save"></i> <?php echo $edit_item ? 'Update' : 'Add'; ?> Item
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Categories -->
                <?php if($active_tab == 'categories'): ?>
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5><i class="fas fa-plus"></i> Add Category</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="category_management.php">
                                    <div class="mb-3">
                                        <label>Category Name *</label>
                                        <input type="text" name="category_name" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label>Category Code</label>
                                        <input type="text" name="category_code" class="form-control">
                                    </div>
                                    <div class="mb-3">
                                        <label>Description</label>
                                        <textarea name="description" class="form-control" rows="2"></textarea>
                                    </div>
                                    <button type="submit" name="add_category" class="btn btn-primary w-100">Add Category</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h5><i class="fas fa-list"></i> Categories</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Code</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($categories as $cat): ?>
                                            <tr>
                                                <td><?php echo $cat['category_name']; ?></td>
                                                <td><?php echo $cat['category_code'] ?? '-'; ?></td>
                                                <td>
                                                    <?php if($cat['is_active']): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Inactive</span>
                                                    <?php endif; ?>
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
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#itemsTable').DataTable({
                order: [[0, 'asc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
        
        function editItem(id) {
            window.location.href = '?tab=edit&id=' + id;
        }
    </script>
</body>
</html>
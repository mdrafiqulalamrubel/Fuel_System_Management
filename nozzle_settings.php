<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Add new nozzle
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_nozzle'])) {
    $nozzle_name = $_POST['nozzle_name'];
    $tank_id = $_POST['tank_id'];
    
    $stmt = $pdo->prepare("INSERT INTO nozzles (nozzle_name, tank_id, is_active) VALUES (?, ?, 1)");
    if($stmt->execute([$nozzle_name, $tank_id])) {
        $success = "Nozzle added successfully!";
    } else {
        $error = "Failed to add nozzle";
    }
}

// Update nozzle
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_nozzle'])) {
    $nozzle_id = $_POST['nozzle_id'];
    $nozzle_name = $_POST['nozzle_name'];
    $tank_id = $_POST['tank_id'];
    
    $stmt = $pdo->prepare("UPDATE nozzles SET nozzle_name = ?, tank_id = ? WHERE id = ?");
    if($stmt->execute([$nozzle_name, $tank_id, $nozzle_id])) {
        $success = "Nozzle updated successfully!";
    } else {
        $error = "Failed to update nozzle";
    }
}

// Delete nozzle
if(isset($_GET['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM nozzles WHERE id = ?");
    if($stmt->execute([$_GET['delete_id']])) {
        $success = "Nozzle deleted successfully!";
    }
}

// Get all nozzles with tank info
$nozzles = $pdo->query("
    SELECT n.*, t.tank_name, p.product_name 
    FROM nozzles n 
    JOIN tanks t ON n.tank_id = t.id 
    JOIN fuel_products p ON t.product_id = p.id 
    ORDER BY n.id
")->fetchAll();

$tanks = $pdo->query("SELECT t.*, p.product_name FROM tanks t JOIN fuel_products p ON t.product_id = p.id")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nozzle Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-oil-can"></i> Nozzle Settings</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addNozzleModal">
                    <i class="fas fa-plus"></i> Add New Nozzle
                </button>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-list"></i> Nozzle List</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="nozzlesTable">
                            <thead>
                                <tr>
                                    <th>SL</th>
                                    <th>Nozzle Name</th>
                                    <th>Tank</th>
                                    <th>Product</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $sl=1; foreach($nozzles as $n): ?>
                                <tr>
                                    <td><?php echo $sl++; ?></td>
                                    <td><strong><?php echo $n['nozzle_name']; ?></strong></td>
                                    <td><?php echo $n['tank_name']; ?></td>
                                    <td><?php echo $n['product_name']; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-warning" onclick="editNozzle(<?php echo $n['id']; ?>, '<?php echo $n['nozzle_name']; ?>', <?php echo $n['tank_id']; ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <a href="?delete_id=<?php echo $n['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this nozzle?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </td>
                                <tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Nozzle Modal -->
    <div class="modal fade" id="addNozzleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5><i class="fas fa-plus"></i> Add New Nozzle</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Nozzle Name</label>
                            <input type="text" name="nozzle_name" class="form-control" placeholder="e.g., Nozzle-01, Diesel Pump 1" required>
                        </div>
                        <div class="mb-3">
                            <label>Select Tank</label>
                            <select name="tank_id" class="form-control" required>
                                <option value="">-- Select Tank --</option>
                                <?php foreach($tanks as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo $t['tank_name']; ?> - <?php echo $t['product_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
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
    
    <!-- Edit Nozzle Modal -->
    <div class="modal fade" id="editNozzleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5><i class="fas fa-edit"></i> Edit Nozzle</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="nozzle_id" id="edit_nozzle_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Nozzle Name</label>
                            <input type="text" name="nozzle_name" id="edit_nozzle_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Select Tank</label>
                            <select name="tank_id" id="edit_tank_id" class="form-control" required>
                                <option value="">-- Select Tank --</option>
                                <?php foreach($tanks as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo $t['tank_name']; ?> - <?php echo $t['product_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
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
            $('#nozzlesTable').DataTable();
        });
        
        function editNozzle(id, name, tankId) {
            document.getElementById('edit_nozzle_id').value = id;
            document.getElementById('edit_nozzle_name').value = name;
            document.getElementById('edit_tank_id').value = tankId;
            new bootstrap.Modal(document.getElementById('editNozzleModal')).show();
        }
    </script>
</body>
</html>
<?php
// customer_management.php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';
$edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;

// Delete customer
if(isset($_GET['delete']) && $_GET['delete'] > 0) {
    $delete_id = intval($_GET['delete']);
    try {
        $stmt = $pdo->prepare("UPDATE customers SET is_active = 0 WHERE id = ?");
        $stmt->execute([$delete_id]);
        $success = "Customer deactivated successfully!";
    } catch(Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Activate customer
if(isset($_GET['activate']) && $_GET['activate'] > 0) {
    $activate_id = intval($_GET['activate']);
    try {
        $stmt = $pdo->prepare("UPDATE customers SET is_active = 1 WHERE id = ?");
        $stmt->execute([$activate_id]);
        $success = "Customer activated successfully!";
    } catch(Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Save/Update customer
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_customer'])) {
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $customer_code = trim($_POST['customer_code'] ?? '');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $credit_limit = floatval($_POST['credit_limit'] ?? 50000);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    try {
        if($customer_id > 0) {
            // Update existing customer
            $stmt = $pdo->prepare("
                UPDATE customers SET 
                    customer_code = ?, 
                    customer_name = ?, 
                    phone = ?, 
                    email = ?, 
                    address = ?, 
                    credit_limit = ?, 
                    is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$customer_code, $customer_name, $phone, $email, $address, $credit_limit, $is_active, $customer_id]);
            $success = "Customer updated successfully!";
        } else {
            // Insert new customer
            $stmt = $pdo->prepare("
                INSERT INTO customers (customer_code, customer_name, phone, email, address, credit_limit, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            // If customer_code is empty, generate one
            if(empty($customer_code)) {
                $customer_code = 'CUST-' . date('Ymd') . rand(100, 999);
            }
            $stmt->execute([$customer_code, $customer_name, $phone, $email, $address, $credit_limit]);
            $success = "Customer added successfully!";
        }
    } catch(Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get customer for editing
$edit_customer = null;
if($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_customer = $stmt->fetch();
}

// Get all customers
$customers = $pdo->query("SELECT * FROM customers ORDER BY customer_name")->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
        .stats-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stats-card.orange { background: linear-gradient(135deg, #f6d365 0%, #fda085 100%); }
        .stats-card.blue { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .status-active { background: #28a745; color: white; padding: 5px 12px; border-radius: 20px; font-size: 12px; }
        .status-inactive { background: #dc3545; color: white; padding: 5px 12px; border-radius: 20px; font-size: 12px; }
        .badge-due { background: #dc3545; color: white; }
        .badge-advance { background: #28a745; color: white; }
        .badge-settled { background: #6c757d; color: white; }
        .action-btns .btn { margin: 2px; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-users"></i> Customer Management</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#customerModal" onclick="resetCustomerForm()">
                    <i class="fas fa-user-plus"></i> Add New Customer
                </button>
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
            
            <!-- Statistics -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-users"></i>
                        <h3><?php echo count($customers); ?></h3>
                        <p>Total Customers</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card green">
                        <i class="fas fa-user-check"></i>
                        <h3><?php 
                            $active = array_filter($customers, function($c) { return $c['is_active'] == 1; });
                            echo count($active);
                        ?></h3>
                        <p>Active Customers</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card orange">
                        <i class="fas fa-user-times"></i>
                        <h3><?php 
                            $inactive = array_filter($customers, function($c) { return $c['is_active'] == 0; });
                            echo count($inactive);
                        ?></h3>
                        <p>Inactive Customers</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card blue">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php 
                            $total_due = 0;
                            foreach($customers as $c) { 
                                $net = ($c['current_balance'] ?? 0) - ($c['advance_balance'] ?? 0);
                                $total_due += $net > 0 ? $net : 0;
                            }
                            echo number_format($total_due, 2);
                        ?></h3>
                        <p>Total Outstanding</p>
                    </div>
                </div>
            </div>
            
            <!-- Customer Table -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-list"></i> Customer List</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="customerTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Phone</th>
                                    <th>Email</th>
                                    <th>Credit Limit</th>
                                    <th>Balance</th>
                                    <th>Advance</th>
                                    <th>Net Balance</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($customers as $c): 
                                    $net = ($c['current_balance'] ?? 0) - ($c['advance_balance'] ?? 0);
                                    $net_class = $net > 0 ? 'text-danger' : ($net < 0 ? 'text-success' : 'text-muted');
                                    $net_label = $net > 0 ? 'Due' : ($net < 0 ? 'Advance' : 'Settled');
                                    $badge_class = $net > 0 ? 'badge-due' : ($net < 0 ? 'badge-advance' : 'badge-settled');
                                ?>
                                <tr>
                                    <td><strong><?php echo $c['customer_code']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($c['customer_name']); ?></td>
                                    <td><?php echo $c['phone'] ?: '-'; ?></td>
                                    <td><?php echo $c['email'] ?: '-'; ?></td>
                                    <td><?php echo $currency; ?> <?php echo number_format($c['credit_limit'], 2); ?></td>
                                    <td><?php echo $currency; ?> <?php echo number_format($c['current_balance'] ?? 0, 2); ?></td>
                                    <td><?php echo $currency; ?> <?php echo number_format($c['advance_balance'] ?? 0, 2); ?></td>
                                    <td class="<?php echo $net_class; ?> fw-bold">
                                        <?php echo $currency; ?> <?php echo number_format(abs($net), 2); ?> 
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo $net_label; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="<?php echo $c['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $c['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td class="action-btns">
                                        <button class="btn btn-sm btn-warning" onclick="editCustomer(<?php echo $c['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if($c['is_active']): ?>
                                            <a href="?delete=<?php echo $c['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Deactivate this customer?')">
                                                <i class="fas fa-ban"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="?activate=<?php echo $c['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Activate this customer?')">
                                                <i class="fas fa-check"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="customer_ledger.php?customer_id=<?php echo $c['id']; ?>" class="btn btn-sm btn-info" target="_blank">
                                            <i class="fas fa-book"></i>
                                        </a>
                                        <a href="customer_payment.php?customer_id=<?php echo $c['id']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                            <i class="fas fa-hand-holding-usd"></i>
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
    
    <!-- Add/Edit Customer Modal -->
    <div class="modal fade" id="customerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 id="modalTitle"><i class="fas fa-user-plus"></i> Add New Customer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="customer_id" id="customer_id" value="0">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Customer Code</label>
                            <input type="text" name="customer_code" id="customer_code" class="form-control" placeholder="Auto-generated if empty">
                        </div>
                        <div class="mb-3">
                            <label>Customer Name <span class="text-danger">*</span></label>
                            <input type="text" name="customer_name" id="customer_name" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Phone</label>
                                    <input type="text" name="phone" id="phone" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Email</label>
                                    <input type="email" name="email" id="email" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Address</label>
                            <textarea name="address" id="address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label>Credit Limit (<?php echo $currency; ?>)</label>
                            <input type="number" name="credit_limit" id="credit_limit" class="form-control" step="0.01" value="50000">
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="is_active" class="form-check-input" id="is_active" value="1" checked>
                            <label class="form-check-label">Active</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="save_customer" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Customer
                        </button>
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
            $('#customerTable').DataTable({
                order: [[1, 'asc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
        
        function resetCustomerForm() {
            document.getElementById('customer_id').value = 0;
            document.getElementById('customer_code').value = '';
            document.getElementById('customer_name').value = '';
            document.getElementById('phone').value = '';
            document.getElementById('email').value = '';
            document.getElementById('address').value = '';
            document.getElementById('credit_limit').value = '50000';
            document.getElementById('is_active').checked = true;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add New Customer';
        }
        
        function editCustomer(id) {
            // Fetch customer data via AJAX
            $.ajax({
                url: 'ajax/get_customer.php',
                method: 'GET',
                data: { id: id },
                dataType: 'json',
                success: function(customer) {
                    if(customer && customer.id) {
                        document.getElementById('customer_id').value = customer.id;
                        document.getElementById('customer_code').value = customer.customer_code || '';
                        document.getElementById('customer_name').value = customer.customer_name || '';
                        document.getElementById('phone').value = customer.phone || '';
                        document.getElementById('email').value = customer.email || '';
                        document.getElementById('address').value = customer.address || '';
                        document.getElementById('credit_limit').value = customer.credit_limit || 50000;
                        document.getElementById('is_active').checked = customer.is_active == 1;
                        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Customer';
                        
                        // Show modal
                        var modal = new bootstrap.Modal(document.getElementById('customerModal'));
                        modal.show();
                    } else {
                        alert('Customer data not found!');
                    }
                },
                error: function(xhr, status, error) {
                    console.log('Error:', error);
                    console.log('Response:', xhr.responseText);
                    alert('Error loading customer data. Please check console for details.');
                }
            });
        }
    </script>
</body>
</html>
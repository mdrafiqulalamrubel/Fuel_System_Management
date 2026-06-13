<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'tenants';

// Add Tenant
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_tenant'])) {
    $tenant_name = $_POST['tenant_name'];
    $shop_no = isset($_POST['shop_no']) ? $_POST['shop_no'] : '';
    $monthly_rent = $_POST['monthly_rent'];
    $agreement_start = isset($_POST['agreement_start']) ? $_POST['agreement_start'] : null;
    $agreement_end = isset($_POST['agreement_end']) ? $_POST['agreement_end'] : null;
    $phone = isset($_POST['phone']) ? $_POST['phone'] : '';
    $email = isset($_POST['email']) ? $_POST['email'] : '';
    $address = isset($_POST['address']) ? $_POST['address'] : '';
    $security_deposit = isset($_POST['security_deposit']) ? $_POST['security_deposit'] : 0;
    
    $stmt = $pdo->prepare("INSERT INTO tenants (tenant_name, shop_no, monthly_rent, agreement_start, agreement_end, phone, email, address, security_deposit, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
    if($stmt->execute([$tenant_name, $shop_no, $monthly_rent, $agreement_start, $agreement_end, $phone, $email, $address, $security_deposit])) {
        $success = "Tenant added successfully!";
    } else {
        $error = "Failed to add tenant";
    }
}

// Update Tenant
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_tenant'])) {
    $tenant_id = $_POST['tenant_id'];
    $tenant_name = $_POST['tenant_name'];
    $shop_no = isset($_POST['shop_no']) ? $_POST['shop_no'] : '';
    $monthly_rent = $_POST['monthly_rent'];
    $agreement_start = isset($_POST['agreement_start']) ? $_POST['agreement_start'] : null;
    $agreement_end = isset($_POST['agreement_end']) ? $_POST['agreement_end'] : null;
    $phone = isset($_POST['phone']) ? $_POST['phone'] : '';
    $email = isset($_POST['email']) ? $_POST['email'] : '';
    $address = isset($_POST['address']) ? $_POST['address'] : '';
    $security_deposit = isset($_POST['security_deposit']) ? $_POST['security_deposit'] : 0;
    
    $stmt = $pdo->prepare("UPDATE tenants SET tenant_name = ?, shop_no = ?, monthly_rent = ?, agreement_start = ?, agreement_end = ?, phone = ?, email = ?, address = ?, security_deposit = ? WHERE id = ?");
    if($stmt->execute([$tenant_name, $shop_no, $monthly_rent, $agreement_start, $agreement_end, $phone, $email, $address, $security_deposit, $tenant_id])) {
        $success = "Tenant updated successfully!";
    } else {
        $error = "Failed to update tenant";
    }
}

// Delete/Deactivate Tenant
if(isset($_GET['delete_id'])) {
    $stmt = $pdo->prepare("UPDATE tenants SET is_active = 0 WHERE id = ?");
    if($stmt->execute([$_GET['delete_id']])) {
        $success = "Tenant deactivated successfully!";
    }
}

// Record Rent Payment - FIXED
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['record_payment'])) {
    $tenant_id = $_POST['tenant_id'];
    $payment_date = $_POST['payment_date'];
    $month = $_POST['month']; // Format: YYYY-MM
    $amount = floatval($_POST['amount']);
    $late_fee = isset($_POST['late_fee']) ? floatval($_POST['late_fee']) : 0;
    $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'cash';
    $notes = isset($_POST['notes']) ? $_POST['notes'] : '';
    $receipt_no = 'RENT-' . date('Ymd') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    
    // Ensure month is in correct format (YYYY-MM)
    if(strlen($month) == 7 && substr($month, 4, 1) == '-') {
        // Valid format - keep as is
    } else {
        $month = date('Y-m', strtotime($month . '-01'));
    }
    
    try {
        $pdo->beginTransaction();
        
        // Check if already paid for this month
        $stmt = $pdo->prepare("SELECT id FROM rent_payments WHERE tenant_id = ? AND month = ?");
        $stmt->execute([$tenant_id, $month]);
        if($stmt->fetch()) {
            throw new Exception("Payment for month " . date('F Y', strtotime($month . '-01')) . " already recorded!");
        }
        
        // Insert payment
        $stmt = $pdo->prepare("INSERT INTO rent_payments (tenant_id, payment_date, month, amount, late_fee, payment_method, notes, receipt_no) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $payment_date, $month, $amount, $late_fee, $payment_method, $notes, $receipt_no]);
        
        // Update customer current balance in tenants table (if you have this field)
        // $stmt = $pdo->prepare("UPDATE tenants SET current_balance = current_balance - ? WHERE id = ?");
        // $stmt->execute([$amount, $tenant_id]);
        
        // Create accounting entry
        $voucher_no = 'RENT-' . date('YmdHis');
        $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'receipt', ?, ?, ?, 'approved')");
        $stmt->execute([$voucher_no, $payment_date, "Rent collection from tenant ID: $tenant_id - Month: " . date('F Y', strtotime($month . '-01')), $user['id']]);
        
        $pdo->commit();
        $success = "Payment recorded! Receipt: $receipt_no";
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Delete Payment
if(isset($_GET['delete_payment'])) {
    $stmt = $pdo->prepare("DELETE FROM rent_payments WHERE id = ?");
    if($stmt->execute([$_GET['delete_payment']])) {
        $success = "Payment deleted successfully!";
    }
}

// Get data
$tenants = $pdo->query("SELECT * FROM tenants WHERE is_active = 1 ORDER BY tenant_name")->fetchAll();
$payments = $pdo->query("
    SELECT rp.*, t.tenant_name, t.shop_no, t.monthly_rent 
    FROM rent_payments rp 
    JOIN tenants t ON rp.tenant_id = t.id 
    ORDER BY rp.payment_date DESC 
    LIMIT 100
")->fetchAll();

// Calculate dues for each tenant - CORRECTED VERSION
$tenant_dues = [];
foreach($tenants as $tenant) {
    // Get all payments for this tenant
    $stmt = $pdo->prepare("SELECT month, amount, late_fee FROM rent_payments WHERE tenant_id = ? ORDER BY month");
    $stmt->execute([$tenant['id']]);
    $paid_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $paid_amount = 0;
    $paid_months_list = [];
    foreach($paid_records as $pr) {
        $paid_amount += $pr['amount'];
        // Ensure month is in YYYY-MM format
        $month_key = (strlen($pr['month']) == 7) ? $pr['month'] : date('Y-m', strtotime($pr['month']));
        $paid_months_list[] = $month_key;
    }
    
    // Calculate due amount properly
    $due_amount = 0;
    $due_months = [];
    
    // Get current date
    $now = new DateTime();
    $now->modify('first day of this month');
    $current_month_key = $now->format('Y-m');
    
    // Get agreement start date
    if($tenant['agreement_start'] && $tenant['agreement_start'] != '0000-00-00') {
        $start = new DateTime($tenant['agreement_start']);
        $start->modify('first day of this month');
    } else {
        // If no agreement date, use the earliest payment month or current month
        if(!empty($paid_months_list)) {
            $start = new DateTime(min($paid_months_list) . '-01');
            $start->modify('first day of this month');
        } else {
            $start = clone $now;
        }
    }
    
    // Calculate months from start to current
    $interval = $start->diff($now);
    $total_months = ($interval->y * 12) + $interval->m;
    
    // For each month from start to current month
    for($i = 0; $i <= $total_months; $i++) {
        $month_date = clone $start;
        $month_date->modify("+$i months");
        $month_key = $month_date->format('Y-m');
        
        // Skip if month is after current month
        if($month_key > $current_month_key) {
            continue;
        }
        
        // Check if this month has been paid
        $is_paid = in_array($month_key, $paid_months_list);
        
        if(!$is_paid) {
            $due_months[] = $month_key;
            $due_amount += $tenant['monthly_rent'];
        }
    }
    
    $tenant_dues[$tenant['id']] = [
        'paid_amount' => $paid_amount,
        'due_amount' => $due_amount,
        'due_months' => $due_months,
        'paid_months' => $paid_months_list
    ];
}

// Calculate totals
$total_monthly_rent = 0;
foreach($tenants as $t) { $total_monthly_rent += $t['monthly_rent']; }
$total_due = 0;
foreach($tenant_dues as $due) { $total_due += $due['due_amount']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rental Management System</title>
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
        .due-badge { background: #dc3545; color: white; padding: 3px 8px; border-radius: 20px; font-size: 11px; display: inline-block; margin: 2px; }
        .paid-badge { background: #28a745; color: white; padding: 3px 8px; border-radius: 20px; font-size: 11px; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-building"></i> Rental Management System</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTenantModal">
                    <i class="fas fa-plus"></i> Add New Tenant
                </button>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-users"></i>
                        <h3><?php echo count($tenants); ?></h3>
                        <p>Active Tenants</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3>BDT <?php echo number_format($total_monthly_rent, 0); ?></h3>
                        <p>Monthly Revenue</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>BDT <?php echo number_format($total_due, 0); ?></h3>
                        <p>Total Due</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-calendar-check"></i>
                        <h3><?php echo count($payments); ?></h3>
                        <p>Total Payments</p>
                    </div>
                </div>
            </div>
            
            <!-- Tabs -->
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'tenants' ? 'active' : ''; ?>" href="?tab=tenants">
                        <i class="fas fa-users"></i> Tenants
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'payments' ? 'active' : ''; ?>" href="?tab=payments">
                        <i class="fas fa-receipt"></i> Payments
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab == 'dues' ? 'active' : ''; ?>" href="?tab=dues">
                        <i class="fas fa-exclamation-triangle"></i> Due Reports
                    </a>
                </li>
            </ul>
            
            <!-- Tenants Tab -->
            <?php if($active_tab == 'tenants'): ?>
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-list"></i> Tenant List</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="tenantsTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Tenant Name</th>
                                    <th>Shop No</th>
                                    <th>Monthly Rent</th>
                                    <th>Phone</th>
                                    <th>Agreement</th>
                                    <th>Due</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($tenants as $tenant): ?>
                                <?php $due = isset($tenant_dues[$tenant['id']]['due_amount']) ? $tenant_dues[$tenant['id']]['due_amount'] : 0; ?>
                                <tr>
                                    <td><?php echo $tenant['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($tenant['tenant_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($tenant['shop_no']); ?></td>
                                    <td>BDT <?php echo number_format($tenant['monthly_rent'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($tenant['phone']); ?></td>
                                    <td><?php echo $tenant['agreement_start'] && $tenant['agreement_start'] != '0000-00-00' ? date('d/m/Y', strtotime($tenant['agreement_start'])) : '-'; ?></td>
                                    <td class="<?php echo $due > 0 ? 'text-danger fw-bold' : 'text-success'; ?>">
                                        <?php echo $due > 0 ? 'BDT ' . number_format($due, 2) : 'Fully Paid'; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-warning" onclick="editTenant(
                                            <?php echo $tenant['id']; ?>, 
                                            '<?php echo addslashes($tenant['tenant_name']); ?>', 
                                            '<?php echo addslashes($tenant['shop_no']); ?>', 
                                            <?php echo $tenant['monthly_rent']; ?>, 
                                            '<?php echo $tenant['agreement_start']; ?>', 
                                            '<?php echo $tenant['agreement_end']; ?>', 
                                            '<?php echo $tenant['phone']; ?>', 
                                            '<?php echo $tenant['email']; ?>', 
                                            '<?php echo addslashes($tenant['address']); ?>', 
                                            <?php echo $tenant['security_deposit']; ?>
                                        )">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn btn-sm btn-success" onclick="recordPayment(<?php echo $tenant['id']; ?>, '<?php echo addslashes($tenant['tenant_name']); ?>', <?php echo $tenant['monthly_rent']; ?>)">
                                            <i class="fas fa-money-bill"></i> Pay
                                        </button>
                                        <a href="?delete_id=<?php echo $tenant['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Deactivate this tenant?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                     </td>
                                 </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Payments Tab -->
            <?php if($active_tab == 'payments'): ?>
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5><i class="fas fa-receipt"></i> Payment History</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="paymentsTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Receipt No</th>
                                    <th>Tenant</th>
                                    <th>Shop</th>
                                    <th>Month</th>
                                    <th>Amount</th>
                                    <th>Late Fee</th>
                                    <th>Method</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($payments as $payment): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                                    <td><?php echo $payment['receipt_no']; ?></td>
                                    <td><?php echo htmlspecialchars($payment['tenant_name']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['shop_no']); ?></td>
                                    <td><?php echo date('F Y', strtotime($payment['month'] . '-01')); ?></td>
                                    <td>BDT <?php echo number_format($payment['amount'], 2); ?></td>
                                    <td>BDT <?php echo number_format($payment['late_fee'], 2); ?></td>
                                    <td><?php echo ucfirst($payment['payment_method'] ?? 'Cash'); ?></td>
                                    <td>
                                        <a href="?delete_payment=<?php echo $payment['id']; ?>&tab=payments" class="btn btn-sm btn-danger" onclick="return confirm('Delete this payment?')">
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
            <?php endif; ?>
            
            <!-- Dues Tab -->
            <?php if($active_tab == 'dues'): ?>
            <div class="card">
                <div class="card-header bg-danger text-white">
                    <h5><i class="fas fa-exclamation-triangle"></i> Tenants with Due</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="duesTable">
                            <thead>
                                <tr>
                                    <th>Tenant</th>
                                    <th>Shop No</th>
                                    <th>Monthly Rent</th>
                                    <th>Total Paid</th>
                                    <th>Due Amount</th>
                                    <th>Due Months</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($tenants as $tenant): 
                                    $due_info = isset($tenant_dues[$tenant['id']]) ? $tenant_dues[$tenant['id']] : ['due_amount'=>0, 'paid_amount'=>0, 'due_months'=>[]];
                                    if($due_info['due_amount'] > 0):
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($tenant['tenant_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($tenant['shop_no']); ?></td>
                                    <td>BDT <?php echo number_format($tenant['monthly_rent'], 2); ?></td>
                                    <td>BDT <?php echo number_format($due_info['paid_amount'], 2); ?></td>
                                    <td class="text-danger fw-bold">BDT <?php echo number_format($due_info['due_amount'], 2); ?></td>
                                    <td>
                                        <?php 
                                        $months_display = array_slice($due_info['due_months'], 0, 6);
                                        foreach($months_display as $m) {
                                            echo '<span class="due-badge">' . date('M Y', strtotime($m . '-01')) . '</span> ';
                                        }
                                        if(count($due_info['due_months']) > 6) {
                                            echo '<span class="due-badge">+' . (count($due_info['due_months']) - 6) . ' more</span>';
                                        }
                                        if(empty($due_info['due_months'])) {
                                            echo '<span class="paid-badge">No due months</span>';
                                        }
                                        ?>
                                     </td>
                                    <td>
                                        <button class="btn btn-sm btn-success" onclick="recordPayment(<?php echo $tenant['id']; ?>, '<?php echo addslashes($tenant['tenant_name']); ?>', <?php echo $tenant['monthly_rent']; ?>)">
                                            <i class="fas fa-money-bill"></i> Receive Payment
                                        </button>
                                     </td>
                                 </tr>
                                <?php endif; endforeach; ?>
                                <?php if(empty(array_filter($tenant_dues, fn($d)=>$d['due_amount']>0))): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-success">
                                        <i class="fas fa-check-circle"></i> All tenants are up to date! No dues pending.
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Add Tenant Modal -->
    <div class="modal fade" id="addTenantModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5><i class="fas fa-user-plus"></i> Add New Tenant</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <label>Tenant Name *</label>
                                <input type="text" name="tenant_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label>Shop No *</label>
                                <input type="text" name="shop_no" class="form-control" placeholder="Shop-01" required>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label>Monthly Rent (BDT) *</label>
                                <input type="number" name="monthly_rent" class="form-control" step="0.01" required>
                            </div>
                            <div class="col-md-6">
                                <label>Security Deposit (BDT)</label>
                                <input type="number" name="security_deposit" class="form-control" step="0.01" value="0">
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label>Agreement Start Date</label>
                                <input type="date" name="agreement_start" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label>Agreement End Date</label>
                                <input type="date" name="agreement_end" class="form-control">
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label>Phone</label>
                                <input type="text" name="phone" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label>Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                        </div>
                        <div class="mt-2">
                            <label>Address</label>
                            <textarea name="address" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="save_tenant" class="btn btn-primary">Save Tenant</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Record Payment Modal -->
    <div class="modal fade" id="paymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5><i class="fas fa-money-bill"></i> Record Rent Payment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="tenant_id" id="payment_tenant_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Tenant</label>
                            <input type="text" id="payment_tenant_name" class="form-control" readonly>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label>Payment Date</label>
                                <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label>Paying For Month</label>
                                <input type="month" name="month" class="form-control" value="<?php echo date('Y-m'); ?>" required>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label>Amount (BDT)</label>
                                <input type="number" name="amount" id="payment_amount" class="form-control" step="0.01" required>
                            </div>
                            <div class="col-md-6">
                                <label>Late Fee (BDT)</label>
                                <input type="number" name="late_fee" class="form-control" step="0.01" value="0">
                            </div>
                        </div>
                        <div class="mt-2">
                            <label>Payment Method</label>
                            <select name="payment_method" class="form-control" required>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="mobile_banking">Mobile Banking</option>
                            </select>
                        </div>
                        <div class="mt-2">
                            <label>Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="record_payment" class="btn btn-success">Record Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Tenant Modal -->
    <div class="modal fade" id="editTenantModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5><i class="fas fa-edit"></i> Edit Tenant</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="tenant_id" id="edit_tenant_id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <label>Tenant Name</label>
                                <input type="text" name="tenant_name" id="edit_tenant_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label>Shop No</label>
                                <input type="text" name="shop_no" id="edit_shop_no" class="form-control" required>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label>Monthly Rent (BDT)</label>
                                <input type="number" name="monthly_rent" id="edit_monthly_rent" class="form-control" step="0.01" required>
                            </div>
                            <div class="col-md-6">
                                <label>Security Deposit (BDT)</label>
                                <input type="number" name="security_deposit" id="edit_security_deposit" class="form-control" step="0.01">
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label>Agreement Start</label>
                                <input type="date" name="agreement_start" id="edit_agreement_start" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label>Agreement End</label>
                                <input type="date" name="agreement_end" id="edit_agreement_end" class="form-control">
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label>Phone</label>
                                <input type="text" name="phone" id="edit_phone" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label>Email</label>
                                <input type="email" name="email" id="edit_email" class="form-control">
                            </div>
                        </div>
                        <div class="mt-2">
                            <label>Address</label>
                            <textarea name="address" id="edit_address" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_tenant" class="btn btn-warning">Update Tenant</button>
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
            $('#tenantsTable, #paymentsTable, #duesTable').DataTable({
                order: [[0, 'asc']],
                pageLength: 25
            });
        });
        
        function editTenant(id, name, shopNo, rent, startDate, endDate, phone, email, address, deposit) {
            document.getElementById('edit_tenant_id').value = id;
            document.getElementById('edit_tenant_name').value = name;
            document.getElementById('edit_shop_no').value = shopNo;
            document.getElementById('edit_monthly_rent').value = rent;
            document.getElementById('edit_security_deposit').value = deposit;
            document.getElementById('edit_agreement_start').value = startDate;
            document.getElementById('edit_agreement_end').value = endDate;
            document.getElementById('edit_phone').value = phone;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_address').value = address;
            new bootstrap.Modal(document.getElementById('editTenantModal')).show();
        }
        
        function recordPayment(id, name, rent) {
            document.getElementById('payment_tenant_id').value = id;
            document.getElementById('payment_tenant_name').value = name;
            document.getElementById('payment_amount').value = rent;
            new bootstrap.Modal(document.getElementById('paymentModal')).show();
        }
    </script>
</body>
</html>
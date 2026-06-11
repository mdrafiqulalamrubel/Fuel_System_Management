<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Get tanks with calibration factors
$tanks = $pdo->query("SELECT t.*, p.product_name, p.purchase_rate 
                      FROM tanks t 
                      JOIN fuel_products p ON t.product_id = p.id")->fetchAll();

// Get the CORRECT account IDs from chart_of_accounts
// Stock Loss Expense account (Code: 5100)
$stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '5100' OR account_name = 'Stock Loss Expense'");
$stmt->execute();
$loss_expense = $stmt->fetch();

if(!$loss_expense) {
    // Create Stock Loss Expense account if not exists
    $stmt = $pdo->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, balance_type, is_active) VALUES ('5100', 'Stock Loss Expense', 'expense', 'debit', 1)");
    $stmt->execute();
    $loss_expense_id = $pdo->lastInsertId();
} else {
    $loss_expense_id = $loss_expense['id'];
}

// Fuel Inventory account (Code: 1200)
$stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '1200' OR account_name = 'Fuel Inventory'");
$stmt->execute();
$inventory_account = $stmt->fetch();

if(!$inventory_account) {
    // Create Fuel Inventory account if not exists
    $stmt = $pdo->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, balance_type, is_active) VALUES ('1200', 'Fuel Inventory', 'asset', 'debit', 1)");
    $stmt->execute();
    $inventory_account_id = $pdo->lastInsertId();
} else {
    $inventory_account_id = $inventory_account['id'];
}

// Verify we have the correct accounts - show warning if wrong
if($loss_expense_id) {
    $stmt = $pdo->prepare("SELECT account_name FROM chart_of_accounts WHERE id = ?");
    $stmt->execute([$loss_expense_id]);
    $check = $stmt->fetch();
    if($check && $check['account_name'] == 'Salary Expense') {
        $error = "WARNING: Stock Loss Expense account is misconfigured as 'Salary Expense'. Please run the SQL fix.";
    }
}

// Process adjustment
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_adjustment'])) {
    $tank_id = $_POST['tank_id'];
    $physical_stock = $_POST['physical_stock'];
    $dip_reading = $_POST['dip_reading'];
    $reason = $_POST['reason'];
    $adjustment_type = $_POST['adjustment_type'];
    
    // Get system stock and tank details
    $stmt = $pdo->prepare("SELECT t.*, p.product_name, p.purchase_rate 
                           FROM tanks t 
                           JOIN fuel_products p ON t.product_id = p.id 
                           WHERE t.id = ?");
    $stmt->execute([$tank_id]);
    $tank = $stmt->fetch();
    $system_stock = $tank['current_stock_liters'];
    $variance = $system_stock - $physical_stock;
    $loss_amount = $variance * $tank['purchase_rate'];
    
    if($variance <= 0) {
        $error = "Physical stock cannot be greater than or equal to system stock. Please verify measurements.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Insert leakage adjustment
            $stmt = $pdo->prepare("INSERT INTO leakage_adjustments (adjustment_date, tank_id, system_stock, physical_stock, variance, dip_stick_reading, reason, adjustment_type, loss_amount, created_by, status) VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved')");
            $stmt->execute([$tank_id, $system_stock, $physical_stock, $variance, $dip_reading, $reason, $adjustment_type, $loss_amount, $user['id']]);
            $adjustment_id = $pdo->lastInsertId();
            
            // Update tank stock
            $stmt = $pdo->prepare("UPDATE tanks SET current_stock_liters = ? WHERE id = ?");
            $stmt->execute([$physical_stock, $tank_id]);
            
            // Add to stock ledger
            $stmt = $pdo->prepare("INSERT INTO stock_ledger (product_id, tank_id, transaction_type, reference_no, out_quantity, balance_quantity, unit_cost) VALUES (?, ?, 'adjustment', ?, ?, ?, ?)");
            $stmt->execute([$tank['product_id'], $tank_id, "LEAK-$adjustment_id", $variance, $physical_stock, $tank['purchase_rate']]);
            
            // Create accounting entry for loss
            // CORRECT Journal Entry: Debit Stock Loss Expense, Credit Fuel Inventory
            $voucher_no = 'LOSS-' . date('YmdHis') . rand(100, 999);
            $narration = "Stock loss adjustment - Tank: {$tank['tank_name']} - Variance: " . number_format($variance, 2) . " Liters @ {$tank['purchase_rate']}/L";
            
            $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'journal', CURDATE(), ?, ?, 'approved')");
            $stmt->execute([$voucher_no, $narration, $user['id']]);
            $voucher_id = $pdo->lastInsertId();
            
            // IMPORTANT: Use the CORRECT account IDs
            // Debit Stock Loss Expense account (5100)
            // Credit Fuel Inventory account (1200)
            $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES (?, ?, ?, ?, ?), (?, ?, ?, ?, ?)");
            $stmt->execute([
                $voucher_id, $loss_expense_id, $loss_amount, 0, "Stock loss from {$tank['tank_name']} - {$variance} Liters",
                $voucher_id, $inventory_account_id, 0, $loss_amount, "Inventory reduction due to loss"
            ]);
            
            $pdo->commit();
            
            $currency = 'BDT';
            $success = "Adjustment completed successfully!<br>
                        <strong>Loss:</strong> " . number_format($variance, 2) . " Liters<br>
                        <strong>Loss Amount:</strong> $currency " . number_format($loss_amount, 2) . "<br>
                        <strong>Accounting Entry:</strong> Dr. Stock Loss Expense ($currency " . number_format($loss_amount, 2) . ") | Cr. Fuel Inventory ($currency " . number_format($loss_amount, 2) . ")";
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Get pending adjustments
$pending = $pdo->query("SELECT l.*, t.tank_name, p.product_name 
                        FROM leakage_adjustments l 
                        JOIN tanks t ON l.tank_id = t.id 
                        JOIN fuel_products p ON t.product_id = p.id 
                        WHERE l.status = 'pending' 
                        ORDER BY l.adjustment_date DESC")->fetchAll();

// Get recent adjustments history
$recent = $pdo->query("SELECT l.*, t.tank_name, p.product_name 
                        FROM leakage_adjustments l 
                        JOIN tanks t ON l.tank_id = t.id 
                        JOIN fuel_products p ON t.product_id = p.id 
                        WHERE l.status = 'approved' 
                        ORDER BY l.adjustment_date DESC 
                        LIMIT 20")->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

$total_loss_liters = 0;
$total_loss_amount = 0;
foreach($recent as $r) {
    $total_loss_liters += $r['variance'];
    $total_loss_amount += $r['loss_amount'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leakage & Wastage Management</title>
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
        }
        .formula-box {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin: 15px 0;
            border-radius: 8px;
        }
        .accounting-box {
            background: #e8f4f8;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 15px 0;
            border-radius: 8px;
        }
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 15px 0;
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-tint"></i> Leakage & Wastage Management</h2>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <!-- Warning if accounts are misconfigured -->
            <?php if($loss_expense_id): 
                $stmt = $pdo->prepare("SELECT account_name FROM chart_of_accounts WHERE id = ?");
                $stmt->execute([$loss_expense_id]);
                $check = $stmt->fetch();
                if($check && $check['account_name'] == 'Salary Expense'): ?>
                <div class="warning-box">
                    <h6><i class="fas fa-exclamation-triangle"></i> Account Configuration Error!</h6>
                    <p>Stock losses are being posted to <strong>"Salary Expense"</strong> account. This is incorrect.</p>
                    <p>Please run the following SQL to fix:</p>
                    <pre style="background:#fff; padding:10px;">
-- Fix the account mapping
UPDATE chart_of_accounts 
SET account_name = 'Stock Loss Expense', account_code = '5100' 
WHERE id = <?php echo $loss_expense_id; ?>;

-- Create a proper Salary Expense account if needed
INSERT INTO chart_of_accounts (account_code, account_name, account_type, balance_type, is_active) 
VALUES ('5110', 'Salary Expense', 'expense', 'debit', 1);
                    </pre>
                    <a href="?fix_accounts=1" class="btn btn-warning btn-sm">Click here after running SQL</a>
                </div>
            <?php endif; endif; ?>
            
            <!-- Statistics Cards -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-oil-can"></i>
                        <h3><?php echo number_format($total_loss_liters, 2); ?> L</h3>
                        <p>Total Loss (Liters)</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3>BDT <?php echo number_format($total_loss_amount, 2); ?></h3>
                        <p>Total Financial Loss</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-clock"></i>
                        <h3><?php echo count($pending); ?></h3>
                        <p>Pending Approvals</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-check-circle"></i>
                        <h3><?php echo count($recent); ?></h3>
                        <p>Total Adjustments</p>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-warning">
                            <h5><i class="fas fa-clipboard-list"></i> Physical Stock Verification</h5>
                        </div>
                        <div class="card-body">
                            <!-- Dip Stick Formula Box -->
                            <div class="formula-box">
                                <h6><i class="fas fa-calculator"></i> Dip Stick Calculation Formula</h6>
                                <div class="formula">
                                    <strong>Volume (Liters) = Dip Reading (cm) × Calibration Factor (L/cm)</strong>
                                </div>
                                <small>Example: 50 cm × 5.2345 L/cm = 261.73 Liters</small>
                            </div>
                            
                            <!-- Accounting Treatment Box -->
                            <div class="accounting-box">
                                <h6><i class="fas fa-book"></i> Correct Accounting Treatment</h6>
                                <div class="formula" style="background:#fff; text-align:left;">
                                    <strong>Journal Entry:</strong><br>
                                    Dr. Stock Loss Expense (Account 5100)    xxx<br>
                                    &nbsp;&nbsp;&nbsp;&nbsp;Cr. Fuel Inventory (Account 1200)    xxx
                                </div>
                                <small>Loss amount = Variance (Liters) × Purchase Rate (BDT/L)</small>
                            </div>
                            
                            <form method="POST">
                                <div class="mb-3">
                                    <label>Select Tank</label>
                                    <select name="tank_id" id="tank_id" class="form-control" required>
                                        <option value="">-- Select Tank --</option>
                                        <?php foreach($tanks as $tank): ?>
                                            <option value="<?php echo $tank['id']; ?>">
                                                <?php echo $tank['tank_name']; ?> - <?php echo $tank['product_name']; ?> 
                                                (Current: <?php echo number_format($tank['current_stock_liters'], 2); ?> L)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Dip Stick Reading (cm)</label>
                                            <input type="number" name="dip_reading" id="dip_reading" class="form-control" step="0.01">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Physical Stock (Liters)</label>
                                            <input type="number" name="physical_stock" id="physical_stock" class="form-control" step="0.01" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>System Stock (Liters)</label>
                                            <input type="text" id="system_stock" class="form-control" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Variance (Liters)</label>
                                            <input type="text" id="variance" class="form-control" readonly>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label>Adjustment Type</label>
                                    <select name="adjustment_type" class="form-control" required>
                                        <option value="leakage">Leakage</option>
                                        <option value="wastage">Wastage</option>
                                        <option value="theft">Theft</option>
                                        <option value="error">Measurement Error</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label>Reason Details</label>
                                    <textarea name="reason" class="form-control" rows="2" required></textarea>
                                </div>
                                
                                <button type="submit" name="submit_adjustment" class="btn btn-primary w-100">
                                    <i class="fas fa-save"></i> Submit Adjustment
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5><i class="fas fa-clock"></i> Recent Adjustments</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="recentTable">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Tank</th>
                                            <th>Variance</th>
                                            <th>Loss Amount</th>
                                            <th>Type</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($recent as $item): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y', strtotime($item['adjustment_date'])); ?></td>
                                            <td><?php echo $item['tank_name']; ?></td>
                                            <td class="text-danger"><?php echo number_format($item['variance'], 2); ?> L</td>
                                            <td class="text-danger">BDT <?php echo number_format($item['loss_amount'], 2); ?></td>
                                            <td><?php echo ucfirst($item['adjustment_type']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                 </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Account Info Card -->
                    <div class="card mt-3">
                        <div class="card-header bg-dark text-white">
                            <h5><i class="fas fa-info-circle"></i> Account Information</h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Stock Loss Expense Account:</strong></td>
                                    <td><?php 
                                        $stmt = $pdo->prepare("SELECT id, account_code, account_name FROM chart_of_accounts WHERE id = ?");
                                        $stmt->execute([$loss_expense_id]);
                                        $a = $stmt->fetch();
                                        echo $a ? "{$a['account_code']} - {$a['account_name']}" : 'Not found';
                                    ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Fuel Inventory Account:</strong></td>
                                    <td><?php 
                                        $stmt = $pdo->prepare("SELECT id, account_code, account_name FROM chart_of_accounts WHERE id = ?");
                                        $stmt->execute([$inventory_account_id]);
                                        $a = $stmt->fetch();
                                        echo $a ? "{$a['account_code']} - {$a['account_name']}" : 'Not found';
                                    ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#recentTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 10
            });
        });
        
        document.getElementById('tank_id').addEventListener('change', function() {
            let option = this.options[this.selectedIndex];
            let stock = option.getAttribute('data-stock') || 0;
            document.getElementById('system_stock').value = parseFloat(stock).toFixed(2);
        });
        
        document.getElementById('physical_stock').addEventListener('input', function() {
            let system = parseFloat(document.getElementById('system_stock').value) || 0;
            let physical = parseFloat(this.value) || 0;
            let variance = system - physical;
            document.getElementById('variance').value = variance.toFixed(2);
        });
    </script>
</body>
</html>
<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'adjustment';

// Get tanks with calibration factors
$tanks = $pdo->query("SELECT t.*, p.product_name, p.purchase_rate 
                      FROM tanks t 
                      JOIN fuel_products p ON t.product_id = p.id")->fetchAll();

// Get the CORRECT account IDs from chart_of_accounts
$stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '5100'");
$stmt->execute();
$loss_expense = $stmt->fetch();

if(!$loss_expense) {
    $stmt = $pdo->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, balance_type, is_active) VALUES ('5100', 'Stock Loss Expense', 'expense', 'debit', 1)");
    $stmt->execute();
    $loss_expense_id = $pdo->lastInsertId();
} else {
    $loss_expense_id = $loss_expense['id'];
}

$stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '1200'");
$stmt->execute();
$inventory_account = $stmt->fetch();

if(!$inventory_account) {
    $stmt = $pdo->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, balance_type, is_active) VALUES ('1200', 'Fuel Inventory', 'asset', 'debit', 1)");
    $stmt->execute();
    $inventory_account_id = $pdo->lastInsertId();
} else {
    $inventory_account_id = $inventory_account['id'];
}

// Process adjustment
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_adjustment'])) {
    $tank_id = $_POST['tank_id'];
    $calculation_method = $_POST['calculation_method'];
    $dip_reading = isset($_POST['dip_reading']) ? floatval($_POST['dip_reading']) : 0;
    $physical_stock = floatval($_POST['physical_stock']);
    $reason = $_POST['reason'];
    $adjustment_type = $_POST['adjustment_type'];
    
    $stmt = $pdo->prepare("SELECT t.*, p.product_name, p.purchase_rate 
                           FROM tanks t 
                           JOIN fuel_products p ON t.product_id = p.id 
                           WHERE t.id = ?");
    $stmt->execute([$tank_id]);
    $tank = $stmt->fetch();
    $system_stock = $tank['current_stock_liters'];
    
    // FIXED: For dip stick method, calculate physical stock from dip reading
    if($calculation_method == 'dip_stick' && $dip_reading > 0) {
        $physical_stock = $dip_reading * $tank['calibration_factor'];
    }
    
    // FIXED: Calculate variance correctly
    $variance = $system_stock - $physical_stock;
    $loss_amount = abs($variance) * $tank['purchase_rate'];
    
    // FIXED: Better validation for manual entry
    if($calculation_method == 'manual' && $physical_stock <= 0) {
        $error = "Physical stock cannot be zero or negative. Please enter a valid amount.";
    }
    elseif(abs($variance) < 0.01) {
        $error = "No difference between system stock and physical stock. No adjustment needed.";
    }
    else {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO leakage_adjustments (adjustment_date, tank_id, system_stock, physical_stock, variance, dip_stick_reading, reason, adjustment_type, loss_amount, created_by, status) VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved')");
            $stmt->execute([$tank_id, $system_stock, $physical_stock, $variance, $dip_reading, $reason, $adjustment_type, $loss_amount, $user['id']]);
            $adjustment_id = $pdo->lastInsertId();
            
            // Update tank stock
            $stmt = $pdo->prepare("UPDATE tanks SET current_stock_liters = ? WHERE id = ?");
            $stmt->execute([$physical_stock, $tank_id]);
            
            // Stock ledger entry
            $quantity = abs($variance);
            $stmt = $pdo->prepare("INSERT INTO stock_ledger (product_id, tank_id, transaction_type, reference_no, out_quantity, in_quantity, balance_quantity, unit_cost) VALUES (?, ?, 'adjustment', ?, ?, ?, ?, ?)");
            
            if($variance > 0) {
                // Loss: Stock decreased
                $stmt->execute([$tank['product_id'], $tank_id, "LEAK-$adjustment_id", $quantity, 0, $physical_stock, $tank['purchase_rate']]);
            } else {
                // Gain: Stock increased
                $stmt->execute([$tank['product_id'], $tank_id, "LEAK-$adjustment_id", 0, $quantity, $physical_stock, $tank['purchase_rate']]);
            }
            
            // Accounting entry
            $voucher_no = 'STKADJ-' . date('YmdHis') . rand(100, 999);
            $loss_or_gain = ($variance > 0) ? 'Loss' : 'Gain';
            $narration = "Stock $loss_or_gain adjustment - Tank: {$tank['tank_name']} - Variance: " . number_format(abs($variance), 2) . " Liters @ {$tank['purchase_rate']}/L";
            
            $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'journal', CURDATE(), ?, ?, 'approved')");
            $stmt->execute([$voucher_no, $narration, $user['id']]);
            $voucher_id = $pdo->lastInsertId();
            
            if($variance > 0) {
                // Loss: Debit Stock Loss Expense, Credit Fuel Inventory
                $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                    (?, ?, ?, ?, ?),
                    (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $voucher_id, $loss_expense_id, $loss_amount, 0, "Stock loss from {$tank['tank_name']} - " . number_format(abs($variance), 2) . " Liters",
                    $voucher_id, $inventory_account_id, 0, $loss_amount, "Inventory reduction due to loss"
                ]);
            } else {
                // Gain: Debit Fuel Inventory, Credit Stock Loss Expense
                $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                    (?, ?, ?, ?, ?),
                    (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $voucher_id, $inventory_account_id, $loss_amount, 0, "Stock gain from {$tank['tank_name']} - " . number_format(abs($variance), 2) . " Liters",
                    $voucher_id, $loss_expense_id, 0, $loss_amount, "Inventory gain adjustment"
                ]);
            }
            
            $pdo->commit();
            
            $currency = 'BDT';
            $success = "Adjustment completed successfully!<br>
                        <strong>System Stock:</strong> " . number_format($system_stock, 2) . " Liters<br>
                        <strong>Physical Stock:</strong> " . number_format($physical_stock, 2) . " Liters<br>
                        <strong>Variance:</strong> " . number_format(abs($variance), 2) . " Liters (" . ($variance > 0 ? 'Loss' : 'Gain') . ")<br>
                        <strong>Amount:</strong> $currency " . number_format($loss_amount, 2);
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Get recent adjustments history
$recent = $pdo->query("
    SELECT l.*, t.tank_name, p.product_name 
    FROM leakage_adjustments l 
    JOIN tanks t ON l.tank_id = t.id 
    JOIN fuel_products p ON t.product_id = p.id 
    WHERE l.status = 'approved' 
    ORDER BY l.adjustment_date DESC 
    LIMIT 50
")->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';

$total_loss_liters = 0;
$total_loss_amount = 0;
$total_gain_liters = 0;
$total_gain_amount = 0;
foreach($recent as $r) {
    if($r['variance'] > 0) {
        $total_loss_liters += $r['variance'];
        $total_loss_amount += $r['loss_amount'];
    } else {
        $total_gain_liters += abs($r['variance']);
        $total_gain_amount += abs($r['loss_amount']);
    }
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
            transition: transform 0.3s;
        }
        .stats-card:hover { transform: translateY(-5px); }
        .stats-card i { font-size: 40px; opacity: 0.5; float: right; }
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
        .method-toggle {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        .method-btn {
            flex: 1;
            padding: 10px;
            text-align: center;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.3s;
        }
        .method-btn.active {
            background: #28a745;
            color: white;
        }
        .method-btn.inactive {
            background: #e9ecef;
            color: #6c757d;
        }
        .calculation-section {
            display: none;
        }
        .calculation-section.active {
            display: block;
        }
        .tank-info {
            background: #fff3cd;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .current-dip-card {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .info-text {
            font-size: 14px;
            margin-top: 5px;
            color: #666;
        }
        .warning-text {
            color: #dc3545;
            font-size: 12px;
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
            
            <!-- Statistics Cards -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-tint"></i>
                        <h3 class="text-danger"><?php echo number_format($total_loss_liters, 2); ?> L</h3>
                        <p>Total Loss (Liters)</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_loss_amount, 2); ?></h3>
                        <p>Total Financial Loss</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                        <i class="fas fa-arrow-up"></i>
                        <h3><?php echo number_format($total_gain_liters, 2); ?> L</h3>
                        <p>Total Gain (Liters)</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);">
                        <i class="fas fa-chart-line"></i>
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
                                    <strong>For Loss (System Stock > Physical Stock):</strong><br>
                                    Dr. Stock Loss Expense (Account 5100)    xxx<br>
                                    &nbsp;&nbsp;&nbsp;&nbsp;Cr. Fuel Inventory (Account 1200)    xxx<br><br>
                                    <strong>For Gain (System Stock < Physical Stock):</strong><br>
                                    Dr. Fuel Inventory (Account 1200)    xxx<br>
                                    &nbsp;&nbsp;&nbsp;&nbsp;Cr. Stock Loss Expense (Account 5100)    xxx
                                </div>
                                <small>Amount = |Variance| (Liters) × Purchase Rate (<?php echo $currency; ?>/L)</small>
                            </div>
                            
                            <form method="POST" id="adjustmentForm">
                                <div class="mb-3">
                                    <label>Select Tank</label>
                                    <select name="tank_id" id="tank_id" class="form-control" required>
                                        <option value="">-- Select Tank --</option>
                                        <?php foreach($tanks as $tank): ?>
                                            <option value="<?php echo $tank['id']; ?>" 
                                                    data-calibration="<?php echo $tank['calibration_factor']; ?>"
                                                    data-stock="<?php echo $tank['current_stock_liters']; ?>"
                                                    data-purchase-rate="<?php echo $tank['purchase_rate']; ?>"
                                                    data-capacity="<?php echo $tank['capacity_liters']; ?>"
                                                    data-tank-name="<?php echo $tank['tank_name']; ?>">
                                                <?php echo $tank['tank_name']; ?> - <?php echo $tank['product_name']; ?> 
                                                (Current: <?php echo number_format($tank['current_stock_liters'], 2); ?> L | 
                                                Calibration: <?php echo $tank['calibration_factor']; ?> L/cm)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <!-- Current Dip Reading Card -->
                                <div id="currentDipCard" class="current-dip-card" style="display:none;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="fas fa-ruler fa-2x text-success"></i>
                                        </div>
                                        <div class="text-center">
                                            <h4 id="currentDipReading" class="mb-0">0.00</h4>
                                            <small>Current Dip Reading (cm)</small>
                                        </div>
                                        <div class="text-center">
                                            <h4 id="currentStockDisplay" class="mb-0">0</h4>
                                            <small>Current Stock (L)</small>
                                        </div>
                                        <div>
                                            <span class="badge bg-success" id="percentageFull">0%</span>
                                        </div>
                                    </div>
                                    <div class="progress mt-2" style="height: 8px;">
                                        <div id="stockProgress" class="progress-bar bg-success" style="width: 0%"></div>
                                    </div>
                                    <div class="info-text text-center mt-2">
                                        <small><i class="fas fa-info-circle"></i> Based on current system stock, dip stick should read <strong id="expectedDipReading">0.00</strong> cm</small>
                                    </div>
                                </div>
                                
                                <!-- Tank Info Display -->
                                <div id="tankInfo" class="tank-info" style="display:none;">
                                    <div class="row">
                                        <div class="col-6"><strong><i class="fas fa-warehouse"></i> System Stock:</strong> <br><span id="display_system_stock">0</span> L</div>
                                        <div class="col-6"><strong><i class="fas fa-chart-line"></i> Calibration Factor:</strong> <br><span id="display_calibration">0</span> L/cm</div>
                                        <div class="col-6 mt-2"><strong><i class="fas fa-dollar-sign"></i> Purchase Rate:</strong> <br><?php echo $currency; ?> <span id="display_purchase_rate">0</span>/L</div>
                                        <div class="col-6 mt-2"><strong><i class="fas fa-tachometer-alt"></i> Tank Capacity:</strong> <br><span id="display_capacity">0</span> L</div>
                                    </div>
                                </div>
                                
                                <!-- Calculation Method Toggle -->
                                <div class="method-toggle">
                                    <div class="method-btn active" data-method="manual" onclick="toggleMethod('manual')">
                                        <i class="fas fa-edit"></i> Manual Entry
                                    </div>
                                    <div class="method-btn inactive" data-method="dip_stick" onclick="toggleMethod('dip_stick')">
                                        <i class="fas fa-ruler"></i> Dip Stick Method
                                    </div>
                                </div>
                                
                                <input type="hidden" name="calculation_method" id="calculation_method" value="manual">
                                
                                <!-- Manual Entry Section -->
                                <div id="manual_section" class="calculation-section active">
                                    <div class="mb-3">
                                        <label>Physical Stock (Liters) *</label>
                                        <input type="number" name="physical_stock" id="physical_stock" class="form-control" step="0.01" placeholder="Enter measured physical stock">
                                        <small class="warning-text" id="manualWarning" style="display:none;">
                                            <i class="fas fa-exclamation-triangle"></i> Physical stock cannot be zero!
                                        </small>
                                    </div>
                                </div>
                                
                                <!-- Dip Stick Method Section -->
                                <div id="dip_stick_section" class="calculation-section">
                                    <div class="mb-3">
                                        <label>New Dip Stick Reading (cm) *</label>
                                        <input type="number" name="dip_reading" id="dip_reading" class="form-control" step="0.01" placeholder="Enter current dip reading in cm">
                                        <small class="text-muted">
                                            <i class="fas fa-info-circle"></i> 
                                            Expected reading based on system stock: <strong id="expectedDipLabel">0.00</strong> cm
                                        </small>
                                    </div>
                                    <div class="mb-3">
                                        <label>Calculated Physical Stock (Liters)</label>
                                        <input type="text" id="calculated_stock" class="form-control" readonly>
                                    </div>
                                    <input type="hidden" name="physical_stock" id="dip_physical_stock" value="0">
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>System Stock (Liters)</label>
                                            <input type="text" id="system_stock_display" class="form-control" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Variance (Liters)</label>
                                            <input type="text" id="variance_display" class="form-control" readonly>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label>Variance Amount (<?php echo $currency; ?>)</label>
                                    <input type="text" id="variance_amount" class="form-control" readonly>
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
                                    <label>Reason Details *</label>
                                    <textarea name="reason" class="form-control" rows="2" placeholder="Describe why adjustment is needed..." required></textarea>
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
                            <h5><i class="fas fa-history"></i> Recent Adjustments History</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="recentTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Date</th>
                                            <th>Tank</th>
                                            <th class="text-end">System Stock</th>
                                            <th class="text-end">Physical Stock</th>
                                            <th class="text-end">Variance</th>
                                            <th class="text-end">Amount</th>
                                            <th>Type</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($recent as $item): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y', strtotime($item['adjustment_date'])); ?></td>
                                            <td><?php echo $item['tank_name']; ?><br><small><?php echo $item['product_name']; ?></small></td>
                                            <td class="text-end"><?php echo number_format($item['system_stock'], 2); ?> L</div>
                                            <td class="text-end"><?php echo number_format($item['physical_stock'], 2); ?> L</div>
                                            <td class="text-end <?php echo $item['variance'] > 0 ? 'text-danger' : 'text-success'; ?> fw-bold">
                                                <?php echo ($item['variance'] > 0 ? '-' : '+') . number_format(abs($item['variance']), 2); ?> L
                                            </div>
                                            <td class="text-end <?php echo $item['variance'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                                <?php echo $currency; ?> <?php echo number_format($item['loss_amount'], 2); ?>
                                            </div>
                                            <td><span class="badge bg-secondary"><?php echo ucfirst($item['adjustment_type']); ?></span></div>
                                         </tr
                                        <?php endforeach; ?>
                                        <?php if(empty($recent)): ?>
                                         <tr
                                            <td colspan="7" class="text-center text-muted">No adjustments found</div>
                                         </tr
                                        <?php endif; ?>
                                    </tbody>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Account Info Card -->
                    <div class="card mt-3">
                        <div class="card-header bg-dark text-white">
                            <h5><i class="fas fa-info-circle"></i> Chart of Accounts Mapping</h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm">
                                 <tr
                                    <td><strong>Stock Loss Expense (5100):</strong></td>
                                    <td>
                                        <?php 
                                        $stmt = $pdo->prepare("SELECT id, account_code, account_name FROM chart_of_accounts WHERE id = ?");
                                        $stmt->execute([$loss_expense_id]);
                                        $a = $stmt->fetch();
                                        echo $a ? "<span class='text-primary'>{$a['account_code']}</span> - {$a['account_name']}" : '<span class="text-danger">Not configured</span>';
                                        ?>
                                     </div>
                                 </tr
                                 <tr
                                    <td><strong>Fuel Inventory (1200):</strong></td>
                                    <td>
                                        <?php 
                                        $stmt = $pdo->prepare("SELECT id, account_code, account_name FROM chart_of_accounts WHERE id = ?");
                                        $stmt->execute([$inventory_account_id]);
                                        $a = $stmt->fetch();
                                        echo $a ? "<span class='text-success'>{$a['account_code']}</span> - {$a['account_name']}" : '<span class="text-danger">Not configured</span>';
                                        ?>
                                     </div>
                                 </tr
                             </div>
                            <div class="alert alert-info mt-2 mb-0">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Note:</strong> Losses are debited to Stock Loss Expense. Gains are credited back to the same account.
                            </div>
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
                pageLength: 10,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
        
        // Toggle between manual and dip stick method
        function toggleMethod(method) {
            document.getElementById('calculation_method').value = method;
            
            document.querySelectorAll('.method-btn').forEach(btn => {
                btn.classList.remove('active');
                btn.classList.add('inactive');
            });
            document.querySelector(`.method-btn[data-method="${method}"]`).classList.add('active');
            
            if(method === 'manual') {
                document.getElementById('manual_section').classList.add('active');
                document.getElementById('dip_stick_section').classList.remove('active');
                document.getElementById('dip_reading').removeAttribute('required');
                document.getElementById('physical_stock').setAttribute('required', 'required');
            } else {
                document.getElementById('manual_section').classList.remove('active');
                document.getElementById('dip_stick_section').classList.add('active');
                document.getElementById('physical_stock').removeAttribute('required');
                document.getElementById('dip_reading').setAttribute('required', 'required');
            }
            
            calculateVariance();
        }
        
        // Calculate expected dip reading from stock
        function calculateExpectedDipReading(stock, calibrationFactor) {
            if(calibrationFactor > 0 && stock > 0) {
                return stock / calibrationFactor;
            }
            return 0;
        }
        
        // Tank selection change
        document.getElementById('tank_id').addEventListener('change', function() {
            let option = this.options[this.selectedIndex];
            if(!option.value) {
                document.getElementById('tankInfo').style.display = 'none';
                document.getElementById('currentDipCard').style.display = 'none';
                document.getElementById('system_stock_display').value = '';
                return;
            }
            
            let systemStock = parseFloat(option.getAttribute('data-stock')) || 0;
            let calibration = parseFloat(option.getAttribute('data-calibration')) || 1;
            let purchaseRate = parseFloat(option.getAttribute('data-purchase-rate')) || 0;
            let capacity = parseFloat(option.getAttribute('data-capacity')) || 0;
            
            // Calculate expected dip reading from current stock
            let expectedDipReading = calculateExpectedDipReading(systemStock, calibration);
            let percentageFull = capacity > 0 ? (systemStock / capacity) * 100 : 0;
            
            // Update Current Dip Card
            document.getElementById('currentDipCard').style.display = 'block';
            document.getElementById('currentDipReading').innerHTML = expectedDipReading.toFixed(2);
            document.getElementById('currentStockDisplay').innerHTML = systemStock.toFixed(0);
            document.getElementById('percentageFull').innerHTML = percentageFull.toFixed(1) + '%';
            document.getElementById('expectedDipReading').innerHTML = expectedDipReading.toFixed(2);
            
            // Update progress bar
            let progressBar = document.getElementById('stockProgress');
            progressBar.style.width = Math.min(percentageFull, 100) + '%';
            if(percentageFull < 20) {
                progressBar.className = 'progress-bar bg-danger';
            } else if(percentageFull < 50) {
                progressBar.className = 'progress-bar bg-warning';
            } else {
                progressBar.className = 'progress-bar bg-success';
            }
            
            // Update expected dip label in dip stick section
            document.getElementById('expectedDipLabel').innerHTML = expectedDipReading.toFixed(2);
            
            // Update Tank Info
            document.getElementById('tankInfo').style.display = 'block';
            document.getElementById('display_system_stock').innerHTML = systemStock.toFixed(2);
            document.getElementById('display_calibration').innerHTML = calibration.toFixed(4);
            document.getElementById('display_purchase_rate').innerHTML = purchaseRate.toFixed(2);
            document.getElementById('display_capacity').innerHTML = capacity.toFixed(0);
            
            document.getElementById('system_stock_display').value = systemStock.toFixed(2);
            
            calculateVariance();
        });
        
        // Manual physical stock input with validation
        document.getElementById('physical_stock').addEventListener('input', function() {
            let value = parseFloat(this.value) || 0;
            let warning = document.getElementById('manualWarning');
            
            if(value <= 0) {
                warning.style.display = 'block';
                this.style.borderColor = '#dc3545';
            } else {
                warning.style.display = 'none';
                this.style.borderColor = '#ced4da';
            }
            calculateVariance();
        });
        
        // Dip stick input
        document.getElementById('dip_reading').addEventListener('input', function() {
            let tankSelect = document.getElementById('tank_id');
            if(tankSelect.selectedIndex > 0) {
                let option = tankSelect.options[tankSelect.selectedIndex];
                let calibration = parseFloat(option.getAttribute('data-calibration')) || 1;
                let dipReading = parseFloat(this.value) || 0;
                let calculatedStock = dipReading * calibration;
                
                document.getElementById('calculated_stock').value = calculatedStock.toFixed(2);
                document.getElementById('dip_physical_stock').value = calculatedStock;
                
                calculateVariance();
            }
        });
        
        function calculateVariance() {
            let systemStock = parseFloat(document.getElementById('system_stock_display').value) || 0;
            let physicalStock = 0;
            let method = document.getElementById('calculation_method').value;
            
            if(method === 'manual') {
                physicalStock = parseFloat(document.getElementById('physical_stock').value) || 0;
            } else {
                physicalStock = parseFloat(document.getElementById('calculated_stock').value) || 0;
            }
            
            let variance = systemStock - physicalStock;
            let purchaseRate = 0;
            
            let tankSelect = document.getElementById('tank_id');
            if(tankSelect.selectedIndex > 0) {
                let option = tankSelect.options[tankSelect.selectedIndex];
                purchaseRate = parseFloat(option.getAttribute('data-purchase-rate')) || 0;
            }
            
            let varianceAmount = Math.abs(variance) * purchaseRate;
            
            let varianceDisplay = document.getElementById('variance_display');
            varianceDisplay.value = variance.toFixed(2);
            document.getElementById('variance_amount').value = varianceAmount.toFixed(2);
            
            // Color coding based on variance
            if(variance > 0) {
                varianceDisplay.style.color = '#dc3545';
                varianceDisplay.style.fontWeight = 'bold';
                document.getElementById('variance_amount').style.color = '#dc3545';
                varianceDisplay.title = 'Loss: System stock is higher than physical stock';
            } else if(variance < 0) {
                varianceDisplay.style.color = '#28a745';
                varianceDisplay.style.fontWeight = 'bold';
                document.getElementById('variance_amount').style.color = '#28a745';
                varianceDisplay.title = 'Gain: Physical stock is higher than system stock';
            } else {
                varianceDisplay.style.color = '#6c757d';
                varianceDisplay.style.fontWeight = 'normal';
                document.getElementById('variance_amount').style.color = '#6c757d';
                varianceDisplay.title = 'No variance';
            }
        }
        
        // Form validation
        document.getElementById('adjustmentForm').addEventListener('submit', function(e) {
            let tank = document.getElementById('tank_id').value;
            let method = document.getElementById('calculation_method').value;
            
            if(!tank) {
                e.preventDefault();
                alert('❌ Please select a tank');
                return false;
            }
            
            if(method === 'manual') {
                let physicalStock = parseFloat(document.getElementById('physical_stock').value) || 0;
                if(physicalStock <= 0) {
                    e.preventDefault();
                    alert('❌ Physical stock cannot be zero or negative! Please enter a valid amount.');
                    return false;
                }
                if(isNaN(physicalStock)) {
                    e.preventDefault();
                    alert('❌ Please enter a valid number for physical stock');
                    return false;
                }
            } else {
                let dipReading = parseFloat(document.getElementById('dip_reading').value) || 0;
                if(dipReading <= 0) {
                    e.preventDefault();
                    alert('❌ Please enter a valid dip stick reading in centimeters');
                    return false;
                }
                let calculatedStock = document.getElementById('calculated_stock').value;
                if(parseFloat(calculatedStock) <= 0) {
                    e.preventDefault();
                    alert('❌ Calculated physical stock is zero. Please check your dip reading and calibration factor.');
                    return false;
                }
                document.getElementById('dip_physical_stock').value = calculatedStock;
            }
            
            let variance = parseFloat(document.getElementById('variance_display').value) || 0;
            if(Math.abs(variance) < 0.01) {
                e.preventDefault();
                alert('⚠️ No variance detected. Adjustment not needed.');
                return false;
            }
            
            let reason = document.querySelector('textarea[name="reason"]').value;
            if(!reason.trim()) {
                e.preventDefault();
                alert('❌ Please provide a reason for this adjustment');
                return false;
            }
            
            // Confirm before submitting
            let confirmMsg = `⚠️ CONFIRM ADJUSTMENT ⚠️\n\n`;
            confirmMsg += `System Stock: ${document.getElementById('system_stock_display').value} L\n`;
            confirmMsg += `Physical Stock: ${method === 'manual' ? document.getElementById('physical_stock').value : document.getElementById('calculated_stock').value} L\n`;
            confirmMsg += `Variance: ${variance.toFixed(2)} L (${variance > 0 ? 'LOSS' : 'GAIN'})\n`;
            confirmMsg += `Amount: ${document.getElementById('variance_amount').value} ${document.getElementById('variance_amount').value ? 'BDT' : ''}\n\n`;
            confirmMsg += `Are you sure you want to proceed?`;
            
            return confirm(confirmMsg);
        });
    </script>
</body>
</html>
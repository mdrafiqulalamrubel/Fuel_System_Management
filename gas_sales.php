<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Get active shift
$stmt = $pdo->query("
    SELECT sc.*, sh.shift_name 
    FROM shift_closing sc 
    JOIN shifts sh ON sc.shift_id = sh.id 
    WHERE sc.status = 'open' 
    ORDER BY sc.id DESC 
    LIMIT 1
");
$active_shift = $stmt->fetch();

if(!$active_shift) {
    $error = "No active shift! Please start a shift first.";
}

// Get GAS nozzles (only GAS type)
$gas_nozzles = $pdo->query("
    SELECT n.*, t.tank_name, t.current_stock_liters 
    FROM nozzles n 
    JOIN tanks t ON n.tank_id = t.id 
    JOIN fuel_products p ON t.product_id = p.id 
    WHERE p.product_name IN ('CNG', 'LPG', 'Natural Gas') 
    AND n.is_active = 1 
    ORDER BY n.nozzle_name
")->fetchAll();

// Get products
$products = $pdo->query("SELECT * FROM fuel_products WHERE product_name IN ('CNG', 'LPG', 'Natural Gas') AND is_active = 1")->fetchAll();

// Process GAS Sale
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['make_gas_sale'])) {
    $invoice_no = 'GAS-' . date('YmdHis');
    $shift_id = $active_shift['id'] ?? 0;
    $nozzle_id = $_POST['nozzle_id'];
    $opening_meter = floatval($_POST['opening_meter']);
    $closing_meter = floatval($_POST['closing_meter']);
    $quantity = $closing_meter - $opening_meter;
    $unit_price = floatval($_POST['unit_price']);
    $sale_type = $_POST['sale_type'];
    $customer_name = $_POST['customer_name'] ?? '';
    $customer_phone = $_POST['customer_phone'] ?? '';
    $received = floatval($_POST['received'] ?? 0);
    $total_amount = $quantity * $unit_price;
    $change = $received - $total_amount;
    
    if($quantity <= 0) {
        $error = "Invalid quantity! Closing meter must be greater than opening meter.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Insert gas sale
            $stmt = $pdo->prepare("
                INSERT INTO gas_sales (
                    invoice_no, sale_date, shift_id, nozzle_id, operator_id,
                    customer_name, customer_phone, sale_type,
                    opening_meter, closing_meter, quantity_liters,
                    unit_price, total_amount, received_amount, change_amount
                ) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $invoice_no, $shift_id, $nozzle_id, $user['id'],
                $customer_name, $customer_phone, $sale_type,
                $opening_meter, $closing_meter, $quantity,
                $unit_price, $total_amount, $received, $change
            ]);
            $sale_id = $pdo->lastInsertId();
            
            // Update nozzle closing meter
            $stmt = $pdo->prepare("UPDATE nozzles SET closing_meter = ? WHERE id = ?");
            $stmt->execute([$closing_meter, $nozzle_id]);
            
            // Get tank id for stock update
            $stmt = $pdo->prepare("SELECT tank_id FROM nozzles WHERE id = ?");
            $stmt->execute([$nozzle_id]);
            $tank_id = $stmt->fetch()['tank_id'];
            
            // Update tank stock
            $stmt = $pdo->prepare("UPDATE tanks SET current_stock_liters = current_stock_liters - ? WHERE id = ?");
            $stmt->execute([$quantity, $tank_id]);
            
            // Stock ledger entry
            $stmt = $pdo->prepare("
                INSERT INTO stock_ledger (product_id, tank_id, transaction_type, reference_no, out_quantity, balance_quantity) 
                SELECT t.product_id, ?, 'sale', ?, ?, (SELECT current_stock_liters FROM tanks WHERE id = ?) 
                FROM tanks t WHERE t.id = ?
            ");
            $stmt->execute([$tank_id, $invoice_no, $quantity, $tank_id, $tank_id]);
            
            // Accounting entry for cash sale
            if($sale_type == 'cash') {
                $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1000' LIMIT 1");
                $cash_account = $stmt->fetch();
                $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '4000' LIMIT 1");
                $sales_account = $stmt->fetch();
                
                if($cash_account && $sales_account) {
                    $voucher_no = 'GASCASH-' . date('YmdHis') . rand(100, 999);
                    $stmt = $pdo->prepare("
                        INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) 
                        VALUES (?, 'receipt', CURDATE(), ?, ?, 'approved')
                    ");
                    $stmt->execute([$voucher_no, "GAS sale - $invoice_no", $user['id']]);
                    $voucher_id = $pdo->lastInsertId();
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                        (?, ?, ?, ?, ?),
                        (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $voucher_id, $cash_account['id'], $total_amount, 0, "GAS cash sale - $invoice_no",
                        $voucher_id, $sales_account['id'], 0, $total_amount, "GAS sales revenue - $invoice_no"
                    ]);
                }
            }
            
            $pdo->commit();
            
            $_SESSION['last_gas_invoice'] = [
                'invoice_no' => $invoice_no,
                'customer_name' => $customer_name,
                'date' => date('Y-m-d H:i:s'),
                'opening_meter' => $opening_meter,
                'closing_meter' => $closing_meter,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'total' => $total_amount,
                'received' => $received,
                'change' => $change
            ];
            
            $success = "✅ GAS sale completed! Invoice: $invoice_no";
            header("Location: print_gas_invoice.php");
            exit();
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    }
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GAS Sales with Meter Readings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .gas-card { background: white; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; overflow: hidden; }
        .gas-card-header { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white; padding: 15px 20px; font-weight: 600; }
        .amount-display { font-size: 28px; font-weight: bold; text-align: right; background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 10px; }
        .meter-display { font-size: 24px; font-weight: bold; color: #11998e; }
        .nozzle-btn { 
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); 
            color: white; border: none; border-radius: 10px; padding: 12px; margin: 5px; width: 100%;
            transition: all 0.2s;
        }
        .nozzle-btn:hover, .nozzle-btn.active { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); transform: scale(1.02); }
        .shift-info { background: #fff3cd; padding: 10px; border-radius: 8px; margin-bottom: 15px; }
        .meter-input { font-size: 18px; font-weight: bold; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-gas-pump"></i> GAS Sales (Meter Reading Based)</h2>
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
            
            <?php if($active_shift): ?>
            <div class="shift-info">
                <i class="fas fa-clock"></i> 
                <strong>Active Shift:</strong> <?php echo $active_shift['shift_name']; ?> | 
                <strong>Started:</strong> <?php echo date('d-m-Y h:i A', strtotime($active_shift['opening_time'])); ?>
            </div>
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> 
                No active shift! Please <a href="shift_closing.php">start a shift</a> first.
            </div>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-7">
                    <div class="gas-card">
                        <div class="gas-card-header">
                            <i class="fas fa-gas-pump"></i> New GAS Sale
                        </div>
                        <div class="card-body p-3">
                            <form method="POST" id="gasSaleForm">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-oil-can"></i> Select GAS Nozzle</label>
                                            <select name="nozzle_id" id="nozzle_id" class="form-control" required>
                                                <option value="">-- Select Nozzle --</option>
                                                <?php foreach($gas_nozzles as $nozzle): ?>
                                                    <option value="<?php echo $nozzle['id']; ?>" 
                                                            data-last-meter="<?php echo $nozzle['closing_meter']; ?>"
                                                            data-stock="<?php echo $nozzle['current_stock_liters']; ?>">
                                                        <?php echo $nozzle['nozzle_name']; ?> - <?php echo $nozzle['tank_name']; ?>
                                                        (Current Meter: <?php echo number_format($nozzle['closing_meter'], 2); ?> | Stock: <?php echo number_format($nozzle['current_stock_liters'], 2); ?> L)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-tag"></i> Sale Type</label>
                                            <select name="sale_type" id="sale_type" class="form-control" required>
                                                <option value="cash">Cash</option>
                                                <option value="credit">Credit</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-tachometer-alt"></i> Opening Meter Reading</label>
                                            <input type="number" name="opening_meter" id="opening_meter" class="form-control meter-input" step="0.01" readonly required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-tachometer-alt"></i> Closing Meter Reading</label>
                                            <input type="number" name="closing_meter" id="closing_meter" class="form-control meter-input" step="0.01" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-arrow-right"></i> Quantity (Liters)</label>
                                            <input type="text" id="quantity_display" class="form-control" readonly>
                                            <input type="hidden" name="quantity" id="quantity_hidden">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-money-bill"></i> Unit Price (<?php echo $currency; ?>/L)</label>
                                            <input type="number" name="unit_price" id="unit_price" class="form-control" step="0.01" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div id="customer_fields" style="display:none;">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label>Customer Name *</label>
                                                <input type="text" name="customer_name" id="customer_name" class="form-control">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label>Customer Phone</label>
                                                <input type="text" name="customer_phone" id="customer_phone" class="form-control">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="amount-display">
                                    <table width="100%">
                                        <tr>
                                            <td><strong>TOTAL AMOUNT:</strong></td>
                                            <td class="text-end total-amount" style="font-size: 32px; color: #28a745;">
                                                <?php echo $currency; ?> <span id="total_amount">0.00</span>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                
                                <div id="cash_fields">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label>Received Amount (<?php echo $currency; ?>)</label>
                                                <input type="number" name="received" id="received" class="form-control" step="0.01" value="0">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label>Change (<?php echo $currency; ?>)</label>
                                                <input type="text" id="change_amount" class="form-control" readonly>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="submit" name="make_gas_sale" class="btn btn-success w-100" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); border: none; padding: 12px;">
                                    <i class="fas fa-print"></i> Process GAS Sale & Print Receipt
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-5">
                    <div class="gas-card">
                        <div class="gas-card-header">
                            <i class="fas fa-oil-can"></i> Available GAS Nozzles
                        </div>
                        <div class="card-body p-3">
                            <div class="row">
                                <?php foreach($gas_nozzles as $nozzle): ?>
                                    <div class="col-6 mb-2">
                                        <button type="button" class="nozzle-btn quick-nozzle" 
                                                data-id="<?php echo $nozzle['id']; ?>"
                                                data-last-meter="<?php echo $nozzle['closing_meter']; ?>"
                                                data-stock="<?php echo $nozzle['current_stock_liters']; ?>"
                                                data-tank="<?php echo $nozzle['tank_name']; ?>">
                                            <i class="fas fa-gas-pump"></i><br>
                                            <strong><?php echo $nozzle['nozzle_name']; ?></strong><br>
                                            <small><?php echo $nozzle['tank_name']; ?></small>
                                            <span class="nozzle-stock d-block mt-1" style="font-size:10px; background:rgba(255,255,255,0.2); padding:2px 6px; border-radius:12px;">
                                                Meter: <?php echo number_format($nozzle['closing_meter'], 2); ?> L
                                            </span>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                                <?php if(empty($gas_nozzles)): ?>
                                    <div class="col-12">
                                        <div class="alert alert-info text-center">
                                            <i class="fas fa-info-circle"></i> No GAS nozzles configured. Please add GAS products and nozzles.
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Nozzle selection
        document.getElementById('nozzle_id').addEventListener('change', function() {
            let option = this.options[this.selectedIndex];
            let lastMeter = parseFloat(option.getAttribute('data-last-meter')) || 0;
            document.getElementById('opening_meter').value = lastMeter.toFixed(2);
            calculateTotal();
        });

        // Quick nozzle selection
        document.querySelectorAll('.quick-nozzle').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('nozzle_id').value = this.getAttribute('data-id');
                let lastMeter = parseFloat(this.getAttribute('data-last-meter')) || 0;
                document.getElementById('opening_meter').value = lastMeter.toFixed(2);
                document.getElementById('closing_meter').focus();
                document.querySelectorAll('.quick-nozzle').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                calculateTotal();
            });
        });

        // Closing meter input
        document.getElementById('closing_meter').addEventListener('input', calculateTotal);
        document.getElementById('unit_price').addEventListener('input', calculateTotal);
        document.getElementById('received').addEventListener('input', calculateChange);

        // Sale type change
        document.getElementById('sale_type').addEventListener('change', function() {
            if(this.value == 'credit') {
                document.getElementById('customer_fields').style.display = 'block';
                document.getElementById('cash_fields').style.display = 'none';
                document.getElementById('customer_name').setAttribute('required', 'required');
            } else {
                document.getElementById('customer_fields').style.display = 'none';
                document.getElementById('cash_fields').style.display = 'block';
                document.getElementById('customer_name').removeAttribute('required');
                document.getElementById('received').value = 0;
            }
        });

        function calculateTotal() {
            let opening = parseFloat(document.getElementById('opening_meter').value) || 0;
            let closing = parseFloat(document.getElementById('closing_meter').value) || 0;
            let quantity = closing - opening;
            let price = parseFloat(document.getElementById('unit_price').value) || 0;
            let total = quantity * price;
            
            document.getElementById('quantity_display').value = quantity.toFixed(2) + ' L';
            document.getElementById('quantity_hidden').value = quantity.toFixed(2);
            document.getElementById('total_amount').innerText = total.toFixed(2);
            calculateChange();
        }

        function calculateChange() {
            let total = parseFloat(document.getElementById('total_amount').innerText) || 0;
            let received = parseFloat(document.getElementById('received').value) || 0;
            let change = received - total;
            document.getElementById('change_amount').value = change.toFixed(2);
        }

        // Form validation
        document.getElementById('gasSaleForm').addEventListener('submit', function(e) {
            let nozzle = document.getElementById('nozzle_id').value;
            let opening = parseFloat(document.getElementById('opening_meter').value) || 0;
            let closing = parseFloat(document.getElementById('closing_meter').value) || 0;
            let quantity = closing - opening;
            
            if(!nozzle) {
                e.preventDefault();
                alert('Please select a GAS nozzle');
                return false;
            }
            
            if(closing <= opening) {
                e.preventDefault();
                alert('Closing meter reading must be greater than opening meter reading!');
                return false;
            }
            
            if(quantity <= 0) {
                e.preventDefault();
                alert('Invalid quantity! Please check meter readings.');
                return false;
            }
            
            let saleType = document.getElementById('sale_type').value;
            if(saleType == 'credit') {
                let customerName = document.getElementById('customer_name').value;
                if(!customerName) {
                    e.preventDefault();
                    alert('Please enter customer name for credit sale');
                    return false;
                }
            }
        });
    </script>
</body>
</html>
<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Get active nozzles
$nozzles = $pdo->query("
    SELECT n.*, t.product_id, p.product_name, p.unit_price, t.current_stock_liters 
    FROM nozzles n 
    JOIN tanks t ON n.tank_id = t.id 
    JOIN fuel_products p ON t.product_id = p.id 
    WHERE n.is_active = 1
    ORDER BY n.nozzle_name
")->fetchAll();

$products = $pdo->query("SELECT * FROM fuel_products WHERE is_active = 1")->fetchAll();
$shifts = $pdo->query("SELECT * FROM shifts WHERE is_active = 1")->fetchAll();

// Process sale
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['make_sale'])) {
    $invoice_no = 'INV-' . date('YmdHis');
    $shift_id = $_POST['shift_id'];
    $nozzle_id = $_POST['nozzle_id'];
    $product_id = $_POST['product_id'];
    $quantity = floatval($_POST['quantity']);
    $unit_price = floatval($_POST['unit_price']);
    $sale_type = $_POST['sale_type'];
    $customer_name = isset($_POST['customer_name']) ? $_POST['customer_name'] : '';
    $customer_phone = isset($_POST['customer_phone']) ? $_POST['customer_phone'] : '';
    
    $subtotal = $quantity * $unit_price;
    
    $stmt = $pdo->prepare("SELECT vat_percentage, tax_percentage FROM fuel_products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();
    
    $vat_amount = $subtotal * (floatval($product['vat_percentage']) / 100);
    $tax_amount = $subtotal * (floatval($product['tax_percentage']) / 100);
    $total_amount = $subtotal + $vat_amount + $tax_amount;
    
    $received = isset($_POST['received']) ? floatval($_POST['received']) : 0;
    $change = $received - $total_amount;
    
    try {
        $pdo->beginTransaction();
        
        // Insert sale
        $stmt = $pdo->prepare("INSERT INTO sales (invoice_no, shift_id, nozzle_id, operator_id, customer_name, customer_phone, sale_type, product_id, quantity_liters, unit_price, subtotal, vat_amount, tax_amount, total_amount, received_amount, change_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$invoice_no, $shift_id, $nozzle_id, $user['id'], $customer_name, $customer_phone, $sale_type, $product_id, $quantity, $unit_price, $subtotal, $vat_amount, $tax_amount, $total_amount, $received, $change]);
        $sale_id = $pdo->lastInsertId();
        
        // Update stock
        $stmt = $pdo->prepare("UPDATE tanks t JOIN nozzles n ON n.tank_id = t.id SET t.current_stock_liters = t.current_stock_liters - ? WHERE n.id = ?");
        $stmt->execute([$quantity, $nozzle_id]);
        
        $stmt = $pdo->prepare("UPDATE nozzles SET closing_meter = closing_meter + ? WHERE id = ?");
        $stmt->execute([$quantity, $nozzle_id]);
        
        $stmt = $pdo->prepare("INSERT INTO stock_ledger (product_id, tank_id, transaction_type, reference_no, out_quantity, balance_quantity) SELECT ?, tank_id, 'sale', ?, ?, (SELECT current_stock_liters FROM tanks WHERE id = tank_id) FROM nozzles WHERE id = ?");
        $stmt->execute([$product_id, $invoice_no, $quantity, $nozzle_id]);
        
        // Get account IDs - FIXED with better error handling
        $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '1300'");
        $stmt->execute();
        $ar_account = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '4000'");
        $stmt->execute();
        $sales_account = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '1000'");
        $stmt->execute();
        $cash_account = $stmt->fetch();
        
        // Log account IDs for debugging
        error_log("AR Account ID: " . ($ar_account['id'] ?? 'NOT FOUND'));
        error_log("Sales Account ID: " . ($sales_account['id'] ?? 'NOT FOUND'));
        error_log("Cash Account ID: " . ($cash_account['id'] ?? 'NOT FOUND'));
        
        $customer_id = null;
        
        // Handle Credit Sale
        if($sale_type == 'credit') {
            // Get or create customer
            if(!empty($customer_phone)) {
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ?");
                $stmt->execute([$customer_phone]);
                $existing = $stmt->fetch();
                if($existing) {
                    $customer_id = $existing['id'];
                    // Update customer name if changed
                    $stmt = $pdo->prepare("UPDATE customers SET customer_name = ? WHERE id = ?");
                    $stmt->execute([$customer_name, $customer_id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO customers (customer_code, customer_name, phone, credit_limit) VALUES (?, ?, ?, ?)");
                    $customer_code = 'CUST-' . date('Ymd') . rand(100, 999);
                    $stmt->execute([$customer_code, $customer_name, $customer_phone, 50000]);
                    $customer_id = $pdo->lastInsertId();
                }
            } else {
                // Check if customer exists by name
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE customer_name = ?");
                $stmt->execute([$customer_name]);
                $existing = $stmt->fetch();
                if($existing) {
                    $customer_id = $existing['id'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO customers (customer_code, customer_name, credit_limit) VALUES (?, ?, ?)");
                    $customer_code = 'CUST-' . date('Ymd') . rand(100, 999);
                    $stmt->execute([$customer_code, $customer_name, 50000]);
                    $customer_id = $pdo->lastInsertId();
                }
            }
            
            // Insert into credit_sales
            $due_date = date('Y-m-d', strtotime('+30 days'));
            $stmt = $pdo->prepare("INSERT INTO credit_sales (sale_id, customer_id, invoice_no, sale_date, due_date, total_amount, paid_amount, balance_due, status) VALUES (?, ?, ?, CURDATE(), ?, ?, 0, ?, 'pending')");
            $stmt->execute([$sale_id, $customer_id, $invoice_no, $due_date, $total_amount, $total_amount]);
            
            // Update customer current balance
            $stmt = $pdo->prepare("UPDATE customers SET current_balance = current_balance + ? WHERE id = ?");
            $stmt->execute([$total_amount, $customer_id]);
            
            // Create accounting entry for credit sale - FIXED
            if($ar_account && $sales_account) {
                $voucher_no = 'CREDIT-' . date('YmdHis') . rand(100, 999);
                $narration = "Credit sale to $customer_name - Invoice: $invoice_no - Amount: BDT $total_amount";
                
                $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'journal', CURDATE(), ?, ?, 'approved')");
                $stmt->execute([$voucher_no, $narration, $user['id']]);
                $voucher_id = $pdo->lastInsertId();
                
                // Debit Accounts Receivable (1300), Credit Sales Revenue (4000)
                $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                    (?, ?, ?, ?, ?),
                    (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $voucher_id, $ar_account['id'], $total_amount, 0, "Credit sale to $customer_name - Invoice: $invoice_no",
                    $voucher_id, $sales_account['id'], 0, $total_amount, "Fuel sale revenue - Invoice: $invoice_no"
                ]);
                
                $success_msg = "Credit sale recorded! Accounts Receivable updated.";
            } else {
                $success_msg = "Credit sale recorded but accounting accounts not found!";
                error_log("ERROR: AR Account or Sales Account not found in chart_of_accounts");
            }
        } 
        // Handle Cash Sale
        else if($sale_type == 'cash') {
            if($cash_account && $sales_account) {
                $voucher_no = 'CASH-' . date('YmdHis') . rand(100, 999);
                $narration = "Cash sale - Invoice: $invoice_no - Amount: BDT $total_amount";
                
                $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'receipt', CURDATE(), ?, ?, 'approved')");
                $stmt->execute([$voucher_no, $narration, $user['id']]);
                $voucher_id = $pdo->lastInsertId();
                
                // Debit Cash (1000), Credit Sales Revenue (4000)
                $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                    (?, ?, ?, ?, ?),
                    (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $voucher_id, $cash_account['id'], $total_amount, 0, "Cash sale - Invoice: $invoice_no",
                    $voucher_id, $sales_account['id'], 0, $total_amount, "Fuel sale revenue - Invoice: $invoice_no"
                ]);
            }
        }
        
        $pdo->commit();
        
        // Get product name
        $stmt = $pdo->prepare("SELECT product_name FROM fuel_products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product_data = $stmt->fetch();
        
        $_SESSION['last_invoice'] = [
            'invoice_no' => $invoice_no,
            'customer_name' => $customer_name,
            'customer_phone' => $customer_phone,
            'date' => date('Y-m-d H:i:s'),
            'product_id' => $product_id,
            'product' => $product_data['product_name'],
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'subtotal' => $subtotal,
            'vat' => $vat_amount,
            'tax' => $tax_amount,
            'total' => $total_amount,
            'received' => $received,
            'change' => $change,
            'sale_type' => $sale_type,
            'customer_id' => $customer_id
        ];
        
        $success = "Sale completed! Invoice: $invoice_no";
        $_SESSION['open_print'] = true;
        header("Location: pos.php?print=1");
        exit();
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Check if we need to open print window
$open_print = isset($_GET['print']) && isset($_SESSION['open_print']);
if($open_print) {
    unset($_SESSION['open_print']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS - Fuel Sales</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .pos-card { background: white; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; overflow: hidden; }
        .pos-card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 20px; font-weight: 600; }
        .pos-card-body { padding: 20px; }
        .product-btn { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 10px; padding: 15px; margin: 5px; width: 100%; }
        .amount-display { font-size: 24px; font-weight: bold; text-align: right; background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 10px; }
        .nozzle-btn { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; border: none; border-radius: 10px; padding: 12px; margin: 5px; width: 100%; }
        .print-toast { position: fixed; bottom: 20px; right: 20px; background: #4CAF50; color: white; padding: 15px 20px; border-radius: 5px; display: none; z-index: 9999; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-7">
                    <div class="pos-card">
                        <div class="pos-card-header">
                            <i class="fas fa-shopping-cart"></i> New Fuel Sale
                        </div>
                        <div class="pos-card-body">
                            <form method="POST" id="saleForm">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-clock"></i> Shift</label>
                                            <select name="shift_id" class="form-control" required>
                                                <option value="">Select Shift</option>
                                                <?php foreach($shifts as $shift): ?>
                                                    <option value="<?php echo $shift['id']; ?>"><?php echo $shift['shift_name']; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-oil-can"></i> Select Nozzle</label>
                                            <select name="nozzle_id" id="nozzle_id" class="form-control" required>
                                                <option value="">-- Select Nozzle --</option>
                                                <?php foreach($nozzles as $nozzle): ?>
                                                    <option value="<?php echo $nozzle['id']; ?>" 
                                                            data-product="<?php echo $nozzle['product_id']; ?>"
                                                            data-product-name="<?php echo $nozzle['product_name']; ?>"
                                                            data-price="<?php echo $nozzle['unit_price']; ?>">
                                                        <?php echo $nozzle['nozzle_name']; ?> - <?php echo $nozzle['product_name']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-tag"></i> Product</label>
                                            <input type="text" id="product_name" class="form-control" readonly>
                                            <input type="hidden" name="product_id" id="product_id">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-money-bill"></i> Unit Price (BDT/L)</label>
                                            <input type="number" name="unit_price" id="unit_price" class="form-control" step="0.01" readonly required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-tachometer-alt"></i> Quantity (Liters)</label>
                                            <input type="number" name="quantity" id="quantity" class="form-control" step="0.01" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label><i class="fas fa-credit-card"></i> Sale Type</label>
                                            <select name="sale_type" id="sale_type" class="form-control" required>
                                                <option value="cash">Cash</option>
                                                <option value="credit">Credit</option>
                                            </select>
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
                                        <tr><td>Subtotal:</td><td class="text-end">BDT <span id="subtotal">0.00</span></td></tr>
                                        <tr><td>VAT (5%):</td><td class="text-end">BDT <span id="vat_amount">0.00</span></td></tr>
                                        <tr><td>Tax (2%):</td><td class="text-end">BDT <span id="tax_amount">0.00</span></td></tr>
                                        <tr class="fw-bold"><td>TOTAL:</td><td class="text-end">BDT <span id="total_amount">0.00</span></td></tr>
                                    </table>
                                </div>
                                
                                <div id="cash_fields">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label>Received Amount (BDT)</label>
                                                <input type="number" name="received" id="received" class="form-control" step="0.01" value="0">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label>Change (BDT)</label>
                                                <input type="text" id="change_amount" class="form-control" readonly>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="submit" name="make_sale" class="btn btn-success w-100" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); border: none; padding: 12px;">
                                    <i class="fas fa-print"></i> Process Sale & Print Receipt
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-5">
                    <div class="pos-card">
                        <div class="pos-card-header">
                            <i class="fas fa-box"></i> Quick Products
                        </div>
                        <div class="pos-card-body">
                            <div class="row">
                                <?php foreach($products as $product): ?>
                                    <div class="col-6 mb-2">
                                        <button type="button" class="product-btn quick-product" 
                                                data-id="<?php echo $product['id']; ?>"
                                                data-name="<?php echo $product['product_name']; ?>"
                                                data-price="<?php echo $product['unit_price']; ?>">
                                            <i class="fas fa-gas-pump"></i><br>
                                            <strong><?php echo $product['product_name']; ?></strong><br>
                                            BDT <?php echo number_format($product['unit_price'], 2); ?>/L
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="pos-card">
                        <div class="pos-card-header">
                            <i class="fas fa-oil-can"></i> Available Nozzles
                        </div>
                        <div class="pos-card-body">
                            <div class="row">
                                <?php foreach($nozzles as $nozzle): ?>
                                    <div class="col-6 mb-2">
                                        <button type="button" class="nozzle-btn quick-nozzle" 
                                                data-id="<?php echo $nozzle['id']; ?>"
                                                data-product="<?php echo $nozzle['product_id']; ?>"
                                                data-product-name="<?php echo $nozzle['product_name']; ?>"
                                                data-price="<?php echo $nozzle['unit_price']; ?>">
                                            <i class="fas fa-oil-can"></i><br>
                                            <strong><?php echo $nozzle['nozzle_name']; ?></strong><br>
                                            <small><?php echo $nozzle['product_name']; ?></small>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div id="printToast" class="print-toast">
        <i class="fas fa-print"></i> Opening print preview...
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if($open_print): ?>
        $(document).ready(function() {
            $('#printToast').fadeIn(300);
            setTimeout(function() {
                var printWindow = window.open('print_invoice.php', '_blank', 'width=500,height=700,scrollbars=yes');
                if(printWindow) { printWindow.focus(); }
                else { alert('Please allow popups for this site to print invoices.'); }
                setTimeout(function() { $('#printToast').fadeOut(500); }, 3000);
            }, 500);
        });
        <?php endif; ?>
        
        document.getElementById('nozzle_id').addEventListener('change', function() {
            let option = this.options[this.selectedIndex];
            document.getElementById('product_name').value = option.getAttribute('data-product-name');
            document.getElementById('product_id').value = option.getAttribute('data-product');
            document.getElementById('unit_price').value = option.getAttribute('data-price');
            calculateTotal();
        });
        
        document.querySelectorAll('.quick-nozzle').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('nozzle_id').value = this.getAttribute('data-id');
                document.getElementById('product_name').value = this.getAttribute('data-product-name');
                document.getElementById('product_id').value = this.getAttribute('data-product');
                document.getElementById('unit_price').value = this.getAttribute('data-price');
                document.querySelectorAll('.quick-nozzle').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                calculateTotal();
            });
        });
        
        document.querySelectorAll('.quick-product').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('product_name').value = this.getAttribute('data-name');
                document.getElementById('product_id').value = this.getAttribute('data-id');
                document.getElementById('unit_price').value = this.getAttribute('data-price');
                document.getElementById('quantity').focus();
                calculateTotal();
            });
        });
        
        document.getElementById('quantity').addEventListener('input', calculateTotal);
        document.getElementById('unit_price').addEventListener('input', calculateTotal);
        document.getElementById('received').addEventListener('input', calculateChange);
        
        document.getElementById('sale_type').addEventListener('change', function() {
            if(this.value == 'credit') {
                document.getElementById('customer_fields').style.display = 'block';
                document.getElementById('cash_fields').style.display = 'none';
                document.getElementById('customer_name').setAttribute('required', 'required');
            } else {
                document.getElementById('customer_fields').style.display = 'none';
                document.getElementById('cash_fields').style.display = 'block';
                document.getElementById('customer_name').removeAttribute('required');
                document.getElementById('received').value = '';
            }
        });
        
        function calculateTotal() {
            let qty = parseFloat(document.getElementById('quantity').value) || 0;
            let price = parseFloat(document.getElementById('unit_price').value) || 0;
            let subtotal = qty * price;
            let vat = subtotal * 0.05;
            let tax = subtotal * 0.02;
            let total = subtotal + vat + tax;
            
            document.getElementById('subtotal').innerText = subtotal.toFixed(2);
            document.getElementById('vat_amount').innerText = vat.toFixed(2);
            document.getElementById('tax_amount').innerText = tax.toFixed(2);
            document.getElementById('total_amount').innerText = total.toFixed(2);
            calculateChange();
        }
        
        function calculateChange() {
            let total = parseFloat(document.getElementById('total_amount').innerText) || 0;
            let received = parseFloat(document.getElementById('received').value) || 0;
            let change = received - total;
            document.getElementById('change_amount').value = change.toFixed(2);
        }
        
        document.getElementById('saleForm').addEventListener('submit', function(e) {
            let nozzle = document.getElementById('nozzle_id').value;
            let quantity = document.getElementById('quantity').value;
            let saleType = document.getElementById('sale_type').value;
            
            if(!nozzle) { e.preventDefault(); alert('Please select a nozzle'); return false; }
            if(!quantity || quantity <= 0) { e.preventDefault(); alert('Please enter valid quantity'); return false; }
            
            if(saleType == 'credit') {
                let customerName = document.getElementById('customer_name').value;
                if(!customerName) { e.preventDefault(); alert('Please enter customer name for credit sale'); return false; }
            }
        });
    </script>
</body>
</html>
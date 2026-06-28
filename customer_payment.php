<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Get Chart of Accounts
$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1000' OR account_name LIKE '%Cash%' LIMIT 1");
$cash_account = $stmt->fetch();

$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1300' OR account_name LIKE '%Accounts Receivable%' LIMIT 1");
$ar_account = $stmt->fetch();

$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '3300' OR account_name LIKE '%Customer Advance%' LIMIT 1");
$customer_advance_account = $stmt->fetch();

if(!$customer_advance_account) {
    $stmt = $pdo->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, balance_type, is_active) VALUES ('3300', 'Customer Advance', 'liability', 'credit', 1)");
    $stmt->execute();
    $customer_advance_id = $pdo->lastInsertId();
} else {
    $customer_advance_id = $customer_advance_account['id'];
}

// Process payment
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['record_payment'])) {
    $customer_id = $_POST['customer_id'];
    $payment_date = $_POST['payment_date'];
    $amount = floatval($_POST['amount']);
    $payment_method = $_POST['payment_method'];
    $payment_type = $_POST['payment_type'] ?? 'regular';
    $notes = isset($_POST['notes']) ? $_POST['notes'] : '';
    $receipt_no = 'PAY-' . date('YmdHis') . rand(100, 999);
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();
        
        if(!$customer) {
            throw new Exception("Customer not found!");
        }
        
        if($payment_type == 'advance') {
            // =====================================================
            // ADVANCE PAYMENT - Add to advance balance (Credit)
            // =====================================================
            
            $stmt = $pdo->prepare("INSERT INTO advance_payments_customer (customer_id, advance_date, amount, payment_method, reference_no, notes, balance_amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$customer_id, $payment_date, $amount, $payment_method, $receipt_no, $notes, $amount, $user['id']]);
            $advance_id = $pdo->lastInsertId();
            
            // Update customer advance balance
            $stmt = $pdo->prepare("UPDATE customers SET advance_balance = advance_balance + ?, total_advance_received = total_advance_received + ? WHERE id = ?");
            $stmt->execute([$amount, $amount, $customer_id]);
            
            // =============================================
            // FIXED: Use 'advance_received' as transaction_type
            // =============================================
            $stmt = $pdo->prepare("
                INSERT INTO customer_transactions (
                    customer_id, 
                    transaction_date, 
                    transaction_type, 
                    reference_no, 
                    amount, 
                    debit, 
                    credit, 
                    notes, 
                    created_by
                ) VALUES (?, ?, 'advance_received', ?, ?, 0, ?, ?, ?)
            ");
            $stmt->execute([$customer_id, $payment_date, $receipt_no, $amount, $amount, $notes, $user['id']]);
            
            // Accounting: Dr. Cash, Cr. Customer Advance
            $voucher_no = 'ADV-C-' . date('YmdHis') . rand(100, 999);
            $narration = "Advance received from {$customer['customer_name']} - Amount: BDT " . number_format($amount, 2);
            
            $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'receipt', ?, ?, ?, 'approved')");
            $stmt->execute([$voucher_no, $payment_date, $narration, $user['id']]);
            $voucher_id = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("UPDATE advance_payments_customer SET voucher_id = ? WHERE id = ?");
            $stmt->execute([$voucher_id, $advance_id]);
            
            $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                (?, ?, ?, ?, ?),
                (?, ?, ?, ?, ?)");
            $stmt->execute([
                $voucher_id, $cash_account['id'], $amount, 0, "Advance received from {$customer['customer_name']}",
                $voucher_id, $customer_advance_id, 0, $amount, "Customer advance liability"
            ]);
            
            $pdo->commit();
            
            $new_advance_balance = $customer['advance_balance'] + $amount;
            $success = "✅ Advance payment recorded for {$customer['customer_name']}!<br>
                        <strong>Amount:</strong> BDT " . number_format($amount, 2) . "<br>
                        <strong>New Advance Balance:</strong> BDT " . number_format($new_advance_balance, 2);
            
        } else {
            // =====================================================
            // REGULAR PAYMENT - Apply to due balance
            // =====================================================
            
            if($amount > $customer['current_balance']) {
                throw new Exception("Payment amount exceeds due balance!");
            }
            
            $stmt = $pdo->prepare("SELECT * FROM credit_sales WHERE customer_id = ? AND balance_due > 0 ORDER BY sale_date ASC");
            $stmt->execute([$customer_id]);
            $credit_sales = $stmt->fetchAll();
            
            if(empty($credit_sales)) {
                throw new Exception("No pending credit sales found!");
            }
            
            $remaining_amount = $amount;
            $total_paid = 0;
            
            foreach($credit_sales as $sale) {
                if($remaining_amount <= 0) break;
                
                $due = $sale['balance_due'];
                $pay_amount = min($remaining_amount, $due);
                
                $new_paid = $sale['paid_amount'] + $pay_amount;
                $new_balance = $due - $pay_amount;
                $new_status = ($new_balance <= 0) ? 'paid' : 'partial';
                
                $stmt = $pdo->prepare("UPDATE credit_sales SET paid_amount = ?, balance_due = ?, status = ? WHERE id = ?");
                $stmt->execute([$new_paid, $new_balance, $new_status, $sale['id']]);
                
                $stmt = $pdo->prepare("INSERT INTO credit_payments (credit_sale_id, payment_date, amount, payment_method, receipt_no, notes) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$sale['id'], $payment_date, $pay_amount, $payment_method, $receipt_no, $notes]);
                
                $remaining_amount -= $pay_amount;
                $total_paid += $pay_amount;
            }
            
            // Update customer current balance
            $stmt = $pdo->prepare("UPDATE customers SET current_balance = current_balance - ?, total_payments = total_payments + ? WHERE id = ?");
            $stmt->execute([$total_paid, $total_paid, $customer_id]);
            
            // =============================================
            // FIXED: Use 'payment' as transaction_type
            // =============================================
            $stmt = $pdo->prepare("
                INSERT INTO customer_transactions (
                    customer_id, 
                    transaction_date, 
                    transaction_type, 
                    reference_no, 
                    amount, 
                    debit, 
                    credit, 
                    notes, 
                    created_by
                ) VALUES (?, ?, 'payment', ?, ?, 0, ?, ?, ?)
            ");
            $stmt->execute([$customer_id, $payment_date, $receipt_no, $total_paid, $total_paid, $notes, $user['id']]);
            
            // Accounting: Dr. Cash, Cr. Accounts Receivable
            $voucher_no = 'RECV-' . date('YmdHis') . rand(100, 999);
            $narration = "Payment received from {$customer['customer_name']} - Receipt: $receipt_no";
            
            $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, 'receipt', ?, ?, ?, 'approved')");
            $stmt->execute([$voucher_no, $payment_date, $narration, $user['id']]);
            $voucher_id = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES 
                (?, ?, ?, ?, ?),
                (?, ?, ?, ?, ?)");
            $stmt->execute([
                $voucher_id, $cash_account['id'], $total_paid, 0, "Payment from {$customer['customer_name']}",
                $voucher_id, $ar_account['id'], 0, $total_paid, "Payment applied to credit sales"
            ]);
            
            $pdo->commit();
            
            $stmt = $pdo->prepare("SELECT current_balance FROM customers WHERE id = ?");
            $stmt->execute([$customer_id]);
            $new_balance = $stmt->fetch()['current_balance'];
            
            $success = "✅ Payment recorded successfully!<br>
                        <strong>Receipt No:</strong> $receipt_no<br>
                        <strong>Amount Paid:</strong> BDT " . number_format($total_paid, 2) . "<br>
                        <strong>New Balance:</strong> BDT " . number_format($new_balance, 2);
        }
        
    } catch(Exception $e) {
        // Only rollback if a transaction is active
        try {
            $pdo->rollBack();
        } catch(Exception $rollbackError) {
            // Transaction might not be active, ignore
        }
        $error = "Error: " . $e->getMessage();
    }
}

$customers = $pdo->query("SELECT * FROM customers WHERE is_active = 1 ORDER BY customer_name")->fetchAll();
$recent_payments = $pdo->query("
    SELECT cp.*, c.customer_name, c.customer_code 
    FROM credit_payments cp 
    JOIN credit_sales cs ON cp.credit_sale_id = cs.id 
    JOIN customers c ON cs.customer_id = c.id 
    ORDER BY cp.payment_date DESC LIMIT 50
")->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Payment</title>
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
        .payment-type-btn {
            cursor: pointer;
            padding: 8px 15px;
            border-radius: 20px;
            transition: all 0.3s;
        }
        .payment-type-btn.active { background: #28a745; color: white; }
        .payment-type-btn.inactive { background: #e9ecef; color: #6c757d; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-hand-holding-usd"></i> Customer Payment Collection</h2>
                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
            
            <?php if($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
            <?php if($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
            
            <div class="row">
                <div class="col-md-4"><div class="stats-card"><i class="fas fa-users"></i><h3><?php echo count($customers); ?></h3><p>Total Customers</p></div></div>
                <div class="col-md-4"><div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);"><i class="fas fa-money-bill-wave"></i><h3><?php echo $currency; ?> <?php $total_due = 0; foreach($customers as $c) { $total_due += $c['current_balance']; } echo number_format($total_due, 2); ?></h3><p>Total Outstanding</p></div></div>
                <div class="col-md-4"><div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);"><i class="fas fa-history"></i><h3><?php echo count($recent_payments); ?></h3><p>Recent Payments</p></div></div>
            </div>
            
            <div class="row">
                <div class="col-md-5">
                    <div class="card">
                        <div class="card-header bg-primary text-white"><h5><i class="fas fa-plus-circle"></i> Record Customer Payment</h5></div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label><i class="fas fa-user"></i> Select Customer</label>
                                    <select name="customer_id" id="customer_id" class="form-control" required>
                                        <option value="">-- Select Customer --</option>
                                        <?php foreach($customers as $c): 
                                            $net = ($c['current_balance'] ?? 0) - ($c['advance_balance'] ?? 0);
                                        ?>
                                            <option value="<?php echo $c['id']; ?>" 
                                                    data-balance="<?php echo $c['current_balance']; ?>"
                                                    data-advance="<?php echo $c['advance_balance']; ?>"
                                                    data-net="<?php echo $net; ?>"
                                                    data-name="<?php echo $c['customer_name']; ?>">
                                                <?php echo $c['customer_name']; ?> (<?php echo $c['customer_code']; ?>) 
                                                - Net: <?php echo $currency; ?> <?php echo number_format($net, 2); ?>
                                                <?php if($net > 0): ?>(Due)<?php elseif($net < 0): ?>(Advance)<?php else: ?>(Settled)<?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div id="customerInfo" style="display:none;" class="alert alert-info">
                                    <strong>Customer:</strong> <span id="selected_customer_name"></span><br>
                                    <strong>Due Balance:</strong> <?php echo $currency; ?> <span id="selected_due_amount">0.00</span><br>
                                    <strong>Advance Balance:</strong> <?php echo $currency; ?> <span id="selected_advance_amount">0.00</span><br>
                                    <strong>Net Balance:</strong> <?php echo $currency; ?> <span id="selected_net_amount">0.00</span>
                                </div>
                                
                                <div class="mb-3"><label>Payment Date</label><input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                                
                                <div class="mb-3">
                                    <label>Payment Type</label>
                                    <div class="d-flex gap-2">
                                        <span class="payment-type-btn active" data-type="regular" onclick="setPaymentType('regular')">Regular Payment</span>
                                        <span class="payment-type-btn inactive" data-type="advance" onclick="setPaymentType('advance')">Advance Payment</span>
                                    </div>
                                    <input type="hidden" name="payment_type" id="payment_type" value="regular">
                                    <small class="text-muted d-block mt-1">Advance payment will be added to customer's advance balance</small>
                                </div>
                                
                                <div class="mb-3"><label><i class="fas fa-money-bill"></i> Amount (<?php echo $currency; ?>)</label><input type="number" name="amount" id="amount" class="form-control" step="0.01" required></div>
                                <div class="mb-3"><label><i class="fas fa-credit-card"></i> Payment Method</label><select name="payment_method" class="form-control" required><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="cheque">Cheque</option><option value="mobile_banking">Mobile Banking</option></select></div>
                                <div class="mb-3"><label><i class="fas fa-comment"></i> Notes (Optional)</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
                                
                                <button type="submit" name="record_payment" class="btn btn-success w-100"><i class="fas fa-save"></i> Record Payment</button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-7">
                    <div class="card">
                        <div class="card-header bg-info text-white"><h5><i class="fas fa-history"></i> Recent Payment History</h5></div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="paymentsTable">
                                    <thead class="table-dark">
                                        <tr><th>Date</th><th>Receipt No</th><th>Customer</th><th class="text-end">Amount</th><th>Method</th><th>Notes</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($recent_payments as $pay): ?>
                                        <tr><td><?php echo date('d/m/Y', strtotime($pay['payment_date'])); ?></td><td><?php echo $pay['receipt_no']; ?></td><td><?php echo $pay['customer_name']; ?></td><td class="text-end text-success fw-bold"><?php echo $currency; ?> <?php echo number_format($pay['amount'], 2); ?></td><td><?php echo ucfirst($pay['payment_method']); ?></td><td><?php echo $pay['notes'] ?: '-'; ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold"><td colspan="3" class="text-end">TOTAL:</td><td class="text-end text-success"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($recent_payments, 'amount')), 2); ?></td><td colspan="2"></td></tr>
                                    </tfoot>
                                </table>
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
            $('#paymentsTable').DataTable({ order: [[0, 'desc']], pageLength: 10, language: { search: "Search:", lengthMenu: "Show _MENU_ entries", info: "Showing _START_ to _END_ of _TOTAL_ entries" } });
        });
        
        function setPaymentType(type) {
            document.getElementById('payment_type').value = type;
            document.querySelectorAll('.payment-type-btn').forEach(btn => {
                btn.classList.remove('active');
                btn.classList.add('inactive');
                if(btn.getAttribute('data-type') == type) {
                    btn.classList.add('active');
                    btn.classList.remove('inactive');
                }
            });
            
            let option = document.getElementById('customer_id').options[document.getElementById('customer_id').selectedIndex];
            let net = parseFloat(option.getAttribute('data-net')) || 0;
            
            if(type == 'advance') {
                document.getElementById('amount').max = 99999999;
                document.getElementById('amount').placeholder = 'Enter advance amount';
                document.getElementById('amount').value = '';
            } else {
                document.getElementById('amount').max = net > 0 ? net : 0;
                document.getElementById('amount').placeholder = 'Max: ' + (net > 0 ? net.toFixed(2) : '0.00');
                document.getElementById('amount').value = net > 0 ? net : '';
            }
        }
        
        document.getElementById('customer_id').addEventListener('change', function() {
            let option = this.options[this.selectedIndex];
            let balance = parseFloat(option.getAttribute('data-balance')) || 0;
            let advance = parseFloat(option.getAttribute('data-advance')) || 0;
            let net = parseFloat(option.getAttribute('data-net')) || 0;
            let name = option.getAttribute('data-name') || '';
            
            if(this.value) {
                document.getElementById('customerInfo').style.display = 'block';
                document.getElementById('selected_customer_name').innerText = name;
                document.getElementById('selected_due_amount').innerText = balance.toFixed(2);
                document.getElementById('selected_advance_amount').innerText = advance.toFixed(2);
                document.getElementById('selected_net_amount').innerText = net.toFixed(2);
                
                let type = document.getElementById('payment_type').value;
                if(type == 'regular') {
                    document.getElementById('amount').max = net > 0 ? net : 0;
                    document.getElementById('amount').placeholder = 'Max: ' + (net > 0 ? net.toFixed(2) : '0.00');
                    document.getElementById('amount').value = net > 0 ? net : '';
                } else {
                    document.getElementById('amount').max = 99999999;
                    document.getElementById('amount').placeholder = 'Enter advance amount';
                    document.getElementById('amount').value = '';
                }
            } else {
                document.getElementById('customerInfo').style.display = 'none';
                document.getElementById('amount').value = '';
            }
        });
        
        document.getElementById('amount').addEventListener('input', function() {
            let type = document.getElementById('payment_type').value;
            let option = document.getElementById('customer_id').options[document.getElementById('customer_id').selectedIndex];
            let net = parseFloat(option.getAttribute('data-net')) || 0;
            let amount = parseFloat(this.value) || 0;
            
            if(type == 'regular' && amount > net && net > 0) {
                this.setCustomValidity('Amount cannot exceed net due balance of <?php echo $currency; ?> ' + net.toFixed(2));
                this.style.borderColor = 'red';
            } else {
                this.setCustomValidity('');
                this.style.borderColor = '';
            }
        });
    </script>
</body>
</html>
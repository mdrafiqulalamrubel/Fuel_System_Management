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

$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '2000' OR account_name LIKE '%Accounts Payable%' LIMIT 1");
$ap_account = $stmt->fetch();

$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '3300' OR account_name LIKE '%Customer Advance%' LIMIT 1");
$customer_advance_account = $stmt->fetch();

$stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '2200' OR account_name LIKE '%Supplier Advance%' LIMIT 1");
$supplier_advance_account = $stmt->fetch();

// Process voucher
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_voucher'])) {
    $voucher_type = $_POST['voucher_type'];
    $date = $_POST['date'];
    $narration = $_POST['narration'];
    $account_id = $_POST['account_id'];
    $debit = $_POST['debit'];
    $credit = $_POST['credit'];
    
    // =============================================
    // GET CUSTOMER & SUPPLIER REFERENCES
    // =============================================
    $customer_id = !empty($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
    $supplier_id = !empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : null;
    $customer_name = !empty($_POST['customer_name']) ? trim($_POST['customer_name']) : '';
    $supplier_name = !empty($_POST['supplier_name']) ? trim($_POST['supplier_name']) : '';
    
    // If customer name is provided but no ID, try to find or create
    if($customer_id === null && !empty($customer_name)) {
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE customer_name = ? OR phone = ?");
        $stmt->execute([$customer_name, $customer_name]);
        $existing = $stmt->fetch();
        if($existing) {
            $customer_id = $existing['id'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO customers (customer_code, customer_name, is_active) VALUES (?, ?, 1)");
            $customer_code = 'CUST-' . date('Ymd') . rand(100, 999);
            $stmt->execute([$customer_code, $customer_name]);
            $customer_id = $pdo->lastInsertId();
        }
    }
    
    // If supplier name is provided but no ID, try to find or create
    if($supplier_id === null && !empty($supplier_name)) {
        $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE supplier_name = ? OR phone = ?");
        $stmt->execute([$supplier_name, $supplier_name]);
        $existing = $stmt->fetch();
        if($existing) {
            $supplier_id = $existing['id'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO suppliers (supplier_code, supplier_name, is_active) VALUES (?, ?, 1)");
            $supplier_code = 'SUP-' . date('Ymd') . rand(100, 999);
            $stmt->execute([$supplier_code, $supplier_name]);
            $supplier_id = $pdo->lastInsertId();
        }
    }
    // =============================================
    
    try {
        // Calculate totals
        $total_debit = 0;
        $total_credit = 0;
        for($i = 0; $i < count($account_id); $i++) {
            $total_debit += floatval($debit[$i]);
            $total_credit += floatval($credit[$i]);
        }
        
        if($total_debit == 0 && $total_credit == 0) {
            throw new Exception("Please enter amounts!");
        }
        
        if(abs($total_debit - $total_credit) > 0.01) {
            throw new Exception("Debit (" . number_format($total_debit, 2) . ") must equal Credit (" . number_format($total_credit, 2) . ")");
        }
        
        $pdo->beginTransaction();
        
        // Generate voucher number
        $voucher_no = $voucher_type . '-' . date('YmdHis') . rand(100, 999);
        
        // Insert voucher with customer and supplier references
        $stmt = $pdo->prepare("
            INSERT INTO vouchers (
                voucher_no, voucher_type, date, narration, 
                customer_id, supplier_id, 
                created_by, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'approved')
        ");
        $stmt->execute([
            $voucher_no, $voucher_type, $date, $narration,
            $customer_id, $supplier_id,
            $user['id']
        ]);
        $voucher_id = $pdo->lastInsertId();
        
        // Insert voucher items
        $count = 0;
        for($i = 0; $i < count($account_id); $i++) {
            if(floatval($debit[$i]) > 0 || floatval($credit[$i]) > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO voucher_items (
                        voucher_id, account_id, debit_amount, credit_amount, description
                    ) VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $voucher_id, 
                    $account_id[$i], 
                    $debit[$i], 
                    $credit[$i], 
                    $narration
                ]);
                $count++;
            }
        }
        
        // =============================================
        // UPDATE CUSTOMER BALANCE IF LINKED
        // =============================================
        if($customer_id) {
            // Get customer details
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch();
            
            if($customer) {
                // Calculate net effect on customer balance
                // Debit to AR increases customer due, Credit to AR decreases customer due
                $stmt = $pdo->prepare("
                    SELECT 
                        COALESCE(SUM(CASE WHEN account_id = ? THEN debit_amount - credit_amount ELSE 0 END), 0) as net_effect
                    FROM voucher_items 
                    WHERE voucher_id = ?
                ");
                $stmt->execute([$ar_account['id'], $voucher_id]);
                $net_effect = $stmt->fetch()['net_effect'];
                
                if($net_effect != 0) {
                    // Update customer balance
                    $stmt = $pdo->prepare("UPDATE customers SET current_balance = current_balance + ? WHERE id = ?");
                    $stmt->execute([$net_effect, $customer_id]);
                }
            }
        }
        
        // =============================================
        // UPDATE SUPPLIER BALANCE IF LINKED
        // =============================================
        if($supplier_id) {
            // Get supplier details
            $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
            $stmt->execute([$supplier_id]);
            $supplier = $stmt->fetch();
            
            if($supplier) {
                // Calculate net effect on supplier balance
                // Credit to AP increases supplier payable, Debit to AP decreases supplier payable
                $stmt = $pdo->prepare("
                    SELECT 
                        COALESCE(SUM(CASE WHEN account_id = ? THEN credit_amount - debit_amount ELSE 0 END), 0) as net_effect
                    FROM voucher_items 
                    WHERE voucher_id = ?
                ");
                $stmt->execute([$ap_account['id'], $voucher_id]);
                $net_effect = $stmt->fetch()['net_effect'];
                
                if($net_effect != 0) {
                    // Update supplier balance
                    $stmt = $pdo->prepare("UPDATE suppliers SET current_balance = current_balance + ? WHERE id = ?");
                    $stmt->execute([$net_effect, $supplier_id]);
                }
            }
        }
        // =============================================
        
        $pdo->commit();
        
        $success = "✅ Voucher saved successfully!<br>
                    <strong>Voucher No:</strong> $voucher_no<br>
                    <strong>Voucher Type:</strong> " . ucfirst($voucher_type) . "<br>
                    <strong>Total Amount:</strong> " . number_format($total_debit, 2) . "<br>
                    <strong>Items:</strong> $count<br>
                    <hr>
                    <a href='view_voucher.php?id=$voucher_id' target='_blank' class='btn btn-sm btn-info'>
                        <i class='fas fa-eye'></i> View Voucher
                    </a>
                    <a href='print_voucher.php?id=$voucher_id' target='_blank' class='btn btn-sm btn-primary'>
                        <i class='fas fa-print'></i> Print Voucher
                    </a>";
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Get accounts for dropdown
$accounts = $pdo->query("SELECT * FROM chart_of_accounts WHERE is_active = 1 ORDER BY account_code")->fetchAll();

// Get customers and suppliers for dropdown
$customers = $pdo->query("SELECT id, customer_name, customer_code, phone FROM customers WHERE is_active = 1 ORDER BY customer_name")->fetchAll();
$suppliers = $pdo->query("SELECT id, supplier_name, supplier_code, phone FROM suppliers WHERE is_active = 1 ORDER BY supplier_name")->fetchAll();

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Voucher Entry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .voucher-type-card {
            cursor: pointer;
            padding: 15px;
            border-radius: 10px;
            border: 2px solid #dee2e6;
            transition: all 0.3s;
            text-align: center;
        }
        .voucher-type-card:hover {
            border-color: #667eea;
            background: #f8f9fa;
        }
        .voucher-type-card.active {
            border-color: #28a745;
            background: #d4edda;
        }
        .voucher-type-card i {
            font-size: 30px;
            display: block;
            margin-bottom: 8px;
        }
        .voucher-type-card .type-label {
            font-weight: 600;
            font-size: 14px;
        }
        .voucher-type-card .type-desc {
            font-size: 11px;
            color: #6c757d;
        }
        
        .party-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
            border-left: 4px solid #17a2b8;
        }
        .party-section .label {
            font-weight: 600;
            color: #495057;
        }
        .party-section .optional {
            font-size: 11px;
            color: #6c757d;
        }
        
        .voucher-row {
            background: #fff;
        }
        .voucher-row .row-number {
            font-weight: bold;
            color: #6c757d;
            padding-top: 8px;
        }
        .total-row {
            background: #e9ecef;
            font-weight: bold;
        }
        .total-row td {
            padding: 8px 5px;
        }
        .debit-total { color: #dc3545; }
        .credit-total { color: #28a745; }
        
        .badge-voucher { font-size: 12px; padding: 5px 12px; }
        .badge-voucher.journal { background: #6f42c1; color: white; }
        .badge-voucher.payment { background: #dc3545; color: white; }
        .badge-voucher.receipt { background: #28a745; color: white; }
        .badge-voucher.contra { background: #fd7e14; color: white; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-file-invoice"></i> Voucher Entry</h2>
                <a href="accounting.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-plus-circle"></i> Create Voucher</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="voucherForm">
                        <!-- Voucher Type Selection -->
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="fw-bold">Voucher Type</label>
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="voucher-type-card active" data-type="journal" onclick="selectVoucherType('journal')">
                                            <i class="fas fa-book"></i>
                                            <div class="type-label">Journal Voucher</div>
                                            <div class="type-desc">General entries with customer/supplier optional</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="voucher-type-card" data-type="payment" onclick="selectVoucherType('payment')">
                                            <i class="fas fa-money-bill-wave"></i>
                                            <div class="type-label">Payment Voucher</div>
                                            <div class="type-desc">Payments to suppliers or expenses</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="voucher-type-card" data-type="receipt" onclick="selectVoucherType('receipt')">
                                            <i class="fas fa-hand-holding-usd"></i>
                                            <div class="type-label">Receipt Voucher</div>
                                            <div class="type-desc">Receipts from customers or income</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="voucher-type-card" data-type="contra" onclick="selectVoucherType('contra')">
                                            <i class="fas fa-exchange-alt"></i>
                                            <div class="type-label">Contra Voucher</div>
                                            <div class="type-desc">Cash/Bank transfers, no 3rd party</div>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="voucher_type" id="voucher_type" value="journal">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Date</label>
                                    <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label>Narration <span class="text-danger">*</span></label>
                                    <input type="text" name="narration" class="form-control" placeholder="Enter description" required>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ============================================= -->
                        <!-- CUSTOMER SECTION -->
                        <!-- ============================================= -->
                        <div id="customerSection" class="party-section">
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="label">
                                        <i class="fas fa-user"></i> Customer 
                                        <span class="optional">(Optional - Link to customer account)</span>
                                    </label>
                                    <select name="customer_id" id="customer_id" class="form-control" onchange="updateCustomerFields(this)">
                                        <option value="">-- Select Customer --</option>
                                        <?php foreach($customers as $c): ?>
                                            <option value="<?php echo $c['id']; ?>" 
                                                    data-name="<?php echo $c['customer_name']; ?>"
                                                    data-phone="<?php echo $c['phone']; ?>">
                                                <?php echo $c['customer_name']; ?> (<?php echo $c['customer_code']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="label">Or Enter Customer Name</label>
                                    <input type="text" name="customer_name" id="customer_name" class="form-control" placeholder="Enter customer name">
                                    <small class="text-muted">If not in list, will be created automatically</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ============================================= -->
                        <!-- SUPPLIER SECTION -->
                        <!-- ============================================= -->
                        <div id="supplierSection" class="party-section">
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="label">
                                        <i class="fas fa-truck"></i> Supplier 
                                        <span class="optional">(Optional - Link to supplier account)</span>
                                    </label>
                                    <select name="supplier_id" id="supplier_id" class="form-control" onchange="updateSupplierFields(this)">
                                        <option value="">-- Select Supplier --</option>
                                        <?php foreach($suppliers as $s): ?>
                                            <option value="<?php echo $s['id']; ?>" 
                                                    data-name="<?php echo $s['supplier_name']; ?>"
                                                    data-phone="<?php echo $s['phone']; ?>">
                                                <?php echo $s['supplier_name']; ?> (<?php echo $s['supplier_code']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="label">Or Enter Supplier Name</label>
                                    <input type="text" name="supplier_name" id="supplier_name" class="form-control" placeholder="Enter supplier name">
                                    <small class="text-muted">If not in list, will be created automatically</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ============================================= -->
                        <!-- ACCOUNTING ENTRIES TABLE -->
                        <!-- ============================================= -->
                        <div class="table-responsive mt-3">
                            <table class="table table-bordered" id="entriesTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th style="width:5%;">#</th>
                                        <th style="width:45%;">Account</th>
                                        <th style="width:25%;">Debit (<?php echo $currency; ?>)</th>
                                        <th style="width:25%;">Credit (<?php echo $currency; ?>)</th>
                                    </tr>
                                </thead>
                                <tbody id="rowsContainer">
                                    <tr class="voucher-row">
                                        <td class="text-center row-number">1</td>
                                        <td>
                                            <select name="account_id[]" class="form-control account-select" required>
                                                <option value="">-- Select Account --</option>
                                                <?php foreach($accounts as $acc): ?>
                                                    <option value="<?php echo $acc['id']; ?>">
                                                        <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" name="debit[]" class="form-control amount-input debit-input" step="0.01" value="0" oninput="calculateTotals()">
                                        </td>
                                        <td>
                                            <input type="number" name="credit[]" class="form-control amount-input credit-input" step="0.01" value="0" oninput="calculateTotals()">
                                        </td>
                                    </tr>
                                    <tr class="voucher-row">
                                        <td class="text-center row-number">2</td>
                                        <td>
                                            <select name="account_id[]" class="form-control account-select" required>
                                                <option value="">-- Select Account --</option>
                                                <?php foreach($accounts as $acc): ?>
                                                    <option value="<?php echo $acc['id']; ?>">
                                                        <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" name="debit[]" class="form-control amount-input debit-input" step="0.01" value="0" oninput="calculateTotals()">
                                        </td>
                                        <td>
                                            <input type="number" name="credit[]" class="form-control amount-input credit-input" step="0.01" value="0" oninput="calculateTotals()">
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="total-row">
                                        <td colspan="2">
                                            <button type="button" class="btn btn-primary btn-sm" onclick="addRow()">
                                                <i class="fas fa-plus"></i> Add Row
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="removeRow()">
                                                <i class="fas fa-minus"></i> Remove Last
                                            </button>
                                        </td>
                                        <td class="text-end debit-total" id="totalDebit">0.00</td>
                                        <td class="text-end credit-total" id="totalCredit">0.00</td>
                                    </tr>
                                    <tr class="total-row" style="background:#d4edda;">
                                        <td colspan="2" class="text-end"><strong>BALANCE:</strong></td>
                                        <td colspan="2" class="text-center" id="balanceDisplay">
                                            <span class="badge bg-success">Balanced</span>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <div class="mt-3">
                            <button type="submit" name="save_voucher" class="btn btn-success" id="saveBtn">
                                <i class="fas fa-save"></i> Save Voucher
                            </button>
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-undo"></i> Reset
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- ============================================= -->
            <!-- ACCOUNTING TIPS -->
            <!-- ============================================= -->
            <div class="card mt-3">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-info-circle"></i> Accounting Guide</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Payment Voucher</h6>
                            <ul>
                                <li><strong>Dr.</strong> Expense/Asset Account</li>
                                <li><strong>Cr.</strong> Cash/Bank OR Accounts Payable</li>
                                <li>Select Supplier to link to Accounts Payable</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Receipt Voucher</h6>
                            <ul>
                                <li><strong>Dr.</strong> Cash/Bank OR Accounts Receivable</li>
                                <li><strong>Cr.</strong> Income Account</li>
                                <li>Select Customer to link to Accounts Receivable</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Journal Voucher</h6>
                            <ul>
                                <li>Any accounts with balanced Dr/Cr</li>
                                <li>Can link Customer or Supplier</li>
                                <li>Used for adjustments, transfers, etc.</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Contra Voucher</h6>
                            <ul>
                                <li><strong>Dr.</strong> Bank/Cash Account</li>
                                <li><strong>Cr.</strong> Cash/Bank Account</li>
                                <li>No customer or supplier required</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let rowCount = 2;
        
        function selectVoucherType(type) {
            document.getElementById('voucher_type').value = type;
            
            // Update card styles
            document.querySelectorAll('.voucher-type-card').forEach(card => {
                card.classList.remove('active');
            });
            document.querySelector(`.voucher-type-card[data-type="${type}"]`).classList.add('active');
            
            // Show/hide customer and supplier sections based on voucher type
            const customerSection = document.getElementById('customerSection');
            const supplierSection = document.getElementById('supplierSection');
            
            // All sections are visible by default, but we can add hints
            if(type === 'contra') {
                // Contra doesn't need customer or supplier
                customerSection.style.opacity = '0.5';
                supplierSection.style.opacity = '0.5';
            } else {
                customerSection.style.opacity = '1';
                supplierSection.style.opacity = '1';
            }
        }
        
        function updateCustomerFields(select) {
            const option = select.options[select.selectedIndex];
            if(option.value) {
                document.getElementById('customer_name').value = option.dataset.name || '';
            }
        }
        
        function updateSupplierFields(select) {
            const option = select.options[select.selectedIndex];
            if(option.value) {
                document.getElementById('supplier_name').value = option.dataset.name || '';
            }
        }
        
        function addRow() {
            rowCount++;
            const row = document.createElement('tr');
            row.className = 'voucher-row';
            row.innerHTML = `
                <td class="text-center row-number">${rowCount}</td>
                <td>
                    <select name="account_id[]" class="form-control account-select">
                        <option value="">-- Select Account --</option>
                        <?php foreach($accounts as $acc): ?>
                            <option value="<?php echo $acc['id']; ?>">
                                <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <input type="number" name="debit[]" class="form-control amount-input debit-input" step="0.01" value="0" oninput="calculateTotals()">
                </td>
                <td>
                    <input type="number" name="credit[]" class="form-control amount-input credit-input" step="0.01" value="0" oninput="calculateTotals()">
                </td>
            `;
            document.getElementById('rowsContainer').appendChild(row);
            calculateTotals();
        }
        
        function removeRow() {
            const container = document.getElementById('rowsContainer');
            if(container.children.length > 2) {
                container.removeChild(container.lastChild);
                rowCount--;
                calculateTotals();
            } else {
                alert('Minimum 2 rows required for voucher entry');
            }
        }
        
        function calculateTotals() {
            let totalDebit = 0;
            let totalCredit = 0;
            
            document.querySelectorAll('.debit-input').forEach(input => {
                totalDebit += parseFloat(input.value) || 0;
            });
            
            document.querySelectorAll('.credit-input').forEach(input => {
                totalCredit += parseFloat(input.value) || 0;
            });
            
            document.getElementById('totalDebit').textContent = totalDebit.toFixed(2);
            document.getElementById('totalCredit').textContent = totalCredit.toFixed(2);
            
            const balance = Math.abs(totalDebit - totalCredit);
            const balanceDisplay = document.getElementById('balanceDisplay');
            
            if(balance < 0.01) {
                balanceDisplay.innerHTML = `<span class="badge bg-success"><i class="fas fa-check-circle"></i> Balanced (${totalDebit.toFixed(2)})</span>`;
                document.getElementById('saveBtn').disabled = false;
            } else {
                const diff = totalDebit - totalCredit;
                balanceDisplay.innerHTML = `
                    <span class="badge bg-danger">
                        <i class="fas fa-exclamation-triangle"></i> 
                        ${diff > 0 ? 'Debit' : 'Credit'} excess: ${Math.abs(diff).toFixed(2)}
                    </span>
                `;
                document.getElementById('saveBtn').disabled = true;
            }
        }
        
        // Form validation
        document.getElementById('voucherForm').addEventListener('submit', function(e) {
            const totalDebit = parseFloat(document.getElementById('totalDebit').textContent) || 0;
            const totalCredit = parseFloat(document.getElementById('totalCredit').textContent) || 0;
            
            if(Math.abs(totalDebit - totalCredit) > 0.01) {
                e.preventDefault();
                alert('⚠️ Debit and Credit must be equal!\n\nDebit: ' + totalDebit.toFixed(2) + '\nCredit: ' + totalCredit.toFixed(2));
                return false;
            }
            
            // Check if at least one account is selected
            let hasAccount = false;
            document.querySelectorAll('.account-select').forEach(select => {
                if(select.value) hasAccount = true;
            });
            
            if(!hasAccount) {
                e.preventDefault();
                alert('⚠️ Please select at least one account');
                return false;
            }
            
            // Check if at least one amount is entered
            let hasAmount = false;
            document.querySelectorAll('.amount-input').forEach(input => {
                if(parseFloat(input.value) > 0) hasAmount = true;
            });
            
            if(!hasAmount) {
                e.preventDefault();
                alert('⚠️ Please enter at least one amount');
                return false;
            }
            
            return true;
        });
        
        // Calculate on load
        window.onload = function() {
            calculateTotals();
            // Set default voucher type
            selectVoucherType('journal');
        };
    </script>
</body>
</html>
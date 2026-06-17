<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Process voucher
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_voucher'])) {
    $voucher_type = $_POST['voucher_type'];
    $date = $_POST['date'];
    $narration = $_POST['narration'];
    $account_id = $_POST['account_id'];
    $debit = $_POST['debit'];
    $credit = $_POST['credit'];
    
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
        
        if($total_debit != $total_credit) {
            throw new Exception("Debit ($total_debit) must equal Credit ($total_credit)");
        }
        
        $pdo->beginTransaction();
        
        // Insert voucher
        $voucher_no = 'VCH-' . date('YmdHis');
        $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, ?, ?, ?, ?, 'approved')");
        $stmt->execute([$voucher_no, $voucher_type, $date, $narration, $user['id']]);
        $voucher_id = $pdo->lastInsertId();
        
        // Insert items
        $count = 0;
        for($i = 0; $i < count($account_id); $i++) {
            if($debit[$i] > 0 || $credit[$i] > 0) {
                $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$voucher_id, $account_id[$i], $debit[$i], $credit[$i], $narration]);
                $count++;
            }
        }
        
        $pdo->commit();
        
        $success = "✅ Voucher saved!<br>
                    Voucher No: $voucher_no<br>
                    Voucher ID: $voucher_id<br>
                    Items: $count<br>
                    <a href='view_voucher.php?id=$voucher_id' target='_blank'>View Voucher</a> | 
                    <a href='print_voucher.php?id=$voucher_id' target='_blank'>Print Voucher</a>";
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Get accounts for dropdown
$accounts = $pdo->query("SELECT * FROM chart_of_accounts WHERE is_active = 1 ORDER BY account_code")->fetchAll();
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Simple Voucher Entry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <h2>Simple Voucher Entry</h2>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5>Create Voucher</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Voucher Type</label>
                                    <select name="voucher_type" class="form-control">
                                        <option value="journal">Journal</option>
                                        <option value="payment">Payment</option>
                                        <option value="receipt">Receipt</option>
                                        <option value="contra">Contra</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Date</label>
                                    <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Narration</label>
                                    <input type="text" name="narration" class="form-control" placeholder="Description" required>
                                </div>
                            </div>
                        </div>
                        
                        <table class="table table-bordered">
                            <thead class="table-dark">
                                <tr>
                                    <th>Account</th>
                                    <th>Debit</th>
                                    <th>Credit</th>
                                </tr>
                            </thead>
                            <tbody id="rowsContainer">
                                <tr>
                                    <td>
                                        <select name="account_id[]" class="form-control" required>
                                            <option value="">-- Select --</option>
                                            <?php foreach($accounts as $acc): ?>
                                                <option value="<?php echo $acc['id']; ?>">
                                                    <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" name="debit[]" class="form-control" step="0.01" value="0"></td>
                                    <td><input type="number" name="credit[]" class="form-control" step="0.01" value="0"></td>
                                </tr>
                                <tr>
                                    <td>
                                        <select name="account_id[]" class="form-control" required>
                                            <option value="">-- Select --</option>
                                            <?php foreach($accounts as $acc): ?>
                                                <option value="<?php echo $acc['id']; ?>">
                                                    <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" name="debit[]" class="form-control" step="0.01" value="0"></td>
                                    <td><input type="number" name="credit[]" class="form-control" step="0.01" value="0"></td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="addRow()">+ Add Row</button>
                                    </td>
                                    <td><strong id="totalDebit">0.00</strong></td>
                                    <td><strong id="totalCredit">0.00</strong></td>
                                </tr>
                            </tfoot>
                        </table>
                        
                        <button type="submit" name="save_voucher" class="btn btn-success">Save Voucher</button>
                        <button type="reset" class="btn btn-secondary">Reset</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function addRow() {
            var row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <select name="account_id[]" class="form-control">
                        <option value="">-- Select --</option>
                        <?php foreach($accounts as $acc): ?>
                            <option value="<?php echo $acc['id']; ?>">
                                <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="number" name="debit[]" class="form-control" step="0.01" value="0" oninput="calculateTotals()"></td>
                <td><input type="number" name="credit[]" class="form-control" step="0.01" value="0" oninput="calculateTotals()"></td>
            `;
            document.getElementById('rowsContainer').appendChild(row);
        }
        
        function calculateTotals() {
            var totalDebit = 0;
            var totalCredit = 0;
            var debits = document.querySelectorAll('input[name="debit[]"]');
            var credits = document.querySelectorAll('input[name="credit[]"]');
            
            debits.forEach(function(input) {
                totalDebit += parseFloat(input.value) || 0;
            });
            
            credits.forEach(function(input) {
                totalCredit += parseFloat(input.value) || 0;
            });
            
            document.getElementById('totalDebit').textContent = totalDebit.toFixed(2);
            document.getElementById('totalCredit').textContent = totalCredit.toFixed(2);
        }
        
        // Calculate on load
        window.onload = function() {
            calculateTotals();
        };
    </script>
</body>
</html>
<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Process voucher with debugging
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_voucher'])) {
    echo "<h2>Debugging Voucher Save</h2>";
    echo "<pre>";
    echo "POST Data:\n";
    print_r($_POST);
    echo "</pre>";
    
    $voucher_type = $_POST['voucher_type'];
    $date = $_POST['date'];
    $narration = $_POST['narration'];
    $accounts = $_POST['account_id'];
    $debits = $_POST['debit'];
    $credits = $_POST['credit'];
    
    try {
        // Validate
        if(empty($narration)) {
            throw new Exception("Please enter narration!");
        }
        
        // Show what we're trying to save
        echo "<h3>Processing:</h3>";
        for($i = 0; $i < count($accounts); $i++) {
            echo "Row $i: Account={$accounts[$i]}, Debit={$debits[$i]}, Credit={$credits[$i]}<br>";
        }
        
        // Count valid items
        $valid_items = 0;
        $total_debit = 0;
        $total_credit = 0;
        $valid_accounts = [];
        
        for($i = 0; $i < count($accounts); $i++) {
            if(!empty($accounts[$i]) && ($debits[$i] > 0 || $credits[$i] > 0)) {
                $valid_items++;
                $total_debit += floatval($debits[$i]);
                $total_credit += floatval($credits[$i]);
                $valid_accounts[] = $i;
                echo "✅ Valid row $i: Account={$accounts[$i]}, Debit={$debits[$i]}, Credit={$credits[$i]}<br>";
            }
        }
        
        if($valid_items < 2) {
            throw new Exception("Please add at least one debit and one credit entry!");
        }
        
        if($total_debit == 0 && $total_credit == 0) {
            throw new Exception("Amounts cannot be zero!");
        }
        
        if(abs($total_debit - $total_credit) > 0.01) {
            throw new Exception("Total Debit ($total_debit) must equal Total Credit ($total_credit)");
        }
        
        $pdo->beginTransaction();
        
        // Create voucher
        $voucher_no = 'VCH-' . date('YmdHis') . rand(100, 999);
        $stmt = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, date, narration, created_by, status) VALUES (?, ?, ?, ?, ?, 'approved')");
        $stmt->execute([$voucher_no, $voucher_type, $date, $narration, $user['id']]);
        $voucher_id = $pdo->lastInsertId();
        echo "✅ Voucher inserted with ID: $voucher_id<br>";
        
        // Insert voucher items
        $items_added = 0;
        foreach($valid_accounts as $i) {
            $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES (?, ?, ?, ?, ?)");
            $result = $stmt->execute([$voucher_id, $accounts[$i], $debits[$i], $credits[$i], $narration]);
            if($result) {
                $items_added++;
                echo "✅ Item $items_added inserted: Account={$accounts[$i]}, Debit={$debits[$i]}, Credit={$credits[$i]}<br>";
            } else {
                echo "❌ Failed to insert item $i<br>";
                $errorInfo = $stmt->errorInfo();
                echo "Error: " . $errorInfo[2] . "<br>";
            }
        }
        
        if($items_added == 0) {
            throw new Exception("No voucher items added!");
        }
        
        $pdo->commit();
        
        $settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $currency = $settings['currency_symbol'] ?? 'BDT';
        
        $success = "✅ Voucher saved successfully!<br>
                    <strong>Voucher No:</strong> $voucher_no<br>
                    <strong>Voucher ID:</strong> $voucher_id<br>
                    <strong>Total Debit:</strong> " . $currency . " " . number_format($total_debit, 2) . "<br>
                    <strong>Total Credit:</strong> " . $currency . " " . number_format($total_credit, 2) . "<br>
                    <strong>Items Added:</strong> $items_added<br><br>
                    <a href='view_voucher.php?id=$voucher_id' target='_blank' class='btn btn-sm btn-info'>View Voucher</a>
                    <a href='print_voucher.php?id=$voucher_id' target='_blank' class='btn btn-sm btn-primary'>Print Voucher</a>";
        
    } catch(Exception $e) {
        if(isset($pdo)) $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
        echo "<div class='alert alert-danger'>$error</div>";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Voucher Entry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-bug"></i> Debug Voucher Entry</h2>
                <a href="accounting.php?tab=list" class="btn btn-secondary">Voucher List</a>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <!-- Show existing vouchers -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5>Existing Vouchers</h5>
                </div>
                <div class="card-body">
                    <?php
                    $vouchers = $pdo->query("SELECT * FROM vouchers ORDER BY id DESC LIMIT 5")->fetchAll();
                    if($vouchers):
                    ?>
                    <table class="table table-bordered">
                        <tr><th>ID</th><th>Voucher No</th><th>Date</th><th>Narration</th><th>Items</th></tr>
                        <?php foreach($vouchers as $v): 
                            $item_count = $pdo->query("SELECT COUNT(*) FROM voucher_items WHERE voucher_id = " . $v['id'])->fetchColumn();
                        ?>
                        <tr>
                            <td><?php echo $v['id']; ?></td>
                            <td><?php echo $v['voucher_no']; ?></td>
                            <td><?php echo $v['date']; ?></td>
                            <td><?php echo substr($v['narration'], 0, 30); ?></td>
                            <td><?php echo $item_count; ?> items</td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    <?php else: ?>
                    <p>No vouchers found</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5>Create Voucher</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Voucher Type *</label>
                                    <select name="voucher_type" class="form-control" required>
                                        <option value="journal">Journal Voucher</option>
                                        <option value="payment">Payment Voucher</option>
                                        <option value="receipt">Receipt Voucher</option>
                                        <option value="contra">Contra Voucher</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Date *</label>
                                    <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Narration *</label>
                                    <input type="text" name="narration" class="form-control" placeholder="Enter description..." required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-bordered" id="voucherTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th width="40%">Account</th>
                                        <th width="25%">Debit (<?php echo $currency; ?>)</th>
                                        <th width="25%">Credit (<?php echo $currency; ?>)</th>
                                        <th width="10%">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            <select name="account_id[]" class="form-control account-select" required>
                                                <option value="">-- Select Account --</option>
                                                <?php foreach($accounts as $acc): ?>
                                                    <option value="<?php echo $acc['id']; ?>">
                                                        <?php echo $acc['account_code']; ?> - <?php echo htmlspecialchars($acc['account_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td><input type="number" name="debit[]" class="form-control debit" step="0.01" value="0"></td>
                                        <td><input type="number" name="credit[]" class="form-control credit" step="0.01" value="0"></td>
                                        <td class="text-center"><button type="button" class="btn btn-danger btn-sm remove-row">×</button></td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <select name="account_id[]" class="form-control account-select" required>
                                                <option value="">-- Select Account --</option>
                                                <?php foreach($accounts as $acc): ?>
                                                    <option value="<?php echo $acc['id']; ?>">
                                                        <?php echo $acc['account_code']; ?> - <?php echo htmlspecialchars($acc['account_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td><input type="number" name="debit[]" class="form-control debit" step="0.01" value="0"></td>
                                        <td><input type="number" name="credit[]" class="form-control credit" step="0.01" value="0"></td>
                                        <td class="text-center"><button type="button" class="btn btn-danger btn-sm remove-row">×</button></td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th class="text-end">Total:</th>
                                        <th><span id="totalDebit" class="fw-bold">0.00</span></th>
                                        <th><span id="totalCredit" class="fw-bold">0.00</span></th>
                                        <th>
                                            <button type="button" class="btn btn-primary btn-sm" id="addRow">
                                                <i class="fas fa-plus"></i> Add Row
                                            </button>
                                        </th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <button type="submit" name="save_voucher" class="btn btn-success mt-3">
                            <i class="fas fa-save"></i> Save Voucher
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(document).ready(function() {
        function calculateTotals() {
            var totalDebit = 0;
            var totalCredit = 0;
            
            $('.debit').each(function() {
                totalDebit += parseFloat($(this).val()) || 0;
            });
            
            $('.credit').each(function() {
                totalCredit += parseFloat($(this).val()) || 0;
            });
            
            $('#totalDebit').text(totalDebit.toFixed(2));
            $('#totalCredit').text(totalCredit.toFixed(2));
        }
        
        $('#addRow').click(function() {
            var newRow = `
                <tr>
                    <td>
                        <select name="account_id[]" class="form-control account-select" required>
                            <option value="">-- Select Account --</option>
                            <?php foreach($accounts as $acc): ?>
                                <option value="<?php echo $acc['id']; ?>">
                                    <?php echo $acc['account_code']; ?> - <?php echo htmlspecialchars($acc['account_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="number" name="debit[]" class="form-control debit" step="0.01" value="0"></td>
                    <td><input type="number" name="credit[]" class="form-control credit" step="0.01" value="0"></td>
                    <td class="text-center"><button type="button" class="btn btn-danger btn-sm remove-row">×</button></td>
                </tr>
            `;
            $('#voucherTable tbody').append(newRow);
            calculateTotals();
        });
        
        $(document).on('click', '.remove-row', function() {
            if($('#voucherTable tbody tr').length > 1) {
                $(this).closest('tr').remove();
                calculateTotals();
            } else {
                alert('At least one row is required!');
            }
        });
        
        $(document).on('input', '.debit, .credit', function() {
            calculateTotals();
        });
        
        calculateTotals();
    });
    </script>
</body>
</html>
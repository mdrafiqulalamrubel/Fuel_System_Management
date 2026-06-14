<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$voucher_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$error = '';
$success = '';

if($voucher_id == 0) {
    header('Location: accounting.php?tab=list');
    exit();
}

// Get voucher details
$stmt = $pdo->prepare("SELECT * FROM vouchers WHERE id = ?");
$stmt->execute([$voucher_id]);
$voucher = $stmt->fetch();

if(!$voucher) {
    header('Location: accounting.php?tab=list');
    exit();
}

// Get voucher items
$stmt = $pdo->prepare("
    SELECT vi.*, ca.account_code, ca.account_name 
    FROM voucher_items vi
    JOIN chart_of_accounts ca ON vi.account_id = ca.id
    WHERE vi.voucher_id = ?
");
$stmt->execute([$voucher_id]);
$voucher_items = $stmt->fetchAll();

// Get accounts for dropdown
$accounts = $pdo->query("SELECT * FROM chart_of_accounts WHERE is_active = 1 ORDER BY account_code")->fetchAll();

// Process update
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_voucher'])) {
    $date = $_POST['date'];
    $narration = $_POST['narration'];
    $status = $_POST['status'];
    
    try {
        $pdo->beginTransaction();
        
        // Update voucher
        $stmt = $pdo->prepare("UPDATE vouchers SET date = ?, narration = ?, status = ? WHERE id = ?");
        $stmt->execute([$date, $narration, $status, $voucher_id]);
        
        // Delete old items
        $stmt = $pdo->prepare("DELETE FROM voucher_items WHERE voucher_id = ?");
        $stmt->execute([$voucher_id]);
        
        // Insert new items
        $accounts = $_POST['account_id'];
        $debits = $_POST['debit'];
        $credits = $_POST['credit'];
        
        for($i = 0; $i < count($accounts); $i++) {
            if($debits[$i] > 0 || $credits[$i] > 0) {
                $stmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, account_id, debit_amount, credit_amount, description) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$voucher_id, $accounts[$i], $debits[$i], $credits[$i], $narration]);
            }
        }
        
        $pdo->commit();
        $success = "Voucher updated successfully!";
        
        // Refresh data
        header("Location: view_voucher.php?id=$voucher_id");
        exit();
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
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
    <title>Edit Voucher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-edit"></i> Edit Voucher</h2>
                <a href="view_voucher.php?id=<?php echo $voucher_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-eye"></i> View Voucher
                </a>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header bg-warning text-white">
                    <h5>Edit Voucher: <?php echo $voucher['voucher_no']; ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-4">
                                <label>Date</label>
                                <input type="date" name="date" class="form-control" value="<?php echo $voucher['date']; ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="draft" <?php echo $voucher['status'] == 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="approved" <?php echo $voucher['status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $voucher['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label>Narration</label>
                                <input type="text" name="narration" class="form-control" value="<?php echo htmlspecialchars($voucher['narration']); ?>" required>
                            </div>
                        </div>
                        
                        <div class="table-responsive mt-3">
                            <table class="table table-bordered" id="itemsTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Account</th>
                                        <th>Debit</th>
                                        <th>Credit</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($voucher_items as $index => $item): ?>
                                    <tr>
                                        <td>
                                            <select name="account_id[]" class="form-control" required>
                                                <option value="">Select Account</option>
                                                <?php foreach($accounts as $acc): ?>
                                                    <option value="<?php echo $acc['id']; ?>" <?php echo $item['account_id'] == $acc['id'] ? 'selected' : ''; ?>>
                                                        <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <td><input type="number" name="debit[]" class="form-control" step="0.01" value="<?php echo $item['debit_amount']; ?>"></div>
                                        <td><input type="number" name="credit[]" class="form-control" step="0.01" value="<?php echo $item['credit_amount']; ?>"></div>
                                        <td><button type="button" class="btn btn-danger btn-sm remove-row">×</button></div>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3">
                                            <button type="button" class="btn btn-primary btn-sm" id="addRow">+ Add Row</button>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <button type="submit" name="update_voucher" class="btn btn-success mt-3">
                            <i class="fas fa-save"></i> Update Voucher
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        $('#addRow').click(function() {
            let newRow = `
                <tr>
                    <td>
                        <select name="account_id[]" class="form-control" required>
                            <option value="">Select Account</option>
                            <?php foreach($accounts as $acc): ?>
                                <option value="<?php echo $acc['id']; ?>">
                                    <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                     </div>
                    <td><input type="number" name="debit[]" class="form-control" step="0.01" value="0"></div>
                    <td><input type="number" name="credit[]" class="form-control" step="0.01" value="0"></div>
                    <td><button type="button" class="btn btn-danger btn-sm remove-row">×</button></div>
                </tr>
            `;
            $('#itemsTable tbody').append(newRow);
        });
        
        $(document).on('click', '.remove-row', function() {
            $(this).closest('tr').remove();
        });
    </script>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
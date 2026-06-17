<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$voucher_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if($voucher_id == 0) {
    die('No voucher ID provided');
}

// Get voucher
$stmt = $pdo->prepare("SELECT * FROM vouchers WHERE id = ?");
$stmt->execute([$voucher_id]);
$voucher = $stmt->fetch();

if(!$voucher) {
    die('Voucher not found');
}

// Get voucher items
$stmt = $pdo->prepare("
    SELECT vi.*, ca.account_code, ca.account_name 
    FROM voucher_items vi
    JOIN chart_of_accounts ca ON vi.account_id = ca.id
    WHERE vi.voucher_id = ?
");
$stmt->execute([$voucher_id]);
$items = $stmt->fetchAll();

$total_debit = array_sum(array_column($items, 'debit_amount'));
$total_credit = array_sum(array_column($items, 'credit_amount'));

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
?>
<!DOCTYPE html>
<html>
<head>
    <title>View Voucher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h4>Voucher: <?php echo $voucher['voucher_no']; ?></h4>
                </div>
                <div class="card-body">
                    <p><strong>Date:</strong> <?php echo date('d-m-Y', strtotime($voucher['date'])); ?></p>
                    <p><strong>Type:</strong> <?php echo ucfirst($voucher['voucher_type']); ?></p>
                    <p><strong>Narration:</strong> <?php echo $voucher['narration']; ?></p>
                    <p><strong>Status:</strong> <?php echo ucfirst($voucher['status']); ?></p>
                    
                    <h5>Voucher Items</h5>
                    <table class="table table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Account</th>
                                <th>Debit</th>
                                <th>Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($items)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-danger">No items found!</td>
                            </tr>
                            <?php else: ?>
                            <?php $sl=1; foreach($items as $item): ?>
                            <tr>
                                <td><?php echo $sl++; ?></td>
                                <td><?php echo $item['account_code']; ?> - <?php echo $item['account_name']; ?></td>
                                <td><?php echo number_format($item['debit_amount'], 2); ?></td>
                                <td><?php echo number_format($item['credit_amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            <tr class="table-info fw-bold">
                                <td colspan="2" class="text-end">TOTAL</td>
                                <td><?php echo number_format($total_debit, 2); ?></td>
                                <td><?php echo number_format($total_credit, 2); ?></td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <a href="print_voucher.php?id=<?php echo $voucher_id; ?>" class="btn btn-primary" target="_blank">Print</a>
                    <a href="accounting.php?tab=list" class="btn btn-secondary">Back to List</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
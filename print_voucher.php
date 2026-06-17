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
$company = $settings['company_name'] ?? 'Fuel Station';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Print Voucher</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .voucher-box { max-width: 800px; margin: 0 auto; border: 1px solid #ddd; padding: 20px; }
        .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 20px; }
        .company { font-size: 24px; font-weight: bold; }
        .voucher-title { font-size: 20px; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        table th, table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        table th { background: #f5f5f5; }
        .text-end { text-align: right; }
        .total-row { background: #f5f5f5; font-weight: bold; }
        .footer { margin-top: 20px; border-top: 1px solid #ddd; padding-top: 10px; text-align: center; }
        .signature { margin-top: 30px; }
        .signature div { display: inline-block; width: 30%; text-align: center; }
        .signature-line { border-top: 1px solid #000; width: 80%; margin: 20px auto 5px; }
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
            .voucher-box { border: none; }
        }
    </style>
</head>
<body>
    <div class="no-print text-center" style="margin-bottom:20px;">
        <button onclick="window.print()" class="btn btn-primary">🖨️ Print</button>
        <a href="view_voucher.php?id=<?php echo $voucher_id; ?>" class="btn btn-secondary">Back</a>
    </div>
    
    <div class="voucher-box">
        <div class="header">
            <div class="company"><?php echo $company; ?></div>
            <div class="voucher-title">VOUCHER</div>
        </div>
        
        <div style="margin-bottom:15px;">
            <strong>Voucher No:</strong> <?php echo $voucher['voucher_no']; ?><br>
            <strong>Date:</strong> <?php echo date('d-m-Y', strtotime($voucher['date'])); ?><br>
            <strong>Type:</strong> <?php echo ucfirst($voucher['voucher_type']); ?><br>
            <strong>Narration:</strong> <?php echo $voucher['narration']; ?>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Account</th>
                    <th class="text-end">Debit (<?php echo $currency; ?>)</th>
                    <th class="text-end">Credit (<?php echo $currency; ?>)</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($items)): ?>
                <tr>
                    <td colspan="4" style="text-align:center;color:red;">No items found!</td>
                </tr>
                <?php else: ?>
                <?php $sl=1; foreach($items as $item): ?>
                <tr>
                    <td><?php echo $sl++; ?></td>
                    <td><?php echo $item['account_code']; ?> - <?php echo $item['account_name']; ?></td>
                    <td class="text-end"><?php echo number_format($item['debit_amount'], 2); ?></td>
                    <td class="text-end"><?php echo number_format($item['credit_amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                <tr class="total-row">
                    <td colspan="2" class="text-end">TOTAL</td>
                    <td class="text-end"><?php echo number_format($total_debit, 2); ?></td>
                    <td class="text-end"><?php echo number_format($total_credit, 2); ?></td>
                </tr>
            </tbody>
        </table>
        
        <div class="signature">
            <div>
                <div class="signature-line"></div>
                Prepared By
            </div>
            <div>
                <div class="signature-line"></div>
                Checked By
            </div>
            <div>
                <div class="signature-line"></div>
                Approved By
            </div>
        </div>
        
        <div class="footer">
            Printed on: <?php echo date('d-m-Y h:i:s A'); ?>
        </div>
    </div>
</body>
</html>
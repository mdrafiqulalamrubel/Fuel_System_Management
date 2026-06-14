<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$voucher_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if($voucher_id == 0) {
    header('Location: accounting.php?tab=list');
    exit();
}

// Get voucher details
$stmt = $pdo->prepare("
    SELECT v.*, u.full_name as creator_name, u2.full_name as approver_name
    FROM vouchers v
    LEFT JOIN users u ON v.created_by = u.id
    LEFT JOIN users u2 ON v.approved_by = u2.id
    WHERE v.id = ?
");
$stmt->execute([$voucher_id]);
$voucher = $stmt->fetch();

if(!$voucher) {
    header('Location: accounting.php?tab=list');
    exit();
}

// Get voucher items with account details
$stmt = $pdo->prepare("
    SELECT vi.*, ca.account_code, ca.account_name, ca.account_type
    FROM voucher_items vi
    JOIN chart_of_accounts ca ON vi.account_id = ca.id
    WHERE vi.voucher_id = ?
    ORDER BY vi.id
");
$stmt->execute([$voucher_id]);
$voucher_items = $stmt->fetchAll();

// Calculate totals
$total_debit = array_sum(array_column($voucher_items, 'debit_amount'));
$total_credit = array_sum(array_column($voucher_items, 'credit_amount'));

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';
$company_name = $settings['company_name'] ?? 'Fuel Station Management';
$company_address = $settings['company_address'] ?? 'Dhaka, Bangladesh';
$company_phone = $settings['company_phone'] ?? '';

// Voucher type badge color
$voucher_colors = [
    'journal' => 'primary',
    'payment' => 'danger',
    'receipt' => 'success',
    'contra' => 'info'
];
$voucher_color = $voucher_colors[$voucher['voucher_type']] ?? 'secondary';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Voucher - <?php echo $voucher['voucher_no']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .voucher-container {
            max-width: 1000px;
            margin: 30px auto;
        }
        .voucher-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .voucher-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
        }
        .voucher-title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .voucher-subtitle {
            font-size: 14px;
            opacity: 0.8;
        }
        .company-info {
            text-align: right;
        }
        .company-name {
            font-size: 18px;
            font-weight: bold;
        }
        .company-details {
            font-size: 12px;
            opacity: 0.8;
        }
        .voucher-details {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
        }
        .detail-label {
            font-weight: 600;
            color: #666;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .detail-value {
            font-weight: 600;
            font-size: 14px;
        }
        .voucher-table {
            width: 100%;
            border-collapse: collapse;
        }
        .voucher-table th {
            background: #f8f9fa;
            padding: 12px;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }
        .voucher-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #dee2e6;
        }
        .total-row {
            background: #f8f9fa;
            font-weight: bold;
        }
        .total-row td {
            border-top: 2px solid #dee2e6;
            font-weight: bold;
        }
        .signature-section {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px dashed #dee2e6;
        }
        .signature {
            text-align: center;
            margin-top: 30px;
        }
        .signature-line {
            width: 200px;
            border-top: 1px solid #000;
            margin: 10px auto 5px auto;
        }
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-approved {
            background: #d4edda;
            color: #155724;
        }
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }
        
        @media print {
            .no-print, .btn, .action-buttons, .print-hide {
                display: none !important;
            }
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .voucher-container {
                margin: 0;
                max-width: 100%;
            }
            .voucher-card {
                box-shadow: none;
            }
            .signature-section {
                margin-top: 30px;
            }
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="voucher-container">
                <!-- Action Buttons -->
                <div class="action-buttons mb-3 no-print">
                    <a href="accounting.php?tab=list" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Voucher
                    </button>
                    <a href="edit_voucher.php?id=<?php echo $voucher_id; ?>" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit Voucher
                    </a>
                </div>
                
                <!-- Voucher Card -->
                <div class="voucher-card">
                    <!-- Header -->
                    <div class="voucher-header">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="voucher-title">
                                    <i class="fas fa-file-invoice"></i> 
                                    <?php echo strtoupper($voucher['voucher_type']); ?> VOUCHER
                                </div>
                                <div class="voucher-subtitle">
                                    Voucher No: <strong><?php echo $voucher['voucher_no']; ?></strong>
                                </div>
                            </div>
                            <div class="col-md-6 company-info">
                                <div class="company-name"><?php echo htmlspecialchars($company_name); ?></div>
                                <div class="company-details"><?php echo htmlspecialchars($company_address); ?></div>
                                <div class="company-details">Tel: <?php echo htmlspecialchars($company_phone); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Voucher Details -->
                    <div class="voucher-details">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="detail-label">Voucher Date</div>
                                <div class="detail-value"><?php echo date('d-m-Y', strtotime($voucher['date'])); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="detail-label">Voucher Type</div>
                                <div class="detail-value">
                                    <span class="badge bg-<?php echo $voucher_color; ?>">
                                        <?php echo ucfirst($voucher['voucher_type']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="detail-label">Status</div>
                                <div class="detail-value">
                                    <span class="status-badge status-<?php echo $voucher['status']; ?>">
                                        <?php echo ucfirst($voucher['status']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="detail-label">Created By</div>
                                <div class="detail-value"><?php echo htmlspecialchars($voucher['creator_name']); ?></div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <div class="detail-label">Narration / Description</div>
                                <div class="detail-value"><?php echo nl2br(htmlspecialchars($voucher['narration'])); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Voucher Items Table -->
                    <div class="p-3">
                        <table class="voucher-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Account Code</th>
                                    <th>Account Name</th>
                                    <th>Account Type</th>
                                    <th class="text-end">Debit (<?php echo $currency; ?>)</th>
                                    <th class="text-end">Credit (<?php echo $currency; ?>)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $sl = 1; ?>
                                <?php foreach($voucher_items as $item): ?>
                                <tr>
                                    <td class="text-center"><?php echo $sl++; ?></td>
                                    <td><?php echo $item['account_code']; ?></div>
                                    <td><strong><?php echo htmlspecialchars($item['account_name']); ?></strong></td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo ucfirst($item['account_type']); ?>
                                        </span>
                                    </div>
                                    <td class="text-end"><?php echo number_format($item['debit_amount'], 2); ?></div>
                                    <td class="text-end"><?php echo number_format($item['credit_amount'], 2); ?></div>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="total-row">
                                    <td colspan="4" class="text-end fw-bold">TOTAL:</td>
                                    <td class="text-end fw-bold"><?php echo number_format($total_debit, 2); ?></td>
                                    <td class="text-end fw-bold"><?php echo number_format($total_credit, 2); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Amount in Words -->
                    <div class="px-3 pb-3">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Amount in Words:</strong> 
                            <?php 
                            function numberToWords($number) {
                                $words = array(
                                    '0' => 'Zero', '1' => 'One', '2' => 'Two', '3' => 'Three', 
                                    '4' => 'Four', '5' => 'Five', '6' => 'Six', '7' => 'Seven', 
                                    '8' => 'Eight', '9' => 'Nine', '10' => 'Ten', '11' => 'Eleven', 
                                    '12' => 'Twelve', '13' => 'Thirteen', '14' => 'Fourteen', 
                                    '15' => 'Fifteen', '16' => 'Sixteen', '17' => 'Seventeen', 
                                    '18' => 'Eighteen', '19' => 'Nineteen', '20' => 'Twenty', 
                                    '30' => 'Thirty', '40' => 'Forty', '50' => 'Fifty', 
                                    '60' => 'Sixty', '70' => 'Seventy', '80' => 'Eighty', 
                                    '90' => 'Ninety'
                                );
                                
                                if($number < 21) {
                                    return $words[$number];
                                } elseif($number < 100) {
                                    $tens = floor($number / 10) * 10;
                                    $units = $number % 10;
                                    return $words[$tens] . ($units ? ' ' . $words[$units] : '');
                                } elseif($number < 1000) {
                                    $hundreds = floor($number / 100);
                                    $remainder = $number % 100;
                                    return $words[$hundreds] . ' Hundred' . ($remainder ? ' ' . numberToWords($remainder) : '');
                                } else {
                                    return 'Amount too large';
                                }
                            }
                            $amount_words = numberToWords(floor($total_debit)) . ' Taka Only';
                            echo $amount_words;
                            ?>
                        </div>
                    </div>
                    
                    <!-- Signature Section -->
                    <div class="signature-section px-3 pb-4">
                        <div class="row">
                            <div class="col-md-4 signature">
                                <div class="signature-line"></div>
                                <div class="small">Prepared By</div>
                                <div class="small text-muted"><?php echo htmlspecialchars($voucher['creator_name']); ?></div>
                            </div>
                            <div class="col-md-4 signature">
                                <div class="signature-line"></div>
                                <div class="small">Checked By</div>
                                <div class="small text-muted">_________________</div>
                            </div>
                            <div class="col-md-4 signature">
                                <div class="signature-line"></div>
                                <div class="small">Approved By</div>
                                <div class="small text-muted">
                                    <?php echo htmlspecialchars($voucher['approver_name'] ?? 'Not Approved Yet'); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Footer -->
                    <div class="text-center p-3 bg-light small text-muted">
                        <i class="fas fa-print"></i> Printed on: <?php echo date('d-m-Y h:i:s A'); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
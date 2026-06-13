<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$payroll_id = isset($_GET['id']) ? $_GET['id'] : 0;

// Get payroll details
$stmt = $pdo->prepare("
    SELECT p.*, e.full_name, e.designation, e.department, e.employee_id, 
           e.joining_date, e.bank_account_no, e.phone, e.address
    FROM payroll p 
    JOIN employees e ON p.employee_id = e.id 
    WHERE p.id = ?
");
$stmt->execute([$payroll_id]);
$payroll = $stmt->fetch();

if(!$payroll) {
    die("Payroll record not found!");
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';

// Calculate totals
$total_earnings = $payroll['basic_salary'] + $payroll['allowances'] + $payroll['overtime_amount'] + $payroll['bonus'];
$total_deductions = $payroll['deductions'];
$net_payable = $payroll['net_salary'];

// Format month name
$month_name = date('F Y', strtotime($payroll['month_year'] . '-01'));

// Number to words function
function numberToWords($number) {
    $words = array(
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 
        14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 
        18 => 'Eighteen', 19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty', 
        40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy', 
        80 => 'Eighty', 90 => 'Ninety'
    );
    
    $amount = round($number, 2);
    $taka = floor($amount);
    $poisha = round(($amount - $taka) * 100);
    
    if($taka == 0) return 'Zero Taka Only';
    
    if($taka < 100) {
        if($taka < 20) {
            $taka_words = $words[$taka];
        } else {
            $taka_words = $words[10 * floor($taka/10)] . ($taka%10 ? ' ' . $words[$taka%10] : '');
        }
    } else {
        $taka_words = number_format($taka) . ' Taka';
    }
    
    $poisha_words = $poisha ? ' and ' . $poisha . ' Poisha' : '';
    return ucfirst($taka_words) . $poisha_words . ' Taka Only';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip - <?php echo $payroll['full_name']; ?> - <?php echo $month_name; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            background: white;
            margin: 0;
            padding: 15px;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none;
            }
            @page {
                size: A4;
                margin: 10mm;
            }
            .page-break {
                page-break-before: avoid;
            }
        }
        
        .payslip-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border: 1px solid #000;
        }
        
        /* Report Header */
        .report-header {
            text-align: center;
            border-bottom: 1px solid #000;
            padding: 15px;
        }
        
        .company-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .company-details {
            font-size: 10px;
        }
        
        .report-title {
            font-size: 14px;
            font-weight: bold;
            margin-top: 10px;
            text-transform: uppercase;
        }
        
        /* Employee Info Section */
        .info-section {
            border-bottom: 1px solid #000;
            padding: 12px;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .info-table td {
            padding: 4px 8px;
            font-size: 11px;
        }
        
        .info-label {
            font-weight: bold;
            width: 130px;
        }
        
        /* Salary Table */
        .salary-section {
            padding: 12px;
        }
        
        .salary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        
        .salary-table th,
        .salary-table td {
            border: 1px solid #000;
            padding: 8px;
            font-size: 11px;
        }
        
        .salary-table th {
            text-align: center;
            font-weight: bold;
            background: none;
        }
        
        .salary-table td:first-child {
            font-weight: bold;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .amount-words {
            border: 1px solid #000;
            padding: 8px;
            margin-top: 10px;
            font-size: 10px;
        }
        
        /* Footer Section */
        .footer-section {
            border-top: 1px solid #000;
            padding: 12px;
            margin-top: 10px;
        }
        
        .signature-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .signature-table td {
            padding: 20px 8px 0 8px;
            font-size: 10px;
            text-align: center;
        }
        
        .signature-line {
            border-top: 1px solid #000;
            margin-top: 30px;
            padding-top: 5px;
        }
        
        .report-footer {
            text-align: center;
            font-size: 9px;
            border-top: 1px solid #000;
            padding: 8px;
            margin-top: 10px;
        }
        
        .button-bar {
            text-align: center;
            margin-bottom: 15px;
        }
        
        .btn {
            padding: 8px 16px;
            margin: 0 5px;
            font-size: 12px;
            cursor: pointer;
            border: none;
            border-radius: 3px;
        }
        
        .btn-print {
            background: #4CAF50;
            color: white;
        }
        
        .btn-back {
            background: #007bff;
            color: white;
        }
        
        .btn-close {
            background: #6c757d;
            color: white;
        }
    </style>
</head>
<body>
    <div class="button-bar no-print">
        <button class="btn btn-print" onclick="window.print()">
            🖨️ Print Payslip
        </button>
        <button class="btn btn-back" onclick="window.location.href='payroll.php?tab=payroll'">
            ← Back to Payroll
        </button>
        <button class="btn btn-close" onclick="window.close()">
            ✕ Close
        </button>
    </div>
    
    <div class="payslip-container">
        <!-- Header -->
        <div class="report-header">
            <div class="company-name"><?php echo strtoupper($settings['company_name'] ?? 'FF ENTERPRISE'); ?></div>
            <div class="company-details"><?php echo $settings['company_address'] ?? 'Dhaka, Bangladesh'; ?></div>
            <div class="company-details">Phone: <?php echo $settings['company_phone'] ?? '+880 1234 567890'; ?></div>
            <div class="company-details">VAT Reg: <?php echo $settings['vat_reg_no'] ?? '123456789'; ?></div>
            <div class="report-title">PAYSLIP FOR <?php echo strtoupper($month_name); ?></div>
        </div>
        
        <!-- Employee Information -->
        <div class="info-section">
            <table class="info-table">
                <tr>
                    <td class="info-label">Employee ID:</td>
                    <td><?php echo $payroll['employee_id']; ?></td>
                    <td class="info-label">Joining Date:</td>
                    <td><?php echo date('d/m/Y', strtotime($payroll['joining_date'])); ?></td>
                </tr>
                <tr>
                    <td class="info-label">Employee Name:</td>
                    <td><?php echo strtoupper($payroll['full_name']); ?></td>
                    <td class="info-label">Designation:</td>
                    <td><?php echo $payroll['designation']; ?></td>
                </tr>
                <tr>
                    <td class="info-label">Department:</td>
                    <td><?php echo $payroll['department']; ?></td>
                    <td class="info-label">Pay Period:</td>
                    <td><?php echo $month_name; ?></td>
                </tr>
                <tr>
                    <td class="info-label">Bank Account:</td>
                    <td><?php echo $payroll['bank_account_no'] ?: 'N/A'; ?></td>
                    <td class="info-label">Phone:</td>
                    <td><?php echo $payroll['phone'] ?: 'N/A'; ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Salary Details -->
        <div class="salary-section">
            <table class="salary-table">
                <thead>
                    <tr>
                        <th width="50%">EARNINGS</th>
                        <th width="25%">Amount (<?php echo $currency; ?>)</th>
                        <th width="25%">DEDUCTIONS</th>
                        <th width="25%">Amount (<?php echo $currency; ?>)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Basic Salary</td
                        <td class="text-right"><?php echo number_format($payroll['basic_salary'], 2); ?></td
                        <td>Deductions</td
                        <td class="text-right"><?php echo number_format($payroll['deductions'], 2); ?></td
                     </tr
                    <tr>
                        <td>House Rent Allowance (50%)</td
                        <td class="text-right"><?php echo number_format($payroll['allowances'] * 0.5, 2); ?></td
                        <td></td
                        <td class="text-right"></td
                     </tr
                    <tr>
                        <td>Medical Allowance (30%)</td
                        <td class="text-right"><?php echo number_format($payroll['allowances'] * 0.3, 2); ?></td
                        <td></td
                        <td class="text-right"></td
                     </tr
                    <tr>
                        <td>Conveyance Allowance (20%)</td
                        <td class="text-right"><?php echo number_format($payroll['allowances'] * 0.2, 2); ?></td
                        <td></td
                        <td class="text-right"></td
                     </tr
                    <tr>
                        <td>Overtime Amount</td
                        <td class="text-right"><?php echo number_format($payroll['overtime_amount'], 2); ?></td
                        <td></td
                        <td class="text-right"></td
                     </tr
                    <tr>
                        <td>Festival Bonus</td
                        <td class="text-right"><?php echo number_format($payroll['bonus'], 2); ?></td
                        <td></td
                        <td class="text-right"></td
                     </tr
                    <tr style="border-top: 1px solid #000;">
                        <td><strong>Total Earnings</strong></td
                        <td class="text-right"><strong><?php echo number_format($total_earnings, 2); ?></strong></td
                        <td><strong>Total Deductions</strong></td
                        <td class="text-right"><strong><?php echo number_format($total_deductions, 2); ?></strong></td
                     </tr
                </tbody>
             </table
            
            <div class="amount-words">
                <strong>Amount in Words:</strong> <?php echo numberToWords($net_payable); ?>
            </div>
            
            <div style="margin-top: 15px; padding: 8px; border: 1px solid #000; text-align: center;">
                <strong>NET PAYABLE: <?php echo $currency; ?> <?php echo number_format($net_payable, 2); ?></strong>
            </div>
        </div>
        
        <!-- Footer with Signatures -->
        <div class="footer-section">
            <table class="signature-table">
                <tr>
                    <td width="33%">
                        <div class="signature-line">Employee Signature</div>
                    </td>
                    <td width="33%">
                        <div class="signature-line">HR Manager Signature</div>
                    </td>
                    <td width="34%">
                        <div class="signature-line">Authorized Signature</div>
                    </td>
                 </tr
             </table
        </div>
        
        <!-- Report Footer -->
        <div class="report-footer">
            This is a computer generated payslip. Valid with authorized signature.<br>
            Generated on: <?php echo date('d/m/Y h:i:s A'); ?>
        </div>
    </div>
    
    <script>
        // Auto print if requested
        <?php if(isset($_GET['print']) && $_GET['print'] == 1): ?>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
        <?php endif; ?>
    </script>
</body>
</html>
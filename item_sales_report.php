<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$category_id = isset($_GET['category_id']) ? $_GET['category_id'] : '';
$item_id = isset($_GET['item_id']) ? $_GET['item_id'] : '';
$sale_type = isset($_GET['sale_type']) ? $_GET['sale_type'] : '';

// =============================================
// GET CATEGORIES FOR FILTER
// =============================================
$categories = $pdo->query("SELECT * FROM item_categories WHERE is_active = 1 ORDER BY category_name")->fetchAll();

// =============================================
// GET ITEMS FOR FILTER
// =============================================
$items = $pdo->query("SELECT id, item_name FROM items WHERE is_active = 1 ORDER BY item_name")->fetchAll();

// =============================================
// BUILD QUERY
// =============================================
$sql = "
    SELECT 
        isa.*,
        c.customer_name,
        c.customer_code,
        u.full_name as operator_name,
        (SELECT COUNT(*) FROM item_sale_items WHERE sale_id = isa.id) as item_count
    FROM item_sales isa
    LEFT JOIN customers c ON isa.customer_id = c.id
    LEFT JOIN users u ON isa.created_by = u.id
    WHERE DATE(isa.sale_date) BETWEEN ? AND ?
";

$params = [$from_date, $to_date];

if($sale_type) {
    $sql .= " AND isa.sale_type = ?";
    $params[] = $sale_type;
}

$sql .= " ORDER BY isa.sale_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

// =============================================
// GET SALE ITEMS FOR DETAILED REPORT
// =============================================
$sale_ids = array_column($sales, 'id');
$sale_items = [];
if(!empty($sale_ids)) {
    $placeholders = implode(',', array_fill(0, count($sale_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT 
            isi.*,
            i.item_name,
            i.item_type,
            i.unit,
            ic.category_name,
            isi.sale_id
        FROM item_sale_items isi
        JOIN items i ON isi.item_id = i.id
        LEFT JOIN item_categories ic ON i.category_id = ic.id
        WHERE isi.sale_id IN ($placeholders)
    ");
    $stmt->execute($sale_ids);
    $sale_items = $stmt->fetchAll();
}

// Group items by sale_id
$items_by_sale = [];
foreach($sale_items as $item) {
    $items_by_sale[$item['sale_id']][] = $item;
}

// =============================================
// CALCULATE TOTALS
// =============================================
$total_sales = count($sales);
$total_amount = array_sum(array_column($sales, 'total_amount'));
$total_subtotal = array_sum(array_column($sales, 'subtotal'));
$total_discount = array_sum(array_column($sales, 'discount_amount'));
$total_tax = array_sum(array_column($sales, 'tax_amount'));

// Calculate product vs service breakdown
$total_product_sales = 0;
$total_service_sales = 0;
$total_product_qty = 0;
$total_service_qty = 0;

foreach($sale_items as $item) {
    if($item['item_type'] == 'product') {
        $total_product_sales += $item['total_amount'];
        $total_product_qty += $item['quantity'];
    } else {
        $total_service_sales += $item['total_amount'];
        $total_service_qty += $item['quantity'];
    }
}

$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency_symbol'] ?? 'BDT';

// Calculate daily average
$start = new DateTime($from_date);
$end = new DateTime($to_date);
$days_count = $start->diff($end)->days + 1;
$daily_avg = $days_count > 0 ? $total_amount / $days_count : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Sales Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
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
        .stats-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stats-card.pink { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stats-card.blue { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stats-card.orange { background: linear-gradient(135deg, #f6d365 0%, #fda085 100%); }
        .badge-product { background: #28a745; color: white; }
        .badge-service { background: #17a2b8; color: white; }
        .badge-cash { background: #28a745; color: white; }
        .badge-credit { background: #ffc107; color: #856404; }
        .clickable-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .clickable-row:hover { background-color: #e8f0fe !important; }
        .drill-down-row { background-color: #f8f9fa; }
        .drill-down-row td { padding: 5px 10px !important; }
        .summary-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 4px solid #667eea;
        }
        .summary-card .value {
            font-size: 20px;
            font-weight: bold;
        }
        @media print {
            .sidebar, .no-print, .stats-card, .card-header .btn, 
            .dataTables_length, .dataTables_filter, .dataTables_paginate,
            form, .btn { display: none !important; }
            .main-content { margin: 0 !important; padding: 10px !important; }
            .print-header { display: block !important; text-align: center; margin-bottom: 20px; }
        }
        .print-header { display: none; }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="print-header">
                <h2><?php echo $settings['company_name'] ?? 'FF Enterprise'; ?></h2>
                <h4>Item & Services Sales Report</h4>
                <p>Period: <?php echo date('d F Y', strtotime($from_date)); ?> to <?php echo date('d F Y', strtotime($to_date)); ?></p>
                <hr>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
                <h2><i class="fas fa-boxes"></i> Item & Services Sales Report</h2>
                <div class="no-print">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="item_pos.php" class="btn btn-success">
                        <i class="fas fa-shopping-cart"></i> POS
                    </a>
                    <a href="item_management.php" class="btn btn-info">
                        <i class="fas fa-cog"></i> Manage Items
                    </a>
                    <a href="reports.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- Filter -->
            <div class="card mb-4 no-print">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-filter"></i> Filter Report</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-2">
                            <label>From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>">
                        </div>
                        <div class="col-md-2">
                            <label>To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>">
                        </div>
                        <div class="col-md-2">
                            <label>Category</label>
                            <select name="category_id" class="form-control">
                                <option value="">All Categories</option>
                                <?php foreach($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" <?php echo $category_id == $cat['id'] ? 'selected' : ''; ?>>
                                        <?php echo $cat['category_name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>Item</label>
                            <select name="item_id" class="form-control">
                                <option value="">All Items</option>
                                <?php foreach($items as $item): ?>
                                    <option value="<?php echo $item['id']; ?>" <?php echo $item_id == $item['id'] ? 'selected' : ''; ?>>
                                        <?php echo $item['item_name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>Sale Type</label>
                            <select name="sale_type" class="form-control">
                                <option value="">All Types</option>
                                <option value="cash" <?php echo $sale_type == 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="credit" <?php echo $sale_type == 'credit' ? 'selected' : ''; ?>>Credit</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search"></i> Generate
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-receipt"></i>
                        <h3><?php echo $total_sales; ?></h3>
                        <p>Total Transactions</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card green">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3><?php echo $currency; ?> <?php echo number_format($total_amount, 2); ?></h3>
                        <p>Total Revenue</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card orange">
                        <i class="fas fa-percent"></i>
                        <h3><?php echo number_format($daily_avg, 2); ?></h3>
                        <p>Daily Average</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card blue">
                        <i class="fas fa-calendar-day"></i>
                        <h3><?php echo $days_count; ?> Days</h3>
                        <p>Report Period</p>
                    </div>
                </div>
            </div>
            
            <!-- Product vs Service Summary -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="summary-card" style="border-left-color: #28a745;">
                        <h6><i class="fas fa-cube"></i> Products</h6>
                        <div class="value text-success"><?php echo $currency; ?> <?php echo number_format($total_product_sales, 2); ?></div>
                        <small><?php echo number_format($total_product_qty, 2); ?> units sold</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="summary-card" style="border-left-color: #17a2b8;">
                        <h6><i class="fas fa-cogs"></i> Services</h6>
                        <div class="value text-info"><?php echo $currency; ?> <?php echo number_format($total_service_sales, 2); ?></div>
                        <small><?php echo number_format($total_service_qty, 2); ?> services rendered</small>
                    </div>
                </div>
            </div>
            
            <!-- Sales Table -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5><i class="fas fa-list"></i> Sales List</h5>
                    <small class="d-block text-light">Click on any row to view item details</small>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="salesTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Invoice</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Items</th>
                                    <th class="text-end">Subtotal</th>
                                    <th class="text-end">Discount</th>
                                    <th class="text-end">Tax</th>
                                    <th class="text-end">Total</th>
                                    <th>Type</th>
                                    <th>Operator</th>
                                    <th class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($sales as $sale): ?>
                                <tr class="clickable-row" data-sale-id="<?php echo $sale['id']; ?>">
                                    <td>
                                        <a href="javascript:void(0)" class="invoice-link" onclick="toggleDetails(<?php echo $sale['id']; ?>)">
                                            <?php echo $sale['invoice_no']; ?>
                                        </a>
                                    </td>
                                    <td><?php echo date('d-m-Y h:i A', strtotime($sale['sale_date'])); ?></td>
                                    <td>
                                        <?php if($sale['customer_name']): ?>
                                            <strong><?php echo $sale['customer_name']; ?></strong>
                                            <?php if($sale['customer_code']): ?>
                                                <br><small><?php echo $sale['customer_code']; ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">Walk-in</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?php echo $sale['item_count']; ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['subtotal'], 2); ?></td>
                                    <td class="text-end text-danger"><?php echo $sale['discount_amount'] > 0 ? '- ' . $currency . ' ' . number_format($sale['discount_amount'], 2) : '-'; ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['tax_amount'], 2); ?></td>
                                    <td class="text-end fw-bold text-success"><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></td>
                                    <td>
                                        <?php if($sale['sale_type'] == 'cash'): ?>
                                            <span class="badge badge-cash">Cash</span>
                                        <?php else: ?>
                                            <span class="badge badge-credit">Credit</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $sale['operator_name'] ?? 'N/A'; ?></td>
                                    <td class="no-print">
                                        <a href="print_item_invoice.php?invoice=<?php echo $sale['invoice_no']; ?>" class="btn btn-sm btn-info" target="_blank" title="View Invoice">
                                            <i class="fas fa-file-invoice"></i>
                                        </a>
                                        <a href="print_thermal_receipt.php?invoice=<?php echo $sale['invoice_no']; ?>&type=item" class="btn btn-sm btn-primary" target="_blank" title="Thermal Receipt">
                                            <i class="fas fa-receipt"></i>
                                        </a>
                                    </td>
                                </tr>
                                <!-- Drill-down row for items -->
                                <tr class="drill-down-row" id="details-<?php echo $sale['id']; ?>" style="display:none;">
                                    <td colspan="11">
                                        <div class="p-2">
                                            <strong><i class="fas fa-box"></i> Items in this sale:</strong>
                                            <div class="table-responsive mt-2">
                                                <table class="table table-sm table-bordered">
                                                    <thead>
                                                        <tr>
                                                            <th>Item</th>
                                                            <th>Category</th>
                                                            <th>Type</th>
                                                            <th class="text-end">Qty</th>
                                                            <th class="text-end">Unit Price</th>
                                                            <th class="text-end">Total</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php 
                                                        $items_for_sale = $items_by_sale[$sale['id']] ?? [];
                                                        if(empty($items_for_sale)): 
                                                        ?>
                                                        <tr>
                                                            <td colspan="6" class="text-center text-muted">No items found</td>
                                                        </tr>
                                                        <?php else: ?>
                                                            <?php foreach($items_for_sale as $item): ?>
                                                            <tr>
                                                                <td><strong><?php echo $item['item_name']; ?></strong></td>
                                                                <td><?php echo $item['category_name'] ?? '-'; ?></td>
                                                                <td>
                                                                    <span class="badge <?php echo $item['item_type'] == 'product' ? 'badge-product' : 'badge-service'; ?>">
                                                                        <?php echo ucfirst($item['item_type']); ?>
                                                                    </span>
                                                                </td>
                                                                <td class="text-end"><?php echo number_format($item['quantity'], 2); ?> <?php echo $item['unit']; ?></td>
                                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($item['unit_price'], 2); ?></td>
                                                                <td class="text-end"><?php echo $currency; ?> <?php echo number_format($item['total_amount'], 2); ?></td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </tbody>
                                                    <tfoot>
                                                        <tr class="fw-bold">
                                                            <td colspan="5" class="text-end">Total:</td>
                                                            <td class="text-end"><?php echo $currency; ?> <?php echo number_format($sale['total_amount'], 2); ?></td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($sales)): ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted">No sales found for the selected period</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="4" class="text-end">TOTAL:</td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_subtotal, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_discount, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_tax, 2); ?></td>
                                    <td class="text-end"><?php echo $currency; ?> <?php echo number_format($total_amount, 2); ?></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Top Selling Items -->
            <?php if(!empty($sale_items)): ?>
            <div class="card mt-4">
                <div class="card-header bg-warning text-dark">
                    <h5><i class="fas fa-chart-bar"></i> Top Selling Items</h5>
                </div>
                <div class="card-body">
                    <?php
                    // Aggregate items by item_id
                    $item_summary = [];
                    foreach($sale_items as $item) {
                        $key = $item['item_id'];
                        if(!isset($item_summary[$key])) {
                            $item_summary[$key] = [
                                'item_name' => $item['item_name'],
                                'item_type' => $item['item_type'],
                                'category_name' => $item['category_name'] ?? 'Uncategorized',
                                'total_qty' => 0,
                                'total_amount' => 0,
                                'transaction_count' => 0
                            ];
                        }
                        $item_summary[$key]['total_qty'] += $item['quantity'];
                        $item_summary[$key]['total_amount'] += $item['total_amount'];
                        $item_summary[$key]['transaction_count']++;
                    }
                    
                    // Sort by total amount
                    usort($item_summary, function($a, $b) {
                        return $b['total_amount'] - $a['total_amount'];
                    });
                    
                    // Take top 10
                    $top_items = array_slice($item_summary, 0, 10);
                    ?>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>Item</th>
                                    <th>Category</th>
                                    <th>Type</th>
                                    <th class="text-end">Total Qty</th>
                                    <th class="text-end">Transactions</th>
                                    <th class="text-end">Revenue</th>
                                    <th class="text-end">% of Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rank = 1; foreach($top_items as $item): 
                                    $percent = $total_amount > 0 ? ($item['total_amount'] / $total_amount) * 100 : 0;
                                ?>
                                <tr>
                                    <td><?php echo $rank++; ?></td>
                                    <td><strong><?php echo $item['item_name']; ?></strong></td>
                                    <td><?php echo $item['category_name']; ?></td>
                                    <td>
                                        <span class="badge <?php echo $item['item_type'] == 'product' ? 'badge-product' : 'badge-service'; ?>">
                                            <?php echo ucfirst($item['item_type']); ?>
                                        </span>
                                    </td>
                                    <td class="text-end"><?php echo number_format($item['total_qty'], 2); ?></td>
                                    <td class="text-end"><?php echo $item['transaction_count']; ?></td>
                                    <td class="text-end fw-bold text-success"><?php echo $currency; ?> <?php echo number_format($item['total_amount'], 2); ?></td>
                                    <td class="text-end"><?php echo number_format($percent, 1); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Report Footer -->
            <div class="text-center mt-4 text-muted">
                <hr>
                <p>This is a computer generated report. Valid with authorized signature.</p>
                <p>*** End of Report ***</p>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#salesTable').DataTable({
                order: [[1, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
        
        function toggleDetails(saleId) {
            var detailsRow = document.getElementById('details-' + saleId);
            if(detailsRow.style.display === 'none') {
                detailsRow.style.display = 'table-row';
            } else {
                detailsRow.style.display = 'none';
            }
        }
    </script>
</body>
</html>
<?php
require_once 'config/database.php';
if(!isLoggedIn()) redirect('index.php');

$user = getCurrentUser();
$error = '';
$success = '';

// Update fuel product prices
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_prices'])) {
    $product_ids = $_POST['product_id'];
    $product_names = $_POST['product_name'];
    $unit_prices = $_POST['unit_price'];
    $purchase_rates = $_POST['purchase_rate'];
    $vat_percentages = $_POST['vat_percentage'];
    $tax_percentages = $_POST['tax_percentage'];
    
    try {
        for($i = 0; $i < count($product_ids); $i++) {
            $stmt = $pdo->prepare("UPDATE fuel_products SET 
                unit_price = ?, 
                purchase_rate = ?, 
                vat_percentage = ?, 
                tax_percentage = ? 
                WHERE id = ?");
            $stmt->execute([
                $unit_prices[$i], 
                $purchase_rates[$i], 
                $vat_percentages[$i], 
                $tax_percentages[$i], 
                $product_ids[$i]
            ]);
        }
        $success = "Fuel prices updated successfully!";
    } catch(Exception $e) {
        $error = "Error updating prices: " . $e->getMessage();
    }
}

// Update single product price via AJAX (for quick update)
if(isset($_GET['ajax_update'])) {
    header('Content-Type: application/json');
    $id = $_GET['id'];
    $field = $_GET['field'];
    $value = $_GET['value'];
    
    $allowed_fields = ['unit_price', 'purchase_rate', 'vat_percentage', 'tax_percentage'];
    if(in_array($field, $allowed_fields)) {
        $stmt = $pdo->prepare("UPDATE fuel_products SET $field = ? WHERE id = ?");
        if($stmt->execute([$value, $id])) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }
    }
    exit;
}

// Get all fuel products
$products = $pdo->query("SELECT * FROM fuel_products ORDER BY id")->fetchAll();

// Get system settings for VAT/Tax defaults
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Price Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        .price-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        .price-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .fuel-icon {
            font-size: 40px;
            margin-bottom: 10px;
        }
        .fuel-name {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .price-value {
            font-size: 28px;
            font-weight: bold;
            color: #28a745;
        }
        .edit-price {
            cursor: pointer;
            color: #007bff;
        }
        .edit-price:hover {
            text-decoration: underline;
        }
        .inline-edit {
            display: none;
            width: 100%;
            padding: 5px;
        }
        .inline-edit.active {
            display: inline-block;
        }
        .price-display {
            display: inline-block;
        }
        .price-display.hide {
            display: none;
        }
    </style>
</head>
<body>
    <?php include 'left_menu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-tags"></i> Fuel Product Price Settings</h2>
                <div>
                    <button class="btn btn-success" onclick="window.print()">
                        <i class="fas fa-print"></i> Print Price List
                    </button>
                </div>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <!-- Price Update Form -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-edit"></i> Update Fuel Prices</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="priceForm">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" id="priceTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Product</th>
                                        <th>Selling Price (BDT/Liter)</th>
                                        <th>Purchase Rate (BDT/Liter)</th>
                                        <th>VAT (%)</th>
                                        <th>Tax (%)</th>
                                        <th>Profit Margin</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($products as $p): ?>
                                    <?php 
                                        $profit = $p['unit_price'] - $p['purchase_rate'];
                                        $margin = $p['purchase_rate'] > 0 ? ($profit / $p['purchase_rate']) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo $p['product_name']; ?></strong>
                                            <input type="hidden" name="product_id[]" value="<?php echo $p['id']; ?>">
                                            <input type="hidden" name="product_name[]" value="<?php echo $p['product_name']; ?>">
                                        </td>
                                        <td>
                                            <input type="number" name="unit_price[]" class="form-control price-input" 
                                                   value="<?php echo $p['unit_price']; ?>" step="0.01" required
                                                   data-product="<?php echo $p['product_name']; ?>"
                                                   data-id="<?php echo $p['id']; ?>"
                                                   data-field="unit_price">
                                        </td>
                                        <td>
                                            <input type="number" name="purchase_rate[]" class="form-control purchase-input" 
                                                   value="<?php echo $p['purchase_rate']; ?>" step="0.01" required
                                                   data-product="<?php echo $p['product_name']; ?>"
                                                   data-id="<?php echo $p['id']; ?>"
                                                   data-field="purchase_rate">
                                        </td>
                                        <td>
                                            <input type="number" name="vat_percentage[]" class="form-control" 
                                                   value="<?php echo $p['vat_percentage']; ?>" step="0.01" required>
                                        </td>
                                        <td>
                                            <input type="number" name="tax_percentage[]" class="form-control" 
                                                   value="<?php echo $p['tax_percentage']; ?>" step="0.01" required>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?php echo $margin > 10 ? 'success' : ($margin > 5 ? 'warning' : 'danger'); ?>">
                                                <?php echo number_format($margin, 1); ?>%
                                            </span>
                                            <br>
                                            <small>Profit: BDT <?php echo number_format($profit, 2); ?>/L</small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <td colspan="6">
                                            <button type="submit" name="update_prices" class="btn btn-primary">
                                                <i class="fas fa-save"></i> Save All Changes
                                            </button>
                                            <button type="button" class="btn btn-secondary" onclick="resetForm()">
                                                <i class="fas fa-undo"></i> Reset
                                            </button>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Price Cards View -->
            <h4 class="mb-3"><i class="fas fa-chart-line"></i> Current Fuel Prices (Quick View)</h4>
            <div class="row">
                <?php foreach($products as $p): ?>
                <div class="col-md-3">
                    <div class="price-card text-center">
                        <?php
                            $icons = [
                                'Diesel' => 'fa-oil-can',
                                'Petrol' => 'fa-gas-pump',
                                'Octane' => 'fa-fire',
                                'CNG' => 'fa-bolt',
                                'LPG' => 'fa-fire-extinguisher'
                            ];
                            $icon = $icons[$p['product_name']] ?? 'fa-gas-pump';
                        ?>
                        <div class="fuel-icon">
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>
                        <div class="fuel-name"><?php echo $p['product_name']; ?></div>
                        <div class="price-value">
                            ৳ <?php echo number_format($p['unit_price'], 2); ?>
                        </div>
                        <div class="text-muted">per Liter</div>
                        <hr>
                        <div class="row">
                            <div class="col-6">
                                <small>Purchase</small><br>
                                <strong>৳ <?php echo number_format($p['purchase_rate'], 2); ?></strong>
                            </div>
                            <div class="col-6">
                                <small>Profit</small><br>
                                <strong>৳ <?php echo number_format($p['unit_price'] - $p['purchase_rate'], 2); ?></strong>
                            </div>
                        </div>
                        <div class="mt-2">
                            <small>VAT: <?php echo $p['vat_percentage']; ?>% | Tax: <?php echo $p['tax_percentage']; ?>%</small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Price Change History -->
            <div class="card mt-4">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-history"></i> Price Change History</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="historyTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Product</th>
                                    <th>Old Price</th>
                                    <th>New Price</th>
                                    <th>Changed By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Get price change history from logs
                                $stmt = $pdo->prepare("
                                    SELECT * FROM activity_logs 
                                    WHERE action = 'price_update' 
                                    ORDER BY created_at DESC 
                                    LIMIT 50
                                ");
                                $stmt->execute();
                                $history = $stmt->fetchAll();
                                foreach($history as $h):
                                ?>
                                <tr>
                                    <td><?php echo date('d M Y H:i', strtotime($h['created_at'])); ?></td>
                                    <td><?php echo $h['description']; ?></td>
                                    <td colspan="3"><?php echo $h['ip_address']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($history)): ?>
                                <tr>
                                    <td colspan="5" class="text-center">No price change history available</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Edit Modal -->
    <div class="modal fade" id="quickEditModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5><i class="fas fa-edit"></i> Quick Price Update</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Product</label>
                        <input type="text" id="quick_product" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label>Field</label>
                        <input type="text" id="quick_field" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label>New Value</label>
                        <input type="number" id="quick_value" class="form-control" step="0.01">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveQuickEdit()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    
    <script>
        let currentEdit = {};
        
        $(document).ready(function() {
            $('#priceTable, #historyTable').DataTable({
                order: [[0, 'asc']],
                pageLength: 25
            });
            
            // Auto-save on input change (optional)
            $('.price-input, .purchase-input').on('change', function() {
                let row = $(this).closest('tr');
                let sellingPrice = parseFloat(row.find('.price-input').val());
                let purchasePrice = parseFloat(row.find('.purchase-input').val());
                
                if(!isNaN(sellingPrice) && !isNaN(purchasePrice)) {
                    let profit = sellingPrice - purchasePrice;
                    let margin = purchasePrice > 0 ? (profit / purchasePrice) * 100 : 0;
                    
                    let marginSpan = row.find('.badge');
                    marginSpan.text(margin.toFixed(1) + '%');
                    
                    if(margin > 10) {
                        marginSpan.removeClass('bg-warning bg-danger').addClass('bg-success');
                    } else if(margin > 5) {
                        marginSpan.removeClass('bg-success bg-danger').addClass('bg-warning');
                    } else {
                        marginSpan.removeClass('bg-success bg-warning').addClass('bg-danger');
                    }
                    
                    row.find('small').text('Profit: BDT ' + profit.toFixed(2) + '/L');
                }
            });
        });
        
        function quickEdit(product, field, currentValue, productId) {
            currentEdit = {
                product: product,
                field: field,
                productId: productId,
                currentValue: currentValue
            };
            
            document.getElementById('quick_product').value = product;
            document.getElementById('quick_field').value = field.replace('_', ' ').toUpperCase();
            document.getElementById('quick_value').value = currentValue;
            
            new bootstrap.Modal(document.getElementById('quickEditModal')).show();
        }
        
        function saveQuickEdit() {
            let newValue = document.getElementById('quick_value').value;
            
            $.ajax({
                url: 'fuel_price_settings.php',
                method: 'GET',
                data: {
                    ajax_update: 1,
                    id: currentEdit.productId,
                    field: currentEdit.field,
                    value: newValue
                },
                success: function(response) {
                    let res = JSON.parse(response);
                    if(res.success) {
                        location.reload();
                    } else {
                        alert('Error updating price!');
                    }
                }
            });
        }
        
        function resetForm() {
            if(confirm('Reset all changes? Unsaved changes will be lost.')) {
                location.reload();
            }
        }
        
        // Print price list
        function printPriceList() {
            window.print();
        }
    </script>
</body>
</html>
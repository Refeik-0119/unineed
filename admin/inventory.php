<?php

require_once '../config/database.php';
requireAdmin();

// Handle stock updates from either the modal (update_stock) or quick adjustment (adjust_stock)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_stock']) || isset($_POST['adjust_stock']))) {
    $is_quick = isset($_POST['adjust_stock']);
    $product_id = intval(clean($_POST['product_id'] ?? 0));
    $variant_id = isset($_POST['variant_id']) && $_POST['variant_id'] !== '' ? intval(clean($_POST['variant_id'])) : null;
    $reason = isset($_POST['reason']) ? clean($_POST['reason']) : '';

    // Start transaction
    mysqli_begin_transaction($conn);
    try {
        if ($is_quick) {
            $adjust_type = isset($_POST['adjustment_type']) ? clean($_POST['adjustment_type']) : 'add';
            $qty = isset($_POST['quantity']) ? intval(clean($_POST['quantity'])) : 0;

            if ($variant_id) {
                $cur_q = "SELECT stock_quantity, price FROM product_variants WHERE variant_id = $variant_id AND product_id = $product_id LIMIT 1";
                $r = mysqli_query($conn, $cur_q);
                if (!$r || mysqli_num_rows($r) === 0) throw new Exception('Variant not found');
                $row = mysqli_fetch_assoc($r);
                $current_stock = intval($row['stock_quantity']);
                $price = floatval($row['price']); // Selling Price

                $new_stock = ($adjust_type === 'add') ? ($current_stock + $qty) : ($current_stock - $qty);
                if ($new_stock < 0) $new_stock = 0;

                $stock_change = $new_stock - $current_stock;
                $movement_type = $stock_change > 0 ? 'add' : ($stock_change < 0 ? 'subtract' : 'adjustment');

                $upd = "UPDATE product_variants SET stock_quantity = $new_stock WHERE variant_id = $variant_id";
                mysqli_query($conn, $upd);

                // Ensure inventory_movements columns exist
                $col_check = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'variant_id'");
                if (mysqli_num_rows($col_check) === 0) {
                    mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN variant_id INT NULL AFTER product_id");
                }
                $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
                if (mysqli_num_rows($col_check2) === 0) {
                    mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER variant_id");
                }

                $mv_reason = mysqli_real_escape_string($conn, $reason ?: ("Quick variant adjustment"));
                // fetch variant type/value for description if available
                $vt = '';
                $vv = '';
                $vtq = mysqli_query($conn, "SELECT variant_type, variant_value FROM product_variants WHERE variant_id = $variant_id LIMIT 1");
                if ($vtq && mysqli_num_rows($vtq)) {
                    $vtrow = mysqli_fetch_assoc($vtq);
                    $vt = mysqli_real_escape_string($conn, $vtrow['variant_type']);
                    $vv = mysqli_real_escape_string($conn, $vtrow['variant_value']);
                }
                if ($stock_change !== 0) {
                    $ins_mv = "INSERT INTO inventory_movements (product_id, variant_id, variant_type, variant_value, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) ";
                    $ins_mv .= "VALUES ($product_id, $variant_id, '$vt', '$vv', $price, $stock_change, $current_stock, $new_stock, '$movement_type', '$mv_reason', {$_SESSION['user_id']})";
                    mysqli_query($conn, $ins_mv);
                }

            } else {
                // product-level quick adjust
                $current_q = mysqli_query($conn, "SELECT stock_quantity, price FROM products WHERE product_id = $product_id LIMIT 1");
                if (!$current_q || mysqli_num_rows($current_q) === 0) throw new Exception('Product not found');
                $prow = mysqli_fetch_assoc($current_q);
                $current_stock = intval($prow['stock_quantity']);
                $price = floatval($prow['price']); // Selling Price

                $new_stock = ($adjust_type === 'add') ? ($current_stock + $qty) : ($current_stock - $qty);
                if ($new_stock < 0) $new_stock = 0;

                $stock_change = $new_stock - $current_stock;
                $movement_type = $stock_change > 0 ? 'add' : ($stock_change < 0 ? 'subtract' : 'adjustment');

                $upd = "UPDATE products SET stock_quantity = $new_stock WHERE product_id = $product_id";
                mysqli_query($conn, $upd);

                $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
                if (mysqli_num_rows($col_check2) === 0) {
                    mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER product_id");
                }

                $mv_reason = mysqli_real_escape_string($conn, $reason ?: ('Quick product adjustment'));
                if ($stock_change !== 0) {
                    $ins_mv = "INSERT INTO inventory_movements (product_id, variant_type, variant_value, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) ";
                    $ins_mv .= "VALUES ($product_id, NULL, NULL, $price, $stock_change, $current_stock, $new_stock, '$movement_type', '$mv_reason', {$_SESSION['user_id']})";
                    mysqli_query($conn, $ins_mv);
                }
            }

        } else {
            // Modal form: update_stock
            $new_stock = isset($_POST['stock_quantity']) ? intval(clean($_POST['stock_quantity'])) : null;
            if ($variant_id) {
                $cur_q = "SELECT stock_quantity, price FROM product_variants WHERE variant_id = $variant_id AND product_id = $product_id LIMIT 1";
                $r = mysqli_query($conn, $cur_q);
                if (!$r || mysqli_num_rows($r) === 0) throw new Exception('Variant not found');
                $row = mysqli_fetch_assoc($r);
                $current_stock = intval($row['stock_quantity']);
                $price = floatval($row['price']); // Selling Price

                if ($new_stock === null) throw new Exception('New stock quantity required for variant update');
                $stock_change = $new_stock - $current_stock;
                $movement_type = $stock_change > 0 ? 'add' : ($stock_change < 0 ? 'subtract' : 'adjustment');

                $upd = "UPDATE product_variants SET stock_quantity = $new_stock WHERE variant_id = $variant_id";
                mysqli_query($conn, $upd);

                $col_check = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'variant_id'");
                if (mysqli_num_rows($col_check) === 0) {
                    mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN variant_id INT NULL AFTER product_id");
                }
                $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
                if (mysqli_num_rows($col_check2) === 0) {
                    mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER variant_id");
                }

                $mv_reason = mysqli_real_escape_string($conn, $reason ?: ("Variant update"));
                $vt = '';
                $vv = '';
                $vtq = mysqli_query($conn, "SELECT variant_type, variant_value FROM product_variants WHERE variant_id = $variant_id LIMIT 1");
                if ($vtq && mysqli_num_rows($vtq)) {
                    $vtrow = mysqli_fetch_assoc($vtq);
                    $vt = mysqli_real_escape_string($conn, $vtrow['variant_type']);
                    $vv = mysqli_real_escape_string($conn, $vtrow['variant_value']);
                }
                if ($stock_change !== 0) {
                    $ins_mv = "INSERT INTO inventory_movements (product_id, variant_id, variant_type, variant_value, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) ";
                    $ins_mv .= "VALUES ($product_id, $variant_id, '$vt', '$vv', $price, $stock_change, $current_stock, $new_stock, '$movement_type', '$mv_reason', {$_SESSION['user_id']})";
                    mysqli_query($conn, $ins_mv);
                }

            } else {
                // Product-level update (no variants)
                if ($new_stock === null) throw new Exception('New stock quantity required');
                $current_q = mysqli_query($conn, "SELECT stock_quantity, price FROM products WHERE product_id = $product_id LIMIT 1");
                if (!$current_q || mysqli_num_rows($current_q) === 0) throw new Exception('Product not found');
                $prow = mysqli_fetch_assoc($current_q);
                $current_stock = intval($prow['stock_quantity']);
                $price = floatval($prow['price']); // Selling Price
                $stock_change = $new_stock - $current_stock;
                $movement_type = $stock_change > 0 ? 'add' : ($stock_change < 0 ? 'subtract' : 'adjustment');

                $upd = "UPDATE products SET stock_quantity = $new_stock WHERE product_id = $product_id";
                mysqli_query($conn, $upd);

                $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
                if (mysqli_num_rows($col_check2) === 0) {
                    mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER product_id");
                }

                $mv_reason = mysqli_real_escape_string($conn, $reason ?: ('Product update'));
                if ($stock_change !== 0) {
                    $ins_mv = "INSERT INTO inventory_movements (product_id, variant_type, variant_value, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) ";
                    $ins_mv .= "VALUES ($product_id, NULL, NULL, $price, $stock_change, $current_stock, $new_stock, '$movement_type', '$mv_reason', {$_SESSION['user_id']})";
                    mysqli_query($conn, $ins_mv);
                }
            }
        }

        mysqli_commit($conn);
        $success = "Stock updated successfully!";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error = "Failed to update stock: " . $e->getMessage();
    }
}

// Get statistics
$total_products = mysqli_query($conn, "SELECT COUNT(*) as total FROM products");
$total_count = mysqli_fetch_assoc($total_products)['total'];

// Low stock based on total_stock
$low_stock_q = "SELECT COUNT(*) as low FROM (
    SELECT p.product_id, (CASE WHEN COUNT(v.variant_id) > 0 THEN COALESCE(SUM(v.stock_quantity),0) ELSE p.stock_quantity END) as total_stock
    FROM products p
    LEFT JOIN product_variants v ON p.product_id = v.product_id
    GROUP BY p.product_id
    HAVING total_stock <= 10 AND total_stock > 0
) t";
$low_stock = mysqli_query($conn, $low_stock_q);
$low_count = mysqli_fetch_assoc($low_stock)['low'] ?? 0;

// Out of stock
$out_stock_q = "SELECT COUNT(*) as out_of_stock FROM (
    SELECT p.product_id, (CASE WHEN COUNT(v.variant_id) > 0 THEN COALESCE(SUM(v.stock_quantity),0) ELSE p.stock_quantity END) as total_stock
    FROM products p
    LEFT JOIN product_variants v ON p.product_id = v.product_id
    GROUP BY p.product_id
    HAVING total_stock = 0
) t";
$out_of_stock = mysqli_query($conn, $out_stock_q);
$out_count = mysqli_fetch_assoc($out_of_stock)['out_of_stock'] ?? 0;


// Inventory value (Uses selling price for a total potential revenue value)
$total_value_q = "SELECT (
    COALESCE((SELECT SUM(v.price * v.stock_quantity) FROM product_variants v), 0) +
    COALESCE((SELECT SUM(p.price * p.stock_quantity) FROM products p WHERE NOT EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.product_id)), 0)
) as value";
$total_value = mysqli_query($conn, $total_value_q);
$value = mysqli_fetch_assoc($total_value)['value'] ?? 0;


// Ensure inventory_movements table exists and columns are present
$table_exists = mysqli_query($conn, "SHOW TABLES LIKE 'inventory_movements'");
if (mysqli_num_rows($table_exists) === 0) {
    $create_table_sql = @file_get_contents('../config/sql/inventory_movements.sql');
    if ($create_table_sql) mysqli_query($conn, $create_table_sql);
}

$col = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'variant_id'");
if (mysqli_num_rows($col) === 0) {
    @mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN variant_id INT NULL AFTER product_id");
}
// add variant_type and variant_value to store descriptive info at time of movement
$col_vt = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'variant_type'");
if (mysqli_num_rows($col_vt) === 0) {
    @mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN variant_type VARCHAR(100) NULL AFTER variant_id");
}
$col_vv = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'variant_value'");
if (mysqli_num_rows($col_vv) === 0) {
    @mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN variant_value VARCHAR(100) NULL AFTER variant_type");
}
$col2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
if (mysqli_num_rows($col2) === 0) {
    @mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER product_id");
}

// Prepare data for Stock Movement History table
$movements_query = "SELECT m.*, p.product_name, u.full_name AS username, 
                      COALESCE(m.variant_type, pv.variant_type) AS variant_type, 
                      COALESCE(m.variant_value, pv.variant_value) AS variant_value 
                      FROM inventory_movements m 
                      JOIN products p ON m.product_id = p.product_id 
                      LEFT JOIN product_variants pv ON m.variant_id = pv.variant_id
                      LEFT JOIN users u ON m.created_by = u.user_id 
                      /* skip the automatic product-level update that just mirrors variant changes */
                      WHERE m.reason NOT LIKE 'Product stock updated from variants' 
                      ORDER BY m.created_at DESC LIMIT 20";
$movements = mysqli_query($conn, $movements_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - UniNeeds Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <button class="btn btn-link d-md-none" id="sidebarToggle">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h2>Inventory Management</h2>
            <div class="ms-auto d-flex gap-2">
                </div>
        </div>
        
        <div class="content-area">
            <?php if (isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
           

            <div class="card mt-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Stock Movements</h5>
                    <div>
                        </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm table-borderless mb-0" style="border:1px solid #dee2e6;">
                            <thead class="table-light">
                                <tr>
                                    <th>Date/Time</th>
                                    <th>Product</th>
                                    <th>Variant</th>
                                    <th>Change</th>
                                    <th>Previous</th>
                                    <th>New Stock</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if (mysqli_num_rows($movements) > 0):
                                    while ($movement = mysqli_fetch_assoc($movements)):
                                        $change_class = $movement['quantity_change'] > 0 ? 'text-success' : ($movement['quantity_change'] < 0 ? 'text-danger' : 'text-warning');
                                        $change_icon = $movement['quantity_change'] > 0 ? 'plus' : ($movement['quantity_change'] < 0 ? 'dash' : 'arrow-left-right');
                                        
                                ?>
                                    <tr>
                                        <td><?php echo date('M j, Y g:i A', strtotime($movement['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($movement['product_name']); ?></td>
                                        <td><?php
                                            // human-readable variant description
                                            $variantDesc = '';
                                            $vt = $movement['variant_type'];
                                            $vv = $movement['variant_value'];
                                            // fallback parse from reason if vt/vv not stored
                                            if (empty($vt) && empty($vv) && !empty($movement['reason'])) {
                                                if (preg_match('/^Variant\s+([^=]+)=([^\s]+)/', $movement['reason'], $m)) {
                                                    $vt = $m[1];
                                                    $vv = $m[2];
                                                }
                                            }

                                            if (!empty($vt)) {
                                                $variantDesc = $vt;
                                                if (!empty($vv)) {
                                                    $variantDesc .= ': ' . $vv;
                                                }
                                            } elseif (!empty($vv)) {
                                                $variantDesc = $vv;
                                            }
                                            if ($variantDesc === '') {
                                                $variantDesc = '-';
                                            }
                                            echo htmlspecialchars($variantDesc);
                                        ?></td>
                                        <td><?php echo $movement['quantity_change'] > 0 ? '+' : ''; echo $movement['quantity_change']; ?></td>
                                        <td><?php echo $movement['previous_quantity']; ?></td>
                                        <td><?php echo $movement['new_quantity']; ?></td>
                                    </tr>
                                <?php 
                                    endwhile;
                                else:
                                ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-state">
                                                <i class="bi bi-clock-history"></i>
                                                <h5>No Stock Movements Yet</h5>
                                                <p>Stock movements will be recorded when you update product quantities.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script>
        function loadVariants() {
            const productSelect = document.getElementById('productSelect');
            const variantSelect = document.getElementById('variantSelect');
            const variantContainer = document.getElementById('variantSelectContainer');
            const stockInfo = document.getElementById('stockInfo');
            const selectedOption = productSelect.options[productSelect.selectedIndex];
            
            variantSelect.innerHTML = '<option value="">-- choose variant --</option>'; 
            stockInfo.innerHTML = 'Select a product to see current stock information.'; 

            if (!selectedOption || selectedOption.value === "") return;

            const hasVariants = selectedOption.getAttribute('data-has-variants') === '1';
            const baseStock = selectedOption.getAttribute('data-base-stock');
            const basePrice = selectedOption.getAttribute('data-base-price');

            if (hasVariants) {
                variantContainer.style.display = 'block';
                const variantsRaw = selectedOption.getAttribute('data-variants');
                if (variantsRaw) {
                    const variants = variantsRaw.split('||');
                    variants.forEach(variantString => {
                        const parts = variantString.split('::');
                        if (parts.length === 5) {
                            const [id, type, value, stock, price] = parts;
                            const option = document.createElement('option');
                            option.value = id;
                            option.textContent = `${type}: ${value} (Stock: ${stock}) - Price: ${formatCurrency(price)}`;
                            option.setAttribute('data-stock', stock);
                            option.setAttribute('data-price', price);
                            variantSelect.appendChild(option);
                        }
                    });
                }
                stockInfo.innerHTML = 'Select a **variant** to see its current stock and selling price.';
            } else {
                variantContainer.style.display = 'none';
                stockInfo.innerHTML = `**Current Stock:** ${baseStock}. **Selling Price:** ${formatCurrency(basePrice)}.`;
            }
        }

        document.getElementById('variantSelect').addEventListener('change', () => {
            const variantSelect = document.getElementById('variantSelect');
            const stockInfo = document.getElementById('stockInfo');
            const selectedVariant = variantSelect.options[variantSelect.selectedIndex];
            
            if (selectedVariant && selectedVariant.value) {
                const stock = selectedVariant.getAttribute('data-stock');
                const price = selectedVariant.getAttribute('data-price');
                stockInfo.innerHTML = `**Current Stock:** ${stock}. **Selling Price:** ${formatCurrency(price)}.`;
            } else {
                loadVariants(); // Revert to product info if variant is unselected
            }
        });

        function formatCurrency(amount) {
            return '₱' + parseFloat(amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('productSelect').addEventListener('change', loadVariants);
            
            // Initial call to set up the form state correctly
            loadVariants(); 
        });
    </script>
</body>
</html>
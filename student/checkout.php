<?php
// Checkout - Place Order & Deduct Stock
require_once '../config/database.php';
requireStudent();

function ensureInventoryMovementsTableExists($conn) {
    $table_exists = mysqli_query($conn, "SHOW TABLES LIKE 'inventory_movements'");
    if (mysqli_num_rows($table_exists) === 0) {
        $create = "CREATE TABLE IF NOT EXISTS inventory_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            variant_id INT NULL,
            price_at_movement DECIMAL(10,2) NULL,
            quantity_change INT NOT NULL,
            previous_quantity INT NOT NULL,
            new_quantity INT NOT NULL,
            movement_type VARCHAR(32) NOT NULL,
            reason TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        mysqli_query($conn, $create);
    }
} 

// Redirect if cart empty
if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    header('Location: cart.php');
    exit();
}

// Handle selected items - for POST requests use POST data, for GET use session
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // POST request - selected items are expected from the cart form checkbox inputs.
    // If they are missing (e.g. browser didn't send them), fall back to using all cart items.
    $selected_keys = $_POST['selected_items'] ?? [];
    if (empty($selected_keys)) {
        $selected_keys = array_keys($_SESSION['cart']);
    }
} else {
    // GET request - use all items in cart as selected
    $selected_keys = array_keys($_SESSION['cart']);
}


$cart_items = array_intersect_key($_SESSION['cart'], array_flip($selected_keys));

// Redirect if no valid items selected
if (empty($cart_items)) {
    header('Location: cart.php?error=select_items');
    exit();
}

// Fetch latest product images for cart items
foreach ($selected_keys as $key) {
    if (isset($_SESSION['cart'][$key])) {
        $p_id = intval($_SESSION['cart'][$key]['product_id']);
        $p_q = mysqli_query($conn, "SELECT image_path, image_url FROM products WHERE product_id = $p_id LIMIT 1");
        if ($p_q && $prow = mysqli_fetch_assoc($p_q)) {
            // Always use database values as source of truth
            $_SESSION['cart'][$key]['image_path'] = $prow['image_path'] ?: null;
            $_SESSION['cart'][$key]['image_url'] = $prow['image_url'] ?: null;
            // Update cart_items with the latest image
            $cart_items[$key]['image_path'] = $_SESSION['cart'][$key]['image_path'];
            $cart_items[$key]['image_url'] = $_SESSION['cart'][$key]['image_url'];
        }
    }
}

$user_id = $_SESSION['user_id'];

// Get user details
$query = "SELECT * FROM users WHERE user_id = $user_id";
$result = mysqli_query($conn, $query);
$user = mysqli_fetch_assoc($result);

// Calculate cart summary
$subtotal = 0;
$total_items = 0;

// NEW LOGIC: Check if any item in the cart requires a down payment
$is_down_payment_required_for_cart = false;
foreach ($cart_items as $item) {
    $subtotal += $item['price'] * $item['quantity'];
    $total_items += $item['quantity'];
    
    // Check item requirement
    if (isset($item['requires_down_payment']) && $item['requires_down_payment']) {
        $is_down_payment_required_for_cart = true;
    }
}

$total = $subtotal;
$down_payment_rate = DOWN_PAYMENT_PERCENTAGE; 
$down_payment_amount = round($total * $down_payment_rate, 2);
$remaining_balance = $total - $down_payment_amount;


// Process order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    // Ensure inventory movements table exists before logging sales
    ensureInventoryMovementsTableExists($conn);
    
    
    // SERVER-SIDE VALIDATION: Ensure cart quantities do not exceed current stock (skip for pre-order items)
    foreach ($cart_items as $item) {
        $p_id = intval($item['product_id']);
        $qty = intval($item['quantity']);
        $variant_id = isset($item['variant_id']) ? intval($item['variant_id']) : null;

        // Get product is_preorder flag
        $prod_check = mysqli_query($conn, "SELECT is_preorder FROM products WHERE product_id = $p_id LIMIT 1");
        $prod_row_check = $prod_check ? mysqli_fetch_assoc($prod_check) : null;
        $is_preorder = !empty($prod_row_check['is_preorder']) && $prod_row_check['is_preorder'] == 1;

        // Skip stock validation for pre-order products
        if ($is_preorder) {
            continue;
        }

        if ($variant_id) {
            $v_q = mysqli_query($conn, "SELECT stock_quantity FROM product_variants WHERE variant_id = $variant_id AND product_id = $p_id LIMIT 1");
            if ($v_q && mysqli_num_rows($v_q) > 0) {
                $vrow = mysqli_fetch_assoc($v_q);
                if ($qty > intval($vrow['stock_quantity'])) {
                    $error = "Not enough stock for " . htmlspecialchars($item['product_name']) . ". Please reduce quantity in your cart.";
                    break;
                }
            }
        } else {
            $p_q = mysqli_query($conn, "SELECT stock_quantity FROM products WHERE product_id = $p_id LIMIT 1");
            $prow = $p_q ? mysqli_fetch_assoc($p_q) : null;
            if ($qty > intval($prow['stock_quantity'])) {
                $error = "Not enough stock for " . htmlspecialchars($item['product_name']) . ". Please reduce quantity in your cart.";
                break;
            }
        }
    }

    if (!empty($error)) {
        // Error will display below
    }

    // we only support cash payments collected in person; everything uses cash_on_pickup
    $payment_method = 'cash_on_pickup';
    // $payment_option is no longer relevant (all payments handled offline)

    if (empty($error)) {
        // Proceed with Database Transaction
        mysqli_begin_transaction($conn);

        // Determine order and payment statuses
        // if any cart item requires a down payment the order should be marked
        // pending_payment (To Pay); otherwise set it to pending (paid/processing)
        $order_status = $is_down_payment_required_for_cart ? 'pending_payment' : 'pending';
        $payment_status = 'unpaid';

            // 1. Create Order record
            // set due_date for downpayment window (7 days) when order is pending_payment
            if ($order_status === 'pending_payment') {
                $due_date_val = date('Y-m-d H:i:s', strtotime('+7 days'));
                $q_order = "INSERT INTO orders (user_id, total_amount, payment_method, order_status, due_date) VALUES ({$_SESSION['user_id']}, $total, '$payment_method', '$order_status', '$due_date_val')";
            } else {
                $q_order = "INSERT INTO orders (user_id, total_amount, payment_method, order_status) VALUES ({$_SESSION['user_id']}, $total, '$payment_method', '$order_status')";
            }
            mysqli_query($conn, $q_order);
            $order_id = mysqli_insert_id($conn);

                // 2. Process Items and Stock

                foreach ($cart_items as $cart_key => $item) {
                    $p_id = $item['product_id'];
                    $qty = $item['quantity'];
                    $price = $item['price'];

                    // Resolve variant_id from variants array when variant_id is not provided
                    $variant_id = isset($item['variant_id']) ? intval($item['variant_id']) : null;
                    if (empty($variant_id) && !empty($item['variants']) && is_array($item['variants'])) {
                        foreach ($item['variants'] as $vt => $vv) {
                            $vt_esc = mysqli_real_escape_string($conn, $vt);
                            $vv_esc = mysqli_real_escape_string($conn, $vv);
                            $vid_q = mysqli_query($conn, "SELECT variant_id FROM product_variants WHERE product_id = $p_id AND variant_type = '$vt_esc' AND variant_value = '$vv_esc' LIMIT 1");
                            if ($vid_q && mysqli_num_rows($vid_q) > 0) {
                                $variant_id = intval(mysqli_fetch_assoc($vid_q)['variant_id']);
                                break;
                            }
                        }
                    }

                    // Insert Item with variants (include resolved variant_id when available)
                    if (!empty($variant_id)) {
                        $res = mysqli_query($conn, "INSERT INTO order_items (order_id, product_id, variant_id, quantity, price) VALUES ($order_id, $p_id, $variant_id, $qty, $price)");
                    } else {
                        $res = mysqli_query($conn, "INSERT INTO order_items (order_id, product_id, quantity, price) VALUES ($order_id, $p_id, $qty, $price)");
                    }

                    if (!$res) {
                        $errorMsg = mysqli_error($conn);
                        error_log('[order-debug] Failed to insert order item for order ' . $order_id . ' (key=' . $cart_key . '): ' . $errorMsg);
                        // Continue attempting to insert other items rather than aborting the whole order
                        continue;
                    }

                    // Deduct Stock only for stocked items (skip for pre-order/made-to-order)
                    $prod_check = mysqli_query($conn, "SELECT is_preorder, price FROM products WHERE product_id = $p_id LIMIT 1");
                    $prod_row = $prod_check ? mysqli_fetch_assoc($prod_check) : null;
                    if (empty($prod_row['is_preorder']) || $prod_row['is_preorder'] == 0) {
                        if ($variant_id) {
                            $v_q = mysqli_query($conn, "SELECT stock_quantity, price, variant_type, variant_value FROM product_variants WHERE variant_id = $variant_id AND product_id = $p_id LIMIT 1");
                            if ($v_q && mysqli_num_rows($v_q) > 0) {
                                $vrow = mysqli_fetch_assoc($v_q);
                                $current_stock = intval($vrow['stock_quantity']);
                                $price_at_movement = floatval($vrow['price']);
                                $new_stock = $current_stock - $qty;
                                if ($new_stock < 0) $new_stock = 0;

                                // Update variant stock
                                mysqli_query($conn, "UPDATE product_variants SET stock_quantity = $new_stock WHERE variant_id = $variant_id");
                                mysqli_query($conn, "UPDATE products SET stock_quantity = stock_quantity - $qty WHERE product_id = $p_id");

                                // Ensure inventory_movements columns exist (variant_id, price_at_movement)
                                $col_check = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'variant_id'");
                                if (mysqli_num_rows($col_check) === 0) {
                                    mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN variant_id INT NULL AFTER product_id");
                                }
                                $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
                                if (mysqli_num_rows($col_check2) === 0) {
                                    mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER variant_id");
                                }

                                $stock_change = $new_stock - $current_stock; // negative
                                $mv_reason = mysqli_real_escape_string($conn, "Sale - Order #" . $order_id . " - " . ($user['full_name'] ?? 'Customer'));
                                $vt = mysqli_real_escape_string($conn, $vrow['variant_type']);
                                $vv = mysqli_real_escape_string($conn, $vrow['variant_value']);
                                $ins_mv = "INSERT INTO inventory_movements (product_id, variant_id, variant_type, variant_value, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) ";
                                $ins_mv .= "VALUES ($p_id, $variant_id, '$vt', '$vv', $price_at_movement, $stock_change, $current_stock, $new_stock, 'sale', '$mv_reason', {$_SESSION['user_id']})";
                                mysqli_query($conn, $ins_mv);

                                // Notify admin if this variant just went out of stock
                                if ($current_stock > 0 && $new_stock == 0) {
                                    $pname = mysqli_real_escape_string($conn, $item['product_name']);
                                    $vvq = mysqli_query($conn, "SELECT variant_type, variant_value FROM product_variants WHERE variant_id = $variant_id LIMIT 1");
                                    $vtext = '';
                                    if ($vvq && mysqli_num_rows($vvq) > 0) {
                                        $vrow2 = mysqli_fetch_assoc($vvq);
                                        $vtext = ' (' . mysqli_real_escape_string($conn, $vrow2['variant_type'] . ': ' . $vrow2['variant_value']) . ')';
                                    }
                                    $note = "Variant out of stock: " . $pname . $vtext;
                                    mysqli_query($conn, "INSERT INTO notifications (user_id, message, type, is_read) VALUES (1, '" . mysqli_real_escape_string($conn, $note) . "', 'stock', 0)");
                                }
                            }
                        } else {
                            $current_q = mysqli_query($conn, "SELECT stock_quantity, price FROM products WHERE product_id = $p_id LIMIT 1");
                            $prow = $current_q ? mysqli_fetch_assoc($current_q) : null;
                            $current_stock = intval($prow['stock_quantity']);
                            $price_at_movement = floatval($prow['price'] ?? $price);
                            $new_stock = $current_stock - $qty;
                            if ($new_stock < 0) $new_stock = 0;

                            mysqli_query($conn, "UPDATE products SET stock_quantity = $new_stock WHERE product_id = $p_id");

                            $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
                            if (mysqli_num_rows($col_check2) === 0) {
                                mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER product_id");
                            }

                            $stock_change = $new_stock - $current_stock; // negative
                            $mv_reason = mysqli_real_escape_string($conn, "Sale - Order #" . $order_id . " - " . ($user['full_name'] ?? 'Customer'));
                            $ins_mv = "INSERT INTO inventory_movements (product_id, variant_type, variant_value, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) ";
                            $ins_mv .= "VALUES ($p_id, NULL, NULL, $price_at_movement, $stock_change, $current_stock, $new_stock, 'sale', '$mv_reason', {$_SESSION['user_id']})";
                            mysqli_query($conn, $ins_mv);

                            // Notify admin if this product just went out of stock
                            if ($current_stock > 0 && $new_stock == 0) {
                                $pname = mysqli_real_escape_string($conn, $item['product_name']);
                                $note = "Product out of stock: " . $pname;
                                mysqli_query($conn, "INSERT INTO notifications (user_id, message, type, is_read) VALUES (1, '" . mysqli_real_escape_string($conn, $note) . "', 'stock', 0)");
                            }
                        }
                    }
                }

            // Verify all items were recorded properly
            $inserted_count = 0;
            $count_res = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM order_items WHERE order_id = $order_id");
            if ($count_res && $count_row = mysqli_fetch_assoc($count_res)) {
                $inserted_count = intval($count_row['cnt']);
            }
            if ($inserted_count !== count($cart_items)) {
                $mismatchMessage = '[order-debug] Order #' . $order_id . ' expected ' . count($cart_items) . ' items, inserted ' . $inserted_count . '. Cart: ' . json_encode($cart_items);
                error_log($mismatchMessage);
                // Also log to a file for easier inspection
                @file_put_contents(__DIR__ . '/../storage/logs/order_mismatch.log', date('c') . ' - ' . $mismatchMessage . "\n", FILE_APPEND);
                // Continue processing to avoid fatal errors for customers; the order will still be created.
            }

            // Update other users' carts and the current session cart to reflect new stock
            foreach ($cart_items as $item) {
                $p_id = intval($item['product_id']);
                $variant_id = isset($item['variant_id']) ? intval($item['variant_id']) : null;

                if ($variant_id) {
                    $v_q = mysqli_query($conn, "SELECT stock_quantity FROM product_variants WHERE variant_id = $variant_id LIMIT 1");
                    $vrow = $v_q ? mysqli_fetch_assoc($v_q) : null;
                    $new_stock = intval($vrow['stock_quantity'] ?? 0);

                    if ($new_stock <= 0) {
                        mysqli_query($conn, "DELETE FROM cart WHERE product_id = $p_id AND variant_id = $variant_id");
                    } else {
                        mysqli_query($conn, "UPDATE cart SET quantity = LEAST(quantity, $new_stock) WHERE product_id = $p_id AND variant_id = $variant_id");
                    }

                    // Adjust current session cart entries
                    foreach ($_SESSION['cart'] as $k => $ci) {
                        if (isset($ci['variant_id']) && intval($ci['variant_id']) === $variant_id && intval($ci['product_id']) === $p_id) {
                            if ($new_stock <= 0) {
                                unset($_SESSION['cart'][$k]);
                            } elseif ($_SESSION['cart'][$k]['quantity'] > $new_stock) {
                                $_SESSION['cart'][$k]['quantity'] = $new_stock;
                            }
                        }
                    }
                } else {
                    $p_q = mysqli_query($conn, "SELECT stock_quantity FROM products WHERE product_id = $p_id LIMIT 1");
                    $prow = $p_q ? mysqli_fetch_assoc($p_q) : null;
                    $new_stock = intval($prow['stock_quantity'] ?? 0);

                    if ($new_stock <= 0) {
                        mysqli_query($conn, "DELETE FROM cart WHERE product_id = $p_id AND (variant_id IS NULL OR variant_id = '')");
                    } else {
                        mysqli_query($conn, "UPDATE cart SET quantity = LEAST(quantity, $new_stock) WHERE product_id = $p_id AND (variant_id IS NULL OR variant_id = '')");
                    }

                    foreach ($_SESSION['cart'] as $k => $ci) {
                        if (intval($ci['product_id']) === $p_id && !isset($ci['variant_id'])) {
                            if ($new_stock <= 0) {
                                unset($_SESSION['cart'][$k]);
                            } elseif ($_SESSION['cart'][$k]['quantity'] > $new_stock) {
                                $_SESSION['cart'][$k]['quantity'] = $new_stock;
                            }
                        }
                    }
                }
            }

            // 3. Create Invoice
            // Invoice number must be unique; include microseconds to avoid duplicates if the same order is processed twice.
            $inv_num = 'INV-' . date('Ymd') . '-' . str_pad($order_id, 6, '0', STR_PAD_LEFT) . '-' . substr(uniqid('', true), -6);
            
            // For cash orders we don't collect anything online; invoice created with full amount due
            $amount_paid = 0;
            $balance_due = $total;
            
            // record initial_down_payment so we can track collections separately
            $q_inv = "INSERT INTO invoices (order_id, invoice_number, payment_status, amount_paid, balance_due, down_payment_due, remaining_balance, initial_down_payment) 
                      VALUES ($order_id, '$inv_num', '$payment_status', $amount_paid, $balance_due, $down_payment_amount, $remaining_balance, $down_payment_amount)";
            mysqli_query($conn, $q_inv);


            mysqli_commit($conn);
            foreach ($selected_keys as $key) unset($_SESSION['cart'][$key]);

            header('Location: orders.php?success=1&order_id=' . $order_id);
            exit();
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - UniNeeds</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <style>
        .qr-display-box { max-width: 220px; margin: 0 auto; border: 3px solid #28a745; border-radius: 12px; padding: 10px; background: #fff; }
        .product-img-checkout { width: 60px; height: 60px; object-fit: cover; border-radius: 8px; }
        .payment-card { border-top: 5px solid #28a745; border-radius: 15px !important; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-bar"><h2>Checkout</h2></div>
        
        <div class="content-area">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="checkoutForm">
                <input type="hidden" name="place_order" value="1">
                <!-- Hidden inputs to preserve selected items -->
                <?php if (isset($_GET['dev']) && $_GET['dev'] === '1'): ?>
                    <input type="hidden" name="dev" value="1">
                <?php endif; ?>
                <?php foreach ($selected_keys as $key): ?>
                    <input type="hidden" name="selected_items[]" value="<?php echo htmlspecialchars($key); ?>">
                <?php endforeach; ?>
                
                <div class="row g-4">
                    <div class="col-lg-8">
                        
                        <div class="card shadow-sm border-0 mb-4" style="border-radius: 15px;">
                            <div class="card-header bg-white pt-3"><h5 class="mb-0"><i class="bi bi-person me-2 text-success"></i>Customer Information</h5></div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="small text-muted">Full Name</label>
                                        <input type="text" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($user['full_name']); ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small text-muted">Student ID</label>
                                        <input type="text" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($user['student_id'] ?? 'N/A'); ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small text-muted">Email Address</label>
                                        <input type="text" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small text-muted">Phone Number</label>
                                        <input type="text" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?>" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card shadow-sm border-0" style="border-radius: 15px;">
                            <div class="card-header bg-white pt-3"><h5 class="mb-0"><i class="bi bi-cart-check me-2 text-success"></i>Order Items</h5></div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0">
                                        <thead class="bg-light text-muted small">
                                            <tr>
                                                <th class="ps-3">Product</th>
                                                <th>Price</th>
                                                <th>Qty</th>
                                                <th class="text-end pe-3">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($cart_items as $item): ?>
                                            <tr>
                                                <td class="ps-3">
                                                    <div class="d-flex align-items-center">
                                                        <?php
                                                            $img = '';
                                                            if (!empty($item['image_path'])) {
                                                                $img = $item['image_path'];
                                                            } elseif (!empty($item['image_url'])) {
                                                                $img = $item['image_url'];
                                                            }

                                                            if (!empty($img)) {
                                                                // Ensure proper path for student context
                                                                if (preg_match('/^(https?:)?\\/\\//i', $img)) {
                                                                    // External URL - use as is
                                                                } elseif (strpos($img, '/assets/') === 0) {
                                                                    // Absolute path from web root - add ../ prefix for student directory
                                                                    $img = '..' . $img;
                                                                } elseif (strpos($img, '../') !== 0 && strpos($img, '/') !== 0) {
                                                                    // Relative path - add ../ prefix
                                                                    $img = '../' . ltrim($img, '/');
                                                                }
                                                            } else {
                                                                $img = '../assets/images/avatar.png';
                                                            }
                                                        ?>
                                                        <img src="<?php echo htmlspecialchars($img); ?>" class="product-img-checkout me-3 border" alt="<?php echo htmlspecialchars($item['product_name']); ?>" onerror="this.src='../assets/images/avatar.png'">
                                                        <div>
                                                            <div class="fw-bold"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                                            <?php if (!empty($item['variants'])): ?>
                                                                <small class="text-success bg-light px-2 rounded">
                                                                    <?php foreach ($item['variants'] as $type => $val) echo ucfirst($type).": ".$val." "; ?>
                                                                </small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo formatCurrency($item['price']); ?></td>
                                                <td><?php echo $item['quantity']; ?></td>
                                                <td class="text-end pe-3 fw-bold"><?php echo formatCurrency($item['price'] * $item['quantity']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="card shadow-sm payment-card border-0 mb-4">
                            <div class="card-header bg-white pt-3"><h5 class="mb-0">Payment Summary</h5></div>
                            <div class="card-body">
                                <?php if ($is_down_payment_required_for_cart): ?>
                                    <div class="alert alert-warning">
                                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                        <strong>Down Payment Required:</strong>
                                        Please pay <?php echo formatCurrency($down_payment_amount); ?> in cash to the administration office within 7 days (due <?php echo date('M j, Y', strtotime('+7 days')); ?>). Failure to pay may result in cancellation.
                                    </div>
                                <?php else: ?>
                                    <p class="mb-3">All payments are made in cash upon claiming your order or at the administration counter.</p>
                                <?php endif; ?>

                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="small">Total Amount:</span> 
                                        <span class="small text-muted"><?php echo formatCurrency($total); ?></span>
                                    </div>
                                    <?php if ($is_down_payment_required_for_cart): ?>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="small">Downpayment Due:</span> 
                                        <span class="small text-muted"><?php echo formatCurrency($down_payment_amount); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <button type="submit" name="place_order" id="submitBtn" class="btn btn-success w-100 py-3 font-weight-bold shadow-sm" style="border-radius: 12px;">
                                    PLACE ORDER NOW
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Stock Verification Alert Modal -->
    <div class="modal fade" id="stockVerificationModal" tabindex="-1" aria-labelledby="stockVerificationModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0" style="border-radius: 12px; box-shadow: 0 10px 40px rgba(255,107,107,0.2);">
                <div class="modal-header border-0" style="background: linear-gradient(135deg, #FF6B6B 0%, #FF5252 100%); color: white; border-radius: 12px 12px 0 0; padding: 2rem;">
                    <h5 class="modal-title fw-bold" id="stockVerificationModalLabel" style="font-size: 1.3rem;">
                        <i class="bi bi-exclamation-triangle me-2"></i>Stock Issue Detected
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="mb-3">Unfortunately, the following items are no longer available in stock:</p>
                    <div id="stockIssuesList" style="max-height: 300px; overflow-y: auto;">
                        <!-- Items will be populated here -->
                    </div>
                </div>
                <div class="modal-footer border-top bg-light" style="border-radius: 0 0 12px 12px; padding: 1.5rem; background-color: #f8f9fa !important;">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal" onclick="window.location.href = 'cart.php';">
                        <i class="bi bi-arrow-left me-2"></i>Review Cart
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Non-Refundable Downpayment Modal -->
    <div class="modal fade" id="downpaymentWarningModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-warning">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title text-white"><i class="bi bi-exclamation-triangle-fill me-2"></i>Important Notice</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning mb-3" role="alert">
                        <strong>Downpayment is Non-Refundable</strong>
                    </div>
                    <p class="mb-3">
                        By selecting the downpayment option, you acknowledge that:
                    </p>
                    <ul class="mb-3">
                        <li>The downpayment of <strong id="downpaymentWarningAmount">₱0.00</strong> is <strong class="text-danger">NOT refundable</strong></li>
                        <li>The remaining balance of <strong id="remainingBalanceWarningAmount">₱0.00</strong> must be paid upon claiming your order</li>
                        <li>If you cancel your order, the downpayment will be forfeited</li>
                        <li>When your order is ready for pickup, <b> you have one (1) week to claim it </b>. Orders not claimed within this period will result in forfeiture of the down payment, which is <strong class="text-danger">non-refundable.</strong></li>
                    </ul>
                    <p class="small text-muted mb-0">
                        Please ensure you have read and understood these terms before proceeding with the downpayment.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Go Back</button>
                    <button type="button" class="btn btn-warning text-white" data-bs-dismiss="modal" id="acknowledgeDownpayment">I Understand & Accept</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // disable submit button on form submit to prevent duplicate orders
    document.getElementById('checkoutForm')?.addEventListener('submit', function() {
        const submitBtn = document.getElementById('submitBtn');
        setTimeout(function(){
            if(submitBtn){
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing Order...';
            }
        }, 10);
    });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php

require_once '../config/database.php';
requireStudent();

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Handle Remove from Cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_item'])) {
    $cart_key = clean($_POST['cart_key']);
    if (isset($_SESSION['cart'][$cart_key])) {
        unset($_SESSION['cart'][$cart_key]);
        $success = "Item removed from cart!";
    }
}

// Handle Update Quantity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quantity'])) {
    $cart_key = clean($_POST['cart_key']);
    $quantity = intval($_POST['quantity']);
    
    if (isset($_SESSION['cart'][$cart_key]) && $quantity > 0) {
        $_SESSION['cart'][$cart_key]['quantity'] = $quantity;
        // Don't show success message for quantity updates - it's too frequent
        // Just redirect to avoid form resubmission
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Handle Clear Cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_cart'])) {
    $_SESSION['cart'] = [];
    $success = "Cart cleared!";
}

// Validate current session cart against latest stock and calculate totals
// Also fetch latest product images
$cart_items = &$_SESSION['cart'];
$subtotal = 0;
$total_items = count($cart_items); // Count unique items, not quantities
$checkout_disabled = false;
$cart_notifications = [];
$removed_items_oos = []; // Track items removed due to out of stock
$adjusted_items = []; // Track items with adjusted quantities

foreach ($cart_items as $key => $item) {
    $p_id = intval($item['product_id']);
    $variant_id = isset($item['variant_id']) ? intval($item['variant_id']) : null;
    $available = 0;

    // Fetch latest product info including image and preorder flag
    $p_q = mysqli_query($conn, "SELECT image_path, image_url, stock_quantity, is_preorder FROM products WHERE product_id = $p_id LIMIT 1");
    $prow = $p_q ? mysqli_fetch_assoc($p_q) : null;
    
    // Update cart item with latest image
    if ($prow) {
        // Always use database values as source of truth
        $_SESSION['cart'][$key]['image_path'] = $prow['image_path'] ?: null;
        $_SESSION['cart'][$key]['image_url'] = $prow['image_url'] ?: null;
    }

    if ($variant_id) {
        $v_q = mysqli_query($conn, "SELECT stock_quantity FROM product_variants WHERE variant_id = $variant_id LIMIT 1");
        if ($v_q && mysqli_num_rows($v_q) > 0) {
            $vrow = mysqli_fetch_assoc($v_q);
            $available = intval($vrow['stock_quantity']);
        }
    } else {
        $available = intval($prow['stock_quantity'] ?? 0);
    }

    // If product is marked as pre-order/made-to-order, do not remove or reduce quantity based on stock
    $is_preorder = !empty($prow['is_preorder']) && $prow['is_preorder'] == 1;

    if ($available <= 0 && !$is_preorder) {
        unset($_SESSION['cart'][$key]);
        $cart_notifications[] = htmlspecialchars($item['product_name']) . " was removed from your cart because it is out of stock.";
        $removed_items_oos[] = [
            'product_name' => $item['product_name'],
            'quantity' => $item['quantity'],
            'price' => $item['price'] * $item['quantity']
        ];
        $checkout_disabled = true;
        $total_items = count($cart_items); // Recount after removal
        continue;
    }

    if (!$is_preorder && $item['quantity'] > $available) {
        $_SESSION['cart'][$key]['quantity'] = $available;
        $cart_notifications[] = htmlspecialchars($item['product_name']) . " quantity reduced to available stock ({$available}).";
        $adjusted_items[] = [
            'product_name' => $item['product_name'],
            'original_quantity' => $item['quantity'],
            'adjusted_quantity' => $available,
            'price' => $item['price']
        ];
        $checkout_disabled = true;
    }

    $subtotal += $item['price'] * $_SESSION['cart'][$key]['quantity'];
}

$total = $subtotal; // No tax or shipping for now
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - UniNeeds</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <link rel="stylesheet" href="../assets/css/cart.css">
</head>
<body class="cart-page">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <button class="btn btn-link d-md-none" id="sidebarToggle">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h2>Shopping Cart</h2>
            <div class="ms-auto">
                <!-- <a href="products.php" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-left me-2"></i>Continue Shopping
                </a> -->
            </div>
        </div>
        
        <div class="content-area">
            <?php if (isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error']) && $_GET['error'] === 'select_items'): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i>Please select at least one item to proceed with checkout.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($cart_notifications)): ?>
                <?php foreach ($cart_notifications as $note): ?>
                    <div class="alert alert-warning alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle me-2"></i><?php echo $note; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if (!empty($cart_items)): ?>
                <form method="POST" action="checkout.php" id="checkout-form">
                <div class="row g-4">
                    <!-- Cart Items -->
                    <div class="col-md-8">
                        <div class="card" style="border: none; box-shadow: 0 2px 12px rgba(0,0,0,0.08); border-radius: 12px;">
                            <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 12px 12px 0 0; border: none; padding: 1.5rem;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0 fw-bold" style="font-size: 1.2rem;">
                                        <i class="bi bi-bag-check me-2"></i>Cart Items (<?php echo $total_items; ?>)
                                    </h5>
                                    <button type="button" class="btn btn-sm" style="background-color: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); font-weight: 600; transition: all 0.3s ease;" onclick="clearCart()" onmouseover="this.style.backgroundColor='rgba(255,255,255,0.3)'" onmouseout="this.style.backgroundColor='rgba(255,255,255,0.2)'">
                                        <i class="bi bi-trash me-1"></i>Clear Cart
                                    </button>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <?php foreach ($cart_items as $key => $item): ?>
                                    <div class="cart-item" style="border-bottom: 1px solid #f0f0f0; padding: 1.5rem; transition: all 0.3s ease;" onmouseover="this.style.backgroundColor='#f9f9f9'" onmouseout="this.style.backgroundColor='white'">
                                        <div class="row align-items-center">
                                            <div class="col-auto">
                                                <input type="checkbox" name="selected_items[]" value="<?php echo htmlspecialchars($key); ?>" class="form-check-input cart-checkbox" checked data-price="<?php echo $item['price']; ?>" data-quantity="<?php echo $item['quantity']; ?>" style="width: 20px; height: 20px; cursor: pointer;">
                                            </div>
                                            <div class="col-md-2">
                                                <?php if ($item['image_path'] || $item['image_url']): ?>
                                                    <?php
                                                        $cartImg = $item['image_path'] ?? $item['image_url'];
                                                        // Ensure proper path for student context
                                                        if (preg_match('/^(https?:)?\\/\\//i', $cartImg)) {
                                                            // External URL or protocol-relative - use as is
                                                        } elseif (strpos($cartImg, '/assets/') === 0) {
                                                            // Absolute path from web root - add ../ prefix for student directory
                                                            $cartImg = '..' . $cartImg;
                                                        } elseif (strpos($cartImg, '../') !== 0 && strpos($cartImg, '/') !== 0) {
                                                            // Relative path - add ../ prefix
                                                            $cartImg = '../' . $cartImg;
                                                        }
                                                    ?>
                                                    <img src="<?php echo htmlspecialchars($cartImg); ?>" alt="Product" class="img-fluid rounded" style="max-height: 90px; object-fit: cover; box-shadow: 0 2px 8px rgba(0,0,0,0.1);" onerror="this.parentElement.style.display='none'; this.parentElement.nextElementSibling?.classList?.remove('d-none');">
                                                <?php else: ?>
                                                    <div class="bg-light rounded d-flex align-items-center justify-content-center" style="height: 90px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                                        <i class="bi bi-image text-muted fs-2"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-4">
                                                <h6 class="mb-2 fw-bold" style="color: #2c3e50; font-size: 1rem;"><?php echo htmlspecialchars($item['product_name']); ?></h6>
                                                <?php if (!empty($item['variants'])): ?>
                                                    <small class="text-muted d-block mb-2" style="line-height: 1.6;">
                                                        <?php foreach ($item['variants'] as $type => $value): ?>
                                                            <span style="background: #f0f0f0; padding: 0.25rem 0.75rem; border-radius: 4px; display: inline-block; margin-right: 0.5rem; margin-bottom: 0.25rem;">
                                                                <strong><?php echo htmlspecialchars(ucfirst($type)); ?></strong>: <?php echo htmlspecialchars($value); ?>
                                                            </span><br>
                                                        <?php endforeach; ?>
                                                    </small>
                                                <?php endif; ?>
                                                <p class="text-success fw-bold mb-0" style="font-size: 1.1rem;">₱<?php echo number_format($item['price'], 2); ?></p>
                                            </div>
                                            <div class="col-md-3">
                                                <input type="number" name="quantity" value="<?php echo $item['quantity']; ?>" min="1" max="<?php echo max(1, $is_preorder ? 9999 : $available); ?>" class="form-control form-control-sm text-center fw-bold quantity-input" style="width: 80px; border-radius: 6px; height: 38px;" data-cart-key="<?php echo htmlspecialchars($key); ?>" onchange="updateQuantity(this)">
                                            </div>
                                            <div class="col-md-2 text-end">
                                                <p class="fw-bold mb-2" style="font-size: 1.1rem; color: #2c3e50;">₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></p>
                                                <button type="button" class="btn btn-sm" style="background-color: #FF6B6B; color: white; border: none; border-radius: 6px; padding: 0.5rem 0.75rem; font-weight: 600; transition: all 0.3s ease;" onclick="removeItem(this, '<?php echo htmlspecialchars($key); ?>')" onmouseover="this.style.backgroundColor='#FF5252'" onmouseout="this.style.backgroundColor='#FF6B6B'">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Order Summary -->
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Order Summary</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Subtotal (<span id="subtotal-items">0</span> items)</span>
                                    <span id="subtotal-amount">₱0.00</span>
                                </div>
                                
                                <hr>
                                <div class="d-flex justify-content-between mb-3">
                                    <strong>Total</strong>
                                    <strong class="text-primary fs-4" id="total-amount">₱0.00</strong>
                                </div>
                                
                                <button type="submit" class="btn btn-success btn-lg w-100 mb-2" <?php echo (!empty($checkout_disabled) ? 'disabled' : ''); ?>>
                                    <i class="bi bi-cart-check me-2"></i>Proceed to Checkout
                                </button>
                                <?php if (!empty($checkout_disabled)): ?>
                                    <div class="small text-muted mt-2">Some items were adjusted or removed due to stock changes. Please review your cart before proceeding.</div>
                                <?php endif; ?>
                                
                                <a href="products.php" class="btn btn-outline-secondary w-100">
                                    <i class="bi bi-arrow-left me-2"></i>Continue Shopping
                                </a>
                            </div>
                        </div>
                        
                        <!-- Info Card -->
                        <div class="card mt-3">
                            <div class="card-body">
                                <h6 class="card-title"><i class="bi bi-shield-check me-2 text-success"></i>Safe & Secure</h6>
                                <ul class="list-unstyled small mb-0">
                                    <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Pay cash on pickup</li>
                                    <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Order tracking</li>
                                    <li class="mb-0"><i class="bi bi-check-circle text-success me-2"></i>Customer support</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                </form>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-cart-x"></i>
                    <h5>Your Cart is Empty</h5>
                    <p>Looks like you haven't added anything to your cart yet.</p>
                    <!-- <a href="products.php" class="btn btn-primary">
                        <i class="bi bi-shop me-2"></i>Start Shopping
                    </a> -->
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    
    <!-- Out of Stock Items Modal -->
    <div class="modal fade" id="outOfStockModal" tabindex="-1" aria-labelledby="outOfStockModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0" style="border-radius: 12px; box-shadow: 0 10px 40px rgba(255,107,107,0.2);">
                <div class="modal-header border-0" style="background: linear-gradient(135deg, #FF6B6B 0%, #FF5252 100%); color: white; border-radius: 12px 12px 0 0; padding: 2rem;">
                    <div>
                        <h5 class="modal-title fw-bold" id="outOfStockModalLabel" style="font-size: 1.3rem;">
                            <i class="bi bi-exclamation-triangle me-2"></i>Items Out of Stock
                        </h5>
                        <p class="mb-0 mt-2 small" style="opacity: 0.9;">These items were removed from your cart because they ran out of stock:</p>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div id="outOfStockList" style="max-height: 400px; overflow-y: auto;">
                        <!-- Items will be populated here by JavaScript -->
                    </div>
                </div>
                <div class="modal-footer border-top bg-light" style="border-radius: 0 0 12px 12px; padding: 1.5rem; background-color: #f8f9fa !important;">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal" style="border-radius: 6px; padding: 0.75rem 2rem; font-weight: 600;">
                        <i class="bi bi-check-circle me-2"></i>Got It
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script>
        // Show out of stock modal if there are items removed
        <?php if (!empty($removed_items_oos)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const outOfStockModal = new bootstrap.Modal(document.getElementById('outOfStockModal'), {
                    keyboard: false,
                    backdrop: 'static'
                });
                
                // Populate the list
                const outOfStockList = document.getElementById('outOfStockList');
                outOfStockList.innerHTML = `
                    <?php foreach ($removed_items_oos as $item): ?>
                    <div class="p-3" style="border-bottom: 1px solid #f0f0f0; transition: background-color 0.2s;">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-2 fw-bold" style="color: #2c3e50;"><?php echo htmlspecialchars($item['product_name']); ?></h6>
                                <small class="text-muted d-block">Quantity: <strong><?php echo intval($item['quantity']); ?></strong></small>
                            </div>
                            <span class="badge bg-danger" style="border-radius: 4px; padding: 0.5rem 0.75rem; font-size: 0.85rem;">
                                <i class="bi bi-x-circle me-1"></i>Out of Stock
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                `;
                
                outOfStockModal.show();
            });
        <?php endif; ?>
        
        // Update total and subtotal when checkboxes change
        function updateTotal() {
            let total = 0;
            let itemCount = 0;
            document.querySelectorAll('.cart-checkbox:checked').forEach(cb => {
                const price = parseFloat(cb.getAttribute('data-price'));
                const quantity = parseInt(cb.getAttribute('data-quantity'));
                total += price * quantity;
                itemCount += 1; // Count unique items, not quantities
            });
            document.getElementById('total-amount').textContent = '₱' + total.toFixed(2);
            document.getElementById('subtotal-amount').textContent = '₱' + total.toFixed(2);
            document.getElementById('subtotal-items').textContent = itemCount;
        }

        document.querySelectorAll('.cart-checkbox').forEach(cb => {
            cb.addEventListener('change', updateTotal);
        });

        // Handle quantity input changes (native up/down spinner + typing)
        document.querySelectorAll('.quantity-input').forEach(input => {
            input.addEventListener('change', function() {
                const val = parseInt(this.value) || 1;
                updateQuantity(this, val);
            });
        });

        // Update quantity via AJAX
        function updateQuantity(input, quantity = null) {
            const val = quantity !== null ? quantity : (parseInt(input.value) || 1);
            const cartKey = input.getAttribute('data-cart-key');
            
            // Ensure the quantity is within allowed bounds
            const max = parseInt(input.max) || 9999;
            const clamped = Math.max(1, Math.min(max, val));
            if (clamped !== val) {
                val = clamped;
                input.value = val;
            }

            // Update the checkbox's data-quantity attribute immediately
            const checkbox = document.querySelector(`.cart-checkbox[value="${cartKey}"]`);
            if (checkbox) {
                const price = parseFloat(checkbox.getAttribute('data-price'));
                checkbox.setAttribute('data-quantity', val);

                // Update the product line total display
                const cartItem = input.closest('.cart-item');
                const totalPriceElement = cartItem.querySelector('.col-md-2.text-end p:first-child');
                if (totalPriceElement) {
                    const total = (price * val).toFixed(2);
                    totalPriceElement.textContent = '₱' + parseFloat(total).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
            }
            
            // Recalculate overall total
            updateTotal();
            
            // Send AJAX request to backend
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'update_quantity=1&cart_key=' + encodeURIComponent(cartKey) + '&quantity=' + val
            })
            .catch(error => console.error('Error:', error));
        }

        // Remove item via AJAX
        function removeItem(btn, cartKey) {
            if (!confirm('Remove this item from cart?')) {
                return;
            }
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'remove_item=1&cart_key=' + encodeURIComponent(cartKey)
            })
            .then(response => response.text())
            .then(data => {
                // Reload the page to update cart display
                window.location.reload();
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to remove item');
            });
        }

        // Clear cart via AJAX
        function clearCart() {
            if (!confirm('Clear all items from cart?')) {
                return;
            }
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'clear_cart=1'
            })
            .then(response => response.text())
            .then(data => {
                // Reload the page to update cart display
                window.location.reload();
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to clear cart');
            });
        }

        // Initial update
        updateTotal();

        // Form validation - ensure at least one item is selected
        document.getElementById('checkout-form').addEventListener('submit', function(e) {
            const checkboxes = document.querySelectorAll('input[name="selected_items[]"]:checked');
            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one item to continue with checkout.');
                return false;
            }
        });
    </script>
</body>
</html>
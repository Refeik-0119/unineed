<?php

require_once '../config/database.php';
requireStudent();

// Get active orders (not completed or cancelled)
$user_id = $_SESSION['user_id'];

// helper to find out if there are any payments recorded for an order
function orderHasPayments($conn, $order_id) {
    // invoice based payment
    $q = mysqli_query($conn, "SELECT 1 FROM invoices WHERE order_id = $order_id AND amount_paid > 0 LIMIT 1");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    // optional payments table
    $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'payments'");
    if ($tbl && mysqli_num_rows($tbl) > 0) {
        $pq = mysqli_query($conn, "SELECT 1 FROM payments WHERE order_id = $order_id LIMIT 1");
        if ($pq && mysqli_num_rows($pq) > 0) {
            return true;
        }
    }
    return false;
}

function orderHasPreorderItems($conn, $order_id) {
    $q = mysqli_query($conn, "SELECT 1 FROM order_items oi JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = $order_id AND p.is_preorder = 1 LIMIT 1");
    return $q && mysqli_num_rows($q) > 0;
}

// Check for success message
$success = isset($_GET['success']) && $_GET['success'] == 1;
$new_order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;


// Get active orders (not completed or cancelled)
// include invoice data so we can check payment status when deciding if cancellation is allowed
// Join only the latest invoice per order to avoid duplicate rows when multiple invoices exist
$query = "SELECT o.*, i.payment_status, i.amount_paid, o.created_at AS order_date
          FROM orders o
          LEFT JOIN (
              SELECT inv.*
              FROM invoices inv
              JOIN (
                  SELECT order_id, MAX(invoice_id) AS max_invoice_id
                  FROM invoices
                  GROUP BY order_id
              ) latest ON inv.order_id = latest.order_id AND inv.invoice_id = latest.max_invoice_id
          ) i ON o.order_id = i.order_id
          WHERE o.user_id = $user_id
          AND o.order_status NOT IN ('completed', 'cancelled')
          ORDER BY o.created_at DESC";
$orders = mysqli_query($conn, $query);

// If specific order ID is provided, get order details
$order_details = null;
if (isset($_GET['id'])) {
    $order_id = intval($_GET['id']);
    // MODIFIED QUERY: Include payment_proof_path and amount tracking from invoices
    $query = "SELECT o.*, i.invoice_number, i.payment_status, i.down_payment_due, i.remaining_balance, i.amount_paid, i.balance_due 
              FROM orders o 
              LEFT JOIN (
                  SELECT inv.*
                  FROM invoices inv
                  JOIN (
                      SELECT order_id, MAX(invoice_id) AS max_invoice_id
                      FROM invoices
                      GROUP BY order_id
                  ) latest ON inv.order_id = latest.order_id AND inv.invoice_id = latest.max_invoice_id
              ) i ON o.order_id = i.order_id
              WHERE o.order_id = $order_id AND o.user_id = $user_id";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $order_details = mysqli_fetch_assoc($result);
        
        // Get order items (include variant info from product_variants)
        $items_query = "SELECT oi.*, p.product_name, p.image_url, p.image_path, 
                               pv.variant_type, pv.variant_value 
                       FROM order_items oi 
                       LEFT JOIN products p ON oi.product_id = p.product_id 
                       LEFT JOIN product_variants pv ON oi.variant_id = pv.variant_id 
                       WHERE oi.order_id = $order_id";
        $order_items = mysqli_query($conn, $items_query);
        
        // Get payment history for this order
        $payment_history = [];
        $history_query = "SELECT * FROM payment_history WHERE order_id = ? ORDER BY change_timestamp DESC";
        $hist_stmt = mysqli_prepare($conn, $history_query);
        if ($hist_stmt) {
            mysqli_stmt_bind_param($hist_stmt, "i", $order_id);
            mysqli_stmt_execute($hist_stmt);
            $history_result = mysqli_stmt_get_result($hist_stmt);
            if ($history_result) {
                while ($history = mysqli_fetch_assoc($history_result)) {
                    $payment_history[] = $history;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $order_details ? 'Order Details' : 'My Orders'; ?> - UniNeeds</title>
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
            <h2><?php echo $order_details ? 'Order Details' : 'My Orders'; ?></h2>
            <?php if ($order_details): ?>
                <div class="ms-auto d-flex">
                    <a href="orders.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Orders
                    </a>
                        <?php if (!in_array($order_details['order_status'], ['completed','cancelled']) 
                        && floatval($order_details['amount_paid'] ?? 0) <= 0 
                        && !orderHasPayments($conn, $order_details['order_id'])): ?>
                        <button class="btn btn-danger ms-2" data-bs-toggle="modal" data-bs-target="#cancelOrderModal" data-order-id="<?php echo $order_details['order_id']; ?>">
                            <i class="bi bi-x-lg me-2"></i>Cancel Order
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="content-area">
            <?php if ($success && $new_order_id): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <h5 class="alert-heading"><i class="bi bi-check-circle me-2"></i>Order Placed Successfully!</h5>
                    <p>Your order #<?php echo str_pad($new_order_id, 6, '0', STR_PAD_LEFT); ?> has been placed successfully. We'll notify you when it's ready for pickup.</p>
                    <hr>
                    <a href="orders.php?id=<?php echo $new_order_id; ?>" class="btn btn-sm btn-success">View Order Details</a>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <div id="alertPlaceholder">
                </div>
            
            <?php if ($order_details): ?>
                <div class="row g-4">
                    <div class="col-md-12">
                        <div class="card mb-4">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Order #<?php echo str_pad($order_details['order_id'], 6, '0', STR_PAD_LEFT); ?></h5>
                                    <?php
                                    $badge_class = [
                                        'pending_payment' => 'secondary',
                                        'partial_payment' => 'warning',
                                        'pending' => 'warning',
                                        'ready for pickup' => 'info',
                                        'completed' => 'success',
                                        'cancelled' => 'danger'
                                    ];

                                    // Determine label for pending orders: only show "Paid / Processing" for fully paid pre-order items
                                    $pending_label = 'Processing';
                                    if ($order_details['order_status'] === 'pending') {
                                        $is_fully_paid = in_array($order_details['payment_status'] ?? '', ['paid','fully_paid'])
                                            || (floatval($order_details['amount_paid'] ?? 0) >= floatval($order_details['total_amount'] ?? 0));
                                        if ($is_fully_paid && orderHasPreorderItems($conn, $order_details['order_id'])) {
                                            $pending_label = 'Paid / Processing';
                                        }
                                    }
                                    ?>
                                    <span class="badge bg-<?php echo $badge_class[$order_details['order_status']] ?? 'secondary'; ?> fs-6">
                                        <?php
                                            $labels = [
                                                'pending_payment' => 'Pending Payment',
                                                'partial_payment' => 'Partial Payment / Processing',
                                                'pending' => $pending_label,
                                                'ready for pickup' => 'Ready for Pickup',
                                                'completed' => 'Completed',
                                                'cancelled' => 'Cancelled'
                                            ];
                                            echo $labels[$order_details['order_status']] ?? ucfirst(str_replace('_', ' ', $order_details['order_status']));
                                        ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <p class="mb-2"><strong>Order Date:</strong> <?php echo date('F j, Y g:i A', strtotime($order_details['created_at'])); ?></p>
                                        <p class="mb-2"><strong>Payment Method:</strong> <?php echo ucfirst(str_replace('_', ' ', $order_details['payment_method'])); ?></p>
                                        <?php if ($order_details['order_status'] === 'pending_payment' && !empty($order_details['due_date'])): ?>
                                            <p class="mb-2"><strong>📅 Downpayment Due By:</strong> 
                                                <span class="badge bg-warning"><?php echo date('M j, Y', strtotime($order_details['due_date'])); ?></span>
                                                <small class="text-muted d-block mt-1">Pay amount in cash within this period to avoid cancellation</small>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($order_details['order_status'] === 'ready for pickup' && !empty($order_details['due_date'])): ?>
                                            <p class="mb-2"><strong>📅 Pickup Due Date:</strong> 
                                                <span class="badge bg-warning"><?php echo date('M j, Y', strtotime($order_details['due_date'])); ?></span>
                                                <small class="text-muted d-block mt-1">Must be picked up by this date</small>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-2"><strong>Total Amount:</strong> <span class="text-success fs-5"><?php echo formatCurrency($order_details['total_amount']); ?></span></p>
                                        <?php if ($order_details['invoice_number']): ?>
                                            <p class="mb-2"><strong>Invoice:</strong> <?php echo htmlspecialchars($order_details['invoice_number']); ?></p>
                                            <p class="mb-2"><strong>Payment Status:</strong> 
                                                <span class="badge bg-<?php echo (in_array($order_details['payment_status'], ['fully_paid','paid'])) ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $order_details['payment_status'])); ?>
                                                </span>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Payment Breakdown Section -->
                                <div class="card bg-light mt-4" style="display: block !important; visibility: visible !important;">
                                    <div class="card-body" style="display: block !important;">
                                        <h6 class="card-title mb-3"><i class="bi bi-receipt me-2"></i>Payment Breakdown</h6>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p class="mb-2"><strong>Full Amount:</strong></p>
                                                <p class="text-success fs-5"><strong><?php echo formatCurrency($order_details['total_amount']); ?></strong></p>
                                            </div>
                                            <div class="col-md-6">
                                                <?php 
                                                $amount_paid = floatval($order_details['amount_paid'] ?? 0);
                                                $total_amount = floatval($order_details['total_amount'] ?? 0);

                                                // Compute balance from totals (avoid trusting potentially stale stored balance_due)
                                                $balance_due = max($total_amount - $amount_paid, 0);

                                                $down_payment = floatval($order_details['down_payment_due'] ?? 0);
                                                
                                                // If invoice/payment status indicates fully paid/paid or order is completed but amount_paid isn't set, treat it as fully paid
                                                if ((in_array($order_details['payment_status'], ['fully_paid','paid']) || $order_details['order_status'] === 'completed') && $amount_paid <= 0) {
                                                    $amount_paid = $total_amount;
                                                    $balance_due = 0;
                                                }
                                                
                                                // Payment type is always cash on pickup; amount_paid will be entered by admin
                                                $payment_type = '';
                                                ?>
                                                <p class="mb-2"><strong>Amount Paid:</strong></p>
                                                <p class="text-info fs-5"><strong><?php echo formatCurrency($amount_paid); ?></strong><span class="small text-muted"><?php echo $payment_type; ?></span></p>
                                            </div>
                                        </div>
                                        <hr class="my-3">
                                        <?php if ($balance_due > 0): ?>
                                            <div class="alert alert-warning p-3 mb-0" style="display: block !important;">
                                                <p class="mb-2"><strong><i class="bi bi-exclamation-triangle me-2"></i>Balance Due:</strong></p>
                                                <p class="text-danger fs-5 mb-0"><strong><?php echo formatCurrency($balance_due); ?></strong></p>
                                                <small class="text-muted d-block mt-2">This amount must be paid when claiming your order.</small>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-success p-3 mb-0">
                                                <p class="mb-0"><i class="bi bi-check-circle me-2"></i><strong>Fully Paid!</strong> No balance due.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if (!empty($payment_history)): ?>
                                <!-- Payment History Section -->
                                <div class="card bg-light mt-4" style="display: block !important; visibility: visible !important;">
                                    <div class="card-body" style="display: block !important;">
                                        <h6 class="card-title mb-3"><i class="bi bi-clock-history me-2"></i>Payment History</h6>
                                        <div style="max-height: 300px; overflow-y: auto;">
                                            <?php foreach ($payment_history as $history): ?>
                                            <div style="padding: 10px; border-bottom: 1px solid #ddd; margin-bottom: 8px;">
                                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 5px;">
                                                    <strong style="color: #0ea55f;">
                                                        <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($history['payment_type']))); ?>
                                                    </strong>
                                                    <span style="color: #0aa04a; font-weight: bold; font-size: 16px;">
                                                        <?php echo formatCurrency($history['amount']); ?>
                                                    </span>
                                                </div>
                                                <div style="color: #666; font-size: 12px; margin-bottom: 3px;">
                                                    <i class="bi bi-calendar-event"></i>
                                                    <?php echo date('M j, Y g:i A', strtotime($history['change_timestamp'])); ?>
                                                </div>
                                                <?php if (!empty($history['notes'])): ?>
                                                <div style="color: #999; font-size: 11px; font-style: italic;">
                                                    <?php echo htmlspecialchars($history['notes']); ?>
                                                </div>
                                                <?php endif; ?>
                                                <div style="color: #999; font-size: 11px; margin-top: 3px;">
                                                    Previous Balance: <?php echo formatCurrency($history['previous_balance']); ?> 
                                                    → New Balance: <?php echo formatCurrency($history['new_balance']); ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mt-4">
                                    <h6 class="mb-3">Order Status</h6>
                                    <div class="progress" style="height: 30px;">
                                        <?php
                                        $status_progress = [
                                            'pending_payment' => 10,
                                            'partial_payment' => 20,
                                            'pending' => 40,
                                            'ready for pickup' => 70,
                                            'completed' => 100,
                                            'cancelled' => 100
                                        ];
                                        // Use null-coalescing or array key check to prevent warning if a status is missing
                                        $progress = $status_progress[$order_details['order_status']] ?? 0;
                                        $status_class = $badge_class[$order_details['order_status']] ?? 'secondary';
                                        ?>
                                        <div class="progress-bar bg-<?php echo $status_class; ?>" 
                                             style="width: <?php echo $progress; ?>%">
                                            <?php
                                                $pending_label = 'Processing';
                                                if ($order_details['order_status'] === 'pending') {
                                                    $is_fully_paid = in_array($order_details['payment_status'] ?? '', ['paid','fully_paid'])
                                                        || (floatval($order_details['amount_paid'] ?? 0) >= floatval($order_details['total_amount'] ?? 0));
                                                    if ($is_fully_paid && orderHasPreorderItems($conn, $order_details['order_id'])) {
                                                        $pending_label = 'Paid / Processing';
                                                    }
                                                }

                                                $labels = [
                                                    'pending_payment' => 'To Pay',
                                                    'partial_payment' => 'Partial Payment / Processing',
                                                    'pending' => $pending_label,
                                                    'ready for pickup' => 'Ready for Pickup',
                                                    'completed' => 'Completed',
                                                    'cancelled' => 'Cancelled'
                                                ];
                                                echo $labels[$order_details['order_status']] ?? ucfirst(str_replace('_', ' ', $order_details['order_status']));
                                            ?>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between mt-2 small text-muted">
                                        <span>To Pay</span>
                                        <span>Partial Payment / Processing</span>
                                        <span>Processing</span>
                                        <span>Ready for Pickup</span>
                                        <span>Completed</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Order Items</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Product</th>
                                                <th>Variant</th>
                                                <th>Price</th>
                                                <th>Quantity</th>
                                                <th class="text-end">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($item = mysqli_fetch_assoc($order_items)): ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <?php 
                                                            $img = $item['image_path'] ?? $item['image_url'];
                                                            $imgSrc = '';
                                                            $imgFound = false;
                                                            
                                                            if ($img) {
                                                                // Normalize the image path
                                                                if (preg_match('/^(https?:)?\\/\\//i', $img)) {
                                                                    // Already full URL or absolute path
                                                                    $imgSrc = $img;
                                                                    $imgFound = true;
                                                                } else {
                                                                    // Relative path: prefer application base so web path points to /unineed/...
                                                                    $appBase = rtrim(dirname($_SERVER['SCRIPT_NAME'], 2), '/');
                                                                    $serverCandidate = dirname(__DIR__) . '/' . ltrim($img, '/');
                                                                    if (file_exists($serverCandidate)) {
                                                                        $imgSrc = ($appBase === '' ? '/' : $appBase . '/') . ltrim($img, '/');
                                                                        $imgFound = true;
                                                                    } else {
                                                                        // Fallback to root-based path
                                                                        $imgSrc = '/' . ltrim($img, '/');
                                                                        $imgFound = false;
                                                                    }
                                                                }
                                                            }
                                                            ?>
                                                            <?php if ($imgSrc): ?>
                                                                <img src="<?php echo htmlspecialchars($imgSrc); ?>" 
                                                                     alt="<?php echo htmlspecialchars($item['product_name']); ?>" 
                                                                     class="rounded me-2" 
                                                                     style="width: 50px; height: 50px; object-fit: cover;"
                                                                     onerror="this.onerror=null; this.src='/assets/images/product-placeholder.jpg';">
                                                            <?php else: ?>
                                                                <div class="rounded me-2 d-flex align-items-center justify-content-center" 
                                                                     style="width: 50px; height: 50px; background-color: #f0f0f0;">
                                                                    <i class="bi bi-image text-muted" style="font-size: 1.5rem;"></i>
                                                                </div>
                                                            <?php endif; ?>
                                                            <strong><?php echo htmlspecialchars($item['product_name'] ?? '[Deleted Product]'); ?></strong>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $variants = null;
                                                        
                                                        // if variant info is available via join, show it
                                                        if (!empty($item['variant_type'])): ?>
                                                            <small class="text-muted">
                                                                <span class="badge bg-light text-dark me-1 mb-1">
                                                                    <?php echo htmlspecialchars($item['variant_type']); ?>: <?php echo htmlspecialchars($item['variant_value']); ?>
                                                                </span>
                                                            </small>
                                                        <?php else: ?>
                                                            <span class="text-muted">No variants</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo formatCurrency($item['price']); ?></td>
                                                    <td><?php echo $item['quantity']; ?></td>
                                                    <td class="text-end"><?php echo formatCurrency($item['price'] * $item['quantity']); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <th colspan="4" class="text-end">Total:</th>
                                                <th class="text-end"><?php echo formatCurrency($order_details['total_amount']); ?></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <?php 
                            // present cash payment instructions based on status
                            $balance_due = floatval($order_details['balance_due'] ?? $order_details['remaining_balance'] ?? 0);
                            if ($order_details['order_status'] === 'pending_payment') {
                                $down = floatval($order_details['down_payment_due'] ?? 0);
                                echo '<div class="alert alert-info mb-3">';
                                echo '<strong>Downpayment Due:</strong> ' . formatCurrency($down) . '<br>';
                                if (!empty($order_details['due_date'])) {
                                    echo '<small>Pay by ' . date('M j, Y', strtotime($order_details['due_date'])) . '</small>';
                                }
                                echo '</div>';
                                $after_payment_label = orderHasPreorderItems($conn, $order_details['order_id']) ? 'Paid / Processing' : 'Processing';
                                echo '<p>Please visit the administration office and pay the required amount in cash. Once the payment is recorded by staff, your order status will change to ' . $after_payment_label . '.</p>';
                            }
                            
                            if ($balance_due > 0 && $order_details['order_status'] !== 'pending_payment') {
                                echo '<div class="alert alert-warning mb-3"><strong>Balance Due:</strong> ' . formatCurrency($balance_due) . '</div>';
                                echo '<p>Pay the remaining amount in cash when claiming your order.</p>';
                            }
                        ?>
                    </div>
                            </div>

                        <!-- 'Order Information' card intentionally removed per request -->
                        
                            <?php if ($order_details['order_status'] === 'completed'): ?>
                                <?php if (in_array($order_details['payment_status'], ['fully_paid','paid'])): ?>
                                <div class="card mb-3">
                                    
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                    </div>
                </div>
                
            <?php else: ?>
                <?php if (mysqli_num_rows($orders) > 0): ?>
                    <div class="row g-4">
                        <?php while ($order = mysqli_fetch_assoc($orders)): ?>
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div>
                                                <h5 class="mb-1">Order #<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></h5>
                                                <small class="text-muted">
                                                    <i class="bi bi-calendar me-1"></i>
                                                    <?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?>
                                                </small>
                                            </div>
                                            <?php
                                            $badge_class = [
                                                'pending_payment' => 'secondary',
                                                'partial_payment' => 'warning',
                                                'pending' => 'warning',
                                                'ready for pickup' => 'info',
                                                'completed' => 'success',
                                                'cancelled' => 'danger'
                                            ];

                                            $pending_label = 'Processing';
                                            if ($order['order_status'] === 'pending') {
                                                $is_fully_paid = in_array($order['payment_status'] ?? '', ['paid','fully_paid'])
                                                    || (floatval($order['amount_paid'] ?? 0) >= floatval($order['total_amount'] ?? 0));
                                                if ($is_fully_paid && orderHasPreorderItems($conn, $order['order_id'])) {
                                                    $pending_label = 'Paid / Processing';
                                                }
                                            }

                                            $labels = [
                                                'pending_payment' => 'To Pay',
                                                'partial_payment' => 'Partial Payment / Processing',
                                                'pending' => $pending_label,
                                                'ready for pickup' => 'Ready for Pickup',
                                                'completed' => 'Completed',
                                                'cancelled' => 'Cancelled'
                                            ];
                                            ?>
                                            <span class="badge bg-<?php echo $badge_class[$order['order_status']] ?? 'secondary'; ?>">
                                                <?php echo $labels[$order['order_status']] ?? ucfirst(str_replace('_',' ',$order['order_status'])); ?>
                                            </span>
                                        </div>
                                        
                                        <?php if ($order['order_status'] === 'ready for pickup' && !empty($order['due_date'])): ?>
                                            <div class="alert alert-warning p-2 mb-3">
                                                <small class="d-flex align-items-center gap-2 mb-0">
                                                    <i class="bi bi-calendar-event"></i>
                                                    <strong>Pickup Due:</strong> <?php echo date('M j, Y', strtotime($order['due_date'])); ?>
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-2">
                                                <span class="text-muted">Total Amount:</span>
                                                <strong class="text-success"><?php echo formatCurrency($order['total_amount']); ?></strong>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span class="text-muted">Payment:</span>
                                                <span><?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?></span>
                                            </div>
                                        </div>
                                        
                                        <a href="orders.php?id=<?php echo $order['order_id']; ?>" class="btn btn-primary btn-sm w-100 mb-2">
                                            <i class="bi bi-eye me-2"></i>View Details
                                        </a>
                                        <?php if (in_array($order['order_status'], ['pending','pending_payment']) && floatval($order['amount_paid'] ?? 0) <= 0): ?>
                                            <button class="btn btn-danger btn-sm w-100" data-bs-toggle="modal" data-bs-target="#cancelOrderModal" data-order-id="<?php echo $order['order_id']; ?>">
                                                <i class="bi bi-x-lg me-2"></i>Cancel Order
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <h5>No Active Orders</h5>
                        <p>You don't have any active orders at the moment.</p>
                        <!-- <a href="products.php" class="btn btn-primary">
                            <i class="bi bi-shop me-2"></i>Start Shopping
                        </a>
                        <a href="order-history.php" class="btn btn-outline-secondary ms-2">
                            <i class="bi bi-clock-history me-2"></i>View Order History
                        </a> -->
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
        <!-- Cancel Order Modal -->
        <div class="modal fade" id="cancelOrderModal" tabindex="-1" aria-labelledby="cancelOrderModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="cancelOrderModalLabel">Select reason</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                      <div class="modal-body">
                        <input type="hidden" id="cancel_order_id" value="">

                        <div id="cancel_reasons_list">
                                <div class="list-group">
                                        <label class="list-group-item">
                                            <input class="form-check-input me-2" type="radio" name="cancel_reason_radio" value="changed_mind" onchange="onCancelReasonRadioChange(this)"> Changed mind
                                        </label>
                                        <label class="list-group-item">
                                            <input class="form-check-input me-2" type="radio" name="cancel_reason_radio" value="wrong_item" onchange="onCancelReasonRadioChange(this)"> Wrong item ordered
                                        </label>
                                        <label class="list-group-item">
                                            <input class="form-check-input me-2" type="radio" name="cancel_reason_radio" value="change_size" onchange="onCancelReasonRadioChange(this)"> Change of size
                                        </label>
                                        <label class="list-group-item">
                                            <input class="form-check-input me-2" type="radio" name="cancel_reason_radio" value="ordered_mistake" onchange="onCancelReasonRadioChange(this)"> Ordered by mistake
                                        </label>
                                        <label class="list-group-item">
                                            <input class="form-check-input me-2" type="radio" name="cancel_reason_radio" value="other" onchange="onCancelReasonRadioChange(this)"> Other
                                        </label>
                                </div>
                        </div>

                        <div class="mb-3 mt-3" id="cancel_reason_other_container" style="display:none;">
                            <label for="cancel_reason_other" class="form-label">If other, please specify</label>
                            <textarea id="cancel_reason_other" class="form-control" rows="3" placeholder="Type reason here..."></textarea>
                        </div>

                        <div class="small text-muted mt-3">Note: Payment is non-refundable.</div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                        <button id="cancel_confirm_btn" type="button" class="btn btn-dark w-100" disabled onclick="submitCancelFromModal(this)">Cancel order</button>
                    </div>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script src="../assets/js/script.js"></script>
</body>
</html>
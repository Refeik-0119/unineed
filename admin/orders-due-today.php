<?php

require_once '../config/database.php';
requireAdmin();
// email helper for sending reminder emails
require_once '../config/EmailHelper.php';

// Handle bulk order cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_cancel'])) {
    $selected_orders = isset($_POST['selected_orders']) ? $_POST['selected_orders'] : [];
    $cancellation_reason = clean($_POST['cancellation_reason']);
    $additional_notes = isset($_POST['additional_notes']) ? clean($_POST['additional_notes']) : '';
    
    if (empty($selected_orders)) {
        $error = "Please select at least one order to cancel.";
    } else {
        $full_reason = !empty($additional_notes) ? $cancellation_reason . " - " . $additional_notes : $cancellation_reason;
        $success_count = 0;
        $error_details = [];
        
        foreach ($selected_orders as $order_id) {
            $order_id = (int)$order_id;
            
            mysqli_begin_transaction($conn);
            try {
                // Get order details
                $cur_q = "SELECT o.order_status, o.user_id, i.payment_status, i.amount_paid 
                FROM orders o 
                LEFT JOIN invoices i ON o.order_id = i.order_id 
                WHERE o.order_id = $order_id FOR UPDATE";
                $cur_res = mysqli_query($conn, $cur_q);
                $cur_row = $cur_res ? mysqli_fetch_assoc($cur_res) : null;
                $previous_status = $cur_row ? $cur_row['order_status'] : null;
                $order_user_id = $cur_row ? $cur_row['user_id'] : null;
                // do not cancel if any payment has been made (partial or full)
                // note parentheses to ensure $cur_row is checked first
                if ($cur_row &&
                   ( ( !empty($cur_row['amount_paid']) && floatval($cur_row['amount_paid']) > 0 )
                     || (isset($cur_row['payment_status']) && $cur_row['payment_status'] !== 'unpaid') )
                ) {
                    throw new Exception('Cannot cancel order that has already been paid');
                }
                // also check auxiliary payments table if present
                $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'payments'");
                if ($tbl && mysqli_num_rows($tbl) > 0) {
                    $pchk = mysqli_query($conn, "SELECT 1 FROM payments WHERE order_id = $order_id LIMIT 1");
                    if ($pchk && mysqli_num_rows($pchk) > 0) {
                        throw new Exception('Cannot cancel order that has a recorded payment');
                    }
                }

                // Update order status to cancelled
                $query = "UPDATE orders SET order_status = 'cancelled', cancellation_reason = '$full_reason' WHERE order_id = $order_id";
                if (!mysqli_query($conn, $query)) {
                    throw new Exception('Failed to cancel order: ' . mysqli_error($conn));
                }

                // Restore stock
                if ($previous_status !== 'cancelled') {
                    $items_query = "SELECT oi.*, oi.quantity as qty, oi.product_id, oi.variant_id 
                                    FROM order_items oi 
                                    WHERE oi.order_id = $order_id";
                    $items_res = mysqli_query($conn, $items_query);
                    if ($items_res) {
                        while ($it = mysqli_fetch_assoc($items_res)) {
                            $qty = intval($it['qty']);
                            $p_id = intval($it['product_id']);
                            $v_id = isset($it['variant_id']) ? intval($it['variant_id']) : null;
                            
                            $upd = "UPDATE products SET stock_quantity = stock_quantity + $qty WHERE product_id = $p_id";
                            mysqli_query($conn, $upd);
                            
                            if ($v_id) {
                                $var_upd = "UPDATE product_variants SET stock_quantity = stock_quantity + $qty WHERE variant_id = $v_id";
                                mysqli_query($conn, $var_upd);
                            }
                        }
                    }
                }

                // Notify student (include order_id)
                $message = "Your order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . " has been cancelled. Reason: " . $cancellation_reason;
                $msg_esc = mysqli_real_escape_string($conn, $message);
                $notif_query = "INSERT INTO notifications (user_id, message, type, order_id) VALUES ({$order_user_id}, '$msg_esc', 'order_cancelled', $order_id)";
                mysqli_query($conn, $notif_query);

                mysqli_commit($conn);
                $success_count++;
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error_details[] = "Order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . ": " . $e->getMessage();
            }
        }
        
        if ($success_count > 0) {
            $success = "$success_count order(s) cancelled successfully and students notified!";
        }
        if (!empty($error_details)) {
            $error = "Some orders failed to cancel: " . implode(", ", $error_details);
        }
    }
}

// Handle sending notifications to single or multiple students
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notification'])) {
    $selected_orders = isset($_POST['selected_orders']) ? $_POST['selected_orders'] : [];
    $notification_message = clean($_POST['notification_message']);
    
    if (empty($selected_orders)) {
        $error = "Please select at least one order to send notification.";
    } elseif (empty($notification_message)) {
        $error = "Please enter a notification message.";
    } else {
        $success_count = 0;
        // instantiate email helper once
        $emailer = new EmailHelper();

        foreach ($selected_orders as $order_id) {
            $order_id = (int)$order_id;
            // fetch email and name as well
            $get_user = mysqli_query($conn, "SELECT o.user_id, u.email, u.full_name
                                           FROM orders o
                                           JOIN users u ON o.user_id = u.user_id
                                           WHERE o.order_id = $order_id");
            if ($get_user && $row = mysqli_fetch_assoc($get_user)) {
                $user_id = $row['user_id'];
                $user_email = $row['email'];
                $user_name = $row['full_name'];

                $msg_esc = mysqli_real_escape_string($conn, $notification_message);
                $notif_query = "INSERT INTO notifications (user_id, message, type, order_id) VALUES ($user_id, '$msg_esc', 'reminder', $order_id)";
                if (mysqli_query($conn, $notif_query)) {
                    $success_count++;
                    // send email reminder as well
                    $subject = "Order Reminder - UniNeeds";
                    $email_body = "<p>Hi " . htmlspecialchars($user_name) . ",</p>"
                    ."<p>This is a reminder about your order #".str_pad($order_id,6,'0',STR_PAD_LEFT).".</p>"
                    ."<p>".nl2br(htmlspecialchars($notification_message))."</p>"
                    ."<p>Please visit your account to review your order or contact support if you have any questions.</p>"
                    ."<p>Thank you,<br>UniNeeds Team</p>";
                    $emailer->sendEmail($user_email, $user_name, $subject, $email_body);
                }
            }
        }
        // end foreach
        $success = "Notification sent to $success_count student(s)!";
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? clean($_GET['search']) : '';
$days_filter = isset($_GET['days']) ? clean($_GET['days']) : '0'; // 0 = today, 1 = tomorrow, 7 = within 7 days, -1 = overdue

// Build query
$where_clauses = ["o.order_status = 'ready for pickup'"];

// Filter by due date
if ($days_filter === '-1') {
    // Overdue orders
    $where_clauses[] = "o.due_date < NOW()";
} elseif ($days_filter === '0') {
    // Due today
    $where_clauses[] = "DATE(o.due_date) = DATE(NOW())";
} elseif ($days_filter === '1') {
    // Due tomorrow
    $where_clauses[] = "DATE(o.due_date) = DATE(DATE_ADD(NOW(), INTERVAL 1 DAY))";
} elseif ($days_filter === '7') {
    // Within 7 business days (ignore weekends)
    // helper to add business days
    function addBusinessDays($start, $days) {
        $current = strtotime($start);
        while ($days > 0) {
            $current = strtotime('+1 day', $current);
            $weekday = date('N', $current);
            if ($weekday < 6) {
                $days--;
            }
        }
        return date('Y-m-d', $current);
    }
    $end_date = addBusinessDays(date('Y-m-d'), 7);
    $where_clauses[] = "DATE(o.due_date) BETWEEN DATE(NOW()) AND '$end_date'";
} else {
    // Default: show all ready for pickup with due dates
    $where_clauses[] = "o.due_date IS NOT NULL";
}

if ($search) {
    $where_clauses[] = "(u.full_name LIKE '%$search%' OR u.email LIKE '%$search%' OR o.order_id LIKE '%$search%')";
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : 'WHERE o.order_status = "ready for pickup"';

// Query orders
$query = "SELECT o.*, o.created_at AS order_date, u.full_name, u.email, u.phone, i.payment_status, i.amount_paid
          FROM orders o
          JOIN users u ON o.user_id = u.user_id
          LEFT JOIN invoices i ON o.order_id = i.order_id
          $where_sql
          ORDER BY o.due_date ASC, o.created_at DESC";
$orders = mysqli_query($conn, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders Due Today - UniNeeds Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <style>
        .order-details-row {
            background-color: #f8f9fa;
            border-top: 2px solid #dee2e6;
        }
        .order-details-content {
            padding: 20px;
        }
        .order-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .order-row:hover {
            background-color: #f8f9fa;
        }
        .order-row.expanded {
            background-color: #e9ecef;
        }
        .overdue-highlight {
            background-color: #fff3cd !important;
        }
        .overdue-highlight:hover {
            background-color: #ffe8a1 !important;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <button class="btn btn-link d-md-none" id="sidebarToggle">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h2>Orders Due Today / Soon</h2>
        </div>
        
        <div class="content-area">
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                This page shows orders marked as "Ready for Pickup" that are due today, overdue, or within the next 7 days. Students must claim their orders by the due date (1 week from when marked as ready).
            </div>
            
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
            
            <div class="filter-bar">
                <form id="dueOrdersFilterForm" method="GET" class="row g-2 align-items-end">
                    <div class="col-12 col-md-4">
                        <label class="form-label small mb-1">Search</label>
                        <input type="text" class="form-control form-control-sm" name="search" placeholder="Customer, email, or order ID" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small mb-1">Filter by Due Date</label>
                        <select class="form-select form-select-sm" name="days">
                            <option value="">All Due Orders</option>
                            <option value="-1" <?php echo $days_filter === '-1' ? 'selected' : ''; ?>>Overdue (Past Due)</option>
                            <option value="0" <?php echo $days_filter === '0' ? 'selected' : ''; ?>>Due Today</option>
                            <option value="1" <?php echo $days_filter === '1' ? 'selected' : ''; ?>>Due Tomorrow</option>
                            <option value="7" <?php echo $days_filter === '7' ? 'selected' : ''; ?>>Within 7 Days</option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3 d-flex gap-2">
                        <a href="orders-due-today.php" class="btn btn-sm btn-outline-secondary flex-grow-1">
                            <i class="bi bi-x-circle me-1"></i>Clear
                        </a>
                        <a href="orders.php" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-arrow-left me-1"></i>Back
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Bulk Actions -->
            <div class="card mb-3" id="bulkActionsPanel" style="display: none;">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <span id="selectedCount" class="text-muted"></span>
                        </div>
                        <div class="col-md-6 text-end">
                            <button type="button" class="btn btn-warning btn-sm" onclick="openBulkNotifyModal()"><i class="bi bi-bell me-2"></i>Notify Selected</button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="openBulkCancelModal()"><i class="bi bi-x-circle me-2"></i>Cancel Selected</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="clearAllSelections()"><i class="bi bi-x me-2"></i>Clear All</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th width="40"><input type="checkbox" id="selectAllOrders" onclick="toggleAllCheckboxes()" title="Select All"></th>
                                    <th>Order ID</th>
                                    <th>Customer</th>
                                    <th>Contact</th>
                                    <th>Amount</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($orders) > 0): ?>
                                    <?php while ($order = mysqli_fetch_assoc($orders)): ?>
                                        <?php 
                                            $due_timestamp = strtotime($order['due_date']);
                                            $now_timestamp = time();
                                            $is_overdue = $due_timestamp < $now_timestamp;
                                            $is_due_today = date('Y-m-d') === date('Y-m-d', $due_timestamp);
                                            $days_until_due = ceil(($due_timestamp - $now_timestamp) / (60 * 60 * 24));
                                            $row_class = $is_overdue ? 'overdue-highlight' : '';
                                        ?>
                                        <tr class="order-row <?php echo $row_class; ?>" data-order-id="<?php echo $order['order_id']; ?>">
                                            <?php 
    $disableCancel = (!empty($order['payment_status']) && $order['payment_status'] !== 'unpaid') || (floatval($order['amount_paid'] ?? 0) > 0);
    // if a separate payments table exists check it as well
    $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'payments'");
    if ($tbl && mysqli_num_rows($tbl) > 0) {
        $pchk = mysqli_query($conn, "SELECT 1 FROM payments WHERE order_id = {$order['order_id']} LIMIT 1");
        if ($pchk && mysqli_num_rows($pchk) > 0) {
            $disableCancel = true;
        }
    }
?>
                                    <td><input type="checkbox" class="order-checkbox" value="<?php echo $order['order_id']; ?>" onclick="updateSelectAllState()" <?php echo $disableCancel ? 'disabled' : ''; ?>></td>
                                            <td><strong>#<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></strong></td>
                                            <td>
                                                <?php echo htmlspecialchars($order['full_name']); ?><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($order['email']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($order['phone']); ?></td>
                                            <td><strong><?php echo formatCurrency($order['total_amount']); ?></strong></td>
                                            <td>
                                                <strong><?php echo date('M j, Y', $due_timestamp); ?></strong>
                                                <br>
                                                <?php if ($is_overdue): ?>
                                                    <span class="badge bg-danger">OVERDUE</span>
                                                <?php elseif ($is_due_today): ?>
                                                    <span class="badge bg-warning">DUE TODAY</span>
                                                <?php elseif ($days_until_due <= 1): ?>
                                                    <span class="badge bg-warning"><?php echo $days_until_due . ' day left'; ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-info"><?php echo $days_until_due . ' days left'; ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info">Ready for Pickup</span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-info btn-action" onclick="event.stopPropagation(); toggleOrderDetails(<?php echo $order['order_id']; ?>)" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-warning btn-action" onclick="event.stopPropagation();" data-bs-toggle="modal" data-bs-target="#notifyModal<?php echo $order['order_id']; ?>" title="Send Reminder Notification">
                                                    <i class="bi bi-bell"></i>
                                                </button>
                                                <?php 
    $showCancel = (empty($order['payment_status']) || $order['payment_status'] === 'unpaid') && (floatval($order['amount_paid'] ?? 0) <= 0);
    // check payments table too if available
    $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'payments'");
    if ($tbl && mysqli_num_rows($tbl) > 0) {
        $pchk = mysqli_query($conn, "SELECT 1 FROM payments WHERE order_id = {$order['order_id']} LIMIT 1");
        if ($pchk && mysqli_num_rows($pchk) > 0) {
            $showCancel = false;
        }
    }
?>
                                                <?php if ($showCancel): ?>
                                                <button class="btn btn-sm btn-danger btn-action" onclick="event.stopPropagation();" data-bs-toggle="modal" data-bs-target="#cancelModal<?php echo $order['order_id']; ?>" title="Cancel Order">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        
                                        <tr class="order-details-row d-none" id="details-<?php echo $order['order_id']; ?>">
                                            <td colspan="8">
                                                <div class="order-details-content">
                                                    <?php
                                                    // Get order details
                                                    $detail_q = "SELECT o.*, i.payment_status, i.down_payment_due, i.remaining_balance, i.amount_paid, i.balance_due 
                                                                 FROM orders o 
                                                                 LEFT JOIN invoices i ON o.order_id = i.order_id 
                                                                 WHERE o.order_id = {$order['order_id']}";
                                                    $detail_res = mysqli_query($conn, $detail_q);
                                                    $detail_data = $detail_res ? mysqli_fetch_assoc($detail_res) : [];
                                                    
                                                    // Get order items
                                                    $items_query = "SELECT oi.*, p.product_name, p.image_url 
                                                                   FROM order_items oi 
                                                                   JOIN products p ON oi.product_id = p.product_id 
                                                                   WHERE oi.order_id = {$order['order_id']}";
                                                    $items = mysqli_query($conn, $items_query);
                                                    ?>
                                                    
                                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                                        <h5 class="mb-0">
                                                            Order Details - #<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?>
                                                            <?php if ($is_overdue): ?>
                                                                <span class="badge bg-danger ms-2">OVERDUE FOR PICKUP</span>
                                                            <?php elseif ($is_due_today): ?>
                                                                <span class="badge bg-warning ms-2">DUE TODAY</span>
                                                            <?php endif; ?>
                                                        </h5>
                                                        <button type="button" class="btn btn-sm btn-secondary" onclick="toggleOrderDetails(<?php echo $order['order_id']; ?>)">
                                                            <i class="bi bi-x-lg me-1"></i> Close
                                                        </button>
                                                    </div>
                                                    
                                                    <div class="row mb-3">
                                                        <div class="col-md-4">
                                                            <h6>Customer Information</h6>
                                                            <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($order['full_name']); ?></p>
                                                            <p class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars($order['email']); ?></p>
                                                            <p class="mb-1"><strong>Phone:</strong> <?php echo htmlspecialchars($order['phone']); ?></p>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <h6>Order & Pickup Details</h6>
                                                            <p class="mb-1"><strong>Order Date:</strong> <?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></p>
                                                            <p class="mb-1"><strong>Due Date:</strong> <strong><?php echo date('M j, Y', $due_timestamp); ?></strong></p>
                                                            <?php if ($is_overdue): ?>
                                                                <p class="mb-1"><strong class="text-danger">Days Overdue:</strong> <span class="badge bg-danger"><?php echo abs($days_until_due) . ' days'; ?></span></p>
                                                            <?php else: ?>
                                                                <p class="mb-1"><strong>Days Until Due:</strong> <span class="badge bg-info"><?php echo $days_until_due . ' days'; ?></span></p>
                                                            <?php endif; ?>
                                                            <p class="mb-1"><strong>Payment Method:</strong> <?php echo ucfirst($order['payment_method']); ?></p>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <h6>Financials</h6>
                                                            <p class="mb-1"><strong>Total Amount:</strong> <strong class="text-success"><?php echo formatCurrency($detail_data['total_amount'] ?? 0); ?></strong></p>
                                                            <p class="mb-1"><strong>Payment Status:</strong> <span class="badge bg-<?php echo ($detail_data['payment_status'] === 'paid' || $detail_data['payment_status'] === 'fully_paid') ? 'success' : 'warning'; ?>"><?php echo ucfirst(str_replace('_', ' ', $detail_data['payment_status'] ?? 'N/A')); ?></span></p>
                                                        </div>
                                                    </div>
                                                    
                                                    <h6>Order Items</h6>
                                                    <div class="table-responsive">
                                                        <table class="table table-sm">
                                                            <thead>
                                                                <tr>
                                                                    <th>Product</th>
                                                                    <th>Variant</th>
                                                                    <th>Price</th>
                                                                    <th>Quantity</th>
                                                                    <th>Subtotal</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php while ($item = mysqli_fetch_assoc($items)): ?>
                                                                    <tr>
                                                                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                                                        <td>
                                                                            <?php 
                                                                            $variant_text = '';
                                                                            if (!empty($item['variant_value'])) {
                                                                                $decoded = json_decode($item['variant_value'], true);
                                                                                if (is_array($decoded)) {
                                                                                    $variant_text = implode(', ', array_map(function($type, $value) {
                                                                                        return ucfirst($type) . ': ' . $value;
                                                                                    }, array_keys($decoded), $decoded));
                                                                                } else {
                                                                                    $variant_text = $item['variant_value'];
                                                                                }
                                                                            }
                                                                            echo $variant_text ? htmlspecialchars($variant_text) : '-';
                                                                            ?>
                                                                        </td>
                                                                        <td><?php echo formatCurrency($item['price']); ?></td>
                                                                        <td><?php echo $item['quantity']; ?></td>
                                                                        <td><?php echo formatCurrency($item['price'] * $item['quantity']); ?></td>
                                                                    </tr>
                                                                <?php endwhile; ?>
                                                            </tbody>
                                                            <tfoot>
                                                                <tr>
                                                                    <th colspan="4" class="text-end">Total:</th>
                                                                    <th><?php echo formatCurrency($order['total_amount']); ?></th>
                                                                </tr>
                                                            </tfoot>
                                                        </table>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        
                                        <!-- Cancel Modal -->
                                        <div class="modal fade" id="cancelModal<?php echo $order['order_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Cancel Order #<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                                            <div class="mb-3">
                                                                <label class="form-label"><strong>Select Cancellation Reason</strong></label>
                                                                <select class="form-select" name="cancellation_reason" required>
                                                                    <option value="">-- Choose a reason --</option>
                                                                    <option value="Customer did not pickup order by due date">Customer did not pickup order by due date</option>
                                                                    <option value="Customer requested cancellation">Customer requested cancellation</option>
                                                                    <option value="Out of stock - unable to fulfill">Out of stock - unable to fulfill</option>
                                                                    <option value="Duplicate order">Duplicate order</option>
                                                                    <option value="Payment issue">Payment issue</option>
                                                                    <option value="Product damaged or defective">Product damaged or defective</option>
                                                                    <option value="Other">Other - Please explain below</option>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Additional Notes (Optional)</label>
                                                                <textarea class="form-control" name="additional_notes" rows="3" placeholder="Add any additional details..."></textarea>
                                                            </div>
                                                            <div class="alert alert-warning">
                                                                <i class="bi bi-exclamation-triangle me-2"></i>
                                                                Stock will be automatically restored and the student will be notified of the cancellation.
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                            <button type="submit" name="cancel_order" class="btn btn-danger">Confirm Cancellation</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Notify Modal -->
                                        <div class="modal fade" id="notifyModal<?php echo $order['order_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Send Reminder - Order #<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="send_notification" value="1">
                                                            <input type="hidden" name="selected_orders[]" value="<?php echo $order['order_id']; ?>">
                                                            <div class="mb-3">
                                                                <label class="form-label"><strong>Customer</strong></label>
                                                                <p class="form-control-plaintext"><?php echo htmlspecialchars($order['full_name']); ?> (<?php echo htmlspecialchars($order['email']); ?>)</p>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label"><strong>Due Date</strong></label>
                                                                <p class="form-control-plaintext"><?php echo date('M j, Y', strtotime($order['due_date'])); ?></p>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Notification Message <span class="text-danger">*</span></label>
                                                                <textarea class="form-control" name="notification_message" rows="4" placeholder="Enter reminder message..." required></textarea>
                                                                <small class="form-text text-muted">Tip: Remind them about the due date and pickup location</small>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                            <button type="submit" class="btn btn-warning">Send Reminder</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <i class="bi bi-inbox"></i>
                                                <h5>No Due Orders Found</h5>
                                                <p>There are no orders due for pickup matching your criteria.</p>
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
    
    <!-- Bulk Notify Modal -->
    <div class="modal fade" id="bulkNotifyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Send Reminder to Selected Orders</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="send_notification" value="1">
                        <div id="bulkNotifyOrdersList"></div>
                        <div class="mb-3">
                            <label class="form-label"><strong>Notification Message</strong></label>
                            <textarea class="form-control" name="notification_message" rows="4" placeholder="Enter reminder message..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-warning">Send Reminder</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Bulk Cancel Modal -->
    <div class="modal fade" id="bulkCancelModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Cancel Selected Orders</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="bulk_cancel" value="1">
                        <div id="bulkCancelOrdersList"></div>
                        <div class="mb-3">
                            <label class="form-label"><strong>Select Cancellation Reason</strong></label>
                            <select class="form-select" name="cancellation_reason" required>
                                <option value="">-- Choose a reason --</option>
                                <option value="Customer did not pickup order by due date">Customer did not pickup order by due date</option>
                                <option value="Customer requested cancellation">Customer requested cancellation</option>
                                <option value="Out of stock - unable to fulfill">Out of stock - unable to fulfill</option>
                                <option value="Duplicate order">Duplicate order</option>
                                <option value="Payment issue">Payment issue</option>
                                <option value="Product damaged or defective">Product damaged or defective</option>
                                <option value="Other">Other - Please explain below</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Additional Notes (Optional)</label>
                            <textarea class="form-control" name="additional_notes" rows="3" placeholder="Add any additional details..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-danger">Confirm Cancellation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script>
        function toggleOrderDetails(orderId) {
            const detailsRow = document.getElementById('details-' + orderId);
            const orderRow = document.querySelector('.order-row[data-order-id="' + orderId + '"]');
            
            // Close all other open details
            document.querySelectorAll('.order-details-row').forEach(row => {
                if (row.id !== 'details-' + orderId) {
                    row.classList.add('d-none');
                }
            });
            
            // Remove expanded class from all rows
            document.querySelectorAll('.order-row').forEach(row => {
                if (row.dataset.orderId != orderId) {
                    row.classList.remove('expanded');
                }
            });
            
            // Toggle current details
            if (detailsRow.classList.contains('d-none')) {
                detailsRow.classList.remove('d-none');
                orderRow.classList.add('expanded');
            } else {
                detailsRow.classList.add('d-none');
                orderRow.classList.remove('expanded');
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-submit filter form on change (debounced)
            (function(){
                const form = document.getElementById('dueOrdersFilterForm');
                if (!form) return;
                let debounceTimer = null;
                const submitDebounced = (delay) => {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => form.submit(), delay || 300);
                };

                form.querySelectorAll('select, input').forEach(el => {
                    el.addEventListener('change', () => submitDebounced(300));
                    if (el.tagName === 'INPUT' && el.type === 'text') {
                        el.addEventListener('input', () => submitDebounced(800));
                    }
                });
            })();
        });
        
        function toggleAllCheckboxes() {
            const selectAll = document.getElementById('selectAllOrders');
            const checkboxes = document.querySelectorAll('.order-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = selectAll.checked;
            });
            updateSelectAllState();
        }
        
        function updateSelectAllState() {
            const checkboxes = document.querySelectorAll('.order-checkbox');
            const selectAll = document.getElementById('selectAllOrders');
            const bulkPanel = document.getElementById('bulkActionsPanel');
            const selectedCount = document.getElementById('selectedCount');
            
            const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            
            selectAll.checked = checkedCount > 0 && checkedCount === checkboxes.length;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
            
            if (checkedCount > 0) {
                bulkPanel.style.display = 'block';
                selectedCount.textContent = checkedCount + ' order(s) selected';
            } else {
                bulkPanel.style.display = 'none';
            }
        }
        
        function clearAllSelections() {
            document.getElementById('selectAllOrders').checked = false;
            document.querySelectorAll('.order-checkbox').forEach(cb => cb.checked = false);
            updateSelectAllState();
        }
        
        function getSelectedOrders() {
            const checkboxes = document.querySelectorAll('.order-checkbox:checked');
            return Array.from(checkboxes).map(cb => cb.value);
        }
        
        function openBulkNotifyModal() {
            const selectedOrders = getSelectedOrders();
            if (selectedOrders.length === 0) {
                alert('Please select at least one order');
                return;
            }
            
            const ordersList = document.getElementById('bulkNotifyOrdersList');
            ordersList.innerHTML = '<div class="mb-3"><strong>Selected Orders:</strong><ul class="list-unstyled">';
            
            selectedOrders.forEach(orderId => {
                const orderRow = document.querySelector(`tr[data-order-id="${orderId}"]`);
                if (orderRow) {
                    const customerName = orderRow.cells[2].textContent.split('\n')[0];
                    ordersList.innerHTML += `<li>#${String(orderId).padStart(6, '0')} - ${customerName}</li>`;
                    ordersList.innerHTML += `<input type="hidden" name="selected_orders[]" value="${orderId}">`;
                }
            });
            
            ordersList.innerHTML += '</ul></div>';
            
            const modal = new bootstrap.Modal(document.getElementById('bulkNotifyModal'));
            modal.show();
        }
        
        function openBulkCancelModal() {
            const selectedOrders = getSelectedOrders();
            if (selectedOrders.length === 0) {
                alert('Please select at least one order');
                return;
            }
            
            const ordersList = document.getElementById('bulkCancelOrdersList');
            ordersList.innerHTML = '<div class="alert alert-warning mb-3"><strong>Warning:</strong> You are about to cancel ' + selectedOrders.length + ' order(s). Stock will be restored for all.</div><div class="mb-3"><strong>Selected Orders:</strong><ul class="list-unstyled">';
            
            selectedOrders.forEach(orderId => {
                const orderRow = document.querySelector(`tr[data-order-id="${orderId}"]`);
                if (orderRow) {
                    const customerName = orderRow.cells[2].textContent.split('\n')[0];
                    ordersList.innerHTML += `<li>#${String(orderId).padStart(6, '0')} - ${customerName}</li>`;
                    ordersList.innerHTML += `<input type="hidden" name="selected_orders[]" value="${orderId}">`;
                }
            });
            
            ordersList.innerHTML += '</ul></div>';
            
            const modal = new bootstrap.Modal(document.getElementById('bulkCancelModal'));
            modal.show();
        }
    </script>
</body>
</html>

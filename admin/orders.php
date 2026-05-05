<?php

require_once '../config/database.php';
requireAdmin();

function orderHasPreorderItems($conn, $order_id) {
    $q = mysqli_query($conn, "SELECT 1 FROM order_items oi JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = $order_id AND p.is_preorder = 1 LIMIT 1");
    return $q && mysqli_num_rows($q) > 0;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // PAYMENT RECORDING (separate action from status change)
    if (isset($_POST['update_payment'])) {
        $order_id = clean($_POST['order_id']);
        $payment_amount = floatval($_POST['payment_amount']);

        mysqli_begin_transaction($conn);
        try {
            // fetch current invoice amounts and order total for decision-making (use latest invoice row per order)
            $info_q = "SELECT i.amount_paid, i.balance_due, i.down_payment_due, i.remaining_balance, o.total_amount, i.invoice_id, i.payment_status
                       FROM orders o
                       JOIN (
                           SELECT inv.*
                           FROM invoices inv
                           JOIN (
                               SELECT order_id, MAX(invoice_id) AS max_invoice_id
                               FROM invoices
                               GROUP BY order_id
                           ) latest ON inv.order_id = latest.order_id AND inv.invoice_id = latest.max_invoice_id
                       ) i ON o.order_id = i.order_id
                       WHERE o.order_id = $order_id FOR UPDATE";
            $info_res = mysqli_query($conn, $info_q);
            $info = $info_res ? mysqli_fetch_assoc($info_res) : null;

            if (!$info) {
                throw new Exception('Invoice not found');
            }

            // prevent overpayment
            if ($payment_amount > floatval($info['balance_due'])) {
                throw new Exception('Payment amount cannot exceed remaining balance.');
            }

            // Store previous values for payment history
            $prev_amount_paid = floatval($info['amount_paid']);
            $prev_balance_due = floatval($info['balance_due']);
            $prev_payment_status = $info['payment_status'];
            $invoice_id = intval($info['invoice_id']);

            $new_paid = floatval($info['amount_paid']) + $payment_amount;
            $new_balance = max(floatval($info['balance_due']) - $payment_amount, 0);
            $new_down_due = max(floatval($info['down_payment_due']) - $payment_amount, 0);
            $new_remaining = max(floatval($info['remaining_balance']) - $payment_amount, 0);

            // determine new payment status
            if ($new_paid >= floatval($info['total_amount'])) {
                $new_payment_status = 'paid';
            } else {
                $new_payment_status = 'downpayment_paid';
            }

            // Determine payment type for history logging
            $payment_type = 'additional_payment';
            if ($prev_amount_paid == 0) {
                $payment_type = 'downpayment';
            } elseif ($new_balance <= 0) {
                $payment_type = 'full_payment';
            }

            // perform update
            $update_invoice = "UPDATE invoices i
                               SET i.amount_paid = $new_paid,
                                   i.balance_due = $new_balance,
                                   i.down_payment_due = $new_down_due,
                                   i.remaining_balance = $new_remaining,
                                   i.payment_status = '$new_payment_status',
                                   i.payment_date = NOW()
                               WHERE i.order_id = $order_id";
            if (!mysqli_query($conn, $update_invoice)) {
                throw new Exception('Failed to record payment: ' . mysqli_error($conn));
            }

            // Log payment to payment_history table
            $admin_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
            $payment_notes = isset($_POST['payment_notes']) ? clean($_POST['payment_notes']) : '';
            $history_insert = "INSERT INTO payment_history 
                               (order_id, invoice_id, payment_type, amount, previous_amount_paid, new_amount_paid, 
                                previous_balance, new_balance, payment_status_before, payment_status_after, 
                                recorded_by, notes)
                               VALUES 
                               ($order_id, $invoice_id, '$payment_type', $payment_amount, $prev_amount_paid, $new_paid, 
                                $prev_balance_due, $new_balance, '$prev_payment_status', '$new_payment_status', 
                                '$admin_id', '$payment_notes')";
            if (!mysqli_query($conn, $history_insert)) {
                throw new Exception('Failed to log payment history: ' . mysqli_error($conn));
            }

            // determine status after payment (either derived or explicitly requested)
            $order_new_status = '';
            if (!empty($_POST['status_after_payment'])) {
                $order_new_status = clean($_POST['status_after_payment']);
            } else {
                // default behavior: move to processing when fully paid, otherwise mark partial
                if ($new_paid >= floatval($info['total_amount'])) {
                    $order_new_status = 'pending';
                } else {
                    $order_new_status = 'partial_payment';
                }
            }

            if (!empty($order_new_status)) {
                mysqli_query($conn, "UPDATE orders SET order_status = '$order_new_status' WHERE order_id = $order_id");
            }

            // Fetch user email info for notification
            $user_q = "SELECT u.email, u.full_name FROM orders o 
                      LEFT JOIN users u ON o.user_id = u.user_id 
                      WHERE o.order_id = $order_id LIMIT 1";
            $user_res = mysqli_query($conn, $user_q);
            $user_data = $user_res ? mysqli_fetch_assoc($user_res) : null;

            mysqli_commit($conn);
            
            // Send email notification for payment
            if ($user_data && !empty($user_data['email'])) {
                require_once '../config/EmailHelper.php';
                $emailHelper = new EmailHelper();
                
                $email_subject = 'Payment Received - Order #' . str_pad($order_id, 6, '0', STR_PAD_LEFT);
                $payment_status_text = ($new_payment_status === 'paid') ? 'Fully Paid' : 'Partially Paid';
                
                $email_body = "
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: linear-gradient(135deg, #61B087 0%, #4e8d6c 100%); color: white; padding: 20px; border-radius: 10px 10px 0 0; text-align: center; }
                        .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                        .payment-box { background: white; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; }
                        .highlight { color: #61B087; font-weight: bold; }
                        .table-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>Payment Received</h2>
                        </div>
                        <div class='content'>
                            <p>Hello <strong>{$user_data['full_name']}</strong>,</p>
                            <p>Thank you for your payment!</p>
                            <div class='payment-box'>
                                <p><strong>Order #:</strong> <span class='highlight'>" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . "</span></p>
                                <div class='table-row'>
                                    <span>Payment Amount:</span>
                                    <span class='highlight'>" . formatCurrency($payment_amount) . "</span>
                                </div>
                                <div class='table-row'>
                                    <span>Total Amount:</span>
                                    <span>" . formatCurrency($info['total_amount']) . "</span>
                                </div>
                                <div class='table-row'>
                                    <span>Amount Paid:</span>
                                    <span class='highlight'>" . formatCurrency($new_paid) . "</span>
                                </div>
                                <div class='table-row'>
                                    <span>Balance Remaining:</span>
                                    <span>" . ($new_balance > 0 ? '<span class=\"highlight\">' . formatCurrency($new_balance) . '</span>' : 'Fully Paid') . "</span>
                                </div>
                                <div class='table-row'>
                                    <span>Payment Status:</span>
                                    <span class='highlight'>" . $payment_status_text . "</span>
                                </div>
                            </div>
                            " . ($new_balance > 0 ? '<p>Please pay the remaining balance of <strong>' . formatCurrency($new_balance) . '</strong> to complete your order.</p>' : '<p>Your order is fully paid! We will begin processing it shortly.</p>') . "
                            <p>Thank you for choosing UniNeeds!</p>
                        </div>
                    </div>
                </body>
                </html>
                ";
                $emailHelper->sendEmail($user_data['email'], $user_data['full_name'], $email_subject, $email_body);
            }

            // redirect after successful payment so filters (if any) are cleared
            $_SESSION['success'] = 'Payment information updated successfully.';
            header('Location: orders.php');
            exit;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = 'Failed to update payment: ' . $e->getMessage();
        }
    }

    // STATUS UPDATE
    if (isset($_POST['update_status'])) {
        $order_id = clean($_POST['order_id']);
        $status = clean($_POST['status']);
        
        // NEW: Check if this is a payment confirmation and update invoice status if needed
        // downpayment amount recorded by admin when moving from pending_payment to pending
        $downpayment_amount = isset($_POST['downpayment_amount']) ? floatval($_POST['downpayment_amount']) : 0;
    

        // Start transaction so status change and any stock restores are atomic
        mysqli_begin_transaction($conn);
        try {
        // Fetch current status and payment method/status for logic
        // MODIFIED: Select from both orders and invoices, plus invoice balances and user email
        // Fetch current order + latest invoice row for correct balances
        $cur_q = "SELECT o.order_status, o.user_id, o.payment_method, o.total_amount, ";
        $cur_q .= "i.payment_status, i.amount_paid, i.balance_due, i.down_payment_due, u.email, u.full_name ";
        $cur_q .= "FROM orders o ";
        $cur_q .= "LEFT JOIN ( ";
        $cur_q .= "    SELECT inv.* FROM invoices inv ";
        $cur_q .= "    JOIN (SELECT order_id, MAX(invoice_id) AS max_invoice_id FROM invoices GROUP BY order_id) latest ";
        $cur_q .= "    ON inv.order_id = latest.order_id AND inv.invoice_id = latest.max_invoice_id ";
        $cur_q .= ") i ON o.order_id = i.order_id ";
        $cur_q .= "LEFT JOIN users u ON o.user_id = u.user_id ";
        $cur_q .= "WHERE o.order_id = $order_id FOR UPDATE";
        $cur_res = mysqli_query($conn, $cur_q);
        $cur_row = $cur_res ? mysqli_fetch_assoc($cur_res) : null;
        $previous_status = $cur_row ? $cur_row['order_status'] : null;
        $previous_payment_status = $cur_row ? $cur_row['payment_status'] : null;
        $order_user_id = $cur_row ? $cur_row['user_id'] : null;

        // if admin supplied a downpayment amount, make sure it doesn't exceed what is due
        if ($downpayment_amount > 0 && $cur_row && isset($cur_row['balance_due']) && $downpayment_amount > floatval($cur_row['balance_due'])) {
            throw new Exception('Downpayment amount cannot exceed remaining balance.');
        }

        // prevent cancelling if any payment has been made
        if ($status === 'cancelled' && ($previous_payment_status !== 'unpaid' || (!empty($cur_row['amount_paid']) && floatval($cur_row['amount_paid']) > 0))) {
            throw new Exception('Cannot cancel an order that has already been paid');
        }

        // --- Payment / status verification logic ---
        if ($previous_status === 'pending_payment' && $status === 'pending' && $downpayment_amount > 0) {
             // Admin recorded cash downpayment. Update invoice figures accordingly
             $new_payment_status = 'downpayment_paid';
             
             // Get current invoice info for history logging
             $inv_q = "SELECT invoice_id, amount_paid, balance_due FROM invoices WHERE order_id = $order_id ORDER BY invoice_id DESC LIMIT 1";
             $inv_res = mysqli_query($conn, $inv_q);
             $inv_data = mysqli_fetch_assoc($inv_res);
             $prev_paid = floatval($inv_data['amount_paid']);
             $prev_bal = floatval($inv_data['balance_due']);
             $prev_invoice_id = intval($inv_data['invoice_id']);
             $new_paid = $prev_paid + $downpayment_amount;
             $new_bal = max($prev_bal - $downpayment_amount, 0);
             
             $update_invoice = "UPDATE invoices \
                                SET payment_status = '$new_payment_status', \
                                    amount_paid = amount_paid + $downpayment_amount, \
                                    balance_due = balance_due - $downpayment_amount, \
                                    down_payment_due = GREATEST(down_payment_due - $downpayment_amount,0), \
                                    remaining_balance = remaining_balance - $downpayment_amount,
                                    payment_date = NOW() \
                                WHERE order_id = $order_id";
             if (!mysqli_query($conn, $update_invoice)) {
                 throw new Exception('Failed to update invoice payment status: ' . mysqli_error($conn));
             }
             
             // Log to payment_history
             $admin_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
             $history_insert = "INSERT INTO payment_history 
                                (order_id, invoice_id, payment_type, amount, previous_amount_paid, new_amount_paid, 
                                 previous_balance, new_balance, payment_status_before, payment_status_after, 
                                 recorded_by, notes)
                                VALUES 
                                ($order_id, $prev_invoice_id, 'downpayment', $downpayment_amount, $prev_paid, $new_paid, 
                                 $prev_bal, $new_bal, '$previous_payment_status', '$new_payment_status', 
                                 '$admin_id', 'Downpayment recorded with status change to pending')";
             mysqli_query($conn, $history_insert);
             // Status is now 'pending' (processing) which is the new submitted status.
        } elseif ($status === 'partial_payment') {
             // Admin marked as partial payment; record payment if provided (falls back to status-only change)
             if ($downpayment_amount > 0) {
                 // Get current invoice info for history logging
                 $inv_q = "SELECT invoice_id, amount_paid, balance_due, payment_status FROM invoices WHERE order_id = $order_id ORDER BY invoice_id DESC LIMIT 1";
                 $inv_res = mysqli_query($conn, $inv_q);
                 $inv_data = mysqli_fetch_assoc($inv_res);
                 $prev_paid = floatval($inv_data['amount_paid']);
                 $prev_bal = floatval($inv_data['balance_due']);
                 $prev_pay_status = $inv_data['payment_status'];
                 $prev_invoice_id = intval($inv_data['invoice_id']);
                 
                 $new_paid = $prev_paid + $downpayment_amount;
                 $new_balance = max($prev_bal - $downpayment_amount, 0);
                 $new_down_due = max(floatval($cur_row['down_payment_due'] ?? 0) - $downpayment_amount, 0);
                 $new_remaining = max(floatval($cur_row['remaining_balance'] ?? $new_balance) - $downpayment_amount, 0);
                 // If we've paid the full total, mark it fully paid
                 $new_payment_status = ($new_balance <= 0) ? 'paid' : 'downpayment_paid';
                 $update_invoice = "UPDATE invoices SET payment_status = '$new_payment_status', amount_paid = $new_paid, balance_due = $new_balance, down_payment_due = $new_down_due, remaining_balance = $new_remaining, payment_date = NOW() WHERE order_id = $order_id";
                 if (!mysqli_query($conn, $update_invoice)) {
                     throw new Exception('Failed to update invoice payment status: ' . mysqli_error($conn));
                 }
                 
                 // Log to payment_history
                 $admin_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
                 $history_insert = "INSERT INTO payment_history 
                                    (order_id, invoice_id, payment_type, amount, previous_amount_paid, new_amount_paid, 
                                     previous_balance, new_balance, payment_status_before, payment_status_after, 
                                     recorded_by, notes)
                                    VALUES 
                                    ($order_id, $prev_invoice_id, 'additional_payment', $downpayment_amount, $prev_paid, $new_paid, 
                                     $prev_bal, $new_balance, '$prev_pay_status', '$new_payment_status', 
                                     '$admin_id', 'Payment recorded with partial payment status')";
                 mysqli_query($conn, $history_insert);
             } else {
                 $inv_q = "SELECT payment_status FROM invoices WHERE order_id = $order_id ORDER BY invoice_id DESC LIMIT 1";
                 $inv_res = mysqli_query($conn, $inv_q);
                 $inv_data = mysqli_fetch_assoc($inv_res);
                 if ($inv_data['payment_status'] !== 'downpayment_paid') {
                     mysqli_query($conn, "UPDATE invoices SET payment_status = 'downpayment_paid' WHERE order_id = $order_id");
                 }
             }
        } elseif ($status === 'pending' && $downpayment_amount > 0) {
             // If recording payment while moving order to processing, update invoice amounts as well
             // Get current invoice info for history logging
             $inv_q = "SELECT invoice_id, amount_paid, balance_due, payment_status FROM invoices WHERE order_id = $order_id ORDER BY invoice_id DESC LIMIT 1";
             $inv_res = mysqli_query($conn, $inv_q);
             $inv_data = mysqli_fetch_assoc($inv_res);
             $prev_paid = floatval($inv_data['amount_paid']);
             $prev_bal = floatval($inv_data['balance_due']);
             $prev_pay_status = $inv_data['payment_status'];
             $prev_invoice_id = intval($inv_data['invoice_id']);
             
             $new_paid = $prev_paid + $downpayment_amount;
             $new_balance = max($prev_bal - $downpayment_amount, 0);
             $new_down_due = max(floatval($cur_row['down_payment_due'] ?? 0) - $downpayment_amount, 0);
             $new_remaining = max(floatval($cur_row['remaining_balance'] ?? $new_balance) - $downpayment_amount, 0);
             $new_payment_status = ($new_balance <= 0) ? 'paid' : 'downpayment_paid';
             $update_invoice = "UPDATE invoices SET payment_status = '$new_payment_status', amount_paid = $new_paid, balance_due = $new_balance, down_payment_due = $new_down_due, remaining_balance = $new_remaining, payment_date = NOW() WHERE order_id = $order_id";
             if (!mysqli_query($conn, $update_invoice)) {
                 throw new Exception('Failed to update invoice payment status: ' . mysqli_error($conn));
             }
             
             // Log to payment_history
             $admin_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
             $payment_type = ($prev_paid == 0) ? 'downpayment' : 'additional_payment';
             $history_insert = "INSERT INTO payment_history 
                                (order_id, invoice_id, payment_type, amount, previous_amount_paid, new_amount_paid, 
                                 previous_balance, new_balance, payment_status_before, payment_status_after, 
                                 recorded_by, notes)
                                VALUES 
                                ($order_id, $prev_invoice_id, '$payment_type', $downpayment_amount, $prev_paid, $new_paid, 
                                 $prev_bal, $new_balance, '$prev_pay_status', '$new_payment_status', 
                                 '$admin_id', 'Payment recorded with status change to pending')";
             mysqli_query($conn, $history_insert);
        } elseif ($status === 'completed') {
             // If marking as completed, set invoice to paid and record payment amounts (assume final payment collected)
             // Get current invoice info for history logging
             $inv_q = "SELECT invoice_id, amount_paid, balance_due, payment_status FROM invoices WHERE order_id = $order_id ORDER BY invoice_id DESC LIMIT 1";
             $inv_res = mysqli_query($conn, $inv_q);
             $inv_data = mysqli_fetch_assoc($inv_res);
             $prev_paid = floatval($inv_data['amount_paid']);
             $prev_pay_status = $inv_data['payment_status'];
             $prev_invoice_id = intval($inv_data['invoice_id']);
             
             $update_invoice = "UPDATE invoices i 
                                JOIN orders o ON i.order_id = o.order_id
                                SET i.payment_status = 'paid', 
                                    i.amount_paid = o.total_amount, 
                                    i.balance_due = 0,
                                    i.remaining_balance = 0,
                                    i.payment_date = NOW()
                                WHERE i.order_id = $order_id AND i.payment_status != 'paid'";
             if (!mysqli_query($conn, $update_invoice)) {
                 throw new Exception('Failed to update invoice payment status to paid: ' . mysqli_error($conn));
             }
             
             // Log the completion payment to payment_history
             if ($prev_pay_status !== 'paid') {
                 $admin_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
                 $final_payment_amount = floatval($cur_row['total_amount']) - $prev_paid;
                 $history_insert = "INSERT INTO payment_history 
                                    (order_id, invoice_id, payment_type, amount, previous_amount_paid, new_amount_paid, 
                                     previous_balance, new_balance, payment_status_before, payment_status_after, 
                                     recorded_by, notes)
                                    VALUES 
                                    ($order_id, $prev_invoice_id, 'full_payment', $final_payment_amount, $prev_paid, " . floatval($cur_row['total_amount']) . ", 
                                     " . floatval($cur_row['balance_due']) . ", 0, '$prev_pay_status', 'paid', 
                                     '$admin_id', 'Order marked as completed - final payment recorded')";
                 mysqli_query($conn, $history_insert);
             }
        }
        
        // Update order status
        // If status is "ready for pickup", set due_date to 1 week (business days) from now
        if ($status === 'ready for pickup') {
            // helper to advance business days skipping weekends
            function addBusinessDays($start, $days) {
                $current = strtotime($start);
                while ($days > 0) {
                    $current = strtotime('+1 day', $current);
                    $weekday = date('N', $current);
                    if ($weekday < 6) {
                        $days--;
                    }
                }
                return date('Y-m-d H:i:s', $current);
            }
            $due_date = addBusinessDays(date('Y-m-d H:i:s'), 7);
            $query = "UPDATE orders SET order_status = '$status', due_date = '$due_date' WHERE order_id = $order_id";
        } else {
            $query = "UPDATE orders SET order_status = '$status' WHERE order_id = $order_id";
        }
        
        if (!mysqli_query($conn, $query)) {
            throw new Exception('Failed to update order status: ' . mysqli_error($conn));
        }

        // If status changed to cancelled and previous status wasn't cancelled, restore stock
        if ($status === 'cancelled' && $previous_status !== 'cancelled') {
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

        $message = "Your order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . " has been updated to: " . ucfirst(str_replace('_', ' ', $status));
        $msg_esc = mysqli_real_escape_string($conn, $message);
        $notif_query = "INSERT INTO notifications (user_id, message, type, order_id) VALUES ({$order_user_id}, '$msg_esc', 'order_update', $order_id)";
        mysqli_query($conn, $notif_query);

        mysqli_commit($conn);
        
        if ($cur_row && !empty($cur_row['email'])) {
            require_once '../config/EmailHelper.php';
            $emailHelper = new EmailHelper();
            
            $status_label = str_replace('_', ' ', $status);
            $email_subject = 'Order Status Updated - Order #' . str_pad($order_id, 6, '0', STR_PAD_LEFT);
            
            $status_message = '';
            if ($status === 'ready for pickup') {
                $status_message = 'Your order is ready for pickup! <strong><em>You have 7 days to claim it.</em></strong>';
            } elseif ($status === 'completed') {
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                $receiptUrl = $baseUrl . '/unineed/student/receipt.php?order_id=' . $order_id;
                $downloadUrl = $receiptUrl . '&download=pdf';

                $status_message = "Your order has been claimed. You can view your online receipt <a href=\"{$receiptUrl}\">here</a> or download a PDF copy <a href=\"{$downloadUrl}\">here</a>.";
            } elseif ($status === 'pending_payment') {
                $status_message = 'Please proceed with payment for your order.';
            } elseif ($status === 'pending') {
                $status_message = 'Thank you for your payment! Your order is now being processed.';
            } elseif ($status === 'partial_payment') {
                $status_message = 'We have received your partial payment. Please pay the remaining balance upon claiming your order.';
            } elseif ($status === 'cancelled') {
                $status_message = 'Your order has been cancelled. If you have any questions, please contact us.';
            }
            
            $email_body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: linear-gradient(135deg, #61B087 0%, #4e8d6c 100%); color: white; padding: 20px; border-radius: 10px 10px 0 0; text-align: center; }
                    .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                    .status-box { background: white; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; }
                    .highlight { color: #61B087; font-weight: bold; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Order Status Updated</h2>
                    </div>
                    <div class='content'>
                        <p>Hello <strong>{$cur_row['full_name']}</strong>,</p>
                        <p>Your order status has been updated!</p>
                        <div class='status-box'>
                            <p><strong>Order #:</strong> <span class='highlight'>" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . "</span></p>
                            <p><strong>New Status:</strong> <span class='highlight'>" . ucfirst($status_label)  . "</span></p>
                            <p><strong>Total Amount:</strong> " . formatCurrency($cur_row['total_amount']) . "</p>
                        </div>
                        <p>$status_message</p>
                        <p>Check your order status anytime by logging into your UniNeeds account.</p>
                        <p>Thank you!</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            $emailHelper->sendEmail($cur_row['email'], $cur_row['full_name'], $email_subject, $email_body);
        }

        $_SESSION['success'] = "Order status updated successfully!";
        header('Location: orders.php');
        exit;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error = "Failed to update order status: " . $e->getMessage();
    }
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

$status_filter = isset($_GET['status']) ? clean($_GET['status']) : '';
$search = isset($_GET['search']) ? clean($_GET['search']) : '';
$date_from = isset($_GET['date_from']) ? clean($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? clean($_GET['date_to']) : '';

$set_status_order = isset($_GET['set_status']) ? intval($_GET['set_status']) : 0;
$record_payment_order = isset($_GET['record_payment']) ? intval($_GET['record_payment']) : 0;

$where_clauses = [];
if ($status_filter) {
    if ($status_filter === 'pending_payment') {
        $where_clauses[] = "o.order_status IN ('pending_payment','partial_payment')";
    } else {
        $where_clauses[] = "o.order_status = '$status_filter'";
    }
} else {
    $where_clauses[] = "o.order_status NOT IN ('completed','cancelled')";
}
if ($search) {
    $where_clauses[] = "(u.full_name LIKE '%$search%' OR u.email LIKE '%$search%' OR o.order_id LIKE '%$search%')";
}
if (!empty($date_from) && !empty($date_to)) {
    $where_clauses[] = "DATE(o.created_at) BETWEEN '$date_from' AND '$date_to'";
} elseif (!empty($date_from)) {
    $where_clauses[] = "DATE(o.created_at) >= '$date_from'";
} elseif (!empty($date_to)) {
    $where_clauses[] = "DATE(o.created_at) <= '$date_to'";
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$query = "SELECT o.*, o.created_at AS order_date, u.full_name, u.email, u.phone, i.payment_status, i.balance_due, i.amount_paid, i.initial_down_payment
          FROM orders o
          JOIN users u ON o.user_id = u.user_id
          LEFT JOIN (
              SELECT inv.*
              FROM invoices inv
              JOIN (
                  SELECT order_id, MAX(invoice_id) AS max_invoice_id
                  FROM invoices
                  GROUP BY order_id
              ) latest ON inv.order_id = latest.order_id AND inv.invoice_id = latest.max_invoice_id
          ) i ON o.order_id = i.order_id
          $where_sql
          ORDER BY o.created_at ASC";
$orders = mysqli_query($conn, $query);

$ordersArray = [];
if ($orders) {
    while ($r = mysqli_fetch_assoc($orders)) {
        $ordersArray[] = $r;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders - UniNeeds Admin</title>
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
        .table td,
        .table th {
            padding: 0.2rem 0.3rem;
            vertical-align: middle;
            font-size: 0.9rem;
        }
        .table {
            table-layout: fixed;
            width: 100%;
        }
        .table thead th {
            white-space: nowrap;
        }
        .table tbody td {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: normal;
            word-break: break-word;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-bar d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-link d-md-none" id="sidebarToggle">
                    <i class="bi bi-list fs-3"></i>
                </button>
                <h2 class="mb-0">Orders Management</h2>
            </div>
            <div class="d-flex gap-2">
                <a href="orders.php?status=completed" class="btn btn-success">
                    <i class="bi bi-check2-circle me-2"></i>Completed
                </a>
                <a href="orders.php?status=cancelled" class="btn btn-danger">
                    <i class="bi bi-ban me-2"></i>Cancelled
                </a>
                <a href="orders-due-today.php" class="btn btn-warning">
                    <i class="bi bi-alarm me-2"></i>Due Soon
                </a>
            </div>
        </div>
        
        <div class="content-area">

            <div class="filter-bar mb-3">
                <form id="ordersFilterForm" method="GET" class="row g-2 align-items-end">
                    <div class="col-12 col-md-4">
                        <label class="form-label small mb-1">Search</label>
                        <input type="text" class="form-control form-control-sm" name="search" placeholder="Customer, email, or order ID" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-12 col-sm-6 col-md-2">
                        <label class="form-label small mb-1">Status</label>
                        <select class="form-select form-select-sm" name="status">
                            <option value="">All Status</option>
                            <option value="pending_payment" <?php echo $status_filter === 'pending_payment' ? 'selected' : ''; ?>>Pending Payment</option>
                            <option value="partial_payment" <?php echo $status_filter === 'partial_payment' ? 'selected' : ''; ?>>Partial Payment / Processing</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Processing</option>
                            <option value="ready for pickup" <?php echo $status_filter === 'ready for pickup' ? 'selected' : ''; ?>>Ready for Pickup</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-md-2">
                        <label class="form-label small mb-1">From</label>
                        <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="col-12 col-sm-6 col-md-2">
                        <label class="form-label small mb-1">To</label>
                        <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-12 col-sm-6 col-md-2 d-flex gap-2">
                        <a href="orders.php" class="btn btn-sm btn-outline-secondary flex-grow-1">
                            <i class="bi bi-x-circle me-1"></i>Clear
                        </a>
                    </div>
                </form>
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
            
            <?php
            // conditional action panels based on GET parameters (view handled inline now)
            if ($set_status_order) {
                $ord_id = $set_status_order;
                
                $ord_id = $set_status_order;
                ?>
                <?php
                // show some extra information for context
                $status_detail = null;
                // Use latest invoice row to ensure amounts (balance/downpayment/paid) stay consistent
                $status_q = "SELECT o.order_status, o.total_amount, i.amount_paid, i.balance_due, i.down_payment_due, i.remaining_balance
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
                              WHERE o.order_id = $ord_id LIMIT 1";
                $status_res = mysqli_query($conn, $status_q);
                if ($status_res) {
                    $status_detail = mysqli_fetch_assoc($status_res);
                }
            ?>
            <!-- status update modal -->
            <div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
              <div class="modal-dialog modal-md modal-dialog-centered">
                <div class="modal-content">
                  <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="statusModalLabel">Update Status for Order #<?php echo str_pad($ord_id,6,'0',STR_PAD_LEFT); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                    <div class="modal-body py-4">
                    <form method="POST" action="orders.php">
                        <input type="hidden" name="order_id" value="<?php echo $ord_id; ?>">
                        <?php if ($status_detail): 
                            // define labels and badge classes for display
                            $pending_label = 'Processing';
                            if ($status_detail['order_status'] === 'pending') {
                                $is_fully_paid = in_array($status_detail['payment_status'] ?? '', ['paid','fully_paid'])
                                    || (floatval($status_detail['amount_paid'] ?? 0) >= floatval($status_detail['total_amount'] ?? 0));
                                if ($is_fully_paid && orderHasPreorderItems($conn, $ord_id)) {
                                    $pending_label = 'Paid / Processing';
                                }
                            }

                            $labels = [
                                'pending_payment'=>'To Pay',
                                'partial_payment'=>'DP/Partial',
                                'pending'=>$pending_label,
                                'ready for pickup'=>'Ready for Pickup',
                                'completed'=>'Completed',
                                'cancelled'=>'Cancelled'
                            ];
                            $badge_class = [
                                'pending_payment'=>'warning',
                                'partial_payment'=>'warning',
                                'pending'=>'warning',
                                'ready for pickup'=>'info',
                                'completed'=>'success',
                                'cancelled'=>'danger'
                            ];
                            $cur = $status_detail['order_status'];
                        ?>
                        <div class="mb-4">
                            <label class="form-label">Current Status</label>
                            <p class="mb-0">
                                <span class="badge bg-<?php echo $badge_class[$cur] ?? 'secondary'; ?>">
                                    <?php echo htmlspecialchars($labels[$cur] ?? ucfirst(str_replace('_',' ',$cur))); ?>
                                </span>
                            </p>
                        </div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label">New Status</label>
                            <select id="statusSelect" name="status" class="form-select" required>
                                <?php
                                    $options = [
                                        'pending_payment'=>'To Pay',
                                        'partial_payment'=>'DP/Partial',
                                        'pending'=>'Processing',
                                        'ready for pickup'=>'Ready for Pickup',
                                        'completed'=>'Completed',
                                        'cancelled'=>'Cancelled'
                                    ];
                                    foreach ($options as $k=>$v) {
                                        $sel = ($status_detail && $status_detail['order_status'] === $k) ? 'selected' : '';
                                        echo "<option value=\"$k\" $sel>$v</option>";
                                    }
                                ?>
                            </select>
                        </div>
                        <input type="hidden" id="statusBalanceDue" value="<?php echo floatval($status_detail['balance_due'] ?? 0); ?>">
                        <div class="text-end">
                            <button type="submit" name="update_status" class="btn btn-primary">Submit</button>
                            <a href="orders.php" class="btn btn-secondary ms-2">Cancel</a>
                        </div>
                    </form>
                  </div>
                </div>
              </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var m = new bootstrap.Modal(document.getElementById('statusModal'));
                    m.show();

                    var statusForm = document.querySelector('#statusModal form');
                    var statusSelect = document.getElementById('statusSelect');
                    var balanceDue = parseFloat(document.getElementById('statusBalanceDue').value || 0);

                    function openPaymentModal(selectedStatus) {
                        // ensure payment modal is present
                        var paymentModal = document.getElementById('paymentModal');
                        if (!paymentModal) return;

                        // set the status we want to apply after recording payment
                        var statusAfter = document.getElementById('statusAfterPayment');
                        if (statusAfter) {
                            statusAfter.value = selectedStatus;
                        }

                        // update max in payment modal input if present
                        var paymentInput = paymentModal.querySelector('input[name="payment_amount"]');
                        var helpText = paymentModal.querySelector('.form-text');
                        if (paymentInput) {
                            paymentInput.max = balanceDue;
                        }
                        if (helpText) {
                            helpText.textContent = 'Cannot exceed remaining balance ' + new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(balanceDue) + '.';
                        }

                        var pm = new bootstrap.Modal(paymentModal);
                        pm.show();
                    }

                    statusForm.addEventListener('submit', function(event) {
                        var status = statusSelect.value;
                        // intercept and ask for payment when changing to partial_payment or pending
                        if (status === 'partial_payment' || status === 'pending') {
                            event.preventDefault();
                            openPaymentModal(status);
                        }
                    });
                });
            </script>

            <?php if ($status_detail): ?>
                <!-- payment modal (used when doing status change that requires recording payment) -->
                <div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                      <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title" id="paymentModalLabel">Record Payment for Order #<?php echo str_pad($ord_id,6,'0',STR_PAD_LEFT); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-3"><strong>Total:</strong> <?php echo formatCurrency($status_detail['total_amount'] ?? 0); ?></div>
                            <div class="col-md-3"><strong>Downpayment Required:</strong> <?php echo formatCurrency($status_detail['down_payment_due'] ?? 0); ?></div>
                            <div class="col-md-3"><strong>Already Paid:</strong> <?php echo formatCurrency($status_detail['amount_paid'] ?? 0); ?></div>
                            <div class="col-md-3"><strong>Balance:</strong> <?php echo formatCurrency($status_detail['balance_due'] ?? 0); ?></div>
                        </div>
                        <form method="POST" action="orders.php">
                            <input type="hidden" name="order_id" value="<?php echo $ord_id; ?>">
                            <input type="hidden" name="status_after_payment" id="statusAfterPayment" value="">
                            <div class="mb-3">
                                <label class="form-label">Amount to Record</label>
                                <input type="number" step="0.01" name="payment_amount" class="form-control" required min="0" max="<?php echo floatval($status_detail['balance_due'] ?? 0); ?>">
                                <div class="form-text">Cannot exceed remaining balance <?php echo formatCurrency($status_detail['balance_due'] ?? 0); ?>.</div>
                            </div>
                            <div class="text-end">
                                <button type="submit" name="update_payment" class="btn btn-primary">Submit</button>
                                <a href="orders.php" class="btn btn-secondary ms-2">Cancel</a>
                            </div>
                        </form>
                      </div>
                    </div>
                  </div>
                </div>
            <?php endif; ?>

                <?php
            } elseif ($record_payment_order) {
                $ord_id = $record_payment_order;
                // load current invoice info for summary (use latest invoice row per order to avoid showing stale/duplicate invoice amounts)
                $pay_q = "SELECT o.total_amount, i.down_payment_due, i.amount_paid, i.balance_due, i.remaining_balance
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
                          WHERE o.order_id = $ord_id LIMIT 1";
                $pay_res = mysqli_query($conn, $pay_q);
                $pay_detail = $pay_res ? mysqli_fetch_assoc($pay_res) : [];
                ?>
            <!-- payment modal -->
            <div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                  <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="paymentModalLabel">Record Payment for Order #<?php echo str_pad($ord_id,6,'0',STR_PAD_LEFT); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <?php if ($pay_detail): ?>
                        <div class="row mb-3">
                            <div class="col-md-3"><strong>Total:</strong> <?php echo formatCurrency($pay_detail['total_amount']); ?></div>
                            <div class="col-md-3"><strong>Downpayment Required:</strong> <?php echo formatCurrency($pay_detail['down_payment_due']); ?></div>
                            <div class="col-md-3"><strong>Already Paid:</strong> <?php echo formatCurrency($pay_detail['amount_paid']); ?></div>
                            <div class="col-md-3"><strong>Balance:</strong> <?php echo formatCurrency($pay_detail['balance_due']); ?></div>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="orders.php">
                        <input type="hidden" name="order_id" value="<?php echo $ord_id; ?>">
                        <input type="hidden" name="status_after_payment" id="statusAfterPayment" value="">
                        <div class="mb-3">
                            <label class="form-label">Amount to Record</label>
                            <input type="number" step="0.01" name="payment_amount" class="form-control" required min="0"
                                   <?php if (isset($pay_detail['balance_due'])): ?>
                                       max="<?php echo $pay_detail['balance_due']; ?>"
                                   <?php endif; ?>
                            >
                            <?php if (isset($pay_detail['balance_due'])): ?>
                                <div class="form-text">Cannot exceed remaining balance <?php echo formatCurrency($pay_detail['balance_due']); ?>.</div>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <button type="submit" name="update_payment" class="btn btn-primary">Submit</button>
                            <a href="orders.php" class="btn btn-secondary ms-2">Cancel</a>
                        </div>
                    </form>
                  </div>
                </div>
              </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var m = new bootstrap.Modal(document.getElementById('paymentModal'));
                    m.show();
                    var input = document.querySelector('input[name="payment_amount"]');
                    if (input && input.max) {
                        input.addEventListener('input', function() {
                            var max = parseFloat(this.max) || 0;
                            var val = parseFloat(this.value) || 0;
                            if (val > max) this.value = max.toFixed(2);
                        });
                    }
                });
            </script>
                <?php
            }
            ?>
            
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:7%;">Order ID</th>
                                    <th style="width:10%;">Customer</th>
                                    <th style="width:9%;">Amount</th>
                                    <th style="width:7%;">Paid</th>
                                    <th style="width:7%;">Balance</th>
                                    <th style="width:12%;">Status</th>
                                    <th style="width:12%;">Date</th>
                                    <th style="width:10%;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($ordersArray) > 0): ?>
                                    <?php foreach ($ordersArray as $order): ?>
                                        <tr class="order-row" data-order-id="<?php echo $order['order_id']; ?>">
                                            <td><strong>#<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></strong></td>
                                            <td>
                                                <?php echo htmlspecialchars($order['full_name']); ?>
                                            </td>
                                            <td><strong><?php echo formatCurrency($order['total_amount']); ?></strong></td>
                                            <td><?php echo formatCurrency($order['amount_paid'] ?? 0); ?></td>
                                            <td><?php echo formatCurrency($order['balance_due'] ?? 0); ?></td>
                                            <td>
                                                <?php
                                                // MODIFIED: Added 'pending_payment'
                                                $badge_class = [
                                                    // mark "to pay" and partial orders with warning
                                                    'pending_payment' => 'warning', 
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

                                                $status_labels = [
                                                    'pending_payment' => 'To Pay',
                                                    'partial_payment' => 'DP/Partial',
                                                    'pending' => $pending_label,
                                                    'ready for pickup' => 'Ready for Pickup',
                                                    'completed' => 'Completed',
                                                    'cancelled' => 'Cancelled'
                                                ];
                                                $order_status_clean = $status_labels[$order['order_status']] ?? ucfirst(str_replace('_', ' ', $order['order_status']));
                                                $current_badge_class = $badge_class[$order['order_status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo $current_badge_class; ?>">
                                                    <?php echo ucfirst($order_status_clean); ?>
                                                </span>
                                                <?php if ($order['order_status'] === 'cancelled' && !empty($order['cancellation_reason'])): ?>
                                                    <div class="small text-muted mt-1">Reason: <?php echo htmlspecialchars(mb_strimwidth($order['cancellation_reason'], 0, 60, '...')); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
    <a href="#" data-bs-toggle="collapse" data-bs-target="#details-<?php echo $order['order_id']; ?>" class="btn btn-sm btn-info btn-action" title="View Details">
        <i class="bi bi-eye"></i>
    </a>
    <?php if ($order['order_status'] !== 'completed' && $order['order_status'] !== 'cancelled'): ?>
        <a href="orders.php?set_status=<?php echo $order['order_id']; ?><?php echo $status_filter ? '&status='.urlencode($status_filter) : ''; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $date_from ? '&date_from='.urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to='.urlencode($date_to) : ''; ?>" class="btn btn-sm btn-primary btn-action" title="Update Status">
            <i class="bi bi-pencil"></i>
        </a>
    <?php endif; ?>
    <?php if (in_array($order['order_status'], ['pending_payment','partial_payment']) || (in_array($order['order_status'], ['pending']) && floatval($order['balance_due'] ?? 0) > 0)): ?>
        <a href="orders.php?record_payment=<?php echo $order['order_id']; ?><?php echo $status_filter ? '&status='.urlencode($status_filter) : ''; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $date_from ? '&date_from='.urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to='.urlencode($date_to) : ''; ?>" class="btn btn-sm btn-warning btn-action" title="Record Payment">
            <i class="bi bi-cash-stack"></i>
        </a>
    <?php endif; ?>
    <?php if ($order['order_status'] === 'completed'): ?>
        <button type="button" onclick="showReceipt(<?php echo $order['order_id']; ?>)" class="btn btn-sm btn-success btn-action" title="View Receipt">
            <i class="bi bi-receipt"></i>
        </button>
    <?php endif; ?>
</div>
                                            </td>
                                        </tr>
                                        <?php
                                        // inline expandable details row
                                        $ord_id = $order['order_id'];
                                        $detail_q = "SELECT o.*, u.full_name, u.email, u.phone, i.payment_status, i.down_payment_due, i.remaining_balance, i.amount_paid, i.balance_due
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
                                                     JOIN users u ON o.user_id = u.user_id
                                                     WHERE o.order_id = $ord_id LIMIT 1";
                                        $detail_res = mysqli_query($conn, $detail_q);
                                        $detail = $detail_res ? mysqli_fetch_assoc($detail_res) : [];
                                        $items_q = "SELECT oi.*, p.product_name, p.image_url, pv.variant_type, COALESCE(pv.variant_value,'') as variant_value
                                                    FROM order_items oi
                                                    LEFT JOIN products p ON oi.product_id = p.product_id
                                                    LEFT JOIN product_variants pv ON oi.variant_id = pv.variant_id
                                                    WHERE oi.order_id = $ord_id";
                                        $items_res = mysqli_query($conn, $items_q);
                                        $items = [];
                                        if ($items_res) {
                                            while ($row = mysqli_fetch_assoc($items_res)) {
                                                $items[] = $row;
                                            }
                                        }
                                        ?>
                                        <tr class="collapse order-details-row" id="details-<?php echo $ord_id; ?>">
                                          <td colspan="8">
                                            <div class="order-details-content">
                                              <div class="row mb-4">
                                                <div class="col-md-6">
                                                  <h6>Customer Info</h6>
                                                  <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($detail['full_name']); ?></p>
                                                  <p class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars($detail['email']); ?></p>
                                                  <p class="mb-1"><strong>Phone:</strong> <?php echo htmlspecialchars($detail['phone']); ?></p>
                                                </div>
                                                <div class="col-md-6">
                                                  <h6>Order Info</h6>
                                                  <p class="mb-1"><strong>Status:</strong> <?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$detail['order_status']))); ?></p>
                                                  <p class="mb-1"><strong>Placed:</strong> <?php echo date('M j, Y g:i A', strtotime($detail['created_at'])); ?></p>
                                                </div>
                                              </div>
                                              <h6>Items</h6>
                                              <div class="table-responsive">
                                                <table class="table table-sm table-bordered">
                                                  <thead class="table-light">
                                                    <tr>
                                                      <th>Product</th>
                                                      <th>Variant</th>
                                                      <th class="text-end">Price</th>
                                                      <th class="text-center">Qty</th>
                                                      <th class="text-end">Subtotal</th>
                                                    </tr>
                                                  </thead>
                                                  <tbody>
                                                    <?php foreach ($items as $it): ?>
                                                      <?php
                                                      $variant_text = '';
                                                      // prefer explicit type/value from join
                                                      if (!empty($it['variant_type']) || !empty($it['variant_value'])) {
                                                          if (!empty($it['variant_type']) && !empty($it['variant_value'])) {
                                                              $variant_text = $it['variant_type'] . ': ' . $it['variant_value'];
                                                          } elseif (!empty($it['variant_value'])) {
                                                              // fallback to decode in case value holds JSON
                                                              $decoded = json_decode($it['variant_value'], true);
                                                              if (is_array($decoded)) {
                                                                  $parts = [];
                                                                  foreach ($decoded as $type => $value) {
                                                                      $parts[] = ucfirst($type) . ': ' . $value;
                                                                  }
                                                                  $variant_text = implode(', ', $parts);
                                                              } else {
                                                                  $variant_text = $it['variant_value'];
                                                              }
                                                          } elseif (!empty($it['variant_type'])) {
                                                              $variant_text = $it['variant_type'];
                                                          }
                                                      }
                                                      // still empty? try one more lookup in case join failed
                                                      if ($variant_text === '' && !empty($it['variant_id'])) {
                                                          $vid = intval($it['variant_id']);
                                                          $vlookup = mysqli_query($conn, "SELECT variant_type, variant_value FROM product_variants WHERE variant_id = $vid LIMIT 1");
                                                          if ($vlookup && mysqli_num_rows($vlookup) > 0) {
                                                              $vrow = mysqli_fetch_assoc($vlookup);
                                                              $variant_text = trim(($vrow['variant_type'] ?? '') . ': ' . ($vrow['variant_value'] ?? '')) ?: 'ID ' . $vid;
                                                          } else {
                                                              $variant_text = 'ID ' . $vid;
                                                          }
                                                      }
                                                      ?>
                                                      <tr>
                                                        <td><?php echo htmlspecialchars($it['product_name'] ?? '[Deleted Product]'); ?></td>
                                                        <td><?php echo $variant_text ? htmlspecialchars($variant_text) : '-'; ?></td>
                                                        <td class="text-end"><?php echo formatCurrency($it['price']); ?></td>
                                                        <td class="text-center"><?php echo $it['quantity']; ?></td>
                                                        <td class="text-end"><?php echo formatCurrency($it['price'] * $it['quantity']); ?></td>
                                                      </tr>
                                                    <?php endforeach; ?>
                                                  </tbody>
                                                  <tfoot>
                                                    <tr>
                                                      <th colspan="4" class="text-end">Total</th>
                                                      <th class="text-end"><?php echo formatCurrency($detail['total_amount']); ?></th>
                                                    </tr>
                                                  </tfoot>
                                                </table>
                                              </div>
                                              <h6 class="mt-4">Financial Summary</h6>
                                              <div class="row">
                                                <div class="col-md-3"><strong>Total Amount:</strong> <?php echo formatCurrency($detail['total_amount'] ?? 0); ?></div>
                                                <div class="col-md-3"><strong>Downpayment Required:</strong> <?php echo formatCurrency($detail['down_payment_due'] ?? $detail['initial_down_payment'] ?? 0); ?></div>
                                                <div class="col-md-3"><strong>Amount Paid:</strong> <?php echo formatCurrency($detail['amount_paid'] ?? 0); ?></div>
                                                <div class="col-md-3"><strong>Balance Due:</strong> <?php echo formatCurrency($detail['balance_due'] ?? 0); ?></div>
                                              </div>
                                              <div class="text-end mt-3">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#details-<?php echo $ord_id; ?>">
                                                    Close
                                                </button>
                                              </div>
                                            </div>
                                          </td>
                                        </tr>
                                            <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10">
                                            <div class="empty-state">
                                                <i class="bi bi-cart-x"></i>
                                                <h5>No Orders Found</h5>
                                                <p>There are no orders matching your criteria.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>

    <!-- receipt modal -->
    <div class="modal fade" id="receiptModal" tabindex="-1" aria-labelledby="receiptModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-success text-white">
            <h5 class="modal-title" id="receiptModalLabel">Receipt</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-0">
            <iframe id="receiptFrame" style="width:100%;height:80vh;border:none;"></iframe>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-primary" onclick="printReceipt()">Print Receipt</button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

    <script>
        function showReceipt(orderId) {
            var frame = document.getElementById('receiptFrame');
            if (frame) {
                frame.src = '../student/receipt.php?order_id=' + orderId;
            }
            var m = new bootstrap.Modal(document.getElementById('receiptModal'));
            m.show();
        }
        function printReceipt() {
            var f = document.getElementById('receiptFrame');
            if (f && f.contentWindow) {
                f.contentWindow.print();
            }
        }

        // auto-submit filters when changed (debounced)
        (function(){
            const form = document.getElementById('ordersFilterForm');
            if (!form) return;
            let timer;
            const submit = () => form.submit();
            form.querySelectorAll('select, input').forEach(el => {
                el.addEventListener('change', () => {
                    clearTimeout(timer);
                    timer = setTimeout(submit, 300);
                });
                if (el.tagName === 'INPUT' && el.type === 'text') {
                    el.addEventListener('input', () => {
                        clearTimeout(timer);
                        timer = setTimeout(submit, 800);
                    });
                }
            });
        })();
        // toggle expanded class on parent row when collapse shows/hides
        document.addEventListener('DOMContentLoaded', function() {
            var detailRows = document.querySelectorAll('.order-details-row');
            detailRows.forEach(function(el) {
                el.addEventListener('shown.bs.collapse', function() {
                    var btn = document.querySelector('[data-bs-target="#' + el.id + '"]');
                    if (btn && btn.closest('tr')) btn.closest('tr').classList.add('expanded');
                });
                el.addEventListener('hidden.bs.collapse', function() {
                    var btn = document.querySelector('[data-bs-target="#' + el.id + '"]');
                    if (btn && btn.closest('tr')) btn.closest('tr').classList.remove('expanded');
                });
            });
        });
    </script>
</body>
</html>     
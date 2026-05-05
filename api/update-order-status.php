<?php
require_once '../config/database.php';
session_start();

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

// Check if user is an admin
if ($_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Only admins can update order status']);
    exit();
}

// Validate input
if (!isset($_POST['order_id']) || !isset($_POST['status'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$order_id = (int)$_POST['order_id'];
$status = $_POST['status'];

$valid_statuses = ['pending', 'processing', 'completed', 'cancelled'];
if (!in_array($status, $valid_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // Get order details (including user contact info)
    $stmt = $conn->prepare("SELECT o.user_id, o.total_amount, u.email, u.full_name FROM orders o JOIN users u ON o.user_id = u.user_id WHERE o.id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception('Order not found');
    }

    $order = $result->fetch_assoc();
        $message = "Your order status has been updated to: " . ucfirst(str_replace('_', ' ', $status));
    // Update order status
    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $order_id);
    $stmt->execute();

    // Create notification for user
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, is_read) 
        VALUES (?, ?, ?, 0)
    ");
    $title = "Order #$order_id Status Updated";
    $message = "Your order status has been updated to: " . ucfirst($status);
    $stmt->bind_param("iss", $order['user_id'], $title, $message);
    $stmt->execute();

        // If order is ready for pickup, additionally notify user with invoice/payment reminder
        if ($status === 'ready_for_pickup') {
            // Prepare invoice message and link (relative URL)
            $order_total = isset($order['total_amount']) ? number_format($order['total_amount'], 2) : '0.00';
        $invoiceLink = '/unineed/student/invoice.php?order_id=' . $order_id;
        $readyTitle = "Order #$order_id Ready for Pickup";
        $readyMessage = "Your order is ready for pickup. Please settle payment of ₱" . $order_total . ". View your invoice: " . $invoiceLink . "";

            $stmt2 = $conn->prepare("INSERT INTO notifications (user_id, title, message, is_read) VALUES (?, ?, ?, 0)");
            $stmt2->bind_param("iss", $order['user_id'], $readyTitle, $readyMessage);
            $stmt2->execute();
        }
    // If order is cancelled, restore stock
    if ($status === 'cancelled') {
        $stmt = $conn->prepare("
            UPDATE products p 
            JOIN order_details od ON p.id = od.product_id 
            SET p.stock = p.stock + od.quantity 
            WHERE od.order_id = ?
        ");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
    }

    // Commit transaction
    $conn->commit();

    // Send receipt confirmation to customer when order is marked as completed
    if ($status === 'completed' && !empty($order['email'])) {
        require_once '../config/EmailHelper.php';
        $emailHelper = new EmailHelper();

        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $receiptUrl = $baseUrl . '/unineed/student/receipt.php?order_id=' . $order_id;
        

        $formattedOrderId = str_pad($order_id, 6, '0', STR_PAD_LEFT);
        $email_subject = "Order Received - Order #$formattedOrderId";
        $email_body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #61B087 0%, #4e8d6c 100%); color: white; padding: 20px; border-radius: 10px 10px 0 0; text-align: center; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; padding: 10px 18px; background: #61B087; color: #fff; border-radius: 6px; text-decoration: none; margin-top: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Order Received</h2>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$order['full_name']}</strong>,</p>
                    <p>Thank you! Your order <strong>#$formattedOrderId</strong> has been marked as <strong>Completed</strong>.</p>
                    <p>Your online receipt is available here:</p>
                    <p><a class='button' href='{$receiptUrl}'>View Receipt</a></p>
                    <p>If you need a copy of your receipt, you can download it from the receipt page.</p>
                </div>
            </div>
        </body>
        </html>
        ";

        $emailHelper->sendEmail($order['email'], $order['full_name'], $email_subject, $email_body);
    }

    echo json_encode(['success' => true, 'message' => 'Order status updated']);
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// Function to format currency with peso sign
function formatPeso($amount) {
    return '₱' . number_format($amount, 2);
}

requireLogin(); // Allow both students and admins

if (!isset($_GET['order_id'])) {
    header('Location: orders.php');
    exit;
}

// PDF download option removed; always render the online receipt
$output_pdf = false;

$order_id = intval($_GET['order_id']);

// Get order details
if (isAdmin()) {
    $order_query = "SELECT o.*, u.full_name, u.email, u.phone 
                    FROM orders o 
                    JOIN users u ON o.user_id = u.user_id 
                    WHERE o.order_id = ?";
    $stmt = mysqli_prepare($conn, $order_query);
    mysqli_stmt_bind_param($stmt, "i", $order_id);
} else {
    $order_query = "SELECT o.*, u.full_name, u.email, u.phone 
                    FROM orders o 
                    JOIN users u ON o.user_id = u.user_id 
                    WHERE o.order_id = ? AND o.user_id = ?";
    $stmt = mysqli_prepare($conn, $order_query);
    mysqli_stmt_bind_param($stmt, "ii", $order_id, $_SESSION['user_id']);
}
mysqli_stmt_execute($stmt);
$order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$order) {
    header('Location: orders.php');
    exit;
}

// Determine order status label / badge for receipt design
$statusLabels = [
    'pending_payment' => 'Pending Payment',
    'partial_payment' => 'Partial Payment',
    'pending' => 'Processing',
    'ready for pickup' => 'Ready for Pickup',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];
$badgeClasses = [
    'pending_payment' => 'secondary',
    'partial_payment' => 'warning',
    'pending' => 'warning',
    'ready for pickup' => 'info',
    'completed' => 'success',
    'cancelled' => 'danger',
];
$orderStatusKey = $order['order_status'] ?? '';
$orderStatusLabel = $statusLabels[$orderStatusKey] ?? ucfirst(str_replace('_', ' ', $orderStatusKey));
$orderStatusBadge = $badgeClasses[$orderStatusKey] ?? 'secondary';

// Get latest invoice/payment info for breakdown (if applicable)
$invoice = null;
$invoice_stmt = mysqli_prepare($conn, "SELECT * FROM invoices WHERE order_id = ? ORDER BY invoice_id DESC LIMIT 1");
if ($invoice_stmt) {
    mysqli_stmt_bind_param($invoice_stmt, "i", $order_id);
    mysqli_stmt_execute($invoice_stmt);
    $invoice_result = mysqli_stmt_get_result($invoice_stmt);
    if ($invoice_result && mysqli_num_rows($invoice_result) > 0) {
        $invoice = mysqli_fetch_assoc($invoice_result);
    }
}

$amountPaid = floatval($invoice['amount_paid'] ?? 0);
$totalAmount = floatval($order['total_amount'] ?? 0);
$balanceDue = max($totalAmount - $amountPaid, 0);

// Get order items
$items_query = "SELECT oi.*, p.product_name, pv.variant_type, pv.variant_value 
                FROM order_items oi 
                JOIN products p ON oi.product_id = p.product_id 
                LEFT JOIN product_variants pv ON oi.variant_id = pv.variant_id 
                WHERE oi.order_id = ?";
$stmt = mysqli_prepare($conn, $items_query);
mysqli_stmt_bind_param($stmt, "i", $order_id);
mysqli_stmt_execute($stmt);
$items = mysqli_stmt_get_result($stmt);

// Store items in array for multiple use
$item_list = [];
while ($item = mysqli_fetch_assoc($items)) {
    $item_list[] = $item;
}

// Get payment history
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <?php if (!$output_pdf): ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php endif; ?>
    <title>Receipt #<?php echo $order['order_id']; ?> - UniNeeds</title>
    <style>
        /* Professional receipt layout for screen and print */
        :root {
            --primary: #0ea55f;
            --accent: #0aa04a;
            --muted: #6c757d;
            --bg: #f7f9f8;
            --border: #e9efec;
        }

        body { font-family: Inter, 'Helvetica Neue', Arial, sans-serif; color: #222; margin: 0; padding: 18px; background: transparent; }

        /* Container for copies (print will show two) */
        .copies { display: flex; gap: 20px; justify-content: center; align-items: flex-start; position: relative; }
        .copies::before { content: ''; position: absolute; left: 50%; top: 18px; bottom: 18px; width: 1px; border-left: 1px dashed var(--border); transform: translateX(-50%); }
        .print-only { display: none; }

        /* Single-copy preview for screen */
        .receipt { width: 360px; box-sizing: border-box; background: #fff; padding: 18px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 6px 18px rgba(15, 23, 21, 0.06); }
        .receipt + .receipt { margin-left: 16px; }

        .receipt-header { padding-bottom: 10px; border-bottom: 1px solid #f0f3f2; text-align: center; }
        .receipt-title { display: flex; justify-content: center; align-items: center; gap: 12px; flex-wrap: wrap; }
        .brand { font-size: 20px; font-weight: 700; color: #0b6a38; letter-spacing: 0.2px; margin: 0; }
        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; color: #fff; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-secondary { background: #6c757d; }
        .badge-warning { background: #f0ad4e; }
        .badge-info { background: #17a2b8; }
        .badge-success { background: #198754; }
        .badge-danger { background: #dc3545; }
        .tagline { margin-top: 4px; font-size: 12px; color: var(--muted); font-style: italic; }

        .info-section { display: flex; gap: 12px; margin: 14px 0; }
        .customer-details { flex: 1 1 60%; }
        .invoice-details { flex: 1 1 40%; text-align: right; }
        .info-section h4 { margin: 0 0 6px 0; color: var(--primary); font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .info-section p { margin: 2px 0; font-size: 13px; color: #333; }

        .receipt-table { width: 100%; border-collapse: collapse; margin-top: 8px; border-radius: 6px; overflow: hidden; }
        .receipt-table thead th { background: var(--primary); color: #fff; padding: 10px 12px; font-weight: 700; font-size: 13px; text-align: left; }
        .receipt-table tbody td { padding: 10px 12px; border-bottom: 1px solid #f3f6f4; font-size: 13px; color: #333; }
        .receipt-table tbody tr:last-child td { border-bottom: none; }

        .total-section { margin-top: 14px; padding: 14px; background: linear-gradient(180deg, #fbfff9, #f3f8f6); border: 1px solid #e6f2e9; border-radius: 8px; }
        .total-amount { font-size: 18px; color: var(--accent); text-align: right; font-weight: 800; }
        .payment-breakdown { margin-top: 10px; border-top: 1px solid #e6f2e9; padding-top: 10px; }
        .breakdown-row { display: flex; justify-content: space-between; font-size: 13px; margin-top: 4px; }
        .breakdown-label { color: #4a4f52; }
        .breakdown-value { font-weight: 700; }

        .payment-method { margin-top: 12px; padding: 12px; background: #fff; border-radius: 8px; text-align: center; border: 1px dashed #d6f0db; }
        .receipt-footer { margin-top: 12px; padding-top: 6px; text-align: center; font-size: 12px; color: var(--muted); }

        /* Screen: center a single copy */
        @media screen {
            .copies { justify-content: center; }
            .copies::before { display: none; }
            .receipt { width: 420px; }
            .print-only { display: none !important; }
        }

        /* Print adjustments - two copies side-by-side */
        @media print {
            body { padding: 8px; }
            .copies { gap: 12px; }
            .copies::before { left: 50%; top: 8mm; bottom: 8mm; }
            .print-only { display: block; }
            .receipt { width: 104mm; padding: 6mm; border: none; border-radius: 0; box-shadow: none; }
            .receipt + .receipt { margin-left: 0; }
            /* Cut guide visible when printing/PDF */
            .cut-guide { display: block; margin: 18px auto; width: 100%; max-width: 220mm; border-top: 2px dashed #cfcfcf; height: 1px; position: relative; }
            .cut-guide::before { content: '✂︎ CUT HERE ✂︎'; position: absolute; top: -8px; left: 50%; transform: translateX(-50%); background: #fff; padding: 0 8px; color: var(--muted); font-size: 12px; }
            @page { size: letter; margin: 6mm; }
        }
    </style>
</head>
<body>
    <?php /* Download PDF removed per request - keep modal/iframe Download in admin only */ ?>

    <div class="copies">
    <!-- First (visible in preview and print) -->
    <div class="receipt">
        <div class="receipt-header">
            <div class="receipt-title">
                <h2>UniNeeds Store</h2>
                <span class="status-badge badge-<?= htmlspecialchars($orderStatusBadge); ?>"><?= htmlspecialchars($orderStatusLabel); ?></span>
            </div>
            <h5><p><i>Study ready. Style steady.</i></p></h5>
        </div>

        <div class="info-section">
            <div class="customer-details">
                <h4>Customer Information</h4>
                <p><strong><?php echo htmlspecialchars($order['full_name']); ?></strong></p>
                <p><?php echo htmlspecialchars($order['email']); ?></p>
                <p><?php echo htmlspecialchars($order['phone']); ?></p>
            </div>

            <div class="invoice-details">
                <h4>Invoice Details</h4>
                <p><strong>Invoice #:</strong> <?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></p>
                <p><strong>Date:</strong> <?php echo date('F j, Y', strtotime($order['created_at'])); ?></p>
            </div>
        </div>

        <table class="receipt-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th style="width: 100px;">Quantity</th>
                    <th style="width: 120px;" class="text-end">Price</th>
                    <th style="width: 120px;" class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total = 0;
                foreach ($item_list as $item):
                    $itemTotal = $item['price'] * $item['quantity'];
                    $total += $itemTotal;
                    // build product display with variant if available
                    $productDisplay = htmlspecialchars($item['product_name']);
                    if (!empty($item['variant_type']) && !empty($item['variant_value'])) {
                        $productDisplay .= '<br><small class="text-muted">' . htmlspecialchars($item['variant_type'] . ': ' . $item['variant_value']) . '</small>';
                    }
                ?>
                    <tr>
                        <td>
                            <?php echo $productDisplay; ?>
                        </td>
                        <td class="text-center"><?php echo $item['quantity']; ?></td>
                        <td class="text-end"><?php echo formatPeso($item['price']); ?></td>
                        <td class="text-end"><?php echo formatPeso($itemTotal); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="total-section">
            <div class="total-amount">
                Total Amount: <?php echo formatPeso($order['total_amount'] ?? $total); ?>
            </div>
            <div class="payment-breakdown">
                <div class="breakdown-row">
                    <span class="breakdown-label">Amount Paid:</span>
                    <span class="breakdown-value"><?php echo formatPeso($amountPaid); ?></span>
                </div>
                <div class="breakdown-row">
                    <span class="breakdown-label">Balance Due:</span>
                    <span class="breakdown-value"><?php echo formatPeso($balanceDue); ?></span>
                </div>
            </div>
        </div>

        <div class="payment-method">
            <strong>Payment Method:</strong> Cash on Pickup
        </div>

        <?php if (!empty($payment_history)): ?>
        <div class="total-section" style="margin-top: 14px; background: linear-gradient(180deg, #f0f7ff, #eef4f9); border-color: #d6e9f7;">
            <h4 style="color: var(--primary); margin: 0 0 10px 0; font-size: 12px; font-weight: 700; text-transform: uppercase;">Payment History</h4>
            <div style="max-height: 200px; overflow-y: auto;">
                <?php foreach ($payment_history as $history): ?>
                <div style="padding: 8px 0; border-bottom: 1px solid #eef4f9; font-size: 12px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 2px;">
                        <strong><?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($history['payment_type']))); ?></strong>
                        <span style="color: #0b6a38; font-weight: 700;"><?php echo formatPeso($history['amount']); ?></span>
                    </div>
                    <div style="color: #666; font-size: 11px;">
                        <?php echo date('M j, Y g:i A', strtotime($history['change_timestamp'])); ?>
                    </div>
                    <div style="color: #999; font-size: 11px;">
                        <?php echo htmlspecialchars($history['notes'] ?? ''); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <?php if (!$output_pdf): // Render second copy only for screen/print (not for PDF download) ?>
    <!-- Second copy: hidden in screen preview, visible when printing -->
    <div class="receipt print-only">
        <div class="receipt-header">
            <div class="receipt-title">
                <h2>UniNeeds Store</h2>
                <span class="status-badge badge-<?= htmlspecialchars($orderStatusBadge); ?>"><?= htmlspecialchars($orderStatusLabel); ?></span>
            </div>
            <h5><p><i>Study ready. Style steady.</i></p></h5>
        </div>

        <div class="info-section">
            <div class="customer-details">
                <h4>Customer Information</h4>
                <p><strong><?php echo htmlspecialchars($order['full_name']); ?></strong></p>
                <p><?php echo htmlspecialchars($order['email']); ?></p>
                <p><?php echo htmlspecialchars($order['phone']); ?></p>
            </div>

            <div class="invoice-details">
                <h4>Invoice Details</h4>
                <p><strong>Invoice #:</strong> <?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></p>
                <p><strong>Date:</strong> <?php echo date('F j, Y', strtotime($order['created_at'])); ?></p>
            </div>
        </div>

        <table class="receipt-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th style="width: 100px;">Quantity</th>
                    <th style="width: 120px;" class="text-end">Price</th>
                    <th style="width: 120px;" class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                foreach ($item_list as $item):
                    $itemTotal = $item['price'] * $item['quantity'];
                    $productDisplay = htmlspecialchars($item['product_name']);
                    if (!empty($item['variant_type']) && !empty($item['variant_value'])) {
                        $productDisplay .= '<br><small class="text-muted">' . htmlspecialchars($item['variant_type'] . ': ' . $item['variant_value']) . '</small>';
                    }
                ?>
                    <tr>
                        <td>
                            <?php echo $productDisplay; ?>
                        </td>
                        <td class="text-center"><?php echo $item['quantity']; ?></td>
                        <td class="text-end"><?php echo formatPeso($item['price']); ?></td>
                        <td class="text-end"><?php echo formatPeso($itemTotal); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="total-section">
            <div class="total-amount">
                Total Amount: <?php echo formatPeso($order['total_amount'] ?? $total); ?>
            </div>
            <div class="payment-breakdown">
                <div class="breakdown-row">
                    <span class="breakdown-label">Amount Paid:</span>
                    <span class="breakdown-value"><?php echo formatPeso($amountPaid); ?></span>
                </div>
                <div class="breakdown-row">
                    <span class="breakdown-label">Balance Due:</span>
                    <span class="breakdown-value"><?php echo formatPeso($balanceDue); ?></span>
                </div>
            </div>
        </div>

        <div class="payment-method">
            <strong>Payment Method:</strong> Cash on Pickup
        </div>

        <?php if (!empty($payment_history)): ?>
        <div class="total-section" style="margin-top: 14px; background: linear-gradient(180deg, #f0f7ff, #eef4f9); border-color: #d6e9f7;">
            <h4 style="color: var(--primary); margin: 0 0 10px 0; font-size: 12px; font-weight: 700; text-transform: uppercase;">Payment History</h4>
            <div style="max-height: 200px; overflow-y: auto;">
                <?php foreach ($payment_history as $history): ?>
                <div style="padding: 8px 0; border-bottom: 1px solid #eef4f9; font-size: 12px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 2px;">
                        <strong><?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($history['payment_type']))); ?></strong>
                        <span style="color: #0b6a38; font-weight: 700;"><?php echo formatPeso($history['amount']); ?></span>
                    </div>
                    <div style="color: #666; font-size: 11px;">
                        <?php echo date('M j, Y g:i A', strtotime($history['change_timestamp'])); ?>
                    </div>
                    <div style="color: #999; font-size: 11px;">
                        <?php echo htmlspecialchars($history['notes'] ?? ''); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>
    </div>

    <?php if (!$output_pdf): ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php endif; ?>
    <!-- Cut guide (print-only) -->
    <div class="cut-guide" aria-hidden="true"></div>
</body>
</html>
<?php
// PDF output removed; only the online receipt is rendered.
?>
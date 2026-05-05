<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method not allowed');
}

$date_from = isset($_GET['date_from']) ? clean($_GET['date_from']) : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? clean($_GET['date_to']) : date('Y-m-d');

// Pre-order items query
$preorder_query = "SELECT p.product_id, p.product_name, COALESCE(pv.variant_value,'') as variant_value, SUM(oi.quantity) as preorder_count
                   FROM order_items oi
                   JOIN products p ON oi.product_id = p.product_id
                   JOIN orders o ON oi.order_id = o.order_id
                   LEFT JOIN product_variants pv ON oi.variant_id = pv.variant_id
                   LEFT JOIN invoices i ON o.order_id = i.order_id
                   WHERE p.is_preorder = 1
                   AND DATE(o.created_at) BETWEEN '$date_from' AND '$date_to'
                   AND o.order_status NOT IN ('cancelled','pending_payment')
                   AND COALESCE(i.amount_paid,0) > 0
                   GROUP BY p.product_id, pv.variant_value
                   ORDER BY preorder_count DESC";

$preorder_result = mysqli_query($conn, $preorder_query);
$preorder_data = [];
$preorder_total = 0;

if ($preorder_result) {
    while ($row = mysqli_fetch_assoc($preorder_result)) {
        $preorder_data[] = $row;
        $preorder_total += intval($row['preorder_count']);
    }
}

// Generate HTML for PDF
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            color: #333;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #007bff;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            color: #007bff;
            font-size: 24px;
        }
        .header p {
            margin: 5px 0;
            color: #666;
            font-size: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th {
            background-color: #f0f0f0;
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            font-weight: bold;
        }
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .text-end {
            text-align: right;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #999;
        }
        .total-row {
            background-color: #e8f4f8;
            font-weight: bold;
        }
        .total-row td {
            border-top: 2px solid #007bff;
            border-bottom: 2px solid #007bff;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Pre-order Items Report</h1>
        <p>Period: ' . date('F j, Y', strtotime($date_from)) . ' to ' . date('F j, Y', strtotime($date_to)) . '</p>
        <p>Generated on: ' . date('F j, Y g:i A') . '</p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Product / Variant</th>
                <th class="text-end">Pre-order Quantity</th>
            </tr>
        </thead>
        <tbody>';

if (!empty($preorder_data)) {
    $counter = 1;
    foreach ($preorder_data as $item) {
        $product_name = htmlspecialchars($item['product_name']);
        $variant = $item['variant_value'] ? ' (' . htmlspecialchars($item['variant_value']) . ')' : '';
        $quantity = intval($item['preorder_count']);
        
        $html .= "<tr>
                    <td>$counter</td>
                    <td>$product_name$variant</td>
                    <td class=\"text-end\">$quantity</td>
                  </tr>";
        $counter++;
    }
    
    $html .= '<tr class="total-row">
                <td colspan="2">Total Pre-order Items</td>
                <td class="text-end">' . $preorder_total . '</td>
              </tr>';
} else {
    $html .= '<tr><td colspan="3" style="text-align:center; color:#999;">No pre-order items found in this period.</td></tr>';
}

$html .= '
        </tbody>
    </table>
    
    <div class="footer">
        <p>UniNeeds - Business Report System</p>
    </div>
</body>
</html>';

// Generate PDF
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Output PDF
$filename = 'PreOrder_Report_' . date('Y-m-d_H-i-s') . '.pdf';
$dompdf->stream($filename);
?>

<?php

require_once '../config/database.php';
requireAdmin();

// Get date filter
$date_from = isset($_GET['date_from']) ? clean($_GET['date_from']) : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? clean($_GET['date_to']) : date('Y-m-d');

// Sales Summary (Revenue) - REMAINS
$sales_query = "SELECT 
                COUNT(*) as total_orders,
                SUM(total_amount) as total_sales,
                AVG(total_amount) as avg_order_value,
                SUM(CASE WHEN order_status = 'completed' THEN total_amount ELSE 0 END) as completed_sales
                FROM orders 
                WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'
                AND order_status != 'cancelled'";
$sales_result = mysqli_query($conn, $sales_query);
$sales_data = mysqli_fetch_assoc($sales_result);

$total_revenue = floatval($sales_data['total_sales'] ?? 0);

// Orders by Status
$status_query = "SELECT order_status, COUNT(*) as count 
                FROM orders 
                WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'
                AND order_status != 'cancelled'
                GROUP BY order_status";
$status_result = mysqli_query($conn, $status_query);

// Top Selling Products
$top_products_query = "SELECT p.product_name, SUM(oi.quantity) as total_sold, SUM(oi.quantity * oi.price) as revenue
                       FROM order_items oi
                       JOIN products p ON oi.product_id = p.product_id
                       JOIN orders o ON oi.order_id = o.order_id
                       WHERE DATE(o.created_at) BETWEEN '$date_from' AND '$date_to'
                       AND o.order_status != 'cancelled'
                       GROUP BY p.product_id
                       ORDER BY total_sold DESC
                       LIMIT 10";
$top_products = mysqli_query($conn, $top_products_query);

// Total Sold by Product and Variant
// note: variant_value is stored in product_variants; join on variant_id
$total_sold_query = "SELECT 
                                p.product_name, 
                                COALESCE(pv.variant_value, '') as variant_value,
                                SUM(oi.quantity) as total_sold 
                            FROM order_items oi
                            JOIN products p ON oi.product_id = p.product_id
                            JOIN orders o ON oi.order_id = o.order_id
                            LEFT JOIN product_variants pv ON oi.variant_id = pv.variant_id
                            WHERE DATE(o.created_at) BETWEEN '$date_from' AND '$date_to'
                            AND o.order_status != 'cancelled'
                            GROUP BY p.product_id, pv.variant_value
                            ORDER BY total_sold DESC";
$total_sold_result = mysqli_query($conn, $total_sold_query);
$total_sold_data = [];
if ($total_sold_result) {
    while($row = mysqli_fetch_assoc($total_sold_result)) {
        $total_sold_data[] = $row;
    }
}


// NEW QUERY: Pending Orders (New Orders) - Fetching all data, only displaying necessary below
$pending_orders_query = "SELECT o.order_id, o.created_at AS order_date, o.total_amount, o.order_status,
                         u.full_name as customer_name
                         FROM orders o
                         LEFT JOIN users u ON o.user_id = u.user_id
                         WHERE o.order_status IN ('pending', 'pending_payment')
                         AND DATE(o.created_at) BETWEEN '$date_from' AND '$date_to'
                         ORDER BY o.created_at DESC";
$pending_orders_result = mysqli_query($conn, $pending_orders_query);
$pending_orders_data = [];
if ($pending_orders_result) {
    while($row = mysqli_fetch_assoc($pending_orders_result)) {
        $pending_orders_data[] = $row;
    }
}


// Daily Sales
$daily_sales_query = "SELECT DATE(created_at) as date, 
                      COUNT(*) as orders,
                      SUM(total_amount) as sales
                      FROM orders
                      WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'
                      AND order_status != 'cancelled'
                      GROUP BY DATE(created_at)
                      ORDER BY date ASC";
$daily_sales = mysqli_query($conn, $daily_sales_query);

// Detailed Order Transactions
$detailed_orders_query = "SELECT o.order_id, o.created_at AS order_date, o.total_amount, o.order_status, o.payment_method,
                          u.full_name as customer_name, u.email as customer_email,
                          GROUP_CONCAT(CONCAT(p.product_name, 
                          CASE WHEN pv.variant_value IS NOT NULL AND pv.variant_value != '' THEN CONCAT(' (', pv.variant_value, ')') ELSE '' END,
                          ' - ', oi.quantity, 'x') SEPARATOR ', ') as items
                          FROM orders o
                          LEFT JOIN users u ON o.user_id = u.user_id
                          LEFT JOIN order_items oi ON o.order_id = oi.order_id
                          LEFT JOIN products p ON oi.product_id = p.product_id
                          LEFT JOIN product_variants pv ON oi.variant_id = pv.variant_id
                          WHERE DATE(o.created_at) BETWEEN '$date_from' AND '$date_to'
                          AND o.order_status != 'cancelled'
                          GROUP BY o.order_id
                          ORDER BY o.created_at DESC";
$detailed_orders = mysqli_query($conn, $detailed_orders_query);

// Pre-order items ordered in the period
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
$preorder_total_count = 0;
if ($preorder_result) {
    while ($r = mysqli_fetch_assoc($preorder_result)) {
        $preorder_total_count += intval($r['preorder_count']);
    }
    // rewind for rendering
    mysqli_data_seek($preorder_result, 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - UniNeeds Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <style>
        @media print {
            .sidebar, .top-bar, .filter-bar, .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; }
            .card { page-break-inside: avoid; }
        }
        .stat-card {
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,.1);
            text-align: center;
        }
        .stat-card .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        /* Custom print function for modal content (Only necessary if you want the content to fill the page) */
        @media print {
            .modal-print-content {
                position: static; /* Allows content to flow naturally */
                width: 100%;
                margin: 0;
                padding: 0;
            }
        }
        /* report-specific filter layout overrides */
        .reports-filter-bar .reports-flex-row {
            flex-direction: row !important;
            flex-wrap: wrap;
            justify-content: center;
        }
        .reports-filter-bar .col-auto {
            flex: 0 0 auto;
        }
        .reports-filter-bar .btn {
            width: auto !important;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-bar no-print">
            <button class="btn btn-link d-md-none" id="sidebarToggle">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h2>Business Reports & Analytics</h2>
            <div class="ms-auto d-flex gap-2">
                
                
                <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#preorderModal">
                    <i class="bi bi-clock-history me-2"></i>Pre-order Items (<?php echo $preorder_total_count; ?>)
                </button>
                
                
            </div>
        </div>
        
        <div class="content-area">
            <div class="filter-bar no-print reports-filter-bar d-flex justify-content-center">
                    <form id="reportsFilterForm" method="GET" class="row g-3 reports-flex-row align-items-end" style="max-width:900px; width:100%;">
                        <div class="col-auto">
                            <label class="form-label small mb-1">Date From</label>
                            <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo $date_from; ?>" required>
                        </div>
                        <div class="col-auto">
                            <label class="form-label small mb-1">Date To</label>
                            <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo $date_to; ?>" required>
                        </div>
                        <div class="col-auto d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('today')">Today</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('week')">This Week</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('month')">This Month</button>
                        </div>
                    </form>
            </div>
            
            <div class="card mb-4">
                <div class="card-body text-center">
                    <h3>UniNeeds Business Report</h3>
                    <p class="text-muted">Period: <?php echo date('F j, Y', strtotime($date_from)); ?> to <?php echo date('F j, Y', strtotime($date_to)); ?></p>
                    <small class="text-muted">Generated on: <?php echo date('F j, Y g:i A'); ?> by <?php echo htmlspecialchars($_SESSION['full_name']); ?></small>
                </div>
            </div>
            
            <h4 class="mb-3 text-center">Sales Summary</h4>
            <div class="row g-4 mb-4 justify-content-center">
                
                <div class="col-lg-3 col-md-6 col-sm-6">
                    <div class="stat-card bg-white border shadow-sm">
                        <div class="stat-icon text-success">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <div class="stat-info">
                            <h3 class="text-success"><?php echo formatCurrency($total_revenue); ?></h3>
                            <p class="mb-1">Expected Total Revenue</p>
                            <small class="text-muted"><?php echo $sales_data['total_orders']; ?> Orders</small>
                        </div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6 col-sm-6">
                    <div class="stat-card bg-white border shadow-sm">
                        <div class="stat-icon text-primary">
                            <i class="bi bi-clipboard-check"></i>
                        </div>
                        <div class="stat-info">
                            <h3 class="text-primary"><?php echo formatCurrency($sales_data['completed_sales'] ?? 0); ?></h3>
                            <p class="mb-1">Completed Sales</p>
                            <small class="text-muted">Total Paid</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 col-sm-6">
                    <div class="stat-card bg-white border shadow-sm">
                        <div class="stat-icon text-info">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                        <div class="stat-info">
                            <h3 class="text-info"><?php echo formatCurrency($sales_data['avg_order_value'] ?? 0); ?></h3>
                            <p class="mb-1">Avg. Order Value</p>
                            <small class="text-muted">Per Order</small>
                        </div>
                    </div>
                </div>
                
            </div>
            
            <div class="row g-4 mb-4">
                
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Orders by Status</h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th class="text-end">Count</th>
                                        <th class="text-end">Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php mysqli_data_seek($status_result, 0); while ($status = mysqli_fetch_assoc($status_result)): ?>
                                        <tr>
                                            <td>
                                                <?php
                                                $badge_class = [
                                                    'pending_payment' => 'secondary',
                                                    'pending' => 'warning',
                                                    'ready for pickup' => 'info',
                                                    'completed' => 'success',
                                                    'cancelled' => 'danger'
                                                ];
                                                ?>
                                                <span class="badge bg-<?php echo $badge_class[$status['order_status']] ?? 'secondary'; ?>">
                                                    <?php echo ucfirst($status['order_status']); ?>
                                                </span>
                                            </td>
                                            <td class="text-end"><?php echo $status['count']; ?></td>
                                            <td class="text-end">
                                                <?php echo $sales_data['total_orders'] > 0 ? round(($status['count'] / $sales_data['total_orders']) * 100, 1) : 0; ?>%
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-star me-2"></i>Top Selling Products (Revenue)</h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-end">Sold</th>
                                        <th class="text-end">Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (mysqli_num_rows($top_products) > 0): ?>
                                        <?php while ($product = mysqli_fetch_assoc($top_products)): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                                                <td class="text-end"><?php echo $product['total_sold']; ?></td>
                                                <td class="text-end"><?php echo formatCurrency($product['revenue']); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted">No sales data</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-calendar3 me-2"></i>Daily Sales Overview</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th class="text-end">Orders</th>
                                    <th class="text-end">Total Sales</th>
                                    <th class="text-end">Avg Order Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($daily_sales) > 0): ?>
                                    <?php 
                                    $total_orders = 0;
                                    $total_sales = 0;
                                    mysqli_data_seek($daily_sales, 0);
                                    while ($day = mysqli_fetch_assoc($daily_sales)): 
                                        $total_orders += $day['orders'];
                                        $total_sales += $day['sales'];
                                    ?>
                                        <tr>
                                            <td><?php echo date('M j, Y (D)', strtotime($day['date'])); ?></td>
                                            <td class="text-end"><?php echo $day['orders']; ?></td>
                                            <td class="text-end"><?php echo formatCurrency($day['sales']); ?></td>
                                            <td class="text-end"><?php echo formatCurrency($day['sales'] / $day['orders']); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                    <tr class="table-light fw-bold">
                                        <td>TOTAL</td>
                                        <td class="text-end"><?php echo $total_orders; ?></td>
                                        <td class="text-end"><?php echo formatCurrency($total_sales); ?></td>
                                        <td class="text-end"><?php echo $total_orders > 0 ? formatCurrency($total_sales / $total_orders) : formatCurrency(0); ?></td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">No sales data for selected period</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            
    </div>
    
    <div class="modal fade" id="pendingOrdersModal" tabindex="-1" aria-labelledby="pendingOrdersModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-md modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header no-print">
                    <h5 class="modal-title" id="pendingOrdersModalLabel">Pending Orders (<?php echo count($pending_orders_data); ?>)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h4 class="text-center">Active Pending Orders Summary</h4>
                    <p class="text-center text-muted">Period: <?php echo date('F j, Y', strtotime($date_from)); ?> to <?php echo date('F j, Y', strtotime($date_to)); ?></p>

                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Order ID</th>
                                    <th>Date</th>
                                    <th class="text-end">Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($pending_orders_data)): ?>
                                    <?php $counter = 1; foreach ($pending_orders_data as $order): ?>
                                        <tr>
                                            <td><?php echo $counter++; ?></td>
                                            <td><strong>#<?php echo str_pad($order['order_id'], 5, '0', STR_PAD_LEFT); ?></strong></td>
                                            <td><?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></td>
                                            <td class="text-end fw-bold"><?php echo formatCurrency($order['total_amount']); ?></td>
                                            <td>
                                                <?php
                                                $badge_class = [
                                                    'pending_payment' => 'secondary',
                                                    'pending' => 'warning'
                                                ];
                                                ?>
                                                <span class="badge bg-<?php echo $badge_class[$order['order_status']] ?? 'secondary'; ?>">
                                                    <?php echo ucfirst($order['order_status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No pending orders found in this period.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer no-print">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-danger" onclick="printModalContent('#pendingOrdersModal')">
                        <i class="bi bi-printer me-2"></i>Print This List
                    </button>
                </div>
            </div>
        </div>
    </div>

    
    <div class="modal fade" id="totalItemsSoldModal" tabindex="-1" aria-labelledby="totalItemsSoldModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-md modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header no-print">
                    <h5 class="modal-title" id="totalItemsSoldModalLabel">Total Items Sold (Detailed)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h4 class="text-center">Items Sold Summary</h4>
                    <p class="text-center text-muted">Period: <?php echo date('F j, Y', strtotime($date_from)); ?> to <?php echo date('F j, Y', strtotime($date_to)); ?></p>

                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Product / Variant</th>
                                    <th class="text-end">Total Quantity Sold</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($total_sold_data)): ?>
                                    <?php $counter = 1; foreach ($total_sold_data as $item): ?>
                                        <tr>
                                            <td><?php echo $counter++; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                                <?php if ($item['variant_value']): ?>
                                                    <span class="text-muted">(<?php echo htmlspecialchars($item['variant_value']); ?>)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end fw-bold"><?php echo $item['total_sold']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted">No products sold in this period.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer no-print">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="printModalContent('#totalItemsSoldModal')">
                        <i class="bi bi-printer me-2"></i>Print This List
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="preorderModal" tabindex="-1" aria-labelledby="preorderModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-md modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header no-print">
                    <h5 class="modal-title" id="preorderModalLabel">Pre-order Items Ordered (<?php echo $preorder_total_count; ?>)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h4 class="text-center">Pre-order Items Summary</h4>
                    <p class="text-center text-muted">Period: <?php echo date('F j, Y', strtotime($date_from)); ?> to <?php echo date('F j, Y', strtotime($date_to)); ?></p>

                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Product / Variant</th>
                                    <th class="text-end">Total Pre-order Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($preorder_result && mysqli_num_rows($preorder_result) > 0): ?>
                                    <?php $counter = 1; while ($row = mysqli_fetch_assoc($preorder_result)): ?>
                                        <tr>
                                            <td><?php echo $counter++; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($row['product_name']); ?></strong>
                                                <?php if (!empty($row['variant_value'])): ?>
                                                    <span class="text-muted">(<?php echo htmlspecialchars($row['variant_value']); ?>)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end fw-bold"><?php echo $row['preorder_count']; ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted">No pre-order items ordered in this period.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer no-print">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="../api/generate-preorder-pdf.php?date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" target="_blank" class="btn btn-danger">
                        <i class="bi bi-filetype-pdf me-2"></i>Download PDF
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script>
        function setDateRange(range) {
            const dateFrom = document.querySelector('input[name="date_from"]');
            const dateTo = document.querySelector('input[name="date_to"]');
            const form = document.getElementById('reportsFilterForm');
            const today = new Date();
            
            if (range === 'today') {
                dateFrom.value = today.toISOString().split('T')[0];
                dateTo.value = today.toISOString().split('T')[0];
            } else if (range === 'week') {
                const weekStart = new Date(today.setDate(today.getDate() - today.getDay()));
                dateFrom.value = weekStart.toISOString().split('T')[0];
                dateTo.value = new Date().toISOString().split('T')[0];
            } else if (range === 'month') {
                const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
                dateFrom.value = monthStart.toISOString().split('T')[0];
                dateTo.value = new Date().toISOString().split('T')[0];
            }
            // submit immediately after updating dates
            if (form) form.submit();
        }
        
        // Generic print function for modal content
        function printModalContent(modalSelector) {
            const modalContent = document.querySelector(modalSelector + ' .modal-content');
            if (!modalContent) return;

            const printWindow = window.open('', '_blank', 'height=600,width=800');
            printWindow.document.write('<html><head><title>Report Printout</title>');
            printWindow.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">');
            printWindow.document.write('<style>');
            // Hide non-print elements
            printWindow.document.write('@media print { .no-print { display: none !important; } }');
            printWindow.document.write('</style>');
            printWindow.document.write('</head><body>');
            
            // Write modal body content (excluding modal header/footer which have .no-print class)
            printWindow.document.write(modalContent.querySelector('.modal-body').innerHTML);
            
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            
            printWindow.focus();
            setTimeout(() => {
                 printWindow.print();
                 printWindow.close();
            }, 500);
        }
    </script>
    <script>
        // Auto-submit reports filter form on change (debounced)
        (function(){
            const form = document.getElementById('reportsFilterForm');
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
    </script>
</body>
</html>
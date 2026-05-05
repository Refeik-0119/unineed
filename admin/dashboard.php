<?php

require_once '../config/database.php';
requireAdmin();

$stats = [];
$is_superadmin = isSuperAdmin();

if ($is_superadmin) {
    $query = "SELECT COUNT(*) as total_students FROM users WHERE user_type = 'student' AND status = 'active'";
    $result = mysqli_query($conn, $query);
    $stats['total_students'] = mysqli_fetch_assoc($result)['total_students'];

    $query = "SELECT COUNT(*) as total_admins FROM users WHERE user_type = 'admin' AND status = 'active'";
    $result = mysqli_query($conn, $query);
    $stats['total_admins'] = mysqli_fetch_assoc($result)['total_admins'];

    $query = "SELECT COUNT(*) as archived_students FROM users WHERE user_type = 'student' AND status = 'archived'";
    $result = mysqli_query($conn, $query);
    $stats['archived_students'] = mysqli_fetch_assoc($result)['archived_students'];

    $query = "SELECT COUNT(*) as total_accounts FROM users WHERE user_type IN ('student','admin') AND status = 'active'";
    $result = mysqli_query($conn, $query);
    $stats['total_accounts'] = mysqli_fetch_assoc($result)['total_accounts'];
} else {
    $query = "SELECT COUNT(*) as total FROM orders";
    $result = mysqli_query($conn, $query);
    $stats['total_orders'] = mysqli_fetch_assoc($result)['total'];

    $query = "SELECT SUM(total_amount) as revenue FROM orders WHERE order_status = 'completed'";
    $result = mysqli_query($conn, $query);
    $stats['revenue'] = mysqli_fetch_assoc($result)['revenue'] ?? 0;

    // Pending Orders
    $query = "SELECT COUNT(*) as pending FROM orders WHERE order_status IN ('pending', 'pending_payment')";
    $result = mysqli_query($conn, $query);
    $stats['pending_orders'] = mysqli_fetch_assoc($result)['pending'];

    $query = "SELECT COUNT(*) as total FROM products WHERE status = 'available'";
    $result = mysqli_query($conn, $query);
    $stats['total_products'] = mysqli_fetch_assoc($result)['total'];

    $query = "SELECT COUNT(*) as low_stock FROM products WHERE stock_quantity <= 10 AND stock_quantity > 0 AND status = 'available'";
    $result = mysqli_query($conn, $query);
    $stats['low_stock'] = mysqli_fetch_assoc($result)['low_stock'];
}

$query = "SELECT o.*, u.full_name, u.email, o.created_at AS order_date
          FROM orders o
          JOIN users u ON o.user_id = u.user_id
          ORDER BY o.created_at DESC
          LIMIT 5";
$recent_orders = mysqli_query($conn, $query);

$query = "SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            SUM(total_amount) as total_sales,
            COUNT(*) as order_count
          FROM orders 
          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
          GROUP BY DATE_FORMAT(created_at, '%Y-%m')
          ORDER BY month DESC";
$monthly_sales = mysqli_query($conn, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - UniNeeds Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.js" rel="stylesheet">
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
            <h2>Dashboard</h2>
            <div class="ms-auto">
                <span class="text-muted"><?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>
        
        <div class="content-area">
            <div class="row g-4 mb-4 align-items-stretch">
                <?php if ($is_superadmin): ?>
                    <div class="col-md-4">
                        <div class="stat-card stat-primary">
                            <div class="stat-icon">
                                <i class="bi bi-people"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $stats['total_students']; ?></h3>
                                <p>Active Students</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card stat-secondary">
                            <div class="stat-icon">
                                <i class="bi bi-person-badge"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $stats['total_admins']; ?></h3>
                                <p>Active Admins</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card stat-success">
                            <div class="stat-icon">
                                <i class="bi bi-folder2-open"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $stats['archived_students']; ?></h3>
                                <p>Archived Students</p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="col-md-4">
                        <div class="stat-card stat-primary">
                            <div class="stat-icon">
                                <i class="bi bi-cart-check"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $stats['total_orders']; ?></h3>
                                <p>Total Orders</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="stat-card stat-success">
                            <div class="stat-icon">
                                <i class="bi bi-currency-peso"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo formatCurrency($stats['revenue']); ?></h3>
                                <p>Total Revenue</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="stat-card stat-warning">
                            <div class="stat-icon">
                                <i class="bi bi-clock-history"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $stats['pending_orders']; ?></h3>
                                <p>Pending Orders</p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                </div>
            
            <?php if (!$is_superadmin && $stats['low_stock'] > 0): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Low Stock Alert!</strong> You have <?php echo $stats['low_stock']; ?> product(s) with low stock levels.
                <a href="inventory.php" class="alert-link">View Product Inventory</a>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if ($is_superadmin): ?>
                <div class="row g-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="bi bi-people-fill me-2"></i>Superadmin Quick Actions</h5>
                                <a href="users.php" class="btn btn-sm btn-primary">Manage Accounts</a>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <a href="users.php?add_type=admin" class="text-decoration-none">
                                            <div class="card border-primary h-100">
                                                <div class="card-body d-flex align-items-center gap-3">
                                                    <i class="bi bi-person-badge fs-2 text-primary"></i>
                                                    <div>
                                                        <h6 class="mb-1">Add Admin</h6>
                                                        <p class="mb-0 text-muted">Create a new admin account</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-sm-6">
                                        <a href="users.php?add_type=student" class="text-decoration-none">
                                            <div class="card border-success h-100">
                                                <div class="card-body d-flex align-items-center gap-3">
                                                    <i class="bi bi-person-plus fs-2 text-success"></i>
                                                    <div>
                                                        <h6 class="mb-1">Add Student</h6>
                                                        <p class="mb-0 text-muted">Create a new student account</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Orders</h5>
                                <a href="orders.php" class="btn btn-sm btn-primary">View All</a>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Order ID</th>
                                                <th>Customer</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (mysqli_num_rows($recent_orders) > 0): ?>
                                                <?php while ($order = mysqli_fetch_assoc($recent_orders)): ?>
                                                    <tr>
                                                        <td>#<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></td>
                                                        <td><?php echo htmlspecialchars($order['full_name']); ?></td>
                                                        <td><?php echo formatCurrency($order['total_amount']); ?></td>
                                                        <td>
                                                            <?php
                                                            // MODIFIED: Added 'pending_payment'
                                                            $badge_class = [
                                                                'pending_payment' => 'secondary',
                                                                'pending' => 'warning',
                                                                'ready for pickup' => 'info',
                                                                'completed' => 'success',
                                                                'cancelled' => 'danger'
                                                            ];
                                                            $order_status_clean = str_replace('_', ' ', $order['order_status']);
                                                            $current_badge_class = $badge_class[$order['order_status']] ?? 'secondary';
                                                            ?>
                                                            <span class="badge bg-<?php echo $current_badge_class; ?>">
                                                                <?php echo ucfirst($order_status_clean); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo date('M j, Y', strtotime($order['created_at'])); ?></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="5" class="text-center py-4">No orders yet</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Monthly Sales</h5>
                            </div>
                            <div class="card-body">
                                <?php if (mysqli_num_rows($monthly_sales) > 0): ?>
                                    <?php while ($month = mysqli_fetch_assoc($monthly_sales)): ?>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <small><?php echo date('M Y', strtotime($month['month'])); ?></small>
                                                <small class="text-muted"><?php echo $month['order_count']; ?> orders</small>
                                            </div>
                                            <div class="progress" style="height: 8px;">
                                                <div class="progress-bar bg-success" style="width: <?php echo min(100, ($month['total_sales'] / ($stats['revenue'] ?: 1)) * 100); ?>%"></div>
                                            </div>
                                            <small class="text-success"><?php echo formatCurrency($month['total_sales']); ?></small>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <p class="text-muted text-center">No sales data available</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script src="../assets/js/mobile-menu.js"></script>
    
</body>
</html>
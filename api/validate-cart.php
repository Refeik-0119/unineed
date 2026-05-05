<?php
header('Content-Type: application/json');
require_once '../config/database.php';
requireStudent();

$user_id = $_SESSION['user_id'];
$cart = $_SESSION['cart'] ?? [];
$removed_items = [];

foreach ($cart as $key => $item) {
    $p_id = intval($item['product_id']);
    $variant_id = isset($item['variant_id']) ? intval($item['variant_id']) : null;
    $available = 0;

    // Fetch product info
    $p_q = mysqli_query($conn, "SELECT stock_quantity, is_preorder FROM products WHERE product_id = $p_id LIMIT 1");
    $prow = $p_q ? mysqli_fetch_assoc($p_q) : null;

    if (!$prow) {
        continue; // Product doesn't exist
    }

    // Check variant stock or product stock
    if ($variant_id) {
        $v_q = mysqli_query($conn, "SELECT stock_quantity FROM product_variants WHERE variant_id = $variant_id LIMIT 1");
        if ($v_q && mysqli_num_rows($v_q) > 0) {
            $vrow = mysqli_fetch_assoc($v_q);
            $available = intval($vrow['stock_quantity']);
        }
    } else {
        $available = intval($prow['stock_quantity'] ?? 0);
    }

    // Check if product is pre-order
    $is_preorder = !empty($prow['is_preorder']) && $prow['is_preorder'] == 1;

    // If out of stock and not pre-order, mark for removal
    if ($available <= 0 && !$is_preorder) {
        $removed_items[] = [
            'product_name' => $item['product_name'] ?? 'Unknown Product',
            'product_id' => $p_id,
            'variant_id' => $variant_id,
            'cart_key' => $key,
            'quantity_in_cart' => $item['quantity'],
            'reason' => 'out_of_stock'
        ];
        unset($_SESSION['cart'][$key]);
    } elseif (!$is_preorder && $item['quantity'] > $available) {
        // Quantity exceeded available stock
        $_SESSION['cart'][$key]['quantity'] = $available;
        $removed_items[] = [
            'product_name' => $item['product_name'] ?? 'Unknown Product',
            'product_id' => $p_id,
            'variant_id' => $variant_id,
            'cart_key' => $key,
            'quantity_in_cart' => $item['quantity'],
            'quantity_adjusted_to' => $available,
            'reason' => 'quantity_reduced'
        ];
    }
}

echo json_encode([
    'success' => true,
    'removed_items' => $removed_items,
    'cart_empty' => empty($_SESSION['cart']),
    'remaining_cart_count' => count($_SESSION['cart'] ?? [])
]);
?>

<?php
/**
 * GET COUPONS API (Dealer App) — WITH PRODUCTS (No Order object)
 * 
 * Query/URL Parameter: customer_id
 * 
 * Har coupon ke saath:
 * - Dealer info (dealer_id, name, station)
 * - Products (jo is coupon pe order hue)
 * 
 * NOTE: order object hata diya gaya hai
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

// ─── Get customer_id ───
$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

if ($customerId <= 0) {
    jsonResponse(['status'=>'error','message'=>'Valid customer_id required']);
}

// ─── Customer Check ───
$stmt = $db->prepare("
    SELECT id, name, mobile, 
           total_coupons, remaining_coupons, used_coupons 
    FROM customers 
    WHERE id = ? 
    LIMIT 1
");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    jsonResponse(['status'=>'error','message'=>'Customer not found with this ID']);
}

// ─── Customer ke coupons lein ───
$stmt = $db->prepare("
    SELECT id, title, description, image_url, 
           discount_percent, discount_amount, min_purchase,
           valid_from, valid_to, status, used_at, used_at_station, created_at
    FROM coupons 
    WHERE customer_id = ? 
    ORDER BY id DESC
");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$result = $stmt->get_result();

$coupons = [];

while ($row = $result->fetch_assoc()) {

    $couponId = (int)$row['id'];

    // ─── Dealer + Products (order ke through) ───
    $dealerInfo = null;
    $products   = [];

    // Order dhundo (sirf order_id aur dealer ke liye)
    $stmt2 = $db->prepare("
        SELECT 
            o.id AS order_id,
            d.id AS dealer_id,
            d.name AS dealer_name,
            d.station_name
        FROM orders o
        LEFT JOIN dealers d ON d.id = o.dealer_id
        WHERE o.coupon_id = ? AND o.customer_id = ?
        ORDER BY o.id DESC
        LIMIT 1
    ");
    $stmt2->bind_param("ii", $couponId, $customerId);
    $stmt2->execute();
    $orderRow = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();

    if ($orderRow) {
        $dealerInfo = [
            'dealer_id'    => (int)$orderRow['dealer_id'],
            'dealer_name'  => $orderRow['dealer_name'],
            'station_name' => $orderRow['station_name'],
        ];

        // ─── Products for this order ───
        $stmt3 = $db->prepare("
            SELECT 
                oi.id              AS item_id,
                oi.product_id,
                oi.product_sku,
                oi.product_name,
                oi.product_brand,
                oi.quantity,
                oi.price,
                oi.discount_percent AS item_discount_percent,
                oi.subtotal         AS item_subtotal
            FROM order_items oi
            WHERE oi.order_id = ?
            ORDER BY oi.id ASC
        ");
        $stmt3->bind_param("i", $orderRow['order_id']);
        $stmt3->execute();
        $res3 = $stmt3->get_result();

        while ($p = $res3->fetch_assoc()) {
            $products[] = [
                'item_id'          => (int)$p['item_id'],
                'product_id'       => (int)$p['product_id'],
                'product_sku'      => $p['product_sku'] ?? '',
                'product_name'     => $p['product_name'],
                'product_brand'    => $p['product_brand'] ?? '',
                'quantity'         => (int)$p['quantity'],
                'price'            => (float)$p['price'],
                'discount_percent' => (float)$p['item_discount_percent'],
                'subtotal'         => (float)$p['item_subtotal'],
            ];
        }
        $stmt3->close();
    }

    // ─── Coupon array ───
    $coupons[] = [
        'id'               => $couponId,
        'title'            => $row['title'],
        'description'      => $row['description'] ?? '',
        'image_url'        => $row['image_url'] ?? '',
        'discount_percent' => (float)$row['discount_percent'],
        // 'discount_amount'  => (float)($row['discount_amount'] ?? 0),
        // 'min_purchase'     => (float)($row['min_purchase'] ?? 0),
        'valid_from'       => $row['valid_from'],
        'valid_to'         => $row['valid_to'],
        'status'           => $row['status'],
        'used_at'          => $row['used_at'] ?? '',
        'used_at_station'  => $row['used_at_station'] ?? '',
        'date_formatted'   => !empty($row['valid_to']) 
                                ? date('d M Y', strtotime($row['valid_to'])) 
                                : '',

        // ❌ ORDER OBJECT HATA DIYA
        'dealer'           => $dealerInfo,
        'products'         => $products,
        'products_count'   => count($products),
    ];
}
$stmt->close();

// ─── Response ───
jsonResponse([
    'status'  => 'success',
    'message' => 'Coupons fetched successfully',
    'customer' => [
        'id'     => (int)$customer['id'],
        'name'   => $customer['name'],
        'mobile' => $customer['mobile'],
    ],
    'summary' => [
        'total'     => (int)$customer['total_coupons'],
        'remaining' => (int)$customer['remaining_coupons'],
        'used'      => (int)$customer['used_coupons'],
    ],
    'total_coupons' => count($coupons),
    'coupons' => $coupons,
]);
<?php
/**
 * GET ORDER ITEMS API
 * POST /api/orders/get-order-items.php
 * Body: { order_id }
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

$input    = getInput();
$order_id = isset($input['order_id']) ? (int)$input['order_id'] : 0;

if ($order_id <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'Valid order_id required']);
    exit;
}

$sql = "
    SELECT 
        oi.id, oi.order_id, oi.product_id,
        oi.product_sku, oi.product_name, oi.product_brand,
        oi.coupon_id, oi.quantity, oi.price,
        oi.discount_percent, oi.subtotal, oi.created_at,
        lp.image AS product_image,
        lp.status AS product_status
    FROM order_items oi
    LEFT JOIN lube_products lp ON lp.id = oi.product_id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
";

$stmt = $db->prepare($sql);
$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
$grand_total = 0;

while ($row = $result->fetch_assoc()) {
    $grand_total += (float)$row['subtotal'];

    $items[] = [
        'id'               => (int)$row['id'],
        'order_id'         => (int)$row['order_id'],
        'product_id'       => (int)$row['product_id'],
        'product_sku'      => $row['product_sku'] ?? '',
        'product_name'     => $row['product_name'],
        'product_brand'    => $row['product_brand'] ?? '',
        'product_image'    => $row['product_image'] ?? '',
        'product_status'   => $row['product_status'] ?? '',
        'coupon_id'        => $row['coupon_id'] ? (int)$row['coupon_id'] : null,
        'quantity'         => (int)$row['quantity'],
        'price'            => (float)$row['price'],
        'discount_percent' => (float)$row['discount_percent'],
        'subtotal'         => (float)$row['subtotal'],
        'created_at'       => $row['created_at'],
    ];
}
$stmt->close();

jsonResponse([
    'status'      => 'success',
    'message'     => 'Order items fetched successfully',
    'total'       => count($items),
    'grand_total' => round($grand_total, 2),
    'items'       => $items,
]);
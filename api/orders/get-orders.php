<?php
/**
 * GET ORDERS API (Read-Only)
 * POST /api/orders/get-orders.php
 * Body: {
 *   customer_id?,
 *   dealer_id?,
 *   status?,              // pending|confirmed|processing|completed|cancelled
 *   payment_status?,      // unpaid|paid|refunded
 *   delivery_type?,       // pickup|delivery
 *   search?,              // order_number, player_key, player_id, coupon_code
 *   date_from?,
 *   date_to?
 * }
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

$input          = getInput();
$customer_id    = isset($input['customer_id']) ? (int)$input['customer_id'] : 0;
$dealer_id      = isset($input['dealer_id']) ? (int)$input['dealer_id'] : 0;
$status         = trim($input['status'] ?? '');
$payment_status = trim($input['payment_status'] ?? '');
$delivery_type  = trim($input['delivery_type'] ?? '');
$search         = trim($input['search'] ?? '');
$date_from      = trim($input['date_from'] ?? '');
$date_to        = trim($input['date_to'] ?? '');

$where  = [];
$params = [];
$types  = '';

if ($customer_id > 0) {
    $where[] = "o.customer_id = ?";
    $params[] = $customer_id;
    $types .= 'i';
}

if ($dealer_id > 0) {
    $where[] = "o.dealer_id = ?";
    $params[] = $dealer_id;
    $types .= 'i';
}

if ($status !== '' && in_array($status, ['pending', 'confirmed', 'processing', 'completed', 'cancelled'])) {
    $where[] = "o.status = ?";
    $params[] = $status;
    $types .= 's';
}

if ($payment_status !== '' && in_array($payment_status, ['unpaid', 'paid', 'refunded'])) {
    $where[] = "o.payment_status = ?";
    $params[] = $payment_status;
    $types .= 's';
}

if ($delivery_type !== '' && in_array($delivery_type, ['pickup', 'delivery'])) {
    $where[] = "o.delivery_type = ?";
    $params[] = $delivery_type;
    $types .= 's';
}

if ($search !== '') {
    $where[] = "(o.order_number LIKE ? OR o.player_key LIKE ? OR o.player_id LIKE ? OR o.coupon_code LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

if ($date_from !== '') {
    $where[] = "DATE(o.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to !== '') {
    $where[] = "DATE(o.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$sql = "
    SELECT 
        o.id, o.order_number, o.customer_id, o.dealer_id,
        o.player_key, o.player_id,
        o.subtotal, o.discount, o.total_amount,
        o.coupon_id, o.coupon_code,
        o.status, o.payment_status, o.payment_method,
        o.delivery_type, o.delivery_address, o.station_name, o.notes,
        o.created_at, o.updated_at,
        c.name AS customer_name,
        c.mobile AS customer_mobile,
        c.email AS customer_email,
        d.name AS dealer_name,
        d.mobile AS dealer_mobile,
        d.station_name AS dealer_station
    FROM orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    LEFT JOIN dealers d ON d.id = o.dealer_id
";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY o.id DESC";

$stmt = $db->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$orders = [];
$total_amount = 0;
$total_discount = 0;
$total_final = 0;

while ($row = $result->fetch_assoc()) {
    $total_amount   += (float)$row['subtotal'];
    $total_discount += (float)$row['discount'];
    $total_final    += (float)$row['total_amount'];

    $orders[] = [
        'id'               => (int)$row['id'],
        'order_number'     => $row['order_number'],
        'customer_id'      => (int)$row['customer_id'],
        'customer_name'    => $row['customer_name'] ?? '—',
        'customer_mobile'  => $row['customer_mobile'] ?? '',
        'customer_email'   => $row['customer_email'] ?? '',
        'dealer_id'        => $row['dealer_id'] ? (int)$row['dealer_id'] : null,
        'dealer_name'      => $row['dealer_name'] ?? '—',
        'dealer_mobile'    => $row['dealer_mobile'] ?? '',
        'dealer_station'   => $row['dealer_station'] ?? '',
        'player_key'       => $row['player_key'],
        'player_id'        => $row['player_id'],
        'subtotal'         => (float)$row['subtotal'],
        'discount'         => (float)$row['discount'],
        'total_amount'     => (float)$row['total_amount'],
        'coupon_id'        => $row['coupon_id'] ? (int)$row['coupon_id'] : null,
        'coupon_code'      => $row['coupon_code'] ?? '',
        'status'           => $row['status'],
        'payment_status'   => $row['payment_status'],
        'payment_method'   => $row['payment_method'] ?? '',
        'delivery_type'    => $row['delivery_type'],
        'delivery_address' => $row['delivery_address'] ?? '',
        'station_name'     => $row['station_name'] ?? '',
        'notes'            => $row['notes'] ?? '',
        'created_at'       => $row['created_at'],
        'updated_at'       => $row['updated_at'],
    ];
}
$stmt->close();

jsonResponse([
    'status'          => 'success',
    'message'         => 'Orders fetched successfully',
    'total'           => count($orders),
    'total_subtotal'  => round($total_amount, 2),
    'total_discount'  => round($total_discount, 2),
    'total_final'     => round($total_final, 2),
    'orders'          => $orders,
]);
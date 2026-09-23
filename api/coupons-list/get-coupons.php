<?php

// ✅ Sirf OPTIONS handle karein
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

$input       = getInput();
$customer_id = isset($input['customer_id']) ? (int)$input['customer_id'] : 0;
$status      = trim($input['status'] ?? '');
$search      = trim($input['search'] ?? '');
$date_from   = trim($input['date_from'] ?? '');
$date_to     = trim($input['date_to'] ?? '');

$where  = [];
$params = [];
$types  = '';

if ($customer_id > 0) {
    $where[] = "cp.customer_id = ?";
    $params[] = $customer_id;
    $types .= 'i';
}

if ($status !== '' && in_array($status, ['available', 'used', 'expired'])) {
    $where[] = "cp.status = ?";
    $params[] = $status;
    $types .= 's';
}

if ($search !== '') {
    $where[] = "(cp.title LIKE ? OR cp.description LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like);
    $types .= 'ss';
}

if ($date_from !== '') {
    $where[] = "DATE(cp.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to !== '') {
    $where[] = "DATE(cp.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$sql = "
    SELECT 
        cp.id, cp.customer_id, cp.title, cp.description, cp.image_url,
        cp.discount_percent, cp.discount_amount, cp.min_purchase,
        cp.valid_from, cp.valid_to, cp.status,
        cp.used_at, cp.used_at_station,
        cp.created_at, cp.updated_at,
        c.name AS customer_name,
        c.mobile AS customer_mobile,
        c.email AS customer_email
    FROM coupons cp
    LEFT JOIN customers c ON c.id = cp.customer_id
";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY cp.id DESC";

$stmt = $db->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$coupons = [];
$total_available = 0;
$total_used = 0;
$total_expired = 0;

while ($row = $result->fetch_assoc()) {
    if ($row['status'] === 'available') $total_available++;
    elseif ($row['status'] === 'used') $total_used++;
    elseif ($row['status'] === 'expired') $total_expired++;

    $coupons[] = [
        'id'                => (int)$row['id'],
        'customer_id'       => $row['customer_id'] ? (int)$row['customer_id'] : null,
        'customer_name'     => $row['customer_name'] ?? '—',
        'customer_mobile'   => $row['customer_mobile'] ?? '',
        'customer_email'    => $row['customer_email'] ?? '',
        'title'             => $row['title'],
        'description'       => $row['description'] ?? '',
        'image_url'         => $row['image_url'] ?? '',
        'discount_percent'  => (float)$row['discount_percent'],
        'discount_amount'   => (float)$row['discount_amount'],
        'min_purchase'      => (float)$row['min_purchase'],
        'valid_from'        => $row['valid_from'],
        'valid_to'          => $row['valid_to'],
        'status'            => $row['status'],
        'used_at'           => $row['used_at'],
        'used_at_station'   => $row['used_at_station'] ?? '',
        'created_at'        => $row['created_at'],
        'updated_at'        => $row['updated_at'],
    ];
}
$stmt->close();

jsonResponse([
    'status'          => 'success',
    'message'         => 'Coupons fetched successfully',
    'total'           => count($coupons),
    'total_available' => $total_available,
    'total_used'      => $total_used,
    'total_expired'   => $total_expired,
    'coupons'         => $coupons,
]);
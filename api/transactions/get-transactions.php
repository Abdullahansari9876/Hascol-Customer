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

$input        = getInput();
$status       = trim($input['status'] ?? '');
$dealer_id    = isset($input['dealer_id']) ? (int)$input['dealer_id'] : 0;
$customer_id  = isset($input['customer_id']) ? (int)$input['customer_id'] : 0;
$station_name = trim($input['station_name'] ?? '');
$date_from    = trim($input['date_from'] ?? '');
$date_to      = trim($input['date_to'] ?? '');
$search       = trim($input['search'] ?? '');

$where = [];
$params = [];
$types = '';

if ($status !== '' && in_array($status, ['successful', 'pending', 'failed'])) {
    $where[] = "t.status = ?";
    $params[] = $status;
    $types .= 's';
}

if ($dealer_id > 0) {
    $where[] = "t.dealer_id = ?";
    $params[] = $dealer_id;
    $types .= 'i';
}

if ($customer_id > 0) {
    $where[] = "t.customer_id = ?";
    $params[] = $customer_id;
    $types .= 'i';
}

if ($station_name !== '') {
    $where[] = "t.station_name LIKE ?";
    $params[] = '%' . $station_name . '%';
    $types .= 's';
}

if ($date_from !== '') {
    $where[] = "DATE(t.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to !== '') {
    $where[] = "DATE(t.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

if ($search !== '') {
    $where[] = "(t.player_key LIKE ? OR t.player_id LIKE ? OR t.coupon_code LIKE ? OR t.transaction_ref LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

$sql = "
    SELECT 
        t.id, t.customer_id, t.dealer_id, t.player_key, t.player_id,
        t.station_name, t.amount, t.discount, t.final_amount,
        t.coupon_id, t.coupon_code, t.status, t.transaction_ref,
        t.created_at, t.updated_at,
        c.name AS customer_name,
        c.mobile AS customer_mobile,
        c.email AS customer_email,
        d.name AS dealer_name,
        d.mobile AS dealer_mobile,
        d.station_name AS dealer_station
    FROM transactions t
    LEFT JOIN customers c ON c.id = t.customer_id
    LEFT JOIN dealers d ON d.id = t.dealer_id
";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY t.id DESC";

$stmt = $db->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$transactions = [];
$total_amount = 0;
$total_discount = 0;
$total_final = 0;

while ($row = $result->fetch_assoc()) {
    $total_amount   += (float)$row['amount'];
    $total_discount += (float)$row['discount'];
    $total_final    += (float)$row['final_amount'];

    $transactions[] = [
        'id'              => (int)$row['id'],
        'customer_id'     => (int)$row['customer_id'],
        'customer_name'   => $row['customer_name'] ?? '—',
        'customer_mobile' => $row['customer_mobile'] ?? '',
        'customer_email'  => $row['customer_email'] ?? '',
        'dealer_id'       => $row['dealer_id'] ? (int)$row['dealer_id'] : null,
        'dealer_name'     => $row['dealer_name'] ?? '—',
        'dealer_mobile'   => $row['dealer_mobile'] ?? '',
        'dealer_station'  => $row['dealer_station'] ?? '',
        'player_key'      => $row['player_key'],
        'player_id'       => $row['player_id'],
        'station_name'    => $row['station_name'],
        'amount'          => (float)$row['amount'],
        'discount'        => (float)$row['discount'],
        'final_amount'    => (float)$row['final_amount'],
        'coupon_id'       => $row['coupon_id'] ? (int)$row['coupon_id'] : null,
        'coupon_code'     => $row['coupon_code'] ?? '',
        'status'          => $row['status'],
        'transaction_ref' => $row['transaction_ref'] ?? '',
        'created_at'      => $row['created_at'],
        'updated_at'      => $row['updated_at'],
    ];
}
$stmt->close();

jsonResponse([
    'status'         => 'success',
    'message'        => 'Transactions fetched successfully',
    'total'          => count($transactions),
    'total_amount'   => round($total_amount, 2),
    'total_discount' => round($total_discount, 2),
    'total_final'    => round($total_final, 2),
    'transactions'   => $transactions,
]);
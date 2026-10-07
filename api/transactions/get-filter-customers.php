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

$input     = getInput();
$dealer_id = isset($input['dealer_id']) ? (int)$input['dealer_id'] : 0;

$where = ["t.customer_id IS NOT NULL"];
$params = [];
$types = '';

if ($dealer_id > 0) {
    $where[] = "t.dealer_id = ?";
    $params[] = $dealer_id;
    $types .= 'i';
}

$sql = "
    SELECT DISTINCT c.id, c.name, c.mobile, c.email
    FROM hascol_customer c
    INNER JOIN transactions t ON t.customer_id = c.id
    WHERE " . implode(" AND ", $where) . "
    ORDER BY c.name ASC
";

$stmt = $db->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$hascol_customer = [];
while ($row = $result->fetch_assoc()) {
    $hascol_customer[] = [
        'id'     => (int)$row['id'],
        'name'   => $row['name'],
        'mobile' => $row['mobile'] ?? '',
        'email'  => $row['email'] ?? '',
    ];
}
$stmt->close();

jsonResponse([
    'status'    => 'success',
    'message'   => 'Customers fetched successfully',
    'total'     => count($hascol_customer),
    'hascol_customer' => $hascol_customer,
]);
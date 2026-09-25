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

// Only hascol_customer who have used at least one referral
$sql = "
    SELECT DISTINCT c.id, c.name, c.mobile, c.email
    FROM hascol_customer c
    INNER JOIN referral_numbers r ON r.used_by_customer_id = c.id
    WHERE r.is_used = 1 AND r.used_by_customer_id IS NOT NULL
    ORDER BY c.name ASC
";
$result = $db->query($sql);

$hascol_customer = [];
while ($row = $result->fetch_assoc()) {
    $hascol_customer[] = [
        'id'     => (int)$row['id'],
        'name'   => $row['name'],
        'mobile' => $row['mobile'] ?? '',
        'email'  => $row['email'] ?? '',
    ];
}

jsonResponse([
    'status'    => 'success',
    'message'   => 'Customers fetched successfully',
    'total'     => count($hascol_customer),
    'hascol_customer' => $hascol_customer,
]);
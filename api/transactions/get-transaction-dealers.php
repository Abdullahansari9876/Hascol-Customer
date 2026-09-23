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

// Only dealers who have transactions
$sql = "
    SELECT DISTINCT d.id, d.name, d.mobile, d.station_name
    FROM dealers d
    INNER JOIN transactions t ON t.dealer_id = d.id
    WHERE t.dealer_id IS NOT NULL
    ORDER BY d.name ASC
";
$result = $db->query($sql);

$dealers = [];
while ($row = $result->fetch_assoc()) {
    $dealers[] = [
        'id'           => (int)$row['id'],
        'name'         => $row['name'],
        'mobile'       => $row['mobile'] ?? '',
        'station_name' => $row['station_name'] ?? '',
    ];
}

jsonResponse([
    'status'  => 'success',
    'message' => 'Dealers fetched successfully',
    'total'   => count($dealers),
    'dealers' => $dealers,
]);
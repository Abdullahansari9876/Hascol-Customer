<?php
/**
 * GET DEALERS API
 * POST /api/dealers/get-dealers.php
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

// Filters
$input  = getInput();
$status = trim($input['status'] ?? '');
$city   = trim($input['city'] ?? '');

$where = [];
$params = [];
$types = '';

if ($status !== '' && in_array($status, ['active', 'inactive'])) {
    $where[] = "status = ?";
    $params[] = $status;
    $types .= 's';
}
if ($city !== '') {
    $where[] = "city = ?";
    $params[] = $city;
    $types .= 's';
}

$sql = "SELECT id, name, mobile, email, station_name, address, city, status, last_login, created_at, updated_at 
        FROM dealers";
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY id DESC";

$stmt = $db->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$dealers = [];
while ($row = $result->fetch_assoc()) {
    $dealers[] = [
        'id'           => (int)$row['id'],
        'name'         => $row['name'],
        'mobile'       => $row['mobile'],
        'email'        => $row['email'] ?? '',
        'station_name' => $row['station_name'],
        'address'      => $row['address'] ?? '',
        'city'         => $row['city'] ?? '',
        'status'       => $row['status'],
        'last_login'   => $row['last_login'] ?? null,
        'created_at'   => $row['created_at'],
        'updated_at'   => $row['updated_at'],
    ];
}
$stmt->close();

jsonResponse([
    'status'  => 'success',
    'message' => 'Dealers fetched successfully',
    'total'   => count($dealers),
    'dealers' => $dealers,
]);
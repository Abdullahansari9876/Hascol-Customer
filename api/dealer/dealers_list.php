<?php

// ✅ Sirf OPTIONS handle karein (CORS ke liye)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

// ─── Sirf GET method allow karo ───
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    jsonResponse([
        'status'  => 'error',
        'message' => 'Method not allowed. Use GET.'
    ]);
}

// ─── Input lo (GET + JSON dono support) ───
$input = array_merge($_GET, getInput() ?? []);

$status = trim($input['status'] ?? '');
$city   = trim($input['city'] ?? '');
$search = trim($input['search'] ?? '');

// ─── Where conditions build karo ───
$where  = [];
$params = [];
$types  = '';

// Status filter (sirf active/inactive allow)
if ($status !== '' && in_array($status, ['active', 'inactive'])) {
    $where[]  = "status = ?";
    $params[] = $status;
    $types   .= 's';
}

// City filter
if ($city !== '') {
    $where[]  = "city = ?";
    $params[] = $city;
    $types   .= 's';
}

// Search filter (name, station_name, mobile)
if ($search !== '') {
    $where[]  = "(name LIKE ? OR station_name LIKE ? OR mobile LIKE ?)";
    $like     = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'sss';
}

$whereSql = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";

// ═══════════════════════════════════════════════
// Main list query (saare dealers, no pagination)
// ═══════════════════════════════════════════════
$sql = "SELECT id, name, mobile, email, station_name, address,
               city, latitude, longitude, status, profile_img,
               last_login, created_at, updated_at
        FROM hascol_dealers" . $whereSql . "
        ORDER BY id DESC";

$stmt = $db->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// ─── Base URL for profile_img ───
$baseUrl = defined('BASE_URL')
    ? BASE_URL
    : 'https://hascol.allowance.flamboyant-spence.92-205-119-218.plesk.page/';

$hascol_dealers = [];
while ($row = $result->fetch_assoc()) {
    $profileImg = !empty($row['profile_img'])
        ? rtrim($baseUrl, '/') . '/' . ltrim($row['profile_img'], '/')
        : '';

    $hascol_dealers[] = [
        'id'           => (int)$row['id'],
        'name'         => $row['name'],
        'mobile'       => $row['mobile'],
        'email'        => $row['email'] ?? '',
        'station_name' => $row['station_name'],
        'address'      => $row['address'] ?? '',
        'city'         => $row['city'] ?? '',
        'latitude'     => $row['latitude'],
        'longitude'    => $row['longitude'],
        'status'       => $row['status'],
        'profile_img'  => $profileImg,
        'last_login'   => $row['last_login'] ?? null,
        'created_at'   => $row['created_at'],
        'updated_at'   => $row['updated_at'],
    ];
}
$stmt->close();

http_response_code(200);
jsonResponse([
    'status'  => 'success',
    'message' => 'Dealers fetched successfully',
    'total'   => count($hascol_dealers),
    'hascol_dealers' => $hascol_dealers,
]);
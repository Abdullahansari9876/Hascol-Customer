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

// ✅ id ya dealer_id dono accept karo
$dealerId = (int)($input['id'] ?? $input['dealer_id'] ?? 0);

// ─── Validation: id zaroori hai ───
if ($dealerId <= 0) {
    http_response_code(400);
    jsonResponse([
        'status'  => 'error',
        'message' => 'Valid dealer id is required'
    ]);
}

// ═══════════════════════════════════════════════
// Single dealer fetch karo (id ke behalf pe)
// ═══════════════════════════════════════════════
$stmt = $db->prepare("
    SELECT id, name, mobile, email, station_name, address,
           city, latitude, longitude, status, profile_img,
           last_login, created_at, updated_at
    FROM hascol_dealers
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $dealerId);
$stmt->execute();
$dealer = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ─── Dealer nahi mila ───
if (!$dealer) {
    http_response_code(404);
    jsonResponse([
        'status'  => 'error',
        'message' => 'Dealer not found'
    ]);
}

// ─── Profile image ka full URL banao ───
$profileImg = '';
if (!empty($dealer['profile_img'])) {
    $baseUrl = defined('BASE_URL')
        ? BASE_URL
        : 'https://hascol.allowance.flamboyant-spence.92-205-119-218.plesk.page/';
    $profileImg = rtrim($baseUrl, '/') . '/' . ltrim($dealer['profile_img'], '/');
}

// ─── Success Response ───
http_response_code(200);
jsonResponse([
    'status'  => 'success',
    'message' => 'Dealer details fetched successfully',
    'dealer'  => [
        'id'           => (int)$dealer['id'],
        'name'         => $dealer['name'],
        'mobile'       => $dealer['mobile'],
        'email'        => $dealer['email'] ?? '',
        'station_name' => $dealer['station_name'],
        'address'      => $dealer['address'] ?? '',
        'city'         => $dealer['city'] ?? '',
        'latitude'     => $dealer['latitude'],
        'longitude'    => $dealer['longitude'],
        'status'       => $dealer['status'],
        'profile_img'  => $profileImg,
        'last_login'   => $dealer['last_login'] ?? null,
        'created_at'   => $dealer['created_at'],
        'updated_at'   => $dealer['updated_at'],
    ],
]);
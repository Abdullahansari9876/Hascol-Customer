<?php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    jsonResponse(['status' => 'error', 'message' => 'Only GET method allowed']);
}

$customerId = (int)($_GET['customer_id'] ?? $_GET['id'] ?? 0);

if ($customerId <= 0) {
    http_response_code(400);
    jsonResponse(['status' => 'error', 'message' => 'customer_id is required']);
}

// password, device_token, player_key jaan bujh kar select nahi kiye (sensitive)
$stmt = $db->prepare("
    SELECT id, player_id, name, email, profile_image, mobile, imei, cnic, address,
           coupon_no, customer_type, verified, status, ip_address,
           created_at, updated_at, verified_at,
           total_coupons, remaining_coupons, used_coupons, last_login
    FROM hascol_customer
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$c = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$c) {
    http_response_code(404);
    jsonResponse(['status' => 'error', 'message' => 'Customer not found']);
}

jsonResponse([
    'status'  => 'success',
    'message' => 'Customer fetched successfully',
    'customer' => [
        'id'                => (int)$c['id'],
        // 'player_id'         => $c['player_id'],
        'name'              => $c['name'],
        'email'             => $c['email'] ?? '',
        'profile_image'     => $c['profile_image'] ?? '',
        'mobile'            => $c['mobile'] ?? '',
        'imei'              => $c['imei'] ?? '',
        'cnic'              => $c['cnic'] ?? '',
        'address'           => $c['address'] ?? '',
        'coupon_no'         => $c['coupon_no'] ?? '',
        'customer_type'     => $c['customer_type'],
        'verified'          => ((int)$c['verified'] === 1),
        'status'            => $c['status'],
        'ip_address'        => $c['ip_address'] ?? '',
        'created_at'        => $c['created_at'],
        'updated_at'        => $c['updated_at'],
        'verified_at'       => $c['verified_at'],
        'total_coupons'     => (int)$c['total_coupons'],
        'remaining_coupons' => (int)$c['remaining_coupons'],
        'used_coupons'      => (int)$c['used_coupons'],
        'last_login'        => $c['last_login'],
    ],
]);
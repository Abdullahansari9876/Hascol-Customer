<?php
/**
 * DASHBOARD API
 * 
 * POST /api/dashboard.php
 * Body: { token }
 * 
 * Token se customer identify karke dashboard data return karta hai.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'config.php';
require 'db.php';

$input = getInput();
$token = trim($input['token'] ?? '');

// ─── Validation ───
if (empty($token)) {
    jsonResponse(['status'=>'error','message'=>'token required']);
}

// ─── Token se session dhoondein ───
$stmt = $db->prepare("
    SELECT * FROM sessions 
    WHERE token = ? AND is_active = 1 AND expires_at > NOW() 
    LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session) {
    jsonResponse(['status'=>'error','message'=>'Invalid or expired token. Please login again.']);
}

$playerId = $session['player_id'];
$key      = $session['player_key'];

// ─── Customer detail lein ───
$stmt = $db->prepare("
    SELECT id, player_id, name, email, mobile, imei, verified, status,
           total_coupons, remaining_coupons, used_coupons,
           created_at, verified_at, last_login
    FROM hascol_customer 
    WHERE player_key = ? LIMIT 1
");
$stmt->bind_param("s", $key);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    jsonResponse(['status'=>'error','message'=>'Customer not found']);
}

// ─── Latest available offers (coupons se) ───
$stmt = $db->prepare("
    SELECT id, coupon_code, title, description, 
           discount_percent, discount_amount, min_purchase, 
           valid_from, valid_to
    FROM coupons 
    WHERE customer_id = ? AND status = 'available' AND valid_to > NOW()
    ORDER BY id DESC 
    LIMIT 5
");
$stmt->bind_param("i", $customer['id']);
$stmt->execute();
$result = $stmt->get_result();
$latestOffers = [];
while ($row = $result->fetch_assoc()) {
    $latestOffers[] = [
        'id'               => (int)$row['id'],
        'coupon_code'      => $row['coupon_code'],
        'title'            => $row['title'],
        'description'      => $row['description'] ?? '',
        'discount_percent' => (float)$row['discount_percent'],
        'discount_amount'  => (float)$row['discount_amount'],
        'min_purchase'     => (float)$row['min_purchase'],
        'valid_from'       => $row['valid_from'],
        'valid_to'         => $row['valid_to'],
    ];
}
$stmt->close();

// ─── Member since format ───
$memberSince = date('d M Y', strtotime($customer['created_at']));

// ─── Success ───
jsonResponse([
    'status'  => 'success',
    'message' => 'Dashboard data fetched successfully',
    'data'    => [
        'customer' => [
            'id'         => (int)$customer['id'],
            'player_id'  => $customer['player_id'],
            'name'       => $customer['name'],
            'email'      => $customer['email'] ?? '',
            'mobile'     => $customer['mobile'] ?? '',
            'imei'       => $customer['imei'] ?? '',
            'verified'   => (bool)$customer['verified'],
            'member_since' => $memberSince,
        ],
        'coupons' => [
            'total'     => (int)$customer['total_coupons'],
            'remaining' => (int)$customer['remaining_coupons'],
            'used'      => (int)$customer['used_coupons'],
        ],
        'latest_offers' => $latestOffers,
    ],
]);
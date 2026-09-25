<?php
/**
 * 
 * GET /hascol_customer/api/profile/get-profile.php?customer_id=123
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ─── Config aur DB (profile folder se 2 step peeche) ───
require __DIR__ . '/../config.php';
require __DIR__ . '/../db.php';

define('BASE_URL', 'https://hascol.allowance.flamboyant-spence.92-205-119-218.plesk.page/');

// ─── Sirf GET method allow karo ───
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['status' => 'error', 'message' => 'Only GET method allowed']);
}

// ─── GET se customer_id lo ───
$customerId = (int) ($_GET['customer_id'] ?? 0);

// ─── Validation ───
if ($customerId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'customer_id required']);
}

// ─── Customer detail ───
$stmt = $db->prepare("
    SELECT id, player_id, name, email, profile_image, mobile, imei, 
           cnic, address,
           verified, status,
           total_coupons, remaining_coupons, used_coupons,
           created_at, verified_at, last_login
    FROM hascol_customer 
    WHERE id = ? LIMIT 1
");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    jsonResponse(['status' => 'error', 'message' => 'Customer not found']);
}

// ─── Full image URL banao ───
$fullImageUrl = !empty($customer['profile_image'])
    ? BASE_URL . $customer['profile_image']
    : '';

jsonResponse([
    'status' => 'success',
    'message' => 'Profile fetched successfully',
    'profile' => [
        'id' => (int) $customer['id'],
        // 'player_id' => $customer['player_id'],
        'name' => $customer['name'],
        'email' => $customer['email'] ?? '',
        'profile_image' => $fullImageUrl,
        'mobile' => $customer['mobile'] ?? '',
        // 'imei' => $customer['imei'] ?? '',
        'cnic' => $customer['cnic'] ?? '',
        'address' => $customer['address'] ?? '',
        'verified' => (bool) $customer['verified'],
        'status' => $customer['status'],
        'total_coupons' => (int) $customer['total_coupons'],
        'remaining_coupons' => (int) $customer['remaining_coupons'],
        'used_coupons' => (int) $customer['used_coupons'],
        'member_since' => date('d M Y', strtotime($customer['created_at'])),
        'verified_at' => $customer['verified_at'],
        'last_login' => $customer['last_login'],
    ],
]);
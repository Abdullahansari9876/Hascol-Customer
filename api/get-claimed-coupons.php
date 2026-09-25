<?php
/**
 * GET CLAIMED COUPONS API
 * 
 * POST /api/customer/get-claimed-coupons.php
 * Body: { customer_id }
 * 
 * Customer ke saare claimed coupons return karta hai.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

$input      = getInput();
$customerId = (int)($input['customer_id'] ?? 0);

if ($customerId <= 0) {
    jsonResponse(['status'=>'error','message'=>'customer_id required']);
}

// ─── Customer check ───
$stmt = $db->prepare("
    SELECT id, name, mobile, total_coupons, remaining_coupons, used_coupons 
    FROM hascol_customer 
    WHERE id = ? LIMIT 1
");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    jsonResponse(['status'=>'error','message'=>'Customer not found']);
}

// ─── Coupons lein ───
$stmt = $db->prepare("
    SELECT id, coupon_code, title, description, 
           discount_percent, discount_amount, min_purchase, 
           valid_from, valid_to, status, used_at, used_at_station 
    FROM coupons 
    WHERE customer_id = ? 
    ORDER BY id DESC
");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$result = $stmt->get_result();

$coupons = [];
while ($row = $result->fetch_assoc()) {
    $coupons[] = [
        'id'               => (int)$row['id'],
        'coupon_code'      => $row['coupon_code'],
        'title'            => $row['title'],
        'description'      => $row['description'] ?? '',
        'discount_percent' => (float)$row['discount_percent'],
        'discount_amount'  => (float)$row['discount_amount'],
        'min_purchase'     => (float)$row['min_purchase'],
        'valid_from'       => $row['valid_from'],
        'valid_to'         => $row['valid_to'],
        'status'           => $row['status'],
        'used_at'          => $row['used_at'] ?? '',
        'used_at_station'  => $row['used_at_station'] ?? '',
        'date_formatted'   => date('d M Y', strtotime($row['valid_to'])),
    ];
}
$stmt->close();

jsonResponse([
    'status'   => 'success',
    'message'  => 'Coupons fetched successfully',
    'customer' => [
        'id'     => (int)$customer['id'],
        'name'   => $customer['name'],
        'mobile' => $customer['mobile'],
    ],
    'summary' => [
        'total'     => (int)$customer['total_coupons'],
        'remaining' => (int)$customer['remaining_coupons'],
        'used'      => (int)$customer['used_coupons'],
    ],
    'coupons' => $coupons,
]);
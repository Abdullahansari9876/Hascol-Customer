<?php
/**
 * GET COUPONS API (Dealer App)
 * 
 * Query/URL Parameter: customer_id
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

// ─── Get customer_id from URL parameter ───
$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

// ─── Validation ───
if (empty($customerId) || $customerId <= 0) {
    jsonResponse(['status'=>'error','message'=>'Valid customer_id required']);
}

// ─── Customer dhoondein customer_id se ───
$stmt = $db->prepare("
    SELECT id, name, mobile, 
           total_coupons, remaining_coupons, used_coupons 
    FROM customers 
    WHERE id = ? 
    LIMIT 1
");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    jsonResponse(['status'=>'error','message'=>'Customer not found with this ID']);
}

// ─── Customer ke coupons lein ───
$stmt = $db->prepare("
    SELECT id, title, description, image_url, 
           discount_percent, 
           valid_from, valid_to, status, used_at, used_at_station, created_at
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
        'title'            => $row['title'],
        'description'      => $row['description'] ?? '',
        'image_url'        => $row['image_url'] ?? '',
        'discount_percent' => (float)$row['discount_percent'],
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
    'status'  => 'success',
    'message' => 'Coupons fetched successfully',
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
    'total_coupons' => count($coupons),
    'coupons' => $coupons,
]);
<?php
/**
 * GET CUSTOMERS API
 * POST /api/customers/get-customers.php
 * Body: { status?, customer_type?, verified?, search? }
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

$input         = getInput();
$status        = trim($input['status'] ?? '');
$customer_type = trim($input['customer_type'] ?? '');
$verified      = $input['verified'] ?? '';
$search        = trim($input['search'] ?? '');

$where = [];
$params = [];
$types = '';

if ($status !== '' && in_array($status, ['active', 'inactive', 'banned'])) {
    $where[] = "status = ?";
    $params[] = $status;
    $types .= 's';
}

if ($customer_type !== '' && in_array($customer_type, ['new_customer', 'referred_customer'])) {
    $where[] = "customer_type = ?";
    $params[] = $customer_type;
    $types .= 's';
}

if ($verified !== '' && ($verified === '0' || $verified === '1' || $verified === 0 || $verified === 1)) {
    $where[] = "verified = ?";
    $params[] = (int)$verified;
    $types .= 'i';
}

if ($search !== '') {
    $where[] = "(name LIKE ? OR email LIKE ? OR mobile LIKE ? OR cnic LIKE ? OR coupon_no LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}

$sql = "SELECT id, player_key, player_id, name, email, profile_image, mobile, 
               imei, cnic, address, coupon_no, customer_type, verified, status, 
               ip_address, created_at, updated_at, verified_at,
               total_coupons, remaining_coupons, used_coupons, last_login
        FROM customers";

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

$customers = [];
while ($row = $result->fetch_assoc()) {
    $customers[] = [
        'id'                => (int)$row['id'],
        'player_key'        => $row['player_key'],
        'player_id'         => $row['player_id'],
        'name'              => $row['name'],
        'email'             => $row['email'] ?? '',
        'profile_image'     => $row['profile_image'] ?? '',
        'mobile'            => $row['mobile'] ?? '',
        'imei'              => $row['imei'] ?? '',
        'cnic'              => $row['cnic'] ?? '',
        'address'           => $row['address'] ?? '',
        'coupon_no'         => $row['coupon_no'] ?? '',
        'customer_type'     => $row['customer_type'],
        'verified'          => (int)$row['verified'],
        'status'            => $row['status'],
        'ip_address'        => $row['ip_address'] ?? '',
        'created_at'        => $row['created_at'],
        'updated_at'        => $row['updated_at'],
        'verified_at'       => $row['verified_at'],
        'total_coupons'     => (int)$row['total_coupons'],
        'remaining_coupons' => (int)$row['remaining_coupons'],
        'used_coupons'      => (int)$row['used_coupons'],
        'last_login'        => $row['last_login'],
    ];
}
$stmt->close();

jsonResponse([
    'status'    => 'success',
    'message'   => 'Customers fetched successfully',
    'total'     => count($customers),
    'customers' => $customers,
]);
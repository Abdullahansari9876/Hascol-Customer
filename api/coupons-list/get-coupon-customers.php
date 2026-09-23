<?php
/**
 * GET COUPON CUSTOMERS (for filter dropdown)
 * POST /api/coupons/get-coupon-customers.php
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

$sql = "
    SELECT DISTINCT c.id, c.name, c.mobile, c.email
    FROM customers c
    INNER JOIN coupons cp ON cp.customer_id = c.id
    WHERE cp.customer_id IS NOT NULL
    ORDER BY c.name ASC
";
$result = $db->query($sql);

$customers = [];
while ($row = $result->fetch_assoc()) {
    $customers[] = [
        'id'     => (int)$row['id'],
        'name'   => $row['name'],
        'mobile' => $row['mobile'] ?? '',
        'email'  => $row['email'] ?? '',
    ];
}

jsonResponse([
    'status'    => 'success',
    'message'   => 'Customers fetched successfully',
    'total'     => count($customers),
    'customers' => $customers,
]);
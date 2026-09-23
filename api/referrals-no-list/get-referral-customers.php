<?php
/**
 * GET REFERRAL CUSTOMERS (for filter dropdown)
 * POST /api/referrals-no-list/get-referral-customers.php
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

// Only customers who have used at least one referral
$sql = "
    SELECT DISTINCT c.id, c.name, c.mobile, c.email
    FROM customers c
    INNER JOIN referral_numbers r ON r.used_by_customer_id = c.id
    WHERE r.is_used = 1 AND r.used_by_customer_id IS NOT NULL
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
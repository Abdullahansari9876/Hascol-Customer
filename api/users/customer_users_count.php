<?php
/**
 * Customer Referral Stats API
 * Endpoint : GET /api/customer_users_count.php?customer_id=1
 *
 * Purpose  : Kisi customer ne kitne referrals banaye, kitne use hue,
 *            kitne unused hain, aur kitne alag customers ne use kiye.
 *
 * Response : {
 *   "success": true,
 *   "data": {
 *     "customer_id": 1,
 *     "total_referrals": 5,        // Total banaye
 *     "used_referrals": 3,         // Use ho gaye
 *     "unused_referrals": 2,       // Baaki hain
 *     "total_referred_users": 3    // Kitne alag customers ne use kiye
 *   }
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../db.php';

function countRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// 1) Method validation
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    countRespond(405, [
        'success' => false,
        'message' => 'Method not allowed. Use GET.'
    ]);
}

// 2) customer_id validation
if (!isset($_GET['customer_id']) || $_GET['customer_id'] === '') {
    countRespond(422, [
        'success' => false,
        'message' => 'Validation failed.',
        'errors'  => ['customer_id' => 'customer_id is required.']
    ]);
}

$customerId = filter_var(
    $_GET['customer_id'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($customerId === false) {
    countRespond(422, [
        'success' => false,
        'message' => 'Validation failed.',
        'errors'  => ['customer_id' => 'customer_id must be a positive integer.']
    ]);
}

$db = getDBConnection();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // 3) Customer exist check
    $custStmt = $db->prepare("SELECT id FROM hascol_customer WHERE id = ? LIMIT 1");
    $custStmt->bind_param('i', $customerId);
    $custStmt->execute();
    $customer = $custStmt->get_result()->fetch_assoc();
    $custStmt->close();

    if (!$customer) {
        countRespond(404, [
            'success' => false,
            'message' => 'Customer not found.',
            'errors'  => ['customer_id' => 'No customer exists with this customer_id.']
        ]);
    }

    // 4) All stats ek hi query mein
    $statsStmt = $db->prepare("
        SELECT 
            COUNT(*) AS total_referrals,
            SUM(CASE WHEN is_used = 1 THEN 1 ELSE 0 END) AS used_referrals,
            SUM(CASE WHEN is_used = 0 THEN 1 ELSE 0 END) AS unused_referrals,
            COUNT(DISTINCT CASE WHEN is_used = 1 THEN used_by_customer_id END) AS total_referred_users
        FROM referral_numbers 
        WHERE referred_by_customer_id = ?
    ");
    $statsStmt->bind_param('i', $customerId);
    $statsStmt->execute();
    $row = $statsStmt->get_result()->fetch_assoc();
    $statsStmt->close();

    countRespond(200, [
        'success' => true,
        'message' => 'Referral stats fetched successfully.',
        'data'    => [
            'customer_id'          => $customerId,
            'total_referrals'      => (int)$row['total_referrals'],
            'used_referrals'       => (int)$row['used_referrals'],
            'unused_referrals'     => (int)$row['unused_referrals']
        ]
    ]);
} catch (Throwable $e) {
    error_log('customer_users_count error: ' . $e->getMessage());
    countRespond(500, [
        'success' => false,
        'message' => 'Internal server error.'
    ]);
}
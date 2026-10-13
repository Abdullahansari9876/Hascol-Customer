<?php
/**
 * Customer Users Count API
 * Endpoint : GET /api/customer_users_count.php?customer_id=1
 * Purpose  : Kisi customer ne kitne users ko referral diya, uski total count return karta hai
 * Response : { "success": true, "data": { "customer_id": 1, "total_referred_users": 3 } }
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../db.php'; // path apne project ke mutabiq adjust karein

function countRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// 1) Request method validation
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    countRespond(405, [
        'success' => false,
        'message' => 'Method not allowed. Use GET.'
    ]);
}

// 2) customer_id validation (query string se)
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

    // 4) Count
    $cntStmt = $db->prepare(
        "SELECT COUNT(*) AS total FROM referral_numbers WHERE referred_by_customer_id = ?"
    );
    $cntStmt->bind_param('i', $customerId);
    $cntStmt->execute();
    $row = $cntStmt->get_result()->fetch_assoc();
    $cntStmt->close();

    countRespond(200, [
        'success' => true,
        'message' => 'Referred users count fetched successfully.',
        'data'    => [
            'customer_id'          => $customerId,
            'total_referred_users' => (int)$row['total']
        ]
    ]);
} catch (Throwable $e) {
    error_log('customer_users_count error: ' . $e->getMessage());
    countRespond(500, [
        'success' => false,
        'message' => 'Internal server error.'
    ]);
}
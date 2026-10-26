<?php
/**
 * Customer Referral Stats API (With Names)
 * Endpoint : GET /api/customer_users_count.php?customer_id=1
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
    $custStmt = $db->prepare("SELECT id, name FROM hascol_customer WHERE id = ? LIMIT 1");
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

    // 4) Counts (existing logic - same)
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

    // 5) USED referrals detail — with used-by customer name
    $usedStmt = $db->prepare("
        SELECT 
            rn.referral_no,
            rn.user_name        AS referred_person_name,
            rn.used_by_customer_id,
            hc.name             AS used_by_name,
            hc.mobile           AS used_by_mobile,
            hc.email            AS used_by_email,
            rn.used_at
        FROM referral_numbers rn
        INNER JOIN hascol_customer hc ON hc.id = rn.used_by_customer_id
        WHERE rn.referred_by_customer_id = ? AND rn.is_used = 1
        ORDER BY rn.used_at DESC
    ");
    $usedStmt->bind_param('i', $customerId);
    $usedStmt->execute();
    $usedResult = $usedStmt->get_result();

    $usedReferrals = [];
    while ($r = $usedResult->fetch_assoc()) {
        $usedReferrals[] = [
            'referral_no'         => $r['referral_no'],
            'referred_person'     => $r['referred_person_name'],
            'used_by_customer_id' => (int)$r['used_by_customer_id'],
            'used_by_name'        => $r['used_by_name'],
            'used_by_mobile'      => $r['used_by_mobile'],
            'used_by_email'       => $r['used_by_email'],
            'used_at'             => $r['used_at'],
        ];
    }
    $usedStmt->close();

    // 6) UNUSED referrals detail — names kaun hain jinhone use NAHI kiya
    $unusedStmt = $db->prepare("
        SELECT 
            rn.referral_no,
            rn.user_name   AS referred_person_name,
            rn.created_at
        FROM referral_numbers rn
        WHERE rn.referred_by_customer_id = ? AND rn.is_used = 0
        ORDER BY rn.created_at DESC
    ");
    $unusedStmt->bind_param('i', $customerId);
    $unusedStmt->execute();
    $unusedResult = $unusedStmt->get_result();

    $unusedReferrals = [];
    while ($r = $unusedResult->fetch_assoc()) {
        $unusedReferrals[] = [
            'referral_no'     => $r['referral_no'],
            'referred_person' => $r['referred_person_name'],
            'created_at'      => $r['created_at'],
        ];
    }
    $unusedStmt->close();

    // 7) Final Response
    countRespond(200, [
        'success' => true,
        'message' => 'Referral stats fetched successfully.',
        'data'    => [
            'customer_id'          => $customerId,
            'customer_name'        => $customer['name'],

            // Counts (existing)
            'total_referrals'      => (int)$row['total_referrals'],
            'used_referrals'       => (int)$row['used_referrals'],
            'unused_referrals'     => (int)$row['unused_referrals'],
            'total_referred_users' => (int)$row['total_referred_users'],

            // NEW — Detail lists with names
            'used_list'   => $usedReferrals,
            'unused_list' => $unusedReferrals,
        ]
    ]);

} catch (Throwable $e) {
    error_log('customer_users_count error: ' . $e->getMessage());
    countRespond(500, [
        'success' => false,
        'message' => 'Internal server error.'
    ]);
}
<?php
/**
 * ASSIGN COUPONS TO A SPECIFIC EXISTING CUSTOMER
 * 
 * POST /api/coupons-list/assign-coupons.php
 * 
 * INPUT (JSON):
 *   - customer_id     (int, required)
 *   - coupons_count   (int, 1-10, required)
 *   - discount_type   ("percent" | "flat", required)
 *   - discount_value  (decimal > 0, required)
 *   - title           (string, optional) — auto-generates "Welcome X% Off"
 *   - description     (string, optional) — auto-generates "Get X% Discount"
 */

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require '../config.php';
require '../db.php';

// ═══════════════════════════════════════════════════
// STEP 1: Only POST allowed
// ═══════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Only POST method allowed']);
}

// ═══════════════════════════════════════════════════
// STEP 2: Read + validate input
// ═══════════════════════════════════════════════════
$input = getInput();

$customerId    = isset($input['customer_id'])    ? (int)    $input['customer_id']    : 0;
$couponsCount  = isset($input['coupons_count'])  ? (int)    $input['coupons_count']  : 0;
$discountType  = isset($input['discount_type'])  ? trim($input['discount_type'])     : '';
$discountValue = isset($input['discount_value']) ? (float)  $input['discount_value'] : 0;
$title         = isset($input['title'])          ? trim($input['title'])             : '';
$description   = isset($input['description'])    ? trim($input['description'])       : '';

if ($customerId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'Valid customer_id is required']);
}
if ($couponsCount < 1 || $couponsCount > 10) {
    jsonResponse(['status' => 'error', 'message' => 'coupons_count must be between 1 and 10']);
}
if (!in_array($discountType, ['percent', 'flat'], true)) {
    jsonResponse(['status' => 'error', 'message' => 'discount_type must be "percent" or "flat"']);
}
if ($discountValue <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'discount_value must be greater than 0']);
}
if ($discountType === 'percent' && $discountValue > 100) {
    jsonResponse(['status' => 'error', 'message' => 'Percent discount cannot exceed 100']);
}

// ── Format discount value for display (remove trailing zeros) ──
$discountValueFormatted = rtrim(rtrim(number_format($discountValue, 2, '.', ''), '0'), '.');

// ── Auto-generate Title if empty ──
if ($title === '') {
    $title = ($discountType === 'percent')
        ? 'Welcome ' . $discountValueFormatted . '% Off'
        : 'Welcome Rs.' . number_format($discountValue, 0) . ' Off';
}

// ── Auto-generate Description if empty ──
if ($description === '') {
    $description = ($discountType === 'percent')
        ? 'Get ' . $discountValueFormatted . '% Discount'
        : 'Get Rs.' . number_format($discountValue, 0) . ' Discount';
}

if (mb_strlen($title) > 200) {
    jsonResponse(['status' => 'error', 'message' => 'title must be under 200 characters']);
}
if (mb_strlen($description) > 1000) {
    jsonResponse(['status' => 'error', 'message' => 'description must be under 1000 characters']);
}

$discountPercent = ($discountType === 'percent') ? $discountValue : 0.00;
$discountAmount  = ($discountType === 'flat')    ? $discountValue : 0.00;

// ═══════════════════════════════════════════════════
// STEP 3: Customer exists + active check
// ═══════════════════════════════════════════════════
$stmt = $db->prepare("
    SELECT id, name, mobile, status 
    FROM hascol_customer 
    WHERE id = ? 
    LIMIT 1
");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    jsonResponse(['status' => 'error', 'message' => 'Customer not found']);
}
if ($customer['status'] !== 'active') {
    jsonResponse([
        'status'  => 'error',
        'message' => 'Cannot assign coupons — customer status is "' . $customer['status'] . '"'
    ]);
}

// ═══════════════════════════════════════════════════
// STEP 4: Duplicate prevention (same customer + same title + same day)
// ═══════════════════════════════════════════════════
$stmt = $db->prepare("
    SELECT COUNT(*) AS cnt 
    FROM coupons 
    WHERE customer_id = ? 
      AND title = ? 
      AND DATE(created_at) = CURDATE()
");
$stmt->bind_param("is", $customerId, $title);
$stmt->execute();
$dupCount = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

if ($dupCount > 0) {
    jsonResponse([
        'status'  => 'error',
        'message' => 'Coupons with the same title ("' . $title . '") were already assigned to this customer today. Please use a different title or try again tomorrow.'
    ]);
}

// ═══════════════════════════════════════════════════
// STEP 5: Insert N coupons (transaction)
// ═══════════════════════════════════════════════════
$validFrom = date('Y-m-d H:i:s');
$validTo   = date('Y-m-d H:i:s', strtotime('+365 days'));

$db->begin_transaction();

try {
    $stmt = $db->prepare("
        INSERT INTO coupons 
        (customer_id, title, description, image_url,
         discount_percent, discount_amount, min_purchase,
         valid_from, valid_to, status, created_at, updated_at)
        VALUES (?, ?, ?, NULL, ?, ?, 0.00, ?, ?, 'available', NOW(), NOW())
    ");

    $insertedIds = [];

    for ($i = 0; $i < $couponsCount; $i++) {
        $stmt->bind_param(
            "issddss",
            $customerId,
            $title,
            $description,
            $discountPercent,
            $discountAmount,
            $validFrom,
            $validTo
        );

        if (!$stmt->execute()) {
            throw new Exception('Coupon insert failed: ' . $stmt->error);
        }
        $insertedIds[] = $db->insert_id;
    }
    $stmt->close();

    // Update customer counters
    $stmt = $db->prepare("
        UPDATE hascol_customer 
        SET total_coupons = (SELECT COUNT(*) FROM coupons WHERE customer_id = ?),
            remaining_coupons = (SELECT COUNT(*) FROM coupons WHERE customer_id = ? AND status = 'available'),
            used_coupons = (SELECT COUNT(*) FROM coupons WHERE customer_id = ? AND status = 'used')
        WHERE id = ?
    ");
    $stmt->bind_param("iiii", $customerId, $customerId, $customerId, $customerId);
    if (!$stmt->execute()) {
        throw new Exception('Customer counters update failed: ' . $stmt->error);
    }
    $stmt->close();

    $db->commit();

} catch (Exception $e) {
    $db->rollback();
    error_log('ASSIGN COUPONS FAILED: ' . $e->getMessage());
    jsonResponse([
        'status'  => 'error',
        'message' => 'Coupons could not be assigned. Please try again.'
    ]);
}

// ═══════════════════════════════════════════════════
// STEP 6: Success response
// ═══════════════════════════════════════════════════
jsonResponse([
    'status'         => 'success',
    'message'        => $couponsCount . ' coupon(s) successfully assigned to ' . $customer['name'],
    'inserted_count' => $couponsCount,
    'customer_id'    => $customerId,
    'customer_name'  => $customer['name'],
    'coupon_ids'     => $insertedIds,
    'valid_from'     => $validFrom,
    'valid_to'       => $validTo,
]);
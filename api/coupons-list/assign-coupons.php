<?php
/**
 * ASSIGN COUPONS TO ONE OR MULTIPLE CUSTOMERS
 *
 * POST /api/coupons-list/assign-coupons.php
 *
 * INPUT (JSON) — two modes:
 *
 *   Mode A (single — backward compatible):
 *     - customer_id     (int, required)
 *
 *   Mode B (multiple — new):
 *     - customer_ids    (array of int, required)
 *
 *   Common fields:
 *     - coupons_count   (int, 1-10, required)
 *     - discount_type   ("percent" | "flat", required)
 *     - discount_value  (decimal > 0, required)
 *     - title           (string, optional)
 *     - description     (string, optional)
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

// ── Collect customer IDs (support both single + multiple) ──
$customerIds = [];

if (!empty($input['customer_ids']) && is_array($input['customer_ids'])) {
    foreach ($input['customer_ids'] as $cid) {
        $cid = (int) $cid;
        if ($cid > 0 && !in_array($cid, $customerIds, true)) {
            $customerIds[] = $cid; // duplicate IDs remove
        }
    }
} elseif (!empty($input['customer_id'])) {
    $cid = (int) $input['customer_id'];
    if ($cid > 0) $customerIds[] = $cid;
}

$couponsCount  = isset($input['coupons_count'])  ? (int)    $input['coupons_count']  : 0;
$discountType  = isset($input['discount_type'])  ? trim($input['discount_type'])     : '';
$discountValue = isset($input['discount_value']) ? (float)  $input['discount_value'] : 0;
$title         = isset($input['title'])          ? trim($input['title'])             : '';
$description   = isset($input['description'])    ? trim($input['description'])       : '';

if (empty($customerIds)) {
    jsonResponse(['status' => 'error', 'message' => 'Valid customer_id or customer_ids is required']);
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

// ── Format discount value for display ──
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
// STEP 3: Validate ALL customers (exists + active)
// ═══════════════════════════════════════════════════
$placeholders = implode(',', array_fill(0, count($customerIds), '?'));
$types        = str_repeat('i', count($customerIds));

$stmt = $db->prepare("
    SELECT id, name, mobile, status
    FROM hascol_customer
    WHERE id IN ($placeholders)
");
$stmt->bind_param($types, ...$customerIds);
$stmt->execute();
$result = $stmt->get_result();

$validCustomers = [];   // id => row
$invalidIds     = [];

while ($row = $result->fetch_assoc()) {
    if ($row['status'] === 'active') {
        $validCustomers[(int)$row['id']] = $row;
    } else {
        $invalidIds[] = (int)$row['id'];
    }
}
$stmt->close();

// Customer IDs jo DB me hi nahi mile
foreach ($customerIds as $cid) {
    if (!isset($validCustomers[$cid]) && !in_array($cid, $invalidIds, true)) {
        $invalidIds[] = $cid;
    }
}

if (empty($validCustomers)) {
    jsonResponse([
        'status'  => 'error',
        'message' => 'No active customers found for the selected IDs',
        'invalid_ids' => $invalidIds,
    ]);
}

// ═══════════════════════════════════════════════════
// STEP 4: Duplicate prevention — per customer
//         (skip customer agar same title aaj already assigned)
// ═══════════════════════════════════════════════════
$dupCustomerIds = [];
$checkStmt = $db->prepare("
    SELECT COUNT(*) AS cnt
    FROM coupons
    WHERE customer_id = ?
      AND title = ?
      AND DATE(created_at) = CURDATE()
");

foreach (array_keys($validCustomers) as $cid) {
    $checkStmt->bind_param("is", $cid, $title);
    $checkStmt->execute();
    $cnt = (int) $checkStmt->get_result()->fetch_assoc()['cnt'];
    if ($cnt > 0) $dupCustomerIds[] = $cid;
}
$checkStmt->close();

// Duplicate walon ko valid list se hata dein
foreach ($dupCustomerIds as $cid) {
    unset($validCustomers[$cid]);
}

if (empty($validCustomers)) {
    jsonResponse([
        'status'  => 'error',
        'message' => 'All selected customers already received coupons with title "' . $title . '" today. Please use a different title or try again tomorrow.',
        'duplicate_ids' => $dupCustomerIds,
    ]);
}

// ═══════════════════════════════════════════════════
// STEP 5: Insert coupons for each valid customer (transaction)
// ═══════════════════════════════════════════════════
$validFrom = date('Y-m-d H:i:s');
$validTo   = date('Y-m-d H:i:s', strtotime('+365 days'));

$db->begin_transaction();

try {
    $insertStmt = $db->prepare("
        INSERT INTO coupons
        (customer_id, title, description, image_url,
         discount_percent, discount_amount, min_purchase,
         valid_from, valid_to, status, created_at, updated_at)
        VALUES (?, ?, ?, NULL, ?, ?, 0.00, ?, ?, 'available', NOW(), NOW())
    ");

    $insertedIds   = [];
    $assignedCount = 0;

    foreach (array_keys($validCustomers) as $cid) {
        for ($i = 0; $i < $couponsCount; $i++) {
            $insertStmt->bind_param(
                "issddss",
                $cid,
                $title,
                $description,
                $discountPercent,
                $discountAmount,
                $validFrom,
                $validTo
            );

            if (!$insertStmt->execute()) {
                throw new Exception('Coupon insert failed for customer ' . $cid . ': ' . $insertStmt->error);
            }
            $insertedIds[] = $db->insert_id;
            $assignedCount++;
        }

        // Update counters per customer
        $counterStmt = $db->prepare("
            UPDATE hascol_customer
            SET total_coupons = (SELECT COUNT(*) FROM coupons WHERE customer_id = ?),
                remaining_coupons = (SELECT COUNT(*) FROM coupons WHERE customer_id = ? AND status = 'available'),
                used_coupons = (SELECT COUNT(*) FROM coupons WHERE customer_id = ? AND status = 'used')
            WHERE id = ?
        ");
        $counterStmt->bind_param("iiii", $cid, $cid, $cid, $cid);
        if (!$counterStmt->execute()) {
            throw new Exception('Customer counters update failed for ' . $cid . ': ' . $counterStmt->error);
        }
        $counterStmt->close();
    }
    $insertStmt->close();

    $db->commit();

} catch (Exception $e) {
    $db->rollback();
    error_log('ASSIGN COUPONS FAILED: ' . $e->getMessage());
    jsonResponse([
        'status'  => 'error',
        'message' => 'Coupons could not be assigned. Please try again.',
    ]);
}

// ═══════════════════════════════════════════════════
// STEP 6: Success response
// ═══════════════════════════════════════════════════
$assignedCustomerIds = array_keys($validCustomers);
$assignedNames       = array_map(fn($c) => $c['name'], $validCustomers);

$message = $assignedCount . ' coupon(s) assigned to ' . count($assignedCustomerIds) . ' customer(s).';
if (!empty($dupCustomerIds)) {
    $message .= ' Skipped ' . count($dupCustomerIds) . ' customer(s) with duplicate title today.';
}
if (!empty($invalidIds)) {
    $message .= ' Skipped ' . count($invalidIds) . ' invalid/inactive customer(s).';
}

jsonResponse([
    'status'              => 'success',
    'message'             => $message,
    'inserted_count'      => $assignedCount,
    'customer_count'      => count($assignedCustomerIds),
    'customer_ids'        => $assignedCustomerIds,
    'customer_names'      => $assignedNames,
    'coupon_ids'          => $insertedIds,
    'skipped_duplicates'  => $dupCustomerIds,
    'skipped_invalid'     => $invalidIds,
    'valid_from'          => $validFrom,
    'valid_to'            => $validTo,
]);
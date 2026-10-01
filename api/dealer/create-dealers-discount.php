<?php
/**
 * CREATE DEALER DISCOUNT API
 * 
 * POST /hascol_customer/api/dealer/create-discount.php
 * 
 * Fields:
 *   - dealer_id           (required)
 *   - discount_type       (required: flat/percent)
 *   - discount_value      (required)
 *   - apply_to            (required: entire_order/specific_products)
 *   - product_ids         (optional - if specific_products)
 *   - start_date          (required: YYYY-MM-DD)
 *   - end_date            (required: YYYY-MM-DD)
 *   - times_per_customer  (optional, default 1)
 *   - total_times         (optional, default 100)
 *   - min_order_value     (optional, default 0)
 */

// ─── CORS / OPTIONS ───
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

// ─── Sirf POST allow ───
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(['success' => false, 'message' => 'Only POST method allowed']);
}

// ─── Input lo (JSON ya form-data) ───
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = (stripos($contentType, 'application/json') !== false)
    ? getInput()
    : $_POST;

// ─── Fields ───
$dealerId         = (int)($input['dealer_id'] ?? 0);
$discountType     = trim($input['discount_type'] ?? '');
$discountValue    = (float)($input['discount_value'] ?? 0);
$applyTo          = trim($input['apply_to'] ?? '');
$productIds       = trim($input['product_ids'] ?? '');
$startDate        = trim($input['start_date'] ?? '');
$endDate          = trim($input['end_date'] ?? '');
$timesPerCustomer = (int)($input['times_per_customer'] ?? 1);
$totalTimes       = (int)($input['total_times'] ?? 100);
$minOrderValue    = (float)($input['min_order_value'] ?? 0);

// ═══════════════════════════════════════════
// VALIDATION
// ═══════════════════════════════════════════

// 1. dealer_id
if ($dealerId <= 0) {
    http_response_code(400);
    jsonResponse(['success' => false, 'message' => 'dealer_id is required']);
}

// 2. discount_type
if (!in_array($discountType, ['flat', 'percent'])) {
    http_response_code(400);
    jsonResponse(['success' => false, 'message' => 'discount_type must be flat or percent']);
}

// 3. discount_value
if ($discountValue <= 0) {
    http_response_code(400);
    jsonResponse(['success' => false, 'message' => 'discount_value must be greater than 0']);
}
if ($discountType === 'percent' && $discountValue > 100) {
    http_response_code(400);
    jsonResponse(['success' => false, 'message' => 'Percent discount cannot exceed 100']);
}

// 4. apply_to
if (!in_array($applyTo, ['entire_order', 'specific_products'])) {
    http_response_code(400);
    jsonResponse(['success' => false, 'message' => 'apply_to must be entire_order or specific_products']);
}
if ($applyTo === 'specific_products' && empty($productIds)) {
    http_response_code(400);
    jsonResponse(['success' => false, 'message' => 'product_ids required for specific_products']);
}

// 5. Dates
if (empty($startDate) || empty($endDate)) {
    http_response_code(400);
    jsonResponse(['success' => false, 'message' => 'start_date and end_date are required']);
}

$startDateObj = DateTime::createFromFormat('Y-m-d', $startDate);
$endDateObj   = DateTime::createFromFormat('Y-m-d', $endDate);

if (!$startDateObj || !$endDateObj) {
    http_response_code(400);
    jsonResponse(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD']);
}
if ($endDateObj < $startDateObj) {
    http_response_code(400);
    jsonResponse(['success' => false, 'message' => 'end_date must be after start_date']);
}

// 6. Usage limits
if ($timesPerCustomer <= 0) $timesPerCustomer = 1;
if ($totalTimes <= 0) $totalTimes = 100;

// ═══════════════════════════════════════════
// DEALER EXISTS?
// ═══════════════════════════════════════════
$stmt = $db->prepare("SELECT id FROM hascol_dealers WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $dealerId);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    http_response_code(404);
    jsonResponse(['success' => false, 'message' => 'Dealer not found']);
}
$stmt->close();

// ═══════════════════════════════════════════
// INSERT
// ═══════════════════════════════════════════
$stmt = $db->prepare("
    INSERT INTO dealer_discounts 
        (dealer_id, discount_type, discount_value, apply_to, product_ids,
         start_date, end_date, times_per_customer, total_times, 
         min_order_value, status, created_at)
    VALUES 
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
");

$stmt->bind_param(
    "issssssiid",
    $dealerId,
    $discountType,
    $discountValue,
    $applyTo,
    $productIds,
    $startDate,
    $endDate,
    $timesPerCustomer,
    $totalTimes,
    $minOrderValue
);

if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    jsonResponse(['success' => false, 'message' => 'Failed to create discount']);
}
$discountId = $stmt->insert_id;
$stmt->close();

// ═══════════════════════════════════════════
// SUCCESS
// ═══════════════════════════════════════════
http_response_code(201);
jsonResponse([
    'success' => true,
    'message' => 'Discount created successfully',
    'data'    => [
        'discount_id'        => $discountId,
        'dealer_id'          => $dealerId,
        'discount_type'      => $discountType,
        'discount_value'     => $discountValue,
        'apply_to'           => $applyTo,
        'product_ids'        => $productIds,
        'start_date'         => $startDate,
        'end_date'           => $endDate,
        'times_per_customer' => $timesPerCustomer,
        'total_times'        => $totalTimes,
        'min_order_value'    => $minOrderValue,
        'status'             => 'active',
    ],
]);
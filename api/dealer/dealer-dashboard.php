<?php

// ✅ Sirf OPTIONS handle karein
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

$input      = getInput();
$dealerId   = (int)($input['dealer_id'] ?? 0);
$fromDate   = trim($input['from_date'] ?? '');
$toDate     = trim($input['to_date'] ?? '');

// ─── Validation ───
if ($dealerId <= 0) {
    jsonResponse(['status'=>'error','message'=>'dealer_id required']);
}

// Date validation
$dateRegex = '/^\d{4}-\d{2}-\d{2}$/';

if (!empty($fromDate) && !preg_match($dateRegex, $fromDate)) {
    jsonResponse(['status'=>'error','message'=>'Invalid from_date format (YYYY-MM-DD)']);
}
if (!empty($toDate) && !preg_match($dateRegex, $toDate)) {
    jsonResponse(['status'=>'error','message'=>'Invalid to_date format (YYYY-MM-DD)']);
}

// Agar dono diye hain to from <= to hona chahiye
if (!empty($fromDate) && !empty($toDate) && $fromDate > $toDate) {
    jsonResponse(['status'=>'error','message'=>'from_date must be less than or equal to to_date']);
}

// ─── Dealer check ───
$stmt = $db->prepare("SELECT id, name, station_name FROM hascol_dealers WHERE id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param("i", $dealerId);
$stmt->execute();
$dealer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$dealer) {
    jsonResponse(['status'=>'error','message'=>'Dealer not found or inactive']);
}

// ─── Date filter condition banayein ───
$dateCondition = "";
$params        = [$dealerId];
$types         = "i";

if (!empty($fromDate) && !empty($toDate)) {
    $dateCondition = " AND DATE(created_at) BETWEEN ? AND ?";
    $params[]      = $fromDate;
    $params[]      = $toDate;
    $types        .= "ss";
} elseif (!empty($fromDate)) {
    $dateCondition = " AND DATE(created_at) >= ?";
    $params[]      = $fromDate;
    $types        .= "s";
} elseif (!empty($toDate)) {
    $dateCondition = " AND DATE(created_at) <= ?";
    $params[]      = $toDate;
    $types        .= "s";
}

// ═══════════════════════════════════════════════════
// CARD 1: Total Customers (Unique)
// ═══════════════════════════════════════════════════
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT customer_id) AS total_customers 
    FROM transactions 
    WHERE dealer_id = ?
    $dateCondition
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$totalCustomers = (int)$stmt->get_result()->fetch_assoc()['total_customers'];
$stmt->close();

// ═══════════════════════════════════════════════════
// CARD 2: Total Transactions
// ═══════════════════════════════════════════════════
$stmt = $db->prepare("
    SELECT COUNT(*) AS total_transactions 
    FROM transactions 
    WHERE dealer_id = ?
    $dateCondition
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$totalTransactions = (int)$stmt->get_result()->fetch_assoc()['total_transactions'];
$stmt->close();

// ═══════════════════════════════════════════════════
// CARD 3 & 4: Total Sales + Total Discounts
// ═══════════════════════════════════════════════════
$stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(amount), 0)       AS total_sales,
        COALESCE(SUM(discount), 0)     AS total_discounts,
        COALESCE(SUM(final_amount), 0) AS total_final
    FROM transactions 
    WHERE dealer_id = ?
    $dateCondition
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totalSales     = (float)$totals['total_sales'];
$totalDiscounts = (float)$totals['total_discounts'];
$totalFinal     = (float)$totals['total_final'];

// ═══════════════════════════════════════════════════
// BONUS: Status-wise breakdown
// ═══════════════════════════════════════════════════
$stmt = $db->prepare("
    SELECT 
        SUM(CASE WHEN status = 'successful' THEN 1 ELSE 0 END) AS successful,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END)    AS pending,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END)     AS failed
    FROM transactions 
    WHERE dealer_id = ?
    $dateCondition
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$statusBreakdown = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ─── Response ───
jsonResponse([
    'status'  => 'success',
    'message' => 'Dashboard data fetched successfully',
    'dealer'  => [
        'id'           => (int)$dealer['id'],
        'name'         => $dealer['name'],
        'station_name' => $dealer['station_name'],
    ],
    'filter'  => [
        'from_date' => $fromDate ?: null,
        'to_date'   => $toDate ?: null,
    ],
    'cards' => [
        'total_customers'    => $totalCustomers,
        'total_transactions' => $totalTransactions,
        'total_sales'        => round($totalSales, 2),
        'total_discounts'    => round($totalDiscounts, 2),
    ],
    'extra' => [
        'total_final'  => round($totalFinal, 2),
        'status_breakdown' => [
            'successful' => (int)($statusBreakdown['successful'] ?? 0),
            'pending'    => (int)($statusBreakdown['pending'] ?? 0),
            'failed'     => (int)($statusBreakdown['failed'] ?? 0),
        ],
    ],
]);
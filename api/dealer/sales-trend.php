<?php
/**
 * SALES TREND API (Bar Chart — 12 Months)
 * 
 * POST /api/dealer/sales-trend.php
 * Body: { 
 *   dealer_id,
 *   year (optional)  // YYYY — default: current year
 * }
 * 
 * Dealer ke liye 12 months ka sales trend deta hai (bar chart ke liye).
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

$input    = getInput();
$dealerId = (int)($input['dealer_id'] ?? 0);
$year     = (int)($input['year'] ?? date('Y'));

// ─── Validation ───
if ($dealerId <= 0) {
    jsonResponse(['status'=>'error','message'=>'dealer_id required']);
}

if ($year < 2020 || $year > 2100) {
    $year = (int)date('Y');
}

// ─── Dealer check ───
$stmt = $db->prepare("SELECT id FROM hascol_dealers WHERE id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param("i", $dealerId);
$stmt->execute();
$dealer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$dealer) {
    jsonResponse(['status'=>'error','message'=>'Dealer not found or inactive']);
}

// ─── 12 Months Ka Data Lein ───
$stmt = $db->prepare("
    SELECT 
        MONTH(created_at)   AS month_num,
        COUNT(*)            AS total_transactions,
        COALESCE(SUM(amount), 0)       AS total_sales,
        COALESCE(SUM(discount), 0)     AS total_discount
    FROM transactions 
    WHERE dealer_id = ? 
      AND YEAR(created_at) = ?
    GROUP BY MONTH(created_at)
    ORDER BY MONTH(created_at) ASC
");
$stmt->bind_param("ii", $dealerId, $year);
$stmt->execute();
$result = $stmt->get_result();

// ─── Array Banayein ───
$dataByMonth = [];
while ($row = $result->fetch_assoc()) {
    $dataByMonth[(int)$row['month_num']] = [
        'transactions' => (int)$row['total_transactions'],
        'sales'        => (float)$row['total_sales'],
        'discount'     => (float)$row['total_discount'],
    ];
}
$stmt->close();

// ─── Month Names ───
$monthNames = [
    1  => 'Jan', 2  => 'Feb', 3  => 'Mar', 4  => 'Apr',
    5  => 'May', 6  => 'Jun', 7  => 'Jul', 8  => 'Aug',
    9  => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'
];

// ─── 12 Months Ka Final Array ───
$months = [];

for ($m = 1; $m <= 12; $m++) {
    $data = $dataByMonth[$m] ?? [
        'transactions' => 0,
        'sales'        => 0,
        'discount'     => 0,
    ];
    
    $months[] = [
        'month'        => $monthNames[$m],
        'label'        => $monthNames[$m] . ' ' . $year,
        'transactions' => $data['transactions'],
        'sales'        => round($data['sales'], 2),
        'discount'     => round($data['discount'], 2),
    ];
}

// ─── Response ───
jsonResponse([
    'status'  => 'success',
    'message' => 'Sales trend fetched successfully',
    'months'  => $months,
]);
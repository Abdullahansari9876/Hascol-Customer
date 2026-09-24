<?php
/**
 * DEALER TRANSACTIONS API
 * 
 * GET /api/dealer/get-transactions.php?dealer_id=1
 * GET /api/dealer/get-transactions.php?dealer_id=1&status=successful
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

$input = getInput();
if (empty($input) && !empty($_GET)) {
    $input = $_GET;
}

$dealerId = (int)($input['dealer_id'] ?? 0);
$status   = trim($input['status'] ?? 'all');

if ($dealerId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'dealer_id required']);
}

if (!in_array($status, ['all', 'successful', 'pending', 'failed'])) {
    $status = 'all';
}

// Dealer check
$stmt = $db->prepare("SELECT id, name, station_name FROM dealers WHERE id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param("i", $dealerId);
$stmt->execute();
$dealer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$dealer) {
    jsonResponse(['status' => 'error', 'message' => 'Dealer not found or inactive']);
}

// Transactions
if ($status !== 'all') {
    $stmt = $db->prepare("
        SELECT 
            id, customer_id, customer_name, dealer_id,
            player_key, player_id, station_name, product_name,
            amount, discount, final_amount,
            coupon_code, status, transaction_ref,
            created_at, updated_at
        FROM transactions
        WHERE dealer_id = ? AND status = ?
        ORDER BY id DESC
    ");
    $stmt->bind_param("is", $dealerId, $status);
} else {
    $stmt = $db->prepare("
        SELECT 
            id, customer_id, customer_name, dealer_id,
            player_key, player_id, station_name, product_name,
            amount, discount, final_amount,
            coupon_code, status, transaction_ref,
            created_at, updated_at
        FROM transactions
        WHERE dealer_id = ?
        ORDER BY id DESC
    ");
    $stmt->bind_param("i", $dealerId);
}

$stmt->execute();
$result = $stmt->get_result();

$transactions  = [];
$totalAmount   = 0;
$totalDiscount = 0;
$totalFinal    = 0;

while ($row = $result->fetch_assoc()) {
    $totalAmount   += (float)$row['amount'];
    $totalDiscount += (float)$row['discount'];
    $totalFinal    += (float)$row['final_amount'];

    $transactions[] = [
        'id'              => (int)$row['id'],
        'customer_id'     => (int)$row['customer_id'],
        'customer_name'   => $row['customer_name'] ?? 'N/A',
        'dealer_id'       => (int)$row['dealer_id'],
        // 'player_id'       => $row['player_id'],
        'station_name'    => $row['station_name'],
        'product_name'    => $row['product_name'] ?? '',
        'amount'          => (float)$row['amount'],
        'discount'        => (float)$row['discount'],
        'final_amount'    => (float)$row['final_amount'],
        'status'          => $row['status'],
        'transaction_ref' => $row['transaction_ref'] ?? '',
        'created_at'      => $row['created_at'],
        'date_formatted'  => date('d M Y, h:i A', strtotime($row['created_at'])),
    ];
}
$stmt->close();

jsonResponse([
    'status'  => 'success',
    'message' => 'Transactions fetched successfully',
    'dealer'  => [
        'id'           => (int)$dealer['id'],
        'name'         => $dealer['name'],
        'station_name' => $dealer['station_name'],
    ],
    'summary' => [
        'total_transactions' => count($transactions),
        'total_amount'       => round($totalAmount, 2),
        'total_discount'     => round($totalDiscount, 2),
        'total_final'        => round($totalFinal, 2),
    ],
    'transactions' => $transactions,
]);
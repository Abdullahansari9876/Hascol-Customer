<?php
/**
 * GET TRANSACTIONS V2 API (Simple — No Coupon, No Discount)
 * GET /api/products/get-transactions-v2.php?dealer_id=3
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require '../config.php';
require '../db.php';

$dealerId = (int) ($_GET['dealer_id'] ?? 0);

if ($dealerId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'dealer_id required']);
}

// ─── Dealer Check ───
$stmt = $db->prepare("SELECT id, name, station_name FROM hascol_dealers WHERE id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param("i", $dealerId);
$stmt->execute();
$dealer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$dealer) {
    jsonResponse(['status' => 'error', 'message' => 'Dealer not found']);
}

// ─── Fetch transactions ───
$stmt = $db->prepare("
    SELECT id, transaction_ref, customer_id, customer_name, player_id,
           product_name, final_amount, status, created_at
    FROM transactions
    WHERE dealer_id = ?
    ORDER BY id DESC
");
$stmt->bind_param("i", $dealerId);
$stmt->execute();
$result = $stmt->get_result();

$transactions = [];

while ($row = $result->fetch_assoc()) {
    $transactions[] = [
        'id'              => (int) $row['id'],
        'transaction_ref' => $row['transaction_ref'],
        'customer' => [
            'id'        => (int) $row['customer_id'],
            'name'      => $row['customer_name'] ?: 'Walking Customer',
            'player_id' => $row['player_id'],
        ],
        'product_name' => $row['product_name'],
        'price'        => (float) $row['final_amount'],
        'status'       => $row['status'],
        'created_at'   => $row['created_at'],
    ];
}
$stmt->close();

// ─── Response ───
jsonResponse([
    'status'        => 'success',
    'message'       => 'Transactions fetched successfully',
    'dealer' => [
        'id'           => (int) $dealer['id'],
        'name'         => $dealer['name'],
        'station_name' => $dealer['station_name'],
    ],
    'total_records' => count($transactions),
    'transactions'  => $transactions,
]);
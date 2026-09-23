<?php
/**
 * CUSTOMER TRANSACTIONS API
 * 
 * POST /api/customer/get-transactions.php
 * Body: { 
 *   customer_id,
 *   status (optional: all | successful | pending | failed)
 * }
 * 
 * Customer-wise transactions fetch karta hai.
 * Saath mein coupons ka summary bhi deta hai.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'config.php';
require 'db.php';

// ─── Input (POST ya GET dono support) ───
$input = getInput();
if (empty($input) && !empty($_GET)) {
    $input = $_GET;
}

$customerId = (int)($input['customer_id'] ?? 0);
$status     = trim($input['status'] ?? 'all');

// ─── Validation ───
if ($customerId <= 0) {
    jsonResponse(['status'=>'error','message'=>'customer_id required']);
}

if (!in_array($status, ['all', 'successful', 'pending', 'failed'])) {
    $status = 'all';
}

// ─── Customer check (coupons counts ke saath) ───
$stmt = $db->prepare("
    SELECT id, name, mobile, 
           total_coupons, remaining_coupons, used_coupons 
    FROM customers 
    WHERE id = ? 
    LIMIT 1
");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    jsonResponse(['status'=>'error','message'=>'Customer not found']);
}

// ─── Transactions query (customer_id se) ───
if ($status !== 'all') {
    $stmt = $db->prepare("
        SELECT id, customer_id, dealer_id, 
               station_name, 
               amount, discount, final_amount, 
               status, transaction_ref, 
               created_at, updated_at
        FROM transactions 
        WHERE customer_id = ? AND status = ?
        ORDER BY id DESC
    ");
    $stmt->bind_param("is", $customerId, $status);
} else {
    $stmt = $db->prepare("
        SELECT id, customer_id, dealer_id, 
               station_name, 
               amount, discount, final_amount, 
               status, transaction_ref, 
               created_at, updated_at
        FROM transactions 
        WHERE customer_id = ?
        ORDER BY id DESC
    ");
    $stmt->bind_param("i", $customerId);
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
        'dealer_id'       => (int)$row['dealer_id'],
        'station_name'    => $row['station_name'],
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

// ─── Success ───
jsonResponse([
    'status'  => 'success',
    'message' => 'Transactions fetched successfully',
    'customer' => [
        'id'     => (int)$customer['id'],
        'name'   => $customer['name'],
        'mobile' => $customer['mobile'],
    ],
    'coupons' => [
        'total_coupons'     => (int)$customer['total_coupons'],
        'remaining' => (int)$customer['remaining_coupons'],
        'used'      => (int)$customer['used_coupons'],
    ],
    'summary' => [
        'total_transactions' => count($transactions),
        'total_amount'       => round($totalAmount, 2),
        'total_discount'     => round($totalDiscount, 2),
        'total_final'        => round($totalFinal, 2),
    ],
    'transactions' => $transactions,
]);
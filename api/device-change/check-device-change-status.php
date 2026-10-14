<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

$input      = getInput();
$referralNo = trim($input['referral_no'] ?? $input['coupon_no'] ?? '');
$contact    = trim($input['contact'] ?? '');
$requestId  = (int)($input['request_id'] ?? 0);   // optional

if ($referralNo === '') jsonResponse(['status'=>'error','message'=>'Please enter your referral number']);
if ($contact === '')    jsonResponse(['status'=>'error','message'=>'Please enter your contact number']);

if (!preg_match('/^[a-zA-Z0-9_\-]{4,50}$/', $referralNo)) {
    jsonResponse(['status'=>'error','error_code'=>'REFERRAL_MISMATCH','message'=>'Your referral number is incorrect.']);
}

// ─── Customer referral_no se dhoondein ───
$stmt = $db->prepare("SELECT id FROM hascol_customer WHERE coupon_no = ? LIMIT 1");
$stmt->bind_param("s", $referralNo);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    $stmt = $db->prepare("
        SELECT c.id
        FROM referral_numbers r
        JOIN hascol_customer c ON c.id = r.used_by_customer_id
        WHERE r.referral_no = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $referralNo);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$customer) {
    jsonResponse(['status'=>'error','error_code'=>'REFERRAL_MISMATCH','message'=>'Your referral number is incorrect.']);
}

// ─── Request dhoondein (contact bhi match hona chahiye) ───
if ($requestId > 0) {
    $stmt = $db->prepare("
        SELECT id, status, admin_note, created_at, processed_at
        FROM hascol_device_change_requests
        WHERE id = ? AND customer_id = ? AND contact = ?
        LIMIT 1
    ");
    $stmt->bind_param("iis", $requestId, $customer['id'], $contact);
} else {
    $stmt = $db->prepare("
        SELECT id, status, admin_note, created_at, processed_at
        FROM hascol_device_change_requests
        WHERE customer_id = ? AND contact = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->bind_param("is", $customer['id'], $contact);
}
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$req) {
    jsonResponse(['status'=>'success','request'=>null,'message'=>'No request found for this referral number and contact number.']);
}

jsonResponse([
    'status'  => 'success',
    'request' => [
        'id'           => (int)$req['id'],
        'status'       => $req['status'],
        'admin_note'   => $req['admin_note'] ?? '',
        'created_at'   => $req['created_at'],
        'processed_at' => $req['processed_at'],
    ],
]);
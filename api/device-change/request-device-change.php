<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

$input      = getInput();
$referralNo = trim($input['referral_no'] ?? $input['coupon_no'] ?? '');
$newImei    = trim($input['new_imei'] ?? '');
$contact    = trim($input['contact'] ?? '');    // customer ka NAYA mobile number
$password   = trim($input['password'] ?? '');   // optional
$reason     = trim($input['reason'] ?? '');     // optional

// ─── Validation ───
if ($referralNo === '') jsonResponse(['status'=>'error','message'=>'Please enter your referral number']);
if ($newImei === '')    jsonResponse(['status'=>'error','message'=>'new_imei required']);
if ($contact === '')    jsonResponse(['status'=>'error','message'=>'Please enter your new mobile number']);
if (strlen($newImei) > 20) jsonResponse(['status'=>'error','message'=>'Invalid device id']);

if (!preg_match('/^[a-zA-Z0-9_\-]{4,50}$/', $referralNo)) {
    jsonResponse(['status'=>'error','error_code'=>'REFERRAL_MISMATCH','message'=>'Your referral number is incorrect. Request cannot be submitted.']);
}
if (!preg_match('/^(03[0-9]{9}|\+923[0-9]{9})$/', $contact)) {
    jsonResponse(['status'=>'error','message'=>'Invalid mobile number (e.g., 03001234567)']);
}
if ($reason !== '' && strlen($reason) < 5) {
    jsonResponse(['status'=>'error','message'=>'Reason must be at least 5 characters']);
}
if (strlen($reason) > 255) jsonResponse(['status'=>'error','message'=>'Reason too long (max 255)']);
if ($reason === '') $reason = 'Not provided';   // DB column NOT NULL hai

// ─── Customer referral_no se dhoondein ───
$stmt = $db->prepare("SELECT id, password, verified, status, imei FROM hascol_customer WHERE coupon_no = ? LIMIT 1");
$stmt->bind_param("s", $referralNo);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fallback: referral_numbers table se
if (!$customer) {
    $stmt = $db->prepare("
        SELECT c.id, c.password, c.verified, c.status, c.imei
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
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'REFERRAL_MISMATCH',
        'message'    => 'Your referral number is incorrect. Request cannot be submitted.',
    ]);
}

if ($customer['verified'] != 1)       jsonResponse(['status'=>'error','message'=>'Account not verified.']);
if ($customer['status'] !== 'active') jsonResponse(['status'=>'error','message'=>'Account is ' . $customer['status']]);

// ─── Password (optional, lekin diya to sahi hona chahiye) ───
if ($password !== '' && !password_verify($password, (string)$customer['password'])) {
    jsonResponse(['status'=>'error','message'=>'Invalid password']);
}

// ─── IMEI checks ───
$storedImei = trim((string)($customer['imei'] ?? ''));

if ($storedImei === '') {
    jsonResponse(['status'=>'error','message'=>'No device is registered. Please login directly.']);
}
if (hash_equals($storedImei, $newImei)) {
    jsonResponse(['status'=>'error','message'=>'This device is already registered. Please login directly.']);
}

// ─── Pehle se pending request? ───
$stmt = $db->prepare("SELECT id FROM hascol_device_change_requests WHERE customer_id = ? AND status = 'pending' LIMIT 1");
$stmt->bind_param("i", $customer['id']);
$stmt->execute();
$pending = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($pending) {
    jsonResponse(['status'=>'error','message'=>'You already have a pending request. Please wait for verification.']);
}

// ─── Rate limit: 24 ghante mein max 3 requests ───
$stmt = $db->prepare("SELECT COUNT(*) AS c FROM hascol_device_change_requests WHERE customer_id = ? AND created_at > (NOW() - INTERVAL 1 DAY)");
$stmt->bind_param("i", $customer['id']);
$stmt->execute();
$cnt = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();
if ($cnt >= 3) {
    jsonResponse(['status'=>'error','message'=>'Too many requests. Please try again tomorrow.']);
}

// ─── Insert ───
$stmt = $db->prepare("
    INSERT INTO hascol_device_change_requests (customer_id, old_imei, new_imei, reason, contact, status, created_at)
    VALUES (?, ?, ?, ?, ?, 'pending', NOW())
");
$stmt->bind_param("issss", $customer['id'], $storedImei, $newImei, $reason, $contact);

if (!$stmt->execute()) {
    jsonResponse(['status'=>'error','message'=>'Request failed. Please try again.']);
}
$requestId = $stmt->insert_id;
$stmt->close();

jsonResponse([
    'status'     => 'success',
    'message'    => 'Request submitted. You will be able to login after verification.',
    'request_id' => (int)$requestId,
]);
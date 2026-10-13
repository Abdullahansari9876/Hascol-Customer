<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

$input    = getInput();
$mobile   = trim($input['mobile'] ?? '');
$password = trim($input['password'] ?? '');
$newImei  = trim($input['new_imei'] ?? '');
$reason   = trim($input['reason'] ?? '');
$contact  = trim($input['contact'] ?? '');

if ($mobile === '')   jsonResponse(['status'=>'error','message'=>'mobile required']);
if ($password === '') jsonResponse(['status'=>'error','message'=>'password required']);
if ($newImei === '')  jsonResponse(['status'=>'error','message'=>'new_imei required']);
if (strlen($newImei) > 20) jsonResponse(['status'=>'error','message'=>'Invalid device id']);
if (strlen($reason) < 5)   jsonResponse(['status'=>'error','message'=>'Please write a reason (min 5 characters)']);
if (strlen($reason) > 255) jsonResponse(['status'=>'error','message'=>'Reason too long (max 255)']);
if ($contact !== '' && !preg_match('/^[0-9+\-\s]{7,20}$/', $contact)) {
    jsonResponse(['status'=>'error','message'=>'Invalid contact number']);
}

// ─── Customer verify (login jaisa hi) ───
$stmt = $db->prepare("SELECT id, password, verified, status, imei FROM hascol_customer WHERE mobile = ? LIMIT 1");
$stmt->bind_param("s", $mobile);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) jsonResponse(['status'=>'error','message'=>'Mobile number not registered']);
if (!password_verify($password, (string)$customer['password'])) {
    jsonResponse(['status'=>'error','message'=>'Invalid password']);
}
if ($customer['verified'] != 1)        jsonResponse(['status'=>'error','message'=>'Account not verified.']);
if ($customer['status'] !== 'active')  jsonResponse(['status'=>'error','message'=>'Account is ' . $customer['status']]);

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
$contactDb = ($contact === '') ? null : $contact;
$stmt = $db->prepare("
    INSERT INTO hascol_device_change_requests (customer_id, old_imei, new_imei, reason, contact, status, created_at)
    VALUES (?, ?, ?, ?, ?, 'pending', NOW())
");
$stmt->bind_param("issss", $customer['id'], $storedImei, $newImei, $reason, $contactDb);

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
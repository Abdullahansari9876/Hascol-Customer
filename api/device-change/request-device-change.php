<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

// Mobile ko ek format (03XXXXXXXXX) mein badalta hai, taake +92 aur 03 dono match hon
function normalizeMobile($m)
{
    $m = preg_replace('/[\s\-]/', '', (string)$m);
    if (strpos($m, '+92') === 0) {
        $m = '0' . substr($m, 3);
    }
    return $m;
}

$input      = getInput();

// ═══════════════════════════════════════════════════════════
// IMPROVED INPUT HANDLING — multiple possible keys support
// ═══════════════════════════════════════════════════════════
$referralNo = trim($input['referral_no'] ?? $input['coupon_no'] ?? $input['referral'] ?? '');
$oldMobile  = trim($input['old_mobile'] ?? $input['mobile'] ?? $input['oldMobile'] ?? $input['old_number'] ?? '');
$newImei    = trim($input['new_imei'] ?? $input['imei'] ?? $input['newImei'] ?? $input['device_id'] ?? '');
$reason     = trim($input['reason'] ?? '');

// ─── Validation ───
if ($referralNo === '') jsonResponse(['status'=>'error','message'=>'Please enter your referral number']);
if ($oldMobile === '')  jsonResponse(['status'=>'error','message'=>'Please enter your old registered mobile number']);
if ($newImei === '')    jsonResponse(['status'=>'error','message'=>'new_imei required']);
if (strlen($newImei) > 20) jsonResponse(['status'=>'error','message'=>'Invalid device id']);

if (!preg_match('/^[a-zA-Z0-9_\-]{4,50}$/', $referralNo)) {
    jsonResponse(['status'=>'error','error_code'=>'REFERRAL_MISMATCH','message'=>'Your referral number is incorrect. Request cannot be submitted.']);
}
if (!preg_match('/^(03[0-9]{9}|\+923[0-9]{9})$/', $oldMobile)) {
    jsonResponse(['status'=>'error','message'=>'Invalid mobile number.']);
}
if ($reason !== '' && strlen($reason) < 5) {
    jsonResponse(['status'=>'error','message'=>'Reason must be at least 5 characters']);
}
if (strlen($reason) > 255) jsonResponse(['status'=>'error','message'=>'Reason too long (max 255)']);
if ($reason === '') $reason = 'Not provided';

// ─── Customer referral_no se dhoondein ───
$stmt = $db->prepare("SELECT id, mobile, verified, status, imei FROM hascol_customer WHERE coupon_no = ? LIMIT 1");
$stmt->bind_param("s", $referralNo);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fallback: referral_numbers table se
if (!$customer) {
    $stmt = $db->prepare("
        SELECT c.id, c.mobile, c.verified, c.status, c.imei
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

// ─── Old mobile match (register wala number) ───
if (normalizeMobile($oldMobile) !== normalizeMobile($customer['mobile'] ?? '')) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'MOBILE_MISMATCH',
        'message'    => 'Your mobile number does not match our records. Request cannot be submitted.',
    ]);
}

// ─── IMEI checks ───
$storedImei = trim((string)($customer['imei'] ?? ''));

if ($storedImei === '') {
    jsonResponse(['status'=>'error','message'=>'No device is registered. Please login directly.']);
}
if (hash_equals($storedImei, $newImei)) {
    jsonResponse(['status'=>'error','message'=>'This device is already registered. Please login directly.']);
}

// ─── NEW: Naya IMEI kisi aur customer ke account mein registered to nahi? ───
$stmt = $db->prepare("SELECT id FROM hascol_customer WHERE imei = ? AND id <> ? LIMIT 1");
$stmt->bind_param("si", $newImei, $customer['id']);
$stmt->execute();
$imeiOwner = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($imeiOwner) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'IMEI_ALREADY_REGISTERED',
        'message'    => 'This device is already registered with another account. Request cannot be submitted.',
    ]);
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

// ─── Insert (contact column me OLD MOBILE save ho raha hai) ───
$stmt = $db->prepare("
    INSERT INTO hascol_device_change_requests (customer_id, old_imei, new_imei, reason, contact, status, created_at)
    VALUES (?, ?, ?, ?, ?, 'pending', NOW())
");
$stmt->bind_param("issss", $customer['id'], $storedImei, $newImei, $reason, $oldMobile);

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
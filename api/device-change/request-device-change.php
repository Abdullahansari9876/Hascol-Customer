<?php
// request-device-change.php — Step 1: Credentials verify + OTP bhejo
// Request abhi insert NAHI hoti. OTP verify hone ke baad insert hogi.

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(0); }
header("Content-Type: application/json; charset=utf-8");
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';
require __DIR__ . '/send-otp-email.php';   // Email helper

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Only POST method allowed']);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function normalizeMobile($m) {
    $m = preg_replace('/[\s\-]/', '', (string)$m);
    if (strpos($m, '+92') === 0) $m = '0' . substr($m, 3);
    return $m;
}

$input    = getInput();
$mobile   = trim($input['mobile'] ?? $input['old_mobile'] ?? '');
$password = (string)($input['password'] ?? '');
$newImei  = trim($input['new_imei'] ?? $input['imei'] ?? '');
$reason   = trim($input['reason'] ?? '');

// ─── Validation ───
if ($mobile === '')   jsonResponse(['status'=>'error','message'=>'Mobile number required']);
if ($password === '') jsonResponse(['status'=>'error','message'=>'Password required']);
if ($newImei === '')  jsonResponse(['status'=>'error','message'=>'New device ID required']);
if (strlen($newImei) > 50) jsonResponse(['status'=>'error','message'=>'Invalid device id']);

if (!preg_match('/^(03[0-9]{9}|\+923[0-9]{9})$/', $mobile)) {
    jsonResponse(['status'=>'error','message'=>'Invalid mobile number format.']);
}
if ($reason !== '' && strlen($reason) < 5) jsonResponse(['status'=>'error','message'=>'Reason must be at least 5 characters']);
if (strlen($reason) > 255) jsonResponse(['status'=>'error','message'=>'Reason too long (max 255)']);
if ($reason === '') $reason = 'Not provided';

try {
    $mobileNorm = normalizeMobile($mobile);

    // ─── Duplicate mobile check (1 mobile = 1 customer) ───
    $stmt = $db->prepare("
        SELECT COUNT(*) AS c
        FROM hascol_customer
        WHERE REPLACE(REPLACE(REPLACE(mobile, ' ', ''), '-', ''), '+92', '0') = ?
    ");
    $stmt->bind_param("s", $mobileNorm);
    $stmt->execute();
    $dupCount = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    if ($dupCount > 1) {
        jsonResponse([
            'status'     => 'error',
            'error_code' => 'DUPLICATE_MOBILE',
            'message'    => 'This mobile number is registered with multiple accounts. Please contact support.',
        ]);
    }

    // ─── Customer dhoondein ───
    $stmt = $db->prepare("
        SELECT id, mobile, password, email, verified, status, imei, name
        FROM hascol_customer
        WHERE REPLACE(REPLACE(REPLACE(mobile, ' ', ''), '-', ''), '+92', '0') = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $mobileNorm);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$customer) {
        jsonResponse(['status' => 'error', 'error_code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid mobile number or password.']);
    }

    // ─── Password verify ───
    if (empty($customer['password']) || !verifyPassword($password, $customer['password'])) {
        jsonResponse(['status' => 'error', 'error_code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid mobile number or password.']);
    }

    if ($customer['verified'] != 1)       jsonResponse(['status'=>'error','message'=>'Account is not verified.']);
    if ($customer['status'] !== 'active') jsonResponse(['status'=>'error','message'=>'Account is ' . $customer['status']]);

    // ─── IMEI (Device) CHECKS ───
    $storedImei = trim((string)($customer['imei'] ?? ''));
    
    if ($storedImei === '') {
        jsonResponse(['status'=>'error','message'=>'No device is currently registered on this account. Please login directly.']);
    }
    
    if (hash_equals($storedImei, $newImei)) {
        jsonResponse(['status'=>'error','message'=>'This device is already registered to your account. Please login directly.']);
    }

    // 🛡️ CHECK: Recent request check (Pichle 24 ghante mein koi request pending ya approved hai?)
    $stmt = $db->prepare("
        SELECT id FROM hascol_device_change_requests 
        WHERE customer_id = ? 
        AND status IN ('pending', 'approved') 
        AND created_at > (NOW() - INTERVAL 1 DAY) 
        LIMIT 1
    ");
    $stmt->bind_param("i", $customer['id']);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        jsonResponse([
            'status'     => 'error',
            'error_code' => 'RECENT_REQUEST_EXISTS',
            'message'    => 'You have already submitted a device change request in the last 24 hours. Please wait for admin approval.'
        ]);
    }
    $stmt->close();

    // Check if NEW IMEI is already used by ANOTHER customer
    $stmt = $db->prepare("SELECT id FROM hascol_customer WHERE imei = ? AND id <> ? LIMIT 1");
    $stmt->bind_param("si", $newImei, $customer['id']);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        jsonResponse([
            'status'     => 'error',
            'error_code' => 'IMEI_ALREADY_REGISTERED',
            'message'    => 'This new device is already registered with another customer account. Request cannot be submitted.',
        ]);
    }
    $stmt->close();

    // ─── EMAIL CHECK ───
    $customerEmail = trim((string)($customer['email'] ?? ''));
    if ($customerEmail === '') {
        jsonResponse([
            'status'     => 'error',
            'error_code' => 'EMAIL_MISSING',
            'message'    => 'No email is registered on your account. An email is required for device change requests. Please contact support to add your email.',
        ]);
    }

    // ─── Rate limit: 24 ghante mein max 3 requests ───
    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM hascol_device_change_requests WHERE customer_id = ? AND created_at > (NOW() - INTERVAL 1 DAY)");
    $stmt->bind_param("i", $customer['id']);
    $stmt->execute();
    if ((int)$stmt->get_result()->fetch_assoc()['c'] >= 3) {
        $stmt->close();
        jsonResponse(['status'=>'error','message'=>'Too many requests. Please try again tomorrow.']);
    }
    $stmt->close();

    // ─── OTP generate karein ───
    $otp     = (string) random_int(100000, 999999);
    // $otpHash = password_hash($otp, PASSWORD_DEFAULT); // <--- HASHING REMOVED
    $expires = date('Y-m-d H:i:s', time() + OTP_EXPIRY);  
    $ip      = $_SERVER['REMOTE_ADDR'] ?? null;

    // Purane unverified OTP rows delete karein
    $del = $db->prepare("DELETE FROM hascol_device_change_otps WHERE customer_id = ? AND verified_at IS NULL");
    $del->bind_param("i", $customer['id']);
    $del->execute();
    $del->close();

    // Naya OTP insert (Plain OTP saving)
    $ins = $db->prepare("
        INSERT INTO hascol_device_change_otps
        (customer_id, new_imei, otp_hash, expires_at, ip_address, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $ins->bind_param("issss", $customer['id'], $newImei, $otp, $expires, $ip);
    $ins->execute();
    $otpId = $ins->insert_id;
    $ins->close();

    // ─── Email bhejein ───
    $sent = sendOtpEmail($customerEmail, $customer['name'] ?? 'Customer', $otp);

    if (!$sent) {
        // Rollback OTP row
        $del = $db->prepare("DELETE FROM hascol_device_change_otps WHERE id = ?");
        $del->bind_param("i", $otpId);
        $del->execute();
        $del->close();
        jsonResponse(['status'=>'error','message'=>'Failed to send OTP email. Please try again later.']);
    }

    jsonResponse([
        'status'       => 'success',
        'message'      => 'OTP has been sent to your registered email. Please verify within 10 minutes.',
        'otp_id'       => (int)$otpId,
        'email_masked' => maskEmail($customerEmail),
    ]);

} catch (Throwable $e) {
    jsonResponse(['status'=>'error','message'=>$e->getMessage()]);
}

function maskEmail($e) {
    [$u, $d] = explode('@', $e, 2);
    $keep = min(2, strlen($u));
    $u = substr($u, 0, $keep) . str_repeat('*', max(1, strlen($u) - $keep));
    return $u . '@' . $d;
}
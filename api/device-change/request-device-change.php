<?php
// request-device-change.php — Step 1: Credentials verify (Email + Password) + OTP bhejo

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

$input    = getInput();
$email    = trim($input['email'] ?? '');
$password = (string)($input['password'] ?? '');
$newImei  = trim($input['new_imei'] ?? $input['imei'] ?? '');
$reason   = trim($input['reason'] ?? '');

// ─── Validation ───
if ($email === '')    jsonResponse(['status'=>'error','message'=>'Email is required']);
if ($password === '') jsonResponse(['status'=>'error','message'=>'Password is required']);
if ($newImei === '')  jsonResponse(['status'=>'error','message'=>'New device ID is required']);
if (strlen($newImei) > 50) jsonResponse(['status'=>'error','message'=>'Invalid device ID']);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['status'=>'error','message'=>'Invalid email format.']);
}
if ($reason !== '' && strlen($reason) < 5) jsonResponse(['status'=>'error','message'=>'Reason must be at least 5 characters']);
if (strlen($reason) > 255) jsonResponse(['status'=>'error','message'=>'Reason too long (max 255)']);
if ($reason === '') $reason = 'Not provided';

try {
    // ─── Customer dhoondein Email se ───
    $stmt = $db->prepare("
        SELECT id, email, password, verified, status, imei, name
        FROM hascol_customer
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$customer) {
        jsonResponse([
            'status'     => 'error', 
            'error_code' => 'EMAIL_NOT_FOUND', 
            'message'    => 'This email address is not registered in our system. Please check the email or contact support.'
        ]);
    }

    // ─── Password verify ───
    if (empty($customer['password']) || !verifyPassword($password, $customer['password'])) {
        jsonResponse(['status' => 'error', 'error_code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid email or password.']);
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
    $sent = sendOtpEmail($customer['email'], $customer['name'] ?? 'Customer', $otp);

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
        'email_masked' => maskEmail($customer['email']),
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
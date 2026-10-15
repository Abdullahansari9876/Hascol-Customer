<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'config.php';
require 'db.php';

// ═══════════════════════════════════════════════════════════
// TEST USER LOCK (sirf testing ke liye)
// Is list ke mobile number bagair IMEI ke login kar sakte hain.
// Password, verified aur active checks phir bhi lagte hain.
// Testing khatam hone par is list ko khali kar dein: []
// ═══════════════════════════════════════════════════════════
$TEST_NO_IMEI_MOBILES = ['03000000001'];

$input    = getInput();
$mobile   = trim($input['mobile'] ?? '');
$password = trim($input['password'] ?? '');
$imei     = trim($input['imei'] ?? '');

$isTestUser = in_array($mobile, $TEST_NO_IMEI_MOBILES, true);

// ─── BASIC VALIDATION ───
if (empty($mobile)) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'MOBILE_REQUIRED',
        'message'    => 'Mobile number is required.'
    ]);
}

if (empty($password)) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'PASSWORD_REQUIRED',
        'message'    => 'Password is required.'
    ]);
}

// IMEI lazmi hai — sirf test user ko chhor kar.
if (empty($imei) && !$isTestUser) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'IMEI_REQUIRED',
        'message'    => 'Device IMEI is required. Please update your app and try again.'
    ]);
}

$ip = getClientIp();

$stmt = $db->prepare("SELECT * FROM hascol_customer WHERE mobile = ? LIMIT 1");
$stmt->bind_param("s", $mobile);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'MOBILE_NOT_REGISTERED',
        'message'    => 'Mobile number not registered.'
    ]);
}

if (!password_verify($password, $customer['password'])) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'INVALID_PASSWORD',
        'message'    => 'Invalid password.'
    ]);
}

if ($customer['verified'] != 1) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'ACCOUNT_NOT_VERIFIED',
        'message'    => 'Account not verified.'
    ]);
}

if ($customer['status'] !== 'active') {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'ACCOUNT_INACTIVE',
        'message'    => 'Account is ' . $customer['status'] . '.'
    ]);
}

// ─── IMEI DEVICE LOCK (STRICT) ───
// Rule:
//  - Test user: IMEI check/bind poori tarah skip.
//  - Baqi users: IMEI request mein lazmi hai (upar validate ho chuki).
//  - Agar DB mein IMEI khali hai  -> pehli dafa bind karo.
//  - Agar DB mein IMEI saved hai  -> exactly match honi chahiye, warna block.
//  - IMEI kabhi overwrite nahi hoti.
if (!$isTestUser) {
    $storedImei = trim((string)($customer['imei'] ?? ''));

    if ($storedImei === '') {
        // Pehli dafa: device bind karo
        $stmt = $db->prepare("UPDATE hascol_customer SET imei = ? WHERE id = ?");
        $stmt->bind_param("si", $imei, $customer['id']);
        if (!$stmt->execute()) {
            $stmt->close();
            jsonResponse([
                'status'     => 'error',
                'error_code' => 'IMEI_BIND_FAILED',
                'message'    => 'Unable to bind device. Please try again.'
            ]);
        }
        $stmt->close();
        $customer['imei'] = $imei;
    } else {
        // Already bound: strict match
        if (!hash_equals($storedImei, $imei)) {
            jsonResponse([
                'status'     => 'error',
                'error_code' => 'IMEI_MISMATCH',
                'message'    => 'This account is locked to a different device. Please contact support to reset your device lock.'
            ]);
        }
    }
}

$key      = $customer['player_key'];
$playerId = $customer['player_id'];

// Purane sessions inactive
$stmt = $db->prepare("UPDATE sessions SET is_active = 0 WHERE player_id = ?");
$stmt->bind_param("s", $playerId);
$stmt->execute();
$stmt->close();

// Naya token
$token     = bin2hex(random_bytes(32));
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$stmt = $db->prepare("
    INSERT INTO sessions 
    (token, player_key, player_id, ip_address, user_agent, created_at, expires_at, is_active) 
    VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 1)
");
$stmt->bind_param("sssss", $token, $key, $playerId, $ip, $userAgent);
if (!$stmt->execute()) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'SESSION_SAVE_FAILED',
        'message'    => 'Session save failed: ' . $stmt->error
    ]);
}
$stmt->close();

$stmt = $db->prepare("UPDATE hascol_customer SET last_login = NOW() WHERE id = ?");
$stmt->bind_param("i", $customer['id']);
$stmt->execute();
$stmt->close();

fbSet("sessions/$token", [
    'player_id' => $playerId,
    'player_key'=> $key,
    'created_at'=> date('Y-m-d H:i:s'),
    'expires_at'=> date('Y-m-d H:i:s', time() + 30*24*3600),
]);

jsonResponse([
    'status'   => 'success',
    'message'  => 'Login successful',
    'token'    => $token,
    'customer' => [
        'id'         => $customer['id'],
        'player_id'  => $customer['player_id'],
        'name'       => $customer['name'],
        'email'      => $customer['email'] ?? '',
        'mobile'     => $customer['mobile'] ?? '',
        'imei'       => $customer['imei'] ?? '',
        'verified'   => true,
    ],
]);
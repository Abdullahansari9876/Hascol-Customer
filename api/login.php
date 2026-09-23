<?php
/**
 * LOGIN API
 * Mobile + Password se login
 * 
 * POST /api/login.php
 * Body: { mobile, password }
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'config.php';
require 'db.php';

$input    = getInput();
$mobile   = trim($input['mobile'] ?? '');
$password = trim($input['password'] ?? '');

// ─── Validation ───
if (empty($mobile))   jsonResponse(['status'=>'error','message'=>'mobile required']);
if (empty($password)) jsonResponse(['status'=>'error','message'=>'password required']);

$ip = getClientIp();

// ─── Customer dhoondein mobile se ───
$stmt = $db->prepare("SELECT * FROM customers WHERE mobile = ? LIMIT 1");
$stmt->bind_param("s", $mobile);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    jsonResponse(['status'=>'error','message'=>'Mobile number not registered']);
}

// ─── Password verify ───
if (!password_verify($password, $customer['password'])) {
    jsonResponse(['status'=>'error','message'=>'Invalid password']);
}

// ─── Verified check ───
if ($customer['verified'] != 1) {
    jsonResponse([
        'status' => 'error',
        'message' => 'Account not verified. Please register again and verify OTP.'
    ]);
}

// ─── Status check ───
if ($customer['status'] !== 'active') {
    jsonResponse(['status'=>'error','message'=>'Account is ' . $customer['status']]);
}

$key      = $customer['player_key'];
$playerId = $customer['player_id'];

// ─── Purane sessions inactive kar dein (optional) ───
$stmt = $db->prepare("UPDATE sessions SET is_active = 0 WHERE player_id = ?");
$stmt->bind_param("s", $playerId);
$stmt->execute();
$stmt->close();

// ─── Session token generate ───
$token     = bin2hex(random_bytes(32));
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// ─── MySQL mein session save ───
$stmt = $db->prepare("
    INSERT INTO sessions 
    (token, player_key, player_id, ip_address, user_agent, created_at, expires_at, is_active) 
    VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 1)
");
$stmt->bind_param("sssss", $token, $key, $playerId, $ip, $userAgent);
if (!$stmt->execute()) {
    jsonResponse(['status'=>'error','message'=>'Session save failed: ' . $stmt->error]);
}
$stmt->close();

// ─── MySQL mein last_login update ───
$stmt = $db->prepare("UPDATE customers SET last_login = NOW() WHERE id = ?");
$stmt->bind_param("i", $customer['id']);
$stmt->execute();
$stmt->close();

// ─── Firebase mein session save ───
fbSet("sessions/$token", [
    'player_id' => $playerId,
    'player_key'=> $key,
    'created_at'=> date('Y-m-d H:i:s'),
    'expires_at'=> date('Y-m-d H:i:s', time() + 30*24*3600),
]);

// ─── Success ───
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
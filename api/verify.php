<?php
/**
 * VERIFY OTP
 * MySQLi se OTP check karega
 * MySQLi mein customer verified mark karega
 * Firebase se OTP delete karega
 * 
 * POST /api/verify.php
 * Body: { player_id, otp }
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'config.php';  // Firebase config
require 'db.php';      // MySQLi connection

$input    = getInput();
$playerId = trim($input['player_id'] ?? '');
$otp      = trim($input['otp'] ?? '');

if (empty($playerId) || empty($otp)) {
    jsonResponse(['status'=>'error','message'=>'player_id and otp required']);
}

$key = playerKey($playerId);
$ip  = getClientIp();

// ─── MySQLi se latest OTP lein ───
$stmt = $db->prepare("
    SELECT * FROM otps 
    WHERE player_key = ? AND is_used = 0 
    ORDER BY id DESC LIMIT 1
");
$stmt->bind_param("s", $key);
$stmt->execute();
$result = $stmt->get_result();
$otpData = $result->fetch_assoc();
$stmt->close();

if (!$otpData) {
    jsonResponse(['status'=>'error','message'=>'OTP not found. Please register again.']);
}

// ─── Expiry check ───
if (time() > strtotime($otpData['expires_at'])) {
    $stmt = $db->prepare("UPDATE otps SET is_used = 1 WHERE id = ?");
    $stmt->bind_param("i", $otpData['id']);
    $stmt->execute();
    $stmt->close();
    
    // Log
    $stmt = $db->prepare("INSERT INTO otp_logs (player_key, action, otp, ip_address, note) VALUES (?, 'expired', ?, ?, 'OTP expired')");
    $stmt->bind_param("sss", $key, $otpData['otp'], $ip);
    $stmt->execute();
    $stmt->close();
    
    jsonResponse(['status'=>'error','message'=>'OTP expired. Please register again.']);
}

// ─── Attempts check ───
$attempts = $otpData['attempts'] + 1;
if ($attempts > $otpData['max_attempts']) {
    $stmt = $db->prepare("UPDATE otps SET is_used = 1 WHERE id = ?");
    $stmt->bind_param("i", $otpData['id']);
    $stmt->execute();
    $stmt->close();
    
    // Log
    $stmt = $db->prepare("INSERT INTO otp_logs (player_key, action, otp, ip_address, note) VALUES (?, 'failed', ?, ?, 'Too many attempts')");
    $stmt->bind_param("sss", $key, $otpData['otp'], $ip);
    $stmt->execute();
    $stmt->close();
    
    jsonResponse(['status'=>'error','message'=>'Too many attempts. Register again.']);
}

// ─── OTP Match ───
if ($otpData['otp'] !== $otp) {
    $stmt = $db->prepare("UPDATE otps SET attempts = ? WHERE id = ?");
    $stmt->bind_param("ii", $attempts, $otpData['id']);
    $stmt->execute();
    $stmt->close();
    
    // Log
    $stmt = $db->prepare("INSERT INTO otp_logs (player_key, action, otp, ip_address, note) VALUES (?, 'failed', ?, ?, 'Invalid OTP')");
    $stmt->bind_param("sss", $key, $otp, $ip);
    $stmt->execute();
    $stmt->close();
    
    $left = $otpData['max_attempts'] - $attempts;
    jsonResponse(['status'=>'error','message'=>"Invalid OTP. Attempts left: $left"]);
}

// ─── ✅ Verified ───
$stmt = $db->prepare("UPDATE otps SET is_used = 1 WHERE id = ?");
$stmt->bind_param("i", $otpData['id']);
$stmt->execute();
$stmt->close();

// Log
$stmt = $db->prepare("INSERT INTO otp_logs (player_key, action, otp, ip_address, note) VALUES (?, 'verified', ?, ?, 'OTP verified successfully')");
$stmt->bind_param("sss", $key, $otp, $ip);
$stmt->execute();
$stmt->close();

// ─── MySQLi mein customer verified mark karein ───
$stmt = $db->prepare("
    UPDATE hascol_customer 
    SET verified = 1, verified_at = NOW(), last_login = NOW() 
    WHERE player_key = ?
");
$stmt->bind_param("s", $key);
$stmt->execute();
$stmt->close();

// ─── Session token generate ───
$token = bin2hex(random_bytes(32));
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$stmt = $db->prepare("
    INSERT INTO sessions 
    (token, player_key, player_id, ip_address, user_agent, created_at, expires_at, is_active) 
    VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 1)
");
$stmt->bind_param("sssss", $token, $key, $playerId, $ip, $userAgent);
$stmt->execute();
$stmt->close();

// ─── Firebase se OTP delete karein ───
fbDelete("otps/$key");

// ─── Firebase mein customer verified mark karein (backup) ───
fbPatch("hascol_customer/$key", [
    'verified'    => true,
    'verified_at' => date('Y-m-d H:i:s'),
    'last_login'  => date('Y-m-d H:i:s'),
]);

// ─── Firebase mein session save karein ───
fbSet("sessions/$token", [
    'player_id' => $playerId,
    'player_key'=> $key,
    'created_at'=> date('Y-m-d H:i:s'),
    'expires_at'=> date('Y-m-d H:i:s', time() + 30*24*3600),
]);

// ─── Customer detail lein ───
$stmt = $db->prepare("SELECT * FROM hascol_customer WHERE player_key = ?");
$stmt->bind_param("s", $key);
$stmt->execute();
$result = $stmt->get_result();
$customer = $result->fetch_assoc();
$stmt->close();

// ─── ✅ Response (email optional ke saath) ───
jsonResponse([
    'status'   => 'success',
    'message'  => 'Registration complete',
    'token'    => $token,
    'customer' => [
        'id'         => $customer['id'],
        'player_id'  => $customer['player_id'],
        'name'       => $customer['name'],
        'email'      => $customer['email'] ?? '',   // ✅ Optional
        'mobile'     => $customer['mobile'] ?? '',
        'imei'       => $customer['imei'] ?? '',
        'verified'   => true,
    ],
]);
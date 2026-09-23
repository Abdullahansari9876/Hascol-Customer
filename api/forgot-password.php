<?php
/**
 * FORGOT PASSWORD API
 * POST /api/forgot-password.php
 * Body: { mobile, new_password, confirm_password }
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'config.php';
require 'db.php';

$input           = getInput();
$mobile          = trim($input['mobile'] ?? '');
$newPassword     = trim($input['new_password'] ?? '');
$confirmPassword = trim($input['confirm_password'] ?? '');

// ─── Validation ───
if (empty($mobile))          jsonResponse(['status'=>'error','message'=>'mobile required']);
if (empty($newPassword))     jsonResponse(['status'=>'error','message'=>'new_password required']);
if (empty($confirmPassword)) jsonResponse(['status'=>'error','message'=>'confirm_password required']);

if (!preg_match('/^(03[0-9]{9}|\+923[0-9]{9})$/', $mobile)) {
    jsonResponse(['status'=>'error','message'=>'Invalid mobile number (e.g., 03001234567)']);
}

if ($newPassword !== $confirmPassword) {
    jsonResponse(['status'=>'error','message'=>'New password and confirm password do not match']);
}

if (strlen($newPassword) < 6) {
    jsonResponse(['status'=>'error','message'=>'New password must be at least 6 characters']);
}

if (strlen($newPassword) > 50) {
    jsonResponse(['status'=>'error','message'=>'New password must be less than 50 characters']);
}

if (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
    jsonResponse(['status'=>'error','message'=>'Password must contain at least one letter and one number']);
}

$ip = getClientIp();

// ─── Mobile se customer dhoondein ───
$stmt = $db->prepare("SELECT * FROM customers WHERE mobile = ? LIMIT 1");
$stmt->bind_param("s", $mobile);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    jsonResponse(['status'=>'error','message'=>'This mobile number is not registered']);
}

// ─── Naya password purane se alag hona chahiye ───
if (password_verify($newPassword, $customer['password'])) {
    jsonResponse(['status'=>'error','message'=>'New password must be different from your old password']);
}

$playerId = $customer['player_id'];
$key      = $customer['player_key'];

$newPasswordHash = password_hash($newPassword, PASSWORD_BCRYPT);

$stmt = $db->prepare("UPDATE customers SET password = ?, updated_at = NOW() WHERE mobile = ?");
$stmt->bind_param("ss", $newPasswordHash, $mobile);
if (!$stmt->execute()) {
    jsonResponse(['status'=>'error','message'=>'Password update failed: ' . $stmt->error]);
}
$stmt->close();

// ─── Purane sessions inactive ───
$stmt = $db->prepare("UPDATE sessions SET is_active = 0 WHERE player_id = ?");
$stmt->bind_param("s", $playerId);
$stmt->execute();
$stmt->close();

// ─── Firebase update ───
fbPatch("customers/$key", [
    'password_updated_at' => date('Y-m-d H:i:s'),
]);

// ─── Log ───
$stmt = $db->prepare("INSERT INTO otp_logs (player_key, action, otp, ip_address, note) VALUES (?, 'forgot_password', NULL, ?, 'Password reset via forgot password')");
$stmt->bind_param("ss", $key, $ip);
$stmt->execute();
$stmt->close();

jsonResponse([
    'status'  => 'success',
    'message' => 'Password reset successfully. Please login with your new password.',
]);
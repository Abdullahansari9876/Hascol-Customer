<?php
/**
 * FORGOT PASSWORD API
 * POST /api/forgot-password.php
 * Body: { mobile, new_password, confirm_password }
 *
 * NOTE: IMEI verification check-mobile.php mein hoti hai (Step 1).
 *       Yahan sirf password reset hota hai (Step 2).
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'config.php';
require 'db.php';

// ─── Mobile normalization ───
function normalizeMobile(string $mobile): string
{
    $mobile = preg_replace('/[\s\-\(\)]+/', '', $mobile);

    if (preg_match('/^\+923(\d{9})$/', $mobile, $m)) {
        return '03' . $m[1];
    }
    if (preg_match('/^923(\d{9})$/', $mobile, $m)) {
        return '03' . $m[1];
    }
    if (preg_match('/^00923(\d{9})$/', $mobile, $m)) {
        return '03' . $m[1];
    }
    if (preg_match('/^3(\d{9})$/', $mobile, $m)) {
        return '03' . $m[1];
    }
    return $mobile;
}

$input           = getInput();
$mobile          = normalizeMobile(trim($input['mobile'] ?? ''));
$newPassword     = trim($input['new_password'] ?? '');
$confirmPassword = trim($input['confirm_password'] ?? '');

// ─── Validation ───
if (empty($mobile))          jsonResponse(['status'=>'error','error_code'=>'MOBILE_REQUIRED','message'=>'mobile required']);
if (empty($newPassword))     jsonResponse(['status'=>'error','error_code'=>'NEW_PASSWORD_REQUIRED','message'=>'new_password required']);
if (empty($confirmPassword)) jsonResponse(['status'=>'error','error_code'=>'CONFIRM_PASSWORD_REQUIRED','message'=>'confirm_password required']);

if (!preg_match('/^(03[0-9]{9})$/', $mobile)) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'INVALID_MOBILE',
        'message'    => 'Please enter a valid Pakistani mobile number like 03001234567.'
    ]);
}

if ($newPassword !== $confirmPassword) {
    jsonResponse(['status'=>'error','error_code'=>'PASSWORD_MISMATCH','message'=>'New password and confirm password do not match']);
}

if (strlen($newPassword) < 6) {
    jsonResponse(['status'=>'error','error_code'=>'PASSWORD_TOO_SHORT','message'=>'New password must be at least 6 characters']);
}

if (strlen($newPassword) > 50) {
    jsonResponse(['status'=>'error','error_code'=>'PASSWORD_TOO_LONG','message'=>'New password must be less than 50 characters']);
}

if (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
    jsonResponse(['status'=>'error','error_code'=>'WEAK_PASSWORD','message'=>'Password must contain at least one letter and one number']);
}

$ip = getClientIp();

// ─── Mobile se customer dhoondein ───
$stmt = $db->prepare("SELECT * FROM hascol_customer WHERE mobile = ? LIMIT 1");
$stmt->bind_param("s", $mobile);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'MOBILE_NOT_REGISTERED',
        'message'    => 'This mobile number is not registered'
    ]);
}

// ─── Naya password purane se alag hona chahiye ───
if (password_verify($newPassword, $customer['password'])) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'PASSWORD_SAME_AS_OLD',
        'message'    => 'New password must be different from your old password'
    ]);
}

$playerId = $customer['player_id'];
$key      = $customer['player_key'];

$newPasswordHash = password_hash($newPassword, PASSWORD_BCRYPT);

// ─── UPDATE: password (hash) + password_plain (plain text) ───
$stmt = $db->prepare("
    UPDATE hascol_customer 
    SET password = ?, 
        password_plain = ?, 
        updated_at = NOW() 
    WHERE mobile = ?
");
$stmt->bind_param("sss", $newPasswordHash, $newPassword, $mobile);

if (!$stmt->execute()) {
    error_log("FORGOT_PASSWORD: execute failed. mobile=$mobile, error=" . $stmt->error);
    $stmt->close();
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'PASSWORD_UPDATE_FAILED',
        'message'    => 'Password update failed. Please try again in a moment.'
    ]);
}

$affected = $stmt->affected_rows;
$stmt->close();

if ($affected === 0) {
    error_log("FORGOT_PASSWORD: 0 rows affected. mobile=$mobile");
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'PASSWORD_UPDATE_FAILED',
        'message'    => 'Password could not be updated. Please try again.'
    ]);
}

// ─── Purane sessions inactive ───
$stmt = $db->prepare("UPDATE sessions SET is_active = 0 WHERE player_id = ?");
$stmt->bind_param("s", $playerId);
$stmt->execute();
$stmt->close();

// ─── Firebase update ───
fbPatch("hascol_customer/$key", [
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
<?php
/**
 * CHANGE PASSWORD API
 *
 * POST /api/change-password.php
 * Body: {
 *   id:               "22",
 *   current_password: "oldpass123",
 *   new_password:     "newpass456",
 *   confirm_password: "newpass456"
 * }
 *
 * Flow:
 *  1. id (hascol_customer.id) se customer dhoondein (sirf ek)
 *  2. Usi customer ka current_password verify karein (AES-256-GCM)
 *  3. Match hone par naya password AES mein encrypt karke save karein
 *  4. Saare purane sessions inactive kar dein
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'config.php';
require 'db.php';

$input           = getInput();
$customerId      = (int)($input['id'] ?? 0);
$currentPassword = trim($input['current_password'] ?? '');
$newPassword     = trim($input['new_password'] ?? '');
$confirmPassword = trim($input['confirm_password'] ?? '');

// ─── Validation ───
if ($customerId <= 0) {
    jsonResponse(['status'=>'error','error_code'=>'ID_REQUIRED','message'=>'id required']);
}
if (empty($currentPassword)) {
    jsonResponse(['status'=>'error','error_code'=>'CURRENT_PASSWORD_REQUIRED','message'=>'current_password required']);
}
if (empty($newPassword)) {
    jsonResponse(['status'=>'error','error_code'=>'NEW_PASSWORD_REQUIRED','message'=>'new_password required']);
}
if (empty($confirmPassword)) {
    jsonResponse(['status'=>'error','error_code'=>'CONFIRM_PASSWORD_REQUIRED','message'=>'confirm_password required']);
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

// ❌ WEAK PASSWORD VALIDATION (A-Z, 0-9) YAHAN SE HATA DI GAYI HAI

if ($newPassword === $currentPassword) {
    jsonResponse(['status'=>'error','error_code'=>'PASSWORD_SAME_AS_OLD','message'=>'New password must be different from current password']);
}

$ip = getClientIp();

// ─── id se customer dhoondein (sirf ek) ───
$stmt = $db->prepare("SELECT * FROM hascol_customer WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'CUSTOMER_NOT_FOUND',
        'message'    => 'Customer not found.'
    ]);
}

if ((int)$customer['verified'] !== 1) {
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

// ─── Sirf USI customer ka current password verify karo (AES-256-GCM support) ───
if (!verifyPassword($currentPassword, $customer['password'])) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'INVALID_CURRENT_PASSWORD',
        'message'    => 'Your current password is incorrect.'
    ]);
}

$playerId = $customer['player_id'];
$key      = $customer['player_key'];

// ─── Naya Password AES-256-GCM mein Encrypt karo ───
$newPasswordEncrypted = encryptPassword($newPassword);

if ($newPasswordEncrypted === '') {
    error_log("CHANGE_PASSWORD: encryption failed. id={$customer['id']}");
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'ENCRYPTION_FAILED',
        'message'    => 'Password encryption failed. Please try again.'
    ]);
}

// ─── UPDATE: password (AES Encrypted) ───
$stmt = $db->prepare("
    UPDATE hascol_customer 
    SET password = ?, 
        updated_at = NOW() 
    WHERE id = ?
");
$stmt->bind_param("si", $newPasswordEncrypted, $customer['id']);

if (!$stmt->execute()) {
    error_log("CHANGE_PASSWORD: execute failed. id={$customer['id']}, error=" . $stmt->error);
    $stmt->close();
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'PASSWORD_UPDATE_FAILED',
        'message'    => 'Password update failed. Please try again.'
    ]);
}

$affected = $stmt->affected_rows;
$stmt->close();

if ($affected === 0) {
    error_log("CHANGE_PASSWORD: 0 rows affected. id={$customer['id']}");
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'PASSWORD_UPDATE_FAILED',
        'message'    => 'Password could not be updated. Please try again.'
    ]);
}

// ─── Security: Saare purane sessions inactive kar dein ───
$stmt = $db->prepare("UPDATE sessions SET is_active = 0 WHERE player_id = ?");
$stmt->bind_param("s", $playerId);
$stmt->execute();
$stmt->close();

// ─── Firebase mein update karein (backup) ───
fbPatch("hascol_customer/$key", [
    'password_updated_at' => date('Y-m-d H:i:s'),
    'updated_at'          => date('Y-m-d H:i:s'),
]);

// ─── OTP log mein entry karein ───
$stmt = $db->prepare("
    INSERT INTO otp_logs (player_key, action, otp, ip_address, note) 
    VALUES (?, 'password_changed', NULL, ?, 'Password changed successfully')
");
$stmt->bind_param("ss", $key, $ip);
$stmt->execute();
$stmt->close();

// ─── Success ───
jsonResponse([
    'status'  => 'success',
    'message' => 'Password changed successfully. Please login again with new password.',
]);
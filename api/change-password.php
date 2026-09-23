<?php
/**
 * CHANGE PASSWORD API (Without Mobile — NOT RECOMMENDED)
 * 
 * POST /api/change-password.php
 * Body: { 
 *   current_password: "oldpass123",
 *   new_password: "newpass456",
 *   confirm_password: "newpass456"
 * }
 * 
 * ⚠️ WARNING: Ye unsafe hai. Agar 2 customers ke passwords same hain,
 * to dono ka password change ho sakta hai.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'config.php';
require 'db.php';

$input           = getInput();
$currentPassword = trim($input['current_password'] ?? '');
$newPassword     = trim($input['new_password'] ?? '');
$confirmPassword = trim($input['confirm_password'] ?? '');

// ─── Validation ───
if (empty($currentPassword)) {
    jsonResponse(['status'=>'error','message'=>'current_password required']);
}
if (empty($newPassword)) {
    jsonResponse(['status'=>'error','message'=>'new_password required']);
}
if (empty($confirmPassword)) {
    jsonResponse(['status'=>'error','message'=>'confirm_password required']);
}

// New password aur confirm password match hone chahiye
if ($newPassword !== $confirmPassword) {
    jsonResponse(['status'=>'error','message'=>'New password and confirm password do not match']);
}

// New password minimum 6 characters
if (strlen($newPassword) < 6) {
    jsonResponse(['status'=>'error','message'=>'New password must be at least 6 characters']);
}

// New password current password se alag hona chahiye
if ($newPassword === $currentPassword) {
    jsonResponse(['status'=>'error','message'=>'New password must be different from current password']);
}

$ip = getClientIp();

// ─── ⚠️ Saare customers lein aur current password match karein ───
$stmt = $db->prepare("SELECT * FROM customers WHERE verified = 1");
$stmt->execute();
$result = $stmt->get_result();

$matchedCustomer = null;
while ($row = $result->fetch_assoc()) {
    if (password_verify($currentPassword, $row['password'])) {
        $matchedCustomer = $row;
        break;   // ⚠️ Pehla match milte hi ruk jayega
    }
}
$stmt->close();

if (!$matchedCustomer) {
    jsonResponse(['status'=>'error','message'=>'Current password is incorrect']);
}

$playerId = $matchedCustomer['player_id'];
$key      = $matchedCustomer['player_key'];

// ─── Naya password hash karein ───
$newPasswordHash = password_hash($newPassword, PASSWORD_BCRYPT);

// ─── MySQL mein password update karein ───
$stmt = $db->prepare("
    UPDATE customers 
    SET password = ?, updated_at = NOW() 
    WHERE player_id = ?
");
$stmt->bind_param("ss", $newPasswordHash, $playerId);
if (!$stmt->execute()) {
    jsonResponse(['status'=>'error','message'=>'Password update failed: ' . $stmt->error]);
}
$stmt->close();

// ─── Security: Saare purane sessions inactive kar dein ───
$stmt = $db->prepare("UPDATE sessions SET is_active = 0 WHERE player_id = ?");
$stmt->bind_param("s", $playerId);
$stmt->execute();
$stmt->close();

// ─── Firebase mein update karein (backup) ───
fbPatch("customers/$key", [
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
<?php
// verify-device-otp.php — Step 2: OTP verify + request INSERT + IMEI UPDATE

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(0); }
header("Content-Type: application/json; charset=utf-8");
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Only POST method allowed']);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$input = getInput();
$otpId = (int)($input['otp_id'] ?? 0);
$otpIn = trim((string)($input['otp'] ?? ''));

if ($otpId <= 0)                      jsonResponse(['status'=>'error','message'=>'otp_id required']);
if ($otpIn === '')                    jsonResponse(['status'=>'error','message'=>'OTP required']);
if (!preg_match('/^\d{6}$/', $otpIn)) jsonResponse(['status'=>'error','message'=>'OTP must be 6 digits']);

try {
    $db->begin_transaction();

    // ─── OTP row lock karein ───
    $stmt = $db->prepare("SELECT * FROM hascol_device_change_otps WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $otpId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $db->rollback();
        jsonResponse(['status'=>'error','error_code'=>'OTP_NOT_FOUND','message'=>'OTP record not found. Please request a new OTP.']);
    }
    if ($row['verified_at'] !== null) {
        $db->rollback();
        jsonResponse(['status'=>'error','message'=>'This OTP has already been used.']);
    }
    if (strtotime($row['expires_at']) < time()) {
        $db->rollback();
        jsonResponse(['status'=>'error','error_code'=>'OTP_EXPIRED','message'=>'OTP expired. Please request a new one.']);
    }
    if ((int)$row['attempts'] >= OTP_MAX_ATTEMPTS) {
        $db->rollback();
        jsonResponse(['status'=>'error','error_code'=>'OTP_LOCKED','message'=>'Too many incorrect attempts. Please request a new OTP.']);
    }

    // ─── OTP verify ───
    if (!password_verify($otpIn, $row['otp_hash'])) {
        $upd = $db->prepare("UPDATE hascol_device_change_otps SET attempts = attempts + 1 WHERE id = ?");
        $upd->bind_param("i", $otpId);
        $upd->execute();
        $upd->close();
        $db->rollback();
        jsonResponse(['status'=>'error','error_code'=>'OTP_INVALID','message'=>'Invalid OTP.']);
    }

    // ═══════════════════════════════════════════════════
    // OTP SAHI — AB ASLI KAAM:
    // ═══════════════════════════════════════════════════

    $customerId = (int)$row['customer_id'];
    $newImei    = $row['new_imei'];

    // Customer details fetch karein
    $stmt = $db->prepare("SELECT id, player_id, imei, status, verified FROM hascol_customer WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $customerId);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$customer) {
        $db->rollback();
        jsonResponse(['status'=>'error','message'=>'Customer not found.']);
    }
    if ($customer['verified'] != 1 || $customer['status'] !== 'active') {
        $db->rollback();
        jsonResponse(['status'=>'error','message'=>'Account is not active.']);
    }

    $storedImei = trim((string)($customer['imei'] ?? ''));
    if ($storedImei === '') {
        $db->rollback();
        jsonResponse(['status'=>'error','message'=>'No device registered.']);
    }
    if (hash_equals($storedImei, $newImei)) {
        $db->rollback();
        jsonResponse(['status'=>'error','message'=>'This device is already registered.']);
    }

    // Naya IMEI kisi aur ke paas toh nahi?
    $stmt = $db->prepare("SELECT id FROM hascol_customer WHERE imei = ? AND id <> ? LIMIT 1");
    $stmt->bind_param("si", $newImei, $customerId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        $db->rollback();
        jsonResponse([
            'status'     => 'error',
            'error_code' => 'IMEI_ALREADY_REGISTERED',
            'message'    => 'This device is already registered with another customer account.',
        ]);
    }
    $stmt->close();

    // Pending request duplicate check
    $stmt = $db->prepare("SELECT id FROM hascol_device_change_requests WHERE customer_id = ? AND status = 'pending' LIMIT 1");
    $stmt->bind_param("i", $customerId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        $db->rollback();
        jsonResponse(['status'=>'error','message'=>'You already have a pending request.']);
    }
    $stmt->close();

    // Reason variable set karein (agar database mein reason column hai toh wo use hoga, warna default)
    $reason = !empty($row['reason']) ? $row['reason'] : 'Device change verified via OTP';
    $contact = $customer['player_id'] ?? ''; 

    // ─── 1. Request INSERT ───
    $stmt = $db->prepare("
        INSERT INTO hascol_device_change_requests
        (customer_id, old_imei, new_imei, reason, contact, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->bind_param("issss", $customerId, $storedImei, $newImei, $reason, $contact);
    $stmt->execute();
    $requestId = $stmt->insert_id; // <-- Yeh line add ki gayi hai
    $stmt->close();

    // ─── 2. IMEI UPDATE (customer ka naya device register) ───
    $stmt = $db->prepare("UPDATE hascol_customer SET imei = ? WHERE id = ?");
    $stmt->bind_param("si", $newImei, $customerId);
    $stmt->execute();
    $stmt->close();

    // ─── 3. Purane sessions band ───
    if (!empty($customer['player_id'])) {
        $stmt = $db->prepare("UPDATE sessions SET is_active = 0 WHERE player_id = ?");
        $stmt->bind_param("s", $customer['player_id']);
        $stmt->execute();
        $stmt->close();
    }

    // ─── 4. OTP row delete (one-time use) ───
    $del = $db->prepare("DELETE FROM hascol_device_change_otps WHERE id = ?");
    $del->bind_param("i", $otpId);
    $del->execute();
    $del->close();

    $db->commit();

    jsonResponse([
        'status'     => 'success',
        'message'    => 'Device change successful. You can now login on the new device.',
        'request_id' => (int)$requestId,
    ]);

} catch (Throwable $e) {
    $db->rollback();
    jsonResponse(['status'=>'error','message'=>$e->getMessage()]);
}
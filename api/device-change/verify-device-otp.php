<?php
// verify-device-otp.php — Step 2: OTP verify + Direct IMEI UPDATE
// Records are NOT deleted from OTP table, and NO request is inserted.

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

    // 1. OTP row lock karein
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

    // 2. OTP verify (Direct comparison as requested)
    if ($otpIn !== $row['otp_hash']) {
        $upd = $db->prepare("UPDATE hascol_device_change_otps SET attempts = attempts + 1 WHERE id = ?");
        $upd->bind_param("i", $otpId);
        $upd->execute();
        $upd->close();
        $db->rollback();
        jsonResponse(['status'=>'error','error_code'=>'OTP_INVALID','message'=>'Invalid OTP.']);
    }

    // OTP SAHI — Ab aage ka process
    $customerId = (int)$row['customer_id'];
    $newImei    = $row['new_imei'];

    // 3. Customer details fetch karein
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

    // 4. IMEI Checks (Update se pehle)
    $storedImei = trim((string)($customer['imei'] ?? ''));
    if ($storedImei === '') {
        $db->rollback();
        jsonResponse(['status'=>'error','message'=>'No device currently registered on this account.']);
    }
    if (hash_equals($storedImei, $newImei)) {
        $db->rollback();
        jsonResponse(['status'=>'error','message'=>'This device is already registered to your account.']);
    }

    // 🛡️ IMPORTANT: Check if the NEW IMEI is already used by ANOTHER customer
    $stmt = $db->prepare("SELECT id FROM hascol_customer WHERE imei = ? AND id <> ? LIMIT 1");
    $stmt->bind_param("si", $newImei, $customerId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        $db->rollback();
        jsonResponse([
            'status'     => 'error',
            'error_code' => 'IMEI_ALREADY_REGISTERED',
            'message'    => 'This new device is already registered with another customer account. Cannot update IMEI.',
        ]);
    }
    $stmt->close();

    // 5. Update OTP table (verified_at set karein, DELETE NAHI KARNA HAI)
    $stmt = $db->prepare("UPDATE hascol_device_change_otps SET verified_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $otpId);
    $stmt->execute();
    $stmt->close();

    // 6. IMEI UPDATE in hascol_customer
    $stmt = $db->prepare("UPDATE hascol_customer SET imei = ? WHERE id = ?");
    $stmt->bind_param("si", $newImei, $customerId);
    $stmt->execute();
    $stmt->close();

    // 7. Purane sessions band
    if (!empty($customer['player_id'])) {
        $stmt = $db->prepare("UPDATE sessions SET is_active = 0 WHERE player_id = ?");
        $stmt->bind_param("s", $customer['player_id']);
        $stmt->execute();
        $stmt->close();
    }

    $db->commit();

    jsonResponse([
        'status'     => 'success',
        'message'    => 'OTP verified successfully. Your device IMEI has been updated. You can now login on the new device.',
    ]);

} catch (Throwable $e) {
    $db->rollback();
    jsonResponse(['status'=>'error','message'=>$e->getMessage()]);
}
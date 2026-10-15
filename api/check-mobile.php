<?php
/**
 * CHECK MOBILE API
 * POST /api/check-mobile.php
 * Body: { mobile, imei }
 *
 * Step 1 of forgot-password flow:
 *  - Mobile register hai? (check)
 *  - IMEI us customer se match karti hai? (check)
 *  - Dono pass hone par App aage naya password form dikhaye
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'config.php';
require 'db.php';

$input  = getInput();
$mobile = trim($input['mobile'] ?? '');
$imei   = trim($input['imei'] ?? '');

// ─── Validation ───
if (empty($mobile)) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'MOBILE_REQUIRED',
        'message'    => 'mobile required'
    ]);
}

if (!preg_match('/^(03[0-9]{9}|\+923[0-9]{9})$/', $mobile)) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'INVALID_MOBILE',
        'message'    => 'Invalid mobile number (e.g., 03001234567)'
    ]);
}

// IMEI lazmi hai — device verification ke liye
if (empty($imei)) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'IMEI_REQUIRED',
        'message'    => 'Device IMEI is required. Please update your app and try again.'
    ]);
}

// ─── Mobile se customer dhoondein ───
$stmt = $db->prepare("SELECT id, player_id, name, mobile, imei FROM hascol_customer WHERE mobile = ? LIMIT 1");
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

// ─── IMEI DEVICE LOCK VERIFY ───
// Sirf wahi device aage badh sakta hai jis par account registered hai.
$storedImei = trim((string)($customer['imei'] ?? ''));

// Customer ka koi device registered nahi — pehle login karke bind karwao
if ($storedImei === '') {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'NO_DEVICE_REGISTERED',
        'message'    => 'No device is registered with this account. Please login first to bind your device.'
    ]);
}

// IMEI match karo (timing-safe)
if (!hash_equals($storedImei, $imei)) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'IMEI_MISMATCH',
        'message'    => 'This device is not registered with this account. Please use your registered device, or contact support to change your device.'
    ]);
}

// ─── Success ───
jsonResponse([
    'status'  => 'success',
    'message' => 'Mobile and device verified. Please enter your new password.',
    'mobile'  => $customer['mobile'],
    'name'    => $customer['name'],
]);
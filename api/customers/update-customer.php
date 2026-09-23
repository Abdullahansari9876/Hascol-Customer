<?php
/**
 * UPDATE CUSTOMER API
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Only POST method allowed']);
    exit;
}

$id            = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$player_key    = trim($_POST['player_key'] ?? '');
$player_id     = trim($_POST['player_id'] ?? '');
$name          = trim($_POST['name'] ?? '');
$email         = trim($_POST['email'] ?? '');
$mobile        = trim($_POST['mobile'] ?? '');
$password      = trim($_POST['password'] ?? '');
$imei          = trim($_POST['imei'] ?? '');
$cnic          = trim($_POST['cnic'] ?? '');
$address       = trim($_POST['address'] ?? '');
$coupon_no     = trim($_POST['coupon_no'] ?? '');
$customer_type = trim($_POST['customer_type'] ?? 'new_customer');
$status        = trim($_POST['status'] ?? 'active');
$verified      = isset($_POST['verified']) ? (int)$_POST['verified'] : 0;

$errors = [];
if ($id <= 0) $errors['id'] = 'Valid ID required';
if (empty($player_key)) $errors['player_key'] = 'Player key is required';
if (empty($player_id)) $errors['player_id'] = 'Player ID is required';
if (empty($name)) $errors['name'] = 'Name is required';
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Invalid email';
if (empty($mobile)) $errors['mobile'] = 'Mobile is required';
elseif (!preg_match('/^[0-9+\-\s]{7,20}$/', $mobile)) $errors['mobile'] = 'Invalid mobile';
if (!empty($password) && strlen($password) < 6) $errors['password'] = 'Password min 6 chars';
if (!empty($cnic) && !preg_match('/^[0-9\-]{5,15}$/', $cnic)) $errors['cnic'] = 'Invalid CNIC';
if (!in_array($customer_type, ['new_customer', 'referred_customer'])) $errors['customer_type'] = 'Invalid type';
if (!in_array($status, ['active', 'inactive', 'banned'])) $errors['status'] = 'Invalid status';

if (!empty($errors)) {
    jsonResponse(['status' => 'error', 'message' => 'Validation failed', 'errors' => $errors]);
    exit;
}

// Check exists
$stmt = $db->prepare("SELECT id, verified, verified_at FROM customers WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing) {
    jsonResponse(['status' => 'error', 'message' => 'Customer not found']);
    exit;
}

// Duplicate player_key
$stmt = $db->prepare("SELECT id FROM customers WHERE player_key = ? AND id != ? LIMIT 1");
$stmt->bind_param("si", $player_key, $id);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    jsonResponse(['status' => 'error', 'message' => 'Player key already used']);
    exit;
}
$stmt->close();

// Duplicate mobile
$stmt = $db->prepare("SELECT id FROM customers WHERE mobile = ? AND id != ? LIMIT 1");
$stmt->bind_param("si", $mobile, $id);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    jsonResponse(['status' => 'error', 'message' => 'Mobile already registered']);
    exit;
}
$stmt->close();

// Duplicate email
if (!empty($email)) {
    $stmt = $db->prepare("SELECT id FROM customers WHERE email = ? AND id != ? LIMIT 1");
    $stmt->bind_param("si", $email, $id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        jsonResponse(['status' => 'error', 'message' => 'Email already registered']);
        exit;
    }
    $stmt->close();
}

$verifiedAt = $existing['verified_at'];
if ($verified === 1 && (int)$existing['verified'] === 0) {
    $verifiedAt = date('Y-m-d H:i:s');
} elseif ($verified === 0) {
    $verifiedAt = null;
}

if (!empty($password)) {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("
        UPDATE customers 
        SET player_key = ?, player_id = ?, name = ?, email = ?, mobile = ?, password = ?,
            imei = ?, cnic = ?, address = ?, coupon_no = ?, customer_type = ?, 
            verified = ?, status = ?, verified_at = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ssssssssssiissi",
        $player_key, $player_id, $name, $email, $mobile, $hashed,
        $imei, $cnic, $address, $coupon_no, $customer_type,
        $verified, $status, $verifiedAt, $id
    );
} else {
    $stmt = $db->prepare("
        UPDATE customers 
        SET player_key = ?, player_id = ?, name = ?, email = ?, mobile = ?,
            imei = ?, cnic = ?, address = ?, coupon_no = ?, customer_type = ?, 
            verified = ?, status = ?, verified_at = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("sssssssssiissi",
        $player_key, $player_id, $name, $email, $mobile,
        $imei, $cnic, $address, $coupon_no, $customer_type,
        $verified, $status, $verifiedAt, $id
    );
}

if ($stmt->execute()) {
    $stmt->close();
    jsonResponse(['status' => 'success', 'message' => 'Customer updated successfully']);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
}
$stmt->close();
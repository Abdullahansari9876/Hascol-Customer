<?php

// ✅ Sirf OPTIONS handle karein
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Only POST method allowed']);
    exit;
}

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

// Validation
$errors = [];
if (empty($player_key)) $errors['player_key'] = 'Player key is required';
if (empty($player_id)) $errors['player_id'] = 'Player ID is required';
if (empty($name)) $errors['name'] = 'Name is required';
elseif (strlen($name) > 150) $errors['name'] = 'Name must not exceed 150 characters';
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Invalid email format';
if (empty($mobile)) $errors['mobile'] = 'Mobile is required';
elseif (!preg_match('/^[0-9+\-\s]{7,20}$/', $mobile)) $errors['mobile'] = 'Invalid mobile format';
if (empty($password)) $errors['password'] = 'Password is required';
elseif (strlen($password) < 6) $errors['password'] = 'Password must be at least 6 characters';
if (!empty($cnic) && !preg_match('/^[0-9\-]{5,15}$/', $cnic)) $errors['cnic'] = 'Invalid CNIC format';
if (!in_array($customer_type, ['new_customer', 'referred_customer'])) $errors['customer_type'] = 'Invalid customer type';
if (!in_array($status, ['active', 'inactive', 'banned'])) $errors['status'] = 'Invalid status';

if (!empty($errors)) {
    jsonResponse(['status' => 'error', 'message' => 'Validation failed', 'errors' => $errors]);
    exit;
}

// Duplicate checks
$stmt = $db->prepare("SELECT id FROM hascol_customer WHERE player_key = ? LIMIT 1");
$stmt->bind_param("s", $player_key);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    jsonResponse(['status' => 'error', 'message' => 'Player key already exists']);
    exit;
}
$stmt->close();

if (!empty($mobile)) {
    $stmt = $db->prepare("SELECT id FROM hascol_customer WHERE mobile = ? LIMIT 1");
    $stmt->bind_param("s", $mobile);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        jsonResponse(['status' => 'error', 'message' => 'Mobile number already registered']);
        exit;
    }
    $stmt->close();
}

if (!empty($email)) {
    $stmt = $db->prepare("SELECT id FROM hascol_customer WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        jsonResponse(['status' => 'error', 'message' => 'Email already registered']);
        exit;
    }
    $stmt->close();
}

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$verifiedAt = $verified ? date('Y-m-d H:i:s') : null;

$stmt = $db->prepare("
    INSERT INTO hascol_customer 
    (player_key, player_id, name, email, mobile, password, imei, cnic, address, coupon_no, 
     customer_type, verified, status, ip_address, created_at, updated_at, verified_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
");
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$stmt->bind_param("sssssssssssisss",
    $player_key, $player_id, $name, $email, $mobile, $hashedPassword,
    $imei, $cnic, $address, $coupon_no, $customer_type, $verified, $status, $ip, $verifiedAt
);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();
    jsonResponse([
        'status'  => 'success',
        'message' => 'Customer created successfully',
        'data'    => ['id' => (int)$newId, 'name' => $name]
    ]);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
}
$stmt->close();
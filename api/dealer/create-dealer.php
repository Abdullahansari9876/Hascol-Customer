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

$name         = trim($_POST['name'] ?? '');
$mobile       = trim($_POST['mobile'] ?? '');
$email        = trim($_POST['email'] ?? '');
$password     = trim($_POST['password'] ?? '');
$station_name = trim($_POST['station_name'] ?? '');
$address      = trim($_POST['address'] ?? '');
$city         = trim($_POST['city'] ?? '');
$status       = trim($_POST['status'] ?? 'active');

// Validation
$errors = [];
if (empty($name)) $errors['name'] = 'Name is required';
elseif (strlen($name) > 150) $errors['name'] = 'Name must not exceed 150 characters';

if (empty($mobile)) $errors['mobile'] = 'Mobile is required';
elseif (!preg_match('/^[0-9+\-\s]{7,20}$/', $mobile)) $errors['mobile'] = 'Invalid mobile format';

if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Invalid email format';
}

if (empty($password)) $errors['password'] = 'Password is required';
elseif (strlen($password) < 6) $errors['password'] = 'Password must be at least 6 characters';

if (empty($station_name)) $errors['station_name'] = 'Station name is required';
elseif (strlen($station_name) > 150) $errors['station_name'] = 'Station name must not exceed 150 characters';

if (strlen($city) > 100) $errors['city'] = 'City must not exceed 100 characters';

if (!in_array($status, ['active', 'inactive'])) $errors['status'] = 'Invalid status';

if (!empty($errors)) {
    jsonResponse(['status' => 'error', 'message' => 'Validation failed', 'errors' => $errors]);
    exit;
}

// Duplicate mobile check
$stmt = $db->prepare("SELECT id FROM dealers WHERE mobile = ? LIMIT 1");
$stmt->bind_param("s", $mobile);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    jsonResponse(['status' => 'error', 'message' => 'Mobile number already registered']);
    exit;
}
$stmt->close();

// Duplicate email check
if (!empty($email)) {
    $stmt = $db->prepare("SELECT id FROM dealers WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        jsonResponse(['status' => 'error', 'message' => 'Email already registered']);
        exit;
    }
    $stmt->close();
}

// Hash password
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Insert
$stmt = $db->prepare("
    INSERT INTO dealers (name, mobile, email, password, station_name, address, city, status, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
");
$stmt->bind_param("ssssssss",
    $name, $mobile, $email, $hashedPassword, $station_name, $address, $city, $status
);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();
    jsonResponse([
        'status'  => 'success',
        'message' => 'Dealer created successfully',
        'data'    => ['id' => (int)$newId, 'name' => $name]
    ]);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
}
$stmt->close();
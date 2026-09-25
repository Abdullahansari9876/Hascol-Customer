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

$id           = isset($_POST['id']) ? (int)$_POST['id'] : 0;
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
if ($id <= 0) $errors['id'] = 'Valid ID required';
if (empty($name)) $errors['name'] = 'Name is required';
if (empty($mobile)) $errors['mobile'] = 'Mobile is required';
elseif (!preg_match('/^[0-9+\-\s]{7,20}$/', $mobile)) $errors['mobile'] = 'Invalid mobile format';
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Invalid email';
if (empty($station_name)) $errors['station_name'] = 'Station name is required';
if (!in_array($status, ['active', 'inactive'])) $errors['status'] = 'Invalid status';
if (!empty($password) && strlen($password) < 6) $errors['password'] = 'Password min 6 chars';

if (!empty($errors)) {
    jsonResponse(['status' => 'error', 'message' => 'Validation failed', 'errors' => $errors]);
    exit;
}

// Check exists
$stmt = $db->prepare("SELECT id FROM hascol_dealers WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    jsonResponse(['status' => 'error', 'message' => 'Dealer not found']);
    exit;
}
$stmt->close();

// Duplicate mobile
$stmt = $db->prepare("SELECT id FROM hascol_dealers WHERE mobile = ? AND id != ? LIMIT 1");
$stmt->bind_param("si", $mobile, $id);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    jsonResponse(['status' => 'error', 'message' => 'Mobile already used by another dealer']);
    exit;
}
$stmt->close();

// Duplicate email
if (!empty($email)) {
    $stmt = $db->prepare("SELECT id FROM hascol_dealers WHERE email = ? AND id != ? LIMIT 1");
    $stmt->bind_param("si", $email, $id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        jsonResponse(['status' => 'error', 'message' => 'Email already used']);
        exit;
    }
    $stmt->close();
}

// Update query — password optional
if (!empty($password)) {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("
        UPDATE hascol_dealers 
        SET name = ?, mobile = ?, email = ?, password = ?, station_name = ?, address = ?, city = ?, status = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ssssssssi",
        $name, $mobile, $email, $hashed, $station_name, $address, $city, $status, $id
    );
} else {
    $stmt = $db->prepare("
        UPDATE hascol_dealers 
        SET name = ?, mobile = ?, email = ?, station_name = ?, address = ?, city = ?, status = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("sssssssi",
        $name, $mobile, $email, $station_name, $address, $city, $status, $id
    );
}

if ($stmt->execute()) {
    $stmt->close();
    jsonResponse(['status' => 'success', 'message' => 'Dealer updated successfully']);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
}
$stmt->close();
<?php

// ─── CORS / OPTIONS ───
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

// ─── Sirf POST allow ───
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(['status' => 'error', 'message' => 'Only POST method allowed']);
}

// ─── Input lo (JSON ya form-data) ───
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = (stripos($contentType, 'application/json') !== false)
    ? getInput()
    : $_POST;

$dealerId = (int)($input['dealer_id'] ?? $input['id'] ?? 0);

// ─── Sirf dealer_id validate karo ───
if ($dealerId <= 0) {
    http_response_code(400);
    jsonResponse(['status' => 'error', 'message' => 'dealer_id is required']);
}

// ─── Dealer exists? ───
$stmt = $db->prepare("SELECT id, profile_img FROM hascol_dealers WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $dealerId);
$stmt->execute();
$dealer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$dealer) {
    http_response_code(404);
    jsonResponse(['status' => 'error', 'message' => 'Dealer not found']);
}

$allowEmpty = isset($input['allow_empty']) && 
              ($input['allow_empty'] === '1' || $input['allow_empty'] === 1 || $input['allow_empty'] === true);

// ─── Fields prepare ───
$fields = [
    'name'         => trim($input['name'] ?? ''),
    'mobile'       => trim($input['mobile'] ?? ''),
    'email'        => trim($input['email'] ?? ''),
    'station_name' => trim($input['station_name'] ?? ''),
    'address'      => trim($input['address'] ?? ''),
    'city'         => trim($input['city'] ?? ''),
    'status'       => trim($input['status'] ?? ''),
];

$latitude       = $input['latitude'] ?? null;
$longitude      = $input['longitude'] ?? null;
$company_share  = $input['company_share'] ?? null;   // ✅ NEW
$dealer_share   = $input['dealer_share'] ?? null;    // ✅ NEW
$password       = trim($input['password'] ?? '');  

// ─── Validation (sirf tab jab value bheji gayi ho) ───
// Mobile validation
if ($fields['mobile'] !== '' && !preg_match('/^[0-9+\-\s]{7,20}$/', $fields['mobile'])) {
    http_response_code(400);
    jsonResponse(['status' => 'error', 'message' => 'Invalid mobile format']);
}

// Email validation
if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    jsonResponse(['status' => 'error', 'message' => 'Invalid email format']);
}

// Status validation (DB enum)
if ($fields['status'] !== '' && !in_array($fields['status'], ['active', 'inactive'])) {
    http_response_code(400);
    jsonResponse(['status' => 'error', 'message' => 'Status must be active or inactive']);
}

// Password validation
if ($password !== '' && strlen($password) < 6) {
    http_response_code(400);
    jsonResponse(['status' => 'error', 'message' => 'Password min 6 characters required']);
}

// ✅ NEW: company_share validation
if ($company_share !== null && $company_share !== '') {
    if (!is_numeric($company_share)) {
        http_response_code(400);
        jsonResponse(['status' => 'error', 'message' => 'company_share must be a number']);
    }
    if ((float)$company_share < 0) {
        http_response_code(400);
        jsonResponse(['status' => 'error', 'message' => 'company_share cannot be negative']);
    }
}

// ✅ NEW: dealer_share validation
if ($dealer_share !== null && $dealer_share !== '') {
    if (!is_numeric($dealer_share)) {
        http_response_code(400);
        jsonResponse(['status' => 'error', 'message' => 'dealer_share must be a number']);
    }
    if ((float)$dealer_share < 0) {
        http_response_code(400);
        jsonResponse(['status' => 'error', 'message' => 'dealer_share cannot be negative']);
    }
}

// ─── Duplicate mobile check ───
if ($fields['mobile'] !== '') {
    $stmt = $db->prepare("SELECT id FROM hascol_dealers WHERE mobile = ? AND id != ? LIMIT 1");
    $stmt->bind_param("si", $fields['mobile'], $dealerId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        http_response_code(400);
        jsonResponse(['status' => 'error', 'message' => 'Mobile already used by another dealer']);
    }
    $stmt->close();
}

// ─── Duplicate email check ───
if ($fields['email'] !== '') {
    $stmt = $db->prepare("SELECT id FROM hascol_dealers WHERE email = ? AND id != ? LIMIT 1");
    $stmt->bind_param("si", $fields['email'], $dealerId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        http_response_code(400);
        jsonResponse(['status' => 'error', 'message' => 'Email already used by another dealer']);
    }
    $stmt->close();
}

// ─── Profile image: File ya Text dono handle karo ───
$finalProfileImg = '';
$hasProfileImgUpdate = false;

// Option 1: File upload
if (isset($_FILES['profile_img']) && $_FILES['profile_img']['error'] !== UPLOAD_ERR_NO_FILE) {

    $file = $_FILES['profile_img'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        jsonResponse(['status' => 'error', 'message' => 'File upload error code: ' . $file['error']]);
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        http_response_code(400);
        jsonResponse(['status' => 'error', 'message' => 'Image too large (max 5MB)']);
    }

    $allowedMime = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedMime)) {
        http_response_code(400);
        jsonResponse(['status' => 'error', 'message' => 'Only JPG, PNG, WEBP allowed']);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
        http_response_code(400);
        jsonResponse(['status' => 'error', 'message' => 'Invalid file extension']);
    }

    $uploadDir = __DIR__ . '/../../uploads/dealers/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = 'dealer_' . $dealerId . '_' . time() . '.' . $ext;
    $filepath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        http_response_code(500);
        jsonResponse(['status' => 'error', 'message' => 'Failed to save image']);
    }

    // Purani image delete
    if (!empty($dealer['profile_img'])) {
        $oldFile = __DIR__ . '/../../' . $dealer['profile_img'];
        if (file_exists($oldFile) && is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    $finalProfileImg = 'uploads/dealers/' . $filename;
    $hasProfileImgUpdate = true;
}
// Option 2: Text path/URL
elseif (isset($input['profile_img']) && trim($input['profile_img']) !== '') {
    $finalProfileImg = trim($input['profile_img']);
    $hasProfileImgUpdate = true;
}

// ─── Kam se kam ek field? ───
$hasField = !empty($fields['name'])
    || !empty($fields['mobile'])
    || !empty($fields['email'])
    || !empty($fields['station_name'])
    || !empty($fields['address'])
    || !empty($fields['city'])
    || !empty($fields['status'])
    || !empty($password)
    || ($latitude !== null && $latitude !== '')
    || ($longitude !== null && $longitude !== '')
    || ($company_share !== null && $company_share !== '')    // ✅ NEW
    || ($dealer_share !== null && $dealer_share !== '')      // ✅ NEW
    || $hasProfileImgUpdate
    || $allowEmpty;

if (!$hasField) {
    http_response_code(400);
    jsonResponse(['status' => 'error', 'message' => 'At least one field is required to update']);
}

// ─── Build dynamic UPDATE query ───
$updates = [];
$params  = [];
$types   = '';

foreach ($fields as $col => $val) {
    if ($val !== '' || $allowEmpty) {
        $updates[] = "$col = ?";
        $params[]  = $val;
        $types    .= 's';
    }
}

// Password update
if ($password !== '') {
    $hashed    = password_hash($password, PASSWORD_DEFAULT);
    $updates[] = "password = ?";
    $params[]  = $hashed;
    $types    .= 's';
}

if ($latitude !== null && $latitude !== '') {
    $updates[] = "latitude = ?";
    $params[]  = (float)$latitude;
    $types    .= 'd';
}

if ($longitude !== null && $longitude !== '') {
    $updates[] = "longitude = ?";
    $params[]  = (float)$longitude;
    $types    .= 'd';
}

// ✅ NEW: company_share update
if ($company_share !== null && $company_share !== '') {
    $updates[] = "company_share = ?";
    $params[]  = (float)$company_share;
    $types    .= 'd';
}

// ✅ NEW: dealer_share update
if ($dealer_share !== null && $dealer_share !== '') {
    $updates[] = "dealer_share = ?";
    $params[]  = (float)$dealer_share;
    $types    .= 'd';
}

if ($hasProfileImgUpdate) {
    $updates[] = "profile_img = ?";
    $params[]  = $finalProfileImg;
    $types    .= 's';
}

$updates[] = "updated_at = NOW()";
$params[]  = $dealerId;
$types    .= 'i';

$sql = "UPDATE hascol_dealers SET " . implode(', ', $updates) . " WHERE id = ?";

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    http_response_code(500);
    jsonResponse(['status' => 'error', 'message' => 'Update failed. Please try again.']);
}
$stmt->close();

// ─── Success response ───
http_response_code(200);
jsonResponse([
    'status'  => 'success',
    'message' => 'Dealer updated successfully',
]);
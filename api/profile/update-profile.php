<?php
/**
 * UPDATE PROFILE API (Without Token) — With Image Upload
 * 
 * POST /hascol_customer/api/profile/update-profile.php
 * 
 * Fields:
 *   - customer_id    (required)
 *   - name           (optional)
 *   - email          (optional)
 *   - cnic           (optional)
 *   - address        (optional)
 *   - profile_image  (optional, file ya URL)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../db.php';

// ─── Input handle karo (JSON ya multipart) ───
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') !== false) {
    $input = getInput();
} else {
    $input = $_POST;
}

$customerId   = (int)($input['customer_id'] ?? 0);
$name         = trim($input['name'] ?? '');
$email        = trim($input['email'] ?? '');
$cnic         = trim($input['cnic'] ?? '');
$address      = trim($input['address'] ?? '');
$profileImage = trim($input['profile_image'] ?? '');

// ─── Validation: customer_id ───
if ($customerId <= 0) {
    jsonResponse(['status'=>'error','message'=>'customer_id required']);
}

// ─── Customer check ───
$stmt = $db->prepare("SELECT id, name, profile_image FROM customers WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customerCheck = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customerCheck) {
    jsonResponse(['status'=>'error','message'=>'Customer not found']);
}

// ─── Email validation ───
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['status'=>'error','message'=>'Invalid email format']);
}

// ─── CNIC validation ───
if (!empty($cnic)) {
    $cnicClean = preg_replace('/[^0-9]/', '', $cnic);
    if (strlen($cnicClean) !== 13) {
        jsonResponse(['status'=>'error','message'=>'CNIC must be 13 digits']);
    }
    // Duplicate check
    $stmt = $db->prepare("SELECT id FROM customers WHERE cnic = ? AND id != ? LIMIT 1");
    $stmt->bind_param("si", $cnicClean, $customerId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        jsonResponse(['status'=>'error','message'=>'CNIC already registered']);
    }
    $stmt->close();
    $cnic = $cnicClean;
}

// ─── Address validation ───
if (!empty($address) && strlen($address) > 255) {
    jsonResponse(['status'=>'error','message'=>'Address too long (max 255 chars)']);
}

// ─── IMAGE UPLOAD ───
$uploadedImagePath = '';

if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {

    $file = $_FILES['profile_image'];

    // Size check (5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        jsonResponse(['status'=>'error','message'=>'Image too large (max 5MB)']);
    }

    // Type check
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedTypes)) {
        jsonResponse(['status'=>'error','message'=>'Only JPG, PNG, WEBP allowed']);
    }

    // Upload folder
    $uploadDir = __DIR__ . '/../../uploads/profiles/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (empty($ext)) $ext = 'jpg';

    $filename = 'profile_' . $customerId . '_' . time() . '.' . $ext;
    $filepath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonResponse(['status'=>'error','message'=>'Failed to save image']);
    }

    // Purani image delete karo
    if (!empty($customerCheck['profile_image'])) {
        $oldFile = __DIR__ . '/../../' . $customerCheck['profile_image'];
        if (file_exists($oldFile) && is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    $uploadedImagePath = 'uploads/profiles/' . $filename;
}

// ─── Kam se kam ek field ───
if (empty($name) && empty($email) && empty($cnic) && empty($address) 
    && empty($profileImage) && empty($uploadedImagePath)) {
    jsonResponse(['status'=>'error','message'=>'At least one field required']);
}

// ─── Update query build ───
$updates = [];
$params  = [];
$types   = "";

if (!empty($name)) {
    $updates[] = "name = ?";
    $params[]  = $name;
    $types    .= "s";
}

if (!empty($email)) {
    $updates[] = "email = ?";
    $params[]  = $email;
    $types    .= "s";
}

if (!empty($cnic)) {
    $updates[] = "cnic = ?";
    $params[]  = $cnic;
    $types    .= "s";
}

if (!empty($address)) {
    $updates[] = "address = ?";
    $params[]  = $address;
    $types    .= "s";
}

if (!empty($uploadedImagePath)) {
    $updates[] = "profile_image = ?";
    $params[]  = $uploadedImagePath;
    $types    .= "s";
} elseif (!empty($profileImage)) {
    $updates[] = "profile_image = ?";
    $params[]  = $profileImage;
    $types    .= "s";
}

$updates[] = "updated_at = NOW()";
$params[]  = $customerId;
$types    .= "i";

$sql = "UPDATE customers SET " . implode(", ", $updates) . " WHERE id = ?";

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    jsonResponse(['status'=>'error','message'=>'Profile update failed: ' . $stmt->error]);
}
$stmt->close();

// ─── Simple success response ───
jsonResponse([
    'status'  => 'success',
    'message' => 'Profile updated successfully',
]);
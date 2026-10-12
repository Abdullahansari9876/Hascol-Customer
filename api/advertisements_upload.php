<?php
require 'config.php';
require 'db.php';

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['status' => 'error', 'message' => 'No image uploaded'], 400);
}

$file = $_FILES['image'];

// Validate type
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
if (!in_array($file['type'], $allowed)) {
    jsonResponse(['status' => 'error', 'message' => 'Invalid image type'], 400);
}

// Validate size (5MB)
if ($file['size'] > 5 * 1024 * 1024) {
    jsonResponse(['status' => 'error', 'message' => 'Image too large (max 5MB)'], 400);
}

// Upload dir
$uploadDir = __DIR__ . '/../uploads/advertisements/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// Unique filename
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$filename = 'adv_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$targetPath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    jsonResponse(['status' => 'error', 'message' => 'Failed to save image'], 500);
}

// Full URL (DB me yahi save hoga)
$baseUrl = 'https://hascol.allowance.flamboyant-spence.92-205-119-218.plesk.page/';
$fullUrl = $baseUrl . 'uploads/advertisements/' . $filename;

jsonResponse([
    'status'    => 'success',
    'message'   => 'Image uploaded successfully',
    'image_url' => $fullUrl,
]);
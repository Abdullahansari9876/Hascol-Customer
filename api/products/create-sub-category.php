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

$category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
$name        = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$status      = trim($_POST['status'] ?? 'active');
$sort_order  = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;

$errors = [];
if ($category_id <= 0) $errors['category_id'] = 'Category is required';
if (empty($name)) $errors['name'] = 'Sub-category name is required';
elseif (strlen($name) < 2) $errors['name'] = 'Name must be at least 2 characters';
elseif (strlen($name) > 100) $errors['name'] = 'Name must not exceed 100 characters';
if (strlen($description) > 255) $errors['description'] = 'Description must not exceed 255 characters';
if (!in_array($status, ['active', 'inactive'])) $errors['status'] = 'Status must be active or inactive';

if (!empty($errors)) {
    jsonResponse(['status' => 'error', 'message' => 'Validation failed', 'errors' => $errors]);
    exit;
}

// Check category exists
$stmt = $db->prepare("SELECT id FROM category WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $category_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    jsonResponse(['status' => 'error', 'message' => 'Category not found']);
    exit;
}
$stmt->close();

// Check duplicate in same category
$stmt = $db->prepare("SELECT id FROM sub_category WHERE name = ? AND category_id = ? LIMIT 1");
$stmt->bind_param("si", $name, $category_id);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    jsonResponse(['status' => 'error', 'message' => 'Sub-category already exists in this category']);
    exit;
}
$stmt->close();

// File upload
$image = '';
if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['image_file'];
    if ($file['size'] > 2 * 1024 * 1024) {
        jsonResponse(['status' => 'error', 'message' => 'Image must not exceed 2 MB']); exit;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, ['image/jpeg','image/jpg','image/png','image/gif','image/webp'])) {
        jsonResponse(['status' => 'error', 'message' => 'Invalid image type']); exit;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) $ext = 'jpg';
    $newName = 'subcategory_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $uploadDir = __DIR__ . '/../../uploads/sub_categories/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $newName)) {
        $image = 'http://localhost:8080/hascol_customer/uploads/sub_categories/' . $newName;
    } else {
        jsonResponse(['status' => 'error', 'message' => 'Failed to upload image']); exit;
    }
}

$stmt = $db->prepare("
    INSERT INTO sub_category (category_id, name, description, image, status, sort_order, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
");
$stmt->bind_param("issssi", $category_id, $name, $description, $image, $status, $sort_order);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();
    jsonResponse([
        'status'  => 'success',
        'message' => 'Sub-category created successfully',
        'data'    => ['id' => (int)$newId, 'name' => $name]
    ]);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
}
$stmt->close();
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

$id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
$name        = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$status      = trim($_POST['status'] ?? 'active');
$sort_order  = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
$removeImage = isset($_POST['remove_image']) && $_POST['remove_image'] === '1';

$errors = [];
if ($id <= 0) $errors['id'] = 'Valid ID required';
if ($category_id <= 0) $errors['category_id'] = 'Category is required';
if (empty($name)) $errors['name'] = 'Name is required';
if (strlen($name) > 100) $errors['name'] = 'Name must not exceed 100 characters';
if (!in_array($status, ['active', 'inactive'])) $errors['status'] = 'Invalid status';

if (!empty($errors)) {
    jsonResponse(['status' => 'error', 'message' => 'Validation failed', 'errors' => $errors]);
    exit;
}

// Check exists
$stmt = $db->prepare("SELECT id, image FROM sub_category WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing) {
    jsonResponse(['status' => 'error', 'message' => 'Sub-category not found']);
    exit;
}

// Duplicate check
$stmt = $db->prepare("SELECT id FROM sub_category WHERE name = ? AND category_id = ? AND id != ? LIMIT 1");
$stmt->bind_param("sii", $name, $category_id, $id);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    jsonResponse(['status' => 'error', 'message' => 'Another sub-category with this name exists in this category']);
    exit;
}
$stmt->close();

$finalImage = $removeImage ? '' : ($existing['image'] ?? '');

// File upload
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
        // Delete old
        if (!empty($existing['image']) && strpos($existing['image'], '/uploads/sub_categories/') !== false) {
            $old = __DIR__ . '/../../uploads/sub_categories/' . basename($existing['image']);
            if (file_exists($old)) @unlink($old);
        }
        $finalImage = 'http://localhost:8080/hascol_customer/uploads/sub_categories/' . $newName;
    }
}

$stmt = $db->prepare("
    UPDATE sub_category 
    SET category_id = ?, name = ?, description = ?, image = ?, status = ?, sort_order = ?, updated_at = NOW()
    WHERE id = ?
");
$stmt->bind_param("issssii", $category_id, $name, $description, $finalImage, $status, $sort_order, $id);

if ($stmt->execute()) {
    $stmt->close();
    jsonResponse(['status' => 'success', 'message' => 'Sub-category updated successfully']);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
}
$stmt->close();
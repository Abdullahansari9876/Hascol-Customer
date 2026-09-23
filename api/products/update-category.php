<?php
/**
 * UPDATE CATEGORY API
 * POST /api/products/update-category.php
 */

// ─── CORS HEADERS ───
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Only POST method allowed']);
    exit;
}

// ─── Input ───
$id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$name        = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$image       = trim($_POST['image'] ?? '');       // Existing image URL (agar new upload nahi hui)
$removeImage = isset($_POST['remove_image']) && $_POST['remove_image'] === '1';
$sort_order  = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
$status      = trim($_POST['status'] ?? 'active');

// ─── Validation ───
$errors = [];

if ($id <= 0) {
    $errors['id'] = 'Valid category ID is required';
}
if (empty($name)) {
    $errors['name'] = 'Category name is required';
} elseif (strlen($name) < 2) {
    $errors['name'] = 'Category name must be at least 2 characters';
} elseif (strlen($name) > 100) {
    $errors['name'] = 'Category name must not exceed 100 characters';
}
if (strlen($description) > 255) {
    $errors['description'] = 'Description must not exceed 255 characters';
}

if (!empty($errors)) {
    jsonResponse(['status' => 'error', 'message' => 'Validation failed', 'errors' => $errors]);
    exit;
}

// ─── Check category exists ───
$stmt = $db->prepare("SELECT id, image FROM category WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing) {
    jsonResponse(['status' => 'error', 'message' => 'Category not found']);
    exit;
}

// ─── Duplicate name check ───
$stmt = $db->prepare("SELECT id FROM category WHERE name = ? AND id != ? LIMIT 1");
$stmt->bind_param("si", $name, $id);
$stmt->execute();
$dup = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($dup) {
    jsonResponse(['status' => 'error', 'message' => 'Another category with this name already exists']);
    exit;
}

// ─── Determine final image ───
$finalImage = $image;

// If no URL provided, fall back to existing
if (empty($finalImage) && !$removeImage) {
    $finalImage = $existing['image'] ?? '';
}

// If remove_image flag is set
if ($removeImage) {
    $finalImage = '';
}

// ─── File Upload Handling ───
if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['image_file'];

    $maxSize = 2 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        jsonResponse(['status' => 'error', 'message' => 'Image size must not exceed 2 MB']);
        exit;
    }

    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array(strtolower($mimeType), $allowedTypes)) {
        jsonResponse(['status' => 'error', 'message' => 'Invalid image type']);
        exit;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        $ext = 'jpg';
    }
    $newFileName = 'category_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    $uploadDir = __DIR__ . '/../../uploads/categories/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $targetPath = $uploadDir . $newFileName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        // Delete old image if exists and is a local upload
        if (!empty($existing['image']) && strpos($existing['image'], '/uploads/categories/') !== false) {
            $oldPath = __DIR__ . '/../../uploads/categories/' . basename($existing['image']);
            if (file_exists($oldPath)) @unlink($oldPath);
        }
        $finalImage = 'http://localhost:8080/hascol_customer/uploads/categories/' . $newFileName;
    } else {
        jsonResponse(['status' => 'error', 'message' => 'Failed to upload image']);
        exit;
    }
}

// ─── Update ───
$stmt = $db->prepare("
    UPDATE category 
    SET name = ?, description = ?, image = ?, status = ?, sort_order = ?, updated_at = NOW()
    WHERE id = ?
");
$stmt->bind_param("ssssii", $name, $description, $finalImage, $status, $sort_order, $id);

if ($stmt->execute()) {
    $stmt->close();
    jsonResponse([
        'status'  => 'success',
        'message' => 'Category updated successfully',
        'data'    => [
            'id'          => $id,
            'name'        => $name,
            'description' => $description,
            'image'       => $finalImage,
            'status'      => $status,
            'sort_order'  => $sort_order,
        ]
    ]);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
}

$stmt->close();
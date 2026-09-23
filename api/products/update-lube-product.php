<?php
/**
 * UPDATE LUBE PRODUCT API
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

$id               = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$sub_category_id  = isset($_POST['sub_category_id']) ? (int)$_POST['sub_category_id'] : 0;
$name             = trim($_POST['name'] ?? '');
$brand            = trim($_POST['brand'] ?? '');
$sku              = trim($_POST['sku'] ?? '');
$description      = trim($_POST['description'] ?? '');
$price            = isset($_POST['price']) ? (float)$_POST['price'] : 0;
$discount_percent = isset($_POST['discount_percent']) ? (float)$_POST['discount_percent'] : 0;
$stock            = isset($_POST['stock']) ? (int)$_POST['stock'] : 0;
$status           = trim($_POST['status'] ?? 'active');
$removeImage      = isset($_POST['remove_image']) && $_POST['remove_image'] === '1';

$errors = [];
if ($id <= 0) $errors['id'] = 'Valid ID required';
if ($sub_category_id <= 0) $errors['sub_category_id'] = 'Sub-category is required';
if (empty($name)) $errors['name'] = 'Name is required';
if ($price < 0) $errors['price'] = 'Price cannot be negative';
if ($discount_percent < 0 || $discount_percent > 100) $errors['discount_percent'] = 'Discount must be 0-100';
if ($stock < 0) $errors['stock'] = 'Stock cannot be negative';
if (!in_array($status, ['active', 'inactive'])) $errors['status'] = 'Invalid status';

if (!empty($errors)) {
    jsonResponse(['status' => 'error', 'message' => 'Validation failed', 'errors' => $errors]);
    exit;
}

// Check exists
$stmt = $db->prepare("SELECT id, image, category_id FROM lube_products WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing) {
    jsonResponse(['status' => 'error', 'message' => 'Product not found']);
    exit;
}

// Get subcat's category
$stmt = $db->prepare("SELECT category_id FROM sub_category WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $sub_category_id);
$stmt->execute();
$subcat = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$subcat) {
    jsonResponse(['status' => 'error', 'message' => 'Sub-category not found']);
    exit;
}
$category_id = (int)$subcat['category_id'];

// SKU duplicate check
if (!empty($sku)) {
    $stmt = $db->prepare("SELECT id FROM lube_products WHERE sku = ? AND id != ? LIMIT 1");
    $stmt->bind_param("si", $sku, $id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        jsonResponse(['status' => 'error', 'message' => 'SKU already used']);
        exit;
    }
    $stmt->close();
}

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
    $newName = 'product_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $uploadDir = __DIR__ . '/../../uploads/lube_products/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $newName)) {
        if (!empty($existing['image']) && strpos($existing['image'], '/uploads/lube_products/') !== false) {
            $old = __DIR__ . '/../../uploads/lube_products/' . basename($existing['image']);
            if (file_exists($old)) @unlink($old);
        }
        $finalImage = 'http://localhost:8080/hascol_customer/uploads/lube_products/' . $newName;
    }
}

$stmt = $db->prepare("
    UPDATE lube_products 
    SET sub_category_id = ?, category_id = ?, name = ?, brand = ?, sku = ?, description = ?, 
        image = ?, price = ?, discount_percent = ?, stock = ?, status = ?, updated_at = NOW()
    WHERE id = ?
");
$stmt->bind_param("iisssssddisi",
    $sub_category_id, $category_id, $name, $brand, $sku, $description,
    $finalImage, $price, $discount_percent, $stock, $status, $id
);

if ($stmt->execute()) {
    $stmt->close();
    jsonResponse(['status' => 'success', 'message' => 'Product updated successfully']);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
}
$stmt->close();
<?php
/**
 * CREATE LUBE PRODUCT API
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

$sub_category_id  = isset($_POST['sub_category_id']) ? (int)$_POST['sub_category_id'] : 0;
$category_id      = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
$name             = trim($_POST['name'] ?? '');
$brand            = trim($_POST['brand'] ?? '');
$sku              = trim($_POST['sku'] ?? '');
$description      = trim($_POST['description'] ?? '');
$price            = isset($_POST['price']) ? (float)$_POST['price'] : 0;
$discount_percent = isset($_POST['discount_percent']) ? (float)$_POST['discount_percent'] : 0;
$stock            = isset($_POST['stock']) ? (int)$_POST['stock'] : 0;
$status           = trim($_POST['status'] ?? 'active');

$errors = [];
if ($sub_category_id <= 0) $errors['sub_category_id'] = 'Sub-category is required';
if (empty($name)) $errors['name'] = 'Product name is required';
elseif (strlen($name) > 150) $errors['name'] = 'Name must not exceed 150 characters';
if (strlen($brand) > 100) $errors['brand'] = 'Brand must not exceed 100 characters';
if (strlen($sku) > 50) $errors['sku'] = 'SKU must not exceed 50 characters';
if ($price < 0) $errors['price'] = 'Price cannot be negative';
if ($discount_percent < 0 || $discount_percent > 100) $errors['discount_percent'] = 'Discount must be 0-100';
if ($stock < 0) $errors['stock'] = 'Stock cannot be negative';
if (!in_array($status, ['active', 'inactive'])) $errors['status'] = 'Invalid status';

if (!empty($errors)) {
    jsonResponse(['status' => 'error', 'message' => 'Validation failed', 'errors' => $errors]);
    exit;
}

// Check sub-category exists + get category
$stmt = $db->prepare("SELECT id, category_id FROM sub_category WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $sub_category_id);
$stmt->execute();
$subcat = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$subcat) {
    jsonResponse(['status' => 'error', 'message' => 'Sub-category not found']);
    exit;
}
if ($category_id <= 0) $category_id = (int)$subcat['category_id'];

// SKU duplicate check
if (!empty($sku)) {
    $stmt = $db->prepare("SELECT id FROM lube_products WHERE sku = ? LIMIT 1");
    $stmt->bind_param("s", $sku);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        jsonResponse(['status' => 'error', 'message' => 'SKU already exists']);
        exit;
    }
    $stmt->close();
}

// Image upload
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
    $newName = 'product_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $uploadDir = __DIR__ . '/../../uploads/lube_products/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $newName)) {
        $image = 'http://localhost:8080/hascol_customer/uploads/lube_products/' . $newName;
    }
}

$stmt = $db->prepare("
    INSERT INTO lube_products 
    (sub_category_id, category_id, name, brand, sku, description, image, price, discount_percent, stock, status, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
");
$stmt->bind_param("iisssssddis",
    $sub_category_id, $category_id, $name, $brand, $sku, $description,
    $image, $price, $discount_percent, $stock, $status
);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();
    jsonResponse([
        'status'  => 'success',
        'message' => 'Product created successfully',
        'data'    => ['id' => (int)$newId, 'name' => $name]
    ]);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
}
$stmt->close();
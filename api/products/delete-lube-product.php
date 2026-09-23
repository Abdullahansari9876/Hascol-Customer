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

$input = getInput();
$id = isset($input['id']) ? (int)$input['id'] : 0;

if ($id <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'Valid ID required']);
    exit;
}

$stmt = $db->prepare("SELECT id, name, image FROM lube_products WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    jsonResponse(['status' => 'error', 'message' => 'Product not found']);
    exit;
}

if (!empty($row['image']) && strpos($row['image'], '/uploads/lube_products/') !== false) {
    $old = __DIR__ . '/../../uploads/lube_products/' . basename($row['image']);
    if (file_exists($old)) @unlink($old);
}

$stmt = $db->prepare("DELETE FROM lube_products WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    $stmt->close();
    jsonResponse(['status' => 'success', 'message' => 'Product deleted successfully']);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
}
$stmt->close();
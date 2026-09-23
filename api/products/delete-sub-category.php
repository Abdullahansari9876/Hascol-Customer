<?php
/**
 * DELETE SUB-CATEGORY API
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

$input = getInput();
$id = isset($input['id']) ? (int)$input['id'] : 0;

if ($id <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'Valid ID required']);
    exit;
}

// Check exists + get image
$stmt = $db->prepare("SELECT id, name, image FROM sub_category WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    jsonResponse(['status' => 'error', 'message' => 'Sub-category not found']);
    exit;
}

// Delete image
if (!empty($row['image']) && strpos($row['image'], '/uploads/sub_categories/') !== false) {
    $old = __DIR__ . '/../../uploads/sub_categories/' . basename($row['image']);
    if (file_exists($old)) @unlink($old);
}

// Delete
$stmt = $db->prepare("DELETE FROM sub_category WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    $stmt->close();
    jsonResponse(['status' => 'success', 'message' => 'Sub-category deleted successfully']);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
}
$stmt->close();
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

// ─── Only POST allowed ───
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Only POST method allowed']);
    exit;
}

// ─── Get input ───
$input = getInput();
$id = isset($input['id']) ? (int)$input['id'] : 0;

// ─── Validation ───
if ($id <= 0) {
    jsonResponse([
        'status'  => 'error',
        'message' => 'Valid category ID is required'
    ]);
    exit;
}

// ─── Check category exists ───
$stmt = $db->prepare("SELECT id, name FROM category WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$category = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$category) {
    jsonResponse([
        'status'  => 'error',
        'message' => 'Category not found'
    ]);
    exit;
}

// ─── Delete ───
$stmt = $db->prepare("DELETE FROM category WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    $stmt->close();

    jsonResponse([
        'status'  => 'success',
        'message' => 'Category deleted successfully',
        'data'    => [
            'id'   => $id,
            'name' => $category['name']
        ]
    ]);
} else {
    jsonResponse([
        'status'  => 'error',
        'message' => 'Database error: ' . $db->error
    ]);
}

$stmt->close();
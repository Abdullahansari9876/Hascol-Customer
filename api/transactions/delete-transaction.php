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

// Check exists
$stmt = $db->prepare("SELECT id, transaction_ref FROM transactions WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    jsonResponse(['status' => 'error', 'message' => 'Transaction not found']);
    exit;
}

// Delete
$stmt = $db->prepare("DELETE FROM transactions WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    $stmt->close();
    jsonResponse(['status' => 'success', 'message' => 'Transaction deleted successfully']);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
}
$stmt->close();
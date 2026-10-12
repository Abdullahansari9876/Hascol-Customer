<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$input = getInput();
$id = (int)($input['id'] ?? 0);
if (!$id) jsonResponse(['status' => 'error', 'message' => 'id is required'], 400);

// Required fields validation
$required = ['title', 'image_url', 'valid_from', 'valid_to'];
foreach ($required as $f) {
    if (empty($input[$f])) {
        jsonResponse(['status' => 'error', 'message' => "$f is required"], 400);
    }
}

$check = $db->prepare("SELECT id FROM advertisements WHERE id = ?");
$check->bind_param("i", $id);
$check->execute();
if (!$check->get_result()->fetch_assoc()) {
    jsonResponse(['status' => 'error', 'message' => 'Advertisement not found'], 404);
}
$check->close();

// Values pehle variables me rakhein (bind_param ko variables chahiye)
$title       = trim($input['title']);
$description = isset($input['description']) ? trim($input['description']) : '';
$image_url   = trim($input['image_url']);
$valid_from  = $input['valid_from'];
$valid_to    = $input['valid_to'];
$status      = (isset($input['status']) && in_array($input['status'], ['active', 'inactive'], true))
                ? $input['status']
                : 'active';

$stmt = $db->prepare("
    UPDATE advertisements SET 
        title = ?, description = ?, image_url = ?, 
        valid_from = ?, valid_to = ?, status = ?
    WHERE id = ?
");

if (!$stmt) {
    jsonResponse(['status' => 'error', 'message' => $db->error], 500);
}

$stmt->bind_param(
    "ssssssi",
    $title,
    $description,
    $image_url,
    $valid_from,
    $valid_to,
    $status,
    $id
);

if (!$stmt->execute()) {
    jsonResponse(['status' => 'error', 'message' => $stmt->error], 500);
}

jsonResponse(['status' => 'success', 'message' => 'Advertisement updated successfully']);
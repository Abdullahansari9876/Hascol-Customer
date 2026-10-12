<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$input = getInput();

$required = ['title', 'image_url', 'valid_from', 'valid_to'];
foreach ($required as $f) {
    if (empty($input[$f])) {
        jsonResponse(['status' => 'error', 'message' => "$f is required"], 400);
    }
}

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
    INSERT INTO advertisements 
    (title, description, image_url, valid_from, valid_to, status) 
    VALUES (?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    jsonResponse(['status' => 'error', 'message' => $db->error], 500);
}

$stmt->bind_param(
    "ssssss",
    $title,
    $description,
    $image_url,
    $valid_from,
    $valid_to,
    $status
);

if (!$stmt->execute()) {
    jsonResponse(['status' => 'error', 'message' => $stmt->error], 500);
}

jsonResponse([
    'status'  => 'success',
    'message' => 'Advertisement created successfully',
    'id'      => $stmt->insert_id,
]);
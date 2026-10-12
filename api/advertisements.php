<?php
/**
 * ADVERTISEMENTS LIST (ADMIN PANEL)
 * POST /api/advertisements_list.php
 * Saari advertisements return karta hai (active + inactive)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$stmt = $db->prepare("
    SELECT id, title, description, image_url, valid_from, valid_to, status
    FROM advertisements
    ORDER BY id DESC
");

if (!$stmt) {
    jsonResponse(['status' => 'error', 'message' => $db->error], 500);
}

if (!$stmt->execute()) {
    jsonResponse(['status' => 'error', 'message' => $stmt->error], 500);
}

$result = $stmt->get_result();

$advertisements = [];
while ($row = $result->fetch_assoc()) {
    $advertisements[] = [
        'id'          => (int)$row['id'],
        'title'       => $row['title'],
        'description' => $row['description'] ?? '',
        'image_url'   => $row['image_url'],
        'valid_from'  => $row['valid_from'],
        'valid_to'    => $row['valid_to'],
        'status'      => $row['status'],
    ];
}
$stmt->close();

jsonResponse([
    'status'         => 'success',
    'message'        => 'Advertisements fetched successfully',
    'total'          => count($advertisements),
    'advertisements' => $advertisements,
]);
<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ─── Error reporting ───
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ─── Includes ───
require '../config.php';
require '../db.php';

// ─── Token verify (commented) ───
// $input = getInput();
// $token = trim($input['token'] ?? '');
// if (empty($token)) {
//     jsonResponse(['status'=>'error','message'=>'token required']);
// }

// $stmt = $db->prepare("
//     SELECT * FROM sessions 
//     WHERE token = ? AND is_active = 1 AND expires_at > NOW() 
//     LIMIT 1
// ");
// $stmt->bind_param("s", $token);
// $stmt->execute();
// $session = $stmt->get_result()->fetch_assoc();
// $stmt->close();

// if (!$session) {
//     jsonResponse(['status'=>'error','message'=>'Invalid or expired token. Please login again.']);
// }

// ─── Categories fetch ───
$stmt = $db->prepare("
    SELECT id, name, description, image, sort_order 
    FROM category 
    WHERE status = 'active' 
    ORDER BY sort_order ASC, id ASC
");
$stmt->execute();
$result = $stmt->get_result();

$categories = [];
while ($row = $result->fetch_assoc()) {
    $categories[] = [
        'id'          => (int)$row['id'],
        'name'        => $row['name'],
        'description' => $row['description'] ?? '',
        'image'       => $row['image'] ?? '',
        'sort_order'  => (int)$row['sort_order'],
    ];
}
$stmt->close();

jsonResponse([
    'status'     => 'success',
    'message'    => 'Categories fetched successfully',
    'total'      => count($categories),
    'categories' => $categories,
]);
<?php
/**
 * GET SUB-CATEGORIES API
 * 
 * POST /api/get-sub-categories.php
 * Body: { token, category_id (optional) }
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

$input      = getInput();
// $token      = trim($input['token'] ?? '');
$categoryId = (int)($input['category_id'] ?? 0);

// if (empty($token)) {
//     jsonResponse(['status'=>'error','message'=>'token required']);
// }

// ─── Token verify ───
$stmt = $db->prepare("
    SELECT * FROM sessions 
    WHERE token = ? AND is_active = 1 AND expires_at > NOW() LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

// if (!$session) {
//     jsonResponse(['status'=>'error','message'=>'Invalid or expired token. Please login again.']);
// }

// ─── Sub-categories ───
if ($categoryId > 0) {
    $stmt = $db->prepare("
        SELECT sc.id, sc.category_id, sc.name, sc.description, sc.image, sc.sort_order,
               c.name AS category_name
        FROM sub_category sc
        LEFT JOIN category c ON c.id = sc.category_id
        WHERE sc.status = 'active' AND sc.category_id = ?
        ORDER BY sc.sort_order ASC, sc.id ASC
    ");
    $stmt->bind_param("i", $categoryId);
} else {
    $stmt = $db->prepare("
        SELECT sc.id, sc.category_id, sc.name, sc.description, sc.image, sc.sort_order,
               c.name AS category_name
        FROM sub_category sc
        LEFT JOIN category c ON c.id = sc.category_id
        WHERE sc.status = 'active'
        ORDER BY sc.sort_order ASC, sc.id ASC
    ");
}

$stmt->execute();
$result = $stmt->get_result();

$subCategories = [];
while ($row = $result->fetch_assoc()) {
    $subCategories[] = [
        'id'            => (int)$row['id'],
        'category_id'   => (int)$row['category_id'],
        'category_name' => $row['category_name'] ?? '',
        'name'          => $row['name'],
        'description'   => $row['description'] ?? '',
        'image'         => $row['image'] ?? '',
        'sort_order'    => (int)$row['sort_order'],
    ];
}
$stmt->close();

jsonResponse([
    'status'          => 'success',
    'message'         => 'Sub-categories fetched successfully',
    'total'           => count($subCategories),
    'sub_categories'  => $subCategories,
]);
<?php
/**
 * ADVERTISEMENTS API
 * 
 * POST /api/customer/advertisements.php
 * Body: { customer_id (optional) }
 * 
 * Saari active advertisements return karta hai.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'config.php';
require 'db.php';

$input      = getInput();
$customerId = (int)($input['customer_id'] ?? 0);

// ─── Advertisements lein ───
$stmt = $db->prepare("
    SELECT id, title, description, image_url, 
           discount_percent, 
           coupon_prefix, valid_from, valid_to, sort_order
    FROM advertisements 
    WHERE status = 'active' 
      AND valid_to > NOW()
    ORDER BY sort_order ASC, id ASC
");
$stmt->execute();
$result = $stmt->get_result();

$advertisements = [];
while ($row = $result->fetch_assoc()) {
    $advertisements[] = [
        'id'               => (int)$row['id'],
        'title'            => $row['title'],
        'description'      => $row['description'] ?? '',
        'image_url'        => $row['image_url'],
        'discount_percent' => (float)$row['discount_percent'],
        'coupon_prefix'    => $row['coupon_prefix'],
        'valid_to'         => $row['valid_to'],
        'date_formatted'   => date('d M Y', strtotime($row['valid_to'])),
    ];
}
$stmt->close();

jsonResponse([
    'status'         => 'success',
    'message'        => 'Advertisements fetched successfully',
    'total'          => count($advertisements),
    'advertisements' => $advertisements,
]);
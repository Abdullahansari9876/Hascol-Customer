<?php
/**
 * GET LUBE PRODUCTS API
 * 
 * POST /api/products/get-lube-products.php
 * Body: { sub_category_id (optional) }
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

$input         = getInput();
$subCategoryId = (int)($input['sub_category_id'] ?? 0);

// ─── Products query ───
if ($subCategoryId > 0) {
    $stmt = $db->prepare("
        SELECT lp.id, lp.sub_category_id, lp.category_id, lp.sku, 
               lp.name, lp.brand, lp.description, lp.image, 
               lp.price, lp.discount_percent, lp.stock, lp.status,
               sc.name AS sub_category_name,
               c.name AS category_name
        FROM lube_products lp
        LEFT JOIN sub_category sc ON sc.id = lp.sub_category_id
        LEFT JOIN category c ON c.id = lp.category_id
        WHERE lp.status = 'active' AND lp.sub_category_id = ?
        ORDER BY lp.id DESC
    ");
    $stmt->bind_param("i", $subCategoryId);
} else {
    $stmt = $db->prepare("
        SELECT lp.id, lp.sub_category_id, lp.category_id, lp.sku, 
               lp.name, lp.brand, lp.description, lp.image, 
               lp.price, lp.discount_percent, lp.stock, lp.status,
               sc.name AS sub_category_name,
               c.name AS category_name
        FROM lube_products lp
        LEFT JOIN sub_category sc ON sc.id = lp.sub_category_id
        LEFT JOIN category c ON c.id = lp.category_id
        WHERE lp.status = 'active'
        ORDER BY lp.id DESC
    ");
}

$stmt->execute();
$result = $stmt->get_result();

$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = [
        'id'                => (int)$row['id'],
        'sku'               => $row['sku'] ?? '',
        'sub_category_id'   => (int)$row['sub_category_id'],
        'sub_category_name' => $row['sub_category_name'] ?? '',
        'category_id'       => (int)$row['category_id'],         
        'category_name'     => $row['category_name'] ?? '',      
        'name'              => $row['name'],
        'brand'             => $row['brand'] ?? '',
        'description'       => $row['description'] ?? '',
        'image'             => $row['image'] ?? '',
        'price'             => (float)$row['price'],
        'discount_percent'  => (float)$row['discount_percent'],
        'stock'             => (int)$row['stock'],
    ];
}
$stmt->close();

jsonResponse([
    'status'   => 'success',
    'message'  => 'Products fetched successfully',
    'total'    => count($products),
    'products' => $products,
]);
<?php
/**
 * LIST DEALER DISCOUNTS API
 * 
 * GET /api/dealer/list-discounts.php
 * 
 * Optional Filters:
 *   - dealer_id    (specific dealer's discounts)
 *   - status       (active/inactive/expired)
 *   - active_only  (1 = only currently valid discounts)
 *   - limit        (default 50)
 *   - offset       (default 0)
 */

// ─── CORS / OPTIONS ───
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

// ─── Sirf GET allow ───
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    jsonResponse(['success' => false, 'message' => 'Only GET method allowed']);
}

// ─── Filters ───
$dealerId   = isset($_GET['dealer_id']) && $_GET['dealer_id'] !== '' ? (int)$_GET['dealer_id'] : null;
$status     = trim($_GET['status'] ?? '');
$activeOnly = (int)($_GET['active_only'] ?? 0);
$limit      = (int)($_GET['limit'] ?? 50);
$offset     = (int)($_GET['offset'] ?? 0);

if ($limit <= 0 || $limit > 200) $limit = 50;
if ($offset < 0) $offset = 0;

// ─── Build WHERE ───
$where  = [];
$params = [];
$types  = '';

if ($dealerId !== null) {
    $where[]  = "d.dealer_id = ?";
    $params[] = $dealerId;
    $types   .= 'i';
}

if ($status !== '' && in_array($status, ['active', 'inactive', 'expired'])) {
    $where[]  = "d.status = ?";
    $params[] = $status;
    $types   .= 's';
}

if ($activeOnly === 1) {
    $where[] = "d.status = 'active'";
    $where[] = "d.start_date <= CURDATE()";
    $where[] = "d.end_date >= CURDATE()";
    $where[] = "(d.total_times = 0 OR d.used_count < d.total_times)";
}

$whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// ─── Total count ───
$countSql = "SELECT COUNT(*) as total FROM dealer_discounts d $whereSql";
$stmt = $db->prepare($countSql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// ─── Fetch list ───
$sql = "
    SELECT 
        d.*,
        dl.name as dealer_name,
        dl.station_name,
        dl.city as dealer_city
    FROM dealer_discounts d
    LEFT JOIN hascol_dealers dl ON dl.id = d.dealer_id
    $whereSql
    ORDER BY d.id DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;
$types   .= "ii";

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$discounts = [];
while ($row = $result->fetch_assoc()) {

    // ─── Products fetch karo (agar specific_products hain) ───
    $products = [];

    if (!empty($row['product_ids']) && $row['apply_to'] === 'specific_products') {

        // Product IDs ko array mein todo
        $productIdArray = array_filter(
            array_map('intval', explode(',', $row['product_ids'])),
            function($id) { return $id > 0; }
        );

        if (!empty($productIdArray)) {
            // Placeholders banao: ?, ?, ?
            $placeholders = implode(',', array_fill(0, count($productIdArray), '?'));
            $productTypes = str_repeat('i', count($productIdArray));

            // ✅ SAHI TABLE: lube_products
            $prodSql = "
                SELECT 
                    id,
                    name,
                    brand,
                    sku,
                    description,
                    image,
                    price,
                    discount_percent,
                    stock,
                    status
                FROM lube_products
                WHERE id IN ($placeholders)
            ";

            $prodStmt = $db->prepare($prodSql);
            if ($prodStmt) {
                $prodStmt->bind_param($productTypes, ...$productIdArray);
                $prodStmt->execute();
                $prodResult = $prodStmt->get_result();

                while ($p = $prodResult->fetch_assoc()) {
                    // Image ka full URL banao
                    $baseUrl = defined('BASE_URL') 
                        ? BASE_URL 
                        : 'https://hascol.allowance.flamboyant-spence.92-205-119-218.plesk.page/';
                    
                    $imageUrl = !empty($p['image'])
                        ? rtrim($baseUrl, '/') . '/' . ltrim($p['image'], '/')
                        : '';

                    $products[] = [
                        'product_id'       => (int)$p['id'],
                        'product_name'     => $p['name'] ?? '',
                        'product_brand'    => $p['brand'] ?? '',
                        'product_sku'      => $p['sku'] ?? '',
                        'product_image'    => $imageUrl,
                        'description'      => $p['description'] ?? '',
                        'price'            => (float)$p['price'],
                        'discount_percent' => (float)$p['discount_percent'],
                        'stock'            => (int)$p['stock'],
                        'status'           => $p['status'],
                    ];
                }
                $prodStmt->close();
            }
        }
    }

    $discounts[] = [
        'discount_id'        => (int)$row['id'],
        'dealer_id'          => (int)$row['dealer_id'],
        'dealer_name'        => $row['dealer_name'],
        'station_name'       => $row['station_name'],
        'dealer_city'        => $row['dealer_city'],
        'discount_type'      => $row['discount_type'],
        'discount_value'     => (float)$row['discount_value'],
        'apply_to'           => $row['apply_to'],
        'product_ids'        => $row['product_ids'],
        'products'           => $products,   // ← Products details
        'start_date'         => $row['start_date'],
        'end_date'           => $row['end_date'],
        'times_per_customer' => (int)$row['times_per_customer'],
        'total_times'        => (int)$row['total_times'],
        'used_count'         => (int)$row['used_count'],
        'min_order_value'    => (float)$row['min_order_value'],
        'status'             => $row['status'],
        'created_at'         => $row['created_at'],
        'updated_at'         => $row['updated_at'],
    ];
}
$stmt->close();

http_response_code(200);
jsonResponse([
    'success'   => true,
    'message'   => 'Discounts fetched successfully',
    'total'     => $total,
    'limit'     => $limit,
    'offset'    => $offset,
    'discounts' => $discounts,
]);
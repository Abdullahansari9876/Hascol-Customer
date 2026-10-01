<?php
/**
 * CUSTOMER - LIST ALL DEALERS WITH DISCOUNTS API
 * 
 * GET /api/customers/list-dealer-discounts.php
 * 
 * Purpose: Customer App mein advertising section ke liye
 * 
 * ⚠️ Sirf active dealers aur active discounts show honge
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

// ─── Base URL ───
$baseUrl = 'https://hascol.allowance.flamboyant-spence.92-205-119-218.plesk.page/';
$baseUrl = rtrim($baseUrl, '/') . '/';

// ─── Default Banners ───
$defaultBanners = [
    'uploads/advertisements/banner1.png',
    'uploads/advertisements/banner2.png',
    'uploads/advertisements/banner3.png',
];

// ─── Filters ───
$city         = trim($_GET['city'] ?? '');
$discountType = trim($_GET['discount_type'] ?? '');
$limit        = (int)($_GET['limit'] ?? 50);
$offset       = (int)($_GET['offset'] ?? 0);

if ($limit <= 0 || $limit > 200) $limit = 50;
if ($offset < 0) $offset = 0;

// ═══════════════════════════════════════════════
// BUILD WHERE - Sirf active dealers + active discounts
// ═══════════════════════════════════════════════
$where  = [];
$params = [];
$types  = '';

// ✅ Dealer active hona chahiye
$where[] = "dl.status = 'active'";

// ✅ Discount active hona chahiye
$where[] = "d.status = 'active'";

// ✅ Discount currently valid hona chahiye (start aur end date ke beech)
$where[] = "d.start_date <= CURDATE()";
$where[] = "d.end_date >= CURDATE()";

// ✅ Usage limit check - agar total_times 0 nahi hai to used_count kam hona chahiye
$where[] = "(d.total_times = 0 OR d.used_count < d.total_times)";

// City filter (optional)
if ($city !== '') {
    $where[]  = "dl.city = ?";
    $params[] = $city;
    $types   .= 's';
}

// Discount type filter (optional)
if (in_array($discountType, ['flat', 'percent'])) {
    $where[]  = "d.discount_type = ?";
    $params[] = $discountType;
    $types   .= 's';
}

$whereSql = "WHERE " . implode(" AND ", $where);

// ─── Total count ───
$countSql = "
    SELECT COUNT(DISTINCT dl.id) as total 
    FROM dealer_discounts d
    INNER JOIN hascol_dealers dl ON dl.id = d.dealer_id
    $whereSql
";
$stmt = $db->prepare($countSql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// ─── Fetch dealers with discounts ───
$sql = "
    SELECT 
        dl.id as dealer_id,
        dl.name as dealer_name,
        dl.mobile as dealer_mobile,
        dl.email as dealer_email,
        dl.station_name,
        dl.address,
        dl.city,
        dl.latitude,
        dl.longitude,
        d.id as discount_id,
        d.discount_type,
        d.discount_value,
        d.apply_to,
        d.product_ids,
        d.logo,
        d.start_date,
        d.end_date,
        d.times_per_customer,
        d.total_times,
        d.used_count,
        d.min_order_value,
        d.status as discount_status
    FROM dealer_discounts d
    INNER JOIN hascol_dealers dl ON dl.id = d.dealer_id
    $whereSql
    ORDER BY dl.id ASC, d.id DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;
$types   .= "ii";

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$dealerMap = [];

while ($row = $result->fetch_assoc()) {
    $dealerId = (int)$row['dealer_id'];

    if (!isset($dealerMap[$dealerId])) {
        $dealerMap[$dealerId] = [
            'dealer_id'     => $dealerId,
            'dealer_name'   => $row['dealer_name'],
            'dealer_mobile' => $row['dealer_mobile'],
            'dealer_email'  => $row['dealer_email'] ?? '',
            'station_name'  => $row['station_name'],
            'address'       => $row['address'] ?? '',
            'city'          => $row['city'] ?? '',
            'latitude'      => $row['latitude'],
            'longitude'     => $row['longitude'],
            'discounts'     => [],
        ];
    }

    // ─── Logo Ka Poora URL ───
    $logoUrl = '';
    
    if (!empty($row['logo'])) {
        $logoUrl = $baseUrl . ltrim($row['logo'], '/');
    } else {
        $bannerIndex = $row['discount_id'] % 3;
        $logoUrl = $baseUrl . $defaultBanners[$bannerIndex];
    }

    // ─── Products Fetch ───
    $products = [];
    if (!empty($row['product_ids']) && $row['apply_to'] === 'specific_products') {
        $productIdArray = array_filter(
            array_map('intval', explode(',', $row['product_ids'])),
            function($id) { return $id > 0; }
        );

        if (!empty($productIdArray)) {
            $placeholders = implode(',', array_fill(0, count($productIdArray), '?'));
            $productTypes = str_repeat('i', count($productIdArray));

            $prodSql = "
                SELECT id, name, brand, sku, price
                FROM lube_products
                WHERE id IN ($placeholders) AND status = 'active'
            ";
            $prodStmt = $db->prepare($prodSql);
            if ($prodStmt) {
                $prodStmt->bind_param($productTypes, ...$productIdArray);
                $prodStmt->execute();
                $prodResult = $prodStmt->get_result();

                while ($p = $prodResult->fetch_assoc()) {
                    $products[] = [
                        'product_id'    => (int)$p['id'],
                        'product_name'  => $p['name'],
                        'product_brand' => $p['brand'] ?? '',
                        'product_sku'   => $p['sku'] ?? '',
                        'price'         => (float)$p['price'],
                    ];
                }
                $prodStmt->close();
            }
        }
    }

    // ─── Discount Add ───
    $dealerMap[$dealerId]['discounts'][] = [
        'discount_id'        => (int)$row['discount_id'],
        'discount_type'      => $row['discount_type'],
        'discount_value'     => (float)$row['discount_value'],
        'apply_to'           => $row['apply_to'],
        'product_ids'        => $row['product_ids'],
        'logo'               => $logoUrl,
        'products'           => $products,
        'start_date'         => $row['start_date'],
        'end_date'           => $row['end_date'],
        'times_per_customer' => (int)$row['times_per_customer'],
        'total_times'        => (int)$row['total_times'],
        'used_count'         => (int)$row['used_count'],
        'min_order_value'    => (float)$row['min_order_value'],
        'status'             => $row['discount_status'],
    ];
}
$stmt->close();

$dealers = array_values($dealerMap);

http_response_code(200);
jsonResponse([
    'success' => true,
    'message' => 'Dealers with discounts fetched successfully',
    'total'   => count($dealers),
    'limit'   => $limit,
    'offset'  => $offset,
    'dealers' => $dealers,
]);
<?php

// ─── CORS ───
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");

// Production me errors screen par nahi dikhane
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require '../config.php';
require '../db.php';

// ─── Sirf POST allow ───
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(['success' => false, 'message' => 'Only POST method allowed']);
}

// ─── Body Read (JSON ya form-data dono) ───
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        jsonResponse(['success' => false, 'message' => 'Invalid JSON body']);
    }
} else {
    $input = $_POST;
}

// ─── Input Values ───
$latitude  = (isset($input['latitude'])  && $input['latitude']  !== '') ? (float)$input['latitude']  : null;
$longitude = (isset($input['longitude']) && $input['longitude'] !== '') ? (float)$input['longitude'] : null;
$radius    = (float)($input['radius'] ?? 10);
$limit     = (int)($input['limit'] ?? 50);

if ($limit <= 0 || $limit > 200) $limit = 50;
if ($radius <= 0)   $radius = 10;
if ($radius > 100)  $radius = 100;

// ─── Validation ───
if ($latitude === null || $longitude === null) {
    http_response_code(400);
    jsonResponse(['success' => false, 'message' => 'latitude and longitude are required']);
}
if ($latitude < -90 || $latitude > 90) {
    http_response_code(400);
    jsonResponse(['success' => false, 'message' => 'Invalid latitude (-90 to 90)']);
}
if ($longitude < -180 || $longitude > 180) {
    http_response_code(400);
    jsonResponse(['success' => false, 'message' => 'Invalid longitude (-180 to 180)']);
}

// ─── Base URL ───
$baseUrl = defined('BASE_URL')
    ? BASE_URL
    : 'https://hascol.allowance.flamboyant-spence.92-205-119-218.plesk.page/';
$baseUrl = rtrim($baseUrl, '/');

// ─── Main Query (Haversine) ───
// Distance ko subquery me calculate karke bahar filter karte hain (code clean rehta hai)
$sql = "
    SELECT * FROM (
        SELECT
            dl.id AS dealer_id,
            dl.name AS dealer_name,
            dl.mobile AS dealer_mobile,
            dl.email AS dealer_email,
            dl.station_name,
            dl.address,
            dl.city,
            dl.latitude,
            dl.longitude,
            dl.profile_img,
            (
                6371 * ACOS(
                    LEAST(1, GREATEST(-1,
                        COS(RADIANS(?)) * COS(RADIANS(dl.latitude)) *
                        COS(RADIANS(dl.longitude) - RADIANS(?)) +
                        SIN(RADIANS(?)) * SIN(RADIANS(dl.latitude))
                    ))
                )
            ) AS distance_km,
            d.id AS discount_id,
            d.discount_type,
            d.discount_value,
            d.apply_to,
            d.product_ids,
            d.start_date,
            d.end_date,
            d.times_per_customer,
            d.total_times,
            d.used_count,
            d.min_order_value,
            d.status AS discount_status
        FROM hascol_dealers dl
        INNER JOIN dealer_discounts d ON d.dealer_id = dl.id
        WHERE
            dl.latitude IS NOT NULL
            AND dl.longitude IS NOT NULL
            AND dl.status = 'active'
            AND d.status = 'active'
            AND d.start_date <= CURDATE()
            AND d.end_date >= CURDATE()
            AND (d.total_times = 0 OR d.used_count < d.total_times)
    ) t
    WHERE t.distance_km <= ?
    ORDER BY t.distance_km ASC, t.discount_id DESC
    LIMIT ?
";

$stmt = $db->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    jsonResponse(['success' => false, 'message' => 'Database error']);
}

// 5 placeholders: lat, lng, lat, radius, limit
$stmt->bind_param("ddddi", $latitude, $longitude, $latitude, $radius, $limit);
$stmt->execute();
$result = $stmt->get_result();

// ─── Rows collect + saare product IDs jama karo ───
$rows = [];
$allProductIds = [];

while ($row = $result->fetch_assoc()) {
    $rows[] = $row;

    if (!empty($row['product_ids']) && $row['apply_to'] === 'specific_products') {
        foreach (explode(',', $row['product_ids']) as $pid) {
            $pid = (int)trim($pid);
            if ($pid > 0) $allProductIds[$pid] = true;
        }
    }
}
$stmt->close();

// ─── Products ek hi query me fetch (N+1 problem fix) ───
$productMap = [];
if (!empty($allProductIds)) {
    $ids = array_keys($allProductIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $prodStmt = $db->prepare("
        SELECT id, name, brand, sku, image, price
        FROM lube_products
        WHERE id IN ($placeholders) AND status = 'active'
    ");

    if ($prodStmt) {
        $prodStmt->bind_param($types, ...$ids);
        $prodStmt->execute();
        $prodResult = $prodStmt->get_result();

        while ($p = $prodResult->fetch_assoc()) {
            $productMap[(int)$p['id']] = [
                'product_id'    => (int)$p['id'],
                'product_name'  => $p['name'],
                'product_brand' => $p['brand'] ?? '',
                'product_sku'   => $p['sku'] ?? '',
                'product_image' => !empty($p['image'])
                    ? $baseUrl . '/' . ltrim($p['image'], '/')
                    : '',
                'price'         => (float)$p['price'],
            ];
        }
        $prodStmt->close();
    }
}

// ─── Helper: discount label ───
function discountLabel($type, $value) {
    $v = rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    if ($type === 'percentage') return $v . '% OFF';
    return 'Rs ' . $v . ' OFF';
}

// ─── Dealer wise group ───
$dealerMap = [];

foreach ($rows as $row) {
    $dealerId = (int)$row['dealer_id'];

    if (!isset($dealerMap[$dealerId])) {
        $profileImg = !empty($row['profile_img'])
            ? $baseUrl . '/' . ltrim($row['profile_img'], '/')
            : '';

        $dealerMap[$dealerId] = [
            'dealer_id'     => $dealerId,
            'dealer_name'   => $row['dealer_name'],
            'dealer_mobile' => $row['dealer_mobile'],
            'dealer_email'  => $row['dealer_email'] ?? '',
            'station_name'  => $row['station_name'],
            'address'       => $row['address'] ?? '',
            'city'          => $row['city'] ?? '',
            'latitude'      => (float)$row['latitude'],
            'longitude'     => (float)$row['longitude'],
            'profile_img'   => $profileImg,
            'distance_km'   => round((float)$row['distance_km'], 2),
            'best_discount_label' => '',
            'discounts'     => [],
            '_best_value'   => -1,
        ];
    }

    // Is discount ke products
    $products = [];
    if (!empty($row['product_ids']) && $row['apply_to'] === 'specific_products') {
        foreach (explode(',', $row['product_ids']) as $pid) {
            $pid = (int)trim($pid);
            if ($pid > 0 && isset($productMap[$pid])) {
                $products[] = $productMap[$pid];
            }
        }
    }

    $label = discountLabel($row['discount_type'], $row['discount_value']);

    $dealerMap[$dealerId]['discounts'][] = [
        'discount_id'        => (int)$row['discount_id'],
        'discount_type'      => $row['discount_type'],
        'discount_value'     => (float)$row['discount_value'],
        'discount_label'     => $label,
        'apply_to'           => $row['apply_to'],
        'product_ids'        => $row['product_ids'],
        'products'           => $products,
        'start_date'         => $row['start_date'],
        'end_date'           => $row['end_date'],
        'times_per_customer' => (int)$row['times_per_customer'],
        'total_times'        => (int)$row['total_times'],
        'used_count'         => (int)$row['used_count'],
        'min_order_value'    => (float)$row['min_order_value'],
        'status'             => $row['discount_status'],
    ];

    // Marker par dikhane ke liye sabse bada discount
    if ((float)$row['discount_value'] > $dealerMap[$dealerId]['_best_value']) {
        $dealerMap[$dealerId]['_best_value'] = (float)$row['discount_value'];
        $dealerMap[$dealerId]['best_discount_label'] = $label;
    }
}

// ─── Final dealers + Google Map marker data ───
$dealers = [];
foreach ($dealerMap as $d) {
    unset($d['_best_value']);
    $d['total_discounts'] = count($d['discounts']);
    $d['marker'] = [
        'lat'      => $d['latitude'],
        'lng'      => $d['longitude'],
        'title'    => $d['station_name'] ?: $d['dealer_name'],
        'snippet'  => $d['best_discount_label'] . ' • ' . $d['distance_km'] . ' km',
    ];
    $dealers[] = $d;
}

http_response_code(200);
jsonResponse([
    'success'       => true,
    'message'       => 'Nearby dealers with discounts fetched successfully',
    'customer_lat'  => $latitude,
    'customer_lng'  => $longitude,
    'radius_km'     => $radius,
    'radius_meters' => $radius * 1000,
    'map_center'    => ['lat' => $latitude, 'lng' => $longitude],
    'total'         => count($dealers),
    'dealers'       => $dealers,
]);
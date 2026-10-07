<?php
/**
 * dealer_details.php  (Core PHP - single file API)
 *
 * 1) NEARBY DEALERS (for Google Map markers)
 *    GET/POST  dealer_details.php?lat=24.8607&lng=67.0011
 *
 * 2) DEALER DETAIL (when a marker is tapped)
 *    GET/POST  dealer_details.php?dealer_id=5&lat=24.8607&lng=67.0011
 *
 * lat / lng  = customer's current location
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config.php';
require '../db.php';

// NOTE: $db (mysqli connection) comes from db.php and is used below

// Live base URL (used for discount logo and product images)
$BASE_URL = 'https://hascol.allowance.flamboyant-spence.92-205-119-218.plesk.page/';

// ======================================================
// 2) HELPER FUNCTIONS
// ======================================================
function respond($success, $message, $data = null, $code = 200)
{
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fetchAll($sql)
{
    global $db;   // mysqli connection from db.php

    $res = $db->query($sql);
    if ($res === false) {
        respond(false, 'Database query error', null, 500);
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $res->free();
    return $rows;
}

function num($n)
{
    // Format a float safely for SQL (no scientific notation)
    return number_format((float)$n, 8, '.', '');
}

function fullUrl($path)
{
    global $BASE_URL;
    if (!$path) return null;
    if (preg_match('#^https?://#i', $path)) return $path;
    return $BASE_URL . ltrim($path, '/');
}

// ======================================================
// 3) INPUT + VALIDATION
// ======================================================
if (!isset($db) || !($db instanceof mysqli)) {
    respond(false, 'Database connection not found. Please check db.php.', null, 500);
}

$input = array_merge($_GET, $_POST);
$raw = file_get_contents('php://input');
if ($raw) {
    $json = json_decode($raw, true);
    if (is_array($json)) $input = array_merge($input, $json);
}

$lat = $input['lat'] ?? $input['latitude'] ?? null;
$lng = $input['lng'] ?? $input['longitude'] ?? null;

if (!is_numeric($lat) || !is_numeric($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    respond(false, 'Valid lat and lng are required', null, 422);
}
$lat = (float)$lat;
$lng = (float)$lng;

// Radius and limit are not sent by the app; the API decides them
$radiusSteps = [10, 15];   // try 10 KM first, then 15 KM if no dealer is found
$limit       = 50;         // max dealers returned for the map
$dealerId    = isset($input['dealer_id']) ? (int)$input['dealer_id'] : 0;

// Haversine formula (distance in KM) - calculated inside MySQL
$latS = num($lat);
$lngS = num($lng);
$distanceSql = "(6371 * ACOS(LEAST(1, GREATEST(-1,
        COS(RADIANS($latS)) * COS(RADIANS(latitude)) * COS(RADIANS(longitude) - RADIANS($lngS))
      + SIN(RADIANS($latS)) * SIN(RADIANS(latitude))
    ))))";

$columns = "id, name, mobile, email, station_name, address, city, latitude, longitude, status";

// ======================================================
// 4) DISCOUNTS + their PRODUCTS (only active + valid date + usage left)
// ======================================================
function getDiscounts(array $ids)
{
    if (empty($ids)) return [];
    $ids = implode(',', array_map('intval', $ids));

    $rows = fetchAll("SELECT id, dealer_id, discount_type, discount_value, apply_to, product_ids,
                             logo, start_date, end_date, times_per_customer, min_order_value
                      FROM dealer_discounts
                      WHERE dealer_id IN ($ids)
                        AND status = 'active'
                        AND CURDATE() BETWEEN start_date AND end_date
                        AND used_count < total_times
                      ORDER BY discount_value DESC");

    // ---- Collect product ids of all discounts (fetched in a single query) ----
    $allProductIds = [];
    foreach ($rows as $r) {
        if ($r['apply_to'] === 'specific_products' && $r['product_ids']) {
            foreach (explode(',', $r['product_ids']) as $pid) {
                $pid = (int)trim($pid);
                if ($pid > 0) $allProductIds[$pid] = $pid;
            }
        }
    }

    // ---- Product details from lube_products table ----
    $productMap = [];
    if (!empty($allProductIds)) {
        $pidList = implode(',', $allProductIds);
        $prodRows = fetchAll("SELECT id, category_id, sub_category_id, name, brand, sku,
                                     description, image, price, discount_percent, stock
                              FROM lube_products
                              WHERE id IN ($pidList)
                                AND status = 'active'");
        foreach ($prodRows as $p) {
            $productMap[(int)$p['id']] = [
                'id'               => (int)$p['id'],
                'category_id'      => $p['category_id'] !== null ? (int)$p['category_id'] : null,
                'sub_category_id'  => (int)$p['sub_category_id'],
                'name'             => $p['name'],
                'brand'            => $p['brand'],
                'sku'              => $p['sku'],
                'description'      => $p['description'],
                'image'            => fullUrl($p['image']),
                'price'            => (float)$p['price'],
                'discount_percent' => (float)$p['discount_percent'],
                'stock'            => (int)$p['stock'],
            ];
        }
    }

    $grouped = [];
    foreach ($rows as $r) {
        $productIds = $r['product_ids']
            ? array_values(array_filter(array_map('trim', explode(',', $r['product_ids']))))
            : [];

        // Products of this discount (in product_ids order)
        $products = [];
        if ($r['apply_to'] === 'specific_products') {
            foreach ($productIds as $pid) {
                if (isset($productMap[(int)$pid])) {
                    $products[] = $productMap[(int)$pid];
                }
            }
        }

        $grouped[(int)$r['dealer_id']][] = [
            'id'                 => (int)$r['id'],
            'discount_type'      => $r['discount_type'],        // flat | percent
            'discount_value'     => (float)$r['discount_value'],
            'apply_to'           => $r['apply_to'],             // entire_order | specific_products
            'product_ids'        => $productIds,
            'products'           => $products,                  // full product JSON (name, price, image...)
            'logo'               => fullUrl($r['logo']),
            'start_date'         => $r['start_date'],
            'end_date'           => $r['end_date'],
            'times_per_customer' => (int)$r['times_per_customer'],
            'min_order_value'    => (float)$r['min_order_value'],
        ];
    }
    return $grouped;
}

function formatDealer(array $r, array $discounts)
{
    global $lat, $lng;
    $dLat = (float)$r['latitude'];
    $dLng = (float)$r['longitude'];
    $km   = (float)$r['distance_km'];

    return [
        'id'             => (int)$r['id'],
        'name'           => $r['name'],
        'station_name'   => $r['station_name'],
        'mobile'         => $r['mobile'],
        'email'          => $r['email'],
        'address'        => $r['address'],
        'city'           => $r['city'],
        'latitude'       => $dLat,
        'longitude'      => $dLng,
        'distance_km'    => round($km, 2),
        'eta_minutes'    => (int)ceil(($km / 30) * 60),   // approx 30 km/h average speed
        'status'         => $r['status'],
        'has_discount'   => !empty($discounts),
        'discounts'      => $discounts,
        // Opens route/navigation in Google Maps
        'directions_url' => 'https://www.google.com/maps/dir/?api=1&origin=' . $lat . ',' . $lng
                          . '&destination=' . $dLat . ',' . $dLng . '&travelmode=driving',
    ];
}

// ======================================================
// 5A) SINGLE DEALER DETAIL  (when dealer_id is provided)
// ======================================================
if ($dealerId > 0) {
    $rows = fetchAll("SELECT $columns, $distanceSql AS distance_km
                      FROM hascol_dealers
                      WHERE id = $dealerId
                        AND status = 'active'
                        AND latitude IS NOT NULL AND longitude IS NOT NULL
                      LIMIT 1");

    if (empty($rows)) {
        respond(false, 'Dealer not found or inactive', null, 404);
    }

    $discounts = getDiscounts([$dealerId]);
    respond(true, 'Dealer details', [
        'customer_location' => ['latitude' => $lat, 'longitude' => $lng],
        'dealer'            => formatDealer($rows[0], $discounts[$dealerId] ?? []),
    ]);
}

// ======================================================
// 5B) NEARBY DEALERS LIST  (within radius, nearest first)
// ======================================================
$rows = [];
$radius = $radiusSteps[0];

foreach ($radiusSteps as $radius) {
    // Bounding box: rough filter first so the query stays fast
    $latDelta = $radius / 111.0;
    $cos      = cos(deg2rad($lat));
    $lngDelta = $radius / (111.0 * max(abs($cos), 0.01));

    $minLat  = num($lat - $latDelta);
    $maxLat  = num($lat + $latDelta);
    $minLng  = num($lng - $lngDelta);
    $maxLng  = num($lng + $lngDelta);
    $radiusS = num($radius);

    $rows = fetchAll("SELECT $columns, $distanceSql AS distance_km
                      FROM hascol_dealers
                      WHERE status = 'active'
                        AND latitude IS NOT NULL AND longitude IS NOT NULL
                        AND latitude  BETWEEN $minLat AND $maxLat
                        AND longitude BETWEEN $minLng AND $maxLng
                      HAVING distance_km <= $radiusS
                      ORDER BY distance_km ASC
                      LIMIT $limit");

    if (!empty($rows)) break;   // dealers found, no need to widen the radius
}

$ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
$discounts = getDiscounts($ids);

$dealers = [];
foreach ($rows as $r) {
    $dealers[] = formatDealer($r, $discounts[(int)$r['id']] ?? []);
}

respond(true, count($dealers) ? 'Nearby dealers found' : 'No dealers found within this radius', [
    'customer_location' => ['latitude' => $lat, 'longitude' => $lng],
    'radius_km'         => $radius,   // radius that the API used
    'total'             => count($dealers),
    'dealers'           => $dealers,
]);
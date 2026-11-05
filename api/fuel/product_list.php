<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require '../config.php';
require '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    $db = getDBConnection();

    // dealer_id query parameter se lo
    if (empty($_GET['dealer_id'])) {
        throw new Exception('dealer_id is required');
    }

    $dealer_id = (int)$_GET['dealer_id'];

    // Dealer exist karta hai?
    $dealerSql = "SELECT id, station_name FROM hascol_dealers WHERE id = $dealer_id LIMIT 1";
    $dealerResult = $db->query($dealerSql);
    if (!$dealerResult || $dealerResult->num_rows === 0) {
        throw new Exception('Dealer not found');
    }

    // Dealer ke rates + product master join
    $sql = "SELECT 
                fp.id,
                fp.name,
                fp.unit,
                fr.rate,
                fr.updated_at
            FROM fuel_rates fr
            INNER JOIN fuel_products fp ON fp.id = fr.product_id
            WHERE fr.dealer_id = $dealer_id
              AND fr.is_active = 1
              AND fp.is_active = 1
            ORDER BY fp.name ASC";

    $result = $db->query($sql);
    if (!$result) {
        throw new Exception('Query failed: ' . $db->error);
    }

    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = [
            'id'         => (int)$row['id'],
            'name'       => $row['name'],
            'rate'       => (float)$row['rate'],
            'unit'       => $row['unit'],
            'updated_at' => $row['updated_at']
        ];
    }

    echo json_encode([
        'status'    => true,
        'message'   => 'Fuel products fetched successfully',
        'dealer_id' => $dealer_id,
        'count'     => count($products),
        'data'      => $products
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => $e->getMessage()]);
}
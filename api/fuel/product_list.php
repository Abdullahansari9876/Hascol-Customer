<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require '../config.php';
require '../db.php';

// Sirf GET allow
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'status'  => false,
        'message' => 'Method not allowed. Use GET.'
    ]);
    exit;
}

try {
    $db = getDBConnection();

    $sql = "SELECT id, name, rate, unit, created_at 
            FROM fuel_products 
            WHERE is_active = 1 
            ORDER BY name ASC";

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
            'created_at' => $row['created_at']
        ];
    }

    echo json_encode([
        'status'  => true,
        'message' => 'Fuel products fetched successfully',
        'count'   => count($products),
        'data'    => $products
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => false,
        'message' => 'Something went wrong',
        'error'   => $e->getMessage()
    ]);
}
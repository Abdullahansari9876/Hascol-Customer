<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require '../config.php';
require '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

try {
    $db = getDBConnection();

    // 1. INPUT
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!$input || !is_array($input)) {
        throw new Exception('Invalid JSON input');
    }

    // 2. VALIDATION (sirf imei, player_id nahi)
    $required = ['customer_id', 'dealer_id', 'imei', 'product_id', 'quantity'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("$field is required");
        }
    }

    $customer_id = (int) $input['customer_id'];
    $dealer_id = (int) $input['dealer_id'];
    $imei = $db->real_escape_string($input['imei']);
    $product_id = (int) $input['product_id'];
    $quantity = (float) $input['quantity'];

    if ($quantity <= 0) {
        throw new Exception('Quantity must be greater than 0');
    }

    // 3. PRODUCT
    $productSql = "SELECT 
                  fp.id, fp.name, fp.unit, fr.rate
               FROM fuel_products fp
               INNER JOIN fuel_rates fr ON fr.product_id = fp.id
               WHERE fp.id = $product_id 
                 AND fr.dealer_id = $dealer_id
                 AND fp.is_active = 1
                 AND fr.is_active = 1
               LIMIT 1";
    $productResult = $db->query($productSql);
    if (!$productResult || $productResult->num_rows === 0) {
        throw new Exception('Invalid or inactive fuel product');
    }
    $product = $productResult->fetch_assoc();
    $rate = (float) $product['rate'];

    // 4. CUSTOMER
    $custSql = "SELECT id, name FROM hascol_customer WHERE id = $customer_id LIMIT 1";
    $custResult = $db->query($custSql);
    if (!$custResult || $custResult->num_rows === 0) {
        throw new Exception('Customer not found');
    }
    $customer = $custResult->fetch_assoc();
    $customer_name = $db->real_escape_string($customer['name']);

    // 5. DEALER
    $dealerSql = "SELECT id, station_name FROM hascol_dealers WHERE id = $dealer_id LIMIT 1";
    $dealerResult = $db->query($dealerSql);
    if (!$dealerResult || $dealerResult->num_rows === 0) {
        throw new Exception('Dealer not found');
    }
    $dealer = $dealerResult->fetch_assoc();
    $station_name = $db->real_escape_string($dealer['station_name']);

    // 5b. IMEI VALIDATION (sirf imei check hoga)
    $imeiSql = "SELECT id FROM hascol_customer 
                WHERE id = $customer_id AND imei = '$imei' 
                LIMIT 1";
    $imeiResult = $db->query($imeiSql);
    if (!$imeiResult || $imeiResult->num_rows === 0) {
        throw new Exception('IMEI does not match with customer.');
    }

    // 6. CALCULATION
    $subtotal = $rate * $quantity;
    $discount = 0.00;
    $total_amount = $subtotal - $discount;

    // 7. ORDER NUMBER
    $order_number = 'ORD' . date('Ymd') . rand(100000, 999999);

    // 8. TRANSACTION REF
    $txn_ref = 'TXN' . strtoupper(bin2hex(random_bytes(5)));
    $txn_ref_esc = $db->real_escape_string($txn_ref);

    // 9. TRANSACTION START
    $db->begin_transaction();

    try {
        // 9a. ORDERS — player_id column insert hi nahi ho raha
        $orderSql = "INSERT INTO orders 
            (order_number, order_type, customer_id, dealer_id,
             subtotal, discount, total_amount, coupon_id, coupon_code,
             status, payment_status, delivery_type, station_name, created_at, updated_at)
            VALUES 
            ('$order_number', 'fuel', $customer_id, $dealer_id,
             $subtotal, $discount, $total_amount, NULL, NULL,
             'confirmed', 'paid', 'pickup', '$station_name', NOW(), NOW())";

        if (!$db->query($orderSql)) {
            throw new Exception('Orders insert failed: ' . $db->error);
        }
        $order_id = $db->insert_id;

        // 9b. ORDER_ITEMS
        $product_name_esc = $db->real_escape_string($product['name']);
        $itemSql = "INSERT INTO order_items 
            (order_id, product_id, product_sku, product_name, product_brand,
             coupon_id, quantity, price, discount_percent, subtotal, created_at)
            VALUES 
            ($order_id, $product_id, NULL, '$product_name_esc', 'Hascol',
             NULL, $quantity, $rate, 0.00, $subtotal, NOW())";

        if (!$db->query($itemSql)) {
            throw new Exception('Order items insert failed: ' . $db->error);
        }
        $order_item_id = $db->insert_id;

        // 9c. TRANSACTIONS — player_id column insert hi nahi ho raha
        $txnSql = "INSERT INTO transactions 
            (customer_id, order_id, customer_name, dealer_id,
             station_name, product_name, amount, discount, final_amount,
             coupon_id, coupon_code, order_type, status, transaction_ref, created_at, updated_at)
            VALUES 
            ($customer_id, $order_id, '$customer_name', $dealer_id,
             '$station_name', '$product_name_esc', $subtotal, $discount, $total_amount,
             NULL, NULL, 'fuel', 'successful', '$txn_ref_esc', NOW(), NOW())";

        if (!$db->query($txnSql)) {
            throw new Exception('Transactions insert failed: ' . $db->error);
        }
        $transaction_id = $db->insert_id;

        // 9d. COMMIT
        $db->commit();

        // 10. SUCCESS
        echo json_encode([
            'status' => true,
            'message' => 'Fuel order placed successfully',
            'data' => [
                'order_id' => $order_id,
                'order_number' => $order_number,
                'order_item_id' => $order_item_id,
                'transaction_id' => $transaction_id,
                'transaction_ref' => $txn_ref,
                'product' => [
                    'id' => (int) $product['id'],
                    'name' => $product['name'],
                    'unit' => $product['unit']
                ],
                'station_name' => $dealer['station_name'],
                'rate' => $rate,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total_amount' => $total_amount
            ]
        ]);

    } catch (Exception $innerEx) {
        $db->rollback();
        throw $innerEx;
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => $e->getMessage()]);
}
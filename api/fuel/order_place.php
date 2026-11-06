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

    // 2. VALIDATION
    $required = ['customer_id', 'dealer_id', 'product_id', 'quantity'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("$field is required");
        }
    }

    $customer_id = (int)$input['customer_id'];
    $dealer_id   = (int)$input['dealer_id'];
    $product_id  = (int)$input['product_id'];
    $quantity    = (float)$input['quantity'];

    // Body se discount (optional)
    $client_discount = isset($input['discount']) ? (float)$input['discount'] : null;

    if ($quantity <= 0) {
        throw new Exception('Quantity must be greater than 0');
    }

    if ($client_discount !== null && $client_discount < 0) {
        throw new Exception('Discount cannot be negative');
    }

    // 3. DEALER CHECK
    $dealerSql = "SELECT id, station_name FROM hascol_dealers 
                  WHERE id = $dealer_id LIMIT 1";
    $dealerResult = $db->query($dealerSql);
    if (!$dealerResult || $dealerResult->num_rows === 0) {
        throw new Exception('Dealer not found');
    }
    $dealer = $dealerResult->fetch_assoc();
    $station_name = $db->real_escape_string($dealer['station_name']);

    // 4. PRODUCT CHECK
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
        throw new Exception('Product not available for this dealer');
    }
    $product = $productResult->fetch_assoc();
    $rate = (float)$product['rate'];

    // 5. CUSTOMER CHECK
    $custSql = "SELECT id, name FROM hascol_customer 
                WHERE id = $customer_id AND status = 'active' LIMIT 1";
    $custResult = $db->query($custSql);
    if (!$custResult || $custResult->num_rows === 0) {
        throw new Exception('Customer not found or inactive');
    }
    $customer = $custResult->fetch_assoc();
    $customer_name = $db->real_escape_string($customer['name']);

    // ============================================
    // 6. DISCOUNT CALCULATION
    // ============================================
    $discount_per_litre     = 1.00;
    $monthly_discount_limit = 250.00;

    $subtotal = $rate * $quantity;

    $monthStart = date('Y-m-01 00:00:00');
    $monthEnd   = date('Y-m-t 23:59:59');

    $discountUsageSql = "SELECT COALESCE(SUM(discount), 0) AS used_discount
                         FROM orders
                         WHERE customer_id = $customer_id
                           AND order_type = 'fuel'
                           AND status IN ('pending','confirmed','processing','completed')
                           AND created_at BETWEEN '$monthStart' AND '$monthEnd'";
    $discountUsageResult = $db->query($discountUsageSql);
    if (!$discountUsageResult) {
        throw new Exception('Discount usage query failed: ' . $db->error);
    }
    $used_discount = (float)$discountUsageResult->fetch_assoc()['used_discount'];

    $remaining_discount = $monthly_discount_limit - $used_discount;
    if ($remaining_discount < 0) {
        $remaining_discount = 0;
    }

    if ($client_discount !== null) {
        // Body se discount aaya
        $discount = $client_discount;

        if ($discount > $subtotal) {
            throw new Exception('Discount cannot be greater than subtotal (Rs ' . number_format($subtotal, 2) . ')');
        }

        if ($discount > $remaining_discount) {
            throw new Exception(
                'Discount exceeds monthly remaining. ' .
                'Requested: Rs ' . number_format($discount, 2) . ', ' .
                'Remaining: Rs ' . number_format($remaining_discount, 2)
            );
        }
    } else {
        // Server calculate kare (Rs 1/litre)
        $requested_discount = $quantity * $discount_per_litre;

        if ($requested_discount > $remaining_discount) {
            $discount = $remaining_discount;
        } else {
            $discount = $requested_discount;
        }
    }

    $total_amount = $subtotal - $discount;

    // Total used (is order ke baad)
    $total_used = $used_discount + $discount;

    // 7. ORDER NUMBER
    $order_number = 'ORD' . date('Ymd') . rand(100000, 999999);

    // 8. TRANSACTION REF
    $txn_ref = 'TXN' . strtoupper(bin2hex(random_bytes(5)));
    $txn_ref_esc = $db->real_escape_string($txn_ref);

    // 9. TRANSACTION START
    $db->begin_transaction();

    try {
        // 9a. ORDERS
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

        // 9c. TRANSACTIONS
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
            'status'  => true,
            'message' => 'Fuel order placed successfully',
            'data' => [
                'order_id'        => $order_id,
                'order_number'    => $order_number,
                'order_item_id'   => $order_item_id,
                'transaction_id'  => $transaction_id,
                'transaction_ref' => $txn_ref,
                'product' => [
                    'id'   => (int)$product['id'],
                    'name' => $product['name'],
                    'unit' => $product['unit']
                ],
                'station_name'   => $dealer['station_name'],
                'rate'           => $rate,
                'quantity'       => $quantity,
                'subtotal'       => $subtotal,
                'discount'       => $discount,
                'total_amount'   => $total_amount,
                'discount_info' => [
                    'discount_per_litre'  => $discount_per_litre,
                    // 'discount_applied'    => $discount,
                    'monthly_limit'       => $monthly_discount_limit,
                    'total_used'          => $total_used,
                    'remaining_after'     => max(0, $remaining_discount - $discount)
                ]
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
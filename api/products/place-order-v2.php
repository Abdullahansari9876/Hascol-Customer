<?php
/**
 * PLACE ORDER API V4 (Frontend Calculates, Backend Just Saves)
 * 
 * POST /api/products/place-order-v2.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require '../config.php';
require '../db.php';

$input = getInput();

if (!is_array($input)) {
    jsonResponse(['status' => 'error', 'message' => 'Invalid JSON input']);
}

// ─── Basic input ───
$dealerId        = (int) ($input['dealer_id'] ?? 0);
$items           = $input['items'] ?? [];
$deliveryType    = trim($input['delivery_type'] ?? 'pickup');
$deliveryAddress = trim($input['delivery_address'] ?? '');
$notes           = trim($input['notes'] ?? '');

// Only total_amount from frontend
$totalAmount     = (float) ($input['total_amount'] ?? 0);

// ─── Validation ───
if ($dealerId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'dealer_id required']);
}
if (empty($items) || !is_array($items)) {
    jsonResponse(['status' => 'error', 'message' => 'items required (array)']);
}
if (!in_array($deliveryType, ['pickup', 'delivery'])) {
    jsonResponse(['status' => 'error', 'message' => 'Invalid delivery_type']);
}
if ($deliveryType === 'delivery' && empty($deliveryAddress)) {
    jsonResponse(['status' => 'error', 'message' => 'delivery_address required']);
}

// ─── Dealer Check ───
$stmt = $db->prepare("SELECT id, name, station_name FROM hascol_dealers WHERE id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param("i", $dealerId);
$stmt->execute();
$dealer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$dealer) {
    jsonResponse(['status' => 'error', 'message' => 'Dealer not found or inactive']);
}

$stationName = $dealer['station_name'];

// ─── Validate items (structure only) ───
$validatedItems = [];
$calculatedSubtotal = 0;

foreach ($items as $item) {
    $productId = (int) ($item['product_id'] ?? 0);
    $quantity  = (int) ($item['quantity'] ?? 0);

    if ($productId <= 0 || $quantity <= 0) {
        jsonResponse(['status' => 'error', 'message' => 'Invalid product_id or quantity']);
    }

    $stmt = $db->prepare("SELECT id, stock, status, name FROM lube_products WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        jsonResponse(['status' => 'error', 'message' => "Product ID $productId not found"]);
    }
    if ($product['status'] !== 'active') {
        jsonResponse(['status' => 'error', 'message' => "Product '{$product['name']}' is not available"]);
    }
    if ($product['stock'] < $quantity) {
        jsonResponse(['status' => 'error', 'message' => "Not enough stock for '{$product['name']}'. Available: {$product['stock']}"]);
    }

    $itemPrice    = (float) ($item['price'] ?? 0);
    $itemSubtotal = (float) ($item['subtotal'] ?? 0);

    $calculatedSubtotal += $itemSubtotal;

    $validatedItems[] = [
        'product_id'    => $productId,
        'product_sku'   => $item['product_sku']   ?? '',
        'product_name'  => $item['product_name']  ?? $product['name'],
        'product_brand' => $item['product_brand'] ?? '',
        'quantity'      => $quantity,
        'price'         => $itemPrice,
        'subtotal'      => $itemSubtotal,
    ];
}

// Subtotal backend calculate kar liya items se
$subtotal = round($calculatedSubtotal, 2);

// Discount = subtotal - total_amount (agar total_amount chota hai)
$totalDiscount = 0;
if ($subtotal > $totalAmount) {
    $totalDiscount = round($subtotal - $totalAmount, 2);
}

// ─── FINAL SANITY ───
if ($totalAmount <= 0 && $subtotal > 0) {
    $totalAmount = $subtotal;
}

$orderNumber = 'ORD' . date('Ymd') . strtoupper(substr(md5(uniqid()), 0, 6));

// Customer not involved — set defaults
$customerId = 0;
$key        = 'WALKIN_' . $dealerId;
$playerId   = 'WALKIN';
$customerName = 'Walking Customer';

$db->begin_transaction();

try {
    // ═══════════════════════════════════════════════════
    // 1. Order insert
    //    Placeholders: order_number, customer_id, dealer_id, player_key, player_id,
    //                  subtotal, discount, total_amount,
    //                  delivery_type, delivery_address, station_name, notes
    //    Total = 12 placeholders
    // ═══════════════════════════════════════════════════
    $stmt = $db->prepare("
        INSERT INTO orders 
        (order_number, customer_id, dealer_id, player_key, player_id, 
         subtotal, discount, total_amount, 
         coupon_id, coupon_code, 
         status, payment_status, 
         delivery_type, delivery_address, station_name, notes, 
         created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, 'successful', 'paid', ?, ?, ?, ?, NOW(), NOW())
    ");

    // Type string: 12 characters for 12 placeholders
    // s=order_number, i=customer_id, i=dealer_id, s=player_key, s=player_id,
    // d=subtotal, d=discount, d=total_amount,
    // s=delivery_type, s=delivery_address, s=station_name, s=notes
    $stmt->bind_param(
        "siissdddssss",
        $orderNumber,
        $customerId,
        $dealerId,
        $key,
        $playerId,
        $subtotal,
        $totalDiscount,
        $totalAmount,
        $deliveryType,
        $deliveryAddress,
        $stationName,
        $notes
    );
    $stmt->execute();
    $orderId = $stmt->insert_id;
    $stmt->close();

    // ═══════════════════════════════════════════════════
    // 2. Order items insert
    //    Placeholders: order_id, product_id, product_sku, product_name, product_brand,
    //                  quantity, price, discount_percent, subtotal
    //    Total = 9 placeholders
    // ═══════════════════════════════════════════════════
    foreach ($validatedItems as $vi) {
        $discountPercent = 0;

        $stmt = $db->prepare("
            INSERT INTO order_items 
            (order_id, product_id, product_sku, product_name, product_brand, 
             coupon_id, quantity, price, discount_percent, subtotal, created_at) 
            VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param(
            "iisssiddd",
            $orderId,
            $vi['product_id'],
            $vi['product_sku'],
            $vi['product_name'],
            $vi['product_brand'],
            $vi['quantity'],
            $vi['price'],
            $discountPercent,
            $vi['subtotal']
        );
        $stmt->execute();
        $stmt->close();

        // Stock kam
        $stmt = $db->prepare("UPDATE lube_products SET stock = stock - ? WHERE id = ? AND stock >= ?");
        $stmt->bind_param("iii", $vi['quantity'], $vi['product_id'], $vi['quantity']);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            throw new Exception("Stock update failed for product ID {$vi['product_id']}");
        }
        $stmt->close();
    }

    // ═══════════════════════════════════════════════════
    // 3. Transaction insert
    //    Placeholders: customer_id, customer_name, dealer_id, player_key, player_id,
    //                  station_name, product_name, amount, discount, final_amount,
    //                  transaction_ref
    //    Total = 11 placeholders
    // ═══════════════════════════════════════════════════
    $transactionRef = 'TXN' . strtoupper(substr(md5(uniqid()), 0, 10));

    $productNamesString = implode(', ', array_map(function ($vi) {
        return $vi['product_name'] . ' (x' . $vi['quantity'] . ')';
    }, $validatedItems));

    $stmt = $db->prepare("
        INSERT INTO transactions 
        (customer_id, customer_name, dealer_id, player_key, player_id, station_name, product_name,
         amount, discount, final_amount, 
         coupon_id, coupon_code, status, transaction_ref, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, 'successful', ?, NOW())
    ");
    $stmt->bind_param(
        "isissssddds",
        $customerId,
        $customerName,
        $dealerId,
        $key,
        $playerId,
        $stationName,
        $productNamesString,
        $subtotal,
        $totalDiscount,
        $totalAmount,
        $transactionRef
    );
    $stmt->execute();
    $stmt->close();

    $db->commit();

    // ─── Success Response ───
    jsonResponse([
        'status' => 'success',
        'message' => 'Order placed successfully',
        'order' => [
            'id'           => (int) $orderId,
            'order_number' => $orderNumber,

            'dealer' => [
                'id'           => (int) $dealer['id'],
                'name'         => $dealer['name'],
                'station_name' => $dealer['station_name'],
            ],

            'subtotal'       => $subtotal,
            'total_discount' => $totalDiscount,
            'total_amount'   => $totalAmount,

            'status'         => 'successful',
            'payment_status' => 'paid',
            'delivery_type'  => $deliveryType,
            'delivery_address' => $deliveryAddress,
            'notes'          => $notes,
            'items'          => $validatedItems,
            'created_at'     => date('Y-m-d H:i:s'),
        ],
    ]);

} catch (Exception $e) {
    $db->rollback();
    error_log('Place Order V4 Error: ' . $e->getMessage());
    jsonResponse(['status' => 'error', 'message' => 'Order failed: ' . $e->getMessage()]);
}
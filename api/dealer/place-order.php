<?php
/**
 * PLACE ORDER API (Frontend Calculates, Backend Just Saves)
 * + Coupon auto-calculate fallback (agar frontend discount 0 bheje)
 * 
 * POST /api/dealer/place-order.php
 * 
 * Body:
 * {
 *   dealer_id,
 *   customer_id,
 *   items: [ { product_id, product_sku, product_name, product_brand, quantity, price, discount_percent, subtotal } ],
 *   delivery_type: "pickup" | "delivery",
 *   delivery_address (optional),
 *   notes (optional),
 *   coupon_id (optional),
 *   coupon_code (optional),
 *   subtotal,
 *   coupon_discount,
 *   manual_discount,
 *   total_discount,
 *   total_amount
 * }
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require '../config.php';
require '../db.php';

$input = getInput();

if (!is_array($input)) {
    jsonResponse(['status'=>'error','message'=>'Invalid JSON input']);
}

// ─── Basic input ───
$dealerId        = (int)($input['dealer_id'] ?? 0);
$customerId      = (int)($input['customer_id'] ?? 0);
$items           = $input['items'] ?? [];
$deliveryType    = trim($input['delivery_type'] ?? 'pickup');
$deliveryAddress = trim($input['delivery_address'] ?? '');
$notes           = trim($input['notes'] ?? '');
$couponId        = (int)($input['coupon_id'] ?? 0);
$couponCode      = trim($input['coupon_code'] ?? '');

// Amounts sent from frontend (already calculated)
$subtotal       = (float)($input['subtotal'] ?? 0);
$couponDiscount = (float)($input['coupon_discount'] ?? 0);
$manualDiscount = (float)($input['manual_discount'] ?? 0);
$totalDiscount  = (float)($input['total_discount'] ?? 0);
$totalAmount    = (float)($input['total_amount'] ?? 0);

// ─── Validation ───
if ($dealerId <= 0) {
    jsonResponse(['status'=>'error','message'=>'dealer_id required']);
}
if ($customerId <= 0) {
    jsonResponse(['status'=>'error','message'=>'customer_id required']);
}
if (empty($items) || !is_array($items)) {
    jsonResponse(['status'=>'error','message'=>'items required (array)']);
}
if (!in_array($deliveryType, ['pickup', 'delivery'])) {
    jsonResponse(['status'=>'error','message'=>'Invalid delivery_type']);
}
if ($deliveryType === 'delivery' && empty($deliveryAddress)) {
    jsonResponse(['status'=>'error','message'=>'delivery_address required']);
}

// ─── Dealer Check ───
$stmt = $db->prepare("SELECT id, name, station_name FROM dealers WHERE id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param("i", $dealerId);
$stmt->execute();
$dealer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$dealer) {
    jsonResponse(['status'=>'error','message'=>'Dealer not found or inactive']);
}

// ─── Customer Check ───
$stmt = $db->prepare("
    SELECT id, player_key, player_id, name, mobile, 
           total_coupons, remaining_coupons, used_coupons 
    FROM customers 
    WHERE id = ? LIMIT 1
");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    jsonResponse(['status'=>'error','message'=>'Customer not found']);
}

$key      = $customer['player_key'];
$playerId = $customer['player_id'];

// ─── Validate items (structure only, NO price calc) ───
$validatedItems = [];

foreach ($items as $item) {
    $productId = (int)($item['product_id'] ?? 0);
    $quantity  = (int)($item['quantity'] ?? 0);

    if ($productId <= 0 || $quantity <= 0) {
        jsonResponse(['status'=>'error','message'=>'Invalid product_id or quantity']);
    }

    // Verify product exists & stock available
    $stmt = $db->prepare("SELECT id, stock, status, name FROM lube_products WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        jsonResponse(['status'=>'error','message'=>"Product ID $productId not found"]);
    }
    if ($product['status'] !== 'active') {
        jsonResponse(['status'=>'error','message'=>"Product '{$product['name']}' is not available"]);
    }
    if ($product['stock'] < $quantity) {
        jsonResponse(['status'=>'error','message'=>"Not enough stock for '{$product['name']}'. Available: {$product['stock']}"]);
    }

    $validatedItems[] = [
        'product_id'       => $productId,
        'product_sku'      => $item['product_sku'] ?? '',
        'product_name'     => $item['product_name'] ?? $product['name'],
        'product_brand'    => $item['product_brand'] ?? '',
        'quantity'         => $quantity,
        'price'            => (float)($item['price'] ?? 0),
        'discount_percent' => (float)($item['discount_percent'] ?? 0),
        'subtotal'         => (float)($item['subtotal'] ?? 0),
    ];
}

// ═══════════════════════════════════════════════════
// ✅ COUPON AUTO-CALCULATE (agar frontend ne 0 bheja)
// ═══════════════════════════════════════════════════
if ($couponId > 0 && $couponDiscount <= 0) {

    $stmt = $db->prepare("
        SELECT id, title, discount_percent, discount_amount, valid_to, status
        FROM coupons 
        WHERE id = ? AND customer_id = ? 
        LIMIT 1
    ");
    $stmt->bind_param("ii", $couponId, $customerId);
    $stmt->execute();
    $couponRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$couponRow) {
        jsonResponse(['status'=>'error','message'=>'Invalid coupon for this customer']);
    }
    if ($couponRow['status'] !== 'available') {
        jsonResponse(['status'=>'error','message'=>'Coupon already used']);
    }
    if (!empty($couponRow['valid_to']) && strtotime($couponRow['valid_to']) < time()) {
        jsonResponse(['status'=>'error','message'=>'Coupon expired']);
    }

    // Auto-fill coupon_code if empty
    if (empty($couponCode)) {
        $couponCode = $couponRow['title'];
    }

    // Calculate discount
    $cpPercent = (float)$couponRow['discount_percent'];
    $cpAmount  = (float)$couponRow['discount_amount'];

    if ($cpPercent > 0) {
        $couponDiscount = round(($subtotal * $cpPercent) / 100, 2);
    } elseif ($cpAmount > 0) {
        $couponDiscount = round($cpAmount, 2);
    }

    // Recompute totals
    $totalDiscount = round($couponDiscount + $manualDiscount, 2);
    if ($totalDiscount > $subtotal) {
        $totalDiscount = $subtotal;
    }
    $totalAmount = round($subtotal - $totalDiscount, 2);
}

// ═══════════════════════════════════════════════════
// ✅ FINAL SANITY — total_amount must match
// ═══════════════════════════════════════════════════
if ($totalAmount <= 0 && $subtotal > 0) {
    // Safety: agar frontend ne total_amount 0 bheja
    $totalDiscount = round($couponDiscount + $manualDiscount, 2);
    if ($totalDiscount > $subtotal) $totalDiscount = $subtotal;
    $totalAmount   = round($subtotal - $totalDiscount, 2);
}

$orderNumber = 'ORD' . date('Ymd') . strtoupper(substr(md5(uniqid()), 0, 6));

$db->begin_transaction();

try {
    // 1. Order insert
    $stmt = $db->prepare("
        INSERT INTO orders 
        (order_number, customer_id, dealer_id, player_key, player_id, 
         subtotal, discount, total_amount, 
         coupon_id, coupon_code, 
         status, payment_status, 
         delivery_type, delivery_address, notes, 
         created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'successful', 'paid', ?, ?, ?, NOW(), NOW())
    ");
    $stmt->bind_param("siissdddissss", 
        $orderNumber, $customerId, $dealerId, $key, $playerId, 
        $subtotal, $totalDiscount, $totalAmount, 
        $couponId, $couponCode, 
        $deliveryType, $deliveryAddress, $notes
    );
    $stmt->execute();
    $orderId = $stmt->insert_id;
    $stmt->close();

    // 2. Order items insert
    foreach ($validatedItems as $vi) {
        $stmt = $db->prepare("
            INSERT INTO order_items 
            (order_id, product_id, product_sku, product_name, product_brand, 
             coupon_id, quantity, price, discount_percent, subtotal, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iisssiiddd", 
            $orderId, $vi['product_id'], $vi['product_sku'], $vi['product_name'], $vi['product_brand'], 
            $couponId, 
            $vi['quantity'], $vi['price'], $vi['discount_percent'], $vi['subtotal']
        );
        $stmt->execute();
        $stmt->close();

        // 3. Stock kam
        $stmt = $db->prepare("UPDATE lube_products SET stock = stock - ? WHERE id = ? AND stock >= ?");
        $stmt->bind_param("iii", $vi['quantity'], $vi['product_id'], $vi['quantity']);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            throw new Exception("Stock update failed for product ID {$vi['product_id']}");
        }
        $stmt->close();
    }

    // 4. Coupon mark used (if any)
    if ($couponId > 0) {
        $stmt = $db->prepare("
            UPDATE coupons 
            SET status = 'used', 
                used_at = NOW(), 
                used_at_station = ? 
            WHERE id = ?
        ");
        $stmt->bind_param("si", $dealer['station_name'], $couponId);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("
            UPDATE customers 
            SET remaining_coupons = remaining_coupons - 1, 
                used_coupons = used_coupons + 1 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $stmt->close();
    }

    // 5. Transaction insert
    $transactionRef = 'TXN' . strtoupper(substr(md5(uniqid()), 0, 10));
    $stationName    = $dealer['station_name'];

    $stmt = $db->prepare("
        INSERT INTO transactions 
        (customer_id, dealer_id, player_key, player_id, station_name, 
         amount, discount, final_amount, 
         coupon_id, coupon_code, status, transaction_ref, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'successful', ?, NOW())
    ");
    $stmt->bind_param("iisssdddiss", 
        $customerId, $dealerId, $key, $playerId, $stationName, 
        $subtotal, $totalDiscount, $totalAmount, 
        $couponId, $couponCode, $transactionRef
    );
    $stmt->execute();
    $stmt->close();

    $db->commit();

    // ─── Success Response ───
    jsonResponse([
        'status'  => 'success',
        'message' => 'Order placed successfully',
        'order'   => [
            'id'            => (int)$orderId,
            'order_number'  => $orderNumber,

            'customer' => [
                'id'                => (int)$customer['id'],
                'name'              => $customer['name'],
                'mobile'            => $customer['mobile'],
                'player_id'         => $customer['player_id'],
                'total_coupons'     => (int)$customer['total_coupons'],
                'remaining_coupons' => (int)$customer['remaining_coupons'] - ($couponId > 0 ? 1 : 0),
                'used_coupons'      => (int)$customer['used_coupons'] + ($couponId > 0 ? 1 : 0),
            ],

            'dealer' => [
                'id'           => (int)$dealer['id'],
                'name'         => $dealer['name'],
                'station_name' => $dealer['station_name'],
            ],

            'subtotal'          => $subtotal,
            'coupon_discount'   => $couponDiscount,
            'manual_discount'   => $manualDiscount,
            'total_discount'    => $totalDiscount,
            'total_amount'      => $totalAmount,

            'coupon_id'         => $couponId ?: null,
            'coupon_code'       => $couponCode ?: null,

            'status'           => 'successful',
            'payment_status'   => 'paid',
            'delivery_type'    => $deliveryType,
            'delivery_address' => $deliveryAddress,
            'notes'            => $notes,
            'items'            => $validatedItems,
            'created_at'       => date('Y-m-d H:i:s'),
        ],
    ]);

} catch (Exception $e) {
    $db->rollback();
    error_log('Place Order Error: ' . $e->getMessage());
    jsonResponse(['status'=>'error','message'=>'Order failed: ' . $e->getMessage()]);
}
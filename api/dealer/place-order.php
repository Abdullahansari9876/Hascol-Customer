<?php
/**
 * PLACE ORDER API (App Simple Bheje, API Calculates)
 * 
 * POST /api/dealer/place-order.php
 * 
 * Body (App ONLY sends basic info):
 * {
 *   dealer_id,
 *   customer_id,
 *   items: [ { product_id, quantity } ],
 *   delivery_type: "pickup" | "delivery",
 *   delivery_address (optional - required if delivery),
 *   notes (optional),
 *   coupon_id (optional)      // 0 = no coupon
 *   discount (optional)        // Manual discount PERCENT (0 = no discount)
 *   discount_amount (optional) // Manual discount FLAT Rs (0 = no discount)
 * }
 * 
 * NOTE:
 * - API khud calculation karti hai
 * - App sirf intent bhejta hai
 * - Response me full breakdown milta hai
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

// ─── App se basic input ───
$dealerId              = (int)($input['dealer_id'] ?? 0);
$customerId            = (int)($input['customer_id'] ?? 0);
$items                 = $input['items'] ?? [];
$deliveryType          = trim($input['delivery_type'] ?? 'pickup');
$deliveryAddress       = trim($input['delivery_address'] ?? '');
$notes                 = trim($input['notes'] ?? '');
$couponId              = (int)($input['coupon_id'] ?? 0);
$manualDiscountPercent = (float)($input['discount'] ?? 0);
$manualDiscountAmount  = (float)($input['discount_amount'] ?? 0);

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
if ($manualDiscountPercent < 0 || $manualDiscountPercent > 100) {
    jsonResponse(['status'=>'error','message'=>'discount percent must be between 0 and 100']);
}
if ($manualDiscountAmount < 0) {
    jsonResponse(['status'=>'error','message'=>'discount_amount cannot be negative']);
}
if ($manualDiscountPercent > 0 && $manualDiscountAmount > 0) {
    jsonResponse(['status'=>'error','message'=>'Use either discount (percent) OR discount_amount (flat), not both']);
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

// ═══════════════════════════════════════════════════
// ✅ API CALCULATES EVERYTHING FROM HERE
// ═══════════════════════════════════════════════════

// ─── Order Items Validate + Subtotal ───
$validatedItems = [];
$subtotal       = 0;

foreach ($items as $item) {
    $productId = (int)($item['product_id'] ?? 0);
    $quantity  = (int)($item['quantity'] ?? 0);

    if ($productId <= 0 || $quantity <= 0) {
        jsonResponse(['status'=>'error','message'=>'Invalid product_id or quantity']);
    }

    $stmt = $db->prepare("
        SELECT id, sku, name, brand, price, discount_percent, stock, status 
        FROM lube_products 
        WHERE id = ? LIMIT 1
    ");
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

    $price           = (float)$product['price'];
    $discountPercent = (float)($product['discount_percent'] ?? 0);
    $itemSubtotal    = $price * $quantity;

    if ($discountPercent > 0) {
        $itemSubtotal = $itemSubtotal - ($itemSubtotal * $discountPercent / 100);
    }

    $subtotal += $itemSubtotal;

    $validatedItems[] = [
        'product_id'       => (int)$product['id'],
        'product_sku'      => $product['sku'] ?? '',
        'product_name'     => $product['name'],
        'product_brand'    => $product['brand'] ?? '',
        'quantity'         => $quantity,
        'price'            => $price,
        'discount_percent' => $discountPercent,
        'subtotal'         => round($itemSubtotal, 2),
    ];
}

$subtotal = round($subtotal, 2);

// ─── Coupon Discount ───
$couponDiscount  = 0;
$appliedCouponId = null;
$appliedCode     = null;
$couponType      = null;
$couponValue     = 0;
$couponApplied   = 0;

if ($couponId > 0) {
    $stmt = $db->prepare("
        SELECT * FROM coupons 
        WHERE id = ? 
          AND customer_id = ? 
          AND status = 'available' 
        LIMIT 1
    ");
    $stmt->bind_param("ii", $couponId, $customerId);
    $stmt->execute();
    $coupon = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$coupon) {
        jsonResponse([
            'status'  => 'error',
            'message' => 'Invalid coupon or coupon not available for this customer'
        ]);
    }

    if (!empty($coupon['valid_to']) && strtotime($coupon['valid_to']) < time()) {
        jsonResponse(['status'=>'error','message'=>'Coupon has expired']);
    }

    $couponPercent = (float)($coupon['discount_percent'] ?? 0);
    $couponAmount  = (float)($coupon['discount_amount'] ?? 0);

    if ($couponPercent > 0) {
        $couponDiscount = round(($subtotal * $couponPercent) / 100, 2);
        $couponType     = 'percent';
        $couponValue    = $couponPercent;
    } elseif ($couponAmount > 0) {
        $couponDiscount = round($couponAmount, 2);
        $couponType     = 'amount';
        $couponValue    = $couponAmount;
    }

    $appliedCouponId = (int)$coupon['id'];
    $appliedCode     = $coupon['title'];
    $couponApplied   = 1;
}

// ─── Manual Discount ───
$manualDiscount      = 0;
$manualDiscountType  = null;
$manualDiscountValue = 0;

if ($manualDiscountPercent > 0) {
    $manualDiscount      = round(($subtotal * $manualDiscountPercent) / 100, 2);
    $manualDiscountType  = 'percent';
    $manualDiscountValue = $manualDiscountPercent;
} elseif ($manualDiscountAmount > 0) {
    $manualDiscount      = round($manualDiscountAmount, 2);
    $manualDiscountType  = 'amount';
    $manualDiscountValue = $manualDiscountAmount;
}

// ─── Total Discount ───
$totalDiscount = round($couponDiscount + $manualDiscount, 2);

if ($totalDiscount > $subtotal) {
    $totalDiscount = $subtotal;
    if ($couponDiscount >= $subtotal) {
        $couponDiscount = $subtotal;
        $manualDiscount = 0;
    } else {
        $manualDiscount = round($subtotal - $couponDiscount, 2);
    }
}

$totalAmount = round($subtotal - $totalDiscount, 2);
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
    $stmt->bind_param("siisidddissss", 
        $orderNumber, $customerId, $dealerId, $key, $playerId, 
        $subtotal, $totalDiscount, $totalAmount, 
        $appliedCouponId, $appliedCode, 
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
            $appliedCouponId, 
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

    // 4. Coupon used mark
    if ($appliedCouponId) {
        $stmt = $db->prepare("
            UPDATE coupons 
            SET status = 'used', 
                used_at = NOW(), 
                used_at_station = ? 
            WHERE id = ?
        ");
        $stmt->bind_param("si", $dealer['station_name'], $appliedCouponId);
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
    $stmt->bind_param("iisssdddsss", 
        $customerId, $dealerId, $key, $playerId, $stationName, 
        $subtotal, $totalDiscount, $totalAmount, 
        $appliedCouponId, $appliedCode, $transactionRef
    );
    $stmt->execute();
    $stmt->close();

    $db->commit();

    // ═══════════════════════════════════════════════════
    // ✅ SUCCESS RESPONSE (Full Breakdown)
    // ═══════════════════════════════════════════════════
    jsonResponse([
        'status'  => 'success',
        'message' => 'Order placed successfully',
        'order'   => [
            // ─── Basic Order Info ───
            'id'            => (int)$orderId,
            'order_number'  => $orderNumber,

            // ─── Customer ───
            'customer' => [
                'id'                => (int)$customer['id'],
                'name'              => $customer['name'],
                'mobile'            => $customer['mobile'],
                'player_id'         => $customer['player_id'],
                'total_coupons'     => (int)$customer['total_coupons'],
                'remaining_coupons' => (int)$customer['remaining_coupons'] - ($appliedCouponId ? 1 : 0),
                'used_coupons'      => (int)$customer['used_coupons'] + ($appliedCouponId ? 1 : 0),
            ],

            // ─── Dealer ───
            'dealer' => [
                'id'           => (int)$dealer['id'],
                'name'         => $dealer['name'],
                'station_name' => $dealer['station_name'],
            ],

            // ─── Amounts (Calculated by API) ───
            'subtotal'          => $subtotal,
            'coupon_discount'   => $couponDiscount,
            'manual_discount'   => $manualDiscount,
            'total_discount'    => $totalDiscount,
            'total_amount'      => $totalAmount,

            // ─── Discount Breakdown (Full Details) ───
            'discount_breakdown' => [
                // Coupon wala
                'coupon_used'           => (bool)$couponApplied,
                'coupon_id'             => $appliedCouponId,
                'coupon_code'           => $appliedCode,
                'coupon_type'           => $couponType,
                'coupon_value'          => $couponValue,
                'coupon_discount'       => $couponDiscount,

                // Manual discount wala
                'manual_discount_used'  => (bool)($manualDiscount > 0),
                'manual_discount_type'  => $manualDiscountType,
                'manual_discount_value' => $manualDiscountValue,
                'manual_discount'       => $manualDiscount,
            ],

            // ─── Order Details ───
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
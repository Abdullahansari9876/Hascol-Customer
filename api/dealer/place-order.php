<?php
/**
 * PLACE ORDER API (Dealer App — Coupon + Manual Discount)
 * 
 * POST /api/dealer/place-order.php
 * 
 * Body: { 
 *   dealer_id,
 *   customer_id,
 *   items: [...],
 *   delivery_type,
 *   delivery_address (optional),
 *   notes (optional),
 *   coupon_id (optional)          // 0 = no coupon
 *   discount (optional)           // Manual discount PERCENT (5 = 5%)
 *   discount_amount (optional)    // Manual discount FLAT ₹ (5 = ₹5)
 * }
 * 
 * NOTE:
 * - min_purchase check HATA diya gaya hai
 * - Coupon sirf customer_id + status se validate hoga
 * - discount = PERCENT
 * - discount_amount = FLAT ₹
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

$dealerId              = (int)($input['dealer_id'] ?? 0);
$customerId            = (int)($input['customer_id'] ?? 0);
$couponId              = (int)($input['coupon_id'] ?? 0);
$manualDiscountPercent = (float)($input['discount'] ?? 0);
$manualDiscountAmount  = (float)($input['discount_amount'] ?? 0);
$deliveryType          = trim($input['delivery_type'] ?? 'pickup');
$deliveryAddress       = trim($input['delivery_address'] ?? '');
$notes                 = trim($input['notes'] ?? '');
$items                 = $input['items'] ?? [];

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
// ✅ min_purchase check HATA diya
// ✅ Sirf id + customer_id + status check hoga
$couponDiscount  = 0;
$appliedCouponId = null;
$appliedCode     = null;
$couponType      = null;
$couponValue     = 0;

// ✅ Coupon applied flag
$couponApplied   = 0;
$couponUsed      = false;

if ($couponId > 0) {
    error_log("=== COUPON DEBUG START ===");
    error_log("coupon_id: $couponId, customer_id: $customerId, subtotal: $subtotal");

    // ✅ min_purchase hata diya
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
        error_log("Coupon NOT FOUND or not available");
        jsonResponse([
            'status'  => 'error',
            'message' => 'Invalid coupon or coupon not available for this customer'
        ]);
    }

    error_log("Coupon found: " . json_encode($coupon));

    // Expiry check
    if (!empty($coupon['valid_to']) && strtotime($coupon['valid_to']) < time()) {
        error_log("Coupon EXPIRED: " . $coupon['valid_to']);
        jsonResponse(['status'=>'error','message'=>'Coupon has expired']);
    }

    $couponPercent = (float)($coupon['discount_percent'] ?? 0);
    $couponAmount  = (float)($coupon['discount_amount'] ?? 0);

    if ($couponPercent > 0) {
        $couponDiscount = ($subtotal * $couponPercent) / 100;
        $couponType     = 'percent';
        $couponValue    = $couponPercent;
    } elseif ($couponAmount > 0) {
        $couponDiscount = $couponAmount;
        $couponType     = 'amount';
        $couponValue    = $couponAmount;
    }

    $couponDiscount = round($couponDiscount, 2);

    $appliedCouponId = (int)$coupon['id'];
    $appliedCode     = $coupon['title'];

    // ✅ Coupon laga flag set karo
    $couponApplied   = 1;
    $couponUsed      = true;

    error_log("Coupon applied: $couponDiscount ($couponType $couponValue)");
    error_log("=== COUPON DEBUG END ===");
}

// ─── Manual Discount ───
$manualDiscount = 0;
$manualType     = null;
$manualValue    = 0;

if ($manualDiscountPercent > 0) {
    $manualDiscount = round(($subtotal * $manualDiscountPercent) / 100, 2);
    $manualType     = 'percent';
    $manualValue    = $manualDiscountPercent;
} elseif ($manualDiscountAmount > 0) {
    $manualDiscount = round($manualDiscountAmount, 2);
    $manualType     = 'amount';
    $manualValue    = $manualDiscountAmount;
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
        $stmt = $db->prepare("UPDATE lube_products SET stock = stock - ? WHERE id = ?");
        $stmt->bind_param("ii", $vi['quantity'], $vi['product_id']);
        $stmt->execute();
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

    // ─── Success Response ───
    jsonResponse([
        'status'  => 'success',
        'message' => 'Order placed successfully',
        'order'   => [
            'id'                => (int)$orderId,
            'order_number'      => $orderNumber,
            'dealer_id'         => (int)$dealerId,
            'dealer_name'       => $dealer['name'],
            'station_name'      => $dealer['station_name'],
            'customer_id'       => (int)$customerId,
            'customer_name'     => $customer['name'],
            'customer_mobile'   => $customer['mobile'],
            'subtotal'          => $subtotal,
            'coupon_discount'   => $couponDiscount,
            'coupon_type'       => $couponType,
            'coupon_value'      => $couponValue,
            'manual_value'      => $manualValue,
            'total_discount'    => $totalDiscount,
            'total_amount'      => $totalAmount,

            // ✅ coupon_applied: 1 = laga, 0 = nahi laga
            'coupon_applied'    => $couponApplied,
            // ✅ coupon_used: true / false
            'coupon_used'       => $couponUsed,

            'remaining_coupons' => (int)$customer['remaining_coupons'] - ($appliedCouponId ? 1 : 0),
            'status'            => 'successful',
            'payment_status'    => 'paid',
            'delivery_type'     => $deliveryType,
            'delivery_address'  => $deliveryAddress,
            'notes'             => $notes,
            'items'             => $validatedItems,
            'created_at'        => date('Y-m-d H:i:s'),
        ],
    ]);

} catch (Exception $e) {
    $db->rollback();
    error_log('Place Order Error: ' . $e->getMessage());
    jsonResponse(['status'=>'error','message'=>'Order failed: ' . $e->getMessage()]);
}
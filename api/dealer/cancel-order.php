<?php
/**
 * CANCEL / REVERT ORDER API
 *
 * POST /api/dealer/cancel-order.php
 *
 * Body (JSON):
 * {
 *   "customer_id": 123,
 *   "dealer_id": 45,
 *   "order_id": 789,           // required (ya order_number)
 *   "order_number": "ORD...",  // optional (agar order_id na ho)
 *   "reason": "Customer changed mind"  // optional
 * }
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
$dealerId = (int) ($input['dealer_id'] ?? 0);
$customerId = (int) ($input['customer_id'] ?? 0);
$orderId = (int) ($input['order_id'] ?? 0);
$orderNumber = trim($input['order_number'] ?? '');
$reason = trim($input['reason'] ?? 'Customer cancelled order');

// ─── Validation ───
if ($dealerId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'dealer_id required']);
}
if ($customerId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'customer_id required']);
}
if ($orderId <= 0 && empty($orderNumber)) {
    jsonResponse(['status' => 'error', 'message' => 'order_id or order_number required']);
}

// ─── Dealer check ───
$stmt = $db->prepare("SELECT id, name, station_name FROM hascol_dealers WHERE id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param("i", $dealerId);
$stmt->execute();
$dealer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$dealer) {
    jsonResponse(['status' => 'error', 'message' => 'Dealer not found or inactive']);
}

$stationName = $dealer['station_name'];

// ─── Customer check ───
$stmt = $db->prepare("
    SELECT id, player_key, player_id, name, mobile,
           total_coupons, remaining_coupons, used_coupons
    FROM hascol_customer
    WHERE id = ? LIMIT 1
");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    jsonResponse(['status' => 'error', 'message' => 'Customer not found']);
}

// ─── Transaction start ───
$db->begin_transaction();

try {
    // 1. ORDER LOCK + FETCH
    if ($orderId > 0) {
        $stmt = $db->prepare("
            SELECT *
            FROM orders
            WHERE id = ? AND customer_id = ? AND dealer_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param("iii", $orderId, $customerId, $dealerId);
    } else {
        $stmt = $db->prepare("
            SELECT *
            FROM orders
            WHERE order_number = ? AND customer_id = ? AND dealer_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param("sii", $orderNumber, $customerId, $dealerId);
    }
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        // Debug log (server log only, client ko sensitive info nahi jati)
        if ($orderId > 0) {
            $chk = $db->prepare("SELECT id, customer_id, dealer_id FROM orders WHERE id = ? LIMIT 1");
            $chk->bind_param("i", $orderId);
            $chk->execute();
            $found = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($found) {
                error_log("Cancel mismatch: sent customer={$customerId}, dealer={$dealerId}, "
                    . "actual customer={$found['customer_id']}, dealer={$found['dealer_id']}");
            } else {
                error_log("Cancel: order id {$orderId} does not exist");
            }
        }
        throw new Exception('Order not found for this customer / dealer');
    }

    $orderId = (int) $order['id'];
    $orderNumber = $order['order_number'];
    $couponId = (int) $order['coupon_id'];
    $totalAmount = (float) $order['total_amount'];
    $subtotal = (float) $order['subtotal'];
    $discount = (float) $order['discount'];
    $couponCode = $order['coupon_code'];

    // Already cancelled?
    if ($order['status'] === 'cancelled' || $order['status'] === 'reverted') {
        throw new Exception('This order has already been reverted/cancelled');
    }

    // Only these statuses can be cancelled
    $allowedCancelStatuses = ['pending', 'confirmed', 'processing', 'completed'];

    if (empty($order['status']) || !in_array($order['status'], $allowedCancelStatuses)) {
        $displayStatus = ($order['status'] === '') ? '(empty - corrupted order)' : $order['status'];
        throw new Exception("Order cannot be cancelled (status: {$displayStatus})");
    }

    // 2. ITEMS NIKALO + PRODUCT NAMES CAPTURE
    $stmt = $db->prepare("
        SELECT id, product_id, product_name, quantity
        FROM order_items
        WHERE order_id = ?
    ");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $itemsResult = $stmt->get_result();

    $orderItems = [];
    $productNames = [];
    while ($row = $itemsResult->fetch_assoc()) {
        $orderItems[] = $row;
        $productNames[] = $row['product_name'] . ' (x' . $row['quantity'] . ')';
    }
    $stmt->close();

    if (empty($orderItems)) {
        throw new Exception('No items found for this order');
    }

    $productNamesString = implode(', ', $productNames);

    // 3. STOCK WAPAS ADD
    foreach ($orderItems as $oi) {
        $stmt = $db->prepare("
            UPDATE lube_products
            SET stock = stock + ?
            WHERE id = ?
        ");
        $stmt->bind_param("ii", $oi['quantity'], $oi['product_id']);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            $stmt->close();
            throw new Exception("Stock revert failed for product ID {$oi['product_id']}");
        }
        $stmt->close();
    }

    // 4. ORDER ITEMS DELETE
    $stmt = $db->prepare("DELETE FROM order_items WHERE order_id = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $stmt->close();

    // 5. COUPON UN-USE
    $couponReverted = false;

    if ($couponId > 0) {
        // Lock coupon
        $stmt = $db->prepare("SELECT id, status, used_at_station FROM coupons WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $couponId);
        $stmt->execute();
        $couponRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$couponRow) {
            throw new Exception('Coupon record not found for this order');
        }

        // Safety: coupon usi station pe use hua ho
        if (!empty($couponRow['used_at_station']) && $couponRow['used_at_station'] !== $stationName) {
            throw new Exception('This coupon was used at a different station and cannot be reverted here');
        }

        if ($couponRow['status'] === 'used') {
            // Coupon wapas available
            $stmt = $db->prepare("
                UPDATE coupons
                SET status = 'available',
                    used_at = NULL,
                    used_at_station = NULL
                WHERE id = ? AND status = 'used'
            ");
            $stmt->bind_param("i", $couponId);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                $stmt->close();
                throw new Exception('Coupon revert failed (already reverted?)');
            }
            $stmt->close();

            // Customer counters reset
            $stmt = $db->prepare("
                UPDATE hascol_customer
                SET remaining_coupons = remaining_coupons + 1,
                    used_coupons = CASE WHEN used_coupons > 0 THEN used_coupons - 1 ELSE 0 END
                WHERE id = ?
            ");
            $stmt->bind_param("i", $customerId);
            $stmt->execute();
            $stmt->close();

            $couponReverted = true;
        }
    }

    // 6. ORDER STATUS -> CANCELLED + REFUNDED
    $stmt = $db->prepare("
        UPDATE orders
        SET status = 'reverted',
            payment_status = 'refunded',
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $stmt->close();

    // 7. TRANSACTION REVERSAL  ✅ ab order_id se match hota hai
    $stmt = $db->prepare("
        UPDATE transactions
        SET status = 'failed', updated_at = NOW()
        WHERE order_id = ?
          AND amount >= 0
    ");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $txnUpdated = $stmt->affected_rows;
    $stmt->close();

    // Purane records (jin me order_id NULL hai) ke liye fallback
    if ($txnUpdated === 0) {
        $stmt = $db->prepare("
            UPDATE transactions
            SET status = 'failed', updated_at = NOW()
            WHERE order_id IS NULL
              AND customer_id = ?
              AND dealer_id = ?
              AND coupon_id = ?
              AND ABS(TIMESTAMPDIFF(SECOND, created_at, ?)) < 120
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param("iiis", $customerId, $dealerId, $couponId, $order['created_at']);
        $stmt->execute();
        $stmt->close();
    }

    // Reversal audit entry (negative amounts)
    $reversalRef = 'REV' . strtoupper(substr(md5(uniqid()), 0, 10));
    $reversalProductName = 'REVERSAL: ' . $productNamesString . ' | Reason: ' . $reason;

    // Negative amounts ko pehle variables mein store kiya (bind_param by reference)
    $negSubtotal = -$subtotal;
    $negDiscount = -$discount;
    $negTotalAmount = -$totalAmount;

    $stmt = $db->prepare("
        INSERT INTO transactions
        (customer_id, order_id, customer_name, dealer_id, player_key, player_id, station_name, product_name,
         amount, discount, final_amount,
         coupon_id, coupon_code, status, transaction_ref, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'failed', ?, NOW())
    ");
    $stmt->bind_param(
        "iisissssdddiss",
        $customerId,
        $orderId,
        $customer['name'],
        $dealerId,
        $customer['player_key'],
        $customer['player_id'],
        $stationName,
        $reversalProductName,
        $negSubtotal,
        $negDiscount,
        $negTotalAmount,
        $couponId,
        $couponCode,
        $reversalRef
    );
    $stmt->execute();
    $stmt->close();

    // 8. COMMIT — sab kuch permanent
    $db->commit();

    // Fresh customer counters (accurate response)
    $stmt = $db->prepare("SELECT remaining_coupons, used_coupons FROM hascol_customer WHERE id = ?");
    $stmt->bind_param("i", $customerId);
    $stmt->execute();
    $freshCustomer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    jsonResponse([
        'status' => 'success',
        'message' => 'Order reverted successfully. Stock, coupon and transaction restored.',
        'data' => [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'order_status' => 'reverted',
            'payment_status' => 'refunded',
            'coupon_id' => $couponId ?: null,
            'coupon_code' => $couponCode ?: null,
            'coupon_reverted' => $couponReverted,
            'refund_amount' => $totalAmount,
            'reversal_ref' => $reversalRef,
            'reason' => $reason,
            'items_reverted' => $productNamesString,
            'customer' => [
                'id' => (int) $customer['id'],
                'name' => $customer['name'],
                'remaining_coupons' => (int) ($freshCustomer['remaining_coupons'] ?? 0),
                'used_coupons' => (int) ($freshCustomer['used_coupons'] ?? 0),
            ],
        ],
    ]);

} catch (Exception $e) {
    $db->rollback();
    error_log('Cancel Order Error: ' . $e->getMessage());
    jsonResponse(['status' => 'error', 'message' => 'Revert failed: ' . $e->getMessage()]);
}
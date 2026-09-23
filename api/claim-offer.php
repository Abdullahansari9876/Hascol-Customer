<?php
/**
 * CLAIM OFFER API
 * 
 * POST /api/customer/claim-offer.php
 * Body: { customer_id, advertisement_id }
 * 
 * Advertisement se offer claim karta hai — coupon issue hota hai.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

$input           = getInput();
$customerId      = (int)($input['customer_id'] ?? 0);
$advertisementId = (int)($input['advertisement_id'] ?? 0);

// ─── Validation ───
if ($customerId <= 0) {
    jsonResponse(['status'=>'error','message'=>'customer_id required']);
}
if ($advertisementId <= 0) {
    jsonResponse(['status'=>'error','message'=>'advertisement_id required']);
}

// ─── Customer check ───
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

// ─── Advertisement check ───
$stmt = $db->prepare("
    SELECT * FROM advertisements 
    WHERE id = ? AND status = 'active' 
    LIMIT 1
");
$stmt->bind_param("i", $advertisementId);
$stmt->execute();
$advertisement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$advertisement) {
    jsonResponse(['status'=>'error','message'=>'Advertisement not found']);
}

// ─── Customer ne pehle claim kiya hai? ───
$stmt = $db->prepare("
    SELECT id FROM coupons 
    WHERE customer_id = ? 
      AND coupon_code LIKE ?
    LIMIT 1
");
$prefix = $advertisement['coupon_prefix'] . '_%';
$stmt->bind_param("is", $customerId, $prefix);
$stmt->execute();
$existingClaim = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existingClaim) {
    jsonResponse([
        'status'  => 'error',
        'message' => 'You have already claimed this offer'
    ]);
}

// ─── Coupon code generate karein ───
$random = strtoupper(substr(md5(uniqid($advertisement['coupon_prefix'] . microtime(), true)), 0, 6));
$couponCode = $advertisement['coupon_prefix'] . '_' . $random;

// ─── Coupon insert karein ───
$validFrom = date('Y-m-d H:i:s');
$validTo   = $advertisement['valid_to'];

$stmt = $db->prepare("
    INSERT INTO coupons 
    (customer_id, coupon_code, title, description, 
     discount_percent, discount_amount, min_purchase, 
     valid_from, valid_to, status) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'available')
");
$stmt->bind_param("isssddsss", 
    $customerId, $couponCode, 
    $advertisement['title'], $advertisement['description'], 
    $advertisement['discount_percent'], $advertisement['discount_amount'], 
    $advertisement['min_purchase'], 
    $validFrom, $validTo
);
if (!$stmt->execute()) {
    jsonResponse(['status'=>'error','message'=>'Coupon insert failed: ' . $stmt->error]);
}
$couponId = $stmt->insert_id;
$stmt->close();

// ─── Customers counts update karein ───
$stmt = $db->prepare("
    UPDATE customers 
    SET total_coupons = total_coupons + 1, 
        remaining_coupons = remaining_coupons + 1 
    WHERE id = ?
");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$stmt->close();

// ─── QR Code data generate karein ───
$qrContent = json_encode([
    'type'       => 'coupon_claim',
    'coupon_id'  => $couponId,
    'coupon_code'=> $couponCode,
    'customer_id'=> $customerId,
    'customer_name' => $customer['name'],
    'customer_mobile' => $customer['mobile'],
    'title'      => $advertisement['title'],
    'discount'   => $advertisement['discount_percent'],
    'claimed_at' => date('Y-m-d H:i:s'),
]);

// ─── Success ───
jsonResponse([
    'status'  => 'success',
    'message' => 'Offer claimed successfully',
    'coupon'  => [
        'id'               => (int)$couponId,
        'coupon_code'      => $couponCode,
        'title'            => $advertisement['title'],
        'description'      => $advertisement['description'],
        'discount_percent' => (float)$advertisement['discount_percent'],
        'discount_amount'  => (float)$advertisement['discount_amount'],
        'min_purchase'     => (float)$advertisement['min_purchase'],
        'valid_from'       => $validFrom,
        'valid_to'         => $validTo,
        'status'           => 'available',
    ],
    'qr_code' => [
        'content'     => $qrContent,
        'coupon_code' => $couponCode,
        'customer_id' => (int)$customerId,
    ],
    'customer' => [
        'id'     => (int)$customer['id'],
        'name'   => $customer['name'],
        'mobile' => $customer['mobile'],
    ],
]);
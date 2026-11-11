<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require '../config.php';
require '../db.php';

if (!isset($db) || $db->connect_error) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Invalid Request Method. Use POST."]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$customer_id = isset($input['customer_id']) ? intval($input['customer_id']) : 0;
$qr_id       = isset($input['qr_id']) ? intval($input['qr_id']) : 0;
$dealer_id   = isset($input['dealer_id']) ? intval($input['dealer_id']) : 0;
$product_id  = isset($input['product_id']) ? intval($input['product_id']) : 0;
$quantity    = isset($input['quantity']) ? floatval($input['quantity']) : 0;
$server_time = isset($input['server_time']) ? trim($input['server_time']) : '';

// server_time normalize
if (!empty($server_time)) {
    $server_time = str_replace('T', ' ', $server_time);
    $server_time = preg_replace('/\.\d+$/', '', $server_time);
}

// Validation
if ($customer_id <= 0 || $dealer_id <= 0 || $product_id <= 0 || $quantity <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Missing or invalid parameters."]);
    exit;
}

if ($qr_id <= 0 && empty($server_time)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Either 'qr_id' or 'server_time' is required to identify the QR code."]);
    exit;
}

$db->begin_transaction();

try {
    // 1. QR LOG CHECK — Priority: qr_id > server_time
    if ($qr_id > 0) {
        $stmt = $db->prepare("
            SELECT id, is_used, is_active, expires_at, server_time 
            FROM customer_qr_logs 
            WHERE id = ? AND customer_id = ? 
            FOR UPDATE
        ");
        $stmt->bind_param("ii", $qr_id, $customer_id);
    } else {
        $stmt = $db->prepare("
            SELECT id, is_used, is_active, expires_at, server_time 
            FROM customer_qr_logs 
            WHERE customer_id = ? AND server_time = ? 
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param("is", $customer_id, $server_time);
    }

    $stmt->execute();
    $qr_log = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // ❌ QR record nahi mila
    if (!$qr_log) {
        $check_stmt = $db->prepare("
            SELECT id, customer_id, server_time, is_used, is_active 
            FROM customer_qr_logs 
            WHERE id = ?
        ");
        $check_stmt->bind_param("i", $qr_id);
        $check_stmt->execute();
        $existing_qr = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();

        $latest_stmt = $db->prepare("
            SELECT id, server_time, expires_at 
            FROM customer_qr_logs 
            WHERE customer_id = ? AND is_active = 1 AND is_used = 0
            ORDER BY id DESC 
            LIMIT 1
        ");
        $latest_stmt->bind_param("i", $customer_id);
        $latest_stmt->execute();
        $latest = $latest_stmt->get_result()->fetch_assoc();
        $latest_stmt->close();

        $db->rollback();
        http_response_code(400);

        $debug = [
            "received_qr_id"       => $qr_id,
            "received_server_time" => $server_time,
            "customer_id"          => $customer_id,
            "qr_exists_in_db"      => $existing_qr ? true : false,
        ];

        if ($existing_qr) {
            $debug["qr_belongs_to_customer_id"] = intval($existing_qr['customer_id']);
            $debug["qr_is_used"]                 = intval($existing_qr['is_used']);
            $debug["qr_is_active"]               = intval($existing_qr['is_active']);
            $debug["qr_server_time"]             = $existing_qr['server_time'];

            if (intval($existing_qr['customer_id']) !== $customer_id) {
                $debug["hint"] = "This QR belongs to customer_id = " . $existing_qr['customer_id'] 
                               . ", not " . $customer_id . ". Generate a new QR for this customer.";
            } else {
                $debug["hint"] = "QR exists but with different parameters. Check your request.";
            }
        } else {
            $debug["hint"] = "This qr_id does not exist in database. Generate a new QR.";
        }

        $debug["latest_active_qr_id"]  = $latest ? intval($latest['id']) : null;
        $debug["latest_server_time"]   = $latest ? $latest['server_time'] : null;
        $debug["suggested_action"]     = $latest 
            ? "Use qr_id = " . $latest['id'] 
            : "Call GET /customer_usage.php?customer_id=" . $customer_id . " to generate new QR";

        echo json_encode([
            "success" => false,
            "status"  => "Inactive",
            "message" => "Invalid QR code. Record not found for this customer.",
            "debug"   => $debug
        ]);
        exit;
    }

    // ❌ QR already used
    if ($qr_log['is_used'] == 1) {
        $db->rollback();
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "status"  => "Inactive",
            "message" => "This QR code has already been used. Please generate a new QR code.",
            "debug"   => [
                "your_qr_id"       => intval($qr_log['id']),
                "your_is_used"     => intval($qr_log['is_used']),
                "your_is_active"   => intval($qr_log['is_active']),
                "your_server_time" => $qr_log['server_time']
            ]
        ]);
        exit;
    }

    // ❌ QR cancelled
    if ($qr_log['is_active'] == 0) {
        $latest_stmt = $db->prepare("
            SELECT id, server_time, expires_at 
            FROM customer_qr_logs 
            WHERE customer_id = ? AND is_active = 1 AND is_used = 0
            ORDER BY id DESC 
            LIMIT 1
        ");
        $latest_stmt->bind_param("i", $customer_id);
        $latest_stmt->execute();
        $latest = $latest_stmt->get_result()->fetch_assoc();
        $latest_stmt->close();

        $db->rollback();
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "status"  => "Inactive",
            "message" => "This QR code has been cancelled. Please use the latest QR code.",
            "debug"   => [
                "your_qr_id"          => intval($qr_log['id']),
                "your_is_used"        => intval($qr_log['is_used']),
                "your_is_active"      => intval($qr_log['is_active']),
                "your_server_time"    => $qr_log['server_time'],
                "latest_active_qr_id" => $latest ? intval($latest['id']) : null,
                "latest_server_time"  => $latest ? $latest['server_time'] : null,
                "hint"                => $latest 
                    ? "Use qr_id = " . $latest['id'] . " instead"
                    : "No active QR found. Generate a new QR via customer_usage.php"
            ]
        ]);
        exit;
    }

    // ❌ QR expired
    if ($qr_log['expires_at'] !== null && strtotime($qr_log['expires_at']) < time()) {
        $db->rollback();
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "status"  => "Expired",
            "message" => "This QR code has expired. Please generate a new QR code.",
            "debug"   => [
                "your_qr_id"     => intval($qr_log['id']),
                "your_expires_at"=> $qr_log['expires_at'],
                "current_time"   => date('Y-m-d H:i:s')
            ]
        ]);
        exit;
    }

    // FUEL PRODUCT CHECK
    $stmt = $db->prepare("SELECT id, name, unit, is_active FROM fuel_products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        $db->rollback();
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Fuel product not found."]);
        exit;
    }

    if (intval($product['is_active']) !== 1) {
        $db->rollback();
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "This fuel product is currently inactive."]);
        exit;
    }

    // FUEL RATE
    $stmt = $db->prepare("SELECT rate FROM fuel_rates WHERE dealer_id = ? AND product_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("ii", $dealer_id, $product_id);
    $stmt->execute();
    $rate_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$rate_row) {
        $db->rollback();
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Fuel rate not found for this dealer and product."]);
        exit;
    }

    // DEALER INFO FETCH 
    $stmt = $db->prepare("SELECT id, name, station_name FROM hascol_dealers WHERE id = ?");
    $stmt->bind_param("i", $dealer_id);
    $stmt->execute();
    $dealer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$dealer) {
        $db->rollback();
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Dealer not found."]);
        exit;
    }

    // ✅ Dealer ki station_name use karo (agar khali ho to dealer ka name)
    $station_name = !empty($dealer['station_name']) ? $dealer['station_name'] : $dealer['name'];

    $product_name   = $product['name'];
    $unit           = $product['unit'] ?? 'Litre';
    $price_per_unit = floatval($rate_row['rate']);

    $subtotal        = $price_per_unit * $quantity;
    $coupon_discount = 1 * $quantity;
    $total_discount  = $coupon_discount;
    $total_amount    = $subtotal - $total_discount;
    if ($total_amount < 0) $total_amount = 0;

    // MONTHLY LIMIT CHECK
    $monthly_limit = 250;
    $current_month = date('Y-m');

    $stmt = $db->prepare("
        SELECT COALESCE(SUM(oi.quantity), 0) AS total_used
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        WHERE o.customer_id = ?
          AND o.order_type = 'fuel'
          AND DATE_FORMAT(o.created_at, '%Y-%m') = ?
          AND o.status IN ('pending', 'confirmed', 'processing', 'completed')
    ");
    $stmt->bind_param("is", $customer_id, $current_month);
    $stmt->execute();
    $used_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $total_used_litres = floatval($used_data['total_used']);
    $remaining_limit   = $monthly_limit - $total_used_litres;

    if ($quantity > $remaining_limit) {
        $db->rollback();
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Insufficient fuel limit. Remaining: " . $remaining_limit . " Litres"
        ]);
        exit;
    }

    // INSERT ORDER (station_name included)
    $order_number = 'ORD-' . strtoupper(uniqid());
    $player_key   = 'test_key';
    $qr_used_at   = date('Y-m-d H:i:s');

    $stmt = $db->prepare("
        INSERT INTO orders 
        (order_number, order_type, customer_id, dealer_id, player_key, subtotal, discount, total_amount, status, payment_status, station_name, qr_status, qr_used, qr_used_at, created_at) 
        VALUES (?, 'fuel', ?, ?, ?, ?, ?, ?, 'confirmed', 'paid', ?, 'inactive', 1, ?, NOW())
    ");
    $stmt->bind_param("siiidddss", $order_number, $customer_id, $dealer_id, $player_key, $subtotal, $total_discount, $total_amount, $station_name, $qr_used_at);

    if (!$stmt->execute()) {
        throw new Exception("Failed to create order: " . $stmt->error);
    }
    $order_id = $db->insert_id;
    $stmt->close();

    // INSERT ORDER ITEMS
    $product_sku      = NULL;
    $product_brand    = NULL;
    $discount_percent = 0.00;

    $stmt = $db->prepare("
        INSERT INTO order_items 
        (order_id, product_id, product_sku, product_name, product_brand, coupon_id, quantity, price, discount_percent, subtotal, created_at) 
        VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("iisssiddd", $order_id, $product_id, $product_sku, $product_name, $product_brand, $quantity, $price_per_unit, $discount_percent, $subtotal);

    if (!$stmt->execute()) {
        throw new Exception("Failed to create order item: " . $stmt->error);
    }
    $stmt->close();

    // INSERT TRANSACTION (station_name included)
    $transaction_ref = 'TXN-' . strtoupper(uniqid());
    $customer_name   = '';

    $stmt = $db->prepare("SELECT name FROM hascol_customer WHERE id = ?");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $cust_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($cust_row) {
        $customer_name = $cust_row['name'];
    }

    $stmt = $db->prepare("
        INSERT INTO transactions 
        (customer_id, order_id, customer_name, dealer_id, player_key, player_id, 
         station_name, product_name, amount, discount, final_amount, 
         coupon_id, coupon_code, order_type, status, transaction_ref, 
         created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, NULL, 
                ?, ?, ?, ?, ?, 
                NULL, NULL, 'fuel', 'successful', ?, 
                NOW(), NOW())
    ");

    $player_id = NULL;

    $stmt->bind_param(
        "iisisssddds",
        $customer_id,
        $order_id,
        $customer_name,
        $dealer_id,
        $player_key,
        $station_name,     
        $product_name,
        $subtotal,
        $total_discount,
        $total_amount,
        $transaction_ref
    );

    if (!$stmt->execute()) {
        throw new Exception("Failed to create transaction: " . $stmt->error);
    }
    $transaction_id = $db->insert_id;
    $stmt->close();

    // MARK QR AS USED
    $stmt = $db->prepare("UPDATE customer_qr_logs SET is_used = 1, is_active = 0 WHERE id = ?");
    $stmt->bind_param("i", $qr_log['id']);
    if (!$stmt->execute()) {
        throw new Exception("Failed to update QR status: " . $stmt->error);
    }
    $stmt->close();

    // FETCH TRANSACTION STATUS
    $stmt = $db->prepare("
        SELECT status AS transaction_status 
        FROM transactions 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $transaction_id);
    $stmt->execute();
    $txn_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $db->commit();

    // RESPONSE
    echo json_encode([
        "success"           => true,
        "message"           => "Fuel order placed successfully.",
        "order_id"          => $order_id,
        "order_number"      => $order_number,
        "qr_id"             => intval($qr_log['id']),
        "transaction_id"    => $transaction_id,
        "transaction_ref"   => $transaction_ref,
        "status"            => $txn_row['transaction_status'],
        "order_details" => [
            "product_name"   => $product_name,
            "unit"           => $unit,
            "quantity"       => $quantity,
            "price_per_unit" => $price_per_unit,
            "subtotal"       => $subtotal,
            "total_discount" => $total_discount,
            "total_amount"   => $total_amount,
            "station_name"   => $station_name        
        ]
    ]);

} catch (Exception $e) {
    $db->rollback();
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}
?>
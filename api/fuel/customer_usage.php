<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require '../config.php';
require '../db.php';

if (!isset($db) || $db->connect_error) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Invalid Request Method. Use GET."]);
    exit;
}

$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$qr_id       = isset($_GET['qr_id']) ? intval($_GET['qr_id']) : 0;          
$input_time  = isset($_GET['server_time']) ? trim($_GET['server_time']) : '';

// Normalize time
if (!empty($input_time)) {
    $input_time = str_replace('T', ' ', $input_time);
    $input_time = preg_replace('/\.\d+$/', '', $input_time);
}

if ($customer_id <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid or missing Customer ID"]);
    exit;
}

try {
    // 1. Customer
    $stmt = $db->prepare("SELECT id, name, email, mobile FROM hascol_customer WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$customer) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Customer not found or inactive"]);
        exit;
    }

    // 2. Config
    $monthly_limit      = 250;
    $discount_per_litre = 1;
    $current_month      = date('Y-m');

    // 3. Litres used
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

    $total_litres_used = floatval($used_data['total_used']);
    $remaining_limit   = max(0, $monthly_limit - $total_litres_used);
    $total_savings     = $total_litres_used * $discount_per_litre;

    // 4. Amount spent
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(o.total_amount), 0) AS total_spent
        FROM orders o
        WHERE o.customer_id = ?
          AND o.order_type = 'fuel'
          AND DATE_FORMAT(o.created_at, '%Y-%m') = ?
          AND o.status IN ('pending', 'confirmed', 'processing', 'completed')
    ");
    $stmt->bind_param("is", $customer_id, $current_month);
    $stmt->execute();
    $spent_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $total_amount_spent = floatval($spent_data['total_spent']);

    // 5. QR Handling
    if ($qr_id > 0) {
        // ============ SCENARIO A1: Exact QR ID se check ============
        $stmt = $db->prepare("
            SELECT id, customer_id, is_used, is_active, expires_at, server_time
            FROM customer_qr_logs 
            WHERE id = ? AND customer_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $qr_id, $customer_id);
        $stmt->execute();
        $qr_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$qr_row) {
            http_response_code(404);
            echo json_encode([
                "success" => false, 
                "message" => "QR code record not found for this QR ID.",
                "received_qr_id" => $qr_id
            ]);
            exit;
        }

        $qr_id_final  = intval($qr_row['id']);
        $server_time  = $qr_row['server_time'];
        $expires_at   = $qr_row['expires_at'];

        if (intval($qr_row['is_used']) === 1) {
            $status = "Inactive";
        } elseif (intval($qr_row['is_active']) === 0) {
            $status = "Inactive";
        } elseif ($expires_at !== null && strtotime($expires_at) < time()) {
            $status = "Expired";
        } else {
            $status = "Active";
        }

    } elseif (!empty($input_time)) {
        // ============ SCENARIO A2: server_time se check ============
        $stmt = $db->prepare("
            SELECT id, customer_id, is_used, is_active, expires_at, server_time
            FROM customer_qr_logs 
            WHERE customer_id = ? AND server_time = ? 
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param("is", $customer_id, $input_time);
        $stmt->execute();
        $qr_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$qr_row) {
            http_response_code(404);
            echo json_encode([
                "success" => false, 
                "message" => "QR code record not found.",
                "received_server_time" => $input_time
            ]);
            exit;
        }

        $qr_id_final  = intval($qr_row['id']);
        $server_time  = $input_time;
        $expires_at   = $qr_row['expires_at'];

        if (intval($qr_row['is_used']) === 1) {
            $status = "Inactive";
        } elseif (intval($qr_row['is_active']) === 0) {
            $status = "Inactive";
        } elseif ($expires_at !== null && strtotime($expires_at) < time()) {
            $status = "Expired";
        } else {
            $status = "Active";
        }

    } else {
        // ============ SCENARIO B: Naya QR Generate ============
        $server_time = date('Y-m-d H:i:s');
        $expires_at  = date('Y-m-d H:i:s', strtotime('+2 minutes'));

        // Purane active QRs cancel
        $stmt = $db->prepare("UPDATE customer_qr_logs SET is_active = 0 WHERE customer_id = ? AND is_active = 1");
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $stmt->close();

        // Naya QR
        $stmt = $db->prepare("
            INSERT INTO customer_qr_logs 
            (customer_id, server_time, expires_at, is_used, is_active) 
            VALUES (?, ?, ?, 0, 1)
        ");
        $stmt->bind_param("iss", $customer_id, $server_time, $expires_at);
        if (!$stmt->execute()) {
            throw new Exception("Failed to log QR generation: " . $stmt->error);
        }
        $qr_id_final = intval($db->insert_id);
        $stmt->close();

        $status = "Active";
    }

    // 6. Response
    echo json_encode([
        "success" => true,
        "data" => [
            "qr_id"              => $qr_id_final,            
            "customer_id"        => $customer['id'],
            "name"               => $customer['name'],
            "email"              => $customer['email'],
            "mobile"             => $customer['mobile'],
            "server_time"        => $server_time,
            "expires_at"         => $expires_at,
            "monthly_limit"      => $monthly_limit,
            "total_litres_used"  => $total_litres_used,
            "remaining_limit"    => $remaining_limit,
            "discount_per_litre" => $discount_per_litre,
            "total_savings"      => $total_savings,
            "total_amount_spent" => $total_amount_spent,
            "status"             => $status
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}
?>
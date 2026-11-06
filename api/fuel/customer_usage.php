<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require '../config.php';
require '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    $db = getDBConnection();

    // 1. CUSTOMER_ID
    if (empty($_GET['customer_id'])) {
        throw new Exception('customer_id is required');
    }
    $customer_id = (int)$_GET['customer_id'];

    // 2. CUSTOMER EXIST
    $custSql = "SELECT id, name FROM hascol_customer 
                WHERE id = $customer_id AND status = 'active' LIMIT 1";
    $custResult = $db->query($custSql);
    if (!$custResult || $custResult->num_rows === 0) {
        throw new Exception('Customer not found or inactive');
    }
    $customer = $custResult->fetch_assoc();

    // 3. MONTHLY DISCOUNT LIMIT CONFIG
    $monthly_discount_limit = 250.00;
    $discount_per_litre     = 1.00;

    // 4. MONTH RANGE
    $monthStart = date('Y-m-01 00:00:00');
    $monthEnd   = date('Y-m-t 23:59:59');

    // 5. USAGE QUERY
    $usageSql = "SELECT 
                    COALESCE(SUM(oi.quantity), 0) AS total_litres,
                    COALESCE(SUM(o.discount), 0)  AS total_discount_used,
                    COALESCE(SUM(o.total_amount), 0) AS total_amount_paid,
                    COUNT(DISTINCT o.id) AS total_orders
                 FROM orders o
                 INNER JOIN order_items oi ON oi.order_id = o.id
                 WHERE o.customer_id = $customer_id
                   AND o.order_type = 'fuel'
                   AND o.status IN ('pending','confirmed','processing','completed')
                   AND o.created_at BETWEEN '$monthStart' AND '$monthEnd'";

    $usageResult = $db->query($usageSql);
    if (!$usageResult) {
        throw new Exception('Usage query failed: ' . $db->error);
    }
    $usage = $usageResult->fetch_assoc();

    $used_litres       = (float)$usage['total_litres'];
    $discount_used     = (float)$usage['total_discount_used'];
    $total_amount_paid = (float)$usage['total_amount_paid'];
    $total_orders      = (int)$usage['total_orders'];

    // 6. REMAINING DISCOUNT
    $remaining_discount = $monthly_discount_limit - $discount_used;
    if ($remaining_discount < 0) {
        $remaining_discount = 0;
    }

    $usage_percent = $monthly_discount_limit > 0
        ? round(($discount_used / $monthly_discount_limit) * 100, 2)
        : 0;

    // ============================================
    // 7. QR STATUS — SIRF SERVER TIME SE MATCH
    //    Latest fuel order ka created_at server time se compare
    //    Valid time = 5 minutes (aap change kar sakte ho)
    // ============================================
    $server_time      = date('Y-m-d H:i:s');
    $qr_valid_minutes = 5;   // ← yahan time change karo agar 5 min nahi chahiye

    $qrSql = "SELECT id, order_number, created_at 
              FROM orders 
              WHERE customer_id = $customer_id 
                AND order_type = 'fuel'
                AND status IN ('pending','confirmed','processing','completed')
              ORDER BY id DESC LIMIT 1";
    $qrResult = $db->query($qrSql);

    $qr_info = null;

    if ($qrResult && $qrResult->num_rows > 0) {
        $latest_order = $qrResult->fetch_assoc();

        // Server time se compare (minutes me difference)
        $created_ts   = strtotime($latest_order['created_at']);
        $server_ts    = strtotime($server_time);
        $diff_minutes = ($server_ts - $created_ts) / 60;

        // Status decide
        if ($diff_minutes <= $qr_valid_minutes) {
            $qr_status = 'active';
            $is_active = true;
        } else {
            $qr_status = 'inactive';
            $is_active = false;
        }

        $qr_info = [
            'order_id'        => (int)$latest_order['id'],
            'order_number'    => $latest_order['order_number'],
            'status'          => $qr_status,          // active | inactive
            'is_active'       => $is_active,
            'valid_minutes'   => $qr_valid_minutes,
            'qr_time'         => $latest_order['created_at'],
            'minutes_ago'     => round($diff_minutes, 2),
            'expires_at'      => date('Y-m-d H:i:s', strtotime($latest_order['created_at'] . ' +' . $qr_valid_minutes . ' minutes'))
        ];
    }

    // 8. RESPONSE
    echo json_encode([
        'status'  => true,
        'message' => 'Customer fuel usage fetched successfully',
        'data' => [
            'customer' => [
                'id'   => (int)$customer['id'],
                'name' => $customer['name']
            ],
            'month'                    => date('Y-m'),
            'month_start'              => $monthStart,
            'month_end'                => $monthEnd,

            'monthly_discount_limit'   => $monthly_discount_limit,
            'discount_per_litre'       => $discount_per_litre,
            'discount_used'            => $discount_used,
            'remaining_discount'       => $remaining_discount,
            'usage_percent'            => $usage_percent,

            'total_litres_used'        => $used_litres,
            'total_amount_paid'        => $total_amount_paid,
            'total_orders'             => $total_orders,

            'qr_info'                  => $qr_info,     // ← QR status

            'server_time'              => $server_time
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => $e->getMessage()]);
}
<?php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

// TODO: yahan apna admin auth check lagayein (jaisa customers APIs mein hai)

$input  = getInput();
$status = trim($input['status'] ?? '');

$sql = "
    SELECT r.id, r.customer_id, r.old_imei, r.new_imei, r.reason, r.contact,
           r.status, r.admin_id, r.admin_note, r.created_at, r.processed_at,
           c.name, c.mobile, c.cnic, c.address, c.coupon_no,
           c.status AS customer_status, c.imei AS current_imei,
           (SELECT COUNT(*) FROM hascol_customer x
             WHERE x.imei = r.new_imei AND x.id <> r.customer_id) AS imei_in_use
    FROM hascol_device_change_requests r
    JOIN hascol_customer c ON c.id = r.customer_id
";

$useFilter = in_array($status, ['pending', 'approved', 'rejected'], true);
if ($useFilter) $sql .= " WHERE r.status = ?";
$sql .= " ORDER BY r.id DESC";

$stmt = $db->prepare($sql);
if ($useFilter) $stmt->bind_param("s", $status);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as &$row) {
    $row['id']          = (int)$row['id'];
    $row['customer_id'] = (int)$row['customer_id'];
    $row['imei_in_use'] = (int)$row['imei_in_use'];
}
unset($row);

jsonResponse(['status' => 'success', 'data' => $rows]);
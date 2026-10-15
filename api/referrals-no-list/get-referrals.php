<?php

// ✅ Sirf OPTIONS handle karein
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

$input       = getInput();
$customer_id = isset($input['customer_id']) ? (int)$input['customer_id'] : 0;
$is_used     = $input['is_used'] ?? '';
$search      = trim($input['search'] ?? '');
$date_from   = trim($input['date_from'] ?? '');
$date_to     = trim($input['date_to'] ?? '');

$where  = [];
$params = [];
$types  = '';

if ($customer_id > 0) {
    $where[] = "r.used_by_customer_id = ?";
    $params[] = $customer_id;
    $types .= 'i';
}

if ($is_used !== '' && ($is_used === '0' || $is_used === '1' || $is_used === 0 || $is_used === 1)) {
    $where[] = "r.is_used = ?";
    $params[] = (int)$is_used;
    $types .= 'i';
}

if ($search !== '') {
    $where[] = "r.referral_no LIKE ?";
    $params[] = '%' . $search . '%';
    $types .= 's';
}

if ($date_from !== '') {
    $where[] = "DATE(r.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to !== '') {
    $where[] = "DATE(r.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

// ─── SELECT: ab user_name + referred_by_customer_id bhi include ───
// ─── JOIN: do LEFT JOINs — used_by aur referred_by dono ke liye ───
$sql = "
    SELECT 
        r.id, 
        r.referral_no, 
        r.user_name, 
        r.referred_by_customer_id, 
        r.is_used, 
        r.used_by_customer_id, 
        r.used_at, 
        r.created_at,
        c1.name   AS used_by_name,
        c1.mobile AS used_by_mobile,
        c1.email  AS used_by_email,
        c2.name   AS referred_by_name,
        c2.mobile AS referred_by_mobile
    FROM referral_numbers r
    LEFT JOIN hascol_customer c1 ON c1.id = r.used_by_customer_id
    LEFT JOIN hascol_customer c2 ON c2.id = r.referred_by_customer_id
";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY r.id DESC";

$stmt = $db->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$referrals = [];
$total_used = 0;
$total_unused = 0;

while ($row = $result->fetch_assoc()) {
    if ((int)$row['is_used'] === 1) $total_used++;
    else $total_unused++;

    $referrals[] = [
        'id'                      => (int)$row['id'],
        'referral_no'             => $row['referral_no'],
        // ─── NAYE FIELDS ───
        'user_name'               => $row['user_name'] ?? null,
        'referred_by_customer_id' => $row['referred_by_customer_id'] ? (int)$row['referred_by_customer_id'] : null,
        'referred_by_name'        => $row['referred_by_name'] ?? null,
        'referred_by_mobile'      => $row['referred_by_mobile'] ?? null,
        // ─── EXISTING FIELDS ───
        'is_used'                 => (int)$row['is_used'],
        'used_by_customer_id'     => $row['used_by_customer_id'] ? (int)$row['used_by_customer_id'] : null,
        'used_by_name'            => $row['used_by_name'] ?? null,
        'used_by_mobile'          => $row['used_by_mobile'] ?? null,
        'used_by_email'           => $row['used_by_email'] ?? null,
        'used_at'                 => $row['used_at'],
        'created_at'              => $row['created_at'],
    ];
}
$stmt->close();

jsonResponse([
    'status'       => 'success',
    'message'      => 'Referral numbers fetched successfully',
    'total'        => count($referrals),
    'total_used'   => $total_used,
    'total_unused' => $total_unused,
    'referrals'    => $referrals,
]);
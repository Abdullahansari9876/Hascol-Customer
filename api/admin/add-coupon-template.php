<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require '../config.php';
require '../db.php';

$input = getInput();

$title = trim($input['title'] ?? '');
$description = trim($input['description'] ?? '');
$image_base64 = $input['image_base64'] ?? '';
$type = $input['discount_type'] ?? 'percent';
$value = (float) ($input['discount_value'] ?? 0);
$valid_from = trim($input['valid_from'] ?? '');
$valid_to = trim($input['valid_to'] ?? '');

if (empty($title)) jsonResponse(['status' => 'error', 'message' => 'Title is required']);
if (empty($valid_from) || empty($valid_to)) jsonResponse(['status' => 'error', 'message' => 'Valid From and Valid To dates are required']);

$percent = ($type === 'percent') ? $value : 0.00;
$amount = ($type === 'flat') ? $value : 0.00;
$valid_from_db = date('Y-m-d H:i:s', strtotime($valid_from));
$valid_to_db = date('Y-m-d H:i:s', strtotime($valid_to));

$image_url = '';
if (!empty($image_base64)) {
    $upload_dir = '../../uploads/coupons/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    
    $image_parts = explode(";base64,", $image_base64);
    if(count($image_parts) == 2) {
        $image_type = explode("image/", $image_parts[0])[1];
        $image_base64_data = base64_decode($image_parts[1]);
        $file_name = uniqid() . '.' . $image_type;
        
        if (file_put_contents($upload_dir . $file_name, $image_base64_data)) {
            $domain = 'http://localhost:8080/hascol_customer'; // Apna domain yahan dalein
            $image_url = $domain . '/uploads/coupons/' . $file_name;
        }
    }
}

$stmt = $db->prepare("INSERT INTO coupon_templates (title, description, image_url, valid_from, valid_to, discount_percent, discount_amount, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
$stmt->bind_param("sssssdd", $title, $description, $image_url, $valid_from_db, $valid_to_db, $percent, $amount);

if ($stmt->execute()) {
    jsonResponse(['status' => 'success', 'message' => 'Template created successfully']);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Failed to create: ' . $stmt->error]);
}
$stmt->close();
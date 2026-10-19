<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require '../config.php';
require '../db.php';

$input = getInput();

$id = (int) ($input['id'] ?? 0);
$title = trim($input['title'] ?? '');
$description = trim($input['description'] ?? '');
$image_base64 = $input['image_base64'] ?? '';
$type = $input['discount_type'] ?? 'percent';
$value = (float) ($input['discount_value'] ?? 0);
$valid_from = trim($input['valid_from'] ?? '');
$valid_to = trim($input['valid_to'] ?? '');

if ($id <= 0) jsonResponse(['status' => 'error', 'message' => 'Invalid ID']);
if (empty($title)) jsonResponse(['status' => 'error', 'message' => 'Title is required']);

$percent = ($type === 'percent') ? $value : 0.00;
$amount = ($type === 'flat') ? $value : 0.00;
$valid_from_db = date('Y-m-d H:i:s', strtotime($valid_from));
$valid_to_db = date('Y-m-d H:i:s', strtotime($valid_to));

$new_image_url = null;
if (!empty($image_base64)) {
    $upload_dir = '../../uploads/coupons/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    
    $image_parts = explode(";base64,", $image_base64);
    if(count($image_parts) == 2) {
        $image_type = explode("image/", $image_parts[0])[1];
        $image_base64_data = base64_decode($image_parts[1]);
        $file_name = uniqid() . '.' . $image_type;
        
        if (file_put_contents($upload_dir . $file_name, $image_base64_data)) {
            $domain = 'http://localhost:8080/hascol_customer';
            $new_image_url = $domain . '/uploads/coupons/' . $file_name;
        }
    }
}

if ($new_image_url !== null) {
    $stmt = $db->prepare("UPDATE coupon_templates SET title = ?, description = ?, image_url = ?, valid_from = ?, valid_to = ?, discount_percent = ?, discount_amount = ? WHERE id = ?");
    $stmt->bind_param("sssssddi", $title, $description, $new_image_url, $valid_from_db, $valid_to_db, $percent, $amount, $id);
} else {
    $stmt = $db->prepare("UPDATE coupon_templates SET title = ?, description = ?, valid_from = ?, valid_to = ?, discount_percent = ?, discount_amount = ? WHERE id = ?");
    $stmt->bind_param("ssssddi", $title, $description, $valid_from_db, $valid_to_db, $percent, $amount, $id);
}

if ($stmt->execute()) {
    jsonResponse(['status' => 'success', 'message' => 'Template updated successfully']);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Failed to update: ' . $stmt->error]);
}
$stmt->close();
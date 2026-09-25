<?php
/**
 * CHECK MOBILE API
 * POST /api/check-mobile.php
 * Body: { mobile }
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'config.php';
require 'db.php';

$input  = getInput();
$mobile = trim($input['mobile'] ?? '');

if (empty($mobile)) {
    jsonResponse(['status'=>'error','message'=>'mobile required']);
}

if (!preg_match('/^(03[0-9]{9}|\+923[0-9]{9})$/', $mobile)) {
    jsonResponse(['status'=>'error','message'=>'Invalid mobile number (e.g., 03001234567)']);
}

$stmt = $db->prepare("SELECT id, player_id, name, mobile FROM hascol_customer WHERE mobile = ? LIMIT 1");
$stmt->bind_param("s", $mobile);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    jsonResponse([
        'status'  => 'error',
        'message' => 'This mobile number is not registered'
    ]);
}

jsonResponse([
    'status'  => 'success',
    'message' => 'Mobile verified. Please enter your new password.',
    'mobile'  => $customer['mobile'],
    'name'    => $customer['name'],
]);
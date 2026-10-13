<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

$input    = getInput();
$mobile   = trim($input['mobile'] ?? '');
$password = trim($input['password'] ?? '');

if ($mobile === '')   jsonResponse(['status'=>'error','message'=>'mobile required']);
if ($password === '') jsonResponse(['status'=>'error','message'=>'password required']);

$stmt = $db->prepare("SELECT id, password FROM hascol_customer WHERE mobile = ? LIMIT 1");
$stmt->bind_param("s", $mobile);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer || !password_verify($password, (string)$customer['password'])) {
    jsonResponse(['status'=>'error','message'=>'Invalid mobile or password']);
}

$stmt = $db->prepare("
    SELECT id, status, admin_note, created_at, processed_at
    FROM hascol_device_change_requests
    WHERE customer_id = ?
    ORDER BY id DESC LIMIT 1
");
$stmt->bind_param("i", $customer['id']);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$req) {
    jsonResponse(['status'=>'success','request'=>null]);
}

jsonResponse([
    'status'  => 'success',
    'request' => [
        'id'           => (int)$req['id'],
        'status'       => $req['status'],
        'admin_note'   => $req['admin_note'] ?? '',
        'created_at'   => $req['created_at'],
        'processed_at' => $req['processed_at'],
    ],
]);
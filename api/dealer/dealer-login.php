<?php
/**
 * DEALER LOGIN API (Simple — No Session Table)
 * 
 * POST /api/dealer/dealer-login.php
 * Body: { mobile, password }
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

$input    = getInput();
$mobile   = trim($input['mobile'] ?? '');
$password = trim($input['password'] ?? '');

// ─── Validation ───
if (empty($mobile)) {
    jsonResponse(['status'=>'error','message'=>'mobile required']);
}
if (empty($password)) {
    jsonResponse(['status'=>'error','message'=>'password required']);
}

// Mobile format check (Pakistan)
if (!preg_match('/^(03[0-9]{9}|\+923[0-9]{9})$/', $mobile)) {
    jsonResponse(['status'=>'error','message'=>'Invalid mobile number (e.g., 03001234567)']);
}

// ─── Dealer dhoondein mobile se ───
$stmt = $db->prepare("
    SELECT id, name, mobile, email, password, 
           station_name, address, city, status 
    FROM dealers
    WHERE mobile = ? 
    LIMIT 1
");
$stmt->bind_param("s", $mobile);
$stmt->execute();
$dealer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$dealer) {
    jsonResponse(['status'=>'error','message'=>'Mobile number not registered']);
}

// ─── Password verify (PLAIN) ───
if ($password !== $dealer['password']) {
    jsonResponse(['status'=>'error','message'=>'Invalid password']);
}

// ─── Status check ───
if ($dealer['status'] !== 'active') {
    jsonResponse(['status'=>'error','message'=>'Account is ' . $dealer['status']]);
}
    
// ─── Last login update karein ───
$stmt = $db->prepare("UPDATE dealers SET last_login = NOW() WHERE id = ?");
$stmt->bind_param("i", $dealer['id']);
$stmt->execute();
$stmt->close();

// ─── Success (Token nahi, sirf dealer detail) ───
jsonResponse([
    'status'  => 'success',
    'message' => 'Login successful',
    'dealer'  => [
        'id'           => (int)$dealer['id'],
        'name'         => $dealer['name'],
        'mobile'       => $dealer['mobile'],
        'email'        => $dealer['email'] ?? '',
        'station_name' => $dealer['station_name'],
        'address'      => $dealer['address'] ?? '',
        'city'         => $dealer['city'] ?? '',
        'status'       => $dealer['status'],
    ],
]);
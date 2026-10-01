<?php
/**
 * CUSTOMER PASSWORD CHECK API
 * 
 * POST /hascol_customer/api/customer/check-password.php
 * 
 * Fields:
 *   - customer_id  (required)
 *   - password     (required)
 */

// ─── CORS / OPTIONS ───
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

// ─── Sirf POST allow ───
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(['status' => 'error', 'message' => 'Only POST method allowed']);
}

// ─── Input lo (JSON ya form-data) ───
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = (stripos($contentType, 'application/json') !== false)
    ? getInput()
    : $_POST;

$customerId = (int)($input['customer_id'] ?? 0);
$password   = $input['password'] ?? '';

// ─── Validation ───
if ($customerId <= 0) {
    http_response_code(400);
    jsonResponse(['status' => 'error', 'message' => 'customer_id is required']);
}

if ($password === '') {
    http_response_code(400);
    jsonResponse(['status' => 'error', 'message' => 'password is required']);
}

// ─── Customer ka password DB se lo ───
$stmt = $db->prepare("SELECT id, password, status FROM hascol_customer WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ─── Customer nahi mila ───
if (!$customer) {
    http_response_code(404);
    jsonResponse(['status' => 'error', 'message' => 'Customer not found']);
}

// ─── Password NULL check ───
if ($customer['password'] === null || $customer['password'] === '') {
    http_response_code(400);
    jsonResponse(['status' => 'error', 'message' => 'Password not set for this customer']);
}

// ─── Password check (Hashed password verify) ───
if (!password_verify($password, $customer['password'])) {
    http_response_code(401);
    jsonResponse(['status' => 'error', 'message' => 'Incorrect password']);
}

// ─── Success ───
http_response_code(200);
jsonResponse([
    'status'  => 'success',
    'message' => 'Password verified successfully',
]);
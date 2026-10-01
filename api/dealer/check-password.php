<?php
/**
 * DEALER PASSWORD CHECK API 
 * 
 * POST /hascol_customer/api/dealer/check-password.php
 * 
 * Fields:
 *   - dealer_id  (required)
 *   - password   (required)
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

$dealerId = (int)($input['dealer_id'] ?? 0);
$password = $input['password'] ?? '';

// ─── Validation ───
if ($dealerId <= 0) {
    http_response_code(400);
    jsonResponse(['status' => 'error', 'message' => 'dealer_id is required']);
}

if ($password === '') {
    http_response_code(400);
    jsonResponse(['status' => 'error', 'message' => 'password is required']);
}

// ─── Dealer ka password DB se lo ───
$stmt = $db->prepare("SELECT id, password FROM hascol_dealers WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $dealerId);
$stmt->execute();
$dealer = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ─── Dealer nahi mila ───
if (!$dealer) {
    http_response_code(404);
    jsonResponse(['status' => 'error', 'message' => 'Dealer not found']);
}

// ─── Password check (Plain text comparison) ───
if ($password !== $dealer['password']) {
    http_response_code(401);
    jsonResponse(['status' => 'error', 'message' => 'Incorrect password']);
}

// ─── Success ───
http_response_code(200);
jsonResponse([
    'status'  => 'success',
    'message' => 'Password verified successfully',
]);
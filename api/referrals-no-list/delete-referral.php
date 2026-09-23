<?php
/**
 * DELETE REFERRAL NUMBER API
 * POST /api/referrals-no-list/delete-referral.php
 * Body: { id }
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

$input = getInput();
$id = isset($input['id']) ? (int)$input['id'] : 0;

if ($id <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'Valid ID required']);
    exit;
}

// Check exists
$stmt = $db->prepare("SELECT id, referral_no, is_used FROM referral_numbers WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    jsonResponse(['status' => 'error', 'message' => 'Referral number not found']);
    exit;
}

// Prevent deleting a used referral
if ((int)$row['is_used'] === 1) {
    jsonResponse([
        'status'  => 'error',
        'message' => 'Cannot delete a used referral number'
    ]);
    exit;
}

$stmt = $db->prepare("DELETE FROM referral_numbers WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    $stmt->close();
    jsonResponse(['status' => 'success', 'message' => 'Referral number deleted successfully']);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
}
$stmt->close();
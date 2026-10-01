<?php
// Hascol Customer - Update Referral Number

// ✅ Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);

require '../config.php';
require '../db.php';

$input = getInput();

$id         = isset($input['id']) ? (int)$input['id'] : 0;
$referralNo = isset($input['referral_no']) ? trim($input['referral_no']) : '';

// ─── Validation ───
if ($id <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'Valid ID required']);
    exit;
}

if ($referralNo === '') {
    jsonResponse(['status' => 'error', 'message' => 'Referral number is required']);
    exit;
}

if (strlen($referralNo) > 50) {
    jsonResponse(['status' => 'error', 'message' => 'Referral number cannot exceed 50 characters']);
    exit;
}

// ─── Check row exists ───
$stmt = $db->prepare("SELECT id, referral_no, is_used FROM referral_numbers WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    jsonResponse(['status' => 'error', 'message' => 'Referral number not found']);
    exit;
}

// ─── Prevent editing a used referral ───
if ((int)$row['is_used'] === 1) {
    jsonResponse([
        'status'  => 'error',
        'message' => 'Cannot edit a used referral number'
    ]);
    exit;
}

// ─── If referral_no unchanged, no-op success ───
if ($row['referral_no'] === $referralNo) {
    jsonResponse([
        'status'      => 'success',
        'message'     => 'No changes were made',
        'id'          => (int)$id,
        'referral_no' => $referralNo
    ]);
    exit;
}

// ─── Duplicate check (exclude current row) ───
$stmt = $db->prepare("SELECT id FROM referral_numbers WHERE referral_no = ? AND id != ? LIMIT 1");
$stmt->bind_param("si", $referralNo, $id);
$stmt->execute();
$dup = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($dup) {
    jsonResponse([
        'status'  => 'error',
        'message' => 'Referral number already exists: ' . $referralNo
    ]);
    exit;
}

// ─── Update ───
$stmt = $db->prepare("UPDATE referral_numbers SET referral_no = ? WHERE id = ?");
$stmt->bind_param("si", $referralNo, $id);

if ($stmt->execute()) {
    $stmt->close();
    jsonResponse([
        'status'      => 'success',
        'message'     => 'Referral number updated successfully',
        'id'          => (int)$id,
        'referral_no' => $referralNo
    ]);
} else {
    $errMsg = $db->error;
    $stmt->close();
    jsonResponse([
        'status'  => 'error',
        'message' => 'Database error: ' . $errMsg
    ]);
}
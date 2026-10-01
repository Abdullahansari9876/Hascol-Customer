<?php
// Hascol Customer - Create Referral Number (Manual Only)

// ✅ Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");

error_reporting(E_ALL);
ini_set('display_errors', 0); // 0 = JSON response clean rahe

require '../config.php';
require '../db.php';

// ─── Read JSON input ───
$input = getInput();
$referralNo = isset($input['referral_no']) ? trim($input['referral_no']) : '';

// ─── Validation ───
if ($referralNo === '') {
    jsonResponse(['status' => 'error', 'message' => 'Referral number is required']);
    exit;
}

if (strlen($referralNo) > 50) {
    jsonResponse(['status' => 'error', 'message' => 'Referral number cannot exceed 50 characters']);
    exit;
}

// ─── Duplicate check ───
$stmt = $db->prepare("SELECT id FROM referral_numbers WHERE referral_no = ? LIMIT 1");
$stmt->bind_param("s", $referralNo);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    jsonResponse([
        'status'  => 'error',
        'message' => 'Referral number already exists: ' . $referralNo
    ]);
    exit;
}

// ─── Insert ───
$stmt = $db->prepare("
    INSERT INTO referral_numbers (referral_no, is_used, created_at)
    VALUES (?, 0, NOW())
");
$stmt->bind_param("s", $referralNo);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();

    jsonResponse([
        'status'      => 'success',
        'message'     => 'Referral number created successfully',
        'id'          => (int)$newId,
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
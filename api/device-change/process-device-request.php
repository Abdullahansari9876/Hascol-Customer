<?php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

// TODO: yahan apna admin auth check lagayein (jaisa customers APIs mein hai)

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Only POST method allowed']);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (session_status() === PHP_SESSION_NONE) session_start();
$adminId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;   // apne session key ke mutabiq adjust karein

$input     = getInput();
$requestId = (int)($input['request_id'] ?? 0);
$action    = trim($input['action'] ?? '');
$note      = trim($input['note'] ?? '');
$force     = !empty($input['force']);

if ($requestId <= 0) jsonResponse(['status'=>'error','message'=>'request_id required']);
if (!in_array($action, ['approve', 'reject'], true)) {
    jsonResponse(['status'=>'error','message'=>'action must be approve or reject']);
}
if ($action === 'reject' && $note === '') {
    jsonResponse(['status'=>'error','message'=>'Note is required when rejecting']);
}
if (strlen($note) > 255) jsonResponse(['status'=>'error','message'=>'Note too long (max 255)']);

$noteDb = ($note === '') ? null : $note;

try {
    $db->begin_transaction();

    // Request lock karke lo (do admin ek saath click na kar saken)
    $stmt = $db->prepare("SELECT * FROM hascol_device_change_requests WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req) throw new Exception('Request not found');
    if ($req['status'] !== 'pending') throw new Exception('Request already ' . $req['status']);

    if ($action === 'approve') {
        // Customer
        $stmt = $db->prepare("SELECT id, player_id FROM hascol_customer WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $req['customer_id']);
        $stmt->execute();
        $cust = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$cust) throw new Exception('Customer not found');

        // Nayi IMEI kisi aur customer ke paas to nahi?
        if (!$force) {
            $stmt = $db->prepare("SELECT COUNT(*) AS c FROM hascol_customer WHERE imei = ? AND id <> ?");
            $stmt->bind_param("si", $req['new_imei'], $req['customer_id']);
            $stmt->execute();
            $used = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();
            if ($used > 0) {
                $db->rollback();
                jsonResponse([
                    'status'     => 'error',
                    'error_code' => 'IMEI_IN_USE',
                    'message'    => 'This device is already registered on another customer account.',
                ]);
            }
        }

        // 1. IMEI update
        $stmt = $db->prepare("UPDATE hascol_customer SET imei = ? WHERE id = ?");
        $stmt->bind_param("si", $req['new_imei'], $req['customer_id']);
        $stmt->execute();
        $stmt->close();

        // 2. Purane sessions band
        $stmt = $db->prepare("UPDATE sessions SET is_active = 0 WHERE player_id = ?");
        $stmt->bind_param("s", $cust['player_id']);
        $stmt->execute();
        $stmt->close();

        $newStatus = 'approved';
    } else {
        $newStatus = 'rejected';
    }

    // 3. Request close
    $stmt = $db->prepare("
        UPDATE hascol_device_change_requests
        SET status = ?, admin_id = ?, admin_note = ?, processed_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("sisi", $newStatus, $adminId, $noteDb, $requestId);
    $stmt->execute();
    $stmt->close();

    $db->commit();

    jsonResponse([
        'status'  => 'success',
        'message' => $action === 'approve' ? 'Request approved. Customer can login on the new device.' : 'Request rejected.',
    ]);

} catch (Throwable $e) {
    $db->rollback();
    jsonResponse(['status' => 'error', 'message' => $e->getMessage()]);
}
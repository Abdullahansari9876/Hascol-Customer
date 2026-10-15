<?php
/**
 * DELETE DEVICE CHANGE REQUEST
 * POST /api/device-change/delete-device-change-request.php
 * Body: { id }
 */

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

// ─── Input ───
$input = getInput();
$id    = isset($input['id']) ? (int)$input['id'] : 0;

// ─── Validation ───
if ($id <= 0) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'INVALID_ID',
        'message'    => 'A valid request ID is required.'
    ]);
}

// ─── Check: request exist karti hai? ───
$stmt = $db->prepare("SELECT id, status FROM hascol_device_change_requests WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'REQUEST_NOT_FOUND',
        'message'    => 'Device change request not found.'
    ]);
}

// ─── Delete ───
$stmt = $db->prepare("DELETE FROM hascol_device_change_requests WHERE id = ?");
$stmt->bind_param("i", $id);

if (!$stmt->execute()) {
    error_log("DELETE_DEVICE_CHANGE_FAILED: id=$id, error=" . $stmt->error);
    $stmt->close();
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'DELETE_FAILED',
        'message'    => 'Could not delete the request. Please try again.'
    ]);
}

$affected = $stmt->affected_rows;
$stmt->close();

if ($affected === 0) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'DELETE_FAILED',
        'message'    => 'No request was deleted.'
    ]);
}

jsonResponse([
    'status'  => 'success',
    'message' => 'Device change request deleted successfully.',
    'id'      => $id,
]);
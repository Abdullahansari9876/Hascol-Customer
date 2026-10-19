<?php
/**
 * DELETE COUPON TEMPLATE API (Soft Delete)
 * POST /api/admin/delete-coupon-template.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require '../config.php';
require '../db.php';

$input = getInput();
$id = (int) ($input['id'] ?? 0);

if ($id <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'Invalid ID']);
}

$stmt = $db->prepare("UPDATE coupon_templates SET is_active = 0 WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    jsonResponse(['status' => 'success', 'message' => 'Template deactivated successfully']);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Failed to deactivate: ' . $stmt->error]);
}
$stmt->close();
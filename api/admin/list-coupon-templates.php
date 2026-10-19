<?php
/**
 * LIST COUPON TEMPLATES API (For DataTables)
 * POST /api/admin/get-coupon-templates.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require '../config.php';
require '../db.php';

// Input receive karein (agar aage chalke search/filter karna ho)
$input = getInput();

try {
    // Sirf active aur inactive dono templates fetch karein (Admin ko sab dikhna chahiye)
    // Agar sirf active chahiye toh: WHERE is_active = 1 lagayein
    $query = "SELECT * FROM coupon_templates ORDER BY id DESC";
    $result = $db->query($query);

    if (!$result) {
        throw new Exception("Database Query Failed: " . $db->error);
    }

    $templates = [];
    while ($row = $result->fetch_assoc()) {
        // Data ko format karein taake frontend par asani se show ho sake
        $templates[] = [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'description' => $row['description'] ?? '',
            'image_url' => $row['image_url'] ?? '',
            'discount_percent' => (float) $row['discount_percent'],
            'discount_amount' => (float) $row['discount_amount'],
            'valid_from' => $row['valid_from'],
            'valid_to' => $row['valid_to'],
            'is_active' => (int) $row['is_active'],
            'created_at' => $row['created_at']
        ];
    }

    // Frontend (DataTables JS) ko response bhejein
    jsonResponse([
        'status' => 'success',
        'message' => 'Templates fetched successfully',
        'total_records' => count($templates),
        'data' => $templates
    ]);

} catch (Exception $e) {
    error_log('List Templates Error: ' . $e->getMessage());
    jsonResponse([
        'status' => 'error', 
        'message' => 'Failed to fetch templates: ' . $e->getMessage()
    ]);
}
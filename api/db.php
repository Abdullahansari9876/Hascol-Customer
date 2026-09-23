<?php
// ============================================
// Disable all time limits
// ============================================
set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('max_input_time', 0);
ini_set('memory_limit', '2048M');

// Database configuration //
define('DB_HOST', 'localhost');
define('DB_NAME', 'hascol_customers');   // ⚠️ Apna database name
define('DB_USER', 'Abdullah');          // ⚠️ Apna MySQL username
define('DB_PASS', 'Allah786@');                    // ⚠️ Apna MySQL password

// Create MySQLi connection - DIRECT GLOBAL VARIABLE
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($db->connect_error) {
    die("Database connection failed: " . $db->connect_error);
}

$db->set_charset("utf8");

// Increase MySQL timeout
$db->query("SET SESSION wait_timeout = 28800");
$db->query("SET SESSION interactive_timeout = 28800");

// Set timezone
date_default_timezone_set('Asia/Karachi');

// Function to get connection (for API files)
function getDBConnection() {
    global $db;
    return $db;
}

// ============================================
// HELPER: Client IP
// ============================================
function getClientIp() {
    return $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '';
}
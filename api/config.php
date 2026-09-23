<?php
// ============================================
// FIREBASE CONFIG
// ============================================
define('FIREBASE_DB_URL', 'https://loyality-sales-ce91e-default-rtdb.asia-southeast1.firebasedatabase.app/');
// ⚠️ Asia region hai to: https://loyality-sales-default-rtdb.asia-southeast1.firebasedatabase.app

define('FIREBASE_DB_SECRET', 'naNpXKi9WZODl9vVB9tEw9SG30D28yF0TIZe2CSQ');
// Console → Project Settings → Service Accounts → Database Secrets

// ============================================
// OTP CONFIG
// ============================================
define('OTP_LENGTH', 6);
define('OTP_EXPIRY', 600);        // ✅ 10 minutes
define('OTP_MAX_ATTEMPTS', 5);

// ============================================
// HELPERS
// ============================================
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput() {
    $raw  = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) return $json;
    return $_POST;
}

function fbGet($path) {
    $url = FIREBASE_DB_URL . '/' . trim($path, '/') . '.json?auth=' . FIREBASE_DB_SECRET;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function fbSet($path, $data) {
    $url = FIREBASE_DB_URL . '/' . trim($path, '/') . '.json?auth=' . FIREBASE_DB_SECRET;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200 ? json_decode($res, true) : false;
}

function fbPatch($path, $data) {
    $url = FIREBASE_DB_URL . '/' . trim($path, '/') . '.json?auth=' . FIREBASE_DB_SECRET;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_exec($ch);
    curl_close($ch);
}

function fbDelete($path) {
    $url = FIREBASE_DB_URL . '/' . trim($path, '/') . '.json?auth=' . FIREBASE_DB_SECRET;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_exec($ch);
    curl_close($ch);
}

function playerKey($playerId) {
    return preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($playerId));
}
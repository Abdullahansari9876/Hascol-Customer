<?php
// ============================================
// FIREBASE CONFIG
// ============================================
define('FIREBASE_DB_URL', 'https://loyality-sales-ce91e-default-rtdb.asia-southeast1.firebasedatabase.app/');
define('FIREBASE_DB_SECRET', 'naNpXKi9WZODl9vVB9tEw9SG30D28yF0TIZe2CSQ');

// ============================================
// OTP CONFIG
// ============================================
define('OTP_LENGTH', 6);
define('OTP_EXPIRY', 600);        // 10 minutes
define('OTP_MAX_ATTEMPTS', 5);

// ============================================
// PASSWORD ENCRYPTION KEY (AES-256-GCM)
// ⚠️ Is key ko kisi ke saath share na karein aur Git mein commit na karein.
// ⚠️ Key badalne par pehle se save kiye hue password_enc decode nahi honge.
// ⚠️ Key ka alag jagah backup zaroor rakhein.
// ============================================
define('PASSWORD_ENC_KEY', '9b79441fe979752861f8ba0f4d9f6d2868d5f4b279c6df23db4e3749bafb039a');

// ============================================
// HELPERS: Input / Output
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

// ============================================
// FIREBASE HELPERS
// ============================================
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

// ============================================
// PASSWORD ENCRYPT / DECRYPT (AES-256-GCM)
// register.php, change/reset password aur admin panel mein use hote hain
// ============================================
function encryptPassword(string $plain): string {
    $key = hex2bin(PASSWORD_ENC_KEY);
    if ($key === false || strlen($key) !== 32) {
        return '';
    }
    $iv  = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        return '';
    }
    return base64_encode($iv . $tag . $cipher);
}

function decryptPassword(string $stored): ?string {
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $key = hex2bin(PASSWORD_ENC_KEY);
    if ($key === false || strlen($key) !== 32) {
        return null;
    }
    $iv     = substr($raw, 0, 12);
    $tag    = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain  = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}
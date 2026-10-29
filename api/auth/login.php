<?php
// 1. Headers (CORS Setup)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:8080'); // Frontend URL
header('Access-Control-Allow-Credentials: true'); // Cookies allow karne ke liye
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// 2. 🔥 COOKIE PATH FIX (Sabse Important)
// Isse cookie poore localhost par valid hogi, dono projects isko use kar payenge
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/', 
    'domain' => '', // khali chhorein, browser khud handle karega
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

// Baki ka database code waisa hi rahega...
require_once __DIR__ . '/../db.php';
$db = getDBConnection();

$login = isset($_POST['login']) ? trim($_POST['login']) : '';
$password = isset($_POST['password']) ? trim($_POST['password']) : '';

if ($login === '' || $password === '') {
    echo json_encode(['status' => 0, 'message' => 'Credentials required']); exit;
}

$stmt = $db->prepare("SELECT id, name, privilege, login, description, status FROM users WHERE login = ? LIMIT 1");
$stmt->bind_param("s", $login);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['status' => 0, 'message' => 'Invalid credentials']); exit;
}

// Password Check
$storedHash = $user['description'];
$passwordOk = false;
if (password_verify($password, $storedHash)) { $passwordOk = true; }
elseif (md5($password) === $storedHash) { $passwordOk = true; }
elseif ($password === $storedHash) { $passwordOk = true; }

if (!$passwordOk) {
    echo json_encode(['status' => 0, 'message' => 'Invalid credentials']); exit;
}

// Session Set
session_regenerate_id(true); 
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_name'] = $user['name'];
$_SESSION['privilege'] = $user['privilege'];

echo json_encode([
    'status' => 1, 
    'message' => 'Login successful',
    'redirect' => '/hascol_dashboard/Customers/dealers.php'
]);
exit;
?>
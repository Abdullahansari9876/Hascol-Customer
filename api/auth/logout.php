<?php
// 🔥 COOKIE PATH FIX
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/', 
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

// Saare session variables delete karein
$_SESSION = array();

// 🔥 BROWSER SE COOKIE DELETE KAREIN (Poore domain se)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"], // Yeh '/' path use karega
        $params["secure"], $params["httponly"]
    );
}

// Session Destroy
session_destroy();

// Response
header('Content-Type: application/json');
echo json_encode(['status' => 1, 'message' => 'Logged out successfully']);
exit;
?>
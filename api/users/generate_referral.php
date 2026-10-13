<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../db.php'; 

function refRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function refGenerateNumber(): string
{
    // Sirf digits: pehla digit 1-9 (0 se start nahi hoga), baqi 7 digits 0-9
    $code = (string)random_int(1, 9);
    for ($i = 0; $i < 7; $i++) {
        $code .= (string)random_int(0, 9);
    }
    return $code; // e.g. 48271936 (8 digits)
}

// 1) Request method validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    refRespond(405, [
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
}

// 2) Input read (JSON ya form-data dono)
$input       = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') !== false) {
    $raw     = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        refRespond(400, [
            'success' => false,
            'message' => 'Invalid JSON body.'
        ]);
    }
    $input = $decoded;
} else {
    $input = $_POST;
}

// 3) Field validation
$errors = [];

// --- name (jis user ke liye referral ban raha hai) ---
$userName = '';
if (!array_key_exists('name', $input)) {
    $errors['name'] = 'Name is required.';
} elseif (!is_string($input['name'])) {
    $errors['name'] = 'Name must be a string.';
} else {
    $userName = trim(preg_replace('/\s+/u', ' ', $input['name']));
    $nameLen  = mb_strlen($userName, 'UTF-8');

    if ($userName === '') {
        $errors['name'] = 'Name cannot be empty.';
    } elseif ($nameLen < 2 || $nameLen > 100) {
        $errors['name'] = 'Name must be between 2 and 100 characters.';
    } elseif (!preg_match('/^\p{L}[\p{L}\p{M} .\'-]*$/u', $userName)) {
        $errors['name'] = 'Name may contain only letters, spaces, dot, apostrophe and hyphen.';
    }
}

// --- customer_id (referral dene wala customer) ---
$customerId = null;
if (!array_key_exists('customer_id', $input) || $input['customer_id'] === '' || $input['customer_id'] === null) {
    $errors['customer_id'] = 'customer_id is required.';
} else {
    $customerId = filter_var(
        $input['customer_id'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if ($customerId === false) {
        $errors['customer_id'] = 'customer_id must be a positive integer.';
    }
}

if (!empty($errors)) {
    refRespond(422, [
        'success' => false,
        'message' => 'Validation failed.',
        'errors'  => $errors
    ]);
}

$db = getDBConnection();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // 4) Customer verification (hascol_customer table)
    $custStmt = $db->prepare("SELECT id, status FROM hascol_customer WHERE id = ? LIMIT 1");
    $custStmt->bind_param('i', $customerId);
    $custStmt->execute();
    $customer = $custStmt->get_result()->fetch_assoc();
    $custStmt->close();

    if (!$customer) {
        refRespond(404, [
            'success' => false,
            'message' => 'Customer not found.',
            'errors'  => ['customer_id' => 'No customer exists with this customer_id.']
        ]);
    }

    if ($customer['status'] !== 'active') {
        refRespond(403, [
            'success' => false,
            'message' => 'This customer account is not active.',
            'errors'  => ['customer_id' => 'Customer status is ' . $customer['status'] . '.']
        ]);
    }

    // 5) Insert with duplicate-retry (is_used = 1 generate hote hi set hota hai)
    $stmt = $db->prepare(
        "INSERT INTO referral_numbers (referral_no, user_name, referred_by_customer_id, is_used)
         VALUES (?, ?, ?, 1)"
    );
    $referralNo = '';
    $stmt->bind_param('ssi', $referralNo, $userName, $customerId);

    $newId    = 0;
    $inserted = false;

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $referralNo = refGenerateNumber();
        try {
            $stmt->execute();
            $newId    = $stmt->insert_id;
            $inserted = true;
            break;
        } catch (mysqli_sql_exception $e) {
            if ((int)$e->getCode() !== 1062) { // 1062 = duplicate entry
                throw $e;
            }
            // duplicate mila, naya number generate karke dobara try karein
        }
    }
    $stmt->close();

    if (!$inserted) {
        refRespond(503, [
            'success' => false,
            'message' => 'Could not generate a unique referral number. Please try again.'
        ]);
    }

    // Saved row wapas read karein
    $sel = $db->prepare(
        "SELECT id, referral_no, user_name, referred_by_customer_id, is_used, created_at
         FROM referral_numbers WHERE id = ? LIMIT 1"
    );
    $sel->bind_param('i', $newId);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $sel->close();

    refRespond(201, [
        'success' => true,
        'message' => 'Referral number generated successfully.',
        'data'    => [
            'id'                      => (int)$row['id'],
            'user_name'               => $row['user_name'],
            'referred_by_customer_id' => (int)$row['referred_by_customer_id'],
            'referral_no'             => $row['referral_no'],
            'is_used'                 => (int)$row['is_used'], 
            'created_at'              => $row['created_at']
            // hello word
        ]
    ]);
} catch (Throwable $e) {
    error_log('generate_referral error: ' . $e->getMessage());
    refRespond(500, [
        'success' => false,
        'message' => 'Internal server error.'
    ]);
}
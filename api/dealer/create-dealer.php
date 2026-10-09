<?php

// ✅ Sirf OPTIONS handle karein
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json; charset=utf-8");

error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config.php';
require '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Only POST method allowed']);
    exit;
}

// ─── Input lo (JSON ya form-data dono support) ───
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = (stripos($contentType, 'application/json') !== false)
    ? (json_decode(file_get_contents('php://input'), true) ?? [])
    : $_POST;

$name         = trim($input['name'] ?? '');
$mobile       = trim($input['mobile'] ?? '');
$email        = trim($input['email'] ?? '');
$password     = trim($input['password'] ?? '');
$station_name = trim($input['station_name'] ?? '');
$address      = trim($input['address'] ?? '');
$city         = trim($input['city'] ?? '');
$status       = trim($input['status'] ?? 'active');

// ✅ NEW FIELDS
$latitude       = $input['latitude']       ?? null;
$longitude      = $input['longitude']      ?? null;
$company_share  = $input['company_share']  ?? null;
$dealer_share   = $input['dealer_share']   ?? null;

// ─── Validation ───
$errors = [];

if (empty($name)) $errors['name'] = 'Name is required';
elseif (strlen($name) > 150) $errors['name'] = 'Name must not exceed 150 characters';

if (empty($mobile)) $errors['mobile'] = 'Mobile is required';
elseif (!preg_match('/^[0-9+\-\s]{7,20}$/', $mobile)) $errors['mobile'] = 'Invalid mobile format';

if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Invalid email format';
}

if (empty($password)) $errors['password'] = 'Password is required';
elseif (strlen($password) < 6) $errors['password'] = 'Password must be at least 6 characters';

if (empty($station_name)) $errors['station_name'] = 'Station name is required';
elseif (strlen($station_name) > 150) $errors['station_name'] = 'Station name must not exceed 150 characters';

if (strlen($city) > 100) $errors['city'] = 'City must not exceed 100 characters';

if (!in_array($status, ['active', 'inactive'])) $errors['status'] = 'Invalid status';

// ✅ NEW: latitude validation
if ($latitude !== null && $latitude !== '') {
    if (!is_numeric($latitude)) {
        $errors['latitude'] = 'Latitude must be a number';
    } elseif ((float)$latitude < -90 || (float)$latitude > 90) {
        $errors['latitude'] = 'Latitude must be between -90 and 90';
    }
}

// ✅ NEW: longitude validation
if ($longitude !== null && $longitude !== '') {
    if (!is_numeric($longitude)) {
        $errors['longitude'] = 'Longitude must be a number';
    } elseif ((float)$longitude < -180 || (float)$longitude > 180) {
        $errors['longitude'] = 'Longitude must be between -180 and 180';
    }
}

// ✅ NEW: company_share validation
if ($company_share !== null && $company_share !== '') {
    if (!is_numeric($company_share)) {
        $errors['company_share'] = 'Company share must be a number';
    } elseif ((float)$company_share < 0) {
        $errors['company_share'] = 'Company share cannot be negative';
    }
}

// ✅ NEW: dealer_share validation
if ($dealer_share !== null && $dealer_share !== '') {
    if (!is_numeric($dealer_share)) {
        $errors['dealer_share'] = 'Dealer share must be a number';
    } elseif ((float)$dealer_share < 0) {
        $errors['dealer_share'] = 'Dealer share cannot be negative';
    }
}

if (!empty($errors)) {
    jsonResponse(['status' => 'error', 'message' => 'Validation failed', 'errors' => $errors]);
    exit;
}

// ─── Duplicate mobile check ───
$stmt = $db->prepare("SELECT id FROM hascol_dealers WHERE mobile = ? LIMIT 1");
$stmt->bind_param("s", $mobile);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    jsonResponse(['status' => 'error', 'message' => 'Mobile number already registered']);
    exit;
}
$stmt->close();

// ─── Duplicate email check ───
if (!empty($email)) {
    $stmt = $db->prepare("SELECT id FROM hascol_dealers WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        jsonResponse(['status' => 'error', 'message' => 'Email already registered']);
        exit;
    }
    $stmt->close();
}

// ─── Hash password ───
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// ─── Nullable values ko NULL set karo agar empty hain ───
$emailDb         = ($email === '')         ? null : $email;
$addressDb       = ($address === '')       ? null : $address;
$cityDb          = ($city === '')          ? null : $city;
$latitudeDb      = ($latitude === null || $latitude === '')     ? null : (float)$latitude;
$longitudeDb     = ($longitude === null || $longitude === '')   ? null : (float)$longitude;
$companyShareDb  = ($company_share === null || $company_share === '') ? null : (float)$company_share;
$dealerShareDb   = ($dealer_share === null || $dealer_share === '')   ? null : (float)$dealer_share;

// ─── Insert Query ───
$stmt = $db->prepare("
    INSERT INTO hascol_dealers 
        (name, mobile, email, password, station_name, address, city, 
         latitude, longitude, company_share, dealer_share, status, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
");

// Types: name(s), mobile(s), email(s), password(s), station_name(s), address(s), city(s),
//        latitude(d), longitude(d), company_share(d), dealer_share(d), status(s)
$stmt->bind_param("sssssssdddds",
    $name,
    $mobile,
    $emailDb,
    $hashedPassword,
    $station_name,
    $addressDb,
    $cityDb,
    $latitudeDb,
    $longitudeDb,
    $companyShareDb,
    $dealerShareDb,
    $status
);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();
    jsonResponse([
        'status'  => 'success',
        'message' => 'Dealer created successfully',
        'data'    => [
            'id'            => (int)$newId,
            'name'          => $name,
            'mobile'        => $mobile,
            'email'         => $emailDb,
            'station_name'  => $station_name,
            'city'          => $cityDb,
            'latitude'      => $latitudeDb,
            'longitude'     => $longitudeDb,
            'company_share' => $companyShareDb,
            'dealer_share'  => $dealerShareDb,
            'status'        => $status
        ]
    ]);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
}
$stmt->close();
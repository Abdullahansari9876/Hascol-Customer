<?php
/**
 * REGISTER + OTP + IMEI + MOBILE + PASSWORD (AES ENCRYPTED) + DYNAMIC COUPONS + COUPON_NO + SKIP OTP
 * 
 * POST /api/register.php
 * 
 * FLOW:
 * 1. Input validation
 * 2. Mobile check (STRICT)
 * 3. IMEI check (STRICT — naya)
 * 4. Player exists check
 * 5. REFERRAL CHECK (pehle — error aaye toh aage mat badho)
 * 6. Customer INSERT (password AES-256-GCM encrypted)
 * 7. Customer ID lo
 * 8. Referral insert/update
 * 9. Coupons insert (Dynamic from coupon_templates)
 * 10. skip_otp check
 *
 * UPDATED: company_name (OPTIONAL) support added.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require 'config.php';
require 'db.php';

// ─── Mobile normalization ───
function normalizeMobile(string $mobile): string
{
    $mobile = preg_replace('/[\s\-\(\)]+/', '', $mobile);

    if (preg_match('/^\+923(\d{9})$/', $mobile, $m)) {
        return '03' . $m[1];
    }
    if (preg_match('/^923(\d{9})$/', $mobile, $m)) {
        return '03' . $m[1];
    }
    return $mobile;
}

$input = getInput();
$name = trim($input['name'] ?? '');
$playerId = trim($input['player_id'] ?? '');
$email = trim($input['email'] ?? '');
$companyName = trim($input['company_name'] ?? '');   // ✅ NEW — OPTIONAL
$imei = trim($input['imei'] ?? '');
$mobile = normalizeMobile(trim($input['mobile'] ?? ''));
$password = trim($input['password'] ?? '');

// ✅ coupon_no ya referral_no — dono accept karo
$userReferralNo = trim($input['coupon_no'] ?? $input['referral_no'] ?? '');
$skipOtp = (int) ($input['skip_otp'] ?? 0);

// ─── Validation ───
if (empty($name))
    jsonResponse(['status' => 'error', 'message' => 'name required']);
if (empty($playerId))
    jsonResponse(['status' => 'error', 'message' => 'player_id required']);
if (empty($imei))
    jsonResponse(['status' => 'error', 'message' => 'imei required']);
if (empty($mobile))
    jsonResponse(['status' => 'error', 'message' => 'mobile required']);
if (empty($password))
    jsonResponse(['status' => 'error', 'message' => 'password required']);

if (!preg_match('/^(03[0-9]{9}|\+923[0-9]{9})$/', $mobile)) {
    jsonResponse(['status' => 'error', 'message' => 'Invalid mobile number (e.g., 03001234567)']);
}

if (strlen($password) < 6) {
    jsonResponse(['status' => 'error', 'message' => 'Password must be at least 6 characters']);
}

if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['status' => 'error', 'message' => 'Invalid email format']);
}

// ✅ company_name — OPTIONAL. Sirf length safety check (agar bheja gaya ho).
if ($companyName !== '' && strlen($companyName) > 150) {
    jsonResponse(['status' => 'error', 'message' => 'Company name too long (max 150 chars)']);
}

// ✅ Empty string ko NULL mein convert karein (DB ke liye cleaner)
$companyNameDb = ($companyName === '') ? null : $companyName;

// ─── referral_no validation (agar diya gaya) ───
if (!empty($userReferralNo)) {
    if (strlen($userReferralNo) < 4) {
        jsonResponse(['status' => 'error', 'message' => 'Referral number must be at least 4 characters']);
    }
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $userReferralNo)) {
        jsonResponse(['status' => 'error', 'message' => 'Referral number can only contain letters, numbers, hyphen, and underscore']);
    }
}

$key = playerKey($playerId);
$ip = getClientIp();

// ─── Password encryption (AES-256-GCM) ───
$passwordEncrypted = encryptPassword($password);

if ($passwordEncrypted === '') {
    error_log("REGISTER: password encryption failed for player_id=$playerId");
    jsonResponse([
        'status'  => 'error',
        'message' => 'Password encryption failed. Please try again.'
    ]);
}

// ─── Mobile already registered check (STRICT) ───
$stmt = $db->prepare("SELECT id, player_id FROM hascol_customer WHERE mobile = ? LIMIT 1");
$stmt->bind_param("s", $mobile);
$stmt->execute();
$mobileExists = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($mobileExists) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'MOBILE_ALREADY_REGISTERED',
        'message'    => 'This mobile number is already registered.'
    ]);
}

// ─── IMEI already registered check (STRICT) ───
$stmt = $db->prepare("SELECT id, player_id FROM hascol_customer WHERE imei = ? LIMIT 1");
$stmt->bind_param("s", $imei);
$stmt->execute();
$imeiExists = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($imeiExists && $imeiExists['player_id'] !== $playerId) {
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'IMEI_ALREADY_REGISTERED',
        'message'    => 'This device is already registered with another account.'
    ]);
}

// ─── Email already registered check (STRICT) ───
if (!empty($email)) {
    $stmt = $db->prepare("SELECT id, player_id FROM hascol_customer WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $emailExists = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($emailExists) {
        jsonResponse([
            'status'     => 'error',
            'error_code' => 'EMAIL_ALREADY_REGISTERED',
            'message'    => 'This email is already registered with another account.'
        ]);
    }
}

// ─── Player already registered check ───
$stmt = $db->prepare("SELECT id, verified FROM hascol_customer WHERE player_id = ? LIMIT 1");
$stmt->bind_param("s", $playerId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing && $existing['verified'] == 1) {
    jsonResponse([
        'status'    => 'exists',
        'message'   => 'Player already registered',
        'player_id' => $playerId,
    ]);
}

// ═══════════════════════════════════════════════════
// ✅ STEP 1: REFERRAL CHECK (PEHLE — customer insert se pehle)
// ═══════════════════════════════════════════════════

function generateCouponNo()
{
    return (string) random_int(1000000000, 9999999999);
}

$customerType = 'new_customer';
$couponNo = '';
$referralRecordId = null;
$referralSource = '';

if (!empty($userReferralNo)) {
    // ─── Case 1: Customer ne coupon_no diya ───
    $stmt = $db->prepare("
        SELECT id, is_used 
        FROM referral_numbers 
        WHERE referral_no = ? 
        LIMIT 1
    ");
    $stmt->bind_param("s", $userReferralNo);
    $stmt->execute();
    $referralData = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$referralData) {
        jsonResponse([
            'status' => 'error',
            'message' => 'Invalid coupon number. This coupon does not exist in our system.',
            'coupon_no' => $userReferralNo,
        ]);
    }

    if ($referralData['is_used'] == 1) {
        jsonResponse([
            'status' => 'error',
            'message' => 'This coupon number has already been used.',
            'coupon_no' => $userReferralNo,
        ]);
    }

    $couponNo = $userReferralNo;
    $referralSource = 'user_provided';
    $referralRecordId = $referralData['id'];
    $customerType = 'referred_customer';

} else {
    // ─── Case 2: Customer ne coupon_no nahi diya ───
    $couponNo = generateCouponNo();
    $maxTries = 5;
    $tries = 0;

    while ($tries < $maxTries) {
        $stmt = $db->prepare("SELECT id FROM hascol_customer WHERE coupon_no = ? LIMIT 1");
        $stmt->bind_param("s", $couponNo);
        $stmt->execute();
        $exists1 = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $db->prepare("SELECT id FROM referral_numbers WHERE referral_no = ? LIMIT 1");
        $stmt->bind_param("s", $couponNo);
        $stmt->execute();
        $exists2 = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exists1 && !$exists2)
            break;

        $couponNo = generateCouponNo();
        $tries++;
    }

    if ($tries >= $maxTries) {
        jsonResponse(['status' => 'error', 'message' => 'Could not generate unique coupon_no. Please try again.']);
    }

    $referralSource = 'auto_generated';
    $referralRecordId = null;
}

// ═══════════════════════════════════════════════════
// ✅ STEP 2: Customer INSERT
// ═══════════════════════════════════════════════════

$verifiedStatus = ($skipOtp === 1) ? 1 : 0;
$verifiedAt = ($skipOtp === 1) ? date('Y-m-d H:i:s') : null;

$stmt = $db->prepare("
    INSERT INTO hascol_customer 
    (player_key, player_id, name, email, company_name, mobile, password, imei, coupon_no, customer_type, verified, verified_at, status, ip_address, created_at, updated_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())
");
$stmt->bind_param(
    "ssssssssssiss",
    $key,
    $playerId,
    $name,
    $email,
    $companyNameDb,
    $mobile,
    $passwordEncrypted,
    $imei,
    $couponNo,
    $customerType,
    $verifiedStatus,
    $verifiedAt,
    $ip
);
if (!$stmt->execute()) {
    error_log("REGISTER: customer insert failed. player_id=$playerId, mobile=$mobile, imei=$imei, error=" . $stmt->error);
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'CUSTOMER_SAVE_FAILED',
        'message'    => 'We could not complete your registration. Please try again in a moment.'
    ]);
}
$stmt->close();

// ─── Customer ID lein ───
$stmt = $db->prepare("SELECT id FROM hascol_customer WHERE player_id = ? LIMIT 1");
$stmt->bind_param("s", $playerId);
$stmt->execute();
$customerRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customerRow) {
    error_log("REGISTER: customer not found after insert. player_id=$playerId, mobile=$mobile, imei=$imei");
    jsonResponse([
        'status'     => 'error',
        'error_code' => 'REGISTRATION_FAILED',
        'message'    => 'We could not complete your registration. Please try again in a moment.'
    ]);
}

$customerId = $customerRow['id'];

// ═══════════════════════════════════════════════════
// ✅ STEP 3: Referral insert/update
// ═══════════════════════════════════════════════════

if ($referralRecordId) {
    $stmt = $db->prepare("
        UPDATE referral_numbers 
        SET is_used = 1, 
            used_by_customer_id = ?, 
            used_at = NOW() 
        WHERE id = ?
    ");
    $stmt->bind_param("ii", $customerId, $referralRecordId);
    $stmt->execute();
    $stmt->close();
} else {
    $stmt = $db->prepare("
        SELECT id, referral_no 
        FROM referral_numbers 
        WHERE used_by_customer_id = ? 
        LIMIT 1
    ");
    $stmt->bind_param("i", $customerId);
    $stmt->execute();
    $existingReferral = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existingReferral) {
        $stmt = $db->prepare("
            INSERT INTO referral_numbers 
            (referral_no, is_used, used_by_customer_id, used_at, created_at) 
            VALUES (?, 0, ?, NULL, NOW())
        ");
        $stmt->bind_param("si", $couponNo, $customerId);
        $stmt->execute();
        $stmt->close();
    }
}

// ═══════════════════════════════════════════════════
// ✅ STEP 4: Default Coupons Insert (SIRF Admin ke coupon_templates se)
// ═══════════════════════════════════════════════════

$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM coupons WHERE customer_id = ?");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$couponCount = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

if ($couponCount == 0) {
    // Admin ke active templates fetch karein
    $defaultCoupons = [];
    $stmt = $db->prepare("SELECT * FROM coupon_templates WHERE is_active = 1");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $defaultCoupons[] = $row;
    }
    $stmt->close();

    // ❌ Koi hardcoded fallback coupon nahi.
    if (empty($defaultCoupons)) {
        error_log("REGISTER: No active coupon_templates found. customer_id=$customerId");
    }

    $fallbackDays = defined('COUPON_DEFAULT_VALIDITY_DAYS') ? (int) COUPON_DEFAULT_VALIDITY_DAYS : 365;

    foreach ($defaultCoupons as $c) {
        $validFrom = !empty($c['valid_from']) ? $c['valid_from'] : date('Y-m-d H:i:s');
        $validTo   = !empty($c['valid_to'])
            ? $c['valid_to']
            : date('Y-m-d H:i:s', strtotime("+{$fallbackDays} days"));

        $title           = $c['title'] ?? '';
        $description     = $c['description'] ?? '';
        $imageUrl        = $c['image_url'] ?? '';
        $discountPercent = (float) ($c['discount_percent'] ?? 0);
        $discountAmount  = (float) ($c['discount_amount'] ?? 0);
        $minPurchase     = (float) ($c['min_purchase'] ?? 0);

        $stmt = $db->prepare("
            INSERT INTO coupons 
            (customer_id, title, description, image_url,
             discount_percent, discount_amount, min_purchase, 
             valid_from, valid_to, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'available')
        ");
        $stmt->bind_param(
            "isssdddss",
            $customerId,
            $title,
            $description,
            $imageUrl,
            $discountPercent,
            $discountAmount,
            $minPurchase,
            $validFrom,
            $validTo
        );
        if (!$stmt->execute()) {
            error_log("Coupon insert failed: " . $stmt->error);
        }
        $stmt->close();
    }
}

// ✅ Counts update
$stmt = $db->prepare("
    UPDATE hascol_customer 
    SET total_coupons = (SELECT COUNT(*) FROM coupons WHERE customer_id = ?),
        remaining_coupons = (SELECT COUNT(*) FROM coupons WHERE customer_id = ? AND status = 'available'),
        used_coupons = (SELECT COUNT(*) FROM coupons WHERE customer_id = ? AND status = 'used')
    WHERE id = ?
");
$stmt->bind_param("iiii", $customerId, $customerId, $customerId, $customerId);
$stmt->execute();
$stmt->close();

$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM coupons WHERE customer_id = ?");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$insertedCount = $row ? (int) $row['cnt'] : 0;
$stmt->close();

// ─── Firebase Customer (backup) ───
fbSet("hascol_customer/$key", [
    'player_id' => $playerId,
    'name' => $name,
    'email' => $email,
    'company_name' => $companyName,   // ✅ NEW (optional)
    'mobile' => $mobile,
    'imei' => $imei,
    'coupon_no' => $couponNo,
    'customer_type' => $customerType,
    'verified' => ($verifiedStatus === 1),
    'created_at' => $existing['created_at'] ?? date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);

// ═══════════════════════════════════════════════════
// ✅ STEP 5: SKIP OTP LOGIC
// ═══════════════════════════════════════════════════

if ($skipOtp === 1) {
    $token = bin2hex(random_bytes(32));
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $stmt = $db->prepare("
        INSERT INTO sessions 
        (token, player_key, player_id, ip_address, user_agent, created_at, expires_at, is_active) 
        VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 1)
    ");
    $stmt->bind_param("sssss", $token, $key, $playerId, $ip, $userAgent);
    $stmt->execute();
    $stmt->close();

    fbSet("sessions/$token", [
        'player_id' => $playerId,
        'player_key' => $key,
        'created_at' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d H:i:s', time() + 30 * 24 * 3600),
    ]);

    jsonResponse([
        'status' => 'success',
        'message' => 'Registration complete (OTP skipped)',
        'player_id' => $playerId,
        'mobile' => $mobile,
        'imei' => $imei,
        'email' => $email,               // ✅ NEW
        'company_name' => $companyName,  // ✅ NEW (empty '' agar nahi bheja)
        'coupon_no' => $couponNo,
        'referral_source' => $referralSource,
        'customer_type' => $customerType,
        'total_coupons' => $insertedCount,
        'otp_skipped' => true,
        'token' => $token,
        'verified' => true,
    ]);
}

// ═══════════════════════════════════════════════════
// ✅ STEP 6: NORMAL OTP FLOW
// ═══════════════════════════════════════════════════

$otp = str_pad(rand(0, pow(10, OTP_LENGTH) - 1), OTP_LENGTH, '0', STR_PAD_LEFT);
$now = time();
$expires = $now + OTP_EXPIRY;
$maxAttempts = OTP_MAX_ATTEMPTS;

$stmt = $db->prepare("
    INSERT INTO otps 
    (player_key, player_id, otp, type, attempts, max_attempts, is_used, sent_at, expires_at, ip_address) 
    VALUES (?, ?, ?, 'register', 0, ?, 0, NOW(), FROM_UNIXTIME(?), ?)
");
$stmt->bind_param("sssiis", $key, $playerId, $otp, $maxAttempts, $expires, $ip);
if (!$stmt->execute()) {
    jsonResponse(['status' => 'error', 'message' => 'OTP insert failed: ' . $stmt->error]);
}
$stmt->close();

$stmt = $db->prepare("INSERT INTO otp_logs (player_key, action, otp, ip_address, note, created_at) VALUES (?, 'sent', ?, ?, 'OTP sent for registration', NOW())");
$stmt->bind_param("sss", $key, $otp, $ip);
$stmt->execute();
$stmt->close();

$notificationMessage = "Your OTP is: $otp\nValid for 10 minutes.";
$payload = json_encode(['otp' => $otp, 'expires_at' => date('Y-m-d H:i:s', $expires)]);

$stmt = $db->prepare("
    INSERT INTO notifications 
    (player_key, player_id, type, title, message, payload, is_read, created_at) 
    VALUES (?, ?, 'otp', '🔐 Verification Code', ?, ?, 0, NOW())
");
$stmt->bind_param("ssss", $key, $playerId, $notificationMessage, $payload);
$stmt->execute();
$stmt->close();

fbSet("otps/$key", [
    'otp' => $otp,
    'player_id' => $playerId,
    'name' => $name,
    'imei' => $imei,
    'type' => 'register_otp',
    'title' => '🔐 Verification Code',
    'message' => "Your OTP is: $otp\nValid for 10 minutes.",
    'is_read' => false,
    'sent_at' => date('Y-m-d H:i:s', $now),
    'expires_at' => date('Y-m-d H:i:s', $expires),
    'expires_ts' => $expires,
    'attempts' => 0,
]);

jsonResponse([
    'status' => 'success',
    'message' => 'OTP sent. Valid for 10 minutes.',
    'player_id' => $playerId,
    'mobile' => $mobile,
    'imei' => $imei,
    'email' => $email,               // ✅ NEW
    'company_name' => $companyName,  // ✅ NEW (empty '' agar nahi bheja)
    'coupon_no' => $couponNo,
    'referral_source' => $referralSource,
    'customer_type' => $customerType,
    'total_coupons' => $insertedCount,
    'otp_skipped' => false,
    'otp' => $otp,
    'expires_in' => OTP_EXPIRY,
    'expires_at' => date('Y-m-d H:i:s', $expires),
]);
<?php
/**
 * REGISTER + OTP + IMEI + MOBILE + PASSWORD + 3 COUPONS + COUPON_NO + SKIP OTP
 * 
 * POST /api/register.php
 * 
 * FLOW:
 * 1. Input validation
 * 2. Mobile check
 * 3. Customer exists check
 * 4. REFERRAL CHECK (pehle — error aaye toh aage mat badho)
 * 5. Customer INSERT/UPDATE
 * 6. Customer ID lo
 * 7. Referral insert/update
 * 8. Coupons insert
 * 9. skip_otp check
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require 'config.php';
require 'db.php';

$input = getInput();
$name = trim($input['name'] ?? '');
$playerId = trim($input['player_id'] ?? '');
$email = trim($input['email'] ?? '');
$imei = trim($input['imei'] ?? '');
$mobile = trim($input['mobile'] ?? '');
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
$passwordHash = password_hash($password, PASSWORD_BCRYPT);

// ─── Mobile already registered check ───
$stmt = $db->prepare("SELECT id, player_key FROM customers WHERE mobile = ?");
$stmt->bind_param("s", $mobile);
$stmt->execute();
$mobileExists = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($mobileExists && $mobileExists['player_key'] !== $key) {
    jsonResponse(['status' => 'error', 'message' => 'Mobile number already registered']);
}

// ─── Check existing customer ───
$stmt = $db->prepare("SELECT * FROM customers WHERE player_key = ?");
$stmt->bind_param("s", $key);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing && $existing['verified'] == 1) {
    jsonResponse([
        'status' => 'exists',
        'message' => 'Player already registered',
        'player_id' => $playerId,
    ]);
}

// ═══════════════════════════════════════════════════
// ✅ STEP 1: REFERRAL CHECK (PEHLE — customer insert se pehle)
// ═══════════════════════════════════════════════════

function generateCouponNo()
{
    $random = strtoupper(substr(md5(uniqid(microtime(), true)), 0, 8));
    return 'CUST-' . $random;
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
        // ✅ Error — customer insert hi nahi hoga
        jsonResponse([
            'status' => 'error',
            'message' => 'Invalid coupon number. This coupon does not exist in our system.',
            'coupon_no' => $userReferralNo,
        ]);
    }

    if ($referralData['is_used'] == 1) {
        // ✅ Error — customer insert hi nahi hoga
        jsonResponse([
            'status' => 'error',
            'message' => 'This coupon number has already been used.',
            'coupon_no' => $userReferralNo,
        ]);
    }

    // ✅ Referral valid
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
        // Check 1: customers table
        $stmt = $db->prepare("SELECT id FROM customers WHERE coupon_no = ? LIMIT 1");
        $stmt->bind_param("s", $couponNo);
        $stmt->execute();
        $exists1 = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Check 2: referral_numbers table
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
// ✅ STEP 2: Customer INSERT/UPDATE
// ═══════════════════════════════════════════════════

$verifiedStatus = ($skipOtp === 1) ? 1 : 0;
$verifiedAt = ($skipOtp === 1) ? date('Y-m-d H:i:s') : null;

$stmt = $db->prepare("
    INSERT INTO customers 
    (player_key, player_id, name, email, mobile, password, imei, coupon_no, customer_type, verified, verified_at, status, ip_address, created_at, updated_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        email = VALUES(email),
        mobile = VALUES(mobile),
        password = VALUES(password),
        imei = VALUES(imei),
        coupon_no = VALUES(coupon_no),
        customer_type = VALUES(customer_type),
        verified = VALUES(verified),
        verified_at = VALUES(verified_at),
        ip_address = VALUES(ip_address),
        updated_at = NOW()
");
$stmt->bind_param(
    "sssssssssiss",
    $key,
    $playerId,
    $name,
    $email,
    $mobile,
    $passwordHash,
    $imei,
    $couponNo,
    $customerType,
    $verifiedStatus,
    $verifiedAt,
    $ip
);
if (!$stmt->execute()) {
    jsonResponse(['status' => 'error', 'message' => 'Customer save failed: ' . $stmt->error]);
}
$stmt->close();

// ─── Customer ID lein ───
$stmt = $db->prepare("SELECT id FROM customers WHERE player_key = ? LIMIT 1");
$stmt->bind_param("s", $key);
$stmt->execute();
$customerRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customerRow) {
    jsonResponse(['status' => 'error', 'message' => 'Customer not found after insert']);
}

$customerId = $customerRow['id'];

// ═══════════════════════════════════════════════════
// ✅ STEP 3: Referral insert/update
// ═══════════════════════════════════════════════════

// ═══════════════════════════════════════════════════
// ✅ STEP 3: Referral insert/update
// ═══════════════════════════════════════════════════

if ($referralRecordId) {
    // ✅ User-provided referral → HAMESHA mark used karo
    // (OTP ki condition hata di — chahe skip_otp 0 ho ya 1)
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
    // Auto-generated referral → insert karo
    // ✅ Pehle check karo ki is customer ka already exists?
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
        // Naya insert karo
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
// ✅ STEP 4: 3 Default Coupons Insert
// ═══════════════════════════════════════════════════

$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM coupons WHERE customer_id = ?");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$couponCount = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

if ($couponCount == 0) {
    // ✅ Har coupon ke saath uska FULL IMAGE URL
    $defaultCoupons = [
        [
            'title' => 'Welcome 10% Off',
            'desc' => 'Get 10% discount on your first purchase',
            'image_url' => 'https://hascol.allowance.flamboyant-spence.92-205-119-218.plesk.page/uploads/advertisements/banner1.png',
            'percent' => 10.00,
            'amount' => 0.00,
            'min' => 500.00
        ],
        [
            'title' => 'Engine Oil 5% Off',
            'desc' => 'Get 5% discount on engine oil',
            'image_url' => 'https://hascol.allowance.flamboyant-spence.92-205-119-218.plesk.page/uploads/advertisements/banner2.png',
            'percent' => 5.00,
            'amount' => 0.00,
            'min' => 1000.00
        ],
        [
            'title' => 'Fuel 3% Off',
            'desc' => 'Get 3% discount on fuel',
            'image_url' => 'https://hascol.allowance.flamboyant-spence.92-205-119-218.plesk.page/uploads/advertisements/banner3.png',
            'percent' => 3.00,
            'amount' => 0.00,
            'min' => 500.00
        ],
    ];

    $validFrom = date('Y-m-d H:i:s');
    $validTo = date('Y-m-d H:i:s', time() + 30 * 24 * 3600);

    foreach ($defaultCoupons as $c) {
        // ✅ INSERT query mein image_url column add kiya
        $stmt = $db->prepare("
            INSERT INTO coupons 
            (customer_id, title, description, image_url,
             discount_percent, discount_amount, min_purchase, 
             valid_from, valid_to, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'available')
        ");
        // ✅ bind_param types update kiye (9 types):
        // i = customer_id
        // s = title
        // s = description
        // s = image_url   ← NAYA
        // d = discount_percent
        // d = discount_amount
        // d = min_purchase
        // s = valid_from
        // s = valid_to
        $stmt->bind_param(
            "isssdddss",
            $customerId,
            $c['title'],
            $c['desc'],
            $c['image_url'],
            $c['percent'],
            $c['amount'],
            $c['min'],
            $validFrom,
            // hello
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
    UPDATE customers 
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
$insertedCount = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// ─── Firebase Customer (backup) ───
fbSet("customers/$key", [
    'player_id' => $playerId,
    'name' => $name,
    'email' => $email,
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
    // Referral mark used (agar user ne diya)
    if ($referralRecordId && $referralSource === 'user_provided') {
        // Already marked above in STEP 3
    }

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
    'coupon_no' => $couponNo,
    'referral_source' => $referralSource,
    'customer_type' => $customerType,
    'total_coupons' => $insertedCount,
    'otp_skipped' => false,
    'otp' => $otp,
    'expires_in' => OTP_EXPIRY,
    'expires_at' => date('Y-m-d H:i:s', $expires),
]);
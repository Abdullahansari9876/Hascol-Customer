<?php
// send-otp-email.php — Email helper (PHPMailer)
// Include this in request-device-change.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Sends OTP email
 * 
 * @param string $to       Customer email
 * @param string $name     Customer name
 * @param string $otp      6-digit OTP (plaintext)
 * @return bool            true if sent
 */
function sendOtpEmail(string $to, string $name, string $otp): bool
{
    // Note: Make sure the path matches your directory structure (3 levels up to vendor)
    require_once __DIR__ . '/../../vendor/autoload.php';

    $mail = new PHPMailer(true);
    try {
        // ═══════════════════════════════════════════
        // SMTP CONFIG
        // ═══════════════════════════════════════════
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'abdullah.a43567@gmail.com';    // Your email
        $mail->Password   = 'wwaljpcvzzxvyeef';             // Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        // Gmail SMTP requires the 'From' address to match your SMTP Username
        $mail->setFrom('abdullah.a43567@gmail.com', 'Hascol Bridge');
        $mail->addAddress($to, $name);

        $mail->isHTML(true);
        $mail->Subject = 'Hascol Bridge - Device Change Verification Code';
        
        // Email Body (Converted to English)
        $mail->Body    = "
            <div style='font-family:Arial,sans-serif;max-width:500px;margin:auto;padding:20px;background:#f9fafb;border-radius:8px;'>
                <div style='text-align:center;margin-bottom:20px;'>
                    <h2 style='color:#1d4ed8;margin:0;'>Hascol Bridge</h2>
                    <p style='color:#64748b;font-size:13px;margin:5px 0;'>Device Change Verification</p>
                </div>
                <div style='background:#fff;padding:20px;border-radius:6px;'>
                    <p style='color:#0f2440;font-size:14px;'>Hello <strong>" . htmlspecialchars($name) . "</strong>,</p>
                    <p style='color:#334155;font-size:13px;'>Here is the verification code for your device change request:</p>
                    <div style='background:#f1f5f9;padding:20px;text-align:center;border-radius:8px;margin:20px 0;'>
                        <span style='font-size:36px;font-weight:bold;letter-spacing:10px;color:#0f2440;'>" . $otp . "</span>
                    </div>
                    <p style='color:#64748b;font-size:13px;'>⏱ This code is valid for <b>10 minutes</b>.</p>
                    <p style='color:#ef4444;font-size:12px;'>🔒 Do not share this code with anyone.</p>
                    <p style='color:#94a3b8;font-size:11px;margin-top:20px;'>If you did not request this change, please ignore this email.</p>
                </div>
                <p style='text-align:center;color:#94a3b8;font-size:11px;margin-top:15px;'>© Hascol Bridge</p>
            </div>
        ";
        
        // Alt body for email clients that don't support HTML
        $mail->AltBody = "Your verification code is: $otp (Valid for 10 minutes)";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('OTP Email Error: ' . $mail->ErrorInfo);
        return false;
    }
}
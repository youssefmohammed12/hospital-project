<?php
/**
 * HealthBridge — Forgot Password
 * POST { email } => generates a secure reset token valid for 60 minutes.
 * Token and expiry are both set via MySQL NOW() to avoid PHP/MySQL timezone
 * mismatches on XAMPP setups.
 *
 * Returns the reset URL directly in JSON (no mail server needed for XAMPP).
 */

require_once __DIR__ . '/../../includes/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$data  = getJsonInput();
$email = sanitizeString($data['email'] ?? '', 150);

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, ['message' => 'A valid email address is required.']);
}

try {
    $db = getDB();

    // Check user exists — tell the user if it doesn't (per user request)
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(false, ['message' => 'No active account found for this email address.']);
    }

    // Invalidate any previous unused tokens for this email
    $db->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);

    // Generate a cryptographically secure token
    $token = bin2hex(random_bytes(32)); // 64 hex chars

    // Use MySQL NOW() + INTERVAL for expiry so there's no PHP/MySQL timezone mismatch
    $stmt = $db->prepare(
        'INSERT INTO password_resets (email, token, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE))'
    );
    $stmt->execute([$email, $token]);

    // Build the reset URL
    $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir       = dirname(dirname($_SERVER['SCRIPT_NAME']));
    $dir       = rtrim($dir, '/');
    $resetUrl  = "{$protocol}://{$host}{$dir}/reset-password.html?token={$token}";

    jsonResponse(true, [
        'message'   => 'Reset link generated! Click the button below to set your new password.',
        'reset_url' => $resetUrl,
    ]);

} catch (Exception $e) {
    error_log('ForgotPassword Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Server error. Please try again later.']);
}



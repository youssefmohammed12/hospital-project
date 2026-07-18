<?php
/**
 * HealthBridge — Reset Password
 * POST { token, password, confirm_password, verify_only } => validates token or updates the user's password.
 * Uses MySQL NOW() in the expiry comparison to avoid PHP/MySQL timezone issues.
 */

require_once __DIR__ . '/../../includes/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$data            = getJsonInput();
$token           = trim($data['token'] ?? '');
$verifyOnly      = !empty($data['verify_only']);
$newPassword     = $data['password'] ?? '';
$confirmPassword = $data['confirm_password'] ?? '';

// Validation
if (!$token) {
    jsonResponse(false, ['message' => 'Reset token is missing.']);
}

try {
    $db = getDB();

    // Find a valid, unused, non-expired token using MySQL NOW() for comparison
    $stmt = $db->prepare(
        'SELECT id, email FROM password_resets
         WHERE token = ? AND used = 0 AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if (!$reset) {
        jsonResponse(false, ['message' => 'This reset link is invalid or has expired. Please request a new one.']);
    }

    // If only verifying token presence/expiry
    if ($verifyOnly) {
        jsonResponse(true, ['message' => 'Token is valid.']);
    }

    if (strlen($newPassword) < 6) {
        jsonResponse(false, ['message' => 'Password must be at least 6 characters.']);
    }
    if ($newPassword !== $confirmPassword) {
        jsonResponse(false, ['message' => 'Passwords do not match.']);
    }

    // Hash new password and update user
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmt = $db->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE email = ?');
    $stmt->execute([$hash, $reset['email']]);

    // Mark token as used so it cannot be reused
    $db->prepare('UPDATE password_resets SET used = 1 WHERE id = ?')->execute([$reset['id']]);

    jsonResponse(true, ['message' => 'Your password has been changed successfully! Redirecting to login…']);

} catch (Exception $e) {
    error_log('ResetPassword Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Server error. Please try again later.']);
}



<?php
/**
 * HealthBridge — Reset Password
 *
 * Reset credentials are looked up by SHA-256 hash, locked and consumed in one
 * transaction. A successful reset increments session_version and revokes all
 * previously authenticated sessions for the account.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/PasswordResetRepository.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$data = getJsonInput();
$tokenValue = $data['token'] ?? '';
$passwordValue = $data['password'] ?? '';
$confirmPasswordValue = $data['confirm_password'] ?? '';
$verifyOnlyValue = $data['verify_only'] ?? false;

if (
    !is_string($tokenValue)
    || !is_string($passwordValue)
    || !is_string($confirmPasswordValue)
    || (!is_bool($verifyOnlyValue) && !is_string($verifyOnlyValue) && !is_int($verifyOnlyValue))
) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid password-reset request.']);
}

$token = trim($tokenValue);
$verifyOnly = filter_var($verifyOnlyValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($verifyOnly === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid password-reset request.']);
}
$newPassword = $passwordValue;
$confirmPassword = $confirmPasswordValue;

if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'This reset link is invalid or has expired. Please request a new one.']);
}

$tokenHash = hash('sha256', strtolower($token));

try {
    $db = getDB();

    if ($verifyOnly) {
        $resetRepository = new PasswordResetRepository($db);
        if (!$resetRepository->isTokenValid($tokenHash)) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'This reset link is invalid or has expired. Please request a new one.']);
        }

        jsonResponse(true, ['message' => 'Token is valid.']);
    }

    $passwordValidation = validatePassword($newPassword);
    if (!$passwordValidation['valid']) {
        http_response_code(400);
        jsonResponse(false, ['message' => implode(' ', $passwordValidation['errors'])]);
    }

    if ($newPassword !== $confirmPassword) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Passwords do not match.']);
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        throw new RuntimeException('Password hashing failed.');
    }

    $resetRepository = new PasswordResetRepository($db);
    $resetRepository->resetPassword($tokenHash, $passwordHash);

    jsonResponse(true, [
        'message' => 'Your password has been changed successfully. Please log in again.',
    ]);
} catch (DomainException $e) {
    if (str_contains($e->getMessage(), 'credentials changed')) {
        http_response_code(409);
    } else {
        http_response_code(400);
    }
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('ResetPassword Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Unable to reset your password right now. Please try again later.']);
}

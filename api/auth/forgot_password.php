<?php
/**
 * HealthBridge — Forgot Password
 *
 * Valid reset tokens are emailed to the account's registered address. The
 * endpoint never returns a reset token or distinguishes known from unknown
 * addresses in its successful response.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/HealthBridgeMailService.php';
require_once __DIR__ . '/../../services/Repositories/PasswordResetRepository.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$data = getJsonInput();
$emailValue = $data['email'] ?? '';
if (!is_string($emailValue)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid email address is required.']);
}
$email = strtolower(trim($emailValue));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid email address is required.']);
}

// Check configuration before looking up the account so setup state cannot
// reveal whether an email address exists.
if (!HealthBridgeMailService::isConfigured()) {
    http_response_code(503);
    jsonResponse(false, [
        'message' => 'Password recovery email delivery is not configured. Please contact the administrator.',
    ]);
}

$remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$requestIp = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? $remoteAddress : null;
$genericMessage = 'If an active account exists for that address, a password reset email will be sent.';
$resetRequestId = null;
$token = null;

try {
    $db = getDB();
    // The HTTP endpoint remains explicitly on v1. The repository's v2 mapping
    // is contract-tested against the disposable v2 fixture.
    $resetRepository = new PasswordResetRepository($db);
    $request = $resetRepository->createRequest($email, $requestIp);

    if ($request['rate_limited']) {
        header('Retry-After: 3600');
        jsonResponse(true, ['message' => $genericMessage]);
    }

    $token = $request['token'];
    $resetRequestId = $request['request_id'];

    if ($token !== null) {
        try {
            HealthBridgeMailService::sendPasswordReset($email, $token);
        } catch (Throwable $mailError) {
            // A delivery failure must not leave a valid reset token that was
            // never delivered. Keep the attempt row for throttling/audit.
            try {
                $resetRepository->invalidateUndeliveredToken((int)$resetRequestId);
            } catch (Throwable $cleanupError) {
                error_log('Password reset token cleanup failed after email delivery failure.');
            }
            error_log('Password reset email delivery failed.');
        }
    }

    jsonResponse(true, ['message' => $genericMessage]);
} catch (Throwable $error) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('ForgotPassword Error: ' . get_class($error));
    http_response_code(500);
    jsonResponse(false, ['message' => 'Unable to process the recovery request right now. Please try again later.']);
}
